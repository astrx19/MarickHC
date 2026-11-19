-- Allow family members (parent / children / siblings) to share one email on `patients`.
-- Safe to run more than once. Back up first.
SET @idx := (SELECT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
                AND COLUMN_NAME = 'email' AND NON_UNIQUE = 0 AND SEQ_IN_INDEX = 1
                AND INDEX_NAME <> 'PRIMARY'
              LIMIT 1);
SET @sql := IF(@idx IS NULL, 'SELECT "patients.email is already non-unique" AS info',
               CONCAT('ALTER TABLE `patients` DROP INDEX `', @idx, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- keep email lookups fast (skipped if a plain index already exists)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
                AND COLUMN_NAME = 'email' AND SEQ_IN_INDEX = 1);
SET @sql := IF(@has = 0, 'ALTER TABLE `patients` ADD INDEX `idx_patients_email` (`email`)', 'SELECT "email index exists" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
