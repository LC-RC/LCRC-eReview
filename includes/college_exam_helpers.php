<?php
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
        $candidates[] = time() + $timeLimitSec;
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
    $totalRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=" . $examId);
    $total = $totalRes ? (int)mysqli_fetch_assoc($totalRes)['c'] : 0;

    $correctRes = mysqli_query($conn, "
      SELECT COUNT(*) AS c FROM college_exam_answers a
      INNER JOIN college_exam_questions q ON q.question_id=a.question_id
      WHERE a.attempt_id=" . (int)$attemptId . " AND a.is_correct=1 AND q.exam_id=" . $examId . "
    ");
    $correct = $correctRes ? (int)mysqli_fetch_assoc($correctRes)['c'] : 0;

    $score = $total > 0 ? round(100 * $correct / $total, 2) : 0.0;
    $submitted = date('Y-m-d H:i:s');

    $upd = mysqli_prepare($conn, "UPDATE college_exam_attempts SET status='submitted', score=?, correct_count=?, total_count=?, submitted_at=? WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($upd, 'diisii', $score, $correct, $total, $submitted, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    return ['ok' => true, 'score' => $score, 'correct' => $correct, 'total' => $total];
}
