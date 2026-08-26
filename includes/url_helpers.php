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

if (!function_exists('ereview_set_post_login_redirect')) {
    /**
     * Remember a safe internal page to open after login (basename only, no query).
     */
    function ereview_set_post_login_redirect(string $basename): void
    {
        $basename = preg_replace('/\.php$/i', '', trim($basename));
        if ($basename !== '' && preg_match('/^[a-z0-9_]+$/', $basename)) {
            $_SESSION['post_login_redirect'] = $basename;
        }
    }
}

if (!function_exists('ereview_consume_post_login_redirect')) {
    /**
     * Return extensionless URL for a stored post-login redirect, or null if none/invalid.
     */
    function ereview_consume_post_login_redirect(?string $role = null): ?string
    {
        $path = $_SESSION['post_login_redirect'] ?? null;
        unset($_SESSION['post_login_redirect']);
        if (!is_string($path) || $path === '' || !preg_match('/^[a-z0-9_]+$/', $path)) {
            return null;
        }

        $professorAllowed = [
            'student_registration',
            'professor_college_students',
            'professor_create_college_student',
            'professor_admin_dashboard',
        ];

        if ($role === 'professor_admin' && in_array($path, $professorAllowed, true)) {
            return ereview_url($path);
        }

        return null;
    }
}
