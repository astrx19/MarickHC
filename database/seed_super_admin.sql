-- =====================================================================
-- MARICK HEALTH SYSTEM v2 - IDEMPOTENT SUPER ADMIN SEED (alternative to bin/create_admin.php)
-- =====================================================================
-- NO password is stored in this file. Generate a bcrypt hash first, on the server:
--
--     php -r "echo password_hash(readline('Password: '), PASSWORD_BCRYPT, ['cost'=>12]), PHP_EOL;"
--
-- then paste it below and run (from the mysql CLI, never through a web page):
--
--     mysql -u <user> -p marick_health_db < database/seed_super_admin.sql
--
-- Safe to re-run: it inserts NOTHING if a super_admin already exists, if the
-- hash is not a valid bcrypt string, or if the username/email is taken.
-- The account is created with is_first_login = 1 (must change password at first login).
-- =====================================================================

SET @sa_username = 'CHANGE_ME_USERNAME';          -- 4-50 chars, NOT 'admin'
SET @sa_email    = 'CHANGE_ME@example.org';
SET @sa_first    = 'Super';
SET @sa_last     = 'Admin';
SET @sa_hash     = 'PASTE_BCRYPT_HASH_HERE';      -- must look like $2y$12$....(60 chars)

INSERT INTO `staff`
    (`first_name`, `last_name`, `contact_number`, `email`, `username`, `password`, `role`, `is_first_login`)
SELECT @sa_first, @sa_last, '', @sa_email, @sa_username, @sa_hash, 'super_admin', 1
FROM DUAL
WHERE @sa_hash REGEXP '^\\$2[ayb]\\$[0-9]{2}\\$.{53}$'                       -- real bcrypt only
  AND @sa_username NOT IN ('CHANGE_ME_USERNAME', 'admin', 'administrator', 'root', 'test')
  AND @sa_email    <> 'CHANGE_ME@example.org'
  AND NOT EXISTS (SELECT 1 FROM `staff` WHERE `role` = 'super_admin')
  AND NOT EXISTS (SELECT 1 FROM `staff` WHERE `username` = @sa_username OR `email` = @sa_email);

-- Result: 1 = created, 0 = skipped (see conditions above).
SELECT ROW_COUNT() AS super_admin_created;
