<?php
declare(strict_types=1);

/**
 * Section targeting regression cases (create temp exam/users, assert, cleanup).
 */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/examination/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/examination/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';
require_once dirname(__DIR__) . '/examination/includes/college_upload_helpers.php';
require_once dirname(__DIR__) . '/examination/includes/college_sections.php';

header('Content-Type: text/plain; charset=utf-8');

$pass = 0;
$fail = 0;
function assert_true(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        echo "PASS  {$label}\n";
        $pass++;
    } else {
        echo "FAIL  {$label}\n";
        $fail++;
    }
}

$suffix = 'QA-SEC-' . date('YmdHis');
$profId = 3;

// Ensure sections exist
foreach (['Section A', 'Section B', 'Section C'] as $name) {
    $row = college_sections_find_by_name($conn, $name);
    if (!$row) {
        college_sections_create($conn, $name, $profId);
    } elseif (($row['status'] ?? '') !== 'active') {
        college_sections_update($conn, (int)$row['section_id'], $name, 'active', $profId);
    }
}

$mkUser = static function (mysqli $conn, string $name, string $section, string $suffix): int {
    $email = strtolower(preg_replace('/\s+/', '.', $name)) . '.' . $suffix . '@example.test';
    $st = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, school, email, password, role, status, review_type, section, college_examination_access)
         VALUES (?, 'QA School', ?, ?, 'college_student', 'approved', 'undergrad', ?, 'active')"
    );
    $hash = password_hash('Test1234!', PASSWORD_DEFAULT);
    mysqli_stmt_bind_param($st, 'ssss', $name, $email, $hash, $section);
    mysqli_stmt_execute($st);
    $id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    return $id;
};

$userA = $mkUser($conn, 'Sec Target A', 'Section A', $suffix);
$userB = $mkUser($conn, 'Sec Target B', 'Section B', $suffix);
$userC = $mkUser($conn, 'Sec Target C', 'Section C', $suffix);

// CASE 1+2: exam Section A only
$ins = mysqli_prepare(
    $conn,
    "INSERT INTO college_exams (title, description, time_limit_seconds, is_published, examinee_scope, assignment_mode, created_by)
     VALUES (?, '', 3600, 1, 'college_student', 'sections', ?)"
);
$title = $suffix . ' Exam A only';
mysqli_stmt_bind_param($ins, 'si', $title, $profId);
mysqli_stmt_execute($ins);
$examId = (int)mysqli_insert_id($conn);
mysqli_stmt_close($ins);
$sec = 'Section A';
$st = mysqli_prepare($conn, 'INSERT INTO college_exam_sections (exam_id, section_value) VALUES (?,?)');
mysqli_stmt_bind_param($st, 'is', $examId, $sec);
mysqli_stmt_execute($st);
mysqli_stmt_close($st);

$exam = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . $examId));
assert_true(examination_user_is_assigned($conn, $userA, $exam, 'regular'), 'CASE1 exam A visible to Section A');
assert_true(!examination_user_is_assigned($conn, $userB, $exam, 'regular'), 'CASE2 exam A hidden from Section B');
assert_true(!examination_user_can_start_exam($conn, $userB, $exam, 'regular', date('Y-m-d H:i:s')), 'CASE5 direct start denied for Section B');

$listA = college_exams_load_assigned_published_exams_for_user($conn, $userA);
$listB = college_exams_load_assigned_published_exams_for_user($conn, $userB);
$idsA = array_map(static fn($r) => (int)($r['exam_id'] ?? 0), $listA);
$idsB = array_map(static fn($r) => (int)($r['exam_id'] ?? 0), $listB);
assert_true(in_array($examId, $idsA, true), 'CASE1 list includes exam for A');
assert_true(!in_array($examId, $idsB, true), 'CASE2 list excludes exam for B');

// CASE 3: upload task A+B
$tTitle = $suffix . ' Upload AB';
$dead = date('Y-m-d H:i:s', strtotime('+2 days'));
$ins = mysqli_prepare(
    $conn,
    "INSERT INTO college_upload_tasks (title, instructions, deadline, max_file_size, allowed_extensions, is_open, examinee_scope, assignment_mode, resubmission_policy, created_by)
     VALUES (?, '', ?, 10485760, 'pdf', 1, 'college_student', 'sections', 'disabled', ?)"
);
mysqli_stmt_bind_param($ins, 'ssi', $tTitle, $dead, $profId);
mysqli_stmt_execute($ins);
$taskId = (int)mysqli_insert_id($conn);
mysqli_stmt_close($ins);
college_upload_save_task_sections($conn, $taskId, ['Section A', 'Section B']);
$task = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM college_upload_tasks WHERE task_id=' . $taskId));
assert_true(college_upload_user_matches_task($conn, $userA, $task), 'CASE3 upload visible to A');
assert_true(college_upload_user_matches_task($conn, $userB, $task), 'CASE3 upload visible to B');
assert_true(!college_upload_user_matches_task($conn, $userC, $task), 'CASE3 upload hidden from C');
assert_true(college_upload_fetch_task_for_student($conn, $taskId, $userC) === null, 'CASE5 upload URL denied for C');

// CASE 4: no section restriction
$ins = mysqli_prepare(
    $conn,
    "INSERT INTO college_exams (title, description, time_limit_seconds, is_published, examinee_scope, assignment_mode, created_by)
     VALUES (?, '', 3600, 1, 'college_student', 'all', ?)"
);
$titleAll = $suffix . ' Exam ALL';
mysqli_stmt_bind_param($ins, 'si', $titleAll, $profId);
mysqli_stmt_execute($ins);
$examAll = (int)mysqli_insert_id($conn);
mysqli_stmt_close($ins);
$examAllRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . $examAll));
assert_true(examination_user_is_assigned($conn, $userA, $examAllRow, 'regular'), 'CASE4 all-mode visible to A');
assert_true(examination_user_is_assigned($conn, $userB, $examAllRow, 'regular'), 'CASE4 all-mode visible to B');
assert_true(examination_user_is_assigned($conn, $userC, $examAllRow, 'regular'), 'CASE4 all-mode visible to C');

// Empty sections map must not open
$ins = mysqli_prepare(
    $conn,
    "INSERT INTO college_exams (title, description, time_limit_seconds, is_published, examinee_scope, assignment_mode, created_by)
     VALUES (?, '', 3600, 1, 'college_student', 'sections', ?)"
);
$titleEmpty = $suffix . ' Exam empty secs';
mysqli_stmt_bind_param($ins, 'si', $titleEmpty, $profId);
mysqli_stmt_execute($ins);
$examEmpty = (int)mysqli_insert_id($conn);
mysqli_stmt_close($ins);
$examEmptyRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . $examEmpty));
assert_true(!examination_user_is_assigned($conn, $userA, $examEmptyRow, 'regular'), 'Empty section map denies access');

// Cleanup
@mysqli_query($conn, 'DELETE FROM college_exam_sections WHERE exam_id IN (' . implode(',', [$examId, $examAll, $examEmpty]) . ')');
@mysqli_query($conn, 'DELETE FROM college_exams WHERE exam_id IN (' . implode(',', [$examId, $examAll, $examEmpty]) . ')');
@mysqli_query($conn, 'DELETE FROM college_upload_task_sections WHERE task_id=' . $taskId);
@mysqli_query($conn, 'DELETE FROM college_upload_tasks WHERE task_id=' . $taskId);
@mysqli_query($conn, 'DELETE FROM users WHERE user_id IN (' . implode(',', [$userA, $userB, $userC]) . ')');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
