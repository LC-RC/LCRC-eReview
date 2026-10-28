<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';
require_once dirname(__DIR__) . '/includes/college_student_number.php';
require_once dirname(__DIR__) . '/includes/college_student_number_display.php';
require_once dirname(__DIR__) . '/includes/college_school_assignment.php';
require_once dirname(__DIR__) . '/includes/college_schools.php';
require_once dirname(__DIR__, 2) . '/includes/profile_avatar.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';

$pageTitle = 'Examinee profile';
$csrf = generateCSRFToken();
$userId = sanitizeInt($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: professor_college_students');
    exit;
}

college_sections_ensure_schema($conn);
$sectionOptions = college_sections_active_names($conn);
$schoolColsReady = college_school_assignment_columns_ready($conn);
$schoolAssignmentReady = college_school_assignment_schema_ready($conn);
$schoolSelectCols = $schoolColsReady ? ', college_school_assigned_at, college_school_assigned_by' : '';

$stmt = mysqli_prepare(
    $conn,
    "SELECT user_id, full_name, email, status, created_at, access_end, school, section, student_number, profile_picture, use_default_avatar, review_type, role, college_examination_access{$schoolSelectCols}
     FROM users WHERE user_id=? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$role = (string) ($u['role'] ?? '');
$platformColsReady = ereview_platform_access_columns_ready($conn);
$inRoster = false;
if ($u) {
    if ($role === 'college_student') {
        $inRoster = true;
    } elseif ($platformColsReady && $role === 'student') {
        $accessVal = ereview_user_college_examination_access_value($u);
        $inRoster = in_array($accessVal, ['active', 'suspended'], true);
    }
}
if (!$u || !$inRoster) {
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
    } elseif ($action === 'approve_account' && $role === 'college_student') {
        $existingSection = trim((string) ($u['section'] ?? ''));
        $sectionRaw = trim((string) ($_POST['section'] ?? ''));
        $sectionVal = null;
        if ($sectionRaw !== '') {
            $canonical = college_sections_resolve_active_name($conn, $sectionRaw);
            if ($canonical === null) {
                $editErr = 'Select an active section from the list.';
            } else {
                $sectionVal = $canonical;
            }
        }
        if ($editErr === null && $existingSection === '' && $sectionVal === null) {
            $editErr = 'Assign a section before approving this student.';
        }
        if ($editErr === null) {
            if ($sectionVal !== null) {
                $upd = mysqli_prepare($conn, "UPDATE users SET status='approved', section=? WHERE user_id=? AND role='college_student' LIMIT 1");
                mysqli_stmt_bind_param($upd, 'si', $sectionVal, $userId);
            } else {
                $upd = mysqli_prepare($conn, "UPDATE users SET status='approved' WHERE user_id=? AND role='college_student' LIMIT 1");
                mysqli_stmt_bind_param($upd, 'i', $userId);
            }
            mysqli_stmt_execute($upd);
            $changed = mysqli_stmt_affected_rows($upd) > 0;
            mysqli_stmt_close($upd);
            if ($changed) {
                $u['status'] = 'approved';
                if ($sectionVal !== null) {
                    $u['section'] = $sectionVal;
                }
                $editFlash = 'Account approved. The student can now sign in.';
            } else {
                $editErr = 'Could not approve account.';
            }
        }
    } elseif ($action === 'reject_account' && $role === 'college_student') {
        $upd = mysqli_prepare($conn, "UPDATE users SET status='rejected' WHERE user_id=? AND role='college_student' LIMIT 1");
        mysqli_stmt_bind_param($upd, 'i', $userId);
        mysqli_stmt_execute($upd);
        $changed = mysqli_stmt_affected_rows($upd) > 0;
        mysqli_stmt_close($upd);
        if ($changed) {
            $u['status'] = 'rejected';
            $editFlash = 'Registration rejected.';
        } else {
            $editErr = 'Could not reject account.';
        }
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
        $currentSn = trim((string) ($u['student_number'] ?? ''));
        $staffErr = college_student_number_staff_change_error($currentSn, $sn);
        if ($staffErr !== null) {
            $editErr = $staffErr;
        } elseif ($sn !== '') {
            $dup = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE student_number=? AND user_id<>? LIMIT 1');
            if (!$dup) {
                $editErr = 'Could not validate student number.';
            } else {
                mysqli_stmt_bind_param($dup, 'si', $sn, $userId);
                if (!mysqli_stmt_execute($dup)) {
                    mysqli_stmt_close($dup);
                    $editErr = 'Could not validate student number.';
                } else {
                    if (mysqli_fetch_assoc(mysqli_stmt_get_result($dup))) {
                        $editErr = 'That student number is already assigned.';
                    }
                    mysqli_stmt_close($dup);
                }
            }
        }
        if ($editErr === null) {
            if (college_student_number_is_generated($currentSn)) {
                $editFlash = 'Generated student number is unchanged.';
            } elseif ($sn === '') {
                $clr = mysqli_prepare($conn, 'UPDATE users SET student_number=NULL WHERE user_id=? LIMIT 1');
                if (!$clr) {
                    $editErr = 'Could not clear student number.';
                } else {
                    mysqli_stmt_bind_param($clr, 'i', $userId);
                    if (!mysqli_stmt_execute($clr)) {
                        mysqli_stmt_close($clr);
                        $editErr = 'Could not clear student number.';
                    } else {
                        mysqli_stmt_close($clr);
                        $u['student_number'] = null;
                        $editFlash = 'Student number saved.';
                    }
                }
            } else {
                $upd = mysqli_prepare($conn, 'UPDATE users SET student_number=? WHERE user_id=? LIMIT 1');
                if (!$upd) {
                    $editErr = 'Could not save student number.';
                } else {
                    mysqli_stmt_bind_param($upd, 'si', $sn, $userId);
                    if (!mysqli_stmt_execute($upd)) {
                        $errno = mysqli_errno($conn);
                        mysqli_stmt_close($upd);
                        $editErr = $errno === 1062
                            ? 'That student number is already assigned.'
                            : 'Could not save student number.';
                    } else {
                        mysqli_stmt_close($upd);
                        $u['student_number'] = $sn;
                        $editFlash = 'Student number saved.';
                    }
                }
            }
        }
    } elseif ($action === 'save_school') {
        $actorId = (int) (getCurrentUserId() ?? 0);
        if ($actorId <= 0) {
            $editErr = 'Not signed in.';
        } else {
            mysqli_begin_transaction($conn);
            $result = college_school_assign_for_user($conn, $userId, (string) ($_POST['school'] ?? ''), $actorId);
            if (empty($result['ok'])) {
                mysqli_rollback($conn);
                $editErr = (string) ($result['error'] ?? 'Could not save school assignment.');
            } else {
                mysqli_commit($conn);
                $reload = mysqli_prepare(
                    $conn,
                    "SELECT user_id, full_name, email, status, created_at, access_end, school, section, student_number, profile_picture, use_default_avatar, review_type, role, college_examination_access{$schoolSelectCols}
                     FROM users WHERE user_id=? LIMIT 1"
                );
                if ($reload) {
                    mysqli_stmt_bind_param($reload, 'i', $userId);
                    mysqli_stmt_execute($reload);
                    $fresh = mysqli_fetch_assoc(mysqli_stmt_get_result($reload));
                    mysqli_stmt_close($reload);
                    if (is_array($fresh)) {
                        $u = $fresh;
                    }
                }
                $logId = (int) ($result['log_id'] ?? 0);
                $editFlash = 'School assignment saved. Account status and exam access were not changed.';
                if ($logId > 0) {
                    $editFlash .= ' Assignment log #' . $logId . '.';
                }
            }
        }
    } elseif ($action === 'rollback_school_assignment') {
        $actorId = (int) (getCurrentUserId() ?? 0);
        $logId = (int) ($_POST['log_id'] ?? 0);
        if ($actorId <= 0) {
            $editErr = 'Not signed in.';
        } else {
            mysqli_begin_transaction($conn);
            $result = college_school_rollback_log($conn, $logId, $actorId);
            if (empty($result['ok'])) {
                mysqli_rollback($conn);
                $editErr = (string) ($result['error'] ?? 'Could not restore school.');
            } elseif ((int) ($result['user_id'] ?? 0) !== $userId) {
                mysqli_rollback($conn);
                $editErr = 'That assignment log belongs to a different student.';
            } else {
                mysqli_commit($conn);
                $reload = mysqli_prepare(
                    $conn,
                    "SELECT user_id, full_name, email, status, created_at, access_end, school, section, student_number, profile_picture, use_default_avatar, review_type, role, college_examination_access{$schoolSelectCols}
                     FROM users WHERE user_id=? LIMIT 1"
                );
                if ($reload) {
                    mysqli_stmt_bind_param($reload, 'i', $userId);
                    mysqli_stmt_execute($reload);
                    $fresh = mysqli_fetch_assoc(mysqli_stmt_get_result($reload));
                    mysqli_stmt_close($reload);
                    if (is_array($fresh)) {
                        $u = $fresh;
                    }
                }
                $editFlash = 'Restored the exact prior school value from assignment log #' . $logId . '.';
            }
        }
    }
}

$rtLower = strtolower(trim((string)($u['review_type'] ?? 'reviewee')));
$examineeTypeLabel = ($rtLower === 'undergrad') ? 'College Student' : 'Reviewee';
$avatarSrc = ereview_avatar_img_src((string)($u['profile_picture'] ?? ''));
$useDefault = !empty($u['use_default_avatar']);
$initial = ereview_avatar_initial((string)($u['full_name'] ?? ''));
$schoolRaw = trim((string)($u['school'] ?? ''));
$schoolStaffAssigned = $schoolColsReady && college_school_row_is_staff_assigned($u);
$schoolLabel = $schoolRaw !== '' ? $schoolRaw : '-';
$schoolCatalogRows = $schoolAssignmentReady ? college_schools_list($conn, false) : [];
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

$statusLower = strtolower((string) ($u['status'] ?? ''));
$statusBadgeClass = match ($statusLower) {
    'approved' => 'admin-badge--success',
    'pending' => 'admin-badge--warning',
    'rejected' => 'admin-badge--danger',
    default => 'admin-badge--neutral',
};

$pageTitle = 'Examinee profile';
$professorFeatureHero = true;
$adminHeroIcon = 'person-circle';
$adminHeroTitle = (string)$u['full_name'];
$adminHeroSubtitle = $examineeTypeLabel . ' · ID ' . (int)$u['user_id'];
$adminHeroEyebrow = 'Student management';
$adminBreadcrumbs = [['Dashboard', 'professor_admin_dashboard'], ['Students', 'professor_college_students'], ['Profile']];
$adminBackHref = 'professor_college_students';
$adminBackLabel = 'Back to Students';
$lmsHeroVariant = 'navy';
$adminHeroActions = '<span class="admin-badge ' . $statusBadgeClass . '">' . h((string)$u['status']) . '</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin prof-page--student-view<?php echo ereview_lms_premium_body_class(); ?>">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php $toastSuccess = ereview_lms_toast_take('success', is_string($editFlash) ? $editFlash : null); ?>
  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($editFlash && empty($toastSuccess)): ?><div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($editFlash); ?></span></div><?php endif; ?>
  <?php if ($editErr): ?><div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($editErr); ?></span></div><?php endif; ?>

  <div class="examination-page-shell">
    <section class="prof-workspace lms-workspace">
    <div class="rounded-xl overflow-hidden p-5 mb-0">
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
    <div class="examination-info-grid lms-info-grid mb-4">
      <div class="examination-info-tile lms-info-tile">
        <div class="examination-info-tile-k lms-info-tile-k">College Exam ID</div>
        <div class="examination-info-tile-v"><?php
          $snv = trim((string) ($u['student_number'] ?? ''));
          echo $snv !== '' ? college_exam_id_chip_html($snv, ['copy' => true, 'compact' => true]) : '—';
        ?></div>
      </div>
      <div class="examination-info-tile lms-info-tile">
        <div class="examination-info-tile-k">Examinee type</div>
        <div class="examination-info-tile-v"><?php echo h($examineeTypeLabel); ?> <span class="text-xs opacity-70">(review_type=<?php echo h((string)($u['review_type'] ?? '')); ?>)</span></div>
      </div>
      <div class="examination-info-tile lms-info-tile">
        <div class="examination-info-tile-k">Email</div>
        <div class="examination-info-tile-v"><a class="hover:underline break-all" href="mailto:<?php echo h($u['email']); ?>"><?php echo h($u['email']); ?></a></div>
      </div>
      <div class="examination-info-tile lms-info-tile">
        <div class="examination-info-tile-k">Section</div>
        <div class="examination-info-tile-v"><?php echo h((string)($u['section'] ?? '-')); ?></div>
      </div>
      <div class="examination-info-tile lms-info-tile">
        <div class="examination-info-tile-k">School</div>
        <div class="examination-info-tile-v">
          <?php if ($schoolStaffAssigned): ?>
            <span class="admin-badge admin-badge--success">Assigned</span> <?php echo h($schoolRaw); ?>
          <?php else: ?>
            <span class="admin-badge admin-badge--warning">Unassigned</span>
            <?php if ($schoolRaw !== ''): ?>
              <span class="text-xs opacity-70">Legacy text: <?php echo h($schoolRaw); ?> (not verified)</span>
            <?php else: ?>
              <?php echo h($schoolLabel); ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="examination-info-tile lms-info-tile">
        <div class="examination-info-tile-k">Access end</div>
        <div class="examination-info-tile-v"><?php echo h($accessEndFmt); ?></div>
      </div>
      <div class="examination-info-tile lms-info-tile">
        <div class="examination-info-tile-k">Created</div>
        <div class="examination-info-tile-v"><?php echo h($createdFmt); ?></div>
      </div>
    </div>

    <?php if ($role === 'college_student' && in_array($statusLower, ['pending', 'rejected'], true)): ?>
    <?php $sectionTxt = trim((string) ($u['section'] ?? '')); ?>
    <h2 class="examination-section-title"><i class="bi bi-shield-check"></i> Account approval</h2>
    <div class="rounded-xl overflow-hidden page-table p-4 mb-4 max-w-xl">
      <?php if ($statusLower === 'pending'): ?>
        <p class="text-sm opacity-80 mb-3">This student registered online and is waiting for approval before they can sign in.</p>
      <?php else: ?>
        <p class="text-sm opacity-80 mb-3">This registration was rejected. You can approve the account if the student should be allowed to sign in.</p>
      <?php endif; ?>
      <form method="post" action="professor_college_student_view?id=<?php echo (int)$userId; ?>" class="flex flex-col gap-3 mb-3">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="approve_account">
        <?php if ($sectionTxt === ''): ?>
          <label class="block text-xs font-semibold uppercase tracking-wide opacity-70">Section <span class="text-red-500">*</span></label>
          <select name="section" required class="w-full">
            <option value=""><?php echo $sectionOptions === [] ? 'No active sections — create under Sections first' : 'Select section'; ?></option>
            <?php foreach ($sectionOptions as $secOpt): ?>
              <option value="<?php echo h($secOpt); ?>"><?php echo h($secOpt); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs opacity-70 m-0">Required before approval when the student has no section yet.</p>
        <?php else: ?>
          <p class="text-sm m-0">Section: <strong><?php echo h($sectionTxt); ?></strong></p>
        <?php endif; ?>
        <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm self-start" <?php echo ($sectionTxt === '' && $sectionOptions === []) ? 'disabled' : ''; ?>><i class="bi bi-check2-circle"></i> <?php echo $sectionTxt !== '' ? 'Approve account' : 'Approve &amp; set section'; ?></button>
      </form>
      <?php if ($statusLower === 'pending'): ?>
        <form method="post" action="professor_college_student_view?id=<?php echo (int)$userId; ?>" onsubmit="return confirm('Reject this registration? The student will not be able to sign in.');">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="reject_account">
          <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-x-circle"></i> Reject</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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

    <h2 class="examination-section-title"><i class="bi bi-building"></i> School assignment</h2>
    <div class="rounded-xl overflow-hidden page-table p-4 mb-4 max-w-xl">
      <?php if (!$schoolAssignmentReady): ?>
        <p class="text-sm opacity-80 m-0">School assignment is unavailable until migration 053 is applied. Legacy <code>users.school</code> text is not treated as a verified assignment.</p>
      <?php else: ?>
        <p class="text-sm opacity-80 mb-3">Staff must enter or select the exact school name. This does not infer school from email, name, student number, or section, and it does not change account approval or examination access.</p>
        <form method="post" action="professor_college_student_view?id=<?php echo (int)$userId; ?>" class="flex flex-col gap-3 mb-4" onsubmit="return confirm('Save this school assignment? Account status and exam access will not change.');">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_school">
          <label class="block text-xs font-semibold uppercase tracking-wide opacity-70" for="pcsViewSchoolSelect">School directory</label>
          <?php if ($schoolCatalogRows === []): ?>
            <p class="text-sm m-0">No schools in the directory yet. <a class="underline font-semibold" href="professor_college_schools">Manage Schools</a></p>
          <?php else: ?>
            <select id="pcsViewSchoolSelect" name="school" required class="w-full">
              <option value="">Select a school</option>
              <?php foreach ($schoolCatalogRows as $catRow): ?>
                <?php $catName = (string) $catRow['school_name']; ?>
                <option value="<?php echo h($catName); ?>" <?php echo ($schoolStaffAssigned && $schoolRaw === $catName) ? 'selected' : ''; ?>><?php echo h($catName); ?><?php echo ($catRow['status'] ?? '') === 'inactive' ? ' (inactive)' : ''; ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <?php if (!$schoolStaffAssigned && $schoolRaw !== ''): ?>
            <p class="text-xs opacity-70 m-0">Original text kept on file until you assign: <?php echo h($schoolRaw); ?></p>
          <?php endif; ?>
          <p class="text-xs opacity-70 m-0"><a class="underline" href="professor_college_schools">Manage Schools</a></p>
          <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm self-start"><i class="bi bi-check2-circle"></i> Save school assignment</button>
        </form>
        <form method="post" action="professor_college_student_view?id=<?php echo (int)$userId; ?>" class="flex flex-col gap-2" onsubmit="return confirm('Restore the exact prior school and assignment flags from this log id?');">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="rollback_school_assignment">
          <label class="block text-xs font-semibold uppercase tracking-wide opacity-70" for="pcsViewSchoolLogId">Rollback assignment log ID</label>
          <input type="number" id="pcsViewSchoolLogId" name="log_id" min="1" step="1" required class="w-full" placeholder="e.g. 12">
          <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm self-start"><i class="bi bi-arrow-counterclockwise"></i> Restore prior school</button>
        </form>
      <?php endif; ?>
    </div>

    <h2 class="examination-section-title"><i class="bi bi-hash"></i> Official student number</h2>
    <?php $snCurrent = trim((string)($u['student_number'] ?? '')); $snGenerated = college_student_number_is_generated($snCurrent); ?>
    <form method="post" action="professor_college_student_view?id=<?php echo (int)$userId; ?>" class="rounded-xl overflow-hidden page-table p-4 max-w-xl">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="save_student_number">
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">College Exam ID</label>
      <?php if ($snGenerated): ?>
        <input type="text" name="student_number" class="w-full" value="<?php echo h($snCurrent); ?>" readonly>
        <p class="examination-form-hint">Generated YYYY-NNN numbers cannot be changed or cleared.</p>
      <?php else: ?>
        <input type="text" name="student_number" maxlength="32" pattern="[A-Za-z0-9_-]*" class="w-full" placeholder="e.g. 2008435" value="<?php echo h($snCurrent); ?>">
        <p class="examination-form-hint">Legacy numbers only for reviewees. Do not enter YYYY-NNN. Leave blank to clear.</p>
        <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm mt-3"><i class="bi bi-check2-circle"></i> Save</button>
      <?php endif; ?>
    </form>
    </section>
  </div>
  <?php college_exam_id_copy_script(); ?>
</body>
</html>
