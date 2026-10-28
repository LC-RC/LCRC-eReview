-- 057 added no schema. Nothing to drop.
-- Seq/UNIQUE rollback is not this file. Use 049_college_student_number_seq_DOWN.sql:
-- it drops college_student_number_seq and removes uq_users_student_number only if
-- that exact index name exists. A differently named UNIQUE on users.student_number
-- may remain. That is best-effort, not a guaranteed full UNIQUE rollback.
SELECT 1;
