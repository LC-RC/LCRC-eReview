<?php
declare(strict_types=1);

/**
 * Optional third-party hooks (helpdesk, CRM). Configure via environment or local overrides.
 * Do not store secrets in this file; use chat_openai.local.php pattern or server env.
 */

function ereview_chat_integration_ticket_url(int $ticketId): ?string
{
    $base = getenv('EREVIEW_HELPDESK_TICKET_URL_BASE');
    if (!is_string($base) || $base === '') {
        return null;
    }
    return rtrim($base, '/') . '/' . $ticketId;
}

function ereview_chat_integration_crm_contact_url(string $email): ?string
{
    $base = getenv('EREVIEW_CRM_CONTACT_SEARCH_URL');
    if (!is_string($base) || $base === '') {
        return null;
    }
    return $base . (str_contains($base, '?') ? '&' : '?') . 'email=' . rawurlencode($email);
}
