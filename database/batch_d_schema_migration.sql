ALTER TABLE staff
  ADD COLUMN IF NOT EXISTS status TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS patient_claim_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_patient_time (patient_id, created_at),
  KEY idx_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(10) NOT NULL,
  identifier VARCHAR(100) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acct_time (scope, identifier, attempted_at),
  KEY idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE dental_records ADD COLUMN IF NOT EXISTS prescription TEXT NULL DEFAULT NULL;
