<?php
/**
 * Examination module bridge to shared head_admin.php with correct asset base URL.
 * Used only by /examination/ professor pages — shared head_admin.php is not modified.
 *
 * Asset base must match head_admin.php for both:
 * - /examination/professor|examinee/* direct paths
 * - root stubs (e.g. /Ereview/professor_admin_dashboard.php)
 */
require_once __DIR__ . '/examination_admin_bootstrap.php';

$savedScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$assetScriptName = $savedScriptName;
if ($savedScriptName !== '') {
    $adjusted = preg_replace('#/examination/(?:professor|examinee)/[^/]+$#', '/index.php', $savedScriptName);
    if (is_string($adjusted) && $adjusted !== '') {
        $assetScriptName = $adjusted;
        $_SERVER['SCRIPT_NAME'] = $adjusted;
    }
}
require dirname(__DIR__, 2) . '/includes/head_admin.php';
$_SERVER['SCRIPT_NAME'] = $savedScriptName;

$examinationAdminCssFile = dirname(__DIR__, 2) . '/assets/css/examination-admin.css';
if (is_file($examinationAdminCssFile)) {
    // Same dirname() base head_admin / head_app use — never fall back to bare /index.php
    // (that produced /assets/... and 404'd under /Ereview/).
    $cssBase = rtrim(str_replace('\\', '/', dirname((string) $assetScriptName)), '/');
    if ($cssBase === '.' || $cssBase === '') {
        $cssBase = '';
    }
    echo '<link rel="stylesheet" href="' . h($cssBase) . '/assets/css/examination-admin.css?v=' . filemtime($examinationAdminCssFile) . '">' . "\n";
}
