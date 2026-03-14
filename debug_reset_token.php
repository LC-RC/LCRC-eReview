<?php
/**
 * Reset token debug – run validation step-by-step and return JSON for console.
 * GET or POST: token=... or url=full_reset_url
 * DELETE THIS FILE when done debugging.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';

$rawInput = trim($_REQUEST['token'] ?? $_REQUEST['url'] ?? '');
if ($rawInput !== '' && strpos($rawInput, 'token=') !== false) {
    $query = parse_url($rawInput, PHP_URL_QUERY);
    if ($query !== null && $query !== false) {
        parse_str($query, $q);
        $rawInput = trim($q['token'] ?? '');
    }
}

$log = [
    'step' => [],
    'result' => null,
    'result_reason' => null,
];

function step(array &$log, $key, $value) {
    $log['step'][$key] = $value;
}

if ($rawInput === '') {
    $log['result'] = 'error';
    $log['result_reason'] = 'No token or url provided. Use ?token=... or ?url=...';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}

step($log, '1_token_received_length', strlen($rawInput));
step($log, '2_token_first_30', substr($rawInput, 0, 30) . '...');

$rawToken = trim($rawInput);
$rawToken = rawurldecode($rawToken);
step($log, '3_after_rawurldecode_length', strlen($rawToken));

if (strpos($rawToken, '.') === false) {
    $log['result'] = 'invalid';
    $log['result_reason'] = 'Token has no dot (selector.validator)';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}
step($log, '4_has_dot', true);

$parts = explode('.', $rawToken, 2);
$selector = trim($parts[0]);
$validatorPart = trim($parts[1] ?? '');
step($log, '5_selector', $selector);
step($log, '5_selector_length', strlen($selector));
step($log, '6_validator_length', strlen($validatorPart));

if ($validatorPart === '') {
    $log['result'] = 'invalid';
    $log['result_reason'] = 'Validator part is empty';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}

$is64Hex = (strlen($validatorPart) === 64 && ctype_xdigit($validatorPart));
step($log, '7_is_64_hex', $is64Hex);
if ($is64Hex) {
    $validatorPart = strtolower($validatorPart);
}

// DB lookup by selector (and expiry for legacy path)
$stmt = @mysqli_prepare($conn, "SELECT id, user_id, token_hash, expires_at FROM password_reset_tokens WHERE selector = ? LIMIT 1");
if (!$stmt) {
    step($log, '8_db_prepare', 'failed');
    step($log, '8_db_error', mysqli_error($conn));
    $log['result'] = 'error';
    $log['result_reason'] = 'DB prepare failed';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}
mysqli_stmt_bind_param($stmt, 's', $selector);
if (!@mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    step($log, '8_db_execute', 'failed');
    $log['result'] = 'error';
    $log['result_reason'] = 'DB execute failed';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}
$result = mysqli_stmt_get_result($stmt);
$row = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

step($log, '9_db_row_found', $row !== null);
if (!$row) {
    $log['result'] = 'invalid';
    $log['result_reason'] = 'No row for this selector (token may never have been created, or already used/deleted)';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}

step($log, '10_row_id', (int)$row['id']);
step($log, '10_row_user_id', (int)$row['user_id']);
step($log, '11_expires_at', $row['expires_at']);
$now = date('Y-m-d H:i:s', time());
step($log, '12_server_now', $now);
$expiresOk = (strtotime($row['expires_at']) > time());
step($log, '13_expires_ok', $expiresOk);
if (!$expiresOk) {
    $log['result'] = 'invalid';
    $log['result_reason'] = 'Token expired at ' . $row['expires_at'];
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}

$storedHash = $row['token_hash'];
step($log, '14_stored_hash_preview', substr($storedHash, 0, 12) . '...');

if ($is64Hex) {
    $validator = @hex2bin($validatorPart);
    step($log, '15_validator_bin_length', $validator !== false ? strlen($validator) : 0);
    if ($validator !== false && strlen($validator) === 32) {
        $computedHash = hash('sha256', $validator);
        $directMatch = hash_equals($computedHash, $storedHash);
        step($log, '16_direct_hash_match', $directMatch);
        if ($directMatch) {
            $log['result'] = 'valid';
            $log['result_reason'] = 'Token is valid (direct match). reset_password.php should show the form.';
            echo json_encode($log, JSON_PRETTY_PRINT);
            exit;
        }
    }
    // Single-char correction
    $hexChars = '0123456789abcdef';
    $corrected = false;
    for ($i = 0; $i < 64 && !$corrected; $i++) {
        $current = $validatorPart[$i];
        for ($k = 0; $k < 16; $k++) {
            $c = $hexChars[$k];
            if ($c === $current) continue;
            $candidate = $validatorPart;
            $candidate[$i] = $c;
            $v = @hex2bin($candidate);
            if ($v !== false && strlen($v) === 32 && hash_equals(hash('sha256', $v), $storedHash)) {
                $corrected = true;
                break;
            }
        }
    }
    step($log, '17_single_char_correction_found', $corrected);
    if ($corrected) {
        $log['result'] = 'valid';
        $log['result_reason'] = 'Token valid after single-char correction (link was corrupted).';
        echo json_encode($log, JSON_PRETTY_PRINT);
        exit;
    }
    $log['result'] = 'invalid';
    $log['result_reason'] = 'Hash does not match (and no single-char correction worked). Token may be wrong or from another request.';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}

// Legacy base64 path
$validatorPartNorm = str_replace(' ', '+', $validatorPart);
$validator = @base64_decode($validatorPartNorm, true);
if ($validator === false || strlen($validator) !== 32) {
    require_once __DIR__ . '/password_reset.php';
    $validator = @passwordResetBase64UrlDecode($validatorPartNorm);
}
step($log, '15_legacy_validator_length', $validator !== false ? strlen($validator) : 0);
if ($validator === false || strlen($validator) !== 32) {
    $log['result'] = 'invalid';
    $log['result_reason'] = 'Validator is not 64 hex and not valid base64 (32 bytes).';
    echo json_encode($log, JSON_PRETTY_PRINT);
    exit;
}
$tokenHash = hash('sha256', $validator);
$legacyMatch = hash_equals($tokenHash, $storedHash);
step($log, '16_legacy_hash_match', $legacyMatch);
if ($legacyMatch) {
    $log['result'] = 'valid';
    $log['result_reason'] = 'Token valid (legacy base64).';
} else {
    $log['result'] = 'invalid';
    $log['result_reason'] = 'Legacy validator hash does not match.';
}
echo json_encode($log, JSON_PRETTY_PRINT);
