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

INSERT INTO role_permissions (role_key, permission_key, allowed)
SELECT r.role_key, 'log_vital_signs',
       IF(r.role_key IN ('health_head','admin','doctor','midwife','dental','bhw'), 1, 0)
FROM system_roles r
ON DUPLICATE KEY UPDATE allowed = allowed;
