-- =====================================================================
-- Patient duplicate protection - run in phpMyAdmin (safe to run once).
-- =====================================================================

-- 1) Index that makes the First+Last+Birthdate lookup fast AND lets the registration
--    transaction lock the matching rows (SELECT ... FOR UPDATE) so two staff members
--    can't register the same person at the same moment. NOT unique on purpose: your
--    current data already contains look-alike rows (see query 2) and a UNIQUE key would fail.
ALTER TABLE `patients` ADD INDEX `idx_patients_identity` (`last_name`, `first_name`, `birthdate`);
ALTER TABLE `patients` ADD INDEX `idx_patients_contact` (`contact_number`);

-- 2) Existing duplicates (same first + last name + birthdate). Review, then archive/merge by hand.
SELECT p.id, p.first_name, p.last_name, p.birthdate, p.contact_number, p.email, p.username, p.status, p.created_at
FROM patients p
JOIN (SELECT last_name, first_name, birthdate FROM patients
      GROUP BY last_name, first_name, birthdate HAVING COUNT(*) > 1) d
  ON d.last_name = p.last_name AND d.first_name = p.first_name AND d.birthdate = p.birthdate
ORDER BY p.last_name, p.first_name, p.birthdate, p.id;

-- 3) Probable duplicates with a mistyped birthdate (same name + same mobile, different birthdate).
SELECT a.id AS id_a, b.id AS id_b, a.first_name, a.last_name,
       a.birthdate AS birthdate_a, b.birthdate AS birthdate_b, a.contact_number
FROM patients a
JOIN patients b ON a.id < b.id
 AND a.first_name = b.first_name AND a.last_name = b.last_name
 AND RIGHT(REPLACE(a.contact_number,'+',''), 10) = RIGHT(REPLACE(b.contact_number,'+',''), 10)
 AND a.birthdate <> b.birthdate;
