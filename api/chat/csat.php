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
$rating = (int)($_POST['rating'] ?? 0);
$comment = ereview_chat_safe_trim((string)($_POST['comment'] ?? ''), 500);

if ($sessionId === '' || $rating < 1 || $rating > 5) {
    ereview_chat_json_response(['ok' => false, 'error' => 'session_id and rating 1–5 are required'], 422);
}

$chk = @mysqli_query(
    $conn,
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_chat_csat' LIMIT 1"
);
if (!$chk || !mysqli_fetch_row($chk)) {
    if ($chk) {
        mysqli_free_result($chk);
    }
    ereview_chat_json_response(['ok' => false, 'error' => 'CSAT table not installed. Run migration 017.'], 503);
}
mysqli_free_result($chk);

$sess = mysqli_prepare($conn, 'SELECT session_id FROM support_chat_sessions WHERE session_id = ? LIMIT 1');
mysqli_stmt_bind_param($sess, 's', $sessionId);
mysqli_stmt_execute($sess);
$res = mysqli_stmt_get_result($sess);
$ok = $res && mysqli_fetch_row($res);
mysqli_stmt_close($sess);
if (!$ok) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Invalid session'], 404);
}

$dup = mysqli_prepare($conn, 'SELECT csat_id FROM support_chat_csat WHERE session_id = ? LIMIT 1');
mysqli_stmt_bind_param($dup, 's', $sessionId);
mysqli_stmt_execute($dup);
$dr = mysqli_stmt_get_result($dup);
if ($dr && mysqli_fetch_row($dr)) {
    mysqli_stmt_close($dup);
    ereview_chat_json_response(['ok' => true, 'duplicate' => true]);
}
mysqli_stmt_close($dup);

$ins = mysqli_prepare(
    $conn,
    'INSERT INTO support_chat_csat (session_id, rating, comment, created_at) VALUES (?, ?, ?, NOW())'
);
$c = $comment !== '' ? $comment : '';
mysqli_stmt_bind_param($ins, 'sis', $sessionId, $rating, $c);
mysqli_stmt_execute($ins);
mysqli_stmt_close($ins);

ereview_chat_log_event($conn, $sessionId, 'csat_submitted', ['rating' => $rating]);

ereview_chat_json_response(['ok' => true]);
