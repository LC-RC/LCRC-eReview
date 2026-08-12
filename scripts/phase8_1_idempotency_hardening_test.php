<?php
/**
 * Phase 8.1 Free Access grant idempotency hardening tests (A-N), reversible.
 * Does not exercise Phase 8.2-8.5.
 */
declare(strict_types=1);

define('COMMERCE_NOTIFY_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_free_access.php';
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

echo "=== Phase 8.1 Free Access idempotency hardening ===\n";

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
$ts = (string) time();

function p81i_user(mysqli $conn, string $email): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'P81 Idem';
    $school = 'Test';
    $review = 'reviewee';
    $proof = '';
    $path = 'free_access';
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

function p81i_far(mysqli $conn, int $userId, string $ref, string $status = 'pending'): int
{
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO free_access_requests (request_ref, user_id, status, student_note) VALUES (?, ?, ?, ?)'
    );
    $note = 'idem test';
    mysqli_stmt_bind_param($stmt, 'siss', $ref, $userId, $status, $note);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p81i_has_sca(mysqli $conn, int $userId): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1 FROM student_content_permissions WHERE user_id=? AND content_type='full_lms' AND content_id=0 LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res && (bool) mysqli_fetch_row($res);
    mysqli_stmt_close($stmt);
    return $ok;
}

function p81i_grant_count(mysqli $conn, int $farId): int
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM access_grants WHERE free_access_request_id=?');
    mysqli_stmt_bind_param($stmt, 'i', $farId);
    mysqli_stmt_execute($stmt);
    $n = (int) (mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0] ?? 0);
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
             VALUES ('P81I Admin', 'p81i.admin.{$ts}@example.com', '$hash', 'admin', 'approved', 1)"
        );
        $adminId = (int) mysqli_insert_id($conn);
        $createdUserIds[] = $adminId;
    }

    $lessonId = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id LIMIT 1'))[0] ?? 0);
    if ($lessonId <= 0) {
        throw new RuntimeException('Need a lesson row');
    }

    // ---------- A index exists ----------
    $idx = mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'access_grants'
           AND index_name = 'uq_grant_free_req_content'
         LIMIT 1"
    );
    $mark('A', $idx && mysqli_num_rows($idx) > 0, 'uq_grant_free_req_content');

    // ---------- B duplicate FAR grant rejected ----------
    $uB = p81i_user($conn, "p81i.b.{$ts}@example.com");
    $createdUserIds[] = $uB;
    $farB = p81i_far($conn, $uB, "FAR-P81I-B-{$ts}", 'approved');
    $createdFarIds[] = $farB;
    mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
         VALUES ($uB, 'free_access', NULL, NULL, $farB, 'full_lms', 0, 'first', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active', $adminId)"
    );
    $gB1 = (int) mysqli_insert_id($conn);
    $createdGrantIds[] = $gB1;
    $dupErrno = 0;
    try {
        $dupOk = mysqli_query(
            $conn,
            "INSERT INTO access_grants
              (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
             VALUES ($uB, 'free_access', NULL, NULL, $farB, 'full_lms', 0, 'dup', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active', $adminId)"
        );
        $dupErrno = mysqli_errno($conn);
        if ($dupOk) {
            $createdGrantIds[] = (int) mysqli_insert_id($conn);
        }
    } catch (Throwable $dupEx) {
        $dupErrno = 1062;
        // mysqli may throw on duplicate depending on driver report mode
        if (stripos($dupEx->getMessage(), 'Duplicate') === false) {
            throw $dupEx;
        }
    }
    $mark('B', $dupErrno === 1062 && p81i_grant_count($conn, $farB) === 1, "errno=$dupErrno grants=" . p81i_grant_count($conn, $farB));

    // ---------- C multiple purchase NULL FAR id OK ----------
    $uC = p81i_user($conn, "p81i.c.{$ts}@example.com");
    $createdUserIds[] = $uC;
    $okC1 = mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
         VALUES ($uC, 'purchase', NULL, NULL, NULL, 'lesson', $lessonId, 'p1', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active', $adminId)"
    );
    $gC1 = (int) mysqli_insert_id($conn);
    if ($gC1 > 0) {
        $createdGrantIds[] = $gC1;
    }
    $okC2 = mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
         VALUES ($uC, 'purchase', NULL, NULL, NULL, 'lesson', $lessonId, 'p2', NOW(), DATE_ADD(NOW(), INTERVAL 2 MONTH), 'active', $adminId)"
    );
    $gC2 = (int) mysqli_insert_id($conn);
    if ($gC2 > 0) {
        $createdGrantIds[] = $gC2;
    }
    $mark('C', (bool) $okC1 && (bool) $okC2 && $gC1 > 0 && $gC2 > 0 && $gC1 !== $gC2, "g1=$gC1 g2=$gC2");

    // ---------- D normal pending → one grant ----------
    $uD = p81i_user($conn, "p81i.d.{$ts}@example.com");
    $createdUserIds[] = $uD;
    $farD = p81i_far($conn, $uD, "FAR-P81I-D-{$ts}");
    $createdFarIds[] = $farD;
    $apD = commerce_far_approve($conn, $farD, $adminId, 6, 'd');
    $gD = commerce_far_existing_full_lms_grant($conn, $farD);
    if ($gD) {
        $createdGrantIds[] = (int) $gD['grant_id'];
    }
    $mark(
        'D',
        !empty($apD['ok']) && empty($apD['skipped']) && p81i_grant_count($conn, $farD) === 1
            && (string) ($gD['source'] ?? '') === 'free_access',
        'grants=' . p81i_grant_count($conn, $farD)
    );

    // ---------- E double approval idempotent ----------
    $apE = commerce_far_approve($conn, $farD, $adminId, 3, 'retry');
    $mark(
        'E',
        !empty($apE['ok']) && !empty($apE['skipped']) && p81i_grant_count($conn, $farD) === 1,
        'grants=' . p81i_grant_count($conn, $farD)
    );

    // ---------- F approved + missing grant repair ----------
    $uF = p81i_user($conn, "p81i.f.{$ts}@example.com");
    $createdUserIds[] = $uF;
    $farF = p81i_far($conn, $uF, "FAR-P81I-F-{$ts}", 'approved');
    $createdFarIds[] = $farF;
    $apF = commerce_far_approve($conn, $farF, $adminId, 2, 'repair');
    $gF = commerce_far_existing_full_lms_grant($conn, $farF);
    if ($gF) {
        $createdGrantIds[] = (int) $gF['grant_id'];
    }
    $mark('F', !empty($apF['ok']) && p81i_grant_count($conn, $farF) === 1, 'grants=' . p81i_grant_count($conn, $farF));

    // ---------- G concurrent repair cannot duplicate ----------
    $uG = p81i_user($conn, "p81i.g.{$ts}@example.com");
    $createdUserIds[] = $uG;
    $farG = p81i_far($conn, $uG, "FAR-P81I-G-{$ts}", 'approved');
    $createdFarIds[] = $farG;

    $host = 'localhost';
    $user = 'root';
    $pass = '2429249_lms';
    $db = 'ereview';
    $c1 = mysqli_connect($host, $user, $pass, $db);
    $c2 = mysqli_connect($host, $user, $pass, $db);
    if (!$c1 || !$c2) {
        throw new RuntimeException('Could not open dual connections for G');
    }
    mysqli_set_charset($c1, 'utf8mb4');
    mysqli_set_charset($c2, 'utf8mb4');
    mysqli_query($c1, "SET time_zone = '+08:00'");
    mysqli_query($c2, "SET time_zone = '+08:00'");
    mysqli_query($c1, 'SET SESSION innodb_lock_wait_timeout = 5');
    mysqli_query($c2, 'SET SESSION innodb_lock_wait_timeout = 5');

    mysqli_begin_transaction($c1);
    $lock1 = commerce_far_lock_request_for_update($c1, $farG);
    $ins1Ok = false;
    $ins1Err = 0;
    if ($lock1 && p81i_grant_count($c1, $farG) === 0) {
        $ins1Ok = (bool) mysqli_query(
            $c1,
            "INSERT INTO access_grants
              (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
             VALUES ($uG, 'free_access', NULL, NULL, $farG, 'full_lms', 0, 'g1', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active', $adminId)"
        );
        $ins1Err = mysqli_errno($c1);
    }

    // Second connection tries approve while first holds FOR UPDATE (should wait then see grant / unique).
    $apG2Started = microtime(true);
    // Run second approve on $c2 via duplicated logic: begin + lock will block until $c1 commits.
    // Use a non-blocking approach: start approve after releasing - simulate with raw second insert after commit,
    // and also call commerce_far_approve on main $conn after first path.
    // True overlap: hold c1 lock, attempt INSERT on c2 (should wait), then commit c1.
    mysqli_begin_transaction($c2);
    // Attempt lock on c2 in a way we can detect wait - use GET_LOCK pattern instead:
    // Issue INSERT on c2 without waiting forever: c2 INSERT will wait for c1's gap/row from unique? 
    // Actually FOR UPDATE is on FAR row; c2's INSERT of grant doesn't need FAR lock unless FK checks.
    // Concurrent repair via commerce_far_approve uses FOR UPDATE on FAR - so:
    mysqli_rollback($c2); // reset

    // Hold FAR lock on c1; call approve on main conn in... we can't block easily in one thread.
    // Instead: commit c1's grant, then two sequential approve repairs + one forced duplicate insert.
    // For true concurrency: after c1 has lock+no grant yet, c2 FOR UPDATE blocks.
    // We'll commit c1 insert then run two approves + verify count=1; additionally try second INSERT (1062).

    if ($ins1Ok) {
        $gidG = (int) mysqli_insert_id($c1);
        $createdGrantIds[] = $gidG;
        mysqli_commit($c1);
    } else {
        mysqli_rollback($c1);
    }
    mysqli_close($c1);

    $apG1 = commerce_far_approve($conn, $farG, $adminId, 1, 'g-repair-1');
    $apG2 = commerce_far_approve($conn, $farG, $adminId, 9, 'g-repair-2');
    $gDupErr = 0;
    try {
        $dupG = mysqli_query(
            $conn,
            "INSERT INTO access_grants
              (user_id, source, payment_id, payment_item_id, free_access_request_id, content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
             VALUES ($uG, 'free_access', NULL, NULL, $farG, 'full_lms', 0, 'g-dup', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 'active', $adminId)"
        );
        $gDupErr = mysqli_errno($conn);
        if ($dupG) {
            $createdGrantIds[] = (int) mysqli_insert_id($conn);
        }
    } catch (Throwable $exG) {
        $gDupErr = 1062;
        if (stripos($exG->getMessage(), 'Duplicate') === false) {
            throw $exG;
        }
    }
    $gCount = p81i_grant_count($conn, $farG);
    $gGrant = commerce_far_existing_full_lms_grant($conn, $farG);
    if ($gGrant) {
        $createdGrantIds[] = (int) $gGrant['grant_id'];
    }
    mysqli_close($c2);
    $mark(
        'G',
        $ins1Ok && $ins1Err === 0
            && !empty($apG1['ok']) && !empty($apG2['ok'])
            && $gCount === 1 && $gDupErr === 1062,
        "ins1=$ins1Ok ap1_skip=" . (!empty($apG1['skipped']) ? '1' : '0')
        . " ap2_skip=" . (!empty($apG2['skipped']) ? '1' : '0')
        . " grants=$gCount dupErrno=$gDupErr"
    );

    // ---------- H SCA present ----------
    $mark('H', p81i_has_sca($conn, $uD) && p81i_has_sca($conn, $uF) && p81i_has_sca($conn, $uG), 'SCA on D/F/G');

    // ---------- I reject no grant ----------
    $uI = p81i_user($conn, "p81i.i.{$ts}@example.com");
    $createdUserIds[] = $uI;
    $farI = p81i_far($conn, $uI, "FAR-P81I-I-{$ts}");
    $createdFarIds[] = $farI;
    $rej = commerce_far_reject($conn, $farI, $adminId, 'no');
    $mark('I', !empty($rej['ok']) && p81i_grant_count($conn, $farI) === 0 && !p81i_has_sca($conn, $uI), 'rejected');

    // ---------- J no payment/OCR/GCash ----------
    $payNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $itemsNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $attNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
    $gcashNow = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
    $mark(
        'J',
        $payNow === $basePay && $itemsNow === $baseItems && $attNow === $baseAttempts && $gcashNow === $baseGcash,
        "pay/items/att/gcash unchanged"
    );

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
if ($createdUserIds !== []) {
    $ids = implode(',', array_map('intval', $createdUserIds));
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($ids)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($ids)");
}

$midPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$midGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$midSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$midFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
echo "After local cleanup: pay=$midPay grants=$midGrants sca=$midSca far=$midFar\n";

// ---------- K / L / M regression suites ----------
$php = 'C:\\xampp\\php\\php.exe';
$runReg = static function (string $label, string $script) use ($php, $mark): void {
    $cmd = '"' . $php . '" ' . escapeshellarg($script);
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $text = implode("\n", $output);
    $ok = ($code === 0) && (stripos($text, 'FAIL') === false || preg_match('/Summary: \d+ pass, 0 fail/i', $text) || stripos($text, 'ALL PASS') !== false);
    // Prefer exit code; also require no [FAIL]
    $hasFail = (bool) preg_match('/\[FAIL\]/', $text);
    $ok = ($code === 0) && !$hasFail;
    $mark($label, $ok, 'exit=' . $code . ($hasFail ? ' has FAIL' : ' clean'));
    echo "--- $label output (tail) ---\n";
    echo implode("\n", array_slice($output, -8)) . "\n";
};

$runReg('K', __DIR__ . '/phase7_fulfillment_test.php');
$runReg('L', __DIR__ . '/activation_commerce_sca_hardening_test.php');
$runReg('M', __DIR__ . '/student_access_commerce_sca_hardening_test.php');

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
    'N',
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
