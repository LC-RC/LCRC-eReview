<?php
/**
 * Simple per-session sliding-window rate limits for profile API endpoints.
 */

if (!function_exists('ereview_profile_rate_limit_exceeded')) {
    /**
     * @return bool True if this request exceeds the limit (caller should reject).
     */
    function ereview_profile_rate_limit_exceeded(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        if ($maxHits < 1 || $windowSeconds < 1) {
            return false;
        }
        $now = time();
        if (!isset($_SESSION['ereview_profile_rl']) || !is_array($_SESSION['ereview_profile_rl'])) {
            $_SESSION['ereview_profile_rl'] = [];
        }
        if (!isset($_SESSION['ereview_profile_rl'][$bucket]) || !is_array($_SESSION['ereview_profile_rl'][$bucket])) {
            $_SESSION['ereview_profile_rl'][$bucket] = [];
        }
        $hits = &$_SESSION['ereview_profile_rl'][$bucket];
        $hits = array_values(array_filter($hits, static function ($t) use ($now, $windowSeconds) {
            return ($now - (int)$t) < $windowSeconds;
        }));
        if (count($hits) >= $maxHits) {
            return true;
        }
        $hits[] = $now;

        return false;
    }
}
