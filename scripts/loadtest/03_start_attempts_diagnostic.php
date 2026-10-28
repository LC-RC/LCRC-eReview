<?php
/**
 * Start diagnostic attempts for LOADTEST users (CLI / guarded DB only).
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

[$conn, $dbName] = loadtest_connect();
$GLOBALS['conn'] = $conn;

require_once loadtest_project_root() . '/includes/platform_access.php';
require_once loadtest_project_root() . '/examination/includes/diagnostic_schema.php';
require_once loadtest_project_root() . '/examination/includes/diagnostic_exam_helpers.php';

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);
$sessionsPath = loadtest_artifact_path($runId, 'sessions.json');
$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$sessionsDoc = loadtest_read_json($sessionsPath);
$batchId = (int)($seed['batch_id'] ?? 0);
$base = rtrim((string)($sessionsDoc['base_url'] ?? ''), '/');
if ($base === '') {
    $base = loadtest_require_base_url();
} else {
    $envBase = loadtest_require_base_url();
    if ($base !== $envBase) {
        loadtest_fail("sessions.json base_url '{$base}' !== LOADTEST_BASE_URL '{$envBase}'");
    }
}

if ($batchId <= 0) {
    loadtest_fail('Invalid batch_id in seed.json (diagnostic)');
}
if ((string)($seed['mode'] ?? '') !== 'diagnostic') {
    loadtest_fail('seed.json mode is not diagnostic — refuse to start diagnostic attempts against regular seed');
}
if (in_array(strtoupper((string)loadtest_env('LOADTEST_HTTP_START', '')), ['1', 'TRUE', 'YES'], true)) {
    loadtest_fail('LOADTEST_HTTP_START is not supported. Attempts start via CLI on the guarded DB only.');
}

$batch = diagnostic_exam_load_batch($conn, $batchId);
if (!$batch || !loadtest_is_loadtest_batch_title((string)($batch['title'] ?? '')) || empty($batch['is_published'])) {
    loadtest_fail('Load-test diagnostic batch missing/unpublished, or title is not [LOADTEST]');
}

/**
 * @return array{attempt_id:int,expires_at:?string,started_at:string,status:string}
 */
function loadtest_cli_start_diagnostic_attempt(mysqli $conn, array $batch, int $batchId, int $uid): array
{
    if (!ereview_user_has_college_examination_access($conn, $uid)) {
        loadtest_fail("Portal access denied for user_id={$uid}");
    }
    $nowQ = mysqli_query($conn, 'SELECT NOW() AS n');
    $nowRow = $nowQ ? mysqli_fetch_assoc($nowQ) : null;
    if ($nowQ) {
        mysqli_free_result($nowQ);
    }
    $now = (string)($nowRow['n'] ?? date('Y-m-d H:i:s'));
    if (!diagnostic_exam_user_can_start_batch($conn, $uid, $batch, $now)) {
        loadtest_fail("diagnostic_exam_user_can_start_batch failed for user_id={$uid} batch_id={$batchId}");
    }

    $stmt = mysqli_prepare($conn, 'SELECT * FROM diagnostic_attempts WHERE user_id=? AND batch_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $uid, $batchId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $started = $now;
    $expiresAt = diagnostic_exam_compute_expires_at((int)($batch['time_limit_seconds'] ?? 180), $batch['deadline'] ?? null);

    if ($attempt) {
        $status = diagnostic_exam_attempt_status_normalized($attempt);
        if ($status === 'in_progress') {
            return [
                'attempt_id' => (int)$attempt['attempt_id'],
                'expires_at' => $attempt['expires_at'] ?? $expiresAt,
                'started_at' => (string)($attempt['started_at'] ?? $started),
                'status' => 'in_progress',
            ];
        }
        // Restart submitted/expired for disposable loadtest users only
        $aid = (int)$attempt['attempt_id'];
        mysqli_query($conn, 'DELETE FROM diagnostic_answers WHERE attempt_id=' . $aid);
        $upd = mysqli_prepare(
            $conn,
            "UPDATE diagnostic_attempts SET status='in_progress', score=NULL, correct_count=NULL, total_count=NULL,
             subject_breakdown_json=NULL, started_at=?, expires_at=?, submitted_at=NULL, ui_state_json=NULL, last_seen_at=?
             WHERE attempt_id=? AND user_id=? AND batch_id=?"
        );
        mysqli_stmt_bind_param($upd, 'sssiii', $started, $expiresAt, $started, $aid, $uid, $batchId);
        if (!mysqli_stmt_execute($upd)) {
            loadtest_fail('Could not restart diagnostic attempt: ' . mysqli_error($conn));
        }
        mysqli_stmt_close($upd);

        return [
            'attempt_id' => $aid,
            'expires_at' => $expiresAt,
            'started_at' => $started,
            'status' => 'in_progress',
        ];
    }

    $ins = mysqli_prepare(
        $conn,
        "INSERT INTO diagnostic_attempts (batch_id, user_id, status, started_at, expires_at, last_seen_at)
         VALUES (?, ?, 'in_progress', ?, ?, ?)"
    );
    mysqli_stmt_bind_param($ins, 'iisss', $batchId, $uid, $started, $expiresAt, $started);
    if (!mysqli_stmt_execute($ins)) {
        loadtest_fail('Diagnostic attempt insert failed: ' . mysqli_error($conn));
    }
    $aid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    return [
        'attempt_id' => $aid,
        'expires_at' => $expiresAt,
        'started_at' => $started,
        'status' => 'in_progress',
    ];
}

$updated = [];
$okCount = 0;
foreach ($sessionsDoc['students'] as $s) {
    $uid = (int)($s['user_id'] ?? 0);
    $email = (string)($s['email'] ?? '');
    $sid = (string)($s['PHPSESSID'] ?? '');
    $csrf = (string)($s['csrf_token'] ?? '');
    $name = (string)($s['full_name'] ?? '');
    if ($uid <= 0 || $sid === '' || $csrf === '' || !loadtest_is_loadtest_email($email) || !loadtest_is_loadtest_name($name)) {
        loadtest_fail('Invalid session row for diagnostic start_attempts');
    }

    $att = loadtest_cli_start_diagnostic_attempt($conn, $batch, $batchId, $uid);
    $row = [
        'user_id' => $uid,
        'email' => $email,
        'full_name' => $name,
        'role' => 'college_student',
        'PHPSESSID' => $sid,
        'csrf_token' => $csrf,
        'cookie_header' => 'PHPSESSID=' . $sid,
        'attempt_id' => (int)$att['attempt_id'],
        'batch_id' => $batchId,
        'exam_id' => null,
        'expires_at' => $att['expires_at'],
        'started_at' => $att['started_at'],
        'status' => $att['status'],
        'start_mode' => 'cli_guarded_db_diagnostic',
    ];
    $updated[] = $row;
    $okCount++;
    loadtest_ok("Started diagnostic attempt_id={$row['attempt_id']} user_id={$uid}");
}

$sessionsDoc['students'] = $updated;
$sessionsDoc['started_at'] = date('c');
$sessionsDoc['db'] = $dbName;
$sessionsDoc['mode'] = 'diagnostic';
$sessionsDoc['start_mode'] = 'cli_guarded_db_diagnostic';
loadtest_write_json($sessionsPath, $sessionsDoc);

$attempts = [];
foreach ($updated as $row) {
    $attempts[] = [
        'user_id' => $row['user_id'],
        'attempt_id' => $row['attempt_id'],
        'batch_id' => $batchId,
        'exam_id' => null,
        'PHPSESSID' => $row['PHPSESSID'],
        'csrf_token' => $row['csrf_token'],
        'cookie_header' => $row['cookie_header'],
        'expires_at' => $row['expires_at'],
    ];
}
loadtest_write_json(loadtest_artifact_path($runId, 'attempts.json'), [
    'run_id' => $runId,
    'mode' => 'diagnostic',
    'batch_id' => $batchId,
    'exam_id' => null,
    'db' => $dbName,
    'base_url' => $base,
    'ajax_url' => $base . '/examination/examinee/college_diagnostic_ajax',
    'start_mode' => 'cli_guarded_db_diagnostic',
    'attempts' => $attempts,
]);

loadtest_ok("Started {$okCount} diagnostic attempts in guarded DB={$dbName}");
mysqli_close($conn);
