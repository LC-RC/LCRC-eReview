<?php
/**
 * Cached table/column existence checks.
 * Avoids repeated SHOW TABLES / SHOW COLUMNS on every admin request and poll.
 */
declare(strict_types=1);

if (!function_exists('ereview_schema_cache_ttl')) {
    function ereview_schema_cache_ttl(): int
    {
        return 3600; // 1 hour — schema changes only via migrations
    }
}

if (!function_exists('ereview_schema_session_get')) {
    /**
     * @return mixed|null
     */
    function ereview_schema_session_get(string $key)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $bucket = $_SESSION['_ereview_schema'] ?? null;
        if (!is_array($bucket)) {
            return null;
        }
        $entry = $bucket[$key] ?? null;
        if (!is_array($entry) || !array_key_exists('v', $entry) || !isset($entry['t'])) {
            return null;
        }
        if ((time() - (int) $entry['t']) > ereview_schema_cache_ttl()) {
            return null;
        }
        return $entry['v'];
    }
}

if (!function_exists('ereview_schema_session_set')) {
    /**
     * @param mixed $value
     */
    function ereview_schema_session_set(string $key, $value): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($_SESSION['_ereview_schema']) || !is_array($_SESSION['_ereview_schema'])) {
            $_SESSION['_ereview_schema'] = [];
        }
        $_SESSION['_ereview_schema'][$key] = ['v' => $value, 't' => time()];
    }
}

if (!function_exists('ereview_schema_table_exists')) {
    function ereview_schema_table_exists(mysqli $conn, string $table): bool
    {
        static $req = [];
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        if ($table === '') {
            return false;
        }
        $key = 't:' . $table;
        if (array_key_exists($key, $req)) {
            return (bool) $req[$key];
        }
        $cached = ereview_schema_session_get($key);
        if ($cached !== null) {
            return $req[$key] = (bool) $cached;
        }
        $esc = mysqli_real_escape_string($conn, $table);
        $res = @mysqli_query($conn, "SHOW TABLES LIKE '{$esc}'");
        $ok = (bool) ($res && mysqli_fetch_row($res));
        if ($res) {
            mysqli_free_result($res);
        }
        ereview_schema_session_set($key, $ok);
        return $req[$key] = $ok;
    }
}

if (!function_exists('ereview_schema_column_exists')) {
    function ereview_schema_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $req = [];
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
        if ($table === '' || $column === '') {
            return false;
        }
        $key = 'c:' . $table . '.' . $column;
        if (array_key_exists($key, $req)) {
            return (bool) $req[$key];
        }
        $cached = ereview_schema_session_get($key);
        if ($cached !== null) {
            return $req[$key] = (bool) $cached;
        }
        $tEsc = mysqli_real_escape_string($conn, $table);
        $cEsc = mysqli_real_escape_string($conn, $column);
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM `{$tEsc}` LIKE '{$cEsc}'");
        $ok = (bool) ($res && mysqli_fetch_assoc($res));
        if ($res) {
            mysqli_free_result($res);
        }
        ereview_schema_session_set($key, $ok);
        return $req[$key] = $ok;
    }
}
