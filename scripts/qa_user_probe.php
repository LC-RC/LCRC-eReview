<?php
require_once __DIR__ . '/../auth.php';
$ids = [6193, 6196, 6194, 6191, 6198];
$in = implode(',', $ids);
$q = mysqli_query($conn, "SELECT user_id, full_name, role, status, review_type, section, college_examination_access FROM users WHERE user_id IN ($in)");
while ($r = mysqli_fetch_assoc($q)) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
