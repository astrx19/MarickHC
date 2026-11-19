USE `marick_health_db`;

ALTER TABLE `staff`
    ADD COLUMN IF NOT EXISTS `can_manage_inventory`     TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_immunization`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_consultations` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_queue`         TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_dental`        TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_maternal`      TINYINT(1) NOT NULL DEFAULT 0;

UPDATE `staff`
SET `role` = 'super_admin'
WHERE `role` = 'admin'
  AND NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `staff` WHERE `role` = 'super_admin') AS existing_super);

ALTER TABLE `staff`
    MODIFY COLUMN `role` VARCHAR(50) NOT NULL
    COMMENT 'super_admin, admin, doctor, bhw, midwife, dental';

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
