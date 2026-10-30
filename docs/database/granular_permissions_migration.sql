-- =====================================================================
-- MARICK HEALTH CENTER - GRANULAR SUB-MODULE PERMISSIONS
-- Run once:  mysql -u root marick_health_db < database/granular_permissions_migration.sql
-- Requires MySQL 8.0.29+ / MariaDB 10.0+ (ADD COLUMN IF NOT EXISTS), the
-- same requirement as rbac_and_names_migration.sql. Safe to re-run.
-- BACK UP THE DATABASE FIRST.
--
-- Replaces the old lump-sum flags (can_manage_inventory, ...) with one
-- flag per function. Full list lives in mh_capability_registry()
-- (includes/auth.php) - that file is the single source of truth.
-- Admin / Super Admin never need these flags (they hold everything).
-- =====================================================================
USE `marick_health_db`;

-- 1. New granular columns ---------------------------------------------
ALTER TABLE `staff`
    ADD COLUMN IF NOT EXISTS `can_manage_supply_masterlist`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_dispensing`         TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_dispensing_history`   TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_log_consultations`         TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_consultation_history` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_log_immunization`          TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_immunization_records` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_log_maternal`              TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_maternal_history`     TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_log_dental`                TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_dental_history`       TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_queue`                TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_queue`              TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_area_masterlist`      TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Carry existing delegations over so nobody loses access -----------
-- (runs only if the old columns exist; guarded so a fresh install is fine)
DROP PROCEDURE IF EXISTS mh_backfill_granular_caps;
DELIMITER $$
CREATE PROCEDURE mh_backfill_granular_caps()
BEGIN
    DECLARE has_old INT DEFAULT 0;
    SELECT COUNT(*) INTO has_old FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'can_manage_inventory';
    IF has_old > 0 THEN
        UPDATE `staff` SET `can_manage_supply_masterlist` = 1, `can_manage_dispensing` = 1, `can_view_dispensing_history` = 1 WHERE `can_manage_inventory` = 1;
        UPDATE `staff` SET `can_log_consultations` = 1, `can_view_consultation_history` = 1 WHERE `can_manage_consultations` = 1;
        UPDATE `staff` SET `can_log_immunization` = 1, `can_view_immunization_records` = 1 WHERE `can_manage_immunization` = 1;
        UPDATE `staff` SET `can_log_maternal` = 1, `can_view_maternal_history` = 1 WHERE `can_manage_maternal` = 1;
        UPDATE `staff` SET `can_log_dental` = 1, `can_view_dental_history` = 1 WHERE `can_manage_dental` = 1;
    END IF;
    -- can_manage_queue keeps its name; anyone who could process the queue can also view it
    UPDATE `staff` SET `can_view_queue` = 1 WHERE `can_manage_queue` = 1;
END$$
DELIMITER ;
CALL mh_backfill_granular_caps();
DROP PROCEDURE mh_backfill_granular_caps;

-- 3. Old lump-sum columns are now unused by the application. They are left
--    in place (harmless, and lets you roll back). Drop them later with:
--   ALTER TABLE `staff` DROP COLUMN `can_manage_inventory`, DROP COLUMN `can_manage_consultations`,
--     DROP COLUMN `can_manage_immunization`, DROP COLUMN `can_manage_maternal`, DROP COLUMN `can_manage_dental`;
