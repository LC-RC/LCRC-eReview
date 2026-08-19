<?php
declare(strict_types=1);

$base = rtrim(getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview', '/');

function http_probe(string $url, bool $follow = false): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => $follow ? 1 : 0,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
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
    $fatal = is_string($body) && (
        stripos($body, 'Fatal error') !== false ||
        stripos($body, 'Parse error') !== false ||
        stripos($body, 'Failed opening required') !== false
    );
    $examRedirect = $location !== '' && str_contains($location, '/examination/');

    return compact('status', 'location', 'fatal', 'examRedirect', 'body');
}

$tests = [
    ['name' => 'professor_admin_dashboard', 'url' => $base . '/professor_admin_dashboard', 'expect' => ['200', '302']],
    ['name' => 'college_student_dashboard', 'url' => $base . '/college_student_dashboard', 'expect' => ['200', '302']],
    ['name' => 'professor_exams', 'url' => $base . '/professor_exams', 'expect' => ['200', '302']],
    ['name' => 'logout', 'url' => $base . '/logout', 'expect' => ['302', '301', '303']],
];

$results = [];
$allPass = true;
foreach ($tests as $t) {
    $r = http_probe($t['url'], $t['name'] === 'logout');
    $ok = !$r['fatal'] && in_array($r['status'], $t['expect'], true) && !$r['examRedirect'];
    if ($t['name'] === 'logout' && $r['status'] === '302' && $r['location'] !== '') {
        $ok = $ok && (stripos($r['location'], 'login') !== false);
    }
    if (!$ok) {
        $allPass = false;
    }
    $results[] = [
        'name' => $t['name'],
        'url' => $t['url'],
        'status' => $r['status'],
        'location' => $r['location'],
        'fatal' => $r['fatal'],
        'exam_redirect' => $r['examRedirect'],
        'pass' => $ok,
    ];
}

// Nav link check: fetch login page is not needed; grep professor dashboard dest for root hrefs
$dash = file_get_contents(dirname(__DIR__) . '/examination/professor/professor_admin_dashboard.php');
$badNav = [];
if (preg_match_all('/href="([^"]*examination\/[^"]*)"/', $dash, $m)) {
    $badNav = array_values(array_unique($m[1]));
}

echo json_encode([
    'http_tests' => $results,
    'all_pass' => $allPass,
    'bad_examination_hrefs_in_prof_dashboard' => $badNav,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($allPass && $badNav === [] ? 0 : 1);
