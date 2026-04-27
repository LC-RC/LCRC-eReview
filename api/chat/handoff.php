<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/chat_support_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ereview_chat_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!ereview_chat_tables_ready($conn)) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Support chat is not initialized. Please run migration 015 first.'], 503);
}

$sessionId = ereview_chat_safe_trim((string)($_POST['session_id'] ?? ''), 64);
if ($sessionId === '') {
    ereview_chat_json_response(['ok' => false, 'error' => 'Session ID is required'], 422);
}

$name = ereview_chat_safe_trim((string)($_POST['name'] ?? ''), 140);
$email = ereview_chat_safe_trim((string)($_POST['email'] ?? ''), 190);
$subject = ereview_chat_safe_trim((string)($_POST['subject'] ?? ''), 250);

$userId = null;
if (isLoggedIn() && verifySession()) {
    $userId = getCurrentUserId();
    if ($name === '' || $email === '') {
        $stmt = mysqli_prepare($conn, "SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $u = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if ($u) {
            if ($name === '') {
                $name = (string)($u['full_name'] ?? '');
            }
            if ($email === '') {
                $email = (string)($u['email'] ?? '');
            }
        }
    }
}

if ($subject === '') {
    $subject = 'Live support request from website chatbot';
}

$session = ereview_chat_get_or_create_session($conn, $sessionId, $userId);
$sessionId = (string)$session['session_id'];

$ticketId = ereview_chat_create_or_update_ticket($conn, $sessionId, $userId, $name, $email, $subject);
ereview_chat_insert_message(
    $conn,
    $sessionId,
    'system',
    'Live support handoff requested. Ticket #' . $ticketId . ' has been opened.',
    'handoff',
    1.0,
    null,
    0
);
ereview_chat_log_event($conn, $sessionId, 'handoff_requested', ['ticket_id' => $ticketId]);

ereview_chat_json_response([
    'ok' => true,
    'ticket_id' => $ticketId,
    'message' => 'Support ticket created. Our team will follow up shortly.',
]);
