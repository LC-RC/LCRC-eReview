<?php
declare(strict_types=1);

/**
 * Legacy SSE endpoint — disabled on purpose.
 *
 * Long-running SSE loops tie up PHP-FPM workers for tens of seconds per browser tab,
 * which exhausts the pool and makes normal page navigations queue for minutes.
 *
 * The messaging UI uses short polling only while the messages panel is open (see
 * messaging_component.php). Do not re-enable blocking loops here without a dedicated
 * async worker or reverse-proxy SSE offload.
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if (!isLoggedIn() || !verifySession()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'SSE endpoint disabled. Messaging uses panel-only polling.',
], JSON_UNESCAPED_UNICODE);
exit;
