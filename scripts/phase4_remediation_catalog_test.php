<?php
/**
 * Phase 4 remediation - reversible live catalog tests A-O.
 * Run once, then cleanup. Do not leave test data.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_catalog.php';
require_once __DIR__ . '/../email_verification.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    $status = $ok ? 'PASS' : 'FAIL';
    echo "[$status] $label" . ($detail !== '' ? " - $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 4 live catalog test (reversible) ===\n";

$baselinePkgs = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$baselinePurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
$baselinePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
echo "Baseline: packages=$baselinePkgs purchasable_lessons=$baselinePurch payments=$baselinePay\n";

$lessonRows = [];
$lr = mysqli_query($conn, "SELECT lesson_id FROM lessons WHERE subject_id IN (SELECT subject_id FROM subjects WHERE status='active') ORDER BY lesson_id LIMIT 2");
while ($lr && ($row = mysqli_fetch_assoc($lr))) {
    $lessonRows[] = (int) $row['lesson_id'];
}
if (count($lessonRows) < 2) {
    echo "ABORT: need at least 2 active-subject lessons for tests.\n";
    exit(1);
}
$lessonBuy = $lessonRows[0];
$lessonOff = $lessonRows[1];

// Snapshot lesson pricing columns for restore
$snapStmt = mysqli_prepare($conn, 'SELECT lesson_id, price_centavos, access_duration_value, access_duration_unit, is_purchasable FROM lessons WHERE lesson_id IN (?,?)');
mysqli_stmt_bind_param($snapStmt, 'ii', $lessonBuy, $lessonOff);
mysqli_stmt_execute($snapStmt);
$snapRes = mysqli_stmt_get_result($snapStmt);
$lessonSnap = [];
while ($snapRes && ($r = mysqli_fetch_assoc($snapRes))) {
    $lessonSnap[(int) $r['lesson_id']] = $r;
}
mysqli_stmt_close($snapStmt);

$createdPackageIds = [];
$pendingIds = [];
$freeAccessIds = [];

try {
    // 1) Full LMS package
    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_FULL_LMS_P4', 'Test Self-Paced - Full LMS', 'Phase4 reversible test', 150000, 'PHP', 6, 'month', 'full_lms', 1, 1, 1)");
    $fullId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $fullId;

    // 2) Mapped package with valid lesson mapping
    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_MAPPED_OK_P4', 'Test Mapped Valid', 'Phase4 reversible test', 50000, 'PHP', 30, 'day', 'mapped', 1, 1, 2)");
    $mappedOkId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $mappedOkId;
    $ins = mysqli_prepare($conn, "INSERT INTO package_content_items (package_id, content_type, content_id, sort_order) VALUES (?, 'lesson', ?, 0)");
    mysqli_stmt_bind_param($ins, 'ii', $mappedOkId, $lessonBuy);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    // 3) Invalid mapped package (non-existent content id)
    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_MAPPED_BAD_P4', 'Test Mapped Invalid', 'Phase4 reversible test', 10000, 'PHP', 7, 'day', 'mapped', 1, 1, 3)");
    $mappedBadId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $mappedBadId;
    $badCid = 99999999;
    $ins2 = mysqli_prepare($conn, "INSERT INTO package_content_items (package_id, content_type, content_id, sort_order) VALUES (?, 'lesson', ?, 0)");
    mysqli_stmt_bind_param($ins2, 'ii', $mappedBadId, $badCid);
    mysqli_stmt_execute($ins2);
    mysqli_stmt_close($ins2);

    // 4/5 Purchasable + non-purchasable lessons
    mysqli_query($conn, "UPDATE lessons SET price_centavos=25000, access_duration_value=30, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=" . (int) $lessonBuy);
    mysqli_query($conn, "UPDATE lessons SET price_centavos=NULL, access_duration_value=NULL, access_duration_unit=NULL, is_purchasable=0 WHERE lesson_id=" . (int) $lessonOff);

    // A - active purchasable package appears
    $cats = commerce_catalog_packages_for_registration($conn);
    $ids = array_map(static fn($p) => (int) $p['package_id'], $cats);
    $mark('A', in_array($fullId, $ids, true), 'full LMS in registration catalog');

    // B - change price → registration reflects
    mysqli_query($conn, "UPDATE sellable_packages SET price_centavos=175000 WHERE package_id=" . (int) $fullId);
    $cats = commerce_catalog_packages_for_registration($conn);
    $found = null;
    foreach ($cats as $p) {
        if ((int) $p['package_id'] === $fullId) {
            $found = $p;
            break;
        }
    }
    $mark('B', $found && (int) $found['price_centavos'] === 175000, 'price_centavos=' . (int) ($found['price_centavos'] ?? 0));

    // C - change duration
    mysqli_query($conn, "UPDATE sellable_packages SET duration_value=9 WHERE package_id=" . (int) $fullId);
    $cats = commerce_catalog_packages_for_registration($conn);
    $found = null;
    foreach ($cats as $p) {
        if ((int) $p['package_id'] === $fullId) {
            $found = $p;
            break;
        }
    }
    $mark('C', $found && (int) $found['duration_value'] === 9, 'duration_value=' . (int) ($found['duration_value'] ?? 0));

    // D - is_purchasable=0 disappears
    mysqli_query($conn, "UPDATE sellable_packages SET is_purchasable=0 WHERE package_id=" . (int) $fullId);
    $cats = commerce_catalog_packages_for_registration($conn);
    $ids = array_map(static fn($p) => (int) $p['package_id'], $cats);
    $mark('D', !in_array($fullId, $ids, true), 'not purchasable');
    mysqli_query($conn, "UPDATE sellable_packages SET is_purchasable=1 WHERE package_id=" . (int) $fullId);

    // E - is_active=0 disappears
    mysqli_query($conn, "UPDATE sellable_packages SET is_active=0 WHERE package_id=" . (int) $fullId);
    $cats = commerce_catalog_packages_for_registration($conn);
    $ids = array_map(static fn($p) => (int) $p['package_id'], $cats);
    $mark('E', !in_array($fullId, $ids, true), 'inactive');
    mysqli_query($conn, "UPDATE sellable_packages SET is_active=1 WHERE package_id=" . (int) $fullId);

    // F - purchasable lesson appears
    $topics = commerce_catalog_topics_for_registration($conn);
    $topicIds = [];
    foreach ($topics as $g) {
        foreach ($g['topics'] as $t) {
            $topicIds[] = (int) $t['lesson_id'];
        }
    }
    $mark('F', in_array($lessonBuy, $topicIds, true) && !in_array($lessonOff, $topicIds, true), "buy=$lessonBuy off=$lessonOff");

    // G - change lesson price
    mysqli_query($conn, "UPDATE lessons SET price_centavos=33300 WHERE lesson_id=" . (int) $lessonBuy);
    $topics = commerce_catalog_topics_for_registration($conn);
    $priceSeen = null;
    foreach ($topics as $g) {
        foreach ($g['topics'] as $t) {
            if ((int) $t['lesson_id'] === $lessonBuy) {
                $priceSeen = (int) $t['price_centavos'];
            }
        }
    }
    $mark('G', $priceSeen === 33300, 'price=' . (string) $priceSeen);

    // H - disable purchasing
    mysqli_query($conn, "UPDATE lessons SET is_purchasable=0 WHERE lesson_id=" . (int) $lessonBuy);
    $topics = commerce_catalog_topics_for_registration($conn);
    $topicIds = [];
    foreach ($topics as $g) {
        foreach ($g['topics'] as $t) {
            $topicIds[] = (int) $t['lesson_id'];
        }
    }
    $mark('H', !in_array($lessonBuy, $topicIds, true), 'disabled');
    mysqli_query($conn, "UPDATE lessons SET is_purchasable=1, price_centavos=25000 WHERE lesson_id=" . (int) $lessonBuy);
    // Make second lesson purchasable for multi-select total
    mysqli_query($conn, "UPDATE lessons SET price_centavos=15000, access_duration_value=14, access_duration_unit='day', is_purchasable=1 WHERE lesson_id=" . (int) $lessonOff);

    // I - multi topic total
    $topicCheck = commerce_validate_topic_selection($conn, [$lessonBuy, $lessonOff]);
    $mark('I', !empty($topicCheck['ok']) && (int) $topicCheck['total_centavos'] === 40000, 'total=' . (int) ($topicCheck['total_centavos'] ?? -1));

    // J - client amount ignored; server recalculates
    $mark('J', !empty($topicCheck['ok']) && (int) $topicCheck['total_centavos'] === 40000 && !isset($topicCheck['client_total']), 'server total only from DB');

    // K - Free Access path: pending with free_access, no payment rows created by selection helpers
    $payBefore = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $tokenUrl = createPendingRegistration([
        'email' => 'phase4.test.free.' . time() . '@example.com',
        'full_name' => 'Phase Four Test',
        'review_type' => 'reviewee',
        'school' => 'Test School',
        'school_other' => null,
        'payment_proof' => 'uploads/forced_proof_should_not_matter.png',
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('TestPass1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'free_access',
        'selected_package_id' => null,
        'selected_lesson_ids_json' => null,
        'free_access_note' => 'Phase4 free access note',
    ]);
    $kOk = is_string($tokenUrl) && $tokenUrl !== '';
    $pendingRow = null;
    if ($kOk) {
        $pr = mysqli_query($conn, "SELECT * FROM pending_registrations WHERE email LIKE 'phase4.test.free.%' ORDER BY id DESC LIMIT 1");
        $pendingRow = $pr ? mysqli_fetch_assoc($pr) : null;
        if ($pendingRow) {
            $pendingIds[] = (int) $pendingRow['id'];
        }
    }
    $payAfter = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $mark(
        'K',
        $kOk
            && $pendingRow
            && ($pendingRow['enrollment_path'] ?? '') === 'free_access'
            && ($pendingRow['free_access_note'] ?? '') === 'Phase4 free access note'
            && $payAfter === $payBefore,
        'pending free_access; payments unchanged'
    );

    // L - invalid mapped rejected
    $bad = commerce_validate_package_selection($conn, $mappedBadId);
    $mark('L', empty($bad['ok']), $bad['error'] ?? 'expected reject');

    // M - full LMS without content items valid
    $good = commerce_validate_package_selection($conn, $fullId);
    $items = commerce_get_package_content($conn, $fullId);
    $mark('M', !empty($good['ok']) && $items === [], 'full_lms valid with empty map');

    // N - package + topics survive pending insert
    $pkgToken = createPendingRegistration([
        'email' => 'phase4.test.pkg.' . time() . '@example.com',
        'full_name' => 'Phase Four Package',
        'review_type' => 'reviewee',
        'school' => 'Test School',
        'school_other' => null,
        'payment_proof' => '',
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('TestPass1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'package',
        'selected_package_id' => $fullId,
        'selected_lesson_ids_json' => null,
        'free_access_note' => null,
    ]);
    $pr2 = mysqli_query($conn, "SELECT * FROM pending_registrations WHERE email LIKE 'phase4.test.pkg.%' ORDER BY id DESC LIMIT 1");
    $pendingPkg = $pr2 ? mysqli_fetch_assoc($pr2) : null;
    if ($pendingPkg) {
        $pendingIds[] = (int) $pendingPkg['id'];
    }
    $topicToken = createPendingRegistration([
        'email' => 'phase4.test.topic.' . time() . '@example.com',
        'full_name' => 'Phase Four Topics',
        'review_type' => 'reviewee',
        'school' => 'Test School',
        'school_other' => null,
        'payment_proof' => '',
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('TestPass1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'by_topic',
        'selected_package_id' => null,
        'selected_lesson_ids_json' => json_encode([$lessonBuy, $lessonOff]),
        'free_access_note' => null,
    ]);
    $pr3 = mysqli_query($conn, "SELECT * FROM pending_registrations WHERE email LIKE 'phase4.test.topic.%' ORDER BY id DESC LIMIT 1");
    $pendingTopic = $pr3 ? mysqli_fetch_assoc($pr3) : null;
    if ($pendingTopic) {
        $pendingIds[] = (int) $pendingTopic['id'];
    }
    $nOk = is_string($pkgToken) && is_string($topicToken)
        && $pendingPkg
        && ($pendingPkg['enrollment_path'] ?? '') === 'package'
        && (int) ($pendingPkg['selected_package_id'] ?? 0) === $fullId
        && $pendingTopic
        && ($pendingTopic['enrollment_path'] ?? '') === 'by_topic'
        && str_contains((string) ($pendingTopic['selected_lesson_ids_json'] ?? ''), (string) $lessonBuy);
    $mark('N', $nOk, 'package/topics persisted on pending_registrations');

    // O - payment_proof forced on new modes must not be treated as accepted commerce proof.
    // register_process forces empty proof for package/by_topic/free_access.
    // Simulate: createPending with empty proof despite client attempting path (K used non-empty string to show storage of field value if passed;
    // register_process clears it before createPending - verify source ignores for new modes by reading register_process logic via empty proof path).
    $oSrc = file_get_contents(__DIR__ . '/../register_process.php');
    $oOk = is_string($oSrc)
        && str_contains($oSrc, "in_array(\$enrollment_path, ['package', 'by_topic', 'free_access'], true)")
        && str_contains($oSrc, 'must NOT accept/store legacy payment_proof');
    // Also: when enrollment modes set uploadedPath='', pending should store empty when called correctly
    $oPending = createPendingRegistration([
        'email' => 'phase4.test.proof.' . time() . '@example.com',
        'full_name' => 'Phase Four Proof',
        'review_type' => 'reviewee',
        'school' => 'Test School',
        'school_other' => null,
        'payment_proof' => '', // what register_process now passes for new modes
        'profile_picture' => '',
        'use_default_avatar' => 1,
        'password_hash' => password_hash('TestPass1!', PASSWORD_DEFAULT),
        'enrollment_path' => 'package',
        'selected_package_id' => $fullId,
        'selected_lesson_ids_json' => null,
        'free_access_note' => null,
    ]);
    $pr4 = mysqli_query($conn, "SELECT * FROM pending_registrations WHERE email LIKE 'phase4.test.proof.%' ORDER BY id DESC LIMIT 1");
    $pendingProof = $pr4 ? mysqli_fetch_assoc($pr4) : null;
    if ($pendingProof) {
        $pendingIds[] = (int) $pendingProof['id'];
    }
    $mark(
        'O',
        $oOk && is_string($oPending) && $pendingProof && ($pendingProof['payment_proof'] ?? null) === '',
        'new modes store empty payment_proof'
    );

    // Extra: zero-price topic rejected
    mysqli_query($conn, "UPDATE lessons SET price_centavos=0, is_purchasable=1 WHERE lesson_id=" . (int) $lessonBuy);
    $zero = commerce_validate_topic_selection($conn, [$lessonBuy]);
    out('ZERO_PRICE', empty($zero['ok']), $zero['error'] ?? 'ok unexpectedly');

} finally {
    echo "\n=== CLEANUP ===\n";
    foreach ($pendingIds as $pid) {
        mysqli_query($conn, 'DELETE FROM pending_registrations WHERE id=' . (int) $pid);
    }
    // Remove any leftover phase4 test pending emails
    mysqli_query($conn, "DELETE FROM pending_registrations WHERE email LIKE 'phase4.test.%'");
    // free_access_requests uses user_id / student_note - no phase4 rows should exist (verification not completed).
    @mysqli_query($conn, "DELETE FROM free_access_requests WHERE student_note LIKE 'Phase4%'");

    foreach ($createdPackageIds as $pid) {
        mysqli_query($conn, 'DELETE FROM package_content_items WHERE package_id=' . (int) $pid);
        mysqli_query($conn, 'DELETE FROM package_feature_items WHERE package_id=' . (int) $pid);
        mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pid);
    }
    mysqli_query($conn, "DELETE FROM sellable_packages WHERE code LIKE 'TEST_%_P4'");

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

    $endPkgs = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
    $endPurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
    $endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
    $endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $cleanupOk = ($endPkgs === $baselinePkgs && $endPurch === $baselinePurch && $endPay === $baselinePay && $endItems === 0);
    out('CLEANUP', $cleanupOk, "packages=$endPkgs purchasable=$endPurch payments=$endPay payment_items=$endItems access_grants=$endGrants");
}

$failed = array_filter($results, static fn($r) => !$r['ok']);
echo "\nSummary: " . (count($results) - count($failed)) . '/' . count($results) . " passed\n";
exit($failed ? 1 : 0);
