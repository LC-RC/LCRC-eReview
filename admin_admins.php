<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/admin_acl.php';

requireAdminPage('manage_admins');
admin_acl_ensure_schema($conn);
admin_acl_refresh_session($conn);

$csrf = generateCSRFToken();
$view = (string) ($_GET['view'] ?? 'admins');
if (!in_array($view, ['admins', 'log'], true)) {
    $view = 'admins';
}

$flashOk = '';
$flashErr = '';
$editId = (int) ($_GET['edit'] ?? 0);

function admin_admins_has_email_verified(mysqli $conn): bool
{
    return ereview_schema_column_exists($conn, 'users', 'email_verified');
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_admins?view=admins');
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $actorId = (int) getCurrentUserId();

    if ($action === 'create_admin') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $keys = isset($_POST['page_keys']) && is_array($_POST['page_keys']) ? $_POST['page_keys'] : [];
        $fullAccess = !empty($_POST['full_access']);

        if ($fullName === '' || $email === '' || $password === '') {
            $_SESSION['error'] = 'Name, email, and password are required.';
            header('Location: admin_admins?view=admins&add=1');
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Enter a valid email address.';
            header('Location: admin_admins?view=admins&add=1');
            exit;
        }
        if (strlen($password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters.';
            header('Location: admin_admins?view=admins&add=1');
            exit;
        }

        $chk = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? LIMIT 1');
        mysqli_stmt_bind_param($chk, 's', $email);
        mysqli_stmt_execute($chk);
        $exists = mysqli_stmt_get_result($chk);
        $dup = $exists ? mysqli_fetch_assoc($exists) : null;
        mysqli_stmt_close($chk);
        if ($dup) {
            $_SESSION['error'] = 'That email is already registered.';
            header('Location: admin_admins?view=admins&add=1');
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $hasEv = admin_admins_has_email_verified($conn);
        if ($hasEv) {
            $ins = mysqli_prepare(
                $conn,
                "INSERT INTO users (full_name, review_type, school, email, password, role, status, email_verified)
                 VALUES (?, 'reviewee', 'Admin', ?, ?, 'admin', 'approved', 1)"
            );
            mysqli_stmt_bind_param($ins, 'sss', $fullName, $email, $hash);
        } else {
            $ins = mysqli_prepare(
                $conn,
                "INSERT INTO users (full_name, review_type, school, email, password, role, status)
                 VALUES (?, 'reviewee', 'Admin', ?, ?, 'admin', 'approved')"
            );
            mysqli_stmt_bind_param($ins, 'sss', $fullName, $email, $hash);
        }
        if (!$ins || !mysqli_stmt_execute($ins)) {
            if ($ins) {
                mysqli_stmt_close($ins);
            }
            $_SESSION['error'] = 'Could not create admin account.';
            header('Location: admin_admins?view=admins&add=1');
            exit;
        }
        $newId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);

        if (!$fullAccess) {
            admin_acl_save($conn, $newId, $keys, $actorId);
        } else {
            admin_acl_save($conn, $newId, [], $actorId); // empty = full
        }

        users_activity_log($conn, 'admin_created', [
            'email' => $email,
            'full_access' => $fullAccess ? 1 : 0,
            'keys' => $fullAccess ? ['*'] : array_values($keys),
        ], $actorId, null, 'admin', $newId);

        $_SESSION['message'] = 'Admin account created for ' . $email . '.';
        header('Location: admin_admins?view=admins');
        exit;
    }

    if ($action === 'save_permissions' || $action === 'save_admin') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $keys = isset($_POST['page_keys']) && is_array($_POST['page_keys']) ? $_POST['page_keys'] : [];
        $fullAccess = !empty($_POST['full_access']);
        if ($targetId <= 0) {
            $_SESSION['error'] = 'Invalid admin user.';
            header('Location: admin_admins?view=admins');
            exit;
        }
        $uStmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, role FROM users WHERE user_id = ? AND role = 'admin' LIMIT 1");
        mysqli_stmt_bind_param($uStmt, 'i', $targetId);
        mysqli_stmt_execute($uStmt);
        $uRes = mysqli_stmt_get_result($uStmt);
        $target = $uRes ? mysqli_fetch_assoc($uRes) : null;
        mysqli_stmt_close($uStmt);
        if (!$target) {
            $_SESSION['error'] = 'Admin not found.';
            header('Location: admin_admins?view=admins');
            exit;
        }
        if ($fullName === '' || $email === '') {
            $_SESSION['error'] = 'Name and email are required.';
            header('Location: admin_admins?view=admins&edit=' . $targetId);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Enter a valid email address.';
            header('Location: admin_admins?view=admins&edit=' . $targetId);
            exit;
        }
        if ($password !== '' && strlen($password) < 8) {
            $_SESSION['error'] = 'New password must be at least 8 characters.';
            header('Location: admin_admins?view=admins&edit=' . $targetId);
            exit;
        }

        $dupStmt = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
        mysqli_stmt_bind_param($dupStmt, 'si', $email, $targetId);
        mysqli_stmt_execute($dupStmt);
        $dupRes = mysqli_stmt_get_result($dupStmt);
        $dup = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
        mysqli_stmt_close($dupStmt);
        if ($dup) {
            $_SESSION['error'] = 'That email is already registered.';
            header('Location: admin_admins?view=admins&edit=' . $targetId);
            exit;
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd = mysqli_prepare($conn, 'UPDATE users SET full_name = ?, email = ?, password = ? WHERE user_id = ? AND role = \'admin\' LIMIT 1');
            mysqli_stmt_bind_param($upd, 'sssi', $fullName, $email, $hash, $targetId);
        } else {
            $upd = mysqli_prepare($conn, 'UPDATE users SET full_name = ?, email = ? WHERE user_id = ? AND role = \'admin\' LIMIT 1');
            mysqli_stmt_bind_param($upd, 'ssi', $fullName, $email, $targetId);
        }
        if (!$upd || !mysqli_stmt_execute($upd)) {
            if ($upd) {
                mysqli_stmt_close($upd);
            }
            $_SESSION['error'] = 'Could not update account details.';
            header('Location: admin_admins?view=admins&edit=' . $targetId);
            exit;
        }
        mysqli_stmt_close($upd);

        $ok = admin_acl_save($conn, $targetId, $fullAccess ? [] : $keys, $actorId);
        if (!$ok) {
            $_SESSION['error'] = 'Account saved, but permissions failed to update.';
            header('Location: admin_admins?view=admins&edit=' . $targetId);
            exit;
        }
        if ($targetId === $actorId) {
            $_SESSION['full_name'] = $fullName;
            $_SESSION['email'] = $email;
            admin_acl_refresh_session($conn, $actorId);
        }
        users_activity_log($conn, 'admin_account_updated', [
            'email' => $email,
            'name_changed' => (($target['full_name'] ?? '') !== $fullName) ? 1 : 0,
            'email_changed' => (strtolower((string) ($target['email'] ?? '')) !== $email) ? 1 : 0,
            'password_changed' => $password !== '' ? 1 : 0,
            'full_access' => $fullAccess ? 1 : 0,
            'keys' => $fullAccess ? ['*'] : array_values($keys),
        ], $actorId, null, 'admin', $targetId);
        $_SESSION['message'] = $password !== ''
            ? 'Admin account, password, and permissions updated.'
            : 'Admin account and permissions updated.';
        header('Location: admin_admins?view=admins');
        exit;
    }
}

if (!empty($_SESSION['message'])) {
    $flashOk = (string) $_SESSION['message'];
    unset($_SESSION['message']);
}
if (!empty($_SESSION['error'])) {
    $flashErr = (string) $_SESSION['error'];
    unset($_SESSION['error']);
}

// ---- Load admins ----
$admins = [];
$adminRes = @mysqli_query(
    $conn,
    "SELECT user_id, full_name, email, status, created_at
     FROM users WHERE role = 'admin' ORDER BY full_name ASC, user_id ASC"
);
if ($adminRes) {
    while ($row = mysqli_fetch_assoc($adminRes)) {
        $uid = (int) $row['user_id'];
        $loaded = admin_acl_load($conn, $uid);
        $row['acl_full'] = $loaded === null;
        $row['acl_keys'] = $loaded ?? admin_acl_all_keys();
        $admins[] = $row;
    }
    mysqli_free_result($adminRes);
}

$editAdmin = null;
$editKeys = [];
$editFull = true;
$openAdd = (string) ($_GET['add'] ?? '') === '1';
if ($editId > 0) {
    foreach ($admins as $a) {
        if ((int) $a['user_id'] === $editId) {
            $editAdmin = $a;
            $editFull = !empty($a['acl_full']);
            $editKeys = $a['acl_keys'] ?? [];
            break;
        }
    }
}

$aclKeyLabels = [];
foreach (admin_acl_catalog() as $group) {
    foreach (($group['keys'] ?? []) as $item) {
        $aclKeyLabels[(string) $item['key']] = (string) $item['label'];
    }
}

$adminsJs = [];
foreach ($admins as $a) {
    $adminsJs[] = [
        'user_id' => (int) $a['user_id'],
        'full_name' => (string) $a['full_name'],
        'email' => (string) $a['email'],
        'status' => (string) ($a['status'] ?? 'approved'),
        'acl_full' => !empty($a['acl_full']),
        'acl_keys' => array_values($a['acl_keys'] ?? []),
    ];
}

// ---- Users log ----
$logRows = [];
$logTotal = 0;
$logPage = max(1, (int) ($_GET['page'] ?? 1));
$logPer = 40;
$logQ = trim((string) ($_GET['q'] ?? ''));
$logAction = trim((string) ($_GET['action'] ?? ''));
$logFrom = trim((string) ($_GET['from'] ?? ''));
$logTo = trim((string) ($_GET['to'] ?? ''));

if ($view === 'log' && admin_acl_log_table_ready($conn)) {
    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($logQ !== '') {
        $where[] = '(actor_email LIKE ? OR actor_role LIKE ? OR action LIKE ? OR meta_json LIKE ?)';
        $like = '%' . $logQ . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($logAction !== '') {
        $where[] = 'action = ?';
        $types .= 's';
        $params[] = $logAction;
    }
    if ($logFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logFrom)) {
        $where[] = 'created_at >= ?';
        $types .= 's';
        $params[] = $logFrom . ' 00:00:00';
    }
    if ($logTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logTo)) {
        $where[] = 'created_at <= ?';
        $types .= 's';
        $params[] = $logTo . ' 23:59:59';
    }
    $whereSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) AS cnt FROM users_activity_log WHERE {$whereSql}";
    if ($types !== '') {
        $cStmt = mysqli_prepare($conn, $countSql);
        mysqli_stmt_bind_param($cStmt, $types, ...$params);
        mysqli_stmt_execute($cStmt);
        $cRes = mysqli_stmt_get_result($cStmt);
        $logTotal = (int) (($cRes ? mysqli_fetch_assoc($cRes)['cnt'] : 0) ?? 0);
        mysqli_stmt_close($cStmt);
    } else {
        $cRes = mysqli_query($conn, $countSql);
        $logTotal = (int) (($cRes ? mysqli_fetch_assoc($cRes)['cnt'] : 0) ?? 0);
    }

    $offset = ($logPage - 1) * $logPer;
    $listSql = "SELECT log_id, actor_user_id, actor_email, actor_role, action, target_user_id, meta_json, ip_address, created_at
                FROM users_activity_log WHERE {$whereSql}
                ORDER BY created_at DESC, log_id DESC LIMIT ? OFFSET ?";
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$logPer, $offset]);
    $lStmt = mysqli_prepare($conn, $listSql);
    mysqli_stmt_bind_param($lStmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($lStmt);
    $lRes = mysqli_stmt_get_result($lStmt);
    while ($lRes && ($lr = mysqli_fetch_assoc($lRes))) {
        $logRows[] = $lr;
    }
    mysqli_stmt_close($lStmt);
}

$logPages = max(1, (int) ceil($logTotal / $logPer));
$catalog = admin_acl_catalog();
$allKeys = admin_acl_all_keys();

$pageTitle = 'Admins';
$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Admins']];
$adminHeroIcon = 'shield-lock';
$adminHeroTitle = 'Admins';
$adminHeroSubtitle = 'Create staff accounts, choose unlocked admin areas, and review the users activity log.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    /* Beat admin-saas .admin-content { max-width: 1400px } — use full main column */
    html body.admin-app.admin-admins-page #main.admin-main-shell > .admin-content,
    html body.admin-app.admin-admins-page #main > .admin-content,
    html body.admin-app.admin-admins-page #main .admin-content {
      max-width: none !important;
      width: 100% !important;
      margin-left: 0 !important;
      margin-right: 0 !important;
      padding-left: 1.25rem !important;
      padding-right: 1.25rem !important;
      box-sizing: border-box !important;
      align-self: stretch !important;
    }
    @media (min-width: 768px) {
      html body.admin-app.admin-admins-page #main > .admin-content {
        padding-left: 1.5rem !important;
        padding-right: 1.5rem !important;
      }
    }
    html body.admin-app.admin-admins-page #main .quiz-admin-hero,
    html body.admin-app.admin-admins-page #main .page-hero,
    html body.admin-app.admin-admins-page #main .acl-tabs,
    html body.admin-app.admin-admins-page #main .acl-staff-shell,
    html body.admin-app.admin-admins-page #main .acl-log-shell {
      width: 100% !important;
      max-width: none !important;
      box-sizing: border-box;
    }
    body.admin-app.admin-admins-page.acl-modal-open {
      overflow: hidden;
    }

    .acl-tabs {
      display: inline-flex; gap: 0.2rem; padding: 0.22rem; margin-bottom: 1rem;
      border-radius: 0.85rem; border: 1px solid var(--admin-border); background: var(--admin-glass);
    }
    .acl-tab {
      padding: 0.48rem 1rem; border-radius: 0.65rem; text-decoration: none;
      font-weight: 650; font-size: 0.84rem; color: var(--admin-text-secondary);
      transition: background .15s ease, color .15s ease;
    }
    .acl-tab:hover { color: var(--admin-text); background: rgba(59,130,246,.08); }
    .acl-tab.is-active {
      background: linear-gradient(135deg, rgba(59,130,246,.28), rgba(37,99,235,.16));
      color: #fff; box-shadow: inset 0 0 0 1px rgba(147,197,253,.28);
    }

    .acl-section-label {
      margin: 0 0 0.55rem;
      font-size: 0.7rem; letter-spacing: .1em; text-transform: uppercase;
      font-weight: 800; color: #93c5fd;
    }
    html[data-admin-theme="light"] .acl-section-label { color: #1d4ed8; }
    .acl-hint {
      margin: 0.2rem 0 0; font-size: 0.72rem; color: var(--admin-text-muted);
    }

    .acl-card {
      border: 1px solid var(--admin-border-strong, var(--admin-border));
      border-radius: 1rem;
      background: var(--admin-glass-strong, var(--admin-glass));
      box-shadow: var(--admin-shadow);
      overflow: hidden;
    }
    .acl-card__head {
      display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
      padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--admin-border);
    }
    .acl-card__head h2 {
      margin: 0; font-size: 1rem; font-weight: 750; letter-spacing: -0.02em; color: var(--admin-text);
    }
    .acl-card__sub { margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--admin-text-muted); }
    .acl-card__body { padding: 0; }
    .acl-card__body--pad { padding: 1rem 1.1rem 1.15rem; }
    .acl-count {
      font-size: 0.72rem; font-weight: 800; color: var(--admin-text-muted);
      padding: 0.2rem 0.55rem; border-radius: 999px; border: 1px solid var(--admin-border);
      background: rgba(255,255,255,.03);
    }

    .acl-avatar {
      width: 2.35rem; height: 2.35rem; border-radius: 0.7rem; flex-shrink: 0;
      display: grid; place-items: center;
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      color: #fff; font-weight: 800; font-size: 0.85rem;
    }
    .acl-admin-cell {
      display: flex; align-items: center; gap: 0.7rem; min-width: 0;
    }
    .acl-admin-name {
      font-size: 0.9rem; font-weight: 700; color: var(--admin-text);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .acl-admin-email {
      font-size: 0.8rem; color: var(--admin-text-muted);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      max-width: 18rem;
    }
    .acl-pill {
      display: inline-flex; align-items: center; gap: 0.25rem;
      padding: 0.2rem 0.55rem; border-radius: 999px;
      font-size: 0.72rem; font-weight: 750; line-height: 1.2;
      border: 1px solid var(--admin-border); color: var(--admin-text-secondary);
      background: rgba(255,255,255,.04);
      white-space: nowrap;
    }
    .acl-pill--full {
      color: #86efac; border-color: rgba(34,197,94,.4);
      background: rgba(22,163,74,.16);
    }
    html[data-admin-theme="light"] .acl-pill--full {
      color: #15803d; border-color: rgba(22,163,74,.35);
      background: rgba(22,163,74,.1);
    }
    .acl-pill--status {
      color: #86efac; border-color: rgba(34,197,94,.35);
      background: rgba(22,163,74,.12);
    }
    html[data-admin-theme="light"] .acl-pill--status {
      color: #15803d; background: rgba(22,163,74,.1);
    }
    .acl-pill--muted {
      color: var(--admin-text-muted);
    }
    .acl-access-detail {
      display: block; margin-top: 0.2rem;
      font-size: 0.7rem; color: var(--admin-text-muted);
      max-width: 16rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .acl-empty {
      padding: 2rem 1rem; text-align: center;
      font-size: 0.84rem; color: var(--admin-text-muted);
    }

    .acl-table-wrap {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .acl-staff-table {
      width: 100%;
      min-width: 52rem;
      border-collapse: collapse;
      font-size: 0.86rem;
    }
    .acl-staff-table th,
    .acl-staff-table td {
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--admin-border);
      text-align: left;
      vertical-align: middle;
    }
    .acl-staff-table th {
      color: var(--admin-text-muted);
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      font-weight: 800;
      background: rgba(0,0,0,.08);
      white-space: nowrap;
    }
    html[data-admin-theme="light"] .acl-staff-table th {
      background: rgba(15,23,42,.03);
    }
    .acl-staff-table tbody tr {
      transition: background .15s ease;
    }
    .acl-staff-table tbody tr:hover td {
      background: rgba(59,130,246,.06);
    }
    .acl-staff-table td:last-child {
      white-space: nowrap;
    }
    .acl-edit-btn {
      transition: border-color .15s ease, background .15s ease, color .15s ease;
    }
    .acl-edit-btn:hover {
      border-color: rgba(59,130,246,.45) !important;
      background: rgba(59,130,246,.1) !important;
    }

    .acl-field { margin: 0; }
    .acl-field label {
      display: block; font-size: 0.76rem; font-weight: 700;
      margin-bottom: 0.28rem; color: var(--admin-text-secondary);
    }
    .acl-field input[type="text"], .acl-field input[type="email"], .acl-field input[type="password"],
    .acl-field input[type="date"], .acl-field input[type="search"], .acl-field select {
      width: 100%; min-height: 2.45rem; border-radius: 0.7rem;
      border: 1px solid var(--admin-border-strong, var(--admin-border));
      background: rgba(15,23,42,.35); color: var(--admin-text);
      padding: 0.45rem 0.75rem; font-size: 0.88rem;
      box-sizing: border-box;
    }
    html[data-admin-theme="light"] .acl-field input[type="text"],
    html[data-admin-theme="light"] .acl-field input[type="email"],
    html[data-admin-theme="light"] .acl-field input[type="password"],
    html[data-admin-theme="light"] .acl-field input[type="date"],
    html[data-admin-theme="light"] .acl-field input[type="search"],
    html[data-admin-theme="light"] .acl-field select {
      background: rgba(255,255,255,.92);
    }
    .acl-field input:focus {
      outline: none; border-color: rgba(59,130,246,.65);
      box-shadow: 0 0 0 3px rgba(59,130,246,.18);
    }
    .acl-form-stack { display: flex; flex-direction: column; gap: 0.85rem; }

    .acl-pass-wrap { position: relative; }
    .acl-pass-wrap input {
      padding-right: 2.75rem !important;
    }
    .acl-pass-toggle {
      position: absolute; right: 0.35rem; top: 50%; transform: translateY(-50%);
      width: 2.1rem; height: 2.1rem; border: 0; border-radius: 0.55rem;
      background: transparent; color: var(--admin-text-muted); cursor: pointer;
      display: grid; place-items: center; padding: 0;
      transition: color .15s ease, background .15s ease;
    }
    .acl-pass-toggle:hover {
      color: var(--admin-text);
      background: rgba(59,130,246,.1);
    }
    .acl-pass-toggle:focus-visible {
      outline: 2px solid rgba(59,130,246,.55);
      outline-offset: 1px;
    }

    .acl-full-toggle {
      display: flex; align-items: flex-start; gap: 0.65rem;
      margin: 0.15rem 0; padding: 0.75rem 0.85rem;
      border-radius: 0.8rem; border: 1px solid rgba(59,130,246,.28);
      background: rgba(59,130,246,.08); cursor: pointer;
    }
    .acl-full-toggle input { margin-top: 0.15rem; width: 1.1rem; height: 1.1rem; accent-color: #2563eb; }
    .acl-full-toggle strong { display: block; color: var(--admin-text); font-size: 0.88rem; }
    .acl-full-toggle span { display: block; margin-top: 0.15rem; font-size: 0.75rem; color: var(--admin-text-muted); }

    .acl-keys-panel {
      border: 1px solid var(--admin-border);
      border-radius: 0.85rem;
      background: rgba(0,0,0,.12);
      max-height: min(18rem, 40vh);
      overflow: auto;
      padding: 0.65rem 0.75rem 0.85rem;
    }
    html[data-admin-theme="light"] .acl-keys-panel { background: rgba(15,23,42,.03); }
    .acl-group {
      margin: 0.85rem 0 0.4rem; padding-bottom: 0.25rem;
      font-size: 0.7rem; letter-spacing: .1em; text-transform: uppercase;
      font-weight: 800; color: #93c5fd;
      border-bottom: 1px solid rgba(147,197,253,.18);
    }
    .acl-group:first-child { margin-top: 0.15rem; }
    html[data-admin-theme="light"] .acl-group { color: #1d4ed8; border-bottom-color: rgba(37,99,235,.15); }
    .acl-key-grid {
      display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.2rem 0.55rem;
    }
    @media (max-width: 640px) { .acl-key-grid { grid-template-columns: 1fr; } }
    .acl-check {
      display: flex; align-items: center; gap: 0.45rem;
      padding: 0.38rem 0.35rem; border-radius: 0.45rem;
      font-size: 0.82rem; color: var(--admin-text); cursor: pointer;
    }
    .acl-check:hover { background: rgba(59,130,246,.08); }
    .acl-check input { width: 1rem; height: 1rem; accent-color: #2563eb; flex-shrink: 0; }

    .acl-modal {
      position: fixed; inset: 0; z-index: 80;
      display: none; align-items: center; justify-content: center;
      padding: 1rem;
      background: rgba(2, 6, 23, 0.55);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
    }
    .acl-modal.is-open { display: flex; }
    .acl-modal__panel {
      width: min(36rem, 100%);
      max-height: min(92vh, 52rem);
      display: flex; flex-direction: column;
      border-radius: 1rem;
      border: 1px solid var(--admin-border-strong, var(--admin-border));
      background: var(--admin-glass-strong, var(--admin-surface, #0f172a));
      box-shadow: 0 24px 60px rgba(0,0,0,.35);
      overflow: hidden;
    }
    html[data-admin-theme="light"] .acl-modal__panel {
      background: #fff;
      box-shadow: 0 24px 60px rgba(15,23,42,.18);
    }
    .acl-modal__head {
      display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;
      padding: 1rem 1.15rem;
      border-bottom: 1px solid var(--admin-border);
      flex-shrink: 0;
    }
    .acl-modal__head h2 {
      margin: 0; font-size: 1.05rem; font-weight: 780; color: var(--admin-text);
      letter-spacing: -0.02em;
    }
    .acl-modal__sub {
      margin: 0.2rem 0 0; font-size: 0.78rem; color: var(--admin-text-muted);
    }
    .acl-modal__close {
      width: 2.2rem; height: 2.2rem; border-radius: 0.65rem;
      border: 1px solid var(--admin-border); background: rgba(255,255,255,.04);
      color: var(--admin-text-secondary); cursor: pointer;
      display: grid; place-items: center; flex-shrink: 0;
      transition: background .15s ease, color .15s ease, border-color .15s ease;
    }
    .acl-modal__close:hover {
      color: var(--admin-text);
      border-color: rgba(59,130,246,.4);
      background: rgba(59,130,246,.1);
    }
    .acl-modal__body {
      padding: 1.05rem 1.15rem;
      overflow-y: auto;
      flex: 1 1 auto;
      min-height: 0;
    }
    .acl-modal__foot {
      display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.5rem;
      padding: 0.9rem 1.15rem;
      border-top: 1px solid var(--admin-border);
      flex-shrink: 0;
      background: rgba(0,0,0,.08);
    }
    html[data-admin-theme="light"] .acl-modal__foot {
      background: rgba(15,23,42,.02);
    }

    .acl-log-shell { width: 100%; }
    .acl-log-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
    .acl-log-table th, .acl-log-table td {
      padding: 0.7rem 0.65rem; border-bottom: 1px solid var(--admin-border);
      text-align: left; vertical-align: top;
    }
    .acl-log-table th {
      color: var(--admin-text-muted); font-size: 0.7rem;
      text-transform: uppercase; letter-spacing: .06em;
    }
    .acl-log-table tr:hover td { background: rgba(59,130,246,.05); }
    .acl-filters { display: flex; flex-wrap: wrap; gap: 0.55rem; margin-bottom: 0.9rem; align-items: end; }
    .acl-filters .acl-field { min-width: 9rem; }
    .acl-filters .acl-field--grow { flex: 1 1 14rem; min-width: 12rem; }
    .acl-meta { color: var(--admin-text-muted); font-size: 0.75rem; word-break: break-word; max-width: 28rem; }
    .acl-action-label { display: block; font-weight: 700; color: var(--admin-text); }
    .acl-action-code { display: block; margin-top: 0.15rem; font-size: 0.68rem; color: var(--admin-text-muted); font-family: ui-monospace, monospace; }
    .acl-link {
      color: #93c5fd; font-weight: 650; text-decoration: none;
      border-bottom: 1px solid transparent;
    }
    .acl-link:hover { color: #bfdbfe; border-bottom-color: rgba(147,197,253,.55); }
    html[data-admin-theme="light"] .acl-link { color: #1d4ed8; }
    html[data-admin-theme="light"] .acl-link:hover { color: #1e40af; }
    .acl-open-btn {
      display: inline-flex; align-items: center; gap: 0.3rem;
      margin-top: 0.35rem; padding: 0.2rem 0.5rem;
      border-radius: 0.45rem; border: 1px solid var(--admin-border);
      font-size: 0.72rem; font-weight: 700; color: var(--admin-text-secondary);
      text-decoration: none; background: rgba(255,255,255,.04);
    }
    .acl-open-btn:hover { border-color: rgba(59,130,246,.45); color: var(--admin-text); background: rgba(59,130,246,.1); }
  </style>
</head>
<body class="admin-app admin-admins-page">
<?php include 'admin_sidebar.php'; ?>
    <?php
      $adminHeroActions = ($view === 'admins')
        ? '<button type="button" class="admin-btn admin-btn--primary" id="aclOpenAddBtn"><i class="bi bi-plus-lg"></i> Add Admin</button>'
        : '';
      include __DIR__ . '/includes/components/admin_page_hero.php';
    ?>

    <?php if ($flashOk !== ''): ?>
      <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl"><?php echo h($flashOk); ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl"><?php echo h($flashErr); ?></div>
    <?php endif; ?>

    <nav class="acl-tabs" aria-label="Admins views">
      <a class="acl-tab <?php echo $view === 'admins' ? 'is-active' : ''; ?>" href="admin_admins?view=admins">Admins</a>
      <a class="acl-tab <?php echo $view === 'log' ? 'is-active' : ''; ?>" href="admin_admins?view=log">Users Log</a>
    </nav>

    <?php if ($view === 'admins'): ?>
      <section class="acl-card acl-staff-shell">
        <div class="acl-card__head">
          <div>
            <h2>Staff Admins</h2>
            <p class="acl-card__sub">Accounts with admin role — edit access areas and credentials</p>
          </div>
          <span class="acl-count"><?php echo count($admins); ?></span>
        </div>
        <div class="acl-card__body">
          <?php if ($admins === []): ?>
            <p class="acl-empty">No admin accounts found. Use <strong>Add Admin</strong> to create one.</p>
          <?php else: ?>
            <div class="acl-table-wrap">
              <table class="acl-staff-table">
                <thead>
                  <tr>
                    <th>Admin</th>
                    <th>Email</th>
                    <th>Access / Areas</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($admins as $a):
                    $initial = strtoupper(substr(trim((string) $a['full_name']), 0, 1));
                    if ($initial === '') {
                        $initial = '?';
                    }
                    $isFull = !empty($a['acl_full']);
                    $keys = $a['acl_keys'] ?? [];
                    $areaLabels = [];
                    if (!$isFull) {
                        foreach ($keys as $k) {
                            $areaLabels[] = $aclKeyLabels[$k] ?? $k;
                        }
                    }
                    $statusRaw = strtolower((string) ($a['status'] ?? 'approved'));
                    $statusLabel = $statusRaw === 'approved' ? 'Active' : ucfirst($statusRaw);
                  ?>
                    <tr>
                      <td>
                        <div class="acl-admin-cell">
                          <div class="acl-avatar" aria-hidden="true"><?php echo h($initial); ?></div>
                          <div class="acl-admin-name"><?php echo h($a['full_name']); ?></div>
                        </div>
                      </td>
                      <td>
                        <div class="acl-admin-email" title="<?php echo h($a['email']); ?>"><?php echo h($a['email']); ?></div>
                      </td>
                      <td>
                        <?php if ($isFull): ?>
                          <span class="acl-pill acl-pill--full">Full access</span>
                          <span class="acl-access-detail">All admin areas</span>
                        <?php else: ?>
                          <span class="acl-pill"><?php echo count($keys); ?> area<?php echo count($keys) === 1 ? '' : 's'; ?></span>
                          <?php if ($areaLabels !== []): ?>
                            <span class="acl-access-detail" title="<?php echo h(implode(', ', $areaLabels)); ?>">
                              <?php echo h(implode(', ', array_slice($areaLabels, 0, 3))); ?><?php echo count($areaLabels) > 3 ? '…' : ''; ?>
                            </span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="acl-pill <?php echo $statusRaw === 'approved' ? 'acl-pill--status' : 'acl-pill--muted'; ?>">
                          <?php echo h($statusLabel); ?>
                        </span>
                      </td>
                      <td>
                        <button
                          type="button"
                          class="admin-btn admin-btn--secondary admin-btn--sm acl-edit-btn"
                          data-acl-edit="<?php echo (int) $a['user_id']; ?>"
                        >
                          <i class="bi bi-pencil-square"></i> Edit
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Add Admin modal -->
      <div class="acl-modal" id="aclAddModal" aria-hidden="true">
        <div class="acl-modal__panel" role="dialog" aria-modal="true" aria-labelledby="aclAddTitle">
          <div class="acl-modal__head">
            <div>
              <h2 id="aclAddTitle">Add Admin</h2>
              <p class="acl-modal__sub">Create a staff login and unlock only the areas they need</p>
            </div>
            <button type="button" class="acl-modal__close" data-acl-close="aclAddModal" aria-label="Close">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
          <form method="POST" action="admin_admins" autocomplete="off" id="aclAddForm">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="create_admin">
            <div class="acl-modal__body">
              <div class="acl-form-stack">
                <div class="acl-field">
                  <label for="full_name">Full Name</label>
                  <input type="text" id="full_name" name="full_name" required maxlength="150" placeholder="e.g. Ana Reyes">
                </div>
                <div class="acl-field">
                  <label for="email">Email</label>
                  <input type="email" id="email" name="email" required maxlength="120" placeholder="staff@example.com">
                </div>
                <div class="acl-field">
                  <label for="password">Temporary Password</label>
                  <div class="acl-pass-wrap">
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Min. 8 characters">
                    <button type="button" class="acl-pass-toggle" data-acl-pass="password" aria-label="Show password" title="Show password">
                      <i class="bi bi-eye-slash" aria-hidden="true"></i>
                    </button>
                  </div>
                  <p class="acl-hint">Share this once — it cannot be retrieved later (stored hashed).</p>
                </div>
                <label class="acl-full-toggle" for="createFullAccess">
                  <input type="checkbox" name="full_access" value="1" id="createFullAccess">
                  <span>
                    <strong>Full access</strong>
                    <span>Grant every admin area (skip picking below)</span>
                  </span>
                </label>
                <div>
                  <p class="acl-section-label">Admin Areas</p>
                  <div id="createKeysWrap" class="acl-keys-panel">
                    <?php foreach ($catalog as $group): ?>
                      <div class="acl-group"><?php echo h($group['group']); ?></div>
                      <div class="acl-key-grid">
                        <?php foreach ($group['keys'] as $item): ?>
                          <label class="acl-check">
                            <input type="checkbox" name="page_keys[]" value="<?php echo h($item['key']); ?>"
                              <?php echo $item['key'] === 'dashboard' ? 'checked' : ''; ?>>
                            <span><?php echo h($item['label']); ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="acl-modal__foot">
              <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-acl-close="aclAddModal">Cancel</button>
              <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">
                <i class="bi bi-person-plus"></i> Create Admin
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Edit Admin modal -->
      <div class="acl-modal" id="aclEditModal" aria-hidden="true">
        <div class="acl-modal__panel" role="dialog" aria-modal="true" aria-labelledby="aclEditTitle">
          <div class="acl-modal__head">
            <div>
              <h2 id="aclEditTitle">Edit Admin</h2>
              <p class="acl-modal__sub">Update credentials and unlocked admin areas</p>
            </div>
            <button type="button" class="acl-modal__close" data-acl-close="aclEditModal" aria-label="Close">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
          <form method="POST" action="admin_admins" autocomplete="off" id="aclEditForm">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="save_admin">
            <input type="hidden" name="user_id" id="edit_user_id" value="">
            <div class="acl-modal__body">
              <div class="acl-form-stack">
                <div class="acl-field">
                  <label for="edit_full_name">Full Name</label>
                  <input type="text" id="edit_full_name" name="full_name" required maxlength="150" value="">
                </div>
                <div class="acl-field">
                  <label for="edit_email">Email</label>
                  <input type="email" id="edit_email" name="email" required maxlength="120" value="">
                </div>
                <div class="acl-field">
                  <label for="edit_password">New Password <span style="font-weight:600;color:var(--admin-text-muted);">(optional)</span></label>
                  <div class="acl-pass-wrap">
                    <input type="password" id="edit_password" name="password" minlength="8" autocomplete="new-password"
                           placeholder="Leave blank to keep current">
                    <button type="button" class="acl-pass-toggle" data-acl-pass="edit_password" aria-label="Show password" title="Show password">
                      <i class="bi bi-eye-slash" aria-hidden="true"></i>
                    </button>
                  </div>
                  <p class="acl-hint">Existing passwords are hashed and cannot be shown. Enter a new one only to change it.</p>
                </div>
                <p class="acl-card__sub" style="margin:0;">Unchecked areas stay visible in the sidebar but locked (not clickable) and blocked by URL.</p>
                <label class="acl-full-toggle" for="editFullAccess">
                  <input type="checkbox" name="full_access" value="1" id="editFullAccess">
                  <span>
                    <strong>Full access</strong>
                    <span>Unlock every admin area for this account</span>
                  </span>
                </label>
                <div>
                  <p class="acl-section-label">Admin Areas</p>
                  <div id="editKeysWrap" class="acl-keys-panel">
                    <?php foreach ($catalog as $group): ?>
                      <div class="acl-group"><?php echo h($group['group']); ?></div>
                      <div class="acl-key-grid">
                        <?php foreach ($group['keys'] as $item): ?>
                          <label class="acl-check">
                            <input type="checkbox" name="page_keys[]" value="<?php echo h($item['key']); ?>" data-acl-key="<?php echo h($item['key']); ?>">
                            <span><?php echo h($item['label']); ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="acl-modal__foot">
              <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-acl-close="aclEditModal">Cancel</button>
              <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Save Changes</button>
            </div>
          </form>
        </div>
      </div>

      <script type="application/json" id="aclAdminsData"><?php echo json_encode($adminsJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <?php else: ?>
      <?php $logActionLabels = users_activity_log_action_labels(); ?>
      <section class="acl-card acl-log-shell">
        <div class="acl-card__head">
          <div>
            <h2>Users activity log</h2>
            <p class="acl-card__sub">Logins, admin account changes, quiz uploads, materials, and other staff actions — click Open to inspect</p>
          </div>
          <span class="acl-count"><?php echo (int) $logTotal; ?></span>
        </div>
        <div class="acl-card__body acl-card__body--pad">
          <form method="GET" class="acl-filters">
            <input type="hidden" name="view" value="log">
            <div class="acl-field acl-field--grow">
              <label for="q">Search</label>
              <input type="search" id="q" name="q" value="<?php echo h($logQ); ?>" placeholder="Email, quiz title, file…">
            </div>
            <div class="acl-field" style="min-width:14rem;">
              <label for="action">Action</label>
              <select id="action" name="action">
                <option value="">All actions</option>
                <?php foreach ($logActionLabels as $actKey => $actLabel): ?>
                  <option value="<?php echo h($actKey); ?>" <?php echo $logAction === $actKey ? 'selected' : ''; ?>>
                    <?php echo h($actLabel); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="acl-field">
              <label for="from">From</label>
              <input type="date" id="from" name="from" value="<?php echo h($logFrom); ?>">
            </div>
            <div class="acl-field">
              <label for="to">To</label>
              <input type="date" id="to" name="to" value="<?php echo h($logTo); ?>">
            </div>
            <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Filter</button>
          </form>

          <?php if (!admin_acl_log_table_ready($conn)): ?>
            <p class="acl-empty">Activity log table is not available yet. Refresh after migration 030 runs.</p>
          <?php elseif ($logRows === []): ?>
            <p class="acl-empty">No log entries match.</p>
          <?php else: ?>
            <div style="overflow-x:auto; width:100%;">
              <table class="acl-log-table">
                <thead>
                  <tr>
                    <th style="width:10rem;">When</th>
                    <th style="min-width:12rem;">What happened</th>
                    <th style="min-width:12rem;">Who</th>
                    <th style="min-width:10rem;">Target</th>
                    <th style="width:7rem;">IP</th>
                    <th style="min-width:12rem;">Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($logRows as $lr):
                    $presented = users_activity_log_present($lr);
                  ?>
                    <tr>
                      <td class="whitespace-nowrap"><?php echo h(date('M j, Y g:i A', strtotime((string) $lr['created_at']))); ?></td>
                      <td>
                        <span class="acl-action-label"><?php echo h($presented['label']); ?></span>
                        <?php if ($presented['summary'] !== ''): ?>
                          <div class="acl-meta"><?php echo h($presented['summary']); ?></div>
                        <?php endif; ?>
                        <span class="acl-action-code"><?php echo h((string) $lr['action']); ?></span>
                        <?php if (!empty($presented['href'])): ?>
                          <a class="acl-open-btn" href="<?php echo h(ereview_url($presented['href'])); ?>">
                            <i class="bi bi-box-arrow-up-right"></i> Open
                          </a>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($presented['actor_href']) && !empty($lr['actor_email'])): ?>
                          <a class="acl-link" href="<?php echo h(ereview_url($presented['actor_href'])); ?>"><?php echo h((string) $lr['actor_email']); ?></a>
                        <?php else: ?>
                          <?php echo h((string) ($lr['actor_email'] ?? '—')); ?>
                        <?php endif; ?>
                        <div class="acl-meta"><?php echo h((string) ($lr['actor_role'] ?? '')); ?></div>
                      </td>
                      <td>
                        <?php if (!empty($presented['target_href']) && $presented['target_label'] !== '—'): ?>
                          <a class="acl-link" href="<?php echo h(ereview_url($presented['target_href'])); ?>"><?php echo h($presented['target_label']); ?></a>
                        <?php else: ?>
                          <?php echo h($presented['target_label']); ?>
                        <?php endif; ?>
                      </td>
                      <td><?php echo h((string) ($lr['ip_address'] ?? '—')); ?></td>
                      <td class="acl-meta"><?php echo h((string) ($lr['meta_json'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if ($logPages > 1): ?>
              <nav class="students-pagination mt-3" aria-label="Log pages">
                <ul>
                  <?php for ($i = max(1, $logPage - 2); $i <= min($logPages, $logPage + 2); $i++): ?>
                    <li>
                      <a href="<?php echo h('admin_admins?' . http_build_query(['view' => 'log', 'q' => $logQ, 'action' => $logAction, 'from' => $logFrom, 'to' => $logTo, 'page' => $i])); ?>"
                         class="<?php echo $i === $logPage ? 'is-active' : ''; ?>"><?php echo $i; ?></a>
                    </li>
                  <?php endfor; ?>
                </ul>
              </nav>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
<?php if ($view === 'admins'): ?>
<script>
(function () {
  var adminsById = {};
  try {
    var raw = document.getElementById('aclAdminsData');
    var list = raw ? JSON.parse(raw.textContent || '[]') : [];
    list.forEach(function (a) { adminsById[String(a.user_id)] = a; });
  } catch (e) {}

  var openAddOnLoad = <?php echo $openAdd ? 'true' : 'false'; ?>;
  var openEditOnLoad = <?php echo $editAdmin ? (int) $editAdmin['user_id'] : 0; ?>;

  function setBodyLock(on) {
    document.body.classList.toggle('acl-modal-open', !!on);
  }

  function anyModalOpen() {
    return !!document.querySelector('.acl-modal.is-open');
  }

  function openModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    document.querySelectorAll('.acl-modal.is-open').forEach(function (m) {
      if (m.id !== id) closeModal(m.id);
    });
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setBodyLock(true);
    var focusEl = modal.querySelector('input, button, select, textarea');
    if (focusEl) {
      setTimeout(function () { focusEl.focus(); }, 40);
    }
  }

  function closeModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (!anyModalOpen()) setBodyLock(false);
    if (id === 'aclEditModal') {
      var pass = document.getElementById('edit_password');
      if (pass) {
        pass.value = '';
        pass.type = 'password';
        syncPassIcon(pass);
      }
    }
  }

  function wireFullAccess(fullId, wrapId) {
    var full = document.getElementById(fullId);
    var wrap = document.getElementById(wrapId);
    if (!full || !wrap) return;
    function sync() {
      var on = !!full.checked;
      wrap.style.opacity = on ? '0.45' : '1';
      wrap.style.pointerEvents = on ? 'none' : 'auto';
    }
    full.addEventListener('change', sync);
    sync();
    return sync;
  }

  var syncCreateKeys = wireFullAccess('createFullAccess', 'createKeysWrap');
  var syncEditKeys = wireFullAccess('editFullAccess', 'editKeysWrap');

  function syncPassIcon(input) {
    var btn = document.querySelector('[data-acl-pass="' + input.id + '"]');
    if (!btn) return;
    var icon = btn.querySelector('i');
    var shown = input.type === 'text';
    if (icon) {
      icon.className = shown ? 'bi bi-eye' : 'bi bi-eye-slash';
    }
    btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
    btn.setAttribute('title', shown ? 'Hide password' : 'Show password');
  }

  document.querySelectorAll('.acl-pass-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-acl-pass');
      var input = id ? document.getElementById(id) : null;
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      syncPassIcon(input);
    });
  });

  function fillEditForm(admin) {
    if (!admin) return;
    document.getElementById('edit_user_id').value = String(admin.user_id);
    document.getElementById('edit_full_name').value = admin.full_name || '';
    document.getElementById('edit_email').value = admin.email || '';
    var pass = document.getElementById('edit_password');
    pass.value = '';
    pass.type = 'password';
    syncPassIcon(pass);

    var full = document.getElementById('editFullAccess');
    full.checked = !!admin.acl_full;
    var keys = admin.acl_keys || [];
    var keySet = {};
    keys.forEach(function (k) { keySet[String(k)] = true; });
    document.querySelectorAll('#editKeysWrap input[data-acl-key]').forEach(function (cb) {
      var k = cb.getAttribute('data-acl-key');
      cb.checked = !admin.acl_full && !!keySet[k];
    });
    if (typeof syncEditKeys === 'function') syncEditKeys();
  }

  var addBtn = document.getElementById('aclOpenAddBtn');
  if (addBtn) {
    addBtn.addEventListener('click', function () { openModal('aclAddModal'); });
  }

  document.querySelectorAll('[data-acl-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = String(btn.getAttribute('data-acl-edit') || '');
      fillEditForm(adminsById[id]);
      openModal('aclEditModal');
    });
  });

  document.querySelectorAll('[data-acl-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      closeModal(btn.getAttribute('data-acl-close'));
    });
  });

  document.querySelectorAll('.acl-modal').forEach(function (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal(modal.id);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.acl-modal.is-open');
    if (open) closeModal(open.id);
  });

  if (openAddOnLoad) openModal('aclAddModal');
  if (openEditOnLoad > 0 && adminsById[String(openEditOnLoad)]) {
    fillEditForm(adminsById[String(openEditOnLoad)]);
    openModal('aclEditModal');
  }
})();
</script>
<?php endif; ?>
</div>
</main>
</body>
</html>
