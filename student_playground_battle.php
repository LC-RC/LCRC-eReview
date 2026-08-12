<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground_battle.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_battle_ensure_schema($conn);

$userId = (int) getCurrentUserId();
$csrf = generateCSRFToken();
$subjects = student_playground_subjects_with_counts($conn, $userId);
$nick = student_playground_battle_nick_get();
$prefillRoom = student_playground_battle_normalize_code((string) ($_GET['room'] ?? ''));
$pageTitle = 'CPA Battle';
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

  <div class="student-dashboard-page pg-page pg-lobby-page pg-battle-hub min-h-full">
    <header class="pg-lobby-hero">
      <div>
        <p class="pg-lobby-kicker">CPA Playground · Multiplayer</p>
        <h1 class="pg-lobby-title">🎮 CPA Battle</h1>
        <p class="pg-lobby-sub">Challenge other reviewees. Answer CPA questions faster and more accurately — nicknames only.</p>
      </div>
      <a href="student_playground" class="pg-finish-link">← Solo Playground</a>
    </header>

    <section class="pg-battle-panel" id="pg-battle-nick-panel" <?php echo $nick !== '' ? 'hidden' : ''; ?>>
      <h2>Choose your game name</h2>
      <p class="pg-setup-lead">This is the name other players will see. Your real LMS name stays private.</p>
      <div class="pg-battle-nick-row">
        <input type="text" id="pg-battle-nick" maxlength="16" minlength="3" value="<?php echo h($nick); ?>" placeholder="e.g. CPA_Warrior_21" autocomplete="off">
        <button type="button" class="pg-start-btn" id="pg-battle-nick-continue">Continue</button>
      </div>
      <p class="pg-rec-hint">3–16 characters · letters, numbers, spaces, underscore</p>
      <p id="pg-battle-nick-error" class="pg-setup-error hidden"></p>
    </section>

    <div id="pg-battle-main" <?php echo $nick === '' ? 'hidden' : ''; ?>>
      <div class="pg-battle-actions">
        <button type="button" class="pg-battle-cta pg-battle-cta-create" id="pg-battle-show-create">
          <span class="ico" aria-hidden="true">⚔️</span>
          <strong>Create Game</strong>
          <span>Host a CPA Battle room</span>
        </button>
        <button type="button" class="pg-battle-cta pg-battle-cta-join" id="pg-battle-show-join">
          <span class="ico" aria-hidden="true">🔗</span>
          <strong>Join Game</strong>
          <span>Enter a room code</span>
        </button>
      </div>
      <p class="pg-battle-nick-line">Playing as <strong id="pg-battle-nick-display"><?php echo h($nick); ?></strong>
        <button type="button" class="pg-sound-btn" id="pg-battle-change-nick">Change</button>
      </p>

      <section class="pg-battle-panel" id="pg-battle-create-panel" hidden>
        <h2>🎮 Create CPA Battle</h2>
        <div class="pg-setup-grid">
          <div class="pg-setup-field" style="flex:1 1 100%">
            <label for="pg-battle-title">Game name</label>
            <input type="text" id="pg-battle-title" class="pg-battle-text" maxlength="120" value="CPA Battle" placeholder="CPA Battle">
          </div>
          <div class="pg-setup-field" style="flex:1 1 100%">
            <label>Question source</label>
            <div class="pg-opt-row">
              <label class="pg-opt"><input type="radio" name="battle_source" value="mixed" checked class="sr-only"> <span>Mixed CPA</span></label>
              <label class="pg-opt"><input type="radio" name="battle_source" value="subjects" class="sr-only"> <span>Select subjects</span></label>
            </div>
          </div>
          <div class="pg-setup-field" id="pg-battle-subjects-wrap" style="flex:1 1 100%" hidden>
            <label>Subjects</label>
            <div class="pg-battle-subjects">
              <?php foreach ($subjects as $s): ?>
                <label class="pg-opt">
                  <input type="checkbox" name="battle_subject" value="<?php echo (int) $s['subject_id']; ?>" class="sr-only">
                  <span><?php echo h($s['subject_name']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <?php if (empty($subjects)): ?>
              <p class="pg-setup-warn">No accessible subjects yet.</p>
            <?php endif; ?>
          </div>
          <div class="pg-setup-field">
            <label>Questions</label>
            <div class="pg-opt-row">
              <?php foreach ([10, 20, 30, 50] as $n): ?>
                <label class="pg-opt"><input type="radio" name="battle_qcount" value="<?php echo $n; ?>" <?php echo $n === 10 ? 'checked' : ''; ?> class="sr-only"> <span><?php echo $n; ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="pg-setup-field pg-setup-field-time">
            <label for="pg-battle-time-value">Total exam time</label>
            <div class="pg-duration">
              <input type="number" id="pg-battle-time-value" class="pg-duration-value" min="1" value="10">
              <select id="pg-battle-time-unit" class="pg-duration-unit">
                <option value="seconds">Seconds</option>
                <option value="minutes" selected>Minutes</option>
                <option value="hours">Hours</option>
              </select>
            </div>
            <div class="pg-opt-row pg-time-presets" id="pg-battle-time-presets"></div>
          </div>
          <div class="pg-setup-field">
            <label>Question selection</label>
            <div class="pg-opt-row">
              <label class="pg-opt"><input type="radio" name="battle_balanced" value="0" checked class="sr-only"> <span>Random</span></label>
              <label class="pg-opt"><input type="radio" name="battle_balanced" value="1" class="sr-only"> <span>Balanced by subject</span></label>
            </div>
          </div>
          <div class="pg-setup-field">
            <label>Scoring</label>
            <div class="pg-opt-row">
              <label class="pg-opt"><input type="checkbox" id="pg-battle-speed" checked class="sr-only"> <span>Speed bonus</span></label>
              <label class="pg-opt"><input type="checkbox" id="pg-battle-streak" checked class="sr-only"> <span>Streak bonus</span></label>
            </div>
          </div>
          <div class="pg-setup-cta">
            <p id="pg-battle-create-error" class="pg-setup-error hidden"></p>
            <button type="button" class="pg-start-btn" id="pg-battle-create-btn"><i class="bi bi-play-fill"></i> Create Game</button>
          </div>
        </div>
      </section>

      <section class="pg-battle-panel" id="pg-battle-join-panel" <?php echo $prefillRoom !== '' ? '' : 'hidden'; ?>>
        <h2>Join CPA Battle</h2>
        <div class="pg-setup-grid">
          <div class="pg-setup-field">
            <label for="pg-battle-join-code">Room code</label>
            <input type="text" id="pg-battle-join-code" class="pg-battle-text pg-battle-code-input" maxlength="5" value="<?php echo h($prefillRoom); ?>" placeholder="A7K9P" autocomplete="off">
          </div>
          <div class="pg-setup-field">
            <label for="pg-battle-join-nick">Game name</label>
            <input type="text" id="pg-battle-join-nick" class="pg-battle-text" maxlength="16" value="<?php echo h($nick); ?>" placeholder="Your nickname">
          </div>
          <div class="pg-setup-cta">
            <p id="pg-battle-join-error" class="pg-setup-error hidden"></p>
            <button type="button" class="pg-start-btn" id="pg-battle-join-btn">Join Game</button>
          </div>
        </div>
      </section>
    </div>
  </div>

  <script>
    window.PG_BATTLE = {
      apiUrl: 'student_playground_battle_api',
      csrf: <?php echo json_encode($csrf); ?>,
      nickname: <?php echo json_encode($nick); ?>,
      prefillRoom: <?php echo json_encode($prefillRoom); ?>,
      view: 'hub'
    };
  </script>
  <script src="assets/js/student-playground-battle.js"></script>
</body>
</html>
