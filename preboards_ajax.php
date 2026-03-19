<?php
/**
 * Preboards AJAX API: save answer, get remaining time.
 * Mirrors quiz_ajax.php behavior (server-side timer + state).
 */
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preboards_migrate.php';

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
    $stmt = mysqli_prepare($conn, "SELECT expires_at, status FROM preboards_attempts WHERE preboards_attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || $row['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    $remaining = !empty($row['expires_at']) ? max(0, strtotime($row['expires_at']) - time()) : 0;
    echo json_encode(['ok' => true, 'remaining_seconds' => $remaining]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;

