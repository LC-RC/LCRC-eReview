<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_playground.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Simulate stale negative cache (the failure mode that caused Duplicate column).
ereview_schema_session_set('c:student_playground_sessions.play_style', false);
ereview_schema_session_set('c:student_playground_sessions.total_time_seconds', false);
ereview_schema_session_set('c:student_playground_sessions.ends_at', false);
ereview_schema_session_set('c:student_playground_sessions.current_ordinal', false);

student_playground_ensure_schema($conn);

foreach (['play_style', 'total_time_seconds', 'ends_at', 'current_ordinal'] as $col) {
    $ok = ereview_schema_column_exists_fresh($conn, 'student_playground_sessions', $col);
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $col . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

echo "Stale-cache ensure_schema OK\n";
