<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/chat_support_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ereview_chat_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!ereview_chat_tables_ready($conn)) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Support chat is not initialized.'], 503);
}

$sessionId = ereview_chat_safe_trim((string)($_POST['session_id'] ?? ''), 64);
$eventType = ereview_chat_safe_trim((string)($_POST['event_type'] ?? ''), 40);

if ($sessionId === '') {
    ereview_chat_json_response(['ok' => false, 'error' => 'session_id is required'], 422);
}

$allowed = ['panel_open', 'panel_close', 'plain_language_on', 'plain_language_off'];
if (!in_array($eventType, $allowed, true)) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Invalid event_type'], 422);
}

$sess = mysqli_prepare($conn, 'SELECT session_id FROM support_chat_sessions WHERE session_id = ? LIMIT 1');
mysqli_stmt_bind_param($sess, 's', $sessionId);
mysqli_stmt_execute($sess);
$res = mysqli_stmt_get_result($sess);
$exists = $res && mysqli_fetch_row($res);
mysqli_stmt_close($sess);
if (!$exists) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Invalid session'], 404);
}

ereview_chat_log_event($conn, $sessionId, 'chat_' . $eventType, []);

$col = @mysqli_query(
    $conn,
    "SHOW COLUMNS FROM support_chat_sessions LIKE 'panel_open_count'"
);
if ($col && mysqli_num_rows($col) > 0) {
    mysqli_free_result($col);
    if ($eventType === 'panel_open') {
        mysqli_query(
            $conn,
            "UPDATE support_chat_sessions SET panel_open_count = panel_open_count + 1, last_panel_open_at = NOW() WHERE session_id = '" . mysqli_real_escape_string($conn, $sessionId) . "' LIMIT 1"
        );
    } elseif ($eventType === 'panel_close') {
        mysqli_query(
            $conn,
            "UPDATE support_chat_sessions SET last_panel_close_at = NOW() WHERE session_id = '" . mysqli_real_escape_string($conn, $sessionId) . "' LIMIT 1"
        );
    }
} elseif ($col) {
    mysqli_free_result($col);
}

ereview_chat_json_response(['ok' => true]);
