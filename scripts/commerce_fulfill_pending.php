<?php
/**
 * CLI — fulfill paid payments that are eligible but unfulfilled (Phase 7).
 *
 * Usage:
 *   php scripts/commerce_fulfill_pending.php
 *   php scripts/commerce_fulfill_pending.php --limit=20
 *   php scripts/commerce_fulfill_pending.php --payment_id=123
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_fulfillment.php';

$limit = 20;
$paymentId = 0;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    } elseif (preg_match('/^--payment_id=(\d+)$/', $arg, $m)) {
        $paymentId = (int) $m[1];
    }
}

if ($paymentId > 0) {
    $r = commerce_fulfill_payment($conn, $paymentId);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($r['ok']) ? 0 : 1);
}

$batch = commerce_fulfill_pending_batch($conn, $limit);
echo json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(0);
