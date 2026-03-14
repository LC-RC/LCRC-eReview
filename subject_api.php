<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
	header('Content-Type: application/json');
	echo json_encode(['error' => 'unauthorized']);
	exit;
}
require 'db.php';

$action = $_GET['action'] ?? '';
header('Content-Type: application/json');

if ($action === 'videos') {
	$lessonId = (int)($_GET['lesson_id'] ?? 0);
	if ($lessonId <= 0) { echo json_encode(['videos' => []]); exit; }
	$lr = mysqli_query($conn, "SELECT lesson_id, title FROM lessons WHERE lesson_id=".$lessonId." LIMIT 1");
	$lesson = $lr ? mysqli_fetch_assoc($lr) : null;
	$res = mysqli_query($conn, "SELECT video_id, video_title, video_url FROM lesson_videos WHERE lesson_id=".$lessonId." ORDER BY video_id ASC");
	$rows = [];
	while ($res && ($row = mysqli_fetch_assoc($res))) {
		$rows[] = $row;
	}
	echo json_encode(['lesson' => $lesson, 'videos' => $rows]);
	exit;
}

if ($action === 'handouts') {
	$lessonId = (int)($_GET['lesson_id'] ?? 0);
	if ($lessonId <= 0) { echo json_encode(['handouts' => []]); exit; }
	$lr = mysqli_query($conn, "SELECT lesson_id, title FROM lessons WHERE lesson_id=".$lessonId." LIMIT 1");
	$lesson = $lr ? mysqli_fetch_assoc($lr) : null;
	$res = mysqli_query($conn, "SELECT handout_id, handout_title, file_path, file_name, file_size, allow_download, uploaded_at FROM lesson_handouts WHERE lesson_id=".$lessonId." ORDER BY handout_id DESC");
	$rows = [];
	while ($res && ($row = mysqli_fetch_assoc($res))) {
		$rows[] = $row;
	}
	echo json_encode(['lesson' => $lesson, 'handouts' => $rows]);
	exit;
}

echo json_encode(['error' => 'unknown_action']);
exit;

