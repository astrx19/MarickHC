-- ==========================================================
-- MARICK HEALTH CENTER - MODULE ACCESS CONTROL
-- Additive migration. Safe to run on an existing database:
-- it only INSERTs IGNORE into the existing `system_settings` table
-- (see database/settings_schema.sql), no new tables are required.
--
-- Run once:  mysql -u root marick_health_db < database/module_access_migration.sql
-- (admin/system_settings.php also self-seeds these rows on first load
--  of the new Module Access tab, via mh_settings_install(), so running
--  this by hand is optional.)
--
-- Each module has two flat keys, matching every other setting already
-- stored here:
--   module_enabled_<key>  '1' = visible/usable, '0' = turned off for everyone
--   module_roles_<key>    comma-separated staff roles allowed in ('admin'
--                          is always implicitly allowed, see
--                          includes/settings_helper.php: mh_module_allowed_roles())
--
-- The defaults below mirror the access the app already granted via the
-- sidebar nav (includes/admin_sidebar.php) before this feature existed,
-- so applying this migration changes nothing for an existing install
-- until an admin actually edits the Module Access tab.
-- ==========================================================

USE `marick_health_db`;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('module_enabled_checkups',       '1'),
  ('module_roles_checkups',         'admin,doctor'),

  ('module_enabled_dental',         '1'),
  ('module_roles_dental',           'admin,dental'),

  ('module_enabled_maternal_care',  '1'),
  ('module_roles_maternal_care',    'admin,bhw,midwife'),

  ('module_enabled_immunization',   '1'),
  ('module_roles_immunization',     'admin,bhw,midwife'),

  ('module_enabled_inventory',      '1'),
  ('module_roles_inventory',        'admin,bhw'),

  ('module_enabled_sms_reminders',  '1'),
  ('module_roles_sms_reminders',    'admin');
