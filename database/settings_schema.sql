-- ==========================================================
-- MARICK HEALTH CENTER - MASTER SYSTEM SETTINGS
-- Additive migration. Safe to run on an existing database:
-- it only CREATEs IF NOT EXISTS and INSERTs IGNORE.
--
-- Run once:  mysql -u root marick_health_db < database/settings_schema.sql
-- (admin/system_settings.php also self-installs these tables on first
--  load, so running this by hand is optional.)
-- ==========================================================

USE `marick_health_db`;

-- ----------------------------------------------------------
-- A. KEY/VALUE SETTINGS (branding, theme colours, feature flags)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(64) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_by`    INT NULL,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------
-- B. DYNAMIC MASTERLISTS / DROPDOWN OPTIONS
--    One row per selectable option, grouped by `list_key`.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_options` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `list_key`      VARCHAR(50)  NOT NULL COMMENT 'streets, delivery_types, ...',
  `option_value`  VARCHAR(150) NOT NULL COMMENT 'text stored in the record',
  `option_label`  VARCHAR(150) NULL     COMMENT 'display text; falls back to option_value',
  `sort_order`    INT DEFAULT 0,
  `is_active`     TINYINT(1) DEFAULT 1,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_list_value` (`list_key`, `option_value`),
  KEY `idx_list_active` (`list_key`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------
-- C. DEFAULT SETTINGS
-- ----------------------------------------------------------
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('system_name',            'Barangay Marick Health Center'),
  ('header_title',           'Barangay Marick Health Center'),
  ('header_tagline',         'Health Records Management System'),
  ('hero_title',             'Quality Healthcare for the Marick Community.'),
  ('hero_subtitle',          'Providing accessible, compassionate, and comprehensive medical services to ensure the well-being of every resident in our barangay.'),
  ('global_announcement',    ''),
  ('enable_online_booking',  '1'),
  ('logo_path',              'assets/images/logo.png'),
  ('background_path',        'assets/images/barangay_bg.png'),
  ('ocr_model',              'gemini-2.0-flash'),
  ('gemini_api_key',         ''),
  ('sms_provider',           'semaphore'),
  ('android_gateway_url',    ''),
  ('android_gateway_key',    ''),
  -- theme
  ('theme_primary',          '#4ca1af'),
  ('theme_primary_hover',    '#3b828e'),
  ('theme_accent',           '#38bdf8'),
  ('theme_sidebar_bg',       '#0f172a'),
  ('theme_sidebar_hover',    '#1e293b'),
  ('theme_sidebar_text',     '#cbd5e1'),
  ('theme_page_bg',          '#f1f5f9'),
  ('theme_panel_bg',         '#ffffff'),
  ('theme_body_text',        '#334155'),
  ('theme_heading_text',     '#1e293b'),
  ('theme_link',             '#0ea5e9'),
  ('theme_button_bg',        '#4ca1af'),
  ('theme_button_text',      '#ffffff'),
  ('theme_border',           '#e2e8f0');

-- ----------------------------------------------------------
-- D. DEFAULT MASTERLIST OPTIONS
-- ----------------------------------------------------------
INSERT IGNORE INTO `system_options` (`list_key`, `option_value`, `sort_order`) VALUES
  -- Streets / Purok inside Marick Subdivision
  ('streets','Aida',10),('streets','Cattleya',20),('streets','Cora',30),
  ('streets','Daffodil',40),('streets','Delia',50),('streets','Emma',60),
  ('streets','Flora',70),('streets','Gracia',80),('streets','Irma',90),
  ('streets','Jaguar',100),('streets','Joey',110),('streets','Leonard',120),
  ('streets','Ritchie',130),('streets','Vicenta',140),

  -- Clinic departments (appointments)
  ('clinic_departments','General Consultation',10),
  ('clinic_departments','Dental Clinic',20),
  ('clinic_departments','Maternal Care',30),
  ('clinic_departments','Child Immunization',40),

  -- Inventory categories
  ('inv_categories','Medicine',10),
  ('inv_categories','Vaccine',20),
  ('inv_categories','Medical Supply',30),
  ('inv_categories','Equipment',40),

  -- Type of delivery (immunization / maternal forms)
  ('delivery_types','Normal Spontaneous Delivery',10),
  ('delivery_types','Caesarean Section',20),
  ('delivery_types','Assisted Breech',30),
  ('delivery_types','Forceps Delivery',40),
  ('delivery_types','Vacuum Extraction',50),

  -- Type of breastfeeding
  ('breastfeeding_types','Exclusive Breastfeeding',10),
  ('breastfeeding_types','Mixed Feeding',20),
  ('breastfeeding_types','Formula Feeding',30),

  -- Vaccine categories
  ('vaccine_categories','BCG',10),
  ('vaccine_categories','Hepatitis B',20),
  ('vaccine_categories','Pentavalent',30),
  ('vaccine_categories','Oral Polio Vaccine (OPV)',40),
  ('vaccine_categories','Inactivated Polio Vaccine (IPV)',50),
  ('vaccine_categories','Pneumococcal Conjugate (PCV)',60),
  ('vaccine_categories','Measles-Rubella (MR)',70),
  ('vaccine_categories','MMR',80),
  ('vaccine_categories','Tetanus Toxoid',90),

  -- Place of delivery / birth place
  ('birth_places','Hospital',10),
  ('birth_places','Lying-in Clinic',20),
  ('birth_places','Barangay Health Center',30),
  ('birth_places','Home',40),

  -- Address jurisdiction
  ('address_types','Marick',10),
  ('address_types','Outside Marick',20);