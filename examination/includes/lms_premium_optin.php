<?php
/**
 * Phase 3.20.0 — positive allowlist for the shared professor LMS premium layer.
 * CSS and body.lms-premium apply only when this returns true.
 * Dashboards, scoped College/Diagnostic monitor, and unlisted scripts stay out.
 */
declare(strict_types=1);

if (!function_exists('ereview_lms_premium_allowlist')) {
    /**
     * @return list<string>
     */
    function ereview_lms_premium_allowlist(): array
    {
        return [
            'professor_examinations',
            'professor_examination_edit',
            'professor_college_students',
            'professor_college_student_view',
            'professor_create_college_student',
            'professor_create_reviewee',
            'professor_college_sections',
            'professor_college_schools',
            'professor_upload_tasks',
            'professor_upload_task_monitor',
            'professor_examination_monitor',
            'professor_exam_review_sheet',
        ];
    }
}

if (!function_exists('ereview_lms_premium_should_load')) {
    function ereview_lms_premium_should_load(): bool
    {
        global $lmsPremiumOptIn;

        if (isset($lmsPremiumOptIn) && $lmsPremiumOptIn === false) {
            return false;
        }

        $script = function_exists('ereview_page_basename')
            ? ereview_page_basename()
            : (string)preg_replace('/\.php$/i', '', basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));

        if (in_array($script, ['professor_admin_dashboard', 'admin_dashboard'], true)) {
            return false;
        }

        if (isset($lmsPremiumOptIn) && $lmsPremiumOptIn === true) {
            return in_array($script, ereview_lms_premium_allowlist(), true);
        }

        return in_array($script, ereview_lms_premium_allowlist(), true);
    }
}

if (!function_exists('ereview_lms_premium_body_class')) {
    function ereview_lms_premium_body_class(): string
    {
        return ereview_lms_premium_should_load() ? ' lms-premium' : '';
    }
}
