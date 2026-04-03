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

if (!function_exists('ereview_web_base_path')) {
    /**
     * URL path prefix for this app (e.g. '' or '/ereview') so assets work when not deployed at domain root.
     */
    function ereview_web_base_path(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));
        $dir = rtrim($dir, '/');
        if ($dir === '' || $dir === '.' || $dir === '/') {
            $cached = '';
        } else {
            $cached = $dir;
        }
        return $cached;
    }
}

if (!function_exists('ereview_avatar_img_src')) {
    /**
     * Absolute URL path for <img src> (fixes broken avatars when DB has Windows paths or wrong relativity).
     */
    function ereview_avatar_img_src($rawPath): string
    {
        $path = trim((string)$rawPath);
        if ($path === '') {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (preg_match('#^[a-z]:/#i', $path)) {
            if (preg_match('#(?:htdocs|Ereview|ereview)[/](uploads/profile_pictures/.+)$#i', $path, $m)) {
                $path = $m[1];
            } elseif (preg_match('#/(uploads/.+)$#i', $path, $m)) {
                $path = ltrim($m[1], '/');
            } else {
                return '';
            }
        }
        $path = ltrim($path, '/');
        if ($path === '' || strpos($path, '..') !== false) {
            return '';
        }
        $base = ereview_web_base_path();
        if ($base === '') {
            return '/' . $path;
        }

        return $base . '/' . $path;
    }
}

