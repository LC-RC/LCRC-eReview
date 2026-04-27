<?php
/**
 * Parse .docx (WordprocessingML) into paragraph runs with highlight preservation,
 * then split into numbered MCQ blocks and group by trailing (Topic) on the stem.
 *
 * Does not modify source text — only reads structure from word/document.xml.
 */

if (!function_exists('ereview_docx_xml_namespace')) {
    function ereview_docx_xml_namespace(): string {
        return 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    }
}

if (!function_exists('ereview_docx_escape_xml_text')) {
    function ereview_docx_escape_xml_text(string $s): string {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Build numbering metadata maps from numbering.xml (if available).
 *
 * @return array{abstract_levels: array<string, array{start:int,fmt:string,lvlText:string}>, num_to_abstract: array<int,int>}
 */
if (!function_exists('ereview_docx_build_numbering_maps')) {
    function ereview_docx_build_numbering_maps(?string $numberingXml): array {
        $maps = [
            'abstract_levels' => [],
            'num_to_abstract' => [],
        ];
        if ($numberingXml === null || trim($numberingXml) === '') {
            return $maps;
        }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($numberingXml)) {
            return $maps;
        }
        $xp = new DOMXPath($dom);
        $ns = ereview_docx_xml_namespace();
        $xp->registerNamespace('w', $ns);

        foreach ($xp->query('//w:abstractNum') as $abs) {
            if (!$abs instanceof DOMElement) {
                continue;
            }
            $aid = $abs->getAttributeNS($ns, 'abstractNumId');
            if ($aid === '') {
                $aid = $abs->getAttribute('w:abstractNumId');
            }
            if ($aid === '' || !is_numeric($aid)) {
                continue;
            }
            $absId = (int)$aid;
            foreach ($xp->query('w:lvl', $abs) as $lvlNode) {
                if (!$lvlNode instanceof DOMElement) {
                    continue;
                }
                $il = $lvlNode->getAttributeNS($ns, 'ilvl');
                if ($il === '') {
                    $il = $lvlNode->getAttribute('w:ilvl');
                }
                if ($il === '' || !is_numeric($il)) {
                    continue;
                }
                $ilvl = (int)$il;
                $start = 1;
                $startNode = $xp->query('w:start', $lvlNode)->item(0);
                if ($startNode instanceof DOMElement) {
                    $sv = $startNode->getAttributeNS($ns, 'val');
                    if ($sv === '') {
                        $sv = $startNode->getAttribute('w:val');
                    }
                    if ($sv !== '' && is_numeric($sv)) {
                        $start = (int)$sv;
                    }
                }
                $fmt = 'decimal';
                $fmtNode = $xp->query('w:numFmt', $lvlNode)->item(0);
                if ($fmtNode instanceof DOMElement) {
                    $fv = $fmtNode->getAttributeNS($ns, 'val');
                    if ($fv === '') {
                        $fv = $fmtNode->getAttribute('w:val');
                    }
                    if ($fv !== '') {
                        $fmt = strtolower($fv);
                    }
                }
                $lvlText = '%1.';
                $txtNode = $xp->query('w:lvlText', $lvlNode)->item(0);
                if ($txtNode instanceof DOMElement) {
                    $tv = $txtNode->getAttributeNS($ns, 'val');
                    if ($tv === '') {
                        $tv = $txtNode->getAttribute('w:val');
                    }
                    if ($tv !== '') {
                        $lvlText = $tv;
                    }
                }
                $maps['abstract_levels'][$absId . ':' . $ilvl] = [
                    'start' => $start,
                    'fmt' => $fmt,
                    'lvlText' => $lvlText,
                ];
            }
        }

        foreach ($xp->query('//w:num') as $numNode) {
            if (!$numNode instanceof DOMElement) {
                continue;
            }
            $nid = $numNode->getAttributeNS($ns, 'numId');
            if ($nid === '') {
                $nid = $numNode->getAttribute('w:numId');
            }
            if ($nid === '' || !is_numeric($nid)) {
                continue;
            }
            $abs = $xp->query('w:abstractNumId', $numNode)->item(0);
            if (!$abs instanceof DOMElement) {
                continue;
            }
            $aid = $abs->getAttributeNS($ns, 'val');
            if ($aid === '') {
                $aid = $abs->getAttribute('w:val');
            }
            if ($aid === '' || !is_numeric($aid)) {
                continue;
            }
            $maps['num_to_abstract'][(int)$nid] = (int)$aid;
        }
        return $maps;
    }
}

/**
 * @return array{0: ?string, 1: bool} [normalized highlight key or null, is_highlighted]
 */
if (!function_exists('ereview_docx_run_highlight')) {
    function ereview_docx_run_highlight(DOMXPath $xpath, DOMElement $r): array {
        $ns = ereview_docx_xml_namespace();
        $n = $xpath->query('w:rPr/w:highlight', $r)->item(0);
        if ($n instanceof DOMElement) {
            $val = $n->getAttributeNS($ns, 'val');
            if ($val === '') {
                $val = $n->getAttribute('val');
            }
            if ($val === '') {
                $val = $n->getAttribute('w:val');
            }
            if ($val !== '') {
                return [strtolower($val), true];
            }
        }
        $shd = $xpath->query('w:rPr/w:shd', $r)->item(0);
        if ($shd instanceof DOMElement) {
            $fill = $shd->getAttributeNS($ns, 'fill');
            if ($fill === '') {
                $fill = $shd->getAttribute('fill');
            }
            if ($fill === '') {
                $fill = $shd->getAttribute('w:fill');
            }
            $fillU = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $fill));
            if ($fillU !== '' && (strpos($fillU, 'FFFF') === 0 || strpos($fillU, 'FFFD') === 0 || $fillU === 'FFF200' || $fillU === 'FFFF99')) {
                return ['yellow', true];
            }
        }
        return [null, false];
    }
}

/**
 * @return ?string 6-char hex without # (WordprocessingML w:color val), or null for auto/inherit
 */
if (!function_exists('ereview_docx_run_color_hex')) {
    function ereview_docx_run_color_hex(DOMXPath $xpath, DOMElement $r): ?string {
        $ns = ereview_docx_xml_namespace();
        $n = $xpath->query('w:rPr/w:color', $r)->item(0);
        if (!$n instanceof DOMElement) {
            return null;
        }
        $val = $n->getAttributeNS($ns, 'val');
        if ($val === '') {
            $val = $n->getAttribute('val');
        }
        if ($val === '' || strcasecmp($val, 'auto') === 0) {
            return null;
        }
        $val = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $val));
        return strlen($val) === 6 ? $val : null;
    }
}

/**
 * True when run color is a red (or red-orange) used for topic labels in CPA drill docs.
 */
if (!function_exists('ereview_docx_hex_is_red_topic')) {
    function ereview_docx_hex_is_red_topic(string $hex): bool {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return false;
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        if ($r >= 0x88 && $r > $g + 12 && $r > $b + 12) {
            return true;
        }
        return false;
    }
}

/**
 * Insert missing spaces where Word runs/tables omit gaps (e.g. "2022" + "P40" → "2022 P40").
 */
if (!function_exists('ereview_docx_fix_glue_currency_spacing')) {
    function ereview_docx_fix_glue_currency_spacing(string $s): string {
        $s = preg_replace('/([\d,])(P(?:hp)?\s*\d)/iu', '$1 $2', $s);
        $s = preg_replace('/(\b20\d{2})(\d{2})(?=\s|$|[A-Za-z])/u', '$1 $2', $s);

        return $s;
    }
}

/**
 * Read document.xml paragraphs as list of ['plain' => string, 'html' => string, 'runs' => list of run metadata].
 *
 * Uses //w:body//w:p so paragraphs inside tables (common in CPA practice files) are included.
 *
 * @return list<array{plain:string,html:string,runs:list<array{text:string,highlight:?string,color:?string,is_red:bool}>,is_list:bool,list_level:?int,list_num_id:?int,list_fmt:?string,list_ord:?int}>
 */
if (!function_exists('ereview_docx_extract_paragraphs')) {
    function ereview_docx_extract_paragraphs(string $docxPath): array {
        if (!is_readable($docxPath)) {
            throw new InvalidArgumentException('Cannot read docx file.');
        }
        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException('Invalid .docx archive.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $numberingXml = $zip->getFromName('word/numbering.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            throw new RuntimeException('Missing word/document.xml in docx.');
        }
        $numberingMaps = ereview_docx_build_numbering_maps($numberingXml !== false ? $numberingXml : null);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml)) {
            throw new RuntimeException('Could not parse document.xml.');
        }
        $xpath = new DOMXPath($dom);
        $ns = ereview_docx_xml_namespace();
        $xpath->registerNamespace('w', $ns);

        $out = [];
        $listCounters = [];
        $bodyPs = $xpath->query('//w:body//w:p');
        if (!$bodyPs) {
            return [];
        }
        foreach ($bodyPs as $p) {
            if (!($p instanceof DOMElement)) {
                continue;
            }
            $listLevel = null;
            $listNumId = null;
            $ilvlNode = $xpath->query('w:pPr/w:numPr/w:ilvl', $p)->item(0);
            if ($ilvlNode instanceof DOMElement) {
                $lv = $ilvlNode->getAttributeNS($ns, 'val');
                if ($lv === '') {
                    $lv = $ilvlNode->getAttribute('val');
                }
                if ($lv === '') {
                    $lv = $ilvlNode->getAttribute('w:val');
                }
                if ($lv !== '' && is_numeric($lv)) {
                    $listLevel = (int)$lv;
                }
            }
            $numIdNode = $xpath->query('w:pPr/w:numPr/w:numId', $p)->item(0);
            if ($numIdNode instanceof DOMElement) {
                $nid = $numIdNode->getAttributeNS($ns, 'val');
                if ($nid === '') {
                    $nid = $numIdNode->getAttribute('val');
                }
                if ($nid === '') {
                    $nid = $numIdNode->getAttribute('w:val');
                }
                if ($nid !== '' && is_numeric($nid)) {
                    $listNumId = (int)$nid;
                }
            }
            $runs = [];
            $plain = '';
            $html = '';
            $listFmt = null;
            $listOrd = null;
            if ($listNumId !== null && $listLevel !== null) {
                $absId = $numberingMaps['num_to_abstract'][$listNumId] ?? null;
                if ($absId !== null) {
                    $def = $numberingMaps['abstract_levels'][$absId . ':' . $listLevel] ?? null;
                    if (is_array($def)) {
                        $listFmt = $def['fmt'] ?? null;
                    }
                }
                $counterKey = $listNumId . ':' . $listLevel;
                if (!isset($listCounters[$counterKey])) {
                    $start = 1;
                    if ($absId !== null) {
                        $def = $numberingMaps['abstract_levels'][$absId . ':' . $listLevel] ?? null;
                        if (is_array($def) && isset($def['start']) && is_numeric($def['start'])) {
                            $start = (int)$def['start'];
                        }
                    }
                    $listCounters[$counterKey] = $start;
                } else {
                    $listCounters[$counterKey]++;
                }
                $listOrd = (int)$listCounters[$counterKey];
            }
            $appendRun = static function (DOMElement $r) use ($xpath, &$runs, &$plain, &$html): void {
                [$hlKey, $isHl] = ereview_docx_run_highlight($xpath, $r);
                $colorHex = ereview_docx_run_color_hex($xpath, $r);
                $isRed = $colorHex !== null && ereview_docx_hex_is_red_topic($colorHex);
                $text = '';
                foreach ($xpath->query('w:t|w:instrText', $r) as $t) {
                    $text .= $t->textContent;
                }
                if ($text !== '') {
                    $pt = $plain;
                    if ($pt !== '' && preg_match('/\d$/u', $pt) && preg_match('/^P/u', $text)) {
                        $plain .= ' ';
                        $html .= ' ';
                    }
                }
                $runs[] = [
                    'text' => $text,
                    'highlight' => $hlKey,
                    'color' => $colorHex,
                    'is_red' => $isRed,
                ];
                $plain .= $text;
                $esc = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($isHl && $hlKey !== null) {
                    $html .= '<mark class="ereview-qsort-hl ereview-qsort-hl--' . htmlspecialchars($hlKey, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" data-ereview-hl="' . htmlspecialchars($hlKey, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $esc . '</mark>';
                } elseif ($isRed) {
                    $html .= '<span class="ereview-qsort-font-red">' . $esc . '</span>';
                } else {
                    $html .= $esc;
                }
            };
            for ($c = $p->firstChild; $c !== null; $c = $c->nextSibling) {
                if (!($c instanceof DOMElement)) {
                    continue;
                }
                if ($c->namespaceURI !== $ns) {
                    continue;
                }
                $ln = $c->localName;
                if ($ln === 'r') {
                    $appendRun($c);
                } elseif ($ln === 'hyperlink') {
                    foreach ($xpath->query('w:r', $c) as $r) {
                        if ($r instanceof DOMElement) {
                            $appendRun($r);
                        }
                    }
                } elseif ($ln === 'tab') {
                    $plain .= "\t";
                    $html .= ' ';
                }
            }
            if ($plain === '' && $runs === []) {
                foreach ($xpath->query('.//w:r', $p) as $r) {
                    if ($r instanceof DOMElement) {
                        $appendRun($r);
                    }
                }
            }
            $plain = ereview_docx_fix_glue_currency_spacing(str_replace(["\t", "\x0b"], ' ', $plain));
            $html = ereview_docx_fix_glue_currency_spacing($html);
            $out[] = [
                'plain' => $plain,
                'html' => $html,
                'runs' => $runs,
                'is_list' => $listNumId !== null,
                'list_level' => $listLevel,
                'list_num_id' => $listNumId,
                'list_fmt' => $listFmt,
                'list_ord' => $listOrd,
            ];
        }
        return $out;
    }
}

/**
 * Lines like "150.What" (no space after period) must still start a new question.
 * Single-digit 1–9 without "?" and without a lettered (Topic) at end are case setup lines, not new questions.
 */
if (!function_exists('ereview_docx_is_question_start')) {
    function ereview_docx_is_question_start(string $plain): bool {
        if (preg_match('/^\s*(\d{1,4})([\.\)])\s*(\S.*)$/u', $plain, $m) !== 1) {
            return false;
        }
        $n = (int)$m[1];
        $sep = $m[2];
        $rest = trim((string)$m[3]);
        // Decimal amounts like "12.50" / "497.75" are not question numbers.
        if ($sep === '.' && preg_match('/^\d/', $rest) === 1) {
            return false;
        }
        if ($n >= 1 && $n <= 9) {
            $t = trim(preg_replace('/\s+/u', ' ', $plain));
            if (preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $t)) {
                return true;
            }
            if (strpos($plain, '?') !== false) {
                return true;
            }
            return false;
        }
        return true;
    }
}

/**
 * @return array{0:?int,1:string} [number, remainder]
 */
if (!function_exists('ereview_docx_extract_leading_question_number')) {
    function ereview_docx_extract_leading_question_number(string $plain): array {
        if (preg_match('/^\s*(\d{1,4})([\.\)])\s*(.*)$/u', $plain, $m) !== 1) {
            return [null, ''];
        }
        $num = (int)$m[1];
        $sep = $m[2];
        $rest = trim((string)$m[3]);
        if ($sep === '.' && preg_match('/^\d/', $rest) === 1) {
            return [null, ''];
        }
        return [$num, $rest];
    }
}

/**
 * After item 100+, lines "1."–"9." without "?" are almost always case notes for the shared problem, not new exam items.
 */
if (!function_exists('ereview_docx_is_numbered_gp_note_after_high_exam_item')) {
    function ereview_docx_is_numbered_gp_note_after_high_exam_item(string $plain, string $currentNumber): bool {
        $cur = (int)$currentNumber;
        if ($cur < 100) {
            return false;
        }
        [$num, ] = ereview_docx_extract_leading_question_number($plain);
        if ($num === null || $num < 1 || $num > 9) {
            return false;
        }
        if (strpos($plain, '?') !== false) {
            return false;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        if (preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $t)) {
            return false;
        }

        return true;
    }
}

/**
 * Text after optional leading "94." / "94)" (for GP / consolidation heuristics).
 */
if (!function_exists('ereview_docx_plain_body_after_leading_item_number')) {
    function ereview_docx_plain_body_after_leading_item_number(string $plain): string {
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        if (preg_match('/^\d{1,4}[\.\)]\s+(.*)$/us', $t, $m)) {
            return trim($m[1]);
        }

        return $t;
    }
}

/**
 * Consolidation / intercompany case setup. Word often applies the next list number (e.g. 95.) even though
 * the line is shared context for items 95–98, not the stem of exam item 95.
 */
if (!function_exists('ereview_docx_plain_smells_like_consolidation_or_gp_prologue')) {
    function ereview_docx_plain_smells_like_consolidation_or_gp_prologue(string $plain): bool {
        $full = trim(preg_replace('/\s+/u', ' ', $plain));
        if ($full === '' || strpos($full, '?') !== false) {
            return false;
        }
        if (preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $full)) {
            return false;
        }
        $b = ereview_docx_plain_body_after_leading_item_number($full);
        if ($b === '') {
            return false;
        }
        if (preg_match('/\bholds\s+\d{1,3}\s*%\s+of\s+the\s+(?:voting\s+)?common\s+stock\b/ui', $b)) {
            return true;
        }
        if (stripos($b, 'subsidiary still possesses') !== false) {
            return true;
        }
        if (stripos($b, 'sold merchandise to') !== false && stripos($b, 'inventory') !== false
            && preg_match('/\b(?:end\s+of|at\s+the\s+end)\b/ui', $b)) {
            return true;
        }
        if (preg_match('/\b(?:sold|transferred)\s+merchandise\s+to\b/ui', $b)
            && preg_match('/\b(?:still|yet)\s+(?:holds|possesses|owns)\b/ui', $b)) {
            return true;
        }

        return false;
    }
}

/**
 * General-problem lead-ins that define a range of items.
 */
if (!function_exists('ereview_docx_plain_smells_like_gp_range_intro')) {
    function ereview_docx_plain_smells_like_gp_range_intro(string $plain): bool {
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        if ($t === '' || strpos($t, '?') !== false) {
            return false;
        }
        if (preg_match('/\bItems?\s+\d+\s*(?:and|to|-|–)\s*\d+\s+are\s+based\s+on\s+the\s+following\s+information\b/ui', $t)) {
            return true;
        }
        if (preg_match('/\bUse\s+the\s+following\s+information\s+for\s+questions?\s+\d+\s*(?:to|-|–)\s*\d+\b/ui', $t)) {
            return true;
        }
        if (preg_match('/\bFor\s+questions?\s+\d+\s*(?:to|-|–)\s*\d+\b/ui', $t) && stripos($t, 'information') !== false) {
            return true;
        }

        return false;
    }
}

/**
 * After finishing MCQ choices, a new line can start the next item (e.g. "5. Simple Company…")
 * even when ereview_docx_is_question_start is false for digits 1–9 (umbrella guard).
 * Do not treat case sub-lines like "1. There is no spoilage…" as a new question.
 */
if (!function_exists('ereview_docx_is_new_question_after_choices_line')) {
    function ereview_docx_is_new_question_after_choices_line(string $plain, string $currentNumber): bool {
        if ($currentNumber === '' || $currentNumber === '?') {
            return false;
        }
        if (!preg_match('/^\s*(\d{1,4})[\.\)]\s+\S/u', $plain)) {
            return false;
        }
        [$num, $rest] = ereview_docx_extract_leading_question_number($plain);
        if ($num === null) {
            return false;
        }
        if ((string)$num === (string)$currentNumber) {
            return false;
        }
        if ($num >= 10) {
            $cur = (int)$currentNumber;
            if ($cur > 0 && $num === $cur + 1 && ereview_docx_plain_smells_like_consolidation_or_gp_prologue($plain)) {
                return false;
            }

            return true;
        }
        if (ereview_docx_is_numbered_gp_note_after_high_exam_item($plain, $currentNumber)) {
            return false;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        if (strpos($plain, '?') !== false) {
            return true;
        }
        if (preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $t)) {
            return true;
        }
        if (preg_match('/^\s*[1-9][\.\)]\s+(The|There|These|This|Additionally|If|When|Because|Since|While|Although)\b/iu', $plain)) {
            return false;
        }
        $restTrim = trim($rest);
        $rlen = function_exists('mb_strlen') ? mb_strlen($restTrim, 'UTF-8') : strlen($restTrim);
        if ($rlen >= 28) {
            return true;
        }
        if (preg_match('/\b(?:Company|Corporation|Inc\.|Ltd|employs|manufactures|acquired|provided|assumes)\b/i', $restTrim)) {
            return true;
        }
        return false;
    }
}

/**
 * Question number from typed "5." text or, when Word hides the marker, from list_ord (decimal ilvl 0).
 *
 * @param array{plain:string,is_list?:bool,list_level?:?int,list_fmt?:?string,list_ord?:?int} $para
 */
if (!function_exists('ereview_docx_infer_leading_question_number_from_para')) {
    function ereview_docx_infer_leading_question_number_from_para(array $para): ?int {
        $plain = (string)($para['plain'] ?? '');
        [$n, ] = ereview_docx_extract_leading_question_number($plain);
        if ($n !== null) {
            return $n;
        }
        if (empty($para['is_list'])) {
            return null;
        }
        $lvl = $para['list_level'] ?? null;
        if ($lvl !== null && (int)$lvl > 0) {
            return null;
        }
        $fmt = strtolower((string)($para['list_fmt'] ?? ''));
        if ($fmt !== '' && $fmt !== 'decimal') {
            return null;
        }
        $ord = (int)($para['list_ord'] ?? 0);

        return $ord > 0 ? $ord : null;
    }
}

/**
 * Same as ereview_docx_is_new_question_after_choices_line but also handles hidden Word list numbers
 * (common across page breaks: plain text starts with "Simple Company…" while list_ord is 5).
 *
 * @param array{plain:string,is_list?:bool,list_level?:?int,list_fmt?:?string,list_ord?:?int} $para
 */
if (!function_exists('ereview_docx_is_new_question_after_choices_para')) {
    function ereview_docx_is_new_question_after_choices_para(array $para, string $currentNumber): bool {
        $plain = (string)($para['plain'] ?? '');
        if (trim($plain) === '') {
            return false;
        }
        if ($currentNumber === '' || $currentNumber === '?') {
            return false;
        }
        $cur = (int)$currentNumber;
        if ($cur <= 0) {
            return false;
        }
        // Hidden list numbers / nested list (ilvl > 0): visible marker may be "48. Information…" while plain lacks "48.".
        $vis = ereview_docx_with_visible_list_marker($para);
        $visPlain = trim(preg_replace('/\s+/u', ' ', $vis['plain']));
        if ($visPlain !== '') {
            [$vn, ] = ereview_docx_extract_leading_question_number($visPlain);
            if ($vn !== null && $vn > $cur && ereview_docx_is_question_start($visPlain)
                && !ereview_docx_is_numeric_choice_line($visPlain)
                && !ereview_docx_is_choice_line($visPlain)) {
                if ($vn === $cur + 1 && ereview_docx_plain_smells_like_consolidation_or_gp_prologue($visPlain)) {
                    return false;
                }

                return true;
            }
        }
        if (ereview_docx_is_new_question_after_choices_line($plain, $currentNumber)) {
            return true;
        }
        if (empty($para['is_list'])) {
            return false;
        }
        $lvl = $para['list_level'] ?? null;
        if ($lvl !== null && (int)$lvl > 0) {
            return false;
        }
        $fmt = strtolower((string)($para['list_fmt'] ?? ''));
        if ($fmt !== '' && $fmt !== 'decimal') {
            return false;
        }
        $ord = (int)($para['list_ord'] ?? 0);
        if ($ord <= 0 || $ord === $cur) {
            return false;
        }
        if (ereview_docx_is_choice_line($plain) || ereview_docx_is_numeric_choice_line($plain)) {
            return false;
        }
        if ($cur >= 100 && $ord >= 1 && $ord <= 9 && strpos($plain, '?') === false) {
            $t0 = trim(preg_replace('/\s+/u', ' ', $plain));
            if (!preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $t0)) {
                return false;
            }
        }
        $scanOrd = $visPlain !== '' ? $visPlain : trim(preg_replace('/\s+/u', ' ', $plain));
        if ($ord === $cur + 1 && ereview_docx_plain_smells_like_consolidation_or_gp_prologue($scanOrd)) {
            return false;
        }
        if ($ord >= 10) {
            return true;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        if (strpos($plain, '?') !== false) {
            return true;
        }
        if (preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $t)) {
            return true;
        }
        if (preg_match('/^\s*(The|There|These|This|Additionally|If|When|Because|Since|While|Although)\b/iu', $plain)) {
            return false;
        }
        $rlen = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
        if ($rlen >= 28) {
            return true;
        }
        if (preg_match('/\b(?:Company|Corporation|Inc\.|Ltd|employs|manufactures|acquired|provided|assumes)\b/i', $plain)) {
            return true;
        }

        return false;
    }
}

/**
 * "51. For 2020, what is…?" is still exam item 50 (list continued); "42. What is…?" is the next item after 41.
 *
 * @param string $visPlain Result of ereview_docx_with_visible_list_marker() merged plain
 */
if (!function_exists('ereview_docx_list_ord_continuation_same_exam_item')) {
    function ereview_docx_list_ord_continuation_same_exam_item(string $visPlain, int $cur, int $vn): bool {
        if ($vn !== $cur + 1) {
            return false;
        }
        if (!preg_match('/^\s*\d{1,4}[\.\)]\s*(.*)$/us', $visPlain, $m)) {
            return false;
        }
        $rest = trim($m[1]);
        if ($rest === '') {
            return false;
        }

        return (bool)preg_match(
            '/^(?:For|In|During|Based|Using|Assume|Given|If|When|The following|The data|Additional)\b/ui',
            $rest
        );
    }
}

/**
 * New exam item while choices never opened: prose options stayed in the stem (in_choices false), so suppressed
 * startsByList would glue the next prompt onto the prior question. Skips same-item continuations like "51. For 2020…".
 */
if (!function_exists('ereview_docx_visible_plain_opens_next_exam_after_stem_phase')) {
    function ereview_docx_visible_plain_opens_next_exam_after_stem_phase(array $para, string $currentNumber): bool {
        if ($currentNumber === '' || $currentNumber === '?') {
            return false;
        }
        $cur = (int)$currentNumber;
        if ($cur <= 0) {
            return false;
        }
        $vis = ereview_docx_with_visible_list_marker($para);
        $visPlain = trim(preg_replace('/\s+/u', ' ', $vis['plain']));
        if ($visPlain === '') {
            return false;
        }
        [$vn, ] = ereview_docx_extract_leading_question_number($visPlain);
        if ($vn === null || $vn <= $cur) {
            return false;
        }
        if (!ereview_docx_is_question_start($visPlain)) {
            return false;
        }
        if (ereview_docx_is_numeric_choice_line($visPlain) || ereview_docx_is_choice_line($visPlain)) {
            return false;
        }
        if ($vn === $cur + 1 && ereview_docx_list_ord_continuation_same_exam_item($visPlain, $cur, $vn)) {
            return false;
        }

        return true;
    }
}

/**
 * Fallback for Word auto-numbered paragraphs where leading number is not in plain text.
 *
 * @param array{plain:string,is_list?:bool,list_level?:?int,list_fmt?:?string,list_ord?:?int} $para
 */
if (!function_exists('ereview_docx_is_list_question_start')) {
    function ereview_docx_is_list_question_start(array $para): bool {
        if (empty($para['is_list'])) {
            return false;
        }
        $lvl = $para['list_level'] ?? null;
        if ($lvl !== null && (int)$lvl > 0) {
            return false;
        }
        $fmt = strtolower((string)($para['list_fmt'] ?? ''));
        if ($fmt !== '' && $fmt !== 'decimal') {
            return false;
        }
        $plain = trim((string)($para['plain'] ?? ''));
        if ($plain === '') {
            return false;
        }
        if (ereview_docx_is_choice_line($plain) || ereview_docx_is_numeric_choice_line($plain)) {
            return false;
        }
        $ord = (int)($para['list_ord'] ?? 0);
        $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
        if ($ord >= 10 && $len >= 16) {
            return true;
        }
        if (strpos($plain, '?') !== false) {
            return true;
        }
        if (preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $plain)) {
            return true;
        }
        return false;
    }
}

/**
 * Unnumbered question stem that should start a new block.
 * Typical in this CPA doc: "... is: (Construction Contracts)" followed by numeric options.
 */
if (!function_exists('ereview_docx_is_topic_tagged_stem_line')) {
    function ereview_docx_is_topic_tagged_stem_line(string $plain): bool {
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        if ($t === '') {
            return false;
        }
        // Answers like "Product J (Sell at split-off); Product K (Process beyond split-off)" — not a stem.
        if (preg_match('/\)\s*;\s*\S/u', $t)) {
            return false;
        }
        if (ereview_docx_is_choice_line($t) || ereview_docx_is_numeric_choice_line($t)) {
            return false;
        }
        if (!preg_match('/\(([^)]+)\)\s*$/u', $t, $m)) {
            return false;
        }
        if (!ereview_docx_paren_inner_is_topic_candidate(trim($m[1]))) {
            return false;
        }
        $before = trim(preg_replace('/\(([^)]+)\)\s*$/u', '', $t));
        $len = function_exists('mb_strlen') ? mb_strlen($before, 'UTF-8') : strlen($before);
        if ($len < 12) {
            return false;
        }
        if (preg_match('/[\?\:]$/u', $before) === 1) {
            return true;
        }
        // Avoid treating long choice prose as a stem; real tagged stems usually end with ? or : before (Topic).
        return $len >= 28 && strpos($before, ';') === false;
    }
}

/**
 * Second (or later) prompt in the same numbered item, after a first MCQ block — e.g.
 * "Compute the cost of goods manufactured: (Job Costing)" following choices for the same case.
 * Must not start a new question number; fold into the current block instead.
 */
if (!function_exists('ereview_docx_is_subquestion_followup_after_choices')) {
    function ereview_docx_is_subquestion_followup_after_choices(string $plain): bool {
        if (!ereview_docx_is_topic_tagged_stem_line($plain)) {
            return false;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        $before = trim(preg_replace('/\(([^)]+)\)\s*$/u', '', $t));
        if ($before === '') {
            return false;
        }
        return (bool)preg_match(
            '/^(Compute|Determine|Calculate|Find|Prepare|How much|How many)\b/ui',
            $before
        );
    }
}

if (!function_exists('ereview_docx_is_choice_line')) {
    function ereview_docx_is_choice_line(string $plain): bool {
        $t = trim($plain);
        if ($t === '') {
            return false;
        }
        // Single-letter labels (a. through z.), including u.–z. sets from some publishers.
        return preg_match('/^[a-zA-Z][\.\)]\s+\S/u', $t) === 1;
    }
}

/**
 * Short prose options common in variable/absorption items: "Increased by 3,000 units".
 */
if (!function_exists('ereview_docx_is_prose_inventory_mcq_line')) {
    function ereview_docx_is_prose_inventory_mcq_line(string $plain): bool {
        $t = trim(preg_replace('/\s+/u', ' ', $plain));
        if ($t === '' || mb_strlen($t, 'UTF-8') > 130) {
            return false;
        }
        if (ereview_docx_is_question_start($plain)) {
            return false;
        }

        return (bool)preg_match(
            '/^(?:Increased?|Decreased?|Increase|Decrease)\s+by\s+/iu',
            $t
        );
    }
}

/**
 * Last non-empty plain text in stem paragraphs (for MCQ context checks).
 *
 * @param list<array{plain?:string}> $stemParagraphs
 */
if (!function_exists('ereview_docx_stem_last_nonempty_plain')) {
    function ereview_docx_stem_last_nonempty_plain(array $stemParagraphs): string {
        for ($i = count($stemParagraphs) - 1; $i >= 0; $i--) {
            $p = trim((string)($stemParagraphs[$i]['plain'] ?? ''));
            if ($p !== '') {
                return $p;
            }
        }

        return '';
    }
}

/**
 * First MCQ option row should not start until the stem has a real prompt (?) or a trailing (Topic) tag.
 * Word tables often reuse lettered lists for cells like "a. 2020" / "b. Depreciable life…" before the actual question.
 */
if (!function_exists('ereview_docx_stem_end_invites_choices')) {
    function ereview_docx_stem_end_invites_choices(string $lastStemPlain): bool {
        $t = trim(preg_replace('/\s+/u', ' ', $lastStemPlain));
        if ($t === '') {
            return false;
        }
        if (strpos($t, '?') !== false) {
            return true;
        }
        if (ereview_docx_is_topic_tagged_stem_line($t)) {
            return true;
        }

        return false;
    }
}

/**
 * Word often hides "a."–"d." as list labels; plain text is only "400 favorable".
 * Treat ilvl-0 lower/upper letter list rows 1–4 as options when the stem looks like a prompt
 * or we are already collecting choices.
 *
 * @param array{plain:string,is_list?:bool,list_level?:?int,list_fmt?:?string,list_ord?:?int} $para
 */
if (!function_exists('ereview_docx_is_letter_list_mcq_option_row')) {
    function ereview_docx_is_letter_list_mcq_option_row(array $para, bool $inChoices, string $lastStemPlain): bool {
        if (empty($para['is_list'])) {
            return false;
        }
        $lvl = $para['list_level'] ?? null;
        $ilvl = $lvl !== null && $lvl !== '' ? (int)$lvl : 0;
        if ($ilvl > 1) {
            return false;
        }
        $fmt = strtolower((string)($para['list_fmt'] ?? ''));
        if ($fmt !== 'lowerletter' && $fmt !== 'upperletter') {
            return false;
        }
        $ord = (int)($para['list_ord'] ?? 0);
        if ($ord < 1 || $ord > 26) {
            return false;
        }
        $mcqStem = $lastStemPlain !== '' && (strpos($lastStemPlain, '?') !== false || ereview_docx_is_topic_tagged_stem_line($lastStemPlain));
        if ($ilvl === 0 && $ord > 4) {
            if (!$inChoices && !$mcqStem) {
                return false;
            }
        }
        if ($ilvl === 1 && $ord > 4) {
            if (!$inChoices && !$mcqStem) {
                return false;
            }
        }
        $plain = trim((string)($para['plain'] ?? ''));
        if ($plain === '') {
            return false;
        }
        if (ereview_docx_is_question_start($plain)) {
            return false;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
        if ($len > 220) {
            return false;
        }
        if ($inChoices) {
            return true;
        }
        if ($lastStemPlain !== '' && strpos($lastStemPlain, '?') !== false) {
            return true;
        }
        if ($lastStemPlain !== '' && ereview_docx_is_topic_tagged_stem_line($lastStemPlain)) {
            return true;
        }

        return false;
    }
}

/**
 * Bare peso amounts / comma-grouped numbers used as MCQ choices (no a. b. c. d. labels).
 */
if (!function_exists('ereview_docx_is_numeric_choice_line')) {
    function ereview_docx_is_numeric_choice_line(string $plain): bool {
        $t = trim($plain);
        if ($t === '') {
            return false;
        }
        $t = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if (preg_match('/\s+(units?|hours?|hrs?|pcs?|pieces?|%)\s*$/iu', $t)) {
            $stripped = trim(preg_replace('/\s+(units?|hours?|hrs?|pcs?|pieces?|%)\s*$/iu', '', $t));
            if ($stripped !== '' && $stripped !== $t) {
                return ereview_docx_is_numeric_choice_line($stripped);
            }
        }
        if (ereview_docx_is_question_start($plain)) {
            return false;
        }
        if (ereview_docx_is_choice_line($plain)) {
            return false;
        }
        if (mb_strlen($t, 'UTF-8') > 44) {
            return false;
        }
        if (preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', $t)) {
            return false;
        }
        $letters = preg_replace('/\bP(?:hp)?\b/iu', '', $t);
        if (preg_match('/\p{L}/u', $letters)) {
            return false;
        }
        if (preg_match('/^\d{5,}$/', preg_replace('/\s+/', '', $t))) {
            return false;
        }
        if (preg_match('/\d{1,3}(?:,\d{3}){1,}\d{1,3}(?:,\d{3}){1,}/', $t)) {
            return false;
        }
        if (preg_match('/^\s*(?:P(?:hp)?\s*)?-?[\d][\d,\s.%]*(?:\s*(?:million|thousand|Mn|B))?\.?\s*$/iu', $t)) {
            return true;
        }
        if (preg_match('/^\s*-?\d{1,3}(?:,\d{3})+\s*$/', $t)) {
            return true;
        }
        if (preg_match('/^\s*-?\d{1,3}(?:\s+\d{3})+\s*$/', $t)) {
            return true;
        }
        if (preg_match('/^\s*-?\d{1,4}\s*$/', $t)) {
            return true;
        }
        return false;
    }
}

/**
 * Normalize common Word / Unicode variants before topic scan.
 */
if (!function_exists('ereview_docx_normalize_for_topic_scan')) {
    function ereview_docx_normalize_for_topic_scan(string $s): string {
        $s = str_replace(
            ["\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99", '（', '）', '［', '］', '【', '】'],
            ['"', '"', "'", "'", '(', ')', '[', ']', '[', ']'],
            $s
        );
        return $s;
    }
}

/**
 * True when parenthetical is a figure (e.g. 2,200, $1k, 15%) — not a topic label.
 */
if (!function_exists('ereview_docx_paren_inner_is_numeric_like')) {
    function ereview_docx_paren_inner_is_numeric_like(string $inner): bool {
        $t = trim($inner);
        if ($t === '') {
            return true;
        }
        if (preg_match('/\p{L}/u', $t)) {
            $compact = preg_replace('/\s+/u', '', $t);
            if (preg_match('/^(?:Php|PHP|USD|EUR|GBP)\d/i', $compact)) {
                return true;
            }
            return false;
        }
        return (bool)preg_match('/^[\d\s,.$€£¥₱%-]+$/u', $t);
    }
}

/**
 * Topic labels should contain at least one letter and not be numeric-only.
 */
if (!function_exists('ereview_docx_paren_inner_is_topic_candidate')) {
    function ereview_docx_paren_inner_is_topic_candidate(string $inner): bool {
        $t = trim($inner);
        $len = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
        if ($len < 2) {
            return false;
        }
        if (!preg_match('/\p{L}/u', $t)) {
            return false;
        }
        if (ereview_docx_paren_inner_is_numeric_like($t)) {
            return false;
        }
        // Guard against option-like tails such as "(d) 1,500 hours" becoming a fake topic.
        if (preg_match('/\d/u', $t) && !preg_match('/\b(?:IFRS|IAS|PFRS|PAS|ASC)\b/i', $t)) {
            return false;
        }
        if (preg_match('/\b(?:hours?|units?|kg|kilos?|meters?|liters?|gallons?|pcs?|pieces?|cost|price|amount)\b/i', $t)) {
            return false;
        }
        if (preg_match('/\b(?:adjusted|using|presented|follows|worked|incurred|year|years|current prices|wage rates)\b/i', $t)) {
            return false;
        }
        $words = preg_split('/\s+/u', trim($t)) ?: [];
        if (count($words) > 7) {
            return false;
        }
        if (preg_match('/^[a-z]\s*[\.\)]?\s*$/iu', $t)) {
            return false;
        }
        return true;
    }
}

/**
 * Pick topic from the last parenthetical in $s that looks like a label (skips trailing (2,200), ($500), etc.).
 */
if (!function_exists('ereview_docx_extract_topic_from_stem')) {
    function ereview_docx_extract_topic_from_stem(string $stemPlain): ?string {
        $s = ereview_docx_normalize_for_topic_scan($stemPlain);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        if ($s === '') {
            return null;
        }
        if (!preg_match_all('/\(([^)]+)\)/u', $s, $m, PREG_SET_ORDER)) {
            return null;
        }
        for ($i = count($m) - 1; $i >= 0; $i--) {
            $inner = trim($m[$i][1]);
            if (ereview_docx_paren_inner_is_topic_candidate($inner)) {
                return $inner;
            }
        }
        return null;
    }
}

/**
 * Stem paragraphs that belong to this numbered item only.
 * Stops at the next question: typed "M." / "M)" or list decimal ilvl 0 with list_ord !== N (Word hides numbers).
 *
 * @param list<array{plain:string,html:string,runs:array,is_list?:bool,list_fmt?:?string,list_ord?:?int,list_level?:?int}> $stemParagraphs
 * @return list<array{plain:string,html:string,runs:array}>
 */
if (!function_exists('ereview_docx_stem_paragraphs_for_question_number')) {
    function ereview_docx_stem_paragraphs_for_question_number(array $stemParagraphs, string $number): array {
        if ($number === '' || $number === '?' || !preg_match('/^\d{1,4}$/', $number)) {
            return $stemParagraphs;
        }
        $nInt = (int)$number;
        $n = preg_quote($number, '/');
        $out = [];
        $started = false;
        foreach ($stemParagraphs as $sp) {
            $p = trim((string)($sp['plain'] ?? ''));
            $listFmt = strtolower((string)($sp['list_fmt'] ?? ''));
            $listOrd = (int)($sp['list_ord'] ?? 0);
            $lvlRaw = $sp['list_level'] ?? null;
            $ilvl = $lvlRaw !== null && $lvlRaw !== '' ? (int)$lvlRaw : 0;
            $typedStart = $p !== '' && preg_match('/^\s*' . $n . '[\.\)]\s+/u', $p);
            $listStart = !empty($sp['is_list']) && $listFmt === 'decimal' && $listOrd === $nInt && $ilvl === 0;

            if (!$started) {
                if ($typedStart || $listStart) {
                    $started = true;
                    $out[] = $sp;
                    continue;
                }
                $out[] = $sp;
                continue;
            }
            if ($p !== '' && preg_match('/^\s*(\d{1,4})[\.\)]\s+/u', $p, $m) && (string)$m[1] !== $number) {
                $mn = (int)$m[1];
                if ($mn > $nInt && ereview_docx_is_question_start($p)) {
                    break;
                }
            }
            if (!empty($sp['is_list']) && $listFmt === 'decimal' && $ilvl === 0 && $listOrd > $nInt) {
                break;
            }
            $out[] = $sp;
        }
        return $out !== [] ? $out : $stemParagraphs;
    }
}

/**
 * First topic-like parenthetical in reading order (later merged items must not override with their tag).
 */
if (!function_exists('ereview_docx_extract_topic_from_stem_forward')) {
    function ereview_docx_extract_topic_from_stem_forward(string $stemPlain): ?string {
        $s = ereview_docx_normalize_for_topic_scan($stemPlain);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        if ($s === '') {
            return null;
        }
        if (!preg_match_all('/\(([^)]+)\)/u', $s, $m, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($m as $match) {
            $inner = trim($match[1]);
            if (ereview_docx_paren_inner_is_topic_candidate($inner)) {
                return $inner;
            }
        }
        return null;
    }
}

/**
 * Prefer topic from red-colored runs (matches Word "red topic" styling in CPA drill files).
 * Uses first valid parenthetical per paragraph in document order.
 *
 * @param list<array{plain:string,html:string,runs:array}> $stemParagraphs
 */
if (!function_exists('ereview_docx_topic_from_red_runs')) {
    function ereview_docx_topic_from_red_runs(array $stemParagraphs): ?string {
        foreach ($stemParagraphs as $sp) {
            $red = '';
            foreach ($sp['runs'] ?? [] as $run) {
                if (!empty($run['is_red'])) {
                    $red .= $run['text'] ?? '';
                }
            }
            $red = trim(preg_replace('/\s+/u', ' ', $red));
            if ($red === '' || !preg_match_all('/\(([^)]+)\)/u', $red, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $match) {
                $inner = trim($match[1]);
                if (ereview_docx_paren_inner_is_topic_candidate($inner)) {
                    return $inner;
                }
            }
        }
        return null;
    }
}

/**
 * Paragraph index where the visible question number should appear (after preamble / case facts).
 *
 * @param list<array{plain:string,html:string,runs:array}> $stemParagraphs
 */
if (!function_exists('ereview_docx_find_question_paragraph_index')) {
    function ereview_docx_find_question_paragraph_index(array $stemParagraphs): ?int {
        $last = null;
        foreach ($stemParagraphs as $i => $sp) {
            $p = trim((string)($sp['plain'] ?? ''));
            if ($p === '') {
                continue;
            }
            $last = $i;
            if (strpos($p, '?') !== false) {
                return $i;
            }
            if (preg_match('/\([^)]*\p{L}[^)]*\)\s*$/u', $p)) {
                return $i;
            }
        }
        return $last;
    }
}

if (!function_exists('ereview_docx_to_roman')) {
    function ereview_docx_to_roman(int $num, bool $upper = false): string {
        if ($num <= 0) {
            return '';
        }
        $map = [
            1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd',
            100 => 'c', 90 => 'xc', 50 => 'l', 40 => 'xl',
            10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i',
        ];
        $out = '';
        foreach ($map as $v => $r) {
            while ($num >= $v) {
                $out .= $r;
                $num -= $v;
            }
        }
        return $upper ? strtoupper($out) : $out;
    }
}

/**
 * Build visible list marker from Word list metadata.
 *
 * @param array{is_list?:bool,list_fmt?:?string,list_ord?:?int} $para
 */
if (!function_exists('ereview_docx_list_marker_from_para')) {
    function ereview_docx_list_marker_from_para(array $para): ?string {
        if (empty($para['is_list'])) {
            return null;
        }
        $ord = (int)($para['list_ord'] ?? 0);
        if ($ord <= 0) {
            return null;
        }
        $fmt = strtolower((string)($para['list_fmt'] ?? ''));
        if ($fmt === 'decimal') {
            return $ord . '. ';
        }
        if ($fmt === 'lowerletter' && $ord >= 1 && $ord <= 26) {
            return chr(96 + $ord) . '. ';
        }
        if ($fmt === 'upperletter' && $ord >= 1 && $ord <= 26) {
            return chr(64 + $ord) . '. ';
        }
        if ($fmt === 'lowerroman') {
            $r = ereview_docx_to_roman($ord, false);
            return $r !== '' ? $r . '. ' : null;
        }
        if ($fmt === 'upperroman') {
            $r = ereview_docx_to_roman($ord, true);
            return $r !== '' ? $r . '. ' : null;
        }
        return null;
    }
}

/**
 * @param array{plain:string,html:string,is_list?:bool,list_fmt?:?string,list_ord?:?int} $para
 * @return array{plain:string,html:string}
 */
if (!function_exists('ereview_docx_with_visible_list_marker')) {
    function ereview_docx_with_visible_list_marker(array $para): array {
        $plain = (string)($para['plain'] ?? '');
        $html = (string)($para['html'] ?? '');
        $trim = ltrim($plain);
        if ($trim === '') {
            return ['plain' => $plain, 'html' => $html];
        }
        if (preg_match('/^(?:\d+|[a-zA-Z]|[ivxlcdmIVXLCDM]+)[\.\)]\s+/u', $trim) === 1) {
            return ['plain' => $plain, 'html' => $html];
        }
        $marker = ereview_docx_list_marker_from_para($para);
        if ($marker === null) {
            return ['plain' => $plain, 'html' => $html];
        }
        $esc = htmlspecialchars($marker, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return [
            'plain' => $marker . $plain,
            'html' => '<span class="ereview-qsort-list-marker">' . $esc . '</span>' . $html,
        ];
    }
}

/**
 * True if a paragraph is a real MCQ answer line (not an empty list slot or blank spacer).
 *
 * @param array{plain:string,html:string,runs?:array} $para
 */
if (!function_exists('ereview_docx_choice_paragraph_has_substance')) {
    function ereview_docx_choice_paragraph_has_substance(array $para): bool {
        $vis = ereview_docx_with_visible_list_marker($para);
        $plainRaw = preg_replace('/[\x{00A0}\x{2000}-\x{200D}\x{FEFF}]/u', ' ', (string)($vis['plain'] ?? ''));
        $plain = trim(preg_replace('/\s+/u', ' ', $plainRaw));
        $html = $vis['html'];
        if ($plain !== ''
            && !preg_match('/^[a-zA-Z][\.\)]\s*$/u', $plain)
            && !preg_match('/^\d{1,2}[\.\)]\s*$/u', $plain)) {
            return true;
        }
        $inner = ereview_docx_strip_choice_label_prefix_from_html($html);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($inner)));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text !== '';
    }
}

/**
 * @param list<array{plain:string,html:string,runs?:array}> $choiceParagraphs
 * @return list<array{plain:string,html:string,runs?:array}>
 */
if (!function_exists('ereview_docx_filter_substantive_choice_paragraphs')) {
    function ereview_docx_filter_substantive_choice_paragraphs(array $choiceParagraphs): array {
        $out = [];
        foreach ($choiceParagraphs as $cp) {
            if (ereview_docx_choice_paragraph_has_substance($cp)) {
                $out[] = $cp;
            }
        }

        return $out;
    }
}

/**
 * Strip source list / label prefix from choice HTML so we can show a. b. c. d. per question.
 *
 * @param string $html
 * @return string
 */
if (!function_exists('ereview_docx_strip_choice_label_prefix_from_html')) {
    function ereview_docx_strip_choice_label_prefix_from_html(string $html): string {
        $h = $html;
        if (preg_match('#^<span class="ereview-qsort-list-marker">[^<]*</span>#u', $h)) {
            $h = preg_replace('#^<span class="ereview-qsort-list-marker">[^<]*</span>#u', '', $h, 1);
        }
        // Letter run sometimes fully wrapped in red span (publisher styling).
        if (preg_match('#^<span class="ereview-qsort-font-red">([a-zA-Z][\.\)]\s*)</span>#u', $h)) {
            $h = preg_replace('#^<span class="ereview-qsort-font-red">([a-zA-Z][\.\)]\s*)</span>#u', '', $h, 1);
        }
        $h = preg_replace('/^[a-zA-Z][\.\)]\s+/u', '', $h, 1);
        $h = preg_replace('/^\d{1,2}[\.\)]\s+/u', '', $h, 1);

        return $h;
    }
}

/**
 * Build choice rows for UI: always show a. b. c. d. … per question (Word may use i. j. or continuing lists).
 *
 * @param list<array{plain:string,html:string,runs?:array}> $choiceParagraphs
 * @return list<string>
 */
if (!function_exists('ereview_docx_build_choices_html_for_display')) {
    function ereview_docx_build_choices_html_for_display(array $choiceParagraphs): array {
        $filtered = ereview_docx_filter_substantive_choice_paragraphs($choiceParagraphs);
        $out = [];
        foreach ($filtered as $i => $cp) {
            $vis = ereview_docx_with_visible_list_marker($cp);
            $html = ereview_docx_strip_choice_label_prefix_from_html($vis['html']);
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
            if ($text === '') {
                continue;
            }
            $letter = chr(ord('a') + min($i, 25));
            $letterEsc = htmlspecialchars($letter . '. ', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $prefix = '<span class="ereview-qsort-choice-letter">' . $letterEsc . '</span>';
            $out[] = $prefix . $html;
        }

        return $out;
    }
}

/**
 * @param list<array{plain:string,html:string,runs:array}> $paragraphs
 * @param list<array<string,mixed>>|null $traceOut When non-null, filled with one row per paragraph (decision log).
 * @return list<array{number:string,stem_paragraphs:list,choice_paragraphs:list,topic:?string,stem_plain:string,topic_source:string}>
 */
if (!function_exists('ereview_docx_build_question_blocks')) {
    function ereview_docx_build_question_blocks(array $paragraphs, ?array &$traceOut = null): array {
        $questions = [];
        $current = null;
        /** @var list<array{plain:string,html:string,runs:array}> */
        $preamble = [];
        $lastNumber = 0;
        $trace = $traceOut !== null;

        foreach ($paragraphs as $pi => $para) {
            $plain = $para['plain'];
            if ($trace) {
                $traceRow = [
                    'para_index' => $pi,
                    'plain_preview' => function_exists('mb_substr')
                        ? mb_substr(trim(preg_replace('/\s+/u', ' ', $plain)), 0, 220, 'UTF-8')
                        : substr(trim(preg_replace('/\s+/u', ' ', $plain)), 0, 220),
                    'list' => [
                        'is_list' => !empty($para['is_list']),
                        'list_level' => $para['list_level'] ?? null,
                        'list_fmt' => $para['list_fmt'] ?? null,
                        'list_ord' => $para['list_ord'] ?? null,
                        'list_num_id' => $para['list_num_id'] ?? null,
                    ],
                    'lead_text_num' => ereview_docx_extract_leading_question_number($plain)[0],
                    'infer_num' => ereview_docx_infer_leading_question_number_from_para($para),
                    'state_in' => [
                        'current_num' => $current['number'] ?? null,
                        'in_choices' => (bool)($current['in_choices'] ?? false),
                    ],
                ];
            }
            if (trim($plain) === '' && $current === null) {
                if ($trace) {
                    $traceRow['action'] = 'skip_blank_preamble';
                    $traceOut[] = $traceRow;
                }
                continue;
            }
            $startsByText = ereview_docx_is_question_start($plain);
            [$leadNum, $leadRest] = ereview_docx_extract_leading_question_number($plain);
            if ($trace) {
                $traceRow['flags'] = [
                    'startsByText' => $startsByText,
                ];
            }
            if (
                $startsByText &&
                $current !== null &&
                $leadNum !== null &&
                (string)$leadNum === (string)$current['number']
            ) {
                // Word line-wrap artifacts can repeat the same number (e.g. "170. statement ...").
                // Keep these as continuation lines to avoid over-splitting and inflated counts.
                $looksContinuation = $leadRest !== '' && preg_match('/^\p{Ll}/u', $leadRest) === 1;
                if ($looksContinuation || $current['in_choices'] === false) {
                    $current['stem_paragraphs'][] = $para;
                    if ($trace) {
                        $traceRow['action'] = 'same_number_continuation_stem';
                        $traceRow['detail'] = ['looksContinuation' => $looksContinuation, 'in_choices' => $current['in_choices']];
                        $traceOut[] = $traceRow;
                    }
                    continue;
                }
            }
            $startsByList = !$startsByText && ereview_docx_is_list_question_start($para);
            // While the MCQ stem is still open (choices not started), list metadata is often a single
            // document-wide sequence: the prompt "For 2020, what is…? (Topic)" may be list ord 51 even
            // though it still belongs to item 50. Do not start a new numbered block on list heuristics alone.
            if ($startsByList && $current !== null && empty($current['in_choices'])) {
                $startsByList = false;
                if ($trace) {
                    $traceRow['flags']['startsByList_suppressed_mid_stem'] = true;
                }
            }
            if (
                $current !== null
                && empty($current['in_choices'])
                && ereview_docx_visible_plain_opens_next_exam_after_stem_phase($para, (string)$current['number'])
            ) {
                $questions[] = ereview_docx_finalize_question_block($current);
                $splitNum = ereview_docx_infer_leading_question_number_from_para($para);
                $num = $splitNum !== null ? (string)$splitNum : '?';
                if ($splitNum !== null) {
                    $lastNumber = $splitNum;
                }
                [$leadFromText, ] = ereview_docx_extract_leading_question_number($plain);
                $startedByList = $leadFromText === null && !empty($para['is_list']);
                $current = [
                    'number' => $num,
                    'stem_paragraphs' => [$para],
                    'choice_paragraphs' => [],
                    'in_choices' => false,
                    'started_by_list' => $startedByList,
                ];
                if ($trace) {
                    $traceRow['action'] = 'split_stem_phase_next_exam_visible';
                    $traceRow['new_number'] = $num;
                    $traceRow['started_by_list'] = $startedByList;
                    $traceOut[] = $traceRow;
                }
                continue;
            }
            if ($trace) {
                $traceRow['flags']['startsByList'] = $startsByList;
                if ($current !== null && !empty($current['in_choices'])) {
                    $traceRow['after_choices'] = [
                        'split_para' => ereview_docx_is_new_question_after_choices_para($para, (string)$current['number']),
                        'split_line_only' => ereview_docx_is_new_question_after_choices_line($plain, (string)$current['number']),
                    ];
                }
            }
            if ($startsByText || $startsByList) {
                if ($current !== null) {
                    $questions[] = ereview_docx_finalize_question_block($current);
                }
                if (preg_match('/^\s*(\d{1,4})[\.\)]\s*/u', $plain, $mm)) {
                    $num = $mm[1];
                    $lastNumber = (int)$num;
                } else {
                    $listFmt = strtolower((string)($para['list_fmt'] ?? ''));
                    $listOrd = (int)($para['list_ord'] ?? 0);
                    if ($startsByList && $listFmt === 'decimal' && $listOrd > 0) {
                        $num = (string)$listOrd;
                        $lastNumber = $listOrd;
                    } else {
                        $lastNumber = $lastNumber > 0 ? $lastNumber + 1 : 1;
                        $num = (string)$lastNumber;
                    }
                }
                $stemParas = $preamble;
                $stemParas[] = $para;
                $preamble = [];
                $current = [
                    'number' => $num,
                    'stem_paragraphs' => $stemParas,
                    'choice_paragraphs' => [],
                    'in_choices' => false,
                    'started_by_list' => (bool)$startsByList,
                ];
                if ($trace) {
                    $traceRow['action'] = 'new_block_question_start';
                    $traceRow['new_number'] = $num;
                    $traceRow['started_by_list'] = (bool)$startsByList;
                    $traceOut[] = $traceRow;
                }
                continue;
            }
            if ($current === null) {
                if (trim($plain) !== '') {
                    $preamble[] = $para;
                }
                if ($trace) {
                    $traceRow['action'] = trim($plain) === '' ? 'skip_blank_no_current' : 'preamble';
                    $traceOut[] = $traceRow;
                }
                continue;
            }
            if (
                !empty($current['in_choices'])
                && ereview_docx_is_new_question_after_choices_para($para, (string)$current['number'])
            ) {
                $questions[] = ereview_docx_finalize_question_block($current);
                $splitNum = ereview_docx_infer_leading_question_number_from_para($para);
                $num = $splitNum !== null ? (string)$splitNum : '?';
                if ($splitNum !== null) {
                    $lastNumber = $splitNum;
                }
                [$leadFromText, ] = ereview_docx_extract_leading_question_number($plain);
                $startedByList = $leadFromText === null && !empty($para['is_list']);
                $current = [
                    'number' => $num,
                    'stem_paragraphs' => [$para],
                    'choice_paragraphs' => [],
                    'in_choices' => false,
                    'started_by_list' => $startedByList,
                ];
                if ($trace) {
                    $traceRow['action'] = 'split_after_choices_new_question';
                    $traceRow['new_number'] = $num;
                    $traceRow['started_by_list'] = $startedByList;
                    $traceOut[] = $traceRow;
                }
                continue;
            }
            if ($current['in_choices'] && ereview_docx_is_topic_tagged_stem_line($plain)) {
                if (ereview_docx_is_subquestion_followup_after_choices($plain)) {
                    foreach ($current['choice_paragraphs'] as $cp) {
                        $current['stem_paragraphs'][] = $cp;
                    }
                    $current['choice_paragraphs'] = [];
                    $current['in_choices'] = false;
                    $current['stem_paragraphs'][] = $para;
                    if ($trace) {
                        $traceRow['action'] = 'subquestion_followup_fold_choices_into_stem';
                        $traceOut[] = $traceRow;
                    }
                    continue;
                }
                $questions[] = ereview_docx_finalize_question_block($current);
                $listFmt = strtolower((string)($para['list_fmt'] ?? ''));
                $listOrd = (int)($para['list_ord'] ?? 0);
                if ($listFmt === 'decimal' && $listOrd > 0) {
                    $lastNumber = $listOrd;
                } else {
                    $lastNumber = $lastNumber > 0 ? $lastNumber + 1 : 1;
                }
                $current = [
                    'number' => (string)$lastNumber,
                    'stem_paragraphs' => [$para],
                    'choice_paragraphs' => [],
                    'in_choices' => false,
                    'started_by_list' => !empty($para['is_list']),
                ];
                if ($trace) {
                    $traceRow['action'] = 'new_block_topic_tagged_stem_after_choices';
                    $traceRow['new_number'] = (string)$lastNumber;
                    $traceOut[] = $traceRow;
                }
                continue;
            }
            $lastStemPlain = ereview_docx_stem_last_nonempty_plain($current['stem_paragraphs']);
            $stemInvitesChoices = ereview_docx_stem_end_invites_choices($lastStemPlain);
            $isLetterListChoice = ereview_docx_is_letter_list_mcq_option_row(
                $para,
                (bool)$current['in_choices'],
                $lastStemPlain
            );
            $isProseInv = ereview_docx_is_prose_inventory_mcq_line($plain);
            $isChoice = ereview_docx_is_choice_line($plain)
                || ereview_docx_is_numeric_choice_line($plain)
                || $isLetterListChoice
                || $isProseInv;
            if (empty($current['in_choices']) && !$stemInvitesChoices) {
                $isChoice = false;
            }
            if ($isChoice && ereview_docx_choice_paragraph_has_substance($para)) {
                $current['in_choices'] = true;
                $current['choice_paragraphs'][] = $para;
                if ($trace) {
                    $traceRow['action'] = 'append_choice';
                    $traceRow['is_choice_line'] = ereview_docx_is_choice_line($plain);
                    $traceRow['is_numeric_choice'] = ereview_docx_is_numeric_choice_line($plain);
                    $traceRow['is_letter_list_choice'] = $isLetterListChoice;
                    $traceRow['is_prose_inventory_choice'] = $isProseInv;
                    $traceRow['stem_invites_choices'] = $stemInvitesChoices;
                    $traceOut[] = $traceRow;
                }
            } elseif ($current['in_choices']) {
                if (!ereview_docx_choice_paragraph_has_substance($para)) {
                    if ($trace) {
                        $traceRow['action'] = 'skip_blank_while_in_choices';
                        $traceOut[] = $traceRow;
                    }
                    continue;
                }
                $current['choice_paragraphs'][] = $para;
                if ($trace) {
                    $traceRow['action'] = 'append_while_in_choices_not_choice_shape';
                    $traceOut[] = $traceRow;
                }
            } else {
                $current['stem_paragraphs'][] = $para;
                if ($trace) {
                    $traceRow['action'] = 'append_stem';
                    $traceOut[] = $traceRow;
                }
            }
        }
        if ($current !== null) {
            $questions[] = ereview_docx_finalize_question_block($current);
        }
        if ($trace) {
            $traceOut[] = [
                'action' => 'summary',
                'total_paragraphs' => count($paragraphs),
                'total_question_blocks' => count($questions),
            ];
        }
        return $questions;
    }
}

if (!function_exists('ereview_docx_finalize_question_block')) {
    function ereview_docx_finalize_question_block(array $block): array {
        $stemParts = [];
        $stemHtmlParts = [];
        foreach ($block['stem_paragraphs'] as $sp) {
            $vis = ereview_docx_with_visible_list_marker($sp);
            $stemParts[] = $vis['plain'];
            $stemHtmlParts[] = $vis['html'];
        }
        $stemPlain = trim(preg_replace('/\s+/u', ' ', implode("\n", $stemParts)));
        $topicParas = ereview_docx_stem_paragraphs_for_question_number($block['stem_paragraphs'], (string)$block['number']);
        $topicStemPlain = trim(preg_replace('/\s+/u', ' ', implode("\n", array_map(static function ($sp) {
            return (string)($sp['plain'] ?? '');
        }, $topicParas))));
        $topic = ereview_docx_topic_from_red_runs($topicParas);
        $topicSource = 'uncategorized';
        if ($topic !== null) {
            $topicSource = 'stem_paren_red';
        } else {
            $topic = ereview_docx_extract_topic_from_stem_forward($topicStemPlain !== '' ? $topicStemPlain : $stemPlain);
            if ($topic !== null) {
                $topicSource = 'stem_paren';
            }
        }
        if ($topic === null && !empty($block['choice_paragraphs'])) {
            $early = [];
            foreach (array_slice($block['choice_paragraphs'], 0, 3) as $cp) {
                $early[] = $cp['plain'] ?? '';
            }
            $base = $topicStemPlain !== '' ? $topicStemPlain : $stemPlain;
            $merged = trim($base . ' ' . preg_replace('/\s+/u', ' ', implode(' ', $early)));
            $topic = ereview_docx_extract_topic_from_stem_forward($merged);
            if ($topic !== null) {
                $topicSource = 'stem_plus_early_lines';
            }
        }

        $dispStemParts = $stemParts;
        $dispHtmlParts = $stemHtmlParts;
        $num = (string)$block['number'];
        if ($num !== '' && $num !== '?' && preg_match('/^\d{1,4}$/', $num)) {
            $qi = !empty($block['started_by_list']) ? 0 : ereview_docx_find_question_paragraph_index($block['stem_paragraphs']);
            if ($qi !== null && isset($dispStemParts[$qi], $dispHtmlParts[$qi])) {
                $p0 = trim((string)$dispStemParts[$qi]);
                if ($p0 !== '' && preg_match('/^\s*(\d{1,4})[\.\)]/u', $p0) !== 1) {
                    $pref = $num . '. ';
                    $dispStemParts[$qi] = $pref . $dispStemParts[$qi];
                    $dispHtmlParts[$qi] = '<span class="ereview-qsort-qnum">' . htmlspecialchars($pref, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>' . $dispHtmlParts[$qi];
                }
            }
        }
        $stemPlainDisplay = trim(preg_replace('/\s+/u', ' ', implode("\n", $dispStemParts)));

        $choiceParas = ereview_docx_filter_substantive_choice_paragraphs($block['choice_paragraphs']);

        return [
            'number' => (string)$block['number'],
            'stem_paragraphs' => $block['stem_paragraphs'],
            'choice_paragraphs' => $choiceParas,
            'topic' => $topic,
            'stem_plain' => $stemPlainDisplay,
            'stem_html_joined' => implode("<br>\n", $dispHtmlParts),
            'choices_html' => ereview_docx_build_choices_html_for_display($choiceParas),
            'topic_source' => $topicSource,
        ];
    }
}

/**
 * Uncategorized tail fragment: internal case numbering + FX table, not a standalone exam item.
 */
if (!function_exists('ereview_docx_finalized_block_is_mergeable_case_tail')) {
    function ereview_docx_finalized_block_is_mergeable_case_tail(array $q): bool {
        $topic = $q['topic'] ?? null;
        if ($topic !== null && $topic !== '') {
            return false;
        }
        $stem = trim((string)($q['stem_plain'] ?? ''));
        if ($stem === '' || strpos($stem, '?') !== false) {
            return false;
        }
        if (!empty($q['choice_paragraphs'])) {
            return false;
        }
        $signals = 0;
        if (preg_match('/The following (?:direct )?exchange rates/i', $stem)) {
            $signals++;
        }
        if (preg_match('/translated amount of retained earnings/i', $stem)) {
            $signals++;
        }
        if (preg_match('/declared dividends/i', $stem) && preg_match('/^\s*[1-9]\.\s+/u', $stem)) {
            $signals++;
        }
        if (preg_match_all(
            '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s*\d{4}\b/i',
            $stem
        ) >= 2) {
            $signals++;
        }

        return $signals >= 1;
    }
}

if (!function_exists('ereview_docx_block_accepts_case_tail_preamble')) {
    function ereview_docx_block_accepts_case_tail_preamble(array $q): bool {
        if (!empty($q['choice_paragraphs'])) {
            return true;
        }
        $topic = $q['topic'] ?? null;
        if ($topic !== null && $topic !== '') {
            return true;
        }
        $stem = (string)($q['stem_plain'] ?? '');

        return strpos($stem, '?') !== false;
    }
}

/**
 * Fold orphan mid-case paragraphs (e.g. items 2–3 + FX table) into the next real MCQ.
 * Several consecutive uncategorized tails chain into one merge target.
 *
 * @param list<array<string,mixed>> $questions
 * @return list<array<string,mixed>>
 */
if (!function_exists('ereview_docx_apply_case_tail_merges')) {
    function ereview_docx_apply_case_tail_merges(array $questions): array {
        $n = count($questions);
        if ($n < 2) {
            return $questions;
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (!ereview_docx_finalized_block_is_mergeable_case_tail($questions[$i])) {
                $out[] = $questions[$i];
                continue;
            }
            /** @var list<array<string,mixed>> $tailChain */
            $tailChain = [$questions[$i]];
            $j = $i + 1;
            while ($j < $n && ereview_docx_finalized_block_is_mergeable_case_tail($questions[$j])) {
                $tailChain[] = $questions[$j];
                $j++;
            }
            if ($j < $n && ereview_docx_block_accepts_case_tail_preamble($questions[$j])) {
                /** @var list<array{plain:string,html:string,runs?:array}> $mergedStem */
                $mergedStem = [];
                foreach ($tailChain as $t) {
                    $sp = $t['stem_paragraphs'] ?? [];
                    if (is_array($sp)) {
                        foreach ($sp as $row) {
                            $mergedStem[] = $row;
                        }
                    }
                }
                $suffix = $questions[$j];
                $sufStem = $suffix['stem_paragraphs'] ?? [];
                if (!is_array($sufStem)) {
                    $sufStem = [];
                }
                $block = [
                    'number' => (string)($suffix['number'] ?? '?'),
                    'stem_paragraphs' => array_merge($mergedStem, $sufStem),
                    'choice_paragraphs' => $suffix['choice_paragraphs'] ?? [],
                    'started_by_list' => false,
                ];
                $out[] = ereview_docx_finalize_question_block($block);
                $i = $j;
                continue;
            }
            foreach ($tailChain as $t) {
                $out[] = $t;
            }
            $i = $j - 1;
        }

        return $out;
    }
}

/**
 * Long case / SFP / assumptions wrongly collected as MCQ choices (e.g. after a.–d.).
 *
 * @param array{plain?:string,html?:string} $para
 */
if (!function_exists('ereview_docx_choice_para_smells_like_general_problem_narrative')) {
    function ereview_docx_choice_para_smells_like_general_problem_narrative(array $para): bool {
        $plain = trim(preg_replace('/\s+/u', ' ', (string)($para['plain'] ?? '')));
        if ($plain === '') {
            return false;
        }
        $visGp = ereview_docx_with_visible_list_marker($para);
        $visPlainGp = trim(preg_replace('/\s+/u', ' ', (string)($visGp['plain'] ?? '')));
        if ($visPlainGp !== '' && ereview_docx_plain_smells_like_consolidation_or_gp_prologue($visPlainGp)) {
            return true;
        }
        if ($visPlainGp !== '' && ereview_docx_plain_smells_like_gp_range_intro($visPlainGp)) {
            return true;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
        if (preg_match('/^On\s+\w+\s+\d{1,2},\s+\d{4}/u', $plain)) {
            return true;
        }
        if (stripos($plain, 'acquired on account') !== false && stripos($plain, 'foreign') !== false) {
            return true;
        }
        if (stripos($plain, 'sold on account') !== false && stripos($plain, 'foreign') !== false) {
            return true;
        }
        if (stripos($plain, 'accounts payable are paid') !== false) {
            return true;
        }
        if (stripos($plain, 'accounts receivable are collected') !== false) {
            return true;
        }
        if (stripos($plain, 'Philippine economy') !== false && stripos($plain, 'functional currency') !== false) {
            return true;
        }
        if (stripos($plain, 'Buying spot rate') !== false && stripos($plain, 'Selling spot rate') !== false) {
            return true;
        }
        if (ereview_docx_plain_smells_like_gp_range_intro($plain)) {
            return true;
        }
        if (preg_match('/\bAs\s+of\s+December\s+31\b/ui', $plain) && stripos($plain, 'financial statements appeared as follows') !== false) {
            return true;
        }
        if (stripos($plain, 'During the year') !== false && stripos($plain, 'still owns') !== false) {
            return true;
        }
        if (preg_match('/^Entity\s+[A-Z]\b.*\b(?:owns|owned)\b/ui', $plain)) {
            return true;
        }
        if (stripos($plain, 'outstanding ordinary shares') !== false) {
            return true;
        }
        if (stripos($plain, 'Statement of Financial Position') !== false) {
            return true;
        }
        if (stripos($plain, 'functional currency') !== false && stripos($plain, 'presentation currency') !== false) {
            return true;
        }
        if (preg_match('/Current\s+assets.*?P\s*[\d,]+.*?Current\s+liabilities/ui', $plain)) {
            return true;
        }
        if ($len >= 80 && preg_match('/Ordinary\s+share\s+capital/ui', $plain) && preg_match('/Retained\s+earnings/ui', $plain)) {
            return true;
        }
        if (preg_match('/^Non\s*current\s+assets/ui', $plain) && preg_match('/P\s*[\d,]+/u', $plain)) {
            return true;
        }
        if (preg_match('/^Total\s+Assets\b/ui', $plain) && preg_match('/Retained\s+earnings/ui', $plain)) {
            return true;
        }
        if (preg_match('/^The following direct exchange rates\b/ui', $plain)) {
            return true;
        }
        if (stripos($plain, 'translated amount of retained earnings') !== false) {
            return true;
        }

        return false;
    }
}

/**
 * Parsed block that is really a shared case setup (not an exam question row).
 *
 * @param array<string,mixed> $q
 */
if (!function_exists('ereview_docx_question_row_smells_like_general_problem_block')) {
    function ereview_docx_question_row_smells_like_general_problem_block(array $q): bool {
        $stemPlain = trim(preg_replace('/\s+/u', ' ', (string)($q['stem_plain'] ?? '')));
        if ($stemPlain === '') {
            return false;
        }
        if (strpos($stemPlain, '?') !== false) {
            return false;
        }
        $choices = $q['choice_paragraphs'] ?? [];
        if (is_array($choices) && count($choices) > 0) {
            return false;
        }
        if (ereview_docx_plain_smells_like_gp_range_intro($stemPlain)) {
            return true;
        }
        if (ereview_docx_plain_smells_like_consolidation_or_gp_prologue($stemPlain)) {
            return true;
        }
        if (stripos($stemPlain, 'financial statements appeared as follows') !== false) {
            return true;
        }

        return false;
    }
}

/**
 * Split question stem into [preamble before actual numbered item, stem from actual item].
 *
 * @param list<array<string,mixed>> $stemParagraphs
 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
 */
if (!function_exists('ereview_docx_split_stem_preamble_before_question_number')) {
    function ereview_docx_split_stem_preamble_before_question_number(array $stemParagraphs, string $number): array {
        if ($stemParagraphs === [] || $number === '' || $number === '?' || preg_match('/^\d{1,4}$/', $number) !== 1) {
            return [[], $stemParagraphs];
        }
        $nInt = (int)$number;
        $n = preg_quote($number, '/');
        $startIdx = null;
        foreach ($stemParagraphs as $i => $sp) {
            $p = trim((string)($sp['plain'] ?? ''));
            $typedStart = $p !== '' && preg_match('/^\s*' . $n . '[\.\)]\s+/u', $p);
            $listFmt = strtolower((string)($sp['list_fmt'] ?? ''));
            $listOrd = (int)($sp['list_ord'] ?? 0);
            $lvlRaw = $sp['list_level'] ?? null;
            $ilvl = $lvlRaw !== null && $lvlRaw !== '' ? (int)$lvlRaw : 0;
            $listStart = !empty($sp['is_list']) && $listFmt === 'decimal' && $listOrd === $nInt && $ilvl === 0;
            if ($typedStart || $listStart) {
                $startIdx = $i;
                break;
            }
        }
        if ($startIdx === null || $startIdx <= 0) {
            return [[], $stemParagraphs];
        }

        return [
            array_slice($stemParagraphs, 0, $startIdx),
            array_slice($stemParagraphs, $startIdx),
        ];
    }
}

/**
 * Strip narrative tails from choice_paragraphs; each stripped segment becomes one general-problem entry.
 *
 * @param list<array<string,mixed>> $questions Finalized question rows
 * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>} [questions, general_problems]
 */
if (!function_exists('ereview_docx_extract_general_problems_from_questions')) {
    function ereview_docx_extract_general_problems_from_questions(array $questions): array {
        $outQs = [];
        $generalProblems = [];
        $gpId = 0;
        $lastRealQuestionNum = '';
        foreach ($questions as $q) {
            $qn = (string)($q['number'] ?? '');
            [$stemPreamble, $stemFromQuestion] = ereview_docx_split_stem_preamble_before_question_number(
                is_array($q['stem_paragraphs'] ?? null) ? $q['stem_paragraphs'] : [],
                $qn
            );
            if ($stemPreamble !== []) {
                $joined = trim(preg_replace('/\s+/u', ' ', implode("\n", array_map(static function ($sp) {
                    return (string)($sp['plain'] ?? '');
                }, $stemPreamble))));
                if (
                    ereview_docx_plain_smells_like_gp_range_intro($joined)
                    || ereview_docx_plain_smells_like_consolidation_or_gp_prologue($joined)
                    || stripos($joined, 'financial statements appeared as follows') !== false
                    || stripos($joined, 'unadjusted trial balance') !== false
                ) {
                    $gpId++;
                    $after = $lastRealQuestionNum;
                    if ($after === '' && preg_match('/^\d{1,4}$/', $qn)) {
                        $qi = (int)$qn;
                        if ($qi > 1) {
                            $after = (string)($qi - 1);
                        }
                    }
                    $gpBlock = [
                        'number' => '',
                        'stem_paragraphs' => $stemPreamble,
                        'choice_paragraphs' => [],
                        'started_by_list' => false,
                    ];
                    $gpFinal = ereview_docx_finalize_question_block($gpBlock);
                    $generalProblems[] = [
                        'id' => $gpId,
                        'stem_plain' => $gpFinal['stem_plain'] ?? '',
                        'stem_html_joined' => $gpFinal['stem_html_joined'] ?? '',
                        'stem_paragraphs' => $gpFinal['stem_paragraphs'] ?? [],
                        'extracted_after_question' => $after,
                    ];
                    $q = ereview_docx_finalize_question_block([
                        'number' => $qn,
                        'stem_paragraphs' => $stemFromQuestion,
                        'choice_paragraphs' => is_array($q['choice_paragraphs'] ?? null) ? $q['choice_paragraphs'] : [],
                        'started_by_list' => false,
                    ]);
                }
            }
            if (ereview_docx_question_row_smells_like_general_problem_block($q)) {
                $gpId++;
                $after = $lastRealQuestionNum;
                if ($after === '') {
                    if (preg_match('/^\d{1,4}$/', $qn)) {
                        $n = (int)$qn;
                        if ($n > 1) {
                            $after = (string)($n - 1);
                        }
                    }
                }
                $gpBlock = [
                    'number' => '',
                    'stem_paragraphs' => $q['stem_paragraphs'] ?? [],
                    'choice_paragraphs' => [],
                    'started_by_list' => false,
                ];
                $gpFinal = ereview_docx_finalize_question_block($gpBlock);
                $generalProblems[] = [
                    'id' => $gpId,
                    'stem_plain' => $gpFinal['stem_plain'] ?? '',
                    'stem_html_joined' => $gpFinal['stem_html_joined'] ?? '',
                    'stem_paragraphs' => $gpFinal['stem_paragraphs'] ?? [],
                    'extracted_after_question' => $after,
                ];
                continue;
            }
            $cps = $q['choice_paragraphs'] ?? [];
            if (!is_array($cps) || $cps === []) {
                $outQs[] = $q;
                if (preg_match('/^\d{1,4}$/', $qn)) {
                    $lastRealQuestionNum = $qn;
                }
                continue;
            }
            $splitAt = null;
            $n = count($cps);
            for ($i = 0; $i < $n; $i++) {
                if (ereview_docx_choice_para_smells_like_general_problem_narrative($cps[$i])) {
                    $splitAt = $i;
                    break;
                }
            }
            if ($splitAt === null || $splitAt < 4) {
                $outQs[] = $q;
                if (preg_match('/^\d{1,4}$/', $qn)) {
                    $lastRealQuestionNum = $qn;
                }
                continue;
            }
            while ($splitAt > 4) {
                $prev = $splitAt - 1;
                $vis = ereview_docx_with_visible_list_marker($cps[$prev]);
                $vplain = trim(preg_replace('/\s+/u', ' ', (string)($vis['plain'] ?? '')));
                if ($vplain === '') {
                    $splitAt = $prev;
                    continue;
                }
                if (ereview_docx_is_numeric_choice_line($vplain)) {
                    break;
                }
                if (ereview_docx_is_choice_line($vplain)) {
                    if (preg_match('/^[a-d][\.\)]\s+/iu', $vplain)
                        && !ereview_docx_choice_para_smells_like_general_problem_narrative($cps[$prev])) {
                        break;
                    }
                }
                $splitAt = $prev;
            }
            $keep = array_slice($cps, 0, $splitAt);
            $gpParas = array_slice($cps, $splitAt);
            $block = [
                'number' => (string)($q['number'] ?? '?'),
                'stem_paragraphs' => $q['stem_paragraphs'] ?? [],
                'choice_paragraphs' => $keep,
                'started_by_list' => false,
            ];
            $outQs[] = ereview_docx_finalize_question_block($block);
            $gpId++;
            $gpBlock = [
                'number' => '',
                'stem_paragraphs' => $gpParas,
                'choice_paragraphs' => [],
                'started_by_list' => false,
            ];
            $gpFinal = ereview_docx_finalize_question_block($gpBlock);
            $generalProblems[] = [
                'id' => $gpId,
                'stem_plain' => $gpFinal['stem_plain'] ?? '',
                'stem_html_joined' => $gpFinal['stem_html_joined'] ?? '',
                'stem_paragraphs' => $gpFinal['stem_paragraphs'] ?? [],
                'extracted_after_question' => (string)($q['number'] ?? ''),
            ];
            $qn = (string)($q['number'] ?? '');
            if (preg_match('/^\d{1,4}$/', $qn)) {
                $lastRealQuestionNum = $qn;
            }
        }

        return [$outQs, $generalProblems];
    }
}

/**
 * @param list<array<string,mixed>>|null $traceOut Optional; filled with parse trace (paragraph decisions).
 * @return array{topics: array<string, list>, questions: list, general_problems: list, stats: array}
 */
if (!function_exists('ereview_docx_parse_and_group')) {
    function ereview_docx_parse_and_group(string $docxPath, ?array &$traceOut = null): array {
        $paragraphs = ereview_docx_extract_paragraphs($docxPath);
        $questions = ereview_docx_build_question_blocks($paragraphs, $traceOut);
        $questions = ereview_docx_apply_case_tail_merges($questions);
        [$questions, $generalProblems] = ereview_docx_extract_general_problems_from_questions($questions);
        $topics = [];
        foreach ($questions as $q) {
            $t = $q['topic'] ?? null;
            $key = $t !== null && $t !== '' ? $t : 'Uncategorized';
            if (!isset($topics[$key])) {
                $topics[$key] = [];
            }
            $topics[$key][] = $q;
        }
        ksort($topics, SORT_NATURAL | SORT_FLAG_CASE);
        if (isset($topics['Uncategorized'])) {
            $u = $topics['Uncategorized'];
            unset($topics['Uncategorized']);
            $topics['Uncategorized'] = $u;
        }
        return [
            'topics' => $topics,
            'questions' => $questions,
            'general_problems' => $generalProblems,
            'stats' => [
                'paragraph_count' => count($paragraphs),
                'question_count' => count($questions),
                'general_problem_count' => count($generalProblems),
                'topic_count' => count($topics),
            ],
        ];
    }
}
