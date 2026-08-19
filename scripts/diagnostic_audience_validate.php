<?php
declare(strict_types=1);

/**
 * Diagnostic audience / assignment validation (Phase 2).
 * Run: c:\xampp\php\php.exe scripts\diagnostic_audience_validate.php
 */

require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require dirname(__DIR__) . '/examination/includes/diagnostic_exam_helpers.php';

$report = [
    'schema' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'syntax' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'eligibility' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'isolation' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'regression' => ['pass' => 0, 'fail' => 0, 'items' => []],
    'cleanup' => [],
];

function rec(array &$bucket, string $name, bool $pass, string $detail = ''): void
{
    $bucket['items'][] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
    if ($pass) {
        $bucket['pass']++;
    } else {
        $bucket['fail']++;
    }
}

$phpBin = getenv('EREVIEW_PHP_BIN') ?: 'c:\\xampp\\php\\php.exe';
$syntaxFiles = [
    'examination/includes/diagnostic_exam_helpers.php',
    'examination/professor/professor_diagnostic_batch_edit.php',
    'examination/professor/professor_create_reviewee.php',
    'examination/professor/professor_college_students.php',
    'examination/professor/professor_college_student_view.php',
    'professor_create_reviewee.php',
];
foreach ($syntaxFiles as $rel) {
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $out = [];
    exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    rec($report['syntax'], $rel, $code === 0, trim(implode(' ', $out)));
}

// Schema columns / table
foreach (['exam_type', 'examinee_scope', 'assignment_mode'] as $col) {
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM diagnostic_batches LIKE '{$col}'");
    $ok = $q && mysqli_fetch_assoc($q);
    if ($q) {
        mysqli_free_result($q);
    }
    rec($report['schema'], "column:diagnostic_batches.{$col}", (bool)$ok);
}
$tb = @mysqli_query($conn, "SHOW TABLES LIKE 'diagnostic_batch_users'");
rec($report['schema'], 'table:diagnostic_batch_users', $tb && mysqli_fetch_assoc($tb) !== null);
if ($tb) {
    mysqli_free_result($tb);
}

function qa_find_or_make_examinee(mysqli $conn, string $reviewType, string $section, string $tag): ?array
{
    $rt = $reviewType === 'undergrad' ? 'undergrad' : 'reviewee';
    $secEsc = mysqli_real_escape_string($conn, $section);
    $email = strtolower($tag) . '_diag_' . bin2hex(random_bytes(4)) . '@qa.local';
    $emailEsc = mysqli_real_escape_string($conn, $email);
    $q = @mysqli_query($conn, "SELECT user_id, review_type, section, status, role FROM users WHERE role='college_student' AND review_type='{$rt}' AND TRIM(COALESCE(section,''))='{$secEsc}' AND status='approved' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        mysqli_free_result($q);
        return $r;
    }
    if ($q) {
        mysqli_free_result($q);
    }
    $pw = password_hash('QaTest123!', PASSWORD_DEFAULT);
    $name = 'QA ' . $tag;
    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, email, password, role, status, email_verified) VALUES (?, ?, 'QA School', ?, ?, ?, 'college_student', 'approved', 1)");
    mysqli_stmt_bind_param($ins, 'sssss', $name, $rt, $section, $email, $pw);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);
        return null;
    }
    $uid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    return diagnostic_exam_load_examinee_user($conn, $uid);
}

function qa_make_batch(mysqli $conn, int $profId, array $overrides = []): int
{
    $title = 'QA Audience ' . bin2hex(random_bytes(4));
    $scope = diagnostic_exam_normalize_examinee_scope((string)($overrides['examinee_scope'] ?? 'both'));
    $mode = diagnostic_exam_normalize_assignment_mode((string)($overrides['assignment_mode'] ?? 'all'));
    $now = date('Y-m-d H:i:s');
    $from = date('Y-m-d H:i:s', time() - 3600);
    $dead = date('Y-m-d H:i:s', time() + 86400 * 7);
    $ins = mysqli_prepare($conn, "INSERT INTO diagnostic_batches (title, time_limit_seconds, available_from, deadline, is_published, examinee_scope, assignment_mode, created_by) VALUES (?, 3600, ?, ?, 1, ?, ?, ?)");
    mysqli_stmt_bind_param($ins, 'sssssi', $title, $from, $dead, $scope, $mode, $profId);
    mysqli_stmt_execute($ins);
    $bid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    foreach (($overrides['sections'] ?? []) as $sec) {
        $st = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_sections (batch_id, section_value) VALUES (?,?)');
        mysqli_stmt_bind_param($st, 'is', $bid, $sec);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
    foreach (($overrides['user_ids'] ?? []) as $uid) {
        $st = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_users (batch_id, user_id) VALUES (?,?)');
        mysqli_stmt_bind_param($st, 'ii', $bid, $uid);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
    return $bid;
}

function qa_delete_batch(mysqli $conn, int $batchId): void
{
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_users WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_sections WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batches WHERE batch_id=' . (int)$batchId);
}

$profQ = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='professor_admin' AND status='approved' ORDER BY user_id ASC LIMIT 1");
$profId = 0;
if ($profQ && ($pr = mysqli_fetch_assoc($profQ))) {
    $profId = (int)$pr['user_id'];
}
if ($profQ) {
    mysqli_free_result($profQ);
}

$nowSql = date('Y-m-d H:i:s');
$createdBatches = [];
$createdUsers = [];

if ($profId <= 0) {
    rec($report['eligibility'], 'setup:professor_admin', false, 'no professor_admin user');
} else {
    rec($report['eligibility'], 'setup:professor_admin', true, "user_id={$profId}");

    $undergrad = qa_find_or_make_examinee($conn, 'undergrad', 'QA-DIAG-A', 'UndergradA');
    $revieweeEmpty = qa_find_or_make_examinee($conn, 'reviewee', '', 'RevieweeEmpty');
    $revieweeSec = qa_find_or_make_examinee($conn, 'reviewee', 'QA-DIAG-B', 'RevieweeB');
    $undergradOther = qa_find_or_make_examinee($conn, 'undergrad', 'QA-DIAG-Z', 'UndergradZ');

    rec($report['eligibility'], 'setup:undergrad user', $undergrad !== null);
    rec($report['eligibility'], 'setup:reviewee empty section', $revieweeEmpty !== null && trim((string)($revieweeEmpty['section'] ?? '')) === '');
    rec($report['eligibility'], 'setup:reviewee with section', $revieweeSec !== null);

    if ($undergrad && $revieweeEmpty && $revieweeSec && $undergradOther) {
        $uidUg = (int)$undergrad['user_id'];
        $uidRevEmpty = (int)$revieweeEmpty['user_id'];
        $uidRevSec = (int)$revieweeSec['user_id'];
        $uidUgOther = (int)$undergradOther['user_id'];

        // College student scope + all
        $b1 = qa_make_batch($conn, $profId, ['examinee_scope' => 'college_student', 'assignment_mode' => 'all']);
        $createdBatches[] = $b1;
        $batch1 = diagnostic_exam_load_batch($conn, $b1);
        rec($report['eligibility'], 'college_student + all: undergrad eligible', diagnostic_exam_user_eligible_for_batch($conn, $uidUg, $batch1, $nowSql));
        rec($report['eligibility'], 'college_student + all: reviewee NOT eligible', !diagnostic_exam_user_eligible_for_batch($conn, $uidRevEmpty, $batch1, $nowSql));

        // Reviewee scope + all (empty section)
        $b2 = qa_make_batch($conn, $profId, ['examinee_scope' => 'reviewee', 'assignment_mode' => 'all']);
        $createdBatches[] = $b2;
        $batch2 = diagnostic_exam_load_batch($conn, $b2);
        rec($report['eligibility'], 'reviewee + all: empty-section reviewee eligible', diagnostic_exam_user_eligible_for_batch($conn, $uidRevEmpty, $batch2, $nowSql));
        rec($report['eligibility'], 'reviewee + all: undergrad NOT eligible', !diagnostic_exam_user_eligible_for_batch($conn, $uidUg, $batch2, $nowSql));

        // Section assignment
        $b3 = qa_make_batch($conn, $profId, ['examinee_scope' => 'both', 'assignment_mode' => 'sections', 'sections' => ['QA-DIAG-A']]);
        $createdBatches[] = $b3;
        $batch3 = diagnostic_exam_load_batch($conn, $b3);
        rec($report['eligibility'], 'sections: matching undergrad eligible', diagnostic_exam_user_eligible_for_batch($conn, $uidUg, $batch3, $nowSql));
        rec($report['eligibility'], 'sections: other section NOT eligible', !diagnostic_exam_user_eligible_for_batch($conn, $uidUgOther, $batch3, $nowSql));
        rec($report['eligibility'], 'sections: empty-section reviewee NOT eligible', !diagnostic_exam_user_eligible_for_batch($conn, $uidRevEmpty, $batch3, $nowSql));

        // Individual assignment
        $b4 = qa_make_batch($conn, $profId, ['examinee_scope' => 'reviewee', 'assignment_mode' => 'users', 'user_ids' => [$uidRevEmpty]]);
        $createdBatches[] = $b4;
        $batch4 = diagnostic_exam_load_batch($conn, $b4);
        rec($report['eligibility'], 'users: assigned reviewee eligible', diagnostic_exam_user_eligible_for_batch($conn, $uidRevEmpty, $batch4, $nowSql));
        rec($report['eligibility'], 'users: unassigned reviewee NOT eligible', !diagnostic_exam_user_eligible_for_batch($conn, $uidRevSec, $batch4, $nowSql));

        // Sections + individuals OR
        $b5 = qa_make_batch($conn, $profId, [
            'examinee_scope' => 'both',
            'assignment_mode' => 'sections_and_users',
            'sections' => ['QA-DIAG-B'],
            'user_ids' => [$uidRevEmpty],
        ]);
        $createdBatches[] = $b5;
        $batch5 = diagnostic_exam_load_batch($conn, $b5);
        rec($report['eligibility'], 'sections_and_users OR: section match', diagnostic_exam_user_eligible_for_batch($conn, $uidRevSec, $batch5, $nowSql));
        rec($report['eligibility'], 'sections_and_users OR: individual only (empty section)', diagnostic_exam_user_eligible_for_batch($conn, $uidRevEmpty, $batch5, $nowSql));
        rec($report['eligibility'], 'sections_and_users OR: neither NOT eligible', !diagnostic_exam_user_eligible_for_batch($conn, $uidUgOther, $batch5, $nowSql));

        // Unpublished batch
        @mysqli_query($conn, 'UPDATE diagnostic_batches SET is_published=0 WHERE batch_id=' . (int)$b1);
        rec($report['eligibility'], 'unpublished batch NOT eligible', !diagnostic_exam_user_eligible_for_batch($conn, $uidUg, diagnostic_exam_load_batch($conn, $b1), $nowSql));
        @mysqli_query($conn, 'UPDATE diagnostic_batches SET is_published=1 WHERE batch_id=' . (int)$b1);
    }
}

foreach ($createdBatches as $bid) {
    qa_delete_batch($conn, $bid);
}
$report['cleanup'][] = 'Removed ' . count($createdBatches) . ' QA batches';

// Isolation grep
$protected = [
    'student_dashboard.php',
    'student_sidebar.php',
    'student_take_quiz.php',
    'includes/quiz_helpers.php',
    'college_take_exam.php',
    'college_exam_ajax.php',
    'college_exam_helpers.php',
];
foreach ($protected as $rel) {
    $path = dirname(__DIR__) . '/' . $rel;
    $content = is_file($path) ? (string)file_get_contents($path) : '';
    $hasDiag = stripos($content, 'diagnostic') !== false;
    rec($report['isolation'], "no_diagnostic:{$rel}", !$hasDiag, $hasDiag ? 'contains diagnostic' : 'clean');
}

$unchanged = [
    'examination/examinee/college_take_exam.php',
    'examination/examinee/college_exam_ajax.php',
    'examination/includes/college_exam_helpers.php',
];
foreach ($unchanged as $rel) {
    $out = [];
    exec('git diff --name-only -- ' . escapeshellarg($rel), $out);
    $changed = trim(implode("\n", $out)) !== '';
    rec($report['isolation'], "unchanged:{$rel}", !$changed, $changed ? 'git diff' : 'ok');
}

// Run phase1 subset for regression
$phase1 = dirname(__DIR__) . '/scripts/diagnostic_phase1_validate.php';
if (is_file($phase1)) {
    $out = [];
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($phase1) . ' 2>&1', $out, $code);
    $json = json_decode(implode("\n", $out), true);
    if (is_array($json)) {
        rec($report['regression'], 'phase1 syntax', ($json['syntax']['fail'] ?? 1) === 0, 'pass=' . ($json['syntax']['pass'] ?? 0));
        rec($report['regression'], 'phase1 architecture', ($json['architecture']['fail'] ?? 1) === 0);
        rec($report['regression'], 'phase1 college exam regression', ($json['regression_college_exam']['fail'] ?? 1) === 0);
    } else {
        rec($report['regression'], 'phase1 harness', false, 'could not parse output');
    }
}

$totalFail = $report['schema']['fail'] + $report['syntax']['fail'] + $report['eligibility']['fail'] + $report['isolation']['fail'] + $report['regression']['fail'];
$report['summary'] = [
    'total_fail' => $totalFail,
    'audience_complete' => $totalFail === 0,
    'eligibility_pass' => $report['eligibility']['pass'],
    'eligibility_fail' => $report['eligibility']['fail'],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($totalFail === 0 ? 0 : 1);
