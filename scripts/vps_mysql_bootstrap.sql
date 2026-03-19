-- =============================================================================
-- Ereview VPS: create database + app user (run once as MySQL root)
-- =============================================================================
-- BEFORE RUNNING: replace CHANGE_ME_STRONG_PASSWORD with a long random password.
-- WHERE TO RUN: on the VPS in SSH — see scripts/README_VPS_MYSQL.md
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `ereview`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- MySQL 8+: IF NOT EXISTS avoids error on re-run. If your server errors here,
-- use: DROP USER IF EXISTS 'ereview_app'@'localhost';  then CREATE USER ...
CREATE USER IF NOT EXISTS 'ereview_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

GRANT ALL PRIVILEGES ON `ereview`.* TO 'ereview_app'@'localhost';
FLUSH PRIVILEGES;
