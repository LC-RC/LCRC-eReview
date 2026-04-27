<?php
declare(strict_types=1);

/**
 * LCRC Support AI v2: grounded LLM, session memory, flows, language, escalation.
 * Loaded at end of chat_support_helpers.php (depends on functions defined there).
 */

function ereview_chat_get_setting(mysqli $conn, string $key, string $default = ''): string
{
    if (!ereview_chat_v2_ready($conn)) {
        return $default;
    }
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM support_chat_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row && isset($row['setting_value']) ? (string) $row['setting_value'] : $default;
}

function ereview_chat_global_banned_lines(mysqli $conn): array
{
    $raw = ereview_chat_get_setting($conn, 'global_banned_topics', '');
    $lines = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = mb_strtolower($line);
        }
    }
    return $out;
}

function ereview_chat_message_hits_banned_phrases(string $message, array $phrases): bool
{
    $t = mb_strtolower($message);
    foreach ($phrases as $p) {
        if ($p === '') {
            continue;
        }
        if (mb_strpos($t, $p) !== false) {
            return true;
        }
    }
    return false;
}

/** en | tl | mixed */
function ereview_chat_detect_language_code(string $message): string
{
    $s = trim($message);
    if ($s === '') {
        return 'en';
    }
    // Common Filipino / Tagalog markers (heuristic, not perfect)
    $tlPattern = '/\b(ang|nga|ng|sa|ko|mo|naman|po|opo|hindi|oo|hindi|kayo|kayo|ako|ikaw|kung|pero|dahil|dito|diyan|doon|salamat|mag|mga|tayo|sila|ba|lang|rin|din|naman|yung|iyong|mayroon|wala|bakit|paano|saan|kailan)\b/ui';
    $hasTl = (bool) preg_match($tlPattern, $s);
    $asciiRatio = strlen(preg_replace('/[^\x00-\x7F]/u', '', $s)) / max(1, strlen($s));
    $hasLatinOnly = $asciiRatio > 0.85;
    if ($hasTl && !$hasLatinOnly) {
        return 'tl';
    }
    if ($hasTl && preg_match('/[a-z]{3,}/i', $s)) {
        return 'mixed';
    }
    if ($hasTl) {
        return 'tl';
    }
    return 'en';
}

/** sales | billing_support | technical_access | general */
function ereview_chat_detect_conversation_flow(string $message, string $intent): string
{
    $t = mb_strtolower($message);
    if (preg_match('/\b(refund|chargeback|dispute|invoice|receipt|billing|paid|payment proof|gcash|bank transfer|money back)\b/u', $t)) {
        return 'billing_support';
    }
    if (preg_match('/\b(login|password|cannot access|error|bug|crash|video won\'t|not loading|technical|otp|link broken)\b/u', $t)) {
        return 'technical_access';
    }
    if (preg_match('/\b(package|price|cost|enroll|plan|promo|compare|what\'s included)\b/u', $t) || in_array($intent, ['packages', 'registration', 'learning_content'], true)) {
        return 'sales';
    }
    return 'general';
}

function ereview_chat_is_account_sensitive(string $message): bool
{
    $t = mb_strtolower($message);
    return (bool) preg_match(
        '/\b(refund|chargeback|dispute|my account|my email|my payment|personal data|delete my data|privacy|hack|unauthorized|lawyer|sue|court|bank account number|credit card)\b/u',
        $t
    );
}

function ereview_chat_infer_package_interest(string $message): ?string
{
    $t = mb_strtolower($message);
    if (preg_match('/\b(14[\s-]*month|14\s*mos?|2500|2\s*,?\s*500)\b/u', $t)) {
        return '14-month';
    }
    if (preg_match('/\b(9[\s-]*month|9\s*mos?|2000|2\s*,?\s*000)\b/u', $t)) {
        return '9-month';
    }
    if (preg_match('/\b(6[\s-]*month|6\s*mos?|1500|1\s*,?\s*500)\b/u', $t)) {
        return '6-month';
    }
    return null;
}

function ereview_chat_topic_from_intent(string $intent): string
{
    $map = [
        'packages' => 'packages',
        'registration' => 'registration',
        'payment' => 'payment',
        'account_issue' => 'access',
        'learning_content' => 'content',
        'faq' => 'general',
    ];
    return $map[$intent] ?? 'general';
}

function ereview_chat_session_load_memory(mysqli $conn, string $sessionId): array
{
    if (!ereview_chat_v2_ready($conn)) {
        return ['last_topic' => null, 'package_interest' => null, 'detected_language' => null, 'conversation_flow' => null];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT last_topic, package_interest, detected_language, conversation_flow FROM support_chat_sessions WHERE session_id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 's', $sessionId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['last_topic' => null, 'package_interest' => null, 'detected_language' => null, 'conversation_flow' => null];
    }
    return [
        'last_topic' => $row['last_topic'] ?? null,
        'package_interest' => $row['package_interest'] ?? null,
        'detected_language' => $row['detected_language'] ?? null,
        'conversation_flow' => $row['conversation_flow'] ?? null,
    ];
}

function ereview_chat_session_write_memory(
    mysqli $conn,
    string $sessionId,
    string $lastTopic,
    ?string $packageInterest,
    string $language,
    string $flow
): void {
    if (!ereview_chat_v2_ready($conn)) {
        return;
    }
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE support_chat_sessions SET last_topic = ?, package_interest = ?, detected_language = ?, conversation_flow = ? WHERE session_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'sssss', $lastTopic, $packageInterest, $language, $flow, $sessionId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function ereview_chat_format_history_for_llm(mysqli $conn, string $sessionId, int $maxPairs = 10): string
{
    $msgs = ereview_chat_fetch_recent_messages($conn, $sessionId, $maxPairs * 2);
    $lines = [];
    foreach ($msgs as $m) {
        if (($m['role'] ?? '') === 'user') {
            $lines[] = 'User: ' . trim((string) ($m['text'] ?? ''));
        } elseif (in_array($m['role'] ?? '', ['assistant', 'agent'], true)) {
            $lines[] = 'Assistant: ' . trim((string) ($m['text'] ?? ''));
        }
    }
    return implode("\n", array_slice($lines, -20));
}

function ereview_chat_build_transcript_for_ticket(mysqli $conn, string $sessionId, int $maxMessages = 24): string
{
    $msgs = ereview_chat_fetch_recent_messages($conn, $sessionId, $maxMessages);
    $lines = [];
    foreach ($msgs as $m) {
        $role = ($m['role'] ?? '') === 'user' ? 'User' : 'Bot';
        $lines[] = $role . ': ' . trim((string) ($m['text'] ?? ''));
    }
    return implode("\n", $lines);
}

/**
 * @return array<int, array<string, mixed>>
 */
function ereview_chat_retrieve_top_documents(mysqli $conn, string $question, int $k = 5): array
{
    $docs = ereview_chat_fetch_kb_documents($conn);
    $qTokens = ereview_chat_tokenize($question);
    $qMap = array_fill_keys($qTokens, true);
    $scored = [];
    foreach ($docs as $doc) {
        $haystack = mb_strtolower($doc['title'] . ' ' . $doc['content'] . ' ' . ($doc['keywords'] ?? '') . ' ' . ($doc['short_answer'] ?? ''));
        $score = 0.0;
        $longHits = 0;
        foreach ($qMap as $tok => $_) {
            if (!ereview_chat_token_matches_as_word($haystack, $tok)) {
                continue;
            }
            $w = strlen($tok) >= 4 ? 1.2 : (strlen($tok) >= 3 ? 1.0 : 0.45);
            $score += $w;
            if (strlen($tok) >= 3) {
                $longHits++;
            }
        }
        if (!empty($qTokens)) {
            $score = $score / max(1, count($qTokens));
        }
        $scored[] = ['score' => $score, 'long_hits' => $longHits, 'doc' => $doc];
    }
    usort($scored, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice($scored, 0, max(1, $k));
}

function ereview_chat_build_strict_kb_context(array $topScored): string
{
    $parts = [];
    foreach ($topScored as $item) {
        $doc = $item['doc'] ?? [];
        $title = (string) ($doc['title'] ?? '');
        $content = trim((string) ($doc['content'] ?? ''));
        $short = trim((string) ($doc['short_answer'] ?? ''));
        $approved = trim((string) ($doc['approved_phrases'] ?? ''));
        $block = "ARTICLE: {$title}\nMAIN: {$content}\n";
        if ($short !== '') {
            $block .= "SHORT ANSWER: {$short}\n";
        }
        if ($approved !== '') {
            $block .= "APPROVED PHRASES (may quote verbatim):\n{$approved}\n";
        }
        $parts[] = $block;
    }
    return implode("\n---\n", $parts);
}

/**
 * Grounded OpenAI call with conversation + memory. Returns null if no API key or error.
 */
function ereview_chat_openai_grounded_reply(
    string $userMessage,
    string $kbContext,
    string $historyBlock,
    string $memoryBlock,
    string $languageCode,
    string $flowType,
    bool $accountSensitive
): ?string {
    $settings = ereview_chat_get_openai_settings();
    $key = $settings['key'] ?? '';
    if ($key === '') {
        return null;
    }
    $model = $settings['model'] ?? 'gpt-4o-mini';
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $langInstr = match ($languageCode) {
        'tl' => 'Reply primarily in Filipino/Tagalog (Taglish allowed). Keep numbers and official program names accurate.',
        'mixed' => 'The user mixes English and Filipino; mirror that style (Taglish).',
        default => 'Reply in clear English.',
    };

    $flowInstr = match ($flowType) {
        'sales' => 'Tone: prospective student / sales help. Focus on packages, enrollment path, free samples.',
        'billing_support' => 'Tone: billing support. Do not promise refunds; escalate to human for disputes and payment account issues.',
        'technical_access' => 'Tone: technical support. Suggest concrete steps; escalate if account-specific.',
        default => 'Tone: general LCRC eReview help.',
    };

    $system = "You are LCRC Support Assistant for LCRC eReview (CPA review platform, Philippines).\n"
        . "Plain text only. No markdown.\n\n"
        . "STRICT GROUNDING:\n"
        . "- You may ONLY state prices, durations, policies, deadlines, or program facts that appear in KNOWLEDGE BASE below.\n"
        . "- If the user's question is not answered by KNOWLEDGE BASE, reply that you are not sure about that specific detail and tell them to use Talk to Human in chat or email contact@ereview.ph.\n"
        . "- Never invent prices, promos, guarantees, legal outcomes, or personal account status.\n"
        . "- For chit-chat (thanks, hello) you may respond briefly without KB.\n"
        . "- {$langInstr}\n"
        . "- {$flowInstr}\n";
    if ($accountSensitive) {
        $system .= "- This message may involve personal account, refund, or dispute: keep reply short and strongly encourage Talk to Human + contact@ereview.ph.\n";
    }

    $userPayload = "KNOWLEDGE BASE:\n" . ($kbContext !== '' ? $kbContext : '(empty)') . "\n\n"
        . "SESSION MEMORY:\n" . $memoryBlock . "\n\n"
        . "RECENT CONVERSATION:\n" . ($historyBlock !== '' ? $historyBlock : '(start of chat)') . "\n\n"
        . "LATEST USER MESSAGE:\n" . $userMessage;

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPayload],
        ],
        'max_tokens' => 500,
        'temperature' => 0.35,
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return null;
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }
    $text = $json['choices'][0]['message']['content'] ?? null;
    if (!is_string($text)) {
        return null;
    }
    $text = trim($text);
    return $text !== '' ? ereview_chat_safe_trim($text, 4500) : null;
}

/**
 * @return array{text: string, intent: string, confidence: float, matched_article_id: int, needs_human: int, actions: array, auto_create_ticket?: bool, ticket_category?: string}
 */
function ereview_chat_generate_reply(mysqli $conn, string $message, string $sessionId, bool $plainLanguage = false): array
{
    [$intent, $intentConfidence] = ereview_chat_detect_intent($message);
    $retrieved = ereview_chat_retrieve_best_doc($conn, $message);
    $doc = $retrieved['doc'];
    $ragScore = (float) ($retrieved['score'] ?? 0.0);
    $confidence = max(0.0, min(1.0, ($intentConfidence * 0.55) + ($ragScore * 0.45)));

    $topDocs = ereview_chat_retrieve_top_documents($conn, $message, 5);

    $globalBanned = ereview_chat_global_banned_lines($conn);
    foreach (array_slice($topDocs, 0, 3) as $row) {
        $ab = trim((string) (($row['doc']['article_banned_topics'] ?? '') ?: ''));
        if ($ab !== '') {
            $parts = array_map('trim', explode(',', $ab));
            $parts = array_filter($parts, static fn ($x) => $x !== '');
            $low = array_map(static fn ($x) => mb_strtolower($x), $parts);
            if (ereview_chat_message_hits_banned_phrases($message, $low)) {
                return [
                    'text' => 'That topic needs a direct reply from our team. Please use Talk to Human or email contact@ereview.ph.',
                    'intent' => 'blocked_article',
                    'confidence' => 0.95,
                    'matched_article_id' => (int) ($row['doc']['article_id'] ?? 0),
                    'needs_human' => 1,
                    'actions' => [
                        ['label' => 'Talk to Human', 'type' => 'handoff', 'value' => 'handoff'],
                        ['label' => 'Email', 'type' => 'link', 'value' => 'mailto:contact@ereview.ph'],
                    ],
                    'auto_create_ticket' => false,
                ];
            }
        }
    }

    if (ereview_chat_message_hits_banned_phrases($message, $globalBanned)) {
        return [
            'text' => 'I cannot help with that topic here. Please email contact@ereview.ph or use Talk to Human so our team can assist you properly.',
            'intent' => 'blocked',
            'confidence' => 0.99,
            'matched_article_id' => 0,
            'needs_human' => 1,
            'actions' => [
                ['label' => 'Talk to Human', 'type' => 'handoff', 'value' => 'handoff'],
                ['label' => 'Email', 'type' => 'link', 'value' => 'mailto:contact@ereview.ph'],
            ],
            'auto_create_ticket' => false,
        ];
    }

    $lang = ereview_chat_detect_language_code($message);
    $flow = ereview_chat_detect_conversation_flow($message, $intent);
    $accountSensitive = ereview_chat_is_account_sensitive($message);
    $pkgInterest = ereview_chat_infer_package_interest($message);
    $topic = ereview_chat_topic_from_intent($intent);

    $mem = ereview_chat_session_load_memory($conn, $sessionId);
    $resolvedTopic = $topic !== 'general' ? $topic : ($mem['last_topic'] ?? 'general');
    $resolvedPkg = $pkgInterest ?? ($mem['package_interest'] ?? null);
    ereview_chat_session_write_memory($conn, $sessionId, $resolvedTopic, $resolvedPkg, $lang, $flow);

    $memoryBlock = 'last_topic=' . $resolvedTopic
        . '; package_interest=' . ($resolvedPkg ?? 'none')
        . '; language=' . $lang
        . '; flow=' . $flow
        . '. Follow-up questions may refer to this topic without repeating keywords.';
    if ($plainLanguage) {
        $memoryBlock .= "\nUSER PREFERENCE: Use very simple words and short sentences. Avoid jargon unless quoting official names.";
    }

    $needsHuman = ($confidence < 0.5 || $intent === 'human_handoff' || $accountSensitive) ? 1 : 0;

    if ($intent === 'human_handoff') {
        return [
            'text' => 'I can connect you with our support team now. Tap "Talk to Human" and I will create a ticket with your chat transcript.',
            'intent' => $intent,
            'confidence' => $confidence,
            'matched_article_id' => 0,
            'needs_human' => 1,
            'actions' => [
                ['label' => 'Talk to Human', 'type' => 'handoff', 'value' => 'handoff'],
                ['label' => 'Email Support', 'type' => 'link', 'value' => 'mailto:contact@ereview.ph'],
            ],
            'auto_create_ticket' => false,
        ];
    }

    if ($intent === 'meta_bot') {
        $asksName = (bool) preg_match(
            '/\b(what\s+is\s+your\s+name|what\'?s\s+your\s+name|whats\s+your\s+name|do\s+you\s+have\s+a\s+name|what\s+should\s+i\s+call\s+you)\b/i',
            $message
        );
        $text = $asksName
            ? "I'm LCRC Support Assistant—an automated helper for LCRC eReview (not a human staff member). You can still reach our team anytime through Talk to Human or email contact@ereview.ph."
            : "I'm an automated assistant for LCRC eReview—not a human—but I'm here to help with packages, registration, video lessons, enrollment, and common questions. For anything sensitive or account-specific, tap Talk to Human or email contact@ereview.ph.";
        return [
            'text' => $text,
            'intent' => $intent,
            'confidence' => 0.95,
            'matched_article_id' => 0,
            'needs_human' => 0,
            'actions' => ereview_chat_quick_actions($intent),
            'auto_create_ticket' => false,
        ];
    }
    if ($intent === 'meta_language') {
        return [
            'text' => "Yes—you can ask in Tagalog or Filipino. I will do my best to answer in Tagalog. For official details (packages, prices, payments), I may repeat important lines in English so nothing gets lost in translation. For account-specific help, Talk to Human or contact@ereview.ph works best.\n\nOo, puwede kang magtanong sa Tagalog. Susubukan kong sumagot sa Tagalog. Para sa presyo, bayad, at enrollment, puwede kong isama ang English sa mahahalagang detalye. Kung kailangan ng staff, gamitin ang Talk to Human.",
            'intent' => $intent,
            'confidence' => 0.94,
            'matched_article_id' => 0,
            'needs_human' => 0,
            'actions' => ereview_chat_quick_actions($intent),
            'auto_create_ticket' => false,
        ];
    }
    if ($intent === 'learning_content') {
        return [
            'text' => 'Yes. LCRC eReview includes video lectures and related materials (such as handouts) inside the platform for enrolled students. The homepage also has free sample videos you can check without signing up. After you register and your access is approved, you get the full lesson library for your package. For subject-specific details, Talk to Human can route you to the team.',
            'intent' => $intent,
            'confidence' => 0.92,
            'matched_article_id' => 0,
            'needs_human' => 0,
            'actions' => ereview_chat_quick_actions('learning_content'),
            'auto_create_ticket' => false,
        ];
    }
    if ($intent === 'greeting') {
        return [
            'text' => "Hello! I'm LCRC Support Assistant. Ask about packages, registration, video lectures, payments, or account access—or tell me what you need.",
            'intent' => $intent,
            'confidence' => 0.9,
            'matched_article_id' => 0,
            'needs_human' => 0,
            'actions' => ereview_chat_quick_actions($intent),
            'auto_create_ticket' => false,
        ];
    }
    if ($intent === 'thanks') {
        return [
            'text' => "You're welcome! If you need anything else about LCRC eReview, just ask.",
            'intent' => $intent,
            'confidence' => 0.9,
            'matched_article_id' => 0,
            'needs_human' => 0,
            'actions' => ereview_chat_quick_actions($intent),
            'auto_create_ticket' => false,
        ];
    }
    if ($intent === 'goodbye') {
        return [
            'text' => 'Take care—and good luck with your review. Come back anytime if you have more questions!',
            'intent' => $intent,
            'confidence' => 0.9,
            'matched_article_id' => 0,
            'needs_human' => 0,
            'actions' => ereview_chat_quick_actions($intent),
            'auto_create_ticket' => false,
        ];
    }

    $kbOk = $doc && !empty($doc['content']) && ereview_chat_kb_match_is_strong_enough($intent, $retrieved);
    $kbContext = ereview_chat_build_strict_kb_context($topDocs);
    $historyBlock = ereview_chat_format_history_for_llm($conn, $sessionId, 10);

    $ai = ereview_chat_openai_grounded_reply(
        $message,
        $kbContext,
        $historyBlock,
        $memoryBlock,
        $lang,
        $flow,
        $accountSensitive
    );

    $autoTicket = false;
    $ticketCat = $flow;
    if ($accountSensitive) {
        $autoTicket = true;
    }
    if (!$ai && $ragScore < 0.15 && $intent === 'unknown') {
        $autoTicket = true;
    }

    if ($ai !== null) {
        $nh = $needsHuman;
        if ($accountSensitive) {
            $nh = 1;
        }
        if ($ragScore < 0.12 && !$kbOk) {
            $nh = 1;
        }
        return [
            'text' => $ai,
            'intent' => $intent,
            'confidence' => max($confidence, 0.72),
            'matched_article_id' => $kbOk && $doc ? (int) ($doc['article_id'] ?? 0) : 0,
            'needs_human' => $nh,
            'actions' => ereview_chat_quick_actions($intent),
            'auto_create_ticket' => $autoTicket,
            'ticket_category' => $ticketCat,
        ];
    }

    if ($kbOk && $doc && !empty($doc['content'])) {
        $answer = trim($doc['content']);
        if ($confidence < 0.55) {
            $answer .= " If this doesn't fully answer your concern, I can connect you to a live support agent.";
        }
        return [
            'text' => $answer,
            'intent' => $intent,
            'confidence' => $confidence,
            'matched_article_id' => (int) ($doc['article_id'] ?? 0),
            'needs_human' => $needsHuman,
            'actions' => ereview_chat_quick_actions($intent),
            'auto_create_ticket' => $autoTicket,
            'ticket_category' => $ticketCat,
        ];
    }

    $fallback = "I'm not sure about that specific detail from our published information. For accurate help with your situation, please use Talk to Human (we can attach this chat) or email contact@ereview.ph.";
    if ($lang === 'tl' || $lang === 'mixed') {
        $fallback .= ' Puwede rin sa Tagalog: hindi ako sigurado sa eksaktong detalyeng iyan; pakiusap Talk to Human o email.';
    }

    return [
        'text' => $fallback,
        'intent' => 'unknown',
        'confidence' => 0.35,
        'matched_article_id' => 0,
        'needs_human' => 1,
        'actions' => ereview_chat_quick_actions('general'),
        'auto_create_ticket' => true,
        'ticket_category' => $ticketCat,
    ];
}

function ereview_chat_create_escalation_ticket(
    mysqli $conn,
    string $sessionId,
    ?int $userId,
    string $category,
    string $subject,
    string $transcript
): int {
    $subject = ereview_chat_safe_trim($subject, 250);
    if ($subject === '') {
        $subject = 'Chat escalation — ' . $category;
    }
    $name = '';
    $email = '';
    if ($userId) {
        $stmt = mysqli_prepare($conn, 'SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $u = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if ($u) {
            $name = (string) ($u['full_name'] ?? '');
            $email = (string) ($u['email'] ?? '');
        }
    }

    if (ereview_chat_v2_ready($conn)) {
        $cat = ereview_chat_safe_trim($category, 40);
        $tex = ereview_chat_safe_trim($transcript, 65000);
        if ($userId === null) {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO support_tickets (session_id, user_id, requester_name, requester_email, subject, category, transcript_excerpt, status, priority, created_at, updated_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, 'open', 'normal', NOW(), NOW())"
            );
            mysqli_stmt_bind_param($stmt, 'ssssss', $sessionId, $name, $email, $subject, $cat, $tex);
        } else {
            $uid = (int) $userId;
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO support_tickets (session_id, user_id, requester_name, requester_email, subject, category, transcript_excerpt, status, priority, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', 'normal', NOW(), NOW())"
            );
            mysqli_stmt_bind_param($stmt, 'sisssss', $sessionId, $uid, $name, $email, $subject, $cat, $tex);
        }
        mysqli_stmt_execute($stmt);
        $tid = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        $up = mysqli_prepare($conn, "UPDATE support_chat_sessions SET status='handoff', handoff_requested=1 WHERE session_id = ? LIMIT 1");
        mysqli_stmt_bind_param($up, 's', $sessionId);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
        return $tid;
    }

    return ereview_chat_create_or_update_ticket($conn, $sessionId, $userId, $name, $email, $subject . "\n\n" . mb_substr($transcript, 0, 2000));
}
