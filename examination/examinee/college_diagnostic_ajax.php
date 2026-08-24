<?php
/**
 * Diagnostic exam take AJAX — persistence/submit patterns adapted from college_exam_ajax.php
 * without modifying college take or college helpers.
 *
 * Preserves diagnostic authorization, batch_id, and A–Z answers (E+ choices).
 */
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_eligibility.php';

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = (int)getCurrentUserId();
$conn = $GLOBALS['conn'];
$action = (string)($_POST['action'] ?? '');

// Do NOT auto-finalize on write/submit paths — races timeout flush (same as college_exam_ajax).
if ($action === 'load_state' || $action === '') {
    diagnostic_exam_finalize_expired_in_progress($conn, 0, $userId);
}
if (function_exists('ereview_release_session_lock')) {
    ereview_release_session_lock();
}

/**
 * Re-check diagnostic batch access for an attempt (preserve diagnostic eligibility rules).
 */
function diagnostic_ajax_verify_attempt_access(mysqli $conn, array $attempt, int $userId): bool
{
    $batchId = (int)($attempt['batch_id'] ?? 0);
    if ($batchId <= 0) {
        return false;
    }
    $batch = diagnostic_exam_load_batch($conn, $batchId);
    if (!$batch || empty($batch['is_published'])) {
        return false;
    }

    return examination_user_can_view_exam($conn, $userId, $batch, 'diagnostic', $attempt);
}

/**
 * @param bool $requireNotExpired When false, allow in_progress after timer end (timeout flush/submit).
 */
function diagnostic_ajax_load_active_attempt(mysqli $conn, int $attemptId, int $userId, bool $requireNotExpired = true): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT attempt_id, batch_id, status, expires_at FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attempt || ($attempt['status'] ?? '') !== 'in_progress') {
        return null;
    }
    if (!diagnostic_ajax_verify_attempt_access($conn, $attempt, $userId)) {
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

function diagnostic_ajax_write_answer_row(mysqli $conn, int $attemptId, int $questionId, string $selected, int $isCorrect): bool
{
    $sql = 'INSERT INTO diagnostic_answers (attempt_id, question_id, selected_answer, is_correct)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              selected_answer = VALUES(selected_answer),
              is_correct = VALUES(is_correct)';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'iisi', $attemptId, $questionId, $selected, $isCorrect);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return (bool)$ok;
}

/**
 * @return array{ok:bool,error?:string,is_correct?:int}
 */
function diagnostic_ajax_upsert_answer(mysqli $conn, int $attemptId, int $userId, int $questionId, string $selected, bool $requireNotExpired = true): array
{
    $selected = strtoupper(trim($selected));
    if (!preg_match('/^[A-Z]$/', $selected)) {
        return ['ok' => false, 'error' => 'Invalid answer'];
    }
    $attempt = diagnostic_ajax_load_active_attempt($conn, $attemptId, $userId, $requireNotExpired);
    if (!$attempt) {
        return ['ok' => false, 'error' => 'Attempt not active'];
    }
    $batchId = (int)($attempt['batch_id'] ?? 0);
    $qStmt = mysqli_prepare($conn, 'SELECT question_id, correct_answer FROM diagnostic_questions WHERE question_id=? AND batch_id=? LIMIT 1');
    if (!$qStmt) {
        return ['ok' => false, 'error' => 'Could not save'];
    }
    mysqli_stmt_bind_param($qStmt, 'ii', $questionId, $batchId);
    mysqli_stmt_execute($qStmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($qStmt));
    mysqli_stmt_close($qStmt);
    if (!$qRow) {
        return ['ok' => false, 'error' => 'Invalid question'];
    }
    $correctLetter = strtoupper(trim((string)($qRow['correct_answer'] ?? 'A')));
    $isCorrect = ($selected === $correctLetter) ? 1 : 0;
    if (!diagnostic_ajax_write_answer_row($conn, $attemptId, $questionId, $selected, $isCorrect)) {
        return ['ok' => false, 'error' => 'Could not save'];
    }

    return ['ok' => true, 'is_correct' => $isCorrect];
}

/**
 * @param array<int,mixed> $rawAnswers
 * @return array{ok:bool,saved:int,errors:int,payload_count:int,skipped:int,error?:string}
 */
function diagnostic_ajax_upsert_answers_payload(mysqli $conn, int $attemptId, int $userId, array $rawAnswers, bool $requireNotExpired = false): array
{
    $payloadCount = count($rawAnswers);
    $saved = 0;
    $errors = 0;
    $skipped = 0;
    if ($attemptId <= 0 || $userId <= 0) {
        return ['ok' => false, 'saved' => 0, 'errors' => $payloadCount, 'payload_count' => $payloadCount, 'skipped' => 0, 'error' => 'Invalid attempt'];
    }
    if ($rawAnswers === []) {
        return ['ok' => true, 'saved' => 0, 'errors' => 0, 'payload_count' => 0, 'skipped' => 0];
    }
    $attempt = diagnostic_ajax_load_active_attempt($conn, $attemptId, $userId, $requireNotExpired);
    if (!$attempt) {
        return ['ok' => false, 'saved' => 0, 'errors' => $payloadCount, 'payload_count' => $payloadCount, 'skipped' => 0, 'error' => 'Attempt not active'];
    }
    $batchId = (int)($attempt['batch_id'] ?? 0);
    $validQ = [];
    $correctByQ = [];
    $qr = mysqli_query($conn, 'SELECT question_id, correct_answer FROM diagnostic_questions WHERE batch_id=' . (int)$batchId);
    if ($qr) {
        while ($r = mysqli_fetch_assoc($qr)) {
            $qid = (int)($r['question_id'] ?? 0);
            if ($qid > 0) {
                $validQ[$qid] = true;
                $correctByQ[$qid] = strtoupper(trim((string)($r['correct_answer'] ?? 'A')));
            }
        }
        mysqli_free_result($qr);
    }
    foreach ($rawAnswers as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }
        $qid = (int)($row['question_id'] ?? 0);
        $sel = strtoupper(trim((string)($row['selected_answer'] ?? '')));
        if ($qid <= 0 || !preg_match('/^[A-Z]$/', $sel) || empty($validQ[$qid])) {
            $skipped++;
            continue;
        }
        $isCorrect = ($sel === ($correctByQ[$qid] ?? '')) ? 1 : 0;
        if (diagnostic_ajax_write_answer_row($conn, $attemptId, $qid, $sel, $isCorrect)) {
            $saved++;
        } else {
            $errors++;
        }
    }
    if ($errors > 0 && $saved === 0) {
        return ['ok' => false, 'saved' => $saved, 'errors' => $errors, 'payload_count' => $payloadCount, 'skipped' => $skipped, 'error' => 'Could not save answers'];
    }

    return ['ok' => true, 'saved' => $saved, 'errors' => $errors, 'payload_count' => $payloadCount, 'skipped' => $skipped];
}

if ($action === 'tab_blur' || $action === 'tab_visibility') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $visibility = strtolower(trim((string)($_POST['visibility'] ?? 'hidden')));
    // Only count leaving the exam (hidden), matching college take semantics.
    if ($action === 'tab_visibility' && $visibility === 'visible') {
        echo json_encode(['ok' => true, 'ignored' => true]);
        exit;
    }
    $attempt = diagnostic_ajax_load_active_attempt($conn, $attemptId, $userId, true);
    if (!$attempt) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }
    $nowSql = date('Y-m-d H:i:s');
    $upd = mysqli_prepare($conn, 'UPDATE diagnostic_attempts SET tab_switch_count = COALESCE(tab_switch_count, 0) + 1, last_tab_switch_at=? WHERE attempt_id=? AND user_id=?');
    mysqli_stmt_bind_param($upd, 'sii', $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    $count = 0;
    $cr = mysqli_query($conn, 'SELECT tab_switch_count FROM diagnostic_attempts WHERE attempt_id=' . (int)$attemptId . ' LIMIT 1');
    if ($cr) {
        $crow = mysqli_fetch_assoc($cr);
        $count = (int)($crow['tab_switch_count'] ?? 0);
        mysqli_free_result($cr);
    }
    echo json_encode(['ok' => true, 'tab_switch_count' => $count, 'last_tab_switch_at' => $nowSql]);
    exit;
}

if ($action === 'save_answer') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $selected = strtoupper(trim((string)($_POST['selected_answer'] ?? '')));
    $res = diagnostic_ajax_upsert_answer($conn, $attemptId, $userId, $questionId, $selected, true);
    if (empty($res['ok'])) {
        echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Could not save')]);
        exit;
    }
    $answeredCount = 0;
    $cr = mysqli_query($conn, "SELECT COUNT(*) AS c FROM diagnostic_answers WHERE attempt_id=" . (int)$attemptId . " AND selected_answer IS NOT NULL AND selected_answer <> ''");
    if ($cr) {
        $answeredCount = (int)(mysqli_fetch_assoc($cr)['c'] ?? 0);
        mysqli_free_result($cr);
    }
    $nowSql = date('Y-m-d H:i:s');
    $touch = mysqli_prepare($conn, 'UPDATE diagnostic_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=?');
    if ($touch) {
        mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId, $userId);
        mysqli_stmt_execute($touch);
        mysqli_stmt_close($touch);
    }
    echo json_encode(['ok' => true, 'saved_at' => date('H:i:s'), 'answered_count' => $answeredCount]);
    exit;
}

if ($action === 'sync_state') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $attempt = diagnostic_ajax_load_active_attempt($conn, $attemptId, $userId, true);
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
    $upd = mysqli_prepare($conn, 'UPDATE diagnostic_attempts SET ui_state_json=?, last_seen_at=? WHERE attempt_id=? AND user_id=?');
    mysqli_stmt_bind_param($upd, 'ssii', $json, $nowSql, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
    exit;
}

if ($action === 'load_state') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, 'SELECT ui_state_json, status, batch_id FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !in_array((string)$row['status'], ['in_progress', 'submitted'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Attempt not found']);
        exit;
    }
    if (!diagnostic_ajax_verify_attempt_access($conn, $row, $userId)) {
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
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
    $stmt = mysqli_prepare($conn, 'SELECT expires_at, status, batch_id FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || ($row['status'] ?? '') !== 'in_progress') {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    if (!diagnostic_ajax_verify_attempt_access($conn, $row, $userId)) {
        echo json_encode(['ok' => false, 'remaining_seconds' => 0]);
        exit;
    }
    $expRaw = $row['expires_at'] ?? '';
    if ($expRaw === '') {
        echo json_encode(['ok' => true, 'remaining_seconds' => null]);
        exit;
    }
    $expTs = strtotime((string)$expRaw);
    $remaining = ($expTs !== false) ? max(0, $expTs - time()) : 0;
    echo json_encode(['ok' => true, 'remaining_seconds' => $remaining]);
    exit;
}

if ($action === 'submit') {
    ignore_user_abort(true);
    @set_time_limit(120);

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
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
        echo json_encode(['ok' => false, 'error' => 'Could not start submit transaction', 'retry' => true]);
        exit;
    }

    $locked = null;
    $lockStmt = mysqli_prepare(
        $conn,
        'SELECT attempt_id, batch_id, status, expires_at, score, correct_count, total_count, subject_breakdown_json
         FROM diagnostic_attempts WHERE attempt_id=? AND user_id=? LIMIT 1 FOR UPDATE'
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
    if (!diagnostic_ajax_verify_attempt_access($conn, $locked, $userId)) {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    $lockedStatus = strtolower(trim((string)($locked['status'] ?? '')));
    if ($lockedStatus === 'submitted') {
        $breakdown = [];
        if (!empty($locked['subject_breakdown_json'])) {
            $decoded = json_decode((string)$locked['subject_breakdown_json'], true);
            if (is_array($decoded)) {
                $breakdown = $decoded;
            }
        }
        mysqli_commit($conn);
        echo json_encode([
            'ok' => true,
            'already_submitted' => true,
            'score' => (float)($locked['score'] ?? 0),
            'correct' => (int)($locked['correct_count'] ?? 0),
            'total' => (int)($locked['total_count'] ?? 0),
            'breakdown' => $breakdown,
        ]);
        exit;
    }
    if ($lockedStatus !== 'in_progress') {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'error' => 'Attempt not active']);
        exit;
    }

    // Flush client answers BEFORE finalize (critical for timeout).
    if ($payloadCount > 0) {
        $flush = diagnostic_ajax_upsert_answers_payload($conn, $attemptId, $userId, $decodedAnswers, false);
        if (empty($flush['ok'])) {
            mysqli_rollback($conn);
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
        $batchIdChk = (int)($locked['batch_id'] ?? 0);
        $batchSubjects = diagnostic_exam_load_batch_subjects($conn, $batchIdChk);
        $questionsChk = diagnostic_exam_build_flat_questions($conn, $batchIdChk, $batchSubjects, $attemptId);
        $qTotal = count($questionsChk);
        $answered = 0;
        $ar = mysqli_query($conn, "SELECT COUNT(*) AS c FROM diagnostic_answers WHERE attempt_id=" . (int)$attemptId . " AND selected_answer IS NOT NULL AND selected_answer <> ''");
        if ($ar) {
            $answered = (int)(mysqli_fetch_assoc($ar)['c'] ?? 0);
            mysqli_free_result($ar);
        }
        if ($qTotal > 0 && $answered < $qTotal) {
            mysqli_rollback($conn);
            echo json_encode([
                'ok' => false,
                'error' => 'Please answer all questions before submitting.',
                'answered' => $answered,
                'total' => $qTotal,
            ]);
            exit;
        }
    }

    $result = diagnostic_exam_finalize_attempt($conn, $attemptId, $userId);
    if (empty($result['ok'])) {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Submit failed', 'retry' => true]);
        exit;
    }
    mysqli_commit($conn);
    echo json_encode([
        'ok' => true,
        'score' => $result['score'],
        'correct' => $result['correct'],
        'total' => $result['total'],
        'breakdown' => $result['breakdown'] ?? [],
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
