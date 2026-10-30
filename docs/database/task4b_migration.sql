-- TASK 4b: departments moved into System Settings, Vital Signs removed.  Run once in phpMyAdmin (safe to re-run).

-- Departments: function per department (the app also creates this table by itself)
CREATE TABLE IF NOT EXISTS department_settings (
  dept_name VARCHAR(100) NOT NULL,
  module_key VARCHAR(30) NOT NULL DEFAULT '',
  description VARCHAR(255) DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dept_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO department_settings (dept_name, module_key) VALUES
 ('General Consultation','checkups'),('Dental Clinic','dental'),
 ('Maternal Care','maternal_care'),('Child Immunization','immunization');

-- Remove the Vital Signs and standalone Departments permission toggles
DELETE FROM role_permissions WHERE permission_key IN ('log_vital_signs', 'manage_departments');

-- Optional: delete the vital signs records you entered while testing (uncomment to run)
-- DROP TABLE IF EXISTS vital_signs;
