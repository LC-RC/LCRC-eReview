<?php
declare(strict_types=1);

/**
 * Shared LMS premium floating toasts (Professor Admin allowlisted pages only).
 * Does not change session flash assignment; only how consumed messages are shown.
 */

require_once __DIR__ . '/lms_premium_optin.php';

if (!function_exists('ereview_lms_toast_queue')) {
    /**
     * @return list<array{type:string,title:string,detail:string,sticky:bool,ttl:int}>
     */
    function &ereview_lms_toast_queue(): array
    {
        if (!isset($GLOBALS['ereview_lms_toast_queue']) || !is_array($GLOBALS['ereview_lms_toast_queue'])) {
            $GLOBALS['ereview_lms_toast_queue'] = [];
        }
        /** @var list<array{type:string,title:string,detail:string,sticky:bool,ttl:int}> $q */
        $q = &$GLOBALS['ereview_lms_toast_queue'];

        return $q;
    }
}

if (!function_exists('ereview_lms_toast_normalize_type')) {
    function ereview_lms_toast_normalize_type(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
    }
}

if (!function_exists('ereview_lms_toast_push')) {
    /**
     * Queue a toast when LMS premium is active. Returns true when the message
     * should not also be rendered as an inline admin-flash.
     */
    function ereview_lms_toast_push(string $type, ?string $message, ?string $title = null, ?bool $sticky = null, ?int $ttlMs = null): bool
    {
        $message = trim((string) $message);
        if ($message === '' || !ereview_lms_premium_should_load()) {
            return false;
        }
        $type = ereview_lms_toast_normalize_type($type);
        $defaults = [
            'success' => 'Success',
            'error' => 'Action needed',
            'warning' => 'Warning',
            'info' => 'Notice',
        ];
        $title = trim((string) $title);
        $detail = '';
        if ($title === '') {
            if (function_exists('mb_strlen') ? mb_strlen($message) <= 72 : strlen($message) <= 72) {
                $title = $message;
            } else {
                $title = $defaults[$type];
                $detail = $message;
            }
        } else {
            $detail = $message;
        }
        if ($sticky === null) {
            $sticky = ($type === 'error');
        }
        if ($ttlMs === null) {
            $ttlMs = $type === 'warning' ? 6000 : 4500;
        }
        $queue = &ereview_lms_toast_queue();
        $queue[] = [
            'type' => $type,
            'title' => $title,
            'detail' => $detail,
            'sticky' => (bool) $sticky,
            'ttl' => max(1500, (int) $ttlMs),
        ];

        return true;
    }
}

if (!function_exists('ereview_lms_toast_take')) {
    /** @see ereview_lms_toast_push */
    function ereview_lms_toast_take(string $type, ?string $message, ?string $title = null, ?bool $sticky = null): bool
    {
        return ereview_lms_toast_push($type, $message, $title, $sticky);
    }
}

if (!function_exists('ereview_lms_toast_boot')) {
    function ereview_lms_toast_boot(): void
    {
        static $booted = false;
        if ($booted || !ereview_lms_premium_should_load()) {
            return;
        }
        $booted = true;
        $items = ereview_lms_toast_queue();
        echo '<div id="lmsToastRoot" class="lms-toast-stack" data-lms-toast-root="1" aria-live="polite" aria-relevant="additions" aria-atomic="false"></div>' . "\n";
        echo '<script type="application/json" id="lmsToastSeed">';
        echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '</script>' . "\n";
    }
}
