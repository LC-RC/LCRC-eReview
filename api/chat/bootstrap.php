<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/chat_support_helpers.php';
require_once __DIR__ . '/../../includes/chat_support_ui.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ereview_chat_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!ereview_chat_tables_ready($conn)) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Support chat is not initialized. Please run migration 015 first.'], 503);
}

$sessionId = (string)($_GET['session_id'] ?? '');
$userId = null;
if (isLoggedIn() && verifySession()) {
    $userId = getCurrentUserId();
}

$session = ereview_chat_get_or_create_session($conn, $sessionId, $userId);
$messages = ereview_chat_fetch_recent_messages($conn, (string)$session['session_id'], 20);

if (!$messages) {
    $welcome = "Hi! I'm LCRC Support AI. I can help with packages, registration, enrollment, and account concerns.";
    ereview_chat_insert_message($conn, (string)$session['session_id'], 'assistant', $welcome, 'greeting', 1.0, null, 0);
    ereview_chat_log_event($conn, (string)$session['session_id'], 'chat_started', ['source' => 'home']);
    $messages = [['role' => 'assistant', 'text' => $welcome]];
}

$extras = ereview_chat_enrich_reply_ui(
    [
        'intent' => 'greeting',
        'cards' => [],
        'quick_replies' => [],
        'actions' => ereview_chat_quick_actions('greeting'),
    ],
    'greeting'
);

ereview_chat_json_response([
    'ok' => true,
    'session_id' => (string)$session['session_id'],
    'messages' => $messages,
    'quick_actions' => ereview_chat_quick_actions('general'),
    'assistant_extras' => $extras,
]);
