-- =====================================================================
-- MARICK HEALTH CENTER - ROLE TIERS, DELEGATED CAPABILITIES, FREE-TEXT NAMES
-- Run once:  mysql -u root marick_health_db < database/rbac_and_names_migration.sql
-- Requires MySQL 8.0.29+ / MariaDB 10.0+ for ADD COLUMN IF NOT EXISTS
-- (same requirement as auth_schema.sql). Safe to re-run.
-- BACK UP THE DATABASE FIRST - section 2 re-labels existing accounts.
-- =====================================================================
USE `marick_health_db`;

-- ---------------------------------------------------------------------
-- 1. Delegated capability flags on `staff`
--    (this project's login table is `staff`, there is no `users` table)
-- ---------------------------------------------------------------------
ALTER TABLE `staff`
    ADD COLUMN IF NOT EXISTS `can_manage_inventory`     TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_immunization`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_consultations` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_queue`         TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_dental`        TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_maternal`      TINYINT(1) NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------
-- 2. Role tiers
--    `staff`.`role` is VARCHAR(50), NOT an ENUM, so no ALTER is needed to
--    add 'admin' - any string is already storable.
--
--    IMPORTANT: until now the string 'admin' meant "master authority".
--    The new hierarchy gives that meaning to 'super_admin' and reuses
--    'admin' for the new, lower operational tier. So existing 'admin'
--    accounts are promoted to 'super_admin' ONCE (nobody loses access).
--    The NOT EXISTS guard stops a re-run from promoting the new Admins.
-- ---------------------------------------------------------------------
UPDATE `staff`
SET `role` = 'super_admin'
WHERE `role` = 'admin'
  AND NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `staff` WHERE `role` = 'super_admin') AS existing_super);

ALTER TABLE `staff`
    MODIFY COLUMN `role` VARCHAR(50) NOT NULL
    COMMENT 'super_admin, admin, doctor, bhw, midwife, dental';

-- ---------------------------------------------------------------------
-- 3. Free-text patient names on the four clinical modules
--    patient_id becomes NULLABLE (a typed name may not match a registered
--    patient) and the typed name is stored in patient_name.
-- ---------------------------------------------------------------------
ALTER TABLE `consultations`
    MODIFY COLUMN `patient_id` INT NULL,
    ADD COLUMN IF NOT EXISTS `patient_name` VARCHAR(150) NULL AFTER `patient_id`;

ALTER TABLE `dental_records`
    MODIFY COLUMN `patient_id` INT NULL,
    ADD COLUMN IF NOT EXISTS `patient_name` VARCHAR(150) NULL AFTER `patient_id`;

ALTER TABLE `maternal_records`
    MODIFY COLUMN `patient_id` INT NULL,
    ADD COLUMN IF NOT EXISTS `patient_name` VARCHAR(150) NULL AFTER `patient_id`;

ALTER TABLE `child_immunization_records`
    MODIFY COLUMN `patient_id` INT NULL,
    ADD COLUMN IF NOT EXISTS `patient_name` VARCHAR(150) NULL AFTER `patient_id`;
