<?php
/**
 * Emit student activity tracker config + script (call near end of student pages).
 *
 * Optional vars before include:
 *   $studentActivityBoot = [
 *     'page_key' => '...',
 *     'page_title' => '...',
 *     'subject_id' => 0,
 *     'lesson_id' => 0,
 *     'quiz_id' => 0,
 *     'video_id' => 0,
 *     'attempt_id' => 0,
 *   ];
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}
if (!function_exists('isLoggedIn') || !isLoggedIn() || (function_exists('getCurrentUserRole') && getCurrentUserRole() !== 'student')) {
    return;
}

require_once __DIR__ . '/student_activity.php';
student_activity_ensure_schema($conn);

$boot = is_array($studentActivityBoot ?? null) ? $studentActivityBoot : [];
$cfg = [
    'endpoint' => function_exists('ereview_url') ? ereview_url('student_activity_api') : 'student_activity_api',
    'csrf' => function_exists('generateCSRFToken') ? generateCSRFToken() : '',
    'page_key' => (string) ($boot['page_key'] ?? (function_exists('ereview_page_basename') ? ereview_page_basename() : '')),
    'page_title' => (string) ($boot['page_title'] ?? ($pageTitle ?? '')),
    'page_url' => (string) ($boot['page_url'] ?? ''),
    'subject_id' => (int) ($boot['subject_id'] ?? 0),
    'lesson_id' => (int) ($boot['lesson_id'] ?? 0),
    'quiz_id' => (int) ($boot['quiz_id'] ?? 0),
    'video_id' => (int) ($boot['video_id'] ?? 0),
    'attempt_id' => (int) ($boot['attempt_id'] ?? 0),
    'resume_sec' => (float) ($boot['resume_sec'] ?? 0),
];

// Always refresh live session location; only throttle the event log.
$uid = (int) getCurrentUserId();
$pageUrl = $cfg['page_url'] !== '' ? $cfg['page_url'] : (string) ($_SERVER['REQUEST_URI'] ?? '');
student_activity_touch_session($conn, $uid, [
    'page_key' => $cfg['page_key'],
    'page_title' => $cfg['page_title'],
    'page_url' => $pageUrl,
    'subject_id' => $cfg['subject_id'],
    'lesson_id' => $cfg['lesson_id'],
    'quiz_id' => $cfg['quiz_id'],
    'video_id' => $cfg['video_id'],
]);

$eventType = (string) ($boot['event_type'] ?? 'page_view');
$throttleKey = 'sa_boot_' . $eventType . '_' . md5(json_encode([
    $cfg['page_key'], $cfg['lesson_id'], $cfg['video_id'], $cfg['quiz_id'], $cfg['handout_id'] ?? 0,
]));
$now = time();
$last = (int) ($_SESSION[$throttleKey] ?? 0);
if ($now - $last >= 20) {
    $_SESSION[$throttleKey] = $now;
    student_activity_log_event($conn, $uid, $eventType, [
        'subject_id' => $cfg['subject_id'],
        'lesson_id' => $cfg['lesson_id'],
        'quiz_id' => $cfg['quiz_id'],
        'video_id' => $cfg['video_id'],
        'handout_id' => (int) ($boot['handout_id'] ?? 0),
        'attempt_id' => $cfg['attempt_id'],
        'page_key' => $cfg['page_key'],
        'page_title' => $cfg['page_title'],
        'page_url' => $pageUrl,
    ]);
}

$jsFile = __DIR__ . '/../assets/js/student-activity.js';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$jsHref = ($base === '' ? '' : $base) . '/assets/js/student-activity.js';
if (is_file($jsFile)) {
    $jsHref .= '?v=' . filemtime($jsFile);
}
?>
<script>window.EreviewStudentActivity = <?php echo json_encode($cfg, JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?php echo htmlspecialchars($jsHref, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php
