<?php
/**
 * Manual sort_order for lessons and quizzes within a subject.
 * Used by Content Hub admin reorder + student subject listings.
 */

if (!function_exists('content_sort_order_column_exists')) {
    function content_sort_order_column_exists(mysqli $conn, string $table, string $column, bool $refresh = false): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (!$refresh && array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return $cache[$key] = false;
        }
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '" . mysqli_real_escape_string($conn, $safeColumn) . "'");
        $ok = $res && mysqli_num_rows($res) > 0;
        if ($res) {
            mysqli_free_result($res);
        }
        return $cache[$key] = $ok;
    }
}

if (!function_exists('content_sort_order_ensure_schema')) {
    /**
     * Ensure sort_order exists and backfill once when all values are still 0.
     */
    function content_sort_order_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (!content_sort_order_column_exists($conn, 'lessons', 'sort_order')) {
            @mysqli_query(
                $conn,
                'ALTER TABLE `lessons`
                 ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `description`'
            );
            @mysqli_query($conn, 'ALTER TABLE `lessons` ADD KEY `idx_lessons_subject_sort` (`subject_id`, `sort_order`)');
            content_sort_order_column_exists($conn, 'lessons', 'sort_order', true);
        }

        if (!content_sort_order_column_exists($conn, 'quizzes', 'sort_order')) {
            @mysqli_query(
                $conn,
                'ALTER TABLE `quizzes`
                 ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `title`'
            );
            @mysqli_query($conn, 'ALTER TABLE `quizzes` ADD KEY `idx_quizzes_subject_sort` (`subject_id`, `sort_order`)');
            content_sort_order_column_exists($conn, 'quizzes', 'sort_order', true);
        }

        content_sort_order_backfill_if_needed($conn, 'lessons', 'lesson_id', 'ASC');
        // Preserve prior student quiz listing (quiz_id DESC).
        content_sort_order_backfill_if_needed($conn, 'quizzes', 'quiz_id', 'DESC');
    }
}

if (!function_exists('content_sort_order_backfill_if_needed')) {
    function content_sort_order_backfill_if_needed(mysqli $conn, string $table, string $idCol, string $idDir): void
    {
        $table = preg_replace('/[^a-z0-9_]/i', '', $table);
        $idCol = preg_replace('/[^a-z0-9_]/i', '', $idCol);
        $idDir = strtoupper($idDir) === 'DESC' ? 'DESC' : 'ASC';
        if ($table === '' || $idCol === '') {
            return;
        }
        if (!content_sort_order_column_exists($conn, $table, 'sort_order')) {
            return;
        }

        // Only backfill rows that are still at the default (0) and the table
        // has no positive sort_order yet — avoids clobbering admin edits.
        $chk = @mysqli_query(
            $conn,
            "SELECT
                SUM(CASE WHEN sort_order > 0 THEN 1 ELSE 0 END) AS positive_cnt,
                COUNT(*) AS total_cnt
             FROM `{$table}`"
        );
        $row = $chk ? mysqli_fetch_assoc($chk) : null;
        if ($chk) {
            mysqli_free_result($chk);
        }
        $positive = (int) ($row['positive_cnt'] ?? 0);
        $total = (int) ($row['total_cnt'] ?? 0);
        if ($total <= 0 || $positive > 0) {
            return;
        }

        $subjects = @mysqli_query($conn, "SELECT DISTINCT subject_id FROM `{$table}` ORDER BY subject_id ASC");
        if (!$subjects) {
            return;
        }
        while ($s = mysqli_fetch_assoc($subjects)) {
            $sid = (int) ($s['subject_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $ord = 0;
            $q = mysqli_prepare(
                $conn,
                "SELECT `{$idCol}` AS id FROM `{$table}` WHERE subject_id = ? ORDER BY `{$idCol}` {$idDir}"
            );
            if (!$q) {
                continue;
            }
            mysqli_stmt_bind_param($q, 'i', $sid);
            mysqli_stmt_execute($q);
            $res = mysqli_stmt_get_result($q);
            $ids = [];
            if ($res) {
                while ($r = mysqli_fetch_assoc($res)) {
                    $ids[] = (int) ($r['id'] ?? 0);
                }
            }
            mysqli_stmt_close($q);
            $upd = mysqli_prepare(
                $conn,
                "UPDATE `{$table}` SET sort_order = ? WHERE `{$idCol}` = ? AND subject_id = ?"
            );
            if (!$upd) {
                continue;
            }
            foreach ($ids as $id) {
                if ($id <= 0) {
                    continue;
                }
                $ord++;
                mysqli_stmt_bind_param($upd, 'iii', $ord, $id, $sid);
                mysqli_stmt_execute($upd);
            }
            mysqli_stmt_close($upd);
        }
        mysqli_free_result($subjects);
    }
}

if (!function_exists('content_sort_order_next')) {
    /** Next sort_order for a new lesson/quiz in a subject (append to end). */
    function content_sort_order_next(mysqli $conn, string $table, int $subjectId): int
    {
        content_sort_order_ensure_schema($conn);
        $table = preg_replace('/[^a-z0-9_]/i', '', $table);
        if ($table === '' || $subjectId <= 0) {
            return 1;
        }
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_ord FROM `{$table}` WHERE subject_id = ?"
        );
        if (!$stmt) {
            return 1;
        }
        mysqli_stmt_bind_param($stmt, 'i', $subjectId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return max(1, (int) ($row['next_ord'] ?? 1));
    }
}

if (!function_exists('content_sort_order_save')) {
    /**
     * Persist full ordered id list for a subject.
     * @param list<int> $orderedIds
     * @return array{ok:bool, error?:string, count?:int}
     */
    function content_sort_order_save(mysqli $conn, string $table, string $idCol, int $subjectId, array $orderedIds): array
    {
        content_sort_order_ensure_schema($conn);
        $table = preg_replace('/[^a-z0-9_]/i', '', $table);
        $idCol = preg_replace('/[^a-z0-9_]/i', '', $idCol);
        if ($table === '' || $idCol === '' || $subjectId <= 0) {
            return ['ok' => false, 'error' => 'Invalid reorder request.'];
        }

        $clean = [];
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        if ($clean === []) {
            return ['ok' => false, 'error' => 'No items to reorder.'];
        }

        $existing = [];
        $stmt = mysqli_prepare($conn, "SELECT `{$idCol}` AS id FROM `{$table}` WHERE subject_id = ?");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not load items.'];
        }
        mysqli_stmt_bind_param($stmt, 'i', $subjectId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $existing[] = (int) ($r['id'] ?? 0);
            }
        }
        mysqli_stmt_close($stmt);
        sort($existing);
        $check = $clean;
        sort($check);
        if ($existing !== $check) {
            return ['ok' => false, 'error' => 'Order list does not match this subject. Refresh and try again.'];
        }

        $upd = mysqli_prepare(
            $conn,
            "UPDATE `{$table}` SET sort_order = ? WHERE `{$idCol}` = ? AND subject_id = ?"
        );
        if (!$upd) {
            return ['ok' => false, 'error' => 'Could not save order.'];
        }
        $ok = true;
        $ord = 0;
        mysqli_begin_transaction($conn);
        try {
            foreach ($clean as $id) {
                $ord++;
                mysqli_stmt_bind_param($upd, 'iii', $ord, $id, $subjectId);
                if (!mysqli_stmt_execute($upd)) {
                    $ok = false;
                    break;
                }
            }
            mysqli_stmt_close($upd);
            if ($ok) {
                mysqli_commit($conn);
                return ['ok' => true, 'count' => $ord];
            }
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Could not save order.'];
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Could not save order.'];
        }
    }
}

if (!function_exists('content_sort_order_sql')) {
    /** ORDER BY clause fragment once schema is ensured. */
    function content_sort_order_sql(string $alias, string $idCol): string
    {
        $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);
        $idCol = preg_replace('/[^a-z0-9_]/i', '', $idCol);
        $prefix = $alias !== '' ? $alias . '.' : '';
        return "{$prefix}sort_order ASC, {$prefix}{$idCol} ASC";
    }
}
