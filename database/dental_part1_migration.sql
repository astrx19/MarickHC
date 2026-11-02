-- =====================================================================
-- DENTAL REDESIGN - PART 1 (visit-based records)   Marick Health Center
-- Optional: the Dental page adds these columns by itself the first time it opens
-- (includes/dental_helper.php -> mh_dental_ensure_visit_columns). Run it to do it on purpose.
-- MariaDB 10.1+ (IF NOT EXISTS). Nothing is dropped or rewritten.
-- =====================================================================
ALTER TABLE `dental_records`
  ADD COLUMN IF NOT EXISTS `chief_complaint` VARCHAR(255) NULL AFTER `procedure_done`,
  ADD COLUMN IF NOT EXISTS `diagnosis`       VARCHAR(255) NULL AFTER `chief_complaint`,
  ADD COLUMN IF NOT EXISTS `referral`        VARCHAR(255) NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `next_visit_date` DATE NULL AFTER `referral`,
  ADD COLUMN IF NOT EXISTS `bp`              VARCHAR(20) NULL AFTER `next_visit_date`,
  ADD COLUMN IF NOT EXISTS `temp`            VARCHAR(10) NULL AFTER `bp`;

ALTER TABLE `dental_charts`
  ADD COLUMN IF NOT EXISTS `profile_completed_at` DATETIME NULL AFTER `odontogram`;

-- Charts already filled in before this feature count as "initial profile completed".
UPDATE `dental_charts` SET `profile_completed_at` = COALESCE(`updated_at`, `created_at`)
 WHERE `profile_completed_at` IS NULL AND `medical_history` IS NOT NULL AND `medical_history` <> '';

ALTER TABLE `dental_records` ADD INDEX IF NOT EXISTS `idx_dental_next_visit` (`next_visit_date`);
