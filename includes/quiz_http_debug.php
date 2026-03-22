<?php
/**
 * Logs PHP fatal errors (parse, core, compile) to help debug HTTP 500 on quiz pages.
 * Safe to include at the top of student_take_quiz.php / quiz_ajax.php.
 *
 * Log file: uploads/quiz_http_errors.log (created if uploads/ is writable).
 */
if (!defined('EREVIEW_QUIZ_HTTP_DEBUG_REGISTERED')) {
    define('EREVIEW_QUIZ_HTTP_DEBUG_REGISTERED', true);
    register_shutdown_function(static function () {
        $e = error_get_last();
        if (!$e || !in_array((int)($e['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $line = date('c')
            . ' type=' . ($e['type'] ?? '')
            . ' msg=' . ($e['message'] ?? '')
            . ' file=' . ($e['file'] ?? '')
            . ' line=' . ($e['line'] ?? '')
            . "\n";
        $base = dirname(__DIR__);
        $log = $base . '/uploads/quiz_http_errors.log';
        $dir = dirname($log);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_writable($dir) || (file_exists($log) && is_writable($log))) {
            @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
        } else {
            @error_log('[ereview quiz fatal] ' . trim($line));
        }
    });
}
