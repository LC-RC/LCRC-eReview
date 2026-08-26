<?php
declare(strict_types=1);

/**
 * Bulk College Examination enable validation (TEST 1–9).
 * php scripts/bulk_enable_college_examination_validate.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/platform_access.php';
require_once dirname(__DIR__) . '/includes/commerce_admin_manual_grant.php';
require_once dirname(__DIR__) . '/examination/includes/college_sections.php';
require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';

$results = [];
$mark = static function (string $name, bool $pass, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'result' => $pass ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$stamp = date('YmdHis');
$createdIds = [];
$createdExamIds = [];

/**
 * Mirror of bulk API update (same SQL semantics) for CLI testing without HTTP/CSRF.
 *
 * @param list<int> $userIds
 * @return array{ok:bool,error?:string,enabled_count?:int}
 */
function test_bulk_enable(mysqli $conn, array $userIds, ?string $section, string $reviewType, int $adminId): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0)));
    if ($userIds === []) {
        return ['ok' => false, 'error' => 'empty'];
    }
    $sectionVal = null;
    if ($section !== null) {
        $section = trim($section);
        if ($section === '' || mb_strlen($section) > 100) {
            return ['ok' => false, 'error' => 'section'];
        }
        $canonical = college_sections_resolve_active_name($conn, $section);
        if ($canonical === null) {
            return ['ok' => false, 'error' => 'invalid section'];
        }
        $sectionVal = $canonical;
    }
    if (!in_array($reviewType, ['undergrad', 'reviewee'], true)) {
        return ['ok' => false, 'error' => 'review_type'];
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    mysqli_begin_transaction($conn);
    try {
        $chk = mysqli_prepare($conn, "SELECT user_id, role, status FROM users WHERE user_id IN ({$placeholders}) FOR UPDATE");
        mysqli_stmt_bind_param($chk, $types, ...$userIds);
        mysqli_stmt_execute($chk);
        $res = mysqli_stmt_get_result($chk);
        $found = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $found[(int) $row['user_id']] = $row;
        }
        mysqli_stmt_close($chk);
        if (count($found) !== count($userIds)) {
            throw new InvalidArgumentException('missing users');
        }
        foreach ($found as $uid => $row) {
            if (($row['role'] ?? '') !== 'student' || strtolower((string) ($row['status'] ?? '')) === 'rejected') {
                throw new InvalidArgumentException('invalid user ' . $uid);
            }
        }
        $upd = mysqli_prepare(
            $conn,
            "UPDATE users SET college_examination_access='active',
             college_examination_enabled_at=COALESCE(college_examination_enabled_at, NOW()),
             college_examination_enabled_by=COALESCE(college_examination_enabled_by, ?),
             review_type=?, section=?
             WHERE user_id IN ({$placeholders}) AND role='student' AND status<>'rejected'"
        );
        $bindTypes = 'iss' . $types;
        $bindValues = array_merge([$adminId, $reviewType, $sectionVal], $userIds);
        mysqli_stmt_bind_param($upd, $bindTypes, ...$bindValues);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        mysqli_commit($conn);
        return ['ok' => true, 'enabled_count' => count($userIds)];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

try {
    if (!ereview_platform_access_columns_ready($conn)) {
        throw new RuntimeException('college_examination_access column missing');
    }
    college_sections_ensure_schema($conn);
    $adminRes = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1");
    $adminId = ($adminRes && ($ar = mysqli_fetch_row($adminRes))) ? (int) $ar[0] : 1;
    foreach (['Section A', 'Section B'] as $secName) {
        if (college_sections_resolve_active_name($conn, $secName) === null) {
            college_sections_create($conn, $secName, $adminId);
        }
    }
    $pass = password_hash('Testpass123!', PASSWORD_DEFAULT);

    $makeStudent = static function (string $email, ?string $section = null) use ($conn, $pass, &$createdIds, $adminId): int {
        $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, email, password, role, status, email_verified, section) VALUES ('Bulk Test', 'reviewee', 'Test', ?, ?, 'student', 'approved', 1, ?)");
        mysqli_stmt_bind_param($ins, 'sss', $email, $pass, $section);
        mysqli_stmt_execute($ins);
        $id = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        $createdIds[] = $id;
        commerce_admin_grant_manual_access($conn, $id, $adminId, ['months' => 6, 'activate_login' => true, 'notify_student' => false, 'label' => 'Bulk test']);
        return $id;
    };

    // TEST 1: 3 students → Section A
    $idsA = [];
    for ($i = 1; $i <= 3; $i++) {
        $idsA[] = $makeStudent("bulk_a{$i}_{$stamp}@example.test");
    }
    $userCountBefore = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM users'))[0] ?? 0);
    $r1 = test_bulk_enable($conn, $idsA, 'Section A', 'undergrad', $adminId);
    $userCountAfter = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM users'))[0] ?? 0);
    $mark('TEST1 bulk ok', !empty($r1['ok']), json_encode($r1));
    $mark('TEST1 no new users', $userCountBefore === $userCountAfter, "before={$userCountBefore} after={$userCountAfter}");
    $okA = true;
    foreach ($idsA as $uid) {
        $row = ereview_user_load_platform_row($conn, $uid);
        if (($row['role'] ?? '') !== 'student' || ereview_user_college_examination_access_value($row) !== 'active' || ($row['section'] ?? '') !== 'Section A') {
            $okA = false;
        }
    }
    $mark('TEST1 three users updated same ids', $okA, json_encode($idsA));

    // TEST 2: 10 students → Section B
    $idsB = [];
    for ($i = 1; $i <= 10; $i++) {
        $idsB[] = $makeStudent("bulk_b{$i}_{$stamp}@example.test");
    }
    $r2 = test_bulk_enable($conn, $idsB, 'Section B', 'undergrad', $adminId);
    $allB = true;
    foreach ($idsB as $uid) {
        $row = ereview_user_load_platform_row($conn, $uid);
        if (($row['section'] ?? '') !== 'Section B' || ereview_user_college_examination_access_value($row) !== 'active') {
            $allB = false;
        }
    }
    $mark('TEST2 ten students section B', !empty($r2['ok']) && $allB, json_encode($r2));

    // TEST 3: 5 unselected unchanged
    $idsLeave = [];
    for ($i = 1; $i <= 5; $i++) {
        $idsLeave[] = $makeStudent("bulk_leave{$i}_{$stamp}@example.test");
    }
    $unchanged = true;
    foreach ($idsLeave as $uid) {
        $row = ereview_user_load_platform_row($conn, $uid);
        if (ereview_user_college_examination_access_value($row) === 'active') {
            $unchanged = false;
        }
        if (($row['section'] ?? null) !== null && trim((string) $row['section']) !== '') {
            $unchanged = false;
        }
    }
    $mark('TEST3 unselected unchanged', $unchanged, '');

    // TEST 4: already enabled idempotent
    $grantsBefore = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE user_id IN (' . implode(',', $idsA) . ')'))[0] ?? 0);
    $r4 = test_bulk_enable($conn, $idsA, 'Section A', 'undergrad', $adminId);
    $countUsers = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM users WHERE user_id IN (' . implode(',', $idsA) . ')'))[0] ?? 0);
    $mark('TEST4 idempotent still 3 rows', !empty($r4['ok']) && $countUsers === 3, "users={$countUsers}");

    // TEST 6: grants unchanged (also covers after TEST1 enable)
    $grantsAfter = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE user_id IN (' . implode(',', $idsA) . ')'))[0] ?? 0);
    $mark('TEST6 access_grants unchanged', $grantsBefore === $grantsAfter && $grantsAfter >= 3, "before={$grantsBefore} after={$grantsAfter}");

    // TEST 5: section A exam eligibility
    $title = 'Bulk section exam ' . $stamp;
    $insExam = mysqli_prepare(
        $conn,
        "INSERT INTO college_exams (title, description, is_published, time_limit_seconds, examinee_scope, assignment_mode, created_by)
         VALUES (?, 'bulk test', 1, 1800, 'college_student', 'sections', ?)"
    );
    if ($insExam) {
        mysqli_stmt_bind_param($insExam, 'si', $title, $adminId);
        mysqli_stmt_execute($insExam);
        $examId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($insExam);
        $createdExamIds[] = $examId;
        @mysqli_query($conn, "INSERT INTO college_exam_sections (exam_id, section_value) VALUES ({$examId}, 'Section A')");
        $assigned = examination_pure_assigned_user_ids($conn, 'regular', $examId);
        $aOk = true;
        foreach ($idsA as $uid) {
            if (!in_array($uid, $assigned, true)) {
                $aOk = false;
            }
        }
        $bBlocked = true;
        foreach ($idsB as $uid) {
            if (in_array($uid, $assigned, true)) {
                $bBlocked = false;
            }
        }
        $mark('TEST5 Section A students eligible', $aOk, json_encode(array_slice($assigned, 0, 5)));
        $mark('TEST5 Section B students not eligible', $bBlocked, '');
    } else {
        $mark('TEST5 create exam', false, mysqli_error($conn));
    }

    // TEST 7: login routing for examination-enabled student
    $u7 = ereview_user_load_platform_row($conn, $idsA[0]);
    $url7 = ereview_user_post_login_url($conn, $idsA[0], $u7);
    $mark('TEST7 routes to College Examination', str_contains($url7, 'college_student_dashboard'), $url7);
    $emailPass = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT email, password, role FROM users WHERE user_id=' . (int) $idsA[0]));
    $mark('TEST7 same credentials identity', ($emailPass['role'] ?? '') === 'student' && password_verify('Testpass123!', (string) ($emailPass['password'] ?? '')), (string) ($emailPass['email'] ?? ''));

    // TEST 8: eReview-only unchanged
    $onlyId = $makeStudent("bulk_only_{$stamp}@example.test");
    $mark('TEST8 eReview-only has ereview', ereview_user_has_ereview_access($conn, $onlyId), '');
    $mark('TEST8 eReview-only no examination', !ereview_user_has_college_examination_access($conn, $onlyId), '');
    $url8 = ereview_user_post_login_url($conn, $onlyId);
    $mark('TEST8 eReview-only → student dashboard', str_contains($url8, 'student_dashboard') && !str_contains($url8, 'portal_select'), $url8);

    // TEST 9: optional section — enable without section assignment
    $idsNoSec = [];
    for ($i = 1; $i <= 2; $i++) {
        $idsNoSec[] = $makeStudent("bulk_nosec{$i}_{$stamp}@example.test");
    }
    $r9 = test_bulk_enable($conn, $idsNoSec, null, 'undergrad', $adminId);
    $noSecOk = !empty($r9['ok']);
    foreach ($idsNoSec as $uid) {
        $row = ereview_user_load_platform_row($conn, $uid);
        if (ereview_user_college_examination_access_value($row) !== 'active') {
            $noSecOk = false;
        }
        $sec = trim((string) ($row['section'] ?? ''));
        if ($sec !== '') {
            $noSecOk = false;
        }
    }
    $url9 = ereview_user_post_login_url($conn, $idsNoSec[0], ereview_user_load_platform_row($conn, $idsNoSec[0]));
    $mark('TEST9 enable without section', $noSecOk, json_encode($r9));
    $mark('TEST9 no-section login routes to College Examination', str_contains($url9, 'college_student_dashboard'), $url9);
    if (isset($examId) && $examId > 0) {
        $assignedNoSec = examination_pure_assigned_user_ids($conn, 'regular', $examId);
        $blockedNoSec = true;
        foreach ($idsNoSec as $uid) {
            if (in_array($uid, $assignedNoSec, true)) {
                $blockedNoSec = false;
            }
        }
        $mark('TEST9 no-section students not eligible for Section A exam', $blockedNoSec, '');
    }

} catch (Throwable $e) {
    $mark('exception', false, $e->getMessage());
}

foreach ($createdExamIds as $eid) {
    @mysqli_query($conn, 'DELETE FROM college_exam_sections WHERE exam_id=' . (int) $eid);
    @mysqli_query($conn, 'DELETE FROM college_exam_users WHERE exam_id=' . (int) $eid);
    @mysqli_query($conn, 'DELETE FROM college_exams WHERE exam_id=' . (int) $eid);
}
foreach (array_reverse($createdIds) as $uid) {
    @mysqli_query($conn, 'DELETE FROM access_grants WHERE user_id=' . (int) $uid);
    @mysqli_query($conn, 'DELETE FROM student_content_permissions WHERE user_id=' . (int) $uid);
    @mysqli_query($conn, 'DELETE FROM college_exam_users WHERE user_id=' . (int) $uid);
    @mysqli_query($conn, 'DELETE FROM users WHERE user_id=' . (int) $uid);
}

$ok = !in_array('FAIL', array_column($results, 'result'), true);
echo json_encode(['ok' => $ok, 'checks' => $results], JSON_PRETTY_PRINT) . PHP_EOL;
exit($ok ? 0 : 1);
