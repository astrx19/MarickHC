-- =====================================================================
-- MARICK HEALTH CENTER - DYNAMIC ROLES & PERMISSIONS (100% database-driven)
-- Run once:  mysql -u root marick_health_db < database/roles_permissions_migration.sql
-- Safe to re-run (CREATE IF NOT EXISTS / INSERT IGNORE). BACK UP FIRST.
--
-- Creates:
--   system_roles      one row per staff role   (role_key = value stored in staff.role)
--   role_permissions  one row per role x feature toggle
-- Seeds the 8 core roles with the access they already had, and carries over:
--   * custom roles previously stored in system_options (staff_roles_custom)
--   * any Module Access role lists already saved in system_settings
--     (module_roles_<module>), so nobody gains or loses access.
-- The permission KEYS are defined in code (mh_permission_registry() in
-- includes/roles.php); which role holds which key lives only in the database.
-- (admin/system_settings.php also self-installs this on first visit, so
--  running this file by hand is optional.)
-- =====================================================================
USE `marick_health_db`;

-- 1. Tables -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_roles` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key`   VARCHAR(50)  NOT NULL,
    `role_name`  VARCHAR(60)  NOT NULL,
    `is_system`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = built-in, cannot be deleted/renamed',
    `status`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = offered for new accounts',
    `sort_order` INT          NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_key`       VARCHAR(50) NOT NULL,
    `permission_key` VARCHAR(60) NOT NULL,
    `allowed`        TINYINT(1)  NOT NULL DEFAULT 0,
    `updated_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_key`, `permission_key`),
    KEY `idx_perm` (`permission_key`, `allowed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Roles ------------------------------------------------------------
INSERT IGNORE INTO `system_roles` (`role_key`, `role_name`, `is_system`, `status`, `sort_order`) VALUES
  ('super_admin', 'Super Admin',            1, 1, 10),
  ('admin',       'Admin',                  1, 1, 20),
  ('doctor',      'Doctor',                 1, 1, 30),
  ('midwife',     'Midwife',                1, 1, 40),
  ('dental',      'Dental',                 1, 1, 50),
  ('bhw',         'BHW',                    1, 1, 60),
  ('inventory',   'Inventory Staff',        0, 1, 70),
  ('encoder',     'Encoder / Registrations',0, 1, 80);

-- Custom roles created before this migration (system_options list) --------
INSERT IGNORE INTO `system_roles` (`role_key`, `role_name`, `is_system`, `status`, `sort_order`)
SELECT o.`option_value`, o.`option_label`, 0, o.`is_active`, 100
  FROM `system_options` o
 WHERE o.`list_key` = 'staff_roles_custom'
   AND o.`option_value` NOT IN ('super_admin','admin','doctor','midwife','dental','bhw');

-- Keep the label the admin already gave the old "Inventory Staff" role
UPDATE `system_roles` r
  JOIN `system_options` o ON o.`list_key` = 'staff_roles_custom' AND o.`option_value` = r.`role_key`
   SET r.`role_name` = o.`option_label`, r.`status` = o.`is_active`
 WHERE r.`is_system` = 0;

-- 3. Default grants ---------------------------------------------------
-- module_key  = the Module Access module whose SAVED role list (if any) wins
-- default_roles = comma list used when nothing was ever saved
DROP TEMPORARY TABLE IF EXISTS `mh_seed_defaults`;
CREATE TEMPORARY TABLE `mh_seed_defaults` (
    `permission_key` VARCHAR(60) NOT NULL,
    `module_key`     VARCHAR(40) NULL,
    `default_roles`  VARCHAR(255) NOT NULL
) ENGINE=MEMORY;

INSERT INTO `mh_seed_defaults` (`permission_key`, `module_key`, `default_roles`) VALUES
  -- Patient management & OCR
  ('view_patient_masterlist',  'patient_masterlist', 'admin,doctor,bhw,midwife,dental,encoder'),
  ('edit_patient_profile',     'patient_masterlist', 'admin,doctor,bhw,midwife,dental,encoder'),
  ('walkin_registration',      'walkin',             'admin,doctor,bhw,midwife,dental,encoder'),
  ('use_ocr_scanner',          NULL,                 'admin,doctor,bhw,midwife,encoder'),
  -- Inventory & pharmacy
  ('view_stock',               'inventory',          'admin,bhw,inventory'),
  ('add_medicine',             'inventory',          'admin,bhw,inventory'),
  ('dispense_medicine',        'inventory',          'admin,bhw,inventory'),
  ('view_dispensing_history',  'inventory',          'admin,bhw,inventory'),
  -- Clinical & queueing
  ('view_patient_queue',       NULL,                 'admin,doctor,midwife,dental,inventory,encoder'),
  ('manage_patient_queue',     NULL,                 'admin,doctor,midwife,dental,inventory'),
  ('manage_consultations',     'checkups',           'admin,doctor'),
  ('manage_maternal_care',     'maternal_care',      'admin,bhw,midwife'),
  ('manage_child_immunization','immunization',       'admin,bhw,midwife'),
  ('manage_dental_records',    'dental',             'admin,dental'),
  ('view_area_masterlist',     NULL,                 'admin,bhw'),
  ('queue_all_departments',    NULL,                 'admin,bhw'),
  -- System administration
  ('manage_staff',             NULL,                 'admin'),
  ('view_system_logs',         NULL,                 'admin'),
  ('view_system_archives',     NULL,                 'admin');

INSERT IGNORE INTO `role_permissions` (`role_key`, `permission_key`, `allowed`)
SELECT r.`role_key`, d.`permission_key`,
       CASE
         WHEN r.`role_key` = 'super_admin'  THEN 1
         WHEN s.`setting_value` IS NOT NULL THEN (FIND_IN_SET(r.`role_key`, REPLACE(s.`setting_value`, ' ', '')) > 0)
         ELSE (FIND_IN_SET(r.`role_key`, d.`default_roles`) > 0)
       END
  FROM `system_roles` r
 CROSS JOIN `mh_seed_defaults` d
  LEFT JOIN `system_settings` s
         ON d.`module_key` IS NOT NULL AND s.`setting_key` = CONCAT('module_roles_', d.`module_key`);

-- Seed safety: the Admin role always starts with Staff Management
UPDATE `role_permissions` SET `allowed` = 1
 WHERE `role_key` = 'admin' AND `permission_key` IN ('manage_staff');

DROP TEMPORARY TABLE IF EXISTS `mh_seed_defaults`;

-- 4. staff.role stays a VARCHAR(50): a new role key needs no schema change.
ALTER TABLE `staff` MODIFY COLUMN `role` VARCHAR(50) NOT NULL
    COMMENT 'Any system_roles.role_key';
