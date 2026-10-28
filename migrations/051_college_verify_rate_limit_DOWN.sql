-- Reverse 051.
-- LOSSY: permanently deletes college registration/resend rate-limit event rows
-- (hashed IP, hashed email, action, timestamps). After this DROP, application
-- code fails closed until 051 is reapplied.

DROP TABLE IF EXISTS `college_verify_rate_events`;
