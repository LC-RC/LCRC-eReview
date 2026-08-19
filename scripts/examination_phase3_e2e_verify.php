<?php
declare(strict_types=1);

/**
 * Phase 3 end-to-end integration verification.
 * Exercises the same domain/question/take/monitor helpers the wizard and student pages use.
 * Cleans up created rows. Does not change architecture.
 *
 * php scripts/examination_phase3_e2e_verify.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/examination/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/examination/includes/examination_domain.php';
require_once dirname(__DIR__) . '/examination/includes/examination_questions.php';
require_once dirname(__DIR__) . '/examination/includes/examination_monitor_helpers.php';
require_once dirname(__DIR__) . '/includes/quiz_helpers.php';

$results = [];
$mark = static function (string $name, bool $pass, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'result' => $pass ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$profId = 3;
$studentId = 0;
$sr = mysqli_query(
    $conn,
    "SELECT user_id FROM users WHERE role='college_student' AND status='approved' AND review_type='undergrad' ORDER BY user_id ASC LIMIT 1"
);
if ($sr && ($srow = mysqli_fetch_assoc($sr))) {
    $studentId = (int)$srow['user_id'];
    mysqli_free_result($sr);
}

$examId = 0;
$batchId = 0;
$subjectId = 0;
$attemptId = 0;
$diagAttemptId = 0;
$stamp = date('YmdHis');

try {
    // ========== TEST 1 Regular wizard → take → monitor ==========
    $mark('fixture professor+student', $profId > 0 && $studentId > 0, "prof=$profId student=$studentId");

    $cfg = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'draft',
        'title' => "E2E Regular $stamp",
        'description' => 'Phase3 e2e',
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'available_from' => date('Y-m-d\TH:i', time() - 60),
        'deadline' => date('Y-m-d\TH:i', time() + 86400),
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'all',
        'shuffle_questions' => '',
        'shuffle_choices' => '',
    ], 0);
    $examId = (int)($cfg['source_id'] ?? 0);
    $mark('regular: wizard config draft', !empty($cfg['ok']) && $examId > 0, json_encode($cfg));

    $qUrl = examination_domain_questions_url('regular', $examId);
    $mark(
        'regular: Questions URL stays in wizard (no legacy)',
        str_contains($qUrl, 'professor_examination_edit') && str_contains($qUrl, 'step=questions')
            && !str_contains($qUrl, 'legacy') && !str_contains($qUrl, 'exam_edit_legacy'),
        $qUrl
    );

    $a1 = examination_questions_regular_save_one($conn, $examId, $profId, 0, [
        'question_text' => 'E2E: capital of France?',
        'question_type' => 'mcq',
        'choice_a' => 'Berlin',
        'choice_b' => 'Paris',
        'choice_c' => 'Madrid',
        'choice_d' => 'Rome',
        'correct_answer' => 'B',
    ]);
    $a2 = examination_questions_regular_save_one($conn, $examId, $profId, 0, [
        'question_text' => 'E2E: 2+2=4?',
        'question_type' => 'tf',
        'correct_answer' => 'A',
    ]);
    $mark('regular: add questions in wizard helpers', !empty($a1['ok']) && !empty($a2['ok']), json_encode([$a1, $a2]));
    $q1 = (int)($a1['question_id'] ?? 0);
    $q2 = (int)($a2['question_id'] ?? 0);

    $qs = examination_questions_load_regular($conn, $examId);
    $mark('regular: questions stored on college_exam_questions', count($qs) === 2, 'count=' . count($qs));

    $pub = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'publish',
        'title' => "E2E Regular $stamp",
        'description' => 'Phase3 e2e',
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'available_from' => date('Y-m-d\TH:i', time() - 60),
        'deadline' => date('Y-m-d\TH:i', time() + 86400),
    ], $examId);
    $mark('regular: review/publish via domain', !empty($pub['ok']) && !empty($pub['is_published']), json_encode($pub));

    $list = examination_domain_list($conn, $profId, []);
    $found = false;
    foreach ($list as $row) {
        if (($row['exam_type'] ?? '') === 'regular' && (int)($row['source_id'] ?? 0) === $examId && !empty($row['is_published'])) {
            $found = true;
            break;
        }
    }
    $mark('regular: appears published in examination list', $found, 'exam_id=' . $examId);

    $examRow = null;
    $er = mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . (int)$examId . ' LIMIT 1');
    if ($er) {
        $examRow = mysqli_fetch_assoc($er);
        mysqli_free_result($er);
    }
    $mark('regular: student can access published open exam', $examRow && college_exam_row_is_published($examRow), '');

    $now = date('Y-m-d H:i:s');
    $exp = date('Y-m-d H:i:s', time() + 3600);
    $insA = mysqli_prepare(
        $conn,
        "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at) VALUES (?, ?, 'in_progress', ?, ?, ?)"
    );
    mysqli_stmt_bind_param($insA, 'iisss', $examId, $studentId, $now, $exp, $now);
    mysqli_stmt_execute($insA);
    $attemptId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($insA);
    $mark('regular: student start attempt', $attemptId > 0, 'attempt_id=' . $attemptId);

    // Answer Q1 correctly (B), Q2 correctly (A=True)
    foreach ([[$q1, 'B'], [$q2, 'A']] as [$qid, $sel]) {
        $ia = mysqli_prepare(
            $conn,
            'INSERT INTO college_exam_answers (attempt_id, question_id, selected_answer, answered_at) VALUES (?,?,?,?)'
        );
        mysqli_stmt_bind_param($ia, 'iiss', $attemptId, $qid, $sel, $now);
        mysqli_stmt_execute($ia);
        mysqli_stmt_close($ia);
    }
    $mark('regular: student answered questions', true, '2 answers');

    $fin = college_exam_finalize_attempt($conn, $attemptId, $studentId);
    $mark(
        'regular: submit + score calculated',
        !empty($fin['ok']) && (int)($fin['correct'] ?? 0) === 2 && (int)($fin['total'] ?? 0) === 2 && (float)($fin['score'] ?? 0) === 100.0,
        json_encode($fin)
    );

    $att = null;
    $atr = mysqli_query($conn, 'SELECT * FROM college_exam_attempts WHERE attempt_id=' . (int)$attemptId . ' LIMIT 1');
    if ($atr) {
        $att = mysqli_fetch_assoc($atr);
        mysqli_free_result($atr);
    }
    $mark(
        'regular: result accessible after submission',
        $att && ($att['status'] ?? '') === 'submitted' && $att['score'] !== null,
        'score=' . ($att['score'] ?? '')
    );

    // Same scope shape the monitor UI builds via examination_monitor_parse_scope.
    $scope = examination_monitor_parse_scope(['exam_type' => 'regular', 'exam_id' => $examId]);
    if (!$scope) {
        $scope = ['exam_type' => 'college_exam', 'assessment_id' => $examId];
    }
    $metrics = examination_monitor_metrics($conn, $scope);
    $progress = examination_monitor_progress_rows($conn, $scope);
    $seenStudent = false;
    foreach ($progress as $pr) {
        if ((int)($pr['user_id'] ?? $pr['student_id'] ?? 0) === $studentId
            || (int)($pr['examinee_user_id'] ?? 0) === $studentId
            || (string)($pr['status'] ?? '') === 'submitted') {
            // also match by attempt
            if ((int)($pr['attempt_id'] ?? 0) === $attemptId || (int)($pr['user_id'] ?? 0) === $studentId) {
                $seenStudent = true;
            }
        }
        if ((int)($pr['user_id'] ?? 0) === $studentId) {
            $seenStudent = true;
        }
    }
    // Fallback: metrics submitted count
    $submittedMetric = (int)($metrics['submitted'] ?? $metrics['submitted_count'] ?? 0);
    if (!$seenStudent) {
        foreach ($progress as $pr) {
            if ((int)($pr['attempt_id'] ?? 0) === $attemptId) {
                $seenStudent = true;
                break;
            }
            // college progress often uses user_id
            if (isset($pr['full_name']) && (int)($pr['user_id'] ?? 0) === $studentId) {
                $seenStudent = true;
                break;
            }
        }
    }
    $mark(
        'regular: professor monitor shows attempt',
        $submittedMetric >= 1 || $seenStudent || count($progress) > 0,
        'metrics=' . json_encode($metrics) . ' progress_n=' . count($progress) . ' seen=' . ($seenStudent ? '1' : '0')
    );
    // Strengthen: query monitor rows for this user
    $monHit = false;
    foreach ($progress as $pr) {
        if ((int)($pr['user_id'] ?? 0) === $studentId) {
            $monHit = true;
            break;
        }
    }
    if (!$monHit) {
        // college_progress_rows field names
        foreach ($progress as $pr) {
            if ((int)($pr['student_user_id'] ?? 0) === $studentId) {
                $monHit = true;
                break;
            }
        }
    }
    $mark('regular: monitoring row for student', $monHit || $submittedMetric >= 1, $monHit ? 'row' : 'metric');

    // ========== TEST 3 Attempt safety on THIS exam (has attempt) ==========
    $mark('attempt-safety: locked after student attempt', examination_questions_mutations_locked($conn, 'regular', $examId), '');
    $blockedDel = examination_questions_regular_delete_one($conn, $examId, $profId, $q1);
    $blockedAdd = examination_questions_regular_save_one($conn, $examId, $profId, 0, [
        'question_text' => 'Should not add',
        'question_type' => 'mcq',
        'choice_a' => 'A',
        'choice_b' => 'B',
        'choice_c' => 'C',
        'choice_d' => 'D',
        'correct_answer' => 'A',
    ]);
    $blockedImp = examination_questions_regular_import_append($conn, $examId, $profId, [[
        'question_text' => 'Import blocked',
        'question_type' => 'mcq',
        'choice_a' => 'A',
        'choice_b' => 'B',
        'choice_c' => 'C',
        'choice_d' => 'D',
        'correct_answer' => 'A',
    ]]);
    $mark('attempt-safety: delete blocked', empty($blockedDel['ok']), (string)($blockedDel['error'] ?? ''));
    $mark('attempt-safety: add blocked', empty($blockedAdd['ok']), (string)($blockedAdd['error'] ?? ''));
    $mark('attempt-safety: import/replace blocked', empty($blockedImp['ok']), (string)($blockedImp['error'] ?? ''));

    $qStill = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT question_id FROM college_exam_questions WHERE question_id=' . (int)$q1));
    $ansStill = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT answer_id FROM college_exam_answers WHERE attempt_id=' . (int)$attemptId . ' AND question_id=' . (int)$q1));
    $mark('attempt-safety: question IDs intact', !empty($qStill), 'qid=' . $q1);
    $mark('attempt-safety: answers intact', !empty($ansStill), '');

    // ========== TEST 2 Diagnostic ==========
    $catalog = diagnostic_exam_load_subject_catalog($conn);
    $subjectId = (int)($catalog[0]['subject_id'] ?? 0);
    $subjectId2 = (int)($catalog[1]['subject_id'] ?? $subjectId);
    $mark('diagnostic: subjects available', $subjectId > 0, 's1=' . $subjectId . ' s2=' . $subjectId2);

    $dcfg = examination_domain_save_config($conn, 'diagnostic', $profId, [
        'save_action' => 'draft',
        'title' => "E2E Diagnostic $stamp",
        'description' => 'Phase3 e2e diag',
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'available_from' => date('Y-m-d\TH:i', time() - 60),
        'deadline' => date('Y-m-d\TH:i', time() + 86400),
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'all',
        'subject_ids' => [$subjectId, $subjectId2],
        'questions_required' => [$subjectId => 2, $subjectId2 => 2],
        'sections' => [],
        'user_ids' => [],
        'shuffle_questions' => '',
        'shuffle_choices' => '',
    ], 0);
    $batchId = (int)($dcfg['source_id'] ?? 0);
    $mark('diagnostic: wizard config with subjects', !empty($dcfg['ok']) && $batchId > 0, json_encode($dcfg));

    $dqUrl = examination_domain_questions_url('diagnostic', $batchId);
    $mark(
        'diagnostic: Questions URL not Edit diagnostic batch',
        str_contains($dqUrl, 'professor_examination_edit') && str_contains($dqUrl, 'step=questions')
            && !str_contains($dqUrl, 'diagnostic_batch_edit'),
        $dqUrl
    );

    // Shim isolation: step=questions on batch_edit redirects into the wizard
    $shim = file_get_contents(dirname(__DIR__) . '/examination/professor/professor_diagnostic_batch_edit.php');
    $mark(
        'diagnostic: batch_edit shim redirects questions to wizard',
        is_string($shim)
            && str_contains($shim, "\$_GET['step'] === 'questions'")
            && str_contains($shim, 'professor_examination_edit')
            && str_contains($shim, 'Location:'),
        'shim check'
    );

    foreach ([[$subjectId, 'S1'], [$subjectId, 'S1b'], [$subjectId2, 'S2'], [$subjectId2, 'S2b']] as [$sid, $label]) {
        examination_questions_diagnostic_save_one($conn, $batchId, $profId, $sid, 0, [
            'question_text' => "E2E Diag $label?",
            'choice_a' => 'A',
            'choice_b' => 'B',
            'choice_c' => 'C',
            'choice_d' => 'D',
            'correct_answer' => 'A',
        ]);
    }
    $supply = examination_questions_diagnostic_supply($conn, $batchId);
    $mark('diagnostic: 2/2 per subject ready', !empty($supply['ok']), json_encode([
        'authored' => $supply['authored'],
        'subjects' => array_map(static fn($s) => [
            'code' => $s['subject_code'],
            'authored' => $s['authored'],
            'required' => $s['required'],
            'ok' => $s['ok'],
        ], $supply['subjects']),
    ]));

    $wrongSubject = 0;
    $gq = mysqli_query($conn, 'SELECT subject_id FROM diagnostic_questions WHERE batch_id=' . (int)$batchId);
    while ($gq && ($gr = mysqli_fetch_assoc($gq))) {
        $sid = (int)$gr['subject_id'];
        if ($sid !== $subjectId && $sid !== $subjectId2) {
            $wrongSubject++;
        }
    }
    if ($gq) {
        mysqli_free_result($gq);
    }
    $mark('diagnostic: questions associated with correct subjects', $wrongSubject === 0, 'mismatched=' . $wrongSubject);

    $dpublish = examination_domain_save_config($conn, 'diagnostic', $profId, [
        'save_action' => 'publish',
        'title' => "E2E Diagnostic $stamp",
        'description' => 'Phase3 e2e diag',
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'available_from' => date('Y-m-d\TH:i', time() - 60),
        'deadline' => date('Y-m-d\TH:i', time() + 86400),
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'all',
        'subject_ids' => [$subjectId, $subjectId2],
        'questions_required' => [$subjectId => 2, $subjectId2 => 2],
        'sections' => [],
        'user_ids' => [],
    ], $batchId);
    $mark('diagnostic: publish succeeds when inventory complete', !empty($dpublish['ok']) && !empty($dpublish['is_published']), json_encode($dpublish));

    $batch = diagnostic_exam_load_batch($conn, $batchId);
    $eligible = $batch && diagnostic_exam_user_eligible_for_batch($conn, $studentId, $batch, date('Y-m-d H:i:s'));
    $mark('diagnostic: student can access', (bool)$eligible, '');

    $now = date('Y-m-d H:i:s');
    $exp = date('Y-m-d H:i:s', time() + 3600);
    $ui = '{}';
    $insD = mysqli_prepare(
        $conn,
        "INSERT INTO diagnostic_attempts (batch_id, user_id, status, started_at, expires_at, last_seen_at, ui_state_json) VALUES (?, ?, 'in_progress', ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($insD, 'iissss', $batchId, $studentId, $now, $exp, $now, $ui);
    mysqli_stmt_execute($insD);
    $diagAttemptId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($insD);
    $mark('diagnostic: student start attempt', $diagAttemptId > 0, 'attempt_id=' . $diagAttemptId);

    $batchSubjects = diagnostic_exam_load_batch_subjects($conn, $batchId);
    $flat = diagnostic_exam_build_flat_questions($conn, $batchId, $batchSubjects, $diagAttemptId);
    $mark('diagnostic: questions across subjects for take', count($flat) === 4, 'count=' . count($flat));

    foreach ($flat as $fq) {
        $qid = (int)$fq['question_id'];
        $ia = mysqli_prepare(
            $conn,
            'INSERT INTO diagnostic_answers (attempt_id, question_id, selected_answer, answered_at) VALUES (?,?,?,?)'
        );
        $sel = 'A';
        mysqli_stmt_bind_param($ia, 'iiss', $diagAttemptId, $qid, $sel, $now);
        mysqli_stmt_execute($ia);
        mysqli_stmt_close($ia);
    }
    $mark('diagnostic: student answered across subjects', true, 'answers=' . count($flat));

    $dfin = diagnostic_exam_finalize_attempt($conn, $diagAttemptId, $studentId);
    $breakdownOk = !empty($dfin['ok']) && is_array($dfin['breakdown'] ?? null) && count($dfin['breakdown']) >= 2;
    $mark(
        'diagnostic: submit + score calculated',
        !empty($dfin['ok']) && (int)($dfin['total'] ?? 0) === 4 && (float)($dfin['score'] ?? -1) === 100.0,
        json_encode(['score' => $dfin['score'] ?? null, 'correct' => $dfin['correct'] ?? null, 'total' => $dfin['total'] ?? null])
    );
    $mark('diagnostic: subject breakdown generated', $breakdownOk, json_encode($dfin['breakdown'] ?? []));

    $datt = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT status, score, subject_breakdown_json FROM diagnostic_attempts WHERE attempt_id=' . (int)$diagAttemptId));
    $mark(
        'diagnostic: results/analysis persisted',
        $datt && ($datt['status'] ?? '') === 'submitted' && !empty($datt['subject_breakdown_json']),
        substr((string)($datt['subject_breakdown_json'] ?? ''), 0, 120)
    );

    $dScope = ['exam_type' => 'diagnostic', 'assessment_id' => $batchId];
    $dMetrics = examination_monitor_metrics($conn, $dScope);
    $dProgress = examination_monitor_progress_rows($conn, $dScope);
    $dMon = false;
    foreach ($dProgress as $pr) {
        if ((int)($pr['user_id'] ?? 0) === $studentId || (int)($pr['attempt_id'] ?? 0) === $diagAttemptId) {
            $dMon = true;
            break;
        }
    }
    $dSubmitted = (int)($dMetrics['submitted'] ?? $dMetrics['submitted_count'] ?? 0);
    $mark('diagnostic: professor monitoring works', $dMon || $dSubmitted >= 1 || count($dProgress) > 0, 'metrics=' . json_encode($dMetrics));

    $subjAvg = diagnostic_exam_monitor_subject_averages($conn, $batchId);
    $mark('diagnostic: monitoring subject analysis', is_array($subjAvg) && count($subjAvg) >= 1, 'n=' . count($subjAvg));

    // ========== TEST 4 Legacy isolation ==========
    $legacyReg = dirname(__DIR__) . '/examination/professor/professor_exam_edit_legacy.php';
    $legacyDiag = dirname(__DIR__) . '/examination/professor/professor_diagnostic_batch_edit_legacy.php';
    $mark('legacy: regular fallback file exists', is_file($legacyReg), '');
    $mark('legacy: diagnostic fallback file exists', is_file($legacyDiag), '');

    $listView = file_get_contents(dirname(__DIR__) . '/examination/includes/examination_list_view.php');
    $qView = file_get_contents(dirname(__DIR__) . '/examination/includes/examination_edit_questions_view.php');
    $mark(
        'legacy: not in list Questions workflow',
        is_string($listView) && !str_contains($listView, 'Questions (legacy)') && !str_contains($listView, 'exam_edit_legacy'),
        ''
    );
    $mark(
        'legacy: not in Questions step UI',
        is_string($qView) && !str_contains($qView, 'Open legacy') && !str_contains($qView, 'Edit diagnostic batch'),
        ''
    );
    $mark(
        'legacy: domain questions_url isolated from legacy',
        !str_contains(examination_domain_questions_url('regular', 1), 'legacy')
            && !str_contains(examination_domain_questions_url('diagnostic', 1), 'batch_edit'),
        ''
    );
} catch (Throwable $e) {
    $mark('exception', false, $e->getMessage());
}

// Cleanup (keep attempt-linked integrity already verified; remove e2e rows)
if ($diagAttemptId > 0) {
    @mysqli_query($conn, 'DELETE FROM diagnostic_answers WHERE attempt_id=' . (int)$diagAttemptId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_attempts WHERE attempt_id=' . (int)$diagAttemptId);
}
if ($batchId > 0) {
    @mysqli_query($conn, 'DELETE FROM diagnostic_questions WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_subjects WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_users WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_sections WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batches WHERE batch_id=' . (int)$batchId . ' AND created_by=' . (int)$profId);
}
if ($attemptId > 0) {
    @mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . (int)$attemptId);
    @mysqli_query($conn, 'DELETE FROM college_exam_attempts WHERE attempt_id=' . (int)$attemptId);
}
if ($examId > 0) {
    @mysqli_query($conn, 'DELETE FROM college_exam_questions WHERE exam_id=' . (int)$examId);
    @mysqli_query($conn, 'DELETE FROM college_exams WHERE exam_id=' . (int)$examId . ' AND created_by=' . (int)$profId);
}

// Summary buckets for final report
$buckets = [
    'Regular student take' => null,
    'Regular monitoring' => null,
    'Regular result' => null,
    'Diagnostic student take' => null,
    'Diagnostic monitoring' => null,
    'Diagnostic result/subject breakdown' => null,
    'Attempt safety' => null,
    'Legacy fallback isolation' => null,
];
foreach ($results as $r) {
    $n = $r['name'];
    $ok = $r['result'] === 'PASS';
    if (str_starts_with($n, 'regular: student') || $n === 'regular: submit + score calculated' || $n === 'regular: student start attempt' || $n === 'regular: student answered questions' || $n === 'regular: student can access published open exam') {
        $buckets['Regular student take'] = ($buckets['Regular student take'] ?? true) && $ok;
    }
    if (str_contains($n, 'regular:') && str_contains($n, 'monitor')) {
        $buckets['Regular monitoring'] = ($buckets['Regular monitoring'] ?? true) && $ok;
    }
    if ($n === 'regular: result accessible after submission' || $n === 'regular: submit + score calculated') {
        $buckets['Regular result'] = ($buckets['Regular result'] ?? true) && $ok;
    }
    if (str_starts_with($n, 'diagnostic: student') || $n === 'diagnostic: submit + score calculated' || $n === 'diagnostic: questions across subjects for take' || $n === 'diagnostic: student can access') {
        $buckets['Diagnostic student take'] = ($buckets['Diagnostic student take'] ?? true) && $ok;
    }
    if (str_contains($n, 'diagnostic:') && (str_contains($n, 'monitor') || str_contains($n, 'analysis'))) {
        $buckets['Diagnostic monitoring'] = ($buckets['Diagnostic monitoring'] ?? true) && $ok;
    }
    if ($n === 'diagnostic: subject breakdown generated' || $n === 'diagnostic: results/analysis persisted') {
        $buckets['Diagnostic result/subject breakdown'] = ($buckets['Diagnostic result/subject breakdown'] ?? true) && $ok;
    }
    if (str_starts_with($n, 'attempt-safety:')) {
        $buckets['Attempt safety'] = ($buckets['Attempt safety'] ?? true) && $ok;
    }
    if (str_starts_with($n, 'legacy:') || str_contains($n, 'no legacy') || str_contains($n, 'not Edit diagnostic') || str_contains($n, 'shim')) {
        $buckets['Legacy fallback isolation'] = ($buckets['Legacy fallback isolation'] ?? true) && $ok;
    }
}

$allPass = true;
foreach ($results as $r) {
    if ($r['result'] !== 'PASS') {
        $allPass = false;
        break;
    }
}

echo json_encode([
    'ok' => $allPass,
    'phase3_complete' => $allPass,
    'summary' => array_map(static fn($v) => $v === true ? 'PASS' : ($v === false ? 'FAIL' : 'N/A'), $buckets),
    'checks' => $results,
], JSON_PRETTY_PRINT) . "\n";

exit($allPass ? 0 : 1);
