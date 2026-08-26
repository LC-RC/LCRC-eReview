<?php
/**
 * Capture MySQL status metrics for a load-test run.
 *
 * Usage:
 *   php 07_collect_mysql_status.php --phase=before
 *   php 07_collect_mysql_status.php --phase=during
 *   php 07_collect_mysql_status.php --phase=after
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

[$conn, $dbName] = loadtest_connect();

$phase = 'snapshot';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--phase=')) {
        $phase = preg_replace('/[^a-z0-9_\-]/i', '', substr($arg, 8)) ?: 'snapshot';
    }
}
$phaseEnv = loadtest_env('LOADTEST_METRIC_PHASE', '');
if ($phaseEnv) {
    $phase = preg_replace('/[^a-z0-9_\-]/i', '', $phaseEnv) ?: $phase;
}

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : loadtest_run_id();
}
$runId = loadtest_run_id($runId ?: null);
$n = (int)loadtest_env('LOADTEST_N', '0');

$wanted = [
    'Threads_running',
    'Threads_connected',
    'Questions',
    'Innodb_row_lock_waits',
    'Innodb_row_lock_time',
    'Slow_queries',
    'Connections',
    'Max_used_connections',
];

$metrics = [];
$unavailable = [];
foreach ($wanted as $name) {
    $esc = mysqli_real_escape_string($conn, $name);
    $r = @mysqli_query($conn, "SHOW GLOBAL STATUS LIKE '{$esc}'");
    if (!$r || mysqli_num_rows($r) === 0) {
        $unavailable[] = $name;
        if ($r) {
            mysqli_free_result($r);
        }
        continue;
    }
    $row = mysqli_fetch_assoc($r);
    mysqli_free_result($r);
    $metrics[$name] = $row['Value'] ?? $row['value'] ?? null;
}

$vars = [];
foreach (['max_connections', 'innodb_buffer_pool_size', 'version'] as $v) {
    $esc = mysqli_real_escape_string($conn, $v);
    $r = @mysqli_query($conn, "SHOW VARIABLES LIKE '{$esc}'");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $vars[$v] = $row['Value'] ?? $row['value'] ?? null;
    }
    if ($r) {
        mysqli_free_result($r);
    }
}

$snap = [
    'run_id' => $runId,
    'db' => $dbName,
    'n' => $n,
    'phase' => $phase,
    'timestamp' => date('c'),
    'unix_ts' => time(),
    'status' => $metrics,
    'variables' => $vars,
    'unavailable_status' => $unavailable,
];

$path = loadtest_artifact_path($runId, 'mysql_status_' . $phase . '.json');
loadtest_write_json($path, $snap);

// Append to timeline
$timelinePath = loadtest_artifact_path($runId, 'mysql_status_timeline.json');
$timeline = is_file($timelinePath) ? loadtest_read_json($timelinePath) : ['run_id' => $runId, 'samples' => []];
if (!isset($timeline['samples']) || !is_array($timeline['samples'])) {
    $timeline['samples'] = [];
}
$timeline['samples'][] = $snap;
loadtest_write_json($timelinePath, $timeline);

loadtest_ok("MySQL status captured phase={$phase} -> " . basename($path));
if ($unavailable !== []) {
    loadtest_ok('Unavailable metrics: ' . implode(', ', $unavailable));
}
mysqli_close($conn);
