<?php
/**
 * Activation × commerce SCA hardening tests (reversible).
 * Ensures activate_user replace-all cannot wipe active purchase grants' SCA rows.
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_content_access.php';
require_once __DIR__ . '/../includes/commerce_payment.php';
require_once __DIR__ . '/../includes/commerce_fulfillment.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " - $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Activation commerce SCA hardening tests ===\n";

$basePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$baseItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$baseFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);

echo "Baseline pay=$basePay grants=$baseGrants sca=$baseSca far=$baseFar pkgs=$basePkg\n";

$createdUserIds = [];
$createdPackageIds = [];
$createdPaymentIds = [];
$createdGrantIds = [];
$lessonSnap = [];

function act_user(mysqli $conn, string $email, string $status = 'pending'): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Act Hardening';
    $school = 'Test';
    $review = 'reviewee';
    $proof = '';
    $path = 'package';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, review_type, enrollment_path, school, school_other, payment_proof, email, password, role, status, email_verified)
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'student', ?, 1)"
    );
    mysqli_stmt_bind_param($stmt, 'ssssssss', $name, $review, $path, $school, $proof, $email, $hash, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function act_has_sca(mysqli $conn, int $userId, string $type, int $cid): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1 FROM student_content_permissions WHERE user_id=? AND content_type=? AND content_id=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $type, $cid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res && (bool) mysqli_fetch_row($res);
    mysqli_stmt_close($stmt);
    return $ok;
}

function act_sca_count(mysqli $conn, int $userId): int
{
    return (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions WHERE user_id=' . (int) $userId))[0] ?? 0);
}

function act_simulate_activate(mysqli $conn, int $userId, int $months, array $perms, bool $grantFull, int $adminId): bool
{
    $sql = "UPDATE users SET status='approved', access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL ? MONTH), access_months=? WHERE user_id=? AND role='student'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iii', $months, $months, $userId);
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);
        return false;
    }
    mysqli_stmt_close($stmt);
    if ($grantFull) {
        return sca_save_user_permissions_preserving_commerce(
            $conn,
            $userId,
            [['content_type' => 'full_lms', 'content_id' => 0]],
            $adminId
        );
    }
    return sca_save_user_permissions_preserving_commerce($conn, $userId, $perms, $adminId);
}

try {
    $ts = (string) time();
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        throw new RuntimeException('Need an admin user');
    }

    $lr = mysqli_query($conn, "SELECT lesson_id FROM lessons ORDER BY lesson_id LIMIT 2");
    $lessons = [];
    while ($lr && ($r = mysqli_fetch_assoc($lr))) {
        $lessons[] = (int) $r['lesson_id'];
    }
    if (count($lessons) < 2) {
        throw new RuntimeException('Need 2 lessons');
    }
    $L1 = $lessons[0];
    $L2 = $lessons[1];
    foreach ([$L1, $L2] as $lid) {
        $lessonSnap[$lid] = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT price_centavos, access_duration_value, access_duration_unit, is_purchasable FROM lessons WHERE lesson_id=' . $lid));
        mysqli_query($conn, "UPDATE lessons SET price_centavos=20000, access_duration_value=6, access_duration_unit='month', is_purchasable=1 WHERE lesson_id=$lid");
    }

    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_ACT_FULL', 'Act Full', 't', 100000, 'PHP', 6, 'month', 'full_lms', 1, 1, 99)");
    $pkgId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $pkgId;

    // ---------- A: no commerce - activation same as before ----------
    $uA = act_user($conn, "act.a.{$ts}@example.com");
    $createdUserIds[] = $uA;
    $okA = act_simulate_activate($conn, $uA, 3, [['content_type' => 'lesson', 'content_id' => $L1]], false, $adminId);
    $scaA = act_sca_count($conn, $uA);
    $mark(
        'A',
        $okA
            && act_has_sca($conn, $uA, 'lesson', $L1)
            && !act_has_sca($conn, $uA, 'full_lms', 0)
            && $scaA === 1
            && (($st = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM users WHERE user_id=$uA"))) && $st['status'] === 'approved'),
        "sca=$scaA"
    );

    // ---------- B: commerce grant preserved on activation ----------
    $uB = act_user($conn, "act.b.{$ts}@example.com");
    $createdUserIds[] = $uB;
    $coB = commerce_create_or_resume_checkout($conn, $uB, 'package', $pkgId, null);
    $pidB = (int) $coB['payment']['payment_id'];
    $createdPaymentIds[] = $pidB;
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='auto_verified', paid_at=NOW() WHERE payment_id=$pidB");
    $fB = commerce_fulfill_payment($conn, $pidB);
    $grantsBeforeB = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uB"))[0] ?? 0);
    $hasFullBefore = act_has_sca($conn, $uB, 'full_lms', 0);
    // Admin activates with a *different* narrow permission (lesson L1 only) - must keep full_lms from commerce
    $okB = act_simulate_activate($conn, $uB, 6, [['content_type' => 'lesson', 'content_id' => $L1]], false, $adminId);
    $grantsAfterB = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uB"))[0] ?? 0);
    $mark(
        'B',
        !empty($fB['ok'])
            && $hasFullBefore
            && $okB
            && act_has_sca($conn, $uB, 'full_lms', 0)
            && act_has_sca($conn, $uB, 'lesson', $L1)
            && $grantsAfterB === $grantsBeforeB,
        "grants=$grantsAfterB/$grantsBeforeB full=" . (act_has_sca($conn, $uB, 'full_lms', 0) ? '1' : '0')
    );

    // ---------- C: admin + commerce both remain ----------
    $uC = act_user($conn, "act.c.{$ts}@example.com");
    $createdUserIds[] = $uC;
    // Manual admin SCA before activation (as if prior upsert)
    sca_upsert_permissions($conn, $uC, [['content_type' => 'lesson', 'content_id' => $L2]], $adminId);
    // Commerce lesson grant for L1
    mysqli_query($conn, "INSERT INTO access_grants
        (user_id, source, payment_id, payment_item_id, content_type, content_id, content_label, starts_at, ends_at, status)
        VALUES ($uC, 'purchase', NULL, NULL, 'lesson', $L1, 'commerce', NOW(), DATE_ADD(NOW(), INTERVAL 6 MONTH), 'active')");
    $createdGrantIds[] = (int) mysqli_insert_id($conn);
    sca_upsert_permissions($conn, $uC, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    // Activate with admin selecting L2 only - L1 (commerce) must remain; L2 (admin selection) remains
    $okC = act_simulate_activate($conn, $uC, 3, [['content_type' => 'lesson', 'content_id' => $L2]], false, $adminId);
    $mark(
        'C',
        $okC && act_has_sca($conn, $uC, 'lesson', $L1) && act_has_sca($conn, $uC, 'lesson', $L2),
        'L1=' . (act_has_sca($conn, $uC, 'lesson', $L1) ? '1' : '0') . ' L2=' . (act_has_sca($conn, $uC, 'lesson', $L2) ? '1' : '0')
    );

    // ---------- D: repeated activation - no duplicate SCA ----------
    $countBeforeD = act_sca_count($conn, $uC);
    act_simulate_activate($conn, $uC, 3, [['content_type' => 'lesson', 'content_id' => $L2]], false, $adminId);
    act_simulate_activate($conn, $uC, 3, [['content_type' => 'lesson', 'content_id' => $L2]], false, $adminId);
    $countAfterD = act_sca_count($conn, $uC);
    $dupCheck = (int) (mysqli_fetch_row(mysqli_query(
        $conn,
        "SELECT COUNT(*) FROM (
            SELECT content_type, content_id, COUNT(*) c FROM student_content_permissions WHERE user_id=$uC
            GROUP BY content_type, content_id HAVING c > 1
         ) x"
    ))[0] ?? 0);
    $mark('D', $dupCheck === 0 && $countAfterD === $countBeforeD, "before=$countBeforeD after=$countAfterD dups=$dupCheck");

    // ---------- E: expired commerce grant NOT re-preserved ----------
    $uE = act_user($conn, "act.e.{$ts}@example.com");
    $createdUserIds[] = $uE;
    mysqli_query($conn, "INSERT INTO access_grants
        (user_id, source, payment_id, payment_item_id, content_type, content_id, content_label, starts_at, ends_at, status)
        VALUES ($uE, 'purchase', NULL, NULL, 'lesson', $L1, 'expired', '2020-01-01', '2020-06-01', 'active')");
    $createdGrantIds[] = (int) mysqli_insert_id($conn);
    sca_upsert_permissions($conn, $uE, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    act_simulate_activate($conn, $uE, 3, [['content_type' => 'lesson', 'content_id' => $L2]], false, $adminId);
    $mark(
        'E',
        !act_has_sca($conn, $uE, 'lesson', $L1) && act_has_sca($conn, $uE, 'lesson', $L2),
        'expired commerce L1 removed; admin L2 kept'
    );

    // ---------- F/G: payment history & grants untouched by activation ----------
    $payB = commerce_get_payment($conn, $pidB);
    $gB2 = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uB"))[0] ?? 0);
    $mark(
        'F',
        ($payB['status'] ?? '') === 'paid'
            && ($payB['verification_status'] ?? '') === 'auto_verified'
            && !empty($payB['fulfilled_at']),
        'payment intact'
    );
    $mark('G', $gB2 === $grantsBeforeB && $grantsBeforeB > 0, "grants still $gB2");

    // ---------- H: Free Access unchanged ----------
    $uH = act_user($conn, "act.free.{$ts}@example.com");
    $createdUserIds[] = $uH;
    $farRef = 'FAR-ACT-' . $ts;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('$farRef', $uH, 'pending')");
    $farBefore = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
    act_simulate_activate($conn, $uH, 1, [['content_type' => 'full_lms', 'content_id' => 0]], true, $adminId);
    $farAfter = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
    $farPay = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM payments WHERE user_id=$uH"))[0] ?? 0);
    $mark('H', $farAfter === $farBefore && $farPay === 0, "far=$farAfter pay=$farPay");

    // ---------- I: login approval unchanged (static + status gate still approved) ----------
    $loginSrc = file_get_contents(dirname(__DIR__) . '/login.php');
    $loginProc = file_get_contents(dirname(__DIR__) . '/login_process.php');
    $actSrc = file_get_contents(dirname(__DIR__) . '/activate_user.php');
    $usesPreserve = strpos($actSrc, 'sca_save_user_permissions_preserving_commerce') !== false;
    $loginStillGates = strpos($loginSrc, "status") !== false && (strpos($loginSrc, 'approved') !== false);
    $noCommerceInLogin = strpos($loginSrc, 'commerce_') === false && strpos($loginProc, 'commerce_') === false;
    $mark('I', $usesPreserve && $loginStillGates && $noCommerceInLogin, 'activate uses preserve; login untouched by commerce');

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup
if ($createdPaymentIds !== []) {
    $in = implode(',', array_map('intval', $createdPaymentIds));
    mysqli_query($conn, "DELETE FROM access_grants WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_verification_attempts WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_gcash_references WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payments WHERE payment_id IN ($in)");
}
if ($createdGrantIds !== []) {
    $gin = implode(',', array_map('intval', $createdGrantIds));
    mysqli_query($conn, "DELETE FROM access_grants WHERE grant_id IN ($gin)");
}
if ($createdUserIds !== []) {
    $uin = implode(',', array_map('intval', $createdUserIds));
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($uin) AND email LIKE 'act.%@example.com'");
}
foreach ($createdPackageIds as $pid) {
    mysqli_query($conn, 'DELETE FROM package_content_items WHERE package_id=' . (int) $pid);
    mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pid . " AND code LIKE 'TEST_ACT_%'");
}
foreach ($lessonSnap as $lid => $snap) {
    if (!$snap) {
        continue;
    }
    $pc = $snap['price_centavos'] === null ? 'NULL' : (int) $snap['price_centavos'];
    $dv = $snap['access_duration_value'] === null ? 'NULL' : (int) $snap['access_duration_value'];
    $du = $snap['access_duration_unit'] === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string) $snap['access_duration_unit']) . "'";
    $ip = (int) ($snap['is_purchasable'] ?? 0);
    mysqli_query($conn, "UPDATE lessons SET price_centavos=$pc, access_duration_value=$dv, access_duration_unit=$du, is_purchasable=$ip WHERE lesson_id=" . (int) $lid);
}

$endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);

$cleanupOk = $endPay === $basePay && $endItems === $baseItems && $endGrants === $baseGrants
    && $endSca === $baseSca && $endFar === $baseFar && $endPkg === $basePkg;
$mark('J_CLEANUP', $cleanupOk, "pay=$endPay/$basePay grants=$endGrants/$baseGrants sca=$endSca/$baseSca pkgs=$endPkg/$basePkg far=$endFar/$baseFar");

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
