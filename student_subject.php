<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/quiz_helpers.php';
requireRole('student');

$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$subject) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM lessons WHERE subject_id=? ORDER BY lesson_id ASC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$lessons = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT * FROM quizzes WHERE subject_id=? ORDER BY quiz_id DESC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$quizzesAll = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

// Load attempt data for Quizzers tab (in_progress + last score per quiz) for current student
$userId = getCurrentUserId();
$attemptsByQuiz = [];
$lastScoreByQuiz = [];
$attemptCountByQuiz = [];
$lastAttemptDateByQuiz = [];
$lastTimeSpentByQuiz = [];
$bestScoreByQuiz = [];
$bestScoreDateByQuiz = [];
if ($userId) {
    $quizIds = [];
    mysqli_data_seek($quizzesAll, 0);
    while ($row = mysqli_fetch_assoc($quizzesAll)) $quizIds[] = (int)$row['quiz_id'];
    mysqli_data_seek($quizzesAll, 0);
    if (!empty($quizIds)) {
        $ids = implode(',', array_map('intval', $quizIds));
        $ar = mysqli_query($conn, "SELECT attempt_id, quiz_id, status, score, correct_count, total_count, submitted_at, started_at FROM quiz_attempts WHERE user_id=".(int)$userId." AND quiz_id IN (".$ids.") ORDER BY attempt_id DESC");
        if ($ar) {
            while ($row = mysqli_fetch_assoc($ar)) {
                $qid = (int)$row['quiz_id'];
                if (!isset($lastAttemptDateByQuiz[$qid])) {
                    $lastAttemptDateByQuiz[$qid] = !empty($row['submitted_at']) ? $row['submitted_at'] : ($row['started_at'] ?? null);
                }
                if ($row['status'] === 'in_progress' && !isset($attemptsByQuiz[$qid])) {
                    $attemptsByQuiz[$qid] = (int)$row['attempt_id'];
                }
                if ($row['status'] === 'submitted') {
                    if (!isset($lastScoreByQuiz[$qid])) {
                        $lastScoreByQuiz[$qid] = ['score' => (float)($row['score'] ?? 0), 'correct' => (int)($row['correct_count'] ?? 0), 'total' => (int)($row['total_count'] ?? 0)];
                        $sub = strtotime($row['submitted_at'] ?? '0');
                        $start = strtotime($row['started_at'] ?? '0');
                        $lastTimeSpentByQuiz[$qid] = ($sub > 0 && $start > 0 && $sub >= $start) ? ($sub - $start) : 0;
                    }
                    $scorePct = (int)$row['total_count'] > 0 ? round(100 * (int)$row['correct_count'] / (int)$row['total_count']) : (float)($row['score'] ?? 0);
                    if (!isset($bestScoreByQuiz[$qid]) || $scorePct > $bestScoreByQuiz[$qid]) {
                        $bestScoreByQuiz[$qid] = $scorePct;
                        $bestScoreDateByQuiz[$qid] = !empty($row['submitted_at']) ? $row['submitted_at'] : ($row['started_at'] ?? null);
                    }
                }
                if (!isset($attemptCountByQuiz[$qid])) $attemptCountByQuiz[$qid] = 0;
                if ($row['status'] === 'submitted') $attemptCountByQuiz[$qid]++;
            }
        }
    }
}

// Quiz summary stats for this subject (total, passed, failed, average score)
$totalQuizzes = 0;
$quizzesPassed = 0;
$quizzesFailed = 0;
$scoreSum = 0;
$scoreCount = 0;
mysqli_data_seek($quizzesAll, 0);
while ($q = mysqli_fetch_assoc($quizzesAll)) {
    $totalQuizzes++;
    $qid = (int)$q['quiz_id'];
    $lastScore = $lastScoreByQuiz[$qid] ?? null;
    if ($lastScore !== null && (int)($lastScore['total'] ?? 0) > 0) {
        $pct = round(100 * (int)($lastScore['correct'] ?? 0) / (int)$lastScore['total']);
        $scoreSum += $pct;
        $scoreCount++;
        if ($pct >= 50) $quizzesPassed++; else $quizzesFailed++;
    }
}
mysqli_data_seek($quizzesAll, 0);
$averageScore = $scoreCount > 0 ? round($scoreSum / $scoreCount) : null;

$pageTitle = 'Subject - ' . $subject['subject_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .video-embed { aspect-ratio: 16/9; width: 100%; border: 0; border-radius: 8px; background: #000; }
    .split-view-container { display: none; position: fixed; top: 0; left: 20%; right: 0; bottom: 0; z-index: 1000; background: #f6f9ff; padding: 6px; gap: 6px; overflow: hidden; }
    .split-view-container.active { display: flex !important; }
    .split-view-container .split-panel { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .split-view-container iframe, .split-view-container video { flex: 1; width: 100%; min-height: 0; }
    .separate-view.hidden { display: none !important; }

    /* Tab navigation: smooth hover and transition */
    .subject-tab-btn {
      transition: all 0.2s ease;
    }
    .subject-tab-btn:hover {
      transform: translateY(-1px);
    }
    .subject-tab-btn:active {
      transform: translateY(0);
    }

    /* Tab panel enter/leave */
    .tab-panel-enter { opacity: 0; transform: translateY(6px); }
    .tab-panel-enter-active { transition: opacity 0.25s ease, transform 0.25s ease; }
    .tab-panel-enter-to { opacity: 1; transform: translateY(0); }
    .tab-panel-leave { opacity: 1; transform: translateY(0); }
    .tab-panel-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
    .tab-panel-leave-to { opacity: 0; transform: translateY(-4px); }

    /* Quizzers: search and filter */
    .quiz-search-input, .quiz-status-select {
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    .quiz-search-input:focus, .quiz-status-select:focus {
      border-color: rgba(65, 84, 241, 0.6);
      box-shadow: 0 0 0 3px rgba(65, 84, 241, 0.15);
    }

    /* Quizzers table: rows and buttons */
    .quiz-table-row {
      transition: background-color 0.18s ease, box-shadow 0.22s ease, transform 0.18s ease;
    }
    .quiz-table-row:hover {
      background-color: rgba(65, 84, 241, 0.03);
      box-shadow:
        0 0 0 1px rgba(148, 163, 184, 0.25),
        0 0 18px rgba(65, 84, 241, 0.16);
      transform: translateY(-1px);
    }
    .quiz-action-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.375rem;
      min-width: 8.5rem; height: 2.25rem; padding: 0 1rem; font-size: 0.8125rem; font-weight: 600;
      border-radius: 0.5rem; text-decoration: none;
      transition: transform 0.18s ease, box-shadow 0.22s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }
    .quiz-action-btn:hover {
      transform: translateY(-2px) scale(1.02);
      box-shadow:
        0 0 0 1px rgba(129, 140, 248, 0.35),
        0 10px 25px rgba(37, 99, 235, 0.28);
    }
    .quiz-action-btn:active {
      transform: translateY(0) scale(0.98);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    /* App modal – dark gradient format (same style for all confirmations/alerts) */
    .quiz-confirm-overlay {
      position: fixed; inset: 0; z-index: 1200; display: flex; align-items: center; justify-content: center; padding: 1rem;
      background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(6px);
      opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .quiz-confirm-overlay.show { opacity: 1; visibility: visible; }
    .quiz-confirm-card {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 50%, #0c0a14 100%);
      border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
      max-width: 400px; width: 100%; overflow: hidden; padding: 1.75rem 1.5rem; text-align: center;
      transform: scale(0.95); transition: transform 0.2s ease;
      border: 1px solid rgba(255,255,255,0.08);
    }
    .quiz-confirm-overlay.show .quiz-confirm-card { transform: scale(1); }
    .quiz-confirm-card .quiz-confirm-icon-wrap {
      width: 4rem; height: 4rem; margin: 0 auto 1.25rem; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 0 0 4px rgba(255,255,255,0.1), inset 0 0 20px rgba(255,255,255,0.05);
    }
    .quiz-confirm-card .quiz-confirm-icon-wrap.info { background: rgba(59, 130, 246, 0.25); color: #93c5fd; }
    .quiz-confirm-card .quiz-confirm-icon-wrap.warning { background: rgba(245, 158, 11, 0.25); color: #fcd34d; }
    .quiz-confirm-card .quiz-confirm-icon-wrap.error { background: rgba(239, 68, 68, 0.3); color: #fca5a5; box-shadow: 0 0 0 4px rgba(255,255,255,0.1), inset 0 0 24px rgba(239, 68, 68, 0.2); }
    .quiz-confirm-card .quiz-confirm-icon-wrap i { font-size: 1.75rem; }
    .quiz-confirm-card .quiz-confirm-header {
      font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.5rem; line-height: 1.3;
    }
    .quiz-confirm-card .quiz-confirm-body {
      color: rgba(241, 245, 249, 0.85); font-size: 0.9375rem; line-height: 1.6; margin-bottom: 1.5rem;
    }
    .quiz-confirm-card .quiz-confirm-actions {
      display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap;
    }
    .quiz-confirm-btn {
      padding: 0.625rem 1.5rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer;
      transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.15s, opacity 0.2s;
    }
    .quiz-confirm-btn:active { transform: scale(0.98); }
    .quiz-confirm-btn-cancel {
      background: transparent; color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.4);
    }
    .quiz-confirm-btn-cancel:hover { background: rgba(255,255,255,0.06); color: #e2e8f0; border-color: rgba(255,255,255,0.2); }
    .quiz-confirm-btn-primary {
      background: transparent; color: #fff; border: 1px solid rgba(255,255,255,0.6);
    }
    .quiz-confirm-btn-primary:hover { background: rgba(255,255,255,0.1); border-color: #fff; }

    .quiz-action-btn:focus,
    .quiz-take-again-btn:focus,
    .quiz-resume-link:focus {
      outline: none;
      box-shadow:
        0 0 0 2px rgba(255, 255, 255, 1),
        0 0 0 4px rgba(65, 84, 241, 0.6);
    }
    /* Take Again / Take Quiz: force primary blue so button is always visible */
    .quiz-take-again-btn {
      background-color: #4154f1 !important;
      color: #fff !important;
    }
    .quiz-take-again-btn:hover {
      background-color: #2d3fc7 !important;
      color: #fff !important;
    }
    .quiz-action-placeholder { min-width: 8.5rem; height: 2.25rem; display: inline-flex; align-items: center; justify-content: center; }
    .quiz-score-badge {
      display: inline-flex; align-items: center; justify-content: center; min-width: 2.5rem;
      padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.8125rem; font-weight: 600;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .quiz-table-row:hover .quiz-score-badge {
      transform: scale(1.05);
    }
    /* Staggered fade-in for quiz rows when tab is shown */
    @keyframes quiz-row-fade-in {
      from { opacity: 0; transform: translateY(4px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .quiz-table-row.quiz-row-visible {
      animation: quiz-row-fade-in 0.4s ease backwards;
    }

    /* Quizzers dashboard: header, summary cards, table polish */
    .quizzers-section { margin-top: 1rem; }
    .quizzers-header { padding: 1.25rem 1.5rem 1rem; border-bottom: 1px solid #e5e7eb; }
    .quizzers-header .quizzers-title { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin: 0 0 0.25rem 0; display: flex; align-items: center; gap: 0.5rem; }
    .quizzers-header .quizzers-subtitle { font-size: 0.875rem; color: #64748b; margin: 0; }
    .quiz-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.75rem; padding: 1rem 1.5rem; margin-bottom: 0; }
    .quiz-summary-card {
      background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 0.875rem 1rem;
      text-align: center;
      transition: box-shadow 0.2s ease, transform 0.15s ease, border-color 0.2s ease;
    }
    .quiz-summary-card:hover {
      box-shadow: 0 0 0 1px rgba(65, 84, 241, 0.2), 0 8px 20px rgba(65, 84, 241, 0.12);
      transform: translateY(-2px);
      border-color: rgba(65, 84, 241, 0.3);
    }
    .quiz-summary-card .summary-value { font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2; display: block; }
    .quiz-summary-card .summary-label { font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.25rem; }
    .quiz-summary-card.summary-passed .summary-value { color: #059669; }
    .quiz-summary-card.summary-failed .summary-value { color: #dc2626; }
    .quiz-summary-card.summary-avg .summary-value { color: #4154f1; }
    .quiz-table-wrap { overflow-x: auto; border-radius: 0 0 12px 12px; }
    .quiz-table { width: 100%; border-collapse: separate; border-spacing: 0; text-sm; }
    .quiz-table thead th { padding: 1rem 1.25rem; font-weight: 600; color: #475569; background: #f8fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .quiz-table thead th:first-child { text-align: left; padding-left: 1.5rem; }
    .quiz-table thead th:not(:first-child) { text-align: center; }
    .quiz-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background-color 0.18s ease, box-shadow 0.22s ease, transform 0.18s ease; }
    .quiz-table tbody tr:hover {
      background-color: rgba(65, 84, 241, 0.04);
      box-shadow: 0 0 0 1px rgba(148, 163, 184, 0.2), 0 0 20px rgba(65, 84, 241, 0.14);
      transform: translateY(-1px);
    }
    .quiz-table tbody td { padding: 1rem 1.25rem; vertical-align: middle; }
    .quiz-table tbody td:first-child { text-align: left; padding-left: 1.5rem; font-weight: 500; color: #1e293b; }
    .quiz-table tbody td:not(:first-child) { text-align: center; color: #475569; }
    .quiz-last-score-cell { display: flex; flex-direction: column; align-items: center; gap: 0.25rem; }
    .quiz-last-score-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
    .quiz-score-badge-lg { display: inline-flex; align-items: center; justify-content: center; min-width: 3rem; padding: 0.35rem 0.65rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 700; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .quiz-score-badge-lg.pass { background: #dcfce7; color: #15803d; box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.3); }
    .quiz-score-badge-lg.fail { background: #fee2e2; color: #b91c1c; box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.3); }
    .quiz-status-badge { display: inline-flex; align-items: center; padding: 0.4rem 0.75rem; border-radius: 9999px; font-size: 0.8125rem; font-weight: 700; }
    .quiz-status-badge.passed { background: #dcfce7; color: #15803d; }
    .quiz-status-badge.failed { background: #fee2e2; color: #b91c1c; }
    .quiz-status-badge.in-progress { background: #fef9c3; color: #a16207; }
    .quiz-status-badge.not-started { background: #f1f5f9; color: #64748b; }
    .quiz-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 600; text-decoration: none; transition: transform 0.18s ease, box-shadow 0.22s ease; }
    .quiz-btn:hover { transform: translateY(-2px); box-shadow: 0 0 0 1px rgba(129, 140, 248, 0.4), 0 8px 20px rgba(65, 84, 241, 0.25); }
    .quiz-btn-primary { background: #4154f1; color: #fff; border: none; }
    .quiz-btn-primary:hover { background: #2d3fc7; color: #fff; }
    .quiz-take-again-btn.quiz-btn { background-color: #4154f1 !important; color: #fff !important; }
    .quiz-take-again-btn.quiz-btn:hover { background-color: #2d3fc7 !important; color: #fff !important; }
    .quiz-btn-outline { background: #fff; color: #475569; border: 2px solid #e2e8f0; }
    .quiz-btn-outline:hover { background: #f8fafc; border-color: #94a3b8; color: #1e293b; }
    .quiz-btn-amber { background: #f59e0b; color: #fff; border: none; }
    .quiz-btn-amber:hover { background: #d97706; color: #fff; }
    .quiz-empty-state { padding: 3rem 1.5rem; text-align: center; }
    .quiz-empty-state .quiz-empty-icon { font-size: 3.5rem; color: #cbd5e1; margin-bottom: 1rem; display: block; }
    .quiz-empty-state .quiz-empty-msg { font-size: 1rem; font-weight: 600; color: #64748b; margin: 0; }
    @media (max-width: 768px) {
      .quiz-summary-grid { grid-template-columns: repeat(2, 1fr); }
      .quiz-table thead th, .quiz-table tbody td { padding: 0.75rem 0.5rem; font-size: 0.8125rem; }
      .quiz-table thead th:first-child, .quiz-table tbody td:first-child { padding-left: 1rem; }
    }

    /* Row enter/leave when filtering (smooth expand/collapse) */
    .quiz-row-enter { opacity: 0; transform: translateX(-8px); }
    .quiz-row-enter-active { transition: opacity 0.3s ease, transform 0.3s ease; }
    .quiz-row-enter-to { opacity: 1; transform: translateX(0); }
    .quiz-row-leave { opacity: 1; transform: translateX(0); }
    .quiz-row-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
    .quiz-row-leave-to { opacity: 0; transform: translateX(8px); }
  </style>
</head>
<body class="font-sans antialiased" x-data="{ activeTab: 'materials', viewMode: 'separate' }" x-init="const h = window.location.hash; if (h === '#quizzers') activeTab = 'quizzers'; else if (h === '#materials') activeTab = 'materials';">
  <?php include 'student_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2"><i class="bi bi-book"></i> <?php echo h($subject['subject_name']); ?></h1>
    <p class="text-gray-500 mt-1 mb-0"><?php echo h($subject['description'] ?? ''); ?></p>
  </div>

  <!-- Tabs (active tab persisted via URL hash so refresh keeps same tab) -->
  <nav class="flex flex-wrap gap-2 mb-4" role="tablist">
    <button type="button" @click="activeTab = 'materials'; window.location.hash = '#materials'" :class="activeTab === 'materials' ? 'bg-primary text-white border-primary' : 'bg-gray-100 text-gray-700 border-gray-200 hover:bg-gray-200'" class="subject-tab-btn px-4 py-2 rounded-lg font-medium border-2 inline-flex items-center gap-2"><i class="bi bi-collection-play"></i> Materials (Videos + Handouts)</button>
    <button type="button" @click="activeTab = 'quizzers'; window.location.hash = '#quizzers'" :class="activeTab === 'quizzers' ? 'bg-primary text-white border-primary' : 'bg-gray-100 text-gray-700 border-gray-200 hover:bg-gray-200'" class="subject-tab-btn px-4 py-2 rounded-lg font-medium border-2 inline-flex items-center gap-2"><i class="bi bi-question-circle"></i> Quizzers</button>
  </nav>

  <!-- Split View Overlay -->
  <div class="split-view-container" :class="{ 'active': viewMode === 'split' }">
    <button type="button" @click="viewMode = 'separate'" class="absolute top-4 right-4 z-10 px-3 py-1.5 rounded-lg text-sm font-semibold bg-red-600 text-white hover:bg-red-700 transition" title="Close Split View"><i class="bi bi-x-lg"></i></button>
    <div class="split-panel bg-white rounded-xl shadow-card overflow-hidden">
      <div class="px-3 py-2 border-b border-gray-100 text-sm font-semibold text-gray-800"><i class="bi bi-play-circle mr-2"></i> Video Player</div>
      <div id="splitVideoPlayer" class="flex-1 min-h-0 bg-gray-900 flex items-center justify-center text-gray-500">
        <div class="text-center py-8"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>Select a lesson to load materials.</p></div>
      </div>
    </div>
    <div class="split-panel bg-white rounded-xl shadow-card overflow-hidden" style="border-left: 2px solid #e5e7eb;">
      <div class="px-3 py-2 border-b border-gray-100 flex justify-between items-center flex-wrap gap-2">
        <span class="text-sm font-semibold text-gray-800"><i class="bi bi-file-earmark-pdf mr-2"></i> Handout</span>
        <select id="splitHandoutSelect" class="rounded-lg border border-gray-300 px-2 py-1 text-sm min-w-[180px]">
          <option value="">Select Handout...</option>
        </select>
      </div>
      <div class="flex-1 min-h-0 relative">
        <iframe id="splitHandoutFrame" class="absolute inset-0 w-full h-full border-0 rounded-b-xl bg-white" style="display: none;"></iframe>
        <div id="splitHandoutEmpty" class="absolute inset-0 flex items-center justify-center flex-col text-gray-500"><i class="bi bi-file-earmark-pdf text-4xl block mb-2"></i><p>Select a handout to view</p></div>
      </div>
    </div>
  </div>

  <!-- Tab: Materials -->
  <div x-show="activeTab === 'materials'" x-cloak class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <div class="lg:col-span-4">
        <div class="bg-white rounded-xl border border-gray-100 overflow-hidden max-h-[60vh] overflow-y-auto">
          <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-800"><i class="bi bi-file-text mr-2"></i> Lessons</div>
          <div class="divide-y divide-gray-100">
            <?php mysqli_data_seek($lessons, 0); while ($l = mysqli_fetch_assoc($lessons)): ?>
              <a href="?subject_id=<?php echo (int)$subjectId; ?>#materials" onclick="loadLessonMaterials(<?php echo (int)$l['lesson_id']; ?>); return false;" class="block px-4 py-3 hover:bg-gray-50 text-gray-700 transition"><?php echo h($l['title']); ?></a>
            <?php endwhile; ?>
          </div>
        </div>
      </div>
      <div class="lg:col-span-8">
        <div id="viewToggleButtons" class="hidden gap-2 mb-3">
          <button type="button" @click="viewMode = 'separate'" :class="viewMode === 'separate' ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-600'" class="px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition"><i class="bi bi-layout-split"></i> Separate</button>
          <button type="button" @click="viewMode = 'split'" :class="viewMode === 'split' ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-600'" class="px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition"><i class="bi bi-columns"></i> Split View</button>
        </div>
        <div class="border border-gray-100 rounded-xl overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100 flex justify-between items-center">
            <span class="font-semibold text-gray-800"><i class="bi bi-list mr-2"></i> Playlist</span>
            <span class="text-gray-500 text-sm" id="lessonTitle"></span>
          </div>
          <div class="p-4">
            <div id="videoPlayer" class="mb-4">
              <div class="text-center text-gray-500 py-12"><i class="bi bi-play-circle text-4xl block mb-2"></i><p class="mt-2 mb-0">Select a lesson to load materials.</p></div>
            </div>
            <div id="videoList" class="space-y-1 mb-4"></div>
            <hr class="my-4 border-gray-200">
            <h4 class="font-semibold text-gray-800 mb-3"><i class="bi bi-file-earmark-pdf mr-2"></i> Handouts</h4>
            <div id="handoutList" class="grid grid-cols-1 md:grid-cols-2 gap-3"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tab: Quizzers (dashboard style with summary + table) -->
  <div x-show="activeTab === 'quizzers'" x-cloak
       x-transition:enter="tab-panel-enter-active"
       x-transition:enter-start="tab-panel-enter"
       x-transition:enter-end="tab-panel-enter-to"
       x-transition:leave="tab-panel-leave-active"
       x-transition:leave-start="tab-panel-leave"
       x-transition:leave-end="tab-panel-leave-to"
       class="quizzers-section bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-5">
    <div class="quizzers-header">
      <h2 class="quizzers-title"><i class="bi bi-question-circle-fill text-primary"></i> Quizzers</h2>
      <p class="quizzers-subtitle">Take or review your quizzes. Passing score is 50%.</p>
    </div>
    <?php if ($totalQuizzes > 0): ?>
    <div class="quiz-summary-grid">
      <div class="quiz-summary-card">
        <span class="summary-value"><?php echo $totalQuizzes; ?></span>
        <span class="summary-label">Total Quizzes</span>
      </div>
      <div class="quiz-summary-card summary-passed">
        <span class="summary-value"><?php echo $quizzesPassed; ?></span>
        <span class="summary-label">Passed</span>
      </div>
      <div class="quiz-summary-card summary-failed">
        <span class="summary-value"><?php echo $quizzesFailed; ?></span>
        <span class="summary-label">Failed</span>
      </div>
      <div class="quiz-summary-card summary-avg">
        <span class="summary-value"><?php echo $averageScore !== null ? $averageScore . '%' : '—'; ?></span>
        <span class="summary-label">Average Score</span>
      </div>
    </div>
    <?php endif; ?>
    <div class="quiz-table-wrap">
      <table class="quiz-table">
        <thead>
          <tr>
            <th style="min-width: 220px;">Quiz Title</th>
            <th class="w-24">Duration</th>
            <th style="min-width: 120px;">Last Attempt</th>
            <th class="w-28">Status</th>
            <th class="w-24">Attempts</th>
            <th style="min-width: 220px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          mysqli_data_seek($quizzesAll, 0);
          $hasQuiz = false;
          while ($q = mysqli_fetch_assoc($quizzesAll)):
            $qid = (int)$q['quiz_id'];
            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM quiz_questions WHERE quiz_id=?");
            mysqli_stmt_bind_param($stmt, 'i', $qid);
            mysqli_stmt_execute($stmt);
            $cResult = mysqli_stmt_get_result($stmt);
            $rc = mysqli_fetch_assoc($cResult);
            mysqli_stmt_close($stmt);
            $cnt = (int)($rc['cnt'] ?? 0);
            $timeLimitSeconds = getQuizTimeLimitSeconds($q);
            $inProgressAttemptId = $attemptsByQuiz[$qid] ?? null;
            $lastScore = $lastScoreByQuiz[$qid] ?? null;
            $lastAttemptDate = $lastAttemptDateByQuiz[$qid] ?? null;
            $attemptCount = (int)($attemptCountByQuiz[$qid] ?? 0);
            $scorePct = $lastScore !== null && $lastScore['total'] > 0 ? round(100 * $lastScore['correct'] / $lastScore['total']) : null;
            $passed = $scorePct !== null && $scorePct >= 50;
            $takeUrl = 'student_take_quiz.php?quiz_id='.$qid.'&subject_id='.(int)$subjectId;
            $viewResultUrl = 'student_take_quiz.php?quiz_id='.$qid.'&subject_id='.(int)$subjectId.'&view_result=1';
            $hasQuiz = true;
            $rowStatus = ($cnt <= 0) ? 'no_questions' : ($inProgressAttemptId ? 'in_progress' : ($passed ? 'passed' : 'need_retake'));
            $notTaken = ($cnt > 0 && !$inProgressAttemptId && $attemptCount === 0);
            if ($notTaken) $rowStatus = 'not_taken';
            $statusBadgeClass = $rowStatus === 'passed' ? 'passed' : ($rowStatus === 'need_retake' ? 'failed' : ($rowStatus === 'in_progress' ? 'in-progress' : 'not-started'));
            $statusLabel = $rowStatus === 'need_retake' ? 'Failed' : ($rowStatus === 'not_taken' ? 'Not started' : ucfirst(str_replace('_', ' ', $rowStatus)));
          ?>
            <tr class="quiz-table-row">
              <td class="break-words" style="min-width: 220px;"><?php echo h($q['title']); ?></td>
              <td><?php echo formatTimeLimitSeconds($timeLimitSeconds); ?></td>
              <td>
                <?php if ($lastAttemptDate): ?>
                  <div class="quiz-last-score-cell">
                    <?php if ($scorePct !== null): ?>
                      <span class="quiz-last-score-label">Last Score</span>
                      <span class="quiz-score-badge-lg <?php echo $passed ? 'pass' : 'fail'; ?>"><?php echo $scorePct; ?>%</span>
                    <?php endif; ?>
                    <span class="text-xs text-gray-500 whitespace-nowrap"><?php echo date('M j, Y', strtotime($lastAttemptDate)); ?></span>
                  </div>
                <?php else: ?>
                  <span class="text-gray-400">—</span>
                <?php endif; ?>
              </td>
              <td><span class="quiz-status-badge <?php echo $statusBadgeClass; ?>"><?php echo h($statusLabel); ?></span></td>
              <td><?php echo $attemptCount; ?></td>
              <td>
                <div class="flex flex-nowrap justify-center items-center gap-2">
                <?php if ($cnt <= 0): ?>
                  <span class="text-gray-400 text-xs">No questions yet</span>
                <?php elseif ($inProgressAttemptId): ?>
                  <a href="<?php echo $takeUrl; ?>&attempt_id=<?php echo $inProgressAttemptId; ?>" class="quiz-btn quiz-btn-amber quiz-resume-link shrink-0"><i class="bi bi-play-fill"></i> Resume</a>
                <?php else: ?>
                  <?php if ($attemptCount > 0): ?>
                    <a href="<?php echo $viewResultUrl; ?>" class="quiz-btn quiz-btn-outline shrink-0"><i class="bi bi-search"></i> View Result</a>
                  <?php endif; ?>
                  <?php if ($passed): ?>
                    <a href="<?php echo $takeUrl; ?>&retake=1" class="quiz-btn quiz-btn-primary quiz-take-again-btn shrink-0"><i class="bi bi-arrow-repeat"></i> Take Again</a>
                  <?php else: ?>
                    <a href="<?php echo $takeUrl; ?>" class="quiz-btn quiz-btn-primary quiz-take-again-btn shrink-0"><i class="bi bi-play-fill"></i> <?php echo $attemptCount > 0 ? 'Take Again' : 'Take Quiz'; ?></a>
                  <?php endif; ?>
                <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasQuiz): ?>
            <tr>
              <td colspan="6" class="quiz-empty-state">
                <i class="bi bi-inbox quiz-empty-icon"></i>
                <p class="quiz-empty-msg">No quizzes available yet.</p>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- App modal: Resume quiz (dark gradient format) -->
  <div class="quiz-confirm-overlay" id="quizResumeConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="quizResumeConfirmTitle" aria-describedby="quizResumeConfirmMessage">
    <div class="quiz-confirm-card">
      <div class="quiz-confirm-icon-wrap info">
        <i class="bi bi-play-circle-fill"></i>
      </div>
      <div class="quiz-confirm-header" id="quizResumeConfirmTitle">Resume quiz</div>
      <div class="quiz-confirm-body" id="quizResumeConfirmMessage">
        You're resuming your quiz. Your progress is saved.<br><br>Continue to quiz?
      </div>
      <div class="quiz-confirm-actions">
        <button type="button" class="quiz-confirm-btn quiz-confirm-btn-cancel" id="quizResumeConfirmCancel">Cancel</button>
        <button type="button" class="quiz-confirm-btn quiz-confirm-btn-primary" id="quizResumeConfirmContinue">Continue</button>
      </div>
    </div>
  </div>

  <!-- Handout Viewer Modal (Alpine store driven from openHandoutViewer()) -->
  <div x-show="$store.handoutViewer && $store.handoutViewer.open" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="$store.handoutViewer && ($store.handoutViewer.open = false)">
    <div class="absolute inset-0 bg-black/50" @click="$store.handoutViewer && ($store.handoutViewer.open = false)"></div>
    <div class="relative bg-white rounded-xl shadow-modal w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col" @click.stop>
      <div class="p-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="($store.handoutViewer && $store.handoutViewer.title) || 'Handout'"></h2>
        <button type="button" @click="$store.handoutViewer && ($store.handoutViewer.open = false, $store.handoutViewer.id = '')" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="flex-1 min-h-0 relative" style="aspect-ratio: 4/3;">
        <iframe x-show="$store.handoutViewer && $store.handoutViewer.id" :src="($store.handoutViewer && $store.handoutViewer.id) ? ('handout_viewer.php?handout_id=' + $store.handoutViewer.id) : ''" class="absolute inset-0 w-full h-full border-0 rounded-b-xl" allowfullscreen></iframe>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('alpine:init', function() {
      Alpine.store('handoutViewer', { open: false, id: '', title: '' });
      });
    window.openHandoutViewer = function(handoutId, title) {
      if (!handoutId) return;
      if (typeof Alpine !== 'undefined' && Alpine.store && Alpine.store('handoutViewer')) {
        Alpine.store('handoutViewer').open = true;
        Alpine.store('handoutViewer').id = String(handoutId);
        Alpine.store('handoutViewer').title = title || 'Handout Preview';
      }
    };

    document.addEventListener('DOMContentLoaded', function() {
      const btnSeparateView = document.getElementById('btnSeparateView');
      const btnSplitView = document.getElementById('btnSplitView');
      const viewToggleButtons = document.getElementById('viewToggleButtons');
      const splitHandoutSelect = document.getElementById('splitHandoutSelect');
      const splitHandoutFrame = document.getElementById('splitHandoutFrame');
      const splitHandoutEmpty = document.getElementById('splitHandoutEmpty');
      if (splitHandoutSelect && splitHandoutFrame && splitHandoutEmpty) {
        splitHandoutSelect.addEventListener('change', function() {
          const val = this.value;
          if (val) {
            splitHandoutFrame.src = 'handout_viewer.php?handout_id=' + encodeURIComponent(val);
            splitHandoutFrame.style.display = 'block';
            splitHandoutEmpty.style.display = 'none';
          } else {
            splitHandoutFrame.src = '';
            splitHandoutFrame.style.display = 'none';
            splitHandoutEmpty.style.display = 'flex';
          }
        });
      }
    });

    async function loadLessonMaterials(lessonId) {
      const titleEl = document.getElementById('lessonTitle');
      const videoListEl = document.getElementById('videoList');
      const videoPlayerEl = document.getElementById('videoPlayer');
      const handoutListEl = document.getElementById('handoutList');
      const splitVideoPlayerEl = document.getElementById('splitVideoPlayer');
      const viewToggleButtons = document.getElementById('viewToggleButtons');
      if (viewToggleButtons) viewToggleButtons.style.display = 'flex';
      titleEl.textContent = '';
      videoListEl.innerHTML = '';
      videoPlayerEl.innerHTML = '<div class="text-center text-gray-500 py-8"><span class="inline-block w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin"></span><p class="mt-3 mb-0">Loading videos...</p></div>';
      if (splitVideoPlayerEl) splitVideoPlayerEl.innerHTML = '<div class="text-center text-gray-500 py-8" style="height:100%;display:flex;align-items:center;justify-content:center;flex-direction:column"><span class="inline-block w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin"></span><p class="mt-3 mb-0">Loading videos...</p></div>';
      handoutListEl.innerHTML = '';
      try {
        const [vRes, hRes] = await Promise.all([
          fetch('subject_api.php?action=videos&lesson_id=' + lessonId, { credentials: 'same-origin' }),
          fetch('subject_api.php?action=handouts&lesson_id=' + lessonId, { credentials: 'same-origin' })
        ]);
        if (!vRes.ok || !hRes.ok) throw new Error('Request failed');
        const videos = await vRes.json();
        const handouts = await hRes.json();
        if (videos.error) throw new Error(videos.error);
        if (handouts.error) throw new Error(handouts.error);
        titleEl.textContent = (videos.lesson && videos.lesson.title) || '';
        const videoItems = Array.isArray(videos.videos) ? videos.videos : [];
        if (videoItems.length === 0) {
          videoPlayerEl.replaceChildren(renderEmptyState('No videos.', false, 'bi bi-play-circle'));
          if (splitVideoPlayerEl) splitVideoPlayerEl.innerHTML = '<div class="text-center text-gray-500 py-8"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>No videos available.</p></div>';
        } else {
          renderVideoPlayer(videoItems[0].video_url || '');
          renderSplitVideoPlayer(videoItems[0].video_url || '');
          videoItems.forEach(v => {
            const link = document.createElement('a');
            link.href = '#'; link.className = 'flex items-center gap-2 px-4 py-3 rounded-lg hover:bg-gray-50 text-gray-700 transition';
            const icon = document.createElement('i'); icon.className = 'bi bi-play-circle'; link.appendChild(icon);
            link.appendChild(document.createTextNode(' ' + (v.video_title || 'Untitled Video')));
            link.addEventListener('click', (e) => { e.preventDefault(); renderVideoPlayer(v.video_url || ''); renderSplitVideoPlayer(v.video_url || ''); });
            videoListEl.appendChild(link);
          });
        }
        const handoutItems = Array.isArray(handouts.handouts) ? handouts.handouts : [];
        const splitHandoutSelect = document.getElementById('splitHandoutSelect');
        const splitHandoutFrame = document.getElementById('splitHandoutFrame');
        const splitHandoutEmpty = document.getElementById('splitHandoutEmpty');
        if (splitHandoutSelect) {
          splitHandoutSelect.innerHTML = '<option value="">Select Handout...</option>';
          handoutItems.forEach(h => {
            if (h.file_path && h.handout_id) {
              const opt = document.createElement('option');
              opt.value = h.handout_id;
              opt.textContent = h.handout_title || 'Untitled Handout';
              splitHandoutSelect.appendChild(opt);
            }
          });
          if (handoutItems.length > 0 && handoutItems[0].handout_id) {
            splitHandoutSelect.value = handoutItems[0].handout_id;
            if (splitHandoutFrame) { splitHandoutFrame.src = 'handout_viewer.php?handout_id=' + handoutItems[0].handout_id; splitHandoutFrame.style.display = 'block'; }
            if (splitHandoutEmpty) splitHandoutEmpty.style.display = 'none';
          } else {
            if (splitHandoutFrame) splitHandoutFrame.style.display = 'none';
            if (splitHandoutEmpty) splitHandoutEmpty.style.display = 'flex';
          }
        }
        if (handoutItems.length === 0) {
          handoutListEl.appendChild(renderEmptyState('No handouts.', true));
        } else {
          handoutItems.forEach(h => {
            const col = document.createElement('div');
            col.className = 'border border-gray-100 rounded-xl p-4';
            const title = document.createElement('h6');
            title.className = 'font-semibold text-gray-800 mb-1';
            title.textContent = h.handout_title || 'Untitled Handout';
            col.appendChild(title);
            if (Number(h.file_size)) {
              const size = document.createElement('div');
              size.className = 'text-gray-500 text-sm mb-2';
              size.textContent = formatFileSize(Number(h.file_size));
              col.appendChild(size);
            }
            const actions = document.createElement('div');
            actions.className = 'flex flex-wrap gap-2 mt-3';
            if (h.file_path) {
              const viewBtn = document.createElement('button');
              viewBtn.type = 'button';
              viewBtn.className = 'inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition';
              viewBtn.textContent = 'View';
              viewBtn.addEventListener('click', () => openHandoutViewer(h.handout_id, h.handout_title || 'Handout'));
              actions.appendChild(viewBtn);
            }
            if (Number(h.allow_download) === 1 && h.file_path) {
              const a = document.createElement('a');
              a.className = 'inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition';
              a.href = h.file_path; a.target = '_blank'; a.rel = 'noopener';
              a.innerHTML = '<i class="bi bi-download"></i> Download';
              actions.appendChild(a);
            } else if (Number(h.allow_download) !== 1) {
              const lock = document.createElement('div');
              lock.className = 'p-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2';
              lock.innerHTML = '<i class="bi bi-lock"></i> Downloads locked by administrator.';
              actions.appendChild(lock);
            }
            col.appendChild(actions);
            handoutListEl.appendChild(col);
          });
        }
      } catch (e) {
        videoPlayerEl.replaceChildren(renderErrorState('Failed to load. ' + (e.message || '')));
        handoutListEl.appendChild(renderErrorState('Failed to load handouts. ' + (e.message || '')));
      }
    }
    function renderVideoPlayer(url) {
      const el = document.getElementById('videoPlayer');
      el.innerHTML = '';
      if (!url) { el.appendChild(renderEmptyState('No videos.', false, 'bi bi-play-circle')); return; }
      const isLocal = url.indexOf('uploads/videos/') === 0;
      let embedUrl = url;
      if (!isLocal && (url.includes('youtube.com') || url.includes('youtu.be'))) { const m = url.match(/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/); if (m) embedUrl = 'https://www.youtube.com/embed/' + m[1] + '?rel=0'; }
      else if (!isLocal && url.includes('vimeo.com')) { const m = url.match(/vimeo.com\/(\d+)/); if (m) embedUrl = 'https://player.vimeo.com/video/' + m[1]; }
      if (isLocal) {
        const v = document.createElement('video');
        v.className = 'video-embed'; v.controls = true;
        const src = document.createElement('source'); src.src = embedUrl; src.type = 'video/mp4';
        v.appendChild(src); el.appendChild(v);
      } else {
        const iframe = document.createElement('iframe');
        iframe.className = 'video-embed'; iframe.src = embedUrl; iframe.allowFullscreen = true;
        el.appendChild(iframe);
      }
    }
    function renderSplitVideoPlayer(url) {
      const el = document.getElementById('splitVideoPlayer');
      if (!el) return;
      el.innerHTML = '';
      if (!url) { el.innerHTML = '<div class="text-center text-gray-500 py-8"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>No video selected.</p></div>'; return; }
      const isLocal = url.indexOf('uploads/videos/') === 0;
      let embedUrl = url;
      if (!isLocal && (url.includes('youtube.com') || url.includes('youtu.be'))) { const m = url.match(/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/); if (m) embedUrl = 'https://www.youtube.com/embed/' + m[1] + '?rel=0'; }
      else if (!isLocal && url.includes('vimeo.com')) { const m = url.match(/vimeo.com\/(\d+)/); if (m) embedUrl = 'https://player.vimeo.com/video/' + m[1]; }
      if (isLocal) {
        const v = document.createElement('video');
        v.className = 'video-embed'; v.controls = true; v.style.height = '100%';
        const src = document.createElement('source'); src.src = embedUrl; src.type = 'video/mp4';
        v.appendChild(src); el.appendChild(v);
      } else {
        const iframe = document.createElement('iframe');
        iframe.className = 'video-embed'; iframe.src = embedUrl; iframe.allowFullscreen = true; iframe.style.height = '100%';
        el.appendChild(iframe);
      }
    }
    function renderEmptyState(message, wrapInColumn, iconClass) {
      const div = document.createElement('div');
      div.className = 'text-center text-gray-500 py-8';
      const icon = document.createElement('i');
      icon.className = iconClass || 'bi bi-inbox';
      icon.style.fontSize = '2.5rem';
      div.appendChild(icon);
      const p = document.createElement('p');
      p.className = 'mt-2 mb-0';
      p.textContent = message;
      div.appendChild(p);
      if (wrapInColumn) {
        const col = document.createElement('div');
        col.className = 'col-span-2';
        col.appendChild(div);
        return col;
      }
      return div;
    }
    function renderErrorState(message) {
      const col = document.createElement('div');
      col.className = 'col-span-2';
      const alert = document.createElement('div');
      alert.className = 'p-4 rounded-xl bg-red-50 border border-red-200 text-red-800';
      alert.textContent = message;
      col.appendChild(alert);
      return col;
    }
    function formatFileSize(bytes) {
      if (!bytes || isNaN(bytes)) return '';
      const units = ['B', 'KB', 'MB', 'GB'];
      let size = bytes;
      let i = 0;
      while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
      return size.toFixed(size >= 10 || i === 0 ? 0 : 1) + ' ' + units[i];
    }
  </script>
  <script>
  (function() {
    var overlay = document.getElementById('quizResumeConfirmOverlay');
    var cancelBtn = document.getElementById('quizResumeConfirmCancel');
    var continueBtn = document.getElementById('quizResumeConfirmContinue');
    var resumeUrl = '';
    function hide() {
      if (overlay) overlay.classList.remove('show');
    }
    function show(url) {
      resumeUrl = url || '';
      if (overlay) overlay.classList.add('show');
    }
    document.addEventListener('click', function(e) {
      var link = e.target.closest('a.quiz-resume-link');
      if (!link || !link.href) return;
      e.preventDefault();
      show(link.href);
    });
    if (cancelBtn) cancelBtn.addEventListener('click', hide);
    if (continueBtn) continueBtn.addEventListener('click', function() {
      hide();
      if (resumeUrl) window.location.href = resumeUrl;
    });
    if (overlay) overlay.addEventListener('click', function(e) {
      if (e.target === overlay) hide();
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && overlay && overlay.classList.contains('show')) hide();
    });
  })();
  </script>
</main>
</body>
</html>
