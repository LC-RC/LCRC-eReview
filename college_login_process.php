<?php
/**
 * Shared login: College Examination uses the same original eReview login page
 * and login_process.php. This endpoint remains so old form posts still work.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: login');
    exit;
}

require __DIR__ . '/login_process.php';
