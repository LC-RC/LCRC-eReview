<?php
/**
 * Regular college-exam item analytics (professor monitor, reporting only).
 * Does not touch take-exam, save, finalize, or diagnostic tables.
 */
declare(strict_types=1);

require_once __DIR__ . '/examination_monitor_helpers.php';
require_once __DIR__ . '/college_exam_attempt_paper.php';

function examination_monitor_analytics_pct(?int $numerator, int $denominator): ?float
{
    if ($denominator <= 0) {
        return null;
    }

    return round(100.0 * (float)max(0, (int)$numerator) / (float)$denominator, 1);
}

function examination_monitor_analytics_pct_label(?float $pct): string
{
    return $pct === null ? '—' : number_format($pct, 1) . '%';
}

/**
 * @return array{presented:int,answered:int,correct:int,incorrect:int,unanswered:int,accuracy:?float,incorrect_rate:?float,unanswered_rate:?float}
 */
function examination_monitor_analytics_rates(int $presented, int $answered, int $correct, int $incorrect): array
{
    $presented = max(0, $presented);
    $answered = max(0, $answered);
    $correct = max(0, $correct);
    $incorrect = max(0, $incorrect);
    if ($answered > $presented) {
        $answered = $presented;
    }
    $unanswered = max(0, $presented - $answered);

    return [
        'presented' => $presented,
        'answered' => $answered,
        'correct' => $correct,
        'incorrect' => $incorrect,
        'unanswered' => $unanswered,
        'accuracy' => examination_monitor_analytics_pct($correct, $answered),
        'incorrect_rate' => examination_monitor_analytics_pct($incorrect, $answered),
        'unanswered_rate' => examination_monitor_analytics_pct($unanswered, $presented),
    ];
}

function examination_monitor_analytics_group_key(int $id, string $name, string $unassignedLabel): array
{
    $name = trim($name);
    if ($id <= 0 && $name === '') {
        return ['key' => 'unassigned', 'id' => 0, 'name' => $unassignedLabel, 'unassigned' => true];
    }
    if ($id > 0) {
        return ['key' => 'id:' . $id, 'id' => $id, 'name' => ($name !== '' ? $name : $unassignedLabel), 'unassigned' => false];
    }

    return ['key' => 'name:' . mb_strtolower($name), 'id' => 0, 'name' => $name, 'unassigned' => false];
}

function examination_monitor_analytics_plain_text(string $html): string
{
    $html = preg_replace('/<\s*br\s*\/?\s*>/i', ' ', $html) ?? $html;
    $html = preg_replace('/<\s*\/\s*(p|div|h[1-6]|li|tr|blockquote|table)\s*>/i', ' ', $html) ?? $html;
    $plain = strip_tags($html);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);

    return $plain;
}

function examination_monitor_analytics_stem(string $text, int $max = 110): string
{
    $text = examination_monitor_analytics_plain_text($text);
    if ($text === '') {
        return 'Question';
    }
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, $max, 'UTF-8') . '…';
    }
    if (strlen($text) > $max) {
        return substr($text, 0, $max) . '…';
    }

    return $text;
}

/**
 * @return list<array{label:string,min:int,max:int,count:int}>
 */
function examination_monitor_analytics_empty_histogram(): array
{
    $out = [];
    for ($i = 0; $i < 9; $i++) {
        $out[] = [
            'label' => (string)($i * 10) . '–' . (string)($i * 10 + 9),
            'min' => $i * 10,
            'max' => $i * 10 + 9,
            'count' => 0,
        ];
    }
    $out[] = ['label' => '90–100', 'min' => 90, 'max' => 100, 'count' => 0];

    return $out;
}

/**
 * @param list<float|int|null> $scores
 * @return list<array{label:string,min:int,max:int,count:int}>
 */
function examination_monitor_analytics_histogram_from_scores(array $scores): array
{
    $buckets = examination_monitor_analytics_empty_histogram();
    foreach ($scores as $raw) {
        if ($raw === null || !is_numeric($raw)) {
            continue;
        }
        $v = (int)round((float)$raw);
        $v = max(0, min(100, $v));
        $idx = $v >= 90 ? 9 : intdiv($v, 10);
        $buckets[$idx]['count']++;
    }

    return $buckets;
}

/**
 * @return array<string,mixed>
 */
function examination_monitor_item_analytics(mysqli $conn, int $examId, string $sectionFilter = ''): array
{
    require_once __DIR__ . '/examination_monitor_helpers.php';
    $examId = (int)$examId;
    $sectionFilter = examination_monitor_normalize_section_filter($sectionFilter);
    $emptyHist = examination_monitor_analytics_empty_histogram();
    $zeroRates = examination_monitor_analytics_rates(0, 0, 0, 0);
    $base = [
        'exam_id' => $examId,
        'section_filter' => $sectionFilter,
        'breakdown_mode' => 'overall',
        'has_snapshots' => false,
        'has_topics' => false,
        'has_uncategorized' => false,
        'submitted_count' => 0,
        'taking_count' => 0,
        'avg_score' => null,
        'pass_count' => 0,
        'fail_count' => 0,
        'passing_rate' => null,
        'passing_rate_label' => '—',
        'weakest_subject' => null,
        'histogram' => $emptyHist,
        'subjects' => [],
        'topics' => [],
        'questions' => [],
        'section_comparisons' => [],
        'weak_subjects' => [],
        'weak_topics' => [],
        'weak_questions' => [],
        'examinee_subjects' => [],
        'examinee_topics' => [],
        'weak_examinee_topics' => [],
        'totals' => $zeroRates,
        'empty' => [
            'no_exam' => $examId <= 0,
            'no_submitted' => true,
            'no_snapshots' => true,
            'overall_mode' => true,
            'zero_answered' => true,
            'no_section_data' => false,
        ],
    ];
    if ($examId <= 0) {
        return $base;
    }

    $userIds = examination_monitor_college_reporting_user_ids($conn, $examId, $sectionFilter);
    $metrics = examination_monitor_metrics_for_college_exam($conn, $examId, $sectionFilter);
    $base['submitted_count'] = (int)($metrics['submitted_count'] ?? 0);
    $base['taking_count'] = (int)($metrics['taking_count'] ?? 0);
    $base['avg_score'] = $metrics['avg_score'] !== null ? (float)$metrics['avg_score'] : null;
    $base['pass_count'] = (int)($metrics['pass_count'] ?? 0);
    $base['fail_count'] = (int)($metrics['fail_count'] ?? 0);
    $base['passing_rate'] = examination_monitor_analytics_pct($base['pass_count'], $base['submitted_count']);
    $base['passing_rate_label'] = examination_monitor_analytics_pct_label($base['passing_rate']);
    $base['empty']['no_section_data'] = ($sectionFilter !== '' && $base['submitted_count'] <= 0);

    $mode = 'overall';
    $mq = @mysqli_query($conn, 'SELECT question_breakdown_mode FROM college_exams WHERE exam_id=' . $examId . ' LIMIT 1');
    if ($mq && ($mr = mysqli_fetch_assoc($mq))) {
        $mode = strtolower(trim((string)($mr['question_breakdown_mode'] ?? 'overall')));
        if ($mode !== 'subject' && $mode !== 'subject_topic') {
            $mode = 'overall';
        }
    }
    if ($mq) {
        mysqli_free_result($mq);
    }
    $base['breakdown_mode'] = $mode;
    $base['empty']['overall_mode'] = ($mode === 'overall');

    $inUsers = $userIds !== [] ? implode(',', array_map('intval', $userIds)) : '0';
    $scoreSql = "
      SELECT
        CASE WHEN IFNULL(total_count,0) > 0
          THEN ROUND(100.0 * COALESCE(correct_count,0) / total_count, 0)
          WHEN score IS NOT NULL THEN score
          ELSE NULL END AS pct
      FROM college_exam_attempts
      WHERE exam_id=" . $examId . "
        AND status='submitted'
        AND user_id IN ({$inUsers})
    ";
    $scores = [];
    if ($userIds !== []) {
        $sq = @mysqli_query($conn, $scoreSql);
        if ($sq) {
            while ($sr = mysqli_fetch_assoc($sq)) {
                if ($sr['pct'] !== null && $sr['pct'] !== '') {
                    $scores[] = (float)$sr['pct'];
                }
            }
            mysqli_free_result($sq);
        }
    }
    $base['histogram'] = examination_monitor_analytics_histogram_from_scores($scores);
    $base['empty']['no_submitted'] = ($base['submitted_count'] <= 0);

    if ($userIds === [] || $base['submitted_count'] <= 0 || !college_exam_attempt_questions_table_ready($conn)) {
        $base['empty']['no_snapshots'] = true;
        $base['empty']['zero_answered'] = true;

        return $base;
    }

    $answeredExpr = "UPPER(TRIM(COALESCE(ans.selected_answer,''))) IN ('A','B','C','D')";
    $sql = "
      SELECT
        aq.question_id AS question_id,
        IFNULL(aq.exam_subject_id, 0) AS exam_subject_id,
        IFNULL(aq.exam_topic_id, 0) AS exam_topic_id,
        TRIM(COALESCE(aq.subject_name,'')) AS subject_name,
        TRIM(COALESCE(aq.topic_name,'')) AS topic_name,
        TRIM(COALESCE(u.section,'')) AS student_section,
        MAX(q.question_text) AS question_text,
        COUNT(*) AS presented,
        SUM(CASE WHEN {$answeredExpr} THEN 1 ELSE 0 END) AS answered,
        SUM(CASE WHEN {$answeredExpr} AND ans.is_correct = 1 THEN 1 ELSE 0 END) AS correct,
        SUM(CASE WHEN {$answeredExpr} AND IFNULL(ans.is_correct,0) <> 1 THEN 1 ELSE 0 END) AS incorrect
      FROM college_exam_attempt_questions aq
      INNER JOIN college_exam_attempts a ON a.attempt_id = aq.attempt_id
      INNER JOIN users u ON u.user_id = a.user_id
      LEFT JOIN college_exam_answers ans
        ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
      LEFT JOIN college_exam_questions q ON q.question_id = aq.question_id
      WHERE a.exam_id = " . $examId . "
        AND a.status = 'submitted'
        AND a.user_id IN ({$inUsers})
      GROUP BY aq.question_id, aq.exam_subject_id, aq.exam_topic_id, aq.subject_name, aq.topic_name, student_section
    ";
    $res = @mysqli_query($conn, $sql);
    $raw = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $raw[] = $row;
        }
        mysqli_free_result($res);
    }
    $base['has_snapshots'] = $raw !== [];
    $base['empty']['no_snapshots'] = !$base['has_snapshots'];
    if ($raw === []) {
        $base['empty']['zero_answered'] = true;

        return $base;
    }

    $subjects = [];
    $topics = [];
    $questions = [];
    $sectionItems = [];
    $totP = $totA = $totC = $totI = 0;
    $hasTopics = false;
    $hasUncat = false;

    foreach ($raw as $row) {
        $qid = (int)($row['question_id'] ?? 0);
        $p = (int)($row['presented'] ?? 0);
        $a = (int)($row['answered'] ?? 0);
        $c = (int)($row['correct'] ?? 0);
        $i = (int)($row['incorrect'] ?? 0);
        $totP += $p;
        $totA += $a;
        $totC += $c;
        $totI += $i;
        $subjMeta = examination_monitor_analytics_group_key(
            (int)($row['exam_subject_id'] ?? 0),
            (string)($row['subject_name'] ?? ''),
            'Unassigned'
        );
        $topicMeta = examination_monitor_analytics_group_key(
            (int)($row['exam_topic_id'] ?? 0),
            (string)($row['topic_name'] ?? ''),
            'Unassigned'
        );
        if ($subjMeta['unassigned'] || $topicMeta['unassigned']) {
            $hasUncat = true;
        }
        if (!$topicMeta['unassigned']) {
            $hasTopics = true;
        }
        $secRaw = trim((string)($row['student_section'] ?? ''));
        $secKey = $secRaw === '' ? '__none__' : $secRaw;
        $secLabel = $secRaw === '' ? 'No section' : $secRaw;

        if (!isset($questions[$qid])) {
            $rawHtml = (string)($row['question_text'] ?? '');
            $questions[$qid] = [
                'question_id' => $qid,
                'question_html' => $rawHtml,
                'stem_plain' => examination_monitor_analytics_plain_text($rawHtml),
                'stem' => examination_monitor_analytics_stem($rawHtml),
                'subject_key' => $subjMeta['key'],
                'subject_name' => $subjMeta['name'],
                'topic_key' => $subjMeta['key'] . '|' . $topicMeta['key'],
                'topic_name' => $topicMeta['name'],
                'presented' => 0,
                'answered' => 0,
                'correct' => 0,
                'incorrect' => 0,
            ];
        }
        $questions[$qid]['presented'] += $p;
        $questions[$qid]['answered'] += $a;
        $questions[$qid]['correct'] += $c;
        $questions[$qid]['incorrect'] += $i;

        if (!isset($subjects[$subjMeta['key']])) {
            $subjects[$subjMeta['key']] = [
                'key' => $subjMeta['key'],
                'exam_subject_id' => $subjMeta['id'],
                'name' => $subjMeta['name'],
                'unassigned' => $subjMeta['unassigned'],
                'presented' => 0,
                'answered' => 0,
                'correct' => 0,
                'incorrect' => 0,
            ];
        }
        $subjects[$subjMeta['key']]['presented'] += $p;
        $subjects[$subjMeta['key']]['answered'] += $a;
        $subjects[$subjMeta['key']]['correct'] += $c;
        $subjects[$subjMeta['key']]['incorrect'] += $i;

        $topicFullKey = $subjMeta['key'] . '|' . $topicMeta['key'];
        if (!isset($topics[$topicFullKey])) {
            $topics[$topicFullKey] = [
                'key' => $topicFullKey,
                'topic_key' => $topicMeta['key'],
                'exam_topic_id' => $topicMeta['id'],
                'name' => $topicMeta['name'],
                'subject_key' => $subjMeta['key'],
                'subject_name' => $subjMeta['name'],
                'unassigned' => $topicMeta['unassigned'],
                'presented' => 0,
                'answered' => 0,
                'correct' => 0,
                'incorrect' => 0,
            ];
        }
        $topics[$topicFullKey]['presented'] += $p;
        $topics[$topicFullKey]['answered'] += $a;
        $topics[$topicFullKey]['correct'] += $c;
        $topics[$topicFullKey]['incorrect'] += $i;

        if (!isset($sectionItems[$secKey])) {
            $sectionItems[$secKey] = [
                'key' => $secKey,
                'name' => $secLabel,
                'presented' => 0,
                'answered' => 0,
                'correct' => 0,
                'incorrect' => 0,
            ];
        }
        $sectionItems[$secKey]['presented'] += $p;
        $sectionItems[$secKey]['answered'] += $a;
        $sectionItems[$secKey]['correct'] += $c;
        $sectionItems[$secKey]['incorrect'] += $i;
    }

    $finish = static function (array $row): array {
        $rates = examination_monitor_analytics_rates(
            (int)$row['presented'],
            (int)$row['answered'],
            (int)$row['correct'],
            (int)$row['incorrect']
        );
        $merged = array_merge($row, $rates);
        $answered = (int)$rates['answered'];
        $acc = $rates['accuracy'];
        $merged['weak'] = $answered >= 3 && $acc !== null && $acc < 50.0;
        $merged['accuracy_label'] = examination_monitor_analytics_pct_label($rates['accuracy']);
        $merged['incorrect_rate_label'] = examination_monitor_analytics_pct_label($rates['incorrect_rate']);
        $merged['unanswered_rate_label'] = examination_monitor_analytics_pct_label($rates['unanswered_rate']);

        return $merged;
    };

    $subjectList = [];
    foreach ($subjects as $s) {
        $subjectList[] = $finish($s);
    }
    usort($subjectList, static function ($a, $b) {
        $aa = $a['accuracy'];
        $bb = $b['accuracy'];
        if ($aa === null && $bb === null) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        }
        if ($aa === null) {
            return 1;
        }
        if ($bb === null) {
            return -1;
        }
        if ($aa === $bb) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        }

        return $aa <=> $bb;
    });

    $topicList = [];
    foreach ($topics as $t) {
        $topicList[] = $finish($t);
    }
    usort($topicList, static function ($a, $b) {
        $ia = $a['incorrect_rate'];
        $ib = $b['incorrect_rate'];
        if ($ia === $ib) {
            return ((int)$b['answered']) <=> ((int)$a['answered']);
        }
        if ($ia === null) {
            return 1;
        }
        if ($ib === null) {
            return -1;
        }

        return $ib <=> $ia;
    });

    $questionList = [];
    foreach ($questions as $q) {
        $questionList[] = $finish($q);
    }
    usort($questionList, static function ($a, $b) {
        $ia = $a['incorrect_rate'];
        $ib = $b['incorrect_rate'];
        if ($ia === $ib) {
            if ((int)$a['answered'] === (int)$b['answered']) {
                return ((int)$a['question_id']) <=> ((int)$b['question_id']);
            }

            return ((int)$b['answered']) <=> ((int)$a['answered']);
        }
        if ($ia === null) {
            return 1;
        }
        if ($ib === null) {
            return -1;
        }

        return $ib <=> $ia;
    });

    $sectionList = [];
    if ($sectionFilter === '') {
        $secScoreSql = "
          SELECT TRIM(COALESCE(u.section,'')) AS student_section,
            SUM(CASE WHEN a.status='submitted' THEN 1 ELSE 0 END) AS submitted_count,
            AVG(CASE WHEN a.status='submitted' AND IFNULL(a.total_count,0) > 0
              THEN ROUND(100.0 * COALESCE(a.correct_count,0) / a.total_count, 0)
              WHEN a.status='submitted' AND a.score IS NOT NULL THEN a.score END) AS avg_score,
            SUM(CASE WHEN a.status='submitted' AND IFNULL(a.total_count,0) > 0
              AND COALESCE(a.correct_count,0) >= CEILING(a.total_count / 2) THEN 1 ELSE 0 END) AS pass_count,
            SUM(CASE WHEN a.status='submitted' AND IFNULL(a.total_count,0) > 0
              AND COALESCE(a.correct_count,0) < CEILING(a.total_count / 2) THEN 1
              WHEN a.status='submitted' AND IFNULL(a.total_count,0) <= 0 THEN 1 ELSE 0 END) AS fail_count
          FROM college_exam_attempts a
          INNER JOIN users u ON u.user_id = a.user_id
          WHERE a.exam_id=" . $examId . "
            AND a.status='submitted'
            AND a.user_id IN ({$inUsers})
          GROUP BY student_section
        ";
        $secMeta = [];
        $ss = @mysqli_query($conn, $secScoreSql);
        if ($ss) {
            while ($sm = mysqli_fetch_assoc($ss)) {
                $rawSec = trim((string)($sm['student_section'] ?? ''));
                $k = $rawSec === '' ? '__none__' : $rawSec;
                $secMeta[$k] = $sm;
            }
            mysqli_free_result($ss);
        }
        foreach ($sectionItems as $k => $secRow) {
            $done = $finish($secRow);
            $meta = $secMeta[$k] ?? [];
            $done['submitted_count'] = (int)($meta['submitted_count'] ?? 0);
            $done['avg_score'] = isset($meta['avg_score']) && $meta['avg_score'] !== null ? (float)$meta['avg_score'] : null;
            $done['avg_score_label'] = $done['avg_score'] === null ? '—' : number_format($done['avg_score'], 1) . '%';
            $done['pass_count'] = (int)($meta['pass_count'] ?? 0);
            $done['fail_count'] = (int)($meta['fail_count'] ?? 0);
            $sectionList[] = $done;
        }
        usort($sectionList, static function ($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }

    $weakSub = array_values(array_filter($subjectList, static fn($r) => !empty($r['weak'])));
    $weakTop = array_values(array_filter($topicList, static fn($r) => !empty($r['weak'])));
    $weakQ = array_values(array_filter($questionList, static fn($r) => !empty($r['weak'])));

    $base['has_topics'] = $hasTopics && $mode === 'subject_topic';
    $base['has_uncategorized'] = $hasUncat;
    $base['subjects'] = $subjectList;
    $base['topics'] = $topicList;
    $base['questions'] = $questionList;
    $base['section_comparisons'] = $sectionList;
    $base['weak_subjects'] = $weakSub;
    $base['weak_topics'] = $weakTop;
    $base['weak_questions'] = array_slice($weakQ, 0, 8);
    $base['totals'] = examination_monitor_analytics_rates($totP, $totA, $totC, $totI);
    $base['empty']['zero_answered'] = ($totA <= 0);
    $base['empty']['overall_mode'] = ($mode === 'overall');
    $weakest = null;
    foreach ($subjectList as $sRow) {
        if ((int)($sRow['answered'] ?? 0) >= 3 && ($sRow['accuracy'] ?? null) !== null) {
            $weakest = [
                'name' => (string)$sRow['name'],
                'accuracy' => $sRow['accuracy'],
                'accuracy_label' => (string)$sRow['accuracy_label'],
                'answered' => (int)$sRow['answered'],
                'unassigned' => !empty($sRow['unassigned']),
            ];
            break;
        }
    }
    $base['weakest_subject'] = $weakest;

    $choiceMap = [];
    $chq = @mysqli_query($conn, "
      SELECT aq.question_id AS question_id,
        UPPER(TRIM(ans.selected_answer)) AS choice,
        COUNT(*) AS c
      FROM college_exam_attempt_questions aq
      INNER JOIN college_exam_attempts a ON a.attempt_id = aq.attempt_id
      INNER JOIN college_exam_answers ans
        ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
      WHERE a.exam_id = " . $examId . "
        AND a.status = 'submitted'
        AND a.user_id IN ({$inUsers})
        AND UPPER(TRIM(COALESCE(ans.selected_answer,''))) IN ('A','B','C','D')
      GROUP BY aq.question_id, choice
    ");
    if ($chq) {
        while ($cr = mysqli_fetch_assoc($chq)) {
            $qid = (int)($cr['question_id'] ?? 0);
            $ch = (string)($cr['choice'] ?? '');
            if ($qid <= 0 || !in_array($ch, ['A', 'B', 'C', 'D'], true)) {
                continue;
            }
            if (!isset($choiceMap[$qid])) {
                $choiceMap[$qid] = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
            }
            $choiceMap[$qid][$ch] = (int)($cr['c'] ?? 0);
        }
        mysqli_free_result($chq);
    }
    foreach ($questionList as $qi => $qRow) {
        $qid = (int)($qRow['question_id'] ?? 0);
        $questionList[$qi]['choices'] = $choiceMap[$qid] ?? ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
    }
    $base['questions'] = $questionList;

    $exSql = "
      SELECT
        a.user_id AS user_id,
        TRIM(COALESCE(u.full_name,'')) AS full_name,
        TRIM(COALESCE(u.student_number,'')) AS student_number,
        TRIM(COALESCE(u.email,'')) AS email,
        TRIM(COALESCE(u.section,'')) AS student_section,
        a.correct_count AS exam_correct,
        a.total_count AS exam_total,
        IFNULL(aq.exam_subject_id, 0) AS exam_subject_id,
        IFNULL(aq.exam_topic_id, 0) AS exam_topic_id,
        TRIM(COALESCE(aq.subject_name,'')) AS subject_name,
        TRIM(COALESCE(aq.topic_name,'')) AS topic_name,
        COUNT(*) AS presented,
        SUM(CASE WHEN {$answeredExpr} THEN 1 ELSE 0 END) AS answered,
        SUM(CASE WHEN {$answeredExpr} AND ans.is_correct = 1 THEN 1 ELSE 0 END) AS correct,
        SUM(CASE WHEN {$answeredExpr} AND IFNULL(ans.is_correct,0) <> 1 THEN 1 ELSE 0 END) AS incorrect
      FROM college_exam_attempt_questions aq
      INNER JOIN college_exam_attempts a ON a.attempt_id = aq.attempt_id
      INNER JOIN users u ON u.user_id = a.user_id
      LEFT JOIN college_exam_answers ans
        ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
      WHERE a.exam_id = " . $examId . "
        AND a.status = 'submitted'
        AND a.user_id IN ({$inUsers})
      GROUP BY a.user_id, u.full_name, u.student_number, u.email, TRIM(COALESCE(u.section,'')),
        a.correct_count, a.total_count, IFNULL(aq.exam_subject_id, 0), IFNULL(aq.exam_topic_id, 0),
        TRIM(COALESCE(aq.subject_name,'')), TRIM(COALESCE(aq.topic_name,''))
    ";
    $exRes = @mysqli_query($conn, $exSql);
    $exTopics = [];
    $exSubjects = [];
    if ($exRes) {
        while ($er = mysqli_fetch_assoc($exRes)) {
            $uid = (int)($er['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $subjMeta = examination_monitor_analytics_group_key(
                (int)($er['exam_subject_id'] ?? 0),
                (string)($er['subject_name'] ?? ''),
                'Unassigned'
            );
            $topicMeta = examination_monitor_analytics_group_key(
                (int)($er['exam_topic_id'] ?? 0),
                (string)($er['topic_name'] ?? ''),
                'Unassigned'
            );
            $examTotal = (int)($er['exam_total'] ?? 0);
            $examCorrect = (int)($er['exam_correct'] ?? 0);
            $examPct = $examTotal > 0 ? examination_monitor_analytics_pct($examCorrect, $examTotal) : null;
            $secRaw = trim((string)($er['student_section'] ?? ''));
            $topicRow = $finish([
                'user_id' => $uid,
                'full_name' => (string)($er['full_name'] ?? ''),
                'student_number' => (string)($er['student_number'] ?? ''),
                'email' => (string)($er['email'] ?? ''),
                'section' => $secRaw === '' ? 'No section' : $secRaw,
                'section_key' => $secRaw === '' ? '__none__' : $secRaw,
                'subject_key' => $subjMeta['key'],
                'subject_name' => $subjMeta['name'],
                'subject_unassigned' => !empty($subjMeta['unassigned']),
                'topic_key' => $subjMeta['key'] . '|' . $topicMeta['key'],
                'topic_name' => $topicMeta['name'],
                'topic_unassigned' => !empty($topicMeta['unassigned']),
                'presented' => (int)($er['presented'] ?? 0),
                'answered' => (int)($er['answered'] ?? 0),
                'correct' => (int)($er['correct'] ?? 0),
                'incorrect' => (int)($er['incorrect'] ?? 0),
                'exam_correct' => $examCorrect,
                'exam_total' => $examTotal,
                'exam_score' => $examPct,
                'exam_score_label' => $examPct === null ? '—' : number_format((float)$examPct, 1) . '%',
                'exam_score_hint' => 'Paper total (unanswered counts as not correct)',
            ]);
            $exTopics[] = $topicRow;

            $sKey = $uid . '|' . $subjMeta['key'];
            if (!isset($exSubjects[$sKey])) {
                $exSubjects[$sKey] = [
                    'user_id' => $uid,
                    'full_name' => (string)($er['full_name'] ?? ''),
                    'student_number' => (string)($er['student_number'] ?? ''),
                    'email' => (string)($er['email'] ?? ''),
                    'section' => $topicRow['section'],
                    'section_key' => $topicRow['section_key'],
                    'subject_key' => $subjMeta['key'],
                    'subject_name' => $subjMeta['name'],
                    'subject_unassigned' => !empty($subjMeta['unassigned']),
                    'presented' => 0,
                    'answered' => 0,
                    'correct' => 0,
                    'incorrect' => 0,
                    'exam_correct' => $examCorrect,
                    'exam_total' => $examTotal,
                    'exam_score' => $examPct,
                    'exam_score_label' => $topicRow['exam_score_label'],
                    'exam_score_hint' => 'Paper total (unanswered counts as not correct)',
                ];
            }
            $exSubjects[$sKey]['presented'] += (int)$topicRow['presented'];
            $exSubjects[$sKey]['answered'] += (int)$topicRow['answered'];
            $exSubjects[$sKey]['correct'] += (int)$topicRow['correct'];
            $exSubjects[$sKey]['incorrect'] += (int)$topicRow['incorrect'];
        }
        mysqli_free_result($exRes);
    }

    $exSubjectList = [];
    foreach ($exSubjects as $sRow) {
        $exSubjectList[] = $finish($sRow);
    }
    $sortEx = static function (array $a, array $b): int {
        $wa = !empty($a['weak']) ? 0 : 1;
        $wb = !empty($b['weak']) ? 0 : 1;
        if ($wa !== $wb) {
            return $wa <=> $wb;
        }
        $n = strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
        if ($n !== 0) {
            return $n;
        }
        $s = strcasecmp((string)($a['subject_name'] ?? ''), (string)($b['subject_name'] ?? ''));
        if ($s !== 0) {
            return $s;
        }

        return strcasecmp((string)($a['topic_name'] ?? ''), (string)($b['topic_name'] ?? ''));
    };
    usort($exTopics, $sortEx);
    usort($exSubjectList, $sortEx);
    $base['examinee_topics'] = $exTopics;
    $base['examinee_subjects'] = $exSubjectList;
    $base['weak_examinee_topics'] = array_values(array_filter($exTopics, static fn($r) => !empty($r['weak'])));

    return $base;
}
