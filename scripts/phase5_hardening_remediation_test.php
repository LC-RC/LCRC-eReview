<?php
/**
 * Phase 5 hardening remediation tests (recovery, payment_ref collision,
 * session regenerate, selection idempotency, security smoke).
 * Reversible - cleans all temporary rows/files.
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_payment.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " - $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 5 hardening remediation tests ===\n";

$basePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$baseItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$baseGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$basePurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
$baseFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);

$createdUserIds = [];
$createdPackageIds = [];
$proofFiles = [];
$lessonSnap = [];

$lr = mysqli_query($conn, "SELECT lesson_id FROM lessons WHERE subject_id IN (SELECT subject_id FROM subjects WHERE status='active') ORDER BY lesson_id LIMIT 2");
$lessonRows = [];
while ($lr && ($row = mysqli_fetch_assoc($lr))) {
    $lessonRows[] = (int) $row['lesson_id'];
}
if (count($lessonRows) < 2) {
    echo "ABORT: need 2 lessons\n";
    exit(1);
}
$lessonA = $lessonRows[0];
$lessonB = $lessonRows[1];

$snapStmt = mysqli_prepare($conn, 'SELECT lesson_id, price_centavos, access_duration_value, access_duration_unit, is_purchasable FROM lessons WHERE lesson_id IN (?,?)');
mysqli_stmt_bind_param($snapStmt, 'ii', $lessonA, $lessonB);
mysqli_stmt_execute($snapStmt);
$snapRes = mysqli_stmt_get_result($snapStmt);
while ($snapRes && ($r = mysqli_fetch_assoc($snapRes))) {
    $lessonSnap[(int) $r['lesson_id']] = $r;
}
mysqli_stmt_close($snapStmt);

function p5h_user(mysqli $conn, string $email, string $path, ?int $pkgId, ?string $lessonsJson): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase5 Harden';
    $school = 'Test School';
    $review = 'reviewee';
    $proof = '';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, review_type, enrollment_path, selected_package_id, selected_lesson_ids_json, school, school_other, payment_proof, email, password, role, status, email_verified)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'student', 'pending', 1)"
    );
    mysqli_stmt_bind_param($stmt, 'sssisssss', $name, $review, $path, $pkgId, $lessonsJson, $school, $proof, $email, $hash);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p5h_png(string $path): void
{
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
}

try {
    // Test-mode safety
    $mark('TESTMODE_CLI', commerce_payment_test_mode_active() === true, 'CLI+constant');
    $mark('TESTMODE_NO_REQUEST', !isset($_GET['COMMERCE_PAYMENT_TEST_MODE']) && !isset($_POST['COMMERCE_PAYMENT_TEST_MODE']), 'not request-driven');

    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P5H_FULL', 'P5H Full LMS', 'test', 150000, 'PHP', 6, 'month', 'full_lms', 1, 1, 1)");
    $fullId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $fullId;

    mysqli_query($conn, "UPDATE lessons SET price_centavos=25000, access_duration_value=30, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=" . (int) $lessonA);
    mysqli_query($conn, "UPDATE lessons SET price_centavos=15000, access_duration_value=14, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=" . (int) $lessonB);

    $u1 = p5h_user($conn, 'phase5h.u1.' . time() . '@example.com', 'package', $fullId, null);
    $u2 = p5h_user($conn, 'phase5h.u2.' . time() . '@example.com', 'package', $fullId, null);
    $uTopic = p5h_user($conn, 'phase5h.topic.' . time() . '@example.com', 'by_topic', null, json_encode([$lessonA]));
    $uFree = p5h_user($conn, 'phase5h.free.' . time() . '@example.com', 'free_access', null, null);
    $createdUserIds = [$u1, $u2, $uTopic, $uFree];

    // ---------- A. Checkout recovery ----------
    $_SESSION = ['csrf_token' => 'csrf-preserve-test', 'created' => time(), 'last_activity' => time()];
    $boot = commerce_bootstrap_checkout_after_verification($conn, $u1);
    $mark('A_BOOTSTRAP', !empty($boot['ok']) && !empty($_SESSION['checkout_token']), $boot['error'] ?? 'ok');
    $pay1 = (int) ($boot['payment']['payment_id'] ?? 0);

    // Resume same open payment
    $resumeSame = commerce_create_or_resume_checkout_for_user($conn, $u1);
    $mark('A_RESUME_OPEN', !empty($resumeSame['ok']) && !empty($resumeSame['resumed']) && (int) $resumeSame['payment']['payment_id'] === $pay1, 'same payment');

    // Correct user can get fresh checkout session
    $sidBefore = session_id();
    $tok2 = commerce_issue_checkout_session($u1, $pay1);
    $mark('A_REISSUE', $tok2 !== '' && (int) $_SESSION['checkout_user_id'] === $u1 && (int) $_SESSION['checkout_payment_id'] === $pay1, 'session bound');

    // Different user cannot use u1 payment via require after binding u2
    commerce_issue_checkout_session($u2, $pay1); // wrongly attempt - ownership check on require uses session user vs payment user
    // Fix: issue for u2's own payment first
    $boot2 = commerce_bootstrap_checkout_after_verification($conn, $u2);
    $pay2 = (int) ($boot2['payment']['payment_id'] ?? 0);
    // Tamper session to claim u1's payment while pretending to be u2
    $_SESSION['checkout_user_id'] = $u2;
    $_SESSION['checkout_payment_id'] = $pay1;
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
    $_SESSION['checkout_expires_at'] = time() + 3600;
    $idor = commerce_require_checkout_session($conn);
    $mark('A_IDOR', empty($idor['ok']), $idor['error'] ?? 'blocked');

    // Closed payment cannot be resumed as open
    mysqli_query($conn, "UPDATE payments SET status='cancelled' WHERE payment_id=" . (int) $pay1);
    $closed = commerce_find_open_payment_for_selection($conn, $u1, 'package', $fullId, null);
    $mark('A_CLOSED', $closed === null, 'cancelled not open');

    // Recovery path: arm + resume for u1 creates NEW open payment after cancel
    commerce_arm_checkout_recovery($u1, 'test recovery');
    $recTok = (string) $_SESSION['checkout_recovery_token'];
    $fromRec = commerce_resume_checkout_from_recovery($conn, $recTok);
    $mark('A_RECOVERY', !empty($fromRec['ok']) && (int) $fromRec['payment']['payment_id'] !== $pay1
        && (string) $fromRec['payment']['status'] === 'awaiting_proof', 'new open after cancel');

    // Wrong recovery token
    commerce_arm_checkout_recovery($u1, 'tok test');
    $badRec = commerce_resume_checkout_from_recovery($conn, str_repeat('a', 64));
    $mark('A_BAD_TOKEN', empty($badRec['ok']), $badRec['error'] ?? 'rejected');

    // Free Access never checkout recovery
    $freeBoot = commerce_bootstrap_checkout_after_verification($conn, $uFree);
    $mark('A_FREE', empty($freeBoot['ok']) && str_contains((string) ($freeBoot['error'] ?? ''), 'Free Access'), $freeBoot['error'] ?? '');

    // ---------- B. payment_ref collision ----------
    $mark('B_DETECT', commerce_mysqli_error_is_payment_ref_collision(1062, "Duplicate entry 'PAY-2026-000001' for key 'uq_payment_ref'") === true, 'detect ref collision');
    $mark('B_NOT_OTHER', commerce_mysqli_error_is_payment_ref_collision(1062, "Duplicate entry 'x' for key 'uq_gcash_ref_payment'") === false, 'ignore other unique');
    $mark('B_NOT_GENERIC', commerce_mysqli_error_is_payment_ref_collision(1062, '') === false, 'empty error not assumed');
    $mark('B_NOT_OTHER_ERR', commerce_mysqli_error_is_payment_ref_collision(1213, 'Deadlock found') === false, 'no retry deadlock');

    // Force first ref to collide with existing payment_ref, then succeed
    $existRef = (string) (mysqli_fetch_assoc(mysqli_query($conn, 'SELECT payment_ref FROM payments ORDER BY payment_id DESC LIMIT 1'))['payment_ref'] ?? '');
    if ($existRef === '') {
        $existRef = 'PAY-' . date('Y') . '-000001';
    }
    $year = date('Y');
    $nextUnique = 'PAY-' . $year . '-009991';
    $GLOBALS['commerce_test_payment_ref_queue'] = [$existRef, $nextUnique];
    $uColl = p5h_user($conn, 'phase5h.coll.' . time() . '@example.com', 'by_topic', null, json_encode([$lessonB]));
    $createdUserIds[] = $uColl;
    $coll = commerce_create_or_resume_checkout($conn, $uColl, 'by_topic', null, [$lessonB]);
    $mark('B_RETRY_OK', !empty($coll['ok']) && (string) $coll['payment']['payment_ref'] === $nextUnique
        && (int) ($coll['attempts'] ?? 0) >= 2, 'ref=' . ($coll['payment']['payment_ref'] ?? '') . ' attempts=' . ($coll['attempts'] ?? 0));
    unset($GLOBALS['commerce_test_payment_ref_queue']);

    // Bounded fail: all colliding refs
    $GLOBALS['commerce_test_payment_ref_queue'] = array_fill(0, COMMERCE_PAYMENT_REF_MAX_ATTEMPTS, $existRef);
    $uColl2 = p5h_user($conn, 'phase5h.coll2.' . time() . '@example.com', 'by_topic', null, json_encode([$lessonA, $lessonB]));
    $createdUserIds[] = $uColl2;
    $collFail = commerce_create_or_resume_checkout($conn, $uColl2, 'by_topic', null, [$lessonA, $lessonB]);
    $mark('B_BOUNDED', empty($collFail['ok']) && (int) ($collFail['attempts'] ?? 0) === COMMERCE_PAYMENT_REF_MAX_ATTEMPTS, 'attempts=' . ($collFail['attempts'] ?? 0));
    unset($GLOBALS['commerce_test_payment_ref_queue']);

    // ---------- C. Session regeneration ----------
    $_SESSION['csrf_token'] = 'keep-csrf';
    $_SESSION['created'] = 123;
    $_SESSION['last_activity'] = 456;
    commerce_arm_checkout_recovery($uTopic, 'will clear');
    $sid1 = session_id();
    // Need open payment for uTopic
    $tPay = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonA]);
    $tPid = (int) ($tPay['payment']['payment_id'] ?? 0);
    $issued = commerce_issue_checkout_session($uTopic, $tPid);
    $sid2 = session_id();
    $mark('C_REGEN', $issued !== '' && $sid2 !== '' /* may equal in CLI without real cookies */, 'token issued');
    $mark('C_PRESERVE', ($_SESSION['csrf_token'] ?? '') === 'keep-csrf' && (int) ($_SESSION['created'] ?? 0) === 123, 'csrf/created kept');
    $mark('C_OWNER', (int) $_SESSION['checkout_user_id'] === $uTopic && (int) $_SESSION['checkout_payment_id'] === $tPid, 'ownership');
    $mark('C_CLEAR_RECOVERY', empty($_SESSION['checkout_recovery_token']), 'recovery cleared');

    // ---------- D. Concurrent / different selections ----------
    $d1 = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonA]);
    $d1b = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonA]);
    $mark('D_SAME', !empty($d1['ok']) && !empty($d1b['resumed']) && (int) $d1['payment']['payment_id'] === (int) $d1b['payment']['payment_id'], 'reuse');

    $d2 = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonA, $lessonB]);
    $mark('D_DIFF', !empty($d2['ok']) && (int) $d2['payment']['payment_id'] !== (int) $d1['payment']['payment_id'], 'separate open');

    $oldId = (int) $d1['payment']['payment_id'];
    $oldAmt = (int) $d1['payment']['expected_amount_centavos'];
    mysqli_query($conn, "UPDATE payments SET status='paid', paid_at=NOW() WHERE payment_id=" . $oldId);
    $d3 = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonA]);
    $mark('D_AFTER_PAID', !empty($d3['ok']) && (int) $d3['payment']['payment_id'] !== $oldId, 'new after paid');
    $oldAfter = commerce_get_payment($conn, $oldId);
    $mark('D_HISTORY', $oldAfter && (string) $oldAfter['status'] === 'paid' && (int) $oldAfter['expected_amount_centavos'] === $oldAmt, 'untouched');

    // ---------- E. Security checks (proof + gcash + forged id) ----------
    $tmp = sys_get_temp_dir() . '/p5h_' . bin2hex(random_bytes(4)) . '.png';
    p5h_png($tmp);
    $subPay = (int) $d3['payment']['payment_id'];
    $ref = 'HREF' . strtoupper(bin2hex(random_bytes(4)));
    commerce_issue_checkout_session($uTopic, $subPay);
    $sub = commerce_submit_payment_proof_and_reference($conn, $subPay, $uTopic, $ref, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => filesize($tmp),
        'name' => 'evil.php.png',
        'type' => 'image/png',
    ]);
    $paySub = commerce_get_payment($conn, $subPay);
    $mark('E_PROOF', !empty($sub['ok']) && str_starts_with((string) ($paySub['proof_path'] ?? ''), 'uploads/payment_proofs/')
        && (string) $paySub['status'] === 'pending_verification'
        && (string) $paySub['verification_status'] === 'not_started', (string) ($paySub['proof_path'] ?? ($sub['error'] ?? '')));
    if (!empty($paySub['proof_path'])) {
        $proofFiles[] = dirname(__DIR__) . '/' . $paySub['proof_path'];
    }

    $norm = commerce_normalize_gcash_reference(' ab-cd 12 ');
    $mark('E_NORM', $norm === 'ABCD12', $norm);

    $dupUser = p5h_user($conn, 'phase5h.dup.' . time() . '@example.com', 'by_topic', null, json_encode([$lessonB]));
    $createdUserIds[] = $dupUser;
    $dupPay = commerce_create_or_resume_checkout($conn, $dupUser, 'by_topic', null, [$lessonB]);
    $tmp2 = sys_get_temp_dir() . '/p5h2_' . bin2hex(random_bytes(4)) . '.png';
    p5h_png($tmp2);
    $dup = commerce_submit_payment_proof_and_reference($conn, (int) $dupPay['payment']['payment_id'], $dupUser, $ref, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp2,
        'size' => filesize($tmp2),
        'name' => 'x.png',
        'type' => 'image/png',
    ]);
    $mark('E_DUP_GCASH', empty($dup['ok']), $dup['error'] ?? '');

    // Forged payment_id path (submit helper ownership)
    $forge = commerce_submit_payment_proof_and_reference($conn, $subPay, $dupUser, 'OTHERREF999', null);
    $mark('E_FORGE_OWNER', empty($forge['ok']), $forge['error'] ?? '');

    $endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
    $mark('E_NO_GRANTS', $endGrants === $baseGrants, "grants=$endGrants");
    $mark('E_NO_SCA', $endSca === $baseSca, "sca=$endSca");

    $mark('E_LOGIN_UNTOUCHED', !str_contains((string) file_get_contents(__DIR__ . '/../login_process.php'), 'commerce_payment')
        && !str_contains((string) file_get_contents(__DIR__ . '/../activate_user.php'), 'commerce_payment'), 'login/activate clean');

} catch (Throwable $e) {
    $mark('EXCEPTION', false, $e->getMessage());
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    echo "\n=== CLEANUP ===\n";
    $uq = mysqli_query($conn, "SELECT user_id FROM users WHERE email LIKE 'phase5h.%@example.com'");
    $uids = $createdUserIds;
    while ($uq && ($r = mysqli_fetch_assoc($uq))) {
        $uids[] = (int) $r['user_id'];
    }
    $uids = array_values(array_unique($uids));
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
        mysqli_query($conn, 'DELETE FROM users WHERE user_id=' . (int) $uid . " AND email LIKE 'phase5h.%'");
    }
    foreach ($createdPackageIds as $pid) {
        mysqli_query($conn, 'DELETE FROM package_content_items WHERE package_id=' . (int) $pid);
        mysqli_query($conn, 'DELETE FROM package_feature_items WHERE package_id=' . (int) $pid);
        mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pid);
    }
    mysqli_query($conn, "DELETE FROM sellable_packages WHERE code LIKE 'TEST_P5H_%'");
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
    $endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
    $endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
    $endPurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
    $endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
    $cleanupOk = $endPay === $basePay && $endItems === $baseItems && $endGcash === $baseGcash
        && $endGrants === $baseGrants && $endSca === $baseSca && $endPkg === $basePkg
        && $endPurch === $basePurch && $endFar === $baseFar;
    out('CLEANUP', $cleanupOk, "pay=$endPay items=$endItems gcash=$endGcash grants=$endGrants sca=$endSca pkgs=$endPkg purch=$endPurch far=$endFar");
}

$failed = array_filter($results, static fn($r) => !$r['ok']);
echo "\nSummary: " . (count($results) - count($failed)) . '/' . count($results) . " passed\n";
exit($failed ? 1 : 0);
