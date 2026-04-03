<?php
require_once 'session_config.php';
require_once 'db.php';
require_once 'remember_me.php';
require_once 'auth.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

if (isset($_SESSION['user_id'])) {
    $uidOut = (int)$_SESSION['user_id'];
    setUserPresenceStatus($uidOut, false);
    college_exam_clear_exam_lock_cookies_for_user($conn, $uidOut);
    college_exam_release_exam_session_locks($conn, $uidOut);
}

// Clear Remember Me cookie and tokens before destroying session
clearRememberMe();

// Destroy session completely
session_unset();
session_destroy();

header("Location: login.php");
exit;
?>
