<?php
/**
 * Build k6_input.json — requires status=SAFE http_attestation.json.
 * Never accepts CONFIG_ATTESTED or operator-only confirmation.
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
if ((string)($attestation['run_id'] ?? '') !== '' && (string)$attestation['run_id'] !== $runId && (string)$attestation['run_id'] !== 'preflight') {
    // Allow preflight run_id when operator has not seeded yet; otherwise require match.
    if (!str_starts_with((string)$attestation['run_id'], 'preflight') && (string)$attestation['run_id'] !== $runId) {
        loadtest_fail('Attestation run_id mismatch with LOADTEST_RUN_ID');
    }
}

$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$attemptsDoc = loadtest_read_json(loadtest_artifact_path($runId, 'attempts.json'));
$expectedAll = loadtest_read_json(loadtest_artifact_path($runId, 'expected_answers.json'));
$sessionsDoc = loadtest_read_json(loadtest_artifact_path($runId, 'sessions.json'));
$alignPath = loadtest_artifact_path($runId, 'align.json');
$align = is_file($alignPath) ? loadtest_read_json($alignPath) : [];

if ((string)($seed['run_id'] ?? $runId) !== $runId && isset($seed['run_id'])) {
    // seed always has run_id from 01_seed
}
if ((string)($attemptsDoc['run_id'] ?? '') !== '' && (string)$attemptsDoc['run_id'] !== $runId) {
    loadtest_fail('attempts.json run_id does not match LOADTEST_RUN_ID');
}
if ((string)($sessionsDoc['run_id'] ?? '') !== '' && (string)$sessionsDoc['run_id'] !== $runId) {
    loadtest_fail('sessions.json run_id does not match LOADTEST_RUN_ID');
}
if (strtolower((string)($attemptsDoc['db'] ?? '')) !== $dbName) {
    loadtest_fail('attempts.json db does not match guarded LOADTEST_DB_NAME');
}

if (empty($attemptsDoc['t_end'])) {
    loadtest_fail('attempts.json missing t_end — run 04_align_expires.php first');
}

$examId = (int)($seed['exam_id'] ?? $attemptsDoc['exam_id'] ?? 0);
if ($examId <= 0) {
    loadtest_fail('Invalid exam_id');
}

$liveQids = [];
$qr = mysqli_query(
    $conn,
    'SELECT question_id FROM college_exam_questions WHERE exam_id=' . (int)$examId .
    ' ORDER BY sort_order ASC, question_id ASC'
);
while ($qr && ($row = mysqli_fetch_assoc($qr))) {
    $liveQids[] = (int)$row['question_id'];
}
if ($qr) {
    mysqli_free_result($qr);
}
if ($liveQids === []) {
    loadtest_fail('No college_exam_questions for exam_id=' . $examId . ' in guarded DB');
}
$seedQids = array_map('intval', $seed['question_ids'] ?? []);
sort($seedQids);
$liveSorted = $liveQids;
sort($liveSorted);
if ($seedQids !== [] && $seedQids !== $liveSorted) {
    loadtest_fail('seed.json question_ids diverge from college_exam_questions in guarded DB');
}

$base = $baseUrl;
$artifactBase = rtrim((string)($attemptsDoc['base_url'] ?? $seed['base_url'] ?? ''), '/');
if ($artifactBase !== '' && $artifactBase !== $base) {
    loadtest_fail("Artifact base_url '{$artifactBase}' !== LOADTEST_BASE_URL '{$base}'");
}
$ajaxUrl = $base . '/examination/examinee/college_exam_ajax';
if (!empty($attemptsDoc['ajax_url'])) {
    $ajaxUrl = rtrim((string)$attemptsDoc['ajax_url'], '/');
    // allow with or without trailing path normalization
    if (!str_starts_with($ajaxUrl, $base)) {
        loadtest_fail('ajax_url must be derived from LOADTEST_BASE_URL (' . $base . '), got: ' . $ajaxUrl);
    }
}

$tEnd = (string)$attemptsDoc['t_end'];
$tEndUnix = null;
if (isset($align['t_end_unix'])) {
    $tEndUnix = (int)$align['t_end_unix'];
} else {
    $tsSt = mysqli_prepare($conn, 'SELECT UNIX_TIMESTAMP(?) AS u');
    mysqli_stmt_bind_param($tsSt, 's', $tEnd);
    mysqli_stmt_execute($tsSt);
    $tsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($tsSt));
    mysqli_stmt_close($tsSt);
    $tEndUnix = (int)($tsRow['u'] ?? 0);
}
if ($tEndUnix <= 0) {
    loadtest_fail('Could not resolve t_end_unix from MySQL for t_end=' . $tEnd);
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
    if ($uid <= 0) {
        loadtest_fail('Missing user_id in attempt row');
    }
    if ($aid <= 0) {
        loadtest_fail('Missing attempt_id for user_id=' . $uid);
    }
    if ($sid === '') {
        loadtest_fail('Missing PHPSESSID / session for user_id=' . $uid);
    }
    if ($csrf === '') {
        loadtest_fail('Missing csrf_token for user_id=' . $uid);
    }
    if (isset($seenSids[$sid])) {
        loadtest_fail('Duplicate PHPSESSID across examinees — cookie isolation broken');
    }
    $seenSids[$sid] = true;

    $sess = $sessionByUser[$uid] ?? null;
    if (!$sess) {
        loadtest_fail("No bootstrap session for user_id={$uid} in this run");
    }
    if ((string)($sess['PHPSESSID'] ?? '') !== $sid) {
        loadtest_fail("PHPSESSID mismatch between sessions.json and attempts for user_id={$uid}");
    }
    if ((string)($sess['csrf_token'] ?? '') !== $csrf) {
        loadtest_fail("csrf_token mismatch between sessions.json and attempts for user_id={$uid}");
    }

    $chk = mysqli_prepare(
        $conn,
        "SELECT a.attempt_id, a.status, a.expires_at, u.email
         FROM college_exam_attempts a
         INNER JOIN users u ON u.user_id=a.user_id
         WHERE a.attempt_id=? AND a.user_id=? AND a.exam_id=? LIMIT 1"
    );
    mysqli_stmt_bind_param($chk, 'iii', $aid, $uid, $examId);
    mysqli_stmt_execute($chk);
    $attRow = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$attRow || !loadtest_is_loadtest_email((string)$attRow['email'])) {
        loadtest_fail("attempt_id={$aid} missing in guarded DB for user_id={$uid}");
    }
    if (strtolower((string)$attRow['status']) !== 'in_progress') {
        loadtest_fail("attempt_id={$aid} status is not in_progress");
    }

    $answers = $expectedAll[(string)$uid] ?? $expectedAll[$uid] ?? null;
    if (!is_array($answers) || $answers === []) {
        loadtest_fail("Expected answers missing/empty for user_id={$uid}");
    }

    $gotQ = [];
    foreach ($answers as $row) {
        $qid = (int)($row['question_id'] ?? 0);
        $sel = strtoupper(trim((string)($row['selected_answer'] ?? '')));
        if ($qid <= 0 || !preg_match('/^[A-D]$/', $sel)) {
            loadtest_fail("Invalid expected answer row for user_id={$uid}");
        }
        if (!in_array($qid, $liveQids, true)) {
            loadtest_fail("Expected answer question_id={$qid} is not on exam_id={$examId}");
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
        $normalized[] = [
            'question_id' => $qid,
            'selected_answer' => $gotQ[$qid],
        ];
    }

    $examinees[] = [
        'user_id' => $uid,
        'attempt_id' => $aid,
        'exam_id' => $examId,
        'PHPSESSID' => $sid,
        'csrf_token' => $csrf,
        'cookie_header' => (string)($a['cookie_header'] ?? ('PHPSESSID=' . $sid)),
        'expires_at' => (string)($a['expires_at'] ?? $tEnd),
        'answers' => $normalized,
    ];
}

if ($examinees === []) {
    loadtest_fail('No examinees to write into k6_input.json');
}

$out = [
    'run_id' => $runId,
    'exam_id' => $examId,
    'db' => $dbName,
    'base_url' => $base,
    'ajax_url' => $ajaxUrl,
    't_end' => $tEnd,
    't_end_unix' => $tEndUnix,
    'grace_seconds' => 60,
    'answer_secret' => $secret,
    'question_ids' => $liveQids,
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
loadtest_ok('Wrote ' . $path . ' examinees=' . count($examinees) . ' attestation=SAFE t_end=' . $out['t_end']);
mysqli_close($conn);
