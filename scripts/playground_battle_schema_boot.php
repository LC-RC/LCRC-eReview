<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_playground_battle.php';
student_playground_battle_ensure_schema($conn);
echo "battle schema ok\n";
