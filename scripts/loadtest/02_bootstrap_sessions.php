<?php
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';
require_once __DIR__ . '/expected_answers.php';

/**
 * Bootstrap PHP sessions for loadtest users (no password login).
 *
 * Writes artifacts/{run_id}/sessions.json
 *
 * Session keys (must match real auth — do not invent names):
 *   user_id, full_name, email, role, created, last_activity, csrf_token
 *   — same as scripts/qa_examination_authenticated_http.php / auth.php login state
 *   active_portal = 'college_examination'
 *   — same key set by ereview_require_college_examination_portal()
 *
 * Session isolation:
 *   Uses a dedicated harness session.save_path under scripts/loadtest/sessions
 *   (never shared XAMPP tmp). session_regenerate_id(true) ensures a fresh
 *   PHPSESSID per VU — never reuses an existing id.
 */
[$conn, $dbName] = loadtest_connect();

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);
$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$students = $seed['students'] ?? [];
if (!is_array($students) || $students === []) {
    loadtest_fail('seed.json has no students');
}

$sessionMeta = loadtest_resolve_session_save_path();
$sessionSavePath = $sessionMeta['path'];
if (!@session_save_path($sessionSavePath)) {
    loadtest_fail('Could not set session.save_path to ' . $sessionSavePath);
}

require loadtest_project_root() . '/session_config.php';

$effectiveSavePath = (string)session_save_path();
if (loadtest_normalize_path($effectiveSavePath) !== loadtest_normalize_path($sessionSavePath)) {
    loadtest_fail(
        'session.save_path mismatch after bootstrap (effective=' . $effectiveSavePath .
        ', expected=' . $sessionSavePath . ')'
    );
}

$sessions = [];
$seenSids = [];
foreach ($students as $s) {
    $uid = (int)($s['user_id'] ?? 0);
    $email = (string)($s['email'] ?? '');
    $name = (string)($s['full_name'] ?? '');
    if ($uid <= 0 || !loadtest_is_loadtest_email($email) || !loadtest_is_loadtest_name($name)) {
        loadtest_fail('Refusing to bootstrap non-LOADTEST student row');
    }

    $uSt = mysqli_prepare($conn, 'SELECT user_id, email, full_name, role, status FROM users WHERE user_id=? LIMIT 1');
    mysqli_stmt_bind_param($uSt, 'i', $uid);
    mysqli_stmt_execute($uSt);
    $uRow = mysqli_fetch_assoc(mysqli_stmt_get_result($uSt));
    mysqli_stmt_close($uSt);
    if (!$uRow || !loadtest_is_loadtest_email((string)$uRow['email']) || (string)$uRow['role'] !== 'college_student') {
        loadtest_fail("Cannot bootstrap session: user_id={$uid} missing or not college_student in guarded DB");
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Force a brand-new session id; never reuse an existing PHPSESSID.
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = $uid;
    $_SESSION['full_name'] = $name;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = 'college_student';
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['active_portal'] = 'college_examination';
    $sid = session_id();
    if ($sid === '' || isset($seenSids[$sid])) {
        loadtest_fail('Failed to allocate unique PHPSESSID for user_id=' . $uid);
    }
    $seenSids[$sid] = true;
    $csrf = (string)$_SESSION['csrf_token'];
    session_write_close();

    $sessions[] = [
        'user_id' => $uid,
        'email' => $email,
        'full_name' => $name,
        'role' => 'college_student',
        'PHPSESSID' => $sid,
        'csrf_token' => $csrf,
        'attempt_id' => null,
        'exam_id' => (int)($seed['exam_id'] ?? 0),
    ];
}

$profId = (int)($seed['professor_user_id'] ?? 0);
if ($profId > 0) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    } else {
        session_regenerate_id(true);
    }
    $_SESSION = [];
    $_SESSION['user_id'] = $profId;
    $_SESSION['full_name'] = LOADTEST_PROF_NAME;
    $_SESSION['email'] = LOADTEST_PROF_EMAIL;
    $_SESSION['role'] = 'professor_admin';
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['active_portal'] = 'college_examination';
    $profSid = session_id();
    $profCsrf = (string)$_SESSION['csrf_token'];
    session_write_close();
} else {
    $profSid = '';
    $profCsrf = '';
}

$out = [
    'run_id' => $runId,
    'db' => $dbName,
    'exam_id' => (int)($seed['exam_id'] ?? 0),
    'base_url' => (string)($seed['base_url'] ?? ''),
    'session_save_path' => $effectiveSavePath,
    'session_save_path_source' => $sessionMeta['source'],
    'students' => $sessions,
    'professor' => [
        'user_id' => $profId,
        'email' => LOADTEST_PROF_EMAIL,
        'PHPSESSID' => $profSid,
        'csrf_token' => $profCsrf,
    ],
    'created_at' => date('c'),
    'session_keys' => [
        'user_id', 'full_name', 'email', 'role', 'created', 'last_activity', 'csrf_token', 'active_portal',
    ],
];

loadtest_write_json(loadtest_artifact_path($runId, 'sessions.json'), $out);
loadtest_ok('Bootstrapped ' . count($sessions) . ' examinee sessions + professor session for run_id=' . $runId);
loadtest_ok('session.save_path=' . $effectiveSavePath . ' (dedicated harness path; Apache must match for SAFE HTTP)');
mysqli_close($conn);
