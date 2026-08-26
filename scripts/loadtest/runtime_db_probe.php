<?php
/**
 * Harness-only web runtime DB probe.
 *
 * Served by Apache under /scripts/loadtest/runtime_db_probe.php.
 * Uses the SAME db.php bootstrap as the live web app so SELECT DATABASE()
 * reflects the Apache/runtime configuration (not the CLI LOADTEST_DB_NAME).
 *
 * Locked with X-Ereview-Loadtest-Probe matching scripts/loadtest/.probe_secret
 * (armed briefly by 09_http_preflight.php). No writes. No Phase A changes.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex');

$secretPath = __DIR__ . DIRECTORY_SEPARATOR . '.probe_secret';
$provided = (string)($_SERVER['HTTP_X_EREVIEW_LOADTEST_PROBE'] ?? '');
if (!is_file($secretPath)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'probe_not_armed']);
    exit;
}
$expected = trim((string)file_get_contents($secretPath));
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Connect exactly as the web application does (Apache runtime credentials/db name).
require dirname(__DIR__, 2) . '/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no_connection']);
    exit;
}

$database = null;
$q = @mysqli_query($conn, 'SELECT DATABASE() AS d');
if ($q) {
    $row = mysqli_fetch_assoc($q);
    mysqli_free_result($q);
    $database = strtolower(trim((string)($row['d'] ?? '')));
}

$sessionSavePath = (string)ini_get('session.save_path');
if ($sessionSavePath === '' && function_exists('session_save_path')) {
    $sessionSavePath = (string)session_save_path();
}

echo json_encode([
    'ok' => true,
    'database' => $database,
    'session_save_path' => $sessionSavePath,
    'probe' => 'ereview_loadtest_runtime_db_probe',
    'verification' => 'SELECT DATABASE() via Apache-included db.php',
], JSON_UNESCAPED_SLASHES);
exit;
