<?php
declare(strict_types=1);

/** Backward-compatible delegate: college exam monitor → unified examination monitor. */
if (!isset($_GET['exam_type']) && (int)($_GET['exam_id'] ?? 0) > 0) {
    $_GET['exam_type'] = 'college_exam';
}
require __DIR__ . '/professor_examination_monitor.php';
