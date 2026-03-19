<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$subjectId = sanitizeInt($_GET['preboards_subject_id'] ?? 0);
if ($subjectId <= 0) {
    header('Location: admin_preboards_subjects.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM preboards_subjects WHERE preboards_subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subject = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$subject) {
    header('Location: admin_preboards_subjects.php');
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
        header('Location: admin_preboards_sets.php?preboards_subject_id=' . $subjectId);
        exit;
    }
    $action = $_POST['action'] ?? 'save';

    if ($action === 'toggle_open') {
        $setId = sanitizeInt($_POST['preboards_set_id'] ?? 0);
        $newVal = isset($_POST['is_open']) && (int)$_POST['is_open'] === 1 ? 1 : 0;
        if ($setId > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE preboards_sets SET is_open=? WHERE preboards_set_id=? AND preboards_subject_id=?");
            mysqli_stmt_bind_param($stmt, 'iii', $newVal, $setId, $subjectId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = $newVal ? 'Set opened.' : 'Set locked.';
        }
        header('Location: admin_preboards_sets.php?preboards_subject_id=' . $subjectId);
        exit;
    }

    if ($action === 'decide_request') {
        $reqId = sanitizeInt($_POST['preboards_request_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        if ($reqId > 0 && in_array($decision, ['approved', 'denied'], true)) {
            $adminId = (int)getCurrentUserId();
            // Load request + ensure it belongs to this subject via set join
            $stmt = mysqli_prepare($conn, "SELECT r.*, s.preboards_subject_id FROM preboards_requests r
              INNER JOIN preboards_sets s ON s.preboards_set_id=r.preboards_set_id
              WHERE r.preboards_request_id=? AND r.status='pending' LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $reqId);
            mysqli_stmt_execute($stmt);
            $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($req && (int)$req['preboards_subject_id'] === (int)$subjectId) {
                $decAt = date('Y-m-d H:i:s');
                $upd = mysqli_prepare($conn, "UPDATE preboards_requests SET status=?, decided_at=?, decided_by=? WHERE preboards_request_id=? AND status='pending'");
                mysqli_stmt_bind_param($upd, 'ssii', $decision, $decAt, $adminId, $reqId);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);

                if ($decision === 'approved') {
                    $uid = (int)$req['user_id'];
                    $sid = (int)$req['preboards_set_id'];
                    if (($req['request_type'] ?? '') === 'open') {
                        // Grant one-time access token for locked sets (auto-locks again after first use)
                        $ins = mysqli_prepare($conn, "INSERT INTO preboards_set_access (user_id, preboards_set_id, granted_by, used_at, revoked_at)
                          VALUES (?, ?, ?, NULL, NULL)
                          ON DUPLICATE KEY UPDATE granted_at=CURRENT_TIMESTAMP, granted_by=VALUES(granted_by), used_at=NULL, revoked_at=NULL");
                        mysqli_stmt_bind_param($ins, 'iii', $uid, $sid, $adminId);
                        mysqli_stmt_execute($ins);
                        mysqli_stmt_close($ins);
                    } elseif (($req['request_type'] ?? '') === 'retake') {
                        // Grant one retake token
                        $ins = mysqli_prepare($conn, "INSERT INTO preboards_retake_tokens (user_id, preboards_set_id, granted_by) VALUES (?, ?, ?)");
                        mysqli_stmt_bind_param($ins, 'iii', $uid, $sid, $adminId);
                        mysqli_stmt_execute($ins);
                        mysqli_stmt_close($ins);
                    }
                }
                $_SESSION['message'] = 'Request ' . ($decision === 'approved' ? 'approved' : 'denied') . '.';
            }
        }
        header('Location: admin_preboards_sets.php?preboards_subject_id=' . $subjectId);
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
        header('Location: admin_preboards_sets.php?preboards_subject_id=' . $subjectId);
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
            header('Location: admin_preboards_sets.php?preboards_subject_id=' . $subjectId);
            exit;
        }
        $stmt = mysqli_prepare($conn, "INSERT INTO preboards_sets (preboards_subject_id, set_label, title, time_limit_seconds, sort_order) VALUES (?, ?, ?, ?, 0)");
        mysqli_stmt_bind_param($stmt, 'issi', $subjectId, $setLabel, $title, $timeLimitSeconds);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Set ' . $setLabel . ' added.';
    }
    header('Location: admin_preboards_sets.php?preboards_subject_id=' . $subjectId);
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

$stmt = mysqli_prepare($conn, "SELECT s.*, (SELECT COUNT(*) FROM preboards_questions q WHERE q.preboards_set_id=s.preboards_set_id) AS questions_cnt FROM preboards_sets s WHERE s.preboards_subject_id=? ORDER BY s.sort_order ASC, s.set_label ASC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$sets = mysqli_stmt_get_result($stmt);

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
}

$pendingRequests = [];
$reqRes = mysqli_query($conn, "SELECT r.preboards_request_id, r.user_id, r.preboards_set_id, r.request_type, r.requested_at,
  u.full_name, u.email, s.set_label
  FROM preboards_requests r
  INNER JOIN preboards_sets s ON s.preboards_set_id=r.preboards_set_id
  INNER JOIN users u ON u.user_id=r.user_id
  WHERE r.status='pending' AND s.preboards_subject_id=" . (int)$subjectId . "
  ORDER BY r.requested_at DESC");
if ($reqRes) { while ($r = mysqli_fetch_assoc($reqRes)) { $pendingRequests[] = $r; } }

$pageTitle = 'Preboards Sets - ' . ($subject['subject_name'] ?? 'Subject');
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard.php'],
    ['Preboards', 'admin_preboards_subjects.php'],
    [($subject['subject_name'] ?? 'Subject'), 'admin_preboards_sets.php?preboards_subject_id=' . (int)$subjectId],
    ['Sets'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app" x-data="preboardsSetsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-5 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-clipboard-check"></i> Sets
    </h1>
    <p class="text-gray-500 mt-1">Each set is one preboard. Students can take one attempt per set (no retake).</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <a href="admin_preboards_subjects.php" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2 transition">Back</a>
    <div class="flex flex-wrap gap-2">
      <?php if ($showCompletion): ?>
        <a href="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2 transition">Back to sets</a>
      <?php else: ?>
        <a href="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>&completion=1" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2 transition">View completion report</a>
        <button type="button"
                @click="openNewSet()"
                :disabled="!nextSetLabelFromServer"
                class="admin-content-btn admin-content-btn--subject px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-primary">
          <i class="bi bi-plus-circle"></i>
          <span x-text="nextSetLabelFromServer ? 'Add set' : 'All sets created'"></span>
        </button>
      <?php endif; ?>
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

  <?php if ($showCompletion): ?>
  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden mb-5">
    <div class="px-5 py-4 border-b border-gray-100">
      <span class="font-semibold text-gray-800">Completion by student</span>
      <p class="text-sm text-gray-500 mt-0.5 mb-0">Who has completed which set (one attempt per set).</p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700">Student</th>
            <?php foreach ($setsForCompletion as $s): ?>
              <th class="px-5 py-3 font-semibold text-gray-700">Set <?php echo h($s['set_label']); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($completionData as $row): ?>
            <tr class="border-b border-gray-100">
              <td class="px-5 py-3">
                <div class="font-medium text-gray-800"><?php echo h($row['user']['full_name']); ?></div>
                <div class="text-xs text-gray-500"><?php echo h($row['user']['email'] ?? ''); ?></div>
              </td>
              <?php foreach ($setsForCompletion as $s): ?>
                <?php $att = $row['by_set'][(int)$s['preboards_set_id']] ?? null; ?>
                <td class="px-5 py-3 text-sm">
                  <?php if ($att): ?>
                    <span class="text-green-700 font-medium"><?php echo number_format((float)$att['score'], 0); ?>%</span>
                    <span class="text-gray-500">(<?php echo (int)$att['correct_count']; ?>/<?php echo (int)$att['total_count']; ?>)</span>
                    <div class="text-xs text-gray-400"><?php echo $att['submitted_at'] ? date('M j, Y', strtotime($att['submitted_at'])) : ''; ?></div>
                  <?php else: ?>
                    <span class="text-gray-400">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($completionData)): ?>
            <tr><td colspan="<?php echo count($setsForCompletion) + 1; ?>" class="px-5 py-8 text-center text-gray-500">No students or no attempts yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100">
      <span class="font-semibold text-gray-800">Sets</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700">Set</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Title</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-28">Time limit</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Questions</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-[320px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false;
          while ($row = mysqli_fetch_assoc($sets)): $hasAny = true;
              $timeSecs = (int)($row['time_limit_seconds'] ?? 3600);
          ?>
            <tr class="border-b border-gray-100">
              <td class="px-5 py-3 font-semibold text-gray-800"><?php echo h($row['set_label']); ?></td>
              <td class="px-5 py-3 text-gray-600"><?php echo h($row['title'] ?: '—'); ?></td>
              <td class="px-5 py-3 text-gray-600"><?php echo formatTimeLimitSeconds($timeSecs); ?></td>
              <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-sm bg-gray-100 text-gray-700"><?php echo (int)($row['questions_cnt'] ?? 0); ?></span></td>
              <td class="px-5 py-3">
                <div class="flex flex-wrap gap-2">
                  <a href="admin_preboards_questions.php?preboards_set_id=<?php echo (int)$row['preboards_set_id']; ?>&preboards_subject_id=<?php echo (int)$subjectId; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-list-check"></i> Questions</a>
                  <form method="POST" action="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="toggle_open">
                    <input type="hidden" name="preboards_set_id" value="<?php echo (int)$row['preboards_set_id']; ?>">
                    <input type="hidden" name="is_open" value="<?php echo ((int)($row['is_open'] ?? 0) === 1) ? 0 : 1; ?>">
                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 <?php echo ((int)($row['is_open'] ?? 0) === 1) ? 'border-emerald-500 text-emerald-700 hover:bg-emerald-500' : 'border-amber-500 text-amber-700 hover:bg-amber-500'; ?> hover:text-white transition">
                      <i class="bi <?php echo ((int)($row['is_open'] ?? 0) === 1) ? 'bi-unlock' : 'bi-lock'; ?>"></i>
                      <?php echo ((int)($row['is_open'] ?? 0) === 1) ? 'Open' : 'Locked'; ?>
                    </button>
                  </form>
                  <button type="button" data-id="<?php echo (int)$row['preboards_set_id']; ?>" data-label="<?php echo h($row['set_label']); ?>" data-title="<?php echo h($row['title'] ?? ''); ?>" data-secs="<?php echo $timeSecs; ?>" @click="openEditSet($el.dataset.id, $el.dataset.label || '', $el.dataset.title || '', parseInt($el.dataset.secs) || 3600)" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition"><i class="bi bi-pencil"></i> Edit</button>
                  <button type="button" data-id="<?php echo (int)$row['preboards_set_id']; ?>" data-label="<?php echo h($row['set_label']); ?>" @click="openDeleteSet($el.dataset.id, $el.dataset.label || '')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="5" class="px-5 py-12 text-center text-gray-500">
                <i class="bi bi-inbox text-4xl block mb-2"></i>
                <div class="font-semibold">No sets yet</div>
                <p class="text-sm mt-1">Add sets (A, B, C, D) so students can take one preboard per set.</p>
                <button type="button" @click="openNewSet()" class="mt-3 px-4 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> Add set</button>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($pendingRequests)): ?>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden mt-6">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
        <div>
          <span class="font-semibold text-gray-800">Requests</span>
          <p class="text-sm text-gray-500 mt-0.5 mb-0">Students requesting access (locked sets) or retake (after completion).</p>
        </div>
        <span class="px-2.5 py-1 rounded-full text-sm bg-amber-50 text-amber-800 border border-amber-200"><?php echo count($pendingRequests); ?> pending</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-5 py-3 font-semibold text-gray-700">Student</th>
              <th class="px-5 py-3 font-semibold text-gray-700">Set</th>
              <th class="px-5 py-3 font-semibold text-gray-700">Type</th>
              <th class="px-5 py-3 font-semibold text-gray-700">Requested</th>
              <th class="px-5 py-3 font-semibold text-gray-700 w-[260px]">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingRequests as $r): ?>
              <tr class="border-b border-gray-100">
                <td class="px-5 py-3">
                  <div class="font-medium text-gray-800"><?php echo h($r['full_name'] ?? ''); ?></div>
                  <div class="text-xs text-gray-500"><?php echo h($r['email'] ?? ''); ?></div>
                </td>
                <td class="px-5 py-3 font-semibold text-gray-800">Set <?php echo h($r['set_label'] ?? ''); ?></td>
                <td class="px-5 py-3 text-sm">
                  <?php if (($r['request_type'] ?? '') === 'open'): ?>
                    <span class="px-2 py-0.5 rounded-full bg-sky-50 text-sky-700 border border-sky-200 font-semibold">Access</span>
                  <?php else: ?>
                    <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200 font-semibold">Retake</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-sm text-gray-600"><?php echo !empty($r['requested_at']) ? date('M j, Y g:i A', strtotime($r['requested_at'])) : '—'; ?></td>
                <td class="px-5 py-3">
                  <div class="flex flex-wrap gap-2">
                    <form method="POST" action="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="decide_request">
                      <input type="hidden" name="preboards_request_id" value="<?php echo (int)$r['preboards_request_id']; ?>">
                      <input type="hidden" name="decision" value="approved">
                      <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-emerald-500 text-emerald-700 hover:bg-emerald-500 hover:text-white transition"><i class="bi bi-check-lg"></i> Approve</button>
                    </form>
                    <form method="POST" action="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="decide_request">
                      <input type="hidden" name="preboards_request_id" value="<?php echo (int)$r['preboards_request_id']; ?>">
                      <input type="hidden" name="decision" value="denied">
                      <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-x-lg"></i> Deny</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
  <?php endif; ?>
  <?php if (isset($stmt)) { mysqli_stmt_close($stmt); } ?>

  <!-- Add/Edit Set Modal -->
  <div x-show="setModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="setModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="setModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="isEdit ? 'Edit set' : 'Add set'"></h2>
        <button type="button" @click="setModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="preboards_set_id" :value="preboards_set_id">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Set label</label>
            <div class="flex items-center gap-2">
              <div class="px-3 py-2 rounded-lg bg-gray-100 text-gray-800 font-semibold" x-text="set_label || '—'"></div>
              <span class="text-sm text-gray-500" x-show="!isEdit">Auto-generated (A-Z)</span>
              <span class="text-sm text-gray-500" x-show="isEdit">Locked</span>
            </div>
            <input type="hidden" name="set_label" :value="set_label">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title (optional)</label>
            <input type="text" name="title" x-model="title" placeholder="e.g. Preboard Set A" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Time limit (how long students can take this set)</label>
            <div class="flex flex-wrap items-center gap-2">
              <input type="number" x-model.number="time_limit_hours" min="0" max="24" class="input-custom w-20" placeholder="1">
              <span class="text-gray-600">hour(s)</span>
              <input type="number" x-model.number="time_limit_mins" min="0" max="59" class="input-custom w-20" placeholder="0">
              <span class="text-gray-600">min(s)</span>
              <input type="number" x-model.number="time_limit_secs" min="0" max="59" class="input-custom w-20" placeholder="0">
              <span class="text-gray-600">sec(s)</span>
            </div>
            <input type="hidden" name="time_limit_seconds" :value="Math.max(60, time_limit_hours * 3600 + time_limit_mins * 60 + time_limit_secs)">
            <p class="text-xs text-gray-500 mt-1">Same format as quiz time limits. Minimum is 60 seconds, max is 24 hours.</p>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="setModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Add'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Set Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="deleteModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-md w-full p-5" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800 m-0"><i class="bi bi-trash text-red-500 mr-2"></i> Delete set</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="preboards_set_id" :value="delete_set_id">
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 mb-4">
          <div class="font-semibold">This will delete the set and all its questions and attempt data.</div>
          <div class="text-sm mt-1">Set: <span class="font-semibold" x-text="delete_set_label"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-700 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function preboardsSetsApp() {
      return {
        setModalOpen: false,
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
        initEditFromServer() {
          if (this.editFromServer) this.openEditSet(this.editFromServer.id, this.editFromServer.label, this.editFromServer.title, this.editFromServer.time_limit_seconds || 3600);
        }
      };
    }
  </script>
</main>
</body>
</html>
