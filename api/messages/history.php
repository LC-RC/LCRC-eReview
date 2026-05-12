<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ereview_msg_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!isLoggedIn() || !verifySession()) {
    ereview_msg_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}
if (!ereview_msg_tables_ready($conn)) {
    ereview_msg_json(['ok' => false, 'error' => 'Messaging migration is not installed.'], 503);
}

$userId = (int)getCurrentUserId();
$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$threadStudentId = (int)($_GET['thread_student_id'] ?? 0);
if (!ereview_msg_can_access_thread($conn, $role, $userId, $threadStudentId)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$rows = ereview_msg_fetch_messages($conn, $threadStudentId, 800);
$items = [];
foreach ($rows as $m) {
    $direction = ((int)($m['sender_id'] ?? 0) === $userId) ? 'sent' : 'received';
    $createdAt = (string)($m['created_at'] ?? '');
    $createdAtHuman = (string)($m['created_at_human'] ?? '');
    $senderName = (string)($m['sender_name'] ?? '');
    $messageId = (int)($m['message_id'] ?? 0);

    $att = $m['attachment'] ?? null;
    if (is_array($att) && !empty($att['download_url'])) {
        $mime = (string)($att['mime_type'] ?? '');
        $kind = 'document';
        if (stripos($mime, 'image/') === 0) $kind = 'photo';
        elseif (stripos($mime, 'audio/') === 0) $kind = 'audio';
        $items[] = [
            'kind' => $kind,
            'direction' => $direction,
            'message_id' => $messageId,
            'created_at' => $createdAt,
            'created_at_human' => $createdAtHuman,
            'sender_name' => $senderName,
            'name' => (string)($att['orig_name'] ?? 'attachment'),
            'mime_type' => $mime,
            'size_bytes' => (int)($att['size_bytes'] ?? 0),
            'url' => (string)$att['download_url'],
        ];
    }

    $body = (string)($m['body'] ?? '');
    if ($body !== '') {
        if (preg_match_all('/https?:\/\/[^\s<>"\']+/i', $body, $matches) && !empty($matches[0])) {
            foreach (array_values(array_unique($matches[0])) as $u) {
                $host = (string)(parse_url($u, PHP_URL_HOST) ?? $u);
                $items[] = [
                    'kind' => 'link',
                    'direction' => $direction,
                    'message_id' => $messageId,
                    'created_at' => $createdAt,
                    'created_at_human' => $createdAtHuman,
                    'sender_name' => $senderName,
                    'name' => $host,
                    'mime_type' => 'text/link',
                    'size_bytes' => 0,
                    'url' => $u,
                ];
            }
        }
    }
}

ereview_msg_json([
    'ok' => true,
    'items' => $items,
]);
