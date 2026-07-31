<?php
/**
 * Admin helpers for login account window (users.access_start / access_end).
 * Distinct from commerce grants / SCA.
 */

/**
 * @return 'day'|'month'|'year'
 */
function admin_normalize_duration_unit(string $unit): string
{
    $u = strtolower(trim($unit));
    if ($u === 'day' || $u === 'days' || $u === 'd') {
        return 'day';
    }
    if ($u === 'year' || $u === 'years' || $u === 'y') {
        return 'year';
    }
    return 'month';
}

/** MySQL INTERVAL unit keyword. */
function admin_sql_interval_unit(string $unit): string
{
    $u = admin_normalize_duration_unit($unit);
    if ($u === 'day') {
        return 'DAY';
    }
    if ($u === 'year') {
        return 'YEAR';
    }
    return 'MONTH';
}

/** Approximate months for legacy access_months column. */
function admin_duration_to_months_equiv(int $value, string $unit): int
{
    $value = max(0, $value);
    $u = admin_normalize_duration_unit($unit);
    if ($u === 'day') {
        return max(1, (int) ceil($value / 30));
    }
    if ($u === 'year') {
        return max(1, $value * 12);
    }
    return max(1, $value);
}

function admin_safe_return_to(string $returnTo, string $fallback = 'admin_students'): string
{
    $returnTo = trim($returnTo);
    if ($returnTo === '' || strpos($returnTo, '://') !== false || strpos($returnTo, '//') === 0 || strpos($returnTo, '/') === 0) {
        return $fallback;
    }
    return $returnTo;
}
