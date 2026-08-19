<?php
declare(strict_types=1);

/**
 * Diagnostic batch edit entry.
 * Config is canonical at professor_examination_edit.
 * Questions step redirects into the unified wizard (legacy file retained but not in normal flow).
 */
if (isset($_GET['step']) && (string)$_GET['step'] === 'questions') {
    $batchId = (int)($_GET['id'] ?? $_GET['batch_id'] ?? 0);
    $qs = 'exam_type=diagnostic&step=questions';
    if ($batchId > 0) {
        $qs .= '&batch_id=' . $batchId;
    }
    header('Location: professor_examination_edit?' . $qs);
    exit;
}

if (!isset($_GET['exam_type'])) {
    $_GET['exam_type'] = 'diagnostic';
}
if (!isset($_GET['batch_id']) && isset($_GET['id'])) {
    $_GET['batch_id'] = $_GET['id'];
}
require __DIR__ . '/professor_examination_edit.php';
