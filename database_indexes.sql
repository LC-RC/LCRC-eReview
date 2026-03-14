-- Database Indexes for Performance Optimization
-- Run this file in phpMyAdmin or MySQL to improve query performance
-- These indexes will help the LMS handle large amounts of data efficiently

USE ereview;

-- Indexes for users table
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_created_at ON users(created_at);

-- Indexes for subjects table
CREATE INDEX idx_subjects_status ON subjects(status);
CREATE INDEX idx_subjects_name ON subjects(subject_name);

-- Indexes for lessons table
CREATE INDEX idx_lessons_subject_id ON lessons(subject_id);
CREATE INDEX idx_lessons_status ON lessons(status);

-- Indexes for lesson_videos table
CREATE INDEX idx_lesson_videos_lesson_id ON lesson_videos(lesson_id);

-- Indexes for lesson_handouts table
CREATE INDEX idx_lesson_handouts_lesson_id ON lesson_handouts(lesson_id);
CREATE INDEX idx_lesson_handouts_handout_id ON lesson_handouts(handout_id);

-- Indexes for handout_annotations table
CREATE INDEX idx_handout_annotations_handout_id ON handout_annotations(handout_id);
CREATE INDEX idx_handout_annotations_student_id ON handout_annotations(student_id);
CREATE INDEX idx_handout_annotations_composite ON handout_annotations(handout_id, student_id);

-- Indexes for quizzes table
CREATE INDEX idx_quizzes_subject_id ON quizzes(subject_id);
CREATE INDEX idx_quizzes_quiz_type ON quizzes(quiz_type);

-- Indexes for quiz_questions table
CREATE INDEX idx_quiz_questions_quiz_id ON quiz_questions(quiz_id);
CREATE INDEX idx_quiz_questions_question_id ON quiz_questions(question_id);

-- Indexes for quiz_answers table
CREATE INDEX idx_quiz_answers_user_id ON quiz_answers(user_id);
CREATE INDEX idx_quiz_answers_question_id ON quiz_answers(question_id);
CREATE INDEX idx_quiz_answers_composite ON quiz_answers(user_id, question_id);

-- Composite indexes for common query patterns
CREATE INDEX idx_users_role_status ON users(role, status);
CREATE INDEX idx_lessons_subject_status ON lessons(subject_id, status);
