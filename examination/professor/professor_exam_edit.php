<?php

if (isset($_GET['duplicate_from']) && (int)$_GET['duplicate_from'] > 0) {
    require __DIR__ . '/professor_exam_edit_legacy.php';
    exit;
}

$params = $_GET;
$params['exam_type'] = 'regular';
if (isset($params['id']) && !isset($params['exam_id'])) {
    $params['exam_id'] = $params['id'];
    unset($params['id']);
}
if (!isset($params['step'])) {
    $params['step'] = 'config';
}
header('Location: professor_examination_edit?' . http_build_query($params));
exit;
