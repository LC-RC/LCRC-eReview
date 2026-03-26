<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Professor dashboard';
$csrf = generateCSRFToken();

$uid = getCurrentUserId();
$nowTs = time();

// --- Students (from professor_college_students.php) ---
$collegeStudents = 0;
$studentStatus = [
  'pending' => 0,
  'approved' => 0,
  'rejected' => 0,
];

$qStudents = @mysqli_query($conn, "
  SELECT status, COUNT(*) AS c
  FROM users
  WHERE role='college_student'
  GROUP BY status
");
if ($qStudents) {
  while ($r = mysqli_fetch_assoc($qStudents)) {
    $st = strtolower((string)($r['status'] ?? ''));
    $cnt = (int)($r['c'] ?? 0);
    if (array_key_exists($st, $studentStatus)) {
      $studentStatus[$st] = $cnt;
    }
    $collegeStudents += $cnt;
  }
  mysqli_free_result($qStudents);
}

// --- Exams (from professor_exams.php) ---
$examCount = 0;
$examPublishedCount = 0;
$examOpenCount = 0;

$nowSql = date('Y-m-d H:i:s');
$nowEsc = mysqli_real_escape_string($conn, $nowSql);

$qExams = @mysqli_query($conn, "
  SELECT
    COUNT(*) AS total_count,
    SUM(CASE WHEN is_published=1 THEN 1 ELSE 0 END) AS published_count,
    SUM(CASE WHEN (deadline IS NULL OR deadline > '{$nowEsc}') THEN 1 ELSE 0 END) AS open_count
  FROM college_exams
  WHERE created_by=" . (int)$uid . "
");
if ($qExams) {
  $er = mysqli_fetch_assoc($qExams);
  $examCount = (int)($er['total_count'] ?? 0);
  $examPublishedCount = (int)($er['published_count'] ?? 0);
  $examOpenCount = (int)($er['open_count'] ?? 0);
  mysqli_free_result($qExams);
}

// Next exams by deadline
$nextExams = [];
$qNextExams = @mysqli_query($conn, "
  SELECT exam_id, title, deadline, is_published
  FROM college_exams
  WHERE created_by=" . (int)$uid . "
    AND (deadline IS NULL OR deadline >= '{$nowEsc}')
  ORDER BY deadline ASC, updated_at DESC
  LIMIT 4
");
if ($qNextExams) {
  while ($r = mysqli_fetch_assoc($qNextExams)) {
    $nextExams[] = $r;
  }
  mysqli_free_result($qNextExams);
}

// --- Upload tasks (from professor_upload_tasks.php) ---
$taskCount = 0;
$taskOpenCount = 0;
$taskDueSoonCount = 0;

$in7Sql = date('Y-m-d H:i:s', strtotime('+7 days'));
$in7Esc = mysqli_real_escape_string($conn, $in7Sql);

$qTasks = @mysqli_query($conn, "
  SELECT
    COUNT(*) AS total_count,
    SUM(CASE WHEN is_open=1 THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE
      WHEN is_open=1
       AND deadline IS NOT NULL
       AND deadline >= '{$nowEsc}'
       AND deadline <= '{$in7Esc}'
      THEN 1 ELSE 0
    END) AS due_soon_count
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
");
if ($qTasks) {
  $tr = mysqli_fetch_assoc($qTasks);
  $taskCount = (int)($tr['total_count'] ?? 0);
  $taskOpenCount = (int)($tr['open_count'] ?? 0);
  $taskDueSoonCount = (int)($tr['due_soon_count'] ?? 0);
  mysqli_free_result($qTasks);
}

$nextTasks = [];
$qNextTasks = @mysqli_query($conn, "
  SELECT task_id, title, deadline, is_open
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
    AND (deadline IS NULL OR deadline >= '{$nowEsc}')
  ORDER BY deadline ASC
  LIMIT 4
");
if ($qNextTasks) {
  while ($r = mysqli_fetch_assoc($qNextTasks)) {
    $nextTasks[] = $r;
  }
  mysqli_free_result($qNextTasks);
}

// --- Recent activity (from professor_monitor.php) ---
$attemptRows = [];
$recentAttempts = @mysqli_query($conn, "
  SELECT a.attempt_id, a.score, a.submitted_at, a.status,
         u.full_name, u.email,
         e.title AS exam_title
  FROM college_exam_attempts a
  INNER JOIN users u ON u.user_id=a.user_id AND u.role='college_student'
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
    AND e.created_by=" . (int)$uid . "
  ORDER BY a.submitted_at DESC
  LIMIT 6
");
if ($recentAttempts) {
  while ($r = mysqli_fetch_assoc($recentAttempts)) {
    $attemptRows[] = $r;
  }
  mysqli_free_result($recentAttempts);
}

$subRows = [];
$recentSubs = @mysqli_query($conn, "
  SELECT s.submission_id, s.file_path, s.file_name, s.submitted_at, s.status,
         u.full_name, u.email,
         t.title AS task_title
  FROM college_submissions s
  INNER JOIN users u ON u.user_id=s.user_id
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  WHERE t.created_by=" . (int)$uid . "
  ORDER BY s.submitted_at DESC
  LIMIT 6
");
if ($recentSubs) {
  while ($r = mysqli_fetch_assoc($recentSubs)) {
    $subRows[] = $r;
  }
  mysqli_free_result($recentSubs);
}

$totalActivity = count($attemptRows) + count($subRows);
$needsAttention = ((int)$studentStatus['pending'] > 0) || ($taskDueSoonCount > 0);

// Overview charts: last 6 months activity trend + current distribution
$activityByMonth = [];
for ($i = 5; $i >= 0; $i--) {
  $ym = date('Y-m', strtotime("-{$i} months"));
  $activityByMonth[$ym] = 0;
}

$qAttemptTrend = @mysqli_query($conn, "
  SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, COUNT(*) AS c
  FROM college_exam_attempts a
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
    AND e.created_by=" . (int)$uid . "
    AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY ym
");
if ($qAttemptTrend) {
  while ($r = mysqli_fetch_assoc($qAttemptTrend)) {
    $ym = (string)($r['ym'] ?? '');
    if (isset($activityByMonth[$ym])) {
      $activityByMonth[$ym] += (int)($r['c'] ?? 0);
    }
  }
  mysqli_free_result($qAttemptTrend);
}

$qSubTrend = @mysqli_query($conn, "
  SELECT DATE_FORMAT(s.submitted_at, '%Y-%m') AS ym, COUNT(*) AS c
  FROM college_submissions s
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  WHERE t.created_by=" . (int)$uid . "
    AND s.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY ym
");
if ($qSubTrend) {
  while ($r = mysqli_fetch_assoc($qSubTrend)) {
    $ym = (string)($r['ym'] ?? '');
    if (isset($activityByMonth[$ym])) {
      $activityByMonth[$ym] += (int)($r['c'] ?? 0);
    }
  }
  mysqli_free_result($qSubTrend);
}

// Activity dataset for interactive 7/30/90 range filter
$activityDaily = [];
for ($i = 89; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} days"));
  $activityDaily[$d] = 0;
}
$qAttemptDaily = @mysqli_query($conn, "
  SELECT DATE(a.submitted_at) AS d, COUNT(*) AS c
  FROM college_exam_attempts a
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
    AND e.created_by=" . (int)$uid . "
    AND a.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
  GROUP BY d
");
if ($qAttemptDaily) {
  while ($r = mysqli_fetch_assoc($qAttemptDaily)) {
    $d = (string)($r['d'] ?? '');
    if (isset($activityDaily[$d])) $activityDaily[$d] += (int)($r['c'] ?? 0);
  }
  mysqli_free_result($qAttemptDaily);
}
$qSubDaily = @mysqli_query($conn, "
  SELECT DATE(s.submitted_at) AS d, COUNT(*) AS c
  FROM college_submissions s
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  WHERE t.created_by=" . (int)$uid . "
    AND s.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
  GROUP BY d
");
if ($qSubDaily) {
  while ($r = mysqli_fetch_assoc($qSubDaily)) {
    $d = (string)($r['d'] ?? '');
    if (isset($activityDaily[$d])) $activityDaily[$d] += (int)($r['c'] ?? 0);
  }
  mysqli_free_result($qSubDaily);
}

$activityWindow7 = array_sum(array_slice(array_values($activityDaily), -7));
$activityWindow30 = array_sum(array_slice(array_values($activityDaily), -30));
$activityWindow90 = array_sum(array_values($activityDaily));

// KPI micro trends (this week vs previous week)
$studentsNew7 = 0; $studentsPrev7 = 0;
$qStudentTrend = @mysqli_query($conn, "
  SELECT
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS prev_week
  FROM users
  WHERE role='college_student'
");
if ($qStudentTrend && $r = mysqli_fetch_assoc($qStudentTrend)) {
  $studentsNew7 = (int)($r['this_week'] ?? 0);
  $studentsPrev7 = (int)($r['prev_week'] ?? 0);
  mysqli_free_result($qStudentTrend);
}

$examsNew7 = 0; $examsPrev7 = 0;
$qExamTrend = @mysqli_query($conn, "
  SELECT
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS prev_week
  FROM college_exams
  WHERE created_by=" . (int)$uid . "
");
if ($qExamTrend && $r = mysqli_fetch_assoc($qExamTrend)) {
  $examsNew7 = (int)($r['this_week'] ?? 0);
  $examsPrev7 = (int)($r['prev_week'] ?? 0);
  mysqli_free_result($qExamTrend);
}

$tasksNew7 = 0; $tasksPrev7 = 0;
$qTaskTrend = @mysqli_query($conn, "
  SELECT
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS prev_week
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
");
if ($qTaskTrend && $r = mysqli_fetch_assoc($qTaskTrend)) {
  $tasksNew7 = (int)($r['this_week'] ?? 0);
  $tasksPrev7 = (int)($r['prev_week'] ?? 0);
  mysqli_free_result($qTaskTrend);
}

$activityPrev7 = array_sum(array_slice(array_values($activityDaily), -14, 7));
$trendText = function (int $current, int $previous): string {
  if ($current === $previous) return 'No change vs last week';
  if ($current > $previous) return '+' . ($current - $previous) . ' vs last week';
  return '-' . ($previous - $current) . ' vs last week';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .prof-dashboard-page {
      background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%);
      min-height: 100%;
    }
    .dashboard-shell {
      padding-bottom: 1.5rem;
      color: #0f172a;
    }
    .saas-hero {
      position: relative;
      border-radius: 0.75rem;
      border: 1px solid rgba(255, 255, 255, 0.28);
      background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 35%, #16a34a 75%, #15803d 100%);
      box-shadow: 0 14px 34px -20px rgba(5, 46, 22, 0.75), inset 0 1px 0 rgba(255, 255, 255, 0.22);
      isolation: isolate;
    }
    .saas-hero::before,
    .saas-hero::after {
      content: "";
      position: absolute;
      border-radius: 9999px;
      pointer-events: none;
      z-index: -1;
    }
    .saas-hero::before {
      width: 270px;
      height: 270px;
      top: -140px;
      right: -90px;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.26) 0%, rgba(255, 255, 255, 0.02) 70%);
    }
    .saas-hero::after {
      width: 240px;
      height: 240px;
      bottom: -145px;
      left: -120px;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.16) 0%, rgba(255, 255, 255, 0.01) 70%);
    }
    .saas-icon-pill {
      background: rgba(255, 255, 255, 0.22);
      border-color: rgba(255, 255, 255, 0.34) !important;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45), 0 8px 22px -16px rgba(0, 0, 0, 0.65);
    }
    .saas-btn {
      border-radius: 9999px;
      transition: transform 0.25s ease-in-out, box-shadow 0.25s ease-in-out, background-color 0.25s ease-in-out, color 0.25s ease-in-out;
    }
    .saas-btn:hover {
      transform: scale(1.04);
    }
    .section-jump {
      position: sticky;
      top: 4.8rem;
      z-index: 20;
      margin: 0.65rem 0 1rem;
      padding: 0.45rem;
      border: 1px solid #ccefd8;
      border-radius: 0.7rem;
      background: rgba(255, 255, 255, 0.92);
      backdrop-filter: blur(6px);
      display: flex;
      flex-wrap: wrap;
      gap: 0.45rem;
    }
    .section-jump a {
      border: 1px solid #d7f8e2;
      border-radius: 9999px;
      background: #f7fff9;
      color: #166534;
      font-size: 0.76rem;
      font-weight: 700;
      padding: 0.34rem 0.65rem;
      transition: all 0.2s ease;
    }
    .section-jump a:hover {
      border-color: #86efac;
      background: #ecfdf3;
      transform: translateY(-1px);
    }
    .overview-strip {
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.24);
      backdrop-filter: blur(2px);
    }
    .quick-actions-bar {
      background: #ffffff;
      border: 1px solid rgba(22, 163, 74, 0.16);
      box-shadow: 0 12px 30px -26px rgba(22, 101, 52, 0.5);
    }
    .quick-pill {
      border-radius: 9999px;
      border: 1px solid #c9efd7;
      color: #166534;
      background: #f7fff9;
      transition: all 0.25s ease-in-out;
    }
    .quick-pill:hover {
      transform: translateY(-2px);
      border-color: #86efac;
      box-shadow: 0 10px 24px -20px rgba(22, 163, 74, 0.75);
      background: #ecfdf3;
    }
    .attention-card {
      border: 1px solid #bbf7d0;
      background: linear-gradient(90deg, #ecfdf3 0%, #f6fff9 100%);
      box-shadow: 0 14px 30px -28px rgba(21, 128, 61, 0.85);
    }
    .dash-card {
      border-radius: 0.75rem;
      border: 1px solid rgba(22, 163, 74, 0.18);
      box-shadow: 0 10px 28px -22px rgba(21, 128, 61, 0.58), 0 1px 0 rgba(255, 255, 255, 0.8) inset;
      transition: transform 0.22s ease-in-out, box-shadow 0.22s ease-in-out, border-color 0.22s ease-in-out, background-color 0.22s ease-in-out;
    }
    .dash-card:hover {
      transform: translateY(-2px);
      border-color: rgba(22, 163, 74, 0.35);
      box-shadow: 0 20px 34px -24px rgba(15, 118, 110, 0.4);
      background-color: #fdfffe;
    }
    .kpi-card {
      background: linear-gradient(180deg, #f4fff8 0%, #ffffff 80%);
    }
    .kpi-card:nth-child(2) {
      background: linear-gradient(180deg, #effcf8 0%, #ffffff 82%);
    }
    .kpi-card {
      min-height: 214px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 1.15rem 1.15rem 1rem;
    }
    .kpi-number {
      line-height: 1;
      letter-spacing: -0.02em;
      font-size: 2.05rem;
    }
    .kpi-meta {
      font-size: 0.74rem;
      color: #6b7280;
      margin-top: 0.55rem;
    }
    .kpi-trend {
      display: inline-flex;
      align-items: center;
      margin-top: 0.4rem;
      font-size: 0.69rem;
      font-weight: 700;
      color: #166534;
      background: #ecfdf3;
      border: 1px solid #bbf7d0;
      border-radius: 9999px;
      padding: 0.18rem 0.5rem;
    }
    .kpi-trend.is-flat {
      color: #475569;
      background: #f8fafc;
      border-color: #e2e8f0;
    }
    .kpi-action-btn {
      margin-top: 0.95rem;
      width: 100%;
      border-radius: 0.55rem;
      border: 1px solid #c9efd7;
      background: #ffffff;
      color: #166534;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      font-size: 0.84rem;
      font-weight: 700;
      padding: 0.58rem 0.7rem;
      transition: all 0.2s ease-in-out;
    }
    .kpi-action-btn:hover {
      transform: translateY(-2px);
      border-color: #86efac;
      background: #f0fdf4;
      box-shadow: 0 12px 24px -20px rgba(22, 163, 74, 0.8);
    }
    .dash-anim {
      opacity: 0;
      transform: translateY(12px);
      animation: dashFadeUp 0.55s ease-out forwards;
    }
    .delay-1 { animation-delay: 0.05s; }
    .delay-2 { animation-delay: 0.12s; }
    .delay-3 { animation-delay: 0.18s; }
    .delay-4 { animation-delay: 0.24s; }
    .delay-5 { animation-delay: 0.3s; }
    @keyframes dashFadeUp {
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .table-shell table {
      min-width: 680px;
    }
    .table-card-head {
      background: linear-gradient(180deg, #edfff4 0%, #f6fff9 100%);
    }
    .table-card-head h2 {
      letter-spacing: -0.01em;
    }
    .table-card-head p {
      color: #475569 !important;
    }
    .uploads-table tbody tr {
      transition: background-color 0.22s ease, transform 0.22s ease;
    }
    .uploads-table tbody tr:hover {
      background: #f4fff8;
    }
    .file-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      border: 1px solid #bbf7d0;
      background: #ecfdf3;
      color: #166534;
      border-radius: 9999px;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 0.2rem 0.55rem;
      margin-top: 0.4rem;
    }
    .table-footer-action {
      border-top: 1px solid #dcfce7;
      padding: 0.75rem 1rem;
      background: #fbfffc;
    }
    .table-footer-action a {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.82rem;
      font-weight: 700;
      color: #166534;
    }
    .upload-overview-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.65rem;
      margin: 1rem;
    }
    .upload-overview-item {
      border: 1px solid #d7f8e2;
      background: linear-gradient(180deg, #f7fff9 0%, #ffffff 100%);
      border-radius: 0.6rem;
      padding: 0.7rem 0.75rem;
      transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .upload-overview-item:hover {
      transform: translateY(-2px);
      border-color: #86efac;
      box-shadow: 0 14px 24px -22px rgba(21, 128, 61, 0.85);
    }
    .upload-feed {
      border-top: 1px solid #dcfce7;
      padding: 0.85rem 1rem 0.45rem;
    }
    .upload-feed-item {
      border: 1px solid #e7f8ee;
      border-radius: 0.6rem;
      padding: 0.62rem 0.72rem;
      margin-bottom: 0.55rem;
      background: #ffffff;
      transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .upload-feed-item:hover {
      transform: translateY(-2px);
      border-color: #bbf7d0;
      box-shadow: 0 10px 20px -20px rgba(22, 163, 74, 0.75);
    }
    .upload-feed-item:last-child {
      margin-bottom: 0;
    }
    .table-shell thead th {
      letter-spacing: 0.01em;
      font-size: 0.79rem;
      text-transform: uppercase;
      font-weight: 800;
    }
    .empty-state {
      color: #6b7280;
    }
    .empty-state i {
      color: #16a34a;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 9999px;
      padding: 0.7rem;
      font-size: 1.15rem;
      display: inline-flex;
      margin-bottom: 0.65rem;
      box-shadow: 0 10px 22px -18px rgba(22, 163, 74, 0.7);
    }
    .list-tile {
      border-radius: 1rem;
      border: 1px solid #d7f8e2;
      background: linear-gradient(180deg, #ffffff 0%, #f4fff8 100%);
      transition: transform 0.22s ease-in-out, box-shadow 0.22s ease-in-out, border-color 0.22s ease-in-out, background-color 0.22s ease-in-out;
    }
    .list-tile:hover {
      transform: translateY(-2px);
      box-shadow: 0 20px 34px -26px rgba(22, 163, 74, 0.75);
      border-color: #86efac;
      background-color: #fbfffd;
    }
    .section-shell {
      background: linear-gradient(180deg, #f4fff8 0%, #ffffff 40%);
    }
    .section-title {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      color: #14532d;
      font-weight: 800;
      font-size: 1.05rem;
      margin: 0 0 0.85rem;
      letter-spacing: 0.005em;
      padding: 0.45rem 0.65rem;
      border: 1px solid #d1fae5;
      border-radius: 0.62rem;
      background: linear-gradient(180deg, #f5fff9 0%, #ffffff 100%);
      box-shadow: 0 12px 24px -24px rgba(21, 128, 61, 0.85);
      position: relative;
      overflow: hidden;
    }
    .section-title::after {
      content: "";
      position: absolute;
      left: 0.62rem;
      bottom: 0.36rem;
      width: 38px;
      height: 2px;
      border-radius: 9999px;
      background: linear-gradient(90deg, #16a34a 0%, rgba(22,163,74,0.12) 100%);
    }
    .section-title i {
      color: #15803d;
      background: #ecfdf3;
      border: 1px solid #bbf7d0;
      width: 1.55rem;
      height: 1.55rem;
      border-radius: 0.45rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.83rem;
    }
    .insights-grid { display: block; }
    .chart-card {
      min-height: 290px;
    }
    .chart-wrap {
      position: relative;
      height: 195px;
      width: 100%;
    }
    .chart-toolbar {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: #f7fff9;
      border: 1px solid #d7f8e2;
      border-radius: 9999px;
      padding: 0.2rem;
    }
    .chart-range-btn {
      border: 0;
      background: transparent;
      border-radius: 9999px;
      padding: 0.26rem 0.56rem;
      font-size: 0.72rem;
      font-weight: 700;
      color: #166534;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    .chart-range-btn.is-active {
      background: #16a34a;
      color: #fff;
    }
    .chart-range-btn:hover {
      background: #ecfdf3;
    }
    .chart-empty {
      position: absolute;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      text-align: center;
      font-size: 0.82rem;
      color: #6b7280;
      background: linear-gradient(180deg, rgba(255,255,255,0.72) 0%, rgba(255,255,255,0.8) 100%);
      border-radius: 0.55rem;
      pointer-events: none;
    }
    .chart-empty.is-visible {
      display: flex;
    }
    .quick-cta {
      margin-top: 0.55rem;
      font-size: 0.75rem;
      font-weight: 700;
      color: #166534;
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      transition: transform 0.2s ease, color 0.2s ease;
    }
    .quick-cta:hover {
      transform: translateX(2px);
      color: #15803d;
    }
    .table-row-caret {
      opacity: 0;
      margin-left: 0.4rem;
      transition: opacity 0.2s ease;
    }
    .result-row:hover .table-row-caret {
      opacity: 1;
    }
    .result-row td {
      transition: background-color 0.2s ease;
    }
    html {
      scroll-behavior: smooth;
    }
    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
      .dash-anim { opacity: 1; transform: none; animation: none; }
      .dash-card, .list-tile, .kpi-action-btn, .saas-btn, .section-jump a { transition: none !important; }
    }
    .mini-metric {
      border: 1px solid #dcfce7;
      background: linear-gradient(180deg, #f7fff9 0%, #ffffff 100%);
      border-radius: 0.9rem;
      padding: 0.85rem 0.9rem;
    }
    @media (max-width: 768px) {
      .upload-overview-grid {
        grid-template-columns: 1fr;
      }
      .table-shell table {
        min-width: 560px;
      }
    }
  </style>
</head>
<body class="font-sans antialiased prof-dashboard-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="dashboard-shell w-full max-w-none">
    <div id="overview" class="mb-6 dash-anim">
      <div class="saas-hero overflow-hidden">
        <div class="p-7 md:p-8 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="saas-icon-pill w-14 h-14 rounded-2xl border flex items-center justify-center shrink-0">
              <i class="bi bi-mortarboard-fill text-white text-2xl"></i>
            </div>
            <div class="pt-0.5">
              <h1 class="text-3xl md:text-[2.05rem] font-extrabold tracking-tight text-white m-0 leading-tight">Professor Dashboard</h1>
              <p class="text-white/90 mt-2 mb-0 text-sm md:text-base">A polished overview of students, exams, submissions, and what needs attention next.</p>
            </div>
          </div>
          <div class="flex flex-wrap gap-3">
        <a href="professor_college_students.php" class="saas-btn inline-flex items-center gap-2 px-4 py-2.5 font-semibold bg-white text-green-800 hover:bg-green-50 shadow-sm hover:shadow-lg">
          <i class="bi bi-person-plus-fill"></i> Add student
        </a>
        <a href="professor_exams.php" class="saas-btn inline-flex items-center gap-2 px-4 py-2.5 font-semibold border border-white/35 bg-white/15 text-white hover:bg-white/25">
          <i class="bi bi-journal-check"></i> Manage exams
        </a>
        <a href="professor_upload_tasks.php" class="saas-btn inline-flex items-center gap-2 px-4 py-2.5 font-semibold border border-white/35 bg-white/15 text-white hover:bg-white/25">
          <i class="bi bi-cloud-arrow-up"></i> Upload tasks
        </a>
          </div>
        </div>
        <div class="px-7 md:px-8 pb-6">
          <div class="overview-strip rounded-xl px-4 py-3 text-white/95 text-sm flex flex-wrap gap-x-3 gap-y-1 items-center">
            <span class="font-semibold">Students: <strong><?php echo (int)$collegeStudents; ?></strong></span>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Pending approvals: <strong><?php echo (int)$studentStatus['pending']; ?></strong></span>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Open exams: <strong><?php echo (int)$examOpenCount; ?></strong></span>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Tasks due soon: <strong><?php echo (int)$taskDueSoonCount; ?></strong></span>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Recent activity (<span data-activity-window-label>30d</span>): <strong id="activityWindowHero"><?php echo (int)$activityWindow30; ?></strong></span>
          </div>
        </div>
      </div>
    </div>

    <nav class="section-jump dash-anim delay-1" aria-label="Dashboard sections">
      <a href="#overview">Overview</a>
      <a href="#performance">Performance</a>
      <a href="#insights">Insights</a>
      <a href="#activity">Activity</a>
      <a href="#upcoming">Upcoming</a>
    </nav>

    <!-- KPI widgets -->
    <h2 id="performance" class="section-title dash-anim delay-2"><i class="bi bi-speedometer2"></i> Performance Overview</h2>
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-6">
      <div class="dash-card kpi-card dash-anim delay-1 group">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0 tracking-wide">College students</p>
            <p class="kpi-number text-4xl font-extrabold text-green-700 m-0 mt-1"><?php echo $collegeStudents; ?></p>
            <div class="mt-3 flex flex-wrap gap-2">
              <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold bg-green-50 text-green-800 border border-green-100">Approved: <?php echo (int)$studentStatus['approved']; ?></span>
              <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold bg-gray-50 text-gray-700 border border-gray-100">Pending: <?php echo (int)$studentStatus['pending']; ?></span>
            </div>
            <span class="kpi-trend <?php echo $studentsNew7 === $studentsPrev7 ? 'is-flat' : ''; ?>"><?php echo h($trendText($studentsNew7, $studentsPrev7)); ?></span>
            <?php if ($collegeStudents === 0): ?>
              <a href="professor_college_students.php" class="quick-cta"><i class="bi bi-plus-circle"></i> Add your first student</a>
            <?php endif; ?>
          </div>
          <div class="w-12 h-12 rounded-2xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-people text-green-700 text-2xl"></i>
          </div>
        </div>
        <a href="professor_college_students.php" class="kpi-action-btn">
          <i class="bi bi-arrow-right"></i> View students
        </a>
      </div>

      <div class="dash-card kpi-card dash-anim delay-2 group">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0 tracking-wide">Exams</p>
            <p class="kpi-number text-4xl font-extrabold text-green-700 m-0 mt-1"><?php echo $examCount; ?></p>
            <p class="kpi-meta">Published: <?php echo (int)$examPublishedCount; ?> · Open: <?php echo (int)$examOpenCount; ?></p>
            <span class="kpi-trend <?php echo $examsNew7 === $examsPrev7 ? 'is-flat' : ''; ?>"><?php echo h($trendText($examsNew7, $examsPrev7)); ?></span>
            <?php if ($examCount === 0): ?>
              <a href="professor_exams.php" class="quick-cta"><i class="bi bi-plus-circle"></i> Create your first exam</a>
            <?php endif; ?>
          </div>
          <div class="w-12 h-12 rounded-2xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-journal-text text-green-700 text-2xl"></i>
          </div>
        </div>
        <a href="professor_exams.php" class="kpi-action-btn">
          <i class="bi bi-arrow-right"></i> Manage exams
        </a>
      </div>

      <div class="dash-card kpi-card dash-anim delay-3 group">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0 tracking-wide">Upload tasks</p>
            <p class="kpi-number text-4xl font-extrabold text-green-700 m-0 mt-1"><?php echo $taskCount; ?></p>
            <p class="kpi-meta">Open: <?php echo (int)$taskOpenCount; ?> · Due soon: <?php echo (int)$taskDueSoonCount; ?></p>
            <span class="kpi-trend <?php echo $tasksNew7 === $tasksPrev7 ? 'is-flat' : ''; ?>"><?php echo h($trendText($tasksNew7, $tasksPrev7)); ?></span>
            <?php if ($taskCount === 0): ?>
              <a href="professor_upload_tasks.php" class="quick-cta"><i class="bi bi-plus-circle"></i> Publish first upload task</a>
            <?php endif; ?>
          </div>
          <div class="w-12 h-12 rounded-2xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-folder-plus text-green-700 text-2xl"></i>
          </div>
        </div>
        <a href="professor_upload_tasks.php" class="kpi-action-btn">
          <i class="bi bi-arrow-right"></i> Manage upload tasks
        </a>
      </div>

      <div class="dash-card kpi-card dash-anim delay-4 group">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0 tracking-wide">Recent activity</p>
            <p id="activityWindowCard" class="kpi-number text-4xl font-extrabold text-green-700 m-0 mt-1"><?php echo (int)$activityWindow30; ?></p>
            <p class="kpi-meta">Submissions in selected window</p>
            <span class="kpi-trend <?php echo $activityWindow7 === $activityPrev7 ? 'is-flat' : ''; ?>"><?php echo h($trendText($activityWindow7, $activityPrev7)); ?></span>
          </div>
          <div class="w-12 h-12 rounded-2xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-activity text-green-700 text-2xl"></i>
          </div>
        </div>
        <a href="professor_monitor.php" class="kpi-action-btn">
          <i class="bi bi-arrow-right"></i> Open monitor
        </a>
      </div>
    </section>

    <h2 id="insights" class="section-title dash-anim delay-3"><i class="bi bi-graph-up-arrow"></i> Insights</h2>
    <section class="insights-grid mb-6">
      <div class="dash-card section-shell chart-card dash-anim delay-3 bg-white p-5">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div>
            <h3 class="text-lg font-bold text-green-800 m-0">Submissions trend</h3>
            <p class="text-sm text-gray-500 m-0 mt-1">Exam attempts + file uploads over the last 6 months.</p>
          </div>
          <div class="flex items-center gap-2">
            <div class="chart-toolbar" role="group" aria-label="Activity range">
              <button class="chart-range-btn" type="button" data-range="7">7d</button>
              <button class="chart-range-btn is-active" type="button" data-range="30">30d</button>
              <button class="chart-range-btn" type="button" data-range="90">90d</button>
            </div>
            <span id="chartTotalBadge" class="text-xs font-semibold px-2.5 py-1 rounded-full bg-green-50 border border-green-200 text-green-700">
              Total: <?php echo (int)$activityWindow30; ?>
            </span>
          </div>
        </div>
        <div class="chart-wrap">
          <canvas id="profActivityChart" aria-label="Professor activity trend"></canvas>
          <div id="profChartEmpty" class="chart-empty">No activity yet in this time range.<br>Activity will appear once students submit exams or files.</div>
        </div>
      </div>
    </section>

    <h2 id="activity" class="section-title dash-anim delay-3"><i class="bi bi-clipboard-data"></i> Activity And Submissions</h2>
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
      <!-- Recent exam attempts -->
      <div class="lg:col-span-2 dash-card section-shell dash-anim delay-3 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-green-100 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold text-green-800 m-0">Recent exam results</h2>
            <p class="text-sm text-gray-500 m-0 mt-1">Latest scores from your exams</p>
          </div>
          <a href="professor_monitor.php" class="text-green-700 font-semibold hover:underline inline-flex items-center gap-1">
            View monitor <i class="bi bi-arrow-right"></i>
          </a>
        </div>

        <div class="table-shell overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="bg-green-50/90 text-green-900 font-semibold">
              <tr>
                <th class="px-6 py-3.5">Student</th>
                <th class="px-6 py-3.5">Exam</th>
                <th class="px-6 py-3.5">Score</th>
                <th class="px-6 py-3.5">Submitted</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-green-100">
              <?php if (empty($attemptRows)): ?>
                <tr>
                  <td colspan="4" class="px-6 py-12 text-center empty-state">
                    <i class="bi bi-file-earmark-text"></i>
                    <div class="font-medium">No exam submissions available yet.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($attemptRows as $r): ?>
                  <tr class="result-row hover:bg-green-50/80 transition-colors">
                    <td class="px-6 py-3.5 font-semibold text-gray-800"><?php echo h($r['full_name']); ?></td>
                    <td class="px-6 py-3.5 text-gray-700"><?php echo h($r['exam_title'] ?? ''); ?></td>
                    <td class="px-6 py-3.5 font-bold text-green-700"><?php echo ($r['score'] !== null && $r['score'] !== '') ? h((string)$r['score']) . '%' : '—'; ?></td>
                    <td class="px-6 py-3.5 text-gray-600"><?php echo !empty($r['submitted_at']) ? h(date('M j, g:i A', strtotime($r['submitted_at']))) : '—'; ?><i class="bi bi-arrow-right table-row-caret"></i></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent file submissions -->
      <div class="dash-card section-shell dash-anim delay-4 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-green-100 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold text-green-800 m-0">Latest file uploads</h2>
            <p class="text-sm text-gray-500 m-0 mt-1">Quick upload overview and latest submission activity.</p>
          </div>
          <a href="professor_upload_tasks.php" class="text-green-700 font-semibold hover:underline inline-flex items-center gap-1">
            Manage uploads <i class="bi bi-arrow-right"></i>
          </a>
        </div>

        <div class="upload-overview-grid">
          <div class="upload-overview-item">
            <p class="text-xs text-gray-500 m-0">Total recent uploads</p>
            <p class="text-xl font-extrabold text-green-800 m-0 mt-1"><?php echo (int)count($subRows); ?></p>
          </div>
          <div class="upload-overview-item">
            <p class="text-xs text-gray-500 m-0">Upload tasks</p>
            <p class="text-xl font-extrabold text-green-800 m-0 mt-1"><?php echo (int)$taskCount; ?></p>
          </div>
          <div class="upload-overview-item">
            <p class="text-xs text-gray-500 m-0">Open tasks</p>
            <p class="text-xl font-extrabold text-green-800 m-0 mt-1"><?php echo (int)$taskOpenCount; ?></p>
          </div>
          <div class="upload-overview-item">
            <p class="text-xs text-gray-500 m-0">Due soon</p>
            <p class="text-xl font-extrabold <?php echo $taskDueSoonCount > 0 ? 'text-amber-700' : 'text-green-800'; ?> m-0 mt-1"><?php echo (int)$taskDueSoonCount; ?></p>
          </div>
        </div>

        <div class="upload-feed">
          <?php if (empty($subRows)): ?>
            <div class="py-8 text-center empty-state">
              <i class="bi bi-folder2-open"></i>
              <div class="font-medium">No file submissions available yet.</div>
            </div>
          <?php else: ?>
            <?php $uploadShown = 0; ?>
            <?php foreach ($subRows as $s): ?>
              <?php if ($uploadShown >= 3) break; ?>
              <div class="upload-feed-item">
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-800 m-0 truncate"><?php echo h($s['full_name']); ?></p>
                    <p class="text-xs text-gray-500 m-0 mt-0.5 truncate"><?php echo h($s['task_title'] ?? ''); ?></p>
                    <?php if (!empty($s['file_name']) && !empty($s['file_path'])): ?>
                      <a href="<?php echo h($s['file_path']); ?>" class="file-chip w-fit" target="_blank" rel="noopener">
                        <i class="bi bi-paperclip"></i><?php echo h($s['file_name']); ?>
                      </a>
                    <?php endif; ?>
                  </div>
                  <span class="text-[11px] text-gray-500 shrink-0">
                    <?php echo !empty($s['submitted_at']) ? h(date('M j, g:i A', strtotime($s['submitted_at']))) : '—'; ?>
                  </span>
                </div>
              </div>
              <?php $uploadShown++; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="table-footer-action">
          <a href="professor_monitor.php"><i class="bi bi-eye"></i> View all file activity</a>
        </div>
      </div>
    </section>

    <h2 id="upcoming" class="section-title dash-anim delay-4"><i class="bi bi-calendar-event"></i> Upcoming Work</h2>
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <!-- Upcoming exams -->
      <div class="dash-card section-shell dash-anim delay-4 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-green-100">
          <h2 class="text-lg font-bold text-green-800 m-0">Upcoming exams</h2>
          <p class="text-sm text-gray-500 m-0 mt-1">Deadlines for your next assessments</p>
        </div>
        <div class="p-5">
          <?php if (empty($nextExams)): ?>
            <div class="text-center py-12 empty-state">
              <i class="bi bi-calendar-week"></i>
              <div class="font-medium">No upcoming exams scheduled.</div>
              <a href="professor_exams.php" class="quick-cta justify-center"><i class="bi bi-plus-circle"></i> Create an exam</a>
            </div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($nextExams as $e): ?>
                <?php
                  $examDeadlineTs = !empty($e['deadline']) ? strtotime((string)$e['deadline']) : null;
                  $isExamDueSoon = $examDeadlineTs !== false && $examDeadlineTs !== null && $examDeadlineTs >= $nowTs && $examDeadlineTs <= ($nowTs + (2 * 86400));
                ?>
                <a href="professor_exam_edit.php?id=<?php echo (int)($e['exam_id'] ?? 0); ?>" class="list-tile group flex items-center justify-between gap-3 p-4">
                  <div class="min-w-0 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-green-100 border border-green-200 text-green-700 flex items-center justify-center shrink-0 mt-0.5">
                      <i class="bi bi-journal-richtext"></i>
                    </div>
                    <div class="min-w-0">
                      <p class="font-semibold text-green-900 truncate"><?php echo h($e['title'] ?? ''); ?></p>
                      <p class="text-xs text-gray-500 mt-1 mb-0">
                      <?php echo !empty($e['deadline']) ? h(date('M j, Y g:i A', strtotime($e['deadline']))) : 'No deadline'; ?>
                      </p>
                    </div>
                  </div>
                  <div class="shrink-0 inline-flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold border <?php echo $isExamDueSoon ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-green-50 text-green-800 border-green-200'; ?>">
                      <?php echo $isExamDueSoon ? 'Due Soon' : 'Upcoming'; ?>
                    </span>
                    <i class="bi bi-arrow-right text-green-700 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Upcoming tasks -->
      <div class="dash-card section-shell dash-anim delay-5 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-green-100">
          <h2 class="text-lg font-bold text-green-800 m-0">Upcoming upload tasks</h2>
          <p class="text-sm text-gray-500 m-0 mt-1">Deadlines for student submissions</p>
        </div>
        <div class="p-5">
          <?php if (empty($nextTasks)): ?>
            <div class="text-center py-12 empty-state">
              <i class="bi bi-calendar2-check"></i>
              <div class="font-medium">No upcoming upload tasks.</div>
              <a href="professor_upload_tasks.php" class="quick-cta justify-center"><i class="bi bi-plus-circle"></i> Create an upload task</a>
            </div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($nextTasks as $t): ?>
                <?php
                  $taskDeadlineTs = !empty($t['deadline']) ? strtotime((string)$t['deadline']) : null;
                  $isTaskDueSoon = $taskDeadlineTs !== false && $taskDeadlineTs !== null && $taskDeadlineTs >= $nowTs && $taskDeadlineTs <= ($nowTs + (2 * 86400));
                ?>
                <a href="professor_upload_tasks.php?edit=<?php echo (int)($t['task_id'] ?? 0); ?>" class="list-tile group flex items-center justify-between gap-3 p-4">
                  <div class="min-w-0 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-green-100 border border-green-200 text-green-700 flex items-center justify-center shrink-0 mt-0.5">
                      <i class="bi bi-folder2"></i>
                    </div>
                    <div class="min-w-0">
                      <p class="font-semibold text-green-900 truncate"><?php echo h($t['title'] ?? ''); ?></p>
                      <p class="text-xs text-gray-500 mt-1 mb-0">
                      <?php echo !empty($t['deadline']) ? h(date('M j, Y g:i A', strtotime($t['deadline']))) : 'No deadline'; ?>
                      </p>
                    </div>
                  </div>
                  <div class="shrink-0 inline-flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold border <?php echo $isTaskDueSoon ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-green-50 text-green-800 border-green-200'; ?>">
                      <?php echo $isTaskDueSoon ? 'Due Soon' : 'Upcoming'; ?>
                    </span>
                    <i class="bi bi-arrow-right text-green-700 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
  (function () {
    if (typeof Chart === 'undefined') return;

    var trendCanvas = document.getElementById('profActivityChart');
    if (!trendCanvas) return;

    var daily = <?php echo json_encode(array_map(function ($date, $count) { return ['date' => $date, 'count' => $count]; }, array_keys($activityDaily), array_values($activityDaily))); ?>;
    var activityWindowHero = document.getElementById('activityWindowHero');
    var activityWindowCard = document.getElementById('activityWindowCard');
    var chartTotalBadge = document.getElementById('chartTotalBadge');
    var chartEmpty = document.getElementById('profChartEmpty');
    var windowLabels = document.querySelectorAll('[data-activity-window-label]');
    var rangeButtons = document.querySelectorAll('.chart-range-btn');

    function formatLabel(dateStr) {
      var d = new Date(dateStr + 'T00:00:00');
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function getGrouped(rangeDays) {
      var windowData = daily.slice(-rangeDays);
      if (rangeDays <= 14) {
        return {
          labels: windowData.map(function (r) { return formatLabel(r.date); }),
          values: windowData.map(function (r) { return Number(r.count || 0); })
        };
      }
      var grouped = [];
      for (var i = 0; i < windowData.length; i += 7) {
        var chunk = windowData.slice(i, i + 7);
        if (!chunk.length) continue;
        var total = chunk.reduce(function (sum, item) { return sum + Number(item.count || 0); }, 0);
        grouped.push({
          label: formatLabel(chunk[0].date) + ' - ' + formatLabel(chunk[chunk.length - 1].date),
          value: total
        });
      }
      return {
        labels: grouped.map(function (g) { return g.label; }),
        values: grouped.map(function (g) { return g.value; })
      };
    }

    var initial = getGrouped(30);
    var chart = new Chart(trendCanvas, {
      type: 'line',
      data: {
        labels: initial.labels,
        datasets: [{
          label: 'Total submissions',
          data: initial.values,
          borderColor: '#15803d',
          backgroundColor: 'rgba(21, 128, 61, 0.12)',
          fill: true,
          tension: 0.34,
          pointRadius: 3.2,
          pointHoverRadius: 5.2,
          pointBackgroundColor: '#15803d'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { color: '#6b7280', precision: 0 },
            grid: { color: 'rgba(21, 128, 61, 0.12)' }
          },
          x: {
            ticks: { color: '#6b7280', maxRotation: 0 },
            grid: { display: false }
          }
        }
      }
    });

    function updateRange(rangeDays) {
      var grouped = getGrouped(rangeDays);
      var total = grouped.values.reduce(function (sum, n) { return sum + Number(n || 0); }, 0);
      chart.data.labels = grouped.labels;
      chart.data.datasets[0].data = grouped.values;
      chart.update();

      if (activityWindowHero) activityWindowHero.textContent = total;
      if (activityWindowCard) activityWindowCard.textContent = total;
      if (chartTotalBadge) chartTotalBadge.textContent = 'Total: ' + total;
      windowLabels.forEach(function (el) { el.textContent = rangeDays + 'd'; });
      if (chartEmpty) chartEmpty.classList.toggle('is-visible', total === 0);
    }

    rangeButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var rangeDays = Number(btn.getAttribute('data-range') || 30);
        rangeButtons.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        updateRange(rangeDays);
      });
    });

    updateRange(30);

  })();
  </script>
</body>
</html>
