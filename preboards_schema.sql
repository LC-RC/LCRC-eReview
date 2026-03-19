-- Preboards: subjects, sets, questions, attempts, answers
-- Run once or let PHP create tables with CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS preboards_subjects (
  preboards_subject_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_preboards_subject_name (subject_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preboards_sets (
  preboards_set_id INT AUTO_INCREMENT PRIMARY KEY,
  preboards_subject_id INT NOT NULL,
  set_label VARCHAR(10) NOT NULL,
  title VARCHAR(200) DEFAULT NULL,
  time_limit_seconds INT NOT NULL DEFAULT 3600,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_preboards_set_subject_label (preboards_subject_id, set_label),
  KEY idx_preboards_sets_subject (preboards_subject_id),
  CONSTRAINT fk_preboards_sets_subject FOREIGN KEY (preboards_subject_id) REFERENCES preboards_subjects (preboards_subject_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preboards_questions (
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
  KEY idx_preboards_questions_set (preboards_set_id),
  CONSTRAINT fk_preboards_questions_set FOREIGN KEY (preboards_set_id) REFERENCES preboards_sets (preboards_set_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preboards_attempts (
  preboards_attempt_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  preboards_set_id INT NOT NULL,
  status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
  score DECIMAL(5,2) DEFAULT NULL,
  correct_count INT DEFAULT NULL,
  total_count INT DEFAULT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_preboards_attempt_user_set (user_id, preboards_set_id),
  KEY idx_preboards_attempts_user (user_id),
  KEY idx_preboards_attempts_set (preboards_set_id),
  CONSTRAINT fk_preboards_attempts_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_preboards_attempts_set FOREIGN KEY (preboards_set_id) REFERENCES preboards_sets (preboards_set_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preboards_answers (
  preboards_answer_id INT AUTO_INCREMENT PRIMARY KEY,
  preboards_attempt_id INT NOT NULL,
  preboards_question_id INT NOT NULL,
  selected_answer VARCHAR(1) DEFAULT NULL,
  is_correct TINYINT(1) DEFAULT NULL,
  answered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preboards_answers_attempt (preboards_attempt_id),
  KEY idx_preboards_answers_question (preboards_question_id),
  CONSTRAINT fk_preboards_answers_attempt FOREIGN KEY (preboards_attempt_id) REFERENCES preboards_attempts (preboards_attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_preboards_answers_question FOREIGN KEY (preboards_question_id) REFERENCES preboards_questions (preboards_question_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

