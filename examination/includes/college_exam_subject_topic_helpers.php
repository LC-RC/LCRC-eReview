<?php
/**
 * Regular exam question breakdown helpers.
 * Modes (mutually exclusive): overall | subject | subject_topic
 * Professor-defined subject/topic names (no hardcoded CPA subjects).
 */
declare(strict_types=1);

/** @return 'overall'|'subject'|'subject_topic' */
function college_exam_normalize_breakdown_mode(string $mode): string
{
    $m = strtolower(trim($mode));
    if ($m === 'subject' || $m === 'by_subject') {
        return 'subject';
    }
    if (
        $m === 'subject_topic'
        || $m === 'by_subject_topic'
        || $m === 'subject_and_topic'
        || $m === 'by_subject_and_topic'
    ) {
        return 'subject_topic';
    }

    return 'overall';
}

function college_exam_subject_topic_tables_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $a = @mysqli_query($conn, "SHOW TABLES LIKE 'college_exam_subjects'");
    $b = @mysqli_query($conn, "SHOW TABLES LIKE 'college_exam_topics'");
    $ready = ($a && mysqli_num_rows($a) > 0) && ($b && mysqli_num_rows($b) > 0);
    if ($a) {
        mysqli_free_result($a);
    }
    if ($b) {
        mysqli_free_result($b);
    }

    return $ready;
}

function college_exam_questions_has_topic_column(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exam_questions` LIKE 'exam_topic_id'");
    $has = ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool)$has;
}

function college_exam_questions_has_subject_column(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exam_questions` LIKE 'exam_subject_id'");
    $has = ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool)$has;
}

function college_exam_attempts_has_breakdown_column(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exam_attempts` LIKE 'subject_breakdown_json'");
    $has = ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool)$has;
}

function college_exam_has_breakdown_mode_column(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exams` LIKE 'question_breakdown_mode'");
    $has = ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool)$has;
}

function college_exam_has_total_questions_column(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exams` LIKE 'total_questions_required'");
    $has = ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool)$has;
}

function college_exam_subjects_has_questions_required(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exam_subjects` LIKE 'questions_required'");
    $has = ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool)$has;
}

/**
 * Resolve breakdown mode for an exam.
 * Legacy: if mode column missing/empty but subjects+topics exist → subject_topic;
 * subjects without topics → subject; else overall.
 *
 * @return 'overall'|'subject'|'subject_topic'
 */
function college_exam_get_breakdown_mode(mysqli $conn, int $examId): string
{
    $examId = (int)$examId;
    if ($examId <= 0) {
        return 'overall';
    }
    if (college_exam_has_breakdown_mode_column($conn)) {
        $r = @mysqli_query(
            $conn,
            'SELECT question_breakdown_mode FROM college_exams WHERE exam_id=' . $examId . ' LIMIT 1'
        );
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if ($r) {
            mysqli_free_result($r);
        }
        $raw = trim((string)($row['question_breakdown_mode'] ?? ''));
        if ($raw !== '') {
            return college_exam_normalize_breakdown_mode($raw);
        }
    }

    // Infer from existing tree (pre-mode exams).
    if (!college_exam_subject_topic_tables_ready($conn)) {
        return 'overall';
    }
    $tree = college_exam_load_subjects_with_topics($conn, $examId);
    if ($tree === []) {
        return 'overall';
    }
    foreach ($tree as $s) {
        if (!empty($s['topics'])) {
            return 'subject_topic';
        }
    }

    return 'subject';
}

function college_exam_get_total_questions_required(mysqli $conn, int $examId): int
{
    $examId = (int)$examId;
    if ($examId <= 0 || !college_exam_has_total_questions_column($conn)) {
        return 0;
    }
    $r = @mysqli_query(
        $conn,
        'SELECT total_questions_required FROM college_exams WHERE exam_id=' . $examId . ' LIMIT 1'
    );
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if ($r) {
        mysqli_free_result($r);
    }

    return max(0, (int)($row['total_questions_required'] ?? 0));
}

/**
 * @return array{ok:bool,error?:string}
 */
function college_exam_save_breakdown_mode(mysqli $conn, int $examId, string $mode, int $totalQuestionsRequired = 0): array
{
    $examId = (int)$examId;
    if ($examId <= 0) {
        return ['ok' => false, 'error' => 'Invalid exam.'];
    }
    $mode = college_exam_normalize_breakdown_mode($mode);
    $totalQuestionsRequired = max(0, (int)$totalQuestionsRequired);
    if (!college_exam_has_breakdown_mode_column($conn)) {
        return ['ok' => false, 'error' => 'Breakdown mode schema is not migrated. Run scripts/migrate_examination_schema.php.'];
    }
    if (college_exam_has_total_questions_column($conn)) {
        $st = mysqli_prepare(
            $conn,
            'UPDATE college_exams SET question_breakdown_mode=?, total_questions_required=? WHERE exam_id=?'
        );
        if (!$st) {
            return ['ok' => false, 'error' => 'Could not save breakdown mode.'];
        }
        mysqli_stmt_bind_param($st, 'sii', $mode, $totalQuestionsRequired, $examId);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Could not save breakdown mode.'];
        }
    } else {
        $st = mysqli_prepare($conn, 'UPDATE college_exams SET question_breakdown_mode=? WHERE exam_id=?');
        if (!$st) {
            return ['ok' => false, 'error' => 'Could not save breakdown mode.'];
        }
        mysqli_stmt_bind_param($st, 'si', $mode, $examId);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Could not save breakdown mode.'];
        }
    }

    return ['ok' => true];
}

/**
 * Load subjects with nested topics for an exam.
 *
 * @return list<array{exam_subject_id:int,exam_id:int,subject_name:string,questions_required:int,sort_order:int,topics:list<array<string,mixed>>}>
 */
function college_exam_load_subjects_with_topics(mysqli $conn, int $examId): array
{
    $examId = (int)$examId;
    if ($examId <= 0 || !college_exam_subject_topic_tables_ready($conn)) {
        return [];
    }
    $hasSubjQty = college_exam_subjects_has_questions_required($conn);
    $subjects = [];
    $cols = $hasSubjQty
        ? 'exam_subject_id, exam_id, subject_name, questions_required, sort_order'
        : 'exam_subject_id, exam_id, subject_name, sort_order';
    $sr = mysqli_query(
        $conn,
        'SELECT ' . $cols . '
         FROM college_exam_subjects WHERE exam_id=' . $examId . '
         ORDER BY sort_order ASC, exam_subject_id ASC'
    );
    if (!$sr) {
        return [];
    }
    while ($row = mysqli_fetch_assoc($sr)) {
        $sid = (int)$row['exam_subject_id'];
        $subjects[$sid] = [
            'exam_subject_id' => $sid,
            'exam_id' => (int)$row['exam_id'],
            'subject_name' => (string)$row['subject_name'],
            'questions_required' => $hasSubjQty ? max(0, (int)($row['questions_required'] ?? 0)) : 0,
            'sort_order' => (int)$row['sort_order'],
            'topics' => [],
        ];
    }
    mysqli_free_result($sr);
    if ($subjects === []) {
        return [];
    }
    $tr = mysqli_query(
        $conn,
        'SELECT exam_topic_id, exam_id, exam_subject_id, topic_name, questions_required, sort_order
         FROM college_exam_topics WHERE exam_id=' . $examId . '
         ORDER BY sort_order ASC, exam_topic_id ASC'
    );
    if ($tr) {
        while ($t = mysqli_fetch_assoc($tr)) {
            $sid = (int)$t['exam_subject_id'];
            if (!isset($subjects[$sid])) {
                continue;
            }
            $subjects[$sid]['topics'][] = [
                'exam_topic_id' => (int)$t['exam_topic_id'],
                'exam_id' => (int)$t['exam_id'],
                'exam_subject_id' => $sid,
                'topic_name' => (string)$t['topic_name'],
                'questions_required' => max(0, (int)$t['questions_required']),
                'sort_order' => (int)$t['sort_order'],
            ];
        }
        mysqli_free_result($tr);
    }

    // Legacy topic-only trees: derive subject questions_required from topic sum when unset.
    foreach ($subjects as &$s) {
        if ((int)$s['questions_required'] <= 0 && $s['topics'] !== []) {
            $sum = 0;
            foreach ($s['topics'] as $tp) {
                $sum += max(0, (int)$tp['questions_required']);
            }
            $s['questions_required'] = $sum;
        }
    }
    unset($s);

    return array_values($subjects);
}

/**
 * Parse POST for mode + subjects/topics.
 *
 * Expected:
 *   question_breakdown_mode = overall|subject|subject_topic
 *   total_questions_required (overall)
 *   reg_subjects[i][name]
 *   reg_subjects[i][questions]
 *   reg_subjects[i][topics][j][name|questions]  (subject_topic only; optional)
 *
 * @return array{
 *   ok:bool,
 *   error?:string,
 *   mode?:string,
 *   total_questions_required?:int,
 *   subjects?:list<array{name:string,questions:int,topics:list<array{name:string,questions:int}>}>
 * }
 */
function college_exam_parse_subjects_topics_from_post(array $post): array
{
    $mode = college_exam_normalize_breakdown_mode((string)($post['question_breakdown_mode'] ?? 'overall'));
    $totalOverall = max(0, (int)($post['total_questions_required'] ?? 0));

    if ($mode === 'overall') {
        if ($totalOverall < 1 && !empty($post['reg_subjects_present'])) {
            // Allow 0 on draft; publish validation enforces > 0.
        }

        return [
            'ok' => true,
            'mode' => 'overall',
            'total_questions_required' => $totalOverall,
            'subjects' => [],
        ];
    }

    $raw = $post['reg_subjects'] ?? null;
    if (!is_array($raw)) {
        return [
            'ok' => true,
            'mode' => $mode,
            'total_questions_required' => 0,
            'subjects' => [],
        ];
    }

    $out = [];
    $seenSubjects = [];
    foreach ($raw as $sRow) {
        if (!is_array($sRow)) {
            continue;
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string)($sRow['name'] ?? '')) ?? '');
        if ($name === '') {
            continue;
        }
        $key = mb_strtolower($name);
        if (isset($seenSubjects[$key])) {
            return ['ok' => false, 'error' => 'Duplicate subject name: ' . $name];
        }
        $seenSubjects[$key] = true;
        $subjQty = (int)($sRow['questions'] ?? 0);
        if ($subjQty < 1) {
            return ['ok' => false, 'error' => 'Question count must be a positive integer for subject "' . $name . '".'];
        }

        $topics = [];
        if ($mode === 'subject_topic') {
            $topicsRaw = is_array($sRow['topics'] ?? null) ? $sRow['topics'] : [];
            $seenTopics = [];
            $topicSum = 0;
            foreach ($topicsRaw as $tRow) {
                if (!is_array($tRow)) {
                    continue;
                }
                $tName = trim(preg_replace('/\s+/u', ' ', (string)($tRow['name'] ?? '')) ?? '');
                if ($tName === '') {
                    continue;
                }
                $tKey = mb_strtolower($tName);
                if (isset($seenTopics[$tKey])) {
                    return ['ok' => false, 'error' => 'Duplicate topic "' . $tName . '" under subject ' . $name];
                }
                $seenTopics[$tKey] = true;
                $qty = (int)($tRow['questions'] ?? 0);
                if ($qty < 1) {
                    return ['ok' => false, 'error' => 'Question count must be a positive integer for topic "' . $tName . '" (' . $name . ').'];
                }
                $topics[] = ['name' => $tName, 'questions' => $qty];
                $topicSum += $qty;
            }
            if ($topics !== [] && $topicSum !== $subjQty) {
                return [
                    'ok' => false,
                    'error' => 'Subject "' . $name . '" requires ' . $subjQty .
                        ' question(s), but its topics total ' . $topicSum . '.',
                ];
            }
        }

        $out[] = ['name' => $name, 'questions' => $subjQty, 'topics' => $topics];
    }

    return [
        'ok' => true,
        'mode' => $mode,
        'total_questions_required' => 0,
        'subjects' => $out,
    ];
}

/**
 * Replace-all save of exam subjects/topics.
 *
 * @param list<array{name:string,questions?:int,topics?:list<array{name:string,questions:int}>}> $subjects
 * @return array{ok:bool,error?:string}
 */
function college_exam_save_subjects_topics(mysqli $conn, int $examId, array $subjects, string $mode = 'subject_topic'): array
{
    $examId = (int)$examId;
    $mode = college_exam_normalize_breakdown_mode($mode);
    if ($examId <= 0) {
        return ['ok' => false, 'error' => 'Invalid exam.'];
    }
    if (!college_exam_subject_topic_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'Subject/topic schema is not migrated. Run scripts/migrate_examination_schema.php.'];
    }

    if ($mode === 'overall') {
        // Clear tree; clear question tags.
        if (college_exam_questions_has_topic_column($conn)) {
            @mysqli_query($conn, 'UPDATE college_exam_questions SET exam_topic_id=NULL WHERE exam_id=' . $examId);
        }
        if (college_exam_questions_has_subject_column($conn)) {
            @mysqli_query($conn, 'UPDATE college_exam_questions SET exam_subject_id=NULL WHERE exam_id=' . $examId);
        }
        @mysqli_query($conn, 'DELETE FROM college_exam_topics WHERE exam_id=' . $examId);
        @mysqli_query($conn, 'DELETE FROM college_exam_subjects WHERE exam_id=' . $examId);

        return ['ok' => true];
    }

    $existingSubjects = [];
    $sr = mysqli_query($conn, 'SELECT exam_subject_id, subject_name FROM college_exam_subjects WHERE exam_id=' . $examId);
    while ($sr && ($r = mysqli_fetch_assoc($sr))) {
        $existingSubjects[mb_strtolower(trim((string)$r['subject_name']))] = (int)$r['exam_subject_id'];
    }
    if ($sr) {
        mysqli_free_result($sr);
    }

    $existingTopics = [];
    $tr = mysqli_query($conn, 'SELECT exam_topic_id, exam_subject_id, topic_name FROM college_exam_topics WHERE exam_id=' . $examId);
    while ($tr && ($r = mysqli_fetch_assoc($tr))) {
        $sid = (int)$r['exam_subject_id'];
        if (!isset($existingTopics[$sid])) {
            $existingTopics[$sid] = [];
        }
        $existingTopics[$sid][mb_strtolower(trim((string)$r['topic_name']))] = (int)$r['exam_topic_id'];
    }
    if ($tr) {
        mysqli_free_result($tr);
    }

    $keepSubjectIds = [];
    $keepTopicIds = [];
    $hasSubjQty = college_exam_subjects_has_questions_required($conn);
    $sOrd = 0;
    foreach ($subjects as $s) {
        $sName = (string)($s['name'] ?? '');
        $sQty = max(0, (int)($s['questions'] ?? 0));
        $sKey = mb_strtolower(trim($sName));
        $subjectId = $existingSubjects[$sKey] ?? 0;
        if ($subjectId > 0) {
            if ($hasSubjQty) {
                $upd = mysqli_prepare(
                    $conn,
                    'UPDATE college_exam_subjects SET subject_name=?, questions_required=?, sort_order=? WHERE exam_subject_id=? AND exam_id=?'
                );
                mysqli_stmt_bind_param($upd, 'siiii', $sName, $sQty, $sOrd, $subjectId, $examId);
            } else {
                $upd = mysqli_prepare(
                    $conn,
                    'UPDATE college_exam_subjects SET subject_name=?, sort_order=? WHERE exam_subject_id=? AND exam_id=?'
                );
                mysqli_stmt_bind_param($upd, 'siii', $sName, $sOrd, $subjectId, $examId);
            }
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        } else {
            if ($hasSubjQty) {
                $insS = mysqli_prepare(
                    $conn,
                    'INSERT INTO college_exam_subjects (exam_id, subject_name, questions_required, sort_order) VALUES (?,?,?,?)'
                );
                mysqli_stmt_bind_param($insS, 'isii', $examId, $sName, $sQty, $sOrd);
            } else {
                $insS = mysqli_prepare(
                    $conn,
                    'INSERT INTO college_exam_subjects (exam_id, subject_name, sort_order) VALUES (?,?,?)'
                );
                mysqli_stmt_bind_param($insS, 'isi', $examId, $sName, $sOrd);
            }
            if (!mysqli_stmt_execute($insS)) {
                mysqli_stmt_close($insS);

                return ['ok' => false, 'error' => 'Could not save subject "' . $sName . '".'];
            }
            $subjectId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($insS);
        }
        $keepSubjectIds[$subjectId] = true;

        $topics = ($mode === 'subject_topic' && is_array($s['topics'] ?? null)) ? $s['topics'] : [];
        $tOrd = 0;
        foreach ($topics as $t) {
            $tName = (string)($t['name'] ?? '');
            $qty = max(1, (int)($t['questions'] ?? 0));
            $tKey = mb_strtolower(trim($tName));
            $topicId = $existingTopics[$subjectId][$tKey] ?? 0;
            if ($topicId > 0) {
                $updT = mysqli_prepare(
                    $conn,
                    'UPDATE college_exam_topics SET topic_name=?, questions_required=?, sort_order=?, exam_subject_id=? WHERE exam_topic_id=? AND exam_id=?'
                );
                mysqli_stmt_bind_param($updT, 'siiiii', $tName, $qty, $tOrd, $subjectId, $topicId, $examId);
                mysqli_stmt_execute($updT);
                mysqli_stmt_close($updT);
            } else {
                $insT = mysqli_prepare(
                    $conn,
                    'INSERT INTO college_exam_topics (exam_id, exam_subject_id, topic_name, questions_required, sort_order) VALUES (?,?,?,?,?)'
                );
                mysqli_stmt_bind_param($insT, 'iisii', $examId, $subjectId, $tName, $qty, $tOrd);
                if (!mysqli_stmt_execute($insT)) {
                    mysqli_stmt_close($insT);

                    return ['ok' => false, 'error' => 'Could not save topic "' . $tName . '".'];
                }
                $topicId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($insT);
            }
            $keepTopicIds[$topicId] = true;
            $tOrd++;
        }
        $sOrd++;
    }

    // In subject mode (or subjects without topics), drop leftover topics.
    $allTopics = mysqli_query($conn, 'SELECT exam_topic_id FROM college_exam_topics WHERE exam_id=' . $examId);
    while ($allTopics && ($r = mysqli_fetch_assoc($allTopics))) {
        $tid = (int)$r['exam_topic_id'];
        if (!isset($keepTopicIds[$tid])) {
            if (college_exam_questions_has_topic_column($conn)) {
                @mysqli_query($conn, 'UPDATE college_exam_questions SET exam_topic_id=NULL WHERE exam_id=' . $examId . ' AND exam_topic_id=' . $tid);
            }
            @mysqli_query($conn, 'DELETE FROM college_exam_topics WHERE exam_topic_id=' . $tid . ' AND exam_id=' . $examId);
        }
    }
    if ($allTopics) {
        mysqli_free_result($allTopics);
    }

    $allSubjects = mysqli_query($conn, 'SELECT exam_subject_id FROM college_exam_subjects WHERE exam_id=' . $examId);
    while ($allSubjects && ($r = mysqli_fetch_assoc($allSubjects))) {
        $sid = (int)$r['exam_subject_id'];
        if (!isset($keepSubjectIds[$sid])) {
            if (college_exam_questions_has_subject_column($conn)) {
                @mysqli_query($conn, 'UPDATE college_exam_questions SET exam_subject_id=NULL WHERE exam_id=' . $examId . ' AND exam_subject_id=' . $sid);
            }
            $topicIdsRes = mysqli_query($conn, 'SELECT exam_topic_id FROM college_exam_topics WHERE exam_subject_id=' . $sid . ' AND exam_id=' . $examId);
            while ($topicIdsRes && ($trRow = mysqli_fetch_assoc($topicIdsRes))) {
                $tidDel = (int)$trRow['exam_topic_id'];
                if (college_exam_questions_has_topic_column($conn)) {
                    @mysqli_query($conn, 'UPDATE college_exam_questions SET exam_topic_id=NULL WHERE exam_id=' . $examId . ' AND exam_topic_id=' . $tidDel);
                }
            }
            if ($topicIdsRes) {
                mysqli_free_result($topicIdsRes);
            }
            @mysqli_query($conn, 'DELETE FROM college_exam_topics WHERE exam_subject_id=' . $sid . ' AND exam_id=' . $examId);
            @mysqli_query($conn, 'DELETE FROM college_exam_subjects WHERE exam_subject_id=' . $sid . ' AND exam_id=' . $examId);
        }
    }
    if ($allSubjects) {
        mysqli_free_result($allSubjects);
    }

    // Subject mode: clear any remaining topic tags.
    if ($mode === 'subject' && college_exam_questions_has_topic_column($conn)) {
        @mysqli_query($conn, 'UPDATE college_exam_questions SET exam_topic_id=NULL WHERE exam_id=' . $examId);
        @mysqli_query($conn, 'DELETE FROM college_exam_topics WHERE exam_id=' . $examId);
    }

    return ['ok' => true];
}

/**
 * Authored counts + supply check for current breakdown mode.
 *
 * @return array{
 *   ok:bool,
 *   configured:bool,
 *   mode:string,
 *   total_required:int,
 *   subjects:list<array<string,mixed>>,
 *   errors:list<string>
 * }
 */
function college_exam_topic_supply(mysqli $conn, int $examId): array
{
    $mode = college_exam_get_breakdown_mode($conn, $examId);

    if ($mode === 'overall') {
        $req = college_exam_get_total_questions_required($conn, $examId);
        $authored = 0;
        $qr = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . (int)$examId);
        if ($qr && ($row = mysqli_fetch_assoc($qr))) {
            $authored = (int)($row['c'] ?? 0);
        }
        if ($qr) {
            mysqli_free_result($qr);
        }
        $errors = [];
        $configured = $req > 0;
        if ($configured && $authored < $req) {
            $errors[] = sprintf('Overall exam requires %d question(s), but only %d authored.', $req, $authored);
        }

        return [
            'ok' => $errors === [],
            'configured' => $configured,
            'mode' => 'overall',
            'total_required' => $req,
            'total_authored' => $authored,
            'remaining' => max(0, $req - $authored),
            'status' => !$configured ? 'idle' : ($authored >= $req ? 'complete' : ($authored > 0 ? 'progress' : 'missing')),
            'subjects' => [],
            'errors' => $errors,
        ];
    }

    $tree = college_exam_load_subjects_with_topics($conn, $examId);
    if ($tree === []) {
        return [
            'ok' => true,
            'configured' => false,
            'mode' => $mode,
            'total_required' => 0,
            'total_authored' => 0,
            'remaining' => 0,
            'status' => 'idle',
            'subjects' => [],
            'errors' => [],
        ];
    }

    $authoredByTopic = [];
    $authoredBySubject = [];
    if (college_exam_questions_has_topic_column($conn)) {
        $qr = mysqli_query(
            $conn,
            'SELECT exam_topic_id, COUNT(*) AS c FROM college_exam_questions
             WHERE exam_id=' . (int)$examId . ' AND exam_topic_id IS NOT NULL AND exam_topic_id > 0
             GROUP BY exam_topic_id'
        );
        while ($qr && ($row = mysqli_fetch_assoc($qr))) {
            $authoredByTopic[(int)$row['exam_topic_id']] = (int)$row['c'];
        }
        if ($qr) {
            mysqli_free_result($qr);
        }
    }
    if (college_exam_questions_has_subject_column($conn)) {
        $qr = mysqli_query(
            $conn,
            'SELECT exam_subject_id, COUNT(*) AS c FROM college_exam_questions
             WHERE exam_id=' . (int)$examId . ' AND exam_subject_id IS NOT NULL AND exam_subject_id > 0
             GROUP BY exam_subject_id'
        );
        while ($qr && ($row = mysqli_fetch_assoc($qr))) {
            $authoredBySubject[(int)$row['exam_subject_id']] = (int)$row['c'];
        }
        if ($qr) {
            mysqli_free_result($qr);
        }
    }
    // Also count topic-tagged questions toward their parent subject for subject_topic mode.
    if ($mode === 'subject_topic' && $authoredByTopic !== []) {
        foreach ($tree as $s) {
            $sid = (int)$s['exam_subject_id'];
            foreach ($s['topics'] as $t) {
                $tid = (int)$t['exam_topic_id'];
                $authoredBySubject[$sid] = (int)($authoredBySubject[$sid] ?? 0) + (int)($authoredByTopic[$tid] ?? 0);
            }
        }
        // Avoid double-counting: subject-tagged + topic-tagged may overlap; recompute cleanly.
        $authoredBySubject = [];
        foreach ($tree as $s) {
            $sid = (int)$s['exam_subject_id'];
            $count = 0;
            if ($s['topics'] !== []) {
                foreach ($s['topics'] as $t) {
                    $count += (int)($authoredByTopic[(int)$t['exam_topic_id']] ?? 0);
                }
                // Subject-level questions without topic also count.
                if (college_exam_questions_has_subject_column($conn)) {
                    $qr = mysqli_query(
                        $conn,
                        'SELECT COUNT(*) AS c FROM college_exam_questions
                         WHERE exam_id=' . (int)$examId . ' AND exam_subject_id=' . $sid .
                        ' AND (exam_topic_id IS NULL OR exam_topic_id=0)'
                    );
                    if ($qr && ($row = mysqli_fetch_assoc($qr))) {
                        $count += (int)($row['c'] ?? 0);
                    }
                    if ($qr) {
                        mysqli_free_result($qr);
                    }
                }
            } else {
                $qr = mysqli_query(
                    $conn,
                    'SELECT COUNT(*) AS c FROM college_exam_questions
                     WHERE exam_id=' . (int)$examId . ' AND exam_subject_id=' . $sid
                );
                if ($qr && ($row = mysqli_fetch_assoc($qr))) {
                    $count = (int)($row['c'] ?? 0);
                }
                if ($qr) {
                    mysqli_free_result($qr);
                }
            }
            $authoredBySubject[$sid] = $count;
        }
    }

    $errors = [];
    $totalReq = 0;
    $subjectsOut = [];
    foreach ($tree as $s) {
        $sid = (int)$s['exam_subject_id'];
        $subReq = max(0, (int)$s['questions_required']);
        $subTopics = [];
        $topicSum = 0;

        if ($mode === 'subject_topic' && $s['topics'] !== []) {
            foreach ($s['topics'] as $t) {
                $tid = (int)$t['exam_topic_id'];
                $req = max(0, (int)$t['questions_required']);
                $auth = (int)($authoredByTopic[$tid] ?? 0);
                $ok = $req > 0 && $auth >= $req;
                $topicSum += $req;
                if ($req < 1) {
                    $errors[] = sprintf('%s / %s: question count must be > 0.', $s['subject_name'], $t['topic_name']);
                } elseif (!$ok) {
                    $errors[] = sprintf(
                        '%s / %s requires %d question(s), but only %d authored.',
                        $s['subject_name'],
                        $t['topic_name'],
                        $req,
                        $auth
                    );
                }
                $subTopics[] = [
                    'exam_topic_id' => $tid,
                    'topic_name' => (string)$t['topic_name'],
                    'required' => $req,
                    'authored' => $auth,
                    'remaining' => max(0, $req - $auth),
                    'status' => $auth >= $req && $req > 0 ? 'complete' : ($auth > 0 ? 'progress' : 'missing'),
                    'ok' => $ok,
                ];
            }
            if ($subReq > 0 && $topicSum !== $subReq) {
                $errors[] = sprintf(
                    '%s: subject requires %d question(s) but topics total %d.',
                    $s['subject_name'],
                    $subReq,
                    $topicSum
                );
            }
            if ($subReq <= 0) {
                $subReq = $topicSum;
            }
            $subAuth = 0;
            foreach ($subTopics as $st) {
                $subAuth += min((int)$st['authored'], (int)$st['required'] > 0 ? (int)$st['required'] : (int)$st['authored']);
            }
            // Prefer raw topic authored sum for display (capped later in UI if needed).
            $subAuthRaw = 0;
            foreach ($subTopics as $st) {
                $subAuthRaw += (int)$st['authored'];
            }
            $subAuth = $subAuthRaw;
            $subOk = $subTopics !== [] && !in_array(false, array_column($subTopics, 'ok'), true)
                && ($subReq <= 0 || $topicSum === $subReq || $subReq === $topicSum);
        } else {
            // Subject-only quota (no topics, or subject mode).
            $auth = (int)($authoredBySubject[$sid] ?? 0);
            $subAuth = $auth;
            $subOk = $subReq > 0 && $auth >= $subReq;
            if ($mode === 'subject' || $s['topics'] === []) {
                if ($subReq < 1) {
                    $errors[] = sprintf('%s: question count must be > 0.', $s['subject_name']);
                    $subOk = false;
                } elseif ($auth < $subReq) {
                    $errors[] = sprintf(
                        '%s requires %d question(s), but only %d authored.',
                        $s['subject_name'],
                        $subReq,
                        $auth
                    );
                    $subOk = false;
                }
            }
        }

        $totalReq += $subReq;
        $subjectsOut[] = [
            'exam_subject_id' => $sid,
            'subject_name' => (string)$s['subject_name'],
            'topic_count' => count($subTopics),
            'questions_required' => $subReq,
            'authored' => $subAuth,
            'remaining' => max(0, $subReq - $subAuth),
            'status' => $subReq > 0 && $subAuth >= $subReq ? 'complete' : ($subAuth > 0 ? 'progress' : 'missing'),
            'ok' => $subOk,
            'topics' => $subTopics,
        ];
    }

    $totalAuthored = 0;
    foreach ($subjectsOut as $so) {
        $totalAuthored += min((int)$so['authored'], (int)$so['questions_required'] > 0 ? (int)$so['questions_required'] : (int)$so['authored']);
    }
    // Display authored as sum of subject authored (may exceed required when pool allowed historically).
    $totalAuthoredRaw = 0;
    foreach ($subjectsOut as $so) {
        $totalAuthoredRaw += (int)$so['authored'];
    }

    return [
        'ok' => $errors === [],
        'configured' => true,
        'mode' => $mode,
        'total_required' => $totalReq,
        'total_authored' => $totalAuthoredRaw,
        'remaining' => max(0, $totalReq - $totalAuthored),
        'status' => $totalReq > 0 && $totalAuthored >= $totalReq && $errors === []
            ? 'complete'
            : ($totalAuthoredRaw > 0 ? 'progress' : 'missing'),
        'subjects' => $subjectsOut,
        'errors' => $errors,
    ];
}

/**
 * Capacity check before assigning a question to a blueprint bucket.
 * Blocks exceeding configured quotas (authored pool must not silently overflow).
 *
 * @return array{ok:bool,error?:string,label?:string,required?:int,authored?:int,remaining?:int}
 */
function college_exam_check_question_capacity(
    mysqli $conn,
    int $examId,
    int $subjectId,
    int $topicId,
    int $excludeQuestionId = 0
): array {
    $examId = (int)$examId;
    $mode = college_exam_get_breakdown_mode($conn, $examId);
    $excludeQuestionId = max(0, (int)$excludeQuestionId);
    $subjectId = max(0, (int)$subjectId);
    $topicId = max(0, (int)$topicId);

    if ($mode === 'overall') {
        $req = college_exam_get_total_questions_required($conn, $examId);
        if ($req <= 0) {
            return ['ok' => true, 'label' => 'Overall', 'required' => 0, 'authored' => 0, 'remaining' => 0];
        }
        $sql = 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . $examId;
        if ($excludeQuestionId > 0) {
            $sql .= ' AND question_id<>' . $excludeQuestionId;
        }
        $authored = 0;
        $qr = @mysqli_query($conn, $sql);
        if ($qr && ($row = mysqli_fetch_assoc($qr))) {
            $authored = (int)($row['c'] ?? 0);
        }
        if ($qr) {
            mysqli_free_result($qr);
        }
        $remaining = max(0, $req - $authored);
        if ($authored >= $req) {
            return [
                'ok' => false,
                'error' => sprintf('Overall target is complete (%d / %d). Remove or reassign a question before adding another.', $authored, $req),
                'label' => 'Overall',
                'required' => $req,
                'authored' => $authored,
                'remaining' => 0,
            ];
        }

        return [
            'ok' => true,
            'label' => sprintf('Overall — %d / %d, %d remaining', $authored, $req, $remaining),
            'required' => $req,
            'authored' => $authored,
            'remaining' => $remaining,
        ];
    }

    $tree = college_exam_load_subjects_with_topics($conn, $examId);
    $subj = null;
    foreach ($tree as $s) {
        if ((int)$s['exam_subject_id'] === $subjectId) {
            $subj = $s;
            break;
        }
    }
    if ($subj === null) {
        return ['ok' => false, 'error' => 'Select a configured subject for this question.'];
    }
    $sName = (string)$subj['subject_name'];
    $hasTopics = !empty($subj['topics']);

    if ($mode === 'subject_topic' && $hasTopics) {
        if ($topicId <= 0) {
            return ['ok' => false, 'error' => 'Select a topic for subject "' . $sName . '".'];
        }
        $topic = null;
        foreach ($subj['topics'] as $t) {
            if ((int)$t['exam_topic_id'] === $topicId) {
                $topic = $t;
                break;
            }
        }
        if ($topic === null) {
            return ['ok' => false, 'error' => 'Selected topic does not belong to subject "' . $sName . '".'];
        }
        $req = max(0, (int)$topic['questions_required']);
        $sql = 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . $examId
            . ' AND exam_topic_id=' . $topicId;
        if ($excludeQuestionId > 0) {
            $sql .= ' AND question_id<>' . $excludeQuestionId;
        }
        $authored = 0;
        $qr = @mysqli_query($conn, $sql);
        if ($qr && ($row = mysqli_fetch_assoc($qr))) {
            $authored = (int)($row['c'] ?? 0);
        }
        if ($qr) {
            mysqli_free_result($qr);
        }
        $label = $sName . ' / ' . (string)$topic['topic_name'];
        $remaining = max(0, $req - $authored);
        if ($req > 0 && $authored >= $req) {
            return [
                'ok' => false,
                'error' => sprintf('%s is complete (%d / %d). Choose another topic or remove an existing question.', $label, $authored, $req),
                'label' => $label,
                'required' => $req,
                'authored' => $authored,
                'remaining' => 0,
            ];
        }

        return [
            'ok' => true,
            'label' => sprintf('%s — %d / %d, %d remaining', $label, $authored, $req, $remaining),
            'required' => $req,
            'authored' => $authored,
            'remaining' => $remaining,
        ];
    }

    // Subject-only bucket.
    $req = max(0, (int)$subj['questions_required']);
    $sql = 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . $examId
        . ' AND exam_subject_id=' . $subjectId;
    if ($excludeQuestionId > 0) {
        $sql .= ' AND question_id<>' . $excludeQuestionId;
    }
    $authored = 0;
    $qr = @mysqli_query($conn, $sql);
    if ($qr && ($row = mysqli_fetch_assoc($qr))) {
        $authored = (int)($row['c'] ?? 0);
    }
    if ($qr) {
        mysqli_free_result($qr);
    }
    $remaining = max(0, $req - $authored);
    if ($req > 0 && $authored >= $req) {
        return [
            'ok' => false,
            'error' => sprintf('%s is complete (%d / %d). Choose another subject or remove an existing question.', $sName, $authored, $req),
            'label' => $sName,
            'required' => $req,
            'authored' => $authored,
            'remaining' => 0,
        ];
    }

    return [
        'ok' => true,
        'label' => sprintf('%s — %d / %d, %d remaining', $sName, $authored, $req, $remaining),
        'required' => $req,
        'authored' => $authored,
        'remaining' => $remaining,
    ];
}

/**
 * Select questions for an attempt according to the exam breakdown mode.
 * Legacy overall with total=0 returns all authored questions.
 *
 * @return list<array<string,mixed>>
 */
function college_exam_select_questions_for_attempt(mysqli $conn, int $examId): array
{
    $examId = (int)$examId;
    $mode = college_exam_get_breakdown_mode($conn, $examId);
    $hasTopicCol = college_exam_questions_has_topic_column($conn);
    $hasSubjCol = college_exam_questions_has_subject_column($conn);

    if ($mode === 'overall') {
        $out = [];
        $qr = mysqli_query(
            $conn,
            'SELECT * FROM college_exam_questions WHERE exam_id=' . $examId . ' ORDER BY sort_order ASC, question_id ASC'
        );
        while ($qr && ($q = mysqli_fetch_assoc($qr))) {
            $out[] = $q;
        }
        if ($qr) {
            mysqli_free_result($qr);
        }
        $req = college_exam_get_total_questions_required($conn, $examId);
        if ($req > 0 && count($out) > $req) {
            $out = array_slice($out, 0, $req);
        }

        return $out;
    }

    $tree = college_exam_load_subjects_with_topics($conn, $examId);
    if ($tree === []) {
        // Misconfigured → fall back to all questions (safe legacy).
        $out = [];
        $qr = mysqli_query(
            $conn,
            'SELECT * FROM college_exam_questions WHERE exam_id=' . $examId . ' ORDER BY sort_order ASC, question_id ASC'
        );
        while ($qr && ($q = mysqli_fetch_assoc($qr))) {
            $out[] = $q;
        }
        if ($qr) {
            mysqli_free_result($qr);
        }

        return $out;
    }

    // Index all questions by subject / topic.
    $bySubject = [];
    $byTopic = [];
    $allQ = [];
    $qr = mysqli_query(
        $conn,
        'SELECT * FROM college_exam_questions WHERE exam_id=' . $examId . ' ORDER BY sort_order ASC, question_id ASC'
    );
    while ($qr && ($q = mysqli_fetch_assoc($qr))) {
        $allQ[] = $q;
        $sid = $hasSubjCol ? (int)($q['exam_subject_id'] ?? 0) : 0;
        $tid = $hasTopicCol ? (int)($q['exam_topic_id'] ?? 0) : 0;
        if ($sid > 0) {
            $bySubject[$sid][] = $q;
        }
        if ($tid > 0) {
            $byTopic[$tid][] = $q;
        }
    }
    if ($qr) {
        mysqli_free_result($qr);
    }

    // Resolve subject from topic when subject_id missing (legacy topic-only tagging).
    $topicToSubject = [];
    foreach ($tree as $s) {
        foreach ($s['topics'] as $t) {
            $topicToSubject[(int)$t['exam_topic_id']] = (int)$s['exam_subject_id'];
        }
    }
    foreach ($byTopic as $tid => $rows) {
        $sid = $topicToSubject[$tid] ?? 0;
        if ($sid <= 0) {
            continue;
        }
        foreach ($rows as $q) {
            $qid = (int)($q['question_id'] ?? 0);
            $already = false;
            foreach ($bySubject[$sid] ?? [] as $existing) {
                if ((int)($existing['question_id'] ?? 0) === $qid) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $bySubject[$sid][] = $q;
            }
        }
    }

    $out = [];
    foreach ($tree as $s) {
        $sid = (int)$s['exam_subject_id'];
        $sName = (string)$s['subject_name'];
        $subReq = max(0, (int)$s['questions_required']);

        if ($mode === 'subject' || $s['topics'] === []) {
            $rows = $bySubject[$sid] ?? [];
            foreach ($rows as &$rq) {
                $rq['_exam_subject_id'] = $sid;
                $rq['_subject_name'] = $sName;
                $rq['_topic_name'] = '';
            }
            unset($rq);
            if ($subReq > 0 && count($rows) > $subReq) {
                $rows = array_slice($rows, 0, $subReq);
            }
            foreach ($rows as $q) {
                $out[] = $q;
            }
            continue;
        }

        // subject_topic with topics: slice per topic; subject total should match topic sum.
        foreach ($s['topics'] as $t) {
            $tid = (int)$t['exam_topic_id'];
            $req = max(0, (int)$t['questions_required']);
            $rows = $byTopic[$tid] ?? [];
            foreach ($rows as &$rq) {
                $rq['_exam_subject_id'] = $sid;
                $rq['_subject_name'] = $sName;
                $rq['_exam_topic_id'] = $tid;
                $rq['_topic_name'] = (string)$t['topic_name'];
            }
            unset($rq);
            if ($req > 0 && count($rows) > $req) {
                $rows = array_slice($rows, 0, $req);
            }
            foreach ($rows as $q) {
                $out[] = $q;
            }
        }
    }

    return $out;
}

/**
 * Build subject/topic breakdown for a scored attempt.
 *
 * @param list<array<string,mixed>> $questions
 * @param array<int,array<string,mixed>> $answersByQid
 * @param 'overall'|'subject'|'subject_topic' $mode
 * @return list<array<string,mixed>>
 */
function college_exam_build_subject_topic_breakdown(array $questions, array $answersByQid, string $mode = 'subject_topic'): array
{
    $mode = college_exam_normalize_breakdown_mode($mode);
    if ($mode === 'overall') {
        return [];
    }
    $includeTopics = ($mode === 'subject_topic');

    $bySubject = [];
    foreach ($questions as $q) {
        $qid = (int)($q['question_id'] ?? 0);
        $sid = (int)($q['_exam_subject_id'] ?? $q['exam_subject_id'] ?? 0);
        $sName = (string)($q['_subject_name'] ?? $q['subject_name'] ?? '');
        $tid = (int)($q['exam_topic_id'] ?? $q['_exam_topic_id'] ?? 0);
        $tName = (string)($q['_topic_name'] ?? $q['topic_name'] ?? '');
        if ($sName === '') {
            $sName = 'Unassigned';
        }
        $sKey = $sid > 0 ? ('id:' . $sid) : ('name:' . mb_strtolower($sName));
        if (!isset($bySubject[$sKey])) {
            $bySubject[$sKey] = [
                'exam_subject_id' => $sid,
                'subject_name' => $sName,
                'correct' => 0,
                'total' => 0,
                'topics' => [],
            ];
        }
        $bySubject[$sKey]['total']++;
        $exp = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
        $sel = isset($answersByQid[$qid])
            ? strtoupper(trim((string)($answersByQid[$qid]['selected_answer'] ?? '')))
            : '';
        $isCorrect = ($sel !== '' && $sel === $exp);
        if ($isCorrect) {
            $bySubject[$sKey]['correct']++;
        }

        if ($includeTopics && $tName !== '') {
            $tKey = $tid > 0 ? ('id:' . $tid) : ('name:' . mb_strtolower($tName));
            if (!isset($bySubject[$sKey]['topics'][$tKey])) {
                $bySubject[$sKey]['topics'][$tKey] = [
                    'exam_topic_id' => $tid,
                    'topic_name' => $tName,
                    'correct' => 0,
                    'total' => 0,
                ];
            }
            $bySubject[$sKey]['topics'][$tKey]['total']++;
            if ($isCorrect) {
                $bySubject[$sKey]['topics'][$tKey]['correct']++;
            }
        }
    }

    $out = [];
    foreach ($bySubject as $subj) {
        $topics = [];
        if ($includeTopics) {
            foreach ($subj['topics'] as $tp) {
                $tt = (int)$tp['total'];
                $tc = (int)$tp['correct'];
                $topics[] = [
                    'exam_topic_id' => (int)$tp['exam_topic_id'],
                    'topic_name' => (string)$tp['topic_name'],
                    'correct' => $tc,
                    'total' => $tt,
                    'score_pct' => $tt > 0 ? round(($tc / $tt) * 100, 2) : 0.0,
                ];
            }
        }
        $st = (int)$subj['total'];
        $sc = (int)$subj['correct'];
        $out[] = [
            'exam_subject_id' => (int)$subj['exam_subject_id'],
            'subject_name' => (string)$subj['subject_name'],
            'correct' => $sc,
            'total' => $st,
            'score_pct' => $st > 0 ? round(($sc / $st) * 100, 2) : 0.0,
            'topics' => $topics,
        ];
    }

    return $out;
}
