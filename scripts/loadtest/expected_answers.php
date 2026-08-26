<?php
/**
 * Deterministic expected-answer generator for load tests.
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

/**
 * @return 'A'|'B'|'C'|'D'
 */
function loadtest_expected_letter(int $userId, int $questionId, ?string $secret = null): string
{
    $secret = $secret ?? loadtest_secret();
    $payload = $userId . ':' . $questionId . ':' . $secret;
    // crc32 can be negative on 32-bit; normalize to unsigned.
    $crc = crc32($payload);
    if ($crc < 0) {
        $crc = $crc & 0xFFFFFFFF;
    }
    $letters = ['A', 'B', 'C', 'D'];

    return $letters[$crc % 4];
}

/**
 * @param list<int> $questionIds
 * @return list<array{question_id:int,selected_answer:string}>
 */
function loadtest_expected_answers_for_user(int $userId, array $questionIds, ?string $secret = null): array
{
    $out = [];
    foreach ($questionIds as $qid) {
        $qid = (int)$qid;
        if ($qid <= 0) {
            continue;
        }
        $out[] = [
            'question_id' => $qid,
            'selected_answer' => loadtest_expected_letter($userId, $qid, $secret),
        ];
    }

    return $out;
}

/**
 * Optionally drop a fraction of questions from the "selected during exam" set
 * while keeping full expected set for timeout flush verification.
 *
 * @param list<array{question_id:int,selected_answer:string}> $answers
 * @return list<array{question_id:int,selected_answer:string}>
 */
function loadtest_subset_for_autosave(array $answers, float $answerRate = 1.0): array
{
    $answerRate = max(0.0, min(1.0, $answerRate));
    if ($answerRate >= 0.999) {
        return $answers;
    }
    $n = count($answers);
    $keep = (int)max(0, floor($n * $answerRate));
    if ($keep >= $n) {
        return $answers;
    }

    return array_slice($answers, 0, $keep);
}
