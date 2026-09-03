<?php
/**
 * Admin helpers for login account window (users.access_start / access_end).
 * Distinct from commerce grants / SCA — but Set/Extend MUST keep grants in sync
 * because commerce_student_can_login requires an active access_grants row.
 */

/**
 * @return 'hour'|'day'|'month'|'year'
 */
function admin_normalize_duration_unit(string $unit): string
{
    $u = strtolower(trim($unit));
    if ($u === 'hour' || $u === 'hours' || $u === 'h' || $u === 'hr' || $u === 'hrs') {
        return 'hour';
    }
    if ($u === 'day' || $u === 'days' || $u === 'd') {
        return 'day';
    }
    if ($u === 'year' || $u === 'years' || $u === 'y') {
        return 'year';
    }
    return 'month';
}

/** MySQL INTERVAL unit keyword (safe whitelist). */
function admin_sql_interval_unit(string $unit): string
{
    $u = admin_normalize_duration_unit($unit);
    if ($u === 'hour') {
        return 'HOUR';
    }
    if ($u === 'day') {
        return 'DAY';
    }
    if ($u === 'year') {
        return 'YEAR';
    }
    return 'MONTH';
}

/** Approximate months for legacy access_months column (display/compat only). */
function admin_duration_to_months_equiv(int $value, string $unit): int
{
    $value = max(0, $value);
    $u = admin_normalize_duration_unit($unit);
    if ($u === 'hour') {
        return max(1, (int) ceil($value / (24 * 30)));
    }
    if ($u === 'day') {
        return max(1, (int) ceil($value / 30));
    }
    if ($u === 'year') {
        return max(1, $value * 12);
    }
    return max(1, $value);
}

function admin_duration_unit_label(string $unit, int $value = 1): string
{
    $u = admin_normalize_duration_unit($unit);
    $plural = $value === 1 ? '' : 's';
    if ($u === 'hour') {
        return 'hour' . $plural;
    }
    if ($u === 'day') {
        return 'day' . $plural;
    }
    if ($u === 'year') {
        return 'year' . $plural;
    }
    return 'month' . $plural;
}

/**
 * Validate duration value for a unit. Returns error message or null when OK.
 */
function admin_validate_duration(int $value, string $unit): ?string
{
    if ($value <= 0) {
        return 'Enter a valid duration (1 or more).';
    }
    $u = admin_normalize_duration_unit($unit);
    if ($u === 'hour' && $value > 8760) {
        return 'Hour duration is too large (max 8760).';
    }
    if ($u === 'day' && $value > 3660) {
        return 'Day duration is too large (max 3660).';
    }
    if ($u === 'month' && $value > 120) {
        return 'Month duration is too large (max 120).';
    }
    if ($u === 'year' && $value > 10) {
        return 'Year duration is too large (max 10).';
    }
    return null;
}

function admin_safe_return_to(string $returnTo, string $fallback = 'admin_students'): string
{
    $returnTo = trim($returnTo);
    if ($returnTo === '' || strpos($returnTo, '://') !== false || strpos($returnTo, '//') === 0 || strpos($returnTo, '/') === 0) {
        return $fallback;
    }
    return $returnTo;
}

/**
 * Ensure users.status ENUM includes archived (idempotent).
 */
function admin_ensure_user_status_archived(mysqli $conn): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status'");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return false;
    }
    $type = strtolower((string) ($row['Type'] ?? ''));
    if (strpos($type, "'archived'") !== false) {
        $done = true;
        return true;
    }
    // Preserve existing enum values; append archived.
    $ok = (bool) @mysqli_query(
        $conn,
        "ALTER TABLE users
         MODIFY COLUMN status ENUM('pending','approved','rejected','archived')
         NOT NULL DEFAULT 'pending'"
    );
    if ($ok) {
        $done = true;
    }
    return $ok;
}

/**
 * After Set/Extend/Custom of users.access_end, keep commerce grants aligned so
 * login + content gates match the admin-visible account window.
 *
 * @param 'set'|'extend'|'custom' $mode
 * @param array{
 *   duration_value?:int,
 *   interval_unit?:string,
 *   absolute_end?:string|null
 * } $opts
 * @return array{ok:bool,grants_touched?:int,created?:bool,error?:string}
 */
function admin_sync_access_grants_with_window(mysqli $conn, int $userId, string $mode, array $opts = []): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }
    if (!function_exists('commerce_schema_ready')) {
        $gate = __DIR__ . '/commerce_access_gate.php';
        if (is_file($gate)) {
            require_once $gate;
        }
    }
    if (!function_exists('commerce_schema_ready') || !commerce_schema_ready($conn)) {
        return ['ok' => true, 'grants_touched' => 0, 'skipped' => true];
    }

    $mode = strtolower(trim($mode));
    $durationValue = max(0, (int) ($opts['duration_value'] ?? 0));
    $intervalUnit = strtoupper(trim((string) ($opts['interval_unit'] ?? 'MONTH')));
    $allowedIntervals = ['HOUR' => true, 'DAY' => true, 'MONTH' => true, 'YEAR' => true];
    if (!isset($allowedIntervals[$intervalUnit])) {
        $intervalUnit = 'MONTH';
    }

    // Resolve target end from users row (authoritative after UPDATE).
    $absoluteEnd = trim((string) ($opts['absolute_end'] ?? ''));
    if ($absoluteEnd === '') {
        $q = mysqli_prepare(
            $conn,
            "SELECT access_end FROM users WHERE user_id = ? AND role = 'student' LIMIT 1"
        );
        if ($q) {
            mysqli_stmt_bind_param($q, 'i', $userId);
            mysqli_stmt_execute($q);
            $r = mysqli_stmt_get_result($q);
            $row = $r ? mysqli_fetch_assoc($r) : null;
            mysqli_stmt_close($q);
            $absoluteEnd = trim((string) ($row['access_end'] ?? ''));
        }
    }
    if ($absoluteEnd === '' || strtotime($absoluteEnd) === false) {
        return ['ok' => false, 'error' => 'missing_access_end'];
    }

    $touched = 0;
    $created = false;

    if ($mode === 'extend' && $durationValue > 0) {
        // Push future-active grants forward by the same interval.
        $sql = "UPDATE access_grants
                SET ends_at = DATE_ADD(ends_at, INTERVAL ? {$intervalUnit}),
                    updated_at = NOW()
                WHERE user_id = ?
                  AND status = 'active'
                  AND ends_at > NOW()
                  AND source IN ('purchase','free_access','admin_manual')";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $durationValue, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $touched = (int) mysqli_stmt_affected_rows($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        // Set / custom: align active grant ends to the account window end (may shorten or lengthen).
        $sql = "UPDATE access_grants
                SET ends_at = ?,
                    updated_at = NOW()
                WHERE user_id = ?
                  AND status = 'active'
                  AND source IN ('purchase','free_access','admin_manual')";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $absoluteEnd, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $touched = (int) mysqli_stmt_affected_rows($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }

    // If no usable active grant remains, create/revive an admin_manual Full LMS grant
    // so Set/Extend actually restores login (server-authoritative).
    $hasActive = false;
    $chk = mysqli_prepare(
        $conn,
        "SELECT grant_id FROM access_grants
         WHERE user_id = ?
           AND status = 'active'
           AND ends_at > NOW()
           AND source IN ('purchase','free_access','admin_manual')
         LIMIT 1"
    );
    if ($chk) {
        mysqli_stmt_bind_param($chk, 'i', $userId);
        mysqli_stmt_execute($chk);
        $cr = mysqli_stmt_get_result($chk);
        $hasActive = $cr && mysqli_fetch_assoc($cr);
        mysqli_stmt_close($chk);
    }

    if (!$hasActive) {
        $adminId = (int) (function_exists('getCurrentUserId') ? (getCurrentUserId() ?? 0) : 0);
        if ($adminId <= 0) {
            $ar = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1");
            if ($ar && ($arow = mysqli_fetch_row($ar))) {
                $adminId = (int) ($arow[0] ?? 0);
            }
        }
        $source = 'admin_manual';
        $ctype = 'full_lms';
        $cid = 0;
        $label = 'Account window sync';
        $gStatus = 'active';
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO access_grants
              (user_id, source, payment_id, payment_item_id, free_access_request_id,
               content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
             VALUES (?, ?, NULL, NULL, NULL, ?, ?, ?, NOW(), ?, ?, ?)'
        );
        if ($ins && $adminId > 0) {
            // i s s i s s s i
            mysqli_stmt_bind_param(
                $ins,
                'ississsi',
                $userId,
                $source,
                $ctype,
                $cid,
                $label,
                $absoluteEnd,
                $gStatus,
                $adminId
            );
            if (mysqli_stmt_execute($ins)) {
                $created = true;
                $touched += 1;
            }
            mysqli_stmt_close($ins);
        }

        // Preserve existing SCA; only seed Full LMS when the student has zero rows.
        if ($created) {
            $scaFile = __DIR__ . '/student_content_access.php';
            if (!function_exists('sca_save_user_permissions_preserving_commerce') && is_file($scaFile)) {
                require_once $scaFile;
            }
            $cnt = 0;
            $cq = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM student_content_permissions WHERE user_id = ?');
            if ($cq) {
                mysqli_stmt_bind_param($cq, 'i', $userId);
                mysqli_stmt_execute($cq);
                $cres = mysqli_stmt_get_result($cq);
                $crow = $cres ? mysqli_fetch_assoc($cres) : null;
                mysqli_stmt_close($cq);
                $cnt = (int) ($crow['c'] ?? 0);
            }
            if ($cnt === 0 && function_exists('sca_save_user_permissions_preserving_commerce')) {
                sca_save_user_permissions_preserving_commerce(
                    $conn,
                    $userId,
                    [['content_type' => 'full_lms', 'content_id' => 0]],
                    $adminId > 0 ? $adminId : null
                );
            }
        }
    }

    return ['ok' => true, 'grants_touched' => $touched, 'created' => $created];
}
