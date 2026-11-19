USE `marick_health_db`;

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

INSERT IGNORE INTO `system_roles` (`role_key`, `role_name`, `is_system`, `status`, `sort_order`) VALUES
  ('super_admin', 'Super Admin',            1, 1, 10),
  ('admin',       'Admin',                  1, 1, 20),
  ('doctor',      'Doctor',                 1, 1, 30),
  ('midwife',     'Midwife',                1, 1, 40),
  ('dental',      'Dental',                 1, 1, 50),
  ('bhw',         'BHW',                    1, 1, 60),
  ('inventory',   'Inventory Staff',        0, 1, 70),
  ('encoder',     'Encoder / Registrations',0, 1, 80);

INSERT IGNORE INTO `system_roles` (`role_key`, `role_name`, `is_system`, `status`, `sort_order`)
SELECT o.`option_value`, o.`option_label`, 0, o.`is_active`, 100
  FROM `system_options` o
 WHERE o.`list_key` = 'staff_roles_custom'
   AND o.`option_value` NOT IN ('super_admin','admin','doctor','midwife','dental','bhw');

UPDATE `system_roles` r
  JOIN `system_options` o ON o.`list_key` = 'staff_roles_custom' AND o.`option_value` = r.`role_key`
   SET r.`role_name` = o.`option_label`, r.`status` = o.`is_active`
 WHERE r.`is_system` = 0;

DROP TEMPORARY TABLE IF EXISTS `mh_seed_defaults`;
CREATE TEMPORARY TABLE `mh_seed_defaults` (
    `permission_key` VARCHAR(60) NOT NULL,
    `module_key`     VARCHAR(40) NULL,
    `default_roles`  VARCHAR(255) NOT NULL
) ENGINE=MEMORY;

INSERT INTO `mh_seed_defaults` (`permission_key`, `module_key`, `default_roles`) VALUES
  ('view_patient_masterlist',  'patient_masterlist', 'admin,doctor,bhw,midwife,dental,encoder'),
  ('edit_patient_profile',     'patient_masterlist', 'admin,doctor,bhw,midwife,dental,encoder'),
  ('walkin_registration',      'walkin',             'admin,doctor,bhw,midwife,dental,encoder'),
  ('use_ocr_scanner',          NULL,                 'admin,doctor,bhw,midwife,encoder'),
  ('view_stock',               'inventory',          'admin,bhw,inventory'),
  ('add_medicine',             'inventory',          'admin,bhw,inventory'),
  ('dispense_medicine',        'inventory',          'admin,bhw,inventory'),
  ('view_dispensing_history',  'inventory',          'admin,bhw,inventory'),
  ('view_patient_queue',       NULL,                 'admin,doctor,midwife,dental,inventory,encoder'),
  ('manage_patient_queue',     NULL,                 'admin,doctor,midwife,dental,inventory'),
  ('manage_consultations',     'checkups',           'admin,doctor'),
  ('manage_maternal_care',     'maternal_care',      'admin,bhw,midwife'),
  ('manage_child_immunization','immunization',       'admin,bhw,midwife'),
  ('manage_dental_records',    'dental',             'admin,dental'),
  ('view_area_masterlist',     NULL,                 'admin,bhw'),
  ('queue_all_departments',    NULL,                 'admin,bhw'),
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

UPDATE `role_permissions` SET `allowed` = 1
 WHERE `role_key` = 'admin' AND `permission_key` IN ('manage_staff');

DROP TEMPORARY TABLE IF EXISTS `mh_seed_defaults`;

ALTER TABLE `staff` MODIFY COLUMN `role` VARCHAR(50) NOT NULL
    COMMENT 'Any system_roles.role_key';
