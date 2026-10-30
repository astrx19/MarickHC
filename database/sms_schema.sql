-- =========================================================================
-- MARICK HEALTH CENTER - SMS FOLLOW-UP REMINDERS
-- =========================================================================
-- Run this once against marick_health_db (phpMyAdmin > Import, or:
--   mysql -u root marick_health_db < database/sms_schema.sql
-- ).
--
-- Everything here is written with IF NOT EXISTS / a guarded ADD COLUMN so
-- it is safe to run more than once.
-- =========================================================================

-- -------------------------------------------------------------------------
-- 1. Follow-up date on each clinic module's record table
-- -------------------------------------------------------------------------
-- (includes/sms/SemaphoreSmsClient.php's mh_sms_install() and the app
--  itself never touch these ALTER statements - they are schema changes
--  and belong in a migration, run once by hand.)

ALTER TABLE `consultations`
    ADD COLUMN IF NOT EXISTS `next_visit_date` DATE NULL AFTER `notes`;

ALTER TABLE `maternal_records`
    ADD COLUMN IF NOT EXISTS `next_visit_date` DATE NULL AFTER `notes`;

ALTER TABLE `child_immunization_records`
    ADD COLUMN IF NOT EXISTS `next_visit_date` DATE NULL AFTER `mmr_date`;

-- -------------------------------------------------------------------------
-- 2. scheduled_sms - the outbound queue
-- -------------------------------------------------------------------------
-- One row per reminder to send. A single follow-up date normally
-- produces TWO rows here (day-before + same-day), see
-- includes/sms/sms_scheduler.php.

CREATE TABLE IF NOT EXISTS `scheduled_sms` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id`       INT NOT NULL,
    `phone_number`     VARCHAR(20) NOT NULL COMMENT '09XXXXXXXXX, normalized before insert',
    `message`          TEXT NOT NULL,
    `scheduled_date`   DATE NOT NULL COMMENT 'The date this reminder should go out',
    `status`           ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `response_log`     TEXT NULL COMMENT 'Raw Semaphore API response or error message',
    `source_module`    VARCHAR(50) NULL COMMENT 'e.g. Check-up, Immunization, Prenatal Care',
    `source_record_id` INT NULL COMMENT 'consultations.id / maternal_records.id / child_immunization_records.id',
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `sent_at`          TIMESTAMP NULL,
    KEY `idx_status_date` (`status`, `scheduled_date`),
    KEY `idx_patient` (`patient_id`),
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------
-- 3. sms_logs - permanent record of every send attempt
-- -------------------------------------------------------------------------
-- Written by cron/send_daily_reminders.php AND by the "Run Pending SMS
-- Queue Now" manual trigger on admin/sms_dashboard.php, so admins can see
-- what actually went out and why anything failed.

CREATE TABLE IF NOT EXISTS `sms_logs` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `scheduled_sms_id` INT NULL,
    `patient_id`       INT NULL,
    `phone_number`     VARCHAR(20) NOT NULL,
    `message`          TEXT NOT NULL,
    `status`           ENUM('sent','failed') NOT NULL,
    `response_log`     TEXT NULL,
    `triggered_by`     VARCHAR(20) NOT NULL DEFAULT 'cron' COMMENT '"cron" or "manual"',
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_created` (`created_at`),
    FOREIGN KEY (`scheduled_sms_id`) REFERENCES `scheduled_sms`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;