<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/content_sort_order.php';
requireAdminPage();

$csrf = generateCSRFToken();
$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) { header('Location: admin_subjects'); exit; }

content_sort_order_ensure_schema($conn);

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subRes = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($subRes);
mysqli_stmt_close($stmt);
if (!$subject) { header('Location: admin_subjects'); exit; }

$listView = strtolower(trim((string) ($_GET['view'] ?? 'student')));
if (!in_array($listView, ['student', 'newest', 'oldest', 'title', 'title_za'], true)) {
    $listView = 'student';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_lessons?subject_id='.$subjectId);
        exit;
    }
    $action = $_POST['action'] ?? 'save';
    if ($action === 'reorder') {
        $ids = $_POST['ordered_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $result = content_sort_order_save($conn, 'lessons', 'lesson_id', $subjectId, $ids);
        if (!empty($result['ok'])) {
            $_SESSION['message'] = 'Lesson order saved.';
        } else {
            $_SESSION['error'] = (string) ($result['error'] ?? 'Could not save lesson order.');
        }
        header('Location: admin_lessons?subject_id=' . $subjectId);
        exit;
    }
    if ($action === 'delete') {
        $lessonId = sanitizeInt($_POST['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM lessons WHERE lesson_id=? AND subject_id=?");
            mysqli_stmt_bind_param($stmt, 'ii', $lessonId, $subjectId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Lesson deleted.';
        }
        header('Location: admin_lessons?subject_id='.$subjectId);
        exit;
    }
    $lessonId = sanitizeInt($_POST['lesson_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($title === '') {
        $_SESSION['error'] = 'Lesson title is required.';
        header('Location: admin_lessons?subject_id='.$subjectId);
        exit;
    }
    if ($lessonId > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE lessons SET title=?, description=? WHERE lesson_id=? AND subject_id=?");
        mysqli_stmt_bind_param($stmt, 'ssii', $title, $desc, $lessonId, $subjectId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Lesson updated.';
    } else {
        $nextOrd = content_sort_order_next($conn, 'lessons', $subjectId);
        $stmt = mysqli_prepare($conn, "INSERT INTO lessons (subject_id, title, description, sort_order) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'issi', $subjectId, $title, $desc, $nextOrd);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Lesson created. It was added at the end of the student order — use Newest filter or Reorder to place it.';
        header('Location: admin_lessons?subject_id='.$subjectId.'&view=newest');
        exit;
    }
    header('Location: admin_lessons?subject_id='.$subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM lessons WHERE lesson_id=? AND subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $eid, $subjectId);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $edit = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);
    }
}

$page = sanitizeInt($_GET['page'] ?? 1, 1);
$perPage = 15;
$offset = ($page - 1) * $perPage;

$searchQ = trim($_GET['q'] ?? '');
$materialsFilter = strtolower(trim((string) ($_GET['materials'] ?? 'all')));
if (!in_array($materialsFilter, ['all', 'complete', 'missing'], true)) {
    $materialsFilter = 'all';
}

$countParts = ['subject_id=?'];
$countTypes = 'i';
$countVals = [$subjectId];
if ($searchQ !== '') {
    $countParts[] = '(title LIKE ? OR IFNULL(description, \'\') LIKE ?)';
    $countTypes .= 'ss';
    $like = '%' . $searchQ . '%';
    $countVals[] = $like;
    $countVals[] = $like;
}
if ($materialsFilter === 'complete') {
    $countParts[] = 'EXISTS (SELECT 1 FROM lesson_videos v WHERE v.lesson_id = lessons.lesson_id)'
        . ' AND EXISTS (SELECT 1 FROM lesson_handouts h WHERE h.lesson_id = lessons.lesson_id)';
} elseif ($materialsFilter === 'missing') {
    $countParts[] = '(NOT EXISTS (SELECT 1 FROM lesson_videos v WHERE v.lesson_id = lessons.lesson_id)'
        . ' OR NOT EXISTS (SELECT 1 FROM lesson_handouts h WHERE h.lesson_id = lessons.lesson_id))';
}
$countSql = 'SELECT COUNT(*) AS total FROM lessons WHERE ' . implode(' AND ', $countParts);
$stmt = mysqli_prepare($conn, $countSql);
mysqli_stmt_bind_param($stmt, $countTypes, ...$countVals);
mysqli_stmt_execute($stmt);
$countRes = mysqli_stmt_get_result($stmt);
$countRow = mysqli_fetch_assoc($countRes);
$totalLessons = (int)($countRow['total'] ?? 0);
mysqli_stmt_close($stmt);
$totalPages = max(1, (int)ceil($totalLessons / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

if ($listView === 'newest') {
    $orderBy = 'l.lesson_id DESC';
} elseif ($listView === 'oldest') {
    $orderBy = 'l.lesson_id ASC';
} elseif ($listView === 'title') {
    $orderBy = 'l.title ASC, l.lesson_id ASC';
} elseif ($listView === 'title_za') {
    $orderBy = 'l.title DESC, l.lesson_id DESC';
} else {
    $orderBy = content_sort_order_sql('l', 'lesson_id');
}
$listParts = ['l.subject_id=?'];
$listTypes = 'i';
$listVals = [$subjectId];
if ($searchQ !== '') {
    $listParts[] = '(l.title LIKE ? OR IFNULL(l.description, \'\') LIKE ?)';
    $listTypes .= 'ss';
    $like = '%' . $searchQ . '%';
    $listVals[] = $like;
    $listVals[] = $like;
}
if ($materialsFilter === 'complete') {
    $listParts[] = 'EXISTS (SELECT 1 FROM lesson_videos v WHERE v.lesson_id = l.lesson_id)'
        . ' AND EXISTS (SELECT 1 FROM lesson_handouts h WHERE h.lesson_id = l.lesson_id)';
} elseif ($materialsFilter === 'missing') {
    $listParts[] = '(NOT EXISTS (SELECT 1 FROM lesson_videos v WHERE v.lesson_id = l.lesson_id)'
        . ' OR NOT EXISTS (SELECT 1 FROM lesson_handouts h WHERE h.lesson_id = l.lesson_id))';
}
$listSql = '
    SELECT l.*,
      (SELECT COUNT(*) FROM lesson_videos v WHERE v.lesson_id=l.lesson_id) AS videos_cnt,
      (SELECT COUNT(*) FROM lesson_handouts h WHERE h.lesson_id=l.lesson_id) AS handouts_cnt
    FROM lessons l
    WHERE ' . implode(' AND ', $listParts) . '
    ORDER BY ' . $orderBy . '
    LIMIT ? OFFSET ?';
$listTypes .= 'ii';
$listVals[] = $perPage;
$listVals[] = $offset;
$stmt = mysqli_prepare($conn, $listSql);
mysqli_stmt_bind_param($stmt, $listTypes, ...$listVals);
mysqli_stmt_execute($stmt);
$lessonsRes = mysqli_stmt_get_result($stmt);
$lessonRows = [];
if ($lessonsRes) {
    while ($row = mysqli_fetch_assoc($lessonsRes)) {
        $lessonRows[] = $row;
    }
}
mysqli_stmt_close($stmt);

// Full subject list in student order for the reorder modal (never paginated/filtered).
$reorderRows = [];
$reorderSql = '
    SELECT l.lesson_id, l.title, l.description, l.sort_order,
      (SELECT COUNT(*) FROM lesson_videos v WHERE v.lesson_id=l.lesson_id) AS videos_cnt,
      (SELECT COUNT(*) FROM lesson_handouts h WHERE h.lesson_id=l.lesson_id) AS handouts_cnt
    FROM lessons l
    WHERE l.subject_id = ?
    ORDER BY ' . content_sort_order_sql('l', 'lesson_id');
$reorderStmt = mysqli_prepare($conn, $reorderSql);
if ($reorderStmt) {
    mysqli_stmt_bind_param($reorderStmt, 'i', $subjectId);
    mysqli_stmt_execute($reorderStmt);
    $reorderRes = mysqli_stmt_get_result($reorderStmt);
    if ($reorderRes) {
        while ($row = mysqli_fetch_assoc($reorderRes)) {
            $reorderRows[] = $row;
        }
    }
    mysqli_stmt_close($reorderStmt);
}
$totalLessonsAll = count($reorderRows);

$pageTitle = 'Lessons - ' . $subject['subject_name'];
$lessonsListUrl = 'admin_lessons?subject_id=' . (int) $subjectId;
$adminBreadcrumbs = [
    ['Content Hub', 'admin_subjects'],
    [(string) $subject['subject_name'], $lessonsListUrl],
    ['Lessons'],
];
$listViewLabels = [
    'student' => 'Student order',
    'newest' => 'Newest → oldest',
    'oldest' => 'Oldest → newest',
    'title' => 'Title A–Z',
    'title_za' => 'Title Z–A',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-lessons-page" x-data="lessonsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <?php
    $adminHeroIcon = 'file-text';
    $adminHeroEyebrow = 'Content Hub / ' . (string) $subject['subject_name'];
    $adminHeroTitle = 'Lessons';
    $adminHeroSubtitle = 'Manage lessons for ' . (string) $subject['subject_name'] . '.';
    $adminHeroMeta = '<span class="quiz-admin-count-pill quiz-admin-count-pill--lessons">' . (int) $totalLessonsAll . ' lesson' . ((int) $totalLessonsAll === 1 ? '' : 's') . '</span>';
    $adminHeroActions = '';
    if ($totalLessonsAll > 1) {
        $adminHeroActions .= '<button type="button" @click="openReorder()" class="admin-btn admin-btn--secondary"><i class="bi bi-arrows-move"></i> Reorder</button>';
    }
    $adminHeroActions .= '<button type="button" @click="openNewLesson()" class="admin-btn admin-btn--primary"><i class="bi bi-plus-lg"></i> New Lesson</button>';
    include __DIR__ . '/includes/components/admin_page_hero.php';
  ?>

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

  <div class="quiz-admin-table-shell overflow-hidden rounded-2xl border border-white/80 bg-white/75 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(37,99,235,0.05)] backdrop-blur-lg">
    <form method="get" action="admin_lessons" class="admin-sticky-toolbar quiz-admin-filter px-4 py-2.5 flex flex-wrap items-end gap-2 border-b border-slate-100/80 bg-white/60">
      <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
      <div class="flex-1 min-w-[160px]">
        <label for="lessons-search-q" class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-0.5">Search</label>
        <input type="search" id="lessons-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search title or description..." class="input-custom w-full" autocomplete="off">
      </div>
      <div class="min-w-[140px]">
        <label for="lessons-view" class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-0.5">Sort list</label>
        <select id="lessons-view" name="view" class="input-custom w-full">
          <?php foreach ($listViewLabels as $vk => $vl): ?>
            <option value="<?php echo h($vk); ?>" <?php echo $listView === $vk ? 'selected' : ''; ?>><?php echo h($vl); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="min-w-[140px]">
        <label for="lessons-materials" class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-0.5">Materials</label>
        <select id="lessons-materials" name="materials" class="input-custom w-full">
          <option value="all" <?php echo $materialsFilter === 'all' ? 'selected' : ''; ?>>All lessons</option>
          <option value="complete" <?php echo $materialsFilter === 'complete' ? 'selected' : ''; ?>>Has video + handout</option>
          <option value="missing" <?php echo $materialsFilter === 'missing' ? 'selected' : ''; ?>>Missing materials</option>
        </select>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="admin-btn admin-btn--secondary"><i class="bi bi-funnel"></i> Apply</button>
        <?php if ($searchQ !== '' || $listView !== 'student' || $materialsFilter !== 'all'): ?>
          <a href="admin_lessons?subject_id=<?php echo (int)$subjectId; ?>" class="admin-btn admin-btn--secondary">Clear</a>
        <?php endif; ?>
      </div>
      <div class="w-full text-xs text-slate-500">
        <?php if ($totalLessons > 0): ?>
          Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalLessons); ?> of <?php echo $totalLessons; ?>
          <span class="mx-1">·</span>
        <?php endif; ?>
        Subject: <strong class="text-slate-900"><?php echo h($subject['subject_name']); ?></strong>
        <span class="mx-1">·</span>
        List: <strong class="text-slate-900"><?php echo h($listViewLabels[$listView] ?? 'Student order'); ?></strong>
        <?php if ($listView !== 'student'): ?>
          <span class="mx-1">·</span>
          <span class="text-amber-700">Browsing only — student order is unchanged until you use Reorder.</span>
        <?php endif; ?>
      </div>
    </form>
    <div class="overflow-x-auto pl-3 pr-8">
      <table class="quiz-admin-data-table admin-data-table w-full text-left">
        <thead>
          <tr>
            <th class="px-3 py-3 font-semibold text-slate-900 text-center w-[4.5rem] col-desktop">#</th>
            <th class="px-5 py-3 font-semibold text-slate-900 admin-col-primary">Lesson</th>
            <th class="px-5 py-3 font-semibold text-slate-900 text-center col-desktop">Videos</th>
            <th class="px-5 py-3 font-semibold text-slate-900 text-center col-desktop">Handouts</th>
            <th class="px-5 py-3 font-semibold text-slate-900 col-actions w-[220px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false; $rowIndex = $offset; foreach ($lessonRows as $l): $hasAny = true; $rowIndex++;
            $vCnt = (int)($l['videos_cnt'] ?? 0);
            $hCnt = (int)($l['handouts_cnt'] ?? 0);
            $vClass = $vCnt === 0 ? 'lesson-count-pill lesson-count-pill--warn' : 'lesson-count-pill lesson-count-pill--ok';
            $hClass = $hCnt === 0 ? 'lesson-count-pill lesson-count-pill--warn' : 'lesson-count-pill lesson-count-pill--ok';
            $vTitle = $vCnt === 0 ? 'No videos yet - add via Materials' : $vCnt . ' video(s)';
            $hTitle = $hCnt === 0 ? 'No handouts yet - add via Materials' : $hCnt . ' handout(s)';
            $materialsUrl = 'admin_materials?lesson_id=' . (int)$l['lesson_id'] . '&subject_id=' . (int)$subjectId;
            $displayOrd = (int)($l['sort_order'] ?? 0);
            if ($displayOrd <= 0) {
                $displayOrd = $rowIndex;
            }
          ?>
            <tr class="quiz-admin-row">
              <td class="px-3 py-3 text-center col-desktop">
                <span class="content-reorder-ord"><?php echo $displayOrd; ?></span>
              </td>
              <td class="px-5 py-3 admin-col-primary">
                <div class="flex items-start gap-3">
                  <span class="lms-icon-tile bg-sky-50 text-sky-600" style="width:2.25rem;height:2.25rem;border-radius:0.65rem"><i class="bi bi-file-text"></i></span>
                  <div class="min-w-0">
                    <a href="<?php echo h($materialsUrl); ?>" class="lesson-title-link"><?php echo h($l['title']); ?></a>
                    <?php if (!empty($l['description'])): ?>
                      <div class="text-slate-500 text-sm mt-0.5"><?php echo h(mb_strimwidth($l['description'], 0, 90, '...')); ?></div>
                    <?php endif; ?>
                    <span class="lesson-row-mobile-meta"><?php echo $vCnt; ?> video<?php echo $vCnt === 1 ? '' : 's'; ?> · <?php echo $hCnt; ?> handout<?php echo $hCnt === 1 ? '' : 's'; ?></span>
                  </div>
                </div>
              </td>
              <td class="px-5 py-3 text-center col-desktop" title="<?php echo h($vTitle); ?>">
                <span class="inline-flex items-center gap-1.5 rounded-xl border border-sky-100 bg-sky-50/80 px-2.5 py-1.5 text-xs font-bold tabular-nums text-slate-800 <?php echo $vClass; ?>">
                  <i class="bi bi-play-circle text-sky-600"></i> <?php echo $vCnt; ?>
                </span>
              </td>
              <td class="px-5 py-3 text-center col-desktop" title="<?php echo h($hTitle); ?>">
                <span class="inline-flex items-center gap-1.5 rounded-xl border border-violet-100 bg-violet-50/80 px-2.5 py-1.5 text-xs font-bold tabular-nums text-slate-800 <?php echo $hClass; ?>">
                  <i class="bi bi-file-earmark-pdf text-violet-600"></i> <?php echo $hCnt; ?>
                </span>
              </td>
              <td class="px-5 py-3 col-actions">
                <div class="admin-row-actions inline-flex items-center justify-end gap-1.5" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                  <a href="<?php echo h($materialsUrl); ?>" class="hub-next-btn">Materials <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                  <div class="admin-row-menu-wrap">
                    <button type="button" class="admin-row-action admin-row-action--more" :class="menuOpen ? 'is-open' : ''" :aria-expanded="menuOpen" aria-label="More actions" title="More actions" @click.stop="menuOpen = !menuOpen"><i class="bi bi-three-dots"></i></button>
                    <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false" class="admin-row-menu">
                      <button type="button" class="admin-row-menu__item" data-id="<?php echo (int)$l['lesson_id']; ?>" data-title="<?php echo h($l['title'] ?? ''); ?>" data-description="<?php echo h($l['description'] ?? ''); ?>" @click="menuOpen = false; openEditLesson($el.dataset.id, $el.dataset.title || '', $el.dataset.description || '')"><i class="bi bi-pencil"></i> Edit Lesson</button>
                      <div class="admin-row-menu__sep" role="separator"></div>
                      <button type="button" class="admin-row-menu__item admin-row-menu__item--danger" data-id="<?php echo (int)$l['lesson_id']; ?>" data-title="<?php echo h($l['title'] ?? ''); ?>" @click="menuOpen = false; openDeleteLesson($el.dataset.id, $el.dataset.title || '')"><i class="bi bi-trash"></i> Delete Lesson</button>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="5" class="px-5 py-14 text-center quiz-admin-empty">
                <i class="bi bi-inbox text-4xl block mb-3 quiz-admin-empty-icon"></i>
                <div class="font-semibold text-slate-900"><?php echo $searchQ !== '' ? 'No lessons match your search' : 'No lessons yet'; ?></div>
                <p class="text-sm mt-1 text-slate-500"><?php echo $searchQ !== '' ? 'Try different keywords or clear the filter.' : 'Create your first lesson to start uploading videos and handouts.'; ?></p>
                <?php if ($searchQ === ''): ?>
                  <button type="button" @click="openNewLesson()" class="mt-4 px-4 py-2.5 rounded-lg font-semibold admin-content-btn admin-content-btn--lessons border-2 transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Lesson</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <nav class="quiz-admin-pagination px-5 py-4 flex justify-center" aria-label="Lesson pagination">
        <ul class="flex flex-wrap items-center gap-1">
          <?php
            $filterQs = [];
            if ($searchQ !== '') {
                $filterQs['q'] = $searchQ;
            }
            if ($listView !== 'student') {
                $filterQs['view'] = $listView;
            }
            if ($materialsFilter !== 'all') {
                $filterQs['materials'] = $materialsFilter;
            }
            $filterSuffix = $filterQs ? '&' . http_build_query($filterQs) : '';
            $baseUrl = 'admin_lessons?subject_id=' . (int)$subjectId . $filterSuffix;
            $mk = function ($p) use ($baseUrl) { return $baseUrl . ($p > 1 ? '&page=' . $p : ''); };
          ?>
          <?php if ($page > 1): ?>
            <li><a href="<?php echo h($mk($page - 1)); ?>" class="quiz-admin-page-link px-3 py-2 rounded-lg border transition">Previous</a></li>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li>
              <a href="<?php echo h($mk($i)); ?>" class="quiz-admin-page-link px-3 py-2 rounded-lg border transition <?php echo $i === $page ? 'is-active' : ''; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <li><a href="<?php echo h($mk($page + 1)); ?>" class="quiz-admin-page-link px-3 py-2 rounded-lg border transition">Next</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>

  <!-- Reorder Lessons Modal -->
  <div x-show="reorderModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-stretch justify-center p-3 sm:p-5" @keydown.escape.window="reorderModalOpen = false">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="reorderModalOpen = false"></div>
    <div class="relative quiz-modal-panel content-reorder-modal rounded-2xl shadow-modal w-full max-w-6xl border border-white/80 bg-white/95 backdrop-blur-xl" @click.stop>
      <div class="p-4 border-b border-slate-100 flex justify-between items-center quiz-modal-panel__head shrink-0">
        <div>
          <h2 class="text-lg font-bold text-slate-900 m-0">Reorder Lessons</h2>
          <p class="text-sm text-slate-500 mt-1 mb-0">Type a position number or drag. Students see this order after you save.</p>
        </div>
        <button type="button" @click="reorderModalOpen = false" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <?php if ($reorderRows === []): ?>
        <div class="p-8 text-center text-slate-500">No lessons to reorder yet.</div>
      <?php else: ?>
        <form method="POST" action="admin_lessons?subject_id=<?php echo (int)$subjectId; ?>" id="content-reorder-form" class="flex flex-col min-h-0 flex-1">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="reorder">
          <div class="px-5 py-3 border-b border-slate-100 shrink-0 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[220px]">
              <label for="lessons-reorder-filter" class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Find in list</label>
              <input type="search" id="lessons-reorder-filter" data-reorder-filter placeholder="Filter by title…" class="input-custom w-full" autocomplete="off">
            </div>
            <p class="content-reorder-hint m-0 text-sm text-slate-500"><i class="bi bi-123"></i> Type # to jump · <i class="bi bi-grip-vertical"></i> drag optional</p>
          </div>
          <div class="content-reorder-scroll px-3 py-2">
            <p data-reorder-filter-empty hidden class="text-center text-sm text-slate-500 py-6">No lessons match this filter.</p>
            <table class="quiz-admin-data-table admin-data-table content-reorder-table w-full text-left">
              <thead>
                <tr>
                  <th class="px-3 py-3 font-semibold content-reorder-col-handle" aria-label="Drag"></th>
                  <th class="px-3 py-3 font-semibold content-reorder-col-ord">#</th>
                  <th class="px-5 py-3 font-semibold admin-col-primary">Lesson</th>
                  <th class="px-5 py-3 font-semibold text-center">Videos</th>
                  <th class="px-5 py-3 font-semibold text-center">Handouts</th>
                </tr>
              </thead>
              <tbody id="content-reorder-list">
                <?php foreach ($reorderRows as $idx => $l): ?>
                  <tr class="quiz-admin-row" draggable="true" data-id="<?php echo (int)$l['lesson_id']; ?>" data-search="<?php echo h(strtolower(($l['title'] ?? '') . ' ' . ($l['description'] ?? ''))); ?>">
                    <td class="px-3 py-3 content-reorder-col-handle">
                      <span class="content-reorder-handle" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                      <input type="hidden" name="ordered_ids[]" value="<?php echo (int)$l['lesson_id']; ?>">
                    </td>
                    <td class="px-3 py-3 content-reorder-col-ord">
                      <label class="sr-only" for="lesson-pos-<?php echo (int)$l['lesson_id']; ?>">Position</label>
                      <input
                        id="lesson-pos-<?php echo (int)$l['lesson_id']; ?>"
                        type="number"
                        class="content-reorder-pos"
                        data-order-pos
                        min="1"
                        max="<?php echo count($reorderRows); ?>"
                        step="1"
                        value="<?php echo (int)$idx + 1; ?>"
                        inputmode="numeric"
                        title="Type position (1–<?php echo count($reorderRows); ?>)"
                      >
                    </td>
                    <td class="px-5 py-3 admin-col-primary">
                      <div class="font-semibold"><?php echo h($l['title']); ?></div>
                      <?php if (!empty($l['description'])): ?>
                        <div class="text-gray-500 text-sm mt-0.5"><?php echo h(mb_strimwidth($l['description'], 0, 90, '...')); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-center tabular-nums"><?php echo (int)($l['videos_cnt'] ?? 0); ?></td>
                    <td class="px-5 py-3 text-center tabular-nums"><?php echo (int)($l['handouts_cnt'] ?? 0); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="p-4 border-t border-slate-100 flex justify-between items-center gap-2 shrink-0">
            <p class="text-sm text-slate-500 m-0"><?php echo count($reorderRows); ?> lesson<?php echo count($reorderRows) === 1 ? '' : 's'; ?> · scroll the list · Enter after typing a #</p>
            <div class="flex gap-2">
              <button type="button" @click="reorderModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
              <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-save"></i> Save Order</button>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Create/Edit Lesson Modal -->
  <div x-show="lessonModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="if (!reorderModalOpen) lessonModalOpen = false">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="lessonModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-2xl shadow-modal max-w-lg w-full max-h-[90vh] overflow-y-auto border border-white/80 bg-white/95 backdrop-blur-xl" @click.stop>
      <div class="p-4 border-b border-slate-100 flex justify-between items-center quiz-modal-panel__head">
        <h2 class="text-lg font-bold text-slate-900 m-0" x-text="isEdit ? 'Edit Lesson' : 'New Lesson'"></h2>
        <button type="button" @click="lessonModalOpen = false" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_lessons?subject_id=<?php echo (int)$subjectId; ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="lesson_id" :value="lesson_id">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Title</label>
            <input type="text" name="title" x-model="title" required placeholder="e.g., Lesson 1: Introduction" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
            <textarea name="description" x-model="description" rows="4" placeholder="Optional summary, outline, or notes" class="input-custom"></textarea>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="lessonModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
          <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Create'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Lesson Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="deleteModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-2xl shadow-modal max-w-md w-full p-5 border border-white/80 bg-white/95 backdrop-blur-xl" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-bold text-slate-900 m-0"><i class="bi bi-trash text-rose-500 mr-2"></i> Delete Lesson</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_lessons?subject_id=<?php echo (int)$subjectId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="lesson_id" :value="delete_lesson_id">
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 mb-4">
          <div class="font-semibold">This will delete the lesson and related materials.</div>
          <div class="text-sm mt-1 text-rose-700">Lesson: <span class="font-semibold" x-text="delete_lesson_title"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-rose-600 text-white hover:bg-rose-700 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script src="assets/js/admin-content-reorder.js?v=3"></script>
  <script>
    function lessonsApp() {
      return {
        lessonModalOpen: false,
        deleteModalOpen: false,
        reorderModalOpen: false,
        isEdit: false,
        lesson_id: 0,
        title: '',
        description: '',
        delete_lesson_id: 0,
        delete_lesson_title: '',
        editFromServer: <?php echo !empty($edit) ? json_encode(['id' => (int)$edit['lesson_id'], 'title' => $edit['title'] ?? '', 'description' => $edit['description'] ?? '']) : 'null'; ?>,
        openNewLesson() {
          this.isEdit = false;
          this.lesson_id = 0;
          this.title = '';
          this.description = '';
          this.lessonModalOpen = true;
        },
        openEditLesson(id, title, description) {
          this.isEdit = true;
          this.lesson_id = id;
          this.title = title || '';
          this.description = description || '';
          this.lessonModalOpen = true;
        },
        openDeleteLesson(id, title) {
          this.delete_lesson_id = id;
          this.delete_lesson_title = title || '';
          this.deleteModalOpen = true;
        },
        openReorder() {
          this.reorderModalOpen = true;
          this.$nextTick(function () {
            var form = document.getElementById('content-reorder-form');
            if (window.AdminContentReorder && form) {
              window.AdminContentReorder.clearFilter(form);
              window.AdminContentReorder.init(form);
            }
          });
        },
        initEditFromServer() {
          if (this.editFromServer) this.openEditLesson(this.editFromServer.id, this.editFromServer.title, this.editFromServer.description);
        }
      };
    }
  </script>
</div>
</main>
</body>
</html>
