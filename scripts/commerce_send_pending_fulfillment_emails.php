<?php
/**
 * CLI — send pending fulfillment notification emails (Phase 8.4).
 *
 * Does NOT fulfill payments. Only emails already-fulfilled payments
 * where fulfillment_email_sent_at IS NULL.
 *
 * Usage:
 *   php scripts/commerce_send_pending_fulfillment_emails.php
 *   php scripts/commerce_send_pending_fulfillment_emails.php --limit=20
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_notifications.php';

$limit = 50;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500, (int) $m[1]));
    }
}

$result = commerce_notify_send_pending_fulfillment_emails($conn, $limit);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
