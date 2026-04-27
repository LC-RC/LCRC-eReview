<?php
/**
 * Download a calendar reminder for enrollment access end (student / college_student).
 */

/**
 * @param string $s
 * @return string
 */
function ereview_ics_escape($s) {
    $s = str_replace(["\r\n", "\n", "\r"], [' ', ' ', ' '], $s);
    return addcslashes($s, ",;\\");
}

require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'student' && $role !== 'college_student') {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

require_once __DIR__ . '/db.php';
$stmt = mysqli_prepare($conn, 'SELECT access_end FROM users WHERE user_id = ? LIMIT 1');
if (!$stmt) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$row || empty($row['access_end'])) {
    header('HTTP/1.1 404 Not Found');
    exit('No access end date');
}

$endTs = strtotime((string)$row['access_end']);
if ($endTs === false) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}

$tzManila = new DateTimeZone('Asia/Manila');
$dt = new DateTime('@' . $endTs);
$dt->setTimezone($tzManila);

$dtUtc = clone $dt;
$dtUtc->setTimezone(new DateTimeZone('UTC'));
$stampUtc = gmdate('Ymd\THis\Z');
$endUtc = $dtUtc->format('Ymd\THis\Z');

$uidSafe = preg_replace('/[^0-9]/', '', (string)$uid);
$uidLine = 'access-end-' . $uidSafe . '@lcrc-ereview';

$summary = 'LCRC eReview — enrollment access ends';
$desc = 'Your LCRC eReview enrollment access window closes at this time (Philippines, PHT). Renew with your administrator if needed.';

$ics = implode("\r\n", [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//LCRC eReview//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . $uidLine,
    'DTSTAMP:' . $stampUtc,
    'DTSTART:' . $endUtc,
    'SUMMARY:' . ereview_ics_escape($summary),
    'DESCRIPTION:' . ereview_ics_escape($desc),
    'LOCATION:LCRC eReview',
    'END:VEVENT',
    'END:VCALENDAR',
]);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="lcrc-ereview-access-end.ics"');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $ics;
exit;
