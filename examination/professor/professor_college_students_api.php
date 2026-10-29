<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';
require_once dirname(__DIR__) . '/includes/college_exam_access_ops.php';
require_once dirname(__DIR__) . '/includes/college_school_assignment.php';
require_once dirname(__DIR__, 2) . '/includes/user_archive_lifecycle.php';
require_once dirname(__DIR__) . '/includes/academic_record_protection.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$mutating = in_array($action, [
    'bulk_enable_college_examination',
    'bulk_assign_section',
    'bulk_disable_college_examination',
    'bulk_remove_from_examination',
    'approve_college_students',
    'bulk_activate_exam_access',
    'bulk_deactivate_exam_access',
    'rollback_exam_access_op',
    'assign_school',
    'rollback_school_assignment',
    'bulk_archive_students',
    'bulk_restore_students',
], true);

if ($mutating) {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
}

function pcs_api_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

/**
 * @return list<int>
 */
function pcs_api_parse_user_ids(mixed $rawIds, int $max = 500): array
{
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) {
            $rawIds = $decoded;
        } else {
            $parts = preg_split('/\s*,\s*/', $rawIds);
            $rawIds = is_array($parts) ? $parts : [];
        }
    }
    if (!is_array($rawIds)) {
        pcs_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }

    $userIds = [];
    foreach ($rawIds as $id) {
        $uid = (int) $id;
        if ($uid > 0) {
            $userIds[$uid] = $uid;
        }
    }
    $userIds = array_values($userIds);
    if ($userIds === []) {
        pcs_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }
    if (count($userIds) > $max) {
        pcs_api_json(['ok' => false, 'error' => 'You can update at most ' . $max . ' students at once.'], 422);
    }

    return $userIds;
}

/**
 * @return array<int, array<string,mixed>>
 */
function pcs_api_lock_users(mysqli $conn, array $userIds): array
{
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    $assignCols = function_exists('college_school_assignment_columns_ready') && college_school_assignment_columns_ready($conn)
        ? ', college_school_assigned_at, college_school_assigned_by'
        : '';
    $sql = "SELECT user_id, role, status, review_type, section, school, college_examination_access{$assignCols}
            FROM users WHERE user_id IN ({$placeholders}) FOR UPDATE";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        throw new RuntimeException('Could not validate selected students.');
    }
    mysqli_stmt_bind_param($st, $types, ...$userIds);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $found = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $found[(int) $row['user_id']] = $row;
    }
    mysqli_stmt_close($st);

    if (count($found) !== count($userIds)) {
        throw new InvalidArgumentException('One or more selected students were not found.');
    }

    return $found;
}

function pcs_api_user_has_college_exam(array $row): bool
{
    return ereview_user_has_college_examination_access(
        $GLOBALS['conn'],
        (int) ($row['user_id'] ?? 0),
        $row
    );
}

/**
 * College roster only (not school-isolated). Throws so bulk archive/restore stay all-or-nothing.
 *
 * @param array<string,mixed> $row
 */
function pcs_api_require_college_roster_row(array $row): void
{
    global $conn;
    $ready = function_exists('ereview_platform_access_columns_ready')
        ? ereview_platform_access_columns_ready($conn)
        : true;
    if (!user_archive_is_college_roster_row($row, $ready)) {
        $uid = (int) ($row['user_id'] ?? 0);
        throw new UserArchiveException(
            'User #' . $uid . ' is not on the College Examination roster and cannot be archived or restored here.',
            'not_on_roster'
        );
    }
}

if ($action === 'approve_college_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $sectionRaw = trim((string) ($_POST['section'] ?? ''));
    $sectionProvided = ($sectionRaw !== '' && $sectionRaw !== '__none__');
    $sectionVal = null;

    if ($sectionProvided) {
        if (mb_strlen($sectionRaw) > 100) {
            pcs_api_json(['ok' => false, 'error' => 'Section must be at most 100 characters.'], 422);
        }
        $canonicalSection = college_sections_resolve_active_name($conn, $sectionRaw);
        if ($canonicalSection === null) {
            pcs_api_json(['ok' => false, 'error' => 'Select an active section from the College Examination Sections list.'], 422);
        }
        $sectionVal = $canonicalSection;
    }

    mysqli_begin_transaction($conn);
    try {
        $found = pcs_api_lock_users($conn, $userIds);
        $needSection = [];

        foreach ($found as $uid => $row) {
            $role = (string) ($row['role'] ?? '');
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($role !== 'college_student') {
                throw new InvalidArgumentException('User #' . $uid . ' is not a native college student registration.');
            }
            if (!in_array($status, ['pending', 'rejected'], true)) {
                throw new InvalidArgumentException('User #' . $uid . ' is already ' . ($status !== '' ? $status : 'approved') . '.');
            }
            $existingSection = trim((string) ($row['section'] ?? ''));
            if ($existingSection === '' && $sectionVal === null) {
                $needSection[] = $uid;
            }
        }

        if ($needSection !== []) {
            throw new InvalidArgumentException(
                count($needSection) === 1
                    ? 'Assign a section before approving this student.'
                    : ('Assign a section before approving. ' . count($needSection) . ' selected student(s) have no section.')
            );
        }

        foreach ($userIds as $uid) {
            if ($sectionVal !== null) {
                $upd = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET status='approved', section=?
                     WHERE user_id=? AND role='college_student' AND status IN ('pending','rejected')
                     LIMIT 1"
                );
                if (!$upd) {
                    throw new RuntimeException('Could not prepare approve update.');
                }
                mysqli_stmt_bind_param($upd, 'si', $sectionVal, $uid);
            } else {
                $upd = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET status='approved'
                     WHERE user_id=? AND role='college_student' AND status IN ('pending','rejected')
                     LIMIT 1"
                );
                if (!$upd) {
                    throw new RuntimeException('Could not prepare approve update.');
                }
                mysqli_stmt_bind_param($upd, 'i', $uid);
            }
            if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
                mysqli_stmt_close($upd);
                throw new RuntimeException('Could not approve student #' . $uid . '.');
            }
            mysqli_stmt_close($upd);
        }

        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $count = count($userIds);
    $msg = 'Approved ' . $count . ' student' . ($count === 1 ? '' : 's') . '. They can now sign in.';
    if ($sectionVal !== null) {
        $msg .= ' Section: ' . $sectionVal . '.';
    }
    pcs_api_json([
        'ok' => true,
        'approved_count' => $count,
        'user_ids' => $userIds,
        'section' => $sectionVal,
        'message' => $msg,
    ]);
}

if ($action === 'bulk_enable_college_examination' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ereview_platform_access_columns_ready($conn)) {
        pcs_api_json(['ok' => false, 'error' => 'College Examination access columns are not available.'], 500);
    }

    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $section = trim((string) ($_POST['section'] ?? ''));
    $sectionVal = null;
    if ($section !== '' && $section !== '__none__') {
        if (mb_strlen($section) > 100) {
            pcs_api_json(['ok' => false, 'error' => 'Section must be at most 100 characters.'], 422);
        }
        $canonicalSection = college_sections_resolve_active_name($conn, $section);
        if ($canonicalSection === null) {
            pcs_api_json(['ok' => false, 'error' => 'Select an active section from the College Examination Sections list, or choose No Section.'], 422);
        }
        $section = $canonicalSection;
        $sectionVal = $section;
    }

    $reviewType = 'undergrad';

    $adminId = (int) (getCurrentUserId() ?? 0);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    mysqli_begin_transaction($conn);
    try {
        $found = pcs_api_lock_users($conn, $userIds);

        foreach ($found as $uid => $row) {
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'student') {
                throw new InvalidArgumentException('User #' . $uid . ' is not an eReview student account. Native college students already have examination access.');
            }
            if (strtolower((string) ($row['status'] ?? '')) === 'rejected') {
                throw new InvalidArgumentException('Rejected student #' . $uid . ' cannot be enabled.');
            }
            if (strtolower((string) ($row['status'] ?? '')) === 'archived') {
                throw new InvalidArgumentException('Archived student #' . $uid . ' cannot be enabled. Restore the LMS account first.');
            }
            if (pcs_api_user_has_college_exam($row)) {
                throw new InvalidArgumentException('User #' . $uid . ' already has College Examination access.');
            }
        }

        if ($sectionVal === null) {
            $upd = mysqli_prepare(
                $conn,
                "UPDATE users
                 SET college_examination_access='active',
                     college_examination_enabled_at=COALESCE(college_examination_enabled_at, NOW()),
                     college_examination_enabled_by=COALESCE(college_examination_enabled_by, ?),
                     review_type=?
                 WHERE user_id IN ({$placeholders})
                   AND role='student'
                   AND status NOT IN ('rejected','archived')"
            );
            if (!$upd) {
                throw new RuntimeException('Could not prepare bulk enable update.');
            }
            $bindTypes = 'is' . $types;
            $bindValues = array_merge([$adminId, $reviewType], $userIds);
        } else {
            $upd = mysqli_prepare(
                $conn,
                "UPDATE users
                 SET college_examination_access='active',
                     college_examination_enabled_at=COALESCE(college_examination_enabled_at, NOW()),
                     college_examination_enabled_by=COALESCE(college_examination_enabled_by, ?),
                     review_type=?,
                     section=?
                 WHERE user_id IN ({$placeholders})
                   AND role='student'
                   AND status NOT IN ('rejected','archived')"
            );
            if (!$upd) {
                throw new RuntimeException('Could not prepare bulk enable update.');
            }
            $bindTypes = 'iss' . $types;
            $bindValues = array_merge([$adminId, $reviewType, $sectionVal], $userIds);
        }
        mysqli_stmt_bind_param($upd, $bindTypes, ...$bindValues);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Bulk enable update failed.');
        }
        mysqli_stmt_close($upd);

        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $count = count($userIds);
    $reviewLabel = $reviewType === 'reviewee' ? 'Reviewee' : 'Undergrad';
    $sectionDetail = $sectionVal === null ? 'No section assigned' : ('Section: ' . $sectionVal);
    pcs_api_json([
        'ok' => true,
        'enabled_count' => $count,
        'user_ids' => $userIds,
        'section' => $sectionVal,
        'review_type' => $reviewType,
        'message' => 'College Examination enabled for ' . $count . ' student' . ($count === 1 ? '' : 's') . '.',
        'detail' => $sectionDetail . ' · Review Type: ' . $reviewLabel,
    ]);
}

if ($action === 'bulk_assign_section' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $sectionRaw = trim((string) ($_POST['section'] ?? ''));
    $clearSection = ($sectionRaw === '' || $sectionRaw === '__none__');
    $sectionVal = null;

    if (!$clearSection) {
        if (mb_strlen($sectionRaw) > 100) {
            pcs_api_json(['ok' => false, 'error' => 'Section must be at most 100 characters.'], 422);
        }
        $canonicalSection = college_sections_resolve_active_name($conn, $sectionRaw);
        if ($canonicalSection === null) {
            pcs_api_json(['ok' => false, 'error' => 'Select an active section or choose No Section.'], 422);
        }
        $sectionVal = $canonicalSection;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    mysqli_begin_transaction($conn);
    try {
        $found = pcs_api_lock_users($conn, $userIds);

        foreach ($found as $uid => $row) {
            $role = (string) ($row['role'] ?? '');
            $rt = strtolower(trim((string) ($row['review_type'] ?? '')));
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($rt !== 'undergrad') {
                throw new InvalidArgumentException('User #' . $uid . ' is not a college student profile. Sections apply to undergrad examinees only.');
            }
            $nativeOk = ($role === 'college_student' && in_array($status, ['pending', 'approved', 'rejected'], true));
            if (!$nativeOk && !pcs_api_user_has_college_exam($row)) {
                throw new InvalidArgumentException('User #' . $uid . ' does not have College Examination access. Enable access first.');
            }
        }

        if ($clearSection) {
            $upd = mysqli_prepare(
                $conn,
                "UPDATE users SET section=NULL WHERE user_id IN ({$placeholders})"
            );
            if (!$upd) {
                throw new RuntimeException('Could not prepare section clear.');
            }
            mysqli_stmt_bind_param($upd, $types, ...$userIds);
        } else {
            $upd = mysqli_prepare(
                $conn,
                "UPDATE users SET section=? WHERE user_id IN ({$placeholders})"
            );
            if (!$upd) {
                throw new RuntimeException('Could not prepare section assignment.');
            }
            $bindTypes = 's' . $types;
            mysqli_stmt_bind_param($upd, $bindTypes, $sectionVal, ...$userIds);
        }
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Section assignment failed.');
        }
        mysqli_stmt_close($upd);

        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $count = count($userIds);
    pcs_api_json([
        'ok' => true,
        'updated_count' => $count,
        'section' => $sectionVal,
        'message' => $clearSection
            ? ('Section cleared for ' . $count . ' student' . ($count === 1 ? '' : 's') . '.')
            : ('Section "' . $sectionVal . '" assigned to ' . $count . ' student' . ($count === 1 ? '' : 's') . '.'),
    ]);
}

if ($action === 'bulk_disable_college_examination' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ereview_platform_access_columns_ready($conn)) {
        pcs_api_json(['ok' => false, 'error' => 'College Examination access columns are not available.'], 500);
    }

    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    mysqli_begin_transaction($conn);
    try {
        $found = pcs_api_lock_users($conn, $userIds);

        foreach ($found as $uid => $row) {
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'student') {
                throw new InvalidArgumentException('User #' . $uid . ' is a native college student account. Use Delete from the row menu to remove native accounts.');
            }
            if (!pcs_api_user_has_college_exam($row)) {
                throw new InvalidArgumentException('User #' . $uid . ' does not have College Examination access enabled.');
            }
        }

        $upd = mysqli_prepare(
            $conn,
            "UPDATE users
             SET college_examination_access='suspended'
             WHERE user_id IN ({$placeholders})
               AND role='student'"
        );
        if (!$upd) {
            throw new RuntimeException('Could not prepare suspend update.');
        }
        mysqli_stmt_bind_param($upd, $types, ...$userIds);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Suspend update failed.');
        }
        mysqli_stmt_close($upd);

        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $count = count($userIds);
    pcs_api_json([
        'ok' => true,
        'disabled_count' => $count,
        'message' => 'College Examination suspended for ' . $count . ' student' . ($count === 1 ? '' : 's') . '. eReview access is unchanged.',
    ]);
}

if ($action === 'bulk_remove_from_examination' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ereview_platform_access_columns_ready($conn)) {
        pcs_api_json(['ok' => false, 'error' => 'College Examination access columns are not available.'], 500);
    }

    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $postedMode = strtolower(trim((string) ($_POST['remove_mode'] ?? 'unlink')));
    $unlinked = 0;
    $archived = 0;
    $deleted = 0;
    $actorId = (int) (getCurrentUserId() ?? 0);

    mysqli_begin_transaction($conn);
    try {
        $found = pcs_api_lock_users($conn, $userIds);

        foreach ($userIds as $uid) {
            if (!isset($found[$uid])) {
                throw new InvalidArgumentException('User #' . $uid . ' was not found.');
            }
            $row = $found[$uid];
            $role = (string) ($row['role'] ?? '');
            $mode = academic_record_resolve_student_remove_mode($postedMode, $role);
            if ($role === 'student') {
                if (!pcs_api_user_has_college_exam($row) && ereview_user_college_examination_access_value($row) !== 'suspended') {
                    throw new InvalidArgumentException('User #' . $uid . ' is not on the Examination roster.');
                }
                $upd = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET college_examination_access='none'
                     WHERE user_id=? AND role='student' LIMIT 1"
                );
                if (!$upd) {
                    throw new RuntimeException('Could not prepare unlink update.');
                }
                mysqli_stmt_bind_param($upd, 'i', $uid);
                if (!mysqli_stmt_execute($upd)) {
                    mysqli_stmt_close($upd);
                    throw new RuntimeException('Unlink update failed for user #' . $uid . '.');
                }
                mysqli_stmt_close($upd);
                $unlinked++;
            } elseif ($role === 'college_student') {
                if ($mode === 'delete') {
                    $block = academic_record_user_hard_delete_block_reason($conn, $uid);
                    if ($block !== null) {
                        throw new InvalidArgumentException($block);
                    }
                    $del = mysqli_prepare($conn, "DELETE FROM users WHERE user_id=? AND role='college_student' LIMIT 1");
                    if (!$del) {
                        throw new RuntimeException('Could not prepare delete.');
                    }
                    mysqli_stmt_bind_param($del, 'i', $uid);
                    if (!mysqli_stmt_execute($del) || mysqli_stmt_affected_rows($del) < 1) {
                        mysqli_stmt_close($del);
                        throw new RuntimeException('Could not delete native college account #' . $uid . '.');
                    }
                    mysqli_stmt_close($del);
                    $deleted++;
                } else {
                    $result = user_archive_apply_locked($conn, $row, $actorId, 'Archived from College Students bulk remove');
                    if (empty($result['ok'])) {
                        throw user_archive_exception_from_result($result, 'Could not archive native college account #' . $uid . '.');
                    }
                    $archived++;
                }
            } else {
                throw new InvalidArgumentException('User #' . $uid . ' cannot be removed from this list.');
            }
        }

        mysqli_commit($conn);
    } catch (UserArchiveException $e) {
        mysqli_rollback($conn);
        pcs_api_json(
            user_archive_json_error_payload($e),
            user_archive_http_status_for_error($e->errorType, $e->getMessage())
        );
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $parts = [];
    if ($unlinked > 0) {
        $parts[] = 'removed exam access for ' . $unlinked . ' LMS student' . ($unlinked === 1 ? '' : 's');
    }
    if ($archived > 0) {
        $parts[] = 'archived ' . $archived . ' native college account' . ($archived === 1 ? '' : 's');
    }
    if ($deleted > 0) {
        $parts[] = 'deleted ' . $deleted . ' native college account' . ($deleted === 1 ? '' : 's') . ' with no protected academic records';
    }
    pcs_api_json([
        'ok' => true,
        'unlinked_count' => $unlinked,
        'archived_count' => $archived,
        'deleted_count' => $deleted,
        'message' => $parts !== [] ? (ucfirst(implode('; ', $parts)) . '.') : 'No changes made.',
    ]);
}

if ($action === 'assign_school' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawIds = $_POST['user_ids'] ?? null;
    if ($rawIds === null && isset($_POST['user_id'])) {
        $rawIds = [(int) $_POST['user_id']];
    }
    $userIds = pcs_api_parse_user_ids($rawIds ?? []);
    if (count($userIds) !== 1) {
        pcs_api_json(['ok' => false, 'error' => 'Assign school for one student at a time.'], 422);
    }
    $school = (string) ($_POST['school'] ?? '');
    $actorId = (int) (getCurrentUserId() ?? 0);
    if ($actorId <= 0) {
        pcs_api_json(['ok' => false, 'error' => 'Not signed in.'], 403);
    }
    $logIds = [];
    $assignedSchool = '';
    mysqli_begin_transaction($conn);
    try {
        foreach ($userIds as $userId) {
            $result = college_school_assign_for_user($conn, (int) $userId, $school, $actorId);
            if (empty($result['ok'])) {
                throw new InvalidArgumentException((string) ($result['error'] ?? 'Could not assign school for user #' . $userId . '.'));
            }
            $lid = (int) ($result['log_id'] ?? 0);
            if ($lid > 0) {
                $logIds[] = $lid;
            }
            $assignedSchool = (string) ($result['school'] ?? $assignedSchool);
        }
        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $n = count($userIds);
    $msg = 'Assigned school "' . $assignedSchool . '" for ' . $n . ' selected student' . ($n === 1 ? '' : 's')
        . '. Account status and exam access were not changed.';
    if ($logIds !== []) {
        $msg .= ' Log IDs: ' . implode(', ', $logIds) . '.';
    }
    pcs_api_json([
        'ok' => true,
        'log_ids' => $logIds,
        'user_ids' => $userIds,
        'school' => $assignedSchool,
        'message' => $msg,
    ]);
}

if ($action === 'rollback_school_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $logId = (int) ($_POST['log_id'] ?? 0);
    $actorId = (int) (getCurrentUserId() ?? 0);
    if ($actorId <= 0) {
        pcs_api_json(['ok' => false, 'error' => 'Not signed in.'], 403);
    }
    mysqli_begin_transaction($conn);
    try {
        $result = college_school_rollback_log($conn, $logId, $actorId);
        if (empty($result['ok'])) {
            throw new InvalidArgumentException((string) ($result['error'] ?? 'Could not restore school.'));
        }
        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    pcs_api_json([
        'ok' => true,
        'log_id' => (int) ($result['log_id'] ?? 0),
        'user_id' => (int) ($result['user_id'] ?? 0),
        'source_log_id' => $logId,
        'message' => 'Restored the exact prior school value from assignment log #' . $logId . '.',
    ]);
}

if ($action === 'bulk_activate_exam_access' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $actorId = (int) (getCurrentUserId() ?? 0);
    mysqli_begin_transaction($conn);
    try {
        $found = pcs_api_lock_users($conn, $userIds);
        $result = college_exam_access_apply_for_ids($conn, $userIds, $found, 'active', 'activate', $actorId);
        if (empty($result['ok'])) {
            throw new InvalidArgumentException((string) ($result['error'] ?? 'Could not activate exam access.'));
        }
        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $changed = (int) ($result['changed_count'] ?? 0);
    $opId = (int) ($result['op_id'] ?? 0);
    pcs_api_json([
        'ok' => true,
        'op_id' => $opId,
        'changed_count' => $changed,
        'unchanged_count' => (int) ($result['unchanged_count'] ?? 0),
        'user_ids' => $userIds,
        'message' => 'Activated College Examination access for ' . $changed . ' selected student' . ($changed === 1 ? '' : 's')
            . '. Account status, school, section, student numbers, and exam history were not changed. Operation ID #' . $opId . ' (use rollback to restore each prior access value).',
    ]);
}

if ($action === 'bulk_deactivate_exam_access' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $actorId = (int) (getCurrentUserId() ?? 0);
    mysqli_begin_transaction($conn);
    try {
        $found = pcs_api_lock_users($conn, $userIds);
        $result = college_exam_access_apply_for_ids($conn, $userIds, $found, 'suspended', 'deactivate', $actorId);
        if (empty($result['ok'])) {
            throw new InvalidArgumentException((string) ($result['error'] ?? 'Could not deactivate exam access.'));
        }
        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $changed = (int) ($result['changed_count'] ?? 0);
    $opId = (int) ($result['op_id'] ?? 0);
    pcs_api_json([
        'ok' => true,
        'op_id' => $opId,
        'changed_count' => $changed,
        'unchanged_count' => (int) ($result['unchanged_count'] ?? 0),
        'user_ids' => $userIds,
        'message' => 'Deactivated College Examination access for ' . $changed . ' selected student' . ($changed === 1 ? '' : 's')
            . '. Records were preserved. Operation ID #' . $opId . ' (rollback restores each student\'s prior access, not a blanket Active).',
    ]);
}

if ($action === 'rollback_exam_access_op' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $opId = (int) ($_POST['op_id'] ?? 0);
    $actorId = (int) (getCurrentUserId() ?? 0);
    mysqli_begin_transaction($conn);
    try {
        $loaded = college_exam_access_load_op_for_rollback($conn, $opId);
        if (empty($loaded['ok'])) {
            throw new InvalidArgumentException((string) ($loaded['error'] ?? 'Could not load operation.'));
        }
        $ids = $loaded['user_ids'] ?? [];
        $rows = $loaded['rows'] ?? [];
        if (!is_array($ids) || $ids === [] || !is_array($rows)) {
            throw new InvalidArgumentException('Operation has no restorable rows.');
        }
        $found = pcs_api_lock_users($conn, $ids);
        $result = college_exam_access_rollback_locked($conn, $opId, $actorId, $ids, $rows, $found);
        if (empty($result['ok'])) {
            throw new InvalidArgumentException((string) ($result['error'] ?? 'Rollback failed.'));
        }
        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $restored = (int) ($result['restored_count'] ?? 0);
    $newOp = (int) ($result['op_id'] ?? 0);
    pcs_api_json([
        'ok' => true,
        'op_id' => $newOp,
        'source_op_id' => $opId,
        'restored_count' => $restored,
        'message' => 'Restored prior College Examination access for ' . $restored . ' student' . ($restored === 1 ? '' : 's')
            . ' from operation #' . $opId . '. New audit id #' . $newOp . '.',
    ]);
}

if ($action === 'bulk_archive_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $reasonKey = strtolower(trim((string) ($_POST['reason'] ?? 'inactive')));
    $reasonMap = [
        'duplicate' => 'Duplicate account',
        'fraud' => 'Fraud or invalid registration',
        'request' => 'Requested by user',
        'inactive' => 'Inactive or abandoned account',
        'graduated' => 'Completed / graduated',
        'other' => 'Other',
    ];
    $reason = $reasonMap[$reasonKey] ?? $reasonMap['inactive'];
    $actorId = (int) (getCurrentUserId() ?? 0);
    $archived = 0;
    $already = 0;
    mysqli_begin_transaction($conn);
    try {
        $locked = [];
        foreach ($userIds as $uid) {
            $row = user_archive_lock_user($conn, $uid);
            if (!$row) {
                throw new UserArchiveException('User #' . $uid . ' was not found.', 'not_found');
            }
            pcs_api_require_college_roster_row($row);
            $locked[] = $row;
        }
        foreach ($locked as $row) {
            $result = user_archive_apply_locked($conn, $row, $actorId, $reason);
            if (empty($result['ok'])) {
                throw user_archive_exception_from_result($result, 'Archive failed.');
            }
            if (!empty($result['already'])) {
                $already++;
            } else {
                $archived++;
            }
        }
        mysqli_commit($conn);
    } catch (UserArchiveException $e) {
        mysqli_rollback($conn);
        pcs_api_json(
            user_archive_json_error_payload($e),
            user_archive_http_status_for_error($e->errorType, $e->getMessage())
        );
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    pcs_api_json([
        'ok' => true,
        'archived_count' => $archived,
        'already_count' => $already,
        'message' => 'Archived ' . $archived . ' account' . ($archived === 1 ? '' : 's')
            . '. Login to eReview and College Examination is blocked. History was preserved.'
            . ($already > 0 ? (' (' . $already . ' already archived.)') : ''),
    ]);
}

if ($action === 'bulk_restore_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIds = pcs_api_parse_user_ids($_POST['user_ids'] ?? []);
    $actorId = (int) (getCurrentUserId() ?? 0);
    $restored = 0;
    mysqli_begin_transaction($conn);
    try {
        $locked = [];
        foreach ($userIds as $uid) {
            $row = user_archive_lock_user($conn, $uid);
            if (!$row) {
                throw new UserArchiveException('User #' . $uid . ' was not found.', 'not_found');
            }
            pcs_api_require_college_roster_row($row);
            $locked[] = $row;
        }
        foreach ($locked as $row) {
            $result = user_archive_restore_locked($conn, $row, $actorId);
            if (empty($result['ok'])) {
                throw user_archive_exception_from_result($result, 'Restore failed.');
            }
            $restored++;
        }
        mysqli_commit($conn);
    } catch (UserArchiveException $e) {
        mysqli_rollback($conn);
        pcs_api_json(
            user_archive_json_error_payload($e),
            user_archive_http_status_for_error($e->errorType, $e->getMessage())
        );
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        pcs_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    pcs_api_json([
        'ok' => true,
        'restored_count' => $restored,
        'message' => 'Restored ' . $restored . ' account' . ($restored === 1 ? '' : 's')
            . ' from archive snapshot. Grants and College Examination access flags were not rewritten.',
    ]);
}

pcs_api_json(['ok' => false, 'error' => 'Unknown action.'], 400);
