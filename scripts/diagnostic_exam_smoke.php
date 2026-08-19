<?php
declare(strict_types=1);

require dirname(__DIR__) . '/db.php';

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
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    require dirname(__DIR__) . '/session_config.php';
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['full_name'] = (string)$user['full_name'];
    $_SESSION['email'] = (string)($user['email'] ?? '');
    $_SESSION['role'] = (string)$user['role'];
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();
    $sid = session_id();
    session_write_close();
    return $sid;
}

function qa_http(string $url, string $cookie): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Cookie: PHPSESSID={$cookie}",
            'timeout' => 20,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);
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
    return compact('status', 'location', 'content', 'fatal');
}

$base = rtrim(getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview', '/');
$prof = qa_load_user($conn, 'professor_admin');
$stu = qa_load_user($conn, 'college_student');
if (!$prof || !$stu) {
    fwrite(STDERR, "Missing QA users\n");
    exit(1);
}

$profSid = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__FILE__) . '/diagnostic_session_bootstrap.php') . ' professor_admin'));
$stuSid = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__FILE__) . '/diagnostic_session_bootstrap.php') . ' college_student'));

$tests = [
    ['professor_diagnostic_batches', $profSid, 'Diagnostic exams', ['200']],
    ['professor_diagnostic_batch_edit', $profSid, 'diagnostic batch', ['200']],
    ['college_student_dashboard', $stuSid, 'College portal', ['200']],
    ['college_diagnostic_take?batch_id=999999', $stuSid, '', ['200', '302']],
];

$out = [];
$allPass = true;
foreach ($tests as [$path, $sid, $needle, $expect]) {
    $r = qa_http($base . '/' . $path, $sid);
    $examRed = str_contains($r['location'] ?? '', '/examination/');
    $hasNeedle = $needle === '' || str_contains((string)$r['content'], $needle);
    $ok = !$r['fatal'] && in_array($r['status'], $expect, true) && !$examRed && ($needle === '' || $hasNeedle || $r['status'] === '302');
    if (!$ok) {
        $allPass = false;
    }
    $out[] = [
        'url' => $base . '/' . $path,
        'status' => $r['status'],
        'location' => $r['location'],
        'fatal' => $r['fatal'],
        'exam_redirect' => $examRed,
        'pass' => $ok,
    ];
}

echo json_encode(['diagnostic_smoke' => $out, 'all_pass' => $allPass], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($allPass ? 0 : 1);
