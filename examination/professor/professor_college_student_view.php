<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__, 2) . '/includes/profile_avatar.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';

$pageTitle = 'Examinee profile';
$csrf = generateCSRFToken();
$userId = sanitizeInt($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: professor_college_students');
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT user_id, full_name, email, status, created_at, access_end, school, section, student_number, profile_picture, use_default_avatar, review_type, role, college_examination_access
     FROM users WHERE user_id=? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$u || !ereview_user_has_college_examination_access($conn, $userId, $u)) {
    $_SESSION['message'] = 'Examinee not found.';
    header('Location: professor_college_students');
    exit;
}

$editFlash = null;
$editErr = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $editErr = 'Invalid security token.';
    } elseif ($action === 'save_examinee_type') {
        $newType = strtolower(trim((string)($_POST['review_type'] ?? '')));
        if (!in_array($newType, ['undergrad', 'reviewee'], true)) {
            $editErr = 'Invalid examinee type.';
        } else {
            $upd = mysqli_prepare($conn, "UPDATE users SET review_type=? WHERE user_id=?");
            mysqli_stmt_bind_param($upd, 'si', $newType, $userId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            $u['review_type'] = $newType;
            $editFlash = 'Examinee type updated.';
        }
    } elseif ($action === 'save_student_number') {
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
                $clr = mysqli_prepare($conn, "UPDATE users SET student_number=NULL WHERE user_id=?");
                mysqli_stmt_bind_param($clr, 'i', $userId);
                mysqli_stmt_execute($clr);
                mysqli_stmt_close($clr);
                $u['student_number'] = null;
            } else {
                $upd = mysqli_prepare($conn, "UPDATE users SET student_number=? WHERE user_id=?");
                mysqli_stmt_bind_param($upd, 'si', $sn, $userId);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
                $u['student_number'] = $sn;
            }
            $editFlash = 'Student number saved.';
        }
    }
}

$rtLower = strtolower(trim((string)($u['review_type'] ?? 'reviewee')));
$examineeTypeLabel = ($rtLower === 'undergrad') ? 'College Student' : 'Reviewee';
$avatarSrc = ereview_avatar_img_src((string)($u['profile_picture'] ?? ''));
$useDefault = !empty($u['use_default_avatar']);
$initial = ereview_avatar_initial((string)($u['full_name'] ?? ''));
$schoolLabel = trim((string)($u['school'] ?? ''));
if ($schoolLabel === '') {
    $schoolLabel = '-';
}
$accessEndFmt = '';
if (!empty($u['access_end'])) {
    $ts = strtotime((string)$u['access_end']);
    $accessEndFmt = $ts !== false ? date('M j, Y', $ts) : '-';
} else {
    $accessEndFmt = '-';
}
$createdFmt = '';
if (!empty($u['created_at'])) {
    $ts2 = strtotime((string)$u['created_at']);
    $createdFmt = $ts2 !== false ? date('M j, Y', $ts2) : '-';
}

$statusBadgeClass = match ((string)($u['status'] ?? '')) {
    'approved' => 'admin-badge--success',
    'pending' => 'admin-badge--warning',
    'rejected' => 'admin-badge--danger',
    default => 'admin-badge--neutral',
};

$pageTitle = 'Examinee profile';
$adminHeroIcon = 'person-circle';
$adminHeroTitle = (string)$u['full_name'];
$adminHeroSubtitle = $examineeTypeLabel . ' · ID ' . (int)$u['user_id'];
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_college_students"><i class="bi bi-arrow-left"></i> Back to directory</a>'
    . '<span class="admin-badge ' . $statusBadgeClass . '">' . h((string)$u['status']) . '</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($editFlash): ?><div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($editFlash); ?></span></div><?php endif; ?>
  <?php if ($editErr): ?><div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($editErr); ?></span></div><?php endif; ?>

  <div class="examination-page-shell">
    <div class="rounded-xl overflow-hidden page-table p-5 mb-4">
      <div class="flex flex-col sm:flex-row sm:items-center gap-5">
        <div class="examination-avatar-box shrink-0" id="viewAvatarBox">
          <?php if ($avatarSrc !== '' && !$useDefault): ?>
            <img src="<?php echo h($avatarSrc); ?>" alt="" class="examination-profile-avatar" width="80" height="80"
                 onerror="document.getElementById('viewAvatarBox').classList.add('show-fallback');">
          <?php endif; ?>
          <span class="examination-profile-avatar-fallback" <?php echo ($avatarSrc === '' || $useDefault) ? ' style="display:flex"' : ''; ?>><?php echo h($initial); ?></span>
        </div>
        <div class="min-w-0">
          <p class="text-sm opacity-70 m-0"><?php echo h((string)$u['email']); ?></p>
          <p class="text-sm mt-1 mb-0"><?php echo h($examineeTypeLabel); ?> · <?php echo h($schoolLabel); ?><?php echo trim((string)($u['section'] ?? '')) !== '' ? ' · Section ' . h((string)$u['section']) : ''; ?></p>
        </div>
      </div>
    </div>

    <h2 class="examination-section-title"><i class="bi bi-card-list"></i> Profile details</h2>
    <div class="examination-info-grid mb-4">
      <div class="examination-info-tile">
        <div class="examination-info-tile-k">Student number</div>
        <div class="examination-info-tile-v"><?php $snv = trim((string)($u['student_number'] ?? '')); echo $snv !== '' ? h($snv) : '-'; ?></div>
      </div>
      <div class="examination-info-tile">
        <div class="examination-info-tile-k">Examinee type</div>
        <div class="examination-info-tile-v"><?php echo h($examineeTypeLabel); ?> <span class="text-xs opacity-70">(review_type=<?php echo h((string)($u['review_type'] ?? '')); ?>)</span></div>
      </div>
      <div class="examination-info-tile">
        <div class="examination-info-tile-k">Email</div>
        <div class="examination-info-tile-v"><a class="hover:underline break-all" href="mailto:<?php echo h($u['email']); ?>"><?php echo h($u['email']); ?></a></div>
      </div>
      <div class="examination-info-tile">
        <div class="examination-info-tile-k">Section</div>
        <div class="examination-info-tile-v"><?php echo h((string)($u['section'] ?? '-')); ?></div>
      </div>
      <div class="examination-info-tile">
        <div class="examination-info-tile-k">School</div>
        <div class="examination-info-tile-v"><?php echo h($schoolLabel); ?></div>
      </div>
      <div class="examination-info-tile">
        <div class="examination-info-tile-k">Access end</div>
        <div class="examination-info-tile-v"><?php echo h($accessEndFmt); ?></div>
      </div>
      <div class="examination-info-tile">
        <div class="examination-info-tile-k">Created</div>
        <div class="examination-info-tile-v"><?php echo h($createdFmt); ?></div>
      </div>
    </div>

    <h2 class="examination-section-title"><i class="bi bi-pencil-square"></i> Correct examinee type</h2>
    <form method="post" action="professor_college_student_view?id=<?php echo (int)$userId; ?>" class="rounded-xl overflow-hidden page-table p-4 mb-4 max-w-xl">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="save_examinee_type">
      <p class="text-sm opacity-80 mb-3">Use this to fix accounts created before college students used <code>review_type=undergrad</code>. This does not change login role (<code>college_student</code>).</p>
      <div class="flex flex-col gap-2 text-sm mb-3">
        <label class="inline-flex items-center gap-2"><input type="radio" name="review_type" value="undergrad" <?php echo $rtLower === 'undergrad' ? 'checked' : ''; ?>> College Student (undergrad)</label>
        <label class="inline-flex items-center gap-2"><input type="radio" name="review_type" value="reviewee" <?php echo $rtLower !== 'undergrad' ? 'checked' : ''; ?>> Reviewee</label>
      </div>
      <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-check2-circle"></i> Save examinee type</button>
    </form>

    <h2 class="examination-section-title"><i class="bi bi-hash"></i> Official student number</h2>
    <form method="post" action="professor_college_student_view?id=<?php echo (int)$userId; ?>" class="rounded-xl overflow-hidden page-table p-4 max-w-xl">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="save_student_number">
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Student number (for reports)</label>
      <input type="text" name="student_number" maxlength="32" pattern="[A-Za-z0-9_-]*" class="w-full" placeholder="e.g. 2008435" value="<?php echo h(trim((string)($u['student_number'] ?? ''))); ?>">
      <p class="examination-form-hint">Used on exam monitor and Excel exports. Leave blank to clear.</p>
      <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm mt-3"><i class="bi bi-check2-circle"></i> Save</button>
    </form>
  </div>
</body>
</html>
