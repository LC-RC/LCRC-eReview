<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground.php';
require_once __DIR__ . '/includes/student_cpa_review.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_enforce_enabled($conn);

$userId = (int) getCurrentUserId();
$sessionId = (int) ($_GET['session_id'] ?? 0);
student_playground_ensure_schema($conn);
$session = student_playground_session_get($conn, $userId, $sessionId);
if (!$session) {
    header('Location: student_playground');
    exit;
}
if ($session['status'] !== 'completed') {
    student_playground_finish($conn, $userId, $sessionId);
    $session = student_playground_session_get($conn, $userId, $sessionId) ?: $session;
}

$results = student_playground_results($conn, $userId, $sessionId);
$stats = $results['stats'];
$rank = student_playground_personal_rank($conn, $userId, $sessionId, (int) $stats['score']);
$csrf = generateCSRFToken();
$pageTitle = 'CPA Playground — Results';

$modeLabels = [
    'quick_play' => 'Quick Play',
    'subject_challenge' => 'Subject Challenge',
    'mixed_challenge' => 'Mixed CPA Challenge',
    'daily_challenge' => 'Daily CPA Challenge',
];
$modeLabel = $modeLabels[$session['mode'] ?? ''] ?? 'Playground';
$weakest = $results['weakest'] ?? null;
$hasWeak = $weakest && (($weakest['accuracy'] ?? 100) < 100) && !empty($weakest['subject_name']);
$wrongCount = !empty($results['wrong']) ? count($results['wrong']) : 0;
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
        <a href="student_playground" class="pg-back-link">← Back to Playground</a>
        <div class="pg-result-top-actions">
          <a href="student_playground" class="pg-start-btn pg-btn-inline"><i class="bi bi-controller"></i> Play Again</a>
          <?php if ($wrongCount > 0): ?>
            <a href="#pg-wrong" class="cpa-toolbar-btn is-active">Review Wrong Answers</a>
          <?php endif; ?>
          <a href="student_cpa_mistakes" class="cpa-toolbar-btn">Mistake Notebook</a>
        </div>
      </div>

      <header class="pg-result-hero">
        <div class="pg-result-hero-main">
          <div class="pg-result-trophy" aria-hidden="true">🏆</div>
          <p class="pg-result-kicker"><?php echo h($modeLabel); ?></p>
          <h1 class="pg-result-headline">Game Complete!</h1>
          <div class="pg-result-score"><?php echo (int) $stats['correct']; ?> / <?php echo (int) $stats['total']; ?></div>
          <p class="pg-result-acc"><?php echo h(number_format((float) $stats['accuracy'], 0)); ?>% accuracy</p>
          <p class="pg-result-points">⭐ <?php echo number_format((int) $stats['score']); ?> POINTS</p>
          <div class="pg-result-rank">RANK #<?php echo (int) $rank; ?> · your games</div>
        </div>
        <div class="pg-result-grid">
          <div class="pg-result-tile">
            <span>⭐ Final score</span>
            <strong><?php echo number_format((int) $stats['score']); ?></strong>
          </div>
          <div class="pg-result-tile">
            <span>🎯 Accuracy</span>
            <strong><?php echo h(number_format((float) $stats['accuracy'], 0)); ?>%</strong>
          </div>
          <div class="pg-result-tile">
            <span>🔥 Best streak</span>
            <strong><?php echo (int) $stats['best_streak']; ?></strong>
          </div>
          <div class="pg-result-tile">
            <span>⏱ Avg. response</span>
            <strong><?php echo h((string) $stats['avg_response_sec']); ?>s</strong>
          </div>
        </div>
      </header>

      <div class="pg-result-body">
        <section class="pg-panel-card pg-result-perf">
          <h2>Performance by CPA subject</h2>
          <?php if (empty($results['by_subject'])): ?>
            <p class="pg-empty-note">No subject breakdown available.</p>
          <?php else: ?>
            <div class="pg-subj-grid">
              <?php foreach ($results['by_subject'] as $b):
                  $acc = (float) $b['accuracy'];
                  $isWeak = $hasWeak && (int) ($weakest['subject_id'] ?? 0) === (int) $b['subject_id'];
                  ?>
                <div class="pg-subj-row<?php echo $isWeak ? ' is-weak-row' : ''; ?>">
                  <span class="pg-subj-name"><?php echo h($b['subject_name']); ?><?php echo $isWeak ? ' 🔥' : ''; ?></span>
                  <div class="pg-subj-bar<?php echo $isWeak ? ' is-weak' : ''; ?>"><i style="width:<?php echo max(4, min(100, $acc)); ?>%"></i></div>
                  <span class="pg-subj-meta"><?php echo (int) $b['correct']; ?>/<?php echo (int) $b['total']; ?> · <?php echo h(number_format($acc, 0)); ?>%</span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <aside class="pg-result-aside">
          <?php if ($hasWeak): ?>
            <div class="pg-weak-card">
              <p class="pg-weak-label">🎯 Weak area</p>
              <p class="pg-weak-value">
                <?php echo h($weakest['subject_name']); ?> — <?php echo h(number_format((float) $weakest['accuracy'], 0)); ?>%
              </p>
              <p class="pg-weak-note">Lowest accuracy in this game. Review this subject next.</p>
              <?php if (!empty($weakest['subject_id'])): ?>
                <a href="student_subject?subject_id=<?php echo (int) $weakest['subject_id']; ?>">Review <?php echo h($weakest['subject_name']); ?></a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="pg-weak-card pg-weak-card--ok">
              <p class="pg-weak-label">🎯 Focus</p>
              <p class="pg-weak-value">Solid round</p>
              <p class="pg-weak-note">No weak subject stood out in this game.</p>
            </div>
          <?php endif; ?>

          <div class="pg-panel-card">
            <h2>Next steps</h2>
            <div class="pg-result-actions">
              <a href="student_playground" class="pg-start-btn"><i class="bi bi-controller"></i> Play Again</a>
              <?php if ($wrongCount > 0): ?>
                <a href="#pg-wrong" class="cpa-toolbar-btn is-active">Review Wrong Answers</a>
              <?php endif; ?>
              <a href="student_cpa_mistakes" class="cpa-toolbar-btn">Mistake Notebook</a>
              <a href="student_playground" class="cpa-toolbar-btn">Back to Playground</a>
            </div>
          </div>
        </aside>
      </div>

      <?php if ($wrongCount > 0): ?>
        <section class="pg-panel-card pg-wrong-section" id="pg-wrong">
          <div class="pg-wrong-head">
            <h2>Wrong answers (<?php echo $wrongCount; ?>)</h2>
            <p class="pg-empty-note">Add items to your Mistake Notebook for later review.</p>
          </div>
          <div class="pg-wrong-grid">
            <?php foreach ($results['wrong'] as $w): ?>
              <div class="pg-wrong-item" data-pg-wrong
                  data-question-id="<?php echo (int) $w['question_id']; ?>"
                  data-quiz-id="<?php echo (int) $w['quiz_id']; ?>"
                  data-subject-id="<?php echo (int) $w['subject_id']; ?>"
                  data-selected="<?php echo h($w['selected_answer'] === '-' ? '' : $w['selected_answer']); ?>"
                  data-correct="<?php echo h($w['correct_answer']); ?>">
                <div class="pg-wrong-meta">Q<?php echo (int) $w['ordinal']; ?> · <?php echo h($w['subject_name']); ?></div>
                <p class="pg-wrong-q"><?php echo h($w['question_preview']); ?>…</p>
                <div class="pg-wrong-ans">
                  Your answer: <?php echo h(($w['selected_answer'] === '-' || $w['selected_answer'] === '') ? '—' : $w['selected_answer']); ?>
                  · Correct: <?php echo h($w['correct_answer'] ?: '—'); ?>
                </div>
                <button type="button" class="cpa-toolbar-btn pg-mistake-btn"><i class="bi bi-exclamation-diamond"></i> <span>Add to Mistake Notebook</span></button>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>

  <script>
    window.PG_RESULT = {
      apiUrl: 'student_playground_api',
      csrf: <?php echo json_encode($csrf); ?>,
      playVictory: true
    };
    (function () {
      try {
        if (window.closeAppShellSidebar) window.closeAppShellSidebar();
      } catch (e) {}
    })();
    document.querySelectorAll('.pg-mistake-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var li = btn.closest('[data-pg-wrong]');
        if (!li) return;
        btn.disabled = true;
        fetch(window.PG_RESULT.apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            action: 'mistake_add',
            csrf_token: window.PG_RESULT.csrf,
            question_id: parseInt(li.getAttribute('data-question-id') || '0', 10),
            quiz_id: parseInt(li.getAttribute('data-quiz-id') || '0', 10),
            subject_id: parseInt(li.getAttribute('data-subject-id') || '0', 10),
            selected_answer: li.getAttribute('data-selected') || '',
            correct_answer: li.getAttribute('data-correct') || ''
          })
        }).then(function (r) { return r.json(); }).then(function (res) {
          btn.disabled = false;
          if (res && res.ok) {
            btn.querySelector('span').textContent = 'Saved to Mistakes';
            btn.classList.add('is-active');
            btn.disabled = true;
          } else {
            alert((res && res.error) || 'Could not save');
          }
        });
      });
    });
  </script>
  <script src="assets/js/student-playground.js"></script>
</body>
</html>
