ALTER TABLE `appointments`
  ADD COLUMN `queue_number` INT NULL AFTER `appointment_time`,
  ADD UNIQUE KEY `uq_queue_slot` (`department`, `appointment_date`, `queue_number`);

UPDATE `appointments` a
JOIN (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY department ORDER BY id) AS rn
      FROM `appointments`
     WHERE appointment_date = CURDATE() AND reason = 'Walk-In Queue Registration' AND queue_number IS NULL
) t ON t.id = a.id
SET a.queue_number = t.rn;
