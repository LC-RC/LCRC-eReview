<?php
/**
 * Favorites retired from My CPA Review workspace — Bookmarks cover “return later”.
 * Lesson Favorites were replaced by Important (concept) quick action.
 */
require_once 'auth.php';
requireRole('student');
header('Location: student_cpa_bookmarks');
exit;
