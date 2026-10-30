-- Single active session per account (see includes/session_guard.php).
-- Run once in phpMyAdmin on the live database. The app also tries to add these
-- columns by itself at the next login, but that needs ALTER rights.
ALTER TABLE `staff`
  ADD COLUMN `session_token` CHAR(64) NULL DEFAULT NULL,
  ADD COLUMN `session_started_at` DATETIME NULL DEFAULT NULL;

ALTER TABLE `patients`
  ADD COLUMN `session_token` CHAR(64) NULL DEFAULT NULL,
  ADD COLUMN `session_started_at` DATETIME NULL DEFAULT NULL;
