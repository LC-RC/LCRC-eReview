<?php
require_once __DIR__ . '/college_exam_helpers.php';
require_once __DIR__ . '/examination_eligibility.php';

/**
 * Shared student-progress rows for PDF / Excel exports (exam monitor).
 * Roster matches live monitor: assigned examinees ∪ anyone with an attempt.
 *
 * @return list<array{student_number:string,name:string,email:string,status:string,score:string,mark:string}>
 */
function exam_monitor_progress_export_rows(mysqli $conn, int $examId, int $examQuestionCount, bool $isFinished, string $sectionFilter = ''): array
{
    require_once __DIR__ . '/examination_monitor_helpers.php';
    $userIds = examination_monitor_college_reporting_user_ids($conn, $examId, $sectionFilter);
    if ($userIds === []) {
        return [];
    }

    $in = implode(',', array_map('intval', $userIds));
    $students = [];
    $sql = '
      SELECT
        u.user_id, u.full_name, u.email, u.student_number,
        a.status AS attempt_status, a.score, a.correct_count, a.total_count
      FROM users u
      LEFT JOIN college_exam_attempts a ON a.user_id=u.user_id AND a.exam_id=' . (int)$examId . '
      WHERE u.user_id IN (' . $in . ')
      ORDER BY u.full_name ASC
    ';
    $sres = @mysqli_query($conn, $sql);
    if ($sres) {
        while ($row = mysqli_fetch_assoc($sres)) {
            $students[] = $row;
        }
        mysqli_free_result($sres);
    }

    $rows = [];
    foreach ($students as $st) {
        $attemptStatus = (string)($st['attempt_status'] ?? '');

        $mark = '-';
        if ($isFinished && $attemptStatus !== 'submitted') {
            $status = 'Failed (Absent)';
            $score = '-';
        } elseif ($attemptStatus === 'in_progress') {
            $status = 'Taking';
            $score = '-';
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
            $score = '-';
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
