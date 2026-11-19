CREATE TABLE IF NOT EXISTS `user_dashboard_preferences` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT NOT NULL,
    `layout_json` TEXT NOT NULL COMMENT 'JSON array of {"id":"widget_id","visible":true}, in display order',
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;