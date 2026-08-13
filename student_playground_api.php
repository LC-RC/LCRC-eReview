<?php
/**
 * CPA Playground JSON API — server-side grading; never exposes correct answers before submit.
 */
require_once 'auth.php';
require_once __DIR__ . '/includes/student_playground.php';
require_once __DIR__ . '/includes/student_cpa_review.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || getCurrentUserRole() !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

student_playground_enforce_enabled_api($conn);
student_playground_ensure_schema($conn);
sca_ensure_schema($conn);
$userId = (int) getCurrentUserId();

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === []) {
    $payload = $_POST;
}

$action = (string) ($payload['action'] ?? $_GET['action'] ?? '');
$token = (string) ($payload['csrf_token'] ?? '');
$mutations = ['start', 'answer', 'finish', 'goto', 'mistake_add'];
if (in_array($action, $mutations, true)) {
    if ($token === '' || !verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
        exit;
    }
}

switch ($action) {
    case 'start':
        echo json_encode(student_playground_start($conn, $userId, $payload));
        exit;

    case 'current':
        $sessionId = (int) ($payload['session_id'] ?? $_GET['session_id'] ?? 0);
        echo json_encode(student_playground_get_current_question($conn, $userId, $sessionId));
        exit;

    case 'goto':
        $sessionId = (int) ($payload['session_id'] ?? 0);
        $ordinal = (int) ($payload['ordinal'] ?? 0);
        echo json_encode(student_playground_get_question($conn, $userId, $sessionId, $ordinal));
        exit;

    case 'answer':
        $sessionId = (int) ($payload['session_id'] ?? 0);
        $questionId = (int) ($payload['question_id'] ?? 0);
        $selected = (string) ($payload['selected_answer'] ?? '');
        $clientMs = (int) ($payload['response_ms'] ?? 0);
        echo json_encode(
            student_playground_submit_answer($conn, $userId, $sessionId, $questionId, $selected, $clientMs, false)
        );
        exit;

    case 'finish':
        $sessionId = (int) ($payload['session_id'] ?? 0);
        echo json_encode(student_playground_finish($conn, $userId, $sessionId));
        exit;

    case 'results':
        $sessionId = (int) ($payload['session_id'] ?? $_GET['session_id'] ?? 0);
        $session = student_playground_session_get($conn, $userId, $sessionId);
        if (!$session) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            exit;
        }
        echo json_encode(['ok' => true, 'data' => student_playground_results($conn, $userId, $sessionId)]);
        exit;

    case 'daily_status':
        echo json_encode(['ok' => true, 'data' => student_playground_daily_status($conn, $userId)]);
        exit;

    case 'subjects':
        echo json_encode(['ok' => true, 'data' => student_playground_subjects_with_counts($conn, $userId)]);
        exit;

    case 'recommended_time':
        $qc = (int) ($payload['question_count'] ?? $_GET['question_count'] ?? 10);
        $sec = student_playground_recommended_total_seconds($qc);
        echo json_encode([
            'ok' => true,
            'seconds' => $sec,
            'minutes' => (int) max(1, (int) round($sec / 60)),
            'presets' => student_playground_allowed_time_minutes(),
            'presets_by_unit' => student_playground_time_presets_by_unit(),
            'min_seconds' => STUDENT_PLAYGROUND_MIN_TOTAL_SECONDS,
            'max_seconds' => STUDENT_PLAYGROUND_MAX_TOTAL_SECONDS,
        ]);
        exit;

    case 'mistake_add':
        student_cpa_review_ensure_schema($conn);
        $result = student_cpa_mistake_add($conn, $userId, [
            'question_id' => (int) ($payload['question_id'] ?? 0),
            'quiz_id' => (int) ($payload['quiz_id'] ?? 0),
            'subject_id' => (int) ($payload['subject_id'] ?? 0),
            'attempt_id' => 0,
            'selected_answer' => (string) ($payload['selected_answer'] ?? ''),
            'correct_answer' => (string) ($payload['correct_answer'] ?? ''),
            'explanation' => (string) ($payload['explanation'] ?? ''),
        ]);
        echo json_encode($result);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
}
