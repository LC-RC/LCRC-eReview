<?php
/**
 * Pure performance-report calculations for regular-exam analytics export.
 * No database access and no attempt writes.
 */
declare(strict_types=1);

function examination_monitor_analytics_invalid_section_message(): string
{
    return 'That section is not on this examination. No analytics report was generated.';
}

/**
 * Stored 0 or 1 only. Null, blank, and every other value are unusable.
 */
function examination_monitor_analytics_stored_correctness(mixed $value): ?int
{
    if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    if ($number !== 0.0 && $number !== 1.0) {
        return null;
    }

    return (int)$number;
}

/**
 * @return array{category:string,graded:bool,percent:?float,mark:string,correct:?int,total:?int}
 */
function examination_monitor_analytics_classify_examinee(string $attemptStatus, ?int $correctCount, ?int $totalCount): array
{
    $status = strtolower(trim($attemptStatus));
    $blank = [
        'category' => 'Absent',
        'graded' => false,
        'percent' => null,
        'mark' => '',
        'correct' => null,
        'total' => null,
    ];
    if ($status === 'in_progress') {
        $blank['category'] = 'In progress';

        return $blank;
    }
    if ($status === 'expired') {
        $blank['category'] = 'Expired';

        return $blank;
    }
    if ($status !== 'submitted') {
        return $blank;
    }
    if ($totalCount === null || $totalCount <= 0) {
        $blank['category'] = 'Submitted, not graded';

        return $blank;
    }
    $correct = max(0, (int)($correctCount ?? 0));
    $total = (int)$totalCount;
    $pass = $correct >= (int)ceil($total / 2);

    return [
        'category' => $pass ? 'Passed' : 'Failed',
        'graded' => true,
        'percent' => round(100.0 * $correct / $total, 1),
        'mark' => $pass ? 'Pass' : 'Fail',
        'correct' => $correct,
        'total' => $total,
    ];
}

/**
 * @param list<array<string,mixed>> $examinees
 * @return list<array<string,mixed>>
 */
function examination_monitor_analytics_normalize_examinees(array $examinees): array
{
    $byUser = [];
    foreach ($examinees as $row) {
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $status = strtolower(trim((string)($row['attempt_status'] ?? '')));
        $correct = examination_monitor_analytics_nullable_int($row['correct_count'] ?? null);
        $total = examination_monitor_analytics_nullable_int($row['total_count'] ?? null);
        $classified = examination_monitor_analytics_classify_examinee($status, $correct, $total);
        $item = [
            'user_id' => $userId,
            'full_name' => trim((string)($row['full_name'] ?? '')),
            'student_number' => trim((string)($row['student_number'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'section' => trim((string)($row['section'] ?? '')),
            'category' => $classified['category'],
            'graded' => $classified['graded'],
            'percent' => $classified['percent'],
            'mark' => $classified['mark'],
            'correct' => $classified['correct'],
            'total' => $classified['total'],
            'inclusion' => trim((string)($row['inclusion'] ?? '')),
            'review' => '',
            '_raw_status' => $status,
        ];
        if (!isset($byUser[$userId])) {
            $byUser[$userId] = $item;
            continue;
        }
        $rank = ['submitted' => 3, 'expired' => 2, 'in_progress' => 1];
        $currentRank = $rank[$status] ?? 0;
        $keptRank = $rank[strtolower((string)($byUser[$userId]['_raw_status'] ?? ''))] ?? 0;
        if ($currentRank >= $keptRank) {
            $byUser[$userId] = $item;
        }
    }
    $out = [];
    foreach ($byUser as $userId => $item) {
        unset($item['_raw_status']);
        $out[] = $item;
    }
    usort($out, static function (array $a, array $b): int {
        $byName = strcasecmp((string)$a['full_name'], (string)$b['full_name']);
        if ($byName !== 0) {
            return $byName;
        }

        return ((int)$a['user_id']) <=> ((int)$b['user_id']);
    });

    return $out;
}

function examination_monitor_analytics_nullable_int(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }

    return (int)$value;
}

/**
 * @param list<array<string,mixed>> $examinees normalized rows
 * @return array<string,mixed>
 */
function examination_monitor_analytics_performance_summary(array $examinees): array
{
    $counts = [
        'assigned' => count($examinees),
        'passed' => 0,
        'failed' => 0,
        'ungraded' => 0,
        'in_progress' => 0,
        'expired' => 0,
        'absent' => 0,
    ];
    $percents = [];
    foreach ($examinees as $row) {
        $category = (string)($row['category'] ?? '');
        if ($category === 'Passed') {
            $counts['passed']++;
        } elseif ($category === 'Failed') {
            $counts['failed']++;
        } elseif ($category === 'Submitted, not graded') {
            $counts['ungraded']++;
        } elseif ($category === 'In progress') {
            $counts['in_progress']++;
        } elseif ($category === 'Expired') {
            $counts['expired']++;
        } else {
            $counts['absent']++;
        }
        if (!empty($row['graded']) && isset($row['percent']) && is_numeric($row['percent'])) {
            $percents[] = (float)$row['percent'];
        }
    }
    $accounted = $counts['passed'] + $counts['failed'] + $counts['ungraded'] + $counts['in_progress'] + $counts['expired'] + $counts['absent'];
    $graded = $counts['passed'] + $counts['failed'];
    $completed = $counts['passed'] + $counts['failed'] + $counts['ungraded'];

    return [
        'counts' => $counts,
        'reconcile_difference' => $counts['assigned'] - $accounted,
        'graded_count' => $graded,
        'completion_rate' => examination_monitor_analytics_summary_rate($completed, $counts['assigned']),
        'passing_rate' => examination_monitor_analytics_summary_rate($counts['passed'], $graded),
        'average' => $percents === [] ? null : round(array_sum($percents) / count($percents), 1),
        'highest' => $percents === [] ? null : round(max($percents), 1),
        'lowest' => $percents === [] ? null : round(min($percents), 1),
        'notes' => [
            'Completion rate = (passed + failed + submitted, not graded) / assigned examinees.',
            'Passing rate = passed / (passed + failed). Absent, in progress, expired, and ungraded submissions are not failed and are not in this denominator.',
            'Average, highest, and lowest exam scores use 100 × correct / total. They are shown when at least 5 graded examinees exist and omit everyone who is not graded.',
            'Expired counts only a stored attempt status of expired. A regular examination normally stores a timed-out attempt as Submitted.',
            'This report covers the selected section. A subject or topic filter is not applied.',
        ],
    ];
}

function examination_monitor_analytics_unfinalized_warning(): string
{
    return "Some examination attempts have not been finalized.\nReview the roster before treating this report as final.";
}

/**
 * Open attempts only. Stored expired is a closed status, not an unfinished attempt.
 */
function examination_monitor_analytics_unfinalized_warning_applies(string $attemptStatus): bool
{
    return strtolower(trim($attemptStatus)) === 'in_progress';
}

function examination_monitor_analytics_summary_rate(int $numerator, int $denominator): ?float
{
    if ($denominator <= 0) {
        return null;
    }

    return round(100.0 * $numerator / $denominator, 1);
}

/**
 * @param list<float> $percents
 * @return list<array{label:string,min:int,max:int,count:int}>
 */
function examination_monitor_analytics_summary_histogram(array $percents): array
{
    $buckets = [];
    for ($i = 0; $i < 9; $i++) {
        $buckets[] = [
            'label' => (string)($i * 10) . '–' . (string)($i * 10 + 9),
            'min' => $i * 10,
            'max' => $i * 10 + 9,
            'count' => 0,
        ];
    }
    $buckets[] = ['label' => '90–100', 'min' => 90, 'max' => 100, 'count' => 0];
    foreach ($percents as $raw) {
        if (!is_numeric($raw)) {
            continue;
        }
        $value = max(0, min(100, (int)round((float)$raw)));
        $index = $value >= 90 ? 9 : intdiv($value, 10);
        $buckets[$index]['count']++;
    }

    return $buckets;
}

/**
 * @param list<array<string,mixed>> $graded rows with percent and user_id
 * @return array{mode:string,top:list<array<string,mixed>>,low:list<array<string,mixed>>,note:string}
 */
function examination_monitor_analytics_rankings(array $examinees): array
{
    $graded = [];
    foreach ($examinees as $row) {
        if (!empty($row['graded']) && isset($row['percent']) && is_numeric($row['percent'])) {
            $graded[] = $row;
        }
    }
    usort($graded, static function (array $a, array $b): int {
        $byScore = ((float)$b['percent']) <=> ((float)$a['percent']);
        if ($byScore !== 0) {
            return $byScore;
        }

        return ((int)$a['user_id']) <=> ((int)$b['user_id']);
    });
    if ($graded === []) {
        return [
            'mode' => 'empty',
            'top' => [],
            'low' => [],
            'note' => 'No graded examinees in this scope. Empty ranks are not filled.',
        ];
    }
    if (count($graded) <= 10) {
        return [
            'mode' => 'all',
            'top' => examination_monitor_analytics_assign_ranks($graded),
            'low' => [],
            'note' => 'Ten or fewer graded examinees. One list is shown so the lowest list does not repeat it.',
        ];
    }
    $ranked = examination_monitor_analytics_assign_ranks($graded);
    $top = examination_monitor_analytics_tie_cutoff($ranked, true);
    $lowPool = examination_monitor_analytics_tie_cutoff(array_reverse($ranked), false);
    $topIds = [];
    foreach ($top as $row) {
        $topIds[(int)$row['user_id']] = true;
    }
    $low = [];
    foreach ($lowPool as $row) {
        if (isset($topIds[(int)$row['user_id']])) {
            continue;
        }
        $low[] = $row;
    }
    $note = 'Tied scores share a rank. The next distinct score takes the next consecutive rank. The cutoff includes everyone tied with the 10th score.';
    if ($low === []) {
        $note .= ' The lowest list is omitted because everyone at that end is already in the top list.';
    }

    return [
        'mode' => 'split',
        'top' => $top,
        'low' => $low,
        'note' => $note,
    ];
}

/**
 * @param list<array<string,mixed>> $sorted highest score first when $descending, lowest first otherwise
 * @return list<array<string,mixed>>
 */
function examination_monitor_analytics_tie_cutoff(array $sorted, bool $descending): array
{
    if (count($sorted) <= 10) {
        return $sorted;
    }
    $cutoff = (float)$sorted[9]['percent'];
    $out = [];
    foreach ($sorted as $row) {
        $percent = (float)$row['percent'];
        if ($descending && $percent < $cutoff) {
            break;
        }
        if (!$descending && $percent > $cutoff) {
            break;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows already ordered
 * @return list<array<string,mixed>>
 */
function examination_monitor_analytics_assign_ranks(array $rows): array
{
    $out = [];
    $rank = 0;
    $previous = null;
    foreach ($rows as $row) {
        $percent = (float)$row['percent'];
        if ($previous === null || $percent !== $previous) {
            $rank++;
            $previous = $percent;
        }
        $row['rank'] = $rank;
        $out[] = $row;
    }

    return $out;
}

/**
 * Snapshot response counts. This does not reconstruct an answer key and does not rank difficulty.
 * A future ranking needs the displayed correct letter, or the choice text, stored on the attempt
 * when the paper was scored. The live question bank cannot provide that historical letter.
 *
 * @param list<array<string,mixed>> $responses
 * @param array{submitted_attempts?:int,attempts_without_snapshot?:int} $coverage
 * @return array<string,mixed>
 */
function examination_monitor_analytics_question_difficulty(array $responses, array $coverage = []): array
{
    $submitted = max(0, (int)($coverage['submitted_attempts'] ?? 0));
    $without = max(0, (int)($coverage['attempts_without_snapshot'] ?? 0));
    if ($without > $submitted) {
        $without = $submitted;
    }
    $grouped = [];
    $excluded = 0;
    foreach ($responses as $row) {
        $questionId = (int)($row['question_id'] ?? 0);
        if ($questionId <= 0) {
            continue;
        }
        $selected = strtoupper(trim((string)($row['selected'] ?? '')));
        $answered = (bool)preg_match('/^[A-D]$/', $selected);
        $other = $selected !== '' && !$answered;
        if (!isset($grouped[$questionId])) {
            $grouped[$questionId] = [
                'question_id' => $questionId,
                'label' => 'Question ' . $questionId,
                'subject_name' => trim((string)($row['subject_name'] ?? '')),
                'topic_name' => trim((string)($row['topic_name'] ?? '')),
                'presented' => 0,
                'answered' => 0,
                'unanswered' => 0,
                'other' => 0,
                'excluded_from_correctness' => 0,
                'choices' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0],
            ];
        }
        $bucket = &$grouped[$questionId];
        $bucket['presented']++;
        if ($answered) {
            $bucket['answered']++;
            $bucket['choices'][$selected]++;
            if (examination_monitor_analytics_stored_correctness($row['stored_is_correct'] ?? null) === null) {
                $bucket['excluded_from_correctness']++;
                $excluded++;
            }
        } elseif ($other) {
            $bucket['other']++;
        } else {
            $bucket['unanswered']++;
        }
        unset($bucket);
    }
    $questions = array_values($grouped);
    usort($questions, static function (array $a, array $b): int {
        return ((int)$a['question_id']) <=> ((int)$b['question_id']);
    });

    return [
        'questions' => $questions,
        'hardest' => [],
        'easiest' => [],
        'rankings_available' => false,
        'excluded_from_correctness' => $excluded,
        'submitted_attempts' => $submitted,
        'attempts_without_snapshot' => $without,
        'partial' => $without > 0,
        'note' => examination_monitor_analytics_response_statistics_note($submitted, $without, $excluded, $questions === []),
    ];
}

function examination_monitor_analytics_response_statistics_note(int $submitted, int $without, int $excluded, bool $noRows): string
{
    $covered = max(0, $submitted - $without);
    $note = 'These rows are response statistics from question snapshots. They are not verified difficulty, and this report does not rank hardest or easiest questions. ';
    $note .= 'Presented, answered, unanswered, other, and A-D counts describe selected letters only. ';
    $note .= 'Correctness is not calculated. The snapshot does not store the displayed correct letter, and the current question bank is not used to reconstruct one. ';
    $note .= 'Difficulty rankings can be enabled only after each scored attempt stores the displayed correct letter, or the choice text, that was shown when the attempt was scored.';
    if ($excluded > 0) {
        $note .= ' ' . $excluded . ' answered response' . ($excluded === 1 ? '' : 's') . ' with a missing or invalid correctness flag ' . ($excluded === 1 ? 'is' : 'are') . ' excluded from correctness analysis and remain in the response counts.';
    }
    if ($without > 0) {
        $note .= ' Snapshot statistics cover ' . $covered . ' of ' . $submitted . ' submitted attempts. ' . $without . ' submitted attempt' . ($without === 1 ? '' : 's') . ' have no question snapshot and are omitted. These figures are partial and are not a complete question analysis.';
    } elseif ($noRows) {
        $note .= ' No snapshot rows were available in this scope.';
    } elseif ($submitted > 0) {
        $note .= ' Snapshot statistics include all ' . $submitted . ' submitted attempts in this scope.';
    }

    return $note;
}

/**
 * Alphabetical groups that meet the shared sample rule. This does not rank by accuracy.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array{label:string,value:float}>
 */
function examination_monitor_analytics_chart_qualified_rows(array $rows, string $kind): array
{
    $qualified = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['unassigned'])) {
            continue;
        }
        $answered = (int)($row['answered'] ?? 0);
        if ($answered <= 0 || !isset($row['accuracy']) || !is_numeric($row['accuracy'])) {
            continue;
        }
        $known = isset($row['examinees']) && is_numeric($row['examinees']);
        if (!examination_monitor_analytics_insight_sample_is_sufficient($answered, [
            'count' => $known ? (int)$row['examinees'] : null,
            'known' => $known,
        ])) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($kind === 'topic') {
            $subject = trim((string)($row['subject_name'] ?? ''));
            $name = ($subject !== '' && strcasecmp($subject, $name) !== 0) ? $subject . ' · ' . $name : $name;
        }
        if ($name === '') {
            $name = $kind === 'topic' ? 'Topic' : 'Subject';
        }
        $qualified[] = ['label' => $name, 'value' => (float)$row['accuracy']];
    }
    usort($qualified, static function (array $a, array $b): int {
        return strcasecmp($a['label'], $b['label']);
    });

    return $qualified;
}

/**
 * One graded paper can move a rate by at most 20 points once five papers are graded.
 */
function examination_monitor_analytics_graded_sample_is_sufficient(int $graded): bool
{
    return $graded >= 5;
}

function examination_monitor_analytics_generated_account_flag(string $name, string $email): bool
{
    $name = trim($name);
    $email = strtolower(trim($email));
    if (stripos($name, 'Audience Test') === 0 || stripos($name, 'QA ') === 0) {
        return true;
    }
    foreach (['@example.test', '@ereview.invalid', '@qa.local'] as $domain) {
        if ($email !== '' && str_ends_with($email, $domain)) {
            return true;
        }
    }

    return false;
}

/**
 * Flags are review markers only. They do not remove a row from assigned counts.
 *
 * @param list<array<string,mixed>> $examinees
 * @return list<array<string,mixed>>
 */
function examination_monitor_analytics_apply_roster_review(array $examinees): array
{
    $nameCounts = [];
    $numberOwners = [];
    foreach ($examinees as $row) {
        $name = strtolower(trim((string)($row['full_name'] ?? '')));
        if ($name !== '') {
            $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
        }
        $number = strtolower(trim((string)($row['student_number'] ?? '')));
        $userId = (int)($row['user_id'] ?? 0);
        if ($number !== '' && $userId > 0) {
            $numberOwners[$number][$userId] = true;
        }
    }
    foreach ($examinees as $index => $row) {
        $flags = [];
        if (examination_monitor_analytics_generated_account_flag((string)($row['full_name'] ?? ''), (string)($row['email'] ?? ''))) {
            $flags[] = 'Review generated account';
        }
        $name = strtolower(trim((string)($row['full_name'] ?? '')));
        if ($name !== '' && ($nameCounts[$name] ?? 0) > 1) {
            $flags[] = 'Review duplicate name';
        }
        if (trim((string)($row['student_number'] ?? '')) === '') {
            $flags[] = 'Review missing student number';
        }
        $number = strtolower(trim((string)($row['student_number'] ?? '')));
        if ($number !== '' && count($numberOwners[$number] ?? []) > 1) {
            $flags[] = 'Review duplicate student number';
        }
        $examinees[$index]['review'] = implode('; ', $flags);
    }

    return $examinees;
}

/**
 * Export-only stored accuracy. Null and invalid flags are excluded, not treated as incorrect.
 * The live item-analytics query is left unchanged.
 *
 * @param list<array<string,mixed>> $responses
 * @return array{subjects:list<array<string,mixed>>,topics:list<array<string,mixed>>,questions:list<array<string,mixed>>,examinee_subjects:list<array<string,mixed>>,examinee_topics:list<array<string,mixed>>}
 */
function examination_monitor_analytics_export_accuracy(array $responses): array
{
    $subjects = [];
    $topics = [];
    $questions = [];
    $examineeSubjects = [];
    $examineeTopics = [];
    foreach ($responses as $row) {
        if (!is_array($row)) {
            continue;
        }
        $questionId = (int)($row['question_id'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $subjectName = trim((string)($row['subject_name'] ?? ''));
        $subjectUnassigned = $subjectName === '';
        if ($subjectUnassigned) {
            $subjectName = 'Unassigned';
        }
        $topicName = trim((string)($row['topic_name'] ?? ''));
        $topicUnassigned = $topicName === '';
        if ($topicUnassigned) {
            $topicName = 'Unassigned';
        }
        $selected = strtoupper(trim((string)($row['selected'] ?? '')));
        $answered = preg_match('/^[A-D]$/', $selected) === 1;
        $flag = $answered ? examination_monitor_analytics_stored_correctness($row['stored_is_correct'] ?? null) : null;
        $correct = $flag === 1 ? 1 : 0;
        $incorrect = $flag === 0 ? 1 : 0;
        $excluded = $answered && $flag === null ? 1 : 0;
        $choice = $answered ? $selected : '';
        $bump = static function (array &$bucket) use ($answered, $correct, $incorrect, $excluded, $choice, $userId): void {
            $bucket['presented']++;
            if ($answered) {
                $bucket['answered']++;
                $bucket['correct'] += $correct;
                $bucket['incorrect'] += $incorrect;
                $bucket['excluded'] += $excluded;
                if ($choice !== '') {
                    $bucket['choices'][$choice] = ($bucket['choices'][$choice] ?? 0) + 1;
                }
                if ($userId > 0) {
                    $bucket['users'][$userId] = true;
                }
            } else {
                $bucket['unanswered']++;
            }
        };
        $blank = static function () use ($subjectName, $subjectUnassigned, $topicName, $topicUnassigned): array {
            return [
                'presented' => 0,
                'answered' => 0,
                'correct' => 0,
                'incorrect' => 0,
                'excluded' => 0,
                'unanswered' => 0,
                'choices' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0],
                'users' => [],
                'name' => $subjectName,
                'subject_name' => $subjectName,
                'topic_name' => $topicName,
                'unassigned' => $subjectUnassigned,
                'topic_unassigned' => $topicUnassigned,
            ];
        };
        $subjectKey = strtolower($subjectName);
        if (!isset($subjects[$subjectKey])) {
            $subjects[$subjectKey] = $blank();
            $subjects[$subjectKey]['name'] = $subjectName;
            $subjects[$subjectKey]['unassigned'] = $subjectUnassigned;
            $subjects[$subjectKey]['key'] = $subjectKey;
        }
        $bump($subjects[$subjectKey]);
        $topicKey = $subjectKey . '|' . strtolower($topicName);
        if (!isset($topics[$topicKey])) {
            $topics[$topicKey] = $blank();
            $topics[$topicKey]['name'] = $topicName;
            $topics[$topicKey]['subject_name'] = $subjectName;
            $topics[$topicKey]['unassigned'] = $topicUnassigned;
            $topics[$topicKey]['key'] = $topicKey;
        }
        $bump($topics[$topicKey]);
        if ($questionId > 0) {
            if (!isset($questions[$questionId])) {
                $questions[$questionId] = $blank();
                $questions[$questionId]['question_id'] = $questionId;
                $questions[$questionId]['name'] = 'Question ' . $questionId;
                $questions[$questionId]['stem'] = trim((string)($row['stem'] ?? ''));
                $questions[$questionId]['subject_name'] = $subjectName;
                $questions[$questionId]['topic_name'] = $topicName;
                $questions[$questionId]['display_positions'] = [];
            }
            $displayPosition = (int)($row['display_position'] ?? 0);
            if ($displayPosition > 0) {
                $questions[$questionId]['display_positions'][$displayPosition] = true;
            }
            $bump($questions[$questionId]);
        }
        if ($userId > 0) {
            $examTotal = (int)($row['exam_total'] ?? 0);
            $examCorrect = (int)($row['exam_correct'] ?? 0);
            $person = [
                'user_id' => $userId,
                'full_name' => trim((string)($row['full_name'] ?? '')),
                'student_number' => trim((string)($row['student_number'] ?? '')),
                'email' => trim((string)($row['email'] ?? '')),
                'section' => trim((string)($row['section'] ?? '')),
                'exam_score_label' => $examTotal > 0 ? number_format(round(100.0 * $examCorrect / $examTotal, 1), 1) . '%' : '—',
            ];
            $subjectPerson = $userId . '|' . $subjectKey;
            if (!isset($examineeSubjects[$subjectPerson])) {
                $examineeSubjects[$subjectPerson] = $blank();
                $examineeSubjects[$subjectPerson] = array_merge($examineeSubjects[$subjectPerson], $person, [
                    'name' => $subjectName,
                    'subject_name' => $subjectName,
                    'unassigned' => $subjectUnassigned,
                    'key' => $subjectKey,
                ]);
            }
            $bump($examineeSubjects[$subjectPerson]);
            $topicPerson = $userId . '|' . $topicKey;
            if (!isset($examineeTopics[$topicPerson])) {
                $examineeTopics[$topicPerson] = array_merge($blank(), $person, [
                    'name' => $topicName,
                    'topic_name' => $topicName,
                    'subject_name' => $subjectName,
                    'unassigned' => $topicUnassigned,
                    'key' => $topicKey,
                ]);
            }
            $bump($examineeTopics[$topicPerson]);
        }
    }
    $finish = static function (array $bucket): array {
        $answered = (int)$bucket['answered'];
        $correct = (int)$bucket['correct'];
        $incorrect = (int)$bucket['incorrect'];
        $excluded = (int)$bucket['excluded'];
        $verified = $correct + $incorrect;
        $accuracy = $verified > 0 ? round(100.0 * $correct / $verified, 1) : null;
        $incorrectRate = $verified > 0 ? round(100.0 * $incorrect / $verified, 1) : null;
        $examinees = count($bucket['users']);
        $sufficient = examination_monitor_analytics_insight_sample_is_sufficient($answered, ['count' => $examinees, 'known' => true]);
        unset($bucket['users']);
        $bucket['presented'] = (int)$bucket['presented'];
        $bucket['answered'] = $answered;
        $bucket['correct'] = $correct;
        $bucket['incorrect'] = $incorrect;
        $bucket['excluded'] = $excluded;
        $bucket['unanswered'] = (int)$bucket['unanswered'];
        $bucket['accuracy'] = $accuracy;
        $bucket['incorrect_rate'] = $incorrectRate;
        $bucket['accuracy_label'] = $accuracy === null ? '—' : number_format($accuracy, 1) . '%';
        $bucket['incorrect_rate_label'] = $incorrectRate === null ? '—' : number_format($incorrectRate, 1) . '%';
        $bucket['examinees'] = $examinees;
        $bucket['examinee_count'] = $examinees;
        $bucket['examinees_known'] = true;
        $bucket['sufficient'] = $sufficient;
        $bucket['sample'] = $sufficient ? '' : 'Limited sample';
        $bucket['weak'] = false;
        $bucket['reconciles'] = $answered === ($correct + $incorrect + $excluded);
        if (isset($bucket['question_id'])) {
            $positions = array_map('intval', array_keys(is_array($bucket['display_positions'] ?? null) ? $bucket['display_positions'] : []));
            sort($positions);
            $bucket['display_position'] = count($positions) === 1 ? $positions[0] : null;
        }
        unset($bucket['display_positions']);

        return $bucket;
    };
    $subjectList = array_map($finish, array_values($subjects));
    $topicList = array_map($finish, array_values($topics));
    $questionList = array_map($finish, array_values($questions));
    $byName = static function (array $a, array $b): int {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    };
    usort($subjectList, $byName);
    usort($topicList, $byName);
    usort($questionList, static function (array $a, array $b): int {
        return ((int)($a['question_id'] ?? 0)) <=> ((int)($b['question_id'] ?? 0));
    });

    return [
        'subjects' => $subjectList,
        'topics' => $topicList,
        'questions' => $questionList,
        'examinee_subjects' => array_map($finish, array_values($examineeSubjects)),
        'examinee_topics' => array_map($finish, array_values($examineeTopics)),
    ];
}

/**
 * @param list<array<string,mixed>> $subjectRows
 * @param list<array<string,mixed>> $topicRows
 * @return array<string,mixed>
 */
function examination_monitor_analytics_chart_plan(
    array $summary,
    array $examinees,
    array $subjectRows,
    array $topicRows,
    string $sectionFilter,
    string $breakdownMode,
    bool $sectionInvalid
): array {
    if ($sectionInvalid) {
        $blocked = examination_monitor_analytics_invalid_section_message();
        $emptyChart = static function (string $title) use ($blocked): array {
            return [
                'included' => false,
                'title' => $title,
                'note' => $blocked,
                'labels' => [],
                'values' => [],
            ];
        };

        return [
            'distribution' => $emptyChart('Result distribution'),
            'subjects' => $emptyChart('Subject accuracy'),
            'topics' => $emptyChart('Topic accuracy'),
            'scores' => $emptyChart('Score distribution'),
            'sections' => $emptyChart('Section comparison'),
        ];
    }
    $counts = $summary['counts'];
    $distributionLabels = ['Passed', 'Failed', 'Submitted, not graded', 'Absent', 'In progress', 'Expired'];
    $distributionValues = [
        (int)$counts['passed'],
        (int)$counts['failed'],
        (int)$counts['ungraded'],
        (int)$counts['absent'],
        (int)$counts['in_progress'],
        (int)$counts['expired'],
    ];
    $distributionRefs = ['Summary!AB6', 'Summary!AB7', 'Summary!AB8', 'Summary!AB11', 'Summary!AB9', 'Summary!AB10'];
    $distributionColors = ['1F7A4D', 'B42318', 'C4B48A', '94A3B8', '1E4B8A', 'CBD5E1'];
    $plotLabels = [];
    $plotValues = [];
    $plotRefs = [];
    $plotColors = [];
    foreach ($distributionLabels as $index => $label) {
        if ($distributionValues[$index] <= 0) {
            continue;
        }
        $plotLabels[] = $label;
        $plotValues[] = $distributionValues[$index];
        $plotRefs[] = $distributionRefs[$index];
        $plotColors[] = $distributionColors[$index];
    }
    $distribution = [
        'included' => (int)$counts['assigned'] > 0 && $plotLabels !== [],
        'title' => 'Attempt status',
        'note' => (int)$counts['assigned'] > 0 ? 'Bars show categories with at least one examinee. Zero counts stay in the summary table.' : 'No assigned examinees in this scope.',
        'labels' => $distributionLabels,
        'values' => $distributionValues,
        'references' => $distributionRefs,
        'colors' => $distributionColors,
        'plot_labels' => $plotLabels,
        'plot_values' => $plotValues,
        'plot_references' => $plotRefs,
        'plot_colors' => $plotColors,
    ];

    $subjectQualified = examination_monitor_analytics_chart_qualified_rows($subjectRows, 'subject');
    $subjectNote = '';
    $subjectIncluded = false;
    if ($breakdownMode === 'overall') {
        $subjectNote = 'Subject comparison is not available for an overall-mode exam.';
    } elseif ($subjectRows === []) {
        $subjectNote = 'Subject comparison is omitted because no subject has stored answered items.';
    } elseif ($subjectQualified === []) {
        $subjectNote = 'Subject comparison is omitted because no subject has at least 5 answered items from at least 3 examinees. Descriptive counts stay on the Subjects sheet.';
    } else {
        $subjectIncluded = true;
        $subjectNote = 'Bars copy stored accuracy for subjects that meet the sample rule. Stored accuracy is stored correct divided by verified answers. A missing correctness flag is excluded. This is stored scoring data, not an independently verified historical result, and it is not a difficulty ranking. Labels are alphabetical.';
    }

    $topicQualified = [];
    $topicNote = 'Topic comparison is omitted because this exam is not in subject-and-topic mode or no topic has answered items.';
    $topicIncluded = false;
    if ($breakdownMode === 'subject_topic') {
        $topicQualified = examination_monitor_analytics_chart_qualified_rows($topicRows, 'topic');
        if ($topicRows === []) {
            $topicNote = 'Topic comparison is omitted because no topic has stored answered items.';
        } elseif ($topicQualified === []) {
            $topicNote = 'Topic comparison is omitted because no topic has at least 5 answered items from at least 3 examinees. Descriptive counts stay on the Topics sheet.';
        } else {
            $topicIncluded = true;
            $topicNote = 'Bars copy stored accuracy for topics that meet the sample rule. Labels are alphabetical. This is stored scoring data, not an independently verified historical result, and it is not a difficulty ranking.';
        }
    }

    $percents = [];
    foreach ($examinees as $row) {
        if (!empty($row['graded']) && isset($row['percent']) && is_numeric($row['percent'])) {
            $percents[] = (float)$row['percent'];
        }
    }
    $histogram = examination_monitor_analytics_summary_histogram($percents);
    $scoreLabels = [];
    $scoreValues = [];
    foreach ($histogram as $bucket) {
        $scoreLabels[] = (string)$bucket['label'];
        $scoreValues[] = (int)$bucket['count'];
    }

    $sectionLabels = [];
    $sectionValues = [];
    $sectionIncluded = false;
    $sectionNote = 'Section comparison is omitted because this report is already limited to one section.';
    if ($sectionFilter === '') {
        $groups = [];
        foreach ($examinees as $row) {
            $name = trim((string)($row['section'] ?? ''));
            $key = $name === '' ? 'No section' : $name;
            if (!isset($groups[$key])) {
                $groups[$key] = ['sum' => 0.0, 'n' => 0];
            }
            if (!empty($row['graded']) && isset($row['percent']) && is_numeric($row['percent'])) {
                $groups[$key]['sum'] += (float)$row['percent'];
                $groups[$key]['n']++;
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        if (count($groups) < 2) {
            $sectionNote = 'Section comparison is omitted because the roster has fewer than two sections.';
        } else {
            foreach ($groups as $name => $group) {
                if ((int)$group['n'] <= 0) {
                    continue;
                }
                $sectionLabels[] = (string)$name;
                $sectionValues[] = round($group['sum'] / $group['n'], 1);
            }
            if (count($sectionLabels) >= 2) {
                $sectionIncluded = true;
                $sectionNote = 'Each bar is the mean exam score in that current section. Sections with no graded examinees are omitted.';
            } else {
                $sectionNote = 'Section comparison is omitted because fewer than two sections have a graded examinee.';
            }
        }
    }

    return [
        'distribution' => $distribution,
        'subjects' => [
            'included' => $subjectIncluded,
            'title' => 'Subject accuracy',
            'note' => $subjectNote,
            'labels' => array_column($subjectQualified, 'label'),
            'values' => array_column($subjectQualified, 'value'),
        ],
        'topics' => [
            'included' => $topicIncluded,
            'title' => 'Topic accuracy',
            'note' => $topicNote,
            'labels' => array_column($topicQualified, 'label'),
            'values' => array_column($topicQualified, 'value'),
        ],
        'scores' => [
            'included' => count($percents) >= 5,
            'title' => 'Score distribution',
            'note' => $percents === []
                ? 'Score distribution is omitted because there are no graded examinees.'
                : (count($percents) < 5
                    ? 'Score distribution is omitted because fewer than 5 graded examinees are available.'
                    : 'Exam scores for graded examinees only. Empty bands stay on the axis.'),
            'labels' => $scoreLabels,
            'values' => $scoreValues,
        ],
        'sections' => [
            'included' => $sectionIncluded,
            'title' => 'Section comparison',
            'note' => $sectionNote,
            'labels' => $sectionLabels,
            'values' => $sectionValues,
        ],
    ];
}

/**
 * Dashboard insights from the graded roster and the stored subject and topic rows.
 * Stored accuracy is copied from those rows. This does not rescore answers.
 *
 * A topic enters the low or high list only when at least 5 items were answered
 * and at least 3 examinees are represented. A smaller sample is not treated as
 * a class-wide result.
 *
 * @param list<array<string,mixed>> $examinees normalized rows
 * @param list<array<string,mixed>> $subjects
 * @param list<array<string,mixed>> $topics
 * @param list<array<string,mixed>>|null $examineeSubjects null when examinee coverage was not supplied
 * @param list<array<string,mixed>>|null $examineeTopics null when examinee coverage was not supplied
 * @return array<string,mixed>
 */
function examination_monitor_analytics_performance_insights(
    array $examinees,
    array $subjects,
    array $topics,
    ?array $examineeSubjects,
    ?array $examineeTopics,
    string $breakdownMode
): array {
    $disclaimer = "Stored accuracy uses stored correctness flags. A post-submission rescore can rewrite those flags.\nThese figures are not an independently verified historical result and are not confirmed question difficulty.";
    $top = examination_monitor_analytics_insight_top_students($examinees);
    $topicPack = examination_monitor_analytics_insight_topics($topics, $examineeTopics, $breakdownMode);
    $subjectPack = examination_monitor_analytics_insight_subjects($subjects, $examineeSubjects, $breakdownMode);
    $observations = examination_monitor_analytics_insight_observations($examinees, $top, $topicPack, $subjectPack);

    return [
        'graded_count' => (int)$top['graded_count'],
        'disclaimer' => $disclaimer,
        'top_students' => $top['rows'],
        'top_note' => (string)$top['note'],
        'topics_available' => !empty($topicPack['available']),
        'topic_message' => (string)$topicPack['message'],
        'topics_low' => $topicPack['low'],
        'topics_high' => $topicPack['high'],
        'subjects_available' => !empty($subjectPack['available']),
        'subject_message' => (string)$subjectPack['message'],
        'subjects' => $subjectPack['rows'],
        'observations' => $observations,
    ];
}

/**
 * @param list<array<string,mixed>> $examinees
 * @return array{graded_count:int,rows:list<array<string,mixed>>,note:string}
 */
function examination_monitor_analytics_insight_top_students(array $examinees): array
{
    $graded = [];
    foreach ($examinees as $row) {
        if (!empty($row['graded']) && isset($row['percent']) && is_numeric($row['percent'])) {
            $graded[] = $row;
        }
    }
    usort($graded, static function (array $a, array $b): int {
        $byScore = ((float)$b['percent']) <=> ((float)$a['percent']);
        if ($byScore !== 0) {
            return $byScore;
        }

        return ((int)$a['user_id']) <=> ((int)$b['user_id']);
    });
    $ranked = examination_monitor_analytics_assign_ranks($graded);
    $gradedCount = count($ranked);
    $excluded = 'Absent, in-progress, expired, and submitted examinees that are not graded are excluded.';
    if ($gradedCount === 0) {
        return [
            'graded_count' => 0,
            'rows' => [],
            'note' => 'No graded students are available for a ranking. ' . $excluded,
        ];
    }
    if ($gradedCount <= 5) {
        $shown = $ranked;
        $note = 'Ranked from ' . $gradedCount . ' graded student' . ($gradedCount === 1 ? '' : 's') . '. ';
        if ($gradedCount < 5) {
            $note .= 'Fewer than five graded students are in this scope. ';
        }
    } else {
        $cutoff = (float)$ranked[4]['percent'];
        $shown = [];
        foreach ($ranked as $row) {
            if ((float)$row['percent'] < $cutoff) {
                break;
            }
            $shown[] = $row;
        }
        $note = 'Ranked from ' . $gradedCount . ' graded students. ';
        if (count($shown) > 5) {
            $note .= 'Everyone tied with the fifth score is included, so ' . count($shown) . ' students are listed. ';
        }
    }
    $scores = [];
    foreach ($shown as $row) {
        $scores[(string)$row['percent']] = ($scores[(string)$row['percent']] ?? 0) + 1;
    }
    if (max($scores) > 1) {
        $note .= 'Tied scores share a rank. The next distinct score takes the next consecutive rank. ';
    }
    $note .= $excluded;
    $rows = [];
    foreach ($shown as $row) {
        $name = trim((string)($row['full_name'] ?? ''));
        $section = trim((string)($row['section'] ?? ''));
        $rows[] = [
            'rank' => (int)$row['rank'],
            'user_id' => (int)$row['user_id'],
            'full_name' => $name !== '' ? $name : ('Student ' . (int)$row['user_id']),
            'section' => $section !== '' ? $section : 'No section',
            'percent' => round((float)$row['percent'], 1),
            'correct' => (int)($row['correct'] ?? 0),
            'total' => (int)($row['total'] ?? 0),
        ];
    }

    return [
        'graded_count' => $gradedCount,
        'rows' => $rows,
        'note' => $note,
    ];
}

/**
 * @param list<array<string,mixed>> $topics
 * @param list<array<string,mixed>>|null $examineeTopics
 * @return array{available:bool,message:string,low:list<array<string,mixed>>,high:list<array<string,mixed>>,eligible:list<array<string,mixed>>,distinct_examinees:?int,excluded:int}
 */
function examination_monitor_analytics_insight_topics(array $topics, ?array $examineeTopics, string $breakdownMode): array
{
    $empty = [
        'available' => false,
        'message' => 'Topic comparisons are not available for this examination.',
        'low' => [],
        'high' => [],
        'eligible' => [],
        'distinct_examinees' => null,
        'excluded' => 0,
    ];
    if ($breakdownMode !== 'subject_topic') {
        return $empty;
    }
    $counts = examination_monitor_analytics_insight_examinee_counts($examineeTopics, 'topic');
    $prepared = [];
    $excluded = 0;
    foreach ($topics as $row) {
        if (!is_array($row) || !empty($row['unassigned'])) {
            continue;
        }
        $answered = (int)($row['answered'] ?? 0);
        if ($answered <= 0 || !isset($row['accuracy']) || !is_numeric($row['accuracy'])) {
            continue;
        }
        $key = examination_monitor_analytics_insight_row_key($row, 'topic');
        $examinees = examination_monitor_analytics_insight_examinee_total($row, $counts, $key);
        $name = examination_monitor_analytics_insight_topic_name($row);
        $item = [
            'key' => $key,
            'name' => $name,
            'accuracy' => round((float)$row['accuracy'], 1),
            'answered' => $answered,
            'examinees' => $examinees['count'],
            'examinees_known' => $examinees['known'],
        ];
        if (examination_monitor_analytics_insight_sample_is_sufficient($answered, $examinees)) {
            $prepared[] = $item;
        } else {
            $excluded++;
        }
    }
    $sampleRule = 'Stored accuracy. A topic is listed only when at least 5 items were answered and at least 3 examinees are represented. A smaller sample is not treated as a class-wide result.';
    if ($prepared === [] && $excluded === 0) {
        $empty['message'] = 'No topic performance data is available. ' . $sampleRule;

        return $empty;
    }
    if ($prepared === []) {
        return [
            'available' => false,
            'message' => 'Topic rankings are omitted because every stored sample is too small. ' . $sampleRule,
            'low' => [],
            'high' => [],
            'eligible' => [],
            'distinct_examinees' => examination_monitor_analytics_insight_distinct_examinees($examineeTopics, 'topic', []),
            'excluded' => $excluded,
        ];
    }
    usort($prepared, static function (array $a, array $b): int {
        $byAccuracy = ((float)$a['accuracy']) <=> ((float)$b['accuracy']);
        if ($byAccuracy !== 0) {
            return $byAccuracy;
        }

        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    $message = $sampleRule;
    if (count($prepared) === 1) {
        $only = $prepared[0];
        $message = 'Only one topic has a large enough stored sample: ' . $only['name'] . '. Stored accuracy is ' . number_format((float)$only['accuracy'], 1) . '% from ' . (int)$only['answered'] . ' answered items and ' . examination_monitor_analytics_insight_examinee_phrase($only) . '. It is not ranked as a high or low result by itself. ' . $sampleRule;
        if ($excluded > 0) {
            $message .= ' ' . $excluded . ' other topic' . ($excluded === 1 ? '' : 's') . ' with stored answers ' . ($excluded === 1 ? 'was' : 'were') . ' left out because the sample is too small.';
        }

        return [
            'available' => false,
            'message' => $message,
            'low' => [],
            'high' => [],
            'eligible' => $prepared,
            'distinct_examinees' => examination_monitor_analytics_insight_distinct_examinees($examineeTopics, 'topic', $prepared),
            'excluded' => $excluded,
        ];
    }
    $count = count($prepared);
    $lowTake = min(3, intdiv($count, 2));
    $highTake = min(3, $count - $lowTake);
    $low = examination_monitor_analytics_insight_accuracy_edge($prepared, $lowTake, true);
    $lowKeys = [];
    foreach ($low as $row) {
        $lowKeys[(string)$row['key']] = true;
    }
    $high = [];
    foreach (examination_monitor_analytics_insight_accuracy_edge($prepared, $highTake, false) as $row) {
        if (isset($lowKeys[(string)$row['key']])) {
            continue;
        }
        $high[] = $row;
    }
    if ($excluded > 0) {
        $message .= ' ' . $excluded . ' topic' . ($excluded === 1 ? '' : 's') . ' with stored answers ' . ($excluded === 1 ? 'was' : 'were') . ' left out because the sample is too small.';
    }
    $listed = count($low) + count($high);
    if ($listed < $count) {
        $between = $count - $listed;
        $message .= ' ' . $between . ' topic' . ($between === 1 ? ' sits' : 's sit') . ' between these groups and ' . ($between === 1 ? 'is' : 'are') . ' not listed here.';
    }
    if ($high === []) {
        $message .= ' The highest list is omitted because those topics are already in the lowest list.';
    }

    return [
        'available' => true,
        'message' => $message,
        'low' => $low,
        'high' => $high,
        'eligible' => $prepared,
        'distinct_examinees' => examination_monitor_analytics_insight_distinct_examinees($examineeTopics, 'topic', $prepared),
        'excluded' => $excluded,
    ];
}

/**
 * @param list<array<string,mixed>> $subjects
 * @param list<array<string,mixed>>|null $examineeSubjects
 * @return array{available:bool,message:string,rows:list<array<string,mixed>>}
 */
function examination_monitor_analytics_insight_subjects(array $subjects, ?array $examineeSubjects, string $breakdownMode): array
{
    if ($breakdownMode !== 'subject' && $breakdownMode !== 'subject_topic') {
        return [
            'available' => false,
            'message' => 'Subject comparisons are not available for this examination.',
            'rows' => [],
        ];
    }
    $counts = examination_monitor_analytics_insight_examinee_counts($examineeSubjects, 'subject');
    $rows = [];
    foreach ($subjects as $row) {
        if (!is_array($row) || !empty($row['unassigned'])) {
            continue;
        }
        $answered = (int)($row['answered'] ?? 0);
        if ($answered <= 0 || !isset($row['accuracy']) || !is_numeric($row['accuracy'])) {
            continue;
        }
        $key = examination_monitor_analytics_insight_row_key($row, 'subject');
        $examinees = examination_monitor_analytics_insight_examinee_total($row, $counts, $key);
        $accuracy = round((float)$row['accuracy'], 1);
        $sufficient = examination_monitor_analytics_insight_sample_is_sufficient($answered, $examinees);
        $note = '';
        if (!$sufficient) {
            $note = 'Limited sample. Not a confirmed class-wide result.';
        } elseif ($accuracy < 50.0) {
            $note = 'Lower stored accuracy.';
        }
        $name = trim((string)($row['name'] ?? ''));
        $rows[] = [
            'key' => $key,
            'name' => $name !== '' ? $name : 'Subject',
            'accuracy' => $accuracy,
            'answered' => $answered,
            'examinees' => $examinees['count'],
            'examinees_known' => $examinees['known'],
            'note' => $note,
            'sufficient' => $sufficient,
        ];
    }
    if ($rows === []) {
        return [
            'available' => false,
            'message' => 'No subject stored-accuracy figures are available.',
            'rows' => [],
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $byAccuracy = ((float)$a['accuracy']) <=> ((float)$b['accuracy']);
        if ($byAccuracy !== 0) {
            return $byAccuracy;
        }

        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    $total = count($rows);
    $shown = array_slice($rows, 0, 6);
    $message = 'Stored accuracy, lowest first. A lower figure is described with its sample size. Fewer than 5 answered items or fewer than 3 examinees is a limited sample, not a confirmed class-wide result.';
    if ($total > count($shown)) {
        $message .= ' Showing ' . count($shown) . ' of ' . $total . ' subjects.';
    }

    return [
        'available' => true,
        'message' => $message,
        'rows' => $shown,
    ];
}

/**
 * @param array{graded_count:int,rows:list<array<string,mixed>>,note:string} $top
 * @param array{available:bool,message:string,low:list<array<string,mixed>>,high:list<array<string,mixed>>,eligible:list<array<string,mixed>>,distinct_examinees:?int,excluded:int} $topicPack
 * @param array{available:bool,message:string,rows:list<array<string,mixed>>} $subjectPack
 * @param list<array<string,mixed>> $examinees
 * @return list<string>
 */
function examination_monitor_analytics_insight_observations(array $examinees, array $top, array $topicPack, array $subjectPack): array
{
    $assigned = count($examinees);
    $graded = (int)$top['graded_count'];
    $lines = [];
    if ($graded === 0) {
        $lines[] = 'No examinees have graded results.';
    } elseif ($graded < $assigned) {
        $lines[] = 'Only ' . $graded . ' ' . ($graded === 1 ? 'examinee has' : 'examinees have') . ' graded results.';
    } else {
        $lines[] = $graded . ' ' . ($graded === 1 ? 'examinee has' : 'examinees have') . ' graded results.';
    }
    if ($graded > 0 && !examination_monitor_analytics_graded_sample_is_sufficient($graded)) {
        $lines[] = 'The graded sample is too small for a class-level conclusion.';
    }
    $winners = [];
    $best = null;
    foreach ($top['rows'] as $row) {
        $percent = (float)$row['percent'];
        if ($best === null || $percent > $best) {
            $best = $percent;
            $winners = [$row];
            continue;
        }
        if ($percent === $best) {
            $winners[] = $row;
        }
    }
    if ($best !== null && $winners !== []) {
        $names = [];
        foreach ($winners as $row) {
            $names[] = (string)$row['full_name'];
        }
        if ($graded === 1) {
            $paper = $winners[0];
            $lines[] = 'The individual exam score was ' . number_format($best, 1) . '% (' . (int)($paper['correct'] ?? 0) . ' of ' . (int)($paper['total'] ?? 0) . '), achieved by ' . (string)$paper['full_name'] . '.';
        } elseif (!examination_monitor_analytics_graded_sample_is_sufficient($graded)) {
            $listed = [];
            foreach ($top['rows'] as $row) {
                $listed[] = (string)$row['full_name'] . ' ' . number_format((float)$row['percent'], 1) . '% (' . (int)($row['correct'] ?? 0) . ' of ' . (int)($row['total'] ?? 0) . ')';
            }
            $lines[] = 'Individual exam scores: ' . implode('; ', $listed) . '.';
        } else {
            $lines[] = 'The highest recorded score was ' . number_format($best, 1) . '%, achieved by ' . examination_monitor_analytics_insight_name_list($names) . '.';
        }
    }
    $eligible = $topicPack['eligible'];
    if ($eligible !== []) {
        $lowestAccuracy = (float)$eligible[0]['accuracy'];
        $lowest = [];
        foreach ($eligible as $row) {
            if ((float)$row['accuracy'] !== $lowestAccuracy) {
                break;
            }
            $lowest[] = $row;
        }
        if (count($eligible) >= 2) {
            $lines[] = 'The lowest stored topic accuracy was ' . number_format($lowestAccuracy, 1) . '%, recorded in ' . examination_monitor_analytics_insight_topic_record($lowest) . '.';
        }
        $answered = 0;
        foreach ($eligible as $row) {
            $answered += (int)$row['answered'];
        }
        $basis = 'Topic observations are based on ' . $answered . ' answered items';
        if ($topicPack['distinct_examinees'] !== null) {
            $basis .= ' from ' . (int)$topicPack['distinct_examinees'] . ' ' . ((int)$topicPack['distinct_examinees'] === 1 ? 'examinee' : 'examinees');
        }
        $lines[] = $basis . '.';
    }
    if ((int)$topicPack['excluded'] > 0 && $topicPack['eligible'] === []) {
        $lines[] = 'Topic rankings are omitted because the stored samples are too small to support a class comparison.';
    }
    $subjectRows = $subjectPack['rows'];
    $limitedSubject = null;
    $qualifiedSubject = null;
    foreach ($subjectRows as $subject) {
        if (!is_array($subject)) {
            continue;
        }
        if (empty($subject['sufficient']) && $limitedSubject === null) {
            $limitedSubject = $subject;
        }
        if (!empty($subject['sufficient']) && $qualifiedSubject === null) {
            $qualifiedSubject = $subject;
        }
    }
    if ($limitedSubject !== null) {
        $lines[] = 'Descriptive data only: ' . (string)$limitedSubject['name'] . ' stored accuracy is ' . number_format((float)$limitedSubject['accuracy'], 1) . '% from ' . (int)$limitedSubject['answered'] . ' answered items and ' . examination_monitor_analytics_insight_examinee_phrase($limitedSubject) . '. The sample is too small for a class-level conclusion.';
    }
    if ($qualifiedSubject !== null) {
        $lines[] = 'The lowest stored subject accuracy among groups that meet the sample rule was recorded in ' . (string)$qualifiedSubject['name'] . ' (' . number_format((float)$qualifiedSubject['accuracy'], 1) . '% stored accuracy, ' . (int)$qualifiedSubject['answered'] . ' answered items, ' . examination_monitor_analytics_insight_examinee_phrase($qualifiedSubject) . ').';
    }
    $generatedReviews = 0;
    $identityReviews = 0;
    foreach ($examinees as $row) {
        $review = (string)($row['review'] ?? '');
        if (str_contains($review, 'Review generated account')) {
            $generatedReviews++;
        }
        if (str_contains($review, 'Review duplicate name') || str_contains($review, 'Review missing student number') || str_contains($review, 'Review duplicate student number')) {
            $identityReviews++;
        }
    }
    if ($generatedReviews > 0) {
        $lines[] = $generatedReviews . ' roster ' . ($generatedReviews === 1 ? 'row is' : 'rows are') . ' flagged for manual review as possible generated accounts. Flagged rows remain in the assigned count.';
    }
    if ($identityReviews > 0) {
        $lines[] = $identityReviews . ' roster ' . ($identityReviews === 1 ? 'row is' : 'rows are') . ' flagged for a duplicate name, a missing student number, or a duplicate student number. Flagged rows remain in the assigned count.';
    }

    return $lines;
}

/**
 * @param list<array<string,mixed>> $rows sorted by accuracy ascending
 * @return list<array<string,mixed>>
 */
function examination_monitor_analytics_insight_accuracy_edge(array $rows, int $take, bool $lowest): array
{
    if ($rows === [] || $take <= 0) {
        return [];
    }
    $pool = $lowest ? array_values($rows) : array_reverse(array_values($rows));
    $index = min($take, count($pool)) - 1;
    $cutoff = (float)$pool[$index]['accuracy'];
    $out = [];
    foreach ($pool as $row) {
        $accuracy = (float)$row['accuracy'];
        if ($lowest && $accuracy > $cutoff) {
            break;
        }
        if (!$lowest && $accuracy < $cutoff) {
            break;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * @param array{count:?int,known:bool} $examinees
 */
function examination_monitor_analytics_insight_sample_is_sufficient(int $answered, array $examinees): bool
{
    return $answered >= 5 && !empty($examinees['known']) && (int)$examinees['count'] >= 3;
}

/**
 * @param list<array<string,mixed>>|null $rows
 * @return array<string,int>|null
 */
function examination_monitor_analytics_insight_examinee_counts(?array $rows, string $kind): ?array
{
    if ($rows === null) {
        return null;
    }
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = examination_monitor_analytics_insight_row_key($row, $kind);
        $userId = (int)($row['user_id'] ?? 0);
        if ($key === '' || $userId <= 0 || (int)($row['answered'] ?? 0) <= 0) {
            continue;
        }
        $ids[$key][$userId] = true;
    }
    $counts = [];
    foreach ($ids as $key => $users) {
        $counts[$key] = count($users);
    }

    return $counts;
}

/**
 * @param array<string,mixed> $row
 * @param array<string,int>|null $counts
 * @return array{count:?int,known:bool}
 */
function examination_monitor_analytics_insight_examinee_total(array $row, ?array $counts, string $key): array
{
    if ($counts !== null) {
        return ['count' => (int)($counts[$key] ?? 0), 'known' => true];
    }
    if (isset($row['examinees']) && is_numeric($row['examinees'])) {
        return ['count' => max(0, (int)$row['examinees']), 'known' => true];
    }
    if (isset($row['examinee_count']) && is_numeric($row['examinee_count'])) {
        return ['count' => max(0, (int)$row['examinee_count']), 'known' => true];
    }

    return ['count' => null, 'known' => false];
}

/**
 * @param list<array<string,mixed>>|null $rows
 * @param list<array<string,mixed>> $eligible
 */
function examination_monitor_analytics_insight_distinct_examinees(?array $rows, string $kind, array $eligible): ?int
{
    if ($rows === null) {
        if ($eligible === []) {
            return null;
        }
        $known = true;
        $sum = 0;
        foreach ($eligible as $row) {
            if (empty($row['examinees_known']) || !isset($row['examinees']) || !is_numeric($row['examinees'])) {
                $known = false;
                break;
            }
            $sum += (int)$row['examinees'];
        }
        if (!$known || count($eligible) !== 1) {
            return null;
        }

        return $sum;
    }
    if ($eligible === []) {
        return 0;
    }
    $keys = [];
    foreach ($eligible as $row) {
        $keys[(string)$row['key']] = true;
    }
    $users = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = examination_monitor_analytics_insight_row_key($row, $kind);
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0 || !isset($keys[$key]) || (int)($row['answered'] ?? 0) <= 0) {
            continue;
        }
        $users[$userId] = true;
    }

    return count($users);
}

/**
 * @param array<string,mixed> $row
 */
function examination_monitor_analytics_insight_row_key(array $row, string $kind): string
{
    if ($kind === 'topic') {
        $key = trim((string)($row['key'] ?? $row['topic_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        $name = trim((string)($row['name'] ?? $row['topic_name'] ?? ''));

        return $name === '' ? '' : 'name:' . strtolower($name);
    }
    $key = trim((string)($row['key'] ?? $row['subject_key'] ?? ''));
    if ($key !== '') {
        return $key;
    }
    $name = trim((string)($row['name'] ?? $row['subject_name'] ?? ''));

    return $name === '' ? '' : 'name:' . strtolower($name);
}

/**
 * @param array<string,mixed> $row
 */
function examination_monitor_analytics_insight_topic_name(array $row): string
{
    $name = trim((string)($row['name'] ?? $row['topic_name'] ?? ''));
    if ($name === '') {
        $name = 'Topic';
    }
    $subject = trim((string)($row['subject_name'] ?? ''));
    if ($subject !== '' && strcasecmp($subject, $name) !== 0) {
        return $subject . ' · ' . $name;
    }

    return $name;
}

/**
 * @param array<string,mixed> $row
 */
function examination_monitor_analytics_insight_examinee_phrase(array $row): string
{
    if (empty($row['examinees_known']) || !isset($row['examinees']) || !is_numeric($row['examinees'])) {
        return 'an unknown number of examinees';
    }
    $count = (int)$row['examinees'];

    return $count . ' ' . ($count === 1 ? 'examinee' : 'examinees');
}

/**
 * @param list<array<string,mixed>> $rows
 */
function examination_monitor_analytics_insight_topic_record(array $rows): string
{
    $parts = [];
    foreach ($rows as $row) {
        $parts[] = (string)$row['name'] . ' (' . (int)$row['answered'] . ' answered items, ' . examination_monitor_analytics_insight_examinee_phrase($row) . ')';
    }

    return examination_monitor_analytics_insight_join($parts);
}

/**
 * @param list<string> $parts
 */
function examination_monitor_analytics_insight_join(array $parts): string
{
    $parts = array_values(array_filter($parts, static function (string $part): bool {
        return $part !== '';
    }));
    $count = count($parts);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return $parts[0];
    }
    if ($count === 2) {
        return $parts[0] . ' and ' . $parts[1];
    }
    $last = array_pop($parts);

    return implode(', ', $parts) . ', and ' . $last;
}

/**
 * @param list<string> $names
 */
function examination_monitor_analytics_insight_name_list(array $names): string
{
    $names = array_values(array_filter($names, static function (string $name): bool {
        return $name !== '';
    }));
    $count = count($names);
    if ($count === 0) {
        return 'a graded student';
    }
    if ($count === 1) {
        return $names[0];
    }
    if ($count === 2) {
        return $names[0] . ' and ' . $names[1];
    }
    if ($count === 3) {
        return $names[0] . ', ' . $names[1] . ', and ' . $names[2];
    }
    $extra = $count - 3;

    return $names[0] . ', ' . $names[1] . ', ' . $names[2] . ', and ' . $extra . ' other graded student' . ($extra === 1 ? '' : 's') . ' with the same score';
}

/**
 * @param list<array<string,mixed>> $rawExaminees
 * @param list<array<string,mixed>> $responses
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function examination_monitor_analytics_build_report(array $rawExaminees, array $responses, array $options = []): array
{
    $subjects = is_array($options['subjects'] ?? null) ? $options['subjects'] : [];
    $topics = is_array($options['topics'] ?? null) ? $options['topics'] : [];
    $breakdownMode = trim((string)($options['breakdown_mode'] ?? 'overall'));
    $examineeSubjects = array_key_exists('examinee_subjects', $options) && is_array($options['examinee_subjects']) ? $options['examinee_subjects'] : null;
    $examineeTopics = array_key_exists('examinee_topics', $options) && is_array($options['examinee_topics']) ? $options['examinee_topics'] : null;
    if (!empty($options['section_invalid'])) {
        $summary = examination_monitor_analytics_performance_summary([]);

        return [
            'examinees' => [],
            'summary' => $summary,
            'rankings' => examination_monitor_analytics_rankings([]),
            'difficulty' => examination_monitor_analytics_question_difficulty([], []),
            'charts' => examination_monitor_analytics_chart_plan($summary, [], [], [], '', 'overall', true),
            'insights' => examination_monitor_analytics_performance_insights([], [], [], [], [], 'overall'),
            'rejected' => true,
            'rejection' => examination_monitor_analytics_invalid_section_message(),
        ];
    }
    $examinees = examination_monitor_analytics_apply_roster_review(
        examination_monitor_analytics_normalize_examinees($rawExaminees)
    );
    $summary = examination_monitor_analytics_performance_summary($examinees);
    $rankings = examination_monitor_analytics_rankings($examinees);
    $coverage = is_array($options['coverage'] ?? null) ? $options['coverage'] : [];
    $difficulty = examination_monitor_analytics_question_difficulty($responses, $coverage);
    $charts = examination_monitor_analytics_chart_plan(
        $summary,
        $examinees,
        $subjects,
        $topics,
        trim((string)($options['section_filter'] ?? '')),
        $breakdownMode,
        !empty($options['section_invalid'])
    );

    return [
        'examinees' => $examinees,
        'summary' => $summary,
        'rankings' => $rankings,
        'difficulty' => $difficulty,
        'charts' => $charts,
        'insights' => examination_monitor_analytics_performance_insights($examinees, $subjects, $topics, $examineeSubjects, $examineeTopics, $breakdownMode),
    ];
}
