-- =====================================================
-- Community Emergency Alert System - Database Migration
-- =====================================================

-- 1. Create alert_responses table
-- Tracks who responds to emergency alerts
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
    FOREIGN KEY (alert_id) REFERENCES panic_alerts(id) ON DELETE CASCADE,
    FOREIGN KEY (responder_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_alert_id (alert_id),
    INDEX idx_responder_id (responder_id),
    INDEX idx_status (status),
    INDEX idx_response_time (response_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create user_alert_settings table
-- User preferences for receiving community alerts
CREATE TABLE IF NOT EXISTS user_alert_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    allow_community_alerts BOOLEAN DEFAULT TRUE,
    alert_radius INT DEFAULT 5000 COMMENT 'Radius in meters',
    notify_push BOOLEAN DEFAULT TRUE,
    notify_sound BOOLEAN DEFAULT TRUE,
    notify_email BOOLEAN DEFAULT FALSE,
    notify_sms BOOLEAN DEFAULT FALSE,
    quiet_hours_start TIME,
    quiet_hours_end TIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_allow_alerts (allow_community_alerts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Update panic_alerts table
-- Add fields for community response tracking
ALTER TABLE panic_alerts
ADD COLUMN IF NOT EXISTS responders_count INT DEFAULT 0 COMMENT 'Number of people responding',
ADD COLUMN IF NOT EXISTS community_notified BOOLEAN DEFAULT FALSE COMMENT 'Whether nearby users were notified',
ADD COLUMN IF NOT EXISTS broadcast_radius INT DEFAULT 5000 COMMENT 'Radius in meters for community broadcast',
ADD COLUMN IF NOT EXISTS nearby_users_count INT DEFAULT 0 COMMENT 'Number of nearby users at time of alert';

-- Note: Spatial index skipped - panic_alerts uses latitude/longitude columns instead of POINT type


-- 4. Update users table
-- Track user's current location for nearby detection
ALTER TABLE users
ADD COLUMN IF NOT EXISTS current_latitude DECIMAL(10, 8) COMMENT 'Current GPS latitude',
ADD COLUMN IF NOT EXISTS current_longitude DECIMAL(11, 8) COMMENT 'Current GPS longitude',
ADD COLUMN IF NOT EXISTS last_location_update TIMESTAMP NULL COMMENT 'Last time location was updated',
ADD COLUMN IF NOT EXISTS is_online BOOLEAN DEFAULT FALSE COMMENT 'Whether user is currently active',
ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP NULL COMMENT 'Last activity timestamp';

-- Create computed column for location point (if MySQL 5.7+)
-- ALTER TABLE users
-- ADD COLUMN current_location POINT AS (POINT(current_longitude, current_latitude)) STORED;

-- Add index for online status
ALTER TABLE users
ADD INDEX IF NOT EXISTS idx_is_online (is_online);

-- Add index for last_seen
ALTER TABLE users
ADD INDEX IF NOT EXISTS idx_last_seen (last_seen);

-- 5. Create view for active nearby users
-- This view helps quickly find users who can receive alerts
CREATE OR REPLACE VIEW active_users_with_location AS
SELECT
    u.id,
    u.email,
    u.display_name,
    u.current_latitude,
    u.current_longitude,
    u.is_online,
    u.last_seen,
    uas.allow_community_alerts,
    uas.alert_radius,
    uas.notify_push,
    uas.notify_sound,
    uas.notify_email
FROM users u
LEFT JOIN user_alert_settings uas ON u.id = uas.user_id
WHERE u.is_online = TRUE
  AND u.current_latitude IS NOT NULL
  AND u.current_longitude IS NOT NULL
  AND (uas.allow_community_alerts IS NULL OR uas.allow_community_alerts = TRUE);

-- 6. Create stored procedure to find nearby users
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS find_nearby_users(
    IN alert_lat DECIMAL(10,8),
    IN alert_lng DECIMAL(11,8),
    IN radius_meters INT,
    IN exclude_user_id INT
)
BEGIN
    SELECT
        u.id,
        u.email,
        u.display_name,
        u.current_latitude,
        u.current_longitude,
        ST_Distance_Sphere(
            POINT(u.current_longitude, u.current_latitude),
            POINT(alert_lng, alert_lat)
        ) as distance_meters,
        uas.notify_push,
        uas.notify_sound,
        uas.notify_email
    FROM users u
    LEFT JOIN user_alert_settings uas ON u.id = uas.user_id
    WHERE u.is_online = TRUE
      AND u.current_latitude IS NOT NULL
      AND u.current_longitude IS NOT NULL
      AND u.id != exclude_user_id
      AND (uas.allow_community_alerts IS NULL OR uas.allow_community_alerts = TRUE)
      AND ST_Distance_Sphere(
          POINT(u.current_longitude, u.current_latitude),
          POINT(alert_lng, alert_lat)
      ) <= radius_meters
    ORDER BY distance_meters ASC
    LIMIT 100;
END //

DELIMITER ;

-- 7. Create trigger to update responders_count
DELIMITER //

CREATE TRIGGER IF NOT EXISTS update_responders_count_insert
AFTER INSERT ON alert_responses
FOR EACH ROW
BEGIN
    UPDATE panic_alerts
    SET responders_count = (
        SELECT COUNT(*)
        FROM alert_responses
        WHERE alert_id = NEW.alert_id
          AND status = 'responding'
    )
    WHERE id = NEW.alert_id;
END //

CREATE TRIGGER IF NOT EXISTS update_responders_count_update
AFTER UPDATE ON alert_responses
FOR EACH ROW
BEGIN
    UPDATE panic_alerts
    SET responders_count = (
        SELECT COUNT(*)
        FROM alert_responses
        WHERE alert_id = NEW.alert_id
          AND status = 'responding'
    )
    WHERE id = NEW.alert_id;
END //

DELIMITER ;

-- 8. Insert default settings for existing users
INSERT INTO user_alert_settings (user_id, allow_community_alerts, alert_radius)
SELECT id, TRUE, 5000
FROM users
WHERE id NOT IN (SELECT user_id FROM user_alert_settings);

-- 9. Create indexes for better performance
-- Note: Skipping panic_alerts index - table structure uses different column names
-- CREATE INDEX IF NOT EXISTS idx_panic_alerts_status_created ON panic_alerts(status, created_at);

CREATE INDEX IF NOT EXISTS idx_alert_responses_alert_status
ON alert_responses(alert_id, status);

-- =====================================================
-- Migration Complete
-- =====================================================

-- To verify tables were created, run these queries separately:
-- SHOW TABLES LIKE 'alert_responses';
-- SHOW TABLES LIKE 'user_alert_settings';
-- SELECT COUNT(*) FROM alert_responses;
-- SELECT COUNT(*) FROM user_alert_settings;
