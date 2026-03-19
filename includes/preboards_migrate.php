<?php
/**
 * Ensures preboards tables exist. Safe to call on every page load (CREATE TABLE IF NOT EXISTS).
 * Requires $conn (mysqli) to be available.
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

// preboards_subjects already created by admin_preboards_subjects / student_preboards
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_subjects (
  preboards_subject_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_preboards_subject_name (subject_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_sets (
  preboards_set_id INT AUTO_INCREMENT PRIMARY KEY,
  preboards_subject_id INT NOT NULL,
  set_label VARCHAR(10) NOT NULL,
  title VARCHAR(200) DEFAULT NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 0,
  time_limit_seconds INT NOT NULL DEFAULT 3600,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_preboards_set_subject_label (preboards_subject_id, set_label),
  KEY idx_preboards_sets_subject (preboards_subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_questions (
  preboards_question_id INT AUTO_INCREMENT PRIMARY KEY,
  preboards_set_id INT NOT NULL,
  question_text TEXT NOT NULL,
  choice_a TEXT DEFAULT NULL,
  choice_b TEXT DEFAULT NULL,
  choice_c TEXT DEFAULT NULL,
  choice_d TEXT DEFAULT NULL,
  choice_e TEXT DEFAULT NULL,
  choice_f TEXT DEFAULT NULL,
  choice_g TEXT DEFAULT NULL,
  choice_h TEXT DEFAULT NULL,
  choice_i TEXT DEFAULT NULL,
  choice_j TEXT DEFAULT NULL,
  correct_answer VARCHAR(1) NOT NULL,
  explanation TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preboards_questions_set (preboards_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_attempts (
  preboards_attempt_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  preboards_set_id INT NOT NULL,
  attempt_no INT NOT NULL DEFAULT 1,
  status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
  score DECIMAL(5,2) DEFAULT NULL,
  correct_count INT DEFAULT NULL,
  total_count INT DEFAULT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL DEFAULT NULL,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_preboards_attempt_user_set_no (user_id, preboards_set_id, attempt_no),
  KEY idx_preboards_attempts_user (user_id),
  KEY idx_preboards_attempts_set (preboards_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_answers (
  preboards_answer_id INT AUTO_INCREMENT PRIMARY KEY,
  preboards_attempt_id INT NOT NULL,
  preboards_question_id INT NOT NULL,
  selected_answer VARCHAR(1) DEFAULT NULL,
  is_correct TINYINT(1) DEFAULT NULL,
  answered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preboards_answers_attempt (preboards_attempt_id),
  KEY idx_preboards_answers_question (preboards_question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Requests: students can request open access (when set is locked) or request retake (after submitted).
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_requests (
  preboards_request_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  preboards_set_id INT NOT NULL,
  request_type ENUM('open','retake') NOT NULL,
  status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
  note VARCHAR(255) DEFAULT NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at TIMESTAMP NULL DEFAULT NULL,
  decided_by INT NULL DEFAULT NULL,
  KEY idx_preboards_requests_set (preboards_set_id),
  KEY idx_preboards_requests_user (user_id),
  KEY idx_preboards_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Access overrides: if set is locked, approved users can still take it.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_set_access (
  preboards_set_access_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  preboards_set_id INT NOT NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by INT NULL DEFAULT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_preboards_set_access_user_set (user_id, preboards_set_id),
  KEY idx_preboards_set_access_set (preboards_set_id),
  KEY idx_preboards_set_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Retake tokens: one token grants exactly one additional attempt for a set.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_retake_tokens (
  preboards_retake_token_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  preboards_set_id INT NOT NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by INT NULL DEFAULT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_preboards_retake_tokens_set (preboards_set_id),
  KEY idx_preboards_retake_tokens_user (user_id),
  KEY idx_preboards_retake_tokens_used (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Lightweight migrations for existing installs (ignore errors)
$cols = [];
$cr = @mysqli_query($conn, "SHOW COLUMNS FROM preboards_attempts");
if ($cr) { while ($r = mysqli_fetch_assoc($cr)) { $cols[] = $r['Field']; } }
if (!in_array('expires_at', $cols, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_attempts ADD COLUMN expires_at DATETIME NULL DEFAULT NULL AFTER started_at");
}
if (!in_array('attempt_no', $cols, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_attempts ADD COLUMN attempt_no INT NOT NULL DEFAULT 1 AFTER preboards_set_id");
  @mysqli_query($conn, "UPDATE preboards_attempts SET attempt_no=1 WHERE attempt_no IS NULL OR attempt_no=0");
}

$setCols = [];
$sr = @mysqli_query($conn, "SHOW COLUMNS FROM preboards_sets");
if ($sr) { while ($r = mysqli_fetch_assoc($sr)) { $setCols[] = $r['Field']; } }
if (!in_array('is_open', $setCols, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_sets ADD COLUMN is_open TINYINT(1) NOT NULL DEFAULT 0 AFTER title");
}

$accCols = [];
$acr = @mysqli_query($conn, "SHOW COLUMNS FROM preboards_set_access");
if ($acr) { while ($r = mysqli_fetch_assoc($acr)) { $accCols[] = $r['Field']; } }
if (!in_array('used_at', $accCols, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_set_access ADD COLUMN used_at TIMESTAMP NULL DEFAULT NULL AFTER granted_by");
}
if (!in_array('revoked_at', $accCols, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_set_access ADD COLUMN revoked_at TIMESTAMP NULL DEFAULT NULL AFTER used_at");
}

// Replace old unique index (user_id, preboards_set_id) with (user_id, preboards_set_id, attempt_no)
$attemptIdx = [];
$ir = @mysqli_query($conn, "SHOW INDEX FROM preboards_attempts");
if ($ir) { while ($r = mysqli_fetch_assoc($ir)) { if (!empty($r['Key_name'])) $attemptIdx[] = $r['Key_name']; } }
if (in_array('uq_preboards_attempt_user_set', $attemptIdx, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_attempts DROP INDEX uq_preboards_attempt_user_set");
}
if (!in_array('uq_preboards_attempt_user_set_no', $attemptIdx, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_attempts ADD UNIQUE KEY uq_preboards_attempt_user_set_no (user_id, preboards_set_id, attempt_no)");
}

// Add unique constraint only if missing (prevents duplicate key name fatal)
$idxNames = [];
$ir = @mysqli_query($conn, "SHOW INDEX FROM preboards_answers");
if ($ir) {
  while ($r = mysqli_fetch_assoc($ir)) {
    if (!empty($r['Key_name'])) $idxNames[] = $r['Key_name'];
  }
}
if (!in_array('uq_preboards_answer_attempt_question', $idxNames, true)) {
  @mysqli_query($conn, "ALTER TABLE preboards_answers ADD UNIQUE KEY uq_preboards_answer_attempt_question (preboards_attempt_id, preboards_question_id)");
}
