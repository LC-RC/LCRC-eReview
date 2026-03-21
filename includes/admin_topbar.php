<?php
/**
 * Legacy include — admin topbar is rendered inside includes/components/app_shell_sidebar.php.
 * If this file is included alone, it outputs only the topbar (unusual).
 */
$appShellTopbarTheme = 'admin';
require __DIR__ . '/components/app_shell_topbar.php';
