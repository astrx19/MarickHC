-- =====================================================================
-- Unified password_resets table (email link tokens + SMS OTPs)
-- Run once in phpMyAdmin (Hostinger > Databases > phpMyAdmin > SQL tab).
--
-- The old table held plaintext 1-hour tokens only, so dropping it is safe:
-- any pending reset links simply stop working and users request a new one.
--
-- Requested columns are all here. Three extra columns are deliberate:
--   user_type + user_id : this project has TWO login tables (staff, patients)
--                         and some accounts share a mobile number / email, so
--                         identifier alone can't say WHICH account to reset.
--   attempts            : a 6-digit OTP has only 1,000,000 possibilities, so
--                         wrong guesses must be counted and capped.
--   ip_address          : lets the backend rate-limit SMS/email sends.
-- =====================================================================
DROP TABLE IF EXISTS `password_resets`;

CREATE TABLE `password_resets` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `identifier`   VARCHAR(255) NOT NULL COMMENT 'email (method=email) or normalised mobile 09XXXXXXXXX (method=sms)',
  `token_or_otp` VARCHAR(255) NOT NULL COMMENT 'sha256 of the emailed token, or bcrypt hash of the 6-digit OTP',
  `method`       ENUM('email','sms') NOT NULL,
  `user_type`    ENUM('patient','staff') NOT NULL,
  `user_id`      INT NOT NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ip_address`   VARCHAR(45) DEFAULT NULL,
  `expires_at`   DATETIME NOT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token`      (`token_or_otp`),
  KEY `idx_account`    (`user_type`,`user_id`),
  KEY `idx_ip_created` (`ip_address`,`created_at`),
  KEY `idx_expires`    (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
