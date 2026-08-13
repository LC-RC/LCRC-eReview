<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground_battle.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_enforce_enabled($conn);
student_playground_battle_ensure_schema($conn);

$userId = (int) getCurrentUserId();
$code = student_playground_battle_normalize_code((string) ($_GET['room'] ?? ''));
$game = $code !== '' ? student_playground_battle_game_by_code($conn, $code) : null;
if (!$game) {
    header('Location: student_playground_battle');
    exit;
}
$player = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
if (!$player || ($player['status'] ?? '') === 'left') {
    header('Location: student_playground_battle?room=' . rawurlencode((string) $game['room_code']));
    exit;
}

$status = (string) ($game['status'] ?? '');
if ($status === 'lobby') {
    header('Location: student_playground_battle_lobby?room=' . rawurlencode((string) $game['room_code']));
    exit;
}
if ($status === 'finished') {
    header('Location: student_playground_battle_result?room=' . rawurlencode((string) $game['room_code']));
    exit;
}
if ($status === 'cancelled') {
    header('Location: student_playground_battle');
    exit;
}

$csrf = generateCSRFToken();
$pageTitle = 'CPA Battle — Live';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require __DIR__ . '/includes/components/playground_styles.php'; ?>
</head>
<body class="font-sans antialiased pg-theme pg-game-mode">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page pg-page pg-play-page pg-battle-play-page min-h-full">
    <div class="pg-arena">
      <header class="pg-hud">
        <div class="pg-hud-left">
          <div class="pg-hud-brand"><span class="ico">🎮</span><span>CPA Battle</span></div>
          <span class="pg-q-inline" id="pg-battle-progress">Q — / —</span>
          <span class="pg-subject-pill" id="pg-battle-subject">—</span>
        </div>
        <div class="pg-hud-center">
          <div class="pg-hud-chip score"><span>⭐</span><span class="chip-lbl">Score</span><strong id="pg-battle-score">0</strong></div>
          <div class="pg-hud-chip streak"><span>🔥</span><span class="chip-lbl">Streak</span><strong id="pg-battle-streak">0</strong></div>
        </div>
        <div class="pg-hud-right">
          <div class="pg-audio-controls">
            <button type="button" class="pg-sound-btn" id="pg-music-toggle">🎵 Music</button>
            <button type="button" class="pg-sound-btn" id="pg-sfx-toggle">🔔 SFX</button>
            <button type="button" class="pg-sound-btn" id="pg-mute-toggle">🔊</button>
          </div>
        </div>
      </header>

      <div class="pg-battle-layout">
        <div class="pg-battle-main-col">
          <div class="pg-exam-timer" id="pg-battle-q-timer" data-state="normal">
            <div class="pg-exam-timer-label" id="pg-battle-timer-label">QUESTION TIME</div>
            <div class="pg-exam-timer-value" id="pg-battle-timer">--</div>
          </div>

          <div class="pg-battle-countdown" id="pg-battle-countdown" hidden>
            <div class="pg-battle-countdown-num" id="pg-battle-countdown-num">3</div>
            <p>Get ready…</p>
          </div>

          <section class="pg-stage" id="pg-battle-stage">
            <div class="pg-q-panel">
              <div class="pg-q-text quiz-rich-text" id="pg-battle-question">Waiting for host…</div>
            </div>
            <div class="pg-choices" id="pg-battle-choices"></div>
            <div class="pg-battle-locked" id="pg-battle-locked" hidden>
              <strong>✓ Answer Locked</strong>
              <p>Waiting for the next question…</p>
            </div>
            <div class="pg-reveal pg-reveal-flash" id="pg-battle-reveal" hidden>
              <span class="pg-reveal-icon" id="pg-battle-reveal-icon"></span>
              <p class="pg-reveal-title" id="pg-battle-reveal-title">ANSWER REVEALED</p>
              <p class="pg-reveal-sub" id="pg-battle-reveal-sub"></p>
              <div class="pg-reveal-metrics" id="pg-battle-reveal-metrics"></div>
            </div>
          </section>
        </div>

        <aside class="pg-battle-board" aria-label="Live ranking">
          <h2>🏆 Live Ranking</h2>
          <ol id="pg-battle-rank-list" class="pg-battle-rank-list"></ol>
          <p class="pg-rec-hint" id="pg-battle-total-left">Total time remaining: —</p>
        </aside>
      </div>
    </div>
  </div>

  <script>
    window.PG_BATTLE = {
      apiUrl: 'student_playground_battle_api',
      csrf: <?php echo json_encode($csrf); ?>,
      roomCode: <?php echo json_encode((string) $game['room_code']); ?>,
      musicUrl: 'assets/audio/thinking-time.mp3',
      view: 'play'
    };
  </script>
  <script src="assets/js/student-playground-battle.js?v=engage-sfx-3"></script>
</body>
</html>
