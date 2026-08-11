<?php
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/preboards_admin_reports.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$subjects = preboards_admin_list_subjects($conn);
$subjectId = sanitizeInt($_GET['preboards_subject_id'] ?? 0);
if ($subjectId <= 0 && !empty($subjects)) {
    $subjectId = (int) ($subjects[0]['preboards_subject_id'] ?? 0);
}

$subject = null;
foreach ($subjects as $s) {
    if ((int) $s['preboards_subject_id'] === $subjectId) {
        $subject = $s;
        break;
    }
}

$setId = sanitizeInt($_GET['preboards_set_id'] ?? 0);
$searchQ = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'submitted';
if (!in_array($statusFilter, ['all', 'submitted', 'in_progress'], true)) {
    $statusFilter = 'submitted';
}
$page = sanitizeInt($_GET['page'] ?? 1, 1);
$perPage = 25;

$sets = $subjectId > 0 ? preboards_admin_list_sets($conn, $subjectId) : [];
if ($setId > 0) {
    $setOk = false;
    foreach ($sets as $st) {
        if ((int) $st['preboards_set_id'] === $setId) {
            $setOk = true;
            break;
        }
    }
    if (!$setOk) {
        $setId = 0;
    }
}

$attemptData = ['rows' => [], 'total' => 0];
$top10 = [];
$stats = ['attempts' => 0, 'submitted' => 0, 'students' => 0, 'avg_score' => null];
$totalAttempts = 0;
$totalPages = 1;

if ($subjectId > 0) {
    $attemptData = preboards_admin_fetch_attempts($conn, [
        'subject_id' => $subjectId,
        'set_id' => $setId,
        'q' => $searchQ,
        'status' => $statusFilter,
        'page' => $page,
        'per_page' => $perPage,
    ]);
    $totalAttempts = (int) ($attemptData['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalAttempts / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $attemptData = preboards_admin_fetch_attempts($conn, [
            'subject_id' => $subjectId,
            'set_id' => $setId,
            'q' => $searchQ,
            'status' => $statusFilter,
            'page' => $page,
            'per_page' => $perPage,
        ]);
        $totalAttempts = (int) ($attemptData['total'] ?? 0);
    }
    $top10 = preboards_admin_fetch_top10($conn, $subjectId, $setId);

    $statWhere = ['s.preboards_subject_id = ?'];
    $statTypes = 'i';
    $statParams = [$subjectId];
    if ($setId > 0) {
        $statWhere[] = 'a.preboards_set_id = ?';
        $statTypes .= 'i';
        $statParams[] = $setId;
    }
    $statSql = "SELECT
        COUNT(*) AS attempts,
        SUM(CASE WHEN a.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
        COUNT(DISTINCT a.user_id) AS students,
        AVG(CASE WHEN a.status = 'submitted' THEN a.score ELSE NULL END) AS avg_score
      FROM preboards_attempts a
      INNER JOIN preboards_sets s ON s.preboards_set_id = a.preboards_set_id
      WHERE " . implode(' AND ', $statWhere);
    $stmt = mysqli_prepare($conn, $statSql);
    mysqli_stmt_bind_param($stmt, $statTypes, ...$statParams);
    mysqli_stmt_execute($stmt);
    $statRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($statRow) {
        $stats['attempts'] = (int) ($statRow['attempts'] ?? 0);
        $stats['submitted'] = (int) ($statRow['submitted'] ?? 0);
        $stats['students'] = (int) ($statRow['students'] ?? 0);
        $stats['avg_score'] = isset($statRow['avg_score']) ? (float) $statRow['avg_score'] : null;
    }
}

$totalPages = max(1, (int) ceil($totalAttempts / $perPage));
$mkUrl = static function (array $overrides = []) use ($subjectId, $setId, $searchQ, $statusFilter, $page) {
    $params = array_filter([
        'preboards_subject_id' => $subjectId > 0 ? $subjectId : null,
        'preboards_set_id' => $setId > 0 ? $setId : null,
        'q' => $searchQ !== '' ? $searchQ : null,
        'status' => $statusFilter !== 'submitted' ? $statusFilter : null,
        'page' => $page > 1 ? $page : null,
    ], static fn($v) => $v !== null && $v !== '');
    $params = array_merge($params, $overrides);
    return 'admin_preboards_monitor?' . http_build_query($params);
};

$subjectName = $subject['subject_name'] ?? 'Preboards';
$pageTitle = 'Preboards Monitoring â€” ' . $subjectName;
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Preboards', 'admin_preboards_subjects'],
    ['Monitoring'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-preboards-monitor-page">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span>
      Preboards Monitoring
    </h1>
    <p class="text-gray-400 mt-2 mb-0 max-w-3xl text-sm sm:text-base">View all student scores, open full attempt reviews, and see the Top 10 highest scores.</p>
  </div>

  <?php if (empty($subjects)): ?>
    <div class="quiz-admin-table-shell rounded-xl px-5 py-12 text-center text-gray-400">
      <i class="bi bi-inbox text-4xl block mb-2"></i>
      <div class="font-semibold text-gray-200">No preboards subjects yet</div>
      <p class="text-sm mt-1 mb-4">Create a preboards subject first.</p>
      <a href="admin_preboards_subjects" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> Manage preboards</a>
    </div>
  <?php else: ?>

  <form method="get" action="admin_preboards_monitor" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
    <div class="min-w-[180px]">
      <label for="pb-mon-subject" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Subject</label>
      <select id="pb-mon-subject" name="preboards_subject_id" class="input-custom w-full" onchange="this.form.querySelector('[name=preboards_set_id]').value=''; this.form.submit();">
        <?php foreach ($subjects as $s): ?>
          <option value="<?php echo (int) $s['preboards_subject_id']; ?>" <?php echo (int) $s['preboards_subject_id'] === $subjectId ? 'selected' : ''; ?>><?php echo h($s['subject_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="min-w-[140px]">
      <label for="pb-mon-set" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Set</label>
      <select id="pb-mon-set" name="preboards_set_id" class="input-custom w-full">
        <option value="">All sets</option>
        <?php foreach ($sets as $st): ?>
          <option value="<?php echo (int) $st['preboards_set_id']; ?>" <?php echo (int) $st['preboards_set_id'] === $setId ? 'selected' : ''; ?>>Set <?php echo h($st['set_label']); ?><?php echo !empty($st['title']) ? ' â€” ' . h($st['title']) : ''; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="min-w-[140px]">
      <label for="pb-mon-status" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Status</label>
      <select id="pb-mon-status" name="status" class="input-custom w-full">
        <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Submitted only</option>
        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All attempts</option>
      </select>
    </div>
    <div class="flex-1 min-w-[200px]">
      <label for="pb-mon-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Search student</label>
      <input type="search" id="pb-mon-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Name or emailâ€¦" class="input-custom w-full" autocomplete="off">
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-search"></i> Apply</button>
      <?php if ($searchQ !== '' || $setId > 0 || $statusFilter !== 'submitted'): ?>
        <a href="<?php echo h($mkUrl(['q' => null, 'preboards_set_id' => null, 'status' => null, 'page' => null])); ?>" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="pb-monitor-stats mb-5">
    <div class="pb-monitor-stat">
      <span class="pb-monitor-stat-icon" aria-hidden="true"><i class="bi bi-journal-check"></i></span>
      <div><div class="pb-monitor-stat-value"><?php echo number_format($stats['attempts']); ?></div><div class="pb-monitor-stat-label">Total attempts</div></div>
    </div>
    <div class="pb-monitor-stat">
      <span class="pb-monitor-stat-icon" aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
      <div><div class="pb-monitor-stat-value"><?php echo number_format($stats['submitted']); ?></div><div class="pb-monitor-stat-label">Submitted</div></div>
    </div>
    <div class="pb-monitor-stat">
      <span class="pb-monitor-stat-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
      <div><div class="pb-monitor-stat-value"><?php echo number_format($stats['students']); ?></div><div class="pb-monitor-stat-label">Students</div></div>
    </div>
    <div class="pb-monitor-stat">
      <span class="pb-monitor-stat-icon" aria-hidden="true"><i class="bi bi-percent"></i></span>
      <div><div class="pb-monitor-stat-value"><?php echo $stats['avg_score'] !== null ? preboards_format_score($stats['avg_score']) : 'â€”'; ?></div><div class="pb-monitor-stat-label">Average score</div></div>
    </div>
  </div>

  <div class="quiz-admin-table-shell rounded-xl overflow-hidden mb-5">
    <div class="quiz-admin-table-head px-5 py-4">
      <span class="font-semibold text-gray-100"><i class="bi bi-trophy-fill text-amber-400 mr-1"></i> Top 10 â€” Highest scores</span>
      <p class="text-sm text-gray-500 mt-0.5 mb-0">
        <?php if ($setId > 0): ?>
          Best score per student for the selected set.
        <?php else: ?>
          Best single-set score per student across <?php echo h($subjectName); ?>.
        <?php endif; ?>
      </p>
    </div>
    <div class="pb-monitor-table-wrap">
      <table class="pb-monitor-table">
        <thead>
          <tr>
            <th class="col-num">#</th>
            <th class="col-student">Student</th>
            <?php if ($setId <= 0): ?><th>Subject / Set</th><?php endif; ?>
            <th>Score</th>
            <th>Correct answers</th>
            <th>Accuracy</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($top10)): ?>
            <tr><td colspan="<?php echo $setId > 0 ? 6 : 7; ?>" class="pb-monitor-empty"><i class="bi bi-trophy"></i>No submitted scores yet for this filter.</td></tr>
          <?php else: ?>
            <?php foreach ($top10 as $row):
              $rank = (int) ($row['rank'] ?? 0);
              $rankClass = $rank <= 3 ? 'pb-monitor-rank--' . $rank : 'pb-monitor-rank--n';
              $score = isset($row['best_score']) ? (float) $row['best_score'] : null;
              $tier = preboards_score_tier($score, true);
              $student = preboards_student_display_lines($row['full_name'] ?? '', $row['email'] ?? '');
              $initial = mb_strtoupper(mb_substr($student['display_name'], 0, 1, 'UTF-8'));
              $correct = (int) ($row['best_correct'] ?? 0);
              $total = (int) ($row['best_total'] ?? 0);
              $accuracy = $total > 0 ? round(($correct / $total) * 100, 1) : 0;
              $submittedDt = preboards_format_datetime_short($row['last_submitted'] ?? null);
            ?>
              <tr class="pb-monitor-row">
                <td class="col-num"><span class="pb-monitor-rank <?php echo h($rankClass); ?>"><?php echo $rank; ?></span></td>
                <td class="col-student">
                  <div class="pb-monitor-student">
                    <span class="pb-monitor-avatar" aria-hidden="true"><?php echo h($initial); ?></span>
                    <div class="min-w-0">
                      <div class="pb-monitor-student-name"><?php echo h($student['display_name']); ?></div>
                      <?php if ($student['subline'] !== ''): ?><div class="pb-monitor-student-email"><?php echo h($student['subline']); ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <?php if ($setId <= 0): ?>
                  <td>
                    <div class="pb-monitor-subject"><?php echo h($subjectName); ?></div>
                    <span class="pb-monitor-set-badge mt-1"><i class="bi bi-collection"></i> Set <?php echo h($row['set_label'] ?? 'â€”'); ?></span>
                  </td>
                <?php endif; ?>
                <td><span class="pb-score-pill pb-score-pill--<?php echo h($tier); ?>"><?php echo preboards_format_score($score); ?></span></td>
                <td><span class="pb-monitor-correct"><?php echo $correct; ?> / <?php echo $total; ?><small>questions</small></span></td>
                <td><span class="pb-monitor-correct"><?php echo number_format($accuracy, 1); ?>%</span></td>
                <td>
                  <div class="pb-monitor-datetime">
                    <strong><?php echo h($submittedDt['date']); ?></strong>
                    <?php if ($submittedDt['time'] !== ''): ?><span><?php echo h($submittedDt['time']); ?></span><?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="quiz-admin-table-shell rounded-xl overflow-hidden mb-5">
    <div class="quiz-admin-table-head px-5 py-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <span class="font-semibold text-gray-100"><i class="bi bi-table text-emerald-400 mr-1"></i> All attempts â€” detailed log</span>
        <p class="text-sm text-gray-500 mt-0.5 mb-0">
          <?php echo number_format($totalAttempts); ?> record<?php echo $totalAttempts === 1 ? '' : 's'; ?>
          <?php if ($searchQ !== ''): ?> Â· filtered by â€œ<?php echo h($searchQ); ?>â€<?php endif; ?>
          <?php if ($setId > 0): ?> Â· Set filter active<?php endif; ?>
        </p>
      </div>
      <span class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    </div>
    <div class="pb-monitor-table-wrap">
      <table class="pb-monitor-table">
        <thead>
          <tr>
            <th class="col-num">#</th>
            <th class="col-student">Student</th>
            <?php if ($setId <= 0): ?><th>Subject</th><?php endif; ?>
            <th>Set</th>
            <th>Attempt</th>
            <th>Score</th>
            <th>Correct</th>
            <th>Status</th>
            <th>Duration</th>
            <th>Started</th>
            <th>Submitted</th>
            <th class="text-right">Review</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($attemptData['rows'])): ?>
            <tr><td colspan="<?php echo $setId > 0 ? 11 : 12; ?>" class="pb-monitor-empty"><i class="bi bi-inbox"></i>No attempts match your filters.</td></tr>
          <?php else: ?>
            <?php
              $rowNum = ($page - 1) * $perPage;
              foreach ($attemptData['rows'] as $att):
              $rowNum++;
              $score = isset($att['score']) ? (float) $att['score'] : null;
              $status = (string) ($att['status'] ?? '');
              $isSubmitted = $status === 'submitted';
              $tier = preboards_score_tier($score, $isSubmitted);
              $student = preboards_student_display_lines($att['full_name'] ?? '', $att['email'] ?? '');
              $initial = mb_strtoupper(mb_substr($student['display_name'], 0, 1, 'UTF-8'));
              $correct = (int) ($att['correct_count'] ?? 0);
              $total = (int) ($att['total_count'] ?? 0);
              $startedDt = preboards_format_datetime_short($att['started_at'] ?? null);
              $submittedDt = preboards_format_datetime_short($att['submitted_at'] ?? null);
              $duration = preboards_format_duration($att['started_at'] ?? null, $isSubmitted ? ($att['submitted_at'] ?? null) : null);
              $reviewUrl = 'admin_preboards_attempt_review?preboards_attempt_id=' . (int) $att['preboards_attempt_id'];
              $attemptId = (int) ($att['preboards_attempt_id'] ?? 0);
            ?>
              <tr class="pb-monitor-row">
                <td class="col-num"><?php echo $rowNum; ?></td>
                <td class="col-student">
                  <div class="pb-monitor-student">
                    <span class="pb-monitor-avatar" aria-hidden="true"><?php echo h($initial); ?></span>
                    <div class="min-w-0">
                      <div class="pb-monitor-student-name"><?php echo h($student['display_name']); ?></div>
                      <?php if ($student['subline'] !== ''): ?><div class="pb-monitor-student-email"><?php echo h($student['subline']); ?></div><?php endif; ?>
                      <div class="pb-monitor-id">ID <?php echo $attemptId; ?></div>
                    </div>
                  </div>
                </td>
                <?php if ($setId <= 0): ?>
                  <td><span class="pb-monitor-subject"><?php echo h($att['subject_name'] ?? $subjectName); ?></span></td>
                <?php endif; ?>
                <td>
                  <span class="pb-monitor-set-badge"><i class="bi bi-collection"></i> Set <?php echo h($att['set_label'] ?? ''); ?></span>
                  <?php if (!empty($att['set_title'])): ?><span class="pb-monitor-set-title" title="<?php echo h($att['set_title']); ?>"><?php echo h($att['set_title']); ?></span><?php endif; ?>
                </td>
                <td><span class="pb-monitor-attempt-no">#<?php echo (int) ($att['attempt_no'] ?? 1); ?></span></td>
                <td>
                  <?php if ($isSubmitted): ?>
                    <span class="pb-score-pill pb-score-pill--<?php echo h($tier); ?>"><?php echo preboards_format_score($score); ?></span>
                  <?php else: ?>
                    <span class="pb-score-pill pb-score-pill--pending">â€”</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isSubmitted || $total > 0): ?>
                    <span class="pb-monitor-correct"><?php echo $correct; ?> / <?php echo $total; ?><small>answered</small></span>
                  <?php else: ?>
                    <span class="text-gray-500">â€”</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="pb-status-pill <?php echo $isSubmitted ? 'pb-status-pill--submitted' : 'pb-status-pill--progress'; ?>"><?php echo h(preboards_attempt_status_label($status)); ?></span>
                </td>
                <td><span class="pb-monitor-duration"><?php echo h($duration); ?></span></td>
                <td>
                  <div class="pb-monitor-datetime">
                    <strong><?php echo h($startedDt['date']); ?></strong>
                    <?php if ($startedDt['time'] !== ''): ?><span><?php echo h($startedDt['time']); ?></span><?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($isSubmitted): ?>
                    <div class="pb-monitor-datetime">
                      <strong><?php echo h($submittedDt['date']); ?></strong>
                      <?php if ($submittedDt['time'] !== ''): ?><span><?php echo h($submittedDt['time']); ?></span><?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-gray-500">â€”</span>
                  <?php endif; ?>
                </td>
                <td class="text-right">
                  <a href="<?php echo h($reviewUrl); ?>" class="pb-monitor-review-btn" title="View full exam review"><i class="bi bi-eye"></i> Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <nav class="px-5 py-4 border-t border-gray-800/60 flex justify-center" aria-label="Attempts pagination">
        <ul class="flex flex-wrap items-center gap-1">
          <?php if ($page > 1): ?>
            <li><a href="<?php echo h($mkUrl(['page' => $page - 1])); ?>" class="px-3 py-2 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-800 transition">Previous</a></li>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li>
              <a href="<?php echo h($mkUrl(['page' => $i])); ?>" class="px-3 py-2 rounded-lg border transition <?php echo $i === $page ? 'bg-primary border-primary text-white' : 'border-gray-600 text-gray-300 hover:bg-gray-800'; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <li><a href="<?php echo h($mkUrl(['page' => $page + 1])); ?>" class="px-3 py-2 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-800 transition">Next</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</body>
</html>
