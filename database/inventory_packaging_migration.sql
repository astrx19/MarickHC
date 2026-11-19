USE `marick_health_db`;

ALTER TABLE `inventory`
    ADD COLUMN IF NOT EXISTS `units_per_pack` INT NULL DEFAULT NULL
        COMMENT 'Base units per intermediate pack (e.g. tablets per blister/banig)'
        AFTER `unit`,
    ADD COLUMN IF NOT EXISTS `pack_label` VARCHAR(40) NULL DEFAULT 'Blister Pack'
        COMMENT 'Display label for the intermediate pack, e.g. Blister Pack, Banig, Vial'
        AFTER `units_per_pack`,
    ADD COLUMN IF NOT EXISTS `packs_per_box` INT NULL DEFAULT NULL
        COMMENT 'Intermediate packs per box/carton'
        AFTER `pack_label`,
    ADD COLUMN IF NOT EXISTS `box_label` VARCHAR(40) NULL DEFAULT 'Box'
        COMMENT 'Display label for the outer packaging, e.g. Box, Carton'
        AFTER `packs_per_box`;

ALTER TABLE `dispensations`
    ADD COLUMN IF NOT EXISTS `dispensed_by` INT NULL
        COMMENT 'Staff who processed the dispensation'
        AFTER `patient_id`,
    ADD COLUMN IF NOT EXISTS `is_fefo_override` TINYINT(1) DEFAULT 0
        COMMENT '1 = staff manually picked a later-expiring batch while an earlier batch still had stock'
        AFTER `dispensed_by`;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'marick_health_db'
      AND TABLE_NAME = 'dispensations'
      AND CONSTRAINT_NAME = 'fk_dispensations_staff'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `dispensations` ADD CONSTRAINT `fk_dispensations_staff` FOREIGN KEY (`dispensed_by`) REFERENCES `staff`(`id`) ON DELETE SET NULL',
    'SELECT "fk_dispensations_staff already exists, skipping"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `inventory` SET `pack_label` = 'Blister Pack' WHERE `pack_label` IS NULL;
UPDATE `inventory` SET `box_label` = 'Box' WHERE `box_label` IS NULL;
