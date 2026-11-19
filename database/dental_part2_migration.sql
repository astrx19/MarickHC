CREATE TABLE IF NOT EXISTS `dental_odontogram_snapshots` (
  `id`               INT(11)     NOT NULL AUTO_INCREMENT,
  `patient_id`       INT(11)     NOT NULL,
  `chart_id`         INT(11)     DEFAULT NULL,
  `dental_record_id` INT(11)     DEFAULT NULL,
  `source`           VARCHAR(20) NOT NULL DEFAULT 'profile',
  `content_hash`     CHAR(32)    NOT NULL,
  `odontogram`       LONGTEXT    NOT NULL,
  `taken_by`         INT(11)     DEFAULT NULL,
  `taken_at`         DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snap_patient` (`patient_id`, `taken_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
