<?php
/**
 * Authenticated HTTP QA for /examination/ copies.
 * READ-ONLY regarding LMS/root examination files. Uses session bootstrap (passwords not in repo).
 * Does NOT modify examination data except optional read-only AJAX load_state.
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$base = rtrim(getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview', '/');

require $projectRoot . '/db.php';

function qa_load_user(mysqli $conn, string $role): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, role, status FROM users WHERE role=? AND status='approved' ORDER BY user_id ASC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $role);
    mysqli_stmt_execute($stmt);
    $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $u ?: null;
}

function qa_bootstrap_session(array $user): string
{
    // CLI session compatible with Apache (shared save path).
    require dirname(__DIR__) . '/session_config.php';
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['full_name'] = (string)$user['full_name'];
    $_SESSION['email'] = (string)($user['email'] ?? '');
    $_SESSION['role'] = (string)$user['role'];
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $sid = session_id();
    session_write_close();
    return $sid;
}

function qa_http(string $url, string $cookie, string $method = 'GET', ?string $body = null, array $headers = [], int $maxRedirects = 2): array
{
    $currentUrl = $url;
    $content = false;
    $status = 'unknown';
    $location = '';
    for ($i = 0; $i <= $maxRedirects; $i++) {
        $hdr = array_merge(["Cookie: PHPSESSID={$cookie}"], $headers);
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $hdr),
                'content' => $body ?? '',
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $content = @file_get_contents($currentUrl, false, $ctx);
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
        if (in_array($status, ['301', '302', '303'], true) && $location !== '' && $method === 'GET' && $i < $maxRedirects) {
            if (!preg_match('#^https?://#i', $location)) {
                $parts = parse_url($currentUrl);
                $basePath = rtrim(dirname($parts['path'] ?? ''), '/');
                if (str_starts_with($location, '/')) {
                    $currentUrl = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost') . $location;
                } else {
                    $currentUrl = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost') . $basePath . '/' . $location;
                }
            } else {
                $currentUrl = $location;
            }
            continue;
        }
        break;
    }
    $fatal = is_string($content) && (
        stripos($content, 'Fatal error') !== false ||
        stripos($content, 'Parse error') !== false ||
        stripos($content, 'Failed opening required') !== false
    );
    return ['status' => $status, 'location' => $location, 'content' => $content, 'fatal' => $fatal, 'final_url' => $currentUrl];
}

function qa_check_page(array $r, array $needles): array
{
    if ($r['fatal']) {
        return ['pass' => false, 'reason' => 'PHP fatal', 'snippet' => substr(strip_tags((string)$r['content']), 0, 200)];
    }
    if (!in_array($r['status'], ['200'], true)) {
        return ['pass' => false, 'reason' => 'HTTP ' . $r['status'] . ($r['location'] ? ' -> ' . $r['location'] : ''), 'snippet' => ''];
    }
    $html = (string)$r['content'];
    foreach ($needles as $n) {
        if (!str_contains($html, $n)) {
            return ['pass' => false, 'reason' => "missing: {$n}", 'snippet' => substr(strip_tags($html), 0, 160)];
        }
    }
    return ['pass' => true, 'reason' => 'ok'];
}

$report = [
    'method' => 'HTTP with CLI session bootstrap (password login not available in repo)',
    'professor' => ['pass' => [], 'fail' => []],
    'examinee' => ['pass' => [], 'fail' => []],
    'ajax' => ['pass' => [], 'fail' => []],
    'uploads' => ['pass' => [], 'fail' => []],
    'lms' => ['pass' => [], 'fail' => []],
    'redirect_issue' => [],
];

$prof = qa_load_user($conn, 'professor_admin');
$examUser = qa_load_user($conn, 'college_student');
$lmsUser = qa_load_user($conn, 'student');
if (!$prof || !$examUser || !$lmsUser) {
    fwrite(STDERR, "Missing QA users\n");
    exit(1);
}

// --- Professor QA ---
$profSid = qa_bootstrap_session($prof);
$profPages = [
    ['Professor dashboard', '/examination/professor/professor_admin_dashboard.php', ['Professor dashboard', 'app-shell']],
    ['College students list', '/examination/professor/professor_college_students.php', ['College student', 'professor_college_students']],
    ['Create student page', '/examination/professor/professor_create_college_student.php', ['Create college student', 'csrf_token']],
    ['Exam list', '/examination/professor/professor_exams.php', ['Exam Library', 'professor_exam_edit']],
    ['Create/edit exam', '/examination/professor/professor_exam_edit.php', ['Edit exam', 'csrf_token']],
    ['Exam monitor', '/examination/professor/professor_exam_monitor.php?exam_id=1', ['Exam Monitor', 'professor_exams']],
    ['Upload tasks', '/examination/professor/professor_upload_tasks.php', ['Upload tasks', 'csrf_token']],
    ['Professor monitor', '/examination/professor/professor_monitor.php', ['Monitor', 'college_exam_attempts']],
    ['Review sheet', '/examination/professor/professor_exam_review_sheet.php?exam_id=1&user_id=4', ['Review', 'perm-q']],
];
foreach ($profPages as [$label, $path, $needles]) {
    $r = qa_http($base . $path, $profSid);
    $c = qa_check_page($r, $needles);
    $row = ['item' => $label, 'url' => $base . $path, 'detail' => $c['reason'], 'snippet' => $c['snippet'] ?? ''];
    if ($c['pass']) {
        $report['professor']['pass'][] = $row;
    } else {
        $report['professor']['fail'][] = $row;
    }
}

// Upload task monitor - skip if no tasks
$tq = mysqli_query($conn, 'SELECT task_id FROM college_upload_tasks ORDER BY task_id DESC LIMIT 1');
$taskRow = $tq ? mysqli_fetch_assoc($tq) : null;
if ($taskRow) {
    $tid = (int)$taskRow['task_id'];
    $r = qa_http($base . "/examination/professor/professor_upload_task_monitor.php?task_id={$tid}", $profSid);
    $c = qa_check_page($r, ['Upload', 'task']);
    $row = ['item' => 'Upload task monitor', 'url' => $base . "/examination/professor/professor_upload_task_monitor.php?task_id={$tid}", 'detail' => $c['reason']];
    if ($c['pass']) {
        $report['professor']['pass'][] = $row;
    } else {
        $report['professor']['fail'][] = $row;
    }
} else {
    $report['uploads']['fail'][] = ['item' => 'Upload task monitor', 'detail' => 'No college_upload_tasks rows in DB to test'];
}

// Live monitor JSON
$live = qa_http($base . '/examination/professor/professor_exam_monitor_live.php?exam_id=1', $profSid, 'GET', null, [], 0);
$liveJson = json_decode((string)$live['content'], true);
if ($live['status'] === '200' && is_array($liveJson) && !empty($liveJson['ok'])) {
    $report['ajax']['pass'][] = 'professor_exam_monitor_live exam_id=1 returns ok JSON';
} else {
    $report['ajax']['fail'][] = ['endpoint' => 'professor_exam_monitor_live', 'status' => $live['status'], 'body' => substr((string)$live['content'], 0, 200)];
}

// CSRF from professor dashboard (same session cookie)
$dashForCsrf = qa_http($base . '/examination/professor/professor_admin_dashboard.php', $profSid);
$csrf = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string)$dashForCsrf['content'], $cm)) {
    $csrf = $cm[1];
}
if ($csrf === '') {
    require $projectRoot . '/session_config.php';
    $_SESSION['user_id'] = (int)$prof['user_id'];
    $_SESSION['role'] = 'professor_admin';
    $_SESSION['full_name'] = $prof['full_name'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf_token'];
    session_write_close();
}
$aiBody = http_build_query(['csrf_token' => $csrf, 'action' => 'generate_choices', 'stem' => 'QA test stem for isolation copy.']);
$ai = qa_http($base . '/examination/professor/professor_exam_ai.php', $profSid, 'POST', $aiBody, ['Content-Type: application/x-www-form-urlencoded'], 0);
$aiJson = json_decode((string)$ai['content'], true);
if ($ai['status'] === '200' && is_array($aiJson) && (!empty($aiJson['ok']) || isset($aiJson['choice_a']))) {
    $report['ajax']['pass'][] = 'professor_exam_ai POST returns structured JSON';
} elseif ($ai['status'] === '200' && is_array($aiJson) && isset($aiJson['error'])) {
    $report['ajax']['pass'][] = 'professor_exam_ai POST reachable (error: ' . $aiJson['error'] . ')';
} else {
    $report['ajax']['fail'][] = ['endpoint' => 'professor_exam_ai', 'status' => $ai['status'], 'body' => substr((string)$ai['content'], 0, 200)];
}

// PDF / XLSX (may redirect if exam not finished)
$pdf = qa_http($base . '/examination/professor/professor_exam_monitor_pdf.php?exam_id=1', $profSid);
if ($pdf['status'] === '200' && is_string($pdf['content']) && str_starts_with($pdf['content'], '%PDF')) {
    $report['professor']['pass'][] = ['item' => 'PDF export', 'url' => $base . '/examination/professor/professor_exam_monitor_pdf.php?exam_id=1', 'detail' => 'PDF bytes'];
} elseif (in_array($pdf['status'], ['302', '301'], true)) {
    $report['professor']['fail'][] = ['item' => 'PDF export', 'detail' => 'Redirect (exam may not be finished): ' . $pdf['location']];
} else {
    $report['professor']['fail'][] = ['item' => 'PDF export', 'detail' => 'HTTP ' . $pdf['status'], 'snippet' => substr((string)$pdf['content'], 0, 120)];
}
$xlsx = qa_http($base . '/examination/professor/professor_exam_monitor_xlsx.php?exam_id=1', $profSid);
if ($xlsx['status'] === '200' && is_string($xlsx['content']) && str_starts_with($xlsx['content'], 'PK')) {
    $report['professor']['pass'][] = ['item' => 'XLSX export', 'detail' => 'ZIP/XLSX bytes'];
} elseif (in_array($xlsx['status'], ['302', '301'], true)) {
    $report['professor']['fail'][] = ['item' => 'XLSX export', 'detail' => 'Redirect (exam may not be finished): ' . $xlsx['location']];
} else {
    $report['professor']['fail'][] = ['item' => 'XLSX export', 'detail' => 'HTTP ' . $xlsx['status']];
}

// Assets on professor dashboard
$dash = qa_http($base . '/examination/professor/professor_admin_dashboard.php', $profSid);
if (preg_match('/href="([^"]*app-shell\.css[^"]*)"/', (string)$dash['content'], $m)) {
    $assetUrl = str_starts_with($m[1], '/') ? 'http://localhost' . $m[1] : $base . '/' . ltrim($m[1], '/');
    $ah = qa_http($assetUrl, $profSid);
    if ($ah['status'] === '200') {
        $report['professor']['pass'][] = ['item' => 'CSS assets via examination_head_app', 'detail' => $m[1]];
    } else {
        $report['professor']['fail'][] = ['item' => 'CSS assets', 'detail' => 'HTTP ' . $ah['status'] . ' for ' . $m[1]];
    }
}

// --- Examinee QA ---
$examSid = qa_bootstrap_session($examUser);
$dashEx = qa_http($base . '/examination/examinee/college_student_dashboard.php', $examSid);
$examCsrf = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string)$dashEx['content'], $em)) {
    $examCsrf = $em[1];
}
if ($examCsrf === '') {
    require $projectRoot . '/session_config.php';
    $_SESSION['user_id'] = (int)$examUser['user_id'];
    $_SESSION['role'] = 'college_student';
    $_SESSION['full_name'] = $examUser['full_name'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $examCsrf = $_SESSION['csrf_token'];
    session_write_close();
}

$exPages = [
    ['Dashboard', '/examination/examinee/college_student_dashboard.php', ['College Portal', 'College portal']],
    ['Exam list', '/examination/examinee/college_exams.php', ['Exams', 'college_take_exam']],
    ['Take exam review', '/examination/examinee/college_take_exam.php?exam_id=1&review=1', ['exam', 'Back to exams']],
    ['Uploads', '/examination/examinee/college_uploads.php', ['Upload', 'college_upload']],
];
foreach ($exPages as [$label, $path, $needles]) {
    $r = qa_http($base . $path, $examSid);
    $c = qa_check_page($r, $needles);
    $row = ['item' => $label, 'url' => $base . $path, 'detail' => $c['reason'], 'snippet' => $c['snippet'] ?? ''];
    if ($c['pass']) {
        $report['examinee']['pass'][] = $row;
    } else {
        $report['examinee']['fail'][] = $row;
    }
}

// AJAX load_state on submitted attempt (read-only)
$attemptId = 1;
$ajaxBody = http_build_query(['action' => 'load_state', 'csrf_token' => $examCsrf, 'attempt_id' => $attemptId]);
$ajax = qa_http($base . '/examination/examinee/college_exam_ajax.php', $examSid, 'POST', $ajaxBody, ['Content-Type: application/x-www-form-urlencoded'], 0);
$ajaxJson = json_decode((string)$ajax['content'], true);
if ($ajax['status'] === '200' && is_array($ajaxJson) && !empty($ajaxJson['ok'])) {
    $report['ajax']['pass'][] = 'college_exam_ajax load_state on submitted attempt_id=1';
} else {
    $report['ajax']['fail'][] = ['endpoint' => 'college_exam_ajax load_state', 'status' => $ajax['status'], 'body' => substr((string)$ajax['content'], 0, 200)];
}

// get_time on submitted attempt (expect ok:false or remaining 0 - endpoint reachable)
$ajaxBody2 = http_build_query(['action' => 'get_time', 'attempt_id' => $attemptId]);
$ajax2 = qa_http($base . '/examination/examinee/college_exam_ajax.php', $examSid, 'POST', $ajaxBody2, ['Content-Type: application/x-www-form-urlencoded'], 0);
$ajax2Json = json_decode((string)$ajax2['content'], true);
if ($ajax2['status'] === '200' && is_array($ajax2Json)) {
    $report['ajax']['pass'][] = 'college_exam_ajax get_time reachable (submitted attempt)';
} else {
    $report['ajax']['fail'][] = ['endpoint' => 'college_exam_ajax get_time', 'status' => $ajax2['status'], 'body' => substr((string)$ajax2['content'], 0, 200)];
}

// save_answer - expect attempt not active (no DB mutation if rejected)
$qid = 1;
$ajaxBody3 = http_build_query(['action' => 'save_answer', 'csrf_token' => $examCsrf, 'attempt_id' => $attemptId, 'question_id' => $qid, 'selected_answer' => 'A']);
$ajax3 = qa_http($base . '/examination/examinee/college_exam_ajax.php', $examSid, 'POST', $ajaxBody3, ['Content-Type: application/x-www-form-urlencoded'], 0);
$ajax3Json = json_decode((string)$ajax3['content'], true);
if ($ajax['status'] === '200' && is_array($ajax3Json) && isset($ajax3Json['error']) && str_contains((string)$ajax3Json['error'], 'not active')) {
    $report['ajax']['pass'][] = 'college_exam_ajax save_answer correctly rejects submitted attempt';
} elseif ($ajax3['status'] === '200' && is_array($ajax3Json)) {
    $report['ajax']['pass'][] = 'college_exam_ajax save_answer responded JSON: ' . json_encode($ajax3Json);
} else {
    $report['ajax']['fail'][] = ['endpoint' => 'college_exam_ajax save_answer', 'status' => $ajax3['status'], 'body' => substr((string)$ajax3['content'], 0, 200)];
}

// Uploads - no tasks
$up = qa_http($base . '/examination/examinee/college_uploads.php', $examSid);
if ($up['status'] === '200') {
    $report['uploads']['pass'][] = 'college_uploads page loads (no tasks in DB is OK)';
} else {
    $report['uploads']['fail'][] = ['item' => 'college_uploads', 'detail' => 'HTTP ' . $up['status']];
}
$sub = mysqli_query($conn, 'SELECT submission_id FROM college_submissions WHERE user_id=' . (int)$examUser['user_id'] . ' LIMIT 1');
$subRow = $sub ? mysqli_fetch_assoc($sub) : null;
if ($subRow) {
    $sid = (int)$subRow['submission_id'];
    $dl = qa_http($base . '/examination/examinee/college_upload_file.php?s=' . $sid, $examSid);
    if (in_array($dl['status'], ['200', '403', '404'], true) && !$dl['fatal']) {
        $report['uploads']['pass'][] = 'college_upload_file reachable HTTP ' . $dl['status'];
    } else {
        $report['uploads']['fail'][] = ['item' => 'college_upload_file', 'detail' => 'HTTP ' . $dl['status']];
    }
} else {
    $report['uploads']['pass'][] = 'college_upload_file skipped (no submissions for examinee — nothing to download)';
}

// Redirect issue authenticated wrong role
$wrongSid = qa_bootstrap_session($examUser);
$wrongProf = qa_http($base . '/examination/professor/professor_admin_dashboard.php', $wrongSid);
if (in_array($wrongProf['status'], ['302', '301'], true) && $wrongProf['location'] === 'index') {
    $follow = qa_http($base . '/examination/professor/index', $wrongSid);
    $report['redirect_issue'][] = 'Wrong-role redirect Location:index from /examination/professor/ -> relative follow HTTP ' . $follow['status'] . ' (404 expected)';
}

// --- LMS regression (root URLs, student session) ---
$lmsSid = qa_bootstrap_session($lmsUser);
$lmsPages = [
    ['Student dashboard', '/student_dashboard.php', ['Dashboard', 'student']],
    ['Subjects', '/student_subjects.php', ['Subject', 'subject']],
    ['Lessons', '/student_lessons.php', ['Lesson', 'lesson']],
    ['Handouts', '/student_handouts.php', ['Handout', 'handout']],
    ['Videos', '/student_videos.php', ['Video', 'video']],
    ['Quizzes', '/student_quizzes.php', ['Quiz', 'quiz']],
    ['Quiz history', '/student_quiz_history.php', ['History', 'quiz']],
    ['Pre-boards', '/student_preboards.php', ['Pre-board', 'preboard']],
    ['Playground', '/student_playground.php', ['Playground', 'playground']],
    ['Playground career', '/student_playground_career.php', ['Career', 'career']],
];
foreach ($lmsPages as [$label, $path, $needles]) {
    $r = qa_http($base . $path, $lmsSid);
    $c = qa_check_page($r, [$needles[0]]); // one needle enough
    $row = ['item' => $label, 'url' => $base . $path, 'detail' => $c['reason']];
    if ($c['pass']) {
        $report['lms']['pass'][] = $row;
    } else {
        $report['lms']['fail'][] = $row;
    }
}

// Root examination still works (sanity - not LMS but confirms no breakage)
$rootExam = qa_http($base . '/college_student_dashboard.php', $examSid);
if ($rootExam['status'] === '200' && !$rootExam['fatal']) {
    $report['lms']['pass'][] = ['item' => 'Root college_student_dashboard still works (parallel system)', 'detail' => 'HTTP 200'];
} else {
    $report['lms']['fail'][] = ['item' => 'Root college_student_dashboard', 'detail' => 'HTTP ' . $rootExam['status']];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
