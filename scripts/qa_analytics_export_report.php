<?php
/**
 * Database-free checks for the regular-exam analytics performance report.
 * Does not connect to MySQL and does not read examination rows.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/examination/includes/examination_monitor_analytics_summary.php';
require_once dirname(__DIR__) . '/examination/includes/examination_monitor_analytics_workbook.php';

$fails = [];
$passes = 0;

function aer_check(array &$fails, int &$passes, string $name, bool $ok): void
{
    if ($ok) {
        $passes++;
        echo "PASS  {$name}\n";
        return;
    }
    $fails[] = $name;
    echo "FAIL  {$name}\n";
}

$pass = examination_monitor_analytics_classify_examinee('submitted', 25, 50);
$fail = examination_monitor_analytics_classify_examinee('submitted', 24, 50);
$odd = examination_monitor_analytics_classify_examinee('submitted', 26, 51);
$ungraded = examination_monitor_analytics_classify_examinee('submitted', null, 0);
$progress = examination_monitor_analytics_classify_examinee('in_progress', 10, 20);
$expired = examination_monitor_analytics_classify_examinee('expired', 40, 50);
$absent = examination_monitor_analytics_classify_examinee('', null, null);
aer_check($fails, $passes, 'half-correct pass and fail stay separate from absent', $pass['category'] === 'Passed' && $fail['category'] === 'Failed' && $absent['category'] === 'Absent' && $ungraded['category'] === 'Submitted, not graded');
aer_check($fails, $passes, 'odd paper uses ceil of half', $odd['category'] === 'Passed' && $odd['mark'] === 'Pass');
aer_check($fails, $passes, 'in progress and expired are not graded', $progress['graded'] === false && $expired['graded'] === false && $expired['category'] === 'Expired');
aer_check($fails, $passes, 'finalization warning follows open attempts only', examination_monitor_analytics_unfinalized_warning_applies('in_progress') && examination_monitor_analytics_unfinalized_warning_applies(' IN_PROGRESS ') && !examination_monitor_analytics_unfinalized_warning_applies('expired') && !examination_monitor_analytics_unfinalized_warning_applies('submitted') && !examination_monitor_analytics_unfinalized_warning_applies('') && !examination_monitor_analytics_unfinalized_warning_applies('completed'));
aer_check($fails, $passes, 'ungraded submission has no passing rate inputs', $ungraded['graded'] === false && $ungraded['percent'] === null);

$raw = [
    ['user_id' => 1, 'full_name' => 'Ada', 'attempt_status' => 'submitted', 'correct_count' => 8, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 2, 'full_name' => 'Bea', 'attempt_status' => 'submitted', 'correct_count' => 3, 'total_count' => 10, 'section' => 'B'],
    ['user_id' => 3, 'full_name' => 'Cal', 'attempt_status' => 'submitted', 'correct_count' => null, 'total_count' => 0, 'section' => 'A'],
    ['user_id' => 4, 'full_name' => 'Dee', 'attempt_status' => 'in_progress', 'correct_count' => 1, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 5, 'full_name' => 'Eve', 'attempt_status' => 'expired', 'correct_count' => 9, 'total_count' => 10, 'section' => 'B'],
    ['user_id' => 6, 'full_name' => 'Fay', 'attempt_status' => '', 'correct_count' => null, 'total_count' => null, 'section' => 'A'],
    ['user_id' => 2, 'full_name' => 'Bea', 'attempt_status' => '', 'correct_count' => null, 'total_count' => null, 'section' => 'B'],
];
$report = examination_monitor_analytics_build_report($raw, [], [
    'section_filter' => '',
    'breakdown_mode' => 'subject',
    'subjects' => [['name' => 'Math', 'answered' => 4, 'accuracy' => 75.0, 'unassigned' => false]],
    'topics' => [],
]);
$counts = $report['summary']['counts'];
aer_check($fails, $passes, 'roster categories reconcile', (int)$report['summary']['reconcile_difference'] === 0 && (int)$counts['assigned'] === 6 && (int)$counts['passed'] === 1 && (int)$counts['failed'] === 1 && (int)$counts['ungraded'] === 1 && (int)$counts['in_progress'] === 1 && (int)$counts['expired'] === 1 && (int)$counts['absent'] === 1);
aer_check($fails, $passes, 'duplicate attempt does not double count', (int)$counts['assigned'] === 6);
aer_check($fails, $passes, 'passing rate ignores absent and ungraded', $report['summary']['passing_rate'] === 50.0 && $report['summary']['completion_rate'] === 50.0);
aer_check($fails, $passes, 'scores use graded papers only', $report['summary']['average'] === 55.0 && $report['summary']['highest'] === 80.0 && $report['summary']['lowest'] === 30.0);
aer_check($fails, $passes, 'empty graded scope does not invent a passing rate', examination_monitor_analytics_performance_summary([])['passing_rate'] === null && examination_monitor_analytics_performance_summary([])['completion_rate'] === null);

$ranked = [];
for ($i = 1; $i <= 12; $i++) {
    $ranked[] = ['user_id' => $i, 'full_name' => 'S' . $i, 'attempt_status' => 'submitted', 'correct_count' => 21 - $i, 'total_count' => 20, 'section' => 'A'];
}
$ranked[10]['correct_count'] = 11;
$many = examination_monitor_analytics_build_report($ranked, [], ['section_filter' => 'A', 'breakdown_mode' => 'overall']);
$tied = array_values(array_filter($many['rankings']['top'], static fn(array $row): bool => (int)$row['user_id'] === 10 || (int)$row['user_id'] === 11));
$lowIds = array_map(static fn(array $row): int => (int)$row['user_id'], $many['rankings']['low']);
aer_check($fails, $passes, 'top list keeps the tie at the cutoff', count($many['rankings']['top']) === 11 && count($tied) === 2 && (int)$tied[0]['rank'] === (int)$tied[1]['rank']);
aer_check($fails, $passes, 'lowest list keeps the person below the top cutoff', $lowIds === [12] && (int)$many['rankings']['low'][0]['rank'] === (int)$tied[0]['rank'] + 1);
aer_check($fails, $passes, 'section filter omits the section chart', empty($many['charts']['sections']['included']));
aer_check($fails, $passes, 'overall mode omits the subject chart', empty($many['charts']['subjects']['included']) && str_contains((string)$many['charts']['subjects']['note'], 'overall-mode'));

$small = examination_monitor_analytics_build_report(array_slice($raw, 0, 2), [], ['section_filter' => '', 'breakdown_mode' => 'subject', 'subjects' => [['name' => 'Math', 'answered' => 4, 'accuracy' => 75.0, 'unassigned' => false]]]);
aer_check($fails, $passes, 'ten or fewer graded papers use one ranking list', ($small['rankings']['mode'] ?? '') === 'all' && $small['rankings']['low'] === []);
aer_check($fails, $passes, 'two sections produce a comparison chart', !empty($report['charts']['sections']['included']) && count($report['charts']['sections']['labels']) === 2);
$detail = examination_monitor_analytics_export_sections([
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], []);
$storedSheets = [$detail[1], $detail[2], $detail[3]];
$storedLabeled = true;
foreach ($storedSheets as $sheet) {
    $headerText = implode(' ', $sheet['headers']);
    if (!str_contains((string)$sheet['hint'], 'stored scoring flags') || !str_contains((string)$sheet['hint'], 'not an independently verified historical result') || !str_contains((string)$sheet['hint'], 'not a difficulty ranking') || !str_contains($headerText, 'Stored correct') || !str_contains($headerText, 'Stored accuracy') || str_contains($headerText, 'Weak')) {
        $storedLabeled = false;
    }
}
aer_check($fails, $passes, 'subject topic and question sheets label stored scoring data', $storedLabeled && str_contains(examination_monitor_analytics_stored_score_note(), 'not an independently verified historical result') && empty($report['charts']['subjects']['included']) && str_contains((string)$report['charts']['subjects']['note'], 'at least 5 answered items') && str_contains((string)$report['charts']['scores']['note'], 'fewer than 5 graded'));

$responses = [];
for ($n = 0; $n < 6; $n++) {
    $responses[] = ['question_id' => 10, 'stem' => 'Hard item', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'A', 'bank_correct' => 'B', 'choice_perm' => '', 'stored_is_correct' => 0];
}
for ($n = 0; $n < 5; $n++) {
    $responses[] = ['question_id' => 11, 'stem' => 'Easy item', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'C', 'bank_correct' => 'C', 'choice_perm' => '', 'stored_is_correct' => 1];
}
$responses[] = ['question_id' => 11, 'stem' => 'Easy item', 'subject_name' => 'Math', 'selected' => '', 'stored_is_correct' => null];
$responses[] = ['question_id' => 11, 'stem' => 'Easy item', 'subject_name' => 'Math', 'selected' => 'E', 'stored_is_correct' => 0];
$responses[] = ['question_id' => 11, 'stem' => 'Easy item', 'subject_name' => 'Math', 'selected' => 'A', 'stored_is_correct' => null];
$responses[] = ['question_id' => 11, 'stem' => 'Easy item', 'subject_name' => 'Math', 'selected' => 'B', 'stored_is_correct' => ''];
$responses[] = ['question_id' => 11, 'stem' => 'Easy item', 'subject_name' => 'Math', 'selected' => 'D', 'stored_is_correct' => 'yes'];
$responses[] = ['question_id' => 11, 'stem' => 'Easy item', 'subject_name' => 'Math', 'selected' => 'C', 'stored_is_correct' => 2];
$difficulty = examination_monitor_analytics_question_difficulty($responses, [
    'submitted_attempts' => 8,
    'attempts_without_snapshot' => 3,
]);
$byId = [];
foreach ($difficulty['questions'] as $question) {
    $byId[(int)$question['question_id']] = $question;
}
aer_check($fails, $passes, 'response counts do not become a difficulty ranking', $difficulty['hardest'] === [] && $difficulty['easiest'] === [] && empty($difficulty['rankings_available']) && !isset($byId[10]['difficulty']) && (string)$byId[10]['label'] === 'Question 10' && !str_contains((string)$byId[10]['label'], 'Hard item'));
aer_check($fails, $passes, 'missing correctness flags are excluded and not scored', (int)$difficulty['excluded_from_correctness'] === 4 && (int)$byId[11]['excluded_from_correctness'] === 4 && !isset($byId[11]['correct']) && !isset($byId[11]['incorrect']));
aer_check($fails, $passes, 'blank and non A-D answers stay in response counts', (int)$byId[11]['presented'] === (int)$byId[11]['answered'] + (int)$byId[11]['unanswered'] + (int)$byId[11]['other'] && (int)$byId[11]['unanswered'] === 1 && (int)$byId[11]['other'] === 1 && (int)$byId[11]['choices']['A'] === 1);
aer_check($fails, $passes, 'legacy attempts without snapshots are labeled partial', !empty($difficulty['partial']) && str_contains((string)$difficulty['note'], '3 of 8') === false && str_contains((string)$difficulty['note'], 'cover 5 of 8') && str_contains((string)$difficulty['note'], 'partial') && str_contains((string)$difficulty['note'], 'not verified difficulty') && str_contains((string)$difficulty['note'], 'displayed correct letter'));
$rejected = examination_monitor_analytics_build_report($raw, $responses, ['section_invalid' => true, 'section_filter' => '']);
aer_check($fails, $passes, 'invalid section does not widen the report', !empty($rejected['rejected']) && $rejected['examinees'] === [] && (int)$rejected['summary']['counts']['assigned'] === 0 && empty($rejected['charts']['sections']['included']) && !str_contains((string)$rejected['rejection'], 'all sections'));
$summarySource = (string)file_get_contents(dirname(__DIR__) . '/examination/includes/examination_monitor_analytics_summary.php');
$exportSource = (string)file_get_contents(dirname(__DIR__) . '/examination/includes/examination_monitor_analytics_export.php');
$invalidAt = strpos($exportSource, 'examination_monitor_analytics_invalid_section_message');
$metricsAt = strpos($exportSource, 'examination_monitor_metrics(');
aer_check($fails, $passes, 'report does not reconstruct a displayed answer key', !str_contains($summarySource, 'function examination_monitor_analytics_displayed_key') && !str_contains($exportSource, 'correct_answer'));
aer_check($fails, $passes, 'invalid section is rejected before analytics queries', $invalidAt !== false && $metricsAt !== false && $invalidAt < $metricsAt);

$exportSource = (string)file_get_contents(dirname(__DIR__) . '/examination/includes/examination_monitor_analytics_export.php');
$workbookSource = (string)file_get_contents(dirname(__DIR__) . '/examination/includes/examination_monitor_analytics_workbook.php');
aer_check($fails, $passes, 'analytics export does not finalize attempts', !str_contains($exportSource, 'examination_monitor_finalize_expired') && !str_contains($workbookSource, 'examination_monitor_finalize_expired'));
aer_check($fails, $passes, 'analytics queries stay read-only', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[a-z0-9_`]|DELETE\s+FROM|REPLACE\s+INTO)\b/i', $exportSource . $workbookSource));

$bytes = examination_monitor_analytics_build_xlsx_bytes('Sample Exam', 'Section A', [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], $many);
$tmp = tempnam(sys_get_temp_dir(), 'aer_xlsx_');
file_put_contents($tmp, $bytes);
$zip = new ZipArchive();
$opened = $zip->open($tmp) === true;
$workbookXml = $opened ? (string)$zip->getFromName('xl/workbook.xml') : '';
$summaryXml = $opened ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
$chartsXml = $summaryXml;
$chartPart = $opened ? (string)$zip->getFromName('xl/charts/chart1.xml') : '';
$rosterXml = $opened ? (string)$zip->getFromName('xl/worksheets/sheet2.xml') : '';
$subjectSheetXml = $opened ? (string)$zip->getFromName('xl/worksheets/sheet3.xml') : '';
$questionSheetXml = $opened ? (string)$zip->getFromName('xl/worksheets/sheet4.xml') : '';
$responseXml = $questionSheetXml;
$names = [];
if ($opened && preg_match_all('/<sheet [^>]*name="([^"]+)"/', $workbookXml, $found)) {
    $names = $found[1];
}
$chartXml = '';
$chartFormulas = [];
$missingParts = [];
if ($opened) {
    for ($chartNumber = 1; $chartNumber <= 12; $chartNumber++) {
        $part = $zip->getFromName('xl/charts/chart' . $chartNumber . '.xml');
        if ($part === false) {
            continue;
        }
        $chartXml .= $part;
        if (simplexml_load_string($part) === false) {
            $missingParts[] = 'chart' . $chartNumber;
        }
        if (preg_match_all('/<c:f>([^<]+)<\/c:f>/', $part, $formulaMatch)) {
            foreach ($formulaMatch[1] as $formula) {
                $chartFormulas[] = html_entity_decode($formula, ENT_QUOTES);
            }
        }
    }
    foreach (['xl/drawings/drawing1.xml', 'xl/worksheets/_rels/sheet1.xml.rels'] as $partName) {
        if ($zip->getFromName($partName) === false) {
            $missingParts[] = $partName;
        }
    }
    foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/styles.xml', 'xl/workbook.xml'] as $partName) {
        $part = $zip->getFromName($partName);
        if ($part === false || simplexml_load_string($part) === false) {
            $missingParts[] = $partName;
        }
    }
}
if ($opened) {
    $zip->close();
}
@unlink($tmp);
$chartRangesOk = $chartFormulas !== [] && !in_array(false, array_map(static fn(string $formula): bool => str_contains($formula, 'Summary!$'), $chartFormulas), true);
$coreSheets = ['Summary', 'Examinee Results', 'Topic Results', 'Question Analysis', 'Examinee Topic Details', 'Rankings'];
aer_check($fails, $passes, 'workbook opens and keeps the detailed sheets', $opened && $names === $coreSheets);
$sheetNamesFrom = static function (string $bytes): array {
    $path = tempnam(sys_get_temp_dir(), 'aer_sheets_');
    if ($path === false) {
        return [];
    }
    file_put_contents($path, $bytes);
    $book = new ZipArchive();
    $foundNames = [];
    if ($book->open($path) === true) {
        if (preg_match_all('/<sheet [^>]*name="([^"]+)"/', (string)$book->getFromName('xl/workbook.xml'), $sheetFound)) {
            $foundNames = $sheetFound[1];
        }
        $book->close();
    }
    @unlink($path);

    return $foundNames;
};
$singleAna = [
    'subjects' => [['name' => 'FAR', 'subject_name' => 'FAR', 'unassigned' => false]],
    'topics' => [['name' => 'Cash', 'subject_name' => 'FAR', 'unassigned' => false]],
    'questions' => [['question_id' => 1, 'stem' => 'Cash question', 'subject_name' => 'FAR', 'topic_name' => 'Cash']],
    'examinee_subjects' => [['subject_name' => 'FAR', 'full_name' => 'Ada']],
    'examinee_topics' => [['subject_name' => 'FAR', 'topic_name' => 'Cash', 'full_name' => 'Ada']],
];
$multiAna = $singleAna;
$multiAna['subjects'][] = ['name' => 'Law', 'subject_name' => 'Law', 'unassigned' => false];
$multiAna['topics'][] = ['name' => 'Torts', 'subject_name' => 'Law', 'unassigned' => false];
$singleNames = $sheetNamesFrom(examination_monitor_analytics_build_xlsx_bytes('Single Subject', 'All sections', $singleAna, [], $emptyReport ?? examination_monitor_analytics_build_report([], [], [])));
$multiNames = $sheetNamesFrom(examination_monitor_analytics_build_xlsx_bytes('Multi Subject', 'All sections', $multiAna, [], examination_monitor_analytics_build_report([], [], [])));
aer_check($fails, $passes, 'single-subject workbook keeps the lecturer sheets and omits rankings below five graded results', $singleNames === ['Summary', 'Examinee Results', 'Topic Results', 'Question Analysis', 'Examinee Topic Details']);
aer_check($fails, $passes, 'multi-subject workbook adds subject sheets and omits rankings below five graded results', $multiNames === ['Summary', 'Examinee Results', 'Subjects', 'Topic Results', 'Question Analysis', 'Examinee Subjects', 'Examinee Topic Details']);
$sheetPartFrom = static function (string $bytes, string $part): string {
    $path = tempnam(sys_get_temp_dir(), 'aer_part_');
    if ($path === false) {
        return '';
    }
    file_put_contents($path, $bytes);
    $book = new ZipArchive();
    $xml = '';
    if ($book->open($path) === true) {
        $xml = (string)$book->getFromName($part);
        $book->close();
    }
    @unlink($path);

    return $xml;
};
$singleSummaryXml = $sheetPartFrom(examination_monitor_analytics_build_xlsx_bytes('Single Subject', 'All sections', $singleAna, [], examination_monitor_analytics_build_report([], [], [])), 'xl/worksheets/sheet1.xml');
$multiSummaryXml = $sheetPartFrom(examination_monitor_analytics_build_xlsx_bytes('Multi Subject', 'All sections', $multiAna, [], examination_monitor_analytics_build_report([], [], [])), 'xl/worksheets/sheet1.xml');
$singleTopicOrder = strpos($singleSummaryXml, 'Top Graded Students');
$singleTopicInsights = strpos($singleSummaryXml, 'Topic Insights');
aer_check($fails, $passes, 'single-subject summary omits subject insights', $singleSummaryXml !== '' && !str_contains($singleSummaryXml, 'Subject Insights') && $singleTopicOrder !== false && $singleTopicInsights !== false && $singleTopicOrder < $singleTopicInsights && str_contains($singleSummaryXml, 'not an independently verified historical result') && str_contains($singleSummaryXml, 'Topic Insights'));
$multiTop = strpos($multiSummaryXml, 'Top Graded Students');
$multiSubjects = strpos($multiSummaryXml, 'Subject Insights');
$multiTopics = strpos($multiSummaryXml, 'Topic Insights');
aer_check($fails, $passes, 'multi-subject summary keeps subject insights', $multiTop !== false && $multiSubjects !== false && $multiTopics !== false && $multiTop < $multiSubjects && $multiSubjects < $multiTopics);
$sectionPeople = [
    ['user_id' => 1, 'full_name' => 'Ada', 'attempt_status' => 'submitted', 'correct_count' => 8, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 2, 'full_name' => 'Bea', 'attempt_status' => 'submitted', 'correct_count' => 7, 'total_count' => 10, 'section' => 'a'],
    ['user_id' => 3, 'full_name' => 'Cal', 'attempt_status' => 'submitted', 'correct_count' => 6, 'total_count' => 10, 'section' => 'Section A'],
    ['user_id' => 4, 'full_name' => 'Dee', 'attempt_status' => 'submitted', 'correct_count' => 5, 'total_count' => 10, 'section' => 'Section  A'],
    ['user_id' => 5, 'full_name' => 'Eve', 'attempt_status' => 'submitted', 'correct_count' => 4, 'total_count' => 10, 'section' => 'Section B'],
];
$sectionAna = [
    'subjects' => [['name' => 'FAR', 'subject_name' => 'FAR', 'unassigned' => false]],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [
        ['subject_name' => 'FAR', 'topic_name' => 'Cash', 'full_name' => 'Ada', 'section' => 'A'],
        ['subject_name' => 'FAR', 'topic_name' => 'Cash', 'full_name' => 'Bea', 'section' => 'a'],
        ['subject_name' => 'FAR', 'topic_name' => 'Cash', 'full_name' => 'Cal', 'section' => 'Section A'],
        ['subject_name' => 'FAR', 'topic_name' => 'Cash', 'full_name' => 'Eve', 'section' => 'Section B'],
    ],
];
$sectionBytes = examination_monitor_analytics_build_xlsx_bytes('Section Labels', 'All sections', $sectionAna, [], examination_monitor_analytics_build_report($sectionPeople, [], []));
$sectionSummaryXml = $sheetPartFrom($sectionBytes, 'xl/worksheets/sheet1.xml');
$sectionRosterXml = $sheetPartFrom($sectionBytes, 'xl/worksheets/sheet2.xml');
$sectionTopicsXml = $sheetPartFrom($sectionBytes, 'xl/worksheets/sheet5.xml');
$sectionRankXml = $sheetPartFrom($sectionBytes, 'xl/worksheets/sheet6.xml');
$sectionLabelOk = str_contains($sectionRosterXml, 'Section A') && str_contains($sectionRosterXml, 'Section B')
    && str_contains($sectionTopicsXml, 'Section A') && str_contains($sectionTopicsXml, 'Section B')
    && str_contains($sectionRankXml, 'Section A') && str_contains($sectionRankXml, 'Section B')
    && str_contains($sectionSummaryXml, 'Section A') && str_contains($sectionSummaryXml, 'Section B')
    && !str_contains($sectionRosterXml, '>a<') && !str_contains($sectionTopicsXml, '>a<')
    && !str_contains($sectionRankXml, '>a<') && !str_contains($sectionSummaryXml, '>a<');
aer_check($fails, $passes, 'equivalent section labels share one display and distinct sections stay separate', $sectionLabelOk);
aer_check($fails, $passes, 'question sheet keeps response columns and a readable stem width', str_contains($questionSheetXml, 'width="44"') && str_contains($questionSheetXml, 'Choice A') && str_contains($questionSheetXml, 'Choice B') && str_contains($questionSheetXml, 'Choice C') && str_contains($questionSheetXml, 'Choice D') && str_contains($questionSheetXml, 'Question No.') && str_contains($questionSheetXml, 'orientation="landscape"') && str_contains($questionSheetXml, 'scale="85"') && str_contains($questionSheetXml, 'fitToPage="0"') && str_contains($workbookXml, '_xlnm.Print_Titles'));
aer_check($fails, $passes, 'response sheet is labeled as statistics rather than verified difficulty', str_contains($responseXml, 'selected responses') && str_contains($responseXml, 'not verified difficulty') && !str_contains($responseXml, 'Hardest') && !str_contains($responseXml, 'Easiest') && !str_contains($responseXml, 'Stored incorrect rate'));
aer_check($fails, $passes, 'summary counts are formulas', str_contains($summaryXml, 'COUNTIF') && str_contains($summaryXml, 'AVERAGE') && str_contains($summaryXml, 'Reconciliation') && !str_contains($summaryXml, 'Roster rows for review'));
aer_check($fails, $passes, 'workbook subject and question sheets keep the stored-score label', str_contains($subjectSheetXml, 'Recorded Correct Rate') && str_contains($subjectSheetXml, 'not an independently verified historical result') && str_contains($questionSheetXml, 'Correct') && str_contains($questionSheetXml, 'not a difficulty ranking') && str_contains($questionSheetXml, 'shortened to 110 characters'));
aer_check($fails, $passes, 'roster writes names and paper percent without a lookup', str_contains($rosterXml, 'Passed') && str_contains($rosterXml, '<v>') && str_contains($rosterXml, 'Examinee Name') && str_contains($rosterXml, 'Examination Status') && str_contains($rosterXml, 'Correct Answers') && str_contains($rosterXml, 'Incorrect Answers') && str_contains($rosterXml, 'Unanswered') && !str_contains($rosterXml, 'Inclusion') && !str_contains($rosterXml, 'Review') && !str_contains($rosterXml, 'VLOOKUP') && !str_contains($workbookXml, 'VLOOKUP'));
aer_check($fails, $passes, 'summary chart data keeps the bar chart and the omission note', str_contains($summaryXml, 'Summary!AB6') && str_contains($chartPart, '<c:barChart>') && str_contains($summaryXml, 'Charts that need a larger sample are omitted') && !str_contains($summaryXml, 'Individual exam scores'));
aer_check($fails, $passes, 'summary keeps source formulas and formula cards', str_contains($summaryXml, 'Final examination results') && str_contains($summaryXml, 'COLLEGE EXAMINATION | PERFORMANCE DASHBOARD') && str_contains($summaryXml, 'Section A') && str_contains($summaryXml, 'Students Assigned') && str_contains($summaryXml, 'Students Submitted') && str_contains($summaryXml, 'Examinee Results') && str_contains($summaryXml, '!E') && str_contains($summaryXml, '!J') && str_contains($summaryXml, 'AB5-(AB6+AB7+AB8+AB9+AB10+AB11)') && str_contains($summaryXml, 'ROUND(AVERAGE(') && str_contains($summaryXml, '(AB6+AB7+AB8)/AB5') && str_contains($summaryXml, 'AB6/(AB6+AB7)') && str_contains($summaryXml, 'TEXT(AB14,') && str_contains($summaryXml, 'TEXT(AB6/(AB6+AB7)') && str_contains($summaryXml, 'TEXT(AB16,') && str_contains($summaryXml, 'Not reported') && str_contains($summaryXml, 'Pass rate is not displayed because fewer than five examinees have graded results.') && !str_contains($summaryXml, 'passed / ') && !str_contains($summaryXml, 'Individual Exam Score') && str_contains($summaryXml, 'Average Exam Score') && str_contains($summaryXml, 'Individual Examination Results') && str_contains($summaryXml, '(AB6+AB7)&lt;5') && str_contains($summaryXml, '<f>AB5</f>') && str_contains($summaryXml, '<f>AB6+AB7+AB8</f>') && str_contains($summaryXml, '<f>AB6</f>') && str_contains($summaryXml, '<f>AB7</f>') && str_contains($summaryXml, '<f>AB11</f>') && !str_contains($summaryXml, '<f>AB9</f>') && str_contains($summaryXml, 'COUNTIF') && str_contains($summaryXml, 'In progress') && str_contains($summaryXml, 'Expired') && str_contains($summaryXml, 'IF(AB9&gt;0,') && !str_contains($summaryXml, 'OR(AB9&gt;0,AB10&gt;0)') && str_contains($summaryXml, 'Some examination attempts have not been finalized') && str_contains($summaryXml, 'Review the roster before treating this report as final') && str_contains($summaryXml, 'QUICK READ') && str_contains($summaryXml, 'Overall Topic Accuracy'));
aer_check($fails, $passes, 'dashboard charts use the summary ranges', str_contains($summaryXml, '<mergeCell ref="A1:I1"/>') && str_contains($summaryXml, 'showGridLines="0"') && str_contains($summaryXml, '<drawing r:id="rId1"/>') && !str_contains($chartXml, '<c:doughnutChart>') && str_contains($chartXml, '<c:barChart>') && str_contains($chartXml, '<c:barDir val="bar"/>') && str_contains($chartXml, '<c:gapWidth val="70"/>') && str_contains($chartXml, 'Attempt status') && str_contains($summaryXml, 'Summary!AB6') && str_contains($summaryXml, 'Summary!AB7') && !str_contains($summaryXml, 'Summary!AB8') && !str_contains($summaryXml, 'Summary!AB9') && !str_contains($summaryXml, 'Summary!AB10') && !str_contains($summaryXml, 'Summary!AB11') && str_contains($summaryXml, 'Percent of assigned') && $chartRangesOk && $missingParts === []);
aer_check($fails, $passes, 'detail sheets keep filters, freeze panes, and print scaling', str_contains($rosterXml, 'autoFilter') && str_contains($rosterXml, 'ySplit="4"') && str_contains($rosterXml, 'fitToWidth="1"') && str_contains($subjectSheetXml, 'autoFilter') && str_contains($subjectSheetXml, 'ySplit="4"') && str_contains($subjectSheetXml, 'fitToWidth="1"') && str_contains($questionSheetXml, 'not a difficulty ranking'));
$emptyReport = examination_monitor_analytics_build_report([], [], []);
$emptyBytes = examination_monitor_analytics_build_xlsx_bytes('Empty Exam', 'All sections', [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], $emptyReport);
$emptyTmp = tempnam(sys_get_temp_dir(), 'aer_empty_');
file_put_contents($emptyTmp, $emptyBytes);
$emptyZip = new ZipArchive();
$emptyOpened = $emptyZip->open($emptyTmp) === true;
$emptySummary = $emptyOpened ? (string)$emptyZip->getFromName('xl/worksheets/sheet1.xml') : '';
$emptyCharts = $emptySummary;
$emptyNames = [];
if ($emptyOpened && preg_match_all('/<sheet [^>]*name="([^"]+)"/', (string)$emptyZip->getFromName('xl/workbook.xml'), $emptyFound)) {
    $emptyNames = $emptyFound[1];
}
$emptyHasChart = $emptyOpened && $emptyZip->getFromName('xl/charts/chart1.xml') !== false;
if ($emptyOpened) {
    $emptyZip->close();
}
@unlink($emptyTmp);
aer_check($fails, $passes, 'empty scope omits charts and keeps the formulas', $emptyOpened && $emptyNames === ['Summary', 'Examinee Results', 'Topic Results', 'Question Analysis', 'Examinee Topic Details'] && str_contains($emptySummary, 'COUNTIF') && str_contains($emptySummary, 'All sections') && !str_contains($emptySummary, '<drawing ') && str_contains($emptyCharts, 'No assigned examinees') && str_contains($emptyCharts, 'no graded examinees') && !$emptyHasChart);

$pdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'Section A', [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], $many);
aer_check($fails, $passes, 'pdf is a performance report with bars', str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, 'Final examination results') && str_contains($pdf, 'Reconciliation difference') && str_contains($pdf, ' rg f') && str_contains($pdf, 'Question response statistics') && !str_contains($pdf, 'have not been finalized'));
$openPdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'All sections', [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], $report);
aer_check($fails, $passes, 'pdf warns when attempts are not finalized', str_contains($openPdf, 'Some examination attempts have not been finalized') && str_contains($openPdf, 'Review the roster before treating this report as final') && str_contains($openPdf, 'In progress'));
$warningSections = [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
];
$warningPdf = static function (string $status) use ($warningSections): string {
    $row = ['user_id' => 1, 'full_name' => 'Ada', 'attempt_status' => $status, 'correct_count' => 8, 'total_count' => 10, 'section' => 'A'];
    $built = examination_monitor_analytics_build_report([$row], [], []);

    return examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'All sections', $warningSections, [], $built);
};
$expiredOnlyPdf = $warningPdf('expired');
$submittedOnlyPdf = $warningPdf('submitted');
$absentOnlyPdf = $warningPdf('');
$progressOnlyPdf = $warningPdf('in_progress');
aer_check($fails, $passes, 'each stored status warns only while in progress', !str_contains($expiredOnlyPdf, 'have not been finalized') && !str_contains($submittedOnlyPdf, 'have not been finalized') && !str_contains($absentOnlyPdf, 'have not been finalized') && str_contains($progressOnlyPdf, 'have not been finalized'));
$longName = 'Ariadne ' . str_repeat('Longname', 20) . ' Tailmarker';
$wrapped = examination_monitor_analytics_pdf_wrap_lines($longName, 40);
$longest = 0;
foreach ($wrapped as $wrappedLine) {
    $longest = max($longest, strlen($wrappedLine));
}
$wrapReport = examination_monitor_analytics_build_report([
    ['user_id' => 90, 'full_name' => $longName, 'student_number' => '2026-0001', 'attempt_status' => 'submitted', 'correct_count' => 8, 'total_count' => 10, 'section' => 'A'],
], [], ['coverage' => ['submitted_attempts' => 2, 'attempts_without_snapshot' => 1]]);
$wrapPdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'Section A', [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], $wrapReport);
aer_check($fails, $passes, 'long pdf lines wrap without dropping the name', $longest <= 40 && str_replace(' ', '', implode('', $wrapped)) === str_replace(' ', '', $longName) && str_contains($wrapPdf, 'Ariadne') && str_contains($wrapPdf, 'Tailmarker') && str_contains($wrapPdf, 'partial') && !str_contains($wrapPdf, 'Tailmarker...'));

$insightPeople = static function (string $status, int $userId, string $name, ?int $correct, ?int $total, string $section = 'A'): array {
    return [
        'user_id' => $userId,
        'full_name' => $name,
        'attempt_status' => $status,
        'correct_count' => $correct,
        'total_count' => $total,
        'section' => $section,
    ];
};
$insightExaminees = static function (string $field, string $key, int $count, int $start): array {
    $rows = [];
    for ($offset = 0; $offset < $count; $offset++) {
        $rows[] = [
            $field => $key,
            'user_id' => $start + $offset,
            'answered' => 1,
        ];
    }

    return $rows;
};
$noGraded = examination_monitor_analytics_build_report([
    $insightPeople('submitted', 1, 'Cal', null, 0),
    $insightPeople('in_progress', 2, 'Dee', 10, 10),
    $insightPeople('expired', 3, 'Eve', 10, 10),
    $insightPeople('', 4, 'Fay', null, null),
], [], ['breakdown_mode' => 'subject_topic']);
$noGradedNames = array_map(static fn(array $row): string => (string)$row['full_name'], $noGraded['insights']['top_students']);
aer_check($fails, $passes, 'no graded students produce an empty ranking', (int)$noGraded['insights']['graded_count'] === 0 && $noGraded['insights']['top_students'] === [] && in_array('No examinees have graded results.', $noGraded['insights']['observations'], true) && $noGradedNames === [] && str_contains((string)$noGraded['insights']['top_note'], 'No graded students'));

$oneGraded = examination_monitor_analytics_build_report([
    $insightPeople('submitted', 1, 'Ada', 8, 10),
    $insightPeople('in_progress', 2, 'Dee', 10, 10),
], [], []);
aer_check($fails, $passes, 'one graded student is ranked alone', (int)$oneGraded['insights']['graded_count'] === 1 && count($oneGraded['insights']['top_students']) === 1 && (int)$oneGraded['insights']['top_students'][0]['rank'] === 1 && (string)$oneGraded['insights']['top_students'][0]['full_name'] === 'Ada' && (int)$oneGraded['insights']['top_students'][0]['correct'] === 8 && (int)$oneGraded['insights']['top_students'][0]['total'] === 10 && str_contains((string)$oneGraded['insights']['top_note'], 'Fewer than five') && in_array('Only 1 examinee has graded results.', $oneGraded['insights']['observations'], true) && str_contains(implode("\n", $oneGraded['insights']['observations']), 'The individual exam score was 80.0% (8 of 10), achieved by Ada.') && str_contains(implode("\n", $oneGraded['insights']['observations']), 'The graded sample is too small for a class-level conclusion.') && empty($oneGraded['charts']['scores']['included']));

$tiedRows = [];
for ($i = 1; $i <= 4; $i++) {
    $tiedRows[] = $insightPeople('submitted', $i, 'Tie' . $i, 9, 10);
}
$tiedRows[] = $insightPeople('submitted', 5, 'Tie5', 8, 10);
$tiedRows[] = $insightPeople('submitted', 6, 'Tie6', 8, 10);
$tiedRows[] = $insightPeople('submitted', 7, 'Low7', 7, 10);
$tiedReport = examination_monitor_analytics_build_report($tiedRows, [], []);
$tiedTop = $tiedReport['insights']['top_students'];
$tiedIds = array_map(static fn(array $row): int => (int)$row['user_id'], $tiedTop);
aer_check($fails, $passes, 'tied scores share a rank and stay inside the top cutoff', count($tiedTop) === 6 && $tiedIds === [1, 2, 3, 4, 5, 6] && (int)$tiedTop[0]['rank'] === 1 && (int)$tiedTop[3]['rank'] === 1 && (int)$tiedTop[4]['rank'] === 2 && (int)$tiedTop[5]['rank'] === 2 && str_contains((string)$tiedReport['insights']['top_note'], 'fifth score') && str_contains((string)$tiedReport['insights']['top_note'], 'Tied scores share a rank') && (int)$tiedReport['insights']['graded_count'] === 7);

$fewRows = [
    $insightPeople('submitted', 1, 'Ada', 9, 10),
    $insightPeople('submitted', 2, 'Bea', 7, 10),
    $insightPeople('submitted', 3, 'Cal', 6, 10),
];
$fewReport = examination_monitor_analytics_build_report($fewRows, [], []);
aer_check($fails, $passes, 'fewer than five graded students are all listed', count($fewReport['insights']['top_students']) === 3 && (int)$fewReport['insights']['graded_count'] === 3 && str_contains((string)$fewReport['insights']['top_note'], 'Fewer than five') && str_contains((string)$fewReport['insights']['top_note'], 'Ranked from 3 graded students'));
$manyTopIds = array_map(static fn(array $row): int => (int)$row['user_id'], $many['insights']['top_students']);
aer_check($fails, $passes, 'more than five graded students show the top five', count($many['insights']['top_students']) === 5 && (int)$many['insights']['graded_count'] === 12 && $manyTopIds === [1, 2, 3, 4, 5] && str_contains((string)$many['insights']['top_note'], 'Ranked from 12 graded students'));

$topicRows = [
    ['key' => 'low', 'name' => 'Cells', 'subject_name' => 'Science', 'accuracy' => 30.0, 'answered' => 20, 'unassigned' => false],
    ['key' => 'high', 'name' => 'Forces', 'subject_name' => 'Science', 'accuracy' => 90.0, 'answered' => 18, 'unassigned' => false],
    ['key' => 'mid', 'name' => 'Energy', 'subject_name' => 'Science', 'accuracy' => 55.0, 'answered' => 12, 'unassigned' => false],
    ['key' => 'tiny', 'name' => 'Tiny Sample', 'subject_name' => 'Science', 'accuracy' => 10.0, 'answered' => 2, 'unassigned' => false],
    ['key' => 'short', 'name' => 'Short Answers', 'subject_name' => 'Science', 'accuracy' => 99.0, 'answered' => 4, 'unassigned' => false],
];
$topicExaminees = array_merge(
    $insightExaminees('topic_key', 'low', 4, 1),
    $insightExaminees('topic_key', 'high', 5, 101),
    $insightExaminees('topic_key', 'mid', 3, 201),
    $insightExaminees('topic_key', 'tiny', 1, 301),
    $insightExaminees('topic_key', 'short', 8, 401)
);
$subjectRows = [
    ['key' => 'law', 'name' => 'Law', 'accuracy' => 20.0, 'answered' => 2, 'unassigned' => false],
    ['key' => 'math', 'name' => 'Math', 'accuracy' => 40.0, 'answered' => 20, 'unassigned' => false],
    ['key' => 'art', 'name' => 'Art', 'accuracy' => 80.0, 'answered' => 10, 'unassigned' => false],
];
$subjectExaminees = array_merge(
    $insightExaminees('subject_key', 'law', 1, 1),
    $insightExaminees('subject_key', 'math', 6, 11),
    $insightExaminees('subject_key', 'art', 4, 21)
);
$topicReport = examination_monitor_analytics_build_report([
    $insightPeople('submitted', 1, 'Ada', 8, 10),
], [], [
    'breakdown_mode' => 'subject_topic',
    'topics' => $topicRows,
    'subjects' => $subjectRows,
    'examinee_topics' => $topicExaminees,
    'examinee_subjects' => $subjectExaminees,
]);
$topicLowNames = array_map(static fn(array $row): string => (string)$row['name'], $topicReport['insights']['topics_low']);
$topicHighNames = array_map(static fn(array $row): string => (string)$row['name'], $topicReport['insights']['topics_high']);
$topicText = implode("\n", $topicReport['insights']['observations']) . "\n" . (string)$topicReport['insights']['topic_message'];
aer_check($fails, $passes, 'topic lists use stored accuracy and leave out small samples', $topicLowNames === ['Science · Cells'] && $topicHighNames === ['Science · Forces', 'Science · Energy'] && !in_array('Science · Tiny Sample', $topicLowNames, true) && !in_array('Science · Short Answers', $topicHighNames, true) && str_contains($topicText, 'Stored accuracy') && str_contains($topicText, 'too small') && str_contains($topicText, 'The lowest stored topic accuracy was 30.0%') && str_contains($topicText, 'Topic observations are based on 50 answered items from 12 examinees.') && !str_contains($topicText, 'weakness') && !str_contains($topicText, 'difficulty ranking'));
$subjectNotes = [];
foreach ($topicReport['insights']['subjects'] as $subjectRow) {
    $subjectNotes[(string)$subjectRow['name']] = (string)$subjectRow['note'];
}
$subjectObservation = implode("\n", $topicReport['insights']['observations']);
aer_check($fails, $passes, 'subject performance qualifies a small sample', isset($subjectNotes['Law'], $subjectNotes['Math'], $subjectNotes['Art']) && str_contains($subjectNotes['Law'], 'Limited sample') && $subjectNotes['Math'] === 'Lower stored accuracy.' && $subjectNotes['Art'] === '' && str_contains($subjectObservation, 'Descriptive data only: Law stored accuracy is 20.0%') && str_contains($subjectObservation, 'The sample is too small for a class-level conclusion.') && str_contains($subjectObservation, 'The lowest stored subject accuracy among groups that meet the sample rule was recorded in Math') && !str_contains($subjectObservation, 'Weak') && (int)$topicReport['insights']['subjects'][0]['answered'] === 2 && (int)$topicReport['insights']['subjects'][0]['examinees'] === 1);

$smallTopics = examination_monitor_analytics_build_report([], [], [
    'breakdown_mode' => 'subject_topic',
    'topics' => [
        ['key' => 'tiny', 'name' => 'Tiny Sample', 'accuracy' => 10.0, 'answered' => 2, 'examinee_count' => 1, 'unassigned' => false],
        ['key' => 'short', 'name' => 'Short Answers', 'accuracy' => 99.0, 'answered' => 4, 'examinee_count' => 8, 'unassigned' => false],
    ],
]);
$smallTopicText = (string)$smallTopics['insights']['topic_message'] . "\n" . implode("\n", $smallTopics['insights']['observations']);
aer_check($fails, $passes, 'insufficient topic samples are not ranked', $smallTopics['insights']['topics_low'] === [] && $smallTopics['insights']['topics_high'] === [] && str_contains($smallTopicText, 'too small') && !str_contains($smallTopicText, 'The lowest stored topic accuracy'));
$noTopics = examination_monitor_analytics_build_report([], [], [
    'breakdown_mode' => 'overall',
    'topics' => [['key' => 'low', 'name' => 'Cells', 'accuracy' => 30.0, 'answered' => 20, 'examinee_count' => 4, 'unassigned' => false]],
]);
aer_check($fails, $passes, 'no topic performance data stays unlabeled', $noTopics['insights']['topics_low'] === [] && $noTopics['insights']['topics_high'] === [] && str_contains((string)$noTopics['insights']['topic_message'], 'not available') && !str_contains((string)$noTopics['insights']['topic_message'], 'Cells'));
$emptyTopics = examination_monitor_analytics_build_report([], [], ['breakdown_mode' => 'subject_topic', 'topics' => []]);
aer_check($fails, $passes, 'an empty topic list says that no topic data is available', str_contains((string)$emptyTopics['insights']['topic_message'], 'No topic performance data'));

$manySubjects = [];
for ($i = 1; $i <= 7; $i++) {
    $manySubjects[] = ['key' => 's' . $i, 'name' => 'Subject ' . $i, 'accuracy' => 10.0 * $i, 'answered' => 10, 'examinee_count' => 4, 'unassigned' => false];
}
$subjectCap = examination_monitor_analytics_build_report([], [], ['breakdown_mode' => 'subject', 'subjects' => $manySubjects]);
aer_check($fails, $passes, 'subject insights stay within six rows', count($subjectCap['insights']['subjects']) === 6 && str_contains((string)$subjectCap['insights']['subject_message'], 'Showing 6 of 7'));

$longStudent = 'Ariadne Marguerite ' . str_repeat('Longname ', 8) . 'Endtoken';
$longTopic = 'Cellular respiration ' . str_repeat('and metabolism ', 6) . 'Tailtopic';
$longInsight = examination_monitor_analytics_build_report([
    $insightPeople('submitted', 90, $longStudent, 8, 10, 'North Campus Section'),
    $insightPeople('submitted', 91, 'Bea', 4, 10, 'North Campus Section'),
], [], [
    'breakdown_mode' => 'subject_topic',
    'topics' => [
        ['key' => 'long', 'name' => $longTopic, 'subject_name' => 'Biology', 'accuracy' => 40.0, 'answered' => 10, 'unassigned' => false],
        ['key' => 'short', 'name' => 'Cells', 'subject_name' => 'Biology', 'accuracy' => 80.0, 'answered' => 10, 'unassigned' => false],
    ],
    'examinee_topics' => array_merge(
        $insightExaminees('topic_key', 'long', 4, 1),
        $insightExaminees('topic_key', 'short', 4, 11)
    ),
]);
$longInsightPdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'Section A', [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], $longInsight);
$longInsightBytes = examination_monitor_analytics_build_xlsx_bytes('Sample Exam', 'Section A', [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], $longInsight);
$longTmp = tempnam(sys_get_temp_dir(), 'aer_insight_');
file_put_contents($longTmp, $longInsightBytes);
$longZip = new ZipArchive();
$longOpened = $longZip->open($longTmp) === true;
$longSummaryXml = $longOpened ? (string)$longZip->getFromName('xl/worksheets/sheet1.xml') : '';
$longChartXml = '';
if ($longOpened) {
    for ($chartNumber = 1; $chartNumber <= 12; $chartNumber++) {
        $part = $longZip->getFromName('xl/charts/chart' . $chartNumber . '.xml');
        if ($part !== false) {
            $longChartXml .= $part;
        }
    }
    $longZip->close();
}
@unlink($longTmp);
$insightShared = array_merge(
    [(string)$longInsight['insights']['top_note'], (string)$longInsight['insights']['topic_message']],
    $longInsight['insights']['observations']
);
$sharedPresent = $longOpened;
foreach ($insightShared as $sharedLine) {
    $head = substr($sharedLine, 0, 40);
    if ($sharedLine === '' || $head === '' || !str_contains($longSummaryXml, $sharedLine) || !str_contains($longInsightPdf, $head)) {
        $sharedPresent = false;
    }
}
aer_check($fails, $passes, 'long names stay intact in the pdf and workbook insights', $longOpened && str_contains($longInsightPdf, 'Ariadne') && str_contains($longInsightPdf, 'Endtoken') && str_contains($longInsightPdf, 'Cellular') && str_contains($longInsightPdf, 'Tailtopic') && str_contains($longSummaryXml, 'Endtoken') && str_contains($longSummaryXml, 'Tailtopic') && !str_contains($longInsightPdf, 'Endtoken...') && !str_contains($longInsightPdf, 'Tailtopic...') && str_contains($longSummaryXml, 'Performance insights') && str_contains($longSummaryXml, 'Top Graded Students') && !str_contains($longSummaryXml, 'Subject Insights') && strpos($longSummaryXml, 'Top Graded Students') < strpos($longSummaryXml, 'Topic Insights') && strpos($longInsightPdf, 'Top Graded Students') < strpos($longInsightPdf, 'Subject Insights') && strpos($longInsightPdf, 'Subject Insights') < strpos($longInsightPdf, 'Topic Insights') && str_contains($longSummaryXml, 'Recorded Correct Rate'));
aer_check($fails, $passes, 'pdf and workbook insights use the same observations', $sharedPresent && str_contains($longInsightPdf, 'Stored accuracy') && str_contains($longSummaryXml, 'not an independently verified historical result') && str_contains($longInsightPdf, 'not confirmed question difficulty') && str_contains($longSummaryXml, '<f>AB5</f>') && str_contains($longSummaryXml, 'AB5-(AB6+AB7+AB8+AB9+AB10+AB11)') && str_contains($longChartXml, 'Summary!$') && !str_contains($longSummaryXml, '<f>AB9</f>'));
aer_check($fails, $passes, 'empty dashboard states that no graded ranking is available', str_contains($emptySummary, 'No graded students are available for a ranking') && str_contains($emptySummary, 'No examinees have graded results.') && str_contains($emptySummary, 'COUNTIF'));

$gradedPeople = static function (int $count): array {
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'user_id' => $i,
            'full_name' => 'Student ' . $i,
            'email' => 'student' . $i . '@school.edu',
            'student_number' => 'S' . $i,
            'attempt_status' => 'submitted',
            'correct_count' => 8,
            'total_count' => 10,
            'section' => 'A',
            'inclusion' => 'Exam assignment',
        ];
    }

    return $rows;
};
$blankSections = [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => [],
    'examinee_topics' => [],
];
$passingPresentation = true;
foreach ([1, 2, 4] as $gradedCount) {
    $gradedReport = examination_monitor_analytics_build_report($gradedPeople($gradedCount), [], []);
    $gradedPdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'Section A', $blankSections, [], $gradedReport);
    $fraction = $gradedCount . ' passed / ' . $gradedCount . ' graded';
    $completion = 'Completion: ' . $gradedCount . ' of ' . $gradedCount . ' submitted';
    if ($gradedReport['summary']['passing_rate'] !== 100.0
        || !str_contains($gradedPdf, $fraction)
        || !str_contains($gradedPdf, 'too small for a class-level conclusion')
        || str_contains($gradedPdf, 'Passing rate: 100.0%')
        || !str_contains($gradedPdf, 'Individual Exam Score')
        || str_contains($gradedPdf, 'Average Exam Score')
        || str_contains($gradedPdf, 'Highest Exam Score')
        || str_contains($gradedPdf, 'Lowest Exam Score')
        || !str_contains($gradedPdf, $completion)
        || !empty($gradedReport['charts']['scores']['included'])) {
        $passingPresentation = false;
    }
}
$fiveReport = examination_monitor_analytics_build_report($gradedPeople(5), [], []);
$fivePdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'Section A', $blankSections, [], $fiveReport);
$onePdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'Section A', $blankSections, [], examination_monitor_analytics_build_report($gradedPeople(1), [], []));
aer_check($fails, $passes, 'passing rate stays a fraction below five graded examinees', $passingPresentation && $fiveReport['summary']['passing_rate'] === 100.0 && str_contains($fivePdf, 'Passing rate: 100.0% \\(5/5\\)') && !str_contains($fivePdf, '5 passed / 5 graded') && !empty($fiveReport['charts']['scores']['included']) && str_contains($onePdf, 'Individual Exam Score') && str_contains($onePdf, '80.0%') && !str_contains($onePdf, 'Highest Exam Score') && !str_contains($onePdf, 'Lowest Exam Score') && !str_contains($onePdf, 'Average Exam Score') && str_contains($fivePdf, 'Average Exam Score: 80.0%') && str_contains($fivePdf, 'Highest Exam Score:') && str_contains($fivePdf, 'Lowest Exam Score:') && !str_contains($fivePdf, 'Individual Exam Score'));
aer_check($fails, $passes, 'sample rule is five answered items and three examinees', !examination_monitor_analytics_insight_sample_is_sufficient(4, ['count' => 3, 'known' => true]) && examination_monitor_analytics_insight_sample_is_sufficient(5, ['count' => 3, 'known' => true]) && !examination_monitor_analytics_insight_sample_is_sufficient(5, ['count' => 2, 'known' => true]) && !examination_monitor_analytics_graded_sample_is_sufficient(4) && examination_monitor_analytics_graded_sample_is_sufficient(5));

$thresholdTopics = [
    ['key' => 'four', 'name' => 'Four Items', 'subject_name' => 'Science', 'accuracy' => 25.0, 'answered' => 4, 'examinees' => 3, 'unassigned' => false],
    ['key' => 'two', 'name' => 'Two Examinees', 'subject_name' => 'Science', 'accuracy' => 80.0, 'answered' => 8, 'examinees' => 2, 'unassigned' => false],
    ['key' => 'apple', 'name' => 'Apple', 'subject_name' => 'Science', 'accuracy' => 90.0, 'answered' => 6, 'examinees' => 4, 'unassigned' => false],
    ['key' => 'zebra', 'name' => 'Zebra', 'subject_name' => 'Science', 'accuracy' => 20.0, 'answered' => 6, 'examinees' => 4, 'unassigned' => false],
];
$thresholdReport = examination_monitor_analytics_build_report($gradedPeople(5), [], [
    'breakdown_mode' => 'subject_topic',
    'topics' => $thresholdTopics,
    'subjects' => [
        ['key' => 'small', 'name' => 'Small', 'accuracy' => 10.0, 'answered' => 4, 'examinees' => 4, 'unassigned' => false],
        ['key' => 'ready', 'name' => 'Ready', 'accuracy' => 70.0, 'answered' => 5, 'examinees' => 3, 'unassigned' => false],
    ],
]);
$thresholdLow = array_map(static fn(array $row): string => (string)$row['name'], $thresholdReport['insights']['topics_low']);
aer_check($fails, $passes, 'charts and labels follow the shared sample rule', empty($thresholdReport['charts']['topics']['included']) === false && $thresholdReport['charts']['topics']['labels'] === ['Science · Apple', 'Science · Zebra'] && !in_array('Science · Four Items', $thresholdReport['charts']['topics']['labels'], true) && !in_array('Science · Two Examinees', $thresholdReport['charts']['topics']['labels'], true) && $thresholdReport['charts']['subjects']['labels'] === ['Ready'] && empty($thresholdReport['charts']['subjects']['included']) === false && $thresholdLow === ['Science · Zebra'] && !str_contains(implode(' ', $thresholdLow), 'Four Items') && str_contains((string)$thresholdReport['charts']['topics']['note'], 'alphabetical') && str_contains((string)$thresholdReport['charts']['subjects']['note'], 'stored scoring data'));

$accuracyRows = [
    ['question_id' => 1, 'user_id' => 1, 'full_name' => 'Ada', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'A', 'stored_is_correct' => 1, 'stem' => 'One'],
    ['question_id' => 1, 'user_id' => 2, 'full_name' => 'Bea', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'B', 'stored_is_correct' => 0, 'stem' => 'One'],
    ['question_id' => 1, 'user_id' => 3, 'full_name' => 'Cal', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'C', 'stored_is_correct' => null, 'stem' => 'One'],
    ['question_id' => 1, 'user_id' => 4, 'full_name' => 'Dee', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'D', 'stored_is_correct' => '', 'stem' => 'One'],
    ['question_id' => 1, 'user_id' => 5, 'full_name' => 'Eve', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'A', 'stored_is_correct' => 'yes', 'stem' => 'One'],
    ['question_id' => 1, 'user_id' => 6, 'full_name' => 'Fay', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => 'B', 'stored_is_correct' => 2, 'stem' => 'One'],
    ['question_id' => 1, 'user_id' => 7, 'full_name' => 'Gio', 'subject_name' => 'Math', 'topic_name' => 'Algebra', 'selected' => '', 'stored_is_correct' => 0, 'stem' => 'One'],
];
$accuracy = examination_monitor_analytics_export_accuracy($accuracyRows);
$accuracyQuestion = $accuracy['questions'][0];
$limitedTopic = examination_monitor_analytics_export_accuracy([
    ['question_id' => 8, 'user_id' => 1, 'subject_name' => 'Math', 'topic_name' => 'Cash', 'selected' => 'A', 'stored_is_correct' => 1],
    ['question_id' => 9, 'user_id' => 1, 'subject_name' => 'Math', 'topic_name' => 'Cash', 'selected' => 'B', 'stored_is_correct' => 0],
    ['question_id' => 10, 'user_id' => 1, 'subject_name' => 'Math', 'topic_name' => 'Cash', 'selected' => 'C', 'stored_is_correct' => 0],
]);
aer_check($fails, $passes, 'null and invalid correctness flags reconcile without becoming incorrect', (int)$accuracyQuestion['answered'] === 6 && (int)$accuracyQuestion['correct'] === 1 && (int)$accuracyQuestion['incorrect'] === 1 && (int)$accuracyQuestion['excluded'] === 4 && (int)$accuracyQuestion['unanswered'] === 1 && !empty($accuracyQuestion['reconciles']) && $accuracyQuestion['accuracy'] === 50.0 && (string)$limitedTopic['topics'][0]['sample'] === 'Limited sample' && empty($limitedTopic['topics'][0]['weak']) && !str_contains(examination_monitor_analytics_class_matrix($limitedTopic['topics'], true)[0][11] ?? '', 'Weak'));

$reviewRows = [
    ['user_id' => 1, 'full_name' => 'Audience Test A', 'email' => 'a@example.test', 'student_number' => '2026-003', 'attempt_status' => '', 'section' => 'A', 'inclusion' => 'Exam assignment'],
    ['user_id' => 2, 'full_name' => 'QA 322a Verify', 'email' => 'b@ereview.invalid', 'student_number' => '2026-011', 'attempt_status' => '', 'section' => 'A', 'inclusion' => 'Exam assignment'],
    ['user_id' => 3, 'full_name' => 'Undergrad A', 'email' => 'c@qa.local', 'student_number' => '2026-001', 'attempt_status' => '', 'section' => 'A', 'inclusion' => 'Existing attempt'],
    ['user_id' => 4, 'full_name' => 'Jake Carillaga', 'email' => 'jake@gmail.com', 'student_number' => '2026-010', 'attempt_status' => '', 'section' => 'A', 'inclusion' => 'Exam assignment'],
    ['user_id' => 5, 'full_name' => 'Kezeah Mae Taclan', 'email' => 'one@gmail.com', 'student_number' => '', 'attempt_status' => '', 'section' => 'a', 'inclusion' => 'Assignment and attempt'],
    ['user_id' => 6, 'full_name' => 'Kezeah Mae Taclan', 'email' => 'two@gmail.com', 'student_number' => '2026-009', 'attempt_status' => '', 'section' => 'a', 'inclusion' => 'Exam assignment'],
    ['user_id' => 7, 'full_name' => 'Other Person', 'email' => 'other@gmail.com', 'student_number' => '2026-009', 'attempt_status' => 'submitted', 'correct_count' => 5, 'total_count' => 10, 'section' => 'a', 'inclusion' => 'Exam assignment'],
];
$reviewReport = examination_monitor_analytics_apply_roster_review(examination_monitor_analytics_normalize_examinees($reviewRows));
$reviewById = [];
foreach ($reviewReport as $reviewRow) {
    $reviewById[(int)$reviewRow['user_id']] = (string)$reviewRow['review'];
}
$reviewBuilt = examination_monitor_analytics_build_report($reviewRows, [], []);
aer_check($fails, $passes, 'roster review flags do not remove assigned examinees', count($reviewReport) === 7 && (int)$reviewBuilt['summary']['counts']['assigned'] === 7 && str_contains($reviewById[1], 'Review generated account') && str_contains($reviewById[2], 'Review generated account') && str_contains($reviewById[3], 'Review generated account') && $reviewById[4] === '' && str_contains($reviewById[5], 'Review duplicate name') && str_contains($reviewById[5], 'Review missing student number') && str_contains($reviewById[6], 'Review duplicate name') && str_contains($reviewById[6], 'Review duplicate student number') && str_contains($reviewById[7], 'Review duplicate student number') && !examination_monitor_analytics_generated_account_flag('Jake Carillaga', 'jake@gmail.com') && str_contains(implode("\n", $reviewBuilt['insights']['observations']), 'remain in the assigned count'));

$statusPdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'All sections', $blankSections, [], $report);
$zeroStatusPdf = examination_monitor_analytics_build_pdf_bytes('Sample Exam', 'Section A', $blankSections, [], $many);
$statusLabels = ['Passed', 'Failed', 'Submitted, not graded', 'In progress', 'Expired', 'Absent'];
$statusPresent = str_contains($summaryXml, 'Passed') && str_contains($summaryXml, 'Failed') && str_contains($summaryXml, 'Submitted, not graded') && str_contains($summaryXml, 'In progress') && str_contains($summaryXml, 'Expired') && str_contains($summaryXml, 'Absent');
foreach ($statusLabels as $statusLabel) {
    if (!str_contains($statusPdf, $statusLabel . ': ') || !str_contains($zeroStatusPdf, $statusLabel . ': ')) {
        $statusPresent = false;
    }
}
aer_check($fails, $passes, 'pdf and excel keep all six statuses including zeros', $statusPresent && str_contains($zeroStatusPdf, 'Expired: 0') && str_contains($zeroStatusPdf, 'In progress: 0') && str_contains($statusPdf, 'Expired: 1') && str_contains($openPdf, 'In progress: 1') && str_contains($questionSheetXml, 'shortened to 110 characters'));

$itemAnalyticsSource = (string)file_get_contents(dirname(__DIR__) . '/examination/includes/examination_monitor_item_analytics.php');
aer_check($fails, $passes, 'live item analytics stays separate from the export accuracy path', str_contains($itemAnalyticsSource, 'IFNULL(ans.is_correct,0)') && str_contains($exportSource, 'examination_monitor_analytics_export_accuracy') && str_contains($exportSource, 'examination_pure_assigned_user_ids') && !str_contains($exportSource, 'professor_admin_filter_student_ids'));

$pdfEndpoint = (string)file_get_contents(dirname(__DIR__) . '/examination/professor/professor_examination_monitor_analytics_pdf.php');
$xlsxEndpoint = (string)file_get_contents(dirname(__DIR__) . '/examination/professor/professor_examination_monitor_analytics_xlsx.php');
$reportArgument = "is_array(\$pack['report'] ?? null) ? \$pack['report'] : []";
$endpointAuthorized = static function (string $source, string $outputCall) use ($reportArgument): bool {
    return str_contains($source, "requireRole('professor_admin')")
        && str_contains($source, 'examination_monitor_analytics_export_context')
        && str_contains($source, $outputCall)
        && str_contains($source, $reportArgument)
        && !str_contains($source, 'professor_admin_permissions.php')
        && !str_contains($source, "professor_admin_require(\$conn, 'results.export')");
};
aer_check($fails, $passes, 'authorization and delegated scope assumptions stay in place', $endpointAuthorized($pdfEndpoint, 'examination_monitor_output_analytics_pdf') && $endpointAuthorized($xlsxEndpoint, 'examination_monitor_output_analytics_xlsx') && str_contains($exportSource, 'examination_monitor_college_reporting_user_ids') && !str_contains($summarySource, 'mysqli_') && !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[a-z0-9_`]|DELETE\s+FROM|REPLACE\s+INTO)\b/i', $summarySource . $exportSource . $workbookSource));

$belowHalf = examination_monitor_analytics_classify_examinee('submitted', 4, 10);
$reinforcement = examination_monitor_analytics_classify_examinee('submitted', 6, 10);
$satisfactory = examination_monitor_analytics_classify_examinee('submitted', 8, 10);
$evenPass = examination_monitor_analytics_classify_examinee('submitted', 5, 10);
$oddFail = examination_monitor_analytics_classify_examinee('submitted', 1, 3);
$oddPass = examination_monitor_analytics_classify_examinee('submitted', 2, 3);
$labelOk = examination_monitor_analytics_result_label($belowHalf) === 'Failed'
    && examination_monitor_analytics_result_label($reinforcement) === 'Passed – Needs Reinforcement'
    && examination_monitor_analytics_result_label($satisfactory) === 'Passed – Satisfactory'
    && examination_monitor_analytics_result_label($evenPass) === 'Passed – Needs Reinforcement'
    && examination_monitor_analytics_result_label($oddFail) === 'Failed'
    && examination_monitor_analytics_result_label($oddPass) === 'Passed – Needs Reinforcement'
    && !str_contains(examination_monitor_analytics_result_label($belowHalf), 'Passed')
    && !str_contains(examination_monitor_analytics_result_label($oddFail), 'Passed');
$bandOk = examination_monitor_analytics_rate_band(39.9, false) === ''
    && examination_monitor_analytics_rate_band(39.9, true) === 'C'
    && examination_monitor_analytics_rate_band(40.0, true) === 'N'
    && examination_monitor_analytics_rate_band(49.9, true) === 'N'
    && examination_monitor_analytics_rate_band(50.0, true) === 'D'
    && examination_monitor_analytics_rate_band(69.9, true) === 'D'
    && examination_monitor_analytics_rate_band(70.0, true) === 'G';
aer_check($fails, $passes, 'result colors follow the official pass rule and do not call a failure passed', $labelOk && $bandOk && $belowHalf['mark'] === 'Fail' && $evenPass['mark'] === 'Pass' && $oddPass['mark'] === 'Pass' && $oddFail['mark'] === 'Fail');

$lecturerPeople = [
    ['user_id' => 1, 'full_name' => 'Ada Santos', 'student_number' => '2026-001', 'attempt_status' => 'submitted', 'correct_count' => 8, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 2, 'full_name' => 'Ada Santos', 'student_number' => '', 'attempt_status' => 'submitted', 'correct_count' => 6, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 3, 'full_name' => 'Cal Reyes', 'student_number' => '2026-003', 'attempt_status' => 'submitted', 'correct_count' => 4, 'total_count' => 10, 'section' => 'B'],
    ['user_id' => 4, 'full_name' => 'Dee Cruz', 'student_number' => '2026-004', 'attempt_status' => 'submitted', 'correct_count' => null, 'total_count' => 0, 'section' => 'B'],
    ['user_id' => 5, 'full_name' => 'Eve Lim', 'student_number' => '2026-005', 'attempt_status' => 'in_progress', 'correct_count' => 3, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 6, 'full_name' => 'Fay Ong', 'student_number' => '2026-006', 'attempt_status' => 'expired', 'correct_count' => 2, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 7, 'full_name' => 'Guy Paz', 'student_number' => '2026-007', 'attempt_status' => '', 'correct_count' => null, 'total_count' => null, 'section' => 'B'],
];
$lecturerTopic = static function (string $name, string $rate, string $sample, int $excluded = 0): array {
    return [
        'name' => $name,
        'subject_name' => 'FAR',
        'presented' => 10,
        'answered' => 8,
        'correct' => 4,
        'incorrect' => 3,
        'unanswered' => 2,
        'excluded' => $excluded,
        'examinees' => '3',
        'accuracy_label' => $rate,
        'incorrect_rate_label' => '20.0%',
        'sample' => $sample,
        'unassigned' => false,
    ];
};
$lecturerAna = [
    'subjects' => [['name' => 'FAR', 'subject_name' => 'FAR', 'unassigned' => false, 'presented' => 10, 'answered' => 8, 'correct' => 6, 'incorrect' => 2, 'unanswered' => 1, 'excluded' => 1, 'examinees' => '3', 'accuracy_label' => '75.0%', 'incorrect_rate_label' => '25.0%', 'sample' => '']],
    'topics' => [
        $lecturerTopic('Critical cash', '30.0%', ''),
        $lecturerTopic('Weak leases', '40.0%', ''),
        $lecturerTopic('Fair bonds', '60.0%', ''),
        $lecturerTopic('Strong cash', '80.0%', ''),
        $lecturerTopic('Tiny sample', '90.0%', 'Limited sample'),
    ],
    'questions' => [[
        'question_id' => 500,
        'stem' => 'Which lease cost is capitalized in this saved attempt?',
        'subject_name' => 'FAR',
        'topic_name' => 'Weak leases',
        'presented' => 7,
        'answered' => 4,
        'correct' => 1,
        'incorrect' => 1,
        'unanswered' => 1,
        'excluded' => 2,
        'choices' => ['A' => 2, 'B' => 1, 'C' => 1, 'D' => 0],
        'accuracy_label' => '50.0%',
        'incorrect_rate_label' => '50.0%',
        'sample' => '',
    ]],
    'examinee_subjects' => [
        ['user_id' => 1, 'full_name' => 'Ada Santos', 'student_number' => '2026-001', 'section' => 'A', 'subject_name' => 'FAR', 'presented' => 10, 'answered' => 9, 'correct' => 8, 'incorrect' => 1, 'unanswered' => 1, 'excluded' => 0, 'accuracy_label' => '88.9%', 'exam_score_label' => '80.0%', 'sample' => 'Limited sample'],
        ['user_id' => 2, 'full_name' => 'Ada Santos', 'student_number' => '', 'section' => 'A', 'subject_name' => 'FAR', 'presented' => 10, 'answered' => 8, 'correct' => 6, 'incorrect' => 2, 'unanswered' => 1, 'excluded' => 1, 'accuracy_label' => '75.0%', 'exam_score_label' => '60.0%', 'sample' => 'Limited sample'],
        ['user_id' => 3, 'full_name' => 'Cal Reyes', 'student_number' => '2026-003', 'section' => 'B', 'subject_name' => 'FAR', 'presented' => 10, 'answered' => 10, 'correct' => 4, 'incorrect' => 6, 'unanswered' => 0, 'excluded' => 0, 'accuracy_label' => '40.0%', 'exam_score_label' => '40.0%', 'sample' => 'Limited sample'],
    ],
    'examinee_topics' => [
        ['user_id' => 1, 'full_name' => 'Ada Santos', 'section' => 'A', 'subject_name' => 'FAR', 'topic_name' => 'Strong cash', 'presented' => 4, 'answered' => 4, 'correct' => 4, 'incorrect' => 0, 'unanswered' => 0, 'excluded' => 0, 'accuracy_label' => '100.0%', 'exam_score_label' => '80.0%', 'sample' => 'Limited sample'],
        ['user_id' => 3, 'full_name' => 'Cal Reyes', 'section' => 'B', 'subject_name' => 'FAR', 'topic_name' => 'Weak leases', 'presented' => 4, 'answered' => 4, 'correct' => 1, 'incorrect' => 3, 'unanswered' => 0, 'excluded' => 0, 'accuracy_label' => '25.0%', 'exam_score_label' => '40.0%', 'sample' => 'Limited sample'],
    ],
];
$itemCounts = examination_monitor_analytics_examinee_item_counts($lecturerAna);
$reconcileOk = $itemCounts[1]['correct'] === 8 && $itemCounts[1]['incorrect'] === 1 && $itemCounts[1]['unanswered'] === 1 && $itemCounts[1]['excluded'] === 0
    && ($itemCounts[1]['correct'] + $itemCounts[1]['incorrect'] + $itemCounts[1]['unanswered'] + $itemCounts[1]['excluded']) === 10
    && $itemCounts[2]['incorrect'] === 2 && $itemCounts[2]['excluded'] === 1
    && ($itemCounts[2]['correct'] + $itemCounts[2]['incorrect'] + $itemCounts[2]['unanswered'] + $itemCounts[2]['excluded']) === 10;
$lowReport = examination_monitor_analytics_build_report($lecturerPeople, [], ['breakdown_mode' => 'subject_topic', 'topics' => []]);
$lowReport['difficulty'] = ['note' => 'Snapshot statistics count selected responses only.', 'questions' => [['question_id' => 500, 'other' => 3]]];
$lowBytes = examination_monitor_analytics_build_xlsx_bytes('Low Sample', 'All sections', $lecturerAna, [], $lowReport);
$lowPath = tempnam(sys_get_temp_dir(), 'aer_low_');
file_put_contents($lowPath, $lowBytes);
$lowZip = new ZipArchive();
$lowOpened = $lowZip->open($lowPath) === true;
$lowNames = [];
$lowSummary = '';
$lowRoster = '';
$lowTopics = '';
$lowQuestions = '';
$lowExamineeTopics = '';
$lowStyles = '';
if ($lowOpened) {
    if (preg_match_all('/<sheet [^>]*name="([^"]+)"/', (string)$lowZip->getFromName('xl/workbook.xml'), $lowFound)) {
        $lowNames = $lowFound[1];
    }
    $lowSummary = (string)$lowZip->getFromName('xl/worksheets/sheet1.xml');
    $lowRoster = (string)$lowZip->getFromName('xl/worksheets/sheet2.xml');
    $lowTopics = (string)$lowZip->getFromName('xl/worksheets/sheet3.xml');
    $lowQuestions = (string)$lowZip->getFromName('xl/worksheets/sheet4.xml');
    $lowExamineeTopics = (string)$lowZip->getFromName('xl/worksheets/sheet5.xml');
    $lowStyles = (string)$lowZip->getFromName('xl/styles.xml');
    $lowZip->close();
}
@unlink($lowPath);
$lowSheetsOk = $lowNames === ['Summary', 'Examinee Results', 'Topic Results', 'Question Analysis', 'Examinee Topic Details'];
$lowRosterOk = substr_count($lowRoster, 'Ada Santos') === 2
    && str_contains($lowRoster, 'Cal Reyes')
    && str_contains($lowRoster, 'Dee Cruz')
    && str_contains($lowRoster, 'Eve Lim')
    && str_contains($lowRoster, 'Fay Ong')
    && str_contains($lowRoster, 'Guy Paz')
    && str_contains($lowRoster, 'Absent')
    && str_contains($lowRoster, 'In progress')
    && str_contains($lowRoster, 'Expired')
    && str_contains($lowRoster, 'Submitted, not graded')
    && str_contains($lowRoster, 'Passed – Satisfactory')
    && str_contains($lowRoster, 'Passed – Needs Reinforcement')
    && str_contains($lowRoster, 'Failed')
    && str_contains($lowRoster, 'not counted as incorrect')
    && !str_contains($lowRoster, 'Inclusion')
    && !str_contains($lowRoster, '>Review<')
    && str_contains($lowRoster, 'Needs Reinforcement')
    && str_contains($lowRoster, 'dxfId="8"')
    && str_contains($lowRoster, 'dxfId="9"')
    && str_contains($lowRoster, 'dxfId="10"');
$lowTopicOk = str_contains($lowTopics, 'Recorded Correct Rate')
    && str_contains($lowTopics, 'Questions in Exam')
    && str_contains($lowTopics, 'Examinees Represented')
    && str_contains($lowTopics, '>Subject<')
    && str_contains($lowTopics, 'Priority')
    && str_contains($lowTopics, 'Lecturer&apos;s Focus')
    && str_contains($lowTopics, 'CRITICAL')
    && str_contains($lowTopics, 'NEEDS IMPROVEMENT')
    && str_contains($lowTopics, 'DEVELOPING')
    && str_contains($lowTopics, '>GOOD<')
    && str_contains($lowTopics, 'Insufficient Sample')
    && str_contains($lowTopics, 'Weak leases')
    && str_contains($lowTopics, 'Tiny sample')
    && !str_contains($lowTopics, '>Sample<')
    && !str_contains($lowTopics, 'Limited sample')
    && !str_contains($lowTopics, 'Stored incorrect rate')
    && str_contains($lowTopics, 'hidden="1"')
    && str_contains($lowTopics, 'dxfId="8"')
    && str_contains($lowTopics, 'dxfId="11"')
    && str_contains($lowTopics, 'dxfId="9"')
    && str_contains($lowTopics, 'dxfId="10"')
    && str_contains($lowStyles, 'FFF8D7D7')
    && str_contains($lowStyles, 'FFFED7AA')
    && str_contains($lowStyles, 'FFFEF3C7')
    && str_contains($lowStyles, 'FFDCFCE7');
$lowQuestionOk = str_contains($lowQuestions, 'Report No.')
    && str_contains($lowQuestions, 'list sequence')
    && !str_contains($lowQuestions, 'Question No.')
    && str_contains($lowQuestions, 'Which lease cost is capitalized')
    && str_contains($lowQuestions, 'Choice A')
    && str_contains($lowQuestions, 'Other choice')
    && str_contains($lowQuestions, 'selected responses')
    && str_contains($lowQuestions, 'not a database id')
    && str_contains($lowQuestions, 'not counted as incorrect')
    && !str_contains($lowQuestions, '>500<')
    && !str_contains($lowQuestions, 'Stored incorrect rate');
$lowSummaryOk = str_contains($lowSummary, 'COLLEGE EXAMINATION | PERFORMANCE DASHBOARD')
    && str_contains($lowSummary, 'QUICK READ')
    && str_contains($lowSummary, 'Students Assigned')
    && str_contains($lowSummary, 'Overall Topic Accuracy')
    && str_contains($lowSummary, 'Topic Accuracy (%)')
    && str_contains($lowSummary, 'Not reported')
    && str_contains($lowSummary, 'Pass rate is not displayed because fewer than five examinees have graded results.')
    && str_contains($lowSummary, 'Individual Examination Results')
    && str_contains($lowSummary, 'Insufficient Sample')
    && !str_contains($lowSummary, 'passed / ')
    && !str_contains($lowSummary, 'Individual Exam Score')
    && !str_contains($lowSummary, 'Each bar is one graded examinee')
    && !str_contains($lowSummary, 'Roster rows for review');
$lowPersonOk = str_contains($lowExamineeTopics, 'Ada Santos')
    && str_contains($lowExamineeTopics, 'Cal Reyes')
    && str_contains($lowExamineeTopics, 'Recorded Correct Rate')
    && str_contains($lowExamineeTopics, 'Performance Interpretation')
    && str_contains($lowExamineeTopics, 'Lecturer&apos;s Focus')
    && str_contains($lowExamineeTopics, 'Student–Topic Heatmap')
    && !str_contains($lowExamineeTopics, 'Exam score')
    && !str_contains($lowExamineeTopics, '>Sample<')
    && str_contains($lowExamineeTopics, 'not a class conclusion')
    && str_contains($lowExamineeTopics, 'dxfId="8"')
    && str_contains($lowExamineeTopics, 'dxfId="10"');
aer_check($fails, $passes, 'low-sample lecturer workbook keeps every examinee and withholds class percentages', $lowOpened && $reconcileOk && $lowSheetsOk && $lowRosterOk && $lowTopicOk && $lowQuestionOk && $lowSummaryOk && $lowPersonOk);

$qualifiedPeople = [];
for ($qualifiedIndex = 1; $qualifiedIndex <= 5; $qualifiedIndex++) {
    $qualifiedPeople[] = ['user_id' => $qualifiedIndex, 'full_name' => 'Qualified ' . $qualifiedIndex, 'student_number' => 'Q-' . $qualifiedIndex, 'attempt_status' => 'submitted', 'correct_count' => 8, 'total_count' => 10, 'section' => $qualifiedIndex < 3 ? 'A' : 'Section B'];
}
$qualifiedReport = examination_monitor_analytics_build_report($qualifiedPeople, [], []);
$qualifiedBytes = examination_monitor_analytics_build_xlsx_bytes('Qualified Sample', 'All sections', $lecturerAna, [], $qualifiedReport);
$qualifiedPath = tempnam(sys_get_temp_dir(), 'aer_qual_');
file_put_contents($qualifiedPath, $qualifiedBytes);
$qualifiedZip = new ZipArchive();
$qualifiedOpened = $qualifiedZip->open($qualifiedPath) === true;
$qualifiedNames = [];
if ($qualifiedOpened && preg_match_all('/<sheet [^>]*name="([^"]+)"/', (string)$qualifiedZip->getFromName('xl/workbook.xml'), $qualifiedFound)) {
    $qualifiedNames = $qualifiedFound[1];
}
if ($qualifiedOpened) {
    $qualifiedZip->close();
}
@unlink($qualifiedPath);
$multiAna = $lecturerAna;
$multiAna['subjects'][] = ['name' => 'Law', 'subject_name' => 'Law', 'unassigned' => false, 'presented' => 10, 'answered' => 8, 'correct' => 3, 'incorrect' => 5, 'unanswered' => 2, 'excluded' => 0, 'examinees' => '3', 'accuracy_label' => '37.5%', 'incorrect_rate_label' => '62.5%', 'sample' => ''];
$multiAna['topics'][] = ['name' => 'Torts', 'subject_name' => 'Law', 'unassigned' => false, 'presented' => 8, 'answered' => 6, 'correct' => 2, 'incorrect' => 4, 'unanswered' => 2, 'excluded' => 0, 'examinees' => '3', 'accuracy_label' => '33.3%', 'incorrect_rate_label' => '66.7%', 'sample' => ''];
$multiBytes = examination_monitor_analytics_build_xlsx_bytes('Multi Subject', 'All sections', $multiAna, [], $qualifiedReport);
$multiPath = tempnam(sys_get_temp_dir(), 'aer_multi_');
file_put_contents($multiPath, $multiBytes);
$multiZip = new ZipArchive();
$multiOpened = $multiZip->open($multiPath) === true;
$multiNames = [];
$multiSubjects = '';
$multiExamineeSubjects = '';
if ($multiOpened) {
    if (preg_match_all('/<sheet [^>]*name="([^"]+)"/', (string)$multiZip->getFromName('xl/workbook.xml'), $multiFound)) {
        $multiNames = $multiFound[1];
    }
    $multiSubjects = (string)$multiZip->getFromName('xl/worksheets/sheet3.xml');
    $multiExamineeSubjects = (string)$multiZip->getFromName('xl/worksheets/sheet6.xml');
    $multiZip->close();
}
@unlink($multiPath);
aer_check($fails, $passes, 'qualified and multi-subject workbooks keep rankings and lecturer subject sheets', $qualifiedOpened && $qualifiedNames === ['Summary', 'Examinee Results', 'Topic Results', 'Question Analysis', 'Examinee Topic Details', 'Rankings'] && $multiOpened && $multiNames === ['Summary', 'Examinee Results', 'Subjects', 'Topic Results', 'Question Analysis', 'Examinee Subjects', 'Examinee Topic Details', 'Rankings'] && str_contains($multiSubjects, 'Recorded Correct Rate') && str_contains($multiSubjects, 'Law') && str_contains($multiExamineeSubjects, 'Examinee Name') && !str_contains($multiExamineeSubjects, 'Exam score'));

$positionResponse = static function (int $questionId, int $userId, int $position, int $correct): array {
    return [
        'question_id' => $questionId,
        'user_id' => $userId,
        'subject_name' => 'FAR',
        'topic_name' => 'Cash',
        'selected' => 'A',
        'stored_is_correct' => $correct,
        'display_position' => $position,
        'full_name' => 'Ada',
        'stem' => $questionId === 901 ? 'Earlier on the paper' : 'Later on the paper',
    ];
};
$sharedAccuracy = examination_monitor_analytics_export_accuracy([
    $positionResponse(900, 1, 5, 1),
    $positionResponse(900, 2, 5, 0),
    $positionResponse(901, 1, 2, 1),
    $positionResponse(901, 2, 2, 1),
]);
$sharedById = [];
foreach ($sharedAccuracy['questions'] as $sharedQuestion) {
    $sharedById[(int)$sharedQuestion['question_id']] = $sharedQuestion;
}
$mixedAccuracy = examination_monitor_analytics_export_accuracy([
    $positionResponse(900, 1, 5, 1),
    $positionResponse(900, 2, 8, 0),
    $positionResponse(901, 1, 2, 1),
]);
$mixedPosition = 'missing';
foreach ($mixedAccuracy['questions'] as $mixedQuestion) {
    if ((int)$mixedQuestion['question_id'] === 900) {
        $mixedPosition = $mixedQuestion['display_position'];
    }
}
$orderBytes = examination_monitor_analytics_build_xlsx_bytes('Display Order', 'All sections', [
    'subjects' => [],
    'topics' => [],
    'questions' => $sharedAccuracy['questions'],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], examination_monitor_analytics_build_report([], [], []));
$orderPath = tempnam(sys_get_temp_dir(), 'aer_order_');
file_put_contents($orderPath, $orderBytes);
$orderZip = new ZipArchive();
$orderOpened = $orderZip->open($orderPath) === true;
$orderQuestions = $orderOpened ? (string)$orderZip->getFromName('xl/worksheets/sheet4.xml') : '';
if ($orderOpened) {
    $orderZip->close();
}
@unlink($orderPath);
$aerSheetRow = static function (string $xml, string $needle): string {
    foreach (explode('<row ', $xml) as $chunk) {
        if ($chunk === '' || !str_contains($chunk, $needle)) {
            continue;
        }
        $end = strpos($chunk, '</row>');

        return $end === false ? '' : substr($chunk, 0, $end);
    }

    return '';
};
$aerNumber = static function (string $row, string $column): ?int {
    if (!preg_match('/<c r="' . $column . '\d+"[^>]*><v>(-?\d+)<\/v>/', $row, $found)) {
        return null;
    }

    return (int)$found[1];
};
$earlierRow = $aerSheetRow($orderQuestions, 'Earlier on the paper');
$laterRow = $aerSheetRow($orderQuestions, 'Later on the paper');
$earlierAt = strpos($orderQuestions, 'Earlier on the paper');
$laterAt = strpos($orderQuestions, 'Later on the paper');
aer_check($fails, $passes, 'question numbers follow a shared display position and are not database ids', $orderOpened && (int)($sharedById[900]['display_position'] ?? 0) === 5 && (int)($sharedById[901]['display_position'] ?? 0) === 2 && $mixedPosition === null && $earlierAt !== false && $laterAt !== false && $earlierAt < $laterAt && !str_contains($orderQuestions, '>900<') && !str_contains($orderQuestions, '>901<') && str_contains($orderQuestions, 'original display position') && str_contains($orderQuestions, 'Question No.') && !str_contains($orderQuestions, 'Report No.') && $aerNumber($earlierRow, 'A') === 2 && $aerNumber($laterRow, 'A') === 5);

$mixedBytes = examination_monitor_analytics_build_xlsx_bytes('Mixed Positions', 'All sections', [
    'subjects' => [],
    'topics' => [],
    'questions' => $mixedAccuracy['questions'],
    'examinee_subjects' => [],
    'examinee_topics' => [],
], [], examination_monitor_analytics_build_report([], [], []));
$mixedPath = tempnam(sys_get_temp_dir(), 'aer_mixed_');
file_put_contents($mixedPath, $mixedBytes);
$mixedZip = new ZipArchive();
$mixedOpened = $mixedZip->open($mixedPath) === true;
$mixedQuestions = $mixedOpened ? (string)$mixedZip->getFromName('xl/worksheets/sheet4.xml') : '';
if ($mixedOpened) {
    $mixedZip->close();
}
@unlink($mixedPath);
$mixedEarlier = $aerSheetRow($mixedQuestions, 'Earlier on the paper');
$mixedLater = $aerSheetRow($mixedQuestions, 'Later on the paper');
$mixedEarlierAt = strpos($mixedQuestions, 'Earlier on the paper');
$mixedLaterAt = strpos($mixedQuestions, 'Later on the paper');
aer_check($fails, $passes, 'different saved positions use the report list and not database ids', $mixedOpened && $mixedLaterAt !== false && $mixedEarlierAt !== false && $mixedLaterAt < $mixedEarlierAt && str_contains($mixedQuestions, 'Report No.') && str_contains($mixedQuestions, 'list sequence') && !str_contains($mixedQuestions, 'Question No.') && !str_contains($mixedQuestions, '>900<') && !str_contains($mixedQuestions, '>901<') && $aerNumber($mixedLater, 'A') === 1 && $aerNumber($mixedEarlier, 'A') === 2);

$flagResponse = static function (int $questionId, string $selected, mixed $flag): array {
    return [
        'question_id' => $questionId,
        'user_id' => 43,
        'subject_name' => 'FAR',
        'topic_name' => 'Flags',
        'selected' => $selected,
        'stored_is_correct' => $flag,
        'display_position' => $questionId,
        'full_name' => 'Invalid Flags',
        'stem' => 'Flag item ' . $questionId,
    ];
};
$flagAccuracy = examination_monitor_analytics_export_accuracy([
    $flagResponse(1, 'A', null),
    $flagResponse(2, 'B', 2),
    $flagResponse(3, '', null),
    $flagResponse(4, 'C', 1),
]);
$gapAna = [
    'subjects' => [],
    'topics' => [],
    'questions' => [],
    'examinee_subjects' => array_merge([
        ['user_id' => 41, 'full_name' => 'Partial Rows', 'student_number' => 'P-41', 'section' => 'A', 'subject_name' => 'FAR', 'presented' => 4, 'answered' => 3, 'correct' => 2, 'incorrect' => 1, 'unanswered' => 1, 'excluded' => 0, 'accuracy_label' => '66.7%'],
    ], $flagAccuracy['examinee_subjects']),
    'examinee_topics' => array_merge([
        ['user_id' => 42, 'full_name' => 'Topic Only', 'student_number' => 'P-42', 'section' => 'A', 'subject_name' => 'FAR', 'topic_name' => 'Flags', 'presented' => 3, 'answered' => 2, 'correct' => 1, 'incorrect' => 0, 'unanswered' => 1, 'excluded' => 1, 'accuracy_label' => '100.0%'],
    ], $flagAccuracy['examinee_topics']),
];
$gapCounts = examination_monitor_analytics_examinee_item_counts($gapAna);
$gapPeople = [
    ['user_id' => 40, 'full_name' => 'No Items', 'student_number' => 'P-40', 'attempt_status' => 'submitted', 'correct_count' => 8, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 41, 'full_name' => 'Partial Rows', 'student_number' => 'P-41', 'attempt_status' => 'submitted', 'correct_count' => 7, 'total_count' => 10, 'section' => 'A'],
    ['user_id' => 42, 'full_name' => 'Topic Only', 'student_number' => 'P-42', 'attempt_status' => 'submitted', 'correct_count' => 2, 'total_count' => 3, 'section' => 'A'],
    ['user_id' => 43, 'full_name' => 'Invalid Flags', 'student_number' => 'P-43', 'attempt_status' => 'submitted', 'correct_count' => 3, 'total_count' => 4, 'section' => 'A'],
    ['user_id' => 44, 'full_name' => 'Official Fail', 'student_number' => 'P-44', 'attempt_status' => 'submitted', 'correct_count' => 4, 'total_count' => 10, 'section' => 'B'],
    ['user_id' => 45, 'full_name' => 'Absent Person', 'student_number' => 'P-45', 'attempt_status' => '', 'correct_count' => null, 'total_count' => null, 'section' => 'B'],
    ['user_id' => 46, 'full_name' => 'Still Working', 'student_number' => 'P-46', 'attempt_status' => 'in_progress', 'correct_count' => 1, 'total_count' => 10, 'section' => 'A'],
];
$gapReport = examination_monitor_analytics_build_report($gapPeople, [], []);
$gapBytes = examination_monitor_analytics_build_xlsx_bytes('Answer Gaps', 'All sections', $gapAna, [], $gapReport);
$gapPath = tempnam(sys_get_temp_dir(), 'aer_gap_');
file_put_contents($gapPath, $gapBytes);
$gapZip = new ZipArchive();
$gapOpened = $gapZip->open($gapPath) === true;
$gapRoster = $gapOpened ? (string)$gapZip->getFromName('xl/worksheets/sheet2.xml') : '';
if ($gapOpened) {
    $gapZip->close();
}
@unlink($gapPath);
$noItemsRow = $aerSheetRow($gapRoster, 'No Items');
$partialRow = $aerSheetRow($gapRoster, 'Partial Rows');
$topicRow = $aerSheetRow($gapRoster, 'Topic Only');
$invalidRow = $aerSheetRow($gapRoster, 'Invalid Flags');
$failRow = $aerSheetRow($gapRoster, 'Official Fail');
$absentRow = $aerSheetRow($gapRoster, 'Absent Person');
$workingRow = $aerSheetRow($gapRoster, 'Still Working');
$gapBlank = static function (string $row, string $column) use ($aerNumber): bool {
    return $aerNumber($row, $column) === null && !str_contains($row, 'Unavailable');
};
$gapCountsOk = !isset($gapCounts[40]) && !isset($gapCounts[44])
    && $gapCounts[41]['correct'] === 2 && $gapCounts[41]['incorrect'] === 1 && $gapCounts[41]['unanswered'] === 1 && $gapCounts[41]['presented'] === 4
    && $gapCounts[42]['correct'] === 1 && $gapCounts[42]['incorrect'] === 0 && $gapCounts[42]['unanswered'] === 1 && $gapCounts[42]['excluded'] === 1 && $gapCounts[42]['presented'] === 3
    && $gapCounts[43]['correct'] === 1 && $gapCounts[43]['incorrect'] === 0 && $gapCounts[43]['unanswered'] === 1 && $gapCounts[43]['excluded'] === 2 && $gapCounts[43]['presented'] === 4;
$gapSheetOk = $gapOpened
    && str_contains($gapRoster, 'A blank count means those rows were not found')
    && str_contains($gapRoster, 'not a score of zero')
    && str_contains($gapRoster, 'current question bank is not used')
    && str_contains($gapRoster, 'only part of the official question total')
    && str_contains($gapRoster, 'not counted as incorrect')
    && !str_contains($gapRoster, 'Unavailable')
    && $gapBlank($noItemsRow, 'F') && $gapBlank($noItemsRow, 'G') && $gapBlank($noItemsRow, 'H')
    && str_contains($noItemsRow, '<v>80</v>')
    && str_contains($noItemsRow, '<v>10</v>')
    && str_contains($noItemsRow, 'Passed – Satisfactory')
    && !str_contains($noItemsRow, '<v>8</v>')
    && !str_contains($noItemsRow, '<v>0</v>')
    && !str_contains($partialRow, 'Unavailable')
    && $aerNumber($partialRow, 'F') === 2 && $aerNumber($partialRow, 'G') === 1 && $aerNumber($partialRow, 'H') === 1 && $aerNumber($partialRow, 'I') === 10
    && str_contains($partialRow, '<v>70</v>')
    && str_contains($partialRow, 'Passed – Needs Reinforcement')
    && !str_contains($partialRow, '<v>6</v>') && !str_contains($partialRow, '<v>7</v>')
    && $aerNumber($topicRow, 'F') === 1 && $aerNumber($topicRow, 'G') === 0 && $aerNumber($topicRow, 'H') === 1 && $aerNumber($topicRow, 'I') === 3
    && str_contains($topicRow, '<v>66.7</v>')
    && str_contains($topicRow, 'Passed – Needs Reinforcement')
    && $aerNumber($invalidRow, 'F') === 1 && $aerNumber($invalidRow, 'G') === 0 && $aerNumber($invalidRow, 'H') === 1 && $aerNumber($invalidRow, 'I') === 4
    && str_contains($invalidRow, '<v>75</v>')
    && str_contains($invalidRow, 'Passed – Satisfactory')
    && $gapBlank($failRow, 'F') && $gapBlank($failRow, 'G') && $gapBlank($failRow, 'H')
    && $aerNumber($failRow, 'I') === 10
    && str_contains($failRow, '<v>40</v>')
    && str_contains($failRow, '>Failed<')
    && !str_contains($failRow, 'Passed')
    && str_contains($absentRow, '>Absent<')
    && $gapBlank($absentRow, 'F') && $gapBlank($absentRow, 'G') && $gapBlank($absentRow, 'H') && $gapBlank($absentRow, 'I')
    && $aerNumber($absentRow, 'J') === null
    && str_contains($workingRow, '>In progress<')
    && $gapBlank($workingRow, 'F') && $gapBlank($workingRow, 'G') && $gapBlank($workingRow, 'H') && $gapBlank($workingRow, 'I')
    && $aerNumber($workingRow, 'J') === null;
aer_check($fails, $passes, 'missing item rows stay unavailable and partial or invalid flags are not treated as incorrect', $gapCountsOk && $gapSheetOk);

echo "\n{$passes} passed, " . count($fails) . " failed\n";
exit($fails === [] ? 0 : 1);
