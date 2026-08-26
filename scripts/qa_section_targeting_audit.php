<?php
declare(strict_types=1);

/**
 * Read-only audit: exam/upload assignment_mode vs section maps vs sample students.
 */
require_once dirname(__DIR__) . '/auth.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== college_exams (latest 20) ===\n";
$q = mysqli_query($conn, 'SELECT exam_id, title, assignment_mode, examinee_scope, is_published FROM college_exams ORDER BY exam_id DESC LIMIT 20');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $eid = (int)$r['exam_id'];
        $secs = [];
        $sc = mysqli_query($conn, 'SELECT section_value FROM college_exam_sections WHERE exam_id=' . $eid);
        if ($sc) {
            while ($s = mysqli_fetch_assoc($sc)) {
                $secs[] = (string)$s['section_value'];
            }
            mysqli_free_result($sc);
        }
        echo sprintf(
            "#%d mode=%s scope=%s pub=%s secs=[%s] title=%s\n",
            $eid,
            $r['assignment_mode'],
            $r['examinee_scope'],
            $r['is_published'],
            implode('|', $secs),
            substr((string)$r['title'], 0, 60)
        );
    }
    mysqli_free_result($q);
}

echo "\n=== diagnostic_batches (latest 10) ===\n";
$q = @mysqli_query($conn, 'SELECT batch_id, title, assignment_mode, examinee_scope, is_published FROM diagnostic_batches ORDER BY batch_id DESC LIMIT 10');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $bid = (int)$r['batch_id'];
        $secs = [];
        $sc = @mysqli_query($conn, 'SELECT section_value FROM diagnostic_batch_sections WHERE batch_id=' . $bid);
        if ($sc) {
            while ($s = mysqli_fetch_assoc($sc)) {
                $secs[] = (string)$s['section_value'];
            }
            mysqli_free_result($sc);
        }
        echo sprintf(
            "#%d mode=%s scope=%s pub=%s secs=[%s] title=%s\n",
            $bid,
            $r['assignment_mode'] ?? '',
            $r['examinee_scope'] ?? '',
            $r['is_published'] ?? '',
            implode('|', $secs),
            substr((string)$r['title'], 0, 60)
        );
    }
    mysqli_free_result($q);
}

echo "\n=== college_upload_tasks (latest 15) ===\n";
$q = @mysqli_query($conn, 'SELECT task_id, title, assignment_mode, examinee_scope, is_open FROM college_upload_tasks ORDER BY task_id DESC LIMIT 15');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $tid = (int)$r['task_id'];
        $secs = [];
        $sc = @mysqli_query($conn, 'SELECT section_value FROM college_upload_task_sections WHERE task_id=' . $tid);
        if ($sc) {
            while ($s = mysqli_fetch_assoc($sc)) {
                $secs[] = (string)$s['section_value'];
            }
            mysqli_free_result($sc);
        }
        echo sprintf(
            "#%d mode=%s scope=%s open=%s secs=[%s] title=%s\n",
            $tid,
            $r['assignment_mode'] ?? '',
            $r['examinee_scope'] ?? '',
            $r['is_open'] ?? '',
            implode('|', $secs),
            substr((string)$r['title'], 0, 60)
        );
    }
    mysqli_free_result($q);
}

echo "\n=== college_sections master ===\n";
$q = @mysqli_query($conn, 'SELECT * FROM college_sections ORDER BY 1 ASC LIMIT 50');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
    mysqli_free_result($q);
} else {
    echo "(table missing or error: " . mysqli_error($conn) . ")\n";
}

echo "\n=== distinct user sections ===\n";
$q = mysqli_query($conn, "SELECT role, TRIM(COALESCE(section,'')) AS sec, COUNT(*) AS c FROM users WHERE role IN ('college_student','student') GROUP BY role, sec ORDER BY c DESC LIMIT 40");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        echo sprintf("role=%s sec=[%s] count=%d\n", $r['role'], $r['sec'], (int)$r['c']);
    }
    mysqli_free_result($q);
}

echo "\n=== eligibility probe for exam #48 (sections=[a]) ===\n";
require_once dirname(__DIR__) . '/examination/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/examination/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';

$examSt = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=48 LIMIT 1');
mysqli_stmt_execute($examSt);
$exam = mysqli_fetch_assoc(mysqli_stmt_get_result($examSt));
mysqli_stmt_close($examSt);
echo 'exam mode=' . ($exam['assignment_mode'] ?? '') . "\n";

$uq = mysqli_query($conn, "SELECT user_id, full_name, role, section, status FROM users WHERE role IN ('college_student','student') ORDER BY user_id DESC LIMIT 15");
while ($u = mysqli_fetch_assoc($uq)) {
    $uid = (int)$u['user_id'];
    $assigned = examination_user_is_assigned($conn, $uid, $exam, 'regular') ? 'YES' : 'no';
    $loaded = diagnostic_exam_load_examinee_user($conn, $uid);
    echo sprintf(
        "#%d role=%s sec=[%s] load_examinee=%s assigned=%s name=%s\n",
        $uid,
        $u['role'],
        $u['section'] ?? '',
        $loaded ? 'ok(' . ($loaded['section'] ?? '') . ')' : 'NULL',
        $assigned,
        $u['full_name'] ?? ''
    );
}

echo "\nDone.\n";
