INSERT INTO role_permissions (role_key, permission_key, allowed) VALUES
  ('bhw', 'walkin_registration', 1),
  ('bhw', 'manage_patient_queue', 1),
  ('bhw', 'use_ocr_scanner', 1)
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);
