<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);

$userId = (int) getCurrentUserId();
student_playground_ensure_schema($conn);
$csrf = generateCSRFToken();
$subjects = student_playground_subjects_with_counts($conn, $userId);
$daily = student_playground_daily_status($conn, $userId);
$stats = student_playground_user_stats($conn, $userId);
$recent = student_playground_recent_games($conn, $userId, 6);
$pageTitle = 'CPA Playground';

$modeLabels = [
    'quick_play' => 'Quick Play',
    'subject_challenge' => 'Subject Challenge',
    'mixed_challenge' => 'Mixed CPA',
    'daily_challenge' => 'Daily Challenge',
];

$subjectHint = 'Choose a subject';
if (!empty($subjects)) {
    $names = array_map(static function ($s) {
        return (string) ($s['subject_name'] ?? '');
    }, array_slice($subjects, 0, 5));
    $names = array_values(array_filter($names));
    if ($names) {
        $subjectHint = implode(' / ', $names);
        if (count($subjects) > 5) {
            $subjectHint .= '…';
        }
    }
}

$pgMusicUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/assets/audio/thinking-time.mp3';
if ($pgMusicUrl === '/assets/audio/thinking-time.mp3' || strpos($pgMusicUrl, '//') === 0) {
    $pgMusicUrl = 'assets/audio/thinking-time.mp3';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require __DIR__ . '/includes/components/playground_styles.php'; ?>
</head>
<body class="font-sans antialiased pg-theme pg-lobby-mode">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page pg-page pg-lobby-page min-h-full">
    <div class="pg-lobby-fold">
      <header class="pg-lobby-hero">
        <div>
          <p class="pg-lobby-kicker">LCRC eReview · Modules</p>
          <h1 class="pg-lobby-title">🎮 CPA Playground</h1>
          <p class="pg-lobby-sub">Practice smarter. Challenge yourself with your real quiz bank.</p>
        </div>
        <div class="pg-lobby-meta">
          <div class="pg-lobby-sound" role="group" aria-label="Background music">
            <button type="button" class="pg-sound-btn" id="pg-music-toggle" title="Thinking-time music on/off" aria-pressed="true">🎵 Music</button>
            <button type="button" class="pg-sound-btn" id="pg-mute-toggle" title="Master mute" aria-pressed="false">🔊</button>
            <span class="pg-music-hint" id="pg-music-hint" hidden>Tap anywhere for sound</span>
          </div>
          <audio id="pg-bg-music" src="<?php echo h($pgMusicUrl); ?>" loop preload="auto" playsinline></audio>
          <div class="pg-lobby-pts" title="Lifetime points from completed games">
            <span aria-hidden="true">🏆</span>
            <?php echo number_format((int) $stats['total_points']); ?> pts
          </div>
        </div>
      </header>

      <div class="pg-modes" role="listbox" aria-label="Game modes">
        <button type="button" class="pg-mode pg-mode-quick is-selected" data-pg-mode="quick_play" aria-selected="true">
          <span class="pg-mode-icon" aria-hidden="true">⚡</span>
          <h3>Quick Play</h3>
          <p>10 questions · fast solo round</p>
        </button>
        <button type="button" class="pg-mode pg-mode-subject" data-pg-mode="subject_challenge" aria-selected="false">
          <span class="pg-mode-icon" aria-hidden="true">📚</span>
          <h3>Subject Challenge</h3>
          <p><?php echo h($subjectHint); ?></p>
        </button>
        <button type="button" class="pg-mode pg-mode-mixed" data-pg-mode="mixed_challenge" aria-selected="false">
          <span class="pg-mode-icon" aria-hidden="true">🔀</span>
          <h3>Mixed CPA</h3>
          <p>Random subjects you can access</p>
        </button>
        <button type="button" class="pg-mode pg-mode-daily" data-pg-mode="daily_challenge" aria-selected="false" <?php echo $daily['completed'] ? 'data-daily-done="1"' : ''; ?>>
          <span class="pg-mode-icon" aria-hidden="true">📅</span>
          <h3>Daily Challenge</h3>
          <?php if ($daily['completed']): ?>
            <p>Done today · <?php echo (int) $daily['correct']; ?>/<?php echo (int) $daily['total']; ?> · <?php echo (int) $daily['accuracy']; ?>%</p>
          <?php else: ?>
            <p>Today’s challenge · 5 questions</p>
          <?php endif; ?>
        </button>
        <a href="student_playground_battle" class="pg-mode pg-mode-battle" data-pg-battle="1">
          <span class="pg-mode-icon" aria-hidden="true">🎮</span>
          <h3>CPA Battle</h3>
          <p>Multiplayer · challenge other reviewees</p>
        </a>
      </div>

      <section id="pg-setup" class="pg-setup" aria-label="Start your game">
        <div class="pg-setup-head">
          <div>
            <h2>Start Your Game</h2>
            <p class="pg-setup-lead">Total exam time — pick your challenge, then start. Instant feedback &amp; points.</p>
          </div>
          <div class="pg-setup-mode-chip" id="pg-mode-chip">⚡ Quick Play</div>
        </div>

        <input type="hidden" id="pg-mode" value="quick_play">
        <input type="hidden" id="pg-play-style" value="playground">

        <div class="pg-setup-grid">
          <div class="pg-setup-field" id="pg-subject-wrap" hidden>
            <label for="pg-subject">Subject</label>
            <select id="pg-subject">
              <option value="0">Select subject…</option>
              <?php foreach ($subjects as $s): ?>
                <option value="<?php echo (int) $s['subject_id']; ?>"><?php echo h($s['subject_name']); ?> (<?php echo (int) $s['question_count']; ?> Qs)</option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($subjects)): ?>
              <p class="pg-setup-warn">No accessible quiz questions yet. Ask your admin for subject access.</p>
            <?php endif; ?>
          </div>

          <div class="pg-setup-field" id="pg-count-wrap">
            <label>Questions</label>
            <div class="pg-opt-row">
              <?php foreach ([10, 20, 30, 50] as $n): ?>
                <label class="pg-opt"><input type="radio" name="qcount" value="<?php echo $n; ?>" <?php echo $n === 10 ? 'checked' : ''; ?> class="sr-only"> <span><?php echo $n; ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="pg-setup-field pg-setup-field-time" id="pg-time-wrap">
            <label for="pg-time-value">Total exam time</label>
            <div class="pg-duration" role="group" aria-label="Total exam time">
              <input
                type="number"
                id="pg-time-value"
                class="pg-duration-value"
                min="1"
                max="10800"
                step="1"
                value="10"
                inputmode="numeric"
                aria-describedby="pg-time-hint"
              >
              <select id="pg-time-unit" class="pg-duration-unit" aria-label="Time unit">
                <option value="seconds">Seconds</option>
                <option value="minutes" selected>Minutes</option>
                <option value="hours">Hours</option>
              </select>
            </div>
            <p class="pg-rec-hint" id="pg-time-hint">Recommended: 10–15 minutes for 10 questions. Total game time applies to the entire session.</p>
            <div class="pg-opt-row pg-time-presets" id="pg-time-presets" aria-label="Time presets"></div>
          </div>

          <div class="pg-setup-cta">
            <p id="pg-setup-error" class="pg-setup-error hidden"></p>
            <button type="button" id="pg-start" class="pg-start-btn"><i class="bi bi-play-fill" aria-hidden="true"></i> Start Game</button>
          </div>
        </div>
      </section>
    </div>

    <div class="pg-lobby-secondary">
      <section class="pg-stats-panel" aria-label="Your stats">
        <h2>Your Stats</h2>
        <div class="pg-stats-grid">
          <div class="pg-stat-tile">
            <span class="ico" aria-hidden="true">🔥</span>
            <span class="lbl">Best Streak</span>
            <span class="val"><?php echo (int) $stats['best_streak']; ?></span>
          </div>
          <div class="pg-stat-tile">
            <span class="ico" aria-hidden="true">⭐</span>
            <span class="lbl">Best Score</span>
            <span class="val"><?php echo number_format((int) $stats['best_score']); ?></span>
          </div>
          <div class="pg-stat-tile">
            <span class="ico" aria-hidden="true">🎯</span>
            <span class="lbl">Accuracy</span>
            <span class="val"><?php echo h(number_format((float) $stats['avg_accuracy'], 0)); ?>%</span>
          </div>
          <div class="pg-stat-tile">
            <span class="ico" aria-hidden="true">🎮</span>
            <span class="lbl">Games</span>
            <span class="val"><?php echo (int) $stats['games_played']; ?></span>
          </div>
        </div>
      </section>

      <?php if (!empty($recent)): ?>
        <section class="pg-recent" aria-label="Recent games">
          <h2>Recent Games</h2>
          <ul class="pg-recent-list">
            <?php foreach ($recent as $g): ?>
              <li>
                <a class="pg-recent-item" href="student_playground_result?session_id=<?php echo (int) $g['session_id']; ?>">
                  <div>
                    <div class="pg-recent-mode"><?php echo h($modeLabels[$g['mode']] ?? 'Playground'); ?></div>
                    <div class="pg-recent-meta"><?php echo (int) $g['correct']; ?>/<?php echo (int) $g['total']; ?> · <?php echo (int) $g['accuracy']; ?>%</div>
                  </div>
                  <div class="pg-recent-score">⭐ <?php echo number_format((int) $g['score']); ?></div>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
    </div>
  </div>

  <script>
    window.PG = {
      apiUrl: 'student_playground_api',
      csrf: <?php echo json_encode($csrf); ?>,
      dailyDone: <?php echo $daily['completed'] ? 'true' : 'false'; ?>,
      dailySessionId: <?php echo $daily['session_id'] ? (int) $daily['session_id'] : 'null'; ?>,
      musicUrl: <?php echo json_encode($pgMusicUrl); ?>
    };
  </script>
  <script src="assets/js/student-playground.js?v=lobby-music-2"></script>
</body>
</html>
