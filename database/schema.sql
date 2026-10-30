-- ==========================================================
-- MARICK HEALTH CENTER - ULTIMATE MASTER DATABASE SCHEMA V3.1
-- Clean, consolidated deployment script (No duplicates/overlaps)
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `marick_health_db`;
USE `marick_health_db`;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- 1. STAFF / USERS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `staff`;
CREATE TABLE `staff` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(50) NOT NULL,
  `middle_name` VARCHAR(50) NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `suffix` VARCHAR(20) NULL,
  `birthdate` DATE NULL,
  `contact_number` VARCHAR(20) NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) NOT NULL COMMENT 'admin, doctor, bhw, midwife, dental',
  `is_first_login` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 2. PATIENTS TABLE 
-- --------------------------------------------------------
DROP TABLE IF EXISTS `patients`;
CREATE TABLE `patients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(50) NOT NULL,
  `middle_name` VARCHAR(50) NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `suffix` VARCHAR(10) NULL,
  `birthdate` DATE NOT NULL,
  `sex` VARCHAR(10) NOT NULL COMMENT 'Male or Female',
  `contact_number` VARCHAR(20) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `address_type` VARCHAR(20) NULL,
  `address` TEXT NULL COMMENT 'Full address or Street Name',
  `philhealth_no` VARCHAR(50) NULL,
  `family_no` VARCHAR(50) NULL,
  `status` INT DEFAULT 1 COMMENT '1=Active, 0=Archived',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 3. APPOINTMENTS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `department` VARCHAR(100) NOT NULL,
  `reason` TEXT NULL,
  `appointment_date` DATE NOT NULL,
  `appointment_time` TIME NOT NULL,
  `status` VARCHAR(20) DEFAULT 'Pending' COMMENT 'Pending, Approved, Completed, Cancelled, Rejected',
  `admin_remarks` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 4. GENERAL CONSULTATIONS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `consultations`;
CREATE TABLE `consultations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `doctor_id` INT NOT NULL,
    `visit_date` DATE NOT NULL,
    `weight` VARCHAR(20) NULL,
    `height` VARCHAR(20) NULL,
    `temp` VARCHAR(20) NULL,
    `bp` VARCHAR(20) NULL,
    `chief_complaints` TEXT NULL,
    `diagnosis` TEXT NULL,
    `prescription` TEXT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 5. DENTAL RECORDS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `dental_records`;
CREATE TABLE `dental_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `dentist_id` INT NOT NULL,
    `visit_date` DATE NOT NULL,
    `procedure_done` VARCHAR(255) NOT NULL,
    `tooth_number` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`dentist_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 6. MATERNAL & PRENATAL RECORDS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `maternal_records`;
CREATE TABLE `maternal_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `midwife_id` INT NOT NULL,
    `visit_date` DATE NOT NULL,
    `gravida` VARCHAR(50) NULL,
    `para` VARCHAR(50) NULL,
    `lmp` DATE NULL,
    `edc` DATE NULL,
    `aog` VARCHAR(50) NULL,
    `blood_pressure` VARCHAR(50) NULL,
    `weight` VARCHAR(20) NULL,
    `height` VARCHAR(20) NULL,
    `bmi` VARCHAR(20) NULL,
    `fh_fht` VARCHAR(100) NULL,
    `tt1_date` DATE NULL,
    `tt2_date` DATE NULL,
    `tt3_date` DATE NULL,
    `tt4_date` DATE NULL,
    `tt5_date` DATE NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`midwife_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 7. CHILD IMMUNIZATION MASTER RECORDS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `child_consultation_logs`;
DROP TABLE IF EXISTS `child_immunization_records`;
CREATE TABLE `child_immunization_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `attending_staff_id` INT NULL,
    `family_no` VARCHAR(50) NULL,
    `others_header` VARCHAR(255) NULL,
    `newborn_screening_date` DATE NULL,
    `tt_of_mother_date` DATE NULL,
    `birth_time` TIME NULL,
    `birth_weight` VARCHAR(50) NULL,
    `birth_height` VARCHAR(50) NULL,
    `birth_place` VARCHAR(255) NULL,
    `type_of_delivery` VARCHAR(100) NULL,
    `place_of_delivery` VARCHAR(255) NULL,
    `type_of_breastfeeding` VARCHAR(100) NULL,
    `mother_name` VARCHAR(255) NULL,
    `mother_bday` DATE NULL,
    `mother_age` INT NULL,
    `father_name` VARCHAR(255) NULL,
    `father_bday` DATE NULL,
    `father_age` INT NULL,
    `attending_physician` VARCHAR(255) NULL,
    `no_of_children` INT NULL,
    `contact_no` VARCHAR(50) NULL,
    -- Specific Immunization Dates
    `bcg_date` DATE NULL,
    `hep_b_24hrs_date` DATE NULL,
    `penta_opv_3_date` DATE NULL,
    `penta_1_opv_1_date` DATE NULL,
    `penta_2_opv_2_date` DATE NULL,
    `pcv_1_date` DATE NULL,
    `pcv_2_date` DATE NULL,
    `pcv_3_date` DATE NULL,
    `others_immunization` VARCHAR(255) NULL,
    `mr_date` DATE NULL,
    `mmr_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`attending_staff_id`) REFERENCES `staff`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 8. GENERAL IMMUNIZATION LOGS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `immunization_records`;
CREATE TABLE `immunization_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `health_worker_id` INT NOT NULL,
    `vaccine_name` VARCHAR(100) NOT NULL,
    `dose_number` VARCHAR(50) NULL,
    `date_administered` DATE NOT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`health_worker_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 9. CHILD CONSULTATION LOGS TABLE
-- --------------------------------------------------------
CREATE TABLE `child_consultation_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `child_record_id` INT NOT NULL,
    `consultation_date` DATE NOT NULL,
    `weight` VARCHAR(50) NULL,
    `height` VARCHAR(50) NULL,
    `temp` VARCHAR(50) NULL,
    `chief_complaints` TEXT NULL,
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`child_record_id`) REFERENCES `child_immunization_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 10. INVENTORY MANAGEMENT TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_name` VARCHAR(100) NOT NULL COMMENT 'Generic name & strength, e.g. Losartan 50mg',
    `brand_name` VARCHAR(255) NULL COMMENT 'Brand variant, e.g. Cozaar. NULL/blank = generic/unbranded',
    `category` VARCHAR(50) NOT NULL,
    `tracking_code` VARCHAR(100) NULL COMMENT 'System-generated SKU for this batch',
    `batch_number` VARCHAR(50) NULL COMMENT 'Manufacturer lot / batch number',
    `quantity` INT NOT NULL DEFAULT 0 COMMENT 'Remaining stock in base units (tablets, mL, pcs, etc.)',
    `unit` VARCHAR(20) NOT NULL COMMENT 'Base unit label, e.g. tablet, capsule, mL, piece',
    -- ---------------------------------------------------------------
    -- Multi-unit packaging breakdown (Box -> Pack/Blister/Banig -> Base Unit).
    -- All NULL/0 means "no packaging structure recorded" and the item is
    -- tracked purely in base units (e.g. a vial, a single piece of equipment).
    -- ---------------------------------------------------------------
    `units_per_pack` INT NULL DEFAULT NULL COMMENT 'Base units per intermediate pack (e.g. tablets per blister/banig)',
    `pack_label` VARCHAR(40) NULL DEFAULT 'Blister Pack' COMMENT 'Display label for the intermediate pack, e.g. Blister Pack, Banig, Vial',
    `packs_per_box` INT NULL DEFAULT NULL COMMENT 'Intermediate packs per box/carton',
    `box_label` VARCHAR(40) NULL DEFAULT 'Box' COMMENT 'Display label for the outer packaging, e.g. Box, Carton',
    `expiry_date` DATE NULL,
    `status` INT DEFAULT 1 COMMENT '1=Active, 0=Archived',
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 11. DISPENSATIONS LOG TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `dispensations`;
CREATE TABLE `dispensations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `inventory_id` INT NOT NULL COMMENT 'Specific batch (inventory row) dispensed from',
    `patient_id` INT NOT NULL,
    `dispensed_by` INT NULL COMMENT 'Staff who processed the dispensation',
    `quantity` INT NOT NULL,
    `is_fefo_override` TINYINT(1) DEFAULT 0 COMMENT '1 = staff manually picked a later-expiring batch while an earlier batch still had stock',
    `dispense_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`inventory_id`) REFERENCES `inventory`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`dispensed_by`) REFERENCES `staff`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 12. HOLIDAYS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `holidays`;
CREATE TABLE `holidays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `holiday_date` DATE NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 13. SYSTEM ACTIVITY LOGS TABLE
-- --------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `user_role` VARCHAR(50) NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;