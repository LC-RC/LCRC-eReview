<?php
/**
 * Quiz AJAX API: save answer, get remaining time.
 * All state is server-side; prevents timer/answer manipulation.
 */
require_once __DIR__ . '/includes/quiz_http_debug.php';
require_once 'auth.php';
requireRole('student');

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

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

// ----- Save single answer -----
if ($action === 'save_answer') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $selected = strtoupper(trim((string)($_POST['selected_answer'] ?? '')));
    $validLetters = ['A','B','C','D','E','F','G','H','I','J'];
    if (!in_array($selected, $validLetters, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid answer']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT a.attempt_id, a.quiz_id, a.status, a.expires_at FROM quiz_attempts a WHERE a.attempt_id=? AND a.user_id=? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['ok' => false, 'error' => 'Server error (prepare attempt)']);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$attempt || $attempt['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'error' => 'Attempt not found or already submitted']);
        exit;
    }
    $expRaw = $attempt['expires_at'] ?? '';
    $expTs = ($expRaw !== '') ? strtotime((string) $expRaw) : false;
    if ($expTs === false || $expTs < time()) {
        echo json_encode(['ok' => false, 'error' => 'Time expired']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT question_id, correct_answer FROM quiz_questions WHERE question_id=? AND quiz_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $questionId, $attempt['quiz_id']);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$qRow) {
        echo json_encode(['ok' => false, 'error' => 'Invalid question']);
        exit;
    }
    $correctLetter = strtoupper(trim((string)($qRow['correct_answer'] ?? '')));
    $isCorrect = ($selected === $correctLetter) ? 1 : 0;

    $stmt = mysqli_prepare($conn, "SELECT answer_id FROM quiz_answers WHERE attempt_id=? AND question_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $questionId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($existing) {
        $stmt = mysqli_prepare($conn, "UPDATE quiz_answers SET selected_answer=?, is_correct=? WHERE answer_id=?");
        if (!$stmt) {
            echo json_encode(['ok' => false, 'error' => 'Server error (prepare update)']);
            exit;
        }
        mysqli_stmt_bind_param($stmt, 'sii', $selected, $isCorrect, $existing['answer_id']);
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO quiz_answers (user_id, question_id, attempt_id, selected_answer, is_correct) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['ok' => false, 'error' => 'Server error (prepare insert)']);
            exit;
        }
        mysqli_stmt_bind_param($stmt, 'iiisi', $userId, $questionId, $attemptId, $selected, $isCorrect);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => false, 'error' => 'Could not save answer', 'detail' => $err]);
        exit;
    }
    mysqli_stmt_close($stmt);

    $countRes = mysqli_query($conn, "SELECT COUNT(DISTINCT question_id) AS cnt FROM quiz_answers WHERE attempt_id=".(int)$attemptId);
    $answeredCount = $countRes ? (int)mysqli_fetch_assoc($countRes)['cnt'] : 0;
    echo json_encode(['ok' => true, 'answered_count' => $answeredCount]);
    exit;
}

// ----- Get remaining seconds (for timer sync) -----
if ($action === 'get_time') {
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT expires_at, status FROM quiz_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || $row['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    $expRaw2 = $row['expires_at'] ?? '';
    $expTs2 = ($expRaw2 !== '') ? strtotime((string) $expRaw2) : false;
    $remaining = ($expTs2 !== false) ? max(0, $expTs2 - time()) : 0;
    echo json_encode(['ok' => true, 'remaining_seconds' => $remaining]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
