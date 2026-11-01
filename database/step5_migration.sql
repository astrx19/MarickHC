-- =====================================================================
-- STEP 5 - ADDITIONAL PATIENT CONTACTS  (Marick Health Center)
-- Run once in phpMyAdmin (SQL tab). Safe to run again (IF NOT EXISTS).
--
-- The page also creates this table by itself the first time it is needed
-- (includes/patient_profile_helper.php), so running this file is optional -
-- it just lets you do it on purpose / review the structure.
-- Nothing existing is changed.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `patient_contacts` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `patient_id`     INT(11)      NOT NULL,
  `contact_name`   VARCHAR(100) NOT NULL,
  `relationship`   VARCHAR(50)  DEFAULT NULL,      -- e.g. Daughter, Mother, Guardian
  `contact_number` VARCHAR(20)  NOT NULL,          -- stored as +639XXXXXXXXX
  `created_by`     INT(11)      DEFAULT NULL,      -- staff.id who added it
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pc_patient` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
