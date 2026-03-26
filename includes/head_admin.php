<?php
require_once __DIR__ . '/head_app.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$appShellFile = __DIR__ . '/../assets/css/app-shell.css';
$adminCssFile = __DIR__ . '/../assets/css/admin.css';
?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin.css<?php echo file_exists($adminCssFile) ? '?v=' . filemtime($adminCssFile) : ''; ?>">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/app-shell.css<?php echo file_exists($appShellFile) ? '?v=' . filemtime($appShellFile) : ''; ?>">
