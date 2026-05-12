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
     *
     * Entry scripts under /api/messages/ or /api/chat/ are not the public asset root; uploads live under
     * the same app root as admin_*.php pages. Without this, avatar URLs become /api/messages/uploads/... (404).
     */
    function ereview_web_base_path(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $reqPath = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));
        $reqPath = preg_replace('/\?.*/', '', $reqPath);
        $stripSource = (preg_match('#/api/(?:messages|chat)/#', $reqPath) !== 0) ? $reqPath : $script;
        if (preg_match('#/api/(?:messages|chat)/#', $stripSource) !== 0) {
            $root = preg_replace('#/api/(?:messages|chat)/.*$#', '', $stripSource);
            $root = rtrim($root, '/');
            $cached = ($root === '' || $root === '/') ? '' : $root;
            return $cached;
        }
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

