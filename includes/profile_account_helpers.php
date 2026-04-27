<?php
/**
 * Profile module: column detection, validation, and load/update helpers.
 */

if (!function_exists('ereview_profile_users_columns')) {
    /**
     * @return array<string,bool>
     */
    function ereview_profile_users_columns(mysqli $conn): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $names = [];
        $res = @mysqli_query($conn, 'SHOW COLUMNS FROM users');
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (!empty($row['Field'])) {
                    $names[$row['Field']] = true;
                }
            }
            mysqli_free_result($res);
        }
        $cache = [
            'phone' => isset($names['phone']),
            'profile_bio' => isset($names['profile_bio']),
            'profile_picture' => isset($names['profile_picture']),
            'profile_cover' => isset($names['profile_cover']),
            'use_default_avatar' => isset($names['use_default_avatar']),
            'review_type' => isset($names['review_type']),
            'school' => isset($names['school']),
            'school_other' => isset($names['school_other']),
            'payment_proof' => isset($names['payment_proof']),
            'last_login_at' => isset($names['last_login_at']),
            'last_login_ip' => isset($names['last_login_ip']),
            'access_start' => isset($names['access_start']),
            'access_end' => isset($names['access_end']),
        ];
        return $cache;
    }
}

if (!function_exists('ereview_profile_role_display')) {
    function ereview_profile_role_display(?string $role): string
    {
        switch ($role) {
            case 'admin':
                return 'Administrator';
            case 'professor_admin':
                return 'Professor';
            case 'college_student':
                return 'College student';
            case 'student':
            default:
                return 'Student';
        }
    }
}

if (!function_exists('ereview_profile_password_meets_policy')) {
    /**
     * @return array{ok:bool,message:string}
     */
    function ereview_profile_password_meets_policy(string $password): array
    {
        if (strlen($password) < 8) {
            return ['ok' => false, 'message' => 'Password must be at least 8 characters.'];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['ok' => false, 'message' => 'Password must include an uppercase letter.'];
        }
        if (!preg_match('/[a-z]/', $password)) {
            return ['ok' => false, 'message' => 'Password must include a lowercase letter.'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['ok' => false, 'message' => 'Password must include a number.'];
        }
        return ['ok' => true, 'message' => ''];
    }
}

if (!function_exists('ereview_profile_sanitize_phone')) {
    function ereview_profile_sanitize_phone(string $raw): string
    {
        $s = preg_replace('/[^\d+\-\s().]/', '', $raw);
        return trim((string)$s);
    }
}

if (!function_exists('ereview_profile_safe_delete_upload')) {
    /**
     * Remove a prior avatar file only if it lives under allowed upload dirs.
     */
    function ereview_profile_safe_delete_upload(?string $relativePath): void
    {
        $rel = trim(str_replace('\\', '/', (string)$relativePath));
        if ($rel === '' || strpos($rel, '..') !== false) {
            return;
        }
        $rel = ltrim($rel, '/');
        $allowed = ['uploads/profile/', 'uploads/avatars/'];
        $ok = false;
        foreach ($allowed as $prefix) {
            if (strncasecmp($rel, $prefix, strlen($prefix)) === 0) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return;
        }
        $full = realpath(__DIR__ . '/../' . $rel);
        $root = realpath(__DIR__ . '/../uploads');
        if ($full === false || $root === false || strpos($full, $root) !== 0) {
            return;
        }
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

if (!function_exists('ereview_profile_save_uploaded_image')) {
    /**
     * @return array{ok:bool,path?:string,message?:string}
     */
    function ereview_profile_save_uploaded_image(array $file): array
    {
        if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Image upload failed.'];
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'Image must be 2 MB or smaller.'];
        }
        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($map[$mime])) {
            return ['ok' => false, 'message' => 'Use JPG, PNG, WebP, or GIF.'];
        }
        $ext = $map[$mime];
        $dir = __DIR__ . '/../uploads/profile';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'message' => 'Could not create upload directory.'];
        }
        $name = 'profile_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $destFs = $dir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($tmp, $destFs)) {
            return ['ok' => false, 'message' => 'Could not save image.'];
        }
        return ['ok' => true, 'path' => 'uploads/profile/' . $name];
    }
}

if (!function_exists('ereview_profile_fetch_row')) {
    /**
     * @return array<string,mixed>|null
     */
    function ereview_profile_fetch_row(mysqli $conn, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $cols = ereview_profile_users_columns($conn);
        $fields = ['user_id', 'full_name', 'email', 'role', 'status', 'created_at', 'updated_at'];
        if ($cols['review_type']) {
            $fields[] = 'review_type';
        }
        if ($cols['school']) {
            $fields[] = 'school';
        }
        if ($cols['school_other']) {
            $fields[] = 'school_other';
        }
        if ($cols['payment_proof']) {
            $fields[] = 'payment_proof';
        }
        if ($cols['phone']) {
            $fields[] = 'phone';
        }
        if ($cols['profile_bio']) {
            $fields[] = 'profile_bio';
        }
        if ($cols['profile_picture']) {
            $fields[] = 'profile_picture';
        }
        if ($cols['profile_cover']) {
            $fields[] = 'profile_cover';
        }
        if ($cols['use_default_avatar']) {
            $fields[] = 'use_default_avatar';
        }
        if ($cols['last_login_at']) {
            $fields[] = 'last_login_at';
        }
        if ($cols['last_login_ip']) {
            $fields[] = 'last_login_ip';
        }
        if ($cols['access_start']) {
            $fields[] = 'access_start';
        }
        if ($cols['access_end']) {
            $fields[] = 'access_end';
        }
        $sql = 'SELECT ' . implode(', ', $fields) . ' FROM users WHERE user_id = ? LIMIT 1';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }
}

if (!function_exists('ereview_profile_activity_rows')) {
    /**
     * @return list<array{label:string,detail:string,time:string}>
     */
    function ereview_profile_activity_rows(mysqli $conn, int $userId, int $limit = 8): array
    {
        try {
            return ereview_profile_activity_rows_uncaught($conn, $userId, $limit);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Recent quiz / preboard submissions for profile activity (internal).
     *
     * @return list<array{label:string,detail:string,time:string}>
     */
    function ereview_profile_activity_rows_uncaught(mysqli $conn, int $userId, int $limit): array
    {
        $out = [];
        $uid = $userId;
        $lim = max(1, min(20, $limit));

        $t1 = @mysqli_query($conn, "SHOW TABLES LIKE 'quiz_attempts'");
        if ($t1 && mysqli_fetch_assoc($t1)) {
            mysqli_free_result($t1);
            $q = "
              SELECT submitted_at, quiz_id, status
              FROM quiz_attempts
              WHERE user_id = {$uid} AND status = 'submitted' AND submitted_at IS NOT NULL
              ORDER BY submitted_at DESC
              LIMIT {$lim}
            ";
            $r = @mysqli_query($conn, $q);
            if ($r) {
                while ($row = mysqli_fetch_assoc($r)) {
                    $ts = strtotime((string)($row['submitted_at'] ?? ''));
                    $out[] = [
                        'label' => 'Quiz submitted',
                        'detail' => 'Quiz #' . (int)($row['quiz_id'] ?? 0),
                        'time' => $ts ? date('M j, Y g:i A', $ts) : '',
                        '_ts' => $ts ?: 0,
                    ];
                }
                mysqli_free_result($r);
            }
        } elseif ($t1) {
            mysqli_free_result($t1);
        }

        $t2 = @mysqli_query($conn, "SHOW TABLES LIKE 'preboards_attempts'");
        if ($t2 && mysqli_fetch_assoc($t2)) {
            mysqli_free_result($t2);
            // Column is preboards_set_id (see preboards_schema.sql / student_take_preboard.php).
            $q2 = "
              SELECT submitted_at, preboards_set_id, status
              FROM preboards_attempts
              WHERE user_id = {$uid} AND status = 'submitted' AND submitted_at IS NOT NULL
              ORDER BY submitted_at DESC
              LIMIT {$lim}
            ";
            $r2 = @mysqli_query($conn, $q2);
            if ($r2) {
                while ($row = mysqli_fetch_assoc($r2)) {
                    $ts = strtotime((string)($row['submitted_at'] ?? ''));
                    $out[] = [
                        'label' => 'Preboard submitted',
                        'detail' => 'Set #' . (int)($row['preboards_set_id'] ?? 0),
                        'time' => $ts ? date('M j, Y g:i A', $ts) : '',
                        '_ts' => $ts ?: 0,
                    ];
                }
                mysqli_free_result($r2);
            }
        } elseif ($t2) {
            mysqli_free_result($t2);
        }

        usort($out, static function ($a, $b) {
            return (int)($b['_ts'] ?? 0) <=> (int)($a['_ts'] ?? 0);
        });

        $out = array_slice($out, 0, $lim);
        foreach ($out as &$o) {
            unset($o['_ts']);
        }
        unset($o);

        return $out;
    }
}

if (!function_exists('ereview_profile_json_payload')) {
    /**
     * @return array<string,mixed>
     */
    function ereview_profile_json_payload(mysqli $conn, int $userId): array
    {
        $row = ereview_profile_fetch_row($conn, $userId);
        if (!$row) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        $cols = ereview_profile_users_columns($conn);
        $pic = trim((string)($row['profile_picture'] ?? ''));
        $useDef = $cols['use_default_avatar'] ? !empty($row['use_default_avatar']) : true;
        $avatarUrl = '';
        if ($pic !== '' && !$useDef) {
            $avatarUrl = function_exists('ereview_avatar_img_src') ? ereview_avatar_img_src($pic) : ereview_avatar_public_path($pic);
        }

        $role = (string)($row['role'] ?? '');
        $activity = [];
        if ($role === 'student' || $role === 'college_student') {
            $activity = ereview_profile_activity_rows($conn, $userId, 8);
        }

        $enrollment = null;
        if (($role === 'student' || $role === 'college_student') && ($cols['access_start'] || $cols['access_end'])) {
            $enrollment = [
                'access_start' => !empty($row['access_start']) ? (string)$row['access_start'] : null,
                'access_end' => !empty($row['access_end']) ? (string)$row['access_end'] : null,
            ];
        }

        $emailLocked = ($role === 'student' || $role === 'college_student');

        return [
            'ok' => true,
            'user' => [
                'user_id' => (int)$row['user_id'],
                'full_name' => (string)($row['full_name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'email_locked' => $emailLocked,
                'phone' => $cols['phone'] ? (string)($row['phone'] ?? '') : '',
                'profile_bio' => $cols['profile_bio'] ? (string)($row['profile_bio'] ?? '') : '',
                'role' => $role,
                'role_label' => ereview_profile_role_display($role),
                'username' => (string)($row['email'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'avatar_url' => $avatarUrl,
                'use_default_avatar' => $useDef ? 1 : 0,
                'last_login_at' => $cols['last_login_at'] && !empty($row['last_login_at']) ? (string)$row['last_login_at'] : null,
                'last_login_ip' => $cols['last_login_ip'] && !empty($row['last_login_ip']) ? (string)$row['last_login_ip'] : null,
            ],
            'activity' => $activity,
            'enrollment' => $enrollment,
        ];
    }
}

if (!function_exists('ereview_profile_apply_update')) {
    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,error?:string}
     */
    function ereview_profile_apply_update(mysqli $conn, int $userId, array $input, ?array $file = null): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Invalid session.'];
        }

        $cols = ereview_profile_users_columns($conn);
        $current = ereview_profile_fetch_row($conn, $userId);
        if (!$current) {
            return ['ok' => false, 'error' => 'User not found.'];
        }

        $role = (string)($current['role'] ?? '');
        $studentEmailLocked = ($role === 'student' || $role === 'college_student');

        $fullName = trim((string)($input['full_name'] ?? ''));
        $email = $studentEmailLocked
            ? trim((string)($current['email'] ?? ''))
            : trim((string)($input['email'] ?? ''));
        $phone = ereview_profile_sanitize_phone((string)($input['phone'] ?? ''));
        $bio = trim((string)($input['profile_bio'] ?? ''));
        $pw = (string)($input['password'] ?? '');
        $pw2 = (string)($input['password_confirm'] ?? '');
        $removeAvatar = !empty($input['remove_avatar']);

        if ($fullName === '' || strlen($fullName) > 200) {
            return ['ok' => false, 'error' => 'Please enter a valid name.', 'field' => 'full_name'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.', 'field' => 'email'];
        }
        if (strlen($phone) > 40) {
            return ['ok' => false, 'error' => 'Phone is too long.', 'field' => 'phone'];
        }
        if (strlen($bio) > 500) {
            return ['ok' => false, 'error' => 'Bio must be 500 characters or less.', 'field' => 'profile_bio'];
        }

        if (!$studentEmailLocked) {
            $stmtDup = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
            if ($stmtDup) {
                mysqli_stmt_bind_param($stmtDup, 'si', $email, $userId);
                mysqli_stmt_execute($stmtDup);
                $dupRes = mysqli_stmt_get_result($stmtDup);
                $dup = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
                mysqli_stmt_close($stmtDup);
                if ($dup) {
                    return ['ok' => false, 'error' => 'That email is already in use by another account.', 'field' => 'email'];
                }
            }
        }

        $updatePassword = false;
        $passwordHash = '';
        if ($pw !== '' || $pw2 !== '') {
            if ($pw !== $pw2) {
                return ['ok' => false, 'error' => 'Passwords do not match.', 'field' => 'password_confirm'];
            }
            $chk = ereview_profile_password_meets_policy($pw);
            if (!$chk['ok']) {
                return ['ok' => false, 'error' => $chk['message'], 'field' => 'password'];
            }
            $updatePassword = true;
            $passwordHash = password_hash($pw, PASSWORD_DEFAULT);
        }

        $oldEmailNorm = strtolower(trim((string)($current['email'] ?? '')));
        $newEmailNorm = strtolower(trim($email));

        $newPicture = trim((string)($current['profile_picture'] ?? ''));
        $newUseDefault = $cols['use_default_avatar'] ? !empty($current['use_default_avatar']) : true;

        if ($removeAvatar) {
            if ($newPicture !== '') {
                ereview_profile_safe_delete_upload($newPicture);
            }
            $newPicture = '';
            $newUseDefault = true;
        }

        if ($file && isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_NO_FILE) {
            if (!$cols['profile_picture']) {
                return ['ok' => false, 'error' => 'Profile images are not enabled for this site (database column missing).', 'field' => 'profile_image'];
            }
            $up = ereview_profile_save_uploaded_image($file);
            if (!$up['ok']) {
                return ['ok' => false, 'error' => $up['message'] ?? 'Upload failed.', 'field' => 'profile_image'];
            }
            if (!empty($up['path'])) {
                if ($newPicture !== '' && $newPicture !== $up['path']) {
                    ereview_profile_safe_delete_upload($newPicture);
                }
                $newPicture = $up['path'];
                $newUseDefault = false;
            }
        }

        $sets = ['full_name = ?', 'email = ?', 'updated_at = NOW()'];
        $types = 'ss';
        $params = [$fullName, $email];

        if ($cols['phone']) {
            $sets[] = 'phone = ?';
            $types .= 's';
            $params[] = $phone;
        }
        if ($cols['profile_bio']) {
            $sets[] = 'profile_bio = ?';
            $types .= 's';
            $params[] = $bio;
        }
        if ($cols['profile_picture']) {
            $sets[] = 'profile_picture = ?';
            $types .= 's';
            $params[] = $newPicture;
        }
        if ($cols['use_default_avatar']) {
            $sets[] = 'use_default_avatar = ?';
            $types .= 'i';
            $params[] = $newUseDefault ? 1 : 0;
        }
        if ($updatePassword) {
            $sets[] = 'password = ?';
            $types .= 's';
            $params[] = $passwordHash;
        }

        $types .= 'i';
        $params[] = $userId;

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ? LIMIT 1';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not update profile.'];
        }

        $bind = array_merge([$types], $params);
        $refs = [];
        foreach ($bind as $k => $v) {
            $refs[$k] = &$bind[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            return ['ok' => false, 'error' => 'Could not save changes.'];
        }

        $_SESSION['full_name'] = $fullName;
        $_SESSION['email'] = $email;

        $emailChanged = !$studentEmailLocked && ($newEmailNorm !== $oldEmailNorm);
        $verificationSent = false;
        // When adding email re-verification: if ($emailChanged && your_mailer_sent()) { $verificationSent = true; }

        return [
            'ok' => true,
            'email_changed' => $emailChanged,
            'verification_email_sent' => $verificationSent,
            'password_changed' => $updatePassword,
        ];
    }
}
