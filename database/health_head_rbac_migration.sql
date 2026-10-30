-- =====================================================================
-- TASK 2: RBAC restructure  (run ONCE in phpMyAdmin, on a backup first)
--   1. Health Head replaces Super Admin
--   2. Encoder limited to external-platform encoding (PhilHealth / Yakap)
--   3. BHW limited to physical folders (read-only) + vital signs entry
-- Safe to re-run.
-- =====================================================================

-- 1. Role rows -----------------------------------------------------------
INSERT IGNORE INTO system_roles (role_key, role_name, is_system, status, sort_order)
VALUES ('health_head', 'Health Head', 1, 1, 10);

UPDATE staff SET role = 'health_head' WHERE role = 'super_admin';

DELETE FROM role_permissions WHERE role_key = 'super_admin';
DELETE FROM system_roles     WHERE role_key = 'super_admin';

-- 2. Vital signs table -----------------------------------------------------
CREATE TABLE IF NOT EXISTS vital_signs (
  id INT NOT NULL AUTO_INCREMENT,
  patient_id INT NOT NULL,
  recorded_by INT NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bp VARCHAR(20) DEFAULT NULL,
  temp VARCHAR(20) DEFAULT NULL,
  pulse VARCHAR(20) DEFAULT NULL,
  resp_rate VARCHAR(20) DEFAULT NULL,
  spo2 VARCHAR(20) DEFAULT NULL,
  weight VARCHAR(20) DEFAULT NULL,
  height VARCHAR(20) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_patient_time (patient_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Rebuild the permission matrix for the roles being restricted ---------
-- (Health Head is implicit in code and needs no rows. Admin / Doctor / Midwife /
--  Dental / Inventory keep what they have, plus the new Vital Signs toggle.)
DELETE FROM role_permissions WHERE role_key IN ('encoder', 'bhw');

INSERT INTO role_permissions (role_key, permission_key, allowed) VALUES
 -- ENCODER: registration intake + OCR + read-only queue. Nothing else.
 ('encoder','view_patient_masterlist',0),('encoder','edit_patient_profile',0),
 ('encoder','walkin_registration',1),    ('encoder','use_ocr_scanner',1),
 ('encoder','view_stock',0),('encoder','add_medicine',0),('encoder','dispense_medicine',0),('encoder','view_dispensing_history',0),
 ('encoder','view_patient_queue',1),     ('encoder','manage_patient_queue',0),
 ('encoder','manage_consultations',0),('encoder','manage_maternal_care',0),
 ('encoder','manage_child_immunization',0),('encoder','manage_dental_records',0),
 ('encoder','queue_all_departments',0),  ('encoder','view_area_masterlist',0),
 ('encoder','log_vital_signs',0),
 ('encoder','manage_staff',0),('encoder','view_system_logs',0),('encoder','view_system_archives',0),
 -- BHW: physical folders (read-only masterlist + area list) and vital signs entry.
 ('bhw','view_patient_masterlist',1),    ('bhw','edit_patient_profile',0),
 ('bhw','walkin_registration',0),        ('bhw','use_ocr_scanner',0),
 ('bhw','view_stock',0),('bhw','add_medicine',0),('bhw','dispense_medicine',0),('bhw','view_dispensing_history',0),
 ('bhw','view_patient_queue',1),         ('bhw','manage_patient_queue',0),
 ('bhw','manage_consultations',0),('bhw','manage_maternal_care',0),
 ('bhw','manage_child_immunization',0),('bhw','manage_dental_records',0),
 ('bhw','queue_all_departments',1),      ('bhw','view_area_masterlist',1),
 ('bhw','log_vital_signs',1),
 ('bhw','manage_staff',0),('bhw','view_system_logs',0),('bhw','view_system_archives',0);

-- Vital Signs toggle for the other clinical roles
INSERT IGNORE INTO role_permissions (role_key, permission_key, allowed) VALUES
 ('admin','log_vital_signs',1),('doctor','log_vital_signs',1),
 ('midwife','log_vital_signs',1),('dental','log_vital_signs',1),
 ('inventory','log_vital_signs',0);

-- 4. Clear per-account overrides that would still give a BHW / Encoder extra access
UPDATE staff SET
  can_manage_supply_masterlist = NULL, can_manage_dispensing = NULL, can_view_dispensing_history = NULL,
  can_log_consultations = NULL, can_view_consultation_history = NULL,
  can_log_immunization = NULL, can_view_immunization_records = NULL,
  can_log_maternal = NULL, can_view_maternal_history = NULL,
  can_log_dental = NULL, can_view_dental_history = NULL,
  can_view_queue = NULL, can_manage_queue = NULL, can_view_area_masterlist = NULL
WHERE role IN ('bhw','encoder');

-- 5. Check: this should list NO super_admin and at least one health_head
SELECT role, COUNT(*) AS accounts FROM staff GROUP BY role;
