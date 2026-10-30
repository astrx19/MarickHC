-- DENTAL MODULE REDESIGN. Optional: the page creates these three tables itself the first time it loads.
-- Your existing dental_records table is NOT changed (patient profile, patient portal and dashboard still read it).
CREATE TABLE IF NOT EXISTS dental_charts (
  id INT NOT NULL AUTO_INCREMENT,
  patient_id INT NOT NULL,
  occupation VARCHAR(100) DEFAULT NULL,
  guardian_name VARCHAR(150) DEFAULT NULL,
  philhealth_no VARCHAR(50) DEFAULT NULL,
  sectors LONGTEXT DEFAULT NULL,
  medical_history LONGTEXT DEFAULT NULL,
  consent_privacy TINYINT(1) NOT NULL DEFAULT 0,
  consent_waiver TINYINT(1) NOT NULL DEFAULT 0,
  consent_name VARCHAR(150) DEFAULT NULL,
  consent_date DATE DEFAULT NULL,
  dental_history LONGTEXT DEFAULT NULL,
  clinical_findings LONGTEXT DEFAULT NULL,
  oral_health LONGTEXT DEFAULT NULL,
  odontogram LONGTEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  updated_by INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dental_chart_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dental_caries_assessments (
  id INT NOT NULL AUTO_INCREMENT,
  chart_id INT NOT NULL,
  patient_id INT NOT NULL,
  age_group VARCHAR(10) NOT NULL,
  assessed_on DATE NOT NULL,
  answers LONGTEXT DEFAULT NULL,
  disease_result VARCHAR(10) DEFAULT NULL,
  socio_result VARCHAR(10) DEFAULT NULL,
  protective_result VARCHAR(10) DEFAULT NULL,
  overall_computed VARCHAR(10) DEFAULT NULL,
  overall_final VARCHAR(10) DEFAULT NULL,
  assessed_by INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_caries_patient (patient_id, assessed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dental_treatments (
  id INT NOT NULL AUTO_INCREMENT,
  chart_id INT NOT NULL,
  patient_id INT NOT NULL,
  treatment_date DATE NOT NULL,
  routine_codes VARCHAR(100) DEFAULT NULL,
  other_codes VARCHAR(100) DEFAULT NULL,
  tooth_numbers VARCHAR(100) DEFAULT NULL,
  referrals VARCHAR(255) DEFAULT NULL,
  remarks TEXT DEFAULT NULL,
  dentist_id INT DEFAULT NULL,
  dental_record_id INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_treat_patient (patient_id, treatment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
