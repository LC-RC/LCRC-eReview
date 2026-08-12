<?php
/**
 * Commerce payment / GCash checkout helpers (Phase 5).
 *
 * Scope: create/resume payment + payment_items, checkout session,
 * proof upload, GCash reference lock → pending_verification.
 *
 * Does NOT: OCR, AI, auto/manual verification, fulfillment, access_grants, SCA sync.
 * Free Access must never call these create/submit helpers.
 *
 * ---------------------------------------------------------------------------
 * Checkout authorization model (do not weaken):
 * - payment_ref is NOT an authorization credential. Never authorize checkout
 *   from payment_ref alone (or any client-supplied reference).
 * - Authorization lives in the server-side PHP session:
 *     checkout_user_id + checkout_payment_id + checkout_token + expiry.
 * - On every checkout request: load payment by session payment_id, require
 *   payment.user_id === checkout_user_id, and require open status
 *   (awaiting_proof | pending_verification).
 * - Client-posted payment_id / tokens must not be trusted; forged payment_id
 *   that does not match the session (or belongs to another user) is rejected.
 * - Checkout recovery uses a separate short-lived recovery session and still
 *   re-issues a real checkout session only after ownership + open checks.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';

const COMMERCE_CHECKOUT_SESSION_TTL_SECONDS = 86400; // 24 hours
const COMMERCE_CHECKOUT_RECOVERY_TTL_SECONDS = 86400; // 24 hours
const COMMERCE_CHECKOUT_LINK_TTL_SECONDS = 604800; // 7 days (email remind resume)
const COMMERCE_PROOF_MAX_BYTES = 5242880; // 5 MB
const COMMERCE_PROOF_DIR_REL = 'uploads/payment_proofs';
const COMMERCE_PAYMENT_REF_MAX_ATTEMPTS = 5;

/**
 * Test-mode gate for non-is_uploaded_file proof copies and payment_ref test queues.
 *
 * NEVER enable in production.
 * NEVER activate via GET/POST/cookie/student-controlled input.
 * True only when COMMERCE_PAYMENT_TEST_MODE is explicitly defined/true AND
 * PHP is running as CLI (acceptance tests). Web SAPIs always return false.
 */
function commerce_payment_test_mode_active(): bool
{
    if (PHP_SAPI !== 'cli') {
        return false;
    }
    return defined('COMMERCE_PAYMENT_TEST_MODE') && COMMERCE_PAYMENT_TEST_MODE;
}

function commerce_mysqli_is_duplicate_key_error(int $errno, string $error = ''): bool
{
    if ($errno === 1062) {
        return true;
    }
    return $error !== '' && stripos($error, 'Duplicate entry') !== false;
}

/**
 * True only when a mysqli failure is a UNIQUE collision on payments.payment_ref.
 * Unrelated duplicate-key errors (and all other SQL errors) return false.
 */
function commerce_mysqli_error_is_payment_ref_collision(int $errno, string $error = ''): bool
{
    if (!commerce_mysqli_is_duplicate_key_error($errno, $error)) {
        return false;
    }
    if ($error === '') {
        return false;
    }
    if (stripos($error, 'payment_ref') !== false) {
        return true;
    }
    // Some MySQL builds report only the index name.
    if (preg_match('/for key [\'"`]?[^\'"`]*payment_ref/i', $error)) {
        return true;
    }
    return false;
}

/**
 * @return list<int>
 */
function commerce_normalize_lesson_ids($raw): array
{
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = [];
        }
    }
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function commerce_lesson_id_sets_equal(array $a, array $b): bool
{
    $a = commerce_normalize_lesson_ids($a);
    $b = commerce_normalize_lesson_ids($b);
    return $a === $b;
}

function commerce_normalize_gcash_reference(string $raw): string
{
    $raw = trim($raw);
    $raw = str_replace([' ', '-', "\t", "\n", "\r"], '', $raw);
    return strtoupper($raw);
}

function commerce_next_payment_ref(mysqli $conn): string
{
    // CLI test-only queue - never consulted on web SAPIs.
    if (
        commerce_payment_test_mode_active()
        && isset($GLOBALS['commerce_test_payment_ref_queue'])
        && is_array($GLOBALS['commerce_test_payment_ref_queue'])
        && $GLOBALS['commerce_test_payment_ref_queue'] !== []
    ) {
        $forced = array_shift($GLOBALS['commerce_test_payment_ref_queue']);
        return (string) $forced;
    }

    $year = date('Y');
    $prefix = 'PAY-' . $year . '-';
    $like = $prefix . '%';
    $stmt = mysqli_prepare(
        $conn,
        'SELECT payment_ref FROM payments WHERE payment_ref LIKE ? ORDER BY payment_id DESC LIMIT 1'
    );
    $next = 1;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $like);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $tail = (int) substr((string) $row['payment_ref'], -6);
            $next = $tail + 1;
        }
        mysqli_stmt_close($stmt);
    }
    return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/** @return array<string,mixed>|null */
function commerce_get_payment(mysqli $conn, int $paymentId): ?array
{
    if ($paymentId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM payments WHERE payment_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function commerce_get_payment_items(mysqli $conn, int $paymentId): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM payment_items WHERE payment_id = ? ORDER BY line_no ASC'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function commerce_payment_is_open(array $payment): bool
{
    $status = (string) ($payment['status'] ?? '');
    return in_array($status, ['awaiting_proof', 'pending_verification'], true);
}

/**
 * Find open payment for user matching the same package or lesson-id set.
 *
 * @param list<int>|null $lessonIds
 */
function commerce_find_open_payment_for_selection(
    mysqli $conn,
    int $userId,
    string $purchaseType,
    ?int $packageId,
    ?array $lessonIds
): ?array {
    if ($userId <= 0 || !in_array($purchaseType, ['package', 'by_topic'], true)) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM payments
         WHERE user_id = ?
           AND purchase_type = ?
           AND status IN ('awaiting_proof', 'pending_verification')
         ORDER BY payment_id DESC"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'is', $userId, $purchaseType);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $candidates = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $candidates[] = $row;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);

    foreach ($candidates as $pay) {
        if ($purchaseType === 'package') {
            if ($packageId !== null && (int) ($pay['package_id'] ?? 0) === (int) $packageId) {
                return $pay;
            }
            continue;
        }
        // by_topic: compare lesson ID sets from payment_items
        $items = commerce_get_payment_items($conn, (int) $pay['payment_id']);
        $existing = [];
        foreach ($items as $it) {
            if (($it['item_type'] ?? '') === 'lesson' && !empty($it['lesson_id'])) {
                $existing[] = (int) $it['lesson_id'];
            }
        }
        if (commerce_lesson_id_sets_equal($existing, $lessonIds ?? [])) {
            return $pay;
        }
    }
    return null;
}

/**
 * Create payment + items for a validated package. Caller must not check access/grants.
 * Retries INSERT only on payment_ref unique collision (bounded). Fail closed.
 *
 * @param array<string,mixed> $package from commerce_validate_package_selection
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,resumed?:bool}
 */
function commerce_create_package_payment(mysqli $conn, int $userId, array $package): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    $packageId = (int) ($package['package_id'] ?? 0);
    if ($packageId <= 0) {
        return ['ok' => false, 'error' => 'Invalid package.'];
    }

    $existing = commerce_find_open_payment_for_selection($conn, $userId, 'package', $packageId, null);
    if ($existing) {
        return ['ok' => true, 'payment' => $existing, 'resumed' => true];
    }

    $amount = (int) $package['price_centavos'];
    $currency = (string) ($package['currency'] ?? 'PHP');
    if ($currency === '') {
        $currency = 'PHP';
    }
    $durationValue = (int) $package['duration_value'];
    $durationUnit = (string) $package['duration_unit'];
    $scope = (string) ($package['access_scope'] ?? 'full_lms');
    $name = (string) $package['name'];
    $code = (string) ($package['code'] ?? '');

    $contentRows = commerce_get_package_content($conn, $packageId);
    $contentSnap = [];
    foreach ($contentRows as $c) {
        $contentSnap[] = [
            'content_type' => (string) ($c['content_type'] ?? ''),
            'content_id' => (int) ($c['content_id'] ?? 0),
        ];
    }
    // full_lms: empty content snapshot is correct - do not invent rows.
    $featureRows = commerce_get_package_features($conn, $packageId);
    $featureSnap = [];
    foreach ($featureRows as $f) {
        if (empty($f['is_included'])) {
            continue;
        }
        $featureSnap[] = [
            'feature_key' => (string) ($f['feature_key'] ?? ''),
            'feature_label' => (string) ($f['feature_label'] ?? ''),
            'feature_description' => (string) ($f['feature_description'] ?? ''),
        ];
    }
    $contentJson = json_encode($contentSnap, JSON_UNESCAPED_UNICODE);
    $featureJson = json_encode($featureSnap, JSON_UNESCAPED_UNICODE);
    if ($contentJson === false) {
        $contentJson = '[]';
    }
    if ($featureJson === false) {
        $featureJson = '[]';
    }

    $paymentId = 0;
    $createdOk = false;
    $lastAttempts = 0;
    $genericFail = 'Could not create payment. Please try again.';

    for ($attempt = 1; $attempt <= COMMERCE_PAYMENT_REF_MAX_ATTEMPTS; $attempt++) {
        $paymentRef = commerce_next_payment_ref($conn);
        mysqli_begin_transaction($conn);
        try {
            $ins = mysqli_prepare(
                $conn,
                "INSERT INTO payments
                  (payment_ref, user_id, purchase_type, package_id, expected_amount_centavos, currency,
                   payment_method, status, verification_status)
                 VALUES (?, ?, 'package', ?, ?, ?, 'gcash_qr', 'awaiting_proof', 'not_started')"
            );
            if (!$ins) {
                throw new RuntimeException('Could not prepare payment insert.');
            }
            mysqli_stmt_bind_param($ins, 'siiis', $paymentRef, $userId, $packageId, $amount, $currency);
            if (!mysqli_stmt_execute($ins)) {
                $errno = mysqli_errno($conn);
                $errstr = mysqli_error($conn);
                mysqli_stmt_close($ins);
                if (commerce_mysqli_error_is_payment_ref_collision($errno, $errstr)) {
                    mysqli_rollback($conn);
                    if ($attempt >= COMMERCE_PAYMENT_REF_MAX_ATTEMPTS) {
                        error_log('commerce_create_package_payment: payment_ref collision exhausted after '
                            . COMMERCE_PAYMENT_REF_MAX_ATTEMPTS . ' attempts');
                        return ['ok' => false, 'error' => $genericFail, 'attempts' => $attempt];
                    }
                    continue;
                }
                throw new RuntimeException('Could not create payment: ' . $errstr);
            }
            $paymentId = (int) mysqli_insert_id($conn);
            mysqli_stmt_close($ins);

            $item = mysqli_prepare(
                $conn,
                "INSERT INTO payment_items
                  (payment_id, line_no, item_type, package_id, lesson_id, subject_id, item_code, item_name, subject_name,
                   unit_amount_centavos, quantity, line_total_centavos, duration_value, duration_unit,
                   package_access_scope, package_content_snapshot_json, package_features_snapshot_json)
                 VALUES (?, 1, 'package', ?, NULL, NULL, ?, ?, NULL, ?, 1, ?, ?, ?, ?, ?, ?)"
            );
            if (!$item) {
                throw new RuntimeException('Could not prepare payment item insert.');
            }
            $lineNoScope = $scope;
            mysqli_stmt_bind_param(
                $item,
                'iissiiissss',
                $paymentId,
                $packageId,
                $code,
                $name,
                $amount,
                $amount,
                $durationValue,
                $durationUnit,
                $lineNoScope,
                $contentJson,
                $featureJson
            );
            if (!mysqli_stmt_execute($item)) {
                $err = mysqli_error($conn);
                mysqli_stmt_close($item);
                throw new RuntimeException('Could not create payment item: ' . $err);
            }
            mysqli_stmt_close($item);
            mysqli_commit($conn);
            $createdOk = true;
            $lastAttempts = $attempt;
            break;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errno = 0;
            $errstr = $e->getMessage();
            if ($e instanceof mysqli_sql_exception) {
                $errno = (int) $e->getCode();
            }
            if (commerce_mysqli_error_is_payment_ref_collision($errno, $errstr)) {
                if ($attempt >= COMMERCE_PAYMENT_REF_MAX_ATTEMPTS) {
                    error_log('commerce_create_package_payment: payment_ref collision exhausted after '
                        . COMMERCE_PAYMENT_REF_MAX_ATTEMPTS . ' attempts');
                    return ['ok' => false, 'error' => $genericFail, 'attempts' => $attempt];
                }
                continue;
            }
            error_log('commerce_create_package_payment: ' . $errstr);
            return ['ok' => false, 'error' => $genericFail, 'attempts' => $attempt];
        }
    }

    if (!$createdOk || $paymentId <= 0) {
        return ['ok' => false, 'error' => $genericFail, 'attempts' => COMMERCE_PAYMENT_REF_MAX_ATTEMPTS];
    }

    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'Payment was created but could not be loaded.', 'attempts' => $lastAttempts ?? 1];
    }
    return ['ok' => true, 'payment' => $payment, 'resumed' => false, 'attempts' => $lastAttempts ?? 1];
}

/**
 * @param list<array<string,mixed>> $lessons from commerce_validate_topic_selection
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,resumed?:bool}
 */
function commerce_create_topic_payment(mysqli $conn, int $userId, array $lessons, int $totalCentavos): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    if ($lessons === [] || $totalCentavos <= 0) {
        return ['ok' => false, 'error' => 'Invalid topic selection.'];
    }
    $lessonIds = [];
    foreach ($lessons as $L) {
        $lessonIds[] = (int) ($L['lesson_id'] ?? 0);
    }
    $lessonIds = commerce_normalize_lesson_ids($lessonIds);

    $existing = commerce_find_open_payment_for_selection($conn, $userId, 'by_topic', null, $lessonIds);
    if ($existing) {
        return ['ok' => true, 'payment' => $existing, 'resumed' => true];
    }

    $currency = 'PHP';
    $paymentId = 0;
    $createdOk = false;
    $lastAttempts = 0;
    $genericFail = 'Could not create payment. Please try again.';

    for ($attempt = 1; $attempt <= COMMERCE_PAYMENT_REF_MAX_ATTEMPTS; $attempt++) {
        $paymentRef = commerce_next_payment_ref($conn);
        mysqli_begin_transaction($conn);
        try {
            $ins = mysqli_prepare(
                $conn,
                "INSERT INTO payments
                  (payment_ref, user_id, purchase_type, package_id, expected_amount_centavos, currency,
                   payment_method, status, verification_status)
                 VALUES (?, ?, 'by_topic', NULL, ?, ?, 'gcash_qr', 'awaiting_proof', 'not_started')"
            );
            if (!$ins) {
                throw new RuntimeException('Could not prepare payment insert.');
            }
            mysqli_stmt_bind_param($ins, 'siis', $paymentRef, $userId, $totalCentavos, $currency);
            if (!mysqli_stmt_execute($ins)) {
                $errno = mysqli_errno($conn);
                $errstr = mysqli_error($conn);
                mysqli_stmt_close($ins);
                if (commerce_mysqli_error_is_payment_ref_collision($errno, $errstr)) {
                    mysqli_rollback($conn);
                    if ($attempt >= COMMERCE_PAYMENT_REF_MAX_ATTEMPTS) {
                        error_log('commerce_create_topic_payment: payment_ref collision exhausted after '
                            . COMMERCE_PAYMENT_REF_MAX_ATTEMPTS . ' attempts');
                        return ['ok' => false, 'error' => $genericFail, 'attempts' => $attempt];
                    }
                    continue;
                }
                throw new RuntimeException('Could not create payment: ' . $errstr);
            }
            $paymentId = (int) mysqli_insert_id($conn);
            mysqli_stmt_close($ins);

            $line = 1;
            foreach ($lessons as $L) {
                $lessonId = (int) $L['lesson_id'];
                $subjectId = (int) $L['subject_id'];
                $itemName = (string) $L['title'];
                $subjectName = (string) $L['subject_name'];
                $unit = (int) $L['price_centavos'];
                $durV = (int) $L['duration_value'];
                $durU = (string) $L['duration_unit'];
                $item = mysqli_prepare(
                    $conn,
                    "INSERT INTO payment_items
                      (payment_id, line_no, item_type, package_id, lesson_id, subject_id, item_code, item_name, subject_name,
                       unit_amount_centavos, quantity, line_total_centavos, duration_value, duration_unit,
                       package_access_scope, package_content_snapshot_json, package_features_snapshot_json)
                     VALUES (?, ?, 'lesson', NULL, ?, ?, NULL, ?, ?, ?, 1, ?, ?, ?, NULL, NULL, NULL)"
                );
                if (!$item) {
                    throw new RuntimeException('Could not prepare lesson payment item.');
                }
                mysqli_stmt_bind_param(
                    $item,
                    'iiiissiiis',
                    $paymentId,
                    $line,
                    $lessonId,
                    $subjectId,
                    $itemName,
                    $subjectName,
                    $unit,
                    $unit,
                    $durV,
                    $durU
                );
                if (!mysqli_stmt_execute($item)) {
                    $err = mysqli_error($conn);
                    mysqli_stmt_close($item);
                    throw new RuntimeException('Could not create lesson payment item: ' . $err);
                }
                mysqli_stmt_close($item);
                $line++;
            }
            mysqli_commit($conn);
            $createdOk = true;
            $lastAttempts = $attempt;
            break;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errno = 0;
            $errstr = $e->getMessage();
            if ($e instanceof mysqli_sql_exception) {
                $errno = (int) $e->getCode();
            }
            if (commerce_mysqli_error_is_payment_ref_collision($errno, $errstr)) {
                if ($attempt >= COMMERCE_PAYMENT_REF_MAX_ATTEMPTS) {
                    error_log('commerce_create_topic_payment: payment_ref collision exhausted after '
                        . COMMERCE_PAYMENT_REF_MAX_ATTEMPTS . ' attempts');
                    return ['ok' => false, 'error' => $genericFail, 'attempts' => $attempt];
                }
                continue;
            }
            error_log('commerce_create_topic_payment: ' . $errstr);
            return ['ok' => false, 'error' => $genericFail, 'attempts' => $attempt];
        }
    }

    if (!$createdOk || $paymentId <= 0) {
        return ['ok' => false, 'error' => $genericFail, 'attempts' => COMMERCE_PAYMENT_REF_MAX_ATTEMPTS];
    }

    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'Payment was created but could not be loaded.', 'attempts' => $lastAttempts ?? 1];
    }
    return ['ok' => true, 'payment' => $payment, 'resumed' => false, 'attempts' => $lastAttempts ?? 1];
}

/**
 * Revalidate user enrollment selection and create/resume open checkout payment.
 * Never called for free_access.
 *
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,resumed?:bool}
 */
function commerce_create_or_resume_checkout_for_user(mysqli $conn, int $userId): array
{
    if ($userId <= 0 || !commerce_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'Checkout is not available.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT user_id, enrollment_path, selected_package_id, selected_lesson_ids_json FROM users WHERE user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not load enrollment selection.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $user = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$user) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    $path = (string) ($user['enrollment_path'] ?? '');
    if ($path === 'free_access') {
        return ['ok' => false, 'error' => 'Free Access does not use payment checkout.'];
    }
    if ($path === 'package') {
        $pkgId = (int) ($user['selected_package_id'] ?? 0);
        $check = commerce_validate_package_selection($conn, $pkgId);
        if (empty($check['ok'])) {
            return ['ok' => false, 'error' => $check['error'] ?? 'Selected package is no longer available.'];
        }
        return commerce_create_package_payment($conn, $userId, $check['package']);
    }
    if ($path === 'by_topic') {
        $ids = commerce_normalize_lesson_ids($user['selected_lesson_ids_json'] ?? null);
        $check = commerce_validate_topic_selection($conn, $ids);
        if (empty($check['ok'])) {
            return ['ok' => false, 'error' => $check['error'] ?? 'Selected topics are no longer available.'];
        }
        return commerce_create_topic_payment(
            $conn,
            $userId,
            $check['lessons'],
            (int) $check['total_centavos']
        );
    }

    return ['ok' => false, 'error' => 'No paid enrollment selection found.'];
}

/**
 * Explicit create/resume for a known selection (tests + future repurchase UI).
 * Does NOT consult access_grants or SCA.
 *
 * @param list<int>|null $lessonIds
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,resumed?:bool}
 */
function commerce_create_or_resume_checkout(
    mysqli $conn,
    int $userId,
    string $purchaseType,
    ?int $packageId = null,
    ?array $lessonIds = null
): array {
    if ($purchaseType === 'package') {
        $check = commerce_validate_package_selection($conn, (int) $packageId);
        if (empty($check['ok'])) {
            return ['ok' => false, 'error' => $check['error'] ?? 'Invalid package.'];
        }
        return commerce_create_package_payment($conn, $userId, $check['package']);
    }
    if ($purchaseType === 'by_topic') {
        $check = commerce_validate_topic_selection($conn, $lessonIds ?? []);
        if (empty($check['ok'])) {
            return ['ok' => false, 'error' => $check['error'] ?? 'Invalid topics.'];
        }
        return commerce_create_topic_payment(
            $conn,
            $userId,
            $check['lessons'],
            (int) $check['total_centavos']
        );
    }
    return ['ok' => false, 'error' => 'Unsupported purchase type.'];
}

/**
 * Arm a short-lived checkout recovery session after verification bootstrap failure.
 * Does not grant LMS access. Free Access must never call this.
 */
function commerce_arm_checkout_recovery(int $userId, string $reason): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || $userId <= 0) {
        return;
    }
    $_SESSION['checkout_recovery_user_id'] = $userId;
    $_SESSION['checkout_recovery_token'] = bin2hex(random_bytes(32));
    $_SESSION['checkout_recovery_expires_at'] = time() + COMMERCE_CHECKOUT_RECOVERY_TTL_SECONDS;
    $_SESSION['checkout_recovery_reason'] = $reason;
}

function commerce_clear_checkout_recovery(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset(
        $_SESSION['checkout_recovery_user_id'],
        $_SESSION['checkout_recovery_token'],
        $_SESSION['checkout_recovery_expires_at'],
        $_SESSION['checkout_recovery_reason']
    );
}

/**
 * Validate recovery session. When $postedToken is provided, it must match the
 * server-side recovery token (hash_equals). Never trusts client user_id.
 *
 * @return array{ok:bool,error?:string,user_id?:int,token?:string}
 */
function commerce_validate_checkout_recovery_session(?string $postedToken = null): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return ['ok' => false, 'error' => 'Checkout recovery session expired. Please use your verification link again or contact support.'];
    }
    $uid = (int) ($_SESSION['checkout_recovery_user_id'] ?? 0);
    $token = (string) ($_SESSION['checkout_recovery_token'] ?? '');
    $expires = (int) ($_SESSION['checkout_recovery_expires_at'] ?? 0);
    if ($uid <= 0 || $token === '' || strlen($token) < 32 || $expires < time()) {
        return ['ok' => false, 'error' => 'Checkout recovery session expired. Please use your verification link again or contact support.'];
    }
    if ($postedToken !== null) {
        if ($postedToken === '' || !hash_equals($token, $postedToken)) {
            return ['ok' => false, 'error' => 'Invalid checkout recovery token.'];
        }
    }
    return ['ok' => true, 'user_id' => $uid, 'token' => $token];
}

/**
 * Post-verification: create/resume open payment for the user's enrollment, then
 * issue a checkout session. On failure, arm recovery (fail closed; no orphan claim).
 *
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,token?:string,resumed?:bool,recovery_armed?:bool}
 */
function commerce_bootstrap_checkout_after_verification(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }

    $created = commerce_create_or_resume_checkout_for_user($conn, $userId);
    if (empty($created['ok']) || empty($created['payment']['payment_id'])) {
        $err = (string) ($created['error'] ?? 'Could not create checkout payment.');
        commerce_arm_checkout_recovery($userId, $err);
        return ['ok' => false, 'error' => $err, 'recovery_armed' => true];
    }

    $paymentId = (int) $created['payment']['payment_id'];
    $token = commerce_issue_checkout_session($userId, $paymentId);
    if ($token === '') {
        $err = 'Could not issue checkout session.';
        commerce_arm_checkout_recovery($userId, $err);
        return [
            'ok' => false,
            'error' => $err,
            'payment' => $created['payment'],
            'recovery_armed' => true,
        ];
    }

    return [
        'ok' => true,
        'payment' => $created['payment'],
        'token' => $token,
        'resumed' => !empty($created['resumed']),
    ];
}

/**
 * Resume checkout using a posted recovery token (server session must match).
 * Re-creates/resumes the user's own open payment, then issues a fresh checkout session.
 * Never exposes another user's payment. Free Access is rejected by create_or_resume.
 *
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,token?:string,resumed?:bool,recovery_armed?:bool}
 */
function commerce_resume_checkout_from_recovery(mysqli $conn, string $postedRecoveryToken): array
{
    $validated = commerce_validate_checkout_recovery_session($postedRecoveryToken);
    if (empty($validated['ok'])) {
        return ['ok' => false, 'error' => $validated['error'] ?? 'Recovery failed.'];
    }
    $userId = (int) $validated['user_id'];

    $created = commerce_create_or_resume_checkout_for_user($conn, $userId);
    if (empty($created['ok']) || empty($created['payment']['payment_id'])) {
        $err = (string) ($created['error'] ?? 'Could not resume checkout.');
        commerce_arm_checkout_recovery($userId, $err);
        return ['ok' => false, 'error' => $err, 'recovery_armed' => true];
    }

    $payment = $created['payment'];
    if ((int) ($payment['user_id'] ?? 0) !== $userId || !commerce_payment_is_open($payment)) {
        $err = 'Checkout is not authorized for this payment.';
        commerce_arm_checkout_recovery($userId, $err);
        return ['ok' => false, 'error' => $err, 'recovery_armed' => true];
    }

    $token = commerce_issue_checkout_session($userId, (int) $payment['payment_id']);
    if ($token === '') {
        $err = 'Could not issue checkout session.';
        commerce_arm_checkout_recovery($userId, $err);
        return ['ok' => false, 'error' => $err, 'recovery_armed' => true];
    }

    return [
        'ok' => true,
        'payment' => $payment,
        'token' => $token,
        'resumed' => !empty($created['resumed']),
    ];
}

/**
 * Issue checkout authorization into the active PHP session.
 * Regenerates the session id before setting checkout keys; preserves csrf_token,
 * created, and last_activity; clears any recovery session on success.
 */
function commerce_issue_checkout_session(int $userId, int $paymentId): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Caller should have started session; do not invent session config here.
        return '';
    }
    if ($userId <= 0 || $paymentId <= 0) {
        return '';
    }

    $preserve = [];
    foreach (['csrf_token', 'created', 'last_activity'] as $key) {
        if (array_key_exists($key, $_SESSION)) {
            $preserve[$key] = $_SESSION[$key];
        }
    }

    // Mitigate session fixation before binding checkout authorization.
    // @ suppresses CLI noise when headers were already sent by test output.
    if (!headers_sent()) {
        session_regenerate_id(true);
    } else {
        @session_regenerate_id(true);
    }

    foreach ($preserve as $key => $value) {
        $_SESSION[$key] = $value;
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['checkout_user_id'] = $userId;
    $_SESSION['checkout_payment_id'] = $paymentId;
    $_SESSION['checkout_token'] = $token;
    $_SESSION['checkout_expires_at'] = time() + COMMERCE_CHECKOUT_SESSION_TTL_SECONDS;
    commerce_clear_checkout_recovery();
    return $token;
}

function commerce_clear_checkout_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset(
        $_SESSION['checkout_user_id'],
        $_SESSION['checkout_payment_id'],
        $_SESSION['checkout_token'],
        $_SESSION['checkout_expires_at']
    );
}

/**
 * Validate checkout session against payment. Returns payment row or null.
 * Client must not be trusted - session owns user_id + payment_id + token;
 * payment must belong to that user and still be open.
 *
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>}
 */
function commerce_require_checkout_session(mysqli $conn): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return ['ok' => false, 'error' => 'Checkout session expired. Please use your verification link again or contact support.'];
    }
    $uid = (int) ($_SESSION['checkout_user_id'] ?? 0);
    $pid = (int) ($_SESSION['checkout_payment_id'] ?? 0);
    $token = (string) ($_SESSION['checkout_token'] ?? '');
    $expires = (int) ($_SESSION['checkout_expires_at'] ?? 0);
    if ($uid <= 0 || $pid <= 0 || $token === '' || strlen($token) < 32 || $expires < time()) {
        return ['ok' => false, 'error' => 'Checkout session expired. Please use your verification link again or contact support.'];
    }
    $payment = commerce_get_payment($conn, $pid);
    if (!$payment || (int) $payment['user_id'] !== $uid) {
        return ['ok' => false, 'error' => 'Checkout is not authorized for this payment.'];
    }
    if (!commerce_payment_is_open($payment)) {
        return ['ok' => false, 'error' => 'This payment is no longer open for checkout.'];
    }
    return ['ok' => true, 'payment' => $payment];
}

/**
 * Mark payment as having a duplicate GCash reference attempt (does not accept proof).
 */
function commerce_flag_duplicate_gcash_reference(mysqli $conn, int $paymentId, string $normRef): void
{
    $flags = json_encode([
        'duplicate_gcash_reference' => true,
        'attempted_norm' => $normRef,
        'at' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE payments SET duplicate_reference = 1, suspicious_flags_json = ? WHERE payment_id = ? LIMIT 1'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'si', $flags, $paymentId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * Store proof file for a payment. Returns relative path + mime or error.
 *
 * @param array<string,mixed> $file $_FILES['payment_proof']
 * @return array{ok:bool,error?:string,path?:string,mime?:string}
 */
function commerce_store_payment_proof(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Please upload a payment proof file.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $isUpload = is_uploaded_file($tmp);
    // Test mode: CLI-only via commerce_payment_test_mode_active(). Never via web/GET/POST/cookie.
    if (!$isUpload && !commerce_payment_test_mode_active()) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($size <= 0) {
        $size = (int) filesize($tmp);
    }
    if ($size <= 0 || $size > COMMERCE_PROOF_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Proof file must be 5 MB or smaller.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($extMap[$mime])) {
        return ['ok' => false, 'error' => 'Proof must be JPG, PNG, WEBP, or PDF.'];
    }

    $root = dirname(__DIR__);
    $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, COMMERCE_PROOF_DIR_REL);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => 'Could not prepare upload directory.'];
    }

    $filename = 'proof_' . bin2hex(random_bytes(16)) . '.' . $extMap[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    // Path traversal guard: destination must stay under proof dir
    $realDir = realpath($dir);
    if ($realDir === false) {
        return ['ok' => false, 'error' => 'Upload directory error.'];
    }
    if ($isUpload) {
        if (!@move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'error' => 'Could not save proof file.'];
        }
    } else {
        if (!@copy($tmp, $dest)) {
            return ['ok' => false, 'error' => 'Could not save proof file.'];
        }
    }
    $realFile = realpath($dest);
    if ($realFile === false || strncmp($realFile, $realDir, strlen($realDir)) !== 0) {
        @unlink($dest);
        return ['ok' => false, 'error' => 'Invalid proof storage path.'];
    }

    $rel = COMMERCE_PROOF_DIR_REL . '/' . $filename;
    return ['ok' => true, 'path' => $rel, 'mime' => $mime];
}

/**
 * Submit GCash reference + proof for an open payment.
 *
 * @param array<string,mixed>|null $file $_FILES entry or null when reusing existing proof on idempotent retry
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,idempotent?:bool}
 */
function commerce_submit_payment_proof_and_reference(
    mysqli $conn,
    int $paymentId,
    int $userId,
    string $gcashReferenceRaw,
    ?array $file
): array {
    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment || (int) $payment['user_id'] !== $userId) {
        return ['ok' => false, 'error' => 'Payment not found.'];
    }
    if (!commerce_payment_is_open($payment)) {
        return ['ok' => false, 'error' => 'This payment is no longer open.'];
    }

    $raw = trim($gcashReferenceRaw);
    $norm = commerce_normalize_gcash_reference($raw);
    if ($norm === '' || strlen($norm) < 6 || strlen($norm) > 64) {
        return ['ok' => false, 'error' => 'Please enter a valid GCash reference number.'];
    }
    if (!preg_match('/^[A-Z0-9]+$/', $norm)) {
        return ['ok' => false, 'error' => 'GCash reference may only contain letters and numbers.'];
    }

    // Existing lock for this payment?
    $ownLock = null;
    $lk = mysqli_prepare($conn, 'SELECT * FROM payment_gcash_references WHERE payment_id = ? LIMIT 1');
    if ($lk) {
        mysqli_stmt_bind_param($lk, 'i', $paymentId);
        mysqli_stmt_execute($lk);
        $lr = mysqli_stmt_get_result($lk);
        $ownLock = $lr ? mysqli_fetch_assoc($lr) : null;
        mysqli_stmt_close($lk);
    }

    // Idempotent: already pending_verification with same reference
    if (
        (string) ($payment['status'] ?? '') === 'pending_verification'
        && !empty($payment['proof_path'])
        && commerce_normalize_gcash_reference((string) ($payment['gcash_reference'] ?? '')) === $norm
    ) {
        return ['ok' => true, 'payment' => $payment, 'idempotent' => true];
    }

    if ($ownLock) {
        $ownedNorm = (string) ($ownLock['gcash_reference_norm'] ?? '');
        if ($ownedNorm !== '' && $ownedNorm !== $norm) {
            return ['ok' => false, 'error' => 'This payment already has a different GCash reference locked. Contact support if you need help.'];
        }
        // Same ref already owned by this payment - idempotent update path below
    } else {
        // Another payment owns this normalized reference?
        $chk = mysqli_prepare($conn, 'SELECT payment_id FROM payment_gcash_references WHERE gcash_reference_norm = ? LIMIT 1');
        if ($chk) {
            mysqli_stmt_bind_param($chk, 's', $norm);
            mysqli_stmt_execute($chk);
            $cr = mysqli_stmt_get_result($chk);
            $other = $cr ? mysqli_fetch_assoc($cr) : null;
            mysqli_stmt_close($chk);
            if ($other && (int) $other['payment_id'] !== $paymentId) {
                commerce_flag_duplicate_gcash_reference($conn, $paymentId, $norm);
                return [
                    'ok' => false,
                    'error' => 'This GCash reference was already used for another payment. Please use a different reference or contact support.',
                ];
            }
        }
    }

    $proofPath = (string) ($payment['proof_path'] ?? '');
    $proofMime = (string) ($payment['proof_mime'] ?? '');
    $needNewProof = ($proofPath === '' || ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK));

    if ($needNewProof) {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($proofPath === '') {
                return ['ok' => false, 'error' => 'Please upload a payment proof file.'];
            }
        } else {
            $stored = commerce_store_payment_proof($file);
            if (empty($stored['ok'])) {
                return ['ok' => false, 'error' => $stored['error'] ?? 'Proof upload failed.'];
            }
            // Remove previous proof file if replacing
            if ($proofPath !== '' && str_starts_with($proofPath, COMMERCE_PROOF_DIR_REL . '/')) {
                $oldAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $proofPath);
                if (is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
            $proofPath = (string) $stored['path'];
            $proofMime = (string) $stored['mime'];
        }
    }

    if ($proofPath === '' || !str_starts_with($proofPath, COMMERCE_PROOF_DIR_REL . '/')) {
        return ['ok' => false, 'error' => 'Invalid proof path.'];
    }

    mysqli_begin_transaction($conn);
    try {
        $upd = mysqli_prepare(
            $conn,
            "UPDATE payments SET
                gcash_reference = ?,
                gcash_reference_norm = ?,
                proof_path = ?,
                proof_mime = ?,
                status = 'pending_verification',
                verification_status = 'not_started',
                duplicate_reference = 0
             WHERE payment_id = ? AND user_id = ? AND status IN ('awaiting_proof','pending_verification')
             LIMIT 1"
        );
        if (!$upd) {
            throw new RuntimeException('Could not prepare payment update.');
        }
        mysqli_stmt_bind_param($upd, 'ssssii', $raw, $norm, $proofPath, $proofMime, $paymentId, $userId);
        if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
            // May already be pending with same data
            mysqli_stmt_close($upd);
        } else {
            mysqli_stmt_close($upd);
        }

        if (!$ownLock) {
            $insRef = mysqli_prepare(
                $conn,
                'INSERT INTO payment_gcash_references (gcash_reference_norm, payment_id, user_id) VALUES (?, ?, ?)'
            );
            if (!$insRef) {
                throw new RuntimeException('Could not prepare GCash reference lock.');
            }
            mysqli_stmt_bind_param($insRef, 'sii', $norm, $paymentId, $userId);
            if (!mysqli_stmt_execute($insRef)) {
                $errno = mysqli_errno($conn);
                $errstr = mysqli_error($conn);
                mysqli_stmt_close($insRef);
                // Duplicate key → hard reject
                if (commerce_mysqli_is_duplicate_key_error($errno, $errstr)) {
                    mysqli_rollback($conn);
                    commerce_flag_duplicate_gcash_reference($conn, $paymentId, $norm);
                    return [
                        'ok' => false,
                        'error' => 'This GCash reference was already used for another payment. Please use a different reference or contact support.',
                    ];
                }
                throw new RuntimeException('Could not lock GCash reference.');
            }
            mysqli_stmt_close($insRef);
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('commerce_submit_payment_proof_and_reference: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not submit payment. Please try again.'];
    }

    $fresh = commerce_get_payment($conn, $paymentId);
    return ['ok' => true, 'payment' => $fresh ?: $payment, 'idempotent' => false];
}

/**
 * Ensure payment_checkout_links table exists (migration 029).
 */
function commerce_checkout_links_ensure_schema(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tq = @mysqli_query($conn, "SHOW TABLES LIKE 'payment_checkout_links'");
    if ($tq && mysqli_num_rows($tq) > 0) {
        if ($tq) {
            mysqli_free_result($tq);
        }
        $ready = true;
        return true;
    }
    if ($tq) {
        mysqli_free_result($tq);
    }
    $sql = "CREATE TABLE IF NOT EXISTS payment_checkout_links (
        link_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        payment_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_payment_checkout_links_token (token_hash),
        KEY idx_payment_checkout_links_user (user_id),
        KEY idx_payment_checkout_links_payment (payment_id),
        KEY idx_payment_checkout_links_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $ready = (bool) @mysqli_query($conn, $sql);
    return $ready;
}

/**
 * Latest open payment awaiting proof upload for a student (no proof path).
 *
 * @return array<string,mixed>|null
 */
function commerce_find_awaiting_proof_payment_for_user(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !commerce_schema_ready($conn)) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM payments
         WHERE user_id = ?
           AND status = 'awaiting_proof'
           AND (proof_path IS NULL OR proof_path = '')
         ORDER BY payment_id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Create a durable email resume link for an open payment (7-day TTL).
 * Returns raw token once - only the hash is stored.
 *
 * @return array{ok:bool,error?:string,token?:string,url?:string,expires_at?:string,payment_id?:int,link_id?:int}
 */
function commerce_create_checkout_resume_link(
    mysqli $conn,
    int $userId,
    int $paymentId,
    int $adminId = 0
): array {
    if ($userId <= 0 || $paymentId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }
    if (!commerce_checkout_links_ensure_schema($conn)) {
        return ['ok' => false, 'error' => 'checkout_links_schema_missing'];
    }

    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment || (int) ($payment['user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'error' => 'payment_not_found'];
    }
    if (!commerce_payment_is_open($payment)) {
        return ['ok' => false, 'error' => 'payment_not_open'];
    }

    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $expiresAt = date('Y-m-d H:i:s', time() + COMMERCE_CHECKOUT_LINK_TTL_SECONDS);
    $createdBy = $adminId > 0 ? $adminId : 0;

    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO payment_checkout_links (user_id, payment_id, token_hash, expires_at, created_by)
         VALUES (?, ?, ?, ?, NULLIF(?, 0))'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'link_prepare_failed'];
    }
    mysqli_stmt_bind_param($ins, 'iissi', $userId, $paymentId, $hash, $expiresAt, $createdBy);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);
        return ['ok' => false, 'error' => 'link_insert_failed'];
    }
    $linkId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    $rel = function_exists('ereview_url')
        ? ereview_url('payment_checkout_link?token=' . rawurlencode($raw))
        : ('payment_checkout_link?token=' . rawurlencode($raw));
    $url = $rel;
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $base = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . ($dir === '/' || $dir === '\\' ? '' : $dir), '/');
        $url = $base . '/' . ltrim($rel, '/');
    }

    return [
        'ok' => true,
        'token' => $raw,
        'url' => $url,
        'expires_at' => $expiresAt,
        'payment_id' => $paymentId,
        'link_id' => $linkId,
    ];
}

/**
 * Consume a durable checkout link: validate hash, require open payment, issue checkout session.
 * Refresh-on-use while payment stays open and link unexpired (marks used_at on first hit).
 *
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,token?:string}
 */
function commerce_consume_checkout_resume_link(mysqli $conn, string $rawToken): array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || strlen($rawToken) < 32) {
        return ['ok' => false, 'error' => 'Invalid or expired upload link.'];
    }
    if (!commerce_checkout_links_ensure_schema($conn)) {
        return ['ok' => false, 'error' => 'Upload link is not available.'];
    }

    $hash = hash('sha256', $rawToken);
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM payment_checkout_links
         WHERE token_hash = ?
           AND expires_at > NOW()
         LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not validate upload link.'];
    }
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $link = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$link) {
        return ['ok' => false, 'error' => 'Invalid or expired upload link. Ask admin to send a new reminder.'];
    }

    $userId = (int) ($link['user_id'] ?? 0);
    $paymentId = (int) ($link['payment_id'] ?? 0);
    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment || (int) ($payment['user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'error' => 'Payment for this link was not found.'];
    }
    if (!commerce_payment_is_open($payment)) {
        return ['ok' => false, 'error' => 'This payment is no longer awaiting proof. If you already uploaded, wait for review or contact support.'];
    }

    $sessionToken = commerce_issue_checkout_session($userId, $paymentId);
    if ($sessionToken === '') {
        return ['ok' => false, 'error' => 'Could not start checkout session. Please try again.'];
    }

    $linkId = (int) ($link['link_id'] ?? 0);
    if ($linkId > 0 && empty($link['used_at'])) {
        $upd = mysqli_prepare(
            $conn,
            'UPDATE payment_checkout_links SET used_at = NOW() WHERE link_id = ? AND used_at IS NULL LIMIT 1'
        );
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'i', $linkId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
    }

    return [
        'ok' => true,
        'payment' => $payment,
        'token' => $sessionToken,
    ];
}
