<?php
/**
 * Helpers for profile avatar rendering and initials fallback.
 */

if (!function_exists('ereview_avatar_initial')) {
    function ereview_avatar_initial($name) {
        $name = trim((string)$name);
        if ($name === '') {
            return 'U';
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($name, 0, 1));
        }
        return strtoupper(substr($name, 0, 1));
    }
}

if (!function_exists('ereview_avatar_public_path')) {
    function ereview_avatar_public_path($rawPath) {
        $path = trim((string)$rawPath);
        if ($path === '') {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if (strpos($path, '..') !== false) {
            return '';
        }
        return $path;
    }
}

