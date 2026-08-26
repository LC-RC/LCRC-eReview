<?php
/**
 * Start exam attempts for LOADTEST users using the SAME business rules as
 * college_take_exam.php start_exam, against the guarded load-test DB only.
 *
 * Always CLI start (no Apache / no HTTP). This avoids creating attempts in
 * production when db.local.php still points at `ereview`.
 *
 * Reproduced against guarded DB (same helpers as take-exam):
 *   - ereview_user_has_college_examination_access (portal access)
 *   - published exam filter (college_exam_where_published_sql)
 *   - college_exam_user_can_start (assignment + eligibility)
 *   - available_from / deadline window
 *   - one attempt row per user/exam (insert or resume in_progress / restart expired)
 *   - college_exam_compute_expires_at → expires_at
 *   - status = in_progress
 *
 * Simulated / not identical to browser start (documented intentionally):
 *   - No HTTP POST start_exam form / redirect
 *   - No CSRF check on the start POST (CLI has no browser form)
 *   - No exam_session_lock cookie (browser-only lock)
 *   - No take-exam HTML rendering
 *
 * HTTP answer/submit traffic remains separate and BLOCKED until http preflight allows it.
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

[$conn, $dbName] = loadtest_connect();
$GLOBALS['conn'] = $conn;

require_once loadtest_project_root() . '/includes/platform_access.php';
require_once loadtest_project_root() . '/examination/includes/college_schema.php';
require_once loadtest_project_root() . '/examination/includes/college_exam_helpers.php';

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);
$sessionsPath = loadtest_artifact_path($runId, 'sessions.json');
$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$sessionsDoc = loadtest_read_json($sessionsPath);
$examId = (int)($seed['exam_id'] ?? 0);
$base = rtrim((string)($sessionsDoc['base_url'] ?? ''), '/');
if ($base === '') {
    $base = loadtest_require_base_url();
} else {
    $envBase = loadtest_require_base_url();
    if ($base !== $envBase) {
        loadtest_fail("sessions.json base_url '{$base}' !== LOADTEST_BASE_URL '{$envBase}'");
    }
}

if ($examId <= 0) {
    loadtest_fail('Invalid exam_id in seed.json');
}
if (in_array(strtoupper((string)loadtest_env('LOADTEST_HTTP_START', '')), ['1', 'TRUE', 'YES'], true)) {
    loadtest_fail(
        'LOADTEST_HTTP_START is not supported. ' .
        'Attempts are started via CLI against the guarded load-test DB using college_exam_* rules. ' .
        'For k6 HTTP traffic, run HttpPreflight until artifacts/http_attestation.json status=SAFE.'
    );
}

$pubWhere = college_exam_where_published_sql();
$exSt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND {$pubWhere} LIMIT 1");
mysqli_stmt_bind_param($exSt, 'i', $examId);
mysqli_stmt_execute($exSt);
$exam = mysqli_fetch_assoc(mysqli_stmt_get_result($exSt));
mysqli_stmt_close($exSt);
if (!$exam || !loadtest_is_loadtest_exam_title((string)($exam['title'] ?? ''))) {
    loadtest_fail('Load-test exam missing/unpublished in guarded DB, or title is not [LOADTEST]');
}

/**
 * Start one attempt using take-exam business rules against $conn (load-test DB).
 *
 * @return array{attempt_id:int,expires_at:?string,started_at:string,status:string}
 */
function loadtest_cli_start_attempt(mysqli $conn, array $exam, int $examId, int $uid): array
{
    if (!ereview_user_has_college_examination_access($conn, $uid)) {
        loadtest_fail("Portal access denied (ereview_user_has_college_examination_access) for user_id={$uid}");
    }

    // Prefer MySQL NOW() for availability window (matches DB clock used elsewhere).
    $nowQ = mysqli_query($conn, 'SELECT NOW() AS n');
    $nowRow = $nowQ ? mysqli_fetch_assoc($nowQ) : null;
    if ($nowQ) {
        mysqli_free_result($nowQ);
    }
    $now = (string)($nowRow['n'] ?? date('Y-m-d H:i:s'));

    if (!college_exam_user_can_start($conn, $uid, $exam, $now)) {
        loadtest_fail("college_exam_user_can_start failed for user_id={$uid} exam_id={$examId}");
    }
    if (!empty($exam['available_from']) && $exam['available_from'] > $now) {
        loadtest_fail("Exam not available yet for user_id={$uid}");
    }
    if (!empty($exam['deadline']) && $exam['deadline'] < $now) {
        loadtest_fail("Exam deadline passed for user_id={$uid}");
    }

    $stmt = mysqli_prepare($conn, 'SELECT * FROM college_exam_attempts WHERE user_id=? AND exam_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $uid, $examId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    // Enforce one logical attempt per user/exam (reject unexpected multiples).
    $cntSt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM college_exam_attempts WHERE user_id=? AND exam_id=?');
    mysqli_stmt_bind_param($cntSt, 'ii', $uid, $examId);
    mysqli_stmt_execute($cntSt);
    $cntRow = mysqli_fetch_assoc(mysqli_stmt_get_result($cntSt));
    mysqli_stmt_close($cntSt);
    if ((int)($cntRow['c'] ?? 0) > 1) {
        loadtest_fail("Duplicate attempt rows for user_id={$uid} exam_id={$examId}");
    }

    $status = college_exam_attempt_status_normalized($attempt);
    if ($attempt && college_exam_attempt_is_effectively_submitted($attempt)) {
        loadtest_fail("Attempt already submitted for user_id={$uid}; teardown/reseed required");
    }

    $startedQ = mysqli_query($conn, 'SELECT NOW() AS n');
    $startedRow = $startedQ ? mysqli_fetch_assoc($startedQ) : null;
    if ($startedQ) {
        mysqli_free_result($startedQ);
    }
    $started = (string)($startedRow['n'] ?? date('Y-m-d H:i:s'));
    $expiresAt = college_exam_compute_expires_at((int)$exam['time_limit_seconds'], $exam['deadline'] ?? null);
    if ($expiresAt === null || $expiresAt === '') {
        loadtest_fail("expires_at could not be established for user_id={$uid}");
    }
    $startedAttemptId = 0;

    if (!$attempt) {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at)
             VALUES (?, ?, 'in_progress', ?, ?, ?)"
        );
        mysqli_stmt_bind_param($ins, 'iisss', $examId, $uid, $started, $expiresAt, $started);
        if (!mysqli_stmt_execute($ins)) {
            loadtest_fail('Attempt insert failed: ' . mysqli_error($conn));
        }
        $startedAttemptId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
    } elseif ($status === 'in_progress') {
        $startedAttemptId = (int)$attempt['attempt_id'];
        $expiresAt = $attempt['expires_at'] ?? $expiresAt;
        $started = (string)($attempt['started_at'] ?? $started);
    } elseif ($status === 'expired') {
        $aid = (int)$attempt['attempt_id'];
        $startedAttemptId = $aid;
        mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $aid);
        $emptyState = '{"current_index":0,"flags":[],"updated_at":0}';
        $upd = mysqli_prepare(
            $conn,
            "UPDATE college_exam_attempts
             SET status='in_progress', started_at=?, expires_at=?, submitted_at=NULL, score=NULL,
                 correct_count=NULL, total_count=NULL, ui_state_json=?, last_seen_at=?,
                 exam_session_lock=NULL, tab_switch_count=0, last_tab_switch_at=NULL
             WHERE attempt_id=? AND user_id=?"
        );
        mysqli_stmt_bind_param($upd, 'ssssii', $started, $expiresAt, $emptyState, $started, $aid, $uid);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    } else {
        loadtest_fail("Unhandled attempt status '{$status}' for user_id={$uid}");
    }

    if ($startedAttemptId <= 0) {
        loadtest_fail("No attempt_id after start for user_id={$uid}");
    }

    // Re-read and assert ownership / status / expires_at
    $verify = mysqli_prepare(
        $conn,
        "SELECT attempt_id, exam_id, user_id, status, expires_at FROM college_exam_attempts
         WHERE attempt_id=? AND user_id=? AND exam_id=? LIMIT 1"
    );
    mysqli_stmt_bind_param($verify, 'iii', $startedAttemptId, $uid, $examId);
    mysqli_stmt_execute($verify);
    $v = mysqli_fetch_assoc(mysqli_stmt_get_result($verify));
    mysqli_stmt_close($verify);
    if (!$v || strtolower((string)$v['status']) !== 'in_progress') {
        loadtest_fail("Post-start verify failed for attempt_id={$startedAttemptId}");
    }
    if (empty($v['expires_at'])) {
        loadtest_fail("Post-start expires_at missing for attempt_id={$startedAttemptId}");
    }
    $expiresAt = (string)$v['expires_at'];

    $events = loadtest_project_root() . '/examination/includes/college_exam_attempt_events.php';
    if (is_file($events)) {
        require_once $events;
        if (function_exists('college_exam_attempt_event_record')) {
            college_exam_attempt_event_record($conn, $startedAttemptId, $uid, $examId, 'exam_started', [
                'source' => 'loadtest_cli_start',
            ]);
        }
    }

    return [
        'attempt_id' => $startedAttemptId,
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
        loadtest_fail('Invalid session row for start_attempts');
    }

    // Confirm user exists in guarded DB (never trust session alone)
    $uSt = mysqli_prepare($conn, 'SELECT user_id, email, full_name, role, status FROM users WHERE user_id=? LIMIT 1');
    mysqli_stmt_bind_param($uSt, 'i', $uid);
    mysqli_stmt_execute($uSt);
    $uRow = mysqli_fetch_assoc(mysqli_stmt_get_result($uSt));
    mysqli_stmt_close($uSt);
    if (!$uRow || !loadtest_is_loadtest_email((string)$uRow['email'])) {
        loadtest_fail("Session user_id={$uid} not found as LOADTEST user in guarded DB");
    }

    $att = loadtest_cli_start_attempt($conn, $exam, $examId, $uid);

    $row = [
        'user_id' => $uid,
        'email' => $email,
        'full_name' => $name,
        'role' => 'college_student',
        'PHPSESSID' => $sid,
        'csrf_token' => $csrf,
        'cookie_header' => 'PHPSESSID=' . $sid,
        'attempt_id' => (int)$att['attempt_id'],
        'exam_id' => $examId,
        'expires_at' => $att['expires_at'],
        'started_at' => $att['started_at'],
        'status' => $att['status'],
        'start_mode' => 'cli_guarded_db',
    ];
    $updated[] = $row;
    $okCount++;
    loadtest_ok("Started attempt_id={$row['attempt_id']} user_id={$uid} (CLI/loadtest DB)");
}

$sessionsDoc['students'] = $updated;
$sessionsDoc['started_at'] = date('c');
$sessionsDoc['db'] = $dbName;
$sessionsDoc['start_mode'] = 'cli_guarded_db';
loadtest_write_json($sessionsPath, $sessionsDoc);

$attempts = [];
foreach ($updated as $row) {
    $attempts[] = [
        'user_id' => $row['user_id'],
        'attempt_id' => $row['attempt_id'],
        'exam_id' => $row['exam_id'],
        'PHPSESSID' => $row['PHPSESSID'],
        'csrf_token' => $row['csrf_token'],
        'cookie_header' => $row['cookie_header'],
        'expires_at' => $row['expires_at'],
    ];
}
loadtest_write_json(loadtest_artifact_path($runId, 'attempts.json'), [
    'run_id' => $runId,
    'exam_id' => $examId,
    'db' => $dbName,
    'base_url' => $base,
    'ajax_url' => $base . '/examination/examinee/college_exam_ajax',
    'start_mode' => 'cli_guarded_db',
    'start_simulation_notes' => [
        'reproduced' => [
            'portal_access',
            'published_exam',
            'college_exam_user_can_start',
            'availability_window',
            'one_attempt_per_user_exam',
            'status_in_progress',
            'expires_at_via_college_exam_compute_expires_at',
        ],
        'not_identical_to_browser' => [
            'no_http_start_exam_post',
            'no_start_csrf_form',
            'no_exam_session_lock_cookie',
            'no_take_exam_html',
        ],
    ],
    'attempts' => $attempts,
]);

loadtest_ok("Started {$okCount} attempts in guarded DB={$dbName} for run_id={$runId}");
loadtest_ok('HTTP/k6 remains BLOCKED until 09_http_preflight.php reports http_allowed=true.');
mysqli_close($conn);
