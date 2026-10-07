<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/college_exam_attempt_paper.php';
require_once dirname(__DIR__) . '/includes/examination_ajax_support.php';

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

header('Content-Type: application/json; charset=UTF-8');
$__examAjaxStarted = microtime(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    examination_ajax_json_exit(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$userId = (int)getCurrentUserId();
$conn = $GLOBALS['conn'];
$action = (string)($_POST['action'] ?? '');
// Do NOT auto-finalize on write/heartbeat/submit paths — that raced timeout submit and
// dropped in-flight answers. List/dashboard/monitor pages still finalize expired attempts.
// load_state is take-page boot: defer finalize when the requested attempt is already
// expired+in_progress so the client timeout bulk submit can flush first.
if ($action === 'load_state' || $action === '') {
    $deferLoadStateFinalize = false;
    if ($action === 'load_state') {
        $peekAid = sanitizeInt($_POST['attempt_id'] ?? 0);
        if ($peekAid > 0) {
            $peekStmt = mysqli_prepare(
                $conn,
                'SELECT attempt_id, status, expires_at FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1'
            );
            if ($peekStmt) {
                mysqli_stmt_bind_param($peekStmt, 'ii', $peekAid, $userId);
                mysqli_stmt_execute($peekStmt);
                $peekRow = mysqli_fetch_assoc(mysqli_stmt_get_result($peekStmt));
                mysqli_stmt_close($peekStmt);
                if (college_exam_attempt_is_expired_in_progress($peekRow ?: null)) {
                    $deferLoadStateFinalize = true;
                }
            }
        }
    }
    if (!$deferLoadStateFinalize) {
        college_exam_finalize_expired_in_progress($conn, 0, $userId, 0);
    }
}
// Release PHP session lock after auth/session values are read so concurrent examinee
// AJAX (autosave, heartbeat, submit) does not serialize behind this request.
if (function_exists('ereview_release_session_lock')) {
    ereview_release_session_lock();
}

function college_exam_ajax_verify_attempt_access(mysqli $conn, array $attempt, int $userId, ?array &$examOut = null): bool
{
    $examOut = null;
    $examId = (int)($attempt['exam_id'] ?? 0);
    if ($examId <= 0) {
        return false;
    }
    $pubWhere = college_exam_where_published_sql();
    $stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND {$pubWhere} LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $examId);
    mysqli_stmt_execute($stmt);
    $exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$exam) {
        return false;
    }

    if (!college_exam_user_can_view($conn, $userId, $exam, $attempt)) {
        return false;
    }
    $examOut = $exam;

    return true;
}

/**
 * @return array<string,mixed>|null
 * @param-out array<string,mixed>|null $examOut Published exam row when access succeeds (avoids duplicate SELECT on save_answer).
 */
/**
 * @param bool $requireNotExpired When false, allow in_progress after timer end (save flush / timeout submit).
 */
function college_exam_ajax_load_active_attempt(
    mysqli $conn,
    int $attemptId,
    int $userId,
    bool $requireNotExpired = true,
    ?array &$examOut = null
): ?array {
    $examOut = null;
    $stmt = mysqli_prepare($conn, "SELECT a.attempt_id, a.exam_id, a.status, a.expires_at FROM college_exam_attempts a WHERE a.attempt_id=? AND a.user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || $attempt['status'] !== 'in_progress') {
        return null;
    }
    if (!college_exam_ajax_verify_attempt_access($conn, $attempt, $userId, $examOut)) {
        $examOut = null;

        return null;
    }
    if ($requireNotExpired) {
        $expRaw = $attempt['expires_at'] ?? '';
        if ($expRaw !== '') {
            $expTs = strtotime((string)$expRaw);
            if ($expTs !== false && $expTs < time()) {
                $examOut = null;

                return null;
            }
        }
    }
    return $attempt;
}

require_once dirname(__DIR__) . '/includes/college_exam_attempt_events.php';

if ($action === 'tab_blur' || $action === 'tab_visibility') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $attempt = college_exam_ajax_load_active_attempt($conn, $attemptId, $userId);
    if (!$attempt) {
        // Monitoring only while attempt is in_progress — ignore intro/result noise.
        echo json_encode(['ok' => false, 'error' => 'Attempt not active', 'monitoring' => false]);
        exit;
    }
    $examIdEv = (int)($attempt['exam_id'] ?? 0);
    $visibility = strtolower(trim((string)($_POST['visibility'] ?? '')));
    if ($action === 'tab_blur') {
        $visibility = 'hidden';
    }
    if (!in_array($visibility, ['hidden', 'visible'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid visibility']);
        exit;
    }

    $lastType = college_exam_attempt_events_last_type($conn, $attemptId);
    $nowSql = date('Y-m-d H:i:s');
    $count = 0;
    $incremented = false;

    if ($visibility === 'hidden') {
        // Count a switch only on VISIBLE → HIDDEN (not duplicate hidden events).
        if ($lastType === 'tab_hidden') {
            $cr = mysqli_query($conn, 'SELECT tab_switch_count FROM college_exam_attempts WHERE attempt_id=' . (int)$attemptId . ' LIMIT 1');
            if ($cr) {
                $count = (int)(mysqli_fetch_assoc($cr)['tab_switch_count'] ?? 0);
                mysqli_free_result($cr);
            }
            echo json_encode([
                'ok' => true,
                'event_type' => 'tab_hidden',
                'duplicate' => true,
                'tab_switch_count' => $count,
                'last_seen_at' => $nowSql,
            ]);
            exit;
        }
        $upd = mysqli_prepare(
            $conn,
            'UPDATE college_exam_attempts SET tab_switch_count = COALESCE(tab_switch_count, 0) + 1, last_tab_switch_at=?, last_seen_at=? WHERE attempt_id=? AND user_id=?'
        );
        if (!$upd) {
            echo json_encode(['ok' => false, 'error' => 'Update failed']);
            exit;
        }
        mysqli_stmt_bind_param($upd, 'ssii', $nowSql, $nowSql, $attemptId, $userId);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        $incremented = true;
        college_exam_attempt_event_record($conn, $attemptId, $userId, $examIdEv, 'tab_hidden', [
            'visibility' => 'hidden',
            'client_ts' => sanitizeInt($_POST['client_ts'] ?? 0) ?: null,
        ]);
    } else {
        // Returning: record visible, do NOT increment switch count.
        if ($lastType === 'tab_visible') {
            $touch = mysqli_prepare($conn, 'UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=?');
            if ($touch) {
                mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
                mysqli_stmt_execute($touch);
                mysqli_stmt_close($touch);
            }
            $cr = mysqli_query($conn, 'SELECT tab_switch_count FROM college_exam_attempts WHERE attempt_id=' . (int)$attemptId . ' LIMIT 1');
            if ($cr) {
                $count = (int)(mysqli_fetch_assoc($cr)['tab_switch_count'] ?? 0);
                mysqli_free_result($cr);
            }
            echo json_encode([
                'ok' => true,
                'event_type' => 'tab_visible',
                'duplicate' => true,
                'tab_switch_count' => $count,
                'last_seen_at' => $nowSql,
            ]);
            exit;
        }
        $awaySec = null;
        $hiddenAt = college_exam_attempt_events_last_at($conn, $attemptId, 'tab_hidden');
        if ($hiddenAt) {
            $hts = strtotime($hiddenAt);
            if ($hts !== false) {
                $awaySec = max(0, time() - $hts);
            }
        }
        $touch = mysqli_prepare($conn, 'UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=?');
        if ($touch) {
            mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
            mysqli_stmt_execute($touch);
            mysqli_stmt_close($touch);
        }
        college_exam_attempt_event_record($conn, $attemptId, $userId, $examIdEv, 'tab_visible', [
            'visibility' => 'visible',
            'away_seconds' => $awaySec,
            'client_ts' => sanitizeInt($_POST['client_ts'] ?? 0) ?: null,
        ]);
    }

    $cr = mysqli_query($conn, 'SELECT tab_switch_count FROM college_exam_attempts WHERE attempt_id=' . (int)$attemptId . ' LIMIT 1');
    if ($cr) {
        $crow = mysqli_fetch_assoc($cr);
        $count = (int)($crow['tab_switch_count'] ?? 0);
        mysqli_free_result($cr);
    }
    echo json_encode([
        'ok' => true,
        'event_type' => $visibility === 'hidden' ? 'tab_hidden' : 'tab_visible',
        'tab_switch_count' => $count,
        'incremented' => $incremented,
        'last_tab_switch_at' => $visibility === 'hidden' ? $nowSql : null,
        'last_seen_at' => $nowSql,
    ]);
    exit;
}

if ($action === 'save_answer') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        examination_ajax_json_exit(['ok' => false, 'error' => 'Invalid request']);
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $selected = strtoupper(trim((string)($_POST['selected_answer'] ?? '')));
    $saveSeq = max(0, (int)($_POST['save_seq'] ?? 0));
    // Stop accepting new interactive answers at official expires_at.
    // Timeout flush goes through action=submit (bulk payload), not save_answer.
    $examRow = null;
    $attempt = college_exam_ajax_load_active_attempt($conn, $attemptId, $userId, true, $examRow);
    if (!$attempt) {
        $stChk = mysqli_prepare($conn, 'SELECT status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
        if ($stChk) {
            mysqli_stmt_bind_param($stChk, 'ii', $attemptId, $userId);
            mysqli_stmt_execute($stChk);
            $rowChk = mysqli_fetch_assoc(mysqli_stmt_get_result($stChk));
            mysqli_stmt_close($stChk);
            $stNorm = strtolower(trim((string)($rowChk['status'] ?? '')));
            if ($stNorm === 'submitted' || $stNorm === 'expired') {
                examination_ajax_json_exit([
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'attempt_finalized',
                ]);
            }
        }
        examination_ajax_json_exit(['ok' => false, 'error' => 'Attempt not active', 'expired' => true]);
    }

    // Reuse attempt/exam already validated above — avoids duplicate SELECTs inside upsert.
    $saved = college_exam_upsert_attempt_answer(
        $conn,
        $attemptId,
        $userId,
        $questionId,
        $selected,
        $saveSeq,
        $attempt,
        is_array($examRow) ? $examRow : null
    );
    if (!empty($saved['ignored'])) {
        examination_ajax_json_exit([
            'ok' => true,
            'ignored' => true,
            'reason' => (string)($saved['reason'] ?? 'attempt_finalized'),
        ]);
    }
    if (empty($saved['ok'])) {
        examination_ajax_log('college_exam', [
            'action' => 'save_answer',
            'user_id' => $userId,
            'attempt_id' => $attemptId,
            'ok' => 0,
            'error' => $saved['error'] ?? 'Could not save',
            'errno' => 0,
            'duration_ms' => (int)round((microtime(true) - $__examAjaxStarted) * 1000),
        ]);
        examination_ajax_json_exit(['ok' => false, 'error' => $saved['error'] ?? 'Could not save']);
    }

    $nowSql = date('Y-m-d H:i:s');
    $touch = mysqli_prepare($conn, "UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=? AND status='in_progress'");
    if ($touch) {
        mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
        mysqli_stmt_execute($touch);
        mysqli_stmt_close($touch);
    }

    examination_ajax_json_exit([
        'ok' => true,
        'saved_at' => date('H:i:s'),
        'stale' => !empty($saved['stale']),
        'saved_seq' => (int)($saved['saved_seq'] ?? $saveSeq),
        'current_seq' => (int)($saved['current_seq'] ?? $saveSeq),
        'current_answer' => strtoupper(trim((string)($saved['current_answer'] ?? ''))),
    ]);
}

if ($action === 'sync_state') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $attempt = college_exam_ajax_load_active_attempt($conn, $attemptId, $userId);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }

    $currentIndex = sanitizeInt($_POST['current_index'] ?? 0);
    $flagsRaw = $_POST['flags'] ?? '[]';
    $flags = json_decode((string)$flagsRaw, true);
    if (!is_array($flags)) {
        $flags = [];
    }
    $cleanFlags = [];
    foreach ($flags as $qid) {
        $iv = (int)$qid;
        if ($iv > 0) {
            $cleanFlags[] = $iv;
        }
    }
    $cleanFlags = array_values(array_unique($cleanFlags));

    $state = [
        'current_index' => max(0, $currentIndex),
        'flags' => $cleanFlags,
        'updated_at' => time(),
    ];
    $json = json_encode($state);
    $nowSql = date('Y-m-d H:i:s');
    $upd = mysqli_prepare($conn, "UPDATE college_exam_attempts SET ui_state_json=?, last_seen_at=? WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($upd, 'ssii', $json, $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
    exit;
}

if ($action === 'load_state') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT ui_state_json, status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !in_array((string)$row['status'], ['in_progress', 'submitted'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not found']);
        exit;
    }
    $state = null;
    $raw = (string)($row['ui_state_json'] ?? '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }
    $answersOut = [];
    $ar = mysqli_prepare(
        $conn,
        'SELECT question_id, selected_answer, save_seq FROM college_exam_answers WHERE attempt_id=? ORDER BY question_id ASC'
    );
    if ($ar) {
        mysqli_stmt_bind_param($ar, 'i', $attemptId);
        mysqli_stmt_execute($ar);
        $ares = mysqli_stmt_get_result($ar);
        while ($ares && ($arow = mysqli_fetch_assoc($ares))) {
            $sel = strtoupper(trim((string)($arow['selected_answer'] ?? '')));
            if (!preg_match('/^[A-D]$/', $sel)) {
                continue;
            }
            $answersOut[] = [
                'question_id' => (int)$arow['question_id'],
                'selected_answer' => $sel,
                'save_seq' => max(0, (int)($arow['save_seq'] ?? 0)),
            ];
        }
        mysqli_stmt_close($ar);
    }
    echo json_encode(['ok' => true, 'state' => $state, 'answers' => $answersOut]);
    exit;
}

if ($action === 'get_time') {
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT expires_at, status FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || $row['status'] !== 'in_progress') {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    $nowSql = date('Y-m-d H:i:s');
    $touch = mysqli_prepare($conn, 'UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=? AND status=\'in_progress\'');
    if ($touch) {
        mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
        mysqli_stmt_execute($touch);
        mysqli_stmt_close($touch);
    }
    $answeredCount = 0;
    $cr = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS c FROM college_exam_answers WHERE attempt_id=' . (int)$attemptId
        . " AND selected_answer IS NOT NULL AND TRIM(selected_answer) <> ''"
    );
    if ($cr) {
        $answeredCount = (int)(mysqli_fetch_assoc($cr)['c'] ?? 0);
        mysqli_free_result($cr);
    }
    $expRaw2 = $row['expires_at'] ?? '';
    if ($expRaw2 === '') {
        echo json_encode(['ok' => true, 'remaining_seconds' => null, 'answered_count' => $answeredCount]);
        exit;
    }
    $expTs2 = strtotime((string)$expRaw2);
    $remaining = ($expTs2 !== false) ? max(0, $expTs2 - time()) : 0;
    echo json_encode(['ok' => true, 'remaining_seconds' => $remaining, 'answered_count' => $answeredCount]);
    exit;
}

if ($action === 'submit') {
    // Finish flush+finalize even if the browser tab closes during timeout rush.
    ignore_user_abort(true);
    @set_time_limit(120);

    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $reason = strtolower(trim((string)($_POST['reason'] ?? 'manual')));
    $allowIncomplete = in_array($reason, ['timeout', 'timeout-sync', 'expired'], true);

    $answersRaw = $_POST['answers'] ?? '';
    $decodedAnswers = [];
    if (is_string($answersRaw) && $answersRaw !== '') {
        $tmp = json_decode($answersRaw, true);
        if (is_array($tmp)) {
            $decodedAnswers = $tmp;
        }
    }
    $payloadCount = count($decodedAnswers);

    if (!mysqli_begin_transaction($conn)) {
        error_log(sprintf(
            '[college_exam_submit] begin_transaction failed attempt_id=%d user_id=%d payload_count=%d db=%s',
            $attemptId,
            $userId,
            $payloadCount,
            mysqli_error($conn)
        ));
        echo json_encode(['ok' => false, 'error' => 'Could not start submit transaction', 'retry' => true]);
        exit;
    }

    $locked = null;
    $lockStmt = mysqli_prepare(
        $conn,
        'SELECT attempt_id, exam_id, status, expires_at, submitted_at, score, correct_count, total_count
         FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1 FOR UPDATE'
    );
    if ($lockStmt) {
        mysqli_stmt_bind_param($lockStmt, 'ii', $attemptId, $userId);
        mysqli_stmt_execute($lockStmt);
        $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt));
        mysqli_stmt_close($lockStmt);
    }
    if (!$locked) {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }
    if (!college_exam_ajax_verify_attempt_access($conn, $locked, $userId)) {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    $lockedStatus = college_exam_attempt_status_normalized($locked);
    if ($lockedStatus === 'submitted') {
        $fill = ['filled' => 0, 'skipped' => 0, 'rescored' => false, 'ok' => true];
        if ($payloadCount > 0 && college_exam_is_timeout_submit_reason($reason)) {
            $fill = college_exam_fill_empty_timeout_answers($conn, $attemptId, $userId, $decodedAnswers, $reason);
        }
        $filledN = (int)($fill['filled'] ?? 0);
        $answersIgnored = $payloadCount > 0 && $filledN <= 0;
        if (!mysqli_commit($conn)) {
            mysqli_rollback($conn);
            echo json_encode(['ok' => false, 'error' => 'Submit commit failed', 'retry' => true]);
            exit;
        }
        if ($answersIgnored) {
            error_log(sprintf(
                '[college_exam_submit] already_submitted answers_ignored attempt_id=%d user_id=%d reason=%s payload_count=%d',
                $attemptId,
                $userId,
                $reason,
                $payloadCount
            ));
        }
        $scoreOut = (float)($locked['score'] ?? 0);
        $correctOut = (int)($locked['correct_count'] ?? 0);
        $totalOut = (int)($locked['total_count'] ?? 0);
        if ($filledN > 0) {
            $re = mysqli_prepare(
                $conn,
                'SELECT score, correct_count, total_count FROM college_exam_attempts WHERE attempt_id=? AND user_id=? LIMIT 1'
            );
            if ($re) {
                mysqli_stmt_bind_param($re, 'ii', $attemptId, $userId);
                mysqli_stmt_execute($re);
                $again = mysqli_fetch_assoc(mysqli_stmt_get_result($re));
                mysqli_stmt_close($re);
                if ($again) {
                    $scoreOut = (float)($again['score'] ?? $scoreOut);
                    $correctOut = (int)($again['correct_count'] ?? $correctOut);
                    $totalOut = (int)($again['total_count'] ?? $totalOut);
                }
            }
        }
        echo json_encode([
            'ok' => true,
            'already_submitted' => true,
            'answers_ignored' => $answersIgnored,
            'fill_empty_recovered' => $filledN > 0,
            'fill_empty_filled' => $filledN,
            'persisted' => $filledN > 0,
            'answers_persisted' => $filledN,
            'payload_count' => $payloadCount,
            'score' => $scoreOut,
            'correct' => $correctOut,
            'total' => $totalOut,
        ]);
        exit;
    }
    if ($lockedStatus !== 'in_progress') {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }

    $attempt = $locked;

    // Heartbeat: mark active submit so expire-finalizer activity hold treats this client as present.
    // Only while still in_progress (before finalize). Never touch last_seen_at after submitted.
    $nowSql = date('Y-m-d H:i:s');
    $touchSeen = mysqli_prepare(
        $conn,
        "UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=? AND status='in_progress'"
    );
    if ($touchSeen) {
        mysqli_stmt_bind_param($touchSeen, 'sii', $nowSql, $attemptId, $userId);
        mysqli_stmt_execute($touchSeen);
        mysqli_stmt_close($touchSeen);
    }

    // Atomic flush: persist complete client payload BEFORE finalize.
    if ($payloadCount > 0) {
        $flush = college_exam_upsert_attempt_answers_payload($conn, $attemptId, $userId, $decodedAnswers);
        if (empty($flush['ok'])) {
            mysqli_rollback($conn);
            error_log(sprintf(
                '[college_exam_submit] answer flush failed attempt_id=%d user_id=%d reason=%s payload_count=%d saved=%d errors=%d skipped=%d error=%s db_error=%s',
                $attemptId,
                $userId,
                $reason,
                (int)($flush['payload_count'] ?? $payloadCount),
                (int)($flush['saved'] ?? 0),
                (int)($flush['errors'] ?? 0),
                (int)($flush['skipped'] ?? 0),
                (string)($flush['error'] ?? ''),
                (string)($flush['db_error'] ?? '')
            ));
            echo json_encode([
                'ok' => false,
                'error' => 'Could not save answers before submit',
                'retry' => true,
                'payload_count' => (int)($flush['payload_count'] ?? $payloadCount),
                'saved' => (int)($flush['saved'] ?? 0),
            ]);
            exit;
        }
    }

    if (!$allowIncomplete) {
        $examIdChk = (int)($attempt['exam_id'] ?? 0);
        $examChk = null;
        $exSt = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=? LIMIT 1');
        if ($exSt) {
            mysqli_stmt_bind_param($exSt, 'i', $examIdChk);
            mysqli_stmt_execute($exSt);
            $examChk = mysqli_fetch_assoc(mysqli_stmt_get_result($exSt));
            mysqli_stmt_close($exSt);
        }
        $questionsChk = [];
        if ($examChk) {
            $questionsChk = college_exam_questions_for_student_attempt($conn, $examChk, $attempt);
        }
        $qTotal = count($questionsChk);
        $answeredIds = [];
        $aidQ = mysqli_query(
            $conn,
            'SELECT question_id FROM college_exam_answers WHERE attempt_id=' . (int)$attemptId
            . " AND selected_answer IS NOT NULL AND TRIM(selected_answer) <> ''"
        );
        if ($aidQ) {
            while ($row = mysqli_fetch_assoc($aidQ)) {
                $answeredIds[(int)$row['question_id']] = true;
            }
            mysqli_free_result($aidQ);
        }
        $answered = count($answeredIds);
        if ($qTotal > 0 && $answered < $qTotal) {
            mysqli_commit($conn); // keep flushed answers; do not finalize
            $missing = [];
            foreach ($questionsChk as $i => $qRow) {
                $qid = (int)($qRow['question_id'] ?? 0);
                if ($qid > 0 && empty($answeredIds[$qid])) {
                    $missing[] = $i + 1;
                }
            }
            echo json_encode([
                'ok' => false,
                'error' => 'Please answer all questions before submitting the exam.',
                'unanswered' => $missing,
                'answered_count' => $answered,
                'total_questions' => $qTotal,
            ]);
            exit;
        }
    }

    try {
        $result = college_exam_finalize_attempt($conn, $attemptId, $userId);
        if (empty($result['ok'])) {
            mysqli_rollback($conn);
            examination_ajax_log('college_exam', [
                'action' => 'submit',
                'user_id' => $userId,
                'attempt_id' => $attemptId,
                'ok' => 0,
                'error' => $result['error'] ?? 'Submit failed',
                'txn' => 1,
                'finalized' => 0,
                'duration_ms' => (int)round((microtime(true) - $__examAjaxStarted) * 1000),
            ]);
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Submit failed', 'retry' => true]);
            exit;
        }

        if (!mysqli_commit($conn)) {
            mysqli_rollback($conn);
            examination_ajax_log('college_exam', [
                'action' => 'submit',
                'user_id' => $userId,
                'attempt_id' => $attemptId,
                'ok' => 0,
                'error' => 'Submit commit failed',
                'errno' => (int)mysqli_errno($conn),
                'txn' => 1,
                'duration_ms' => (int)round((microtime(true) - $__examAjaxStarted) * 1000),
            ]);
            echo json_encode(['ok' => false, 'error' => 'Submit commit failed', 'retry' => true]);
            exit;
        }
    } catch (Throwable $e) {
        @mysqli_rollback($conn);
        examination_ajax_log('college_exam', [
            'action' => 'submit',
            'user_id' => $userId,
            'attempt_id' => $attemptId,
            'ok' => 0,
            'exception' => get_class($e) . ': ' . $e->getMessage(),
            'txn' => 1,
            'duration_ms' => (int)round((microtime(true) - $__examAjaxStarted) * 1000),
        ]);
        echo json_encode(['ok' => false, 'error' => 'Submit failed', 'retry' => true]);
        exit;
    }

    $persistedCount = 0;
    $pcq = @mysqli_query(
        $conn,
        'SELECT COUNT(*) AS c FROM college_exam_answers WHERE attempt_id=' . (int)$attemptId
        . " AND selected_answer IS NOT NULL AND TRIM(selected_answer) <> ''"
    );
    if ($pcq) {
        $persistedCount = (int)(mysqli_fetch_assoc($pcq)['c'] ?? 0);
        mysqli_free_result($pcq);
    }

    examination_ajax_log('college_exam', [
        'action' => 'submit',
        'user_id' => $userId,
        'attempt_id' => $attemptId,
        'ok' => 1,
        'finalized' => 1,
        'reason' => $reason,
        'payload_count' => $payloadCount,
        'answers_persisted' => $persistedCount,
        'correct' => (int)($result['correct'] ?? 0),
        'total' => (int)($result['total'] ?? 0),
        'duration_ms' => (int)round((microtime(true) - $__examAjaxStarted) * 1000),
    ]);

    echo json_encode([
        'ok' => true,
        'already_submitted' => !empty($result['already_submitted']),
        'answers_ignored' => false,
        'payload_count' => $payloadCount,
        'answers_persisted' => $persistedCount,
        'score' => $result['score'],
        'correct' => $result['correct'],
        'total' => $result['total'],
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
