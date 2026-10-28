<?php
declare(strict_types=1);

/**
 * Unified learner archive / restore.
 * Soft-inactivates users.status='archived' for role=student and role=college_student.
 * Does not DELETE users, grants, or exam history. Does not mutate college_examination_access.
 */

require_once __DIR__ . '/schema_introspection.php';
require_once __DIR__ . '/admin_account_window.php';
require_once __DIR__ . '/admin_acl.php';
require_once __DIR__ . '/platform_access.php';

class UserArchiveException extends InvalidArgumentException
{
    public string $errorType;

    public function __construct(string $message, string $errorType = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorType = $errorType;
    }
}

/**
 * @param array<string,mixed> $result
 */
function user_archive_exception_from_result(array $result, string $fallback): UserArchiveException
{
    return new UserArchiveException(
        (string) ($result['error'] ?? $fallback),
        (string) ($result['error_type'] ?? '')
    );
}

/**
 * @return array{ok:bool, error:string, error_type?:string}
 */
function user_archive_json_error_payload(UserArchiveException $e): array
{
    $payload = [
        'ok' => false,
        'error' => $e->getMessage(),
    ];
    if ($e->errorType !== '') {
        $payload['error_type'] = $e->errorType;
    }

    return $payload;
}

function user_archive_learner_roles(): array
{
    return ['student', 'college_student'];
}

function user_archive_snapshots_table_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (function_exists('ereview_schema_table_exists')) {
        $ready = ereview_schema_table_exists($conn, 'user_archive_snapshots');

        return $ready;
    }
    $chk = @mysqli_query($conn, "SHOW TABLES LIKE 'user_archive_snapshots'");
    $ready = (bool) ($chk && mysqli_fetch_row($chk));
    if ($chk) {
        mysqli_free_result($chk);
    }

    return $ready;
}

function user_archive_deleted_users_log_ready(mysqli $conn): bool
{
    if (function_exists('ereview_schema_table_exists')) {
        return ereview_schema_table_exists($conn, 'deleted_users_log');
    }
    $chk = @mysqli_query($conn, "SHOW TABLES LIKE 'deleted_users_log'");
    $ok = (bool) ($chk && mysqli_fetch_row($chk));
    if ($chk) {
        mysqli_free_result($chk);
    }

    return $ok;
}

function user_archive_status_enum_supports_archived(mysqli $conn): bool
{
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status'");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) {
        mysqli_free_result($res);
    }
    $type = strtolower((string) ($row['Type'] ?? ''));

    return str_contains($type, "'archived'");
}

/**
 * @return list<string>
 */
function user_archive_client_error_types(): array
{
    return [
        'archive_snapshot_missing',
        'archive_snapshot_invalid',
        'archive_schema_missing',
        'not_found',
        'not_archived',
        'not_learner',
        'not_archivable',
        'not_on_roster',
    ];
}

function user_archive_http_status_for_error(?string $errorType, string $message = ''): int
{
    $type = strtolower(trim((string) $errorType));
    if (in_array($type, user_archive_client_error_types(), true)) {
        return 422;
    }
    if ($message === 'User not found'
        || str_contains($message, 'Cannot restore')
        || str_contains($message, 'snapshot table')
        || str_contains($message, 'migration 054')
        || str_contains($message, 'Only archived')
        || str_contains($message, 'Only student')) {
        return 422;
    }

    return 500;
}

function user_archive_normalize_status(string $status): string
{
    $s = strtolower(trim($status));

    return in_array($s, ['pending', 'approved', 'rejected', 'archived'], true) ? $s : '';
}

function user_archive_normalize_exam_access(string $access): string
{
    $a = strtolower(trim($access));

    return in_array($a, ['none', 'active', 'suspended'], true) ? $a : 'none';
}

/**
 * College Examination roster eligibility (same idea as pcs_build_list_where).
 * Native college_student, or LMS student with exam access active/suspended.
 * Archived roster members remain eligible so they can be restored.
 * Not school-scoped: professors have no school identity in this schema.
 *
 * @param array<string,mixed> $row
 */
function user_archive_is_college_roster_row(array $row, bool $platformColsReady = true): bool
{
    $role = (string) ($row['role'] ?? '');
    if ($role === 'college_student') {
        return true;
    }
    if ($role !== 'student' || !$platformColsReady) {
        return false;
    }
    $access = function_exists('ereview_user_college_examination_access_value')
        ? ereview_user_college_examination_access_value($row)
        : user_archive_normalize_exam_access((string) ($row['college_examination_access'] ?? 'none'));

    return in_array($access, ['active', 'suspended'], true);
}

/**
 * Resolve restore status from an open snapshot only. Fail closed if missing/invalid.
 *
 * @param array<string,mixed>|null $snap
 * @return array{ok:bool, error?:string, error_type?:string, status?:string, snapshot_id?:int, prior_college_examination_access?:string}
 */
function user_archive_resolve_restore_status(?array $snap): array
{
    if ($snap === null || $snap === []) {
        return [
            'ok' => false,
            'error_type' => 'archive_snapshot_missing',
            'error' => 'Cannot restore this account: no archive snapshot was recorded. Restoration is refused so previous access is not guessed. Capture a valid snapshot before restoring.',
        ];
    }
    $restoreStatus = user_archive_normalize_status((string) ($snap['prior_status'] ?? ''));
    if (!in_array($restoreStatus, ['pending', 'approved', 'rejected'], true)) {
        return [
            'ok' => false,
            'error_type' => 'archive_snapshot_invalid',
            'error' => 'Cannot restore this account: the archive snapshot prior status is missing or invalid. Restoration is refused so previous access is not guessed.',
        ];
    }
    $snapshotId = (int) ($snap['snapshot_id'] ?? 0);
    if ($snapshotId <= 0) {
        return [
            'ok' => false,
            'error_type' => 'archive_snapshot_invalid',
            'error' => 'Cannot restore this account: the archive snapshot is invalid. Restoration is refused so previous access is not guessed.',
        ];
    }

    return [
        'ok' => true,
        'status' => $restoreStatus,
        'snapshot_id' => $snapshotId,
        'prior_college_examination_access' => user_archive_normalize_exam_access(
            (string) ($snap['prior_college_examination_access'] ?? 'none')
        ),
    ];
}

/**
 * @return array<string,mixed>|null
 */
function user_archive_lock_user(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $hasExam = function_exists('ereview_platform_access_columns_ready') && ereview_platform_access_columns_ready($conn);
    $cols = 'user_id, full_name, email, role, status, review_type, school, school_other, student_number';
    if ($hasExam) {
        $cols .= ', college_examination_access';
    }
    $st = mysqli_prepare($conn, "SELECT {$cols} FROM users WHERE user_id=? LIMIT 1 FOR UPDATE");
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $userId);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

/**
 * Latest unrestored snapshot for this user.
 *
 * @return array<string,mixed>|null
 */
function user_archive_open_snapshot(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !user_archive_snapshots_table_ready($conn)) {
        return null;
    }
    $st = mysqli_prepare(
        $conn,
        'SELECT snapshot_id, user_id, prior_status, prior_college_examination_access, reason, archived_by, archived_at
         FROM user_archive_snapshots
         WHERE user_id=? AND restored_at IS NULL
         ORDER BY snapshot_id DESC
         LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $userId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

function user_archive_clear_remember_me(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $rm = dirname(__DIR__) . '/remember_me.php';
    if (is_file($rm)) {
        require_once $rm;
    }
    if (function_exists('clearRememberMeForUserId')) {
        clearRememberMeForUserId($userId);
    }
}

/**
 * Caller must be inside a transaction.
 *
 * @param array<string,mixed> $lockedRow
 * @return array{ok:bool, already?:bool, error?:string, error_type?:string, user_id?:int, snapshot_id?:int, prior_status?:string, prior_college_examination_access?:string}
 */
function user_archive_apply_locked(
    mysqli $conn,
    array $lockedRow,
    int $actorUserId,
    string $reason
): array {
    $userId = (int) ($lockedRow['user_id'] ?? 0);
    $role = (string) ($lockedRow['role'] ?? '');
    if ($userId <= 0 || !in_array($role, user_archive_learner_roles(), true)) {
        return ['ok' => false, 'error' => 'Only student and college student accounts can be archived.', 'error_type' => 'not_learner'];
    }
    if (!user_archive_status_enum_supports_archived($conn)) {
        return [
            'ok' => false,
            'error_type' => 'archive_schema_missing',
            'error' => 'users.status does not include archived. Archive is refused so schema is not altered during this request.',
        ];
    }
    if (!user_archive_snapshots_table_ready($conn)) {
        return [
            'ok' => false,
            'error_type' => 'archive_schema_missing',
            'error' => 'Archive snapshot table is not installed. Apply migration 054 before archiving.',
        ];
    }

    $status = user_archive_normalize_status((string) ($lockedRow['status'] ?? ''));
    if ($status === 'archived') {
        return ['ok' => true, 'already' => true, 'user_id' => $userId];
    }
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'This account status cannot be archived.', 'error_type' => 'not_archivable'];
    }

    $examAccess = function_exists('ereview_user_college_examination_access_value')
        ? ereview_user_college_examination_access_value($lockedRow)
        : 'none';
    $examAccess = user_archive_normalize_exam_access($examAccess);
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Inactive or abandoned account';
    }
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 255);
    } else {
        $reason = substr($reason, 0, 255);
    }

    $open = user_archive_open_snapshot($conn, $userId);
    if ($open) {
        $close = mysqli_prepare(
            $conn,
            "UPDATE user_archive_snapshots
             SET restored_at=NOW(), restored_by=?, restore_status='superseded'
             WHERE snapshot_id=? AND restored_at IS NULL
             LIMIT 1"
        );
        if (!$close) {
            return ['ok' => false, 'error' => 'Could not close a previous archive snapshot.'];
        }
        $sid = (int) $open['snapshot_id'];
        mysqli_stmt_bind_param($close, 'ii', $actorUserId, $sid);
        if (!mysqli_stmt_execute($close)) {
            mysqli_stmt_close($close);

            return ['ok' => false, 'error' => 'Could not close a previous archive snapshot.'];
        }
        mysqli_stmt_close($close);
    }

    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO user_archive_snapshots
            (user_id, prior_status, prior_college_examination_access, reason, archived_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'Could not record archive snapshot.'];
    }
    mysqli_stmt_bind_param($ins, 'isssi', $userId, $status, $examAccess, $reason, $actorUserId);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);

        return ['ok' => false, 'error' => 'Could not record archive snapshot.'];
    }
    $snapshotId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    if ($snapshotId <= 0) {
        return ['ok' => false, 'error' => 'Could not record archive snapshot.'];
    }

    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET status='archived'
         WHERE user_id=? AND role IN ('student','college_student') AND status<>'archived'
         LIMIT 1"
    );
    if (!$upd) {
        return ['ok' => false, 'error' => 'Could not archive account.'];
    }
    mysqli_stmt_bind_param($upd, 'i', $userId);
    if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
        mysqli_stmt_close($upd);

        return ['ok' => false, 'error' => 'Could not archive account.'];
    }
    mysqli_stmt_close($upd);

    user_archive_clear_remember_me($userId);

    if (function_exists('users_activity_log')) {
        users_activity_log($conn, 'student_archived', [
            'reason' => $reason,
            'snapshot_id' => $snapshotId,
            'prior_status' => $status,
            'prior_college_examination_access' => $examAccess,
            'role' => $role,
        ], $actorUserId, null, null, $userId);
    }

    return [
        'ok' => true,
        'already' => false,
        'user_id' => $userId,
        'snapshot_id' => $snapshotId,
        'prior_status' => $status,
        'prior_college_examination_access' => $examAccess,
    ];
}

/**
 * Caller must be inside a transaction. Restores users.status from snapshot only.
 *
 * @param array<string,mixed> $lockedRow
 * @return array{ok:bool, error?:string, error_type?:string, user_id?:int, status?:string, snapshot_id?:int, legacy?:bool, prior_college_examination_access?:string}
 */
function user_archive_restore_locked(
    mysqli $conn,
    array $lockedRow,
    int $actorUserId
): array {
    $userId = (int) ($lockedRow['user_id'] ?? 0);
    $role = (string) ($lockedRow['role'] ?? '');
    if ($userId <= 0 || !in_array($role, user_archive_learner_roles(), true)) {
        return ['ok' => false, 'error' => 'Only student and college student accounts can be restored.', 'error_type' => 'not_learner'];
    }
    $status = user_archive_normalize_status((string) ($lockedRow['status'] ?? ''));
    if ($status !== 'archived') {
        return ['ok' => false, 'error' => 'Only archived students can be restored.', 'error_type' => 'not_archived'];
    }
    if (!user_archive_snapshots_table_ready($conn)) {
        return [
            'ok' => false,
            'error_type' => 'archive_schema_missing',
            'error' => 'Archive snapshot table is not installed. Apply migration 054 before restoring.',
        ];
    }

    $snap = user_archive_open_snapshot($conn, $userId);
    $resolved = user_archive_resolve_restore_status($snap);
    if (empty($resolved['ok'])) {
        return [
            'ok' => false,
            'error' => (string) ($resolved['error'] ?? 'Cannot restore this account without a valid archive snapshot.'),
            'error_type' => (string) ($resolved['error_type'] ?? 'archive_snapshot_missing'),
            'user_id' => $userId,
        ];
    }
    $restoreStatus = (string) ($resolved['status'] ?? '');
    $priorExam = (string) ($resolved['prior_college_examination_access'] ?? 'none');
    $snapshotId = (int) ($resolved['snapshot_id'] ?? 0);

    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET status=?
         WHERE user_id=? AND role IN ('student','college_student') AND status='archived'
         LIMIT 1"
    );
    if (!$upd) {
        return ['ok' => false, 'error' => 'Could not restore account.'];
    }
    mysqli_stmt_bind_param($upd, 'si', $restoreStatus, $userId);
    if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
        mysqli_stmt_close($upd);

        return ['ok' => false, 'error' => 'Could not restore account.'];
    }
    mysqli_stmt_close($upd);

    if ($snapshotId > 0) {
        $mark = mysqli_prepare(
            $conn,
            'UPDATE user_archive_snapshots
             SET restored_at=NOW(), restored_by=?, restore_status=?
             WHERE snapshot_id=? AND restored_at IS NULL
             LIMIT 1'
        );
        if (!$mark) {
            return ['ok' => false, 'error' => 'Could not close archive snapshot.'];
        }
        mysqli_stmt_bind_param($mark, 'isi', $actorUserId, $restoreStatus, $snapshotId);
        if (!mysqli_stmt_execute($mark)) {
            mysqli_stmt_close($mark);

            return ['ok' => false, 'error' => 'Could not close archive snapshot.'];
        }
        mysqli_stmt_close($mark);
    }

    if (function_exists('users_activity_log')) {
        users_activity_log($conn, 'student_restored', [
            'snapshot_id' => $snapshotId,
            'new_status' => $restoreStatus,
            'legacy_snapshot' => false,
            'prior_college_examination_access' => $priorExam,
            'role' => $role,
        ], $actorUserId, null, null, $userId);
    }

    return [
        'ok' => true,
        'user_id' => $userId,
        'status' => $restoreStatus,
        'snapshot_id' => $snapshotId,
        'legacy' => false,
        'prior_college_examination_access' => $priorExam,
    ];
}
