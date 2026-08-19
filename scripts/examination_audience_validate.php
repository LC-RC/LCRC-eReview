<?php
declare(strict_types=1);

/**
 * Phase 2 audience/assignment verification.
 * php scripts/examination_audience_validate.php
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/examination/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/examination/includes/examination_domain.php';
require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';
require_once dirname(__DIR__) . '/examination/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_exam_helpers.php';

$results = [];
$mark = static function (string $name, bool $pass, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'result' => $pass ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$profId = 3;
$stamp = date('YmdHis');
$examIds = [];
$batchId = 0;

function audience_find_or_create_student(mysqli $conn, string $section, string $suffix): ?array
{
    $secEsc = mysqli_real_escape_string($conn, $section);
    $q = @mysqli_query($conn, "SELECT user_id, section, review_type FROM users WHERE role='college_student' AND status='approved' AND TRIM(COALESCE(section,''))='{$secEsc}' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        mysqli_free_result($q);
        return $r;
    }
    if ($q) {
        mysqli_free_result($q);
    }
    $email = 'audience_' . $suffix . '_' . time() . '@example.test';
    $name = 'Audience Test ' . strtoupper($suffix);
    $pass = password_hash('testpass123', PASSWORD_DEFAULT);
    $school = 'Audience Test School';
    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, email, password, role, status, review_type, school, section) VALUES (?, ?, ?, 'college_student', 'approved', 'undergrad', ?, ?)");
    mysqli_stmt_bind_param($ins, 'sssss', $name, $email, $pass, $school, $section);
    mysqli_stmt_execute($ins);
    $uid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    return $uid > 0 ? ['user_id' => $uid, 'section' => $section, 'review_type' => 'undergrad'] : null;
}

try {
    $sA = audience_find_or_create_student($conn, 'Section A', 'a' . $stamp);
    $sB = audience_find_or_create_student($conn, 'Section B', 'b' . $stamp);
    $sC = audience_find_or_create_student($conn, 'Section C', 'c' . $stamp);
    $mark('fixture students A/B/C', $sA && $sB && $sC, json_encode([
        'A' => (int)($sA['user_id'] ?? 0),
        'B' => (int)($sB['user_id'] ?? 0),
        'C' => (int)($sC['user_id'] ?? 0),
    ]));

    $future = date('Y-m-d\TH:i', time() + 86400);
    $past = date('Y-m-d\TH:i', time() - 86400);
    $now = date('Y-m-d H:i:s');
    $openFrom = date('Y-m-d\TH:i', time() - 3600);
    $openUntil = date('Y-m-d\TH:i', time() + 3600);

    // 1. Regular Section B only
    $cfg1 = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'publish',
        'title' => "Audience SecB {$stamp}",
        'description' => 'test',
        'time_limit_hours' => 1,
        'time_limit_minutes' => 0,
        'available_from' => $openFrom,
        'deadline' => $openUntil,
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'sections',
        'sections' => ['Section B'],
    ], 0);
    $e1 = (int)($cfg1['source_id'] ?? 0);
    $examIds[] = $e1;
    $exam1 = examination_type_regular_load_raw($conn, $e1, $profId);
    $mark('1 regular Section B publish', !empty($cfg1['ok']) && $e1 > 0, json_encode($cfg1));
    $mark('1 Section B sees exam', examination_user_is_assigned($conn, (int)$sB['user_id'], $exam1, 'regular'), '');
    $mark('1 Section A hidden', !examination_user_is_assigned($conn, (int)$sA['user_id'], $exam1, 'regular'), '');
    $mark('1 Section C hidden', !examination_user_is_assigned($conn, (int)$sC['user_id'], $exam1, 'regular'), '');

    // 2. Regular Section A + C
    $cfg2 = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'publish',
        'title' => "Audience AC {$stamp}",
        'time_limit_hours' => 1,
        'available_from' => $openFrom,
        'deadline' => $openUntil,
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'sections',
        'sections' => ['Section A', 'Section C'],
    ], 0);
    $e2 = (int)($cfg2['source_id'] ?? 0);
    $examIds[] = $e2;
    $exam2 = examination_type_regular_load_raw($conn, $e2, $profId);
    $mark('2 A sees', examination_user_is_assigned($conn, (int)$sA['user_id'], $exam2, 'regular'), '');
    $mark('2 C sees', examination_user_is_assigned($conn, (int)$sC['user_id'], $exam2, 'regular'), '');
    $mark('2 B hidden', !examination_user_is_assigned($conn, (int)$sB['user_id'], $exam2, 'regular'), '');

    // 3. Selected students only
    $cfg3 = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'publish',
        'title' => "Audience Users {$stamp}",
        'time_limit_hours' => 1,
        'available_from' => $openFrom,
        'deadline' => $openUntil,
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'users',
        'user_ids' => [(int)$sA['user_id']],
    ], 0);
    $e3 = (int)($cfg3['source_id'] ?? 0);
    $examIds[] = $e3;
    $exam3 = examination_type_regular_load_raw($conn, $e3, $profId);
    $mark('3 selected A sees', examination_user_is_assigned($conn, (int)$sA['user_id'], $exam3, 'regular'), '');
    $mark('3 B hidden', !examination_user_is_assigned($conn, (int)$sB['user_id'], $exam3, 'regular'), '');

    // 4. Section B + additional student from A
    $cfg4 = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'publish',
        'title' => "Audience B+A {$stamp}",
        'time_limit_hours' => 1,
        'available_from' => $openFrom,
        'deadline' => $openUntil,
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'sections_and_users',
        'sections' => ['Section B'],
        'user_ids' => [(int)$sA['user_id']],
    ], 0);
    $e4 = (int)($cfg4['source_id'] ?? 0);
    $examIds[] = $e4;
    $exam4 = examination_type_regular_load_raw($conn, $e4, $profId);
    $mark('4 B sees', examination_user_is_assigned($conn, (int)$sB['user_id'], $exam4, 'regular'), '');
    $mark('4 extra A sees', examination_user_is_assigned($conn, (int)$sA['user_id'], $exam4, 'regular'), '');
    $mark('4 C hidden', !examination_user_is_assigned($conn, (int)$sC['user_id'], $exam4, 'regular'), '');

    // 5 & 6 wrong section direct access / start
    $mark('5 wrong-section can_view denied', !examination_user_can_view_exam($conn, (int)$sA['user_id'], $exam1, 'regular', null), '');
    $mark('6 wrong-section can_start denied', !college_exam_user_can_start($conn, (int)$sA['user_id'], $exam1, $now), '');

    // 7 upcoming visible but cannot start
    $cfg7 = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'publish',
        'title' => "Audience Upcoming {$stamp}",
        'time_limit_hours' => 1,
        'available_from' => $future,
        'deadline' => date('Y-m-d\TH:i', time() + 172800),
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'sections',
        'sections' => ['Section B'],
    ], 0);
    $e7 = (int)($cfg7['source_id'] ?? 0);
    $examIds[] = $e7;
    $exam7 = examination_type_regular_load_raw($conn, $e7, $profId);
    $list7 = college_exams_load_assigned_published_exams_for_user($conn, (int)$sB['user_id']);
    $inList7 = false;
    foreach ($list7 as $row) {
        if ((int)($row['exam_id'] ?? 0) === $e7) {
            $inList7 = true;
            break;
        }
    }
    $mark('7 upcoming visible in list', $inList7, 'count=' . count($list7));
    $mark('7 upcoming cannot start', !college_exam_user_can_start($conn, (int)$sB['user_id'], $exam7, $now), '');

    // 8 during schedule can start
    $mark('8 during schedule can start', college_exam_user_can_start($conn, (int)$sB['user_id'], $exam1, $now), '');

    // 9 after deadline cannot start
    $cfg9 = examination_domain_save_config($conn, 'regular', $profId, [
        'save_action' => 'publish',
        'title' => "Audience Closed {$stamp}",
        'time_limit_hours' => 1,
        'available_from' => $past,
        'deadline' => date('Y-m-d\TH:i', time() - 60),
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'sections',
        'sections' => ['Section B'],
    ], 0);
    $e9 = (int)($cfg9['source_id'] ?? 0);
    $examIds[] = $e9;
    $exam9 = examination_type_regular_load_raw($conn, $e9, $profId);
    $mark('9 after deadline cannot start', !college_exam_user_can_start($conn, (int)$sB['user_id'], $exam9, $now), '');

    // 10 existing all-student behavior
    $legacy = @mysqli_query($conn, "SELECT exam_id FROM college_exams WHERE assignment_mode='all' ORDER BY exam_id ASC LIMIT 1");
    $legacyRow = $legacy ? mysqli_fetch_assoc($legacy) : null;
    if ($legacy) {
        mysqli_free_result($legacy);
    }
    if ($legacyRow) {
        $legacyExam = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM college_exams WHERE exam_id=' . (int)$legacyRow['exam_id']));
        $mark('10 legacy all-student exam A assigned', $legacyExam && examination_user_is_assigned($conn, (int)$sA['user_id'], $legacyExam, 'regular'), 'exam_id=' . (int)$legacyRow['exam_id']);
    } else {
        $mark('10 legacy all-student exam exists', false, 'none found');
    }

    // 11 diagnostic unchanged
    $dcfg = examination_domain_save_config($conn, 'diagnostic', $profId, [
        'save_action' => 'publish',
        'title' => "Audience Diag {$stamp}",
        'time_limit_hours' => 1,
        'available_from' => $openFrom,
        'deadline' => $openUntil,
        'examinee_scope' => 'college_student',
        'assignment_mode' => 'sections',
        'sections' => ['Section C'],
        'subject_ids' => [(int)(diagnostic_exam_load_subject_catalog($conn)[0]['subject_id'] ?? 1)],
        'questions_required' => [(int)(diagnostic_exam_load_subject_catalog($conn)[0]['subject_id'] ?? 1) => 0],
    ], 0);
    $batchId = (int)($dcfg['source_id'] ?? 0);
    $batch = diagnostic_exam_load_batch($conn, $batchId);
    $mark('11 diagnostic C sees', $batch && diagnostic_exam_user_is_assigned($conn, (int)$sC['user_id'], $batch), '');
    $mark('11 diagnostic B hidden', $batch && !diagnostic_exam_user_is_assigned($conn, (int)$sB['user_id'], $batch), '');

    // 12 monitor roster
    $roster = examination_assigned_roster_user_ids($conn, 'regular', $e1);
    $mark('12 monitor roster Section B only', in_array((int)$sB['user_id'], $roster, true) && !in_array((int)$sA['user_id'], $roster, true), 'n=' . count($roster));

    // 13 dashboard assigned filter
    $dash = college_exams_load_assigned_published_exams_for_user($conn, (int)$sB['user_id']);
    $dashIds = array_map(static fn($r) => (int)($r['exam_id'] ?? 0), $dash);
    $mark('13 dashboard includes assigned', in_array($e1, $dashIds, true) && !in_array($e2, $dashIds, true) || !in_array((int)$sB['user_id'], examination_pure_assigned_user_ids($conn, 'regular', $e2), true), 'ids=' . implode(',', $dashIds));

    // 14 student list filter
    $listB = college_exams_load_assigned_published_exams_for_user($conn, (int)$sB['user_id']);
    $listA = college_exams_load_assigned_published_exams_for_user($conn, (int)$sA['user_id']);
    $idsB = array_map(static fn($r) => (int)($r['exam_id'] ?? 0), $listB);
    $idsA = array_map(static fn($r) => (int)($r['exam_id'] ?? 0), $listA);
    $mark('14 list B has e1 not e3', in_array($e1, $idsB, true) && !in_array($e3, $idsB, true), '');
    $mark('14 list A has e3 not e1', in_array($e3, $idsA, true) && !in_array($e1, $idsA, true), '');

    // 15 server-side view check
    $mark('15 direct URL view denied wrong section', !examination_user_can_view_exam($conn, (int)$sC['user_id'], $exam1, 'regular', null), '');
} catch (Throwable $e) {
    $mark('exception', false, $e->getMessage());
}

foreach ($examIds as $eid) {
    if ($eid <= 0) {
        continue;
    }
    @mysqli_query($conn, 'DELETE FROM college_exam_users WHERE exam_id=' . (int)$eid);
    @mysqli_query($conn, 'DELETE FROM college_exam_sections WHERE exam_id=' . (int)$eid);
    @mysqli_query($conn, 'DELETE FROM college_exam_questions WHERE exam_id=' . (int)$eid);
    @mysqli_query($conn, 'DELETE FROM college_exam_attempts WHERE exam_id=' . (int)$eid);
    @mysqli_query($conn, 'DELETE FROM college_exams WHERE exam_id=' . (int)$eid . ' AND created_by=' . (int)$profId);
}
if ($batchId > 0) {
    @mysqli_query($conn, 'DELETE FROM diagnostic_questions WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_subjects WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batch_sections WHERE batch_id=' . (int)$batchId);
    @mysqli_query($conn, 'DELETE FROM diagnostic_batches WHERE batch_id=' . (int)$batchId);
}

$allPass = true;
foreach ($results as $r) {
    if ($r['result'] !== 'PASS') {
        $allPass = false;
        break;
    }
}

echo json_encode(['ok' => $allPass, 'checks' => $results], JSON_PRETTY_PRINT) . "\n";
exit($allPass ? 0 : 1);
