<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/profile_avatar.php';

$pageTitle = 'College student';
$csrf = generateCSRFToken();
$userId = sanitizeInt($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: professor_college_students.php');
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT user_id, full_name, email, status, created_at, access_end, school, section, student_number, profile_picture, use_default_avatar, review_type
     FROM users WHERE user_id=? AND role='college_student' LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$u) {
    $_SESSION['message'] = 'Student not found.';
    header('Location: professor_college_students.php');
    exit;
}

$editFlash = null;
$editErr = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_student_number') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $editErr = 'Invalid security token.';
    } else {
        $sn = trim($_POST['student_number'] ?? '');
        if ($sn !== '' && (strlen($sn) > 32 || !preg_match('/^[A-Za-z0-9_-]+$/', $sn))) {
            $editErr = 'Student number must be at most 32 characters (letters, digits, hyphen, underscore).';
        } elseif ($sn !== '') {
            $dup = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE student_number=? AND user_id<>? LIMIT 1');
            mysqli_stmt_bind_param($dup, 'si', $sn, $userId);
            mysqli_stmt_execute($dup);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($dup))) {
                $editErr = 'That student number is already assigned.';
            }
            mysqli_stmt_close($dup);
        }
        if ($editErr === null) {
            if ($sn === '') {
                $clr = mysqli_prepare($conn, "UPDATE users SET student_number=NULL WHERE user_id=? AND role='college_student'");
                mysqli_stmt_bind_param($clr, 'i', $userId);
                mysqli_stmt_execute($clr);
                mysqli_stmt_close($clr);
                $u['student_number'] = null;
            } else {
                $upd = mysqli_prepare($conn, "UPDATE users SET student_number=? WHERE user_id=? AND role='college_student'");
                mysqli_stmt_bind_param($upd, 'si', $sn, $userId);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
                $u['student_number'] = $sn;
            }
            $editFlash = 'Student number saved.';
        }
    }
}

$avatarSrc = ereview_avatar_img_src((string)($u['profile_picture'] ?? ''));
$useDefault = !empty($u['use_default_avatar']);
$initial = ereview_avatar_initial((string)($u['full_name'] ?? ''));
$schoolLabel = trim((string)($u['school'] ?? ''));
if ($schoolLabel === '') {
    $schoolLabel = '—';
}
$accessEndFmt = '';
if (!empty($u['access_end'])) {
    $ts = strtotime((string)$u['access_end']);
    $accessEndFmt = $ts !== false ? date('M j, Y', $ts) : '—';
} else {
    $accessEndFmt = '—';
}
$createdFmt = '';
if (!empty($u['created_at'])) {
    $ts2 = strtotime((string)$u['created_at']);
    $createdFmt = $ts2 !== false ? date('M j, Y', $ts2) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .prof-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    .view-hero {
      border-radius: .85rem; border: 1px solid rgba(22,163,74,.22);
      background: linear-gradient(180deg, #f4fff8 0%, #fff 55%);
      box-shadow: 0 12px 28px -22px rgba(21,128,61,.45);
    }
    .view-avatar {
      width: 5.5rem; height: 5.5rem; border-radius: 999px; border: 3px solid #bbf7d0; object-fit: cover; background: #ecfdf3;
    }
    .view-avatar-fallback {
      width: 5.5rem; height: 5.5rem; border-radius: 999px; border: 3px solid #bbf7d0; background: #ecfdf3; color: #166534;
      display: none; align-items: center; justify-content: center; font-size: 1.75rem; font-weight: 900;
    }
    .avatar-box.show-fallback .view-avatar { display: none; }
    .avatar-box.show-fallback .view-avatar-fallback { display: flex; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
    .info-tile { border: 1px solid #d1fae5; border-radius: .65rem; padding: .85rem 1rem; background: #fff; }
    .info-tile-k { font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
    .info-tile-v { margin-top: .25rem; font-weight: 700; color: #14532d; }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>
  <main class="dashboard-shell w-full max-w-none px-1 pb-8">
    <div class="mb-4">
      <a href="professor_college_students.php" class="inline-flex items-center gap-2 text-sm font-semibold text-[#15803d] hover:underline">
        <i class="bi bi-arrow-left"></i> Back to directory
      </a>
    </div>
    <div class="view-hero p-6 mb-6">
      <div class="flex flex-col sm:flex-row sm:items-center gap-5">
        <div class="avatar-box shrink-0" id="viewAvatarBox">
          <?php if ($avatarSrc !== '' && !$useDefault): ?>
            <img src="<?php echo h($avatarSrc); ?>" alt="" class="view-avatar" width="88" height="88"
                 onerror="document.getElementById('viewAvatarBox').classList.add('show-fallback');">
          <?php endif; ?>
          <span class="view-avatar-fallback" <?php echo ($avatarSrc === '' || $useDefault) ? ' style="display:flex"' : ''; ?>><?php echo h($initial); ?></span>
        </div>
        <div class="min-w-0">
          <h1 class="text-2xl font-bold text-[#14532d] m-0"><?php echo h((string)$u['full_name']); ?></h1>
          <p class="text-sm text-slate-600 mt-1 mb-0">College student · ID <?php echo (int)$u['user_id']; ?></p>
          <span class="inline-flex mt-2 px-2.5 py-0.5 rounded-full text-xs font-bold border <?php echo (string)$u['status'] === 'approved' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ((string)$u['status'] === 'pending' ? 'bg-amber-50 text-amber-900 border-amber-200' : 'bg-red-50 text-red-800 border-red-200'); ?>">
            <?php echo h((string)$u['status']); ?>
          </span>
        </div>
      </div>
    </div>
    <?php if ($editFlash): ?><div class="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm font-semibold"><?php echo h($editFlash); ?></div><?php endif; ?>
    <?php if ($editErr): ?><div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-900 text-sm font-semibold"><?php echo h($editErr); ?></div><?php endif; ?>

    <h2 class="text-lg font-extrabold text-[#14532d] mb-3">Profile details</h2>
    <div class="info-grid">
      <div class="info-tile">
        <div class="info-tile-k">Student number</div>
        <div class="info-tile-v"><?php $snv = trim((string)($u['student_number'] ?? '')); echo $snv !== '' ? h($snv) : '—'; ?></div>
      </div>
      <div class="info-tile">
        <div class="info-tile-k">Email</div>
        <div class="info-tile-v"><a class="text-[#1665A0] hover:underline break-all" href="mailto:<?php echo h($u['email']); ?>"><?php echo h($u['email']); ?></a></div>
      </div>
      <div class="info-tile">
        <div class="info-tile-k">Section</div>
        <div class="info-tile-v"><?php echo h((string)($u['section'] ?? '—')); ?></div>
      </div>
      <div class="info-tile">
        <div class="info-tile-k">School</div>
        <div class="info-tile-v"><?php echo h($schoolLabel); ?></div>
      </div>
      <div class="info-tile">
        <div class="info-tile-k">Access end</div>
        <div class="info-tile-v"><?php echo h($accessEndFmt); ?></div>
      </div>
      <div class="info-tile">
        <div class="info-tile-k">Created</div>
        <div class="info-tile-v"><?php echo h($createdFmt); ?></div>
      </div>
    </div>

    <h2 class="text-lg font-extrabold text-[#14532d] mb-3 mt-6">Official student number</h2>
    <form method="post" action="professor_college_student_view.php?id=<?php echo (int)$userId; ?>" class="max-w-xl rounded-lg border border-slate-200 bg-white p-4">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="save_student_number">
      <label class="block text-sm font-semibold text-green-800 mb-1">Student number (for reports)</label>
      <input type="text" name="student_number" maxlength="32" pattern="[A-Za-z0-9_-]*" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="e.g. 2008435" value="<?php echo h(trim((string)($u['student_number'] ?? ''))); ?>">
      <p class="text-xs text-slate-500 mt-2 mb-0">Used on exam monitor and Excel exports. Leave blank to clear.</p>
      <button type="submit" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">Save</button>
    </form>
  </main>
</body>
</html>
