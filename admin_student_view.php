<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/profile_avatar.php';

$userId = sanitizeInt($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: admin_dashboard.php');
    exit;
}

$hasProfilePicture = false;
$hasUseDefaultAvatar = false;
$cp1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
if ($cp1 && mysqli_fetch_assoc($cp1)) $hasProfilePicture = true;
$cp2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
if ($cp2 && mysqli_fetch_assoc($cp2)) $hasUseDefaultAvatar = true;

$selectCols = "user_id, full_name, email, review_type, school, school_other, payment_proof, role, status, access_start, access_end, access_months, created_at, updated_at";
if ($hasProfilePicture) $selectCols .= ", profile_picture";
if ($hasUseDefaultAvatar) $selectCols .= ", use_default_avatar";

$stmt = mysqli_prepare($conn, "SELECT $selectCols FROM users WHERE user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user || $user['role'] !== 'student') {
    header('Location: admin_dashboard.php');
    exit;
}

$schoolLabel = $user['school'] === 'Other' && !empty($user['school_other']) ? $user['school_other'] : $user['school'];
$avatarPath = ereview_avatar_public_path($user['profile_picture'] ?? '');
$useDefaultAvatar = $hasUseDefaultAvatar ? !empty($user['use_default_avatar']) : true;
$avatarInitial = ereview_avatar_initial($user['full_name'] ?? 'U');
$hasPaymentProof = !empty($user['payment_proof']);
$paymentProofUrl = 'admin_payment_proof.php?user_id=' . (int)$user['user_id'];
$paymentProofExt = $hasPaymentProof ? strtolower((string)pathinfo((string)$user['payment_proof'], PATHINFO_EXTENSION)) : '';
$isProofImage = in_array($paymentProofExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
$isProofPdf = ($paymentProofExt === 'pdf');
$csrf = generateCSRFToken();
$pageTitle = 'Student Details - ' . $user['full_name'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Students', 'admin_students.php'], [ h($user['full_name']) ] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .student-profile-hero {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      gap: 0.35rem;
      padding: 0.4rem 0 0.7rem;
      margin-bottom: 1.1rem;
    }
    .student-profile-hero__avatar-wrap {
      position: relative;
      width: 6.25rem;
      height: 6.25rem;
      border-radius: 9999px;
      padding: 3px;
      background: linear-gradient(135deg, rgba(14, 165, 233, 0.6), rgba(99, 102, 241, 0.6));
      box-shadow: 0 8px 20px rgba(30, 41, 59, 0.22);
      cursor: pointer;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .student-profile-hero__avatar-wrap:hover {
      transform: translateY(-1px) scale(1.02);
      box-shadow: 0 12px 24px rgba(30, 41, 59, 0.3);
    }
    .student-profile-hero__avatar {
      width: 100%;
      height: 100%;
      border-radius: 9999px;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #2563eb;
      color: #fff;
      font-size: 1.9rem;
      font-weight: 800;
      text-transform: uppercase;
    }
    .student-profile-hero__avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .student-profile-hero__name {
      font-size: 0.95rem;
      font-weight: 700;
      color: #e2e8f0;
      margin-top: 0.35rem;
    }
    .student-profile-hero__caption {
      color: #94a3b8;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.09em;
      font-weight: 700;
    }
    .student-profile-hero__hint {
      margin-top: 0.1rem;
      color: #64748b;
      font-size: 0.73rem;
      font-weight: 600;
    }
    .proof-viewer {
      margin-top: 0.45rem;
      border: 1px solid rgba(255, 255, 255, 0.10);
      border-radius: 0.9rem;
      background: #141414;
      overflow: hidden;
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.75);
    }
    .proof-viewer__head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.65rem;
      padding: 0.6rem 0.9rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.10);
      background: #141414;
    }
    .proof-viewer__title {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      color: #fafafa;
      font-size: 0.8rem;
      font-weight: 700;
    }
    .proof-viewer__open {
      border: 1px solid rgba(255, 255, 255, 0.28);
      color: #fafafa;
      background: rgba(255, 255, 255, 0.06);
      border-radius: 0.55rem;
      font-size: 0.75rem;
      font-weight: 700;
      padding: 0.34rem 0.7rem;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .proof-viewer__open:hover {
      background: #fafafa;
      color: #0a0a0a;
      border-color: rgba(255, 255, 255, 0.7);
    }
    .proof-viewer__body {
      height: 21rem;
      background: #141414;
    }
    .proof-viewer__image {
      width: 100%;
      height: 100%;
      object-fit: contain;
      background: #141414;
      cursor: zoom-in;
    }
    .proof-viewer__frame {
      width: 100%;
      height: 100%;
      border: 0;
      background: #141414;
    }
    .proof-empty {
      margin-top: 0.45rem;
      border: 1px dashed rgba(255, 255, 255, 0.25);
      border-radius: 0.85rem;
      color: #e4e4e7;
      background: #141414;
      font-size: 0.84rem;
      font-weight: 600;
      padding: 0.75rem 0.9rem;
    }
    .media-modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      background: rgba(2, 6, 23, 0.82);
      backdrop-filter: blur(4px);
      z-index: 1400;
    }
    .media-modal.is-open { display: flex; }
    .media-modal__dialog {
      width: min(92vw, 900px);
      max-height: 92vh;
      border-radius: 0.95rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: linear-gradient(180deg, #0f172a 0%, #020617 100%);
      box-shadow: 0 24px 80px rgba(2, 6, 23, 0.75);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .media-modal__head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.7rem 0.85rem;
      border-bottom: 1px solid rgba(148, 163, 184, 0.3);
      color: #e2e8f0;
    }
    .media-modal__title {
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .media-modal__close {
      width: 2rem;
      height: 2rem;
      border-radius: 0.55rem;
      border: 1px solid rgba(148, 163, 184, 0.45);
      background: rgba(15, 23, 42, 0.7);
      color: #e2e8f0;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .media-modal__close:hover {
      background: #1e293b;
      border-color: #94a3b8;
    }
    .media-modal__body {
      flex: 1;
      min-height: 18rem;
      display: flex;
      align-items: center;
      justify-content: center;
      background: radial-gradient(circle at 10% 20%, rgba(30, 58, 138, 0.16), transparent 48%), #020617;
      padding: 0.85rem;
    }
    .media-modal__image {
      max-width: 100%;
      max-height: calc(92vh - 7rem);
      border-radius: 0.7rem;
      object-fit: contain;
      box-shadow: 0 16px 42px rgba(2, 6, 23, 0.65);
      background: #0f172a;
    }
  </style>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5 flex flex-wrap justify-between items-center gap-4">
    <div>
      <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
      <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
        <i class="bi bi-person-badge"></i> Student Details
      </h1>
      <p class="text-gray-500 mt-1">View registration, approve or reject, and manage access. ID: <?php echo (int)$user['user_id']; ?></p>
    </div>
    <div class="flex gap-2">
      <a href="admin_students.php" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition inline-flex items-center gap-2"><i class="bi bi-arrow-left"></i> Back to list</a>
      <?php if ($hasPaymentProof): ?>
        <a href="#payment-proof-section" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition inline-flex items-center gap-2"><i class="bi bi-receipt"></i> View Proof</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-7">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-card-text"></i> Registration Info</h2>
        <div class="student-profile-hero">
          <button
            type="button"
            class="student-profile-hero__avatar-wrap js-open-media-modal"
            data-media-src="<?php echo h($avatarPath !== '' && !$useDefaultAvatar ? $avatarPath : ''); ?>"
            data-media-title="<?php echo h($user['full_name']); ?> profile picture"
            aria-label="View full profile picture">
            <span class="student-profile-hero__avatar">
              <?php if ($avatarPath !== '' && !$useDefaultAvatar): ?>
                <img src="<?php echo h($avatarPath); ?>" alt="<?php echo h($user['full_name']); ?> profile photo" loading="lazy">
              <?php else: ?>
                <?php echo h($avatarInitial); ?>
              <?php endif; ?>
            </span>
          </button>
          <div class="student-profile-hero__name"><?php echo h($user['full_name']); ?></div>
          <div class="student-profile-hero__caption">Student Profile</div>
          <?php if ($avatarPath !== '' && !$useDefaultAvatar): ?>
            <div class="student-profile-hero__hint"><i class="bi bi-zoom-in"></i> Click photo to view full size</div>
          <?php endif; ?>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <div class="text-gray-500 text-sm">Full Name</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['full_name']); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Email</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['email']); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Review Type</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['review_type']); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">School</div>
            <div class="font-semibold text-gray-800"><?php echo h($schoolLabel); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Status</div>
            <?php $status = strtolower((string)$user['status']); $badgeClass = $status === 'approved' ? 'bg-green-100 text-green-800' : ($status === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'); ?>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>"><?php echo h($user['status']); ?></span>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Registered</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['created_at']); ?></div>
          </div>
          <div class="md:col-span-2" id="payment-proof-section">
            <div class="text-gray-500 text-sm">Payment Proof</div>
            <?php if ($hasPaymentProof): ?>
              <div class="proof-viewer">
                <div class="proof-viewer__head">
                  <span class="proof-viewer__title">
                    <i class="bi bi-shield-check"></i>
                    Uploaded proof preview
                  </span>
                  <a href="<?php echo h($paymentProofUrl); ?>" target="_blank" rel="noopener" class="proof-viewer__open">Open original</a>
                </div>
                <div class="proof-viewer__body">
                  <?php if ($isProofImage): ?>
                    <img
                      src="<?php echo h($paymentProofUrl); ?>"
                      alt="Payment proof of <?php echo h($user['full_name']); ?>"
                      class="proof-viewer__image js-open-media-modal"
                      data-media-src="<?php echo h($paymentProofUrl); ?>"
                      data-media-title="Payment proof of <?php echo h($user['full_name']); ?>"
                      loading="lazy">
                  <?php elseif ($isProofPdf): ?>
                    <iframe
                      src="<?php echo h($paymentProofUrl); ?>"
                      class="proof-viewer__frame"
                      title="Payment proof document"></iframe>
                  <?php else: ?>
                    <iframe
                      src="<?php echo h($paymentProofUrl); ?>"
                      class="proof-viewer__frame"
                      title="Payment proof file preview"></iframe>
                  <?php endif; ?>
                </div>
              </div>
            <?php else: ?>
              <div class="proof-empty"><i class="bi bi-info-circle"></i> No proof uploaded.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="lg:col-span-5">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-calendar-check"></i> Access</h2>
        <div class="space-y-2 mb-4">
          <div><span class="text-gray-500 text-sm">Start:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_start'] ? h($user['access_start']) : '-'; ?></span></div>
          <div><span class="text-gray-500 text-sm">End:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_end'] ? h($user['access_end']) : '-'; ?></span></div>
          <div><span class="text-gray-500 text-sm">Months:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_months'] !== null ? (int)$user['access_months'] : '-'; ?></span></div>
        </div>
        <?php if (strtolower((string)$user['status']) !== 'approved'): ?>
          <form class="flex flex-wrap gap-2" action="activate_user.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <input type="number" min="1" max="24" name="months" class="input-custom w-28" placeholder="Months" required>
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-check2-circle"></i> Approve</button>
          </form>
          <form class="mt-2" action="reject.php" method="POST" onsubmit="return confirm('Reject this student?');">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition inline-flex items-center justify-center gap-2"><i class="bi bi-x-circle"></i> Reject</button>
          </form>
        <?php else: ?>
          <form class="flex flex-wrap gap-2" action="extend_access.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <input type="number" min="1" max="24" name="months" class="input-custom w-28" placeholder="+Months" required>
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> Extend</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</main>
<div id="mediaPreviewModal" class="media-modal" aria-hidden="true">
  <section class="media-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mediaPreviewTitle">
    <header class="media-modal__head">
      <h3 id="mediaPreviewTitle" class="media-modal__title">Image preview</h3>
      <button type="button" id="mediaPreviewCloseBtn" class="media-modal__close" aria-label="Close preview">
        <i class="bi bi-x-lg"></i>
      </button>
    </header>
    <div class="media-modal__body">
      <img id="mediaPreviewImage" class="media-modal__image" alt="Preview">
    </div>
  </section>
</div>
<script>
  (function () {
    var modal = document.getElementById('mediaPreviewModal');
    var image = document.getElementById('mediaPreviewImage');
    var title = document.getElementById('mediaPreviewTitle');
    var closeBtn = document.getElementById('mediaPreviewCloseBtn');
    if (!modal || !image || !title || !closeBtn) return;

    function openModal(src, text) {
      if (!src) return;
      image.src = src;
      image.alt = text || 'Preview';
      title.textContent = text || 'Image preview';
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      image.src = '';
    }

    document.querySelectorAll('.js-open-media-modal').forEach(function (el) {
      el.addEventListener('click', function () {
        var src = el.getAttribute('data-media-src') || el.getAttribute('src') || '';
        var text = el.getAttribute('data-media-title') || 'Image preview';
        if (!src) return;
        openModal(src, text);
      });
    });

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();
    });
  })();
</script>
</body>
</html>
