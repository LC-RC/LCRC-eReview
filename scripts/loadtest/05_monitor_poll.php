<?php
/**
 * Professor monitor live poller (grace-period observer).
 *
 * Polls professor_examination_monitor_live while recording any attempt that flips
 * to submitted before expires_at + 60s without a prior client submit OK.
 *
 * Env:
 *   LOADTEST_MONITOR_SECONDS=120
 *   LOADTEST_MONITOR_INTERVAL_MS=3000
 *   LOADTEST_GRACE_SECONDS=60
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

[$conn, $dbName] = loadtest_connect();

$runId = loadtest_env('LOADTEST_RUN_ID', '');
if ($runId === null || $runId === '') {
    $latest = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'LATEST_RUN_ID';
    $runId = is_file($latest) ? trim((string)file_get_contents($latest)) : '';
}
$runId = loadtest_run_id($runId ?: null);

$sessions = loadtest_read_json(loadtest_artifact_path($runId, 'sessions.json'));
$attemptsDoc = loadtest_read_json(loadtest_artifact_path($runId, 'attempts.json'));
$seed = loadtest_read_json(loadtest_artifact_path($runId, 'seed.json'));
$examId = (int)($seed['exam_id'] ?? 0);
$base = rtrim((string)($attemptsDoc['base_url'] ?? ''), '/');
if ($base === '') {
    $base = loadtest_require_base_url();
} else {
    $envBase = loadtest_require_base_url();
    if ($base !== $envBase) {
        loadtest_fail("attempts base_url '{$base}' !== LOADTEST_BASE_URL '{$envBase}'");
    }
}
$liveUrl = $base . '/examination/professor/professor_examination_monitor_live.php?exam_type=regular&exam_id=' . $examId;

$prof = $sessions['professor'] ?? [];
$sid = (string)($prof['PHPSESSID'] ?? '');
if ($sid === '') {
    loadtest_fail('Missing professor PHPSESSID in sessions.json — run 02_bootstrap_sessions.php');
}

$duration = max(10, (int)loadtest_env('LOADTEST_MONITOR_SECONDS', '120'));
$intervalMs = max(500, (int)loadtest_env('LOADTEST_MONITOR_INTERVAL_MS', '3000'));
$grace = max(0, (int)loadtest_env('LOADTEST_GRACE_SECONDS', '60'));

$attemptMeta = [];
foreach ($attemptsDoc['attempts'] as $a) {
    $aid = (int)($a['attempt_id'] ?? 0);
    if ($aid <= 0) {
        continue;
    }
    $attemptMeta[$aid] = [
        'user_id' => (int)($a['user_id'] ?? 0),
        'expires_at' => (string)($a['expires_at'] ?? ''),
        'client_submitted_ok' => false,
    ];
}

$clientSubmitLog = loadtest_artifact_path($runId, 'client_submit_ok.json');
if (is_file($clientSubmitLog)) {
    $okMap = loadtest_read_json($clientSubmitLog);
    foreach ($okMap as $aidStr => $flag) {
        $aid = (int)$aidStr;
        if (isset($attemptMeta[$aid]) && $flag) {
            $attemptMeta[$aid]['client_submitted_ok'] = true;
        }
    }
}

$violations = [];
$samples = [];
$endAt = time() + $duration;
loadtest_ok("Monitor polling {$liveUrl} for {$duration}s every {$intervalMs}ms (grace={$grace}s)");

while (time() <= $endAt) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Cookie: PHPSESSID={$sid}\r\nUser-Agent: EreviewLoadTestMonitor/1.0\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($liveUrl, false, $ctx);
    $status = '000';
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = $m[1];
    }
    $decoded = is_string($body) ? json_decode($body, true) : null;
    $now = date('c');
    $samples[] = [
        'at' => $now,
        'http_status' => $status,
        'ok' => is_array($decoded) && !empty($decoded['ok']),
        'auto_finalized' => is_array($decoded) ? ($decoded['auto_finalized'] ?? $decoded['metrics']['auto_finalized'] ?? null) : null,
    ];

    // DB truth for grace violations
    if ($attemptMeta !== []) {
        $ids = implode(',', array_map('intval', array_keys($attemptMeta)));
        $q = mysqli_query(
            $conn,
            "SELECT attempt_id, status, expires_at, submitted_at FROM college_exam_attempts WHERE attempt_id IN ({$ids})"
        );
        while ($q && ($row = mysqli_fetch_assoc($q))) {
            $aid = (int)$row['attempt_id'];
            if (($row['status'] ?? '') !== 'submitted') {
                continue;
            }
            $meta = $attemptMeta[$aid] ?? null;
            if (!$meta) {
                continue;
            }
            if (!empty($meta['client_submitted_ok'])) {
                continue;
            }
            $expTs = strtotime((string)($row['expires_at'] ?? $meta['expires_at'] ?? ''));
            $subTs = strtotime((string)($row['submitted_at'] ?? ''));
            if ($expTs === false || $subTs === false) {
                continue;
            }
            if ($subTs < ($expTs + $grace)) {
                $key = (string)$aid;
                if (!isset($violations[$key])) {
                    $violations[$key] = [
                        'attempt_id' => $aid,
                        'user_id' => $meta['user_id'],
                        'expires_at' => date('c', $expTs),
                        'submitted_at' => date('c', $subTs),
                        'grace_deadline' => date('c', $expTs + $grace),
                        'detected_at' => $now,
                        'note' => 'submitted before expires_at+grace without recorded client submit ok',
                    ];
                }
            }
        }
        if ($q) {
            mysqli_free_result($q);
        }
    }

    usleep($intervalMs * 1000);
}

$report = [
    'run_id' => $runId,
    'db' => $dbName,
    'exam_id' => $examId,
    'grace_seconds' => $grace,
    'samples' => $samples,
    'grace_violations' => array_values($violations),
    'violation_count' => count($violations),
];
loadtest_write_json(loadtest_artifact_path($runId, 'monitor.json'), $report);

if ($violations !== []) {
    loadtest_fail('Monitor grace violations detected: ' . count($violations) . ' (see monitor.json)');
}

loadtest_ok('Monitor poll complete with 0 grace violations');
mysqli_close($conn);
