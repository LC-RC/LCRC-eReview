<?php
/**
 * Phase 8.1 — Free Access approval acceptance tests (A–S), reversible.
 * Does not exercise Phase 8.2–8.5.
 */
declare(strict_types=1);

define('COMMERCE_NOTIFY_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_free_access.php';
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

echo "=== Phase 8.1 Free Access approval tests ===\n";

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
$createdFarIds = [];
$createdGrantIds = [];
$createdPaymentIds = [];
$ts = (string) time();

function p81_user(mysqli $conn, string $email, string $status = 'pending'): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase81 FAR';
    $school = 'Test School';
    $review = 'reviewee';
    $proof = '';
    $path = 'free_access';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, review_type, enrollment_path, school, school_other, payment_proof, email, password, role, status, email_verified)
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'student', ?, 1)"
    );
    mysqli_stmt_bind_param($stmt, 'ssssssss', $name, $review, $path, $school, $proof, $email, $hash, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('user insert: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p81_far(mysqli $conn, int $userId, string $ref, string $note = 'please'): int
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO free_access_requests (request_ref, user_id, status, student_note) VALUES (?, ?, 'pending', ?)"
    );
    mysqli_stmt_bind_param($stmt, 'sis', $ref, $userId, $note);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('far insert: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p81_has_sca(mysqli $conn, int $userId, string $type, int $cid): bool
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

function p81_user_status(mysqli $conn, int $userId): string
{
    $stmt = mysqli_prepare($conn, 'SELECT status FROM users WHERE user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (string) ($row['status'] ?? '');
}

function p81_grant_count_for_far(mysqli $conn, int $farId): int
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM access_grants WHERE free_access_request_id=?');
    mysqli_stmt_bind_param($stmt, 'i', $farId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n = (int) (mysqli_fetch_row($res)[0] ?? 0);
    mysqli_stmt_close($stmt);
    return $n;
}

try {
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        $hash = password_hash('AdminPass1!', PASSWORD_DEFAULT);
        mysqli_query(
            $conn,
            "INSERT INTO users (full_name, email, password, role, status, email_verified)
             VALUES ('Phase81 Admin', 'phase81.admin.{$ts}@example.com', '$hash', 'admin', 'approved', 1)"
        );
        $adminId = (int) mysqli_insert_id($conn);
        $createdUserIds[] = $adminId;
    }

    $lessonId = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id LIMIT 1'))[0] ?? 0);
    if ($lessonId <= 0) {
        throw new RuntimeException('Need at least one lesson for SCA tests');
    }

    // ---------- A approve → one full_lms grant ----------
    $uA = p81_user($conn, "p81.a.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uA;
    $farA = p81_far($conn, $uA, "FAR-P81-A-{$ts}");
    $createdFarIds[] = $farA;
    $apA = commerce_far_approve($conn, $farA, $adminId, 6, 'approve A');
    $gA = commerce_far_existing_full_lms_grant($conn, $farA);
    if ($gA) {
        $createdGrantIds[] = (int) $gA['grant_id'];
    }
    $mark(
        'A',
        !empty($apA['ok']) && $gA
            && (string) $gA['source'] === 'free_access'
            && (string) $gA['content_type'] === 'full_lms'
            && (int) $gA['content_id'] === 0
            && (string) $gA['status'] === 'active'
            && p81_grant_count_for_far($conn, $farA) === 1,
        'grants=' . p81_grant_count_for_far($conn, $farA)
    );

    // ---------- B starts_at / ends_at calendar months ----------
    $startsOk = false;
    $endsOk = false;
    if ($gA) {
        $chk = mysqli_query(
            $conn,
            'SELECT starts_at, ends_at,
                    DATE_ADD(starts_at, INTERVAL 6 MONTH) AS expected_ends,
                    (ends_at = DATE_ADD(starts_at, INTERVAL 6 MONTH)) AS months_ok
             FROM access_grants WHERE grant_id=' . (int) $gA['grant_id'] . ' LIMIT 1'
        );
        $crow = $chk ? mysqli_fetch_assoc($chk) : null;
        $startsOk = $crow && !empty($crow['starts_at']);
        $endsOk = $crow && (int) ($crow['months_ok'] ?? 0) === 1;
        $mark(
            'B',
            $startsOk && $endsOk,
            'starts=' . ($crow['starts_at'] ?? '') . ' ends=' . ($crow['ends_at'] ?? '')
            . ' expected=' . ($crow['expected_ends'] ?? '')
        );
    } else {
        $mark('B', false, 'no grant');
    }

    // ---------- C SCA permission ----------
    $mark('C', p81_has_sca($conn, $uA, 'full_lms', 0), 'full_lms SCA');

    // ---------- D student login auto-activated after FAR approve ----------
    $mark('D', p81_user_status($conn, $uA) === 'approved', 'status=' . p81_user_status($conn, $uA));

    // ---------- E–H no payment / items / gcash / OCR ----------
    $payNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $itemsNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $gcashNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
    $attNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
    $mark('E', $payNow === $basePay, "pay {$basePay}->{$payNow}");
    $mark('F', $itemsNow === $baseItems, "items {$baseItems}->{$itemsNow}");
    $mark('G', $gcashNow === $baseGcash, "gcash {$baseGcash}->{$gcashNow}");
    $mark('H', $attNow === $baseAttempts, "attempts {$baseAttempts}->{$attNow}");

    // ---------- I reject → no grant ----------
    $uI = p81_user($conn, "p81.i.{$ts}@example.com");
    $createdUserIds[] = $uI;
    $farI = p81_far($conn, $uI, "FAR-P81-I-{$ts}", 'reject me');
    $createdFarIds[] = $farI;
    $rejI = commerce_far_reject($conn, $farI, $adminId, 'nope');
    $reqI = commerce_far_get_request($conn, $farI);
    $mark(
        'I',
        !empty($rejI['ok'])
            && ($reqI['status'] ?? '') === 'rejected'
            && p81_grant_count_for_far($conn, $farI) === 0,
        'status=' . ($reqI['status'] ?? '')
    );

    // ---------- J reject → no SCA ----------
    $mark('J', !p81_has_sca($conn, $uI, 'full_lms', 0), 'no SCA after reject');

    // ---------- K double approval no duplicate ----------
    $apA2 = commerce_far_approve($conn, $farA, $adminId, 3, 'retry');
    $mark(
        'K',
        !empty($apA2['ok'])
            && !empty($apA2['skipped'])
            && p81_grant_count_for_far($conn, $farA) === 1,
        'grants=' . p81_grant_count_for_far($conn, $farA)
    );

    // ---------- L concurrent claim (second loses race safely) ----------
    $uL = p81_user($conn, "p81.l.{$ts}@example.com");
    $createdUserIds[] = $uL;
    $farL = p81_far($conn, $uL, "FAR-P81-L-{$ts}");
    $createdFarIds[] = $farL;
    // Simulate concurrent: claim first in separate update, then both approve paths
    mysqli_begin_transaction($conn);
    $claim1 = mysqli_prepare(
        $conn,
        "UPDATE free_access_requests SET status='approved', reviewed_by=?, reviewed_at=NOW()
         WHERE request_id=? AND status='pending' LIMIT 1"
    );
    mysqli_stmt_bind_param($claim1, 'ii', $adminId, $farL);
    mysqli_stmt_execute($claim1);
    $aff1 = mysqli_stmt_affected_rows($claim1);
    mysqli_stmt_close($claim1);
    // Second claim in same "race" window
    $claim2 = mysqli_prepare(
        $conn,
        "UPDATE free_access_requests SET status='approved', reviewed_by=?, reviewed_at=NOW()
         WHERE request_id=? AND status='pending' LIMIT 1"
    );
    mysqli_stmt_bind_param($claim2, 'ii', $adminId, $farL);
    mysqli_stmt_execute($claim2);
    $aff2 = mysqli_stmt_affected_rows($claim2);
    mysqli_stmt_close($claim2);
    mysqli_commit($conn);
    // Repair grant via approve (status already approved, no grant yet)
    $apL = commerce_far_approve($conn, $farL, $adminId, 2, 'race repair');
    $gL = commerce_far_existing_full_lms_grant($conn, $farL);
    if ($gL) {
        $createdGrantIds[] = (int) $gL['grant_id'];
    }
    $apL2 = commerce_far_approve($conn, $farL, $adminId, 9, 'race retry');
    $mark(
        'L',
        $aff1 === 1 && $aff2 === 0
            && !empty($apL['ok'])
            && !empty($apL2['ok']) && !empty($apL2['skipped'])
            && p81_grant_count_for_far($conn, $farL) === 1,
        "aff1=$aff1 aff2=$aff2 grants=" . p81_grant_count_for_far($conn, $farL)
    );

    // ---------- M existing manual SCA remains ----------
    $uM = p81_user($conn, "p81.m.{$ts}@example.com");
    $createdUserIds[] = $uM;
    sca_upsert_permissions($conn, $uM, [['content_type' => 'lesson', 'content_id' => $lessonId]], $adminId);
    $farM = p81_far($conn, $uM, "FAR-P81-M-{$ts}");
    $createdFarIds[] = $farM;
    $apM = commerce_far_approve($conn, $farM, $adminId, 1, 'keep manual');
    $gM = commerce_far_existing_full_lms_grant($conn, $farM);
    if ($gM) {
        $createdGrantIds[] = (int) $gM['grant_id'];
    }
    $mark(
        'M',
        !empty($apM['ok'])
            && p81_has_sca($conn, $uM, 'lesson', $lessonId)
            && p81_has_sca($conn, $uM, 'full_lms', 0),
        'manual+full_lms'
    );

    // ---------- N existing active purchase SCA remains ----------
    $uN = p81_user($conn, "p81.n.{$ts}@example.com");
    $createdUserIds[] = $uN;
    mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
         VALUES ($uN, 'purchase', NULL, NULL, NULL, 'lesson', $lessonId, 'paid lesson', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active', $adminId)"
    );
    $purchaseGrantId = (int) mysqli_insert_id($conn);
    $createdGrantIds[] = $purchaseGrantId;
    sca_upsert_permissions($conn, $uN, [['content_type' => 'lesson', 'content_id' => $lessonId]], $adminId);
    $farN = p81_far($conn, $uN, "FAR-P81-N-{$ts}");
    $createdFarIds[] = $farN;
    $apN = commerce_far_approve($conn, $farN, $adminId, 1, 'keep purchase');
    $gN = commerce_far_existing_full_lms_grant($conn, $farN);
    if ($gN) {
        $createdGrantIds[] = (int) $gN['grant_id'];
    }
    $mark(
        'N',
        !empty($apN['ok'])
            && p81_has_sca($conn, $uN, 'lesson', $lessonId)
            && p81_has_sca($conn, $uN, 'full_lms', 0)
            && (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE grant_id=$purchaseGrantId AND status='active'"))[0] ?? 0) === 1,
        'purchase SCA+grant intact'
    );

    // ---------- O Free Access SCA preserved by Student Access save ----------
    sca_save_user_permissions_preserving_commerce($conn, $uA, [
        ['content_type' => 'lesson', 'content_id' => $lessonId],
    ], $adminId);
    $mark(
        'O',
        p81_has_sca($conn, $uA, 'full_lms', 0) && p81_has_sca($conn, $uA, 'lesson', $lessonId),
        'preserve FAR + add lesson'
    );

    // ---------- P Free Access SCA preserved by activation ----------
    $uP = p81_user($conn, "p81.p.{$ts}@example.com", 'pending');
    $createdUserIds[] = $uP;
    $farP = p81_far($conn, $uP, "FAR-P81-P-{$ts}");
    $createdFarIds[] = $farP;
    $apP = commerce_far_approve($conn, $farP, $adminId, 4, 'then activate');
    $gP = commerce_far_existing_full_lms_grant($conn, $farP);
    if ($gP) {
        $createdGrantIds[] = (int) $gP['grant_id'];
    }
    // Simulate activate_user SCA path (preserving commerce), then set approved
    sca_save_user_permissions_preserving_commerce($conn, $uP, [
        ['content_type' => 'lesson', 'content_id' => $lessonId],
    ], $adminId);
    mysqli_query($conn, "UPDATE users SET status='approved' WHERE user_id=$uP LIMIT 1");
    $mark(
        'P',
        p81_user_status($conn, $uP) === 'approved'
            && p81_has_sca($conn, $uP, 'full_lms', 0)
            && p81_has_sca($conn, $uP, 'lesson', $lessonId),
        'activate preserve FAR SCA'
    );

    // ---------- Q another user's grants/SCA untouched ----------
    $uQ = p81_user($conn, "p81.q.{$ts}@example.com");
    $createdUserIds[] = $uQ;
    sca_upsert_permissions($conn, $uQ, [['content_type' => 'lesson', 'content_id' => $lessonId]], $adminId);
    mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
         VALUES ($uQ, 'admin_manual', NULL, NULL, NULL, 'lesson', $lessonId, 'other', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active', $adminId)"
    );
    $qGrant = (int) mysqli_insert_id($conn);
    $createdGrantIds[] = $qGrant;
    $scaQBefore = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM student_content_permissions WHERE user_id=$uQ"))[0] ?? 0);
    // Approve unrelated user
    $uQ2 = p81_user($conn, "p81.q2.{$ts}@example.com");
    $createdUserIds[] = $uQ2;
    $farQ2 = p81_far($conn, $uQ2, "FAR-P81-Q2-{$ts}");
    $createdFarIds[] = $farQ2;
    $apQ2 = commerce_far_approve($conn, $farQ2, $adminId, 1, 'other user');
    $gQ2 = commerce_far_existing_full_lms_grant($conn, $farQ2);
    if ($gQ2) {
        $createdGrantIds[] = (int) $gQ2['grant_id'];
    }
    $scaQAfter = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM student_content_permissions WHERE user_id=$uQ"))[0] ?? 0);
    $qGrantOk = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE grant_id=$qGrant AND user_id=$uQ"))[0] ?? 0) === 1;
    $mark(
        'Q',
        !empty($apQ2['ok']) && $scaQBefore === $scaQAfter && $qGrantOk && !p81_has_sca($conn, $uQ, 'full_lms', 0),
        "scaQ {$scaQBefore}->{$scaQAfter}"
    );

    // ---------- S no Phase 5–7 regression (static isolation) ----------
    $farFile = file_get_contents(dirname(__DIR__) . '/includes/commerce_free_access.php');
    $login = file_get_contents(dirname(__DIR__) . '/login_process.php');
    $mark(
        'S',
        strpos($farFile, 'commerce_fulfill_payment') === false
            && strpos($farFile, 'commerce_verify') === false
            && strpos($farFile, 'payment_checkout') === false
            && strpos($login, 'commerce_far_') === false
            && is_file(dirname(__DIR__) . '/admin_commerce_free_access.php'),
        'FAR isolated from payment/login'
    );

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// ---------- Cleanup ----------
if ($createdGrantIds !== []) {
    $ids = implode(',', array_map('intval', $createdGrantIds));
    mysqli_query($conn, "DELETE FROM access_grants WHERE grant_id IN ($ids)");
}
if ($createdFarIds !== []) {
    $ids = implode(',', array_map('intval', $createdFarIds));
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE request_id IN ($ids)");
}
if ($createdUserIds !== []) {
    $ids = implode(',', array_map('intval', $createdUserIds));
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($ids)");
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
    'R',
    $cleanupOk,
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
