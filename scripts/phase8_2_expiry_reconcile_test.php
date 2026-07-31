<?php
/**
 * Phase 8.2 — Grant expiry + SCA reconciliation acceptance tests (A–Z), reversible.
 * Does not exercise Phase 8.3–8.5.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_grant_expiry.php';
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

echo "=== Phase 8.2 expiry + SCA reconcile tests ===\n";

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

function p82_user(mysqli $conn, string $email): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase82 Test';
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

function p82_grant(
    mysqli $conn,
    int $userId,
    string $source,
    string $ctype,
    int $cid,
    string $startsSql,
    string $endsSql,
    string $status,
    ?int $farId = null
): int {
    $farSql = $farId === null ? 'NULL' : (string) (int) $farId;
    $ok = mysqli_query(
        $conn,
        "INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id,
           content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
         VALUES ($userId, '" . mysqli_real_escape_string($conn, $source) . "', NULL, NULL, $farSql,
           '" . mysqli_real_escape_string($conn, $ctype) . "', $cid, 'p82',
           $startsSql, $endsSql, '" . mysqli_real_escape_string($conn, $status) . "', NULL)"
    );
    if (!$ok) {
        throw new RuntimeException('grant insert: ' . mysqli_error($conn));
    }
    return (int) mysqli_insert_id($conn);
}

function p82_has_sca(mysqli $conn, int $userId, string $type, int $cid): bool
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

function p82_grant_status(mysqli $conn, int $grantId): string
{
    $stmt = mysqli_prepare($conn, 'SELECT status FROM access_grants WHERE grant_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $grantId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (string) ($row['status'] ?? '');
}

function p82_grant_exists(mysqli $conn, int $grantId): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT 1 FROM access_grants WHERE grant_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $grantId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res && (bool) mysqli_fetch_row($res);
    mysqli_stmt_close($stmt);
    return $ok;
}

try {
    $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1"))[0] ?? 0);
    if ($adminId <= 0) {
        $hash = password_hash('AdminPass1!', PASSWORD_DEFAULT);
        mysqli_query(
            $conn,
            "INSERT INTO users (full_name, email, password, role, status, email_verified)
             VALUES ('P82 Admin', 'p82.admin.{$ts}@example.com', '$hash', 'admin', 'approved', 1)"
        );
        $adminId = (int) mysqli_insert_id($conn);
        $createdUserIds[] = $adminId;
    }

    $lessonRows = [];
    $lr = mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id ASC LIMIT 3');
    while ($lr && ($row = mysqli_fetch_assoc($lr))) {
        $lessonRows[] = (int) $row['lesson_id'];
    }
    if (count($lessonRows) < 2) {
        throw new RuntimeException('Need at least 2 lessons');
    }
    $L1 = $lessonRows[0];
    $L2 = $lessonRows[1];

    // ---------- A future active stays active ----------
    $uA = p82_user($conn, "p82.a.{$ts}@example.com");
    $createdUserIds[] = $uA;
    $gA = p82_grant($conn, $uA, 'purchase', 'lesson', $L1, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)', 'active');
    $createdGrantIds[] = $gA;
    sca_upsert_permissions($conn, $uA, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rA = commerce_expire_and_reconcile($conn, 500, $uA);
    $mark('A', !empty($rA['ok']) && p82_grant_status($conn, $gA) === 'active' && p82_has_sca($conn, $uA, 'lesson', $L1), 'status=' . p82_grant_status($conn, $gA));

    // ---------- B overdue → expired ----------
    $uB = p82_user($conn, "p82.b.{$ts}@example.com");
    $createdUserIds[] = $uB;
    $gB = p82_grant($conn, $uB, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'active');
    $createdGrantIds[] = $gB;
    sca_upsert_permissions($conn, $uB, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rB = commerce_expire_and_reconcile($conn, 500, $uB);
    $mark('B', !empty($rB['ok']) && p82_grant_status($conn, $gB) === 'expired' && (int) $rB['expired_grants'] >= 1, 'status=' . p82_grant_status($conn, $gB));

    // ---------- C grant history not deleted ----------
    $mark('C', p82_grant_exists($conn, $gB), 'grant still present');

    // ---------- D expired-only removes commerce SCA; keep manual ----------
    sca_upsert_permissions($conn, $uB, [['content_type' => 'lesson', 'content_id' => $L2]], null);
    // L1 was commerce-backed; after expire+reconcile L1 SCA should be gone; L2 manual-only (no grant) stays
    // Re-run reconcile to ensure (B already reconciled)
    $rD = commerce_reconcile_user_commerce_sca($conn, $uB);
    $mark(
        'D',
        !empty($rD['ok']) && !p82_has_sca($conn, $uB, 'lesson', $L1) && p82_has_sca($conn, $uB, 'lesson', $L2),
        'L1 gone L2 manual kept'
    );

    // ---------- E overlapping active purchase keeps SCA ----------
    $uE = p82_user($conn, "p82.e.{$ts}@example.com");
    $createdUserIds[] = $uE;
    $gE1 = p82_grant($conn, $uE, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 10 DAY)', 'DATE_ADD(NOW(), INTERVAL 10 DAY)', 'active');
    $gE2 = p82_grant($conn, $uE, 'purchase', 'lesson', $L1, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 20 DAY)', 'active');
    $createdGrantIds[] = $gE1;
    $createdGrantIds[] = $gE2;
    sca_upsert_permissions($conn, $uE, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rE = commerce_expire_and_reconcile($conn, 500, $uE);
    $mark('E', !empty($rE['ok']) && p82_has_sca($conn, $uE, 'lesson', $L1), 'overlap keep');

    // ---------- F purchase + free_access overlap ----------
    $uF = p82_user($conn, "p82.f.{$ts}@example.com");
    $createdUserIds[] = $uF;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('FAR-P82-F-{$ts}', $uF, 'approved')");
    $farF = (int) mysqli_insert_id($conn);
    $createdFarIds[] = $farF;
    $gFp = p82_grant($conn, $uF, 'purchase', 'full_lms', 0, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 5 DAY)', 'active');
    $gFf = p82_grant($conn, $uF, 'free_access', 'full_lms', 0, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 30 DAY)', 'active', $farF);
    $createdGrantIds[] = $gFp;
    $createdGrantIds[] = $gFf;
    sca_upsert_permissions($conn, $uF, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    $rF = commerce_expire_and_reconcile($conn, 500, $uF);
    $mark('F', !empty($rF['ok']) && p82_has_sca($conn, $uF, 'full_lms', 0), 'purchase+FAR');

    // ---------- G older expired + newer active ----------
    $uG = p82_user($conn, "p82.g.{$ts}@example.com");
    $createdUserIds[] = $uG;
    $gGold = p82_grant($conn, $uG, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 3 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 MONTH)', 'expired');
    $gGnew = p82_grant($conn, $uG, 'purchase', 'lesson', $L1, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)', 'active');
    $createdGrantIds[] = $gGold;
    $createdGrantIds[] = $gGnew;
    sca_upsert_permissions($conn, $uG, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rG = commerce_expire_and_reconcile($conn, 500, $uG);
    $mark('G', !empty($rG['ok']) && p82_has_sca($conn, $uG, 'lesson', $L1) && p82_grant_status($conn, $gGold) === 'expired', 'newer keeps');

    // ---------- H all commerce expired removes commerce SCA ----------
    $uH = p82_user($conn, "p82.h.{$ts}@example.com");
    $createdUserIds[] = $uH;
    $gH = p82_grant($conn, $uH, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'active');
    $createdGrantIds[] = $gH;
    sca_upsert_permissions($conn, $uH, [
        ['content_type' => 'lesson', 'content_id' => $L1],
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], null);
    $rH = commerce_expire_and_reconcile($conn, 500, $uH);
    $mark(
        'H',
        !empty($rH['ok']) && !p82_has_sca($conn, $uH, 'lesson', $L1) && p82_has_sca($conn, $uH, 'lesson', $L2),
        'commerce gone manual kept'
    );

    // ---------- I manual-only SCA remains (no commerce grant history) ----------
    $uI = p82_user($conn, "p82.i.{$ts}@example.com");
    $createdUserIds[] = $uI;
    sca_upsert_permissions($conn, $uI, [['content_type' => 'lesson', 'content_id' => $L2]], null);
    $rI = commerce_expire_and_reconcile($conn, 500, $uI);
    $mark('I', !empty($rI['ok']) && p82_has_sca($conn, $uI, 'lesson', $L2), 'manual only');

    // ---------- J revoked-only does not preserve SCA ----------
    $uJ = p82_user($conn, "p82.j.{$ts}@example.com");
    $createdUserIds[] = $uJ;
    $gJ = p82_grant($conn, $uJ, 'purchase', 'lesson', $L1, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)', 'revoked');
    $createdGrantIds[] = $gJ;
    sca_upsert_permissions($conn, $uJ, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rJ = commerce_reconcile_user_commerce_sca($conn, $uJ);
    $mark('J', !empty($rJ['ok']) && !p82_has_sca($conn, $uJ, 'lesson', $L1) && p82_grant_status($conn, $gJ) === 'revoked', 'revoked uncover');

    // ---------- K revoked + active keeps SCA ----------
    $uK = p82_user($conn, "p82.k.{$ts}@example.com");
    $createdUserIds[] = $uK;
    $gKr = p82_grant($conn, $uK, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 1 MONTH)', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)', 'revoked');
    $gKa = p82_grant($conn, $uK, 'purchase', 'lesson', $L1, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 2 MONTH)', 'active');
    $createdGrantIds[] = $gKr;
    $createdGrantIds[] = $gKa;
    sca_upsert_permissions($conn, $uK, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rK = commerce_expire_and_reconcile($conn, 500, $uK);
    $mark('K', !empty($rK['ok']) && p82_has_sca($conn, $uK, 'lesson', $L1), 'revoked+active');

    // ---------- L cross-user isolation ----------
    $uL1 = p82_user($conn, "p82.l1.{$ts}@example.com");
    $uL2 = p82_user($conn, "p82.l2.{$ts}@example.com");
    $createdUserIds[] = $uL1;
    $createdUserIds[] = $uL2;
    $gL1 = p82_grant($conn, $uL1, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'active');
    $gL2 = p82_grant($conn, $uL2, 'purchase', 'lesson', $L1, 'NOW()', 'DATE_ADD(NOW(), INTERVAL 1 MONTH)', 'active');
    $createdGrantIds[] = $gL1;
    $createdGrantIds[] = $gL2;
    sca_upsert_permissions($conn, $uL1, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    sca_upsert_permissions($conn, $uL2, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rL = commerce_expire_and_reconcile($conn, 500, $uL1);
    $mark(
        'L',
        !empty($rL['ok'])
            && !p82_has_sca($conn, $uL1, 'lesson', $L1)
            && p82_has_sca($conn, $uL2, 'lesson', $L1)
            && p82_grant_status($conn, $gL2) === 'active',
        'L2 untouched'
    );

    // ---------- M payments/FAR/OCR/GCash unchanged (counts at mid) ----------
    $midPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
    $midItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
    $midAtt = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
    $midGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);
    $farCnt = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM free_access_requests WHERE request_id=$farF"))[0] ?? 0);
    $mark(
        'M',
        $midPay === $basePay && $midItems === $baseItems && $midAtt === $baseAttempts && $midGcash === $baseGcash && $farCnt === 1,
        'commerce tables stable'
    );

    // ---------- N idempotent repeated reconcile ----------
    $rN1 = commerce_expire_and_reconcile($conn, 500, $uB);
    $rN2 = commerce_expire_and_reconcile($conn, 500, $uB);
    $mark(
        'N',
        !empty($rN1['ok']) && !empty($rN2['ok'])
            && (int) $rN2['expired_grants'] === 0
            && !p82_has_sca($conn, $uB, 'lesson', $L1)
            && p82_has_sca($conn, $uB, 'lesson', $L2),
        'second expire=0'
    );

    // ---------- O revoked never becomes expired ----------
    $uO = p82_user($conn, "p82.o.{$ts}@example.com");
    $createdUserIds[] = $uO;
    $gO = p82_grant($conn, $uO, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'revoked');
    $createdGrantIds[] = $gO;
    $rO = commerce_expire_and_reconcile($conn, 500, $uO);
    $mark('O', !empty($rO['ok']) && p82_grant_status($conn, $gO) === 'revoked', 'stays revoked');

    // ---------- P overdue expires + SCA removed if uncovered ----------
    $uP = p82_user($conn, "p82.p.{$ts}@example.com");
    $createdUserIds[] = $uP;
    $gP = p82_grant($conn, $uP, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 HOUR)', 'active');
    $createdGrantIds[] = $gP;
    sca_upsert_permissions($conn, $uP, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    $rP = commerce_expire_and_reconcile($conn, 500, $uP);
    $mark(
        'P',
        !empty($rP['ok']) && p82_grant_status($conn, $gP) === 'expired' && !p82_has_sca($conn, $uP, 'lesson', $L1),
        'expired+SCA cleared'
    );

    // ---------- Q FAR full_lms expiry removes SCA when uncovered ----------
    $uQ = p82_user($conn, "p82.q.{$ts}@example.com");
    $createdUserIds[] = $uQ;
    mysqli_query($conn, "INSERT INTO free_access_requests (request_ref, user_id, status) VALUES ('FAR-P82-Q-{$ts}', $uQ, 'approved')");
    $farQ = (int) mysqli_insert_id($conn);
    $createdFarIds[] = $farQ;
    $gQ = p82_grant($conn, $uQ, 'free_access', 'full_lms', 0, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'active', $farQ);
    $createdGrantIds[] = $gQ;
    sca_upsert_permissions($conn, $uQ, [['content_type' => 'full_lms', 'content_id' => 0]], null);
    $rQ = commerce_expire_and_reconcile($conn, 500, $uQ);
    $mark(
        'Q',
        !empty($rQ['ok']) && p82_grant_status($conn, $gQ) === 'expired' && !p82_has_sca($conn, $uQ, 'full_lms', 0),
        'FAR SCA cleared'
    );

    // ---------- W preserve helper still ignores expired ----------
    $uW = p82_user($conn, "p82.w.{$ts}@example.com");
    $createdUserIds[] = $uW;
    $gW = p82_grant($conn, $uW, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'expired');
    $createdGrantIds[] = $gW;
    sca_upsert_permissions($conn, $uW, [['content_type' => 'lesson', 'content_id' => $L1]], null);
    sca_save_user_permissions_preserving_commerce($conn, $uW, [
        ['content_type' => 'lesson', 'content_id' => $L2],
    ], $adminId);
    $mark(
        'W',
        !p82_has_sca($conn, $uW, 'lesson', $L1) && p82_has_sca($conn, $uW, 'lesson', $L2),
        'preserve skips expired'
    );

    // ---------- X no migration required/applied for 8.2 ----------
    $mig029 = is_file(dirname(__DIR__) . '/migrations/029_grant_expiry.sql')
        || is_file(dirname(__DIR__) . '/migrations/029_commerce_expire.sql');
    $hasExpiryHelper = is_file(dirname(__DIR__) . '/includes/commerce_grant_expiry.php');
    $mark('X', !$mig029 && $hasExpiryHelper, 'no 8.2 schema migration');

    // ---------- Y CLI limit behavior ----------
    $uY1 = p82_user($conn, "p82.y1.{$ts}@example.com");
    $uY2 = p82_user($conn, "p82.y2.{$ts}@example.com");
    $createdUserIds[] = $uY1;
    $createdUserIds[] = $uY2;
    $gY1 = p82_grant($conn, $uY1, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'active');
    $gY2 = p82_grant($conn, $uY2, 'purchase', 'lesson', $L1, 'DATE_SUB(NOW(), INTERVAL 2 MONTH)', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'active');
    $createdGrantIds[] = $gY1;
    $createdGrantIds[] = $gY2;
    // Global expire with limit=1 (no user filter) — only one grant expired this call
    $rY = commerce_expire_overdue_grants($conn, 1, 0);
    $expiredOne = (p82_grant_status($conn, $gY1) === 'expired') XOR (p82_grant_status($conn, $gY2) === 'expired');
    $mark(
        'Y',
        !empty($rY['ok']) && (int) $rY['expired_count'] === 1 && $expiredOne,
        'limit=1 expired_count=' . (int) ($rY['expired_count'] ?? -1)
    );
    // Finish remaining
    commerce_expire_and_reconcile($conn, 500, 0);

} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Local cleanup before regressions
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

echo "After local cleanup mid-check...\n";

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
    echo "--- $label tail ---\n" . implode("\n", array_slice($output, -6)) . "\n";
};

if (getenv('COMMERCE_SKIP_NESTED_REGRESSIONS') === '1') {
    $mark('R', true, 'skipped nested (parent suite)');
    $mark('S', true, 'skipped nested (parent suite)');
    $mark('T', true, 'skipped nested (parent suite)');
    $mark('U', true, 'skipped nested (parent suite)');
    $mark('V', true, 'skipped nested (parent suite)');
} else {
    $runReg('R', __DIR__ . '/phase8_1_free_access_test.php');
    $runReg('S', __DIR__ . '/phase8_1_idempotency_hardening_test.php');
    $runReg('T', __DIR__ . '/phase7_fulfillment_test.php');
    $runReg('U', __DIR__ . '/activation_commerce_sca_hardening_test.php');
    $runReg('V', __DIR__ . '/student_access_commerce_sca_hardening_test.php');
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
    'Z',
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
