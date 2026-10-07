<?php
require_once 'auth.php';
requireAdminPage('quizzes');
require_once __DIR__ . '/includes/quiz_admin_reports.php';
require_once __DIR__ . '/includes/student_activity.php';
require_once __DIR__ . '/includes/schema_introspection.php';

student_activity_ensure_schema($conn);

$subjects = quiz_admin_list_subjects($conn);
$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0 && !empty($subjects)) {
    $subjectId = (int) ($subjects[0]['subject_id'] ?? 0);
}

$subject = null;
foreach ($subjects as $s) {
    if ((int) $s['subject_id'] === $subjectId) {
        $subject = $s;
        break;
    }
}

$quizId = sanitizeInt($_GET['quiz_id'] ?? 0);
$searchQ = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'submitted');
if (!in_array($statusFilter, ['all', 'submitted', 'in_progress', 'expired'], true)) {
    $statusFilter = 'submitted';
}
$page = sanitizeInt($_GET['page'] ?? 1, 1);
$perPage = 25;

$quizzes = $subjectId > 0 ? quiz_admin_list_quizzes($conn, $subjectId) : [];
if ($quizId > 0) {
    $ok = false;
    foreach ($quizzes as $qz) {
        if ((int) $qz['quiz_id'] === $quizId) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $quizId = 0;
    }
}

$attemptData = ['rows' => [], 'total' => 0];
$stats = ['attempts' => 0, 'submitted' => 0, 'students' => 0, 'avg_score' => null, 'in_progress' => 0];
$totalAttempts = 0;

if (ereview_schema_table_exists($conn, 'quiz_attempts') && $subjectId > 0) {
    $attemptData = quiz_admin_fetch_attempts($conn, [
        'subject_id' => $subjectId,
        'quiz_id' => $quizId,
        'q' => $searchQ,
        'status' => $statusFilter,
        'page' => $page,
        'per_page' => $perPage,
    ]);
    $totalAttempts = (int) ($attemptData['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalAttempts / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $attemptData = quiz_admin_fetch_attempts($conn, [
            'subject_id' => $subjectId,
            'quiz_id' => $quizId,
            'q' => $searchQ,
            'status' => $statusFilter,
            'page' => $page,
            'per_page' => $perPage,
        ]);
        $totalAttempts = (int) ($attemptData['total'] ?? 0);
    }

    $statWhere = ['s.subject_id = ?'];
    $statTypes = 'i';
    $statParams = [$subjectId];
    if ($quizId > 0) {
        $statWhere[] = 'a.quiz_id = ?';
        $statTypes .= 'i';
        $statParams[] = $quizId;
    }
    $statSql = 'SELECT COUNT(*) AS attempts,
        SUM(CASE WHEN a.status = \'submitted\' THEN 1 ELSE 0 END) AS submitted,
        SUM(CASE WHEN a.status = \'in_progress\' THEN 1 ELSE 0 END) AS in_progress,
        COUNT(DISTINCT a.user_id) AS students,
        AVG(CASE WHEN a.status = \'submitted\' THEN a.score ELSE NULL END) AS avg_score
      FROM quiz_attempts a
      INNER JOIN quizzes q ON q.quiz_id = a.quiz_id
      INNER JOIN subjects s ON s.subject_id = q.subject_id
      WHERE ' . implode(' AND ', $statWhere);
    $stmt = mysqli_prepare($conn, $statSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $statTypes, ...$statParams);
        mysqli_stmt_execute($stmt);
        $statRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($statRow) {
            $stats['attempts'] = (int) ($statRow['attempts'] ?? 0);
            $stats['submitted'] = (int) ($statRow['submitted'] ?? 0);
            $stats['in_progress'] = (int) ($statRow['in_progress'] ?? 0);
            $stats['students'] = (int) ($statRow['students'] ?? 0);
            $stats['avg_score'] = isset($statRow['avg_score']) ? (float) $statRow['avg_score'] : null;
        }
    }
}

$totalPages = max(1, (int) ceil($totalAttempts / $perPage));
$mkUrl = static function (array $overrides = []) use ($subjectId, $quizId, $searchQ, $statusFilter, $page) {
    $merged = [
        'subject_id' => $subjectId > 0 ? $subjectId : null,
        'quiz_id' => $quizId > 0 ? $quizId : null,
        'q' => $searchQ !== '' ? $searchQ : null,
        'status' => $statusFilter !== 'submitted' ? $statusFilter : null,
        'page' => $page > 1 ? $page : null,
    ];
    foreach ($overrides as $k => $v) {
        $merged[$k] = $v;
    }
    $params = [];
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '' || $v === false) {
            continue;
        }
        if ($k === 'quiz_id' && (int) $v <= 0) {
            continue;
        }
        if ($k === 'page' && (int) $v <= 1) {
            continue;
        }
        if ($k === 'status' && (string) $v === 'submitted') {
            continue;
        }
        $params[$k] = $v;
    }
    $qs = http_build_query($params);

    return 'admin_quiz_monitor' . ($qs !== '' ? '?' . $qs : '');
};
$quizChipTitle = '';
if ($quizId > 0) {
    foreach ($quizzes as $qzChip) {
        if ((int) ($qzChip['quiz_id'] ?? 0) === $quizId) {
            $quizChipTitle = (string) ($qzChip['title'] ?? '');
            break;
        }
    }
}
$statusChipLabels = [
    'all' => 'All',
    'submitted' => 'Submitted',
    'in_progress' => 'In Progress',
    'expired' => 'Expired',
];
$statusChipLabel = $statusChipLabels[$statusFilter] ?? $statusFilter;
$showFrom = $totalAttempts === 0 ? 0 : (($page - 1) * $perPage) + 1;
$showTo = min($page * $perPage, $totalAttempts);
$resetUrl = $mkUrl(['quiz_id' => null, 'q' => null, 'status' => null, 'page' => null]);

$subjectName = $subject['subject_name'] ?? 'Quizzes';
$pageTitle = 'Quiz Monitoring - ' . $subjectName;
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Quiz Monitor'],
];
$adminHeroIcon = 'clipboard-data';
$adminHeroEyebrow = 'Quiz management';
$adminHeroTitle = 'Quiz Monitoring';
$adminHeroSubtitle = 'Assessment monitoring console — attempts, scores, accuracy, and live in-progress sessions.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-quiz-monitor-page">
  <?php include 'admin_sidebar.php'; ?>
  <?php
    include __DIR__ . '/includes/components/admin_page_hero.php';
  ?>

  <?php if (!ereview_schema_table_exists($conn, 'quiz_attempts')): ?>
    <div class="rounded-2xl border border-slate-200/70 bg-white/85 px-6 py-10 text-center shadow-sm backdrop-blur-lg">
      <span class="lms-icon-tile mx-auto mb-3 bg-amber-50 text-amber-600"><i class="bi bi-exclamation-triangle"></i></span>
      <div class="font-semibold text-slate-900">Quiz attempts table is not available yet.</div>
      <p class="mt-2 mb-0 text-sm text-slate-500">Run migration 031 or ensure quiz_attempts exists.</p>
    </div>
  <?php elseif (empty($subjects)): ?>
    <div class="rounded-2xl border border-slate-200/70 bg-white/85 px-6 py-10 text-center shadow-sm backdrop-blur-lg">
      <span class="lms-icon-tile mx-auto mb-3 bg-violet-50 text-violet-600"><i class="bi bi-journal-x"></i></span>
      <div class="font-semibold text-slate-900">No subjects yet</div>
      <a href="admin_subjects" class="admin-btn admin-btn--primary mt-4 inline-flex">Content Hub</a>
    </div>
  <?php else: ?>

  <div class="qm-kpi-strip mb-3">
    <div class="qm-kpi relative flex min-h-0 flex-col overflow-hidden rounded-2xl border border-blue-100/90 bg-white/90 px-3 py-2">
      <div class="relative z-[1] flex items-center gap-3">
        <span class="lms-icon-tile inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-100 to-blue-50 text-blue-600 shadow-sm ring-1 ring-white"><i class="bi bi-list-check"></i></span>
        <div class="min-w-0">
          <div class="qm-kpi__label text-[11px] font-bold uppercase tracking-wide text-slate-500">Attempts</div>
          <div class="qm-kpi__value text-xl font-bold tracking-tight text-slate-900"><?php echo (int) $stats['attempts']; ?></div>
        </div>
      </div>
    </div>
    <div class="qm-kpi qm-kpi--emerald relative flex min-h-0 flex-col overflow-hidden rounded-2xl border border-emerald-100/90 bg-white/90 px-3 py-2">
      <div class="relative z-[1] flex items-center gap-3">
        <span class="lms-icon-tile inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-100 to-emerald-50 text-emerald-600 shadow-sm ring-1 ring-white"><i class="bi bi-check2-circle"></i></span>
        <div class="min-w-0">
          <div class="qm-kpi__label text-[11px] font-bold uppercase tracking-wide text-slate-500">Submitted</div>
          <div class="qm-kpi__value text-xl font-bold tracking-tight text-slate-900"><?php echo (int) $stats['submitted']; ?></div>
        </div>
      </div>
    </div>
    <div class="qm-kpi qm-kpi--amber relative flex min-h-0 flex-col overflow-hidden rounded-2xl border border-amber-100/90 bg-white/90 px-3 py-2">
      <div class="relative z-[1] flex items-center gap-3">
        <span class="lms-icon-tile inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-100 to-amber-50 text-amber-600 shadow-sm ring-1 ring-white"><i class="bi bi-hourglass-split"></i></span>
        <div class="min-w-0">
          <div class="qm-kpi__label text-[11px] font-bold uppercase tracking-wide text-slate-500">In progress</div>
          <div class="qm-kpi__value text-xl font-bold tracking-tight text-slate-900"><?php echo (int) $stats['in_progress']; ?></div>
        </div>
      </div>
    </div>
    <div class="qm-kpi qm-kpi--violet relative flex min-h-0 flex-col overflow-hidden rounded-2xl border border-violet-100/90 bg-white/90 px-3 py-2">
      <div class="relative z-[1] flex items-center gap-3">
        <span class="lms-icon-tile inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-100 to-violet-50 text-violet-600 shadow-sm ring-1 ring-white"><i class="bi bi-graph-up"></i></span>
        <div class="min-w-0">
          <div class="qm-kpi__label text-[11px] font-bold uppercase tracking-wide text-slate-500">Avg score</div>
          <div class="qm-kpi__value text-xl font-bold tracking-tight text-slate-900"><?php echo $stats['avg_score'] !== null ? h(quiz_admin_format_score($stats['avg_score'])) : '-'; ?></div>
        </div>
      </div>
    </div>
  </div>

  <form method="get" action="admin_quiz_monitor" class="admin-data-toolbar qm-filter-bar edupro-toolbar">
    <div class="admin-data-toolbar__row">
      <div class="admin-filter-field">
        <label for="qm-subject">Subject</label>
        <select id="qm-subject" name="subject_id" class="input-custom" onchange="this.form.querySelector('[name=quiz_id]').value=''; this.form.submit();">
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo (int) $s['subject_id']; ?>" <?php echo (int) $s['subject_id'] === $subjectId ? 'selected' : ''; ?>><?php echo h($s['subject_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-field">
        <label for="qm-quiz">Quiz</label>
        <select id="qm-quiz" name="quiz_id" class="input-custom">
          <option value="">All quizzes</option>
          <?php foreach ($quizzes as $qz): ?>
            <option value="<?php echo (int) $qz['quiz_id']; ?>" <?php echo (int) $qz['quiz_id'] === $quizId ? 'selected' : ''; ?>><?php echo h($qz['title']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-field">
        <label for="qm-status">Status</label>
        <select id="qm-status" name="status" class="input-custom">
          <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
          <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
          <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
          <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
        </select>
      </div>
      <div class="admin-filter-field admin-filter-search">
        <label for="qm-q">Search</label>
        <div class="admin-filter-search__control">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="qm-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search student or email..." autocomplete="off">
        </div>
      </div>
      <div class="admin-filter-field admin-filter-field--actions">
        <span class="admin-filter-field__spacer" aria-hidden="true"></span>
        <div class="admin-filter-actions">
          <button type="submit" class="admin-btn admin-btn--primary">Apply Filters</button>
          <a class="admin-btn admin-btn--ghost" href="<?php echo h($resetUrl); ?>">Reset</a>
        </div>
      </div>
    </div>
    <div class="admin-filter-chips" aria-label="Active filters">
      <?php if ($subjectName !== ''): ?>
        <span class="admin-filter-chip"><?php echo h($subjectName); ?></span>
      <?php endif; ?>
      <span class="admin-filter-chip"><?php echo h($statusChipLabel); ?><?php if ($statusFilter !== 'submitted'): ?> <a href="<?php echo h($mkUrl(['status' => null, 'page' => null])); ?>" class="admin-filter-chip__x" aria-label="Reset status to submitted">×</a><?php endif; ?></span>
      <?php if ($quizId > 0 && $quizChipTitle !== ''): ?>
        <span class="admin-filter-chip"><?php echo h($quizChipTitle); ?> <a href="<?php echo h($mkUrl(['quiz_id' => null, 'page' => null])); ?>" class="admin-filter-chip__x" aria-label="Clear quiz filter">×</a></span>
      <?php endif; ?>
      <?php if ($searchQ !== ''): ?>
        <span class="admin-filter-chip"><?php echo h($searchQ); ?> <a href="<?php echo h($mkUrl(['q' => null, 'page' => null])); ?>" class="admin-filter-chip__x" aria-label="Clear search">×</a></span>
      <?php endif; ?>
    </div>
  </form>

  <div class="admin-data-surface qm-table-shell">
    <div class="admin-data-header">
      <div>
        <div class="admin-data-header__title">Quiz attempts</div>
        <div class="admin-data-header__meta"><?php echo number_format($totalAttempts); ?> record<?php echo $totalAttempts === 1 ? '' : 's'; ?></div>
      </div>
    </div>
    <?php if (empty($attemptData['rows'])): ?>
      <div class="admin-empty-state">
        <span class="admin-empty-state__icon" aria-hidden="true"><i class="bi bi-search"></i></span>
        <h3 class="admin-empty-state__title">No quiz attempts found</h3>
        <p class="admin-empty-state__desc">Try adjusting your search or filters.</p>
        <a class="admin-btn admin-btn--ghost" href="<?php echo h($resetUrl); ?>">Clear filters</a>
      </div>
    <?php else: ?>
    <div class="admin-data-scroll">
      <table class="admin-table admin-data-table qm-monitor-table w-full text-sm">
        <thead>
          <tr>
            <th>Student</th>
            <th>Quiz</th>
            <th>Score</th>
            <th>Accuracy</th>
            <th>Submitted</th>
            <th>Status</th>
            <th class="qm-col-secondary hidden xl:table-cell">Started</th>
            <th class="qm-col-secondary hidden xl:table-cell">Tab switches</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($attemptData['rows'] as $row): ?>
              <?php
                $qmName = trim((string) ($row['full_name'] ?? ''));
                $qmParts = preg_split('/\s+/', $qmName !== '' ? $qmName : '?') ?: ['?'];
                $qmInitials = strtoupper(substr((string) ($qmParts[0] ?? '?'), 0, 1) . substr((string) ($qmParts[1] ?? ''), 0, 1));
                if ($qmInitials === '') {
                    $qmInitials = '?';
                }
                $qmStatus = (string) ($row['status'] ?? '');
                $qmStatusMod = 'muted';
                $qmStatusLabel = ucwords(str_replace('_', ' ', $qmStatus));
                if ($qmStatus === 'submitted') {
                    $qmStatusMod = 'ok';
                    $qmStatusLabel = 'Submitted';
                } elseif ($qmStatus === 'in_progress') {
                    $qmStatusMod = 'warn';
                    $qmStatusLabel = 'In Progress';
                } elseif ($qmStatus === 'expired') {
                    $qmStatusLabel = 'Expired';
                }
                $qmCorrect = (int) ($row['correct_count'] ?? 0);
                $qmTotal = (int) ($row['total_count'] ?? 0);
                $qmAccuracyPct = $qmTotal > 0 ? (int) round(($qmCorrect / $qmTotal) * 100) : null;
              ?>
              <tr>
                <td class="admin-data-sticky">
                  <div class="admin-data-person">
                    <span class="admin-data-avatar" aria-hidden="true"><?php echo h($qmInitials); ?></span>
                    <div class="admin-data-person__meta">
                      <div class="admin-data-person__name"><?php echo h($row['full_name'] ?? ''); ?></div>
                      <div class="admin-data-person__email"><?php echo h($row['email'] ?? ''); ?></div>
                    </div>
                  </div>
                </td>
                <td class="font-semibold"><?php echo h($row['quiz_title'] ?? ''); ?></td>
                <td class="admin-data-score"><?php echo h(quiz_admin_format_score(isset($row['score']) ? (float) $row['score'] : null)); ?></td>
                <td>
                  <div class="admin-data-accuracy">
                    <span><?php echo $qmCorrect; ?>/<?php echo $qmTotal; ?><?php if ($qmAccuracyPct !== null): ?> · <?php echo $qmAccuracyPct; ?>%<?php endif; ?></span>
                    <?php if ($qmAccuracyPct !== null): ?>
                      <div class="admin-data-meter" aria-hidden="true">
                        <span class="admin-data-meter__fill" style="width: <?php echo max(0, min(100, $qmAccuracyPct)); ?>%;"></span>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="whitespace-nowrap"><?php echo !empty($row['submitted_at']) ? h(date('M j, g:i A', strtotime((string) $row['submitted_at']))) : '-'; ?></td>
                <td>
                  <span class="admin-data-status admin-data-status--<?php echo h($qmStatusMod); ?>">
                    <span class="admin-data-status__dot" aria-hidden="true"></span>
                    <?php echo h($qmStatusLabel); ?>
                  </span>
                </td>
                <td class="qm-col-secondary hidden whitespace-nowrap xl:table-cell"><?php echo !empty($row['started_at']) ? h(date('M j, g:i A', strtotime((string) $row['started_at']))) : '-'; ?></td>
                <td class="qm-col-secondary hidden tabular-nums xl:table-cell"><?php echo isset($row['tab_switch_count']) ? (int) $row['tab_switch_count'] : '-'; ?></td>
                <td>
                  <a class="admin-data-action" href="admin_quiz_attempt_review?attempt_id=<?php echo (int) $row['attempt_id']; ?>" aria-label="Review attempt for <?php echo h($qmName !== '' ? $qmName : 'student'); ?>"><i class="bi bi-eye" aria-hidden="true"></i> Review <span aria-hidden="true">→</span></a>
                </td>
              </tr>
            <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="admin-pagination" aria-label="Quiz attempts pagination">
      <div class="admin-pagination__meta">Showing <?php echo number_format($showFrom); ?>–<?php echo number_format($showTo); ?> of <?php echo number_format($totalAttempts); ?></div>
      <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination__nav" aria-label="Pages">
          <?php if ($page > 1): ?>
            <a class="admin-pagination__btn" href="<?php echo h($mkUrl(['page' => $page - 1])); ?>">‹ Previous</a>
          <?php else: ?>
            <span class="admin-pagination__btn is-disabled" aria-disabled="true">‹ Previous</span>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a class="admin-pagination__btn<?php echo $i === $page ? ' is-current' : ''; ?>" href="<?php echo h($mkUrl(['page' => $i])); ?>"<?php echo $i === $page ? ' aria-current="page"' : ''; ?>><?php echo $i; ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a class="admin-pagination__btn" href="<?php echo h($mkUrl(['page' => $page + 1])); ?>">Next ›</a>
          <?php else: ?>
            <span class="admin-pagination__btn is-disabled" aria-disabled="true">Next ›</span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</main>
</body>
</html>
