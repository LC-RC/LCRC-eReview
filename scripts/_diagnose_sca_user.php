<?php
/**
 * Diagnose live SCA rows for a student (CLI-safe).
 * Usage: C:\xampp\php\php.exe scripts/_diagnose_sca_user.php [user_id]
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/student_content_access.php';

$uid = isset($argv[1]) ? (int) $argv[1] : 0;
if ($uid <= 0) {
    $r = mysqli_query($conn, "SELECT user_id, full_name, email FROM users WHERE role='student' AND status='approved' ORDER BY updated_at DESC LIMIT 5");
    echo "Recent approved students (pass user_id as arg):\n";
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        echo "  {$row['user_id']}  {$row['full_name']}  {$row['email']}\n";
    }
    exit(0);
}

$u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, full_name, email, status, access_end FROM users WHERE user_id={$uid}"));
echo "USER: " . json_encode($u) . "\n\n";

echo "SCA rows:\n";
$sr = mysqli_query($conn, "SELECT content_type, content_id, access_level, granted_at FROM student_content_permissions WHERE user_id={$uid} ORDER BY content_type, content_id");
$n = 0;
while ($sr && ($row = mysqli_fetch_assoc($sr))) {
    $n++;
    $label = '';
    $cid = (int) $row['content_id'];
    if ($row['content_type'] === 'subject') {
        $label = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT subject_name FROM subjects WHERE subject_id={$cid}"))['subject_name'] ?? '');
    } elseif ($row['content_type'] === 'lesson') {
        $l = mysqli_fetch_assoc(mysqli_query($conn, "SELECT title, subject_id FROM lessons WHERE lesson_id={$cid}"));
        $label = ($l['title'] ?? '') . ' (subject_id=' . ($l['subject_id'] ?? '?') . ')';
    }
    echo "  {$row['content_type']}:{$cid}  {$label}\n";
}
if ($n === 0) {
    echo "  (none)\n";
}

echo "\nCommerce grants (active):\n";
$gr = mysqli_query($conn, "SELECT grant_id, source, content_type, content_id, ends_at, status FROM access_grants WHERE user_id={$uid} AND status='active' AND ends_at>NOW() ORDER BY grant_id");
while ($gr && ($row = mysqli_fetch_assoc($gr))) {
    echo "  #{$row['grant_id']} {$row['source']} {$row['content_type']}:{$row['content_id']} ends={$row['ends_at']}\n";
}

$perms = sca_load_permissions($conn, $uid);
echo "\nsca_load_permissions full_lms=" . (!empty($perms['full_lms']) ? 'YES' : 'NO') . "\n";
echo "map keys: " . json_encode(array_map('array_keys', $perms['map'])) . "\n";

// Sample lessons for each subject that appears in SCA
$subjects = [];
$ls = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects ORDER BY subject_id");
while ($ls && ($s = mysqli_fetch_assoc($ls))) {
    $sid = (int) $s['subject_id'];
    $any = sca_subject_has_any_access($conn, $uid, $sid);
    echo "\nSubject {$sid} {$s['subject_name']}: page=" . ($any ? 'OPEN' : 'LOCKED') . "\n";
    if (!$any) {
        continue;
    }
    $lr = mysqli_query($conn, "SELECT lesson_id, title FROM lessons WHERE subject_id={$sid} ORDER BY lesson_id");
    while ($lr && ($l = mysqli_fetch_assoc($lr))) {
        $lid = (int) $l['lesson_id'];
        $ok = sca_has_access($conn, $uid, 'lesson', $lid);
        echo "  lesson {$lid} {$l['title']}: " . ($ok ? 'OPEN' : 'LOCKED') . "\n";
    }
}
