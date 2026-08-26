<?php
/**
 * Quick unit checks for examination schedule bucket logic (CLI).
 * Usage: php scripts/qa_exam_schedule_status_unit.php
 */
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';

$nowBase = '2026-08-25 10:30:00';

function qa_bucket(array $item, string $now): string
{
    global $conn;
    return examination_student_resolve_bucket($conn, $item, $now);
}

function qa_item(array $overrides = []): array
{
    $base = [
        'exam_type' => 'regular',
        'available_from' => '2026-08-25 10:00:00',
        'deadline' => '2026-08-25 11:00:00',
        'attempt_status' => null,
        'started_at' => null,
        'submitted_at' => null,
        '_submitted_count' => 0,
        '_record' => ['is_published' => 1, 'deadline' => '2026-08-25 11:00:00'],
    ];

    return array_merge($base, $overrides);
}

$tests = [
    ['Upcoming before start', qa_item(), '2026-08-25 09:30:00', 'upcoming'],
    ['Open during window', qa_item(), '2026-08-25 10:30:00', 'open'],
    ['Closed after end (never started)', qa_item(), '2026-08-25 11:01:00', 'missed'],
    ['Finished after end (started)', qa_item(['attempt_status' => 'expired', 'started_at' => '2026-08-25 10:05:00']), '2026-08-25 11:01:00', 'finished'],
    ['Submitted', qa_item(['attempt_status' => 'submitted', 'submitted_at' => '2026-08-25 10:50:00']), '2026-08-25 11:01:00', 'finished'],
    ['In progress stays open', qa_item(['attempt_status' => 'in_progress', 'started_at' => '2026-08-25 10:05:00']), '2026-08-25 10:45:00', 'open'],
];

$failed = 0;
foreach ($tests as [$label, $item, $now, $expected]) {
    $got = qa_bucket($item, $now);
    $ok = $got === $expected;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? 'PASS' : 'FAIL') . "  {$label}: expected {$expected}, got {$got}\n";
}

exit($failed > 0 ? 1 : 0);
