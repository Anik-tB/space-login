-- =====================================================
-- Community Emergency Alert System - Simple Migration
-- Run this in phpMyAdmin SQL tab
-- =====================================================

-- 1. Create alert_responses table
CREATE TABLE IF NOT EXISTS alert_responses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alert_id INT NOT NULL,
    responder_id INT NOT NULL,
    response_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    current_latitude DECIMAL(10, 8),
    current_longitude DECIMAL(11, 8),
    eta_minutes INT,
    status ENUM('responding', 'arrived', 'cancelled') DEFAULT 'responding',
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_alert_id (alert_id),
    INDEX idx_responder_id (responder_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create user_alert_settings table
CREATE TABLE IF NOT EXISTS user_alert_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    allow_community_alerts BOOLEAN DEFAULT TRUE,
    alert_radius INT DEFAULT 5000,
    notify_push BOOLEAN DEFAULT TRUE,
    notify_sound BOOLEAN DEFAULT TRUE,
    notify_email BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add columns to users table
ALTER TABLE users
ADD COLUMN IF NOT EXISTS current_latitude DECIMAL(10, 8),
ADD COLUMN IF NOT EXISTS current_longitude DECIMAL(11, 8),
ADD COLUMN IF NOT EXISTS last_location_update TIMESTAMP NULL,
ADD COLUMN IF NOT EXISTS is_online BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP NULL;

-- 4. Add indexes to users table
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_is_online (is_online);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_last_seen (last_seen);

-- 5. Insert default settings for existing users
INSERT IGNORE INTO user_alert_settings (user_id, allow_community_alerts, alert_radius)
SELECT id, TRUE, 5000 FROM users;

-- Done! Now verify:
-- SHOW TABLES LIKE 'alert_responses';
-- SHOW TABLES LIKE 'user_alert_settings';
