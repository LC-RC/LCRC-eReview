<?php
/**
 * Centralized Session Configuration
 * This file should be included at the very beginning of every PHP file
 * before any output is sent to the browser
 */

// Start session with secure configuration
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    // Session timeout: 8 hours (28800 seconds)
    ini_set('session.gc_maxlifetime', 28800);
    
    // Set session cookie lifetime (Lax required so OAuth redirect from Google sends the cookie)
    session_set_cookie_params([
        'lifetime' => 28800, // 8 hours
        'path' => '/',
        'domain' => '',
        'secure' => false, // Set to true if using HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
    
    // Regenerate session ID periodically to prevent session fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    // Check for session timeout
    $timeout_duration = 28800; // 8 hours
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
        // Session expired
        session_unset();
        session_destroy();
        session_start();
    }
}
