SET @wipe_other_staff = 0;
SET @wipe_inventory   = 0;
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

    TRUNCATE TABLE `appointments`;
    TRUNCATE TABLE `consultations`;
    TRUNCATE TABLE `dental_records`;
    TRUNCATE TABLE `maternal_records`;
    TRUNCATE TABLE `immunization_records`;
    TRUNCATE TABLE `child_consultation_logs`;
    TRUNCATE TABLE `child_immunization_records`;
    TRUNCATE TABLE `dispensations`;
    TRUNCATE TABLE `scheduled_sms`;
    TRUNCATE TABLE `sms_logs`;
    TRUNCATE TABLE `patients`;

    TRUNCATE TABLE `activity_logs`;
    TRUNCATE TABLE `password_resets`;

    IF @wipe_inventory = 1 THEN
        TRUNCATE TABLE `inventory`;
    END IF;

    IF @wipe_other_staff = 1 THEN
        DELETE FROM `user_dashboard_preferences` WHERE `user_id` NOT IN (SELECT `id` FROM `staff` WHERE `role` = 'super_admin');
        DELETE FROM `staff` WHERE `role` <> 'super_admin';
    END IF;

    SET FOREIGN_KEY_CHECKS = 1;
END$$
DELIMITER ;

CALL mh_clean_reset();
DROP PROCEDURE mh_clean_reset;

SELECT 'staff (super_admin kept)' AS tbl, COUNT(*) AS rows_left FROM `staff`
UNION ALL SELECT 'patients', COUNT(*) FROM `patients`
UNION ALL SELECT 'appointments', COUNT(*) FROM `appointments`
UNION ALL SELECT 'activity_logs', COUNT(*) FROM `activity_logs`
UNION ALL SELECT 'inventory', COUNT(*) FROM `inventory`;
