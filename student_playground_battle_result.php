<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground_battle.php';
require_once __DIR__ . '/includes/student_cpa_review.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_enforce_enabled($conn);
student_playground_battle_ensure_schema($conn);
student_cpa_review_ensure_schema($conn);

$userId = (int) getCurrentUserId();
$code = student_playground_battle_normalize_code((string) ($_GET['room'] ?? ''));
$results = $code !== '' ? student_playground_battle_results($conn, $userId, $code) : ['ok' => false];
if (empty($results['ok'])) {
    header('Location: student_playground_battle');
    exit;
}

$me = $results['me'];
$board = $results['leaderboard'] ?? [];
$wrong = $results['wrong'] ?? [];
$bySubject = $results['by_subject'] ?? [];
$weakest = $results['weakest'] ?? null;
$csrf = generateCSRFToken();
$pageTitle = 'CPA Battle — Results';
$medal = ['🥇', '🥈', '🥉'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require __DIR__ . '/includes/components/playground_styles.php'; ?>
  <?php require __DIR__ . '/includes/components/cpa_review_styles.php'; ?>
</head>
<body class="font-sans antialiased pg-theme pg-game-mode">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page pg-page pg-result-page min-h-full">
    <div class="pg-result-shell">
      <div class="pg-result-topbar">
        <a href="student_playground_battle" class="pg-back-link">← Back to CPA Battle</a>
        <div class="pg-result-top-actions">
          <a href="student_playground_battle" class="pg-start-btn pg-btn-inline">Play Again</a>
          <?php if (!empty($wrong)): ?>
            <a href="#pg-battle-wrong" class="cpa-toolbar-btn is-active">Review Wrong Answers</a>
          <?php endif; ?>
          <a href="student_cpa_mistakes" class="cpa-toolbar-btn">Mistake Notebook</a>
        </div>
      </div>

      <header class="pg-result-hero">
        <div class="pg-result-hero-main">
          <div class="pg-result-trophy" aria-hidden="true"><?php echo $medal[max(0, min(2, ((int) $me['final_rank']) - 1))] ?? '🏆'; ?></div>
          <p class="pg-result-kicker">CPA Battle · Room <?php echo h($results['room_code']); ?></p>
          <h1 class="pg-result-headline">Game Complete!</h1>
          <p class="pg-lobby-sub" style="margin:0 auto"><?php echo h($me['nickname']); ?></p>
          <div class="pg-result-score"><?php echo (int) $me['correct_count']; ?> / <?php echo (int) $me['total']; ?></div>
          <p class="pg-result-acc"><?php echo (int) $me['accuracy']; ?>% accuracy</p>
          <p class="pg-result-points">⭐ <?php echo number_format((int) $me['score']); ?> POINTS</p>
          <div class="pg-result-rank">RANK #<?php echo (int) $me['final_rank']; ?></div>
        </div>
        <div class="pg-result-grid">
          <div class="pg-result-tile"><span>🔥 Best streak</span><strong><?php echo (int) $me['best_streak']; ?></strong></div>
          <div class="pg-result-tile"><span>🎯 Accuracy</span><strong><?php echo (int) $me['accuracy']; ?>%</strong></div>
          <div class="pg-result-tile"><span>⭐ Score</span><strong><?php echo number_format((int) $me['score']); ?></strong></div>
          <div class="pg-result-tile"><span>❌ Wrong</span><strong><?php echo (int) $me['wrong_count']; ?></strong></div>
        </div>
      </header>

      <div class="pg-result-body">
        <section class="pg-panel-card">
          <h2>Final Leaderboard</h2>
          <ol class="pg-battle-rank-list pg-battle-final-board">
            <?php foreach ($board as $i => $row): ?>
              <li>
                <span class="rank"><?php echo $medal[$i] ?? ('#' . (int) $row['final_rank']); ?></span>
                <span class="nick"><?php echo h($row['nickname']); ?></span>
                <span class="pts"><?php echo number_format((int) $row['score']); ?></span>
              </li>
            <?php endforeach; ?>
          </ol>
        </section>

        <section class="pg-panel-card pg-result-perf">
          <h2>Performance by subject</h2>
          <?php if (empty($bySubject)): ?>
            <p class="pg-empty-note">No subject breakdown.</p>
          <?php else: ?>
            <ul class="pg-perf-list">
              <?php foreach ($bySubject as $s): ?>
                <li>
                  <span><?php echo h($s['subject_name']); ?></span>
                  <strong><?php echo (int) $s['accuracy']; ?>%</strong>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($weakest && ($weakest['accuracy'] ?? 100) < 100): ?>
              <p class="pg-rec-hint">Weak area: <?php echo h($weakest['subject_name']); ?></p>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <?php if (!empty($wrong)): ?>
          <section class="pg-panel-card" id="pg-battle-wrong">
            <h2>Wrong answers</h2>
            <ul class="pg-wrong-list">
              <?php foreach ($wrong as $w): ?>
                <li class="pg-wrong-item">
                  <div class="pg-wrong-meta">Q<?php echo (int) $w['ordinal']; ?> · <?php echo h($w['subject_name']); ?></div>
                  <div class="pg-wrong-preview"><?php echo h($w['question_preview']); ?></div>
                  <div class="pg-wrong-ans">Your answer: <?php echo h($w['selected_answer']); ?> · Correct: <?php echo h($w['correct_answer']); ?></div>
                  <button
                    type="button"
                    class="cpa-toolbar-btn pg-mistake-btn"
                    data-question-id="<?php echo (int) $w['question_id']; ?>"
                    data-quiz-id="<?php echo (int) $w['quiz_id']; ?>"
                    data-subject-id="<?php echo (int) $w['subject_id']; ?>"
                    data-selected="<?php echo h($w['selected_answer']); ?>"
                    data-correct="<?php echo h($w['correct_answer']); ?>"
                    data-explanation="<?php echo h($w['explanation']); ?>"
                  >Add to Mistake Notebook</button>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    window.PG_BATTLE = {
      apiUrl: 'student_playground_battle_api',
      csrf: <?php echo json_encode($csrf); ?>,
      roomCode: <?php echo json_encode($results['room_code']); ?>,
      view: 'result',
      playVictory: true
    };
  </script>
  <script src="assets/js/student-playground-battle.js"></script>
</body>
</html>
