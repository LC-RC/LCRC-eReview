<?php
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/profile_avatar.php';
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/commerce_student_admin.php';
require_once __DIR__ . '/includes/commerce_payment.php';
require_once __DIR__ . '/includes/student_activity.php';
require_once __DIR__ . '/remember_me.php';
require_once __DIR__ . '/includes/schema_introspection.php';
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/platform_access.php';

$userId = sanitizeInt($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: admin_dashboard');
    exit;
}

student_activity_ensure_schema($conn);

$hasProfilePicture = false;
$hasUseDefaultAvatar = false;
$cp1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
if ($cp1 && mysqli_fetch_assoc($cp1)) $hasProfilePicture = true;
$cp2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
if ($cp2 && mysqli_fetch_assoc($cp2)) $hasUseDefaultAvatar = true;

$selectCols = "user_id, full_name, email, review_type, school, school_other, payment_proof, role, status, access_start, access_end, access_months, created_at, updated_at";
$enrollCols = ['enrollment_path', 'selected_package_id', 'selected_lesson_ids_json'];
foreach ($enrollCols as $ecol) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $ecol) . "'");
    if ($chk && mysqli_fetch_assoc($chk)) {
        $selectCols .= ', ' . $ecol;
    }
}
foreach (['is_online', 'last_seen_at', 'last_logout_at', 'last_login_at', 'last_login_ip', 'last_login_user_agent'] as $pcol) {
    if (ereview_schema_column_exists($conn, 'users', $pcol)) {
        $selectCols .= ', ' . $pcol;
    }
}
if ($hasProfilePicture) $selectCols .= ", profile_picture";
if ($hasUseDefaultAvatar) $selectCols .= ", use_default_avatar";
if (ereview_schema_column_exists($conn, 'users', 'section')) {
    $selectCols .= ', section';
}
if (ereview_schema_column_exists($conn, 'users', 'student_number')) {
    $selectCols .= ', student_number';
}
if (ereview_schema_column_exists($conn, 'users', 'college_examination_access')) {
    $selectCols .= ', college_examination_access, college_examination_enabled_at, college_examination_enabled_by';
}

$stmt = mysqli_prepare($conn, "SELECT $selectCols FROM users WHERE user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user || $user['role'] !== 'student') {
    header('Location: admin_dashboard');
    exit;
}

$commerce = commerce_admin_student_detail_summary($conn, $user);
$isCommerceEnrollment = !empty($commerce['is_commerce']);
$isPaidPath = !empty($commerce['is_paid_path']);
$isFreeAccess = !empty($commerce['is_free_access']);
$latestPayment = $commerce['latest_payment'] ?? null;

$schoolLabel = $user['school'] === 'Other' && !empty($user['school_other']) ? $user['school_other'] : $user['school'];
$avatarPath = ereview_avatar_public_path($user['profile_picture'] ?? '');
$useDefaultAvatar = $hasUseDefaultAvatar ? !empty($user['use_default_avatar']) : true;
$avatarInitial = ereview_avatar_initial($user['full_name'] ?? 'U');
$legacyHasPaymentProof = !empty($user['payment_proof']);
$legacyPaymentProofUrl = 'admin_payment_proof?user_id=' . (int)$user['user_id'];
$commerceProofUrl = ($latestPayment && !empty($latestPayment['has_proof']))
    ? ereview_url('payment_proof_file') . '?payment_id=' . (int) $latestPayment['payment_id']
    : '';
$csrf = generateCSRFToken();
$pageTitle = 'Student Details - ' . $user['full_name'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard'], ['Students', 'admin_students'], [ h($user['full_name']) ] ];

$activityQuizAttempts = student_activity_fetch_quiz_attempts_for_user($conn, $userId, 12);
$activityPreboardAttempts = student_activity_fetch_preboard_attempts_for_user($conn, $userId, 8);
$activityEvents = student_activity_fetch_events($conn, $userId, 30);
$activityVideoProgress = student_activity_fetch_video_progress_for_user($conn, $userId, 40);
$activityDevices = function_exists('getRememberedDevices') ? getRememberedDevices($userId) : [];
$lastSeenLabel = '-';
if (!empty($user['last_seen_at'])) {
    $ago = time() - strtotime((string) $user['last_seen_at']);
    if ($ago < 120) {
        $lastSeenLabel = 'Active now';
    } elseif ($ago < 3600) {
        $lastSeenLabel = max(1, (int) floor($ago / 60)) . 'm ago';
    } elseif ($ago < 86400) {
        $lastSeenLabel = max(1, (int) floor($ago / 3600)) . 'h ago';
    } else {
        $lastSeenLabel = date('M j, Y g:i A', strtotime((string) $user['last_seen_at']));
    }
}
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
    .view-approve-access {
      margin-top: 0.35rem;
      padding: 0.75rem;
      border-radius: 0.75rem;
      border: 1px solid rgba(148, 163, 184, 0.28);
      background: rgba(15, 23, 42, 0.45);
    }
    .view-approve-access .sca-tree {
      max-height: 16rem; overflow-y: auto; padding-right: 0.25rem;
    }
    .view-approve-access .sca-tree details {
      border: 1px solid rgba(148, 163, 184, 0.28); border-radius: 0.55rem;
      margin-bottom: 0.4rem; padding: 0.3rem 0.55rem; background: rgba(30, 41, 59, 0.75);
    }
    .view-approve-access .sca-tree summary {
      cursor: pointer; font-weight: 700; color: #e2e8f0; list-style: none; font-size: 0.84rem;
    }
    .view-approve-access .sca-tree summary::-webkit-details-marker { display: none; }
    .view-approve-access .sca-tree label {
      display: flex; align-items: center; gap: 0.4rem; padding: 0.2rem 0 0.2rem 0.85rem;
      font-size: 0.8rem; color: #cbd5e1; cursor: pointer; border-radius: 0.35rem;
    }
    .view-approve-access .sca-tree label:hover { background: rgba(51, 65, 85, 0.65); }
    .view-approve-access .sca-tree input[type=checkbox] { accent-color: #34d399; width: 0.95rem; height: 0.95rem; }
    .view-approve-access .text-gray-100 { color: #f1f5f9 !important; }
    .view-approve-access .text-gray-500 { color: #94a3b8 !important; }
    .view-approve-access .sca-tree-hint { color: #94a3b8; }
    .view-approve-access .sca-subject-summary { display: flex; align-items: center; gap: 0.4rem; }
    .view-approve-access .sca-chevron {
      width: 0.5rem; height: 0.5rem; border-right: 2px solid #94a3b8; border-bottom: 2px solid #94a3b8;
      transform: rotate(-45deg); flex-shrink: 0;
    }
    .view-approve-access details[open] > summary .sca-chevron { transform: rotate(45deg); }
    .view-approve-access .sca-subject-summary__meta {
      font-size: 0.65rem; font-weight: 700; color: #cbd5e1; background: rgba(51, 65, 85, 0.9);
      border-radius: 999px; padding: 0.1rem 0.4rem; margin-left: auto;
    }
    .view-approve-access .sca-grant-all {
      align-items: flex-start !important; margin: 0.35rem 0 0.45rem; padding: 0.45rem 0.55rem !important;
      border: 1px solid rgba(52, 211, 153, 0.35); border-radius: 0.5rem; background: rgba(6, 78, 59, 0.35);
    }
    .view-approve-access .sca-grant-all__title { display: block; font-weight: 800; color: #a7f3d0; font-size: 0.8rem; }
    .view-approve-access .sca-grant-all__sub { display: block; font-size: 0.7rem; color: #86efac; }
    .view-approve-access .sca-topic-list { border-left: 2px solid rgba(148, 163, 184, 0.35); margin-left: 0.3rem; padding-left: 0.35rem; }
    .view-approve-access .sca-topic-list__head { color: #94a3b8; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; margin: 0.3rem 0 0.2rem; }
    .view-approve-access .sca-topic-check { color: #f1f5f9 !important; font-weight: 600; }
    html[data-admin-theme="light"] .view-approve-access {
      background: #f8fafc;
      border-color: rgba(15, 23, 42, 0.12);
    }
    html[data-admin-theme="light"] .view-approve-access .sca-tree details {
      background: #ffffff;
      border-color: rgba(15, 23, 42, 0.12);
    }
    html[data-admin-theme="light"] .view-approve-access .sca-tree summary,
    html[data-admin-theme="light"] .view-approve-access .text-gray-100,
    html[data-admin-theme="light"] .view-approve-access .sca-topic-check { color: #0f172a !important; }
    html[data-admin-theme="light"] .view-approve-access .sca-tree label { color: #334155; }
    html[data-admin-theme="light"] .view-approve-access .sca-tree label:hover { background: rgba(37, 99, 235, 0.08); }
    html[data-admin-theme="light"] .view-approve-access .text-gray-500,
    html[data-admin-theme="light"] .view-approve-access .sca-tree-hint,
    html[data-admin-theme="light"] .view-approve-access .sca-topic-list__head { color: #64748b !important; }
    html[data-admin-theme="light"] .view-approve-access .sca-chevron { border-color: #64748b; }
    html[data-admin-theme="light"] .view-approve-access .sca-subject-summary__meta {
      color: #334155; background: #e2e8f0;
    }
    html[data-admin-theme="light"] .view-approve-access .sca-grant-all {
      border-color: #86efac; background: #ecfdf5;
    }
    html[data-admin-theme="light"] .view-approve-access .sca-grant-all__title { color: #166534; }
    html[data-admin-theme="light"] .view-approve-access .sca-grant-all__sub { color: #15803d; }
  </style>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-6 py-5 mb-5 page-hero admin-glass-hero">
    <div class="admin-page-header">
      <div class="min-w-0">
        <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
        <h1 class="admin-page-header__title flex flex-wrap items-center gap-3 m-0">
          <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
          <span><?php echo h($user['full_name'] ?? 'Student Details'); ?></span>
        </h1>
        <p class="admin-page-header__subtitle">Registration, commerce, and account activation · ID <?php echo (int)$user['user_id']; ?></p>
      </div>
      <div class="admin-page-header__actions">
        <a href="admin_students" class="admin-btn admin-btn--secondary"><i class="bi bi-arrow-left"></i> Back to list</a>
        <?php if ($commerceProofUrl !== ''): ?>
          <a href="<?php echo h($commerceProofUrl); ?>" data-admin-proof
             data-proof-title="Proof · <?php echo h($user['full_name'] ?? 'Student'); ?>"
             class="admin-btn admin-btn--primary"><i class="bi bi-receipt"></i> View Proof</a>
        <?php endif; ?>
        <?php if ($latestPayment): ?>
          <a href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $latestPayment['payment_id']); ?>" class="admin-btn admin-btn--secondary"><i class="bi bi-credit-card"></i> View Payment</a>
        <?php endif; ?>
        <?php
          $heroAccessTone = (string) ($commerce['commerce_access']['tone'] ?? 'none');
          $heroPay = is_array($latestPayment ?? null) ? $latestPayment : [];
          $heroNeedsRemind = $heroAccessTone !== 'active'
              && (int) ($heroPay['payment_id'] ?? 0) > 0
              && (string) ($heroPay['status'] ?? '') === 'awaiting_proof'
              && trim((string) ($heroPay['proof_path'] ?? '')) === '';
          if ($heroNeedsRemind):
        ?>
          <form method="post" action="<?php echo h(ereview_url('admin_remind_upload_proof')); ?>" class="inline"
                data-admin-confirm-title="Remind to upload proof"
                data-admin-confirm="Email this student a secure link to upload GCash proof? The link is valid for 7 days."
                data-admin-confirm-ok="Send reminder"
                data-admin-confirm-icon="<i class=&quot;bi bi-envelope&quot;></i>">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
            <input type="hidden" name="payment_id" value="<?php echo (int) ($heroPay['payment_id'] ?? 0); ?>">
            <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int) $user['user_id']; ?>">
            <button type="submit" class="admin-btn admin-btn--secondary"><i class="bi bi-envelope"></i> Remind to upload</button>
          </form>
        <?php endif; ?>
        <?php if (!$heroNeedsRemind && $heroAccessTone !== 'active' && strtolower((string) ($user['status'] ?? '')) !== 'rejected'): ?>
          <form method="post" action="<?php echo h(ereview_url('admin_grant_access')); ?>" class="inline"
                data-admin-confirm-title="Grant Access"
                data-admin-confirm="Grant Full LMS access for 6 months? Open payment reviews with proof will also be marked approved."
                data-admin-confirm-ok="Confirm grant"
                data-admin-confirm-icon="<i class=&quot;bi bi-key&quot;></i>">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int) $user['user_id']; ?>">
            <input type="hidden" name="months" value="6">
            <input type="hidden" name="activate_login" value="1">
            <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int) $user['user_id']; ?>">
            <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-key"></i> Grant Access</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-flash admin-flash--success mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-flash admin-flash--error mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-7">
      <div class="rounded-xl shadow-card border p-5 page-table">
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
            <?php
              $status = strtolower((string)$user['status']);
              if ($status === 'approved') {
                  $badgeClass = 'bg-green-100 text-green-800';
              } elseif ($status === 'rejected') {
                  $badgeClass = 'bg-red-100 text-red-800';
              } elseif ($status === 'archived') {
                  $badgeClass = 'bg-slate-200 text-slate-800';
              } else {
                  $badgeClass = 'bg-amber-100 text-amber-800';
              }
            ?>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>"><?php echo h(ucfirst($status)); ?></span>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Registered</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['created_at']); ?></div>
          </div>
        </div>
      </div>

      <?php
      $collExAccess = function_exists('ereview_user_college_examination_access_value')
          ? ereview_user_college_examination_access_value($user)
          : 'none';
      $collExActive = ereview_user_has_college_examination_access($conn, $userId, $user);
      ?>
      <div class="rounded-xl shadow-card border p-5 page-table mt-5" id="college-examination">
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-clipboard-check"></i> College Examination</h2>
        <div class="space-y-2 mb-4 text-sm">
          <div><span class="text-gray-500">Access:</span> <span class="font-semibold text-gray-800"><?php echo h($collExActive ? 'Active' : ($collExAccess === 'suspended' ? 'Suspended' : 'Disabled')); ?></span></div>
          <?php if (!empty($user['college_examination_enabled_at'])): ?>
          <div><span class="text-gray-500">Enabled at:</span> <span class="font-semibold text-gray-800"><?php echo h((string)$user['college_examination_enabled_at']); ?></span></div>
          <?php endif; ?>
          <?php if ($collExActive): ?>
          <div><span class="text-gray-500">Student number:</span> <span class="font-semibold text-gray-800"><?php echo trim((string)($user['student_number'] ?? '')) !== '' ? h((string)$user['student_number']) : '—'; ?></span></div>
          <div><span class="text-gray-500">Section:</span> <span class="font-semibold text-gray-800"><?php echo trim((string)($user['section'] ?? '')) !== '' ? h((string)$user['section']) : '—'; ?></span></div>
          <div><span class="text-gray-500">Review type:</span> <span class="font-semibold text-gray-800"><?php echo h((string)($user['review_type'] ?? 'reviewee')); ?></span></div>
          <?php endif; ?>
        </div>
        <?php if (!$collExActive): ?>
        <form method="post" action="<?php echo h(ereview_url('admin_student_access_api.php')); ?>" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="enable_college_examination">
          <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
          <input type="hidden" name="redirect" value="1">
          <div>
            <label class="block text-sm text-gray-600 mb-1" for="coll_ex_sn">Student Number</label>
            <input type="text" id="coll_ex_sn" name="student_number" maxlength="32" class="w-full border rounded-lg px-3 py-2" placeholder="Optional">
          </div>
          <div>
            <label class="block text-sm text-gray-600 mb-1" for="coll_ex_section">Section</label>
            <?php
              require_once __DIR__ . '/examination/includes/college_sections.php';
              $collExSections = college_sections_active_names($conn);
            ?>
            <select id="coll_ex_section" name="section" class="w-full border rounded-lg px-3 py-2" <?php echo $collExSections === [] ? 'disabled' : ''; ?>>
              <option value="__none__">No section</option>
              <?php foreach ($collExSections as $secOpt): ?>
                <option value="<?php echo h($secOpt); ?>"><?php echo h($secOpt); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm text-gray-600 mb-1" for="coll_ex_rt">Review Type</label>
            <select id="coll_ex_rt" name="review_type" class="w-full border rounded-lg px-3 py-2">
              <option value="undergrad">Undergrad (College Student)</option>
              <option value="reviewee">Reviewee</option>
            </select>
          </div>
          <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-check2-circle"></i> Enable College Examination</button>
          <p class="text-xs text-gray-500 mb-0">Updates this existing account only. Does not change eReview grants or create a duplicate user.</p>
        </form>
        <?php else: ?>
        <p class="text-sm text-gray-600 mb-0">College Examination is already enabled for this account. The student keeps <code>role=student</code> and uses the portal selector when both modules are active.</p>
        <?php endif; ?>
      </div>

      <?php require __DIR__ . '/includes/admin_student_commerce_panel.php'; ?>
    </div>
    <div class="lg:col-span-5">
      <div class="rounded-xl shadow-card border p-5 page-table">
        <h2 id="account-window-edit" class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-calendar-check"></i> Account activation</h2>
        <?php if (strtolower((string) $user['status']) === 'archived'): ?>
          <div class="rounded-lg border border-slate-300 bg-slate-50 text-slate-800 text-sm p-3 mb-4">
            This student is <strong>Archived</strong>. Restore the account from Student Management before changing the access window or grants.
          </div>
        <?php endif; ?>
        <div class="space-y-2 mb-4 text-sm">
          <div><span class="text-gray-500">Login status:</span> <span class="font-semibold text-gray-800"><?php echo h((string) $commerce['account_label']); ?></span></div>
          <div><span class="text-gray-500">Account window start:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_start'] ? h($user['access_start']) : '-'; ?></span></div>
          <div><span class="text-gray-500">Account window end:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_end'] ? h($user['access_end']) : '-'; ?></span></div>
          <div><span class="text-gray-500">Stored months equiv.:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_months'] !== null ? (int)$user['access_months'] : '-'; ?></span></div>
          <div><span class="text-gray-500">Commerce content access:</span> <span class="font-semibold text-gray-800"><?php echo h((string) ($commerce['commerce_access']['label'] ?? 'None')); ?></span></div>
          <div class="pt-1">
            <a class="text-sm font-semibold underline text-sky-600" href="<?php echo h(ereview_url('admin_commerce_grants') . '?user_id=' . (int) $user['user_id']); ?>">View Commerce Grants</a>
          </div>
        </div>

        <?php
          $acctIsApproved = strtolower((string) $user['status']) === 'approved';
          $payToneUi = '';
          $accessToneUi = (string) ($commerce['commerce_access']['tone'] ?? 'none');
          $latestPayStatus = (string) (($latestPayment['status'] ?? ''));
          $latestVStatus = (string) (($latestPayment['verification_status'] ?? ''));
          $latestFulfilled = !empty($latestPayment['fulfilled']);
          if (!empty($isPaidPath) && $latestPayment) {
              if ($latestPayStatus === 'rejected' || in_array($latestVStatus, ['manually_rejected', 'failed'], true)) {
                  $payToneUi = 'rejected';
              } elseif ($latestPayStatus === 'pending_verification' || $latestVStatus === 'needs_review') {
                  $payToneUi = 'review';
              } elseif ($latestPayStatus === 'paid' && in_array($latestVStatus, ['auto_verified', 'manually_approved'], true)) {
                  $payToneUi = 'verified';
              } elseif ($latestPayStatus === 'awaiting_proof') {
                  $payToneUi = 'awaiting';
              }
          }
        ?>
        <?php if ($isCommerceEnrollment && $acctIsApproved && $accessToneUi === 'active' && ($payToneUi === 'verified' || !empty($isFreeAccess))): ?>
          <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-950 mb-3">
            <div class="font-bold flex items-center gap-2"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Active</div>
            <div class="text-xs mt-1">
              <?php if (!empty($isPaidPath)): ?>Payment Verified · <?php endif; ?>
              Access Granted · Login activated automatically after commerce success.
            </div>
          </div>
          <?php
            $awStartVal = !empty($user['access_start']) ? date('Y-m-d\TH:i', strtotime((string) $user['access_start'])) : date('Y-m-d\TH:i');
            $awEndVal = !empty($user['access_end']) ? date('Y-m-d\TH:i', strtotime((string) $user['access_end'])) : date('Y-m-d\TH:i', strtotime('+6 months'));
          ?>
          <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3 mb-3 space-y-3" x-data="{ awMode: 'extend' }">
            <div class="text-sm font-bold text-gray-800">Edit account window</div>
            <p class="text-xs text-gray-500 m-0">Login account dates only - not commerce grants or Edit content permissions (SCA).</p>
            <div class="flex flex-wrap gap-2 text-xs">
              <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="aw_mode_ui" value="extend" x-model="awMode"> Extend</label>
              <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="aw_mode_ui" value="set" x-model="awMode"> Set new duration</label>
              <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="aw_mode_ui" value="custom" x-model="awMode"> Custom dates</label>
            </div>

            <form class="space-y-2" action="extend_access" method="POST" x-show="awMode !== 'custom'">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="mode" :value="awMode">
              <div class="flex flex-wrap items-end gap-2">
                <div>
                  <label class="block text-xs text-gray-500 mb-1" for="awDurationValue">Duration</label>
                  <input id="awDurationValue" type="number" min="1" max="3660" name="duration_value" class="input-custom w-24" value="1" required>
                </div>
                <div>
                  <label class="block text-xs text-gray-500 mb-1" for="awDurationUnit">Unit</label>
                  <select id="awDurationUnit" name="duration_unit" class="input-custom w-28">
                    <option value="hour">Hours</option>
                    <option value="day">Days</option>
                    <option value="month" selected>Months</option>
                    <option value="year">Years</option>
                  </select>
                </div>
                <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition inline-flex items-center gap-2">
                  <i class="bi bi-calendar2-check"></i>
                  <span x-text="awMode === 'set' ? 'Set window' : 'Extend window'"></span>
                </button>
              </div>
              <p class="text-xs text-gray-500 m-0" x-show="awMode === 'extend'">Adds time to the current end date (or from now if expired/missing).</p>
              <p class="text-xs text-gray-500 m-0" x-show="awMode === 'set'">Replaces the window: start = now, end = now + duration.</p>
            </form>

            <form class="space-y-2" action="extend_access" method="POST" x-show="awMode === 'custom'" x-cloak>
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="mode" value="custom">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                  <label class="block text-xs text-gray-500 mb-1" for="awStart">Start</label>
                  <input id="awStart" type="datetime-local" name="access_start" class="input-custom w-full" value="<?php echo h($awStartVal); ?>" required>
                </div>
                <div>
                  <label class="block text-xs text-gray-500 mb-1" for="awEnd">End</label>
                  <input id="awEnd" type="datetime-local" name="access_end" class="input-custom w-full" value="<?php echo h($awEndVal); ?>" required>
                </div>
              </div>
              <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition inline-flex items-center gap-2"><i class="bi bi-pencil-square"></i> Save custom dates</button>
            </form>
          </div>
          <a href="admin_student_access?user_id=<?php echo (int)$user['user_id']; ?>" class="mt-3 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-semibold border-2 border-[#1665A0] text-[#1665A0] hover:bg-[#1665A0] hover:text-white transition no-underline"><i class="bi bi-shield-lock"></i> Edit content permissions (SCA)</a>
          <p class="text-xs text-gray-500 mt-2 mb-0">Manual SCA edits are administrative. They are not the same as a paid purchase or Free Access grant.</p>
        <?php elseif ($isCommerceEnrollment && !$acctIsApproved): ?>
          <?php if ($payToneUi === 'review'): ?>
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 mb-3">
              <div class="font-bold">Payment Review Required</div>
              <p class="text-xs mt-1 mb-0">Account stays Pending Activation until payment is verified and fulfilled.</p>
            </div>
            <?php if (!empty($latestPayment['payment_id'])): ?>
              <a class="admin-btn admin-btn--primary admin-btn--sm mb-3 inline-flex" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $latestPayment['payment_id']); ?>">Review Payment</a>
            <?php endif; ?>
          <?php elseif ($payToneUi === 'rejected'): ?>
            <div class="rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-950 mb-3">
              <div class="font-bold">Payment Rejected</div>
              <p class="text-xs mt-1 mb-0">No commerce access was granted. Account remains Pending Activation.</p>
            </div>
          <?php elseif ($payToneUi === 'verified' && $latestFulfilled && $accessToneUi === 'active'): ?>
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 mb-3">
              <div class="font-bold">Commerce access granted - login repair needed</div>
              <p class="text-xs mt-1 mb-0">Payment was verified and fulfilled, but login is still pending. Use repair activation below.</p>
            </div>
          <?php elseif (!empty($isFreeAccess) && (($commerce['far']['status'] ?? '') === 'pending')): ?>
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 mb-3">
              <div class="font-bold">Free Access Review Required</div>
              <p class="text-xs mt-1 mb-0">Account activates automatically when the Free Access request is approved.</p>
            </div>
          <?php else: ?>
            <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900 mb-3">
              Paid enrollments activate login automatically after verification + fulfillment.
              Free Access activates after FAR approval. Use repair activation only if commerce succeeded but login is still pending.
            </div>
          <?php endif; ?>
          <?php if ($accessToneUi === 'active'): ?>
          <form class="space-y-3" action="activate_user" method="POST" id="studentViewApproveForm">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <input type="hidden" name="grant_full_lms" value="0">
            <input type="hidden" name="permissions" value="[]">
            <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int)$user['user_id']; ?>">
            <label class="block text-sm font-semibold text-gray-300 mb-1" for="viewApproveMonths">Account window</label>
            <div class="flex flex-wrap gap-2 items-end">
              <input id="viewApproveMonths" type="number" min="1" max="3660" name="duration_value" class="input-custom w-28" value="6" required>
              <select name="duration_unit" class="input-custom w-28" aria-label="Duration unit">
                <option value="hour">Hours</option>
                    <option value="day">Days</option>
                <option value="month" selected>Months</option>
                <option value="year">Years</option>
              </select>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-wrench"></i> Repair Activation</button>
          </form>
          <?php endif; ?>
          <form class="mt-2" action="reject" method="POST"
                data-admin-confirm-title="Reject student"
                data-admin-confirm="Reject this student? They will not be able to access the LMS."
                data-admin-confirm-ok="Reject"
                data-admin-confirm-icon="<i class=&quot;bi bi-x-octagon&quot;></i>">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition inline-flex items-center justify-center gap-2"><i class="bi bi-x-circle"></i> Reject</button>
          </form>
        <?php elseif (!$acctIsApproved): ?>
          <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 mb-3">
            Legacy / non-commerce student. Approving can set <strong>Edit content permissions (SCA)</strong> via the SCA picker below. This is not a paid purchase.
          </div>
          <form class="space-y-3" action="activate_user" method="POST" id="studentViewApproveForm"
                x-data="viewApproveAccessPicker()" x-init="init()"
                @submit="prepareSubmit($event)">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <input type="hidden" name="grant_full_lms" :value="hasFullLms ? '1' : '0'">
            <input type="hidden" name="permissions" :value="JSON.stringify(hasFullLms ? [{content_type:'full_lms',content_id:0}] : permissions)">
            <label class="block text-sm font-semibold text-gray-300 mb-1" for="viewApproveMonths">Account window</label>
            <div class="flex flex-wrap gap-2 items-end mb-2">
              <input id="viewApproveMonths" type="number" min="1" max="3660" name="duration_value" class="input-custom w-28" value="6" required>
              <select name="duration_unit" class="input-custom w-28" aria-label="Duration unit">
                <option value="hour">Hours</option>
                    <option value="day">Days</option>
                <option value="month" selected>Months</option>
                <option value="year">Years</option>
              </select>
            </div>
            <div class="view-approve-access">
              <p class="text-xs font-semibold text-slate-300 mb-1">Edit content permissions (SCA)</p>
              <?php
                $scaTreeScope = 'viewapprove';
                require __DIR__ . '/includes/admin_sca_permission_tree.php';
              ?>
              <p class="text-xs text-gray-500 m-0 mt-2" x-show="loadingCatalog">Loading content catalog...</p>
              <p class="text-xs text-emerald-400 m-0 mt-2" x-text="'Access: ' + activePermCount"></p>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-check2-circle"></i> Approve account &amp; set manual access</button>
          </form>
          <form class="mt-2" action="reject" method="POST"
                data-admin-confirm-title="Reject student"
                data-admin-confirm="Reject this student? They will not be able to access the LMS."
                data-admin-confirm-ok="Reject"
                data-admin-confirm-icon="<i class=&quot;bi bi-x-octagon&quot;></i>">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition inline-flex items-center justify-center gap-2"><i class="bi bi-x-circle"></i> Reject</button>
          </form>
        <?php else: ?>
          <?php
            $awStartVal = !empty($user['access_start']) ? date('Y-m-d\TH:i', strtotime((string) $user['access_start'])) : date('Y-m-d\TH:i');
            $awEndVal = !empty($user['access_end']) ? date('Y-m-d\TH:i', strtotime((string) $user['access_end'])) : date('Y-m-d\TH:i', strtotime('+6 months'));
          ?>
          <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3 mb-3 space-y-3" x-data="{ awMode: 'extend' }">
            <div class="text-sm font-bold text-gray-800">Edit account window</div>
            <div class="flex flex-wrap gap-2 text-xs">
              <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="aw_mode_ui_legacy" value="extend" x-model="awMode"> Extend</label>
              <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="aw_mode_ui_legacy" value="set" x-model="awMode"> Set new duration</label>
              <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="aw_mode_ui_legacy" value="custom" x-model="awMode"> Custom dates</label>
            </div>
            <form class="space-y-2" action="extend_access" method="POST" x-show="awMode !== 'custom'">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="mode" :value="awMode">
              <div class="flex flex-wrap items-end gap-2">
                <input type="number" min="1" max="3660" name="duration_value" class="input-custom w-24" value="1" required>
                <select name="duration_unit" class="input-custom w-28">
                  <option value="hour">Hours</option>
                    <option value="day">Days</option>
                  <option value="month" selected>Months</option>
                  <option value="year">Years</option>
                </select>
                <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition inline-flex items-center gap-2">
                  <i class="bi bi-calendar2-check"></i>
                  <span x-text="awMode === 'set' ? 'Set window' : 'Extend window'"></span>
                </button>
              </div>
            </form>
            <form class="space-y-2" action="extend_access" method="POST" x-show="awMode === 'custom'" x-cloak>
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int)$user['user_id']; ?>">
              <input type="hidden" name="mode" value="custom">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <input type="datetime-local" name="access_start" class="input-custom w-full" value="<?php echo h($awStartVal); ?>" required>
                <input type="datetime-local" name="access_end" class="input-custom w-full" value="<?php echo h($awEndVal); ?>" required>
              </div>
              <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition inline-flex items-center gap-2"><i class="bi bi-pencil-square"></i> Save custom dates</button>
            </form>
          </div>
          <a href="admin_student_access?user_id=<?php echo (int)$user['user_id']; ?>" class="mt-3 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-semibold border-2 border-[#1665A0] text-[#1665A0] hover:bg-[#1665A0] hover:text-white transition no-underline"><i class="bi bi-shield-lock"></i> Edit content permissions (SCA)</a>
          <p class="text-xs text-gray-500 mt-2 mb-0">Manual SCA edits are administrative. They are not the same as a paid purchase or Free Access grant.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <section class="rounded-xl shadow-card border p-5 page-table mt-5" id="student-activity">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <h2 class="text-lg font-bold text-gray-800 m-0 flex items-center gap-2"><i class="bi bi-activity"></i> Activity</h2>
      <a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_student_live">Live board</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 text-sm">
      <div class="rounded-lg border p-3">
        <div class="text-xs uppercase text-gray-500">Presence</div>
        <div class="font-semibold text-gray-800"><?php echo h($lastSeenLabel); ?></div>
      </div>
      <div class="rounded-lg border p-3">
        <div class="text-xs uppercase text-gray-500">Last login</div>
        <div class="font-semibold text-gray-800"><?php echo !empty($user['last_login_at']) ? h(date('M j, Y g:i A', strtotime((string) $user['last_login_at']))) : '-'; ?></div>
        <?php if (!empty($user['last_login_ip'])): ?>
          <div class="text-xs text-gray-500 mt-1"><?php echo h((string) $user['last_login_ip']); ?></div>
        <?php endif; ?>
      </div>
      <div class="rounded-lg border p-3">
        <div class="text-xs uppercase text-gray-500">Remembered devices</div>
        <div class="font-semibold text-gray-800"><?php echo count($activityDevices); ?></div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
      <div>
        <h3 class="text-sm font-bold text-gray-700 mb-2">Recent quiz attempts</h3>
        <?php if ($activityQuizAttempts === []): ?>
          <p class="text-sm text-gray-500 m-0">No quiz attempts yet.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="admin-table w-full text-sm">
              <thead><tr><th>Quiz</th><th>Status</th><th>Score</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($activityQuizAttempts as $qa): ?>
                  <tr>
                    <td>
                      <div class="font-semibold"><?php echo h($qa['quiz_title'] ?? ''); ?></div>
                      <div class="text-xs text-gray-500"><?php echo h($qa['subject_name'] ?? ''); ?></div>
                    </td>
                    <td><?php echo h((string) ($qa['status'] ?? '')); ?></td>
                    <td><?php echo isset($qa['score']) ? h(number_format((float) $qa['score'], 1) . '%') : '-'; ?></td>
                    <td><a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_quiz_attempt_review?attempt_id=<?php echo (int) $qa['attempt_id']; ?>">Review</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <h3 class="text-sm font-bold text-gray-700 mb-2">Recent preboard attempts</h3>
        <?php if ($activityPreboardAttempts === []): ?>
          <p class="text-sm text-gray-500 m-0">No preboard attempts yet.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="admin-table w-full text-sm">
              <thead><tr><th>Set</th><th>Status</th><th>Score</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($activityPreboardAttempts as $pa): ?>
                  <tr>
                    <td>
                      <div class="font-semibold">Set <?php echo h($pa['set_label'] ?? ''); ?></div>
                      <div class="text-xs text-gray-500"><?php echo h($pa['subject_name'] ?? ''); ?></div>
                    </td>
                    <td><?php echo h((string) ($pa['status'] ?? '')); ?></td>
                    <td><?php echo isset($pa['score']) ? h(number_format((float) $pa['score'], 1) . '%') : '-'; ?></td>
                    <td><a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_preboards_attempt_review?preboards_attempt_id=<?php echo (int) $pa['preboards_attempt_id']; ?>">Review</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <h3 class="text-sm font-bold text-gray-700 mb-2">Video watch progress (this student only)</h3>
    <?php if ($activityVideoProgress === []): ?>
      <p class="text-sm text-gray-500 mb-4">No video watch data yet for this student.</p>
    <?php else:
      $videoFolders = [];
      foreach ($activityVideoProgress as $vp) {
          $subj = trim((string) ($vp['subject_name'] ?? 'Subject'));
          $les = trim((string) ($vp['lesson_title'] ?? 'Lesson'));
          $key = $subj . "\0" . $les;
          if (!isset($videoFolders[$key])) {
              $videoFolders[$key] = ['subject' => $subj, 'lesson' => $les, 'items' => []];
          }
          $videoFolders[$key]['items'][] = $vp;
      }
    ?>
      <div class="space-y-3 mb-4">
        <?php foreach ($videoFolders as $folder): ?>
          <details class="rounded-xl border page-table" open>
            <summary class="cursor-pointer px-3 py-2.5 font-semibold text-sm list-none">
              <i class="bi bi-folder2-open"></i>
              <?php echo h($folder['subject']); ?>
              <span class="opacity-50">/</span>
              <?php echo h($folder['lesson']); ?>
              <span class="text-xs font-normal opacity-60 ml-1"><?php echo count($folder['items']); ?> video(s)</span>
            </summary>
            <div class="overflow-x-auto border-t">
              <table class="admin-table w-full text-sm">
                <thead>
                  <tr>
                    <th>Video</th>
                    <th>Stopped at</th>
                    <th>Watched</th>
                    <th>%</th>
                    <th>Updated</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($folder['items'] as $vp):
                    $vPos = (float) ($vp['position_sec'] ?? 0);
                    $vDur = isset($vp['duration_sec']) ? (float) $vp['duration_sec'] : null;
                    $vWatch = (float) ($vp['watch_seconds'] ?? 0);
                    $vPct = (float) ($vp['percent'] ?? 0);
                  ?>
                    <tr>
                      <td class="font-semibold"><?php echo h((string) ($vp['video_title'] ?? 'Video #' . (int) ($vp['video_id'] ?? 0))); ?></td>
                      <td class="whitespace-nowrap">
                        <?php echo h(student_activity_format_duration($vPos)); ?>
                        <?php if ($vDur !== null && $vDur > 0): ?> / <?php echo h(student_activity_format_duration($vDur)); ?><?php endif; ?>
                      </td>
                      <td class="whitespace-nowrap"><?php echo h(student_activity_format_duration($vWatch)); ?></td>
                      <td><?php echo h(number_format($vPct, 0)); ?>%</td>
                      <td class="whitespace-nowrap text-xs text-gray-500"><?php echo !empty($vp['updated_at']) ? h(date('M j, g:i A', strtotime((string) $vp['updated_at']))) : '-'; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h3 class="text-sm font-bold text-gray-700 mb-2">Content timeline</h3>
    <?php if ($activityEvents === []): ?>
      <p class="text-sm text-gray-500 m-0">No lesson/video/handout events yet. Events appear as the student uses the LMS.</p>
    <?php else: ?>
      <ul class="space-y-2 m-0 p-0 list-none text-sm">
        <?php foreach ($activityEvents as $ev): ?>
          <li class="rounded-lg border px-3 py-2 flex flex-wrap justify-between gap-2">
            <span>
              <strong><?php echo h(student_activity_event_label((string) ($ev['event_type'] ?? ''))); ?></strong>
              <?php if (!empty($ev['page_title'])): ?>
                <span class="text-gray-600"> - <?php echo h((string) $ev['page_title']); ?></span>
              <?php endif; ?>
            </span>
            <span class="text-xs text-gray-500 whitespace-nowrap"><?php echo !empty($ev['created_at']) ? h(date('M j, g:i A', strtotime((string) $ev['created_at']))) : ''; ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($activityDevices !== []): ?>
      <h3 class="text-sm font-bold text-gray-700 mt-4 mb-2">Remembered devices</h3>
      <ul class="space-y-1 m-0 p-0 list-none text-sm">
        <?php foreach ($activityDevices as $dev): ?>
          <li class="rounded-lg border px-3 py-2">
            <span class="font-semibold"><?php echo h(mb_substr((string) ($dev['last_used_user_agent'] ?? 'Remembered device'), 0, 80)); ?></span>
            <span class="text-xs text-gray-500 block">
              <?php if (!empty($dev['last_used_ip'])): ?>IP <?php echo h((string) $dev['last_used_ip']); ?> · <?php endif; ?>
              Expires <?php echo !empty($dev['expires_at']) ? h(date('M j, Y', strtotime((string) $dev['expires_at']))) : '-'; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
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

  document.addEventListener('alpine:init', function () {
    Alpine.data('viewApproveAccessPicker', function () {
      return {
        catalog: { subjects: [], preboard_subjects: [], preweek_units: [], test_bank: [] },
        permissions: [{ content_type: 'full_lms', content_id: 0 }],
        loadingCatalog: false,
        get permissionListKey() { return 'permissions'; },
        get activePermissionList() { return this.permissions || []; },
        get hasFullLms() {
          return this.activePermissionList.some(function (p) {
            return p.content_type === 'full_lms' && Number(p.content_id) === 0;
          });
        },
        get activePermCount() {
          if (this.hasFullLms) return 'Full LMS';
          var n = this.activePermissionList.length;
          return n === 0 ? 'None selected' : n + ' item' + (n === 1 ? '' : 's');
        },
        async init() {
          this.loadingCatalog = true;
          try {
            var res = await fetch('admin_student_access_api?action=catalog', { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return {}; });
            if (res.ok && data.ok && data.catalog) this.catalog = data.catalog;
          } catch (e) { /* ignore */ }
          this.loadingCatalog = false;
        },
        isChecked: function (type, id) {
          return this.activePermissionList.some(function (p) {
            return p.content_type === type && Number(p.content_id) === Number(id);
          });
        },
        toggle: function (type, id, on) {
          this.permissions = this.permissions.filter(function (p) {
            return !(p.content_type === type && Number(p.content_id) === Number(id));
          });
          if (on) this.permissions.push({ content_type: type, content_id: Number(id) });
        },
        toggleFullLms: function (on) {
          this.permissions = this.permissions.filter(function (p) { return p.content_type !== 'full_lms'; });
          if (on) this.permissions.push({ content_type: 'full_lms', content_id: 0 });
        },
        prepareSubmit: function (e) {
          if (!this.hasFullLms && (!this.permissions || this.permissions.length === 0)) {
            e.preventDefault();
            if (window.adminUiDialog) {
              window.adminUiDialog.notice({
                type: 'info',
                title: 'Select access',
                message: 'Select Full LMS access or at least one content item before approving.'
              });
            }
          }
        }
      };
    });
  });
</script>
<?php include __DIR__ . '/includes/components/admin_proof_modal.php'; ?>
<?php include __DIR__ . '/includes/admin_ui_dialogs.php'; ?>
</body>
</html>
