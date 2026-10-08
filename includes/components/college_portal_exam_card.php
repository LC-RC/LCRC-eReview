<?php
/**
 * Examination card / row for the college portal.
 *
 * @var array  $cpExam
 * @var string $cpExamLayout    card|featured|row|feed|lms
 * @var bool   $cpExamFeatured  Dashboard featured panel
 * @var bool   $cpExamIsNewest  Subtle NEW highlight (catalog, newest sort)
 */
$cpExam = is_array($cpExam ?? null) ? $cpExam : [];
$cpExamLayout = (string)($cpExamLayout ?? 'card');
$cpExamFeatured = !empty($cpExamFeatured);
$cpExamIsNewest = !empty($cpExamIsNewest);

if (!function_exists('cp_portal_exam_status_pill_class')) {
    function cp_portal_exam_status_pill_class(string $statusKey): string
    {
        return match ($statusKey) {
            'open' => 'status-open',
            'upcoming' => 'status-upcoming',
            'in_progress' => 'status-progress',
            'submitted', 'finished' => 'status-done',
            'missed' => 'status-missed',
            'closed' => 'status-closed',
            default => 'status-closed',
        };
    }

    function cp_portal_exam_status_icon(string $statusKey): string
    {
        return match ($statusKey) {
            'open' => 'bi-unlock',
            'upcoming' => 'bi-clock',
            'in_progress' => 'bi-play-circle',
            'submitted', 'finished' => 'bi-check-circle',
            'missed' => 'bi-exclamation-circle',
            'closed' => 'bi-lock',
            default => 'bi-lock',
        };
    }

    function cp_portal_exam_format_datetime(?string $value, string $fallback = '-'): string
    {
        if ($value === null || trim($value) === '') {
            return $fallback;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return $fallback;
        }

        return date('M j, Y · g:i A', $ts);
    }

    function cp_portal_exam_datetime_parts(string $formatted): array
    {
        $formatted = trim($formatted);
        if ($formatted === '' || $formatted === '-') {
            return ['date' => $formatted, 'time' => ''];
        }
        $parts = explode(' · ', $formatted, 2);

        return [
            'date' => trim((string)($parts[0] ?? '')),
            'time' => trim((string)($parts[1] ?? '')),
        ];
    }
}

$examType = (string)($cpExam['exam_type'] ?? 'regular');
$typeLabel = function_exists('examination_exam_type_label')
    ? examination_exam_type_label($examType)
    : ucfirst($examType);
$typeClass = $examType === 'diagnostic' ? 'type-diagnostic' : 'type-regular';
$statusKey = (string)($cpExam['_status_key'] ?? 'locked');
$statusLabel = (string)($cpExam['_status_label'] ?? 'Locked');
$statusClass = cp_portal_exam_status_pill_class($statusKey);
$statusIcon = cp_portal_exam_status_icon($statusKey);
$duration = function_exists('college_exam_human_duration')
    ? college_exam_human_duration((int)($cpExam['time_limit_seconds'] ?? 0))
    : '-';
$opens = cp_portal_exam_format_datetime(isset($cpExam['available_from']) ? (string)$cpExam['available_from'] : null, 'Immediate');
$closes = cp_portal_exam_format_datetime(isset($cpExam['deadline']) ? (string)$cpExam['deadline'] : null);
$desc = trim((string)($cpExam['description'] ?? ''));
$descText = $desc !== '' ? $desc : '';
$st = (string)($cpExam['attempt_status'] ?? '');
$qCount = (int)($cpExam['_q_count'] ?? 0);
$actionMode = (string)($cpExam['_action_mode'] ?? 'closed');
$actionUrl = (string)($cpExam['_action_url'] ?? '');
$actionLabel = (string)($cpExam['_action_label'] ?? 'Closed');
$bucket = (string)($cpExam['_bucket'] ?? 'all');
$title = (string)($cpExam['title'] ?? 'Untitled');

$scoreText = '';
$scoreFraction = '';
$scorePercent = '';
$scorePercentNum = null;
if ($st === 'submitted' || ($st === 'expired' && !empty($cpExam['submitted_at']))) {
    if (function_exists('college_exam_score_display_from_counts')) {
        $scoreDisplay = college_exam_score_display_from_counts(
            isset($cpExam['correct_count']) ? (int)$cpExam['correct_count'] : null,
            isset($cpExam['total_count']) ? (int)$cpExam['total_count'] : null,
            $qCount
        );
        if ($scoreDisplay !== null) {
            $scoreFraction = $scoreDisplay['fraction'];
            $scorePercent = $scoreDisplay['percent_label'];
            $scorePercentNum = $scoreDisplay['percent'];
            $scoreText = $scoreFraction . ' | ' . $scorePercent;
        }
    }
}

$baseClass = match ($cpExamLayout) {
    'lms' => 'cp-exam-lms-item',
    'feed' => 'cp-exam-feed-item',
    'row' => 'cp-exam-row',
    default => 'cp-exam-card',
};
$toneClass = ' ' . $baseClass . '--neutral';
if ($cpExamFeatured || $cpExamLayout === 'featured') {
    $toneClass = ' ' . $baseClass . '--featured';
} elseif ($bucket === 'open' || $statusKey === 'in_progress') {
    $toneClass = ' ' . $baseClass . '--active';
} elseif ($bucket === 'upcoming' || $statusKey === 'upcoming') {
    $toneClass = ' ' . $baseClass . '--upcoming';
} elseif ($bucket === 'finished' || in_array($statusKey, ['submitted', 'finished'], true)) {
    $toneClass = ' ' . $baseClass . '--finished';
} elseif ($bucket === 'missed' || $statusKey === 'missed') {
    $toneClass = ' ' . $baseClass . '--missed';
}

$questionsLabel = $qCount . ' ' . ($qCount === 1 ? 'Question' : 'Questions');
$scheduleLine = $opens . ' — ' . $closes;
$openParts = cp_portal_exam_datetime_parts($opens);
$closeParts = cp_portal_exam_datetime_parts($closes);
$scheduleDateLabel = $openParts['date'] !== '' ? $openParts['date'] : $closeParts['date'];
if ($closeParts['date'] !== '' && $openParts['date'] !== '' && strcasecmp($openParts['date'], $closeParts['date']) !== 0 && strcasecmp($openParts['date'], 'Immediate') !== 0) {
    $scheduleDateLabel = $openParts['date'] . ' – ' . $closeParts['date'];
}
$scheduleTimeLabel = '';
if ($openParts['time'] !== '' && $closeParts['time'] !== '') {
    $scheduleTimeLabel = $openParts['time'] . ' – ' . $closeParts['time'];
} elseif ($closeParts['time'] !== '') {
    $scheduleTimeLabel = $closeParts['time'];
} elseif ($openParts['time'] !== '') {
    $scheduleTimeLabel = $openParts['time'];
}
$availableUntilLabel = $closeParts['time'] !== '' ? $closeParts['time'] : $closes;
$isCatalogCard = ($cpExamLayout === 'card');

$renderAction = static function () use ($actionMode, $actionUrl, $actionLabel, $cpExamLayout, $isCatalogCard): void {
    if ($isCatalogCard) {
        if (in_array($actionMode, ['start', 'continue'], true) && $actionUrl !== '') {
            echo '<a class="cp-exam-card__btn cp-exam-card__btn--start" href="' . h($actionUrl) . '"><i class="bi ' . ($actionMode === 'continue' ? 'bi-arrow-right-circle' : 'bi-play-fill') . '" aria-hidden="true"></i><span>' . h($actionLabel) . '</span></a>';
        } elseif ($actionMode === 'review' && $actionUrl !== '') {
            echo '<a class="cp-exam-card__btn cp-exam-card__btn--action" href="' . h($actionUrl) . '"><i class="bi bi-eye" aria-hidden="true"></i><span>' . h($actionLabel) . '</span></a>';
        } elseif ($actionMode === 'none') {
            echo '<span class="cp-exam-card__btn cp-exam-card__btn--muted"><span>' . h($actionLabel) . '</span></span>';
        } else {
            echo '<span class="cp-exam-card__btn cp-exam-card__btn--closed"><i class="bi bi-slash-circle" aria-hidden="true"></i><span>' . h($actionLabel) . '</span></span>';
        }

        return;
    }

    if (in_array($actionMode, ['start', 'continue'], true) && $actionUrl !== '') {
        echo '<a class="cp-btn cp-btn--primary cp-btn--sm" href="' . h($actionUrl) . '"><i class="bi ' . ($actionMode === 'continue' ? 'bi-arrow-right-circle' : 'bi-play-fill') . '"></i> ' . h($actionLabel) . '</a>';
    } elseif ($actionMode === 'review' && $actionUrl !== '') {
        $reviewBtnClass = $cpExamLayout === 'lms' ? 'cp-btn--primary' : 'cp-btn--secondary';
        echo '<a class="cp-btn ' . h($reviewBtnClass) . ' cp-btn--sm" href="' . h($actionUrl) . '"><i class="bi bi-eye"></i> ' . h($actionLabel) . '</a>';
    } elseif ($actionMode === 'none') {
        echo '<span class="action-muted">' . h($actionLabel) . '</span>';
    } else {
        echo '<span class="action-closed-pill"><i class="bi bi-slash-circle"></i> ' . h($actionLabel) . '</span>';
    }
};

if ($cpExamLayout === 'lms'): ?>
  <article class="cp-exam-lms-item<?php echo h($toneClass); ?><?php echo $cpExamIsNewest ? ' cp-exam-lms-item--new' : ''; ?>">
    <div class="cp-exam-lms-item__main">
      <div class="cp-exam-lms-item__labels">
        <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
        <span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span>
        <?php if ($cpExamIsNewest): ?>
          <span class="cp-exam-badge cp-exam-badge--new">New</span>
        <?php endif; ?>
      </div>
      <h3 class="cp-exam-lms-item__title"><?php echo h($title); ?></h3>
      <?php if ($descText !== ''): ?>
        <p class="cp-exam-lms-item__desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
      <?php endif; ?>
      <div class="cp-exam-meta">
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-journal-text"></i></span><?php echo h($questionsLabel); ?></span>
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-stopwatch"></i></span><?php echo h($duration); ?></span>
        <?php if ($availableUntilLabel !== '' && $availableUntilLabel !== '-'): ?>
          <span class="cp-exam-meta__item cp-exam-meta__item--until"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>Available until <?php echo h($availableUntilLabel); ?></span>
        <?php endif; ?>
      </div>
      <div class="cp-exam-meta cp-exam-meta--when">
        <?php if ($scheduleDateLabel !== ''): ?>
          <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-calendar3"></i></span><?php echo h($scheduleDateLabel); ?></span>
        <?php endif; ?>
        <?php if ($scheduleTimeLabel !== ''): ?>
          <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-clock"></i></span><?php echo h($scheduleTimeLabel); ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="cp-exam-lms-item__aside">
      <?php if ($scoreFraction !== '' || $scorePercent !== ''): ?>
        <div class="cp-exam-lms-item__score">
          <span class="cp-exam-lms-item__score-k">Score</span>
          <?php if ($scoreFraction !== ''): ?>
            <span class="cp-exam-lms-item__score-fraction"><?php echo h($scoreFraction); ?></span>
          <?php endif; ?>
          <?php if ($scorePercent !== ''): ?>
            <span class="cp-exam-lms-item__score-pct"><?php echo h($scorePercent); ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="cp-exam-lms-item__action"><?php $renderAction(); ?></div>
    </div>
  </article>
<?php elseif ($cpExamLayout === 'feed'): ?>
  <article class="cp-exam-feed-item<?php echo h($toneClass); ?>">
    <div class="cp-exam-feed-item__body">
      <div class="cp-exam-feed-item__labels">
        <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
        <span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span>
      </div>
      <h3 class="cp-exam-feed-item__title"><?php echo h($title); ?></h3>
      <?php if ($descText !== ''): ?>
        <p class="cp-exam-feed-item__desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
      <?php endif; ?>
      <div class="cp-exam-meta">
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-journal-text"></i></span><?php echo h($questionsLabel); ?></span>
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-stopwatch"></i></span><?php echo h($duration); ?></span>
      </div>
      <div class="cp-exam-meta cp-exam-meta--when">
        <?php if ($scheduleDateLabel !== ''): ?>
          <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-calendar3"></i></span><?php echo h($scheduleDateLabel); ?></span>
        <?php endif; ?>
        <?php if ($scheduleTimeLabel !== ''): ?>
          <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-clock"></i></span><?php echo h($scheduleTimeLabel); ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="cp-exam-feed-item__aside">
      <?php if ($scoreText !== ''): ?>
        <p class="cp-exam-feed-item__score"><span class="cp-meta-k">Score</span> <?php echo h($scoreText); ?></p>
      <?php endif; ?>
      <div class="cp-exam-feed-item__action"><?php $renderAction(); ?></div>
    </div>
  </article>
<?php elseif ($cpExamLayout === 'row'): ?>
  <div class="cp-exam-row<?php echo h($toneClass); ?>">
    <div class="cp-exam-row__main">
      <div class="cp-exam-row__top">
        <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
        <span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span>
      </div>
      <h3 class="cp-exam-row__title"><?php echo h($title); ?></h3>
      <?php if ($descText !== ''): ?>
        <p class="cp-exam-row__desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
      <?php endif; ?>
    </div>
    <div class="cp-exam-row__meta">
      <span><?php echo $qCount; ?> <?php echo $qCount === 1 ? 'question' : 'questions'; ?></span>
      <span><?php echo h($duration); ?></span>
      <span><?php echo h($closes); ?></span>
      <?php if ($scoreText !== ''): ?><span class="cp-exam-row__score"><?php echo h($scoreText); ?></span><?php endif; ?>
    </div>
    <div class="cp-exam-row__action"><?php $renderAction(); ?></div>
  </div>
<?php elseif ($cpExamLayout === 'featured'): ?>
  <article class="cp-exam-featured cp-exam-card--featured">
    <div class="cp-exam-featured__labels">
      <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
      <span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span>
    </div>
    <h3 class="cp-exam-featured__title"><?php echo h($title); ?></h3>
    <?php if ($descText !== ''): ?>
      <p class="cp-exam-featured__desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
    <?php endif; ?>
    <div class="cp-exam-meta">
      <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-journal-text"></i></span><?php echo h($questionsLabel); ?></span>
      <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-stopwatch"></i></span><?php echo h($duration); ?></span>
      <?php if ($availableUntilLabel !== '' && $availableUntilLabel !== '-'): ?>
        <span class="cp-exam-meta__item cp-exam-meta__item--until"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>Available until <?php echo h($availableUntilLabel); ?></span>
      <?php endif; ?>
    </div>
    <div class="cp-exam-meta cp-exam-meta--when">
      <?php if ($scheduleDateLabel !== ''): ?>
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-calendar3"></i></span><?php echo h($scheduleDateLabel); ?></span>
      <?php endif; ?>
      <?php if ($scheduleTimeLabel !== ''): ?>
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-clock"></i></span><?php echo h($scheduleTimeLabel); ?></span>
      <?php endif; ?>
    </div>
    <div class="cp-exam-featured__foot">
      <?php if ($scoreFraction !== '' || $scorePercent !== ''): ?>
        <div class="cp-exam-featured__score">
          <span class="cp-meta-k">Score</span>
          <?php if ($scoreFraction !== ''): ?><span class="cp-exam-featured__score-v"><?php echo h($scoreFraction); ?></span><?php endif; ?>
          <?php if ($scorePercent !== ''): ?><span class="cp-exam-featured__score-v"><?php echo h($scorePercent); ?></span><?php endif; ?>
        </div>
      <?php else: ?>
        <span class="cp-exam-featured__foot-spacer" aria-hidden="true"></span>
      <?php endif; ?>
      <div class="cp-exam-featured__action"><?php $renderAction(); ?></div>
    </div>
  </article>
<?php else: ?>
  <article class="cp-exam-card cp-exam-card--catalog<?php echo h($toneClass); ?><?php echo $examType === 'diagnostic' ? ' cp-exam-card--diagnostic' : ' cp-exam-card--regular'; ?><?php echo $cpExamIsNewest ? ' cp-exam-card--new' : ''; ?>">
    <div class="cp-exam-card__header">
      <div class="cp-exam-card__labels">
        <div class="cp-exam-card__labels-primary">
          <?php if ($cpExamIsNewest): ?>
            <span class="cp-exam-badge cp-exam-badge--new">New</span>
          <?php endif; ?>
          <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
        </div>
        <span class="cp-exam-card__status-subtle status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>" aria-hidden="true"></i> <?php echo h($statusLabel); ?></span>
      </div>
    </div>
    <div class="cp-exam-card__body">
      <h3 class="cp-exam-card__title"><?php echo h($title); ?></h3>
      <?php if ($descText !== ''): ?>
        <p class="cp-exam-card__desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
      <?php endif; ?>
      <div class="cp-exam-card__meta cp-exam-meta">
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-journal-text"></i></span><?php echo h($questionsLabel); ?></span>
        <span class="cp-exam-meta__item"><span class="cp-exam-meta__ico" aria-hidden="true"><i class="bi bi-stopwatch"></i></span><?php echo h($duration); ?></span>
      </div>
      <div class="cp-exam-card__schedule">
        <div class="cp-exam-card__schedule-row">
          <span class="cp-exam-card__schedule-k">Opens</span>
          <span class="cp-exam-card__schedule-v"><?php echo h($opens); ?></span>
        </div>
        <div class="cp-exam-card__schedule-row">
          <span class="cp-exam-card__schedule-k">Closes</span>
          <span class="cp-exam-card__schedule-v"><?php echo h($closes); ?></span>
        </div>
      </div>
    </div>
    <div class="cp-exam-card__score<?php echo $scoreText === '' ? ' cp-exam-card__score--empty' : ''; ?>">
      <div class="cp-exam-card__score-head">
        <span class="cp-exam-card__score-k">Score</span>
        <?php if ($scorePercent !== ''): ?>
          <span class="cp-exam-card__score-pct"><?php echo h($scorePercent); ?></span>
        <?php else: ?>
          <span class="cp-exam-card__score-pct cp-exam-card__score-pct--placeholder" aria-hidden="true">—</span>
        <?php endif; ?>
      </div>
      <div class="cp-exam-card__score-row">
        <?php if ($scoreText !== ''): ?>
          <span class="cp-exam-card__score-fraction"><?php echo h($scoreFraction !== '' ? $scoreFraction : $scoreText); ?></span>
        <?php else: ?>
          <span class="cp-exam-card__score-fraction cp-exam-card__score-fraction--placeholder" aria-hidden="true">—</span>
        <?php endif; ?>
        <div class="cp-exam-card__score-bar" role="presentation" aria-hidden="true">
          <?php if ($scorePercentNum !== null): ?>
            <span class="cp-exam-card__score-bar-fill" style="width: <?php echo h((string)$scorePercentNum); ?>%;"></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="cp-exam-card__foot">
      <div class="cp-exam-card__action"><?php $renderAction(); ?></div>
    </div>
    <span class="cp-exam-card__stretch" aria-hidden="true"></span>
  </article>
<?php endif; ?>
