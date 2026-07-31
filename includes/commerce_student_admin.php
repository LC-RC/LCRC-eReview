<?php
/**
 * Read-only Student Admin ↔ Commerce integration helpers.
 * Does not fulfill payments, create grants, or change Phase 8 algorithms.
 */
declare(strict_types=1);

/**
 * Enrollment paths that must not receive paid content via Student Approve SCA picker.
 */
function commerce_admin_is_commerce_enrollment_path(?string $path): bool
{
    return in_array((string) $path, ['package', 'by_topic', 'free_access'], true);
}

function commerce_admin_is_paid_enrollment_path(?string $path): bool
{
    return in_array((string) $path, ['package', 'by_topic'], true);
}

function commerce_admin_label_payment_status(string $status): string
{
    $map = [
        'awaiting_proof' => 'Awaiting proof',
        'pending_verification' => 'Pending verification',
        'paid' => 'Paid',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ];
    return $map[$status] ?? ($status !== '' ? $status : '—');
}

function commerce_admin_label_verification_status(string $status): string
{
    $map = [
        'not_started' => 'Not started',
        'processing' => 'Processing',
        'needs_review' => 'Needs review',
        'failed' => 'Failed',
        'auto_verified' => 'Auto verified',
        'manually_approved' => 'Manually approved',
        'manually_rejected' => 'Manually rejected',
    ];
    return $map[$status] ?? ($status !== '' ? $status : '—');
}

function commerce_admin_label_account_status(string $status): string
{
    $s = strtolower(trim($status));
    if ($s === 'approved') {
        return 'Active';
    }
    if ($s === 'rejected') {
        return 'Rejected';
    }
    if ($s === 'pending') {
        return 'Pending Activation';
    }
    return $status !== '' ? $status : '—';
}

/**
 * Summarize grant rows for one user (same rules as commerce_admin_grant_access_summary).
 *
 * @param list<array<string,mixed>> $rows
 * @return array{label:string,tone:string}
 */
function commerce_admin_grant_access_summary_from_rows(array $rows): array
{
    if ($rows === []) {
        return ['label' => 'No active commerce grant', 'tone' => 'none'];
    }
    $now = time();
    $hasActive = false;
    $hasExpired = false;
    $hasRevoked = false;
    $activeSource = '';
    foreach ($rows as $r) {
        $st = (string) ($r['status'] ?? '');
        if ($st === 'active') {
            $endTs = !empty($r['ends_at']) ? strtotime((string) $r['ends_at']) : false;
            if ($endTs !== false && $endTs < $now) {
                $hasExpired = true;
                continue;
            }
            $hasActive = true;
            $activeSource = (string) ($r['source'] ?? '');
            break;
        }
        if ($st === 'expired') {
            $hasExpired = true;
        }
        if ($st === 'revoked') {
            $hasRevoked = true;
        }
    }
    if ($hasActive) {
        $src = $activeSource === 'free_access' ? 'Free Access' : ($activeSource === 'purchase' ? 'Purchase' : $activeSource);
        return ['label' => 'Active ' . ($src !== '' ? $src . ' grant' : 'commerce grant'), 'tone' => 'active'];
    }
    if ($hasExpired) {
        return ['label' => 'Expired', 'tone' => 'expired'];
    }
    if ($hasRevoked) {
        return ['label' => 'Revoked', 'tone' => 'revoked'];
    }
    return ['label' => 'No active commerce grant', 'tone' => 'none'];
}

/**
 * @return array{label:string,tone:string}
 */
function commerce_admin_grant_access_summary(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['label' => 'No active commerce grant', 'tone' => 'none'];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT status, source, ends_at, starts_at
         FROM access_grants
         WHERE user_id = ?
         ORDER BY
           CASE status WHEN 'active' THEN 0 WHEN 'expired' THEN 1 WHEN 'revoked' THEN 2 ELSE 3 END,
           ends_at DESC
         LIMIT 20"
    );
    if (!$stmt) {
        return ['label' => 'No active commerce grant', 'tone' => 'none'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return commerce_admin_grant_access_summary_from_rows($rows);
}

/**
 * Batch grant summaries for many users (one query). Same labels/tones as single-user helper.
 *
 * @param list<int> $userIds
 * @return array<int, array{label:string,tone:string}>
 */
function commerce_admin_grant_access_summaries_for_users(mysqli $conn, array $userIds): array
{
    $out = [];
    $ids = [];
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid > 0) {
            $ids[$uid] = $uid;
        }
    }
    $ids = array_values($ids);
    foreach ($ids as $uid) {
        $out[$uid] = ['label' => 'No active commerce grant', 'tone' => 'none'];
    }
    if ($ids === []) {
        return $out;
    }
    $in = implode(',', array_map('intval', $ids));
    $q = mysqli_query(
        $conn,
        "SELECT user_id, status, source, ends_at, starts_at
         FROM access_grants
         WHERE user_id IN ($in)
         ORDER BY
           user_id ASC,
           CASE status WHEN 'active' THEN 0 WHEN 'expired' THEN 1 WHEN 'revoked' THEN 2 ELSE 3 END,
           ends_at DESC"
    );
    $byUser = [];
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $uid = (int) ($r['user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [];
        }
        if (count($byUser[$uid]) >= 20) {
            continue;
        }
        $byUser[$uid][] = $r;
    }
    foreach ($byUser as $uid => $rows) {
        $out[(int) $uid] = commerce_admin_grant_access_summary_from_rows($rows);
    }
    return $out;
}

/**
 * Unified dashboard status for Students list (display only; no mutations).
 *
 * @param array<string,mixed> $ctx
 * @return array{
 *   payment_ui:string,payment_tone:string,access_ui:string,access_tone:string,
 *   action_key:string,action_label:string,action_href:string,proof_status:string
 * }
 */
function commerce_admin_dashboard_status(array $ctx): array
{
    $userId = (int) ($ctx['user_id'] ?? 0);
    $path = (string) ($ctx['enrollment_path'] ?? '');
    $account = strtolower((string) ($ctx['account_status'] ?? ''));
    $grantTone = (string) ($ctx['grant_tone'] ?? 'none');
    $farStatus = (string) ($ctx['far_status'] ?? '');
    $farId = (int) ($ctx['far_request_id'] ?? 0);
    $payId = (int) ($ctx['payment_id'] ?? 0);
    $payStatus = (string) ($ctx['payment_status'] ?? '');
    $vStatus = (string) ($ctx['verification_status'] ?? '');
    $fulfilled = !empty($ctx['fulfilled']);
    $hasProof = !empty($ctx['has_proof']);

    $viewStudent = 'admin_student_view?id=' . $userId;
    $viewPayment = $payId > 0 ? ('admin_commerce_payments?id=' . $payId) : $viewStudent;
    $viewFar = $farId > 0 ? ('admin_commerce_free_access?id=' . $farId) : $viewStudent;

    $base = [
        'payment_ui' => '—',
        'payment_tone' => 'neutral',
        'access_ui' => 'None',
        'access_tone' => 'none',
        'action_key' => 'view',
        'action_label' => 'View',
        'action_href' => $viewStudent,
        'proof_status' => 'none',
        'proof_ui' => '—',
        'fulfilled_ui' => '',
    ];

    if ($path === 'free_access') {
        $base['payment_ui'] = 'N/A';
        $base['payment_tone'] = 'neutral';
        $base['proof_status'] = 'na';
        $base['proof_ui'] = 'N/A';
        if ($grantTone === 'active') {
            $base['access_ui'] = 'Granted';
            $base['access_tone'] = 'granted';
            $base['action_key'] = 'view';
            $base['action_label'] = 'View';
            $base['action_href'] = $viewStudent;
            $base['fulfilled_ui'] = '—';
        } elseif ($farStatus === 'pending') {
            $base['access_ui'] = 'Pending';
            $base['access_tone'] = 'pending';
            $base['action_key'] = 'review_far';
            $base['action_label'] = 'Review';
            $base['action_href'] = $viewFar;
        } elseif ($farStatus === 'rejected') {
            $base['access_ui'] = 'None';
            $base['access_tone'] = 'none';
            $base['action_key'] = 'review_far';
            $base['action_label'] = 'Review';
            $base['action_href'] = $viewFar;
        } elseif ($farStatus === 'approved' && $grantTone !== 'active') {
            $base['access_ui'] = $grantTone === 'expired' ? 'Expired' : ($grantTone === 'revoked' ? 'Revoked' : 'Pending');
            $base['access_tone'] = $grantTone === 'expired' ? 'expired' : ($grantTone === 'revoked' ? 'revoked' : 'pending');
            $base['action_key'] = 'view';
            $base['action_label'] = 'View';
            $base['action_href'] = $viewStudent;
        } else {
            $base['access_ui'] = 'Pending';
            $base['access_tone'] = 'pending';
            $base['action_key'] = 'view';
            $base['action_label'] = 'View';
        }
        return $base;
    }

    if (commerce_admin_is_paid_enrollment_path($path)) {
        $base['proof_status'] = $hasProof ? 'uploaded' : 'not_uploaded';
        $base['proof_ui'] = $hasProof ? 'View Proof' : 'Not Uploaded';

        if ($payId <= 0) {
            $base['payment_ui'] = 'Awaiting Payment';
            $base['payment_tone'] = 'awaiting';
            $base['access_ui'] = 'None';
            $base['access_tone'] = 'none';
            $base['action_key'] = 'view';
            $base['action_label'] = 'View';
            $base['action_href'] = $viewStudent;
            return $base;
        }

        if ($payStatus === 'awaiting_proof') {
            $base['payment_ui'] = 'Awaiting Payment';
            $base['payment_tone'] = 'awaiting';
            $base['access_ui'] = 'None';
            $base['access_tone'] = 'none';
            $base['action_key'] = 'view';
            $base['action_label'] = 'View';
            $base['action_href'] = $viewStudent;
            return $base;
        }

        if ($payStatus === 'rejected' || $vStatus === 'manually_rejected') {
            $base['payment_ui'] = 'Rejected';
            $base['payment_tone'] = 'rejected';
            $base['access_ui'] = $grantTone === 'active' ? 'Granted' : 'None';
            $base['access_tone'] = $grantTone === 'active' ? 'granted' : 'none';
            $base['proof_status'] = $hasProof ? 'rejected' : $base['proof_status'];
            $base['action_key'] = 'review_payment';
            $base['action_label'] = 'Review';
            $base['action_href'] = $viewPayment;
            return $base;
        }

        // OCR/engine failure — not an admin rejection; still reviewable for Manual Approve.
        if ($vStatus === 'failed') {
            $base['payment_ui'] = 'OCR Failed';
            $base['payment_tone'] = 'review';
            $base['access_ui'] = $grantTone === 'active' ? 'Granted' : 'Pending';
            $base['access_tone'] = $grantTone === 'active' ? 'granted' : 'pending';
            $base['proof_status'] = $hasProof ? 'needs_review' : $base['proof_status'];
            $base['action_key'] = 'review_payment';
            $base['action_label'] = 'Review';
            $base['action_href'] = $viewPayment;
            return $base;
        }

        if ($payStatus === 'cancelled' || $payStatus === 'expired') {
            $base['payment_ui'] = $payStatus === 'expired' ? 'Expired' : 'Cancelled';
            $base['payment_tone'] = 'rejected';
            $base['access_ui'] = 'None';
            $base['access_tone'] = 'none';
            $base['action_key'] = 'review_payment';
            $base['action_label'] = 'Review';
            $base['action_href'] = $viewPayment;
            return $base;
        }

        // Distinct display: Needs Review vs Pending Verification (logic unchanged).
        if ($vStatus === 'needs_review') {
            $base['payment_ui'] = 'Needs Review';
            $base['payment_tone'] = 'review';
            $base['access_ui'] = $grantTone === 'active' ? 'Granted' : 'Pending';
            $base['access_tone'] = $grantTone === 'active' ? 'granted' : 'pending';
            $base['proof_status'] = $hasProof ? 'needs_review' : $base['proof_status'];
            $base['action_key'] = 'review_payment';
            $base['action_label'] = 'Review';
            $base['action_href'] = $viewPayment;
            return $base;
        }

        if ($payStatus === 'pending_verification' || $vStatus === 'processing' || $vStatus === 'not_started') {
            $base['payment_ui'] = 'Pending Verification';
            $base['payment_tone'] = 'awaiting';
            $base['access_ui'] = $grantTone === 'active' ? 'Granted' : 'Pending';
            $base['access_tone'] = $grantTone === 'active' ? 'granted' : 'pending';
            $base['action_key'] = 'review_payment';
            $base['action_label'] = 'Review';
            $base['action_href'] = $viewPayment;
            return $base;
        }

        $verified = ($payStatus === 'paid' && in_array($vStatus, ['auto_verified', 'manually_approved'], true));
        if ($verified) {
            $base['payment_ui'] = 'Verified';
            $base['payment_tone'] = 'verified';
            $base['proof_status'] = $hasProof ? 'verified' : $base['proof_status'];
            if ($fulfilled || $grantTone === 'active') {
                $base['access_ui'] = 'Granted';
                $base['access_tone'] = 'granted';
                $base['fulfilled_ui'] = $fulfilled ? 'Fulfilled' : '';
            } else {
                $base['access_ui'] = 'Pending';
                $base['access_tone'] = 'pending';
                $base['fulfilled_ui'] = '';
            }
            $base['action_key'] = 'view';
            $base['action_label'] = 'View';
            $base['action_href'] = $viewStudent;
            return $base;
        }

        // Fallback paid-path
        $base['payment_ui'] = commerce_admin_label_payment_status($payStatus);
        $base['payment_tone'] = 'neutral';
        $base['access_ui'] = $grantTone === 'active' ? 'Granted' : ($grantTone === 'expired' ? 'Expired' : 'None');
        $base['access_tone'] = $grantTone === 'active' ? 'granted' : ($grantTone === 'expired' ? 'expired' : 'none');
        $base['action_key'] = $payId > 0 ? 'review_payment' : 'view';
        $base['action_label'] = $payId > 0 ? 'Review' : 'View';
        $base['action_href'] = $payId > 0 ? $viewPayment : $viewStudent;
        return $base;
    }

    // Legacy / unknown enrollment
    if ($grantTone === 'active') {
        $base['access_ui'] = 'Granted';
        $base['access_tone'] = 'granted';
    }
    return $base;
}

/**
 * Dashboard rows for Students list (Enrollment / Payment / Proof / Access / Action).
 *
 * @param list<int> $userIds
 * @return array<int, array<string,mixed>>
 */
function commerce_admin_students_dashboard_rows(mysqli $conn, array $userIds): array
{
    if (!function_exists('commerce_centavos_to_pesos_display')) {
        require_once __DIR__ . '/commerce_catalog.php';
    }
    if (!function_exists('ereview_url')) {
        require_once __DIR__ . '/url_helpers.php';
    }

    $out = [];
    $ids = [];
    foreach ($userIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if ($ids === []) {
        return $out;
    }
    $in = implode(',', array_map('intval', $ids));

    $pathMap = [];
    $uq = mysqli_query(
        $conn,
        "SELECT user_id, enrollment_path, status, selected_package_id, selected_lesson_ids_json
         FROM users WHERE user_id IN ($in)"
    );
    while ($uq && ($r = mysqli_fetch_assoc($uq))) {
        $uid = (int) $r['user_id'];
        $pathMap[$uid] = $r;
    }

    $pkgIds = [];
    foreach ($pathMap as $r) {
        $pid = (int) ($r['selected_package_id'] ?? 0);
        if ($pid > 0) {
            $pkgIds[$pid] = $pid;
        }
    }
    $pkgNames = [];
    $pkgPrices = [];
    if ($pkgIds !== []) {
        $pin = implode(',', array_map('intval', array_values($pkgIds)));
        $pqPkg = mysqli_query($conn, "SELECT package_id, name, price_centavos FROM sellable_packages WHERE package_id IN ($pin)");
        while ($pqPkg && ($pr = mysqli_fetch_assoc($pqPkg))) {
            $pkgNames[(int) $pr['package_id']] = (string) ($pr['name'] ?? '');
            $pkgPrices[(int) $pr['package_id']] = (int) ($pr['price_centavos'] ?? 0);
        }
    }

    $payMap = [];
    $pq = mysqli_query(
        $conn,
        "SELECT p.payment_id, p.user_id, p.status, p.verification_status, p.fulfilled_at, p.created_at,
                p.expected_amount_centavos, p.proof_path, p.purchase_type, p.payment_ref
         FROM payments p
         INNER JOIN (
           SELECT user_id, MAX(payment_id) AS max_id FROM payments WHERE user_id IN ($in) GROUP BY user_id
         ) t ON t.max_id = p.payment_id"
    );
    while ($pq && ($r = mysqli_fetch_assoc($pq))) {
        $payMap[(int) $r['user_id']] = $r;
    }

    $itemNames = [];
    if ($payMap !== []) {
        $payIds = array_map(static function ($p) {
            return (int) $p['payment_id'];
        }, array_values($payMap));
        $payIds = array_values(array_unique(array_filter($payIds)));
        if ($payIds !== []) {
            $pin = implode(',', $payIds);
            $iq = mysqli_query(
                $conn,
                "SELECT payment_id, item_name FROM payment_items
                 WHERE payment_id IN ($pin) AND line_no = 1"
            );
            while ($iq && ($ir = mysqli_fetch_assoc($iq))) {
                $itemNames[(int) $ir['payment_id']] = (string) ($ir['item_name'] ?? '');
            }
        }
    }

    $farMap = [];
    $fq = mysqli_query(
        $conn,
        "SELECT r.request_id, r.user_id, r.status, r.request_ref
         FROM free_access_requests r
         INNER JOIN (
           SELECT user_id, MAX(request_id) AS max_id FROM free_access_requests WHERE user_id IN ($in) GROUP BY user_id
         ) t ON t.max_id = r.request_id"
    );
    while ($fq && ($r = mysqli_fetch_assoc($fq))) {
        $farMap[(int) $r['user_id']] = $r;
    }

    $grantMap = commerce_admin_grant_access_summaries_for_users($conn, $ids);

    foreach ($ids as $uid) {
        $u = $pathMap[$uid] ?? [];
        $path = (string) ($u['enrollment_path'] ?? '');
        $acct = (string) ($u['status'] ?? '');
        $selPkg = (int) ($u['selected_package_id'] ?? 0);
        $grant = $grantMap[$uid] ?? ['label' => 'No active commerce grant', 'tone' => 'none'];
        $pay = $payMap[$uid] ?? null;
        $far = $farMap[$uid] ?? null;

        $enrollmentLabel = '—';
        $amountDisplay = '—';
        if ($path === 'package') {
            $name = $pkgNames[$selPkg] ?? '';
            if ($pay && !empty($itemNames[(int) $pay['payment_id']])) {
                $name = $itemNames[(int) $pay['payment_id']];
            }
            $enrollmentLabel = $name !== '' ? ('Package · ' . $name) : 'Package';
            if ($pay) {
                $amountDisplay = '₱' . commerce_centavos_to_pesos_display((int) ($pay['expected_amount_centavos'] ?? 0));
            } elseif ($selPkg > 0 && isset($pkgPrices[$selPkg])) {
                $amountDisplay = '₱' . commerce_centavos_to_pesos_display($pkgPrices[$selPkg]);
            }
        } elseif ($path === 'by_topic') {
            $enrollmentLabel = 'By Topic';
            if ($pay) {
                $amountDisplay = '₱' . commerce_centavos_to_pesos_display((int) ($pay['expected_amount_centavos'] ?? 0));
                if (!empty($itemNames[(int) $pay['payment_id']])) {
                    $enrollmentLabel = 'By Topic · ' . $itemNames[(int) $pay['payment_id']];
                }
            }
        } elseif ($path === 'free_access') {
            $enrollmentLabel = 'Free Access';
            $amountDisplay = '—';
        } elseif ($path !== '') {
            $enrollmentLabel = $path;
        }

        $payId = $pay ? (int) $pay['payment_id'] : 0;
        $hasProof = $pay && !empty($pay['proof_path']);
        $proofUrl = ($hasProof && $payId > 0)
            ? (ereview_url('payment_proof_file') . '?payment_id=' . $payId)
            : '';

        $mapped = commerce_admin_dashboard_status([
            'user_id' => $uid,
            'enrollment_path' => $path,
            'account_status' => $acct,
            'grant_tone' => $grant['tone'],
            'far_status' => $far ? (string) ($far['status'] ?? '') : '',
            'far_request_id' => $far ? (int) $far['request_id'] : 0,
            'payment_id' => $payId,
            'payment_status' => $pay ? (string) ($pay['status'] ?? '') : '',
            'verification_status' => $pay ? (string) ($pay['verification_status'] ?? '') : '',
            'fulfilled' => $pay && !empty($pay['fulfilled_at']),
            'has_proof' => $hasProof,
        ]);

        $activationRequired = (
            strtolower($acct) === 'pending'
            && ($mapped['access_tone'] ?? '') === 'granted'
        );

        $out[$uid] = [
            'user_id' => $uid,
            'enrollment_path' => $path,
            'enrollment_label' => $enrollmentLabel,
            'enrollment_amount_display' => $amountDisplay,
            'account_status' => $acct,
            'account_label' => commerce_admin_label_account_status($acct),
            'activation_required' => $activationRequired,
            'payment_id' => $payId,
            'payment_ref' => $pay ? (string) ($pay['payment_ref'] ?? '') : '',
            'payment_ui' => $mapped['payment_ui'],
            'payment_tone' => $mapped['payment_tone'],
            'payment_label' => $mapped['payment_ui'] === '—' ? null : $mapped['payment_ui'],
            'verification_label' => $pay ? commerce_admin_label_verification_status((string) ($pay['verification_status'] ?? '')) : null,
            'has_proof' => $hasProof,
            'proof_url' => $proofUrl,
            'proof_status' => $mapped['proof_status'],
            'proof_ui' => (string) ($mapped['proof_ui'] ?? ($hasProof ? 'View Proof' : ($path === 'free_access' ? 'N/A' : 'Not Uploaded'))),
            'fulfilled_ui' => (string) ($mapped['fulfilled_ui'] ?? ''),
            'show_repair_activation' => $activationRequired,
            'access_ui' => $mapped['access_ui'],
            'access_tone' => $mapped['access_tone'],
            'commerce_access_label' => $mapped['access_ui'],
            'commerce_access_tone' => $mapped['access_tone'],
            'action_key' => $mapped['action_key'],
            'action_label' => $mapped['action_label'],
            'action_href' => $mapped['action_href'],
            'far_request_id' => $far ? (int) $far['request_id'] : 0,
            'far_label' => $far ? ('FAR: ' . ucfirst((string) $far['status'])) : null,
            'is_commerce' => commerce_admin_is_commerce_enrollment_path($path),
            'is_paid_path' => commerce_admin_is_paid_enrollment_path($path),
            'is_free_access' => $path === 'free_access',
        ];
    }
    return $out;
}

/**
 * @param list<int> $userIds
 * @return array<int, array<string,mixed>>
 */
function commerce_admin_students_list_badges(mysqli $conn, array $userIds): array
{
    return commerce_admin_students_dashboard_rows($conn, $userIds);
}

/**
 * Full enrollment & commerce summary for student detail (safe fields only).
 *
 * @return array<string,mixed>
 */
function commerce_admin_student_detail_summary(mysqli $conn, array $user): array
{
    $userId = (int) ($user['user_id'] ?? 0);
    $path = (string) ($user['enrollment_path'] ?? '');
    $packageId = isset($user['selected_package_id']) ? (int) $user['selected_package_id'] : 0;
    $lessonJson = (string) ($user['selected_lesson_ids_json'] ?? '');

    $packageName = '';
    if ($packageId > 0) {
        $ps = mysqli_prepare($conn, 'SELECT name, code FROM sellable_packages WHERE package_id = ? LIMIT 1');
        if ($ps) {
            mysqli_stmt_bind_param($ps, 'i', $packageId);
            mysqli_stmt_execute($ps);
            $pr = mysqli_stmt_get_result($ps);
            $prow = $pr ? mysqli_fetch_assoc($pr) : null;
            mysqli_stmt_close($ps);
            if ($prow) {
                $packageName = (string) ($prow['name'] ?? '');
            }
        }
    }

    $lessonLabels = [];
    $lessonIds = [];
    if ($lessonJson !== '') {
        $decoded = json_decode($lessonJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $lid) {
                $lid = (int) $lid;
                if ($lid > 0) {
                    $lessonIds[$lid] = $lid;
                }
            }
        }
    }
    if ($lessonIds !== []) {
        $in = implode(',', array_map('intval', array_values($lessonIds)));
        $lq = mysqli_query($conn, "SELECT lesson_id, title FROM lessons WHERE lesson_id IN ($in) ORDER BY lesson_id");
        while ($lq && ($lr = mysqli_fetch_assoc($lq))) {
            $lessonLabels[] = (string) ($lr['title'] ?? ('Lesson #' . $lr['lesson_id']));
        }
    }

    $payments = [];
    $pst = mysqli_prepare(
        $conn,
        'SELECT payment_id, payment_ref, purchase_type, expected_amount_centavos, status, verification_status,
                gcash_reference, proof_path, fulfilled_at, paid_at, created_at
         FROM payments WHERE user_id = ? ORDER BY payment_id DESC LIMIT 20'
    );
    if ($pst) {
        mysqli_stmt_bind_param($pst, 'i', $userId);
        mysqli_stmt_execute($pst);
        $pres = mysqli_stmt_get_result($pst);
        while ($pres && ($pr = mysqli_fetch_assoc($pres))) {
            $pid = (int) $pr['payment_id'];
            $itemName = '';
            $is = mysqli_prepare(
                $conn,
                'SELECT item_name FROM payment_items WHERE payment_id = ? ORDER BY line_no ASC LIMIT 1'
            );
            if ($is) {
                mysqli_stmt_bind_param($is, 'i', $pid);
                mysqli_stmt_execute($is);
                $ir = mysqli_stmt_get_result($is);
                $irow = $ir ? mysqli_fetch_assoc($ir) : null;
                mysqli_stmt_close($is);
                $itemName = (string) ($irow['item_name'] ?? '');
            }
            $payments[] = [
                'payment_id' => $pid,
                'payment_ref' => (string) ($pr['payment_ref'] ?? ''),
                'purchase_type' => (string) ($pr['purchase_type'] ?? ''),
                'amount_centavos' => (int) ($pr['expected_amount_centavos'] ?? 0),
                'status' => (string) ($pr['status'] ?? ''),
                'verification_status' => (string) ($pr['verification_status'] ?? ''),
                'gcash_reference' => (string) ($pr['gcash_reference'] ?? ''),
                'has_proof' => !empty($pr['proof_path']),
                'fulfilled' => !empty($pr['fulfilled_at']),
                'fulfilled_at' => $pr['fulfilled_at'] ?? null,
                'paid_at' => $pr['paid_at'] ?? null,
                'created_at' => (string) ($pr['created_at'] ?? ''),
                'primary_item_name' => $itemName,
            ];
        }
        mysqli_stmt_close($pst);
    }

    $latestPayment = $payments[0] ?? null;

    $far = null;
    $fst = mysqli_prepare(
        $conn,
        'SELECT request_id, request_ref, status, created_at, reviewed_at
         FROM free_access_requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1'
    );
    if ($fst) {
        mysqli_stmt_bind_param($fst, 'i', $userId);
        mysqli_stmt_execute($fst);
        $fres = mysqli_stmt_get_result($fst);
        $frow = $fres ? mysqli_fetch_assoc($fres) : null;
        mysqli_stmt_close($fst);
        if ($frow) {
            $far = [
                'request_id' => (int) $frow['request_id'],
                'request_ref' => (string) ($frow['request_ref'] ?? ''),
                'status' => (string) ($frow['status'] ?? ''),
                'created_at' => (string) ($frow['created_at'] ?? ''),
                'reviewed_at' => $frow['reviewed_at'] ?? null,
            ];
        }
    }

    $grantRows = [];
    $activePurchase = null;
    $activeFarGrant = null;
    $gst = mysqli_prepare(
        $conn,
        'SELECT grant_id, source, status, starts_at, ends_at, content_type, content_label, payment_id, free_access_request_id
         FROM access_grants WHERE user_id = ?
         ORDER BY CASE status WHEN \'active\' THEN 0 ELSE 1 END, ends_at DESC
         LIMIT 10'
    );
    if ($gst) {
        mysqli_stmt_bind_param($gst, 'i', $userId);
        mysqli_stmt_execute($gst);
        $gres = mysqli_stmt_get_result($gst);
        $now = time();
        while ($gres && ($g = mysqli_fetch_assoc($gres))) {
            $grantRows[] = [
                'grant_id' => (int) $g['grant_id'],
                'source' => (string) ($g['source'] ?? ''),
                'status' => (string) ($g['status'] ?? ''),
                'starts_at' => (string) ($g['starts_at'] ?? ''),
                'ends_at' => (string) ($g['ends_at'] ?? ''),
                'content_label' => (string) ($g['content_label'] ?? ''),
                'payment_id' => $g['payment_id'] !== null ? (int) $g['payment_id'] : null,
                'free_access_request_id' => $g['free_access_request_id'] !== null ? (int) $g['free_access_request_id'] : null,
            ];
            $st = (string) ($g['status'] ?? '');
            $endOk = empty($g['ends_at']) || (strtotime((string) $g['ends_at']) !== false && strtotime((string) $g['ends_at']) >= $now);
            if ($st === 'active' && $endOk) {
                if (($g['source'] ?? '') === 'purchase' && $activePurchase === null) {
                    $activePurchase = end($grantRows);
                }
                if (($g['source'] ?? '') === 'free_access' && $activeFarGrant === null) {
                    $activeFarGrant = end($grantRows);
                }
            }
        }
        mysqli_stmt_close($gst);
    }

    $grantSummary = commerce_admin_grant_access_summary($conn, $userId);

    $enrollmentLabel = '—';
    if ($path === 'package') {
        $enrollmentLabel = 'Package';
    } elseif ($path === 'by_topic') {
        $enrollmentLabel = 'By Topic';
    } elseif ($path === 'free_access') {
        $enrollmentLabel = 'Free Access';
    } elseif ($path !== '') {
        $enrollmentLabel = $path;
    }

    return [
        'user_id' => $userId,
        'enrollment_path' => $path,
        'enrollment_label' => $enrollmentLabel,
        'is_commerce' => commerce_admin_is_commerce_enrollment_path($path),
        'is_paid_path' => commerce_admin_is_paid_enrollment_path($path),
        'is_free_access' => $path === 'free_access',
        'package_id' => $packageId,
        'package_name' => $packageName,
        'lesson_labels' => $lessonLabels,
        'account_status' => (string) ($user['status'] ?? ''),
        'account_label' => commerce_admin_label_account_status((string) ($user['status'] ?? '')),
        'access_start' => $user['access_start'] ?? null,
        'access_end' => $user['access_end'] ?? null,
        'access_months' => $user['access_months'] ?? null,
        'payments' => $payments,
        'latest_payment' => $latestPayment,
        'far' => $far,
        'grants' => $grantRows,
        'active_purchase_grant' => $activePurchase,
        'active_far_grant' => $activeFarGrant,
        'commerce_access' => $grantSummary,
    ];
}
