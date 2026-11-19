USE `marick_health_db`;

ALTER TABLE `staff`
    MODIFY COLUMN `role` VARCHAR(50) NOT NULL
    COMMENT 'super_admin, admin, doctor, bhw, midwife, dental, inventory';

UPDATE `system_settings`
SET `setting_value` = CONCAT_WS(',', NULLIF(TRIM(`setting_value`), ''), 'inventory')
WHERE `setting_key` = 'module_roles_inventory'
  AND NOT FIND_IN_SET('inventory', REPLACE(IFNULL(`setting_value`, ''), ' ', ''));

INSERT IGNORE INTO `system_options` (`list_key`, `option_value`, `option_label`, `sort_order`, `is_active`)
SELECT 'staff_roles_custom', 'inventory', 'Inventory Staff', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'staff_roles_seeded');

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('staff_roles_seeded', '1');
