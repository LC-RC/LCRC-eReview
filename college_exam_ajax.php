<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = getCurrentUserId();
$action = $_POST['action'] ?? '';
$conn = $GLOBALS['conn'];

if ($action === 'save_answer') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $selected = strtoupper(trim((string)($_POST['selected_answer'] ?? '')));
    if (!preg_match('/^[A-D]$/', $selected)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid answer']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT a.attempt_id, a.exam_id, a.status, a.expires_at FROM college_exam_attempts a WHERE a.attempt_id=? AND a.user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$attempt || $attempt['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }
    $expRaw = $attempt['expires_at'] ?? '';
    if ($expRaw !== '') {
        $expTs = strtotime((string)$expRaw);
        if ($expTs !== false && $expTs < time()) {
            echo json_encode(['ok' => false, 'error' => 'Time expired']);
            exit;
        }
    }

    $stmt = mysqli_prepare($conn, "SELECT question_id, correct_answer FROM college_exam_questions WHERE question_id=? AND exam_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $questionId, $attempt['exam_id']);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$qRow) {
        echo json_encode(['ok' => false, 'error' => 'Invalid question']);
        exit;
    }
    $correctLetter = strtoupper(trim((string)($qRow['correct_answer'] ?? '')));
    $isCorrect = ($selected === $correctLetter) ? 1 : 0;

    $stmt = mysqli_prepare($conn, "SELECT answer_id FROM college_exam_answers WHERE attempt_id=? AND question_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $questionId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($existing) {
        $stmt = mysqli_prepare($conn, "UPDATE college_exam_answers SET selected_answer=?, is_correct=? WHERE answer_id=?");
        mysqli_stmt_bind_param($stmt, 'sii', $selected, $isCorrect, $existing['answer_id']);
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO college_exam_answers (attempt_id, question_id, selected_answer, is_correct) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iisi', $attemptId, $questionId, $selected, $isCorrect);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => false, 'error' => 'Could not save']);
        exit;
    }
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'get_time') {
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT expires_at, status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || $row['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    $expRaw2 = $row['expires_at'] ?? '';
    if ($expRaw2 === '') {
        echo json_encode(['ok' => true, 'remaining_seconds' => null]);
        exit;
    }
    $expTs2 = strtotime((string)$expRaw2);
    $remaining = ($expTs2 !== false) ? max(0, $expTs2 - time()) : 0;
    echo json_encode(['ok' => true, 'remaining_seconds' => $remaining]);
    exit;
}

if ($action === 'submit') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $result = college_exam_finalize_attempt($conn, $attemptId, $userId);
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Submit failed']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'score' => $result['score'],
        'correct' => $result['correct'],
        'total' => $result['total'],
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
