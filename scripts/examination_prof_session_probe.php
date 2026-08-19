<?php
declare(strict_types=1);
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/session_config.php';

$stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, role FROM users WHERE role='professor_admin' AND status='approved' LIMIT 1");
mysqli_stmt_execute($stmt);
$prof = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$prof['user_id'];
$_SESSION['full_name'] = (string)$prof['full_name'];
$_SESSION['email'] = (string)($prof['email'] ?? '');
$_SESSION['role'] = 'professor_admin';
$_SESSION['created'] = time();
$_SESSION['last_activity'] = time();
$sid = session_id();
$savePath = session_save_path();
session_write_close();

function probe(string $url, string $sid): array {
    $ctx = stream_context_create(['http' => ['header' => "Cookie: PHPSESSID={$sid}", 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15]]);
    $body = @file_get_contents($url, false, $ctx);
    preg_match('/\d{3}/', $http_response_header[0] ?? '', $m);
    $loc = '';
    foreach ((array)$http_response_header as $h) {
        if (stripos($h, 'Location:') === 0) {
            $loc = trim(substr($h, 9));
        }
    }
    return ['status' => $m[0] ?? '?', 'location' => $loc, 'has_prof' => str_contains((string)$body, 'Professor dashboard'), 'fatal' => str_contains((string)$body, 'Fatal error')];
}

$base = 'http://localhost/Ereview';
$urls = [
    "$base/professor_admin_dashboard",
    "$base/examination/professor/professor_admin_dashboard.php",
];
$out = ['session_id' => $sid, 'session_save_path' => $savePath, 'prof_user_id' => $prof['user_id'] ?? null, 'probes' => []];
foreach ($urls as $u) {
    $out['probes'][$u] = probe($u, $sid);
}
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
