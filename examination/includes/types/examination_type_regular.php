<?php
declare(strict_types=1);

/**
 * Regular examination adapter — read/list/normalize via college_exams.
 */

require_once dirname(__DIR__) . '/college_exam_helpers.php';
require_once dirname(__DIR__) . '/examination_assignment.php';
require_once dirname(__DIR__) . '/examination_eligibility.php';

function examination_type_regular_list_rows(mysqli $conn, int $professorId): array
{
    $rows = [];
    $res = @mysqli_query(
        $conn,
        'SELECT * FROM college_exams WHERE created_by=' . (int)$professorId . ' ORDER BY updated_at DESC'
    );
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    }

    return $rows;
}

function examination_type_regular_load_raw(mysqli $conn, int $sourceId, int $professorId): ?array
{
    if ($sourceId <= 0) {
        return null;
    }
    $st = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'ii', $sourceId, $professorId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

function examination_type_regular_question_count(mysqli $conn, int $examId): int
{
    $q = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . (int)$examId);
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        mysqli_free_result($q);

        return (int)($r['c'] ?? 0);
    }
    if ($q) {
        mysqli_free_result($q);
    }

    return 0;
}

function examination_type_regular_normalize(mysqli $conn, array $rawRow, string $nowSql): array
{
    $examId = (int)($rawRow['exam_id'] ?? 0);
    $isPublished = !empty($rawRow['is_published']);
    $sections = examination_load_assigned_sections($conn, 'regular', $examId);
    $users = examination_load_assigned_user_ids($conn, 'regular', $examId);
    $examineeScope = examination_normalize_examinee_scope((string)($rawRow['examinee_scope'] ?? 'college_student'));
    $assignmentMode = examination_normalize_assignment_mode((string)($rawRow['assignment_mode'] ?? 'all'));

    $submittedCount = 0;
    $sq = @mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM college_exam_attempts WHERE exam_id={$examId} AND status='submitted'"
    );
    if ($sq && ($sr = mysqli_fetch_assoc($sq))) {
        $submittedCount = (int)($sr['c'] ?? 0);
        mysqli_free_result($sq);
    } elseif ($sq) {
        mysqli_free_result($sq);
    }

    $allFinishedOpen = college_exam_finished_all_submitted_no_deadline($conn, $rawRow, $submittedCount);
    $isFinished = (!empty($rawRow['deadline']) && (string)$rawRow['deadline'] < $nowSql) || $allFinishedOpen;
    $isOpenBySchedule = $isPublished
        && (empty($rawRow['available_from']) || (string)$rawRow['available_from'] <= $nowSql)
        && (empty($rawRow['deadline']) || (string)$rawRow['deadline'] >= $nowSql);
    $isRunning = $isOpenBySchedule && !$isFinished && !$allFinishedOpen;

    return examination_domain_build_record([
        'source_id' => $examId,
        'source_table' => 'college_exams',
        'exam_type' => 'regular',
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
        'assigned_sections' => $sections,
        'question_count' => examination_type_regular_question_count($conn, $examId),
        'examinee_count' => examination_count_assigned_examinees($conn, 'regular', $examId),
        'window_state' => examination_domain_window_state($rawRow, $nowSql, $isFinished),
        'is_finished' => $isFinished,
        'is_running' => $isRunning,
        'created_at' => (string)($rawRow['created_at'] ?? ''),
        'updated_at' => (string)($rawRow['updated_at'] ?? ''),
        'metadata' => [
            'shuffle_questions' => !empty($rawRow['shuffle_questions']),
            'shuffle_choices' => !empty($rawRow['shuffle_choices']),
            'shuffle_mcq_questions' => !empty($rawRow['shuffle_mcq_questions']),
            'shuffle_tf_questions' => !empty($rawRow['shuffle_tf_questions']),
            'description_markdown' => !empty($rawRow['description_markdown']),
            'review_sheet_available_from' => examination_domain_nullable_datetime($rawRow['review_sheet_available_from'] ?? null),
            'review_sheet_available_until' => examination_domain_nullable_datetime($rawRow['review_sheet_available_until'] ?? null),
            'assigned_sections' => $sections,
        ],
    ]);
}

function examination_type_regular_config_extras(mysqli $conn, int $professorId, int $sourceId): array
{
    $raw = $sourceId > 0 ? examination_type_regular_load_raw($conn, $sourceId, $professorId) : null;
    $scope = examination_normalize_examinee_scope((string)($raw['examinee_scope'] ?? 'college_student'));
    $sections = $sourceId > 0 ? examination_load_assigned_sections($conn, 'regular', $sourceId) : [];
    $users = $sourceId > 0 ? examination_load_assigned_user_ids($conn, 'regular', $sourceId) : [];

    return [
        'sections' => $sections !== [] ? $sections : [''],
        'assigned_user_ids' => $users,
        'batch_subjects' => [],
        'questions_required' => [],
        'subjects' => [],
        'suggested_sections' => diagnostic_exam_suggest_sections($conn, $scope),
        'shuffle_questions' => !empty($raw['shuffle_questions']),
        'shuffle_choices' => !empty($raw['shuffle_choices']),
        'shuffle_mcq_questions' => !empty($raw['shuffle_mcq_questions'] ?? $raw['shuffle_questions'] ?? 0),
        'shuffle_tf_questions' => !empty($raw['shuffle_tf_questions'] ?? $raw['shuffle_questions'] ?? 0),
        'description_markdown' => !empty($raw['description_markdown']),
        'review_sheet_available_from' => $raw['review_sheet_available_from'] ?? null,
        'review_sheet_available_until' => $raw['review_sheet_available_until'] ?? null,
    ];
}

function examination_type_regular_save_config(mysqli $conn, int $professorId, array $post, int $sourceId): array
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
    $shuffleQuestions = !empty($post['shuffle_questions']) ? 1 : 0;
    $shuffleChoices = !empty($post['shuffle_choices']) ? 1 : 0;
    $shuffleMcq = !empty($post['shuffle_mcq_questions']) ? 1 : ($shuffleQuestions ? 1 : 0);
    $shuffleTf = !empty($post['shuffle_tf_questions']) ? 1 : ($shuffleQuestions ? 1 : 0);
    $descriptionMarkdown = !empty($post['description_markdown']) ? 1 : 0;
    $reviewFrom = college_exam_parse_datetime_local(trim((string)($post['review_sheet_available_from'] ?? '')));
    $reviewUntil = college_exam_parse_datetime_local(trim((string)($post['review_sheet_available_until'] ?? '')));
    $examineeScope = examination_normalize_examinee_scope((string)($post['examinee_scope'] ?? 'college_student'));
    $assignmentMode = examination_normalize_assignment_mode((string)($post['assignment_mode'] ?? 'all'));
    $sections = examination_parse_sections_from_post($post);
    $userIds = examination_parse_user_ids_from_post($post);

    if ($sourceId > 0 && examination_assignment_mutations_locked($conn, 'regular', $sourceId)) {
        $existing = examination_type_regular_load_raw($conn, $sourceId, $professorId);
        if ($existing) {
            $examineeScope = examination_normalize_examinee_scope((string)($existing['examinee_scope'] ?? 'college_student'));
            $assignmentMode = examination_normalize_assignment_mode((string)($existing['assignment_mode'] ?? 'all'));
            $sections = examination_load_assigned_sections($conn, 'regular', $sourceId);
            $userIds = examination_load_assigned_user_ids($conn, 'regular', $sourceId);
        }
    }

    if (!$isDraft) {
        $assignErr = examination_validate_assignment_for_publish($assignmentMode, $sections, $userIds);
        if ($assignErr !== null) {
            return ['ok' => false, 'error' => $assignErr];
        }
    }

    mysqli_begin_transaction($conn);
    try {
        if ($sourceId <= 0) {
            $ins = mysqli_prepare(
                $conn,
                'INSERT INTO college_exams (title, description, time_limit_seconds, available_from, deadline, is_published, examinee_scope, assignment_mode, created_by, shuffle_questions, shuffle_choices, shuffle_mcq_questions, shuffle_tf_questions, description_markdown, review_sheet_available_from, review_sheet_available_until) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            mysqli_stmt_bind_param(
                $ins,
                'ssississiiiiiiss',
                $title,
                $description,
                $timeLimit,
                $availSql,
                $deadSql,
                $isPublished,
                $examineeScope,
                $assignmentMode,
                $professorId,
                $shuffleQuestions,
                $shuffleChoices,
                $shuffleMcq,
                $shuffleTf,
                $descriptionMarkdown,
                $reviewFrom,
                $reviewUntil
            );
            mysqli_stmt_execute($ins);
            $sourceId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($ins);
        } else {
            $existing = examination_type_regular_load_raw($conn, $sourceId, $professorId);
            if (!$existing) {
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => 'Examination not found.'];
            }
            $upd = mysqli_prepare(
                $conn,
                'UPDATE college_exams SET title=?, description=?, time_limit_seconds=?, available_from=?, deadline=?, is_published=?, examinee_scope=?, assignment_mode=?, shuffle_questions=?, shuffle_choices=?, shuffle_mcq_questions=?, shuffle_tf_questions=?, description_markdown=?, review_sheet_available_from=?, review_sheet_available_until=? WHERE exam_id=? AND created_by=?'
            );
            mysqli_stmt_bind_param(
                $upd,
                'ssississiiiiissii',
                $title,
                $description,
                $timeLimit,
                $availSql,
                $deadSql,
                $isPublished,
                $examineeScope,
                $assignmentMode,
                $shuffleQuestions,
                $shuffleChoices,
                $shuffleMcq,
                $shuffleTf,
                $descriptionMarkdown,
                $reviewFrom,
                $reviewUntil,
                $sourceId,
                $professorId
            );
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }

        if (!examination_assignment_mutations_locked($conn, 'regular', $sourceId)) {
            @mysqli_query($conn, 'DELETE FROM college_exam_sections WHERE exam_id=' . (int)$sourceId);
            @mysqli_query($conn, 'DELETE FROM college_exam_users WHERE exam_id=' . (int)$sourceId);

            if ($assignmentMode === 'sections' || $assignmentMode === 'sections_and_users') {
                foreach ($sections as $sec) {
                    $st = mysqli_prepare($conn, 'INSERT INTO college_exam_sections (exam_id, section_value) VALUES (?,?)');
                    mysqli_stmt_bind_param($st, 'is', $sourceId, $sec);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                }
            }

            if ($assignmentMode === 'users' || $assignmentMode === 'sections_and_users') {
                foreach ($userIds as $userId) {
                    $st = mysqli_prepare($conn, 'INSERT INTO college_exam_users (exam_id, user_id) VALUES (?,?)');
                    mysqli_stmt_bind_param($st, 'ii', $sourceId, $userId);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                }
            }
        }

        mysqli_commit($conn);

        return [
            'ok' => true,
            'source_id' => $sourceId,
            'exam_type' => 'regular',
            'is_published' => (bool)$isPublished,
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not save regular examination configuration.'];
    }
}
