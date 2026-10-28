<?php
/**
 * Default bootstrap for Examination professor admin pages (LMS Admin design system).
 * Include before examination_head_admin.php when not already set.
 */
$adminLoadStudentsCss = $adminLoadStudentsCss ?? true;
$examinationAdminPageClass = $examinationAdminPageClass ?? 'examination-admin-page professor-admin';
$professorPageChrome = true;
require_once __DIR__ . '/lms_premium_optin.php';
require_once __DIR__ . '/lms_premium_toast.php';
