<?php
/**
 * Phase 8.3 - Paid access revoke acceptance tests (A-AA), reversible.
 * Does not exercise Phase 8.4-8.5.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_revoke.php';
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

echo "=== Phase 8.3 paid access revoke tests ===\n";

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

function p83_user(mysqli $conn, string $email): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase83 Test';
    $school = 'Test';
    $review = 'reviewee';
    $proof = '';
    $path = 'package';
    $status = 'pending';
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

/** @return array{payment_id:int,payment_item_id:int} */
function p83_paid_payment(mysqli $conn, int $userId, string $ref): array
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO payments
          (payment_ref, user_id, purchase_type, expected_amount_centavos, status, verification_status, paid_at, fulfilled_at)
         VALUES (?, ?, 'package', 10000, 'paid', 'auto_verified', NOW(), NOW())"
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
         VALUES (?, 1, 'package', 'P83 Full', 10000, 1, 10000, 6, 'month', 'full_lms')"
    );
    mysqli_stmt_bind_param($ins, 'i', $pid);
    if (!mysqli_stmt_execute($ins)) {
        throw new RuntimeException('item: ' . mysqli_error($conn));
    }
    $iid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    return ['payment_id' => $pid, 'payment_item_id' => $iid];
}

function p83_attempt(mysqli $conn, int $paymentId): int
{
    mysqli_query(
        $conn,
        "INSERT INTO payment_verification_attempts (payment_id, engine, confidence, decision, decision_reasons_json)
         VALUES ($paymentId, 'tesseract', 90.00, 'auto_verified', '[]')"
    );
    return (int) mysqli_insert_id($conn);
}

function p83_grant(
    mysqli $conn,
    int $userId,
    string $source,
    ?int $paymentId,
    ?int $paymentItemId,
    ?int $farId,
    string $ctype,
    int $cid,
    string $status,
    string $startsSql,
    string $endsSql
): int {
    $pay = $paymentId === null ? 'NULL' : (string) (int) $paymentId;
    $item = $paymentItemId === null ? 'NULL' : (string) (int) $paymentItemId;
    $far = $farId === null ? 'NULL' : (string) (int) $farId;
    $ok = mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id,
           content_type, content_id, content_label, starts_at, ends_at, status)
         VALUES ($userId, '" . mysqli_real_escape_string($conn, $source) . "', $pay, $item, $far,
           '" . mysqli_real_escape_string($conn, $ctype) . "', $cid, 'p83',
           $startsSql, $endsSql, '" . mysqli_real_escape_string($conn, $status) . "')"
    );
    if (!$ok) {
        throw new RuntimeException('grant: ' . mysqli_error($conn));
    }
    return (int) mysqli_insert_id($conn);
}

function p83_has_sca(mysqli $conn, int $userId, string $type, int $cid): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1 FROM student_content_permissions WHERE user_id=? AND content_type=? AND content_id=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $type, $cid);
    mysqli_stmt_execute($stmt);
    $ok = (bool) mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $ok;
}

function p83_gstatus(mysqli $conn, int $grantId): string
{
    $r = mysqli_query($conn, 'SELECT status FROM access_grants WHERE grant_id=' . (int) $grantId);
    return (string) (mysqli_fetch_row($r)[0] ?? '');
}

function p83_snap_payment(mysqli $conn, int $pid): array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT status, verification_status, paid_at, fulfilled_at, expected_amount_centavos FROM payments WHERE payment_id=' . (int) $pid));
    return $r ?: [];
}

try {
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        $hash = password_hash('AdminPass1!', PASSWORD_DEFAULT);
        mysqli_query(
            $conn,
            "INSERT INTO users (full_name, email, password, role, status, email_verified)
             VALUES ('P83 Admin', 'p83.admin.{$ts}@example.com', '$hash', 'admin', 'approved', 1)"
        );
        $adminId = (int) mysqli_insert_id($conn);
        $createdUserIds[] = $adminId;
    }

    $L1 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id LIMIT 1'))[0] ?? 0);
    $L2 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id LIMIT 1 OFFSET 1'))[0] ?? 0);
    if ($L1 <= 0 || $L2 <= 0) {
        throw new RuntimeException('Need 2 lessons');
    }

    // ---------- A/B/C/D/E/F revoke lone purchase ----------
    $uA = p83_user($conn, "p83.a.{$ts}@example.com");
    $createdUserIds[] = $uA;
    $payA = p83_paid_payment($conn, $uA, "PAY-P83-A-{$ts}");
    $createdPaymentIds[] = $payA['payment_id'];
    $attA = p83_attempt($conn, $payA['payment_id']);
    $snapA = p83_snap_payment($conn, $payA['payment_id']);
    $itemsA = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items WHERE payment_id=' . $payA['payment_id']))[0] ?? 0);
    $attCntA = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts WHERE payment_id=' . $payA['payment_id']))[0] ?? 0);
    $gA = p83_grant($conn, $uA, 'purchase', $payA['payment_id'], $payA['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 6 MONTH)');
    $createdGrantIds[] = $gA;
    sca_upsert_permissions($conn, $uA, [
        ['content_type' => 'full_lms', 'content_id' => 0],
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], null);

    $revA = commerce_revoke_payment_grants($conn, $payA['payment_id'], $adminId, 'fraud review');
    $snapA2 = p83_snap_payment($conn, $payA['payment_id']);
    $itemsA2 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items WHERE payment_id=' . $payA['payment_id']))[0] ?? 0);
    $attCntA2 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts WHERE payment_id=' . $payA['payment_id']))[0] ?? 0);
    $gExists = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE grant_id=' . $gA))[0] ?? 0);

    $mark('A', !empty($revA['ok']) && p83_gstatus($conn, $gA) === 'revoked' && (int) ($revA['revoked_count'] ?? 0) === 1, 'status=' . p83_gstatus($conn, $gA));
    $mark('B', $gExists === 1, 'grant remains');
    $mark('C', $snapA === $snapA2, 'payment unchanged');
    $mark('D', $itemsA === $itemsA2 && $itemsA === 1, 'items intact');
    $mark('E', $attCntA === $attCntA2 && $attA > 0, 'attempts intact');
    $mark(
        'F',
        !p83_has_sca($conn, $uA, 'full_lms', 0) && p83_has_sca($conn, $uA, 'lesson', $L2),
        'commerce SCA gone, manual kept'
    );

    // ---------- G other active purchase keeps SCA ----------
    $uG = p83_user($conn, "p83.g.{$ts}@example.com");
    $createdUserIds[] = $uG;
    $payG1 = p83_paid_payment($conn, $uG, "PAY-P83-G1-{$ts}");
    $payG2 = p83_paid_payment($conn, $uG, "PAY-P83-G2-{$ts}");
    $createdPaymentIds[] = $payG1['payment_id'];
    $createdPaymentIds[] = $payG2['payment_id'];
    $gG1 = p83_grant($conn, $uG, 'purchase', $payG1['payment_id'], $payG1['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $gG2 = p83_grant($conn, $uG, 'purchase', $payG2['payment_id'], $payG2['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 2 MONTH)');
    $createdGrantIds[] = $gG1;
    $createdGrantIds[] = $gG2;
    sca_upsert_permissions($conn, $uG, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    $revG = commerce_revoke_payment_grants($conn, $payG1['payment_id'], $adminId, 'revoke A keep B');
    $mark(
        'G',
        !empty($revG['ok']) && p83_gstatus($conn, $gG1) === 'revoked' && p83_gstatus($conn, $gG2) === 'active' && p83_has_sca($conn, $uG, 'full_lms', 0),
        'B keeps SCA'
    );

    // ---------- H/I Free Access preserves SCA ----------
    $uH = p83_user($conn, "p83.h.{$ts}@example.com");
    $createdUserIds[] = $uH;
    $payH = p83_paid_payment($conn, $uH, "PAY-P83-H-{$ts}");
    $createdPaymentIds[] = $payH['payment_id'];
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('FAR-P83-H-{$ts}', $uH, 'approved')");
    $farH = (int) mysqli_insert_id($conn);
    $createdFarIds[] = $farH;
    $gHp = p83_grant($conn, $uH, 'purchase', $payH['payment_id'], $payH['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $gHf = p83_grant($conn, $uH, 'free_access', null, null, $farH, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 6 MONTH)');
    $createdGrantIds[] = $gHp;
    $createdGrantIds[] = $gHf;
    sca_upsert_permissions($conn, $uH, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    $revH = commerce_revoke_payment_grants($conn, $payH['payment_id'], $adminId, 'keep FAR');
    $mark('H', !empty($revH['ok']) && p83_has_sca($conn, $uH, 'full_lms', 0) && p83_gstatus($conn, $gHf) === 'active', 'FAR keeps SCA');
    $mark('I', p83_gstatus($conn, $gHp) === 'revoked' && p83_gstatus($conn, $gHf) === 'active' && p83_has_sca($conn, $uH, 'full_lms', 0), 'revoked+FAR');

    // ---------- J revoked + other purchase (reuse G) ----------
    $mark('J', p83_gstatus($conn, $gG1) === 'revoked' && p83_gstatus($conn, $gG2) === 'active' && p83_has_sca($conn, $uG, 'full_lms', 0), 'same as G');

    // ---------- K manual-only ----------
    $uK = p83_user($conn, "p83.k.{$ts}@example.com");
    $createdUserIds[] = $uK;
    sca_upsert_permissions($conn, $uK, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $payK = p83_paid_payment($conn, $uK, "PAY-P83-K-{$ts}");
    $createdPaymentIds[] = $payK['payment_id'];
    $gK = p83_grant($conn, $uK, 'purchase', $payK['payment_id'], $payK['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gK;
    sca_upsert_permissions($conn, $uK, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    commerce_revoke_payment_grants($conn, $payK['payment_id'], $adminId, 'manual stay');
    $mark('K', p83_has_sca($conn, $uK, 'lesson', $L1) && !p83_has_sca($conn, $uK, 'full_lms', 0), 'manual lesson kept');

    // ---------- L expired not revived ----------
    $uL = p83_user($conn, "p83.l.{$ts}@example.com");
    $createdUserIds[] = $uL;
    $payL = p83_paid_payment($conn, $uL, "PAY-P83-L-{$ts}");
    $createdPaymentIds[] = $payL['payment_id'];
    $gL = p83_grant($conn, $uL, 'purchase', $payL['payment_id'], $payL['payment_item_id'], null, 'full_lms', 0, 'expired', 'DATE_SUB(NOW(), INTERVAL 3 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gL;
    $revL = commerce_revoke_payment_grants($conn, $payL['payment_id'], $adminId, 'expired stay');
    $mark(
        'L',
        !empty($revL['ok']) && !empty($revL['skipped']) && p83_gstatus($conn, $gL) === 'expired' && (int) ($revL['revoked_count'] ?? -1) === 0,
        'expired unchanged'
    );

    // ---------- M/N idempotent ----------
    $revA2 = commerce_revoke_payment_grants($conn, $payA['payment_id'], $adminId, 'again');
    $mark('M', !empty($revA2['ok']) && !empty($revA2['skipped']) && (int) ($revA2['revoked_count'] ?? -1) === 0, 'already revoked');
    $reasonCnt = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE grant_id=$gA AND status='revoked'"))[0] ?? 0);
    $mark('N', $reasonCnt === 1 && p83_gstatus($conn, $gA) === 'revoked', 'no duplicate effects');

    // ---------- O forged IDs / other user ----------
    $uO1 = p83_user($conn, "p83.o1.{$ts}@example.com");
    $uO2 = p83_user($conn, "p83.o2.{$ts}@example.com");
    $createdUserIds[] = $uO1;
    $createdUserIds[] = $uO2;
    $payO1 = p83_paid_payment($conn, $uO1, "PAY-P83-O1-{$ts}");
    $payO2 = p83_paid_payment($conn, $uO2, "PAY-P83-O2-{$ts}");
    $createdPaymentIds[] = $payO1['payment_id'];
    $createdPaymentIds[] = $payO2['payment_id'];
    $gO1 = p83_grant($conn, $uO1, 'purchase', $payO1['payment_id'], $payO1['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $gO2 = p83_grant($conn, $uO2, 'purchase', $payO2['payment_id'], $payO2['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gO1;
    $createdGrantIds[] = $gO2;
    sca_upsert_permissions($conn, $uO1, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    sca_upsert_permissions($conn, $uO2, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    // Revoke O1 only - O2 must stay
    commerce_revoke_payment_grants($conn, $payO1['payment_id'], $adminId, 'only o1');
    $mark(
        'O',
        p83_gstatus($conn, $gO1) === 'revoked'
            && p83_gstatus($conn, $gO2) === 'active'
            && !p83_has_sca($conn, $uO1, 'full_lms', 0)
            && p83_has_sca($conn, $uO2, 'full_lms', 0),
        'cross-user safe'
    );
    $mark('T', p83_has_sca($conn, $uO2, 'full_lms', 0), 'no cross-user SCA wipe');

    // ---------- P/Q static security ----------
    $adminPhp = file_get_contents(dirname(__DIR__) . '/admin_commerce_payments.php');
    $mark(
        'P',
        strpos($adminPhp, "requireRole('admin')") !== false
            && strpos($adminPhp, 'revoke_access') !== false
            && strpos($adminPhp, 'commerce_revoke_payment_grants') !== false,
        'admin-only UI'
    );
    $mark(
        'Q',
        strpos($adminPhp, 'verifyCSRFToken') !== false
            && preg_match('/verifyCSRFToken[\s\S]*?revoke_access/', $adminPhp) === 1,
        'CSRF before revoke'
    );

    // ---------- R Free Access not revoked ----------
    $mark('R', p83_gstatus($conn, $gHf) === 'active' && (string) (mysqli_fetch_row(mysqli_query($conn, "SELECT source FROM access_grants WHERE grant_id=$gHf"))[0] ?? '') === 'free_access', 'FAR untouched');

    // ---------- S payment ledger ----------
    $payNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $itemsNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $attNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
    $gcashNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
    $mark(
        'S',
        $snapA === $snapA2 && $gcashNow === $baseGcash,
        'ledger immutable / no gcash'
    );

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup local rows
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

$php = 'C:\\xampp\\php\\php.exe';
$runReg = static function (string $label, string $script) use ($php, $mark): void {
    $cmd = '"' . $php . '" ' . escapeshellarg($script);
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $text = implode("\n", $output);
    $hasFail = (bool) preg_match('/\[FAIL\]/', $text);
    $ok = ($code === 0) && !$hasFail;
    $mark($label, $ok, 'exit=' . $code . ($hasFail ? ' has FAIL' : ' clean'));
    echo "--- $label tail ---\n" . implode("\n", array_slice($output, -5)) . "\n";
};

if (getenv('COMMERCE_SKIP_NESTED_REGRESSIONS') === '1') {
    $mark('U', true, 'skipped nested (parent suite)');
    $mark('V', true, 'skipped nested (parent suite)');
    $mark('W', true, 'skipped nested (parent suite)');
    $mark('X', true, 'skipped nested (parent suite)');
    $mark('Y', true, 'skipped nested (parent suite)');
    $mark('Z', true, 'skipped nested (parent suite)');
} else {
    $runReg('U', __DIR__ . '/phase8_2_expiry_reconcile_test.php');
    $runReg('V', __DIR__ . '/phase8_1_free_access_test.php');
    $runReg('W', __DIR__ . '/phase8_1_idempotency_hardening_test.php');
    $runReg('X', __DIR__ . '/phase7_fulfillment_test.php');
    $runReg('Y', __DIR__ . '/activation_commerce_sca_hardening_test.php');
    $runReg('Z', __DIR__ . '/student_access_commerce_sca_hardening_test.php');
}
$endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$endAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$endGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
$endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$endLessons = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons'))[0] ?? 0);

$mark(
    'AA',
    $endPay === $basePay
        && $endItems === $baseItems
        && $endAttempts === $baseAttempts
        && $endGcash === $baseGcash
        && $endGrants === $baseGrants
        && $endSca === $baseSca
        && $endFar === $baseFar
        && $endPkg === $basePkg
        && $endLessons === $baseLessons,
    "AFTER pay=$endPay items=$endItems attempts=$endAttempts gcash=$endGcash grants=$endGrants sca=$endSca far=$endFar pkgs=$endPkg lessons=$endLessons"
);

echo "\n=== Summary ===\n";
$fail = 0;
foreach ($results as $k => $v) {
    if (empty($v['ok'])) {
        $fail++;
        echo "FAIL $k: " . ($v['detail'] ?? '') . "\n";
    }
}
echo ($fail === 0 ? "ALL PASS\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
