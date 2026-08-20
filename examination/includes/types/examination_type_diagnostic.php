<?php
declare(strict_types=1);

/**
 * Diagnostic examination adapter — read/list/normalize via diagnostic_batches.
 */

require_once dirname(__DIR__) . '/college_exam_helpers.php';
require_once dirname(__DIR__) . '/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/examination_assignment.php';
require_once dirname(__DIR__) . '/examination_eligibility.php';

function examination_type_diagnostic_list_rows(mysqli $conn, int $professorId): array
{
    $rows = [];
    $res = @mysqli_query(
        $conn,
        'SELECT * FROM diagnostic_batches WHERE created_by=' . (int)$professorId . ' ORDER BY updated_at DESC'
    );
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    }

    return $rows;
}

function examination_type_diagnostic_load_raw(mysqli $conn, int $sourceId, int $professorId): ?array
{
    if ($sourceId <= 0) {
        return null;
    }

    return diagnostic_exam_load_batch($conn, $sourceId, $professorId);
}

function examination_type_diagnostic_normalize(mysqli $conn, array $rawRow, string $nowSql): array
{
    $batchId = (int)($rawRow['batch_id'] ?? 0);
    $stats = examination_type_diagnostic_list_stats($conn, $batchId);
    $sections = diagnostic_exam_load_batch_sections($conn, $batchId);
    $users = diagnostic_exam_load_batch_users($conn, $batchId);

    $examineeScope = examination_normalize_examinee_scope((string)($rawRow['examinee_scope'] ?? 'college_student'));
    $assignmentMode = examination_normalize_assignment_mode((string)($rawRow['assignment_mode'] ?? 'sections'));

    $inProgress = 0;
    $submitted = 0;
    $mq = @mysqli_query(
        $conn,
        "SELECT
            SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS taking,
            SUM(CASE WHEN status IN ('submitted','expired') THEN 1 ELSE 0 END) AS submitted
         FROM diagnostic_attempts WHERE batch_id=" . (int)$batchId
    );
    if ($mq && ($m = mysqli_fetch_assoc($mq))) {
        $inProgress = (int)($m['taking'] ?? 0);
        $submitted = (int)($m['submitted'] ?? 0);
        mysqli_free_result($mq);
    } elseif ($mq) {
        mysqli_free_result($mq);
    }

    $examineeCount = examination_count_assigned_examinees($conn, 'diagnostic', $batchId, $rawRow, $sections, $users);
    $allDone = $examineeCount > 0 && ($submitted + $inProgress) >= $examineeCount && $inProgress === 0;
    $isPublished = !empty($rawRow['is_published']);
    $isFinished = (!empty($rawRow['deadline']) && (string)$rawRow['deadline'] < $nowSql) || $allDone;
    $isOpenBySchedule = $isPublished
        && (empty($rawRow['available_from']) || (string)$rawRow['available_from'] <= $nowSql)
        && (empty($rawRow['deadline']) || (string)$rawRow['deadline'] >= $nowSql);
    $isRunning = $isOpenBySchedule && !$isFinished && !$allDone;

    return examination_domain_build_record([
        'source_id' => $batchId,
        'source_table' => 'diagnostic_batches',
        'exam_type' => 'diagnostic',
        'title' => (string)($rawRow['title'] ?? ''),
        'description' => (string)($rawRow['description'] ?? ''),
        'time_limit_seconds' => (int)($rawRow['time_limit_seconds'] ?? 3600),
        'available_from' => examination_domain_nullable_datetime($rawRow['available_from'] ?? null),
        'deadline' => examination_domain_nullable_datetime($rawRow['deadline'] ?? null),
        'is_published' => $isPublished,
        'examinee_scope' => $examineeScope,
        'assignment_mode' => $assignmentMode,
        'section_count' => count($sections),
        'user_count' => count($users),
        'question_count' => (int)($stats['question_count'] ?? 0),
        'examinee_count' => $examineeCount,
        'assigned_sections' => $sections,
        'window_state' => examination_domain_window_state($rawRow, $nowSql, $isFinished),
        'is_finished' => $isFinished,
        'is_running' => $isRunning,
        'created_at' => (string)($rawRow['created_at'] ?? ''),
        'updated_at' => (string)($rawRow['updated_at'] ?? ''),
        'metadata' => [
            'subject_count' => (int)($stats['subject_count'] ?? 0),
            'shuffle_questions' => !empty($rawRow['shuffle_questions']),
            'shuffle_choices' => !empty($rawRow['shuffle_choices']),
        ],
    ]);
}

/**
 * Lightweight subject/question counts for list pages (no full question row load).
 *
 * @return array{subject_count:int,question_count:int}
 */
function examination_type_diagnostic_list_stats(mysqli $conn, int $batchId): array
{
    $subjectCount = 0;
    $questionCount = 0;
    if ($batchId <= 0) {
        return ['subject_count' => 0, 'question_count' => 0];
    }
    $q = @mysqli_query(
        $conn,
        'SELECT bs.subject_id, bs.questions_required, COUNT(q.question_id) AS avail
         FROM diagnostic_batch_subjects bs
         LEFT JOIN diagnostic_questions q
           ON q.batch_id = bs.batch_id AND q.subject_id = bs.subject_id
         WHERE bs.batch_id=' . (int)$batchId . '
         GROUP BY bs.subject_id, bs.questions_required'
    );
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $subjectCount++;
            $req = max(0, (int)($row['questions_required'] ?? 0));
            $avail = (int)($row['avail'] ?? 0);
            $questionCount += ($req > 0) ? min($req, $avail) : $avail;
        }
        mysqli_free_result($q);
    }

    return [
        'subject_count' => $subjectCount,
        'question_count' => $questionCount,
    ];
}

function examination_type_diagnostic_config_extras(mysqli $conn, int $professorId, int $sourceId): array
{
    $batch = null;
    $scope = 'college_student';
    if ($sourceId > 0) {
        $batch = examination_type_diagnostic_load_raw($conn, $sourceId, $professorId);
        $scope = examination_normalize_examinee_scope((string)($batch['examinee_scope'] ?? 'college_student'));
    }

    $sections = $sourceId > 0 ? diagnostic_exam_load_batch_sections($conn, $sourceId) : [];
    $users = $sourceId > 0 ? diagnostic_exam_load_batch_users($conn, $sourceId) : [];
    $batchSubjects = $sourceId > 0 ? diagnostic_exam_load_batch_subjects($conn, $sourceId) : [];
    $questionsRequired = [];
    foreach ($batchSubjects as $bs) {
        $questionsRequired[(int)($bs['subject_id'] ?? 0)] = (int)($bs['questions_required'] ?? 0);
    }

    return [
        'sections' => $sections !== [] ? $sections : [''],
        'assigned_user_ids' => $users,
        'batch_subjects' => $batchSubjects,
        'questions_required' => $questionsRequired,
        'subjects' => diagnostic_exam_load_subject_catalog($conn),
        'suggested_sections' => diagnostic_exam_suggest_sections($conn, $scope),
        'shuffle_questions' => !empty($batch['shuffle_questions'] ?? 0),
        'shuffle_choices' => !empty($batch['shuffle_choices'] ?? 0),
    ];
}

function examination_type_diagnostic_save_config(mysqli $conn, int $professorId, array $post, int $sourceId): array
{
    $saveAction = strtolower(trim((string)($post['save_action'] ?? 'draft')));
    $isDraft = $saveAction !== 'publish';
    $title = trim((string)($post['title'] ?? ''));
    if ($title === '' && $isDraft) {
        $title = 'Untitled draft';
    }
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required.'];
    }

    $description = trim((string)($post['description'] ?? ''));
    $timeLimit = examination_time_limit_from_post($post);
    if ($timeLimit < 60) {
        $timeLimit = 3600;
    }
    $availSql = college_exam_parse_datetime_local(trim((string)($post['available_from'] ?? '')));
    $deadSql = college_exam_parse_datetime_local(trim((string)($post['deadline'] ?? '')));
    $isPublished = $isDraft ? 0 : 1;
    $shuffleQ = !empty($post['shuffle_questions']) ? 1 : 0;
    $shuffleC = !empty($post['shuffle_choices']) ? 1 : 0;
    $examineeScope = examination_normalize_examinee_scope((string)($post['examinee_scope'] ?? 'college_student'));
    $assignmentMode = examination_normalize_assignment_mode((string)($post['assignment_mode'] ?? 'all'));
    $sections = examination_parse_sections_from_post($post);
    $userIds = examination_parse_user_ids_from_post($post);
    $subjectIds = examination_parse_subject_ids_from_post($post);
    $reqMap = is_array($post['questions_required'] ?? null) ? $post['questions_required'] : [];

    if (!$isDraft) {
        $assignErr = examination_validate_assignment_for_publish($assignmentMode, $sections, $userIds);
        if ($assignErr !== null) {
            return ['ok' => false, 'error' => $assignErr];
        }
        if ($subjectIds === []) {
            return ['ok' => false, 'error' => 'Select at least one subject.'];
        }
    }

    if ($sourceId > 0 && examination_assignment_mutations_locked($conn, 'diagnostic', $sourceId)) {
        $existing = examination_type_diagnostic_load_raw($conn, $sourceId, $professorId);
        if ($existing) {
            $examineeScope = examination_normalize_examinee_scope((string)($existing['examinee_scope'] ?? 'college_student'));
            $assignmentMode = examination_normalize_assignment_mode((string)($existing['assignment_mode'] ?? 'all'));
            $sections = examination_load_assigned_sections($conn, 'diagnostic', $sourceId);
            $userIds = examination_load_assigned_user_ids($conn, 'diagnostic', $sourceId);
        }
    }

    mysqli_begin_transaction($conn);
    try {
        if ($sourceId <= 0) {
            $ins = mysqli_prepare(
                $conn,
                'INSERT INTO diagnostic_batches (title, description, time_limit_seconds, available_from, deadline, is_published, shuffle_questions, shuffle_choices, examinee_scope, assignment_mode, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            mysqli_stmt_bind_param(
                $ins,
                'ssissiiissi',
                $title,
                $description,
                $timeLimit,
                $availSql,
                $deadSql,
                $isPublished,
                $shuffleQ,
                $shuffleC,
                $examineeScope,
                $assignmentMode,
                $professorId
            );
            mysqli_stmt_execute($ins);
            $sourceId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($ins);
        } else {
            $existing = examination_type_diagnostic_load_raw($conn, $sourceId, $professorId);
            if (!$existing) {
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => 'Examination not found.'];
            }
            $upd = mysqli_prepare(
                $conn,
                'UPDATE diagnostic_batches SET title=?, description=?, time_limit_seconds=?, available_from=?, deadline=?, is_published=?, shuffle_questions=?, shuffle_choices=?, examinee_scope=?, assignment_mode=? WHERE batch_id=? AND created_by=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'ssissiiissii',
                $title,
                $description,
                $timeLimit,
                $availSql,
                $deadSql,
                $isPublished,
                $shuffleQ,
                $shuffleC,
                $examineeScope,
                $assignmentMode,
                $sourceId,
                $professorId
            );
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }

        if (!examination_assignment_mutations_locked($conn, 'diagnostic', $sourceId)) {
            @mysqli_query($conn, 'DELETE FROM diagnostic_batch_sections WHERE batch_id=' . (int)$sourceId);
            @mysqli_query($conn, 'DELETE FROM diagnostic_batch_users WHERE batch_id=' . (int)$sourceId);

            if ($assignmentMode === 'sections' || $assignmentMode === 'sections_and_users') {
                foreach ($sections as $sec) {
                    $st = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_sections (batch_id, section_value) VALUES (?,?)');
                    mysqli_stmt_bind_param($st, 'is', $sourceId, $sec);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                }
            }

            if ($assignmentMode === 'users' || $assignmentMode === 'sections_and_users') {
                foreach ($userIds as $userId) {
                    $st = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_users (batch_id, user_id) VALUES (?,?)');
                    mysqli_stmt_bind_param($st, 'ii', $sourceId, $userId);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                }
            }
        }

        @mysqli_query($conn, 'DELETE FROM diagnostic_batch_subjects WHERE batch_id=' . (int)$sourceId);
        $sort = 0;
        foreach ($subjectIds as $sid) {
            $sort++;
            $req = max(0, (int)($reqMap[$sid] ?? $reqMap[(string)$sid] ?? 0));
            $st = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_subjects (batch_id, subject_id, sort_order, questions_required) VALUES (?,?,?,?)');
            mysqli_stmt_bind_param($st, 'iiii', $sourceId, $sid, $sort, $req);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }

        mysqli_commit($conn);

        return [
            'ok' => true,
            'source_id' => $sourceId,
            'exam_type' => 'diagnostic',
            'is_published' => (bool)$isPublished,
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not save diagnostic examination configuration.'];
    }
}

/**
 * Permanently delete a diagnostic examination and related rows (owner-scoped).
 *
 * @return array{ok:bool,error?:string,title?:string}
 */
function examination_type_diagnostic_delete(mysqli $conn, int $sourceId, int $professorId): array
{
    if ($sourceId <= 0 || $professorId <= 0) {
        return ['ok' => false, 'error' => 'Invalid examination.'];
    }

    $raw = examination_type_diagnostic_load_raw($conn, $sourceId, $professorId);
    if ($raw === null) {
        return ['ok' => false, 'error' => 'Examination not found.'];
    }
    $record = examination_type_diagnostic_normalize($conn, $raw, date('Y-m-d H:i:s'));
    if (!empty($record['is_running'])) {
        return ['ok' => false, 'error' => 'Cannot delete while this examination is running.'];
    }

    $title = (string)($record['title'] ?? '');
    $batchId = $sourceId;

    mysqli_begin_transaction($conn);
    try {
        @mysqli_query(
            $conn,
            'DELETE a FROM diagnostic_answers a
             INNER JOIN diagnostic_attempts t ON t.attempt_id = a.attempt_id
             WHERE t.batch_id=' . (int)$batchId
        );
        @mysqli_query($conn, 'DELETE FROM diagnostic_attempts WHERE batch_id=' . (int)$batchId);
        @mysqli_query($conn, 'DELETE FROM diagnostic_questions WHERE batch_id=' . (int)$batchId);
        @mysqli_query($conn, 'DELETE FROM diagnostic_batch_subjects WHERE batch_id=' . (int)$batchId);
        @mysqli_query($conn, 'DELETE FROM diagnostic_batch_sections WHERE batch_id=' . (int)$batchId);
        @mysqli_query($conn, 'DELETE FROM diagnostic_batch_users WHERE batch_id=' . (int)$batchId);

        $st = mysqli_prepare($conn, 'DELETE FROM diagnostic_batches WHERE batch_id=? AND created_by=? LIMIT 1');
        if (!$st) {
            throw new RuntimeException('prepare failed');
        }
        mysqli_stmt_bind_param($st, 'ii', $batchId, $professorId);
        mysqli_stmt_execute($st);
        $affected = mysqli_stmt_affected_rows($st);
        mysqli_stmt_close($st);
        if ($affected < 1) {
            throw new RuntimeException('batch missing');
        }

        mysqli_commit($conn);

        return ['ok' => true, 'title' => $title];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not delete this examination.'];
    }
}

/**
 * Clone a diagnostic examination (config + subjects + assignment + questions) as a new draft.
 * Attempts/answers are not copied.
 *
 * @return array{ok:bool,error?:string,title?:string,source_id?:int,exam_type?:string,question_count?:int}
 */
function examination_type_diagnostic_duplicate(mysqli $conn, int $sourceId, int $professorId): array
{
    if ($sourceId <= 0 || $professorId <= 0) {
        return ['ok' => false, 'error' => 'Invalid examination.'];
    }

    $raw = examination_type_diagnostic_load_raw($conn, $sourceId, $professorId);
    if ($raw === null) {
        return ['ok' => false, 'error' => 'Examination not found.'];
    }

    $baseTitle = trim((string)($raw['title'] ?? ''));
    if ($baseTitle === '') {
        $baseTitle = 'Untitled draft';
    }
    if (preg_match('/^(.*) \(Copy(?: (\d+))?\)$/u', $baseTitle, $m)) {
        $n = isset($m[2]) && $m[2] !== '' ? ((int)$m[2] + 1) : 2;
        $title = $m[1] . ' (Copy ' . $n . ')';
    } else {
        $title = $baseTitle . ' (Copy)';
    }

    $description = (string)($raw['description'] ?? '');
    $timeLimit = max(60, (int)($raw['time_limit_seconds'] ?? 3600));
    $availSql = examination_domain_nullable_datetime($raw['available_from'] ?? null);
    $deadSql = examination_domain_nullable_datetime($raw['deadline'] ?? null);
    $isPublished = 0;
    $shuffleQ = !empty($raw['shuffle_questions']) ? 1 : 0;
    $shuffleC = !empty($raw['shuffle_choices']) ? 1 : 0;
    $examineeScope = examination_normalize_examinee_scope((string)($raw['examinee_scope'] ?? 'college_student'));
    $assignmentMode = examination_normalize_assignment_mode((string)($raw['assignment_mode'] ?? 'all'));

    mysqli_begin_transaction($conn);
    try {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO diagnostic_batches (title, description, time_limit_seconds, available_from, deadline, is_published, shuffle_questions, shuffle_choices, examinee_scope, assignment_mode, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        if (!$ins) {
            throw new RuntimeException('prepare insert failed');
        }
        mysqli_stmt_bind_param(
            $ins,
            'ssissiiissi',
            $title,
            $description,
            $timeLimit,
            $availSql,
            $deadSql,
            $isPublished,
            $shuffleQ,
            $shuffleC,
            $examineeScope,
            $assignmentMode,
            $professorId
        );
        mysqli_stmt_execute($ins);
        $newId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        if ($newId <= 0) {
            throw new RuntimeException('insert id missing');
        }

        $okSub = @mysqli_query(
            $conn,
            'INSERT INTO diagnostic_batch_subjects (batch_id, subject_id, sort_order, questions_required)
             SELECT ' . (int)$newId . ', subject_id, sort_order, questions_required
             FROM diagnostic_batch_subjects WHERE batch_id=' . (int)$sourceId
        );
        if ($okSub === false) {
            throw new RuntimeException('copy subjects failed');
        }

        $okSec = @mysqli_query(
            $conn,
            'INSERT INTO diagnostic_batch_sections (batch_id, section_value)
             SELECT ' . (int)$newId . ', section_value FROM diagnostic_batch_sections WHERE batch_id=' . (int)$sourceId
        );
        if ($okSec === false) {
            throw new RuntimeException('copy sections failed');
        }

        $okUsers = @mysqli_query(
            $conn,
            'INSERT INTO diagnostic_batch_users (batch_id, user_id)
             SELECT ' . (int)$newId . ', user_id FROM diagnostic_batch_users WHERE batch_id=' . (int)$sourceId
        );
        if ($okUsers === false) {
            throw new RuntimeException('copy users failed');
        }

        $okQ = @mysqli_query(
            $conn,
            'INSERT INTO diagnostic_questions (batch_id, subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
             SELECT ' . (int)$newId . ', subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order
             FROM diagnostic_questions WHERE batch_id=' . (int)$sourceId
             . ' ORDER BY sort_order ASC, question_id ASC'
        );
        if ($okQ === false) {
            throw new RuntimeException('copy questions failed');
        }

        $qCount = 0;
        $qc = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM diagnostic_questions WHERE batch_id=' . (int)$newId);
        if ($qc && ($qr = mysqli_fetch_assoc($qc))) {
            $qCount = (int)($qr['c'] ?? 0);
            mysqli_free_result($qc);
        }

        mysqli_commit($conn);

        return [
            'ok' => true,
            'source_id' => $newId,
            'exam_type' => 'diagnostic',
            'title' => $title,
            'question_count' => $qCount,
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not duplicate this examination.'];
    }
}
