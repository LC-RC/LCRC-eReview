<?php
/**
 * Step 5 batch stub processor — remaining root examination files only.
 * Fail-fast; does not modify examination/* destinations.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$backupDir = $root . '/backups/examination_cutover';

$remaining = [
    ['professor_college_student_view.php', 'examination/professor/professor_college_student_view.php'],
    ['professor_college_student_delete.php', 'examination/professor/professor_college_student_delete.php'],
    ['professor_exams.php', 'examination/professor/professor_exams.php'],
    ['professor_exam_edit.php', 'examination/professor/professor_exam_edit.php'],
    ['professor_exam_monitor.php', 'examination/professor/professor_exam_monitor.php'],
    ['professor_exam_monitor_live.php', 'examination/professor/professor_exam_monitor_live.php'],
    ['professor_exam_monitor_pdf.php', 'examination/professor/professor_exam_monitor_pdf.php'],
    ['professor_exam_monitor_xlsx.php', 'examination/professor/professor_exam_monitor_xlsx.php'],
    ['professor_exam_review_sheet.php', 'examination/professor/professor_exam_review_sheet.php'],
    ['professor_exam_ai.php', 'examination/professor/professor_exam_ai.php'],
    ['professor_upload_tasks.php', 'examination/professor/professor_upload_tasks.php'],
    ['professor_upload_task_monitor.php', 'examination/professor/professor_upload_task_monitor.php'],
    ['professor_monitor.php', 'examination/professor/professor_monitor.php'],
    ['college_exams.php', 'examination/examinee/college_exams.php'],
    ['college_take_exam.php', 'examination/examinee/college_take_exam.php'],
    ['college_exam_ajax.php', 'examination/examinee/college_exam_ajax.php'],
    ['college_uploads.php', 'examination/examinee/college_uploads.php'],
    ['college_upload_task.php', 'examination/examinee/college_upload_task.php'],
    ['college_upload_file.php', 'examination/examinee/college_upload_file.php'],
    ['college_exams_debug.php', 'examination/examinee/college_exams_debug.php'],
];

function sha(string $path): string
{
    return hash_file('sha256', $path);
}

function phpLint(string $path): bool
{
    $out = [];
    $code = 0;
    exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    return $code === 0;
}

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

$destHashesBefore = [];
foreach ($remaining as [$relRoot, $relDest]) {
    $dest = $root . '/' . str_replace('\\', '/', $relDest);
    if (is_file($dest)) {
        $destHashesBefore[$relDest] = sha($dest);
    }
}

$completed = [];
foreach ($remaining as [$relRoot, $relDest]) {
    $rootFile = $root . '/' . $relRoot;
    $destFile = $root . '/' . str_replace('\\', '/', $relDest);
    $backupName = str_replace(['/', '\\'], '_', $relRoot) . '.pre_stub';
    $backupFile = $backupDir . '/' . $backupName;

    if (!is_file($rootFile)) {
        fwrite(STDERR, "FAIL: root missing: $relRoot\n");
        exit(1);
    }
    if (!is_file($destFile)) {
        fwrite(STDERR, "FAIL: destination missing: $relDest\n");
        exit(1);
    }

    // Skip if already stubbed
    $current = file_get_contents($rootFile);
    if (preg_match("#require __DIR__ \\. '/examination/#", $current)) {
        echo "SKIP already stubbed: $relRoot\n";
        $completed[] = $relRoot;
        continue;
    }

    $origHash = sha($rootFile);
    if (!copy($rootFile, $backupFile)) {
        fwrite(STDERR, "FAIL: backup copy failed: $relRoot\n");
        exit(1);
    }
    if (sha($backupFile) !== $origHash) {
        fwrite(STDERR, "FAIL: backup hash mismatch: $relRoot\n");
        exit(1);
    }
    if (!phpLint($rootFile) || !phpLint($destFile)) {
        fwrite(STDERR, "FAIL: syntax pre-check: $relRoot\n");
        exit(1);
    }

    $stub = "<?php\nrequire __DIR__ . '/" . str_replace('\\', '/', $relDest) . "';\n";
    if (file_put_contents($rootFile, $stub) === false) {
        fwrite(STDERR, "FAIL: write stub failed: $relRoot\n");
        exit(1);
    }
    if (!phpLint($rootFile)) {
        fwrite(STDERR, "FAIL: stub syntax: $relRoot\n");
        exit(1);
    }
    if (sha($backupFile) !== $origHash) {
        fwrite(STDERR, "FAIL: backup changed after stub: $relRoot\n");
        exit(1);
    }
    if (!isset($destHashesBefore[$relDest]) || sha($destFile) !== $destHashesBefore[$relDest]) {
        fwrite(STDERR, "FAIL: destination modified: $relDest\n");
        exit(1);
    }

    echo "PASS stub: $relRoot sha256=$origHash\n";
    $completed[] = $relRoot;
}

echo "BATCH_COMPLETE count=" . count($completed) . "\n";
