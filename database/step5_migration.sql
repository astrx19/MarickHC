CREATE TABLE IF NOT EXISTS `patient_contacts` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `patient_id`     INT(11)      NOT NULL,
  `contact_name`   VARCHAR(100) NOT NULL,
  `relationship`   VARCHAR(50)  DEFAULT NULL,
  `contact_number` VARCHAR(20)  NOT NULL,
  `created_by`     INT(11)      DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pc_patient` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
