<?php
/**
 * Deploy one grouped topic from question-sort session cache into quizzes + quiz_questions (admin).
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/quiz_helpers.php';
require_once __DIR__ . '/../includes/docx_question_parser.php';
require_once __DIR__ . '/../includes/question_sort_cache.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * Best-effort exam item number from stored stem HTML (matches parser "29." style).
 */
function ereview_qsort_lead_item_number_from_stem_html(string $html): ?string {
    $plain = trim(preg_replace(
        '/\s+/u',
        ' ',
        html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')
    ));
    if ($plain === '') {
        return null;
    }
    [$vn, ] = ereview_docx_extract_leading_question_number($plain);

    return $vn !== null ? (string)$vn : null;
}

function ereview_qsort_deploy_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if (!isLoggedIn() || ($_SESSION['role'] ?? '') !== 'admin') {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'Unauthorized.'], 403);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($token)) {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'Invalid security token. Refresh the page and try again.'], 419);
}

$subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
if ($subjectId <= 0) {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'Choose a subject.'], 400);
}

$topicKey = trim((string)($_POST['topic'] ?? ''));
if ($topicKey === '') {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'Missing topic.'], 400);
}

$title = trim((string)($_POST['title'] ?? ''));
if ($title === '') {
    $title = $topicKey;
}

$quizIdExisting = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;

$hours = max(0, min(24, (int)($_POST['time_limit_hours'] ?? 0)));
$mins = max(0, min(59, (int)($_POST['time_limit_mins'] ?? 0)));
$secs = max(0, min(59, (int)($_POST['time_limit_secs'] ?? 0)));
$timeLimitSeconds = $hours * 3600 + $mins * 60 + $secs;
if ($timeLimitSeconds < 1) {
    $timeLimitSeconds = 1;
}
if ($timeLimitSeconds > 86400) {
    $timeLimitSeconds = 86400;
}
$timeLimitMinutes = (int)ceil($timeLimitSeconds / 60);

$cache = ereview_qsort_load_cache();
if ($cache === null) {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'No parsed document in this session. Upload the .docx again on Question sorting.'], 400);
}

$block = null;
foreach ($cache['by_topic'] ?? [] as $b) {
    if (!is_array($b)) {
        continue;
    }
    if ((string)($b['topic'] ?? '') === $topicKey) {
        $block = $b;
        break;
    }
}
if ($block === null) {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'That topic was not found in the current parse. Refresh or re-upload.'], 400);
}

$questions = $block['questions'] ?? [];
if (!is_array($questions) || $questions === []) {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'This topic has no questions to deploy.'], 400);
}

$stmt = mysqli_prepare($conn, 'SELECT subject_id, subject_name FROM subjects WHERE subject_id=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subRes = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($subRes);
mysqli_stmt_close($stmt);
if (!$subject) {
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'Subject not found.'], 400);
}

// Mirror admin_quizzes.php column checks
$quizCols = [];
$qc = @mysqli_query($conn, 'SHOW COLUMNS FROM quizzes');
if ($qc) {
    while ($row = mysqli_fetch_assoc($qc)) {
        $quizCols[] = $row['Field'];
    }
}
if (!in_array('time_limit_minutes', $quizCols, true)) {
    @mysqli_query($conn, 'ALTER TABLE `quizzes` ADD COLUMN `time_limit_minutes` int(11) NOT NULL DEFAULT 30 AFTER `title`');
}
if (!in_array('time_limit_seconds', $quizCols, true)) {
    @mysqli_query($conn, 'ALTER TABLE `quizzes` ADD COLUMN `time_limit_seconds` int(11) NOT NULL DEFAULT 1800 AFTER `time_limit_minutes`');
}

$questionColumns = [];
$qqColRes = @mysqli_query($conn, 'SHOW COLUMNS FROM quiz_questions');
if ($qqColRes) {
    while ($row = mysqli_fetch_assoc($qqColRes)) {
        $questionColumns[] = $row['Field'];
    }
}
$hasExplanation = in_array('explanation', $questionColumns, true);
$hasChoiceFeedback = in_array('choice_a_feedback', $questionColumns, true);
$allChoiceCols = ['choice_a','choice_b','choice_c','choice_d','choice_e','choice_f','choice_g','choice_h','choice_i','choice_j'];
$choiceCols = array_values(array_intersect($allChoiceCols, $questionColumns));
$validCorrectLetters = ['A','B','C','D','E','F','G','H','I','J'];

$letters = ['A','B','C','D','E','F','G','H','I','J'];

/** @return array{stem:string,choices:array<string,string>,correct:string}|null */
$normalize = static function (array $q) use ($letters, $topicKey): ?array {
    $choiceBodies = $q['choice_bodies_html'] ?? null;
    if (!is_array($choiceBodies) || $choiceBodies === []) {
        $choiceBodies = [];
        $chHtml = $q['choices_html'] ?? [];
        if (is_array($chHtml)) {
            foreach ($chHtml as $h) {
                $h = (string)$h;
                $h = preg_replace('#^<span class="ereview-qsort-choice-letter">[^<]*</span>#u', '', $h, 1);
                $choiceBodies[] = trim($h);
            }
        }
    }
    $cleanChoices = [];
    foreach ($choiceBodies as $html) {
        $html = trim((string)$html);
        if ($html === '') {
            continue;
        }
        $cleanChoices[] = $html;
    }
    if (count($cleanChoices) < 2) {
        return null;
    }
    if (count($cleanChoices) > 10) {
        return null;
    }

    $correct = strtoupper(trim((string)($q['correct_answer_letter'] ?? '')));
    if ($correct === '' || strlen($correct) !== 1) {
        $chHtml = $q['choices_html'] ?? [];
        if (is_array($chHtml)) {
            foreach ($chHtml as $ix => $h) {
                if (preg_match('/ereview-qsort-hl--yellow|data-ereview-hl=[\'"]yellow[\'"]/i', (string)$h)) {
                    $correct = $letters[$ix] ?? '';
                    break;
                }
            }
        }
    }
    if ($correct === '' || !in_array($correct, $letters, true)) {
        return null;
    }
    $idx = array_search($correct, $letters, true);
    if ($idx === false || $idx >= count($cleanChoices)) {
        return null;
    }

    $stemRaw = (string)($q['stem_html_joined'] ?? '');
    $topicForStem = isset($q['topic']) ? trim((string)$q['topic']) : '';
    if ($topicForStem === '') {
        $topicForStem = $topicKey;
    }
    $stemRaw = ereview_qsort_stem_html_for_quiz($stemRaw, $topicForStem !== '' ? $topicForStem : null);
    $stem = sanitizeQuizRichHtmlForStorage($stemRaw);
    if ($stem === '') {
        return null;
    }

    $choicesMap = [];
    foreach ($cleanChoices as $i => $frag) {
        $letter = $letters[$i];
        $stripped = ereview_docx_strip_choice_label_prefix_from_html(trim((string)$frag));
        $choicesMap[$letter] = sanitizeQuizRichHtmlForStorage($stripped);
        if ($choicesMap[$letter] === '') {
            return null;
        }
    }

    return ['stem' => $stem, 'choices' => $choicesMap, 'correct' => $correct];
};

$parseNumCounts = [];
foreach ($questions as $q) {
    if (!is_array($q)) {
        continue;
    }
    $n = trim((string)($q['number'] ?? ''));
    if ($n === '') {
        continue;
    }
    $parseNumCounts[$n] = ($parseNumCounts[$n] ?? 0) + 1;
}
$dupInParse = [];
foreach ($parseNumCounts as $n => $c) {
    if ($c > 1) {
        $dupInParse[] = $n;
    }
}
sort($dupInParse, SORT_NATURAL);
if ($dupInParse !== []) {
    ereview_qsort_deploy_json([
        'ok' => false,
        'error' => 'This topic has duplicate question numbers in the parse: #' . implode(', #', $dupInParse) . '. Fix the document or parser output before deploying.',
        'error_code' => 'DUPLICATE_IN_PARSE',
        'duplicate_numbers' => $dupInParse,
    ], 409);
}

$batch = [];
$batchItemNums = [];
$missing = [];
foreach ($questions as $q) {
    if (!is_array($q)) {
        continue;
    }
    $num = (string)($q['number'] ?? '');
    $row = $normalize($q);
    if ($row === null) {
        $missing[] = $num !== '' ? $num : '?';
        continue;
    }
    $batch[] = $row;
    $batchItemNums[] = trim($num);
}

if ($batch === []) {
    $hint = $missing !== [] ? ' Items with missing yellow answer highlight or fewer than 2 choices: ' . implode(', ', array_slice($missing, 0, 15))
        . (count($missing) > 15 ? '…' : '') . '.' : '';
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'No questions could be deployed. Ensure each item has at least two choices and a yellow-highlighted correct answer.' . $hint], 400);
}

$needsExtendedChoices = false;
foreach ($batch as $br) {
    if (count($br['choices']) > 4) {
        $needsExtendedChoices = true;
        break;
    }
}
$hasQuizTypeCol = in_array('quiz_type', $quizCols, true);

if ($needsExtendedChoices && !in_array('choice_e', $choiceCols, true)) {
    $alterSqls = [
        'ALTER TABLE `quiz_questions` ADD COLUMN `choice_e` text DEFAULT NULL AFTER `choice_d`',
        'ALTER TABLE `quiz_questions` ADD COLUMN `choice_f` text DEFAULT NULL AFTER `choice_e`',
        'ALTER TABLE `quiz_questions` ADD COLUMN `choice_g` text DEFAULT NULL AFTER `choice_f`',
        'ALTER TABLE `quiz_questions` ADD COLUMN `choice_h` text DEFAULT NULL AFTER `choice_g`',
        'ALTER TABLE `quiz_questions` ADD COLUMN `choice_i` text DEFAULT NULL AFTER `choice_h`',
        'ALTER TABLE `quiz_questions` ADD COLUMN `choice_j` text DEFAULT NULL AFTER `choice_i`',
        'ALTER TABLE `quiz_questions` MODIFY `correct_answer` varchar(1) NOT NULL',
        'ALTER TABLE `quiz_answers` MODIFY `selected_answer` varchar(1) DEFAULT NULL',
    ];
    foreach ($alterSqls as $sql) {
        @mysqli_query($conn, $sql);
    }
    $questionColumns = [];
    $qqColRes = @mysqli_query($conn, 'SHOW COLUMNS FROM quiz_questions');
    if ($qqColRes) {
        while ($row = mysqli_fetch_assoc($qqColRes)) {
            $questionColumns[] = $row['Field'];
        }
    }
    $hasExplanation = in_array('explanation', $questionColumns, true);
    $hasChoiceFeedback = in_array('choice_a_feedback', $questionColumns, true);
    $choiceCols = array_values(array_intersect($allChoiceCols, $questionColumns));
}

$quizId = 0;
$resolvedTitle = $title;
$mergedIntoExisting = false;

if ($quizIdExisting > 0) {
    $stmt = mysqli_prepare($conn, 'SELECT quiz_id, subject_id, title, quiz_type FROM quizzes WHERE quiz_id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $quizIdExisting);
    mysqli_stmt_execute($stmt);
    $qrz = mysqli_stmt_get_result($stmt);
    $quizRowExisting = $qrz ? mysqli_fetch_assoc($qrz) : null;
    mysqli_stmt_close($stmt);
    if (!$quizRowExisting) {
        ereview_qsort_deploy_json(['ok' => false, 'error' => 'That quiz was not found.'], 404);
    }
    if ((int)$quizRowExisting['subject_id'] !== $subjectId) {
        ereview_qsort_deploy_json(['ok' => false, 'error' => 'That quiz belongs to a different subject.'], 400);
    }
    if ($hasQuizTypeCol && strtolower((string)($quizRowExisting['quiz_type'] ?? '')) !== 'topical') {
        ereview_qsort_deploy_json(['ok' => false, 'error' => 'Only topical quizzes can be extended from Question sorting. Choose another quiz or create a new one.'], 400);
    }
    $resolvedTitle = trim((string)($quizRowExisting['title'] ?? ''));
    if ($resolvedTitle === '') {
        $resolvedTitle = $title;
    }

    $existingNums = [];
    $stmt = mysqli_prepare($conn, 'SELECT question_text FROM quiz_questions WHERE quiz_id=?');
    mysqli_stmt_bind_param($stmt, 'i', $quizIdExisting);
    mysqli_stmt_execute($stmt);
    $resQq = mysqli_stmt_get_result($stmt);
    if ($resQq) {
        while ($r = mysqli_fetch_assoc($resQq)) {
            $ln = ereview_qsort_lead_item_number_from_stem_html((string)($r['question_text'] ?? ''));
            if ($ln !== null && $ln !== '') {
                $existingNums[$ln] = true;
            }
        }
        mysqli_free_result($resQq);
    }
    mysqli_stmt_close($stmt);

    $collisions = [];
    foreach ($batchItemNums as $bn) {
        if ($bn !== '' && isset($existingNums[$bn])) {
            $collisions[$bn] = true;
        }
    }
    if ($collisions !== []) {
        $clist = array_keys($collisions);
        sort($clist, SORT_NATURAL);
        ereview_qsort_deploy_json([
            'ok' => false,
            'error' => 'Those item numbers are already in the quiz “' . $resolvedTitle . '”: #' . implode(', #', $clist) . '. Choose a different quiz or new title, or remove duplicates from the bank.',
            'error_code' => 'DUPLICATE_IN_QUIZ',
            'duplicate_numbers' => $clist,
            'quiz_title' => $resolvedTitle,
        ], 409);
    }

    $quizId = $quizIdExisting;
    $mergedIntoExisting = true;
}

mysqli_begin_transaction($conn);
try {
    $quizType = 'topical';
    if (!$mergedIntoExisting) {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO quizzes (subject_id, title, quiz_type, time_limit_minutes, time_limit_seconds) VALUES (?, ?, ?, ?, ?)'
        );
        mysqli_stmt_bind_param($stmt, 'issii', $subjectId, $title, $quizType, $timeLimitMinutes, $timeLimitSeconds);
        mysqli_stmt_execute($stmt);
        $quizId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if ($quizId <= 0) {
            throw new RuntimeException('Could not create quiz.');
        }
        $resolvedTitle = $title;
    }

    $insertCols = ['quiz_id', 'question_text'];
    foreach ($choiceCols as $c) {
        $insertCols[] = $c;
    }
    $feedbackCols = [];
    if ($hasChoiceFeedback) {
        foreach ($choiceCols as $c) {
            $fc = $c . '_feedback';
            if (in_array($fc, $questionColumns, true)) {
                $feedbackCols[] = $fc;
            }
        }
        foreach ($feedbackCols as $fc) {
            $insertCols[] = $fc;
        }
    }
    $insertCols[] = 'correct_answer';
    if ($hasExplanation) {
        $insertCols[] = 'explanation';
    }
    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $sql = 'INSERT INTO quiz_questions (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conn) ?: 'Prepare failed');
    }

    foreach ($batch as $row) {
        $dbRow = [
            'question_text' => $row['stem'],
            'correct_answer' => $row['correct'],
        ];
        foreach ($choiceCols as $col) {
            $letter = strtoupper(substr($col, -1));
            $dbRow[$col] = $row['choices'][$letter] ?? '';
        }
        if ($hasChoiceFeedback) {
            foreach ($feedbackCols as $fc) {
                $dbRow[$fc] = '';
            }
        }
        if ($hasExplanation) {
            $dbRow['explanation'] = '';
        }

        $types = 'is';
        $refs = [&$quizId, &$dbRow['question_text']];
        foreach ($choiceCols as $col) {
            $types .= 's';
            $refs[] = &$dbRow[$col];
        }
        foreach ($feedbackCols as $fc) {
            $types .= 's';
            $refs[] = &$dbRow[$fc];
        }
        $types .= 's';
        $refs[] = &$dbRow['correct_answer'];
        if ($hasExplanation) {
            $types .= 's';
            $refs[] = &$dbRow['explanation'];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
        mysqli_stmt_execute($stmt);
    }
    mysqli_stmt_close($stmt);

    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    ereview_qsort_deploy_json(['ok' => false, 'error' => 'Database error while deploying.'], 500);
}

// Same-directory URLs so redirects work from app roots like /Ereview/
$qqUrl = 'admin_quiz_questions.php?quiz_id=' . $quizId . '&subject_id=' . $subjectId;
$qzUrl = 'admin_quizzes.php?subject_id=' . $subjectId;

if ($mergedIntoExisting) {
    $_SESSION['message'] = count($batch) . ' new question(s) appended to existing quiz "' . $resolvedTitle . '" (' . h($subject['subject_name']) . ').';
} else {
    $_SESSION['message'] = count($batch) . ' question(s) added to quiz "' . $resolvedTitle . '" under ' . h($subject['subject_name']) . '.';
}
if (count($missing) > 0) {
    $_SESSION['message'] .= ' Some items were skipped (#' . implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? '…' : '') . ').';
}
if (isset($_SESSION['error'])) {
    unset($_SESSION['error']);
}

ereview_qsort_deploy_json([
    'ok' => true,
    'quiz_id' => $quizId,
    'subject_id' => $subjectId,
    'questions_saved' => count($batch),
    'skipped_count' => count($missing),
    'skipped_numbers' => $missing,
    'merged_into_existing' => $mergedIntoExisting,
    'redirect_questions' => $qqUrl,
    'redirect_quizzes' => $qzUrl,
    'message' => $mergedIntoExisting
        ? ('Appended ' . count($batch) . ' question(s) to “' . $resolvedTitle . '” (' . h($subject['subject_name']) . ').')
        : ('Deployed ' . count($batch) . ' question(s) as “' . $resolvedTitle . '” (' . h($subject['subject_name']) . ').'),
]);
