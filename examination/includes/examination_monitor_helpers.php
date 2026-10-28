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

/**
 * Normalize a monitor/export section query value.
 * '' = all sections; '__none__' = blank/NULL current users.section; otherwise a named section.
 */
function examination_monitor_normalize_section_filter(mixed $raw): string
{
    $s = trim((string)$raw);
    if ($s === '' || strcasecmp($s, 'all') === 0) {
        return '';
    }
    if ($s === '__none__') {
        return '__none__';
    }

    return $s;
}

function examination_monitor_append_section_query(string $url, string $examType, string $sectionFilter = ''): string
{
    if (examination_monitor_normalize_exam_type($examType) !== 'college_exam') {
        return $url;
    }
    $n = examination_monitor_normalize_section_filter($sectionFilter);
    if ($n === '') {
        return $url;
    }
    $sep = str_contains($url, '?') ? '&' : '?';

    return $url . $sep . 'section=' . rawurlencode($n);
}

/**
 * Assigned ∪ attempt-holder user IDs, optionally restricted to current users.section.
 *
 * @return list<int>
 */
function examination_monitor_college_reporting_user_ids(mysqli $conn, int $examId, string $sectionFilter = ''): array
{
    require_once __DIR__ . '/examination_eligibility.php';
    $examId = (int)$examId;
    if ($examId <= 0) {
        return [];
    }
    $ids = examination_assigned_roster_user_ids($conn, 'regular', $examId);
    $sectionFilter = examination_monitor_normalize_section_filter($sectionFilter);
    if ($sectionFilter === '' || $ids === []) {
        return $ids;
    }
    $in = implode(',', array_map('intval', $ids));
    $res = @mysqli_query($conn, "SELECT user_id, TRIM(COALESCE(section,'')) AS section FROM users WHERE user_id IN ({$in})");
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $uid = (int)($row['user_id'] ?? 0);
            $sec = trim((string)($row['section'] ?? ''));
            if ($uid <= 0) {
                continue;
            }
            if ($sectionFilter === '__none__') {
                if ($sec === '') {
                    $out[] = $uid;
                }
            } elseif (examination_section_is_in_list($sec, [$sectionFilter])) {
                $out[] = $uid;
            }
        }
        mysqli_free_result($res);
    }
    sort($out);

    return array_values($out);
}

/**
 * Named monitor dropdown options = exam-assigned college_exam_sections labels
 * union distinct non-blank users.section on this exam's reporting roster
 * (assigned ∪ attempt holders). Does not query the global college_sections catalog.
 * No Section is not an assigned section; it is offered only when that roster
 * includes a blank users.section.
 *
 * @return array{options: list<string>, has_none: bool}
 */
function examination_monitor_college_section_options(mysqli $conn, int $examId): array
{
    require_once __DIR__ . '/examination_eligibility.php';
    $examId = (int)$examId;
    $named = [];
    $addNamed = static function (string $label) use (&$named): void {
        $label = trim($label);
        if ($label === '') {
            return;
        }
        $key = examination_normalize_section_compare_key($label);
        if ($key === '' || isset($named[$key])) {
            return;
        }
        $named[$key] = $label;
    };
    if ($examId > 0) {
        foreach (examination_load_assigned_sections($conn, 'regular', $examId) as $sec) {
            $addNamed((string)$sec);
        }
    }

    $hasNone = false;
    $ids = $examId > 0 ? examination_monitor_college_reporting_user_ids($conn, $examId, '') : [];
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $res = @mysqli_query($conn, "SELECT TRIM(COALESCE(section,'')) AS section FROM users WHERE user_id IN ({$in})");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $sec = trim((string)($row['section'] ?? ''));
                if ($sec === '') {
                    $hasNone = true;
                    continue;
                }
                $addNamed($sec);
            }
            mysqli_free_result($res);
        }
    }
    $options = array_values($named);
    natcasesort($options);

    return ['options' => array_values($options), 'has_none' => $hasNone];
}

/**
 * Accept GET section only when it is All, an exam-assigned named section, or No Section
 * when that population exists. Invalid values fall back to All Sections (do not filter).
 *
 * @return array{filter: string, invalid: bool, options: list<string>, has_none: bool}
 */
function examination_monitor_resolve_college_section_filter(mysqli $conn, int $examId, mixed $raw, ?array $secOpts = null): array
{
    $secOpts = is_array($secOpts) ? $secOpts : examination_monitor_college_section_options($conn, $examId);
    $options = is_array($secOpts['options'] ?? null) ? $secOpts['options'] : [];
    $hasNone = !empty($secOpts['has_none']);
    $n = examination_monitor_normalize_section_filter($raw);
    if ($n === '') {
        return ['filter' => '', 'invalid' => false, 'options' => $options, 'has_none' => $hasNone];
    }
    if ($n === '__none__') {
        if ($hasNone) {
            return ['filter' => '__none__', 'invalid' => false, 'options' => $options, 'has_none' => $hasNone];
        }

        return ['filter' => '', 'invalid' => true, 'options' => $options, 'has_none' => $hasNone];
    }
    foreach ($options as $opt) {
        if (examination_section_is_in_list($n, [(string)$opt])) {
            return ['filter' => (string)$opt, 'invalid' => false, 'options' => $options, 'has_none' => $hasNone];
        }
    }

    return ['filter' => '', 'invalid' => true, 'options' => $options, 'has_none' => $hasNone];
}

function examination_monitor_live_url(array $scope, string $sectionFilter = ''): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor_live?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    $url = 'professor_examination_monitor_live?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];

    return examination_monitor_append_section_query($url, 'college_exam', $sectionFilter);
}

function examination_monitor_pdf_url(array $scope, string $sectionFilter = ''): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor_pdf?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    $url = 'professor_examination_monitor_pdf?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];

    return examination_monitor_append_section_query($url, 'college_exam', $sectionFilter);
}

function examination_monitor_xlsx_url(array $scope, string $sectionFilter = ''): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor_xlsx?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    $url = 'professor_examination_monitor_xlsx?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];

    return examination_monitor_append_section_query($url, 'college_exam', $sectionFilter);
}

function examination_monitor_analytics_pdf_url(array $scope, string $sectionFilter = ''): string
{
    if (($scope['exam_type'] ?? '') !== 'college_exam') {
        return '';
    }
    $url = 'professor_examination_monitor_analytics_pdf?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];

    return examination_monitor_append_section_query($url, 'college_exam', $sectionFilter);
}

function examination_monitor_analytics_xlsx_url(array $scope, string $sectionFilter = ''): string
{
    if (($scope['exam_type'] ?? '') !== 'college_exam') {
        return '';
    }
    $url = 'professor_examination_monitor_analytics_xlsx?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];

    return examination_monitor_append_section_query($url, 'college_exam', $sectionFilter);
}

function examination_monitor_scoped_url(array $scope, string $sectionFilter = ''): string
{
    if ($scope['exam_type'] === 'diagnostic') {
        return 'professor_examination_monitor?exam_type=diagnostic&batch_id=' . (int)$scope['assessment_id'];
    }

    $url = 'professor_examination_monitor?exam_type=regular&exam_id=' . (int)$scope['assessment_id'];

    return examination_monitor_append_section_query($url, 'college_exam', $sectionFilter);
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
        $authoredCount = 0;
        $qc = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . (int)$assessmentId);
        if ($qc && ($qr = mysqli_fetch_assoc($qc))) {
            $authoredCount = (int)($qr['c'] ?? 0);
        }
        if ($qc) {
            mysqli_free_result($qc);
        }
        require_once __DIR__ . '/college_exam_attempt_paper.php';
        $attemptLen = college_exam_configured_attempt_length($conn, (int)$assessmentId);
        $qCount = $attemptLen > 0 ? $attemptLen : $authoredCount;

        return [
            'exam_type' => 'college_exam',
            'assessment_id' => $assessmentId,
            'title' => (string)($row['title'] ?? ''),
            'row' => $row,
            'question_count' => $qCount,
            'authored_question_count' => $authoredCount,
            'supports_live' => true,
            'supports_review_sheet' => true,
            'supports_pass_fail' => true,
            'supports_tab_tracking' => true,
            'back_url' => 'professor_examination_monitor',
            'back_label' => 'Back to Monitoring',
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
        'back_url' => 'professor_examination_monitor',
        'back_label' => 'Back to Monitoring',
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

function examination_monitor_metrics(mysqli $conn, array $scope, string $sectionFilter = ''): array
{
    if ($scope['exam_type'] === 'college_exam') {
        return examination_monitor_metrics_for_college_exam($conn, (int)$scope['assessment_id'], $sectionFilter);
    }

    return examination_monitor_metrics_for_diagnostic($conn, (int)$scope['assessment_id']);
}

function examination_monitor_metrics_for_college_exam(mysqli $conn, int $examId, string $sectionFilter = ''): array
{
    $metrics = [
        'taking_count' => 0,
        'submitted_count' => 0,
        'avg_score' => null,
        'pass_count' => 0,
        'fail_count' => 0,
    ];
    $userClause = '';
    $sectionFilter = examination_monitor_normalize_section_filter($sectionFilter);
    if ($sectionFilter !== '') {
        $userIds = examination_monitor_college_reporting_user_ids($conn, $examId, $sectionFilter);
        if ($userIds === []) {
            return $metrics;
        }
        $userClause = ' AND user_id IN (' . implode(',', array_map('intval', $userIds)) . ')';
    }
    $mq = @mysqli_query($conn, "
      SELECT
        SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS taking_count,
        SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count,
        AVG(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          THEN ROUND(100.0 * COALESCE(correct_count,0) / total_count, 0)
          WHEN status='submitted' AND score IS NOT NULL THEN score END) AS avg_score,
        SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          AND COALESCE(correct_count,0) >= CEILING(total_count / 2) THEN 1
          ELSE 0 END) AS pass_count,
        SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          AND COALESCE(correct_count,0) < CEILING(total_count / 2) THEN 1
          WHEN status='submitted' AND IFNULL(total_count,0) <= 0 THEN 1
          ELSE 0 END) AS fail_count
      FROM college_exam_attempts
      WHERE exam_id=" . (int)$examId . $userClause
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

function examination_monitor_progress_rows(mysqli $conn, array $scope, string $sectionFilter = ''): array
{
    if ($scope['exam_type'] === 'college_exam') {
        return examination_monitor_college_progress_rows($conn, (int)$scope['assessment_id'], $sectionFilter);
    }

    return examination_monitor_diagnostic_progress_rows($conn, (int)$scope['assessment_id']);
}

function examination_monitor_college_progress_rows(mysqli $conn, int $examId, string $sectionFilter = ''): array
{
    require_once __DIR__ . '/examination_eligibility.php';
    require_once __DIR__ . '/college_exam_attempt_events.php';
    college_exam_attempt_events_ensure_schema($conn);
    $userIds = examination_monitor_college_reporting_user_ids($conn, $examId, $sectionFilter);
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

/**
 * Overview “Exam access” facts from existing publish + schedule helpers.
 * Does not create attempts or change eligibility; per-student assignment is not claimed here.
 *
 * @param array<string,mixed> $record college_exams row
 * @param array{is_running?:bool,is_finished?:bool,all_finished_open?:bool} $window
 * @return array{published:bool,publish_label:string,access_key:string,access_label:string,access_detail:string}
 */
function examination_monitor_exam_access_summary(array $record, array $window, string $nowSql): array
{
    require_once __DIR__ . '/examination_eligibility.php';
    $published = examination_record_is_published($record, 'regular');
    $out = [
        'published' => $published,
        'publish_label' => $published ? 'Published' : 'Unpublished',
        'access_key' => 'unavailable',
        'access_label' => 'Not open',
        'access_detail' => 'Schedule facts are shown below. Whether a given student can start also depends on assignment.',
    ];
    if (!$published) {
        $out['access_key'] = 'unpublished';
        $out['access_label'] = 'Students cannot start';
        $out['access_detail'] = 'Unpublished exams cannot be started. Publish and set the window in the editor. This monitor does not start attempts.';

        return $out;
    }
    if (!empty($window['all_finished_open'])) {
        $out['access_key'] = 'closed';
        $out['access_label'] = 'Closed';
        $out['access_detail'] = 'No close time is set and everyone on the roster has submitted, so new starts are closed.';

        return $out;
    }
    if (examination_record_schedule_is_open($record, $nowSql)) {
        $out['access_key'] = 'open';
        $out['access_label'] = 'Open now';
        $out['access_detail'] = 'Assigned students may start their own attempt during this window. This monitor does not start attempts.';

        return $out;
    }
    $nowTs = examination_schedule_to_timestamp($nowSql) ?? time();
    $startsTs = examination_schedule_to_timestamp(isset($record['available_from']) ? (string)$record['available_from'] : null);
    if ($startsTs !== null && $nowTs < $startsTs) {
        $out['access_key'] = 'scheduled';
        $out['access_label'] = 'Scheduled — not open yet';
        $out['access_detail'] = 'Opens ' . examination_monitor_format_dt($record['available_from'] ?? null) . '. Assigned students cannot start before then.';

        return $out;
    }
    $endsTs = examination_schedule_to_timestamp(isset($record['deadline']) ? (string)$record['deadline'] : null);
    if ($endsTs !== null && $nowTs >= $endsTs) {
        $out['access_key'] = 'closed';
        $out['access_label'] = 'Closed';
        $out['access_detail'] = 'The close time has passed, so students cannot start a new attempt.';

        return $out;
    }

    return $out;
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

function examination_monitor_export_rows(mysqli $conn, array $ctx, bool $isFinished, string $sectionFilter = ''): array
{
    if ($ctx['exam_type'] === 'college_exam') {
        require_once __DIR__ . '/exam_monitor_progress_rows.php';

        return exam_monitor_progress_export_rows($conn, (int)$ctx['assessment_id'], (int)$ctx['question_count'], $isFinished, $sectionFilter);
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

/**
 * Elapsed attempt time from started_at to submitted_at, or to $nowTs while in progress.
 * Returns null when start (or end, for finished attempts) is missing so the UI cannot fabricate duration.
 */
function examination_monitor_attempt_duration_seconds(
    ?string $startedAt,
    ?string $submittedAt,
    ?string $attemptStatus,
    ?int $nowTs = null
): ?int {
    if ($startedAt === null || trim($startedAt) === '') {
        return null;
    }
    $start = strtotime($startedAt);
    if ($start === false) {
        return null;
    }
    $st = strtolower(trim((string)$attemptStatus));
    $endTs = null;
    if ($st === 'submitted' || $st === 'expired') {
        if ($submittedAt === null || trim($submittedAt) === '') {
            return null;
        }
        $end = strtotime($submittedAt);
        if ($end === false) {
            return null;
        }
        $endTs = $end;
    } elseif ($st === 'in_progress') {
        $endTs = $nowTs ?? time();
    } else {
        return null;
    }

    return max(0, $endTs - $start);
}

function examination_monitor_format_duration_seconds(?int $sec): string
{
    if ($sec === null) {
        return '';
    }
    $sec = max(0, $sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) {
        return sprintf('%dh %02dm', $h, $m);
    }
    if ($m > 0) {
        return sprintf('%dm %02ds', $m, $s);
    }

    return sprintf('%ds', $s);
}

/**
 * True only when the take page includes the client-heartbeat script.
 * Unwired take pages must not be presented as live device/instance detection.
 */
function examination_monitor_client_instance_tracking_wired(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $take = dirname(__DIR__) . '/examinee/college_take_exam.php';
    if (!is_file($take)) {
        $cached = false;

        return false;
    }
    $src = (string)@file_get_contents($take);
    $cached = (bool)preg_match('/<script[^>]+college-exam-client-heartbeat\\.js/i', $src);

    return $cached;
}

function examination_monitor_client_instances_label(bool $trackingLive, int $instances, int $scopes): string
{
    if (!$trackingLive) {
        return 'Not currently tracked';
    }
    $instances = max(0, $instances);
    $scopes = max(0, $scopes);
    if ($instances <= 0) {
        return 'No active exam page instances';
    }
    $inst = $instances . ' exam page instance' . ($instances === 1 ? '' : 's');
    if ($scopes > 0) {
        $inst .= ' · ' . $scopes . ' browser scope' . ($scopes === 1 ? '' : 's');
    }

    return $inst;
}

/**
 * Live JSON answer/score fields from SQL progress-row counts.
 *
 * College submitted/expired keep actual non-empty selected_answer counts.
 * Diagnostic submitted/expired still map answered_count to paper total_count
 * (legacy diagnostic roster display).
 *
 * @return array{answered_count:int,correct_count:int,score_pct_answered:?float,total_questions:?int}
 */
function examination_monitor_live_answer_fields(
    string $examType,
    string $attemptStatus,
    int $answeredLive,
    int $correctLive,
    mixed $correctCount,
    mixed $totalCount,
    int $qTotal
): array {
    $attemptStatus = strtolower(trim($attemptStatus));
    $closed = ($attemptStatus === 'submitted' || $attemptStatus === 'expired');
    $isCollege = ($examType === 'college_exam');
    $paperTot = ($totalCount !== null && $totalCount !== '') ? (int)$totalCount : 0;
    $answered = $answeredLive;
    $correct = $correctLive;
    if ($closed) {
        if ($correctCount !== null && $correctCount !== '') {
            $correct = (int)$correctCount;
        }
        if (!$isCollege && $paperTot > 0) {
            $answered = $paperTot;
        }
    }
    $totalQuestions = $qTotal > 0 ? $qTotal : ($paperTot > 0 ? $paperTot : null);
    $livePct = null;
    if ($isCollege && $closed) {
        $den = $paperTot > 0 ? $paperTot : (int)($totalQuestions ?? 0);
        if ($den > 0) {
            $livePct = round(100 * $correct / $den, 1);
        }
    } elseif ($answered > 0) {
        $livePct = round(100 * $correct / $answered, 1);
    }

    return [
        'answered_count' => $answered,
        'correct_count' => $correct,
        'score_pct_answered' => $livePct,
        'total_questions' => $totalQuestions,
    ];
}

function examination_monitor_live_payload(mysqli $conn, array $ctx, string $sectionFilter = ''): array
{
    $scope = ['exam_type' => $ctx['exam_type'], 'assessment_id' => (int)$ctx['assessment_id']];
    if (($ctx['exam_type'] ?? '') !== 'college_exam') {
        $sectionFilter = '';
    } else {
        $sectionFilter = examination_monitor_normalize_section_filter($sectionFilter);
    }
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
    $metrics = examination_monitor_metrics($conn, $scope, $sectionFilter);
    $qTotal = (int)($ctx['question_count'] ?? 0);
    $students = [];
    $totalTab = 0;
    $nowTs = time();
    $presenceCounts = ['active' => 0, 'idle' => 0, 'disconnected' => 0, 'submitted' => 0, 'expired' => 0, 'not_started' => 0];
    $clientOverlapByAttempt = [];
    $overlapExamineeCount = 0;
    $clientTrackingLive = false;
    $concurrentAlerts = [];
    if (($ctx['exam_type'] ?? '') === 'college_exam') {
        $clientTrackingLive = examination_monitor_client_instance_tracking_wired();
        require_once __DIR__ . '/college_exam_client_heartbeat.php';
        $clientOverlapByAttempt = college_exam_client_active_counts_for_exam($conn, (int)$ctx['assessment_id']);
        $concurrentAlerts = examination_monitor_college_concurrent_alerts($conn, (int)$ctx['assessment_id']);
    }
    foreach (examination_monitor_progress_rows($conn, $scope, $sectionFilter) as $row) {
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
        $liveFields = examination_monitor_live_answer_fields(
            (string)($ctx['exam_type'] ?? ''),
            $attemptStatus,
            isset($row['answered_live']) ? (int)$row['answered_live'] : 0,
            isset($row['correct_live']) ? (int)$row['correct_live'] : 0,
            $row['correct_count'] ?? null,
            $row['total_count'] ?? null,
            $qTotal
        );
        $answeredLive = (int)$liveFields['answered_count'];
        $correctLive = (int)$liveFields['correct_count'];
        $livePct = $liveFields['score_pct_answered'];
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
        $aidLive = isset($row['attempt_id']) ? (int)$row['attempt_id'] : 0;
        $clientCounts = ($aidLive > 0 && isset($clientOverlapByAttempt[$aidLive]) && is_array($clientOverlapByAttempt[$aidLive]))
            ? $clientOverlapByAttempt[$aidLive]
            : ['instances' => 0, 'scopes' => 0];
        $activeInstances = (int)($clientCounts['instances'] ?? 0);
        $activeScopes = (int)($clientCounts['scopes'] ?? 0);
        $clientOverlap = $activeInstances >= 2;
        if ($clientOverlap) {
            $overlapExamineeCount++;
        }
        $submittedRaw = $row['submitted_at'] ?? null;
        $durSec = examination_monitor_attempt_duration_seconds(
            isset($row['started_at']) ? (string)$row['started_at'] : null,
            isset($submittedRaw) ? (string)$submittedRaw : null,
            $attemptStatus,
            $nowTs
        );
        $students[] = [
            'user_id' => (int)($row['user_id'] ?? 0),
            'full_name' => (string)($row['full_name'] ?? ''),
            'student_number' => trim((string)($row['student_number'] ?? '')),
            'section' => (string)($row['section'] ?? ''),
            'attempt_id' => $aidLive > 0 ? $aidLive : null,
            'attempt_status' => $attemptStatus,
            'presence_status' => $presence,
            'answered_count' => $answeredLive,
            'correct_count' => $correctLive,
            'score_pct_answered' => $livePct,
            'total_questions' => $liveFields['total_questions'],
            'current_question' => $currentQuestion,
            'remaining_seconds' => $remainingSec,
            'remaining_fmt' => $remainingFmt,
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'last_seen_fmt' => examination_monitor_format_dt($row['last_seen_at'] ?? null),
            'started_at' => $row['started_at'] ?? null,
            'started_fmt' => examination_monitor_format_dt($row['started_at'] ?? null),
            'submitted_at' => $submittedRaw,
            'submitted_fmt' => examination_monitor_format_dt($submittedRaw),
            'duration_seconds' => $durSec,
            'duration_fmt' => examination_monitor_format_duration_seconds($durSec),
            'tab_switch_count' => $tc,
            'tab_hidden' => $tabHidden,
            'last_tab_switch_at' => $row['last_tab_switch_at'] ?? null,
            'last_tab_switch_fmt' => examination_monitor_format_dt($row['last_tab_switch_at'] ?? null),
            'active_instances' => $activeInstances,
            'active_browser_scopes' => $activeScopes,
            'client_overlap' => $clientOverlap,
            'client_instances_label' => examination_monitor_client_instances_label(
                $clientTrackingLive,
                $activeInstances,
                $activeScopes
            ),
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
        'section_filter' => $sectionFilter,
        'presence' => $presenceCounts,
        'idle_seconds' => examination_monitor_idle_threshold_seconds(),
        'disconnected_seconds' => examination_monitor_disconnected_threshold_seconds(),
        'client_tracking_live' => $clientTrackingLive,
        'overlap_examinee_count' => $overlapExamineeCount,
        'concurrent_alerts' => $concurrentAlerts,
        'students' => $students,
    ];
}

function examination_monitor_examinee_type_label(string $reviewType): string
{
    return strtolower(trim($reviewType)) === 'undergrad' ? 'College Student' : 'Reviewee';
}

/**
 * @return array<string,string> event_type => label (stored types only)
 */
function examination_monitor_college_security_allowed_types(): array
{
    return [
        'tab_hidden' => 'Tab left',
        'tab_visible' => 'Returned to exam',
        'exam_started' => 'Exam started',
        'exam_submitted' => 'Exam submitted',
        'exam_finalized' => 'Exam submitted',
        'exam_expired' => 'Exam expired / auto-submitted',
    ];
}

function examination_monitor_college_security_type_label(string $type): string
{
    $type = strtolower(trim($type));
    $map = examination_monitor_college_security_allowed_types();

    return $map[$type] ?? ($type !== '' ? $type : 'Event');
}

/** Parse datetime-local / SQL datetime into Y-m-d H:i:s, or null. */
function examination_monitor_parse_filter_dt(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || preg_match('/^0000-00-00/', $raw)) {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        $h = (int)$m[4];
        $mi = (int)$m[5];
        $s = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s);
    }

    return null;
}

/**
 * @param list<int> $allowedUserIds
 * @return array{user_id:int,event_type:string,from:?string,until:?string}
 */
function examination_monitor_normalize_security_filters(array $raw, array $allowedUserIds): array
{
    $allowed = [];
    foreach ($allowedUserIds as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) {
            $allowed[$uid] = $uid;
        }
    }
    $userId = (int)($raw['user_id'] ?? 0);
    if ($userId > 0 && !isset($allowed[$userId])) {
        $userId = 0;
    }
    $type = strtolower(trim((string)($raw['event_type'] ?? '')));
    if ($type !== '' && !isset(examination_monitor_college_security_allowed_types()[$type])) {
        $type = '';
    }
    $from = examination_monitor_parse_filter_dt((string)($raw['from'] ?? ''));
    $until = examination_monitor_parse_filter_dt((string)($raw['until'] ?? ''));
    if ($from !== null && $until !== null && $until < $from) {
        $until = null;
    }

    return [
        'user_id' => $userId,
        'event_type' => $type,
        'from' => $from,
        'until' => $until,
    ];
}

/**
 * @param list<int> $userIds
 * @return array{sql:string,ids:list<int>}|null
 */
function examination_monitor_college_security_events_where(int $examId, array $userIds, array $filters): ?array
{
    $examId = (int)$examId;
    if ($examId <= 0) {
        return null;
    }
    $ids = [];
    foreach ($userIds as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) {
            $ids[$uid] = $uid;
        }
    }
    $wantUser = (int)($filters['user_id'] ?? 0);
    if ($wantUser > 0) {
        if (!isset($ids[$wantUser])) {
            return null;
        }
        $ids = [$wantUser => $wantUser];
    }
    if ($ids === []) {
        return null;
    }
    $in = implode(',', array_map('intval', array_values($ids)));
    $sql = 'e.exam_id=' . $examId . ' AND e.user_id IN (' . $in . ')'
        . " AND e.event_type NOT IN ('window_blurred','window_focused')";
    $type = strtolower(trim((string)($filters['event_type'] ?? '')));
    $allowed = examination_monitor_college_security_allowed_types();
    if ($type === 'exam_submitted') {
        $sql .= " AND e.event_type IN ('exam_submitted','exam_finalized')";
    } elseif ($type !== '' && preg_match('/^[a-z_]+$/', $type) && isset($allowed[$type])) {
        $sql .= " AND e.event_type='" . $type . "'";
    }
    $from = isset($filters['from']) && is_string($filters['from']) ? $filters['from'] : null;
    $until = isset($filters['until']) && is_string($filters['until']) ? $filters['until'] : null;
    if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $from)) {
        $sql .= " AND e.occurred_at >= '" . $from . "'";
    }
    if ($until !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $until)) {
        $sql .= " AND e.occurred_at <= '" . $until . "'";
    }

    return ['sql' => $sql, 'ids' => array_values($ids)];
}

/**
 * Recent recorded attempt events for the college monitor Security tab (existing table only).
 *
 * @param list<int> $userIds
 * @param array{user_id?:int,event_type?:string,from?:?string,until?:?string} $filters
 * @return list<array<string,mixed>>
 */
function examination_monitor_college_security_events(mysqli $conn, int $examId, array $userIds, array $filters = [], int $limit = 200): array
{
    $scope = examination_monitor_college_security_events_where($examId, $userIds, $filters);
    if ($scope === null) {
        return [];
    }
    $limit = max(1, min(400, $limit));
    $sql = 'SELECT e.event_id, e.attempt_id, e.user_id, e.event_type, e.occurred_at, e.meta_json
            FROM college_exam_attempt_events e
            WHERE ' . $scope['sql'] . '
            ORDER BY e.occurred_at DESC, e.event_id DESC
            LIMIT ' . (int)$limit;
    $res = @mysqli_query($conn, $sql);
    $out = [];
    if (!$res) {
        return $out;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $type = strtolower(trim((string)($row['event_type'] ?? '')));
        $away = null;
        $rawMeta = (string)($row['meta_json'] ?? '');
        if ($rawMeta !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded) && isset($decoded['away_seconds']) && is_numeric($decoded['away_seconds'])) {
                $away = max(0, (int)$decoded['away_seconds']);
            }
        }
        $detail = null;
        if ($type === 'tab_visible' && $away !== null) {
            $detail = 'Away for ' . $away . ' second' . ($away === 1 ? '' : 's');
        }
        $out[] = [
            'event_id' => (int)($row['event_id'] ?? 0),
            'attempt_id' => (int)($row['attempt_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'event_type' => $type,
            'label' => examination_monitor_college_security_type_label($type),
            'kind' => 'recorded',
            'occurred_at' => (string)($row['occurred_at'] ?? ''),
            'occurred_fmt' => examination_monitor_format_dt($row['occurred_at'] ?? null),
            'away_seconds' => $away,
            'detail' => $detail,
        ];
    }
    mysqli_free_result($res);

    return $out;
}

/**
 * @param list<int> $userIds
 * @return array{matching:int,examinees:int,types:list<array{type:string,label:string,count:int}>}
 */
function examination_monitor_college_security_events_stats(mysqli $conn, int $examId, array $userIds, array $filters = []): array
{
    $empty = ['matching' => 0, 'examinees' => 0, 'types' => []];
    $scope = examination_monitor_college_security_events_where($examId, $userIds, $filters);
    if ($scope === null) {
        return $empty;
    }
    $totSql = 'SELECT COUNT(*) AS c, COUNT(DISTINCT e.user_id) AS u
               FROM college_exam_attempt_events e WHERE ' . $scope['sql'];
    $totRes = @mysqli_query($conn, $totSql);
    $matching = 0;
    $examinees = 0;
    if ($totRes && ($tr = mysqli_fetch_assoc($totRes))) {
        $matching = (int)($tr['c'] ?? 0);
        $examinees = (int)($tr['u'] ?? 0);
    }
    if ($totRes) {
        mysqli_free_result($totRes);
    }
    $typeSql = 'SELECT e.event_type AS t, COUNT(*) AS c
                FROM college_exam_attempt_events e WHERE ' . $scope['sql'] . '
                GROUP BY e.event_type ORDER BY c DESC, t ASC';
    $typeRes = @mysqli_query($conn, $typeSql);
    $types = [];
    $merged = [];
    if ($typeRes) {
        while ($row = mysqli_fetch_assoc($typeRes)) {
            $t = strtolower(trim((string)($row['t'] ?? '')));
            $label = examination_monitor_college_security_type_label($t);
            $n = (int)($row['c'] ?? 0);
            if (!isset($merged[$label])) {
                $merged[$label] = ['type' => $t, 'label' => $label, 'count' => 0];
            }
            $merged[$label]['count'] += $n;
        }
        mysqli_free_result($typeRes);
    }
    foreach ($merged as $row) {
        $types[] = $row;
    }
    usort($types, static function (array $a, array $b): int {
        return ($b['count'] <=> $a['count']) ?: strcmp((string)$a['label'], (string)$b['label']);
    });

    return ['matching' => $matching, 'examinees' => $examinees, 'types' => $types];
}

/**
 * Stored overlapping-instance alerts for the Security tab (read-only; no restriction actions).
 *
 * @return list<array<string,mixed>>
 */
function examination_monitor_college_concurrent_alerts(mysqli $conn, int $examId): array
{
    $examId = (int)$examId;
    if ($examId <= 0) {
        return [];
    }
    require_once __DIR__ . '/college_exam_client_heartbeat.php';
    if (!college_exam_client_heartbeat_tables_ready($conn)) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT a.alert_id, a.attempt_id, a.user_id, a.status, a.instance_count, a.browser_scope_count,
                a.peak_instance_count, a.peak_scope_count, a.first_detected_at, a.last_detected_at,
                u.full_name
         FROM college_exam_concurrent_alerts a
         LEFT JOIN users u ON u.user_id = a.user_id
         WHERE a.exam_id=?
         ORDER BY a.last_detected_at DESC, a.alert_id DESC
         LIMIT 100'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $examId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $status = strtolower(trim((string)($row['status'] ?? 'pending_review')));
        $out[] = [
            'alert_id' => (int)($row['alert_id'] ?? 0),
            'attempt_id' => (int)($row['attempt_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'full_name' => (string)($row['full_name'] ?? ''),
            'status' => $status,
            'review_state' => $status === '' ? 'pending_review' : $status,
            'instance_count' => (int)($row['instance_count'] ?? 0),
            'browser_scope_count' => (int)($row['browser_scope_count'] ?? 0),
            'peak_instance_count' => (int)($row['peak_instance_count'] ?? 0),
            'peak_scope_count' => (int)($row['peak_scope_count'] ?? 0),
            'first_detected_at' => $row['first_detected_at'] ?? null,
            'first_detected_fmt' => examination_monitor_format_dt($row['first_detected_at'] ?? null),
            'last_detected_at' => $row['last_detected_at'] ?? null,
            'last_detected_fmt' => examination_monitor_format_dt($row['last_detected_at'] ?? null),
        ];
    }
    mysqli_stmt_close($stmt);

    return $out;
}
