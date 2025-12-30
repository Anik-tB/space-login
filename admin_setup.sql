-- =====================================================
-- SafeSpace Admin Panel - Complete SQL Setup
-- =====================================================
-- Run this file in phpMyAdmin or MySQL to set up admin features
-- Database: space_login
-- =====================================================

-- 1. Add is_admin column to users table (if not exists)
-- This allows better admin management instead of email-based checks
-- Note: MariaDB doesn't support IF NOT EXISTS in ALTER TABLE, so check manually first
SET @dbname = DATABASE();
SET @tablename = 'users';
SET @columnname = 'is_admin';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TINYINT(1) DEFAULT 0 AFTER email_verified')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index for faster admin queries (if not exists)
SET @indexname = 'idx_is_admin';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('CREATE INDEX ', @indexname, ' ON ', @tablename, ' (is_admin)')
));
PREPARE createIndexIfNotExists FROM @preparedStatement;
EXECUTE createIndexIfNotExists;
DEALLOCATE PREPARE createIndexIfNotExists;

-- 2. Create admin user (optional - you can also use create_admin_user.php)
-- Default credentials: admin@safespace.com / Admin@123
-- CHANGE THE PASSWORD after first login!
INSERT INTO `users` (
    `email`,
    `password`,
    `display_name`,
    `is_admin`,
    `email_verified`,
    `status`,
    `created_at`,
    `last_login`
) VALUES (
    'admin@safespace.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Password: Admin@123
    'System Administrator',
    1,
    1,
    'active',
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `is_admin` = 1,
    `display_name` = 'System Administrator',
    `status` = 'active';

-- 3. Ensure audit_logs table exists for tracking admin actions
-- Check if table exists first, then create if needed
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `table_name` VARCHAR(100) DEFAULT NULL,
    `record_id` INT(11) UNSIGNED DEFAULT NULL,
    `old_values` TEXT DEFAULT NULL,
    `new_values` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_action` (`action`),
    KEY `idx_table_record` (`table_name`, `record_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Update incident_reports table to ensure status supports 'pending'
-- Check if status column exists and update enum if needed
-- Note: This assumes your current enum includes 'pending', 'approved', 'rejected'
-- If not, you may need to adjust based on your current schema

-- 5. Update safe_spaces table to ensure status supports 'pending_verification'
-- The code uses 'pending_verification' for new safe spaces
-- If your table uses different values, update accordingly

-- 6. Update disputes table status enum (if needed)
-- Ensure it supports: 'pending', 'under_review', 'approved', 'rejected', 'closed'
-- Uncomment below if you need to add these statuses:
-- ALTER TABLE `disputes`
-- MODIFY `status` ENUM('pending','under_review','approved','rejected','closed')
-- DEFAULT 'pending';

-- 7. Update neighborhood_groups table to ensure status supports 'pending'
-- Ensure it supports: 'pending', 'approved', 'rejected', 'active', 'inactive'
-- Uncomment below if needed:
-- ALTER TABLE `neighborhood_groups`
-- MODIFY `status` ENUM('pending','approved','rejected','active','inactive')
-- DEFAULT 'pending';

-- 8. Update community_alerts table to ensure status supports 'pending'
-- Ensure it supports: 'pending', 'approved', 'rejected', 'active', 'expired'
-- Uncomment below if needed:
-- ALTER TABLE `community_alerts`
-- MODIFY `status` ENUM('pending','approved','rejected','active','expired')
-- DEFAULT 'pending';

-- 9. Update legal_aid_providers table to ensure is_verified field exists
SET @tablename = 'legal_aid_providers';
SET @columnname = 'is_verified';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TINYINT(1) DEFAULT 0 AFTER status')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 10. Update medical_support_providers table to ensure is_verified field exists
SET @tablename = 'medical_support_providers';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TINYINT(1) DEFAULT 0 AFTER status')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- =====================================================
-- Verification Queries (Run these to check setup)
-- =====================================================

-- Check if is_admin column exists
-- SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = 'space_login'
-- AND TABLE_NAME = 'users'
-- AND COLUMN_NAME = 'is_admin';

-- Check admin users
-- SELECT id, email, display_name, is_admin, status
-- FROM users
-- WHERE is_admin = 1 OR email LIKE '%admin%';

-- Check audit_logs table
-- SELECT COUNT(*) as total_logs FROM audit_logs;

-- =====================================================
-- Notes:
-- =====================================================
-- 1. The admin panel works with existing database structure
-- 2. The is_admin field is optional - the code also checks email for 'admin'
-- 3. All approval actions are logged in audit_logs table
-- 4. Default admin password is 'Admin@123' - CHANGE IT IMMEDIATELY!
-- 5. You can also use create_admin_user.php script instead of SQL
-- =====================================================

