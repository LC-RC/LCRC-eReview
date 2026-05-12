<?php
/**
 * Session-backed temp JSON cache for admin question sort (parse results).
 */

if (!function_exists('ereview_qsort_cache_path')) {
    function ereview_qsort_cache_path(): string {
        $sid = session_id();
        if ($sid === '') {
            $sid = 'nosess';
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ereview_qsort_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sid) . '.json';
    }
}

if (!function_exists('ereview_qsort_is_temp_json_safe')) {
    function ereview_qsort_is_temp_json_safe(string $path): bool {
        $real = realpath($path);
        if ($real === false || !is_readable($real)) {
            return false;
        }
        $tmp = realpath(sys_get_temp_dir());
        if ($tmp === false) {
            return false;
        }
        return strncmp($real, $tmp, strlen($tmp)) === 0 && strncmp(basename($real), 'ereview_qsort_', 14) === 0;
    }
}

/** @return array|null */
if (!function_exists('ereview_qsort_load_cache')) {
    function ereview_qsort_load_cache(): ?array {
        $sessionKey = 'ereview_qsort_cache_v1';
        if (empty($_SESSION[$sessionKey]['path'])) {
            return null;
        }
        $p = (string)$_SESSION[$sessionKey]['path'];
        if (!ereview_qsort_is_temp_json_safe($p)) {
            unset($_SESSION[$sessionKey]);
            return null;
        }
        $raw = @file_get_contents($p);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
