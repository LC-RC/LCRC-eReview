<?php
/**
 * CLI diagnostic for payment proof upload directory.
 * Usage: php scripts/payment_proof_upload_diag.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dirRel = 'uploads/payment_proofs';
$dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dirRel);

echo "Root: {$root}\n";
echo "Proof dir: {$dir}\n";
echo "Exists: " . (is_dir($dir) ? 'yes' : 'no') . "\n";
echo "Writable: " . (is_writable($dir) ? 'yes' : 'no') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "file_uploads: " . ini_get('file_uploads') . "\n";
echo "upload_tmp_dir: " . (ini_get('upload_tmp_dir') ?: '(system default)') . "\n";
echo "sys_temp_dir: " . sys_get_temp_dir() . "\n";
echo "open_basedir: " . (ini_get('open_basedir') ?: '(none)') . "\n";

if (!is_dir($dir)) {
    $mk = @mkdir($dir, 0775, true);
    echo "mkdir: " . ($mk ? 'ok' : 'FAILED') . "\n";
}

$probe = $dir . DIRECTORY_SEPARATOR . 'diag_' . bin2hex(random_bytes(4)) . '.txt';
$bytes = @file_put_contents($probe, 'ok');
echo "Probe write: " . ($bytes !== false ? 'ok' : 'FAILED') . "\n";
if ($bytes !== false) {
    @unlink($probe);
}

$last = error_get_last();
if ($last) {
    echo "Last PHP error: " . ($last['message'] ?? '') . "\n";
}
