<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_activity.php';
require_once __DIR__ . '/../includes/schema_introspection.php';

student_activity_ensure_schema($conn);
foreach (['student_content_events', 'student_sessions', 'student_video_progress', 'quiz_attempts'] as $t) {
    echo $t . ': ' . (ereview_schema_table_exists($conn, $t) ? 'ok' : 'missing') . PHP_EOL;
}
echo 'quiz_attempts.last_seen_at: ' . (ereview_schema_column_exists($conn, 'quiz_attempts', 'last_seen_at') ? 'ok' : 'missing') . PHP_EOL;
