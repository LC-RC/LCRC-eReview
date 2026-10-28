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
$professorExamUiCssFile = dirname(__DIR__, 2) . '/assets/css/professor-examination-ui.css';
$professorPremiumCssFile = dirname(__DIR__, 2) . '/assets/css/professor-premium.css';
if (is_file($examinationAdminCssFile)) {
    // Same dirname() base head_admin / head_app use — never fall back to bare /index.php
    // (that produced /assets/... and 404'd under /Ereview/).
    $cssBase = rtrim(str_replace('\\', '/', dirname((string) $assetScriptName)), '/');
    if ($cssBase === '.' || $cssBase === '') {
        $cssBase = '';
    }
    echo '<link rel="stylesheet" href="' . h($cssBase) . '/assets/css/examination-admin.css?v=' . filemtime($examinationAdminCssFile) . '">' . "\n";
    if (is_file($professorExamUiCssFile)) {
        echo '<link rel="stylesheet" href="' . h($cssBase) . '/assets/css/professor-examination-ui.css?v=' . filemtime($professorExamUiCssFile) . '">' . "\n";
    }
    if (is_file($professorPremiumCssFile)) {
        echo '<link rel="stylesheet" href="' . h($cssBase) . '/assets/css/professor-premium.css?v=' . filemtime($professorPremiumCssFile) . '">' . "\n";
    }
    $lmsPremiumCssFile = dirname(__DIR__, 2) . '/assets/css/lms-premium.css';
    if (ereview_lms_premium_should_load() && is_file($lmsPremiumCssFile)) {
        echo '<link rel="stylesheet" href="' . h($cssBase) . '/assets/css/lms-premium.css?v=' . filemtime($lmsPremiumCssFile) . '" data-lms-premium="1">' . "\n";
        $lmsToastJsFile = dirname(__DIR__, 2) . '/assets/js/lms-premium-toast.js';
        if (is_file($lmsToastJsFile)) {
            echo '<script src="' . h($cssBase) . '/assets/js/lms-premium-toast.js?v=' . filemtime($lmsToastJsFile) . '" defer></script>' . "\n";
        }
    }
    $examDraftJsFile = dirname(__DIR__, 2) . '/assets/js/examination-config-draft.js';
    if (is_file($examDraftJsFile)) {
        echo '<script src="' . h($cssBase) . '/assets/js/examination-config-draft.js?v=' . filemtime($examDraftJsFile) . '"></script>' . "\n";
        if (!empty($examinationClearDraft) && is_array($examinationClearDraft)) {
            $clearJson = json_encode($examinationClearDraft, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            if (is_string($clearJson)) {
                echo '<script>if(window.ereviewExamConfigDraft)window.ereviewExamConfigDraft.clearFor(' . $clearJson . ');</script>' . "\n";
            }
        }
    }
    $professorModalsJsFile = dirname(__DIR__, 2) . '/assets/js/professor-modals.js';
    if (is_file($professorModalsJsFile)) {
        echo '<script src="' . h($cssBase) . '/assets/js/professor-modals.js?v=' . filemtime($professorModalsJsFile) . '" defer></script>' . "\n";
    }
}
