<?php
/**
 * Student activity heartbeat / video progress / page ping API.
 */
require_once 'auth.php';
require_once __DIR__ . '/includes/student_activity.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || getCurrentUserRole() !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

student_activity_ensure_schema($conn);
$userId = (int) getCurrentUserId();
$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === []) {
    $payload = $_POST;
}

$action = (string) ($payload['action'] ?? $_GET['action'] ?? 'heartbeat');
$token = (string) ($payload['csrf_token'] ?? '');
if ($token !== '' && function_exists('verifyCSRFToken') && !verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
    exit;
}

$location = [
    'page_key' => trim((string) ($payload['page_key'] ?? '')),
    'page_title' => trim((string) ($payload['page_title'] ?? '')),
    'page_url' => trim((string) ($payload['page_url'] ?? '')),
    'subject_id' => (int) ($payload['subject_id'] ?? 0) ?: null,
    'lesson_id' => (int) ($payload['lesson_id'] ?? 0) ?: null,
    'quiz_id' => (int) ($payload['quiz_id'] ?? 0) ?: null,
    'video_id' => (int) ($payload['video_id'] ?? 0) ?: null,
];

if ($action === 'heartbeat' || $action === 'page_view') {
    student_activity_touch_session($conn, $userId, $location);
    if ($action === 'page_view') {
        student_activity_log_event($conn, $userId, 'page_view', $location + [
            'meta' => ['source' => 'api'],
        ]);
    }
    // Soft presence bump
    if (function_exists('touchUserPresence')) {
        touchUserPresence($userId);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'video_progress') {
    $videoId = (int) ($payload['video_id'] ?? 0);
    $position = (float) ($payload['position_sec'] ?? 0);
    $duration = isset($payload['duration_sec']) && $payload['duration_sec'] !== null && $payload['duration_sec'] !== ''
        ? (float) $payload['duration_sec']
        : null;
    $lessonId = (int) ($payload['lesson_id'] ?? 0) ?: null;
    $subjectId = (int) ($payload['subject_id'] ?? 0) ?: null;
    $watchDelta = (float) ($payload['watch_delta_sec'] ?? 0);
    $isPlaying = !empty($payload['is_playing']);
    $ok = student_activity_upsert_video_progress(
        $conn,
        $userId,
        $videoId,
        $position,
        $duration,
        $lessonId,
        $subjectId,
        ['watch_delta_sec' => $watchDelta, 'is_playing' => $isPlaying]
    );
    $title = trim((string) ($location['page_title'] ?? ''));
    if ($title === '') {
        $title = 'Watching video';
    }
    if ($duration !== null && $duration > 0) {
        $title .= ' @ ' . student_activity_format_duration($position) . ' / ' . student_activity_format_duration($duration);
    } else {
        $title .= ' @ ' . student_activity_format_duration($position);
    }
    student_activity_touch_session($conn, $userId, $location + [
        'video_id' => $videoId,
        'lesson_id' => $lessonId,
        'subject_id' => $subjectId,
        'page_title' => $title,
        'page_key' => $location['page_key'] !== '' ? $location['page_key'] : 'student_lesson_viewer',
    ]);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'quiz_heartbeat') {
    $attemptId = (int) ($payload['attempt_id'] ?? 0);
    $tabSwitch = !empty($payload['tab_switch']);
    student_activity_touch_session($conn, $userId, $location);
    if ($attemptId > 0 && ereview_schema_table_exists($conn, 'quiz_attempts')
        && ereview_schema_column_exists($conn, 'quiz_attempts', 'last_seen_at')) {
        if ($tabSwitch) {
            $stmt = mysqli_prepare(
                $conn,
                'UPDATE quiz_attempts SET last_seen_at = NOW(),
                  tab_switch_count = tab_switch_count + 1, last_tab_switch_at = NOW()
                 WHERE attempt_id = ? AND user_id = ? AND status = \'in_progress\''
            );
        } else {
            $stmt = mysqli_prepare(
                $conn,
                'UPDATE quiz_attempts SET last_seen_at = NOW()
                 WHERE attempt_id = ? AND user_id = ? AND status = \'in_progress\''
            );
        }
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        if ($tabSwitch) {
            student_activity_log_event($conn, $userId, 'quiz_tab_switch', $location + [
                'attempt_id' => $attemptId,
                'quiz_id' => (int) ($payload['quiz_id'] ?? 0) ?: null,
            ]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
