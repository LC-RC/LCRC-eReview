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
            header('Location: admin_admins?view=admins');
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Enter a valid email address.';
            header('Location: admin_admins?view=admins');
            exit;
        }
        if (strlen($password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters.';
            header('Location: admin_admins?view=admins');
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
            header('Location: admin_admins?view=admins');
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
            header('Location: admin_admins?view=admins');
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
        header('Location: admin_admins?view=admins&edit=' . $targetId);
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
    body.admin-app.admin-admins-page #main .admin-content {
      max-width: none !important;
      width: 100% !important;
      margin-left: 0 !important;
      margin-right: 0 !important;
      padding-left: 1rem !important;
      padding-right: 1rem !important;
      box-sizing: border-box;
    }
    body.admin-app.admin-admins-page .quiz-admin-hero,
    body.admin-app.admin-admins-page .page-hero,
    body.admin-app.admin-admins-page .acl-tabs,
    body.admin-app.admin-admins-page .acl-grid,
    body.admin-app.admin-admins-page .acl-log-shell {
      width: 100%;
      max-width: none;
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

    .acl-grid {
      display: grid;
      grid-template-columns: minmax(18rem, 30%) minmax(0, 1fr);
      gap: 1.1rem;
      align-items: start;
      width: 100%;
    }
    @media (max-width: 1100px) { .acl-grid { grid-template-columns: 1fr; } }

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
    .acl-card__body { padding: 1rem 1.1rem 1.15rem; }
    .acl-count {
      font-size: 0.72rem; font-weight: 800; color: var(--admin-text-muted);
      padding: 0.2rem 0.55rem; border-radius: 999px; border: 1px solid var(--admin-border);
      background: rgba(255,255,255,.03);
    }

    .acl-admin-list { display: flex; flex-direction: column; gap: 0.55rem; }
    .acl-admin-row {
      display: flex; align-items: center; gap: 0.7rem;
      padding: 0.7rem 0.75rem;
      border: 1px solid var(--admin-border);
      border-radius: 0.8rem;
      background: rgba(255,255,255,.03);
      transition: border-color .15s ease, background .15s ease;
    }
    .acl-admin-row:hover {
      border-color: rgba(59,130,246,.35);
      background: rgba(59,130,246,.06);
    }
    .acl-admin-row.is-editing {
      border-color: rgba(59,130,246,.55);
      background: rgba(59,130,246,.1);
      box-shadow: inset 0 0 0 1px rgba(147,197,253,.18);
    }
    .acl-avatar {
      width: 2.35rem; height: 2.35rem; border-radius: 0.7rem; flex-shrink: 0;
      display: grid; place-items: center;
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      color: #fff; font-weight: 800; font-size: 0.85rem;
    }
    .acl-admin-meta { flex: 1; min-width: 0; }
    .acl-admin-name {
      font-size: 0.9rem; font-weight: 700; color: var(--admin-text);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .acl-admin-email {
      font-size: 0.76rem; color: var(--admin-text-muted);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .acl-admin-actions {
      display: flex; flex-direction: column; align-items: flex-end; gap: 0.35rem; flex-shrink: 0;
    }
    .acl-pill {
      display: inline-flex; align-items: center; gap: 0.25rem;
      padding: 0.16rem 0.5rem; border-radius: 999px;
      font-size: 0.68rem; font-weight: 750; line-height: 1.2;
      border: 1px solid var(--admin-border); color: var(--admin-text-secondary);
      background: rgba(255,255,255,.04);
    }
    .acl-pill--full {
      color: #86efac; border-color: rgba(34,197,94,.4);
      background: rgba(22,163,74,.16);
    }
    .acl-empty {
      padding: 1.5rem 0.75rem; text-align: center;
      font-size: 0.84rem; color: var(--admin-text-muted);
    }

    .acl-form-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem 0.85rem;
    }
    @media (max-width: 720px) { .acl-form-grid { grid-template-columns: 1fr; } }
    .acl-form-grid .acl-field--full { grid-column: 1 / -1; }

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

    .acl-full-toggle {
      display: flex; align-items: flex-start; gap: 0.65rem;
      margin: 1rem 0 0.85rem; padding: 0.75rem 0.85rem;
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
      max-height: min(32rem, 58vh);
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
      display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.2rem 0.55rem;
    }
    @media (max-width: 1280px) { .acl-key-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .acl-key-grid { grid-template-columns: 1fr; } }
    .acl-check {
      display: flex; align-items: center; gap: 0.45rem;
      padding: 0.38rem 0.35rem; border-radius: 0.45rem;
      font-size: 0.82rem; color: var(--admin-text); cursor: pointer;
    }
    .acl-check:hover { background: rgba(59,130,246,.08); }
    .acl-check input { width: 1rem; height: 1rem; accent-color: #2563eb; flex-shrink: 0; }

    .acl-actions {
      display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;
      margin-top: 1rem; padding-top: 0.9rem;
      border-top: 1px solid var(--admin-border);
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
<main id="main" class="admin-main-shell">
  <?php include __DIR__ . '/includes/admin_topbar.php'; ?>
  <div class="admin-content p-4 md:p-6">
    <?php
      $adminHeroActions = '';
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
      <div class="acl-grid">
        <section class="acl-card">
          <div class="acl-card__head">
            <div>
              <h2>Staff admins</h2>
              <p class="acl-card__sub">Accounts with admin role</p>
            </div>
            <span class="acl-count"><?php echo count($admins); ?></span>
          </div>
          <div class="acl-card__body">
            <?php if ($admins === []): ?>
              <p class="acl-empty">No admin accounts found.</p>
            <?php else: ?>
              <div class="acl-admin-list">
                <?php foreach ($admins as $a):
                  $isEditing = $editAdmin && (int) $editAdmin['user_id'] === (int) $a['user_id'];
                  $initial = strtoupper(substr(trim((string) $a['full_name']), 0, 1));
                  if ($initial === '') {
                      $initial = '?';
                  }
                ?>
                  <div class="acl-admin-row<?php echo $isEditing ? ' is-editing' : ''; ?>">
                    <div class="acl-avatar" aria-hidden="true"><?php echo h($initial); ?></div>
                    <div class="acl-admin-meta">
                      <div class="acl-admin-name"><?php echo h($a['full_name']); ?></div>
                      <div class="acl-admin-email"><?php echo h($a['email']); ?></div>
                    </div>
                    <div class="acl-admin-actions">
                      <?php if (!empty($a['acl_full'])): ?>
                        <span class="acl-pill acl-pill--full">Full access</span>
                      <?php else: ?>
                        <span class="acl-pill"><?php echo count($a['acl_keys']); ?> area(s)</span>
                      <?php endif; ?>
                      <a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_admins?view=admins&edit=<?php echo (int) $a['user_id']; ?>">
                        <?php echo $isEditing ? 'Editing…' : 'Edit'; ?>
                      </a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="acl-card">
          <?php if ($editAdmin): ?>
            <div class="acl-card__head">
              <div>
                <h2>Edit admin</h2>
                <p class="acl-card__sub">Update credentials and unlocked admin areas</p>
              </div>
            </div>
            <div class="acl-card__body">
              <form method="POST" action="admin_admins" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="save_admin">
                <input type="hidden" name="user_id" value="<?php echo (int) $editAdmin['user_id']; ?>">

                <p class="acl-section-label">Account</p>
                <div class="acl-form-grid">
                  <div class="acl-field acl-field--full">
                    <label for="edit_full_name">Full name</label>
                    <input type="text" id="edit_full_name" name="full_name" required maxlength="150"
                           value="<?php echo h($editAdmin['full_name']); ?>">
                  </div>
                  <div class="acl-field">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" required maxlength="120"
                           value="<?php echo h($editAdmin['email']); ?>">
                  </div>
                  <div class="acl-field">
                    <label for="edit_password">New password</label>
                    <input type="password" id="edit_password" name="password" minlength="8" autocomplete="new-password"
                           placeholder="Leave blank to keep current">
                    <p class="acl-hint">Optional. Min. 8 characters if changing.</p>
                  </div>
                </div>

                <p class="acl-section-label" style="margin-top:1rem;">Access</p>
                <p class="acl-card__sub" style="margin-bottom:0.75rem;">Unchecked areas stay visible in the sidebar but locked (not clickable) and blocked by URL.</p>
                <label class="acl-full-toggle" for="editFullAccess">
                  <input type="checkbox" name="full_access" value="1" id="editFullAccess" <?php echo $editFull ? 'checked' : ''; ?>>
                  <span>
                    <strong>Full access</strong>
                    <span>Unlock every admin area for this account</span>
                  </span>
                </label>
                <div id="editKeysWrap" class="acl-keys-panel" <?php echo $editFull ? 'style="opacity:.45;pointer-events:none;"' : ''; ?>>
                  <?php foreach ($catalog as $group): ?>
                    <div class="acl-group"><?php echo h($group['group']); ?></div>
                    <div class="acl-key-grid">
                      <?php foreach ($group['keys'] as $item): ?>
                        <label class="acl-check">
                          <input type="checkbox" name="page_keys[]" value="<?php echo h($item['key']); ?>"
                            <?php echo (!$editFull && in_array($item['key'], $editKeys, true)) ? 'checked' : ''; ?>>
                          <span><?php echo h($item['label']); ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="acl-actions">
                  <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Save changes</button>
                  <a class="admin-btn admin-btn--ghost admin-btn--sm" href="admin_admins?view=admins">Cancel</a>
                </div>
              </form>
            </div>
          <?php else: ?>
            <div class="acl-card__head">
              <div>
                <h2>Add admin</h2>
                <p class="acl-card__sub">Create a staff login and unlock only the areas they need</p>
              </div>
            </div>
            <div class="acl-card__body">
              <form method="POST" action="admin_admins" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="create_admin">
                <div class="acl-form-grid">
                  <div class="acl-field acl-field--full">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" required maxlength="150" placeholder="e.g. Ana Reyes">
                  </div>
                  <div class="acl-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required maxlength="120" placeholder="staff@example.com">
                  </div>
                  <div class="acl-field">
                    <label for="password">Temporary password</label>
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Min. 8 characters">
                  </div>
                </div>
                <label class="acl-full-toggle" for="createFullAccess">
                  <input type="checkbox" name="full_access" value="1" id="createFullAccess">
                  <span>
                    <strong>Full access</strong>
                    <span>Grant every admin area (skip picking below)</span>
                  </span>
                </label>
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
                <div class="acl-actions">
                  <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-person-plus"></i> Create admin</button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </section>
      </div>
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
        <div class="acl-card__body">
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
  </div>
</main>
<script>
(function () {
  function wire(fullId, wrapId) {
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
  }
  wire('createFullAccess', 'createKeysWrap');
  wire('editFullAccess', 'editKeysWrap');
})();
</script>
</body>
</html>
