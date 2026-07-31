<?php
/**
 * Student Admin ↔ Commerce integration tests (reversible).
 * Does not modify Phase 8 algorithms; verifies Approve safety + summary helpers.
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_content_access.php';
require_once __DIR__ . '/../includes/commerce_payment.php';
require_once __DIR__ . '/../includes/commerce_fulfillment.php';
require_once __DIR__ . '/../includes/commerce_free_access.php';
require_once __DIR__ . '/../includes/commerce_student_admin.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 9 Student ↔ Commerce Admin Integration ===\n";

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
$proofFiles = [];

function p9_user(mysqli $conn, string $email, string $path, ?int $packageId = null): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'P9 Integration';
    $school = 'Test';
    $review = 'reviewee';
    $proof = '';
    $status = 'pending';
    if ($packageId !== null && $packageId > 0) {
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

function p9_has_sca(mysqli $conn, int $userId, string $type, int $cid): bool
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

function p9_sca_count(mysqli $conn, int $userId): int
{
    return (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions WHERE user_id=' . (int) $userId))[0] ?? 0);
}

/** Simulate activate_user.php commerce branch (login only; preserve commerce SCA). */
function p9_activate_commerce_login_only(mysqli $conn, int $userId, int $months, int $adminId): bool
{
    $sql = "UPDATE users SET status='approved', access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL ? MONTH), access_months=? WHERE user_id=? AND role='student'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iii', $months, $months, $userId);
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);
        return false;
    }
    mysqli_stmt_close($stmt);
    return sca_save_user_permissions_preserving_commerce($conn, $userId, [], $adminId);
}

try {
    $ts = (string) time();
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        throw new RuntimeException('Need admin user');
    }

    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P9_PKG', 'P9 Package', 't', 150000, 'PHP', 6, 'month', 'full_lms', 1, 1, 99)");
    $pkgId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $pkgId;

    // ---- A: pending paid — activate must not grant paid SCA ----
    $uA = p9_user($conn, "p9.a.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uA;
    $coA = commerce_create_or_resume_checkout($conn, $uA, 'package', $pkgId, null);
    $pidA = (int) ($coA['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidA;
    $payA0 = commerce_get_payment($conn, $pidA);
    $scaBefore = p9_sca_count($conn, $uA);
    $grantsBefore = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uA"))[0] ?? 0);
    $okActA = p9_activate_commerce_login_only($conn, $uA, 6, $adminId);
    $scaAfter = p9_sca_count($conn, $uA);
    $grantsAfter = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uA"))[0] ?? 0);
    $payA1 = commerce_get_payment($conn, $pidA);
    $stA = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM users WHERE user_id=$uA"));
    $mark(
        'A_NO_PAID_SCA',
        $okActA
        && ($stA['status'] ?? '') === 'approved'
        && $scaAfter === $scaBefore
        && $grantsAfter === $grantsBefore
        && $grantsAfter === 0
        && !p9_has_sca($conn, $uA, 'full_lms', 0)
        && ($payA1['status'] ?? '') === ($payA0['status'] ?? '')
        && empty($payA1['fulfilled_at']),
        "sca=$scaBefore->$scaAfter grants=$grantsAfter pay=" . ($payA1['status'] ?? '')
    );

    $sumA = commerce_admin_student_detail_summary($conn, array_merge(
        mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE user_id=$uA")) ?: [],
        []
    ));
    $mark(
        'A_UI_SUMMARY',
        ($sumA['is_paid_path'] ?? false)
        && ($sumA['account_label'] ?? '') === 'Active'
        && !empty($sumA['latest_payment'])
        && empty($sumA['latest_payment']['fulfilled'])
        && ($sumA['commerce_access']['tone'] ?? '') === 'none',
        (string) ($sumA['commerce_access']['label'] ?? '')
    );

    // ---- B: fulfill then summary shows commerce access ----
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='auto_verified', paid_at=NOW() WHERE payment_id=$pidA");
    $fB = commerce_fulfill_payment($conn, $pidA, ['granted_by' => $adminId]);
    $sumB = commerce_admin_student_detail_summary($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE user_id=$uA")) ?: []);
    $mark(
        'B_FULFILLED_UI',
        !empty($fB['ok'])
        && p9_has_sca($conn, $uA, 'full_lms', 0)
        && !empty($sumB['latest_payment']['fulfilled'])
        && ($sumB['commerce_access']['tone'] ?? '') === 'active'
        && !empty($sumB['latest_payment']['payment_id']),
        (string) ($sumB['commerce_access']['label'] ?? '')
    );

    // ---- C: fulfilled → login auto-activated ----
    $uC = p9_user($conn, "p9.c.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uC;
    $coC = commerce_create_or_resume_checkout($conn, $uC, 'package', $pkgId, null);
    $pidC = (int) ($coC['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidC;
    mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='manually_approved', paid_at=NOW() WHERE payment_id=$pidC");
    $fC = commerce_fulfill_payment($conn, $pidC, ['granted_by' => $adminId]);
    $sumC = commerce_admin_student_detail_summary($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE user_id=$uC")) ?: []);
    $mark(
        'C_AUTO_ACTIVATED_AFTER_FULFILL',
        !empty($fC['ok'])
        && !empty($fC['activation']['activated'])
        && ($sumC['account_label'] ?? '') === 'Active'
        && ($sumC['commerce_access']['tone'] ?? '') === 'active'
        && !empty($sumC['latest_payment']['fulfilled']),
        'acct=' . ($sumC['account_label'] ?? '') . ' access=' . ($sumC['commerce_access']['label'] ?? '')
        . ' act=' . (!empty($fC['activation']['activated']) ? '1' : '0')
    );

    // ---- D: approved account + unresolved payment — no commerce grant created by activate ----
    $uD = p9_user($conn, "p9.d.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uD;
    $coD = commerce_create_or_resume_checkout($conn, $uD, 'package', $pkgId, null);
    $pidD = (int) ($coD['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidD;
    p9_activate_commerce_login_only($conn, $uD, 3, $adminId);
    $gD = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uD"))[0] ?? 0);
    $payD = commerce_get_payment($conn, $pidD);
    $sumD = commerce_admin_student_detail_summary($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE user_id=$uD")) ?: []);
    $mark(
        'D_APPROVED_UNPAID',
        $gD === 0
        && ($payD['status'] ?? '') !== 'paid'
        && empty($payD['fulfilled_at'])
        && ($sumD['account_label'] ?? '') === 'Active'
        && ($sumD['commerce_access']['tone'] ?? '') === 'none',
        'grants=' . $gD . ' pay=' . ($payD['status'] ?? '')
    );

    // ---- E: Free Access ----
    $uE = p9_user($conn, "p9.e.{$ts}@example.com", 'free_access', null);
    $createdUserIds[] = $uE;
    $farRef = 'FAR-P9-' . $ts;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('$farRef', $uE, 'pending')");
    $farId = (int) mysqli_insert_id($conn);
    $createdFarIds[] = $farId;
    $farOk = commerce_far_approve($conn, $farId, $adminId, 3, 'p9 test');
    p9_activate_commerce_login_only($conn, $uE, 3, $adminId);
    $payE = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM payments WHERE user_id=$uE"))[0] ?? 0);
    $sumE = commerce_admin_student_detail_summary($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE user_id=$uE")) ?: []);
    $mark(
        'E_FREE_ACCESS',
        !empty($farOk['ok'])
        && $payE === 0
        && ($sumE['is_free_access'] ?? false)
        && !empty($sumE['far'])
        && ($sumE['far']['status'] ?? '') === 'approved'
        && ($sumE['commerce_access']['tone'] ?? '') === 'active'
        && empty($sumE['latest_payment']),
        'far=' . ($sumE['far']['status'] ?? '') . ' pay=' . $payE
    );

    // ---- F: proof link helper (no upload; flag only) ----
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p9_proof_' . bin2hex(random_bytes(3)) . '.png';
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $proofFiles[] = $tmp;
    $uF = p9_user($conn, "p9.f.{$ts}@example.com", 'package', $pkgId);
    $createdUserIds[] = $uF;
    $coF = commerce_create_or_resume_checkout($conn, $uF, 'package', $pkgId, null);
    $pidF = (int) ($coF['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidF;
    commerce_issue_checkout_session($uF, $pidF);
    $subF = commerce_submit_payment_proof_and_reference($conn, $pidF, $uF, 'P9REF' . substr($ts, -6), [
        'name' => 'p9.png',
        'type' => 'image/png',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ]);
    $payF = commerce_get_payment($conn, $pidF);
    if (!empty($payF['proof_path'])) {
        $proofFiles[] = dirname(__DIR__) . '/' . $payF['proof_path'];
    }
    $sumF = commerce_admin_student_detail_summary($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE user_id=$uF")) ?: []);
    $mark(
        'F_PROOF_LINK',
        !empty($subF['ok'])
        && !empty($sumF['latest_payment']['has_proof'])
        && (int) $sumF['latest_payment']['payment_id'] === $pidF
        && strpos(file_get_contents(dirname(__DIR__) . '/admin_student_view.php'), 'payment_proof_file') !== false
        && strpos(file_get_contents(dirname(__DIR__) . '/includes/admin_student_commerce_panel.php'), 'payment_proof_file') !== false,
        'has_proof=' . (!empty($sumF['latest_payment']['has_proof']) ? '1' : '0')
    );

    // ---- G: deep links in sources ----
    $viewSrc = file_get_contents(dirname(__DIR__) . '/admin_student_view.php');
    $paySrc = file_get_contents(dirname(__DIR__) . '/admin_commerce_payments.php');
    $panelSrc = file_get_contents(dirname(__DIR__) . '/includes/admin_student_commerce_panel.php');
    $actSrc = file_get_contents(dirname(__DIR__) . '/activate_user.php');
    $mark(
        'G_DEEP_LINKS',
        strpos($panelSrc, 'admin_commerce_payments') !== false
        && strpos($panelSrc, 'admin_commerce_grants') !== false
        && strpos($panelSrc, 'admin_commerce_free_access') !== false
        && strpos($paySrc, 'admin_student_view') !== false
        && strpos($paySrc, 'View Student') !== false
        && strpos($viewSrc, 'Repair Activation') !== false
        && strpos($viewSrc, 'Activate Account') === false,
        'links ok'
    );

    // ---- H: GET pages no mutations (static) ----
    $mark(
        'H_GET_SAFE',
        strpos($viewSrc, 'commerce_fulfill_payment') === false
        && strpos($panelSrc, 'mysqli_query($conn, \'DELETE') === false
        && strpos($actSrc, 'commerce_fulfill_payment') === false
        && strpos($actSrc, 'commerce_admin_is_commerce_enrollment_path') !== false,
        'no fulfill from student UI/activate'
    );

    // ---- J: list badges helper ----
    $badges = commerce_admin_students_list_badges($conn, [$uA, $uE, $uD]);
    $mark(
        'J_LIST_BADGES',
        isset($badges[$uA], $badges[$uE], $badges[$uD])
        && !empty($badges[$uE]['is_free_access'])
        && ($badges[$uD]['account_label'] ?? '') === 'Active'
        && ($badges[$uD]['commerce_access_tone'] ?? '') === 'none',
        'badges ok'
    );

    // ---- K: dashboard mapper — awaiting_proof / needs_review / verified+fulfilled / rejected / FAR ----
    $mapAwait = commerce_admin_dashboard_status([
        'user_id' => 1,
        'enrollment_path' => 'package',
        'account_status' => 'pending',
        'grant_tone' => 'none',
        'payment_id' => 10,
        'payment_status' => 'awaiting_proof',
        'verification_status' => 'not_started',
        'fulfilled' => false,
        'has_proof' => false,
    ]);
    $mapPendingVer = commerce_admin_dashboard_status([
        'user_id' => 1,
        'enrollment_path' => 'package',
        'account_status' => 'pending',
        'grant_tone' => 'none',
        'payment_id' => 10,
        'payment_status' => 'pending_verification',
        'verification_status' => 'not_started',
        'fulfilled' => false,
        'has_proof' => true,
    ]);
    $mapReview = commerce_admin_dashboard_status([
        'user_id' => 1,
        'enrollment_path' => 'package',
        'account_status' => 'pending',
        'grant_tone' => 'none',
        'payment_id' => 11,
        'payment_status' => 'pending_verification',
        'verification_status' => 'needs_review',
        'fulfilled' => false,
        'has_proof' => true,
    ]);
    $mapVerified = commerce_admin_dashboard_status([
        'user_id' => 1,
        'enrollment_path' => 'package',
        'account_status' => 'pending',
        'grant_tone' => 'active',
        'payment_id' => 12,
        'payment_status' => 'paid',
        'verification_status' => 'auto_verified',
        'fulfilled' => true,
        'has_proof' => true,
    ]);
    $mapRejected = commerce_admin_dashboard_status([
        'user_id' => 1,
        'enrollment_path' => 'by_topic',
        'account_status' => 'pending',
        'grant_tone' => 'none',
        'payment_id' => 13,
        'payment_status' => 'rejected',
        'verification_status' => 'manually_rejected',
        'fulfilled' => false,
        'has_proof' => true,
    ]);
    $mapFar = commerce_admin_dashboard_status([
        'user_id' => 2,
        'enrollment_path' => 'free_access',
        'account_status' => 'pending',
        'grant_tone' => 'none',
        'far_status' => 'pending',
        'far_request_id' => 99,
        'has_proof' => false,
    ]);
    $mark(
        'K_DASHBOARD_MAPPER',
        ($mapAwait['payment_ui'] ?? '') === 'Awaiting Payment'
        && ($mapAwait['proof_ui'] ?? '') === 'Not Uploaded'
        && ($mapAwait['access_ui'] ?? '') === 'None'
        && ($mapAwait['action_label'] ?? '') === 'View'
        && ($mapPendingVer['payment_ui'] ?? '') === 'Pending Verification'
        && ($mapPendingVer['action_label'] ?? '') === 'Review'
        && ($mapReview['payment_ui'] ?? '') === 'Needs Review'
        && ($mapReview['action_label'] ?? '') === 'Review'
        && strpos((string) ($mapReview['action_href'] ?? ''), 'admin_commerce_payments?id=11') !== false
        && ($mapVerified['payment_ui'] ?? '') === 'Verified'
        && ($mapVerified['access_ui'] ?? '') === 'Granted'
        && ($mapVerified['action_label'] ?? '') === 'View'
        && ($mapRejected['payment_ui'] ?? '') === 'Rejected'
        && ($mapRejected['action_label'] ?? '') === 'Review'
        && ($mapFar['payment_ui'] ?? '') === 'N/A'
        && ($mapFar['proof_ui'] ?? '') === 'N/A'
        && ($mapFar['access_ui'] ?? '') === 'Pending'
        && ($mapFar['action_label'] ?? '') === 'Review'
        && strpos((string) ($mapFar['action_href'] ?? ''), 'admin_commerce_free_access?id=99') !== false,
        'await/pending_ver/review/verified/rejected/far'
    );

    // ---- L: dashboard rows — fulfilled students are Active (no activation_required) ----
    $rowsDash = commerce_admin_students_dashboard_rows($conn, [$uA, $uC, $uD, $uE, $uF]);
    $uFPayUi = (string) ($rowsDash[$uF]['payment_ui'] ?? '');
    $uFActionOk = (
        (in_array($uFPayUi, ['Needs Review', 'Pending Verification'], true)
            && ($rowsDash[$uF]['action_label'] ?? '') === 'Review'
            && strpos((string) ($rowsDash[$uF]['action_href'] ?? ''), 'admin_commerce_payments?id=' . $pidF) !== false)
        || ($uFPayUi === 'Verified'
            && ($rowsDash[$uF]['action_label'] ?? '') === 'View')
    );
    $mark(
        'L_DASHBOARD_ROWS',
        isset($rowsDash[$uA], $rowsDash[$uC], $rowsDash[$uD], $rowsDash[$uE], $rowsDash[$uF])
        && ($rowsDash[$uA]['payment_ui'] ?? '') === 'Verified'
        && ($rowsDash[$uA]['access_ui'] ?? '') === 'Granted'
        && ($rowsDash[$uA]['action_label'] ?? '') === 'View'
        && ($rowsDash[$uC]['account_label'] ?? '') === 'Active'
        && empty($rowsDash[$uC]['activation_required'])
        && empty($rowsDash[$uC]['show_repair_activation'])
        && ($rowsDash[$uD]['payment_ui'] ?? '') === 'Awaiting Payment'
        && ($rowsDash[$uD]['proof_ui'] ?? '') === 'Not Uploaded'
        && empty($rowsDash[$uD]['has_proof'])
        && ($rowsDash[$uD]['proof_url'] ?? '') === ''
        && empty($rowsDash[$uD]['show_repair_activation'])
        && !empty($rowsDash[$uF]['has_proof'])
        && ($rowsDash[$uF]['proof_ui'] ?? '') === 'View Proof'
        && strpos((string) ($rowsDash[$uF]['proof_url'] ?? ''), 'payment_proof_file') !== false
        && strpos((string) ($rowsDash[$uF]['proof_url'] ?? ''), 'payment_id=' . $pidF) !== false
        && $uFActionOk
        && empty($rowsDash[$uF]['show_repair_activation'])
        && ($rowsDash[$uE]['enrollment_label'] ?? '') === 'Free Access'
        && ($rowsDash[$uE]['payment_ui'] ?? '') === 'N/A'
        && ($rowsDash[$uE]['proof_ui'] ?? '') === 'N/A'
        && ($rowsDash[$uE]['access_ui'] ?? '') === 'Granted'
        && ($rowsDash[$uE]['account_label'] ?? '') === 'Active',
        'A=' . ($rowsDash[$uA]['payment_ui'] ?? '') . '/' . ($rowsDash[$uA]['access_ui'] ?? '')
        . ' C=' . ($rowsDash[$uC]['account_label'] ?? '')
        . ' D=' . ($rowsDash[$uD]['payment_ui'] ?? '') . ' proofD=' . (!empty($rowsDash[$uD]['has_proof']) ? '1' : '0')
        . ' F=' . $uFPayUi . ' E=' . ($rowsDash[$uE]['access_ui'] ?? '')
    );

    // ---- M: students table commerce columns present ----
    $studentsSrc = file_get_contents(dirname(__DIR__) . '/admin_students.php');
    $cssSrc = file_get_contents(dirname(__DIR__) . '/assets/css/admin-students.css');
    $mark(
        'M_STUDENTS_TABLE',
        strpos($studentsSrc, 'col-enrollment') !== false
        && strpos($studentsSrc, 'col-account-status') !== false
        && strpos($studentsSrc, 'commerce_admin_students_dashboard_rows') !== false
        && strpos($studentsSrc, 'payment_proof_file') !== false
        && strpos($studentsSrc, 'Repair Activation') !== false
        && strpos($studentsSrc, 'Activate Account') === false
        && strpos($studentsSrc, 'Needs review') !== false
        && strpos($studentsSrc, 'Approve & grant') === false
        && strpos($cssSrc, 'commerce-pill') !== false
        && strpos($cssSrc, 'col-account-status') !== false
        && (
            strpos(file_get_contents(dirname(__DIR__) . '/includes/admin_student_commerce_panel.php'), 'Pending Activation') !== false
            || strpos(file_get_contents(dirname(__DIR__) . '/includes/admin_student_commerce_panel.php'), 'Account: Active') !== false
        )
        && strpos(file_get_contents(dirname(__DIR__) . '/admin_commerce_payments.php'), 'Enrollment') !== false
        && strpos(file_get_contents(dirname(__DIR__) . '/admin_commerce_payments.php'), 'account activation requires repair') !== false,
        'table+polish ok'
    );

    $gUnpaid = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uD"))[0] ?? 0);
    $mark('N_ACTIVATE_NO_GRANTS_UNPAID', $gUnpaid === 0, 'grants=' . $gUnpaid);

    // ---- I: activate_user commerce path preserves SCA after fulfill (after activation_required assertion) ----
    $scaCBefore = p9_sca_count($conn, $uC);
    p9_activate_commerce_login_only($conn, $uC, 6, $adminId);
    $scaCAfter = p9_sca_count($conn, $uC);
    $mark(
        'I_PRESERVE_COMMERCE_SCA',
        $scaCBefore > 0 && $scaCAfter === $scaCBefore && p9_has_sca($conn, $uC, 'full_lms', 0),
        "sca=$scaCBefore->$scaCAfter"
    );

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup own fixtures only
foreach ($createdPaymentIds as $pid) {
    $pid = (int) $pid;
    $pr = mysqli_query($conn, "SELECT proof_path FROM payments WHERE payment_id=$pid");
    $prow = $pr ? mysqli_fetch_assoc($pr) : null;
    if (!empty($prow['proof_path']) && str_starts_with((string) $prow['proof_path'], 'uploads/payment_proofs/')) {
        $abs = dirname(__DIR__) . '/' . $prow['proof_path'];
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
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
    mysqli_query($conn, "DELETE FROM users WHERE user_id=$uid AND email LIKE 'p9.%@example.com'");
}
foreach ($createdPackageIds as $pkg) {
    mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pkg . " AND code LIKE 'TEST_P9_%'");
}
foreach ($proofFiles as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
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

// Nested regressions: children skip their own nests via COMMERCE_SKIP_NESTED_REGRESSIONS.
putenv('COMMERCE_SKIP_NESTED_REGRESSIONS=1');
$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$nested = [
    'phase8_5' => __DIR__ . '/phase8_5_commerce_reports_test.php',
    'phase8_4' => __DIR__ . '/phase8_4_commerce_notifications_test.php',
    'phase8_3' => __DIR__ . '/phase8_3_paid_revoke_test.php',
    'phase8_2' => __DIR__ . '/phase8_2_expiry_reconcile_test.php',
    'phase8_1' => __DIR__ . '/phase8_1_free_access_test.php',
    'phase8_1_idemp' => __DIR__ . '/phase8_1_idempotency_hardening_test.php',
    'phase7' => __DIR__ . '/phase7_fulfillment_test.php',
    'activation' => __DIR__ . '/activation_commerce_sca_hardening_test.php',
    'student_access' => __DIR__ . '/student_access_commerce_sca_hardening_test.php',
];
if (getenv('P9_SKIP_NESTED') === '1') {
    echo "NESTED_REGRESSIONS=SKIPPED (P9_SKIP_NESTED=1)\n";
} else {
    foreach ($nested as $name => $script) {
        if (!is_file($script)) {
            $mark('NESTED_' . $name, false, 'missing');
            continue;
        }
        $cmd = 'set COMMERCE_SKIP_NESTED_REGRESSIONS=1&& "' . $php . '" ' . escapeshellarg($script);
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $mark('NESTED_' . $name, $code === 0, 'exit=' . $code);
    }
}

$failed = array_filter($results, static function ($r) { return !$r['ok']; });
echo "\nSummary: " . (count($results) - count($failed)) . '/' . count($results) . " passed\n";
exit($failed ? 1 : 0);
