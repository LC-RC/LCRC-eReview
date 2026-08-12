<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_playground_battle.php';

function b_assert(bool $ok, string $msg): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $msg . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

student_playground_battle_ensure_schema($conn);

$nickOk = student_playground_battle_validate_nickname('CPA_Warrior_21');
b_assert(!empty($nickOk['ok']), 'valid nickname');
$nickBad = student_playground_battle_validate_nickname('ab');
b_assert(empty($nickBad['ok']), 'short nickname rejected');
$nickBad2 = student_playground_battle_validate_nickname('bad!!');
b_assert(empty($nickBad2['ok']), 'special chars rejected');

$code = student_playground_battle_generate_code($conn);
b_assert(strlen($code) === 5, 'room code length 5');
b_assert($code === student_playground_battle_normalize_code(strtolower($code)), 'code normalize');

$ptsFast = student_playground_battle_compute_points(true, 500, 30, 3, true, true);
$ptsSlow = student_playground_battle_compute_points(true, 25000, 30, 1, true, true);
$ptsWrong = student_playground_battle_compute_points(false, 500, 30, 0, true, true);
b_assert($ptsWrong === 0, 'wrong = 0');
b_assert($ptsFast > $ptsSlow, 'faster earns more');
b_assert($ptsFast >= 500 && $ptsFast <= 1300, 'points in range');

// Public question must not leak correct answer in battle payload builder path.
$pool = student_playground_question_pool($conn, 1, 0);
if ($pool !== []) {
    $q = $pool[0];
    $stmt = mysqli_prepare($conn, 'SELECT * FROM quiz_questions WHERE question_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $q['question_id']);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $item = [
        'ordinal' => 1,
        'question_id' => (int) $q['question_id'],
        'choice_order' => 'ABCD',
        'subject_id' => (int) $q['subject_id'],
        'selected_answer' => null,
    ];
    $pub = student_playground_public_question($conn, $item, $qRow);
    b_assert(!array_key_exists('correct_answer', $pub), 'public question has no correct_answer');
} else {
    echo "[SKIP] no questions in pool for user 1 — leak check skipped\n";
}

echo "\nAll battle smoke checks passed.\n";
