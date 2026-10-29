<?php
/**
 * Students management page is College Examination Students only.
 *
 *   C:\xampp\php\php.exe scripts\qa_college_students_page_unit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$page = (string) file_get_contents($root . '/examination/professor/professor_college_students.php');
$createPage = (string) file_get_contents($root . '/examination/professor/professor_create_college_student.php');
$createLib = (string) file_get_contents($root . '/examination/includes/college_student_create.php');
$login = (string) file_get_contents($root . '/login_process.php');
$publicReg = (string) file_get_contents($root . '/examination/student_registration.php');
$revieweeCreate = (string) file_get_contents($root . '/examination/professor/professor_create_reviewee.php');
$verifySrc = (string) file_get_contents($root . '/examination/includes/college_email_verification.php');
$apiSrc = (string) file_get_contents($root . '/examination/professor/professor_college_students_api.php');
$viewSrc = (string) file_get_contents($root . '/examination/professor/professor_college_student_view.php');
$attachFn = '';
if (preg_match('/function college_attach_lms_student_in_transaction\(.*?function college_insert_verified_college_student/s', $verifySrc, $m)) {
    $attachFn = $m[0];
}

$registerCtaCount = preg_match_all('/Register Student/', $page);
$pass('page has both Register Student CTAs', $registerCtaCount === 2);
$pass(
    'both Register Student CTAs go to staff creator',
    substr_count($page, 'href="professor_create_college_student"') >= 2
    && !str_contains($page, 'href="student_registration"')
);
$pass('staff creator page still exists', str_contains($createPage, 'college_student_create_from_request'));
$pass('page has no Reviewees tab', !str_contains($page, 'students-view-tabs') && !str_contains($page, 'type=reviewee'));
$pass('page has no Reviewees KPI', !preg_match('/lms-kpi__label[^>]*>[^<]*Reviewees/', $page) && !str_contains($page, '$statsReviewees'));
$pass('page has no Add Reviewee CTA', !str_contains($page, 'professor_create_reviewee'));
$pass('page ignores type=reviewee list switching', !str_contains($page, "['college_student', 'reviewee']") && str_contains($page, "\$reviewTypeSql = 'undergrad'"));
$pass('roster filter stays undergrad', str_contains($page, "\$where = ['review_type=?']") && str_contains($page, "\$reviewTypeSql = 'undergrad'"));
$pass(
    'approval-status chips are gone',
    !str_contains($page, "'approved' => ['Approved'")
    && !str_contains($page, "'pending' => ['Pending'")
    && !str_contains($page, "'rejected' => ['Rejected'")
);
$pass('Archived remains a supported filter', str_contains($page, "\$validStatus = ['all', 'archived']") && str_contains($page, "'archived' => ['Archived'"));
$pass('search school section sort pagination remain', str_contains($page, 'name="q"') && str_contains($page, 'name="school"') && str_contains($page, 'name="section"') && str_contains($page, 'name="sort"') && str_contains($page, 'name="per_page"') && str_contains($page, 'students-pagination'));
$pass('enable-from-this-page cannot set reviewee', str_contains($page, "review_type: 'undergrad'") && !str_contains($page, 'pcsEnableReviewType') && !str_contains($page, 'option value="reviewee"'));
$pass('reviewee creator is still present elsewhere', str_contains($revieweeCreate, "review_type") && str_contains($revieweeCreate, 'reviewee'));
$pass('public student_registration is unchanged by this page', str_contains($publicReg, 'college_public_register_from_request') && !str_contains($publicReg, 'professor_create_college_student'));
$pass('staff create still skips public email verification', str_contains($createLib, 'Skips email token') && str_contains($createLib, 'college_auth_credentials_put'));
$pass('shared login still requires College credentials plus access', str_contains($login, 'college_auth_credentials_verify') && str_contains($login, 'ereview_user_has_college_examination_access'));
$pass(
    'new College verify insert is undergrad college_student',
    substr_count($verifySrc, "VALUES (?, 'undergrad'") >= 2
    && str_contains($verifySrc, "'college_student', ?, 1, ?)")
);
$pass(
    'existing LMS attach sets undergrad without inserting users or changing role',
    $attachFn !== ''
    && substr_count($attachFn, "review_type='undergrad'") === 2
    && !preg_match('/INSERT\s+INTO\s+users/i', $attachFn)
    && !preg_match('/role=[\'"]college_student/', $attachFn)
);
$pass(
    'staff-created College student is undergrad college_student',
    str_contains($createLib, "VALUES (?, 'undergrad'")
    && str_contains($createLib, "'college_student', ?, 1, ?)")
);
$pass(
    'professor roster would include attached LMS and native College undergrads',
    str_contains($page, "(role='college_student' OR (role='student' AND college_examination_access IN ('active','suspended')))")
    && str_contains($page, "\$reviewTypeSql = 'undergrad'")
);
$pass('professor bulk enable forces undergrad', str_contains($apiSrc, "\$reviewType = 'undergrad';") && !str_contains($apiSrc, "['undergrad', 'reviewee']"));
$pass('student view cannot reclassify as reviewee', str_contains($viewSrc, "if (\$newType !== 'undergrad')") && !str_contains($viewSrc, 'value="reviewee"'));

echo PHP_EOL . ($fail === 0 ? 'ALL PASSED' : $fail . ' FAILED') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
