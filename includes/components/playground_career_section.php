<?php
/**
 * Integrated Career section for CPA Playground Game Zone (read-only).
 *
 * Expects:
 * - bool $careerReady
 * - array|null $career from student_gamification_career_summary()
 * - list $xpHistory from student_gamification_list_events()
 * - list $achievements from student_gamification_achievement_gallery()
 */
$careerReady = !empty($careerReady);
$career = $career ?? null;
$xpHistory = $xpHistory ?? [];
$achievements = $achievements ?? [];
?>
<section id="pg-career" class="pg-career-hub" aria-label="Your Career">
  <?php if (!$careerReady || empty($career['ready'])): ?>
    <div class="pg-career-panel pg-career-empty">
      <h2 class="pg-career-heading">Your Career Stats</h2>  
      <p class="pg-career-lead m-0">Career tracking is currently unavailable. Check back later.</p>
    </div>
  <?php else: ?>
    <header class="pg-career-head">
      <div>
        <p class="pg-career-kicker">Game Zone · Career</p>
        <h2 class="pg-career-title">Your Career Stats</h2>
        <p class="pg-career-lead">Track XP, level up, keep your streak, and unlock achievements.</p>
      </div>
      <div class="pg-career-rank-badge" aria-label="Current rank">
        <span class="pg-career-rank-label">Rank</span>
        <span class="pg-career-rank-name"><?php echo h((string) $career['rank']); ?></span>
        <span class="pg-career-rank-level">Level <?php echo (int) $career['level']; ?></span>
      </div>
    </header>

    <div class="pg-career-summary-grid">
      <div class="pg-career-stat">
        <span class="ico" aria-hidden="true">✦</span>
        <span class="lbl">Total Career XP</span>
        <span class="val"><?php echo number_format((int) $career['total_xp']); ?></span>
      </div>
      <div class="pg-career-stat">
        <span class="ico" aria-hidden="true">Lv</span>
        <span class="lbl">Level</span>
        <span class="val"><?php echo (int) $career['level']; ?></span>
      </div>  
      <div class="pg-career-stat">
        <span class="ico" aria-hidden="true">★</span>
        <span class="lbl">Rank</span>
        <span class="val pg-career-stat-rank"><?php echo h((string) $career['rank']); ?></span>
      </div>
      <div class="pg-career-stat">
        <span class="ico" aria-hidden="true">🔥</span>
        <span class="lbl">Current Streak</span>
        <span class="val"><?php echo (int) $career['current_streak_days']; ?>d</span>
      </div>
      <div class="pg-career-stat">
        <span class="ico" aria-hidden="true">🏆</span>
        <span class="lbl">Best Streak</span>
        <span class="val"><?php echo (int) $career['longest_streak_days']; ?>d</span>
      </div>
    </div>

    <div class="pg-career-panel pg-career-progress-panel" aria-label="XP progress">
      <h3 class="pg-career-subheading">XP Progress</h3>
      <?php if (!empty($career['is_max_level'])): ?>
        <p class="pg-career-progress-title m-0">Max Level Reached</p>
        <p class="pg-career-progress-meta">
          <span>Level <?php echo (int) $career['level']; ?> · <?php echo h((string) $career['rank']); ?></span>
          <span><?php echo number_format((int) $career['total_xp']); ?> XP total</span>
        </p>
      <?php else: ?>
        <p class="pg-career-progress-title">
          Level <?php echo (int) $career['level']; ?> — <?php echo h((string) $career['rank']); ?>
        </p>
        <p class="pg-career-progress-count">
          <?php echo number_format((int) $career['xp_in_level']); ?> / <?php echo number_format((int) $career['xp_span']); ?> XP
        </p>
        <div
          class="pg-career-progress-bar"
          role="progressbar"
          aria-valuenow="<?php echo (float) $career['progress_pct']; ?>"
          aria-valuemin="0"
          aria-valuemax="100"
          aria-label="Progress to next level"
        >
          <i style="width: <?php echo h((string) min(100, max(0, (float) $career['progress_pct']))); ?>%"></i>
        </div>
        <div class="pg-career-progress-meta">
          <span><?php echo number_format((int) $career['total_xp']); ?> XP total</span>
          <span><?php echo number_format((int) $career['xp_to_next']); ?> XP to next level</span>
        </div>
      <?php endif; ?>
    </div>

    <div class="pg-career-columns">
      <div class="pg-career-panel" aria-label="Recent XP activity">
        <h3 class="pg-career-subheading">Recent XP Activity</h3>
        <?php if ($xpHistory === []): ?>
          <p class="pg-career-lead m-0">No Career XP yet. Complete a quiz, Playground run, Daily Challenge, or Battle to begin.</p>
        <?php else: ?>
          <ul class="pg-career-history">
            <?php foreach ($xpHistory as $ev): ?>
              <li class="pg-career-history-item">
                <div class="pg-career-history-body">
                  <span class="pg-career-xp-chip<?php echo (int) $ev['xp_delta'] === 0 ? ' is-zero' : ''; ?>">
                    <?php echo (int) $ev['xp_delta'] >= 0 ? '+' : ''; ?><?php echo number_format((int) $ev['xp_delta']); ?> XP
                  </span>
                  <div class="pg-career-history-text">
                    <div class="title"><?php echo h((string) $ev['label']); ?></div>
                    <div class="meta">
                      <?php echo h((string) $ev['created_at_display']); ?>
                      <?php if (!empty($ev['bucket_label'])): ?>
                        · <?php echo h((string) $ev['bucket_label']); ?>
                      <?php endif; ?>
                      <?php if (!empty($ev['capped']) && (int) $ev['xp_delta'] === 0): ?>
                        · Daily cap reached
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="pg-career-panel" aria-label="Achievements">
        <h3 class="pg-career-subheading">Achievements</h3>
        <?php if ($achievements === []): ?>
          <p class="pg-career-lead m-0">Complete activities to unlock achievements.</p>
        <?php else: ?>
          <div class="pg-career-ach-grid">
            <?php foreach ($achievements as $ach): ?>
              <article class="pg-career-ach <?php echo !empty($ach['unlocked']) ? 'is-unlocked' : 'is-locked'; ?>">
                <span class="pg-career-ach-icon" aria-hidden="true"><?php echo !empty($ach['unlocked']) ? '🏅' : '🔒'; ?></span>
                <div class="pg-career-ach-body">
                  <div class="title"><?php echo h((string) $ach['label']); ?></div>
                  <?php if (!empty($ach['description'])): ?>
                    <div class="meta"><?php echo h((string) $ach['description']); ?></div>
                  <?php endif; ?>
                  <?php if (!empty($ach['unlocked']) && !empty($ach['unlocked_at'])): ?>
                    <div class="meta">Unlocked <?php echo h(student_gamification_format_event_datetime((string) $ach['unlocked_at'])); ?></div>
                  <?php elseif (!empty($ach['progress'])): ?>
                    <?php
                      $p = $ach['progress'];
                      $pct = ($p['target'] ?? 0) > 0 ? min(100, round(((int) $p['current'] / (int) $p['target']) * 100)) : 0;
                    ?>
                    <div class="meta"><?php echo h((string) ($p['label'] ?? 'Progress')); ?>: <?php echo (int) ($p['current'] ?? 0); ?> / <?php echo (int) ($p['target'] ?? 0); ?></div>
                    <div class="pg-career-ach-progress"><i style="width: <?php echo (int) $pct; ?>%"></i></div>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
