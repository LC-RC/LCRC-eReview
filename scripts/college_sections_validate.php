<?php
declare(strict_types=1);

/**
 * Centralized college_sections validation.
 * php scripts/college_sections_validate.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/college_sections.php';
require_once dirname(__DIR__) . '/includes/commerce_admin_manual_grant.php';
require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';

$results = [];
$mark = static function (string $name, bool $pass, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'result' => $pass ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$stamp = date('YmdHis');
$createdSectionIds = [];
$createdUserIds = [];
$createdExamIds = [];

try {
    college_sections_ensure_schema($conn);
    college_sections_seed_from_existing($conn);
    $mark('schema ready', true, '');

    $adminRes = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1");
    $adminId = ($adminRes && ($ar = mysqli_fetch_row($adminRes))) ? (int) $ar[0] : 1;

    $nameA = 'QA-SEC-A-' . $stamp;
    $nameB = 'QA-SEC-B-' . $stamp;
    $c1 = college_sections_create($conn, $nameA, $adminId);
    $c2 = college_sections_create($conn, $nameB, $adminId);
    $mark('create section A', !empty($c1['ok']), json_encode($c1));
    $mark('create section B', !empty($c2['ok']), json_encode($c2));
    $createdSectionIds[] = (int) ($c1['section_id'] ?? 0);
    $createdSectionIds[] = (int) ($c2['section_id'] ?? 0);

    $dup = college_sections_create($conn, $nameA, $adminId);
    $mark('reject duplicate section', empty($dup['ok']), json_encode($dup));

    $active = college_sections_active_names($conn);
    $mark('active names include A', in_array($nameA, $active, true), '');
    $mark('resolve active name', college_sections_resolve_active_name($conn, $nameA) === $nameA, '');
    $mark('reject unknown name', college_sections_resolve_active_name($conn, 'NOPE-' . $stamp) === null, '');

    // Bulk enable uses master section
    $pass = password_hash('Testpass123!', PASSWORD_DEFAULT);
    $ids = [];
    for ($i = 1; $i <= 3; $i++) {
        $email = "secbulk{$i}_{$stamp}@example.test";
        $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, email, password, role, status, email_verified) VALUES ('Sec Bulk', 'reviewee', 'Test', ?, ?, 'student', 'approved', 1)");
        mysqli_stmt_bind_param($ins, 'ss', $email, $pass);
        mysqli_stmt_execute($ins);
        $uid = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        $createdUserIds[] = $uid;
        $ids[] = $uid;
        commerce_admin_grant_manual_access($conn, $uid, $adminId, ['months' => 6, 'activate_login' => true, 'notify_student' => false, 'label' => 'sec test']);
    }
    $usersBefore = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM users'))[0] ?? 0);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    mysqli_begin_transaction($conn);
    $upd = mysqli_prepare($conn, "UPDATE users SET college_examination_access='active', review_type='undergrad', section=? WHERE user_id IN ({$placeholders}) AND role='student'");
    $bind = array_merge([$nameA], $ids);
    mysqli_stmt_bind_param($upd, 's' . $types, ...$bind);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    mysqli_commit($conn);
    $usersAfter = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM users'))[0] ?? 0);
    $mark('bulk enable no new users', $usersBefore === $usersAfter, "before={$usersBefore} after={$usersAfter}");
    $allA = true;
    foreach ($ids as $uid) {
        $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT section, college_examination_access, role FROM users WHERE user_id=' . (int) $uid));
        if (($row['section'] ?? '') !== $nameA || ($row['college_examination_access'] ?? '') !== 'active' || ($row['role'] ?? '') !== 'student') {
            $allA = false;
        }
    }
    $mark('bulk enable same canonical section', $allA, '');

    // Exam assignment eligibility
    $title = 'Sec exam ' . $stamp;
    $insExam = mysqli_prepare($conn, "INSERT INTO college_exams (title, description, is_published, time_limit_seconds, examinee_scope, assignment_mode, created_by) VALUES (?, 't', 1, 1800, 'college_student', 'sections', ?)");
    mysqli_stmt_bind_param($insExam, 'si', $title, $adminId);
    mysqli_stmt_execute($insExam);
    $examId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($insExam);
    $createdExamIds[] = $examId;
    $stSec = mysqli_prepare($conn, 'INSERT INTO college_exam_sections (exam_id, section_value) VALUES (?, ?)');
    mysqli_stmt_bind_param($stSec, 'is', $examId, $nameA);
    mysqli_stmt_execute($stSec);
    mysqli_stmt_close($stSec);
    $assigned = examination_pure_assigned_user_ids($conn, 'regular', $examId);
    $mark('section A students eligible', in_array($ids[0], $assigned, true), json_encode($assigned));

    // Rename propagates
    $renamed = $nameA . '-REN';
    $ru = college_sections_update($conn, (int) $c1['section_id'], $renamed, 'active', $adminId);
    $mark('rename ok', !empty($ru['ok']), json_encode($ru));
    $rowAfter = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT section FROM users WHERE user_id=' . (int) $ids[0]));
    $mark('rename updates users.section', ($rowAfter['section'] ?? '') === $renamed, (string) ($rowAfter['section'] ?? ''));
    $exSec = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT section_value FROM college_exam_sections WHERE exam_id=' . (int) $examId . ' LIMIT 1'));
    $mark('rename updates exam assignment value', ($exSec['section_value'] ?? '') === $renamed, (string) ($exSec['section_value'] ?? ''));

    // Delete blocked when section is referenced by a student
    @mysqli_query($conn, 'UPDATE users SET section=' . "'" . mysqli_real_escape_string($conn, $nameB) . "' WHERE user_id=" . (int) $ids[1]);
    $refsB = college_sections_reference_counts($conn, $nameB);
    $delBlocked = college_sections_delete($conn, (int) ($c2['section_id'] ?? 0), $adminId);
    $mark('delete blocked when student assigned', empty($delBlocked['ok']) && ($refsB['students'] ?? 0) > 0, json_encode(['refs' => $refsB, 'del' => $delBlocked]));

    // Assign exam to section B, then delete should still fail
    $stSecB = mysqli_prepare($conn, 'INSERT INTO college_exam_sections (exam_id, section_value) VALUES (?, ?)');
    mysqli_stmt_bind_param($stSecB, 'is', $examId, $nameB);
    mysqli_stmt_execute($stSecB);
    mysqli_stmt_close($stSecB);
    $delBlockedExam = college_sections_delete($conn, (int) ($c2['section_id'] ?? 0), $adminId);
    $mark('delete blocked when exam assignment exists', empty($delBlockedExam['ok']), json_encode($delBlockedExam));

    // Unreferenced section can be deleted
    $emptyName = 'QA-SEC-EMPTY-' . $stamp;
    $cEmpty = college_sections_create($conn, $emptyName, $adminId);
    $delEmpty = college_sections_delete($conn, (int) ($cEmpty['section_id'] ?? 0), $adminId);
    $mark('delete unreferenced section ok', !empty($delEmpty['ok']), json_encode($delEmpty));

} catch (Throwable $e) {
    $mark('exception', false, $e->getMessage());
}

foreach ($createdExamIds as $eid) {
    @mysqli_query($conn, 'DELETE FROM college_exam_sections WHERE exam_id=' . (int) $eid);
    @mysqli_query($conn, 'DELETE FROM college_exams WHERE exam_id=' . (int) $eid);
}
foreach (array_reverse($createdUserIds) as $uid) {
    @mysqli_query($conn, 'DELETE FROM access_grants WHERE user_id=' . (int) $uid);
    @mysqli_query($conn, 'DELETE FROM users WHERE user_id=' . (int) $uid);
}
foreach ($createdSectionIds as $sid) {
    if ($sid > 0) {
        @mysqli_query($conn, 'DELETE FROM college_sections WHERE section_id=' . (int) $sid);
    }
}
@mysqli_query($conn, "DELETE FROM college_sections WHERE section_name LIKE 'QA-SEC-%'");

$ok = !in_array('FAIL', array_column($results, 'result'), true);
echo json_encode(['ok' => $ok, 'checks' => $results], JSON_PRETTY_PRINT) . PHP_EOL;
exit($ok ? 0 : 1);
