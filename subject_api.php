<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
	echo json_encode(['error' => 'unauthorized']);
	exit;
}

$userId = (int) (getCurrentUserId() ?? 0);
if ($userId <= 0 || !sca_account_access_active($conn, $userId)) {
	http_response_code(403);
	echo json_encode(['error' => 'access_denied']);
	exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'videos') {
	$lessonId = (int)($_GET['lesson_id'] ?? 0);
	if ($lessonId <= 0) { echo json_encode(['videos' => []]); exit; }
	if (!sca_has_access($conn, $userId, 'lesson', $lessonId)) {
		http_response_code(403);
		echo json_encode(['error' => 'access_denied', 'videos' => []]);
		exit;
	}
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
	if (!sca_has_access($conn, $userId, 'lesson', $lessonId)) {
		http_response_code(403);
		echo json_encode(['error' => 'access_denied', 'handouts' => []]);
		exit;
	}
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

