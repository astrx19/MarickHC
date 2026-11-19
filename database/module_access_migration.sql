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
