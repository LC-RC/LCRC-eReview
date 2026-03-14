<?php
require_once 'session_config.php';
require_once 'db.php';
require_once 'remember_me.php';

// Clear Remember Me cookie and tokens before destroying session
clearRememberMe();

// Destroy session completely
session_unset();
session_destroy();

header("Location: login.php");
exit;
?>
