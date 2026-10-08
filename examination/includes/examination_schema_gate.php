<?php
/**
 * Schema ensure gate — student exam hot paths must never run DDL.
 * Enable only via deploy migrate CLI or explicit $GLOBALS['EREVIEW_ENSURE_SCHEMA']=true.
 */
function ereview_schema_ensure_enabled(): bool
{
    if (!empty($GLOBALS['EREVIEW_ENSURE_SCHEMA'])) {
        return true;
    }
    $env = getenv('EREVIEW_ENSURE_SCHEMA');
    if ($env === '1' || strtolower((string)$env) === 'true') {
        return true;
    }
    if (defined('EREVIEW_SCHEMA_MIGRATE') && EREVIEW_SCHEMA_MIGRATE) {
        return true;
    }

    return false;
}

/**
 * Positive-cache schema probes across PHP workers via a temp file.
 * Student hot paths must not SHOW TABLES / SHOW COLUMNS on every request.
 * Only caches true; missing objects still probe until they exist (deploy).
 *
 * @param callable(mysqli):bool $probe
 */
function ereview_schema_object_ready_cached(mysqli $conn, string $cacheKey, callable $probe): bool
{
    static $mem = [];
    if (array_key_exists($cacheKey, $mem)) {
        return $mem[$cacheKey];
    }
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cacheKey);
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'ereview_schema_ready';
    $file = $dir . DIRECTORY_SEPARATOR . $safe . '.ok';
    if (is_file($file)) {
        $mem[$cacheKey] = true;

        return true;
    }
    $ok = false;
    try {
        $ok = (bool)$probe($conn);
    } catch (Throwable $e) {
        $ok = false;
    }
    if ($ok) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, '1');
    }
    $mem[$cacheKey] = $ok;

    return $ok;
}
