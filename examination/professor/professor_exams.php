<?php
declare(strict_types=1);

/** Legacy delegate → unified Examinations list (regular filter). */
$params = $_GET;
if (!isset($params['exam_type'])) {
    $params['exam_type'] = 'regular';
}
header('Location: professor_examinations?' . http_build_query($params));
exit;
