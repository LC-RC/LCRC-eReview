<?php
declare(strict_types=1);

/**
 * LCRC support chat helper functions:
 * - lightweight retrieval ("RAG-like") from support_kb_articles
 * - intent detection
 * - reply composition with quick action buttons
 * - analytics and ticket lifecycle helpers
 */

function ereview_chat_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload);
    exit;
}

function ereview_chat_tables_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tables = [
        'support_kb_articles',
        'support_chat_sessions',
        'support_chat_messages',
        'support_tickets',
        'support_analytics_events',
    ];
    foreach ($tables as $table) {
        $safe = mysqli_real_escape_string($conn, $table);
        $res = @mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
        if (!$res || !mysqli_fetch_row($res)) {
            $ready = false;
            return false;
        }
    }
    $ready = true;
    return true;
}

function ereview_chat_v2_ready(mysqli $conn): bool
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $r = @mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_chat_sessions' AND COLUMN_NAME = 'last_topic' LIMIT 1"
    );
    $v = ($r && mysqli_fetch_row($r)) ? true : false;
    if ($r) {
        mysqli_free_result($r);
    }
    return $v;
}

function ereview_chat_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function ereview_chat_safe_trim(string $value, int $maxLen): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen);
    }
    return substr($value, 0, $maxLen);
}

function ereview_chat_detect_intent(string $message): array
{
    $t = strtolower(trim($message));
    // Order matters: meta + handoff + business topics before loose "hi"/"thanks" patterns.
    $rules = [
        'meta_bot' => '/\b(are you (an? )?(ai|a bot|a robot|real)|who are you|what are you|is this (a )?bot|chatbot|artificial intelligence|openai|gpt|what\s+is\s+your\s+name|what\'?s\s+your\s+name|whats\s+your\s+name|do\s+you\s+have\s+a\s+name|what\s+should\s+i\s+call\s+you|your\s+name\s*\?)\b/',
        'meta_language' => '/\b(tagalog|filipino|pilipino|bisaya|cebuano|ilocano|taglish|filipino language)\b|\b(in|using|speak|talk)\s+(tagalog|filipino|pilipino)\b|\b(tagalog|filipino)\s*(please|po|ba)\b|\b(mag-?tagalog|salitang filipino)\b/i',
        'human_handoff' => '/\b(talk to (a )?human|speak to (a )?human|live agent|representative|live support|customer service|open (a )?ticket|create (a )?ticket|support ticket|escalate|handoff)\b/i',
        'learning_content' => '/\b(video|videos|lecture|lectures|lesson|lessons|handout|handouts|study materials|course materials|streaming|mock exam|mock exams|quiz|quizzes|test bank|preboard|pre-week|sample videos|free sample|samples)\b|is\s+there\s+(a\s+)?(video|videos)\b/i',
        'packages' => '/\b(package|packages|price|pricing|cost|fee|plan|plans|promo)\b/',
        'registration' => '/\b(register|registration|sign up|signup|enroll|enrollment|how to join)\b/',
        'payment' => '/\b(payment|pay|gcash|bank|proof of payment|receipt|invoice)\b/',
        'account_issue' => '/\b(login|password|locked|forgot|lost access|no access|access issue|cannot access|cant access)\b/i',
        'faq' => '/\b(faq|frequently asked|how does (it|this) work)\b/',
        'thanks' => '/\b(thanks|thank you|ty|salamat|appreciate it)\b/',
        'goodbye' => '/\b(bye|goodbye|see you|later)\b/',
        // Only treat as pure greeting when the message is basically just hello/hi (not "hi, how much...")
        'greeting' => '/^(hello|hi|hey|good morning|good afternoon|good evening)[\s!?.,]*$/i',
    ];
    foreach ($rules as $intent => $pattern) {
        if (preg_match($pattern, $t)) {
            return [$intent, 0.88];
        }
    }
    return ['unknown', 0.35];
}

function ereview_chat_tokenize(string $text): array
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text) ?? '';
    $parts = preg_split('/\s+/', trim($text)) ?: [];
    $stop = [
        'the' => true, 'and' => true, 'for' => true, 'with' => true, 'from' => true,
        'that' => true, 'this' => true, 'are' => true, 'you' => true, 'your' => true,
        'what' => true, 'when' => true, 'where' => true, 'how' => true, 'can' => true,
        'about' => true, 'have' => true, 'has' => true, 'was' => true, 'were' => true,
        'will' => true, 'our' => true, 'please' => true, 'help' => true, 'lcrc' => true,
        'a' => true, 'an' => true, 'is' => true, 'it' => true, 'its' => true, "it's" => true,
        'im' => true, "i'm" => true, 'my' => true, 'me' => true, 'we' => true, 'us' => true,
        'be' => true, 'to' => true, 'of' => true, 'in' => true, 'on' => true, 'at' => true,
        'do' => true, 'does' => true, 'did' => true, 'am' => true, 'i' => true,
        'if' => true, 'so' => true, 'no' => true, 'yes' => true, 'not' => true, 'or' => true,
        'as' => true, 'by' => true, 'any' => true, 'some' => true, 'just' => true, 'very' => true,
    ];
    $tokens = [];
    foreach ($parts as $p) {
        if ($p === '' || isset($stop[$p]) || strlen($p) < 2) {
            continue;
        }
        $tokens[] = $p;
    }
    return $tokens;
}

/**
 * True if $token appears as a whole word in $haystack (avoids "ai" matching inside "details").
 */
function ereview_chat_token_matches_as_word(string $haystack, string $token): bool
{
    $token = strtolower(trim($token));
    if ($token === '' || strlen($token) < 2) {
        return false;
    }
    $quoted = preg_quote($token, '/');
    return (bool) preg_match('/\b' . $quoted . '\b/u', $haystack);
}

function ereview_chat_get_or_create_session(mysqli $conn, string $sessionId, ?int $userId = null): array
{
    $sessionId = ereview_chat_safe_trim($sessionId, 64);
    if ($sessionId === '') {
        $sessionId = ereview_chat_uuid_v4();
    }

    $stmt = mysqli_prepare($conn, "SELECT session_id, status, handoff_requested FROM support_chat_sessions WHERE session_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $sessionId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if ($row) {
        return $row;
    }

    $status = 'open';
    $uid = $userId ?: null;
    $insert = mysqli_prepare(
        $conn,
        "INSERT INTO support_chat_sessions (session_id, user_id, status, source_page, created_at, last_message_at) VALUES (?, ?, ?, 'home', NOW(), NOW())"
    );
    mysqli_stmt_bind_param($insert, 'sis', $sessionId, $uid, $status);
    mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);

    return [
        'session_id' => $sessionId,
        'status' => $status,
        'handoff_requested' => 0,
    ];
}

function ereview_chat_fetch_recent_messages(mysqli $conn, string $sessionId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $sql = "SELECT role, message_text, created_at FROM support_chat_messages WHERE session_id = ? ORDER BY message_id DESC LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $sessionId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);

    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = [
                'role' => (string)($r['role'] ?? 'assistant'),
                'text' => (string)($r['message_text'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        mysqli_free_result($res);
    }

    return array_reverse($rows);
}

function ereview_chat_insert_message(
    mysqli $conn,
    string $sessionId,
    string $role,
    string $message,
    string $intent = 'unknown',
    float $confidence = 0.0,
    ?int $matchedArticleId = null,
    int $needsHuman = 0
): void {
    $role = in_array($role, ['user', 'assistant', 'agent', 'system'], true) ? $role : 'assistant';
    $message = ereview_chat_safe_trim($message, 5000);
    $intent = ereview_chat_safe_trim($intent, 60);
    $needsHuman = $needsHuman ? 1 : 0;
    $article = $matchedArticleId ?: null;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO support_chat_messages (session_id, role, message_text, intent, confidence_score, matched_article_id, needs_human, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    mysqli_stmt_bind_param($stmt, 'ssssdii', $sessionId, $role, $message, $intent, $confidence, $article, $needsHuman);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $up = mysqli_prepare($conn, "UPDATE support_chat_sessions SET last_message_at = NOW() WHERE session_id = ? LIMIT 1");
    mysqli_stmt_bind_param($up, 's', $sessionId);
    mysqli_stmt_execute($up);
    mysqli_stmt_close($up);
}

function ereview_chat_log_event(mysqli $conn, string $sessionId, string $eventType, array $payload = []): void
{
    $eventType = ereview_chat_safe_trim($eventType, 60);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO support_analytics_events (session_id, event_type, payload_json, created_at) VALUES (?, ?, ?, NOW())"
    );
    mysqli_stmt_bind_param($stmt, 'sss', $sessionId, $eventType, $json);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function ereview_chat_fetch_kb_documents(mysqli $conn): array
{
    $docs = [];
    $v2 = ereview_chat_v2_ready($conn);
    $sql = $v2
        ? "SELECT article_id, title, content, keywords, short_answer, approved_phrases, article_banned_topics FROM support_kb_articles WHERE status='active' ORDER BY article_id DESC"
        : "SELECT article_id, title, content, keywords FROM support_kb_articles WHERE status='active' ORDER BY article_id DESC";
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $docs[] = [
                'article_id' => (int) ($r['article_id'] ?? 0),
                'title' => (string) ($r['title'] ?? ''),
                'content' => (string) ($r['content'] ?? ''),
                'keywords' => (string) ($r['keywords'] ?? ''),
                'short_answer' => (string) ($r['short_answer'] ?? ''),
                'approved_phrases' => (string) ($r['approved_phrases'] ?? ''),
                'article_banned_topics' => (string) ($r['article_banned_topics'] ?? ''),
            ];
        }
        mysqli_free_result($res);
    }

    if (!$docs) {
        $docs[] = [
            'article_id' => 0,
            'title' => 'Packages',
            'content' => 'LCRC eReview packages: 6-month ₱1,500, 9-month ₱2,000, 14-month ₱2,500.',
            'keywords' => 'package,price,plan,enroll',
            'short_answer' => '',
            'approved_phrases' => '',
            'article_banned_topics' => '',
        ];
        $docs[] = [
            'article_id' => 0,
            'title' => 'Registration',
            'content' => 'To register, create an account, fill the form, upload payment proof, then wait for admin approval.',
            'keywords' => 'register,registration,payment proof,approval',
            'short_answer' => '',
            'approved_phrases' => '',
            'article_banned_topics' => '',
        ];
    }

    return $docs;
}

function ereview_chat_retrieve_best_doc(mysqli $conn, string $question): array
{
    $docs = ereview_chat_fetch_kb_documents($conn);
    $qTokens = ereview_chat_tokenize($question);
    $qMap = array_fill_keys($qTokens, true);

    $best = ['score' => 0.0, 'doc' => null, 'long_hits' => 0];
    foreach ($docs as $doc) {
        $haystack = strtolower($doc['title'] . ' ' . $doc['content'] . ' ' . $doc['keywords'] . ' ' . ($doc['short_answer'] ?? ''));
        $score = 0.0;
        $longHits = 0;
        foreach ($qMap as $tok => $_) {
            if (!ereview_chat_token_matches_as_word($haystack, $tok)) {
                continue;
            }
            // Weight longer tokens more; 2-letter words are easy to false-match even with \b in edge cases.
            $w = strlen($tok) >= 4 ? 1.2 : (strlen($tok) >= 3 ? 1.0 : 0.45);
            $score += $w;
            if (strlen($tok) >= 3) {
                $longHits++;
            }
        }
        if (!empty($qTokens)) {
            $score = $score / max(1, count($qTokens));
        }
        if ($score > $best['score']) {
            $best = ['score' => $score, 'doc' => $doc, 'long_hits' => $longHits];
        }
    }
    return $best;
}

/**
 * Whether KB text is safe to show for this question (guards weak / unrelated matches).
 */
function ereview_chat_kb_match_is_strong_enough(string $intent, array $retrieved): bool
{
    $score = (float)($retrieved['score'] ?? 0.0);
    $longHits = (int)($retrieved['long_hits'] ?? 0);
    $businessIntents = ['packages', 'registration', 'payment', 'account_issue', 'faq', 'learning_content'];

    if ($score <= 0.0) {
        return false;
    }
    if (in_array($intent, $businessIntents, true)) {
        return $longHits >= 1 || $score >= 0.45;
    }
    // Unknown / meta / thanks: require clearer lexical overlap with the article.
    return $longHits >= 1 && $score >= 0.35;
}

/**
 * OpenAI credentials for support chat. PHP does NOT inherit `export` from an SSH shell.
 * Use one of: Apache SetEnv / php-fpm Environment= / includes/chat_openai.local.php
 *
 * chat_openai.local.php must return: array{ openai_api_key?: string, api_key?: string, model?: string }
 */
function ereview_chat_get_openai_settings(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $pick = static function (string $name): string {
        $v = getenv($name);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        if (!empty($_SERVER[$name]) && is_string($_SERVER[$name]) && trim($_SERVER[$name]) !== '') {
            return trim($_SERVER[$name]);
        }
        if (!empty($_ENV[$name]) && is_string($_ENV[$name]) && trim($_ENV[$name]) !== '') {
            return trim($_ENV[$name]);
        }
        return '';
    };

    $key = $pick('EREVIEW_OPENAI_API_KEY');
    if ($key === '') {
        $key = $pick('OPENAI_API_KEY');
    }

    $model = $pick('EREVIEW_OPENAI_MODEL');
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $local = __DIR__ . '/chat_openai.local.php';
    if (is_readable($local)) {
        $cfg = include $local;
        if (is_array($cfg)) {
            if (!empty($cfg['openai_api_key']) && is_string($cfg['openai_api_key']) && trim($cfg['openai_api_key']) !== '') {
                $key = trim($cfg['openai_api_key']);
            } elseif (!empty($cfg['api_key']) && is_string($cfg['api_key']) && trim($cfg['api_key']) !== '') {
                $key = trim($cfg['api_key']);
            }
            if (!empty($cfg['model']) && is_string($cfg['model']) && trim($cfg['model']) !== '') {
                $model = trim($cfg['model']);
            }
        }
    }

    $cached = ['key' => $key, 'model' => $model];
    return $cached;
}

function ereview_chat_quick_actions(string $intent = 'general'): array
{
    $base = [
        ['label' => 'View Packages', 'type' => 'link', 'value' => '#packages'],
        ['label' => 'FAQs', 'type' => 'link', 'value' => '#faqs'],
        ['label' => 'How to Register', 'type' => 'link', 'value' => 'registration.php'],
        ['label' => 'Talk to Human', 'type' => 'handoff', 'value' => 'handoff'],
    ];
    if ($intent === 'learning_content') {
        return [
            ['label' => 'Free Sample Videos', 'type' => 'link', 'value' => '#free-samples'],
            ['label' => 'View Packages', 'type' => 'link', 'value' => '#packages'],
            ['label' => 'Talk to Human', 'type' => 'handoff', 'value' => 'handoff'],
        ];
    }
    if (in_array($intent, ['meta_bot', 'meta_language', 'thanks', 'goodbye', 'greeting', 'unknown'], true)) {
        return $base;
    }
    if ($intent === 'packages') {
        return [
            ['label' => 'See Packages', 'type' => 'link', 'value' => '#packages'],
            ['label' => 'Enroll Now', 'type' => 'link', 'value' => 'registration.php'],
            ['label' => 'Ask Human Advisor', 'type' => 'handoff', 'value' => 'handoff'],
        ];
    }
    return $base;
}

function ereview_chat_create_or_update_ticket(
    mysqli $conn,
    string $sessionId,
    ?int $userId,
    string $name,
    string $email,
    string $subject
): int {
    $name = ereview_chat_safe_trim($name, 140);
    $email = ereview_chat_safe_trim($email, 190);
    $subject = ereview_chat_safe_trim($subject, 250);
    if ($subject === '') {
        $subject = 'Support chat escalation';
    }

    $check = mysqli_prepare($conn, "SELECT ticket_id FROM support_tickets WHERE session_id = ? ORDER BY ticket_id DESC LIMIT 1");
    mysqli_stmt_bind_param($check, 's', $sessionId);
    mysqli_stmt_execute($check);
    $res = mysqli_stmt_get_result($check);
    $existing = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($check);

    if ($existing) {
        $ticketId = (int)$existing['ticket_id'];
        $up = mysqli_prepare($conn, "UPDATE support_tickets SET status='open', updated_at=NOW() WHERE ticket_id = ? LIMIT 1");
        mysqli_stmt_bind_param($up, 'i', $ticketId);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
        return $ticketId;
    }

    $status = 'open';
    $priority = 'normal';
    $ins = mysqli_prepare(
        $conn,
        "INSERT INTO support_tickets (session_id, user_id, requester_name, requester_email, subject, status, priority, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    mysqli_stmt_bind_param($ins, 'sisssss', $sessionId, $userId, $name, $email, $subject, $status, $priority);
    mysqli_stmt_execute($ins);
    $ticketId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    $up2 = mysqli_prepare($conn, "UPDATE support_chat_sessions SET status='handoff', handoff_requested=1 WHERE session_id = ? LIMIT 1");
    mysqli_stmt_bind_param($up2, 's', $sessionId);
    mysqli_stmt_execute($up2);
    mysqli_stmt_close($up2);

    return $ticketId;
}

require_once __DIR__ . '/chat_support_ai_engine.php';
