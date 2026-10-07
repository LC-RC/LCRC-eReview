<?php
/**
 * Deploy-time examination schema ensure (Regular + Diagnostic).
 *
 * Usage (CLI):
 *   php scripts/migrate_examination_schema.php
 *
 * Or set EREVIEW_ENSURE_SCHEMA=1 for a one-off include.
 * Student request paths do NOT run this automatically.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

define('EREVIEW_SCHEMA_MIGRATE', true);
$GLOBALS['EREVIEW_ENSURE_SCHEMA'] = true;

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/examination/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';

$checks = [
    'college_exam_answers.save_seq' => "SHOW COLUMNS FROM college_exam_answers LIKE 'save_seq'",
    'diagnostic_answers.save_seq' => "SHOW COLUMNS FROM diagnostic_answers LIKE 'save_seq'",
    'college_exam_attempt_events' => "SHOW TABLES LIKE 'college_exam_attempt_events'",
    'college_exam_subjects' => "SHOW TABLES LIKE 'college_exam_subjects'",
    'college_exam_topics' => "SHOW TABLES LIKE 'college_exam_topics'",
    'college_exam_subjects.questions_required' => "SHOW COLUMNS FROM college_exam_subjects LIKE 'questions_required'",
    'college_exams.question_breakdown_mode' => "SHOW COLUMNS FROM college_exams LIKE 'question_breakdown_mode'",
    'college_exams.total_questions_required' => "SHOW COLUMNS FROM college_exams LIKE 'total_questions_required'",
    'college_exam_questions.exam_topic_id' => "SHOW COLUMNS FROM college_exam_questions LIKE 'exam_topic_id'",
    'college_exam_questions.exam_subject_id' => "SHOW COLUMNS FROM college_exam_questions LIKE 'exam_subject_id'",
    'college_exam_attempts.subject_breakdown_json' => "SHOW COLUMNS FROM college_exam_attempts LIKE 'subject_breakdown_json'",
    'college_exam_attempt_questions' => "SHOW TABLES LIKE 'college_exam_attempt_questions'",
];
$ok = true;
foreach ($checks as $label => $sql) {
    $r = mysqli_query($conn, $sql);
    $row = $r ? mysqli_fetch_row($r) : null;
    if ($r) {
        mysqli_free_result($r);
    }
    $pass = (bool)$row;
    echo ($pass ? '[OK] ' : '[MISSING] ') . $label . PHP_EOL;
    $ok = $ok && $pass;
}

echo $ok ? "Migration ensure completed.\n" : "Migration ensure finished with missing objects — inspect DB privileges/errors.\n";
exit($ok ? 0 : 2);
