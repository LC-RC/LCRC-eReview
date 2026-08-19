<?php
/**
 * READ-ONLY validation of /examination/ copies. Does not modify any project files.
 * CLI: php scripts/examination_validate_copies_readonly.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$report = [
    'syntax' => ['pass' => 0, 'fail' => []],
    'requires' => ['pass' => 0, 'fail' => []],
    'shared_targets' => ['pass' => 0, 'fail' => []],
    'exam_includes' => ['pass' => 0, 'fail' => []],
    'upload_paths' => ['pass' => 0, 'fail' => []],
    'ajax' => [],
    'head_bridge' => [],
    'http' => ['pass' => 0, 'fail' => []],
    'ui_terms' => [],
];

function resolve_require_path(string $fromFile, string $matchLine): ?string
{
    $base = dirname($fromFile);
    if (preg_match("/dirname\\(__DIR__, 2\\)\\s*\\.\\s*'([^']+)'/", $matchLine, $m)) {
        return realpath($base . '/../..' . $m[1]) ?: null;
    }
    if (preg_match("/dirname\\(__DIR__\\)\\s*\\.\\s*'([^']+)'/", $matchLine, $m)) {
        return realpath($base . '/..' . $m[1]) ?: null;
    }
    if (preg_match("/__DIR__\\s*\\.\\s*'\\/([^']+)'/", $matchLine, $m)) {
        return realpath($base . '/' . $m[1]) ?: null;
    }

    return null;
}

$allPhp = array_merge(
    glob($root . '/examination/professor/*.php') ?: [],
    glob($root . '/examination/examinee/*.php') ?: [],
    glob($root . '/examination/includes/*.php') ?: []
);

foreach ($allPhp as $file) {
    $lint = shell_exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($file) . ' 2>&1');
    if (is_string($lint) && str_contains($lint, 'No syntax errors')) {
        $report['syntax']['pass']++;
    } else {
        $report['syntax']['fail'][] = [basename($file), trim((string) $lint)];
    }

    $content = file_get_contents($file);
    if (!is_string($content)) {
        continue;
    }
    if (preg_match_all('/(?:require_once|require)\s+([^;]+);/', $content, $matches)) {
        foreach ($matches[1] as $expr) {
            $expr = trim($expr);
            if (!str_contains($expr, '__DIR__')) {
                continue;
            }
            $resolved = resolve_require_path($file, $expr);
            if ($resolved && is_file($resolved)) {
                $report['requires']['pass']++;
            } else {
                $report['requires']['fail'][] = [
                    str_replace($root . DIRECTORY_SEPARATOR, '', $file),
                    $expr,
                ];
            }
        }
    }
}

$sharedMustExist = [
    'auth.php',
    'db.php',
    'session_config.php',
    'includes/quiz_helpers.php',
    'includes/simple_markdown.php',
    'includes/url_helpers.php',
    'includes/format_display_name.php',
    'includes/profile_avatar.php',
    'includes/ai_config.php',
    'includes/head_app.php',
    'includes/components/app_shell_sidebar.php',
];
foreach ($sharedMustExist as $rel) {
    $p = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($p)) {
        $report['shared_targets']['pass']++;
    } else {
        $report['shared_targets']['fail'][] = $rel;
    }
}

$examInc = [
    'college_schema.php',
    'college_exam_helpers.php',
    'college_upload_helpers.php',
    'college_take_exam_review_submitted_section.php',
    'exam_monitor_progress_rows.php',
    'exam_progress_report_pdf.php',
    'exam_progress_report_xlsx.php',
    'examination_head_app.php',
];
foreach ($examInc as $name) {
    $p = $root . '/examination/includes/' . $name;
    if (is_file($p)) {
        $report['exam_includes']['pass']++;
    } else {
        $report['exam_includes']['fail'][] = $name;
    }
}

// Chain: exam_monitor -> college_exam_helpers -> simple_markdown
$chainOk = true;
$emr = $root . '/examination/includes/exam_monitor_progress_rows.php';
$ceh = $root . '/examination/includes/college_exam_helpers.php';
$sm = $root . '/includes/simple_markdown.php';
if (!is_file($emr) || !is_file($ceh) || !is_file($sm)) {
    $chainOk = false;
} else {
    $emrContent = file_get_contents($emr);
    $cehContent = file_get_contents($ceh);
    if (!str_contains((string) $emrContent, "college_exam_helpers.php")) {
        $chainOk = false;
    }
    if (!str_contains((string) $cehContent, "includes/simple_markdown.php")) {
        $chainOk = false;
    }
}
$report['include_chain'] = $chainOk ? 'PASS' : 'FAIL';

// Upload path literals in copies
$uploadChecks = [
    'examination/examinee/college_upload_task.php' => "dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads'",
    'examination/examinee/college_upload_file.php' => 'college_upload_resolve_storage_path(dirname(__DIR__, 2)',
    'examination/professor/professor_upload_tasks.php' => 'college_upload_delete_task_files($conn, $tid, dirname(__DIR__, 2))',
    'examination/professor/professor_create_college_student.php' => "dirname(__DIR__, 2) . '/uploads/profile_pictures'",
];
foreach ($uploadChecks as $rel => $needle) {
    $p = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $c = is_file($p) ? file_get_contents($p) : '';
    if (is_string($c) && str_contains($c, $needle)) {
        $report['upload_paths']['pass']++;
    } else {
        $report['upload_paths']['fail'][] = [$rel, $needle];
    }
}
$uploadsDir = $root . '/uploads/college';
$report['uploads_dir_exists'] = is_dir($uploadsDir) ? 'yes' : 'no (may be created at runtime)';

// AJAX source inspection
$ajaxMap = [
    'examination/examinee/college_take_exam.php' => 'college_exam_ajax',
    'examination/professor/professor_exam_edit.php' => 'professor_exam_ai',
    'examination/professor/professor_exam_monitor.php' => 'professor_exam_monitor_live',
];
foreach ($ajaxMap as $rel => $endpoint) {
    $pagePath = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $ajaxFile = dirname($pagePath) . '/' . $endpoint . '.php';
    $report['ajax'][] = [
        'page' => $rel,
        'endpoint_literal' => $endpoint,
        'ajax_file_same_dir' => is_file($ajaxFile),
        'resolved_relative_url_from_page_dir' => dirname(str_replace($root . '/', '', $pagePath)) . '/' . $endpoint,
        'note' => 'Browser resolves relative to current page URL path; extensionless depends on root .htaccess',
    ];
}

// examination_head_app bridge simulation
$headBridge = $root . '/examination/includes/examination_head_app.php';
$simScript = '/Ereview/examination/professor/professor_admin_dashboard.php';
$_SERVER['SCRIPT_NAME'] = $simScript;
$saved = $_SERVER['SCRIPT_NAME'];
$adjusted = preg_replace('#/examination/(?:professor|examinee)/[^/]+$#', '/index.php', $saved);
$baseAfter = rtrim(dirname($adjusted ?? ''), '/');
$report['head_bridge'] = [
    'simulated_script_name' => $simScript,
    'adjusted_script_name' => $adjusted,
    'computed_asset_base' => $baseAfter,
    'expected_contains' => '/Ereview',
    'pass' => is_string($adjusted) && str_ends_with(rtrim($baseAfter, '/'), '/Ereview'),
];

// DB connectivity (read-only)
$dbOk = false;
$dbErr = '';
try {
    require_once $root . '/session_config.php';
    require_once $root . '/db.php';
    if (isset($conn) && $conn instanceof mysqli) {
        $res = @$conn->query("SHOW TABLES LIKE 'college_exams'");
        $dbOk = $res && $res->num_rows > 0;
        if ($res) {
            mysqli_free_result($res);
        }
    }
} catch (Throwable $e) {
    $dbErr = $e->getMessage();
}
$report['database'] = ['connect' => isset($conn) && $conn instanceof mysqli, 'college_exams_table' => $dbOk, 'error' => $dbErr];

// UI terminology scan (examination copies only)
$terms = ['College Portal', 'College portal', 'college student', 'college_student', 'College Student', 'Professor dashboard', 'College'];
$uiHits = [];
foreach ($allPhp as $file) {
    $content = file_get_contents($file);
    if (!is_string($content)) {
        continue;
    }
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
    foreach ($terms as $term) {
        if (stripos($content, $term) !== false) {
            $uiHits[$rel][] = $term;
        }
    }
}
$report['ui_terms'] = $uiHits;

// HTTP probe (optional)
$baseUrl = getenv('EREVIEW_TEST_BASE') ?: 'http://localhost/Ereview';
$httpPaths = [
    '/examination/professor/professor_admin_dashboard.php',
    '/examination/professor/professor_college_students.php',
    '/examination/professor/professor_exams.php',
    '/examination/professor/professor_exam_edit.php',
    '/examination/professor/professor_exam_monitor.php',
    '/examination/professor/professor_upload_tasks.php',
    '/examination/professor/professor_monitor.php',
    '/examination/examinee/college_student_dashboard.php',
    '/examination/examinee/college_exams.php',
    '/examination/examinee/college_take_exam.php',
    '/examination/examinee/college_uploads.php',
];
foreach ($httpPaths as $path) {
    $url = rtrim($baseUrl, '/') . $path;
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 'unknown';
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $sm)) {
        $status = $sm[0];
    }
    $isFatal = is_string($body) && (
        str_contains($body, 'Fatal error') ||
        str_contains($body, 'Parse error') ||
        str_contains($body, 'Warning: require') ||
        str_contains($body, 'Failed opening required')
    );
    if ($isFatal) {
        $report['http']['fail'][] = ['url' => $url, 'status' => $status, 'snippet' => substr(strip_tags($body), 0, 200)];
    } elseif (in_array($status, ['200', '302', '301', '303'], true)) {
        $report['http']['pass']++;
        $report['http']['details'][] = ['url' => $url, 'status' => $status];
    } else {
        $report['http']['fail'][] = ['url' => $url, 'status' => $status, 'snippet' => is_string($body) ? substr($body, 0, 120) : 'no body'];
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
