<?php
/**
 * Teardown LOADTEST-marked data only.
 *
 * Requires the same safety flags as other harness scripts.
 * Never deletes a user/exam unless email/name/title carries the LOADTEST marker.
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

[$conn, $dbName] = loadtest_connect();

$confirmDelete = strtoupper(trim((string)loadtest_env('LOADTEST_TEARDOWN_CONFIRM', '')));
if ($confirmDelete !== 'YES') {
    loadtest_fail('Set LOADTEST_TEARDOWN_CONFIRM=YES to delete LOADTEST data (extra safety gate).');
}

$deleted = [
    'answers' => 0,
    'attempts' => 0,
    'exam_users' => 0,
    'questions' => 0,
    'exams' => 0,
    'users' => 0,
];

// 1) Find LOADTEST exams by title + professor email marker
$examIds = [];
$eq = mysqli_query(
    $conn,
    "SELECT e.exam_id, e.title, e.created_by, u.email, u.full_name
     FROM college_exams e
     INNER JOIN users u ON u.user_id = e.created_by
     WHERE e.title LIKE '[LOADTEST]%'"
);
while ($eq && ($row = mysqli_fetch_assoc($eq))) {
    $title = (string)$row['title'];
    $email = (string)$row['email'];
    $name = (string)$row['full_name'];
    if (!loadtest_is_loadtest_exam_title($title)) {
        continue;
    }
    if (!loadtest_is_loadtest_email($email) || !loadtest_is_loadtest_name($name)) {
        fwrite(STDERR, "SKIP exam_id={$row['exam_id']}: creator is not a LOADTEST user\n");
        continue;
    }
    $examIds[] = (int)$row['exam_id'];
}
if ($eq) {
    mysqli_free_result($eq);
}

foreach ($examIds as $examId) {
    // Answers for attempts on this exam belonging to loadtest students
    $sqlAns = "DELETE ans FROM college_exam_answers ans
        INNER JOIN college_exam_attempts a ON a.attempt_id = ans.attempt_id
        INNER JOIN users u ON u.user_id = a.user_id
        WHERE a.exam_id = " . (int)$examId . "
          AND u.email LIKE 'loadtest+%@ereview.invalid'
          AND u.full_name LIKE '[LOADTEST]%'";
    if (mysqli_query($conn, $sqlAns)) {
        $deleted['answers'] += mysqli_affected_rows($conn);
    }

    $sqlAtt = "DELETE a FROM college_exam_attempts a
        INNER JOIN users u ON u.user_id = a.user_id
        WHERE a.exam_id = " . (int)$examId . "
          AND u.email LIKE 'loadtest+%@ereview.invalid'
          AND u.full_name LIKE '[LOADTEST]%'";
    if (mysqli_query($conn, $sqlAtt)) {
        $deleted['attempts'] += mysqli_affected_rows($conn);
    }

    if (loadtest_table_exists($conn, 'college_exam_users')) {
        $sqlEu = 'DELETE FROM college_exam_users WHERE exam_id=' . (int)$examId;
        // Only if exam itself is loadtest (already gated)
        if (mysqli_query($conn, $sqlEu)) {
            $deleted['exam_users'] += mysqli_affected_rows($conn);
        }
    }
    if (loadtest_table_exists($conn, 'college_exam_sections')) {
        mysqli_query($conn, 'DELETE FROM college_exam_sections WHERE exam_id=' . (int)$examId);
    }
    if (loadtest_table_exists($conn, 'college_exam_attempt_events')) {
        mysqli_query(
            $conn,
            'DELETE ev FROM college_exam_attempt_events ev
             INNER JOIN college_exam_attempts a ON a.attempt_id = ev.attempt_id
             WHERE a.exam_id=' . (int)$examId
        );
        // leftover events if attempts already deleted
        mysqli_query($conn, 'DELETE FROM college_exam_attempt_events WHERE exam_id=' . (int)$examId);
    }

    if (mysqli_query($conn, 'DELETE FROM college_exam_questions WHERE exam_id=' . (int)$examId)) {
        $deleted['questions'] += mysqli_affected_rows($conn);
    }
    $delE = mysqli_prepare($conn, "DELETE FROM college_exams WHERE exam_id=? AND title LIKE '[LOADTEST]%'");
    mysqli_stmt_bind_param($delE, 'i', $examId);
    mysqli_stmt_execute($delE);
    $deleted['exams'] += mysqli_stmt_affected_rows($delE);
    mysqli_stmt_close($delE);
}

// 2) Delete LOADTEST users (students + professor) with marker checks
$uq = mysqli_query(
    $conn,
    "SELECT user_id, email, full_name, role FROM users
     WHERE email LIKE 'loadtest+%@ereview.invalid' AND full_name LIKE '[LOADTEST]%'"
);
$userIds = [];
while ($uq && ($row = mysqli_fetch_assoc($uq))) {
    if (!loadtest_is_loadtest_email((string)$row['email']) || !loadtest_is_loadtest_name((string)$row['full_name'])) {
        continue;
    }
    $userIds[] = (int)$row['user_id'];
}
if ($uq) {
    mysqli_free_result($uq);
}

foreach ($userIds as $uid) {
    // Remove leftover attempts/answers for safety
    mysqli_query(
        $conn,
        'DELETE ans FROM college_exam_answers ans
         INNER JOIN college_exam_attempts a ON a.attempt_id=ans.attempt_id
         WHERE a.user_id=' . (int)$uid
    );
    mysqli_query($conn, 'DELETE FROM college_exam_attempts WHERE user_id=' . (int)$uid);
    if (loadtest_table_exists($conn, 'college_exam_users')) {
        mysqli_query($conn, 'DELETE FROM college_exam_users WHERE user_id=' . (int)$uid);
    }
    $delU = mysqli_prepare(
        $conn,
        "DELETE FROM users WHERE user_id=? AND email LIKE 'loadtest+%@ereview.invalid' AND full_name LIKE '[LOADTEST]%'"
    );
    mysqli_stmt_bind_param($delU, 'i', $uid);
    mysqli_stmt_execute($delU);
    $deleted['users'] += mysqli_stmt_affected_rows($delU);
    mysqli_stmt_close($delU);
}

$report = [
    'db' => $dbName,
    'deleted' => $deleted,
    'exam_ids' => $examIds,
    'user_ids' => $userIds,
    'teardown_at' => date('c'),
];
$runId = loadtest_env('LOADTEST_RUN_ID', '') ?: ('teardown_' . date('Ymd_His'));
loadtest_write_json(loadtest_artifact_path(loadtest_run_id($runId), 'teardown.json'), $report);

loadtest_ok('Teardown complete on DB=' . $dbName);
loadtest_ok(json_encode($deleted, JSON_UNESCAPED_SLASHES));
mysqli_close($conn);
