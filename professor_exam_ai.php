<?php
/**
 * Professor-only JSON API for optional AI-assisted question authoring.
 * Requires OPENAI_API_KEY (see includes/ai_config.php) for live suggestions;
 * otherwise returns a structured offline fallback.
 */
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/ai_config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$stem = trim((string)($_POST['stem'] ?? ''));

if ($stem === '' || mb_strlen($stem) > 8000) {
    echo json_encode(['ok' => false, 'error' => 'Enter a question stem (max 8000 characters).']);
    exit;
}

function professor_exam_ai_fallback_generate(string $stem): array
{
    $snippet = mb_substr(preg_replace('/\s+/', ' ', $stem), 0, 80);
    return [
        'choice_a' => 'Yes — this follows directly from the statement above.',
        'choice_b' => 'No — this contradicts the expected outcome.',
        'choice_c' => 'Only under specific conditions not stated in the question.',
        'choice_d' => 'Cannot be determined from the information given.',
        'correct' => 'A',
        'note' => 'Offline template. Replace with course-specific options. Add OPENAI_API_KEY for AI-generated distractors.',
    ];
}

function professor_exam_ai_fallback_distractors(string $stem, string $correctLetter, string $correctText): array
{
    $pool = [
        'A related but weaker statement than the correct answer.',
        'A common misconception in this topic.',
        'True in a different context than the one asked.',
        'None of the above.',
    ];
    $letters = ['A', 'B', 'C', 'D'];
    $out = ['choice_a' => '', 'choice_b' => '', 'choice_c' => '', 'choice_d' => '', 'correct' => $correctLetter];
    $ci = ord(strtoupper($correctLetter)) - ord('A');
    if ($ci < 0 || $ci > 3) {
        $ci = 0;
    }
    $p = 0;
    for ($i = 0; $i < 4; $i++) {
        $L = $letters[$i];
        if ($i === $ci) {
            $out['choice_' . strtolower($L)] = $correctText;
        } else {
            $out['choice_' . strtolower($L)] = $pool[$p % count($pool)];
            $p++;
        }
    }
    $out['note'] = 'Offline template distractors. Configure OPENAI_API_KEY for smarter suggestions.';
    return $out;
}

function professor_exam_openai_json(string $apiKey, string $system, string $user): ?array
{
    if ($apiKey === '' || !function_exists('curl_init')) {
        return null;
    }
    $payload = json_encode([
        'model' => 'gpt-4o-mini',
        'temperature' => 0.35,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return null;
    }
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 55,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $txt = $data['choices'][0]['message']['content'] ?? '';
    if (!is_string($txt) || $txt === '') {
        return null;
    }
    $parsed = json_decode($txt, true);
    return is_array($parsed) ? $parsed : null;
}

if ($action === 'generate_options') {
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    $parsed = null;
    if ($apiKey !== '') {
        $parsed = professor_exam_openai_json(
            $apiKey,
            'You write multiple-choice items for college exams. Reply with JSON only: {"choice_a":"","choice_b":"","choice_c":"","choice_d":"","correct":"A|B|C|D","note":""}. Four plausible options, exactly one correct key.',
            "Question stem:\n" . $stem
        );
    }
    if (!$parsed || !isset($parsed['choice_a'], $parsed['correct'])) {
        $parsed = professor_exam_ai_fallback_generate($stem);
    }
    $cor = strtoupper(trim((string)($parsed['correct'] ?? 'A')));
    if (!preg_match('/^[A-D]$/', $cor)) {
        $cor = 'A';
    }
    echo json_encode([
        'ok' => true,
        'choice_a' => (string)($parsed['choice_a'] ?? ''),
        'choice_b' => (string)($parsed['choice_b'] ?? ''),
        'choice_c' => (string)($parsed['choice_c'] ?? ''),
        'choice_d' => (string)($parsed['choice_d'] ?? ''),
        'correct' => $cor,
        'note' => (string)($parsed['note'] ?? ''),
    ]);
    exit;
}

if ($action === 'suggest_distractors') {
    $correctLetter = strtoupper(trim((string)($_POST['correct_letter'] ?? 'A')));
    if (!preg_match('/^[A-D]$/', $correctLetter)) {
        echo json_encode(['ok' => false, 'error' => 'Pick a correct answer A–D first.']);
        exit;
    }
    $ca = trim((string)($_POST['choice_a'] ?? ''));
    $cb = trim((string)($_POST['choice_b'] ?? ''));
    $cc = trim((string)($_POST['choice_c'] ?? ''));
    $cd = trim((string)($_POST['choice_d'] ?? ''));
    $map = ['A' => $ca, 'B' => $cb, 'C' => $cc, 'D' => $cd];
    $correctText = $map[$correctLetter] ?? '';
    if ($correctText === '') {
        echo json_encode(['ok' => false, 'error' => 'Fill the correct option text before asking for distractors.']);
        exit;
    }

    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    $parsed = null;
    if ($apiKey !== '') {
        $parsed = professor_exam_openai_json(
            $apiKey,
            'You improve multiple-choice exams. Given a stem and the correct option letter and text, return JSON only: {"choice_a":"","choice_b":"","choice_c":"","choice_d":"","correct":"A|B|C|D","note":""}. Keep the correct answer text exactly for the correct letter; replace only distractors with plausible wrong answers.',
            "Stem:\n" . $stem . "\n\nCorrect letter: " . $correctLetter . "\nCorrect text: " . $correctText
        );
    }
    if (!$parsed || !isset($parsed['choice_a'])) {
        $parsed = professor_exam_ai_fallback_distractors($stem, $correctLetter, $correctText);
    } else {
        $parsed['choice_' . strtolower($correctLetter)] = $correctText;
        $parsed['correct'] = $correctLetter;
    }
    $cor = strtoupper(trim((string)($parsed['correct'] ?? $correctLetter)));
    if (!preg_match('/^[A-D]$/', $cor)) {
        $cor = $correctLetter;
    }
    echo json_encode([
        'ok' => true,
        'choice_a' => (string)($parsed['choice_a'] ?? ''),
        'choice_b' => (string)($parsed['choice_b'] ?? ''),
        'choice_c' => (string)($parsed['choice_c'] ?? ''),
        'choice_d' => (string)($parsed['choice_d'] ?? ''),
        'correct' => $cor,
        'note' => (string)($parsed['note'] ?? ''),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
exit;
