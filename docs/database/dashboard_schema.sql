-- =========================================================================
-- MARICK HEALTH CENTER - CUSTOMIZABLE ADMIN DASHBOARD
-- =========================================================================
-- Run this once against marick_health_db (phpMyAdmin > Import, or:
--   mysql -u root marick_health_db < database/dashboard_schema.sql
-- ).
--
-- Written with CREATE TABLE IF NOT EXISTS so it is safe to run more than
-- once. Note: includes/dashboard_helper.php also creates this table on
-- first use (mh_dashboard_install()), the same lazy-install pattern used
-- by includes/settings_helper.php and includes/sms/sms_scheduler.php, so
-- running this file by hand is optional - it exists mainly for anyone
-- who prefers to provision the schema up front.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `user_dashboard_preferences` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT NOT NULL,
    `layout_json` TEXT NOT NULL COMMENT 'JSON array of {"id":"widget_id","visible":true}, in display order',
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;