<?php
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preboards_migrate.php';

$id = sanitizeInt($_GET['preboards_subject_id'] ?? 0);
if ($id <= 0) {
  $_SESSION['error'] = 'Invalid preboards subject.';
  header('Location: student_preboards.php');
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
  header('Location: student_preboards.php');
  exit;
}

$userId = getCurrentUserId();
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId) {
  $token = $_POST['csrf_token'] ?? '';
  if (!verifyCSRFToken($token)) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: student_preboards_view.php?preboards_subject_id=' . (int)$id);
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
      header('Location: student_preboards_view.php?preboards_subject_id=' . (int)$id);
      exit;
    }

    if ($action === 'request_open' || $action === 'request_retake') {
      $type = $action === 'request_open' ? 'open' : 'retake';
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
        $_SESSION['message'] = 'Request submitted.';
      }
      header('Location: student_preboards_view.php?preboards_subject_id=' . (int)$id);
      exit;
    }
  }
}

$sets = mysqli_query($conn, "SELECT s.preboards_set_id, s.set_label, s.title, s.time_limit_seconds, s.is_open,
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
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="mb-5">
      <div class="rounded-2xl px-6 py-5 bg-gradient-to-r from-[#143D59] to-[#1665A0] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
            <i class="bi bi-clipboard-check text-xl" aria-hidden="true"></i>
          </span>
          <div>
            <h1 class="text-xl sm:text-2xl font-bold m-0 tracking-tight"><?php echo h($subject['subject_name']); ?></h1>
            <p class="text-sm sm:text-base text-white/90 mt-1 mb-0"><?php echo h($subject['description'] ?: 'Preboards preparation for this subject.'); ?></p>
          </div>
        </div>
        <a href="student_preboards.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold bg-white/15 hover:bg-white/25 border border-white/20 transition">
          <i class="bi bi-arrow-left-circle" aria-hidden="true"></i>
          <span>Back</span>
        </a>
      </div>
    </section>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="mb-5 p-4 rounded-2xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo h($_SESSION['error']); ?></span>
        <?php unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['message'])): ?>
      <div class="mb-5 p-4 rounded-2xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800">
        <i class="bi bi-check-circle-fill"></i>
        <span><?php echo h($_SESSION['message']); ?></span>
        <?php unset($_SESSION['message']); ?>
      </div>
    <?php endif; ?>

    <section aria-label="Preboard sets">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
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
            $isOpen = (int)($set['is_open'] ?? 0) === 1;
            $hasAccess = $isOpen || isset($accessBySet[$setId]);
            $openPending = isset($pendingOpenReqBySet[$setId]);
            $retakePending = isset($pendingRetakeReqBySet[$setId]);
            $retakeReady = isset($retakeReadyBySet[$setId]);
        ?>
        <article class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08),0_6px_18px_rgba(15,23,42,0.06)] hover:shadow-[0_8px_26px_rgba(15,23,42,0.16)] transition-all duration-300 flex flex-col overflow-hidden">
          <div class="px-5 pt-5 pb-4 flex items-start justify-between gap-3">
            <div class="flex items-start gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#143D59] text-white shadow-md">
                <i class="bi bi-clipboard-check text-lg" aria-hidden="true"></i>
              </span>
              <div>
                <h2 class="text-base sm:text-lg font-bold text-[#143D59] m-0">Set <?php echo h($set['set_label']); ?></h2>
                <p class="text-xs uppercase tracking-[0.16em] text-[#143D59]/55 mt-1 mb-0"><?php echo h($set['title'] ?: 'Preboard'); ?></p>
              </div>
            </div>
          </div>
          <div class="px-5 pb-4 flex-1">
            <p class="text-sm text-[#143D59]/80 m-0">
              <?php echo $qCount; ?> question<?php echo $qCount === 1 ? '' : 's'; ?>. One attempt per set — no retake once submitted.
            </p>
          </div>
          <div class="px-5 pb-5 flex items-center justify-between gap-3 text-sm border-t border-[#1665A0]/10 bg-white/70">
            <?php if ($submitted): ?>
              <div class="flex items-center gap-2 text-green-700">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                <span>Completed <?php echo number_format((float)($att['score'] ?? 0), 0); ?>% (<?php echo (int)($att['correct_count'] ?? 0); ?>/<?php echo (int)($att['total_count'] ?? 0); ?>)</span>
              </div>
              <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="student_take_preboard.php?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>&attempt_id=<?php echo $attemptId; ?>&review=1"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-white bg-[#1665A0] hover:bg-[#0f4d7a] shadow-[0_2px_8px_rgba(22,101,160,0.45)] active:scale-[0.97] transition-all duration-200">
                  <i class="bi bi-journal-text" aria-hidden="true"></i>
                  <span>Review</span>
                </a>

                <?php if ($retakeReady): ?>
                  <a href="student_take_preboard.php?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>"
                     class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-white bg-amber-600 hover:bg-amber-700 shadow-[0_2px_8px_rgba(245,158,11,0.35)] active:scale-[0.97] transition-all duration-200">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <span>Retake</span>
                  </a>
                <?php elseif ($retakePending): ?>
                  <span class="text-xs text-gray-400 font-semibold">Retake pending</span>
                <?php else: ?>
                  <button type="button"
                          class="text-xs font-semibold text-amber-700 hover:text-amber-800 underline underline-offset-2 px-2 py-1"
                          data-set-id="<?php echo $setId; ?>"
                          data-set-label="<?php echo h($set['set_label']); ?>"
                          onclick="openRetakeModal(this)">
                    Request retake
                  </button>
                <?php endif; ?>
              </div>
            <?php elseif (!$hasAccess): ?>
              <div class="flex items-center gap-2 text-gray-500">
                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                <span>Locked</span>
              </div>
              <?php if ($openPending): ?>
                <span class="text-gray-400 text-sm">Request pending</span>
              <?php else: ?>
                <form method="POST" action="student_preboards_view.php?preboards_subject_id=<?php echo (int)$id; ?>" class="m-0">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="action" value="request_open">
                  <input type="hidden" name="preboards_set_id" value="<?php echo $setId; ?>">
                  <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-white bg-[#1665A0] hover:bg-[#0f4d7a] shadow-[0_2px_8px_rgba(22,101,160,0.45)] active:scale-[0.97] transition-all duration-200">
                    <i class="bi bi-send" aria-hidden="true"></i>
                    <span>Request access</span>
                  </button>
                </form>
              <?php endif; ?>
            <?php elseif ($inProgress && $attemptId > 0): ?>
              <div class="flex items-center gap-2 text-[#143D59]/75">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <span>In progress</span>
              </div>
              <a href="student_take_preboard.php?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>&attempt_id=<?php echo $attemptId; ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-white bg-amber-500 hover:bg-amber-600 shadow-[0_2px_8px_rgba(245,158,11,0.4)] active:scale-[0.97] transition-all duration-200">
                <i class="bi bi-play-fill" aria-hidden="true"></i>
                <span>Continue</span>
              </a>
            <?php elseif ($qCount > 0): ?>
              <div class="flex items-center gap-2 text-[#143D59]/75">
                <i class="bi bi-list-check" aria-hidden="true"></i>
                <span><?php echo $qCount; ?> questions</span>
              </div>
              <a href="student_take_preboard.php?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo (int)$id; ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-white bg-[#1665A0] hover:bg-[#0f4d7a] shadow-[0_2px_8px_rgba(22,101,160,0.45)] active:scale-[0.97] transition-all duration-200">
                <span>Take set</span>
                <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
              </a>
            <?php else: ?>
              <div class="text-gray-400 text-sm">No questions yet</div>
              <span class="text-gray-400 text-sm">—</span>
            <?php endif; ?>
          </div>
        </article>
        <?php
          }
        }
        ?>
        <?php if (!$hasSets): ?>
          <div class="col-span-full">
            <div class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white p-12 text-center text-[#143D59]/80">
              <i class="bi bi-inbox text-5xl mb-3 text-[#1665A0]" aria-hidden="true"></i>
              <p class="text-lg font-semibold m-0">No sets available yet.</p>
              <p class="text-sm mt-1 mb-0">Check back later. Your admin may add sets (A, B, C, D) for this subject.</p>
            </div>
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
      <form method="POST" action="student_preboards_view.php?preboards_subject_id=<?php echo (int)$id; ?>" class="m-0">
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

