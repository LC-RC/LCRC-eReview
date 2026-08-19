<?php
/**
 * Examination module bridge to shared head_app.php with correct asset base URL.
 * Used only by /examination/ page copies — shared head_app.php is not modified.
 */
$savedScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if ($savedScriptName !== '') {
    $adjusted = preg_replace('#/examination/(?:professor|examinee)/[^/]+$#', '/index.php', $savedScriptName);
    if (is_string($adjusted) && $adjusted !== '') {
        $_SERVER['SCRIPT_NAME'] = $adjusted;
    }
}
require dirname(__DIR__, 2) . '/includes/head_app.php';
$_SERVER['SCRIPT_NAME'] = $savedScriptName;

$__cpBase = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$__cpCssFile = dirname(__DIR__, 2) . '/assets/css/college-portal.css';
if (is_file($__cpCssFile)) {
    echo '<link rel="stylesheet" href="' . h($__cpBase) . '/assets/css/college-portal.css?v=' . filemtime($__cpCssFile) . '">' . "\n";
}
