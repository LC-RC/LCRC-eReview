<?php
/**
 * One-time cleanup: eliminate Active + No Access students.
 *
 * - Commerce (package/by_topic/free_access): demote to pending
 * - Legacy (null enrollment_path) with SCA or access_end: backfill admin_manual grant
 * - Legacy with neither: demote to pending
 *
 * Usage: C:\xampp\php\php.exe scripts/cleanup_active_no_grant.php
 *        C:\xampp\php\php.exe scripts/cleanup_active_no_grant.php --dry-run
 */
declare(strict_types=1);

require dirname(__DIR__) . '/session_config.php';
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/includes/commerce_catalog.php';
require dirname(__DIR__) . '/includes/commerce_access_gate.php';
require dirname(__DIR__) . '/includes/commerce_admin_manual_grant.php';

$dry = in_array('--dry-run', $argv ?? [], true);
if (!commerce_schema_ready($conn)) {
    fwrite(STDERR, "Commerce schema not ready.\n");
    exit(1);
}

$adminId = (int) (mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1"
))[0] ?? 0);
if ($adminId <= 0) {
    fwrite(STDERR, "Need an admin user for granted_by.\n");
    exit(1);
}

$sql = "SELECT u.user_id, u.full_name, u.email, u.enrollment_path, u.access_end, u.access_months,
               (SELECT COUNT(*) FROM student_content_permissions p WHERE p.user_id = u.user_id) AS sca
        FROM users u
        WHERE u.role = 'student'
          AND u.status = 'approved'
          AND NOT EXISTS (
            SELECT 1 FROM access_grants g
            WHERE g.user_id = u.user_id
              AND g.status = 'active'
              AND g.ends_at > NOW()
              AND g.source IN ('purchase','free_access','admin_manual')
          )
        ORDER BY u.user_id ASC";
$res = mysqli_query($conn, $sql);
$rows = [];
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $rows[] = $r;
}

echo ($dry ? "[DRY-RUN] " : '') . 'Active+NoGrant students: ' . count($rows) . PHP_EOL;

$demoted = 0;
$backfilled = 0;
$failed = 0;

foreach ($rows as $row) {
    $uid = (int) $row['user_id'];
    $path = (string) ($row['enrollment_path'] ?? '');
    $isCommerce = in_array($path, ['package', 'by_topic', 'free_access'], true);
    $sca = (int) ($row['sca'] ?? 0);
    $endRaw = trim((string) ($row['access_end'] ?? ''));
    $label = $row['full_name'] . ' (#' . $uid . ')';

    if ($isCommerce || ($sca <= 0 && $endRaw === '')) {
        echo "DEMOTE  {$label} path=" . ($path !== '' ? $path : 'null') . PHP_EOL;
        if (!$dry) {
            $d = commerce_student_demote_if_no_active_grant($conn, $uid);
            if (!empty($d['demoted'])) {
                $demoted++;
            } else {
                $failed++;
            }
        } else {
            $demoted++;
        }
        continue;
    }

    // Legacy with SCA and/or access window → backfill admin_manual grant
    $months = (int) ($row['access_months'] ?? 0);
    if ($months < 1) {
        if ($endRaw !== '') {
            $endTs = strtotime($endRaw);
            if ($endTs !== false && $endTs > time()) {
                $months = max(1, (int) ceil(($endTs - time()) / (30 * 86400)));
            }
        }
    }
    if ($months < 1) {
        $months = 6;
    }
    if ($months > 120) {
        $months = 120;
    }

    echo "BACKFILL {$label} months={$months} sca={$sca}" . PHP_EOL;
    if (!$dry) {
        $g = commerce_admin_grant_manual_access($conn, $uid, $adminId, [
            'months' => $months,
            'activate_login' => true,
            'close_open_payment' => false,
            'notify_student' => false,
            'label' => 'Legacy access backfill',
        ]);
        if (!empty($g['ok'])) {
            $backfilled++;
            // Preserve longer historical access_end if grant window is shorter.
            if ($endRaw !== '') {
                $endTs = strtotime($endRaw);
                if ($endTs && function_exists('commerce_fulfill_maybe_extend_access_end')) {
                    require_once dirname(__DIR__) . '/includes/commerce_fulfillment.php';
                    commerce_fulfill_maybe_extend_access_end($conn, $uid, $endTs);
                }
            }
        } else {
            echo "  FAILED: " . ($g['error'] ?? 'unknown') . PHP_EOL;
            $failed++;
        }
    } else {
        $backfilled++;
    }
}

echo PHP_EOL . "Done. demoted={$demoted} backfilled={$backfilled} failed={$failed}" . PHP_EOL;
if ($dry) {
    echo "Re-run without --dry-run to apply.\n";
}
