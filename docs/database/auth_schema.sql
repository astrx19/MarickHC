-- =========================================================================
-- MARICK HEALTH CENTER - USERNAME LOGIN + FORGOT PASSWORD
-- =========================================================================
-- Run this once against marick_health_db (phpMyAdmin > Import, or:
--   mysql -u root marick_health_db < database/auth_schema.sql
-- ), THEN run the one-time username backfill:
--   php database/backfill_usernames.php
-- (see that file's header for why a separate PHP step is needed).
--
-- Written with IF NOT EXISTS / a guarded ADD COLUMN so it is safe to run
-- more than once. Requires MySQL 8.0.29+ / MariaDB 10.0+ for
-- "ADD COLUMN IF NOT EXISTS" - on older versions, drop that clause and
-- run the ADD COLUMN statements once by hand instead.
-- =========================================================================

-- -------------------------------------------------------------------------
-- 1. username column
-- -------------------------------------------------------------------------
-- NULL is allowed on both tables:
--   - patients.username is NULL for admin/walkin.php Quick Registration
--     rows (email stays the identifier for those until a patient claims
--     the record through the portal and picks a username).
--   - staff.username is NULL only for the instant between the ALTER
--     TABLE below and running backfill_usernames.php.
-- UNIQUE still works correctly with NULL: MySQL/MariaDB unique indexes
-- allow any number of NULLs, they just never collide with each other.

ALTER TABLE `staff`
    ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) NULL UNIQUE AFTER `email`;

ALTER TABLE `patients`
    ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) NULL UNIQUE AFTER `email`;

-- -------------------------------------------------------------------------
-- 2. password_resets
-- -------------------------------------------------------------------------
-- This is the exact table requested, with one deliberate addition:
-- `user_type`. Reasoning: this project has TWO separate login tables
-- (`staff` and `patients`) with no shared "users" table, but a reset
-- token only stores an email address. Without recording which table that
-- email belongs to, reset_password.php would have to guess by searching
-- both tables in some fixed order - harmless almost always, but silently
-- wrong in the edge case where the same email is (or later becomes) both
-- a staff member's and a patient's login. `user_type` removes that
-- ambiguity for a single extra column. Everything else matches the
-- schema as given.

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(255) NOT NULL,
    `token`      VARCHAR(255) NOT NULL,
    `user_type`  ENUM('patient','staff') NOT NULL DEFAULT 'patient',
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_token` (`token`),
    KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
