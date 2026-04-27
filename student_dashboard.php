<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/profile_avatar.php';
requireRole('student');
$pageTitle = 'Student Dashboard';
$csrf = generateCSRFToken();

$hasWeeklyGoalCol = false;
$wgCol = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'weekly_activity_goal'");
if ($wgCol && mysqli_fetch_assoc($wgCol)) {
    $hasWeeklyGoalCol = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['weekly_activity_goal_save'])) {
    $uidPost = getCurrentUserId();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
    } elseif ($hasWeeklyGoalCol && $uidPost) {
        $g = max(0, min(50, (int)($_POST['weekly_activity_goal'] ?? 0)));
        $st = mysqli_prepare($conn, 'UPDATE users SET weekly_activity_goal = ? WHERE user_id = ? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $g, $uidPost);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            $_SESSION['success'] = $g > 0 ? 'Weekly goal saved.' : 'Weekly goal cleared.';
        }
    }
    header('Location: student_dashboard.php');
    exit;
}

$lastLoginAt = null;
$uid = getCurrentUserId();
$weeklyActivityGoal = 0;
if ($hasWeeklyGoalCol && $uid) {
    $wgRes = @mysqli_query($conn, 'SELECT weekly_activity_goal AS g FROM users WHERE user_id = ' . (int)$uid . ' LIMIT 1');
    if ($wgRes && ($wgr = mysqli_fetch_assoc($wgRes))) {
        $weeklyActivityGoal = max(0, min(50, (int)($wgr['g'] ?? 0)));
    }
}
$dashboardAvatarPath = '';
$dashboardUseDefaultAvatar = 1;
$dashboardAvatarInitial = ereview_avatar_initial($_SESSION['full_name'] ?? 'U');

$tableExists = static function (mysqli $conn, string $table): bool {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') return false;
    $res = @mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
};

try {
    $hasProfilePicture = false;
    $hasDefaultAvatar = false;
    $cp1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($cp1 && mysqli_fetch_assoc($cp1)) $hasProfilePicture = true;
    $cp2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
    if ($cp2 && mysqli_fetch_assoc($cp2)) $hasDefaultAvatar = true;

    $select = 'SELECT last_login_at, access_start, access_end';
    if ($hasProfilePicture) $select .= ', profile_picture';
    if ($hasDefaultAvatar) $select .= ', use_default_avatar';
    $select .= ' FROM users WHERE user_id = ? LIMIT 1';

    $stmt = mysqli_prepare($conn, $select);
    $info = null;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if ($row) {
                $info = $row;
                $lastLoginAt = !empty($row['last_login_at']) ? (string)$row['last_login_at'] : null;
                $dashboardAvatarPath = ereview_avatar_public_path($row['profile_picture'] ?? '');
                if ($hasDefaultAvatar) {
                    $dashboardUseDefaultAvatar = !empty($row['use_default_avatar']) ? 1 : 0;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
} catch (mysqli_sql_exception $e) {
    $info = null;
}

require_once __DIR__ . '/includes/student_dashboard_aggregate.php';
$dash = ereview_student_dashboard_aggregate($conn, $uid, $tableExists, $info, $weeklyActivityGoal);
foreach ($dash as $_k => $_v) {
    ${$_k} = $_v;
}

$chartJsLocal = __DIR__ . '/assets/vendor/chart.js/4.4.1/chart.umd.min.js';
$chartJsLocalWeb = 'assets/vendor/chart.js/4.4.1/chart.umd.min.js';
$chartJsUseLocal = is_file($chartJsLocal);
$chartJsSriCdn = 'sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4';
$dashBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$dashboardPrefetchJson = isset($_GET['dashboard_prefetch']) && $_GET['dashboard_prefetch'] !== '0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased dash-page dash-page--loading<?php echo $dashboardPrefetchJson ? ' dash-async-fetch' : ''; ?>" data-dashboard-data-url="<?php echo h($dashBase); ?>/api/student/dashboard_data.php">
  <?php include 'student_sidebar.php'; ?>
  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7">
      <div class="relative z-10 text-white">
        <div class="dash-hero-masthead">
          <div class="dash-hero-avatar-col">
            <div class="dash-hero-avatar-ring" aria-hidden="true">
              <?php if ($dashboardAvatarPath !== '' && !$dashboardUseDefaultAvatar): ?>
                <img src="<?php echo h($dashboardAvatarPath); ?>" alt="Your profile photo" class="dash-hero-avatar-img" width="160" height="160" loading="eager" decoding="async">
              <?php else: ?>
                <span class="dash-hero-avatar-initial"><?php echo h($dashboardAvatarInitial); ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="dash-hero-copy-col min-w-0">
            <h1 class="dash-hero-title m-0 text-2xl sm:text-3xl lg:text-[1.85rem] font-extrabold leading-tight tracking-tight">
              <?php echo h($greeting); ?>, <?php echo h($_SESSION['full_name']); ?>
            </h1>
            <p class="dash-hero-lede mt-2 mb-0 max-w-2xl text-white/90 text-sm sm:text-base font-medium leading-relaxed">A smarter learning overview with your real-time review activity and progress.</p>
            <div class="hero-chip-row mt-3 flex flex-wrap gap-2" aria-label="Quick stats">
          <span class="hero-chip"><i class="bi bi-fire"></i> <?php echo (int)$weeksActiveStreak; ?> active week<?php echo $weeksActiveStreak === 1 ? '' : 's'; ?> streak</span>
          <span class="hero-chip"><i class="bi bi-calendar-check"></i> <?php echo (int)$daysActiveStreak; ?> day submission streak</span>
          <?php if ($weeklyActivityGoal > 0): ?>
            <span class="hero-chip hero-chip--goal"><i class="bi bi-bullseye"></i> This week: <?php echo (int)$goalThisWeekCount; ?> / <?php echo (int)$weeklyActivityGoal; ?> toward your goal</span>
          <?php endif; ?>
          <?php if (count($last5Scores) > 0): ?>
            <span class="hero-chip hero-chip--score"><i class="bi bi-<?php echo $last5Trend === 'up' ? 'graph-up-arrow' : ($last5Trend === 'down' ? 'graph-down-arrow' : 'dash'); ?>"></i> Last <?php echo count($last5Scores); ?> quizzes avg <?php echo (int)$last5Avg; ?>%</span>
          <?php endif; ?>
        </div>
        <div class="hero-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
          <span class="font-semibold">Active subjects: <?php echo (int)$subjectsCount; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Lessons: <?php echo (int)$lessonsCount; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Quiz submissions: <?php echo (int)$quizSubmittedCount; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Preboards done: <?php echo (int)$preboardsSubmittedCount; ?></span>
          <?php if ($lastLoginAt): ?>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Last login: <?php echo h(date('M j, g:i A', strtotime($lastLoginAt))); ?></span>
          <?php endif; ?>
        </div>
          </div>
        </div>
      </div>
    </section>

    <h2 class="section-title dash-section-title dash-anim delay-2" id="dash-section-learning-overview"><span class="dash-section-title__icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span><span class="dash-section-title__text">Learning Overview</span></h2>
    <section class="dash-kpi-grid dash-kpi-strip grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-7">
      <article class="dash-card kpi-card dash-kpi-card dash-kpi-card--subjects dash-anim delay-2 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-[#e8f2fa] text-[#1665A0]"><i class="bi bi-journal-bookmark"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Subjects</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$subjectsCount; ?></p>
          </div>
        </div>
        <a href="student_subjects.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Manage subjects</a>
      </article>
      <article class="dash-card kpi-card dash-kpi-card dash-kpi-card--lessons dash-anim delay-2 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-cyan-50 text-cyan-700"><i class="bi bi-journal-text"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Lessons</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$lessonsCount; ?></p>
          </div>
        </div>
        <a href="student_subjects.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Continue learning</a>
      </article>
      <article class="dash-card kpi-card dash-kpi-card dash-kpi-card--quizzes dash-anim delay-3 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-emerald-50 text-emerald-700"><i class="bi bi-check2-circle"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Quiz completed</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$quizSubmittedCount; ?></p>
          </div>
        </div>
        <a href="student_subjects.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> View quizzes</a>
      </article>
      <article class="dash-card kpi-card dash-kpi-card dash-kpi-card--preboards dash-anim delay-3 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-indigo-50 text-indigo-700"><i class="bi bi-clipboard-check"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Preboards done</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$preboardsSubmittedCount; ?></p>
          </div>
        </div>
        <a href="student_preboards.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Open preboards</a>
      </article>
    </section>

    <?php
    $flashOk = !empty($_SESSION['success']) ? (string)$_SESSION['success'] : '';
    $flashErr = !empty($_SESSION['error']) ? (string)$_SESSION['error'] : '';
    unset($_SESSION['success'], $_SESSION['error']);
    ?>
    <?php if ($flashOk !== '' || $flashErr !== ''): ?>
    <div class="dash-flash dash-anim delay-2 mb-4 px-4 py-3 rounded-xl border <?php echo $flashErr !== '' ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900'; ?>" role="status">
      <?php echo h($flashErr !== '' ? $flashErr : $flashOk); ?>
    </div>
    <?php endif; ?>

    <section id="dash-insights" class="dash-insights-section">
      <h2 class="section-title dash-section-title dash-section-title--insights dash-anim delay-3" id="dash-section-insights"><span class="dash-section-title__icon" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span><span class="dash-section-title__text">Insights</span></h2>
      <div class="dash-insights-grid">
        <div class="dash-insights-primary min-w-0">
        <article class="dash-card dash-anim delay-3 p-5 study-activity-card study-activity-card--dense<?php echo !$activityChartEmpty ? ' study-activity-card--has-chart' : ''; ?>">
          <div class="insight-snapshot">
            <h3 class="insight-snapshot__heading m-0" id="insight-snapshot-heading">Performance snapshot</h3>
            <?php if ($activityLast8Weeks === 0): ?>
            <p class="insight-snapshot__empty m-0 mt-2 text-sm text-slate-600 max-w-xl">Submit a quiz or preboard once — this panel will show totals, averages, and streaks at a glance.</p>
            <?php else:
                $snapScoreShown = false;
                $snapScoreVal = '';
                $snapScoreLbl = '';
                if (count($last5Scores) > 0) {
                    $snapScoreShown = true;
                    $snapScoreVal = (int)$last5Avg . '%';
                    $snapScoreLbl = 'Last ' . count($last5Scores) . ' quiz avg';
                } elseif ($avgQuizScore > 0) {
                    $snapScoreShown = true;
                    $snapScoreVal = (int)$avgQuizScore . '%';
                    $snapScoreLbl = 'Lifetime avg';
                }
                ?>
            <div class="insight-snapshot-grid mt-3" role="group" aria-label="Key activity metrics">
              <div class="insight-snap-tile">
                <span class="insight-snap-tile__val"><?php echo (int)$activityLast8Weeks; ?></span>
                <span class="insight-snap-tile__lbl">8-week total</span>
              </div>
              <div class="insight-snap-tile">
                <span class="insight-snap-tile__val"><?php echo (int)$currentWeekActivity; ?></span>
                <span class="insight-snap-tile__lbl">This week</span>
              </div>
              <?php if ($snapScoreShown): ?>
              <div class="insight-snap-tile insight-snap-tile--accent">
                <span class="insight-snap-tile__val"><?php echo h($snapScoreVal); ?></span>
                <span class="insight-snap-tile__lbl"><?php echo h($snapScoreLbl); ?></span>
              </div>
              <?php endif; ?>
              <div class="insight-snap-tile">
                <span class="insight-snap-tile__val insight-snap-tile__val--sm"><?php echo (int)$weeksActiveStreak; ?>w · <?php echo (int)$daysActiveStreak; ?>d</span>
                <span class="insight-snap-tile__lbl">Streaks <span class="insight-snap-tile__hint">(wk · day)</span></span>
              </div>
              <?php if ($weeklyActivityGoal > 0): ?>
              <div class="insight-snap-tile insight-snap-tile--goal">
                <span class="insight-snap-tile__val"><?php echo (int)$goalThisWeekCount; ?>/<?php echo (int)$weeklyActivityGoal; ?></span>
                <span class="insight-snap-tile__lbl">Weekly goal</span>
              </div>
              <?php endif; ?>
            </div>
            <p class="insight-snapshot__foot m-0 mt-2.5 text-xs text-slate-500">Updates as you submit quizzes and preboards.</p>
            <p class="sr-only"><?php
                $__sr = [
                    (int)$activityLast8Weeks . ' submissions in the last eight weeks',
                    (int)$currentWeekActivity . ' submissions this week',
                    (int)$weeksActiveStreak . '-week and ' . (int)$daysActiveStreak . '-day streaks',
                ];
                if ($snapScoreShown) {
                    $__sr[] = $snapScoreLbl . ' ' . $snapScoreVal;
                }
                if ($weeklyActivityGoal > 0) {
                    $__sr[] = 'weekly goal ' . (int)$goalThisWeekCount . ' of ' . (int)$weeklyActivityGoal;
                }
                echo h(implode('. ', $__sr)) . '.';
            ?></p>
            <?php endif; ?>
          </div>
          <?php if (count($last5Scores) > 0): ?>
          <div class="insight-recents mt-3">
            <div class="insight-recents__top">
              <span class="insight-recents__title">Recent quiz scores</span>
              <span class="insight-recents__trend insight-recents__trend--<?php echo $last5Trend === 'up' ? 'up' : ($last5Trend === 'down' ? 'down' : 'flat'); ?>"><?php echo $last5Trend === 'up' ? 'Trending up' : ($last5Trend === 'down' ? 'Cooling' : 'Steady'); ?></span>
            </div>
            <?php if (count($last5Scores) === 1):
                $__only = (int)round((float)($last5Scores[0]['score'] ?? 0));
                $__only = max(0, min(100, $__only));
                ?>
            <div class="insight-recents__single" role="img" aria-label="Latest quiz score <?php echo $__only; ?> percent">
              <div class="insight-recents__single-row">
                <span class="insight-recents__single-label">Latest quiz</span>
                <span class="insight-recents__single-pct"><?php echo $__only; ?>%</span>
              </div>
              <div class="insight-recents__meter" aria-hidden="true">
                <span class="insight-recents__meter-fill" data-recent-quiz-width="<?php echo $__only; ?>" style="width: 0%"></span>
              </div>
              <p class="insight-recents__single-hint m-0 mt-2 text-xs text-slate-500">With more completed quizzes, a small bar chart will appear here (newest on the left).</p>
            </div>
            <?php else: ?>
            <div class="insight-recents__spark" role="img" aria-label="Recent quiz score bars, newest on the left">
              <?php foreach ($last5Scores as $ls):
                  $sc = (int)round((float)($ls['score'] ?? 0));
                  $h = max(12, min(44, (int)round($sc * 0.42)));
              ?>
              <span class="insight-spark__bar" style="height:<?php echo $h; ?>px" title="<?php echo (int)$sc; ?>%"></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($wkLen > 1): ?>
          <p class="insight-wowline m-0 mt-2 text-sm <?php echo $activityWoW > 0 ? 'insight-wowline--up' : ($activityWoW < 0 ? 'insight-wowline--down' : 'insight-wowline--flat'); ?>">
            <i class="bi <?php echo $activityWoW > 0 ? 'bi-arrow-up-right' : ($activityWoW < 0 ? 'bi-arrow-down-right' : 'bi-dash'); ?>" aria-hidden="true"></i>
            <?php if ($activityWoW > 0): ?>
              <span class="insight-wowline__txt"><strong>+<?php echo (int)$activityWoW; ?></strong> vs last week</span>
            <?php elseif ($activityWoW < 0): ?>
              <span class="insight-wowline__txt"><strong><?php echo (int)$activityWoW; ?></strong> vs last week</span>
            <?php else: ?>
              <span class="insight-wowline__txt">Same pace as last week</span>
            <?php endif; ?>
          </p>
          <?php endif; ?>

          <?php if ($hasWeeklyGoalCol): ?>
          <form method="post" class="insight-goal-form mt-4 p-3 rounded-xl border border-[#d6e8f7] bg-[#f8fbff]" action="student_dashboard.php">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="weekly_activity_goal_save" value="1">
            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2" for="weekly_activity_goal">Weekly submission goal</label>
            <div class="flex flex-wrap items-end gap-2">
              <select name="weekly_activity_goal" id="weekly_activity_goal" class="insight-goal-select rounded-lg border border-[#cde2f4] px-3 py-2 text-sm font-semibold text-[#143D59] bg-white">
                <?php for ($gi = 0; $gi <= 20; $gi++): ?>
                  <option value="<?php echo $gi; ?>"<?php echo $weeklyActivityGoal === $gi ? ' selected' : ''; ?>><?php echo $gi === 0 ? 'No goal' : (string)$gi . ' / week'; ?></option>
                <?php endfor; ?>
              </select>
              <button type="submit" class="insight-goal-btn rounded-lg px-4 py-2 text-sm font-bold bg-[#1665A0] text-white border-0 shadow-sm">Save</button>
            </div>
            <?php if ($weeklyActivityGoal > 0): ?>
              <p class="text-xs text-slate-600 m-0 mt-2">Progress this week: <strong><?php echo (int)$goalThisWeekCount; ?></strong> / <?php echo (int)$weeklyActivityGoal; ?> (<?php echo (int)$goalProgressPct; ?>%).</p>
            <?php endif; ?>
          </form>
          <?php else: ?>
          <p class="insight-goal-fallback mt-4 text-xs text-slate-500 m-0">Weekly goals: available once your account has the latest database update.</p>
          <?php endif; ?>

          <?php if (count($subjectStudyHints) > 0): ?>
          <div class="insight-subjects mt-4">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500 m-0 mb-2">Where to study next</p>
            <ul class="insight-subject-list m-0 p-0 list-none space-y-2">
              <?php foreach ($subjectStudyHints as $hint):
                  $sid = (int)($hint['subject_id'] ?? 0);
                  $cov = (int)($hint['coverage'] ?? 0);
                  $quiet = !empty($hint['quiet_month']);
              ?>
              <li class="insight-subject-row flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[#e2eef9] bg-white px-3 py-2">
                <div class="min-w-0">
                  <a href="student_subject.php?subject_id=<?php echo $sid; ?>" class="insight-subject-name font-bold text-[#143D59] text-sm truncate block hover:underline"><?php echo h((string)($hint['subject_name'] ?? '')); ?></a>
                  <span class="text-xs text-slate-500"><?php echo (int)($hint['quiz_done'] ?? 0); ?>/<?php echo (int)($hint['quiz_total'] ?? 0); ?> quizzes · <?php echo $cov; ?>% coverage<?php if ($quiet): ?> · <strong class="text-amber-700">No quiz this month</strong><?php endif; ?></span>
                </div>
                <a href="student_subject.php?subject_id=<?php echo $sid; ?>" class="insight-subject-go shrink-0 text-xs font-bold text-[#1665A0]">Open <i class="bi bi-arrow-right"></i></a>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <div class="chart-shell mt-4 min-h-[280px] relative rounded-xl border border-[#e8f2fa] bg-gradient-to-b from-[#fbfdff] to-white overflow-hidden">
            <?php if ($activityChartEmpty): ?>
            <div class="chart-empty flex flex-col items-center justify-center text-center px-6 py-12 min-h-[280px]">
              <span class="chart-empty-icon"><i class="bi bi-bar-chart-line"></i></span>
              <p class="m-0 text-base font-bold text-[#143D59]">No activity in the last 8 weeks yet</p>
              <p class="m-0 mt-2 text-sm text-slate-600 max-w-md">Start your first quiz from any subject. Submissions will populate this chart and your streaks.</p>
              <a href="student_subjects.php" class="chart-empty-cta mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[#1665A0] text-white text-sm font-bold no-underline shadow-md"><i class="bi bi-journal-bookmark"></i> Start your first quiz in Subjects</a>
              <a href="student_preboards.php" class="mt-3 text-sm font-bold text-[#1665A0]">Or try a preboard set →</a>
            </div>
            <?php else: ?>
            <div class="p-2 min-h-[280px] h-[min(320px,50vh)]">
              <canvas id="dashboardStudyChart" aria-label="Student activity trend" aria-describedby="dashActivitySrTable"></canvas>
            </div>
            <div id="dashActivitySrTable" class="sr-only">
              <table>
                <caption>Weekly quiz and preboard submission counts (last eight ISO weeks)</caption>
                <thead><tr><th scope="col">Week</th><th scope="col">Quiz submissions</th><th scope="col">Preboard submissions</th><th scope="col">Total</th></tr></thead>
                <tbody>
                  <?php foreach ($weeklyLabels as $__wk => $__lbl):
                      $qz = (int)($weeklyQuizAct[$__wk] ?? 0);
                      $pr = (int)($weeklyPreAct[$__wk] ?? 0);
                      $tot = $qz + $pr;
                  ?>
                  <tr>
                    <th scope="row"><?php echo h((string)$__lbl); ?></th>
                    <td><?php echo $qz; ?></td>
                    <td><?php echo $pr; ?></td>
                    <td><?php echo $tot; ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
          <?php if (!$activityChartEmpty): ?>
          <p class="text-xs text-slate-500 m-0 mt-2 text-center">Tap a week on the chart to list quiz and preboard submissions.</p>
          <?php endif; ?>
        </article>
        </div>

        <div class="dash-insights-secondary min-w-0">
        <div class="dash-progress-landmark">
          <h2 class="section-title dash-section-title dash-anim delay-4" id="dash-section-progress-indicators"><span class="dash-section-title__icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span><span class="dash-section-title__text">Progress indicators</span></h2>
        </div>
        <article class="dash-card dash-anim delay-4 p-5 progress-indicators-card" data-progress-observe aria-labelledby="dash-section-progress-indicators">
          <div class="dash-progress-intro">
            <p class="dash-progress-intro__sub m-0">Expand a row for details — the bar and percentage replay each time.</p>
            <button type="button" class="progress-metrics-help-btn dash-progress-intro__help" data-dash-metrics-open aria-controls="dashMetricsExplainModal" aria-expanded="false">How we calculate these</button>
          </div>
          <div class="space-y-2">
            <details class="progress-detail">
              <summary class="progress-detail-summary">
                <span class="progress-detail-label"><i class="bi bi-stars"></i> Overall learning progress</span>
                <span class="progress-detail-pct text-[#1665A0]" data-progress-pct="<?php echo (int)$learningProgressPct; ?>"><?php echo (int)$learningProgressPct; ?>%</span>
                <span class="progress-detail-chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
              </summary>
              <p class="progress-detail-hint">Average of quiz coverage and preboard set coverage (each measures how much of the catalog you have completed at least once).</p>
              <div class="progress-track progress-track--lg mt-2" role="presentation">
                <div class="progress-fill progress-fill--animated" data-progress-width="<?php echo (int)$learningProgressPct; ?>"></div>
              </div>
            </details>
            <details class="progress-detail">
              <summary class="progress-detail-summary">
                <span class="progress-detail-label"><i class="bi bi-patch-check"></i> Quiz coverage</span>
                <span class="progress-detail-pct text-[#1665A0]" data-progress-pct="<?php echo (int)$quizCoverage; ?>"><?php echo (int)$quizCoverage; ?>%</span>
                <span class="progress-detail-chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
              </summary>
              <p class="progress-detail-hint m-0"><?php echo (int)$quizzesSubmittedDistinct; ?> of <?php echo (int)$quizzesCount; ?> active quiz<?php echo $quizzesCount === 1 ? '' : 'zes'; ?> submitted at least once.</p>
              <p class="progress-detail-actions mt-2 mb-0">
                <a href="student_subjects.php" class="progress-inline-link"><i class="bi bi-journal-bookmark"></i> Browse subjects &amp; quizzes</a>
                <?php if ($quizSubmittedCount > 0): ?>
                  <a href="student_quiz_history.php" class="progress-inline-link"><i class="bi bi-clock-history"></i> Quiz history</a>
                <?php endif; ?>
              </p>
              <div class="progress-track progress-track--lg mt-2" role="presentation">
                <div class="progress-fill progress-fill--animated progress-fill--cyan" data-progress-width="<?php echo (int)$quizCoverage; ?>"></div>
              </div>
            </details>
            <details class="progress-detail">
              <summary class="progress-detail-summary">
                <span class="progress-detail-label"><i class="bi bi-clipboard2-pulse"></i> Preboards coverage</span>
                <span class="progress-detail-pct text-[#1665A0]" data-progress-pct="<?php echo (int)$preboardCoverage; ?>"><?php echo (int)$preboardCoverage; ?>%</span>
                <span class="progress-detail-chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
              </summary>
              <p class="progress-detail-hint m-0"><?php echo (int)$preboardsSetsSubmittedDistinct; ?> of <?php echo (int)$preboardsSetsCount; ?> active preboard set<?php echo $preboardsSetsCount === 1 ? '' : 's'; ?> completed.</p>
              <p class="progress-detail-actions mt-2 mb-0">
                <a href="student_preboards.php" class="progress-inline-link"><i class="bi bi-clipboard-check"></i> Open preboards library</a>
              </p>
              <div class="progress-track progress-track--lg mt-2" role="presentation">
                <div class="progress-fill progress-fill--animated progress-fill--amber" data-progress-width="<?php echo (int)$preboardCoverage; ?>"></div>
              </div>
            </details>
          </div>
        </article>
        </div>

        <div id="dash-focus" class="dash-insights-focus min-w-0">
          <details class="dash-focus-mobile dash-card overflow-hidden xl:hidden">
            <summary class="dash-focus-mobile-summary">
              <span class="dash-focus-mobile-summary-main">
                <span class="dash-focus-head__icon dash-focus-head__icon--sm" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></span>
                <span class="dash-focus-mobile-summary-text">
                  <span class="dash-focus-head__title dash-focus-head__title--mobile block m-0">Focus &amp; deadlines</span>
                  <span class="dash-focus-head__sub dash-focus-head__sub--mobile block m-0 mt-0.5"><?php echo (int)$quizInProgressCount; ?> quiz in progress<?php if ($focusAccessDaysLeft !== null && $focusAccessDaysLeft > 0): ?> · <?php echo (int)$focusAccessDaysLeft; ?> days access left<?php endif; ?></span>
                </span>
              </span>
              <i class="bi bi-chevron-down dash-focus-mobile-chev" aria-hidden="true"></i>
            </summary>
            <div class="dash-focus-mobile-body border-t border-[#d6e8f7] bg-[#fbfdff]">
              <?php require __DIR__ . '/includes/components/student_dashboard_focus_panel.php'; ?>
            </div>
          </details>
          <aside class="dash-focus-desktop dash-card dash-anim delay-4 overflow-hidden h-fit hidden xl:block" aria-labelledby="dash-focus-panel-heading">
            <header class="dash-focus-head px-5 py-4 border-b border-[#d6e8f7]">
              <span class="dash-focus-head__icon" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></span>
              <div class="dash-focus-head__text">
                <h3 class="dash-focus-head__title m-0" id="dash-focus-panel-heading">Focus panel</h3>
                <p class="dash-focus-head__sub m-0 mt-1">Deadlines, enrollment, and your next steps.</p>
              </div>
            </header>
            <?php require __DIR__ . '/includes/components/student_dashboard_focus_panel.php'; ?>
          </aside>
        </div>
      </div>
    </section>

    <div id="dashWeekModal" class="dash-week-modal" hidden>
      <div class="dash-week-modal__backdrop" data-dash-week-close tabindex="-1"></div>
      <div class="dash-week-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="dashWeekModalTitle">
        <div class="dash-week-modal__head">
          <h3 id="dashWeekModalTitle" class="dash-week-modal__title m-0">Week activity</h3>
          <button type="button" class="dash-week-modal__x" data-dash-week-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <p id="dashWeekModalSub" class="dash-week-modal__sub m-0 text-sm text-slate-600"></p>
        <div id="dashWeekModalBody" class="dash-week-modal__body mt-3"></div>
      </div>
    </div>

    <div id="dashMetricsExplainModal" class="dash-week-modal" hidden>
      <div class="dash-week-modal__backdrop" data-dash-metrics-close tabindex="-1"></div>
      <div class="dash-week-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="dashMetricsExplainTitle" tabindex="-1">
        <div class="dash-week-modal__head">
          <h3 id="dashMetricsExplainTitle" class="dash-week-modal__title m-0">How we calculate these</h3>
          <button type="button" class="dash-week-modal__x" data-dash-metrics-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="dash-week-modal__body mt-2 text-sm text-slate-600 space-y-3">
          <p class="m-0"><strong class="text-[#143D59]">Quiz coverage</strong> is the share of <em>active</em> quizzes in the catalog you have submitted at least once: distinct quizzes you finished divided by total active quizzes, capped at 100%.</p>
          <p class="m-0"><strong class="text-[#143D59]">Preboard set coverage</strong> is the same idea for active preboard sets: how many different sets you have submitted at least once, out of all active sets.</p>
          <p class="m-0"><strong class="text-[#143D59]">Overall learning progress</strong> is the average of quiz coverage and preboard set coverage when both exist; if only one side exists, that single coverage is shown.</p>
          <p class="m-0 text-xs text-slate-500">Numbers refresh when you load the dashboard. They reflect your account only.</p>
        </div>
      </div>
    </div>

    <?php if ($info && !empty($info['access_end'])): ?>
      <?php
      $daysLeft = (int)floor((strtotime((string)$info['access_end']) - time()) / 86400);
      $startTs = !empty($info['access_start']) ? strtotime((string)$info['access_start']) : null;
      $endTs = strtotime((string)$info['access_end']);
      $pct = 0;
      if ($startTs && $endTs && $endTs > $startTs) {
          $pct = (int)round(((time() - $startTs) / ($endTs - $startTs)) * 100);
          $pct = max(0, min(100, $pct));
      }
      ?>
      <?php if ($daysLeft > 0): ?>
      <div class="dash-card dash-anim delay-5 mt-6 p-5 flex flex-wrap items-center justify-between gap-3">
        <p class="m-0 flex items-center gap-2 font-semibold text-[#143D59]"><i class="bi bi-calendar-check text-[#1665A0] text-lg"></i> Access active until <?php echo h(date('M j, Y', (int)$endTs)); ?> · <?php echo (int)$daysLeft; ?> day<?php echo $daysLeft === 1 ? '' : 's'; ?> left</p>
        <div class="flex items-center gap-3">
          <div class="w-40 h-2.5 rounded-full bg-[#e8f2fa] overflow-hidden border border-[#cde2f4]"><div class="h-full rounded-full bg-[#1665A0]" style="width:<?php echo (int)$pct; ?>%"></div></div>
          <span class="text-sm font-bold text-[#1665A0]"><?php echo (int)$pct; ?>% used</span>
        </div>
      </div>
      <?php else: ?>
      <div class="dash-card dash-anim delay-5 mt-6 p-5 border-amber-300 bg-amber-50 text-amber-800 flex items-center gap-2">
        <i class="bi bi-exclamation-triangle text-xl"></i> <span class="font-medium">Access expired. Contact admin to renew your access.</span>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
<style>
.student-dashboard-page {
  background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%);
}
.student-hero {
  border-radius: 0.75rem;
  border: 1px solid rgba(255,255,255,0.28);
  background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
  box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
}
.dash-hero-masthead {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 1.25rem;
}
@media (min-width: 640px) {
  .dash-hero-masthead {
    flex-direction: row;
    align-items: flex-start;
    text-align: left;
    gap: 1.5rem;
  }
}
.dash-hero-avatar-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}
@media (min-width: 640px) {
  .dash-hero-avatar-col { align-items: flex-start; }
}
.dash-hero-avatar-ring {
  width: clamp(5.5rem, 14vw, 7.25rem);
  height: clamp(5.5rem, 14vw, 7.25rem);
  border-radius: 9999px;
  padding: 4px;
  background: linear-gradient(145deg, rgba(255,255,255,0.95) 0%, rgba(186, 230, 253, 0.55) 45%, rgba(255,255,255,0.35) 100%);
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.45),
    0 12px 28px -8px rgba(15, 40, 70, 0.55),
    inset 0 1px 0 rgba(255,255,255,0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.dash-hero-avatar-img {
  width: 100%;
  height: 100%;
  border-radius: 9999px;
  object-fit: cover;
  display: block;
}
.dash-hero-avatar-initial {
  width: 100%;
  height: 100%;
  border-radius: 9999px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: clamp(1.75rem, 5vw, 2.35rem);
  font-weight: 800;
  color: #0c4a6e;
  background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
}
.dash-hero-copy-col { flex: 1; min-width: 0; }
.dash-hero-title { text-shadow: 0 1px 2px rgba(15, 40, 70, 0.25); }
.hero-btn {
  border-radius: 9999px;
  transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
}
.hero-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 22px -20px rgba(14, 64, 105, .95);
}
.hero-strip {
  background: rgba(255,255,255,0.14);
  border: 1px solid rgba(255,255,255,0.24);
  border-radius: 0.62rem;
}
.student-dashboard-page .section-title.dash-section-title {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0 0 0.85rem;
  padding: 0.5rem 0.85rem 0.5rem 0.75rem;
  border: 1px solid #b9daf2;
  border-radius: 0.65rem;
  border-left: 3px solid #1665A0;
  background: linear-gradient(105deg, rgba(22, 101, 160, 0.09) 0%, #ffffff 38%, #f8fbff 100%);
  box-shadow: 0 2px 12px -7px rgba(20, 61, 89, 0.2), 0 1px 0 rgba(255, 255, 255, 0.95) inset;
  color: #0a2540;
  font-size: 1.02rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.25;
  scroll-margin-top: 5.5rem;
}
.student-dashboard-page .section-title.dash-section-title--insights {
  border-left-color: #0f766e;
  background: linear-gradient(105deg, rgba(15, 118, 110, 0.08) 0%, #ffffff 38%, #f8fbff 100%);
}
.student-dashboard-page .dash-section-title__icon {
  flex-shrink: 0;
  width: 2.05rem;
  height: 2.05rem;
  border-radius: 0.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.95rem;
  color: #fff;
  background: linear-gradient(145deg, #1665A0 0%, #1d84d4 100%);
  box-shadow: 0 4px 12px -6px rgba(22, 101, 160, 0.5);
}
.student-dashboard-page .dash-section-title--insights .dash-section-title__icon {
  background: linear-gradient(145deg, #0f766e 0%, #14b8a6 100%);
  box-shadow: 0 4px 12px -6px rgba(15, 118, 110, 0.45);
}
.student-dashboard-page .dash-section-title__text {
  flex: 1;
  min-width: 0;
}
@media (min-width: 640px) {
  .student-dashboard-page .section-title.dash-section-title {
    font-size: 1.0625rem;
    padding: 0.55rem 0.95rem 0.55rem 0.85rem;
  }
  .student-dashboard-page .dash-section-title__icon {
    width: 2.2rem;
    height: 2.2rem;
    font-size: 1rem;
  }
}
.dash-card {
  border-radius: .75rem;
  border: 1px solid rgba(22,101,160,.18);
  background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
  box-shadow: 0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset;
  transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background-color .22s ease;
}
.dash-card:hover {
  transform: translateY(-2px);
  border-color: rgba(22,101,160,.32);
  background-color: #fdfeff;
  box-shadow: 0 20px 34px -24px rgba(20,61,89,.35);
}
.kpi-card { display: flex; flex-direction: column; justify-content: space-between; min-height: 188px; }
.kpi-icon {
  width: 3rem; height: 3rem; border-radius: .75rem;
  display: inline-flex; align-items: center; justify-content: center; font-size: 1.3rem;
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s ease, border-color 0.25s ease;
  border: 1px solid transparent;
}
.kpi-number { font-size: 2rem; font-weight: 800; line-height: 1; letter-spacing: -0.02em; transition: color 0.25s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.kpi-action {
  width: 100%; border: 1px solid #cde2f4; border-radius: .55rem; background: #fff;
  display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
  font-size: .82rem; font-weight: 700; color: #1665A0; padding: .55rem .7rem;
  transition: border-color 0.22s ease, background-color 0.22s ease, box-shadow 0.22s ease, transform 0.22s ease, color 0.22s ease;
  text-decoration: none;
  position: relative;
  overflow: hidden;
}
.kpi-action:hover { border-color: #8fc0e8; background: #f4f9fe; transform: translateY(-1px); }
.dash-kpi-strip {
  gap: 1.1rem;
}
.dash-kpi-grid .dash-kpi-card {
  position: relative;
  overflow: hidden;
  isolation: isolate;
}
.dash-kpi-grid .dash-kpi-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, var(--kpi-accent, #1665A0), var(--kpi-accent-end, #38bdf8));
  transform: scaleX(0.28);
  transform-origin: left center;
  transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
  z-index: 1;
  pointer-events: none;
}
.dash-kpi-grid .dash-kpi-card:hover::before {
  transform: scaleX(1);
}
.dash-kpi-card--subjects { --kpi-accent: #1665A0; --kpi-accent-end: #38bdf8; --kpi-metric: #1665A0; }
.dash-kpi-card--lessons { --kpi-accent: #0891b2; --kpi-accent-end: #22d3ee; --kpi-metric: #0e7490; }
.dash-kpi-card--quizzes { --kpi-accent: #059669; --kpi-accent-end: #34d399; --kpi-metric: #047857; }
.dash-kpi-card--preboards { --kpi-accent: #4f46e5; --kpi-accent-end: #818cf8; --kpi-metric: #4338ca; }
.dash-kpi-grid .dash-kpi-card.dash-card {
  background: linear-gradient(165deg, #ffffff 0%, #f8fbff 48%, #f4f9fe 100%);
}
.dash-kpi-grid .dash-kpi-card.dash-card:hover {
  transform: translateY(-5px);
  border-color: rgba(22, 101, 160, 0.32);
  background: linear-gradient(165deg, #ffffff 0%, #fbfdff 40%, #f0f7fc 100%);
  box-shadow:
    0 22px 44px -20px rgba(20, 61, 89, 0.42),
    0 0 0 1px rgba(255, 255, 255, 0.85) inset,
    0 12px 36px -18px rgba(22, 101, 160, 0.18);
}
.dash-kpi-card--lessons.dash-card:hover { border-color: rgba(8, 145, 178, 0.35); box-shadow: 0 22px 44px -20px rgba(20, 61, 89, 0.42), 0 0 0 1px rgba(255, 255, 255, 0.85) inset, 0 12px 36px -18px rgba(8, 145, 178, 0.2); }
.dash-kpi-card--quizzes.dash-card:hover { border-color: rgba(5, 150, 105, 0.35); box-shadow: 0 22px 44px -20px rgba(20, 61, 89, 0.42), 0 0 0 1px rgba(255, 255, 255, 0.85) inset, 0 12px 36px -18px rgba(5, 150, 105, 0.2); }
.dash-kpi-card--preboards.dash-card:hover { border-color: rgba(79, 70, 229, 0.38); box-shadow: 0 22px 44px -20px rgba(20, 61, 89, 0.42), 0 0 0 1px rgba(255, 255, 255, 0.85) inset, 0 12px 36px -18px rgba(79, 70, 229, 0.22); }
.dash-kpi-grid .dash-kpi-card:hover .kpi-icon {
  transform: scale(1.07) rotate(-2deg);
  box-shadow: 0 10px 22px -14px rgba(22, 101, 160, 0.35);
  border-color: rgba(22, 101, 160, 0.18);
}
.dash-kpi-card--lessons:hover .kpi-icon { box-shadow: 0 10px 22px -14px rgba(8, 145, 178, 0.38); border-color: rgba(8, 145, 178, 0.2); }
.dash-kpi-card--quizzes:hover .kpi-icon { box-shadow: 0 10px 22px -14px rgba(5, 150, 105, 0.38); border-color: rgba(5, 150, 105, 0.2); }
.dash-kpi-card--preboards:hover .kpi-icon { box-shadow: 0 10px 22px -14px rgba(79, 70, 229, 0.38); border-color: rgba(79, 70, 229, 0.22); }
.dash-kpi-grid .dash-kpi-card:hover .kpi-number {
  color: var(--kpi-metric);
  transform: translateY(-1px);
}
.dash-kpi-grid .dash-kpi-card:focus-within {
  outline: 2px solid rgba(22, 101, 160, 0.45);
  outline-offset: 3px;
}
.dash-kpi-card--lessons:focus-within { outline-color: rgba(8, 145, 178, 0.5); }
.dash-kpi-card--quizzes:focus-within { outline-color: rgba(5, 150, 105, 0.5); }
.dash-kpi-card--preboards:focus-within { outline-color: rgba(79, 70, 229, 0.5); }
.dash-kpi-grid .dash-kpi-card .kpi-action {
  color: var(--kpi-metric);
  border-color: #cfe8f8;
}
.dash-kpi-grid .dash-kpi-card .kpi-action:hover {
  background: linear-gradient(180deg, #ffffff 0%, #f0f7fc 100%);
  border-color: #8fc0e8;
  color: var(--kpi-metric);
  box-shadow: 0 6px 16px -10px rgba(22, 101, 160, 0.22);
  transform: translateY(-2px);
}
.dash-kpi-card--lessons .kpi-action:hover { box-shadow: 0 6px 16px -10px rgba(8, 145, 178, 0.22); }
.dash-kpi-card--quizzes .kpi-action:hover { box-shadow: 0 6px 16px -10px rgba(5, 150, 105, 0.22); }
.dash-kpi-card--preboards .kpi-action:hover { box-shadow: 0 6px 16px -10px rgba(79, 70, 229, 0.24); }
.dash-kpi-grid .dash-kpi-card .kpi-action i {
  transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}
.dash-kpi-grid .dash-kpi-card .kpi-action:hover i {
  transform: translateX(5px);
}
.metric-box {
  padding: .5rem .65rem;
  border-radius: .55rem;
  border: 1px solid #d6e8f7;
  background: #f8fbff;
}
.metric-label { margin: 0; font-size: .66rem; color: #64748b; text-transform: uppercase; font-weight: 700; }
.metric-value { margin: .15rem 0 0; font-size: .95rem; color: #1665A0; font-weight: 800; }
.progress-track {
  height: .65rem; border-radius: 9999px; background: #e8f2fa; overflow: hidden; border: 1px solid #d4e6f6;
}
.progress-fill {
  height: 100%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #1665A0 0%, #38bdf8 55%, #3b82c4 100%);
  background-size: 200% 100%;
  width: 0;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.25);
  transition: width 1.05s cubic-bezier(0.22, 1, 0.34, 1);
  animation: progressFillShimmer 2.2s ease-in-out infinite;
}
@keyframes progressFillShimmer {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
.progress-fill--cyan {
  background: linear-gradient(90deg, #0e7490 0%, #22d3ee 50%, #06b6d4 100%);
  background-size: 200% 100%;
}
.progress-fill--amber {
  background: linear-gradient(90deg, #b45309 0%, #fbbf24 50%, #f59e0b 100%);
  background-size: 200% 100%;
}
.progress-track--lg { height: .78rem; }
.progress-detail-summary:hover {
  background: rgba(248, 251, 255, 0.85);
}
.progress-indicators-card .progress-detail {
  border: 1px solid #d6e8f7;
  border-radius: .62rem;
  background: #fbfdff;
  padding: 0;
  overflow: hidden;
}
.progress-detail-summary {
  list-style: none;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(4.5rem, 5.75rem) 1.5rem;
  align-items: center;
  column-gap: 0.5rem;
  padding: .65rem .85rem;
  cursor: pointer;
  font-weight: 700;
  color: #143D59;
  user-select: none;
}
.progress-detail-summary::-webkit-details-marker { display: none; }
.progress-detail-label {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  font-size: .82rem;
  min-width: 0;
  text-align: left;
}
.progress-detail-label i { color: #1665A0; flex-shrink: 0; }
.progress-detail-pct {
  font-size: 1.05rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  text-align: center;
  justify-self: stretch;
  letter-spacing: -0.02em;
  transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1), color 0.2s ease;
}
.progress-detail[open] .progress-detail-pct {
  color: #0f4f7f;
  transform: scale(1.04);
}
.progress-detail-chevron {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  color: #64748b;
}
.progress-detail-chevron i {
  font-size: 1rem;
  transition: transform 0.25s ease;
}
.progress-detail[open] .progress-detail-chevron i { transform: rotate(90deg); }
.progress-detail-hint {
  margin: 0;
  padding: 0 .85rem .65rem;
  font-size: .72rem;
  line-height: 1.45;
  color: #64748b;
}
.progress-detail-actions {
  padding: 0 .85rem .65rem;
  display: flex;
  flex-wrap: wrap;
  gap: .5rem .75rem;
}
.progress-inline-link {
  font-size: .72rem;
  font-weight: 700;
  color: #1665A0;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: .3rem;
}
.progress-inline-link:hover { text-decoration: underline; }
.focus-card {
  border: 1px solid #d6e8f7;
  border-radius: .62rem;
  background: #f8fbff;
  padding: .75rem .8rem;
}
.focus-card--soft { background: linear-gradient(180deg, #ffffff 0%, #f4f9fe 100%); }
.focus-card.is-primary {
  border-color: #9ec8e9;
  background: linear-gradient(145deg, #1665A0 0%, #145a8f 85%);
}
.focus-title { margin: 0 0 .2rem; font-size: .75rem; font-weight: 800; color: #143D59; text-transform: uppercase; letter-spacing: .02em; }
.focus-copy { margin: 0; font-size: .77rem; color: #475569; line-height: 1.45; }
.focus-card.is-primary .focus-title, .focus-card.is-primary .focus-copy { color: #fff; }
.focus-badge {
  flex-shrink: 0;
  min-width: 1.5rem;
  height: 1.5rem;
  padding: 0 .4rem;
  border-radius: 9999px;
  background: rgba(255,255,255,.22);
  border: 1px solid rgba(255,255,255,.35);
  font-size: .72rem;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #fff;
}
.focus-subheading {
  font-size: .66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #64748b;
  margin-top: .65rem !important;
}
.focus-card--soft .focus-title { color: #143D59; }
.focus-card--soft .focus-copy { color: #475569; }
.focus-action-list {
  list-style: none;
  margin: .5rem 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: .45rem;
}
.focus-action-list--compact { gap: .35rem; }
.focus-action-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
  padding: .45rem .5rem;
  border-radius: .45rem;
  background: rgba(255,255,255,.12);
  border: 1px solid rgba(255,255,255,.2);
}
.focus-card--soft .focus-action-item {
  background: #f8fbff;
  border-color: #d6e8f7;
}
.focus-action-meta { min-width: 0; }
.focus-action-title {
  display: block;
  font-size: .78rem;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.focus-card--soft .focus-action-title { color: #143D59; }
.focus-action-sub {
  display: block;
  font-size: .68rem;
  color: rgba(255,255,255,.85);
  margin-top: .1rem;
}
.focus-card--soft .focus-action-sub { color: #64748b; }
.focus-resume-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: .25rem;
  padding: .35rem .55rem;
  border-radius: .45rem;
  font-size: .68rem;
  font-weight: 800;
  text-decoration: none;
  background: #fff;
  color: #145a8f;
  border: 1px solid rgba(255,255,255,.65);
  transition: transform .15s ease, box-shadow .15s ease;
}
.focus-resume-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px -6px rgba(0,0,0,.35); }
.focus-resume-btn--amber {
  background: linear-gradient(180deg, #fff7ed 0%, #ffedd5 100%);
  color: #9a3412;
  border-color: #fdba74;
}
.focus-empty { padding: .25rem 0 0; }
.focus-empty-copy { font-size: .75rem; color: rgba(255,255,255,.88) !important; }
.focus-empty-link {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  margin-top: .45rem;
  font-size: .72rem;
  font-weight: 800;
  color: #fff !important;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.focus-library-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .4rem;
  margin-top: .55rem;
  padding: .45rem .6rem;
  border-radius: .5rem;
  border: 1px solid #cde2f4;
  background: #fff;
  font-size: .75rem;
  font-weight: 800;
  color: #1665A0;
  text-decoration: none;
  transition: background .15s ease, border-color .15s ease;
}
.focus-library-btn:hover { background: #f0f7fc; border-color: #9ec8e9; }
.focus-stat-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .5rem;
  margin-top: .55rem;
}
.focus-stat-pill {
  border-radius: .5rem;
  border: 1px solid #d6e8f7;
  background: #f8fbff;
  padding: .45rem .5rem;
  text-align: center;
}
.focus-stat-num { display: block; font-size: 1.15rem; font-weight: 800; color: #1665A0; line-height: 1.1; }
.focus-stat-lbl { font-size: .62rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
.focus-detail-actions {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem .65rem;
  margin-top: .55rem;
}
.focus-inline-link {
  font-size: .72rem;
  font-weight: 700;
  color: #1665A0;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: .3rem;
}
.focus-inline-link:hover { text-decoration: underline; }
.focus-deadline-banner {
  border-radius: .62rem;
  border: 1px solid rgba(251, 191, 36, 0.55);
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  padding: .65rem .75rem;
  margin-bottom: .5rem;
}
.focus-deadline-label { font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #92400e; }
.focus-deadline-title { font-size: .85rem; font-weight: 800; color: #78350f; }
.focus-deadline-sub { font-size: .72rem; color: #a16207; }
.focus-deadline-btn {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  margin-top: .45rem;
  padding: .35rem .65rem;
  border-radius: .45rem;
  font-size: .72rem;
  font-weight: 800;
  text-decoration: none;
  background: #1665A0;
  color: #fff;
}
.focus-access-inline {
  border-radius: .62rem;
  border: 1px solid #d6e8f7;
  background: #f8fbff;
  padding: .65rem .75rem;
  margin-bottom: .5rem;
}
.focus-access-inline-label { font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
.focus-access-inline-copy { font-size: .78rem; color: #334155; }
.focus-access-inline-pct { font-size: .85rem; font-weight: 800; color: #1665A0; }
.focus-access-inline-track { height: .45rem; border-radius: 9999px; background: #e8f2fa; border: 1px solid #cde2f4; overflow: hidden; }
.focus-access-inline-fill { height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #1665A0, #3b82c4); }
.focus-access-inline-ics {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  margin-top: .45rem;
  font-size: .7rem;
  font-weight: 700;
  color: #1665A0;
}
.dash-insights-section { margin-bottom: 1.5rem; }
.dash-insights-grid {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: 1fr;
  grid-template-areas:
    "primary"
    "secondary"
    "focus";
}
@media (min-width: 1280px) {
  .dash-insights-grid {
    grid-template-columns: minmax(0, 1fr) 300px;
    grid-template-areas:
      "primary focus"
      "secondary focus";
    align-items: start;
  }
}
.dash-insights-primary { grid-area: primary; }
.dash-insights-secondary { grid-area: secondary; }
.dash-insights-focus { grid-area: focus; }
.dash-focus-head {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  background: linear-gradient(120deg, #f0f7fc 0%, #ffffff 72%);
}
.dash-focus-head__icon {
  flex-shrink: 0;
  width: 2.5rem;
  height: 2.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.6rem;
  background: linear-gradient(145deg, #eef2ff 0%, #e0e7ff 100%);
  color: #4338ca;
  font-size: 1.1rem;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85), 0 2px 10px -5px rgba(67, 56, 202, 0.28);
}
.dash-focus-head__icon--sm {
  width: 2.1rem;
  height: 2.1rem;
  font-size: 0.95rem;
}
.dash-focus-head__text {
  min-width: 0;
  flex: 1;
}
.dash-focus-head__title {
  font-size: 1rem;
  font-weight: 800;
  color: #143D59;
  letter-spacing: -0.02em;
  line-height: 1.25;
}
.dash-focus-head__title--mobile {
  font-size: 0.9rem;
}
.dash-focus-head__sub {
  font-size: 0.8125rem;
  color: #64748b;
  font-weight: 600;
  line-height: 1.4;
}
.dash-focus-head__sub--mobile {
  font-size: 0.68rem;
  font-weight: 700;
}
.dash-focus-mobile-summary {
  list-style: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  padding: .85rem 1rem;
  background: linear-gradient(180deg, #f4f9fe 0%, #fff 100%);
}
.dash-focus-mobile-summary::-webkit-details-marker { display: none; }
.dash-focus-mobile-summary-main { display: flex; align-items: center; gap: .65rem; min-width: 0; }
.dash-focus-mobile-chev { transition: transform .2s ease; }
.dash-focus-mobile[open] .dash-focus-mobile-chev { transform: rotate(180deg); }
.dash-focus-mobile-summary:focus-visible {
  outline: 2px solid #1665A0;
  outline-offset: -2px;
  border-radius: 0.35rem;
}
.hero-chip {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  padding: .28rem .55rem;
  border-radius: 9999px;
  font-size: .72rem;
  font-weight: 700;
  background: rgba(255,255,255,.16);
  border: 1px solid rgba(255,255,255,.28);
  color: #f0f9ff;
}
.hero-chip--goal { background: rgba(254, 243, 199, .95); color: #78350f; border-color: rgba(251, 191, 36, .65); }
.hero-chip--score { background: rgba(224, 242, 254, .2); }
.insight-snapshot__heading {
  font-size: 1.02rem;
  font-weight: 800;
  color: #143D59;
  letter-spacing: -0.02em;
}
.insight-snapshot-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(5.85rem, 1fr));
  gap: 0.55rem;
}
.insight-snap-tile {
  border-radius: 0.65rem;
  border: 1px solid #d6e8f7;
  background: linear-gradient(180deg, #ffffff 0%, #f4f9fe 100%);
  padding: 0.55rem 0.6rem 0.5rem;
  text-align: center;
  min-width: 0;
}
.insight-snap-tile__val {
  display: block;
  font-size: 1.45rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
  color: #143D59;
  letter-spacing: -0.03em;
}
.insight-snap-tile__val--sm { font-size: 1.12rem; }
.insight-snap-tile__lbl {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.58rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #64748b;
  line-height: 1.25;
}
.insight-snap-tile__hint {
  font-weight: 600;
  text-transform: none;
  letter-spacing: 0;
  opacity: 0.88;
}
.insight-snap-tile--accent {
  border-color: #9ec8e9;
  background: linear-gradient(180deg, #f0f7fc 0%, #e8f2fa 100%);
}
.insight-snap-tile--accent .insight-snap-tile__val { color: #1665A0; }
.insight-snap-tile--goal {
  border-color: #fde68a;
  background: linear-gradient(180deg, #fffbeb 0%, #fef3c7 100%);
}
.insight-snap-tile--goal .insight-snap-tile__val { color: #92400e; }
.insight-snap-tile--goal .insight-snap-tile__lbl { color: #a16207; }
.insight-recents {
  border: 1px solid #e2eef9;
  border-radius: 0.65rem;
  background: #fbfdff;
  padding: 0.55rem 0.65rem 0.45rem;
}
.insight-recents__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
}
.insight-recents__title {
  font-size: 0.62rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #64748b;
}
.insight-recents__trend {
  font-size: 0.68rem;
  font-weight: 800;
  padding: 0.12rem 0.45rem;
  border-radius: 9999px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #475569;
}
.insight-recents__trend--up { color: #047857; border-color: #a7f3d0; background: #ecfdf5; }
.insight-recents__trend--down { color: #b45309; border-color: #fde68a; background: #fffbeb; }
.insight-recents__trend--flat { color: #475569; }
.insight-recents__spark {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 5px;
  min-height: 48px;
  padding: 0.15rem 0 0.1rem;
}
.insight-recents__single { padding-top: 0.15rem; }
.insight-recents__single-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.45rem;
}
.insight-recents__single-label {
  font-size: 0.78rem;
  font-weight: 700;
  color: #475569;
}
.insight-recents__single-pct {
  font-size: 1.35rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: #1665A0;
  letter-spacing: -0.03em;
}
.insight-recents__meter {
  height: 0.65rem;
  border-radius: 9999px;
  background: #e8f2fa;
  border: 1px solid #cde2f4;
  overflow: hidden;
}
.insight-recents__meter-fill {
  display: block;
  height: 100%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #1665A0 0%, #38bdf8 100%);
  min-width: 0;
  transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}
.insight-spark__bar {
  width: 8px;
  border-radius: 4px 4px 2px 2px;
  background: linear-gradient(180deg, #1665A0, #38bdf8);
  min-height: 12px;
}
.insight-wowline {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 600;
}
.insight-wowline i { font-size: 1rem; opacity: 0.9; }
.insight-wowline--up { color: #047857; }
.insight-wowline--down { color: #b45309; }
.insight-wowline--flat { color: #64748b; }
.insight-goal-select { min-width: 8rem; }
.insight-goal-btn { cursor: pointer; }
.insight-subject-name { text-decoration: none; }
.insight-subject-go { text-decoration: none; }
.chart-empty-icon { font-size: 2rem; color: #1665A0; opacity: .35; margin-bottom: .25rem; }
.chart-empty-cta { text-decoration: none; }
.dash-week-modal {
  position: fixed;
  inset: 0;
  z-index: 1300;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.dash-week-modal[hidden] { display: none !important; }
.dash-week-modal__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
}
.dash-week-modal__dialog {
  position: relative;
  width: min(100%, 420px);
  max-height: min(80vh, 520px);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  border-radius: .85rem;
  border: 1px solid #d6e8f7;
  background: linear-gradient(180deg, #f8fbff 0%, #fff 40%);
  box-shadow: 0 24px 48px -24px rgba(20, 61, 89, 0.55);
  padding: 1rem 1.1rem;
}
.dash-week-modal__head { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
.dash-week-modal__title { font-size: 1.05rem; font-weight: 800; color: #143D59; }
.dash-week-modal__x {
  border: 0;
  background: #f1f5f9;
  border-radius: .45rem;
  width: 2rem;
  height: 2rem;
  cursor: pointer;
  color: #475569;
}
.dash-week-modal__body { overflow-y: auto; flex: 1; min-height: 0; }
.dash-week-modal__row {
  display: flex;
  flex-direction: column;
  gap: .15rem;
  padding: .55rem .65rem;
  border-radius: .5rem;
  border: 1px solid #e8f2fa;
  background: #fff;
  margin-bottom: .45rem;
}
.dash-week-modal__row a { font-weight: 800; color: #1665A0; text-decoration: none; font-size: .85rem; }
.dash-week-modal__row a:hover { text-decoration: underline; }
.dash-week-modal__meta { font-size: .72rem; color: #64748b; font-weight: 600; }
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
.progress-metrics-help-btn {
  border: 0;
  background: none;
  padding: 0;
  font-size: .72rem;
  font-weight: 800;
  color: #1665A0;
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}
.progress-metrics-help-btn:hover { color: #0f4f7f; }
.student-dashboard-page .dash-progress-landmark .section-title.dash-section-title {
  margin-bottom: 0.4rem;
}
.student-dashboard-page .dash-progress-intro {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 0.85rem;
  margin: 0 0 0.65rem;
}
.student-dashboard-page .dash-progress-intro__sub {
  flex: 1 1 12rem;
  min-width: 0;
  font-size: 0.72rem;
  color: #64748b;
  font-weight: 600;
  line-height: 1.45;
  max-width: 28rem;
}
.student-dashboard-page .dash-progress-intro .dash-progress-intro__help {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.35rem 0.6rem;
  border-radius: 0.45rem;
  background: #fff;
  border: 1px solid #cfe8f8;
  text-decoration: none;
  font-size: 0.72rem;
  font-weight: 800;
  color: #1665A0;
  line-height: 1.2;
}
.student-dashboard-page .progress-indicators-card .dash-progress-intro {
  margin-bottom: 0.8rem;
}
.student-dashboard-page .dash-progress-intro .dash-progress-intro__help:hover {
  background: #f0f7fc;
  color: #0f4f7f;
  border-color: #8fc0e8;
}
.student-dashboard-page .dash-progress-intro .dash-progress-intro__help:focus-visible {
  outline: 2px solid #1665A0;
  outline-offset: 2px;
}
@media (max-width: 480px) {
  .student-dashboard-page .dash-progress-intro {
    flex-direction: column;
    align-items: stretch;
  }
  .student-dashboard-page .dash-progress-intro .dash-progress-intro__help {
    align-self: flex-end;
  }
}
@keyframes dashShimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
.dash-page--loading .dash-kpi-grid .kpi-card {
  position: relative;
  pointer-events: none;
}
.dash-page--loading .dash-kpi-grid .kpi-card::after {
  content: '';
  position: absolute;
  inset: 0.65rem;
  border-radius: .55rem;
  background: linear-gradient(90deg, #e8f2fa 0%, #f4f9fe 45%, #e8f2fa 90%);
  background-size: 200% 100%;
  animation: dashShimmer 1.15s ease-in-out infinite;
  pointer-events: none;
}
.dash-page--loading .study-activity-card--has-chart .chart-shell {
  position: relative;
}
.dash-page--loading .study-activity-card--has-chart .chart-shell::after {
  content: '';
  position: absolute;
  inset: 0.5rem;
  border-radius: .65rem;
  background: linear-gradient(90deg, #e8f2fa 0%, #f8fbff 50%, #e8f2fa 100%);
  background-size: 200% 100%;
  animation: dashShimmer 1.15s ease-in-out infinite;
  pointer-events: none;
  z-index: 1;
}
.dash-page--loading .study-activity-card--has-chart .chart-shell .p-2 {
  opacity: 0.35;
}
.dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
.delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; } .delay-4 { animation-delay: .24s; } .delay-5 { animation-delay: .3s; }
@keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) {
  .dash-anim { opacity: 1; transform: none; animation: none; }
  .dash-card, .kpi-action, .hero-btn { transition: none !important; }
  .dash-page--loading .dash-kpi-grid .kpi-card::after,
  .dash-page--loading .study-activity-card--has-chart .chart-shell::after { animation: none; background-position: 0 0; }
  .dash-kpi-grid .dash-kpi-card::before { transition: none; transform: scaleX(1); opacity: 0.9; }
  .dash-kpi-grid .dash-kpi-card.dash-card:hover { transform: none; }
  .dash-kpi-grid .dash-kpi-card:hover .kpi-icon { transform: none; }
  .dash-kpi-grid .dash-kpi-card:hover .kpi-number { transform: none; }
  .dash-kpi-grid .dash-kpi-card .kpi-action:hover { transform: none; }
  .dash-kpi-grid .dash-kpi-card .kpi-action:hover i { transform: none; }
  .progress-fill { animation: none !important; transition-duration: 0.01ms !important; }
  .progress-detail-pct { transition: none !important; }
  .progress-detail[open] .progress-detail-pct { transform: none; }
}
</style>
<?php if ($chartJsUseLocal): ?>
<script src="<?php echo h($dashBase . '/' . $chartJsLocalWeb); ?>?v=<?php echo (int)@filemtime($chartJsLocal); ?>"></script>
<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="<?php echo h($chartJsSriCdn); ?>" crossorigin="anonymous"></script>
<?php endif; ?>
<script>
(function() {
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function wireRecentQuizMeter() {
    var fill = document.querySelector('.insight-recents__meter-fill[data-recent-quiz-width]');
    if (!fill) return;
    var target = Math.max(0, Math.min(100, parseInt(fill.getAttribute('data-recent-quiz-width') || '0', 10) || 0));
    if (reduceMotion) {
      fill.style.width = target + '%';
      return;
    }
    fill.style.width = '0%';
    requestAnimationFrame(function() {
      requestAnimationFrame(function() {
        fill.style.width = target + '%';
      });
    });
  }

  function wireProgressDetails() {
    var root = document.querySelector('[data-progress-observe]');
    if (!root) return;

    function parsePct(el) {
      if (!el) return 0;
      var d = el.getAttribute('data-progress-pct');
      if (d !== null && d !== '') return Math.max(0, Math.min(100, parseInt(d, 10) || 0));
      return 0;
    }

    function setPctLabel(el, n) {
      if (!el) return;
      el.textContent = Math.round(n) + '%';
    }

    function resetFill(detail) {
      var fill = detail.querySelector('.progress-fill');
      if (!fill) return;
      fill.style.transition = 'none';
      fill.style.width = '0%';
    }

    function playFill(detail) {
      var fill = detail.querySelector('.progress-fill');
      if (!fill) return;
      var w = fill.getAttribute('data-progress-width');
      var pct = Math.max(0, Math.min(100, parseInt(w, 10) || 0));
      fill.style.transition = 'none';
      fill.style.width = '0%';
      void fill.offsetWidth;
      fill.style.removeProperty('transition');
      requestAnimationFrame(function() {
        fill.style.width = pct + '%';
      });
    }

    function playNumber(pctEl, target) {
      if (!pctEl) return;
      if (reduceMotion) {
        setPctLabel(pctEl, target);
        return;
      }
      var start = performance.now();
      var dur = 520;
      setPctLabel(pctEl, 0);
      function frame(now) {
        var t = Math.min(1, (now - start) / dur);
        var eased = 1 - Math.pow(1 - t, 3);
        setPctLabel(pctEl, target * eased);
        if (t < 1) requestAnimationFrame(frame);
      }
      requestAnimationFrame(frame);
    }

    root.querySelectorAll('.progress-detail').forEach(function(detail) {
      var pctEl = detail.querySelector('.progress-detail-pct');
      var targetPct = parsePct(pctEl);
      if (!detail.open) resetFill(detail);
      detail.addEventListener('toggle', function() {
        if (!detail.open) {
          resetFill(detail);
          setPctLabel(pctEl, targetPct);
          return;
        }
        playNumber(pctEl, targetPct);
        playFill(detail);
      });
    });
  }
  wireProgressDetails();
  wireRecentQuizMeter();

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }

  var weekDrill = <?php echo json_encode($weekDrillForJs, JSON_UNESCAPED_UNICODE); ?>;
  var modal = document.getElementById('dashWeekModal');
  var modalSub = document.getElementById('dashWeekModalSub');
  var modalBody = document.getElementById('dashWeekModalBody');
  function closeWeekModal() {
    if (modal) modal.hidden = true;
  }
  function openWeekModal(idx) {
    if (!modal || !modalSub || !modalBody || !weekDrill || !weekDrill[idx]) return;
    var w = weekDrill[idx];
    modal.hidden = false;
    document.getElementById('dashWeekModalTitle').textContent = w.label || 'Week';
    var items = w.items || [];
    var total = items.length;
    modalSub.textContent = total ? (total + ' submission' + (total === 1 ? '' : 's') + ' this week.') : 'No submissions this week.';
    if (!total) {
      modalBody.innerHTML = '<p class="text-sm text-slate-600 m-0">Try a quiz or preboard set — activity will show here.</p>';
      return;
    }
    modalBody.innerHTML = items.map(function(it) {
      var kind = it.kind === 'preboard' ? 'Preboard' : 'Quiz';
      var sc = it.score != null ? ' · Score ' + Math.round(Number(it.score)) + '%' : '';
      var at = it.at ? new Date(it.at.replace(' ', 'T')).toLocaleString() : '';
      var href = String(it.href || '#');
      return '<div class="dash-week-modal__row">' +
        '<span class="dash-week-modal__meta">' + esc(kind + sc + (at ? ' · ' + at : '')) + '</span>' +
        '<a href="' + href + '">' + esc(it.title) + '</a>' +
        '<span class="dash-week-modal__meta">' + esc(it.sub) + '</span></div>';
    }).join('');
  }
  if (modal) {
    modal.querySelectorAll('[data-dash-week-close]').forEach(function(el) {
      el.addEventListener('click', closeWeekModal);
    });
  }

  function clearDashPageLoading() {
    if (document.body) document.body.classList.remove('dash-page--loading');
  }
  setTimeout(clearDashPageLoading, 12000);

  var metricsModal = document.getElementById('dashMetricsExplainModal');
  var metricsOpenBtn = document.querySelector('[data-dash-metrics-open]');
  function closeMetricsModal() {
    if (!metricsModal) return;
    metricsModal.hidden = true;
    if (metricsOpenBtn) metricsOpenBtn.setAttribute('aria-expanded', 'false');
  }
  function openMetricsModal() {
    if (!metricsModal) return;
    metricsModal.hidden = false;
    if (metricsOpenBtn) metricsOpenBtn.setAttribute('aria-expanded', 'true');
    var dlg = metricsModal.querySelector('.dash-week-modal__dialog');
    if (dlg && dlg.focus) try { dlg.focus(); } catch (e) {}
  }
  if (metricsModal) {
    metricsModal.querySelectorAll('[data-dash-metrics-close]').forEach(function(el) {
      el.addEventListener('click', closeMetricsModal);
    });
  }
  if (metricsOpenBtn) {
    metricsOpenBtn.addEventListener('click', function() { openMetricsModal(); });
  }
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape' || !metricsModal || metricsModal.hidden) return;
    closeMetricsModal();
  });

  var canvas = document.getElementById('dashboardStudyChart');
  if (!canvas || typeof Chart === 'undefined') {
    clearDashPageLoading();
  } else {
    var ctx = canvas.getContext('2d');
    var labels = <?php echo json_encode(array_values($weeklyLabels)); ?>;
    var quizData = <?php echo json_encode(array_values($weeklyQuizAct)); ?>;
    var preData = <?php echo json_encode(array_values($weeklyPreAct)); ?>;
    var weekGoal = <?php echo (int)$weeklyActivityGoal; ?>;
    var datasets = [
      {
        label: 'Quiz submissions',
        data: quizData,
        backgroundColor: 'rgba(22, 101, 160, 0.82)',
        borderRadius: 6,
        borderSkipped: false
      },
      {
        label: 'Preboard submissions',
        data: preData,
        backgroundColor: 'rgba(245, 158, 11, 0.88)',
        borderRadius: 6,
        borderSkipped: false
      }
    ];
    new Chart(ctx, {
      type: 'bar',
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        onClick: function(evt, els) {
          if (!els || !els.length) return;
          var i = els[0].index;
          if (typeof i === 'number') openWeekModal(i);
        },
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: { boxWidth: 12, padding: 16, font: { size: 11, weight: '600' }, color: '#475569' }
          },
          tooltip: {
            callbacks: {
              footer: function(items) {
                if (!items || !items.length) return '';
                var t = 0;
                items.forEach(function(it) {
                  t += (it.parsed && typeof it.parsed.y === 'number') ? it.parsed.y : 0;
                });
                return 'Week total: ' + t + (weekGoal > 0 ? ' · Weekly goal: ' + weekGoal : '') + ' · Click bar for list';
              }
            }
          }
        },
        scales: {
          x: {
            stacked: true,
            grid: { display: false },
            ticks: { color: '#64748b', font: { size: 10 }, maxRotation: 45, minRotation: 0 }
          },
          y: {
            stacked: true,
            beginAtZero: true,
            ticks: { precision: 0, color: '#64748b', font: { size: 11 } },
            grid: { color: 'rgba(22, 101, 160, 0.1)' }
          }
        }
      }
    });
    canvas.style.cursor = 'pointer';
    requestAnimationFrame(function() {
      requestAnimationFrame(clearDashPageLoading);
    });
  }

  if (document.body && document.body.classList.contains('dash-async-fetch')) {
    var du = document.body.getAttribute('data-dashboard-data-url');
    if (du) {
      fetch(du, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(function() {});
    }
  }
})();
</script>
</body>
</html>
