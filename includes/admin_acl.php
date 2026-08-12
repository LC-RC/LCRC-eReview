<?php
/**
 * Admin page ACL - granular locked/unlocked access + activity log helpers.
 *
 * Rule: zero rows in admin_page_permissions => full access (legacy/super admins).
 * Any rows => only those page_keys are allowed.
 */

require_once __DIR__ . '/schema_introspection.php';
require_once __DIR__ . '/url_helpers.php';

/**
 * Catalog of admin capabilities (groups for the picker UI).
 * @return array<int, array{group:string, keys: array<int, array{key:string, label:string, scripts: array<int,string>}>}>
 */
function admin_acl_catalog(): array
{
    return [
        [
            'group' => 'Main',
            'keys' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'scripts' => ['admin_dashboard']],
                ['key' => 'manage_admins', 'label' => 'Manage Admins & Users Log', 'scripts' => ['admin_admins']],
            ],
        ],
        [
            'group' => 'Students',
            'keys' => [
                [
                    'key' => 'students',
                    'label' => 'Students',
                    'scripts' => [
                        'admin_students', 'admin_student_view', 'admin_student_delete',
                        'admin_remind_upload_proof', 'admin_grant_access', 'activate_user',
                        'admin_payment_proof', 'admin_students_presence', 'extend_access', 'reject',
                    ],
                ],
                [
                    'key' => 'student_activity',
                    'label' => 'Live Activity & Monitoring',
                    'scripts' => ['admin_student_live', 'admin_student_live_api'],
                ],
                ['key' => 'student_access', 'label' => 'Student Access', 'scripts' => ['admin_student_access', 'admin_student_access_api']],
                [
                    'key' => 'support',
                    'label' => 'Support Analytics',
                    'scripts' => [
                        'admin_support_analytics', 'admin_support_backlog',
                        'admin_support_kb', 'admin_support_lookup',
                    ],
                ],
            ],
        ],
        [
            'group' => 'Content',
            'keys' => [
                ['key' => 'subjects', 'label' => 'Subjects', 'scripts' => ['admin_subjects']],
                ['key' => 'lessons', 'label' => 'Lessons', 'scripts' => ['admin_lessons']],
                ['key' => 'videos', 'label' => 'Videos', 'scripts' => ['admin_videos']],
                ['key' => 'handouts', 'label' => 'Handouts', 'scripts' => ['admin_handouts']],
                ['key' => 'materials', 'label' => 'Materials', 'scripts' => ['admin_materials', 'admin_materials_diagnose']],
                [
                    'key' => 'quizzes',
                    'label' => 'Quizzes',
                    'scripts' => [
                        'admin_quizzes', 'admin_quiz_questions',
                        'admin_quiz_monitor', 'admin_quiz_attempt_review',
                    ],
                ],
                ['key' => 'test_bank', 'label' => 'Test Bank', 'scripts' => ['admin_test_bank']],
            ],
        ],
        [
            'group' => 'Preboards & Pre-week',
            'keys' => [
                [
                    'key' => 'preboards',
                    'label' => 'Preboards',
                    'scripts' => [
                        'admin_preboards_subjects', 'admin_preboards_sets', 'admin_preboards_questions',
                        'admin_preboards_monitor', 'admin_preboards_attempt_review',
                    ],
                ],
                [
                    'key' => 'preweek',
                    'label' => 'Pre-week',
                    'scripts' => ['admin_preweek', 'admin_preweek_topics', 'admin_preweek_materials'],
                ],
                ['key' => 'question_bank', 'label' => 'Question Bank', 'scripts' => ['admin_question_sort']],
            ],
        ],
        [
            'group' => 'Commerce',
            'keys' => [
                ['key' => 'commerce_packages', 'label' => 'Packages', 'scripts' => ['admin_commerce_packages']],
                ['key' => 'commerce_topics', 'label' => 'By Topic Pricing', 'scripts' => ['admin_commerce_topics']],
                ['key' => 'commerce_gcash', 'label' => 'GCash Settings', 'scripts' => ['admin_commerce_gcash']],
                ['key' => 'commerce_payments', 'label' => 'Payment Verification', 'scripts' => ['admin_commerce_payments']],
                ['key' => 'commerce_free_access', 'label' => 'Free Access', 'scripts' => ['admin_commerce_free_access']],
                ['key' => 'commerce_grants', 'label' => 'Grant Ledger', 'scripts' => ['admin_commerce_grants']],
                ['key' => 'commerce_reports', 'label' => 'Reports', 'scripts' => ['admin_commerce_reports']],
            ],
        ],
    ];
}

/** @return list<string> */
function admin_acl_all_keys(): array
{
    $keys = [];
    foreach (admin_acl_catalog() as $group) {
        foreach ($group['keys'] as $item) {
            $keys[] = $item['key'];
        }
    }
    return $keys;
}

function admin_acl_key_for_script(string $script): ?string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (admin_acl_catalog() as $group) {
            foreach ($group['keys'] as $item) {
                foreach ($item['scripts'] as $s) {
                    $map[$s] = $item['key'];
                }
            }
        }
    }
    return $map[$script] ?? null;
}

function admin_acl_table_ready(mysqli $conn): bool
{
    return ereview_schema_table_exists($conn, 'admin_page_permissions');
}

function admin_acl_log_table_ready(mysqli $conn): bool
{
    return ereview_schema_table_exists($conn, 'users_activity_log');
}

/** Create ACL / activity tables if missing (safe to call repeatedly). */
function admin_acl_ensure_schema(mysqli $conn): void
{
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `admin_page_permissions` (
      `permission_id` bigint(20) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `page_key` varchar(64) NOT NULL,
      `granted_by` int(11) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`permission_id`),
      UNIQUE KEY `uq_admin_page_perm` (`user_id`, `page_key`),
      KEY `idx_app_user` (`user_id`),
      KEY `idx_app_key` (`page_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `users_activity_log` (
      `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
      `actor_user_id` int(11) DEFAULT NULL,
      `actor_email` varchar(120) DEFAULT NULL,
      `actor_role` varchar(32) DEFAULT NULL,
      `action` varchar(64) NOT NULL,
      `target_user_id` int(11) DEFAULT NULL,
      `meta_json` longtext DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `user_agent` varchar(500) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`log_id`),
      KEY `idx_ual_created` (`created_at`),
      KEY `idx_ual_action` (`action`),
      KEY `idx_ual_actor` (`actor_user_id`),
      KEY `idx_ual_target` (`target_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if (function_exists('ereview_schema_session_set')) {
        ereview_schema_session_set('t:admin_page_permissions', true);
        ereview_schema_session_set('t:users_activity_log', true);
    }
}

/**
 * Load granted page keys for a user.
 * Returns null when table missing or user has zero rows (meaning full access).
 * Returns string[] when restricted.
 *
 * @return list<string>|null
 */
function admin_acl_load(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !admin_acl_table_ready($conn)) {
        return null;
    }
    $keys = [];
    $stmt = mysqli_prepare($conn, 'SELECT page_key FROM admin_page_permissions WHERE user_id = ?');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $k = trim((string) ($row['page_key'] ?? ''));
        if ($k !== '') {
            $keys[] = $k;
        }
    }
    mysqli_stmt_close($stmt);
    if ($keys === []) {
        return null; // full access
    }
    return array_values(array_unique($keys));
}

function admin_acl_refresh_session(mysqli $conn, ?int $userId = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        unset($_SESSION['admin_page_keys'], $_SESSION['admin_page_keys_full']);
        return;
    }
    $loaded = admin_acl_load($conn, $uid);
    if ($loaded === null) {
        $_SESSION['admin_page_keys_full'] = 1;
        $_SESSION['admin_page_keys'] = admin_acl_all_keys();
    } else {
        $_SESSION['admin_page_keys_full'] = 0;
        $_SESSION['admin_page_keys'] = $loaded;
    }
}

/**
 * @return list<string>|null null = full access
 */
function admin_acl_session_keys(): ?array
{
    if (!empty($_SESSION['admin_page_keys_full'])) {
        return null;
    }
    if (!isset($_SESSION['admin_page_keys']) || !is_array($_SESSION['admin_page_keys'])) {
        return null; // treat as full until refreshed
    }
    return array_values($_SESSION['admin_page_keys']);
}

function admin_can(string $pageKey): bool
{
    if ($pageKey === '') {
        return true;
    }
    // Non-admins never use this for LMS pages; callers still check role.
    if (($_SESSION['role'] ?? '') !== 'admin') {
        return false;
    }
    $keys = admin_acl_session_keys();
    if ($keys === null) {
        return true;
    }
    return in_array($pageKey, $keys, true);
}

function admin_can_manage_admins(): bool
{
    return admin_can('manage_admins');
}

/**
 * Require admin role + page permission. Auto-detects page key from script if omitted.
 * Prefer calling requireAdminPage() from auth.php.
 */
function admin_acl_require_page(?string $pageKey = null): void
{
    requireRole('admin');
    global $conn;
    if ($conn instanceof mysqli) {
        admin_acl_ensure_schema($conn);
        // Always reload from DB so permission edits apply without re-login.
        admin_acl_refresh_session($conn);
    }
    if ($pageKey === null || $pageKey === '') {
        $script = function_exists('ereview_page_basename')
            ? ereview_page_basename()
            : basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
        $pageKey = admin_acl_key_for_script($script) ?? '';
    }
    if ($pageKey === '') {
        return; // unmapped admin helper - allow if role ok
    }
    if (!admin_can($pageKey)) {
        $_SESSION['error'] = 'You do not have permission to access that admin area.';
        $fallback = 'index';
        if (admin_can('dashboard')) {
            $fallback = 'admin_dashboard';
        } else {
            $keys = admin_acl_session_keys();
            $scriptMap = [
                'manage_admins' => 'admin_admins',
                'students' => 'admin_students',
                'student_activity' => 'admin_student_live',
                'student_access' => 'admin_student_access',
                'support' => 'admin_support_analytics',
                'subjects' => 'admin_subjects',
                'lessons' => 'admin_lessons',
                'videos' => 'admin_videos',
                'handouts' => 'admin_handouts',
                'materials' => 'admin_materials',
                'quizzes' => 'admin_quiz_monitor',
                'test_bank' => 'admin_test_bank',
                'preboards' => 'admin_preboards_subjects',
                'preweek' => 'admin_preweek',
                'question_bank' => 'admin_question_sort',
                'commerce_packages' => 'admin_commerce_packages',
                'commerce_topics' => 'admin_commerce_topics',
                'commerce_gcash' => 'admin_commerce_gcash',
                'commerce_payments' => 'admin_commerce_payments',
                'commerce_free_access' => 'admin_commerce_free_access',
                'commerce_grants' => 'admin_commerce_grants',
                'commerce_reports' => 'admin_commerce_reports',
            ];
            if (is_array($keys)) {
                foreach ($keys as $k) {
                    if (isset($scriptMap[$k])) {
                        $fallback = $scriptMap[$k];
                        break;
                    }
                }
            }
        }
        ereview_redirect($fallback);
        exit;
    }
}

/**
 * Replace permissions for a user. Empty $keys => full access (delete all rows).
 *
 * @param list<string> $keys
 */
function admin_acl_save(mysqli $conn, int $userId, array $keys, ?int $grantedBy = null): bool
{
    if ($userId <= 0 || !admin_acl_table_ready($conn)) {
        return false;
    }
    $valid = array_flip(admin_acl_all_keys());
    $clean = [];
    foreach ($keys as $k) {
        $k = trim((string) $k);
        if ($k !== '' && isset($valid[$k])) {
            $clean[$k] = true;
        }
    }
    $clean = array_keys($clean);

    mysqli_begin_transaction($conn);
    try {
        $del = mysqli_prepare($conn, 'DELETE FROM admin_page_permissions WHERE user_id = ?');
        if (!$del) {
            throw new RuntimeException('delete prepare failed');
        }
        mysqli_stmt_bind_param($del, 'i', $userId);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);

        if ($clean !== []) {
            $ins = mysqli_prepare(
                $conn,
                'INSERT INTO admin_page_permissions (user_id, page_key, granted_by) VALUES (?, ?, ?)'
            );
            if (!$ins) {
                throw new RuntimeException('insert prepare failed');
            }
            foreach ($clean as $pageKey) {
                $gb = $grantedBy !== null ? (int) $grantedBy : null;
                // Bind granted_by as string so PHP null stays SQL NULL (int bind coerces null → 0).
                $gbBind = $gb !== null ? (string) $gb : null;
                mysqli_stmt_bind_param($ins, 'iss', $userId, $pageKey, $gbBind);
                mysqli_stmt_execute($ins);
            }
            mysqli_stmt_close($ins);
        }
        mysqli_commit($conn);
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return false;
    }
}

/**
 * Broad activity logger. Safe no-op if table missing.
 *
 * @param array<string,mixed> $meta
 */
function users_activity_log(
    mysqli $conn,
    string $action,
    array $meta = [],
    ?int $actorUserId = null,
    ?string $actorEmail = null,
    ?string $actorRole = null,
    ?int $targetUserId = null
): void {
    if ($action === '' || !admin_acl_log_table_ready($conn)) {
        return;
    }
    if ($actorUserId === null && isset($_SESSION['user_id'])) {
        $actorUserId = (int) $_SESSION['user_id'];
    }
    if ($actorEmail === null && isset($_SESSION['email'])) {
        $actorEmail = (string) $_SESSION['email'];
    }
    if ($actorRole === null && isset($_SESSION['role'])) {
        $actorRole = (string) $_SESSION['role'];
    }
    $ip = '';
    if (function_exists('getLoginClientIp')) {
        $ip = (string) getLoginClientIp();
    } else {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $metaJson = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        $metaJson = null;
    }

    $aemail = $actorEmail !== null ? substr($actorEmail, 0, 120) : null;
    $arole = $actorRole !== null ? substr($actorRole, 0, 32) : null;
    // Bind nullable ints as strings so NULL stays NULL.
    $aidStr = $actorUserId !== null ? (string) (int) $actorUserId : null;
    $tidStr = $targetUserId !== null ? (string) (int) $targetUserId : null;
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO users_activity_log
            (actor_user_id, actor_email, actor_role, action, target_user_id, meta_json, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssss',
        $aidStr,
        $aemail,
        $arole,
        $action,
        $tidStr,
        $metaJson,
        $ip,
        $ua
    );
    @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Human labels for activity log actions (Users Log UI + filters).
 *
 * @return array<string,string>
 */
function users_activity_log_action_labels(): array
{
    return [
        'login_success' => 'Logged in',
        'login_fail' => 'Login failed',
        'admin_created' => 'Created admin',
        'admin_account_updated' => 'Updated admin account',
        'admin_permissions_updated' => 'Updated admin permissions',
        'account_activated' => 'Activated student account',
        'access_granted' => 'Granted student access',
        'student_deleted' => 'Deleted student',
        'quiz_created' => 'Created quiz',
        'quiz_updated' => 'Updated quiz',
        'quiz_deleted' => 'Deleted quiz',
        'quiz_questions_uploaded' => 'Uploaded quiz questions',
        'quiz_question_added' => 'Added quiz question',
        'quiz_question_updated' => 'Updated quiz question',
        'quiz_question_deleted' => 'Deleted quiz question',
        'material_handout_uploaded' => 'Uploaded handout',
        'material_handout_updated' => 'Updated handout',
        'material_video_added' => 'Added lesson video',
        'material_video_updated' => 'Updated lesson video',
        'handout_uploaded' => 'Uploaded handout',
        'video_added' => 'Added video',
        'lesson_created' => 'Created lesson',
        'lesson_updated' => 'Updated lesson',
        'subject_created' => 'Created subject',
        'subject_updated' => 'Updated subject',
    ];
}

/**
 * Present a log row for the Users Log UI (label, summary, deep links).
 *
 * @param array<string,mixed> $row
 * @return array{label:string, summary:string, href:?string, target_label:string, target_href:?string, actor_href:?string}
 */
function users_activity_log_present(array $row): array
{
    $action = (string) ($row['action'] ?? '');
    $meta = [];
    if (!empty($row['meta_json'])) {
        $decoded = json_decode((string) $row['meta_json'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    $labels = users_activity_log_action_labels();
    $label = $labels[$action] ?? ucwords(str_replace('_', ' ', $action));

    $quizId = (int) ($meta['quiz_id'] ?? 0);
    $subjectId = (int) ($meta['subject_id'] ?? 0);
    $lessonId = (int) ($meta['lesson_id'] ?? 0);
    $quizTitle = trim((string) ($meta['quiz_title'] ?? $meta['title'] ?? ''));
    $subjectName = trim((string) ($meta['subject_name'] ?? ''));
    $lessonTitle = trim((string) ($meta['lesson_title'] ?? ''));
    $count = (int) ($meta['count'] ?? $meta['questions_count'] ?? 0);
    $fileName = trim((string) ($meta['file_name'] ?? $meta['handout_title'] ?? $meta['video_title'] ?? ''));

    $summaryParts = [];
    if ($quizTitle !== '') {
        $summaryParts[] = $quizTitle;
    }
    if ($subjectName !== '') {
        $summaryParts[] = $subjectName;
    }
    if ($lessonTitle !== '') {
        $summaryParts[] = $lessonTitle;
    }
    if ($fileName !== '' && $fileName !== $quizTitle && $fileName !== $lessonTitle) {
        $summaryParts[] = $fileName;
    }
    if ($count > 0 && strpos($action, 'question') !== false) {
        $summaryParts[] = $count . ' question' . ($count === 1 ? '' : 's');
    }
    if ($action === 'login_fail' && !empty($meta['reason'])) {
        $summaryParts[] = (string) $meta['reason'];
    }
    if (($action === 'admin_account_updated' || $action === 'admin_permissions_updated') && !empty($meta['keys']) && is_array($meta['keys'])) {
        $keys = $meta['keys'];
        $summaryParts[] = !empty($meta['full_access']) || $keys === ['*']
            ? 'full access'
            : (count($keys) . ' area(s)');
    }
    if (!empty($meta['password_changed'])) {
        $summaryParts[] = 'password changed';
    }
    $summary = implode(' · ', array_values(array_unique($summaryParts)));

    $href = null;
    if ($quizId > 0 && $subjectId > 0 && strpos($action, 'quiz_question') === 0) {
        $href = 'admin_quiz_questions?quiz_id=' . $quizId . '&subject_id=' . $subjectId;
    } elseif ($quizId > 0 && $subjectId > 0 && strpos($action, 'quiz_') === 0) {
        $href = 'admin_quizzes?subject_id=' . $subjectId;
    } elseif ($quizId > 0 && strpos($action, 'quiz_question') === 0) {
        $href = 'admin_quiz_questions?quiz_id=' . $quizId;
    } elseif ($lessonId > 0 && $subjectId > 0 && (strpos($action, 'material_') === 0 || $action === 'handout_uploaded' || $action === 'video_added')) {
        $href = 'admin_materials?lesson_id=' . $lessonId . '&subject_id=' . $subjectId;
    } elseif ($lessonId > 0 && strpos($action, 'lesson_') === 0) {
        $href = 'admin_lessons?subject_id=' . max(1, $subjectId);
    } elseif ($subjectId > 0 && strpos($action, 'subject_') === 0) {
        $href = 'admin_subjects';
    } elseif ($action === 'admin_created' || $action === 'admin_account_updated' || $action === 'admin_permissions_updated') {
        $tid = (int) ($row['target_user_id'] ?? 0);
        if ($tid > 0) {
            $href = 'admin_admins?view=admins&edit=' . $tid;
        }
    } elseif (in_array($action, ['account_activated', 'access_granted', 'student_deleted'], true)) {
        $tid = (int) ($row['target_user_id'] ?? 0);
        if ($tid > 0 && $action !== 'student_deleted') {
            $href = 'admin_student_view?id=' . $tid;
        } else {
            $href = 'admin_students';
        }
    }

    $targetLabel = '-';
    $targetHref = null;
    $tid = (int) ($row['target_user_id'] ?? 0);
    if ($tid > 0) {
        $targetLabel = trim((string) ($meta['email'] ?? ('#' . $tid)));
        if ($targetLabel === '') {
            $targetLabel = '#' . $tid;
        }
        if (in_array($action, ['admin_created', 'admin_account_updated', 'admin_permissions_updated'], true)
            || (($row['actor_role'] ?? '') === 'admin' && strpos($action, 'admin_') === 0)) {
            $targetHref = 'admin_admins?view=admins&edit=' . $tid;
        } elseif ($action !== 'student_deleted') {
            $targetHref = 'admin_student_view?id=' . $tid;
        }
    } elseif ($quizTitle !== '' || $quizId > 0) {
        $targetLabel = $quizTitle !== '' ? $quizTitle : ('Quiz #' . $quizId);
        $targetHref = $href;
    } elseif ($lessonTitle !== '' || $lessonId > 0) {
        $targetLabel = $lessonTitle !== '' ? $lessonTitle : ('Lesson #' . $lessonId);
        $targetHref = $href;
    } elseif ($fileName !== '') {
        $targetLabel = $fileName;
        $targetHref = $href;
    }

    $actorHref = null;
    $actorId = (int) ($row['actor_user_id'] ?? 0);
    $actorRole = (string) ($row['actor_role'] ?? '');
    if ($actorId > 0) {
        $actorHref = $actorRole === 'admin'
            ? ('admin_admins?view=admins&edit=' . $actorId)
            : ('admin_student_view?id=' . $actorId);
    }

    return [
        'label' => $label,
        'summary' => $summary,
        'href' => $href,
        'target_label' => $targetLabel,
        'target_href' => $targetHref,
        'actor_href' => $actorHref,
    ];
}

/**
 * Content tools already reachable from Subjects (Content Hub).
 * Full-access / super admins only need Subjects in the sidebar.
 *
 * @return list<string>
 */
function admin_acl_content_hub_child_keys(): array
{
    return ['lessons', 'videos', 'handouts', 'materials', 'quizzes', 'test_bank'];
}

function admin_acl_is_full_access(): bool
{
    return admin_acl_session_keys() === null;
}

/**
 * Annotate sidebar nav by ACL. Keep all items visible; mark locked ones
 * so the UI stays complete but unauthorized links are not clickable.
 *
 * Full-access admins: hide Content Hub children (Lessons/Videos/...) - they
 * open those from Subjects. Restricted staff still see their granted pages.
 *
 * @param list<array<string,mixed>> $navConfig
 * @return list<array<string,mixed>>
 */
function admin_acl_filter_nav(array $navConfig): array
{
    $fullAccess = admin_acl_is_full_access();
    $hubChildren = array_fill_keys(admin_acl_content_hub_child_keys(), true);

    $out = [];
    foreach ($navConfig as $section) {
        $items = [];
        $sectionLabel = (string) ($section['label'] ?? $section['title'] ?? '');
        foreach (($section['items'] ?? []) as $item) {
            $key = (string) ($item['acl_key'] ?? '');

            // Super / full-access: Content Hub only - drop redundant siblings.
            if (
                $fullAccess
                && strcasecmp($sectionLabel, 'Content') === 0
                && $key !== ''
                && isset($hubChildren[$key])
            ) {
                continue;
            }

            $allowed = ($key === '' || admin_can($key));
            $item['acl_allowed'] = $allowed;
            $item['acl_locked'] = !$allowed;
            if (!$allowed) {
                $label = (string) ($item['label'] ?? 'this area');
                $item['title'] = 'Locked - no access to ' . $label;
                $item['href'] = '#';
                unset($item['badge']);
            }

            // Full-access: treat Subjects as the Content Hub entry.
            if ($fullAccess && $key === 'subjects') {
                $item['label'] = 'Content Hub';
                $item['title'] = 'Subjects, lessons, videos, handouts, materials, quizzes & test bank';
                $item['active'] = [
                    'admin_subjects',
                    'admin_lessons',
                    'admin_videos',
                    'admin_handouts',
                    'admin_materials',
                    'admin_quizzes',
                    'admin_quiz_questions',
                    'admin_test_bank',
                ];
            }

            $items[] = $item;
        }
        if ($items !== []) {
            $section['items'] = $items;
            $out[] = $section;
        }
    }
    return $out;
}
