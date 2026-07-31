<?php
/**
 * Student Access API × commerce SCA hardening (reversible).
 * save_permissions / save_bulk_permissions must not wipe active purchase SCA.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_content_access.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Student Access API commerce SCA hardening ===\n";

$basePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$baseFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);

echo "Baseline pay=$basePay grants=$baseGrants sca=$baseSca far=$baseFar pkgs=$basePkg\n";

$createdUserIds = [];
$createdGrantIds = [];

function saa_user(mysqli $conn, string $email): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'SAA Test';
    $school = 'Test';
    $review = 'reviewee';
    $status = 'approved';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, review_type, school, payment_proof, email, password, role, status, email_verified)
         VALUES (?, ?, ?, '', ?, ?, 'student', ?, 1)"
    );
    mysqli_stmt_bind_param($stmt, 'ssssss', $name, $review, $school, $email, $hash, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function saa_has(mysqli $conn, int $uid, string $type, int $cid): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1 FROM student_content_permissions WHERE user_id=? AND content_type=? AND content_id=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'isi', $uid, $type, $cid);
    mysqli_stmt_execute($stmt);
    $ok = (bool) mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $ok;
}

function saa_count(mysqli $conn, int $uid): int
{
    return (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions WHERE user_id=' . (int) $uid))[0] ?? 0);
}

function saa_grant(mysqli $conn, int $uid, string $type, int $cid, string $endsSql, array &$grantIds): void
{
    mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, content_type, content_id, content_label, starts_at, ends_at, status)
         VALUES ($uid, 'purchase', NULL, NULL, '" . mysqli_real_escape_string($conn, $type) . "', $cid, 't', NOW(), $endsSql, 'active')"
    );
    $grantIds[] = (int) mysqli_insert_id($conn);
}

try {
    $ts = (string) time();
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        throw new RuntimeException('Need admin user');
    }

    $lr = mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id LIMIT 3');
    $lessons = [];
    while ($lr && ($r = mysqli_fetch_assoc($lr))) {
        $lessons[] = (int) $r['lesson_id'];
    }
    if (count($lessons) < 3) {
        throw new RuntimeException('Need 3 lessons');
    }
    [$L1, $L2, $L3] = $lessons;

    // ---------- A manual-only replace ----------
    $uA = saa_user($conn, "saa.a.{$ts}@example.com");
    $createdUserIds[] = $uA;
    sca_save_user_permissions($conn, $uA, [
        ['content_type' => 'lesson', 'content_id' => $L1],
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    sca_save_user_permissions_preserving_commerce($conn, $uA, [
        ['content_type' => 'lesson', 'content_id' => $L3],
    ], $adminId);
    $mark(
        'A',
        saa_has($conn, $uA, 'lesson', $L3)
            && !saa_has($conn, $uA, 'lesson', $L1)
            && !saa_has($conn, $uA, 'lesson', $L2)
            && saa_count($conn, $uA) === 1,
        'manual replace works; count=' . saa_count($conn, $uA)
    );

    // ---------- B active commerce preserved when omitted from POST ----------
    $uB = saa_user($conn, "saa.b.{$ts}@example.com");
    $createdUserIds[] = $uB;
    saa_grant($conn, $uB, 'lesson', $L1, 'DATE_ADD(NOW(), INTERVAL 6 MONTH)', $createdGrantIds);
    sca_upsert_permissions($conn, $uB, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    // Admin posts only L2 — L1 commerce must remain
    sca_save_user_permissions_preserving_commerce($conn, $uB, [
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    $mark(
        'B',
        saa_has($conn, $uB, 'lesson', $L1) && saa_has($conn, $uB, 'lesson', $L2),
        'commerce L1 + manual L2'
    );

    // ---------- C remove manual, keep commerce ----------
    $uC = saa_user($conn, "saa.c.{$ts}@example.com");
    $createdUserIds[] = $uC;
    saa_grant($conn, $uC, 'lesson', $L1, 'DATE_ADD(NOW(), INTERVAL 6 MONTH)', $createdGrantIds);
    sca_upsert_permissions($conn, $uC, [
        ['content_type' => 'lesson', 'content_id' => $L1],
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    // Post empty manual set except we need at least merge commerce — post [] → commerce-only
    sca_save_user_permissions_preserving_commerce($conn, $uC, [], $adminId);
    $mark(
        'C',
        saa_has($conn, $uC, 'lesson', $L1) && !saa_has($conn, $uC, 'lesson', $L2),
        'manual L2 gone; commerce L1 kept'
    );

    // ---------- D expired not re-injected ----------
    $uD = saa_user($conn, "saa.d.{$ts}@example.com");
    $createdUserIds[] = $uD;
    saa_grant($conn, $uD, 'lesson', $L1, "'2020-06-01 00:00:00'", $createdGrantIds);
    sca_upsert_permissions($conn, $uD, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    sca_save_user_permissions_preserving_commerce($conn, $uD, [
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    $mark(
        'D',
        !saa_has($conn, $uD, 'lesson', $L1) && saa_has($conn, $uD, 'lesson', $L2),
        'expired L1 not preserved'
    );

    // ---------- E multiple commerce grants ----------
    $uE = saa_user($conn, "saa.e.{$ts}@example.com");
    $createdUserIds[] = $uE;
    saa_grant($conn, $uE, 'lesson', $L1, 'DATE_ADD(NOW(), INTERVAL 3 MONTH)', $createdGrantIds);
    saa_grant($conn, $uE, 'lesson', $L2, 'DATE_ADD(NOW(), INTERVAL 3 MONTH)', $createdGrantIds);
    saa_grant($conn, $uE, 'full_lms', 0, 'DATE_ADD(NOW(), INTERVAL 3 MONTH)', $createdGrantIds);
    sca_upsert_permissions($conn, $uE, [
        ['content_type' => 'lesson', 'content_id' => $L1],
        ['content_type' => 'lesson', 'content_id' => $L2],
        ['content_type' => 'full_lms', 'content_id' => 0],
    ], null);
    sca_save_user_permissions_preserving_commerce($conn, $uE, [
        ['content_type' => 'lesson', 'content_id' => $L3],
    ], $adminId);
    $mark(
        'E',
        saa_has($conn, $uE, 'lesson', $L1)
            && saa_has($conn, $uE, 'lesson', $L2)
            && saa_has($conn, $uE, 'full_lms', 0)
            && saa_has($conn, $uE, 'lesson', $L3),
        'all active commerce + L3'
    );

    // ---------- F cross-user isolation ----------
    $uF1 = saa_user($conn, "saa.f1.{$ts}@example.com");
    $uF2 = saa_user($conn, "saa.f2.{$ts}@example.com");
    $createdUserIds[] = $uF1;
    $createdUserIds[] = $uF2;
    saa_grant($conn, $uF1, 'lesson', $L1, 'DATE_ADD(NOW(), INTERVAL 6 MONTH)', $createdGrantIds);
    sca_upsert_permissions($conn, $uF1, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    sca_save_user_permissions_preserving_commerce($conn, $uF2, [
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    $mark(
        'F',
        saa_has($conn, $uF1, 'lesson', $L1)
            && !saa_has($conn, $uF2, 'lesson', $L1)
            && saa_has($conn, $uF2, 'lesson', $L2),
        'F1 L1 not copied to F2'
    );

    // ---------- G bulk: each student's own commerce preserved ----------
    $uG1 = saa_user($conn, "saa.g1.{$ts}@example.com");
    $uG2 = saa_user($conn, "saa.g2.{$ts}@example.com");
    $createdUserIds[] = $uG1;
    $createdUserIds[] = $uG2;
    saa_grant($conn, $uG1, 'lesson', $L1, 'DATE_ADD(NOW(), INTERVAL 6 MONTH)', $createdGrantIds);
    saa_grant($conn, $uG2, 'lesson', $L2, 'DATE_ADD(NOW(), INTERVAL 6 MONTH)', $createdGrantIds);
    sca_upsert_permissions($conn, $uG1, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    sca_upsert_permissions($conn, $uG2, [['content_type' => 'lesson', 'content_id' => $L2]], null);
    $bulkPosted = [['content_type' => 'lesson', 'content_id' => $L3]];
    foreach ([$uG1, $uG2] as $uid) {
        sca_save_user_permissions_preserving_commerce($conn, $uid, $bulkPosted, $adminId);
    }
    $mark(
        'G',
        saa_has($conn, $uG1, 'lesson', $L1) && saa_has($conn, $uG1, 'lesson', $L3)
            && saa_has($conn, $uG2, 'lesson', $L2) && saa_has($conn, $uG2, 'lesson', $L3)
            && !saa_has($conn, $uG1, 'lesson', $L2)
            && !saa_has($conn, $uG2, 'lesson', $L1),
        'bulk per-student commerce intact'
    );

    // ---------- H no duplicate rows on repeat ----------
    $beforeH = saa_count($conn, $uB);
    sca_save_user_permissions_preserving_commerce($conn, $uB, [
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    sca_save_user_permissions_preserving_commerce($conn, $uB, [
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    $afterH = saa_count($conn, $uB);
    $dups = (int) (mysqli_fetch_row(mysqli_query(
        $conn,
        "SELECT COUNT(*) FROM (
            SELECT content_type, content_id, COUNT(*) c FROM student_content_permissions WHERE user_id=$uB
            GROUP BY content_type, content_id HAVING c > 1
         ) x"
    ))[0] ?? 0);
    $mark('H', $dups === 0 && $beforeH === $afterH, "count=$afterH dups=$dups");

    // ---------- I access_grants unchanged by save ----------
    $grantsMid = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    sca_save_user_permissions_preserving_commerce($conn, $uB, [
        ['content_type' => 'lesson', 'content_id' => $L3],
    ], $adminId);
    $grantsAfterSave = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $mark('I', $grantsMid === $grantsAfterSave && $grantsMid > 0, "grants=$grantsAfterSave");

    // ---------- J Free Access preserved (Phase 8.1) ----------
    $uJ = saa_user($conn, "saa.free.{$ts}@example.com");
    $createdUserIds[] = $uJ;
    $farRef = 'FAR-SAA-' . $ts;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('$farRef', $uJ, 'pending')");
    // active free_access grant must be preserved by commerce helper (with purchase)
    mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status)
         SELECT $uJ, 'free_access', NULL, NULL, request_id, 'lesson', $L1, 'far', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active'
         FROM free_access_requests WHERE request_ref='$farRef' LIMIT 1"
    );
    $createdGrantIds[] = (int) mysqli_insert_id($conn);
    sca_upsert_permissions($conn, $uJ, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    sca_save_user_permissions_preserving_commerce($conn, $uJ, [
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    $farCnt = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM free_access_requests WHERE request_ref='$farRef'"))[0] ?? 0);
    $mark(
        'J',
        $farCnt === 1
            && saa_has($conn, $uJ, 'lesson', $L1)
            && saa_has($conn, $uJ, 'lesson', $L2),
        'FAR intact; active free_access SCA preserved with manual'
    );

    // ---------- M login unchanged (static) ----------
    $login = file_get_contents(dirname(__DIR__) . '/login.php');
    $api = file_get_contents(dirname(__DIR__) . '/admin_student_access_api.php');
    $mark(
        'M',
        strpos($api, 'sca_save_user_permissions_preserving_commerce') !== false
            && substr_count($api, 'sca_save_user_permissions_preserving_commerce') >= 2
            && strpos($login, 'commerce_') === false,
        'API uses preserve ×2; login untouched'
    );

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup
if ($createdGrantIds !== []) {
    $gin = implode(',', array_map('intval', array_filter($createdGrantIds)));
    if ($gin !== '') {
        mysqli_query($conn, "DELETE FROM access_grants WHERE grant_id IN ($gin)");
    }
}
if ($createdUserIds !== []) {
    $uin = implode(',', array_map('intval', $createdUserIds));
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($uin) AND email LIKE 'saa.%@example.com'");
}

$endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);

$mark(
    'CLEANUP',
    $endPay === $basePay && $endGrants === $baseGrants && $endSca === $baseSca
        && $endFar === $baseFar && $endPkg === $basePkg,
    "pay=$endPay/$basePay grants=$endGrants/$baseGrants sca=$endSca/$baseSca far=$endFar/$baseFar pkgs=$endPkg/$basePkg"
);

// Verify API source wiring
$apiSrc = file_get_contents(dirname(__DIR__) . '/admin_student_access_api.php');
$usesRawSaveInSaveActions = false;
if (preg_match('/action === \'save_permissions\'[\s\S]*?sca_save_user_permissions\(/', $apiSrc)
    && !preg_match('/action === \'save_permissions\'[\s\S]*?sca_save_user_permissions_preserving_commerce\(/', $apiSrc)) {
    $usesRawSaveInSaveActions = true;
}
$mark('API_WIRED', !$usesRawSaveInSaveActions && strpos($apiSrc, 'sca_save_user_permissions_preserving_commerce') !== false, 'save_* use preserving helper');

$pass = 0;
$fail = 0;
foreach ($results as $r) {
    if (!empty($r['ok'])) {
        $pass++;
    } else {
        $fail++;
    }
}
echo "=== Summary: $pass pass, $fail fail ===\n";
exit($fail > 0 ? 1 : 0);
