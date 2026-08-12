<?php
/**
 * Phase 9 - commerce auto-activation after successful fulfill / FAR approve.
 * Reversible fixtures; restores baseline counts (does not require zero payments).
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_content_access.php';
require_once __DIR__ . '/../includes/commerce_payment.php';
require_once __DIR__ . '/../includes/commerce_fulfillment.php';
require_once __DIR__ . '/../includes/commerce_free_access.php';
require_once __DIR__ . '/../includes/commerce_activation.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " - $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 9 Commerce Auto-Activation ===\n";

$countTable = static function (mysqli $conn, string $t): int {
    $r = mysqli_query($conn, 'SELECT COUNT(*) FROM `' . $t . '`');
    return (int) (mysqli_fetch_row($r)[0] ?? 0);
};

$basePay = $countTable($conn, 'payments');
$baseItems = $countTable($conn, 'payment_items');
$baseAttempts = $countTable($conn, 'payment_verification_attempts');
$baseGcash = $countTable($conn, 'payment_gcash_references');
$baseGrants = $countTable($conn, 'access_grants');
$baseFar = $countTable($conn, 'free_access_requests');
$baseSca = $countTable($conn, 'student_content_permissions');

echo "BASELINE pay=$basePay items=$baseItems attempts=$baseAttempts gcash=$baseGcash grants=$baseGrants far=$baseFar sca=$baseSca\n";

$createdUserIds = [];
$createdPackageIds = [];
$createdPaymentIds = [];
$createdFarIds = [];

function p9a_user(mysqli $conn, string $email, string $path, ?int $packageId = null, ?string $lessonsJson = null): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'P9 AutoAct';
    $school = 'Test';
    $review = 'reviewee';
    $proof = '';
    $status = 'pending';
    if ($path === 'by_topic' && $lessonsJson !== null) {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, enrollment_path, selected_package_id, selected_lesson_ids_json, school, school_other, payment_proof, email, password, role, status, email_verified)
             VALUES (?, ?, ?, NULL, ?, ?, NULL, ?, ?, ?, 'student', ?, 1)"
        );
        mysqli_stmt_bind_param($stmt, 'sssssssss', $name, $review, $path, $lessonsJson, $school, $proof, $email, $hash, $status);
    } elseif ($packageId !== null && $packageId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, enrollment_path, selected_package_id, school, school_other, payment_proof, email, password, role, status, email_verified)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 'student', ?, 1)"
        );
        mysqli_stmt_bind_param($stmt, 'sssisssss', $name, $review, $path, $packageId, $school, $proof, $email, $hash, $status);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, enrollment_path, school, school_other, payment_proof, email, password, role, status, email_verified)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'student', ?, 1)"
        );
        mysqli_stmt_bind_param($stmt, 'ssssssss', $name, $review, $path, $school, $proof, $email, $hash, $status);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p9a_status(mysqli $conn, int $userId): string
{
    $r = mysqli_query($conn, 'SELECT status FROM users WHERE user_id=' . (int) $userId . ' LIMIT 1');
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return strtolower((string) ($row['status'] ?? ''));
}

function p9a_grant_count(mysqli $conn, int $userId): int
{
    return (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE user_id=' . (int) $userId))[0] ?? 0);
}

function p9a_has_sca(mysqli $conn, int $userId, string $type, int $cid): bool
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

try {
    $ts = (string) time();
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        throw new RuntimeException('Need admin user');
    }

    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P9A_PKG', 'P9A Package', 't', 150000, 'PHP', 6, 'month', 'full_lms', 1, 1, 99)");
    $pkgId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $pkgId;

    $lessonIds = [];
    $lr = mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id ASC LIMIT 2');
    while ($lr && ($row = mysqli_fetch_assoc($lr))) {
        $lessonIds[] = (int) $row['lesson_id'];
    }
    if (count($lessonIds) < 1) {
        throw new RuntimeException('Need at least 1 lesson for by_topic');
    }
    $lessonsJson = json_encode($lessonIds);

    // ---- A: paid package → verified → fulfilled → approved ----
    $uA = p9a_user($conn, "p9a.a.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uA;
    $coA = commerce_create_or_resume_checkout($conn, $uA, 'package', $pkgId, null);
    $pidA = (int) ($coA['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidA;
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='auto_verified', paid_at=NOW() WHERE payment_id=$pidA");
    $fA = commerce_fulfill_payment($conn, $pidA, ['granted_by' => $adminId]);
    $mark(
        'A_PACKAGE_AUTO_ACTIVATE',
        !empty($fA['ok'])
        && p9a_grant_count($conn, $uA) >= 1
        && p9a_has_sca($conn, $uA, 'full_lms', 0)
        && p9a_status($conn, $uA) === 'approved'
        && !empty($fA['activation']['activated']),
        'status=' . p9a_status($conn, $uA) . ' grants=' . p9a_grant_count($conn, $uA)
    );

    // ---- B: by_topic → verified → fulfilled → approved ----
    $uB = p9a_user($conn, "p9a.b.{$ts}@example.com", 'by_topic', null, $lessonsJson);
    $createdUserIds[] = $uB;
    $coB = commerce_create_or_resume_checkout($conn, $uB, 'by_topic', null, $lessonIds);
    $pidB = (int) ($coB['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidB;
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='manually_approved', paid_at=NOW() WHERE payment_id=$pidB");
    $fB = commerce_fulfill_payment($conn, $pidB, ['granted_by' => $adminId]);
    $mark(
        'B_BY_TOPIC_AUTO_ACTIVATE',
        !empty($fB['ok'])
        && p9a_grant_count($conn, $uB) >= 1
        && p9a_status($conn, $uB) === 'approved',
        'status=' . p9a_status($conn, $uB) . ' grants=' . p9a_grant_count($conn, $uB)
    );

    // ---- C: awaiting_proof remains pending, no grant ----
    $uC = p9a_user($conn, "p9a.c.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uC;
    $coC = commerce_create_or_resume_checkout($conn, $uC, 'package', $pkgId, null);
    $pidC = (int) ($coC['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidC;
    $mark(
        'C_AWAITING_PROOF_PENDING',
        p9a_status($conn, $uC) === 'pending'
        && p9a_grant_count($conn, $uC) === 0
        && (commerce_get_payment($conn, $pidC)['status'] ?? '') === 'awaiting_proof',
        'status=' . p9a_status($conn, $uC)
    );

    // ---- D: needs_review remains pending, no grant ----
    $uD = p9a_user($conn, "p9a.d.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uD;
    $coD = commerce_create_or_resume_checkout($conn, $uD, 'package', $pkgId, null);
    $pidD = (int) ($coD['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidD;
    mysqli_query(
        $conn,
        "UPDATE payments SET status='pending_verification', verification_status='needs_review',
         proof_path='uploads/payment_proofs/_p9a_fake.png' WHERE payment_id=$pidD"
    );
    $fD = commerce_fulfill_payment($conn, $pidD, ['granted_by' => $adminId]);
    $mark(
        'D_NEEDS_REVIEW_PENDING',
        empty($fD['ok'])
        && p9a_status($conn, $uD) === 'pending'
        && p9a_grant_count($conn, $uD) === 0,
        'fulfill_err=' . ($fD['error'] ?? '') . ' status=' . p9a_status($conn, $uD)
    );

    // ---- E: rejected remains pending ----
    $uE = p9a_user($conn, "p9a.e.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uE;
    $coE = commerce_create_or_resume_checkout($conn, $uE, 'package', $pkgId, null);
    $pidE = (int) ($coE['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidE;
    mysqli_query(
        $conn,
        "UPDATE payments SET status='rejected', verification_status='manually_rejected',
         proof_path='uploads/payment_proofs/_p9a_fake2.png' WHERE payment_id=$pidE"
    );
    $fE = commerce_fulfill_payment($conn, $pidE, ['granted_by' => $adminId]);
    $mark(
        'E_REJECTED_PENDING',
        empty($fE['ok'])
        && p9a_status($conn, $uE) === 'pending'
        && p9a_grant_count($conn, $uE) === 0,
        'status=' . p9a_status($conn, $uE)
    );

    // ---- F: failed fulfillment (no items) must not approve ----
    $uF = p9a_user($conn, "p9a.f.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uF;
    $coF = commerce_create_or_resume_checkout($conn, $uF, 'package', $pkgId, null);
    $pidF = (int) ($coF['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidF;
    mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id=$pidF");
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='auto_verified', paid_at=NOW() WHERE payment_id=$pidF");
    $fF = commerce_fulfill_payment($conn, $pidF, ['granted_by' => $adminId]);
    $mark(
        'F_FULFILL_FAIL_NO_ACTIVATE',
        empty($fF['ok'])
        && p9a_status($conn, $uF) === 'pending'
        && p9a_grant_count($conn, $uF) === 0,
        'err=' . ($fF['error'] ?? '') . ' status=' . p9a_status($conn, $uF)
    );

    // ---- G: repeated fulfillment - no duplicate grant; stays approved ----
    $gBefore = p9a_grant_count($conn, $uA);
    $endBefore = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=$uA"))['access_end'] ?? '');
    $fG = commerce_fulfill_payment($conn, $pidA, ['granted_by' => $adminId]);
    $gAfter = p9a_grant_count($conn, $uA);
    $endAfter = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=$uA"))['access_end'] ?? '');
    $mark(
        'G_IDEMPOTENT_FULFILL',
        !empty($fG['ok'])
        && !empty($fG['skipped'])
        && $gAfter === $gBefore
        && p9a_status($conn, $uA) === 'approved'
        && $endAfter === $endBefore,
        "grants $gBefore->$gAfter skipped=" . (!empty($fG['skipped']) ? '1' : '0')
    );

    // ---- H: already approved - no downgrade ----
    $uH = p9a_user($conn, "p9a.h.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uH;
    mysqli_query(
        $conn,
        "UPDATE users SET status='approved', access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL 12 MONTH), access_months=12
         WHERE user_id=$uH"
    );
    $coH = commerce_create_or_resume_checkout($conn, $uH, 'package', $pkgId, null);
    $pidH = (int) ($coH['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidH;
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='auto_verified', paid_at=NOW() WHERE payment_id=$pidH");
    $endH0 = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=$uH"))['access_end'] ?? '');
    $fH = commerce_fulfill_payment($conn, $pidH, ['granted_by' => $adminId]);
    $stH = p9a_status($conn, $uH);
    $endH1 = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=$uH"))['access_end'] ?? '');
    $mark(
        'H_ALREADY_APPROVED_NO_DOWNGRADE',
        !empty($fH['ok'])
        && $stH === 'approved'
        && empty($fH['activation']['activated'])
        && !empty($fH['activation']['already_approved'])
        && ($endH1 === $endH0 || strtotime($endH1) >= strtotime($endH0)),
        "status=$stH end0=$endH0 end1=$endH1"
    );

    // ---- I: FAR approve → grant + SCA + approved ----
    $uI = p9a_user($conn, "p9a.i.{$ts}@example.com", 'free_access', null);
    $createdUserIds[] = $uI;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('FAR-P9A-I-$ts', $uI, 'pending')");
    $farI = (int) mysqli_insert_id($conn);
    $createdFarIds[] = $farI;
    $farOk = commerce_far_approve($conn, $farI, $adminId, 3, 'p9a');
    $mark(
        'I_FAR_AUTO_ACTIVATE',
        !empty($farOk['ok'])
        && p9a_grant_count($conn, $uI) >= 1
        && p9a_has_sca($conn, $uI, 'full_lms', 0)
        && p9a_status($conn, $uI) === 'approved'
        && !empty($farOk['activation']['activated']),
        'status=' . p9a_status($conn, $uI)
    );

    // ---- J: FAR reject → remains pending ----
    $uJ = p9a_user($conn, "p9a.j.{$ts}@example.com", 'free_access', null);
    $createdUserIds[] = $uJ;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('FAR-P9A-J-$ts', $uJ, 'pending')");
    $farJ = (int) mysqli_insert_id($conn);
    $createdFarIds[] = $farJ;
    $rejJ = commerce_far_reject($conn, $farJ, $adminId, 'no');
    $mark(
        'J_FAR_REJECT_PENDING',
        !empty($rejJ['ok'])
        && p9a_status($conn, $uJ) === 'pending'
        && p9a_grant_count($conn, $uJ) === 0,
        'status=' . p9a_status($conn, $uJ)
    );

    // ---- K: manual SCA does not create purchase grant ----
    $uK = p9a_user($conn, "p9a.k.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uK;
    sca_upsert_permissions($conn, $uK, [['content_type' => 'full_lms', 'content_id' => 0]], $adminId);
    $purchaseGrants = (int) (mysqli_fetch_row(mysqli_query(
        $conn,
        "SELECT COUNT(*) FROM access_grants WHERE user_id=$uK AND source='purchase'"
    ))[0] ?? 0);
    $mark(
        'K_MANUAL_SCA_NO_PURCHASE_GRANT',
        p9a_has_sca($conn, $uK, 'full_lms', 0)
        && $purchaseGrants === 0
        && p9a_status($conn, $uK) === 'pending',
        'purchase_grants=' . $purchaseGrants
    );

    // ---- L: approved + valid access → login gate still requires approved ----
    $loginSrc = (string) file_get_contents(dirname(__DIR__) . '/login_process.php');
    $mark(
        'L_LOGIN_GATE',
        p9a_status($conn, $uA) === 'approved'
        && strpos($loginSrc, 'approved') !== false
        && (strpos($loginSrc, 'not approved') !== false || strpos($loginSrc, 'not_approved') !== false),
        'login still gates on approved'
    );

    // ---- M: paid verified but not fulfilled → not approved ----
    $uM = p9a_user($conn, "p9a.m.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uM;
    $coM = commerce_create_or_resume_checkout($conn, $uM, 'package', $pkgId, null);
    $pidM = (int) ($coM['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidM;
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='auto_verified', paid_at=NOW(), fulfilled_at=NULL WHERE payment_id=$pidM");
    // Do not call fulfill
    $mark(
        'M_VERIFIED_NOT_FULFILLED',
        p9a_status($conn, $uM) === 'pending'
        && empty(commerce_get_payment($conn, $pidM)['fulfilled_at'])
        && p9a_grant_count($conn, $uM) === 0,
        'status=' . p9a_status($conn, $uM)
    );

    // ---- N: proof uploaded but not verified → not approved ----
    $uN = p9a_user($conn, "p9a.n.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uN;
    $coN = commerce_create_or_resume_checkout($conn, $uN, 'package', $pkgId, null);
    $pidN = (int) ($coN['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidN;
    mysqli_query(
        $conn,
        "UPDATE payments SET status='pending_verification', verification_status='not_started',
         proof_path='uploads/payment_proofs/_p9a_unverified.png' WHERE payment_id=$pidN"
    );
    $mark(
        'N_PROOF_NOT_VERIFIED',
        p9a_status($conn, $uN) === 'pending'
        && p9a_grant_count($conn, $uN) === 0
        && !empty(commerce_get_payment($conn, $pidN)['proof_path']),
        'status=' . p9a_status($conn, $uN)
    );

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

foreach ($createdPaymentIds as $pid) {
    $pid = (int) $pid;
    mysqli_query($conn, "DELETE FROM payment_verification_attempts WHERE payment_id=$pid");
    mysqli_query($conn, "DELETE FROM payment_gcash_references WHERE payment_id=$pid");
    mysqli_query($conn, "DELETE FROM access_grants WHERE payment_id=$pid");
    mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id=$pid");
    mysqli_query($conn, "DELETE FROM payments WHERE payment_id=$pid");
}
foreach ($createdFarIds as $fid) {
    $fid = (int) $fid;
    mysqli_query($conn, "DELETE FROM access_grants WHERE free_access_request_id=$fid");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE request_id=$fid");
}
foreach ($createdUserIds as $uid) {
    $uid = (int) $uid;
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id=$uid");
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id=$uid");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id=$uid");
    mysqli_query($conn, "DELETE FROM users WHERE user_id=$uid AND email LIKE 'p9a.%@example.com'");
}
foreach ($createdPackageIds as $pkg) {
    mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pkg . " AND code LIKE 'TEST_P9A_%'");
}

$endPay = $countTable($conn, 'payments');
$endItems = $countTable($conn, 'payment_items');
$endAttempts = $countTable($conn, 'payment_verification_attempts');
$endGcash = $countTable($conn, 'payment_gcash_references');
$endGrants = $countTable($conn, 'access_grants');
$endFar = $countTable($conn, 'free_access_requests');
$endSca = $countTable($conn, 'student_content_permissions');

$cleanupOk = $endPay === $basePay && $endItems === $baseItems && $endAttempts === $baseAttempts
    && $endGcash === $baseGcash && $endGrants === $baseGrants && $endFar === $baseFar && $endSca === $baseSca;
$mark(
    'CLEANUP',
    $cleanupOk,
    "pay $basePay->$endPay grants $baseGrants->$endGrants sca $baseSca->$endSca far $baseFar->$endFar"
);

echo "\nFINAL pay=$endPay items=$endItems attempts=$endAttempts gcash=$endGcash grants=$endGrants far=$endFar sca=$endSca\n";

$failed = array_filter($results, static function ($r) { return !$r['ok']; });
echo 'Summary: ' . (count($results) - count($failed)) . '/' . count($results) . " passed\n";
exit($failed === [] ? 0 : 1);
