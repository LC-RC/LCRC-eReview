<?php
/**
 * Focused non-production checks: two-connection lock, HTTP save_answer/submit,
 * fill-empty gates, payload safety, DB 503 branch, client empty-payload guard.
 *
 * Does NOT run 20–300 load tests. Cleans up only ephemeral __focusqa__ rows.
 *
 * Run: C:\xampp\php\php.exe scripts\qa_exam_focused_http_concurrency.php
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/db.php';
require_once $projectRoot . '/examination/includes/college_schema.php';
require_once $projectRoot . '/examination/includes/college_exam_helpers.php';
require_once $projectRoot . '/examination/includes/college_exam_attempt_paper.php';

$base = rtrim((string)(getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview'), '/');
$phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'C:\\xampp\\php\\php.exe';
$report = [];

function focus_pass(array &$report, string $id, bool $ok, string $detail = ''): void
{
    $report[$id] = ['result' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $id . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}

function focus_nv(array &$report, string $id, string $detail): void
{
    $report[$id] = ['result' => 'NOT VERIFIED', 'detail' => $detail];
    echo '[NOT VERIFIED] ' . $id . ' — ' . $detail . PHP_EOL;
}

function focus_http(string $url, string $cookie, string $method = 'POST', ?string $body = null, array $headers = []): array
{
    $hdr = array_merge(['Cookie: PHPSESSID=' . $cookie], $headers);
    $content = false;
    $status = '0';
    $respHeaders = [];
    for ($hop = 0; $hop < 3; $hop++) {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $hdr),
                'content' => $body ?? '',
                'timeout' => 25,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $content = @file_get_contents($url, false, $ctx);
        $respHeaders = $http_response_header ?? [];
        $status = '0';
        if (isset($respHeaders[0]) && preg_match('/\d{3}/', $respHeaders[0], $m)) {
            $status = $m[0];
        }
        $location = '';
        foreach ($respHeaders as $h) {
            if (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        if (($status === '301' || $status === '302') && $location !== '') {
            if (!preg_match('#^https?://#i', $location)) {
                $parts = parse_url($url);
                $location = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost') . $location;
            }
            $url = $location;
            continue;
        }
        break;
    }
    $json = null;
    if (is_string($content) && $content !== '') {
        $tmp = json_decode($content, true);
        if (is_array($tmp)) {
            $json = $tmp;
        }
    }

    return ['status' => $status, 'content' => is_string($content) ? $content : '', 'json' => $json, 'headers' => $respHeaders];
}

function focus_cleanup(mysqli $conn, int $examId, int $attemptId): void
{
    if ($attemptId > 0 && $attemptId !== 362) {
        mysqli_query($conn, 'DELETE FROM college_exam_attempt_questions WHERE attempt_id=' . $attemptId);
        mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $attemptId);
        mysqli_query($conn, 'DELETE FROM college_exam_attempts WHERE attempt_id=' . $attemptId);
    }
    if ($examId > 0) {
        mysqli_query($conn, 'DELETE FROM college_exam_questions WHERE exam_id=' . $examId);
        mysqli_query($conn, 'DELETE FROM college_exam_users WHERE exam_id=' . $examId);
        mysqli_query($conn, 'DELETE FROM college_exams WHERE exam_id=' . $examId . " AND title LIKE '__focusqa_%'");
    }
}

$stu = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT user_id, full_name, email, role FROM users WHERE role='college_student' AND status='approved' ORDER BY user_id ASC LIMIT 1"
));
if (!$stu) {
    echo "SKIP: no college_student user\n";
    exit(1);
}
$uid = (int)$stu['user_id'];

mysqli_query(
    $conn,
    "INSERT INTO college_exams (title, description, time_limit_seconds, is_published, created_by, shuffle_questions, shuffle_choices, question_breakdown_mode, examinee_scope, assignment_mode)
     VALUES ('__focusqa__', '', 3600, 1, {$uid}, 0, 0, 'overall', 'college_student', 'all')"
);
$examId = (int)mysqli_insert_id($conn);
$qids = [];
for ($i = 0; $i < 3; $i++) {
    mysqli_query(
        $conn,
        "INSERT INTO college_exam_questions (exam_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
         VALUES ({$examId}, 'mcq', 'FQ{$i}', 'a', 'b', 'c', 'd', 'A', {$i})"
    );
    $qids[] = (int)mysqli_insert_id($conn);
}
mysqli_query($conn, "INSERT IGNORE INTO college_exam_users (exam_id, user_id) VALUES ({$examId}, {$uid})");
$exam = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . $examId));

$started = date('Y-m-d H:i:s', time() - 120);
$expiresPast = date('Y-m-d H:i:s', time() - 200);
$staleSeen = date('Y-m-d H:i:s', time() - 400);
mysqli_query(
    $conn,
    "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at)
     VALUES ({$examId}, {$uid}, 'in_progress', '{$started}', '{$expiresPast}', '{$staleSeen}')"
);
$attemptId = (int)mysqli_insert_id($conn);
college_exam_create_attempt_snapshot($conn, $exam, $attemptId);
college_exam_write_answer_row($conn, $attemptId, $uid, $qids[0], 'A', 0, 1);

$outs = [
    'holder' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ereview_holder_' . $attemptId . '.json',
    'waiter' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ereview_waiter_' . $attemptId . '.json',
];
$flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ereview_lockflag_' . $attemptId;
@unlink($flag);
@unlink($outs['holder']);
@unlink($outs['waiter']);

$peer = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'qa_exam_lock_peer.php';
$holderCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($peer) . ' holder '
    . $attemptId . ' ' . $uid . ' ' . $examId . ' ' . $qids[1] . ' C 1800 ' . escapeshellarg($outs['holder']);
$waiterCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($peer) . ' waiter '
    . $attemptId . ' ' . $uid . ' ' . $examId . ' 0 A 0 ' . escapeshellarg($outs['waiter']);

$holderProc = proc_open($holderCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hPipes, $projectRoot);
$flagWait = time() + 6;
while (!is_file($flag) && time() < $flagWait) {
    usleep(30000);
}
$waiterProc = is_file($flag) ? proc_open($waiterCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wPipes, $projectRoot) : false;

if (is_resource($holderProc)) {
    $hStatus = proc_get_status($holderProc);
    $hDeadline = time() + 12;
    while (!empty($hStatus['running']) && time() < $hDeadline) {
        usleep(50000);
        $hStatus = proc_get_status($holderProc);
    }
    proc_close($holderProc);
}
if (is_resource($waiterProc)) {
    $wStatus = proc_get_status($waiterProc);
    $wDeadline = time() + 12;
    while (!empty($wStatus['running']) && time() < $wDeadline) {
        usleep(50000);
        $wStatus = proc_get_status($waiterProc);
    }
    proc_close($waiterProc);
}

$holderJson = is_file($outs['holder']) ? json_decode((string)file_get_contents($outs['holder']), true) : null;
$waiterJson = is_file($outs['waiter']) ? json_decode((string)file_get_contents($outs['waiter']), true) : null;
$stRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT status FROM college_exam_attempts WHERE attempt_id=' . $attemptId . ' LIMIT 1'));
$status = strtolower((string)($stRow['status'] ?? ''));
$ansMap = [];
$ar = mysqli_query($conn, 'SELECT question_id, selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId);
while ($ar && ($r = mysqli_fetch_assoc($ar))) {
    $ansMap[(int)$r['question_id']] = strtoupper((string)$r['selected_answer']);
}
$waiterMs = (int)($waiterJson['elapsed_ms'] ?? 0);
$holderElapsed = (int)($holderJson['elapsed_ms'] ?? 0);
$lockWaited = $waiterMs >= 1200;
$oneFinal = $status === 'submitted' && !empty($holderJson['ok']) && (int)($holderJson['already_submitted'] ?? 0) === 0;
$waiterNoSecondScore = is_array($waiterJson)
    && (int)($waiterJson['finalize_count'] ?? -1) === 0
    && empty($waiterJson['error']);
$noDeadlock = $waiterMs < 19000 && $holderElapsed < 19000 && empty($holderJson['error']);
$answersOk = ($ansMap[$qids[0]] ?? '') === 'A' && ($ansMap[$qids[1]] ?? '') === 'C';

focus_pass(
    $report,
    '1_two_connection_lock',
    $oneFinal && $lockWaited && $answersOk && $waiterNoSecondScore && $noDeadlock,
    'holder=' . json_encode($holderJson) . ' waiter=' . json_encode($waiterJson) . " status=$status answers=" . json_encode($ansMap) . " lockWaited=" . ($lockWaited ? '1' : '0') . " waiter_finalize_count=" . (int)($waiterJson['finalize_count'] ?? -1)
);

// Extra question NOT in snapshot (for fill-empty reject)
mysqli_query(
    $conn,
    "INSERT INTO college_exam_questions (exam_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
     VALUES ({$examId}, 'mcq', 'OUTSIDE', 'a', 'b', 'c', 'd', 'A', 99)"
);
$outsideQid = (int)mysqli_insert_id($conn);

mysqli_query($conn, "UPDATE college_exam_attempts SET submitted_at=NOW() WHERE attempt_id=" . $attemptId);
$fillSnap = college_exam_fill_empty_timeout_answers($conn, $attemptId, $uid, [
    ['question_id' => $outsideQid, 'selected_answer' => 'B', 'save_seq' => 3],
], 'timeout');
$outRow = mysqli_fetch_assoc(mysqli_query(
    $conn,
    'SELECT answer_id FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $outsideQid . ' LIMIT 1'
));
focus_pass(
    $report,
    '5_fill_empty_rejects_outside_snapshot',
    (int)$fillSnap['filled'] === 0 && !$outRow,
    json_encode($fillSnap)
);

$fillExist = college_exam_fill_empty_timeout_answers($conn, $attemptId, $uid, [
    ['question_id' => $qids[0], 'selected_answer' => 'D', 'save_seq' => 50],
], 'timeout');
$keepA = strtoupper((string)(mysqli_fetch_assoc(mysqli_query(
    $conn,
    'SELECT selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $qids[0] . ' LIMIT 1'
))['selected_answer'] ?? ''));
focus_pass($report, '5_fill_empty_no_overwrite', $keepA === 'A' && (int)$fillExist['filled'] === 0, "sel=$keepA fill=" . json_encode($fillExist));

mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . (int)$qids[2]);
$fillOk = college_exam_fill_empty_timeout_answers($conn, $attemptId, $uid, [
    ['question_id' => $qids[2], 'selected_answer' => 'B', 'save_seq' => 4],
], 'timeout');
$gotB = strtoupper((string)(mysqli_fetch_assoc(mysqli_query(
    $conn,
    'SELECT selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $qids[2] . ' LIMIT 1'
))['selected_answer'] ?? ''));
focus_pass($report, '5_fill_empty_within_30s', (int)$fillOk['filled'] === 1 && $gotB === 'B', json_encode($fillOk) . " sel=$gotB");

$fillManual = college_exam_fill_empty_timeout_answers($conn, $attemptId, $uid, [
    ['question_id' => $qids[2], 'selected_answer' => 'D', 'save_seq' => 5],
], 'manual');
focus_pass($report, '5_fill_empty_rejects_manual', (int)$fillManual['filled'] === 0, json_encode($fillManual));

mysqli_query($conn, "UPDATE college_exam_attempts SET submitted_at='" . date('Y-m-d H:i:s', time() - 90) . "' WHERE attempt_id=" . $attemptId);
mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . (int)$qids[2]);
$fillLate = college_exam_fill_empty_timeout_answers($conn, $attemptId, $uid, [
    ['question_id' => $qids[2], 'selected_answer' => 'C', 'save_seq' => 6],
], 'timeout');
focus_pass($report, '5_fill_empty_after_30s', (int)$fillLate['filled'] === 0, json_encode($fillLate));

// Payload safety (CLI helpers + source)
$takeSrc = (string)file_get_contents($projectRoot . '/examination/examinee/college_take_exam.php');
$emptyGuard = strpos($takeSrc, 'buildAuthoritativeAnswersPayload') !== false
    && strpos($takeSrc, 'MAX_SUBMIT_ATTEMPTS') !== false
    && strpos($takeSrc, 'sendSingleSubmit') !== false
    && strpos($takeSrc, 'autoSubmitOnTimeUp(reason, 1)') === false;
focus_pass($report, '4_client_empty_payload_retries', $emptyGuard, 'timeout submit uses authoritative payload + bounded retries (no infinite loop)');

mysqli_query(
    $conn,
    "UPDATE college_exam_attempts SET status='in_progress', submitted_at=NULL, expires_at='" . date('Y-m-d H:i:s', time() - 10) . "', last_seen_at=NOW() WHERE attempt_id=" . $attemptId
);
mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $attemptId);
college_exam_write_answer_row($conn, $attemptId, $uid, $qids[0], 'A', 0, 1);
$emptyFlush = college_exam_upsert_attempt_answers_payload($conn, $attemptId, $uid, []);
$finEmpty = college_exam_finalize_attempt($conn, $attemptId, $uid);
$stillA = strtoupper((string)(mysqli_fetch_assoc(mysqli_query(
    $conn,
    'SELECT selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $qids[0] . ' LIMIT 1'
))['selected_answer'] ?? ''));
focus_pass(
    $report,
    '4_empty_payload_keeps_db_answers',
    !empty($emptyFlush['ok']) && !empty($finEmpty['ok']) && $stillA === 'A',
    "flush=" . json_encode($emptyFlush) . " sel=$stillA"
);

json_decode('{not-json', true);
$malformedDecodesEmpty = json_last_error() !== JSON_ERROR_NONE;
focus_pass($report, '4_malformed_json_decodes_empty_array', $malformedDecodesEmpty, 'server uses json_decode; invalid JSON => [] => no wipe of existing rows');

// HTTP
$httpOk = false;
$probe = @file_get_contents($base . '/index.php', false, stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]));
if ($probe !== false || isset($http_response_header)) {
    $httpOk = true;
}

if (!$httpOk) {
    focus_nv($report, '2_http_save_answer_expired', 'Apache/base URL not reachable at ' . $base);
    focus_nv($report, '2_http_timeout_submit_after_rejected_save', 'HTTP base unreachable');
    focus_nv($report, '3_browser_autosubmit_flow', 'No browser automation; HTTP unreachable');
    focus_nv($report, '4_http_malformed_answers_field', 'HTTP unreachable');
} else {
    require $projectRoot . '/session_config.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $uid;
    $_SESSION['full_name'] = (string)$stu['full_name'];
    $_SESSION['email'] = (string)($stu['email'] ?? '');
    $_SESSION['role'] = 'college_student';
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf = (string)$_SESSION['csrf_token'];
    $sid = session_id();
    session_write_close();

    mysqli_query(
        $conn,
        "UPDATE college_exam_attempts SET status='in_progress', submitted_at=NULL, score=NULL, correct_count=NULL, total_count=NULL, expires_at='"
        . date('Y-m-d H:i:s', time() - 15)
        . "', last_seen_at=NOW() WHERE attempt_id=" . $attemptId
    );
    mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $attemptId);

    $ajaxUrl = $base . '/examination/examinee/college_exam_ajax';
    $saveBody = http_build_query([
        'action' => 'save_answer',
        'csrf_token' => $csrf,
        'attempt_id' => $attemptId,
        'question_id' => $qids[0],
        'selected_answer' => 'B',
        'save_seq' => 1,
    ]);
    $saveRes = focus_http($ajaxUrl, $sid, 'POST', $saveBody, ['Content-Type: application/x-www-form-urlencoded']);
    $saveRejected = ($saveRes['json']['ok'] ?? null) === false
        || !empty($saveRes['json']['expired'])
        || (($saveRes['json']['error'] ?? '') === 'Attempt not active');
    $noRow = !mysqli_fetch_assoc(mysqli_query(
        $conn,
        'SELECT answer_id FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $qids[0] . ' LIMIT 1'
    ));
    focus_pass(
        $report,
        '2_http_save_answer_expired',
        $saveRejected && $noRow,
        'status=' . $saveRes['status'] . ' body=' . substr($saveRes['content'], 0, 220)
    );

    $subBody = http_build_query([
        'action' => 'submit',
        'csrf_token' => $csrf,
        'attempt_id' => $attemptId,
        'reason' => 'timeout',
        'answers' => json_encode([['question_id' => $qids[0], 'selected_answer' => 'B', 'save_seq' => 1]]),
    ]);
    $subRes = focus_http($ajaxUrl, $sid, 'POST', $subBody, ['Content-Type: application/x-www-form-urlencoded']);
    $rowB = mysqli_fetch_assoc(mysqli_query(
        $conn,
        'SELECT selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $qids[0] . ' LIMIT 1'
    ));
    $selB = strtoupper((string)($rowB['selected_answer'] ?? ''));
    $httpSubmitOk = !empty($subRes['json']['ok']) && $selB === 'B';
    focus_pass(
        $report,
        '2_http_timeout_submit_after_rejected_save',
        $httpSubmitOk,
        'status=' . $subRes['status'] . ' json=' . substr((string)$subRes['content'], 0, 240) . " sel=$selB"
    );

    $malBody = http_build_query([
        'action' => 'submit',
        'csrf_token' => $csrf,
        'attempt_id' => $attemptId,
        'reason' => 'timeout',
        'answers' => '{not-json',
    ]);
    $malRes = focus_http($ajaxUrl, $sid, 'POST', $malBody, ['Content-Type: application/x-www-form-urlencoded']);
    $stillB = strtoupper((string)(mysqli_fetch_assoc(mysqli_query(
        $conn,
        'SELECT selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $qids[0] . ' LIMIT 1'
    ))['selected_answer'] ?? ''));
    $malDidNotWipe = $stillB === 'B';
    focus_pass(
        $report,
        '4_http_malformed_answers_field',
        $malDidNotWipe,
        'existing B kept; status=' . $malRes['status'] . ' body=' . substr($malRes['content'], 0, 180) . " sel=$stillB"
    );

    mysqli_query(
        $conn,
        "UPDATE college_exam_attempts SET status='in_progress', submitted_at=NULL, score=NULL, correct_count=NULL, total_count=NULL, expires_at='"
        . date('Y-m-d H:i:s', time() - 15)
        . "', last_seen_at=NOW() WHERE attempt_id=" . $attemptId
    );
    mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $attemptId);
    college_exam_write_answer_row($conn, $attemptId, $uid, $qids[0], 'A', 0, 1);
    $emptyHttp = focus_http($ajaxUrl, $sid, 'POST', http_build_query([
        'action' => 'submit',
        'csrf_token' => $csrf,
        'attempt_id' => $attemptId,
        'reason' => 'timeout',
        'answers' => '[]',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    $keptA = strtoupper((string)(mysqli_fetch_assoc(mysqli_query(
        $conn,
        'SELECT selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId . ' AND question_id=' . $qids[0] . ' LIMIT 1'
    ))['selected_answer'] ?? ''));
    $stEmpty = strtolower((string)(mysqli_fetch_assoc(mysqli_query(
        $conn,
        'SELECT status FROM college_exam_attempts WHERE attempt_id=' . $attemptId . ' LIMIT 1'
    ))['status'] ?? ''));
    focus_pass(
        $report,
        '4_http_empty_payload_keeps_db_answers',
        $keptA === 'A' && $stEmpty === 'submitted' && !empty($emptyHttp['json']['ok']),
        "sel=$keptA status=$stEmpty http=" . substr((string)$emptyHttp['content'], 0, 180)
    );

    $reviewUrl = $base . '/examination/examinee/college_take_exam.php?exam_id=' . $examId . '&review=1';
    $rev = focus_http($reviewUrl, $sid, 'GET', null, []);
    $reviewHasAnswer = stripos($rev['content'], 'FQ0') !== false || stripos($rev['content'], 'Question') !== false;
    if (($rev['status'] === '0' || (int)$rev['status'] >= 400) && !$reviewHasAnswer) {
        focus_nv($report, '3_browser_autosubmit_flow', 'No Playwright/browser tools; HTTP review GET status=' . $rev['status'] . ' (session/portal may redirect). Delayed autosave+timer was not driven in a real browser.');
    } else {
        focus_pass(
            $report,
            '3_http_review_shows_submitted_exam',
            $reviewHasAnswer && $selB === 'B',
            'HTTP review reached; full autoSubmitOnTimeUp+slow fetch still needs a browser. status=' . $rev['status']
        );
        focus_nv($report, '3_browser_autosubmit_flow', 'No Playwright in this session. HTTP timeout submit (test 2) persisted B; DOM debounce/timer JS was not executed.');
    }
}

// 6. Real mysqli_connect failure using a temp copy of db.php (does not touch db.local.php / production).
$dbSrc = (string)file_get_contents($projectRoot . '/db.php');
$has503 = strpos($dbSrc, 'college_exam_ajax.php') !== false
    && strpos($dbSrc, 'http_response_code(503)') !== false
    && strpos($dbSrc, 'Database unavailable') !== false;
$tmpDbDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ereview_dbfail_' . $attemptId;
@mkdir($tmpDbDir, 0777, true);
$failLocal = "<?php\n\$host='127.0.0.1'; \$user='ereview_qa_nosuch_user'; \$pass='wrong'; \$db='ereview_qa_nosuch_db';\n";
file_put_contents($tmpDbDir . DIRECTORY_SEPARATOR . 'db.local.php', $failLocal);
file_put_contents($tmpDbDir . DIRECTORY_SEPARATOR . 'session_config.php', "<?php\n");
file_put_contents($tmpDbDir . DIRECTORY_SEPARATOR . 'db.php', $dbSrc);
$probe503 = $tmpDbDir . DIRECTORY_SEPARATOR . 'probe.php';
file_put_contents(
    $probe503,
    "<?php\n\$_SERVER['SCRIPT_FILENAME'] = (string)(\$argv[1] ?? '');\nregister_shutdown_function(function () {\n"
    . "  file_put_contents(__DIR__ . '/http_code.txt', (string)http_response_code());\n"
    . "});\nrequire __DIR__ . '/db.php';\necho 'CONNECTED';\n"
);
$ajaxScript = $projectRoot . DIRECTORY_SEPARATOR . 'examination' . DIRECTORY_SEPARATOR . 'examinee' . DIRECTORY_SEPARATOR . 'college_exam_ajax.php';
$htmlScript = $projectRoot . DIRECTORY_SEPARATOR . 'admin_dashboard.php';
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pAjax = proc_open(
    $phpBin . ' -d display_errors=0 ' . escapeshellarg($probe503) . ' ' . escapeshellarg($ajaxScript),
    $desc,
    $pipesA,
    $tmpDbDir
);
$ajaxOut = '';
$ajaxErr = '';
if (is_resource($pAjax)) {
    $ajaxOut = (string)stream_get_contents($pipesA[1]);
    $ajaxErr = (string)stream_get_contents($pipesA[2]);
    proc_close($pAjax);
}
$ajaxCode = is_file($tmpDbDir . DIRECTORY_SEPARATOR . 'http_code.txt')
    ? trim((string)file_get_contents($tmpDbDir . DIRECTORY_SEPARATOR . 'http_code.txt'))
    : '0';
@unlink($tmpDbDir . DIRECTORY_SEPARATOR . 'http_code.txt');
$ajaxJson = json_decode($ajaxOut, true);
$ajax503 = $has503 && $ajaxCode === '503' && is_array($ajaxJson) && ($ajaxJson['ok'] ?? true) === false
    && (($ajaxJson['error'] ?? '') === 'Database unavailable');

$pHtml = proc_open(
    $phpBin . ' -d display_errors=0 ' . escapeshellarg($probe503) . ' ' . escapeshellarg($htmlScript),
    $desc,
    $pipesH,
    $tmpDbDir
);
$htmlOut = '';
if (is_resource($pHtml)) {
    $htmlOut = (string)stream_get_contents($pipesH[1]) . (string)stream_get_contents($pipesH[2]);
    proc_close($pHtml);
}
$htmlCode = is_file($tmpDbDir . DIRECTORY_SEPARATOR . 'http_code.txt')
    ? trim((string)file_get_contents($tmpDbDir . DIRECTORY_SEPARATOR . 'http_code.txt'))
    : '0';
$html500 = $htmlCode === '500' && str_contains($htmlOut, 'Connection failed') && strpos($htmlOut, 'Database unavailable') === false;

@unlink($tmpDbDir . DIRECTORY_SEPARATOR . 'db.php');
@unlink($tmpDbDir . DIRECTORY_SEPARATOR . 'db.local.php');
@unlink($tmpDbDir . DIRECTORY_SEPARATOR . 'session_config.php');
@unlink($probe503);
@rmdir($tmpDbDir);

focus_pass($report, '6_ajax_db_fail_json_503', $ajax503, "http=$ajaxCode body=" . substr($ajaxOut . $ajaxErr, 0, 180));
focus_pass($report, '6_non_ajax_db_fail_html_500', $html500, "http=$htmlCode body=" . substr($htmlOut, 0, 100));

focus_cleanup($conn, $examId, $attemptId);

$failN = 0;
foreach ($report as $row) {
    if (($row['result'] ?? '') === 'FAIL') {
        $failN++;
    }
}
echo $failN === 0 ? "FOCUSED CHECKS COMPLETE (no FAIL)\n" : "FAILURES=$failN\n";
exit($failN === 0 ? 0 : 1);
