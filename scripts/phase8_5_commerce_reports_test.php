<?php
/**
 * Phase 8.5 — Commerce reports acceptance tests (A–AE), reversible.
 * Does not mutate Phase 8.1–8.4 algorithms. No migrations.
 */
declare(strict_types=1);

define('COMMERCE_NOTIFY_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_reports.php';
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

echo "=== Phase 8.5 commerce reports tests ===\n";

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
$createdPackageIds = [];
$ts = (string) time();

function p85_user(mysqli $conn, string $email, string $name = 'Phase85 Student'): int
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
function p85_package_payment(
    mysqli $conn,
    int $userId,
    string $ref,
    int $amount,
    string $status,
    string $vStatus,
    ?int $packageId,
    bool $fulfilled
): array {
    $fulfilledSql = $fulfilled ? 'NOW()' : 'NULL';
    $paidSql = ($status === 'paid') ? 'NOW()' : 'NULL';
    $pkg = $packageId;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO payments
          (payment_ref, user_id, purchase_type, package_id, expected_amount_centavos, status, verification_status, paid_at, fulfilled_at)
         VALUES (?, ?, 'package', ?, ?, ?, ?, {$paidSql}, {$fulfilledSql})"
    );
    mysqli_stmt_bind_param($stmt, 'siiiss', $ref, $userId, $pkg, $amount, $status, $vStatus);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('pay: ' . mysqli_error($conn));
    }
    $pid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $ins = mysqli_prepare(
        $conn,
        "INSERT INTO payment_items
          (payment_id, line_no, item_type, item_name, package_id, unit_amount_centavos, quantity, line_total_centavos,
           duration_value, duration_unit, package_access_scope)
         VALUES (?, 1, 'package', 'P85 Pkg', ?, ?, 1, ?, 6, 'month', 'full_lms')"
    );
    mysqli_stmt_bind_param($ins, 'iiii', $pid, $packageId, $amount, $amount);
    if (!mysqli_stmt_execute($ins)) {
        throw new RuntimeException('item: ' . mysqli_error($conn));
    }
    $iid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    return ['payment_id' => $pid, 'payment_item_id' => $iid];
}

/** @return array{payment_id:int,item_ids:list<int>} */
function p85_by_topic_payment(mysqli $conn, int $userId, string $ref, int $amount, array $lessonIds): array
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO payments
          (payment_ref, user_id, purchase_type, expected_amount_centavos, status, verification_status, paid_at, fulfilled_at)
         VALUES (?, ?, 'by_topic', ?, 'paid', 'auto_verified', NOW(), NULL)"
    );
    mysqli_stmt_bind_param($stmt, 'sii', $ref, $userId, $amount);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('bt pay: ' . mysqli_error($conn));
    }
    $pid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $n = max(1, count($lessonIds));
    $each = (int) floor($amount / $n);
    $itemIds = [];
    $line = 1;
    foreach ($lessonIds as $lid) {
        $lineTotal = ($line === $n) ? ($amount - $each * ($n - 1)) : $each;
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO payment_items
              (payment_id, line_no, item_type, item_name, lesson_id, unit_amount_centavos, quantity, line_total_centavos,
               duration_value, duration_unit)
             VALUES (?, ?, 'lesson', 'P85 Lesson', ?, ?, 1, ?, 30, 'day')"
        );
        mysqli_stmt_bind_param($ins, 'iiiii', $pid, $line, $lid, $lineTotal, $lineTotal);
        if (!mysqli_stmt_execute($ins)) {
            throw new RuntimeException('bt item: ' . mysqli_error($conn));
        }
        $itemIds[] = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        $line++;
    }
    return ['payment_id' => $pid, 'item_ids' => $itemIds];
}

function p85_far(mysqli $conn, int $userId, string $ref, string $status = 'pending'): int
{
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO free_access_requests (request_ref, user_id, status, student_note) VALUES (?, ?, ?, \'note\')'
    );
    mysqli_stmt_bind_param($stmt, 'sis', $ref, $userId, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('far: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p85_grant(
    mysqli $conn,
    int $userId,
    string $source,
    ?int $paymentId,
    ?int $paymentItemId,
    ?int $farId,
    string $status,
    string $startsSql,
    string $endsSql
): int {
    $sql = "INSERT INTO access_grants
              (user_id, source, payment_id, payment_item_id, free_access_request_id,
               content_type, content_id, content_label, starts_at, ends_at, status)
             VALUES (
               " . (int) $userId . ",
               '" . mysqli_real_escape_string($conn, $source) . "',
               " . ($paymentId === null ? 'NULL' : (int) $paymentId) . ",
               " . ($paymentItemId === null ? 'NULL' : (int) $paymentItemId) . ",
               " . ($farId === null ? 'NULL' : (int) $farId) . ",
               'full_lms', 0, 'P85',
               {$startsSql}, {$endsSql},
               '" . mysqli_real_escape_string($conn, $status) . "'
             )";
    if (!mysqli_query($conn, $sql)) {
        throw new RuntimeException('grant: ' . mysqli_error($conn));
    }
    return (int) mysqli_insert_id($conn);
}

function p85_empty_filters(): array
{
    return commerce_reports_parse_filters([]);
}

try {
    // ---------- A metrics match current baseline (allow pre-existing unpaid leftovers; do not require zero DB) ----------
    $m0 = commerce_reports_payment_metrics($conn, p85_empty_filters());
    $mark(
        'A',
        (int) $m0['total'] === $basePay
            && (int) $m0['paid_gmv_centavos'] >= 0,
        "total={$m0['total']} gmv={$m0['paid_gmv_centavos']} basePay=$basePay"
    );

    $pkgId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT package_id FROM sellable_packages ORDER BY package_id LIMIT 1"))[0] ?? 0);
    if ($pkgId <= 0) {
        mysqli_query(
            $conn,
            "INSERT INTO sellable_packages (code, name, price_centavos, duration_value, duration_unit, access_scope, is_active, is_purchasable)
             VALUES ('TEST_P85_{$ts}', 'P85 Package', 15000, 6, 'month', 'full_lms', 1, 1)"
        );
        $pkgId = (int) mysqli_insert_id($conn);
        $createdPackageIds[] = $pkgId;
    }

    $lessons = [];
    $lr = mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id ASC LIMIT 3');
    while ($lr && ($row = mysqli_fetch_assoc($lr))) {
        $lessons[] = (int) $row['lesson_id'];
    }
    if (count($lessons) < 3) {
        throw new RuntimeException('Need at least 3 lessons for By Topic multi-item test');
    }

    // ---------- B/C/D/E paid package + by_topic with 3 items ----------
    $uB = p85_user($conn, "p85.b.{$ts}@example.com", 'Phase85 PackageBuyer');
    $createdUserIds[] = $uB;
    $payB = p85_package_payment($conn, $uB, "PAY-P85-B-{$ts}", 15000, 'paid', 'auto_verified', $pkgId, true);
    $createdPaymentIds[] = $payB['payment_id'];

    $uC = p85_user($conn, "p85.c.{$ts}@example.com", 'Phase85 TopicBuyer');
    $createdUserIds[] = $uC;
    $payC = p85_by_topic_payment($conn, $uC, "PAY-P85-C-{$ts}", 9000, $lessons);
    $createdPaymentIds[] = $payC['payment_id'];
    $itemCnt = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items WHERE payment_id=' . (int) $payC['payment_id']))[0] ?? 0);

    $m1 = commerce_reports_payment_metrics($conn, p85_empty_filters());
    $mark('B', (int) $m1['paid'] === 2 && (int) $m1['package_count'] >= 1, 'paid=' . $m1['paid']);
    $mark('C', $itemCnt === 3 && (int) $m1['by_topic_count'] === 1, "items=$itemCnt by_topic={$m1['by_topic_count']}");
    $mark('D', (int) $m1['paid_gmv_centavos'] === 24000, 'gmv=' . $m1['paid_gmv_centavos']);
    $mark(
        'E',
        (int) $m1['package_gmv_centavos'] === 15000 && (int) $m1['by_topic_gmv_centavos'] === 9000,
        "pkgGMV={$m1['package_gmv_centavos']} topicGMV={$m1['by_topic_gmv_centavos']}"
    );

    // ---------- F fulfilled vs paid-unfulfilled ----------
    $mark(
        'F',
        (int) $m1['fulfilled'] === 1 && (int) $m1['paid_unfulfilled'] === 1,
        "fulfilled={$m1['fulfilled']} unfulfilled={$m1['paid_unfulfilled']}"
    );

    // ---------- G needs_review ----------
    $uG = p85_user($conn, "p85.g.{$ts}@example.com");
    $createdUserIds[] = $uG;
    $payG = p85_package_payment($conn, $uG, "PAY-P85-G-{$ts}", 5000, 'pending_verification', 'needs_review', $pkgId, false);
    $createdPaymentIds[] = $payG['payment_id'];
    $mG = commerce_reports_payment_metrics($conn, p85_empty_filters());
    $mark('G', (int) $mG['needs_review'] === 1 && (int) $mG['v_needs_review'] === 1, 'needs_review=' . $mG['needs_review']);

    // ---------- H/I grant counts + overdue ----------
    $gActive = p85_grant($conn, $uB, 'purchase', $payB['payment_id'], $payB['payment_item_id'], null, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)');
    $createdGrantIds[] = $gActive;
    $gExpired = p85_grant(
        $conn,
        $uC,
        'purchase',
        $payC['payment_id'],
        $payC['item_ids'][0] ?? null,
        null,
        'expired',
        'DATE_SUB(NOW(), INTERVAL 3 MONTH)',
        'DATE_SUB(NOW(), INTERVAL 1 MONTH)'
    );
    $createdGrantIds[] = $gExpired;
    // Use second item for revoked to avoid unique collision
    $gRevoked = p85_grant(
        $conn,
        $uC,
        'purchase',
        $payC['payment_id'],
        $payC['item_ids'][1] ?? null,
        null,
        'revoked',
        'NOW()',
        'DATE_ADD(NOW(), INTERVAL 1 MONTH)'
    );
    $createdGrantIds[] = $gRevoked;
    $gOverdue = p85_grant(
        $conn,
        $uC,
        'purchase',
        $payC['payment_id'],
        $payC['item_ids'][2] ?? null,
        null,
        'active',
        'DATE_SUB(NOW(), INTERVAL 2 MONTH)',
        'DATE_SUB(NOW(), INTERVAL 1 DAY)'
    );
    $createdGrantIds[] = $gOverdue;

    $gm = commerce_reports_grant_metrics($conn, p85_empty_filters());
    $mark(
        'H',
        (int) $gm['purchase_active'] >= 1
            && (int) $gm['purchase_expired'] >= 1
            && (int) $gm['purchase_revoked'] >= 1,
        "a={$gm['purchase_active']} e={$gm['purchase_expired']} r={$gm['purchase_revoked']}"
    );
    $mark('I', (int) $gm['purchase_overdue_active'] >= 1, 'overdue=' . $gm['purchase_overdue_active']);

    // ---------- J/K Free Access excluded from GMV ----------
    $uJ = p85_user($conn, "p85.j.{$ts}@example.com");
    $createdUserIds[] = $uJ;
    $farJ = p85_far($conn, $uJ, "FAR-P85-J-{$ts}", 'approved');
    $createdFarIds[] = $farJ;
    $gFar = p85_grant($conn, $uJ, 'free_access', null, null, $farJ, 'active', 'NOW()', 'DATE_ADD(NOW(), INTERVAL 2 MONTH)');
    $createdGrantIds[] = $gFar;
    $farPending = p85_far($conn, $uJ, "FAR-P85-P-{$ts}", 'pending');
    $createdFarIds[] = $farPending;
    $mJ = commerce_reports_payment_metrics($conn, p85_empty_filters());
    $farM = commerce_reports_far_metrics($conn);
    $mark('J', (int) $mJ['paid_gmv_centavos'] === 24000, 'gmv unchanged=' . $mJ['paid_gmv_centavos']);
    $mark('K', (int) $farM['approved'] >= 1 && (int) $farM['pending'] >= 1, "far a={$farM['approved']} p={$farM['pending']}");

    // ---------- L date filter ----------
    $filtL = commerce_reports_parse_filters([
        'date_from' => date('Y-m-d'),
        'date_to' => date('Y-m-d'),
    ]);
    $mL = commerce_reports_payment_metrics($conn, $filtL);
    $mark('L', empty($filtL['warnings']) && (int) $mL['total'] >= 3, 'today total=' . $mL['total']);

    // ---------- M student filter ----------
    $filtM = commerce_reports_parse_filters(['student' => 'PackageBuyer']);
    $mM = commerce_reports_payment_metrics($conn, $filtM);
    $mark('M', (int) $mM['total'] === 1 && (int) $mM['paid_gmv_centavos'] === 15000, 'student total=' . $mM['total']);

    // ---------- N payment_ref ----------
    $filtN = commerce_reports_parse_filters(['payment_ref' => 'PAY-P85-C-']);
    $mN = commerce_reports_payment_metrics($conn, $filtN);
    $mark('N', (int) $mN['total'] === 1 && (int) $mN['by_topic_count'] === 1, 'ref total=' . $mN['total']);

    // ---------- O package ----------
    $filtO = commerce_reports_parse_filters(['package_id' => (string) $pkgId]);
    $mO = commerce_reports_payment_metrics($conn, $filtO);
    $mark('O', (int) $mO['package_count'] >= 1 && (int) $mO['by_topic_count'] === 0, 'pkg=' . $mO['package_count']);

    // ---------- P lesson EXISTS without GMV multiply ----------
    $lessonFilter = $lessons[0];
    $filtP = commerce_reports_parse_filters(['lesson_id' => (string) $lessonFilter]);
    $mP = commerce_reports_payment_metrics($conn, $filtP);
    $mark(
        'P',
        (int) $mP['total'] === 1 && (int) $mP['paid_gmv_centavos'] === 9000 && (int) $mP['by_topic_count'] === 1,
        "lesson total={$mP['total']} gmv={$mP['paid_gmv_centavos']}"
    );

    // ---------- Q invalid ENUM ----------
    $filtQ = commerce_reports_parse_filters(['status' => 'not_a_real_status', 'verification_status' => 'bogus', 'purchase_type' => 'hack']);
    $mark(
        'Q',
        ($filtQ['status'] ?? null) === null
            && ($filtQ['verification_status'] ?? null) === null
            && ($filtQ['purchase_type'] ?? null) === null
            && count($filtQ['warnings']) >= 3,
        'warnings=' . count($filtQ['warnings'])
    );

    // ---------- R injection-style values ----------
    $filtR = commerce_reports_parse_filters([
        'status' => "paid' OR '1'='1",
        'payment_ref' => "x%' OR 1=1 --",
        'student' => "x%' OR 1=1 --",
    ]);
    $mR = commerce_reports_payment_metrics($conn, $filtR);
    $mark(
        'R',
        ($filtR['status'] ?? null) === null && (int) $mR['paid_gmv_centavos'] === 0,
        'inj gmv=' . $mR['paid_gmv_centavos']
    );

    // ---------- S GET-only / no mutation helpers in reports module ----------
    $src = file_get_contents(dirname(__DIR__) . '/includes/commerce_reports.php');
    $adminSrc = file_get_contents(dirname(__DIR__) . '/admin_commerce_reports.php');
    $mark(
        'S',
        strpos($src, 'mysqli_begin_transaction') === false
            && preg_match('/\b(UPDATE|DELETE|INSERT)\b/i', $src) !== 1
            && strpos($adminSrc, 'REQUEST_METHOD') !== false
            && strpos($adminSrc, 'method="post"') === false,
        'read-only reports module'
    );

    // ---------- T admin-only structural ----------
    $mark(
        'T',
        strpos($adminSrc, "requireRole('admin')") !== false
            && strpos($adminSrc, 'commerce_schema_ready') !== false,
        'admin gate'
    );

    // ---------- U no OCR/proof exposure ----------
    $mark(
        'U',
        strpos($adminSrc, 'ocr_raw') === false
            && strpos($adminSrc, 'proof_path') === false
            && strpos($adminSrc, 'ocr_extracted') === false
            && strpos($src, 'ocr_raw') === false,
        'no OCR/proof fields'
    );

    // ---------- V recent lists max 20 ----------
    $recent = commerce_reports_recent_payments($conn, p85_empty_filters(), 20);
    $recentFar = commerce_reports_recent_far($conn, 20);
    $mark('V', count($recent) <= 20 && count($recentFar) <= 20, 'payments=' . count($recent) . ' far=' . count($recentFar));

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup
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
if ($createdPackageIds !== []) {
    $ids = implode(',', array_map('intval', $createdPackageIds));
    mysqli_query($conn, "DELETE FROM package_content_items WHERE package_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM package_feature_items WHERE package_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM sellable_packages WHERE package_id IN ($ids)");
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

$runReg('W', __DIR__ . '/phase8_4_commerce_notifications_test.php');
$runReg('X', __DIR__ . '/phase8_3_paid_revoke_test.php');
$runReg('Y', __DIR__ . '/phase8_2_expiry_reconcile_test.php');
$runReg('Z', __DIR__ . '/phase8_1_free_access_test.php');
$runReg('AA', __DIR__ . '/phase8_1_idempotency_hardening_test.php');
$runReg('AB', __DIR__ . '/phase7_fulfillment_test.php');
$runReg('AC', __DIR__ . '/activation_commerce_sca_hardening_test.php');
$runReg('AD', __DIR__ . '/student_access_commerce_sca_hardening_test.php');

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
$mark('AE', $cleanupOk, "baseline pay {$basePay}->{$endPay} grants {$baseGrants}->{$endGrants} sca {$baseSca}->{$endSca}");

$failed = 0;
foreach ($results as $r) {
    if (empty($r['ok'])) {
        $failed++;
    }
}
echo "=== Phase 8.5 summary: " . (count($results) - $failed) . ' passed, ' . $failed . " failed ===\n";
exit($failed > 0 ? 1 : 0);
