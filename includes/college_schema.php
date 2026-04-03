<?php
/**
 * Ensures college/professor module tables exist. Idempotent.
 * Requires $conn (mysqli) from db.php.
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

@mysqli_query($conn, "ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','student','college_student','professor_admin') NOT NULL DEFAULT 'student'");

// Optional profile field used by professor "Create college student" UI.
// Adds the column only if it doesn't exist yet (idempotent).
$hasSectionCol = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'section'");
if ($hasSectionCol) {
    $row = mysqli_fetch_assoc($hasSectionCol);
    if (!$row) {
        @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `section` varchar(100) DEFAULT NULL");
    }
    mysqli_free_result($hasSectionCol);
}

$hasStudentNumCol = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'student_number'");
if ($hasStudentNumCol) {
    $rowSn = mysqli_fetch_assoc($hasStudentNumCol);
    if (!$rowSn) {
        @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `student_number` varchar(32) DEFAULT NULL COMMENT 'Official college student ID for reports' AFTER `section`");
        @mysqli_query($conn, "ALTER TABLE `users` ADD KEY `idx_users_student_number` (`student_number` (16))");
    }
    mysqli_free_result($hasStudentNumCol);
}

$stmts = [
    "CREATE TABLE IF NOT EXISTS `college_exams` (
      `exam_id` int(11) NOT NULL AUTO_INCREMENT,
      `title` varchar(255) NOT NULL,
      `description` text DEFAULT NULL,
      `time_limit_seconds` int(11) NOT NULL DEFAULT 3600,
      `available_from` datetime DEFAULT NULL,
      `deadline` datetime DEFAULT NULL,
      `is_published` tinyint(1) NOT NULL DEFAULT 0,
      `created_by` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`exam_id`),
      KEY `idx_college_exams_published` (`is_published`),
      KEY `idx_college_exams_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `college_exam_questions` (
      `question_id` int(11) NOT NULL AUTO_INCREMENT,
      `exam_id` int(11) NOT NULL,
      `question_text` text NOT NULL,
      `question_type` varchar(16) NOT NULL DEFAULT 'mcq',
      `choice_a` text DEFAULT NULL,
      `choice_b` text DEFAULT NULL,
      `choice_c` text DEFAULT NULL,
      `choice_d` text DEFAULT NULL,
      `correct_answer` varchar(1) NOT NULL,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (`question_id`),
      KEY `idx_college_q_exam` (`exam_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `college_exam_attempts` (
      `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
      `exam_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `status` enum('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
      `score` decimal(5,2) DEFAULT NULL,
      `correct_count` int(11) DEFAULT NULL,
      `total_count` int(11) DEFAULT NULL,
      `started_at` datetime NOT NULL,
      `expires_at` datetime DEFAULT NULL,
      `submitted_at` datetime DEFAULT NULL,
      PRIMARY KEY (`attempt_id`),
      UNIQUE KEY `uq_college_attempt_user_exam` (`user_id`,`exam_id`),
      KEY `idx_college_attempt_exam` (`exam_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `college_exam_answers` (
      `answer_id` int(11) NOT NULL AUTO_INCREMENT,
      `attempt_id` int(11) NOT NULL,
      `question_id` int(11) NOT NULL,
      `selected_answer` varchar(1) DEFAULT NULL,
      `is_correct` tinyint(1) DEFAULT NULL,
      `answered_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`answer_id`),
      UNIQUE KEY `uq_college_answer_attempt_q` (`attempt_id`,`question_id`),
      KEY `idx_college_ans_q` (`question_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `college_upload_tasks` (
      `task_id` int(11) NOT NULL AUTO_INCREMENT,
      `title` varchar(255) NOT NULL,
      `instructions` text DEFAULT NULL,
      `deadline` datetime NOT NULL,
      `max_file_size` int(11) NOT NULL DEFAULT 10485760,
      `allowed_extensions` varchar(255) NOT NULL DEFAULT 'pdf,jpg,jpeg,png',
      `is_open` tinyint(1) NOT NULL DEFAULT 1,
      `created_by` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`task_id`),
      KEY `idx_college_upload_deadline` (`deadline`),
      KEY `idx_college_upload_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `college_submissions` (
      `submission_id` int(11) NOT NULL AUTO_INCREMENT,
      `task_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `file_path` varchar(512) NOT NULL DEFAULT '',
      `file_name` varchar(255) NOT NULL DEFAULT '',
      `file_size` bigint(20) DEFAULT NULL,
      `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
      `status` varchar(32) NOT NULL DEFAULT 'submitted',
      PRIMARY KEY (`submission_id`),
      UNIQUE KEY `uq_college_submission_task_user` (`task_id`,`user_id`),
      KEY `idx_college_sub_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($stmts as $sql) {
    @mysqli_query($conn, $sql);
}

// Legacy installs: table may have been created before is_published existed.
$chkPub = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exams` LIKE 'is_published'");
if ($chkPub) {
    $pubRow = mysqli_fetch_assoc($chkPub);
    mysqli_free_result($chkPub);
    if (!$pubRow) {
        @mysqli_query($conn, "ALTER TABLE `college_exams` ADD COLUMN `is_published` tinyint(1) NOT NULL DEFAULT 0");
        @mysqli_query($conn, "ALTER TABLE `college_exams` ADD KEY `idx_college_exams_published` (`is_published`)");
    }
}

// Legacy installs: ensure updated_at exists (used for ordering / tooling).
$chkUpd = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exams` LIKE 'updated_at'");
if ($chkUpd) {
    $updRow = mysqli_fetch_assoc($chkUpd);
    mysqli_free_result($chkUpd);
    if (!$updRow) {
        @mysqli_query($conn, "ALTER TABLE `college_exams` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()");
    }
}

$collegeExamExtraCols = [
    'shuffle_questions' => "ALTER TABLE `college_exams` ADD COLUMN `shuffle_questions` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Randomize question order per attempt'",
    'shuffle_choices' => "ALTER TABLE `college_exams` ADD COLUMN `shuffle_choices` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Randomize A-D per question per attempt'",
    'shuffle_mcq_questions' => "ALTER TABLE `college_exams` ADD COLUMN `shuffle_mcq_questions` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Randomize MCQ order per attempt'",
    'shuffle_tf_questions' => "ALTER TABLE `college_exams` ADD COLUMN `shuffle_tf_questions` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Randomize True/False order per attempt'",
    'description_markdown' => "ALTER TABLE `college_exams` ADD COLUMN `description_markdown` tinyint(1) NOT NULL DEFAULT 0",
];
foreach ($collegeExamExtraCols as $col => $alterSql) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exams` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    if ($chk) {
        $row = mysqli_fetch_assoc($chk);
        mysqli_free_result($chk);
        if (!$row) {
            @mysqli_query($conn, $alterSql);
        }
    }
}

$collegeExamReviewCols = [
    'review_sheet_available_from' => "ALTER TABLE `college_exams` ADD COLUMN `review_sheet_available_from` datetime DEFAULT NULL COMMENT 'When students may open full question review; NULL = locked'",
    'review_sheet_available_until' => "ALTER TABLE `college_exams` ADD COLUMN `review_sheet_available_until` datetime DEFAULT NULL COMMENT 'Optional end of review window; NULL = no end'",
];
foreach ($collegeExamReviewCols as $col => $alterSql) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exams` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    if ($chk) {
        $row = mysqli_fetch_assoc($chk);
        mysqli_free_result($chk);
        if (!$row) {
            @mysqli_query($conn, $alterSql);
        }
    }
}

$collegeQuestionExtraCols = [
    'question_type' => "ALTER TABLE `college_exam_questions` ADD COLUMN `question_type` varchar(16) NOT NULL DEFAULT 'mcq' COMMENT 'mcq|tf' AFTER `question_text`",
];
foreach ($collegeQuestionExtraCols as $col => $alterSql) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exam_questions` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    if ($chk) {
        $row = mysqli_fetch_assoc($chk);
        mysqli_free_result($chk);
        if (!$row) {
            @mysqli_query($conn, $alterSql);
        }
    }
}

$collegeAttemptExtraCols = [
    'ui_state_json' => "ALTER TABLE `college_exam_attempts` ADD COLUMN `ui_state_json` LONGTEXT NULL COMMENT 'Navigator state: current question index + flagged question ids'",
    'last_seen_at' => "ALTER TABLE `college_exam_attempts` ADD COLUMN `last_seen_at` datetime NULL COMMENT 'Heartbeat timestamp from active attempt page'",
];
foreach ($collegeAttemptExtraCols as $col => $alterSql) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exam_attempts` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    if ($chk) {
        $row = mysqli_fetch_assoc($chk);
        mysqli_free_result($chk);
        if (!$row) {
            @mysqli_query($conn, $alterSql);
        }
    }
}

$collegeAttemptSecurityCols = [
    'tab_switch_count' => "ALTER TABLE `college_exam_attempts` ADD COLUMN `tab_switch_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Times student left exam tab'",
    'last_tab_switch_at' => "ALTER TABLE `college_exam_attempts` ADD COLUMN `last_tab_switch_at` datetime DEFAULT NULL COMMENT 'Last visibility blur timestamp'",
    'exam_session_lock' => "ALTER TABLE `college_exam_attempts` ADD COLUMN `exam_session_lock` varchar(64) DEFAULT NULL COMMENT 'HttpOnly cookie token; blocks second-device login while in progress'",
];
foreach ($collegeAttemptSecurityCols as $col => $alterSql) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `college_exam_attempts` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    if ($chk) {
        $row = mysqli_fetch_assoc($chk);
        mysqli_free_result($chk);
        if (!$row) {
            @mysqli_query($conn, $alterSql);
        }
    }
}
