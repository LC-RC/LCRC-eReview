<?php
/**
 * Authentication Helper Functions
 * Provides consistent authentication and authorization checks
 */

require_once 'db.php';

// Restore session from Remember Me cookie if not logged in
if (!isset($_SESSION['user_id']) && isset($_COOKIE['ereview_rm'])) {
    require_once __DIR__ . '/remember_me.php';
    loginFromRememberMe();
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

/**
 * Check if user has a specific role
 * @param string $role Role to check (admin, student)
 * @return bool
 */
function hasRole($role) {
    return isLoggedIn() && $_SESSION['role'] === $role;
}

/**
 * Require login - redirect to index if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['error'] = 'Please login to access this page.';
        header('Location: index.php');
        exit;
    }
}

/**
 * Require specific role - redirect if user doesn't have the role
 * @param string $role Required role
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: index.php');
        exit;
    }
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Get current user role
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Verify user session is still valid in database
 * @return bool
 */
function verifySession() {
    global $conn;
    
    if (!isLoggedIn()) {
        return false;
    }
    
    $userId = getCurrentUserId();
    $stmt = mysqli_prepare($conn, "SELECT user_id, role, status FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$user || $user['role'] !== $_SESSION['role']) {
        return false;
    }
    
    // For non-admin users, check if account is still approved
    if ($user['role'] !== 'admin' && strtolower($user['status']) !== 'approved') {
        return false;
    }
    
    return true;
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token Token to verify
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize output for HTML
 * @param string $string String to sanitize
 * @return string
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize integer input
 * @param mixed $value Value to sanitize
 * @param int $default Default value if invalid
 * @return int
 */
function sanitizeInt($value, $default = 0) {
    $int = filter_var($value, FILTER_VALIDATE_INT);
    return $int !== false ? $int : $default;
}
