<?php
/**
 * admin_materials_diagnose.php — TEMPORARY ONE-OFF SERVER DIAGNOSTIC
 *
 * Chrome DevTools Console CANNOT show PHP errors. A 500 means the server crashed
 * before sending HTML; the Console only shows net::ERR_HTTP_RESPONSE_CODE_FAILURE.
 *
 * How to use:
 * 1. Edit DIAG_TOKEN below to a long random secret (not the placeholder).
 * 2. Upload this file next to admin_materials.php on the VPS.
 * 3. In the SAME browser where you are logged in as admin, open:
 *    https://lcrc-ereview.com/admin_materials_diagnose.php?token=YOUR_SECRET&lesson_id=37
 * 4. Read the plain-text output on the page (or use curl — see bottom of this file).
 * 5. DELETE this file from the server when done (anyone with the URL can run it).
 */

declare(strict_types=1);

const DIAG_TOKEN = 'CHANGE-ME-to-a-long-random-secret-string';

header('Content-Type: text/plain; charset=utf-8');

if (DIAG_TOKEN === 'CHANGE-ME-to-a-long-random-secret-string' || ($_GET['token'] ?? '') !== DIAG_TOKEN) {
    http_response_code(403);
    echo "403 Forbidden.\n\n";
    echo "Edit DIAG_TOKEN in admin_materials_diagnose.php on the server, then call:\n";
    echo "  ?token=YOUR_SECRET&lesson_id=37\n";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('html_errors', '0');

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    echo "\n\n========== FATAL (shutdown) ==========\n";
    echo $err['message'] . "\n";
    echo $err['file'] . ':' . $err['line'] . "\n";
});

$lessonId = max(1, (int)($_GET['lesson_id'] ?? 37));

echo "=== LCRC eReview — admin_materials pipeline diagnose ===\n\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n";
echo "lesson_id: {$lessonId}\n\n";

echo "--- Extensions ---\n";
echo 'mysqli: ' . (extension_loaded('mysqli') ? 'yes' : 'NO') . "\n";
echo 'curl: ' . (extension_loaded('curl') ? 'yes' : 'no') . "\n";
echo 'json: ' . (extension_loaded('json') ? 'yes' : 'no') . "\n\n";

echo "--- [1] db.php ---\n";
require_once __DIR__ . '/db.php';
/** @var mysqli $conn */
global $conn;
if (empty($conn) || !($conn instanceof mysqli)) {
    echo "FAIL: \$conn is not a mysqli instance.\n";
    exit(1);
}
echo 'OK: connected — ' . mysqli_get_host_info($conn) . "\n\n";

echo "--- [2] Lesson + subject (same SQL as admin_materials.php) ---\n";
$lessonSql = 'SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=' . (int)$lessonId . ' LIMIT 1';
$lessonRes = mysqli_query($conn, $lessonSql);
if (!$lessonRes) {
    echo 'FAIL: ' . mysqli_error($conn) . "\n";
} else {
    $lesson = mysqli_fetch_assoc($lessonRes);
    if (!$lesson) {
        echo "No row for this lesson_id (admin_materials would redirect away).\n";
    } else {
        echo 'OK: lesson title=' . ($lesson['title'] ?? '(no title key)') . ', subject_id=' . ($lesson['subject_id'] ?? '?') . "\n";
    }
}

echo "\n--- [3] ALTER lesson_videos thumbnail columns (same as admin_materials; dup column = OK) ---\n";
foreach (
    [
        'ALTER TABLE lesson_videos ADD COLUMN thumbnail_url TEXT NULL AFTER video_url',
        'ALTER TABLE lesson_videos ADD COLUMN thumbnail_source VARCHAR(32) NULL DEFAULT NULL AFTER thumbnail_url',
        'ALTER TABLE lesson_videos ADD COLUMN thumbnail_updated_at DATETIME NULL DEFAULT NULL AFTER thumbnail_source',
    ] as $alterSql
) {
    if (@mysqli_query($conn, $alterSql)) {
        echo "OK: applied one ALTER\n";
    } else {
        $e = mysqli_error($conn);
        echo 'mysqli: ' . ($e !== '' ? $e : '(empty)') . "\n";
    }
}

echo "\n--- [4] lesson_videos / lesson_handouts SELECT ---\n";
$vq = mysqli_query($conn, 'SELECT * FROM lesson_videos WHERE lesson_id=' . (int)$lessonId . ' ORDER BY video_id DESC LIMIT 5');
if (!$vq) {
    echo 'lesson_videos FAIL: ' . mysqli_error($conn) . "\n";
} else {
    echo 'lesson_videos OK, rows (sample max 5): ' . mysqli_num_rows($vq) . "\n";
}
$hq = mysqli_query($conn, 'SELECT * FROM lesson_handouts WHERE lesson_id=' . (int)$lessonId . ' ORDER BY handout_id DESC LIMIT 5');
if (!$hq) {
    echo 'lesson_handouts FAIL: ' . mysqli_error($conn) . "\n";
} else {
    echo 'lesson_handouts OK, rows (sample max 5): ' . mysqli_num_rows($hq) . "\n";
}

echo "\n--- [5] includes/vimeo_helpers.php ---\n";
require_once __DIR__ . '/includes/vimeo_helpers.php';
echo "OK: loaded\n";

echo "\n--- [6] auth.php + admin role ---\n";
require_once __DIR__ . '/auth.php';
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    echo "WARN: Not logged in in this session. Log in as admin in this browser, then reload this diagnose URL.\n";
    echo "      (Steps 7–8 test the same includes as the real page and need an admin session.)\n\n";
} else {
    $role = (string)($_SESSION['role'] ?? '');
    echo "Logged in. role={$role}\n";
    if ($role !== 'admin') {
        echo "WARN: Not admin — step 7 may not match production failure.\n\n";
    }
}

echo "--- [7] includes/head_admin.php (needs \$pageTitle, h() from auth) ---\n";
$pageTitle = 'Diagnose';
ob_start();
try {
    require_once __DIR__ . '/includes/head_admin.php';
    ob_end_clean();
    echo "OK: head_admin included\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo 'FAIL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\n--- [8] admin_sidebar.php (full shell: sidebar + main open + topbar + messaging + notifications) ---\n";
try {
    ob_start();
    require __DIR__ . '/admin_sidebar.php';
    ob_end_clean();
    echo "OK: admin_sidebar included (if this fails on real admin_materials, error is likely here)\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo 'FAIL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\n=== End diagnose ===\n";
echo "\nIf admin_materials.php still returns 500 but this script reaches [8] OK, the fault is in admin_materials.php body after the sidebar include.\n";
echo "Check VPS error log, e.g.: tail -n 80 /var/log/apache2/error.log\n";
echo "Or: journalctl -u php*-fpm -n 50\n";

/*
 * curl (from your PC, after setting DIAG_TOKEN on server):
 *
 * curl -sS "https://lcrc-ereview.com/admin_materials_diagnose.php?token=YOUR_SECRET&lesson_id=37"
 *
 * (Add -b "PHPSESSID=..." copied from browser if steps 7–8 need login.)
 */
