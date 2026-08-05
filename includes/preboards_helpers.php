<?php
declare(strict_types=1);

/**
 * Preboard set access: manual is_open or scheduled opens_at / closes_at window.
 */

function preboards_app_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = @timezone_open('Asia/Manila') ?: new DateTimeZone('UTC');
    }
    return $tz;
}

function preboards_set_uses_schedule(array $set): bool
{
    return (int) ($set['use_schedule'] ?? 0) === 1;
}

/** Scheduled window length in seconds (closes_at − opens_at), or null if not scheduled / invalid. */
function preboards_set_schedule_window_seconds(array $set): ?int
{
    if (!preboards_set_uses_schedule($set)) {
        return null;
    }
    $opensRaw = trim((string) ($set['opens_at'] ?? ''));
    $closesRaw = trim((string) ($set['closes_at'] ?? ''));
    if ($opensRaw === '' || $closesRaw === '') {
        return null;
    }
    $opensTs = strtotime($opensRaw);
    $closesTs = strtotime($closesRaw);
    if ($opensTs === false || $closesTs === false || $closesTs <= $opensTs) {
        return null;
    }
    return $closesTs - $opensTs;
}

/** Duration shown to students and used for the exam timer ring (schedule window or manual time limit). */
function preboards_set_effective_time_limit_seconds(array $set): int
{
    $window = preboards_set_schedule_window_seconds($set);
    if ($window !== null && $window > 0) {
        return $window;
    }
    $limit = (int) ($set['time_limit_seconds'] ?? 3600);
    return $limit > 0 ? $limit : 3600;
}

/**
 * When a student starts or resumes, expires_at is the earlier of:
 * - scheduled closes_at (hard window end), and
 * - started_at + effective duration.
 */
function preboards_attempt_compute_expires_at(array $set, ?int $startedTs = null): string
{
    $startedTs = $startedTs ?? time();
    $candidates = [];

    if (preboards_set_uses_schedule($set)) {
        $closesRaw = trim((string) ($set['closes_at'] ?? ''));
        if ($closesRaw !== '') {
            $closesTs = strtotime($closesRaw);
            if ($closesTs !== false) {
                $candidates[] = $closesTs;
            }
        }
    }

    $limit = preboards_set_effective_time_limit_seconds($set);
    if ($limit > 0) {
        $candidates[] = $startedTs + $limit;
    }

    if ($candidates === []) {
        return date('Y-m-d H:i:s', $startedTs + 3600);
    }

    return date('Y-m-d H:i:s', min($candidates));
}

/** Keep in-progress attempts aligned when schedule changes or legacy rows used time_limit only. */
function preboards_attempt_sync_expires_at(mysqli $conn, array $set, array $attempt): void
{
    if (($attempt['status'] ?? '') !== 'in_progress') {
        return;
    }
    $attemptId = (int) ($attempt['preboards_attempt_id'] ?? 0);
    if ($attemptId <= 0) {
        return;
    }
    $startedRaw = trim((string) ($attempt['started_at'] ?? ''));
    $startedTs = $startedRaw !== '' ? strtotime($startedRaw) : false;
    if ($startedTs === false) {
        $startedTs = time();
    }
    $expected = preboards_attempt_compute_expires_at($set, $startedTs);
    $current = trim((string) ($attempt['expires_at'] ?? ''));
    if ($current === $expected) {
        return;
    }
    $stmt = mysqli_prepare($conn, 'UPDATE preboards_attempts SET expires_at=? WHERE preboards_attempt_id=? AND status=\'in_progress\' LIMIT 1');
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'si', $expected, $attemptId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function preboards_set_is_open_for_students(array $set): bool
{
    if (preboards_set_uses_schedule($set)) {
        $now = new DateTime('now', preboards_app_timezone());
        $opensRaw = trim((string) ($set['opens_at'] ?? ''));
        $closesRaw = trim((string) ($set['closes_at'] ?? ''));
        if ($opensRaw === '' && $closesRaw === '') {
            return false;
        }
        if ($opensRaw !== '') {
            $opens = DateTime::createFromFormat('Y-m-d H:i:s', $opensRaw, preboards_app_timezone())
                ?: DateTime::createFromFormat('Y-m-d H:i', $opensRaw, preboards_app_timezone());
            if ($opens && $now < $opens) {
                return false;
            }
        }
        if ($closesRaw !== '') {
            $closes = DateTime::createFromFormat('Y-m-d H:i:s', $closesRaw, preboards_app_timezone())
                ?: DateTime::createFromFormat('Y-m-d H:i', $closesRaw, preboards_app_timezone());
            if ($closes && $now > $closes) {
                return false;
            }
        }
        return true;
    }
    return (int) ($set['is_open'] ?? 0) === 1;
}

/** @return array{key:string,label:string,opens_display:?string,closes_display:?string} */
function preboards_set_access_meta(array $set, bool $forStudent = false): array
{
    $fmt = static function (?string $raw): ?string {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts ? date('M j, Y g:i A', $ts) : null;
    };

    if (preboards_set_uses_schedule($set)) {
        $opensDisplay = $fmt((string) ($set['opens_at'] ?? ''));
        $closesDisplay = $fmt((string) ($set['closes_at'] ?? ''));
        $openNow = preboards_set_is_open_for_students($set);
        if ($openNow) {
            $durationLabel = null;
            $windowSecs = preboards_set_schedule_window_seconds($set);
            if ($windowSecs !== null && $windowSecs > 0) {
                if (!function_exists('formatTimeLimitSeconds')) {
                    require_once __DIR__ . '/quiz_helpers.php';
                }
                $durationLabel = formatTimeLimitSeconds($windowSecs);
            }
            return [
                'key' => 'open',
                'label' => $closesDisplay
                    ? ('Open until ' . $closesDisplay . ($durationLabel ? ' · ' . $durationLabel : ''))
                    : ($forStudent ? 'Open' : 'Open (scheduled)'),
                'opens_display' => $opensDisplay,
                'closes_display' => $closesDisplay,
            ];
        }
        $now = time();
        $opensTs = !empty($set['opens_at']) ? strtotime((string) $set['opens_at']) : false;
        $closesTs = !empty($set['closes_at']) ? strtotime((string) $set['closes_at']) : false;
        if ($closesTs && $now > $closesTs) {
            return [
                'key' => 'closed',
                'label' => 'Closed · ended ' . ($closesDisplay ?: ''),
                'opens_display' => $opensDisplay,
                'closes_display' => $closesDisplay,
            ];
        }
        if ($opensTs && $now < $opensTs) {
            return [
                'key' => 'upcoming',
                'label' => 'Opens ' . ($opensDisplay ?: 'soon'),
                'opens_display' => $opensDisplay,
                'closes_display' => $closesDisplay,
            ];
        }
        return [
            'key' => 'locked',
            'label' => $forStudent ? 'Not open yet' : 'Scheduled · not open',
            'opens_display' => $opensDisplay,
            'closes_display' => $closesDisplay,
        ];
    }

    $manualOpen = (int) ($set['is_open'] ?? 0) === 1;
    return [
        'key' => $manualOpen ? 'open' : 'locked',
        'label' => $manualOpen ? 'Open' : 'Locked',
        'opens_display' => null,
        'closes_display' => null,
    ];
}

function preboards_datetime_local_to_sql(?string $input): ?string
{
    $input = trim((string) $input);
    if ($input === '') {
        return null;
    }
    $input = str_replace('T', ' ', $input);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $input)) {
        $input .= ':00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $input, preboards_app_timezone());
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function preboards_datetime_sql_to_local(?string $sql): string
{
    $sql = trim((string) $sql);
    if ($sql === '') {
        return '';
    }
    $ts = strtotime($sql);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function preboards_count_pending_requests(mysqli $conn): int
{
    $res = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM preboards_requests WHERE status='pending'");
    if (!$res) {
        return 0;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    return (int) ($row['c'] ?? 0);
}

/**
 * Pending preboard requests with student + set + subject for admin inbox.
 *
 * @return list<array<string, mixed>>
 */
function preboards_list_pending_requests(mysqli $conn, ?int $subjectId = null): array
{
    $sql = "SELECT r.preboards_request_id, r.user_id, r.preboards_set_id, r.request_type, r.requested_at,
      u.full_name, u.email, s.set_label, s.preboards_subject_id, ps.subject_name
      FROM preboards_requests r
      INNER JOIN preboards_sets s ON s.preboards_set_id = r.preboards_set_id
      INNER JOIN preboards_subjects ps ON ps.preboards_subject_id = s.preboards_subject_id
      INNER JOIN users u ON u.user_id = r.user_id
      WHERE r.status = 'pending'";
    if ($subjectId !== null && $subjectId > 0) {
        $sql .= ' AND s.preboards_subject_id = ' . (int) $subjectId;
    }
    $sql .= ' ORDER BY r.requested_at DESC';
    $out = [];
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $out[] = $row;
        }
        mysqli_free_result($res);
    }
    return $out;
}

/**
 * Approve or deny a pending request. Grants access/retake token on approve.
 * Notifies the student (in-app + email) after a successful decision.
 */
function preboards_decide_request(mysqli $conn, int $reqId, string $decision, int $adminId, ?int $requireSubjectId = null): bool
{
    if ($reqId <= 0 || !in_array($decision, ['approved', 'denied'], true) || $adminId <= 0) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT r.*, s.preboards_subject_id, s.set_label, s.title AS set_title,
                ps.subject_name
         FROM preboards_requests r
         INNER JOIN preboards_sets s ON s.preboards_set_id = r.preboards_set_id
         INNER JOIN preboards_subjects ps ON ps.preboards_subject_id = s.preboards_subject_id
         WHERE r.preboards_request_id = ? AND r.status = 'pending'
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $reqId);
    mysqli_stmt_execute($stmt);
    $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$req) {
        return false;
    }
    if ($requireSubjectId !== null && (int) $req['preboards_subject_id'] !== (int) $requireSubjectId) {
        return false;
    }

    $decAt = date('Y-m-d H:i:s');
    $upd = mysqli_prepare($conn, "UPDATE preboards_requests SET status=?, decided_at=?, decided_by=? WHERE preboards_request_id=? AND status='pending'");
    if (!$upd) {
        return false;
    }
    mysqli_stmt_bind_param($upd, 'ssii', $decision, $decAt, $adminId, $reqId);
    mysqli_stmt_execute($upd);
    $ok = mysqli_stmt_affected_rows($upd) > 0;
    mysqli_stmt_close($upd);
    if (!$ok) {
        return false;
    }

    if ($decision === 'approved') {
        $uid = (int) $req['user_id'];
        $sid = (int) $req['preboards_set_id'];
        if (($req['request_type'] ?? '') === 'open') {
            $ins = mysqli_prepare($conn, "INSERT INTO preboards_set_access (user_id, preboards_set_id, granted_by, used_at, revoked_at)
              VALUES (?, ?, ?, NULL, NULL)
              ON DUPLICATE KEY UPDATE granted_at=CURRENT_TIMESTAMP, granted_by=VALUES(granted_by), used_at=NULL, revoked_at=NULL");
            if ($ins) {
                mysqli_stmt_bind_param($ins, 'iii', $uid, $sid, $adminId);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        } elseif (($req['request_type'] ?? '') === 'retake') {
            $ins = mysqli_prepare($conn, "INSERT INTO preboards_retake_tokens (user_id, preboards_set_id, granted_by) VALUES (?, ?, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, 'iii', $uid, $sid, $adminId);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }
    }

    try {
        preboards_notify_student_request_decision($conn, $req, $decision, $adminId);
    } catch (Throwable $e) {
        error_log('preboards_decide_request notify failed req=' . $reqId . ' ' . $e->getMessage());
    }

    return true;
}

/**
 * In-app + email when a preboards access/retake request is approved or denied.
 * Failure never undoes the decision.
 *
 * @param array<string,mixed> $req Row with user_id, request_type, preboards_subject_id, set_label, subject_name, …
 * @return array{ok:bool,sent?:bool,in_app?:bool,error?:string}
 */
function preboards_notify_student_request_decision(
    mysqli $conn,
    array $req,
    string $decision,
    int $adminId = 0
): array {
    $userId = (int) ($req['user_id'] ?? 0);
    if ($userId <= 0 || !in_array($decision, ['approved', 'denied'], true)) {
        return ['ok' => false, 'error' => 'invalid_args'];
    }

    $reqType = (string) ($req['request_type'] ?? 'open');
    $isRetake = $reqType === 'retake';
    $kind = $isRetake ? 'retake' : 'access';
    $setLabel = trim((string) ($req['set_label'] ?? ''));
    if ($setLabel === '') {
        $setLabel = 'Set';
    }
    $subjectName = trim((string) ($req['subject_name'] ?? 'Preboards'));
    $subjectId = (int) ($req['preboards_subject_id'] ?? 0);
    $link = $subjectId > 0
        ? ('student_preboards_view?preboards_subject_id=' . $subjectId)
        : 'student_preboards';

    $approved = $decision === 'approved';
    if ($approved) {
        $title = $isRetake ? 'Preboards retake approved' : 'Preboards access approved';
        $msg = 'Your request for ' . $kind . ' to Set ' . $setLabel . ' (' . $subjectName . ') was approved. You can open it from Preboards.';
    } else {
        $title = $isRetake ? 'Preboards retake not approved' : 'Preboards access not approved';
        $msg = 'Your request for ' . $kind . ' to Set ' . $setLabel . ' (' . $subjectName . ') was not approved.';
    }

    $inApp = false;
    if (is_file(__DIR__ . '/notification_helpers.php')) {
        require_once __DIR__ . '/notification_helpers.php';
        if (function_exists('notifications_create_for_user')) {
            $inApp = notifications_create_for_user(
                $conn,
                $userId,
                'student',
                $title,
                $msg,
                $link,
                $approved
                    ? ($isRetake ? 'preboards_retake_approved' : 'preboards_access_approved')
                    : ($isRetake ? 'preboards_retake_denied' : 'preboards_access_denied'),
                $adminId > 0 ? $adminId : null
            );
        }
    }

    $emailSent = false;
    if (is_file(__DIR__ . '/commerce_notifications.php')) {
        require_once __DIR__ . '/commerce_notifications.php';
        $student = function_exists('commerce_notify_load_student')
            ? commerce_notify_load_student($conn, $userId)
            : ['ok' => false];
        $config = function_exists('commerce_notify_load_mail_config')
            ? commerce_notify_load_mail_config()
            : null;
        if (!empty($student['ok']) && is_array($config)) {
            $name = htmlspecialchars((string) $student['full_name'], ENT_QUOTES, 'UTF-8');
            $setEsc = htmlspecialchars($setLabel, ENT_QUOTES, 'UTF-8');
            $subEsc = htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8');
            $loginHint = htmlspecialchars(
                function_exists('commerce_notify_login_hint') ? commerce_notify_login_hint() : 'login',
                ENT_QUOTES,
                'UTF-8'
            );
            $mailSubject = 'LCRC eReview — ' . $title;
            if ($approved) {
                $bodyHtml = "<p>Dear {$name},</p>"
                    . '<p>Your Preboards <strong>' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '</strong> request for '
                    . "<strong>Set {$setEsc}</strong> ({$subEsc}) has been <strong>approved</strong>.</p>"
                    . '<p>Sign in and open Preboards to continue:</p>'
                    . "<p><a href=\"{$loginHint}\">{$loginHint}</a></p>";
                $plain = "Dear {$student['full_name']},\n\nYour Preboards {$kind} request for Set {$setLabel} ({$subjectName}) was approved.\n"
                    . 'Sign in at: ' . (function_exists('commerce_notify_login_hint') ? commerce_notify_login_hint() : 'login') . "\n\nLCRC eReview Admin Team\n";
            } else {
                $bodyHtml = "<p>Dear {$name},</p>"
                    . '<p>Your Preboards <strong>' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '</strong> request for '
                    . "<strong>Set {$setEsc}</strong> ({$subEsc}) was <strong>not approved</strong>.</p>"
                    . '<p>If you believe this was a mistake, please contact LCRC eReview support.</p>';
                $plain = "Dear {$student['full_name']},\n\nYour Preboards {$kind} request for Set {$setLabel} ({$subjectName}) was not approved.\n\nLCRC eReview Admin Team\n";
            }
            $html = commerce_notify_wrap_html($title, $bodyHtml);
            $sent = commerce_notify_dispatch((string) $student['email'], $mailSubject, $html, $plain, $config);
            $emailSent = !empty($sent['ok']);
        } elseif ($config === null) {
            error_log('preboards_notify: mail config missing; student email skipped for user=' . $userId);
        }
    }

    return [
        'ok' => $inApp || $emailSent,
        'sent' => $emailSent,
        'in_app' => $inApp,
    ];
}

/** @return array{access:int, retake:int, total:int} */
function preboards_pending_request_counts(mysqli $conn): array
{
    $access = 0;
    $retake = 0;
    $res = @mysqli_query($conn, "SELECT request_type, COUNT(*) AS c FROM preboards_requests WHERE status='pending' GROUP BY request_type");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $type = (string) ($row['request_type'] ?? '');
            $count = (int) ($row['c'] ?? 0);
            if ($type === 'open') {
                $access = $count;
            } elseif ($type === 'retake') {
                $retake = $count;
            }
        }
        mysqli_free_result($res);
    }
    return ['access' => $access, 'retake' => $retake, 'total' => $access + $retake];
}

/** @return array<string, string> */
function preboards_get_question_choices(array $q): array
{
    $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    $out = [];
    foreach ($letters as $letter) {
        $col = 'choice_' . strtolower($letter);
        if (isset($q[$col]) && trim((string) $q[$col]) !== '') {
            $out[$letter] = trim((string) $q[$col]);
        }
    }
    return $out;
}

function preboards_format_score(?float $score): string
{
    if ($score === null) {
        return '—';
    }
    return number_format($score, 1) . '%';
}

function preboards_format_datetime(?string $sql): string
{
    $sql = trim((string) $sql);
    if ($sql === '') {
        return '—';
    }
    $ts = strtotime($sql);
    return $ts ? date('M j, Y g:i A', $ts) : '—';
}

function preboards_format_datetime_short(?string $sql): array
{
    $sql = trim((string) $sql);
    if ($sql === '') {
        return ['date' => '—', 'time' => ''];
    }
    $ts = strtotime($sql);
    if (!$ts) {
        return ['date' => '—', 'time' => ''];
    }
    return ['date' => date('M j, Y', $ts), 'time' => date('g:i A', $ts)];
}

function preboards_format_duration(?string $startedAt, ?string $endedAt = null): string
{
    $startedAt = trim((string) $startedAt);
    if ($startedAt === '') {
        return '—';
    }
    $startTs = strtotime($startedAt);
    if (!$startTs) {
        return '—';
    }
    $endTs = $endedAt !== null && trim($endedAt) !== '' ? strtotime($endedAt) : time();
    if (!$endTs || $endTs < $startTs) {
        return '—';
    }
    $secs = $endTs - $startTs;
    $h = (int) floor($secs / 3600);
    $m = (int) floor(($secs % 3600) / 60);
    $s = (int) ($secs % 60);
    if ($h > 0) {
        return sprintf('%dh %02dm', $h, $m);
    }
    if ($m > 0) {
        return sprintf('%dm %02ds', $m, $s);
    }
    return sprintf('%ds', $s);
}

function preboards_attempt_status_label(string $status): string
{
    return match ($status) {
        'submitted' => 'Submitted',
        'in_progress' => 'In progress',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

/** @return 'high'|'mid'|'low'|'pending' */
function preboards_score_tier(?float $score, bool $isSubmitted = true): string
{
    if (!$isSubmitted || $score === null) {
        return 'pending';
    }
    if ($score >= 75) {
        return 'high';
    }
    if ($score >= 50) {
        return 'mid';
    }
    return 'low';
}

/** @return array{display_name:string, subline:string} */
function preboards_student_display_lines(?string $fullName, ?string $email): array
{
    $name = trim((string) $fullName);
    $mail = trim((string) $email);
    if ($name === '' && $mail === '') {
        return ['display_name' => 'Unknown student', 'subline' => ''];
    }
    if ($name === '') {
        return ['display_name' => $mail, 'subline' => ''];
    }
    if ($mail === '' || strcasecmp($name, $mail) === 0) {
        return ['display_name' => $name, 'subline' => ''];
    }
    return ['display_name' => $name, 'subline' => $mail];
}
