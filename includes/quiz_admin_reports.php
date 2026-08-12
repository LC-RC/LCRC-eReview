<?php
declare(strict_types=1);

require_once __DIR__ . '/schema_introspection.php';

/** @return list<array<string, mixed>> */
function quiz_admin_list_subjects(mysqli $conn): array
{
    $rows = [];
    $res = @mysqli_query($conn, 'SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    }
    return $rows;
}

/** @return list<array<string, mixed>> */
function quiz_admin_list_quizzes(mysqli $conn, int $subjectId): array
{
    if ($subjectId <= 0) {
        return [];
    }
    $rows = [];
    $stmt = mysqli_prepare($conn, 'SELECT quiz_id, title FROM quizzes WHERE subject_id = ? ORDER BY title ASC');
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $subjectId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * @return array{rows:list<array<string,mixed>>, total:int}
 */
function quiz_admin_fetch_attempts(mysqli $conn, array $filters): array
{
    if (!ereview_schema_table_exists($conn, 'quiz_attempts')) {
        return ['rows' => [], 'total' => 0];
    }

    $subjectId = (int) ($filters['subject_id'] ?? 0);
    $quizId = (int) ($filters['quiz_id'] ?? 0);
    $search = trim((string) ($filters['q'] ?? ''));
    $status = (string) ($filters['status'] ?? 'submitted');
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($subjectId > 0) {
        $where[] = 's.subject_id = ?';
        $types .= 'i';
        $params[] = $subjectId;
    }
    if ($quizId > 0) {
        $where[] = 'a.quiz_id = ?';
        $types .= 'i';
        $params[] = $quizId;
    }
    if ($status !== '' && $status !== 'all') {
        $where[] = 'a.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
        $types .= 'ss';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);
    $hasLastSeen = ereview_schema_column_exists($conn, 'quiz_attempts', 'last_seen_at');
    $hasTab = ereview_schema_column_exists($conn, 'quiz_attempts', 'tab_switch_count');
    $extraCols = '';
    if ($hasLastSeen) {
        $extraCols .= ', a.last_seen_at';
    }
    if ($hasTab) {
        $extraCols .= ', a.tab_switch_count, a.last_tab_switch_at';
    }

    $baseFrom = "FROM quiz_attempts a
      INNER JOIN users u ON u.user_id = a.user_id
      INNER JOIN quizzes q ON q.quiz_id = a.quiz_id
      INNER JOIN subjects s ON s.subject_id = q.subject_id
      WHERE {$whereSql}";

    $countSql = "SELECT COUNT(*) AS cnt {$baseFrom}";
    $stmt = mysqli_prepare($conn, $countSql);
    if (!$stmt) {
        return ['rows' => [], 'total' => 0];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $total = (int) ($countRow['cnt'] ?? 0);

    $offset = ($page - 1) * $perPage;
    $listSql = "SELECT a.attempt_id, a.user_id, a.quiz_id, a.status, a.score, a.correct_count, a.total_count,
        a.started_at, a.expires_at, a.submitted_at{$extraCols},
        u.full_name, u.email,
        q.title AS quiz_title,
        s.subject_id, s.subject_name
      {$baseFrom}
      ORDER BY COALESCE(a.submitted_at, a.started_at) DESC, a.attempt_id DESC
      LIMIT ? OFFSET ?";

    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt = mysqli_prepare($conn, $listSql);
    if (!$stmt) {
        return ['rows' => [], 'total' => $total];
    }
    mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);

    return ['rows' => $rows, 'total' => $total];
}

/** @return array<string,mixed>|null */
function quiz_admin_fetch_attempt(mysqli $conn, int $attemptId): ?array
{
    if ($attemptId <= 0 || !ereview_schema_table_exists($conn, 'quiz_attempts')) {
        return null;
    }
    $hasLastSeen = ereview_schema_column_exists($conn, 'quiz_attempts', 'last_seen_at');
    $extra = $hasLastSeen ? ', a.last_seen_at, a.tab_switch_count, a.last_tab_switch_at' : '';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT a.attempt_id, a.user_id, a.quiz_id, a.status, a.score, a.correct_count, a.total_count,
                a.started_at, a.expires_at, a.submitted_at{$extra},
                u.full_name, u.email,
                q.title AS quiz_title, s.subject_id, s.subject_name
         FROM quiz_attempts a
         INNER JOIN users u ON u.user_id = a.user_id
         INNER JOIN quizzes q ON q.quiz_id = a.quiz_id
         INNER JOIN subjects s ON s.subject_id = q.subject_id
         WHERE a.attempt_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $attemptId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function quiz_admin_fetch_attempt_questions(mysqli $conn, int $attemptId, int $quizId): array
{
    if ($attemptId <= 0 || $quizId <= 0) {
        return [];
    }
    $hasAttemptCol = ereview_schema_column_exists($conn, 'quiz_answers', 'attempt_id');
    if ($hasAttemptCol) {
        $sql = "SELECT qq.*, qa.selected_answer, qa.is_correct
                FROM quiz_questions qq
                LEFT JOIN quiz_answers qa ON qa.question_id = qq.question_id AND qa.attempt_id = ?
                WHERE qq.quiz_id = ?
                ORDER BY qq.question_id ASC";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $quizId);
    } else {
        $sql = "SELECT qq.*, qa.selected_answer, qa.is_correct
                FROM quiz_questions qq
                LEFT JOIN quiz_answers qa ON qa.question_id = qq.question_id
                WHERE qq.quiz_id = ?
                ORDER BY qq.question_id ASC";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'i', $quizId);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function quiz_admin_student_attempt_history(mysqli $conn, int $userId, int $quizId): array
{
    if ($userId <= 0 || $quizId <= 0 || !ereview_schema_table_exists($conn, 'quiz_attempts')) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT attempt_id, status, score, correct_count, total_count, started_at, submitted_at
         FROM quiz_attempts
         WHERE user_id = ? AND quiz_id = ?
         ORDER BY COALESCE(submitted_at, started_at) DESC, attempt_id DESC
         LIMIT 20"
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $quizId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function quiz_admin_format_score(?float $score): string
{
    if ($score === null) {
        return '-';
    }
    return number_format($score, 1) . '%';
}
