<?php
/**
 * HTTP smoke test for student_registration (open page, no login required).
 */
declare(strict_types=1);

$base = rtrim(getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview', '/');
$pageUrl = $base . '/student_registration';

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 15,
        'ignore_errors' => true,
        'follow_location' => 0,
    ],
]);
$body = (string) @file_get_contents($pageUrl, false, $ctx);
$status = 'unknown';
if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
    $status = $m[0];
}

$ok = $status === '200'
    && str_contains($body, 'Student Registration')
    && str_contains($body, 'name="csrf_token"')
    && !str_contains($body, 'Professor Admin Only');

echo "URL: {$pageUrl}\n";
echo 'HTTP: ' . $status . "\n";
echo ($ok ? 'PASS' : 'FAIL') . ' — open college student registration page' . "\n";
exit($ok ? 0 : 1);
