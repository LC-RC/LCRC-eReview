<?php
/**
 * CPA Battle JSON API — server-authoritative multiplayer state.
 */
require_once 'auth.php';
require_once __DIR__ . '/includes/student_playground_battle.php';
require_once __DIR__ . '/includes/student_cpa_review.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || getCurrentUserRole() !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

student_playground_battle_ensure_schema($conn);
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
$mutations = [
    'nick_set', 'create', 'join', 'ready', 'unready', 'leave', 'kick',
    'cancel', 'start', 'answer', 'mistake_add',
];
if (in_array($action, $mutations, true)) {
    if ($token === '' || !verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
        exit;
    }
}

switch ($action) {
    case 'nick_set':
        echo json_encode(student_playground_battle_nick_set((string) ($payload['nickname'] ?? '')));
        exit;

    case 'nick_get':
        echo json_encode(['ok' => true, 'nickname' => student_playground_battle_nick_get()]);
        exit;

    case 'create':
        echo json_encode(student_playground_battle_create($conn, $userId, $payload));
        exit;

    case 'join':
        echo json_encode(student_playground_battle_join(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? ''),
            (string) ($payload['nickname'] ?? '')
        ));
        exit;

    case 'ready':
        echo json_encode(student_playground_battle_set_ready(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? ''),
            true
        ));
        exit;

    case 'unready':
        echo json_encode(student_playground_battle_set_ready(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? ''),
            false
        ));
        exit;

    case 'leave':
        echo json_encode(student_playground_battle_leave(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? '')
        ));
        exit;

    case 'kick':
        echo json_encode(student_playground_battle_kick(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? ''),
            (string) ($payload['nickname'] ?? '')
        ));
        exit;

    case 'cancel':
        echo json_encode(student_playground_battle_cancel(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? '')
        ));
        exit;

    case 'start':
        echo json_encode(student_playground_battle_start(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? '')
        ));
        exit;

    case 'answer':
        echo json_encode(student_playground_battle_answer(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? ''),
            (int) ($payload['game_question_id'] ?? 0),
            (string) ($payload['selected_answer'] ?? '')
        ));
        exit;

    case 'state':
        echo json_encode(student_playground_battle_state(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? $_GET['room_code'] ?? '')
        ));
        exit;

    case 'results':
        echo json_encode(student_playground_battle_results(
            $conn,
            $userId,
            (string) ($payload['room_code'] ?? $_GET['room_code'] ?? '')
        ));
        exit;

    case 'subjects':
        echo json_encode(['ok' => true, 'data' => student_playground_subjects_with_counts($conn, $userId)]);
        exit;

    case 'mistake_add':
        student_cpa_review_ensure_schema($conn);
        echo json_encode(student_cpa_mistake_add($conn, $userId, [
            'question_id' => (int) ($payload['question_id'] ?? 0),
            'quiz_id' => (int) ($payload['quiz_id'] ?? 0),
            'subject_id' => (int) ($payload['subject_id'] ?? 0),
            'attempt_id' => 0,
            'selected_answer' => (string) ($payload['selected_answer'] ?? ''),
            'correct_answer' => (string) ($payload['correct_answer'] ?? ''),
            'explanation' => (string) ($payload['explanation'] ?? ''),
        ]));
        exit;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
}
