<?php
/**
 * Build k6_input.json for diagnostic mode — requires status=SAFE attestation.
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';
require_once __DIR__ . '/expected_answers.php';

[$conn, $dbName] = loadtest_connect();

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);

$baseUrl = loadtest_require_base_url();

$attestationPath = loadtest_attestation_path($runId);
if (!is_file($attestationPath)) {
    $attestationPath = loadtest_attestation_path(null);
}
if (!is_file($attestationPath)) {
    loadtest_fail('Missing http_attestation.json — run HttpPreflight first. HTTP/k6 BLOCKED.');
}
$attestation = loadtest_read_json($attestationPath);
$attestation = loadtest_require_safe_attestation($attestation, $baseUrl, $dbName);

$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$attemptsDoc = loadtest_read_json(loadtest_artifact_path($runId, 'attempts.json'));
$expectedAll = loadtest_read_json(loadtest_artifact_path($runId, 'expected_answers.json'));
$sessionsDoc = loadtest_read_json(loadtest_artifact_path($runId, 'sessions.json'));
$alignPath = loadtest_artifact_path($runId, 'align.json');
$align = is_file($alignPath) ? loadtest_read_json($alignPath) : [];

if ((string)($seed['mode'] ?? '') !== 'diagnostic' || (string)($attemptsDoc['mode'] ?? '') !== 'diagnostic') {
    loadtest_fail('seed/attempts mode must be diagnostic');
}
if ((string)($attemptsDoc['run_id'] ?? '') !== '' && (string)$attemptsDoc['run_id'] !== $runId) {
    loadtest_fail('attempts.json run_id does not match LOADTEST_RUN_ID');
}
if (strtolower((string)($attemptsDoc['db'] ?? '')) !== $dbName) {
    loadtest_fail('attempts.json db does not match guarded LOADTEST_DB_NAME');
}
if (empty($attemptsDoc['t_end'])) {
    loadtest_fail('attempts.json missing t_end — run 04_align_expires_diagnostic.php first');
}

$batchId = (int)($seed['batch_id'] ?? $attemptsDoc['batch_id'] ?? 0);
if ($batchId <= 0) {
    loadtest_fail('Invalid batch_id');
}

$liveQids = [];
$qr = mysqli_query(
    $conn,
    'SELECT question_id FROM diagnostic_questions WHERE batch_id=' . (int)$batchId .
    ' ORDER BY sort_order ASC, question_id ASC'
);
while ($qr && ($row = mysqli_fetch_assoc($qr))) {
    $liveQids[] = (int)$row['question_id'];
}
if ($qr) {
    mysqli_free_result($qr);
}
if ($liveQids === []) {
    loadtest_fail('No diagnostic_questions for batch_id=' . $batchId);
}

$base = $baseUrl;
$artifactBase = rtrim((string)($attemptsDoc['base_url'] ?? $seed['base_url'] ?? ''), '/');
if ($artifactBase !== '' && $artifactBase !== $base) {
    loadtest_fail("Artifact base_url '{$artifactBase}' !== LOADTEST_BASE_URL '{$base}'");
}
$ajaxUrl = $base . '/examination/examinee/college_diagnostic_ajax';
if (!empty($attemptsDoc['ajax_url'])) {
    $ajaxUrl = rtrim((string)$attemptsDoc['ajax_url'], '/');
    if (!str_starts_with($ajaxUrl, $base)) {
        loadtest_fail('ajax_url must be derived from LOADTEST_BASE_URL (' . $base . '), got: ' . $ajaxUrl);
    }
}

$tEnd = (string)$attemptsDoc['t_end'];
$tEndUnix = isset($align['t_end_unix']) ? (int)$align['t_end_unix'] : 0;
if ($tEndUnix <= 0) {
    $tsSt = mysqli_prepare($conn, 'SELECT UNIX_TIMESTAMP(?) AS u');
    mysqli_stmt_bind_param($tsSt, 's', $tEnd);
    mysqli_stmt_execute($tsSt);
    $tsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($tsSt));
    mysqli_stmt_close($tsSt);
    $tEndUnix = (int)($tsRow['u'] ?? 0);
}
if ($tEndUnix <= 0) {
    loadtest_fail('Could not resolve t_end_unix');
}

$secret = (string)($seed['answer_secret'] ?? loadtest_secret());
$examinees = [];
$seenSids = [];
$sessionByUser = [];
foreach ($sessionsDoc['students'] ?? [] as $s) {
    $sessionByUser[(int)($s['user_id'] ?? 0)] = $s;
}

foreach ($attemptsDoc['attempts'] as $a) {
    $uid = (int)($a['user_id'] ?? 0);
    $aid = (int)($a['attempt_id'] ?? 0);
    $sid = (string)($a['PHPSESSID'] ?? '');
    $csrf = (string)($a['csrf_token'] ?? '');
    if ($uid <= 0 || $aid <= 0 || $sid === '' || $csrf === '') {
        loadtest_fail('Incomplete diagnostic attempt row');
    }
    if (isset($seenSids[$sid])) {
        loadtest_fail('Duplicate PHPSESSID across examinees');
    }
    $seenSids[$sid] = true;
    $sess = $sessionByUser[$uid] ?? null;
    if (!$sess || (string)($sess['PHPSESSID'] ?? '') !== $sid || (string)($sess['csrf_token'] ?? '') !== $csrf) {
        loadtest_fail("Session mismatch for user_id={$uid}");
    }

    $chk = mysqli_prepare(
        $conn,
        "SELECT a.attempt_id, a.status, u.email
         FROM diagnostic_attempts a
         INNER JOIN users u ON u.user_id=a.user_id
         WHERE a.attempt_id=? AND a.user_id=? AND a.batch_id=? LIMIT 1"
    );
    mysqli_stmt_bind_param($chk, 'iii', $aid, $uid, $batchId);
    mysqli_stmt_execute($chk);
    $attRow = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$attRow || !loadtest_is_loadtest_email((string)$attRow['email'])) {
        loadtest_fail("diagnostic attempt_id={$aid} missing for user_id={$uid}");
    }
    if (strtolower((string)$attRow['status']) !== 'in_progress') {
        loadtest_fail("attempt_id={$aid} status is not in_progress");
    }

    $answers = $expectedAll[(string)$uid] ?? $expectedAll[$uid] ?? null;
    if (!is_array($answers) || $answers === []) {
        loadtest_fail("Expected answers missing for user_id={$uid}");
    }
    $gotQ = [];
    foreach ($answers as $row) {
        $qid = (int)($row['question_id'] ?? 0);
        $sel = strtoupper(trim((string)($row['selected_answer'] ?? '')));
        if ($qid <= 0 || !preg_match('/^[A-D]$/', $sel)) {
            loadtest_fail("Invalid expected answer for user_id={$uid}");
        }
        if (!in_array($qid, $liveQids, true)) {
            loadtest_fail("Expected question_id={$qid} not on batch_id={$batchId}");
        }
        $gotQ[$qid] = $sel;
    }
    foreach ($liveQids as $qid) {
        if (!isset($gotQ[$qid])) {
            loadtest_fail("Expected answers omit question_id={$qid} for user_id={$uid}");
        }
    }
    $normalized = [];
    foreach ($liveQids as $qid) {
        $normalized[] = ['question_id' => $qid, 'selected_answer' => $gotQ[$qid]];
    }

    $examinees[] = [
        'user_id' => $uid,
        'attempt_id' => $aid,
        'batch_id' => $batchId,
        'exam_id' => null,
        'PHPSESSID' => $sid,
        'csrf_token' => $csrf,
        'cookie_header' => (string)($a['cookie_header'] ?? ('PHPSESSID=' . $sid)),
        'expires_at' => (string)($a['expires_at'] ?? $tEnd),
        'answers' => $normalized,
    ];
}

if ($examinees === []) {
    loadtest_fail('No examinees for diagnostic k6_input.json');
}

$scenario = strtolower(trim((string)loadtest_env('LOADTEST_SCENARIO', 'timeout_wave')));
$out = [
    'run_id' => $runId,
    'mode' => 'diagnostic',
    'scenario' => $scenario,
    'exam_id' => null,
    'batch_id' => $batchId,
    'db' => $dbName,
    'base_url' => $base,
    'ajax_url' => $ajaxUrl,
    't_end' => $tEnd,
    't_end_unix' => $tEndUnix,
    'grace_seconds' => 60,
    'answer_secret' => $secret,
    'question_ids' => $liveQids,
    'notes' => [
        'harness' => 'HTTP/load approximation — not a real browser certification',
        'approximates' => ['debounced saves', 'save_seq', 'polling', 'submit bursts', 'duplicate submit'],
        'does_not_reproduce' => ['DOM', 'visibility handlers', 'exact JS timers'],
    ],
    'http_attestation' => [
        'status' => 'SAFE',
        'database' => $attestation['database'],
        'base_url' => $attestation['base_url'],
        'verified_at' => $attestation['verified_at'],
        'verification_method' => $attestation['verification_method'],
        'run_id' => $attestation['run_id'] ?? $runId,
    ],
    'examinees' => $examinees,
];

$path = loadtest_artifact_path($runId, 'k6_input.json');
loadtest_write_json($path, $out);
loadtest_ok('Wrote diagnostic ' . $path . ' examinees=' . count($examinees));
mysqli_close($conn);
