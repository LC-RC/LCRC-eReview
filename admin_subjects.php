<?php
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/profile_avatar.php';

/**
 * Remove saved subject cover files from disk (all extensions for this id).
 */
function ereview_subject_cover_delete_files(int $subjectId): void
{
    if ($subjectId <= 0) {
        return;
    }
    $dir = __DIR__ . '/uploads/subject_covers';
    foreach (glob($dir . '/subject_' . $subjectId . '.*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/**
 * Validate and store an uploaded subject cover. Deletes any previous file for this subject.
 *
 * @return string|null Error message, or null on success
 */
function ereview_apply_subject_cover_upload(mysqli $conn, int $subjectId, array $file): ?string
{
    if ($subjectId <= 0) {
        return 'Invalid subject.';
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return 'Cover image must be 5 MB or smaller.';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '') {
        return 'Invalid upload.';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extMap[$mime])) {
        return 'Use JPG, PNG, WebP, or GIF for the cover image.';
    }
    $dir = __DIR__ . '/uploads/subject_covers';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return 'Could not create cover upload directory.';
    }
    ereview_subject_cover_delete_files($subjectId);
    $ext = $extMap[$mime];
    $rel = 'uploads/subject_covers/subject_' . $subjectId . '.' . $ext;
    $dest = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!@move_uploaded_file($tmp, $dest)) {
        return 'Could not save cover image.';
    }
    $up = mysqli_prepare($conn, 'UPDATE subjects SET subject_cover = ? WHERE subject_id = ? LIMIT 1');
    if (!$up) {
        return 'Could not update cover path.';
    }
    mysqli_stmt_bind_param($up, 'si', $rel, $subjectId);
    mysqli_stmt_execute($up);
    mysqli_stmt_close($up);

    return null;
}

$hasSubjectCover = false;
$__scCol = @mysqli_query($conn, "SHOW COLUMNS FROM subjects LIKE 'subject_cover'");
if ($__scCol && mysqli_fetch_assoc($__scCol)) {
    $hasSubjectCover = true;
}
if ($__scCol) {
    mysqli_free_result($__scCol);
}

$csrf = generateCSRFToken();

// Filters / pagination
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all'; // all|active|inactive
$page = sanitizeInt($_GET['page'] ?? 1, 1);
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Create / Update / Delete (POST only, CSRF protected)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_subjects');
        exit;
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $delId = sanitizeInt($_POST['subject_id'] ?? 0);
        if ($delId > 0) {
            ereview_subject_cover_delete_files($delId);
            $stmt = mysqli_prepare($conn, 'DELETE FROM subjects WHERE subject_id=?');
            mysqli_stmt_bind_param($stmt, 'i', $delId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Subject deleted.';
        }
        header('Location: admin_subjects');
        exit;
    }

    // Save
    $subjectId = sanitizeInt($_POST['subject_id'] ?? 0);
    $name = trim($_POST['subject_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';

    $allowedSubjects = ['FAR', 'AFAR', 'TAX', 'RFBT', 'MAS', 'AUD PROB', 'AUD THEORIES'];

    if ($name === '' || !in_array($name, $allowedSubjects, true)) {
        $_SESSION['error'] = 'Please select a valid subject.';
        header('Location: admin_subjects' . ($subjectId > 0 ? ('?edit=' . $subjectId) : ''));
        exit;
    }

    // Prevent duplicate subject names (case-insensitive)
    $dupStmt = mysqli_prepare(
        $conn,
        "SELECT subject_id FROM subjects WHERE LOWER(subject_name) = LOWER(?) AND subject_id <> ? LIMIT 1"
    );
    mysqli_stmt_bind_param($dupStmt, 'si', $name, $subjectId);
    mysqli_stmt_execute($dupStmt);
    $dupRes = mysqli_stmt_get_result($dupStmt);
    $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
    mysqli_stmt_close($dupStmt);
    if ($dupRow) {
        $_SESSION['error'] = 'This subject already exists.';
        header('Location: admin_subjects' . ($subjectId > 0 ? ('?edit=' . $subjectId) : ''));
        exit;
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    if ($subjectId > 0) {
        $stmt = mysqli_prepare($conn, 'UPDATE subjects SET subject_name=?, description=?, status=? WHERE subject_id=?');
        mysqli_stmt_bind_param($stmt, 'sssi', $name, $desc, $status, $subjectId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Subject updated.';
        if ($hasSubjectCover) {
            $removeCover = !empty($_POST['subject_cover_remove']);
            $fileErr = (int)(($_FILES['subject_cover'] ?? [])['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($removeCover) {
                ereview_subject_cover_delete_files($subjectId);
                $clr = mysqli_prepare($conn, 'UPDATE subjects SET subject_cover = NULL WHERE subject_id = ? LIMIT 1');
                if ($clr) {
                    mysqli_stmt_bind_param($clr, 'i', $subjectId);
                    mysqli_stmt_execute($clr);
                    mysqli_stmt_close($clr);
                }
            } elseif ($fileErr === UPLOAD_ERR_OK) {
                $err = ereview_apply_subject_cover_upload($conn, $subjectId, $_FILES['subject_cover']);
                if ($err !== null) {
                    unset($_SESSION['message']);
                    $_SESSION['error'] = $err;
                }
            }
        }
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO subjects (subject_name, description, status) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'sss', $name, $desc, $status);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $newId = (int)mysqli_insert_id($conn);
        $_SESSION['message'] = 'Subject created.';
        if ($hasSubjectCover && $newId > 0) {
            $fileErr = (int)(($_FILES['subject_cover'] ?? [])['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($fileErr === UPLOAD_ERR_OK) {
                $err = ereview_apply_subject_cover_upload($conn, $newId, $_FILES['subject_cover']);
                if ($err !== null) {
                    unset($_SESSION['message']);
                    $_SESSION['error'] = $err;
                }
            }
        }
    }

    header('Location: admin_subjects');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $eid);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
}

// Count for pagination
$like = '%' . $q . '%';
if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM subjects WHERE subject_name LIKE ? AND status=?");
    mysqli_stmt_bind_param($stmt, 'ss', $like, $statusFilter);
} else {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM subjects WHERE subject_name LIKE ?");
    mysqli_stmt_bind_param($stmt, 's', $like);
}
mysqli_stmt_execute($stmt);
$countRes = mysqli_stmt_get_result($stmt);
$countRow = mysqli_fetch_assoc($countRes);
$total = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
mysqli_stmt_close($stmt);

// Subjects list with counts
if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $stmt = mysqli_prepare($conn, "
        SELECT 
          s.*,
          (SELECT COUNT(*) FROM lessons l WHERE l.subject_id=s.subject_id) AS lessons_cnt,
          (SELECT COUNT(*) FROM quizzes qz WHERE qz.subject_id=s.subject_id) AS quizzes_cnt
        FROM subjects s
        WHERE s.subject_name LIKE ? AND s.status=?
        ORDER BY s.subject_name ASC
        LIMIT ? OFFSET ?
    ");
    mysqli_stmt_bind_param($stmt, 'ssii', $like, $statusFilter, $perPage, $offset);
} else {
    $stmt = mysqli_prepare($conn, "
        SELECT 
          s.*,
          (SELECT COUNT(*) FROM lessons l WHERE l.subject_id=s.subject_id) AS lessons_cnt,
          (SELECT COUNT(*) FROM quizzes qz WHERE qz.subject_id=s.subject_id) AS quizzes_cnt
        FROM subjects s
        WHERE s.subject_name LIKE ?
        ORDER BY s.subject_name ASC
        LIMIT ? OFFSET ?
    ");
    mysqli_stmt_bind_param($stmt, 'sii', $like, $perPage, $offset);
}
mysqli_stmt_execute($stmt);
$subjects = mysqli_stmt_get_result($stmt);
$hasUpdatedAt = false;
if ($subjects) {
    foreach (mysqli_fetch_fields($subjects) ?: [] as $field) {
        if (($field->name ?? '') === 'updated_at') {
            $hasUpdatedAt = true;
            break;
        }
    }
}

$pageTitle = 'Content Hub';
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard'], ['Content Hub'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    body.admin-subjects-page .admin-modal-overlay { z-index: 1400; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-subjects-page" x-data="adminSubjectsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <?php
    $adminHeroIcon = 'book';
    $adminHeroEyebrow = 'Content management';
    $adminHeroTitle = 'Content Hub';
    $adminHeroSubtitle = 'Manage subjects and their learning content.';
    $adminHeroMeta = '<span class="quiz-admin-count-pill">' . (int) $total . ' subject' . ((int) $total === 1 ? '' : 's') . '</span>';
    $adminHeroActions = '<button type="button" class="admin-btn admin-btn--primary inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-semibold" @click="openNewSubject()"><i class="bi bi-plus-lg"></i> New Subject</button>';
    include __DIR__ . '/includes/components/admin_page_hero.php';
  ?>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-flash admin-flash--success mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-check-circle-fill"></i>
      <span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-flash admin-flash--error mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <section class="content-library">
    <div class="content-library__head">
      <div>
        <h2 class="content-library__title">Subjects</h2>
        <p class="content-library__sub">Each subject holds lessons, quizzes, and a test bank.</p>
      </div>
      <span class="content-library__count"><?php echo (int) $total; ?> subject<?php echo (int) $total === 1 ? '' : 's'; ?></span>
    </div>

    <form method="GET" class="content-library__toolbar">
      <div class="content-library__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search subjects..." class="input-custom" aria-label="Search subjects">
      </div>
      <div class="content-library__status">
        <label class="sr-only" for="content-library-status">Status</label>
        <select id="content-library-status" name="status" class="input-custom">
          <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All status</option>
          <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
      </div>
      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>
      <p class="content-library__meta">
        Showing <?php echo $total ? ($offset + 1) : 0; ?>–<?php echo min($offset + $perPage, $total); ?> of <?php echo (int)$total; ?> subjects
      </p>
    </form>

    <?php if ($total === 0): ?>
      <div class="content-library-empty">
        <i class="bi bi-book" aria-hidden="true"></i>
        <h3><?php echo ($q !== '' || ($statusFilter !== 'all')) ? 'No subjects found' : 'No subjects yet'; ?></h3>
        <p class="content-library__sub"><?php echo ($q !== '' || ($statusFilter !== 'all')) ? 'Try clearing filters or create a new subject.' : 'Create your first subject to begin adding lessons, materials and quizzes.'; ?></p>
        <button type="button" @click="openNewSubject()" class="admin-btn admin-btn--primary mt-3">
          <i class="bi bi-plus-lg"></i> New Subject
        </button>
      </div>
    <?php else: ?>
      <div class="content-library-table-scroll">
        <table class="content-library-table">
          <thead>
            <tr>
              <th scope="col">Subject</th>
              <th class="col-desktop" scope="col">Lessons</th>
              <th class="col-desktop" scope="col">Quizzes</th>
              <th class="col-desktop" scope="col">Status</th>
              <?php if ($hasUpdatedAt): ?><th class="col-desktop" scope="col">Last updated</th><?php endif; ?>
              <th class="col-actions" scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($s = mysqli_fetch_assoc($subjects)): ?>
              <?php
                $rowCoverSrc = '';
                if ($hasSubjectCover && !empty($s['subject_cover'])) {
                    $rowCoverSrc = ereview_avatar_img_src((string)$s['subject_cover']);
                }
                $lessonsCnt = (int)($s['lessons_cnt'] ?? 0);
                $quizzesCnt = (int)($s['quizzes_cnt'] ?? 0);
                $manageUrl = 'admin_lessons?subject_id=' . (int)$s['subject_id'];
                $stLower = strtolower((string)($s['status'] ?? ''));
                $updatedLabel = '';
                if ($hasUpdatedAt && !empty($s['updated_at'])) {
                    $ts = strtotime((string)$s['updated_at']);
                    $updatedLabel = $ts ? date('M j, Y', $ts) : (string)$s['updated_at'];
                }
              ?>
              <tr>
                <td>
                  <div class="content-library-subject">
                    <?php if ($rowCoverSrc !== ''): ?>
                      <img src="<?php echo h($rowCoverSrc); ?>" alt="" class="lms-icon-tile h-10 w-10 rounded-xl object-cover shrink-0" width="40" height="40">
                    <?php else: ?>
                      <span class="lms-icon-tile inline-flex h-10 w-10 items-center justify-center rounded-xl"><i class="bi bi-book"></i></span>
                    <?php endif; ?>
                    <span class="content-library-subject__text">
                      <a href="<?php echo h($manageUrl); ?>" class="content-library-subject__name"><?php echo h($s['subject_name']); ?></a>
                      <?php if (!empty($s['description'])): ?>
                        <span class="content-library-subject__desc" title="<?php echo h($s['description']); ?>"><?php echo h(mb_strimwidth($s['description'], 0, 90, '...')); ?></span>
                      <?php endif; ?>
                      <span class="content-library-subject__mobile-meta">
                        <?php echo $lessonsCnt; ?> lesson<?php echo $lessonsCnt === 1 ? '' : 's'; ?>
                        · <?php echo $quizzesCnt; ?> quiz<?php echo $quizzesCnt === 1 ? '' : 'zes'; ?>
                        · <?php echo h($s['status']); ?>
                      </span>
                    </span>
                  </div>
                </td>
                <td class="col-desktop">
                  <span class="content-library-metric" title="<?php echo $lessonsCnt; ?> lesson(s)">
                    <?php echo $lessonsCnt; ?>
                    <span><?php echo $lessonsCnt === 1 ? 'lesson' : 'lessons'; ?></span>
                  </span>
                </td>
                <td class="col-desktop">
                  <span class="content-library-metric" title="<?php echo $quizzesCnt; ?> quiz(zes)">
                    <?php echo $quizzesCnt; ?>
                    <span><?php echo $quizzesCnt === 1 ? 'quiz' : 'quizzes'; ?></span>
                  </span>
                </td>
                <td class="col-desktop">
                  <span class="admin-status-pill admin-status-pill--<?php echo $stLower === 'active' ? 'active' : 'inactive'; ?>"><?php echo h($s['status']); ?></span>
                </td>
                <?php if ($hasUpdatedAt): ?>
                  <td class="col-desktop"><?php echo $updatedLabel !== '' ? h($updatedLabel) : '—'; ?></td>
                <?php endif; ?>
                <td class="col-actions">
                  <div class="admin-row-actions inline-flex items-center justify-end gap-1.5" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                    <a href="<?php echo h($manageUrl); ?>" class="hub-next-btn">Lessons <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    <div class="admin-row-menu-wrap">
                      <button type="button" class="admin-row-action admin-row-action--more inline-flex h-9 w-9 items-center justify-center rounded-lg" :class="menuOpen ? 'is-open' : ''" :aria-expanded="menuOpen" aria-label="More actions" title="More actions" @click.stop="menuOpen = !menuOpen"><i class="bi bi-three-dots"></i></button>
                      <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false" class="admin-row-menu">
                        <a href="admin_quizzes?subject_id=<?php echo (int)$s['subject_id']; ?>" class="admin-row-menu__item no-underline"><i class="bi bi-question-circle"></i> Quizzes</a>
                        <a href="admin_test_bank?subject_id=<?php echo (int)$s['subject_id']; ?>" class="admin-row-menu__item no-underline"><i class="bi bi-folder2-open"></i> Test Bank</a>
                        <div class="admin-row-menu__sep" role="separator"></div>
                        <button type="button"
                                class="admin-row-menu__item"
                                data-id="<?php echo (int)$s['subject_id']; ?>"
                                data-name="<?php echo h($s['subject_name'] ?? ''); ?>"
                                data-description="<?php echo h($s['description'] ?? ''); ?>"
                                data-status="<?php echo h($s['status'] ?? 'active'); ?>"
                                data-cover-src="<?php echo h($rowCoverSrc); ?>"
                                @click="menuOpen = false; openEditSubject($el.dataset.id, $el.dataset.name || '', $el.dataset.description || '', $el.dataset.status || 'active', $el.dataset.coverSrc || '')">
                          <i class="bi bi-pencil"></i> Edit Subject
                        </button>
                        <div class="admin-row-menu__sep" role="separator"></div>
                        <button type="button"
                                class="admin-row-menu__item admin-row-menu__item--danger"
                                data-id="<?php echo (int)$s['subject_id']; ?>"
                                data-name="<?php echo h($s['subject_name'] ?? ''); ?>"
                                @click="menuOpen = false; openDeleteSubject($el.dataset.id, $el.dataset.name || '')">
                          <i class="bi bi-trash"></i> Delete Subject
                        </button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php mysqli_stmt_close($stmt); ?>
    <?php if ($totalPages > 1): ?>
      <nav class="content-library-pager" aria-label="Subject pagination">
        <ul class="flex flex-wrap items-center justify-center gap-1">
          <?php
            $baseParams = ['q' => $q, 'status' => $statusFilter];
            $mk = function ($p) use ($baseParams) {
              $params = $baseParams;
              $params['page'] = $p;
              return 'admin_subjects?' . http_build_query($params);
            };
          ?>
          <?php if ($page > 1): ?>
            <li><a href="<?php echo h($mk($page - 1)); ?>" class="admin-btn admin-btn--secondary admin-btn--sm">Previous</a></li>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li>
              <a href="<?php echo h($mk($i)); ?>" class="admin-btn admin-btn--sm <?php echo $i === $page ? 'admin-btn--primary' : 'admin-btn--secondary'; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <li><a href="<?php echo h($mk($page + 1)); ?>" class="admin-btn admin-btn--secondary admin-btn--sm">Next</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </section>

  <div class="admin-modal-overlay" :class="{ 'is-open': subjectModalOpen }" x-show="subjectModalOpen" x-cloak x-teleport="body" role="dialog" aria-modal="true" aria-labelledby="subjectModalTitle" @keydown.escape.window="subjectModalOpen = false" @click.self="subjectModalOpen = false">
    <section class="quiz-modal-panel admin-modal" @click.stop>
      <header class="quiz-modal-panel__head p-4 flex justify-between items-center">
        <h2 id="subjectModalTitle" class="text-lg font-bold m-0 flex items-center gap-2"><i class="bi bi-bookmark-plus"></i><span x-text="isEdit ? 'Edit Subject' : 'New Subject'"></span></h2>
        <button type="button" @click="subjectModalOpen = false" class="p-2 rounded-lg" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </header>
      <form method="POST" action="admin_subjects" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="subject_id" :value="subject_id">

        <div class="admin-modal-form__body p-5 space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Subject</label>
            <select name="subject_name" x-model="subject_name" required class="input-custom">
              <option value="" disabled>Select subject</option>
              <option value="FAR">FAR</option>
              <option value="AFAR">AFAR</option>
              <option value="TAX">TAX</option>
              <option value="RFBT">RFBT</option>
              <option value="MAS">MAS</option>
              <option value="AUD PROB">AUD PROB</option>
              <option value="AUD THEORIES">AUD THEORIES</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Description</label>
            <textarea name="description" x-model="description" rows="4" placeholder="Optional notes for this subject" class="input-custom"></textarea>
          </div>
          <?php if ($hasSubjectCover): ?>
          <div class="rounded-xl border border-dashed p-4 space-y-3" style="border-color: var(--glass-border); background: var(--glass-surface-inner);">
            <div>
              <label class="block text-sm font-semibold mb-0.5">Card cover image</label>
              <p class="text-xs m-0" style="color: var(--text-secondary);">Shown as the top banner on each subject card for students. JPG, PNG, WebP, or GIF · max 5 MB · about 1200×480 or wider works best.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <div class="relative w-full max-w-[280px] aspect-[5/2] rounded-lg overflow-hidden border" style="border-color: var(--glass-border); background: linear-gradient(135deg, #2563eb, #4f46e5);">
                <img x-show="coverPreview || existing_cover_src" :src="coverPreview || existing_cover_src" alt="" class="absolute inset-0 w-full h-full object-cover">
                <div x-show="!coverPreview && !existing_cover_src" class="absolute inset-0 flex items-center justify-center text-white/90 text-xs font-semibold px-3 text-center">No cover yet - students see a default blue banner</div>
              </div>
              <div class="flex-1 min-w-[12rem] space-y-2">
                <input type="file" name="subject_cover" accept="image/jpeg,image/png,image/webp,image/gif" class="block w-full text-sm file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 cursor-pointer" x-ref="coverFileInput" @change="
                  cover_remove = false;
                  const inp = $refs.coverFileInput;
                  const f = inp && inp.files && inp.files[0];
                  if (!f) { coverPreview = ''; return; }
                  const r = new FileReader();
                  r.onload = () => { coverPreview = r.result || ''; };
                  r.readAsDataURL(f);
                ">
                <label x-show="isEdit && (existing_cover_src || coverPreview)" class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                  <input type="checkbox" name="subject_cover_remove" value="1" x-model="cover_remove" @change="if (cover_remove) { coverPreview = ''; if ($refs.coverFileInput) $refs.coverFileInput.value = ''; }" class="rounded">
                  <span>Remove cover from this subject</span>
                </label>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <div x-show="isEdit">
            <label class="block text-sm font-medium mb-1">Status</label>
            <select name="status" x-model="status" class="input-custom">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <input type="hidden" name="status" x-bind:value="status" x-show="!isEdit">
          <p class="text-sm m-0" style="color: var(--text-secondary);">Inactive subjects won't appear to students.</p>
        </div>
        <div class="admin-modal__actions p-4 flex justify-end gap-2">
          <button type="button" @click="subjectModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
          <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Create'"></span></button>
        </div>
      </form>
    </section>
  </div>

  <div class="admin-modal-overlay" :class="{ 'is-open': deleteModalOpen }" x-show="deleteModalOpen" x-cloak x-teleport="body" role="dialog" aria-modal="true" aria-labelledby="deleteSubjectTitle" @keydown.escape.window="deleteModalOpen = false" @click.self="deleteModalOpen = false">
    <section class="quiz-modal-panel admin-modal" @click.stop>
      <header class="quiz-modal-panel__head p-4 flex justify-between items-center">
        <h2 id="deleteSubjectTitle" class="text-lg font-bold m-0"><i class="bi bi-trash mr-2"></i> Delete Subject</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </header>
      <form method="POST" action="admin_subjects">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="subject_id" :value="delete_id">
        <div class="admin-modal-form__body p-5">
          <div class="p-4 rounded-xl" style="background: color-mix(in srgb, #ef4444 12%, transparent); border: 1px solid color-mix(in srgb, #ef4444 35%, transparent); color: var(--text-primary);">
            <div class="font-semibold">This will delete the subject and related lessons/quizzes.</div>
            <div class="text-sm mt-1">Subject: <span class="font-semibold" x-text="delete_name"></span></div>
          </div>
        </div>
        <div class="admin-modal__actions p-4 flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="admin-btn admin-btn--secondary">Cancel</button>
          <button type="submit" class="admin-btn admin-btn--primary" style="background:#e11d48;border-color:#e11d48;"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </section>
  </div>

  <script>
    function adminSubjectsApp() {
      return {
        subjectModalOpen: false,
        deleteModalOpen: false,
        isEdit: false,
        subject_id: 0,
        subject_name: '',
        description: '',
        status: 'active',
        existing_cover_src: '',
        coverPreview: '',
        cover_remove: false,
        delete_id: 0,
        delete_name: '',
        editFromServer: <?php
          $editJson = null;
          if (!empty($edit)) {
              $editCoverSrc = '';
              if ($hasSubjectCover && !empty($edit['subject_cover'])) {
                  $editCoverSrc = ereview_avatar_img_src((string)$edit['subject_cover']);
              }
              $editJson = [
                  'id' => (int)$edit['subject_id'],
                  'name' => $edit['subject_name'] ?? '',
                  'description' => $edit['description'] ?? '',
                  'status' => $edit['status'] ?? 'active',
                  'coverSrc' => $editCoverSrc,
              ];
          }
          echo json_encode($editJson);
        ?>,

        openNewSubject() {
          this.isEdit = false;
          this.subject_id = 0;
          this.subject_name = '';
          this.description = '';
          this.status = 'active';
          this.existing_cover_src = '';
          this.coverPreview = '';
          this.cover_remove = false;
          this.$nextTick(() => { if (this.$refs.coverFileInput) this.$refs.coverFileInput.value = ''; });
          this.subjectModalOpen = true;
        },
        openEditSubject(id, name, description, status, coverSrc) {
          this.isEdit = true;
          this.subject_id = id;
          this.subject_name = name || '';
          this.description = description || '';
          this.status = (status === 'inactive') ? 'inactive' : 'active';
          this.existing_cover_src = coverSrc || '';
          this.coverPreview = '';
          this.cover_remove = false;
          this.$nextTick(() => { if (this.$refs.coverFileInput) this.$refs.coverFileInput.value = ''; });
          this.subjectModalOpen = true;
        },
        openDeleteSubject(id, name) {
          this.delete_id = id;
          this.delete_name = name || '';
          this.deleteModalOpen = true;
        },
        initEditFromServer() {
          if (this.editFromServer) {
            this.openEditSubject(
              this.editFromServer.id,
              this.editFromServer.name,
              this.editFromServer.description,
              this.editFromServer.status,
              this.editFromServer.coverSrc || ''
            );
          }
        }
      };
    }
  </script>
</div>
</main>
</body>
</html>
