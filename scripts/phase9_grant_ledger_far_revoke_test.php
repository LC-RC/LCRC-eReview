<?php
/**
 * Phase 9 — Grant Ledger + Free Access revoke acceptance tests (A–AV), reversible.
 * Does not mutate Phase 8.1–8.5 algorithms. No migrations.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_grants_admin.php';
require_once __DIR__ . '/../includes/student_content_access.php';
require_once __DIR__ . '/../includes/commerce_catalog.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 9 grant ledger + FAR revoke tests ===\n";

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
$scaTableRenamed = false;

function p9_user(mysqli $conn, string $email, string $name = 'Phase9 Student'): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $school = 'Test School';
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
        throw new RuntimeException('user: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

/** @return array{payment_id:int,payment_item_id:int} */
function p9_paid_payment(mysqli $conn, int $userId, string $ref): array
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
         VALUES (?, 1, 'package', 'P9 Full', 10000, 1, 10000, 6, 'month', 'full_lms')"
    );
    mysqli_stmt_bind_param($ins, 'i', $pid);
    if (!mysqli_stmt_execute($ins)) {
        throw new RuntimeException('item: ' . mysqli_error($conn));
    }
    $iid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    return ['payment_id' => $pid, 'payment_item_id' => $iid];
}

function p9_attempt(mysqli $conn, int $paymentId): int
{
    mysqli_query(
        $conn,
        "INSERT INTO payment_verification_attempts (payment_id, engine, confidence, decision, decision_reasons_json)
         VALUES ($paymentId, 'tesseract', 90.00, 'auto_verified', '[]')"
    );
    return (int) mysqli_insert_id($conn);
}

function p9_far(mysqli $conn, int $userId, string $ref, string $status = 'approved'): int
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO free_access_requests (request_ref, user_id, status, student_note, reviewed_at)
         VALUES (?, ?, ?, 'p9 note', NOW())"
    );
    mysqli_stmt_bind_param($stmt, 'sis', $ref, $userId, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('far: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p9_grant(
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
           '" . mysqli_real_escape_string($conn, $ctype) . "', $cid, 'p9',
           $startsSql, $endsSql, '" . mysqli_real_escape_string($conn, $status) . "')"
    );
    if (!$ok) {
        throw new RuntimeException('grant: ' . mysqli_error($conn));
    }
    return (int) mysqli_insert_id($conn);
}

function p9_has_sca(mysqli $conn, int $userId, string $type, int $cid): bool
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

function p9_gstatus(mysqli $conn, int $grantId): string
{
    $r = mysqli_query($conn, 'SELECT status FROM access_grants WHERE grant_id=' . (int) $grantId);
    return (string) (mysqli_fetch_row($r)[0] ?? '');
}

function p9_grow(mysqli $conn, int $grantId): array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM access_grants WHERE grant_id=' . (int) $grantId));
    return $r ?: [];
}

function p9_snap_payment(mysqli $conn, int $pid): array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT status, verification_status, paid_at, fulfilled_at, expected_amount_centavos FROM payments WHERE payment_id=' . (int) $pid));
    return $r ?: [];
}

function p9_user_snap(mysqli $conn, int $uid): array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT status, access_end FROM users WHERE user_id=' . (int) $uid));
    return $r ?: [];
}

try {
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        $hash = password_hash('AdminPass1!', PASSWORD_DEFAULT);
        mysqli_query(
            $conn,
            "INSERT INTO users (full_name, email, password, role, status, email_verified)
             VALUES ('P9 Admin', 'p9.admin.{$ts}@example.com', '$hash', 'admin', 'approved', 1)"
        );
        $adminId = (int) mysqli_insert_id($conn);
        $createdUserIds[] = $adminId;
    }

    $L1 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id LIMIT 1'))[0] ?? 0);
    if ($L1 <= 0) {
        throw new RuntimeException('Need at least 1 lesson');
    }

    $helperSrc = (string) file_get_contents(dirname(__DIR__) . '/includes/commerce_grants_admin.php');
    $ledgerSrc = (string) file_get_contents(dirname(__DIR__) . '/admin_commerce_grants.php');
    $farAdminSrc = (string) file_get_contents(dirname(__DIR__) . '/admin_commerce_free_access.php');
    $payAdminSrc = (string) file_get_contents(dirname(__DIR__) . '/admin_commerce_payments.php');
    $studentViewSrc = (string) file_get_contents(dirname(__DIR__) . '/admin_student_view.php');
    $sidebarSrc = (string) file_get_contents(dirname(__DIR__) . '/admin_sidebar.php');

    // ---------- A admin can load ledger (structure + helper) ----------
    $mark(
        'A',
        strpos($ledgerSrc, "requireRole('admin')") !== false
            && strpos($ledgerSrc, 'commerce_grants_admin_build_ledger') !== false
            && strpos($sidebarSrc, 'admin_commerce_grants') !== false
            && strpos($sidebarSrc, 'Grant Ledger') !== false,
        'admin gate + nav'
    );

    // ---------- B non-admin blocked ----------
    $mark(
        'B',
        strpos($ledgerSrc, "requireRole('admin')") !== false
            && preg_match("/requireRole\\('admin'\\)/", $ledgerSrc) === 1,
        'requireRole admin'
    );

    // Seed grants for filter/display tests
    $uLedger = p9_user($conn, "p9.ledger.{$ts}@example.com", 'P9 Ledger Student');
    $createdUserIds[] = $uLedger;
    $payL = p9_paid_payment($conn, $uLedger, "PAY-P9-L-{$ts}");
    $createdPaymentIds[] = $payL['payment_id'];
    $gPurchase = p9_grant($conn, $uLedger, 'purchase', $payL['payment_id'], $payL['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gPurchase;
    $farL = p9_far($conn, $uLedger, "FAR-P9-L-{$ts}", 'approved');
    $createdFarIds[] = $farL;
    $gFar = p9_grant($conn, $uLedger, 'free_access', null, null, $farL, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 2 MONTH)');
    $createdGrantIds[] = $gFar;
    $gRev = p9_grant($conn, $uLedger, 'admin_manual', null, null, null, 'lesson', $L1, 'revoked', 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gRev;
    mysqli_query($conn, "UPDATE access_grants SET revoked_at=NOW(), revoke_reason='[admin#0] seed' WHERE grant_id=$gRev");
    $gExp = p9_grant($conn, $uLedger, 'complimentary', null, null, null, 'lesson', $L1, 'expired', 'DATE_SUB(NOW(), INTERVAL 3 MONTH)', 'DATE_SUB(NOW(), INTERVAL 2 MONTH)');
    $createdGrantIds[] = $gExp;
    $gExt = p9_grant($conn, $uLedger, 'extension', null, null, null, 'lesson', $L1, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 10 DAY)');
    $createdGrantIds[] = $gExt;

    // ---------- C filters ----------
    $bySource = commerce_grants_admin_build_ledger($conn, ['source' => 'purchase', 'user_id' => (string) $uLedger, 'per_page' => '50']);
    $byFar = commerce_grants_admin_build_ledger($conn, ['free_access_request_id' => (string) $farL]);
    $byPay = commerce_grants_admin_build_ledger($conn, ['payment_id' => (string) $payL['payment_id']]);
    $byStudent = commerce_grants_admin_build_ledger($conn, ['student' => 'P9 Ledger']);
    $mark(
        'C',
        $bySource['total'] >= 1
            && $byFar['total'] === 1
            && $byPay['total'] >= 1
            && $byStudent['total'] >= 1,
        'filters source/far/pay/student'
    );

    // ---------- D pagination ----------
    $page1 = commerce_grants_admin_build_ledger($conn, ['user_id' => (string) $uLedger, 'per_page' => '2', 'page' => '1']);
    $page2 = commerce_grants_admin_build_ledger($conn, ['user_id' => (string) $uLedger, 'per_page' => '2', 'page' => '2']);
    $mark(
        'D',
        count($page1['rows']) === 2
            && $page1['total'] >= 5
            && $page1['total_pages'] >= 3
            && ((int) ($page1['rows'][0]['grant_id'] ?? 0) !== (int) ($page2['rows'][0]['grant_id'] ?? 0)),
        'page size + page2 distinct'
    );

    // ---------- E allowlists ----------
    $bad = commerce_grants_admin_parse_filters(['source' => "purchase'; DROP TABLE access_grants;--", 'status' => 'bogus', 'content_type' => 'evil']);
    $mark(
        'E',
        ($bad['source'] === null)
            && ($bad['status'] === null)
            && ($bad['content_type'] === null)
            && !empty($bad['warnings']),
        'bad filters ignored'
    );

    // ---------- F SQL injection attempts do not alter query ----------
    $inj = commerce_grants_admin_build_ledger($conn, [
        'student' => "%' OR 1=1 --",
        'source' => 'purchase',
        'user_id' => (string) $uLedger,
    ]);
    $mark(
        'F',
        is_array($inj['rows'])
            && $inj['total'] <= $bySource['total'] + 5
            && strpos($helperSrc, 'mysqli_prepare') !== false
            && strpos($helperSrc, 'mysqli_stmt_bind_param') !== false,
        'prepared + bounded injection search'
    );

    // ---------- G GET / read-only page ----------
    $mark(
        'G',
        strpos($ledgerSrc, 'REQUEST_METHOD') !== false
            && strpos($ledgerSrc, 'method="post"') === false
            && strpos($ledgerSrc, 'method="get"') !== false
            && strpos($ledgerSrc, 'ocr_raw') === false
            && strpos($ledgerSrc, 'proof_path') === false
            && strpos($ledgerSrc, 'ocr_extracted') === false,
        'GET-only UI, no OCR/proof'
    );

    // ---------- H no mutation helper in ledger path ----------
    $ledgerOnly = preg_replace('/function commerce_far_revoke_access[\s\S]*$/m', '', $helperSrc) ?? $helperSrc;
    $mark(
        'H',
        preg_match('/\b(UPDATE|DELETE|INSERT)\b/i', $ledgerOnly) !== 1
            && strpos($ledgerSrc, 'commerce_far_revoke_access') === false
            && strpos($ledgerSrc, 'commerce_revoke_payment_grants') === false
            && strpos($ledgerSrc, 'method="post"') === false
            && strpos($helperSrc, 'function commerce_far_revoke_access') !== false,
        'ledger read-only helpers'
    );

    // ---------- I/J/K/L display sources/statuses ----------
    $allU = commerce_grants_admin_build_ledger($conn, ['user_id' => (string) $uLedger, 'per_page' => '100']);
    $sourcesSeen = [];
    $statusesSeen = [];
    foreach ($allU['rows'] as $row) {
        $sourcesSeen[(string) $row['source']] = true;
        $statusesSeen[(string) $row['status']] = true;
    }
    $mark('I', isset($sourcesSeen['purchase']), 'purchase visible');
    $mark('J', isset($sourcesSeen['free_access']), 'free_access visible');
    $mark('K', isset($statusesSeen['revoked']) && isset($statusesSeen['expired']), 'revoked+expired visible');
    $mark(
        'L',
        isset($sourcesSeen['admin_manual'])
            && isset($sourcesSeen['complimentary'])
            && isset($sourcesSeen['extension'])
            && strpos($helperSrc, 'admin_manual') !== false
            && strpos($helperSrc, "INSERT INTO access_grants") === false,
        'manual/comp/ext inspectable only'
    );

    // ---------- M deep links ----------
    $mark(
        'M',
        strpos($farAdminSrc, 'admin_commerce_grants') !== false
            && strpos($farAdminSrc, 'free_access_request_id') !== false
            && strpos($payAdminSrc, 'admin_commerce_grants') !== false
            && strpos($payAdminSrc, 'payment_id=') !== false
            && strpos($studentViewSrc, 'admin_commerce_grants') !== false
            && strpos($studentViewSrc, 'user_id=') !== false,
        'FAR/payment/student deep links'
    );

    // ---------- N–W lone FAR revoke ----------
    $uN = p9_user($conn, "p9.n.{$ts}@example.com");
    $createdUserIds[] = $uN;
    mysqli_query($conn, "UPDATE users SET status='pending', access_end=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE user_id=$uN");
    $userSnapN = p9_user_snap($conn, $uN);
    $payN = p9_paid_payment($conn, $uN, "PAY-P9-N-{$ts}");
    $createdPaymentIds[] = $payN['payment_id'];
    $attN = p9_attempt($conn, $payN['payment_id']);
    mysqli_query(
        $conn,
        "INSERT INTO payment_gcash_references (gcash_reference_norm, payment_id, user_id)
         VALUES ('gcashp9n{$ts}', {$payN['payment_id']}, $uN)"
    );
    $snapPayN = p9_snap_payment($conn, $payN['payment_id']);
    $itemsN = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items WHERE payment_id=' . $payN['payment_id']))[0] ?? 0);
    $attCntN = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts WHERE payment_id=' . $payN['payment_id']))[0] ?? 0);
    $gcashCntN = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references WHERE payment_id=' . $payN['payment_id']))[0] ?? 0);
    $farN = p9_far($conn, $uN, "FAR-P9-N-{$ts}");
    $createdFarIds[] = $farN;
    $gN = p9_grant($conn, $uN, 'free_access', null, null, $farN, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 6 MONTH)');
    $createdGrantIds[] = $gN;
    // Unrelated purchase grant on same user (for X later in stacking tests we use separate users; here for X purchase untouched)
    $gNPurchase = p9_grant($conn, $uN, 'purchase', $payN['payment_id'], $payN['payment_item_id'], null, 'lesson', $L1, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gNPurchase;
    sca_upsert_permissions($conn, $uN, [
        ['content_type' => 'full_lms', 'content_id' => 0],
        ['content_type' => 'lesson', 'content_id' => $L1],
    ], null);

    $revN = commerce_far_revoke_access($conn, $farN, $adminId, 'phase9 revoke test');
    $gNRow = p9_grow($conn, $gN);
    $farNStatus = (string) (mysqli_fetch_row(mysqli_query($conn, 'SELECT status FROM free_access_requests WHERE request_id=' . $farN))[0] ?? '');
    $snapPayN2 = p9_snap_payment($conn, $payN['payment_id']);
    $itemsN2 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items WHERE payment_id=' . $payN['payment_id']))[0] ?? 0);
    $attCntN2 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts WHERE payment_id=' . $payN['payment_id']))[0] ?? 0);
    $gcashCntN2 = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references WHERE payment_id=' . $payN['payment_id']))[0] ?? 0);
    $userSnapN2 = p9_user_snap($conn, $uN);
    $gExists = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE grant_id=' . $gN))[0] ?? 0);

    $mark('N', !empty($revN['ok']) && empty($revN['skipped']) && p9_gstatus($conn, $gN) === 'revoked', 'revoked');
    $mark('O', $gExists === 1, 'grant remains');
    $mark('P', !empty($gNRow['revoked_at']), 'revoked_at set');
    $mark('Q', strpos((string) ($gNRow['revoke_reason'] ?? ''), '[admin#' . $adminId . ']') === 0, 'reason prefixed');
    $mark('R', $farNStatus === 'approved', 'FAR stays approved');
    $mark('S', $snapPayN === $snapPayN2 && $itemsN === $itemsN2, 'payments/items unchanged');
    $mark('T', $attCntN === $attCntN2 && $attN > 0, 'verification unchanged');
    $mark('U', $gcashCntN === $gcashCntN2 && $gcashCntN === 1, 'gcash unchanged');
    $mark('V', ($userSnapN['status'] ?? '') === ($userSnapN2['status'] ?? ''), 'users.status unchanged');
    $mark('W', ($userSnapN['access_end'] ?? '') === ($userSnapN2['access_end'] ?? ''), 'users.access_end unchanged');
    $mark('X', p9_gstatus($conn, $gNPurchase) === 'active', 'purchase grant untouched');

    // ---------- Y other FAR grant untouched ----------
    $farY2 = p9_far($conn, $uN, "FAR-P9-Y2-{$ts}");
    $createdFarIds[] = $farY2;
    $gY2 = p9_grant($conn, $uN, 'free_access', null, null, $farY2, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 3 MONTH)');
    $createdGrantIds[] = $gY2;
    // Re-revoke farN (already revoked) and ensure Y2 stays active — already revoked farN; create separate user for clean Y
    $uY = p9_user($conn, "p9.y.{$ts}@example.com");
    $createdUserIds[] = $uY;
    $farY1 = p9_far($conn, $uY, "FAR-P9-Y1-{$ts}");
    $farYOther = p9_far($conn, $uY, "FAR-P9-YOTH-{$ts}");
    $createdFarIds[] = $farY1;
    $createdFarIds[] = $farYOther;
    $gY1 = p9_grant($conn, $uY, 'free_access', null, null, $farY1, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $gYOther = p9_grant($conn, $uY, 'free_access', null, null, $farYOther, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 2 MONTH)');
    $createdGrantIds[] = $gY1;
    $createdGrantIds[] = $gYOther;
    commerce_far_revoke_access($conn, $farY1, $adminId, 'revoke one FAR only');
    $mark('Y', p9_gstatus($conn, $gY1) === 'revoked' && p9_gstatus($conn, $gYOther) === 'active', 'other FAR grant untouched');

    // ---------- Z purchase overlap keeps SCA ----------
    $uZ = p9_user($conn, "p9.z.{$ts}@example.com");
    $createdUserIds[] = $uZ;
    $payZ = p9_paid_payment($conn, $uZ, "PAY-P9-Z-{$ts}");
    $createdPaymentIds[] = $payZ['payment_id'];
    $farZ = p9_far($conn, $uZ, "FAR-P9-Z-{$ts}");
    $createdFarIds[] = $farZ;
    $gZp = p9_grant($conn, $uZ, 'purchase', $payZ['payment_id'], $payZ['payment_item_id'], null, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $gZf = p9_grant($conn, $uZ, 'free_access', null, null, $farZ, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 6 MONTH)');
    $createdGrantIds[] = $gZp;
    $createdGrantIds[] = $gZf;
    sca_upsert_permissions($conn, $uZ, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    $revZ = commerce_far_revoke_access($conn, $farZ, $adminId, 'keep purchase SCA');
    $mark(
        'Z',
        !empty($revZ['ok']) && p9_gstatus($conn, $gZf) === 'revoked' && p9_gstatus($conn, $gZp) === 'active' && p9_has_sca($conn, $uZ, 'full_lms', 0),
        'purchase stacking'
    );

    // ---------- AA free_access overlap keeps SCA ----------
    $uAA = p9_user($conn, "p9.aa.{$ts}@example.com");
    $createdUserIds[] = $uAA;
    $farAA1 = p9_far($conn, $uAA, "FAR-P9-AA1-{$ts}");
    $farAA2 = p9_far($conn, $uAA, "FAR-P9-AA2-{$ts}");
    $createdFarIds[] = $farAA1;
    $createdFarIds[] = $farAA2;
    $gAA1 = p9_grant($conn, $uAA, 'free_access', null, null, $farAA1, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $gAA2 = p9_grant($conn, $uAA, 'free_access', null, null, $farAA2, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 2 MONTH)');
    $createdGrantIds[] = $gAA1;
    $createdGrantIds[] = $gAA2;
    sca_upsert_permissions($conn, $uAA, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    commerce_far_revoke_access($conn, $farAA1, $adminId, 'keep other FAR');
    $mark(
        'AA',
        p9_gstatus($conn, $gAA1) === 'revoked' && p9_gstatus($conn, $gAA2) === 'active' && p9_has_sca($conn, $uAA, 'full_lms', 0),
        'FAR stacking'
    );

    // ---------- AB no live coverage removes commerce SCA key ----------
    $uAB = p9_user($conn, "p9.ab.{$ts}@example.com");
    $createdUserIds[] = $uAB;
    $farAB = p9_far($conn, $uAB, "FAR-P9-AB-{$ts}");
    $createdFarIds[] = $farAB;
    $gAB = p9_grant($conn, $uAB, 'free_access', null, null, $farAB, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gAB;
    sca_upsert_permissions($conn, $uAB, [
        ['content_type' => 'full_lms', 'content_id' => 0],
        ['content_type' => 'lesson', 'content_id' => $L1],
    ], null);
    commerce_far_revoke_access($conn, $farAB, $adminId, 'remove commerce SCA');
    $mark(
        'AB',
        !p9_has_sca($conn, $uAB, 'full_lms', 0) && p9_has_sca($conn, $uAB, 'lesson', $L1),
        'commerce key gone, manual kept'
    );

    // ---------- AC manual-only SCA remains (explicit) ----------
    $mark('AC', p9_has_sca($conn, $uAB, 'lesson', $L1), 'manual-only remains');

    // ---------- AD already revoked idempotent ----------
    $revAD = commerce_far_revoke_access($conn, $farN, $adminId, 'again');
    $mark(
        'AD',
        !empty($revAD['ok']) && !empty($revAD['skipped']) && (int) ($revAD['revoked_count'] ?? -1) === 0 && p9_gstatus($conn, $gN) === 'revoked',
        'idempotent skip'
    );

    // ---------- AE forged cross-user ----------
    $uAE1 = p9_user($conn, "p9.ae1.{$ts}@example.com");
    $uAE2 = p9_user($conn, "p9.ae2.{$ts}@example.com");
    $createdUserIds[] = $uAE1;
    $createdUserIds[] = $uAE2;
    $farAE1 = p9_far($conn, $uAE1, "FAR-P9-AE1-{$ts}");
    $farAE2 = p9_far($conn, $uAE2, "FAR-P9-AE2-{$ts}");
    $createdFarIds[] = $farAE1;
    $createdFarIds[] = $farAE2;
    $gAE1 = p9_grant($conn, $uAE1, 'free_access', null, null, $farAE1, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $gAE2 = p9_grant($conn, $uAE2, 'free_access', null, null, $farAE2, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gAE1;
    $createdGrantIds[] = $gAE2;
    sca_upsert_permissions($conn, $uAE1, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    sca_upsert_permissions($conn, $uAE2, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    commerce_far_revoke_access($conn, $farAE1, $adminId, 'only ae1');
    $noPostUserAuthority = strpos($farAdminSrc, "\$_POST['user_id']") === false
        && strpos($farAdminSrc, '$_POST["user_id"]') === false
        && strpos($helperSrc, 'commerce_far_get_request') !== false;
    $mark(
        'AE',
        p9_gstatus($conn, $gAE1) === 'revoked'
            && p9_gstatus($conn, $gAE2) === 'active'
            && !p9_has_sca($conn, $uAE1, 'full_lms', 0)
            && p9_has_sca($conn, $uAE2, 'full_lms', 0)
            && $noPostUserAuthority,
        'cross-user safe'
    );

    // ---------- AF non-admin blocked (structural) ----------
    $mark(
        'AF',
        strpos($farAdminSrc, "requireRole('admin')") !== false
            && strpos($farAdminSrc, 'revoke_access') !== false
            && strpos($farAdminSrc, 'commerce_far_revoke_access') !== false,
        'admin-only FAR revoke UI'
    );

    // ---------- AG invalid CSRF rejected (structural) ----------
    $mark(
        'AG',
        strpos($farAdminSrc, 'verifyCSRFToken') !== false
            && preg_match('/verifyCSRFToken[\s\S]*?revoke_access/', $farAdminSrc) === 1,
        'CSRF before revoke'
    );

    // ---------- AH invalid / non-free_access source ----------
    $uAH = p9_user($conn, "p9.ah.{$ts}@example.com");
    $createdUserIds[] = $uAH;
    $farAH = p9_far($conn, $uAH, "FAR-P9-AH-{$ts}");
    $createdFarIds[] = $farAH;
    $gAH = p9_grant($conn, $uAH, 'free_access', null, null, $farAH, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gAH;
    mysqli_query($conn, "UPDATE access_grants SET source='purchase' WHERE grant_id=$gAH");
    $revAH = commerce_far_revoke_access($conn, $farAH, $adminId, 'should fail source');
    $mark(
        'AH',
        empty($revAH['ok']) && ($revAH['error'] ?? '') === 'not_free_access_grant' && p9_gstatus($conn, $gAH) === 'active',
        'non-free_access rejected'
    );

    // ---------- AI expired cannot revoke ----------
    $uAI = p9_user($conn, "p9.ai.{$ts}@example.com");
    $createdUserIds[] = $uAI;
    $farAI = p9_far($conn, $uAI, "FAR-P9-AI-{$ts}");
    $createdFarIds[] = $farAI;
    $gAI = p9_grant($conn, $uAI, 'free_access', null, null, $farAI, 'full_lms', 0, 'expired', 'DATE_SUB(NOW(), INTERVAL 3 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gAI;
    $revAI = commerce_far_revoke_access($conn, $farAI, $adminId, 'expired stay');
    $mark(
        'AI',
        empty($revAI['ok']) && ($revAI['error'] ?? '') === 'grant_not_active' && p9_gstatus($conn, $gAI) === 'expired',
        'expired not revoked'
    );

    // ---------- AJ repeated revoke no duplicate side effects ----------
    $reasonBefore = (string) (mysqli_fetch_row(mysqli_query($conn, "SELECT revoke_reason FROM access_grants WHERE grant_id=$gN"))[0] ?? '');
    $revokedAtBefore = (string) (mysqli_fetch_row(mysqli_query($conn, "SELECT revoked_at FROM access_grants WHERE grant_id=$gN"))[0] ?? '');
    $revAJ = commerce_far_revoke_access($conn, $farN, $adminId, 'duplicate side effect check');
    $reasonAfter = (string) (mysqli_fetch_row(mysqli_query($conn, "SELECT revoke_reason FROM access_grants WHERE grant_id=$gN"))[0] ?? '');
    $revokedAtAfter = (string) (mysqli_fetch_row(mysqli_query($conn, "SELECT revoked_at FROM access_grants WHERE grant_id=$gN"))[0] ?? '');
    $mark(
        'AJ',
        !empty($revAJ['ok']) && !empty($revAJ['skipped'])
            && $reasonBefore === $reasonAfter
            && $revokedAtBefore === $revokedAtAfter,
        'no reason/timestamp rewrite'
    );

    // ---------- AK reconcile failure does not restore grant ----------
    $uAK = p9_user($conn, "p9.ak.{$ts}@example.com");
    $createdUserIds[] = $uAK;
    $farAK = p9_far($conn, $uAK, "FAR-P9-AK-{$ts}");
    $createdFarIds[] = $farAK;
    $gAK = p9_grant($conn, $uAK, 'free_access', null, null, $farAK, 'full_lms', 0, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gAK;
    sca_upsert_permissions($conn, $uAK, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    if (!mysqli_query($conn, 'RENAME TABLE student_content_permissions TO student_content_permissions_p9_bak')) {
        throw new RuntimeException('rename sca failed: ' . mysqli_error($conn));
    }
    $scaTableRenamed = true;
    $revAK = commerce_far_revoke_access($conn, $farAK, $adminId, 'force reconcile fail');
    $akStatus = p9_gstatus($conn, $gAK);
    if (!mysqli_query($conn, 'RENAME TABLE student_content_permissions_p9_bak TO student_content_permissions')) {
        throw new RuntimeException('restore sca table failed: ' . mysqli_error($conn));
    }
    $scaTableRenamed = false;
    $mark(
        'AK',
        empty($revAK['ok'])
            && strpos((string) ($revAK['error'] ?? ''), 'grants_revoked_but_reconcile_failed') === 0
            && $akStatus === 'revoked'
            && (int) ($revAK['user_id'] ?? 0) === $uAK,
        'revoke retained on reconcile fail'
    );

    // ---------- AL existing Phase 8 repair usable ----------
    $repair = commerce_reconcile_user_commerce_sca($conn, $uAK);
    $cliSrc = (string) file_get_contents(dirname(__DIR__) . '/scripts/commerce_expire_reconcile.php');
    $mark(
        'AL',
        !empty($repair['ok'])
            && !p9_has_sca($conn, $uAK, 'full_lms', 0)
            && strpos($cliSrc, '--user_id=') !== false
            && strpos($cliSrc, 'commerce_expire_and_reconcile') !== false,
        'Phase 8 repair path'
    );

} catch (Throwable $e) {
    if ($scaTableRenamed) {
        @mysqli_query($conn, 'RENAME TABLE student_content_permissions_p9_bak TO student_content_permissions');
        $scaTableRenamed = false;
    }
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup local rows
if ($scaTableRenamed) {
    @mysqli_query($conn, 'RENAME TABLE student_content_permissions_p9_bak TO student_content_permissions');
}
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
    $cmd = 'set COMMERCE_SKIP_NESTED_REGRESSIONS=1&& "' . $php . '" ' . escapeshellarg($script);
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $text = implode("\n", $output);
    $hasFail = (bool) preg_match('/\[FAIL\]/', $text);
    $ok = ($code === 0) && !$hasFail;
    $mark($label, $ok, 'exit=' . $code . ($hasFail ? ' has FAIL' : ' clean'));
    echo "--- $label tail ---\n" . implode("\n", array_slice($output, -5)) . "\n";
};

$runReg('AM', __DIR__ . '/phase8_5_commerce_reports_test.php');
$runReg('AN', __DIR__ . '/phase8_4_commerce_notifications_test.php');
$runReg('AO', __DIR__ . '/phase8_3_paid_revoke_test.php');
$runReg('AP', __DIR__ . '/phase8_2_expiry_reconcile_test.php');
$runReg('AQ', __DIR__ . '/phase8_1_free_access_test.php');
$runReg('AR', __DIR__ . '/phase8_1_idempotency_hardening_test.php');
$runReg('AS', __DIR__ . '/phase7_fulfillment_test.php');
$runReg('AT', __DIR__ . '/activation_commerce_sca_hardening_test.php');
$runReg('AU', __DIR__ . '/student_access_commerce_sca_hardening_test.php');

$endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$endAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$endGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
$endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$endLessons = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons'))[0] ?? 0);

$farDup = (int) (mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM (
        SELECT free_access_request_id
        FROM access_grants
        WHERE free_access_request_id IS NOT NULL
          AND content_type = 'full_lms'
          AND content_id = 0
        GROUP BY free_access_request_id
        HAVING COUNT(*) > 1
     ) d"
))[0] ?? 0);
$approvedNoGrant = (int) (mysqli_fetch_row(mysqli_query(
    $conn,
    "SELECT COUNT(*) FROM free_access_requests f
     WHERE f.status = 'approved'
       AND NOT EXISTS (
         SELECT 1 FROM access_grants g
         WHERE g.free_access_request_id = f.request_id
           AND g.content_type = 'full_lms'
           AND g.content_id = 0
       )"
))[0] ?? 0);

echo "AFTER pay=$endPay items=$endItems attempts=$endAttempts gcash=$endGcash grants=$endGrants sca=$endSca far=$endFar pkgs=$endPkg lessons=$endLessons far_dup=$farDup approved_without_grant=$approvedNoGrant\n";

$cleanupOk = $endPay === $basePay
    && $endItems === $baseItems
    && $endAttempts === $baseAttempts
    && $endGcash === $baseGcash
    && $endGrants === $baseGrants
    && $endSca === $baseSca
    && $endFar === $baseFar
    && $endPkg === $basePkg
    && $endLessons === $baseLessons
    && $farDup === 0
    && $approvedNoGrant === 0;
$mark('AV', $cleanupOk, "baseline pay {$basePay}->{$endPay} grants {$baseGrants}->{$endGrants} sca {$baseSca}->{$endSca} far_dup=$farDup awg=$approvedNoGrant");

$failed = 0;
foreach ($results as $r) {
    if (empty($r['ok'])) {
        $failed++;
    }
}
echo '=== Phase 9 summary: ' . (count($results) - $failed) . ' passed, ' . $failed . " failed ===\n";
exit($failed > 0 ? 1 : 0);
