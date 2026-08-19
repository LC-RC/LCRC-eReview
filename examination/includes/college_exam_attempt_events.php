<?php
/**
 * College exam attempt monitoring events (tab visibility, future signals).
 * Aggregate counters on college_exam_attempts remain the source of cheap KPIs.
 */

/**
 * Ensure college_exam_attempt_events exists (idempotent).
 */
function college_exam_attempt_events_ensure_schema(mysqli $conn): void
{
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `college_exam_attempt_events` (
      `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
      `attempt_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `exam_id` int(11) NOT NULL,
      `event_type` varchar(32) NOT NULL,
      `occurred_at` datetime NOT NULL,
      `meta_json` longtext NULL,
      PRIMARY KEY (`event_id`),
      KEY `idx_ceae_attempt_time` (`attempt_id`,`occurred_at`),
      KEY `idx_ceae_exam_time` (`exam_id`,`occurred_at`),
      KEY `idx_ceae_type` (`event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * @param array<string,mixed>|null $meta
 */
function college_exam_attempt_event_record(
    mysqli $conn,
    int $attemptId,
    int $userId,
    int $examId,
    string $eventType,
    ?array $meta = null
): bool {
    $type = strtolower(trim($eventType));
    if ($attemptId <= 0 || $userId <= 0 || $examId <= 0 || $type === '') {
        return false;
    }
    if (strlen($type) > 32) {
        $type = substr($type, 0, 32);
    }
    college_exam_attempt_events_ensure_schema($conn);
    $occurred = date('Y-m-d H:i:s');
    $metaJson = null;
    if ($meta !== null) {
        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $metaJson = $encoded;
        }
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO college_exam_attempt_events (attempt_id, user_id, exam_id, event_type, occurred_at, meta_json) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'iiisss', $attemptId, $userId, $examId, $type, $occurred, $metaJson);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return (bool)$ok;
}

/**
 * @return list<array<string,mixed>>
 */
function college_exam_attempt_events_list(mysqli $conn, int $attemptId, int $limit = 100): array
{
    if ($attemptId <= 0) {
        return [];
    }
    college_exam_attempt_events_ensure_schema($conn);
    $limit = max(1, min(500, $limit));
    $rows = [];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT event_id, attempt_id, user_id, exam_id, event_type, occurred_at, meta_json
         FROM college_exam_attempt_events
         WHERE attempt_id=?
         ORDER BY occurred_at ASC, event_id ASC
         LIMIT ' . (int)$limit
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $attemptId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

/**
 * Last event type for an attempt (any type), or null.
 */
function college_exam_attempt_events_last_type(mysqli $conn, int $attemptId): ?string
{
    if ($attemptId <= 0) {
        return null;
    }
    college_exam_attempt_events_ensure_schema($conn);
    $stmt = mysqli_prepare(
        $conn,
        'SELECT event_type FROM college_exam_attempt_events WHERE attempt_id=? ORDER BY occurred_at DESC, event_id DESC LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $attemptId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $t = strtolower(trim((string)($row['event_type'] ?? '')));

    return $t !== '' ? $t : null;
}

/**
 * Last occurred_at for a given event type on an attempt.
 */
function college_exam_attempt_events_last_at(mysqli $conn, int $attemptId, string $eventType): ?string
{
    if ($attemptId <= 0 || $eventType === '') {
        return null;
    }
    college_exam_attempt_events_ensure_schema($conn);
    $stmt = mysqli_prepare(
        $conn,
        'SELECT occurred_at FROM college_exam_attempt_events WHERE attempt_id=? AND event_type=? ORDER BY occurred_at DESC, event_id DESC LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'is', $attemptId, $eventType);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $at = (string)($row['occurred_at'] ?? '');

    return $at !== '' ? $at : null;
}

/**
 * Full activity timeline for professor detail (exam_started, tab events, submit, etc.).
 *
 * @param list<array<string,mixed>> $events
 * @return list<array{label:string,occurred_at:?string,occurred_fmt:string,away_seconds:?int,event_type:string,detail:?string}>
 */
function college_exam_attempt_events_activity_timeline(array $events): array
{
    $out = [];
    $pendingHiddenAt = null;
    foreach ($events as $ev) {
        $type = strtolower(trim((string)($ev['event_type'] ?? '')));
        $at = (string)($ev['occurred_at'] ?? '');
        $ts = $at !== '' ? strtotime($at) : false;
        $fmt = ($ts !== false) ? date('g:i:s A', $ts) : $at;
        $meta = [];
        $rawMeta = (string)($ev['meta_json'] ?? '');
        if ($rawMeta !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        if ($type === 'exam_started') {
            $out[] = ['label' => 'Exam started', 'occurred_at' => $at ?: null, 'occurred_fmt' => $fmt, 'away_seconds' => null, 'event_type' => $type, 'detail' => null];
            continue;
        }
        if ($type === 'exam_submitted' || $type === 'exam_finalized') {
            $out[] = ['label' => 'Exam submitted', 'occurred_at' => $at ?: null, 'occurred_fmt' => $fmt, 'away_seconds' => null, 'event_type' => $type, 'detail' => null];
            continue;
        }
        if ($type === 'exam_expired') {
            $out[] = ['label' => 'Exam expired / auto-submitted', 'occurred_at' => $at ?: null, 'occurred_fmt' => $fmt, 'away_seconds' => null, 'event_type' => $type, 'detail' => null];
            continue;
        }
        if ($type === 'tab_hidden') {
            $pendingHiddenAt = $ts !== false ? $ts : null;
            $out[] = ['label' => 'Tab left', 'occurred_at' => $at ?: null, 'occurred_fmt' => $fmt, 'away_seconds' => null, 'event_type' => $type, 'detail' => null];
            continue;
        }
        if ($type === 'tab_visible') {
            $away = null;
            if (isset($meta['away_seconds']) && is_numeric($meta['away_seconds'])) {
                $away = max(0, (int)$meta['away_seconds']);
            } elseif ($pendingHiddenAt !== null && $ts !== false) {
                $away = max(0, $ts - $pendingHiddenAt);
            }
            $pendingHiddenAt = null;
            $detail = $away !== null ? ('Away for ' . $away . ' second' . ($away === 1 ? '' : 's')) : null;
            $out[] = ['label' => 'Returned to exam', 'occurred_at' => $at ?: null, 'occurred_fmt' => $fmt, 'away_seconds' => $away, 'event_type' => $type, 'detail' => $detail];
            continue;
        }
        if ($type === 'window_blurred' || $type === 'window_focused') {
            // Supplemental only — skip from primary timeline to avoid noise.
            continue;
        }
        $out[] = ['label' => $type, 'occurred_at' => $at ?: null, 'occurred_fmt' => $fmt, 'away_seconds' => null, 'event_type' => $type, 'detail' => null];
    }

    return $out;
}

/**
 * Pair tab_hidden / tab_visible into timeline rows with optional away duration.
 *
 * @param list<array<string,mixed>> $events
 * @return list<array{label:string,occurred_at:?string,occurred_fmt:string,away_seconds:?int,event_type:string}>
 */
function college_exam_attempt_events_tab_timeline(array $events): array
{
    $full = college_exam_attempt_events_activity_timeline($events);
    $out = [];
    foreach ($full as $row) {
        if (!in_array($row['event_type'], ['tab_hidden', 'tab_visible'], true)) {
            continue;
        }
        $out[] = [
            'label' => $row['label'],
            'occurred_at' => $row['occurred_at'],
            'occurred_fmt' => $row['occurred_fmt'],
            'away_seconds' => $row['away_seconds'],
            'event_type' => $row['event_type'],
        ];
    }

    return $out;
}
