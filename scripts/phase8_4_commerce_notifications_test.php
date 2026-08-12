<?php
/**
 * Phase 8.4 - Commerce student notifications acceptance tests (A-AA), reversible.
 * Does not exercise Phase 8.5. Uses COMMERCE_NOTIFY_TEST_MODE (no real SMTP).
 */
declare(strict_types=1);

define('COMMERCE_NOTIFY_TEST_MODE', true);
define('COMMERCE_PAYMENT_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_fulfillment.php';
require_once __DIR__ . '/../includes/commerce_free_access.php';
require_once __DIR__ . '/../includes/commerce_notifications.php';
require_once __DIR__ . '/../includes/student_content_access.php';
require_once __DIR__ . '/../includes/commerce_catalog.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " - $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 8.4 commerce notifications tests ===\n";

if (!commerce_schema_ready($conn)) {
    fwrite(STDERR, "Commerce schema not ready.\n");
    exit(1);
}

$basePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$baseItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$baseAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$baseGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$baseFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$baseLessons = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons'))[0] ?? 0);

echo "BEFORE pay=$basePay items=$baseItems attempts=$baseAttempts gcash=$baseGcash grants=$baseGrants sca=$baseSca far=$baseFar pkgs=$basePkg lessons=$baseLessons\n";

$createdUserIds = [];
$createdPaymentIds = [];
$createdFarIds = [];
$createdGrantIds = [];
$ts = (string) time();

$GLOBALS['commerce_test_notify_result'] = ['ok' => true];
$GLOBALS['commerce_test_notify_log'] = [];

function p84_reset_log(): void
{
    $GLOBALS['commerce_test_notify_log'] = [];
}

function p84_log_count(): int
{
    return count($GLOBALS['commerce_test_notify_log'] ?? []);
}

/** @return ?array{to?:string,subject?:string,html?:string,plain?:string} */
function p84_last_mail(): ?array
{
    $log = $GLOBALS['commerce_test_notify_log'] ?? [];
    if ($log === []) {
        return null;
    }
    return $log[count($log) - 1];
}

function p84_user(mysqli $conn, string $email, string $status = 'pending'): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase84 Student';
    $school = 'Test School';
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
        throw new RuntimeException('user: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

/** @return array{payment_id:int,payment_item_id:int} */
function p84_paid_ready(mysqli $conn, int $userId, string $ref, bool $fulfilled = false): array
{
    $fulfilledSql = $fulfilled ? 'NOW()' : 'NULL';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO payments
          (payment_ref, user_id, purchase_type, expected_amount_centavos, status, verification_status, paid_at, fulfilled_at)
         VALUES (?, ?, 'package', 10000, 'paid', 'auto_verified', NOW(), {$fulfilledSql})"
    );
    mysqli_stmt_bind_param($stmt, 'si', $ref, $userId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('payment: ' . mysqli_error($conn));
    }
    $pid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $ins = mysqli_prepare(
        $conn,
        "INSERT INTO payment_items
          (payment_id, line_no, item_type, item_name, unit_amount_centavos, quantity, line_total_centavos,
           duration_value, duration_unit, package_access_scope)
         VALUES (?, 1, 'package', 'P84 Full', 10000, 1, 10000, 6, 'month', 'full_lms')"
    );
    mysqli_stmt_bind_param($ins, 'i', $pid);
    if (!mysqli_stmt_execute($ins)) {
        throw new RuntimeException('item: ' . mysqli_error($conn));
    }
    $iid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    return ['payment_id' => $pid, 'payment_item_id' => $iid];
}

/** @return array{payment_id:int} */
function p84_needs_review(mysqli $conn, int $userId, string $ref): array
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO payments
          (payment_ref, user_id, purchase_type, expected_amount_centavos, status, verification_status)
         VALUES (?, ?, 'package', 10000, 'pending_verification', 'needs_review')"
    );
    mysqli_stmt_bind_param($stmt, 'si', $ref, $userId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('needs_review pay: ' . mysqli_error($conn));
    }
    $pid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['payment_id' => $pid];
}

function p84_far(mysqli $conn, int $userId, string $ref): int
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO free_access_requests (request_ref, user_id, status, student_note) VALUES (?, ?, 'pending', 'please')"
    );
    mysqli_stmt_bind_param($stmt, 'si', $ref, $userId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('far: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p84_email_stamp(mysqli $conn, int $paymentId): ?string
{
    $r = mysqli_query($conn, 'SELECT fulfillment_email_sent_at FROM payments WHERE payment_id=' . (int) $paymentId . ' LIMIT 1');
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $v = $row['fulfillment_email_sent_at'] ?? null;
    return ($v === null || $v === '') ? null : (string) $v;
}

function p84_fulfilled(mysqli $conn, int $paymentId): bool
{
    $r = mysqli_query($conn, 'SELECT fulfilled_at FROM payments WHERE payment_id=' . (int) $paymentId . ' LIMIT 1');
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return !empty($row['fulfilled_at']);
}

function p84_user_status(mysqli $conn, int $userId): string
{
    $r = mysqli_query($conn, 'SELECT status FROM users WHERE user_id=' . (int) $userId . ' LIMIT 1');
    return (string) (mysqli_fetch_row($r)[0] ?? '');
}

function p84_grant_count(mysqli $conn, int $userId): int
{
    $r = mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE user_id=' . (int) $userId);
    return (int) (mysqli_fetch_row($r)[0] ?? 0);
}

function p84_body_has_sensitive(?array $mail): bool
{
    if ($mail === null) {
        return false;
    }
    $blob = strtolower(($mail['html'] ?? '') . "\n" . ($mail['plain'] ?? '') . "\n" . ($mail['subject'] ?? ''));
    $needles = [
        'smtp_password',
        'crcn',
        'ocr',
        'confidence',
        'proof_path',
        'tesseract',
        'admin_note',
        'review_note',
        'payment_id=',
        'grant_id=',
        'mysqli',
        'stack trace',
    ];
    foreach ($needles as $n) {
        if (strpos($blob, $n) !== false) {
            return true;
        }
    }
    return false;
}

try {
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        $hash = password_hash('AdminPass1!', PASSWORD_DEFAULT);
        mysqli_query(
            $conn,
            "INSERT INTO users (full_name, email, password, role, status, email_verified, review_type, school, payment_proof)
             VALUES ('Phase84 Admin', 'phase84.admin.{$ts}@example.com', '$hash', 'admin', 'approved', 1, 'reviewee', 'Test', '')"
        );
        $adminId = (int) mysqli_insert_id($conn);
        $createdUserIds[] = $adminId;
    }

    // ---------- A/B fulfillment email + stamp ----------
    $uA = p84_user($conn, "p84.a.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uA;
    $payA = p84_paid_ready($conn, $uA, "PAY-P84-A-{$ts}", false);
    $createdPaymentIds[] = $payA['payment_id'];
    p84_reset_log();
    $GLOBALS['commerce_test_notify_result'] = ['ok' => true];
    $fA = commerce_fulfill_payment($conn, $payA['payment_id'], ['granted_by' => $adminId]);
    $stampA = p84_email_stamp($conn, $payA['payment_id']);
    $mailA = p84_last_mail();
    $mark(
        'A',
        !empty($fA['ok']) && p84_log_count() === 1 && !empty($fA['notify']['sent'])
            && $mailA && stripos((string) ($mailA['subject'] ?? ''), 'fulfilled') !== false,
        'sent=' . p84_log_count() . ' fulfill_ok=' . (!empty($fA['ok']) ? '1' : '0')
    );
    $mark('B', $stampA !== null && p84_fulfilled($conn, $payA['payment_id']), 'stamp=' . ($stampA ?? 'NULL'));

    // ---------- C/D SMTP failure does not rollback; stamp stays NULL ----------
    $uC = p84_user($conn, "p84.c.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uC;
    $payC = p84_paid_ready($conn, $uC, "PAY-P84-C-{$ts}", false);
    $createdPaymentIds[] = $payC['payment_id'];
    p84_reset_log();
    $GLOBALS['commerce_test_notify_result'] = ['ok' => false, 'error' => 'smtp_test_fail'];
    $fC = commerce_fulfill_payment($conn, $payC['payment_id'], ['granted_by' => $adminId]);
    $stampC = p84_email_stamp($conn, $payC['payment_id']);
    $grantsC = p84_grant_count($conn, $uC);
    $mark(
        'C',
        !empty($fC['ok']) && p84_fulfilled($conn, $payC['payment_id']) && $grantsC >= 1,
        'fulfilled+grants despite SMTP fail grants=' . $grantsC
    );
    $mark('D', $stampC === null && empty($fC['notify']['sent']), 'stamp NULL after SMTP fail');

    // ---------- E/F/G pending fulfillment retry ----------
    $GLOBALS['commerce_test_notify_result'] = ['ok' => true];
    p84_reset_log();
    $beforeRetryGrants = $grantsC;
    $retry1 = commerce_notify_send_pending_fulfillment_emails($conn, 50);
    $stampC2 = p84_email_stamp($conn, $payC['payment_id']);
    $afterRetryGrants = p84_grant_count($conn, $uC);
    $mark(
        'E',
        !empty($retry1['ok']) && (int) ($retry1['sent'] ?? 0) >= 1 && $stampC2 !== null,
        'retry sent=' . ($retry1['sent'] ?? 0) . ' stamp=' . ($stampC2 ?? 'NULL')
    );
    $mark('F', $afterRetryGrants === $beforeRetryGrants, "grants {$beforeRetryGrants}->{$afterRetryGrants}");

    p84_reset_log();
    $retry2 = commerce_notify_send_pending_fulfillment_emails($conn, 50);
    // payC already stamped; should not appear again in sent for that id
    $mark(
        'G',
        p84_log_count() === 0 || (int) ($retry2['sent'] ?? 0) === 0,
        'second retry sent=' . ($retry2['sent'] ?? 0) . ' log=' . p84_log_count()
    );
    // Also: fulfill again must skip and not resend
    p84_reset_log();
    $fAgain = commerce_fulfill_payment($conn, $payC['payment_id']);
    $mark(
        'G2',
        !empty($fAgain['ok']) && !empty($fAgain['skipped']) && p84_log_count() === 0,
        'idempotent fulfill skip no email'
    );

    // ---------- H/I payment rejection once ----------
    $uH = p84_user($conn, "p84.h.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uH;
    $payH = p84_needs_review($conn, $uH, "PAY-P84-H-{$ts}");
    $createdPaymentIds[] = $payH['payment_id'];
    p84_reset_log();
    $GLOBALS['commerce_test_notify_result'] = ['ok' => true];
    $rejH = commerce_manual_reject_payment($conn, $payH['payment_id'], $adminId, 'internal admin note OCR 99');
    $mark(
        'H',
        !empty($rejH['ok']) && p84_log_count() === 1 && !empty($rejH['notify']['sent']),
        'reject notify log=' . p84_log_count()
    );
    p84_reset_log();
    $rejH2 = commerce_manual_reject_payment($conn, $payH['payment_id'], $adminId, 'again');
    $mark(
        'I',
        empty($rejH2['ok']) && p84_log_count() === 0,
        'repeat reject no email err=' . ($rejH2['error'] ?? '')
    );

    // ---------- J/K FAR approval once ----------
    $uJ = p84_user($conn, "p84.j.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uJ;
    $farJ = p84_far($conn, $uJ, "FAR-P84-J-{$ts}");
    $createdFarIds[] = $farJ;
    p84_reset_log();
    $apJ = commerce_far_approve($conn, $farJ, $adminId, 3, 'secret admin note');
    if (!empty($apJ['grant']['grant_id'])) {
        $createdGrantIds[] = (int) $apJ['grant']['grant_id'];
    }
    $mark(
        'J',
        !empty($apJ['ok']) && empty($apJ['skipped']) && p84_log_count() === 1 && !empty($apJ['notify']['sent']),
        'far approve email'
    );
    p84_reset_log();
    $apJ2 = commerce_far_approve($conn, $farJ, $adminId, 9, 'retry');
    $mark(
        'K',
        !empty($apJ2['ok']) && !empty($apJ2['skipped']) && p84_log_count() === 0,
        'far approve skip no email'
    );

    // ---------- L/M FAR rejection once ----------
    $uL = p84_user($conn, "p84.l.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uL;
    $farL = p84_far($conn, $uL, "FAR-P84-L-{$ts}");
    $createdFarIds[] = $farL;
    p84_reset_log();
    $rjL = commerce_far_reject($conn, $farL, $adminId, 'internal reject reason');
    $mark(
        'L',
        !empty($rjL['ok']) && empty($rjL['skipped']) && p84_log_count() === 1,
        'far reject email'
    );
    p84_reset_log();
    $rjL2 = commerce_far_reject($conn, $farL, $adminId, 'again');
    $mark(
        'M',
        !empty($rjL2['ok']) && !empty($rjL2['skipped']) && p84_log_count() === 0,
        'far reject skip no email'
    );

    // ---------- N FAR approval auto-activates login ----------
    $mark('N', p84_user_status($conn, $uJ) === 'approved', 'status=' . p84_user_status($conn, $uJ));

    // ---------- O pending wording (fallback when login still pending after fulfill) ----------
    p84_reset_log();
    $GLOBALS['commerce_test_notify_result'] = ['ok' => true];
    // Simulate activation failure: force pending, clear stamp, re-notify
    mysqli_query($conn, 'UPDATE users SET status=\'pending\' WHERE user_id=' . (int) $uA);
    mysqli_query($conn, 'UPDATE payments SET fulfillment_email_sent_at = NULL WHERE payment_id=' . (int) $payA['payment_id']);
    $nO = commerce_notify_payment_fulfilled($conn, $payA['payment_id']);
    $mailO = p84_last_mail();
    $pendingWording = $mailO
        && (
            stripos((string) ($mailO['plain'] ?? ''), 'pending admin activation') !== false
            || stripos((string) ($mailO['html'] ?? ''), 'pending admin activation') !== false
        )
        && stripos((string) ($mailO['plain'] ?? ''), 'You may sign in') === false;
    $mark('O', !empty($nO['ok']) && $pendingWording, 'pending wording');
    // Restore approved after fallback wording check
    mysqli_query($conn, 'UPDATE users SET status=\'approved\' WHERE user_id=' . (int) $uA);

    // ---------- P approved wording ----------
    $uP = p84_user($conn, "p84.p.{$ts}@example.com", 'approved');
    $createdUserIds[] = $uP;
    $payP = p84_paid_ready($conn, $uP, "PAY-P84-P-{$ts}", false);
    $createdPaymentIds[] = $payP['payment_id'];
    p84_reset_log();
    $fP = commerce_fulfill_payment($conn, $payP['payment_id'], ['granted_by' => $adminId]);
    $mailP = p84_last_mail();
    $approvedWording = $mailP
        && (
            stripos((string) ($mailP['plain'] ?? ''), 'You may sign in') !== false
            || stripos((string) ($mailP['html'] ?? ''), 'You may sign in') !== false
        );
    $mark('P', !empty($fP['ok']) && $approvedWording, 'approved wording');

    // ---------- Q invalid SMTP config fails gracefully ----------
    $uQ = p84_user($conn, "p84.q.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uQ;
    $payQ = p84_paid_ready($conn, $uQ, "PAY-P84-Q-{$ts}", false);
    $createdPaymentIds[] = $payQ['payment_id'];
    p84_reset_log();
    $GLOBALS['commerce_test_mail_config'] = null;
    $fQ = commerce_fulfill_payment($conn, $payQ['payment_id'], ['granted_by' => $adminId]);
    unset($GLOBALS['commerce_test_mail_config']);
    $stampQ = p84_email_stamp($conn, $payQ['payment_id']);
    $mark(
        'Q',
        !empty($fQ['ok']) && p84_fulfilled($conn, $payQ['payment_id']) && $stampQ === null
            && empty($fQ['notify']['sent']) && ($fQ['notify']['error'] ?? '') === 'mail_config_invalid',
        'invalid config graceful err=' . ($fQ['notify']['error'] ?? '')
    );

    // ---------- R no sensitive information ----------
    $sensitive = false;
    foreach (($GLOBALS['commerce_test_notify_log'] ?? []) as $m) {
        if (p84_body_has_sensitive($m)) {
            $sensitive = true;
            break;
        }
    }
    // Also check H/J rejection/approval bodies specifically (re-fetch from earlier logs already cleared - rebuild samples)
    p84_reset_log();
    $GLOBALS['commerce_test_notify_result'] = ['ok' => true];
    commerce_notify_payment_rejected($conn, $payH['payment_id']);
    commerce_notify_far_approved($conn, $farJ, 3);
    commerce_notify_far_rejected($conn, $farL);
    foreach ($GLOBALS['commerce_test_notify_log'] as $m) {
        if (p84_body_has_sensitive($m)) {
            $sensitive = true;
        }
        // Admin-only notes must not leak
        if (stripos((string) ($m['plain'] ?? ''), 'internal') !== false
            || stripos((string) ($m['html'] ?? ''), 'secret admin') !== false
            || stripos((string) ($m['plain'] ?? ''), 'OCR') !== false) {
            $sensitive = true;
        }
    }
    $mark('R', !$sensitive, $sensitive ? 'leak detected' : 'clean');

    // ---------- S email failure never changes access/payment state ----------
    $uS = p84_user($conn, "p84.s.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uS;
    $payS = p84_paid_ready($conn, $uS, "PAY-P84-S-{$ts}", false);
    $createdPaymentIds[] = $payS['payment_id'];
    p84_reset_log();
    $GLOBALS['commerce_test_notify_result'] = ['ok' => false, 'error' => 'smtp_test_fail'];
    $fS = commerce_fulfill_payment($conn, $payS['payment_id'], ['granted_by' => $adminId]);
    $paySrow = commerce_get_payment($conn, $payS['payment_id']);
    $mark(
        'S',
        !empty($fS['ok'])
            && (string) ($paySrow['status'] ?? '') === 'paid'
            && !empty($paySrow['fulfilled_at'])
            && empty($paySrow['fulfillment_email_sent_at'])
            && p84_grant_count($conn, $uS) >= 1,
        'commerce intact after notify fail'
    );
    $GLOBALS['commerce_test_notify_result'] = ['ok' => true];

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup local rows before regression suites
if ($createdGrantIds !== []) {
    $ids = implode(',', array_unique(array_map('intval', $createdGrantIds)));
    mysqli_query($conn, "DELETE FROM access_grants WHERE grant_id IN ($ids)");
}
if ($createdFarIds !== []) {
    $ids = implode(',', array_map('intval', $createdFarIds));
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE request_id IN ($ids)");
}
if ($createdPaymentIds !== []) {
    $ids = implode(',', array_map('intval', $createdPaymentIds));
    mysqli_query($conn, "DELETE FROM payment_verification_attempts WHERE payment_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM payment_gcash_references WHERE payment_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM access_grants WHERE payment_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM payments WHERE payment_id IN ($ids)");
}
if ($createdUserIds !== []) {
    $ids = implode(',', array_map('intval', $createdUserIds));
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($ids)");
}

echo "After local cleanup...\n";

putenv('COMMERCE_SKIP_NESTED_REGRESSIONS=1');

$php = 'C:\\xampp\\php\\php.exe';
$runReg = static function (string $label, string $script) use ($php, $mark): void {
    // Ensure nested suites skip their own nested regressions (Windows-safe env).
    $cmd = 'set COMMERCE_SKIP_NESTED_REGRESSIONS=1&& "' . $php . '" ' . escapeshellarg($script);
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $text = implode("\n", $output);
    $hasFail = (bool) preg_match('/\[FAIL\]/', $text);
    $ok = ($code === 0) && !$hasFail;
    $mark($label, $ok, 'exit=' . $code . ($hasFail ? ' has FAIL' : ' clean'));
    echo "--- $label tail ---\n" . implode("\n", array_slice($output, -6)) . "\n";
};

$runReg('T', __DIR__ . '/phase8_1_free_access_test.php');
$runReg('U', __DIR__ . '/phase8_1_idempotency_hardening_test.php');
$runReg('V', __DIR__ . '/phase8_2_expiry_reconcile_test.php');
$runReg('W', __DIR__ . '/phase8_3_paid_revoke_test.php');
$runReg('X', __DIR__ . '/phase7_fulfillment_test.php');
$runReg('Y', __DIR__ . '/activation_commerce_sca_hardening_test.php');
$runReg('Z', __DIR__ . '/student_access_commerce_sca_hardening_test.php');

$endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$endAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$endGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
$endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$endLessons = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons'))[0] ?? 0);

echo "AFTER pay=$endPay items=$endItems attempts=$endAttempts gcash=$endGcash grants=$endGrants sca=$endSca far=$endFar pkgs=$endPkg lessons=$endLessons\n";

$cleanupOk = $endPay === $basePay
    && $endItems === $baseItems
    && $endAttempts === $baseAttempts
    && $endGcash === $baseGcash
    && $endGrants === $baseGrants
    && $endSca === $baseSca
    && $endFar === $baseFar
    && $endPkg === $basePkg
    && $endLessons === $baseLessons;
$mark(
    'AA',
    $cleanupOk,
    "baseline restored pay {$basePay}->{$endPay} grants {$baseGrants}->{$endGrants} sca {$baseSca}->{$endSca}"
);

$failed = 0;
foreach ($results as $k => $r) {
    if (empty($r['ok'])) {
        $failed++;
    }
}
echo "=== Phase 8.4 summary: " . (count($results) - $failed) . ' passed, ' . $failed . " failed ===\n";
exit($failed > 0 ? 1 : 0);
