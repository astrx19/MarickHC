SET @sa_username = 'CHANGE_ME_USERNAME';
SET @sa_email    = 'CHANGE_ME@example.org';
SET @sa_first    = 'Super';
SET @sa_last     = 'Admin';
SET @sa_hash     = 'PASTE_BCRYPT_HASH_HERE';

INSERT INTO `staff`
    (`first_name`, `last_name`, `contact_number`, `email`, `username`, `password`, `role`, `is_first_login`)
SELECT @sa_first, @sa_last, '', @sa_email, @sa_username, @sa_hash, 'super_admin', 1
FROM DUAL
WHERE @sa_hash REGEXP '^\\$2[ayb]\\$[0-9]{2}\\$.{53}$'
  AND @sa_username NOT IN ('CHANGE_ME_USERNAME', 'admin', 'administrator', 'root', 'test')
  AND @sa_email    <> 'CHANGE_ME@example.org'
  AND NOT EXISTS (SELECT 1 FROM `staff` WHERE `role` = 'super_admin')
  AND NOT EXISTS (SELECT 1 FROM `staff` WHERE `username` = @sa_username OR `email` = @sa_email);

SELECT ROW_COUNT() AS super_admin_created;
