<?php
/**
 * Analytics performance workbook and PDF. Read-only rendering.
 * Progress PDF/Excel builders are not used here.
 */
declare(strict_types=1);

require_once __DIR__ . '/examination_monitor_analytics_export.php';

/**
 * Distinct assigned subject names in the analytics payload. Unassigned is not a subject.
 *
 * @param array<string,mixed> $ana
 * @return list<string>
 */
function examination_monitor_analytics_subject_names(array $ana): array
{
    $names = [];
    $add = static function (string $name) use (&$names): void {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'Unassigned') === 0) {
            return;
        }
        $names[strtolower($name)] = $name;
    };
    foreach (['subjects', 'examinee_subjects'] as $key) {
        $rows = is_array($ana[$key] ?? null) ? $ana[$key] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['unassigned'])) {
                continue;
            }
            $add((string)($row['subject_name'] ?? $row['name'] ?? ''));
        }
    }
    foreach (['topics', 'questions', 'examinee_topics'] as $key) {
        $rows = is_array($ana[$key] ?? null) ? $ana[$key] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $add((string)($row['subject_name'] ?? ''));
        }
    }
    natcasesort($names);

    return array_values($names);
}

/**
 * @param array{title?:string,hint?:string,headers?:list<string>,rows?:list<list<string>>} $section
 * @return array{title:string,hint:string,headers:list<string>,rows:list<list<string>>}
 */
function examination_monitor_analytics_drop_sheet_column(array $section, string $header): array
{
    $headers = is_array($section['headers'] ?? null) ? array_values($section['headers']) : [];
    $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
    $index = array_search($header, $headers, true);
    if ($index === false) {
        return [
            'title' => (string)($section['title'] ?? ''),
            'hint' => (string)($section['hint'] ?? ''),
            'headers' => $headers,
            'rows' => $rows,
        ];
    }
    array_splice($headers, (int)$index, 1);
    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = array_values($row);
        array_splice($line, (int)$index, 1);
        $rows[$rowIndex] = $line;
    }

    return [
        'title' => (string)($section['title'] ?? ''),
        'hint' => (string)($section['hint'] ?? ''),
        'headers' => $headers,
        'rows' => $rows,
    ];
}

/**
 * Keep the response-count disclosure on Questions and add the non-A-D count when it exists.
 *
 * @param array{title?:string,hint?:string,headers?:list<string>,rows?:list<list<string>>} $section
 * @param list<array<string,mixed>> $questions
 * @param array<string,mixed> $difficulty
 * @return array{title:string,hint:string,headers:list<string>,rows:list<list<string>>}
 */
function examination_monitor_analytics_questions_for_workbook(array $section, array $questions, array $difficulty): array
{
    $headers = is_array($section['headers'] ?? null) ? array_values($section['headers']) : [];
    $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
    $hint = (string)($section['hint'] ?? '');
    $note = trim((string)($difficulty['note'] ?? ''));
    if ($note !== '') {
        $compact = 'These response counts are not verified difficulty. The snapshot does not store the displayed correct letter, and the current question bank is not used to reconstruct one.';
        if (preg_match('/Snapshot statistics.+/', $note, $snapshot) === 1) {
            $compact .= ' ' . $snapshot[0];
        } elseif (str_contains($note, 'No snapshot rows were available')) {
            $compact .= ' No snapshot rows were available in this scope.';
        }
        $hint = trim($hint . "\n" . $compact);
    }
    $otherById = [];
    $difficultyQuestions = is_array($difficulty['questions'] ?? null) ? $difficulty['questions'] : [];
    foreach ($difficultyQuestions as $row) {
        if (!is_array($row)) {
            continue;
        }
        $otherById[(int)($row['question_id'] ?? 0)] = (string)(int)($row['other'] ?? 0);
    }
    if ($otherById !== []) {
        $insertAt = array_search('D', $headers, true);
        $insertAt = $insertAt === false ? count($headers) : ((int)$insertAt + 1);
        array_splice($headers, $insertAt, 0, ['Other']);
        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = array_values($row);
            $questionId = (int)($questions[$rowIndex]['question_id'] ?? 0);
            array_splice($line, $insertAt, 0, [$otherById[$questionId] ?? '0']);
            $rows[$rowIndex] = $line;
        }
    }

    return [
        'title' => (string)($section['title'] ?? ''),
        'hint' => $hint,
        'headers' => $headers,
        'rows' => $rows,
    ];
}

function examination_monitor_analytics_section_compare_key(string $section): string
{
    $collapsed = trim(preg_replace('/\s+/u', ' ', $section) ?? $section);
    if ($collapsed === '') {
        return '';
    }

    return function_exists('mb_strtolower') ? mb_strtolower($collapsed, 'UTF-8') : strtolower($collapsed);
}

/**
 * One display label for labels the existing section rule already treats as equal.
 * "a" and "Section A" stay distinct because their compare keys differ.
 *
 * @param list<string> $labels
 * @return array<string,string>
 */
function examination_monitor_analytics_section_display_map(array $labels): array
{
    $groups = [];
    foreach ($labels as $label) {
        $collapsed = trim(preg_replace('/\s+/u', ' ', trim($label)) ?? trim($label));
        if ($collapsed === '' || $collapsed === 'No section') {
            continue;
        }
        $key = examination_monitor_analytics_section_compare_key($collapsed);
        if ($key === '') {
            continue;
        }
        $groups[$key][$collapsed] = ($groups[$key][$collapsed] ?? 0) + 1;
    }
    $map = [];
    foreach ($groups as $variants) {
        $choices = array_keys($variants);
        usort($choices, static function (string $a, string $b) use ($variants): int {
            $form = static function (string $label): int {
                if (preg_match('/^\p{L}$/u', $label) === 1 && preg_match('/\p{Lu}/u', $label) === 1) {
                    return 0;
                }
                if (preg_match('/\p{Lu}/u', $label) === 1) {
                    return 1;
                }

                return 2;
            };
            $byForm = $form($a) <=> $form($b);
            if ($byForm !== 0) {
                return $byForm;
            }
            $byCount = $variants[$b] <=> $variants[$a];
            if ($byCount !== 0) {
                return $byCount;
            }

            return strlen($b) <=> strlen($a);
        });
        $display = (string)$choices[0];
        if (preg_match('/^\p{Ll}$/u', $display) === 1) {
            $display = function_exists('mb_strtoupper') ? mb_strtoupper($display, 'UTF-8') : strtoupper($display);
        }
        foreach ($choices as $choice) {
            $map[$choice] = $display;
        }
    }

    return $map;
}

function examination_monitor_analytics_section_display(string $raw, array $map): string
{
    $collapsed = trim(preg_replace('/\s+/u', ' ', trim($raw)) ?? trim($raw));
    if ($collapsed === '') {
        return '';
    }

    return $map[$collapsed] ?? $collapsed;
}

/**
 * @param array<string,mixed> $chart
 * @param list<array<string,mixed>> $examinees
 * @param array<string,string> $map
 * @return array<string,mixed>
 */
function examination_monitor_analytics_present_section_chart(array $chart, array $examinees, array $map): array
{
    $original = $chart;
    $seen = [];
    $collapse = false;
    foreach ($examinees as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = trim((string)($row['section'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $key = examination_monitor_analytics_section_compare_key($raw);
        if ($key === '') {
            continue;
        }
        if (isset($seen[$key]) && $seen[$key] !== $raw) {
            $collapse = true;
        }
        $seen[$key] = $raw;
    }
    if (!$collapse) {
        if (is_array($chart['labels'] ?? null)) {
            foreach ($chart['labels'] as $index => $label) {
                $chart['labels'][$index] = examination_monitor_analytics_section_display((string)$label, $map);
            }
        }

        return $chart;
    }
    $groups = [];
    foreach ($examinees as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = trim((string)($row['section'] ?? ''));
        $key = $raw === '' ? "\0none" : examination_monitor_analytics_section_compare_key($raw);
        $label = $raw === '' ? 'No section' : examination_monitor_analytics_section_display($raw, $map);
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $label, 'sum' => 0.0, 'n' => 0];
        }
        if (!empty($row['graded']) && isset($row['percent']) && is_numeric($row['percent'])) {
            $groups[$key]['sum'] += (float)$row['percent'];
            $groups[$key]['n']++;
        }
    }
    uasort($groups, static function (array $a, array $b): int {
        return strnatcasecmp((string)$a['label'], (string)$b['label']);
    });
    $labels = [];
    $values = [];
    foreach ($groups as $group) {
        if ((int)$group['n'] <= 0) {
            continue;
        }
        $labels[] = (string)$group['label'];
        $values[] = round($group['sum'] / $group['n'], 1);
    }
    $chart['labels'] = $labels;
    $chart['values'] = $values;
    if (count($labels) >= 2) {
        $chart['included'] = true;

        return $chart;
    }
    if (empty($original['included'])) {
        return $original;
    }
    $chart['included'] = false;
    $chart['note'] = 'Section comparison is omitted because the roster has fewer than two sections.';

    return $chart;
}

/**
 * @param array{title?:string,hint?:string,headers?:list<string>,rows?:list<list<string>>} $section
 * @param array<string,string> $map
 * @return array{title:string,hint:string,headers:list<string>,rows:list<list<string>>}
 */
function examination_monitor_analytics_relabel_section_column(array $section, array $map): array
{
    $headers = is_array($section['headers'] ?? null) ? array_values($section['headers']) : [];
    $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
    $index = array_search('Section', $headers, true);
    if ($index === false) {
        return [
            'title' => (string)($section['title'] ?? ''),
            'hint' => (string)($section['hint'] ?? ''),
            'headers' => $headers,
            'rows' => $rows,
        ];
    }
    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = array_values($row);
        $line[(int)$index] = examination_monitor_analytics_section_display((string)($line[(int)$index] ?? ''), $map);
        $rows[$rowIndex] = $line;
    }

    return [
        'title' => (string)($section['title'] ?? ''),
        'hint' => (string)($section['hint'] ?? ''),
        'headers' => $headers,
        'rows' => $rows,
    ];
}

/**
 * Volume and individual-score charts copy counts already stored on the report.
 * They are omitted from the PDF chart plan and do not relax sample rules.
 *
 * @param array<string,mixed> $ana
 * @param list<array<string,mixed>> $examinees
 * @return array<string,array<string,mixed>>
 */
function examination_monitor_analytics_descriptive_charts(array $ana, array $examinees): array
{
    $topics = [];
    foreach (is_array($ana['topics'] ?? null) ? $ana['topics'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        $answered = (int)($row['answered'] ?? 0);
        if ($name === '' || $answered <= 0) {
            continue;
        }
        $topics[] = ['label' => $name, 'value' => $answered];
    }
    usort($topics, static function (array $a, array $b): int {
        return strnatcasecmp((string)$a['label'], (string)$b['label']);
    });
    $truncated = count($topics) > 12;
    if ($truncated) {
        $topics = array_slice($topics, 0, 12);
    }
    $people = [];
    foreach ($examinees as $row) {
        if (!is_array($row) || empty($row['graded']) || !isset($row['percent']) || !is_numeric($row['percent'])) {
            continue;
        }
        $people[] = [
            'label' => trim((string)($row['full_name'] ?? '')) !== '' ? trim((string)$row['full_name']) : 'Examinee',
            'value' => round((float)$row['percent'], 1),
        ];
    }

    return [
        'topic_volume' => [
            'included' => false,
            'title' => 'Answered items by topic',
            'note' => 'Each bar counts stored answered items. This is not stored accuracy, not a class performance comparison, and not a difficulty ranking.'
                . ($truncated ? ' Showing 12 topics alphabetically.' : ''),
            'labels' => array_column($topics, 'label'),
            'values' => array_column($topics, 'value'),
        ],
        'individual_scores' => [
            'included' => false,
            'title' => 'Individual exam scores',
            'note' => 'Individual scores are listed in the results table.',
            'labels' => [],
            'values' => [],
        ],
    ];
}

function examination_monitor_analytics_concise_stored_note(): string
{
    return 'The recorded correct rate uses saved scoring records. A later rescore can change those records. A missing or invalid scoring flag is not counted as incorrect. This rate is not an independently verified historical result, is not question difficulty, and is not a difficulty ranking.';
}

function examination_monitor_analytics_result_label(array $row): string
{
    if (empty($row['graded']) || ($row['mark'] ?? '') === '') {
        return '';
    }
    if (($row['mark'] ?? '') !== 'Pass') {
        return 'Failed';
    }
    if (!is_numeric($row['percent'] ?? null)) {
        return 'Failed';
    }

    return ((float)$row['percent'] >= 75.0) ? 'Passed – Satisfactory' : 'Passed – Needs Reinforcement';
}

/**
 * Saved item totals for one examinee.
 * Subject rows are used when they cover at least as many items as topic rows.
 * Topic rows are used when they are the only saved rows, or when they cover more items.
 * The two sets are not added together. Excluded flags stay out of the incorrect count.
 *
 * @param list<array<string,mixed>> $rows
 * @return array<int,array{correct:int,incorrect:int,unanswered:int,excluded:int,presented:int}>
 */
function examination_monitor_analytics_sum_examinee_items(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        if (!isset($out[$userId])) {
            $out[$userId] = ['correct' => 0, 'incorrect' => 0, 'unanswered' => 0, 'excluded' => 0, 'presented' => 0];
        }
        $out[$userId]['correct'] += (int)($row['correct'] ?? 0);
        $out[$userId]['incorrect'] += (int)($row['incorrect'] ?? 0);
        $out[$userId]['unanswered'] += (int)($row['unanswered'] ?? 0);
        $out[$userId]['excluded'] += (int)($row['excluded'] ?? 0);
        $out[$userId]['presented'] += (int)($row['presented'] ?? 0);
    }

    return $out;
}

/**
 * @param array<string,mixed> $ana
 * @return array<int,array{correct:int,incorrect:int,unanswered:int,excluded:int,presented:int}>
 */
function examination_monitor_analytics_examinee_item_counts(array $ana): array
{
    $fromSubjects = examination_monitor_analytics_sum_examinee_items(is_array($ana['examinee_subjects'] ?? null) ? $ana['examinee_subjects'] : []);
    $fromTopics = examination_monitor_analytics_sum_examinee_items(is_array($ana['examinee_topics'] ?? null) ? $ana['examinee_topics'] : []);
    $out = [];
    foreach (array_unique(array_merge(array_keys($fromSubjects), array_keys($fromTopics))) as $userId) {
        $subjectPresented = (int)($fromSubjects[$userId]['presented'] ?? 0);
        $topicPresented = (int)($fromTopics[$userId]['presented'] ?? 0);
        if ($subjectPresented <= 0 && $topicPresented <= 0) {
            continue;
        }
        $out[$userId] = $subjectPresented >= $topicPresented ? $fromSubjects[$userId] : $fromTopics[$userId];
    }

    return $out;
}

/**
 * @param array{title?:string,hint?:string,headers?:list<string>,rows?:list<list<string>>} $section
 * @param list<string> $order old header names, in lecturer order
 * @param array<string,string> $rename
 * @return array{title:string,hint:string,headers:list<string>,rows:list<list<string>>,bands:list<string>}
 */
function examination_monitor_analytics_lecturer_columns(array $section, array $order, array $rename, bool $classSample): array
{
    $headers = is_array($section['headers'] ?? null) ? array_values($section['headers']) : [];
    $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
    $index = [];
    foreach ($headers as $position => $header) {
        $index[(string)$header] = $position;
    }
    $excludedTotal = 0;
    if (isset($index['Excluded from correctness'])) {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $excludedTotal += (int)($row[$index['Excluded from correctness']] ?? 0);
            }
        }
    }
    $newHeaders = [];
    foreach ($order as $old) {
        if (!isset($index[$old])) {
            continue;
        }
        $newHeaders[] = $rename[$old] ?? $old;
    }
    $newRows = [];
    $bands = [];
    $sampleAt = $index['Sample'] ?? null;
    $rateAt = $index['Stored accuracy'] ?? null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = array_values($row);
        $built = [];
        foreach ($order as $old) {
            if (!isset($index[$old])) {
                continue;
            }
            $built[] = (string)($line[$index[$old]] ?? '');
        }
        $newRows[] = $built;
        $rateText = $rateAt === null ? '' : (string)($line[$rateAt] ?? '');
        $percent = null;
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $rateText, $rateMatch) === 1) {
            $percent = (float)$rateMatch[1];
        }
        $qualified = $percent !== null;
        if ($classSample) {
            $qualified = $qualified && ($sampleAt === null || trim((string)($line[$sampleAt] ?? '')) === '');
        }
        $bands[] = examination_monitor_analytics_rate_band($percent, $qualified);
    }
    $hint = (string)($section['hint'] ?? '');
    if ($excludedTotal > 0) {
        $itemWord = $excludedTotal === 1 ? 'item had' : 'items had';
        $hint = trim($hint . ' ' . $excludedTotal . ' answered ' . $itemWord . ' a missing or invalid scoring flag and ' . ($excludedTotal === 1 ? 'is' : 'are') . ' not counted as incorrect.');
    }

    return [
        'title' => (string)($section['title'] ?? ''),
        'hint' => $hint,
        'headers' => $newHeaders,
        'rows' => $newRows,
        'bands' => $bands,
    ];
}

function examination_monitor_analytics_rate_band(?float $percent, bool $qualified): string
{
    if (!$qualified || $percent === null) {
        return '';
    }
    if ($percent < 40.0) {
        return 'C';
    }
    if ($percent < 50.0) {
        return 'N';
    }
    if ($percent < 70.0) {
        return 'D';
    }

    return 'G';
}

function examination_monitor_analytics_diagnostic_label(string $band): string
{
    return match ($band) {
        'C' => 'CRITICAL',
        'N' => 'NEEDS IMPROVEMENT',
        'D' => 'DEVELOPING',
        'G' => 'GOOD',
        default => 'Insufficient Sample',
    };
}

function examination_monitor_analytics_diagnostic_focus(string $band, bool $individual): string
{
    if ($band === '') {
        return $individual
            ? 'No saved rate is available for this topic, so no individual priority is assigned.'
            : 'Saved counts stay visible. This topic does not meet the class sample, so it has no class-level priority.';
    }
    $action = match ($band) {
        'C' => 'Prioritize these topics for focused re-teaching and guided practice.',
        'N' => 'Schedule additional discussion, examples, and practice questions.',
        'D' => 'Reinforce concepts and monitor through short assessments.',
        default => 'Maintain current understanding through review and recall activities.',
    };
    if (!$individual) {
        return $action;
    }

    return match ($band) {
        'C' => 'This examinee needs focused re-teaching and guided practice on this topic.',
        'N' => 'Give this examinee more examples and practice questions on this topic.',
        'D' => 'Reinforce this topic with this examinee and check it in a short assessment.',
        default => 'Keep this topic fresh for this examinee through review and recall.',
    };
}

function examination_monitor_analytics_diagnostic_interpretation(string $band): string
{
    return match ($band) {
        'C' => 'Critical for this examinee',
        'N' => 'Needs improvement for this examinee',
        'D' => 'Developing for this examinee',
        'G' => 'Good for this examinee',
        default => 'Unavailable',
    };
}

/**
 * @param array{title:string,hint:string,headers:list<string>,rows:list<list<string>>,bands:list<string>} $section
 * @return array{title:string,hint:string,headers:list<string>,rows:list<list<string>>,bands:list<string>}
 */
function examination_monitor_analytics_attach_diagnostic(array $section, bool $individual): array
{
    $rateAt = array_search('Recorded Correct Rate', $section['headers'], true);
    $nameAt = array_search('Examinee Name', $section['headers'], true);
    $topicAt = array_search('Topic', $section['headers'], true);
    $subjectAt = array_search('Subject', $section['headers'], true);
    $paired = [];
    foreach ($section['rows'] as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rate = null;
        $rateText = $rateAt === false ? '' : (string)($row[$rateAt] ?? '');
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $rateText, $match) === 1) {
            $rate = (float)$match[1];
        }
        $band = (string)($section['bands'][$index] ?? '');
        $paired[] = [
            'row' => array_values($row),
            'band' => $band,
            'rate' => $rate,
            'name' => $nameAt === false ? '' : (string)($row[$nameAt] ?? ''),
            'topic' => $topicAt === false ? '' : (string)($row[$topicAt] ?? ''),
            'subject' => $subjectAt === false ? '' : (string)($row[$subjectAt] ?? ''),
            'index' => $index,
        ];
    }
    usort($paired, static function (array $left, array $right) use ($individual): int {
        if ($individual) {
            $byName = strcasecmp($left['name'], $right['name']);
            if ($byName !== 0) {
                return $byName;
            }
        }
        $leftKnown = $left['band'] !== '';
        $rightKnown = $right['band'] !== '';
        if ($leftKnown !== $rightKnown) {
            return $leftKnown ? -1 : 1;
        }
        $leftRate = $left['rate'] ?? 9999.0;
        $rightRate = $right['rate'] ?? 9999.0;
        if ($leftRate !== $rightRate) {
            return $leftRate <=> $rightRate;
        }

        return strcasecmp($left['subject'] . ' ' . $left['topic'], $right['subject'] . ' ' . $right['topic']) ?: ($left['index'] <=> $right['index']);
    });
    $rows = [];
    $bands = [];
    foreach ($paired as $item) {
        $line = $item['row'];
        if ($individual) {
            $line[] = examination_monitor_analytics_diagnostic_interpretation($item['band']);
            $line[] = examination_monitor_analytics_diagnostic_focus($item['band'], true);
        } else {
            $line[] = examination_monitor_analytics_diagnostic_label($item['band']);
            $line[] = examination_monitor_analytics_diagnostic_focus($item['band'], false);
        }
        $rows[] = $line;
        $bands[] = $item['band'];
    }
    $section['rows'] = $rows;
    $section['bands'] = $bands;
    $section['headers'][] = $individual ? 'Performance Interpretation' : 'Priority';
    $section['headers'][] = "Lecturer's Focus";

    return $section;
}

/**
 * Numbering uses the saved attempt display position when every question has one shared position.
 * The database id is never used as the displayed question number.
 *
 * @param array{title?:string,hint?:string,headers?:list<string>,rows?:list<list<string>>} $section
 * @param list<array<string,mixed>> $questions
 * @return array{section:array{title?:string,hint?:string,headers?:list<string>,rows?:list<list<string>>},questions:list<array<string,mixed>>,numbers:list<string>,by_display:bool}
 */
function examination_monitor_analytics_order_question_rows(array $section, array $questions): array
{
    $rows = is_array($section['rows'] ?? null) ? array_values($section['rows']) : [];
    $questions = array_values($questions);
    $count = min(count($rows), count($questions));
    $paired = [];
    $byDisplay = $count > 0;
    for ($index = 0; $index < $count; $index++) {
        $question = is_array($questions[$index]) ? $questions[$index] : [];
        $position = is_numeric($question['display_position'] ?? null) ? (int)$question['display_position'] : 0;
        if ($position <= 0) {
            $byDisplay = false;
        }
        $paired[] = [
            'row' => is_array($rows[$index]) ? $rows[$index] : [],
            'question' => $question,
            'position' => $position,
            'id' => (int)($question['question_id'] ?? 0),
        ];
    }
    if ($byDisplay) {
        usort($paired, static function (array $left, array $right): int {
            return $left['position'] <=> $right['position'] ?: $left['id'] <=> $right['id'];
        });
    }
    $orderedRows = [];
    $orderedQuestions = [];
    $numbers = [];
    $sequence = 1;
    foreach ($paired as $item) {
        $orderedRows[] = $item['row'];
        $orderedQuestions[] = $item['question'];
        $numbers[] = $byDisplay ? (string)$item['position'] : (string)$sequence;
        $sequence++;
    }
    $section['rows'] = $orderedRows;

    return ['section' => $section, 'questions' => $orderedQuestions, 'numbers' => $numbers, 'by_display' => $byDisplay];
}

/**
 * @param array{title?:string,hint?:string,headers?:list<string>,rows?:list<list<string>>} $section
 * @param list<string> $numbers
 * @return array{title:string,hint:string,headers:list<string>,rows:list<list<string>>,bands:list<string>}
 */
function examination_monitor_analytics_lecturer_questions(array $section, array $numbers = [], string $numberHeader = 'Question No.'): array
{
    $headers = is_array($section['headers'] ?? null) ? array_values($section['headers']) : [];
    $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
    $index = [];
    foreach ($headers as $position => $header) {
        $index[(string)$header] = $position;
    }
    $excludedTotal = 0;
    if (isset($index['Excluded from correctness'])) {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $excludedTotal += (int)($row[$index['Excluded from correctness']] ?? 0);
            }
        }
    }
    $order = ['Topic', 'Question', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'A', 'B', 'C', 'D', 'Other'];
    $rename = [
        'Answered (n)' => 'Answered',
        'Stored correct' => 'Correct',
        'Stored incorrect' => 'Incorrect',
        'A' => 'Choice A',
        'B' => 'Choice B',
        'C' => 'Choice C',
        'D' => 'Choice D',
        'Other' => 'Other choice',
    ];
    $newHeaders = [$numberHeader];
    foreach ($order as $old) {
        if (isset($index[$old])) {
            $newHeaders[] = $rename[$old] ?? $old;
        }
    }
    $newRows = [];
    $number = 1;
    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = array_values($row);
        $built = [(string)($numbers[$rowIndex] ?? $number)];
        foreach ($order as $old) {
            if (!isset($index[$old])) {
                continue;
            }
            $built[] = (string)($line[$index[$old]] ?? '');
        }
        $newRows[] = $built;
        $number++;
    }
    $hint = (string)($section['hint'] ?? '');
    if ($excludedTotal > 0) {
        $itemWord = $excludedTotal === 1 ? 'item had' : 'items had';
        $hint = trim($hint . ' ' . $excludedTotal . ' answered ' . $itemWord . ' a missing or invalid scoring flag and ' . ($excludedTotal === 1 ? 'is' : 'are') . ' not counted as incorrect.');
    }

    return [
        'title' => (string)($section['title'] ?? ''),
        'hint' => $hint,
        'headers' => $newHeaders,
        'rows' => $newRows,
        'bands' => [],
    ];
}

/**
 * @param array<string,mixed> $report
 */
function examination_monitor_analytics_build_xlsx_bytes(string $examTitle, string $scopeLabel, array $ana, array $progressRows, array $report): string
{
    $sections = examination_monitor_analytics_export_sections($ana, $progressRows);
    $examinees = is_array($report['examinees'] ?? null) ? $report['examinees'] : [];
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $charts = is_array($report['charts'] ?? null) ? $report['charts'] : [];
    $rankings = is_array($report['rankings'] ?? null) ? $report['rankings'] : [];
    $difficulty = is_array($report['difficulty'] ?? null) ? $report['difficulty'] : [];
    $insights = is_array($report['insights'] ?? null) ? $report['insights'] : [];
    $sectionLabels = [];
    foreach ($examinees as $row) {
        if (is_array($row)) {
            $sectionLabels[] = (string)($row['section'] ?? '');
        }
    }
    foreach (['top', 'low'] as $listKey) {
        foreach (is_array($rankings[$listKey] ?? null) ? $rankings[$listKey] : [] as $row) {
            if (is_array($row)) {
                $sectionLabels[] = (string)($row['section'] ?? '');
            }
        }
    }
    foreach (is_array($insights['top_students'] ?? null) ? $insights['top_students'] : [] as $row) {
        if (is_array($row)) {
            $sectionLabels[] = (string)($row['section'] ?? '');
        }
    }
    foreach ([4, 5] as $sectionIndex) {
        $headers = is_array($sections[$sectionIndex]['headers'] ?? null) ? $sections[$sectionIndex]['headers'] : [];
        $column = array_search('Section', $headers, true);
        if ($column === false) {
            continue;
        }
        foreach (is_array($sections[$sectionIndex]['rows'] ?? null) ? $sections[$sectionIndex]['rows'] : [] as $row) {
            if (is_array($row)) {
                $sectionLabels[] = (string)($row[$column] ?? '');
            }
        }
    }
    foreach (is_array($charts['sections']['labels'] ?? null) ? $charts['sections']['labels'] : [] as $label) {
        $sectionLabels[] = (string)$label;
    }
    $sectionMap = examination_monitor_analytics_section_display_map($sectionLabels);
    foreach ($examinees as $index => $row) {
        if (is_array($row)) {
            $examinees[$index]['section'] = examination_monitor_analytics_section_display((string)($row['section'] ?? ''), $sectionMap);
        }
    }
    foreach (['top', 'low'] as $listKey) {
        if (!is_array($rankings[$listKey] ?? null)) {
            continue;
        }
        foreach ($rankings[$listKey] as $index => $row) {
            if (is_array($row)) {
                $rankings[$listKey][$index]['section'] = examination_monitor_analytics_section_display((string)($row['section'] ?? ''), $sectionMap);
            }
        }
    }
    if (is_array($insights['top_students'] ?? null)) {
        foreach ($insights['top_students'] as $index => $row) {
            if (is_array($row)) {
                $insights['top_students'][$index]['section'] = examination_monitor_analytics_section_display((string)($row['section'] ?? ''), $sectionMap);
            }
        }
    }
    if (is_array($charts['sections'] ?? null)) {
        $charts['sections'] = examination_monitor_analytics_present_section_chart($charts['sections'], is_array($report['examinees'] ?? null) ? $report['examinees'] : [], $sectionMap);
    }
    foreach (examination_monitor_analytics_descriptive_charts($ana, $examinees) as $chartKey => $chartSpec) {
        $charts[$chartKey] = $chartSpec;
    }
    if (!empty($charts['topics']['included']) && is_array($charts['topic_volume'] ?? null)) {
        $charts['topic_volume']['included'] = false;
    }
    $rosterLast = max(5, 4 + count($examinees));

    $subjectNames = examination_monitor_analytics_subject_names($ana);
    $chartPack = examination_monitor_analytics_charts_sheet($charts, $examTitle, $scopeLabel);
    $subjectLine = count($subjectNames) === 1 ? $subjectNames[0] : (count($subjectNames) > 1 ? count($subjectNames) . ' subjects' : '');
    $scopeLine = $examTitle . ' · ' . $scopeLabel;
    $topicLine = $scopeLine . (count($subjectNames) === 1 ? ' · ' . $subjectNames[0] : '');
    $storedBrief = examination_monitor_analytics_concise_stored_note();
    $classColorNote = ' Class priority uses saved accuracy only when a group has at least 5 answered items from at least 3 examinees. Critical is below 40%. Needs Improvement is 40% to below 50%. Developing is 50% to below 70%. Good is 70% or above. A smaller sample is marked Insufficient Sample and is not a class conclusion. These bands do not change the official pass or fail result.';
    $sections[1]['hint'] = $storedBrief . $classColorNote;
    $sections[2]['hint'] = 'Topic names come from the saved attempt. Qualified topics are sorted from the lowest recorded correct rate to the highest. Unassigned topics stay labeled Unassigned. ' . $storedBrief . $classColorNote;
    $sections[3]['hint'] = 'Question text is shortened to 110 characters. Question no. follows the saved examination display position when every attempt uses the same position. Otherwise it follows this report list. It is not a database id. Choice A, Choice B, Choice C, Choice D, and Other choice count selected responses. They are not correct answers and are not verified difficulty. ' . $storedBrief;
    $sections[4]['hint'] = 'Each row is one examinee and subject. Colors use diagnostic bands: Critical below 40%, Needs Improvement from 40% to below 50%, Developing from 50% to below 70%, and Good at 70% or above. They are not the official examination result and not a class conclusion. ' . $storedBrief;
    $sections[5]['hint'] = 'Each row is one examinee and topic, with that examinee\'s weakest saved topics first. Critical is below 40%, Needs Improvement is 40% to below 50%, Developing is 50% to below 70%, and Good is 70% or above. These labels are not the official examination result and not a class conclusion. Unavailable means no saved rate, and it is not a score of zero. Unassigned topics stay labeled Unassigned. ' . $storedBrief;
    $metricRename = [
        'Presented' => 'Questions in Exam',
        'Answered (n)' => 'Answered',
        'Stored correct' => 'Correct Answers',
        'Stored incorrect' => 'Incorrect Answers',
        'Unanswered' => 'Unanswered',
        'Stored accuracy' => 'Recorded Correct Rate',
        'Examinees' => 'Examinees Represented',
    ];
    $topicOrder = ['Subject', 'Topic', 'Presented', 'Examinees', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Stored accuracy'];
    $subjectOrder = ['Subject', 'Presented', 'Examinees', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Stored accuracy'];
    $subjectSection = examination_monitor_analytics_attach_diagnostic(examination_monitor_analytics_lecturer_columns($sections[1], $subjectOrder, $metricRename, true), false);
    $topicSection = examination_monitor_analytics_relabel_section_column($sections[2], $sectionMap);
    $topicPresented = examination_monitor_analytics_attach_diagnostic(examination_monitor_analytics_lecturer_columns($topicSection, $topicOrder, $metricRename, true), false);
    $questionOrder = examination_monitor_analytics_order_question_rows($sections[3], is_array($ana['questions'] ?? null) ? $ana['questions'] : []);
    $questionSection = examination_monitor_analytics_questions_for_workbook($questionOrder['section'], $questionOrder['questions'], $difficulty);
    $questionLine = $scopeLine;
    if (count($subjectNames) === 1) {
        $questionSection = examination_monitor_analytics_drop_sheet_column($questionSection, 'Subject');
        $questionLine .= ' · ' . $subjectNames[0];
    }
    $questionNumbers = is_array($questionOrder['numbers'] ?? null) ? $questionOrder['numbers'] : [];
    $questionNumberHeader = 'Question No.';
    $questionNumberSentence = 'Question no. is not a database id.';
    if ($questionNumbers !== [] && !empty($questionOrder['by_display'])) {
        $questionNumberSentence = 'Question no. is the original display position saved on the examination attempt. Every submitted attempt used that same position. It is not a database id.';
    } elseif ($questionNumbers !== []) {
        $questionNumberHeader = 'Report No.';
        $questionNumberSentence = 'Report no. is this report\'s list sequence. The original display position is not shown because submitted attempts do not share one saved position. It is not a database id.';
    }
    $questionSection['hint'] = str_replace(
        'Question no. follows the saved examination display position when every attempt uses the same position. Otherwise it follows this report list. It is not a database id.',
        $questionNumberSentence,
        (string)($questionSection['hint'] ?? '')
    );
    $questionPresented = examination_monitor_analytics_lecturer_questions($questionSection, $questionNumbers, $questionNumberHeader);
    $personRename = [
        'Examinee' => 'Examinee Name',
        'Section' => 'Section',
        'Subject' => 'Subject',
        'Topic' => 'Topic',
        'Presented' => 'Questions',
        'Answered (n)' => 'Answered',
        'Stored correct' => 'Correct',
        'Stored incorrect' => 'Incorrect',
        'Unanswered' => 'Unanswered',
        'Stored accuracy' => 'Recorded Correct Rate',
    ];
    $examineeSubjectSection = examination_monitor_analytics_relabel_section_column($sections[4], $sectionMap);
    $examineeTopicSection = examination_monitor_analytics_relabel_section_column($sections[5], $sectionMap);
    if (count($subjectNames) === 1) {
        $examineeTopicSection = examination_monitor_analytics_drop_sheet_column($examineeTopicSection, 'Subject');
    }
    $examineeSubjectPresented = examination_monitor_analytics_lecturer_columns($examineeSubjectSection, ['Examinee', 'Section', 'Subject', 'Presented', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Stored accuracy'], $personRename, false);
    $examineeTopicPresented = examination_monitor_analytics_attach_diagnostic(examination_monitor_analytics_lecturer_columns($examineeTopicSection, ['Examinee', 'Section', 'Topic', 'Presented', 'Answered (n)', 'Stored correct', 'Stored incorrect', 'Unanswered', 'Stored accuracy'], $personRename, false), true);
    $summaryPack = examination_monitor_analytics_summary_sheet($examTitle, $scopeLabel, $summary, $rosterLast, $chartPack['by_key'], $charts, $insights, $chartPack['rows'], count($subjectNames), $subjectLine, $examinees, $topicPresented);
    $gradedCount = 0;
    foreach ($examinees as $row) {
        if (is_array($row) && !empty($row['graded'])) {
            $gradedCount++;
        }
    }
    $named = [
        'Summary' => $summaryPack['sheet'],
        'Examinee Results' => examination_monitor_analytics_roster_sheet($examTitle, $scopeLabel, $examinees, examination_monitor_analytics_examinee_item_counts($ana)),
    ];
    if (count($subjectNames) > 1) {
        $named['Subjects'] = examination_monitor_analytics_xlsx_sheet('Subjects · ' . $examTitle, (string)$subjectSection['hint'], $subjectSection['headers'], $subjectSection['rows'], $scopeLine, $subjectSection['bands']);
    }
    $named['Topic Results'] = examination_monitor_analytics_xlsx_sheet('Topic Results · ' . $examTitle, (string)$topicPresented['hint'], $topicPresented['headers'], $topicPresented['rows'], $topicLine, $topicPresented['bands']);
    $named['Question Analysis'] = examination_monitor_analytics_xlsx_sheet('Question Analysis · ' . $examTitle, (string)$questionPresented['hint'], $questionPresented['headers'], $questionPresented['rows'], $questionLine, $questionPresented['bands']);
    if (count($subjectNames) > 1) {
        $named['Examinee Subjects'] = examination_monitor_analytics_xlsx_sheet('Examinee Subjects · ' . $examTitle, (string)$examineeSubjectPresented['hint'], $examineeSubjectPresented['headers'], $examineeSubjectPresented['rows'], $scopeLine, $examineeSubjectPresented['bands']);
    }
    $named['Examinee Topic Details'] = examination_monitor_analytics_xlsx_sheet('Examinee Topic Details · ' . $examTitle, (string)$examineeTopicPresented['hint'], $examineeTopicPresented['headers'], $examineeTopicPresented['rows'], $topicLine, $examineeTopicPresented['bands'], examination_monitor_analytics_topic_heatmap($examineeTopicPresented));
    if ($gradedCount >= 5) {
        $named['Rankings'] = examination_monitor_analytics_xlsx_sheet('Rankings · ' . $examTitle, 'Tied scores share a rank. This list is shown because at least five examinees have graded results.', examination_monitor_analytics_ranking_headers(), examination_monitor_analytics_ranking_rows($rankings), $scopeLabel);
    }

    $sheetNames = array_keys($named);
    $sheetEls = [];
    $relEls = [];
    $overrideEls = [];
    $i = 0;
    foreach ($sheetNames as $name) {
        $i++;
        $rid = 'rId' . $i;
        $sheetEls[] = '<sheet name="' . ereview_xlsx_t($name) . '" sheetId="' . $i . '" r:id="' . $rid . '"/>';
        $relEls[] = '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $overrideEls[] = '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $styleRid = 'rId' . ($i + 1);
    $relEls[] = '<Relationship Id="' . $styleRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $extras = [];
    examination_monitor_analytics_attach_drawing(
        $named,
        'Summary',
        $summaryPack['charts'],
        1,
        1,
        $extras,
        $overrideEls
    );

    $stylesXml = examination_monitor_analytics_styles_xml();
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <bookViews><workbookView activeTab="0" windowWidth="24000" windowHeight="12000"/></bookViews>
  <sheets>' . implode('', $sheetEls) . '</sheets>
  <definedNames>' . examination_monitor_analytics_print_areas($named) . '</definedNames>
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
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        @unlink($tmp);

        return '';
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $sheetNumber = 0;
    foreach ($named as $xml) {
        $sheetNumber++;
        $zip->addFromString('xl/worksheets/sheet' . $sheetNumber . '.xml', $xml);
    }
    foreach ($extras as $path => $xml) {
        $zip->addFromString($path, $xml);
    }
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

/**
 * Performance insights sit below the chart anchors so B5:B18 and the chart rows stay put.
 *
 * @param array<string,mixed> $insights
 * @return array{cells:array<int,list<string>>,merges:list<string>,heights:array<int,int>,last:int}
 */
function examination_monitor_analytics_summary_insight_block(array $insights, int $startRow, bool $includeSubjects = true): array
{
    $cells = [];
    $merges = [];
    $heights = [];
    $row = $startRow;
    $put = static function (string $column, string $value, string $style, bool $number = false) use (&$cells, &$row): void {
        $ref = $column . $row;
        $cells[$row][] = $number
            ? examination_monitor_analytics_number_cell($ref, $value, $style)
            : examination_monitor_analytics_xlsx_cell($ref, $value, $style);
    };
    $span = static function (string $range) use (&$merges): void {
        $merges[] = $range;
    };
    $heading = static function (string $label) use (&$row, &$heights, $put, $span): void {
        $put('A', $label, '27');
        $span('A' . $row . ':I' . $row);
        $heights[$row] = 22;
        $row++;
    };
    $paragraph = static function (string $label, string $style) use (&$row, &$heights, $put, $span): void {
        $put('A', $label, $style);
        $span('A' . $row . ':I' . $row);
        $length = strlen($label);
        $heights[$row] = $length > 240 ? 36 : ($length > 140 ? 28 : 20);
        $row++;
    };
    $gap = static function () use (&$row, &$heights, $put, $span): void {
        $put('A', '', '3');
        $span('A' . $row . ':I' . $row);
        $heights[$row] = 6;
        $row++;
    };
    $groupHeader = static function (string $first) use (&$row, &$heights, $put, $span): void {
        $put('A', $first, '4');
        $span('A' . $row . ':C' . $row);
        $put('D', 'Recorded Correct Rate', '4');
        $put('E', 'Answered items', '4');
        $put('F', 'Examinees', '4');
        $put('G', 'Note', '4');
        $span('G' . $row . ':I' . $row);
        $heights[$row] = 22;
        $row++;
    };
    $groupRow = static function (array $item) use (&$row, &$heights, $put, $span): void {
        $name = (string)($item['name'] ?? '');
        $put('A', $name, '5');
        $span('A' . $row . ':C' . $row);
        $put('D', number_format((float)($item['accuracy'] ?? 0), 1), '33', true);
        $put('E', (string)(int)($item['answered'] ?? 0), '29', true);
        if (!empty($item['examinees_known']) && isset($item['examinees']) && is_numeric($item['examinees'])) {
            $put('F', (string)(int)$item['examinees'], '29', true);
        } else {
            $put('F', '—', '5');
        }
        $note = (string)($item['note'] ?? '');
        if ($note === 'Limited sample') {
            $note = 'Not enough responses for a class conclusion.';
        }
        $put('G', $note, '5');
        $span('G' . $row . ':I' . $row);
        $heights[$row] = strlen($name) > 90 ? 36 : 20;
        $row++;
    };

    $heading('Performance insights');
    $paragraph(examination_monitor_analytics_concise_stored_note(), '3');
    $gap();
    $heading('Top Graded Students');
    $paragraph((string)($insights['top_note'] ?? 'No graded students are available for a ranking.'), '3');
    $students = is_array($insights['top_students'] ?? null) ? $insights['top_students'] : [];
    if ($students === []) {
        $paragraph('No graded students are available for a ranking.', '5');
    } else {
        $put('A', 'Rank', '4');
        $put('B', 'Student', '4');
        $span('B' . $row . ':D' . $row);
        $put('E', 'Section', '4');
        $put('F', 'Exam score', '4');
        $put('G', 'Correct', '4');
        $put('H', 'Items', '4');
        $heights[$row] = 22;
        $row++;
        foreach ($students as $student) {
            if (!is_array($student)) {
                continue;
            }
            $name = (string)($student['full_name'] ?? '');
            $put('A', (string)(int)($student['rank'] ?? 0), '29', true);
            $put('B', $name, '5');
            $span('B' . $row . ':D' . $row);
            $put('E', (string)($student['section'] ?? ''), '5');
            $put('F', number_format((float)($student['percent'] ?? 0), 1), '33', true);
            $put('G', (string)(int)($student['correct'] ?? 0), '29', true);
            $put('H', (string)(int)($student['total'] ?? 0), '29', true);
            $heights[$row] = strlen($name) > 90 ? 36 : 20;
            $row++;
        }
    }
    $gap();
    if ($includeSubjects) {
        $heading('Subject Insights');
        $paragraph((string)($insights['subject_message'] ?? 'Subject comparisons are not available for this examination.'), '3');
        $subjectRows = is_array($insights['subjects'] ?? null) ? $insights['subjects'] : [];
        if (!empty($insights['subjects_available']) && $subjectRows !== []) {
            $groupHeader('Subject');
            foreach ($subjectRows as $item) {
                if (is_array($item)) {
                    $groupRow($item);
                }
            }
        }
        $gap();
    }
    $heading('Topic Insights');
    $paragraph((string)($insights['topic_message'] ?? 'Topic comparisons are not available for this examination.'), '3');
    if (!empty($insights['topics_available'])) {
        $low = is_array($insights['topics_low'] ?? null) ? $insights['topics_low'] : [];
        $high = is_array($insights['topics_high'] ?? null) ? $insights['topics_high'] : [];
        $heading('Lowest recorded correct rate');
        if ($low === []) {
            $paragraph('No separate lowest list is available.', '5');
        } else {
            $groupHeader('Topic');
            foreach ($low as $item) {
                if (is_array($item)) {
                    $groupRow($item);
                }
            }
        }
        $heading('Highest recorded correct rate');
        if ($high === []) {
            $paragraph('No separate highest list is available.', '5');
        } else {
            $groupHeader('Topic');
            foreach ($high as $item) {
                if (is_array($item)) {
                    $groupRow($item);
                }
            }
        }
    }
    $gap();
    $heading('Observations');
    $observations = is_array($insights['observations'] ?? null) ? $insights['observations'] : [];
    $observationText = $observations === [] ? 'No observations are available from this report.' : implode("\n", array_map('strval', $observations));
    $put('A', $observationText, '28');
    $span('A' . $row . ':I' . $row);
    $heights[$row] = min(64, 18 + (14 * max(1, count($observations))));
    $last = $row;

    return [
        'cells' => $cells,
        'merges' => $merges,
        'heights' => $heights,
        'last' => $last,
    ];
}

/**
 * Official counts stay in AB5:AB18. The visible dashboard repeats them.
 *
 * @param array<string,mixed> $summary
 * @param array<string,array<string,mixed>> $chartRanges
 * @param array<string,mixed> $chartPlan
 * @param array<string,mixed> $insights
 * @param array{headers?:list<string>,rows?:list<list<string>>,bands?:list<string>} $topicSection
 * @return array{sheet:string,charts:list<array<string,mixed>>}
 */
function examination_monitor_analytics_summary_sheet(string $examTitle, string $scopeLabel, array $summary, int $rosterLast, array $chartRanges = [], array $chartPlan = [], array $insights = [], array $chartRows = [], int $subjectCount = 0, string $subjectLabel = '', array $examinees = [], array $topicSection = []): array
{
    $sheetRef = "'Examinee Results'";
    $range = $sheetRef . '!E5:E' . $rosterLast;
    $scoreRange = $sheetRef . '!J5:J' . $rosterLast;
    $cells = [];
    $heights = [];
    $merges = [];
    $add = static function (string $ref, string $xml) use (&$cells): void {
        $row = (int)preg_replace('/\D/', '', $ref);
        $cells[$row][] = $xml;
    };
    $merge = static function (string $span) use (&$merges): void {
        $merges[] = $span;
    };
    $text = static function (string $ref, string $value, string $style) use ($add): void {
        $add($ref, examination_monitor_analytics_xlsx_cell($ref, $value, $style));
    };
    $formula = static function (string $ref, string $value, string $style) use ($add): void {
        $add($ref, examination_monitor_analytics_formula_cell($ref, $value, $style));
    };
    $pair = static function (string $start, string $end, int $row, string $label, string $source) use ($text, $formula, $merge): void {
        $text($start . $row, $label, '34');
        $merge($start . $row . ':' . $end . $row);
        $formula($start . ($row + 1), $source, '35');
        $merge($start . ($row + 1) . ':' . $end . ($row + 1));
    };

    $text('A1', 'COLLEGE EXAMINATION | PERFORMANCE DASHBOARD', '1');
    $merge('A1:I1');
    $text('A2', $examTitle, '2');
    $merge('A2:I2');
    $scopeLine = trim($subjectLabel . ($subjectLabel !== '' ? ' · ' : '') . $scopeLabel);
    $text('A3', $scopeLine . ' · Diagnostic examination results', '3');
    $merge('A3:I3');
    $warningLines = explode("\n", examination_monitor_analytics_unfinalized_warning());
    $formula('A4', 'IF(AB9>0,"' . $warningLines[0] . '"&CHAR(10)&"' . $warningLines[1] . '","")', '3');
    $merge('A4:I4');
    $heights[1] = 34;
    $heights[2] = 22;
    $heights[3] = 18;
    $heights[4] = 32;

    $metrics = [
        5 => ['Assigned', 'COUNTA(' . $range . ')', '10'],
        6 => ['Passed', 'COUNTIF(' . $range . ',"Passed")', '10'],
        7 => ['Failed', 'COUNTIF(' . $range . ',"Failed")', '10'],
        8 => ['Submitted, not graded', 'COUNTIF(' . $range . ',"Submitted, not graded")', '10'],
        9 => ['In progress', 'COUNTIF(' . $range . ',"In progress")', '10'],
        10 => ['Expired', 'COUNTIF(' . $range . ',"Expired")', '10'],
        11 => ['Absent', 'COUNTIF(' . $range . ',"Absent")', '10'],
        12 => ['Reconciliation', 'AB5-(AB6+AB7+AB8+AB9+AB10+AB11)', '10'],
        14 => ['Completion rate', 'IF(AB5=0,"",(AB6+AB7+AB8)/AB5)', '11'],
        15 => ['Passing Rate', 'IF((AB6+AB7)=0,"—",IF((AB6+AB7)<5,"Not reported",TEXT(AB6/(AB6+AB7),"0.0%")&" ("&AB6&"/"&(AB6+AB7)&")"))', '10'],
        16 => ['IF((AB6+AB7)<5,"","Average Exam Score")', 'IF((AB6+AB7)<5,"",IF(COUNT(' . $scoreRange . ')=0,"",ROUND(AVERAGE(' . $scoreRange . '),1)))', '12'],
        17 => ['IF((AB6+AB7)<5,"","Highest Exam Score")', 'IF((AB6+AB7)<5,"",IF(COUNT(' . $scoreRange . ')=0,"",MAX(' . $scoreRange . ')))', '12'],
        18 => ['IF((AB6+AB7)<5,"","Lowest Exam Score")', 'IF((AB6+AB7)<5,"",IF(COUNT(' . $scoreRange . ')=0,"",MIN(' . $scoreRange . ')))', '12'],
    ];
    foreach ($metrics as $rowNumber => $metric) {
        if ($rowNumber >= 16) {
            $formula('AA' . $rowNumber, $metric[0], '9');
        } else {
            $text('AA' . $rowNumber, $metric[0], '9');
        }
        $formula('AB' . $rowNumber, $metric[1], $metric[2]);
        $heights[$rowNumber] = $heights[$rowNumber] ?? 20;
    }

    $topicHeaders = is_array($topicSection['headers'] ?? null) ? $topicSection['headers'] : [];
    $topicIndex = [];
    foreach ($topicHeaders as $position => $header) {
        $topicIndex[(string)$header] = $position;
    }
    $topicRows = [];
    $topicBands = is_array($topicSection['bands'] ?? null) ? array_values($topicSection['bands']) : [];
    foreach (is_array($topicSection['rows'] ?? null) ? $topicSection['rows'] : [] as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = static function (string $header) use ($topicIndex, $row): string {
            return isset($topicIndex[$header]) ? (string)($row[$topicIndex[$header]] ?? '') : '';
        };
        $rate = null;
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $value('Recorded Correct Rate'), $rateMatch) === 1) {
            $rate = ((float)$rateMatch[1]) / 100;
        }
        $band = (string)($topicBands[$index] ?? '');
        $topicRows[] = [
            'subject' => $value('Subject'),
            'topic' => $value('Topic'),
            'correct' => $value('Correct Answers'),
            'incorrect' => $value('Incorrect Answers'),
            'answered' => $value('Answered'),
            'rate' => $rate,
            'priority' => $value('Priority'),
            'focus' => $value("Lecturer's Focus"),
            'band' => $band,
        ];
    }
    $qualifiedCount = 0;
    foreach ($topicRows as $topicRow) {
        if ($topicRow['band'] !== '') {
            $qualifiedCount++;
        }
    }
    $topicStart = 24;
    $topicEnd = $topicRows === [] ? $topicStart : ($topicStart + count($topicRows) - 1);
    $priorityRange = '$H$' . $topicStart . ':$H$' . $topicEnd;
    $correctRange = '$D$' . $topicStart . ':$D$' . $topicEnd;
    $answeredRange = '$F$' . $topicStart . ':$F$' . $topicEnd;
    $accuracyRange = '$G$' . $topicStart . ':$G$' . $topicEnd;
    $overallFormula = $qualifiedCount === 0
        ? '"Not reported"'
        : 'IF(SUMIF(' . $priorityRange . ',"<>Insufficient Sample",' . $answeredRange . ')=0,"Not reported",TEXT(SUMPRODUCT((' . $priorityRange . '<>"Insufficient Sample")*(ISNUMBER(' . $accuracyRange . '))*(' . $accuracyRange . ')*(' . $answeredRange . '))/SUMIF(' . $priorityRange . ',"<>Insufficient Sample",' . $answeredRange . '),"0.0%"))';

    $heights[5] = 8;
    $pair('A', 'B', 6, 'Students Assigned', 'AB5');
    $pair('C', 'D', 6, 'Students Submitted', 'AB6+AB7+AB8');
    $pair('E', 'F', 6, 'Overall Topic Accuracy', $overallFormula);
    $pair('G', 'H', 6, 'Passed', 'AB6');
    $heights[6] = 18;
    $heights[7] = 34;
    $pair('A', 'B', 9, 'Failed', 'AB7');
    $pair('C', 'D', 9, 'Completion Rate', 'IF(AB5=0,"—",(AB6+AB7+AB8)&" of "&AB5&" submitted ("&TEXT(AB14,"0.0%")&")")');
    $pair('E', 'F', 9, 'Passing Rate', 'AB15');
    $pair('G', 'H', 9, 'Average Exam Score', 'IF((AB6+AB7)<5,"Not reported",TEXT(AB16,"0.0")&"%")');
    $heights[9] = 18;
    $heights[10] = 34;
    $formula('A11', 'IF((AB6+AB7)<5,"Pass rate is not displayed because fewer than five examinees have graded results. Highest and lowest exam scores are not reported for the same reason.","Highest "&TEXT(AB17,"0.0")&"% · Lowest "&TEXT(AB18,"0.0")&"%")', '3');
    $merge('A11:I11');
    $heights[11] = 28;
    $text('A12', 'Submitted, not graded', '9');
    $formula('B12', 'AB8', '10');
    $text('C12', 'In progress', '9');
    $formula('D12', 'IF(ISNUMBER(AB9),AB9,0)', '10');
    $text('E12', 'Expired', '9');
    $formula('F12', 'IF(ISNUMBER(AB10),AB10,0)', '10');
    $text('G12', 'Absent', '9');
    $formula('H12', 'AB11', '10');
    $heights[12] = 20;

    $text('A14', "LECTURER'S QUICK READ", '1');
    $merge('A14:I14');
    $heights[14] = 24;
    $text('A15', 'Priority', '4');
    $text('B15', 'No. of Topics', '4');
    $text('C15', 'What to Do', '4');
    $merge('C15:I15');
    $heights[15] = 22;
    $quickReads = [
        16 => ['CRITICAL (<40%)', 'CRITICAL', 'Prioritize these topics for focused re-teaching and guided practice.', '36'],
        17 => ['NEEDS IMPROVEMENT (40–49.9%)', 'NEEDS IMPROVEMENT', 'Schedule additional discussion, examples, and practice questions.', '37'],
        18 => ['DEVELOPING (50–69.9%)', 'DEVELOPING', 'Reinforce concepts and monitor through short assessments.', '38'],
        19 => ['GOOD (70%+)', 'GOOD', 'Maintain current understanding through review and recall activities.', '39'],
    ];
    foreach ($quickReads as $rowNumber => $item) {
        $text('A' . $rowNumber, $item[0], $item[3]);
        $formula('B' . $rowNumber, $topicRows === [] ? '0' : 'COUNTIF(' . $priorityRange . ',"' . $item[1] . '")', '29');
        $text('C' . $rowNumber, $item[2], '5');
        $merge('C' . $rowNumber . ':I' . $rowNumber);
        $heights[$rowNumber] = 28;
    }
    $classNote = $qualifiedCount === 0
        ? 'The available data is insufficient for a class-level conclusion. Topics below keep their saved counts and are marked Insufficient Sample.'
        : 'Only topics with at least 5 answered items from at least 3 examinees are counted above. These bands do not change the official pass or fail result.';
    $text('A20', $classNote, '3');
    $merge('A20:I20');
    $heights[20] = 28;

    $text('A22', 'TOPIC PERFORMANCE | SORTED FROM WEAKEST TO STRONGEST', '1');
    $merge('A22:I22');
    $heights[22] = 24;
    foreach (['Rank', 'Subject', 'Topic', 'Correct', 'Incorrect', 'Answered', 'Accuracy', 'Priority', "Lecturer's Focus"] as $column => $label) {
        $text(ereview_xlsx_col_letter($column) . '23', $label, '4');
    }
    $heights[23] = 24;
    $colorFor = ['C' => 'B91C1C', 'N' => 'C2410C', 'D' => 'B45309', 'G' => '15803D'];
    $chartColors = [];
    if ($topicRows === []) {
        $text('A24', 'No topic rows were saved for this scope.', '5');
        $merge('A24:I24');
        $heights[24] = 22;
    } else {
        $rank = 0;
        foreach ($topicRows as $offset => $topicRow) {
            $line = $topicStart + $offset;
            $qualified = $topicRow['band'] !== '';
            if ($qualified) {
                $rank++;
                $formula('A' . $line, (string)$rank, '29');
                $chartColors[] = $colorFor[$topicRow['band']] ?? '64748B';
            } else {
                $text('A' . $line, '', '5');
            }
            $text('B' . $line, $topicRow['subject'], '5');
            $text('C' . $line, $topicRow['topic'], '5');
            $formula('D' . $line, $topicRow['correct'] === '' ? '0' : (string)(int)$topicRow['correct'], '29');
            $formula('E' . $line, $topicRow['incorrect'] === '' ? '0' : (string)(int)$topicRow['incorrect'], '29');
            $formula('F' . $line, $topicRow['answered'] === '' ? '0' : (string)(int)$topicRow['answered'], '29');
            if ($topicRow['rate'] === null) {
                $text('G' . $line, '—', '5');
            } else {
                $formula('G' . $line, rtrim(rtrim(sprintf('%.6F', $topicRow['rate']), '0'), '.'), '41');
            }
            $text('H' . $line, $topicRow['priority'], '5');
            $text('I' . $line, $topicRow['focus'], '5');
            $heights[$line] = 30;
        }
    }

    $chartCount = min(18, $qualifiedCount);
    $summaryCharts = [];
    $cursor = $topicEnd + 2;
    if ($chartCount > 0) {
        $text('A' . $cursor, 'Topic Accuracy (%) — Lowest to Highest', '27');
        $merge('A' . $cursor . ':I' . $cursor);
        $heights[$cursor] = 22;
        $cursor++;
        $chartHeight = max(14, $chartCount * 2 + 2);
        $summaryCharts[] = [
            'title' => 'Topic Accuracy (%) — Lowest to Highest',
            'cat' => 'Summary!$C$' . $topicStart . ':$C$' . ($topicStart + $chartCount - 1),
            'val' => 'Summary!$G$' . $topicStart . ':$G$' . ($topicStart + $chartCount - 1),
            'kind' => 'bar',
            'colors' => array_slice($chartColors, 0, $chartCount),
            'percent' => true,
            'from_row' => $cursor - 1,
            'to_row' => $cursor - 1 + $chartHeight,
            'from_col' => 0,
            'to_col' => 9,
        ];
        for ($blank = $cursor; $blank < $cursor + $chartHeight; $blank++) {
            $text('A' . $blank, '', '3');
            $heights[$blank] = 18;
        }
        $cursor += $chartHeight + 1;
        if ($qualifiedCount > $chartCount) {
            $text('A' . $cursor, 'The chart shows the 18 lowest qualified topics. The table lists every topic.', '3');
            $merge('A' . $cursor . ':I' . $cursor);
            $heights[$cursor] = 20;
            $cursor += 2;
        }
    } else {
        $text('A' . $cursor, 'Topic Accuracy (%) — Lowest to Highest is omitted because no topic meets the class sample.', '3');
        $merge('A' . $cursor . ':I' . $cursor);
        $heights[$cursor] = 20;
        $cursor += 2;
    }

    $text('A' . $cursor, 'Individual Examination Results', '1');
    $merge('A' . $cursor . ':I' . $cursor);
    $heights[$cursor] = 24;
    $cursor++;
    foreach (['Examinee Name', 'Section', 'Examination Status', 'Correct Answers', 'Incorrect Answers', 'Unanswered', 'Total Questions', 'Exam Score', 'Result'] as $column => $label) {
        $text(ereview_xlsx_col_letter($column) . $cursor, $label, '4');
    }
    $heights[$cursor] = 24;
    $headerRow = $cursor;
    $cursor++;
    $tableFirst = 0;
    $tableLast = $headerRow;
    if ($examinees === []) {
        $text('A' . $cursor, 'No examinees in this scope.', '5');
        $merge('A' . $cursor . ':I' . $cursor);
        $heights[$cursor] = 20;
        $tableLast = $cursor;
        $cursor++;
    } else {
        $tableFirst = $cursor;
        foreach ($examinees as $offset => $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = 5 + ($cursor - $tableFirst);
            $shown = static function (string $column) use ($sheetRef, $source): string {
                $ref = $sheetRef . '!' . $column . $source;

                return 'IF(' . $ref . '="","",' . $ref . ')';
            };
            $formula('A' . $cursor, $sheetRef . '!C' . $source, '5');
            $formula('B' . $cursor, $sheetRef . '!D' . $source, '5');
            $formula('C' . $cursor, $sheetRef . '!E' . $source, '5');
            $formula('D' . $cursor, $shown('F'), '5');
            $formula('E' . $cursor, $shown('G'), '5');
            $formula('F' . $cursor, $shown('H'), '5');
            $formula('G' . $cursor, $shown('I'), '5');
            $formula('H' . $cursor, 'IF(ISNUMBER(' . $sheetRef . '!J' . $source . '),' . $sheetRef . '!J' . $source . ',"")', '33');
            $formula('I' . $cursor, $shown('K'), '5');
            $heights[$cursor] = 22;
            $tableLast = $cursor;
            $cursor++;
        }
    }

    $chartKeys = ['distribution', 'scores', 'sections'];
    if ($subjectCount > 1) {
        $chartKeys[] = 'subjects';
    }
    foreach ($chartKeys as $chartKey) {
        if (!is_array($chartRanges[$chartKey] ?? null)) {
            continue;
        }
        $spec = $chartRanges[$chartKey];
        $spec['kind'] = 'bar';
        $spec['from_row'] = $cursor - 1;
        $spec['to_row'] = $cursor - 1 + 16;
        $spec['from_col'] = 0;
        $spec['to_col'] = 9;
        $summaryCharts[] = $spec;
        for ($blank = $cursor; $blank < $cursor + 16; $blank++) {
            $text('A' . $blank, '', '3');
            $heights[$blank] = 18;
        }
        $cursor += 18;
    }
    $omittedNotes = [];
    foreach (['distribution', 'scores', 'topics', 'sections', 'subjects'] as $chartKey) {
        if (isset($chartRanges[$chartKey])) {
            continue;
        }
        $note = examination_monitor_analytics_chart_omission($chartPlan, $chartKey);
        if ($subjectCount < 2) {
            $note = str_replace('Descriptive counts stay on the Subjects sheet.', 'Descriptive counts stay on the Topics and Questions sheets.', $note);
        }
        if ($note !== '' && !in_array($note, $omittedNotes, true)) {
            $omittedNotes[] = $note;
        }
    }
    if ($omittedNotes !== []) {
        $text('A' . $cursor, 'Charts that need a larger sample are omitted. Passed and failed counts, and every individual result, remain.', '3');
        $merge('A' . $cursor . ':I' . $cursor);
        $heights[$cursor] = 20;
        $cursor += 2;
    }

    $insightBlock = examination_monitor_analytics_summary_insight_block($insights, $cursor, $subjectCount > 1);
    foreach ($insightBlock['cells'] as $rowNumber => $rowCells) {
        foreach ($rowCells as $xml) {
            $cells[$rowNumber][] = $xml;
        }
    }
    foreach ($insightBlock['merges'] as $span) {
        $merges[] = $span;
    }
    foreach ($insightBlock['heights'] as $rowNumber => $height) {
        $heights[$rowNumber] = $height;
    }
    $noteRow = (int)$insightBlock['last'] + 2;
    $text('A' . $noteRow, 'Notes', '27');
    $merge('A' . $noteRow . ':I' . $noteRow);
    $heights[$noteRow] = 20;
    $bodyRow = $noteRow + 1;
    $noteLines = [
        examination_monitor_analytics_concise_stored_note(),
        'Diagnostic priority is a topic-accuracy band only. It does not change the official rule: a submitted paper passes when correct answers reach at least half the questions, rounded up.',
        'A blank answer count is not a zero. A missing or invalid scoring flag is not counted as incorrect.',
        'Every assigned examinee remains in this table and on Examinee Results, including absent, in-progress, expired, and submitted but not graded records.',
    ];
    $text('A' . $bodyRow, implode("\n", $noteLines), '28');
    $merge('A' . $bodyRow . ':I' . ($bodyRow + 2));
    $heights[$bodyRow] = 48;
    $lastRow = $bodyRow + 2;

    ksort($cells);
    $rows = [];
    foreach ($cells as $rowNumber => $rowCells) {
        usort($rowCells, static function (string $left, string $right): int {
            preg_match('/\br="([A-Z]+)/', $left, $leftMatch);
            preg_match('/\br="([A-Z]+)/', $right, $rightMatch);

            return examination_monitor_analytics_column_number($leftMatch[1] ?? 'A') <=> examination_monitor_analytics_column_number($rightMatch[1] ?? 'A');
        });
        $height = isset($heights[$rowNumber]) ? ' ht="' . $heights[$rowNumber] . '" customHeight="1"' : '';
        $rows[] = '<row r="' . $rowNumber . '"' . $height . '>' . implode('', $rowCells) . '</row>';
    }
    $chartEnd = $lastRow;
    foreach ($chartRows as $chartRow) {
        if (!is_string($chartRow) || $chartRow === '') {
            continue;
        }
        $rows[] = $chartRow;
        if (preg_match('/<row r="(\d+)"/', $chartRow, $chartMatch)) {
            $chartEnd = max($chartEnd, (int)$chartMatch[1]);
        }
    }
    $topicConditional = '';
    if ($topicRows !== []) {
        $topicConditional = '<conditionalFormatting sqref="G' . $topicStart . ':H' . $topicEnd . '">'
            . '<cfRule type="expression" dxfId="8" priority="1"><formula>$H' . $topicStart . '="CRITICAL"</formula></cfRule>'
            . '<cfRule type="expression" dxfId="11" priority="2"><formula>$H' . $topicStart . '="NEEDS IMPROVEMENT"</formula></cfRule>'
            . '<cfRule type="expression" dxfId="9" priority="3"><formula>$H' . $topicStart . '="DEVELOPING"</formula></cfRule>'
            . '<cfRule type="expression" dxfId="10" priority="4"><formula>$H' . $topicStart . '="GOOD"</formula></cfRule>'
            . '</conditionalFormatting>';
    }

    return [
        'sheet' => examination_monitor_analytics_sheet_document([
            'rows' => $rows,
            'dimension' => 'A1:AB' . $chartEnd,
            'printArea' => 'A1:I' . $lastRow,
            'cols' => [[1, 1, 28], [2, 2, 24], [3, 3, 34], [4, 6, 14], [7, 7, 16], [8, 8, 24], [9, 9, 46], [10, 16, 14], [27, 28, 3, true]],
            'merges' => $merges,
            'zoom' => 110,
            'fitWidth' => 1,
            'fitHeight' => 0,
            'tabColor' => '0F2744',
            'selected' => true,
            'conditional' => '<conditionalFormatting sqref="A4:I4"><cfRule type="expression" dxfId="7" priority="1"><formula>LEN(TRIM(A4))&gt;0</formula></cfRule></conditionalFormatting>'
                . $topicConditional
                . ($tableFirst > 0 ? examination_monitor_analytics_status_conditional('C' . $tableFirst . ':C' . $tableLast) . examination_monitor_analytics_performance_conditional('H' . $tableFirst . ':I' . $tableLast, 'I') : ''),
        ]),
        'charts' => $summaryCharts,
    ];
}

function examination_monitor_analytics_column_number(string $letters): int
{
    $number = 0;
    foreach (str_split($letters) as $letter) {
        $number = ($number * 26) + (ord($letter) - 64);
    }

    return $number;
}

/**
 * @param array{headers?:list<string>,rows?:list<list<string>>,bands?:list<string>} $section
 * @return array{title?:string,note?:string,headers?:list<string>,rows?:list<list<string>>,styles?:list<list<string>>}
 */
function examination_monitor_analytics_topic_heatmap(array $section): array
{
    $headers = is_array($section['headers'] ?? null) ? $section['headers'] : [];
    $nameAt = array_search('Examinee Name', $headers, true);
    $topicAt = array_search('Topic', $headers, true);
    $rateAt = array_search('Recorded Correct Rate', $headers, true);
    if ($nameAt === false || $topicAt === false || $rateAt === false) {
        return [];
    }
    $names = [];
    $topics = [];
    $grid = [];
    $bands = is_array($section['bands'] ?? null) ? $section['bands'] : [];
    foreach (is_array($section['rows'] ?? null) ? $section['rows'] : [] as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row[$nameAt] ?? ''));
        $topic = trim((string)($row[$topicAt] ?? ''));
        if ($name === '' || $topic === '') {
            continue;
        }
        $names[$name] = true;
        $topics[$topic] = true;
        if (!isset($grid[$name][$topic])) {
            $grid[$name][$topic] = [
                'rate' => (string)($row[$rateAt] ?? ''),
                'band' => (string)($bands[$index] ?? ''),
            ];
        }
    }
    $topicList = array_keys($topics);
    $nameList = array_keys($names);
    if ($topicList === [] || $nameList === []) {
        return [];
    }
    if (count($topicList) > 12 || count($nameList) > 30) {
        return [
            'title' => 'Student–Topic Heatmap',
            'note' => 'The detailed table lists every examinee and topic. A matrix is omitted because this scope has too many labels to stay readable. Unavailable in the table is not a score of zero.',
            'headers' => [],
            'rows' => [],
            'styles' => [],
        ];
    }
    natcasesort($topicList);
    $topicList = array_values($topicList);
    $styleFor = ['C' => '36', 'N' => '37', 'D' => '38', 'G' => '39'];
    $matrixRows = [];
    $matrixStyles = [];
    foreach ($nameList as $name) {
        $line = [$name];
        $style = ['5'];
        foreach ($topicList as $topic) {
            if (!isset($grid[$name][$topic]) || $grid[$name][$topic]['rate'] === '' || $grid[$name][$topic]['rate'] === '—') {
                $line[] = 'Unavailable';
                $style[] = '40';
                continue;
            }
            $line[] = $grid[$name][$topic]['rate'];
            $style[] = $styleFor[$grid[$name][$topic]['band']] ?? '40';
        }
        $matrixRows[] = $line;
        $matrixStyles[] = $style;
    }

    return [
        'title' => 'Student–Topic Heatmap',
        'note' => 'One cell is one examinee and one topic. Unavailable means that pair has no saved rate. A recorded 0% stays 0% and is not treated as missing. This is not the official examination result.',
        'headers' => array_merge(['Examinee Name'], $topicList),
        'rows' => $matrixRows,
        'styles' => $matrixStyles,
    ];
}

function examination_monitor_analytics_roster_sheet(string $examTitle, string $scopeLabel, array $examinees, array $itemCounts = []): string
{
    $headers = ['No.', 'Student Number', 'Examinee Name', 'Section', 'Examination Status', 'Correct Answers', 'Incorrect Answers', 'Unanswered', 'Total Questions', 'Exam Score', 'Result'];
    $excludedTotal = 0;
    foreach ($itemCounts as $count) {
        $excludedTotal += (int)($count['excluded'] ?? 0);
    }
    $unavailableRows = 0;
    $partialRows = 0;
    $dataRows = [];
    $line = 5;
    foreach ($examinees as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $style = ($line % 2 === 0) ? '6' : '5';
        $countStyle = $style === '6' ? '30' : '29';
        $percent = (isset($row['percent']) && is_numeric($row['percent'])) ? (float)$row['percent'] : null;
        $userId = (int)($row['user_id'] ?? 0);
        $items = $userId > 0 && isset($itemCounts[$userId]) ? $itemCounts[$userId] : null;
        $itemsKnown = $items !== null && (int)$items['presented'] > 0;
        $officialTotal = isset($row['total']) && $row['total'] !== null ? (int)$row['total'] : null;
        if ($itemsKnown) {
            $correct = (int)$items['correct'];
            $incorrect = (int)$items['incorrect'];
            $unanswered = (int)$items['unanswered'];
            if ($officialTotal !== null && $officialTotal > (int)$items['presented']) {
                $partialRows++;
            }
        } else {
            $correct = null;
            $incorrect = null;
            $unanswered = null;
            $unavailableRows++;
        }
        $total = $officialTotal !== null ? $officialTotal : ($itemsKnown ? (int)$items['presented'] : null);
        $countCell = static function (string $ref, ?int $value, bool $known, string $blankStyle, string $numberStyle): string {
            if (!$known) {
                return examination_monitor_analytics_xlsx_cell($ref, '', $blankStyle);
            }

            return examination_monitor_analytics_number_cell($ref, (string)$value, $numberStyle);
        };
        $cells = [
            examination_monitor_analytics_number_cell('A' . $line, (string)($index + 1), $style),
            examination_monitor_analytics_xlsx_cell('B' . $line, (string)($row['student_number'] ?? ''), $style),
            examination_monitor_analytics_xlsx_cell('C' . $line, (string)($row['full_name'] ?? ''), $style),
            examination_monitor_analytics_xlsx_cell('D' . $line, (string)($row['section'] ?? ''), $style),
            examination_monitor_analytics_xlsx_cell('E' . $line, (string)($row['category'] ?? ''), $style),
            $countCell('F' . $line, $correct, $itemsKnown, $style, $countStyle),
            $countCell('G' . $line, $incorrect, $itemsKnown, $style, $countStyle),
            $countCell('H' . $line, $unanswered, $itemsKnown, $style, $countStyle),
            $total === null
                ? examination_monitor_analytics_xlsx_cell('I' . $line, '', $style)
                : examination_monitor_analytics_number_cell('I' . $line, (string)$total, $countStyle),
            $percent === null
                ? examination_monitor_analytics_xlsx_cell('J' . $line, '', $style)
                : examination_monitor_analytics_number_cell('J' . $line, (string)$percent, '33'),
            examination_monitor_analytics_xlsx_cell('K' . $line, examination_monitor_analytics_result_label($row), $style),
        ];
        $dataRows[] = '<row r="' . $line . '" ht="24" customHeight="1">' . implode('', $cells) . '</row>';
        $line++;
    }
    $hint = 'One row for every assigned examinee. Exam Score and Result use the official rule: a submitted paper passes when correct answers reach at least half the questions, rounded up. Yellow means passed and below 75%. Green means 75% or above. Correct Answers, Incorrect Answers, and Unanswered come only from saved item rows. A blank count means those rows were not found. It is not a score of zero. The official score is not split into those three counts, and the current question bank is not used to reconstruct them. A partial row counts only the saved items. Questions without a saved row are not counted as incorrect. A missing or invalid scoring flag is not counted as incorrect.';
    if ($unavailableRows > 0) {
        $hint .= ' ' . $unavailableRows . ' examinee' . ($unavailableRows === 1 ? ' has' : 's have') . ' no saved item rows, so those three counts are left blank.';
    }
    if ($partialRows > 0) {
        $hint .= ' ' . $partialRows . ' examinee' . ($partialRows === 1 ? ' has' : 's have') . ' saved item rows for only part of the official question total.';
    }
    if ($excludedTotal > 0) {
        $hint .= ' ' . $excludedTotal . ' answered ' . ($excludedTotal === 1 ? 'item had a missing or invalid scoring flag and is' : 'items had a missing or invalid scoring flag and are') . ' not counted as incorrect.';
    }
    $rows = [];
    $rows[] = '<row r="1" ht="28" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A1', 'Examinee Results', '1') . '</row>';
    $rows[] = '<row r="2" ht="20" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A2', $examTitle . ' · ' . $scopeLabel, '2') . '</row>';
    $rows[] = '<row r="3" ht="96" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A3', $hint, '3') . '</row>';
    $header = [];
    foreach ($headers as $index => $label) {
        $header[] = examination_monitor_analytics_xlsx_cell(ereview_xlsx_col_letter($index) . '4', $label, '4');
    }
    $rows[] = '<row r="4" ht="32" customHeight="1">' . implode('', $header) . '</row>';
    if ($dataRows === []) {
        $rows[] = '<row r="5" ht="20" customHeight="1">' . examination_monitor_analytics_xlsx_cell('A5', 'No examinees in this scope.', '5') . '</row>';
    }
    foreach ($dataRows as $dataRow) {
        $rows[] = $dataRow;
    }
    $last = max(5, $line - 1);

    return examination_monitor_analytics_sheet_document([
        'rows' => $rows,
        'dimension' => 'A1:K' . $last,
        'printArea' => 'A1:K' . $last,
        'cols' => [[1, 1, 6], [2, 2, 18], [3, 3, 32], [4, 4, 16], [5, 5, 26], [6, 8, 18], [9, 10, 16], [11, 11, 36]],
        'merges' => ['A1:K1', 'A2:K2', 'A3:K3'],
        'autoFilter' => 'A4:K' . $last,
        'freeze' => 4,
        'zoom' => 110,
        'fitWidth' => 1,
        'fitHeight' => 0,
        'tabColor' => '334155',
        'conditional' => examination_monitor_analytics_status_conditional('E5:E' . $last) . examination_monitor_analytics_performance_conditional('J5:K' . $last, 'K'),
    ]);
}

/**
 * @param array<string,mixed> $charts
 * @return array{sheet:string,charts:list<array<string,mixed>>,by_key:array<string,array<string,mixed>>}
 */
function examination_monitor_analytics_charts_sheet(array $charts, string $examTitle = '', string $scopeLabel = ''): array
{
    $rows = [];
    $excelRow = 4000;
    $hiddenRow = static function (int $row, int $height, string $inner): string {
        return '<row r="' . $row . '" ht="' . $height . '" customHeight="1" hidden="1">' . $inner . '</row>';
    };
    $chartSpecs = [];
    $byKey = [];
    $order = ['distribution', 'subjects', 'topics', 'scores', 'sections'];
    foreach ($order as $key) {
        $chart = is_array($charts[$key] ?? null) ? $charts[$key] : [];
        $title = (string)($chart['title'] ?? $key);
        $note = (string)($chart['note'] ?? '');
        $titleRow = $excelRow;
        $rows[] = $hiddenRow($excelRow, 22, examination_monitor_analytics_xlsx_cell('A' . $excelRow, $title, '27'));
        $excelRow++;
        if (empty($chart['included'])) {
            $rows[] = $hiddenRow($excelRow, 48, examination_monitor_analytics_xlsx_cell('A' . $excelRow, $note !== '' ? $note : 'This chart cannot be calculated for this scope.', '3'));
            $excelRow += 2;
            continue;
        }
        $labels = is_array($chart['labels'] ?? null) ? $chart['labels'] : [];
        $values = is_array($chart['values'] ?? null) ? $chart['values'] : [];
        $plotLabels = $key === 'distribution' && is_array($chart['plot_labels'] ?? null) ? $chart['plot_labels'] : $labels;
        $plotValues = $key === 'distribution' && is_array($chart['plot_values'] ?? null) ? $chart['plot_values'] : $values;
        $plotRefs = $key === 'distribution' && is_array($chart['plot_references'] ?? null) ? $chart['plot_references'] : [];
        $plotColors = $key === 'distribution' && is_array($chart['plot_colors'] ?? null) ? $chart['plot_colors'] : [];
        $rows[] = $hiddenRow($excelRow, 20, examination_monitor_analytics_xlsx_cell('A' . $excelRow, 'Label', '4')
            . examination_monitor_analytics_xlsx_cell('B' . $excelRow, 'Value', '4')
            . ($key === 'distribution' ? examination_monitor_analytics_xlsx_cell('C' . $excelRow, 'Percent of assigned', '4') : ''));
        $excelRow++;
        $start = $excelRow;
        foreach ($plotLabels as $index => $label) {
            $valueCell = $plotRefs !== [] && isset($plotRefs[$index])
                ? examination_monitor_analytics_formula_cell('B' . $excelRow, (string)$plotRefs[$index], '10')
                : examination_monitor_analytics_number_cell('B' . $excelRow, (string)($plotValues[$index] ?? 0), '10');
            $percentCell = '';
            if ($key === 'distribution' && $plotRefs !== [] && isset($plotRefs[$index])) {
                $percentCell = examination_monitor_analytics_formula_cell('C' . $excelRow, 'IF(Summary!$AB$5=0,"",' . $plotRefs[$index] . '/Summary!$AB$5)', '11');
            }
            $labelCell = examination_monitor_analytics_xlsx_cell('A' . $excelRow, (string)$label, '5');
            $chartLabelCell = '';
            if ($key === 'distribution' && $percentCell !== '') {
                $chartLabelCell = examination_monitor_analytics_formula_cell('D' . $excelRow, 'A' . $excelRow . '&" ("&TEXT(C' . $excelRow . ',"0.0%")&")"', '5');
            }
            $rows[] = $hiddenRow($excelRow, 18, $labelCell . $valueCell . $percentCell . $chartLabelCell);
            $excelRow++;
        }
        if ($note !== '') {
            $rows[] = $hiddenRow($excelRow, 64, examination_monitor_analytics_xlsx_cell('A' . $excelRow, $note, '3'));
            $excelRow++;
        }
        $end = $start + count($plotLabels) - 1;
        if ($end >= $start) {
            $spec = [
                'title' => $title,
                'cat' => 'Summary!$' . ($key === 'distribution' ? 'D' : 'A') . '$' . $start . ':$' . ($key === 'distribution' ? 'D' : 'A') . '$' . $end,
                'val' => 'Summary!$B$' . $start . ':$B$' . $end,
                'kind' => 'bar',
                'colors' => $plotColors,
                'from_row' => $titleRow - 1,
                'to_row' => max($titleRow + 16, $end + 1),
                'from_col' => 3,
                'to_col' => 16,
            ];
            $byKey[$key] = $spec;
            if ($key !== 'distribution' && $key !== 'scores') {
                $chartSpecs[] = $spec;
            }
        }
        $excelRow = max($excelRow + 2, $titleRow + 19);
    }

    return [
        'sheet' => '',
        'rows' => $rows,
        'charts' => $chartSpecs,
        'by_key' => $byKey,
    ];
}

/**
 * @param array<string,mixed> $options
 */
function examination_monitor_analytics_sheet_document(array $options): string
{
    $rows = is_array($options['rows'] ?? null) ? $options['rows'] : [];
    $dimension = (string)($options['dimension'] ?? 'A1');
    $cols = is_array($options['cols'] ?? null) ? $options['cols'] : [];
    $merges = is_array($options['merges'] ?? null) ? $options['merges'] : [];
    $autoFilter = (string)($options['autoFilter'] ?? '');
    $freeze = (int)($options['freeze'] ?? 0);
    $zoom = max(40, (int)($options['zoom'] ?? 110));
    $fitPage = !array_key_exists('fitPage', $options) || !empty($options['fitPage']);
    $scale = max(55, min(100, (int)($options['scale'] ?? 100)));
    $fitWidth = $fitPage ? max(1, (int)($options['fitWidth'] ?? 1)) : 0;
    $fitHeight = max(0, (int)($options['fitHeight'] ?? 0));
    $orientation = (string)($options['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
    $tab = preg_replace('/[^0-9A-Fa-f]/', '', (string)($options['tabColor'] ?? '0F2744')) ?: '0F2744';
    $conditional = (string)($options['conditional'] ?? '');
    $selected = !empty($options['selected']) ? ' tabSelected="1"' : '';
    $colXml = '';
    if ($cols !== []) {
        $pieces = [];
        foreach ($cols as $col) {
            if (!is_array($col) || count($col) < 3) {
                continue;
            }
            $hidden = !empty($col[3]) ? ' hidden="1"' : '';
            $pieces[] = '<col min="' . (int)$col[0] . '" max="' . (int)$col[1] . '" width="' . $col[2] . '" customWidth="1"' . $hidden . '/>';
        }
        if ($pieces !== []) {
            $colXml = '<cols>' . implode('', $pieces) . '</cols>';
        }
    }
    $mergeXml = '';
    if ($merges !== []) {
        $bits = [];
        foreach ($merges as $range) {
            $bits[] = '<mergeCell ref="' . $range . '"/>';
        }
        $mergeXml = '<mergeCells count="' . count($bits) . '">' . implode('', $bits) . '</mergeCells>';
    }
    $pane = '';
    if ($freeze > 0) {
        $top = $freeze + 1;
        $pane = '<pane ySplit="' . $freeze . '" topLeftCell="A' . $top . '" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A' . $top . '" sqref="A' . $top . '"/>';
    }
    $filterXml = $autoFilter !== '' ? '<autoFilter ref="' . $autoFilter . '"/>' : '';
    $colBreakXml = '';
    $colBreak = (int)($options['colBreak'] ?? 0);
    if ($colBreak > 1) {
        $colBreakXml = '<colBreaks count="1" manualBreakCount="1"><brk id="' . $colBreak . '" max="1048575" man="1"/></colBreaks>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><tabColor rgb="FF' . strtoupper($tab) . '"/><pageSetUpPr fitToPage="' . ($fitPage ? '1' : '0') . '"/></sheetPr>'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView showGridLines="0" zoomScale="' . $zoom . '" workbookViewId="0"' . $selected . '>' . $pane . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . $colXml
        . '<sheetData>' . implode('', $rows) . '</sheetData>'
        . $filterXml
        . $mergeXml
        . $conditional
        . '<pageMargins left="0.45" right="0.45" top="0.5" bottom="0.5" header="0.25" footer="0.25"/>'
        . '<pageSetup orientation="' . $orientation . '" paperSize="9" scale="' . $scale . '" fitToWidth="' . $fitWidth . '" fitToHeight="' . $fitHeight . '"/>'
        . '<headerFooter><oddFooter>&amp;LFinal examination results&amp;C&amp;A&amp;RPage &amp;P of &amp;N</oddFooter></headerFooter>'
        . $colBreakXml
        . (!empty($options['printArea']) && preg_match('/^[A-Z]+[0-9]+:[A-Z]+[0-9]+$/', (string)$options['printArea']) ? '<!--ereview-print:' . $options['printArea'] . '-->' : '')
        . '</worksheet>';
}

/**
 * @param array<string,string> $named
 */
function examination_monitor_analytics_print_areas(array $named): string
{
    $out = [];
    $index = 0;
    foreach ($named as $name => $xml) {
        $ref = '';
        if (preg_match('/<!--ereview-print:([A-Z]+[0-9]+:[A-Z]+[0-9]+)-->/', (string)$xml, $printMatch)) {
            $ref = $printMatch[1];
        } elseif (preg_match('/<dimension ref="([^"]+)"/', (string)$xml, $match)) {
            $ref = $match[1];
        }
        if ($ref !== '') {
            $quoted = "'" . str_replace("'", "''", (string)$name) . "'";
            $out[] = '<definedName name="_xlnm.Print_Area" localSheetId="' . $index . '">' . $quoted . '!' . examination_monitor_analytics_absolute_ref($ref) . '</definedName>';
            if ($index > 0) {
                $out[] = '<definedName name="_xlnm.Print_Titles" localSheetId="' . $index . '">' . $quoted . '!$1:$4</definedName>';
            }
        }
        $index++;
    }

    return implode('', $out);
}

function examination_monitor_analytics_absolute_ref(string $ref): string
{
    $parts = explode(':', $ref);
    $absolute = [];
    foreach ($parts as $part) {
        if (preg_match('/^([A-Z]+)(\d+)$/', $part, $match)) {
            $absolute[] = '$' . $match[1] . '$' . $match[2];
        } else {
            $absolute[] = $part;
        }
    }

    return implode(':', $absolute);
}

/**
 * @param array<string,string> $named
 * @param list<array<string,mixed>> $charts
 * @param array<string,string> $extras
 * @param list<string> $overrideEls
 */
function examination_monitor_analytics_attach_drawing(array &$named, string $sheetName, array $charts, int $firstChartNumber, int $sheetNumber, array &$extras, array &$overrideEls): int
{
    if ($charts === []) {
        return $firstChartNumber - 1;
    }
    $sheetXml = (string)($named[$sheetName] ?? '');
    $named[$sheetName] = str_replace(
        '</worksheet>',
        '<drawing r:id="rId1"/></worksheet>',
        str_replace(
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">',
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
            $sheetXml
        )
    );
    $drawingName = 'drawing' . $sheetNumber;
    $extras['xl/worksheets/_rels/sheet' . $sheetNumber . '.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/' . $drawingName . '.xml"/>'
        . '</Relationships>';
    $anchors = [];
    $rels = [];
    $chartNumber = $firstChartNumber - 1;
    foreach ($charts as $index => $chart) {
        $chartNumber++;
        $local = $index + 1;
        $anchors[] = examination_monitor_analytics_chart_anchor($local, $chart);
        $rels[] = '<Relationship Id="rId' . $local . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart' . $chartNumber . '.xml"/>';
        $colors = is_array($chart['colors'] ?? null) ? $chart['colors'] : [];
        $extras['xl/charts/chart' . $chartNumber . '.xml'] = examination_monitor_analytics_chart_xml(
            (string)($chart['title'] ?? 'Chart'),
            (string)($chart['cat'] ?? ''),
            (string)($chart['val'] ?? ''),
            (string)($chart['kind'] ?? 'bar'),
            $colors,
            !empty($chart['percent'])
        );
        $overrideEls[] = '<Override PartName="/xl/charts/chart' . $chartNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
    }
    $extras['xl/drawings/' . $drawingName . '.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . implode('', $anchors) . '</xdr:wsDr>';
    $extras['xl/drawings/_rels/' . $drawingName . '.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $rels) . '</Relationships>';
    $overrideEls[] = '<Override PartName="/xl/drawings/' . $drawingName . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';

    return $chartNumber;
}

/**
 * @param array<string,mixed> $chartPlan
 */
function examination_monitor_analytics_chart_omission(array $chartPlan, string $key): string
{
    $chart = is_array($chartPlan[$key] ?? null) ? $chartPlan[$key] : [];
    $note = trim((string)($chart['note'] ?? ''));

    return $note !== '' ? $note : 'This chart cannot be calculated for this scope.';
}

/**
 * @return list<string>
 */
function examination_monitor_analytics_status_colors(): array
{
    return ['1F7A4D', 'B42318', 'C4B48A', '94A3B8', '1E4B8A', 'CBD5E1'];
}

function examination_monitor_analytics_status_conditional(string $sqref): string
{
    $anchor = (string)preg_replace('/:.*/', '', $sqref);
    $rules = [
        ['Submitted, not graded', 2],
        ['In progress', 3],
        ['Passed', 0],
        ['Failed', 1],
        ['Absent', 4],
        ['Expired', 5],
    ];
    $xml = '<conditionalFormatting sqref="' . $sqref . '">';
    foreach ($rules as $priority => $rule) {
        $xml .= '<cfRule type="containsText" dxfId="' . $rule[1] . '" priority="' . ($priority + 1) . '" operator="containsText" text="' . htmlspecialchars($rule[0], ENT_XML1) . '">'
            . '<formula>NOT(ISERROR(SEARCH("' . $rule[0] . '",' . $anchor . ')))</formula></cfRule>';
    }

    return $xml . '</conditionalFormatting>';
}

function examination_monitor_analytics_performance_conditional(string $sqref, string $resultColumn): string
{
    $anchor = (string)preg_replace('/:.*/', '', $sqref);
    $row = (int)preg_replace('/\D/', '', $anchor);
    $rules = [
        ['Needs Reinforcement', 9],
        ['Satisfactory', 10],
        ['Failed', 8],
    ];
    $xml = '<conditionalFormatting sqref="' . $sqref . '">';
    foreach ($rules as $priority => $rule) {
        $xml .= '<cfRule type="expression" dxfId="' . $rule[1] . '" priority="' . ($priority + 1) . '">'
            . '<formula>NOT(ISERROR(SEARCH("' . $rule[0] . '",$'.$resultColumn . $row . ')))</formula></cfRule>';
    }

    return $xml . '</conditionalFormatting>';
}

function examination_monitor_analytics_text_row(int $row, string $ref, string $text, string $style): string
{
    return '<row r="' . $row . '">' . examination_monitor_analytics_xlsx_cell($ref, $text, $style) . '</row>';
}

function examination_monitor_analytics_formula_cell(string $ref, string $formula, string $style): string
{
    return '<c r="' . $ref . '" s="' . $style . '"><f>' . ereview_xlsx_f($formula) . '</f></c>';
}

function examination_monitor_analytics_number_cell(string $ref, string $value, string $style): string
{
    if (!is_numeric($value)) {
        return examination_monitor_analytics_xlsx_cell($ref, $value, $style);
    }

    return '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
}

function examination_monitor_analytics_styles_xml(): string
{
    $font = static function (int $size, string $rgb, bool $bold): string {
        return '<font>' . ($bold ? '<b/>' : '') . '<sz val="' . $size . '"/><color rgb="' . $rgb . '"/><name val="Calibri"/></font>';
    };
    $fonts = [
        $font(11, 'FF1E293B', false),
        $font(18, 'FFFFFFFF', true),
        $font(16, 'FF0F2744', true),
        $font(10, 'FF64748B', false),
        $font(10, 'FFFFFFFF', true),
        $font(9, 'FF64748B', true),
        $font(20, 'FF0F2744', true),
        $font(20, 'FF1F7A4D', true),
        $font(20, 'FFB42318', true),
        $font(20, 'FF64748B', true),
        $font(20, 'FF1E4B8A', true),
        $font(12, 'FF0F2744', true),
        $font(9, 'FF475569', false),
        $font(12, 'FF0F2744', true),
        $font(20, 'FF0F2744', true),
    ];
    $solid = static function (string $rgb): string {
        return '<fill><patternFill patternType="solid"><fgColor rgb="' . $rgb . '"/></patternFill></fill>';
    };
    $fills = [
        '<fill><patternFill patternType="none"/></fill>',
        '<fill><patternFill patternType="gray125"/></fill>',
        $solid('FF0F2744'),
        $solid('FFFFFFFF'),
        $solid('FFF7F9FC'),
        $solid('FFF4F7FB'),
        $solid('FFE7F6EE'),
        $solid('FFFDECEC'),
        $solid('FFF8F4EA'),
        $solid('FFE8EEF7'),
        $solid('FFF1F3F5'),
        $solid('FFF8FAFC'),
        $solid('FFD6E6F5'),
        $solid('FFF8D7D7'),
        $solid('FFFED7AA'),
        $solid('FFFEF3C7'),
        $solid('FFDCFCE7'),
    ];
    $borders = [
        '<border><left/><right/><top/><bottom/><diagonal/></border>',
        '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>',
    ];
    $left = '<alignment horizontal="left" vertical="center" wrapText="1"/>';
    $center = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
    $top = '<alignment horizontal="left" vertical="top" wrapText="1"/>';
    $right = '<alignment horizontal="right" vertical="center" wrapText="0"/>';
    $xf = static function (int $numFmt, int $fontId, int $fillId, int $borderId, string $align) use ($left, $center, $top, $right): string {
        $apply = ' applyFont="1" applyFill="1" applyAlignment="1"';
        if ($borderId > 0) {
            $apply .= ' applyBorder="1"';
        }
        if ($numFmt !== 0) {
            $apply .= ' applyNumberFormat="1"';
        }
        $alignment = $align === 'center' ? $center : ($align === 'top' ? $top : ($align === 'right' ? $right : $left));

        return '<xf numFmtId="' . $numFmt . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"' . $apply . '>' . $alignment . '</xf>';
    };
    $xfs = [
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
        $xf(0, 1, 2, 0, 'left'),
        $xf(0, 2, 3, 0, 'left'),
        $xf(0, 3, 5, 0, 'left'),
        $xf(0, 4, 2, 0, 'center'),
        $xf(0, 0, 3, 1, 'left'),
        $xf(0, 0, 4, 1, 'left'),
        $xf(10, 0, 3, 1, 'center'),
        $xf(164, 0, 3, 1, 'center'),
        $xf(0, 3, 3, 1, 'left'),
        $xf(0, 11, 3, 1, 'center'),
        $xf(10, 11, 3, 1, 'center'),
        $xf(164, 11, 3, 1, 'center'),
        $xf(0, 5, 5, 0, 'center'),
        $xf(0, 6, 5, 0, 'center'),
        $xf(0, 5, 6, 0, 'center'),
        $xf(0, 7, 6, 0, 'center'),
        $xf(0, 5, 7, 0, 'center'),
        $xf(0, 8, 7, 0, 'center'),
        $xf(0, 5, 8, 0, 'center'),
        $xf(0, 9, 8, 0, 'center'),
        $xf(0, 5, 9, 0, 'center'),
        $xf(0, 10, 9, 0, 'center'),
        $xf(0, 5, 10, 0, 'center'),
        $xf(0, 9, 10, 0, 'center'),
        $xf(10, 6, 5, 0, 'center'),
        $xf(164, 6, 5, 0, 'center'),
        $xf(0, 13, 3, 0, 'left'),
        $xf(0, 12, 11, 0, 'top'),
        $xf(1, 0, 3, 1, 'right'),
        $xf(1, 0, 4, 1, 'right'),
        $xf(10, 0, 3, 1, 'right'),
        $xf(10, 0, 4, 1, 'right'),
        $xf(164, 11, 3, 1, 'right'),
        $xf(0, 13, 12, 0, 'center'),
        $xf(0, 14, 12, 0, 'center'),
        $xf(0, 0, 13, 1, 'center'),
        $xf(0, 0, 14, 1, 'center'),
        $xf(0, 0, 15, 1, 'center'),
        $xf(0, 0, 16, 1, 'center'),
        $xf(0, 3, 10, 1, 'center'),
        $xf(165, 0, 3, 1, 'center'),
    ];
    $dxf = static function (string $fontRgb, string $fillRgb): string {
        return '<dxf><font><b/><color rgb="' . $fontRgb . '"/></font><fill><patternFill patternType="solid"><fgColor rgb="' . $fillRgb . '"/><bgColor rgb="' . $fillRgb . '"/></patternFill></fill></dxf>';
    };
    $dxfs = [
        $dxf('FF0F2744', 'FFE8EEF6'),
        $dxf('FFB42318', 'FFFDECEC'),
        $dxf('FF8A7340', 'FFF8F4EA'),
        $dxf('FF1E4B8A', 'FFE8EEF7'),
        $dxf('FF64748B', 'FFF1F3F5'),
        $dxf('FF64748B', 'FFE8EDF2'),
        $dxf('FF8A7340', 'FFF8F4EA'),
        $dxf('FF8A5A00', 'FFF8F1DE'),
        $dxf('FF7F1D1D', 'FFF8D7D7'),
        $dxf('FF92400E', 'FFFEF3C7'),
        $dxf('FF14532D', 'FFDCFCE7'),
        $dxf('FF9A3412', 'FFFED7AA'),
    ];

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="0.0&quot;%&quot;"/><numFmt numFmtId="165" formatCode="0.0%"/></numFmts>'
        . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
        . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
        . '<dxfs count="' . count($dxfs) . '">' . implode('', $dxfs) . '</dxfs>'
        . '</styleSheet>';
}

/**
 * @param array<string,mixed> $chart
 */
function examination_monitor_analytics_chart_anchor(int $index, array $chart): string
{
    $fromRow = max(0, (int)($chart['from_row'] ?? 0));
    $toRow = max($fromRow + 8, (int)($chart['to_row'] ?? ($fromRow + 16)));
    $fromCol = max(0, (int)($chart['from_col'] ?? 3));
    $toCol = max($fromCol + 4, (int)($chart['to_col'] ?? 16));

    return '<xdr:twoCellAnchor>'
        . '<xdr:from><xdr:col>' . $fromCol . '</xdr:col><xdr:colOff>80000</xdr:colOff><xdr:row>' . $fromRow . '</xdr:row><xdr:rowOff>80000</xdr:rowOff></xdr:from>'
        . '<xdr:to><xdr:col>' . $toCol . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $toRow . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
        . '<xdr:graphicFrame macro="">'
        . '<xdr:nvGraphicFramePr>'
        . '<xdr:cNvPr id="' . ($index + 1) . '" name="Chart ' . $index . '"/>'
        . '<xdr:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></xdr:cNvGraphicFramePr>'
        . '</xdr:nvGraphicFramePr>'
        . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="8000000" cy="4000000"/></xdr:xfrm>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
        . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId' . $index . '"/>'
        . '</a:graphicData></a:graphic>'
        . '</xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
}

/**
 * @param list<string> $colors
 */
function examination_monitor_analytics_chart_xml(string $title, string $categories, string $values, string $kind = 'bar', array $colors = [], bool $percent = false): string
{
    $title = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $categories = htmlspecialchars($categories, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $values = htmlspecialchars($values, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $points = '';
    foreach (array_values($colors) as $index => $rgb) {
        $rgb = preg_replace('/[^0-9A-Fa-f]/', '', (string)$rgb) ?: '1E3A5F';
        $points .= '<c:dPt><c:idx val="' . $index . '"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="' . strtoupper($rgb) . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr></c:dPt>';
    }
    $seriesFill = $points === ''
        ? '<c:spPr><a:solidFill><a:srgbClr val="1E3A5F"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr>'
        : '';
    $doughnut = $kind === 'doughnut';
    $percentFmt = $percent ? '<c:numFmt formatCode="0.0%" sourceLinked="0"/>' : '';
    $labels = $doughnut
        ? '<c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/><c:showBubbleSize val="0"/></c:dLbls>'
        : '<c:dLbls>' . $percentFmt . '<c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>';
    $seriesName = 'Examinees';
    if (stripos($title, 'accuracy') !== false || stripos($title, 'Section') !== false || stripos($title, 'Individual exam') !== false) {
        $seriesName = stripos($title, 'Individual exam') !== false ? 'Exam score' : 'Percent';
    } elseif (stripos($title, 'Answered items') !== false) {
        $seriesName = 'Answered items';
    }
    $series = '<c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>' . $seriesName . '</c:v></c:tx>'
        . $seriesFill . $points
        . '<c:cat><c:strRef><c:f>' . $categories . '</c:f></c:strRef></c:cat>'
        . '<c:val><c:numRef><c:f>' . $values . '</c:f></c:numRef></c:val>'
        . '</c:ser>';
    $plot = $doughnut
        ? '<c:doughnutChart><c:varyColors val="0"/>' . $series . $labels . '<c:firstSliceAng val="270"/><c:holeSize val="62"/></c:doughnutChart>'
        : '<c:barChart><c:barDir val="bar"/><c:grouping val="clustered"/><c:varyColors val="0"/>' . $series . $labels
            . '<c:gapWidth val="70"/><c:overlap val="0"/><c:axId val="100"/><c:axId val="200"/></c:barChart>'
            . examination_monitor_analytics_chart_axes($percent ? '0.0%' : '', stripos($title, 'Lowest to Highest') !== false);
    $legend = $doughnut ? '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>' : '';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<c:roundedCorners val="0"/>'
        . '<c:chart>'
        . '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1400" b="1"><a:solidFill><a:srgbClr val="0F2744"/></a:solidFill><a:latin typeface="Calibri"/></a:defRPr></a:pPr><a:r><a:rPr lang="en-US" sz="1400" b="1"><a:solidFill><a:srgbClr val="0F2744"/></a:solidFill><a:latin typeface="Calibri"/></a:rPr><a:t>' . $title . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>'
        . '<c:autoTitleDeleted val="0"/>'
        . '<c:plotArea><c:layout/>' . $plot . '</c:plotArea>'
        . $legend
        . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>'
        . '</c:chart>'
        . '<c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr>'
        . '</c:chartSpace>';
}

function examination_monitor_analytics_chart_axes(string $valueFormat = '', bool $categoryReverse = false): string
{
    $label = '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000"><a:solidFill><a:srgbClr val="334155"/></a:solidFill><a:latin typeface="Calibri"/></a:defRPr></a:pPr><a:endParaRPr lang="en-US"/></a:p></c:txPr>';
    $valueFmt = $valueFormat !== '' ? '<c:numFmt formatCode="' . htmlspecialchars($valueFormat, ENT_XML1) . '" sourceLinked="0"/>' : '';
    $categoryOrientation = $categoryReverse ? 'maxMin' : 'minMax';
    $valueCross = $categoryReverse ? 'max' : 'autoZero';

    return '<c:catAx><c:axId val="100"/><c:scaling><c:orientation val="' . $categoryOrientation . '"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . $label . '<c:crossAx val="200"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/></c:catAx>'
        . '<c:valAx><c:axId val="200"/><c:scaling><c:orientation val="minMax"/><c:min val="0"/>' . ($valueFormat !== '' ? '<c:max val="1"/>' : '') . '</c:scaling><c:delete val="0"/><c:axPos val="b"/><c:majorGridlines/>' . $valueFmt . '<c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . $label . '<c:crossAx val="100"/><c:crosses val="' . $valueCross . '"/><c:crossBetween val="between"/></c:valAx>';
}

/**
 * @return list<string>
 */
function examination_monitor_analytics_ranking_headers(): array
{
    return ['List', 'Rank', 'Student number', 'Name', 'Section', 'Correct', 'Total', 'Exam score', 'Mark'];
}

/**
 * @param array<string,mixed> $rankings
 * @return list<list<string>>
 */
function examination_monitor_analytics_ranking_rows(array $rankings): array
{
    $rows = [];
    $lists = [(string)($rankings['mode'] ?? '') === 'all' ? 'All graded' : 'Top' => $rankings['top'] ?? []];
    if (($rankings['low'] ?? []) !== []) {
        $lists['Lowest'] = $rankings['low'];
    }
    foreach ($lists as $label => $people) {
        if (!is_array($people)) {
            continue;
        }
        foreach ($people as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                (string)$label,
                (string)(int)($row['rank'] ?? 0),
                (string)($row['student_number'] ?? ''),
                (string)($row['full_name'] ?? ''),
                (string)($row['section'] ?? ''),
                (string)($row['correct'] ?? ''),
                (string)($row['total'] ?? ''),
                isset($row['percent']) && is_numeric($row['percent']) ? number_format((float)$row['percent'], 1) : '',
                (string)($row['mark'] ?? ''),
            ];
        }
    }

    return $rows;
}

/**
 * @return list<string>
 */
function examination_monitor_analytics_difficulty_headers(): array
{
    return ['Question', 'Subject', 'Topic', 'Presented', 'Answered', 'Unanswered', 'Other', 'A', 'B', 'C', 'D', 'Excluded from correctness'];
}

/**
 * @param array<string,mixed> $difficulty
 * @return list<list<string>>
 */
function examination_monitor_analytics_difficulty_rows(array $difficulty): array
{
    $rows = [];
    $questions = is_array($difficulty['questions'] ?? null) ? $difficulty['questions'] : [];
    foreach ($questions as $row) {
        if (!is_array($row)) {
            continue;
        }
        $choices = is_array($row['choices'] ?? null) ? $row['choices'] : [];
        $rows[] = [
            (string)($row['label'] ?? ('Question ' . (int)($row['question_id'] ?? 0))),
            (string)($row['subject_name'] ?? ''),
            (string)($row['topic_name'] ?? ''),
            (string)(int)($row['presented'] ?? 0),
            (string)(int)($row['answered'] ?? 0),
            (string)(int)($row['unanswered'] ?? 0),
            (string)(int)($row['other'] ?? 0),
            (string)(int)($choices['A'] ?? 0),
            (string)(int)($choices['B'] ?? 0),
            (string)(int)($choices['C'] ?? 0),
            (string)(int)($choices['D'] ?? 0),
            (string)(int)($row['excluded_from_correctness'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * Wrap a report line so every character remains available on a later line.
 *
 * @return list<string>
 */
function examination_monitor_analytics_pdf_wrap_lines(string $value, int $maxChars): array
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    if ($value === '') {
        return [''];
    }
    $maxChars = max(20, $maxChars);
    $lines = [];
    $remaining = $value;
    while ($remaining !== '') {
        $length = function_exists('mb_strlen') ? mb_strlen($remaining, 'UTF-8') : strlen($remaining);
        if ($length <= $maxChars) {
            $lines[] = $remaining;
            break;
        }
        $chunk = function_exists('mb_substr') ? mb_substr($remaining, 0, $maxChars, 'UTF-8') : substr($remaining, 0, $maxChars);
        $breakAt = function_exists('mb_strrpos') ? mb_strrpos($chunk, ' ', 0, 'UTF-8') : strrpos($chunk, ' ');
        if ($breakAt === false || $breakAt < (int)floor($maxChars * 0.5)) {
            $lines[] = $chunk;
            $remaining = function_exists('mb_substr') ? mb_substr($remaining, $maxChars, null, 'UTF-8') : substr($remaining, $maxChars);
            continue;
        }
        $line = function_exists('mb_substr') ? mb_substr($chunk, 0, $breakAt, 'UTF-8') : substr($chunk, 0, $breakAt);
        $lines[] = rtrim($line);
        $remaining = ltrim(function_exists('mb_substr') ? mb_substr($remaining, $breakAt + 1, null, 'UTF-8') : substr($remaining, $breakAt + 1));
    }

    return $lines === [] ? [''] : $lines;
}

/**
 * @param array<string,mixed> $report
 */
function examination_monitor_analytics_build_pdf_bytes(string $examTitle, string $scopeLabel, array $ana, array $progressRows, array $report): string
{
    $sections = examination_monitor_analytics_export_sections($ana, $progressRows);
    $examinees = is_array($report['examinees'] ?? null) ? $report['examinees'] : [];
    if ($examinees !== []) {
        $rosterRows = [];
        foreach ($examinees as $index => $row) {
            $percent = (isset($row['percent']) && is_numeric($row['percent'])) ? number_format((float)$row['percent'], 1) . '%' : '—';
            $rosterRows[] = [
                (string)($index + 1),
                (string)($row['student_number'] ?? ''),
                (string)($row['full_name'] ?? ''),
                (string)($row['email'] ?? ''),
                (string)($row['category'] ?? ''),
                $percent,
                (string)($row['mark'] ?? ''),
                (string)($row['section'] ?? ''),
                (string)($row['inclusion'] ?? ''),
                (string)($row['review'] ?? ''),
            ];
        }
        $sections[0]['rows'] = $rosterRows;
        $sections[0]['headers'] = ['#', 'Student number', 'Student name', 'Email', 'Status', 'Exam score', 'Mark', 'Section', 'Inclusion', 'Review'];
        $sections[0]['hint'] = 'Final result categories for the authorized roster. An in-progress attempt stays in progress and is not shown as submitted. Review flags do not remove anyone from the assigned count.';
    }
    $pageW = 842.0;
    $pageH = 595.28;
    $margin = 28.0;
    $lineH = 12.0;
    $generated = ereview_pdf_winansi(date('M j, Y g:i A'), 80);
    $pages = [];
    $cur = [];
    $y = $pageH - $margin;
    $emitLine = static function (string $line, string $font, float $size, float $r, float $g, float $b) use (&$cur, &$y, $margin): void {
        $cur[] = 'BT ' . $font . ' ' . sprintf('%.1F', $size) . ' Tf ' . sprintf('%.2F %.2F %.2F rg', $r, $g, $b) . ' 1 0 0 1 ' . sprintf('%.2F %.2F', $margin, $y) . ' Tm (' . ereview_pdf_literal(ereview_pdf_winansi($line, 4000)) . ') Tj ET';
        $y -= ($size + 3);
    };
    $flush = static function () use (&$pages, &$cur): void {
        if ($cur !== []) {
            $pages[] = implode("\n", $cur);
        }
        $cur = [];
    };
    $startPage = static function () use (&$cur, &$y, $pageH, $margin, $pageW, $examTitle, $scopeLabel, $emitLine): void {
        $cur = [];
        $y = $pageH - $margin;
        $header = static function (string $value, string $font, float $size, float $r, float $g, float $b) use ($emitLine, $pageW, $margin): void {
            $maxChars = max(24, (int)floor(($pageW - (2 * $margin)) / max(1.0, $size * 0.52)));
            foreach (examination_monitor_analytics_pdf_wrap_lines($value, $maxChars) as $line) {
                $emitLine($line, $font, $size, $r, $g, $b);
            }
        };
        $header('Final examination results', '/F2', 13, 0.12, 0.18, 0.28);
        $y -= 3;
        $header($examTitle, '/F2', 11, 0.12, 0.18, 0.28);
        $header('Scope: ' . $scopeLabel, '/F1', 9, 0.35, 0.42, 0.52);
        $y -= 6;
    };
    $text = static function (string $value, string $font, float $size, float $r, float $g, float $b) use (&$y, &$flush, &$startPage, $emitLine, $margin, $pageW): void {
        $maxChars = max(24, (int)floor(($pageW - (2 * $margin)) / max(1.0, $size * 0.52)));
        foreach (examination_monitor_analytics_pdf_wrap_lines($value, $maxChars) as $line) {
            if ($y - ($size + 3) < $margin + 18) {
                $flush();
                $startPage();
            }
            $emitLine($line, $font, $size, $r, $g, $b);
        }
    };
    $need = static function (float $needH) use (&$y, &$flush, &$startPage, $margin): void {
        if ($y - $needH < $margin + 18) {
            $flush();
            $startPage();
        }
    };
    $startPage();
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
    $text('Examination overview', '/F2', 12, 0.12, 0.25, 0.55);
    $assigned = (int)($counts['assigned'] ?? 0);
    $passed = (int)($counts['passed'] ?? 0);
    $failed = (int)($counts['failed'] ?? 0);
    $ungraded = (int)($counts['ungraded'] ?? 0);
    $inProgress = (int)($counts['in_progress'] ?? 0);
    $expired = (int)($counts['expired'] ?? 0);
    $absent = (int)($counts['absent'] ?? 0);
    $submitted = $passed + $failed + $ungraded;
    $graded = $passed + $failed;
    $text('Assigned examinees: ' . $assigned, '/F1', 10, 0.12, 0.18, 0.28);
    $text('Participation and result summary', '/F2', 11, 0.12, 0.25, 0.55);
    foreach ([
        'Passed' => $passed,
        'Failed' => $failed,
        'Submitted, not graded' => $ungraded,
        'In progress' => $inProgress,
        'Expired' => $expired,
        'Absent' => $absent,
    ] as $label => $count) {
        $need(14);
        $share = $assigned > 0 ? ' (' . number_format(100.0 * $count / $assigned, 1) . '% of assigned)' : '';
        $text($label . ': ' . $count . $share, '/F1', 10, 0.12, 0.18, 0.28);
    }
    if ($inProgress > 0) {
        $need(28);
        foreach (explode("\n", examination_monitor_analytics_unfinalized_warning()) as $warningLine) {
            $text($warningLine, '/F2', 10, 0.54, 0.35, 0.00);
        }
    }
    $completionRate = isset($summary['completion_rate']) && is_numeric($summary['completion_rate']) ? (float)$summary['completion_rate'] : null;
    $passingRate = isset($summary['passing_rate']) && is_numeric($summary['passing_rate']) ? (float)$summary['passing_rate'] : null;
    $need(16);
    if ($assigned === 0 || $completionRate === null) {
        $text('Completion: —', '/F1', 10, 0.12, 0.18, 0.28);
    } else {
        $text('Completion: ' . $submitted . ' of ' . $assigned . ' submitted (' . number_format($completionRate, 1) . '%)', '/F1', 10, 0.12, 0.18, 0.28);
    }
    if ($graded === 0 || $passingRate === null) {
        $text('Passing rate: —', '/F1', 10, 0.12, 0.18, 0.28);
    } elseif (!examination_monitor_analytics_graded_sample_is_sufficient($graded)) {
        $text('Passing rate: ' . $passed . ' passed / ' . $graded . ' graded. The graded sample is too small for a class-level conclusion.', '/F1', 10, 0.12, 0.18, 0.28);
    } else {
        $text('Passing rate: ' . number_format($passingRate, 1) . '% (' . $passed . '/' . $graded . ')', '/F1', 10, 0.12, 0.18, 0.28);
    }
    $scoreText = static function (mixed $value): string {
        return is_numeric($value) ? number_format((float)$value, 1) . '%' : '—';
    };
    $text('Exam score', '/F2', 11, 0.12, 0.25, 0.55);
    if ($graded === 0) {
        $text('No graded examinees.', '/F1', 10, 0.12, 0.18, 0.28);
    } elseif (!examination_monitor_analytics_graded_sample_is_sufficient($graded)) {
        $text('Individual Exam Score', '/F1', 10, 0.12, 0.18, 0.28);
        foreach ($examinees as $scoreRow) {
            if (empty($scoreRow['graded']) || !isset($scoreRow['percent']) || !is_numeric($scoreRow['percent'])) {
                continue;
            }
            $need(12);
            $text((string)($scoreRow['full_name'] ?? '') . ' - ' . number_format((float)$scoreRow['percent'], 1) . '%', '/F1', 10, 0.12, 0.18, 0.28);
        }
        $text('Fewer than five graded examinees. These individual exam scores are not a class-level result.', '/F1', 9, 0.35, 0.42, 0.52);
    } else {
        $text('Average Exam Score: ' . $scoreText($summary['average'] ?? null), '/F1', 10, 0.12, 0.18, 0.28);
        $text('Highest Exam Score: ' . $scoreText($summary['highest'] ?? null), '/F1', 10, 0.12, 0.18, 0.28);
        $text('Lowest Exam Score: ' . $scoreText($summary['lowest'] ?? null), '/F1', 10, 0.12, 0.18, 0.28);
    }
    $text('Reconciliation difference: ' . (int)($summary['reconcile_difference'] ?? 0), '/F1', 10, 0.12, 0.18, 0.28);
    foreach ((array)($summary['notes'] ?? []) as $note) {
        $need(14);
        $text((string)$note, '/F1', 8, 0.35, 0.42, 0.52);
    }

    $insights = is_array($report['insights'] ?? null) ? $report['insights'] : [];
    $need(28);
    $text('Performance insights', '/F2', 12, 0.12, 0.25, 0.55);
    foreach (preg_split("/\n/", (string)($insights['disclaimer'] ?? '')) ?: [] as $disclaimerLine) {
        $disclaimerLine = trim((string)$disclaimerLine);
        if ($disclaimerLine !== '') {
            $text($disclaimerLine, '/F1', 8, 0.35, 0.42, 0.52);
        }
    }
    $text('Top Graded Students', '/F2', 11, 0.12, 0.25, 0.55);
    $text((string)($insights['top_note'] ?? ''), '/F1', 8, 0.35, 0.42, 0.52);
    $insightStudents = is_array($insights['top_students'] ?? null) ? $insights['top_students'] : [];
    if ($insightStudents === []) {
        $text('No graded students are available for a ranking.', '/F1', 9, 0.12, 0.18, 0.28);
    }
    foreach ($insightStudents as $student) {
        if (!is_array($student)) {
            continue;
        }
        $need(12);
        $text((int)($student['rank'] ?? 0) . '. ' . (string)($student['full_name'] ?? '') . ' | ' . (string)($student['section'] ?? '') . ' | ' . number_format((float)($student['percent'] ?? 0), 1) . '% | ' . (int)($student['correct'] ?? 0) . ' correct | ' . (int)($student['total'] ?? 0) . ' items', '/F1', 8, 0.12, 0.18, 0.28);
    }
    $insightGroup = static function (string $title, array $rows) use ($text, $need): void {
        if ($rows === []) {
            return;
        }
        if ($title !== '') {
            $text($title, '/F2', 10, 0.12, 0.25, 0.55);
        }
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }
            $examineeText = (!empty($item['examinees_known']) && isset($item['examinees']) && is_numeric($item['examinees']))
                ? ((int)$item['examinees'] . ' examinees')
                : 'examinee count unavailable';
            $line = (string)($item['name'] ?? '') . ' | Stored accuracy ' . number_format((float)($item['accuracy'] ?? 0), 1) . '% | ' . (int)($item['answered'] ?? 0) . ' answered items | ' . $examineeText;
            $note = trim((string)($item['note'] ?? ''));
            if ($note !== '') {
                $line .= ' | ' . $note;
            }
            $need(12);
            $text($line, '/F1', 8, 0.12, 0.18, 0.28);
        }
    };
    $text('Subject Insights', '/F2', 11, 0.12, 0.25, 0.55);
    $text((string)($insights['subject_message'] ?? ''), '/F1', 8, 0.35, 0.42, 0.52);
    if (!empty($insights['subjects_available'])) {
        $insightGroup('', is_array($insights['subjects'] ?? null) ? $insights['subjects'] : []);
    }
    $text('Topic Insights', '/F2', 11, 0.12, 0.25, 0.55);
    $text((string)($insights['topic_message'] ?? ''), '/F1', 8, 0.35, 0.42, 0.52);
    if (!empty($insights['topics_available'])) {
        $insightGroup('Lowest stored accuracy', is_array($insights['topics_low'] ?? null) ? $insights['topics_low'] : []);
        $insightGroup('Highest stored accuracy', is_array($insights['topics_high'] ?? null) ? $insights['topics_high'] : []);
    }
    $text('Observations', '/F2', 11, 0.12, 0.25, 0.55);
    $insightObservations = is_array($insights['observations'] ?? null) ? $insights['observations'] : [];
    if ($insightObservations === []) {
        $text('No observations are available from this report.', '/F1', 8, 0.35, 0.42, 0.52);
    }
    foreach ($insightObservations as $observation) {
        $need(12);
        $text((string)$observation, '/F1', 9, 0.12, 0.18, 0.28);
    }

    $charts = is_array($report['charts'] ?? null) ? $report['charts'] : [];
    foreach (['distribution', 'subjects', 'topics', 'scores', 'sections'] as $chartKey) {
        $chart = is_array($charts[$chartKey] ?? null) ? $charts[$chartKey] : null;
        if ($chart === null) {
            continue;
        }
        $need(28);
        $text((string)($chart['title'] ?? 'Chart'), '/F2', 11, 0.12, 0.25, 0.55);
        $note = (string)($chart['note'] ?? '');
        if ($note !== '') {
            $need(14);
            $text($note, '/F1', 8, 0.35, 0.42, 0.52);
        }
        if (empty($chart['included'])) {
            continue;
        }
        $labels = $chartKey === 'distribution' && is_array($chart['plot_labels'] ?? null)
            ? $chart['plot_labels']
            : (is_array($chart['labels'] ?? null) ? $chart['labels'] : []);
        $values = $chartKey === 'distribution' && is_array($chart['plot_values'] ?? null)
            ? $chart['plot_values']
            : (is_array($chart['values'] ?? null) ? $chart['values'] : []);
        $max = 0.0;
        foreach ($values as $value) {
            $max = max($max, (float)$value);
        }
        if ($max <= 0) {
            $max = 1;
        }
        foreach ($labels as $index => $label) {
            $value = (float)($values[$index] ?? 0);
            $valueText = rtrim(rtrim(number_format($value, 1), '0'), '.');
            if ($chartKey === 'distribution' && $assigned > 0) {
                $valueText .= ' (' . number_format(100.0 * $value / $assigned, 1) . '% of assigned)';
            }
            $text((string)$label . ': ' . $valueText, '/F1', 8, 0.12, 0.18, 0.28);
            $width = 220.0 * ($value / $max);
            $cur[] = sprintf('%.2F %.2F %.2F %.2F re 0.15 0.39 0.72 rg f', $margin + 180, $y - 1, max(1.0, $width), 8);
            $y -= 12;
        }
    }

    $rankings = is_array($report['rankings'] ?? null) ? $report['rankings'] : [];
    $need(24);
    $text('Examinee rankings', '/F2', 11, 0.12, 0.25, 0.55);
    $text((string)($rankings['note'] ?? ''), '/F1', 8, 0.35, 0.42, 0.52);
    foreach (examination_monitor_analytics_ranking_rows($rankings) as $rankRow) {
        $need(12);
        $text(implode('  |  ', $rankRow), '/F1', 8, 0.12, 0.18, 0.28);
    }

    $difficulty = is_array($report['difficulty'] ?? null) ? $report['difficulty'] : [];
    $need(24);
    $text('Question response statistics', '/F2', 11, 0.12, 0.25, 0.55);
    $text((string)($difficulty['note'] ?? ''), '/F1', 8, 0.35, 0.42, 0.52);
    foreach (examination_monitor_analytics_difficulty_rows($difficulty) as $difficultyRow) {
        $text(implode('  |  ', $difficultyRow), '/F1', 8, 0.12, 0.18, 0.28);
    }

    foreach ($sections as $sec) {
        $need(36);
        $text((string)$sec['title'], '/F2', 11, 0.12, 0.25, 0.55);
        $text((string)$sec['hint'], '/F1', 8, 0.35, 0.42, 0.52);
        $body = $sec['rows'];
        if ($body === []) {
            $need(12);
            $text('No rows for this section.', '/F1', 8, 0.35, 0.42, 0.52);
            continue;
        }
        $need(12);
        $text(implode(' | ', $sec['headers']), '/F2', 7, 0.12, 0.25, 0.55);
        foreach ($body as $line) {
            $cells = [];
            foreach ($line as $cell) {
                $cells[] = trim((string)$cell);
            }
            $text(implode(' | ', $cells), '/F1', 7, 0.12, 0.18, 0.28);
        }
    }
    $flush();

    return examination_monitor_analytics_pdf_assemble($pages, $pageW, $pageH, $margin, $generated);
}

/**
 * @param list<string> $pages
 */
function examination_monitor_analytics_pdf_assemble(array $pages, float $pageW, float $pageH, float $margin, string $generated): string
{
    if ($pages === []) {
        $pages = ['BT /F1 12 Tf 1 0 0 1 40 400 Tm (No report rows.) Tj ET'];
    }
    $totalPages = count($pages);
    $objects = [];
    $objects[] = null;
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kidRefs = [];
    $baseId = 3;
    $contentObjIds = [];
    for ($i = 0; $i < $totalPages; $i++) {
        $contentObjIds[$i] = $baseId + 1;
        $kidRefs[] = $baseId . ' 0 R';
        $baseId += 2;
    }
    $font1Id = $baseId;
    $font2Id = $baseId + 1;
    $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kidRefs) . '] /Count ' . $totalPages . ' >>';
    for ($i = 0; $i < $totalPages; $i++) {
        $stream = $pages[$i] . "\nBT /F1 8 Tf 0.35 0.42 0.52 rg 1 0 0 1 " . sprintf('%.2F %.2F', $margin, 16) . ' Tm (' . ereview_pdf_literal('Page ' . ($i + 1) . ' of ' . $totalPages . '  |  ' . $generated) . ') Tj ET';
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

    return $pdf;
}
