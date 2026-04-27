<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/chat_support_helpers.php';
require_once __DIR__ . '/../../includes/chat_support_ui.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ereview_chat_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!ereview_chat_tables_ready($conn)) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Support chat is not initialized. Please run migration 015 first.'], 503);
}

$sessionId = ereview_chat_safe_trim((string)($_POST['session_id'] ?? ''), 64);
$message = ereview_chat_safe_trim((string)($_POST['message'] ?? ''), 1000);
$plainLanguage = !empty($_POST['plain_language']) && ($_POST['plain_language'] === '1' || $_POST['plain_language'] === 'true');

if ($message === '') {
    ereview_chat_json_response(['ok' => false, 'error' => 'Message is required'], 422);
}

$userId = null;
if (isLoggedIn() && verifySession()) {
    $userId = getCurrentUserId();
}

$session = ereview_chat_get_or_create_session($conn, $sessionId, $userId);
$sessionId = (string)$session['session_id'];

ereview_chat_insert_message($conn, $sessionId, 'user', $message, 'user_message', 1.0, null, 0);
ereview_chat_log_event($conn, $sessionId, 'user_message', ['len' => strlen($message)]);

$reply = ereview_chat_generate_reply($conn, $message, $sessionId, $plainLanguage);
$intentForUi = (string) ($reply['intent'] ?? 'general');
$reply = ereview_chat_enrich_reply_ui($reply, $intentForUi);

if (!empty($reply['auto_create_ticket']) && function_exists('ereview_chat_v2_ready') && ereview_chat_v2_ready($conn)) {
    $dup = @mysqli_query(
        $conn,
        "SELECT ticket_id FROM support_tickets WHERE session_id = '" . mysqli_real_escape_string($conn, $sessionId) . "' AND status IN ('open','pending') LIMIT 1"
    );
    $hasOpen = $dup && mysqli_fetch_row($dup);
    if ($dup) {
        mysqli_free_result($dup);
    }
    if (!$hasOpen) {
        $transcript = ereview_chat_build_transcript_for_ticket($conn, $sessionId);
        $cat = isset($reply['ticket_category']) ? (string) $reply['ticket_category'] : 'general';
        ereview_chat_create_escalation_ticket(
            $conn,
            $sessionId,
            $userId,
            $cat,
            'LCRC chat — ' . $cat . ' — follow-up needed',
            $transcript
        );
        ereview_chat_log_event($conn, $sessionId, 'auto_ticket_created', ['category' => $cat]);
    }
}

ereview_chat_insert_message(
    $conn,
    $sessionId,
    'assistant',
    (string)$reply['text'],
    (string)$reply['intent'],
    (float)$reply['confidence'],
    (int)($reply['matched_article_id'] ?? 0),
    (int)($reply['needs_human'] ?? 0)
);

if (!empty($reply['needs_human'])) {
    ereview_chat_log_event($conn, $sessionId, 'unanswered_detected', [
        'intent' => (string)$reply['intent'],
        'confidence' => (float)$reply['confidence'],
    ]);
}
ereview_chat_maybe_queue_kb_backlog(
    $conn,
    $sessionId,
    $message,
    $intentForUi,
    (float)$reply['confidence'],
    (int)($reply['needs_human'] ?? 0)
);

ereview_chat_log_event($conn, $sessionId, 'assistant_reply', [
    'intent' => (string)$reply['intent'],
    'confidence' => (float)$reply['confidence'],
]);

$userMsgCount = 0;
$uc = @mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM support_chat_messages WHERE session_id = '" . mysqli_real_escape_string($conn, $sessionId) . "' AND role = 'user'"
);
if ($uc && ($rw = mysqli_fetch_assoc($uc))) {
    $userMsgCount = (int) ($rw['c'] ?? 0);
}
if ($uc) {
    mysqli_free_result($uc);
}

ereview_chat_json_response([
    'ok' => true,
    'session_id' => $sessionId,
    'reply' => [
        'role' => 'assistant',
        'text' => (string)$reply['text'],
        'intent' => (string)$reply['intent'],
        'confidence' => (float)$reply['confidence'],
        'needs_human' => !empty($reply['needs_human']),
        'actions' => $reply['actions'] ?? [],
        'cards' => $reply['cards'] ?? [],
        'quick_replies' => $reply['quick_replies'] ?? [],
        'next_step' => (string)($reply['next_step'] ?? ''),
        'show_csat' => $userMsgCount >= 2,
    ],
]);
