-- =====================================================================
-- DENTAL REDESIGN - PART 2 (tooth chart history)   Marick Health Center
-- Optional: the Dental page creates this table by itself the first time it opens
-- (includes/dental_helper.php -> mh_dental_ensure_snapshot_table). Safe to run twice.
-- Existing charts get a baseline snapshot automatically the first time their history is opened.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `dental_odontogram_snapshots` (
  `id`               INT(11)     NOT NULL AUTO_INCREMENT,
  `patient_id`       INT(11)     NOT NULL,
  `chart_id`         INT(11)     DEFAULT NULL,
  `dental_record_id` INT(11)     DEFAULT NULL,           -- the visit that changed the chart (NULL = chart saved from the profile)
  `source`           VARCHAR(20) NOT NULL DEFAULT 'profile',   -- 'profile' | 'visit'
  `content_hash`     CHAR(32)    NOT NULL,               -- md5 of the chart, so an unchanged chart never makes a new snapshot
  `odontogram`       LONGTEXT    NOT NULL,               -- {"16":{"c":"carious","s":""}, ...}
  `taken_by`         INT(11)     DEFAULT NULL,
  `taken_at`         DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snap_patient` (`patient_id`, `taken_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
