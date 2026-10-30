-- =====================================================================
-- MARICK HEALTH CENTER - CLEAN DATABASE RESET
-- Erases ALL transactional / dummy / test data. Keeps every table schema
-- and every Super Admin account (so you can log in right after).
--
--   mysql -u root marick_health_db < database/reset_clean_database.sql
--
-- *** DESTRUCTIVE AND IRREVERSIBLE - BACK UP FIRST (mysqldump). ***
-- The script ABORTS before deleting anything if no Super Admin exists.
--
-- Options (set to 1 to enable, before running):
SET @wipe_other_staff = 0;   -- 1 = also delete every non-Super-Admin staff account (seed doctors, BHWs...)
SET @wipe_inventory   = 0;   -- 1 = also delete the medicine/supply masterlist (stock items & batches)
-- =====================================================================
USE `marick_health_db`;

DROP PROCEDURE IF EXISTS mh_clean_reset;
DELIMITER $$
CREATE PROCEDURE mh_clean_reset()
BEGIN
    DECLARE sa_count INT DEFAULT 0;
    SELECT COUNT(*) INTO sa_count FROM `staff` WHERE `role` = 'super_admin';
    IF sa_count = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ABORTED: no super_admin account exists. Nothing was deleted.';
    END IF;

    SET FOREIGN_KEY_CHECKS = 0;

    -- Patient-linked clinical & transactional records ---------------------
    TRUNCATE TABLE `appointments`;                -- patient queue entries
    TRUNCATE TABLE `consultations`;               -- general consultation logs
    TRUNCATE TABLE `dental_records`;              -- dental logs
    TRUNCATE TABLE `maternal_records`;            -- maternal / prenatal records
    TRUNCATE TABLE `immunization_records`;        -- vaccines administered
    TRUNCATE TABLE `child_consultation_logs`;     -- child checkups
    TRUNCATE TABLE `child_immunization_records`;  -- child immunization forms
    TRUNCATE TABLE `dispensations`;               -- dispensing history (stock is NOT re-added)
    TRUNCATE TABLE `scheduled_sms`;               -- SMS reminder queue
    TRUNCATE TABLE `sms_logs`;                    -- SMS delivery history
    TRUNCATE TABLE `patients`;                    -- patient masterlist + patient portal logins

    -- Audit trail & auth tokens ------------------------------------------
    TRUNCATE TABLE `activity_logs`;
    TRUNCATE TABLE `password_resets`;

    -- Optional: masterlist stock -----------------------------------------
    IF @wipe_inventory = 1 THEN
        TRUNCATE TABLE `inventory`;
    END IF;

    -- Staff: Super Admin accounts always survive ---------------------------
    IF @wipe_other_staff = 1 THEN
        DELETE FROM `user_dashboard_preferences` WHERE `user_id` NOT IN (SELECT `id` FROM `staff` WHERE `role` = 'super_admin');
        DELETE FROM `staff` WHERE `role` <> 'super_admin';
    END IF;

    SET FOREIGN_KEY_CHECKS = 1;
END$$
DELIMITER ;

CALL mh_clean_reset();
DROP PROCEDURE mh_clean_reset;

-- Deliberately NOT touched: staff (Super Admin), system_settings,
-- system_options (dropdown masterlists), holidays, user_dashboard_preferences
-- of kept accounts, and all table structures.

-- Verification
SELECT 'staff (super_admin kept)' AS tbl, COUNT(*) AS rows_left FROM `staff`
UNION ALL SELECT 'patients', COUNT(*) FROM `patients`
UNION ALL SELECT 'appointments', COUNT(*) FROM `appointments`
UNION ALL SELECT 'activity_logs', COUNT(*) FROM `activity_logs`
UNION ALL SELECT 'inventory', COUNT(*) FROM `inventory`;
