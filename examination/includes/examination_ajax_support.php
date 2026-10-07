<?php
/**
 * Shared examination AJAX helpers: JSON errors + structured logging (no secrets/payloads).
 */

function examination_ajax_log(string $channel, array $ctx): void
{
    $safe = [
        'channel' => $channel,
        'ts' => date('c'),
        'action' => (string)($ctx['action'] ?? ''),
        'user_id' => (int)($ctx['user_id'] ?? 0),
        'attempt_id' => (int)($ctx['attempt_id'] ?? 0),
        'duration_ms' => isset($ctx['duration_ms']) ? (int)$ctx['duration_ms'] : null,
        'ok' => array_key_exists('ok', $ctx) ? (!empty($ctx['ok']) ? 1 : 0) : null,
        'http' => isset($ctx['http']) ? (int)$ctx['http'] : null,
        'errno' => isset($ctx['errno']) ? (int)$ctx['errno'] : null,
        'error' => isset($ctx['error']) ? substr((string)$ctx['error'], 0, 240) : null,
        'exception' => isset($ctx['exception']) ? substr((string)$ctx['exception'], 0, 240) : null,
        'txn' => isset($ctx['txn']) ? (int)$ctx['txn'] : null,
        'finalized' => isset($ctx['finalized']) ? (int)$ctx['finalized'] : null,
    ];
    error_log('[exam_ajax] ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * @param array<string,mixed> $payload
 */
function examination_ajax_json_exit(array $payload, int $http = 200): void
{
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
