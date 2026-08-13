<?php
/**
 * Simple key/value app settings (site-wide toggles).
 */
declare(strict_types=1);

if (!function_exists('ereview_app_settings_ensure_schema')) {
    function ereview_app_settings_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        @mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS `ereview_app_settings` (
              `setting_key` VARCHAR(64) NOT NULL,
              `setting_value` TEXT NOT NULL,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}

if (!function_exists('ereview_app_setting_get')) {
    function ereview_app_setting_get(mysqli $conn, string $key, string $default = ''): string
    {
        ereview_app_settings_ensure_schema($conn);
        $key = trim($key);
        if ($key === '') {
            return $default;
        }
        $stmt = mysqli_prepare($conn, 'SELECT setting_value FROM ereview_app_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) {
            return $default;
        }
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$row) {
            return $default;
        }
        return (string) ($row['setting_value'] ?? $default);
    }
}

if (!function_exists('ereview_app_setting_set')) {
    function ereview_app_setting_set(mysqli $conn, string $key, string $value): bool
    {
        ereview_app_settings_ensure_schema($conn);
        $key = trim($key);
        if ($key === '') {
            return false;
        }
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO ereview_app_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return (bool) $ok;
    }
}
