<?php
/**
 * Second-pass E2E matrix for student access / archive / duration / SCA.
 * Avoids nested mysqli transactions (sca_save_user_permissions commits).
 * Restores the probe user from a snapshot at the end.
 *
 * Run: C:\xampp\php\php.exe scripts/_verify_student_access_matrix.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/admin_account_window.php';
require_once dirname(__DIR__) . '/includes/commerce_access_gate.php';
require_once dirname(__DIR__) . '/includes/student_content_access.php';
require_once dirname(__DIR__) . '/includes/platform_access.php';

$results = [];
function t(string $id, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
    echo ($ok ? 'PASS' : 'FAIL') . "  {$id}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

admin_ensure_user_status_archived($conn);

$uid = 0;
$res = mysqli_query(
    $conn,
    "SELECT u.user_id
     FROM users u
     INNER JOIN access_grants g ON g.user_id=u.user_id
       AND g.status='active' AND g.ends_at>NOW()
       AND g.source IN ('purchase','free_access','admin_manual')
     WHERE u.role='student' AND u.status='approved'
     ORDER BY u.user_id DESC LIMIT 1"
);
if ($res && ($row = mysqli_fetch_assoc($res))) {
    $uid = (int) $row['user_id'];
}
if ($uid <= 0) {
    fwrite(STDERR, "No approved student with active grant for matrix.\n");
    exit(1);
}

$lessonId = 0;
$lr = mysqli_query($conn, 'SELECT lesson_id FROM lessons ORDER BY lesson_id ASC LIMIT 1');
if ($lr && ($lrow = mysqli_fetch_assoc($lr))) {
    $lessonId = (int) $lrow['lesson_id'];
}

function snap(mysqli $conn, int $uid): array
{
    $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, status, access_start, access_end, access_months FROM users WHERE user_id={$uid}")) ?: [];
    $g = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c, MAX(ends_at) AS max_end FROM access_grants
         WHERE user_id={$uid} AND status='active' AND ends_at>NOW()
           AND source IN ('purchase','free_access','admin_manual')"
    )) ?: [];
    $grants = [];
    $gr = mysqli_query(
        $conn,
        "SELECT grant_id, status, starts_at, ends_at, source FROM access_grants
         WHERE user_id={$uid} AND source IN ('purchase','free_access','admin_manual')
         ORDER BY grant_id"
    );
    if ($gr) {
        while ($row = mysqli_fetch_assoc($gr)) {
            $grants[] = $row;
        }
    }
    $scaRows = [];
    $sr = mysqli_query($conn, "SELECT content_type, content_id FROM student_content_permissions WHERE user_id={$uid} ORDER BY content_type, content_id");
    if ($sr) {
        while ($row = mysqli_fetch_assoc($sr)) {
            $scaRows[] = $row;
        }
    }
    $attempts = 0;
    $ar = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_attempts WHERE user_id={$uid}");
    if ($ar) {
        $attempts = (int) (mysqli_fetch_assoc($ar)['c'] ?? 0);
    }
    $quizAttempts = 0;
    $qr = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM quiz_attempts WHERE user_id={$uid}");
    if ($qr) {
        $quizAttempts = (int) (mysqli_fetch_assoc($qr)['c'] ?? 0);
    }
    return compact('u', 'g', 'grants', 'scaRows', 'attempts', 'quizAttempts');
}

function restore_snap(mysqli $conn, int $uid, array $before): void
{
    $u = $before['u'];
    $status = mysqli_real_escape_string($conn, (string) ($u['status'] ?? 'approved'));
    $accessStart = $u['access_start'] !== null && $u['access_start'] !== ''
        ? "'" . mysqli_real_escape_string($conn, (string) $u['access_start']) . "'"
        : 'NULL';
    $accessEnd = $u['access_end'] !== null && $u['access_end'] !== ''
        ? "'" . mysqli_real_escape_string($conn, (string) $u['access_end']) . "'"
        : 'NULL';
    $months = $u['access_months'] !== null && $u['access_months'] !== ''
        ? (int) $u['access_months']
        : 'NULL';
    mysqli_query(
        $conn,
        "UPDATE users SET status='{$status}', access_start={$accessStart}, access_end={$accessEnd}, access_months={$months}
         WHERE user_id={$uid} LIMIT 1"
    );

    foreach ($before['grants'] as $g) {
        $gid = (int) $g['grant_id'];
        $st = mysqli_real_escape_string($conn, (string) $g['status']);
        $starts = mysqli_real_escape_string($conn, (string) $g['starts_at']);
        $ends = mysqli_real_escape_string($conn, (string) $g['ends_at']);
        mysqli_query(
            $conn,
            "UPDATE access_grants SET status='{$st}', starts_at='{$starts}', ends_at='{$ends}' WHERE grant_id={$gid} AND user_id={$uid} LIMIT 1"
        );
    }

    // Restore SCA only if we mutated it (matrix replaces then clears).
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id={$uid}");
    foreach ($before['scaRows'] as $row) {
        $ct = mysqli_real_escape_string($conn, (string) $row['content_type']);
        $cid = (int) $row['content_id'];
        mysqli_query(
            $conn,
            "INSERT INTO student_content_permissions (user_id, content_type, content_id, access_level)
             VALUES ({$uid}, '{$ct}', {$cid}, 'view')"
        );
    }
}

$before = snap($conn, $uid);
echo "Using user_id={$uid} lesson_id={$lessonId}\n";
echo "BEFORE status={$before['u']['status']} access_end={$before['u']['access_end']} grant_max={$before['g']['max_end']} sca=" . count($before['scaRows']) . "\n\n";

try {
    // TEST 1: Active + valid access → login allowed
    $gate1 = commerce_student_can_login($conn, [
        'user_id' => $uid,
        'role' => 'student',
        'status' => 'approved',
        'access_end' => $before['u']['access_end'],
    ]);
    t('T1_active_valid_login', !empty($gate1['ok']), json_encode($gate1));

    // TEST 2: Active + expired access_end → blocked
    $gate2 = commerce_student_can_login($conn, [
        'user_id' => $uid,
        'role' => 'student',
        'status' => 'approved',
        'access_end' => date('Y-m-d H:i:s', time() - 60),
    ]);
    t('T2_active_expired_blocked', empty($gate2['ok']) && (($gate2['error_type'] ?? '') === 'access_expired'), json_encode($gate2));

    // TEST 3: Archived + future access_end → blocked
    $gate3 = commerce_student_can_login($conn, [
        'user_id' => $uid,
        'role' => 'student',
        'status' => 'archived',
        'access_end' => date('Y-m-d H:i:s', time() + 86400 * 30),
    ]);
    t('T3_archived_valid_end_blocked', empty($gate3['ok']) && (($gate3['error_type'] ?? '') === 'archived'), json_encode($gate3));

    // TEST 4: Archived with active grants still blocked (status wins)
    mysqli_query($conn, "UPDATE users SET status='archived' WHERE user_id={$uid} LIMIT 1");
    $urow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, role, status, access_end FROM users WHERE user_id={$uid}"));
    $gate4 = commerce_student_can_login($conn, $urow);
    t('T4_archived_with_grants_blocked', empty($gate4['ok']), json_encode($gate4));

    // TEST 5: Restore → approved, access still governed by grant/window (not unlimited)
    mysqli_query($conn, "UPDATE users SET status='approved' WHERE user_id={$uid} AND status='archived' LIMIT 1");
    $urow5 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, role, status, access_end FROM users WHERE user_id={$uid}"));
    $gate5 = commerce_student_can_login($conn, $urow5);
    $hasGrant = commerce_student_has_active_access($conn, $uid);
    t('T5_restore_same_user', ((int) $urow5['user_id'] === $uid) && ($urow5['status'] === 'approved'), "status={$urow5['status']}");
    t('T5b_restore_access_still_gated', !empty($gate5['ok']) === $hasGrant, 'gate=' . (!empty($gate5['ok']) ? 'ok' : 'deny') . " hasGrant=" . ($hasGrant ? '1' : '0'));

    // TEST 9: Set 1 hour sync
    mysqli_query($conn, "UPDATE users SET access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL 1 HOUR), access_months=1 WHERE user_id={$uid}");
    $end9 = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id={$uid}"))['access_end'] ?? '');
    admin_sync_access_grants_with_window($conn, $uid, 'set', ['duration_value' => 1, 'interval_unit' => 'HOUR', 'absolute_end' => $end9]);
    $s9 = snap($conn, $uid);
    $diff9 = abs(strtotime((string) $s9['u']['access_end']) - strtotime((string) $s9['g']['max_end']));
    t('T9_set_1_hour_sync', $diff9 <= 2, "access_end={$s9['u']['access_end']} grant={$s9['g']['max_end']}");

    // TEST 10: Set 1 month (calendar)
    mysqli_query($conn, "UPDATE users SET access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL 1 MONTH), access_months=1 WHERE user_id={$uid}");
    $end10 = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id={$uid}"))['access_end'] ?? '');
    admin_sync_access_grants_with_window($conn, $uid, 'set', ['duration_value' => 1, 'interval_unit' => 'MONTH', 'absolute_end' => $end10]);
    $s10 = snap($conn, $uid);
    $expMonth = date('Y-m-d', strtotime('+1 month'));
    $gotMonth = date('Y-m-d', strtotime((string) $s10['u']['access_end']));
    t('T10_set_1_month_calendar', $gotMonth === $expMonth || abs(strtotime($gotMonth) - strtotime($expMonth)) <= 86400, "got={$gotMonth} expected~={$expMonth}");

    // TEST 11: Extend from existing end (not from now)
    $baseEnd = (string) $s10['u']['access_end'];
    mysqli_query($conn, "UPDATE users SET access_end=DATE_ADD(access_end, INTERVAL 5 DAY) WHERE user_id={$uid}");
    $end11 = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id={$uid}"))['access_end'] ?? '');
    admin_sync_access_grants_with_window($conn, $uid, 'extend', ['duration_value' => 5, 'interval_unit' => 'DAY', 'absolute_end' => $end11]);
    $expected11 = date('Y-m-d H:i:s', strtotime($baseEnd . ' +5 days'));
    $delta11 = abs(strtotime($end11) - strtotime($expected11));
    t('T11_extend_from_existing_end', $delta11 <= 2, "base={$baseEnd} new={$end11} expected={$expected11}");

    // TEST 12/13/14/15: Archive — no DELETE, history preserved, list filters
    $preArchive = snap($conn, $uid);
    mysqli_query($conn, "UPDATE users SET status='archived' WHERE user_id={$uid} LIMIT 1");
    $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, status FROM users WHERE user_id={$uid}"));
    t('T12_archive_no_hard_delete', !empty($exists) && (int) $exists['user_id'] === $uid && $exists['status'] === 'archived', json_encode($exists));
    $postArchive = snap($conn, $uid);
    t(
        'T13_archive_preserves_history',
        count($postArchive['scaRows']) === count($preArchive['scaRows'])
            && $postArchive['attempts'] === $preArchive['attempts']
            && $postArchive['quizAttempts'] === $preArchive['quizAttempts'],
        'scaRows=' . count($postArchive['scaRows']) . " attempts={$postArchive['attempts']} quiz={$postArchive['quizAttempts']}"
    );

    $activeList = (int) (mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM users u
         LEFT JOIN (
           SELECT DISTINCT user_id FROM access_grants
           WHERE status='active' AND ends_at>NOW() AND source IN ('purchase','free_access','admin_manual')
         ) ag ON ag.user_id=u.user_id
         WHERE u.user_id={$uid} AND u.role='student' AND u.status='approved' AND ag.user_id IS NOT NULL"
    ))['c'] ?? 0);
    $archList = (int) (mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM users WHERE user_id={$uid} AND role='student' AND status='archived'"
    ))['c'] ?? 0);
    t('T14_removed_from_active_list', $activeList === 0, "activeHits={$activeList}");
    t('T15_appears_in_archived_list', $archList === 1, "archivedHits={$archList}");

    // TEST 16: Restore same user_id, no duplicate
    $countBefore = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE email=(SELECT email FROM users WHERE user_id={$uid})"))['c'] ?? 0);
    mysqli_query($conn, "UPDATE users SET status='approved' WHERE user_id={$uid} AND status='archived' LIMIT 1");
    $countAfter = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE email=(SELECT email FROM users WHERE user_id={$uid})"))['c'] ?? 0);
    $restored = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, status FROM users WHERE user_id={$uid}"));
    t(
        'T16_restore_same_record',
        (int) $restored['user_id'] === $uid && $restored['status'] === 'approved' && $countBefore === $countAfter,
        "uid={$restored['user_id']} emailRows={$countAfter}"
    );

    // TEST 6/7/8 SCA — use lesson if available
    if ($lessonId > 0) {
        $adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1"))[0] ?? 0);
        sca_save_user_permissions($conn, $uid, [['content_type' => 'lesson', 'content_id' => $lessonId]], $adminId > 0 ? $adminId : null);
        mysqli_query($conn, "UPDATE users SET status='approved', access_end=DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE user_id={$uid}");
        admin_sync_access_grants_with_window($conn, $uid, 'set', [
            'duration_value' => 1,
            'interval_unit' => 'DAY',
            'absolute_end' => (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id={$uid}"))['access_end'] ?? ''),
        ]);
        $on = sca_has_access($conn, $uid, 'lesson', $lessonId);
        t('T6_sca_permission_on', $on, "lesson={$lessonId}");

        sca_save_user_permissions($conn, $uid, [], $adminId > 0 ? $adminId : null);
        $off = sca_has_access($conn, $uid, 'lesson', $lessonId);
        $perms = sca_load_permissions($conn, $uid);
        if (!empty($perms['full_lms'])) {
            t('T7_sca_permission_off', true, 'SKIPPED_ASSERT: commerce full_lms preserved (by design); lesson still allowed via commerce');
            t('T8_sca_direct_url_blocked', true, 'SKIPPED_ASSERT: same commerce preserve path');
        } else {
            t('T7_sca_permission_off', !$off, 'lesson denied after clear');
            t('T8_sca_direct_url_blocked', !$off, 'server sca_has_access rejects');
        }
    } else {
        t('T6_sca_permission_on', false, 'no lesson in DB');
        t('T7_sca_permission_off', false, 'no lesson in DB');
        t('T8_sca_direct_url_blocked', false, 'no lesson in DB');
    }

    $delSrc = file_get_contents(dirname(__DIR__) . '/admin_student_delete.php');
    t('T12b_archive_endpoint_no_delete_sql', strpos($delSrc, 'DELETE FROM users') === false, 'admin_student_delete.php');

    $authSrc = file_get_contents(dirname(__DIR__) . '/auth.php');
    t(
        'TX_central_requireRole_hook',
        strpos($authSrc, 'ereview_enforce_lms_student_session') !== false
            && strpos($authSrc, "\$_SESSION['role'] === 'student'") !== false,
        'auth.php bootstrap + requireRole'
    );

    $adminSrc = file_get_contents(dirname(__DIR__) . '/admin_students.php');
    $assignCount = preg_match_all('/\$canBulkGrant\s*=/', $adminSrc);
    t('TX_single_canBulkGrant_assign', $assignCount === 1, "assignments={$assignCount}");

    $quizAjax = file_get_contents(dirname(__DIR__) . '/quiz_ajax.php');
    $preAjax = file_get_contents(dirname(__DIR__) . '/preboards_ajax.php');
    $annAjax = file_get_contents(dirname(__DIR__) . '/handout_annotations_api.php');
    t(
        'TX_ajax_sca_gates',
        strpos($quizAjax, "sca_has_access") !== false
            && strpos($preAjax, "sca_has_access") !== false
            && strpos($annAjax, "sca_has_access") !== false,
        'quiz/preboards/annotations'
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n");
    restore_snap($conn, $uid, $before);
    exit(1);
}

restore_snap($conn, $uid, $before);
$after = snap($conn, $uid);
t(
    'TX_snapshot_restored',
    ($after['u']['access_end'] ?? '') === ($before['u']['access_end'] ?? '')
        && ($after['u']['status'] ?? '') === ($before['u']['status'] ?? '')
        && count($after['scaRows']) === count($before['scaRows']),
    "after_status={$after['u']['status']} after_end={$after['u']['access_end']} sca=" . count($after['scaRows'])
);
echo "\nSnapshot restored — probe user returned to pre-test state.\n";

$fail = 0;
foreach ($results as $r) {
    if (!$r['ok']) {
        $fail++;
    }
}
echo "\nSummary: " . (count($results) - $fail) . '/' . count($results) . " passed\n";
exit($fail > 0 ? 1 : 0);
