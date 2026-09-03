<?php
/**
 * Subject + topic SCA matrix (CLI-safe).
 *
 * Run: C:\xampp\php\php.exe scripts/_verify_sca_subject_topic_matrix.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/student_content_access.php';
require_once dirname(__DIR__) . '/includes/commerce_access_gate.php';

$results = [];
function t(string $id, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
    echo ($ok ? 'PASS' : 'FAIL') . "  {$id}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// Find a subject with at least 2 lessons
$sub = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT s.subject_id, s.subject_name, COUNT(l.lesson_id) AS cnt
     FROM subjects s
     INNER JOIN lessons l ON l.subject_id = s.subject_id
     GROUP BY s.subject_id, s.subject_name
     HAVING cnt >= 2
     ORDER BY s.subject_id ASC
     LIMIT 1"
));
if (!$sub) {
    fwrite(STDERR, "Need a subject with >= 2 lessons.\n");
    exit(1);
}
$subjectId = (int) $sub['subject_id'];
$otherSub = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT subject_id FROM subjects WHERE subject_id <> {$subjectId} ORDER BY subject_id ASC LIMIT 1"
));
$otherSubjectId = (int) ($otherSub['subject_id'] ?? 0);

$lessons = [];
$lr = mysqli_query($conn, "SELECT lesson_id, title FROM lessons WHERE subject_id={$subjectId} ORDER BY lesson_id ASC LIMIT 4");
while ($lr && ($row = mysqli_fetch_assoc($lr))) {
    $lessons[] = $row;
}
$lesson1 = (int) $lessons[0]['lesson_id'];
$lesson2 = (int) $lessons[1]['lesson_id'];
$lesson3 = isset($lessons[2]) ? (int) $lessons[2]['lesson_id'] : 0;

$handout2 = 0;
$hr = mysqli_query($conn, "SELECT handout_id FROM lesson_handouts WHERE lesson_id={$lesson2} LIMIT 1");
if ($hr && ($hrow = mysqli_fetch_assoc($hr))) {
    $handout2 = (int) $hrow['handout_id'];
}
$video2 = 0;
$vr = mysqli_query($conn, "SELECT video_id FROM lesson_videos WHERE lesson_id={$lesson2} LIMIT 1");
if ($vr && ($vrow = mysqli_fetch_assoc($vr))) {
    $video2 = (int) $vrow['video_id'];
}

// Probe student with active login access
$uid = 0;
$ures = mysqli_query(
    $conn,
    "SELECT u.user_id
     FROM users u
     INNER JOIN access_grants g ON g.user_id=u.user_id
       AND g.status='active' AND g.ends_at>NOW()
       AND g.source IN ('purchase','free_access','admin_manual')
     WHERE u.role='student' AND u.status='approved'
     ORDER BY u.user_id DESC LIMIT 1"
);
if ($ures && ($urow = mysqli_fetch_assoc($ures))) {
    $uid = (int) $urow['user_id'];
}
if ($uid <= 0) {
    fwrite(STDERR, "No approved student with active grant.\n");
    exit(1);
}

$adminId = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1"))[0] ?? 0);

$beforeSca = [];
$sr = mysqli_query($conn, "SELECT content_type, content_id FROM student_content_permissions WHERE user_id={$uid}");
while ($sr && ($row = mysqli_fetch_assoc($sr))) {
    $beforeSca[] = $row;
}

echo "subject={$subjectId} ({$sub['subject_name']}) lessons={$lesson1},{$lesson2}" . ($lesson3 ? ",{$lesson3}" : '') . " otherSubject={$otherSubjectId} user={$uid}\n\n";

function restore_sca(mysqli $conn, int $uid, array $beforeSca, ?int $adminId): void
{
    $payload = [];
    foreach ($beforeSca as $row) {
        $payload[] = ['content_type' => (string) $row['content_type'], 'content_id' => (int) $row['content_id']];
    }
    sca_save_user_permissions($conn, $uid, $payload, $adminId);
}

try {
    // CASE B: Selected Topics — Topic 1 only (no subject row)
    sca_save_user_permissions_preserving_commerce($conn, $uid, [
        ['content_type' => 'lesson', 'content_id' => $lesson1],
    ], $adminId > 0 ? $adminId : null);

    // Simulate commerce full_lms grant row existing — re-save should NOT force full LMS
    $hasFullGrant = (int) (mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM access_grants
         WHERE user_id={$uid} AND content_type='full_lms' AND status='active' AND ends_at>NOW()
           AND source IN ('purchase','free_access','admin_manual')"
    ))['c'] ?? 0);
    sca_save_user_permissions_preserving_commerce($conn, $uid, [
        ['content_type' => 'lesson', 'content_id' => $lesson1],
    ], $adminId > 0 ? $adminId : null);
    $perms = sca_load_permissions($conn, $uid);
    t('T_no_full_lms_reinject', empty($perms['full_lms']), 'commerce_full_grant_rows=' . $hasFullGrant . ' sca_full=' . (!empty($perms['full_lms']) ? '1' : '0'));

    t('T_subject_page_open', sca_subject_has_any_access($conn, $uid, $subjectId), 'subject entry with topic1');
    t('T_topic1_open', sca_has_access($conn, $uid, 'lesson', $lesson1), "lesson {$lesson1}");
    t('T_topic2_blocked', !sca_has_access($conn, $uid, 'lesson', $lesson2), "lesson {$lesson2}");
    if ($lesson3 > 0) {
        t('T_topic3_blocked', !sca_has_access($conn, $uid, 'lesson', $lesson3), "lesson {$lesson3}");
    } else {
        t('T_topic3_blocked', true, 'SKIPPED (only 2 lessons)');
    }
    if ($otherSubjectId > 0) {
        t('T_other_subject_blocked', !sca_subject_has_any_access($conn, $uid, $otherSubjectId), "subject {$otherSubjectId}");
    } else {
        t('T_other_subject_blocked', true, 'SKIPPED (no other subject)');
    }
    if ($handout2 > 0) {
        t('T_topic2_handout_blocked', !sca_has_access($conn, $uid, 'handout', $handout2), "handout {$handout2}");
    } else {
        t('T_topic2_handout_blocked', true, 'SKIPPED (no handout on topic2)');
    }
    if ($video2 > 0) {
        t('T_topic2_video_blocked', !sca_has_access($conn, $uid, 'video', $video2), "video {$video2}");
    } else {
        t('T_topic2_video_blocked', true, 'SKIPPED (no video on topic2)');
    }
    t('T_fail_closed_bad_id', !sca_has_access($conn, $uid, 'lesson', 0), 'lesson id 0');

    // CASE A: Full subject access
    sca_save_user_permissions_preserving_commerce($conn, $uid, [
        ['content_type' => 'subject', 'content_id' => $subjectId],
    ], $adminId > 0 ? $adminId : null);
    t('T_full_subject_topic1', sca_has_access($conn, $uid, 'lesson', $lesson1), 'full subject');
    t('T_full_subject_topic2', sca_has_access($conn, $uid, 'lesson', $lesson2), 'full subject');
    if ($otherSubjectId > 0) {
        t('T_full_subject_other_locked', !sca_subject_has_any_access($conn, $uid, $otherSubjectId), 'other still locked');
    } else {
        t('T_full_subject_other_locked', true, 'SKIPPED');
    }

    // CASE C: Topics 1 + 3
    $multi = [['content_type' => 'lesson', 'content_id' => $lesson1]];
    if ($lesson3 > 0) {
        $multi[] = ['content_type' => 'lesson', 'content_id' => $lesson3];
    }
    sca_save_user_permissions_preserving_commerce($conn, $uid, $multi, $adminId > 0 ? $adminId : null);
    t('T_multi_topic1', sca_has_access($conn, $uid, 'lesson', $lesson1), '');
    t('T_multi_topic2_blocked', !sca_has_access($conn, $uid, 'lesson', $lesson2), '');
    if ($lesson3 > 0) {
        t('T_multi_topic3', sca_has_access($conn, $uid, 'lesson', $lesson3), '');
    } else {
        t('T_multi_topic3', true, 'SKIPPED');
    }

    // CASE: Full LMS still works
    sca_save_user_permissions_preserving_commerce($conn, $uid, [
        ['content_type' => 'full_lms', 'content_id' => 0],
    ], $adminId > 0 ? $adminId : null);
    t('T_full_lms_topic2', sca_has_access($conn, $uid, 'lesson', $lesson2), '');
    if ($otherSubjectId > 0) {
        t('T_full_lms_other', sca_subject_has_any_access($conn, $uid, $otherSubjectId), '');
    } else {
        t('T_full_lms_other', true, 'SKIPPED');
    }

    // Existing subject compatibility: subject row alone = full subject (no lesson rows needed)
    sca_save_user_permissions($conn, $uid, [
        ['content_type' => 'subject', 'content_id' => $subjectId],
    ], $adminId > 0 ? $adminId : null);
    t('T_legacy_subject_row_full', sca_has_access($conn, $uid, 'lesson', $lesson2)
        && sca_has_access($conn, $uid, 'lesson', $lesson1), 'subject row without lesson rows');

    // Isolation: different user should not inherit (spot-check load)
    $uidB = 0;
    $br = mysqli_query($conn, "SELECT user_id FROM users WHERE role='student' AND user_id<>{$uid} AND status='approved' ORDER BY user_id DESC LIMIT 1");
    if ($br && ($brow = mysqli_fetch_assoc($br))) {
        $uidB = (int) $brow['user_id'];
    }
    if ($uidB > 0) {
        $beforeB = [];
        $srB = mysqli_query($conn, "SELECT content_type, content_id FROM student_content_permissions WHERE user_id={$uidB}");
        while ($srB && ($row = mysqli_fetch_assoc($srB))) {
            $beforeB[] = $row;
        }
        sca_save_user_permissions($conn, $uid, [['content_type' => 'lesson', 'content_id' => $lesson1]], $adminId > 0 ? $adminId : null);
        sca_save_user_permissions($conn, $uidB, [['content_type' => 'lesson', 'content_id' => $lesson2]], $adminId > 0 ? $adminId : null);
        t('T_iso_A_topic1', sca_has_access($conn, $uid, 'lesson', $lesson1) && !sca_has_access($conn, $uid, 'lesson', $lesson2), "A={$uid}");
        t('T_iso_B_topic2', sca_has_access($conn, $uidB, 'lesson', $lesson2) && !sca_has_access($conn, $uidB, 'lesson', $lesson1), "B={$uidB}");
        restore_sca($conn, $uidB, $beforeB, $adminId > 0 ? $adminId : null);
    } else {
        t('T_iso_A_topic1', true, 'SKIPPED (no second student)');
        t('T_iso_B_topic2', true, 'SKIPPED');
    }
} catch (Throwable $e) {
    restore_sca($conn, $uid, $beforeSca, $adminId > 0 ? $adminId : null);
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n");
    exit(1);
}

restore_sca($conn, $uid, $beforeSca, $adminId > 0 ? $adminId : null);
echo "\nRestored original SCA for user {$uid}.\n";

$fail = 0;
foreach ($results as $r) {
    if (!$r['ok']) {
        $fail++;
    }
}
echo 'Summary: ' . (count($results) - $fail) . '/' . count($results) . " passed\n";
exit($fail > 0 ? 1 : 0);
