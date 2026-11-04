<?php
/**
 * DB-free checks for legacy College password fallback and legacy school
 * recognition on Activate/Deactivate. Does not write the database.
 *
 *   C:\xampp\php\php.exe scripts\qa_college_legacy_compat_unit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/platform_access.php';
require_once $root . '/examination/includes/college_auth_credentials.php';
require_once $root . '/examination/includes/college_school_assignment.php';
require_once $root . '/examination/includes/college_exam_access_ops.php';
require_once $root . '/includes/user_archive_lifecycle.php';

$fail = 0;
$pass = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$hash = password_hash('LegacyCollege#1', PASSWORD_DEFAULT);
$pass(
    'legacy users.password bcrypt matches',
    college_auth_credentials_users_password_matches('LegacyCollege#1', $hash)
);
$pass(
    'legacy users.password bcrypt rejects wrong secret',
    !college_auth_credentials_users_password_matches('wrong', $hash)
);
$pass(
    'legacy plaintext users.password still matches',
    college_auth_credentials_users_password_matches('plain-secret', 'plain-secret')
);

$pass(
    'modern College row blocks legacy fallback',
    !college_auth_credentials_legacy_fallback_permitted(
        ['user_id' => 8, 'password_hash' => $hash],
        true
    )
);
$pass(
    'LMS-only (no College eligibility) cannot use legacy fallback',
    !college_auth_credentials_legacy_fallback_permitted(null, false)
);
$pass(
    'eligible College student with no dedicated row may use legacy password',
    college_auth_credentials_legacy_fallback_permitted(null, true)
);

$lmsOnlyStores = ereview_password_login_authorized_stores(true, false, false, true, false);
$pass(
    'LMS-only authorized stores stay LMS-only even if users.password matched',
    $lmsOnlyStores['stores'] === ['lms'] && !$lmsOnlyStores['college']
);
$legacyNative = ereview_password_login_authorized_stores(true, true, false, false, true);
$pass(
    'legacy native College (no LMS access) authorizes College only',
    $legacyNative['stores'] === ['college'] && $legacyNative['college'] && !$legacyNative['lms']
);
$modernCollege = ereview_password_login_authorized_stores(false, true, false, false, true);
$pass(
    'modern College credential authorizes College without LMS password',
    $modernCollege['stores'] === ['college']
);
$suspendedNoCollege = ereview_password_login_authorized_stores(true, false, false, true, false);
$pass(
    'suspended or ineligible College access does not authorize College store',
    $suspendedNoCollege['stores'] === ['lms'] && !$suspendedNoCollege['college']
);

$loginSrc = (string) file_get_contents($root . '/login_process.php');
$pass(
    'login applies legacy fallback only after College eligibility',
    str_contains($loginSrc, 'college_auth_credentials_legacy_fallback_permitted')
    && str_contains($loginSrc, 'ereview_user_has_college_examination_access')
);
$legacyAt = strpos($loginSrc, 'college_auth_credentials_legacy_fallback_permitted');
$authzAt = strpos($loginSrc, 'ereview_password_login_authorized_stores');
$verifyAt = strpos($loginSrc, 'college_auth_credentials_verify');
$pass(
    'dedicated College verify runs before legacy fallback, both before store authz',
    $verifyAt !== false && $legacyAt !== false && $authzAt !== false
    && $verifyAt < $legacyAt && $legacyAt < $authzAt
);
$pass(
    'legacy fallback does not write college_auth_credentials or users.password',
    !str_contains($loginSrc, 'college_auth_credentials_put')
);
$pass(
    'legacy fallback requires no dedicated credential row',
    str_contains($loginSrc, 'college_auth_credentials_load($conn, (int) $user[\'user_id\'])')
);
$pass(
    'Google/magic identity login still does not use College credential helper',
    !str_contains((string) file_get_contents($root . '/google_callback.php'), 'college_auth_credentials_legacy_fallback_permitted')
    && !str_contains((string) file_get_contents($root . '/login.php'), 'college_auth_credentials_legacy_fallback_permitted')
);

$credSrc = (string) file_get_contents($root . '/examination/includes/college_auth_credentials.php');
$pass(
    'college_auth_credentials_verify still never reads users.password',
    (bool) preg_match(
        '/function college_auth_credentials_verify\(.*?return \$stored !== \'\' && password_verify\(\$password, \$stored\);/s',
        $credSrc
    )
);
$pass('credential helper still does not CREATE TABLE', !str_contains($credSrc, 'CREATE TABLE'));

$nativeApproved = [
    'role' => 'college_student',
    'status' => 'approved',
    'college_examination_access' => 'none',
];
$pass(
    'native approved College remains eligible without dedicated access flag',
    ereview_user_college_examination_access_value($nativeApproved) !== 'suspended'
);
$pass(
    'suspended flag is still suspended',
    ereview_user_college_examination_access_value([
        'role' => 'college_student',
        'college_examination_access' => 'suspended',
    ]) === 'suspended'
);

$pageSrc = (string) file_get_contents($root . '/examination/professor/professor_college_students.php');
$pass(
    'Students page uses existing school text for Activate/Deactivate, not only assigned_at',
    str_contains($pageSrc, '$schoolForExamAccess = $schoolMapped || $schoolTxt !== \'\'')
    && str_contains($pageSrc, '$canActivateExam = $statusLower !== \'archived\' && $schoolForExamAccess && $collExAccessVal === \'suspended\'')
);
$pass(
    'Students page still shows Unassigned from staff timestamp, not from Activate eligibility',
    str_contains($pageSrc, 'college_school_row_is_staff_assigned($u)')
    && str_contains($pageSrc, 'if ($schoolMapped):')
);
$pass(
    'Enable still excludes suspended LMS students (Activate remains the unsuspend)',
    str_contains($pageSrc, '$collExAccessVal !== \'suspended\'')
);

$opsSrc = (string) file_get_contents($root . '/examination/includes/college_exam_access_ops.php');
$pass(
    'Activate helper does not stamp assigned_at or school_id',
    !preg_match('/SET\s+school=/i', $opsSrc)
    && !str_contains($opsSrc, 'college_school_assigned_at=NOW()')
);
$pass(
    'empty school still unmapped for access ops',
    college_exam_access_unmapped_ids([
        21 => ['school' => '', 'college_school_assigned_at' => ''],
        22 => ['school' => 'Pre-schema College', 'college_school_assigned_at' => ''],
    ]) === [21]
);

$archiveSrc = (string) file_get_contents($root . '/includes/user_archive_lifecycle.php');
$pass(
    'restore still writes status only',
    str_contains($archiveSrc, 'UPDATE users SET status=?')
    && !str_contains($archiveSrc, 'college_examination_access=?')
);
$pass(
    'archive still does not mutate exam access',
    str_contains($archiveSrc, 'Does not mutate college_examination_access')
);
$snap = user_archive_resolve_restore_status([
    'snapshot_id' => 4,
    'prior_status' => 'approved',
    'prior_college_examination_access' => 'suspended',
]);
$pass(
    'restore snapshot still does not rewrite exam access',
    !empty($snap['ok'])
    && ($snap['status'] ?? '') === 'approved'
    && ($snap['prior_college_examination_access'] ?? '') === 'suspended'
);

echo $fail === 0 ? "OK 0 failed\n" : "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
