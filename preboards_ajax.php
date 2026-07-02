<?php
/**
 * Preboards AJAX API: save answer, get remaining time.
 * Mirrors quiz_ajax.php behavior (server-side timer + state).
 */
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/preboards_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = getCurrentUserId();
if (!$userId) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$conn = $GLOBALS['conn'];
$validLetters = ['A','B','C','D','E','F','G','H','I','J'];

if ($action === 'save_answer') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $selected = $_POST['selected_answer'] ?? '';
    if (!in_array($selected, $validLetters, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid answer']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT a.preboards_attempt_id, a.preboards_set_id, a.status, a.expires_at FROM preboards_attempts a WHERE a.preboards_attempt_id=? AND a.user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || $attempt['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'error' => 'Attempt not found or already submitted']);
        exit;
    }
    if (!empty($attempt['expires_at']) && strtotime($attempt['expires_at']) < time()) {
        echo json_encode(['ok' => false, 'error' => 'Time expired']);
        exit;
    }
    $setStmt = mysqli_prepare($conn, 'SELECT use_schedule, closes_at FROM preboards_sets WHERE preboards_set_id=? LIMIT 1');
    mysqli_stmt_bind_param($setStmt, 'i', $attempt['preboards_set_id']);
    mysqli_stmt_execute($setStmt);
    $setRow = mysqli_fetch_assoc(mysqli_stmt_get_result($setStmt));
    mysqli_stmt_close($setStmt);
    if ($setRow && preboards_set_uses_schedule($setRow) && !empty($setRow['closes_at']) && strtotime($setRow['closes_at']) < time()) {
        echo json_encode(['ok' => false, 'error' => 'Time expired']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT preboards_question_id, correct_answer FROM preboards_questions WHERE preboards_question_id=? AND preboards_set_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $questionId, $attempt['preboards_set_id']);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$qRow) {
        echo json_encode(['ok' => false, 'error' => 'Invalid question']);
        exit;
    }
    $isCorrect = (strtoupper($selected) === strtoupper($qRow['correct_answer'])) ? 1 : 0;

    $stmt = mysqli_prepare($conn, "INSERT INTO preboards_answers (preboards_attempt_id, preboards_question_id, selected_answer, is_correct) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE selected_answer=VALUES(selected_answer), is_correct=VALUES(is_correct), answered_at=CURRENT_TIMESTAMP");
    mysqli_stmt_bind_param($stmt, 'iisi', $attemptId, $questionId, $selected, $isCorrect);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $countRes = mysqli_query($conn, "SELECT COUNT(DISTINCT preboards_question_id) AS cnt FROM preboards_answers WHERE preboards_attempt_id=".(int)$attemptId);
    $answeredCount = $countRes ? (int)mysqli_fetch_assoc($countRes)['cnt'] : 0;
    echo json_encode(['ok' => true, 'answered_count' => $answeredCount]);
    exit;
}

if ($action === 'get_time') {
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT a.expires_at, a.status, a.preboards_set_id, s.use_schedule, s.closes_at, s.opens_at, s.time_limit_seconds
      FROM preboards_attempts a
      INNER JOIN preboards_sets s ON s.preboards_set_id = a.preboards_set_id
      WHERE a.preboards_attempt_id=? AND a.user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || $row['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    $remaining = 0;
    $now = time();
    $candidates = [];
    if (!empty($row['expires_at'])) {
        $exp = strtotime($row['expires_at']);
        if ($exp !== false) {
            $candidates[] = $exp;
        }
    }
    if (preboards_set_uses_schedule($row) && !empty($row['closes_at'])) {
        $closesTs = strtotime($row['closes_at']);
        if ($closesTs !== false) {
            $candidates[] = $closesTs;
        }
    }
    if ($candidates !== []) {
        $remaining = max(0, min($candidates) - $now);
    }
    echo json_encode(['ok' => true, 'remaining_seconds' => $remaining]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;

