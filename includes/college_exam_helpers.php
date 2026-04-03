<?php
require_once __DIR__ . '/simple_markdown.php';

/**
 * @param string|null $raw
 */
function ereview_render_exam_description(?string $raw, bool $isMarkdown): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    if ($isMarkdown) {
        return ereview_simple_markdown_html($raw);
    }
    return nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

/**
 * Shared helpers for college exam attempts (used by take page + AJAX).
 *
 * @param int $timeLimitSec
 * @param string|null $deadlineSql
 * @return string|null datetime MySQL
 */
function college_exam_compute_expires_at(int $timeLimitSec, ?string $deadlineSql): ?string
{
    $candidates = [];
    if ($timeLimitSec > 0) {
        $eff = $timeLimitSec;
        if ($deadlineSql !== null && $deadlineSql !== '') {
            $d = strtotime($deadlineSql);
            if ($d !== false) {
                $eff = min($eff, max(0, $d - time()));
            }
        }
        $candidates[] = time() + $eff;
    }
    if ($deadlineSql !== null && $deadlineSql !== '') {
        $d = strtotime($deadlineSql);
        if ($d !== false) {
            $candidates[] = $d;
        }
    }
    if (empty($candidates)) {
        return null;
    }
    return date('Y-m-d H:i:s', min($candidates));
}

/**
 * Roster size for professor library/monitor: attempt holders ∪ approved (or blank-status) college students.
 */
function college_exam_professor_roster_count(mysqli $conn, int $examId): int
{
    $examId = (int)$examId;
    if ($examId <= 0) {
        return 0;
    }
    $sql = "
      SELECT COUNT(*) AS c FROM (
        SELECT user_id FROM college_exam_attempts WHERE exam_id=" . $examId . "
        UNION
        SELECT u.user_id FROM users u
        WHERE u.role='college_student'
          AND (u.status='approved' OR u.status IS NULL OR TRIM(COALESCE(u.status,''))='')
      ) exam_roster
    ";
    $q = @mysqli_query($conn, $sql);
    if (!$q) {
        return 0;
    }
    $row = mysqli_fetch_assoc($q);
    mysqli_free_result($q);

    return (int)($row['c'] ?? 0);
}

/**
 * Exam with no deadline: treat as finished for UI/access once submitted attempts cover the full roster.
 * Roster = users with any attempt on this exam ∪ approved (or blank-status) college students.
 * Schema enforces one attempt per user per exam, so $submittedCount matches distinct submitters.
 *
 * @param array<string,mixed> $examRow college_exams row (needs exam_id, deadline)
 */
function college_exam_finished_all_submitted_no_deadline(mysqli $conn, array $examRow, int $submittedCount): bool
{
    $dead = trim((string)($examRow['deadline'] ?? ''));
    if ($dead !== '') {
        return false;
    }
    $eid = (int)($examRow['exam_id'] ?? 0);
    $n = college_exam_professor_roster_count($conn, $eid);

    return $n > 0 && $submittedCount >= $n;
}

/**
 * Human-readable duration for UI (e.g. professor validation, exam intro).
 */
function college_exam_human_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    $parts = [];
    if ($h > 0) {
        $parts[] = $h . ' hr' . ($h !== 1 ? 's' : '');
    }
    if ($m > 0) {
        $parts[] = $m . ' min' . ($m !== 1 ? 's' : '');
    }
    if ($s > 0 || $parts === []) {
        $parts[] = $s . ' sec' . ($s !== 1 ? 's' : '');
    }
    return implode(' ', $parts);
}

/**
 * Seconds from max(ref time, opens-at) until deadline. Null if there is no deadline.
 * Used to block publishing when the timer exceeds the remaining exam window.
 *
 * @param string|null $availableFromSql MySQL datetime or null (open immediately)
 * @param string|null $deadlineSql MySQL datetime
 */
function college_exam_seconds_exam_window_remaining(?string $availableFromSql, ?string $deadlineSql, ?int $refTs = null): ?int
{
    if ($deadlineSql === null || $deadlineSql === '') {
        return null;
    }
    $deadTs = strtotime($deadlineSql);
    if ($deadTs === false) {
        return null;
    }
    $t = $refTs ?? time();
    $windowStart = $t;
    if ($availableFromSql !== null && $availableFromSql !== '') {
        $av = strtotime($availableFromSql);
        if ($av !== false) {
            $windowStart = max($t, $av);
        }
    }
    return max(0, $deadTs - $windowStart);
}

/**
 * CEO grading curve: stored percentage = 50 + 0.5 × (traditional %), where traditional = 100 × correct / total.
 * Example: 40/50 correct → 50 + 0.5×80 = 90.00.
 */
function college_exam_compute_score_percentage(int $correct, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }

    $traditional = 100.0 * (float)$correct / (float)$total;

    return round(50.0 + 0.5 * $traditional, 2);
}

/**
 * Curved % for pass/fail and KPIs: prefer CEO formula from correct/total when available (fixes legacy rows where DB score is still the old 100×correct/total).
 *
 * @param mixed $storedScore Raw value from college_exam_attempts.score
 */
function college_exam_effective_percentage(?int $correctCount, ?int $totalCount, $storedScore, int $fallbackQuestionTotal = 0): float
{
    $tot = ($totalCount !== null && (int)$totalCount > 0) ? (int)$totalCount : (($fallbackQuestionTotal > 0) ? $fallbackQuestionTotal : null);
    if ($tot !== null) {
        $c = ($correctCount !== null) ? max(0, (int)$correctCount) : 0;

        return college_exam_compute_score_percentage($c, $tot);
    }

    return is_numeric($storedScore) ? (float)$storedScore : 0.0;
}

/**
 * Pass/Fail from raw items correct: pass when at least half right (24/50 fail, 25/50 pass).
 * Odd totals use ceil(total/2) (e.g. 26+ of 51).
 */
function college_exam_is_pass_half_correct(?int $correctCount, ?int $totalCount, int $fallbackQuestionTotal = 0): bool
{
    $tot = ($totalCount !== null && (int)$totalCount > 0) ? (int)$totalCount : (($fallbackQuestionTotal > 0) ? $fallbackQuestionTotal : null);
    if ($tot === null || $tot <= 0) {
        return false;
    }
    $c = ($correctCount !== null) ? max(0, (int)$correctCount) : 0;
    $need = (int)ceil($tot / 2.0);

    return $c >= $need;
}

/**
 * Display line: "correct/total | XX.XX%" for student list + monitor (total falls back to question count when missing).
 * Percentage is always the CEO curve when correct/total are known, not the stored score.
 *
 * @param mixed $score Percentage from DB (fallback when totals unknown)
 */
function college_exam_format_score_total_line(?int $correctCount, ?int $totalCount, $score, int $fallbackQuestionTotal = 0): string
{
    $tot = ($totalCount !== null && (int)$totalCount > 0) ? (int)$totalCount : (($fallbackQuestionTotal > 0) ? $fallbackQuestionTotal : null);
    if ($tot !== null) {
        $c = ($correctCount !== null) ? max(0, (int)$correctCount) : 0;
        $pctVal = college_exam_compute_score_percentage($c, $tot);
        $pct = number_format($pctVal, 2);

        return $c . '/' . $tot . ' | ' . $pct . '%';
    }

    $pct = is_numeric($score) ? number_format((float)$score, 2) : '0.00';

    return $pct . '%';
}

/**
 * Grade and mark attempt submitted.
 * @return array{ok:bool,score:?float,correct:?int,total:?int,error?:string}
 */
function college_exam_finalize_attempt(mysqli $conn, int $attemptId, int $userId): array
{
    $stmt = mysqli_prepare($conn, "SELECT attempt_id, exam_id, user_id, status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $att = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$att || $att['status'] !== 'in_progress') {
        return ['ok' => false, 'error' => 'Invalid attempt'];
    }

    $examId = (int)$att['exam_id'];

    $examRow = mysqli_query($conn, "SELECT * FROM college_exams WHERE exam_id=" . $examId . " LIMIT 1");
    $exam = $examRow ? mysqli_fetch_assoc($examRow) : null;
    if (!$exam) {
        return ['ok' => false, 'error' => 'Exam missing'];
    }

    $qres = mysqli_query($conn, "SELECT * FROM college_exam_questions WHERE exam_id=" . $examId . " ORDER BY sort_order ASC, question_id ASC");
    $questions = [];
    if ($qres) {
        while ($q = mysqli_fetch_assoc($qres)) {
            $questions[] = $q;
        }
        mysqli_free_result($qres);
    }
    $questions = college_exam_prepare_questions_for_attempt($questions, $exam, $attemptId);
    $total = count($questions);

    $ansRes = mysqli_query($conn, "SELECT answer_id, question_id, selected_answer FROM college_exam_answers WHERE attempt_id=" . (int)$attemptId);
    $byQ = [];
    if ($ansRes) {
        while ($r = mysqli_fetch_assoc($ansRes)) {
            $byQ[(int)$r['question_id']] = $r;
        }
        mysqli_free_result($ansRes);
    }

    $correct = 0;
    foreach ($questions as $q) {
        $qid = (int)$q['question_id'];
        $exp = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
        $sel = isset($byQ[$qid]) ? strtoupper(trim((string)($byQ[$qid]['selected_answer'] ?? ''))) : '';
        $isCorrect = ($sel !== '' && $sel === $exp) ? 1 : 0;
        if ($isCorrect) {
            $correct++;
        }
        if (isset($byQ[$qid])) {
            $aid = (int)$byQ[$qid]['answer_id'];
            $updA = mysqli_prepare($conn, "UPDATE college_exam_answers SET is_correct=? WHERE answer_id=?");
            mysqli_stmt_bind_param($updA, 'ii', $isCorrect, $aid);
            mysqli_stmt_execute($updA);
            mysqli_stmt_close($updA);
        }
    }

    $score = $total > 0 ? college_exam_compute_score_percentage($correct, $total) : 0.0;
    $submitted = date('Y-m-d H:i:s');

    $upd = mysqli_prepare($conn, "UPDATE college_exam_attempts SET status='submitted', score=?, correct_count=?, total_count=?, submitted_at=?, exam_session_lock=NULL WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($upd, 'diisii', $score, $correct, $total, $submitted, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    return ['ok' => true, 'score' => $score, 'correct' => $correct, 'total' => $total];
}

/**
 * Auto-submit in_progress attempts when the attempt timer or the exam deadline has passed
 * (student closed the browser / lost power). At least one scope filter must be set.
 *
 * @param int $examId >0: only this exam
 * @param int $userId >0: only this user
 * @param int $professorCreatedBy >0: only exams created by this professor
 * @return int Number of attempts successfully finalized
 */
function college_exam_finalize_expired_in_progress(mysqli $conn, int $examId = 0, int $userId = 0, int $professorCreatedBy = 0): int
{
    if ($examId <= 0 && $userId <= 0 && $professorCreatedBy <= 0) {
        return 0;
    }
    $nowSql = date('Y-m-d H:i:s');
    $sql = "
      SELECT a.attempt_id, a.user_id
      FROM college_exam_attempts a
      INNER JOIN college_exams e ON e.exam_id = a.exam_id
      WHERE a.status = 'in_progress'
      AND (
        (a.expires_at IS NOT NULL AND TRIM(COALESCE(a.expires_at, '')) <> ''
         AND a.expires_at NOT LIKE '0000-00-00%'
         AND a.expires_at <= ?)
        OR (e.deadline IS NOT NULL AND TRIM(COALESCE(e.deadline, '')) <> ''
            AND e.deadline NOT LIKE '0000-00-00%'
            AND e.deadline <= ?)
      )
    ";
    if ($examId > 0) {
        $sql .= ' AND a.exam_id = ' . (int)$examId;
    }
    if ($userId > 0) {
        $sql .= ' AND a.user_id = ' . (int)$userId;
    }
    if ($professorCreatedBy > 0) {
        $sql .= ' AND e.created_by = ' . (int)$professorCreatedBy;
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $nowSql, $nowSql);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);

    $n = 0;
    foreach ($rows as $r) {
        $aid = (int)($r['attempt_id'] ?? 0);
        $u = (int)($r['user_id'] ?? 0);
        if ($aid <= 0 || $u <= 0) {
            continue;
        }
        $out = college_exam_finalize_attempt($conn, $aid, $u);
        if (!empty($out['ok'])) {
            $n++;
        }
    }

    return $n;
}

/**
 * Normalize attempt status for comparisons (ENUM / mysqli type quirks).
 */
function college_exam_attempt_status_normalized(?array $attempt): string
{
    if ($attempt === null || !array_key_exists('status', $attempt)) {
        return '';
    }

    return strtolower(trim((string)$attempt['status']));
}

/**
 * True when the student should see results/review: status is submitted, or a real submitted_at
 * while not actively in progress (handles ENUM quirks, legacy rows, and finalized expired attempts).
 */
function college_exam_attempt_is_effectively_submitted(?array $attempt): bool
{
    if ($attempt === null) {
        return false;
    }
    $st = college_exam_attempt_status_normalized($attempt);
    if ($st === 'submitted') {
        return true;
    }
    if ($st === 'in_progress') {
        return false;
    }
    $sub = trim((string)($attempt['submitted_at'] ?? ''));
    if ($sub === '' || preg_match('/^0000-00-00/', $sub)) {
        return false;
    }

    return true;
}

/**
 * Display datetime on student result/review screens (invalid-safe).
 *
 * @param mixed $raw
 */
function college_exam_format_student_result_datetime($raw): string
{
    if ($raw === null) {
        return '—';
    }
    $s = trim((string)$raw);
    if ($s === '' || preg_match('/^0000-00-00/', $s)) {
        return '—';
    }
    $ts = strtotime($s);

    return $ts === false ? '—' : date('M j, Y g:i A', $ts);
}

/**
 * @return 'no_schedule'|'pending'|'open'|'ended'
 */
function college_exam_review_access_status(array $examRow, string $nowSql): string
{
    $from = trim((string)($examRow['review_sheet_available_from'] ?? ''));
    if ($from === '' || preg_match('/^0000-00-00/', $from)) {
        return 'no_schedule';
    }
    if ($from > $nowSql) {
        return 'pending';
    }
    $until = trim((string)($examRow['review_sheet_available_until'] ?? ''));
    if ($until !== '' && !preg_match('/^0000-00-00/', $until) && $until < $nowSql) {
        return 'ended';
    }

    return 'open';
}

/**
 * Whether the student may see the full per-question review sheet (not just the summary).
 */
function college_exam_review_sheet_is_open(array $examRow, string $nowSql): bool
{
    return college_exam_review_access_status($examRow, $nowSql) === 'open';
}

/**
 * Format MySQL datetime for HTML datetime-local input (empty if invalid).
 */
function college_exam_format_datetime_local(?string $sqlDt): string
{
    if ($sqlDt === null || trim((string)$sqlDt) === '') {
        return '';
    }
    $s = trim((string)$sqlDt);
    if (preg_match('/^0000-00-00/', $s)) {
        return '';
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $ts);
}

/**
 * Parse datetime-local or empty to MySQL datetime or null.
 */
function college_exam_parse_datetime_local(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $t = trim(str_replace('T', ' ', $raw));
    if ($t === '') {
        return null;
    }
    $ts = strtotime($t);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * Deterministic shuffle using crc32 — same attempt always sees same order.
 *
 * @param array<int, mixed> $items
 * @return array<int, mixed>
 */
function college_exam_shuffle_order(array $items, int $seed): array
{
    $indexed = array_values($items);
    $n = count($indexed);
    if ($n <= 1) {
        return $indexed;
    }
    $order = range(0, $n - 1);
    usort($order, function ($a, $b) use ($seed) {
        return crc32($seed . ':' . $a) <=> crc32($seed . ':' . $b);
    });
    $out = [];
    foreach ($order as $idx) {
        $out[] = $indexed[$idx];
    }
    return $out;
}

/**
 * @param array<string, mixed> $q
 * @return array<string, mixed>
 */
function college_exam_shuffle_question_choices(array $q, int $seed): array
{
    $letters = ['A', 'B', 'C', 'D'];
    $perm = college_exam_shuffle_order($letters, $seed);
    $out = $q;
    $co = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
    if (!preg_match('/^[A-D]$/', $co)) {
        $co = 'A';
    }
    for ($i = 0; $i < 4; $i++) {
        $newL = $letters[$i];
        $oldL = $perm[$i];
        $out['choice_' . strtolower($newL)] = $q['choice_' . strtolower($oldL)] ?? '';
    }
    for ($i = 0; $i < 4; $i++) {
        if ($perm[$i] === $co) {
            $out['correct_answer'] = $letters[$i];
            break;
        }
    }
    return $out;
}

/**
 * @param array<int, array<string, mixed>> $questions
 * @param array<string, mixed> $exam
 * @return array<int, array<string, mixed>>
 */
function college_exam_prepare_questions_for_attempt(array $questions, array $exam, int $attemptId): array
{
    $shuffleQ = !empty($exam['shuffle_questions']);
    $shuffleMcq = !empty($exam['shuffle_mcq_questions']) || $shuffleQ;
    $shuffleTf = !empty($exam['shuffle_tf_questions']) || $shuffleQ;
    $shuffleC = !empty($exam['shuffle_choices']);
    if (!$shuffleMcq && !$shuffleTf && !$shuffleC) {
        return $questions;
    }
    $examId = (int)$exam['exam_id'];
    $base = $attemptId * 100000 + $examId;
    $mcq = [];
    $tf = [];
    $other = [];
    foreach ($questions as $q) {
        $qt = strtolower(trim((string)($q['question_type'] ?? 'mcq')));
        if ($qt === 'tf' || $qt === 'true_false' || $qt === 'truefalse') {
            $tf[] = $q;
        } elseif ($qt === 'mcq' || $qt === '') {
            $mcq[] = $q;
        } else {
            $other[] = $q;
        }
    }
    if ($shuffleMcq) {
        $mcq = college_exam_shuffle_order($mcq, $base + 111);
    }
    if ($shuffleTf) {
        $tf = college_exam_shuffle_order($tf, $base + 222);
    }
    $out = array_merge($mcq, $tf, $other);
    if ($shuffleC) {
        foreach ($out as $i => $q) {
            $qt = strtolower(trim((string)($q['question_type'] ?? 'mcq')));
            if ($qt === 'tf' || $qt === 'true_false' || $qt === 'truefalse') {
                continue;
            }
            $qid = (int)($q['question_id'] ?? 0);
            $out[$i] = college_exam_shuffle_question_choices($q, $base + $qid * 7919);
        }
    }
    return $out;
}

/**
 * Correct letter (A–D) as shown to the student for this attempt (respects shuffle settings).
 */
function college_exam_shuffled_correct_answer_for_question(mysqli $conn, int $attemptId, int $userId, int $questionId): ?string
{
    $stmt = mysqli_prepare($conn, "SELECT exam_id FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $att = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$att) {
        return null;
    }
    $examId = (int)$att['exam_id'];
    $er = mysqli_query($conn, "SELECT * FROM college_exams WHERE exam_id=" . $examId . " LIMIT 1");
    $exam = $er ? mysqli_fetch_assoc($er) : null;
    if (!$exam) {
        return null;
    }
    $qres = mysqli_query($conn, "SELECT * FROM college_exam_questions WHERE exam_id=" . $examId . " ORDER BY sort_order ASC, question_id ASC");
    $questions = [];
    if ($qres) {
        while ($q = mysqli_fetch_assoc($qres)) {
            $questions[] = $q;
        }
        mysqli_free_result($qres);
    }
    $questions = college_exam_prepare_questions_for_attempt($questions, $exam, $attemptId);
    foreach ($questions as $q) {
        if ((int)$q['question_id'] === $questionId) {
            return strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
        }
    }
    return null;
}

/**
 * Whether a college_exams row is published (handles tinyint, string, BIT quirks from mysqli).
 */
function college_exam_row_is_published(array $e): bool
{
    if (!array_key_exists('is_published', $e)) {
        return false;
    }
    $p = $e['is_published'];
    if ($p === null) {
        return false;
    }
    if (is_bool($p)) {
        return $p;
    }
    if (is_int($p)) {
        return $p === 1;
    }
    if (is_float($p)) {
        return (int) round($p) === 1;
    }
    if (is_string($p)) {
        $t = trim($p);
        if ($t === '' || $t === '0') {
            return false;
        }
        if ($t === '1') {
            return true;
        }
        $lc = strtolower($t);
        if ($lc === 'yes' || $lc === 'true') {
            return true;
        }
        if (strlen($t) === 1) {
            return ord($t) === 1;
        }
    }
    return false;
}

/**
 * SQL fragment: row counts as "published" for college_exams (matches professor UI and avoids tinyint/string mismatches).
 *
 * @param string|null $tableAlias e.g. "e" for "e.is_published"
 */
function college_exam_where_published_sql(?string $tableAlias = null): string
{
    $col = 'is_published';
    if ($tableAlias !== null && $tableAlias !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableAlias)) {
        $col = $tableAlias . '.is_published';
    }
    return '(CAST(IFNULL(' . $col . ', 0) AS UNSIGNED) = 1 OR LOWER(TRIM(CAST(' . $col . ' AS CHAR))) IN (\'1\',\'yes\',\'true\'))';
}

/**
 * All published exams for the student list (same data as professor publishes).
 * ORDER BY fallbacks avoid failures on legacy rows missing deadline column.
 *
 * @return list<array<string,mixed>>
 */
function college_exams_load_published_exams(mysqli $conn): array
{
    $publishedWhere = college_exam_where_published_sql();
    $attempts = [
        "SELECT * FROM college_exams WHERE {$publishedWhere} ORDER BY deadline IS NULL, deadline ASC, title ASC",
        "SELECT * FROM college_exams WHERE {$publishedWhere} ORDER BY exam_id DESC",
    ];
    $q = false;
    foreach ($attempts as $sql) {
        $q = mysqli_query($conn, $sql);
        if ($q) {
            break;
        }
        error_log('college_exams_load_published_exams: ' . mysqli_error($conn));
    }
    if (!$q) {
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    mysqli_free_result($q);
    return $rows;
}

/**
 * Block password/Google/magic login when an in-progress exam is bound to another browser (exam_session_lock cookie).
 *
 * @return string|null Error message to show, or null if login may proceed.
 */
function college_exam_login_blocked_by_active_exam_session(mysqli $conn, int $userId, string $role): ?string
{
    if ($role !== 'college_student') {
        return null;
    }
    $stale = mysqli_prepare(
        $conn,
        "UPDATE college_exam_attempts SET exam_session_lock=NULL WHERE user_id=? AND status='in_progress' AND last_seen_at IS NOT NULL AND last_seen_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
    );
    if ($stale) {
        mysqli_stmt_bind_param($stale, 'i', $userId);
        mysqli_stmt_execute($stale);
        mysqli_stmt_close($stale);
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT attempt_id, exam_session_lock FROM college_exam_attempts WHERE user_id=? AND status='in_progress' AND exam_session_lock IS NOT NULL AND TRIM(exam_session_lock)<>''"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);
    foreach ($rows as $row) {
        $aid = (int)($row['attempt_id'] ?? 0);
        $lock = (string)($row['exam_session_lock'] ?? '');
        if ($aid <= 0 || $lock === '') {
            continue;
        }
        $cookieName = 'ereview_exam_lock_' . $aid;
        $cookieVal = $_COOKIE[$cookieName] ?? '';
        if (!hash_equals($lock, $cookieVal)) {
            return 'You are already taking an exam in another browser or device. Signing in from here is not allowed until you finish or submit that exam in the session where you started.';
        }
    }
    return null;
}

/**
 * Clear exam lock cookies when logging out (allows fresh login on this device).
 */
function college_exam_clear_exam_lock_cookies_for_user(mysqli $conn, int $userId): void
{
    $uid = (int)$userId;
    if ($uid <= 0) {
        return;
    }
    $q = mysqli_query($conn, 'SELECT attempt_id FROM college_exam_attempts WHERE user_id=' . $uid . " AND status='in_progress'");
    if (!$q) {
        return;
    }
    $exp = time() - 3600;
    $path = '/';
    while ($r = mysqli_fetch_assoc($q)) {
        $name = 'ereview_exam_lock_' . (int)$r['attempt_id'];
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, '', ['expires' => $exp, 'path' => $path, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            setcookie($name, '', $exp, $path . '; samesite=Lax', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
        }
    }
    mysqli_free_result($q);
}

/**
 * Release server-side exam session locks for in-progress attempts (call on logout so the student can sign in elsewhere).
 */
function college_exam_release_exam_session_locks(mysqli $conn, int $userId): void
{
    $uid = (int)$userId;
    if ($uid <= 0) {
        return;
    }
    $stmt = mysqli_prepare($conn, "UPDATE college_exam_attempts SET exam_session_lock=NULL WHERE user_id=? AND status='in_progress'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
