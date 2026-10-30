-- =====================================================================
-- INVENTORY STAFF / PHARMACIST ROLE  (staff.role = 'inventory')
-- =====================================================================
-- Safe to run more than once.
--
-- 1. `staff`.`role` is a VARCHAR(50) (not an ENUM), so the new value
--    'inventory' needs no schema change - only the documentation comment
--    is refreshed here.
-- 2. Installs that already saved Module Access settings keep their saved
--    role list (it overrides the code default), so 'inventory' is added to
--    the Medicine Inventory module explicitly. Fresh installs get it from
--    mh_module_registry() automatically; this is a no-op for them if the
--    key does not exist yet.
-- =====================================================================

USE `marick_health_db`;

ALTER TABLE `staff`
    MODIFY COLUMN `role` VARCHAR(50) NOT NULL
    COMMENT 'super_admin, admin, doctor, bhw, midwife, dental, inventory';

UPDATE `system_settings`
SET `setting_value` = CONCAT_WS(',', NULLIF(TRIM(`setting_value`), ''), 'inventory')
WHERE `setting_key` = 'module_roles_inventory'
  AND NOT FIND_IN_SET('inventory', REPLACE(IFNULL(`setting_value`, ''), ' ', ''));

-- 3. "Inventory Staff" is now an ordinary, deletable role managed under
--    System Settings -> Staff Roles. It is created automatically the first
--    time any page loads the role list (and never re-created after you
--    delete it). Nothing to do here; this block only pre-creates it when
--    the migration is run before that happens.
INSERT IGNORE INTO `system_options` (`list_key`, `option_value`, `option_label`, `sort_order`, `is_active`)
SELECT 'staff_roles_custom', 'inventory', 'Inventory Staff', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM `system_settings` WHERE `setting_key` = 'staff_roles_seeded');

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('staff_roles_seeded', '1');
