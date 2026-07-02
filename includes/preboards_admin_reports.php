<?php
declare(strict_types=1);

require_once __DIR__ . '/preboards_helpers.php';

/** @return list<array<string, mixed>> */
function preboards_admin_list_subjects(mysqli $conn): array
{
    $rows = [];
    $res = mysqli_query($conn, "SELECT preboards_subject_id, subject_name, status FROM preboards_subjects ORDER BY subject_name ASC");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    }
    return $rows;
}

/** @return list<array<string, mixed>> */
function preboards_admin_list_sets(mysqli $conn, int $subjectId): array
{
    if ($subjectId <= 0) {
        return [];
    }
    $rows = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT preboards_set_id, set_label, title FROM preboards_sets WHERE preboards_subject_id=? ORDER BY set_label ASC"
    );
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
function preboards_admin_fetch_attempts(mysqli $conn, array $filters): array
{
    $subjectId = (int) ($filters['subject_id'] ?? 0);
    $setId = (int) ($filters['set_id'] ?? 0);
    $search = trim((string) ($filters['q'] ?? ''));
    $status = (string) ($filters['status'] ?? 'submitted');
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($subjectId > 0) {
        $where[] = 'sub.preboards_subject_id = ?';
        $types .= 'i';
        $params[] = $subjectId;
    }
    if ($setId > 0) {
        $where[] = 'a.preboards_set_id = ?';
        $types .= 'i';
        $params[] = $setId;
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
    $baseFrom = "FROM preboards_attempts a
      INNER JOIN users u ON u.user_id = a.user_id
      INNER JOIN preboards_sets s ON s.preboards_set_id = a.preboards_set_id
      INNER JOIN preboards_subjects sub ON sub.preboards_subject_id = s.preboards_subject_id
      WHERE {$whereSql}";

    $countSql = "SELECT COUNT(*) AS cnt {$baseFrom}";
    $stmt = mysqli_prepare($conn, $countSql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $total = (int) ($countRow['cnt'] ?? 0);

    $offset = ($page - 1) * $perPage;
    $listSql = "SELECT a.preboards_attempt_id, a.user_id, a.preboards_set_id, a.attempt_no, a.status,
        a.score, a.correct_count, a.total_count, a.started_at, a.expires_at, a.submitted_at,
        u.full_name, u.email,
        s.set_label, s.title AS set_title,
        sub.preboards_subject_id, sub.subject_name
      {$baseFrom}
      ORDER BY COALESCE(a.submitted_at, a.started_at) DESC, a.preboards_attempt_id DESC
      LIMIT ? OFFSET ?";

    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt = mysqli_prepare($conn, $listSql);
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

/**
 * Top 10 by best submitted score (per set if set_id > 0, else best single-set score in subject).
 *
 * @return list<array<string,mixed>>
 */
function preboards_admin_fetch_top10(mysqli $conn, int $subjectId, int $setId = 0): array
{
    if ($subjectId <= 0) {
        return [];
    }

    if ($setId > 0) {
        $sql = "SELECT u.user_id, u.full_name, u.email,
            MAX(a.score) AS best_score,
            MAX(a.correct_count) AS best_correct,
            MAX(a.total_count) AS best_total,
            MAX(a.submitted_at) AS last_submitted,
            MAX(a.attempt_no) AS attempts_taken,
            s.set_label
          FROM preboards_attempts a
          INNER JOIN users u ON u.user_id = a.user_id
          INNER JOIN preboards_sets s ON s.preboards_set_id = a.preboards_set_id
          WHERE a.status = 'submitted'
            AND s.preboards_subject_id = ?
            AND a.preboards_set_id = ?
          GROUP BY u.user_id, u.full_name, u.email, s.set_label
          ORDER BY best_score DESC, best_correct DESC, last_submitted ASC
          LIMIT 10";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ii', $subjectId, $setId);
    } else {
        $sql = "SELECT u.user_id, u.full_name, u.email,
            MAX(a.score) AS best_score,
            MAX(a.correct_count) AS best_correct,
            MAX(a.total_count) AS best_total,
            MAX(a.submitted_at) AS last_submitted,
            SUBSTRING_INDEX(GROUP_CONCAT(
              CONCAT(s.set_label, '|', a.preboards_set_id)
              ORDER BY a.score DESC, a.correct_count DESC, a.submitted_at ASC
              SEPARATOR ','
            ), ',', 1) AS set_pick
          FROM preboards_attempts a
          INNER JOIN users u ON u.user_id = a.user_id
          INNER JOIN preboards_sets s ON s.preboards_set_id = a.preboards_set_id
          WHERE a.status = 'submitted' AND s.preboards_subject_id = ?
          GROUP BY u.user_id, u.full_name, u.email
          ORDER BY best_score DESC, best_correct DESC, last_submitted ASC
          LIMIT 10";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $subjectId);
    }

    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $rank = 1;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $setPick = (string) ($r['set_pick'] ?? '');
        unset($r['set_pick']);
        if ($setPick !== '' && strpos($setPick, '|') !== false) {
            [$setLabel, $setIdPick] = explode('|', $setPick, 2);
            $r['set_label'] = $setLabel;
            $r['preboards_set_id'] = (int) $setIdPick;
        }
        $r['rank'] = $rank++;
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/** @return array<string,mixed>|null */
function preboards_admin_fetch_attempt(mysqli $conn, int $attemptId): ?array
{
    if ($attemptId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT a.*, u.full_name, u.email,
          s.set_label, s.title AS set_title, s.preboards_subject_id,
          sub.subject_name
        FROM preboards_attempts a
        INNER JOIN users u ON u.user_id = a.user_id
        INNER JOIN preboards_sets s ON s.preboards_set_id = a.preboards_set_id
        INNER JOIN preboards_subjects sub ON sub.preboards_subject_id = s.preboards_subject_id
        WHERE a.preboards_attempt_id = ?
        LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $attemptId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function preboards_admin_fetch_attempt_questions(mysqli $conn, int $attemptId, int $setId): array
{
    $rows = [];
    $sql = "SELECT pq.*, pa.selected_answer, pa.is_correct
      FROM preboards_questions pq
      LEFT JOIN preboards_answers pa
        ON pa.preboards_question_id = pq.preboards_question_id
       AND pa.preboards_attempt_id = ?
      WHERE pq.preboards_set_id = ?
      ORDER BY pq.sort_order ASC, pq.preboards_question_id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $setId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/** @return list<array<string,mixed>> */
function preboards_admin_student_attempt_history(mysqli $conn, int $userId, int $setId): array
{
    if ($userId <= 0 || $setId <= 0) {
        return [];
    }
    $rows = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT preboards_attempt_id, attempt_no, status, score, correct_count, total_count, started_at, submitted_at
         FROM preboards_attempts
         WHERE user_id = ? AND preboards_set_id = ?
         ORDER BY attempt_no DESC, preboards_attempt_id DESC"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $setId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}
