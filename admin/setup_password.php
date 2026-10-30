<?php
/**
 * LEGACY PAGE - retired.
 * First-login password setup is handled inside admin/login.php (session key `temp_user_id`,
 * minimum 8 characters). This old page used a different key (`temp_staff_id`) that nothing
 * sets, so it could never work. Redirect to the real login page.
 */
header('Location: login.php');
exit();
