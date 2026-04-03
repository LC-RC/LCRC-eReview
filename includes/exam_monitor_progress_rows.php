<?php
require_once __DIR__ . '/college_exam_helpers.php';

/**
 * Shared student-progress rows for PDF / Excel exports (exam monitor).
 *
 * @return list<array{student_number:string,name:string,email:string,status:string,score:string,mark:string}>
 */
function exam_monitor_progress_export_rows(mysqli $conn, int $examId, int $examQuestionCount, bool $isFinished): array
{
    $students = [];
    $sq = mysqli_prepare($conn, "
      SELECT
        u.user_id, u.full_name, u.email, u.student_number,
        a.status AS attempt_status, a.score, a.correct_count, a.total_count
      FROM users u
      INNER JOIN (
        SELECT user_id FROM college_exam_attempts WHERE exam_id=?
        UNION
        SELECT u2.user_id FROM users u2
        WHERE u2.role='college_student'
          AND (
            u2.status='approved' OR u2.status IS NULL OR TRIM(COALESCE(u2.status,''))=''
          )
      ) exam_roster ON exam_roster.user_id = u.user_id
      LEFT JOIN college_exam_attempts a ON a.user_id=u.user_id AND a.exam_id=?
      ORDER BY u.full_name ASC
    ");
    mysqli_stmt_bind_param($sq, 'ii', $examId, $examId);
    mysqli_stmt_execute($sq);
    $sres = mysqli_stmt_get_result($sq);
    if ($sres) {
        while ($row = mysqli_fetch_assoc($sres)) {
            $students[] = $row;
        }
    }
    mysqli_stmt_close($sq);

    $rows = [];
    foreach ($students as $st) {
        $attemptStatus = (string)($st['attempt_status'] ?? '');

        $mark = '—';
        if ($isFinished && $attemptStatus !== 'submitted') {
            $status = 'Failed (Absent)';
            $score = '—';
        } elseif ($attemptStatus === 'in_progress') {
            $status = 'Taking';
            $score = '—';
        } elseif ($attemptStatus === 'submitted') {
            $status = 'Submitted';
            $score = college_exam_format_score_total_line(
                isset($st['correct_count']) ? (int)$st['correct_count'] : null,
                isset($st['total_count']) ? (int)$st['total_count'] : null,
                $st['score'] ?? null,
                $examQuestionCount
            );
            $mark = college_exam_is_pass_half_correct(
                isset($st['correct_count']) ? (int)$st['correct_count'] : null,
                isset($st['total_count']) ? (int)$st['total_count'] : null,
                $examQuestionCount
            ) ? 'Pass' : 'Fail';
        } else {
            $status = 'Not started';
            $score = '—';
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
