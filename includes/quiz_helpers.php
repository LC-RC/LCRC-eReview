<?php
/**
 * Format total seconds as "1 hour 3 mins 2 seconds" (only non-zero parts).
 * Used for quiz time limit display on admin and student sides.
 */
function formatTimeLimitSeconds($totalSeconds) {
  $s = (int) $totalSeconds;
  if ($s <= 0) return '0 seconds';
  $hours = floor($s / 3600);
  $mins = floor(($s % 3600) / 60);
  $secs = $s % 60;
  $parts = [];
  if ($hours > 0) $parts[] = $hours . ' hour' . ($hours !== 1 ? 's' : '');
  if ($mins > 0) $parts[] = $mins . ' min' . ($mins !== 1 ? 's' : '');
  if ($secs > 0) $parts[] = $secs . ' second' . ($secs !== 1 ? 's' : '');
  return implode(' ', $parts);
}

/**
 * Get quiz time limit in seconds (from time_limit_seconds or legacy time_limit_minutes).
 */
function getQuizTimeLimitSeconds($quizRow) {
  if (isset($quizRow['time_limit_seconds']) && (int)$quizRow['time_limit_seconds'] > 0) {
    return (int) $quizRow['time_limit_seconds'];
  }
  $mins = (int)($quizRow['time_limit_minutes'] ?? 30);
  return $mins * 60;
}
