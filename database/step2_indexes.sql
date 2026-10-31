-- =====================================================================
-- STEP 2 - INDEX MIGRATION  (Marick Health Center)
-- Run once in phpMyAdmin (SQL tab) or:  mysql -u USER -p DBNAME < database/step2_indexes.sql
--
-- * Safe to run more than once: every statement uses IF NOT EXISTS
--   (MariaDB 10.1+ - your dump reports MariaDB 11.8, so this is fine).
--   On Oracle MySQL 8 remove the "IF NOT EXISTS" words and skip any
--   index that already exists.
-- * Nothing is dropped or changed - indexes only. Rollback = DROP INDEX name ON table.
-- * Foreign-key style columns (patient_id, doctor_id, inventory_id ...)
--   are ALREADY indexed in your schema, so they are not repeated here.
--   Only columns the code filters / sorts on without an index are added.
-- * Your schema has no `department_id` column: departments are stored by
--   NAME in appointments.department (covered by the existing
--   uq_queue_slot (department, appointment_date, queue_number) index).
-- =====================================================================

-- Queue / "today" screens: WHERE appointment_date = CURDATE() [AND status IN (...)]
ALTER TABLE `appointments`
  ADD INDEX IF NOT EXISTS `idx_apt_date_status` (`appointment_date`, `status`);

-- Dashboard widgets + inventory page: low-stock / expiring / expired counts
ALTER TABLE `inventory`
  ADD INDEX IF NOT EXISTS `idx_inv_status_expiry`   (`status`, `expiry_date`),
  ADD INDEX IF NOT EXISTS `idx_inv_status_quantity` (`status`, `quantity`);

-- Dashboard "registered this week" / total active patients
ALTER TABLE `patients`
  ADD INDEX IF NOT EXISTS `idx_patients_status_created` (`status`, `created_at`);

-- Follow-up / due-date widgets and visit-date reports
ALTER TABLE `child_immunization_records`
  ADD INDEX IF NOT EXISTS `idx_cir_next_visit` (`next_visit_date`);
ALTER TABLE `maternal_records`
  ADD INDEX IF NOT EXISTS `idx_mat_next_visit` (`next_visit_date`),
  ADD INDEX IF NOT EXISTS `idx_mat_visit_date` (`visit_date`);
ALTER TABLE `consultations`
  ADD INDEX IF NOT EXISTS `idx_cons_visit_date` (`visit_date`),
  ADD INDEX IF NOT EXISTS `idx_cons_next_visit` (`next_visit_date`);
ALTER TABLE `dental_records`
  ADD INDEX IF NOT EXISTS `idx_dental_visit_date` (`visit_date`);

-- Dispensing history (date filter + newest first)
ALTER TABLE `dispensations`
  ADD INDEX IF NOT EXISTS `idx_disp_date` (`dispense_date`);

-- SMS dashboard "failed today"
ALTER TABLE `sms_logs`
  ADD INDEX IF NOT EXISTS `idx_sms_status_created` (`status`, `created_at`);

-- Audit trail / System Logs: newest first, per-user, date-range (used again in Step 8)
ALTER TABLE `activity_logs`
  ADD INDEX IF NOT EXISTS `idx_act_created`      (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_act_user_created` (`user_id`, `created_at`);

-- Optional: refresh optimizer statistics
ANALYZE TABLE `appointments`, `inventory`, `patients`, `child_immunization_records`, `maternal_records`,
              `consultations`, `dental_records`, `dispensations`, `sms_logs`, `activity_logs`;
