<?php
require_once 'auth.php';
requireRole('student');

$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'student_playground_leaderboard' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $target, true, 302);
exit;
