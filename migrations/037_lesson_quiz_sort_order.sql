-- Manual display order for Content Hub lessons & quizzes (per subject).
-- Safe additive migration: does not drop or recreate lesson/quiz rows.
-- Runtime ensure also lives in includes/content_sort_order.php.

ALTER TABLE `lessons`
  ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `description`;

ALTER TABLE `lessons`
  ADD KEY `idx_lessons_subject_sort` (`subject_id`, `sort_order`);

ALTER TABLE `quizzes`
  ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `title`;

ALTER TABLE `quizzes`
  ADD KEY `idx_quizzes_subject_sort` (`subject_id`, `sort_order`);

-- Backfill preserves previous student-visible order:
--   lessons: oldest-first (lesson_id ASC)
--   quizzes: newest-first (quiz_id DESC)
-- Application ensure_schema() also backfills if needed.
