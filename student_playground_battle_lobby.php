<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground_battle.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_battle_ensure_schema($conn);

$userId = (int) getCurrentUserId();
$code = student_playground_battle_normalize_code((string) ($_GET['room'] ?? ''));
$game = $code !== '' ? student_playground_battle_game_by_code($conn, $code) : null;
if (!$game) {
    $_SESSION['error'] = 'Battle room not found.';
    header('Location: student_playground_battle');
    exit;
}
$player = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
if (!$player || ($player['status'] ?? '') === 'left') {
    header('Location: student_playground_battle?room=' . rawurlencode((string) $game['room_code']));
    exit;
}

$status = (string) ($game['status'] ?? '');
if (in_array($status, ['countdown', 'question', 'reveal'], true)) {
    header('Location: student_playground_battle_play?room=' . rawurlencode((string) $game['room_code']));
    exit;
}
if ($status === 'finished') {
    header('Location: student_playground_battle_result?room=' . rawurlencode((string) $game['room_code']));
    exit;
}
if ($status === 'cancelled') {
    $_SESSION['error'] = 'This battle was cancelled.';
    header('Location: student_playground_battle');
    exit;
}

$csrf = generateCSRFToken();
$inviteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\')
    . '/student_playground_battle?room=' . rawurlencode((string) $game['room_code']);
$pageTitle = 'CPA Battle Lobby';
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

  <div class="student-dashboard-page pg-page pg-battle-lobby-page min-h-full">
    <div class="pg-arena pg-battle-lobby">
      <header class="pg-battle-lobby-hero">
        <p class="pg-lobby-kicker">🎮 CPA Battle</p>
        <h1><?php echo h((string) $game['title']); ?></h1>
        <div class="pg-battle-room-code" id="pg-battle-room-code"><?php echo h((string) $game['room_code']); ?></div>
        <p class="pg-setup-lead">Share this code with your opponents. Only nicknames are visible.</p>
      </header>

      <div class="pg-battle-invite">
        <button type="button" class="pg-btn-secondary" id="pg-copy-code">Copy Code</button>
        <button type="button" class="pg-btn-secondary" id="pg-copy-link">Copy Invite Link</button>
      </div>

      <section class="pg-battle-panel">
        <div class="pg-battle-lobby-head">
          <h2>Players</h2>
          <span id="pg-battle-player-count">0 / <?php echo (int) STUDENT_PLAYGROUND_BATTLE_MAX_PLAYERS; ?></span>
        </div>
        <ul class="pg-battle-player-list" id="pg-battle-player-list"></ul>
        <p class="pg-rec-hint" id="pg-battle-lobby-hint">Waiting for players…</p>
        <div class="pg-battle-lobby-actions">
          <button type="button" class="pg-start-btn" id="pg-battle-ready-btn">Ready</button>
          <button type="button" class="pg-start-btn" id="pg-battle-start-btn" hidden>Start Battle</button>
          <button type="button" class="pg-btn-secondary" id="pg-battle-leave-btn">Leave</button>
          <button type="button" class="pg-btn-secondary" id="pg-battle-cancel-btn" hidden>Cancel Game</button>
        </div>
        <p id="pg-battle-lobby-error" class="pg-setup-error hidden"></p>
      </section>
    </div>
  </div>

  <script>
    window.PG_BATTLE = {
      apiUrl: 'student_playground_battle_api',
      csrf: <?php echo json_encode($csrf); ?>,
      roomCode: <?php echo json_encode((string) $game['room_code']); ?>,
      inviteUrl: <?php echo json_encode($inviteUrl); ?>,
      isHost: <?php echo ((int) $game['host_user_id'] === $userId) ? 'true' : 'false'; ?>,
      view: 'lobby'
    };
  </script>
  <script src="assets/js/student-playground-battle.js"></script>
</body>
</html>
