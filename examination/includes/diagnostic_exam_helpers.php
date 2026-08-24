<?php
declare(strict_types=1);

/**
 * Diagnostic Exam helpers — isolated from college_exam_helpers.php
 */

(function (): void {
    $platformFile = dirname(__DIR__, 2) . '/includes/platform_access.php';
    if (is_file($platformFile)) {
        require_once $platformFile;
    }
})();

/**
 * Decode optional E+ choices from diagnostic_questions.extra_choices_json.
 *
 * @return array<string,string>
 */
function diagnostic_exam_extra_choices_decode(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $k => $v) {
        $L = strtoupper(trim((string)$k));
        if (!preg_match('/^[E-Z]$/', $L)) {
            continue;
        }
        $text = trim((string)$v);
        if ($text === '') {
            continue;
        }
        $out[$L] = $text;
    }
    ksort($out);

    return $out;
}

function diagnostic_exam_human_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return 'No limit';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($h > 0) {
        $parts[] = $h . 'h';
    }
    if ($m > 0) {
        $parts[] = $m . 'm';
    }
    if ($parts === []) {
        $parts[] = max(1, $seconds) . 's';
    }
    return implode(' ', $parts);
}

function diagnostic_exam_compute_expires_at(int $timeLimitSec, ?string $deadlineSql): ?string
{
    if ($timeLimitSec <= 0) {
        return $deadlineSql ?: null;
    }
    $fromTimer = date('Y-m-d H:i:s', time() + $timeLimitSec);
    if ($deadlineSql === null || $deadlineSql === '') {
        return $fromTimer;
    }
    return ($fromTimer < $deadlineSql) ? $fromTimer : $deadlineSql;
}

function diagnostic_exam_compute_score_percentage(int $correct, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }
    return round(($correct / $total) * 100, 2);
}

function diagnostic_exam_batch_is_published(array $batch): bool
{
    return !empty($batch['is_published']);
}

function diagnostic_exam_batch_is_open(array $batch, string $nowSql): bool
{
    if (!diagnostic_exam_batch_is_published($batch)) {
        return false;
    }
    if (!empty($batch['available_from']) && (string)$batch['available_from'] > $nowSql) {
        return false;
    }
    if (!empty($batch['deadline']) && (string)$batch['deadline'] < $nowSql) {
        return false;
    }
    return true;
}

function diagnostic_exam_load_subject_catalog(mysqli $conn): array
{
    $out = [];
    $q = @mysqli_query($conn, "SELECT subject_id, subject_code, subject_name, sort_order FROM diagnostic_subjects WHERE is_active=1 ORDER BY sort_order ASC, subject_code ASC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $out[] = $r;
        }
        mysqli_free_result($q);
    }
    return $out;
}

function diagnostic_exam_load_batch(mysqli $conn, int $batchId, int $professorId = 0): ?array
{
    $sql = 'SELECT * FROM diagnostic_batches WHERE batch_id=?';
    if ($professorId > 0) {
        $sql .= ' AND created_by=' . (int)$professorId;
    }
    $sql .= ' LIMIT 1';
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $batchId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    return $row ?: null;
}

function diagnostic_exam_load_batch_sections(mysqli $conn, int $batchId): array
{
    $out = [];
    $st = mysqli_prepare($conn, 'SELECT section_value FROM diagnostic_batch_sections WHERE batch_id=? ORDER BY section_value ASC');
    if ($st) {
        mysqli_stmt_bind_param($st, 'i', $batchId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($r = mysqli_fetch_assoc($res)) {
            $out[] = (string)($r['section_value'] ?? '');
        }
        mysqli_stmt_close($st);
    }
    return $out;
}

function diagnostic_exam_load_batch_subjects(mysqli $conn, int $batchId): array
{
    $out = [];
    $q = @mysqli_query($conn, "
        SELECT bs.batch_subject_id, bs.subject_id, bs.sort_order, bs.questions_required,
               s.subject_code, s.subject_name
        FROM diagnostic_batch_subjects bs
        INNER JOIN diagnostic_subjects s ON s.subject_id = bs.subject_id
        WHERE bs.batch_id=" . (int)$batchId . "
        ORDER BY bs.sort_order ASC, s.sort_order ASC
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $out[] = $r;
        }
        mysqli_free_result($q);
    }
    return $out;
}

function diagnostic_exam_load_questions_grouped(mysqli $conn, int $batchId): array
{
    $grouped = [];
    $q = @mysqli_query($conn, "SELECT * FROM diagnostic_questions WHERE batch_id=" . (int)$batchId . " ORDER BY subject_id ASC, sort_order ASC, question_id ASC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $sid = (int)($r['subject_id'] ?? 0);
            if (!isset($grouped[$sid])) {
                $grouped[$sid] = [];
            }
            $grouped[$sid][] = $r;
        }
        mysqli_free_result($q);
    }
    return $grouped;
}

function diagnostic_exam_normalize_examinee_scope(string $scope): string
{
    $s = strtolower(trim($scope));
    return in_array($s, ['college_student', 'reviewee', 'both'], true) ? $s : 'college_student';
}

function diagnostic_exam_normalize_assignment_mode(string $mode): string
{
    $m = strtolower(trim($mode));
    return in_array($m, ['all', 'sections', 'users', 'sections_and_users'], true) ? $m : 'sections';
}

function diagnostic_exam_examinee_scope_label(string $scope): string
{
    return match (diagnostic_exam_normalize_examinee_scope($scope)) {
        'reviewee' => 'Reviewee',
        'both' => 'Both',
        default => 'College Student',
    };
}

function diagnostic_exam_assignment_mode_label(string $mode): string
{
    return match (diagnostic_exam_normalize_assignment_mode($mode)) {
        'all' => 'All eligible',
        'users' => 'Selected individuals',
        'sections_and_users' => 'Sections + individuals',
        default => 'Selected sections',
    };
}

function diagnostic_exam_load_examinee_user(mysqli $conn, int $userId): ?array
{
    $platformFile = dirname(__DIR__, 2) . '/includes/platform_access.php';
    if (is_file($platformFile)) {
        require_once $platformFile;
        if (function_exists('ereview_load_college_examinee_user')) {
            return ereview_load_college_examinee_user($conn, $userId);
        }
    }
    $st = mysqli_prepare($conn, "SELECT user_id, role, status, review_type, TRIM(COALESCE(section,'')) AS section FROM users WHERE user_id=? AND role='college_student' LIMIT 1");
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $userId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    return $row ?: null;
}

function diagnostic_exam_user_matches_examinee_scope(array $user, string $scope): bool
{
    $platformFile = dirname(__DIR__, 2) . '/includes/platform_access.php';
    if (is_file($platformFile)) {
        require_once $platformFile;
        global $conn;
        if (isset($conn) && $conn instanceof mysqli) {
            $uid = (int)($user['user_id'] ?? 0);
            if ($uid <= 0 || !ereview_user_has_college_examination_access($conn, $uid, $user)) {
                return false;
            }
        }
    } elseif (($user['role'] ?? '') !== 'college_student') {
        return false;
    }
    if (($user['status'] ?? '') !== 'approved') {
        return false;
    }
    $rt = strtolower(trim((string)($user['review_type'] ?? 'reviewee')));
    $scope = diagnostic_exam_normalize_examinee_scope($scope);
    if ($scope === 'both') {
        return in_array($rt, ['undergrad', 'reviewee'], true);
    }
    if ($scope === 'college_student') {
        return $rt === 'undergrad';
    }
    return $rt === 'reviewee';
}

function diagnostic_exam_examinee_scope_sql(string $scope, string $alias = 'u'): string
{
    $scope = diagnostic_exam_normalize_examinee_scope($scope);
    $a = preg_replace('/[^a-z_]/', '', $alias) ?: 'u';
    if ($scope === 'both') {
        return "{$a}.review_type IN ('undergrad','reviewee')";
    }
    if ($scope === 'college_student') {
        return "{$a}.review_type='undergrad'";
    }
    return "{$a}.review_type='reviewee'";
}

function diagnostic_exam_load_batch_users(mysqli $conn, int $batchId): array
{
    $out = [];
    $q = @mysqli_query($conn, 'SELECT user_id FROM diagnostic_batch_users WHERE batch_id=' . (int)$batchId . ' ORDER BY user_id ASC');
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $out[] = (int)($r['user_id'] ?? 0);
        }
        mysqli_free_result($q);
    }
    return array_values(array_filter($out, static fn($id) => $id > 0));
}

function diagnostic_exam_user_in_batch_sections(mysqli $conn, int $batchId, string $section): bool
{
    require_once __DIR__ . '/examination_assignment.php';
    if (examination_normalize_section_compare_key($section) === '') {
        return false;
    }
    $assigned = diagnostic_exam_load_batch_sections($conn, $batchId);

    return examination_section_is_in_list($section, $assigned);
}

function diagnostic_exam_user_in_batch_assignees(mysqli $conn, int $batchId, int $userId): bool
{
    $st = mysqli_prepare($conn, 'SELECT 1 FROM diagnostic_batch_users WHERE batch_id=? AND user_id=? LIMIT 1');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'ii', $batchId, $userId);
    mysqli_stmt_execute($st);
    $ok = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    return $ok;
}

function diagnostic_exam_user_passes_assignment(mysqli $conn, int $userId, array $batch, string $section): bool
{
    require_once __DIR__ . '/examination_eligibility.php';
    $batchId = (int)($batch['batch_id'] ?? 0);
    $mode = (string)($batch['assignment_mode'] ?? 'sections');

    return examination_user_passes_assignment($conn, $userId, $mode, $batchId, 'diagnostic', $section);
}

function diagnostic_exam_user_is_assigned(mysqli $conn, int $userId, array $batch): bool
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_user_is_assigned($conn, $userId, $batch, 'diagnostic');
}

function diagnostic_exam_user_can_start_batch(mysqli $conn, int $userId, array $batch, string $nowSql): bool
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_user_can_start_exam($conn, $userId, $batch, 'diagnostic', $nowSql);
}

function diagnostic_exam_user_eligible_for_batch(mysqli $conn, int $userId, array $batch, string $nowSql): bool
{
    return diagnostic_exam_user_can_start_batch($conn, $userId, $batch, $nowSql);
}

/** @deprecated alias */
function diagnostic_exam_student_eligible_for_batch(mysqli $conn, int $userId, array $batch, string $nowSql): bool
{
    return diagnostic_exam_user_eligible_for_batch($conn, $userId, $batch, $nowSql);
}

function diagnostic_exam_student_section(mysqli $conn, int $userId): string
{
    $user = diagnostic_exam_load_examinee_user($conn, $userId);
    return trim((string)($user['section'] ?? ''));
}

function diagnostic_exam_load_eligible_batches_for_user(mysqli $conn, int $userId, string $nowSql): array
{
    unset($nowSql);
    $user = diagnostic_exam_load_examinee_user($conn, $userId);
    if (!$user) {
        return [];
    }
    $out = [];
    $q = @mysqli_query($conn, "SELECT * FROM diagnostic_batches WHERE is_published=1 ORDER BY updated_at DESC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            if (diagnostic_exam_user_is_assigned($conn, $userId, $r)) {
                $out[] = $r;
            }
        }
        mysqli_free_result($q);
    }
    usort($out, static function ($a, $b) {
        $da = (string)($a['deadline'] ?? '');
        $db = (string)($b['deadline'] ?? '');
        if ($da === $db) {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        }
        if ($da === '') {
            return 1;
        }
        if ($db === '') {
            return -1;
        }
        return strcmp($da, $db);
    });
    return $out;
}

/** @deprecated alias */
function diagnostic_exam_load_eligible_batches_for_student(mysqli $conn, int $userId, string $nowSql): array
{
    return diagnostic_exam_load_eligible_batches_for_user($conn, $userId, $nowSql);
}

function diagnostic_exam_search_examinees(mysqli $conn, string $scope = 'both', string $q = '', int $limit = 200): array
{
    $scope = diagnostic_exam_normalize_examinee_scope($scope);
    $scopeSql = diagnostic_exam_examinee_scope_sql($scope, 'u');
    $examineeSql = ereview_sql_college_examinee_where('u');
    $sql = "SELECT u.user_id, u.full_name, u.email, u.section, u.student_number, u.review_type, u.status FROM users u WHERE {$examineeSql} AND {$scopeSql}";
    if ($q !== '') {
        $esc = '%' . mysqli_real_escape_string($conn, $q) . '%';
        $sql .= " AND (u.full_name LIKE '{$esc}' OR u.email LIKE '{$esc}' OR u.section LIKE '{$esc}' OR u.student_number LIKE '{$esc}')";
    }
    $sql .= ' ORDER BY u.full_name ASC LIMIT ' . max(1, min(500, $limit));
    $out = [];
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $out[] = $r;
        }
        mysqli_free_result($res);
    }
    return $out;
}

function diagnostic_exam_load_attempt(mysqli $conn, int $batchId, int $userId): ?array
{
    $st = mysqli_prepare($conn, 'SELECT * FROM diagnostic_attempts WHERE batch_id=? AND user_id=? LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'ii', $batchId, $userId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    return $row ?: null;
}

function diagnostic_exam_attempt_status_normalized(?array $attempt): string
{
    if (!$attempt) {
        return '';
    }
    $st = strtolower((string)($attempt['status'] ?? ''));
    return in_array($st, ['in_progress', 'submitted', 'expired'], true) ? $st : '';
}

function diagnostic_exam_attempt_is_submitted(?array $attempt): bool
{
    $st = diagnostic_exam_attempt_status_normalized($attempt);
    return $st === 'submitted' || $st === 'expired';
}

function diagnostic_exam_build_flat_questions(mysqli $conn, int $batchId, array $batchSubjects, ?int $attemptId = null): array
{
    $grouped = diagnostic_exam_load_questions_grouped($conn, $batchId);
    $flat = [];
    foreach ($batchSubjects as $bs) {
        $sid = (int)($bs['subject_id'] ?? 0);
        $req = max(0, (int)($bs['questions_required'] ?? 0));
        $rows = $grouped[$sid] ?? [];
        if ($req > 0 && count($rows) > $req) {
            $rows = array_slice($rows, 0, $req);
        }
        foreach ($rows as $q) {
            $q['_subject_code'] = (string)($bs['subject_code'] ?? '');
            $q['_subject_name'] = (string)($bs['subject_name'] ?? '');
            $flat[] = $q;
        }
    }
    if (!empty($batchSubjects) && $attemptId !== null && $attemptId > 0) {
        $batchRow = diagnostic_exam_load_batch($conn, $batchId);
        if ($batchRow && !empty($batchRow['shuffle_questions'])) {
            usort($flat, static function ($a, $b) use ($attemptId) {
                $ka = sha1($attemptId . ':sub:' . ($a['subject_id'] ?? 0) . ':q:' . ($a['question_id'] ?? 0));
                $kb = sha1($attemptId . ':sub:' . ($b['subject_id'] ?? 0) . ':q:' . ($b['question_id'] ?? 0));
                return strcmp($ka, $kb);
            });
        }
    }
    return $flat;
}

function diagnostic_exam_batch_stats_for_student(mysqli $conn, int $batchId): array
{
    $subjects = diagnostic_exam_load_batch_subjects($conn, $batchId);
    $grouped = diagnostic_exam_load_questions_grouped($conn, $batchId);
    $subjectCount = count($subjects);
    $questionCount = 0;
    $labels = [];
    foreach ($subjects as $bs) {
        $sid = (int)($bs['subject_id'] ?? 0);
        $req = max(0, (int)($bs['questions_required'] ?? 0));
        $avail = count($grouped[$sid] ?? []);
        $use = ($req > 0) ? min($req, $avail) : $avail;
        $questionCount += $use;
        $labels[] = (string)($bs['subject_code'] ?? '');
    }
    return [
        'subject_count' => $subjectCount,
        'question_count' => $questionCount,
        'subject_labels' => $labels,
    ];
}

function diagnostic_exam_finalize_attempt(mysqli $conn, int $attemptId, int $userId): array
{
    $st = mysqli_prepare($conn, 'SELECT * FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($st, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($st);
    $att = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$att || ($att['status'] ?? '') !== 'in_progress') {
        return ['ok' => false, 'error' => 'Invalid attempt'];
    }
    $batchId = (int)($att['batch_id'] ?? 0);
    $batch = diagnostic_exam_load_batch($conn, $batchId);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch missing'];
    }
    $batchSubjects = diagnostic_exam_load_batch_subjects($conn, $batchId);
    $questions = diagnostic_exam_build_flat_questions($conn, $batchId, $batchSubjects, $attemptId);

    $ansRes = mysqli_query($conn, 'SELECT question_id, selected_answer FROM diagnostic_answers WHERE attempt_id=' . (int)$attemptId);
    $byQ = [];
    if ($ansRes) {
        while ($r = mysqli_fetch_assoc($ansRes)) {
            $byQ[(int)$r['question_id']] = strtoupper(trim((string)($r['selected_answer'] ?? '')));
        }
        mysqli_free_result($ansRes);
    }

    $correct = 0;
    $total = count($questions);
    $bySubject = [];
    foreach ($questions as $q) {
        $qid = (int)($q['question_id'] ?? 0);
        $sid = (int)($q['subject_id'] ?? 0);
        if (!isset($bySubject[$sid])) {
            $bySubject[$sid] = ['correct' => 0, 'total' => 0, 'subject_code' => (string)($q['_subject_code'] ?? ''), 'subject_name' => (string)($q['_subject_name'] ?? '')];
        }
        $bySubject[$sid]['total']++;
        $exp = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
        $sel = $byQ[$qid] ?? '';
        $isCorrect = ($sel !== '' && $sel === $exp) ? 1 : 0;
        if ($isCorrect) {
            $correct++;
            $bySubject[$sid]['correct']++;
        }
        $upd = mysqli_prepare($conn, 'UPDATE diagnostic_answers SET is_correct=? WHERE attempt_id=? AND question_id=?');
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'iii', $isCorrect, $attemptId, $qid);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
    }

    $breakdown = [];
    foreach ($bySubject as $sid => $agg) {
        $t = (int)$agg['total'];
        $c = (int)$agg['correct'];
        $breakdown[] = [
            'subject_id' => (int)$sid,
            'subject_code' => (string)$agg['subject_code'],
            'subject_name' => (string)$agg['subject_name'],
            'correct' => $c,
            'total' => $t,
            'score_pct' => diagnostic_exam_compute_score_percentage($c, $t),
        ];
    }

    $score = diagnostic_exam_compute_score_percentage($correct, $total);
    $json = json_encode($breakdown, JSON_UNESCAPED_UNICODE);
    $submitted = date('Y-m-d H:i:s');
    $upd = mysqli_prepare($conn, "UPDATE diagnostic_attempts SET status='submitted', score=?, correct_count=?, total_count=?, subject_breakdown_json=?, submitted_at=? WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($upd, 'diissii', $score, $correct, $total, $json, $submitted, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    return ['ok' => true, 'score' => $score, 'correct' => $correct, 'total' => $total, 'breakdown' => $breakdown];
}

function diagnostic_exam_finalize_expired_in_progress(mysqli $conn, int $batchId = 0, int $userId = 0): int
{
    $where = ["status='in_progress'"];
    if ($batchId > 0) {
        $where[] = 'batch_id=' . (int)$batchId;
    }
    if ($userId > 0) {
        $where[] = 'user_id=' . (int)$userId;
    }
    if ($batchId <= 0 && $userId <= 0) {
        return 0;
    }
    $now = date('Y-m-d H:i:s');
    $where[] = "(expires_at IS NOT NULL AND expires_at <= '{$now}')";
    $sql = 'SELECT attempt_id, user_id FROM diagnostic_attempts WHERE ' . implode(' AND ', $where);
    $count = 0;
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $r = diagnostic_exam_finalize_attempt($conn, (int)$row['attempt_id'], (int)$row['user_id']);
            if (!empty($r['ok'])) {
                $count++;
            }
        }
        mysqli_free_result($res);
    }
    return $count;
}

function diagnostic_exam_count_assigned_examinees(mysqli $conn, int $batchId): int
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_count_assigned_examinees($conn, 'diagnostic', $batchId);
}

/** @deprecated alias */
function diagnostic_exam_count_assigned_students(mysqli $conn, int $batchId): int
{
    return diagnostic_exam_count_assigned_examinees($conn, $batchId);
}

function diagnostic_exam_professor_batch_summaries(mysqli $conn, int $professorId): array
{
    $out = [];
    $q = @mysqli_query($conn, 'SELECT * FROM diagnostic_batches WHERE created_by=' . (int)$professorId . ' ORDER BY updated_at DESC');
    if ($q) {
        while ($b = mysqli_fetch_assoc($q)) {
            $bid = (int)($b['batch_id'] ?? 0);
            $stats = diagnostic_exam_batch_stats_for_student($conn, $bid);
            $assigned = diagnostic_exam_count_assigned_examinees($conn, $bid);
            $submitted = 0;
            $sq = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM diagnostic_attempts WHERE batch_id={$bid} AND status='submitted'");
            if ($sq && ($sr = mysqli_fetch_assoc($sq))) {
                $submitted = (int)($sr['c'] ?? 0);
            }
            $b['_subject_count'] = $stats['subject_count'];
            $b['_question_count'] = $stats['question_count'];
            $b['_assigned'] = $assigned;
            $b['_submitted'] = $submitted;
            $out[] = $b;
        }
        mysqli_free_result($q);
    }
    return $out;
}

function diagnostic_exam_monitor_section_rows(mysqli $conn, int $batchId): array
{
    $batch = diagnostic_exam_load_batch($conn, $batchId);
    if (!$batch) {
        return [];
    }
    $scope = diagnostic_exam_normalize_examinee_scope((string)($batch['examinee_scope'] ?? 'college_student'));
    $mode = diagnostic_exam_normalize_assignment_mode((string)($batch['assignment_mode'] ?? 'sections'));
    if ($mode === 'users') {
        return [];
    }
    $scopeSql = diagnostic_exam_examinee_scope_sql($scope, 'u');
    $sections = diagnostic_exam_load_batch_sections($conn, $batchId);
    $rows = [];
    foreach ($sections as $sec) {
        $secEsc = mysqli_real_escape_string($conn, $sec);
        $total = 0;
        $submitted = 0;
        $inProgress = 0;
        $tq = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM users u WHERE ' . ereview_sql_college_examinee_where('u') . " AND {$scopeSql} AND TRIM(COALESCE(u.section,''))='{$secEsc}'");
        if ($tq && ($tr = mysqli_fetch_assoc($tq))) {
            $total = (int)($tr['c'] ?? 0);
        }
        $aq = @mysqli_query($conn, "
            SELECT a.status, COUNT(*) AS c
            FROM diagnostic_attempts a
            INNER JOIN users u ON u.user_id=a.user_id
            WHERE a.batch_id=" . (int)$batchId . " AND TRIM(COALESCE(u.section,''))='{$secEsc}' AND {$scopeSql}
            GROUP BY a.status
        ");
        if ($aq) {
            while ($ar = mysqli_fetch_assoc($aq)) {
                $st = (string)($ar['status'] ?? '');
                $c = (int)($ar['c'] ?? 0);
                if ($st === 'submitted' || $st === 'expired') {
                    $submitted += $c;
                } elseif ($st === 'in_progress') {
                    $inProgress += $c;
                }
            }
            mysqli_free_result($aq);
        }
        $notStarted = max(0, $total - $submitted - $inProgress);
        $rows[] = [
            'section' => $sec,
            'total' => $total,
            'submitted' => $submitted,
            'in_progress' => $inProgress,
            'not_started' => $notStarted,
            'completion_pct' => $total > 0 ? (int)round(($submitted / $total) * 100) : 0,
        ];
    }
    return $rows;
}

function diagnostic_exam_monitor_subject_averages(mysqli $conn, int $batchId): array
{
    $subjects = diagnostic_exam_load_batch_subjects($conn, $batchId);
    $out = [];
    $res = @mysqli_query($conn, "SELECT subject_breakdown_json FROM diagnostic_attempts WHERE batch_id=" . (int)$batchId . " AND status IN ('submitted','expired') AND subject_breakdown_json IS NOT NULL");
    $sums = [];
    $counts = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $decoded = json_decode((string)($row['subject_breakdown_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $item) {
                $sid = (int)($item['subject_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $sums[$sid] = ($sums[$sid] ?? 0.0) + (float)($item['score_pct'] ?? 0);
                $counts[$sid] = ($counts[$sid] ?? 0) + 1;
            }
        }
        mysqli_free_result($res);
    }
    foreach ($subjects as $bs) {
        $sid = (int)($bs['subject_id'] ?? 0);
        $cnt = (int)($counts[$sid] ?? 0);
        $avg = $cnt > 0 ? round(($sums[$sid] ?? 0) / $cnt, 1) : null;
        $out[] = [
            'subject_id' => $sid,
            'subject_code' => (string)($bs['subject_code'] ?? ''),
            'subject_name' => (string)($bs['subject_name'] ?? ''),
            'average_score' => $avg,
            'attempt_count' => $cnt,
        ];
    }
    return $out;
}

function diagnostic_exam_student_card_status(?array $attempt): string
{
    if (!$attempt) {
        return 'not_started';
    }
    $st = diagnostic_exam_attempt_status_normalized($attempt);
    if ($st === 'submitted') {
        return 'submitted';
    }
    if ($st === 'expired') {
        return 'expired';
    }
    if ($st === 'in_progress') {
        return 'in_progress';
    }
    return 'not_started';
}

function diagnostic_exam_student_card_label(string $status): string
{
    return match ($status) {
        'in_progress' => 'In progress',
        'submitted' => 'Submitted',
        'expired' => 'Expired',
        default => 'Not started',
    };
}

function diagnostic_exam_load_batch_user_details(mysqli $conn, int $batchId): array
{
    $ids = diagnostic_exam_load_batch_users($conn, $batchId);
    if ($ids === []) {
        return [];
    }
    $out = [];
    foreach ($ids as $uid) {
        $st = mysqli_prepare($conn, 'SELECT user_id, full_name, email, section, review_type, status FROM users WHERE user_id=? LIMIT 1');
        if (!$st) {
            continue;
        }
        mysqli_stmt_bind_param($st, 'i', $uid);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
        if ($row) {
            $out[] = $row;
        }
    }
    return $out;
}

function diagnostic_exam_examinee_type_label(string $reviewType): string
{
    return strtolower(trim($reviewType)) === 'undergrad' ? 'College Student' : 'Reviewee';
}

function diagnostic_exam_monitor_individual_rows(mysqli $conn, int $batchId): array
{
    $batch = diagnostic_exam_load_batch($conn, $batchId);
    if (!$batch) {
        return [];
    }
    $scope = diagnostic_exam_normalize_examinee_scope((string)($batch['examinee_scope'] ?? 'college_student'));
    $mode = diagnostic_exam_normalize_assignment_mode((string)($batch['assignment_mode'] ?? 'sections'));
    if ($mode !== 'users' && $mode !== 'sections_and_users') {
        return [];
    }
    $rows = [];
    foreach (diagnostic_exam_load_batch_users($conn, $batchId) as $uid) {
        $u = diagnostic_exam_load_examinee_user($conn, (int)$uid);
        if (!$u || !diagnostic_exam_user_matches_examinee_scope($u, $scope)) {
            continue;
        }
        $st = '';
        $aq = @mysqli_query($conn, 'SELECT status FROM diagnostic_attempts WHERE batch_id=' . (int)$batchId . ' AND user_id=' . (int)$uid . ' LIMIT 1');
        if ($aq && ($ar = mysqli_fetch_assoc($aq))) {
            $st = (string)($ar['status'] ?? '');
        }
        $detail = null;
        $dst = mysqli_prepare($conn, "SELECT full_name, email, section, review_type FROM users WHERE user_id=? LIMIT 1");
        if ($dst) {
            mysqli_stmt_bind_param($dst, 'i', $uid);
            mysqli_stmt_execute($dst);
            $detail = mysqli_fetch_assoc(mysqli_stmt_get_result($dst));
            mysqli_stmt_close($dst);
        }
        $rows[] = [
            'user_id' => (int)$uid,
            'full_name' => (string)($detail['full_name'] ?? ''),
            'email' => (string)($detail['email'] ?? ''),
            'section' => trim((string)($detail['section'] ?? '')),
            'review_type' => (string)($detail['review_type'] ?? ''),
            'attempt_status' => $st,
        ];
    }
    return $rows;
}

function diagnostic_exam_suggest_sections(mysqli $conn, string $scope = 'both'): array
{
    $sectionsFile = __DIR__ . '/college_sections.php';
    if (is_file($sectionsFile)) {
        require_once $sectionsFile;
        if (function_exists('college_sections_active_names')) {
            $master = college_sections_active_names($conn);
            if ($master !== []) {
                return $master;
            }
        }
    }
    $scopeSql = diagnostic_exam_examinee_scope_sql(diagnostic_exam_normalize_examinee_scope($scope), 'u');
    $out = [];
    $examineeSql = function_exists('ereview_sql_college_examinee_where')
        ? ereview_sql_college_examinee_where('u')
        : "u.role='college_student' AND u.status='approved'";
    $q = @mysqli_query($conn, "SELECT DISTINCT TRIM(u.section) AS sec FROM users u WHERE {$examineeSql} AND u.section IS NOT NULL AND TRIM(u.section) <> '' AND {$scopeSql} ORDER BY sec ASC LIMIT 100");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $out[] = (string)($r['sec'] ?? '');
        }
        mysqli_free_result($q);
    }
    return $out;
}

/**
 * Format a percentage for display without trailing zeroes.
 */
function diagnostic_exam_format_score_percent($value, bool $includeSymbol = true): string
{
    if (function_exists('college_exam_format_score_percent')) {
        return college_exam_format_score_percent($value, $includeSymbol);
    }
    if (!is_numeric($value)) {
        return $includeSymbol ? '0%' : '0';
    }
    $f = (float)$value;
    if (abs($f - round($f)) < 0.00001) {
        $s = (string)(int)round($f);
    } else {
        $s = rtrim(rtrim(sprintf('%.2f', $f), '0'), '.');
    }

    return $includeSymbol ? $s . '%' : $s;
}

/**
 * Visual performance band for diagnostic analytics (display guidance only).
 *
 * @return array{label:string, state:string}
 */
function diagnostic_exam_performance_band(float $pct): array
{
    if ($pct >= 80) {
        return ['label' => 'Strong', 'state' => 'strong'];
    }
    if ($pct >= 60) {
        return ['label' => 'Developing', 'state' => 'developing'];
    }

    return ['label' => 'Needs review', 'state' => 'needs_review'];
}

/**
 * Resolve an optional topic/category label from a diagnostic question row.
 */
function diagnostic_exam_question_topic_label(array $q): ?string
{
    foreach (['topic_name', 'topic', 'category_name', 'category', 'question_category', 'area_name', 'area'] as $key) {
        $v = trim((string)($q[$key] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }

    return null;
}

function diagnostic_exam_plain_text_preview(string $html, int $maxLen = 90): string
{
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
    if ($t === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($t) > $maxLen) {
            return rtrim(mb_substr($t, 0, $maxLen - 1)) . '…';
        }

        return $t;
    }
    if (strlen($t) > $maxLen) {
        return rtrim(substr($t, 0, $maxLen - 1)) . '…';
    }

    return $t;
}

/**
 * Build diagnostic result analytics for the student assessment report.
 *
 * @param list<array<string,mixed>> $questions
 * @param array<int,array<string,mixed>> $answersMap
 * @param list<array<string,mixed>> $breakdown
 * @return array<string,mixed>
 */
function diagnostic_exam_build_result_analytics(array $questions, array $answersMap, array $breakdown): array
{
    $correctC = 0;
    $wrongC = 0;
    $unansweredC = 0;
    $bySubject = [];
    $hasTopics = false;

    foreach ($questions as $q) {
        $qid = (int)($q['question_id'] ?? 0);
        $sid = (int)($q['subject_id'] ?? 0);
        $sel = strtoupper(trim((string)($answersMap[$qid]['selected_answer'] ?? '')));
        $exp = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
        $isCorrect = ($sel !== '' && $sel === $exp);
        $isAnswered = ($sel !== '');

        if (!$isAnswered) {
            $unansweredC++;
        } elseif ($isCorrect) {
            $correctC++;
        } else {
            $wrongC++;
        }

        if (!isset($bySubject[$sid])) {
            $bySubject[$sid] = [
                'subject_id' => $sid,
                'subject_code' => (string)($q['_subject_code'] ?? ''),
                'subject_name' => (string)($q['_subject_name'] ?? ''),
                'correct' => 0,
                'total' => 0,
                'topics' => [],
                'questions' => [],
            ];
        }
        $bySubject[$sid]['total']++;
        if ($isCorrect) {
            $bySubject[$sid]['correct']++;
        }

        $topicLabel = diagnostic_exam_question_topic_label($q);
        if ($topicLabel !== null) {
            $hasTopics = true;
            $tKey = function_exists('mb_strtolower') ? mb_strtolower($topicLabel) : strtolower($topicLabel);
            if (!isset($bySubject[$sid]['topics'][$tKey])) {
                $bySubject[$sid]['topics'][$tKey] = [
                    'label' => $topicLabel,
                    'correct' => 0,
                    'total' => 0,
                ];
            }
            $bySubject[$sid]['topics'][$tKey]['total']++;
            if ($isCorrect) {
                $bySubject[$sid]['topics'][$tKey]['correct']++;
            }
        }

        $bySubject[$sid]['questions'][] = [
            'question_id' => $qid,
            'preview' => diagnostic_exam_plain_text_preview((string)($q['question_text'] ?? '')),
            'is_correct' => $isCorrect,
            'is_answered' => $isAnswered,
            'selected' => $sel,
            'expected' => $exp,
        ];
    }

    $breakdownById = [];
    foreach ($breakdown as $item) {
        $bsid = (int)($item['subject_id'] ?? 0);
        $breakdownById[$bsid] = $item;
        if ($bsid > 0 && !isset($bySubject[$bsid])) {
            $bySubject[$bsid] = [
                'subject_id' => $bsid,
                'subject_code' => (string)($item['subject_code'] ?? ''),
                'subject_name' => (string)($item['subject_name'] ?? ''),
                'correct' => (int)($item['correct'] ?? 0),
                'total' => (int)($item['total'] ?? 0),
                'topics' => [],
                'questions' => [],
            ];
        }
    }

    $subjects = [];
    foreach ($bySubject as $sid => $agg) {
        $c = (int)$agg['correct'];
        $t = (int)$agg['total'];
        if ($t <= 0 && isset($breakdownById[$sid])) {
            $c = (int)($breakdownById[$sid]['correct'] ?? 0);
            $t = (int)($breakdownById[$sid]['total'] ?? 0);
        }
        $pct = $t > 0 ? (float)diagnostic_exam_compute_score_percentage($c, $t) : 0.0;
        if ($t > 0 && isset($breakdownById[$sid]) && is_numeric($breakdownById[$sid]['score_pct'] ?? null)) {
            $pct = (float)$breakdownById[$sid]['score_pct'];
        }
        $band = diagnostic_exam_performance_band($pct);

        $topicRows = [];
        foreach ($agg['topics'] as $top) {
            $tc = (int)$top['correct'];
            $tt = (int)$top['total'];
            $tp = $tt > 0 ? (float)diagnostic_exam_compute_score_percentage($tc, $tt) : 0.0;
            $topicRows[] = [
                'label' => (string)$top['label'],
                'correct' => $tc,
                'total' => $tt,
                'score_pct' => $tp,
                'band' => diagnostic_exam_performance_band($tp),
            ];
        }
        usort($topicRows, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        $strongTopics = [];
        $weakTopics = [];
        if (count($topicRows) >= 2) {
            $byScore = $topicRows;
            usort($byScore, static fn(array $a, array $b): int => ($b['score_pct'] <=> $a['score_pct']));
            foreach (array_slice($byScore, 0, 2) as $row) {
                if ($row['score_pct'] >= 80) {
                    $strongTopics[] = $row['label'];
                }
            }
            usort($byScore, static fn(array $a, array $b): int => ($a['score_pct'] <=> $b['score_pct']));
            foreach (array_slice($byScore, 0, 2) as $row) {
                if ($row['score_pct'] < 80) {
                    $weakTopics[] = $row['label'];
                }
            }
            $strongTopics = array_values(array_unique($strongTopics));
            $weakTopics = array_values(array_unique($weakTopics));
        }

        $subjects[] = [
            'subject_id' => (int)$sid,
            'subject_code' => (string)$agg['subject_code'],
            'subject_name' => (string)$agg['subject_name'],
            'correct' => $c,
            'total' => $t,
            'score_pct' => $pct,
            'band' => $band,
            'topics' => $topicRows,
            'strong_topics' => $strongTopics,
            'weak_topics' => $weakTopics,
            'questions' => $agg['questions'],
        ];
    }

    usort($subjects, static fn(array $a, array $b): int => strcmp($a['subject_code'], $b['subject_code']));

    $totalQ = count($questions);
    $overallPct = $totalQ > 0 ? (float)diagnostic_exam_compute_score_percentage($correctC, $totalQ) : 0.0;
    $overallBand = diagnostic_exam_performance_band($overallPct);

    $strongest = null;
    $weakest = null;
    $priorityTopic = null;
    $subjectsWithData = array_values(array_filter($subjects, static fn(array $s): bool => (int)$s['total'] > 0));

    if ($subjectsWithData !== []) {
        $sortedSubj = $subjectsWithData;
        usort($sortedSubj, static fn(array $a, array $b): int => ($b['score_pct'] <=> $a['score_pct']));
        $strongest = $sortedSubj[0];
        $weakest = $sortedSubj[count($sortedSubj) - 1];
    }

    if ($hasTopics) {
        $allTopics = [];
        foreach ($subjects as $s) {
            foreach ($s['topics'] as $topicRow) {
                if ((int)$topicRow['total'] > 0) {
                    $allTopics[] = $topicRow;
                }
            }
        }
        if ($allTopics !== []) {
            usort($allTopics, static fn(array $a, array $b): int => ($a['score_pct'] <=> $b['score_pct']));
            $priorityTopic = $allTopics[0];
        }
    }

    $heroInsight = '';
    if (count($subjectsWithData) >= 2 && $strongest && $weakest) {
        $strongCode = (string)($strongest['subject_code'] !== '' ? $strongest['subject_code'] : $strongest['subject_name']);
        $weakCodes = [];
        foreach ($subjectsWithData as $s) {
            if ((int)$s['subject_id'] === (int)$strongest['subject_id']) {
                continue;
            }
            if ((float)$s['score_pct'] <= (float)$weakest['score_pct'] + 0.001) {
                $weakCodes[] = (string)($s['subject_code'] !== '' ? $s['subject_code'] : $s['subject_name']);
            }
        }
        if ($weakCodes === [] && (int)$weakest['subject_id'] !== (int)$strongest['subject_id']) {
            $weakCodes[] = (string)($weakest['subject_code'] !== '' ? $weakest['subject_code'] : $weakest['subject_name']);
        }
        if ($weakCodes !== []) {
            $heroInsight = 'Your results show strong performance in ' . $strongCode
                . ', while ' . implode(', ', $weakCodes) . ' require additional review.';
        }
    } elseif (count($subjectsWithData) === 1) {
        $code = (string)($subjectsWithData[0]['subject_code'] !== '' ? $subjectsWithData[0]['subject_code'] : $subjectsWithData[0]['subject_name']);
        $heroInsight = 'This diagnostic covered ' . $code . '. Use the breakdown below to guide your study plan.';
    }

    if ($overallBand['state'] === 'strong') {
        $readinessNote = 'You are performing strongly on this diagnostic. Maintain consistency and address any remaining weak spots.';
    } elseif ($overallBand['state'] === 'developing') {
        $readinessNote = 'You are showing good progress but should focus on lower-performing subject areas before your next assessment.';
    } else {
        $readinessNote = 'Focus on the subjects and areas below that need the most review before your next study session.';
    }

    return [
        'correct' => $correctC,
        'incorrect' => $wrongC,
        'unanswered' => $unansweredC,
        'total' => $totalQ,
        'overall_pct' => $overallPct,
        'overall_band' => $overallBand,
        'accuracy_pct' => $overallPct,
        'subjects' => $subjects,
        'has_topics' => $hasTopics,
        'strongest_subject' => $strongest,
        'weakest_subject' => $weakest,
        'priority_topic' => $priorityTopic,
        'hero_insight' => $heroInsight,
        'readiness_note' => $readinessNote,
        'show_insights' => $subjectsWithData !== [],
    ];
}
