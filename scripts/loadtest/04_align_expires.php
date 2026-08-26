<?php
/**
 * Align all LOADTEST in-progress attempts for this run to the same expires_at (T_end).
 *
 * Env:
 *   LOADTEST_ALIGN_SECONDS=90   (expires_at = now + N seconds)
 *   LOADTEST_T_END=2026-08-20 18:00:00  (optional absolute datetime)
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
$examId = (int)($attemptsDoc['exam_id'] ?? $seed['exam_id'] ?? 0);

$tEndEnv = trim((string)loadtest_env('LOADTEST_T_END', ''));
if ($tEndEnv !== '') {
    // Interpret as MySQL datetime in the session time_zone set by loadtest_connect (+08:00).
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
    // Use MySQL NOW() so T_end matches server/DB clock used by finalize grace queries.
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
    // Verify attempt belongs to loadtest user + exam
    $chk = mysqli_prepare(
        $conn,
        "SELECT a.attempt_id, u.email, u.full_name
         FROM college_exam_attempts a
         INNER JOIN users u ON u.user_id=a.user_id
         WHERE a.attempt_id=? AND a.user_id=? AND a.exam_id=? LIMIT 1"
    );
    mysqli_stmt_bind_param($chk, 'iii', $aid, $uid, $examId);
    mysqli_stmt_execute($chk);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$row || !loadtest_is_loadtest_email((string)$row['email']) || !loadtest_is_loadtest_name((string)$row['full_name'])) {
        loadtest_fail("Refusing to align non-LOADTEST attempt_id={$aid}");
    }
    $attemptIds[] = $aid;
}

if ($attemptIds === []) {
    loadtest_fail('No attempts to align');
}

$in = implode(',', array_map('intval', $attemptIds));
$sql = "UPDATE college_exam_attempts SET expires_at=? WHERE exam_id=" . (int)$examId .
    " AND status='in_progress' AND attempt_id IN ({$in})";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $expiresAt);
if (!mysqli_stmt_execute($stmt)) {
    loadtest_fail('Align update failed: ' . mysqli_error($conn));
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

$sessions = loadtest_read_json(loadtest_artifact_path($runId, 'sessions.json'));
foreach ($sessions['students'] as &$s) {
    $s['expires_at'] = $expiresAt;
}
unset($s);
loadtest_write_json(loadtest_artifact_path($runId, 'sessions.json'), $sessions);

$metaQ = mysqli_prepare(
    $conn,
    'SELECT UNIX_TIMESTAMP(?) AS t_end_unix, DATE_ADD(?, INTERVAL 60 SECOND) AS grace_deadline, NOW() AS server_now'
);
mysqli_stmt_bind_param($metaQ, 'ss', $expiresAt, $expiresAt);
mysqli_stmt_execute($metaQ);
$meta = mysqli_fetch_assoc(mysqli_stmt_get_result($metaQ));
mysqli_stmt_close($metaQ);
$tEndUnix = (int)($meta['t_end_unix'] ?? 0);
$graceDeadline = (string)($meta['grace_deadline'] ?? '');
if ($tEndUnix <= 0 || $graceDeadline === '') {
    loadtest_fail('Could not compute t_end_unix / grace_deadline from MySQL');
}

loadtest_write_json(loadtest_artifact_path($runId, 'align.json'), [
    'run_id' => $runId,
    'db' => $dbName,
    'exam_id' => $examId,
    't_end' => $expiresAt,
    't_end_unix' => $tEndUnix,
    'server_now_at_align' => $serverNow ?? ($meta['server_now'] ?? null),
    'grace_seconds' => 60,
    'grace_deadline' => $graceDeadline,
    'attempt_ids' => $attemptIds,
    'affected_rows' => $affected,
    'clock_source' => 'mysql_now',
]);

loadtest_ok("Aligned " . count($attemptIds) . " attempts to expires_at={$expiresAt} (affected_rows={$affected})");
mysqli_close($conn);
