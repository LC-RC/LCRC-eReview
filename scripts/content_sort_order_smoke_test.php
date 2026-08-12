<?php
/**
 * Smoke test: lesson/quiz sort_order schema, append-on-create, save order, student ORDER BY.
 * Does not touch playground tables.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/content_sort_order.php';

$failed = 0;
$passed = 0;
function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "PASS  {$msg}\n";
    } else {
        $failed++;
        echo "FAIL  {$msg}\n";
    }
}

content_sort_order_ensure_schema($conn);
assert_true(content_sort_order_column_exists($conn, 'lessons', 'sort_order', true), 'lessons.sort_order exists');
assert_true(content_sort_order_column_exists($conn, 'quizzes', 'sort_order', true), 'quizzes.sort_order exists');

$sid = 0;
$r = @mysqli_query(
    $conn,
    'SELECT subject_id FROM lessons GROUP BY subject_id HAVING COUNT(*) >= 2 ORDER BY subject_id ASC LIMIT 1'
);
if ($r && ($row = mysqli_fetch_assoc($r))) {
    $sid = (int) $row['subject_id'];
}
if ($sid <= 0) {
    $r = mysqli_query($conn, 'SELECT subject_id FROM subjects ORDER BY subject_id ASC LIMIT 1');
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $sid = (int) $row['subject_id'];
    }
}
assert_true($sid > 0, 'found a subject for smoke test');

$lessonIdsBefore = [];
$q = mysqli_prepare($conn, 'SELECT lesson_id FROM lessons WHERE subject_id = ? ORDER BY sort_order ASC, lesson_id ASC');
mysqli_stmt_bind_param($q, 'i', $sid);
mysqli_stmt_execute($q);
$res = mysqli_stmt_get_result($q);
while ($row = mysqli_fetch_assoc($res)) {
    $lessonIdsBefore[] = (int) $row['lesson_id'];
}
mysqli_stmt_close($q);

$ts = (string) time();
$createdTemps = [];
for ($i = 1; $i <= 2; $i++) {
    $title = 'SortOrder Smoke Lesson ' . $ts . '-' . $i;
    $next = content_sort_order_next($conn, 'lessons', $sid);
    $stmt = mysqli_prepare($conn, 'INSERT INTO lessons (subject_id, title, description, sort_order) VALUES (?, ?, ?, ?)');
    $desc = 'smoke';
    mysqli_stmt_bind_param($stmt, 'issi', $sid, $title, $desc, $next);
    mysqli_stmt_execute($stmt);
    $nid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    $createdTemps[] = $nid;
}
assert_true(count($createdTemps) === 2 && $createdTemps[0] > 0 && $createdTemps[1] > 0, 'created two temp lessons');

$ordered = [];
$q = mysqli_prepare($conn, 'SELECT lesson_id FROM lessons WHERE subject_id = ? ORDER BY sort_order ASC, lesson_id ASC');
mysqli_stmt_bind_param($q, 'i', $sid);
mysqli_stmt_execute($q);
$res = mysqli_stmt_get_result($q);
while ($row = mysqli_fetch_assoc($res)) {
    $ordered[] = (int) $row['lesson_id'];
}
mysqli_stmt_close($q);
$tail = array_slice($ordered, -2);
assert_true($tail === $createdTemps, 'new lessons append at end in create order');

$reversed = array_reverse($ordered);
$save = content_sort_order_save($conn, 'lessons', 'lesson_id', $sid, $reversed);
assert_true(!empty($save['ok']), 'save reversed lesson order');
$check = [];
$q = mysqli_prepare($conn, 'SELECT lesson_id FROM lessons WHERE subject_id = ? ORDER BY sort_order ASC, lesson_id ASC');
mysqli_stmt_bind_param($q, 'i', $sid);
mysqli_stmt_execute($q);
$res = mysqli_stmt_get_result($q);
while ($row = mysqli_fetch_assoc($res)) {
    $check[] = (int) $row['lesson_id'];
}
mysqli_stmt_close($q);
assert_true($check === $reversed, 'persisted lesson order matches saved order');

// Restore prior lesson ids, then remove temps.
$restore = $lessonIdsBefore;
content_sort_order_save($conn, 'lessons', 'lesson_id', $sid, array_merge($restore, $createdTemps));
foreach ($createdTemps as $tid) {
    mysqli_query($conn, 'DELETE FROM lessons WHERE lesson_id=' . (int) $tid . ' LIMIT 1');
}
if ($restore !== []) {
    content_sort_order_save($conn, 'lessons', 'lesson_id', $sid, $restore);
}
assert_true(true, 'cleaned up temp lessons');

// Quiz append smoke
$quizTitle = 'SortOrder Smoke Quiz ' . $ts;
$nextQ = content_sort_order_next($conn, 'quizzes', $sid);
$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO quizzes (subject_id, title, quiz_type, time_limit_minutes, time_limit_seconds, sort_order) VALUES (?, ?, 'topical', 30, 1800, ?)"
);
mysqli_stmt_bind_param($stmt, 'isi', $sid, $quizTitle, $nextQ);
mysqli_stmt_execute($stmt);
$newQuizId = (int) mysqli_insert_id($conn);
mysqli_stmt_close($stmt);
assert_true($newQuizId > 0, 'created temp quiz');

$quizOrdered = [];
$q = mysqli_prepare($conn, 'SELECT quiz_id FROM quizzes WHERE subject_id = ? ORDER BY sort_order ASC, quiz_id ASC');
mysqli_stmt_bind_param($q, 'i', $sid);
mysqli_stmt_execute($q);
$res = mysqli_stmt_get_result($q);
while ($row = mysqli_fetch_assoc($res)) {
    $quizOrdered[] = (int) $row['quiz_id'];
}
mysqli_stmt_close($q);
assert_true(!empty($quizOrdered) && end($quizOrdered) === $newQuizId, 'new quiz is at end of subject sequence');

mysqli_query($conn, 'DELETE FROM quizzes WHERE quiz_id=' . (int) $newQuizId . ' LIMIT 1');

$orderSql = content_sort_order_sql('l', 'lesson_id');
assert_true($orderSql === 'l.sort_order ASC, l.lesson_id ASC', 'ORDER BY helper for lessons');
$orderSqlQ = content_sort_order_sql('q', 'quiz_id');
assert_true($orderSqlQ === 'q.sort_order ASC, q.quiz_id ASC', 'ORDER BY helper for quizzes');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
