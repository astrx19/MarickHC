ALTER TABLE `appointments`
  ADD INDEX IF NOT EXISTS `idx_apt_date_status` (`appointment_date`, `status`);

ALTER TABLE `inventory`
  ADD INDEX IF NOT EXISTS `idx_inv_status_expiry`   (`status`, `expiry_date`),
  ADD INDEX IF NOT EXISTS `idx_inv_status_quantity` (`status`, `quantity`);

ALTER TABLE `patients`
  ADD INDEX IF NOT EXISTS `idx_patients_status_created` (`status`, `created_at`);

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

ALTER TABLE `dispensations`
  ADD INDEX IF NOT EXISTS `idx_disp_date` (`dispense_date`);

ALTER TABLE `sms_logs`
  ADD INDEX IF NOT EXISTS `idx_sms_status_created` (`status`, `created_at`);

ALTER TABLE `activity_logs`
  ADD INDEX IF NOT EXISTS `idx_act_created`      (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_act_user_created` (`user_id`, `created_at`);

ANALYZE TABLE `appointments`, `inventory`, `patients`, `child_immunization_records`, `maternal_records`,
              `consultations`, `dental_records`, `dispensations`, `sms_logs`, `activity_logs`;
