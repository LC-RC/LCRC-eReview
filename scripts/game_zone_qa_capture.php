<?php
require_once __DIR__ . '/../db.php';
session_start();
$_SESSION['user_id'] = (int) ($argv[1] ?? 2);
$_SESSION['role'] = 'student';
$_SESSION['full_name'] = 'Sample Student QA';
chdir(dirname(__DIR__));
$page = $argv[2] ?? 'career';
$map = [
    'play' => 'student_playground.php',
    'career' => 'student_playground_career.php',
    'compete' => 'student_playground_leaderboard.php',
];
$scriptFile = $map[$page] ?? $map['career'];
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/' . $scriptFile;
$_SERVER['PHP_SELF'] = '/' . $scriptFile;
ob_start();
include $scriptFile;
$html = ob_get_clean();
file_put_contents(__DIR__ . '/game_zone_qa_capture.html', $html);
echo strlen($html) . " bytes written\n";
echo 'nav count: ' . substr_count($html, '<nav class="pg-zone-nav"') . "\n";
echo 'sidebar Career Progress link: ' . (preg_match('/student-nav-item[^>]*>[\s\S]*?Career Progress/s', $html) ? 'YES' : 'NO') . "\n";
