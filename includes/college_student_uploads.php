<?php
/**
 * College Examination — student uploads module toggle (site-wide).
 *
 * Professors enable this when file submissions are required. Default OFF.
 */
declare(strict_types=1);

require_once __DIR__ . '/ereview_app_settings.php';

const COLLEGE_STUDENT_UPLOADS_SETTING_KEY = 'college_student_uploads_enabled';

/** Module enabled for college students (default OFF when setting missing). */
function college_student_uploads_is_enabled(mysqli $conn): bool
{
    return ereview_app_setting_get($conn, COLLEGE_STUDENT_UPLOADS_SETTING_KEY, '0') === '1';
}

function college_student_uploads_set_enabled(mysqli $conn, bool $enabled): bool
{
    return ereview_app_setting_set($conn, COLLEGE_STUDENT_UPLOADS_SETTING_KEY, $enabled ? '1' : '0');
}

/** Redirect college students when the uploads module is disabled. */
function college_student_uploads_enforce_enabled(mysqli $conn): void
{
    if (college_student_uploads_is_enabled($conn)) {
        return;
    }
    $_SESSION['error'] = 'Student uploads are not available at this time.';
    header('Location: college_student_dashboard');
    exit;
}

/**
 * Sidebar visibility: module ON and at least one eligible published task.
 */
function college_student_uploads_nav_visible(mysqli $conn, int $userId): bool
{
    if ($userId <= 0 || !college_student_uploads_is_enabled($conn)) {
        return false;
    }
    if (!function_exists('college_upload_list_for_student')) {
        require_once dirname(__DIR__) . '/examination/includes/college_upload_helpers.php';
    }

    return college_upload_list_for_student($conn, $userId) !== [];
}
