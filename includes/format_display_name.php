<?php
/**
 * Compact display names for topbar / sidebar (saves horizontal space).
 *
 * Examples:
 *   "System Admin"          → "S. Admin"
 *   "Sample Student Lizeth" → "S. S. Lizeth"
 */
if (!function_exists('ereview_format_topbar_display_name')) {
    function ereview_format_topbar_display_name(?string $fullName): string
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return 'User';
        }

        $words = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($words);

        if ($n <= 1) {
            return mb_strtoupper(mb_substr($fullName, 0, 1)) . '.';
        }

        if ($n === 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1)) . '. ' . $words[1];
        }

        $last = array_pop($words);
        $parts = [];
        foreach ($words as $w) {
            $parts[] = mb_strtoupper(mb_substr($w, 0, 1)) . '.';
        }

        return implode(' ', $parts) . ' ' . $last;
    }
}
