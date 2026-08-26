<?php
/** READ-ONLY: list test accounts for QA (no modifications) */
require dirname(__DIR__) . '/db.php';
$res = mysqli_query($conn, "SELECT user_id, email, role, status, full_name FROM users WHERE role IN ('professor_admin','college_student','student') ORDER BY role, user_id LIMIT 30");
while ($row = mysqli_fetch_assoc($res)) {
    echo implode("\t", [$row['role'], $row['user_id'], $row['status'], $row['email'], $row['full_name']]) . PHP_EOL;
}
$res2 = mysqli_query($conn, "SELECT exam_id, title, is_published, created_by FROM college_exams ORDER BY exam_id DESC LIMIT 5");
echo PHP_EOL . 'EXAMS:' . PHP_EOL;
while ($row = mysqli_fetch_assoc($res2)) {
    echo implode("\t", [$row['exam_id'], $row['is_published'], $row['title']]) . PHP_EOL;
}
