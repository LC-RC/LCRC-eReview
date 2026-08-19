<?php
declare(strict_types=1);

/**
 * Platform access / unified identity validation (TEST 1–8).
 * php scripts/platform_access_validate.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/platform_access.php';
require_once dirname(__DIR__) . '/includes/commerce_access_gate.php';
require_once dirname(__DIR__) . '/examination/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_exam_helpers.php';

$results = [];
$mark = static function (string $name, bool $pass, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'result' => $pass ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$stamp = date('YmdHis');
$createdIds = [];
$createdExamIds = [];

try {
    if (!ereview_platform_access_columns_ready($conn)) {
        throw new RuntimeException('college_examination_access column missing (migration 044 / college_schema ensure failed)');
    }

    $adminRes = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1");
    $adminId = ($adminRes && ($ar = mysqli_fetch_row($adminRes))) ? (int) $ar[0] : 1;
    require_once dirname(__DIR__) . '/includes/commerce_admin_manual_grant.php';
    $pass = password_hash('Testpass123!', PASSWORD_DEFAULT);

    // -------------------------------------------------------------------------
    // TEST 1: eReview-only student → login routing unchanged (direct eReview)
    // -------------------------------------------------------------------------
    $emailEr = "platform_er_{$stamp}@example.test";
    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, email, password, role, status, email_verified, section) VALUES ('Platform ER', 'reviewee', 'Test School', ?, ?, 'student', 'approved', 1, NULL)");
    mysqli_stmt_bind_param($ins, 'ss', $emailEr, $pass);
    mysqli_stmt_execute($ins);
    $erId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    $createdIds[] = $erId;
    commerce_admin_grant_manual_access($conn, $erId, $adminId, ['months' => 6, 'activate_login' => true, 'notify_student' => false, 'label' => 'Platform test']);

    $grantsBefore = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id={$erId}"))[0] ?? 0);
    $sectionBefore = mysqli_fetch_assoc(mysqli_query($conn, "SELECT section FROM users WHERE user_id={$erId}"))['section'] ?? null;

    $mark('TEST1 eReview access', ereview_user_has_ereview_access($conn, $erId), "id={$erId}");
    $mark('TEST1 no examination access', !ereview_user_has_college_examination_access($conn, $erId), '');
    $portals1 = ereview_user_available_portals($conn, $erId);
    $mark('TEST1 single portal ereview', $portals1 === ['ereview'], json_encode($portals1));
    $url1 = ereview_user_post_login_url($conn, $erId);
    $mark('TEST1 post-login → eReview dashboard', str_contains($url1, 'student_dashboard') && !str_contains($url1, 'portal_select'), $url1);
    // Incomplete login-shaped row (no college_examination_access key) must still resolve correctly.
    $incomplete = ['user_id' => $erId, 'role' => 'student', 'status' => 'approved', 'access_end' => null];
    $mark(
        'TEST1 incomplete row still ereview-only',
        ereview_user_available_portals($conn, $erId, $incomplete) === ['ereview'],
        json_encode(ereview_user_available_portals($conn, $erId, $incomplete))
    );

    // -------------------------------------------------------------------------
    // TEST 2: Enable College Examination on SAME user_id → portal selector
    // -------------------------------------------------------------------------
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET college_examination_access='active', college_examination_enabled_at=NOW(), college_examination_enabled_by=?,
         review_type='undergrad', section=COALESCE(?, section), student_number=COALESCE(?, student_number)
         WHERE user_id=? AND role='student'"
    );
    $sn = 'PLAT-' . $stamp;
    $sectionNull = null;
    mysqli_stmt_bind_param($upd, 'issi', $adminId, $sectionNull, $sn, $erId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    $row = ereview_user_load_platform_row($conn, $erId);
    $mark('TEST2 same user_id', (int) ($row['user_id'] ?? 0) === $erId, "user_id={$erId}");
    $mark('TEST2 role stays student', ($row['role'] ?? '') === 'student', (string) ($row['role'] ?? ''));
    $mark('TEST2 access flag active', ereview_user_college_examination_access_value($row) === 'active', '');
    $emailChk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT email, password FROM users WHERE user_id={$erId}"));
    $mark('TEST2 same email', ($emailChk['email'] ?? '') === $emailEr, (string) ($emailChk['email'] ?? ''));
    $mark('TEST2 same password hash preserved', password_verify('Testpass123!', (string) ($emailChk['password'] ?? '')), '');
    $portals2 = ereview_user_available_portals($conn, $erId);
    $mark('TEST2 dual portals', $portals2 === ['ereview', 'college_examination'], json_encode($portals2));
    $url2 = ereview_user_post_login_url($conn, $erId);
    $mark('TEST2 post-login → College Examination (exam mode)', str_contains($url2, 'college_student_dashboard'), $url2);
    $mark(
        'TEST2 incomplete login row still dual',
        count(ereview_user_available_portals($conn, $erId, $incomplete)) === 2,
        json_encode(ereview_user_available_portals($conn, $erId, $incomplete))
    );

    // -------------------------------------------------------------------------
    // TEST 3: Select eReview → eReview dashboard URL
    // -------------------------------------------------------------------------
    $mark('TEST3 eReview dashboard URL', str_contains(ereview_portal_dashboard_url('ereview'), 'student_dashboard'), ereview_portal_dashboard_url('ereview'));
    $mark('TEST3 still has ereview access after enable', ereview_user_has_ereview_access($conn, $erId), '');

    // -------------------------------------------------------------------------
    // TEST 4: Select College Examination → examination dashboard + examinee load
    // -------------------------------------------------------------------------
    $mark('TEST4 examination dashboard URL', str_contains(ereview_portal_dashboard_url('college_examination'), 'college_student_dashboard'), ereview_portal_dashboard_url('college_examination'));
    $examinee = ereview_load_college_examinee_user($conn, $erId);
    $mark('TEST4 examinee load works', $examinee !== null && (int) $examinee['user_id'] === $erId, json_encode($examinee));
    $mark('TEST4 college examination access true', ereview_user_has_college_examination_access($conn, $erId), '');

    // -------------------------------------------------------------------------
    // TEST 5: access_grants unchanged after enabling Examination
    // -------------------------------------------------------------------------
    $grantsAfter = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM access_grants WHERE user_id={$erId}"))[0] ?? 0);
    $mark('TEST5 grants count unchanged', $grantsBefore === $grantsAfter && $grantsAfter > 0, "before={$grantsBefore} after={$grantsAfter}");

    // -------------------------------------------------------------------------
    // TEST 6: section unchanged unless explicitly entered
    // -------------------------------------------------------------------------
    $sectionAfter = mysqli_fetch_assoc(mysqli_query($conn, "SELECT section FROM users WHERE user_id={$erId}"))['section'] ?? null;
    $mark(
        'TEST6 section unchanged when not provided',
        ($sectionBefore === null || trim((string) $sectionBefore) === '')
            && ($sectionAfter === null || trim((string) $sectionAfter) === ''),
        'before=' . json_encode($sectionBefore) . ' after=' . json_encode($sectionAfter)
    );

    // Explicit section on a separate enable-style update
    $emailEr2 = "platform_er2_{$stamp}@example.test";
    $ins3 = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, email, password, role, status, email_verified, section) VALUES ('ER2', 'reviewee', 'Test', ?, ?, 'student', 'approved', 1, NULL)");
    mysqli_stmt_bind_param($ins3, 'ss', $emailEr2, $pass);
    mysqli_stmt_execute($ins3);
    $er2Id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins3);
    $createdIds[] = $er2Id;
    commerce_admin_grant_manual_access($conn, $er2Id, $adminId, ['months' => 6, 'activate_login' => true, 'notify_student' => false, 'label' => 'Platform test 2']);
    $secSet = 'BSIT-3A';
    $upd2 = mysqli_prepare($conn, "UPDATE users SET college_examination_access='active', review_type='undergrad', section=COALESCE(?, section) WHERE user_id=? AND role='student'");
    mysqli_stmt_bind_param($upd2, 'si', $secSet, $er2Id);
    mysqli_stmt_execute($upd2);
    mysqli_stmt_close($upd2);
    $r2 = ereview_user_load_platform_row($conn, $er2Id);
    $mark('TEST6 section stored when provided', ($r2['section'] ?? '') === 'BSIT-3A', (string) ($r2['section'] ?? ''));

    // -------------------------------------------------------------------------
    // TEST 7: Examination login access ≠ every exam (assignment still enforced)
    // -------------------------------------------------------------------------
    $title = 'Platform assign test ' . $stamp;
    $insExam = mysqli_prepare(
        $conn,
        "INSERT INTO college_exams (title, description, time_limit_seconds, is_published, examinee_scope, assignment_mode, created_by)
         VALUES (?, 'platform access test', 1800, 1, 'college_student', 'users', ?)"
    );
    if ($insExam) {
        mysqli_stmt_bind_param($insExam, 'si', $title, $adminId);
        if (!mysqli_stmt_execute($insExam)) {
            $mark('TEST7 create exam fixture', false, mysqli_stmt_error($insExam));
            mysqli_stmt_close($insExam);
        } else {
            $examId = (int) mysqli_insert_id($conn);
            mysqli_stmt_close($insExam);
            $createdExamIds[] = $examId;

            // Assign only er2, not er (dual-access user)
            $hasUsers = @mysqli_query($conn, "SHOW TABLES LIKE 'college_exam_users'");
            if ($hasUsers && mysqli_fetch_assoc($hasUsers)) {
                @mysqli_query($conn, "INSERT INTO college_exam_users (exam_id, user_id) VALUES ({$examId}, {$er2Id})");
            }
            $examRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM college_exams WHERE exam_id={$examId}"));
            $assigned = examination_pure_assigned_user_ids($conn, 'regular', $examId);
            $mark('TEST7 assigned list excludes dual user without assignment', !in_array($erId, $assigned, true), json_encode($assigned));
            $mark('TEST7 assigned list includes explicitly assigned user', in_array($er2Id, $assigned, true), json_encode($assigned));
            if (function_exists('examination_user_can_view_exam') && $examRow) {
                $canViewEr = examination_user_can_view_exam($conn, $erId, $examRow, 'regular', null);
                $canViewEr2 = examination_user_can_view_exam($conn, $er2Id, $examRow, 'regular', null);
                $mark('TEST7 dual user cannot view unassigned exam', $canViewEr === false, '');
                $mark('TEST7 assigned user can view exam', $canViewEr2 === true, '');
            } else {
                $mark('TEST7 view helpers available', false, 'examination_user_can_view_exam missing');
            }
        }
    } else {
        $mark('TEST7 create exam fixture', false, mysqli_error($conn));
    }

    // -------------------------------------------------------------------------
    // TEST 8: Legacy role=college_student continues working
    // -------------------------------------------------------------------------
    $emailLeg = "platform_leg_{$stamp}@example.test";
    $ins2 = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, email, password, role, status, email_verified, college_examination_access) VALUES ('Legacy Coll', 'undergrad', 'Test', 'Sec-L', ?, ?, 'college_student', 'approved', 1, 'active')");
    mysqli_stmt_bind_param($ins2, 'ss', $emailLeg, $pass);
    mysqli_stmt_execute($ins2);
    $legId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins2);
    $createdIds[] = $legId;
    $mark('TEST8 legacy examination access', ereview_user_has_college_examination_access($conn, $legId), '');
    $mark('TEST8 legacy no ereview access', !ereview_user_has_ereview_access($conn, $legId), '');
    $legPortals = ereview_user_available_portals($conn, $legId);
    $mark('TEST8 legacy single examination portal', $legPortals === ['college_examination'], json_encode($legPortals));
    $legUrl = ereview_user_post_login_url($conn, $legId);
    $mark('TEST8 legacy post-login → examination dashboard', str_contains($legUrl, 'college_student_dashboard'), $legUrl);
    $legExaminee = ereview_load_college_examinee_user($conn, $legId);
    $mark('TEST8 legacy examinee load', $legExaminee !== null && (int) $legExaminee['user_id'] === $legId, '');

} catch (Throwable $e) {
    $mark('exception', false, $e->getMessage());
}

foreach ($createdExamIds as $eid) {
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
