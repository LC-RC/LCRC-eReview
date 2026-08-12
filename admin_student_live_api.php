<?php
/**
 * JSON feed for Live Activity board (polled every few seconds).
 */
require_once 'auth.php';
requireAdminPage('student_activity');
require_once __DIR__ . '/includes/student_activity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

student_activity_ensure_schema($conn);
$within = sanitizeInt($_GET['within'] ?? 180, 180);
$within = max(60, min(3600, $within));

$live = student_activity_fetch_live($conn, $within);
$recent = student_activity_fetch_recent_video_watches($conn, 72, 60);

$normalizeLive = static function (array $row): array {
    $pos = (float) ($row['video_position_sec'] ?? 0);
    $dur = isset($row['video_duration_sec']) && $row['video_duration_sec'] !== null
        ? (float) $row['video_duration_sec']
        : null;
    $pct = $dur !== null && $dur > 0
        ? min(100.0, max(0.0, ($pos / $dur) * 100.0))
        : (float) ($row['video_percent'] ?? 0);
    $title = trim((string) ($row['video_title'] ?? ''));
    if ($title === '' && !empty($row['video_id'])) {
        $title = 'Video #' . (int) $row['video_id'];
    }
    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'where' => (string) ($row['current_page_title'] ?? $row['current_page_key'] ?? 'LMS'),
        'page_url' => (string) ($row['current_page_url'] ?? ''),
        'subject_name' => (string) ($row['subject_name'] ?? ''),
        'lesson_title' => (string) ($row['lesson_title'] ?? ''),
        'quiz_title' => (string) ($row['quiz_title'] ?? ''),
        'video_id' => (int) ($row['video_id'] ?? 0),
        'video_title' => $title,
        'video_position_sec' => $pos,
        'video_duration_sec' => $dur,
        'video_percent' => $pct,
        'video_watch_seconds' => (float) ($row['video_watch_seconds'] ?? 0),
        'video_is_playing' => !empty($row['video_is_playing']),
        'has_progress' => isset($row['video_position_sec']) || isset($row['video_watch_seconds']),
        'session_seconds' => (int) ($row['session_seconds'] ?? 0),
        'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
        'last_seen_label' => !empty($row['last_seen_at'])
            ? date('g:i:s A', strtotime((string) $row['last_seen_at']))
            : '-',
    ];
};

$normalizeRecent = static function (array $row): array {
    $pos = (float) ($row['position_sec'] ?? 0);
    $dur = isset($row['duration_sec']) && $row['duration_sec'] !== null ? (float) $row['duration_sec'] : null;
    $pct = $dur !== null && $dur > 0
        ? min(100.0, max(0.0, ($pos / $dur) * 100.0))
        : (float) ($row['percent'] ?? 0);
    $title = trim((string) ($row['video_title'] ?? ''));
    if ($title === '' && !empty($row['video_id'])) {
        $title = 'Video #' . (int) $row['video_id'];
    }
    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'video_title' => $title,
        'subject_name' => (string) ($row['subject_name'] ?? ''),
        'lesson_title' => (string) ($row['lesson_title'] ?? ''),
        'position_sec' => $pos,
        'duration_sec' => $dur,
        'percent' => $pct,
        'watch_seconds' => (float) ($row['watch_seconds'] ?? 0),
        'is_playing' => !empty($row['is_playing']),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'updated_label' => !empty($row['updated_at'])
            ? date('M j, g:i:s A', strtotime((string) $row['updated_at']))
            : '-',
    ];
};

echo json_encode([
    'ok' => true,
    'server_time' => date('c'),
    'within' => $within,
    'live' => array_map($normalizeLive, $live),
    'recent_watches' => array_map($normalizeRecent, $recent),
], JSON_UNESCAPED_SLASHES);
