-- CPA Battle multiplayer tables (server-authoritative).
-- Prefer app ensure_schema(); apply manually if needed.

CREATE TABLE IF NOT EXISTS `student_playground_games` (
  `game_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_code` CHAR(5) NOT NULL,
  `host_user_id` INT(11) NOT NULL,
  `title` VARCHAR(120) NOT NULL DEFAULT '',
  `status` ENUM('lobby','countdown','question','reveal','finished','cancelled') NOT NULL DEFAULT 'lobby',
  `question_count` INT(11) NOT NULL DEFAULT 10,
  `total_time_seconds` INT(11) NOT NULL DEFAULT 600,
  `seconds_per_question` INT(11) NOT NULL DEFAULT 30,
  `selection_mode` ENUM('mixed','subjects') NOT NULL DEFAULT 'mixed',
  `subject_ids_json` TEXT DEFAULT NULL,
  `balanced` TINYINT(1) NOT NULL DEFAULT 0,
  `speed_bonus` TINYINT(1) NOT NULL DEFAULT 1,
  `streak_bonus` TINYINT(1) NOT NULL DEFAULT 1,
  `seed` VARCHAR(64) NOT NULL DEFAULT '',
  `settings_json` TEXT DEFAULT NULL,
  `current_ordinal` INT(11) NOT NULL DEFAULT 0,
  `question_started_at` DATETIME DEFAULT NULL,
  `question_ends_at` DATETIME DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`game_id`),
  UNIQUE KEY `uq_spg_battle_room_code` (`room_code`),
  KEY `idx_spg_battle_host` (`host_user_id`),
  KEY `idx_spg_battle_status` (`status`, `last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_playground_game_players` (
  `player_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT(11) NOT NULL,
  `nickname` VARCHAR(16) NOT NULL,
  `avatar_key` VARCHAR(32) NOT NULL DEFAULT 'a1',
  `status` ENUM('joined','ready','playing','disconnected','left','finished') NOT NULL DEFAULT 'joined',
  `ready_at` DATETIME DEFAULT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `left_at` DATETIME DEFAULT NULL,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `score` INT(11) NOT NULL DEFAULT 0,
  `correct_count` INT(11) NOT NULL DEFAULT 0,
  `wrong_count` INT(11) NOT NULL DEFAULT 0,
  `best_streak` INT(11) NOT NULL DEFAULT 0,
  `current_streak` INT(11) NOT NULL DEFAULT 0,
  `final_rank` INT(11) DEFAULT NULL,
  PRIMARY KEY (`player_id`),
  UNIQUE KEY `uq_spg_battle_game_user` (`game_id`, `user_id`),
  KEY `idx_spg_battle_player_user` (`user_id`),
  KEY `idx_spg_battle_player_game` (`game_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_playground_game_questions` (
  `game_question_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id` BIGINT UNSIGNED NOT NULL,
  `question_id` INT(11) NOT NULL,
  `ordinal` INT(11) NOT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`game_question_id`),
  UNIQUE KEY `uq_spg_battle_gq_ord` (`game_id`, `ordinal`),
  UNIQUE KEY `uq_spg_battle_gq_qid` (`game_id`, `question_id`),
  KEY `idx_spg_battle_gq_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_playground_game_answers` (
  `answer_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id` BIGINT UNSIGNED NOT NULL,
  `player_id` BIGINT UNSIGNED NOT NULL,
  `game_question_id` BIGINT UNSIGNED NOT NULL,
  `selected_answer` VARCHAR(5) NOT NULL DEFAULT '',
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `response_ms` INT(11) NOT NULL DEFAULT 0,
  `points` INT(11) NOT NULL DEFAULT 0,
  `answered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`answer_id`),
  UNIQUE KEY `uq_spg_battle_ans_once` (`player_id`, `game_question_id`),
  KEY `idx_spg_battle_ans_game` (`game_id`),
  KEY `idx_spg_battle_ans_gq` (`game_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
