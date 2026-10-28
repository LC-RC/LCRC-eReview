<?php
/**
 * Diagnostic integrity verifier — FAIL if intentional answers missing/wrong after successful submit.
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';
require_once __DIR__ . '/expected_answers.php';
require_once loadtest_project_root() . '/examination/includes/diagnostic_exam_helpers.php';

[$conn, $dbName] = loadtest_connect();

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);

$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$expectedAll = loadtest_read_json(loadtest_artifact_path($runId, 'expected_answers.json'));
$attemptsDoc = loadtest_read_json(loadtest_artifact_path($runId, 'attempts.json'));
$batchId = (int)($seed['batch_id'] ?? 0);
if ($batchId <= 0 || (string)($seed['mode'] ?? '') !== 'diagnostic') {
    loadtest_fail('Diagnostic verify requires seed.mode=diagnostic and batch_id');
}

$k6SummaryPath = loadtest_artifact_path($runId, 'k6_summary.json');
$k6Summary = is_file($k6SummaryPath) ? loadtest_read_json($k6SummaryPath) : [];
$submitOkAttempts = [];
$flushFailedAttempts = [];
if (isset($k6Summary['submit_ok_attempt_ids']) && is_array($k6Summary['submit_ok_attempt_ids'])) {
    foreach ($k6Summary['submit_ok_attempt_ids'] as $aid) {
        $submitOkAttempts[(int)$aid] = true;
    }
}
if (isset($k6Summary['flush_failed_attempt_ids']) && is_array($k6Summary['flush_failed_attempt_ids'])) {
    foreach ($k6Summary['flush_failed_attempt_ids'] as $aid) {
        $flushFailedAttempts[(int)$aid] = true;
    }
}
$clientOkPath = loadtest_artifact_path($runId, 'client_submit_ok.json');
if (is_file($clientOkPath)) {
    foreach (loadtest_read_json($clientOkPath) as $aid => $flag) {
        if ($flag) {
            $submitOkAttempts[(int)$aid] = true;
        }
    }
}

$failures = [];
$warnings = [];
$stats = [
    'mode' => 'diagnostic',
    'examinees' => count($attemptsDoc['attempts'] ?? []),
    'submitted' => 0,
    'in_progress' => 0,
    'expected_answer_rows' => 0,
    'matched_answer_rows' => 0,
    'missing_or_mismatch' => 0,
    'score_mismatches' => 0,
    'fail_closed_violations' => 0,
];

foreach ($attemptsDoc['attempts'] as $a) {
    $aid = (int)($a['attempt_id'] ?? 0);
    $uid = (int)($a['user_id'] ?? 0);
    if ($aid <= 0 || $uid <= 0) {
        continue;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT a.*, u.email, u.full_name
         FROM diagnostic_attempts a
         INNER JOIN users u ON u.user_id=a.user_id
         WHERE a.attempt_id=? AND a.user_id=? AND a.batch_id=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'iii', $aid, $uid, $batchId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !loadtest_is_loadtest_email((string)$row['email'])) {
        $failures[] = "Attempt {$aid} missing or not LOADTEST";
        continue;
    }
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status === 'submitted') {
        $stats['submitted']++;
    } elseif ($status === 'in_progress') {
        $stats['in_progress']++;
    }
    if (!empty($submitOkAttempts[$aid]) && $status !== 'submitted') {
        $failures[] = "Successful submit for attempt {$aid} but DB status='{$status}'";
    }
    if (!empty($flushFailedAttempts[$aid]) && $status === 'submitted') {
        $stats['fail_closed_violations']++;
        $failures[] = "FAIL-CLOSED: attempt {$aid} flush failed but status=submitted";
    }

    $expected = $expectedAll[(string)$uid] ?? $expectedAll[$uid] ?? null;
    if (!is_array($expected)) {
        $failures[] = "Missing expected answers for user_id={$uid}";
        continue;
    }
    $mustMatch = !empty($submitOkAttempts[$aid]) || $status === 'submitted';
    if (!$mustMatch) {
        $warnings[] = "No successful submit for attempt {$aid} (status={$status})";
        continue;
    }

    $dupQ = mysqli_query(
        $conn,
        'SELECT question_id, COUNT(*) AS c FROM diagnostic_answers WHERE attempt_id=' . (int)$aid .
        ' GROUP BY question_id HAVING c > 1'
    );
    while ($dupQ && ($d = mysqli_fetch_assoc($dupQ))) {
        $failures[] = "Duplicate answer rows attempt {$aid} question_id=" . (int)$d['question_id'];
    }
    if ($dupQ) {
        mysqli_free_result($dupQ);
    }

    $byQ = [];
    $ansRes = mysqli_query($conn, 'SELECT question_id, selected_answer FROM diagnostic_answers WHERE attempt_id=' . (int)$aid);
    while ($ansRes && ($ar = mysqli_fetch_assoc($ansRes))) {
        $byQ[(int)$ar['question_id']] = strtoupper(trim((string)$ar['selected_answer']));
    }
    if ($ansRes) {
        mysqli_free_result($ansRes);
    }

    foreach ($expected as $exp) {
        $qid = (int)($exp['question_id'] ?? 0);
        $want = strtoupper(trim((string)($exp['selected_answer'] ?? '')));
        if ($qid <= 0 || !preg_match('/^[A-D]$/', $want)) {
            continue;
        }
        $stats['expected_answer_rows']++;
        $got = $byQ[$qid] ?? null;
        if ($got === null || $got !== $want) {
            $stats['missing_or_mismatch']++;
            $failures[] = "Answer mismatch attempt={$aid} q={$qid} expected={$want} got=" . ($got ?? 'NULL');
        } else {
            $stats['matched_answer_rows']++;
        }
    }

    if ($status === 'submitted' && empty($row['subject_breakdown_json'])) {
        $failures[] = "Attempt {$aid} submitted without subject_breakdown_json";
    }
}

$report = [
    'run_id' => $runId,
    'mode' => 'diagnostic',
    'db' => $dbName,
    'batch_id' => $batchId,
    'stats' => $stats,
    'failures' => $failures,
    'warnings' => $warnings,
    'passed' => $failures === [],
    'verified_at' => date('c'),
];
loadtest_write_json(loadtest_artifact_path($runId, 'integrity_report.json'), $report);

if ($failures !== []) {
    fwrite(STDERR, 'DIAGNOSTIC INTEGRITY FAIL (' . count($failures) . "):\n");
    foreach (array_slice($failures, 0, 50) as $f) {
        fwrite(STDERR, " - {$f}\n");
    }
    mysqli_close($conn);
    exit(1);
}

loadtest_ok('DIAGNOSTIC INTEGRITY PASS');
loadtest_ok(json_encode($stats, JSON_UNESCAPED_SLASHES));
mysqli_close($conn);
