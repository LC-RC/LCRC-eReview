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

/**
 * @return array{ok:bool,redirect?:string,ctx?:array,section_filter?:string,analytics?:array,progress_rows?:list<array<string,mixed>>,is_finished?:bool}
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
    $sectionFilter = examination_monitor_resolve_college_section_filter(
        $conn,
        (int)$scope['assessment_id'],
        $_GET['section'] ?? ''
    )['filter'];
    $now = date('Y-m-d H:i:s');
    $metrics = examination_monitor_metrics($conn, $scope, $sectionFilter);
    $window = examination_monitor_running_finished($conn, $ctx, $metrics, $now);
    $ana = examination_monitor_item_analytics($conn, (int)$scope['assessment_id'], $sectionFilter);
    $progressRows = exam_monitor_progress_export_rows(
        $conn,
        (int)$ctx['assessment_id'],
        (int)$ctx['question_count'],
        !empty($window['is_finished']),
        $sectionFilter
    );

    return [
        'ok' => true,
        'ctx' => $ctx,
        'section_filter' => $sectionFilter,
        'analytics' => $ana,
        'progress_rows' => $progressRows,
        'is_finished' => !empty($window['is_finished']),
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
        $line[] = (string)($row['accuracy_label'] ?? '—');
        $line[] = (string)($row['incorrect_rate_label'] ?? '—');
        $line[] = !empty($row['weak']) ? 'Weak' : '';
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
            (string)($row['stem'] ?? ''),
            (string)($row['subject_name'] ?? ''),
            (string)($row['topic_name'] ?? ''),
            (string)(int)($row['presented'] ?? 0),
            (string)(int)($row['answered'] ?? 0),
            (string)(int)($row['correct'] ?? 0),
            (string)(int)($row['incorrect'] ?? 0),
            (string)(int)($row['unanswered'] ?? 0),
            (string)(int)($ch['A'] ?? 0),
            (string)(int)($ch['B'] ?? 0),
            (string)(int)($ch['C'] ?? 0),
            (string)(int)($ch['D'] ?? 0),
            (string)($row['accuracy_label'] ?? '—'),
            (string)($row['incorrect_rate_label'] ?? '—'),
            !empty($row['weak']) ? 'Weak' : '',
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
        $line[] = (string)($row['accuracy_label'] ?? '—');
        $line[] = (string)($row['exam_score_label'] ?? '—');
        $line[] = !empty($row['weak']) ? 'Weak' : '';
        $out[] = $line;
    }

    return $out;
}

/**
 * @return list<array{title:string,hint:string,headers:list<string>,rows:list<list<string>>}>
 */
function examination_monitor_analytics_export_sections(array $ana, array $progressRows): array
{
    $topicHeaders = ['Topic', 'Subject', 'Presented', 'Answered (n)', 'Correct', 'Incorrect', 'Unanswered', 'Accuracy', 'Incorrect rate', 'Weak'];
    $subjectHeaders = ['Subject', 'Presented', 'Answered (n)', 'Correct', 'Incorrect', 'Unanswered', 'Accuracy', 'Incorrect rate', 'Weak'];
    $qHeaders = ['Question', 'Subject', 'Topic', 'Presented', 'Answered (n)', 'Correct', 'Incorrect', 'Unanswered', 'A', 'B', 'C', 'D', 'Accuracy', 'Incorrect rate', 'Weak'];
    $exTopicHeaders = ['Student no.', 'Examinee', 'Section', 'Subject', 'Topic', 'Presented', 'Answered (n)', 'Correct', 'Incorrect', 'Unanswered', 'Accuracy', 'Exam score (paper total)', 'Weak'];
    $exSubjHeaders = ['Student no.', 'Examinee', 'Section', 'Subject', 'Presented', 'Answered (n)', 'Correct', 'Incorrect', 'Unanswered', 'Accuracy', 'Exam score (paper total)', 'Weak'];

    return [
        [
            'title' => 'Roster / progress',
            'hint' => 'Same columns as the existing progress export. Exam score uses paper total (unanswered counts as not correct).',
            'headers' => ['#', 'Student number', 'Student name', 'Email', 'Status', 'Score', 'Mark'],
            'rows' => examination_monitor_analytics_progress_matrix($progressRows),
        ],
        [
            'title' => 'Class subject performance',
            'hint' => 'Submitted attempts only. Accuracy = correct / answered A–D. Unanswered is presented minus answered.',
            'headers' => $subjectHeaders,
            'rows' => examination_monitor_analytics_class_matrix($ana['subjects'] ?? [], false),
        ],
        [
            'title' => 'Class topic performance',
            'hint' => 'Snapshot topic labels. Hidden in UI for overall-mode exams; included here when snapshot topics exist.',
            'headers' => $topicHeaders,
            'rows' => examination_monitor_analytics_class_matrix($ana['topics'] ?? [], true),
        ],
        [
            'title' => 'Question analysis',
            'hint' => 'A–D counts are displayed choice letters from saved selected_answer, not live bank keys.',
            'headers' => $qHeaders,
            'rows' => examination_monitor_analytics_question_matrix($ana['questions'] ?? []),
        ],
        [
            'title' => 'Examinee subject performance',
            'hint' => 'Accuracy = correct / answered A–D. Exam score column uses paper total. Weak = at least 3 answered and accuracy below 50%.',
            'headers' => $exSubjHeaders,
            'rows' => examination_monitor_analytics_examinee_matrix($ana['examinee_subjects'] ?? [], false),
        ],
        [
            'title' => 'Examinee topic weaknesses',
            'hint' => 'One row per examinee topic on the submitted paper snapshot. Unmapped topics are labeled Unassigned.',
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
function examination_monitor_analytics_xlsx_sheet(string $title, string $hint, array $headers, array $rows): string
{
    $xmlRows = [];
    $xmlRows[] = '<row r="1" ht="22" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A1', $title, '1') . '</row>';
    $xmlRows[] = '<row r="2" ht="18" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A2', $hint, '2') . '</row>';
    $hc = [];
    foreach ($headers as $i => $h) {
        $hc[] = examination_monitor_analytics_xlsx_cell(ereview_xlsx_col_letter($i) . '4', $h, '3');
    }
    $xmlRows[] = '<row r="4" ht="20" customHeight="1">' . implode('', $hc) . '</row>';
    $r = 5;
    if ($rows === []) {
        $xmlRows[] = '<row r="5">' . examination_monitor_analytics_xlsx_cell('A5', 'No rows for this section.', '4') . '</row>';
        $r = 6;
    } else {
        foreach ($rows as $ri => $line) {
            $st = ($ri % 2 === 0) ? '4' : '5';
            $cells = [];
            foreach ($line as $ci => $val) {
                $cells[] = examination_monitor_analytics_xlsx_cell(ereview_xlsx_col_letter($ci) . (string)$r, (string)$val, $st);
            }
            $xmlRows[] = '<row r="' . $r . '">' . implode('', $cells) . '</row>';
            $r++;
        }
    }
    $lastCol = ereview_xlsx_col_letter(max(0, count($headers) - 1));
    $dim = 'A1:' . $lastCol . (string)max(5, $r - 1);

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <dimension ref="' . $dim . '"/>
  <sheetViews><sheetView workbookViewId="0"/></sheetViews>
  <sheetFormatPr defaultRowHeight="16"/>
  <sheetData>' . implode('', $xmlRows) . '</sheetData>
  <pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>
  <pageSetup orientation="landscape" paperSize="9"/>
</worksheet>';
}

function examination_monitor_output_analytics_xlsx(string $examTitle, string $scopeLabel, array $ana, array $progressRows): void
{
    $sections = examination_monitor_analytics_export_sections($ana, $progressRows);
    $sheetNames = ['Roster', 'Subjects', 'Topics', 'Questions', 'Examinee subjects', 'Examinee topics'];
    $sheetXml = [];
    foreach ($sections as $i => $sec) {
        $sheetXml[$i] = examination_monitor_analytics_xlsx_sheet(
            (string)$sec['title'] . ' · ' . $examTitle . ' · ' . $scopeLabel,
            (string)$sec['hint'],
            $sec['headers'],
            $sec['rows']
        );
    }

    $sheetEls = [];
    $relEls = [];
    $overrideEls = [];
    foreach ($sheetNames as $i => $name) {
        $sid = $i + 1;
        $rid = 'rId' . $sid;
        $sheetEls[] = '<sheet name="' . ereview_xlsx_t($name) . '" sheetId="' . $sid . '" r:id="' . $rid . '"/>';
        $relEls[] = '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sid . '.xml"/>';
        $overrideEls[] = '<Override PartName="/xl/worksheets/sheet' . $sid . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $relEls[] = '<Relationship Id="rId' . (count($sheetNames) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="4">
    <font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>
    <font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><sz val="10"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  </fonts>
  <fills count="6">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E40AF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFDE68A"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF7ED"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFBFDBFE"/></left>
      <right style="thin"><color rgb="FFBFDBFE"/></right>
      <top style="thin"><color rgb="FFBFDBFE"/></top>
      <bottom style="thin"><color rgb="FFBFDBFE"/></bottom>
    </border>
  </borders>
  <cellXfs count="6">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
    <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>
    <xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>
  </cellXfs>
</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>' . implode('', $sheetEls) . '</sheets>
</workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $relEls) . '</Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  ' . implode('', $overrideEls) . '
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>';
    $created = gmdate('c');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>' . ereview_xlsx_t($examTitle . ' analytics') . '</dc:title>
  <dc:creator>LCRC eReview</dc:creator>
  <dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>
</cp:coreProperties>';
    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
  <Application>LCRC eReview</Application>
</Properties>';

    $tmp = tempnam(sys_get_temp_dir(), 'ereview_ana_xlsx_');
    if ($tmp === false) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not create temporary file for Excel export.';
        exit;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        @unlink($tmp);
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not build Excel file.';
        exit;
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    foreach ($sheetXml as $i => $xml) {
        $zip->addFromString('xl/worksheets/sheet' . (string)($i + 1) . '.xml', $xml);
    }
    $zip->close();

    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $examTitle) ?: 'exam';
    $safeName = substr($safeName, 0, 64) . '_lecturer_analytics.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . (string)filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
}

function examination_monitor_output_analytics_pdf(string $examTitle, string $scopeLabel, array $ana, array $progressRows): void
{
    $sections = examination_monitor_analytics_export_sections($ana, $progressRows);
    $pageW = 842.0;
    $pageH = 595.28;
    $margin = 28.0;
    $lineH = 12.0;
    $title = ereview_pdf_winansi($examTitle, 180);
    $scope = ereview_pdf_winansi('Scope: ' . $scopeLabel, 120);
    $note = ereview_pdf_winansi('Submitted attempts only. Accuracy uses answered A-D. Exam score uses paper total.', 180);
    $generated = ereview_pdf_winansi(date('M j, Y g:i A'), 80);

    $pages = [];
    $cur = [];
    $y = $pageH - $margin;
    $startPage = static function () use (&$cur, &$y, $pageH, $margin, $title, $scope, $note): void {
        $cur = [];
        $y = $pageH - $margin;
        $cur[] = 'BT /F2 12 Tf 0.12 0.18 0.28 rg 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal('Lecturer analytics') . ') Tj ET';
        $y -= 14;
        $cur[] = 'BT /F2 10 Tf 0.12 0.18 0.28 rg 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal($title) . ') Tj ET';
        $y -= 12;
        $cur[] = 'BT /F1 8 Tf 0.35 0.42 0.52 rg 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal($scope) . ') Tj ET';
        $y -= 11;
        $cur[] = 'BT /F1 8 Tf 0.35 0.42 0.52 rg 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal($note) . ') Tj ET';
        $y -= 16;
    };
    $flush = static function () use (&$pages, &$cur): void {
        $pages[] = implode("\n", $cur);
        $cur = [];
    };
    $need = static function (float $needH) use (&$startPage, &$flush, &$y, $margin): void {
        if ($y - $needH < $margin + 18) {
            $flush();
            $startPage();
        }
    };
    $startPage();

    foreach ($sections as $sec) {
        $need(36);
        $cur[] = 'BT /F2 10 Tf 0.12 0.25 0.55 rg 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal(ereview_pdf_winansi((string)$sec['title'], 90)) . ') Tj ET';
        $y -= 12;
        $cur[] = 'BT /F1 7.5 Tf 0.35 0.42 0.52 rg 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal(ereview_pdf_winansi((string)$sec['hint'], 140)) . ') Tj ET';
        $y -= 12;
        $headers = $sec['headers'];
        $colN = max(1, count($headers));
        $tableW = $pageW - (2 * $margin);
        $colW = $tableW / $colN;
        $drawRow = static function (array $cells, bool $head) use (&$cur, &$y, $margin, $colW, $lineH): void {
            $x = $margin;
            foreach ($cells as $cell) {
                $txt = ereview_pdf_winansi((string)$cell, 28);
                $font = $head ? '/F2 6.5 Tf 1 1 1 rg' : '/F1 6.5 Tf 0.12 0.18 0.28 rg';
                $cur[] = 'BT ' . $font . ' 1 0 0 1 ' . sprintf('%.2F %.2F', $x + 1.5, $y + 2.5) . ' Tm (' . ereview_pdf_literal($txt) . ') Tj ET';
                $x += $colW;
            }
            $y -= $lineH;
        };
        $need($lineH + 2);
        $cur[] = sprintf('%.2F %.2F %.2F %.2F re 0.12 0.25 0.55 rg f', $margin, $y, $tableW, $lineH);
        $drawRow($headers, true);
        $body = $sec['rows'];
        if ($body === []) {
            $need($lineH);
            $cur[] = 'BT /F1 8 Tf 0.35 0.42 0.52 rg 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal('No rows for this section.') . ') Tj ET';
            $y -= 16;
            continue;
        }
        foreach ($body as $line) {
            $need($lineH);
            $drawRow($line, false);
        }
        $y -= 10;
    }
    if ($cur !== []) {
        $flush();
    }

    $totalPages = max(1, count($pages));
    $objects = [];
    $objects[] = null;
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kidRefs = [];
    $baseId = 3;
    $pageObjIds = [];
    $contentObjIds = [];
    for ($i = 0; $i < $totalPages; $i++) {
        $pageObjIds[$i] = $baseId;
        $contentObjIds[$i] = $baseId + 1;
        $kidRefs[] = $pageObjIds[$i] . ' 0 R';
        $baseId += 2;
    }
    $font1Id = $baseId;
    $font2Id = $baseId + 1;
    $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kidRefs) . '] /Count ' . $totalPages . ' >>';
    for ($i = 0; $i < $totalPages; $i++) {
        $stream = $pages[$i] . "\nBT /F1 7 Tf 0.35 0.42 0.52 rg 1 0 0 1 " . sprintf('%.2F %.2F', $margin, 16) . ' Tm (' . ereview_pdf_literal('Page ' . ($i + 1) . ' of ' . $totalPages . '  |  ' . $generated) . ") Tj ET";
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageW . ' ' . $pageH . '] /Contents ' . $contentObjIds[$i] . ' 0 R /Resources << /Font << /F1 ' . $font1Id . ' 0 R /F2 ' . $font2Id . ' 0 R >> >> >>';
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    $objCount = count($objects) - 1;
    for ($i = 1; $i <= $objCount; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . ($objCount + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $objCount; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . ($objCount + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $examTitle) ?: 'exam';
    $safeName = substr($safeName, 0, 64) . '_lecturer_analytics.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store');
    echo $pdf;
}
