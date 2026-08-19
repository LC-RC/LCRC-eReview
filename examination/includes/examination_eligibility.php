<?php
declare(strict_types=1);

/**
 * Shared examination audience / assignment eligibility.
 * Visibility (assigned + published) is separate from can-start (adds schedule window).
 */

require_once __DIR__ . '/examination_assignment.php';

$__ereviewPlatformAccess = dirname(__DIR__, 2) . '/includes/platform_access.php';
if (is_file($__ereviewPlatformAccess)) {
    require_once $__ereviewPlatformAccess;
}

function examination_exam_type_normalize(string $examType): string
{
    $t = examination_normalize_exam_type($examType);
    return $t === 'diagnostic' ? 'diagnostic' : 'regular';
}

function examination_record_is_published(array $record, string $examType): bool
{
    if ($examType === 'diagnostic') {
        return diagnostic_exam_batch_is_published($record);
    }

    return college_exam_row_is_published($record);
}

function examination_record_schedule_is_open(array $record, string $nowSql): bool
{
    if (!empty($record['available_from']) && (string)$record['available_from'] > $nowSql) {
        return false;
    }
    if (!empty($record['deadline']) && (string)$record['deadline'] < $nowSql) {
        return false;
    }

    return true;
}

function examination_user_in_assigned_sections(mysqli $conn, string $examType, int $sourceId, string $section): bool
{
    if (examination_normalize_section_compare_key($section) === '' || $sourceId <= 0) {
        return false;
    }
    $assigned = examination_load_assigned_sections($conn, $examType, $sourceId);

    return examination_section_is_in_list($section, $assigned);
}

function examination_user_in_explicit_assignees(mysqli $conn, string $examType, int $sourceId, int $userId): bool
{
    if ($sourceId <= 0 || $userId <= 0) {
        return false;
    }
    if ($examType === 'diagnostic') {
        return diagnostic_exam_user_in_batch_assignees($conn, $sourceId, $userId);
    }
    $st = mysqli_prepare($conn, 'SELECT 1 FROM college_exam_users WHERE exam_id=? AND user_id=? LIMIT 1');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'ii', $sourceId, $userId);
    mysqli_stmt_execute($st);
    $ok = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $ok;
}

function examination_user_passes_assignment(
    mysqli $conn,
    int $userId,
    string $assignmentMode,
    int $sourceId,
    string $examType,
    string $userSection
): bool {
    $mode = examination_normalize_assignment_mode($assignmentMode);
    if ($mode === 'all') {
        return true;
    }
    $examType = examination_exam_type_normalize($examType);
    if ($sourceId <= 0) {
        return false;
    }

    // Section-targeted items with an empty map must not fall open to everyone.
    if ($mode === 'sections' || $mode === 'sections_and_users') {
        $assignedSections = examination_load_assigned_sections($conn, $examType, $sourceId);
        if ($mode === 'sections' && $assignedSections === []) {
            return false;
        }
        $inSection = examination_section_is_in_list($userSection, $assignedSections);
        if ($mode === 'sections') {
            return $inSection;
        }
        $inUsers = examination_user_in_explicit_assignees($conn, $examType, $sourceId, $userId);

        return $inSection || $inUsers;
    }

    if ($mode === 'users') {
        return examination_user_in_explicit_assignees($conn, $examType, $sourceId, $userId);
    }

    return false;
}

/**
 * Assigned to this examination (scope + assignment). Does NOT check schedule or publish.
 */
function examination_user_is_assigned(mysqli $conn, int $userId, array $record, string $examType): bool
{
    $examType = examination_exam_type_normalize($examType);
    $sourceId = (int)($record[$examType === 'diagnostic' ? 'batch_id' : 'exam_id'] ?? 0);
    if ($sourceId <= 0) {
        return false;
    }
    $user = diagnostic_exam_load_examinee_user($conn, $userId);
    if (!$user) {
        return false;
    }
    $scope = examination_normalize_examinee_scope((string)($record['examinee_scope'] ?? 'college_student'));
    if (!diagnostic_exam_user_matches_examinee_scope($user, $scope)) {
        return false;
    }
    $mode = examination_normalize_assignment_mode((string)($record['assignment_mode'] ?? 'all'));

    return examination_user_passes_assignment(
        $conn,
        $userId,
        $mode,
        $sourceId,
        $examType,
        (string)($user['section'] ?? '')
    );
}

/**
 * May open the take page intro (published + assigned, or existing attempt holder).
 */
function examination_user_can_view_exam(mysqli $conn, int $userId, array $record, string $examType, ?array $attempt = null): bool
{
    if ($attempt !== null) {
        return true;
    }
    if (!examination_record_is_published($record, examination_exam_type_normalize($examType))) {
        return false;
    }

    return examination_user_is_assigned($conn, $userId, $record, $examType);
}

/**
 * May start or continue an in-window attempt (published + assigned + schedule open).
 */
function examination_user_can_start_exam(mysqli $conn, int $userId, array $record, string $examType, string $nowSql): bool
{
    if (!examination_record_is_published($record, examination_exam_type_normalize($examType))) {
        return false;
    }
    if (!examination_record_schedule_is_open($record, $nowSql)) {
        return false;
    }

    return examination_user_is_assigned($conn, $userId, $record, $examType);
}

function examination_load_assigned_sections(mysqli $conn, string $examType, int $sourceId): array
{
    if ($sourceId <= 0) {
        return [];
    }
    if (examination_exam_type_normalize($examType) === 'diagnostic') {
        return diagnostic_exam_load_batch_sections($conn, $sourceId);
    }
    $out = [];
    $st = mysqli_prepare($conn, 'SELECT section_value FROM college_exam_sections WHERE exam_id=? ORDER BY section_value ASC');
    if ($st) {
        mysqli_stmt_bind_param($st, 'i', $sourceId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($r = mysqli_fetch_assoc($res)) {
            $out[] = (string)($r['section_value'] ?? '');
        }
        mysqli_stmt_close($st);
    }

    return $out;
}

function examination_load_assigned_user_ids(mysqli $conn, string $examType, int $sourceId): array
{
    if ($sourceId <= 0) {
        return [];
    }
    if (examination_exam_type_normalize($examType) === 'diagnostic') {
        return diagnostic_exam_load_batch_users($conn, $sourceId);
    }
    $out = [];
    $q = @mysqli_query($conn, 'SELECT user_id FROM college_exam_users WHERE exam_id=' . (int)$sourceId . ' ORDER BY user_id ASC');
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $out[] = (int)($r['user_id'] ?? 0);
        }
        mysqli_free_result($q);
    }

    return array_values(array_filter($out, static fn($id) => $id > 0));
}

/**
 * User IDs matching assignment rules (does not include attempt-only extras).
 *
 * @return list<int>
 */
function examination_pure_assigned_user_ids(mysqli $conn, string $examType, int $sourceId): array
{
    $examType = examination_exam_type_normalize($examType);
    if ($sourceId <= 0) {
        return [];
    }

    $record = $examType === 'diagnostic'
        ? diagnostic_exam_load_batch($conn, $sourceId)
        : null;
    if ($examType === 'regular') {
        $st = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'i', $sourceId);
            mysqli_stmt_execute($st);
            $record = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            mysqli_stmt_close($st);
        }
    }
    if (!$record) {
        return [];
    }

    $scope = examination_normalize_examinee_scope((string)($record['examinee_scope'] ?? 'college_student'));
    $mode = examination_normalize_assignment_mode((string)($record['assignment_mode'] ?? 'all'));
    $scopeSql = diagnostic_exam_examinee_scope_sql($scope, 'u');
    $examineeWhere = function_exists('ereview_sql_college_examinee_where')
        ? ereview_sql_college_examinee_where('u')
        : "u.role='college_student' AND u.status='approved'";
    $ids = [];

    if ($mode === 'all') {
        $q = @mysqli_query($conn, "SELECT u.user_id FROM users u WHERE {$examineeWhere} AND {$scopeSql}");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $ids[(int)($row['user_id'] ?? 0)] = true;
            }
            mysqli_free_result($q);
        }
    } else {
        if ($mode === 'users' || $mode === 'sections_and_users') {
            foreach (examination_load_assigned_user_ids($conn, $examType, $sourceId) as $uid) {
                $ids[$uid] = true;
            }
        }
        if ($mode === 'sections' || $mode === 'sections_and_users') {
            $sections = examination_load_assigned_sections($conn, $examType, $sourceId);
            if ($sections !== []) {
                // Case-insensitive section match via PHP filter (section names are few).
                $qAll = @mysqli_query($conn, "SELECT u.user_id, TRIM(COALESCE(u.section,'')) AS section FROM users u WHERE {$examineeWhere} AND {$scopeSql}");
                if ($qAll) {
                    while ($row = mysqli_fetch_assoc($qAll)) {
                        if (examination_section_is_in_list((string)($row['section'] ?? ''), $sections)) {
                            $ids[(int)($row['user_id'] ?? 0)] = true;
                        }
                    }
                    mysqli_free_result($qAll);
                }
            }
        }
        if ($mode === 'users') {
            $clean = [];
            foreach (array_keys($ids) as $uid) {
                $u = diagnostic_exam_load_examinee_user($conn, (int)$uid);
                if ($u && diagnostic_exam_user_matches_examinee_scope($u, $scope)) {
                    $clean[] = (int)$uid;
                }
            }
            sort($clean);

            return $clean;
        }
    }

    $out = array_keys($ids);
    sort($out);

    return array_values(array_filter($out, static fn($id) => $id > 0));
}

/**
 * User IDs on professor monitor roster: assigned examinees ∪ anyone with an attempt.
 *
 * @return list<int>
 */
function examination_assigned_roster_user_ids(mysqli $conn, string $examType, int $sourceId): array
{
    $ids = array_fill_keys(examination_pure_assigned_user_ids($conn, $examType, $sourceId), true);
    $examType = examination_exam_type_normalize($examType);
    if ($sourceId <= 0) {
        return [];
    }

    $attemptSql = $examType === 'diagnostic'
        ? 'SELECT user_id FROM diagnostic_attempts WHERE batch_id=' . (int)$sourceId
        : 'SELECT user_id FROM college_exam_attempts WHERE exam_id=' . (int)$sourceId;
    $ar = @mysqli_query($conn, $attemptSql);
    if ($ar) {
        while ($row = mysqli_fetch_assoc($ar)) {
            $ids[(int)($row['user_id'] ?? 0)] = true;
        }
        mysqli_free_result($ar);
    }

    $out = array_keys($ids);
    sort($out);

    return array_values(array_filter($out, static fn($id) => $id > 0));
}

function examination_count_assigned_examinees(mysqli $conn, string $examType, int $sourceId): int
{
    return count(examination_pure_assigned_user_ids($conn, $examType, $sourceId));
}

function examination_validate_assignment_for_publish(string $assignmentMode, array $sections, array $userIds): ?string
{
    $mode = examination_normalize_assignment_mode($assignmentMode);
    if ($mode === 'sections' || $mode === 'sections_and_users') {
        if ($sections === []) {
            return 'Add at least one section.';
        }
    }
    if ($mode === 'users' || $mode === 'sections_and_users') {
        if ($userIds === []) {
            return 'Select at least one student.';
        }
    }

    return null;
}

function examination_assignment_mutations_locked(mysqli $conn, string $examType, int $sourceId): bool
{
    require_once __DIR__ . '/examination_questions.php';

    return examination_questions_attempt_count($conn, $examType, $sourceId) > 0;
}

/**
 * Normalize a regular or diagnostic examination record for student list views.
 *
 * @param array<string,mixed> $record
 * @return array<string,mixed>
 */
function examination_student_normalize_record(array $record, string $examType): array
{
    $examType = examination_exam_type_normalize($examType);
    $sourceId = (int)($record[$examType === 'diagnostic' ? 'batch_id' : 'exam_id'] ?? 0);

    return [
        'exam_type' => $examType,
        'source_id' => $sourceId,
        'exam_id' => $examType === 'regular' ? $sourceId : 0,
        'batch_id' => $examType === 'diagnostic' ? $sourceId : 0,
        'title' => (string)($record['title'] ?? 'Untitled'),
        'description' => trim((string)($record['description'] ?? '')),
        'available_from' => $record['available_from'] ?? null,
        'deadline' => $record['deadline'] ?? null,
        'time_limit_seconds' => max(0, (int)($record['time_limit_seconds'] ?? 0)),
        'created_at' => $record['created_at'] ?? $record['updated_at'] ?? null,
        'created_by' => (int)($record['created_by'] ?? 0),
        '_record' => $record,
    ];
}

function examination_student_take_url(string $examType, int $sourceId, bool $review = false): string
{
    $examType = examination_exam_type_normalize($examType);
    if ($sourceId <= 0) {
        return 'college_exams';
    }
    if ($examType === 'diagnostic') {
        $url = 'college_diagnostic_take?batch_id=' . $sourceId;
    } else {
        $url = 'college_take_exam?exam_id=' . $sourceId;
    }
    if ($review) {
        $url .= '&review=1';
    }

    return $url;
}

function examination_student_relative_deadline(?string $deadline, string $nowSql): string
{
    if ($deadline === null || trim($deadline) === '') {
        return 'No deadline';
    }
    $d = strtotime($deadline);
    $n = strtotime($nowSql);
    if ($d === false || $n === false) {
        return '-';
    }
    $diff = $d - $n;
    $abs = abs($diff);
    $days = (int)floor($abs / 86400);
    $hours = (int)floor(($abs % 86400) / 3600);
    $mins = (int)floor(($abs % 3600) / 60);
    if ($diff >= 0) {
        if ($days > 0) {
            return 'Due in ' . $days . 'd ' . $hours . 'h';
        }
        if ($hours > 0) {
            return 'Due in ' . $hours . 'h ' . $mins . 'm';
        }

        return 'Due in ' . max(1, $mins) . 'm';
    }
    if ($days > 0) {
        return $days . 'd late';
    }
    if ($hours > 0) {
        return $hours . 'h late';
    }

    return max(1, $mins) . 'm late';
}

/**
 * Resolve student-facing status bucket from schedule + attempt state.
 *
 * @param array<string,mixed> $item Normalized row with attempt_status, _submitted_count, _record
 */
function examination_student_resolve_bucket(mysqli $conn, array $item, string $nowSql): string
{
    require_once __DIR__ . '/college_exam_helpers.php';

    $st = (string)($item['attempt_status'] ?? '');
    $examType = examination_exam_type_normalize((string)($item['exam_type'] ?? 'regular'));
    $isAvail = examination_record_is_published($item['_record'], $examType)
        && examination_record_schedule_is_open($item, $nowSql);

    $globalDoneNoDeadline = false;
    if ($examType === 'regular') {
        $globalDoneNoDeadline = college_exam_finished_all_submitted_no_deadline(
            $conn,
            $item['_record'],
            (int)($item['_submitted_count'] ?? 0)
        );
        if ($globalDoneNoDeadline && $st !== 'in_progress') {
            $isAvail = false;
        }
    }

    $isUpcoming = !empty($item['available_from']) && (string)$item['available_from'] > $nowSql;
    $isMissed = !empty($item['deadline']) && (string)$item['deadline'] < $nowSql && $st !== 'submitted';
    $terminalExpired = $st === 'expired' && !empty($item['submitted_at']);

    if ($st === 'submitted' || $terminalExpired) {
        return 'finished';
    }
    if ($examType === 'regular' && $globalDoneNoDeadline && $st === 'in_progress') {
        return 'open';
    }
    if ($isMissed || $st === 'expired') {
        return 'missed';
    }
    if ($isUpcoming) {
        return 'upcoming';
    }
    if ($isAvail) {
        return 'open';
    }
    if ($globalDoneNoDeadline) {
        return 'missed';
    }

    return 'missed';
}

/**
 * @return array{key:string,label:string}
 */
function examination_student_status_meta(array $item, string $bucket): array
{
    $st = (string)($item['attempt_status'] ?? '');
    if ($st === 'submitted' || ($st === 'expired' && !empty($item['submitted_at']))) {
        return ['key' => 'submitted', 'label' => 'Submitted'];
    }
    if ($st === 'in_progress') {
        return ['key' => 'in_progress', 'label' => 'In progress'];
    }
    if ($bucket === 'open') {
        return ['key' => 'open', 'label' => 'Open'];
    }
    if ($bucket === 'upcoming') {
        return ['key' => 'upcoming', 'label' => 'Upcoming'];
    }
    if ($bucket === 'finished') {
        return ['key' => 'finished', 'label' => 'Finished'];
    }
    if ($st === 'expired' || $bucket === 'missed') {
        return ['key' => 'missed', 'label' => 'Missed'];
    }

    return ['key' => 'locked', 'label' => 'Locked'];
}

/**
 * @return array{mode:string,url:string,label:string}
 */
function examination_student_action_meta(array $item, string $bucket): array
{
    $st = (string)($item['attempt_status'] ?? '');
    $examType = examination_exam_type_normalize((string)($item['exam_type'] ?? 'regular'));
    $sourceId = (int)($item['source_id'] ?? 0);

    if ($st === 'submitted' || ($st === 'expired' && !empty($item['submitted_at']))) {
        return [
            'mode' => 'review',
            'url' => examination_student_take_url($examType, $sourceId, true),
            'label' => 'View result',
        ];
    }
    if ($st === 'in_progress') {
        return [
            'mode' => 'continue',
            'url' => examination_student_take_url($examType, $sourceId, false),
            'label' => 'Continue',
        ];
    }
    if ($bucket === 'open') {
        return [
            'mode' => 'start',
            'url' => examination_student_take_url($examType, $sourceId, false),
            'label' => 'Take exam',
        ];
    }
    if ($bucket === 'upcoming') {
        return ['mode' => 'none', 'url' => '', 'label' => 'Not yet available'];
    }

    return ['mode' => 'closed', 'url' => '', 'label' => 'Closed'];
}

/**
 * Published regular + diagnostic examinations assigned to the student (visibility only).
 *
 * @return list<array<string,mixed>>
 */
function examination_student_load_assigned_exams(mysqli $conn, int $userId, string $nowSql): array
{
    require_once __DIR__ . '/college_exam_helpers.php';

    if ($userId <= 0) {
        return [];
    }

    $items = [];
    foreach (college_exams_load_assigned_published_exams_for_user($conn, $userId) as $exam) {
        $items[] = examination_student_normalize_record($exam, 'regular');
    }
    foreach (diagnostic_exam_load_eligible_batches_for_user($conn, $userId, $nowSql) as $batch) {
        $items[] = examination_student_normalize_record($batch, 'diagnostic');
    }

    if ($items === []) {
        return [];
    }

    $regularIds = [];
    $diagIds = [];
    foreach ($items as $item) {
        if (($item['exam_type'] ?? '') === 'diagnostic') {
            $diagIds[] = (int)$item['source_id'];
        } else {
            $regularIds[] = (int)$item['source_id'];
        }
    }
    $regularIds = array_values(array_unique(array_filter($regularIds, static fn($id) => $id > 0)));
    $diagIds = array_values(array_unique(array_filter($diagIds, static fn($id) => $id > 0)));

    $attemptByRegular = [];
    if ($regularIds !== []) {
        $inSql = implode(',', $regularIds);
        $aq = mysqli_query($conn, "
          SELECT exam_id, status AS attempt_status, score, correct_count, total_count, submitted_at, started_at
          FROM college_exam_attempts
          WHERE user_id = {$userId} AND exam_id IN ({$inSql})
        ");
        if ($aq) {
            while ($ar = mysqli_fetch_assoc($aq)) {
                $attemptByRegular[(int)($ar['exam_id'] ?? 0)] = $ar;
            }
            mysqli_free_result($aq);
        }
    }

    $attemptByDiagnostic = [];
    if ($diagIds !== []) {
        $inSql = implode(',', $diagIds);
        $aq = mysqli_query($conn, "
          SELECT batch_id, status AS attempt_status, score, correct_count, total_count, submitted_at, started_at
          FROM diagnostic_attempts
          WHERE user_id = {$userId} AND batch_id IN ({$inSql})
        ");
        if ($aq) {
            while ($ar = mysqli_fetch_assoc($aq)) {
                $attemptByDiagnostic[(int)($ar['batch_id'] ?? 0)] = $ar;
            }
            mysqli_free_result($aq);
        }
    }

    $qCountRegular = [];
    if ($regularIds !== []) {
        $inSql = implode(',', $regularIds);
        $qcr = mysqli_query($conn, "SELECT exam_id, COUNT(*) AS c FROM college_exam_questions WHERE exam_id IN ({$inSql}) GROUP BY exam_id");
        if ($qcr) {
            while ($qr = mysqli_fetch_assoc($qcr)) {
                $qCountRegular[(int)$qr['exam_id']] = (int)($qr['c'] ?? 0);
            }
            mysqli_free_result($qcr);
        }
    }

    $qCountDiagnostic = [];
    foreach ($diagIds as $bid) {
        $stats = diagnostic_exam_batch_stats_for_student($conn, (int)$bid);
        $qCountDiagnostic[(int)$bid] = (int)($stats['question_count'] ?? 0);
    }

    $submittedCountRegular = [];
    if ($regularIds !== []) {
        $inSql = implode(',', $regularIds);
        $sqc = mysqli_query($conn, "
          SELECT exam_id,
            SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count
          FROM college_exam_attempts
          WHERE exam_id IN ({$inSql})
          GROUP BY exam_id
        ");
        if ($sqc) {
            while ($sr = mysqli_fetch_assoc($sqc)) {
                $submittedCountRegular[(int)$sr['exam_id']] = (int)($sr['submitted_count'] ?? 0);
            }
            mysqli_free_result($sqc);
        }
    }

    $out = [];
    foreach ($items as $item) {
        $examType = (string)($item['exam_type'] ?? 'regular');
        $sourceId = (int)($item['source_id'] ?? 0);
        if ($examType === 'diagnostic') {
            $attempt = $attemptByDiagnostic[$sourceId] ?? null;
            $item['_q_count'] = (int)($qCountDiagnostic[$sourceId] ?? 0);
            $item['_submitted_count'] = 0;
        } else {
            $attempt = $attemptByRegular[$sourceId] ?? null;
            $item['_q_count'] = (int)($qCountRegular[$sourceId] ?? 0);
            $item['_submitted_count'] = (int)($submittedCountRegular[$sourceId] ?? 0);
        }
        if ($attempt) {
            $item = array_merge($item, $attempt);
        } else {
            $item['attempt_status'] = null;
            $item['score'] = null;
            $item['correct_count'] = null;
            $item['total_count'] = null;
            $item['submitted_at'] = null;
            $item['started_at'] = null;
        }
        $item['_bucket'] = examination_student_resolve_bucket($conn, $item, $nowSql);
        $statusMeta = examination_student_status_meta($item, (string)$item['_bucket']);
        $item['_status_key'] = $statusMeta['key'];
        $item['_status_label'] = $statusMeta['label'];
        $actionMeta = examination_student_action_meta($item, (string)$item['_bucket']);
        $item['_action_mode'] = $actionMeta['mode'];
        $item['_action_url'] = $actionMeta['url'];
        $item['_action_label'] = $actionMeta['label'];
        $item['_relative_deadline'] = examination_student_relative_deadline(
            isset($item['deadline']) ? (string)$item['deadline'] : null,
            $nowSql
        );
        $out[] = $item;
    }

    return $out;
}
