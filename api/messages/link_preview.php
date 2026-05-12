<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ereview_msg_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!isLoggedIn() || !verifySession()) {
    ereview_msg_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '' || !preg_match('#^https?://#i', $url)) {
    ereview_msg_json(['ok' => false, 'error' => 'Invalid URL'], 422);
}
$parts = @parse_url($url);
$host = strtolower((string)($parts['host'] ?? ''));
if ($host === '') {
    ereview_msg_json(['ok' => false, 'error' => 'Invalid host'], 422);
}
$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    ereview_msg_json(['ok' => false, 'error' => 'Host not allowed'], 422);
}

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 2.2,
        'follow_location' => 1,
        'max_redirects' => 3,
        'user_agent' => 'EreviewLinkPreview/1.0',
    ],
]);
$html = @file_get_contents($url, false, $ctx, 0, 120000);
$title = $host;
if (is_string($html) && preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m)) {
    $parsed = trim(html_entity_decode(strip_tags((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($parsed !== '') {
        $title = $parsed;
    }
}
ereview_msg_json([
    'ok' => true,
    'preview' => [
        'url' => $url,
        'domain' => $host,
        'title' => $title,
    ],
]);
