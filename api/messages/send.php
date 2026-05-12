<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ereview_msg_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!isLoggedIn() || !verifySession()) {
    ereview_msg_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}
if (!ereview_msg_tables_ready($conn)) {
    ereview_msg_json(['ok' => false, 'error' => 'Messaging migration is not installed. Run migration 021.'], 503);
}

$userId = (int)getCurrentUserId();
$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$body = trim((string)($_POST['body'] ?? ''));
$hasUpload = isset($_FILES['attachment']) && is_array($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($body === '' && !$hasUpload) {
    ereview_msg_json(['ok' => false, 'error' => 'Message is empty.'], 422);
}
if (mb_strlen($body) > 15000) {
    ereview_msg_json(['ok' => false, 'error' => 'Message is too long.'], 422);
}
if ($body === '' && $hasUpload) {
    $body = '[attachment]';
}

$studentId = ereview_msg_is_admin_role($role) ? (int)($_POST['student_id'] ?? 0) : $userId;
if ($studentId <= 0) {
    ereview_msg_json(['ok' => false, 'error' => 'Student thread is required.'], 422);
}
if (!ereview_msg_can_access_thread($conn, $role, $userId, $studentId)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$rate = ereview_msg_rate_limit_check($conn, $studentId, $userId, 10, 6);
if (empty($rate['allowed'])) {
    ereview_msg_json([
        'ok' => false,
        'error' => 'You are sending messages too quickly. Please wait a moment.',
        'retry_after_seconds' => (int)($rate['retry_after'] ?? 2),
    ], 429);
}

$recipientRole = ereview_msg_is_admin_role($role) ? 'student' : 'admin';
$recipientId = ereview_msg_is_admin_role($role) ? $studentId : 0;
if (ereview_msg_is_admin_role($role)) {
    $q = mysqli_prepare($conn, "SELECT role FROM users WHERE user_id=? LIMIT 1");
    if ($q) {
        mysqli_stmt_bind_param($q, 'i', $studentId);
        mysqli_stmt_execute($q);
        $rr = mysqli_stmt_get_result($q);
        $row = $rr ? mysqli_fetch_assoc($rr) : null;
        mysqli_stmt_close($q);
        $targetRole = (string)($row['role'] ?? 'student');
        if ($targetRole === 'college_student') {
            $recipientRole = 'college_student';
        }
    }
} else {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    if ($adminId > 0) {
        $q = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id=? AND role IN ('admin','professor_admin') LIMIT 1");
        if ($q) {
            mysqli_stmt_bind_param($q, 'i', $adminId);
            mysqli_stmt_execute($q);
            $rr = mysqli_stmt_get_result($q);
            $row = $rr ? mysqli_fetch_assoc($rr) : null;
            mysqli_stmt_close($q);
            if ($row) {
                $recipientId = (int)$row['user_id'];
            }
        }
    }
    if ($recipientId <= 0) {
        $res = @mysqli_query($conn, "SELECT user_id FROM users WHERE role IN ('admin','professor_admin') ORDER BY role='admin' DESC, user_id ASC LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($row) {
            $recipientId = (int)($row['user_id'] ?? 0);
        }
    }
    if ($recipientId > 0) {
        $rq = mysqli_prepare($conn, "SELECT role FROM users WHERE user_id=? LIMIT 1");
        if ($rq) {
            mysqli_stmt_bind_param($rq, 'i', $recipientId);
            mysqli_stmt_execute($rq);
            $rr = mysqli_stmt_get_result($rq);
            $rrow = $rr ? mysqli_fetch_assoc($rr) : null;
            mysqli_stmt_close($rq);
            $rrr = (string)($rrow['role'] ?? '');
            if ($rrr === 'professor_admin') {
                $recipientRole = 'professor_admin';
            } elseif ($rrr === 'admin') {
                $recipientRole = 'admin';
            }
        }
    }
}

if (!ereview_msg_is_admin_role($role) && $recipientId <= 0) {
    ereview_msg_json(['ok' => false, 'error' => 'No administrator account is available to receive messages.'], 422);
}

$stmt = mysqli_prepare($conn, "INSERT INTO admin_reviewee_messages
  (thread_student_id, sender_id, sender_role, recipient_id, recipient_role, body)
  VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    ereview_msg_json(['ok' => false, 'error' => 'Could not send message.'], 500);
}
mysqli_stmt_bind_param($stmt, 'iisiss', $studentId, $userId, $role, $recipientId, $recipientRole, $body);
$ok = mysqli_stmt_execute($stmt);
$newId = (int)mysqli_insert_id($conn);
mysqli_stmt_close($stmt);
if (!$ok) {
    ereview_msg_json(['ok' => false, 'error' => 'Could not send message.'], 500);
}

$attachment = null;
if ($hasUpload) {
    if (!ereview_msg_attachments_table_ready($conn)) {
        ereview_msg_json(['ok' => false, 'error' => 'Attachment migration not installed (023).'], 503);
    }
    $f = $_FILES['attachment'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        ereview_msg_json(['ok' => false, 'error' => 'Attachment upload failed.'], 422);
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > (5 * 1024 * 1024)) {
        ereview_msg_json(['ok' => false, 'error' => 'Attachment must be up to 5MB.'], 422);
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $orig = trim((string)($f['name'] ?? 'attachment'));
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)@finfo_file($fi, $tmp);
            @finfo_close($fi);
        }
    }
    $allowed = [
        'image/jpeg', 'image/png', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/wav', 'audio/x-wav'
    ];
    if (!in_array($mime, $allowed, true)) {
        ereview_msg_json(['ok' => false, 'error' => 'Only images, PDF/docs, plain text, and audio files are allowed.'], 422);
    }
    $token = hash('sha256', $newId . '|' . $userId . '|' . microtime(true) . '|' . random_int(1000, 999999));
    $ext = pathinfo($orig, PATHINFO_EXTENSION);
    $stored = $token . ($ext !== '' ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext)) : '');
    $storageDir = ereview_msg_attachment_storage_dir();
    if ($storageDir === '') {
        ereview_msg_json(['ok' => false, 'error' => 'Upload storage is not writable. Please contact support.'], 500);
    }
    $dest = $storageDir . '/' . $stored;
    if (!@move_uploaded_file($tmp, $dest)) {
        $reason = 'Could not store attachment.';
        $lastErr = error_get_last();
        if (!empty($lastErr['message'])) {
            $reason .= ' ' . (string)$lastErr['message'];
        }
        ereview_msg_json(['ok' => false, 'error' => $reason], 500);
    }
    if (!ereview_msg_attachment_passes_scan($dest)) {
        @unlink($dest);
        ereview_msg_json(['ok' => false, 'error' => 'Attachment failed security scan.'], 422);
    }
    $ast = mysqli_prepare($conn, "INSERT INTO admin_reviewee_message_attachments
      (message_id, thread_student_id, uploader_id, storage_token, stored_name, orig_name, mime_type, size_bytes)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($ast) {
        mysqli_stmt_bind_param($ast, 'iiissssi', $newId, $studentId, $userId, $token, $stored, $orig, $mime, $size);
        mysqli_stmt_execute($ast);
        mysqli_stmt_close($ast);
        $attachment = [
            'orig_name' => $orig,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'download_url' => 'api/messages/attachment_download.php?token=' . urlencode($token),
        ];
    }
}

ereview_msg_json([
    'ok' => true,
    'message_id' => $newId,
    'attachment' => $attachment,
    'unread_total' => ereview_msg_unread_total($conn, $role, $userId),
]);

