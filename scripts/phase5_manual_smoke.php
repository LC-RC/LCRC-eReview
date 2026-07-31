<?php
/**
 * Phase 5 MANUAL smoke execution (reversible).
 * Uses existing admin packages; temporarily enables 2 lessons for By Topic only.
 * Cleans all smoke users/payments/proofs; restores lesson pricing.
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_payment.php';
require_once __DIR__ . '/../email_verification.php';
require_once __DIR__ . '/../auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$results = [];
$mark = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[$id] = ['ok' => $ok, 'detail' => $detail];
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $id" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
};

echo "=== Phase 5 MANUAL SMOKE ===\n";

$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$adminPackages = [];
$pq = mysqli_query($conn, 'SELECT package_id, code, name FROM sellable_packages ORDER BY package_id');
while ($pq && ($r = mysqli_fetch_assoc($pq))) {
    $adminPackages[] = $r;
}

$pkg = null;
$pr = mysqli_query($conn, "SELECT * FROM sellable_packages WHERE code='sf6ma' AND is_active=1 AND is_purchasable=1 LIMIT 1");
if ($pr) {
    $pkg = mysqli_fetch_assoc($pr);
}
if (!$pkg) {
    echo "ABORT: admin package sf6ma not available\n";
    exit(1);
}
$packageId = (int) $pkg['package_id'];
$features = commerce_get_package_features($conn, $packageId);

$lessonIds = [2, 3];
$lessonSnap = [];
$ls = mysqli_query($conn, 'SELECT lesson_id, price_centavos, access_duration_value, access_duration_unit, is_purchasable FROM lessons WHERE lesson_id IN (2,3)');
while ($ls && ($r = mysqli_fetch_assoc($ls))) {
    $lessonSnap[(int) $r['lesson_id']] = $r;
}

$smokeUsers = [];
$proofFiles = [];
$ts = time();

function smoke_create_pending_and_verify(mysqli $conn, array $data): ?int
{
    $url = createPendingRegistration($data);
    if (!is_string($url) || $url === '') {
        return null;
    }
    // Extract token from URL
    $parts = parse_url($url);
    $token = '';
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        $token = (string) ($q['token'] ?? '');
    }
    if ($token === '') {
        return null;
    }
    $pending = validateVerificationToken($token);
    if ($pending === null) {
        return null;
    }
    return completeVerificationAndCreateUser($pending);
}

function smoke_png(string $path): void
{
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
}

try {
    // Temporarily enable By Topic lessons (restored in finally)
    mysqli_query($conn, "UPDATE lessons SET price_centavos=25000, access_duration_value=30, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=2");
    mysqli_query($conn, "UPDATE lessons SET price_centavos=15000, access_duration_value=14, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=3");

    // ========== 1. Package registration ==========
    $_SESSION = [];
    session_regenerate_id(true);
    $emailPkg = "smoke.pkg.{$ts}@example.com";
    $uidPkg = smoke_create_pending_and_verify($conn, [
        'email' => $emailPkg,
        'full_name' => 'Smoke Package User',
        'review_type' => 'reviewee',
        'school' => 'Laguna University',
        'school_other' => null,
        'payment_proof' => '',
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('SmokeTest1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'package',
        'selected_package_id' => $packageId,
        'selected_lesson_ids_json' => null,
        'free_access_note' => null,
    ]);
    if ($uidPkg) {
        $smokeUsers[] = $uidPkg;
    }
    $uRow = null;
    if ($uidPkg) {
        $ur = mysqli_prepare($conn, 'SELECT user_id, status, enrollment_path, selected_package_id FROM users WHERE user_id=? LIMIT 1');
        mysqli_stmt_bind_param($ur, 'i', $uidPkg);
        mysqli_stmt_execute($ur);
        $uRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ur));
        mysqli_stmt_close($ur);
    }
    $mark('1_PENDING_USER', $uRow && ($uRow['status'] ?? '') === 'pending' && ($uRow['enrollment_path'] ?? '') === 'package', json_encode($uRow));

    // completeVerification already bootstraps checkout; ensure session exists
    $checkoutReady = !empty($_SESSION['checkout_payment_id']) && !empty($_SESSION['checkout_token'])
        && (int) ($_SESSION['checkout_user_id'] ?? 0) === (int) $uidPkg;
    if (!$checkoutReady && $uidPkg) {
        $boot = commerce_bootstrap_checkout_after_verification($conn, $uidPkg);
        $checkoutReady = !empty($boot['ok']);
    }
    $mark('1_CHECKOUT_SESSION', $checkoutReady, 'payment_id=' . (int) ($_SESSION['checkout_payment_id'] ?? 0));
    $mark('1_CONTINUE_CTA', $checkoutReady, 'Continue to payment would show');

    $payAuth = commerce_require_checkout_session($conn);
    $pay = $payAuth['payment'] ?? null;
    $items = $pay ? commerce_get_payment_items($conn, (int) $pay['payment_id']) : [];
    $item0 = $items[0] ?? null;
    $mark(
        '1_CHECKOUT_DB_SNAPSHOT',
        $pay
        && (int) $pay['expected_amount_centavos'] === (int) $pkg['price_centavos']
        && (int) ($item0['duration_value'] ?? 0) === (int) $pkg['duration_value']
        && (string) ($item0['duration_unit'] ?? '') === (string) $pkg['duration_unit']
        && (string) ($item0['package_access_scope'] ?? '') === 'full_lms'
        && (string) ($item0['item_name'] ?? '') === (string) $pkg['name'],
        'amt=' . (int) ($pay['expected_amount_centavos'] ?? 0) . ' name=' . (string) ($item0['item_name'] ?? '')
    );
    $featJson = (string) ($item0['package_features_snapshot_json'] ?? '[]');
    $mark('1_FEATURES_PRESENT', $featJson !== '' && $featJson !== 'null', 'features_json_len=' . strlen($featJson));

    $settings = commerce_get_payment_settings($conn);
    $mark('1_GCASH_SETTINGS', ($settings['gcash_number'] ?? '') !== '', 'number set for checkout UI');

    // ========== 2. Checkout recovery ==========
    $openBefore = (int) ($_SESSION['checkout_payment_id'] ?? 0);
    // Refresh reopen
    $reopen = commerce_require_checkout_session($conn);
    $mark('2_REFRESH_REOPEN', !empty($reopen['ok']) && (int) $reopen['payment']['payment_id'] === $openBefore, 'same open payment');

    // Continue Payment recovery: clear checkout session, arm recovery, resume
    $recoveryUid = (int) $uidPkg;
    commerce_clear_checkout_session();
    commerce_arm_checkout_recovery($recoveryUid, 'smoke recovery');
    $recTok = (string) $_SESSION['checkout_recovery_token'];
    $resumed = commerce_resume_checkout_from_recovery($conn, $recTok);
    $mark('2_CONTINUE_PAYMENT', !empty($resumed['ok']) && (int) $resumed['payment']['payment_id'] === $openBefore, 'resumed same payment');

    // Other user cannot access
    $emailOther = "smoke.other.{$ts}@example.com";
    $uidOther = smoke_create_pending_and_verify($conn, [
        'email' => $emailOther,
        'full_name' => 'Smoke Other User',
        'review_type' => 'reviewee',
        'school' => 'Laguna University',
        'school_other' => null,
        'payment_proof' => '',
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('SmokeTest1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'package',
        'selected_package_id' => $packageId,
        'selected_lesson_ids_json' => null,
        'free_access_note' => null,
    ]);
    if ($uidOther) {
        $smokeUsers[] = $uidOther;
    }
    // Tamper: other user session claiming package user's payment
    $_SESSION['checkout_user_id'] = (int) $uidOther;
    $_SESSION['checkout_payment_id'] = $openBefore;
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
    $_SESSION['checkout_expires_at'] = time() + 3600;
    $idor = commerce_require_checkout_session($conn);
    $mark('2_CROSS_USER_BLOCK', empty($idor['ok']), $idor['error'] ?? 'blocked');

    // payment_ref alone cannot authorize
    $pref = (string) ($pay['payment_ref'] ?? '');
    commerce_clear_checkout_session();
    $_GET['payment_ref'] = $pref; // must be ignored by require
    $byRef = commerce_require_checkout_session($conn);
    $mark('2_NO_REF_AUTH', empty($byRef['ok']) && $pref !== '', 'ref=' . $pref);

    // Restore rightful checkout session for submission
    commerce_issue_checkout_session((int) $uidPkg, $openBefore);

    // ========== 3. Payment submission ==========
    $tmpProof = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smoke_proof_' . bin2hex(random_bytes(4)) . '.png';
    smoke_png($tmpProof);
    $gcashRaw = 'SMK-' . strtoupper(bin2hex(random_bytes(3))) . ' 99';
    $normExpect = commerce_normalize_gcash_reference($gcashRaw);
    $sub = commerce_submit_payment_proof_and_reference($conn, $openBefore, (int) $uidPkg, $gcashRaw, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmpProof,
        'size' => filesize($tmpProof),
        'name' => 'receipt.png',
        'type' => 'image/png',
    ]);
    $payAfter = commerce_get_payment($conn, $openBefore);
    $mark('3_SUBMIT_OK', !empty($sub['ok']), $sub['error'] ?? 'ok');
    $mark('3_STATUS', $payAfter && ($payAfter['status'] ?? '') === 'pending_verification', (string) ($payAfter['status'] ?? ''));
    $mark('3_VERIFICATION', $payAfter && ($payAfter['verification_status'] ?? '') === 'not_started', (string) ($payAfter['verification_status'] ?? ''));
    $gchk = mysqli_prepare($conn, 'SELECT gcash_reference_norm, payment_id, user_id FROM payment_gcash_references WHERE gcash_reference_norm=? LIMIT 1');
    mysqli_stmt_bind_param($gchk, 's', $normExpect);
    mysqli_stmt_execute($gchk);
    $grow = mysqli_fetch_assoc(mysqli_stmt_get_result($gchk));
    mysqli_stmt_close($gchk);
    $mark('3_GCASH_LOCK', $grow && (int) $grow['payment_id'] === $openBefore && (int) $grow['user_id'] === (int) $uidPkg, $normExpect);
    if (!empty($payAfter['proof_path'])) {
        $proofFiles[] = dirname(__DIR__) . '/' . $payAfter['proof_path'];
    }
    $mark('3_NO_OCR', empty($payAfter['ocr_engine']) && empty($payAfter['ocr_raw_text']) && ($payAfter['verification_status'] ?? '') === 'not_started', 'no ocr fields');
    $mark('3_NO_APPROVAL', empty($payAfter['paid_at']) && empty($payAfter['reviewed_by']) && ($payAfter['status'] ?? '') !== 'paid', 'not approved');
    $mark('3_NO_FULFILL', empty($payAfter['fulfilled_at']), 'not fulfilled');
    $gNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $sNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
    $mark('3_NO_GRANTS', $gNow === $baseGrants, "grants=$gNow");
    $mark('3_NO_SCA_CHANGE', $sNow === $baseSca, "sca=$sNow");

    // ========== 4. By Topic ==========
    $_SESSION = [];
    $emailTopic = "smoke.topic.{$ts}@example.com";
    $uidTopic = smoke_create_pending_and_verify($conn, [
        'email' => $emailTopic,
        'full_name' => 'Smoke Topic User',
        'review_type' => 'reviewee',
        'school' => 'Laguna University',
        'school_other' => null,
        'payment_proof' => '',
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('SmokeTest1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'by_topic',
        'selected_package_id' => null,
        'selected_lesson_ids_json' => json_encode([2, 3]),
        'free_access_note' => null,
    ]);
    if ($uidTopic) {
        $smokeUsers[] = $uidTopic;
    }
    if ($uidTopic && empty($_SESSION['checkout_payment_id'])) {
        commerce_bootstrap_checkout_after_verification($conn, $uidTopic);
    }
    $tAuth = commerce_require_checkout_session($conn);
    $tPay = $tAuth['payment'] ?? null;
    $tItems = $tPay ? commerce_get_payment_items($conn, (int) $tPay['payment_id']) : [];
    $mark('4_TOTAL', $tPay && (int) $tPay['expected_amount_centavos'] === 40000, 'total=' . (int) ($tPay['expected_amount_centavos'] ?? 0));
    $mark('4_ITEMS', count($tItems) === 2, 'lines=' . count($tItems));
    $tmp2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smoke_proof2_' . bin2hex(random_bytes(4)) . '.png';
    smoke_png($tmp2);
    $tRef = 'SMKT' . strtoupper(bin2hex(random_bytes(4)));
    $tSub = commerce_submit_payment_proof_and_reference($conn, (int) $tPay['payment_id'], (int) $uidTopic, $tRef, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp2,
        'size' => filesize($tmp2),
        'name' => 't.png',
        'type' => 'image/png',
    ]);
    $tAfter = commerce_get_payment($conn, (int) $tPay['payment_id']);
    $mark('4_BOUNDARY', !empty($tSub['ok']) && ($tAfter['status'] ?? '') === 'pending_verification' && ($tAfter['verification_status'] ?? '') === 'not_started', (string) ($tAfter['status'] ?? ''));
    if (!empty($tAfter['proof_path'])) {
        $proofFiles[] = dirname(__DIR__) . '/' . $tAfter['proof_path'];
    }

    // ========== 5. Free Access ==========
    $_SESSION = [];
    $emailFree = "smoke.free.{$ts}@example.com";
    $payBeforeFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $itemsBeforeFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $uidFree = smoke_create_pending_and_verify($conn, [
        'email' => $emailFree,
        'full_name' => 'Smoke Free User',
        'review_type' => 'reviewee',
        'school' => 'Laguna University',
        'school_other' => null,
        'payment_proof' => '',
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('SmokeTest1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'free_access',
        'selected_package_id' => null,
        'selected_lesson_ids_json' => null,
        'free_access_note' => 'Smoke free access note',
    ]);
    if ($uidFree) {
        $smokeUsers[] = $uidFree;
    }
    $far = null;
    if ($uidFree) {
        $fs = mysqli_prepare($conn, 'SELECT request_id, status, student_note FROM free_access_requests WHERE user_id=? LIMIT 1');
        mysqli_stmt_bind_param($fs, 'i', $uidFree);
        mysqli_stmt_execute($fs);
        $far = mysqli_fetch_assoc(mysqli_stmt_get_result($fs));
        mysqli_stmt_close($fs);
    }
    $payAfterFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $itemsAfterFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $freeCheckout = commerce_create_or_resume_checkout_for_user($conn, (int) $uidFree);
    $mark('5_FAR', $far && ($far['status'] ?? '') === 'pending', 'far created');
    $mark('5_NO_PAYMENTS', $payAfterFree === $payBeforeFree, "payments=$payAfterFree");
    $mark('5_NO_ITEMS', $itemsAfterFree === $itemsBeforeFree, "items=$itemsAfterFree");
    $mark('5_NO_CHECKOUT', empty($freeCheckout['ok']), $freeCheckout['error'] ?? 'blocked');
    $mark('5_NO_CHECKOUT_SESSION', empty($_SESSION['checkout_payment_id']), 'no checkout session for free');
    $gFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $sFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
    $mark('5_NO_GRANTS_SCA', $gFree === $baseGrants && $sFree === $baseSca, "g=$gFree s=$sFree");

    // ========== 6. Existing behavior ==========
    $loginSrc = (string) file_get_contents(__DIR__ . '/../login_process.php');
    $activateSrc = (string) file_get_contents(__DIR__ . '/../activate_user.php');
    $mark('6_LOGIN_GATE', str_contains($loginSrc, "strtolower(\$user['status']) !== 'approved'")
        && !str_contains($loginSrc, 'commerce_payment'), 'pending blocked; no commerce login change');
    $mark('6_ACTIVATE_UNTOUCHED', !str_contains($activateSrc, 'commerce_payment') && !str_contains($activateSrc, 'checkout_'), 'activate untouched');
    // Pending user with submitted payment still pending
    $st = mysqli_prepare($conn, 'SELECT status FROM users WHERE user_id=? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $uidPkg);
    mysqli_stmt_execute($st);
    $stRow = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    $mark('6_PENDING_NO_LMS', ($stRow['status'] ?? '') === 'pending', 'still pending after checkout submit');

} catch (Throwable $e) {
    $mark('EXCEPTION', false, $e->getMessage());
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    echo "\n=== CLEANUP ===\n";
    // Delete smoke users and related commerce rows
    $q = mysqli_query($conn, "SELECT user_id FROM users WHERE email LIKE 'smoke.%@example.com'");
    $uids = $smokeUsers;
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $uids[] = (int) $r['user_id'];
    }
    $uids = array_values(array_unique(array_filter($uids)));
    foreach ($uids as $uid) {
        mysqli_query($conn, 'DELETE FROM free_access_requests WHERE user_id=' . (int) $uid);
        mysqli_query($conn, 'DELETE FROM payment_gcash_references WHERE user_id=' . (int) $uid);
        $pq = mysqli_query($conn, 'SELECT payment_id, proof_path FROM payments WHERE user_id=' . (int) $uid);
        while ($pq && ($pr = mysqli_fetch_assoc($pq))) {
            $pid = (int) $pr['payment_id'];
            if (!empty($pr['proof_path']) && str_starts_with((string) $pr['proof_path'], 'uploads/payment_proofs/')) {
                $abs = dirname(__DIR__) . '/' . $pr['proof_path'];
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
            mysqli_query($conn, 'DELETE FROM payment_items WHERE payment_id=' . $pid);
            mysqli_query($conn, 'DELETE FROM payment_verification_attempts WHERE payment_id=' . $pid);
            mysqli_query($conn, 'DELETE FROM payments WHERE payment_id=' . $pid);
        }
        mysqli_query($conn, 'DELETE FROM users WHERE user_id=' . (int) $uid . " AND email LIKE 'smoke.%'");
    }
    mysqli_query($conn, "DELETE FROM pending_registrations WHERE email LIKE 'smoke.%@example.com'");

    foreach ($lessonSnap as $lid => $snap) {
        $pc = $snap['price_centavos'];
        $dv = $snap['access_duration_value'];
        $du = $snap['access_duration_unit'];
        $ip = (int) $snap['is_purchasable'];
        $pcSql = $pc === null ? 'NULL' : (int) $pc;
        $dvSql = $dv === null ? 'NULL' : (int) $dv;
        $duSql = $du === null ? 'NULL' : ("'" . mysqli_real_escape_string($conn, (string) $du) . "'");
        mysqli_query($conn, "UPDATE lessons SET price_centavos=$pcSql, access_duration_value=$dvSql, access_duration_unit=$duSql, is_purchasable=$ip WHERE lesson_id=" . (int) $lid);
    }
    foreach ($proofFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }

    $endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $endGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
    $endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
    $endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
    $endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
    $purch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
    $leftUsers = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE email LIKE 'smoke.%@example.com'"))[0] ?? 0);

    echo "FINAL_COUNTS payments=$endPay items=$endItems gcash=$endGcash grants=$endGrants far=$endFar sca=$endSca packages=$endPkg purchasable_lessons=$purch smoke_users_left=$leftUsers\n";
    echo "ADMIN_PACKAGES_PRESERVED=$endPkg (baseline=$basePkg)\n";
    foreach ($adminPackages as $ap) {
        echo "  - #{$ap['package_id']} {$ap['code']} {$ap['name']}\n";
    }
    $cleanupOk = $endPay === 0 && $endItems === 0 && $endGcash === 0 && $endGrants === $baseGrants
        && $endFar === 0 && $endSca === $baseSca && $endPkg === $basePkg && $purch === 0 && $leftUsers === 0;
    $mark('7_CLEANUP', $cleanupOk, 'temporary smoke data removed; admin catalog intact');
}

$failed = array_filter($results, static fn($r) => !$r['ok']);
echo "\nSummary: " . (count($results) - count($failed)) . '/' . count($results) . " passed\n";
echo "PHASE6_STARTED=NO\n";
exit($failed ? 1 : 0);
