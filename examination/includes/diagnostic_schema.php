<?php
/**
 * Ensures Diagnostic Exam tables exist. Idempotent — isolated from college_schema.
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

$stmts = [
    "CREATE TABLE IF NOT EXISTS `diagnostic_subjects` (
      `subject_id` int(11) NOT NULL AUTO_INCREMENT,
      `subject_code` varchar(32) NOT NULL,
      `subject_name` varchar(255) NOT NULL,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (`subject_id`),
      UNIQUE KEY `uq_diagnostic_subject_code` (`subject_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `diagnostic_batches` (
      `batch_id` int(11) NOT NULL AUTO_INCREMENT,
      `title` varchar(255) NOT NULL,
      `description` text DEFAULT NULL,
      `time_limit_seconds` int(11) NOT NULL DEFAULT 3600,
      `available_from` datetime DEFAULT NULL,
      `deadline` datetime DEFAULT NULL,
      `is_published` tinyint(1) NOT NULL DEFAULT 0,
      `shuffle_questions` tinyint(1) NOT NULL DEFAULT 0,
      `shuffle_choices` tinyint(1) NOT NULL DEFAULT 0,
      `created_by` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`batch_id`),
      KEY `idx_diagnostic_batches_published` (`is_published`),
      KEY `idx_diagnostic_batches_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `diagnostic_batch_subjects` (
      `batch_subject_id` int(11) NOT NULL AUTO_INCREMENT,
      `batch_id` int(11) NOT NULL,
      `subject_id` int(11) NOT NULL,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `questions_required` int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (`batch_subject_id`),
      UNIQUE KEY `uq_diagnostic_batch_subject` (`batch_id`,`subject_id`),
      KEY `idx_dbs_batch` (`batch_id`),
      KEY `idx_dbs_subject` (`subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `diagnostic_batch_sections` (
      `batch_section_id` int(11) NOT NULL AUTO_INCREMENT,
      `batch_id` int(11) NOT NULL,
      `section_value` varchar(100) NOT NULL,
      PRIMARY KEY (`batch_section_id`),
      UNIQUE KEY `uq_diagnostic_batch_section` (`batch_id`,`section_value`),
      KEY `idx_dbsct_batch` (`batch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `diagnostic_questions` (
      `question_id` int(11) NOT NULL AUTO_INCREMENT,
      `batch_id` int(11) NOT NULL,
      `subject_id` int(11) NOT NULL,
      `question_text` text NOT NULL,
      `question_type` varchar(16) NOT NULL DEFAULT 'mcq',
      `choice_a` text DEFAULT NULL,
      `choice_b` text DEFAULT NULL,
      `choice_c` text DEFAULT NULL,
      `choice_d` text DEFAULT NULL,
      `correct_answer` varchar(1) NOT NULL,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (`question_id`),
      KEY `idx_dq_batch_subject` (`batch_id`,`subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `diagnostic_attempts` (
      `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
      `batch_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `status` enum('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
      `score` decimal(5,2) DEFAULT NULL,
      `correct_count` int(11) DEFAULT NULL,
      `total_count` int(11) DEFAULT NULL,
      `subject_breakdown_json` longtext DEFAULT NULL,
      `started_at` datetime NOT NULL,
      `expires_at` datetime DEFAULT NULL,
      `submitted_at` datetime DEFAULT NULL,
      `ui_state_json` longtext DEFAULT NULL,
      `last_seen_at` datetime DEFAULT NULL,
      `tab_switch_count` int(11) NOT NULL DEFAULT 0,
      `last_tab_switch_at` datetime DEFAULT NULL,
      PRIMARY KEY (`attempt_id`),
      UNIQUE KEY `uq_diagnostic_attempt_user_batch` (`user_id`,`batch_id`),
      KEY `idx_da_batch` (`batch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `diagnostic_answers` (
      `answer_id` int(11) NOT NULL AUTO_INCREMENT,
      `attempt_id` int(11) NOT NULL,
      `question_id` int(11) NOT NULL,
      `selected_answer` varchar(1) DEFAULT NULL,
      `is_correct` tinyint(1) DEFAULT NULL,
      `answered_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`answer_id`),
      UNIQUE KEY `uq_diagnostic_answer_attempt_q` (`attempt_id`,`question_id`),
      KEY `idx_dans_q` (`question_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($stmts as $sql) {
    @mysqli_query($conn, $sql);
}

$hasReviewTypeCol = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'review_type'");
if ($hasReviewTypeCol) {
    $rowRt = mysqli_fetch_assoc($hasReviewTypeCol);
    if (!$rowRt) {
        @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `review_type` enum('reviewee','undergrad') NOT NULL DEFAULT 'reviewee' AFTER `full_name`");
    }
    mysqli_free_result($hasReviewTypeCol);
}

$alterChecks = [
    'exam_type' => "ALTER TABLE `diagnostic_batches` ADD COLUMN `exam_type` ENUM('diagnostic') NOT NULL DEFAULT 'diagnostic' AFTER `batch_id`",
    'examinee_scope' => "ALTER TABLE `diagnostic_batches` ADD COLUMN `examinee_scope` ENUM('college_student','reviewee','both') NOT NULL DEFAULT 'college_student' AFTER `exam_type`",
    'assignment_mode' => "ALTER TABLE `diagnostic_batches` ADD COLUMN `assignment_mode` ENUM('all','sections','users','sections_and_users') NOT NULL DEFAULT 'sections' AFTER `examinee_scope`",
];
foreach ($alterChecks as $col => $sql) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM diagnostic_batches LIKE '{$col}'");
    if (!$chk || !mysqli_fetch_assoc($chk)) {
        @mysqli_query($conn, $sql);
    }
    if ($chk) {
        mysqli_free_result($chk);
    }
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `diagnostic_batch_users` (
  `batch_user_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`batch_user_id`),
  UNIQUE KEY `uq_diagnostic_batch_user` (`batch_id`,`user_id`),
  KEY `idx_dbu_batch` (`batch_id`),
  KEY `idx_dbu_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$seeds = [
    ['FAR', 'Financial Accounting and Reporting', 1],
    ['AFAR', 'Advanced Financial Accounting and Reporting', 2],
    ['AUD', 'Auditing', 3],
    ['TAX', 'Taxation', 4],
    ['MAS', 'Management Advisory Services', 5],
    ['RFBT', 'Regulatory Framework for Business Transactions', 6],
    ['MS', 'Management Services', 7],
];
foreach ($seeds as [$code, $name, $ord]) {
    $cEsc = mysqli_real_escape_string($conn, $code);
    $nEsc = mysqli_real_escape_string($conn, $name);
    @mysqli_query($conn, "INSERT IGNORE INTO diagnostic_subjects (subject_code, subject_name, sort_order, is_active) VALUES ('{$cEsc}', '{$nEsc}', " . (int)$ord . ", 1)");
}
