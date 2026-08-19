<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_domain.php';
require_once dirname(__DIR__) . '/includes/examination_questions.php';

$pageTitle = 'Examination';
$uid = (int)getCurrentUserId();
$csrf = generateCSRFToken();
$error = null;

$examType = examination_domain_resolve_type_from_request($_GET);
if ($examType === '' && isset($_GET['exam_type'])) {
    $examType = examination_normalize_exam_type((string)$_GET['exam_type']);
}
if ($examType === '') {
    $examType = 'regular';
}

$sourceId = examination_domain_source_id_from_request($_GET, $examType);
$isNew = $sourceId <= 0;

$step = strtolower(trim((string)($_GET['step'] ?? 'config')));
if (!in_array($step, ['config', 'questions', 'review'], true)) {
    $step = 'config';
}

$modalMode = !empty($_GET['modal']) || !empty($_POST['modal']);

if (!$isNew) {
    $existing = examination_domain_load($conn, $examType, $sourceId, $uid);
    if (!$existing) {
        $_SESSION['examination_flash_error'] = 'Examination not found.';
        header('Location: professor_examinations');
        exit;
    }
    $examType = (string)$existing['exam_type'];
}

/**
 * @return never
 */
function examination_edit_redirect_questions(string $examType, int $sourceId, ?int $subjectId = null, array $extraQuery = []): void
{
    $url = examination_domain_edit_url($examType, $sourceId, 'questions');
    if ($subjectId !== null && $subjectId > 0) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'subject_id=' . (int)$subjectId;
    }
    foreach ($extraQuery as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $url .= (str_contains($url, '?') ? '&' : '?') . rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    header('Location: ' . $url);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $postType = examination_normalize_exam_type((string)($_POST['exam_type'] ?? $examType));
        $postId = examination_domain_source_id_from_request($_POST, $postType);

        if ($postType === '') {
            $error = 'Invalid examination type.';
        } elseif ($action === 'save_config' && in_array($step, ['config', 'review'], true)) {
            $payload = $_POST;
            if ($step === 'review' && $postId > 0) {
                $payload = examination_domain_build_config_post_from_record($conn, $postType, $postId, $uid, $_POST);
            }
            $saveAction = strtolower(trim((string)($_POST['save_action'] ?? 'draft')));
            if ($saveAction === 'publish' && $postId > 0) {
                $qCheck = examination_questions_validate_for_publish($conn, $postType, $postId);
                if (empty($qCheck['ok'])) {
                    $error = (string)($qCheck['error'] ?? 'Questions are incomplete.');
                    if (!empty($qCheck['details']) && is_array($qCheck['details']) && count($qCheck['details']) > 1) {
                        $error .= ' ' . implode(' ', array_slice($qCheck['details'], 1));
                    }
                    $examType = $postType;
                    $sourceId = $postId;
                }
            }
            if ($error === null) {
                $result = examination_domain_save_config($conn, $postType, $uid, $payload, $postId);
                if (!empty($result['ok'])) {
                    $_SESSION['examination_flash'] = !empty($result['is_published'])
                        ? 'Examination published.'
                        : 'Examination configuration saved as draft.';
                    if ($modalMode) {
                        header('Location: professor_examinations');
                        exit;
                    }
                    $redirectStep = $step === 'review' ? 'review' : 'config';
                    header('Location: ' . examination_domain_edit_url($postType, (int)$result['source_id'], $redirectStep));
                    exit;
                }
                $error = (string)($result['error'] ?? 'Could not save configuration.');
                $examType = $postType;
                $sourceId = $postId;
            }
        } elseif ($step === 'questions' && $postId > 0) {
            $examType = $postType;
            $sourceId = $postId;
            $focusSubject = (int)($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);

            if ($action === 'save_question' && $postType === 'regular') {
                $qid = (int)($_POST['question_id'] ?? 0);
                $res = examination_questions_regular_save_one($conn, $postId, $uid, $qid, $_POST);
                if (!empty($res['ok'])) {
                    $continue = !empty($_POST['save_and_continue']);
                    $_SESSION['examination_flash'] = $qid > 0
                        ? 'Question updated.'
                        : ($continue ? 'Question saved. Creating the next one…' : 'Question added.');
                    examination_edit_redirect_questions($postType, $postId, null, $continue ? ['focus' => 'builder'] : []);
                }
                $error = (string)($res['error'] ?? 'Could not save question.');
            } elseif ($action === 'duplicate_question' && $postType === 'regular') {
                $res = examination_questions_regular_duplicate_one($conn, $postId, $uid, (int)($_POST['question_id'] ?? 0));
                if (!empty($res['ok'])) {
                    $_SESSION['examination_flash'] = 'Question duplicated.';
                    examination_edit_redirect_questions($postType, $postId, null, [
                        'edit' => (string)(int)($res['question_id'] ?? 0),
                    ]);
                }
                $error = (string)($res['error'] ?? 'Could not duplicate question.');
            } elseif ($action === 'delete_question' && $postType === 'regular') {
                $res = examination_questions_regular_delete_one($conn, $postId, $uid, (int)($_POST['question_id'] ?? 0));
                if (!empty($res['ok'])) {
                    $_SESSION['examination_flash'] = 'Question deleted.';
                    examination_edit_redirect_questions($postType, $postId);
                }
                $error = (string)($res['error'] ?? 'Could not delete question.');
            } elseif ($action === 'import_questions' && $postType === 'regular') {
                $raw = $_POST['import_json'] ?? '';
                $rows = json_decode((string)$raw, true);
                if (!is_array($rows)) {
                    $error = 'Import data is invalid.';
                } else {
                    $res = examination_questions_regular_import_append($conn, $postId, $uid, $rows);
                    if (!empty($res['ok'])) {
                        $_SESSION['examination_flash'] = 'Imported ' . (int)($res['imported'] ?? 0) . ' question(s).';
                        examination_edit_redirect_questions($postType, $postId);
                    }
                    $error = (string)($res['error'] ?? 'Import failed.');
                }
            } elseif ($action === 'save_question' && $postType === 'diagnostic') {
                $qid = (int)($_POST['question_id'] ?? 0);
                $sid = (int)($_POST['subject_id'] ?? 0);
                $res = examination_questions_diagnostic_save_one($conn, $postId, $uid, $sid, $qid, $_POST);
                if (!empty($res['ok'])) {
                    $continue = !empty($_POST['save_and_continue']);
                    $_SESSION['examination_flash'] = $qid > 0
                        ? 'Question updated.'
                        : ($continue ? 'Question saved. Creating the next one…' : 'Question added.');
                    examination_edit_redirect_questions($postType, $postId, $sid, $continue ? ['focus' => 'builder'] : []);
                }
                $error = (string)($res['error'] ?? 'Could not save question.');
            } elseif ($action === 'duplicate_question' && $postType === 'diagnostic') {
                $sid = (int)($_POST['subject_id'] ?? 0);
                $res = examination_questions_diagnostic_duplicate_one($conn, $postId, $uid, $sid, (int)($_POST['question_id'] ?? 0));
                if (!empty($res['ok'])) {
                    $_SESSION['examination_flash'] = 'Question duplicated.';
                    examination_edit_redirect_questions($postType, $postId, $sid, [
                        'edit' => (string)(int)($res['question_id'] ?? 0),
                    ]);
                }
                $error = (string)($res['error'] ?? 'Could not duplicate question.');
            } elseif ($action === 'delete_question' && $postType === 'diagnostic') {
                $sid = (int)($_POST['subject_id'] ?? 0);
                $res = examination_questions_diagnostic_delete_one($conn, $postId, $uid, (int)($_POST['question_id'] ?? 0));
                if (!empty($res['ok'])) {
                    $_SESSION['examination_flash'] = 'Question deleted.';
                    examination_edit_redirect_questions($postType, $postId, $sid);
                }
                $error = (string)($res['error'] ?? 'Could not delete question.');
            } elseif ($action === 'import_questions' && $postType === 'diagnostic') {
                $sid = (int)($_POST['subject_id'] ?? 0);
                $raw = $_POST['import_json'] ?? '';
                $rows = json_decode((string)$raw, true);
                if (!is_array($rows) || $sid <= 0) {
                    $error = 'Import data is invalid.';
                } else {
                    $res = examination_questions_diagnostic_import_append($conn, $postId, $uid, $sid, $rows);
                    if (!empty($res['ok'])) {
                        $_SESSION['examination_flash'] = 'Imported ' . (int)($res['imported'] ?? 0) . ' question(s).';
                        examination_edit_redirect_questions($postType, $postId, $sid);
                    }
                    $error = (string)($res['error'] ?? 'Import failed.');
                }
            } else {
                $error = 'Unknown questions action.';
            }
        }
    }
}

if ($modalMode && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $error !== null) {
    $_SESSION['examination_modal_error'] = $error;
    $postType = examination_normalize_exam_type((string)($_POST['exam_type'] ?? $examType));
    $postId = examination_domain_source_id_from_request($_POST, $postType);
    $editUrl = $postId > 0
        ? (examination_domain_edit_url($postType, $postId, 'config') . '&modal=1')
        : ('professor_examination_edit?modal=1&exam_type=' . rawurlencode($postType));
    $postScope = examination_normalize_examinee_scope((string)($_POST['examinee_scope'] ?? ''));
    if ($postScope !== '') {
        $editUrl .= '&examinee_scope=' . rawurlencode($postScope);
    }
    header('Location: professor_examinations?open_edit=' . rawurlencode($editUrl));
    exit;
}

if ($modalMode && $error === null && !empty($_SESSION['examination_modal_error'])) {
    $error = (string)$_SESSION['examination_modal_error'];
    unset($_SESSION['examination_modal_error']);
}

$editContext = examination_domain_load_for_edit($conn, $examType, $sourceId, $uid);
$record = is_array($editContext['record'] ?? null) ? $editContext['record'] : null;
$extras = is_array($editContext['extras'] ?? null) ? $editContext['extras'] : [];

$scopeForSearch = examination_normalize_examinee_scope((string)($_GET['examinee_scope'] ?? $_POST['examinee_scope'] ?? ($record['examinee_scope'] ?? 'college_student')));
$examineeSearchResults = diagnostic_exam_search_examinees($conn, $scopeForSearch, '', 200);

$flashMessage = $_SESSION['examination_flash'] ?? null;
unset($_SESSION['examination_flash']);
$flashError = $_SESSION['examination_flash_error'] ?? null;
unset($_SESSION['examination_flash_error']);

if ($step === 'questions') {
    if ($isNew) {
        header('Location: professor_examination_edit?exam_type=' . rawurlencode($examType));
        exit;
    }
    require dirname(__DIR__) . '/includes/examination_edit_questions_view.php';
    exit;
}

if ($step === 'review') {
    if ($isNew) {
        header('Location: professor_examination_edit?exam_type=' . rawurlencode($examType));
        exit;
    }
    require dirname(__DIR__) . '/includes/examination_edit_review_view.php';
    exit;
}

if ($modalMode && $step === 'config') {
    require dirname(__DIR__) . '/includes/examination_edit_config_modal.php';
    exit;
}

require dirname(__DIR__) . '/includes/examination_edit_config_view.php';
