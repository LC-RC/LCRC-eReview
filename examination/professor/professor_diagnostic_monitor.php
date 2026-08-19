<?php
declare(strict_types=1);

/** Backward-compatible delegate: diagnostic batch monitor → unified examination monitor. */
if (!isset($_GET['exam_type']) && (int)($_GET['batch_id'] ?? 0) > 0) {
    $_GET['exam_type'] = 'diagnostic';
}
require __DIR__ . '/professor_examination_monitor.php';
