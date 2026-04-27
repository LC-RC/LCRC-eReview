<?php
/**
 * Build a minimal .docx (OOXML) from grouped question data with w:highlight preserved per run.
 */
require_once __DIR__ . '/docx_question_parser.php';

if (!function_exists('ereview_docx_runs_to_ooxml')) {
    function ereview_docx_runs_to_ooxml(array $runs): string {
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xml = '';
        foreach ($runs as $run) {
            $text = (string)($run['text'] ?? '');
            $hl = isset($run['highlight']) ? (string)$run['highlight'] : '';
            $xml .= '<w:r>';
            if ($hl !== '') {
                $xml .= '<w:rPr><w:highlight w:val="' . ereview_docx_escape_xml_text($hl) . '"/></w:rPr>';
            }
            if ($text !== '') {
                $xml .= '<w:t xml:space="preserve">' . ereview_docx_escape_xml_text($text) . '</w:t>';
            }
            $xml .= '</w:r>';
        }
        return $xml;
    }
}

if (!function_exists('ereview_docx_html_to_simple_runs')) {
    /**
     * Fallback: strip tags to plain text as single run (when exporting from HTML-only fragments).
     */
    function ereview_docx_html_to_simple_runs(string $html): array {
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $plain === '' ? [] : [['text' => $plain, 'highlight' => '']];
    }
}

/**
 * @param array $grouped Result of ereview_docx_parse_and_group()
 * @return string XML body fragment (w:p elements only)
 */
if (!function_exists('ereview_docx_build_document_body_xml')) {
    function ereview_docx_build_document_body_xml(array $grouped): string {
        $body = '';
        $gps = $grouped['general_problems'] ?? [];
        if (is_array($gps) && $gps !== []) {
            $body .= '<w:p><w:pPr/><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t xml:space="preserve">' . ereview_docx_escape_xml_text('General problems') . '</w:t></w:r></w:p>';
            foreach ($gps as $gp) {
                if (!is_array($gp)) {
                    continue;
                }
                $after = trim((string)($gp['extracted_after_question'] ?? ''));
                if ($after !== '') {
                    $body .= '<w:p><w:pPr/><w:r><w:rPr><w:i/></w:rPr><w:t xml:space="preserve">' . ereview_docx_escape_xml_text('Context for items following question ' . $after . '.') . '</w:t></w:r></w:p>';
                }
                foreach ($gp['stem_paragraphs'] ?? [] as $sp) {
                    if (!is_array($sp)) {
                        continue;
                    }
                    $runs = $sp['runs'] ?? ereview_docx_html_to_simple_runs($sp['html'] ?? '');
                    $inner = ereview_docx_runs_to_ooxml($runs);
                    $body .= $inner === '' ? '<w:p/>' : '<w:p>' . $inner . '</w:p>';
                }
                $body .= '<w:p/>';
            }
        }
        foreach ($grouped['topics'] as $topicName => $list) {
            $body .= '<w:p><w:pPr/><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t xml:space="preserve">' . ereview_docx_escape_xml_text('Topic: ' . $topicName) . '</w:t></w:r></w:p>';
            foreach ($list as $q) {
                $body .= '<w:p/>';
                foreach ($q['stem_paragraphs'] as $sp) {
                    $runs = $sp['runs'] ?? ereview_docx_html_to_simple_runs($sp['html'] ?? '');
                    $inner = ereview_docx_runs_to_ooxml($runs);
                    $body .= $inner === '' ? '<w:p/>' : '<w:p>' . $inner . '</w:p>';
                }
                foreach ($q['choice_paragraphs'] as $cp) {
                    $runs = $cp['runs'] ?? ereview_docx_html_to_simple_runs($cp['html'] ?? '');
                    $inner = ereview_docx_runs_to_ooxml($runs);
                    $body .= $inner === '' ? '<w:p/>' : '<w:p>' . $inner . '</w:p>';
                }
            }
        }
        $body .= '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>';
        return $body;
    }
}

/**
 * @return string path to temp .docx file, or false on failure
 */
if (!function_exists('ereview_docx_write_grouped_docx')) {
    function ereview_docx_write_grouped_docx(array $grouped, string $targetPath): bool {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $documentInner = ereview_docx_build_document_body_xml($grouped);
        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . $w . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $documentInner . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>';

        $wordRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';

        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Grouped questions</dc:title><dc:creator>LCRC eReview</dc:creator></cp:coreProperties>';

        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/_rels/document.xml.rels', $wordRels);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->close();
        return is_file($targetPath);
    }
}
