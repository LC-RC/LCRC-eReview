-- =====================================================
-- 039 DOWN - Rollback Phase 1 gamification tables (SAFE)
-- Drops only the three additive tables created by 039.
-- Does not touch users, quizzes, Playground, SCA, or commerce.
-- =====================================================

DROP TABLE IF EXISTS `student_achievements`;
DROP TABLE IF EXISTS `student_gamification_events`;
DROP TABLE IF EXISTS `student_gamification_profile`;
