<?php
/**
 * Seed disposable LOADTEST users, professor, exam, questions, and assignments.
 *
 * Usage:
 *   set EREVIEW_LOADTEST=1
 *   set EREVIEW_LOADTEST_CONFIRM=YES
 *   set LOADTEST_DB_NAME=ereview_loadtest
 *   php 01_seed.php
 *
 * Env:
 *   LOADTEST_N=5
 *   LOADTEST_QUESTION_COUNT=50
 *   LOADTEST_RUN_ID=run_...
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';
require_once __DIR__ . '/expected_answers.php';

[$conn, $dbName] = loadtest_connect();

$n = max(1, (int)loadtest_env('LOADTEST_N', '5'));
$qCount = max(10, min(80, (int)loadtest_env('LOADTEST_QUESTION_COUNT', '50')));
$runId = loadtest_run_id();

foreach (['users', 'college_exams', 'college_exam_questions', 'college_exam_attempts', 'college_exam_answers', 'college_exam_users'] as $t) {
    if (!loadtest_table_exists($conn, $t)) {
        loadtest_fail("Required table missing in {$dbName}: {$t}. Import examination schema into the load-test database first.");
    }
}

// Ensure college schema helpers can run if needed (no production touch — we're on loadtest DB).
$schema = loadtest_project_root() . '/examination/includes/college_schema.php';
if (is_file($schema)) {
    $GLOBALS['conn'] = $conn;
    require_once $schema;
}

$passwordHash = password_hash('LoadTest!Pass123', PASSWORD_DEFAULT);
$hasCea = loadtest_column_exists($conn, 'users', 'college_examination_access');
$hasSection = loadtest_column_exists($conn, 'users', 'section');
$hasReviewType = loadtest_column_exists($conn, 'users', 'review_type');
$hasSchool = loadtest_column_exists($conn, 'users', 'school');
$hasCreatedAt = loadtest_column_exists($conn, 'users', 'created_at');

/**
 * Insert or refresh a loadtest-marked user. Never updates non-loadtest rows.
 */
function loadtest_upsert_user(
    mysqli $conn,
    string $email,
    string $fullName,
    string $role,
    string $passwordHash,
    bool $hasCea,
    bool $hasSection,
    bool $hasReviewType,
    bool $hasSchool,
    bool $hasCreatedAt
): int {
    if (!loadtest_is_loadtest_email($email) || !loadtest_is_loadtest_name($fullName)) {
        loadtest_fail('Refusing to upsert non-LOADTEST identity: ' . $email);
    }
    $stmt = mysqli_prepare($conn, 'SELECT user_id, full_name, email FROM users WHERE email=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($existing) {
        if (!loadtest_is_loadtest_email((string)$existing['email']) || !loadtest_is_loadtest_name((string)$existing['full_name'])) {
            loadtest_fail('Email collision with non-LOADTEST user: ' . $email);
        }
        $uid = (int)$existing['user_id'];
        $sets = ["full_name=?", "password=?", "role=?", "status='approved'"];
        $types = 'sss';
        $params = [$fullName, $passwordHash, $role];
        if ($hasCea) {
            $sets[] = "college_examination_access='active'";
        }
        if ($hasSection) {
            $sets[] = 'section=?';
            $types .= 's';
            $params[] = LOADTEST_SECTION;
        }
        if ($hasReviewType) {
            $sets[] = "review_type='college'";
        }
        $sql = 'UPDATE users SET ' . implode(',', $sets) . ' WHERE user_id=? AND email=?';
        $types .= 'is';
        $params[] = $uid;
        $params[] = $email;
        $upd = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($upd, $types, ...$params);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        return $uid;
    }

    $cols = ['full_name', 'email', 'password', 'role', 'status'];
    $vals = ['?', '?', '?', '?', "'approved'"];
    $types = 'ssss';
    $params = [$fullName, $email, $passwordHash, $role];
    if ($hasCea) {
        $cols[] = 'college_examination_access';
        $vals[] = "'active'";
    }
    if ($hasSection) {
        $cols[] = 'section';
        $vals[] = '?';
        $types .= 's';
        $params[] = LOADTEST_SECTION;
    }
    if ($hasReviewType) {
        $cols[] = 'review_type';
        $vals[] = "'college'";
    }
    if ($hasSchool) {
        $cols[] = 'school';
        $vals[] = "'LOADTEST School'";
    }
    if ($hasCreatedAt) {
        $cols[] = 'created_at';
        $vals[] = 'NOW()';
    }
    $sql = 'INSERT INTO users (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
    $ins = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($ins, $types, ...$params);
    }
    if (!mysqli_stmt_execute($ins)) {
        loadtest_fail('User insert failed for ' . $email . ': ' . mysqli_error($conn));
    }
    $uid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    if ($uid <= 0) {
        loadtest_fail('User insert produced no id for ' . $email);
    }

    return $uid;
}

$profId = loadtest_upsert_user(
    $conn,
    LOADTEST_PROF_EMAIL,
    LOADTEST_PROF_NAME,
    'professor_admin',
    $passwordHash,
    $hasCea,
    $hasSection,
    $hasReviewType,
    $hasSchool,
    $hasCreatedAt
);
loadtest_ok("Professor user_id={$profId}");

$students = [];
for ($i = 1; $i <= $n; $i++) {
    $num = str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    $email = 'loadtest+' . $num . '@' . LOADTEST_EMAIL_DOMAIN;
    $name = LOADTEST_NAME_PREFIX . ' Student ' . $num;
    $uid = loadtest_upsert_user(
        $conn,
        $email,
        $name,
        'college_student',
        $passwordHash,
        $hasCea,
        $hasSection,
        $hasReviewType,
        $hasSchool,
        $hasCreatedAt
    );
    $students[] = [
        'user_id' => $uid,
        'email' => $email,
        'full_name' => $name,
        'index' => $i,
    ];
}
loadtest_ok('Seeded ' . count($students) . ' students');

// Reuse existing LOADTEST exam for this professor if present; else create.
$examId = 0;
$find = mysqli_prepare($conn, 'SELECT exam_id, title FROM college_exams WHERE created_by=? AND title=? LIMIT 1');
$title = LOADTEST_EXAM_TITLE;
mysqli_stmt_bind_param($find, 'is', $profId, $title);
mysqli_stmt_execute($find);
$ex = mysqli_fetch_assoc(mysqli_stmt_get_result($find));
mysqli_stmt_close($find);
if ($ex && loadtest_is_loadtest_exam_title((string)$ex['title'])) {
    $examId = (int)$ex['exam_id'];
    loadtest_ok("Reusing exam_id={$examId}");
} else {
    $availableFrom = date('Y-m-d H:i:s', time() - 3600);
    $deadline = date('Y-m-d H:i:s', time() + 86400 * 7);
    $timeLimit = max(120, (int)loadtest_env('LOADTEST_TIME_LIMIT_SECONDS', '180'));
    $hasAssign = loadtest_column_exists($conn, 'college_exams', 'assignment_mode');
    $hasScope = loadtest_column_exists($conn, 'college_exams', 'examinee_scope');
    $hasShuffleQ = loadtest_column_exists($conn, 'college_exams', 'shuffle_questions');
    $hasShuffleC = loadtest_column_exists($conn, 'college_exams', 'shuffle_choices');

    $cols = ['title', 'description', 'time_limit_seconds', 'available_from', 'deadline', 'is_published', 'created_by'];
    $ph = ['?', '?', '?', '?', '?', '1', '?'];
    $types = 'ssissi';
    $desc = 'Disposable load-test exam. Safe to delete.';
    $params = [$title, $desc, $timeLimit, $availableFrom, $deadline, $profId];
    if ($hasScope) {
        $cols[] = 'examinee_scope';
        $ph[] = "'college_student'";
    }
    if ($hasAssign) {
        $cols[] = 'assignment_mode';
        $ph[] = "'users'";
    }
    if ($hasShuffleQ) {
        $cols[] = 'shuffle_questions';
        $ph[] = '0';
    }
    if ($hasShuffleC) {
        $cols[] = 'shuffle_choices';
        $ph[] = '0';
    }
    $sql = 'INSERT INTO college_exams (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
    $ins = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($ins, $types, ...$params);
    if (!mysqli_stmt_execute($ins)) {
        loadtest_fail('Exam insert failed: ' . mysqli_error($conn));
    }
    $examId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    loadtest_ok("Created exam_id={$examId}");
}

// Refresh publish/window/assignment
$timeLimit = max(120, (int)loadtest_env('LOADTEST_TIME_LIMIT_SECONDS', '180'));
$availableFrom = date('Y-m-d H:i:s', time() - 3600);
$deadline = date('Y-m-d H:i:s', time() + 86400 * 7);
$updSql = "UPDATE college_exams SET is_published=1, time_limit_seconds=?, available_from=?, deadline=?";
if (loadtest_column_exists($conn, 'college_exams', 'assignment_mode')) {
    $updSql .= ", assignment_mode='users'";
}
if (loadtest_column_exists($conn, 'college_exams', 'examinee_scope')) {
    $updSql .= ", examinee_scope='college_student'";
}
$updSql .= ' WHERE exam_id=? AND created_by=?';
$upd = mysqli_prepare($conn, $updSql);
mysqli_stmt_bind_param($upd, 'issii', $timeLimit, $availableFrom, $deadline, $examId, $profId);
mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

// Questions: ensure count
$cntR = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . (int)$examId);
$haveQ = (int)(mysqli_fetch_assoc($cntR)['c'] ?? 0);
if ($cntR) {
    mysqli_free_result($cntR);
}
if ($haveQ < $qCount) {
    $insQ = mysqli_prepare(
        $conn,
        'INSERT INTO college_exam_questions (exam_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
         VALUES (?, ?, \'mcq\', \'Alpha\', \'Bravo\', \'Charlie\', \'Delta\', ?, ?)'
    );
    for ($i = $haveQ + 1; $i <= $qCount; $i++) {
        $text = '[LOADTEST] Question ' . $i . ' — select the deterministic expected letter.';
        $correct = ['A', 'B', 'C', 'D'][($i - 1) % 4];
        mysqli_stmt_bind_param($insQ, 'issi', $examId, $text, $correct, $i);
        mysqli_stmt_execute($insQ);
    }
    mysqli_stmt_close($insQ);
    loadtest_ok('Inserted questions up to ' . $qCount);
} else {
    loadtest_ok("Exam already has {$haveQ} questions (requested {$qCount})");
}

$qids = [];
$qr = mysqli_query($conn, 'SELECT question_id FROM college_exam_questions WHERE exam_id=' . (int)$examId . ' ORDER BY sort_order ASC, question_id ASC');
while ($qr && ($row = mysqli_fetch_assoc($qr))) {
    $qids[] = (int)$row['question_id'];
}
if ($qr) {
    mysqli_free_result($qr);
}
if ($qids === []) {
    loadtest_fail('No questions on loadtest exam');
}

// Assign users exclusively (assignment_mode=users)
mysqli_query($conn, 'DELETE ceu FROM college_exam_users ceu INNER JOIN users u ON u.user_id=ceu.user_id WHERE ceu.exam_id=' . (int)$examId . " AND u.email LIKE 'loadtest+%@" . LOADTEST_EMAIL_DOMAIN . "'");
$asg = mysqli_prepare($conn, 'INSERT IGNORE INTO college_exam_users (exam_id, user_id) VALUES (?, ?)');
foreach ($students as $s) {
    $uid = (int)$s['user_id'];
    mysqli_stmt_bind_param($asg, 'ii', $examId, $uid);
    mysqli_stmt_execute($asg);
}
mysqli_stmt_close($asg);

$secret = loadtest_secret();
$expectedByUser = [];
foreach ($students as $s) {
    $uid = (int)$s['user_id'];
    $expectedByUser[(string)$uid] = loadtest_expected_answers_for_user($uid, $qids, $secret);
}

$meta = [
    'run_id' => $runId,
    'db' => $dbName,
    'n' => $n,
    'exam_id' => $examId,
    'exam_title' => LOADTEST_EXAM_TITLE,
    'professor_user_id' => $profId,
    'professor_email' => LOADTEST_PROF_EMAIL,
    'time_limit_seconds' => $timeLimit,
    'question_ids' => $qids,
    'question_count' => count($qids),
    'answer_secret' => $secret,
    'students' => $students,
    'created_at' => date('c'),
    'base_url' => loadtest_require_base_url(),
];

loadtest_write_json(loadtest_artifact_path($runId, 'seed.json'), $meta);
loadtest_write_json(loadtest_artifact_path($runId, 'expected_answers.json'), $expectedByUser);
file_put_contents(loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID', $runId . "\n");

loadtest_ok("Seed complete. run_id={$runId} exam_id={$examId} n={$n} questions=" . count($qids));
loadtest_ok('Artifacts: artifacts/' . $runId . '/seed.json + expected_answers.json');
mysqli_close($conn);
