<?php
/**
 * Align diagnostic LOADTEST in-progress attempts to a shared expires_at (T_end).
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

[$conn, $dbName] = loadtest_connect();

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);
$attemptsDoc = loadtest_read_json(loadtest_artifact_path($runId, 'attempts.json'));
$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$batchId = (int)($attemptsDoc['batch_id'] ?? $seed['batch_id'] ?? 0);
if ($batchId <= 0) {
    loadtest_fail('Invalid batch_id for diagnostic align');
}
if ((string)($attemptsDoc['mode'] ?? $seed['mode'] ?? '') !== 'diagnostic') {
    loadtest_fail('attempts/seed mode is not diagnostic');
}

$tEndEnv = trim((string)loadtest_env('LOADTEST_T_END', ''));
if ($tEndEnv !== '') {
    $chk = mysqli_prepare($conn, 'SELECT ? AS t_end, NOW() AS server_now');
    mysqli_stmt_bind_param($chk, 's', $tEndEnv);
    mysqli_stmt_execute($chk);
    $nowRow = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    $expiresAt = (string)($nowRow['t_end'] ?? '');
    $serverNow = (string)($nowRow['server_now'] ?? '');
    if ($expiresAt === '' || strtotime($expiresAt) === false) {
        loadtest_fail('Invalid LOADTEST_T_END');
    }
} else {
    $secs = max(30, (int)loadtest_env('LOADTEST_ALIGN_SECONDS', '90'));
    $q = mysqli_query($conn, 'SELECT DATE_ADD(NOW(), INTERVAL ' . (int)$secs . ' SECOND) AS t_end, NOW() AS server_now');
    $nowRow = $q ? mysqli_fetch_assoc($q) : null;
    if ($q) {
        mysqli_free_result($q);
    }
    $expiresAt = (string)($nowRow['t_end'] ?? '');
    $serverNow = (string)($nowRow['server_now'] ?? '');
    if ($expiresAt === '') {
        loadtest_fail('Could not compute T_end from MySQL NOW()');
    }
}

$attemptIds = [];
foreach ($attemptsDoc['attempts'] as $a) {
    $aid = (int)($a['attempt_id'] ?? 0);
    $uid = (int)($a['user_id'] ?? 0);
    if ($aid <= 0 || $uid <= 0) {
        continue;
    }
    $chk = mysqli_prepare(
        $conn,
        "SELECT a.attempt_id, u.email, u.full_name
         FROM diagnostic_attempts a
         INNER JOIN users u ON u.user_id=a.user_id
         WHERE a.attempt_id=? AND a.user_id=? AND a.batch_id=? LIMIT 1"
    );
    mysqli_stmt_bind_param($chk, 'iii', $aid, $uid, $batchId);
    mysqli_stmt_execute($chk);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$row || !loadtest_is_loadtest_email((string)$row['email']) || !loadtest_is_loadtest_name((string)$row['full_name'])) {
        loadtest_fail("Refusing to align non-LOADTEST diagnostic attempt_id={$aid}");
    }
    $attemptIds[] = $aid;
}

if ($attemptIds === []) {
    loadtest_fail('No diagnostic attempts to align');
}

$in = implode(',', array_map('intval', $attemptIds));
$sql = "UPDATE diagnostic_attempts SET expires_at=? WHERE batch_id=" . (int)$batchId .
    " AND status='in_progress' AND attempt_id IN ({$in})";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $expiresAt);
if (!mysqli_stmt_execute($stmt)) {
    loadtest_fail('Diagnostic align update failed: ' . mysqli_error($conn));
}
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

foreach ($attemptsDoc['attempts'] as &$a) {
    $a['expires_at'] = $expiresAt;
}
unset($a);
$attemptsDoc['t_end'] = $expiresAt;
$attemptsDoc['aligned_at'] = date('c');
loadtest_write_json(loadtest_artifact_path($runId, 'attempts.json'), $attemptsDoc);

$tsSt = mysqli_prepare($conn, 'SELECT UNIX_TIMESTAMP(?) AS u');
mysqli_stmt_bind_param($tsSt, 's', $expiresAt);
mysqli_stmt_execute($tsSt);
$tsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($tsSt));
mysqli_stmt_close($tsSt);
$tEndUnix = (int)($tsRow['u'] ?? 0);

loadtest_write_json(loadtest_artifact_path($runId, 'align.json'), [
    'run_id' => $runId,
    'mode' => 'diagnostic',
    'batch_id' => $batchId,
    'db' => $dbName,
    't_end' => $expiresAt,
    't_end_unix' => $tEndUnix,
    'server_now' => $serverNow,
    'affected' => $affected,
    'aligned_at' => date('c'),
]);

loadtest_ok("Aligned {$affected} diagnostic attempts to t_end={$expiresAt}");
mysqli_close($conn);
