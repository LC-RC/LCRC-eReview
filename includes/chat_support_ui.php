<?php
declare(strict_types=1);

/**
 * Rich chat UI payloads: package cards, payment hints, quick replies, next steps.
 */

function ereview_chat_package_cards(): array
{
    return [
        [
            'id' => 'pkg-6',
            'title' => '6-month access',
            'subtitle' => 'Structured review track + materials',
            'price' => 'PHP 1,500',
            'cta_label' => 'See details',
            'href' => '#packages',
        ],
        [
            'id' => 'pkg-9',
            'title' => '9-month access',
            'subtitle' => 'Extended runway for busy schedules',
            'price' => 'PHP 2,000',
            'cta_label' => 'See details',
            'href' => '#packages',
        ],
        [
            'id' => 'pkg-14',
            'title' => '14-month access',
            'subtitle' => 'Maximum flexibility',
            'price' => 'PHP 2,500',
            'cta_label' => 'See details',
            'href' => '#packages',
        ],
    ];
}

function ereview_chat_payment_hint_cards(): array
{
    return [
        [
            'id' => 'pay-proof',
            'title' => 'Payment proof',
            'subtitle' => 'Upload proof after registering so admin can approve access.',
            'cta_label' => 'Registration',
            'href' => 'registration.php',
        ],
        [
            'id' => 'pay-contact',
            'title' => 'Billing questions',
            'subtitle' => 'For receipt or payment issues, email or talk to staff.',
            'cta_label' => 'Email',
            'href' => 'mailto:contact@ereview.ph',
        ],
    ];
}

/** Deep-link chips (conversion + FAQs). */
function ereview_chat_default_quick_links(): array
{
    return [
        ['label' => 'Packages', 'type' => 'link', 'value' => '#packages'],
        ['label' => 'FAQs', 'type' => 'link', 'value' => '#faqs'],
        ['label' => 'Register', 'type' => 'link', 'value' => 'registration.php'],
        ['label' => 'Free samples', 'type' => 'link', 'value' => '#free-samples'],
    ];
}

/**
 * @param array<string, mixed> $reply
 * @return array<string, mixed>
 */
function ereview_chat_enrich_reply_ui(array $reply, string $intent): array
{
    $reply['cards'] = $reply['cards'] ?? [];
    $reply['quick_replies'] = [];

    if (in_array($intent, ['packages', 'registration'], true)) {
        $reply['cards'] = array_merge($reply['cards'], ereview_chat_package_cards());
        $reply['next_step'] = 'Compare packages below, then start registration when you are ready.';
        $reply['quick_replies'] = [
            ['label' => 'Compare packages', 'type' => 'link', 'value' => '#packages'],
            ['label' => 'FAQs', 'type' => 'link', 'value' => '#faqs'],
            ['label' => 'Start registration', 'type' => 'link', 'value' => 'registration.php'],
        ];
    } elseif ($intent === 'payment') {
        $reply['cards'] = array_merge($reply['cards'], ereview_chat_payment_hint_cards());
        $reply['next_step'] = 'After paying, upload proof during registration so we can activate your access.';
        $reply['quick_replies'] = ereview_chat_default_quick_links();
    } elseif ($intent === 'learning_content') {
        $reply['quick_replies'] = [
            ['label' => 'Free samples', 'type' => 'link', 'value' => '#free-samples'],
            ['label' => 'Packages', 'type' => 'link', 'value' => '#packages'],
            ['label' => 'FAQs', 'type' => 'link', 'value' => '#faqs'],
        ];
    } else {
        $reply['quick_replies'] = ereview_chat_default_quick_links();
    }

    return $reply;
}

function ereview_chat_kb_backlog_table_ready(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_kb_backlog' LIMIT 1"
    );
    $cache = (bool) ($r && mysqli_fetch_row($r));
    if ($r) {
        mysqli_free_result($r);
    }
    return $cache;
}

function ereview_chat_maybe_queue_kb_backlog(
    mysqli $conn,
    string $sessionId,
    string $userMessage,
    string $intent,
    float $confidence,
    int $needsHuman
): void {
    if (!ereview_chat_kb_backlog_table_ready($conn)) {
        return;
    }
    if ($intent === 'human_handoff') {
        return;
    }
    if (!$needsHuman) {
        return;
    }
    if ($confidence >= 0.55 && !in_array($intent, ['unknown', 'blocked', 'blocked_article'], true)) {
        return;
    }
    $snippet = ereview_chat_safe_trim($userMessage, 500);
    if ($snippet === '') {
        return;
    }
    $chk = mysqli_prepare(
        $conn,
        'SELECT backlog_id FROM support_kb_backlog WHERE session_id = ? AND sample_question = ? LIMIT 1'
    );
    if (!$chk) {
        return;
    }
    mysqli_stmt_bind_param($chk, 'ss', $sessionId, $snippet);
    mysqli_stmt_execute($chk);
    $res = mysqli_stmt_get_result($chk);
    $exists = $res && mysqli_fetch_row($res);
    mysqli_stmt_close($chk);
    if ($exists) {
        return;
    }
    $st = mysqli_prepare(
        $conn,
        "INSERT INTO support_kb_backlog (session_id, sample_question, intent, confidence, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())"
    );
    if (!$st) {
        return;
    }
    mysqli_stmt_bind_param($st, 'sssd', $sessionId, $snippet, $intent, $confidence);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}
