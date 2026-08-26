<?php
/**
 * Integrity verifier — FAIL the run if any intentional answer is missing/wrong after successful submit.
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';
require_once __DIR__ . '/expected_answers.php';
require_once loadtest_project_root() . '/examination/includes/college_exam_helpers.php';

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
$examId = (int)($seed['exam_id'] ?? 0);
$grace = max(0, (int)loadtest_env('LOADTEST_GRACE_SECONDS', '60'));

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
// Also accept client_submit_ok.json from harness helpers
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
    'examinees' => count($attemptsDoc['attempts'] ?? []),
    'attempts_in_artifact' => 0,
    'submitted' => 0,
    'in_progress' => 0,
    'expected_answer_rows' => 0,
    'matched_answer_rows' => 0,
    'missing_or_mismatch' => 0,
    'duplicate_attempts' => 0,
    'duplicate_answer_rows' => 0,
    'fail_closed_violations' => 0,
    'grace_violations' => 0,
    'score_mismatches' => 0,
];

$attemptRows = [];
foreach ($attemptsDoc['attempts'] as $a) {
    $aid = (int)($a['attempt_id'] ?? 0);
    $uid = (int)($a['user_id'] ?? 0);
    if ($aid <= 0 || $uid <= 0) {
        continue;
    }
    $stats['attempts_in_artifact']++;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT a.*, u.email, u.full_name
         FROM college_exam_attempts a
         INNER JOIN users u ON u.user_id=a.user_id
         WHERE a.attempt_id=? AND a.user_id=? AND a.exam_id=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'iii', $aid, $uid, $examId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !loadtest_is_loadtest_email((string)$row['email'])) {
        $failures[] = "Attempt {$aid} is missing or not a LOADTEST user";
        continue;
    }
    $attemptRows[] = $row;
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status === 'submitted') {
        $stats['submitted']++;
    } elseif ($status === 'in_progress') {
        $stats['in_progress']++;
    }

    // Successful client submit must not remain in_progress
    if (!empty($submitOkAttempts[$aid]) && $status !== 'submitted') {
        $failures[] = "Successful submit recorded for attempt {$aid} but DB status='{$status}' (expected submitted)";
    }

    // Fail-closed: flush failed must not be submitted
    if (!empty($flushFailedAttempts[$aid]) && $status === 'submitted') {
        $stats['fail_closed_violations']++;
        $failures[] = "FAIL-CLOSED violated: attempt {$aid} flush failed but status=submitted";
    }

    $expected = $expectedAll[(string)$uid] ?? $expectedAll[$uid] ?? null;
    if (!is_array($expected)) {
        $failures[] = "Missing expected answers for user_id={$uid}";
        continue;
    }

    // Hard-require full answer match when submit succeeded OR attempt is submitted
    $mustMatch = !empty($submitOkAttempts[$aid]) || $status === 'submitted';
    if (!$mustMatch) {
        // Not a soft pass for successful runs — only skip rows that never claimed success.
        $warnings[] = "No successful submit for attempt {$aid} (status={$status}); answer match not required";
        continue;
    }

    $ansRes = mysqli_query(
        $conn,
        'SELECT question_id, selected_answer, COUNT(*) AS c FROM college_exam_answers WHERE attempt_id=' . (int)$aid .
        ' GROUP BY question_id, selected_answer'
    );
    $byQ = [];
    $dupAns = 0;
    if ($ansRes) {
        // Use distinct query for duplicates
        mysqli_free_result($ansRes);
    }
    $dupQ = mysqli_query(
        $conn,
        'SELECT question_id, COUNT(*) AS c FROM college_exam_answers WHERE attempt_id=' . (int)$aid .
        ' GROUP BY question_id HAVING c > 1'
    );
    while ($dupQ && ($d = mysqli_fetch_assoc($dupQ))) {
        $dupAns += (int)$d['c'];
        $failures[] = "Duplicate answer rows for attempt {$aid} question_id=" . (int)$d['question_id'];
    }
    if ($dupQ) {
        mysqli_free_result($dupQ);
    }
    $stats['duplicate_answer_rows'] += $dupAns;

    $ansRes = mysqli_query(
        $conn,
        'SELECT question_id, selected_answer FROM college_exam_answers WHERE attempt_id=' . (int)$aid
    );
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
            $failures[] = "Answer mismatch attempt={$aid} user={$uid} question={$qid} expected={$want} got=" . ($got ?? 'NULL');
        } else {
            $stats['matched_answer_rows']++;
        }
    }

    // Score recompute using app helper (shuffle off in seed)
    if ($status === 'submitted') {
        $examRow = null;
        $er = mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . (int)$examId . ' LIMIT 1');
        $examRow = $er ? mysqli_fetch_assoc($er) : null;
        if ($er) {
            mysqli_free_result($er);
        }
        $questions = [];
        $qr = mysqli_query($conn, 'SELECT * FROM college_exam_questions WHERE exam_id=' . (int)$examId . ' ORDER BY sort_order ASC, question_id ASC');
        while ($qr && ($q = mysqli_fetch_assoc($qr))) {
            $questions[] = $q;
        }
        if ($qr) {
            mysqli_free_result($qr);
        }
        if ($examRow) {
            $questions = college_exam_prepare_questions_for_attempt($questions, $examRow, $aid);
            $correct = 0;
            $total = count($questions);
            foreach ($questions as $q) {
                $qid = (int)$q['question_id'];
                $expLetter = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
                $sel = $byQ[$qid] ?? '';
                if ($sel !== '' && $sel === $expLetter) {
                    $correct++;
                }
            }
            $score = $total > 0 ? college_exam_compute_score_percentage($correct, $total) : 0.0;
            $dbCorrect = (int)($row['correct_count'] ?? -1);
            $dbTotal = (int)($row['total_count'] ?? -1);
            $dbScore = (float)($row['score'] ?? -1);
            if ($dbCorrect !== $correct || $dbTotal !== $total || abs($dbScore - $score) > 0.051) {
                $stats['score_mismatches']++;
                $failures[] = sprintf(
                    'Score mismatch attempt=%d db=(%d/%d score=%.2f) expected=(%d/%d score=%.2f)',
                    $aid,
                    $dbCorrect,
                    $dbTotal,
                    $dbScore,
                    $correct,
                    $total,
                    $score
                );
            }
        }
    }
}

// Duplicate attempts per user/exam
$dupAtt = mysqli_query(
    $conn,
    'SELECT user_id, COUNT(*) AS c FROM college_exam_attempts WHERE exam_id=' . (int)$examId .
    ' GROUP BY user_id HAVING c > 1'
);
while ($dupAtt && ($d = mysqli_fetch_assoc($dupAtt))) {
    $stats['duplicate_attempts']++;
    $failures[] = 'Duplicate attempts for user_id=' . (int)$d['user_id'] . ' count=' . (int)$d['c'];
}
if ($dupAtt) {
    mysqli_free_result($dupAtt);
}

// Grace: submitted without client ok before expires+grace
$monitorPath = loadtest_artifact_path($runId, 'monitor.json');
if (is_file($monitorPath)) {
    $mon = loadtest_read_json($monitorPath);
    $vc = (int)($mon['violation_count'] ?? 0);
    $stats['grace_violations'] = $vc;
    if ($vc > 0) {
        $failures[] = "Grace violations from monitor.json: {$vc}";
    }
}

$report = [
    'run_id' => $runId,
    'db' => $dbName,
    'exam_id' => $examId,
    'stats' => $stats,
    'failures' => $failures,
    'warnings' => $warnings,
    'passed' => $failures === [],
    'verified_at' => date('c'),
];
loadtest_write_json(loadtest_artifact_path($runId, 'integrity_report.json'), $report);

if ($failures !== []) {
    fwrite(STDERR, "INTEGRITY FAIL (" . count($failures) . "):\n");
    foreach (array_slice($failures, 0, 50) as $f) {
        fwrite(STDERR, " - {$f}\n");
    }
    if (count($failures) > 50) {
        fwrite(STDERR, ' - ... ' . (count($failures) - 50) . " more\n");
    }
    mysqli_close($conn);
    exit(1);
}

loadtest_ok('INTEGRITY PASS — all intentional answers matched for successful submits');
loadtest_ok(json_encode($stats, JSON_UNESCAPED_SLASHES));
mysqli_close($conn);
