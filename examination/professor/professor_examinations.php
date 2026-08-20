<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/examination_domain.php';

$pageTitle = 'Examinations';
$uid = (int)getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCSRFToken($token)) {
        $_SESSION['examination_flash_error'] = 'Invalid security token. Please try again.';
        header('Location: professor_examinations');
        exit;
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $redirectQs = http_build_query(array_filter([
        'status' => (($s = strtolower(trim((string)($_POST['return_status'] ?? '')))) !== '' && $s !== 'all') ? $s : null,
        'exam_type' => examination_normalize_exam_type((string)($_POST['return_exam_type'] ?? '')) ?: null,
        'examinee_type' => (($e = trim((string)($_POST['return_examinee_type'] ?? ''))) !== '') ? $e : null,
        'q' => (($q = trim((string)($_POST['return_q'] ?? ''))) !== '') ? $q : null,
    ]));
    $redirect = 'professor_examinations' . ($redirectQs !== '' ? '?' . $redirectQs : '');

    if ($action === 'delete') {
        $examType = examination_normalize_exam_type((string)($_POST['exam_type'] ?? ''));
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $result = examination_domain_delete($conn, $examType, $sourceId, $uid);
        if (!empty($result['ok'])) {
            $title = trim((string)($result['title'] ?? ''));
            $_SESSION['examination_flash'] = $title !== ''
                ? ('Deleted examination "' . $title . '".')
                : 'Examination deleted.';
        } else {
            $_SESSION['examination_flash_error'] = (string)($result['error'] ?? 'Could not delete examination.');
        }
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'duplicate') {
        $examType = examination_normalize_exam_type((string)($_POST['exam_type'] ?? ''));
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $result = examination_domain_duplicate($conn, $examType, $sourceId, $uid);
        if (!empty($result['ok'])) {
            $newId = (int)($result['source_id'] ?? 0);
            $newType = examination_normalize_exam_type((string)($result['exam_type'] ?? $examType));
            $title = trim((string)($result['title'] ?? ''));
            $qCount = (int)($result['question_count'] ?? 0);
            $_SESSION['examination_flash'] = $title !== ''
                ? ('Duplicated "' . $title . '"' . ($qCount > 0 ? (' with ' . $qCount . ' question' . ($qCount === 1 ? '' : 's')) : '') . '.')
                : 'Examination duplicated.';
            $editUrl = examination_domain_edit_url($newType, $newId, 'config') . '&modal=1';
            $join = $redirectQs !== '' ? '&' : '?';
            header('Location: ' . $redirect . $join . 'open_edit=' . rawurlencode($editUrl));
            exit;
        }
        $_SESSION['examination_flash_error'] = (string)($result['error'] ?? 'Could not duplicate examination.');
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'bulk_delete') {
        $keys = $_POST['exam_keys'] ?? [];
        if (!is_array($keys)) {
            $keys = [];
        }
        $keys = array_values(array_filter(array_map(static fn($k) => trim((string)$k), $keys), static fn($k) => $k !== ''));
        if ($keys === []) {
            $_SESSION['examination_flash_error'] = 'No examinations selected.';
            header('Location: ' . $redirect);
            exit;
        }

        $result = examination_domain_delete_many($conn, $keys, $uid);
        $deleted = (int)($result['deleted'] ?? 0);
        $skipped = (int)($result['skipped'] ?? 0);
        if ($deleted > 0 && $skipped === 0) {
            $_SESSION['examination_flash'] = $deleted === 1
                ? 'Deleted 1 examination.'
                : ('Deleted ' . $deleted . ' examinations.');
        } elseif ($deleted > 0) {
            $_SESSION['examination_flash'] = 'Deleted ' . $deleted . ' examination(s). ' . $skipped . ' could not be deleted.';
            $errs = array_slice((array)($result['errors'] ?? []), 0, 3);
            if ($errs !== []) {
                $_SESSION['examination_flash_error'] = implode(' ', $errs);
            }
        } else {
            $errs = array_slice((array)($result['errors'] ?? []), 0, 3);
            $_SESSION['examination_flash_error'] = $errs !== []
                ? implode(' ', $errs)
                : 'Could not delete the selected examinations.';
        }
        header('Location: ' . $redirect);
        exit;
    }

    $_SESSION['examination_flash_error'] = 'Unknown action.';
    header('Location: ' . $redirect);
    exit;
}

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'draft', 'published', 'finished'], true)) {
    $statusFilter = 'all';
}

$typeFilter = examination_normalize_exam_type((string)($_GET['exam_type'] ?? ''));
$examineeFilter = trim((string)($_GET['examinee_type'] ?? ''));
if (!in_array($examineeFilter, ['', 'college_student', 'reviewee', 'both'], true)) {
    $examineeFilter = '';
}
$searchQ = trim((string)($_GET['q'] ?? ''));

$filters = [
    'status' => $statusFilter,
    'exam_type' => $typeFilter,
    'examinee_type' => $examineeFilter,
    'q' => $searchQ,
];

$examinations = examination_domain_list($conn, $uid, $filters);
$counts = examination_domain_list_counts($conn, $uid, [
    'exam_type' => $typeFilter,
    'examinee_type' => $examineeFilter,
    'q' => $searchQ,
]);

$flashMessage = $_SESSION['examination_flash'] ?? null;
unset($_SESSION['examination_flash']);
$flashError = $_SESSION['examination_flash_error'] ?? null;
unset($_SESSION['examination_flash_error']);
$openEditUrl = trim((string)($_GET['open_edit'] ?? ''));
$csrfToken = generateCSRFToken();

require dirname(__DIR__) . '/includes/examination_list_view.php';
