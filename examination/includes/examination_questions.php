<?php
declare(strict_types=1);

/**
 * Examination wizard — question operations for Regular + Diagnostic.
 *
 * Architecture (no new tables):
 *   Questions UI → this file → college_exam_questions | diagnostic_questions
 *
 * There were no prior shared CRUD helpers in college_exam_helpers /
 * diagnostic_exam_helpers — question writes lived only as inline SQL inside
 * professor_exam_edit_legacy.php and professor_diagnostic_batch_edit_legacy.php
 * (DELETE-all + INSERT). This module extracts that same row shape and field
 * rules into attempt-safe per-question ops, and reuses existing loaders:
 *   - diagnostic_exam_load_batch / _batch_subjects / _questions_grouped
 *   - sanitizeQuizRichHtmlForStorage (quiz_helpers)
 */

require_once __DIR__ . '/college_exam_helpers.php';
require_once __DIR__ . '/diagnostic_exam_helpers.php';
require_once __DIR__ . '/examination_assignment.php';

if (!function_exists('sanitizeQuizRichHtmlForStorage')) {
    require_once dirname(__DIR__, 2) . '/includes/quiz_helpers.php';
}

function examination_questions_attempt_count(mysqli $conn, string $examType, int $sourceId): int
{
    $examType = examination_normalize_exam_type($examType);
    if ($sourceId <= 0) {
        return 0;
    }
    if ($examType === 'diagnostic') {
        $r = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM diagnostic_attempts WHERE batch_id=' . (int)$sourceId);
    } else {
        $r = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_attempts WHERE exam_id=' . (int)$sourceId);
    }
    if (!$r) {
        return 0;
    }
    $row = mysqli_fetch_assoc($r);
    mysqli_free_result($r);

    return (int)($row['c'] ?? 0);
}

function examination_questions_mutations_locked(mysqli $conn, string $examType, int $sourceId): bool
{
    return examination_questions_attempt_count($conn, $examType, $sourceId) > 0;
}

function examination_questions_load_regular(mysqli $conn, int $examId): array
{
    $out = [];
    if ($examId <= 0) {
        return $out;
    }
    $qr = @mysqli_query(
        $conn,
        'SELECT * FROM college_exam_questions WHERE exam_id=' . (int)$examId . ' ORDER BY sort_order ASC, question_id ASC'
    );
    if ($qr) {
        while ($q = mysqli_fetch_assoc($qr)) {
            $out[] = $q;
        }
        mysqli_free_result($qr);
    }

    return $out;
}

/**
 * Decode optional E+ choices from diagnostic_questions.extra_choices_json.
 *
 * @return array<string,string> letter => text (E, F, …)
 */
function examination_questions_diagnostic_extra_choices_decode(?string $json): array
{
    return diagnostic_exam_extra_choices_decode($json);
}

/**
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,json?:?string,map?:array<string,string>}
 */
function examination_questions_diagnostic_extra_choices_normalize(array $data): array
{
    $raw = $data['extra_choices'] ?? ($data['extra_choices_json'] ?? null);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    $map = [];
    foreach ($raw as $k => $v) {
        $L = strtoupper(trim((string)$k));
        if (!preg_match('/^[E-Z]$/', $L)) {
            continue;
        }
        $text = trim((string)$v);
        if ($text === '') {
            continue;
        }
        $map[$L] = $text;
    }
    // Contiguous from E — no gaps (E then G without F)
    $expected = 'E';
    foreach ($map as $L => $_t) {
        if ($L !== $expected) {
            return ['ok' => false, 'error' => 'Extra choices must be contiguous starting at E (no gaps).'];
        }
        $expected = chr(ord($expected) + 1);
        if ($expected > 'Z') {
            break;
        }
    }
    if ($map === []) {
        return ['ok' => true, 'json' => null, 'map' => []];
    }
    ksort($map);

    return ['ok' => true, 'json' => json_encode($map, JSON_UNESCAPED_UNICODE), 'map' => $map];
}

/**
 * @return array{authored:int,required:int,ok:bool,subjects:list<array>}
 */
function examination_questions_diagnostic_supply(mysqli $conn, int $batchId): array
{
    $batchSubjects = diagnostic_exam_load_batch_subjects($conn, $batchId);
    $grouped = diagnostic_exam_load_questions_grouped($conn, $batchId);
    $subjects = [];
    $allOk = true;
    $totalAuthored = 0;
    $totalRequired = 0;

    foreach ($batchSubjects as $bs) {
        $sid = (int)($bs['subject_id'] ?? 0);
        $required = max(0, (int)($bs['questions_required'] ?? 0));
        $authored = count($grouped[$sid] ?? []);
        // required 0 means "use all authored" — OK if at least 1 authored
        if ($required === 0) {
            $ok = $authored >= 1;
            $displayRequired = $authored > 0 ? $authored : 1;
        } else {
            $ok = $authored >= $required;
            $displayRequired = $required;
        }
        if (!$ok) {
            $allOk = false;
        }
        $totalAuthored += $authored;
        $totalRequired += $displayRequired;
        $subjects[] = [
            'subject_id' => $sid,
            'subject_code' => (string)($bs['subject_code'] ?? ''),
            'subject_name' => (string)($bs['subject_name'] ?? ''),
            'authored' => $authored,
            'required' => $required,
            'display_required' => $displayRequired,
            'ok' => $ok,
            'questions' => $grouped[$sid] ?? [],
        ];
    }

    if ($batchSubjects === []) {
        $allOk = false;
    }

    return [
        'authored' => $totalAuthored,
        'required' => $totalRequired,
        'ok' => $allOk && $totalAuthored > 0,
        'subjects' => $subjects,
    ];
}

/**
 * @return array{ok:bool,error?:string,details?:list<string>}
 */
function examination_questions_validate_for_publish(mysqli $conn, string $examType, int $sourceId): array
{
    $examType = examination_normalize_exam_type($examType);
    if ($sourceId <= 0) {
        return ['ok' => false, 'error' => 'Save the examination configuration before publishing.'];
    }

    if ($examType === 'diagnostic') {
        $supply = examination_questions_diagnostic_supply($conn, $sourceId);
        if ($supply['subjects'] === []) {
            return ['ok' => false, 'error' => 'Configure at least one subject before publishing.'];
        }
        $details = [];
        foreach ($supply['subjects'] as $s) {
            if (!$s['ok']) {
                $need = (int)$s['required'] > 0 ? (int)$s['required'] : 1;
                $details[] = sprintf(
                    '%s requires %d question(s), but only %d have been added.',
                    $s['subject_code'] !== '' ? $s['subject_code'] : ('Subject #' . $s['subject_id']),
                    $need,
                    (int)$s['authored']
                );
            }
        }
        if ($details !== []) {
            return [
                'ok' => false,
                'error' => $details[0],
                'details' => $details,
            ];
        }

        return ['ok' => true];
    }

    require_once __DIR__ . '/college_exam_subject_topic_helpers.php';
    $topicSupply = college_exam_topic_supply($conn, $sourceId);
    if (!empty($topicSupply['configured'])) {
        if (empty($topicSupply['ok'])) {
            $details = $topicSupply['errors'] ?? [];

            return [
                'ok' => false,
                'error' => $details[0] ?? 'Question supply is insufficient for the configured breakdown.',
                'details' => $details,
            ];
        }
        if ((int)($topicSupply['total_required'] ?? 0) < 1) {
            $mode = (string)($topicSupply['mode'] ?? 'overall');
            if ($mode === 'overall') {
                return ['ok' => false, 'error' => 'Set a total number of questions before publishing.'];
            }

            return ['ok' => false, 'error' => 'Configure at least one subject with a positive question count before publishing.'];
        }

        return ['ok' => true];
    }

    $count = count(examination_questions_load_regular($conn, $sourceId));
    if ($count < 1) {
        return ['ok' => false, 'error' => 'Add at least one question before publishing.'];
    }

    return ['ok' => true];
}

/**
 * @param array{question_text?:string,question_type?:string,choice_a?:string,choice_b?:string,choice_c?:string,choice_d?:string,correct_answer?:string} $data
 * @return array{ok:bool,error?:string,question_id?:int}
 */
function examination_questions_normalize_regular_row(array $data, bool $strict): array
{
    $qt = sanitizeQuizRichHtmlForStorage(trim((string)($data['question_text'] ?? '')));
    if ($qt === '') {
        return ['ok' => false, 'error' => 'Question text is required.'];
    }
    $type = strtolower(trim((string)($data['question_type'] ?? 'mcq')));
    if ($type !== 'tf') {
        $type = 'mcq';
    }
    $a = trim((string)($data['choice_a'] ?? ''));
    $b = trim((string)($data['choice_b'] ?? ''));
    $c = trim((string)($data['choice_c'] ?? ''));
    $d = trim((string)($data['choice_d'] ?? ''));
    $ok = strtoupper(trim((string)($data['correct_answer'] ?? '')));
    if ($type === 'tf') {
        // Reject stale/extra C–D before normalizing storage to True/False + A/B.
        if ($strict && ($c !== '' || $d !== '')) {
            return [
                'ok' => false,
                'error' => 'True or False questions may only contain True and False choices. Leave choice C and D blank.',
            ];
        }
        $a = 'True';
        $b = 'False';
        $c = '';
        $d = '';
        if ($ok !== 'A' && $ok !== 'B') {
            if ($strict) {
                return ['ok' => false, 'error' => 'Select True or False as the correct answer.'];
            }
            $ok = '';
        }
    } else {
        if ($strict) {
            if ($a === '' || $b === '') {
                return ['ok' => false, 'error' => 'Multiple Choice questions require at least two choices (A and B).'];
            }
            // Compact trailing empty slots (C/D may be unused); do not allow gap after filled.
            if ($c === '' && $d !== '') {
                return ['ok' => false, 'error' => 'Choice D cannot be filled while Choice C is empty.'];
            }
            if ($ok === '') {
                return ['ok' => false, 'error' => 'Please select the correct answer.'];
            }
            if (!preg_match('/^[A-D]$/', $ok)) {
                return ['ok' => false, 'error' => 'Select the correct choice (A–D).'];
            }
            $map = ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d];
            if (trim((string)($map[$ok] ?? '')) === '') {
                return ['ok' => false, 'error' => 'Correct answer must match one of the available choices.'];
            }
        } elseif (!preg_match('/^[A-D]$/', $ok)) {
            $ok = '';
        }
    }

    return [
        'ok' => true,
        'row' => [
            'question_type' => $type,
            'question_text' => $qt,
            'choice_a' => $a,
            'choice_b' => $b,
            'choice_c' => $c,
            'choice_d' => $d,
            'correct_answer' => $ok,
        ],
    ];
}

/**
 * @return array{ok:bool,error?:string,question_id?:int}
 */
function examination_questions_regular_save_one(mysqli $conn, int $examId, int $professorId, int $questionId, array $data): array
{
    if ($examId <= 0 || examination_questions_mutations_locked($conn, 'regular', $examId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $own = @mysqli_query($conn, 'SELECT exam_id FROM college_exams WHERE exam_id=' . (int)$examId . ' AND created_by=' . (int)$professorId . ' LIMIT 1');
    if (!$own || !mysqli_fetch_assoc($own)) {
        if ($own) {
            mysqli_free_result($own);
        }

        return ['ok' => false, 'error' => 'Examination not found.'];
    }
    mysqli_free_result($own);

    $norm = examination_questions_normalize_regular_row($data, true);
    if (empty($norm['ok'])) {
        return ['ok' => false, 'error' => (string)($norm['error'] ?? 'Invalid question.')];
    }
    $row = $norm['row'];

    require_once __DIR__ . '/college_exam_subject_topic_helpers.php';
    $mode = college_exam_get_breakdown_mode($conn, $examId);
    $topicId = max(0, (int)($data['exam_topic_id'] ?? 0));
    $subjectId = max(0, (int)($data['exam_subject_id'] ?? 0));
    $hasTopicCol = college_exam_questions_has_topic_column($conn);
    $hasSubjCol = college_exam_questions_has_subject_column($conn);

    if ($mode === 'subject' || $mode === 'subject_topic') {
        if ($subjectId <= 0 && $topicId > 0 && $hasTopicCol) {
            $chkMap = mysqli_prepare(
                $conn,
                'SELECT exam_subject_id FROM college_exam_topics WHERE exam_topic_id=? AND exam_id=? LIMIT 1'
            );
            mysqli_stmt_bind_param($chkMap, 'ii', $topicId, $examId);
            mysqli_stmt_execute($chkMap);
            $mapRow = mysqli_fetch_assoc(mysqli_stmt_get_result($chkMap));
            mysqli_stmt_close($chkMap);
            $subjectId = (int)($mapRow['exam_subject_id'] ?? 0);
        }
        if ($subjectId <= 0) {
            return ['ok' => false, 'error' => 'Select a subject for this question.'];
        }
        $chkS = mysqli_prepare(
            $conn,
            'SELECT exam_subject_id FROM college_exam_subjects WHERE exam_subject_id=? AND exam_id=? LIMIT 1'
        );
        mysqli_stmt_bind_param($chkS, 'ii', $subjectId, $examId);
        mysqli_stmt_execute($chkS);
        $sOk = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($chkS));
        mysqli_stmt_close($chkS);
        if (!$sOk) {
            return ['ok' => false, 'error' => 'Selected subject does not belong to this examination.'];
        }
        if ($mode === 'subject') {
            $topicId = 0;
        } elseif ($topicId > 0) {
            $chkT = mysqli_prepare(
                $conn,
                'SELECT exam_topic_id FROM college_exam_topics WHERE exam_topic_id=? AND exam_id=? AND exam_subject_id=? LIMIT 1'
            );
            mysqli_stmt_bind_param($chkT, 'iii', $topicId, $examId, $subjectId);
            mysqli_stmt_execute($chkT);
            $tOk = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($chkT));
            mysqli_stmt_close($chkT);
            if (!$tOk) {
                return ['ok' => false, 'error' => 'Selected topic does not belong to this subject.'];
            }
        } else {
            // Topic required when this subject has configured topics.
            $treeChk = college_exam_load_subjects_with_topics($conn, $examId);
            foreach ($treeChk as $sNode) {
                if ((int)$sNode['exam_subject_id'] === $subjectId && !empty($sNode['topics'])) {
                    return ['ok' => false, 'error' => 'Select a topic for this subject.'];
                }
            }
        }

        $cap = college_exam_check_question_capacity($conn, $examId, $subjectId, $topicId, $questionId);
        if (empty($cap['ok'])) {
            return ['ok' => false, 'error' => (string)($cap['error'] ?? 'This subject/topic already has enough questions.')];
        }
    } else {
        $topicId = 0;
        $subjectId = 0;
        if ($mode === 'overall') {
            $cap = college_exam_check_question_capacity($conn, $examId, 0, 0, $questionId);
            if (empty($cap['ok'])) {
                return ['ok' => false, 'error' => (string)($cap['error'] ?? 'Overall question target is already complete.')];
            }
        }
    }

    if ($questionId > 0) {
        if ($hasSubjCol && $hasTopicCol && $subjectId > 0 && $topicId > 0) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE college_exam_questions SET question_type=?, question_text=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=?, exam_subject_id=?, exam_topic_id=? WHERE question_id=? AND exam_id=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'sssssssiiii',
                $row['question_type'],
                $row['question_text'],
                $row['choice_a'],
                $row['choice_b'],
                $row['choice_c'],
                $row['choice_d'],
                $row['correct_answer'],
                $subjectId,
                $topicId,
                $questionId,
                $examId
            );
        } elseif ($hasSubjCol && $subjectId > 0) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE college_exam_questions SET question_type=?, question_text=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=?, exam_subject_id=?, exam_topic_id=NULL WHERE question_id=? AND exam_id=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'sssssssiii',
                $row['question_type'],
                $row['question_text'],
                $row['choice_a'],
                $row['choice_b'],
                $row['choice_c'],
                $row['choice_d'],
                $row['correct_answer'],
                $subjectId,
                $questionId,
                $examId
            );
        } elseif ($hasSubjCol) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE college_exam_questions SET question_type=?, question_text=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=?, exam_subject_id=NULL, exam_topic_id=NULL WHERE question_id=? AND exam_id=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'sssssssii',
                $row['question_type'],
                $row['question_text'],
                $row['choice_a'],
                $row['choice_b'],
                $row['choice_c'],
                $row['choice_d'],
                $row['correct_answer'],
                $questionId,
                $examId
            );
        } elseif ($hasTopicCol && $topicId > 0) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE college_exam_questions SET question_type=?, question_text=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=?, exam_topic_id=? WHERE question_id=? AND exam_id=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'sssssssiii',
                $row['question_type'],
                $row['question_text'],
                $row['choice_a'],
                $row['choice_b'],
                $row['choice_c'],
                $row['choice_d'],
                $row['correct_answer'],
                $topicId,
                $questionId,
                $examId
            );
        } elseif ($hasTopicCol) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE college_exam_questions SET question_type=?, question_text=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=?, exam_topic_id=NULL WHERE question_id=? AND exam_id=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'sssssssii',
                $row['question_type'],
                $row['question_text'],
                $row['choice_a'],
                $row['choice_b'],
                $row['choice_c'],
                $row['choice_d'],
                $row['correct_answer'],
                $questionId,
                $examId
            );
        } else {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE college_exam_questions SET question_type=?, question_text=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=? WHERE question_id=? AND exam_id=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'sssssssii',
                $row['question_type'],
                $row['question_text'],
                $row['choice_a'],
                $row['choice_b'],
                $row['choice_c'],
                $row['choice_d'],
                $row['correct_answer'],
                $questionId,
                $examId
            );
        }
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        return ['ok' => true, 'question_id' => $questionId];
    }

    $sort = 0;
    $sr = @mysqli_query($conn, 'SELECT COALESCE(MAX(sort_order), -1) AS m FROM college_exam_questions WHERE exam_id=' . (int)$examId);
    if ($sr && ($sm = mysqli_fetch_assoc($sr))) {
        $sort = (int)($sm['m'] ?? -1) + 1;
        mysqli_free_result($sr);
    }
    if ($hasSubjCol && $hasTopicCol && $subjectId > 0 && $topicId > 0) {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO college_exam_questions (exam_id, exam_subject_id, exam_topic_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param(
            $ins,
            'iiisssssssi',
            $examId,
            $subjectId,
            $topicId,
            $row['question_type'],
            $row['question_text'],
            $row['choice_a'],
            $row['choice_b'],
            $row['choice_c'],
            $row['choice_d'],
            $row['correct_answer'],
            $sort
        );
    } elseif ($hasSubjCol && $subjectId > 0) {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO college_exam_questions (exam_id, exam_subject_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param(
            $ins,
            'iisssssssi',
            $examId,
            $subjectId,
            $row['question_type'],
            $row['question_text'],
            $row['choice_a'],
            $row['choice_b'],
            $row['choice_c'],
            $row['choice_d'],
            $row['correct_answer'],
            $sort
        );
    } elseif ($hasTopicCol && $topicId > 0) {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO college_exam_questions (exam_id, exam_topic_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param(
            $ins,
            'iisssssssi',
            $examId,
            $topicId,
            $row['question_type'],
            $row['question_text'],
            $row['choice_a'],
            $row['choice_b'],
            $row['choice_c'],
            $row['choice_d'],
            $row['correct_answer'],
            $sort
        );
    } else {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO college_exam_questions (exam_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param(
            $ins,
            'isssssssi',
            $examId,
            $row['question_type'],
            $row['question_text'],
            $row['choice_a'],
            $row['choice_b'],
            $row['choice_c'],
            $row['choice_d'],
            $row['correct_answer'],
            $sort
        );
    }
    mysqli_stmt_execute($ins);
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    return ['ok' => true, 'question_id' => $newId];
}

/**
 * @return array{ok:bool,error?:string}
 */
function examination_questions_regular_delete_one(mysqli $conn, int $examId, int $professorId, int $questionId): array
{
    if ($examId <= 0 || $questionId <= 0 || examination_questions_mutations_locked($conn, 'regular', $examId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $own = @mysqli_query($conn, 'SELECT exam_id FROM college_exams WHERE exam_id=' . (int)$examId . ' AND created_by=' . (int)$professorId . ' LIMIT 1');
    if (!$own || !mysqli_fetch_assoc($own)) {
        if ($own) {
            mysqli_free_result($own);
        }

        return ['ok' => false, 'error' => 'Examination not found.'];
    }
    mysqli_free_result($own);
    @mysqli_query($conn, 'DELETE FROM college_exam_questions WHERE question_id=' . (int)$questionId . ' AND exam_id=' . (int)$examId);

    return ['ok' => true];
}

/**
 * Validate one import row (regular or diagnostic). Friendly professor messages.
 *
 * @param array{question_text?:string,question_type?:string,choice_a?:string,choice_b?:string,choice_c?:string,choice_d?:string,correct_answer?:string} $data
 * @return array{ok:bool,error?:string,row?:array}
 */
function examination_questions_validate_import_row(array $data, string $examType): array
{
    $examType = examination_normalize_exam_type($examType) ?: 'regular';
    $type = strtolower(trim((string)($data['question_type'] ?? 'mcq')));
    if ($type !== 'tf') {
        $type = 'mcq';
    }
    if ($examType === 'diagnostic' && $type === 'tf') {
        return [
            'ok' => false,
            'error' => 'Diagnostic examinations currently support Multiple Choice questions only.',
        ];
    }
    $data['question_type'] = $type;

    if ($examType === 'diagnostic') {
        $qt = sanitizeQuizRichHtmlForStorage(trim((string)($data['question_text'] ?? '')));
        if ($qt === '') {
            return ['ok' => false, 'error' => 'Question text is required.'];
        }
        $a = trim((string)($data['choice_a'] ?? ''));
        $b = trim((string)($data['choice_b'] ?? ''));
        $c = trim((string)($data['choice_c'] ?? ''));
        $d = trim((string)($data['choice_d'] ?? ''));
        $cor = strtoupper(trim((string)($data['correct_answer'] ?? '')));
        if ($a === '' || $b === '') {
            return ['ok' => false, 'error' => 'Multiple Choice questions require at least two choices (A and B).'];
        }
        if ($c === '' && $d !== '') {
            return ['ok' => false, 'error' => 'Choice D cannot be filled while Choice C is empty.'];
        }
        if ($cor === '' || !preg_match('/^[A-D]$/', $cor)) {
            return ['ok' => false, 'error' => 'Please select the correct answer.'];
        }
        $map = ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d];
        if (trim((string)($map[$cor] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Correct answer must match one of the available choices.'];
        }

        return [
            'ok' => true,
            'row' => [
                'question_type' => 'mcq',
                'question_text' => $qt,
                'choice_a' => $a,
                'choice_b' => $b,
                'choice_c' => $c,
                'choice_d' => $d,
                'correct_answer' => $cor,
            ],
        ];
    }

    return examination_questions_validate_import_row_regular_with_meta($data);
}

/**
 * Preserve optional subject/topic names from import rows after normalize.
 *
 * @param array{question_text?:string,question_type?:string,choice_a?:string,choice_b?:string,choice_c?:string,choice_d?:string,correct_answer?:string,subject_name?:string,topic_name?:string} $data
 * @return array{ok:bool,error?:string,row?:array}
 */
function examination_questions_validate_import_row_regular_with_meta(array $data): array
{
    $res = examination_questions_normalize_regular_row($data, true);
    if (empty($res['ok']) || !isset($res['row']) || !is_array($res['row'])) {
        return $res;
    }
    $res['row']['subject_name'] = trim((string)($data['subject_name'] ?? ''));
    $res['row']['topic_name'] = trim((string)($data['topic_name'] ?? ''));

    return $res;
}

/**
 * Validate every import row. No database writes. Fail closed if any row is invalid.
 *
 * @param list<array> $rows
 * @return array{ok:bool,detected:int,valid:int,invalid:int,errors:list<array{row:int,message:string}>,rows:list<array>,error?:string}
 */
function examination_questions_validate_import_all(array $rows, string $examType): array
{
    $errors = [];
    $normalized = [];
    $detected = 0;
    foreach ($rows as $idx => $data) {
        if (!is_array($data)) {
            $errors[] = ['row' => $idx + 1, 'message' => 'Invalid question row.'];
            continue;
        }
        $detected++;
        $sourceRow = (int)($data['_source_row'] ?? ($idx + 1));
        $res = examination_questions_validate_import_row($data, $examType);
        if (empty($res['ok'])) {
            $errors[] = [
                'row' => $sourceRow > 0 ? $sourceRow : ($idx + 1),
                'message' => (string)($res['error'] ?? 'Invalid question.'),
            ];
            continue;
        }
        $normalized[] = $res['row'];
    }

    $invalid = count($errors);
    $valid = count($normalized);
    if ($detected === 0) {
        return [
            'ok' => false,
            'detected' => 0,
            'valid' => 0,
            'invalid' => 0,
            'errors' => [['row' => 0, 'message' => 'No questions found to import.']],
            'rows' => [],
            'error' => 'No questions found to import.',
        ];
    }
    if ($invalid > 0) {
        return [
            'ok' => false,
            'detected' => $detected,
            'valid' => $valid,
            'invalid' => $invalid,
            'errors' => $errors,
            'rows' => [],
            'error' => 'Some questions contain errors. Please fix them before importing.',
        ];
    }

    return [
        'ok' => true,
        'detected' => $detected,
        'valid' => $valid,
        'invalid' => 0,
        'errors' => [],
        'rows' => $normalized,
    ];
}

/**
 * Append imported questions atomically. Validates ALL rows first; never partial-inserts.
 *
 * @param list<array> $rows
 * @return array{ok:bool,error?:string,imported?:int,detected?:int,valid?:int,invalid?:int,errors?:list<array{row:int,message:string}>}
 */
function examination_questions_regular_import_append(mysqli $conn, int $examId, int $professorId, array $rows): array
{
    if ($examId <= 0 || examination_questions_mutations_locked($conn, 'regular', $examId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $own = @mysqli_query($conn, 'SELECT exam_id FROM college_exams WHERE exam_id=' . (int)$examId . ' AND created_by=' . (int)$professorId . ' LIMIT 1');
    if (!$own || !mysqli_fetch_assoc($own)) {
        if ($own) {
            mysqli_free_result($own);
        }

        return ['ok' => false, 'error' => 'Examination not found.'];
    }
    mysqli_free_result($own);

    $batch = examination_questions_validate_import_all($rows, 'regular');
    if (empty($batch['ok'])) {
        return $batch;
    }

    require_once __DIR__ . '/college_exam_subject_topic_helpers.php';
    $mode = college_exam_get_breakdown_mode($conn, $examId);
    $tree = college_exam_load_subjects_with_topics($conn, $examId);
    $subjByName = [];
    $topicBySubjName = [];
    foreach ($tree as $s) {
        $sk = mb_strtolower(trim((string)$s['subject_name']));
        $subjByName[$sk] = $s;
        $topicBySubjName[$sk] = [];
        foreach ($s['topics'] as $t) {
            $topicBySubjName[$sk][mb_strtolower(trim((string)$t['topic_name']))] = $t;
        }
    }

    $resolved = [];
    $errors = [];
    $plannedSubject = [];
    $plannedTopic = [];
    $plannedOverall = 0;
    $coveragePreview = [];

    foreach ($batch['rows'] as $idx => $row) {
        $sourceRow = $idx + 1;
        $sid = 0;
        $tid = 0;
        if ($mode === 'subject' || $mode === 'subject_topic') {
            $sName = trim((string)($row['subject_name'] ?? ''));
            if ($sName === '') {
                $errors[] = ['row' => $sourceRow, 'message' => 'Subject is required for this exam blueprint.'];
                continue;
            }
            $sk = mb_strtolower($sName);
            if (!isset($subjByName[$sk])) {
                $errors[] = ['row' => $sourceRow, 'message' => 'Unknown subject "' . $sName . '". Use a subject configured for this exam.'];
                continue;
            }
            $sid = (int)$subjByName[$sk]['exam_subject_id'];
            $hasTopics = !empty($subjByName[$sk]['topics']);
            $tName = trim((string)($row['topic_name'] ?? ''));
            if ($mode === 'subject_topic' && $hasTopics) {
                if ($tName === '') {
                    $errors[] = ['row' => $sourceRow, 'message' => 'Topic is required for subject "' . $sName . '".'];
                    continue;
                }
                $tk = mb_strtolower($tName);
                if (!isset($topicBySubjName[$sk][$tk])) {
                    $errors[] = ['row' => $sourceRow, 'message' => 'Unknown topic "' . $tName . '" under subject "' . $sName . '".'];
                    continue;
                }
                $tid = (int)$topicBySubjName[$sk][$tk]['exam_topic_id'];
            } elseif ($tName !== '' && $mode === 'subject_topic') {
                $errors[] = ['row' => $sourceRow, 'message' => 'Subject "' . $sName . '" has no topics configured; leave Topic blank.'];
                continue;
            }
        }

        // Projected capacity including earlier rows in this batch.
        if ($mode === 'overall') {
            $req = college_exam_get_total_questions_required($conn, $examId);
            $existing = 0;
            $qr = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . (int)$examId);
            if ($qr && ($r = mysqli_fetch_assoc($qr))) {
                $existing = (int)($r['c'] ?? 0);
            }
            if ($qr) {
                mysqli_free_result($qr);
            }
            if ($req > 0 && ($existing + $plannedOverall + 1) > $req) {
                $errors[] = ['row' => $sourceRow, 'message' => sprintf('Overall target is %d; import would exceed capacity.', $req)];
                continue;
            }
            $plannedOverall++;
        } elseif ($tid > 0) {
            $cap = college_exam_check_question_capacity($conn, $examId, $sid, $tid, 0);
            $authored = (int)($cap['authored'] ?? 0);
            $req = (int)($cap['required'] ?? 0);
            $alreadyPlanned = (int)($plannedTopic[$tid] ?? 0);
            if ($req > 0 && ($authored + $alreadyPlanned + 1) > $req) {
                $errors[] = ['row' => $sourceRow, 'message' => sprintf(
                    '%s capacity exceeded (%d / %d).',
                    (string)($cap['label'] ?? 'Topic'),
                    $authored + $alreadyPlanned,
                    $req
                )];
                continue;
            }
            $plannedTopic[$tid] = $alreadyPlanned + 1;
        } elseif ($sid > 0) {
            $cap = college_exam_check_question_capacity($conn, $examId, $sid, 0, 0);
            $authored = (int)($cap['authored'] ?? 0);
            $req = (int)($cap['required'] ?? 0);
            $alreadyPlanned = (int)($plannedSubject[$sid] ?? 0);
            if ($req > 0 && ($authored + $alreadyPlanned + 1) > $req) {
                $errors[] = ['row' => $sourceRow, 'message' => sprintf(
                    '%s capacity exceeded (%d / %d).',
                    (string)($cap['label'] ?? 'Subject'),
                    $authored + $alreadyPlanned,
                    $req
                )];
                continue;
            }
            $plannedSubject[$sid] = $alreadyPlanned + 1;
        }

        $row['exam_subject_id'] = $sid;
        $row['exam_topic_id'] = $tid;
        $resolved[] = $row;
    }

    if ($errors !== []) {
        $previewLines = [];
        $supply = college_exam_topic_supply($conn, $examId);
        if (!empty($supply['configured'])) {
            if (($supply['mode'] ?? '') === 'overall') {
                $previewLines[] = sprintf(
                    'Overall: %d required, %d already authored — %s',
                    (int)$supply['total_required'],
                    (int)($supply['total_authored'] ?? 0),
                    !empty($supply['ok']) ? 'COMPLETE' : ((int)($supply['remaining'] ?? 0) . ' MISSING')
                );
            }
            foreach (($supply['subjects'] ?? []) as $ss) {
                $previewLines[] = sprintf(
                    '%s: %d required, %d authored — %s',
                    (string)$ss['subject_name'],
                    (int)$ss['questions_required'],
                    (int)$ss['authored'],
                    !empty($ss['ok']) ? 'COMPLETE' : ((int)($ss['remaining'] ?? 0) . ' MISSING')
                );
                foreach (($ss['topics'] ?? []) as $tt) {
                    $previewLines[] = sprintf(
                        '  %s / %s: %d required, %d authored — %s',
                        (string)$ss['subject_name'],
                        (string)$tt['topic_name'],
                        (int)$tt['required'],
                        (int)$tt['authored'],
                        !empty($tt['ok']) ? 'COMPLETE' : ((int)($tt['remaining'] ?? 0) . ' MISSING')
                    );
                }
            }
        }

        return [
            'ok' => false,
            'detected' => (int)$batch['detected'],
            'valid' => 0,
            'invalid' => count($errors),
            'errors' => $errors,
            'coverage_preview' => $previewLines,
            'error' => 'Import failed validation against the exam blueprint. No questions were imported.',
        ];
    }

    $sort = 0;
    $sr = @mysqli_query($conn, 'SELECT COALESCE(MAX(sort_order), -1) AS m FROM college_exam_questions WHERE exam_id=' . (int)$examId);
    if ($sr && ($sm = mysqli_fetch_assoc($sr))) {
        $sort = (int)($sm['m'] ?? -1) + 1;
        mysqli_free_result($sr);
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Could not start import. Please try again.'];
    }

    $imported = 0;
    $hasSubjCol = college_exam_questions_has_subject_column($conn);
    $hasTopicCol = college_exam_questions_has_topic_column($conn);
    try {
        foreach ($resolved as $row) {
            $sid = (int)($row['exam_subject_id'] ?? 0);
            $tid = (int)($row['exam_topic_id'] ?? 0);
            $qtype = (string)$row['question_type'];
            $qtext = (string)$row['question_text'];
            $a = (string)$row['choice_a'];
            $b = (string)$row['choice_b'];
            $c = (string)$row['choice_c'];
            $d = (string)$row['choice_d'];
            $cor = (string)$row['correct_answer'];
            if ($hasSubjCol && $hasTopicCol && $sid > 0 && $tid > 0) {
                $ins = mysqli_prepare(
                    $conn,
                    'INSERT INTO college_exam_questions (exam_id, exam_subject_id, exam_topic_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                mysqli_stmt_bind_param($ins, 'iiisssssssi', $examId, $sid, $tid, $qtype, $qtext, $a, $b, $c, $d, $cor, $sort);
            } elseif ($hasSubjCol && $sid > 0) {
                $ins = mysqli_prepare(
                    $conn,
                    'INSERT INTO college_exam_questions (exam_id, exam_subject_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                mysqli_stmt_bind_param($ins, 'iisssssssi', $examId, $sid, $qtype, $qtext, $a, $b, $c, $d, $cor, $sort);
            } else {
                $ins = mysqli_prepare(
                    $conn,
                    'INSERT INTO college_exam_questions (exam_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?)'
                );
                mysqli_stmt_bind_param($ins, 'isssssssi', $examId, $qtype, $qtext, $a, $b, $c, $d, $cor, $sort);
            }
            if (!$ins || !mysqli_stmt_execute($ins)) {
                if ($ins) {
                    mysqli_stmt_close($ins);
                }
                throw new RuntimeException('insert_failed');
            }
            mysqli_stmt_close($ins);
            $sort++;
            $imported++;
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('commit_failed');
        }
    } catch (Throwable $e) {
        @mysqli_rollback($conn);
        error_log('[examination_questions_regular_import] ' . $e->getMessage() . ' exam_id=' . $examId);

        return ['ok' => false, 'error' => 'Import failed and no questions were added. Please try again.'];
    }

    $supplyAfter = college_exam_topic_supply($conn, $examId);
    foreach (($supplyAfter['subjects'] ?? []) as $ss) {
        $coveragePreview[] = sprintf(
            '%s: %d required, %d supplied — %s',
            (string)$ss['subject_name'],
            (int)$ss['questions_required'],
            (int)$ss['authored'],
            !empty($ss['ok']) ? 'COMPLETE' : ((int)($ss['remaining'] ?? 0) . ' MISSING')
        );
        foreach (($ss['topics'] ?? []) as $tt) {
            $coveragePreview[] = sprintf(
                '%s / %s: %d required, %d supplied — %s',
                (string)$ss['subject_name'],
                (string)$tt['topic_name'],
                (int)$tt['required'],
                (int)$tt['authored'],
                !empty($tt['ok']) ? 'COMPLETE' : ((int)($tt['remaining'] ?? 0) . ' MISSING')
            );
        }
    }
    if (($supplyAfter['mode'] ?? '') === 'overall' && !empty($supplyAfter['configured'])) {
        $coveragePreview[] = sprintf(
            'Overall: %d required, %d supplied — %s',
            (int)$supplyAfter['total_required'],
            (int)($supplyAfter['total_authored'] ?? 0),
            !empty($supplyAfter['ok']) ? 'COMPLETE' : ((int)($supplyAfter['remaining'] ?? 0) . ' MISSING')
        );
    }

    return [
        'ok' => true,
        'imported' => $imported,
        'detected' => (int)$batch['detected'],
        'valid' => $imported,
        'invalid' => 0,
        'errors' => [],
        'coverage_preview' => $coveragePreview,
    ];
}

/**
 * @param array{question_text?:string,choice_a?:string,choice_b?:string,choice_c?:string,choice_d?:string,correct_answer?:string} $data
 * @return array{ok:bool,error?:string,question_id?:int}
 */
function examination_questions_diagnostic_save_one(mysqli $conn, int $batchId, int $professorId, int $subjectId, int $questionId, array $data): array
{
    if ($batchId <= 0 || $subjectId <= 0 || examination_questions_mutations_locked($conn, 'diagnostic', $batchId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $batch = diagnostic_exam_load_batch($conn, $batchId, $professorId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Examination not found.'];
    }
    $onBatch = false;
    foreach (diagnostic_exam_load_batch_subjects($conn, $batchId) as $bs) {
        if ((int)($bs['subject_id'] ?? 0) === $subjectId) {
            $onBatch = true;
            break;
        }
    }
    if (!$onBatch) {
        return ['ok' => false, 'error' => 'Subject is not configured on this examination. Add it in Configuration first.'];
    }

    $qt = sanitizeQuizRichHtmlForStorage(trim((string)($data['question_text'] ?? '')));
    if ($qt === '') {
        return ['ok' => false, 'error' => 'Question text is required.'];
    }
    $a = trim((string)($data['choice_a'] ?? ''));
    $b = trim((string)($data['choice_b'] ?? ''));
    $c = trim((string)($data['choice_c'] ?? ''));
    $d = trim((string)($data['choice_d'] ?? ''));
    $cor = strtoupper(trim((string)($data['correct_answer'] ?? '')));
    if ($a === '' || $b === '') {
        return ['ok' => false, 'error' => 'Multiple Choice questions require at least two choices (A and B).'];
    }
    if ($c === '' && $d !== '') {
        return ['ok' => false, 'error' => 'Choice D cannot be filled while Choice C is empty.'];
    }
    $extraRes = examination_questions_diagnostic_extra_choices_normalize($data);
    if (empty($extraRes['ok'])) {
        return ['ok' => false, 'error' => (string)($extraRes['error'] ?? 'Invalid extra choices.')];
    }
    $extraMap = $extraRes['map'] ?? [];
    $extraJson = $extraRes['json'] ?? null;
    if ($extraJson === null) {
        $extraJson = '';
    }
    if ($extraMap !== [] && $d === '') {
        return ['ok' => false, 'error' => 'Fill choices A–D before adding E and beyond.'];
    }
    if ($cor === '' || !preg_match('/^[A-Z]$/', $cor)) {
        return ['ok' => false, 'error' => 'Please select the correct answer.'];
    }
    $map = ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d] + $extraMap;
    if (trim((string)($map[$cor] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Correct answer must match one of the available choices.'];
    }
    $qtype = 'mcq';

    $hasExtraCol = false;
    $colChk = @mysqli_query($conn, "SHOW COLUMNS FROM `diagnostic_questions` LIKE 'extra_choices_json'");
    if ($colChk && mysqli_num_rows($colChk) > 0) {
        $hasExtraCol = true;
    }
    if ($colChk) {
        mysqli_free_result($colChk);
    }
    if ($extraMap !== [] && !$hasExtraCol) {
        return ['ok' => false, 'error' => 'Extra choices (E+) require a database update. Please reload and try again.'];
    }

    if ($questionId > 0) {
        if ($hasExtraCol) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE diagnostic_questions SET question_text=?, question_type=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, extra_choices_json=?, correct_answer=? WHERE question_id=? AND batch_id=? AND subject_id=?'
            );
            mysqli_stmt_bind_param($upd, 'ssssssssiii', $qt, $qtype, $a, $b, $c, $d, $extraJson, $cor, $questionId, $batchId, $subjectId);
        } else {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE diagnostic_questions SET question_text=?, question_type=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=? WHERE question_id=? AND batch_id=? AND subject_id=?'
            );
            mysqli_stmt_bind_param($upd, 'sssssssiii', $qt, $qtype, $a, $b, $c, $d, $cor, $questionId, $batchId, $subjectId);
        }
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        return ['ok' => true, 'question_id' => $questionId];
    }

    $sort = 0;
    $sr = @mysqli_query(
        $conn,
        'SELECT COALESCE(MAX(sort_order), 0) AS m FROM diagnostic_questions WHERE batch_id=' . (int)$batchId . ' AND subject_id=' . (int)$subjectId
    );
    if ($sr && ($sm = mysqli_fetch_assoc($sr))) {
        $sort = (int)($sm['m'] ?? 0) + 1;
        mysqli_free_result($sr);
    }
    if ($hasExtraCol) {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO diagnostic_questions (batch_id, subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, extra_choices_json, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($ins, 'iissssssssi', $batchId, $subjectId, $qt, $qtype, $a, $b, $c, $d, $extraJson, $cor, $sort);
    } else {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO diagnostic_questions (batch_id, subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($ins, 'iisssssssi', $batchId, $subjectId, $qt, $qtype, $a, $b, $c, $d, $cor, $sort);
    }
    mysqli_stmt_execute($ins);
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    return ['ok' => true, 'question_id' => $newId];
}

/**
 * @return array{ok:bool,error?:string}
 */
function examination_questions_diagnostic_delete_one(mysqli $conn, int $batchId, int $professorId, int $questionId): array
{
    if ($batchId <= 0 || $questionId <= 0 || examination_questions_mutations_locked($conn, 'diagnostic', $batchId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $batch = diagnostic_exam_load_batch($conn, $batchId, $professorId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Examination not found.'];
    }
    @mysqli_query($conn, 'DELETE FROM diagnostic_questions WHERE question_id=' . (int)$questionId . ' AND batch_id=' . (int)$batchId);

    return ['ok' => true];
}

/**
 * Append diagnostic questions atomically for one subject. Validate-all first.
 *
 * @param list<array> $rows
 * @return array{ok:bool,error?:string,imported?:int,detected?:int,valid?:int,invalid?:int,errors?:list<array{row:int,message:string}>}
 */
function examination_questions_diagnostic_import_append(mysqli $conn, int $batchId, int $professorId, int $subjectId, array $rows): array
{
    if ($batchId <= 0 || $subjectId <= 0 || examination_questions_mutations_locked($conn, 'diagnostic', $batchId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $batch = diagnostic_exam_load_batch($conn, $batchId, $professorId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Examination not found.'];
    }
    $onBatch = false;
    foreach (diagnostic_exam_load_batch_subjects($conn, $batchId) as $bs) {
        if ((int)($bs['subject_id'] ?? 0) === $subjectId) {
            $onBatch = true;
            break;
        }
    }
    if (!$onBatch) {
        return ['ok' => false, 'error' => 'Please select a subject before importing diagnostic questions.'];
    }

    $validated = examination_questions_validate_import_all($rows, 'diagnostic');
    if (empty($validated['ok'])) {
        return $validated;
    }

    $sort = 0;
    $sr = @mysqli_query(
        $conn,
        'SELECT COALESCE(MAX(sort_order), 0) AS m FROM diagnostic_questions WHERE batch_id=' . (int)$batchId . ' AND subject_id=' . (int)$subjectId
    );
    if ($sr && ($sm = mysqli_fetch_assoc($sr))) {
        $sort = (int)($sm['m'] ?? 0) + 1;
        mysqli_free_result($sr);
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Could not start import. Please try again.'];
    }

    $imported = 0;
    try {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO diagnostic_questions (batch_id, subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        if (!$ins) {
            throw new RuntimeException('prepare_failed');
        }
        foreach ($validated['rows'] as $row) {
            $qt = (string)$row['question_text'];
            $qtype = 'mcq';
            $a = (string)$row['choice_a'];
            $b = (string)$row['choice_b'];
            $c = (string)$row['choice_c'];
            $d = (string)$row['choice_d'];
            $cor = (string)$row['correct_answer'];
            mysqli_stmt_bind_param($ins, 'iisssssssi', $batchId, $subjectId, $qt, $qtype, $a, $b, $c, $d, $cor, $sort);
            if (!mysqli_stmt_execute($ins)) {
                mysqli_stmt_close($ins);
                throw new RuntimeException('insert_failed');
            }
            $sort++;
            $imported++;
        }
        mysqli_stmt_close($ins);
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('commit_failed');
        }
    } catch (Throwable $e) {
        @mysqli_rollback($conn);
        error_log('[examination_questions_diagnostic_import] ' . $e->getMessage() . ' batch_id=' . $batchId . ' subject_id=' . $subjectId);

        return ['ok' => false, 'error' => 'Import failed and no questions were added. Please try again.'];
    }

    return [
        'ok' => true,
        'imported' => $imported,
        'detected' => (int)$validated['detected'],
        'valid' => (int)$validated['valid'],
        'invalid' => 0,
        'errors' => [],
    ];
}

/**
 * Duplicate an existing regular question as a new row (new question_id).
 *
 * @return array{ok:bool,error?:string,question_id?:int}
 */
function examination_questions_regular_duplicate_one(mysqli $conn, int $examId, int $professorId, int $questionId): array
{
    if ($examId <= 0 || $questionId <= 0 || examination_questions_mutations_locked($conn, 'regular', $examId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $src = null;
    $st = mysqli_prepare($conn, 'SELECT * FROM college_exam_questions WHERE question_id=? AND exam_id=? LIMIT 1');
    if ($st) {
        mysqli_stmt_bind_param($st, 'ii', $questionId, $examId);
        mysqli_stmt_execute($st);
        $src = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
    }
    if (!$src) {
        return ['ok' => false, 'error' => 'Question not found.'];
    }

    return examination_questions_regular_save_one($conn, $examId, $professorId, 0, [
        'question_type' => (string)($src['question_type'] ?? 'mcq'),
        'question_text' => (string)($src['question_text'] ?? ''),
        'choice_a' => (string)($src['choice_a'] ?? ''),
        'choice_b' => (string)($src['choice_b'] ?? ''),
        'choice_c' => (string)($src['choice_c'] ?? ''),
        'choice_d' => (string)($src['choice_d'] ?? ''),
        'correct_answer' => (string)($src['correct_answer'] ?? ''),
        'exam_subject_id' => (int)($src['exam_subject_id'] ?? 0),
        'exam_topic_id' => (int)($src['exam_topic_id'] ?? 0),
    ]);
}

/**
 * Duplicate an existing diagnostic question as a new row (new question_id).
 *
 * @return array{ok:bool,error?:string,question_id?:int}
 */
function examination_questions_diagnostic_duplicate_one(mysqli $conn, int $batchId, int $professorId, int $subjectId, int $questionId): array
{
    if ($batchId <= 0 || $subjectId <= 0 || $questionId <= 0 || examination_questions_mutations_locked($conn, 'diagnostic', $batchId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $src = null;
    $st = mysqli_prepare(
        $conn,
        'SELECT * FROM diagnostic_questions WHERE question_id=? AND batch_id=? AND subject_id=? LIMIT 1'
    );
    if ($st) {
        mysqli_stmt_bind_param($st, 'iii', $questionId, $batchId, $subjectId);
        mysqli_stmt_execute($st);
        $src = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
    }
    if (!$src) {
        return ['ok' => false, 'error' => 'Question not found.'];
    }

    return examination_questions_diagnostic_save_one($conn, $batchId, $professorId, $subjectId, 0, [
        'question_text' => (string)($src['question_text'] ?? ''),
        'choice_a' => (string)($src['choice_a'] ?? ''),
        'choice_b' => (string)($src['choice_b'] ?? ''),
        'choice_c' => (string)($src['choice_c'] ?? ''),
        'choice_d' => (string)($src['choice_d'] ?? ''),
        'correct_answer' => (string)($src['correct_answer'] ?? ''),
    ]);
}
