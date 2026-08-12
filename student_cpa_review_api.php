<?php
/**
 * My CPA Review JSON API (student-only, CSRF on mutations).
 */
require_once 'auth.php';
require_once __DIR__ . '/includes/student_cpa_review.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || getCurrentUserRole() !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

student_cpa_review_ensure_schema($conn);
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
    'note_save', 'note_delete', 'bookmark_toggle', 'favorite_toggle', 'important_toggle',
    'concept_save', 'concept_delete', 'concept_last_minute',
    'mistake_add', 'mistake_update', 'mistake_reviewed', 'mistake_delete',
    'quick_save', 'quick_delete',
];
if (in_array($action, $mutations, true)) {
    if ($token === '' || !function_exists('verifyCSRFToken') || !verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
        exit;
    }
}

switch ($action) {
    case 'note_save':
        $result = student_cpa_note_save($conn, $userId, $payload);
        echo json_encode($result + ['data' => ['note_id' => $result['note_id'] ?? null]]);
        exit;

    case 'note_delete':
        $noteId = (int) ($payload['note_id'] ?? 0);
        $ok = student_cpa_note_delete($conn, $userId, $noteId);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Note not found.']);
        exit;

    case 'note_get':
        $noteId = (int) ($payload['note_id'] ?? $_GET['note_id'] ?? 0);
        $row = student_cpa_note_get($conn, $userId, $noteId);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            exit;
        }
        echo json_encode(['ok' => true, 'data' => $row]);
        exit;

    case 'bookmark_toggle':
        echo json_encode(student_cpa_bookmark_toggle($conn, $userId, $payload));
        exit;

    case 'favorite_toggle':
        echo json_encode(student_cpa_favorite_toggle($conn, $userId, $payload));
        exit;

    case 'important_toggle':
        echo json_encode(student_cpa_important_toggle($conn, $userId, $payload));
        exit;

    case 'concept_save':
        $result = student_cpa_concept_save($conn, $userId, $payload);
        echo json_encode($result + ['data' => ['important_id' => $result['important_id'] ?? null]]);
        exit;

    case 'concept_delete':
        $importantId = (int) ($payload['important_id'] ?? 0);
        $ok = student_cpa_concept_delete($conn, $userId, $importantId);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Not found.']);
        exit;

    case 'concept_last_minute':
        $importantId = (int) ($payload['important_id'] ?? 0);
        $on = !empty($payload['is_last_minute']);
        echo json_encode(student_cpa_concept_set_last_minute($conn, $userId, $importantId, $on));
        exit;

    case 'mistake_add':
        echo json_encode(student_cpa_mistake_add($conn, $userId, $payload));
        exit;

    case 'mistake_update':
        $mistakeId = (int) ($payload['mistake_id'] ?? 0);
        echo json_encode(student_cpa_mistake_update($conn, $userId, $mistakeId, $payload));
        exit;

    case 'mistake_reviewed':
        $mistakeId = (int) ($payload['mistake_id'] ?? 0);
        $payload['is_reviewed'] = !empty($payload['is_reviewed']) ? 1 : 0;
        if (!array_key_exists('personal_note', $payload)) {
            $existing = student_cpa_mistake_get($conn, $userId, $mistakeId);
            $payload['personal_note'] = $existing['personal_note'] ?? '';
        }
        echo json_encode(student_cpa_mistake_update($conn, $userId, $mistakeId, $payload));
        exit;

    case 'mistake_delete':
        $mistakeId = (int) ($payload['mistake_id'] ?? 0);
        $ok = student_cpa_mistake_delete($conn, $userId, $mistakeId);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Not found.']);
        exit;

    case 'quick_save':
        $result = student_cpa_quick_save($conn, $userId, $payload);
        echo json_encode($result + ['data' => ['quick_id' => $result['quick_id'] ?? null]]);
        exit;

    case 'quick_delete':
        $quickId = (int) ($payload['quick_id'] ?? 0);
        $ok = student_cpa_quick_delete($conn, $userId, $quickId);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Not found.']);
        exit;

    case 'status':
        echo json_encode([
            'ok' => true,
            'data' => [
                'bookmarked' => student_cpa_bookmark_has(
                    $conn,
                    $userId,
                    (string) ($payload['item_type'] ?? $_GET['item_type'] ?? ''),
                    (int) ($payload['item_id'] ?? $_GET['item_id'] ?? 0)
                ),
                'favorited' => student_cpa_favorite_has(
                    $conn,
                    $userId,
                    (string) ($payload['fav_type'] ?? $_GET['fav_type'] ?? $payload['item_type'] ?? $_GET['item_type'] ?? ''),
                    (int) ($payload['fav_id'] ?? $_GET['fav_id'] ?? $payload['item_id'] ?? $_GET['item_id'] ?? 0)
                ),
            ],
        ]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
}
