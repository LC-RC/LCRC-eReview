<?php
/**
 * CLI worker - verify pending GCash payments (Phase 6).
 * Does not require checkout session. No fulfillment / SCA / activation.
 *
 * Usage:
 *   php scripts/commerce_verify_pending.php
 *   php scripts/commerce_verify_pending.php --limit=20
 *   php scripts/commerce_verify_pending.php --payment_id=123 --force=1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_verification.php';

$limit = 20;
$paymentId = 0;
$force = false;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    } elseif (preg_match('/^--payment_id=(\d+)$/', $arg, $m)) {
        $paymentId = (int) $m[1];
    } elseif ($arg === '--force=1' || $arg === '--force') {
        $force = true;
    }
}

if ($paymentId > 0) {
    $r = commerce_verify_payment($conn, $paymentId, ['force' => $force, 'allow_stuck_processing' => true]);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($r['ok']) || ($r['decision'] ?? '') === 'skipped' ? 0 : 1);
}

$batch = commerce_verify_pending_batch($conn, $limit);
echo json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(0);
