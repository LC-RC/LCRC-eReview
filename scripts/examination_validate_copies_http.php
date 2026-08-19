<?php
/** READ-ONLY extended HTTP/asset tests for /examination/ copies */
$root = dirname(__DIR__);
$base = getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview';

function http_get(string $url, bool $follow = false): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
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
        stripos($body, 'Failed opening required') !== false ||
        stripos($body, 'Warning: require') !== false
    );

    return compact('status', 'location', 'fatal', 'body');
}

$out = ['extensionless' => [], 'ajax_endpoints' => [], 'assets' => []];

$pages = [
    '/examination/professor/professor_admin_dashboard',
    '/examination/professor/professor_college_students',
    '/examination/professor/professor_exams',
    '/examination/professor/professor_exam_edit',
    '/examination/professor/professor_exam_monitor',
    '/examination/professor/professor_upload_tasks',
    '/examination/professor/professor_monitor',
    '/examination/examinee/college_student_dashboard',
    '/examination/examinee/college_exams',
    '/examination/examinee/college_take_exam',
    '/examination/examinee/college_uploads',
];

foreach ($pages as $path) {
    $url = rtrim($base, '/') . $path;
    $r = http_get($url, false);
    $out['extensionless'][] = [
        'url' => $url,
        'status' => $r['status'],
        'location' => $r['location'],
        'fatal' => $r['fatal'],
        'pass' => !$r['fatal'] && in_array($r['status'], ['200', '302', '301', '303'], true),
    ];
}

$ajax = [
    '/examination/examinee/college_exam_ajax',
    '/examination/professor/professor_exam_ai',
    '/examination/professor/professor_exam_monitor_live?exam_id=1',
];
foreach ($ajax as $path) {
    $url = rtrim($base, '/') . $path;
    $r = http_get($url, false);
    $out['ajax_endpoints'][] = [
        'url' => $url,
        'status' => $r['status'],
        'location' => $r['location'],
        'fatal' => $r['fatal'],
        'snippet' => is_string($r['body']) ? substr(strip_tags($r['body']), 0, 120) : '',
        'pass' => !$r['fatal'] && in_array($r['status'], ['200', '302', '301', '403', '405'], true),
    ];
}

require_once $root . '/auth.php';
$_SERVER['SCRIPT_NAME'] = '/Ereview/examination/professor/professor_admin_dashboard.php';
ob_start();
include $root . '/examination/includes/examination_head_app.php';
$head = ob_get_clean();
preg_match_all('/href="([^"]*assets[^"]*)"/', $head, $m);
$assets = array_values(array_unique($m[1] ?? []));
foreach (array_slice($assets, 0, 6) as $href) {
    $url = str_starts_with($href, '/') ? 'http://localhost' . $href : rtrim($base, '/') . '/' . ltrim($href, '/');
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true]]);
    @file_get_contents($url, false, $ctx);
    $st = $http_response_header[0] ?? 'fail';
    $out['assets'][] = ['href' => $href, 'probed' => $url, 'status' => $st, 'pass' => str_contains($st, '200') || str_contains($st, '304')];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
