<?php
/**
 * Examination card / row — compact catalog layout with status hierarchy.
 *
 * @var array  $cpExam
 * @var string $cpExamLayout    card|featured|row
 * @var bool   $cpExamFeatured  Dashboard featured panel only
 */
$cpExam = is_array($cpExam ?? null) ? $cpExam : [];
$cpExamLayout = (string)($cpExamLayout ?? 'card');
$cpExamFeatured = !empty($cpExamFeatured);

if (!function_exists('cp_portal_exam_status_pill_class')) {
    function cp_portal_exam_status_pill_class(string $statusKey): string
    {
        return match ($statusKey) {
            'open' => 'status-open',
            'upcoming' => 'status-upcoming',
            'in_progress' => 'status-progress',
            'submitted', 'finished' => 'status-done',
            'missed' => 'status-missed',
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

        return date('M j, Y g:i A', $ts);
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

$scoreText = '';
if ($st === 'submitted' || ($st === 'expired' && !empty($cpExam['submitted_at']))) {
    if (function_exists('college_exam_format_score_total_line')) {
        $scoreText = college_exam_format_score_total_line(
            isset($cpExam['correct_count']) ? (int)$cpExam['correct_count'] : null,
            isset($cpExam['total_count']) ? (int)$cpExam['total_count'] : null,
            $cpExam['score'] ?? null,
            $qCount
        );
    }
}

$toneClass = ' cp-exam-card--neutral';
if ($cpExamFeatured || $cpExamLayout === 'featured') {
    $toneClass = ' cp-exam-card--featured';
} elseif ($bucket === 'open' || $statusKey === 'in_progress') {
    $toneClass = ' cp-exam-card--active';
} elseif ($bucket === 'upcoming' || $statusKey === 'upcoming') {
    $toneClass = ' cp-exam-card--upcoming';
} elseif ($bucket === 'finished' || in_array($statusKey, ['submitted', 'finished'], true)) {
    $toneClass = ' cp-exam-card--finished';
} elseif ($bucket === 'missed' || $statusKey === 'missed') {
    $toneClass = ' cp-exam-card--missed';
}

$featuredLabel = '';
if ($cpExamFeatured || $cpExamLayout === 'featured') {
    if ($statusKey === 'in_progress' || $actionMode === 'continue') {
        $featuredLabel = 'In progress';
    } elseif ($bucket === 'open' || $actionMode === 'start') {
        $featuredLabel = 'Available now';
    } else {
        $featuredLabel = 'Your examination';
    }
}

if ($cpExamLayout === 'row'): ?>
  <div class="cp-exam-row<?php echo h($toneClass); ?>">
    <div class="cp-exam-row__main">
      <div class="cp-exam-row__top">
        <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
        <span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span>
      </div>
      <h3 class="cp-exam-row__title"><?php echo h((string)($cpExam['title'] ?? 'Untitled')); ?></h3>
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
    <div class="cp-exam-row__action">
      <?php if (in_array($actionMode, ['start', 'continue'], true) && $actionUrl !== ''): ?>
        <a class="cp-btn cp-btn--primary cp-btn--sm" href="<?php echo h($actionUrl); ?>"><i class="bi <?php echo $actionMode === 'continue' ? 'bi-arrow-right-circle' : 'bi-play-fill'; ?>"></i> <?php echo h($actionLabel); ?></a>
      <?php elseif ($actionMode === 'review' && $actionUrl !== ''): ?>
        <a class="cp-btn cp-btn--secondary cp-btn--sm" href="<?php echo h($actionUrl); ?>"><i class="bi bi-eye"></i> <?php echo h($actionLabel); ?></a>
      <?php elseif ($actionMode === 'none'): ?>
        <span class="action-muted"><?php echo h($actionLabel); ?></span>
      <?php else: ?>
        <span class="action-closed-pill"><i class="bi bi-slash-circle"></i> <?php echo h($actionLabel); ?></span>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <article class="cp-exam-card<?php echo h($toneClass); ?><?php echo $examType === 'diagnostic' ? ' cp-exam-card--diagnostic' : ''; ?><?php echo ($cpExamFeatured || $cpExamLayout === 'featured') ? ' cp-exam-card--panel' : ''; ?>">
    <?php if ($featuredLabel !== ''): ?>
      <p class="cp-exam-card__context"><?php echo h($featuredLabel); ?></p>
    <?php endif; ?>
    <div class="cp-exam-card__labels">
      <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
      <span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span>
    </div>
    <h3 class="cp-exam-card__title"><?php echo h((string)($cpExam['title'] ?? 'Untitled')); ?></h3>
    <?php if ($descText !== ''): ?>
      <p class="cp-exam-card__desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
    <?php endif; ?>
    <div class="cp-exam-card__divider" aria-hidden="true"></div>
    <div class="cp-exam-card__meta">
      <span><strong><?php echo $qCount; ?></strong> <?php echo $qCount === 1 ? 'Question' : 'Questions'; ?></span>
      <span class="cp-exam-card__meta-sep" aria-hidden="true">·</span>
      <span><?php echo h($duration); ?></span>
    </div>
    <p class="cp-exam-card__schedule"><?php echo h($opens); ?> — <?php echo h($closes); ?></p>
    <div class="cp-exam-card__foot">
      <?php if ($scoreText !== ''): ?>
        <div class="cp-exam-card__score">
          <span class="cp-meta-k">Score</span>
          <span class="cp-exam-card__score-v"><?php echo h($scoreText); ?></span>
        </div>
      <?php else: ?>
        <span class="cp-exam-card__foot-spacer" aria-hidden="true"></span>
      <?php endif; ?>
      <div class="cp-exam-card__action">
        <?php if (in_array($actionMode, ['start', 'continue'], true) && $actionUrl !== ''): ?>
          <a class="cp-btn cp-btn--primary cp-btn--sm" href="<?php echo h($actionUrl); ?>"><i class="bi <?php echo $actionMode === 'continue' ? 'bi-arrow-right-circle' : 'bi-play-fill'; ?>"></i> <?php echo h($actionLabel); ?></a>
        <?php elseif ($actionMode === 'review' && $actionUrl !== ''): ?>
          <a class="cp-btn cp-btn--secondary cp-btn--sm" href="<?php echo h($actionUrl); ?>"><i class="bi bi-eye"></i> <?php echo h($actionLabel); ?></a>
        <?php elseif ($actionMode === 'none'): ?>
          <span class="action-muted"><?php echo h($actionLabel); ?></span>
        <?php else: ?>
          <span class="action-closed-pill"><i class="bi bi-slash-circle"></i> <?php echo h($actionLabel); ?></span>
        <?php endif; ?>
      </div>
    </div>
  </article>
<?php endif; ?>
