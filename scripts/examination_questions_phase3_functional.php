<?php
declare(strict_types=1);

/**
 * Phase 3 functional tests for Examination Questions workspace.
 * Creates temporary exams/batches, exercises CRUD + publish gates + attempt lock, then cleans up.
 *
 * Usage: php scripts/examination_questions_phase3_functional.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/examination/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/examination/includes/examination_domain.php';
require_once dirname(__DIR__) . '/examination/includes/examination_questions.php';
require_once dirname(__DIR__) . '/includes/quiz_helpers.php';

$checks = [];
$pass = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'pass' => $ok, 'detail' => $detail];
};

$uid = 0;
$pr = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='professor_admin' ORDER BY user_id ASC LIMIT 1");
if ($pr && ($prow = mysqli_fetch_assoc($pr))) {
    $uid = (int)$prow['user_id'];
    mysqli_free_result($pr);
}
$pass('professor available', $uid > 0, 'uid=' . $uid);
if ($uid <= 0) {
    echo json_encode(['ok' => false, 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$createdExamId = 0;
$createdBatchId = 0;
$createdSubjectId = 0;
$createdQids = [];
$diagQids = [];

try {
    // --- Regular: create draft exam ---
    $title = 'Phase3 Functional Regular ' . date('YmdHis');
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO college_exams (title, description, time_limit_seconds, available_from, deadline, is_published, created_by, shuffle_questions, shuffle_choices) VALUES (?,?,3600,NULL,NULL,0,?,0,0)'
    );
    $desc = 'functional test';
    mysqli_stmt_bind_param($ins, 'ssi', $title, $desc, $uid);
    mysqli_stmt_execute($ins);
    $createdExamId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    $pass('regular draft created', $createdExamId > 0, 'exam_id=' . $createdExamId);

    $v0 = examination_questions_validate_for_publish($conn, 'regular', $createdExamId);
    $pass('regular publish blocked with 0 questions', empty($v0['ok']), (string)($v0['error'] ?? ''));

    // Add
    $add = examination_questions_regular_save_one($conn, $createdExamId, $uid, 0, [
        'question_text' => 'What is 2+2?',
        'question_type' => 'mcq',
        'choice_a' => '3',
        'choice_b' => '4',
        'choice_c' => '5',
        'choice_d' => '6',
        'correct_answer' => 'B',
    ]);
    $pass('regular add question', !empty($add['ok']), json_encode($add));
    $qid = (int)($add['question_id'] ?? 0);
    if ($qid > 0) {
        $createdQids[] = $qid;
    }

    $list = examination_questions_load_regular($conn, $createdExamId);
    $pass('regular list questions', count($list) === 1, 'count=' . count($list));

    // Edit (preserve ID)
    $edit = examination_questions_regular_save_one($conn, $createdExamId, $uid, $qid, [
        'question_text' => 'What is 2+2? (edited)',
        'question_type' => 'mcq',
        'choice_a' => '3',
        'choice_b' => '4',
        'choice_c' => '5',
        'choice_d' => '6',
        'correct_answer' => 'B',
    ]);
    $pass('regular edit preserves question_id', !empty($edit['ok']) && (int)($edit['question_id'] ?? 0) === $qid, 'qid=' . $qid);

    // Import append
    $imp = examination_questions_regular_import_append($conn, $createdExamId, $uid, [
        [
            'question_text' => 'True or false: sky is blue',
            'question_type' => 'tf',
            'choice_a' => 'True',
            'choice_b' => 'False',
            'correct_answer' => 'A',
        ],
    ]);
    $pass('regular import append', !empty($imp['ok']) && (int)($imp['imported'] ?? 0) === 1, json_encode($imp));
    $list2 = examination_questions_load_regular($conn, $createdExamId);
    $pass('regular count after import', count($list2) === 2, 'count=' . count($list2));
    foreach ($list2 as $row) {
        $createdQids[] = (int)$row['question_id'];
    }
    $createdQids = array_values(array_unique($createdQids));

    $v1 = examination_questions_validate_for_publish($conn, 'regular', $createdExamId);
    $pass('regular publish allowed with questions', !empty($v1['ok']), '');

    $pub = examination_domain_save_config($conn, 'regular', $uid, [
        'save_action' => 'publish',
        'title' => $title,
        'description' => $desc,
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'available_from' => '',
        'deadline' => '',
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'all',
    ], $createdExamId);
    $pass('regular domain publish succeeds', !empty($pub['ok']) && !empty($pub['is_published']), json_encode($pub));

    // Delete one (still unlocked — no attempts)
    $delQ = $createdQids[count($createdQids) - 1] ?? 0;
    $del = examination_questions_regular_delete_one($conn, $createdExamId, $uid, $delQ);
    $pass('regular delete question', !empty($del['ok']), 'qid=' . $delQ);

    // --- Attempt lock on existing exam with attempts ---
    $attemptExam = 0;
    $ar = @mysqli_query(
        $conn,
        'SELECT a.exam_id, a.attempt_id, an.question_id, an.answer_id
         FROM college_exam_attempts a
         INNER JOIN college_exam_answers an ON an.attempt_id = a.attempt_id
         INNER JOIN college_exams e ON e.exam_id = a.exam_id AND e.created_by = ' . (int)$uid . '
         LIMIT 1'
    );
    $attemptRow = $ar ? mysqli_fetch_assoc($ar) : null;
    if ($ar) {
        mysqli_free_result($ar);
    }
    if ($attemptRow) {
        $attemptExam = (int)$attemptRow['exam_id'];
        $lockedQid = (int)$attemptRow['question_id'];
        $answerId = (int)$attemptRow['answer_id'];
        $pass('attempt fixture found', true, 'exam=' . $attemptExam . ' q=' . $lockedQid);

        $pass('attempt mutations locked', examination_questions_mutations_locked($conn, 'regular', $attemptExam), '');

        $blockedDel = examination_questions_regular_delete_one($conn, $attemptExam, $uid, $lockedQid);
        $pass('attempt blocks delete', empty($blockedDel['ok']), (string)($blockedDel['error'] ?? ''));

        $blockedAdd = examination_questions_regular_save_one($conn, $attemptExam, $uid, 0, [
            'question_text' => 'Should not insert',
            'question_type' => 'mcq',
            'choice_a' => 'A',
            'choice_b' => 'B',
            'choice_c' => 'C',
            'choice_d' => 'D',
            'correct_answer' => 'A',
        ]);
        $pass('attempt blocks add', empty($blockedAdd['ok']), (string)($blockedAdd['error'] ?? ''));

        $qStill = @mysqli_query($conn, 'SELECT question_id FROM college_exam_questions WHERE question_id=' . (int)$lockedQid . ' LIMIT 1');
        $qRow = $qStill ? mysqli_fetch_assoc($qStill) : null;
        if ($qStill) {
            mysqli_free_result($qStill);
        }
        $ansStill = @mysqli_query($conn, 'SELECT answer_id FROM college_exam_answers WHERE answer_id=' . (int)$answerId . ' AND question_id=' . (int)$lockedQid . ' LIMIT 1');
        $aRow = $ansStill ? mysqli_fetch_assoc($ansStill) : null;
        if ($ansStill) {
            mysqli_free_result($ansStill);
        }
        $pass('attempt question_id preserved', !empty($qRow), 'qid=' . $lockedQid);
        $pass('attempt answer relationship preserved', !empty($aRow), 'answer_id=' . $answerId);
    } else {
        $pass('attempt fixture found', false, 'no attempt+answer for professor exams — skipped lock proofs');
    }

    // --- Diagnostic: subject required=20, 18 fail / 20 pass ---
    $catalog = diagnostic_exam_load_subject_catalog($conn);
    $createdSubjectId = (int)(($catalog[0]['subject_id'] ?? 0));
    $pass('diagnostic subject catalog', $createdSubjectId > 0, 'subject_id=' . $createdSubjectId);

    $btitle = 'Phase3 Functional Diagnostic ' . date('YmdHis');
    $bins = mysqli_prepare(
        $conn,
        "INSERT INTO diagnostic_batches (title, description, time_limit_seconds, available_from, deadline, is_published, shuffle_questions, shuffle_choices, examinee_scope, assignment_mode, created_by) VALUES (?,?,3600,NULL,NULL,0,0,0,'college_student','all',?)"
    );
    $bdesc = 'functional test';
    mysqli_stmt_bind_param($bins, 'ssi', $btitle, $bdesc, $uid);
    mysqli_stmt_execute($bins);
    $createdBatchId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($bins);
    $pass('diagnostic draft created', $createdBatchId > 0, 'batch_id=' . $createdBatchId);

    $req = 20;
    $sst = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_subjects (batch_id, subject_id, sort_order, questions_required) VALUES (?,?,1,?)');
    mysqli_stmt_bind_param($sst, 'iii', $createdBatchId, $createdSubjectId, $req);
    mysqli_stmt_execute($sst);
    mysqli_stmt_close($sst);

    for ($i = 1; $i <= 18; $i++) {
        $r = examination_questions_diagnostic_save_one($conn, $createdBatchId, $uid, $createdSubjectId, 0, [
            'question_text' => "Diag Q{$i}?",
            'choice_a' => 'A',
            'choice_b' => 'B',
            'choice_c' => 'C',
            'choice_d' => 'D',
            'correct_answer' => 'A',
        ]);
        if (!empty($r['question_id'])) {
            $diagQids[] = (int)$r['question_id'];
        }
    }
    $pass('diagnostic added 18 questions', count($diagQids) === 18, 'count=' . count($diagQids));

    $supply18 = examination_questions_diagnostic_supply($conn, $createdBatchId);
    $sub18 = $supply18['subjects'][0] ?? null;
    $pass(
        'diagnostic display 18/20 incomplete',
        $sub18 && (int)$sub18['authored'] === 18 && (int)$sub18['required'] === 20 && empty($sub18['ok']),
        json_encode($sub18)
    );

    $v18 = examination_questions_validate_for_publish($conn, 'diagnostic', $createdBatchId);
    $pass('diagnostic 18/20 publish MUST FAIL', empty($v18['ok']), (string)($v18['error'] ?? ''));

    $pub18 = examination_domain_save_config($conn, 'diagnostic', $uid, [
        'save_action' => 'publish',
        'title' => $btitle,
        'description' => $bdesc,
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'all',
        'subject_ids' => [$createdSubjectId],
        'questions_required' => [$createdSubjectId => 20],
        'sections' => [],
        'user_ids' => [],
    ], $createdBatchId);
    $pass('diagnostic domain publish 18/20 MUST FAIL', empty($pub18['ok']), (string)($pub18['error'] ?? ''));

    for ($i = 19; $i <= 20; $i++) {
        $r = examination_questions_diagnostic_save_one($conn, $createdBatchId, $uid, $createdSubjectId, 0, [
            'question_text' => "Diag Q{$i}?",
            'choice_a' => 'A',
            'choice_b' => 'B',
            'choice_c' => 'C',
            'choice_d' => 'D',
            'correct_answer' => 'B',
        ]);
        if (!empty($r['question_id'])) {
            $diagQids[] = (int)$r['question_id'];
        }
    }
    $supply20 = examination_questions_diagnostic_supply($conn, $createdBatchId);
    $sub20 = $supply20['subjects'][0] ?? null;
    $pass(
        'diagnostic display 20/20 complete',
        $sub20 && (int)$sub20['authored'] === 20 && !empty($sub20['ok']),
        json_encode($sub20)
    );

    $v20 = examination_questions_validate_for_publish($conn, 'diagnostic', $createdBatchId);
    $pass('diagnostic 20/20 publish validation OK', !empty($v20['ok']), '');

    $pub20 = examination_domain_save_config($conn, 'diagnostic', $uid, [
        'save_action' => 'publish',
        'title' => $btitle,
        'description' => $bdesc,
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'all',
        'subject_ids' => [$createdSubjectId],
        'questions_required' => [$createdSubjectId => 20],
        'sections' => [],
        'user_ids' => [],
    ], $createdBatchId);
    $pass('diagnostic domain publish 20/20 MUST SUCCEED', !empty($pub20['ok']) && !empty($pub20['is_published']), json_encode($pub20));

    // Subject association check — all questions have correct batch+subject
    $bad = 0;
    $chk = @mysqli_query(
        $conn,
        'SELECT COUNT(*) AS c FROM diagnostic_questions WHERE batch_id=' . (int)$createdBatchId . ' AND subject_id<>' . (int)$createdSubjectId
    );
    if ($chk && ($cr = mysqli_fetch_assoc($chk))) {
        $bad = (int)$cr['c'];
        mysqli_free_result($chk);
    }
    $pass('diagnostic questions stay on correct subject', $bad === 0, 'mismatched=' . $bad);

    // URLs stay in wizard
    $pass(
        'questions_url stays in wizard',
        str_contains(examination_domain_questions_url('diagnostic', $createdBatchId), 'professor_examination_edit')
            && str_contains(examination_domain_questions_url('diagnostic', $createdBatchId), 'step=questions'),
        examination_domain_questions_url('diagnostic', $createdBatchId)
    );

    // Legacy files still present
    $pass(
        'legacy regular file retained',
        is_file(dirname(__DIR__) . '/examination/professor/professor_exam_edit_legacy.php'),
        ''
    );
    $pass(
        'legacy diagnostic file retained',
        is_file(dirname(__DIR__) . '/examination/professor/professor_diagnostic_batch_edit_legacy.php'),
        ''
    );
} catch (Throwable $e) {
    $pass('exception', false, $e->getMessage());
}

// Cleanup temp rows
if ($createdExamId > 0) {
    @mysqli_query($conn, 'DELETE FROM college_exam_questions WHERE exam_id=' . (int)$createdExamId);
    @mysqli_query($conn, 'DELETE FROM college_exams WHERE exam_id=' . (int)$createdExamId . ' AND created_by=' . (int)$uid);
}
if ($createdBatchId > 0) {
    @mysqli_query($conn, 'DELETE FROM diagnostic_questions WHERE batch_id=' . (int)$createdBatchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_subjects WHERE batch_id=' . (int)$createdBatchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_sections WHERE batch_id=' . (int)$createdBatchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_users WHERE batch_id=' . (int)$createdBatchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batches WHERE batch_id=' . (int)$createdBatchId . ' AND created_by=' . (int)$uid);
}

$ok = true;
foreach ($checks as $c) {
    if (empty($c['pass'])) {
        $ok = false;
        break;
    }
}

echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
exit($ok ? 0 : 1);
