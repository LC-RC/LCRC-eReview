<?php
/**
 * Parse k6 stdout log for LOADTEST_SUBMIT_OK / LOADTEST_FLUSH_FAIL markers.
 *
 * Usage:
 *   php parse_k6_stdout.php path\to\k6_stdout.txt
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

loadtest_require_safe_environment();

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    loadtest_fail('Usage: php parse_k6_stdout.php <k6_stdout.txt>');
}

$ok = [];
$flushFail = [];
$lines = file($path, FILE_IGNORE_NEW_LINES);
foreach ($lines ?: [] as $line) {
    if (preg_match('/LOADTEST_SUBMIT_OK\s+attempt_id=(\d+)/', $line, $m)) {
        $ok[(int)$m[1]] = true;
    }
    if (preg_match('/LOADTEST_FLUSH_FAIL\s+attempt_id=(\d+)/', $line, $m)) {
        $flushFail[(int)$m[1]] = true;
    }
}

$summaryPath = loadtest_artifact_path($runId, 'k6_summary.json');
$summary = is_file($summaryPath) ? loadtest_read_json($summaryPath) : ['run_id' => $runId];
$summary['submit_ok_attempt_ids'] = array_map('intval', array_keys($ok));
$summary['flush_failed_attempt_ids'] = array_map('intval', array_keys($flushFail));
loadtest_write_json($summaryPath, $summary);

$clientOk = [];
foreach ($ok as $aid => $_) {
    $clientOk[(string)$aid] = true;
}
loadtest_write_json(loadtest_artifact_path($runId, 'client_submit_ok.json'), $clientOk);

loadtest_ok('Parsed submit_ok=' . count($ok) . ' flush_fail=' . count($flushFail));
