<?php
/**
 * Negative safety tests (no live HTTP exam traffic, no production writes, no k6 execution).
 * Covers the PART 9 checklist (1–16).
 */
declare(strict_types=1);

require_once __DIR__ . '/00_env_guard.php';

$failed = 0;
function neg_pass(string $msg): void
{
    echo "PASS  {$msg}\n";
}
function neg_fail(string $msg): void
{
    global $failed;
    echo "FAIL  {$msg}\n";
    $failed++;
}

function neg_run_child(string $body, string $label): void
{
    $php = PHP_BINARY;
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ereview_neg_' . bin2hex(random_bytes(4)) . '.php';
    $guard = var_export(__DIR__ . DIRECTORY_SEPARATOR . '00_env_guard.php', true);
    $code = "<?php\ndeclare(strict_types=1);\nrequire {$guard};\n{$body}\necho \"UNEXPECTED_OK\\n\";\n";
    file_put_contents($tmp, $code);
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($tmp);
    $out = [];
    $exit = 0;
    exec($cmd . ' 2>&1', $out, $exit);
    @unlink($tmp);
    $joined = implode("\n", $out);
    if ($exit !== 0 && !str_contains($joined, 'UNEXPECTED_OK')) {
        neg_pass($label . " (exit={$exit})");
    } else {
        neg_fail($label . " (expected fail; exit={$exit}; out={$joined})");
    }
}

function neg_safe_doc(array $overrides = []): array
{
    return array_merge([
        'status' => 'SAFE',
        'database' => 'ereview_loadtest',
        'base_url' => 'http://127.0.0.1/EreviewLoadtest',
        'verified_at' => date('c'),
        'verification_method' => 'runtime_db_probe+SELECT_DATABASE+session_save_path',
        'run_id' => 'run_neg',
    ], $overrides);
}

// 1) missing EREVIEW_LOADTEST
neg_run_child(
    'putenv("EREVIEW_LOADTEST="); $_ENV["EREVIEW_LOADTEST"]=""; putenv("EREVIEW_LOADTEST_CONFIRM=YES"); putenv("LOADTEST_DB_NAME=ereview_loadtest"); loadtest_require_safe_environment();',
    '1) missing EREVIEW_LOADTEST'
);

// 2) missing EREVIEW_LOADTEST_CONFIRM
neg_run_child(
    'putenv("EREVIEW_LOADTEST=1"); putenv("EREVIEW_LOADTEST_CONFIRM="); $_ENV["EREVIEW_LOADTEST_CONFIRM"]=""; putenv("LOADTEST_DB_NAME=ereview_loadtest"); loadtest_require_safe_environment();',
    '2) missing EREVIEW_LOADTEST_CONFIRM'
);

// 3) production DB name
neg_run_child(
    'putenv("EREVIEW_LOADTEST=1"); putenv("EREVIEW_LOADTEST_CONFIRM=YES"); putenv("LOADTEST_DB_NAME=ereview"); loadtest_require_safe_environment();',
    '3) production DB name rejected'
);

// 4) unknown DB name
neg_run_child(
    'putenv("EREVIEW_LOADTEST=1"); putenv("EREVIEW_LOADTEST_CONFIRM=YES"); putenv("LOADTEST_DB_NAME=something_else"); loadtest_require_safe_environment();',
    '4) unknown DB name rejected'
);

// 5) missing LOADTEST_BASE_URL
neg_run_child(
    'putenv("LOADTEST_BASE_URL"); $_ENV["LOADTEST_BASE_URL"]=""; loadtest_require_base_url();',
    '5) missing LOADTEST_BASE_URL'
);

// 6) malformed BASE_URL
neg_run_child(
    'putenv("LOADTEST_BASE_URL=not-a-url"); $_ENV["LOADTEST_BASE_URL"]="not-a-url"; loadtest_require_base_url();',
    '6) malformed BASE_URL'
);

// 7) missing SAFE attestation
neg_run_child('loadtest_require_safe_attestation([]);', '7) missing SAFE attestation');

// 8) CONFIG_ATTESTED
neg_run_child(
    'loadtest_require_safe_attestation(' . var_export(neg_safe_doc(['status' => 'CONFIG_ATTESTED']), true) . ');',
    '8) CONFIG_ATTESTED rejected'
);

// 9) wrong attested DB
neg_run_child(
    'loadtest_require_safe_attestation(' . var_export(neg_safe_doc(['database' => 'ereview']), true) . ');',
    '9) wrong attested DB'
);

// 10) wrong attested BASE_URL
neg_run_child(
    'loadtest_require_safe_attestation(' . var_export(neg_safe_doc(), true) . ', "http://127.0.0.1/OTHER", "ereview_loadtest");',
    '10) wrong attested BASE_URL'
);

// 11) stale attestation
$stale = neg_safe_doc(['verified_at' => date('c', time() - 7200)]);
neg_run_child(
    'loadtest_require_safe_attestation(' . var_export($stale, true) . ', "http://127.0.0.1/EreviewLoadtest", "ereview_loadtest");',
    '11) stale attestation'
);

// Valid SAFE still accepted
try {
    $got = loadtest_require_safe_attestation(neg_safe_doc(), 'http://127.0.0.1/EreviewLoadtest', 'ereview_loadtest');
    if (($got['status'] ?? '') === 'SAFE') {
        neg_pass('SAFE allowlisted attestation accepted (unit)');
    } else {
        neg_fail('SAFE allowlisted attestation accepted (unit)');
    }
} catch (Throwable $e) {
    neg_fail('SAFE allowlisted attestation accepted (unit): ' . $e->getMessage());
}

$k6 = (string)file_get_contents(__DIR__ . '/k6_mass_timeout.js');
$build = (string)file_get_contents(__DIR__ . '/build_k6_input.php');

// 12) missing session
if (str_contains($k6, 'missing PHPSESSID') && str_contains($build, 'Missing PHPSESSID')) {
    neg_pass('12) missing session rejected (k6+build)');
} else {
    neg_fail('12) missing session rejected (k6+build)');
}

// 13) missing CSRF
if (str_contains($k6, 'missing csrf_token') && (str_contains($build, 'Missing csrf_token') || str_contains($build, 'csrf_token mismatch'))) {
    neg_pass('13) missing CSRF rejected (k6+build)');
} else {
    neg_fail('13) missing CSRF rejected (k6+build)');
}

// 14) missing attempt_id
if (str_contains($k6, 'missing attempt_id') && str_contains($build, 'Missing attempt_id')) {
    neg_pass('14) missing attempt_id rejected (k6+build)');
} else {
    neg_fail('14) missing attempt_id rejected (k6+build)');
}

// 15) missing expected answers
if (str_contains($k6, 'missing expected answers') && str_contains($build, 'Expected answers missing')) {
    neg_pass('15) missing expected answers rejected (k6+build)');
} else {
    neg_fail('15) missing expected answers rejected (k6+build)');
}

// 16) mismatched run ID
if (str_contains($k6, 'run_id mismatch') && str_contains($build, 'run_id')) {
    neg_pass('16) mismatched run ID rejected (k6+build)');
} else {
    neg_fail('16) mismatched run ID rejected (k6+build)');
}

// Extra: crafted-input bypass mitigations present in k6
if (str_contains($k6, 'LOADTEST_DB_NAME env is required') && str_contains($k6, 'attestation stale')) {
    neg_pass('k6 requires env DB + fresh attestation (no crafted bypass)');
} else {
    neg_fail('k6 requires env DB + fresh attestation (no crafted bypass)');
}

echo $failed === 0 ? "\nNEGATIVE SAFETY TESTS PASS\n" : "\nNEGATIVE SAFETY TESTS FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
