<?php
require_once 'session_config.php';
require_once 'db.php';
require_once 'remember_me.php';
require_once 'auth.php';

if (isset($_SESSION['user_id'])) {
    setUserPresenceStatus((int)$_SESSION['user_id'], false);
}

// Clear Remember Me cookie and tokens before destroying session
clearRememberMe();

// Destroy session completely
session_unset();
session_destroy();

header("Location: login.php");
exit;
?>
