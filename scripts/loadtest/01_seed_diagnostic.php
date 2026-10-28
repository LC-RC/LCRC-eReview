<?php
/**
 * Seed disposable LOADTEST users + diagnostic batch/questions (isolated DB only).
 *
 * Env:
 *   LOADTEST_MODE=diagnostic (informational; this script is diagnostic-only)
 *   LOADTEST_N, LOADTEST_QUESTION_COUNT, LOADTEST_TIME_LIMIT_SECONDS, LOADTEST_BASE_URL
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';
require_once __DIR__ . '/expected_answers.php';

[$conn, $dbName] = loadtest_connect();
$GLOBALS['conn'] = $conn;

$n = max(1, (int)loadtest_env('LOADTEST_N', '5'));
$qCount = max(10, min(80, (int)loadtest_env('LOADTEST_QUESTION_COUNT', '50')));
$runId = loadtest_run_id();

foreach (['users', 'diagnostic_batches', 'diagnostic_questions', 'diagnostic_attempts', 'diagnostic_answers', 'diagnostic_subjects'] as $t) {
    if (!loadtest_table_exists($conn, $t)) {
        loadtest_fail("Required table missing in {$dbName}: {$t}. Import diagnostic schema into the load-test database first.");
    }
}

$schema = loadtest_project_root() . '/examination/includes/diagnostic_schema.php';
if (is_file($schema)) {
    // Gate is OFF by default — enable ensure only inside this CLI seed on the isolated DB.
    $GLOBALS['EREVIEW_ENSURE_SCHEMA'] = true;
    require_once $schema;
    unset($GLOBALS['EREVIEW_ENSURE_SCHEMA']);
}

$passwordHash = password_hash('LoadTest!Pass123', PASSWORD_DEFAULT);
$hasCea = loadtest_column_exists($conn, 'users', 'college_examination_access');
$hasSection = loadtest_column_exists($conn, 'users', 'section');
$hasReviewType = loadtest_column_exists($conn, 'users', 'review_type');
$hasSchool = loadtest_column_exists($conn, 'users', 'school');
$hasCreatedAt = loadtest_column_exists($conn, 'users', 'created_at');

/**
 * @see 01_seed.php — same LOADTEST-only upsert rules
 */
function loadtest_diag_upsert_user(
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
            $sets[] = "review_type='undergrad'";
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
        $vals[] = "'undergrad'";
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
    mysqli_stmt_bind_param($ins, $types, ...$params);
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

$profId = loadtest_diag_upsert_user(
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
    $uid = loadtest_diag_upsert_user(
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

// Ensure a subject exists
$subjectId = 0;
$sr = mysqli_query($conn, "SELECT subject_id FROM diagnostic_subjects WHERE subject_code='FAR' LIMIT 1");
$rowS = $sr ? mysqli_fetch_assoc($sr) : null;
if ($sr) {
    mysqli_free_result($sr);
}
if ($rowS) {
    $subjectId = (int)$rowS['subject_id'];
} else {
    mysqli_query($conn, "INSERT INTO diagnostic_subjects (subject_code, subject_name, sort_order, is_active) VALUES ('FAR', 'Financial Accounting and Reporting', 1, 1)");
    $subjectId = (int)mysqli_insert_id($conn);
}
if ($subjectId <= 0) {
    loadtest_fail('Could not resolve diagnostic subject_id');
}

$title = LOADTEST_DIAGNOSTIC_BATCH_TITLE;
$batchId = 0;
$find = mysqli_prepare($conn, 'SELECT batch_id, title FROM diagnostic_batches WHERE created_by=? AND title=? LIMIT 1');
mysqli_stmt_bind_param($find, 'is', $profId, $title);
mysqli_stmt_execute($find);
$bx = mysqli_fetch_assoc(mysqli_stmt_get_result($find));
mysqli_stmt_close($find);
if ($bx && loadtest_is_loadtest_batch_title((string)$bx['title'])) {
    $batchId = (int)$bx['batch_id'];
    loadtest_ok("Reusing batch_id={$batchId}");
} else {
    $availableFrom = date('Y-m-d H:i:s', time() - 3600);
    $deadline = date('Y-m-d H:i:s', time() + 86400 * 7);
    $timeLimit = max(120, (int)loadtest_env('LOADTEST_TIME_LIMIT_SECONDS', '180'));
    $cols = ['title', 'description', 'time_limit_seconds', 'available_from', 'deadline', 'is_published', 'created_by'];
    $ph = ['?', '?', '?', '?', '?', '1', '?'];
    $types = 'ssissi';
    $desc = 'Disposable diagnostic load-test batch. Safe to delete.';
    $params = [$title, $desc, $timeLimit, $availableFrom, $deadline, $profId];
    if (loadtest_column_exists($conn, 'diagnostic_batches', 'examinee_scope')) {
        $cols[] = 'examinee_scope';
        $ph[] = "'college_student'";
    }
    if (loadtest_column_exists($conn, 'diagnostic_batches', 'assignment_mode')) {
        $cols[] = 'assignment_mode';
        $ph[] = "'users'";
    }
    $sql = 'INSERT INTO diagnostic_batches (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
    $ins = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($ins, $types, ...$params);
    if (!mysqli_stmt_execute($ins)) {
        loadtest_fail('Batch insert failed: ' . mysqli_error($conn));
    }
    $batchId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    loadtest_ok("Created batch_id={$batchId}");
}

$timeLimit = max(120, (int)loadtest_env('LOADTEST_TIME_LIMIT_SECONDS', '180'));
$availableFrom = date('Y-m-d H:i:s', time() - 3600);
$deadline = date('Y-m-d H:i:s', time() + 86400 * 7);
$updSql = 'UPDATE diagnostic_batches SET is_published=1, time_limit_seconds=?, available_from=?, deadline=?';
if (loadtest_column_exists($conn, 'diagnostic_batches', 'assignment_mode')) {
    $updSql .= ", assignment_mode='users'";
}
if (loadtest_column_exists($conn, 'diagnostic_batches', 'examinee_scope')) {
    $updSql .= ", examinee_scope='college_student'";
}
$updSql .= ' WHERE batch_id=? AND created_by=?';
$upd = mysqli_prepare($conn, $updSql);
mysqli_stmt_bind_param($upd, 'issii', $timeLimit, $availableFrom, $deadline, $batchId, $profId);
mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

// Link subject
if (loadtest_table_exists($conn, 'diagnostic_batch_subjects')) {
    mysqli_query(
        $conn,
        'INSERT IGNORE INTO diagnostic_batch_subjects (batch_id, subject_id, sort_order, questions_required) VALUES (' .
        (int)$batchId . ', ' . (int)$subjectId . ', 1, ' . (int)$qCount . ')'
    );
    mysqli_query(
        $conn,
        'UPDATE diagnostic_batch_subjects SET questions_required=' . (int)$qCount .
        ' WHERE batch_id=' . (int)$batchId . ' AND subject_id=' . (int)$subjectId
    );
}

$cntR = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM diagnostic_questions WHERE batch_id=' . (int)$batchId);
$haveQ = (int)(mysqli_fetch_assoc($cntR)['c'] ?? 0);
if ($cntR) {
    mysqli_free_result($cntR);
}
if ($haveQ < $qCount) {
    $insQ = mysqli_prepare(
        $conn,
        'INSERT INTO diagnostic_questions (batch_id, subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
         VALUES (?, ?, ?, \'mcq\', \'Alpha\', \'Bravo\', \'Charlie\', \'Delta\', ?, ?)'
    );
    for ($i = $haveQ + 1; $i <= $qCount; $i++) {
        $text = '[LOADTEST] Diagnostic Q' . $i . ' — select the deterministic expected letter.';
        $correct = ['A', 'B', 'C', 'D'][($i - 1) % 4];
        mysqli_stmt_bind_param($insQ, 'iissi', $batchId, $subjectId, $text, $correct, $i);
        mysqli_stmt_execute($insQ);
    }
    mysqli_stmt_close($insQ);
    loadtest_ok('Inserted diagnostic questions up to ' . $qCount);
} else {
    loadtest_ok("Batch already has {$haveQ} questions (requested {$qCount})");
}

$qids = [];
$qr = mysqli_query($conn, 'SELECT question_id FROM diagnostic_questions WHERE batch_id=' . (int)$batchId . ' ORDER BY sort_order ASC, question_id ASC');
while ($qr && ($row = mysqli_fetch_assoc($qr))) {
    $qids[] = (int)$row['question_id'];
}
if ($qr) {
    mysqli_free_result($qr);
}
if ($qids === []) {
    loadtest_fail('No questions on loadtest diagnostic batch');
}

if (loadtest_table_exists($conn, 'diagnostic_batch_users')) {
    mysqli_query(
        $conn,
        'DELETE dbu FROM diagnostic_batch_users dbu INNER JOIN users u ON u.user_id=dbu.user_id WHERE dbu.batch_id=' .
        (int)$batchId . " AND u.email LIKE 'loadtest+%@ereview.invalid'"
    );
    $asg = mysqli_prepare($conn, 'INSERT IGNORE INTO diagnostic_batch_users (batch_id, user_id) VALUES (?, ?)');
    foreach ($students as $s) {
        $uid = (int)$s['user_id'];
        mysqli_stmt_bind_param($asg, 'ii', $batchId, $uid);
        mysqli_stmt_execute($asg);
    }
    mysqli_stmt_close($asg);
}

$secret = loadtest_secret();
$expectedByUser = [];
foreach ($students as $s) {
    $uid = (int)$s['user_id'];
    $expectedByUser[(string)$uid] = loadtest_expected_answers_for_user($uid, $qids, $secret);
}

$meta = [
    'run_id' => $runId,
    'mode' => 'diagnostic',
    'db' => $dbName,
    'n' => $n,
    'exam_id' => null,
    'batch_id' => $batchId,
    'batch_title' => LOADTEST_DIAGNOSTIC_BATCH_TITLE,
    'professor_user_id' => $profId,
    'professor_email' => LOADTEST_PROF_EMAIL,
    'time_limit_seconds' => $timeLimit,
    'subject_id' => $subjectId,
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

loadtest_ok("Diagnostic seed complete. run_id={$runId} batch_id={$batchId} n={$n} questions=" . count($qids));
mysqli_close($conn);
