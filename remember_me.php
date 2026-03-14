<?php
/**
 * Remember Me – secure long-lived login via HTTP-only cookie and DB token.
 * Requires $conn (mysqli) and session already started.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

const REMEMBER_ME_COOKIE_NAME = 'ereview_rm';
const REMEMBER_ME_DAYS = 30;
const REMEMBER_ME_MAX_TOKENS_PER_USER = 5;

/**
 * Set remember-me cookie and store token in DB after successful login.
 * @param int $userId
 */
function setRememberMeCookie($userId) {
    global $conn;
    $userId = (int) $userId;
    if ($userId <= 0) return;

    $selector = bin2hex(random_bytes(8));
    $validator = random_bytes(32);
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_ME_DAYS * 86400);

    $stmt = @mysqli_prepare($conn, "INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $selector, $tokenHash, $expiresAt);
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
    setcookie(REMEMBER_ME_COOKIE_NAME, $cookieValue, [
        'expires' => $expire,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
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
    $stmt = @mysqli_prepare($conn, "SELECT user_id, full_name, email, role, status, access_end FROM users WHERE user_id = ? LIMIT 1");
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

    if ($user['role'] !== 'admin' && strtolower($user['status']) !== 'approved') {
        $del = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE id = ?");
        if ($del) {
            mysqli_stmt_bind_param($del, 'i', $row['id']);
            @mysqli_stmt_execute($del);
            mysqli_stmt_close($del);
        }
        clearRememberMeCookie();
        return false;
    }
    if ($user['role'] !== 'admin' && !empty($user['access_end'])) {
        if (strtotime($user['access_end']) < time()) {
            $del = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE id = ?");
            if ($del) {
                mysqli_stmt_bind_param($del, 'i', $row['id']);
                @mysqli_stmt_execute($del);
                mysqli_stmt_close($del);
            }
            clearRememberMeCookie();
            return false;
        }
    }

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();

    return true;
}

/**
 * Remove remember-me cookie and delete all tokens for current user (call on logout).
 */
function clearRememberMe() {
    global $conn;
    if (isset($_SESSION['user_id'])) {
        $uid = (int) $_SESSION['user_id'];
        $stmt = @mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $uid);
            @mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    clearRememberMeCookie();
}

/**
 * Remove the remember-me cookie only (no DB).
 */
function clearRememberMeCookie() {
    setcookie(REMEMBER_ME_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}
