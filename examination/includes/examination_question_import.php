<?php
declare(strict_types=1);

/**
 * Professor question import: CSV template + parse helpers (no DB writes).
 * Validation and atomic persistence live in examination_questions.php.
 */

if (!function_exists('examination_normalize_exam_type')) {
    require_once __DIR__ . '/examination_assignment.php';
}

/** Max upload size for import files (2 MiB). */
const EXAMINATION_QUESTION_IMPORT_MAX_BYTES = 2097152;

/**
 * @return list<string>
 */
function examination_question_import_headers(string $examType = 'regular', string $breakdownMode = 'overall'): array
{
    $examType = examination_normalize_exam_type($examType) ?: 'regular';
    $headers = ['question', 'type', 'choice_a', 'choice_b', 'choice_c', 'choice_d', 'correct'];
    if ($examType === 'regular') {
        $mode = strtolower(trim($breakdownMode));
        if ($mode === 'subject' || $mode === 'subject_topic' || $mode === 'by_subject' || $mode === 'by_subject_topic') {
            $headers[] = 'subject';
        }
        if ($mode === 'subject_topic' || $mode === 'by_subject_topic' || $mode === 'subject_and_topic') {
            $headers[] = 'topic';
        }
    }

    return $headers;
}

/**
 * @return list<array{question:string,type:string,choice_a:string,choice_b:string,choice_c:string,choice_d:string,correct:string}>
 */
function examination_question_import_example_rows(): array
{
    return [
        [
            'question' => 'What is 2 + 2?',
            'type' => 'mcq',
            'choice_a' => '3',
            'choice_b' => '4',
            'choice_c' => '5',
            'choice_d' => '6',
            'correct' => 'B',
        ],
        [
            'question' => 'The sun rises in the east.',
            'type' => 'tf',
            'choice_a' => 'True',
            'choice_b' => 'False',
            'choice_c' => '',
            'choice_d' => '',
            'correct' => 'A',
        ],
        [
            'question' => 'Which is a current asset? (2-choice example)',
            'type' => 'mcq',
            'choice_a' => 'Cash',
            'choice_b' => 'Building',
            'choice_c' => '',
            'choice_d' => '',
            'correct' => 'A',
        ],
    ];
}

function examination_question_import_csv_escape(string $value): string
{
    if (str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n") || str_contains($value, "\r")) {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    return $value;
}

/**
 * Build CSV body matching the import parser column order.
 */
function examination_question_import_build_csv(string $examType = 'regular', string $breakdownMode = 'overall'): string
{
    $examType = examination_normalize_exam_type($examType) ?: 'regular';
    $headers = examination_question_import_headers($examType, $breakdownMode);
    $lines = [implode(',', $headers)];
    foreach (examination_question_import_example_rows() as $row) {
        if ($examType === 'diagnostic' && strtolower($row['type']) === 'tf') {
            continue;
        }
        $cols = [
            examination_question_import_csv_escape($row['question']),
            examination_question_import_csv_escape($row['type']),
            examination_question_import_csv_escape($row['choice_a']),
            examination_question_import_csv_escape($row['choice_b']),
            examination_question_import_csv_escape($row['choice_c']),
            examination_question_import_csv_escape($row['choice_d']),
            examination_question_import_csv_escape($row['correct']),
        ];
        if (in_array('subject', $headers, true)) {
            $cols[] = examination_question_import_csv_escape('Tax');
        }
        if (in_array('topic', $headers, true)) {
            $cols[] = examination_question_import_csv_escape('Income Tax');
        }
        $lines[] = implode(',', $cols);
    }

    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Stream CSV template download (Excel-compatible; no Composer dependency).
 */
function examination_question_import_send_csv_template(string $examType = 'regular', string $breakdownMode = 'overall'): never
{
    $examType = examination_normalize_exam_type($examType) ?: 'regular';
    $name = $examType === 'diagnostic'
        ? 'ereview_diagnostic_questions_template.csv'
        : 'ereview_exam_questions_template.csv';
    $csv = examination_question_import_build_csv($examType, $breakdownMode);
    $body = "\xEF\xBB\xBF" . $csv;

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . (string)strlen($body));
    echo $body;
    exit;
}

/**
 * @return list<string>
 */
function examination_question_import_split_csv_line(string $line): array
{
    $parts = [];
    $cur = '';
    $inQ = false;
    $len = strlen($line);
    for ($c = 0; $c < $len; $c++) {
        $ch = $line[$c];
        if ($ch === '"') {
            $inQ = !$inQ;
            continue;
        }
        if ($ch === ',' && !$inQ) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    $parts[] = $cur;

    return $parts;
}

/**
 * @return list<array{question_text:string,question_type:string,choice_a:string,choice_b:string,choice_c:string,choice_d:string,correct_answer:string,subject_name?:string,topic_name?:string,_source_row:int}>
 */
function examination_question_import_parse_csv(string $text): array
{
    $lines = preg_split('/\r\n|\n|\r/', (string)$text) ?: [];
    $trimmed = [];
    foreach ($lines as $line) {
        $t = trim((string)$line);
        if ($t !== '') {
            $trimmed[] = $t;
        }
    }
    if ($trimmed === []) {
        return [];
    }

    $map = [
        'question' => 0,
        'type' => 1,
        'choice_a' => 2,
        'choice_b' => 3,
        'choice_c' => 4,
        'choice_d' => 5,
        'correct' => 6,
        'subject' => -1,
        'topic' => -1,
    ];
    $start = 0;
    if (preg_match('/question/i', $trimmed[0]) && preg_match('/correct/i', $trimmed[0])) {
        $start = 1;
        $hdrParts = examination_question_import_split_csv_line($trimmed[0]);
        foreach ($hdrParts as $hi => $hRaw) {
            $h = strtolower(trim((string)$hRaw));
            $h = str_replace([' ', '-'], '_', $h);
            if ($h === 'question' || $h === 'question_text') {
                $map['question'] = $hi;
            } elseif ($h === 'type' || $h === 'question_type') {
                $map['type'] = $hi;
            } elseif ($h === 'choice_a' || $h === 'a') {
                $map['choice_a'] = $hi;
            } elseif ($h === 'choice_b' || $h === 'b') {
                $map['choice_b'] = $hi;
            } elseif ($h === 'choice_c' || $h === 'c') {
                $map['choice_c'] = $hi;
            } elseif ($h === 'choice_d' || $h === 'd') {
                $map['choice_d'] = $hi;
            } elseif ($h === 'correct' || $h === 'correct_answer' || $h === 'answer') {
                $map['correct'] = $hi;
            } elseif ($h === 'subject' || $h === 'subject_name') {
                $map['subject'] = $hi;
            } elseif ($h === 'topic' || $h === 'topic_name') {
                $map['topic'] = $hi;
            }
        }
    }

    $rows = [];
    for ($i = $start; $i < count($trimmed); $i++) {
        $parts = examination_question_import_split_csv_line($trimmed[$i]);
        if (count($parts) < 2) {
            continue;
        }
        $type = strtolower(trim((string)($parts[$map['type']] ?? 'mcq')));
        if ($type !== 'tf') {
            $type = 'mcq';
        }
        $row = [
            'question_text' => trim((string)($parts[$map['question']] ?? '')),
            'question_type' => $type,
            'choice_a' => trim((string)($parts[$map['choice_a']] ?? '')),
            'choice_b' => trim((string)($parts[$map['choice_b']] ?? '')),
            'choice_c' => trim((string)($parts[$map['choice_c']] ?? '')),
            'choice_d' => trim((string)($parts[$map['choice_d']] ?? '')),
            'correct_answer' => strtoupper(trim((string)($parts[$map['correct']] ?? ''))),
            '_source_row' => $i + 1,
        ];
        if ($map['subject'] >= 0) {
            $row['subject_name'] = trim((string)($parts[$map['subject']] ?? ''));
        }
        if ($map['topic'] >= 0) {
            $row['topic_name'] = trim((string)($parts[$map['topic']] ?? ''));
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Human-readable structured text (Word template / paste).
 * Supports:
 *   1. Question
 *   A. ...
 *   Answer: B
 * and True/False with Answer: True|False
 *
 * @return list<array{question_text:string,question_type:string,choice_a:string,choice_b:string,choice_c:string,choice_d:string,correct_answer:string,_source_row:int}>
 */
function examination_question_import_parse_structured_text(string $text): array
{
    $raw = preg_split('/\r\n|\n|\r/', (string)$text) ?: [];
    $lines = [];
    foreach ($raw as $line) {
        $lines[] = trim((string)$line);
    }
    $n = count($lines);
    $rows = [];
    $i = 0;
    $qIndex = 0;

    $isChoiceLine = static function (string $line): bool {
        return (bool)preg_match('/^[A-Da-d][\)\.\:\-]\s+\S/u', $line)
            || (bool)preg_match('/^[A-Da-d][\)\.\:\-]\s*$/u', $line);
    };
    $isAnswerLine = static function (string $line): bool {
        return (bool)preg_match('/^answer\s*[:\-]/i', $line);
    };
    $isBareTf = static function (string $line): bool {
        return (bool)preg_match('/^(true|false)$/i', $line);
    };

    while ($i < $n) {
        while ($i < $n && $lines[$i] === '') {
            $i++;
        }
        if ($i >= $n) {
            break;
        }

        $stemParts = [];
        $startLine = $i + 1;
        while (
            $i < $n
            && $lines[$i] !== ''
            && !$isChoiceLine($lines[$i])
            && !$isAnswerLine($lines[$i])
            && !$isBareTf($lines[$i])
        ) {
            $stemParts[] = $lines[$i];
            $i++;
        }
        while ($i < $n && $lines[$i] === '') {
            $i++;
        }

        $choices = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
        $type = 'mcq';
        $sawTfBare = false;

        if ($i < $n && $isBareTf($lines[$i])) {
            $sawTfBare = true;
            $type = 'tf';
            while ($i < $n && $isBareTf($lines[$i])) {
                if (strcasecmp($lines[$i], 'true') === 0) {
                    $choices['A'] = 'True';
                } else {
                    $choices['B'] = 'False';
                }
                $i++;
            }
        } else {
            while ($i < $n && $isChoiceLine($lines[$i])) {
                if (preg_match('/^([A-Da-d])[\)\.\:\-]\s*(.*)$/u', $lines[$i], $m)) {
                    $choices[strtoupper($m[1])] = trim((string)$m[2]);
                }
                $i++;
            }
        }
        while ($i < $n && $lines[$i] === '') {
            $i++;
        }

        $ans = '';
        if ($i < $n && $isAnswerLine($lines[$i])) {
            if (preg_match('/^answer\s*[:\-]\s*(.+)$/i', $lines[$i], $am)) {
                $ansRaw = trim((string)$am[1]);
                if ($sawTfBare || preg_match('/^(true|false)$/i', $ansRaw)) {
                    $type = 'tf';
                    $choices['A'] = 'True';
                    $choices['B'] = 'False';
                    $choices['C'] = '';
                    $choices['D'] = '';
                    if (preg_match('/^true$/i', $ansRaw) || strtoupper($ansRaw) === 'A') {
                        $ans = 'A';
                    } else {
                        $ans = 'B';
                    }
                } elseif (preg_match('/^[A-Da-d]/', $ansRaw)) {
                    $ans = strtoupper(substr($ansRaw, 0, 1));
                }
            }
            $i++;
        }

        $stem = trim(implode(' ', $stemParts));
        $stem = (string)preg_replace('/^\d+[\.\)]\s+/u', '', $stem);
        // Skip empty scraps and stem-only preamble/instructions (no choices, no Answer line).
        if (
            $ans === ''
            && $choices['A'] === ''
            && $choices['B'] === ''
            && $choices['C'] === ''
            && $choices['D'] === ''
        ) {
            continue;
        }
        if ($type === 'tf') {
            $choices['A'] = 'True';
            $choices['B'] = 'False';
            $choices['C'] = '';
            $choices['D'] = '';
        }
        $qIndex++;
        $rows[] = [
            'question_text' => $stem,
            'question_type' => $type,
            'choice_a' => $choices['A'],
            'choice_b' => $choices['B'],
            'choice_c' => $choices['C'],
            'choice_d' => $choices['D'],
            'correct_answer' => $ans,
            '_source_row' => $startLine,
        ];
    }

    return $rows;
}

/**
 * Paste import — structured Word-like text (preferred) with legacy blank-line MCQ blocks as fallback.
 *
 * @return list<array{question_text:string,question_type:string,choice_a:string,choice_b:string,choice_c:string,choice_d:string,correct_answer:string,_source_row:int}>
 */
function examination_question_import_parse_paste(string $text): array
{
    $structured = examination_question_import_parse_structured_text($text);
    if ($structured !== []) {
        return $structured;
    }

    // Legacy blank-line MCQ blocks (A) / Answer: B)
    $blocks = preg_split("/\n\s*\n/", (string)$text) ?: [];
    $rows = [];
    $blockNum = 0;
    foreach ($blocks as $block) {
        $blockNum++;
        $lines = preg_split('/\r\n|\n|\r/', (string)$block) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $t = trim((string)$line);
            if ($t !== '') {
                $clean[] = $t;
            }
        }
        if ($clean === []) {
            continue;
        }
        $choices = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
        $ans = 'A';
        for ($j = 1; $j < count($clean); $j++) {
            $line = $clean[$j];
            if (preg_match('/^([A-Da-d])[\)\.\:\-\s]\s*(.*)$/', $line, $m)) {
                $choices[strtoupper($m[1])] = trim((string)$m[2]);
                continue;
            }
            if (preg_match('/^answer\s*[:\-]\s*([A-Da-d])/i', $line, $a)) {
                $ans = strtoupper($a[1]);
            }
        }
        $rows[] = [
            'question_text' => $clean[0],
            'question_type' => 'mcq',
            'choice_a' => $choices['A'],
            'choice_b' => $choices['B'],
            'choice_c' => $choices['C'],
            'choice_d' => $choices['D'],
            'correct_answer' => $ans,
            '_source_row' => $blockNum,
        ];
    }

    return $rows;
}

/**
 * Parse .docx via existing ZipArchive-based project helper (no new dependency).
 *
 * @return list<array{question_text:string,question_type:string,choice_a:string,choice_b:string,choice_c:string,choice_d:string,correct_answer:string,_source_row:int}>
 */
function examination_question_import_parse_docx(string $docxPath): array
{
    $parser = dirname(__DIR__, 2) . '/includes/docx_question_parser.php';
    if (!is_file($parser)) {
        throw new RuntimeException('DOCX parser is not available.');
    }
    require_once $parser;
    if (!function_exists('ereview_docx_extract_paragraphs')) {
        throw new RuntimeException('DOCX parser is not available.');
    }
    $paras = ereview_docx_extract_paragraphs($docxPath);
    $lines = [];
    foreach ($paras as $p) {
        if (!is_array($p)) {
            continue;
        }
        $plain = trim((string)($p['plain'] ?? ''));
        $lines[] = $plain;
    }

    return examination_question_import_parse_structured_text(implode("\n", $lines));
}

function examination_question_import_xml_t(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Build a minimal .docx (OOXML) template with the professor-facing structure.
 */
function examination_question_import_build_docx_bytes(string $examType = 'regular'): string
{
    $examType = examination_normalize_exam_type($examType) ?: 'regular';
    // Body is only sample questions in the required human-readable structure.
    // UI copy explains how to replace examples; avoid stem-only instruction paragraphs.
    $paras = [
        '1. What is 2 + 2?',
        '',
        'A. 3',
        'B. 4',
        'C. 5',
        'D. 6',
        '',
        'Answer: B',
        '',
    ];
    if ($examType !== 'diagnostic') {
        $paras = array_merge($paras, [
            '2. The sun rises in the east.',
            '',
            'True',
            'False',
            '',
            'Answer: True',
            '',
        ]);
    }
    $paras = array_merge($paras, [
        ($examType === 'diagnostic' ? '2' : '3') . '. Which is a current asset?',
        '',
        'A. Cash',
        'B. Building',
        '',
        'Answer: A',
    ]);

    $bodyXml = '';
    foreach ($paras as $line) {
        if ($line === '') {
            $bodyXml .= '<w:p/>';
            continue;
        }
        $bodyXml .= '<w:p><w:r><w:t xml:space="preserve">' . examination_question_import_xml_t($line) . '</w:t></w:r></w:p>';
    }
    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $bodyXml . '<w:sectPr/></w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';
    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'ereview_q_docx_');
    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary file for Word template.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not build Word template.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/_rels/document.xml.rels', $docRels);
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

/**
 * Stream preferred Word template (.docx). Falls back to CSV if ZipArchive fails.
 */
function examination_question_import_send_template(string $examType = 'regular', string $format = 'docx', string $breakdownMode = 'overall'): never
{
    $examType = examination_normalize_exam_type($examType) ?: 'regular';
    $format = strtolower(trim($format));
    if ($format === 'csv') {
        examination_question_import_send_csv_template($examType, $breakdownMode);
    }

    try {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive unavailable');
        }
        $bytes = examination_question_import_build_docx_bytes($examType);
        $name = $examType === 'diagnostic'
            ? 'ereview_diagnostic_questions_template.docx'
            : 'ereview_exam_questions_template.docx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . (string)strlen($bytes));
        echo $bytes;
        exit;
    } catch (Throwable $e) {
        error_log('[examination_question_import] docx template failed: ' . $e->getMessage());
        examination_question_import_send_csv_template($examType, $breakdownMode);
    }
}

/**
 * Resolve import rows from POST (DOCX/CSV upload, paste, or preview JSON).
 *
 * @return array{ok:bool,rows:list<array>,error?:string}
 */
function examination_question_import_resolve_rows_from_request(): array
{
    // Prefer validated JSON from the preview step when present (server still re-validates).
    $rawJson = $_POST['import_json'] ?? '';
    if (is_string($rawJson) && trim($rawJson) !== '' && trim($rawJson) !== '[]') {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'rows' => [], 'error' => 'Import data is invalid. Please validate your questions again.'];
        }
        $rows = [];
        foreach ($decoded as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!isset($item['_source_row'])) {
                $item['_source_row'] = $i + 1;
            }
            $rows[] = $item;
        }

        return ['ok' => true, 'rows' => $rows];
    }

    if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file((string)$_FILES['import_file']['tmp_name'])) {
        $name = (string)($_FILES['import_file']['name'] ?? '');
        $size = (int)($_FILES['import_file']['size'] ?? 0);
        $err = (int)($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'rows' => [], 'error' => 'Could not upload the file. Please try again.'];
        }
        if ($size <= 0 || $size > EXAMINATION_QUESTION_IMPORT_MAX_BYTES) {
            return ['ok' => false, 'rows' => [], 'error' => 'The file is too large. Please use a file under 2 MB.'];
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $tmp = (string)$_FILES['import_file']['tmp_name'];
        if ($ext === 'docx') {
            try {
                $rows = examination_question_import_parse_docx($tmp);

                return ['ok' => true, 'rows' => $rows];
            } catch (Throwable $e) {
                error_log('[examination_question_import] docx parse: ' . $e->getMessage());

                return [
                    'ok' => false,
                    'rows' => [],
                    'error' => 'Could not read the Word file. Please use the Download Template format, or upload the CSV fallback.',
                ];
            }
        }
        if ($ext === 'csv' || $ext === 'txt') {
            $raw = @file_get_contents($tmp);
            if ($raw === false) {
                return ['ok' => false, 'rows' => [], 'error' => 'Could not read the uploaded file.'];
            }
            if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                $raw = substr($raw, 3);
            }

            return ['ok' => true, 'rows' => examination_question_import_parse_csv($raw)];
        }

        return [
            'ok' => false,
            'rows' => [],
            'error' => 'Please upload a Word (.docx) template file, or a CSV fallback file.',
        ];
    }

    $paste = trim((string)($_POST['import_paste'] ?? ''));
    if ($paste !== '') {
        $mode = strtolower(trim((string)($_POST['import_mode'] ?? 'paste')));
        $rows = $mode === 'csv'
            ? examination_question_import_parse_csv($paste)
            : examination_question_import_parse_paste($paste);

        return ['ok' => true, 'rows' => $rows];
    }

    return ['ok' => false, 'rows' => [], 'error' => 'No import data provided. Upload a Word template or paste your questions.'];
}
