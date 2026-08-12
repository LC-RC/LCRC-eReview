<?php
/**
 * Smoke: total exam timer schema + flexible duration helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_playground.php';

function pg_assert(bool $ok, string $msg): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $msg . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

student_playground_ensure_schema($conn);

foreach (['play_style', 'total_time_seconds', 'ends_at', 'current_ordinal'] as $col) {
    pg_assert(
        ereview_schema_column_exists($conn, 'student_playground_sessions', $col),
        "column $col"
    );
}

$rec10 = student_playground_recommended_total_seconds(10);
$rec20 = student_playground_recommended_total_seconds(20);
pg_assert($rec10 === 600, "recommended 10q = 10min (got $rec10)");
pg_assert($rec20 === 900, "recommended 20q = 15min (got $rec20)");

pg_assert(student_playground_duration_to_seconds(30, 'seconds') === 30, '30 seconds');
pg_assert(student_playground_duration_to_seconds(10, 'minutes') === 600, '10 minutes');
pg_assert(student_playground_duration_to_seconds(1, 'hours') === 3600, '1 hour');
pg_assert(student_playground_duration_to_seconds(2, 'hours') === 7200, '2 hours');
pg_assert(student_playground_duration_to_seconds(1, 'seconds') === 30, 'clamp min 30s');
pg_assert(student_playground_duration_to_seconds(5, 'hours') === 10800, 'clamp max 3h');

$fromPayload = student_playground_resolve_total_seconds(10, [
    'time_value' => 90,
    'time_unit' => 'seconds',
]);
pg_assert($fromPayload === 90, 'resolve time_value+unit seconds');

$legacy = student_playground_resolve_total_seconds(10, [
    'time_minutes' => 15,
    'custom_time' => 0,
]);
pg_assert($legacy === 900, 'legacy time_minutes preset');

$fake = [
    'ends_at' => date('Y-m-d H:i:s', time() + 125),
    'total_time_seconds' => 600,
    'question_count' => 20,
    'started_at' => date('Y-m-d H:i:s'),
];
$rem = student_playground_remaining_total_seconds($fake);
pg_assert($rem >= 120 && $rem <= 126, "remaining ~125s (got $rem)");

$expired = [
    'status' => 'in_progress',
    'ends_at' => date('Y-m-d H:i:s', time() - 5),
    'total_time_seconds' => 600,
    'question_count' => 20,
    'started_at' => date('Y-m-d H:i:s', time() - 700),
];
pg_assert(student_playground_remaining_total_seconds($expired) === 0, 'expired remaining = 0');
pg_assert(student_playground_session_expired($expired), 'session marked expired');

echo PHP_EOL . 'All total-timer smoke checks passed.' . PHP_EOL;
