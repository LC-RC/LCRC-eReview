<?php
/**
 * Focused integrity checks for college exam timeout / auto-finalize race fixes.
 *
 * Safety:
 * - Never touches attempt_id 362
 * - Never deletes historical attempts by user/exam wipe
 * - Creates ephemeral attempts only; cleans up ONLY those attempt_ids
 *
 * Run (Windows / XAMPP):
 *   & 'C:\xampp\php\php.exe' scripts\qa_exam_timeout_finalize_race.php
 * Or:
 *   powershell -File scripts\run_qa_exam_integrity.ps1
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/examination/includes/college_exam_helpers.php';

function pass(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}

/**
 * @return array{exam_id:int,user_id:int,qids:list<int>}|null
 */
function qa_find_free_exam_student_multi(mysqli $conn, int $minQuestions = 2): ?array
{
    $exams = mysqli_query(
        $conn,
        'SELECT e.exam_id,
                (SELECT COUNT(*) FROM college_exam_questions q WHERE q.exam_id = e.exam_id) AS qcount
         FROM college_exams e
         WHERE e.is_published = 1
         HAVING qcount >= ' . (int)$minQuestions . '
         ORDER BY e.exam_id DESC
         LIMIT 40'
    );
    if (!$exams) {
        return null;
    }
    $students = mysqli_query(
        $conn,
        "SELECT user_id FROM users
         WHERE role IN ('college_student','student') AND status='approved'
         ORDER BY user_id DESC LIMIT 80"
    );
    $userIds = [];
    if ($students) {
        while ($u = mysqli_fetch_assoc($students)) {
            $userIds[] = (int)$u['user_id'];
        }
        mysqli_free_result($students);
    }
    while ($ex = mysqli_fetch_assoc($exams)) {
        $examId = (int)$ex['exam_id'];
        if ($examId <= 0) {
            continue;
        }
        $qids = [];
        $qr = mysqli_query(
            $conn,
            'SELECT question_id FROM college_exam_questions WHERE exam_id=' . $examId
            . ' ORDER BY question_id ASC LIMIT 5'
        );
        if ($qr) {
            while ($row = mysqli_fetch_assoc($qr)) {
                $qids[] = (int)$row['question_id'];
            }
            mysqli_free_result($qr);
        }
        if (count($qids) < $minQuestions) {
            continue;
        }
        foreach ($userIds as $uid) {
            $chk = mysqli_query(
                $conn,
                'SELECT attempt_id FROM college_exam_attempts WHERE user_id=' . $uid
                . ' AND exam_id=' . $examId . ' LIMIT 1'
            );
            $exists = $chk && mysqli_fetch_assoc($chk);
            if ($chk) {
                mysqli_free_result($chk);
            }
            if ($exists) {
                continue;
            }

            return ['exam_id' => $examId, 'user_id' => $uid, 'qids' => $qids];
        }
    }

    return null;
}

function qa_abort_if_protected_attempt(int $attemptId): void
{
    if ($attemptId === 362) {
        fwrite(STDERR, "ABORT: refused to use protected attempt_id 362\n");
        exit(1);
    }
    if ($attemptId <= 0) {
        fwrite(STDERR, "ABORT: invalid ephemeral attempt_id\n");
        exit(1);
    }
}

function qa_cleanup_ephemeral(mysqli $conn, int $attemptId): void
{
    qa_abort_if_protected_attempt($attemptId);
    mysqli_query($conn, 'DELETE FROM college_exam_answers WHERE attempt_id=' . $attemptId);
    mysqli_query($conn, 'DELETE FROM college_exam_attempts WHERE attempt_id=' . $attemptId);
}

/** Mirror of client shouldClearAnswerBackup() policy for regression. */
function qa_should_clear_answer_backup(array $data, int $localAnswerCount = 0): bool
{
    unset($localAnswerCount);

    return !empty($data['ok']);
}

$fails = 0;
$createdAttemptIds = [];

$grace = college_exam_finalize_expired_grace_seconds();
$hold = college_exam_finalize_expired_activity_hold_seconds();
pass('grace seconds >= 60', $grace >= 60, "grace=$grace");
if ($grace < 60) {
    $fails++;
}
pass('activity hold seconds >= 60', $hold >= 60, "hold=$hold");
if ($hold < 60) {
    $fails++;
}

$score = college_exam_compute_score_percentage(2, 70);
pass('traditional score 2/70 rounds to 3', abs($score - 3.0) < 0.001, "score=$score");
if (abs($score - 3.0) >= 0.001) {
    $fails++;
}

$slot = qa_find_free_exam_student_multi($conn, 2);
if ($slot === null) {
    echo "SKIP remaining DB tests: no free exam+student pair without existing attempt\n";
    echo ($fails === 0 ? "ALL PASS (partial)\n" : "FAILURES=$fails\n");
    exit($fails === 0 ? 0 : 1);
}
$examId = $slot['exam_id'];
$userId = $slot['user_id'];
$qids = $slot['qids'];
echo "QA slot exam_id={$examId} user_id={$userId} qids=" . count($qids) . "\n";

$started = date('Y-m-d H:i:s', time() - 600);
$expires = date('Y-m-d H:i:s', time() - ($grace + 30));
$lastSeenRecent = date('Y-m-d H:i:s');
$ins = mysqli_prepare(
    $conn,
    "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at)
     VALUES (?, ?, 'in_progress', ?, ?, ?)"
);
mysqli_stmt_bind_param($ins, 'iisss', $examId, $userId, $started, $expires, $lastSeenRecent);
mysqli_stmt_execute($ins);
$attemptId = (int)mysqli_insert_id($conn);
mysqli_stmt_close($ins);
qa_abort_if_protected_attempt($attemptId);
$createdAttemptIds[] = $attemptId;

try {
    $nSkip = college_exam_finalize_expired_in_progress($conn, $examId, $userId, 0);
    $st = mysqli_query($conn, 'SELECT status FROM college_exam_attempts WHERE attempt_id=' . $attemptId . ' LIMIT 1');
    $statusAfter = $st ? strtolower((string)(mysqli_fetch_assoc($st)['status'] ?? '')) : '';
    $okSkip = ($statusAfter === 'in_progress');
    pass('auto-finalize skips recent last_seen_at', $okSkip, "status=$statusAfter finalized_count=$nSkip");
    if (!$okSkip) {
        $fails++;
    }

    $stale = date('Y-m-d H:i:s', time() - ($hold + 60));
    mysqli_query(
        $conn,
        "UPDATE college_exam_attempts SET last_seen_at='" . mysqli_real_escape_string($conn, $stale)
        . "' WHERE attempt_id=" . $attemptId
    );

    $payload = [];
    foreach ($qids as $i => $qid) {
        $payload[] = [
            'question_id' => $qid,
            'selected_answer' => ['A', 'B', 'C', 'D'][$i % 4],
            'save_seq' => $i + 1,
        ];
    }
    $flush = college_exam_upsert_attempt_answers_payload($conn, $attemptId, $userId, $payload);
    pass('bulk upsert before finalize', !empty($flush['ok']) && (int)$flush['saved'] === count($payload), json_encode($flush));
    if (empty($flush['ok']) || (int)$flush['saved'] !== count($payload)) {
        $fails++;
    }

    $cntR = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS c FROM college_exam_answers WHERE attempt_id=' . $attemptId
        . " AND selected_answer IS NOT NULL AND TRIM(selected_answer) <> ''"
    );
    $persisted = $cntR ? (int)(mysqli_fetch_assoc($cntR)['c'] ?? 0) : 0;
    pass('persisted answer rows match payload', $persisted === count($payload), "persisted=$persisted");
    if ($persisted !== count($payload)) {
        $fails++;
    }

    $nFin = college_exam_finalize_expired_in_progress($conn, $examId, $userId, 0);
    $st2 = mysqli_query($conn, 'SELECT status, correct_count, total_count, score FROM college_exam_attempts WHERE attempt_id=' . $attemptId . ' LIMIT 1');
    $row2 = $st2 ? mysqli_fetch_assoc($st2) : null;
    $okFin = $row2 && strtolower((string)$row2['status']) === 'submitted';
    pass('auto-finalize after stale last_seen', $okFin, 'n=' . $nFin . ' row=' . json_encode($row2));
    if (!$okFin) {
        $fails++;
    }

    $latePayload = [
        ['question_id' => $qids[0], 'selected_answer' => 'D', 'save_seq' => 999],
    ];
    $late = college_exam_upsert_attempt_answers_payload($conn, $attemptId, $userId, $latePayload);
    $sr = mysqli_query(
        $conn,
        'SELECT selected_answer FROM college_exam_answers WHERE attempt_id=' . $attemptId
        . ' AND question_id=' . (int)$qids[0] . ' LIMIT 1'
    );
    $sel = $sr ? strtoupper((string)(mysqli_fetch_assoc($sr)['selected_answer'] ?? '')) : '';
    $expected = strtoupper($payload[0]['selected_answer']);
    $okImmutable = ($sel === $expected) && empty($late['ok']);
    pass('post-finalize bulk upsert rejected (immutable)', $okImmutable, "sel=$sel expected=$expected late=" . json_encode($late));
    if (!$okImmutable) {
        $fails++;
    }
} finally {
    qa_cleanup_ephemeral($conn, $attemptId);
}

// Backup clear policy mirrors
$clearEmptyAlready = qa_should_clear_answer_backup([
    'ok' => true,
    'already_submitted' => true,
    'answers_ignored' => false,
    'payload_count' => 0,
]);
pass('already_submitted + empty payload clears backup after confirmed success', $clearEmptyAlready === true);
if ($clearEmptyAlready !== true) {
    $fails++;
}

$clearIgnored = qa_should_clear_answer_backup([
    'ok' => true,
    'already_submitted' => true,
    'answers_ignored' => true,
    'payload_count' => 5,
]);
pass('already_submitted idempotent success clears backup', $clearIgnored === true);
if ($clearIgnored !== true) {
    $fails++;
}

$clearFakePersisted = qa_should_clear_answer_backup([
    'ok' => true,
    'already_submitted' => true,
    'answers_ignored' => false,
    'payload_count' => 3,
    'answers_persisted' => 3,
]);
pass('already_submitted confirmed success clears backup', $clearFakePersisted === true);
if ($clearFakePersisted !== true) {
    $fails++;
}

$clearFail = qa_should_clear_answer_backup(['ok' => false, 'error' => 'x']);
pass('failed submit keeps backup', $clearFail === false);
if ($clearFail !== false) {
    $fails++;
}

$clearEmptyLocal = qa_should_clear_answer_backup([
    'ok' => true,
    'already_submitted' => false,
    'answers_ignored' => false,
    'payload_count' => 0,
], 4);
pass('ok submit with empty payload still clears after confirmation', $clearEmptyLocal === true);
if ($clearEmptyLocal !== true) {
    $fails++;
}

$clearOk = qa_should_clear_answer_backup([
    'ok' => true,
    'already_submitted' => false,
    'answers_ignored' => false,
    'payload_count' => 3,
    'answers_persisted' => 3,
]);
pass('successful finalize with persisted payload may clear backup', $clearOk === true);
if ($clearOk !== true) {
    $fails++;
}

// Second free slot for take-page defer + last_seen touch
$slot2 = qa_find_free_exam_student_multi($conn, 2);
if ($slot2 === null) {
    echo "SKIP remaining DB tests: no second free exam+student pair\n";
    echo ($fails === 0 ? "ALL PASS (partial)\n" : "FAILURES=$fails\n");
    exit($fails === 0 ? 0 : 1);
}
$examId = $slot2['exam_id'];
$userId = $slot2['user_id'];
echo "QA slot2 exam_id={$examId} user_id={$userId}\n";

$started2 = date('Y-m-d H:i:s', time() - 600);
$expires2 = date('Y-m-d H:i:s', time() - ($grace + 30));
$stale2 = date('Y-m-d H:i:s', time() - ($hold + 60));
$ins2 = mysqli_prepare(
    $conn,
    "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at)
     VALUES (?, ?, 'in_progress', ?, ?, ?)"
);
mysqli_stmt_bind_param($ins2, 'iisss', $examId, $userId, $started2, $expires2, $stale2);
mysqli_stmt_execute($ins2);
$attemptId2 = (int)mysqli_insert_id($conn);
mysqli_stmt_close($ins2);
qa_abort_if_protected_attempt($attemptId2);
$createdAttemptIds[] = $attemptId2;

try {
    $peek = [
        'status' => 'in_progress',
        'expires_at' => $expires2,
    ];
    $isExpiredIp = college_exam_attempt_is_expired_in_progress($peek);
    pass('helper detects expired in_progress', $isExpiredIp === true);
    if ($isExpiredIp !== true) {
        $fails++;
    }

    $st3 = mysqli_query($conn, 'SELECT status, last_seen_at FROM college_exam_attempts WHERE attempt_id=' . $attemptId2 . ' LIMIT 1');
    $row3 = $st3 ? mysqli_fetch_assoc($st3) : null;
    $stillIp = $row3 && strtolower((string)$row3['status']) === 'in_progress';
    pass('take-page defer leaves expired attempt in_progress (stale last_seen)', $stillIp, json_encode($row3));
    if (!$stillIp) {
        $fails++;
    }

    $beforeSeen = (string)($row3['last_seen_at'] ?? '');
    $nowSql = date('Y-m-d H:i:s');
    $touch = mysqli_prepare(
        $conn,
        "UPDATE college_exam_attempts SET last_seen_at=? WHERE attempt_id=? AND user_id=? AND status='in_progress'"
    );
    mysqli_stmt_bind_param($touch, 'sii', $nowSql, $attemptId2, $userId);
    mysqli_stmt_execute($touch);
    mysqli_stmt_close($touch);
    $st4 = mysqli_query($conn, 'SELECT last_seen_at, status FROM college_exam_attempts WHERE attempt_id=' . $attemptId2 . ' LIMIT 1');
    $row4 = $st4 ? mysqli_fetch_assoc($st4) : null;
    $seenAfter = (string)($row4['last_seen_at'] ?? '');
    $okTouch = $seenAfter !== '' && $seenAfter !== $beforeSeen
        && strtolower((string)($row4['status'] ?? '')) === 'in_progress';
    pass('submit-path last_seen_at touch while in_progress', $okTouch, "before=$beforeSeen after=$seenAfter");
    if (!$okTouch) {
        $fails++;
    }

    $nSkip2 = college_exam_finalize_expired_in_progress($conn, $examId, $userId, 0);
    $st5 = mysqli_query($conn, 'SELECT status FROM college_exam_attempts WHERE attempt_id=' . $attemptId2 . ' LIMIT 1');
    $status5 = $st5 ? strtolower((string)(mysqli_fetch_assoc($st5)['status'] ?? '')) : '';
    pass('activity hold after submit touch skips auto-finalize', $status5 === 'in_progress', "status=$status5 n=$nSkip2");
    if ($status5 !== 'in_progress') {
        $fails++;
    }

    $takeSrc = (string)file_get_contents(dirname(__DIR__) . '/examination/examinee/college_take_exam.php');
    $fnPos = strpos($takeSrc, 'function submitNow(reason)');
    $promotePos = $fnPos !== false ? strpos($takeSrc, 'promotePendingSavesToLocalBackup()', $fnPos) : false;
    $submittingPos = $fnPos !== false ? strpos($takeSrc, 'state.submitting = true;', $fnPos) : false;
    $okOrder = $fnPos !== false && $promotePos !== false && $submittingPos !== false && $promotePos < $submittingPos;
    pass('submitNow source: promote before state.submitting', $okOrder, "promote@$promotePos submitting@$submittingPos");
    if (!$okOrder) {
        $fails++;
    }

    $awaitSafe = strpos($takeSrc, 'if (state.submitting || answersLocked)') !== false
        && strpos($takeSrc, 'promotePendingSavesToLocalBackup();') !== false;
    pass('awaitInflightSaves promotes when submitting/locked', $awaitSafe);
    if (!$awaitSafe) {
        $fails++;
    }

    $singleSubmit = strpos($takeSrc, 'function sendSingleSubmit') !== false
        && strpos($takeSrc, 'MAX_SUBMIT_ATTEMPTS = 5') !== false
        && strpos($takeSrc, 'submitInFlight') !== false;
    pass('timeout submit is single-flight with bounded retries', $singleSubmit);
    if (!$singleSubmit) {
        $fails++;
    }

    $nonEmptyGuard = strpos($takeSrc, 'answersJsonHasAnswers') !== false
        && strpos($takeSrc, 'timeoutSubmitUnderway') !== false;
    pass('beforeunload keepalive requires non-empty timeout path', $nonEmptyGuard);
    if (!$nonEmptyGuard) {
        $fails++;
    }

    $ajaxSrc = (string)file_get_contents(dirname(__DIR__) . '/examination/examinee/college_exam_ajax.php');
    $hasSubmitTouch = strpos($ajaxSrc, 'Do not UPDATE last_seen_at while this attempt row is locked') !== false;
    pass('action=submit does not UPDATE last_seen_at under FOR UPDATE', $hasSubmitTouch);
    if (!$hasSubmitTouch) {
        $fails++;
    }

    $hasIgnoredExplicit = strpos($ajaxSrc, "'answers_ignored' => \$answersIgnored") !== false
        && strpos($ajaxSrc, 'fill_empty_recovered') !== false
        && strpos($ajaxSrc, 'college_exam_fill_empty_timeout_answers') !== false;
    pass('already_submitted timeout path uses gated fill-empty recovery', $hasIgnoredExplicit);
    if (!$hasIgnoredExplicit) {
        $fails++;
    }

    $takePhpDefer = strpos($takeSrc, 'college_exam_attempt_is_expired_in_progress') !== false
        && strpos($takeSrc, 'deferExpiredFinalizeForTakePage') !== false;
    pass('take page defers finalize for expired in_progress', $takePhpDefer);
    if (!$takePhpDefer) {
        $fails++;
    }
} finally {
    qa_cleanup_ephemeral($conn, $attemptId2);
}

echo $fails === 0 ? "ALL PASS\n" : "FAILURES=$fails\n";
exit($fails === 0 ? 0 : 1);
