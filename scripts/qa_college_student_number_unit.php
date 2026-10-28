<?php
/**
 * DB-free checks for staff student-number rules and prepare/execute guards.
 *
 *   C:\xampp\php\php.exe scripts\qa_college_student_number_unit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/examination/includes/college_student_number.php';

$fail = 0;
$pass = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$pass('generated YYYY-NNN accepted', college_student_number_is_generated('2026-001'));
$pass('legacy digits not generated', !college_student_number_is_generated('2008435'));
$pass('legacy allowed', college_student_number_is_legacy_allowed('2008435'));
$pass('generated not legacy-allowed', !college_student_number_is_legacy_allowed('2026-001'));
$pass(
    'staff cannot change generated',
    college_student_number_staff_change_error('2026-001', '2008435') !== null
);
$pass(
    'staff cannot clear generated',
    college_student_number_staff_change_error('2026-001', '') !== null
);
$pass(
    'same generated value is allowed',
    college_student_number_staff_change_error('2026-001', '2026-001') === null
);
$pass(
    'staff cannot assign generated onto empty',
    college_student_number_staff_change_error('', '2026-014') !== null
);
$pass(
    'staff can set legacy',
    college_student_number_staff_change_error('', '2008435') === null
);
$pass(
    'staff can clear legacy',
    college_student_number_staff_change_error('2008435', '') === null
);

$createSrc = (string) file_get_contents($root . '/examination/professor/professor_create_reviewee.php');
$viewSrc = (string) file_get_contents($root . '/examination/professor/professor_college_student_view.php');

$pass(
    'create refuses generated input before insert',
    str_contains($createSrc, 'college_student_number_is_generated($studentNumber)')
    && str_contains($createSrc, '!college_student_number_is_legacy_allowed($studentNumber)')
);
$pass(
    'create checks duplicate-number prepare',
    str_contains($createSrc, 'if (!$chkSn)')
);
$pass(
    'create checks duplicate-number execute',
    str_contains($createSrc, '!mysqli_stmt_execute($chkSn)')
);
$pass(
    'create checks student-number update execute before success',
    str_contains($createSrc, '$snOk = mysqli_stmt_execute($updSn)')
    && str_contains($createSrc, 'if ($legacyNumberSaved && $error === null)')
);
$pass(
    'create does not claim success when number update fails',
    str_contains($createSrc, 'but the student number could not be saved')
    && str_contains($createSrc, 'but that student number is already assigned')
);
$pass(
    'create does not delete the new account on number save failure',
    !preg_match('/DELETE FROM users/i', $createSrc)
);

$pass(
    'view uses staff change guard',
    str_contains($viewSrc, 'college_student_number_staff_change_error($currentSn, $sn)')
);
$pass(
    'view checks duplicate prepare/execute',
    str_contains($viewSrc, 'if (!$dup)')
    && str_contains($viewSrc, '!mysqli_stmt_execute($dup)')
);
$pass(
    'view checks clear prepare/execute before local state',
    str_contains($viewSrc, 'if (!$clr)')
    && str_contains($viewSrc, '!mysqli_stmt_execute($clr)')
    && strpos($viewSrc, '!mysqli_stmt_execute($clr)') < strpos($viewSrc, "\$u['student_number'] = null")
);
$pass(
    'view checks update prepare/execute before local state',
    str_contains($viewSrc, 'if (!$upd)')
    && str_contains($viewSrc, '!mysqli_stmt_execute($upd)')
    && strpos($viewSrc, '!mysqli_stmt_execute($upd)') < strpos($viewSrc, "\$u['student_number'] = \$sn")
);

echo ($fail === 0 ? "OK {$fail} failed\n" : "FAILED {$fail}\n");
exit($fail === 0 ? 0 : 1);
