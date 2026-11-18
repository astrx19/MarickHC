-- OPTIONAL - run ONLY if you want the BHW role to match the paper's "Front Desk Staff / Barangay Health Workers"
-- description (register walk-ins, route patients through the queue, use the OCR scanner).
-- Today these three default to OFF for BHW (registration lives with the `encoder` role per Dra. Celma's feedback).
-- You can make the same change without SQL: System Settings > Staff Roles & Permissions > BHW.
INSERT INTO role_permissions (role_key, permission_key, allowed) VALUES
  ('bhw', 'walkin_registration', 1),
  ('bhw', 'manage_patient_queue', 1),
  ('bhw', 'use_ocr_scanner', 1)
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);
