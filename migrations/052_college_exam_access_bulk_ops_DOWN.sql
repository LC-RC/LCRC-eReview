-- Reverse 052.
-- LOSSY: permanently deletes exam-access bulk-op audit headers and per-user
-- prior/new access rows. Access-change history cannot be reconstructed from
-- these tables after this DROP.
-- Dropping these tables does not restore or revert users.college_examination_access.
-- It does not change users.status or delete users.

DROP TABLE IF EXISTS `college_exam_access_bulk_op_rows`;
DROP TABLE IF EXISTS `college_exam_access_bulk_ops`;
