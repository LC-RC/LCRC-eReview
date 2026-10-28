<?php
declare(strict_types=1);

/**
 * Explicit-ID college exam access activate/deactivate with prior-state audit.
 * Does not change users.status, student_number, section, school, or attempts.
 */

require_once __DIR__ . '/college_school_assignment.php';

function college_exam_access_ops_tables_ready(mysqli $conn): bool
{
    $a = @mysqli_query($conn, "SHOW TABLES LIKE 'college_exam_access_bulk_ops'");
    $okA = $a && mysqli_fetch_row($a);
    if ($a) {
        mysqli_free_result($a);
    }
    $b = @mysqli_query($conn, "SHOW TABLES LIKE 'college_exam_access_bulk_op_rows'");
    $okB = $b && mysqli_fetch_row($b);
    if ($b) {
        mysqli_free_result($b);
    }

    return (bool) $okA && (bool) $okB;
}

function college_exam_access_school_mapped(?string $school): bool
{
    return trim((string) $school) !== '';
}

/**
 * Restore the exact recorded prior_access. Returns null if the stored value is invalid.
 */
function college_exam_access_rollback_target(?string $priorAccess): ?string
{
    $prior = strtolower(trim((string) $priorAccess));

    return in_array($prior, ['none', 'active', 'suspended'], true) ? $prior : null;
}

/**
 * Refuse if a later audit op exists for the user, or current access is not the value this op wrote.
 */
function college_exam_access_rollback_row_stale_error(
    int $userId,
    int $sourceOpId,
    int $latestOpIdForUser,
    string $currentAccess,
    string $opNewAccess
): ?string {
    if ($latestOpIdForUser > $sourceOpId) {
        return 'Access rollback refused: user #' . $userId
            . ' has a later access operation (#' . $latestOpIdForUser . '). Reverse the latest operation first.';
    }
    if ($currentAccess !== $opNewAccess) {
        return 'Access rollback refused: user #' . $userId
            . ' current access "' . $currentAccess . '" does not match operation #' . $sourceOpId
            . ' new_access "' . $opNewAccess . '".';
    }

    return null;
}

/**
 * Staff-assigned school only. Legacy users.school text is not enough.
 *
 * @param array<int, array<string,mixed>> $found
 * @return list<int>
 */
function college_exam_access_unmapped_ids(array $found): array
{
    $ids = [];
    foreach ($found as $uid => $row) {
        $assigned = function_exists('college_school_row_is_staff_assigned')
            ? college_school_row_is_staff_assigned($row)
            : false;
        if (!$assigned) {
            $ids[] = (int) $uid;
        }
    }

    return $ids;
}

/**
 * @param array<int, array<string,mixed>> $found keyed by user_id
 * @param list<int> $userIds
 * @return array{ok:bool, error?:string, op_id?:int, changed_count?:int, unchanged_count?:int, user_ids?:list<int>}
 */
function college_exam_access_apply_for_ids(
    mysqli $conn,
    array $userIds,
    array $found,
    string $newAccess,
    string $action,
    int $actorUserId
): array {
    if (!in_array($newAccess, ['active', 'suspended'], true)) {
        return ['ok' => false, 'error' => 'Invalid access target.'];
    }
    if (!in_array($action, ['activate', 'deactivate', 'rollback'], true)) {
        return ['ok' => false, 'error' => 'Invalid access action.'];
    }
    if (!ereview_platform_access_columns_ready($conn)) {
        return ['ok' => false, 'error' => 'College Examination access columns are not available.'];
    }
    if (!college_exam_access_ops_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'Access audit tables are not installed. Apply migration 052 before activating or deactivating exam access.'];
    }
    if (function_exists('college_school_assignment_schema_ready') && !college_school_assignment_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'Staff school assignment is not installed. Apply migration 053, then assign schools before changing exam access.'];
    }

    $unmapped = college_exam_access_unmapped_ids($found);
    if ($unmapped !== []) {
        return [
            'ok' => false,
            'error' => 'Cannot include students without a staff-assigned school (user IDs: ' . implode(', ', $unmapped) . '). Assign a school first. Legacy school text is not enough.',
        ];
    }

    $changes = [];
    foreach ($userIds as $uid) {
        if (!isset($found[$uid])) {
            return ['ok' => false, 'error' => 'User #' . $uid . ' was not found.'];
        }
        $row = $found[$uid];
        $role = (string) ($row['role'] ?? '');
        $status = strtolower((string) ($row['status'] ?? ''));
        if ($role !== 'college_student' && $role !== 'student') {
            return ['ok' => false, 'error' => 'User #' . $uid . ' is not an examination student account.'];
        }
        if ($role === 'student' && in_array($status, ['rejected', 'archived'], true)) {
            return ['ok' => false, 'error' => 'User #' . $uid . ' cannot change exam access while ' . $status . '.'];
        }
        if ($role === 'student') {
            $current = ereview_user_college_examination_access_value($row);
            if (!in_array($current, ['active', 'suspended'], true)) {
                return ['ok' => false, 'error' => 'User #' . $uid . ' is not on the College Examination roster. Use Enable College Examination first.'];
            }
        }
        $stored = ereview_user_college_examination_access_value($row);
        if ($stored === $newAccess) {
            continue;
        }
        $changes[] = [
            'user_id' => $uid,
            'role' => $role,
            'school' => trim((string) ($row['school'] ?? '')),
            'prior' => $stored,
        ];
    }

    if ($changes === []) {
        return [
            'ok' => true,
            'op_id' => 0,
            'changed_count' => 0,
            'unchanged_count' => count($userIds),
            'user_ids' => $userIds,
        ];
    }

    $insOp = mysqli_prepare(
        $conn,
        'INSERT INTO college_exam_access_bulk_ops (action, actor_user_id, created_at, note) VALUES (?, ?, NOW(), NULL)'
    );
    if (!$insOp) {
        return ['ok' => false, 'error' => 'Could not write access audit header.'];
    }
    mysqli_stmt_bind_param($insOp, 'si', $action, $actorUserId);
    if (!mysqli_stmt_execute($insOp)) {
        mysqli_stmt_close($insOp);

        return ['ok' => false, 'error' => 'Could not write access audit header.'];
    }
    mysqli_stmt_close($insOp);
    $opId = (int) mysqli_insert_id($conn);
    if ($opId < 1) {
        return ['ok' => false, 'error' => 'Could not capture access audit id.'];
    }

    $insRow = mysqli_prepare(
        $conn,
        'INSERT INTO college_exam_access_bulk_op_rows (op_id, user_id, role, school_snapshot, prior_access, new_access)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$insRow) {
        return ['ok' => false, 'error' => 'Could not write access audit rows.'];
    }
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET college_examination_access=?
         WHERE user_id=? AND role IN ('college_student','student') LIMIT 1"
    );
    if (!$upd) {
        mysqli_stmt_close($insRow);

        return ['ok' => false, 'error' => 'Could not prepare access update.'];
    }

    foreach ($changes as $ch) {
        $uid = (int) $ch['user_id'];
        $role = (string) $ch['role'];
        $school = (string) $ch['school'];
        $prior = (string) $ch['prior'];
        mysqli_stmt_bind_param($insRow, 'iissss', $opId, $uid, $role, $school, $prior, $newAccess);
        if (!mysqli_stmt_execute($insRow)) {
            mysqli_stmt_close($insRow);
            mysqli_stmt_close($upd);

            return ['ok' => false, 'error' => 'Could not write access audit for user #' . $uid . '.'];
        }
        mysqli_stmt_bind_param($upd, 'si', $newAccess, $uid);
        if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
            mysqli_stmt_close($insRow);
            mysqli_stmt_close($upd);

            return ['ok' => false, 'error' => 'Could not update exam access for user #' . $uid . '.'];
        }
    }
    mysqli_stmt_close($insRow);
    mysqli_stmt_close($upd);

    return [
        'ok' => true,
        'op_id' => $opId,
        'changed_count' => count($changes),
        'unchanged_count' => count($userIds) - count($changes),
        'user_ids' => $userIds,
    ];
}

/**
 * Load an activate/deactivate operation for rollback. Caller must lock users then call rollback_locked.
 *
 * @return array{ok:bool, error?:string, user_ids?:list<int>, rows?:list<array<string,mixed>>}
 */
function college_exam_access_load_op_for_rollback(mysqli $conn, int $opId): array
{
    if ($opId < 1) {
        return ['ok' => false, 'error' => 'Invalid operation id.'];
    }
    if (!college_exam_access_ops_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'Access audit tables are not installed. Apply migration 052 first.'];
    }

    $head = mysqli_prepare($conn, 'SELECT op_id, action FROM college_exam_access_bulk_ops WHERE op_id=? LIMIT 1');
    if (!$head) {
        return ['ok' => false, 'error' => 'Could not load access operation.'];
    }
    mysqli_stmt_bind_param($head, 'i', $opId);
    mysqli_stmt_execute($head);
    $op = mysqli_fetch_assoc(mysqli_stmt_get_result($head));
    mysqli_stmt_close($head);
    if (!$op) {
        return ['ok' => false, 'error' => 'Access operation #' . $opId . ' was not found.'];
    }
    if ((string) ($op['action'] ?? '') === 'rollback') {
        return ['ok' => false, 'error' => 'That operation is already a rollback.'];
    }

    $rowsSt = mysqli_prepare(
        $conn,
        'SELECT user_id, prior_access, new_access FROM college_exam_access_bulk_op_rows WHERE op_id=? ORDER BY row_id ASC'
    );
    if (!$rowsSt) {
        return ['ok' => false, 'error' => 'Could not load access operation rows.'];
    }
    mysqli_stmt_bind_param($rowsSt, 'i', $opId);
    mysqli_stmt_execute($rowsSt);
    $res = mysqli_stmt_get_result($rowsSt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($rowsSt);
    if ($rows === []) {
        return ['ok' => false, 'error' => 'Access operation #' . $opId . ' has no rows to restore.'];
    }

    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int) $r['user_id'];
    }

    return ['ok' => true, 'user_ids' => $ids, 'rows' => $rows];
}

/**
 * @param list<int> $ids
 * @param list<array<string,mixed>> $rows
 * @param array<int, array<string,mixed>> $found
 * @return array{ok:bool, error?:string, op_id?:int, restored_count?:int, source_op_id?:int, user_ids?:list<int>}
 */
function college_exam_access_rollback_locked(
    mysqli $conn,
    int $opId,
    int $actorUserId,
    array $ids,
    array $rows,
    array $found
): array {
    $restoreFound = [];
    $restoreIds = [];
    $latestByUser = [];
    $affectedIds = [];
    foreach ($rows as $scan) {
        $sid = (int) ($scan['user_id'] ?? 0);
        if ($sid > 0) {
            $affectedIds[$sid] = $sid;
        }
    }
    $affectedIds = array_values($affectedIds);
    if ($affectedIds !== []) {
        $placeholders = implode(',', array_fill(0, count($affectedIds), '?'));
        $types = str_repeat('i', count($affectedIds));
        $latestSt = mysqli_prepare(
            $conn,
            "SELECT user_id, MAX(op_id) AS max_op
             FROM college_exam_access_bulk_op_rows
             WHERE user_id IN ({$placeholders})
             GROUP BY user_id"
        );
        if (!$latestSt) {
            return ['ok' => false, 'error' => 'Could not verify access operation order.'];
        }
        mysqli_stmt_bind_param($latestSt, $types, ...$affectedIds);
        mysqli_stmt_execute($latestSt);
        $latestRes = mysqli_stmt_get_result($latestSt);
        while ($lr = mysqli_fetch_assoc($latestRes)) {
            $latestByUser[(int) $lr['user_id']] = (int) $lr['max_op'];
        }
        mysqli_stmt_close($latestSt);
    }

    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $prior = college_exam_access_rollback_target((string) ($r['prior_access'] ?? ''));
        if ($prior === null) {
            return ['ok' => false, 'error' => 'Invalid prior access stored for user #' . $uid . '.'];
        }
        $opNew = college_exam_access_rollback_target((string) ($r['new_access'] ?? ''));
        if ($opNew === null) {
            return ['ok' => false, 'error' => 'Invalid new_access stored for user #' . $uid . '.'];
        }
        if (!isset($found[$uid])) {
            return ['ok' => false, 'error' => 'User #' . $uid . ' from operation #' . $opId . ' was not found.'];
        }
        $row = $found[$uid];
        $current = ereview_user_college_examination_access_value($row);
        $latestOp = $latestByUser[$uid] ?? 0;
        $staleErr = college_exam_access_rollback_row_stale_error($uid, $opId, $latestOp, $current, $opNew);
        if ($staleErr !== null) {
            return ['ok' => false, 'error' => $staleErr];
        }
        $row['_rollback_to'] = $prior;
        $restoreFound[$uid] = $row;
        $restoreIds[] = $uid;
    }

    $insOp = mysqli_prepare(
        $conn,
        'INSERT INTO college_exam_access_bulk_ops (action, actor_user_id, created_at, note) VALUES (\'rollback\', ?, NOW(), ?)'
    );
    if (!$insOp) {
        return ['ok' => false, 'error' => 'Could not write rollback audit header.'];
    }
    $note = 'rollback of op_id=' . $opId;
    mysqli_stmt_bind_param($insOp, 'is', $actorUserId, $note);
    if (!mysqli_stmt_execute($insOp)) {
        mysqli_stmt_close($insOp);

        return ['ok' => false, 'error' => 'Could not write rollback audit header.'];
    }
    mysqli_stmt_close($insOp);
    $newOpId = (int) mysqli_insert_id($conn);

    $insRow = mysqli_prepare(
        $conn,
        'INSERT INTO college_exam_access_bulk_op_rows (op_id, user_id, role, school_snapshot, prior_access, new_access)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET college_examination_access=?
         WHERE user_id=? AND role IN ('college_student','student') LIMIT 1"
    );
    if (!$insRow || !$upd) {
        if ($insRow) {
            mysqli_stmt_close($insRow);
        }
        if ($upd) {
            mysqli_stmt_close($upd);
        }

        return ['ok' => false, 'error' => 'Could not prepare rollback.'];
    }

    $restored = 0;
    foreach ($restoreIds as $uid) {
        $row = $restoreFound[$uid];
        $target = (string) $row['_rollback_to'];
        $current = ereview_user_college_examination_access_value($row);
        $role = (string) ($row['role'] ?? '');
        $school = trim((string) ($row['school'] ?? ''));
        mysqli_stmt_bind_param($insRow, 'iissss', $newOpId, $uid, $role, $school, $current, $target);
        if (!mysqli_stmt_execute($insRow)) {
            mysqli_stmt_close($insRow);
            mysqli_stmt_close($upd);

            return ['ok' => false, 'error' => 'Could not audit rollback for user #' . $uid . '.'];
        }
        mysqli_stmt_bind_param($upd, 'si', $target, $uid);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($insRow);
            mysqli_stmt_close($upd);

            return ['ok' => false, 'error' => 'Could not restore exam access for user #' . $uid . '.'];
        }
        $restored++;
    }
    mysqli_stmt_close($insRow);
    mysqli_stmt_close($upd);

    return ['ok' => true, 'op_id' => $newOpId, 'restored_count' => $restored, 'source_op_id' => $opId];
}
