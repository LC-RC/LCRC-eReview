<?php
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/preboards_helpers.php';
require_once __DIR__ . '/includes/preboards_workspace_nav.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$subjectId = sanitizeInt($_GET['preboards_subject_id'] ?? 0);
if ($subjectId <= 0) {
    header('Location: admin_preboards_subjects');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM preboards_subjects WHERE preboards_subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subject = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$subject) {
    header('Location: admin_preboards_subjects');
    exit;
}

$csrf = generateCSRFToken();

function getNextPreboardsSetLabel(mysqli $conn, int $subjectId): ?string {
    $existing = [];
    $res = mysqli_query($conn, "SELECT UPPER(set_label) AS set_label FROM preboards_sets WHERE preboards_subject_id=" . (int)$subjectId);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $lbl = trim((string)($r['set_label'] ?? ''));
        if ($lbl !== '') $existing[$lbl] = true;
    }
    for ($i = 0; $i < 26; $i++) {
        $letter = chr(65 + $i); // A-Z
        if (!isset($existing[$letter])) return $letter;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_preboards_sets?preboards_subject_id=' . $subjectId);
        exit;
    }
    $action = $_POST['action'] ?? 'save';

    if ($action === 'toggle_open') {
        $setId = sanitizeInt($_POST['preboards_set_id'] ?? 0);
        $newVal = isset($_POST['is_open']) && (int)$_POST['is_open'] === 1 ? 1 : 0;
        if ($setId > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE preboards_sets SET is_open=?, use_schedule=0, opens_at=NULL, closes_at=NULL WHERE preboards_set_id=? AND preboards_subject_id=?");
            mysqli_stmt_bind_param($stmt, 'iii', $newVal, $setId, $subjectId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = $newVal ? 'Set opened manually.' : 'Set locked.';
        }
        header('Location: admin_preboards_sets?preboards_subject_id=' . $subjectId);
        exit;
    }

    if ($action === 'save_schedule') {
        $setId = sanitizeInt($_POST['preboards_set_id'] ?? 0);
        $useSchedule = isset($_POST['use_schedule']) && (int)$_POST['use_schedule'] === 1 ? 1 : 0;
        $opensAt = preboards_datetime_local_to_sql($_POST['opens_at'] ?? '');
        $closesAt = preboards_datetime_local_to_sql($_POST['closes_at'] ?? '');
        if ($setId > 0) {
            if ($useSchedule && $opensAt === null) {
                $_SESSION['error'] = 'Set an open date/time when scheduling is enabled.';
            } elseif ($useSchedule && $closesAt === null) {
                $_SESSION['error'] = 'Set a close date/time - exam duration is based on the open and close window.';
            } elseif ($opensAt !== null && $closesAt !== null && strtotime($closesAt) <= strtotime($opensAt)) {
                $_SESSION['error'] = 'Close time must be after open time.';
            } else {
                if ($useSchedule) {
                    $windowSeconds = max(60, min(86400, strtotime($closesAt) - strtotime($opensAt)));
                    $stmt = mysqli_prepare($conn, "UPDATE preboards_sets SET use_schedule=1, opens_at=?, closes_at=?, is_open=0, time_limit_seconds=? WHERE preboards_set_id=? AND preboards_subject_id=?");
                    mysqli_stmt_bind_param($stmt, 'ssiii', $opensAt, $closesAt, $windowSeconds, $setId, $subjectId);
                } else {
                    $stmt = mysqli_prepare($conn, "UPDATE preboards_sets SET use_schedule=0, opens_at=NULL, closes_at=NULL WHERE preboards_set_id=? AND preboards_subject_id=?");
                    mysqli_stmt_bind_param($stmt, 'ii', $setId, $subjectId);
                }
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['message'] = $useSchedule ? 'Schedule saved for this set.' : 'Schedule removed. Use Open/Locked for manual control.';
            }
        }
        header('Location: admin_preboards_sets?preboards_subject_id=' . $subjectId);
        exit;
    }

    if ($action === 'decide_request') {
        $reqId = sanitizeInt($_POST['preboards_request_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $adminId = (int) getCurrentUserId();
        if (preboards_decide_request($conn, $reqId, $decision, $adminId, $subjectId)) {
            $_SESSION['message'] = 'Request ' . ($decision === 'approved' ? 'approved' : 'denied') . '.';
        } else {
            $_SESSION['error'] = 'Could not update that request. It may already be decided.';
        }
        header('Location: admin_preboards_sets?preboards_subject_id=' . $subjectId . '#preboards-requests');
        exit;
    }

    if ($action === 'delete') {
        $setId = sanitizeInt($_POST['preboards_set_id'] ?? 0);
        if ($setId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM preboards_sets WHERE preboards_set_id=? AND preboards_subject_id=?");
            mysqli_stmt_bind_param($stmt, 'ii', $setId, $subjectId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Set deleted.';
        }
        header('Location: admin_preboards_sets?preboards_subject_id=' . $subjectId);
        exit;
    }

    $setId = sanitizeInt($_POST['preboards_set_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $timeLimitSeconds = (int)($_POST['time_limit_seconds'] ?? 3600);
    if ($timeLimitSeconds < 1) $timeLimitSeconds = 3600;
    if ($timeLimitSeconds > 86400) $timeLimitSeconds = 86400;

    if ($setId > 0) {
        // Lock set label on edit (A-Z auto sequence)
        $stmt = mysqli_prepare($conn, "UPDATE preboards_sets SET title=?, time_limit_seconds=? WHERE preboards_set_id=? AND preboards_subject_id=?");
        mysqli_stmt_bind_param($stmt, 'siii', $title, $timeLimitSeconds, $setId, $subjectId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Set updated.';
    } else {
        $setLabel = getNextPreboardsSetLabel($conn, $subjectId);
        if (!$setLabel) {
            $_SESSION['error'] = 'All sets A-Z already exist for this subject.';
            header('Location: admin_preboards_sets?preboards_subject_id=' . $subjectId);
            exit;
        }
        $stmt = mysqli_prepare($conn, "INSERT INTO preboards_sets (preboards_subject_id, set_label, title, time_limit_seconds, sort_order) VALUES (?, ?, ?, ?, 0)");
        mysqli_stmt_bind_param($stmt, 'issi', $subjectId, $setLabel, $title, $timeLimitSeconds);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Set ' . $setLabel . ' added.';
    }
    header('Location: admin_preboards_sets?preboards_subject_id=' . $subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM preboards_sets WHERE preboards_set_id=? AND preboards_subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $eid, $subjectId);
        mysqli_stmt_execute($stmt);
        $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

$searchQ = trim($_GET['q'] ?? '');
$setParts = ['s.preboards_subject_id=?'];
$setTypes = 'i';
$setVals = [$subjectId];
if ($searchQ !== '') {
    $setParts[] = '(s.set_label LIKE ? OR IFNULL(s.title, \'\') LIKE ?)';
    $setTypes .= 'ss';
    $like = '%' . $searchQ . '%';
    $setVals[] = $like;
    $setVals[] = $like;
}
$setsSql = 'SELECT s.*, (SELECT COUNT(*) FROM preboards_questions q WHERE q.preboards_set_id=s.preboards_set_id) AS questions_cnt FROM preboards_sets s WHERE ' . implode(' AND ', $setParts) . ' ORDER BY s.sort_order ASC, s.set_label ASC';
$stmt = mysqli_prepare($conn, $setsSql);
mysqli_stmt_bind_param($stmt, $setTypes, ...$setVals);
mysqli_stmt_execute($stmt);
$sets = mysqli_stmt_get_result($stmt);
$preboardsNavQ = $searchQ !== '' ? '&q=' . rawurlencode($searchQ) : '';

$totalSetsRes = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM preboards_sets WHERE preboards_subject_id=' . (int)$subjectId);
$totalSetsSubject = 0;
if ($totalSetsRes) {
    $tr = mysqli_fetch_assoc($totalSetsRes);
    $totalSetsSubject = (int)($tr['c'] ?? 0);
}

$nextSetLabel = getNextPreboardsSetLabel($conn, $subjectId);

$showCompletion = isset($_GET['completion']) && (int)$_GET['completion'] === 1;
$completionData = [];
$setsForCompletion = [];
if ($showCompletion) {
    $setsForCompletionRes = mysqli_query($conn, "SELECT preboards_set_id, set_label FROM preboards_sets WHERE preboards_subject_id=$subjectId ORDER BY sort_order ASC, set_label ASC");
    while ($r = mysqli_fetch_assoc($setsForCompletionRes)) {
        $setsForCompletion[] = $r;
    }
    $students = mysqli_query($conn, "SELECT user_id, full_name, email FROM users WHERE role='student' AND LOWER(status)='approved' ORDER BY full_name ASC");
    while ($stu = $students ? mysqli_fetch_assoc($students) : null) {
        if (!$stu) break;
        $uid = (int)$stu['user_id'];
        $bySet = [];
        // Latest submitted attempt per set (supports retakes)
        $ar = mysqli_query($conn, "SELECT a.preboards_set_id, a.score, a.correct_count, a.total_count, a.submitted_at
          FROM preboards_attempts a
          INNER JOIN preboards_sets s ON s.preboards_set_id=a.preboards_set_id
          INNER JOIN (
            SELECT preboards_set_id, MAX(attempt_no) AS max_no
            FROM preboards_attempts
            WHERE user_id=$uid AND status='submitted'
            GROUP BY preboards_set_id
          ) x ON x.preboards_set_id=a.preboards_set_id AND x.max_no=a.attempt_no
          WHERE a.user_id=$uid AND a.status='submitted' AND s.preboards_subject_id=$subjectId");
        while ($arow = $ar ? mysqli_fetch_assoc($ar) : null) {
            if ($arow) $bySet[(int)$arow['preboards_set_id']] = $arow;
            if (!$arow) break;
        }
        $completionData[] = ['user' => $stu, 'by_set' => $bySet];
    }
    if ($searchQ !== '') {
        $sq = mb_strtolower($searchQ);
        $completionData = array_values(array_filter($completionData, function ($row) use ($sq) {
            $name = mb_strtolower($row['user']['full_name'] ?? '');
            $em = mb_strtolower($row['user']['email'] ?? '');
            return strpos($name, $sq) !== false || strpos($em, $sq) !== false;
        }));
    }
}

$pendingRequests = preboards_list_pending_requests($conn, $subjectId);
if ($searchQ !== '' && !empty($pendingRequests)) {
    $sq = mb_strtolower($searchQ);
    $pendingRequests = array_values(array_filter($pendingRequests, function ($r) use ($sq) {
        $n = mb_strtolower($r['full_name'] ?? '');
        $e = mb_strtolower($r['email'] ?? '');
        $lbl = mb_strtolower($r['set_label'] ?? '');
        return strpos($n, $sq) !== false || strpos($e, $sq) !== false || strpos($lbl, $sq) !== false;
    }));
}
// Global badge (all subjects), not just this subject's inbox.
preboards_sync_admin_pending_badge(null, $conn);

$pageTitle = 'Preboards Sets - ' . ($subject['subject_name'] ?? 'Subject');
$subjectName = (string) ($subject['subject_name'] ?? 'Subject');
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Preboards', 'admin_preboards_subjects'],
    [$subjectName, 'admin_preboards_sets?preboards_subject_id=' . (int)$subjectId],
    [$showCompletion ? 'Completion' : 'Sets'],
];
$adminHeroIcon = 'clipboard-check';
$adminHeroTint = 'violet';
$adminHeroEyebrow = 'Preboards / ' . $subjectName;
$adminHeroTitle = $showCompletion ? 'Completion' : 'Sets';
$adminHeroSubtitle = 'Preboard Examination Workspace';
$subjectIsActive = strtolower((string) ($subject['status'] ?? '')) === 'active';
$adminHeroMeta = '<span class="preboards-ws-status' . ($subjectIsActive ? ' preboards-ws-status--active' : '') . '">'
    . '<span class="preboards-ws-status__dot" aria-hidden="true"></span>'
    . ($subjectIsActive ? 'Active' : 'Inactive')
    . '</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-preboards-sets-page admin-preboards-workspace" x-data="preboardsSetsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

  <div class="preboards-ws">
  <div class="preboards-ws-command">
    <?php
      preboards_render_workspace_nav([
          'subject_id' => (int) $subjectId,
          'subject_name' => $subjectName,
          'active' => $showCompletion ? 'completion' : 'sets',
          'pending_count' => count($pendingRequests),
          'sets_q' => $searchQ,
          'sets_count' => (int) $totalSetsSubject,
          'variant' => 'app',
      ]);
    ?>
    <?php if (!$showCompletion): ?>
      <button type="button"
              @click="openNewSet()"
              :disabled="!nextSetLabelFromServer"
              class="preboards-ws-add admin-btn admin-btn--primary disabled:opacity-50 disabled:cursor-not-allowed">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
        <span x-text="nextSetLabelFromServer ? 'Add Set' : 'All sets created'"></span>
      </button>
    <?php endif; ?>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success mb-5 flex items-center gap-2">
      <i class="bi bi-check-circle-fill shrink-0"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-5 flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill shrink-0"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <?php if ($showCompletion): ?>
  <section class="preboards-report preboards-matrix-section" aria-labelledby="preboards-completion-heading">
    <header class="preboards-section-head">
      <div>
        <h2 id="preboards-completion-heading" class="preboards-section-title">Completion</h2>
        <p class="preboards-report__lede">Who has completed which set (one attempt per set).</p>
      </div>
    </header>
    <form method="get" action="admin_preboards_sets" class="preboards-set-toolstrip">
      <input type="hidden" name="preboards_subject_id" value="<?php echo (int)$subjectId; ?>">
      <input type="hidden" name="completion" value="1">
      <div class="preboards-set-toolstrip__search">
        <label for="pb-sets-search-q" class="sr-only">Filter students</label>
        <input type="search" id="pb-sets-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search students..." class="input-custom" autocomplete="off">
      </div>
      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-search" aria-hidden="true"></i> Apply</button>
      <?php if ($searchQ !== ''): ?>
        <a href="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?>&completion=1" class="admin-btn admin-btn--secondary admin-btn--sm">Clear</a>
      <?php endif; ?>
    </form>
    <div class="preboards-matrix-wrap">
      <table class="preboards-matrix">
        <thead>
          <tr>
            <th class="preboards-matrix__student" scope="col">Student</th>
            <?php foreach ($setsForCompletion as $s): ?>
              <th class="preboards-matrix__set" scope="col">Set <?php echo h($s['set_label']); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($completionData as $row): ?>
            <tr>
              <th class="preboards-matrix__student" scope="row">
                <div class="preboards-matrix__name"><?php echo h($row['user']['full_name']); ?></div>
                <div class="preboards-matrix__email"><?php echo h($row['user']['email'] ?? ''); ?></div>
              </th>
              <?php foreach ($setsForCompletion as $s): ?>
                <?php $att = $row['by_set'][(int)$s['preboards_set_id']] ?? null; ?>
                <td class="preboards-matrix__cell">
                  <?php if ($att):
                      $pct = (float) $att['score'];
                      $pctW = max(0, min(100, $pct));
                  ?>
                    <span class="preboards-matrix__pct"><?php echo number_format($pct, 0); ?>%</span>
                    <span class="preboards-matrix__frac"><?php echo (int)$att['correct_count']; ?>/<?php echo (int)$att['total_count']; ?></span>
                    <span class="preboards-matrix__bar" aria-hidden="true"><span style="width: <?php echo $pctW; ?>%"></span></span>
                  <?php else: ?>
                    <span class="preboards-matrix__empty" aria-label="Not completed">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($completionData)): ?>
            <tr><td colspan="<?php echo count($setsForCompletion) + 1; ?>" class="px-5 py-8 text-center quiz-admin-empty"><?php echo $searchQ !== '' ? 'No students match your search.' : 'No students or no attempts yet.'; ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php else: ?>

  <?php if (!empty($pendingRequests)): ?>
    <section id="preboards-requests" class="preboards-requests" style="scroll-margin-top:1.25rem">
      <h2 class="preboards-report__title">Who requested access</h2>
      <p class="preboards-report__lede">Students asking for locked-set access or a retake. <span><?php echo count($pendingRequests); ?> pending</span></p>
      <div class="preboards-report__table-wrap">
        <table class="quiz-admin-data-table w-full text-left">
          <thead>
            <tr>
              <th class="px-5 py-3 font-semibold">Student</th>
              <th class="px-5 py-3 font-semibold">Set</th>
              <th class="px-5 py-3 font-semibold">Type</th>
              <th class="px-5 py-3 font-semibold">Requested</th>
              <th class="px-5 py-3 font-semibold w-[120px]">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingRequests as $r): ?>
              <tr class="quiz-admin-row">
                <td class="px-5 py-3">
                  <div class="font-medium text-slate-900"><?php echo h($r['full_name'] ?? ''); ?></div>
                  <div class="text-xs text-gray-500"><?php echo h($r['email'] ?? ''); ?></div>
                </td>
                <td class="px-5 py-3 font-semibold text-slate-900">Set <?php echo h($r['set_label'] ?? ''); ?></td>
                <td class="px-5 py-3 text-sm">
                  <?php if (($r['request_type'] ?? '') === 'open'): ?>
                    <span class="px-2 py-0.5 rounded-full bg-sky-500/15 text-sky-300 border border-sky-500/35 font-semibold">Access</span>
                  <?php else: ?>
                    <span class="px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-200 border border-amber-500/35 font-semibold">Retake</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-sm text-gray-400"><?php echo !empty($r['requested_at']) ? date('M j, Y g:i A', strtotime($r['requested_at'])) : '-'; ?></td>
                <td class="px-5 py-3 text-center">
                  <div class="admin-row-actions">
                    <form method="POST" action="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?><?php echo h($preboardsNavQ); ?>#preboards-requests" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="decide_request">
                      <input type="hidden" name="preboards_request_id" value="<?php echo (int)$r['preboards_request_id']; ?>">
                      <input type="hidden" name="decision" value="approved">
                      <button type="submit" class="preboards-text-action preboards-text-action--ok" title="Approve <?php echo h($r['full_name'] ?? ''); ?>"><i class="bi bi-check-lg" aria-hidden="true"></i> Approve</button>
                    </form>
                    <form method="POST" action="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?><?php echo h($preboardsNavQ); ?>#preboards-requests" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="decide_request">
                      <input type="hidden" name="preboards_request_id" value="<?php echo (int)$r['preboards_request_id']; ?>">
                      <input type="hidden" name="decision" value="denied">
                      <button type="submit" class="preboards-text-action preboards-text-action--danger" title="Deny <?php echo h($r['full_name'] ?? ''); ?>"><i class="bi bi-x-lg" aria-hidden="true"></i> Deny</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <?php
        $setCards = [];
        while ($row = mysqli_fetch_assoc($sets)) {
            $setCards[] = $row;
        }
        $hasAny = $setCards !== [];
        $setsShown = count($setCards);
  ?>
  <section class="content-library" aria-labelledby="preboards-sets-heading">
    <div class="content-library__head">
      <div>
        <h2 id="preboards-sets-heading" class="content-library__title">Manage Sets</h2>
        <p class="content-library__sub">Create, configure and manage examination sets for <?php echo h($subjectName); ?>.</p>
      </div>
      <span class="content-library__count"><?php echo (int)$setsShown; ?> set<?php echo (int)$setsShown === 1 ? '' : 's'; ?></span>
    </div>
    <form method="get" action="admin_preboards_sets" class="content-library__toolbar">
      <input type="hidden" name="preboards_subject_id" value="<?php echo (int)$subjectId; ?>">
      <div class="content-library__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <label for="pb-sets-search-q" class="sr-only">Search sets</label>
        <input type="search" id="pb-sets-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search sets..." class="input-custom" autocomplete="off">
      </div>
      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Apply</button>
      <?php if ($searchQ !== ''): ?>
        <a href="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?>" class="admin-btn admin-btn--ghost admin-btn--sm">Clear</a>
      <?php endif; ?>
    </form>
      <?php if (!$hasAny): ?>
        <div class="content-library-empty">
          <i class="bi bi-inbox" aria-hidden="true"></i>
          <h3><?php echo $searchQ !== '' ? 'No sets match your search' : 'No sets yet'; ?></h3>
          <p class="content-library__sub"><?php echo $searchQ !== '' ? 'Try different keywords or clear the filter.' : 'Add sets (A, B, C, D) so students can take one preboard per set.'; ?></p>
          <?php if ($searchQ === ''): ?>
            <button type="button" @click="openNewSet()" class="admin-btn admin-btn--primary mt-3"><i class="bi bi-plus-lg"></i> Add Set</button>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="content-library-table-scroll">
          <table class="content-library-table">
            <thead>
              <tr>
                <th scope="col">Set</th>
                <th class="col-desktop" scope="col">Questions</th>
                <th class="col-desktop" scope="col">Duration</th>
                <th class="col-desktop" scope="col">Schedule</th>
                <th class="col-desktop" scope="col">Status</th>
                <th class="col-actions" scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
          <?php foreach ($setCards as $row):
              $timeSecs = preboards_set_effective_time_limit_seconds($row);
              $accessMeta = preboards_set_access_meta($row);
              $statusParts = preboards_exam_status_parts($accessMeta);
              $qCnt = (int)($row['questions_cnt'] ?? 0);
              $qLabel = $qCnt . ' question' . ($qCnt === 1 ? '' : 's');
              $durLabel = formatTimeLimitSeconds($timeSecs);
              $questionsUrl = 'admin_preboards_questions?preboards_set_id=' . (int)$row['preboards_set_id'] . '&preboards_subject_id=' . (int)$subjectId;
              $stKey = strtolower((string)$statusParts['key']);
              $stPill = in_array($stKey, ['open', 'active'], true) ? 'active' : (in_array($stKey, ['upcoming'], true) ? 'pending' : 'inactive');
          ?>
            <tr>
              <td>
                <div class="content-library-subject">
                  <span class="lms-icon-tile inline-flex h-10 w-10 items-center justify-center rounded-xl" aria-hidden="true"><?php echo h($row['set_label']); ?></span>
                  <span class="content-library-subject__text">
                    <a class="content-library-subject__name" href="<?php echo h($questionsUrl); ?>">Set <?php echo h($row['set_label']); ?></a>
                    <?php if (trim((string)($row['title'] ?? '')) !== ''): ?>
                      <span class="content-library-subject__desc"><?php echo h($row['title']); ?></span>
                    <?php endif; ?>
                    <span class="content-library-subject__mobile-meta"><?php echo h($qLabel); ?> · <?php echo h($durLabel); ?> · <?php echo h($statusParts['word']); ?></span>
                  </span>
                </div>
              </td>
              <td class="col-desktop">
                <span class="content-library-metric"><?php echo (int)$qCnt; ?> <span><?php echo $qCnt === 1 ? 'question' : 'questions'; ?></span></span>
              </td>
              <td class="col-desktop"><?php echo h($durLabel); ?></td>
              <td class="col-desktop"><?php echo $statusParts['detail'] !== '' ? h($statusParts['detail']) : '—'; ?></td>
              <td class="col-desktop">
                <span class="admin-status-pill admin-status-pill--<?php echo h($stPill); ?>"><?php echo h($statusParts['word']); ?></span>
              </td>
              <td class="col-actions">
                <div class="admin-row-actions inline-flex items-center justify-end gap-1.5"
                     x-data="{ menuOpen: false, dropUp: false, closeMenu(){ this.menuOpen=false; this.dropUp=false; }, toggleMenu(){ this.menuOpen=!this.menuOpen; if(!this.menuOpen){this.dropUp=false;return;} this.$nextTick(()=>{ const wrap=this.$refs.menuWrap; const menu=this.$refs.menuEl; if(!wrap||!menu)return; const r=wrap.getBoundingClientRect(); const h=Math.max(menu.offsetHeight||0,200); this.dropUp=(r.bottom+h+16)>window.innerHeight; const first=menu.querySelector('[role=menuitem]'); if(first) first.focus(); }); } }"
                     @keydown.escape.window="closeMenu()">
                  <a href="<?php echo h($questionsUrl); ?>" class="hub-next-btn">Manage <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                  <div class="admin-row-menu-wrap" x-ref="menuWrap">
                    <button type="button" class="admin-row-action admin-row-action--more preboards-more-btn" :class="menuOpen ? 'is-open' : ''" :aria-expanded="menuOpen" aria-haspopup="menu" aria-label="<?php echo h('More actions for Set ' . $row['set_label']); ?>" title="<?php echo h('More actions for Set ' . $row['set_label']); ?>" @click.stop="toggleMenu()"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                    <div x-show="menuOpen" x-cloak x-ref="menuEl" role="menu" class="admin-row-menu" :class="{ 'is-drop-up': dropUp }" x-transition.opacity.duration.180ms @click.outside="closeMenu()">
                    <button type="button" class="admin-row-menu__item" role="menuitem"
                          data-id="<?php echo (int)$row['preboards_set_id']; ?>"
                          data-label="<?php echo h($row['set_label']); ?>"
                          data-use-schedule="<?php echo (int)($row['use_schedule'] ?? 0); ?>"
                          data-opens-at="<?php echo h(preboards_datetime_sql_to_local($row['opens_at'] ?? '')); ?>"
                          data-closes-at="<?php echo h(preboards_datetime_sql_to_local($row['closes_at'] ?? '')); ?>"
                          @click="closeMenu(); openScheduleSet($el.dataset.id, $el.dataset.label || '', $el.dataset.useSchedule === '1', $el.dataset.opensAt || '', $el.dataset.closesAt || '')">
                      <i class="bi bi-calendar-range" aria-hidden="true"></i> Schedule
                    </button>
                    <?php if (!preboards_set_uses_schedule($row)): ?>
                    <form method="POST" action="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?><?php echo h($preboardsNavQ); ?>" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="toggle_open">
                      <input type="hidden" name="preboards_set_id" value="<?php echo (int)$row['preboards_set_id']; ?>">
                      <input type="hidden" name="is_open" value="<?php echo ((int)($row['is_open'] ?? 0) === 1) ? 0 : 1; ?>">
                      <button type="submit" class="admin-row-menu__item" role="menuitem" @click="closeMenu()">
                        <i class="bi <?php echo ((int)($row['is_open'] ?? 0) === 1) ? 'bi-lock' : 'bi-unlock'; ?>"></i>
                        <?php echo ((int)($row['is_open'] ?? 0) === 1) ? 'Lock set' : 'Open set'; ?>
                      </button>
                    </form>
                    <?php endif; ?>
                    <button type="button" class="admin-row-menu__item" role="menuitem" data-id="<?php echo (int)$row['preboards_set_id']; ?>" data-label="<?php echo h($row['set_label']); ?>" data-title="<?php echo h($row['title'] ?? ''); ?>" data-secs="<?php echo $timeSecs; ?>" @click="closeMenu(); openEditSet($el.dataset.id, $el.dataset.label || '', $el.dataset.title || '', parseInt($el.dataset.secs) || 3600)"><i class="bi bi-pencil"></i> Edit</button>
                    <button type="button" class="admin-row-menu__item admin-row-menu__item--danger" role="menuitem" data-id="<?php echo (int)$row['preboards_set_id']; ?>" data-label="<?php echo h($row['set_label']); ?>" @click="closeMenu(); openDeleteSet($el.dataset.id, $el.dataset.label || '')"><i class="bi bi-trash"></i> Delete</button>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
  </section>

  <?php endif; ?>
  </div>
  <?php if (isset($stmt)) { mysqli_stmt_close($stmt); } ?>

  <!-- Add/Edit Set Modal -->
  <div x-show="setModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="setModalOpen = false">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="setModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-2xl shadow-modal border border-slate-200/70 bg-white/95 backdrop-blur-xl max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-slate-100 flex justify-between items-center quiz-modal-panel__head">
        <h2 class="text-lg font-bold text-slate-900 m-0" x-text="isEdit ? 'Edit set' : 'Add set'"></h2>
        <button type="button" @click="setModalOpen = false" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?><?php echo h($preboardsNavQ); ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="preboards_set_id" :value="preboards_set_id">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Set label</label>
            <div class="flex items-center gap-2">
              <div class="px-3 py-2 rounded-lg bg-slate-50 text-slate-900 font-semibold border border-slate-200" x-text="set_label || '-'"></div>
              <span class="text-sm text-gray-500" x-show="!isEdit">Auto-generated (A-Z)</span>
              <span class="text-sm text-gray-500" x-show="isEdit">Locked</span>
            </div>
            <input type="hidden" name="set_label" :value="set_label">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Title (optional)</label>
            <input type="text" name="title" x-model="title" placeholder="e.g. Preboard Set A" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Time limit (manual open only)</label>
            <div class="flex flex-wrap items-center gap-2">
              <input type="number" x-model.number="time_limit_hours" min="0" max="24" class="input-custom w-20" placeholder="1">
              <span class="text-gray-400">hour(s)</span>
              <input type="number" x-model.number="time_limit_mins" min="0" max="59" class="input-custom w-20" placeholder="0">
              <span class="text-gray-400">min(s)</span>
              <input type="number" x-model.number="time_limit_secs" min="0" max="59" class="input-custom w-20" placeholder="0">
              <span class="text-gray-400">sec(s)</span>
            </div>
            <input type="hidden" name="time_limit_seconds" :value="Math.max(60, time_limit_hours * 3600 + time_limit_mins * 60 + time_limit_secs)">
            <p class="text-xs text-gray-500 mt-1">Used when the set is manually opened. If a schedule is set, duration comes from the open → close window instead.</p>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="setModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-violet-600 text-white hover:bg-violet-500 transition inline-flex items-center gap-2 shadow-lg shadow-violet-900/30"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Add'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Schedule Set Modal -->
  <div x-show="scheduleModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="scheduleModalOpen = false">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="scheduleModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-2xl shadow-modal border border-slate-200/70 bg-white/95 backdrop-blur-xl max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-slate-100 flex justify-between items-center quiz-modal-panel__head">
        <h2 class="text-lg font-bold text-slate-900 m-0"><i class="bi bi-calendar-range text-sky-600 mr-2"></i> Schedule access</h2>
        <button type="button" @click="scheduleModalOpen = false" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?><?php echo h($preboardsNavQ); ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="preboards_set_id" :value="schedule_set_id">
        <p class="text-sm text-gray-400 mb-4">Set <span class="font-semibold text-gray-200" x-text="schedule_set_label"></span> - students can take this preboard only between the open and close times (Philippines time). The exam timer uses that window (e.g. 1:00 AM - 2:00 AM = 1 hour).</p>
        <div class="space-y-4">
          <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-slate-200 bg-slate-50">
            <input type="checkbox" name="use_schedule" value="1" class="mt-1" x-model="schedule_enabled">
            <span>
              <span class="block font-semibold text-slate-900">Enable scheduled window</span>
              <span class="block text-xs text-gray-500 mt-0.5">Overrides manual Open/Locked. Duration is computed from open → close.</span>
            </span>
          </label>
          <div x-show="schedule_enabled" x-cloak class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">Opens at <span class="text-red-400">*</span></label>
              <input type="datetime-local" name="opens_at" x-model="schedule_opens_at" class="input-custom w-full" :required="schedule_enabled" @change="updateScheduleDurationPreview()">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">Closes at <span class="text-red-400">*</span></label>
              <input type="datetime-local" name="closes_at" x-model="schedule_closes_at" class="input-custom w-full" :required="schedule_enabled" @change="updateScheduleDurationPreview()">
              <p class="text-xs text-gray-500 mt-1">Required. Students must submit before this time; timer counts down to close.</p>
            </div>
            <p class="text-sm text-sky-200/90 m-0 px-3 py-2 rounded-lg bg-sky-500/10 border border-sky-500/25" x-show="schedule_duration_label" x-cloak>
              <i class="bi bi-clock-history mr-1"></i> Exam duration: <strong x-text="schedule_duration_label"></strong>
            </p>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="scheduleModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-sky-600 text-white hover:bg-sky-500 transition inline-flex items-center gap-2"><i class="bi bi-save"></i> Save schedule</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Set Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="deleteModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-2xl shadow-modal border border-slate-200/70 bg-white/95 backdrop-blur-xl max-w-md w-full p-5" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-bold text-slate-900 m-0"><i class="bi bi-trash text-rose-500 mr-2"></i> Delete set</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_sets?preboards_subject_id=<?php echo (int)$subjectId; ?><?php echo h($preboardsNavQ); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="preboards_set_id" :value="delete_set_id">
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/35 text-amber-100 mb-4">
          <div class="font-semibold">This will delete the set and all its questions and attempt data.</div>
          <div class="text-sm mt-1 text-amber-200/90">Set: <span class="font-semibold" x-text="delete_set_label"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-500 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function preboardsSetsApp() {
      return {
        setModalOpen: false,
        scheduleModalOpen: false,
        deleteModalOpen: false,
        isEdit: false,
        preboards_set_id: 0,
        set_label: '',
        title: '',
        time_limit_hours: 1,
        time_limit_mins: 0,
        time_limit_secs: 0,
        delete_set_id: 0,
        delete_set_label: '',
        schedule_set_id: 0,
        schedule_set_label: '',
        schedule_enabled: false,
        schedule_opens_at: '',
        schedule_closes_at: '',
        schedule_duration_label: '',
        nextSetLabelFromServer: <?php echo json_encode($nextSetLabel ?: ''); ?>,
        editFromServer: <?php echo !empty($edit) ? json_encode(['id' => (int)$edit['preboards_set_id'], 'label' => $edit['set_label'] ?? '', 'title' => $edit['title'] ?? '', 'time_limit_seconds' => (int)($edit['time_limit_seconds'] ?? 3600)]) : 'null'; ?>,
        openNewSet() {
          this.isEdit = false;
          this.preboards_set_id = 0;
          this.set_label = this.nextSetLabelFromServer || '';
          this.title = '';
          this.time_limit_hours = 1;
          this.time_limit_mins = 0;
          this.time_limit_secs = 0;
          this.setModalOpen = true;
        },
        openEditSet(id, label, title, secs) {
          this.isEdit = true;
          this.preboards_set_id = id;
          this.set_label = label || '';
          this.title = title || '';
          secs = (secs > 0 && secs <= 86400) ? secs : 3600;
          this.time_limit_hours = Math.floor(secs / 3600);
          this.time_limit_mins = Math.floor((secs % 3600) / 60);
          this.time_limit_secs = secs % 60;
          this.setModalOpen = true;
        },
        openDeleteSet(id, label) {
          this.delete_set_id = id;
          this.delete_set_label = label || '';
          this.deleteModalOpen = true;
        },
        openScheduleSet(id, label, enabled, opensAt, closesAt) {
          this.schedule_set_id = id;
          this.schedule_set_label = label || '';
          this.schedule_enabled = !!enabled;
          this.schedule_opens_at = opensAt || '';
          this.schedule_closes_at = closesAt || '';
          this.updateScheduleDurationPreview();
          this.scheduleModalOpen = true;
        },
        updateScheduleDurationPreview() {
          const open = this.schedule_opens_at;
          const close = this.schedule_closes_at;
          if (!open || !close) {
            this.schedule_duration_label = '';
            return;
          }
          const start = new Date(open);
          const end = new Date(close);
          if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) {
            this.schedule_duration_label = '';
            return;
          }
          let secs = Math.floor((end - start) / 1000);
          const h = Math.floor(secs / 3600);
          secs %= 3600;
          const m = Math.floor(secs / 60);
          const s = secs % 60;
          const parts = [];
          if (h > 0) parts.push(h + ' hour' + (h !== 1 ? 's' : ''));
          if (m > 0) parts.push(m + ' min' + (m !== 1 ? 's' : ''));
          if (s > 0) parts.push(s + ' second' + (s !== 1 ? 's' : ''));
          this.schedule_duration_label = parts.join(' ') || '0 seconds';
        },
        initEditFromServer() {
          if (this.editFromServer) this.openEditSet(this.editFromServer.id, this.editFromServer.label, this.editFromServer.title, this.editFromServer.time_limit_seconds || 3600);
        }
      };
    }
  </script>
</main>
</body>
</html>
