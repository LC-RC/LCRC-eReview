<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground.php';
require_once __DIR__ . '/includes/student_gamification.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_enforce_enabled($conn);

$userId = (int) getCurrentUserId();
$careerReady = student_gamification_tables_ready($conn);
$career = $careerReady ? student_gamification_career_summary($conn, $userId) : null;
$xpHistory = $careerReady ? student_gamification_list_events($conn, $userId, 30) : [];
$achievements = $careerReady ? student_gamification_achievement_gallery($conn, $userId) : [];
$pgZoneNavActive = 'career';
$pageTitle = 'CPA Playground · Career';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require __DIR__ . '/includes/components/playground_styles.php'; ?>
</head>
<body class="font-sans antialiased pg-theme pg-lobby-mode">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page pg-page pg-zone-page min-h-full">
    <?php require __DIR__ . '/includes/components/playground_zone_header.php'; ?>
    <?php require __DIR__ . '/includes/components/playground_career_section.php'; ?>
  </div>
</body>
</html>
