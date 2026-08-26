<?php
/**
 * Access-control QA for professor_register_college_student (read-only HTTP checks).
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$base = rtrim(getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview', '/');
$pageUrl = $base . '/professor_register_college_student';

require $projectRoot . '/db.php';
require $projectRoot . '/session_config.php';

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
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['full_name'] = (string) $user['full_name'];
    $_SESSION['email'] = (string) ($user['email'] ?? '');
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $sid = session_id();
    session_write_close();

    return $sid;
}

function qa_http_get(string $url, ?string $cookie = null): array
{
    $headers = $cookie ? ["Cookie: PHPSESSID={$cookie}"] : [];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
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
    foreach ((array) $http_response_header as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }

    return ['status' => $status, 'location' => $location, 'content' => (string) $content];
}

$results = [];

// Anonymous (no cookie)
$r = qa_http_get($pageUrl);
$blocked = in_array($r['status'], ['302', '301'], true) || str_contains($r['location'], 'index') || str_contains($r['location'], 'login');
$results['anonymous'] = $blocked ? 'PASS (redirected to login/index)' : 'FAIL status=' . $r['status'] . ' loc=' . $r['location'];

foreach (['professor_admin', 'college_student', 'student'] as $role) {
    require $projectRoot . '/session_config.php';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $user = qa_load_user($conn, $role);
    if (!$user) {
        $results[$role] = 'SKIP (no user)';
        continue;
    }
    $sid = qa_bootstrap_session($user);
    $r = qa_http_get($pageUrl, $sid);
    if ($role === 'professor_admin') {
        $ok = $r['status'] === '200'
            && str_contains($r['content'], 'Register College Student')
            && str_contains($r['content'], 'Professor Admin Only');
        $results[$role] = $ok ? 'PASS (200 + registration form)' : 'FAIL status=' . $r['status'];
    } else {
        $blocked = in_array($r['status'], ['302', '301'], true)
            || str_contains($r['location'], 'index')
            || !str_contains($r['content'], 'Register College Student');
        $results[$role] = $blocked ? 'PASS (denied/redirected)' : 'FAIL status=' . $r['status'];
    }
}

echo "QA professor_register_college_student access\n";
echo "URL: {$pageUrl}\n\n";
foreach ($results as $who => $out) {
    echo str_pad($who, 18) . $out . "\n";
}
$fail = false;
foreach ($results as $out) {
    if (str_starts_with($out, 'FAIL')) {
        $fail = true;
    }
}
exit($fail ? 1 : 0);
