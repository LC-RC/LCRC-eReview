<?php
/**
 * Phase 5 - reversible payment foundation acceptance tests (A-T).
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_payment.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " - $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 5 payment foundation tests ===\n";

$basePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$baseItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$basePurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
$baseFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$baseGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);

echo "Baseline pay=$basePay items=$baseItems grants=$baseGrants sca=$baseSca pkgs=$basePkg\n";

$createdUserIds = [];
$createdPackageIds = [];
$createdPaymentIds = [];
$proofFiles = [];
$lessonBuy = 0;
$lessonOff = 0;
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
$lessonBuy = $lessonRows[0];
$lessonOff = $lessonRows[1];

$snapStmt = mysqli_prepare($conn, 'SELECT lesson_id, price_centavos, access_duration_value, access_duration_unit, is_purchasable FROM lessons WHERE lesson_id IN (?,?)');
mysqli_stmt_bind_param($snapStmt, 'ii', $lessonBuy, $lessonOff);
mysqli_stmt_execute($snapStmt);
$snapRes = mysqli_stmt_get_result($snapStmt);
while ($snapRes && ($r = mysqli_fetch_assoc($snapRes))) {
    $lessonSnap[(int) $r['lesson_id']] = $r;
}
mysqli_stmt_close($snapStmt);

function p5_create_user(mysqli $conn, string $email, string $path, ?int $pkgId, ?string $lessonsJson): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase5 Test';
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
        throw new RuntimeException('user insert failed: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p5_tiny_png(string $path): void
{
    $bin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $bin);
}

try {
    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P5_FULL', 'Phase5 Full LMS', 'test', 150000, 'PHP', 6, 'month', 'full_lms', 1, 1, 1)");
    $fullId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $fullId;

    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P5_MAP', 'Phase5 Mapped', 'test', 50000, 'PHP', 30, 'day', 'mapped', 1, 1, 2)");
    $mapId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $mapId;
    $ins = mysqli_prepare($conn, "INSERT INTO package_content_items (package_id, content_type, content_id, sort_order) VALUES (?, 'lesson', ?, 0)");
    mysqli_stmt_bind_param($ins, 'ii', $mapId, $lessonBuy);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    mysqli_query($conn, "UPDATE lessons SET price_centavos=25000, access_duration_value=30, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=" . (int) $lessonBuy);
    mysqli_query($conn, "UPDATE lessons SET price_centavos=15000, access_duration_value=14, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=" . (int) $lessonOff);

    $uPkg = p5_create_user($conn, 'phase5.pkg.' . time() . '@example.com', 'package', $fullId, null);
    $createdUserIds[] = $uPkg;
    $uTopic = p5_create_user($conn, 'phase5.topic.' . time() . '@example.com', 'by_topic', null, json_encode([$lessonBuy]));
    $createdUserIds[] = $uTopic;
    $uMulti = p5_create_user($conn, 'phase5.multi.' . time() . '@example.com', 'by_topic', null, json_encode([$lessonBuy, $lessonOff]));
    $createdUserIds[] = $uMulti;
    $uFree = p5_create_user($conn, 'phase5.free.' . time() . '@example.com', 'free_access', null, null);
    $createdUserIds[] = $uFree;

    // A - Full LMS package payment
    $a = commerce_create_or_resume_checkout($conn, $uPkg, 'package', $fullId, null);
    $mark('A', !empty($a['ok']) && (string) $a['payment']['purchase_type'] === 'package'
        && (int) $a['payment']['expected_amount_centavos'] === 150000
        && (string) $a['payment']['status'] === 'awaiting_proof',
        $a['error'] ?? ('ref=' . ($a['payment']['payment_ref'] ?? '')));
    if (!empty($a['payment']['payment_id'])) {
        $createdPaymentIds[] = (int) $a['payment']['payment_id'];
        $itemsA = commerce_get_payment_items($conn, (int) $a['payment']['payment_id']);
        $mark('A_ITEMS', count($itemsA) === 1 && ($itemsA[0]['package_access_scope'] ?? '') === 'full_lms'
            && ($itemsA[0]['package_content_snapshot_json'] === '[]' || $itemsA[0]['package_content_snapshot_json'] === []),
            'lines=' . count($itemsA));
    }

    // B - single topic
    $b = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonBuy]);
    $mark('B', !empty($b['ok']) && (int) $b['payment']['expected_amount_centavos'] === 25000, $b['error'] ?? '');
    if (!empty($b['payment']['payment_id'])) {
        $createdPaymentIds[] = (int) $b['payment']['payment_id'];
    }

    // C - multi topic
    $c = commerce_create_or_resume_checkout($conn, $uMulti, 'by_topic', null, [$lessonBuy, $lessonOff]);
    $mark('C', !empty($c['ok']) && (int) $c['payment']['expected_amount_centavos'] === 40000
        && count(commerce_get_payment_items($conn, (int) $c['payment']['payment_id'])) === 2, $c['error'] ?? '');
    if (!empty($c['payment']['payment_id'])) {
        $createdPaymentIds[] = (int) $c['payment']['payment_id'];
    }

    // D - Free Access: no payment helpers; create FAR only
    $payBeforeFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $freeCheckout = commerce_create_or_resume_checkout_for_user($conn, $uFree);
    $ref = commerce_next_free_access_ref($conn);
    $far = mysqli_prepare($conn, "INSERT INTO free_access_requests (request_ref, user_id, status, student_note) VALUES (?, ?, 'pending', 'Phase5 free')");
    mysqli_stmt_bind_param($far, 'si', $ref, $uFree);
    mysqli_stmt_execute($far);
    mysqli_stmt_close($far);
    $payAfterFree = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $mark('D', empty($freeCheckout['ok']) && $payAfterFree === $payBeforeFree, $freeCheckout['error'] ?? 'blocked');
    $mark('Q_FREE', $payAfterFree === $payBeforeFree, 'payments unchanged for free access');

    // E-G - price/duration reflected on NEW payment after catalog change
    mysqli_query($conn, "UPDATE sellable_packages SET price_centavos=175000, duration_value=9 WHERE package_id=" . (int) $fullId);
    // Close previous open package payment so a new one is created with new snapshot
    mysqli_query($conn, "UPDATE payments SET status='cancelled' WHERE payment_id=" . (int) $a['payment']['payment_id']);
    $e = commerce_create_or_resume_checkout($conn, $uPkg, 'package', $fullId, null);
    $mark('E', !empty($e['ok']) && (int) $e['payment']['expected_amount_centavos'] === 175000, 'amt=' . (int) ($e['payment']['expected_amount_centavos'] ?? 0));
    if (!empty($e['payment']['payment_id'])) {
        $createdPaymentIds[] = (int) $e['payment']['payment_id'];
        $eItems = commerce_get_payment_items($conn, (int) $e['payment']['payment_id']);
        $mark('F', !empty($eItems[0]) && (int) $eItems[0]['duration_value'] === 9, 'duration=' . (int) ($eItems[0]['duration_value'] ?? 0));
    }
    mysqli_query($conn, "UPDATE lessons SET price_centavos=33300 WHERE lesson_id=" . (int) $lessonBuy);
    mysqli_query($conn, "UPDATE payments SET status='cancelled' WHERE payment_id=" . (int) $b['payment']['payment_id']);
    $g = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonBuy]);
    $mark('G', !empty($g['ok']) && (int) $g['payment']['expected_amount_centavos'] === 33300, 'amt=' . (int) ($g['payment']['expected_amount_centavos'] ?? 0));
    if (!empty($g['payment']['payment_id'])) {
        $createdPaymentIds[] = (int) $g['payment']['payment_id'];
    }

    // H - inactive package rejected
    mysqli_query($conn, "UPDATE sellable_packages SET is_active=0 WHERE package_id=" . (int) $fullId);
    $h = commerce_create_or_resume_checkout($conn, $uPkg, 'package', $fullId, null);
    $mark('H', empty($h['ok']), $h['error'] ?? 'ok unexpectedly');
    mysqli_query($conn, "UPDATE sellable_packages SET is_active=1, is_purchasable=1 WHERE package_id=" . (int) $fullId);

    // I - non-purchasable topic rejected
    mysqli_query($conn, "UPDATE lessons SET is_purchasable=0 WHERE lesson_id=" . (int) $lessonBuy);
    $i = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonBuy]);
    $mark('I', empty($i['ok']), $i['error'] ?? 'ok unexpectedly');
    mysqli_query($conn, "UPDATE lessons SET is_purchasable=1, price_centavos=33300 WHERE lesson_id=" . (int) $lessonBuy);

    // P - resume same open selection
    $p1 = commerce_create_or_resume_checkout($conn, $uMulti, 'by_topic', null, [$lessonBuy, $lessonOff]);
    $p2 = commerce_create_or_resume_checkout($conn, $uMulti, 'by_topic', null, [$lessonBuy, $lessonOff]);
    $mark('P', !empty($p1['ok']) && !empty($p2['ok']) && (int) $p1['payment']['payment_id'] === (int) $p2['payment']['payment_id'] && !empty($p2['resumed']),
        'id=' . (int) ($p1['payment']['payment_id'] ?? 0));

    // O + submit proof
    $tmpProof = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p5_proof_' . bin2hex(random_bytes(4)) . '.png';
    p5_tiny_png($tmpProof);
    $payIdForProof = (int) $g['payment']['payment_id'];
    $userForProof = $uTopic;
    $ref1 = 'GCASHREF' . strtoupper(bin2hex(random_bytes(4)));
    $sub1 = commerce_submit_payment_proof_and_reference($conn, $payIdForProof, $userForProof, $ref1, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmpProof,
        'size' => filesize($tmpProof),
        'name' => 'evil.php.png',
        'type' => 'image/png',
    ]);
    $payAfterSub = commerce_get_payment($conn, $payIdForProof);
    $mark('O', !empty($sub1['ok']) && str_starts_with((string) ($payAfterSub['proof_path'] ?? ''), 'uploads/payment_proofs/')
        && (string) ($payAfterSub['status'] ?? '') === 'pending_verification'
        && (string) ($payAfterSub['verification_status'] ?? '') === 'not_started',
        (string) ($payAfterSub['proof_path'] ?? ($sub1['error'] ?? '')));
    if (!empty($payAfterSub['proof_path'])) {
        $proofFiles[] = dirname(__DIR__) . '/' . $payAfterSub['proof_path'];
    }

    // J - duplicate GCash reference hard reject
    $uDup = p5_create_user($conn, 'phase5.dup.' . time() . '@example.com', 'by_topic', null, json_encode([$lessonOff]));
    $createdUserIds[] = $uDup;
    $dupPay = commerce_create_or_resume_checkout($conn, $uDup, 'by_topic', null, [$lessonOff]);
    if (!empty($dupPay['payment']['payment_id'])) {
        $createdPaymentIds[] = (int) $dupPay['payment']['payment_id'];
    }
    $tmpProof2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p5_proof2_' . bin2hex(random_bytes(4)) . '.png';
    p5_tiny_png($tmpProof2);
    $j = commerce_submit_payment_proof_and_reference($conn, (int) $dupPay['payment']['payment_id'], $uDup, '  ' . strtolower($ref1) . ' - ', [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmpProof2,
        'size' => filesize($tmpProof2),
        'name' => 'x.png',
        'type' => 'image/png',
    ]);
    $dupFlag = commerce_get_payment($conn, (int) $dupPay['payment']['payment_id']);
    $mark('J', empty($j['ok']) && (int) ($dupFlag['duplicate_reference'] ?? 0) === 1
        && (string) ($dupFlag['status'] ?? '') === 'awaiting_proof', $j['error'] ?? '');

    // Q - same payment retry with own reference is idempotent
    $q = commerce_submit_payment_proof_and_reference($conn, $payIdForProof, $userForProof, $ref1, null);
    $mark('Q', !empty($q['ok']) && !empty($q['idempotent']), $q['error'] ?? 'idempotent');

    // K-N - repeat purchase after closing prior payment; old history untouched
    $oldId = (int) $g['payment']['payment_id'];
    $oldAmt = (int) $g['payment']['expected_amount_centavos'];
    $oldItemsCount = count(commerce_get_payment_items($conn, $oldId));
    // already pending_verification - create NEW purchase for same topic requires different open logic:
    // pending_verification is still "open" so resume would return same. Mark paid first to allow new purchase.
    mysqli_query($conn, "UPDATE payments SET status='paid', paid_at=NOW() WHERE payment_id=" . $oldId);
    // Keep gcash lock - new payment uses new ref
    $k = commerce_create_or_resume_checkout($conn, $uTopic, 'by_topic', null, [$lessonBuy]);
    $mark('K', !empty($k['ok']) && (int) $k['payment']['payment_id'] !== $oldId, 'new=' . (int) ($k['payment']['payment_id'] ?? 0));
    if (!empty($k['payment']['payment_id'])) {
        $createdPaymentIds[] = (int) $k['payment']['payment_id'];
    }
    $mark('L', !empty($k['ok']) && (int) $k['payment']['payment_id'] > $oldId, 'new payment');
    $mark('M', count(commerce_get_payment_items($conn, (int) $k['payment']['payment_id'])) === 1, 'new items');
    $oldAfter = commerce_get_payment($conn, $oldId);
    $mark('N', $oldAfter && (int) $oldAfter['expected_amount_centavos'] === $oldAmt
        && (string) $oldAfter['status'] === 'paid'
        && count(commerce_get_payment_items($conn, $oldId)) === $oldItemsCount, 'history intact');

    // R / S
    $endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
    $mark('R', $endGrants === $baseGrants, "grants=$endGrants");
    $mark('S', $endSca === $baseSca, "sca=$endSca");

    // T - login/activation files untouched (presence + no Phase5 edits implied); smoke helpers
    $mark('T', file_exists(__DIR__ . '/../activate_user.php') && file_exists(__DIR__ . '/../login_process.php')
        && !str_contains((string) file_get_contents(__DIR__ . '/../activate_user.php'), 'commerce_payment')
        && !str_contains((string) file_get_contents(__DIR__ . '/../login_process.php'), 'commerce_payment'),
        'activate/login untouched');

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . PHP_EOL;
    $mark('EXCEPTION', false, $e->getMessage());
} finally {
    echo "\n=== CLEANUP ===\n";
    // GCash refs then items then payments
    $payIds = array_values(array_unique(array_filter($createdPaymentIds)));
    if ($payIds !== []) {
        $in = implode(',', array_map('intval', $payIds));
        mysqli_query($conn, "DELETE FROM payment_gcash_references WHERE payment_id IN ($in)");
        mysqli_query($conn, "DELETE FROM payment_verification_attempts WHERE payment_id IN ($in)");
        mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id IN ($in)");
        mysqli_query($conn, "DELETE FROM payments WHERE payment_id IN ($in)");
    }
    mysqli_query($conn, "DELETE FROM payments WHERE payment_ref LIKE 'PAY-%' AND user_id IN (SELECT user_id FROM users WHERE email LIKE 'phase5.%')");
    // leftover
    $uq = mysqli_query($conn, "SELECT user_id FROM users WHERE email LIKE 'phase5.%@example.com'");
    $uids = [];
    while ($uq && ($r = mysqli_fetch_assoc($uq))) {
        $uids[] = (int) $r['user_id'];
    }
    foreach (array_unique(array_merge($createdUserIds, $uids)) as $uid) {
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
        mysqli_query($conn, 'DELETE FROM users WHERE user_id=' . (int) $uid . " AND email LIKE 'phase5.%'");
    }

    foreach ($createdPackageIds as $pid) {
        mysqli_query($conn, 'DELETE FROM package_content_items WHERE package_id=' . (int) $pid);
        mysqli_query($conn, 'DELETE FROM package_feature_items WHERE package_id=' . (int) $pid);
        mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pid);
    }
    mysqli_query($conn, "DELETE FROM sellable_packages WHERE code LIKE 'TEST_P5_%'");

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
    // wipe any leftover phase5 proof files
    $proofDir = dirname(__DIR__) . '/uploads/payment_proofs';
    if (is_dir($proofDir)) {
        foreach (glob($proofDir . '/proof_*.png') ?: [] as $f) {
            // only delete if orphaned from this test run - safer: delete files modified in last hour matching proof_
            if (is_file($f) && (time() - filemtime($f)) < 3600) {
                // Don't delete non-test production proofs - test uses random names; only delete if no payment points to it
                $rel = 'uploads/payment_proofs/' . basename($f);
                $chk = mysqli_query($conn, "SELECT 1 FROM payments WHERE proof_path='" . mysqli_real_escape_string($conn, $rel) . "' LIMIT 1");
                if ($chk && !mysqli_fetch_row($chk)) {
                    @unlink($f);
                }
            }
        }
    }

    $endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
    $endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
    $endPurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
    $endGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
    $endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
    $cleanupOk = $endPay === $basePay && $endItems === $baseItems && $endGrants === $baseGrants
        && $endSca === $baseSca && $endPkg === $basePkg && $endPurch === $basePurch
        && $endGcash === $baseGcash && $endFar === $baseFar;
    out('CLEANUP', $cleanupOk, "pay=$endPay items=$endItems grants=$endGrants sca=$endSca pkgs=$endPkg purch=$endPurch gcash=$endGcash far=$endFar");
}

$failed = array_filter($results, static fn($r) => !$r['ok']);
echo "\nSummary: " . (count($results) - count($failed)) . '/' . count($results) . " passed\n";
exit($failed ? 1 : 0);
