<?php
/** Compact Top 10 submitted ranking. Expects $pemTop10, $pemSecBadge. Optional $pemTop10PanelId, $pemTop10Variant. */
if (empty($isCollege) || !isset($pemTop10) || !is_array($pemTop10)) {
    return;
}
$pemTop10PanelId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($pemTop10PanelId ?? 'pemTop10')) ?: 'pemTop10';
$pemTop10Variant = (string)($pemTop10Variant ?? 'results');
$pemTop10PrivacySafe = !empty($pemTop10PrivacySafe);
$pemTop10Shown = count($pemTop10);
$pemTop10Eligible = isset($pemTop10Eligible) ? (int)$pemTop10Eligible : $pemTop10Shown;
require_once __DIR__ . '/college_student_number.php';
?>
<aside class="pem-top10 pem-top10--<?php echo $pemTop10Variant === 'live' ? 'live' : 'results'; ?><?php echo $pemTop10Shown > 0 && $pemTop10Shown < 4 ? ' pem-top10--sparse' : ''; ?>" id="<?php echo h($pemTop10PanelId); ?>" aria-labelledby="<?php echo h($pemTop10PanelId); ?>Title">
  <div class="pem-card-head">
    <div>
      <h3 id="<?php echo h($pemTop10PanelId); ?>Title"><i class="bi bi-trophy" aria-hidden="true"></i> Top 10 student ranking</h3>
      <p class="pem-top10__sub">Submitted papers only · <?php echo h((string)$pemSecBadge); ?></p>
    </div>
    <span class="pem-card-head__meta"><?php echo (int)$pemTop10Shown; ?> of <?php echo (int)$pemTop10Eligible; ?> eligible</span>
  </div>
  <?php if ($pemTop10 === []): ?>
    <p class="pem-analytics__empty pem-top10__empty">No eligible submitted attempts in this exam and section yet. Ranking uses completed paper totals, not live progress.</p>
  <?php else: ?>
    <?php
      $pemTop10RankN = [];
      foreach ($pemTop10 as $rankRow) {
          $rk = (int)($rankRow['rank'] ?? 0);
          $pemTop10RankN[$rk] = ($pemTop10RankN[$rk] ?? 0) + 1;
      }
    ?>
    <ol class="pem-top10__list">
      <?php foreach ($pemTop10 as $row): ?>
        <?php
          $dispRank = (int)($row['rank'] ?? 0);
          $medal = $dispRank === 1 ? 'gold' : ($dispRank === 2 ? 'silver' : ($dispRank === 3 ? 'bronze' : ''));
          $pctW = max(0.0, min(100.0, (float)($row['score'] ?? 0)));
          $isTied = ($pemTop10RankN[$dispRank] ?? 0) > 1;
        ?>
        <li class="pem-top10__item<?php echo $medal !== '' ? ' pem-top10__item--' . $medal : ''; ?><?php echo $isTied ? ' pem-top10__item--tied' : ''; ?>">
          <span class="pem-top10__rank" aria-label="<?php echo $isTied ? 'Tied rank ' : 'Rank '; echo $dispRank; ?>"><?php echo $dispRank; ?></span>
          <div class="pem-top10__body">
            <div class="pem-top10__name"><?php
              $examIdLabel = college_exam_id_public_label($row['student_number'] ?? '');
              if ($pemTop10PrivacySafe) {
                  echo h($examIdLabel);
              } else {
                  echo h((string)($row['full_name'] ?? ''));
              }
              echo $isTied ? ' <span class="pem-top10__tied">Tied</span>' : '';
            ?></div>
            <div class="pem-top10__meta">
              <?php
                if ($pemTop10PrivacySafe) {
                    echo (string)($row['fraction'] ?? '') !== '' ? h((string)$row['fraction']) : 'Submitted';
                } else {
                    $secLine = trim((string)($row['section'] ?? ''));
                    $idLine = trim((string)($row['student_number'] ?? ''));
                    echo h($idLine !== '' ? $idLine : 'No College Exam ID');
                    echo ' · ' . h($secLine !== '' ? $secLine : 'No section');
                    if ((string)($row['fraction'] ?? '') !== '') {
                        echo ' · ' . h((string)$row['fraction']);
                    }
                }
              ?>
            </div>
            <div class="pem-top10__bar" role="img" aria-label="Exam score <?php echo h((string)($row['score_label'] ?? '')); ?>">
              <span class="pem-top10__track"><span class="pem-top10__fill" style="width:<?php echo h((string)$pctW); ?>%"></span></span>
              <span class="pem-top10__score"><?php echo h((string)($row['score_label'] ?? '—')); ?></span>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if ($pemTop10Shown < 10): ?>
      <p class="pem-top10__more"><?php echo (int)$pemTop10Shown === 1 ? 'Only one submitted paper in this scope so far.' : 'Fewer than 10 submitted papers in this scope.'; ?> Empty ranks are not filled with placeholder students.</p>
    <?php endif; ?>
    <p class="pem-top10__note">Paper total (unanswered counts as not correct). Tied scores share a rank; the next distinct score takes the next consecutive rank. Listing order among ties is not a placement rule.</p>
  <?php endif; ?>
</aside>
