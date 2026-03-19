<?php
/**
 * Take Preboard - One attempt per set. No retake once submitted.
 * Security model mirrors student_take_quiz.php:
 * - One attempt per user per set
 * - Server-side expires_at timer
 * - Answers saved server-side via preboards_ajax.php
 */
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$setId = sanitizeInt($_GET['preboards_set_id'] ?? 0);
$subjectId = sanitizeInt($_GET['preboards_subject_id'] ?? 0);
$attemptId = sanitizeInt($_GET['attempt_id'] ?? 0);
if ($setId <= 0) {
    header('Location: student_preboards.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT s.*, p.subject_name FROM preboards_sets s JOIN preboards_subjects p ON p.preboards_subject_id=s.preboards_subject_id WHERE s.preboards_set_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $setId);
mysqli_stmt_execute($stmt);
$setRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$setRow) {
    header('Location: student_preboards.php');
    exit;
}
if (!$subjectId) $subjectId = (int)$setRow['preboards_subject_id'];

$userId = getCurrentUserId();

// Enforce lock: set must be open OR student has a one-time access grant OR an existing in-progress/submitted attempt (for resume/review).
$isOpen = (int)($setRow['is_open'] ?? 0) === 1;
$hasUnusedAccessGrant = false;
if (!$isOpen && $userId) {
    // Allow review/finish for existing attempts even when locked
    $stmt = mysqli_prepare($conn, "SELECT status FROM preboards_attempts WHERE user_id=? AND preboards_set_id=? ORDER BY attempt_no DESC, preboards_attempt_id DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $setId);
    mysqli_stmt_execute($stmt);
    $lastAtt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $hasAnyAttempt = !empty($lastAtt);
    $isInProgress = $hasAnyAttempt && ($lastAtt['status'] ?? '') === 'in_progress';
    $isSubmitted = $hasAnyAttempt && ($lastAtt['status'] ?? '') === 'submitted';

    if ($isInProgress) {
        // resume allowed
    } elseif ($isSubmitted) {
        // If student is trying to start a new attempt on a locked set:
        // allow only if there is an unused retake token; otherwise force review.
        $isReviewOrResult = (isset($_GET['review']) && (int)($_GET['review'] ?? 0) === 1) || (isset($_GET['result']) && (int)($_GET['result'] ?? 0) === 1);
        if (!$isReviewOrResult) {
            $tok = mysqli_prepare($conn, "SELECT preboards_retake_token_id FROM preboards_retake_tokens WHERE user_id=? AND preboards_set_id=? AND used_at IS NULL LIMIT 1");
            mysqli_stmt_bind_param($tok, 'ii', $userId, $setId);
            mysqli_stmt_execute($tok);
            $hasTok = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($tok));
            mysqli_stmt_close($tok);
            if (!$hasTok) {
                $stmt = mysqli_prepare($conn, "SELECT preboards_attempt_id FROM preboards_attempts WHERE user_id=? AND preboards_set_id=? ORDER BY attempt_no DESC, preboards_attempt_id DESC LIMIT 1");
                mysqli_stmt_bind_param($stmt, 'ii', $userId, $setId);
                mysqli_stmt_execute($stmt);
                $aidRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                $aid = $aidRow ? (int)$aidRow['preboards_attempt_id'] : 0;
                header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&attempt_id=' . $aid . '&review=1');
                exit;
            }
        }
    } else {
        // No attempts yet: require an unused access grant.
        $acc = mysqli_prepare($conn, "SELECT preboards_set_access_id FROM preboards_set_access WHERE user_id=? AND preboards_set_id=? AND used_at IS NULL AND revoked_at IS NULL LIMIT 1");
        mysqli_stmt_bind_param($acc, 'ii', $userId, $setId);
        mysqli_stmt_execute($acc);
        $hasUnusedAccessGrant = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($acc));
        mysqli_stmt_close($acc);
        if (!$hasUnusedAccessGrant) {
            $_SESSION['error'] = 'This set is currently locked.';
            header('Location: student_preboards_view.php?preboards_subject_id=' . $subjectId);
            exit;
        }
    }
}
$timeLimitSeconds = (int)($setRow['time_limit_seconds'] ?? 3600);
if ($timeLimitSeconds < 1) $timeLimitSeconds = 3600;

// Load questions
$questions = [];
$qRes = mysqli_query($conn, "SELECT * FROM preboards_questions WHERE preboards_set_id=$setId ORDER BY sort_order ASC, preboards_question_id ASC");
if ($qRes) {
    while ($r = mysqli_fetch_assoc($qRes)) {
        $questions[] = $r;
    }
}
$totalQuestions = count($questions);

// Compute remaining seconds from attempt row
function preboards_remaining_seconds($attemptRow, $timeLimitSeconds) {
    if (!$attemptRow) return 0;
    if (!empty($attemptRow['expires_at'])) {
        return max(0, strtotime($attemptRow['expires_at']) - time());
    }
    $started = !empty($attemptRow['started_at']) ? strtotime($attemptRow['started_at']) : time();
    return max(0, ($started + (int)$timeLimitSeconds) - time());
}

// ----- POST: Submit preboard -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_preboard']) && $totalQuestions > 0) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&attempt_id=' . $attemptId);
        exit;
    }
    $aid = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT * FROM preboards_attempts WHERE preboards_attempt_id=? AND user_id=? AND preboards_set_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iii', $aid, $userId, $setId);
    mysqli_stmt_execute($stmt);
    $att = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$att || $att['status'] !== 'in_progress') {
        $_SESSION['error'] = 'Attempt not found or already submitted.';
        header('Location: student_preboards_view.php?preboards_subject_id=' . $subjectId);
        exit;
    }
    $attemptId = (int)$att['preboards_attempt_id'];
    // If expired, still allow submission (auto-submit behavior)
    $total = $totalQuestions;
    $countRes = mysqli_query($conn, "SELECT COUNT(DISTINCT preboards_question_id) AS cnt FROM preboards_answers WHERE preboards_attempt_id=".(int)$attemptId);
    $answered = $countRes ? (int)mysqli_fetch_assoc($countRes)['cnt'] : 0;
    $correctRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM preboards_answers WHERE preboards_attempt_id=".(int)$attemptId." AND is_correct=1");
    $correct = $correctRes ? (int)mysqli_fetch_assoc($correctRes)['cnt'] : 0;
    $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
    $submittedAt = date('Y-m-d H:i:s');
    $upd = mysqli_prepare($conn, "UPDATE preboards_attempts SET status='submitted', score=?, correct_count=?, total_count=?, submitted_at=? WHERE preboards_attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($upd, 'diisii', $score, $correct, $total, $submittedAt, $attemptId, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    $_SESSION['preboard_result'] = ['set_id' => $setId, 'subject_id' => $subjectId, 'attempt_id' => $attemptId, 'score' => $score, 'correct' => $correct, 'total' => $total, 'answered' => $answered];
    header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&result=1');
    exit;
}

// ----- Result view -----
$showResult = isset($_GET['result']) && isset($_SESSION['preboard_result']) && (int)($_SESSION['preboard_result']['set_id'] ?? 0) === $setId;
$result = $showResult ? $_SESSION['preboard_result'] : null;
if ($showResult) unset($_SESSION['preboard_result']);

// Persistent review mode (does not rely on session)
$reviewMode = (isset($_GET['review']) && (int)$_GET['review'] === 1 && $attemptId > 0);
if ($reviewMode && $userId) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM preboards_attempts WHERE preboards_attempt_id=? AND user_id=? AND preboards_set_id=? AND status='submitted' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iii', $attemptId, $userId, $setId);
    mysqli_stmt_execute($stmt);
    $att = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($att) {
        $showResult = true;
        $result = [
            'set_id' => $setId,
            'subject_id' => $subjectId,
            'attempt_id' => (int)$att['preboards_attempt_id'],
            'score' => (float)($att['score'] ?? 0),
            'correct' => (int)($att['correct_count'] ?? 0),
            'total' => (int)($att['total_count'] ?? 0),
        ];
    } else {
        $_SESSION['error'] = 'Review not available.';
        header('Location: student_preboards_view.php?preboards_subject_id=' . $subjectId);
        exit;
    }
}

// ----- Load or create attempt -----
$attempt = null;
$savedAnswers = [];
$remainingSeconds = 0;
// For result view: load review questions with answers from submitted attempt
$reviewQuestions = [];
$attemptHistory = [];
if ($showResult && $result && $userId) {
    // Load attempt history (all submitted attempts for this set)
    $histRes = mysqli_query($conn, "SELECT preboards_attempt_id, attempt_no, score, correct_count, total_count, submitted_at
      FROM preboards_attempts
      WHERE user_id=" . (int)$userId . " AND preboards_set_id=" . (int)$setId . " AND status='submitted'
      ORDER BY attempt_no DESC, preboards_attempt_id DESC");
    if ($histRes) {
        while ($hr = mysqli_fetch_assoc($histRes)) {
            $attemptHistory[] = $hr;
        }
    }

    $aid = (int)($result['attempt_id'] ?? 0);
    if ($aid > 0) {
        // Ensure attempt belongs to user and set
        $stmt = mysqli_prepare($conn, "SELECT preboards_attempt_id FROM preboards_attempts WHERE preboards_attempt_id=? AND user_id=? AND preboards_set_id=? AND status='submitted' LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'iii', $aid, $userId, $setId);
        mysqli_stmt_execute($stmt);
        $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($ok) {
            $qRes = mysqli_query($conn, "SELECT pq.*, pa.selected_answer, pa.is_correct
              FROM preboards_questions pq
              LEFT JOIN preboards_answers pa
                ON pa.preboards_question_id=pq.preboards_question_id
               AND pa.preboards_attempt_id=".(int)$aid."
              WHERE pq.preboards_set_id=".(int)$setId."
              ORDER BY pq.sort_order ASC, pq.preboards_question_id ASC");
            if ($qRes) { while ($r = mysqli_fetch_assoc($qRes)) $reviewQuestions[] = $r; }
        }
    }
}

if ($userId && $totalQuestions > 0 && !$showResult) {
    // If attempt_id missing, resume existing in_progress attempt for this set
    if ($attemptId <= 0) {
        $stmt = mysqli_prepare($conn, "SELECT preboards_attempt_id FROM preboards_attempts WHERE user_id=? AND preboards_set_id=? AND status='in_progress' ORDER BY attempt_no DESC, preboards_attempt_id DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $userId, $setId);
        mysqli_stmt_execute($stmt);
        $resume = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($resume) {
            header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&attempt_id=' . (int)$resume['preboards_attempt_id']);
            exit;
        }
    }
    if ($attemptId > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM preboards_attempts WHERE preboards_attempt_id=? AND user_id=? AND preboards_set_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'iii', $attemptId, $userId, $setId);
        mysqli_stmt_execute($stmt);
        $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
    if (!$attempt) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM preboards_attempts WHERE user_id=? AND preboards_set_id=? ORDER BY attempt_no DESC, preboards_attempt_id DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $userId, $setId);
        mysqli_stmt_execute($stmt);
        $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }

    if ($attempt && $attempt['status'] === 'submitted') {
        // Allow retake only if there is an unused retake token.
        $tok = mysqli_prepare($conn, "SELECT preboards_retake_token_id FROM preboards_retake_tokens WHERE user_id=? AND preboards_set_id=? AND used_at IS NULL ORDER BY preboards_retake_token_id ASC LIMIT 1");
        mysqli_stmt_bind_param($tok, 'ii', $userId, $setId);
        mysqli_stmt_execute($tok);
        $tokenRow = mysqli_fetch_assoc(mysqli_stmt_get_result($tok));
        mysqli_stmt_close($tok);
        if (!$tokenRow) {
            header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&attempt_id=' . (int)$attempt['preboards_attempt_id'] . '&review=1');
            exit;
        }
        // token exists: create a new attempt (attempt_no = last + 1) and mark token used
        $lastNo = (int)($attempt['attempt_no'] ?? 1);
        $newNo = max(1, $lastNo + 1);
        $now = time();
        $startedAt = date('Y-m-d H:i:s', $now);
        $expiresAt = date('Y-m-d H:i:s', $now + $timeLimitSeconds);
        mysqli_begin_transaction($conn);
        try {
            $useAt = date('Y-m-d H:i:s');
            $updTok = mysqli_prepare($conn, "UPDATE preboards_retake_tokens SET used_at=? WHERE preboards_retake_token_id=? AND user_id=? AND preboards_set_id=? AND used_at IS NULL");
            $tokId = (int)$tokenRow['preboards_retake_token_id'];
            mysqli_stmt_bind_param($updTok, 'siii', $useAt, $tokId, $userId, $setId);
            mysqli_stmt_execute($updTok);
            $affected = mysqli_stmt_affected_rows($updTok);
            mysqli_stmt_close($updTok);
            if ($affected < 1) {
                throw new Exception('Token already used');
            }

            $ins = mysqli_prepare($conn, "INSERT INTO preboards_attempts (user_id, preboards_set_id, attempt_no, started_at, expires_at, status) VALUES (?, ?, ?, ?, ?, 'in_progress')");
            mysqli_stmt_bind_param($ins, 'iiiss', $userId, $setId, $newNo, $startedAt, $expiresAt);
            mysqli_stmt_execute($ins);
            $newAttemptId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($ins);

            mysqli_commit($conn);
            header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&attempt_id=' . $newAttemptId);
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error'] = 'Could not start retake. Please try again.';
            header('Location: student_preboards_view.php?preboards_subject_id=' . $subjectId);
            exit;
        }
    }

    if (!$attempt) {
        $now = time();
        $startedAt = date('Y-m-d H:i:s', $now);
        $expiresAt = date('Y-m-d H:i:s', $now + $timeLimitSeconds);
        // If set is locked and access was granted, consume the one-time grant atomically with attempt creation.
        $isOpenNow = (int)($setRow['is_open'] ?? 0) === 1;
        if (!$isOpenNow) {
            mysqli_begin_transaction($conn);
            try {
                $useAt = date('Y-m-d H:i:s');
                $updAcc = mysqli_prepare($conn, "UPDATE preboards_set_access SET used_at=? WHERE user_id=? AND preboards_set_id=? AND used_at IS NULL AND revoked_at IS NULL");
                mysqli_stmt_bind_param($updAcc, 'sii', $useAt, $userId, $setId);
                mysqli_stmt_execute($updAcc);
                $accAffected = mysqli_stmt_affected_rows($updAcc);
                mysqli_stmt_close($updAcc);
                if ($accAffected < 1) {
                    throw new Exception('No unused access grant');
                }

                $stmt = mysqli_prepare($conn, "INSERT INTO preboards_attempts (user_id, preboards_set_id, attempt_no, started_at, expires_at, status) VALUES (?, ?, 1, ?, ?, 'in_progress')");
                mysqli_stmt_bind_param($stmt, 'iiss', $userId, $setId, $startedAt, $expiresAt);
                mysqli_stmt_execute($stmt);
                $attemptId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $_SESSION['error'] = 'This set is currently locked.';
                header('Location: student_preboards_view.php?preboards_subject_id=' . $subjectId);
                exit;
            }
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO preboards_attempts (user_id, preboards_set_id, attempt_no, started_at, expires_at, status) VALUES (?, ?, 1, ?, ?, 'in_progress')");
            mysqli_stmt_bind_param($stmt, 'iiss', $userId, $setId, $startedAt, $expiresAt);
            mysqli_stmt_execute($stmt);
            $attemptId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        }
        if ($attemptId > 0) {
            header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&attempt_id=' . $attemptId);
            exit;
        }
    } else {
        $attemptId = (int)$attempt['preboards_attempt_id'];
        $remainingSeconds = preboards_remaining_seconds($attempt, $timeLimitSeconds);
        // Auto-submit if time expired (server side)
        if ($remainingSeconds <= 0 && $attempt['status'] === 'in_progress') {
            $countRes = mysqli_query($conn, "SELECT COUNT(DISTINCT preboards_question_id) AS cnt FROM preboards_answers WHERE preboards_attempt_id=".(int)$attemptId);
            $answered = $countRes ? (int)mysqli_fetch_assoc($countRes)['cnt'] : 0;
            $correctRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM preboards_answers WHERE preboards_attempt_id=".(int)$attemptId." AND is_correct=1");
            $correct = $correctRes ? (int)mysqli_fetch_assoc($correctRes)['cnt'] : 0;
            $total = $totalQuestions;
            $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
            $submittedAt = date('Y-m-d H:i:s');
            $upd = mysqli_prepare($conn, "UPDATE preboards_attempts SET status='submitted', score=?, correct_count=?, total_count=?, submitted_at=? WHERE preboards_attempt_id=? AND user_id=?");
            mysqli_stmt_bind_param($upd, 'diisii', $score, $correct, $total, $submittedAt, $attemptId, $userId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            $_SESSION['preboard_result'] = ['set_id' => $setId, 'subject_id' => $subjectId, 'attempt_id' => $attemptId, 'score' => $score, 'correct' => $correct, 'total' => $total, 'answered' => $answered];
            header('Location: student_take_preboard.php?preboards_set_id=' . $setId . '&preboards_subject_id=' . $subjectId . '&result=1');
            exit;
        }
        $ar = mysqli_query($conn, "SELECT preboards_question_id, selected_answer FROM preboards_answers WHERE preboards_attempt_id=".(int)$attemptId);
        if ($ar) {
            while ($a = mysqli_fetch_assoc($ar)) {
                $savedAnswers[(int)$a['preboards_question_id']] = $a['selected_answer'];
            }
        }
    }
}

function get_preboard_choices($q) {
    $letters = ['A','B','C','D','E','F','G','H','I','J'];
    $out = [];
    foreach ($letters as $letter) {
        $col = 'choice_' . strtolower($letter);
        if (isset($q[$col]) && trim((string)$q[$col]) !== '') {
            $out[$letter] = trim($q[$col]);
        }
    }
    return $out;
}

$pageTitle = 'Preboard - ' . ($setRow['subject_name'] ?? '') . ' Set ' . ($setRow['set_label'] ?? '');
$csrf = generateCSRFToken();
$backUrl = 'student_preboards_view.php?preboards_subject_id=' . $subjectId;
$timeLimitLabel = formatTimeLimitSeconds($timeLimitSeconds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    <?php
      // Reuse the exact exam UI styles from the quiz page for pixel-identical layout.
      // (Kept inline like student_take_quiz.php to avoid path/version drift.)
      include __DIR__ . '/includes/_exam_ui_styles_inline.php';
    ?>
  </style>
</head>
<body class="font-sans antialiased exam-protected">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="w-full">
    <?php if (isset($_SESSION['error'])): ?>
      <div class="mb-5 mx-4 sm:mx-6 mt-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo h($_SESSION['error']); ?></span>
        <?php unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <!-- Preboard header card (match quiz header layout) -->
    <section class="mb-4 sm:mb-5 mx-4 sm:mx-6 mt-5">
      <div class="rounded-2xl px-4 sm:px-6 py-4 sm:py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0 flex-1">
          <a href="<?php echo htmlspecialchars($backUrl); ?>" class="exam-leave-link flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md hover:bg-white/25 transition" aria-label="Back">
            <i class="bi bi-arrow-left text-lg sm:text-xl" aria-hidden="true"></i>
          </a>
          <span class="flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
            <i class="bi bi-clipboard-check text-lg sm:text-xl" aria-hidden="true"></i>
          </span>
          <div class="min-w-0">
            <h1 class="text-lg sm:text-xl md:text-2xl font-bold m-0 tracking-tight truncate"><?php echo h($setRow['title'] ?: ('Set ' . ($setRow['set_label'] ?? ''))); ?></h1>
            <p class="text-xs sm:text-sm text-white/90 mt-1 mb-0 flex items-center gap-3">
              <span class="inline-flex items-center gap-1"><i class="bi bi-book" aria-hidden="true"></i><?php echo h($setRow['subject_name']); ?></span>
              <span class="inline-flex items-center gap-2 text-white/80 text-[0.75rem] sm:text-xs">
                <span class="inline-flex items-center gap-1"><i class="bi bi-list-ol"></i> <?php echo (int)$totalQuestions; ?> questions</span>
                <span class="inline-flex items-center gap-1"><i class="bi bi-clock"></i> <?php echo h($timeLimitLabel); ?></span>
              </span>
            </p>
          </div>
        </div>
      </div>
    </section>

    <?php if ($showResult && $result): ?>
      <?php
        $score = (float)($result['score'] ?? 0);
        $resultClass = $score >= 50 ? 'result-pass' : 'result-fail';
        $resultLabel = $score >= 50 ? 'Passed' : 'Failed';
        $correctCount = (int)($result['correct'] ?? 0);
        $totalCount = (int)($result['total'] ?? 0);
        $incorrectCount = max(0, $totalCount - $correctCount);
        $scoreWidth = max(4, min(100, (int)round($score)));
      ?>
      <div class="exam-page-container exam-page-container-result px-4 sm:px-6">
        <div class="exam-result-inner">
          <div class="exam-question-card result-card w-full text-center mb-4 <?php echo $resultClass; ?>">
            <div class="result-card-inner">
              <div class="flex flex-col items-center gap-2 mb-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white/80 shadow-sm">
                  <i class="bi bi-trophy-fill text-[#f97373] text-xl"></i>
                </div>
                <div class="exam-question-label"><?php echo h($setRow['subject_name']); ?> — Preboard Complete</div>
              </div>
              <span class="result-badge"><?php echo h($resultLabel); ?></span>
              <h2 class="text-2xl font-bold text-[#1e293b] mb-2"><i class="bi bi-bar-chart-fill mr-2"></i>Results</h2>
              <div class="text-5xl font-extrabold mb-3 result-score tracking-tight"><?php echo number_format($score, 0); ?>%</div>
              <div class="w-full max-w-xs mx-auto h-2 rounded-full bg-white/70 overflow-hidden mb-4">
                <div class="h-full rounded-full" style="width: <?php echo $scoreWidth; ?>%; background: linear-gradient(90deg, #3393ff, #60a5fa);"></div>
              </div>
              <p class="text-[#64748b] mb-3 text-sm sm:text-base">
                You got <strong class="text-[#1e293b]"><?php echo $correctCount; ?></strong> out of
                <strong class="text-[#1e293b]"><?php echo $totalCount; ?></strong> questions correct.
              </p>
              <div class="result-stats-row text-xs sm:text-sm text-[#64748b]">
                <span class="result-stat-card result-stat-correct"><i class="bi bi-check-circle-fill"></i><?php echo $correctCount; ?> correct</span>
                <span class="result-stat-card result-stat-wrong"><i class="bi bi-x-circle-fill"></i><?php echo $incorrectCount; ?> incorrect</span>
                <span class="result-stat-card result-stat-total"><i class="bi bi-question-circle"></i><?php echo $totalCount; ?> questions</span>
              </div>

              <?php if (!empty($attemptHistory)): ?>
                <div class="mt-4 pt-4 border-t border-[#dbeafe]">
                  <div class="text-sm font-semibold text-[#1e293b] mb-2"><i class="bi bi-clock-history mr-1 text-[#4154f1]"></i>Attempt history</div>
                  <div class="flex flex-wrap gap-2 justify-center">
                    <?php foreach ($attemptHistory as $h): ?>
                      <?php
                        $hid = (int)($h['preboards_attempt_id'] ?? 0);
                        $hno = (int)($h['attempt_no'] ?? 1);
                        $hscore = number_format((float)($h['score'] ?? 0), 0) . '%';
                        $isCurrent = $hid === (int)($result['attempt_id'] ?? 0);
                      ?>
                      <a href="student_take_preboard.php?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo $subjectId; ?>&attempt_id=<?php echo $hid; ?>&review=1"
                         class="px-3 py-2 rounded-xl border text-sm font-semibold transition <?php echo $isCurrent ? 'bg-[#1665A0] border-[#1665A0] text-white' : 'bg-white border-gray-200 text-[#143D59] hover:bg-gray-50'; ?>">
                        Attempt <?php echo $hno; ?> · <?php echo $hscore; ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>

              <div class="result-actions-bar mt-4 pt-3 border-t border-[#dbeafe]">
                <a href="<?php echo h($backUrl); ?>" class="result-actions-primary"><i class="bi bi-arrow-left-circle"></i> Back to sets</a>
                <?php if (!empty($reviewQuestions)): ?>
                  <a href="#reviewAnswers" class="result-actions-primary" style="background:#ffffff;color:#4154f1;border-color:#d1d5db">
                    <i class="bi bi-journal-text"></i> Review Answers
                  </a>
                <?php endif; ?>
              </div>
              <p class="text-xs text-[#64748b] mt-3">You cannot retake this set.</p>
            </div>
          </div>
        </div>
      </div>
      <?php if (!empty($reviewQuestions)): ?>
      <div class="exam-page-container px-4 sm:px-6" id="reviewAnswers">
        <div class="exam-question-card w-full mb-5">
          <h3 class="text-lg font-bold text-[#1e293b] mb-4"><i class="bi bi-journal-text text-[#4154f1] mr-2"></i>Review answers</h3>
          <p class="text-[#64748b] text-sm mb-4">Review each question, your answer, and the correct answer below.</p>
          <div class="space-y-5">
            <?php foreach ($reviewQuestions as $i => $q): ?>
              <?php
                $choices = get_preboard_choices($q);
                $sel = $q['selected_answer'] ?? '';
                $correctAns = $q['correct_answer'] ?? '';
                $isCorrect = !empty($q['is_correct']);
              ?>
              <div class="border rounded-xl p-5 <?php echo $isCorrect ? 'review-item-correct' : 'review-item-wrong'; ?>">
                <div class="text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Question <?php echo $i + 1; ?> of <?php echo count($reviewQuestions); ?></div>
                <div class="text-base font-semibold text-[#1e293b] mb-4 leading-relaxed"><?php echo nl2br(h($q['question_text'])); ?></div>
                <div class="space-y-2 mb-4">
                  <?php foreach ($choices as $letter => $choiceText): ?>
                    <?php
                      $isYourAnswer = ($sel === $letter);
                      $isCorrectChoice = ($correctAns === $letter);
                      $cls = '';
                      if ($isCorrectChoice) $cls = 'review-correct-choice';
                    ?>
                    <div class="review-choice flex items-start gap-3 p-3 rounded-xl border border-gray-200 <?php echo $cls; ?>">
                      <span class="review-choice-letter w-9 h-9 rounded-full flex items-center justify-center font-bold bg-gray-100 text-gray-700 shrink-0"><?php echo h($letter); ?></span>
                      <div class="flex-1">
                        <div class="text-gray-800"><?php echo nl2br(h($choiceText)); ?></div>
                        <div class="text-xs mt-1">
                          <?php if ($isYourAnswer): ?>
                            <span class="px-2 py-0.5 rounded-full bg-sky-50 text-sky-700 border border-sky-200 font-semibold">Your answer</span>
                          <?php endif; ?>
                          <?php if ($isCorrectChoice): ?>
                            <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-semibold review-correct-label">Correct answer</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="text-sm font-semibold <?php echo $isCorrect ? 'text-emerald-700' : 'text-red-700'; ?>">
                  <?php if ($isCorrect): ?>
                    <i class="bi bi-check-circle-fill mr-1"></i> Correct
                  <?php else: ?>
                    <i class="bi bi-x-circle-fill mr-1"></i> Incorrect
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    <?php elseif ($totalQuestions > 0 && $attemptId > 0): ?>
      <?php
        $answeredCount = 0;
        $questionIds = [];
        foreach ($questions as $qq) {
          $qid = (int)$qq['preboards_question_id'];
          $questionIds[] = $qid;
          if (isset($savedAnswers[$qid]) && $savedAnswers[$qid] !== '') $answeredCount++;
        }
        $allAnswered = ($totalQuestions > 0 && $answeredCount >= $totalQuestions);
        $remainingSeconds = (int)$remainingSeconds;
        $min = floor($remainingSeconds / 60);
        $sec = $remainingSeconds % 60;
        $timerDisplay = $remainingSeconds >= 3600
          ? sprintf('%d:%02d:%02d', floor($remainingSeconds / 3600), (int)($remainingSeconds / 60) % 60, $sec)
          : sprintf('%02d:%02d', $min, $sec);
      ?>
      <div class="exam-page-container px-4 sm:px-6">
      <div class="exam-bar mb-4">
        <div class="exam-header">
          <div class="exam-header-left">
            <span class="exam-title"><?php echo h($setRow['subject_name']); ?> — Set <?php echo h($setRow['set_label']); ?></span>
            <span class="exam-subject"><i class="bi bi-book mr-1"></i><?php echo h($setRow['subject_name']); ?> · Preboards</span>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="exam-q-badge"><strong id="answeredCountNum"><?php echo $answeredCount; ?></strong> of <strong><?php echo $totalQuestions; ?></strong> answered</div>
          </div>
        </div>
        <div class="exam-progress-wrap">
          <div class="exam-progress-bar">
            <div class="exam-progress-fill" id="progressBar" style="width: <?php echo $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100) : 0; ?>%"></div>
          </div>
          <div class="exam-progress-label" id="progressText"><?php echo $answeredCount; ?> of <?php echo $totalQuestions; ?> answered</div>
        </div>
      </div>

      <div class="exam-layout exam-protected">
        <div class="exam-main">
      <?php foreach ($questions as $idx => $q): $num = $idx + 1; ?>
      <div class="exam-question-card" id="q<?php echo $num; ?>">
        <div class="exam-question-label">Question <?php echo $num; ?> of <?php echo $totalQuestions; ?></div>
        <h2 class="exam-question-text mb-6"><?php echo nl2br(h($q['question_text'])); ?></h2>
        <div class="exam-choices">
          <?php $choices = get_preboard_choices($q); foreach ($choices as $letter => $choiceText): ?>
            <?php $isSelected = (isset($savedAnswers[(int)$q['preboards_question_id']]) && $savedAnswers[(int)$q['preboards_question_id']] === $letter); ?>
            <label class="exam-choice <?php echo $isSelected ? 'selected' : ''; ?>">
              <input type="radio" name="answer_<?php echo (int)$q['preboards_question_id']; ?>" value="<?php echo $letter; ?>" class="sr-only" data-question-id="<?php echo (int)$q['preboards_question_id']; ?>"
                <?php echo $isSelected ? ' checked' : ''; ?>>
              <span class="exam-choice-letter"><?php echo $letter; ?></span>
              <span class="exam-choice-text"><?php echo h($choiceText); ?></span>
              <span class="exam-choice-check"><i class="bi bi-check"></i></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="exam-nav-card">
        <form method="POST" id="submitForm" action="student_take_preboard.php?preboards_set_id=<?php echo $setId; ?>&preboards_subject_id=<?php echo $subjectId; ?>" class="w-full">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="submit_preboard" value="1">
          <input type="hidden" name="attempt_id" value="<?php echo $attemptId; ?>">
          <button type="submit" id="submitQuizBtn" class="exam-btn-submit w-full justify-center" <?php echo $allAnswered ? '' : 'disabled'; ?>>
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $allAnswered ? 'Submit preboard' : 'Answer all questions to submit'; ?>
          </button>
        </form>
        <p class="text-xs text-[#64748b] mt-2">No retake after submission.</p>
      </div>
        </div>

        <aside class="exam-sidebar">
          <div class="exam-sidebar-card mb-4">
            <div class="exam-sidebar-title text-center">Timer remaining</div>
            <div class="exam-timer-circle-wrap" id="examTimerCircle" data-remaining="<?php echo $remainingSeconds; ?>" data-total="<?php echo (int)$timeLimitSeconds; ?>">
              <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle class="exam-timer-circle-track" cx="60" cy="60" r="52"/>
                <circle class="exam-timer-circle-progress" id="examTimerCircleProgress" cx="60" cy="60" r="52" stroke-dasharray="327" stroke-dashoffset="0"/>
              </svg>
              <div class="exam-timer-circle-inner">
                <span class="exam-timer-circle-value" id="examTimerCircleValue"><?php echo $timerDisplay; ?></span>
                <span class="exam-timer-circle-label">MM : SS</span>
              </div>
            </div>
            <p class="text-center text-xs text-[#64748b] px-3 pb-3">Limit: <?php echo h(formatTimeLimitSeconds($timeLimitSeconds)); ?></p>
          </div>
          <div class="exam-sidebar-card">
            <div class="exam-sidebar-title flex items-center justify-between flex-wrap gap-1">
              <span>Questions</span>
              <span class="text-xs font-normal text-[#64748b]"><?php echo $totalQuestions; ?> total</span>
            </div>
            <div class="exam-sidebar-section" id="examQListSection">
              <button type="button" class="exam-sidebar-section-head" id="examQListTrigger" aria-expanded="true">
                <span>Jump to question</span>
                <i class="bi bi-chevron-up"></i>
              </button>
              <div class="exam-q-list">
                <?php for ($n = 1; $n <= $totalQuestions; $n++): ?>
                  <?php $qIdForN = $questionIds[$n - 1] ?? 0; $answered = isset($savedAnswers[$qIdForN]) && $savedAnswers[$qIdForN] !== ''; ?>
                  <a href="#q<?php echo $n; ?>" class="<?php echo $answered ? 'answered' : ''; ?>" data-question-id="<?php echo $qIdForN; ?>">
                    <span class="q-num"><?php echo $n; ?></span>
                    <span>Question <?php echo $n; ?></span>
                    <?php if ($answered): ?><i class="bi bi-check-circle-fill q-check"></i><?php endif; ?>
                  </a>
                <?php endfor; ?>
              </div>
            </div>
          </div>
        </aside>
      </div>
      </div><!-- end exam-page-container -->

      <div class="exam-saved-toast" id="examSavedToast" aria-live="polite"><i class="bi bi-check-circle-fill mr-1"></i> Answer saved</div>
      <div class="exam-time-warning-toast" id="examTimeWarningToast" aria-live="assertive" role="alert"></div>

      <!-- Global submit loader (match quiz) -->
      <div class="quiz-submit-overlay" id="quizSubmitOverlay" aria-live="assertive" aria-busy="true">
        <div class="quiz-submit-card">
          <div class="quiz-submit-spinner"></div>
          <div class="quiz-submit-title">Submitting your preboard…</div>
          <p class="quiz-submit-text">Please wait a moment while we save your answers and calculate your score.</p>
        </div>
      </div>

      <!-- Leave confirm modal (match quiz) -->
      <div class="quiz-confirm-overlay" id="examLeaveConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="examLeaveConfirmTitle" aria-describedby="examLeaveConfirmMessage">
        <div class="quiz-confirm-card">
          <div class="quiz-confirm-icon-wrap warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
          </div>
          <div class="quiz-confirm-header" id="examLeaveConfirmTitle">Leave preboard?</div>
          <div class="quiz-confirm-body" id="examLeaveConfirmMessage">
            Are you sure you want to leave the preboard? Your progress will be saved and you can resume later.
          </div>
          <div class="quiz-confirm-actions">
            <button type="button" class="quiz-confirm-btn quiz-confirm-btn-cancel" id="examLeaveConfirmCancel">Stay</button>
            <button type="button" class="quiz-confirm-btn quiz-confirm-btn-primary" id="examLeaveConfirmLeave">Leave</button>
          </div>
        </div>
      </div>

      <script>
      (function() {
        function setLeavingAllowed(allow) { window.__examLeavingAllowed = !!allow; }
        setLeavingAllowed(false);
        window.addEventListener('beforeunload', function(e) {
          if (window.__examLeavingAllowed) return;
          e.preventDefault();
          e.returnValue = 'Are you sure you want to leave? Your progress is saved.';
          return e.returnValue;
        });

        // Leave confirmation (use custom modal, not browser dialog)
        var leaveOverlay = document.getElementById('examLeaveConfirmOverlay');
        var leaveCancelBtn = document.getElementById('examLeaveConfirmCancel');
        var leaveLeaveBtn = document.getElementById('examLeaveConfirmLeave');
        var leaveUrl = '';
        function hideLeaveModal() { if (leaveOverlay) leaveOverlay.classList.remove('show'); }
        function showLeaveModal(url) { leaveUrl = url || ''; if (leaveOverlay) leaveOverlay.classList.add('show'); }
        document.addEventListener('click', function(e) {
          var link = e.target.closest('a.exam-leave-link');
          if (!link || !link.href) return;
          e.preventDefault();
          showLeaveModal(link.getAttribute('href'));
        });
        if (leaveCancelBtn) leaveCancelBtn.addEventListener('click', hideLeaveModal);
        if (leaveLeaveBtn) leaveLeaveBtn.addEventListener('click', function() {
          setLeavingAllowed(true);
          hideLeaveModal();
          if (leaveUrl) window.location.href = leaveUrl;
        });
        if (leaveOverlay) leaveOverlay.addEventListener('click', function(e) { if (e.target === leaveOverlay) hideLeaveModal(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && leaveOverlay && leaveOverlay.classList.contains('show')) hideLeaveModal(); });

        var form = document.getElementById('submitForm');
        var submitBtn = document.getElementById('submitQuizBtn');
        var submitOverlay = document.getElementById('quizSubmitOverlay');
        if (form) {
          form.addEventListener('submit', function(e) {
            if (!form.dataset.submitting) {
              e.preventDefault();
              form.dataset.submitting = '1';
              if (submitOverlay) submitOverlay.classList.add('show');
              setTimeout(function() {
                setLeavingAllowed(true);
                form.submit();
              }, 900);
              return;
            }
            setLeavingAllowed(true);
          });
        }

        var circleTimer = document.getElementById('examTimerCircle');
        var circleTimerValue = document.getElementById('examTimerCircleValue');
        var circleProgress = document.getElementById('examTimerCircleProgress');
        if (!circleTimer || !circleTimerValue || !circleProgress) return;

        var serverRemaining = parseInt(circleTimer.getAttribute('data-remaining'), 10);
        var totalSec = parseInt(circleTimer.getAttribute('data-total'), 10) || Math.max(1, serverRemaining);
        var circumference = 327;
        var expires = new Date(Date.now() + (isNaN(serverRemaining) ? 0 : serverRemaining) * 1000);
        var lastSyncAt = Date.now();
        var SYNC_INTERVAL_MS = 30000;
        var timeWarned = { 300: false, 60: false, 30: false };
        var timeWarningToast = document.getElementById('examTimeWarningToast');
        function showTimeWarning(text, isDanger) {
          if (!timeWarningToast) return;
          timeWarningToast.textContent = text;
          timeWarningToast.className = 'exam-time-warning-toast show ' + (isDanger ? 'danger' : 'warning');
          setTimeout(function() { timeWarningToast.classList.remove('show'); }, 5000);
        }
        function formatTime(sec) {
          var h = Math.floor(sec / 3600);
          var m = Math.floor((sec % 3600) / 60);
          var s = sec % 60;
          if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
          return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }
        function syncTimerFromServer() {
          var fd = new FormData();
          fd.append('action', 'get_time');
          fd.append('attempt_id', <?php echo (int)$attemptId; ?>);
          fetch('preboards_ajax.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
              if (data.ok && typeof data.remaining_seconds === 'number' && data.remaining_seconds >= 0) {
                expires = new Date(Date.now() + data.remaining_seconds * 1000);
                lastSyncAt = Date.now();
              }
            });
        }
        window.__examTimerPaused = false;
        function updateTimer() {
          if (window.__examTimerPaused) { setTimeout(updateTimer, 500); return; }
          if (Date.now() - lastSyncAt >= SYNC_INTERVAL_MS) syncTimerFromServer();
          var rem = Math.max(0, Math.floor((expires - new Date()) / 1000));
          circleTimerValue.textContent = formatTime(rem);
          var pct = totalSec > 0 ? rem / totalSec : 0;
          circleProgress.setAttribute('stroke-dashoffset', circumference * (1 - pct));
          circleTimer.classList.remove('warning', 'danger');
          if (rem <= 60) circleTimer.classList.add('danger');
          else if (rem <= 300) circleTimer.classList.add('warning');
          if (rem === 300 && !timeWarned[300]) { timeWarned[300] = true; showTimeWarning('5 minutes remaining.', false); }
          if (rem === 60 && !timeWarned[60]) { timeWarned[60] = true; showTimeWarning('1 minute remaining!', true); }
          if (rem === 30 && !timeWarned[30]) { timeWarned[30] = true; showTimeWarning('30 seconds left! Submit soon.', true); }
          if (rem <= 0) { setLeavingAllowed(true); form && form.submit(); return; }
          setTimeout(updateTimer, 1000);
        }
        updateTimer();

        var qListSection = document.getElementById('examQListSection');
        var qListTrigger = document.getElementById('examQListTrigger');
        if (qListSection && qListTrigger) {
          qListTrigger.addEventListener('click', function() {
            qListSection.classList.toggle('collapsed');
            qListTrigger.setAttribute('aria-expanded', !qListSection.classList.contains('collapsed'));
          });
        }

        var toast = document.getElementById('examSavedToast');
        function showSavedToast() {
          if (!toast) return;
          toast.classList.add('show');
          setTimeout(function() { toast.classList.remove('show'); }, 2200);
        }

        var csrf = '<?php echo addslashes($csrf); ?>';
        var attemptId = <?php echo (int)$attemptId; ?>;
        var progressEl = document.getElementById('progressText');
        var progressBar = document.getElementById('progressBar');
        var total = <?php echo (int)$totalQuestions; ?>;
        var answeredCountEl = document.getElementById('answeredCountNum');
        function updateProgressUi(answered) {
          if (progressBar) progressBar.style.width = Math.round((answered / total) * 100) + '%';
          if (progressEl) progressEl.textContent = answered + ' of ' + total + ' answered';
          if (answeredCountEl) answeredCountEl.textContent = answered;
          if (submitBtn && answered >= total) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class=\"bi bi-check-circle-fill\"></i> Submit preboard';
          }
        }
        document.querySelectorAll('input[name^=\"answer_\"]').forEach(function(radio) {
          radio.addEventListener('change', function() {
            var qId = this.getAttribute('data-question-id');
            var val = this.value;
            var card = radio.closest('.exam-question-card');
            if (card) card.querySelectorAll('.exam-choice').forEach(function(c) { c.classList.remove('selected'); });
            radio.closest('.exam-choice').classList.add('selected');
            var fd = new FormData();
            fd.append('action', 'save_answer');
            fd.append('csrf_token', csrf);
            fd.append('attempt_id', attemptId);
            fd.append('question_id', qId);
            fd.append('selected_answer', val);
            fetch('preboards_ajax.php', { method: 'POST', body: fd })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                if (data.ok && typeof data.answered_count !== 'undefined') {
                  updateProgressUi(data.answered_count);
                  var link = document.querySelector('.exam-q-list a[data-question-id=\"' + qId + '\"]');
                  if (link && !link.classList.contains('answered')) {
                    link.classList.add('answered');
                    if (!link.querySelector('.q-check')) {
                      var ic = document.createElement('i');
                      ic.className = 'bi bi-check-circle-fill q-check';
                      link.appendChild(ic);
                    }
                  }
                  showSavedToast();
                }
              });
          });
        });

        // Basic client-side protections (same as quiz)
        (function() {
          var examRoot = document.querySelector('.exam-layout') || document.body;
          if (!examRoot) return;
          function isInputLike(el) {
            if (!el) return false;
            var tag = el.tagName ? el.tagName.toLowerCase() : '';
            var type = (el.type || '').toLowerCase();
            return tag === 'input' || tag === 'textarea' || el.isContentEditable || type === 'text' || type === 'password';
          }
          examRoot.addEventListener('contextmenu', function(e) { if (!isInputLike(e.target)) e.preventDefault(); });
          examRoot.addEventListener('selectstart', function(e) { if (!isInputLike(e.target)) e.preventDefault(); });
          window.addEventListener('keydown', function(e) {
            var ctrlLike = e.ctrlKey || e.metaKey;
            var key = (e.key || '').toLowerCase();
            if (ctrlLike && ['c','x','s','p','u','a'].indexOf(key) !== -1 && !isInputLike(e.target)) e.preventDefault();
          }, true);
        })();
      })();
      </script>
    <?php else: ?>
      <div class="rounded-2xl border border-[#1665A0]/15 bg-white shadow-lg p-8 text-center">
        <i class="bi bi-inbox text-5xl text-[#1665A0] mb-3"></i>
        <p class="text-lg font-semibold text-[#143D59] m-0">No questions in this set yet.</p>
        <a href="<?php echo h($backUrl); ?>" class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-xl font-semibold bg-[#1665A0] text-white hover:bg-[#0f4d7a] transition">Back to sets</a>
      </div>
    <?php endif; ?>
  </div>
</main>
</div>
</body>
</html>
