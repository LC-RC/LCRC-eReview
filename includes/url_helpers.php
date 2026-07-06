<?php
/**
 * Extensionless public URL helpers (paired with root .htaccess rewrite rules).
 */

if (!function_exists('ereview_page_basename')) {
    /**
     * Current script name without .php (for nav active state).
     */
    function ereview_page_basename(?string $script = null): string
    {
        if ($script === null) {
            $script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? 'index.php';
        }
        $base = basename($script);
        $base = preg_replace('/\.php$/i', '', $base);

        return $base !== '' ? $base : 'index';
    }
}

if (!function_exists('ereview_url')) {
    /**
     * Convert an internal script path to an extensionless browser URL.
     */
    function ereview_url(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }
        if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'mailto:')) {
            return $path;
        }

        $fragment = '';
        if (($hashAt = strpos($path, '#')) !== false) {
            $fragment = substr($path, $hashAt);
            $path = substr($path, 0, $hashAt);
        }

        $query = '';
        if (($queryAt = strpos($path, '?')) !== false) {
            $query = substr($path, $queryAt);
            $path = substr($path, 0, $queryAt);
        }

        $path = preg_replace('/\.php$/i', '', $path);
        if ($path === 'index' || preg_match('#/index$#', $path)) {
            $path = preg_replace('#/index$#', '', $path);
            if ($path === '') {
                $path = '.';
            }
        }

        return $path . $query . $fragment;
    }
}

if (!function_exists('ereview_redirect')) {
    /**
     * Redirect to an extensionless public URL.
     */
    function ereview_redirect(string $path, int $status = 302): void
    {
        header('Location: ' . ereview_url($path), true, $status);
        exit;
    }
}

if (!function_exists('ereview_page_matches')) {
    /**
     * Whether the current page matches a script name (with or without .php).
     */
    function ereview_page_matches(string $candidate, ?string $current = null): bool
    {
        return ereview_page_basename($current) === ereview_page_basename($candidate);
    }
}
