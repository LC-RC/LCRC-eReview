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
                return ['ok' => false, 'error' => 'Enter at least choices A and B.'];
            }
            // Compact trailing empty slots (C/D may be unused); do not allow gap after filled.
            if ($c === '' && $d !== '') {
                return ['ok' => false, 'error' => 'Fill choice C before using choice D, or clear D.'];
            }
            if ($ok === '') {
                return ['ok' => false, 'error' => 'Please select the correct answer.'];
            }
            if (!preg_match('/^[A-D]$/', $ok)) {
                return ['ok' => false, 'error' => 'Select the correct choice (A–D).'];
            }
            $map = ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d];
            if (trim((string)($map[$ok] ?? '')) === '') {
                return ['ok' => false, 'error' => 'The correct answer must match a filled choice.'];
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

    if ($questionId > 0) {
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
 * Append imported questions (legacy CSV/paste shape). Never delete-all.
 *
 * @param list<array> $rows
 * @return array{ok:bool,error?:string,imported?:int}
 */
function examination_questions_regular_import_append(mysqli $conn, int $examId, int $professorId, array $rows): array
{
    if ($examId <= 0 || examination_questions_mutations_locked($conn, 'regular', $examId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $imported = 0;
    foreach ($rows as $data) {
        if (!is_array($data)) {
            continue;
        }
        $res = examination_questions_regular_save_one($conn, $examId, $professorId, 0, $data);
        if (!empty($res['ok'])) {
            $imported++;
        }
    }
    if ($imported === 0) {
        return ['ok' => false, 'error' => 'No valid questions to import.'];
    }

    return ['ok' => true, 'imported' => $imported];
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
        return ['ok' => false, 'error' => 'Enter at least choices A and B.'];
    }
    if ($c === '' && $d !== '') {
        return ['ok' => false, 'error' => 'Fill choice C before using choice D, or clear D.'];
    }
    if ($cor === '' || !preg_match('/^[A-D]$/', $cor)) {
        return ['ok' => false, 'error' => 'Please select the correct answer.'];
    }
    $map = ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d];
    if (trim((string)($map[$cor] ?? '')) === '') {
        return ['ok' => false, 'error' => 'The correct answer must match a filled choice.'];
    }
    $qtype = 'mcq';

    if ($questionId > 0) {
        $upd = mysqli_prepare(
            $conn,
            'UPDATE diagnostic_questions SET question_text=?, question_type=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=? WHERE question_id=? AND batch_id=? AND subject_id=?'
        );
        mysqli_stmt_bind_param($upd, 'sssssssiii', $qt, $qtype, $a, $b, $c, $d, $cor, $questionId, $batchId, $subjectId);
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
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO diagnostic_questions (batch_id, subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    mysqli_stmt_bind_param($ins, 'iisssssssi', $batchId, $subjectId, $qt, $qtype, $a, $b, $c, $d, $cor, $sort);
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
 * @param list<array> $rows
 * @return array{ok:bool,error?:string,imported?:int}
 */
function examination_questions_diagnostic_import_append(mysqli $conn, int $batchId, int $professorId, int $subjectId, array $rows): array
{
    if ($batchId <= 0 || $subjectId <= 0 || examination_questions_mutations_locked($conn, 'diagnostic', $batchId)) {
        return ['ok' => false, 'error' => 'Questions are locked because this examination already has student attempts.'];
    }
    $imported = 0;
    foreach ($rows as $data) {
        if (!is_array($data)) {
            continue;
        }
        $res = examination_questions_diagnostic_save_one($conn, $batchId, $professorId, $subjectId, 0, $data);
        if (!empty($res['ok'])) {
            $imported++;
        }
    }
    if ($imported === 0) {
        return ['ok' => false, 'error' => 'No valid questions to import.'];
    }

    return ['ok' => true, 'imported' => $imported];
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
