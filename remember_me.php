<?php
/**
 * Remember Me - secure long-lived login via HTTP-only cookie and DB token.
 * Requires $conn (mysqli) and session already started.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

const REMEMBER_ME_COOKIE_NAME = 'ereview_rm';
const REMEMBER_ME_DAYS = 30;
const REMEMBER_ME_MAX_TOKENS_PER_USER = 5;

function rememberMeCookiePath() {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = dirname($script);
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        return '/';
    }
    // e.g. /Ereview - cookie must cover the app subdirectory
    return rtrim($dir, '/') ?: '/';
}

function rememberMeCookieOptions($expiresAt) {
    return [
        'expires' => (int)$expiresAt,
        'path' => rememberMeCookiePath(),
        'domain' => '',
        'secure' => (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
}

function rememberMeTableExists() {
    global $conn;
    static $exists = null;
    if ($exists !== null) return $exists;
    $res = @mysqli_query($conn, "SHOW TABLES LIKE 'remember_tokens'");
    $exists = (bool)($res && mysqli_fetch_row($res));
    return $exists;
}

function rememberMeTableColumns() {
    global $conn;
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    if (!rememberMeTableExists()) return $cols;
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM remember_tokens");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['Field'])) {
                $cols[(string)$row['Field']] = true;
            }
        }
    }
    return $cols;
}

function rememberMeClientIp() {
    if (function_exists('getLoginClientIp')) {
        return (string)getLoginClientIp();
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Set remember-me cookie and store token in DB after successful login.
 * @param int $userId
 */
function setRememberMeCookie($userId) {
    global $conn;
    $userId = (int) $userId;
    if ($userId <= 0 || !rememberMeTableExists()) return;

    $selector = bin2hex(random_bytes(8));
    $validator = random_bytes(32);
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_ME_DAYS * 86400);

    $cols = rememberMeTableColumns();
    $ip = substr(rememberMeClientIp(), 0, 45);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    $insertCols = ['user_id', 'selector', 'token_hash', 'expires_at'];
    $insertVals = ['?', '?', '?', '?'];
    $types = 'isss';
    $bind = [$userId, $selector, $tokenHash, $expiresAt];

    if (!empty($cols['last_used_at'])) {
        $insertCols[] = 'last_used_at';
        $insertVals[] = 'NOW()';
    }
    if (!empty($cols['last_used_ip'])) {
        $insertCols[] = 'last_used_ip';
        $insertVals[] = '?';
        $types .= 's';
        $bind[] = $ip;
    }
    if (!empty($cols['last_used_user_agent'])) {
        $insertCols[] = 'last_used_user_agent';
        $insertVals[] = '?';
        $types .= 's';
        $bind[] = $ua;
    }

    $stmt = @mysqli_prepare($conn, "INSERT INTO remember_tokens (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")");
    if (!$stmt) return;
    $bindParams = [$types];
    foreach ($bind as $k => $v) {
        $bindParams[] = &$bind[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return;
    }
    mysqli_stmt_close($stmt);

    // Prune old tokens for this user (keep at most MAX)
    $stmt = @mysqli_prepare($conn, "SELECT id FROM remember_tokens WHERE user_id = ? ORDER BY created_at ASC");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
        mysqli_stmt_close($stmt);
        if (count($rows) > REMEMBER_ME_MAX_TOKENS_PER_USER) {
            $idsToDelete = array_slice(array_column($rows, 'id'), 0, count($rows) - REMEMBER_ME_MAX_TOKENS_PER_USER);
            foreach ($idsToDelete as $id) {
                $del = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE id = ?");
                if ($del) {
                    mysqli_stmt_bind_param($del, 'i', $id);
                    @mysqli_stmt_execute($del);
                    mysqli_stmt_close($del);
                }
            }
        }
    }

    $cookieValue = $selector . '.' . base64_encode($validator);
    $expire = time() + REMEMBER_ME_DAYS * 86400;
    $opts = rememberMeCookieOptions($expire);
    // Clear any legacy cookie on path=/ so subdirectory installs don't keep a stale token.
    if (($opts['path'] ?? '/') !== '/') {
        setcookie(REMEMBER_ME_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => !empty($opts['secure']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    setcookie(REMEMBER_ME_COOKIE_NAME, $cookieValue, $opts);
    $_COOKIE[REMEMBER_ME_COOKIE_NAME] = $cookieValue;
}

/**
 * If not logged in but valid remember-me cookie exists, restore session and return true.
 * Call early (e.g. from auth) so protected pages see the user as logged in.
 * @return bool True if session was restored from remember-me
 */
function loginFromRememberMe() {
    global $conn;
    if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
        return false;
    }
    $raw = $_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? '';
    if ($raw === '') return false;

    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) {
        clearRememberMeCookie();
        return false;
    }
    $selector = $parts[0];
    $validator = @base64_decode($parts[1], true);
    if ($validator === false || strlen($validator) !== 32) {
        clearRememberMeCookie();
        return false;
    }
    $tokenHash = hash('sha256', $validator);

    if (!rememberMeTableExists()) return false;

    $stmt = @mysqli_prepare($conn, "SELECT id, user_id, token_hash, expires_at FROM remember_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $selector);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$row || !hash_equals($tokenHash, $row['token_hash'])) {
        if ($row) {
            $del = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE id = ?");
            if ($del) {
                mysqli_stmt_bind_param($del, 'i', $row['id']);
                @mysqli_stmt_execute($del);
                mysqli_stmt_close($del);
            }
        }
        clearRememberMeCookie();
        return false;
    }

    $userId = (int) $row['user_id'];
    require_once __DIR__ . '/includes/platform_access.php';
    $loginCols = 'user_id, full_name, email, role, status, access_end';
    if (ereview_platform_access_columns_ready($conn)) {
        $loginCols .= ', college_examination_access, review_type, section, student_number';
    }
    $stmt = @mysqli_prepare($conn, "SELECT {$loginCols} FROM users WHERE user_id = ? LIMIT 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $user = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$user) {
        $del = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE id = ?");
        if ($del) {
            mysqli_stmt_bind_param($del, 'i', $row['id']);
            @mysqli_stmt_execute($del);
            mysqli_stmt_close($del);
        }
        clearRememberMeCookie();
        return false;
    }

    if (!function_exists('isStaffRole') || !isStaffRole((string) $user['role'])) {
        require_once __DIR__ . '/includes/platform_access.php';
        $gate = ereview_user_can_authenticate($conn, $user);
        if (empty($gate['ok'])) {
            $del = @mysqli_prepare($conn, 'DELETE FROM remember_tokens WHERE id = ?');
            if ($del) {
                mysqli_stmt_bind_param($del, 'i', $row['id']);
                @mysqli_stmt_execute($del);
                mysqli_stmt_close($del);
            }
            clearRememberMeCookie();
            return false;
        }
    }

    $evCols = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
    if ($evCols && mysqli_fetch_assoc($evCols)) {
        $evStmt = @mysqli_prepare($conn, "SELECT email_verified FROM users WHERE user_id = ? LIMIT 1");
        if ($evStmt) {
            mysqli_stmt_bind_param($evStmt, 'i', $userId);
            mysqli_stmt_execute($evStmt);
            $evRes = mysqli_stmt_get_result($evStmt);
            $evRow = $evRes ? mysqli_fetch_assoc($evRes) : null;
            mysqli_stmt_close($evStmt);
            if ($evRow && (int)($evRow['email_verified'] ?? 1) === 0) {
                clearRememberMeCookie();
                return false;
            }
        }
    }

    if (!function_exists('college_exam_login_blocked_by_active_exam_session')) {
        @require_once __DIR__ . '/includes/college_schema.php';
        @require_once __DIR__ . '/includes/college_exam_helpers.php';
    }
    if (function_exists('college_exam_login_blocked_by_active_exam_session')) {
        $examBlock = college_exam_login_blocked_by_active_exam_session($conn, (int)$user['user_id']);
        if ($examBlock !== null) {
            clearRememberMeCookie();
            return false;
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['role'] = $user['role'];
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();

    // Rotate in place (update then set cookie) so a failed Set-Cookie cannot orphan the session.
    $newSelector = bin2hex(random_bytes(8));
    $newValidator = random_bytes(32);
    $newHash = hash('sha256', $newValidator);
    $newExpires = date('Y-m-d H:i:s', time() + REMEMBER_ME_DAYS * 86400);
    $cols = rememberMeTableColumns();
    $setParts = ['selector = ?', 'token_hash = ?', 'expires_at = ?'];
    $types = 'sss';
    $vals = [$newSelector, $newHash, $newExpires];
    if (!empty($cols['last_used_at'])) {
        $setParts[] = 'last_used_at = NOW()';
    }
    if (!empty($cols['last_used_ip'])) {
        $setParts[] = 'last_used_ip = ?';
        $types .= 's';
        $vals[] = substr(rememberMeClientIp(), 0, 45);
    }
    if (!empty($cols['last_used_user_agent'])) {
        $setParts[] = 'last_used_user_agent = ?';
        $types .= 's';
        $vals[] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }
    $types .= 'i';
    $vals[] = (int)$row['id'];
    $upd = @mysqli_prepare($conn, "UPDATE remember_tokens SET " . implode(', ', $setParts) . " WHERE id = ? LIMIT 1");
    if ($upd) {
        $updParams = [$types];
        foreach ($vals as $k => $v) {
            $updParams[] = &$vals[$k];
        }
        call_user_func_array([$upd, 'bind_param'], $updParams);
        if (@mysqli_stmt_execute($upd)) {
            $cookieValue = $newSelector . '.' . base64_encode($newValidator);
            $opts = rememberMeCookieOptions(time() + REMEMBER_ME_DAYS * 86400);
            if (($opts['path'] ?? '/') !== '/') {
                setcookie(REMEMBER_ME_COOKIE_NAME, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => !empty($opts['secure']),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
            setcookie(REMEMBER_ME_COOKIE_NAME, $cookieValue, $opts);
            $_COOKIE[REMEMBER_ME_COOKIE_NAME] = $cookieValue;
        }
        mysqli_stmt_close($upd);
    }

    require_once __DIR__ . '/includes/admin_acl.php';
    admin_acl_ensure_schema($conn);
    if (($user['role'] ?? '') === 'admin') {
        admin_acl_refresh_session($conn, (int) $user['user_id']);
    }
    users_activity_log(
        $conn,
        'login_success',
        ['via' => 'remember'],
        (int) $user['user_id'],
        (string) ($user['email'] ?? ''),
        (string) ($user['role'] ?? ''),
        null
    );

    return true;
}

function clearRememberMeForUserId($userId) {
    global $conn;
    $uid = (int)$userId;
    if ($uid <= 0 || !rememberMeTableExists()) return;
    $stmt = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE user_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        @mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function getRememberedDevices($userId) {
    global $conn;
    $uid = (int)$userId;
    if ($uid <= 0 || !rememberMeTableExists()) return [];
    $cols = rememberMeTableColumns();
    $fields = ['id', 'selector', 'expires_at', 'created_at'];
    if (!empty($cols['last_used_at'])) $fields[] = 'last_used_at';
    if (!empty($cols['last_used_ip'])) $fields[] = 'last_used_ip';
    if (!empty($cols['last_used_user_agent'])) $fields[] = 'last_used_user_agent';
    $stmt = @mysqli_prepare($conn, "SELECT " . implode(', ', $fields) . " FROM remember_tokens WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return is_array($rows) ? $rows : [];
}

function revokeRememberedDevice($userId, $tokenId) {
    global $conn;
    $uid = (int)$userId;
    $tid = (int)$tokenId;
    if ($uid <= 0 || $tid <= 0 || !rememberMeTableExists()) return false;
    $stmt = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ii', $tid, $uid);
    @mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected > 0;
}

function rememberMeCurrentSelector() {
    $raw = (string)($_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? '');
    if ($raw === '') return '';
    $parts = explode('.', $raw, 2);
    return count($parts) === 2 ? trim((string)$parts[0]) : '';
}

function clearCurrentRememberedDevice($userId) {
    global $conn;
    $uid = (int)$userId;
    if ($uid <= 0 || !rememberMeTableExists()) {
        clearRememberMeCookie();
        return;
    }
    $selector = rememberMeCurrentSelector();
    if ($selector !== '') {
        $stmt = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE user_id = ? AND selector = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $uid, $selector);
            @mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    clearRememberMeCookie();
}

/**
 * Remove remember-me cookie and delete all tokens for current user (call on logout).
 */
function clearRememberMe() {
    if (isset($_SESSION['user_id'])) {
        clearCurrentRememberedDevice((int)$_SESSION['user_id']);
        return;
    }
    clearRememberMeCookie();
}

/**
 * Remove the remember-me cookie only (no DB).
 */
function clearRememberMeCookie() {
    $opts = rememberMeCookieOptions(time() - 3600);
    setcookie(REMEMBER_ME_COOKIE_NAME, '', $opts);
    // Also clear legacy root-path cookie from earlier builds.
    if (($opts['path'] ?? '/') !== '/') {
        setcookie(REMEMBER_ME_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => !empty($opts['secure']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    unset($_COOKIE[REMEMBER_ME_COOKIE_NAME]);
}
