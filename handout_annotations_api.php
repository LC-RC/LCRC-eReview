<?php
require_once 'auth.php';
requireRole('student');

header('Content-Type: application/json');

$studentId = getCurrentUserId();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
header('Content-Type: application/json');

if ($action === 'save') {
    $handoutId = (int)($_POST['handout_id'] ?? 0);
    $annotationType = trim($_POST['annotation_type'] ?? 'text');
    $pageNumber = (int)($_POST['page_number'] ?? 1);
    $x = floatval($_POST['x'] ?? 0);
    $y = floatval($_POST['y'] ?? 0);
    $width = floatval($_POST['width'] ?? 0);
    $height = floatval($_POST['height'] ?? 0);
    $content = $_POST['content'] ?? ''; // Don't trim, preserve SVG paths
    $color = trim($_POST['color'] ?? '#000000');
    $fontSize = (int)($_POST['font_size'] ?? 12);
    
    if ($handoutId <= 0) {
        echo json_encode(['error' => 'Invalid handout ID', 'debug' => ['handout_id' => $handoutId]]);
        exit;
    }
    
    if ($studentId <= 0) {
        echo json_encode(['error' => 'Invalid student ID', 'debug' => ['student_id' => $studentId, 'session' => $_SESSION]]);
        exit;
    }
    
    // Verify handout exists
    $stmt = mysqli_prepare($conn, "SELECT handout_id FROM lesson_handouts WHERE handout_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $handoutId);
    mysqli_stmt_execute($stmt);
    $check = mysqli_stmt_get_result($stmt);
    if (!$check || mysqli_num_rows($check) == 0) {
        mysqli_stmt_close($stmt);
        echo json_encode(['error' => 'Handout not found', 'debug' => ['handout_id' => $handoutId]]);
        exit;
    }
    mysqli_stmt_close($stmt);
    
    // Verify student exists
    $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $studentId);
    mysqli_stmt_execute($stmt);
    $checkStudent = mysqli_stmt_get_result($stmt);
    if (!$checkStudent || mysqli_num_rows($checkStudent) == 0) {
        mysqli_stmt_close($stmt);
        echo json_encode(['error' => 'Student not found', 'debug' => ['student_id' => $studentId]]);
        exit;
    }
    mysqli_stmt_close($stmt);
    
    $stmt = mysqli_prepare($conn, "INSERT INTO handout_annotations (handout_id, student_id, annotation_type, page_number, x, y, width, height, content, color, font_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed', 'debug' => ['mysql_error' => mysqli_error($conn)]]);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, 'iisiddddssi', $handoutId, $studentId, $annotationType, $pageNumber, $x, $y, $width, $height, $content, $color, $fontSize);
    
    if (mysqli_stmt_execute($stmt)) {
        $newId = mysqli_insert_id($conn);
        echo json_encode(['success' => true, 'annotation_id' => $newId]);
    } else {
        $error = mysqli_error($conn);
        echo json_encode(['error' => 'Failed to save annotation', 'debug' => ['mysql_error' => $error, 'content_length' => strlen($content)]]);
    }
    mysqli_stmt_close($stmt);
    exit;
}

if ($action === 'load') {
    $handoutId = (int)($_GET['handout_id'] ?? 0);
    
    if ($handoutId <= 0) {
        echo json_encode(['annotations' => [], 'error' => 'Invalid handout ID']);
        exit;
    }
    
    if ($studentId <= 0) {
        echo json_encode(['annotations' => [], 'error' => 'Invalid student ID']);
        exit;
    }
    
    $stmt = mysqli_prepare($conn, "SELECT annotation_id, annotation_type, page_number, x, y, width, height, content, color, font_size, created_at, updated_at FROM handout_annotations WHERE handout_id=? AND student_id=? ORDER BY page_number ASC, created_at ASC");
    mysqli_stmt_bind_param($stmt, 'ii', $handoutId, $studentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    if (!$res) {
        mysqli_stmt_close($stmt);
        echo json_encode(['annotations' => [], 'error' => 'Query failed', 'debug' => ['mysql_error' => mysqli_error($conn)]]);
        exit;
    }
    
    $annotations = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $annotations[] = $row;
    }
    mysqli_stmt_close($stmt);
    
    echo json_encode(['annotations' => $annotations, 'count' => count($annotations)]);
    exit;
}

if ($action === 'delete') {
    $annotationId = (int)($_POST['annotation_id'] ?? 0);
    
    if ($annotationId <= 0) {
        echo json_encode(['error' => 'Invalid annotation ID']);
        exit;
    }
    
    // Verify ownership
    $check = mysqli_query($conn, "SELECT annotation_id FROM handout_annotations WHERE annotation_id=".$annotationId." AND student_id=".$studentId." LIMIT 1");
    if (!$check || mysqli_num_rows($check) == 0) {
        echo json_encode(['error' => 'Annotation not found or access denied']);
        exit;
    }
    
    mysqli_query($conn, "DELETE FROM handout_annotations WHERE annotation_id=".$annotationId." AND student_id=".$studentId);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'update') {
    $annotationId = (int)($_POST['annotation_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $x = floatval($_POST['x'] ?? 0);
    $y = floatval($_POST['y'] ?? 0);
    $width = floatval($_POST['width'] ?? 0);
    $height = floatval($_POST['height'] ?? 0);
    $color = trim($_POST['color'] ?? '#000000');
    $fontSize = (int)($_POST['font_size'] ?? 12);
    
    if ($annotationId <= 0) {
        echo json_encode(['error' => 'Invalid annotation ID']);
        exit;
    }
    
    // Verify ownership
    $check = mysqli_query($conn, "SELECT annotation_id FROM handout_annotations WHERE annotation_id=".$annotationId." AND student_id=".$studentId." LIMIT 1");
    if (!$check || mysqli_num_rows($check) == 0) {
        echo json_encode(['error' => 'Annotation not found or access denied']);
        exit;
    }
    
    $stmt = mysqli_prepare($conn, "UPDATE handout_annotations SET content=?, x=?, y=?, width=?, height=?, color=?, font_size=? WHERE annotation_id=? AND student_id=?");
    mysqli_stmt_bind_param($stmt, 'sddddssii', $content, $x, $y, $width, $height, $color, $fontSize, $annotationId, $studentId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to update annotation']);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action']);
?>
