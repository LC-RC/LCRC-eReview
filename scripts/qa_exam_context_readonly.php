<?php
/** READ-ONLY DB context for QA */
require dirname(__DIR__) . '/db.php';
$uid = 4;
echo "ATTEMPTS user $uid:\n";
$q = mysqli_query($conn, "SELECT attempt_id, exam_id, status, score FROM college_exam_attempts WHERE user_id=$uid");
while ($r = mysqli_fetch_assoc($q)) print_r($r);
echo "\nQUESTIONS exam 1:\n";
$q2 = mysqli_query($conn, "SELECT COUNT(*) c FROM college_exam_questions WHERE exam_id=1");
print_r(mysqli_fetch_assoc($q2));
echo "\nUPLOAD TASKS:\n";
$q3 = mysqli_query($conn, "SELECT task_id, title, is_open, deadline FROM college_upload_tasks ORDER BY task_id DESC LIMIT 5");
while ($r = mysqli_fetch_assoc($q3)) print_r($r);
echo "\nSUBMISSIONS user $uid:\n";
$q4 = mysqli_query($conn, "SELECT submission_id, task_id FROM college_submissions WHERE user_id=$uid LIMIT 5");
while ($r = mysqli_fetch_assoc($q4)) print_r($r);
