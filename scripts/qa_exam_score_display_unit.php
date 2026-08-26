<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/examination/includes/college_exam_helpers.php';

$assert = static function (string $label, $actual, $expected): void {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}: expected " . json_encode($expected) . " got " . json_encode($actual) . "\n");
        exit(1);
    }
    echo "PASS {$label}\n";
};

$assert('0/2', college_exam_score_display_from_counts(0, 2), [
    'fraction' => '0/2',
    'percent' => 0.0,
    'percent_label' => '0%',
]);
$assert('1/2', college_exam_score_display_from_counts(1, 2), [
    'fraction' => '1/2',
    'percent' => 50.0,
    'percent_label' => '50%',
]);
$assert('2/2', college_exam_score_display_from_counts(2, 2), [
    'fraction' => '2/2',
    'percent' => 100.0,
    'percent_label' => '100%',
]);
$assert('0/4', college_exam_score_display_from_counts(0, 4), [
    'fraction' => '0/4',
    'percent' => 0.0,
    'percent_label' => '0%',
]);
$assert('2/4', college_exam_score_display_from_counts(2, 4), [
    'fraction' => '2/4',
    'percent' => 50.0,
    'percent_label' => '50%',
]);
$assert('3/4', college_exam_score_display_from_counts(3, 4), [
    'fraction' => '3/4',
    'percent' => 75.0,
    'percent_label' => '75%',
]);
$assert('1/3', college_exam_score_display_from_counts(1, 3), [
    'fraction' => '1/3',
    'percent' => 33.0,
    'percent_label' => '33%',
]);
$assert('2/3', college_exam_score_display_from_counts(2, 3), [
    'fraction' => '2/3',
    'percent' => 67.0,
    'percent_label' => '67%',
]);
$assert('no total', college_exam_score_display_from_counts(0, 0), null);
$assert('fallback total', college_exam_score_display_from_counts(1, null, 4), [
    'fraction' => '1/4',
    'percent' => 25.0,
    'percent_label' => '25%',
]);

// CEO curve still differs (grading storage) — ensure we did not break it.
$assert('ceo 0/2', college_exam_compute_score_percentage(0, 2), 50.0);

echo "All score display unit tests passed.\n";
