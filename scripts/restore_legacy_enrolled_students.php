<?php
/**
 * Restore previously enrolled students who lost login after commerce grants.
 *
 * Targets students with no active grant who still show LMS history:
 * - student_content_permissions rows, and/or
 * - valid access_end / approved+access_start markers
 *
 * Does NOT approve brand-new pending registrations with no LMS history.
 *
 * Usage:
 *   php scripts/restore_legacy_enrolled_students.php
 *   php scripts/restore_legacy_enrolled_students.php --dry-run
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

$sql = "SELECT u.user_id, u.full_name, u.email, u.role, u.status, u.enrollment_path,
               u.access_start, u.access_end, u.access_months,
               (SELECT COUNT(*) FROM student_content_permissions p WHERE p.user_id = u.user_id) AS sca
        FROM users u
        WHERE u.role = 'student'
          AND u.status <> 'rejected'
          AND NOT EXISTS (
            SELECT 1 FROM access_grants g
            WHERE g.user_id = u.user_id
              AND g.status = 'active'
              AND g.ends_at > NOW()
              AND g.source IN ('purchase','free_access','admin_manual')
          )
        ORDER BY u.user_id ASC";
$res = mysqli_query($conn, $sql);
if (!$res) {
    fwrite(STDERR, 'Query failed: ' . mysqli_error($conn) . PHP_EOL);
    exit(1);
}

$rows = [];
while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r;
}

echo ($dry ? '[DRY-RUN] ' : '') . 'Students without active grant: ' . count($rows) . PHP_EOL;

$restored = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $row) {
    $uid = (int) $row['user_id'];
    $label = ($row['full_name'] ?? '') . ' (#' . $uid . ')';
    $sca = (int) ($row['sca'] ?? 0);
    $status = (string) ($row['status'] ?? '');
    $path = (string) ($row['enrollment_path'] ?? '');

    if (!commerce_student_has_legacy_enrollment_signal($conn, $uid, $row)) {
        echo "SKIP    {$label} status={$status} path=" . ($path !== '' ? $path : 'null') . " sca={$sca} (new registrant)\n";
        $skipped++;
        continue;
    }

    echo "RESTORE {$label} status={$status} path=" . ($path !== '' ? $path : 'null') . " sca={$sca}\n";
    if ($dry) {
        $restored++;
        continue;
    }

    $r = commerce_student_try_restore_legacy_access($conn, $uid, $row);
    if (!empty($r['restored'])) {
        $restored++;
    } elseif (!empty($r['skipped'])) {
        // Already had grant after race, or unexpected skip after signal match.
        if (commerce_student_has_active_access($conn, $uid)) {
            $restored++;
        } else {
            echo "  SKIPPED after signal: " . ($r['error'] ?? 'no_change') . PHP_EOL;
            $skipped++;
        }
    } else {
        echo '  FAILED: ' . ($r['error'] ?? 'unknown') . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL . "Done. restored={$restored} skipped_new={$skipped} failed={$failed}" . PHP_EOL;
exit($failed > 0 ? 2 : 0);
