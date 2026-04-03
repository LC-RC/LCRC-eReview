<?php
/**
 * Excel (.xlsx) export for exam progress — OOXML via ZipArchive, yellow/blue theme, landscape print.
 * Main sheet uses VLOOKUP against a second "Lookup" sheet (student number → name, email).
 */

declare(strict_types=1);

function ereview_xlsx_col_letter(int $colIdx0): string
{
    $n = $colIdx0 + 1;
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }

    return $s;
}

function ereview_xlsx_t(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Escape formula text for OOXML <f> element (quotes → &quot;). */
function ereview_xlsx_f(string $formula): string
{
    return htmlspecialchars($formula, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Rich Score cell: bold green/red "40/50", muted gray " | 90.00%" when mark is Pass/Fail. */
function ereview_xlsx_score_cell_xml(string $col, int $r, string $rowStyle, string $scoreLine, string $markLabel): string
{
    $plain = trim($scoreLine);
    if ($markLabel !== 'Pass' && $markLabel !== 'Fail') {
        return '<c r="' . $col . $r . '" s="' . $rowStyle . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($plain) . '</t></is></c>';
    }
    if (!preg_match('/^(\d+)\s*\/\s*(\d+)\s*\|\s*(.+)$/u', $plain, $m)) {
        return '<c r="' . $col . $r . '" s="' . $rowStyle . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($plain) . '</t></is></c>';
    }
    $frac = $m[1] . '/' . $m[2];
    $rest = ' | ' . trim($m[3]);
    $rgbFrac = ($markLabel === 'Pass') ? 'FF047857' : 'FFB91C1C';

    return '<c r="' . $col . $r . '" s="' . $rowStyle . '" t="inlineStr"><is>'
        . '<r><rPr><b/><sz val="11"/><color rgb="' . $rgbFrac . '"/><rFont val="Calibri"/></rPr><t>' . ereview_xlsx_t($frac) . '</t></r>'
        . '<r><rPr><sz val="11"/><color rgb="FF64748B"/><rFont val="Calibri"/></rPr><t xml:space="preserve">' . ereview_xlsx_t($rest) . '</t></r>'
        . '</is></c>';
}

/** Mark cell: bold green Pass / red Fail. */
function ereview_xlsx_mark_cell_xml(string $col, int $r, string $rowStyle, string $mark): string
{
    $m = trim($mark);
    if ($m !== 'Pass' && $m !== 'Fail') {
        return '<c r="' . $col . $r . '" s="' . $rowStyle . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($m === '' ? '—' : $m) . '</t></is></c>';
    }
    $rgb = ($m === 'Pass') ? 'FF047857' : 'FFB91C1C';

    return '<c r="' . $col . $r . '" s="' . $rowStyle . '" t="inlineStr"><is>'
        . '<r><rPr><b/><sz val="11"/><color rgb="' . $rgb . '"/><rFont val="Calibri"/></rPr><t>' . ereview_xlsx_t($m) . '</t></r>'
        . '</is></c>';
}

/**
 * @param list<array{student_number:string,name:string,email:string,status:string,score:string,mark:string}> $rows
 * @param list<array{student_number:string,full_name:string,email:string}>                                     $lookupRows
 */
function ereview_output_exam_progress_xlsx(string $examTitle, array $rows, array $lookupRows = []): void
{
    if (!class_exists('ZipArchive')) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Excel export requires the PHP zip extension (ZipArchive).';
        exit;
    }

    $brand = 'LCRC eReview';
    $subtitle = 'Exam progress report';
    $generated = 'Time generated: ' . date('M j, Y g:i A');
    $headers = ['#', 'Student number', 'Student name', 'Email', 'Status', 'Score'];

    $dataStartRow = 7;
    $colCount = 6;
    if ($rows === []) {
        $lastRowNum = $dataStartRow;
    } else {
        $lastRowNum = $dataStartRow + count($rows) - 1;
    }
    $lastRef = 'F' . (string)$lastRowNum;

    $mergeRefs = ['A1:F1', 'A2:F2', 'A3:F3', 'A4:F4'];
    if ($rows === []) {
        $mergeRefs[] = 'A7:F7';
    }

    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($brand) . '</t></is></c></row>';
    $sheetRows[] = '<row r="2" ht="22" customHeight="1"><c r="A2" s="1" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($subtitle) . '</t></is></c></row>';
    $sheetRows[] = '<row r="3" ht="28" customHeight="1"><c r="A3" s="1" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($examTitle) . '</t></is></c></row>';
    $sheetRows[] = '<row r="4" ht="22" customHeight="1"><c r="A4" s="2" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($generated) . '</t></is></c></row>';
    $sheetRows[] = '<row r="5" ht="8" customHeight="1"><c r="A5" s="0"/><c r="B5" s="0"/><c r="C5" s="0"/><c r="D5" s="0"/><c r="E5" s="0"/><c r="F5" s="0"/><c r="G5" s="0"/></row>';

    $hc = [];
    for ($c = 0; $c < $colCount; $c++) {
        $ref = ereview_xlsx_col_letter($c) . '6';
        $hc[] = '<c r="' . $ref . '" s="3" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($headers[$c]) . '</t></is></c>';
    }
    $sheetRows[] = '<row r="6" ht="22" customHeight="1">' . implode('', $hc) . '</row>';

    $n = 0;
    foreach ($rows as $row) {
        $n++;
        $r = $dataStartRow + $n - 1;
        $st = ($n % 2 === 0) ? '4' : '5';
        $cells = [];
        $cells[] = '<c r="A' . $r . '" s="' . $st . '"><v>' . (int)$n . '</v></c>';

        $sn = trim((string)($row['student_number'] ?? ''));
        if ($sn !== '' && preg_match('/^\d+$/', $sn)) {
            $cells[] = '<c r="B' . $r . '" s="' . $st . '"><v>' . $sn . '</v></c>';
        } elseif ($sn !== '') {
            $cells[] = '<c r="B' . $r . '" s="' . $st . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($sn) . '</t></is></c>';
        } else {
            $cells[] = '<c r="B' . $r . '" s="' . $st . '"/>';
        }

        $fName = 'IFERROR(VLOOKUP(B' . $r . ',Lookup!$A:$C,2,FALSE),"")';
        $fMail = 'IFERROR(VLOOKUP(B' . $r . ',Lookup!$A:$C,3,FALSE),"")';
        $cells[] = '<c r="C' . $r . '" s="' . $st . '"><f>' . ereview_xlsx_f($fName) . '</f></c>';
        $cells[] = '<c r="D' . $r . '" s="' . $st . '"><f>' . ereview_xlsx_f($fMail) . '</f></c>';
        $cells[] = '<c r="E' . $r . '" s="' . $st . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($row['status']) . '</t></is></c>';
        $markLabel = (string)($row['mark'] ?? '—');
        $cells[] = ereview_xlsx_score_cell_xml('F', $r, $st, (string)($row['score'] ?? ''), $markLabel);
        $cells[] = ereview_xlsx_mark_cell_xml('G', $r, $st, $markLabel);
        $sheetRows[] = '<row r="' . $r . '" ht="18">' . implode('', $cells) . '</row>';
    }

    if ($rows === []) {
        $sheetRows[] = '<row r="7" ht="22" customHeight="1"><c r="A7" s="1" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t('No students on roster.') . '</t></is></c></row>';
    }

    $mergeXml = '';
    foreach ($mergeRefs as $mref) {
        $mergeXml .= '    <mergeCell ref="' . $mref . '"/>' . "\n";
    }

    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <dimension ref="A1:' . $lastRef . '"/>
  <sheetViews>
    <sheetView tabSelected="1" workbookViewId="0">
      <pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/>
      <selection activeCell="A1" sqref="A1"/>
    </sheetView>
  </sheetViews>
  <sheetFormatPr defaultRowHeight="15"/>
  <cols>
    <col min="1" max="1" width="6" customWidth="1"/>
    <col min="2" max="2" width="14" customWidth="1"/>
    <col min="3" max="3" width="34" customWidth="1"/>
    <col min="4" max="4" width="34" customWidth="1"/>
    <col min="5" max="5" width="16" customWidth="1"/>
    <col min="6" max="6" width="26" customWidth="1"/>
    <col min="7" max="7" width="12" customWidth="1"/>
  </cols>
  <sheetData>
    ' . implode("\n    ", $sheetRows) . '
  </sheetData>
  <mergeCells count="' . count($mergeRefs) . '">
' . $mergeXml . '  </mergeCells>
  <pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>
  <pageSetup paperSize="9" orientation="landscape"/>
</worksheet>';

    $lookupLast = max(2, 1 + count($lookupRows));
    $lookupRowsXml = [];
    $lookupRowsXml[] = '<row r="1" ht="20" customHeight="1">'
        . '<c r="A1" s="3" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t('Student number') . '</t></is></c>'
        . '<c r="B1" s="3" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t('Student name') . '</t></is></c>'
        . '<c r="C1" s="3" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t('Email') . '</t></is></c>'
        . '</row>';
    $lr = 1;
    foreach ($lookupRows as $lrRow) {
        $lr++;
        $lst = ($lr % 2 === 0) ? '4' : '5';
        $snL = trim((string)($lrRow['student_number'] ?? ''));
        $nameL = (string)($lrRow['full_name'] ?? '');
        $emL = (string)($lrRow['email'] ?? '');
        $a = '';
        if ($snL !== '' && preg_match('/^\d+$/', $snL)) {
            $a = '<c r="A' . $lr . '" s="' . $lst . '"><v>' . $snL . '</v></c>';
        } elseif ($snL !== '') {
            $a = '<c r="A' . $lr . '" s="' . $lst . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($snL) . '</t></is></c>';
        } else {
            $a = '<c r="A' . $lr . '" s="' . $lst . '"/>';
        }
        $lookupRowsXml[] = '<row r="' . $lr . '" ht="18">'
            . $a
            . '<c r="B' . $lr . '" s="' . $lst . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($nameL) . '</t></is></c>'
            . '<c r="C' . $lr . '" s="' . $lst . '" t="inlineStr"><is><t xml:space="preserve">' . ereview_xlsx_t($emL) . '</t></is></c>'
            . '</row>';
    }

    $lookupDim = 'A1:C' . (string)$lookupLast;
    $sheet2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <dimension ref="' . $lookupDim . '"/>
  <sheetViews>
    <sheetView workbookViewId="0">
      <selection activeCell="A1" sqref="A1"/>
    </sheetView>
  </sheetViews>
  <sheetFormatPr defaultRowHeight="15"/>
  <cols>
    <col min="1" max="1" width="14" customWidth="1"/>
    <col min="2" max="2" width="36" customWidth="1"/>
    <col min="3" max="3" width="38" customWidth="1"/>
  </cols>
  <sheetData>
    ' . implode("\n    ", $lookupRowsXml) . '
  </sheetData>
  <pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>
</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="6">
    <font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>
    <font><b/><sz val="15"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><sz val="10"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font>
    <font><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>
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
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="4" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>
  </cellXfs>
</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <fileVersion appName="xl"/>
  <workbookPr/>
  <bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="15000"/></bookViews>
  <sheets>
    <sheet name="Student progress" sheetId="1" r:id="rId1"/>
    <sheet name="Lookup" sheetId="2" r:id="rId2"/>
  </sheets>
</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

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
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>';

    $created = gmdate('c');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>' . ereview_xlsx_t($subtitle) . '</dc:title>
  <dc:creator>LCRC eReview</dc:creator>
  <dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>
</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>LCRC eReview</Application>
</Properties>';

    $tmp = tempnam(sys_get_temp_dir(), 'ereview_xlsx_');
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
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);
    $zip->close();

    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $examTitle) ?: 'exam';
    $safeName = substr($safeName, 0, 72) . '_student_progress.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . (string)filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
}
