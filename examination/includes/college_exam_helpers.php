<?php
require_once dirname(__DIR__, 2) . '/includes/simple_markdown.php';

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
 * Whether a stored question_type is True/False.
 */
function college_exam_question_type_is_tf(?string $type): bool
{
    $t = strtolower(trim((string)$type));

    return $t === 'tf' || $t === 'true_false' || $t === 'truefalse';
}

/**
 * Display choices for student/review UI.
 * Radio/value letters remain A–D for answer persistence.
 * TF shows labels True/False without requiring C/D.
 *
 * @return list<array{letter:string,label:string,show_letter:bool}>
 */
function college_exam_question_display_choices(array $q): array
{
    if (college_exam_question_type_is_tf((string)($q['question_type'] ?? ''))) {
        return [
            ['letter' => 'A', 'label' => 'True', 'show_letter' => false],
            ['letter' => 'B', 'label' => 'False', 'show_letter' => false],
        ];
    }
    $out = [];
    foreach (['A' => 'choice_a', 'B' => 'choice_b', 'C' => 'choice_c', 'D' => 'choice_d'] as $L => $key) {
        $txt = $q[$key] ?? null;
        if ($txt === null || $txt === '') {
            continue;
        }
        $out[] = ['letter' => $L, 'label' => (string)$txt, 'show_letter' => true];
    }

    return $out;
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
 * Roster size for professor library/monitor: assigned examinees only.
 */
function college_exam_professor_roster_count(mysqli $conn, int $examId): int
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_count_assigned_examinees($conn, 'regular', $examId);
}

/**
 * Whether a student appears on the professor exam monitor roster for this exam.
 */
function college_exam_student_on_professor_monitor_roster(mysqli $conn, int $examId, int $studentUserId): bool
{
    require_once __DIR__ . '/examination_eligibility.php';
    $examId = (int)$examId;
    $studentUserId = (int)$studentUserId;
    if ($examId <= 0 || $studentUserId <= 0) {
        return false;
    }
    $ids = examination_assigned_roster_user_ids($conn, 'regular', $examId);

    return in_array($studentUserId, $ids, true);
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
 * Format a percentage for display without trailing zeroes (100 not 100.00, 87.5 stays 87.5).
 *
 * @param mixed $value Numeric percentage
 */
function college_exam_format_score_percent($value, bool $includeSymbol = true): string
{
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
        $pct = college_exam_format_score_percent($pctVal);

        return $c . '/' . $tot . ' | ' . $pct;
    }

    $pct = is_numeric($score) ? college_exam_format_score_percent((float)$score) : '0%';

    return $pct;
}

/**
 * Grade and mark attempt submitted.
 * @return array{ok:bool,score:?float,correct:?int,total:?int,error?:string}
 */
/**
 * Persist one MCQ answer for an in-progress attempt (insert or update).
 * Allowed even after expires_at so timeout flush can still land answers before finalize.
 *
 * @return array{ok:bool, error?:string, is_correct?:int}
 */
function college_exam_upsert_attempt_answer(mysqli $conn, int $attemptId, int $userId, int $questionId, string $selected): array
{
    $selected = strtoupper(trim($selected));
    if (!preg_match('/^[A-D]$/', $selected)) {
        return ['ok' => false, 'error' => 'Invalid answer'];
    }
    if ($attemptId <= 0 || $userId <= 0 || $questionId <= 0) {
        return ['ok' => false, 'error' => 'Invalid request'];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT attempt_id, exam_id, status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Lookup failed'];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || strtolower(trim((string)($attempt['status'] ?? ''))) !== 'in_progress') {
        return ['ok' => false, 'error' => 'Attempt not active'];
    }
    $examId = (int)($attempt['exam_id'] ?? 0);

    $er = mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . $examId . ' LIMIT 1');
    $exam = $er ? mysqli_fetch_assoc($er) : null;
    if ($er) {
        mysqli_free_result($er);
    }
    if (!$exam) {
        return ['ok' => false, 'error' => 'Invalid question'];
    }

    $qStmt = mysqli_prepare($conn, 'SELECT * FROM college_exam_questions WHERE question_id=? AND exam_id=? LIMIT 1');
    if (!$qStmt) {
        return ['ok' => false, 'error' => 'Invalid question'];
    }
    mysqli_stmt_bind_param($qStmt, 'ii', $questionId, $examId);
    mysqli_stmt_execute($qStmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($qStmt));
    mysqli_stmt_close($qStmt);
    if (!$qRow) {
        return ['ok' => false, 'error' => 'Invalid question'];
    }

    $correctLetter = college_exam_display_correct_letter_for_question($exam, $qRow, $attemptId);
    if ($correctLetter === null || !preg_match('/^[A-D]$/', $correctLetter)) {
        return ['ok' => false, 'error' => 'Invalid question'];
    }
    $isCorrect = ($selected === $correctLetter) ? 1 : 0;

    if (!college_exam_write_answer_row($conn, $attemptId, $questionId, $selected, $isCorrect)) {
        return ['ok' => false, 'error' => 'Could not save'];
    }

    return ['ok' => true, 'is_correct' => $isCorrect];
}

/**
 * Correct A–D letter as shown to the student for one question (choice shuffle only; no full exam scan).
 */
function college_exam_display_correct_letter_for_question(array $exam, array $q, int $attemptId): ?string
{
    $shuffleC = !empty($exam['shuffle_choices']);
    $qt = strtolower(trim((string)($q['question_type'] ?? 'mcq')));
    $isTf = ($qt === 'tf' || $qt === 'true_false' || $qt === 'truefalse');
    if ($shuffleC && !$isTf) {
        $examId = (int)($exam['exam_id'] ?? 0);
        $qid = (int)($q['question_id'] ?? 0);
        $base = $attemptId * 100000 + $examId;
        $q = college_exam_shuffle_question_choices($q, $base + $qid * 7919);
    }
    $letter = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));

    return preg_match('/^[A-D]$/', $letter) ? $letter : null;
}

/**
 * Fast upsert via unique (attempt_id, question_id).
 */
function college_exam_write_answer_row(mysqli $conn, int $attemptId, int $questionId, string $selected, int $isCorrect): bool
{
    $sql = 'INSERT INTO college_exam_answers (attempt_id, question_id, selected_answer, is_correct, answered_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              selected_answer = VALUES(selected_answer),
              is_correct = VALUES(is_correct),
              answered_at = NOW()';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'iisi', $attemptId, $questionId, $selected, $isCorrect);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return (bool)$ok;
}

/**
 * Upsert many answers from client payload (submit/timeout flush).
 * Bulk INSERT … ON DUPLICATE KEY UPDATE in chunks; loads exam/questions once.
 *
 * @param array<int, mixed> $rawAnswers
 * @return array{ok:bool, saved:int, errors:int, payload_count:int, skipped:int, error?:string, db_error?:string}
 */
function college_exam_upsert_attempt_answers_payload(mysqli $conn, int $attemptId, int $userId, array $rawAnswers): array
{
    $payloadCount = count($rawAnswers);
    $saved = 0;
    $errors = 0;
    $skipped = 0;
    if ($attemptId <= 0 || $userId <= 0) {
        return [
            'ok' => false,
            'saved' => 0,
            'errors' => $payloadCount,
            'payload_count' => $payloadCount,
            'skipped' => 0,
            'error' => 'Invalid attempt',
        ];
    }
    if ($rawAnswers === []) {
        return ['ok' => true, 'saved' => 0, 'errors' => 0, 'payload_count' => 0, 'skipped' => 0];
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT attempt_id, exam_id, status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1'
    );
    if (!$stmt) {
        return [
            'ok' => false,
            'saved' => 0,
            'errors' => $payloadCount,
            'payload_count' => $payloadCount,
            'skipped' => 0,
            'error' => 'Lookup failed',
            'db_error' => (string)mysqli_error($conn),
        ];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || strtolower(trim((string)($attempt['status'] ?? ''))) !== 'in_progress') {
        return [
            'ok' => false,
            'saved' => 0,
            'errors' => $payloadCount,
            'payload_count' => $payloadCount,
            'skipped' => 0,
            'error' => 'Attempt not active',
        ];
    }
    $examId = (int)($attempt['exam_id'] ?? 0);

    $er = mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . $examId . ' LIMIT 1');
    $exam = $er ? mysqli_fetch_assoc($er) : null;
    if ($er) {
        mysqli_free_result($er);
    }
    if (!$exam) {
        return [
            'ok' => false,
            'saved' => 0,
            'errors' => $payloadCount,
            'payload_count' => $payloadCount,
            'skipped' => 0,
            'error' => 'Exam missing',
        ];
    }

    $qres = mysqli_query($conn, 'SELECT * FROM college_exam_questions WHERE exam_id=' . $examId);
    $byId = [];
    if ($qres) {
        while ($q = mysqli_fetch_assoc($qres)) {
            $qid = (int)($q['question_id'] ?? 0);
            if ($qid > 0) {
                $byId[$qid] = $q;
            }
        }
        mysqli_free_result($qres);
    }

    $correctMap = [];
    foreach ($byId as $qid => $q) {
        $letter = college_exam_display_correct_letter_for_question($exam, $q, $attemptId);
        if ($letter !== null) {
            $correctMap[$qid] = $letter;
        }
    }

    // Deduplicate by question_id (last write wins — matches single-row upsert semantics).
    $rows = [];
    foreach ($rawAnswers as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }
        $qid = (int)($row['question_id'] ?? $row['qid'] ?? 0);
        $sel = strtoupper(trim((string)($row['selected_answer'] ?? $row['answer'] ?? '')));
        if ($qid <= 0 || !preg_match('/^[A-D]$/', $sel) || !isset($correctMap[$qid])) {
            $skipped++;
            continue;
        }
        $rows[$qid] = [
            'qid' => $qid,
            'sel' => $sel,
            'is_correct' => ($sel === $correctMap[$qid]) ? 1 : 0,
        ];
    }

    $valid = array_values($rows);
    $validCount = count($valid);
    if ($validCount === 0) {
        return [
            'ok' => true,
            'saved' => 0,
            'errors' => 0,
            'payload_count' => $payloadCount,
            'skipped' => $skipped,
        ];
    }

    $chunkSize = 40;
    for ($offset = 0; $offset < $validCount; $offset += $chunkSize) {
        $chunk = array_slice($valid, $offset, $chunkSize);
        $n = count($chunk);
        $placeholders = implode(',', array_fill(0, $n, '(?, ?, ?, ?, NOW())'));
        $sql = 'INSERT INTO college_exam_answers (attempt_id, question_id, selected_answer, is_correct, answered_at)
                VALUES ' . $placeholders . '
                ON DUPLICATE KEY UPDATE
                  selected_answer = VALUES(selected_answer),
                  is_correct = VALUES(is_correct),
                  answered_at = NOW()';
        $ins = mysqli_prepare($conn, $sql);
        if (!$ins) {
            return [
                'ok' => false,
                'saved' => $saved,
                'errors' => $validCount - $saved,
                'payload_count' => $payloadCount,
                'skipped' => $skipped,
                'error' => 'Prepare failed',
                'db_error' => (string)mysqli_error($conn),
            ];
        }
        $types = '';
        $bind = [];
        foreach ($chunk as $item) {
            $types .= 'iisi';
            $bind[] = $attemptId;
            $bind[] = $item['qid'];
            $bind[] = $item['sel'];
            $bind[] = $item['is_correct'];
        }
        if (!mysqli_stmt_bind_param($ins, $types, ...$bind)) {
            $dbErr = (string)mysqli_stmt_error($ins);
            mysqli_stmt_close($ins);

            return [
                'ok' => false,
                'saved' => $saved,
                'errors' => $validCount - $saved,
                'payload_count' => $payloadCount,
                'skipped' => $skipped,
                'error' => 'Bind failed',
                'db_error' => $dbErr !== '' ? $dbErr : (string)mysqli_error($conn),
            ];
        }
        if (!mysqli_stmt_execute($ins)) {
            $dbErr = (string)mysqli_stmt_error($ins);
            mysqli_stmt_close($ins);

            return [
                'ok' => false,
                'saved' => $saved,
                'errors' => $validCount - $saved,
                'payload_count' => $payloadCount,
                'skipped' => $skipped,
                'error' => 'Execute failed',
                'db_error' => $dbErr !== '' ? $dbErr : (string)mysqli_error($conn),
            ];
        }
        mysqli_stmt_close($ins);
        $saved += $n;
    }

    $ok = ($saved === $validCount);
    if (!$ok) {
        $errors = $validCount - $saved;
    }

    return [
        'ok' => $ok,
        'saved' => $saved,
        'errors' => $errors,
        'payload_count' => $payloadCount,
        'skipped' => $skipped,
    ];
}

function college_exam_finalize_attempt(mysqli $conn, int $attemptId, int $userId): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT attempt_id, exam_id, user_id, status, score, correct_count, total_count
         FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Lookup failed'];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $att = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$att) {
        return ['ok' => false, 'error' => 'Invalid attempt'];
    }
    $status = college_exam_attempt_status_normalized($att);
    if ($status === 'submitted') {
        return [
            'ok' => true,
            'already_submitted' => true,
            'score' => (float)($att['score'] ?? 0),
            'correct' => (int)($att['correct_count'] ?? 0),
            'total' => (int)($att['total_count'] ?? 0),
        ];
    }
    if ($status !== 'in_progress') {
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

    $upd = mysqli_prepare(
        $conn,
        "UPDATE college_exam_attempts SET status='submitted', score=?, correct_count=?, total_count=?, submitted_at=?, exam_session_lock=NULL
         WHERE attempt_id=? AND user_id=? AND status='in_progress'"
    );
    mysqli_stmt_bind_param($upd, 'diisii', $score, $correct, $total, $submitted, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    $affected = mysqli_stmt_affected_rows($upd);
    mysqli_stmt_close($upd);

    if ($affected < 1) {
        // Lost the race to another finalize — treat as idempotent success if submitted.
        $re = mysqli_prepare(
            $conn,
            'SELECT status, score, correct_count, total_count FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1'
        );
        if ($re) {
            mysqli_stmt_bind_param($re, 'ii', $attemptId, $userId);
            mysqli_stmt_execute($re);
            $again = mysqli_fetch_assoc(mysqli_stmt_get_result($re));
            mysqli_stmt_close($re);
            if ($again && college_exam_attempt_status_normalized($again) === 'submitted') {
                return [
                    'ok' => true,
                    'already_submitted' => true,
                    'score' => (float)($again['score'] ?? 0),
                    'correct' => (int)($again['correct_count'] ?? 0),
                    'total' => (int)($again['total_count'] ?? 0),
                ];
            }
        }

        return ['ok' => false, 'error' => 'Invalid attempt'];
    }

    if (is_file(__DIR__ . '/college_exam_attempt_events.php')) {
        require_once __DIR__ . '/college_exam_attempt_events.php';
        college_exam_attempt_event_record($conn, $attemptId, $userId, $examId, 'exam_submitted', [
            'score' => $score,
            'correct' => $correct,
            'total' => $total,
        ]);
    }

    return ['ok' => true, 'score' => $score, 'correct' => $correct, 'total' => $total];
}

/**
 * Seconds after official expires_at/deadline before server may auto-finalize.
 * Does NOT extend answering time — only protects in-flight timeout flush.
 */
function college_exam_finalize_expired_grace_seconds(): int
{
    return 60;
}

/**
 * Auto-submit in_progress attempts when the attempt timer or the exam deadline has passed
 * (student closed the browser / lost power). At least one scope filter must be set.
 *
 * Uses a grace window after expiry so client timeout flush can persist answers first.
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
    $grace = max(0, college_exam_finalize_expired_grace_seconds());
    $cutoffTs = time() - $grace;
    $cutoffSql = date('Y-m-d H:i:s', $cutoffTs);
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
    mysqli_stmt_bind_param($stmt, 'ss', $cutoffSql, $cutoffSql);
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
        return '-';
    }
    $s = trim((string)$raw);
    if ($s === '' || preg_match('/^0000-00-00/', $s)) {
        return '-';
    }
    $ts = strtotime($s);

    return $ts === false ? '-' : date('M j, Y g:i A', $ts);
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
 * Deterministic shuffle using crc32 - same attempt always sees same order.
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
 * Correct letter (A-D) as shown to the student for this attempt (respects shuffle settings).
 */
function college_exam_shuffled_correct_answer_for_question(mysqli $conn, int $attemptId, int $userId, int $questionId): ?string
{
    $stmt = mysqli_prepare($conn, 'SELECT exam_id FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $att = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$att) {
        return null;
    }
    $examId = (int)$att['exam_id'];
    $er = mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . $examId . ' LIMIT 1');
    $exam = $er ? mysqli_fetch_assoc($er) : null;
    if ($er) {
        mysqli_free_result($er);
    }
    if (!$exam) {
        return null;
    }
    $qStmt = mysqli_prepare($conn, 'SELECT * FROM college_exam_questions WHERE question_id=? AND exam_id=? LIMIT 1');
    if (!$qStmt) {
        return null;
    }
    mysqli_stmt_bind_param($qStmt, 'ii', $questionId, $examId);
    mysqli_stmt_execute($qStmt);
    $q = mysqli_fetch_assoc(mysqli_stmt_get_result($qStmt));
    mysqli_stmt_close($qStmt);
    if (!$q) {
        return null;
    }

    return college_exam_display_correct_letter_for_question($exam, $q, $attemptId);
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
 * @deprecated Prefer college_exams_load_assigned_published_exams_for_user()
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
 * Published regular exams assigned to the given student (visibility; schedule not required).
 *
 * @return list<array<string,mixed>>
 */
function college_exams_load_assigned_published_exams_for_user(mysqli $conn, int $userId): array
{
    require_once __DIR__ . '/examination_eligibility.php';
    if ($userId <= 0) {
        return [];
    }
    $out = [];
    foreach (college_exams_load_published_exams($conn) as $exam) {
        if (examination_user_is_assigned($conn, $userId, $exam, 'regular')) {
            $out[] = $exam;
        }
    }

    return $out;
}

function college_exam_user_is_assigned(mysqli $conn, int $userId, array $examRow): bool
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_user_is_assigned($conn, $userId, $examRow, 'regular');
}

function college_exam_user_can_start(mysqli $conn, int $userId, array $examRow, string $nowSql): bool
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_user_can_start_exam($conn, $userId, $examRow, 'regular', $nowSql);
}

function college_exam_user_can_view(mysqli $conn, int $userId, array $examRow, ?array $attempt = null): bool
{
    require_once __DIR__ . '/examination_eligibility.php';

    return examination_user_can_view_exam($conn, $userId, $examRow, 'regular', $attempt);
}

/**
 * Set HttpOnly exam session lock cookie (shared path for all app routes).
 */
function college_exam_set_session_lock_cookie(string $cookieName, string $token): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $expires = $token === '' ? time() - 3600 : time() + 86400 * 30;
    if (PHP_VERSION_ID >= 70300) {
        setcookie($cookieName, $token, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($cookieName, $token, $expires, '/; samesite=Lax', '', $secure, true);
    }
}

/**
 * Bind or refresh the browser session lock for an in-progress attempt.
 * Quizzers-style: same user may resume in this browser even if the cookie was lost
 * (refresh, new tab, cleared cookies). Reclaims the lock instead of hard-blocking.
 *
 * @return array{ok:bool, blocked:bool}
 */
function college_exam_ensure_attempt_session_lock(mysqli $conn, int $attemptId, int $userId): array
{
    if ($attemptId <= 0 || $userId <= 0) {
        return ['ok' => false, 'blocked' => true];
    }

    $stale = mysqli_prepare(
        $conn,
        "UPDATE college_exam_attempts SET exam_session_lock=NULL WHERE attempt_id=? AND user_id=? AND status='in_progress' AND last_seen_at IS NOT NULL AND last_seen_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
    );
    if ($stale) {
        mysqli_stmt_bind_param($stale, 'ii', $attemptId, $userId);
        mysqli_stmt_execute($stale);
        mysqli_stmt_close($stale);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT exam_session_lock FROM college_exam_attempts WHERE attempt_id=? AND user_id=? AND status='in_progress' LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'blocked' => true];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => false, 'blocked' => true];
    }

    $cookieName = 'ereview_exam_lock_' . $attemptId;
    $lock = trim((string)($row['exam_session_lock'] ?? ''));
    $cookieVal = (string)($_COOKIE[$cookieName] ?? '');

    if ($lock !== '' && hash_equals($lock, $cookieVal)) {
        return ['ok' => true, 'blocked' => false];
    }

    $newLock = bin2hex(random_bytes(32));
    $upd = mysqli_prepare(
        $conn,
        "UPDATE college_exam_attempts SET exam_session_lock=? WHERE attempt_id=? AND user_id=? AND status='in_progress'"
    );
    if (!$upd) {
        return ['ok' => false, 'blocked' => true];
    }
    mysqli_stmt_bind_param($upd, 'sii', $newLock, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    college_exam_set_session_lock_cookie($cookieName, $newLock);

    return ['ok' => true, 'blocked' => false];
}

/**
 * Block password/Google/magic login when an in-progress exam is bound to another browser (exam_session_lock cookie).
 *
 * @return string|null Error message to show, or null if login may proceed.
 */
function college_exam_login_blocked_by_active_exam_session(mysqli $conn, int $userId): ?string
{
    require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
    if (!ereview_user_has_college_examination_access($conn, $userId)) {
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
            // Quizzers-style resume: allow login; lock re-binds when the student opens the exam.
            $clr = mysqli_prepare($conn, 'UPDATE college_exam_attempts SET exam_session_lock=NULL WHERE attempt_id=? AND user_id=?');
            if ($clr) {
                mysqli_stmt_bind_param($clr, 'ii', $aid, $userId);
                mysqli_stmt_execute($clr);
                mysqli_stmt_close($clr);
            }
            college_exam_set_session_lock_cookie($cookieName, '');
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
        college_exam_set_session_lock_cookie($name, '');
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
