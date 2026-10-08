<?php
/**
 * Regular-exam attempt paper: grouped randomization + persistent snapshot.
 * Legacy attempts without snapshot rows keep college_exam_select_questions_for_attempt
 * + college_exam_prepare_questions_for_attempt (MCQ-then-TF shuffle).
 */

declare(strict_types=1);

require_once __DIR__ . '/college_exam_helpers.php';
require_once __DIR__ . '/college_exam_subject_topic_helpers.php';

function college_exam_attempt_questions_table_ready(mysqli $conn): bool
{
    require_once __DIR__ . '/examination_schema_gate.php';

    return ereview_schema_object_ready_cached($conn, 'table_college_exam_attempt_questions', static function (mysqli $c): bool {
        $r = @mysqli_query($c, "SHOW TABLES LIKE 'college_exam_attempt_questions'");
        $ok = (bool)($r && mysqli_fetch_row($r));
        if ($r) {
            mysqli_free_result($r);
        }

        return $ok;
    });
}

function college_exam_attempt_has_snapshot(mysqli $conn, int $attemptId): bool
{
    $attemptId = (int)$attemptId;
    if ($attemptId <= 0 || !college_exam_attempt_questions_table_ready($conn)) {
        return false;
    }
    $r = @mysqli_query(
        $conn,
        'SELECT attempt_question_id FROM college_exam_attempt_questions WHERE attempt_id=' . $attemptId . ' LIMIT 1'
    );
    $ok = (bool)($r && mysqli_fetch_row($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return $ok;
}

function college_exam_paper_question_shuffle_enabled(array $exam): bool
{
    // New grouped snapshots shuffle only on the main Shuffle Questions flag.
    // shuffle_mcq_questions / shuffle_tf_questions remain independent controls for
    // LEGACY college_exam_prepare_questions_for_attempt (MCQ-then-TF reconstruct).
    // Applying those type flags here would either regroup by type (breaking
    // subject/topic blocks) or silently randomize when Shuffle Questions is OFF.
    return !empty($exam['shuffle_questions']);
}

/**
 * @param list<string> $perm Original letters in displayed A,B,C,D slots.
 */
function college_exam_encode_choice_perm(array $perm): string
{
    $out = [];
    foreach ($perm as $L) {
        $L = strtoupper(trim((string)$L));
        if (preg_match('/^[A-D]$/', $L)) {
            $out[] = $L;
        }
    }

    return count($out) === 4 ? implode(',', $out) : '';
}

function college_exam_apply_choice_perm(array $q, string $encoded): array
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return $q;
    }
    $perm = explode(',', $encoded);
    if (count($perm) !== 4) {
        return $q;
    }
    $letters = ['A', 'B', 'C', 'D'];
    $out = $q;
    $co = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
    if (!preg_match('/^[A-D]$/', $co)) {
        $co = 'A';
    }
    for ($i = 0; $i < 4; $i++) {
        $newL = $letters[$i];
        $oldL = strtoupper(trim((string)$perm[$i]));
        if (!preg_match('/^[A-D]$/', $oldL)) {
            return $q;
        }
        $out['choice_' . strtolower($newL)] = $q['choice_' . strtolower($oldL)] ?? '';
    }
    for ($i = 0; $i < 4; $i++) {
        if (strtoupper(trim((string)$perm[$i])) === $co) {
            $out['correct_answer'] = $letters[$i];
            break;
        }
    }
    $out['_choice_perm'] = $encoded;

    return $out;
}

function college_exam_snapshot_choice_perm(mysqli $conn, int $attemptId, int $questionId): ?string
{
    $attemptId = (int)$attemptId;
    $questionId = (int)$questionId;
    if ($attemptId <= 0 || $questionId <= 0 || !college_exam_attempt_questions_table_ready($conn)) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT choice_perm FROM college_exam_attempt_questions WHERE attempt_id=? AND question_id=? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $questionId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        return null;
    }

    return (string)($row['choice_perm'] ?? '');
}

function college_exam_question_in_attempt_snapshot(mysqli $conn, int $attemptId, int $questionId): bool
{
    $meta = college_exam_snapshot_answer_meta($conn, $attemptId, $questionId);

    return !empty($meta['allowed']);
}

/**
 * One-query snapshot membership + stored choice permutation for an attempt question.
 *
 * @return array{allowed:bool, has_snapshot:bool, choice_perm:string}
 */
function college_exam_snapshot_answer_meta(mysqli $conn, int $attemptId, int $questionId): array
{
    $attemptId = (int)$attemptId;
    $questionId = (int)$questionId;
    $empty = ['allowed' => false, 'has_snapshot' => false, 'choice_perm' => ''];
    if ($attemptId <= 0 || $questionId <= 0 || !college_exam_attempt_questions_table_ready($conn)) {
        return ['allowed' => $attemptId > 0 && $questionId > 0, 'has_snapshot' => false, 'choice_perm' => ''];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT question_id, choice_perm FROM college_exam_attempt_questions WHERE attempt_id=? AND question_id=? LIMIT 1'
    );
    if (!$stmt) {
        return $empty;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $questionId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($row) {
        return [
            'allowed' => true,
            'has_snapshot' => true,
            'choice_perm' => (string)($row['choice_perm'] ?? ''),
        ];
    }
    if (!college_exam_attempt_has_snapshot($conn, $attemptId)) {
        return ['allowed' => true, 'has_snapshot' => false, 'choice_perm' => ''];
    }

    return $empty;
}

/**
 * One-query question + snapshot membership for save_answer (avoids extra has_snapshot SELECT).
 *
 * @return array{ok:bool, question:?array<string,mixed>, snap:array{allowed:bool,has_snapshot:bool,choice_perm:string}}
 */
function college_exam_load_save_question_context(mysqli $conn, int $examId, int $attemptId, int $questionId): array
{
    $emptySnap = ['allowed' => false, 'has_snapshot' => false, 'choice_perm' => ''];
    if ($examId <= 0 || $attemptId <= 0 || $questionId <= 0) {
        return ['ok' => false, 'question' => null, 'snap' => $emptySnap];
    }
    if (!college_exam_attempt_questions_table_ready($conn)) {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM college_exam_questions WHERE question_id=? AND exam_id=? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'question' => null, 'snap' => $emptySnap];
        }
        mysqli_stmt_bind_param($stmt, 'ii', $questionId, $examId);
        mysqli_stmt_execute($stmt);
        $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return [
            'ok' => (bool)$qRow,
            'question' => $qRow ?: null,
            'snap' => ['allowed' => (bool)$qRow, 'has_snapshot' => false, 'choice_perm' => ''],
        ];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT q.*, aq.question_id AS _snap_qid, aq.choice_perm AS _snap_perm,
                (SELECT aq2.attempt_question_id FROM college_exam_attempt_questions aq2
                 WHERE aq2.attempt_id=? LIMIT 1) AS _any_snap
         FROM college_exam_questions q
         LEFT JOIN college_exam_attempt_questions aq
           ON aq.attempt_id=? AND aq.question_id=q.question_id
         WHERE q.question_id=? AND q.exam_id=?
         LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'question' => null, 'snap' => $emptySnap];
    }
    mysqli_stmt_bind_param($stmt, 'iiii', $attemptId, $attemptId, $questionId, $examId);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$qRow) {
        return ['ok' => false, 'question' => null, 'snap' => $emptySnap];
    }
    $hasRow = !empty($qRow['_snap_qid']);
    $hasAny = !empty($qRow['_any_snap']);
    $perm = (string)($qRow['_snap_perm'] ?? '');
    unset($qRow['_snap_qid'], $qRow['_snap_perm'], $qRow['_any_snap']);
    if ($hasRow) {
        $snap = ['allowed' => true, 'has_snapshot' => true, 'choice_perm' => $perm];
    } elseif (!$hasAny) {
        $snap = ['allowed' => true, 'has_snapshot' => false, 'choice_perm' => ''];
    } else {
        $snap = $emptySnap;
    }

    return ['ok' => true, 'question' => $qRow, 'snap' => $snap];
}

/**
 * @param list<array<string,mixed>> $pool
 * @return list<array<string,mixed>>
 */
function college_exam_pick_from_pool(array $pool, int $required, bool $shuffle, int $seed): array
{
    $rows = array_values($pool);
    if ($shuffle && $rows !== []) {
        $rows = college_exam_shuffle_order($rows, $seed);
    }
    if ($required > 0 && count($rows) > $required) {
        $rows = array_slice($rows, 0, $required);
    }

    return $rows;
}

/**
 * Assemble a new attempt paper (grouped). Does not persist.
 *
 * @return list<array<string,mixed>>
 */
function college_exam_assemble_attempt_paper(mysqli $conn, array $exam, int $attemptId): array
{
    $examId = (int)($exam['exam_id'] ?? 0);
    $attemptId = (int)$attemptId;
    if ($examId <= 0) {
        return [];
    }
    $mode = college_exam_get_breakdown_mode($conn, $examId);
    $shuffleQ = college_exam_paper_question_shuffle_enabled($exam);
    $shuffleC = !empty($exam['shuffle_choices']);
    $base = $attemptId * 100000 + $examId;
    $hasTopicCol = college_exam_questions_has_topic_column($conn);
    $hasSubjCol = college_exam_questions_has_subject_column($conn);

    $allQ = [];
    $bySubject = [];
    $byTopic = [];
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

    $decorate = static function (array $rows, int $sid, string $sName, int $tid, string $tName): array {
        foreach ($rows as &$rq) {
            $rq['_exam_subject_id'] = $sid;
            $rq['_subject_name'] = $sName;
            $rq['_exam_topic_id'] = $tid;
            $rq['_topic_name'] = $tName;
        }
        unset($rq);

        return $rows;
    };

    $out = [];
    if ($mode === 'overall') {
        $req = college_exam_get_total_questions_required($conn, $examId);
        $out = college_exam_pick_from_pool($allQ, $req, $shuffleQ, $base + 17);
        $out = $decorate($out, 0, '', 0, '');
    } else {
        $tree = college_exam_load_subjects_with_topics($conn, $examId);
        if ($tree === []) {
            $out = college_exam_pick_from_pool($allQ, 0, $shuffleQ, $base + 17);
            $out = $decorate($out, 0, '', 0, '');
        } else {
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

            foreach ($tree as $s) {
                $sid = (int)$s['exam_subject_id'];
                $sName = (string)$s['subject_name'];
                $subReq = max(0, (int)$s['questions_required']);
                if ($mode === 'subject' || $s['topics'] === []) {
                    $picked = college_exam_pick_from_pool(
                        $bySubject[$sid] ?? [],
                        $subReq,
                        $shuffleQ,
                        $base + 1000 + $sid
                    );
                    foreach ($decorate($picked, $sid, $sName, 0, '') as $q) {
                        $out[] = $q;
                    }
                    continue;
                }
                foreach ($s['topics'] as $t) {
                    $tid = (int)$t['exam_topic_id'];
                    $req = max(0, (int)$t['questions_required']);
                    $picked = college_exam_pick_from_pool(
                        $byTopic[$tid] ?? [],
                        $req,
                        $shuffleQ,
                        $base + 500000 + $tid
                    );
                    foreach ($decorate($picked, $sid, $sName, $tid, (string)$t['topic_name']) as $q) {
                        $out[] = $q;
                    }
                }
            }
        }
    }

    foreach ($out as $i => $q) {
        $perm = '';
        $qt = strtolower(trim((string)($q['question_type'] ?? 'mcq')));
        $isTf = ($qt === 'tf' || $qt === 'true_false' || $qt === 'truefalse');
        if ($shuffleC && !$isTf) {
            $qid = (int)($q['question_id'] ?? 0);
            $letters = college_exam_shuffle_order(['A', 'B', 'C', 'D'], $base + $qid * 7919);
            $perm = college_exam_encode_choice_perm($letters);
            $q = college_exam_apply_choice_perm($q, $perm);
        }
        $q['_choice_perm'] = $perm;
        $q['_display_position'] = $i + 1;
        $out[$i] = $q;
    }

    return $out;
}

/**
 * Persist using integer-null-safe inserts (subject/topic ids).
 *
 * @param list<array<string,mixed>> $paper
 */
function college_exam_persist_attempt_snapshot_safe(mysqli $conn, int $attemptId, array $paper, bool $useOwnTransaction = true): array
{
    $attemptId = (int)$attemptId;
    if ($attemptId <= 0) {
        return ['ok' => false, 'error' => 'Invalid attempt'];
    }
    if (!college_exam_attempt_questions_table_ready($conn)) {
        return ['ok' => false, 'error' => 'Attempt snapshot table is not available.'];
    }
    $rows = [];
    foreach ($paper as $q) {
        $qid = (int)($q['question_id'] ?? 0);
        $pos = (int)($q['_display_position'] ?? 0);
        if ($qid <= 0 || $pos <= 0) {
            continue;
        }
        $sid = (int)($q['_exam_subject_id'] ?? $q['exam_subject_id'] ?? 0);
        $tid = (int)($q['_exam_topic_id'] ?? $q['exam_topic_id'] ?? 0);
        $rows[] = [
            'qid' => $qid,
            'pos' => $pos,
            'sid' => $sid > 0 ? $sid : null,
            'tid' => $tid > 0 ? $tid : null,
            'sName' => (string)($q['_subject_name'] ?? $q['subject_name'] ?? ''),
            'tName' => (string)($q['_topic_name'] ?? $q['topic_name'] ?? ''),
            'perm' => (string)($q['_choice_perm'] ?? ''),
        ];
    }
    if ($rows === []) {
        return ['ok' => true, 'empty' => true];
    }

    $ownTxn = false;
    if ($useOwnTransaction && function_exists('mysqli_begin_transaction')) {
        $ownTxn = mysqli_begin_transaction($conn);
    }
    $okDel = mysqli_query($conn, 'DELETE FROM college_exam_attempt_questions WHERE attempt_id=' . $attemptId);
    if (!$okDel) {
        if ($ownTxn) {
            mysqli_rollback($conn);
        }

        return ['ok' => false, 'error' => 'Could not save attempt questions.'];
    }

    $chunkSize = 40;
    $n = count($rows);
    for ($offset = 0; $offset < $n; $offset += $chunkSize) {
        $chunk = array_slice($rows, $offset, $chunkSize);
        $values = [];
        foreach ($chunk as $item) {
            $sidSql = $item['sid'] !== null ? (string)(int)$item['sid'] : 'NULL';
            $tidSql = $item['tid'] !== null ? (string)(int)$item['tid'] : 'NULL';
            $values[] = '('
                . $attemptId . ','
                . (int)$item['qid'] . ','
                . (int)$item['pos'] . ','
                . $sidSql . ','
                . $tidSql . ','
                . "'" . mysqli_real_escape_string($conn, $item['sName']) . "',"
                . "'" . mysqli_real_escape_string($conn, $item['tName']) . "',"
                . "'" . mysqli_real_escape_string($conn, $item['perm']) . "'"
                . ')';
        }
        $sql = 'INSERT INTO college_exam_attempt_questions
            (attempt_id, question_id, display_position, exam_subject_id, exam_topic_id, subject_name, topic_name, choice_perm)
            VALUES ' . implode(',', $values);
        try {
            $okIns = mysqli_query($conn, $sql);
            $insErr = $okIns ? '' : (string)mysqli_error($conn);
        } catch (Throwable $e) {
            $okIns = false;
            $insErr = $e->getMessage();
        }
        if (!$okIns) {
            if ($ownTxn) {
                mysqli_rollback($conn);
            }

            return ['ok' => false, 'error' => 'Could not save attempt questions.', 'db_error' => $insErr];
        }
    }

    if ($ownTxn && !mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not save attempt questions.'];
    }

    return ['ok' => true];
}

function college_exam_create_attempt_snapshot(mysqli $conn, array $exam, int $attemptId, bool $useOwnTransaction = true): array
{
    $paper = college_exam_assemble_attempt_paper($conn, $exam, $attemptId);
    if ($paper === []) {
        return ['ok' => true, 'empty' => true];
    }

    return college_exam_persist_attempt_snapshot_safe($conn, $attemptId, $paper, $useOwnTransaction);
}

/**
 * Create or resume a regular-exam attempt and persist its snapshot in one transaction.
 *
 * @return array{ok:bool, attempt_id:int, resumed?:bool, already_submitted?:bool, created?:bool, error?:string}
 */
function college_exam_start_attempt_with_snapshot(
    mysqli $conn,
    array $exam,
    int $userId,
    string $startedSql,
    ?string $expiresAt
): array {
    $examId = (int)($exam['exam_id'] ?? 0);
    $userId = (int)$userId;
    if ($examId <= 0 || $userId <= 0) {
        return ['ok' => false, 'attempt_id' => 0, 'error' => 'Invalid exam'];
    }
    $expiresSql = ($expiresAt !== null && $expiresAt !== '') ? $expiresAt : null;
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'attempt_id' => 0, 'error' => 'Could not start attempt'];
    }

    $loadLocked = static function () use ($conn, $examId, $userId): ?array {
        $st = mysqli_prepare(
            $conn,
            'SELECT * FROM college_exam_attempts WHERE user_id=? AND exam_id=? LIMIT 1 FOR UPDATE'
        );
        if (!$st) {
            return null;
        }
        mysqli_stmt_bind_param($st, 'ii', $userId, $examId);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);

        return $row ?: null;
    };

    $row = $loadLocked();

    if (!$row) {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at)
             VALUES (?, ?, 'in_progress', ?, ?, ?)"
        );
        if (!$ins) {
            mysqli_rollback($conn);

            return ['ok' => false, 'attempt_id' => 0, 'error' => 'Could not start attempt'];
        }
        $expBind = $expiresSql ?? '';
        mysqli_stmt_bind_param($ins, 'iisss', $examId, $userId, $startedSql, $expBind, $startedSql);
        $okIns = false;
        $errno = 0;
        try {
            $okIns = mysqli_stmt_execute($ins);
            $errno = (int)mysqli_errno($conn);
        } catch (Throwable $e) {
            $okIns = false;
            $errno = (int)$e->getCode();
            if ($errno === 0) {
                $errno = (int)mysqli_errno($conn);
            }
        }
        mysqli_stmt_close($ins);
        if (!$okIns) {
            if ($errno === 1062) {
                $row = $loadLocked();
            } else {
                mysqli_rollback($conn);

                return ['ok' => false, 'attempt_id' => 0, 'error' => 'Could not start attempt'];
            }
        } else {
            $newId = (int)mysqli_insert_id($conn);
            $snap = college_exam_create_attempt_snapshot($conn, $exam, $newId, false);
            if (empty($snap['ok'])) {
                mysqli_rollback($conn);

                return ['ok' => false, 'attempt_id' => 0, 'error' => (string)($snap['error'] ?? 'Could not prepare this examination.')];
            }
            if (!mysqli_commit($conn)) {
                mysqli_rollback($conn);

                return ['ok' => false, 'attempt_id' => 0, 'error' => 'Could not start attempt'];
            }

            return ['ok' => true, 'attempt_id' => $newId, 'created' => true];
        }
    }

    if (!$row) {
        mysqli_rollback($conn);

        return ['ok' => false, 'attempt_id' => 0, 'error' => 'Could not start attempt'];
    }

    $aid = (int)($row['attempt_id'] ?? 0);
    $st = college_exam_attempt_status_normalized($row);
    if ($st === 'submitted' || college_exam_attempt_is_effectively_submitted($row)) {
        mysqli_commit($conn);

        return ['ok' => true, 'attempt_id' => $aid, 'already_submitted' => true];
    }
    if ($st === 'in_progress') {
        mysqli_commit($conn);

        return ['ok' => true, 'attempt_id' => $aid, 'resumed' => true];
    }

    // expired (or other resettable): replace answers/snapshot inside this transaction only.
    mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $aid);
    college_exam_delete_attempt_snapshot($conn, $aid);
    $emptyState = '{"current_index":0,"flags":[],"updated_at":0}';
    $upd = mysqli_prepare(
        $conn,
        "UPDATE college_exam_attempts SET status='in_progress', started_at=?, expires_at=?, submitted_at=NULL, score=NULL, correct_count=NULL, total_count=NULL, ui_state_json=?, last_seen_at=?, exam_session_lock=NULL, tab_switch_count=0, last_tab_switch_at=NULL WHERE attempt_id=? AND user_id=?"
    );
    if (!$upd) {
        mysqli_rollback($conn);

        return ['ok' => false, 'attempt_id' => $aid, 'error' => 'Could not start attempt'];
    }
    $expBind = $expiresSql ?? '';
    mysqli_stmt_bind_param($upd, 'ssssii', $startedSql, $expBind, $emptyState, $startedSql, $aid, $userId);
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        mysqli_rollback($conn);

        return ['ok' => false, 'attempt_id' => $aid, 'error' => 'Could not start attempt'];
    }
    mysqli_stmt_close($upd);
    $snap = college_exam_create_attempt_snapshot($conn, $exam, $aid, false);
    if (empty($snap['ok'])) {
        mysqli_rollback($conn);

        return ['ok' => false, 'attempt_id' => $aid, 'error' => (string)($snap['error'] ?? 'Could not prepare this examination.')];
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'attempt_id' => $aid, 'error' => 'Could not start attempt'];
    }

    return ['ok' => true, 'attempt_id' => $aid, 'created' => true];
}

function college_exam_delete_attempt_snapshot(mysqli $conn, int $attemptId): void
{
    $attemptId = (int)$attemptId;
    if ($attemptId <= 0 || !college_exam_attempt_questions_table_ready($conn)) {
        return;
    }
    @mysqli_query($conn, 'DELETE FROM college_exam_attempt_questions WHERE attempt_id=' . $attemptId);
}

/**
 * @return list<array<string,mixed>>
 */
function college_exam_load_attempt_snapshot_questions(mysqli $conn, int $attemptId): array
{
    $attemptId = (int)$attemptId;
    if ($attemptId <= 0 || !college_exam_attempt_questions_table_ready($conn)) {
        return [];
    }
    $sql = 'SELECT aq.display_position, aq.exam_subject_id AS snap_subject_id, aq.exam_topic_id AS snap_topic_id,
                   aq.subject_name AS snap_subject_name, aq.topic_name AS snap_topic_name, aq.choice_perm,
                   q.*
            FROM college_exam_attempt_questions aq
            INNER JOIN college_exam_questions q ON q.question_id = aq.question_id
            WHERE aq.attempt_id=' . $attemptId . '
            ORDER BY aq.display_position ASC, aq.attempt_question_id ASC';
    $r = mysqli_query($conn, $sql);
    $out = [];
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $perm = (string)($row['choice_perm'] ?? '');
        $q = college_exam_apply_choice_perm($row, $perm);
        $sid = (int)($row['snap_subject_id'] ?? 0);
        if ($sid <= 0) {
            $sid = (int)($row['exam_subject_id'] ?? 0);
        }
        $tid = (int)($row['snap_topic_id'] ?? 0);
        if ($tid <= 0) {
            $tid = (int)($row['exam_topic_id'] ?? 0);
        }
        $q['_exam_subject_id'] = $sid;
        $q['_exam_topic_id'] = $tid;
        $q['_subject_name'] = trim((string)($row['snap_subject_name'] ?? ''));
        $q['_topic_name'] = trim((string)($row['snap_topic_name'] ?? ''));
        $q['_display_position'] = (int)($row['display_position'] ?? 0);
        $q['_choice_perm'] = $perm;
        $out[] = $q;
    }
    if ($r) {
        mysqli_free_result($r);
    }

    return $out;
}

/**
 * Fill empty _subject_name/_topic_name from subject/topic IDs. Display-only; does not write snapshot rows.
 *
 * @param list<array<string,mixed>> $paper
 * @return list<array<string,mixed>>
 */
function college_exam_hydrate_attempt_paper_labels(mysqli $conn, array $paper): array
{
    if ($paper === []) {
        return $paper;
    }
    $examId = 0;
    $need = false;
    foreach ($paper as $q) {
        if (!is_array($q)) {
            continue;
        }
        if ($examId <= 0) {
            $examId = (int)($q['exam_id'] ?? 0);
        }
        $sid = (int)($q['_exam_subject_id'] ?? 0);
        if ($sid <= 0) {
            $sid = (int)($q['exam_subject_id'] ?? 0);
        }
        $tid = (int)($q['_exam_topic_id'] ?? 0);
        if ($tid <= 0) {
            $tid = (int)($q['exam_topic_id'] ?? 0);
        }
        $sName = trim((string)($q['_subject_name'] ?? ''));
        $tName = trim((string)($q['_topic_name'] ?? ''));
        if (($sid > 0 && $sName === '') || ($tid > 0 && $tName === '')) {
            $need = true;
        }
    }
    if (!$need || $examId <= 0) {
        return $paper;
    }

    $subjectNameById = [];
    $topicNameById = [];
    foreach (college_exam_load_subjects_with_topics($conn, $examId) as $s) {
        $sid = (int)($s['exam_subject_id'] ?? 0);
        if ($sid > 0) {
            $subjectNameById[$sid] = trim((string)($s['subject_name'] ?? ''));
        }
        foreach ($s['topics'] ?? [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tid = (int)($t['exam_topic_id'] ?? 0);
            if ($tid > 0) {
                $topicNameById[$tid] = trim((string)($t['topic_name'] ?? ''));
            }
        }
    }
    if ($subjectNameById === [] && $topicNameById === []) {
        return $paper;
    }

    foreach ($paper as $i => $q) {
        if (!is_array($q)) {
            continue;
        }
        $sid = (int)($q['_exam_subject_id'] ?? 0);
        if ($sid <= 0) {
            $sid = (int)($q['exam_subject_id'] ?? 0);
        }
        $tid = (int)($q['_exam_topic_id'] ?? 0);
        if ($tid <= 0) {
            $tid = (int)($q['exam_topic_id'] ?? 0);
        }
        $sName = trim((string)($q['_subject_name'] ?? ''));
        $tName = trim((string)($q['_topic_name'] ?? ''));
        if ($sName === '' && $sid > 0) {
            $sName = (string)($subjectNameById[$sid] ?? '');
        }
        if ($tName === '' && $tid > 0) {
            $tName = (string)($topicNameById[$tid] ?? '');
        }
        $paper[$i]['_exam_subject_id'] = $sid;
        $paper[$i]['_exam_topic_id'] = $tid;
        $paper[$i]['_subject_name'] = $sName;
        $paper[$i]['_topic_name'] = $tName;
    }

    return $paper;
}

/**
 * Load the paper the student actually sat. Snapshot if present; else legacy reconstruct.
 *
 * @param array<string,mixed> $exam
 * @param array<string,mixed> $attempt
 * @return list<array<string,mixed>>
 */
function college_exam_questions_for_student_attempt(mysqli $conn, array $exam, array $attempt): array
{
    $attemptId = (int)($attempt['attempt_id'] ?? 0);
    $examId = (int)($exam['exam_id'] ?? $attempt['exam_id'] ?? 0);
    if ($attemptId > 0 && college_exam_attempt_has_snapshot($conn, $attemptId)) {
        return college_exam_hydrate_attempt_paper_labels(
            $conn,
            college_exam_load_attempt_snapshot_questions($conn, $attemptId)
        );
    }
    $questions = college_exam_select_questions_for_attempt($conn, $examId);
    $questions = college_exam_prepare_questions_for_attempt($questions, $exam, $attemptId);

    return college_exam_hydrate_attempt_paper_labels($conn, $questions);
}

function college_exam_configured_attempt_length(mysqli $conn, int $examId): int
{
    $examId = (int)$examId;
    $mode = college_exam_get_breakdown_mode($conn, $examId);
    if ($mode === 'overall') {
        $req = college_exam_get_total_questions_required($conn, $examId);
        if ($req > 0) {
            return $req;
        }
    } else {
        $sum = 0;
        foreach (college_exam_load_subjects_with_topics($conn, $examId) as $s) {
            $sum += max(0, (int)($s['questions_required'] ?? 0));
        }
        if ($sum > 0) {
            return $sum;
        }
    }
    $c = 0;
    $qr = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . $examId);
    if ($qr && ($row = mysqli_fetch_assoc($qr))) {
        $c = (int)($row['c'] ?? 0);
    }
    if ($qr) {
        mysqli_free_result($qr);
    }

    return $c;
}

/**
 * Canonical professor authoring order (subject/topic config order, not student shuffle).
 *
 * @param list<array<string,mixed>> $questions
 * @return list<array<string,mixed>>
 */
function college_exam_canonical_authoring_rows(mysqli $conn, int $examId, array $questions): array
{
    $mode = college_exam_get_breakdown_mode($conn, $examId);
    $subjectNameById = [];
    $topicNameById = [];
    $topicParentById = [];
    if ($mode === 'subject' || $mode === 'subject_topic') {
        foreach (college_exam_load_subjects_with_topics($conn, $examId) as $s) {
            $sid = (int)$s['exam_subject_id'];
            $sName = (string)$s['subject_name'];
            $subjectNameById[$sid] = $sName;
            foreach ($s['topics'] as $t) {
                $tid = (int)$t['exam_topic_id'];
                $topicNameById[$tid] = (string)$t['topic_name'];
                $topicParentById[$tid] = $sName;
            }
        }
    }

    $ordered = [];
    foreach ($questions as $q) {
        if (!is_array($q)) {
            continue;
        }
        $qid = (int)($q['question_id'] ?? 0);
        if ($qid <= 0) {
            continue;
        }
        $sid = (int)($q['exam_subject_id'] ?? 0);
        $tid = (int)($q['exam_topic_id'] ?? 0);
        $sName = (string)($subjectNameById[$sid] ?? '');
        $tName = (string)($topicNameById[$tid] ?? '');
        if ($mode === 'overall') {
            $q['_group_key'] = 'overall';
            $q['_group_label'] = '';
            $q['_group_parent'] = '';
        } elseif ($mode === 'subject_topic' && $tid > 0) {
            $q['_group_key'] = 't:' . $tid;
            $q['_group_label'] = $tName !== '' ? $tName : 'Topic';
            $q['_group_parent'] = (string)($topicParentById[$tid] ?? $sName);
        } elseif ($sid > 0) {
            $q['_group_key'] = 's:' . $sid;
            $q['_group_label'] = $sName !== '' ? $sName : 'Subject';
            $q['_group_parent'] = '';
        } else {
            $q['_group_key'] = 'unassigned';
            $q['_group_label'] = 'Unassigned';
            $q['_group_parent'] = '';
        }
        $ordered[] = $q;
    }

    usort($ordered, static function (array $a, array $b): int {
        $so = (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);
        if ($so !== 0) {
            return $so;
        }

        return (int)($a['question_id'] ?? 0) <=> (int)($b['question_id'] ?? 0);
    });

    foreach ($ordered as $i => $q) {
        $ordered[$i]['display_number'] = $i + 1;
        $ordered[$i]['_canonical_order'] = $i + 1;
    }

    return $ordered;
}
