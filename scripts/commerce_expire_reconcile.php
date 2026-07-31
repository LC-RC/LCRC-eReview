<?php
/**
 * CLI — expire overdue access_grants and reconcile commerce-backed SCA (Phase 8.2).
 *
 * Usage:
 *   php scripts/commerce_expire_reconcile.php
 *   php scripts/commerce_expire_reconcile.php --limit=100
 *   php scripts/commerce_expire_reconcile.php --user_id=123
 *   php scripts/commerce_expire_reconcile.php --user_id=123 --limit=50
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_grant_expiry.php';

$limit = 500;
$userId = 0;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(5000, (int) $m[1]));
    } elseif (preg_match('/^--user_id=(\d+)$/', $arg, $m)) {
        $userId = (int) $m[1];
    }
}

$result = commerce_expire_and_reconcile($conn, $limit, $userId);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
