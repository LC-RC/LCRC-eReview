<?php
/**
 * PHP syntax check for student-access second-pass files.
 * Prefer this over `php -r` on Windows PowerShell (quoting breaks easily).
 *
 * Run:
 *   C:\xampp\php\php.exe scripts/_verify_student_access_lint.php
 */
declare(strict_types=1);

$php = 'C:\\xampp\\php\\php.exe';
if (!is_file($php)) {
    $php = PHP_BINARY;
}
$root = dirname(__DIR__);

$files = [
    'auth.php',
    'includes/platform_access.php',
    'includes/student_content_access.php',
    'includes/admin_account_window.php',
    'includes/commerce_access_gate.php',
    'admin_students.php',
    'admin_student_delete.php',
    'admin_student_access_api.php',
    'extend_access.php',
    'activate_user.php',
    'quiz_ajax.php',
    'preboards_ajax.php',
    'handout_annotations_api.php',
    'vimeo_thumbs_batch.php',
    'examination/professor/professor_college_students_api.php',
    'scripts/bulk_enable_college_examination_validate.php',
    'scripts/_verify_student_access_matrix.php',
    'scripts/_verify_window_grant_sync_dryrun.php',
    'scripts/_verify_users_status_enum.php',
    'scripts/_verify_student_access_lint.php',
];

echo "PHP syntax check:\n";
$fail = 0;
foreach ($files as $rel) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        echo "FAIL  [MISSING] {$rel}\n";
        $fail++;
        continue;
    }
    $out = [];
    $code = 0;
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    $line = trim(implode(' ', $out));
    if ($code !== 0 || stripos($line, 'No syntax errors') === false) {
        echo "FAIL  {$rel} :: {$line}\n";
        $fail++;
    } else {
        echo "PASS  {$rel}\n";
    }
}

if ($fail === 0) {
    echo "\nALL PASS\n";
    exit(0);
}
echo "\nFAILED: {$fail} file(s)\n";
exit(1);
