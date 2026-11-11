<?php
/**
 * Read-only lecturer analytics PDF/XLSX for regular college exams.
 * Does not write attempts, answers, snapshots, or review schedules.
 */
declare(strict_types=1);

require_once __DIR__ . '/exam_progress_report_pdf.php';
require_once __DIR__ . '/exam_progress_report_xlsx.php';
require_once __DIR__ . '/exam_monitor_progress_rows.php';
require_once __DIR__ . '/examination_monitor_item_analytics.php';
require_once __DIR__ . '/examination_monitor_analytics_summary.php';

/**
 * @return array{ok:bool,redirect?:string,ctx?:array,section_filter?:string,analytics?:array,progress_rows?:list<array<string,mixed>>,is_finished?:bool,report?:array}
 */
function examination_monitor_analytics_export_context(mysqli $conn, int $professorId, ?array $scope): array
{
    if ($scope === null || ($scope['exam_type'] ?? '') !== 'college_exam') {
        return ['ok' => false, 'redirect' => 'professor_examination_monitor'];
    }
    $ctx = examination_monitor_load_context($conn, $professorId, 'college_exam', (int)$scope['assessment_id']);
    if (!$ctx) {
        return ['ok' => false, 'redirect' => 'professor_examination_monitor'];
    }
    $resolvedSection = examination_monitor_resolve_college_section_filter(
        $conn,
        (int)$scope['assessment_id'],
        $_GET['section'] ?? ''
    );
    if (!empty($resolvedSection['invalid'])) {
        return [
            'ok' => false,
            'message' => examination_monitor_analytics_invalid_section_message(),
            'redirect' => examination_monitor_scoped_url(
                ['exam_type' => 'college_exam', 'assessment_id' => (int)$scope['assessment_id']],
                ''
            ),
        ];
    }
    $sectionFilter = (string)($resolvedSection['filter'] ?? '');
    $now = date('Y-m-d H:i:s');
    $metrics = examination_monitor_metrics($conn, $scope, $sectionFilter);
    $window = examination_monitor_running_finished($conn, $ctx, $metrics, $now);
    $examId = (int)$scope['assessment_id'];
    $ana = examination_monitor_item_analytics($conn, $examId, $sectionFilter);
    $responses = examination_monitor_analytics_load_question_responses($conn, $examId, $sectionFilter);
    $accuracy = examination_monitor_analytics_export_accuracy($responses);
    $stems = [];
    foreach (is_array($ana['questions'] ?? null) ? $ana['questions'] : [] as $question) {
        if (!is_array($question)) {
            continue;
        }
        $stems[(int)($question['question_id'] ?? 0)] = trim((string)($question['stem'] ?? ''));
    }
    foreach ($accuracy['questions'] as $index => $question) {
        $stem = $stems[(int)($question['question_id'] ?? 0)] ?? '';
        if ($stem !== '') {
            $accuracy['questions'][$index]['stem'] = $stem;
        }
    }
    $ana['subjects'] = $accuracy['subjects'];
    $ana['topics'] = $accuracy['topics'];
    $ana['questions'] = $accuracy['questions'];
    $ana['examinee_subjects'] = $accuracy['examinee_subjects'];
    $ana['examinee_topics'] = $accuracy['examinee_topics'];
    $progressRows = exam_monitor_progress_export_rows(
        $conn,
        (int)$ctx['assessment_id'],
        (int)$ctx['question_count'],
        !empty($window['is_finished']),
        $sectionFilter
    );
    $report = examination_monitor_analytics_build_report(
        examination_monitor_analytics_load_roster($conn, $examId, $sectionFilter),
        $responses,
        [
            'section_filter' => $sectionFilter,
            'section_invalid' => false,
            'coverage' => examination_monitor_analytics_load_snapshot_coverage($conn, $examId, $sectionFilter),
            'breakdown_mode' => (string)($ana['breakdown_mode'] ?? 'overall'),
            'subjects' => is_array($ana['subjects'] ?? null) ? $ana['subjects'] : [],
            'topics' => is_array($ana['topics'] ?? null) ? $ana['topics'] : [],
            'examinee_subjects' => is_array($ana['examinee_subjects'] ?? null) ? $ana['examinee_subjects'] : [],
            'examinee_topics' => is_array($ana['examinee_topics'] ?? null) ? $ana['examinee_topics'] : [],
        ]
    );

    return [
        'ok' => true,
        'ctx' => $ctx,
        'section_filter' => $sectionFilter,
        'analytics' => $ana,
        'progress_rows' => $progressRows,
        'is_finished' => !empty($window['is_finished']),
        'report' => $report,
    ];
}

/**
 * Read-only roster for the performance summary. Does not create or finalize attempts.
 *
 * @return list<array<string,mixed>>
 */
function examination_monitor_analytics_load_roster(mysqli $conn, int $examId, string $sectionFilter): array
{
    $examId = (int)$examId;
    if ($examId <= 0) {
        return [];
    }
    $userIds = examination_monitor_college_reporting_user_ids($conn, $examId, $sectionFilter);
    if ($userIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $userIds));
    $assignedIds = array_fill_keys(examination_pure_assigned_user_ids($conn, 'regular', $examId), true);
    $sql = 'SELECT u.user_id, u.full_name, u.email, u.student_number, TRIM(COALESCE(u.section, \'\')) AS section,
            a.status AS attempt_status, a.correct_count, a.total_count
        FROM users u
        LEFT JOIN college_exam_attempts a ON a.user_id = u.user_id AND a.exam_id = ' . $examId . '
        WHERE u.user_id IN (' . $in . ')
        ORDER BY u.full_name ASC';
    $out = [];
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $userId = (int)($row['user_id'] ?? 0);
            $hasAttempt = trim((string)($row['attempt_status'] ?? '')) !== '';
            $inAssignment = isset($assignedIds[$userId]);
            if ($inAssignment && $hasAttempt) {
                $row['inclusion'] = 'Assignment and attempt';
            } elseif ($hasAttempt) {
                $row['inclusion'] = 'Existing attempt';
            } else {
                $row['inclusion'] = 'Exam assignment';
            }
            $out[] = $row;
        }
        mysqli_free_result($res);
    }

    return $out;
}

/**
 * Read-only snapshot response rows. Does not read the live answer key and does not write answers.
 *
 * @return list<array<string,mixed>>
 */
function examination_monitor_analytics_load_question_responses(mysqli $conn, int $examId, string $sectionFilter): array
{
    $examId = (int)$examId;
    if ($examId <= 0 || !function_exists('college_exam_attempt_questions_table_ready') || !college_exam_attempt_questions_table_ready($conn)) {
        return [];
    }
    $userIds = examination_monitor_college_reporting_user_ids($conn, $examId, $sectionFilter);
    if ($userIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $userIds));
    $sql = 'SELECT aq.question_id AS question_id,
            aq.display_position AS display_position,
            TRIM(COALESCE(aq.subject_name, \'\')) AS subject_name,
            TRIM(COALESCE(aq.topic_name, \'\')) AS topic_name,
            TRIM(COALESCE(ans.selected_answer, \'\')) AS selected,
            ans.is_correct AS stored_is_correct,
            a.user_id AS user_id,
            u.full_name AS full_name,
            u.student_number AS student_number,
            u.email AS email,
            TRIM(COALESCE(u.section, \'\')) AS section,
            a.correct_count AS exam_correct,
            a.total_count AS exam_total
        FROM college_exam_attempt_questions aq
        INNER JOIN college_exam_attempts a ON a.attempt_id = aq.attempt_id
        INNER JOIN users u ON u.user_id = a.user_id
        LEFT JOIN college_exam_answers ans ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
        WHERE a.exam_id = ' . $examId . '
          AND a.status = \'submitted\'
          AND a.user_id IN (' . $in . ')';
    $out = [];
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $out[] = $row;
        }
        mysqli_free_result($res);
    }

    return $out;
}

/**
 * Submitted attempts in scope, and how many of them have no question snapshot.
 * Read-only. A missing snapshot table counts every submitted attempt as uncovered.
 *
 * @return array{submitted_attempts:int,attempts_without_snapshot:int}
 */
function examination_monitor_analytics_load_snapshot_coverage(mysqli $conn, int $examId, string $sectionFilter): array
{
    $empty = ['submitted_attempts' => 0, 'attempts_without_snapshot' => 0];
    $examId = (int)$examId;
    if ($examId <= 0) {
        return $empty;
    }
    $userIds = examination_monitor_college_reporting_user_ids($conn, $examId, $sectionFilter);
    if ($userIds === []) {
        return $empty;
    }
    $in = implode(',', array_map('intval', $userIds));
    $submitted = 0;
    $countSql = 'SELECT COUNT(*) AS c FROM college_exam_attempts a
        WHERE a.exam_id = ' . $examId . '
          AND a.status = \'submitted\'
          AND a.user_id IN (' . $in . ')';
    $countRes = @mysqli_query($conn, $countSql);
    if ($countRes && ($countRow = mysqli_fetch_assoc($countRes))) {
        $submitted = (int)($countRow['c'] ?? 0);
    }
    if ($countRes) {
        mysqli_free_result($countRes);
    }
    if ($submitted <= 0) {
        return $empty;
    }
    if (!function_exists('college_exam_attempt_questions_table_ready') || !college_exam_attempt_questions_table_ready($conn)) {
        return ['submitted_attempts' => $submitted, 'attempts_without_snapshot' => $submitted];
    }
    $withSnapshot = 0;
    $withSql = 'SELECT COUNT(DISTINCT a.attempt_id) AS c
        FROM college_exam_attempts a
        INNER JOIN college_exam_attempt_questions aq ON aq.attempt_id = a.attempt_id
        WHERE a.exam_id = ' . $examId . '
          AND a.status = \'submitted\'
          AND a.user_id IN (' . $in . ')';
    $withRes = @mysqli_query($conn, $withSql);
    if ($withRes && ($withRow = mysqli_fetch_assoc($withRes))) {
        $withSnapshot = (int)($withRow['c'] ?? 0);
    }
    if ($withRes) {
        mysqli_free_result($withRes);
    }

    return [
        'submitted_attempts' => $submitted,
        'attempts_without_snapshot' => max(0, $submitted - $withSnapshot),
    ];
}

function examination_monitor_analytics_scope_label(string $sectionFilter): string
{
    $sectionFilter = examination_monitor_normalize_section_filter($sectionFilter);
    if ($sectionFilter === '') {
        return 'All sections';
    }
    if ($sectionFilter === '__none__') {
        return 'No section';
    }

    return $sectionFilter;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<list<string>>
 */
function examination_monitor_analytics_progress_matrix(array $rows): array
{
    $out = [];
    $n = 0;
    foreach ($rows as $row) {
        $n++;
        $out[] = [
            (string)$n,
            (string)($row['student_number'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($row['email'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['score'] ?? ''),
            (string)($row['mark'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<list<string>>
 */
function examination_monitor_analytics_class_matrix(array $rows, bool $withTopic): array
{
    $out = [];
    foreach ($rows as $row) {
        $line = [
            (string)($row['name'] ?? ''),
        ];
        if ($withTopic) {
            $line = [
                (string)($row['name'] ?? ''),
                (string)($row['subject_name'] ?? ''),
            ];
        }
        $line[] = (string)(int)($row['presented'] ?? 0);
        $line[] = (string)(int)($row['answered'] ?? 0);
        $line[] = (string)(int)($row['correct'] ?? 0);
        $line[] = (string)(int)($row['incorrect'] ?? 0);
        $line[] = (string)(int)($row['unanswered'] ?? 0);
        $line[] = (string)(int)($row['excluded'] ?? 0);
        $line[] = (string)($row['examinees'] ?? '');
        $line[] = (string)($row['accuracy_label'] ?? '—');
        $line[] = (string)($row['incorrect_rate_label'] ?? '—');
        $line[] = (string)($row['sample'] ?? '');
        $out[] = $line;
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<list<string>>
 */
function examination_monitor_analytics_question_matrix(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $ch = is_array($row['choices'] ?? null) ? $row['choices'] : ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $out[] = [
            (string)(($row['stem'] ?? '') !== '' ? $row['stem'] : ($row['name'] ?? '')),
            (string)($row['subject_name'] ?? ''),
            (string)($row['topic_name'] ?? ''),
            (string)(int)($row['presented'] ?? 0),
            (string)(int)($row['answered'] ?? 0),
            (string)(int)($row['correct'] ?? 0),
            (string)(int)($row['incorrect'] ?? 0),
            (string)(int)($row['unanswered'] ?? 0),
            (string)(int)($row['excluded'] ?? 0),
            (string)(int)($ch['A'] ?? 0),
            (string)(int)($ch['B'] ?? 0),
            (string)(int)($ch['C'] ?? 0),
            (string)(int)($ch['D'] ?? 0),
            (string)($row['accuracy_label'] ?? '—'),
            (string)($row['incorrect_rate_label'] ?? '—'),
            (string)($row['sample'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<list<string>>
 */
function examination_monitor_analytics_examinee_matrix(array $rows, bool $withTopic): array
{
    $out = [];
    foreach ($rows as $row) {
        $line = [
            (string)($row['student_number'] ?? ''),
            (string)($row['full_name'] ?? ''),
            (string)($row['section'] ?? ''),
            (string)($row['subject_name'] ?? ''),
        ];
        if ($withTopic) {
            $line[] = (string)($row['topic_name'] ?? '');
        }
        $line[] = (string)(int)($row['presented'] ?? 0);
        $line[] = (string)(int)($row['answered'] ?? 0);
        $line[] = (string)(int)($row['correct'] ?? 0);
        $line[] = (string)(int)($row['incorrect'] ?? 0);
        $line[] = (string)(int)($row['unanswered'] ?? 0);
        $line[] = (string)(int)($row['excluded'] ?? 0);
        $line[] = (string)($row['accuracy_label'] ?? '—');
        $line[] = (string)($row['exam_score_label'] ?? '—');
        $line[] = (string)($row['sample'] ?? '');
        $out[] = $line;
    }

    return $out;
}

function examination_monitor_analytics_stored_score_note(): string
{
    return 'Correct, incorrect, and accuracy are stored scoring flags from the answer row. An answered item with a missing or invalid flag is excluded from stored correct and stored incorrect. A post-submit rescore can rewrite those flags from the question bank at that time. These columns are not an independently verified historical result, are not a difficulty ranking, and do not identify hardest or easiest questions. A group with fewer than 5 answered items or fewer than 3 examinees is marked Limited sample.';
}

/**
 * @return list<array{title:string,hint:string,headers:list<string>,rows:list<list<string>>}>
 */
function examination_monitor_analytics_export_sections(array $ana, array $progressRows): array
{
    $stored = examination_monitor_analytics_stored_score_note();
    $topicHeaders = ['Topic', 'Subject', 'Presented', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Excluded from correctness', 'Examinees', 'Stored accuracy', 'Stored incorrect rate', 'Sample'];
    $subjectHeaders = ['Subject', 'Presented', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Excluded from correctness', 'Examinees', 'Stored accuracy', 'Stored incorrect rate', 'Sample'];
    $qHeaders = ['Question', 'Subject', 'Topic', 'Presented', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Excluded from correctness', 'A', 'B', 'C', 'D', 'Stored accuracy', 'Stored incorrect rate', 'Sample'];
    $exTopicHeaders = ['Student no.', 'Examinee', 'Section', 'Subject', 'Topic', 'Presented', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Excluded from correctness', 'Stored accuracy', 'Exam score', 'Sample'];
    $exSubjHeaders = ['Student no.', 'Examinee', 'Section', 'Subject', 'Presented', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Excluded from correctness', 'Stored accuracy', 'Exam score', 'Sample'];

    return [
        [
            'title' => 'Roster',
            'hint' => 'One authorized examinee per row. Status keeps the stored result. An in-progress attempt is not shown as submitted.',
            'headers' => ['#', 'Student number', 'Student name', 'Email', 'Status', 'Score', 'Mark'],
            'rows' => examination_monitor_analytics_progress_matrix($progressRows),
        ],
        [
            'title' => 'Subjects — stored scoring data',
            'hint' => 'Submitted attempts only. Stored accuracy = stored correct / verified answers. A missing or invalid flag is excluded from stored correct and stored incorrect. Unanswered is presented minus answered. ' . $stored,
            'headers' => $subjectHeaders,
            'rows' => examination_monitor_analytics_class_matrix($ana['subjects'] ?? [], false),
        ],
        [
            'title' => 'Topics — stored scoring data',
            'hint' => 'Snapshot topic labels. Included in this report when the saved attempt stores a topic name. ' . $stored,
            'headers' => $topicHeaders,
            'rows' => examination_monitor_analytics_class_matrix($ana['topics'] ?? [], true),
        ],
        [
            'title' => 'Questions — stored scoring data',
            'hint' => 'A–D counts are saved selected letters, not the current question-bank key. Question stems are shortened to 110 characters in this export. ' . $stored,
            'headers' => $qHeaders,
            'rows' => examination_monitor_analytics_question_matrix($ana['questions'] ?? []),
        ],
        [
            'title' => 'Examinee subjects — stored scoring data',
            'hint' => 'Stored accuracy = stored correct / verified answers. A missing or invalid flag is excluded. The exam-score column uses the exam total. Sample marks groups below 5 answered items or 3 examinees. ' . $stored,
            'headers' => $exSubjHeaders,
            'rows' => examination_monitor_analytics_examinee_matrix($ana['examinee_subjects'] ?? [], false),
        ],
        [
            'title' => 'Examinee topics — stored scoring data',
            'hint' => 'One row per examinee topic on the submitted attempt snapshot. Unmapped topics are labeled Unassigned. ' . $stored,
            'headers' => $exTopicHeaders,
            'rows' => examination_monitor_analytics_examinee_matrix($ana['examinee_topics'] ?? [], true),
        ],
    ];
}

function examination_monitor_analytics_xlsx_cell(string $ref, string $text, string $style): string
{
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($text) . '</t></is></c>';
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 */
function examination_monitor_analytics_xlsx_sheet(string $title, string $hint, array $headers, array $rows, string $subtitle = '', array $bands = [], array $appendix = []): string
{
    $colored = false;
    foreach ($bands as $band) {
        if ($band === 'C' || $band === 'N' || $band === 'D' || $band === 'G') {
            $colored = true;
            break;
        }
    }
    if ($colored && count($bands) === count($rows)) {
        $headers[] = 'Band';
        foreach ($rows as $rowIndex => $line) {
            if (is_array($line)) {
                $line[] = (string)$bands[$rowIndex];
                $rows[$rowIndex] = $line;
            }
        }
    }
    $lastIndex = max(0, count($headers) - 1);
    $lastCol = ereview_xlsx_col_letter($lastIndex);
    $questionColumn = null;
    foreach ($headers as $headerIndex => $headerName) {
        if (strcasecmp((string)$headerName, 'Question') === 0) {
            $questionColumn = $headerIndex;
        }
    }
    $hintHeight = min(56, max(28, (int)ceil(strlen($hint) / 120) * 15));
    $xmlRows = [];
    $xmlRows[] = '<row r="1" ht="28" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A1', $title, '1') . '</row>';
    $xmlRows[] = '<row r="2" ht="20" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A2', $subtitle, '2') . '</row>';
    $xmlRows[] = '<row r="3" ht="' . $hintHeight . '" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A3', $hint, '3') . '</row>';
    $hc = [];
    $widths = [];
    foreach ($headers as $i => $h) {
        $hc[] = examination_monitor_analytics_xlsx_cell(ereview_xlsx_col_letter($i) . '4', $h, '4');
        $width = min(32, max(12, (int)ceil(strlen((string)$h) * 1.05) + 2));
        if (strcasecmp((string)$h, 'Question') === 0) {
            $width = 44;
        } elseif (strcasecmp((string)$h, 'Band') === 0) {
            $width = 3;
        } elseif (preg_match('/^(A|B|C|D|Other)$/i', (string)$h) || preg_match('/choice/i', (string)$h)) {
            $width = 12;
        } elseif (preg_match('/student|examinee|name/i', (string)$h)) {
            $width = 26;
        } elseif (preg_match('/topic|subject/i', (string)$h)) {
            $width = 22;
        } elseif (preg_match('/section/i', (string)$h)) {
            $width = 14;
        } elseif (preg_match('/email/i', (string)$h)) {
            $width = 28;
        } elseif (preg_match('/accuracy|incorrect rate|exam score|correct rate|result/i', (string)$h)) {
            $width = 16;
        } elseif (preg_match('/excluded/i', (string)$h)) {
            $width = 16;
        } elseif (preg_match('/sample/i', (string)$h)) {
            $width = 16;
        } elseif (preg_match('/focus/i', (string)$h)) {
            $width = 48;
        } elseif (preg_match('/interpretation/i', (string)$h)) {
            $width = 34;
        } elseif (preg_match('/priority/i', (string)$h)) {
            $width = 24;
        } elseif (preg_match('/presented|answered|correct|incorrect|unanswered|examinees|rank|total|questions|question no|report no/i', (string)$h)) {
            $width = 16;
        }
        $widths[] = [$i + 1, $i + 1, $width, strcasecmp((string)$h, 'Band') === 0];
    }
    $xmlRows[] = '<row r="4" ht="34" customHeight="1">' . implode('', $hc) . '</row>';
    $r = 5;
    if ($rows === []) {
        $xmlRows[] = '<row r="5" ht="20" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A5', 'No rows for this section.', '5') . '</row>';
        $r = 6;
    } else {
        foreach ($rows as $ri => $line) {
            $st = ($ri % 2 === 0) ? '5' : '6';
            $cells = [];
            foreach ($line as $ci => $val) {
                $header = (string)($headers[$ci] ?? '');
                $cells[] = examination_monitor_analytics_detail_value_cell(ereview_xlsx_col_letter($ci) . (string)$r, $header, (string)$val, $st);
            }
            $rowHeight = 20;
            if (in_array("Lecturer's Focus", $headers, true)) {
                $focusAt = array_search("Lecturer's Focus", $headers, true);
                $focusText = $focusAt === false ? '' : (string)($line[$focusAt] ?? '');
                $rowHeight = min(64, max(36, (int)ceil(max(1, strlen($focusText)) / 46) * 18));
            }
            if ($questionColumn !== null) {
                $stem = (string)($line[$questionColumn] ?? '');
                $rowHeight = min(60, max(22, (int)ceil(max(1, strlen($stem)) / 42) * 16));
            }
            $xmlRows[] = '<row r="' . $r . '" ht="' . $rowHeight . '" customHeight="1">' . implode('', $cells) . '</row>';
            $r++;
        }
    }
    $last = max(5, $r - 1);
    $bandAt = array_search('Band', $headers, true);
    $visibleLast = ($bandAt !== false && (int)$bandAt > 0) ? ereview_xlsx_col_letter((int)$bandAt - 1) : $lastCol;
    $conditional = '';
    $rateAt = array_search('Recorded Correct Rate', $headers, true);
    if ($rateAt !== false && $bandAt !== false) {
        $rateCol = ereview_xlsx_col_letter((int)$rateAt);
        $bandCol = ereview_xlsx_col_letter((int)$bandAt);
        $priorityAt = false;
        foreach (['Priority', 'Performance Interpretation'] as $priorityHeader) {
            $foundPriority = array_search($priorityHeader, $headers, true);
            if ($foundPriority !== false) {
                $priorityAt = $foundPriority;
                break;
            }
        }
        $colorSpan = $rateCol . '5:' . $rateCol . $last;
        if ($priorityAt !== false) {
            $colorSpan .= ' ' . ereview_xlsx_col_letter((int)$priorityAt) . '5:' . ereview_xlsx_col_letter((int)$priorityAt) . $last;
        }
        $conditional .= '<conditionalFormatting sqref="' . $colorSpan . '">'
            . '<cfRule type="expression" dxfId="8" priority="1"><formula>$' . $bandCol . '5="C"</formula></cfRule>'
            . '<cfRule type="expression" dxfId="11" priority="2"><formula>$' . $bandCol . '5="N"</formula></cfRule>'
            . '<cfRule type="expression" dxfId="9" priority="3"><formula>$' . $bandCol . '5="D"</formula></cfRule>'
            . '<cfRule type="expression" dxfId="10" priority="4"><formula>$' . $bandCol . '5="G"</formula></cfRule>'
            . '</conditionalFormatting>';
    }
    foreach ($headers as $i => $h) {
        if (strcasecmp($h, 'Sample') === 0) {
            $col = ereview_xlsx_col_letter($i);
            $conditional .= '<conditionalFormatting sqref="' . $col . '5:' . $col . $last . '">'
                . '<cfRule type="containsText" dxfId="6" priority="1" operator="containsText" text="Limited sample">'
                . '<formula>NOT(ISERROR(SEARCH("Limited sample",' . $col . '5)))</formula></cfRule></conditionalFormatting>';
        }
    }

    $filterLast = $last;
    $filterColumn = $visibleLast;
    $appendixHeaders = is_array($appendix['headers'] ?? null) ? array_values($appendix['headers']) : [];
    $appendixRows = is_array($appendix['rows'] ?? null) ? $appendix['rows'] : [];
    $appendixStyles = is_array($appendix['styles'] ?? null) ? $appendix['styles'] : [];
    $extraMerges = [];
    if ($appendix !== []) {
        $cursor = $last + 2;
        $appendixTitle = (string)($appendix['title'] ?? '');
        if ($appendixTitle !== '') {
            $xmlRows[] = '<row r="' . $cursor . '" ht="24" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A' . $cursor, $appendixTitle, '1') . '</row>';
            $extraMerges[] = [$cursor];
            $cursor++;
        }
        $appendixNote = (string)($appendix['note'] ?? '');
        if ($appendixNote !== '') {
            $noteHeight = min(48, max(32, (int)ceil(strlen($appendixNote) / 110) * 16));
            $xmlRows[] = '<row r="' . $cursor . '" ht="' . $noteHeight . '" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A' . $cursor, $appendixNote, '3') . '</row>';
            $extraMerges[] = [$cursor];
            $cursor++;
        }
        if ($appendixHeaders !== []) {
            $headerCells = [];
            foreach ($appendixHeaders as $headerIndex => $headerLabel) {
                $headerCells[] = examination_monitor_analytics_xlsx_cell(ereview_xlsx_col_letter($headerIndex) . $cursor, (string)$headerLabel, '4');
                if (!isset($widths[$headerIndex])) {
                    $widths[] = [$headerIndex + 1, $headerIndex + 1, 16, false];
                }
            }
            $xmlRows[] = '<row r="' . $cursor . '" ht="30" customHeight="1">' . implode('', $headerCells) . '</row>';
            $cursor++;
            foreach ($appendixRows as $rowIndex => $line) {
                if (!is_array($line)) {
                    continue;
                }
                $lineCells = [];
                foreach (array_values($line) as $columnIndex => $value) {
                    $style = (string)($appendixStyles[$rowIndex][$columnIndex] ?? '5');
                    $lineCells[] = examination_monitor_analytics_xlsx_cell(ereview_xlsx_col_letter($columnIndex) . $cursor, (string)$value, $style);
                }
                $xmlRows[] = '<row r="' . $cursor . '" ht="22" customHeight="1">' . implode('', $lineCells) . '</row>';
                $cursor++;
            }
            $appendixLast = ereview_xlsx_col_letter(max(count($headers), count($appendixHeaders)) - 1);
            if (strlen($appendixLast) > strlen($lastCol) || (strlen($appendixLast) === strlen($lastCol) && strcmp($appendixLast, $lastCol) > 0)) {
                $lastCol = $appendixLast;
            }
        }
        $last = max($last, $cursor - 1);
        $visibleColumns = $bandAt !== false ? (int)$bandAt : count($headers);
        if (count($appendixHeaders) > $visibleColumns) {
            $visibleLast = ereview_xlsx_col_letter(count($appendixHeaders) - 1);
        }
    }

    $breakAt = 0;
    $breakHeaderName = $questionColumn !== null ? 'Choice A' : 'Questions in Exam';
    foreach ($headers as $breakIndex => $breakHeader) {
        if (strcasecmp((string)$breakHeader, $breakHeaderName) === 0) {
            $breakAt = $breakIndex + 1;
            break;
        }
    }
    $wide = $questionColumn !== null || count($headers) > 12;

    $merges = ['A1:' . $visibleLast . '1', 'A2:' . $visibleLast . '2', 'A3:' . $visibleLast . '3'];
    foreach ($extraMerges as $mergedRow) {
        $merges[] = 'A' . $mergedRow[0] . ':' . $visibleLast . $mergedRow[0];
    }

    return examination_monitor_analytics_sheet_document([
        'rows' => $xmlRows,
        'dimension' => 'A1:' . $lastCol . $last,
        'printArea' => 'A1:' . $visibleLast . $last,
        'cols' => $widths,
        'merges' => $merges,
        'autoFilter' => 'A4:' . $filterColumn . $filterLast,
        'freeze' => 4,
        'zoom' => 110,
        'fitPage' => !$wide,
        'fitWidth' => $wide ? 0 : 1,
        'fitHeight' => 0,
        'scale' => $wide ? 85 : 100,
        'colBreak' => $wide ? $breakAt : 0,
        'tabColor' => '1E3A5F',
        'conditional' => $conditional,
    ]);
}

function examination_monitor_analytics_detail_value_cell(string $ref, string $header, string $value, string $textStyle): string
{
    $label = strtolower($header);
    $percentStyle = $textStyle === '6' ? '32' : '31';
    $countStyle = $textStyle === '6' ? '30' : '29';
    if (preg_match('/^-?\d+(?:\.\d+)?%$/', $value) && preg_match('/accuracy|rate|percent|score/', $label)) {
        $ratio = ((float)str_replace('%', '', $value)) / 100;

        return '<c r="' . $ref . '" s="' . $percentStyle . '"><v>' . $ratio . '</v></c>';
    }
    if (preg_match('/^-?\d+\.\d+$/', $value) && preg_match('/percent|score/', $label)) {
        return examination_monitor_analytics_number_cell($ref, $value, '33');
    }
    if (preg_match('/^-?\d+$/', $value) && preg_match('/presented|answered|correct|incorrect|unanswered|excluded|examinees|other|choice|questions|question no|report no|^a$|^b$|^c$|^d$|^rank$|^total$|^#$/', $label)) {
        return '<c r="' . $ref . '" s="' . $countStyle . '"><v>' . (int)$value . '</v></c>';
    }

    return examination_monitor_analytics_xlsx_cell($ref, $value, $textStyle);
}


function examination_monitor_output_analytics_xlsx(string $examTitle, string $scopeLabel, array $ana, array $progressRows, array $report = []): void
{
    require_once __DIR__ . '/examination_monitor_analytics_workbook.php';
    $bytes = examination_monitor_analytics_build_xlsx_bytes($examTitle, $scopeLabel, $ana, $progressRows, $report);
    if ($bytes === '') {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not build Excel file.';
        exit;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $examTitle) ?: 'exam';
    $safeName = substr($safeName, 0, 64) . '_lecturer_analytics.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . (string)strlen($bytes));
    echo $bytes;
}

function examination_monitor_output_analytics_pdf(string $examTitle, string $scopeLabel, array $ana, array $progressRows, array $report = []): void
{
    require_once __DIR__ . '/examination_monitor_analytics_workbook.php';
    $pdf = examination_monitor_analytics_build_pdf_bytes($examTitle, $scopeLabel, $ana, $progressRows, $report);
    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $examTitle) ?: 'exam';
    $safeName = substr($safeName, 0, 64) . '_lecturer_analytics.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store');
    echo $pdf;
}
