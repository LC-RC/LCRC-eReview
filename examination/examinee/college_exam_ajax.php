<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = (int)getCurrentUserId();
$conn = $GLOBALS['conn'];
$action = (string)($_POST['action'] ?? '');
// Do NOT auto-finalize on write/heartbeat/submit paths — that raced timeout submit and
// dropped in-flight answers. List/take/monitor pages still finalize expired attempts.
if ($action === 'load_state' || $action === '') {
    college_exam_finalize_expired_in_progress($conn, 0, $userId, 0);
}
// Release PHP session lock after auth/session values are read so concurrent examinee
// AJAX (autosave, heartbeat, submit) does not serialize behind this request.
if (function_exists('ereview_release_session_lock')) {
    ereview_release_session_lock();
}

function college_exam_ajax_verify_attempt_access(mysqli $conn, array $attempt, int $userId): bool
{
    $examId = (int)($attempt['exam_id'] ?? 0);
    if ($examId <= 0) {
        return false;
    }
    $pubWhere = college_exam_where_published_sql();
    $stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND {$pubWhere} LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $examId);
    mysqli_stmt_execute($stmt);
    $exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$exam) {
        return false;
    }

    return college_exam_user_can_view($conn, $userId, $exam, $attempt);
}

/**
 * @return array<string,mixed>|null
 */
/**
 * @param bool $requireNotExpired When false, allow in_progress after timer end (save flush / timeout submit).
 */
function college_exam_ajax_load_active_attempt(mysqli $conn, int $attemptId, int $userId, bool $requireNotExpired = true): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT a.attempt_id, a.exam_id, a.status, a.expires_at FROM college_exam_attempts a WHERE a.attempt_id=? AND a.user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || $attempt['status'] !== 'in_progress') {
        return null;
    }
    if (!college_exam_ajax_verify_attempt_access($conn, $attempt, $userId)) {
        return null;
    }
    if ($requireNotExpired) {
        $expRaw = $attempt['expires_at'] ?? '';
        if ($expRaw !== '') {
            $expTs = strtotime((string)$expRaw);
            if ($expTs !== false && $expTs < time()) {
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
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $selected = strtoupper(trim((string)($_POST['selected_answer'] ?? '')));
    // Stop accepting new interactive answers at official expires_at.
    // Timeout flush goes through action=submit (bulk payload), not save_answer.
    $attempt = college_exam_ajax_load_active_attempt($conn, $attemptId, $userId, true);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active', 'expired' => true]);
        exit;
    }

    $saved = college_exam_upsert_attempt_answer($conn, $attemptId, $userId, $questionId, $selected);
    if (empty($saved['ok'])) {
        echo json_encode(['ok' => false, 'error' => $saved['error'] ?? 'Could not save']);
        exit;
    }

    $answeredCount = 0;
    $cr = mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_answers WHERE attempt_id=" . (int)$attemptId . " AND selected_answer IS NOT NULL AND selected_answer <> ''");
    if ($cr) {
        $answeredCount = (int)(mysqli_fetch_assoc($cr)['c'] ?? 0);
        mysqli_free_result($cr);
    }
    $nowSql = date('Y-m-d H:i:s');
    $touch = mysqli_prepare($conn, "UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($touch);
    mysqli_stmt_close($touch);

    echo json_encode([
        'ok' => true,
        'saved_at' => date('H:i:s'),
        'answered_count' => $answeredCount,
    ]);
    exit;
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
    echo json_encode(['ok' => true, 'state' => $state]);
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
        'SELECT attempt_id, exam_id, status, expires_at, score, correct_count, total_count
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
        mysqli_commit($conn);
        echo json_encode([
            'ok' => true,
            'already_submitted' => true,
            'score' => (float)($locked['score'] ?? 0),
            'correct' => (int)($locked['correct_count'] ?? 0),
            'total' => (int)($locked['total_count'] ?? 0),
        ]);
        exit;
    }
    if ($lockedStatus !== 'in_progress') {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }

    $attempt = $locked;

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
        $qq = mysqli_query($conn, 'SELECT * FROM college_exam_questions WHERE exam_id=' . $examIdChk . ' ORDER BY sort_order ASC, question_id ASC');
        if ($qq) {
            while ($row = mysqli_fetch_assoc($qq)) {
                $questionsChk[] = $row;
            }
            mysqli_free_result($qq);
        }
        if ($examChk) {
            $questionsChk = college_exam_prepare_questions_for_attempt($questionsChk, $examChk, (int)$attemptId);
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

    $result = college_exam_finalize_attempt($conn, $attemptId, $userId);
    if (empty($result['ok'])) {
        mysqli_rollback($conn);
        error_log(sprintf(
            '[college_exam_submit] finalize failed attempt_id=%d user_id=%d reason=%s payload_count=%d error=%s',
            $attemptId,
            $userId,
            $reason,
            $payloadCount,
            (string)($result['error'] ?? '')
        ));
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Submit failed', 'retry' => true]);
        exit;
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        error_log(sprintf(
            '[college_exam_submit] commit failed attempt_id=%d user_id=%d payload_count=%d db=%s',
            $attemptId,
            $userId,
            $payloadCount,
            mysqli_error($conn)
        ));
        echo json_encode(['ok' => false, 'error' => 'Submit commit failed', 'retry' => true]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'already_submitted' => !empty($result['already_submitted']),
        'score' => $result['score'],
        'correct' => $result['correct'],
        'total' => $result['total'],
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
