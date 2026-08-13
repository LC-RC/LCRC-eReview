<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground.php';
require_once __DIR__ . '/includes/quiz_helpers.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_enforce_enabled($conn);

$userId = (int) getCurrentUserId();
$sessionId = (int) ($_GET['session_id'] ?? 0);
student_playground_ensure_schema($conn);
$session = student_playground_session_get($conn, $userId, $sessionId);
if (!$session) {
    $_SESSION['error'] = 'Playground session not found.';
    header('Location: student_playground');
    exit;
}
if ($session['status'] === 'completed') {
    header('Location: student_playground_result?session_id=' . $sessionId);
    exit;
}

// Backfill ends_at for older in-progress sessions.
if (empty($session['ends_at']) && ereview_schema_column_exists($conn, 'student_playground_sessions', 'ends_at')) {
    $total = (int) ($session['total_time_seconds'] ?? 0);
    if ($total <= 0) {
        $total = student_playground_recommended_total_seconds((int) $session['question_count']);
    }
    $u = mysqli_prepare(
        $conn,
        'UPDATE student_playground_sessions
         SET total_time_seconds = ?, ends_at = DATE_ADD(started_at, INTERVAL ? SECOND)
         WHERE session_id = ? AND user_id = ?'
    );
    if ($u) {
        mysqli_stmt_bind_param($u, 'iiii', $total, $total, $sessionId, $userId);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
        $session = student_playground_session_get($conn, $userId, $sessionId) ?: $session;
    }
}

if (student_playground_session_expired($session)) {
    student_playground_finish($conn, $userId, $sessionId);
    header('Location: student_playground_result?session_id=' . $sessionId);
    exit;
}

$csrf = generateCSRFToken();
$pageTitle = 'CPA Playground — Playing';
$qTotal = (int) $session['question_count'];
$answered = (int) $session['answered_count'];
$remaining = student_playground_remaining_total_seconds($session);
$endsAtIso = !empty($session['ends_at']) ? date('c', strtotime((string) $session['ends_at'])) : '';
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

  <div class="student-dashboard-page pg-page pg-play-page min-h-full">
    <div class="pg-arena" id="pg-arena">

      <header class="pg-hud">
        <div class="pg-hud-left">
          <div class="pg-hud-brand">
            <span class="ico" aria-hidden="true">🎮</span>
            <span>CPA Playground</span>
          </div>
          <span class="pg-subject-pill" id="pg-subject">—</span>
          <span class="pg-q-inline" id="pg-progress">Q <?php echo min($answered + 1, $qTotal); ?> / <?php echo $qTotal; ?></span>
        </div>

        <div class="pg-hud-center">
          <div class="pg-hud-chip score" id="pg-score-chip">
            <span aria-hidden="true">⭐</span>
            <span class="chip-lbl">Score</span>
            <strong id="pg-score"><?php echo (int) $session['score']; ?></strong>
          </div>
          <div class="pg-hud-chip streak" id="pg-streak-wrap">
            <span aria-hidden="true">🔥</span>
            <span class="chip-lbl">Streak</span>
            <strong id="pg-streak-n"><?php echo (int) $session['current_streak']; ?></strong>
          </div>
        </div>

        <div class="pg-hud-right">
          <div class="pg-audio-controls" id="pg-audio-controls" aria-label="Audio controls">
            <button type="button" class="pg-sound-btn" id="pg-music-toggle" title="Thinking-time music on/off" aria-pressed="true">🎵 Music</button>
            <button type="button" class="pg-sound-btn" id="pg-sfx-toggle" title="Sound effects on/off" aria-pressed="true">🔔 SFX</button>
            <button type="button" class="pg-sound-btn" id="pg-mute-toggle" title="Master mute" aria-pressed="false">🔊</button>
          </div>
          <a href="student_playground" class="pg-exit-btn" title="Leave this game and return to the lobby">Exit</a>
        </div>
      </header>

      <div class="pg-exam-timer" id="pg-exam-timer" data-state="normal">
        <div class="pg-exam-timer-label">TIME REMAINING</div>
        <div class="pg-exam-timer-value" id="pg-timer" aria-live="polite">--:--</div>
        <div class="pg-exam-timer-warn" id="pg-timer-warn" hidden></div>
      </div>

      <div class="pg-progress-block">
        <div class="pg-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="pg-progress-bar">
          <div class="pg-progress-fill" id="pg-progress-fill" style="width: 0%"></div>
        </div>
      </div>

      <div class="pg-nav-row">
        <nav class="pg-nav" id="pg-nav" aria-label="Question navigator"></nav>
        <button type="button" class="pg-finish-link" id="pg-finish-game" title="Finish and submit your game">Finish Game</button>
      </div>

      <section class="pg-stage" id="pg-card" aria-live="polite">
        <div class="pg-confetti" id="pg-confetti" hidden aria-hidden="true"></div>

        <div class="pg-q-panel" id="pg-q-panel">
          <div class="pg-q-text quiz-rich-text" id="pg-question">Loading…</div>
        </div>

        <div class="pg-choices" id="pg-choices" role="listbox" aria-label="Answer choices"></div>

        <div id="pg-reveal" class="pg-reveal pg-reveal-flash" hidden>
          <span class="pg-reveal-icon" id="pg-reveal-icon" aria-hidden="true"></span>
          <p class="pg-reveal-title" id="pg-reveal-title"></p>
          <p class="pg-reveal-sub" id="pg-reveal-sub"></p>
          <div class="pg-reveal-metrics" id="pg-reveal-metrics"></div>
          <div class="pg-milestone" id="pg-milestone" hidden></div>
        </div>

        <div class="pg-play-actions">
          <button type="button" class="pg-skip-btn" id="pg-skip">Skip Question →</button>
          <a href="student_playground" class="pg-exit-btn pg-exit-btn--action">Exit playground</a>
        </div>
      </section>
    </div>
  </div>

  <div class="pg-modal" id="pg-submit-modal" hidden>
    <div class="pg-modal-card" role="dialog" aria-modal="true" aria-labelledby="pg-modal-title">
      <h2 id="pg-modal-title">Finish Game?</h2>
      <p id="pg-modal-body">You still have unanswered questions.</p>
      <div class="pg-modal-actions">
        <button type="button" class="pg-btn-secondary" id="pg-review-unanswered">Review Questions</button>
        <button type="button" class="pg-next-btn" id="pg-confirm-submit">Submit Game</button>
        <button type="button" class="pg-btn-secondary" id="pg-modal-cancel">Cancel</button>
      </div>
    </div>
  </div>

  <script>
    window.PG_PLAY = {
      apiUrl: 'student_playground_api',
      csrf: <?php echo json_encode($csrf); ?>,
      sessionId: <?php echo (int) $sessionId; ?>,
      endsAt: <?php echo json_encode($endsAtIso); ?>,
      remainingTotalSeconds: <?php echo (int) $remaining; ?>,
      questionCount: <?php echo (int) $qTotal; ?>,
      playStyle: <?php echo json_encode((string) ($session['play_style'] ?? 'playground')); ?>,
      feedbackMs: 1300,
      musicUrl: <?php echo json_encode('assets/audio/thinking-time.mp3'); ?>
    };
  </script>
  <script src="assets/js/student-playground.js?v=engage-sfx-3"></script>
</body>
</html>
