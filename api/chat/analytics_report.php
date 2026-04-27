<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/chat_support_helpers.php';

if (!isLoggedIn() || !verifySession() || !hasRole('admin')) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}
if (!ereview_chat_tables_ready($conn)) {
    ereview_chat_json_response(['ok' => false, 'error' => 'Support chat is not initialized. Please run migration 015 first.'], 503);
}

$days = (int)($_GET['days'] ?? 7);
if ($days < 1) {
    $days = 1;
}
if ($days > 60) {
    $days = 60;
}

$sinceExpr = "DATE_SUB(NOW(), INTERVAL {$days} DAY)";

$totals = [
    'sessions' => 0,
    'messages' => 0,
    'handoffs' => 0,
    'unanswered' => 0,
];

$q1 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_chat_sessions WHERE created_at >= $sinceExpr");
if ($q1 && ($r = mysqli_fetch_assoc($q1))) {
    $totals['sessions'] = (int)$r['cnt'];
}

$q2 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_chat_messages WHERE created_at >= $sinceExpr");
if ($q2 && ($r = mysqli_fetch_assoc($q2))) {
    $totals['messages'] = (int)$r['cnt'];
}

$q3 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_analytics_events WHERE event_type='handoff_requested' AND created_at >= $sinceExpr");
if ($q3 && ($r = mysqli_fetch_assoc($q3))) {
    $totals['handoffs'] = (int)$r['cnt'];
}

$q4 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_analytics_events WHERE event_type='unanswered_detected' AND created_at >= $sinceExpr");
if ($q4 && ($r = mysqli_fetch_assoc($q4))) {
    $totals['unanswered'] = (int)$r['cnt'];
}

$unanswered = [];
$uq = @mysqli_query(
    $conn,
    "SELECT m.session_id, m.message_text, m.intent, m.confidence_score, m.created_at
     FROM support_chat_messages m
     WHERE m.needs_human = 1 AND m.role = 'assistant' AND m.created_at >= $sinceExpr
     ORDER BY m.created_at DESC
     LIMIT 50"
);
if ($uq) {
    while ($r = mysqli_fetch_assoc($uq)) {
        $unanswered[] = [
            'session_id' => (string)$r['session_id'],
            'message_text' => (string)$r['message_text'],
            'intent' => (string)$r['intent'],
            'confidence' => (float)$r['confidence_score'],
            'created_at' => (string)$r['created_at'],
        ];
    }
}

$csatAvg = null;
$csatN = 0;
$cs = @mysqli_query(
    $conn,
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_chat_csat' LIMIT 1"
);
if ($cs && mysqli_fetch_row($cs)) {
    mysqli_free_result($cs);
    $cq = @mysqli_query($conn, "SELECT AVG(rating) AS a, COUNT(*) AS n FROM support_chat_csat WHERE created_at >= $sinceExpr");
    if ($cq && ($cr = mysqli_fetch_assoc($cq))) {
        $csatAvg = isset($cr['a']) && $cr['a'] !== null ? round((float)$cr['a'], 2) : null;
        $csatN = (int)($cr['n'] ?? 0);
    }
} elseif ($cs) {
    mysqli_free_result($cs);
}

$panelOpens = 0;
$panelCloses = 0;
$pq = @mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM support_analytics_events WHERE event_type = 'chat_panel_open' AND created_at >= $sinceExpr"
);
if ($pq && ($pr = mysqli_fetch_assoc($pq))) {
    $panelOpens = (int)($pr['c'] ?? 0);
}
$pq2 = @mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM support_analytics_events WHERE event_type = 'chat_panel_close' AND created_at >= $sinceExpr"
);
if ($pq2 && ($pr2 = mysqli_fetch_assoc($pq2))) {
    $panelCloses = (int)($pr2['c'] ?? 0);
}

$dropoffRate = null;
if ($panelOpens > 0) {
    $dropoffRate = round(max(0, $panelOpens - $panelCloses) / $panelOpens, 4);
}

$handoffRate = $totals['sessions'] > 0
    ? round($totals['handoffs'] / $totals['sessions'], 4)
    : 0.0;

$topIntents = [];
$iq = @mysqli_query(
    $conn,
    "SELECT intent, COUNT(*) AS c FROM support_chat_messages WHERE role = 'assistant' AND created_at >= $sinceExpr GROUP BY intent ORDER BY c DESC LIMIT 12"
);
if ($iq) {
    while ($row = mysqli_fetch_assoc($iq)) {
        $topIntents[] = ['intent' => (string)$row['intent'], 'count' => (int)$row['c']];
    }
}

$topUserSamples = [];
$uq2 = @mysqli_query(
    $conn,
    "SELECT message_text, COUNT(*) AS c FROM support_chat_messages WHERE role = 'user' AND created_at >= $sinceExpr GROUP BY message_text ORDER BY c DESC LIMIT 15"
);
if ($uq2) {
    while ($row = mysqli_fetch_assoc($uq2)) {
        $topUserSamples[] = [
            'sample' => mb_substr((string)$row['message_text'], 0, 200),
            'count' => (int)$row['c'],
        ];
    }
}

ereview_chat_json_response([
    'ok' => true,
    'days' => $days,
    'totals' => $totals,
    'unanswered' => $unanswered,
    'csat' => ['avg' => $csatAvg, 'count' => $csatN],
    'panel' => ['opens' => $panelOpens, 'closes' => $panelCloses, 'dropoff_rate' => $dropoffRate],
    'rates' => ['handoff_rate' => $handoffRate],
    'top_intents' => $topIntents,
    'top_user_messages' => $topUserSamples,
]);
