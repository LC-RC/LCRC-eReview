<?php
/**
 * One-off environment check for quiz HTTP 500 debugging.
 *
 * 1) Edit $QUIZ_DEBUG_KEY below to a long random string.
 * 2) Open: https://yoursite.com/quiz_env_check.php?key=YOUR_KEY
 * 3) Delete this file when done (security).
 */
$QUIZ_DEBUG_KEY = 'change-this-to-a-long-random-string-before-use';

if (($_GET['key'] ?? '') !== $QUIZ_DEBUG_KEY || $QUIZ_DEBUG_KEY === 'change-this-to-a-long-random-string-before-use') {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "PHP " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n\n";

foreach (['mbstring', 'dom', 'mysqli', 'json', 'session', 'libxml'] as $ext) {
    echo str_pad($ext, 12) . (extension_loaded($ext) ? 'yes' : 'NO') . "\n";
}

echo "\n--- mysqli_report mode ---\n";
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
    echo "mysqli_report callable: yes\n";
} else {
    echo "mysqli_report: n/a\n";
}

echo "\n--- quiz_helpers renderQuizRichText ---\n";
require_once __DIR__ . '/includes/quiz_helpers.php';
try {
    $out = renderQuizRichText('<p>Test <strong>bold</strong></p>');
    echo "OK, length=" . strlen($out) . "\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n--- format_display_name ---\n";
require_once __DIR__ . '/includes/format_display_name.php';
try {
    echo ereview_format_topbar_display_name('Sample Student Name') . "\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
