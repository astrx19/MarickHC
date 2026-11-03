-- =====================================================================
-- STEP 7 - EXPIRED MEDICINE DISPOSAL LOG   (Marick Health Center)
-- Optional: the Supply Masterlist creates this table by itself the first time it needs it.
-- Safe to run twice. Nothing existing is changed.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `inventory_disposals` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `inventory_id`      INT(11)      DEFAULT NULL,            -- the batch (kept even after it is archived)
  `item_name`         VARCHAR(100) NOT NULL,                -- item details are COPIED so the record stays readable forever
  `brand_name`        VARCHAR(255) DEFAULT NULL,
  `category`          VARCHAR(50)  DEFAULT NULL,
  `tracking_code`     VARCHAR(100) DEFAULT NULL,
  `batch_number`      VARCHAR(50)  DEFAULT NULL,
  `item_form`         VARCHAR(10)  NOT NULL DEFAULT 'piece',-- liquid | capsule | piece
  `unit`              VARCHAR(20)  NOT NULL,
  `expiry_date`       DATE         DEFAULT NULL,
  `quantity_before`   INT(11)      NOT NULL,
  `quantity_disposed` INT(11)      NOT NULL,
  `quantity_after`    INT(11)      NOT NULL,
  `reason`            VARCHAR(20)  NOT NULL DEFAULT 'expired', -- expired | damaged | recalled | other
  `method`            VARCHAR(80)  NOT NULL,
  `witness`           VARCHAR(100) DEFAULT NULL,
  `remarks`           VARCHAR(500) DEFAULT NULL,
  `disposed_on`       DATE         NOT NULL,
  `disposed_by`       INT(11)      DEFAULT NULL,            -- staff.id
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disp_date` (`disposed_on`),
  KEY `idx_disp_inventory` (`inventory_id`),
  KEY `idx_disp_reason` (`reason`, `disposed_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
