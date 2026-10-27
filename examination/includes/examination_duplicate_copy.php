<?php
declare(strict_types=1);

/**
 * Exam-scoped paper copy for Duplicate. Does not touch attempts, answers, events, or heartbeats.
 */

require_once __DIR__ . '/college_exam_subject_topic_helpers.php';

/**
 * Copy regular subjects/topics/questions with new PKs and remapped FKs.
 * Must run inside an open transaction.
 *
 * @return array{ok:bool,question_count:int,error?:string}
 */
function examination_duplicate_copy_regular_paper(mysqli $conn, int $sourceId, int $newId): array
{
    $sourceId = (int) $sourceId;
    $newId = (int) $newId;
    if ($sourceId <= 0 || $newId <= 0) {
        return ['ok' => false, 'question_count' => 0, 'error' => 'Invalid examination ids for duplicate.'];
    }

    $mode = college_exam_get_breakdown_mode($conn, $sourceId);
    $total = college_exam_get_total_questions_required($conn, $sourceId);
    $modeSave = college_exam_save_breakdown_mode($conn, $newId, $mode, $total);
    if (empty($modeSave['ok'])) {
        return ['ok' => false, 'question_count' => 0, 'error' => (string) ($modeSave['error'] ?? 'Could not copy question allocation mode.')];
    }

    $subjectMap = [];
    $topicMap = [];
    $hasSubjQty = college_exam_subjects_has_questions_required($conn);

    if (college_exam_subject_topic_tables_ready($conn)) {
        $cols = $hasSubjQty
            ? 'exam_subject_id, subject_name, questions_required, sort_order'
            : 'exam_subject_id, subject_name, sort_order';
        $sr = mysqli_query($conn, 'SELECT ' . $cols . ' FROM college_exam_subjects WHERE exam_id=' . $sourceId . ' ORDER BY sort_order ASC, exam_subject_id ASC');
        if ($sr === false) {
            return ['ok' => false, 'question_count' => 0, 'error' => 'Could not read subjects to duplicate.'];
        }
        while ($row = mysqli_fetch_assoc($sr)) {
            $oldSid = (int) ($row['exam_subject_id'] ?? 0);
            $name = (string) ($row['subject_name'] ?? '');
            $qty = $hasSubjQty ? max(0, (int) ($row['questions_required'] ?? 0)) : 0;
            $sort = (int) ($row['sort_order'] ?? 0);
            if ($oldSid <= 0 || $name === '') {
                mysqli_free_result($sr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'A subject row is incomplete and cannot be duplicated.'];
            }
            if ($hasSubjQty) {
                $ins = mysqli_prepare(
                    $conn,
                    'INSERT INTO college_exam_subjects (exam_id, subject_name, questions_required, sort_order) VALUES (?,?,?,?)'
                );
                if (!$ins) {
                    mysqli_free_result($sr);

                    return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy subjects.'];
                }
                mysqli_stmt_bind_param($ins, 'isii', $newId, $name, $qty, $sort);
            } else {
                $ins = mysqli_prepare($conn, 'INSERT INTO college_exam_subjects (exam_id, subject_name, sort_order) VALUES (?,?,?)');
                if (!$ins) {
                    mysqli_free_result($sr);

                    return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy subjects.'];
                }
                mysqli_stmt_bind_param($ins, 'isi', $newId, $name, $sort);
            }
            if (!mysqli_stmt_execute($ins)) {
                mysqli_stmt_close($ins);
                mysqli_free_result($sr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy subjects.'];
            }
            $newSid = (int) mysqli_insert_id($conn);
            mysqli_stmt_close($ins);
            if ($newSid <= 0) {
                mysqli_free_result($sr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not allocate new subject ids.'];
            }
            $subjectMap[$oldSid] = $newSid;
        }
        mysqli_free_result($sr);

        $tr = mysqli_query(
            $conn,
            'SELECT exam_topic_id, exam_subject_id, topic_name, questions_required, sort_order
             FROM college_exam_topics WHERE exam_id=' . $sourceId . ' ORDER BY sort_order ASC, exam_topic_id ASC'
        );
        if ($tr === false) {
            return ['ok' => false, 'question_count' => 0, 'error' => 'Could not read topics to duplicate.'];
        }
        while ($row = mysqli_fetch_assoc($tr)) {
            $oldTid = (int) ($row['exam_topic_id'] ?? 0);
            $oldSid = (int) ($row['exam_subject_id'] ?? 0);
            $tname = (string) ($row['topic_name'] ?? '');
            $tqty = max(0, (int) ($row['questions_required'] ?? 0));
            $tsort = (int) ($row['sort_order'] ?? 0);
            if ($oldTid <= 0 || $tname === '') {
                mysqli_free_result($tr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'A topic row is incomplete and cannot be duplicated.'];
            }
            if ($oldSid > 0 && !isset($subjectMap[$oldSid])) {
                mysqli_free_result($tr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Topic "' . $tname . '" references a subject that is missing from this examination.'];
            }
            $newSid = $oldSid > 0 ? (int) $subjectMap[$oldSid] : 0;
            if ($newSid <= 0) {
                mysqli_free_result($tr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Topic "' . $tname . '" has no valid subject to copy onto.'];
            }
            $insT = mysqli_prepare(
                $conn,
                'INSERT INTO college_exam_topics (exam_id, exam_subject_id, topic_name, questions_required, sort_order) VALUES (?,?,?,?,?)'
            );
            if (!$insT) {
                mysqli_free_result($tr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy topics.'];
            }
            mysqli_stmt_bind_param($insT, 'iisii', $newId, $newSid, $tname, $tqty, $tsort);
            if (!mysqli_stmt_execute($insT)) {
                mysqli_stmt_close($insT);
                mysqli_free_result($tr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy topics.'];
            }
            $newTid = (int) mysqli_insert_id($conn);
            mysqli_stmt_close($insT);
            if ($newTid <= 0) {
                mysqli_free_result($tr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not allocate new topic ids.'];
            }
            $topicMap[$oldTid] = $newTid;
        }
        mysqli_free_result($tr);
    }

    $hasQSubj = college_exam_questions_has_subject_column($conn);
    $hasQTopic = college_exam_questions_has_topic_column($conn);
    $qr = mysqli_query(
        $conn,
        'SELECT question_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order'
        . ($hasQSubj ? ', exam_subject_id' : '')
        . ($hasQTopic ? ', exam_topic_id' : '')
        . ' FROM college_exam_questions WHERE exam_id=' . $sourceId . ' ORDER BY sort_order ASC, question_id ASC'
    );
    if ($qr === false) {
        return ['ok' => false, 'question_count' => 0, 'error' => 'Could not read questions to duplicate.'];
    }

    $qCount = 0;
    while ($q = mysqli_fetch_assoc($qr)) {
        $text = (string) ($q['question_text'] ?? '');
        $qtype = (string) ($q['question_type'] ?? 'mcq');
        $a = $q['choice_a'] ?? null;
        $b = $q['choice_b'] ?? null;
        $c = $q['choice_c'] ?? null;
        $d = $q['choice_d'] ?? null;
        $correct = (string) ($q['correct_answer'] ?? '');
        $sort = (int) ($q['sort_order'] ?? 0);
        $oldSid = $hasQSubj ? (int) ($q['exam_subject_id'] ?? 0) : 0;
        $oldTid = $hasQTopic ? (int) ($q['exam_topic_id'] ?? 0) : 0;
        $newSid = 0;
        $newTid = 0;
        if ($oldSid > 0) {
            if (!isset($subjectMap[$oldSid])) {
                mysqli_free_result($qr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'A question is linked to a subject that is missing from this examination. Repair the original allocation, then duplicate.'];
            }
            $newSid = (int) $subjectMap[$oldSid];
        }
        if ($oldTid > 0) {
            if (!isset($topicMap[$oldTid])) {
                mysqli_free_result($qr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'A question is linked to a topic that is missing from this examination. Repair the original allocation, then duplicate.'];
            }
            $newTid = (int) $topicMap[$oldTid];
        }
        $newSidSql = $newSid > 0 ? $newSid : 0;
        $newTidSql = $newTid > 0 ? $newTid : 0;

        if ($hasQSubj && $hasQTopic) {
            $insQ = mysqli_prepare(
                $conn,
                'INSERT INTO college_exam_questions (exam_id, exam_subject_id, exam_topic_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
                 VALUES (?,NULLIF(?,0),NULLIF(?,0),?,?,?,?,?,?,?,?)'
            );
            if (!$insQ) {
                mysqli_free_result($qr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy questions.'];
            }
            mysqli_stmt_bind_param($insQ, 'iiisssssssi', $newId, $newSidSql, $newTidSql, $text, $qtype, $a, $b, $c, $d, $correct, $sort);
        } elseif ($hasQTopic) {
            $insQ = mysqli_prepare(
                $conn,
                'INSERT INTO college_exam_questions (exam_id, exam_topic_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
                 VALUES (?,NULLIF(?,0),?,?,?,?,?,?,?,?)'
            );
            if (!$insQ) {
                mysqli_free_result($qr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy questions.'];
            }
            mysqli_stmt_bind_param($insQ, 'iisssssssi', $newId, $newTidSql, $text, $qtype, $a, $b, $c, $d, $correct, $sort);
        } else {
            $insQ = mysqli_prepare(
                $conn,
                'INSERT INTO college_exam_questions (exam_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            if (!$insQ) {
                mysqli_free_result($qr);

                return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy questions.'];
            }
            mysqli_stmt_bind_param($insQ, 'isssssssi', $newId, $text, $qtype, $a, $b, $c, $d, $correct, $sort);
        }
        if (!mysqli_stmt_execute($insQ)) {
            mysqli_stmt_close($insQ);
            mysqli_free_result($qr);

            return ['ok' => false, 'question_count' => 0, 'error' => 'Could not copy questions.'];
        }
        mysqli_stmt_close($insQ);
        $qCount++;
    }
    mysqli_free_result($qr);

    return ['ok' => true, 'question_count' => $qCount];
}

function examination_duplicate_diagnostic_has_extra_choices(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `diagnostic_questions` LIKE 'extra_choices_json'");
    $ok = (bool) ($r && mysqli_fetch_row($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return $ok;
}
