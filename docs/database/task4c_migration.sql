-- TASK 4c: who can use each custom department. Optional - the app creates these tables itself on first use.
CREATE TABLE IF NOT EXISTS department_access (
  dept_name VARCHAR(100) NOT NULL,
  role_key VARCHAR(50) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (dept_name, role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_department_access (
  staff_id INT NOT NULL,
  dept_name VARCHAR(100) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (staff_id, dept_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
