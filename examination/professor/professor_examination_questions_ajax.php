<?php
declare(strict_types=1);

/**
 * AJAX mutations for professor examination questions (rapid-entry autosave + list refresh).
 * Reuses examination_questions_* helpers — same CSRF, ownership, and lock rules as POST forms.
 */

require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_domain.php';
require_once dirname(__DIR__) . '/includes/examination_questions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

/**
 * @param array<string,mixed> $payload
 */
function examination_questions_ajax_json(array $payload, int $http = 200): never
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    examination_questions_ajax_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$raw = file_get_contents('php://input');
$jsonBody = [];
if (is_string($raw) && $raw !== '' && str_starts_with(ltrim($raw), '{')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$src = $jsonBody !== [] ? $jsonBody : $_POST;
$csrf = (string)($src['csrf_token'] ?? '');
if (!verifyCSRFToken($csrf)) {
    examination_questions_ajax_json(['ok' => false, 'error' => 'Invalid security token. Please reload the page.'], 403);
}

$uid = (int)getCurrentUserId();
$action = strtolower(trim((string)($src['action'] ?? '')));
$examType = examination_normalize_exam_type((string)($src['exam_type'] ?? ''));
if ($examType === '') {
    examination_questions_ajax_json(['ok' => false, 'error' => 'Invalid examination type.']);
}

$sourceId = $examType === 'diagnostic'
    ? (int)($src['batch_id'] ?? $src['source_id'] ?? 0)
    : (int)($src['exam_id'] ?? $src['source_id'] ?? 0);

if ($sourceId <= 0) {
    examination_questions_ajax_json(['ok' => false, 'error' => 'Examination not found.']);
}

$existing = examination_domain_load($conn, $examType, $sourceId, $uid);
if (!$existing) {
    examination_questions_ajax_json(['ok' => false, 'error' => 'Examination not found.'], 404);
}

$clientRev = (int)($src['client_rev'] ?? 0);

if ($action === 'list_questions') {
    $subjectId = (int)($src['subject_id'] ?? 0);
    $rows = examination_questions_ajax_list_rows($conn, $examType, $sourceId, $subjectId);
    examination_questions_ajax_json([
        'ok' => true,
        'exam_type' => $examType,
        'source_id' => $sourceId,
        'subject_id' => $subjectId,
        'count' => count($rows),
        'next_number' => count($rows) + 1,
        'questions' => $rows,
        'client_rev' => $clientRev,
    ]);
}

if ($action === 'save_question') {
    $qid = (int)($src['question_id'] ?? 0);
    $data = [
        'question_type' => (string)($src['question_type'] ?? 'mcq'),
        'question_text' => (string)($src['question_text'] ?? ''),
        'choice_a' => (string)($src['choice_a'] ?? ''),
        'choice_b' => (string)($src['choice_b'] ?? ''),
        'choice_c' => (string)($src['choice_c'] ?? ''),
        'choice_d' => (string)($src['choice_d'] ?? ''),
        'correct_answer' => (string)($src['correct_answer'] ?? ''),
        'extra_choices' => $src['extra_choices'] ?? null,
    ];

    if ($examType === 'regular') {
        $res = examination_questions_regular_save_one($conn, $sourceId, $uid, $qid, $data);
    } else {
        $subjectId = (int)($src['subject_id'] ?? 0);
        if ($subjectId <= 0) {
            examination_questions_ajax_json(['ok' => false, 'error' => 'Please select a subject before saving diagnostic questions.', 'client_rev' => $clientRev]);
        }
        $res = examination_questions_diagnostic_save_one($conn, $sourceId, $uid, $subjectId, $qid, $data);
    }

    if (empty($res['ok'])) {
        examination_questions_ajax_json([
            'ok' => false,
            'error' => (string)($res['error'] ?? 'Could not save question.'),
            'client_rev' => $clientRev,
            'incomplete' => true,
        ]);
    }

    $newId = (int)($res['question_id'] ?? 0);
    $subjectId = (int)($src['subject_id'] ?? 0);
    $rows = examination_questions_ajax_list_rows($conn, $examType, $sourceId, $subjectId);
    $saved = null;
    $displayNum = 0;
    foreach ($rows as $i => $row) {
        if ((int)($row['question_id'] ?? 0) === $newId) {
            $saved = $row;
            $displayNum = $i + 1;
            break;
        }
    }

    examination_questions_ajax_json([
        'ok' => true,
        'question_id' => $newId,
        'display_number' => $displayNum,
        'question' => $saved,
        'count' => count($rows),
        'next_number' => count($rows) + 1,
        'client_rev' => $clientRev,
        'created' => $qid <= 0,
    ]);
}

if ($action === 'delete_question') {
    $qid = (int)($src['question_id'] ?? 0);
    if ($qid <= 0) {
        examination_questions_ajax_json(['ok' => false, 'error' => 'Question not found.', 'client_rev' => $clientRev]);
    }
    if ($examType === 'regular') {
        $res = examination_questions_regular_delete_one($conn, $sourceId, $uid, $qid);
    } else {
        $res = examination_questions_diagnostic_delete_one($conn, $sourceId, $uid, $qid);
    }
    if (empty($res['ok'])) {
        examination_questions_ajax_json([
            'ok' => false,
            'error' => (string)($res['error'] ?? 'Could not delete question.'),
            'client_rev' => $clientRev,
        ]);
    }
    $subjectId = (int)($src['subject_id'] ?? 0);
    $rows = examination_questions_ajax_list_rows($conn, $examType, $sourceId, $subjectId);
    examination_questions_ajax_json([
        'ok' => true,
        'deleted_id' => $qid,
        'count' => count($rows),
        'next_number' => count($rows) + 1,
        'questions' => $rows,
        'client_rev' => $clientRev,
    ]);
}

examination_questions_ajax_json(['ok' => false, 'error' => 'Unknown action.'], 400);

/**
 * @return list<array{question_id:int,question_type:string,question_text:string,preview:string,choice_a:string,choice_b:string,choice_c:string,choice_d:string,correct_answer:string,type_label:string,answer_label:string,display_number:int}>
 */
function examination_questions_ajax_list_rows(mysqli $conn, string $examType, int $sourceId, int $subjectId = 0): array
{
    $raw = [];
    if ($examType === 'regular') {
        $raw = examination_questions_load_regular($conn, $sourceId);
    } elseif ($subjectId > 0) {
        $supply = examination_questions_diagnostic_supply($conn, $sourceId);
        foreach (($supply['subjects'] ?? []) as $sub) {
            if ((int)($sub['subject_id'] ?? 0) === $subjectId) {
                $raw = $sub['questions'] ?? [];
                break;
            }
        }
    }

    $out = [];
    foreach ($raw as $i => $q) {
        if (!is_array($q)) {
            continue;
        }
        $type = strtolower((string)($q['question_type'] ?? 'mcq')) === 'tf' ? 'tf' : 'mcq';
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($q['question_text'] ?? ''))) ?? '');
        $preview = $plain;
        if (function_exists('mb_strlen') && mb_strlen($preview) > 140) {
            $preview = mb_substr($preview, 0, 137) . '…';
        } elseif (strlen($preview) > 140) {
            $preview = substr($preview, 0, 137) . '…';
        }
        $ans = strtoupper((string)($q['correct_answer'] ?? ''));
        $ansLabel = $ans !== '' ? $ans : '—';
        if ($type === 'tf') {
            $ansLabel = $ans === 'A' ? 'True' : ($ans === 'B' ? 'False' : $ansLabel);
        }
        $extra = examination_questions_diagnostic_extra_choices_decode(
            isset($q['extra_choices_json']) ? (string)$q['extra_choices_json'] : null
        );
        $out[] = [
            'question_id' => (int)($q['question_id'] ?? 0),
            'question_type' => $type,
            'question_text' => (string)($q['question_text'] ?? ''),
            'preview' => $preview !== '' ? $preview : '—',
            'choice_a' => (string)($q['choice_a'] ?? ''),
            'choice_b' => (string)($q['choice_b'] ?? ''),
            'choice_c' => (string)($q['choice_c'] ?? ''),
            'choice_d' => (string)($q['choice_d'] ?? ''),
            'extra_choices' => $extra,
            'correct_answer' => $ans,
            'type_label' => $type === 'tf' ? 'True/False' : 'Multiple',
            'answer_label' => $ansLabel,
            'display_number' => $i + 1,
        ];
    }

    return $out;
}
