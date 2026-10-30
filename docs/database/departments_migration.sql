-- TASK 4: dynamic departments. Run once in phpMyAdmin (the page also creates the table itself if you skip this).
CREATE TABLE IF NOT EXISTS department_settings (
  dept_name VARCHAR(100) NOT NULL,
  module_key VARCHAR(30) NOT NULL DEFAULT '',
  description VARCHAR(255) DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dept_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Explicit module links for the four existing departments (behaviour unchanged)
INSERT IGNORE INTO department_settings (dept_name, module_key) VALUES
 ('General Consultation','checkups'),('Dental Clinic','dental'),
 ('Maternal Care','maternal_care'),('Child Immunization','immunization');

-- Admin role gets the new "Department Management" toggle (Health Head always has it)
INSERT IGNORE INTO role_permissions (role_key, permission_key, allowed) VALUES ('admin','manage_departments',1);
