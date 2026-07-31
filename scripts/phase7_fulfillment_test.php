<?php
/**
 * Phase 7 — fulfillment + manual review + By Topic bulk acceptance tests (A–Z).
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);
define('COMMERCE_OCR_TEST_MODE', true);
define('COMMERCE_NOTIFY_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_payment.php';
require_once __DIR__ . '/../includes/commerce_verification.php';
require_once __DIR__ . '/../includes/commerce_fulfillment.php';
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

echo "=== Phase 7 fulfillment tests ===\n";

$basePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$baseItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$baseAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$baseFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$baseGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
$baseLessons = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons'))[0] ?? 0);

echo "Baseline pay=$basePay grants=$baseGrants sca=$baseSca pkgs=$basePkg lessons=$baseLessons\n";

$createdUserIds = [];
$createdPackageIds = [];
$createdPaymentIds = [];
$proofFiles = [];
$touchedLessonIds = [];
$lessonSnap = [];
$settingsBackup = commerce_get_payment_settings($conn);

$GCASH_NAME = 'LCRC Review Center';
$GCASH_NUM = '09171234567';
mysqli_query(
    $conn,
    "UPDATE payment_settings SET
        gcash_account_name='" . mysqli_real_escape_string($conn, $GCASH_NAME) . "',
        gcash_number='" . mysqli_real_escape_string($conn, $GCASH_NUM) . "',
        ocr_confidence_threshold=85, receipt_max_age_days=7, vision_fallback_enabled=0
     WHERE setting_id=1"
);

function p7_user(mysqli $conn, string $email, string $path, ?int $pkgId, ?string $lessonsJson, string $status = 'pending'): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase7 Test';
    $school = 'Test School';
    $review = 'reviewee';
    $proof = '';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, review_type, enrollment_path, selected_package_id, selected_lesson_ids_json, school, school_other, payment_proof, email, password, role, status, email_verified)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'student', ?, 1)"
    );
    mysqli_stmt_bind_param($stmt, 'sssissssss', $name, $review, $path, $pkgId, $lessonsJson, $school, $proof, $email, $hash, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('user insert: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p7_png(string $path): void
{
    $bin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $bin);
}

function p7_receipt(string $amountPesos, string $ref, string $recipient, string $paidAt): string
{
    return "Payment Successful\nYou have sent money\nAmount: ₱{$amountPesos}\nRef No. {$ref}\nTo: {$recipient}\nDate: {$paidAt}";
}

function p7_set_ocr(string $text, float $conf = 92.0): void
{
    $GLOBALS['commerce_test_ocr_result'] = [
        'ok' => true,
        'engine' => 'tesseract',
        'raw_text' => $text,
        'confidence' => $conf,
    ];
}

function p7_submit(
    mysqli $conn,
    int $userId,
    string $purchaseType,
    ?int $pkgId,
    ?array $lessonIds,
    string $ref,
    array &$createdPaymentIds,
    array &$proofFiles
): array {
    $co = commerce_create_or_resume_checkout($conn, $userId, $purchaseType, $pkgId, $lessonIds);
    if (empty($co['ok'])) {
        return ['ok' => false, 'error' => $co['error'] ?? 'checkout'];
    }
    $pid = (int) $co['payment']['payment_id'];
    $createdPaymentIds[] = $pid;
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p7_' . $pid . '_' . bin2hex(random_bytes(3)) . '.png';
    p7_png($tmp);
    $proofFiles[] = $tmp;
    $sub = commerce_submit_payment_proof_and_reference($conn, $pid, $userId, $ref, [
        'name' => 'p.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp),
    ]);
    if (empty($sub['ok'])) {
        return ['ok' => false, 'error' => $sub['error'] ?? 'submit', 'payment_id' => $pid];
    }
    $pay = commerce_get_payment($conn, $pid);
    if (!empty($pay['proof_path'])) {
        $proofFiles[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $pay['proof_path']);
    }
    return ['ok' => true, 'payment_id' => $pid, 'payment' => $pay];
}

function p7_grant_count(mysqli $conn, int $userId): int
{
    $r = mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE user_id=' . (int) $userId);
    return (int) (mysqli_fetch_row($r)[0] ?? 0);
}

function p7_sca_count(mysqli $conn, int $userId): int
{
    $r = mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions WHERE user_id=' . (int) $userId);
    return (int) (mysqli_fetch_row($r)[0] ?? 0);
}

try {
    $ts = (string) time();
    $paidAt = date('Y-m-d H:i:s', time() - 3600);

    // Lessons for by-topic
    $lr = mysqli_query($conn, "SELECT lesson_id FROM lessons WHERE subject_id IN (SELECT subject_id FROM subjects WHERE status='active') ORDER BY lesson_id LIMIT 3");
    $lessonIds = [];
    while ($lr && ($row = mysqli_fetch_assoc($lr))) {
        $lessonIds[] = (int) $row['lesson_id'];
    }
    if (count($lessonIds) < 2) {
        throw new RuntimeException('Need at least 2 lessons');
    }
    $L1 = $lessonIds[0];
    $L2 = $lessonIds[1];
    $L3 = $lessonIds[2] ?? $lessonIds[1];
    foreach ([$L1, $L2, $L3] as $lid) {
        $touchedLessonIds[] = $lid;
        $snap = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT price_centavos, access_duration_value, access_duration_unit, is_purchasable FROM lessons WHERE lesson_id=' . (int) $lid));
        $lessonSnap[$lid] = $snap;
    }
    mysqli_query($conn, "UPDATE lessons SET price_centavos=20000, access_duration_value=6, access_duration_unit='month', is_purchasable=1 WHERE lesson_id IN ($L1,$L2)");

    // Packages
    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P7_FULL', 'P7 Full', 't', 150000, 'PHP', 6, 'month', 'full_lms', 1, 1, 1)");
    $fullId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $fullId;

    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P7_MAP', 'P7 Mapped', 't', 50000, 'PHP', 3, 'month', 'mapped', 1, 1, 2)");
    $mapId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $mapId;
    $ins = mysqli_prepare($conn, "INSERT INTO package_content_items (package_id, content_type, content_id, sort_order) VALUES (?, 'lesson', ?, 0)");
    mysqli_stmt_bind_param($ins, 'ii', $mapId, $L1);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P7_BAD', 'P7 Bad Map', 't', 40000, 'PHP', 1, 'month', 'mapped', 1, 1, 3)");
    $badId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $badId;
    // Orphan content id that will not exist at fulfill time — insert fake then delete entity? Use nonexistent lesson id 999999999
    mysqli_query($conn, "INSERT INTO package_content_items (package_id, content_type, content_id, sort_order) VALUES ($badId, 'lesson', 999999999, 0)");

    // ---------- A auto_verified full_lms fulfills ----------
    $uA = p7_user($conn, "phase7.a.{$ts}@example.com", 'package', $fullId, null);
    $createdUserIds[] = $uA;
    // Seed existing SCA that must survive merge
    sca_upsert_permissions($conn, $uA, [['content_type' => 'lesson', 'content_id' => $L3]], null);
    $scaBeforeA = p7_sca_count($conn, $uA);
    $refA = 'P7A' . substr($ts, -8) . 'ZZ';
    $subA = p7_submit($conn, $uA, 'package', $fullId, null, $refA, $createdPaymentIds, $proofFiles);
    p7_set_ocr(p7_receipt('1,500.00', $refA, $GCASH_NAME, $paidAt));
    $vA = commerce_verify_payment($conn, (int) $subA['payment_id']);
    $payA = commerce_get_payment($conn, (int) $subA['payment_id']);
    $gA = p7_grant_count($conn, $uA);
    $mark(
        'A',
        ($vA['decision'] ?? '') === 'auto_verified'
            && ($payA['status'] ?? '') === 'paid'
            && !empty($payA['fulfilled_at'])
            && $gA >= 1,
        'dec=' . ($vA['decision'] ?? '') . ' fulfilled=' . ($payA['fulfilled_at'] ?? 'NULL') . " grants=$gA"
    );

    // ---------- I/J full_lms without content items ----------
    $itemsA = commerce_get_payment_items($conn, (int) $subA['payment_id']);
    $scopeA = (string) ($itemsA[0]['package_access_scope'] ?? '');
    $snapA = $itemsA[0]['package_content_snapshot_json'] ?? '[]';
    $mark('I', $scopeA === 'full_lms' && $gA >= 1, "scope=$scopeA");
    $mark('J', $scopeA === 'full_lms' && ($snapA === '[]' || $snapA === [] || $snapA === 'null'), 'snapshot empty ok');

    // ---------- S/T SCA merge preserved ----------
    $hasL3 = false;
    $hasFull = false;
    $pr = mysqli_query($conn, "SELECT content_type, content_id FROM student_content_permissions WHERE user_id=$uA");
    while ($pr && ($row = mysqli_fetch_assoc($pr))) {
        if ($row['content_type'] === 'lesson' && (int) $row['content_id'] === $L3) {
            $hasL3 = true;
        }
        if ($row['content_type'] === 'full_lms') {
            $hasFull = true;
        }
    }
    $scaAfterA = p7_sca_count($conn, $uA);
    $mark('S', $hasL3 && $scaAfterA >= $scaBeforeA, "before=$scaBeforeA after=$scaAfterA hasL3=" . ($hasL3 ? '1' : '0'));
    $mark('T', $hasL3 && $hasFull, 'existing lesson + full_lms both present');

    // ---------- U login auto-activated after successful fulfill ----------
    $stA = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM users WHERE user_id=$uA"));
    $mark('U', ($stA['status'] ?? '') === 'approved', (string) ($stA['status'] ?? ''));

    // ---------- B needs_review no fulfill ----------
    $uB = p7_user($conn, "phase7.b.{$ts}@example.com", 'package', $fullId, null);
    $createdUserIds[] = $uB;
    $refB = 'P7B' . substr($ts, -8) . 'ZZ';
    $subB = p7_submit($conn, $uB, 'package', $fullId, null, $refB, $createdPaymentIds, $proofFiles);
    p7_set_ocr(p7_receipt('999.00', $refB, $GCASH_NAME, $paidAt));
    $vB = commerce_verify_payment($conn, (int) $subB['payment_id']);
    $payB = commerce_get_payment($conn, (int) $subB['payment_id']);
    $mark(
        'B',
        ($vB['decision'] ?? '') === 'needs_review'
            && ($payB['status'] ?? '') === 'pending_verification'
            && empty($payB['fulfilled_at'])
            && p7_grant_count($conn, $uB) === 0,
        ($vB['decision'] ?? '')
    );

    // ---------- C manual Approve fulfills ----------
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        // create disposable admin
        $ah = password_hash('AdminPass1!', PASSWORD_DEFAULT);
        mysqli_query($conn, "INSERT INTO users (full_name, email, password, role, status, email_verified, review_type, school, payment_proof)
            VALUES ('P7 Admin', 'phase7.admin.{$ts}@example.com', '$ah', 'admin', 'approved', 1, 'reviewee', 'x', '')");
        $adminId = (int) mysqli_insert_id($conn);
        $createdUserIds[] = $adminId;
    }
    $appr = commerce_manual_approve_payment($conn, (int) $subB['payment_id'], $adminId, 'ok receipt');
    $payB2 = commerce_get_payment($conn, (int) $subB['payment_id']);
    $mark(
        'C',
        !empty($appr['ok'])
            && ($payB2['verification_status'] ?? '') === 'manually_approved'
            && ($payB2['status'] ?? '') === 'paid'
            && !empty($payB2['fulfilled_at'])
            && p7_grant_count($conn, $uB) >= 1,
        ($appr['error'] ?? '') . ' v=' . ($payB2['verification_status'] ?? '')
    );

    // ---------- D manual Reject no fulfill ----------
    $uD = p7_user($conn, "phase7.d.{$ts}@example.com", 'package', $fullId, null);
    $createdUserIds[] = $uD;
    $refD = 'P7D' . substr($ts, -8) . 'ZZ';
    $subD = p7_submit($conn, $uD, 'package', $fullId, null, $refD, $createdPaymentIds, $proofFiles);
    p7_set_ocr(p7_receipt('1.00', $refD, $GCASH_NAME, $paidAt));
    commerce_verify_payment($conn, (int) $subD['payment_id']);
    $rej = commerce_manual_reject_payment($conn, (int) $subD['payment_id'], $adminId, 'bad');
    $payD = commerce_get_payment($conn, (int) $subD['payment_id']);
    $mark(
        'D',
        !empty($rej['ok'])
            && ($payD['verification_status'] ?? '') === 'manually_rejected'
            && ($payD['status'] ?? '') === 'rejected'
            && empty($payD['fulfilled_at'])
            && p7_grant_count($conn, $uD) === 0,
        ($rej['error'] ?? '')
    );

    // ---------- E failed does not fulfill ----------
    $uE = p7_user($conn, "phase7.e.{$ts}@example.com", 'package', $fullId, null);
    $createdUserIds[] = $uE;
    $refE = 'P7E' . substr($ts, -8) . 'ZZ';
    $subE = p7_submit($conn, $uE, 'package', $fullId, null, $refE, $createdPaymentIds, $proofFiles);
    $GLOBALS['commerce_test_ocr_result'] = ['ok' => false, 'engine' => 'none', 'raw_text' => '', 'confidence' => 0, 'error' => 'tesseract_unavailable'];
    $vE = commerce_verify_payment($conn, (int) $subE['payment_id']);
    $payE = commerce_get_payment($conn, (int) $subE['payment_id']);
    $fE = commerce_fulfill_payment($conn, (int) $subE['payment_id']);
    $mark(
        'E',
        ($vE['decision'] ?? '') === 'failed'
            && empty($payE['fulfilled_at'])
            && empty($fE['ok'])
            && p7_grant_count($conn, $uE) === 0,
        ($vE['decision'] ?? '') . ' fulfill_err=' . ($fE['error'] ?? '')
    );

    // ---------- F awaiting_proof ----------
    $uF = p7_user($conn, "phase7.f.{$ts}@example.com", 'package', $fullId, null);
    $createdUserIds[] = $uF;
    $coF = commerce_create_or_resume_checkout($conn, $uF, 'package', $fullId, null);
    $pidF = (int) ($coF['payment']['payment_id'] ?? 0);
    $createdPaymentIds[] = $pidF;
    $fF = commerce_fulfill_payment($conn, $pidF);
    $payF = commerce_get_payment($conn, $pidF);
    $mark('F', empty($fF['ok']) && ($payF['status'] ?? '') === 'awaiting_proof' && p7_grant_count($conn, $uF) === 0, ($fF['error'] ?? ''));

    // ---------- G unpaid / not eligible ----------
    $mark('G', empty($fF['ok']) && ($fF['error'] ?? '') === 'not_paid', ($fF['error'] ?? ''));

    // ---------- H Free Access ----------
    $uH = p7_user($conn, "phase7.free.{$ts}@example.com", 'free_access', null, null);
    $createdUserIds[] = $uH;
    $farRef = 'FAR-P7-' . $ts;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('$farRef', $uH, 'pending')");
    $freePay = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM payments WHERE user_id=$uH"))[0] ?? 0);
    $mark('H', $freePay === 0 && p7_grant_count($conn, $uH) === 0, "payments=$freePay");

    // ---------- K mapped valid ----------
    $uK = p7_user($conn, "phase7.k.{$ts}@example.com", 'package', $mapId, null);
    $createdUserIds[] = $uK;
    $refK = 'P7K' . substr($ts, -8) . 'ZZ';
    $subK = p7_submit($conn, $uK, 'package', $mapId, null, $refK, $createdPaymentIds, $proofFiles);
    p7_set_ocr(p7_receipt('500.00', $refK, $GCASH_NAME, $paidAt));
    commerce_verify_payment($conn, (int) $subK['payment_id']);
    $payK = commerce_get_payment($conn, (int) $subK['payment_id']);
    $gK = mysqli_fetch_assoc(mysqli_query($conn, "SELECT content_type, content_id FROM access_grants WHERE user_id=$uK AND content_type='lesson' LIMIT 1"));
    $mark(
        'K',
        !empty($payK['fulfilled_at']) && $gK && (int) $gK['content_id'] === $L1,
        'lesson=' . (int) ($gK['content_id'] ?? 0)
    );

    // ---------- L invalid mapped fails closed ----------
    $uL = p7_user($conn, "phase7.l.{$ts}@example.com", 'package', $badId, null);
    $createdUserIds[] = $uL;
    // Checkout validation may reject bad map at create — if create fails, force payment with snapshot orphan
    $coL = commerce_create_or_resume_checkout($conn, $uL, 'package', $badId, null);
    if (!empty($coL['ok'])) {
        $pidL = (int) $coL['payment']['payment_id'];
        $createdPaymentIds[] = $pidL;
        $refL = 'P7L' . substr($ts, -8) . 'ZZ';
        $tmp = sys_get_temp_dir() . '/p7l.png';
        p7_png($tmp);
        $proofFiles[] = $tmp;
        commerce_submit_payment_proof_and_reference($conn, $pidL, $uL, $refL, [
            'name' => 'p.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp),
        ]);
        // Force paid eligible without OCR
        mysqli_query($conn, "UPDATE payments SET status='paid', verification_status='auto_verified', paid_at=NOW() WHERE payment_id=$pidL");
        $fL = commerce_fulfill_payment($conn, $pidL);
        $payL = commerce_get_payment($conn, $pidL);
        $mark('L', empty($fL['ok']) && empty($payL['fulfilled_at']) && p7_grant_count($conn, $uL) === 0, ($fL['error'] ?? ''));
    } else {
        // Create rejected at checkout is also fail-closed for orphan maps
        $mark('L', true, 'checkout rejected invalid map: ' . ($coL['error'] ?? ''));
    }

    // ---------- M/N by topic grants ----------
    $uM = p7_user($conn, "phase7.m.{$ts}@example.com", 'by_topic', null, json_encode([$L1, $L2]));
    $createdUserIds[] = $uM;
    $refM = 'P7M' . substr($ts, -8) . 'ZZ';
    $subM = p7_submit($conn, $uM, 'by_topic', null, [$L1, $L2], $refM, $createdPaymentIds, $proofFiles);
    $amtM = (int) ($subM['payment']['expected_amount_centavos'] ?? 0);
    p7_set_ocr(p7_receipt(number_format($amtM / 100, 2, '.', ','), $refM, $GCASH_NAME, $paidAt));
    commerce_verify_payment($conn, (int) $subM['payment_id']);
    $gM = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uM AND content_type='lesson'"))[0] ?? 0);
    $payM = commerce_get_payment($conn, (int) $subM['payment_id']);
    $mark('M', $gM === 2 && !empty($payM['fulfilled_at']), "grants=$gM");
    $mark('N', $gM === 2, "multi=$gM");

    // ---------- O/P repurchase stacks ----------
    $g1 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ends_at FROM access_grants WHERE user_id=$uM AND content_id=$L1 ORDER BY grant_id ASC LIMIT 1"));
    $ends1 = strtotime((string) ($g1['ends_at'] ?? ''));
    // Second purchase same lessons
    // Need new payment — first is fulfilled/paid so create new checkout
    $refO = 'P7O' . substr($ts, -8) . 'ZZ';
    $subO = p7_submit($conn, $uM, 'by_topic', null, [$L1], $refO, $createdPaymentIds, $proofFiles);
    $amtO = (int) ($subO['payment']['expected_amount_centavos'] ?? 0);
    p7_set_ocr(p7_receipt(number_format($amtO / 100, 2, '.', ','), $refO, $GCASH_NAME, $paidAt));
    commerce_verify_payment($conn, (int) $subO['payment_id']);
    $gO = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id=$uM AND content_id=$L1"))[0] ?? 0);
    $g2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT ends_at FROM access_grants WHERE user_id=$uM AND content_id=$L1 ORDER BY grant_id DESC LIMIT 1"));
    $ends2 = strtotime((string) ($g2['ends_at'] ?? ''));
    $mark('O', $gO >= 2, "grants_for_L1=$gO");
    $mark('P', $ends1 && $ends2 && $ends2 > $ends1, 'ends1=' . date('Y-m-d', $ends1 ?: 0) . ' ends2=' . date('Y-m-d', $ends2 ?: 0));

    // ---------- Q expired starts from NOW ----------
    $uQ = p7_user($conn, "phase7.q.{$ts}@example.com", 'by_topic', null, json_encode([$L1]));
    $createdUserIds[] = $uQ;
    // Seed expired grant
    mysqli_query($conn, "INSERT INTO access_grants
        (user_id, source, payment_id, payment_item_id, content_type, content_id, content_label, starts_at, ends_at, status)
        VALUES ($uQ, 'admin_manual', NULL, NULL, 'lesson', $L1, 'expired', '2020-01-01 00:00:00', '2020-02-01 00:00:00', 'active')");
    $refQ = 'P7Q' . substr($ts, -8) . 'ZZ';
    $subQ = p7_submit($conn, $uQ, 'by_topic', null, [$L1], $refQ, $createdPaymentIds, $proofFiles);
    $amtQ = (int) ($subQ['payment']['expected_amount_centavos'] ?? 0);
    p7_set_ocr(p7_receipt(number_format($amtQ / 100, 2, '.', ','), $refQ, $GCASH_NAME, $paidAt));
    commerce_verify_payment($conn, (int) $subQ['payment_id']);
    $gQ = mysqli_fetch_assoc(mysqli_query($conn, "SELECT starts_at, ends_at FROM access_grants WHERE user_id=$uQ AND payment_id=" . (int) $subQ['payment_id'] . ' LIMIT 1'));
    $startsQ = strtotime((string) ($gQ['starts_at'] ?? ''));
    $endsQ = strtotime((string) ($gQ['ends_at'] ?? ''));
    $mark('Q', $startsQ && $endsQ && $startsQ >= time() - 120 && $endsQ > $startsQ + (5 * 30 * 86400 / 2), 'starts near now, ~6 months ahead');

    // ---------- R duplicate fulfill ----------
    $beforeR = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE payment_id=' . (int) $subA['payment_id']))[0] ?? 0);
    $r2 = commerce_fulfill_payment($conn, (int) $subA['payment_id']);
    $afterR = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE payment_id=' . (int) $subA['payment_id']))[0] ?? 0);
    $mark('R', !empty($r2['ok']) && !empty($r2['skipped']) && $beforeR === $afterR, "before=$beforeR after=$afterR");

    // ---------- V approved access_end not shortened ----------
    $uV = p7_user($conn, "phase7.v.{$ts}@example.com", 'package', $fullId, null, 'approved');
    $createdUserIds[] = $uV;
    $farEnd = date('Y-m-d H:i:s', time() + 400 * 86400);
    mysqli_query($conn, "UPDATE users SET access_end='$farEnd' WHERE user_id=$uV");
    $refV = 'P7V' . substr($ts, -8) . 'ZZ';
    $subV = p7_submit($conn, $uV, 'package', $fullId, null, $refV, $createdPaymentIds, $proofFiles);
    p7_set_ocr(p7_receipt('1,500.00', $refV, $GCASH_NAME, $paidAt));
    commerce_verify_payment($conn, (int) $subV['payment_id']);
    $endV = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=$uV"))['access_end'] ?? '');
    $mark('V', $endV === $farEnd || strtotime($endV) >= strtotime($farEnd), "access_end=$endV");

    // ---------- W/X/Y By Topic bulk + new lesson appears ----------
    // Count lessons in admin source query
    $adminCountBefore = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons l INNER JOIN subjects s ON s.subject_id=l.subject_id'))[0] ?? 0);
    // Simulate bulk update on L1,L2
    $bulkPrice = 25000;
    $bulkDur = 30;
    $stmt = mysqli_prepare($conn, "UPDATE lessons SET price_centavos=?, access_duration_value=?, access_duration_unit='day', is_purchasable=1, purchasable_updated_at=NOW() WHERE lesson_id=?");
    foreach ([$L1, $L2] as $lid) {
        mysqli_stmt_bind_param($stmt, 'iii', $bulkPrice, $bulkDur, $lid);
        mysqli_stmt_execute($stmt);
    }
    mysqli_stmt_close($stmt);
    $rowX = mysqli_fetch_assoc(mysqli_query($conn, "SELECT price_centavos, access_duration_value, is_purchasable FROM lessons WHERE lesson_id=$L1"));
    $mark('X', (int) $rowX['price_centavos'] === 25000 && (int) $rowX['access_duration_value'] === 30 && (int) $rowX['is_purchasable'] === 1, json_encode($rowX));
    $lessonsAfter = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons'))[0] ?? 0);
    $mark('Y', $lessonsAfter === $baseLessons, "lessons=$lessonsAfter base=$baseLessons (no new topic rows)");
    // W: insert a temporary lesson then confirm it would appear in SELECT (then delete)
    $sid = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT subject_id FROM subjects WHERE status='active' LIMIT 1"))[0] ?? 0);
    mysqli_query($conn, "INSERT INTO lessons (subject_id, title, description) VALUES ($sid, 'P7 Temp Lesson $ts', 'temp')");
    $tempLesson = (int) mysqli_insert_id($conn);
    $appears = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lessons l INNER JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=$tempLesson"))[0] ?? 0);
    mysqli_query($conn, "DELETE FROM lessons WHERE lesson_id=$tempLesson LIMIT 1");
    $mark('W', $appears === 1 && $tempLesson > 0, "temp appeared=$appears then removed");

    // Failed OCR with proof CAN be manually approved (admin override) → fulfill
    $okApproveFailed = commerce_manual_approve_payment($conn, (int) $subE['payment_id'], $adminId, 'ocr failed but receipt ok');
    $payE2 = commerce_get_payment($conn, (int) $subE['payment_id']);
    $mark(
        'E_MANUAL_APPROVE_FAILED',
        !empty($okApproveFailed['ok'])
            && ($payE2['verification_status'] ?? '') === 'manually_approved'
            && ($payE2['status'] ?? '') === 'paid'
            && !empty($payE2['fulfilled_at'])
            && p7_grant_count($conn, $uE) >= 1,
        ($okApproveFailed['error'] ?? '') . ' v=' . ($payE2['verification_status'] ?? '')
    );
    // Awaiting proof still cannot be manually approved
    $badAwait = commerce_manual_approve_payment($conn, $pidF, $adminId, 'no proof');
    $mark(
        'F_NO_APPROVE_AWAITING_PROOF',
        empty($badAwait['ok']) && ($badAwait['error'] ?? '') === 'not_reviewable',
        ($badAwait['error'] ?? '')
    );

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// ---------- Cleanup ----------
$paymentIds = array_values(array_unique(array_filter(array_map('intval', $createdPaymentIds))));
if ($paymentIds !== []) {
    $in = implode(',', $paymentIds);
    mysqli_query($conn, "DELETE FROM access_grants WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_verification_attempts WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_gcash_references WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payments WHERE payment_id IN ($in)");
}

$userIds = array_values(array_unique(array_filter(array_map('intval', $createdUserIds))));
if ($userIds !== []) {
    $uin = implode(',', $userIds);
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($uin) AND email LIKE 'phase7.%@example.com'");
}

foreach ($createdPackageIds as $pid) {
    mysqli_query($conn, 'DELETE FROM package_content_items WHERE package_id=' . (int) $pid);
    mysqli_query($conn, 'DELETE FROM package_feature_items WHERE package_id=' . (int) $pid);
    mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pid . " AND code LIKE 'TEST_P7_%'");
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

$name = mysqli_real_escape_string($conn, (string) ($settingsBackup['gcash_account_name'] ?? ''));
$num = mysqli_real_escape_string($conn, (string) ($settingsBackup['gcash_number'] ?? ''));
$thr = (float) ($settingsBackup['ocr_confidence_threshold'] ?? 85);
$age = (int) ($settingsBackup['receipt_max_age_days'] ?? 7);
$vis = !empty($settingsBackup['vision_fallback_enabled']) ? 1 : 0;
mysqli_query($conn, "UPDATE payment_settings SET gcash_account_name='$name', gcash_number='$num', ocr_confidence_threshold=$thr, receipt_max_age_days=$age, vision_fallback_enabled=$vis WHERE setting_id=1");

$proofCleaned = 0;
foreach (array_unique($proofFiles) as $pf) {
    if (is_string($pf) && is_file($pf)) {
        $norm = str_replace('\\', '/', $pf);
        if (strpos($norm, '/payment_proofs/') !== false || strpos($norm, 'p7_') !== false || strpos($norm, sys_get_temp_dir()) !== false) {
            if (@unlink($pf)) {
                $proofCleaned++;
            }
        }
    }
}

$endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$endAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$endGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
$endLessons = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons'))[0] ?? 0);

$cleanupOk = $endPay === $basePay && $endItems === $baseItems && $endAttempts === $baseAttempts
    && $endGrants === $baseGrants && $endSca === $baseSca && $endPkg === $basePkg
    && $endFar === $baseFar && $endGcash === $baseGcash && $endLessons === $baseLessons;

$mark(
    'Z',
    $cleanupOk,
    "pay=$endPay/$basePay grants=$endGrants/$baseGrants sca=$endSca/$baseSca pkgs=$endPkg/$basePkg lessons=$endLessons/$baseLessons proofs=$proofCleaned"
);

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
