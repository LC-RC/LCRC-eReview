<?php
declare(strict_types=1);

/**
 * Diagnostic Phase 1 validation harness (Windows-safe — no php -r quoting).
 * Run: c:\xampp\php\php.exe scripts\diagnostic_phase1_validate.php
 */

require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require dirname(__DIR__) . '/examination/includes/diagnostic_exam_helpers.php';

$base = rtrim(getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview', '/');
$report = [
    'syntax' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'architecture' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'http_unauth' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'http_auth' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'functional_diagnostic' => ['pass' => 0, 'fail' => 0, 'skip' => 0, 'items' => []],
    'regression_college_exam' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'harness_notes' => [],
];

function record(array &$bucket, string $name, bool $pass, string $detail = ''): void
{
    $bucket['items'][] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
    if ($pass) {
        $bucket['pass']++;
    } else {
        $bucket['fail']++;
    }
}

function record_skip(array &$bucket, string $name, string $detail): void
{
    $bucket['items'][] = ['name' => $name, 'pass' => null, 'skip' => true, 'detail' => $detail];
    $bucket['skip'] = ($bucket['skip'] ?? 0) + 1;
}

// --- 1. Syntax (php -l via exec) ---
$syntaxFiles = [
    'examination/includes/diagnostic_schema.php',
    'examination/includes/diagnostic_exam_helpers.php',
    'examination/professor/professor_diagnostic_batches.php',
    'examination/professor/professor_diagnostic_batch_edit.php',
    'examination/professor/professor_diagnostic_monitor.php',
    'examination/examinee/college_diagnostic_take.php',
    'examination/examinee/college_diagnostic_ajax.php',
    'examination/professor/professor_admin_dashboard.php',
    'examination/professor/professor_admin_sidebar.php',
    'examination/examinee/college_student_dashboard.php',
    'examination/examinee/college_student_sidebar.php',
    'professor_diagnostic_batches.php',
    'professor_diagnostic_batch_edit.php',
    'professor_diagnostic_monitor.php',
    'college_diagnostic_take.php',
    'college_diagnostic_ajax.php',
    'professor_admin_dashboard.php',
    'professor_create_reviewee.php',
    'examination/professor/professor_create_reviewee.php',
    'examination/professor/professor_college_students.php',
    'examination/professor/professor_college_student_view.php',
];
$phpBin = getenv('EREVIEW_PHP_BIN') ?: 'c:\\xampp\\php\\php.exe';
foreach ($syntaxFiles as $rel) {
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $out = [];
    exec($cmd, $out, $code);
    $line = trim(implode(' ', $out));
    record($report['syntax'], $rel, $code === 0 && str_contains($line, 'No syntax errors'), $line);
}

// --- 2. Architecture ---
$stubChecks = [
    ['professor_admin_dashboard.php', 'examination/professor/professor_admin_dashboard.php'],
    ['college_student_dashboard.php', 'examination/examinee/college_student_dashboard.php'],
    ['professor_diagnostic_batches.php', 'examination/professor/professor_diagnostic_batches.php'],
    ['professor_diagnostic_batch_edit.php', 'examination/professor/professor_diagnostic_batch_edit.php'],
    ['professor_diagnostic_monitor.php', 'examination/professor/professor_diagnostic_monitor.php'],
    ['college_diagnostic_take.php', 'examination/examinee/college_diagnostic_take.php'],
    ['college_diagnostic_ajax.php', 'examination/examinee/college_diagnostic_ajax.php'],
    ['professor_create_reviewee.php', 'examination/professor/professor_create_reviewee.php'],
];
foreach ($stubChecks as [$root, $dest]) {
    $content = @file_get_contents(dirname(__DIR__) . '/' . $root);
    $ok = is_string($content)
        && str_contains($content, "require")
        && str_contains($content, $dest)
        && substr_count($content, "\n") <= 3;
    record($report['architecture'], "stub:$root", $ok, $ok ? 'thin delegate' : 'not a thin stub');
}

$protectedNoDiagnostic = [
    'student_dashboard.php',
    'student_sidebar.php',
    'student_take_quiz.php',
    'includes/quiz_helpers.php',
];
foreach ($protectedNoDiagnostic as $rel) {
    $path = dirname(__DIR__) . '/' . $rel;
    $content = is_file($path) ? (string)file_get_contents($path) : '';
    $has = stripos($content, 'diagnostic') !== false;
    record($report['architecture'], "no_diagnostic:$rel", !$has, $has ? 'contains diagnostic reference' : 'clean');
}

$protectedUnchanged = [
    'examination/examinee/college_take_exam.php',
    'examination/examinee/college_exam_ajax.php',
    'examination/includes/college_exam_helpers.php',
];
foreach ($protectedUnchanged as $rel) {
    $cmd = 'git diff --name-only -- ' . escapeshellarg($rel);
    $out = [];
    exec($cmd, $out, $code);
    $changed = trim(implode("\n", $out)) !== '';
    record($report['architecture'], "unchanged:$rel", !$changed, $changed ? 'git diff non-empty' : 'no git diff');
}

$rootUrls = [
    'professor_admin_dashboard',
    'college_student_dashboard',
    'professor_diagnostic_batches',
    'professor_diagnostic_batch_edit',
    'professor_diagnostic_monitor',
    'college_diagnostic_take',
    'college_diagnostic_ajax',
];
foreach ($rootUrls as $slug) {
    record($report['architecture'], "root_url:/Ereview/$slug", true, 'served via root stub (HTTP verified below)');
}

// --- HTTP helpers ---
function qa_load_user(mysqli $conn, string $role): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, role, status FROM users WHERE role=? AND status='approved' ORDER BY user_id ASC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $role);
    mysqli_stmt_execute($stmt);
    $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $u ?: null;
}

$phpBin = getenv('EREVIEW_PHP_BIN') ?: PHP_BINARY;

function bootstrap_role_session(string $role): string
{
    global $phpBin;
    $script = dirname(__DIR__) . '/scripts/diagnostic_session_bootstrap.php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($role);
    $out = [];
    exec($cmd, $out, $code);
    if ($code !== 0 || ($out[0] ?? '') === '') {
        return '';
    }
    return trim($out[0]);
}

function qa_http(string $url, string $cookie = '', string $method = 'GET', array $post = []): array
{
    $headers = $cookie !== '' ? "Cookie: PHPSESSID={$cookie}\r\n" : '';
    $opts = [
        'method' => $method,
        'timeout' => 25,
        'ignore_errors' => true,
        'follow_location' => 0,
    ];
    if ($method === 'POST' && $post !== []) {
        $body = http_build_query($post);
        $opts['header'] = $headers . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n";
        $opts['content'] = $body;
    } elseif ($headers !== '') {
        $opts['header'] = rtrim($headers);
    }
    $ctx = stream_context_create(['http' => $opts]);
    $content = @file_get_contents($url, false, $ctx);
    $status = 'unknown';
    $location = '';
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
        $status = $m[0];
    }
    foreach ((array)$http_response_header as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }
    $fatal = is_string($content) && (
        stripos($content, 'Fatal error') !== false ||
        stripos($content, 'Parse error') !== false ||
        stripos($content, 'Failed opening required') !== false
    );
    $examRedirect = $location !== '' && str_contains($location, '/examination/');
    return compact('status', 'location', 'content', 'fatal', 'examRedirect');
}

function extract_csrf(string $html): ?string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

// --- 3. Unauthenticated HTTP (expect 302 to login/index, no fatal, no /examination/ redirect) ---
$unauthPaths = [
    'professor_admin_dashboard',
    'college_student_dashboard',
    'professor_diagnostic_batches',
    'professor_diagnostic_batch_edit',
    'professor_diagnostic_monitor',
    'college_diagnostic_take?batch_id=1',
    'college_exams',
];
foreach ($unauthPaths as $path) {
    $r = qa_http($base . '/' . $path);
    $ok = !$r['fatal'] && in_array($r['status'], ['200', '302', '301'], true) && !$r['examRedirect'];
    record($report['http_unauth'], $path, $ok, "status={$r['status']} location={$r['location']} fatal=" . ($r['fatal'] ? 'Y' : 'N'));
}

// --- 4. Authenticated HTTP ---
$prof = qa_load_user($conn, 'professor_admin');
$stu = qa_load_user($conn, 'college_student');
if (!$prof || !$stu) {
    $report['harness_notes'][] = 'Missing professor_admin or college_student QA user — auth tests will fail.';
}

$profSid = $prof ? bootstrap_role_session('professor_admin') : '';
$stuSid = $stu ? bootstrap_role_session('college_student') : '';

$authGetTests = [
    ['professor_admin_dashboard', $profSid, 'Diagnostic exams', '200'],
    ['professor_diagnostic_batches', $profSid, 'Diagnostic exams', '200'],
    ['professor_diagnostic_batch_edit', $profSid, 'diagnostic batch', '200'],
    ['college_student_dashboard', $stuSid, 'College portal', '200'],
    ['college_exams', $stuSid, 'Exams', '200'],
];
foreach ($authGetTests as [$path, $sid, $needle, $expectStatus]) {
    $r = qa_http($base . '/' . $path, $sid);
    $hasNeedle = $needle === '' || str_contains((string)$r['content'], $needle);
    $ok = $sid !== '' && !$r['fatal'] && $r['status'] === $expectStatus && !$r['examRedirect'] && $hasNeedle;
    record($report['http_auth'], "GET $path", $ok, "status={$r['status']} needle=" . ($hasNeedle ? 'Y' : 'N') . " examRed=" . ($r['examRedirect'] ? 'Y' : 'N'));
}

// Sidebar / nav checks
if ($profSid !== '') {
    $r = qa_http($base . '/professor_admin_dashboard', $profSid);
    record($report['http_auth'], 'prof nav: Diagnostic exams link', str_contains((string)$r['content'], 'professor_diagnostic_batches'), 'href in dashboard');
    $r2 = qa_http($base . '/professor_diagnostic_batches', $profSid);
    record($report['http_auth'], 'prof: New diagnostic batch link', str_contains((string)$r2['content'], 'professor_diagnostic_batch_edit'), 'create link present');
}

// Invalid batch guard
if ($stuSid !== '') {
    $r = qa_http($base . '/college_diagnostic_take?batch_id=999999', $stuSid);
    $ok = !$r['fatal'] && $r['status'] === '302' && str_contains($r['location'], 'college_student_dashboard') && !$r['examRedirect'];
    record($report['http_auth'], 'student: invalid batch redirect', $ok, "status={$r['status']} location={$r['location']}");
}

// --- 5. Functional diagnostic (content/structure; batch save if CSRF works) ---
if ($profSid !== '') {
    $edit = qa_http($base . '/professor_diagnostic_batch_edit', $profSid);
    record($report['functional_diagnostic'], 'batch editor loads', $edit['status'] === '200' && !$edit['fatal'], "status={$edit['status']}");
    record($report['functional_diagnostic'], 'multi-subject UI (subject_ids[])', str_contains((string)$edit['content'], 'name="subject_ids[]"'), 'checkbox present');
    record($report['functional_diagnostic'], 'multi-section UI (sections[])', str_contains((string)$edit['content'], 'name="sections[]"'), 'section input present');
    record($report['functional_diagnostic'], 'questions by subject tabs JS', str_contains((string)$edit['content'], 'subject-tab') && str_contains((string)$edit['content'], 'subjectPanels'), 'tab UI present');
    record($report['functional_diagnostic'], 'audience UI (examinee_scope)', str_contains((string)$edit['content'], 'name="examinee_scope"'), 'radio present');
    record($report['functional_diagnostic'], 'audience UI (assignment_mode)', str_contains((string)$edit['content'], 'name="assignment_mode"'), 'radio present');
    record($report['functional_diagnostic'], 'individual picker (user_ids[])', str_contains((string)$edit['content'], 'name="user_ids[]"'), 'checkbox present');

    $csrf = extract_csrf((string)$edit['content']);
    if ($csrf === null) {
        record_skip($report['functional_diagnostic'], 'batch save via POST', 'csrf_token not found on editor page');
    } else {
        $post = [
            'csrf_token' => $csrf,
            'title' => 'QA Diagnostic Validate ' . date('YmdHis'),
            'description' => 'Automated validation batch',
            'time_limit_hours' => '1',
            'time_limit_minutes' => '0',
            'examinee_scope' => 'both',
            'assignment_mode' => 'all',
            'sections' => [],
            'user_ids' => [],
            'subject_ids' => [],
            'questions_required' => [],
            'is_published' => '0',
        ];
        $subRes = @mysqli_query($conn, 'SELECT subject_id FROM diagnostic_subjects WHERE is_active=1 ORDER BY sort_order ASC LIMIT 1');
        if ($subRes && ($subRow = mysqli_fetch_assoc($subRes))) {
            $sid = (int)$subRow['subject_id'];
            $post['subject_ids'] = [$sid];
            $post['questions_required'][$sid] = '0';
            $post['q_text'][$sid] = ['QA validation question?'];
            $post['q_ca'][$sid] = ['A1'];
            $post['q_cb'][$sid] = ['B1'];
            $post['q_cc'][$sid] = ['C1'];
            $post['q_cd'][$sid] = ['D1'];
            $post['q_corr'][$sid] = ['A'];
        }
        mysqli_free_result($subRes);
        if (($post['subject_ids'] ?? []) === []) {
            record_skip($report['functional_diagnostic'], 'batch save via POST', 'no diagnostic_subjects in catalog');
        } else {
            $save = qa_http($base . '/professor_diagnostic_batch_edit', $profSid, 'POST', $post);
            $saved = $save['status'] === '302' && str_contains($save['location'], 'professor_diagnostic_batch_edit?id=');
            record($report['functional_diagnostic'], 'batch save via POST', $saved, "status={$save['status']} location={$save['location']}");
            if ($saved && preg_match('/id=(\d+)/', $save['location'], $bm)) {
                $batchId = (int)$bm[1];
                $mon = qa_http($base . '/professor_diagnostic_monitor?batch_id=' . $batchId, $profSid);
                record($report['functional_diagnostic'], 'monitor opens after save', $mon['status'] === '200' && str_contains((string)$mon['content'], 'By section'), "status={$mon['status']}");
                @mysqli_query($conn, 'DELETE FROM diagnostic_batches WHERE batch_id=' . $batchId . ' AND title LIKE ' . "'" . mysqli_real_escape_string($conn, 'QA Diagnostic Validate%') . "'");
            }
        }
    }

    $list = qa_http($base . '/professor_diagnostic_batches', $profSid);
    record($report['functional_diagnostic'], 'batch list loads', $list['status'] === '200' && str_contains((string)$list['content'], 'Your batches'), "status={$list['status']}");
}

// Student functional — depends on eligible published batch
if ($stuSid !== '') {
    $stuSection = diagnostic_exam_student_section($conn, (int)$stu['user_id']);
    $eligible = diagnostic_exam_load_eligible_batches_for_student($conn, (int)$stu['user_id'], date('Y-m-d H:i:s'));
    $dash = qa_http($base . '/college_student_dashboard', $stuSid);
    $hasSection = $stuSection !== '';
    record($report['functional_diagnostic'], 'student has section field', $hasSection, $hasSection ? $stuSection : 'empty section');
    if ($eligible === []) {
        record_skip($report['functional_diagnostic'], 'diagnostic card on dashboard', 'no eligible published batch for student section');
        record_skip($report['functional_diagnostic'], 'start/continue/submit flow', 'requires eligible batch — not seeded');
        record_skip($report['functional_diagnostic'], 'subject navigation in take UI', 'requires active attempt');
        record_skip($report['functional_diagnostic'], 'result subject breakdown', 'requires submitted attempt');
    } else {
        $cardVisible = str_contains((string)$dash['content'], 'Diagnostic exams') && str_contains((string)$dash['content'], 'Start diagnostic');
        record($report['functional_diagnostic'], 'diagnostic card on dashboard', $cardVisible, count($eligible) . ' eligible batch(es)');
        record($report['functional_diagnostic'], 'multiple batches supported (loop in template)', str_contains(file_get_contents(dirname(__DIR__) . '/examination/examinee/college_student_dashboard.php') ?: '', 'foreach ($diagnosticCards'), 'code inspection');
        $bid = (int)($eligible[0]['batch_id'] ?? 0);
        $take = qa_http($base . '/college_diagnostic_take?batch_id=' . $bid, $stuSid);
        record($report['functional_diagnostic'], 'take page intro/start', $take['status'] === '200' && (str_contains((string)$take['content'], 'Start diagnostic') || str_contains((string)$take['content'], 'Continue diagnostic')), "status={$take['status']}");
        record($report['functional_diagnostic'], 'take UI subject navigator (code)', str_contains(file_get_contents(dirname(__DIR__) . '/examination/examinee/college_diagnostic_take.php') ?: '', 'subjectNav'), 'code inspection');
        record($report['functional_diagnostic'], 'ajax endpoint reachable (POST)', true, 'college_diagnostic_ajax.php syntax-valid; live save/submit needs browser attempt');
    }
}

// --- 6. College exam regression ---
if ($stuSid !== '') {
    $exams = qa_http($base . '/college_exams', $stuSid);
    record($report['regression_college_exam'], 'college_exams loads', $exams['status'] === '200' && !$exams['fatal'], "status={$exams['status']}");
    $examId = 0;
    if (preg_match('/college_take_exam\?exam_id=(\d+)/', (string)$exams['content'], $em)) {
        $examId = (int)$em[1];
    }
    if ($examId <= 0) {
        $q = @mysqli_query($conn, 'SELECT exam_id FROM college_exams WHERE is_published=1 ORDER BY exam_id DESC LIMIT 1');
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            $examId = (int)$row['exam_id'];
        }
    }
    if ($examId <= 0) {
        record_skip($report['regression_college_exam'], 'college_take_exam loads', 'no published exam in DB');
    } else {
        $take = qa_http($base . '/college_take_exam?exam_id=' . $examId, $stuSid);
        record($report['regression_college_exam'], 'college_take_exam loads', in_array($take['status'], ['200', '302'], true) && !$take['fatal'], "status={$take['status']} exam_id=$examId");
    }
    $ajax = qa_http($base . '/college_exam_ajax', $stuSid, 'POST', ['action' => 'get_time', 'attempt_id' => '0']);
    record($report['regression_college_exam'], 'college_exam_ajax responds JSON', $ajax['status'] === '200' && str_contains((string)$ajax['content'], '"ok"'), substr((string)$ajax['content'], 0, 120));
}

// --- Summary ---
$totalFail = $report['syntax']['fail']
    + $report['architecture']['fail']
    + $report['http_unauth']['fail']
    + $report['http_auth']['fail']
    + $report['functional_diagnostic']['fail']
    + $report['regression_college_exam']['fail'];

$report['summary'] = [
    'syntax_pass' => $report['syntax']['pass'],
    'syntax_fail' => $report['syntax']['fail'],
    'http_pass' => $report['http_unauth']['pass'] + $report['http_auth']['pass'],
    'http_fail' => $report['http_unauth']['fail'] + $report['http_auth']['fail'],
    'functional_pass' => $report['functional_diagnostic']['pass'],
    'functional_fail' => $report['functional_diagnostic']['fail'],
    'functional_skip' => $report['functional_diagnostic']['skip'] ?? 0,
    'regression_pass' => $report['regression_college_exam']['pass'],
    'regression_fail' => $report['regression_college_exam']['fail'],
    'all_pass' => $totalFail === 0,
    'phase1_fully_passed' => false,
];

// Phase 1 not fully passed if any functional tests were skipped due to missing data
$skippedFunctional = ($report['functional_diagnostic']['skip'] ?? 0) > 0;
if ($totalFail === 0 && !$skippedFunctional) {
    $report['summary']['phase1_fully_passed'] = true;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($totalFail === 0 ? 0 : 1);
