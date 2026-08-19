<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/examination_domain.php';

$pageTitle = 'Examinations';
$uid = (int)getCurrentUserId();

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

require dirname(__DIR__) . '/includes/examination_list_view.php';
