<?php
/**
 * Minimal PDF (PDF 1.4, Helvetica) for exam progress reports — no Composer deps.
 * Landscape A4, yellow/blue theme, centered layout. Text via Windows-1252 transliteration.
 */

declare(strict_types=1);

function ereview_pdf_winansi(string $utf8, int $maxLen = 500): string
{
    $utf8 = preg_replace('/\s+/u', ' ', trim($utf8)) ?? '';
    if ($utf8 === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($utf8, 'UTF-8') > $maxLen) {
        $utf8 = mb_substr($utf8, 0, $maxLen, 'UTF-8') . '…';
    } elseif (strlen($utf8) > $maxLen * 3) {
        $utf8 = substr($utf8, 0, $maxLen * 3) . '…';
    }
    $t = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $utf8);
    if ($t === false) {
        $t = @iconv('UTF-8', 'Windows-1252//IGNORE', $utf8) ?: '?';
    }

    return $t;
}

function ereview_pdf_literal(string $s): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

/** Approximate horizontal offset to center single-line Helvetica text (procedural PDF). */
function ereview_pdf_center_tm_x(string $text, float $fontSize, float $pageW): float
{
    $n = max(1, strlen($text));
    $approxWidth = $n * $fontSize * 0.52;

    return max(24.0, ($pageW - $approxWidth) / 2);
}

/**
 * Split score for PDF: emphasize "40/50"; keep " | 80.00%" secondary.
 *
 * @return array{mode:string,frac?:string,rest?:string,single?:string}
 */
function ereview_pdf_score_display_parts(string $winansiScore): array
{
    $s = trim($winansiScore);
    if ($s === '' || $s === '—' || $s === '-') {
        return ['mode' => 'single', 'single' => $s !== '' ? $s : '-'];
    }
    if (preg_match('/^(.+?)\s*\|\s*(.+)$/', $s, $m)) {
        $frac = trim($m[1]);
        $rest = ' | ' . trim($m[2]);

        return ['mode' => 'split', 'frac' => $frac, 'rest' => $rest];
    }
    if (preg_match('/^\d+\s*\/\s*\d+$/', $s)) {
        return ['mode' => 'split', 'frac' => preg_replace('/\s+/', '', $s), 'rest' => ''];
    }

    return ['mode' => 'single', 'single' => $s];
}

/**
 * @param list<array{student_number:string,name:string,email:string,status:string,score:string}> $rows
 */
function ereview_output_exam_progress_pdf(string $examTitle, array $rows): void
{
    $title = ereview_pdf_winansi($examTitle, 220);
    $generatedLong = ereview_pdf_winansi(date('M j, Y g:i A') . ' - Time generated (report)', 120);
    $timeOnly = ereview_pdf_winansi('Time generated: ' . date('M j, Y g:i A'), 100);
    $brand = 'LCRC eReview';

    $lineH = 17.0;
    $tableHeadH = 21.0;
    $gapAfterHeader = 14.0;
    $marginTop = 36.0;
    $marginBottom = 42.0;

    $pageW = 842.0;
    $pageH = 595.28;

    $tableWidth = 720.0;
    $tableLeft = ($pageW - $tableWidth) / 2;

    $headerCardH = 92.0;
    $goldStripH = 7.0;
    $blueCardH = $headerCardH - $goldStripH;

    $headerY0 = $pageH - $marginTop - $headerCardH;
    $footerY = 26.0;
    $contentBottom = $marginBottom + 36.0;

    $hdrY = $headerY0 - 10.0 - $tableHeadH;
    $firstRowY = $hdrY - $tableHeadH - 2.0 - $gapAfterHeader;
    $usableH = $firstRowY - $contentBottom;
    $rowsPerPage = max(1, (int) floor($usableH / $lineH));

    $chunks = array_chunk($rows, $rowsPerPage);
    if ($chunks === []) {
        $chunks = [[]];
    }
    $totalPages = count($chunks);

    $colW = [40.0, 66.0, 158.0, 158.0, 98.0, 200.0];
    $colX = [$tableLeft];
    for ($i = 1; $i < 6; $i++) {
        $colX[$i] = $colX[$i - 1] + $colW[$i - 1];
    }

    $blue = [30 / 255, 64 / 255, 175 / 255];
    $blueLight = [239 / 255, 246 / 255, 255 / 255];
    $gold = [251 / 255, 191 / 255, 36 / 255];
    $goldDeep = [245 / 255, 158 / 255, 11 / 255];
    $cream = [255 / 255, 251 / 255, 235 / 255];
    $rowA = [0.98, 0.99, 1.0];
    $rowB = [1.0, 0.99, 0.94];
    $borderBlue = [0.65, 0.78, 0.95];
    $textDark = [0.12, 0.18, 0.28];
    $textMuted = [0.35, 0.42, 0.52];
    $scoreFracBlue = [12 / 255, 74 / 255, 168 / 255];
    $scorePctMuted = [0.38, 0.44, 0.52];

    $pageStreams = [];
    $rowSerial = 0;

    foreach ($chunks as $pi => $pageRows) {
        $pnum = $pi + 1;
        $lines = [];

        $lines[] = 'q';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $blueLight[0], $blueLight[1], $blueLight[2]);
        $lines[] = sprintf('%.2F %.2F %.2F %.2F re', 0, 0, $pageW, $pageH);
        $lines[] = 'f';
        $lines[] = 'Q';

        $lines[] = 'q';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $blue[0], $blue[1], $blue[2]);
        $lines[] = sprintf('%.2F %.2F %.2F %.2F re', $tableLeft, $headerY0, $tableWidth, $blueCardH);
        $lines[] = 'f';
        $lines[] = 'Q';

        $lines[] = 'q';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $gold[0], $gold[1], $gold[2]);
        $lines[] = sprintf('%.2F %.2F %.2F %.2F re', $tableLeft, $headerY0 + $blueCardH, $tableWidth, $goldStripH);
        $lines[] = 'f';
        $lines[] = 'Q';

        $lines[] = 'q';
        $lines[] = sprintf('%.4F %.4F %.4F RG', $goldDeep[0], $goldDeep[1], $goldDeep[2]);
        $lines[] = sprintf('%.4F w', 1.0);
        $lines[] = sprintf('%.2F %.2F m', $tableLeft, $headerY0);
        $lines[] = sprintf('%.2F %.2F l', $tableLeft + $tableWidth, $headerY0);
        $lines[] = 'S';
        $lines[] = 'Q';

        $ty = $headerY0 + $blueCardH - 20.0;
        $lines[] = 'BT';
        $lines[] = '/F2 12 Tf';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $gold[0], $gold[1], $gold[2]);
        $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', ereview_pdf_center_tm_x($brand, 12, $pageW), $ty);
        $lines[] = '(' . ereview_pdf_literal($brand) . ') Tj';
        $lines[] = 'ET';

        $ty -= 16.0;
        $lines[] = 'BT';
        $lines[] = '/F2 11 Tf';
        $lines[] = sprintf('%.4F %.4F %.4F rg', 1, 1, 1);
        $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', ereview_pdf_center_tm_x('Exam progress report', 11, $pageW), $ty);
        $lines[] = '(' . ereview_pdf_literal('Exam progress report') . ') Tj';
        $lines[] = 'ET';

        $ty -= 15.0;
        $lines[] = 'BT';
        $lines[] = '/F2 10 Tf';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $cream[0], $cream[1], $cream[2]);
        $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', ereview_pdf_center_tm_x($title, 10, $pageW), $ty);
        $lines[] = '(' . ereview_pdf_literal($title) . ') Tj';
        $lines[] = 'ET';

        $ty -= 14.0;
        $lines[] = 'BT';
        $lines[] = '/F1 8.5 Tf';
        $lines[] = sprintf('%.4F %.4F %.4F rg', 1, 0.96, 0.78);
        $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', ereview_pdf_center_tm_x($timeOnly, 8.5, $pageW), $ty);
        $lines[] = '(' . ereview_pdf_literal($timeOnly) . ') Tj';
        $lines[] = 'ET';

        $lines[] = 'q';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $blue[0] * 0.35 + 0.65, $blue[1] * 0.35 + 0.65, $blue[2] * 0.35 + 0.65);
        $lines[] = sprintf('%.2F %.2F %.2F %.2F re', $tableLeft, $hdrY - $tableHeadH + 2, $tableWidth, $tableHeadH);
        $lines[] = 'f';
        $lines[] = 'Q';
        $lines[] = 'BT';
        $lines[] = '/F2 8.5 Tf';
        $lines[] = sprintf('%.4F %.4F %.4F rg', 1, 1, 1);
        $headers = ['#', 'Stud. no.', 'Student name', 'Email', 'Status', 'Score'];
        for ($c = 0; $c < 6; $c++) {
            $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $colX[$c] + ($c === 0 ? 2 : 4), $hdrY - 5);
            $lines[] = '(' . ereview_pdf_literal($headers[$c]) . ') Tj';
        }
        $lines[] = 'ET';

        $lines[] = 'q';
        $lines[] = sprintf('%.4F %.4F %.4F RG', $goldDeep[0], $goldDeep[1], $goldDeep[2]);
        $lines[] = sprintf('%.4F w', 0.85);
        $lines[] = sprintf('%.2F %.2F m', $tableLeft, $hdrY - $tableHeadH + 2);
        $lines[] = sprintf('%.2F %.2F l', $tableLeft + $tableWidth, $hdrY - $tableHeadH + 2);
        $lines[] = 'S';
        $lines[] = 'Q';

        $y = $firstRowY;
        $ri = 0;
        foreach ($pageRows as $row) {
            $rowSerial++;
            $fill = ($ri % 2 === 0) ? $rowA : $rowB;
            $lines[] = 'q';
            $lines[] = sprintf('%.4F %.4F %.4F rg', $fill[0], $fill[1], $fill[2]);
            $lines[] = sprintf('%.2F %.2F %.2F %.2F re', $tableLeft, $y - $lineH + 3, $tableWidth, $lineH);
            $lines[] = 'f';
            $lines[] = 'Q';

            $numStr = (string)$rowSerial;
            $numX = $colX[0] + $colW[0] - strlen($numStr) * 4.0 - 4;
            $snRaw = trim((string)($row['student_number'] ?? ''));
            $studNoDisp = $snRaw !== '' ? ereview_pdf_winansi($snRaw, 14) : '—';
            $cells = [
                ereview_pdf_winansi((string)$row['name'], 40),
                ereview_pdf_winansi((string)$row['email'], 50),
                ereview_pdf_winansi((string)$row['status'], 22),
                ereview_pdf_winansi((string)$row['score'], 36),
            ];
            $scoreParts = ereview_pdf_score_display_parts($cells[3]);
            $sx = $colX[5] + 4;
            if ($scoreParts['mode'] === 'split' && ($scoreParts['frac'] ?? '') !== '') {
                $fracOnly = (string)$scoreParts['frac'];
                $pillW = min(strlen($fracOnly) * 5.15 + 8, $colW[5] - 12);
                $lines[] = 'q';
                $lines[] = sprintf('%.4F %.4F %.4F rg', 0.93, 0.96, 1.0);
                $lines[] = sprintf('%.4F %.4F %.4F RG', 0.55, 0.72, 0.95);
                $lines[] = sprintf('%.4F w', 0.35);
                $lines[] = sprintf('%.2F %.2F %.2F %.2F re', $sx - 2, $y - 11, $pillW, 12);
                $lines[] = 'f';
                $lines[] = 'S';
                $lines[] = 'Q';
            }

            $lines[] = 'BT';
            $lines[] = '/F2 8 Tf';
            $lines[] = sprintf('%.4F %.4F %.4F rg', $textMuted[0], $textMuted[1], $textMuted[2]);
            $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $numX, $y - 2);
            $lines[] = '(' . ereview_pdf_literal($numStr) . ') Tj';
            $lines[] = '/F1 8 Tf';
            $lines[] = sprintf('%.4F %.4F %.4F rg', $textDark[0], $textDark[1], $textDark[2]);
            $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $colX[1] + 4, $y - 2);
            $lines[] = '(' . ereview_pdf_literal($studNoDisp) . ') Tj';
            $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $colX[2] + 4, $y - 2);
            $lines[] = '(' . ereview_pdf_literal($cells[0]) . ') Tj';
            $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $colX[3] + 4, $y - 2);
            $lines[] = '(' . ereview_pdf_literal($cells[1]) . ') Tj';
            $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $colX[4] + 4, $y - 2);
            $lines[] = '(' . ereview_pdf_literal($cells[2]) . ') Tj';

            if ($scoreParts['mode'] === 'split') {
                $frac = $scoreParts['frac'] ?? '';
                $rest = $scoreParts['rest'] ?? '';
                $lines[] = '/F2 9 Tf';
                $lines[] = sprintf('%.4F %.4F %.4F rg', $scoreFracBlue[0], $scoreFracBlue[1], $scoreFracBlue[2]);
                $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $sx, $y - 2);
                $lines[] = '(' . ereview_pdf_literal($frac) . ') Tj';
                $fracW = strlen($frac) * 4.85;
                if ($rest !== '') {
                    $lines[] = '/F1 8 Tf';
                    $lines[] = sprintf('%.4F %.4F %.4F rg', $scorePctMuted[0], $scorePctMuted[1], $scorePctMuted[2]);
                    $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $sx + $fracW, $y - 2);
                    $lines[] = '(' . ereview_pdf_literal($rest) . ') Tj';
                }
            } else {
                $single = $scoreParts['single'] ?? '';
                $lines[] = '/F1 8 Tf';
                $lines[] = sprintf('%.4F %.4F %.4F rg', $textDark[0], $textDark[1], $textDark[2]);
                $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $sx, $y - 2);
                $lines[] = '(' . ereview_pdf_literal($single) . ') Tj';
            }
            $lines[] = 'ET';

            $lines[] = 'q';
            $lines[] = sprintf('%.4F %.4F %.4F RG', $borderBlue[0], $borderBlue[1], $borderBlue[2]);
            $lines[] = sprintf('%.4F w', 0.25);
            $lines[] = sprintf('%.2F %.2F m', $tableLeft, $y - $lineH + 3);
            $lines[] = sprintf('%.2F %.2F l', $tableLeft + $tableWidth, $y - $lineH + 3);
            $lines[] = 'S';
            $lines[] = 'Q';

            $y -= $lineH;
            $ri++;
        }

        if ($pageRows === []) {
            $lines[] = 'BT';
            $lines[] = '/F1 9 Tf';
            $lines[] = sprintf('%.4F %.4F %.4F rg', $textMuted[0], $textMuted[1], $textMuted[2]);
            $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', ereview_pdf_center_tm_x('No students on roster.', 9, $pageW), $y - 8);
            $lines[] = '(' . ereview_pdf_literal('No students on roster.') . ') Tj';
            $lines[] = 'ET';
        }

        $lines[] = 'q';
        $lines[] = sprintf('%.4F %.4F %.4F RG', $blue[0] * 0.55 + 0.45, $blue[1] * 0.55 + 0.45, $blue[2] * 0.55 + 0.45);
        $lines[] = sprintf('%.4F w', 0.6);
        $lines[] = sprintf('%.2F %.2F m', $tableLeft, $footerY + 28);
        $lines[] = sprintf('%.2F %.2F l', $tableLeft + $tableWidth, $footerY + 28);
        $lines[] = 'S';
        $lines[] = 'Q';

        $footLeft = $brand . '  |  Confidential student summary';
        $footRight = 'Page ' . $pnum . ' of ' . $totalPages;
        $footRightX = $tableLeft + $tableWidth - strlen($footRight) * 3.85 - 6;
        $lines[] = 'BT';
        $lines[] = '/F1 7.5 Tf';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $textMuted[0], $textMuted[1], $textMuted[2]);
        $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $tableLeft, $footerY + 14);
        $lines[] = '(' . ereview_pdf_literal($footLeft) . ') Tj';
        $lines[] = '/F1 7.5 Tf';
        $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', $footRightX, $footerY + 14);
        $lines[] = '(' . ereview_pdf_literal($footRight) . ') Tj';
        $lines[] = 'ET';

        $lines[] = 'BT';
        $lines[] = '/F2 7.5 Tf';
        $lines[] = sprintf('%.4F %.4F %.4F rg', $blue[0], $blue[1], $blue[2]);
        $lines[] = sprintf('1 0 0 1 %.2F %.2F Tm', ereview_pdf_center_tm_x($generatedLong, 7.5, $pageW), $footerY + 2);
        $lines[] = '(' . ereview_pdf_literal($generatedLong) . ') Tj';
        $lines[] = 'ET';

        $pageStreams[] = implode("\n", $lines);
    }

    $objects = [];
    $objects[] = null;
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

    $pageObjIds = [];
    $contentObjIds = [];
    $kidRefs = [];
    $baseId = 3;
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
        $cid = $contentObjIds[$i];
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageW . ' ' . $pageH . '] /Contents ' . $cid . ' 0 R /Resources << /Font << /F1 ' . $font1Id . ' 0 R /F2 ' . $font2Id . ' 0 R >> >> >>';
        $stream = $pageStreams[$i];
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
    $pdf .= "xref\n0 " . ($objCount + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $objCount; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . ($objCount + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $examTitle) ?: 'exam';
    $safeName = substr($safeName, 0, 72) . '_student_progress.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store');
    echo $pdf;
}
