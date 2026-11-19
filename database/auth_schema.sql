ALTER TABLE `staff`
    ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) NULL UNIQUE AFTER `email`;

ALTER TABLE `patients`
    ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) NULL UNIQUE AFTER `email`;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(255) NOT NULL,
    `token`      VARCHAR(255) NOT NULL,
    `user_type`  ENUM('patient','staff') NOT NULL DEFAULT 'patient',
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_token` (`token`),
    KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
