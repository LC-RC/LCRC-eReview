<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
requireRole('student');
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/preboards_helpers.php';
require_once __DIR__ . '/includes/notification_helpers.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

sca_ensure_schema($conn);
sca_enforce_student_session($conn);

$id = sanitizeInt($_GET['preboards_subject_id'] ?? 0);
if ($id <= 0) {
  $_SESSION['error'] = 'Invalid preboards subject.';
  header('Location: student_preboards');
  exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM preboards_subjects WHERE preboards_subject_id=? AND status='active' LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$subject = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$subject) {
  $_SESSION['error'] = 'Preboards subject not found or inactive.';
  header('Location: student_preboards');
  exit;
}

$userId = getCurrentUserId();
if (!sca_preboard_subject_has_any_access($conn, (int)$userId, $id)) {
    $_SESSION['error'] = SCA_DENIED_MESSAGE;
    header('Location: student_preboards');
    exit;
}
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId) {
  $token = $_POST['csrf_token'] ?? '';
  if (!verifyCSRFToken($token)) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
    exit;
  }
  $action = $_POST['action'] ?? '';
  $setIdPost = sanitizeInt($_POST['preboards_set_id'] ?? 0);
  if ($setIdPost > 0) {
    // Ensure set belongs to this subject
    $chk = mysqli_prepare($conn, "SELECT preboards_set_id FROM preboards_sets WHERE preboards_set_id=? AND preboards_subject_id=? LIMIT 1");
    mysqli_stmt_bind_param($chk, 'ii', $setIdPost, $id);
    mysqli_stmt_execute($chk);
    $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$ok) {
      $_SESSION['error'] = 'Invalid set.';
      header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
      exit;
    }

    if ($action === 'request_open' || $action === 'request_retake') {
      $type = $action === 'request_open' ? 'open' : 'retake';
      $setStmt = mysqli_prepare($conn, "SELECT * FROM preboards_sets WHERE preboards_set_id=? AND preboards_subject_id=? LIMIT 1");
      mysqli_stmt_bind_param($setStmt, 'ii', $setIdPost, $id);
      mysqli_stmt_execute($setStmt);
      $setRowReq = mysqli_fetch_assoc(mysqli_stmt_get_result($setStmt));
      mysqli_stmt_close($setStmt);
      if (!$setRowReq) {
        $_SESSION['error'] = 'Invalid set.';
        header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
        exit;
      }
      if (!sca_preboard_set_granted($conn, (int)$userId, $setIdPost, (int)$id)) {
        $_SESSION['error'] = 'You do not have access to this set.';
        header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
        exit;
      }

      if ($type === 'open') {
        if (preboards_set_is_open_for_students($setRowReq)) {
          $_SESSION['error'] = 'This set is already open.';
          header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
          exit;
        }
        $accChk = mysqli_prepare($conn, "SELECT preboards_set_access_id FROM preboards_set_access WHERE user_id=? AND preboards_set_id=? AND used_at IS NULL AND revoked_at IS NULL LIMIT 1");
        mysqli_stmt_bind_param($accChk, 'ii', $userId, $setIdPost);
        mysqli_stmt_execute($accChk);
        $hasUnusedGrant = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($accChk));
        mysqli_stmt_close($accChk);
        if ($hasUnusedGrant) {
          $_SESSION['message'] = 'You already have approved access for this set.';
          header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
          exit;
        }
        $attChk = mysqli_prepare($conn, "SELECT status FROM preboards_attempts WHERE user_id=? AND preboards_set_id=? ORDER BY attempt_no DESC, preboards_attempt_id DESC LIMIT 1");
        mysqli_stmt_bind_param($attChk, 'ii', $userId, $setIdPost);
        mysqli_stmt_execute($attChk);
        $lastAttReq = mysqli_fetch_assoc(mysqli_stmt_get_result($attChk));
        mysqli_stmt_close($attChk);
        if ($lastAttReq && ($lastAttReq['status'] ?? '') === 'in_progress') {
          $_SESSION['error'] = 'You already have an attempt in progress for this set.';
          header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
          exit;
        }
      } else {
        $attChk = mysqli_prepare($conn, "SELECT status FROM preboards_attempts WHERE user_id=? AND preboards_set_id=? ORDER BY attempt_no DESC, preboards_attempt_id DESC LIMIT 1");
        mysqli_stmt_bind_param($attChk, 'ii', $userId, $setIdPost);
        mysqli_stmt_execute($attChk);
        $lastAttReq = mysqli_fetch_assoc(mysqli_stmt_get_result($attChk));
        mysqli_stmt_close($attChk);
        if (!$lastAttReq || ($lastAttReq['status'] ?? '') !== 'submitted') {
          $_SESSION['error'] = 'You can only request a retake after completing this set.';
          header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
          exit;
        }
      }

      // Avoid duplicate pending requests for same type
      $dup = mysqli_prepare($conn, "SELECT preboards_request_id FROM preboards_requests WHERE user_id=? AND preboards_set_id=? AND request_type=? AND status='pending' LIMIT 1");
      mysqli_stmt_bind_param($dup, 'iis', $userId, $setIdPost, $type);
      mysqli_stmt_execute($dup);
      $dupRow = mysqli_fetch_assoc(mysqli_stmt_get_result($dup));
      mysqli_stmt_close($dup);
      if ($dupRow) {
        $_SESSION['message'] = 'Request already pending.';
      } else {
        $ins = mysqli_prepare($conn, "INSERT INTO preboards_requests (user_id, preboards_set_id, request_type, status) VALUES (?, ?, ?, 'pending')");
        mysqli_stmt_bind_param($ins, 'iis', $userId, $setIdPost, $type);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
        notifications_create_admin_preboards_request_notifications(
            $conn,
            (int) $userId,
            (int) $id,
            (string) ($subject['subject_name'] ?? 'Preboards'),
            (string) ($setRowReq['set_label'] ?? ''),
            $type
        );
        $_SESSION['message'] = 'Request submitted. An admin will be notified.';
      }
      header('Location: student_preboards_view?preboards_subject_id=' . (int)$id);
      exit;
    }
  }
}

$sets = mysqli_query($conn, "SELECT s.preboards_set_id, s.set_label, s.title, s.time_limit_seconds, s.is_open, s.use_schedule, s.opens_at, s.closes_at,
  (SELECT COUNT(*) FROM preboards_questions q WHERE q.preboards_set_id=s.preboards_set_id) AS questions_cnt
  FROM preboards_sets s WHERE s.preboards_subject_id=" . (int)$id . " ORDER BY s.sort_order ASC, s.set_label ASC");

$attemptsBySet = [];
if ($userId) {
  // pick latest attempt per set (highest attempt_no)
  $ar = mysqli_query($conn, "SELECT a.preboards_set_id, a.status, a.score, a.correct_count, a.total_count, a.preboards_attempt_id
    FROM preboards_attempts a
    INNER JOIN (
      SELECT preboards_set_id, MAX(attempt_no) AS max_no
      FROM preboards_attempts
      WHERE user_id=" . (int)$userId . "
      GROUP BY preboards_set_id
    ) x ON x.preboards_set_id=a.preboards_set_id AND x.max_no=a.attempt_no
    WHERE a.user_id=" . (int)$userId);
  if ($ar) {
    while ($row = mysqli_fetch_assoc($ar)) {
      $sid = (int)$row['preboards_set_id'];
      $attemptsBySet[$sid] = $row;
    }
  }
}

$accessBySet = [];
$pendingOpenReqBySet = [];
$pendingRetakeReqBySet = [];
$retakeReadyBySet = [];
if ($userId) {
  // One-time access grants (unused and not revoked)
  $acc = mysqli_query($conn, "SELECT preboards_set_id FROM preboards_set_access WHERE user_id=" . (int)$userId . " AND used_at IS NULL AND revoked_at IS NULL");
  if ($acc) { while ($r = mysqli_fetch_assoc($acc)) { $accessBySet[(int)$r['preboards_set_id']] = true; } }
  $pr = mysqli_query($conn, "SELECT preboards_set_id, request_type FROM preboards_requests WHERE user_id=" . (int)$userId . " AND status='pending'");
  if ($pr) {
    while ($r = mysqli_fetch_assoc($pr)) {
      $sid = (int)$r['preboards_set_id'];
      if (($r['request_type'] ?? '') === 'open') $pendingOpenReqBySet[$sid] = true;
      if (($r['request_type'] ?? '') === 'retake') $pendingRetakeReqBySet[$sid] = true;
    }
  }
  $rr = mysqli_query($conn, "SELECT preboards_set_id FROM preboards_retake_tokens WHERE user_id=" . (int)$userId . " AND used_at IS NULL");
  if ($rr) { while ($r = mysqli_fetch_assoc($rr)) { $retakeReadyBySet[(int)$r['preboards_set_id']] = true; } }
}

$pageTitle = 'Preboards - ' . ($subject['subject_name'] ?? 'Subject');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require_once __DIR__ . '/includes/student_lock_styles.php'; ?>
  <style>
    .student-dashboard-page { background: transparent; }
    html[data-student-theme="light"] .student-dashboard-page {
      background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%);
    }
    .pb-sets-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1.1rem;
    }
    @media (min-width: 768px) {
      .pb-sets-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (min-width: 1200px) {
      .pb-sets-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.25rem; }
    }
    .pb-set-card {
      --pb-accent: #1665A0;
      --pb-soft: rgba(22, 101, 160, 0.12);
      --pb-glow: rgba(22, 101, 160, 0.28);
      position: relative;
      display: flex;
      flex-direction: column;
      min-height: 100%;
      border-radius: 1rem;
      border: 1px solid rgba(22, 101, 160, 0.14);
      background:
        radial-gradient(ellipse 90% 70% at 100% 0%, var(--pb-soft), transparent 55%),
        linear-gradient(165deg, #ffffff 0%, #f7fbff 55%, #f2f8fd 100%);
      box-shadow:
        0 12px 28px -22px rgba(20, 61, 89, 0.42),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
      overflow: hidden;
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.2s ease;
    }
    .pb-set-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--pb-accent), #7dd3fc);
    }
    .pb-set-card:hover {
      transform: translateY(-3px);
      border-color: rgba(22, 101, 160, 0.28);
      box-shadow:
        0 20px 40px -24px var(--pb-glow),
        0 10px 22px -18px rgba(20, 61, 89, 0.28),
        inset 0 1px 0 rgba(255, 255, 255, 0.95);
    }
    .pb-set-card__body {
      padding: 1.2rem 1.25rem 1rem;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      flex: 1;
    }
    .pb-set-card__head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
    }
    .pb-set-card__identity {
      display: flex;
      align-items: flex-start;
      gap: 0.8rem;
      min-width: 0;
    }
    .pb-set-card__icon {
      width: 2.75rem;
      height: 2.75rem;
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.85rem;
      background: linear-gradient(145deg, #1665A0, #143D59);
      color: #fff;
      font-size: 1.15rem;
      border: 1px solid rgba(255, 255, 255, 0.22);
      box-shadow: 0 12px 22px -12px rgba(22, 101, 160, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.25);
    }
    .pb-set-card__title {
      margin: 0;
      font-size: 1.1rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--student-text, #143D59);
      line-height: 1.25;
    }
    .pb-set-card__eyebrow {
      margin: 0.25rem 0 0;
      font-size: 0.68rem;
      font-weight: 750;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--student-text-muted, #64748b);
    }
    .pb-set-card__status {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      flex-shrink: 0;
      padding: 0.28rem 0.6rem;
      border-radius: 9999px;
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      border: 1px solid transparent;
    }
    .pb-set-card__status--open {
      color: #047857;
      background: rgba(4, 120, 87, 0.1);
      border-color: rgba(4, 120, 87, 0.18);
    }
    .pb-set-card__status--upcoming {
      color: #0369a1;
      background: rgba(3, 105, 161, 0.1);
      border-color: rgba(3, 105, 161, 0.18);
    }
    .pb-set-card__status--closed,
    .pb-set-card__status--locked {
      color: #b45309;
      background: rgba(245, 158, 11, 0.12);
      border-color: rgba(180, 83, 9, 0.18);
    }
    .pb-set-card__meta {
      margin: 0;
      font-size: 0.84rem;
      line-height: 1.5;
      font-weight: 500;
      color: var(--student-text-secondary, #475569);
    }
    .pb-set-card__chips {
      display: flex;
      flex-wrap: wrap;
      gap: 0.45rem;
    }
    .pb-set-card__chip {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.3rem 0.6rem;
      border-radius: 0.55rem;
      font-size: 0.72rem;
      font-weight: 700;
      color: #143D59;
      background: rgba(22, 101, 160, 0.08);
      border: 1px solid rgba(22, 101, 160, 0.12);
    }
    .pb-set-card__chip i { color: #1665A0; }
    .pb-set-card__note {
      margin: 0;
      font-size: 0.75rem;
      font-weight: 650;
      color: #047857;
      display: flex;
      align-items: flex-start;
      gap: 0.35rem;
    }
    .pb-set-card__foot {
      margin-top: auto;
      padding: 0.95rem 1.25rem 1.1rem;
      border-top: 1px solid rgba(22, 101, 160, 0.1);
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      background: rgba(255, 255, 255, 0.55);
    }
    .pb-set-card__progress {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--student-text-secondary, #475569);
      min-width: 0;
    }
    .pb-set-card__progress--done { color: #047857; }
    .pb-set-card__progress--warn { color: #b45309; }
    .pb-set-card__actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: flex-end;
      gap: 0.45rem;
    }
    .pb-set-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.35rem;
      min-height: 2.35rem;
      padding: 0.45rem 0.85rem;
      border-radius: 0.7rem;
      border: 1px solid transparent;
      font-size: 0.8rem;
      font-weight: 750;
      text-decoration: none;
      cursor: pointer;
      transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .pb-set-btn:hover { transform: translateY(-1px); }
    .pb-set-btn--primary {
      color: #fff;
      background: linear-gradient(145deg, #1665A0, #143D59);
      box-shadow: 0 10px 18px -12px rgba(22, 101, 160, 0.55);
    }
    .pb-set-btn--primary:hover { filter: brightness(1.05); }
    .pb-set-btn--amber {
      color: #fff;
      background: linear-gradient(145deg, #f59e0b, #d97706);
      box-shadow: 0 10px 18px -12px rgba(217, 119, 6, 0.5);
    }
    .pb-set-btn--ghost {
      color: #b45309;
      background: rgba(245, 158, 11, 0.1);
      border-color: rgba(180, 83, 9, 0.22);
    }
    .pb-set-btn--ghost:hover {
      background: rgba(245, 158, 11, 0.16);
    }
    .pb-set-btn--muted {
      color: #64748b;
      background: rgba(148, 163, 184, 0.12);
      border-color: rgba(148, 163, 184, 0.22);
      cursor: default;
      transform: none !important;
    }
    .pb-sets-empty {
      grid-column: 1 / -1;
      border-radius: 1rem;
      border: 1px solid rgba(22, 101, 160, 0.14);
      background: linear-gradient(165deg, #fff 0%, #f7fbff 100%);
      padding: 2.75rem 1.5rem;
      text-align: center;
      color: #475569;
    }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
      .pb-set-card, .pb-set-btn { transition: none !important; }
      .pb-set-card:hover, .pb-set-btn:hover { transform: none; }
    }
  </style>
</head>
<body class="font-sans antialiased preboards-view-page">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero student-hero--glass dash-anim delay-1 relative overflow-hidden mb-6">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3 min-w-0">
          <a href="student_preboards" class="student-hero-icon-btn flex shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md hover:bg-white/25 transition" aria-label="Back to Preboards" style="width:2.75rem;height:2.75rem;color:inherit;">
            <i class="bi bi-arrow-left text-lg" aria-hidden="true"></i>
          </a>
          <span class="student-hero-icon-btn flex shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md" style="width:2.75rem;height:2.75rem;" aria-hidden="true">
            <i class="bi bi-clipboard-check text-lg"></i>
          </span>
          <div class="min-w-0">
            <h1 class="student-hero__title text-2xl sm:text-3xl font-bold m-0 tracking-tight"><?php echo h($subject['subject_name']); ?></h1>
            <p class="student-hero__lede mt-2 mb-0"><?php echo h($subject['description'] ?: 'Preboards preparation for this subject.'); ?></p>
          </div>
        </div>
      </div>
    </section>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800 dash-anim delay-1">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo h($_SESSION['error']); ?></span>
        <?php unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['message'])): ?>
      <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800 dash-anim delay-1">
        <i class="bi bi-check-circle-fill"></i>
        <span><?php echo h($_SESSION['message']); ?></span>
        <?php unset($_SESSION['message']); ?>
      </div>
    <?php endif; ?>

    <section aria-label="Preboard sets" class="dash-anim delay-2">
      <div class="pb-sets-grid">
        <?php
        $hasSets = false;
        if ($sets) {
          while ($set = mysqli_fetch_assoc($sets)) {
            $hasSets = true;
            $setId = (int)$set['preboards_set_id'];
            $qCount = (int)($set['questions_cnt'] ?? 0);
            $att = $attemptsBySet[$setId] ?? null;
            $submitted = $att && ($att['status'] ?? '') === 'submitted';
            $inProgress = $att && ($att['status'] ?? '') === 'in_progress';
            $attemptId = $att ? (int)($att['preboards_attempt_id'] ?? 0) : 0;
            $hasGrant = isset($accessBySet[$setId]);
            $scaGranted = sca_preboard_set_granted($conn, (int)$userId, $setId, (int)$id);
            $hasAccess = sca_preboard_set_can_enter($conn, (int)$userId, $setId, (int)$id, $set, $hasGrant);
            $accessMeta = preboards_set_access_meta($set, true);
            $effectiveOpen = preboards_set_is_open_for_students($set);
            $durationSecs = preboards_set_effective_time_limit_seconds($set);
            $durationLabel = formatTimeLimitSeconds($durationSecs);
            $statusKey = (string)($accessMeta['key'] ?? 'locked');
            $statusIcon = match ($statusKey) {
                'open' => 'bi-unlock',
                'upcoming' => 'bi-calendar-event',
                'closed' => 'bi-calendar-x',
                default => 'bi-lock-fill',
            };
            $statusMod = match ($statusKey) {
                'open' => 'open',
                'upcoming' => 'upcoming',
                'closed' => 'closed',
                default => 'locked',
            };
            $openPending = isset($pendingOpenReqBySet[$setId]);
            $retakePending = isset($pendingRetakeReqBySet[$setId]);
            $retakeReady = isset($retakeReadyBySet[$setId]);
            $scorePct = number_format((float)($att['score'] ?? 0), 0);
            $correctCnt = (int)($att['correct_count'] ?? 0);
            $totalCnt = (int)($att['total_count'] ?? 0);
        ?>
        <article class="pb-set-card<?php echo !$scaGranted ? ' lms-locked-card' : ''; ?>">
          <?php if (!$scaGranted): ?><span class="lms-lock-overlay lms-lock-badge"><i class="bi bi-lock-fill"></i> No access</span><?php endif; ?>
          <div class="pb-set-card__body">
            <div class="pb-set-card__head">
              <div class="pb-set-card__identity">
                <span class="pb-set-card__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
                <div class="min-w-0">
                  <h2 class="pb-set-card__title">Set <?php echo h($set['set_label']); ?></h2>
                  <p class="pb-set-card__eyebrow"><?php echo h($set['title'] ?: 'Preboard'); ?></p>
                </div>
              </div>
              <?php if ($scaGranted): ?>
              <span class="pb-set-card__status pb-set-card__status--<?php echo h($statusMod); ?>">
                <i class="bi <?php echo h($statusIcon); ?>" aria-hidden="true"></i>
                <?php echo h($accessMeta['label']); ?>
              </span>
              <?php endif; ?>
            </div>

            <div class="pb-set-card__chips">
              <span class="pb-set-card__chip"><i class="bi bi-list-ol" aria-hidden="true"></i><?php echo $qCount; ?> question<?php echo $qCount === 1 ? '' : 's'; ?></span>
              <span class="pb-set-card__chip"><i class="bi bi-clock" aria-hidden="true"></i><?php echo h($durationLabel); ?></span>
            </div>
            <p class="pb-set-card__meta">One attempt per set — request a retake after you submit if needed.</p>
            <?php if ($scaGranted && $hasGrant && !$effectiveOpen): ?>
              <p class="pb-set-card__note"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>Admin approved your access — you may take this set once.</span></p>
            <?php endif; ?>
          </div>

          <div class="pb-set-card__foot">
            <?php if ($submitted): ?>
              <div class="pb-set-card__progress pb-set-card__progress--done">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                <span>Completed <?php echo h($scorePct); ?>% (<?php echo $correctCnt; ?>/<?php echo $totalCnt; ?>)</span>
              </div>
              <div class="pb-set-card__actions">
                <a href="student_take_preboard?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>&attempt_id=<?php echo $attemptId; ?>&review=1" class="pb-set-btn pb-set-btn--primary">
                  <i class="bi bi-journal-text" aria-hidden="true"></i> Review
                </a>
                <?php if ($retakeReady): ?>
                  <a href="student_take_preboard?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>" class="pb-set-btn pb-set-btn--amber">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Retake
                  </a>
                <?php elseif ($retakePending): ?>
                  <span class="pb-set-btn pb-set-btn--muted">Retake pending</span>
                <?php else: ?>
                  <button type="button" class="pb-set-btn pb-set-btn--ghost"
                    data-set-id="<?php echo $setId; ?>"
                    data-set-label="<?php echo h($set['set_label']); ?>"
                    onclick="openRetakeModal(this)">
                    <i class="bi bi-send" aria-hidden="true"></i> Request retake
                  </button>
                <?php endif; ?>
              </div>
            <?php elseif (!$scaGranted): ?>
              <div class="pb-set-card__progress pb-set-card__progress--warn">
                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                <span>Not included in your access</span>
              </div>
              <div class="pb-set-card__actions">
                <span class="pb-set-btn pb-set-btn--muted">Contact admin</span>
              </div>
            <?php elseif (!$hasAccess): ?>
              <div class="pb-set-card__progress pb-set-card__progress--warn">
                <i class="bi <?php echo h($statusIcon); ?>" aria-hidden="true"></i>
                <span><?php echo h($accessMeta['label']); ?></span>
              </div>
              <div class="pb-set-card__actions">
                <?php if ($openPending): ?>
                  <span class="pb-set-btn pb-set-btn--muted">Request pending</span>
                <?php else: ?>
                  <form method="POST" action="student_preboards_view?preboards_subject_id=<?php echo (int)$id; ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="request_open">
                    <input type="hidden" name="preboards_set_id" value="<?php echo $setId; ?>">
                    <button type="submit" class="pb-set-btn pb-set-btn--primary">
                      <i class="bi bi-send" aria-hidden="true"></i> Request access
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            <?php elseif ($inProgress && $attemptId > 0): ?>
              <div class="pb-set-card__progress pb-set-card__progress--warn">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <span>In progress</span>
              </div>
              <div class="pb-set-card__actions">
                <a href="student_take_preboard?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>&attempt_id=<?php echo $attemptId; ?>" class="pb-set-btn pb-set-btn--amber">
                  <i class="bi bi-play-fill" aria-hidden="true"></i> Continue
                </a>
              </div>
            <?php elseif ($qCount > 0): ?>
              <div class="pb-set-card__progress">
                <i class="bi bi-list-check" aria-hidden="true"></i>
                <span>Ready when you are</span>
              </div>
              <div class="pb-set-card__actions">
                <a href="student_take_preboard?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>" class="pb-set-btn pb-set-btn--primary">
                  Take set <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
              </div>
            <?php else: ?>
              <div class="pb-set-card__progress">
                <i class="bi bi-inbox" aria-hidden="true"></i>
                <span>No questions yet</span>
              </div>
              <div class="pb-set-card__actions">
                <span class="pb-set-btn pb-set-btn--muted">—</span>
              </div>
            <?php endif; ?>
          </div>
        </article>
        <?php
          }
        }
        ?>
        <?php if (!$hasSets): ?>
          <div class="pb-sets-empty">
            <i class="bi bi-inbox text-4xl text-[#1665A0] mb-2 block" aria-hidden="true"></i>
            <p class="text-lg font-semibold m-0 text-[#143D59]">No sets available yet.</p>
            <p class="text-sm mt-1 mb-0">Check back later. Your admin may add sets (A, B, C, D) for this subject.</p>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <!-- Retake request modal -->
  <div id="retakeModal" class="fixed inset-0 z-[1200] hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50" onclick="closeRetakeModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-modal max-w-md w-full p-5" role="dialog" aria-modal="true" aria-labelledby="retakeModalTitle">
      <div class="flex items-start justify-between gap-3 mb-3">
        <div>
          <h2 id="retakeModalTitle" class="text-xl font-bold text-gray-800 m-0">Request retake</h2>
          <p class="text-sm text-gray-500 mt-1 mb-0">Your admin needs to approve before you can retake this set.</p>
        </div>
        <button type="button" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" onclick="closeRetakeModal()" aria-label="Close">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 mb-4">
        <div class="font-semibold">Set <span id="retakeModalSetLabel"></span></div>
        <div class="text-sm mt-1">Submit a retake request for this set?</div>
      </div>
      <form method="POST" action="student_preboards_view?preboards_subject_id=<?php echo (int)$id; ?>" class="m-0">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="request_retake">
        <input type="hidden" name="preboards_set_id" id="retakeModalSetId" value="0">
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2.5 rounded-xl font-semibold border border-gray-300 text-gray-700 hover:bg-gray-100 transition" onclick="closeRetakeModal()">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-xl font-semibold bg-amber-600 text-white hover:bg-amber-700 transition inline-flex items-center gap-2">
            <i class="bi bi-send"></i>
            <span>Request</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openRetakeModal(btn) {
      var modal = document.getElementById('retakeModal');
      var idEl = document.getElementById('retakeModalSetId');
      var labelEl = document.getElementById('retakeModalSetLabel');
      if (!modal || !idEl || !labelEl || !btn) return;
      idEl.value = btn.getAttribute('data-set-id') || '0';
      labelEl.textContent = btn.getAttribute('data-set-label') || '';
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    }
    function closeRetakeModal() {
      var modal = document.getElementById('retakeModal');
      if (!modal) return;
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    }
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeRetakeModal();
    });
  </script>
</main>
</div>
</body>
</html>

