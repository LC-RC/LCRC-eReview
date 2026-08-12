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
    $params = array_filter([
        'subject_id' => $subjectId > 0 ? $subjectId : null,
        'quiz_id' => $quizId > 0 ? $quizId : null,
        'q' => $searchQ !== '' ? $searchQ : null,
        'status' => $statusFilter !== 'submitted' ? $statusFilter : null,
        'page' => $page > 1 ? $page : null,
    ], static fn($v) => $v !== null && $v !== '');
    return 'admin_quiz_monitor?' . http_build_query(array_merge($params, $overrides));
};

$subjectName = $subject['subject_name'] ?? 'Quizzes';
$pageTitle = 'Quiz Monitoring - ' . $subjectName;
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Quiz Monitor'],
];
$adminHeroIcon = 'bar-chart-line';
$adminHeroTitle = 'Quiz Monitoring';
$adminHeroSubtitle = 'Track student quiz attempts, scores, and in-progress exams.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>
  <?php
    include __DIR__ . '/includes/components/admin_page_hero.php';
  ?>

  <?php if (!ereview_schema_table_exists($conn, 'quiz_attempts')): ?>
    <div class="rounded-xl border p-8 text-center page-table">
      <div class="font-semibold">Quiz attempts table is not available yet.</div>
      <p class="text-sm text-gray-500 mt-2 mb-0">Run migration 031 or ensure quiz_attempts exists.</p>
    </div>
  <?php elseif (empty($subjects)): ?>
    <div class="rounded-xl border p-8 text-center page-table">
      <div class="font-semibold">No subjects yet</div>
      <a href="admin_subjects" class="admin-btn admin-btn--primary mt-4 inline-flex">Content Hub</a>
    </div>
  <?php else: ?>

  <form method="get" action="admin_quiz_monitor" class="rounded-xl border p-4 mb-4 page-table flex flex-wrap items-end gap-3">
    <div class="min-w-[180px]">
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" for="qm-subject">Subject</label>
      <select id="qm-subject" name="subject_id" class="input-custom w-full" onchange="this.form.querySelector('[name=quiz_id]').value=''; this.form.submit();">
        <?php foreach ($subjects as $s): ?>
          <option value="<?php echo (int) $s['subject_id']; ?>" <?php echo (int) $s['subject_id'] === $subjectId ? 'selected' : ''; ?>><?php echo h($s['subject_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="min-w-[180px]">
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" for="qm-quiz">Quiz</label>
      <select id="qm-quiz" name="quiz_id" class="input-custom w-full">
        <option value="">All quizzes</option>
        <?php foreach ($quizzes as $qz): ?>
          <option value="<?php echo (int) $qz['quiz_id']; ?>" <?php echo (int) $qz['quiz_id'] === $quizId ? 'selected' : ''; ?>><?php echo h($qz['title']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="min-w-[140px]">
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" for="qm-status">Status</label>
      <select id="qm-status" name="status" class="input-custom w-full">
        <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
      </select>
    </div>
    <div class="flex-1 min-w-[200px]">
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" for="qm-q">Search student</label>
      <input type="search" id="qm-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Name or email..." class="input-custom w-full" autocomplete="off">
    </div>
    <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
  </form>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">Attempts</div><div class="text-2xl font-bold"><?php echo (int) $stats['attempts']; ?></div></div>
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">Submitted</div><div class="text-2xl font-bold"><?php echo (int) $stats['submitted']; ?></div></div>
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">In progress</div><div class="text-2xl font-bold"><?php echo (int) $stats['in_progress']; ?></div></div>
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">Avg score</div><div class="text-2xl font-bold"><?php echo $stats['avg_score'] !== null ? h(quiz_admin_format_score($stats['avg_score'])) : '-'; ?></div></div>
  </div>

  <div class="rounded-xl border overflow-hidden page-table">
    <div class="overflow-x-auto">
      <table class="admin-table w-full text-sm">
        <thead>
          <tr>
            <th>Student</th>
            <th>Quiz</th>
            <th>Status</th>
            <th>Score</th>
            <th>Correct</th>
            <th>Started</th>
            <th>Submitted</th>
            <th>Tab switches</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($attemptData['rows'])): ?>
            <tr><td colspan="9" class="text-center py-8 opacity-60">No attempts match.</td></tr>
          <?php else: ?>
            <?php foreach ($attemptData['rows'] as $row): ?>
              <tr>
                <td>
                  <div class="font-semibold"><?php echo h($row['full_name'] ?? ''); ?></div>
                  <div class="text-xs opacity-60"><?php echo h($row['email'] ?? ''); ?></div>
                </td>
                <td><?php echo h($row['quiz_title'] ?? ''); ?></td>
                <td><span class="acl-pill"><?php echo h((string) ($row['status'] ?? '')); ?></span></td>
                <td><?php echo h(quiz_admin_format_score(isset($row['score']) ? (float) $row['score'] : null)); ?></td>
                <td><?php echo (int) ($row['correct_count'] ?? 0); ?>/<?php echo (int) ($row['total_count'] ?? 0); ?></td>
                <td class="whitespace-nowrap"><?php echo !empty($row['started_at']) ? h(date('M j, g:i A', strtotime((string) $row['started_at']))) : '-'; ?></td>
                <td class="whitespace-nowrap"><?php echo !empty($row['submitted_at']) ? h(date('M j, g:i A', strtotime((string) $row['submitted_at']))) : '-'; ?></td>
                <td><?php echo isset($row['tab_switch_count']) ? (int) $row['tab_switch_count'] : '-'; ?></td>
                <td>
                  <a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_quiz_attempt_review?attempt_id=<?php echo (int) $row['attempt_id']; ?>">Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <div class="p-3 flex flex-wrap gap-2 border-t">
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
          <a class="admin-btn admin-btn--sm <?php echo $i === $page ? 'admin-btn--primary' : 'admin-btn--ghost'; ?>" href="<?php echo h($mkUrl(['page' => $i])); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</main>
</body>
</html>
