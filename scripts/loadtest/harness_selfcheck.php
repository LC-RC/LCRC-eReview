<?php
/**
 * Harness self-check (no load traffic). Validates guards + expected-answer helper + static k6 review.
 * Does not require ereview_loadtest to exist for guard tests.
 */
declare(strict_types=1);

require_once __DIR__ . '/expected_answers.php';

$failed = 0;
function expect(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "PASS  {$msg}\n";
    } else {
        echo "FAIL  {$msg}\n";
        $failed++;
    }
}

// --- Expected answer determinism / structure ---
$a1 = loadtest_expected_letter(101, 55, 'secret');
$a2 = loadtest_expected_letter(101, 55, 'secret');
$a3 = loadtest_expected_letter(101, 56, 'secret');
$a4 = loadtest_expected_letter(202, 55, 'secret');
expect($a1 === $a2, 'expected letter stable for same inputs');
expect(preg_match('/^[A-D]$/', $a1) === 1, 'expected letter is A-D');
expect(preg_match('/^[A-D]$/', $a3) === 1, 'different question letter is A-D');
expect(preg_match('/^[A-D]$/', $a4) === 1, 'different user letter is A-D');

$letters = [];
$sawDiffQuestion = false;
$sawDiffUser = false;
for ($i = 1; $i <= 80; $i++) {
    $letters[loadtest_expected_letter(7, $i, 'secret')] = true;
    if (loadtest_expected_letter(7, $i, 'secret') !== loadtest_expected_letter(7, 1, 'secret')) {
        $sawDiffQuestion = true;
    }
    if (loadtest_expected_letter($i, 9, 'secret') !== loadtest_expected_letter(1, 9, 'secret')) {
        $sawDiffUser = true;
    }
}
expect(count($letters) >= 2, 'crc32 mapping covers multiple letters across questions');
expect($sawDiffQuestion, 'different question_id can change letter');
expect($sawDiffUser, 'different user_id can change letter');

$payload = loadtest_expected_answers_for_user(42, [10, 11, 12], 'secret');
expect(count($payload) === 3, 'expected answers payload count matches questions');
expect(isset($payload[0]['question_id'], $payload[0]['selected_answer']), 'expected JSON keys present');
expect(is_int($payload[0]['question_id']), 'question_id is int');
expect(preg_match('/^[A-D]$/', $payload[0]['selected_answer']) === 1, 'selected_answer is A-D');
$encoded = json_encode($payload);
expect(is_string($encoded) && $encoded !== '', 'expected answers JSON-encodes');
$decoded = json_decode((string)$encoded, true);
expect(is_array($decoded) && count($decoded) === 3, 'expected answers JSON round-trip');

// Marker helpers
expect(loadtest_is_loadtest_email('loadtest+001@ereview.invalid'), 'loadtest email accepted');
expect(!loadtest_is_loadtest_email('student@school.edu'), 'real email rejected');
expect(loadtest_is_loadtest_name('[LOADTEST] Student 001'), 'loadtest name accepted');
expect(!loadtest_is_loadtest_name('Juan Dela Cruz'), 'real name rejected');
expect(loadtest_is_loadtest_exam_title('[LOADTEST] Mass Timeout Test'), 'loadtest exam title accepted');
expect(!loadtest_is_loadtest_exam_title('Midterm Exam'), 'real exam title rejected');

// Guard subprocess checks via temp scripts (avoid PowerShell quoting issues)
$php = PHP_BINARY;
$guard = __DIR__ . '/00_env_guard.php';
$tmpDir = sys_get_temp_dir();
$tmpGuard = $tmpDir . DIRECTORY_SEPARATOR . 'ereview_loadtest_guard_check.php';
file_put_contents($tmpGuard, "<?php\nrequire " . var_export($guard, true) . ";\nloadtest_require_safe_environment();\necho 'GUARD_OK';\n");

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

$envClear = [
    'EREVIEW_LOADTEST' => '',
    'EREVIEW_LOADTEST_CONFIRM' => '',
    'LOADTEST_DB_NAME' => '',
];
$proc1 = proc_open(escapeshellarg($php) . ' ' . escapeshellarg($tmpGuard), $descriptors, $pipes1, null, $envClear);
$out1 = stream_get_contents($pipes1[1]) . stream_get_contents($pipes1[2]);
foreach ($pipes1 as $p) {
    fclose($p);
}
$code1 = proc_close($proc1);
expect($code1 !== 0, 'env guard refuses when flags missing (exit=' . $code1 . ')');

$envProd = [
    'EREVIEW_LOADTEST' => '1',
    'EREVIEW_LOADTEST_CONFIRM' => 'YES',
    'LOADTEST_DB_NAME' => 'ereview',
];
$proc2 = proc_open(escapeshellarg($php) . ' ' . escapeshellarg($tmpGuard), $descriptors, $pipes2, null, $envProd);
$out2 = stream_get_contents($pipes2[1]) . stream_get_contents($pipes2[2]);
foreach ($pipes2 as $p) {
    fclose($p);
}
$code2 = proc_close($proc2);
expect($code2 !== 0, 'env guard refuses LOADTEST_DB_NAME=ereview (exit=' . $code2 . ')');
expect(!str_contains($out2, 'GUARD_OK'), 'production DB path does not continue');

$envEmptyDb = [
    'EREVIEW_LOADTEST' => '1',
    'EREVIEW_LOADTEST_CONFIRM' => 'YES',
    'LOADTEST_DB_NAME' => '',
];
$procE = proc_open(escapeshellarg($php) . ' ' . escapeshellarg($tmpGuard), $descriptors, $pipesE, null, $envEmptyDb);
$outE = stream_get_contents($pipesE[1]) . stream_get_contents($pipesE[2]);
foreach ($pipesE as $p) {
    fclose($p);
}
$codeE = proc_close($procE);
expect($codeE !== 0, 'env guard refuses empty LOADTEST_DB_NAME');

$envOk = [
    'EREVIEW_LOADTEST' => '1',
    'EREVIEW_LOADTEST_CONFIRM' => 'YES',
    'LOADTEST_DB_NAME' => 'ereview_loadtest',
];
$proc3 = proc_open(escapeshellarg($php) . ' ' . escapeshellarg($tmpGuard), $descriptors, $pipes3, null, $envOk);
$out3 = stream_get_contents($pipes3[1]) . stream_get_contents($pipes3[2]);
foreach ($pipes3 as $p) {
    fclose($p);
}
$code3 = proc_close($proc3);
expect($code3 === 0 && str_contains($out3, 'GUARD_OK'), 'env guard accepts ereview_loadtest flags');
@unlink($tmpGuard);

// Files exist
$need = [
    '00_env_guard.php', '01_seed.php', '02_bootstrap_sessions.php', '03_start_attempts.php',
    '04_align_expires.php', '05_monitor_poll.php', '06_verify_integrity.php', '07_collect_mysql_status.php',
    '08_teardown.php', '09_http_preflight.php', 'runtime_db_probe.php', 'expected_answers.php',
    'build_k6_input.php', 'parse_k6_stdout.php', 'negative_safety_tests.php',
    'k6_mass_timeout.js', 'config.example.env', 'README.md',
    'Invoke-Loadtest.ps1', 'Invoke-K6MassTimeout.ps1', 'Get-LoadtestDbName.ps1',
    'Validate-LoadtestScripts.ps1',
];
foreach ($need as $f) {
    expect(is_file(__DIR__ . '/' . $f), "file exists: {$f}");
}

// Teardown safety
$td = file_get_contents(__DIR__ . '/08_teardown.php');
expect(str_contains((string)$td, 'LOADTEST_TEARDOWN_CONFIRM'), 'teardown requires extra confirm');
expect(str_contains((string)$td, 'loadtest_is_loadtest_email'), 'teardown checks email marker');
expect(str_contains((string)$td, 'loadtest_connect'), 'teardown uses guarded connect');
expect(str_contains((string)$td, '[LOADTEST]%'), 'teardown scopes exam title marker');

// Integrity hard-fail behaviors (static source checks)
$iv = file_get_contents(__DIR__ . '/06_verify_integrity.php');
expect(str_contains((string)$iv, 'exit(1)'), 'integrity verifier exits 1 on failure');
expect(str_contains((string)$iv, 'missing_or_mismatch'), 'integrity tracks mismatches');
expect(str_contains((string)$iv, 'FAIL-CLOSED'), 'integrity checks fail-closed');
expect(str_contains((string)$iv, "Successful submit recorded"), 'integrity fails submit_ok still in_progress');
expect(str_contains((string)$iv, 'Duplicate answer rows'), 'integrity fails duplicate answers');
expect(str_contains((string)$iv, 'Duplicate attempts'), 'integrity fails duplicate attempts');

// Static k6 script review (no live traffic)
$k6 = (string)file_get_contents(__DIR__ . '/k6_mass_timeout.js');
expect(str_contains($k6, "action: 'submit'"), 'k6 posts submit');
expect(str_contains($k6, "reason: 'timeout'"), 'k6 timeout reason');
expect(str_contains($k6, 'answers: answersJson'), 'k6 sends full answers JSON');
expect(str_contains($k6, 'csrf_token'), 'k6 sends csrf');
expect(str_contains($k6, 'PHPSESSID') || str_contains($k6, 'cookie_header'), 'k6 sends session cookie');
expect(str_contains($k6, 'LOADTEST_AUTOSAVE_FAILURE_RATE'), 'k6 autosave failure rate env');
expect(str_contains($k6, 'LOADTEST_DUPLICATE_SUBMIT_RATE'), 'k6 duplicate submit env');
expect(str_contains($k6, 'exec.vu.idInTest'), 'k6 maps VU to examinee');
expect(!preg_match('/password\s*[:=]/i', $k6), 'k6 has no hardcoded password');
expect(!str_contains($k6, 'https://') && !preg_match('#http://(?!localhost)#', $k6), 'k6 has no hardcoded remote production URL');
expect(str_contains($k6, 'doc.ajax_url'), 'k6 ajax URL comes from input artifact');
expect(str_contains($k6, 't_end_unix'), 'k6 prefers MySQL-derived t_end_unix');
expect(str_contains($k6, 'attestation stale') || str_contains($k6, 'ATTESTATION_MAX_AGE'), 'k6 rejects stale attestation');
expect(str_contains($k6, 'LOADTEST_DB_NAME env is required'), 'k6 requires LOADTEST_DB_NAME env');
expect(str_contains($k6, 'LOADTEST_BASE_URL env is required'), 'k6 requires LOADTEST_BASE_URL env');
expect(str_contains($k6, 'run_id mismatch'), 'k6 rejects run_id mismatch');

// Attempt start must use guarded CLI path
$start = (string)file_get_contents(__DIR__ . '/03_start_attempts.php');
expect(str_contains($start, 'loadtest_connect'), '03_start_attempts uses guarded connect');
expect(str_contains($start, 'college_exam_user_can_start'), '03 uses real can_start rules');
expect(str_contains($start, 'college_exam_compute_expires_at'), '03 uses real expires_at rules');
expect(str_contains($start, 'cli_guarded_db'), '03 records CLI guarded start mode');
expect(str_contains($start, 'ereview_user_has_college_examination_access'), '03 checks portal access');
expect(str_contains($start, 'LOADTEST_HTTP_START is not supported'), '03 refuses HTTP start bypass');
expect(str_contains($start, 'loadtest_require_base_url'), '03 requires explicit BASE_URL');

$build = (string)file_get_contents(__DIR__ . '/build_k6_input.php');
expect(str_contains($build, 'loadtest_require_safe_attestation'), 'build_k6_input requires SAFE attestation');
expect(str_contains($build, 'http_attestation'), 'build_k6_input embeds http_attestation');
expect(str_contains($build, 'college_exam_questions'), 'build_k6_input revalidates question IDs from DB');
expect(str_contains($build, 'Duplicate PHPSESSID'), 'build_k6_input enforces cookie isolation');
expect(!str_contains($build, 'loadtest_require_http_allowed'), 'build_k6 no longer uses CONFIG_ATTESTED helper');

$pre = (string)file_get_contents(__DIR__ . '/09_http_preflight.php');
expect(str_contains($pre, 'loadtest_build_http_attestation'), '09 builds runtime attestation');
expect(str_contains($pre, 'http_attestation.json'), '09 writes http_attestation.json');
expect(str_contains($pre, 'runtime_db_probe'), '09 documents runtime probe method');

$probe = (string)file_get_contents(__DIR__ . '/runtime_db_probe.php');
expect(str_contains($probe, 'SELECT DATABASE()'), 'probe returns SELECT DATABASE()');
expect(str_contains($probe, "require dirname(__DIR__, 2) . '/db.php'"), 'probe uses Apache db.php');

$guardSrc = (string)file_get_contents(__DIR__ . '/00_env_guard.php');
expect(str_contains($guardSrc, 'function loadtest_require_base_url'), '00 requires explicit BASE_URL');
expect(str_contains($guardSrc, 'function loadtest_require_safe_attestation'), '00 validates SAFE attestation');
expect(str_contains($guardSrc, 'function loadtest_build_http_attestation'), '00 builds SAFE via probe');
expect(str_contains($guardSrc, 'CONFIG_ATTESTED is not sufficient'), '00 rejects CONFIG_ATTESTED');
expect(!str_contains($guardSrc, "http://localhost/Ereview"), '00 has no silent localhost default URL');

$align = (string)file_get_contents(__DIR__ . '/04_align_expires.php');
expect(str_contains($align, 'DATE_ADD(NOW()'), 'align uses MySQL NOW() for relative T_end');
expect(str_contains($align, 't_end_unix'), 'align records MySQL UNIX_TIMESTAMP');

$boot = (string)file_get_contents(__DIR__ . '/02_bootstrap_sessions.php');
expect(str_contains($boot, 'active_portal'), 'bootstrap sets active_portal');
expect(str_contains($boot, 'csrf_token'), 'bootstrap sets csrf_token');
expect(str_contains($boot, 'loadtest_resolve_session_save_path'), 'bootstrap uses dedicated session path');
expect(str_contains($boot, 'session_regenerate_id'), 'bootstrap regenerates unique PHPSESSID');

$psK6 = (string)file_get_contents(__DIR__ . '/Invoke-K6MassTimeout.ps1');
expect(str_contains($psK6, '09_http_preflight.php'), 'k6 runner runs HTTP preflight');
expect(str_contains($psK6, 'SAFE'), 'k6 runner requires SAFE');
expect(str_contains($psK6, 'LOADTEST_DB_NAME'), 'k6 runner passes LOADTEST_DB_NAME to k6');
expect(str_contains($psK6, 'LOADTEST_BASE_URL'), 'k6 runner passes LOADTEST_BASE_URL to k6');
expect(!preg_match('/\$\{att\./', $psK6), 'k6 runner does not use broken ${att.prop} PowerShell syntax');
expect(str_contains($psK6, '$att.status'), 'k6 runner reads attestation status via $att.status');

$psLoad = (string)file_get_contents(__DIR__ . '/Invoke-Loadtest.ps1');
expect(str_contains($psLoad, 'HttpPreflight'), 'Invoke-Loadtest has HttpPreflight action');
expect(str_contains($psLoad, 'ConfigFile not found'), 'missing -ConfigFile path is intentional fail-closed');

expect(is_file(__DIR__ . '/negative_safety_tests.php'), 'negative_safety_tests.php exists');
expect(is_file(__DIR__ . '/runtime_db_probe.php'), 'runtime_db_probe.php exists');

// DB isolation
$dbScripts = [
    '00_env_guard.php', '01_seed.php', '02_bootstrap_sessions.php', '03_start_attempts.php',
    '04_align_expires.php', '05_monitor_poll.php', '06_verify_integrity.php', '07_collect_mysql_status.php',
    '08_teardown.php', '09_http_preflight.php', 'build_k6_input.php',
];
foreach ($dbScripts as $f) {
    $src = (string)file_get_contents(__DIR__ . '/' . $f);
    if ($f === '00_env_guard.php') {
        expect(str_contains($src, 'function loadtest_connect'), '00 defines loadtest_connect');
        expect(str_contains($src, 'LOADTEST_DB_NAME'), '00 gates on LOADTEST_DB_NAME');
        continue;
    }
    expect(str_contains($src, 'loadtest_connect'), "{$f} uses loadtest_connect");
    expect(!preg_match('/require(?:_once)?\s+[^;]*\bdb\.php\b/', $src), "{$f} does not require app db.php");
    expect(!preg_match('/mysqli_connect\s*\(/', $src), "{$f} has no direct mysqli_connect");
}

echo $failed === 0 ? "\nHARNESS SELF-CHECK PASS\n" : "\nHARNESS SELF-CHECK FAIL ({$failed})\n";
exit($failed === 0 ? 0 : 1);
