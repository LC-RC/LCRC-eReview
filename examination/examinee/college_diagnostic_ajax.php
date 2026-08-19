<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';

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
diagnostic_exam_finalize_expired_in_progress($conn, 0, $userId);
$action = $_POST['action'] ?? '';

function diagnostic_ajax_load_active_attempt(mysqli $conn, int $attemptId, int $userId): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT attempt_id, batch_id, status, expires_at FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || ($attempt['status'] ?? '') !== 'in_progress') {
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
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $attempt = diagnostic_ajax_load_active_attempt($conn, $attemptId, $userId);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }
    $nowSql = date('Y-m-d H:i:s');
    $upd = mysqli_prepare($conn, 'UPDATE diagnostic_attempts SET tab_switch_count = COALESCE(tab_switch_count, 0) + 1, last_tab_switch_at=? WHERE attempt_id=? AND user_id=?');
    mysqli_stmt_bind_param($upd, 'sii', $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    $count = 0;
    $cr = mysqli_query($conn, 'SELECT tab_switch_count FROM diagnostic_attempts WHERE attempt_id=' . (int)$attemptId . ' LIMIT 1');
    if ($cr) {
        $crow = mysqli_fetch_assoc($cr);
        $count = (int)($crow['tab_switch_count'] ?? 0);
        mysqli_free_result($cr);
    }
    echo json_encode(['ok' => true, 'tab_switch_count' => $count, 'last_tab_switch_at' => $nowSql]);
    exit;
}

if ($action === 'save_answer') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
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

    $attempt = diagnostic_ajax_load_active_attempt($conn, $attemptId, $userId);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }

    $batchId = (int)($attempt['batch_id'] ?? 0);
    $stmt = mysqli_prepare($conn, 'SELECT question_id, correct_answer FROM diagnostic_questions WHERE question_id=? AND batch_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $questionId, $batchId);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$qRow) {
        echo json_encode(['ok' => false, 'error' => 'Invalid question']);
        exit;
    }
    $correctLetter = strtoupper(trim((string)($qRow['correct_answer'] ?? 'A')));
    $isCorrect = ($selected === $correctLetter) ? 1 : 0;

    $stmt = mysqli_prepare($conn, 'SELECT answer_id FROM diagnostic_answers WHERE attempt_id=? AND question_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $questionId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($existing) {
        $stmt = mysqli_prepare($conn, 'UPDATE diagnostic_answers SET selected_answer=?, is_correct=? WHERE answer_id=?');
        mysqli_stmt_bind_param($stmt, 'sii', $selected, $isCorrect, $existing['answer_id']);
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO diagnostic_answers (attempt_id, question_id, selected_answer, is_correct) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iisi', $attemptId, $questionId, $selected, $isCorrect);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => false, 'error' => 'Could not save']);
        exit;
    }
    mysqli_stmt_close($stmt);

    $answeredCount = 0;
    $cr = mysqli_query($conn, "SELECT COUNT(*) AS c FROM diagnostic_answers WHERE attempt_id=" . (int)$attemptId . " AND selected_answer IS NOT NULL AND selected_answer <> ''");
    if ($cr) {
        $answeredCount = (int)(mysqli_fetch_assoc($cr)['c'] ?? 0);
        mysqli_free_result($cr);
    }
    $nowSql = date('Y-m-d H:i:s');
    $touch = mysqli_prepare($conn, 'UPDATE diagnostic_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=?');
    mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($touch);
    mysqli_stmt_close($touch);

    echo json_encode(['ok' => true, 'saved_at' => date('H:i:s'), 'answered_count' => $answeredCount]);
    exit;
}

if ($action === 'sync_state') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $attempt = diagnostic_ajax_load_active_attempt($conn, $attemptId, $userId);
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
    $upd = mysqli_prepare($conn, 'UPDATE diagnostic_attempts SET ui_state_json=?, last_seen_at=? WHERE attempt_id=? AND user_id=?');
    mysqli_stmt_bind_param($upd, 'ssii', $json, $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
    exit;
}

if ($action === 'load_state') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, 'SELECT ui_state_json, status FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
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
    $stmt = mysqli_prepare($conn, 'SELECT expires_at, status FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || ($row['status'] ?? '') !== 'in_progress') {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    $expRaw = $row['expires_at'] ?? '';
    if ($expRaw === '') {
        echo json_encode(['ok' => true, 'remaining_seconds' => null]);
        exit;
    }
    $expTs = strtotime((string)$expRaw);
    $remaining = ($expTs !== false) ? max(0, $expTs - time()) : 0;
    echo json_encode(['ok' => true, 'remaining_seconds' => $remaining]);
    exit;
}

if ($action === 'submit') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $result = diagnostic_exam_finalize_attempt($conn, $attemptId, $userId);
    if (empty($result['ok'])) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Submit failed']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'score' => $result['score'],
        'correct' => $result['correct'],
        'total' => $result['total'],
        'breakdown' => $result['breakdown'] ?? [],
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
