<?php
/**
 * Admin Commerce — GCash QR / payment settings (Phase 3).
 * Receipt OCR verification comes in later phases; settings thresholds stored here.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/commerce_catalog.php';

if (!commerce_schema_ready($conn)) {
    $_SESSION['error'] = 'Commerce schema is not installed.';
    header('Location: admin_dashboard');
    exit;
}

$csrf = generateCSRFToken();
$pageTitle = 'Commerce — GCash Settings';
$settings = commerce_get_payment_settings($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: admin_commerce_gcash');
        exit;
    }

    $name = trim((string) ($_POST['gcash_account_name'] ?? ''));
    $number = trim((string) ($_POST['gcash_number'] ?? ''));
    $instructions = trim((string) ($_POST['payment_instructions'] ?? ''));
    $threshold = (float) ($_POST['ocr_confidence_threshold'] ?? 85);
    $maxAge = max(1, (int) ($_POST['receipt_max_age_days'] ?? 7));
    $vision = !empty($_POST['vision_fallback_enabled']) ? 1 : 0;
    $removeQr = !empty($_POST['remove_qr']);
    $adminId = (int) ($_SESSION['user_id'] ?? 0);
    $qrPath = $settings['gcash_qr_path'] ?? null;
    if ($qrPath === '') {
        $qrPath = null;
    }

    if ($removeQr && !empty($qrPath)) {
        $abs = __DIR__ . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $qrPath);
        if (is_file($abs)) {
            @unlink($abs);
        }
        $qrPath = null;
    }

    if (!empty($_FILES['gcash_qr']['tmp_name']) && is_uploaded_file($_FILES['gcash_qr']['tmp_name'])) {
        $tmp = $_FILES['gcash_qr']['tmp_name'];
        $size = (int) ($_FILES['gcash_qr']['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            $_SESSION['error'] = 'QR image must be 5 MB or smaller.';
            header('Location: admin_commerce_gcash');
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extMap[$mime])) {
            $_SESSION['error'] = 'Use JPG, PNG, or WebP for the QR image.';
            header('Location: admin_commerce_gcash');
            exit;
        }
        $dir = __DIR__ . '/uploads/gcash';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $_SESSION['error'] = 'Could not create uploads/gcash directory.';
            header('Location: admin_commerce_gcash');
            exit;
        }
        if (!empty($qrPath)) {
            $old = __DIR__ . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $qrPath);
            if (is_file($old)) {
                @unlink($old);
            }
        }
        $rel = 'uploads/gcash/qr_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
        $dest = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!@move_uploaded_file($tmp, $dest)) {
            $_SESSION['error'] = 'Could not save QR image.';
            header('Location: admin_commerce_gcash');
            exit;
        }
        $qrPath = $rel;
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE payment_settings SET
           gcash_account_name = ?, gcash_number = ?, gcash_qr_path = ?, payment_instructions = ?,
           ocr_confidence_threshold = ?, receipt_max_age_days = ?, vision_fallback_enabled = ?,
           updated_by = ?, updated_at = NOW()
         WHERE setting_id = 1 LIMIT 1'
    );
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            'ssssdiii',
            $name,
            $number,
            $qrPath,
            $instructions,
            $threshold,
            $maxAge,
            $vision,
            $adminId
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'GCash settings saved. Registration checkout loads these values dynamically.';
    } else {
        $_SESSION['error'] = 'Could not save settings.';
    }
    header('Location: admin_commerce_gcash');
    exit;
}

$settings = commerce_get_payment_settings($conn);
$qrSrc = !empty($settings['gcash_qr_path']) ? ereview_url($settings['gcash_qr_path']) : '';

$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Commerce'], ['GCash Settings']];
$adminHeroIcon = 'qr-code';
$adminHeroTitle = 'GCash Settings';
$adminHeroSubtitle = 'Static QR and account details for enrollment. OCR later verifies receipts only — not a GCash API.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>
  <div class="p-4 sm:p-6 lg:p-8 max-w-3xl mx-auto w-full">
    <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

    <?php if (!empty($_SESSION['message'])): ?>
      <div class="admin-alert admin-alert--success mb-4"><?php echo h($_SESSION['message']); unset($_SESSION['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
      <div class="admin-alert admin-alert--error mb-4"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="quiz-admin-table-shell rounded-2xl p-5 sm:p-6 space-y-5">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

      <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
        OCR confidence threshold, receipt max age, and Vision fallback apply to Phase 6 receipt verification.
        Auto-verify sets the payment to <strong>paid</strong> only — it does <strong>not</strong> grant LMS access, sync SCA, or activate the student (Phase 7). Uncertain matches go to needs_review. This is not direct GCash settlement confirmation.
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">GCash account name</label>
          <input class="input-custom w-full" name="gcash_account_name" value="<?php echo h($settings['gcash_account_name'] ?? ''); ?>" required>
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">GCash number</label>
          <input class="input-custom w-full" name="gcash_number" value="<?php echo h($settings['gcash_number'] ?? ''); ?>" required>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Payment instructions</label>
        <textarea class="input-custom w-full" name="payment_instructions" rows="4" placeholder="Shown on the payment step…"><?php echo h($settings['payment_instructions'] ?? ''); ?></textarea>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">QR image</label>
          <input class="input-custom w-full" type="file" name="gcash_qr" accept="image/jpeg,image/png,image/webp">
          <?php if ($qrSrc): ?>
            <label class="inline-flex items-center gap-2 text-sm mt-2 cursor-pointer">
              <input type="checkbox" name="remove_qr" value="1"> Remove current QR
            </label>
          <?php endif; ?>
        </div>
        <div>
          <?php if ($qrSrc): ?>
            <img src="<?php echo h($qrSrc); ?>" alt="GCash QR" class="max-w-[180px] rounded-xl border border-white/10 bg-white p-2">
          <?php else: ?>
            <p class="text-sm opacity-60">No QR uploaded yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">OCR confidence threshold</label>
          <input class="input-custom w-full" type="number" min="50" max="100" step="0.01" name="ocr_confidence_threshold" value="<?php echo h((string)($settings['ocr_confidence_threshold'] ?? 85)); ?>">
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Receipt max age (days)</label>
          <input class="input-custom w-full" type="number" min="1" name="receipt_max_age_days" value="<?php echo (int)($settings['receipt_max_age_days'] ?? 7); ?>">
        </div>
        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-sm font-semibold cursor-pointer">
            <input type="checkbox" name="vision_fallback_enabled" value="1" <?php echo !empty($settings['vision_fallback_enabled']) ? 'checked' : ''; ?>> Vision AI fallback (optional)
          </label>
        </div>
      </div>

      <button type="submit" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-xl font-semibold inline-flex items-center gap-2"><i class="bi bi-check-lg"></i> Save settings</button>
    </form>
  </div>
</div>
</main>
</body>
</html>
