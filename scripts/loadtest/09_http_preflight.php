<?php
/**
 * HTTP safety preflight — produces http_attestation.json.
 *
 * Only status=SAFE permits k6. SAFE requires a live web runtime probe that
 * returns SELECT DATABASE() via scripts/loadtest/runtime_db_probe.php (Apache),
 * matching the allowlisted LOADTEST_DB_NAME and harness session.save_path.
 *
 * Operator flags / db.local.php alone NEVER yield SAFE.
 *
 * Never modifies Phase A examination PHP.
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

[$conn, $dbName] = loadtest_connect();

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
if ($runId === '') {
    $runId = 'preflight';
}
$runId = loadtest_run_id($runId);

$attestation = loadtest_build_http_attestation($conn, $dbName, $runId);

$runPath = loadtest_attestation_path($runId);
$latestPath = loadtest_attestation_path(null);
loadtest_write_json($runPath, $attestation);
loadtest_write_json($latestPath, $attestation);
// Keep legacy filename for older runners (same content).
loadtest_write_json(loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'http_safety_latest.json', $attestation);

if (($attestation['status'] ?? '') === 'SAFE') {
    loadtest_ok('CLI DB: READY (' . $dbName . ')');
    loadtest_ok('HTTP attestation: SAFE (web runtime DATABASE()=' . $attestation['database'] . ')');
} else {
    loadtest_ok('CLI DB: ' . (!empty($attestation['cli_ready']) ? 'READY' : 'NOT READY') . ' (' . $dbName . ')');
    loadtest_ok('HTTP attestation: BLOCKED');
    foreach ($attestation['reasons'] as $r) {
        loadtest_ok('reason: ' . $r);
    }
    loadtest_ok('HTTP safety remains BLOCKED because runtime Apache DB identity cannot be proven SAFE.');
}

loadtest_ok('Wrote ' . $latestPath);
mysqli_close($conn);
exit(($attestation['status'] ?? '') === 'SAFE' ? 0 : 2);
