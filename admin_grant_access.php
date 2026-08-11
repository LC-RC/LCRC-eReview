<?php
/**
 * Admin Grant Access — creates source=admin_manual grant(s) + SCA.
 * Supports Full LMS or by-topic permissions (same picker as Student Access).
 * Does not verify payment or run paid fulfillment.
 * Accepts user_id (single) or user_ids (JSON array / comma list) for bulk.
 */
require_once __DIR__ . '/auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/commerce_admin_manual_grant.php';

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'))
);

function admin_grant_access_respond(bool $ok, string $message, array $extra = []): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
        exit;
    }
    if ($ok) {
        $_SESSION['message'] = $message;
    } else {
        $_SESSION['error'] = $message;
    }
    $returnTo = trim((string) ($_POST['return_to'] ?? 'admin_students'));
    if ($returnTo === '' || str_contains($returnTo, '://') || str_starts_with($returnTo, '//') || str_starts_with($returnTo, '/')) {
        $returnTo = 'admin_students';
    }
    header('Location: ' . ereview_url($returnTo));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_grant_access_respond(false, 'Invalid method.');
}

if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    admin_grant_access_respond(false, 'Invalid request (CSRF).');
}

if (!commerce_schema_ready($conn)) {
    admin_grant_access_respond(false, 'Commerce schema is not installed.');
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$months = (int) ($_POST['months'] ?? 6);
$activateLogin = !isset($_POST['activate_login']) || (string) $_POST['activate_login'] !== '0';
$closeAwaitingWithoutProof = isset($_POST['close_awaiting_without_proof'])
    && in_array((string) $_POST['close_awaiting_without_proof'], ['1', 'true', 'on', 'yes'], true);

$grantFull = isset($_POST['grant_full_lms']) && in_array((string) $_POST['grant_full_lms'], ['1', 'true', 'on', 'yes'], true);
$permissions = [];
if ($grantFull) {
    $permissions = [['content_type' => 'full_lms', 'content_id' => 0]];
} else {
    $rawPerms = $_POST['permissions'] ?? '[]';
    if (is_string($rawPerms)) {
        $decoded = json_decode($rawPerms, true);
        $rawPerms = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($rawPerms)) {
        $rawPerms = [];
    }
    require_once __DIR__ . '/includes/student_content_access.php';
    $permissions = sca_normalize_permission_payload($rawPerms);
}
$pickerSubmitted = array_key_exists('grant_full_lms', $_POST) || array_key_exists('permissions', $_POST);
if ($permissions === []) {
    if ($pickerSubmitted) {
        admin_grant_access_respond(false, 'Select Full LMS or at least one topic/subject.');
    }
    // Older forms without the picker still default to Full LMS.
    $permissions = [['content_type' => 'full_lms', 'content_id' => 0]];
    $grantFull = true;
}
$isFullLms = $grantFull;
foreach ($permissions as $p) {
    if (($p['content_type'] ?? '') === 'full_lms') {
        $isFullLms = true;
        break;
    }
}
$scopeLabel = $isFullLms
    ? 'Full LMS'
    : (count($permissions) . ' selected topic' . (count($permissions) === 1 ? '' : 's'));

$userIds = [];
$rawIds = $_POST['user_ids'] ?? null;
if ($rawIds !== null && $rawIds !== '') {
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) {
            $rawIds = $decoded;
        } else {
            $rawIds = preg_split('/\s*,\s*/', $rawIds) ?: [];
        }
    }
    if (is_array($rawIds)) {
        foreach ($rawIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $userIds[$id] = $id;
            }
        }
    }
}
$singleId = (int) ($_POST['user_id'] ?? 0);
if ($singleId > 0) {
    $userIds[$singleId] = $singleId;
}
$userIds = array_values($userIds);

if ($userIds === []) {
    admin_grant_access_respond(false, 'No students selected.');
}
if (count($userIds) > 50) {
    admin_grant_access_respond(false, 'Select at most 50 students at a time.');
}

$errorMap = [
    'not_student' => 'User is not a student.',
    'rejected_student' => 'Rejected students cannot receive access.',
    'commerce_schema_missing' => 'Commerce schema is not installed.',
    'sca_upsert_failed' => 'Grant saved but content permissions failed.',
    'no_permissions' => 'Select Full LMS or at least one topic/subject.',
];

$granted = [];
$failed = [];
$activated = 0;
$paymentsClosed = 0;
$emailsSent = 0;
$inAppNotified = 0;

foreach ($userIds as $userId) {
    $result = commerce_admin_grant_manual_access($conn, $userId, $adminId, [
        'months' => $months,
        'activate_login' => $activateLogin,
        'label' => 'Administrative Access (' . $scopeLabel . ')',
        'close_open_payment' => true,
        'close_awaiting_without_proof' => $closeAwaitingWithoutProof,
        'notify_student' => true,
        'permissions' => $permissions,
    ]);
    if (empty($result['ok'])) {
        $err = (string) ($result['error'] ?? 'grant_failed');
        $failed[] = [
            'user_id' => $userId,
            'error' => $err,
            'message' => $errorMap[$err] ?? ('Could not grant access (' . $err . ').'),
        ];
        continue;
    }
    $act = $result['activation'] ?? [];
    if (!empty($act['activated'])) {
        $activated++;
    }
    $pc = $result['payment_close'] ?? [];
    $paymentClosed = !empty($pc['ok']) && empty($pc['skipped']);
    if ($paymentClosed) {
        $paymentsClosed++;
    }
    $nv = $result['notify'] ?? [];
    if (!empty($nv['sent'])) {
        $emailsSent++;
    }
    if (!empty($nv['in_app'])) {
        $inAppNotified++;
    }
    $granted[] = [
        'user_id' => $userId,
        'grant_id' => (int) ($result['grant_id'] ?? 0),
        'already_active' => !empty($result['already_active']),
        'activated' => !empty($act['activated']),
        'already_approved' => !empty($act['already_approved']),
        'payment_closed' => $paymentClosed,
        'payment_id' => (int) ($pc['payment_id'] ?? 0),
        'email_sent' => !empty($nv['sent']),
        'in_app_notified' => !empty($nv['in_app']),
    ];
}

$okCount = count($granted);
$failCount = count($failed);

if ($okCount > 0) {
    require_once __DIR__ . '/includes/admin_acl.php';
    $grantedIds = array_map(static function ($g) {
        return (int) ($g['user_id'] ?? 0);
    }, $granted);
    users_activity_log($conn, 'access_granted', [
        'user_ids' => $grantedIds,
        'scope' => $scopeLabel ?? '',
        'granted_count' => $okCount,
        'failed_count' => $failCount,
    ], getCurrentUserId(), null, 'admin', $okCount === 1 ? (int) ($grantedIds[0] ?? 0) : null);
}

if ($okCount === 0) {
    $first = $failed[0]['message'] ?? 'Could not grant access.';
    admin_grant_access_respond(false, $first, [
        'granted' => $granted,
        'failed' => $failed,
        'granted_count' => 0,
        'failed_count' => $failCount,
    ]);
}

if (count($userIds) === 1) {
    $one = $granted[0];
    $note = !empty($one['already_active'])
        ? ('Administrative access updated (' . $scopeLabel . ').')
        : ('Administrative access granted (' . $scopeLabel . ').');
    if (!empty($one['activated'])) {
        $note .= ' Login account activated.';
    } elseif (!empty($one['already_approved'])) {
        $note .= ' Login was already active.';
    }
    if (!empty($one['payment_closed'])) {
        $note .= ' Open payment also marked manually approved.';
    }
    if (!empty($one['email_sent'])) {
        $note .= ' Student emailed.';
    }
    if (!empty($one['in_app_notified'])) {
        $note .= ' In-app notification sent.';
    }
    admin_grant_access_respond(true, $note, [
        'grant_id' => (int) ($one['grant_id'] ?? 0),
        'user_id' => (int) $one['user_id'],
        'granted' => $granted,
        'failed' => $failed,
        'granted_count' => 1,
        'failed_count' => $failCount,
        'payments_closed' => $paymentsClosed,
        'emails_sent' => $emailsSent,
        'in_app_notified' => $inAppNotified,
    ]);
}

$note = $okCount . ' student' . ($okCount === 1 ? '' : 's') . ' granted ' . $scopeLabel . ' access';
if ($activated > 0) {
    $note .= ' (' . $activated . ' login activated)';
}
if ($paymentsClosed > 0) {
    $note .= '; ' . $paymentsClosed . ' payment' . ($paymentsClosed === 1 ? '' : 's') . ' manually approved';
}
if ($emailsSent > 0) {
    $note .= '; ' . $emailsSent . ' email' . ($emailsSent === 1 ? '' : 's') . ' sent';
}
if ($inAppNotified > 0) {
    $note .= '; ' . $inAppNotified . ' in-app notice' . ($inAppNotified === 1 ? '' : 's');
}
$note .= '.';
if ($failCount > 0) {
    $note .= ' ' . $failCount . ' failed.';
}

admin_grant_access_respond(true, $note, [
    'granted' => $granted,
    'failed' => $failed,
    'granted_count' => $okCount,
    'failed_count' => $failCount,
    'payments_closed' => $paymentsClosed,
    'emails_sent' => $emailsSent,
    'in_app_notified' => $inAppNotified,
    'partial' => $failCount > 0,
]);
