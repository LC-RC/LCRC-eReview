<?php
/**
 * CLI smoke for CPA Playground core helpers (no HTTP).
 * Usage: php scripts/student_playground_smoke_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_playground.php';

$fail = 0;
function assert_true(bool $c, string $m): void
{
    global $fail;
    if ($c) {
        echo "[PASS] {$m}\n";
    } else {
        echo "[FAIL] {$m}\n";
        $fail++;
    }
}

student_playground_ensure_schema($conn);
assert_true(ereview_schema_table_exists($conn, 'student_playground_sessions'), 'sessions table');
assert_true(ereview_schema_table_exists($conn, 'student_playground_items'), 'items table');

$ptsFast = student_playground_compute_points(true, 1000, 20);
$ptsSlow = student_playground_compute_points(true, 19000, 20);
$ptsWrong = student_playground_compute_points(false, 1000, 20);
assert_true($ptsWrong === 0, 'wrong = 0 points');
$ptsInstant = student_playground_compute_points(true, 0, 20);
assert_true($ptsInstant === STUDENT_PLAYGROUND_BASE_POINTS + STUDENT_PLAYGROUND_SPEED_BONUS_MAX, 'instant correct = base+max bonus');
assert_true($ptsFast > $ptsSlow && $ptsFast >= STUDENT_PLAYGROUND_BASE_POINTS, 'faster earns more than slow');
assert_true($ptsSlow >= STUDENT_PLAYGROUND_BASE_POINTS && $ptsSlow < $ptsFast, 'slow correct still base, less bonus');

$items = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];
$a = student_playground_seeded_shuffle($items, 'seed-a');
$b = student_playground_seeded_shuffle($items, 'seed-a');
$c = student_playground_seeded_shuffle($items, 'seed-b');
assert_true($a === $b, 'same seed = same shuffle');
assert_true($a !== $c, 'different seed changes order');
$ids = array_column($a, 'id');
assert_true(count($ids) === count(array_unique($ids)), 'shuffle keeps unique items');

$order = student_playground_shuffle_choice_order(['A', 'B', 'C', 'D'], 'seed', 99);
assert_true(strlen($order) === 4 && count(array_unique(str_split($order))) === 4, 'choice order unique letters');

assert_true(student_playground_daily_key() === date('Y-m-d'), 'daily key is calendar date');

echo $fail === 0 ? "\nAll playground smoke checks passed.\n" : "\n{$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
