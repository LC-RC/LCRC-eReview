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

$userId = (int)getCurrentUserId();
$conn = $GLOBALS['conn'];
college_exam_finalize_expired_in_progress($conn, 0, $userId, 0);
$action = $_POST['action'] ?? '';

/**
 * @return array<string,mixed>|null
 */
function college_exam_ajax_load_active_attempt(mysqli $conn, int $attemptId, int $userId): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT a.attempt_id, a.exam_id, a.status, a.expires_at FROM college_exam_attempts a WHERE a.attempt_id=? AND a.user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || $attempt['status'] !== 'in_progress') {
        return null;
    }
    $expRaw = $attempt['expires_at'] ?? '';
    if ($expRaw !== '') {
        $expTs = strtotime((string)$expRaw);
        if ($expTs !== false && $expTs < time()) {
            return null;
        }
    }
    return $attempt;
}

if ($action === 'tab_blur') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $attempt = college_exam_ajax_load_active_attempt($conn, $attemptId, $userId);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }
    $nowSql = date('Y-m-d H:i:s');
    $upd = mysqli_prepare(
        $conn,
        'UPDATE college_exam_attempts SET tab_switch_count = COALESCE(tab_switch_count, 0) + 1, last_tab_switch_at=? WHERE attempt_id=? AND user_id=?'
    );
    if (!$upd) {
        echo json_encode(['ok' => false, 'error' => 'Update failed']);
        exit;
    }
    mysqli_stmt_bind_param($upd, 'sii', $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    $count = 0;
    $cr = mysqli_query($conn, 'SELECT tab_switch_count FROM college_exam_attempts WHERE attempt_id=' . (int)$attemptId . ' LIMIT 1');
    if ($cr) {
        $crow = mysqli_fetch_assoc($cr);
        $count = (int)($crow['tab_switch_count'] ?? 0);
        mysqli_free_result($cr);
    }
    echo json_encode(['ok' => true, 'tab_switch_count' => $count, 'last_tab_switch_at' => $nowSql]);
    exit;
}

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

    $attempt = college_exam_ajax_load_active_attempt($conn, $attemptId, $userId);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT question_id FROM college_exam_questions WHERE question_id=? AND exam_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $questionId, $attempt['exam_id']);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$qRow) {
        echo json_encode(['ok' => false, 'error' => 'Invalid question']);
        exit;
    }
    $correctLetter = college_exam_shuffled_correct_answer_for_question($conn, $attemptId, $userId, $questionId);
    if ($correctLetter === null || !preg_match('/^[A-D]$/', $correctLetter)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid question']);
        exit;
    }
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

    $answeredCount = 0;
    $cr = mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_answers WHERE attempt_id=" . (int)$attemptId . " AND selected_answer IS NOT NULL AND selected_answer <> ''");
    if ($cr) {
        $answeredCount = (int)(mysqli_fetch_assoc($cr)['c'] ?? 0);
        mysqli_free_result($cr);
    }
    $nowSql = date('Y-m-d H:i:s');
    $touch = mysqli_prepare($conn, "UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($touch);
    mysqli_stmt_close($touch);

    echo json_encode([
        'ok' => true,
        'saved_at' => date('H:i:s'),
        'answered_count' => $answeredCount,
    ]);
    exit;
}

if ($action === 'sync_state') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $attempt = college_exam_ajax_load_active_attempt($conn, $attemptId, $userId);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }

    $currentIndex = sanitizeInt($_POST['current_index'] ?? 0);
    $flagsRaw = $_POST['flags'] ?? '[]';
    $flags = json_decode((string)$flagsRaw, true);
    if (!is_array($flags)) {
        $flags = [];
    }
    $cleanFlags = [];
    foreach ($flags as $qid) {
        $iv = (int)$qid;
        if ($iv > 0) {
            $cleanFlags[] = $iv;
        }
    }
    $cleanFlags = array_values(array_unique($cleanFlags));

    $state = [
        'current_index' => max(0, $currentIndex),
        'flags' => $cleanFlags,
        'updated_at' => time(),
    ];
    $json = json_encode($state);
    $nowSql = date('Y-m-d H:i:s');
    $upd = mysqli_prepare($conn, "UPDATE college_exam_attempts SET ui_state_json=?, last_seen_at=? WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($upd, 'ssii', $json, $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
    exit;
}

if ($action === 'load_state') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT ui_state_json, status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !in_array((string)$row['status'], ['in_progress', 'submitted'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not found']);
        exit;
    }
    $state = null;
    $raw = (string)($row['ui_state_json'] ?? '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }
    echo json_encode(['ok' => true, 'state' => $state]);
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
