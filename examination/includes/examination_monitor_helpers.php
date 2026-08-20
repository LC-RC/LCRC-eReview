<?php
declare(strict_types=1);

/**
 * Unified Examination monitoring adapter.
 * Reads college_exams + diagnostic_batches (and their attempts) without changing take flows.
 */

require_once __DIR__ . '/college_exam_helpers.php';
require_once __DIR__ . '/diagnostic_schema.php';
require_once __DIR__ . '/diagnostic_exam_helpers.php';

function examination_monitor_format_dt(mixed $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $s = trim((string)$raw);
    if ($s === '' || preg_match('/^0000-00-00(\s00:00:00)?$/', $s)) {
        return '';
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return '';
    }

    return date('M j, Y g:i A', $ts);
}

/**
 * Compact two-line date/time for dense monitor tables (presentation only).
 */
function examination_monitor_format_dt_html(mixed $raw): string
{
    $fmt = examination_monitor_format_dt($raw);
    if ($fmt === '') {
        return '';
    }
    if (preg_match('/^(.+,\s*\d{4})\s+(.+)$/', $fmt, $m)) {
        return '<span class="pem-dt"><span class="pem-dt__d">' . h($m[1]) . '</span><span class="pem-dt__t">' . h($m[2]) . '</span></span>';
    }

    return '<span class="pem-dt">' . h($fmt) . '</span>';
}

function examination_monitor_normalize_exam_type(string $type): string
{
    $t = strtolower(trim($type));
    if ($t === 'regular' || $t === 'college_exam') {
        return 'college_exam';
    }
    return $t === 'diagnostic' ? 'diagnostic' : '';
}

function examination_monitor_exam_type_label(string $type): string
{
    return examination_monitor_normalize_exam_type($type) === 'diagnostic' ? 'Diagnostic Exam' : 'Regular Exam';
}

function examination_monitor_parse_scope(array $query): ?array
{
    $examType = strtolower(trim((string)($query['exam_type'] ?? '')));
    if ($examType === 'regular') {
        $examType = 'college_exam';
    }
    $examType = examination_monitor_normalize_exam_type($examType);
    $examId = (int)($query['exam_id'] ?? 0);
    $batchId = (int)($query['batch_id'] ?? 0);

    if ($examType === '' && $examId > 0) {
        $examType = 'regular';
    }
    if ($examType === 'regular') {
        $examType = 'college_exam';
    }
    if ($examType === '' && $batchId > 0) {
        $examType = 'diagnostic';
    }
    if ($examType === 'college_exam' && $examId > 0) {
        return ['exam_type' => 'college_exam', 'assessment_id' => $examId];
    }
    if ($examType === 'diagnostic' && $batchId > 0) {
        return ['exam_type' => 'diagnostic', 'assessment_id' => $batchId];
    }

    return null;
}

function examination_monitor_live_url(array $scope): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor_live?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    return 'professor_examination_monitor_live?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];
}

function examination_monitor_pdf_url(array $scope): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor_pdf?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    return 'professor_examination_monitor_pdf?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];
}

function examination_monitor_xlsx_url(array $scope): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor_xlsx?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    return 'professor_examination_monitor_xlsx?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];
}

function examination_monitor_scoped_url(array $scope): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    return 'professor_examination_monitor?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];
}

function examination_monitor_load_context(mysqli $conn, int $professorId, string $examType, int $assessmentId): ?array
{
    $examType = examination_monitor_normalize_exam_type($examType);
    if ($examType === '' || $assessmentId <= 0) {
        return null;
    }

    if ($examType === 'college_exam') {
        $st = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1');
        mysqli_stmt_bind_param($st, 'ii', $assessmentId, $professorId);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
        if (!$row) {
            return null;
        }
        $qCount = 0;
        $qc = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . (int)$assessmentId);
        if ($qc && ($qr = mysqli_fetch_assoc($qc))) {
            $qCount = (int)($qr['c'] ?? 0);
        }
        if ($qc) {
            mysqli_free_result($qc);
        }

        return [
            'exam_type' => 'college_exam',
            'assessment_id' => $assessmentId,
            'title' => (string)($row['title'] ?? ''),
            'row' => $row,
            'question_count' => $qCount,
            'supports_live' => true,
            'supports_review_sheet' => true,
            'supports_pass_fail' => true,
            'supports_tab_tracking' => true,
            'back_url' => 'professor_examinations',
            'back_label' => 'Back to Examinations',
            'subtitle' => 'Live monitoring for this examination.',
        ];
    }

    $row = diagnostic_exam_load_batch($conn, $assessmentId, $professorId);
    if (!$row) {
        return null;
    }
    $stats = diagnostic_exam_batch_stats_for_student($conn, $assessmentId);

    return [
        'exam_type' => 'diagnostic',
        'assessment_id' => $assessmentId,
        'title' => (string)($row['title'] ?? ''),
        'row' => $row,
        'question_count' => (int)($stats['question_count'] ?? 0),
        'supports_live' => true,
        'supports_review_sheet' => false,
        'supports_pass_fail' => true,
        'supports_tab_tracking' => true,
        'back_url' => 'professor_examinations',
        'back_label' => 'Back to Examinations',
        'subtitle' => diagnostic_exam_examinee_scope_label((string)($row['examinee_scope'] ?? 'college_student'))
            . ' · ' . diagnostic_exam_assignment_mode_label((string)($row['assignment_mode'] ?? 'sections')),
    ];
}

function examination_monitor_list_assessments(mysqli $conn, int $professorId, array $filters = []): array
{
    $typeFilter = examination_monitor_normalize_exam_type((string)($filters['exam_type'] ?? ''));
    $sectionFilter = trim((string)($filters['section'] ?? ''));
    $examineeType = trim((string)($filters['examinee_type'] ?? ''));
    $statusFilter = strtolower(trim((string)($filters['status'] ?? '')));
    $q = trim((string)($filters['q'] ?? ''));
    $now = date('Y-m-d H:i:s');

    $out = [];

    if ($typeFilter === '' || $typeFilter === 'college_exam') {
        $sql = 'SELECT exam_id AS assessment_id, title, is_published, available_from, deadline, created_at, updated_at FROM college_exams WHERE created_by=' . (int)$professorId;
        if ($q !== '') {
            $esc = '%' . mysqli_real_escape_string($conn, $q) . '%';
            $sql .= " AND title LIKE '{$esc}'";
        }
        $sql .= ' ORDER BY updated_at DESC';
        $res = @mysqli_query($conn, $sql);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $aid = (int)($r['assessment_id'] ?? 0);
                $metrics = examination_monitor_metrics_for_college_exam($conn, $aid);
                $roster = college_exam_professor_roster_count($conn, $aid);
                $out[] = [
                    'exam_type' => 'college_exam',
                    'assessment_id' => $aid,
                    'title' => (string)($r['title'] ?? ''),
                    'is_published' => !empty($r['is_published']),
                    'available_from' => (string)($r['available_from'] ?? ''),
                    'deadline' => (string)($r['deadline'] ?? ''),
                    'roster_count' => $roster,
                    'taking_count' => (int)($metrics['taking_count'] ?? 0),
                    'submitted_count' => (int)($metrics['submitted_count'] ?? 0),
                    'avg_score' => $metrics['avg_score'] ?? null,
                    'scope' => examination_monitor_scoped_url(['exam_type' => 'college_exam', 'assessment_id' => $aid]),
                    'window_state' => examination_monitor_window_state($r, $now),
                ];
            }
            mysqli_free_result($res);
        }
    }

    if ($typeFilter === '' || $typeFilter === 'diagnostic') {
        $res = @mysqli_query($conn, 'SELECT * FROM diagnostic_batches WHERE created_by=' . (int)$professorId . ' ORDER BY updated_at DESC');
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                if ($q !== '') {
                    $needle = mb_strtolower($q);
                    if (mb_strpos(mb_strtolower((string)($r['title'] ?? '')), $needle) === false) {
                        continue;
                    }
                }
                $aid = (int)($r['batch_id'] ?? 0);
                $metrics = examination_monitor_metrics_for_diagnostic($conn, $aid);
                $roster = diagnostic_exam_count_assigned_examinees($conn, $aid);
                $out[] = [
                    'exam_type' => 'diagnostic',
                    'assessment_id' => $aid,
                    'title' => (string)($r['title'] ?? ''),
                    'is_published' => !empty($r['is_published']),
                    'available_from' => (string)($r['available_from'] ?? ''),
                    'deadline' => (string)($r['deadline'] ?? ''),
                    'examinee_scope' => (string)($r['examinee_scope'] ?? 'college_student'),
                    'assignment_mode' => (string)($r['assignment_mode'] ?? 'sections'),
                    'roster_count' => $roster,
                    'taking_count' => (int)($metrics['taking_count'] ?? 0),
                    'submitted_count' => (int)($metrics['submitted_count'] ?? 0),
                    'avg_score' => $metrics['avg_score'] ?? null,
                    'scope' => examination_monitor_scoped_url(['exam_type' => 'diagnostic', 'assessment_id' => $aid]),
                    'window_state' => examination_monitor_window_state($r, $now),
                ];
            }
            mysqli_free_result($res);
        }
    }

    if ($sectionFilter !== '' || $examineeType !== '' || $statusFilter !== '') {
        $out = array_values(array_filter($out, static function ($item) use ($conn, $sectionFilter, $examineeType, $statusFilter) {
            $scope = ['exam_type' => $item['exam_type'], 'assessment_id' => (int)$item['assessment_id']];
            $rows = examination_monitor_progress_rows($conn, $scope);
            foreach ($rows as $row) {
                if ($sectionFilter !== '' && stripos(trim((string)($row['section'] ?? '')), $sectionFilter) === false) {
                    continue;
                }
                if ($examineeType === 'college_student' && strtolower(trim((string)($row['review_type'] ?? ''))) !== 'undergrad') {
                    continue;
                }
                if ($examineeType === 'reviewee' && strtolower(trim((string)($row['review_type'] ?? ''))) !== 'reviewee') {
                    continue;
                }
                $st = examination_monitor_normalized_attempt_status((string)($row['attempt_status'] ?? ''));
                if ($statusFilter !== '' && $st !== $statusFilter) {
                    continue;
                }
                return true;
            }
            return $sectionFilter === '' && $examineeType === '' && $statusFilter === '';
        }));
    }

    return $out;
}

function examination_monitor_window_state(array $row, string $nowSql): string
{
    if (empty($row['is_published'])) {
        return 'draft';
    }
    if (!empty($row['available_from']) && (string)$row['available_from'] > $nowSql) {
        return 'scheduled';
    }
    if (!empty($row['deadline']) && (string)$row['deadline'] < $nowSql) {
        return 'closed';
    }

    return 'open';
}

function examination_monitor_normalized_attempt_status(string $status): string
{
    $st = strtolower(trim($status));
    if ($st === 'in_progress') {
        return 'in_progress';
    }
    if ($st === 'submitted' || $st === 'expired') {
        return 'submitted';
    }

    return 'not_started';
}

function examination_monitor_finalize_expired(mysqli $conn, array $scope): int
{
    if ($scope['exam_type'] === 'college_exam') {
        return college_exam_finalize_expired_in_progress($conn, (int)$scope['assessment_id'], 0, 0);
    }

    return diagnostic_exam_finalize_expired_in_progress($conn, (int)$scope['assessment_id'], 0);
}

function examination_monitor_roster_count(mysqli $conn, array $scope): int
{
    if ($scope['exam_type'] === 'college_exam') {
        return college_exam_professor_roster_count($conn, (int)$scope['assessment_id']);
    }

    return diagnostic_exam_count_assigned_examinees($conn, (int)$scope['assessment_id']);
}

function examination_monitor_metrics(mysqli $conn, array $scope): array
{
    if ($scope['exam_type'] === 'college_exam') {
        return examination_monitor_metrics_for_college_exam($conn, (int)$scope['assessment_id']);
    }

    return examination_monitor_metrics_for_diagnostic($conn, (int)$scope['assessment_id']);
}

function examination_monitor_metrics_for_college_exam(mysqli $conn, int $examId): array
{
    $metrics = [
        'taking_count' => 0,
        'submitted_count' => 0,
        'avg_score' => null,
        'pass_count' => 0,
        'fail_count' => 0,
    ];
    $mq = @mysqli_query($conn, "
      SELECT
        SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS taking_count,
        SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count,
        AVG(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          THEN (50 + 0.5 * (100.0 * COALESCE(correct_count,0) / total_count))
          WHEN status='submitted' THEN score END) AS avg_score,
        SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          AND COALESCE(correct_count,0) >= CEILING(total_count / 2) THEN 1
          ELSE 0 END) AS pass_count,
        SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          AND COALESCE(correct_count,0) < CEILING(total_count / 2) THEN 1
          WHEN status='submitted' AND IFNULL(total_count,0) <= 0 THEN 1
          ELSE 0 END) AS fail_count
      FROM college_exam_attempts
      WHERE exam_id=" . (int)$examId
    );
    if ($mq && ($m = mysqli_fetch_assoc($mq))) {
        $metrics['taking_count'] = (int)($m['taking_count'] ?? 0);
        $metrics['submitted_count'] = (int)($m['submitted_count'] ?? 0);
        $metrics['avg_score'] = $m['avg_score'] !== null ? (float)$m['avg_score'] : null;
        $metrics['pass_count'] = (int)($m['pass_count'] ?? 0);
        $metrics['fail_count'] = (int)($m['fail_count'] ?? 0);
    }
    if ($mq) {
        mysqli_free_result($mq);
    }

    return $metrics;
}

function examination_monitor_metrics_for_diagnostic(mysqli $conn, int $batchId): array
{
    $metrics = [
        'taking_count' => 0,
        'submitted_count' => 0,
        'avg_score' => null,
        'pass_count' => 0,
        'fail_count' => 0,
    ];
    $mq = @mysqli_query($conn, "
      SELECT
        SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS taking_count,
        SUM(CASE WHEN status IN ('submitted','expired') THEN 1 ELSE 0 END) AS submitted_count,
        AVG(CASE WHEN status IN ('submitted','expired') AND score IS NOT NULL THEN score END) AS avg_score,
        SUM(CASE WHEN status IN ('submitted','expired') AND score IS NOT NULL AND score >= 50 THEN 1 ELSE 0 END) AS pass_count,
        SUM(CASE WHEN status IN ('submitted','expired') AND (score IS NULL OR score < 50) THEN 1 ELSE 0 END) AS fail_count
      FROM diagnostic_attempts
      WHERE batch_id=" . (int)$batchId
    );
    if ($mq && ($m = mysqli_fetch_assoc($mq))) {
        $metrics['taking_count'] = (int)($m['taking_count'] ?? 0);
        $metrics['submitted_count'] = (int)($m['submitted_count'] ?? 0);
        $metrics['avg_score'] = $m['avg_score'] !== null ? (float)$m['avg_score'] : null;
        $metrics['pass_count'] = (int)($m['pass_count'] ?? 0);
        $metrics['fail_count'] = (int)($m['fail_count'] ?? 0);
    }
    if ($mq) {
        mysqli_free_result($mq);
    }

    return $metrics;
}

function examination_monitor_diagnostic_roster_user_ids(mysqli $conn, int $batchId): array
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_assigned_roster_user_ids($conn, 'diagnostic', $batchId);
}

function examination_monitor_progress_rows(mysqli $conn, array $scope): array
{
    if ($scope['exam_type'] === 'college_exam') {
        return examination_monitor_college_progress_rows($conn, (int)$scope['assessment_id']);
    }

    return examination_monitor_diagnostic_progress_rows($conn, (int)$scope['assessment_id']);
}

function examination_monitor_college_progress_rows(mysqli $conn, int $examId): array
{
    require_once __DIR__ . '/examination_eligibility.php';
    require_once __DIR__ . '/college_exam_attempt_events.php';
    college_exam_attempt_events_ensure_schema($conn);
    $userIds = examination_assigned_roster_user_ids($conn, 'regular', $examId);
    if ($userIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $userIds));
    $rows = [];
    $sql = "
      SELECT
        u.user_id, u.full_name, u.email, u.student_number, u.section, u.review_type, u.status AS user_status,
        a.attempt_id, a.status AS attempt_status, a.score, a.correct_count, a.total_count, a.started_at, a.submitted_at, a.last_seen_at,
        a.expires_at, a.ui_state_json, a.tab_switch_count, a.last_tab_switch_at,
        (SELECT COUNT(*) FROM college_exam_answers ans
          WHERE ans.attempt_id = a.attempt_id AND ans.selected_answer IS NOT NULL AND TRIM(ans.selected_answer) <> '') AS answered_live,
        (SELECT COUNT(*) FROM college_exam_answers ans2
          WHERE ans2.attempt_id = a.attempt_id AND ans2.is_correct = 1) AS correct_live,
        (SELECT e.event_type FROM college_exam_attempt_events e
          WHERE e.attempt_id = a.attempt_id
          ORDER BY e.occurred_at DESC, e.event_id DESC LIMIT 1) AS last_tab_event
      FROM users u
      LEFT JOIN college_exam_attempts a ON a.user_id=u.user_id AND a.exam_id=" . (int)$examId . "
      WHERE u.user_id IN ({$in})
      ORDER BY u.full_name ASC
    ";
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }

    return $rows;
}

function examination_monitor_diagnostic_progress_rows(mysqli $conn, int $batchId): array
{
    $userIds = examination_monitor_diagnostic_roster_user_ids($conn, $batchId);
    if ($userIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $userIds));
    $rows = [];
    $sql = "
      SELECT
        u.user_id, u.full_name, u.email, u.student_number, u.section, u.review_type, u.status AS user_status,
        a.attempt_id, a.status AS attempt_status, a.score, a.correct_count, a.total_count, a.started_at, a.submitted_at, a.last_seen_at,
        a.tab_switch_count, a.last_tab_switch_at
      FROM users u
      LEFT JOIN diagnostic_attempts a ON a.user_id=u.user_id AND a.batch_id=" . (int)$batchId . "
      WHERE u.user_id IN ({$in})
      ORDER BY u.full_name ASC
    ";
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }

    return $rows;
}

function examination_monitor_running_finished(mysqli $conn, array $ctx, array $metrics, string $nowSql): array
{
    $row = $ctx['row'];
    if ($ctx['exam_type'] === 'college_exam') {
        $allFinishedOpenExam = college_exam_finished_all_submitted_no_deadline($conn, $row, (int)($metrics['submitted_count'] ?? 0));
        $isRunning = !empty($row['is_published'])
            && (empty($row['available_from']) || (string)$row['available_from'] <= $nowSql)
            && (empty($row['deadline']) || (string)$row['deadline'] >= $nowSql)
            && !$allFinishedOpenExam;
        $isFinished = (!empty($row['deadline']) && (string)$row['deadline'] < $nowSql) || $allFinishedOpenExam;

        return ['is_running' => $isRunning, 'is_finished' => $isFinished, 'all_finished_open' => $allFinishedOpenExam];
    }

    $assigned = examination_monitor_roster_count($conn, ['exam_type' => 'diagnostic', 'assessment_id' => (int)$ctx['assessment_id']]);
    $submitted = (int)($metrics['submitted_count'] ?? 0);
    $inProgress = (int)($metrics['taking_count'] ?? 0);
    $allDone = $assigned > 0 && $submitted + $inProgress >= $assigned && $inProgress === 0;
    $isRunning = !empty($row['is_published'])
        && (empty($row['available_from']) || (string)$row['available_from'] <= $nowSql)
        && (empty($row['deadline']) || (string)$row['deadline'] >= $nowSql)
        && !$allDone;
    $isFinished = (!empty($row['deadline']) && (string)$row['deadline'] < $nowSql) || $allDone;

    return ['is_running' => $isRunning, 'is_finished' => $isFinished, 'all_finished_open' => $allDone && empty($row['deadline'])];
}

function examination_monitor_export_rows(mysqli $conn, array $ctx, bool $isFinished): array
{
    if ($ctx['exam_type'] === 'college_exam') {
        require_once __DIR__ . '/exam_monitor_progress_rows.php';

        return exam_monitor_progress_export_rows($conn, (int)$ctx['assessment_id'], (int)$ctx['question_count'], $isFinished);
    }

    $rows = [];
    foreach (examination_monitor_diagnostic_progress_rows($conn, (int)$ctx['assessment_id']) as $st) {
        $attemptStatus = (string)($st['attempt_status'] ?? '');
        if ($isFinished && !in_array($attemptStatus, ['submitted', 'expired'], true)) {
            $status = 'Failed (Absent)';
            $score = '-';
            $mark = '-';
        } elseif ($attemptStatus === 'in_progress') {
            $status = 'Taking';
            $score = '-';
            $mark = '-';
        } elseif (in_array($attemptStatus, ['submitted', 'expired'], true)) {
            $status = 'Submitted';
            $pct = $st['score'] !== null ? round((float)$st['score'], 2) : null;
            $score = $pct !== null ? (string)$pct . '%' : '-';
            $mark = ($pct !== null && $pct >= 50) ? 'Pass' : 'Fail';
        } else {
            $status = 'Not started';
            $score = '-';
            $mark = '-';
        }
        $rows[] = [
            'student_number' => trim((string)($st['student_number'] ?? '')),
            'name' => (string)($st['full_name'] ?? ''),
            'email' => (string)($st['email'] ?? ''),
            'status' => $status,
            'score' => $score,
            'mark' => $mark,
        ];
    }

    return $rows;
}

function examination_monitor_idle_threshold_seconds(): int
{
    return 60;
}

function examination_monitor_disconnected_threshold_seconds(): int
{
    return 180;
}

/**
 * Derive live presence status for an in-progress attempt.
 *
 * @return 'not_started'|'active'|'idle'|'disconnected'|'submitted'|'expired'
 */
function examination_monitor_presence_status(?string $attemptStatus, ?string $lastSeenAt, ?int $nowTs = null): string
{
    $st = strtolower(trim((string)$attemptStatus));
    if ($st === '' || $st === null) {
        return 'not_started';
    }
    if ($st === 'submitted') {
        return 'submitted';
    }
    if ($st === 'expired') {
        return 'expired';
    }
    if ($st !== 'in_progress') {
        return 'not_started';
    }
    $now = $nowTs ?? time();
    if ($lastSeenAt === null || trim($lastSeenAt) === '') {
        return 'disconnected';
    }
    $seen = strtotime($lastSeenAt);
    if ($seen === false) {
        return 'disconnected';
    }
    $age = max(0, $now - $seen);
    if ($age >= examination_monitor_disconnected_threshold_seconds()) {
        return 'disconnected';
    }
    if ($age >= examination_monitor_idle_threshold_seconds()) {
        return 'idle';
    }

    return 'active';
}

function examination_monitor_format_remaining(?string $expiresAt, ?int $nowTs = null): ?string
{
    if ($expiresAt === null || trim((string)$expiresAt) === '') {
        return null;
    }
    $exp = strtotime((string)$expiresAt);
    if ($exp === false) {
        return null;
    }
    $now = $nowTs ?? time();
    $sec = max(0, $exp - $now);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    return sprintf('%d:%02d', $m, $s);
}

function examination_monitor_live_payload(mysqli $conn, array $ctx): array
{
    $scope = ['exam_type' => $ctx['exam_type'], 'assessment_id' => (int)$ctx['assessment_id']];
    if (!empty($_GET['tab_events_attempt_id'])) {
        $aid = (int)$_GET['tab_events_attempt_id'];
        require_once __DIR__ . '/college_exam_attempt_events.php';
        $events = [];
        $activity = [];
        if ($ctx['exam_type'] === 'college_exam' && $aid > 0) {
            $chk = mysqli_prepare($conn, 'SELECT attempt_id FROM college_exam_attempts WHERE attempt_id=? AND exam_id=? LIMIT 1');
            if ($chk) {
                $examId = (int)$ctx['assessment_id'];
                mysqli_stmt_bind_param($chk, 'ii', $aid, $examId);
                mysqli_stmt_execute($chk);
                $okRow = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
                mysqli_stmt_close($chk);
                if ($okRow) {
                    $raw = college_exam_attempt_events_list($conn, $aid, 200);
                    $events = college_exam_attempt_events_tab_timeline($raw);
                    $activity = college_exam_attempt_events_activity_timeline($raw);
                }
            }
        }

        return [
            'ok' => true,
            'attempt_id' => $aid,
            'tab_events' => $events,
            'activity_timeline' => $activity,
        ];
    }

    $autoFinalized = examination_monitor_finalize_expired($conn, $scope);
    $metrics = examination_monitor_metrics($conn, $scope);
    $qTotal = (int)($ctx['question_count'] ?? 0);
    $students = [];
    $totalTab = 0;
    $nowTs = time();
    $presenceCounts = ['active' => 0, 'idle' => 0, 'disconnected' => 0, 'submitted' => 0, 'expired' => 0, 'not_started' => 0];
    foreach (examination_monitor_progress_rows($conn, $scope) as $row) {
        $tc = (int)($row['tab_switch_count'] ?? 0);
        $totalTab += $tc;
        $attemptStatus = (string)($row['attempt_status'] ?? '');
        $presence = examination_monitor_presence_status($attemptStatus, $row['last_seen_at'] ?? null, $nowTs);
        if (isset($presenceCounts[$presence])) {
            $presenceCounts[$presence]++;
        }
        $ui = [];
        $rawUi = (string)($row['ui_state_json'] ?? '');
        if ($rawUi !== '') {
            $decoded = json_decode($rawUi, true);
            if (is_array($decoded)) {
                $ui = $decoded;
            }
        }
        $currentIndex = isset($ui['current_index']) ? (int)$ui['current_index'] : null;
        $currentQuestion = ($currentIndex !== null && $currentIndex >= 0) ? ($currentIndex + 1) : null;
        $answeredLive = isset($row['answered_live']) ? (int)$row['answered_live'] : 0;
        $correctLive = isset($row['correct_live']) ? (int)$row['correct_live'] : 0;
        if ($attemptStatus === 'submitted' || $attemptStatus === 'expired') {
            if (isset($row['correct_count'])) {
                $correctLive = (int)$row['correct_count'];
            }
            if (isset($row['total_count']) && (int)$row['total_count'] > 0) {
                $answeredLive = (int)$row['total_count'];
            }
        }
        $livePct = null;
        if ($answeredLive > 0) {
            $livePct = round(100 * $correctLive / $answeredLive, 1);
        }
        $remainingSec = null;
        $expRaw = $row['expires_at'] ?? null;
        $remainingFmt = null;
        if ($attemptStatus === 'in_progress') {
            if ($expRaw) {
                $expTs = strtotime((string)$expRaw);
                if ($expTs !== false) {
                    $remainingSec = max(0, $expTs - $nowTs);
                    if ($remainingSec <= 0) {
                        $remainingFmt = 'Expired';
                        $presence = 'expired';
                    } else {
                        $remainingFmt = examination_monitor_format_remaining((string)$expRaw, $nowTs);
                    }
                }
            }
        }
        $lastEvent = strtolower(trim((string)($row['last_tab_event'] ?? '')));
        $tabHidden = ($lastEvent === 'tab_hidden');
        $students[] = [
            'user_id' => (int)($row['user_id'] ?? 0),
            'full_name' => (string)($row['full_name'] ?? ''),
            'section' => (string)($row['section'] ?? ''),
            'attempt_id' => isset($row['attempt_id']) ? (int)$row['attempt_id'] : null,
            'attempt_status' => $attemptStatus,
            'presence_status' => $presence,
            'answered_count' => $answeredLive,
            'correct_count' => $correctLive,
            'score_pct_answered' => $livePct,
            'total_questions' => $qTotal > 0 ? $qTotal : (isset($row['total_count']) ? (int)$row['total_count'] : null),
            'current_question' => $currentQuestion,
            'remaining_seconds' => $remainingSec,
            'remaining_fmt' => $remainingFmt,
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'last_seen_fmt' => examination_monitor_format_dt($row['last_seen_at'] ?? null),
            'started_at' => $row['started_at'] ?? null,
            'started_fmt' => examination_monitor_format_dt($row['started_at'] ?? null),
            'tab_switch_count' => $tc,
            'tab_hidden' => $tabHidden,
            'last_tab_switch_at' => $row['last_tab_switch_at'] ?? null,
            'last_tab_switch_fmt' => examination_monitor_format_dt($row['last_tab_switch_at'] ?? null),
        ];
    }

    return [
        'ok' => true,
        'exam_type' => $ctx['exam_type'],
        'assessment_id' => (int)$ctx['assessment_id'],
        'auto_finalized' => $autoFinalized,
        'taking_count' => (int)($metrics['taking_count'] ?? 0),
        'submitted_count' => (int)($metrics['submitted_count'] ?? 0),
        'avg_score' => $metrics['avg_score'] ?? null,
        'pass_count' => (int)($metrics['pass_count'] ?? 0),
        'fail_count' => (int)($metrics['fail_count'] ?? 0),
        'total_tab_leaves' => $totalTab,
        'roster_total' => count($students),
        'presence' => $presenceCounts,
        'idle_seconds' => examination_monitor_idle_threshold_seconds(),
        'disconnected_seconds' => examination_monitor_disconnected_threshold_seconds(),
        'students' => $students,
    ];
}

function examination_monitor_examinee_type_label(string $reviewType): string
{
    return strtolower(trim($reviewType)) === 'undergrad' ? 'College Student' : 'Reviewee';
}
