<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/examination/includes/examination_eligibility.php';

$items = [
    ['title' => 'Old Exam', 'source_id' => 1, 'created_at' => '2026-01-01 08:00:00', 'deadline' => '2026-12-01 23:59:59', 'available_from' => '2026-06-01 08:00:00', '_bucket' => 'missed'],
    ['title' => 'New Exam', 'source_id' => 99, 'created_at' => '2026-08-20 08:00:00', 'deadline' => '2026-09-01 23:59:59', 'available_from' => '2026-08-01 08:00:00', '_bucket' => 'finished'],
    ['title' => 'Mid Exam', 'source_id' => 50, 'created_at' => '2026-05-15 08:00:00', 'deadline' => '2026-08-01 23:59:59', 'available_from' => '2026-04-01 08:00:00', '_bucket' => 'open'],
];

$assert = static function (string $label, array $actual, array $expected): void {
    $got = array_column($actual, 'title');
    if ($got !== $expected) {
        fwrite(STDERR, "FAIL {$label}: expected [" . implode(', ', $expected) . "] got [" . implode(', ', $got) . "]\n");
        exit(1);
    }
    echo "PASS {$label}\n";
};

$assert('recent', examination_student_sort_items($items, 'recent'), ['New Exam', 'Mid Exam', 'Old Exam']);
$assert('oldest', examination_student_sort_items($items, 'oldest'), ['Old Exam', 'Mid Exam', 'New Exam']);
$assert('deadline_asc', examination_student_sort_items($items, 'deadline_asc'), ['Mid Exam', 'New Exam', 'Old Exam']);

$missed = array_values(array_filter($items, static fn(array $i): bool => ($i['_bucket'] ?? '') === 'missed'));
$assert('missed+recent', examination_student_sort_items($missed, 'recent'), ['Old Exam']);

echo "All sort unit tests passed.\n";
