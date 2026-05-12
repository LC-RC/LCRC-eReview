<?php
/**
 * List topical quizzes for a subject (admin) — used by Question sorting deploy modal.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');

function ereview_qsort_quiz_opts_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ereview_qsort_quiz_opts_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if (!isLoggedIn() || ($_SESSION['role'] ?? '') !== 'admin') {
    ereview_qsort_quiz_opts_json(['ok' => false, 'error' => 'Unauthorized.'], 403);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($token)) {
    ereview_qsort_quiz_opts_json(['ok' => false, 'error' => 'Invalid security token.'], 419);
}

$subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
if ($subjectId <= 0) {
    ereview_qsort_quiz_opts_json(['ok' => false, 'error' => 'Invalid subject.'], 400);
}

$stmt = mysqli_prepare($conn, 'SELECT subject_id FROM subjects WHERE subject_id=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$resSub = mysqli_stmt_get_result($stmt);
$ok = $resSub && mysqli_fetch_assoc($resSub) !== null;
if ($resSub) {
    mysqli_free_result($resSub);
}
mysqli_stmt_close($stmt);
if (!$ok) {
    ereview_qsort_quiz_opts_json(['ok' => false, 'error' => 'Subject not found.'], 400);
}

$quizCols = [];
$qc = @mysqli_query($conn, 'SHOW COLUMNS FROM quizzes');
if ($qc) {
    while ($row = mysqli_fetch_assoc($qc)) {
        $quizCols[] = $row['Field'];
    }
}
$hasQuizType = in_array('quiz_type', $quizCols, true);

if ($hasQuizType) {
    $sql = 'SELECT q.quiz_id, q.title, COUNT(qq.question_id) AS question_count
            FROM quizzes q
            LEFT JOIN quiz_questions qq ON qq.quiz_id = q.quiz_id
            WHERE q.subject_id = ? AND q.quiz_type = \'topical\'
            GROUP BY q.quiz_id, q.title
            ORDER BY q.title ASC';
} else {
    $sql = 'SELECT q.quiz_id, q.title, COUNT(qq.question_id) AS question_count
            FROM quizzes q
            LEFT JOIN quiz_questions qq ON qq.quiz_id = q.quiz_id
            WHERE q.subject_id = ?
            GROUP BY q.quiz_id, q.title
            ORDER BY q.title ASC';
}

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$quizzes = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $quizzes[] = [
            'quiz_id' => (int)$row['quiz_id'],
            'title' => (string)($row['title'] ?? ''),
            'question_count' => (int)($row['question_count'] ?? 0),
        ];
    }
    mysqli_free_result($res);
}
mysqli_stmt_close($stmt);

ereview_qsort_quiz_opts_json(['ok' => true, 'quizzes' => $quizzes, 'quiz_type_filter' => $hasQuizType ? 'topical' : 'all']);
