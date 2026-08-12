<?php
/**
 * Seed demo pending students for local Grant Access / Students UI checks.
 * Safe to re-run: removes prior seed emails matching demo.pending.*@ereview.local
 *
 * Usage: C:\xampp\php\php.exe scripts/seed_demo_pending_students.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/session_config.php';
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/includes/commerce_catalog.php';
require dirname(__DIR__) . '/includes/commerce_payment.php';

if (!commerce_schema_ready($conn)) {
    fwrite(STDERR, "Commerce schema not ready.\n");
    exit(1);
}

$prefix = 'demo.pending.';

/**
 * Write a clearly visible demo GCash-style receipt PNG (not a 1×1 pixel).
 */
function seed_write_demo_receipt_png(string $absPath, string $label): void
{
    $dir = dirname($absPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (function_exists('imagecreatetruecolor')) {
        $w = 640;
        $h = 900;
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 248, 250, 252);
        $card = imagecolorallocate($im, 255, 255, 255);
        $blue = imagecolorallocate($im, 0, 122, 255);
        $ink = imagecolorallocate($im, 15, 23, 42);
        $muted = imagecolorallocate($im, 100, 116, 139);
        $green = imagecolorallocate($im, 22, 163, 74);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        imagefilledrectangle($im, 40, 40, $w - 40, $h - 40, $card);
        imagefilledrectangle($im, 40, 40, $w - 40, 140, $blue);
        imagestring($im, 5, 70, 70, 'GCash Demo Receipt', imagecolorallocate($im, 255, 255, 255));
        imagestring($im, 4, 70, 180, 'DEMO PROOF OF PAYMENT', $ink);
        imagestring($im, 3, 70, 220, $label, $muted);
        imagestring($im, 5, 70, 280, 'Amount: PHP 2,500.00', $ink);
        imagestring($im, 4, 70, 320, 'Ref No: DEMO' . date('His'), $muted);
        imagestring($im, 4, 70, 360, 'Status: SUCCESS', $green);
        imagestring($im, 3, 70, 420, 'Seed file for admin modal testing', $muted);
        imagestring($im, 3, 70, 450, 'Not a real payment.', $muted);
        imagepng($im, $absPath);
        imagedestroy($im);
        return;
    }
    // Fallback: larger solid PNG via packed chunks if GD missing
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC'
    );
    file_put_contents($absPath, $png !== false ? $png : 'demo-receipt');
}

// Cleanup previous seed
$old = mysqli_query(
    $conn,
    "SELECT user_id FROM users WHERE email LIKE 'demo.pending.%@ereview.local'"
);
$oldIds = [];
while ($old && ($r = mysqli_fetch_assoc($old))) {
    $oldIds[] = (int) $r['user_id'];
}
if ($oldIds !== []) {
    $in = implode(',', $oldIds);
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($in)");
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id IN (SELECT payment_id FROM payments WHERE user_id IN ($in))");
    mysqli_query($conn, "DELETE FROM payments WHERE user_id IN ($in)");
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($in)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($in)");
    echo "Cleaned previous demo users: " . count($oldIds) . "\n";
}

$pkgQ = mysqli_query(
    $conn,
    "SELECT package_id, name, price_centavos FROM sellable_packages
     WHERE is_active = 1 AND is_purchasable = 1
     ORDER BY sort_order ASC, package_id ASC LIMIT 1"
);
$pkg = $pkgQ ? mysqli_fetch_assoc($pkgQ) : null;
if (!$pkg) {
    mysqli_query(
        $conn,
        "INSERT INTO sellable_packages
          (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
         VALUES ('DEMO_PENDING_PKG', 'Demo Package 6 Months', 'Seed package', 200000, 'PHP', 6, 'month', 'full_lms', 1, 1, 90)"
    );
    $pkgId = (int) mysqli_insert_id($conn);
    $pkgName = 'Demo Package 6 Months';
} else {
    $pkgId = (int) $pkg['package_id'];
    $pkgName = (string) $pkg['name'];
}

$hash = password_hash('DemoPass1!', PASSWORD_DEFAULT);
$ts = date('YmdHis');

/**
 * @return int user_id
 */
function seed_user(mysqli $conn, string $name, string $email, string $hash, int $pkgId): int
{
    $review = 'reviewee';
    $path = 'package';
    $school = 'Demo Review Center';
    $proof = '';
    $status = 'pending';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users
          (full_name, review_type, enrollment_path, selected_package_id, school, school_other, payment_proof, email, password, role, status, email_verified)
         VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 'student', ?, 1)"
    );
    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'sssisssss', $name, $review, $path, $pkgId, $school, $proof, $email, $hash, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

$cases = [
    [
        'slug' => 'no-payment',
        'name' => 'Demo No Payment Yet',
        'note' => 'Pending account, no checkout - best for Grant Access',
        'setup' => static function (mysqli $conn, int $uid, int $pkgId): void {
            // no payment row
        },
    ],
    [
        'slug' => 'awaiting-proof',
        'name' => 'Demo Awaiting Proof',
        'note' => 'Checkout open, waiting for proof upload',
        'setup' => static function (mysqli $conn, int $uid, int $pkgId): void {
            $co = commerce_create_or_resume_checkout($conn, $uid, 'package', $pkgId, null);
            if (empty($co['ok'])) {
                throw new RuntimeException('checkout failed: ' . ($co['error'] ?? ''));
            }
        },
    ],
    [
        'slug' => 'needs-review',
        'name' => 'Demo Needs Review',
        'note' => 'Proof uploaded, verification needs_review - Payment Needs Review + Access None',
        'setup' => static function (mysqli $conn, int $uid, int $pkgId): void {
            $co = commerce_create_or_resume_checkout($conn, $uid, 'package', $pkgId, null);
            $pid = (int) ($co['payment']['payment_id'] ?? 0);
            if ($pid <= 0) {
                throw new RuntimeException('no payment');
            }
            $rel = 'uploads/payment_proofs/demo_seed_needs_review.png';
            $abs = dirname(__DIR__) . '/' . $rel;
            seed_write_demo_receipt_png($abs, 'Case: needs_review');
            mysqli_query(
                $conn,
                "UPDATE payments SET
                   status='pending_verification',
                   verification_status='needs_review',
                   proof_path='" . mysqli_real_escape_string($conn, $rel) . "',
                   proof_mime='image/png'
                 WHERE payment_id=" . $pid
            );
        },
    ],
    [
        'slug' => 'ocr-failed',
        'name' => 'Demo OCR Failed',
        'note' => 'Proof uploaded, OCR failed - manual review path',
        'setup' => static function (mysqli $conn, int $uid, int $pkgId): void {
            $co = commerce_create_or_resume_checkout($conn, $uid, 'package', $pkgId, null);
            $pid = (int) ($co['payment']['payment_id'] ?? 0);
            if ($pid <= 0) {
                throw new RuntimeException('no payment');
            }
            $rel = 'uploads/payment_proofs/demo_seed_ocr_failed.png';
            $abs = dirname(__DIR__) . '/' . $rel;
            seed_write_demo_receipt_png($abs, 'Case: OCR failed');
            mysqli_query(
                $conn,
                "UPDATE payments SET
                   status='pending_verification',
                   verification_status='failed',
                   proof_path='" . mysqli_real_escape_string($conn, $rel) . "',
                   proof_mime='image/png'
                 WHERE payment_id=" . $pid
            );
        },
    ],
    [
        'slug' => 'awaiting-grant',
        'name' => 'Demo Awaiting Grant',
        'note' => 'Pending account + Access None - Grant Access target (never Active without grant)',
        'setup' => static function (mysqli $conn, int $uid, int $pkgId): void {
            // Stay pending / no grant - Enrolled requires active access_grants.
        },
    ],
    [
        'slug' => 'far-pending',
        'name' => 'Demo FAR Pending',
        'note' => 'Free Access request pending review',
        'setup' => static function (mysqli $conn, int $uid, int $pkgId): void {
            mysqli_query($conn, "UPDATE users SET enrollment_path='free_access', selected_package_id=NULL WHERE user_id=" . $uid);
            $ref = 'FAR-DEMO-' . $uid . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $ok = mysqli_query(
                $conn,
                "INSERT INTO free_access_requests (request_ref, user_id, status, student_note, created_at)
                 VALUES ('" . mysqli_real_escape_string($conn, $ref) . "', $uid, 'pending', 'Demo seed FAR for admin review', NOW())"
            );
            if (!$ok) {
                throw new RuntimeException('FAR insert failed: ' . mysqli_error($conn));
            }
        },
    ],
];

echo "Using package_id={$pkgId} ({$pkgName})\n\n";
echo str_pad('Name', 28) . str_pad('Email', 48) . "Where to look / note\n";
echo str_repeat('-', 120) . "\n";

foreach ($cases as $case) {
    $email = $prefix . $case['slug'] . '.' . $ts . '@ereview.local';
    $uid = seed_user($conn, $case['name'], $email, $hash, $pkgId);
    ($case['setup'])($conn, $uid, $pkgId);
    $tab = ($case['slug'] === 'active-no-grant') ? 'Enrolled (Active + Access None)' : 'Needs review tab';
    echo str_pad($case['name'], 28) . str_pad($email, 48) . $tab . ' - ' . $case['note'] . "\n";
    echo '  user_id=' . $uid . "\n";
}

echo "\nPassword for all: DemoPass1!\n";
echo "Open: admin_students?tab=pending  (and Enrolled for Demo Active No Grant)\n";
echo "Search: demo.pending\n";
