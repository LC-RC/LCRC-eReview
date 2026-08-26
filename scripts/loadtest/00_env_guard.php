<?php
/**
 * Load-test environment safety + shared helpers.
 * Included by every scripts/loadtest/*.php entrypoint.
 */
declare(strict_types=1);

const LOADTEST_ALLOWED_DB_NAMES = ['ereview_loadtest', 'ereview_test'];
const LOADTEST_BLOCKED_DB_NAMES = ['ereview', 'ereview_prod', 'ereview_production', 'production', 'prod'];
const LOADTEST_EMAIL_DOMAIN = 'ereview.invalid';
const LOADTEST_NAME_PREFIX = '[LOADTEST]';
const LOADTEST_EXAM_TITLE = '[LOADTEST] Mass Timeout Test';
const LOADTEST_PROF_EMAIL = 'loadtest+professor@ereview.invalid';
const LOADTEST_PROF_NAME = '[LOADTEST] Professor Monitor';
const LOADTEST_SECTION = 'LOADTEST-SECTION';
/** Attestation max age for build_k6 / k6 runners (seconds). */
const LOADTEST_ATTESTATION_MAX_AGE_SEC = 1800;

/**
 * Load KEY=VALUE pairs from an env file into putenv/$_ENV (does not override existing env).
 */
function loadtest_load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '') {
            continue;
        }
        if (preg_match('/^"(.*)"$/', $v, $m) || preg_match("/^'(.*)'$/", $v, $m)) {
            $v = $m[1];
        }
        $existing = getenv($k);
        if ($existing !== false && $existing !== '') {
            continue;
        }
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}

function loadtest_env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }

    return $v;
}

function loadtest_project_root(): string
{
    return dirname(__DIR__, 2);
}

function loadtest_dir(): string
{
    return __DIR__;
}

function loadtest_artifacts_dir(): string
{
    $d = __DIR__ . DIRECTORY_SEPARATOR . 'artifacts';
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }

    return $d;
}

/**
 * Dedicated session directory under the harness (not shared XAMPP tmp).
 */
function loadtest_default_session_save_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'sessions';
}

/**
 * Hard safety gate. Exits process on failure.
 */
function loadtest_require_safe_environment(): void
{
    $root = loadtest_project_root();
    loadtest_load_env_file(__DIR__ . '/config.local.env');
    loadtest_load_env_file(__DIR__ . '/config.env');

    $flag = strtoupper(trim((string)loadtest_env('EREVIEW_LOADTEST', '')));
    $confirm = strtoupper(trim((string)loadtest_env('EREVIEW_LOADTEST_CONFIRM', '')));
    if ($flag !== '1' && $flag !== 'TRUE' && $flag !== 'YES') {
        fwrite(STDERR, "LOADTEST SAFETY: EREVIEW_LOADTEST=1 is required. Refusing to run.\n");
        exit(2);
    }
    if ($confirm !== 'YES') {
        fwrite(STDERR, "LOADTEST SAFETY: EREVIEW_LOADTEST_CONFIRM=YES is required. Refusing to run.\n");
        exit(2);
    }

    $dbName = trim((string)loadtest_env('LOADTEST_DB_NAME', ''));
    if ($dbName === '') {
        fwrite(STDERR, "LOADTEST SAFETY: LOADTEST_DB_NAME is required and must be an allowlisted load-test database.\n");
        exit(2);
    }
    $dbNameLower = strtolower($dbName);
    if (in_array($dbNameLower, LOADTEST_BLOCKED_DB_NAMES, true)) {
        fwrite(STDERR, "LOADTEST SAFETY: Database '{$dbName}' is blocked (production/ambiguous). Refusing to run.\n");
        exit(2);
    }
    if (!in_array($dbNameLower, LOADTEST_ALLOWED_DB_NAMES, true)) {
        fwrite(STDERR, "LOADTEST SAFETY: Database '{$dbName}' is not in the allowlist (" . implode(', ', LOADTEST_ALLOWED_DB_NAMES) . "). Refusing to run.\n");
        exit(2);
    }
}

/**
 * Connect ONLY to the allowlisted LOADTEST_DB_NAME.
 * Credentials: LOADTEST_DB_* or fallback host/user/pass from db.local.php (never its $db name).
 *
 * @return array{0:mysqli,1:string}
 */
function loadtest_connect(): array
{
    loadtest_require_safe_environment();
    $dbName = strtolower(trim((string)loadtest_env('LOADTEST_DB_NAME', '')));

    $host = (string)loadtest_env('LOADTEST_DB_HOST', '');
    $user = (string)loadtest_env('LOADTEST_DB_USER', '');
    $passEnvSet = loadtest_env('LOADTEST_DB_PASS', null) !== null;
    $pass = $passEnvSet ? (string)loadtest_env('LOADTEST_DB_PASS', '') : null;

    $local = loadtest_project_root() . '/db.local.php';
    if (($host === '' || $user === '' || $pass === null) && is_file($local)) {
        $cfgHost = $cfgUser = $cfgPass = $cfgDb = null;
        (static function () use ($local, &$cfgHost, &$cfgUser, &$cfgPass, &$cfgDb): void {
            /** @noinspection PhpIncludeInspection */
            require $local;
            $cfgHost = isset($host) ? (string)$host : 'localhost';
            $cfgUser = isset($user) ? (string)$user : 'root';
            $cfgPass = isset($pass) ? (string)$pass : '';
            $cfgDb = isset($db) ? strtolower(trim((string)$db)) : '';
        })();
        if ($cfgDb !== '' && in_array($cfgDb, LOADTEST_BLOCKED_DB_NAMES, true)) {
            // Borrow credentials only; connection still targets LOADTEST_DB_NAME.
        } elseif ($cfgDb !== '' && !in_array($cfgDb, LOADTEST_ALLOWED_DB_NAMES, true)) {
            fwrite(STDERR, "LOADTEST SAFETY: db.local.php \$db='{$cfgDb}' is not allowlisted. Set LOADTEST_DB_HOST/USER/PASS explicitly.\n");
            exit(2);
        }
        if ($host === '') {
            $host = (string)$cfgHost;
        }
        if ($user === '') {
            $user = (string)$cfgUser;
        }
        if ($pass === null) {
            $pass = (string)$cfgPass;
        }
    }
    if ($pass === null) {
        $pass = '';
    }

    if ($host === '' || $user === '') {
        fwrite(STDERR, "LOADTEST SAFETY: Missing LOADTEST_DB_HOST / LOADTEST_DB_USER (and no usable db.local.php fallback).\n");
        exit(2);
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @mysqli_connect($host, $user, $pass, $dbName);
    if (!$conn) {
        fwrite(STDERR, "LOADTEST SAFETY: Could not connect to allowlisted DB '{$dbName}': " . mysqli_connect_error() . "\n");
        fwrite(STDERR, "Create the database and import schema first (see README.md). Do not use the production database.\n");
        fwrite(STDERR, "HTTP/k6 remains BLOCKED until the isolated load-test DB exists and Apache is attested to use it.\n");
        exit(2);
    }
    mysqli_set_charset($conn, 'utf8mb4');
    @mysqli_query($conn, "SET time_zone = '+08:00'");

    $check = mysqli_query($conn, 'SELECT DATABASE() AS d');
    $row = $check ? mysqli_fetch_assoc($check) : null;
    if ($check) {
        mysqli_free_result($check);
    }
    $actual = strtolower(trim((string)($row['d'] ?? '')));
    if ($actual === '' || $actual !== $dbName) {
        fwrite(STDERR, "LOADTEST SAFETY: Connected database mismatch (got '{$actual}', expected '{$dbName}'). Refusing to continue.\n");
        mysqli_close($conn);
        exit(2);
    }
    if (!in_array($actual, LOADTEST_ALLOWED_DB_NAMES, true)) {
        fwrite(STDERR, "LOADTEST SAFETY: Connected database '{$actual}' failed allowlist re-check.\n");
        mysqli_close($conn);
        exit(2);
    }

    return [$conn, $actual];
}

function loadtest_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "LOADTEST ERROR: {$message}\n");
    exit($code);
}

function loadtest_ok(string $message): void
{
    fwrite(STDOUT, "LOADTEST: {$message}\n");
}

function loadtest_run_id(?string $explicit = null): string
{
    if ($explicit !== null && $explicit !== '') {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $explicit) ?: 'run';
    }
    $fromEnv = loadtest_env('LOADTEST_RUN_ID', '');
    if ($fromEnv !== null && $fromEnv !== '') {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $fromEnv) ?: 'run';
    }

    return 'run_' . date('Ymd_His');
}

function loadtest_artifact_path(string $runId, string $name): string
{
    $dir = loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . $runId;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . DIRECTORY_SEPARATOR . $name;
}

function loadtest_write_json(string $path, mixed $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        loadtest_fail('Failed to encode JSON for ' . $path);
    }
    if (file_put_contents($path, $json . "\n") === false) {
        loadtest_fail('Failed to write ' . $path);
    }
}

function loadtest_read_json(string $path): array
{
    if (!is_file($path)) {
        loadtest_fail('Missing artifact: ' . $path);
    }
    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        loadtest_fail('Invalid JSON artifact: ' . $path);
    }

    return $data;
}

function loadtest_table_exists(mysqli $conn, string $table): bool
{
    $t = mysqli_real_escape_string($conn, $table);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    $ok = $r && mysqli_num_rows($r) > 0;
    if ($r) {
        mysqli_free_result($r);
    }

    return $ok;
}

function loadtest_column_exists(mysqli $conn, string $table, string $column): bool
{
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    $ok = $r && mysqli_num_rows($r) > 0;
    if ($r) {
        mysqli_free_result($r);
    }

    return $ok;
}

/**
 * Require explicit LOADTEST_BASE_URL. No silent localhost/Ereview default.
 */
function loadtest_require_base_url(): string
{
    $raw = loadtest_env('LOADTEST_BASE_URL', null);
    if ($raw === null || trim((string)$raw) === '') {
        loadtest_fail('LOADTEST_BASE_URL is required and must be set explicitly (no default URL).');
    }
    $url = rtrim(trim((string)$raw), '/');
    if (preg_match('/\s/', $url)) {
        loadtest_fail('LOADTEST_BASE_URL must not contain whitespace');
    }
    if (!preg_match('#^https?://#i', $url)) {
        loadtest_fail('LOADTEST_BASE_URL must start with http:// or https://');
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        loadtest_fail('LOADTEST_BASE_URL is malformed (could not parse scheme/host)');
    }
    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        loadtest_fail('LOADTEST_BASE_URL scheme must be http or https');
    }
    $host = strtolower((string)$parts['host']);
    if ($host === '' || $host === '.' || str_contains($host, '..')) {
        loadtest_fail('LOADTEST_BASE_URL host is invalid');
    }

    return $url;
}

function loadtest_secret(): string
{
    $s = (string)loadtest_env('LOADTEST_ANSWER_SECRET', 'loadtest-phase-a-secret');
    if ($s === '') {
        $s = 'loadtest-phase-a-secret';
    }

    return $s;
}

function loadtest_is_loadtest_email(string $email): bool
{
    $email = strtolower(trim($email));

    return str_ends_with($email, '@' . LOADTEST_EMAIL_DOMAIN) && str_starts_with($email, 'loadtest+');
}

function loadtest_is_loadtest_name(string $name): bool
{
    return str_starts_with(trim($name), LOADTEST_NAME_PREFIX);
}

function loadtest_is_loadtest_exam_title(string $title): bool
{
    return str_starts_with(trim($title), LOADTEST_NAME_PREFIX);
}

function loadtest_is_allowlisted_db(?string $db): bool
{
    $db = strtolower(trim((string)$db));

    return $db !== '' && in_array($db, LOADTEST_ALLOWED_DB_NAMES, true);
}

function loadtest_probe_secret_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.probe_secret';
}

/**
 * Arm a one-time probe secret readable by runtime_db_probe.php under Apache.
 */
function loadtest_arm_probe_secret(): string
{
    $secret = bin2hex(random_bytes(32));
    $path = loadtest_probe_secret_path();
    if (file_put_contents($path, $secret . "\n") === false) {
        loadtest_fail('Could not write probe secret file');
    }
    @chmod($path, 0600);

    return $secret;
}

function loadtest_disarm_probe_secret(): void
{
    $path = loadtest_probe_secret_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Normalize filesystem paths for comparison (session.save_path).
 */
function loadtest_normalize_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $real = realpath($path);
    if ($real !== false) {
        $path = $real;
    }
    $path = str_replace('\\', '/', $path);

    return rtrim(strtolower($path), '/');
}

/**
 * Resolve dedicated load-test session.save_path (fail closed on shared/ambiguous paths).
 *
 * @return array{path:string, source:string}
 */
function loadtest_resolve_session_save_path(): array
{
    $configured = trim((string)loadtest_env('LOADTEST_SESSION_SAVE_PATH', ''));
    $dedicated = loadtest_default_session_save_path();
    if ($configured === '') {
        $path = $dedicated;
        $source = 'harness_default';
    } else {
        $path = $configured;
        $source = 'env';
    }
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        loadtest_fail('Cannot create session.save_path: ' . $path);
    }
    $norm = loadtest_normalize_path($path);
    $dedNorm = loadtest_normalize_path($dedicated);
    // Reject well-known shared XAMPP/system temp paths (production session collision risk).
    $ambiguous = [
        loadtest_normalize_path('C:\\xampp\\tmp'),
        loadtest_normalize_path('C:/xampp/tmp'),
        loadtest_normalize_path(sys_get_temp_dir()),
    ];
    foreach ($ambiguous as $bad) {
        if ($bad !== '' && $norm === $bad) {
            loadtest_fail(
                'LOADTEST_SESSION_SAVE_PATH points at a shared system/XAMPP temp directory (' . $path . '). ' .
                'Use the dedicated harness path: ' . $dedicated
            );
        }
    }
    // Prefer (and for HTTP SAFE require) the harness-owned directory.
    if ($source === 'env' && $dedNorm !== '' && $norm !== $dedNorm) {
        // Allow only if still under scripts/loadtest/
        $under = loadtest_normalize_path(__DIR__);
        if ($under === '' || !str_starts_with($norm, $under . '/')) {
            loadtest_fail(
                'LOADTEST_SESSION_SAVE_PATH must be under scripts/loadtest/ for isolation (got: ' . $path . ')'
            );
        }
    }

    return ['path' => $path, 'source' => $source];
}

function loadtest_attestation_path(?string $runId = null): string
{
    if ($runId !== null && $runId !== '') {
        return loadtest_artifact_path($runId, 'http_attestation.json');
    }

    return loadtest_artifacts_dir() . DIRECTORY_SEPARATOR . 'http_attestation.json';
}

/**
 * Validate an attestation document. Throws via loadtest_fail on reject.
 *
 * @param array<string,mixed> $doc
 * @return array<string,mixed>
 */
function loadtest_require_safe_attestation(array $doc, ?string $expectedBaseUrl = null, ?string $expectedDb = null): array
{
    $status = strtoupper(trim((string)($doc['status'] ?? '')));
    if ($status === 'CONFIG_ATTESTED') {
        loadtest_fail('Attestation status CONFIG_ATTESTED is not sufficient. Only status=SAFE is accepted.');
    }
    if ($status !== 'SAFE') {
        loadtest_fail('Attestation status must be SAFE (got: ' . ($doc['status'] ?? 'missing') . '). HTTP/k6 BLOCKED.');
    }
    $database = strtolower(trim((string)($doc['database'] ?? '')));
    if (!loadtest_is_allowlisted_db($database)) {
        loadtest_fail('Attestation database is not allowlisted: ' . $database);
    }
    if (in_array($database, LOADTEST_BLOCKED_DB_NAMES, true)) {
        loadtest_fail('Attestation database is blocked/production: ' . $database);
    }
    if ($expectedDb !== null && $expectedDb !== '' && $database !== strtolower($expectedDb)) {
        loadtest_fail("Attestation database '{$database}' !== expected '{$expectedDb}'");
    }
    $base = rtrim(trim((string)($doc['base_url'] ?? '')), '/');
    if ($base === '') {
        loadtest_fail('Attestation missing base_url');
    }
    if ($expectedBaseUrl !== null && $expectedBaseUrl !== '' && $base !== rtrim($expectedBaseUrl, '/')) {
        loadtest_fail("Attestation base_url mismatch (attested={$base}, expected={$expectedBaseUrl})");
    }
    $method = trim((string)($doc['verification_method'] ?? ''));
    if ($method === '' || !str_contains($method, 'runtime_db_probe')) {
        loadtest_fail('Attestation verification_method must be runtime_db_probe (SELECT DATABASE via web runtime)');
    }
    $verifiedAt = trim((string)($doc['verified_at'] ?? ''));
    if ($verifiedAt === '') {
        loadtest_fail('Attestation missing verified_at');
    }
    $ts = strtotime($verifiedAt);
    if ($ts === false) {
        loadtest_fail('Attestation verified_at is not a valid timestamp');
    }
    if ((time() - $ts) > LOADTEST_ATTESTATION_MAX_AGE_SEC) {
        loadtest_fail('Attestation is stale (>' . LOADTEST_ATTESTATION_MAX_AGE_SEC . 's). Re-run HttpPreflight.');
    }
    if ((time() - $ts) < -120) {
        loadtest_fail('Attestation verified_at is in the future');
    }

    return $doc;
}

/**
 * HTTP GET the harness-only runtime DB probe through Apache (proves web runtime DB).
 *
 * @return array{ok:bool, database:?string, session_save_path:?string, raw:?string, http_code:int, error:?string}
 */
function loadtest_probe_web_runtime_db(string $baseUrl, string $probeSecret): array
{
    $url = rtrim($baseUrl, '/') . '/scripts/loadtest/runtime_db_probe.php';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "X-Ereview-Loadtest-Probe: {$probeSecret}\r\nAccept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
    }
    if ($raw === false || $raw === '') {
        return [
            'ok' => false,
            'database' => null,
            'session_save_path' => null,
            'raw' => null,
            'http_code' => $code,
            'error' => 'Probe HTTP request failed (is Apache serving scripts/loadtest/runtime_db_probe.php?)',
        ];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['ok'])) {
        return [
            'ok' => false,
            'database' => null,
            'session_save_path' => null,
            'raw' => $raw,
            'http_code' => $code,
            'error' => 'Probe returned non-ok JSON (http=' . $code . ')',
        ];
    }
    $db = strtolower(trim((string)($data['database'] ?? '')));
    $sess = (string)($data['session_save_path'] ?? '');

    return [
        'ok' => true,
        'database' => $db !== '' ? $db : null,
        'session_save_path' => $sess,
        'raw' => $raw,
        'http_code' => $code,
        'error' => null,
    ];
}

/**
 * Build attestation from CLI DB + live web runtime probe. Never SAFE from operator flags alone.
 *
 * @return array<string,mixed>
 */
function loadtest_build_http_attestation(mysqli $conn, string $cliDbName, string $runId): array
{
    $reasons = [];
    $cliDb = strtolower(trim($cliDbName));
    $baseUrl = loadtest_require_base_url();

    $check = mysqli_query($conn, 'SELECT DATABASE() AS d');
    $row = $check ? mysqli_fetch_assoc($check) : null;
    if ($check) {
        mysqli_free_result($check);
    }
    $cliActual = strtolower(trim((string)($row['d'] ?? '')));
    $cliReady = loadtest_is_allowlisted_db($cliActual) && $cliActual === $cliDb;
    if (!$cliReady) {
        $reasons[] = "CLI SELECT DATABASE() not ready (got '{$cliActual}', expected '{$cliDb}')";
    }

    $session = loadtest_resolve_session_save_path();
    $cliSessionPath = loadtest_normalize_path($session['path']);

    $secret = loadtest_arm_probe_secret();
    $probe = loadtest_probe_web_runtime_db($baseUrl, $secret);
    loadtest_disarm_probe_secret();

    $webDb = $probe['database'] ?? null;
    $webSession = loadtest_normalize_path((string)($probe['session_save_path'] ?? ''));

    if (!$probe['ok']) {
        $reasons[] = 'Web runtime probe failed: ' . (string)($probe['error'] ?? 'unknown');
    }
    if ($webDb === null || $webDb === '') {
        $reasons[] = 'Web runtime database identity could not be proven (empty SELECT DATABASE())';
    } elseif (!loadtest_is_allowlisted_db($webDb)) {
        $reasons[] = "Web runtime DATABASE()='{$webDb}' is not an allowlisted load-test DB — HTTP BLOCKED";
    } elseif ($webDb !== $cliDb) {
        $reasons[] = "Web runtime DB '{$webDb}' !== CLI LOADTEST_DB_NAME '{$cliDb}'";
    }
    if ($webSession === '') {
        $reasons[] = 'Web runtime session.save_path could not be read from probe';
    } elseif ($webSession !== $cliSessionPath) {
        $reasons[] = "Web session.save_path '{$webSession}' !== harness session path '{$cliSessionPath}' "
            . '(dedicated staging PHP session path required; do not share production XAMPP tmp)';
    }

    $safe = $cliReady
        && $probe['ok']
        && loadtest_is_allowlisted_db($webDb)
        && $webDb === $cliDb
        && $webSession !== ''
        && $webSession === $cliSessionPath;

    $status = $safe ? 'SAFE' : 'BLOCKED';
    if (!$safe) {
        $reasons[] = 'HTTP safety remains BLOCKED because runtime Apache DB identity could not be proven SAFE '
            . '(operator flags / db.local.php alone are never sufficient).';
    }

    return [
        'status' => $status,
        'database' => $safe ? $webDb : ($webDb ?? null),
        'base_url' => $baseUrl,
        'verified_at' => date('c'),
        'verification_method' => 'runtime_db_probe+SELECT_DATABASE+session_save_path',
        'run_id' => $runId,
        'cli_db' => $cliDb,
        'cli_ready' => $cliReady,
        'web_runtime_db' => $webDb,
        'session_save_path' => $session['path'],
        'web_session_save_path' => $probe['session_save_path'] ?? null,
        'session_path_matched' => $safe,
        'probe_http_code' => $probe['http_code'],
        'http_allowed' => $safe,
        'reasons' => $reasons,
        'note' => $safe
            ? 'SAFE: web runtime SELECT DATABASE() and session.save_path match allowlisted load-test isolation.'
            : 'BLOCKED: cannot permit k6/HTTP examination traffic.',
    ];
}
