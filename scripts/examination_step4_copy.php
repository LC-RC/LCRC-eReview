<?php
/**
 * Step 4 — COPY ONLY: duplicate Examination System files into /examination/
 * and fix paths inside copies. Does not read/write any root LMS or root Examination originals.
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$professorFiles = [
    'professor_admin_dashboard.php',
    'professor_admin_sidebar.php',
    'professor_college_students.php',
    'professor_create_college_student.php',
    'professor_college_student_view.php',
    'professor_college_student_delete.php',
    'professor_exams.php',
    'professor_exam_edit.php',
    'professor_exam_ai.php',
    'professor_exam_monitor.php',
    'professor_exam_monitor_live.php',
    'professor_exam_monitor_pdf.php',
    'professor_exam_monitor_xlsx.php',
    'professor_exam_review_sheet.php',
    'professor_upload_tasks.php',
    'professor_upload_task_monitor.php',
    'professor_monitor.php',
];

$examineeFiles = [
    'college_student_dashboard.php',
    'college_student_sidebar.php',
    'college_exams.php',
    'college_take_exam.php',
    'college_exam_ajax.php',
    'college_exams_debug.php',
    'college_uploads.php',
    'college_upload_task.php',
    'college_upload_file.php',
];

$includeFiles = [
    'includes/college_schema.php',
    'includes/college_exam_helpers.php',
    'includes/college_upload_helpers.php',
    'includes/college_take_exam_review_submitted_section.php',
    'includes/exam_monitor_progress_rows.php',
    'includes/exam_progress_report_pdf.php',
    'includes/exam_progress_report_xlsx.php',
];

function patch_examination_page_content(string $content, string $basename): string
{
    $examInc = "dirname(__DIR__) . '/includes/";
    $rootInc = "dirname(__DIR__, 2) . '/includes/";
    $rootAuth = "dirname(__DIR__, 2) . '/auth.php'";

    $replacements = [
        "require_once __DIR__ . '/includes/head_app.php'" => "require_once dirname(__DIR__) . '/includes/examination_head_app.php'",
        "require_once __DIR__ . '/includes/exam_monitor_progress_rows.php'" => "require_once {$examInc}exam_monitor_progress_rows.php'",
        "require_once __DIR__ . '/includes/exam_progress_report_pdf.php'" => "require_once {$examInc}exam_progress_report_pdf.php'",
        "require_once __DIR__ . '/includes/exam_progress_report_xlsx.php'" => "require_once {$examInc}exam_progress_report_xlsx.php'",
        "require __DIR__ . '/includes/college_take_exam_review_submitted_section.php'" => "require dirname(__DIR__) . '/includes/college_take_exam_review_submitted_section.php'",
        "require_once __DIR__ . '/includes/college_schema.php'" => "require_once {$examInc}college_schema.php'",
        "require_once __DIR__ . '/includes/college_exam_helpers.php'" => "require_once {$examInc}college_exam_helpers.php'",
        "require_once __DIR__ . '/includes/college_upload_helpers.php'" => "require_once {$examInc}college_upload_helpers.php'",
        "require_once __DIR__ . '/includes/quiz_helpers.php'" => "require_once {$rootInc}quiz_helpers.php'",
        "require_once __DIR__ . '/includes/ai_config.php'" => "require_once {$rootInc}ai_config.php'",
        "require_once __DIR__ . '/includes/profile_avatar.php'" => "require_once {$rootInc}profile_avatar.php'",
        "require_once __DIR__ . '/includes/url_helpers.php'" => "require_once {$rootInc}url_helpers.php'",
        "require_once __DIR__ . '/includes/format_display_name.php'" => "require_once {$rootInc}format_display_name.php'",
        "require __DIR__ . '/includes/components/app_shell_sidebar.php'" => "require dirname(__DIR__, 2) . '/includes/components/app_shell_sidebar.php'",
        "require_once __DIR__ . '/auth.php'" => "require_once {$rootAuth}",
    ];

    foreach ($replacements as $from => $to) {
        $content = str_replace($from, $to, $content);
    }

    if ($basename === 'college_upload_task.php') {
        $content = str_replace(
            '$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . \'uploads\'',
            '$uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . \'uploads\'',
            $content
        );
        $content = str_replace(
            "file_exists(__DIR__ . '/' . \$old['file_path'])",
            "file_exists(dirname(__DIR__, 2) . '/' . \$old['file_path'])",
            $content
        );
        $content = str_replace(
            "@unlink(__DIR__ . '/' . \$old['file_path'])",
            "@unlink(dirname(__DIR__, 2) . '/' . \$old['file_path'])",
            $content
        );
    }

    if ($basename === 'college_upload_file.php') {
        $content = str_replace(
            'college_upload_resolve_storage_path(__DIR__,',
            'college_upload_resolve_storage_path(dirname(__DIR__, 2),',
            $content
        );
    }

    if ($basename === 'professor_create_college_student.php') {
        $content = str_replace(
            "__DIR__ . '/uploads/profile_pictures'",
            "dirname(__DIR__, 2) . '/uploads/profile_pictures'",
            $content
        );
    }

    if ($basename === 'professor_upload_tasks.php') {
        $content = str_replace(
            'college_upload_delete_task_files($conn, $tid, __DIR__)',
            'college_upload_delete_task_files($conn, $tid, dirname(__DIR__, 2))',
            $content
        );
    }

    return $content;
}

function patch_examination_include_content(string $content, string $basename): string
{
    if ($basename === 'college_exam_helpers.php') {
        $content = str_replace(
            "require_once __DIR__ . '/simple_markdown.php'",
            "require_once dirname(__DIR__, 2) . '/includes/simple_markdown.php'",
            $content
        );
    }

    return $content;
}

$copied = [];
$patched = [];

foreach ($professorFiles as $file) {
    $src = $projectRoot . DIRECTORY_SEPARATOR . $file;
    $dest = $projectRoot . DIRECTORY_SEPARATOR . 'examination' . DIRECTORY_SEPARATOR . 'professor' . DIRECTORY_SEPARATOR . $file;
    if (!is_file($src)) {
        fwrite(STDERR, "Missing source: {$file}\n");
        exit(1);
    }
    $content = file_get_contents($src);
    $content = patch_examination_page_content($content, $file);
    file_put_contents($dest, $content);
    $copied[] = "examination/professor/{$file}";
    $patched[] = "examination/professor/{$file}";
}

foreach ($examineeFiles as $file) {
    $src = $projectRoot . DIRECTORY_SEPARATOR . $file;
    $dest = $projectRoot . DIRECTORY_SEPARATOR . 'examination' . DIRECTORY_SEPARATOR . 'examinee' . DIRECTORY_SEPARATOR . $file;
    if (!is_file($src)) {
        fwrite(STDERR, "Missing source: {$file}\n");
        exit(1);
    }
    $content = file_get_contents($src);
    $content = patch_examination_page_content($content, $file);
    file_put_contents($dest, $content);
    $copied[] = "examination/examinee/{$file}";
    $patched[] = "examination/examinee/{$file}";
}

foreach ($includeFiles as $rel) {
    $basename = basename($rel);
    $src = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dest = $projectRoot . DIRECTORY_SEPARATOR . 'examination' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . $basename;
    if (!is_file($src)) {
        fwrite(STDERR, "Missing source: {$rel}\n");
        exit(1);
    }
    $content = file_get_contents($src);
    $content = patch_examination_include_content($content, $basename);
    file_put_contents($dest, $content);
    $copied[] = "examination/includes/{$basename}";
    if ($basename === 'college_exam_helpers.php') {
        $patched[] = "examination/includes/{$basename}";
    }
}

echo json_encode([
    'ok' => true,
    'copied_count' => count($copied),
    'copied' => $copied,
    'patched' => $patched,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
