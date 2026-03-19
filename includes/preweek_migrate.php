<?php
/**
 * Preweek module schema bootstrap.
 * Creates tables if they don't exist (safe to call on every request).
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preweek_videos (
  preweek_video_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL DEFAULT 0,
  video_title VARCHAR(255) NOT NULL,
  video_url TEXT NOT NULL,
  upload_type ENUM('url','file') NOT NULL DEFAULT 'url',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preweek_videos_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preweek_handouts (
  preweek_handout_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL DEFAULT 0,
  handout_title VARCHAR(255) NOT NULL,
  file_path TEXT NOT NULL,
  file_name VARCHAR(255) NULL,
  file_size BIGINT NULL,
  allow_download TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preweek_handouts_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Safe migration for older installs (add subject_id + indexes if missing)
$col = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_videos LIKE 'subject_id'");
if ($col && !mysqli_fetch_assoc($col)) {
    @mysqli_query($conn, "ALTER TABLE preweek_videos ADD COLUMN subject_id INT NOT NULL DEFAULT 0 AFTER preweek_video_id");
    @mysqli_query($conn, "ALTER TABLE preweek_videos ADD KEY idx_preweek_videos_subject (subject_id)");
}
$col = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_handouts LIKE 'subject_id'");
if ($col && !mysqli_fetch_assoc($col)) {
    @mysqli_query($conn, "ALTER TABLE preweek_handouts ADD COLUMN subject_id INT NOT NULL DEFAULT 0 AFTER preweek_handout_id");
    @mysqli_query($conn, "ALTER TABLE preweek_handouts ADD KEY idx_preweek_handouts_subject (subject_id)");
}

