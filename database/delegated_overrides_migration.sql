-- =====================================================================
-- MARICK HEALTH CENTER - STRICT DELEGATED FUNCTIONS (grant / deny overrides)
-- Run once after roles_permissions_migration.sql. Safe to re-run. BACK UP FIRST.
--
-- staff.can_* flags become TRI-STATE:
--     NULL = inherit what the person's ROLE allows (Staff Roles & Permissions matrix)
--     1    = explicitly granted
--     0    = explicitly DENIED (removes a function the role would otherwise give)
-- Existing 1s stay grants; existing 0s ("not delegated") become NULL, so nobody gains or
-- loses access when this runs. (admin/staff_management.php also applies this automatically
-- the first time it is opened, so running the file by hand is optional.)
-- =====================================================================
USE `marick_health_db`;

ALTER TABLE `staff`
  MODIFY `can_manage_supply_masterlist`  TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_manage_dispensing`         TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_view_dispensing_history`   TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_log_consultations`         TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_view_consultation_history` TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_log_immunization`          TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_view_immunization_records` TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_log_maternal`              TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_view_maternal_history`     TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_log_dental`                TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_view_dental_history`       TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_view_queue`                TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_manage_queue`              TINYINT(1) NULL DEFAULT NULL,
  MODIFY `can_view_area_masterlist`      TINYINT(1) NULL DEFAULT NULL;

UPDATE `staff` SET
  `can_manage_supply_masterlist`  = NULLIF(`can_manage_supply_masterlist`, 0),
  `can_manage_dispensing`         = NULLIF(`can_manage_dispensing`, 0),
  `can_view_dispensing_history`   = NULLIF(`can_view_dispensing_history`, 0),
  `can_log_consultations`         = NULLIF(`can_log_consultations`, 0),
  `can_view_consultation_history` = NULLIF(`can_view_consultation_history`, 0),
  `can_log_immunization`          = NULLIF(`can_log_immunization`, 0),
  `can_view_immunization_records` = NULLIF(`can_view_immunization_records`, 0),
  `can_log_maternal`              = NULLIF(`can_log_maternal`, 0),
  `can_view_maternal_history`     = NULLIF(`can_view_maternal_history`, 0),
  `can_log_dental`                = NULLIF(`can_log_dental`, 0),
  `can_view_dental_history`       = NULLIF(`can_view_dental_history`, 0),
  `can_view_queue`                = NULLIF(`can_view_queue`, 0),
  `can_manage_queue`              = NULLIF(`can_manage_queue`, 0),
  `can_view_area_masterlist`      = NULLIF(`can_view_area_masterlist`, 0);
