<?php
/**
 * Examination module bridge to shared head_app.php with correct asset base URL.
 * Used only by /examination/ page copies — shared head_app.php is not modified.
 */
$loadStudentTheme = true;
$examinationStudentBodyClass = 'college-examination-portal college-student-app';

$savedScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$assetScriptName = $savedScriptName;
if ($savedScriptName !== '') {
    $adjusted = preg_replace('#/examination/(?:professor|examinee)/[^/]+$#', '/index.php', $savedScriptName);
    if (is_string($adjusted) && $adjusted !== '') {
        $assetScriptName = $adjusted;
        $_SERVER['SCRIPT_NAME'] = $adjusted;
    }
}
require dirname(__DIR__, 2) . '/includes/head_app.php';
$_SERVER['SCRIPT_NAME'] = $savedScriptName;

$__cpBase = rtrim(str_replace('\\', '/', dirname((string) $assetScriptName)), '/');
if ($__cpBase === '.' || $__cpBase === '') {
    $__cpBase = '';
}
$__cpCssFile = dirname(__DIR__, 2) . '/assets/css/college-portal.css';
$__csPremiumCssFile = dirname(__DIR__, 2) . '/assets/css/college-student-premium.css';
if (is_file($__cpCssFile)) {
    echo '<link rel="stylesheet" href="' . h($__cpBase) . '/assets/css/college-portal.css?v=' . filemtime($__cpCssFile) . '">' . "\n";
}
if (is_file($__csPremiumCssFile)) {
    echo '<link rel="stylesheet" href="' . h($__cpBase) . '/assets/css/college-student-premium.css?v=' . filemtime($__csPremiumCssFile) . '">' . "\n";
}
