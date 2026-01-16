##  User Management System

### 1. User Registration & Login

**Description:** Complete authentication system with email/password and social login support.

**SQL Table Structure:**
```sql
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `firebase_uid` varchar(128) DEFAULT NULL,
  `provider` varchar(32) DEFAULT 'local',
  `email_verified` tinyint(1) DEFAULT 0,
  `is_admin` tinyint(1) DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `password_reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `status` varchar(16) DEFAULT 'active',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Features:**
- Email and password registration
- Firebase Authentication support
- Social login (Google, Facebook)
- Email verification system
- Password reset functionality
- Secure session management

**Example Query - User Registration:**
```sql
INSERT INTO users (email, password, display_name, provider, created_at)
VALUES ('user@example.com', '$2y$10$hashed_password', 'anik', 'local', NOW());
```

**Example Query - User Login:**
```sql
SELECT id, email, password, display_name, is_admin, status
FROM users
WHERE email = 'mdabusayumanik123@gmail.com' AND is_active = 1;
```

---

### 2. User Profile Management

**Description:** Comprehensive user profile system with NID and face verification.

**SQL Extension:**
```sql
ALTER TABLE `users` ADD COLUMN `nid_number` varchar(20) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `nid_front_photo` varchar(255) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `nid_back_photo` varchar(255) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `face_verified` tinyint(1) DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `nid_verified` tinyint(1) DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `verification_status`
  ENUM('pending','under_review','verified','rejected') DEFAULT 'pending';
```

**Features:**
- Personal information storage (name, email, phone)
- Bio/description
- Profile picture upload
- NID verification (front & back photos)
- Face verification system

**Example Query - Update Profile:**
```sql
UPDATE users
SET display_name = 'Anik',
    phone = '+8801871745957',
    bio = 'Software Developer',
    updated_at = NOW()
WHERE id = 1;
```

---

### 3. Admin Panel

**Description:** Administrative dashboard for system management.

**Features:**
- Admin dashboard
- User management
- Report approval/rejection
- System statistics viewing
- Audit log monitoring

**Example Query - Get Admin Dashboard Stats:**
```sql
SELECT
  (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
  (SELECT COUNT(*) FROM incident_reports WHERE status = 'pending') as pending_reports,
  (SELECT COUNT(*) FROM incident_reports WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as reports_today,
  (SELECT COUNT(*) FROM panic_alerts WHERE status = 'active') as active_alerts;
```

---

## 📍 Incident Reporting System

### 4. Incident Report Creation

**Description:** Multi-category incident reporting system with evidence upload.

**SQL Table Structure:**
```sql
CREATE TABLE `incident_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` ENUM('harassment','assault','theft','vandalism','stalking',
                   'cyberbullying','discrimination','other') NOT NULL,
  `severity` ENUM('low','medium','high','critical') DEFAULT 'medium',
  `status` ENUM('pending','under_review','investigating','resolved','closed','disputed')
           DEFAULT 'pending',
  `location_name` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `incident_date` datetime DEFAULT NULL,
  `reported_date` datetime DEFAULT current_timestamp(),
  `updated_date` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `is_public` tinyint(1) DEFAULT 0,
  `evidence_files` text DEFAULT NULL,
  `witness_count` int(11) DEFAULT 0,
  `response_time_minutes` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `category` (`category`),
  KEY `severity` (`severity`),
  KEY `status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Categories:**
- Harassment
- Assault
- Theft
- Vandalism
- Stalking
- Cyberbullying
- Discrimination
- Other

**Example Query - Create Report:**
```sql
INSERT INTO incident_reports
  (user_id, title, description, category, severity, location_name,
   latitude, longitude, incident_date, is_anonymous, is_public)
VALUES
  (1, 'Street Harassment', 'Detailed description...', 'harassment', 'high',
   'Dhanmondi', 23.7465, 90.3700, '2026-01-11 14:30:00', 0, 1);
```

---

### 5. Report Details & Evidence

**Features:**
- Detailed incident description
- Severity levels (Low, Medium, High, Critical)
- GPS location (coordinates)
- Incident date and time
- Witness count
- Evidence file upload (images, videos, documents)
- Anonymous reporting option
- Public/Private report visibility

**Example Query - Get Report with Evidence:**
```sql
SELECT
  ir.*,
  u.display_name as reporter_name,
  u.email as reporter_email
FROM incident_reports ir
LEFT JOIN users u ON ir.user_id = u.id
WHERE ir.id = 2;
```

---

### 6. Report Tracking

**Description:** Complete status tracking system for incident reports.

**Status Flow:**
- Pending → Under Review → Investigating → Resolved/Closed
- Can be marked as Disputed at any stage

**Example Query - Update Report Status:**
```sql
UPDATE incident_reports
SET status = 'under_review',
    assigned_to = 4,
    updated_date = NOW()
WHERE id = 104;
```

**Example Query - Get Reports by Status:**
```sql
SELECT id, title, category, severity, status, reported_date
FROM incident_reports
WHERE status = 'pending'
ORDER BY severity DESC, reported_date DESC
LIMIT 20;
```

---

### 7. Dispute System

**SQL Table Structure:**
```sql
CREATE TABLE `disputes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `reason` ENUM('false_accusation','wrong_person','misunderstanding',
                'malicious_report','other') NOT NULL,
  `description` text NOT NULL,
  `status` ENUM('pending','under_review','approved','rejected','closed') DEFAULT 'pending',
  `evidence_files` text DEFAULT NULL,
  `supporting_documents` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`report_id`) REFERENCES `incident_reports` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Features:**
- File dispute against false reports
- Submit evidence
- Review process
- Status updates

**Example Query - Create Dispute:**
```sql
INSERT INTO disputes (user_id, report_id, reason, description, evidence_files)
VALUES (2, 104, 'false_accusation', 'This report is incorrect...', '["evidence1.pdf"]');
```

---

## 🗺️ Map & Location Features

### 8. Incident Zone Mapping

**Description:** Real-time incident zone mapping with automatic status calculation.

**SQL Table Structure:**
```sql
CREATE TABLE `incident_zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_name` varchar(255) NOT NULL,
  `area_name` varchar(255) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location` point NOT NULL,
  `report_count` int(11) DEFAULT 0,
  `zone_status` ENUM('safe','moderate','unsafe') DEFAULT 'safe',
  `last_incident_date` datetime DEFAULT NULL,
  `first_incident_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `zone_name` (`zone_name`),
  SPATIAL KEY `location` (`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Zone Status Logic:**
- 🟢 **Safe**: 0-2 reports
- 🟡 **Moderate**: 3-4 reports
- 🔴 **Unsafe**: 5+ reports

**Stored Procedure - Auto Update Zone:**
```sql
DELIMITER $$
CREATE PROCEDURE `update_incident_zone` (
    IN `p_zone_name` VARCHAR(255),
    IN `p_area_name` VARCHAR(255),
    IN `p_latitude` DECIMAL(10,8),
    IN `p_longitude` DECIMAL(11,8),
    IN `p_incident_date` DATETIME
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_status ENUM('safe', 'moderate', 'unsafe');
    DECLARE v_first_date DATETIME;

    -- Get current report count for this zone
    SELECT COUNT(*) INTO v_count
    FROM incident_reports
    WHERE location_name COLLATE utf8mb4_unicode_ci = p_zone_name COLLATE utf8mb4_unicode_ci
      AND (latitude BETWEEN p_latitude - 0.01 AND p_latitude + 0.01)
      AND (longitude BETWEEN p_longitude - 0.01 AND p_longitude + 0.01)
      AND status != 'disputed';

    -- Determine status based on count
    SET v_status = update_zone_status(v_count);

    -- Get first incident date
    SELECT MIN(incident_date) INTO v_first_date
    FROM incident_reports
    WHERE location_name COLLATE utf8mb4_unicode_ci = p_zone_name COLLATE utf8mb4_unicode_ci
      AND (latitude BETWEEN p_latitude - 0.01 AND p_latitude + 0.01)
      AND (longitude BETWEEN p_longitude - 0.01 AND p_longitude + 0.01);

    -- Insert or update zone
    INSERT INTO incident_zones (
        zone_name, area_name, latitude, longitude, location,
        report_count, zone_status, last_incident_date, first_incident_date
    ) VALUES (
        p_zone_name, p_area_name, p_latitude, p_longitude,
        ST_GeomFromText(CONCAT('POINT(', p_longitude, ' ', p_latitude, ')'), 4326),
        v_count, v_status, p_incident_date, COALESCE(v_first_date, p_incident_date)
    )
    ON DUPLICATE KEY UPDATE
        report_count = v_count,
        zone_status = v_status,
        last_incident_date = p_incident_date,
        location = ST_GeomFromText(CONCAT('POINT(', p_longitude, ' ', p_latitude, ')'), 4326),
        updated_at = CURRENT_TIMESTAMP;
END$$
DELIMITER ;
```

**Function - Determine Zone Status:**
```sql
DELIMITER $$
CREATE FUNCTION `update_zone_status` (`report_count` INT)
RETURNS ENUM('safe','moderate','unsafe')
DETERMINISTIC
BEGIN
    IF report_count >= 5 THEN
        RETURN 'unsafe';
    ELSEIF report_count >= 3 THEN
        RETURN 'moderate';
    ELSE
        RETURN 'safe';
    END IF;
END$$
DELIMITER ;
```

**Trigger - Auto Update on New Report:**
```sql
DELIMITER $$
CREATE TRIGGER `after_incident_insert`
AFTER INSERT ON `incident_reports`
FOR EACH ROW
BEGIN
    IF NEW.location_name IS NOT NULL AND NEW.latitude IS NOT NULL AND NEW.longitude IS NOT NULL THEN
        CALL update_incident_zone(
            NEW.location_name,
            COALESCE(NEW.address, NEW.location_name),
            NEW.latitude,
            NEW.longitude,
            COALESCE(NEW.incident_date, NOW())
        );
    END IF;
END$$
DELIMITER ;
```

**Example Query - Get Zone Status:**
```sql
SELECT zone_name, area_name, report_count, zone_status,
       last_incident_date, latitude, longitude
FROM incident_zones
WHERE zone_status = 'unsafe'
ORDER BY report_count DESC;
```

---

### 9. Safe Spaces/Nodes System

**SQL Table Structure:**
```sql
CREATE TABLE `leaf_nodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location` point NOT NULL,
  `safety_score` decimal(3,1) NOT NULL DEFAULT 5.0,
  `status` ENUM('safe','moderate','unsafe') NOT NULL DEFAULT 'moderate',
  `description` text DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `hours` varchar(100) DEFAULT NULL,
  `amenities` JSON DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  SPATIAL KEY `location` (`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Categories:**
- Parks
- Police Stations
- Hospitals
- Schools
- Libraries
- Business establishments
- Religious places

**Example Query - Find Nearby Safe Spaces:**
```sql
SELECT name, category, safety_score, address, contact,
       haversine_distance(23.7465, 90.3700, latitude, longitude) as distance_km
FROM leaf_nodes
WHERE safety_score >= 7.0
HAVING distance_km <= 2.0
ORDER BY distance_km ASC
LIMIT 10;
```

---

### 10. Distance Calculation

**Haversine Distance Function:**
```sql
DELIMITER $$
CREATE FUNCTION `haversine_distance` (
    `lat1` DECIMAL(10,8),
    `lon1` DECIMAL(11,8),
    `lat2` DECIMAL(10,8),
    `lon2` DECIMAL(11,8)
)
RETURNS DECIMAL(10,2)
DETERMINISTIC
BEGIN
    DECLARE R DECIMAL(10, 2) DEFAULT 6371; -- Earth radius in km
    DECLARE dlat DECIMAL(10, 8);
    DECLARE dlon DECIMAL(11, 8);
    DECLARE a DECIMAL(10, 8);
    DECLARE c DECIMAL(10, 8);

    SET dlat = RADIANS(lat2 - lat1);
    SET dlon = RADIANS(lon2 - lon1);
    SET a = SIN(dlat/2) * SIN(dlat/2) +
            COS(RADIANS(lat1)) * COS(RADIANS(lat2)) *
            SIN(dlon/2) * SIN(dlon/2);
    SET c = 2 * ATAN2(SQRT(a), SQRT(1-a));

    RETURN R * c;
END$$
DELIMITER ;
```

**Usage Example:**
```sql
-- Calculate distance between two points
SELECT haversine_distance(23.7465, 90.3700, 23.7947, 90.4144) as distance_km;
-- Result: Distance in kilometers
```

---

## 🚨 Emergency Services

### 11. Panic Button/SOS Alert

**SQL Table Structure:**
```sql
CREATE TABLE `panic_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `trigger_method` ENUM('app_button','sms_keyword','voice_command','automated') NOT NULL,
  `location_name` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `emergency_contacts_notified` int(11) DEFAULT 0,
  `police_notified` tinyint(1) DEFAULT 0,
  `ambulance_notified` tinyint(1) DEFAULT 0,
  `fire_service_notified` tinyint(1) DEFAULT 0,
  `status` ENUM('active','acknowledged','false_alarm','resolved') DEFAULT 'active',
  `response_time_seconds` int(11) DEFAULT NULL,
  `triggered_at` datetime DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Features:**
- One-click emergency help
- Multiple trigger methods (App button, SMS, Voice, Automated)
- Real-time location sharing
- Emergency message sending

**Example Query - Trigger SOS:**
```sql
INSERT INTO panic_alerts
  (user_id, trigger_method, latitude, longitude, message, police_notified)
VALUES
  (1, 'app_button', 23.8146, 90.3861, 'Help needed!', 1);
```

---

### 12. Emergency Contact Management

**SQL Table Structure:**
```sql
CREATE TABLE `emergency_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `contact_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `priority` int(1) DEFAULT 1,
  `is_verified` tinyint(1) DEFAULT 0,
  `notification_methods` text DEFAULT 'sms,call',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Add Emergency Contact:**
```sql
INSERT INTO emergency_contacts
  (user_id, contact_name, phone_number, relationship, priority, notification_methods)
VALUES
  (1, 'John Doe', '+8801234567890', 'Brother', 1, 'sms,call,whatsapp');
```

---

### 13. Panic Notification System

**SQL Table Structure:**
```sql
CREATE TABLE `panic_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `panic_alert_id` int(11) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `notification_type` ENUM('sms','whatsapp','email','call','push') NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` ENUM('pending','sent','delivered','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`panic_alert_id`) REFERENCES `panic_alerts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Send Notifications:**
```sql
INSERT INTO panic_notifications
  (panic_alert_id, contact_id, notification_type, recipient, message)
SELECT
  2 as panic_alert_id,
  ec.id as contact_id,
  'sms' as notification_type,
  ec.phone_number as recipient,
  CONCAT('🚨 EMERGENCY ALERT 🚨\nUser: ', u.display_name,
         '\nLocation: https://maps.google.com/?q=', pa.latitude, ',', pa.longitude,
         '\nTime: ', pa.triggered_at, '\nMessage: ', pa.message) as message
FROM panic_alerts pa
JOIN users u ON pa.user_id = u.id
JOIN emergency_contacts ec ON ec.user_id = u.id
WHERE pa.id = 2 AND ec.is_active = 1
ORDER BY ec.priority ASC;
```

---

### 14. Walk Session Tracking

**SQL Table Structure:**
```sql
CREATE TABLE `walk_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `start_time` datetime DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `status` ENUM('active','completed','emergency') DEFAULT 'active',
  `destination` varchar(255) DEFAULT NULL,
  `estimated_duration_minutes` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Start Walk Session:**
```sql
INSERT INTO walk_sessions (user_id, session_token, destination, estimated_duration_minutes)
VALUES (1, SHA2(CONCAT(1, NOW(), RAND()), 256), 'Dhanmondi Lake', 30);
```

---

## 👥 Community Features

### 15. Neighborhood Groups

**SQL Table Structure:**
```sql
CREATE TABLE `neighborhood_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `area_name` varchar(100) NOT NULL,
  `ward_number` varchar(20) DEFAULT NULL,
  `union_name` varchar(100) DEFAULT NULL,
  `division_id` int(11) NOT NULL,
  `district_id` int(11) NOT NULL,
  `upazila_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `member_count` int(11) DEFAULT 0,
  `active_members` int(11) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `status` ENUM('active','inactive','suspended','pending_approval') DEFAULT 'pending_approval',
  `privacy_level` ENUM('public','private','invite_only') DEFAULT 'public',
  `rules` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Create Group:**
```sql
INSERT INTO neighborhood_groups
  (group_name, description, area_name, division_id, district_id, created_by, privacy_level)
VALUES
  ('Dhanmondi Safety Watch', 'Community safety group for Dhanmondi residents',
   'Dhanmondi', 1, 1, 1, 'public');
```

---

### 16. Group Alert System

**SQL Table Structure:**
```sql
CREATE TABLE `group_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `posted_by` int(11) NOT NULL,
  `alert_type` ENUM('safety_warning','missing_person','suspicious_activity',
                     'emergency','general') DEFAULT 'general',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `location_details` varchar(255) DEFAULT NULL,
  `severity` ENUM('low','medium','high','critical') DEFAULT 'medium',
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `views_count` int(11) DEFAULT 0,
  `acknowledgments` int(11) DEFAULT 0,
  `status` ENUM('active','resolved','false_alarm','expired') DEFAULT 'active',
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`group_id`) REFERENCES `neighborhood_groups` (`id`),
  FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Post Alert:**
```sql
INSERT INTO group_alerts
  (group_id, posted_by, alert_type, title, message, location_details, severity)
VALUES
  (1, 1, 'safety_warning', 'Suspicious Activity Alert',
   'Multiple reports of suspicious individuals...', 'Near Dhanmondi Lake', 'high');
```

---

### 17. Group Media Sharing

**SQL Table Structure:**
```sql
CREATE TABLE `group_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `alert_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` ENUM('image','video','document','other') NOT NULL,
  `file_size_bytes` bigint(20) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 1,
  `views_count` int(11) DEFAULT 0,
  `download_count` int(11) DEFAULT 0,
  `status` ENUM('active','deleted','flagged') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`group_id`) REFERENCES `neighborhood_groups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 18. Group Member Management

**SQL Table Structure:**
```sql
CREATE TABLE `group_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` ENUM('member','moderator','admin','founder') DEFAULT 'member',
  `joined_at` datetime DEFAULT current_timestamp(),
  `status` ENUM('active','inactive','banned') DEFAULT 'active',
  `contribution_score` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_user` (`group_id`, `user_id`),
  FOREIGN KEY (`group_id`) REFERENCES `neighborhood_groups` (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Join Group:**
```sql
INSERT INTO group_members (group_id, user_id, role)
VALUES (1, 2, 'member');

-- Update member count
UPDATE neighborhood_groups
SET member_count = member_count + 1,
    active_members = active_members + 1
WHERE id = 1;
```

---

## 📚 Education & Training

### 19. Safety Courses

**SQL Table Structure:**
```sql
CREATE TABLE `safety_courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_title` varchar(255) NOT NULL,
  `course_description` text DEFAULT NULL,
  `category` ENUM('self_defense','cyber_safety','legal_rights',
                   'emergency_response','prevention','awareness') NOT NULL,
  `target_audience` text DEFAULT 'general',
  `duration_minutes` int(11) DEFAULT NULL,
  `content_type` ENUM('video','interactive','text','quiz','mixed') DEFAULT 'mixed',
  `content_url` varchar(500) DEFAULT NULL,
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `is_premium` tinyint(1) DEFAULT 0,
  `instructor_name` varchar(100) DEFAULT NULL,
  `language` ENUM('bn','en','both') DEFAULT 'bn',
  `enrollment_count` int(11) DEFAULT 0,
  `completion_count` int(11) DEFAULT 0,
  `average_rating` decimal(3,2) DEFAULT 0.00,
  `rating_count` int(11) DEFAULT 0,
  `status` ENUM('active','draft','archived') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Get Popular Courses:**
```sql
SELECT course_title, category, duration_minutes, enrollment_count, average_rating
FROM safety_courses
WHERE status = 'active'
ORDER BY enrollment_count DESC, average_rating DESC
LIMIT 10;
```

---

### 20. Course Enrollment System

**SQL Table Structure:**
```sql
CREATE TABLE `course_enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `status` ENUM('enrolled','in_progress','completed','dropped') DEFAULT 'enrolled',
  `started_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `certificate_issued` tinyint(1) DEFAULT 0,
  `certificate_id` varchar(100) DEFAULT NULL,
  `rating` int(1) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `last_accessed_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_course` (`user_id`, `course_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`course_id`) REFERENCES `safety_courses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Enroll in Course:**
```sql
INSERT INTO course_enrollments (user_id, course_id)
VALUES (1, 3);

-- Update enrollment count
UPDATE safety_courses
SET enrollment_count = enrollment_count + 1
WHERE id = 3;
```

---

### 21. Certificate System

**SQL Table Structure:**
```sql
CREATE TABLE `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `certificate_number` varchar(100) NOT NULL,
  `certificate_file_path` varchar(500) DEFAULT NULL,
  `verification_code` varchar(50) DEFAULT NULL,
  `issued_at` datetime DEFAULT current_timestamp(),
  `expires_at` date DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `certificate_number` (`certificate_number`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`course_id`) REFERENCES `safety_courses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Issue Certificate:**
```sql
INSERT INTO certificates
  (user_id, course_id, enrollment_id, certificate_number, verification_code)
VALUES
  (1, 3, 2, CONCAT('CERT-', YEAR(NOW()), '-', LPAD(LAST_INSERT_ID(), 6, '0')),
   UPPER(SUBSTRING(MD5(RAND()), 1, 10)));
```

---

## ⚖️ Legal Aid

### 22. Legal Aid Providers

**SQL Table Structure:**
```sql
CREATE TABLE `legal_aid_providers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `organization_name` varchar(255) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `division` varchar(50) DEFAULT NULL,
  `specialization` text NOT NULL COMMENT 'criminal,civil,family,labor,property,cyber,women_rights,human_rights',
  `language_support` text DEFAULT 'bn,en',
  `fee_structure` ENUM('free','low_cost','standard','pro_bono') DEFAULT 'free',
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT 0.00,
  `review_count` int(11) DEFAULT 0,
  `cases_handled` int(11) DEFAULT 0,
  `success_rate` decimal(5,2) DEFAULT 0.00,
  `availability_hours` text DEFAULT NULL,
  `is_24_7` tinyint(1) DEFAULT 0,
  `status` ENUM('active','inactive','suspended') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Find Legal Aid:**
```sql
SELECT organization_name, specialization, fee_structure, phone, rating
FROM legal_aid_providers
WHERE status = 'active'
  AND FIND_IN_SET('women_rights', specialization) > 0
  AND fee_structure IN ('free', 'low_cost')
ORDER BY rating DESC;
```

---

### 23. Legal Consultation

**SQL Table Structure:**
```sql
CREATE TABLE `legal_consultations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `provider_id` int(11) NOT NULL,
  `consultation_type` ENUM('initial','follow_up','emergency','document_review') DEFAULT 'initial',
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `status` ENUM('requested','scheduled','completed','cancelled','no_show') DEFAULT 'requested',
  `scheduled_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `provider_notes` text DEFAULT NULL,
  `user_feedback` text DEFAULT NULL,
  `rating` int(1) DEFAULT NULL,
  `cost_bdt` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`provider_id`) REFERENCES `legal_aid_providers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 24. Legal Documents

**SQL Table Structure:**
```sql
CREATE TABLE `legal_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `document_type` ENUM('form','template','guideline','law_reference','case_study') NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_url` varchar(500) DEFAULT NULL,
  `language` ENUM('bn','en','both') DEFAULT 'bn',
  `download_count` int(11) DEFAULT 0,
  `is_premium` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `status` ENUM('active','draft','archived') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🏥 Medical Support

### 25. Medical Support Providers

**SQL Table Structure:**
```sql
CREATE TABLE `medical_support_providers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider_name` varchar(255) NOT NULL,
  `provider_type` ENUM('hospital','clinic','counselor','psychologist',
                        'psychiatrist','trauma_center','ngo') NOT NULL,
  `specialization` text DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` text NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `division` varchar(50) DEFAULT NULL,
  `is_24_7` tinyint(1) DEFAULT 0,
  `languages` text DEFAULT 'bn,en',
  `accepts_insurance` tinyint(1) DEFAULT 0,
  `insurance_types` text DEFAULT NULL,
  `fee_structure` ENUM('free','subsidized','standard','premium') DEFAULT 'free',
  `rating` decimal(3,2) DEFAULT 0.00,
  `review_count` int(11) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 26. Support Referral System

**SQL Table Structure:**
```sql
CREATE TABLE `support_referrals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `provider_id` int(11) NOT NULL,
  `referral_type` ENUM('medical','counseling','emergency','follow_up') NOT NULL,
  `priority` ENUM('low','medium','high','urgent') DEFAULT 'medium',
  `reason` text DEFAULT NULL,
  `status` ENUM('pending','contacted','appointment_scheduled','completed','declined') DEFAULT 'pending',
  `referred_at` datetime DEFAULT current_timestamp(),
  `appointment_date` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `user_feedback` text DEFAULT NULL,
  `rating` int(1) DEFAULT NULL,
  `provider_notes` text DEFAULT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`provider_id`) REFERENCES `medical_support_providers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🔔 Notification System

### 27. Multi-Channel Notifications

**SQL Table Structure:**
```sql
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` ENUM('alert','report_update','dispute_update','system','emergency') DEFAULT 'system',
  `action_url` varchar(255) DEFAULT NULL,
  `action_data` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_sent` tinyint(1) DEFAULT 0,
  `email_sent` tinyint(1) DEFAULT 0,
  `sms_sent` tinyint(1) DEFAULT 0,
  `push_sent` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Send Notification:**
```sql
INSERT INTO notifications (user_id, title, message, type, action_url)
VALUES (1, 'Report Submitted', 'Your incident report #104 has been submitted successfully.',
        'report_update', 'view_report.php?id=104');
```

---

### 28. User Preferences

**SQL Table Structure:**
```sql
CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email_notifications` tinyint(1) DEFAULT 1,
  `sms_notifications` tinyint(1) DEFAULT 0,
  `push_notifications` tinyint(1) DEFAULT 1,
  `alert_radius_km` decimal(5,2) DEFAULT 1.00,
  `alert_types` text DEFAULT NULL,
  `profile_visibility` ENUM('public','private','friends_only') DEFAULT 'private',
  `location_sharing` tinyint(1) DEFAULT 0,
  `anonymous_reporting` tinyint(1) DEFAULT 1,
  `preferred_language` ENUM('en','bn') DEFAULT 'en',
  `timezone` varchar(50) DEFAULT 'Asia/Dhaka',
  `emergency_contacts` text DEFAULT NULL,
  `theme_preference` ENUM('light','dark','auto') DEFAULT 'auto',
  `accessibility_features` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 📊 Area Safety Scoring

### 29. Area Safety Scores

**SQL Table Structure:**
```sql
CREATE TABLE `area_safety_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `area_name` varchar(255) NOT NULL,
  `ward_number` varchar(20) DEFAULT NULL,
  `union_name` varchar(100) DEFAULT NULL,
  `division_id` int(11) NOT NULL,
  `district_id` int(11) NOT NULL,
  `upazila_id` int(11) DEFAULT NULL,
  `safety_score` decimal(5,2) DEFAULT 0.00,
  `incident_rate_score` decimal(5,2) DEFAULT 0.00,
  `resolution_rate_score` decimal(5,2) DEFAULT 0.00,
  `response_time_score` decimal(5,2) DEFAULT 0.00,
  `user_ratings_score` decimal(5,2) DEFAULT 0.00,
  `critical_incidents_score` decimal(5,2) DEFAULT 0.00,
  `total_incidents` int(11) DEFAULT 0,
  `resolved_incidents` int(11) DEFAULT 0,
  `critical_incidents` int(11) DEFAULT 0,
  `response_time_avg_hours` decimal(8,2) DEFAULT 0.00,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Calculate Safety Score:**
```sql
SELECT
  area_name,
  ROUND((incident_rate_score + resolution_rate_score + response_time_score +
         user_ratings_score + critical_incidents_score) / 5, 2) as overall_safety_score,
  total_incidents,
  resolved_incidents,
  ROUND((resolved_incidents / NULLIF(total_incidents, 0)) * 100, 2) as resolution_rate_percent
FROM area_safety_scores
ORDER BY overall_safety_score DESC;
```

---

### 30. User Area Ratings

**SQL Table Structure:**
```sql
CREATE TABLE `user_area_ratings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `area_id` int(11) NOT NULL,
  `safety_rating` int(1) NOT NULL,
  `comments` text DEFAULT NULL,
  `factors` text DEFAULT NULL,
  `is_verified_resident` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`area_id`) REFERENCES `area_safety_scores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 📈 Statistics & Analytics

### 31. System Statistics

**SQL Table Structure:**
```sql
CREATE TABLE `system_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `new_users` int(11) DEFAULT 0,
  `active_users` int(11) DEFAULT 0,
  `total_users` int(11) DEFAULT 0,
  `new_reports` int(11) DEFAULT 0,
  `resolved_reports` int(11) DEFAULT 0,
  `total_reports` int(11) DEFAULT 0,
  `new_alerts` int(11) DEFAULT 0,
  `active_alerts` int(11) DEFAULT 0,
  `total_alerts` int(11) DEFAULT 0,
  `avg_response_time_minutes` decimal(8,2) DEFAULT 0.00,
  `min_response_time_minutes` int(11) DEFAULT 0,
  `max_response_time_minutes` int(11) DEFAULT 0,
  `top_cities` text DEFAULT NULL,
  `top_categories` text DEFAULT NULL,
  `system_uptime_percentage` decimal(5,2) DEFAULT 100.00,
  `avg_page_load_time` decimal(5,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Example Query - Daily Statistics:**
```sql
SELECT
  DATE(created_at) as report_date,
  COUNT(*) as total_reports,
  SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_reports,
  SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_reports,
  AVG(response_time_minutes) as avg_response_time
FROM incident_reports
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at)
ORDER BY report_date DESC;
```

---

## 🔒 Security Features

### 32. Audit Logging System

**SQL Table Structure:**
```sql
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_url` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Audit Triggers:**
```sql
-- Trigger for INSERT operations
DELIMITER $$
CREATE TRIGGER `tr_users_after_insert_audit`
AFTER INSERT ON `users` FOR EACH ROW
BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `new_values`)
  VALUES (NEW.id, 'INSERT', 'users', NEW.id,
          JSON_OBJECT('email', NEW.email, 'status', NEW.status, 'is_active', NEW.is_active));
END$$
DELIMITER ;

-- Trigger for UPDATE operations
DELIMITER $$
CREATE TRIGGER `tr_users_after_update_audit`
AFTER UPDATE ON `users` FOR EACH ROW
BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`)
  VALUES (NEW.id, 'UPDATE', 'users', NEW.id,
          JSON_OBJECT('email', OLD.email, 'status', OLD.status, 'is_active', OLD.is_active),
          JSON_OBJECT('email', NEW.email, 'status', NEW.status, 'is_active', NEW.is_active));
END$$
DELIMITER ;

-- Trigger for DELETE operations
DELIMITER $$
CREATE TRIGGER `tr_users_after_delete_audit`
AFTER DELETE ON `users` FOR EACH ROW
BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`)
  VALUES (OLD.id, 'DELETE', 'users', OLD.id,
          JSON_OBJECT('email', OLD.email, 'status', OLD.status, 'is_active', OLD.is_active));
END$$
DELIMITER ;
```

---

### 33. Session Management

**SQL Table Structure:**
```sql
CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `device_type` ENUM('desktop','mobile','tablet') DEFAULT 'desktop',
  `login_time` datetime DEFAULT current_timestamp(),
  `last_activity` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🌍 Geographical Features

### 34. Location Hierarchy

**SQL Table Structures:**
```sql
-- Divisions
CREATE TABLE `divisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Districts
CREATE TABLE `districts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `division_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `bbs_code` varchar(10) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upazilas
CREATE TABLE `upazilas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `district_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### 35. Safe Zone Management

**SQL Table Structure:**
```sql
CREATE TABLE `safe_zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `boundary` polygon NOT NULL,
  `safety_level` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  SPATIAL KEY `boundary` (`boundary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🛠️ Backend Automation

### 36. Database Views

**Incident Zones Summary View:**
```sql
CREATE VIEW `v_incident_zones_summary` AS
SELECT
  iz.id,
  iz.zone_name,
  iz.area_name,
  iz.latitude,
  iz.longitude,
  iz.report_count,
  iz.zone_status,
  iz.last_incident_date,
  iz.first_incident_date,
  COUNT(ir.id) AS total_reports,
  COUNT(CASE WHEN ir.severity = 'critical' THEN 1 END) AS critical_count,
  COUNT(CASE WHEN ir.severity = 'high' THEN 1 END) AS high_count,
  COUNT(CASE WHEN ir.status = 'resolved' THEN 1 END) AS resolved_count
FROM incident_zones iz
LEFT JOIN incident_reports ir ON
  ir.location_name COLLATE utf8mb4_unicode_ci = iz.zone_name COLLATE utf8mb4_unicode_ci
  AND ABS(ir.latitude - iz.latitude) < 0.01
  AND ABS(ir.longitude - iz.longitude) < 0.01
  AND ir.status != 'disputed'
GROUP BY iz.id;
```

**Usage:**
```sql
SELECT * FROM v_incident_zones_summary
WHERE zone_status = 'unsafe'
ORDER BY critical_count DESC;
```

---


## 📈 Advanced Analytics & Reporting

This section contains complex SQL queries demonstrating advanced database capabilities.

### Multi-Table JOIN Queries

#### 20. User Activity Dashboard - Complete Overview
Get comprehensive user activity including reports, alerts, courses, and group participation:

```sql
SELECT
    u.id,
    u.display_name,
    u.email,
    COUNT(DISTINCT ir.id) as total_reports,
    COUNT(DISTINCT CASE WHEN ir.status = 'resolved' THEN ir.id END) as resolved_reports,
    COUNT(DISTINCT pa.id) as panic_alerts,
    COUNT(DISTINCT ce.id) as courses_enrolled,
    COUNT(DISTINCT CASE WHEN ce.status = 'completed' THEN ce.id END) as courses_completed,
    COUNT(DISTINCT gm.id) as groups_joined,
    COUNT(DISTINCT d.id) as disputes_filed,
    MAX(ir.reported_date) as last_report_date,
    MAX(pa.triggered_at) as last_panic_alert,
    AVG(ce.progress_percentage) as avg_course_progress
FROM users u
LEFT JOIN incident_reports ir ON u.id = ir.user_id
LEFT JOIN panic_alerts pa ON u.id = pa.user_id
LEFT JOIN course_enrollments ce ON u.id = ce.user_id
LEFT JOIN group_members gm ON u.id = gm.user_id
LEFT JOIN disputes d ON u.id = d.user_id
WHERE u.id IN (1, 2, 6, 7, 8, 9)
GROUP BY u.id, u.display_name, u.email
ORDER BY total_reports DESC;
```

**Purpose**: Provides a complete overview of user engagement across all system features.

**Use Case**: Admin dashboard, user profile summary, activity reports.

---

### Aggregation & GROUP BY Queries

#### 21. Incident Statistics by Category and Severity
Detailed breakdown of incidents with percentages:

```sql
SELECT
    category,
    severity,
    COUNT(*) as incident_count,
    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (), 2) as percentage,
    COUNT(DISTINCT user_id) as unique_reporters,
    COUNT(DISTINCT location_name) as unique_locations,
    AVG(witness_count) as avg_witnesses,
    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
    ROUND(SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as resolution_rate
FROM incident_reports
WHERE user_id IN (1, 2, 6, 7, 8, 9)
GROUP BY category, severity
ORDER BY incident_count DESC;
```

**Purpose**: Statistical breakdown of incidents by type and severity with resolution rates.

**Use Case**: Analytics dashboard, safety reports, resource allocation planning.

---

#### 22. Daily Incident Trends (January 1-17, 2026)
Time-series analysis of incident patterns:

```sql
SELECT
    DATE(incident_date) as incident_day,
    DAYNAME(incident_date) as day_of_week,
    COUNT(*) as total_incidents,
    COUNT(DISTINCT user_id) as unique_reporters,
    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_incidents,
    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_incidents,
    SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium_incidents,
    SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low_incidents,
    GROUP_CONCAT(DISTINCT category) as categories_reported
FROM incident_reports
WHERE incident_date BETWEEN '2026-01-01' AND '2026-01-17'
GROUP BY DATE(incident_date), DAYNAME(incident_date)
ORDER BY incident_day;
```

**Purpose**: Track daily incident trends to identify patterns and peak days.

**Use Case**: Trend analysis, resource planning, safety forecasting.

---

#### 23. Hourly Incident Distribution - Peak Danger Hours
Identify the most dangerous hours of the day:

```sql
SELECT
    HOUR(incident_date) as hour_of_day,
    COUNT(*) as incident_count,
    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (), 2) as percentage,
    GROUP_CONCAT(DISTINCT category) as common_categories,
    AVG(CASE WHEN severity = 'critical' THEN 4
             WHEN severity = 'high' THEN 3
             WHEN severity = 'medium' THEN 2
             ELSE 1 END) as avg_severity_score,
    CASE
        WHEN HOUR(incident_date) BETWEEN 0 AND 5 THEN 'Late Night'
        WHEN HOUR(incident_date) BETWEEN 6 AND 11 THEN 'Morning'
        WHEN HOUR(incident_date) BETWEEN 12 AND 17 THEN 'Afternoon'
        WHEN HOUR(incident_date) BETWEEN 18 AND 21 THEN 'Evening'
        ELSE 'Night'
    END as time_period
FROM incident_reports
WHERE user_id IN (1, 2, 6, 7, 8, 9)
GROUP BY HOUR(incident_date)
ORDER BY incident_count DESC;
```

**Purpose**: Identify peak danger hours for targeted safety measures.

**Use Case**: Patrol scheduling, safety alerts, community awareness campaigns.

---

### Subquery Examples

#### 24. Most Active Users in Each Category
Find top reporters for each incident category:

```sql
SELECT
    category,
    user_id,
    display_name,
    report_count,
    rank_in_category
FROM (
    SELECT
        ir.category,
        ir.user_id,
        u.display_name,
        COUNT(*) as report_count,
        RANK() OVER (PARTITION BY ir.category ORDER BY COUNT(*) DESC) as rank_in_category
    FROM incident_reports ir
    JOIN users u ON ir.user_id = u.id
    WHERE ir.user_id IN (1, 2, 6, 7, 8, 9)
    GROUP BY ir.category, ir.user_id, u.display_name
) ranked_users
WHERE rank_in_category <= 3
ORDER BY category, rank_in_category;
```

**Purpose**: Identify top contributors in each incident category.

**Use Case**: Community recognition, engagement analysis, user profiling.

---

#### 25. Areas with Highest Incident Rates
Identify the most dangerous locations:

```sql
SELECT
    location_name,
    total_incidents,
    critical_count,
    high_count,
    unique_reporters,
    avg_witnesses,
    resolution_rate,
    CASE
        WHEN total_incidents >= 100 THEN 'Very High Risk'
        WHEN total_incidents >= 50 THEN 'High Risk'
        WHEN total_incidents >= 20 THEN 'Moderate Risk'
        ELSE 'Low Risk'
    END as risk_level
FROM (
    SELECT
        location_name,
        COUNT(*) as total_incidents,
        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_count,
        COUNT(DISTINCT user_id) as unique_reporters,
        AVG(witness_count) as avg_witnesses,
        ROUND(SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as resolution_rate
    FROM incident_reports
    WHERE user_id IN (1, 2, 6, 7, 8, 9)
    GROUP BY location_name
) location_stats
ORDER BY total_incidents DESC
LIMIT 20;
```

**Purpose**: Rank locations by danger level for targeted interventions.

**Use Case**: Safety mapping, resource deployment, community alerts.

---

#### 26. Users with Most Completed Courses
Top learners ranked by course completion:

```sql
SELECT
    u.id,
    u.display_name,
    u.email,
    completed_courses,
    total_enrollments,
    ROUND(completed_courses * 100.0 / total_enrollments, 2) as completion_rate,
    avg_rating,
    total_certificates
FROM (
    SELECT
        user_id,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_courses,
        COUNT(*) as total_enrollments,
        AVG(CASE WHEN rating IS NOT NULL THEN rating END) as avg_rating
    FROM course_enrollments
    WHERE user_id IN (1, 2, 6, 7, 8, 9)
    GROUP BY user_id
) ce_stats
JOIN users u ON ce_stats.user_id = u.id
LEFT JOIN (
    SELECT user_id, COUNT(*) as total_certificates
    FROM certificates
    GROUP BY user_id
) cert_stats ON u.id = cert_stats.user_id
ORDER BY completed_courses DESC, completion_rate DESC;
```

**Purpose**: Identify most engaged learners for recognition and incentives.

**Use Case**: Gamification, user engagement, training effectiveness analysis.

---

### CTE (Common Table Expressions)

#### 27. Hierarchical Incident Analysis with CTEs
Complex multi-level analysis using CTEs:

```sql
WITH incident_summary AS (
    SELECT
        user_id,
        COUNT(*) as total_reports,
        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_reports,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_reports
    FROM incident_reports
    WHERE incident_date BETWEEN '2026-01-01' AND '2026-01-17'
    GROUP BY user_id
),
panic_summary AS (
    SELECT
        user_id,
        COUNT(*) as total_alerts,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_alerts,
        SUM(emergency_contacts_notified) as total_contacts_notified
    FROM panic_alerts
    GROUP BY user_id
),
course_summary AS (
    SELECT
        user_id,
        COUNT(*) as total_enrollments,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_courses,
        AVG(progress_percentage) as avg_progress
    FROM course_enrollments
    GROUP BY user_id
)
SELECT
    u.id,
    u.display_name,
    u.email,
    COALESCE(i.total_reports, 0) as incident_reports,
    COALESCE(i.critical_reports, 0) as critical_incidents,
    COALESCE(i.resolved_reports, 0) as resolved_incidents,
    COALESCE(p.total_alerts, 0) as panic_alerts,
    COALESCE(p.resolved_alerts, 0) as resolved_alerts,
    COALESCE(p.total_contacts_notified, 0) as contacts_notified,
    COALESCE(c.total_enrollments, 0) as course_enrollments,
    COALESCE(c.completed_courses, 0) as courses_completed,
    ROUND(COALESCE(c.avg_progress, 0), 2) as avg_course_progress,
    -- Calculate engagement score
    (COALESCE(i.total_reports, 0) * 2 +
     COALESCE(p.total_alerts, 0) * 3 +
     COALESCE(c.completed_courses, 0) * 5) as engagement_score
FROM users u
LEFT JOIN incident_summary i ON u.id = i.user_id
LEFT JOIN panic_summary p ON u.id = p.user_id
LEFT JOIN course_summary c ON u.id = c.user_id
WHERE u.id IN (1, 2, 6, 7, 8, 9)
ORDER BY engagement_score DESC;
```

**Purpose**: Comprehensive user engagement analysis with calculated scores.

**Use Case**: User ranking, engagement metrics, gamification systems.

---

### Window Functions

#### 28. Running Total of Incidents Over Time
Calculate cumulative incidents with running totals:

```sql
SELECT
    incident_date,
    category,
    severity,
    COUNT(*) OVER (ORDER BY incident_date) as running_total,
    COUNT(*) OVER (PARTITION BY category ORDER BY incident_date) as category_running_total,
    COUNT(*) OVER (PARTITION BY severity ORDER BY incident_date) as severity_running_total,
    ROW_NUMBER() OVER (PARTITION BY DATE(incident_date) ORDER BY incident_date) as incident_number_of_day,
    DENSE_RANK() OVER (PARTITION BY category ORDER BY incident_date) as category_rank
FROM incident_reports
WHERE user_id IN (1, 2, 6, 7, 8, 9)
    AND incident_date BETWEEN '2026-01-01' AND '2026-01-17'
ORDER BY incident_date;
```

**Purpose**: Track cumulative incident growth over time.

**Use Case**: Trend visualization, forecasting, performance tracking.

---

#### 29. Moving Average of Daily Incidents
Calculate 3-day and 7-day moving averages:

```sql
SELECT
    incident_day,
    daily_count,
    ROUND(AVG(daily_count) OVER (ORDER BY incident_day ROWS BETWEEN 2 PRECEDING AND CURRENT ROW), 2) as moving_avg_3day,
    ROUND(AVG(daily_count) OVER (ORDER BY incident_day ROWS BETWEEN 6 PRECEDING AND CURRENT ROW), 2) as moving_avg_7day,
    MAX(daily_count) OVER (ORDER BY incident_day ROWS BETWEEN 6 PRECEDING AND CURRENT ROW) as max_last_7days,
    MIN(daily_count) OVER (ORDER BY incident_day ROWS BETWEEN 6 PRECEDING AND CURRENT ROW) as min_last_7days
FROM (
    SELECT
        DATE(incident_date) as incident_day,
        COUNT(*) as daily_count
    FROM incident_reports
    WHERE user_id IN (1, 2, 6, 7, 8, 9)
        AND incident_date BETWEEN '2026-01-01' AND '2026-01-17'
    GROUP BY DATE(incident_date)
) daily_incidents
ORDER BY incident_day;
```

**Purpose**: Smooth out daily fluctuations to identify true trends.

**Use Case**: Trend analysis, anomaly detection, forecasting.

---

### Spatial Queries

#### 30. Find Incidents Within Radius of a Location
Get all incidents within 2km of a specific point (Dhanmondi):

```sql
SELECT
    id,
    title,
    category,
    severity,
    location_name,
    latitude,
    longitude,
    incident_date,
    haversine_distance(23.7465, 90.3700, latitude, longitude) as distance_km
FROM incident_reports
WHERE user_id IN (1, 2, 6, 7, 8, 9)
    AND haversine_distance(23.7465, 90.3700, latitude, longitude) <= 2.0
ORDER BY distance_km ASC
LIMIT 50;
```

**Purpose**: Find incidents near a specific location using GPS coordinates.

**Use Case**: Location-based alerts, proximity search, safety radius analysis.

---

#### 31. Nearest Safe Spaces to Incident Locations
Find closest safe spaces for each incident:

```sql
SELECT
    ir.id as incident_id,
    ir.title as incident_title,
    ir.location_name as incident_location,
    ln.name as safe_space_name,
    ln.category as safe_space_type,
    ln.safety_score,
    ln.address,
    ln.contact,
    haversine_distance(ir.latitude, ir.longitude, ln.latitude, ln.longitude) as distance_km
FROM incident_reports ir
CROSS JOIN leaf_nodes ln
WHERE ir.user_id IN (1, 2, 6, 7, 8, 9)
    AND ln.safety_score >= 7.0
    AND haversine_distance(ir.latitude, ir.longitude, ln.latitude, ln.longitude) <= 1.0
ORDER BY ir.id, distance_km ASC;
```

**Purpose**: Help users find nearby safe spaces during emergencies.

**Use Case**: Emergency response, safe space recommendations, evacuation planning.

---

#### 32. Incident Density Heatmap Data
Generate data for heatmap visualization:

```sql
SELECT
    ROUND(latitude, 3) as lat_grid,
    ROUND(longitude, 3) as lon_grid,
    COUNT(*) as incident_count,
    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_count,
    AVG(latitude) as center_lat,
    AVG(longitude) as center_lon,
    GROUP_CONCAT(DISTINCT category) as categories
FROM incident_reports
WHERE user_id IN (1, 2, 6, 7, 8, 9)
    AND latitude IS NOT NULL
    AND longitude IS NOT NULL
GROUP BY ROUND(latitude, 3), ROUND(longitude, 3)
HAVING incident_count >= 5
ORDER BY incident_count DESC;
```

**Purpose**: Generate grid-based data for creating incident heatmaps.

**Use Case**: Heatmap visualization, hotspot identification, safety mapping.

---

### Date/Time Calculations

#### 33. Response Time Analysis
Analyze how quickly incidents are being addressed:

```sql
SELECT
    category,
    severity,
    status,
    COUNT(*) as total_incidents,
    AVG(TIMESTAMPDIFF(HOUR, reported_date, updated_date)) as avg_response_hours,
    MIN(TIMESTAMPDIFF(HOUR, reported_date, updated_date)) as min_response_hours,
    MAX(TIMESTAMPDIFF(HOUR, reported_date, updated_date)) as max_response_hours,
    STDDEV(TIMESTAMPDIFF(HOUR, reported_date, updated_date)) as stddev_response_hours,
    SUM(CASE WHEN TIMESTAMPDIFF(HOUR, reported_date, updated_date) <= 24 THEN 1 ELSE 0 END) as resolved_within_24h,
    ROUND(SUM(CASE WHEN TIMESTAMPDIFF(HOUR, reported_date, updated_date) <= 24 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as pct_within_24h
FROM incident_reports
WHERE user_id IN (1, 2, 6, 7, 8, 9)
    AND status IN ('resolved', 'closed')
    AND updated_date > reported_date
GROUP BY category, severity, status
ORDER BY avg_response_hours;
```

**Purpose**: Measure system efficiency and response times.

**Use Case**: Performance metrics, SLA monitoring, process improvement.

---

#### 34. Course Completion Time Analysis
Analyze how long users take to complete courses:

```sql
SELECT
    ce.course_id,
    sc.course_title,
    sc.duration_minutes as estimated_duration,
    COUNT(*) as total_completions,
    AVG(TIMESTAMPDIFF(DAY, ce.started_at, ce.completed_at)) as avg_days_to_complete,
    MIN(TIMESTAMPDIFF(DAY, ce.started_at, ce.completed_at)) as fastest_completion_days,
    MAX(TIMESTAMPDIFF(DAY, ce.started_at, ce.completed_at)) as slowest_completion_days,
    AVG(ce.rating) as avg_rating,
    COUNT(DISTINCT ce.user_id) as unique_completers
FROM course_enrollments ce
JOIN safety_courses sc ON ce.course_id = sc.id
WHERE ce.status = 'completed'
    AND ce.user_id IN (1, 2, 6, 7, 8, 9)
    AND ce.completed_at IS NOT NULL
GROUP BY ce.course_id, sc.course_title, sc.duration_minutes
ORDER BY total_completions DESC;
```

**Purpose**: Evaluate course effectiveness and user engagement.

**Use Case**: Course optimization, content improvement, user experience analysis.

---

### Complex Filtering & Correlation

#### 35. Multi-Criteria Incident Search
Advanced search with multiple filters and priority calculation:

```sql
SELECT
    ir.id,
    ir.title,
    ir.category,
    ir.severity,
    ir.status,
    u.display_name as reporter,
    ir.location_name,
    ir.incident_date,
    ir.witness_count,
    COUNT(d.id) as dispute_count,
    iz.zone_status,
    CASE
        WHEN ir.severity = 'critical' AND iz.zone_status = 'unsafe' THEN 'URGENT'
        WHEN ir.severity IN ('critical', 'high') THEN 'HIGH PRIORITY'
        WHEN iz.zone_status = 'unsafe' THEN 'MONITOR'
        ELSE 'NORMAL'
    END as priority_level
FROM incident_reports ir
JOIN users u ON ir.user_id = u.id
LEFT JOIN disputes d ON ir.id = d.report_id
LEFT JOIN incident_zones iz ON ir.location_name = iz.zone_name
WHERE ir.user_id IN (1, 2, 6, 7, 8, 9)
    AND ir.incident_date BETWEEN '2026-01-01' AND '2026-01-17'
    AND (
        (ir.severity IN ('critical', 'high') AND ir.status = 'pending')
        OR (iz.zone_status = 'unsafe')
        OR (ir.witness_count >= 3)
    )
GROUP BY ir.id
HAVING dispute_count = 0
ORDER BY
    CASE priority_level
        WHEN 'URGENT' THEN 1
        WHEN 'HIGH PRIORITY' THEN 2
        WHEN 'MONITOR' THEN 3
        ELSE 4
    END,
    ir.incident_date DESC;
```

**Purpose**: Advanced filtering with dynamic priority assignment.

**Use Case**: Incident triage, priority queue management, resource allocation.

---

#### 36. Correlation Between User Activity and Safety Outcomes
Analyze if active users have better safety outcomes:

```sql
SELECT
    user_activity_level,
    COUNT(*) as user_count,
    AVG(total_reports) as avg_reports,
    AVG(panic_alerts) as avg_panic_alerts,
    AVG(courses_completed) as avg_courses_completed,
    AVG(resolution_rate) as avg_resolution_rate,
    AVG(avg_response_time_hours) as avg_response_time
FROM (
    SELECT
        u.id,
        u.display_name,
        COUNT(DISTINCT ir.id) as total_reports,
        COUNT(DISTINCT pa.id) as panic_alerts,
        COUNT(DISTINCT CASE WHEN ce.status = 'completed' THEN ce.id END) as courses_completed,
        ROUND(SUM(CASE WHEN ir.status = 'resolved' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(ir.id), 0), 2) as resolution_rate,
        AVG(TIMESTAMPDIFF(HOUR, ir.reported_date, ir.updated_date)) as avg_response_time_hours,
        CASE
            WHEN COUNT(DISTINCT ir.id) + COUNT(DISTINCT pa.id) + COUNT(DISTINCT ce.id) >= 1500 THEN 'Very Active'
            WHEN COUNT(DISTINCT ir.id) + COUNT(DISTINCT pa.id) + COUNT(DISTINCT ce.id) >= 1000 THEN 'Active'
            WHEN COUNT(DISTINCT ir.id) + COUNT(DISTINCT pa.id) + COUNT(DISTINCT ce.id) >= 500 THEN 'Moderate'
            ELSE 'Low Activity'
        END as user_activity_level
    FROM users u
    LEFT JOIN incident_reports ir ON u.id = ir.user_id
    LEFT JOIN panic_alerts pa ON u.id = pa.user_id
    LEFT JOIN course_enrollments ce ON u.id = ce.user_id
    WHERE u.id IN (1, 2, 6, 7, 8, 9)
    GROUP BY u.id, u.display_name
) user_stats
GROUP BY user_activity_level
ORDER BY
    CASE user_activity_level
        WHEN 'Very Active' THEN 1
        WHEN 'Active' THEN 2
        WHEN 'Moderate' THEN 3
        ELSE 4
    END;
```

**Purpose**: Identify correlations between user engagement and safety outcomes.

**Use Case**: Engagement analysis, effectiveness measurement, user segmentation.

---

### Summary

These 18+ complex queries demonstrate:

✅ **Multi-table JOINs** - Combining data from 5+ tables
✅ **Aggregations** - Statistical analysis with GROUP BY
✅ **Subqueries** - Nested queries for complex filtering
✅ **CTEs** - Readable, maintainable complex queries
✅ **Window Functions** - Running totals, rankings, moving averages
✅ **Spatial Calculations** - Distance-based queries using haversine function
✅ **Date/Time Analysis** - Response times, trends, patterns
✅ **Complex Filtering** - Multi-criteria search with dynamic prioritization

All queries are optimized for the test dataset with ~30,000+ records across users 1, 2, 6, 7, 8, 9 and dates between January 1-17, 2026.

---

**Last Updated:** January 16, 2026


## 📋 Summary

**Total Features: 46+**

This system includes:
- ✅ Complete User Management
- ✅ Incident Reporting & Tracking
- ✅ Real-time Map & Zone Monitoring
- ✅ Emergency SOS System
- ✅ Community Groups & Alerts
- ✅ Education & Training
- ✅ Legal & Medical Aid
- ✅ Advanced Security
- ✅ Comprehensive Analytics

---

**Last Updated:** January 11, 2026

**Database Engine:** MySQL/MariaDB
**Character Set:** UTF-8 (utf8mb4)
**Collation:** utf8mb4_general_ci / utf8mb4_unicode_ci
