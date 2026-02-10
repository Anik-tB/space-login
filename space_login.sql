-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 09, 2026 at 04:43 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `space_login`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `find_nearby_users` (IN `alert_lat` DECIMAL(10,8), IN `alert_lng` DECIMAL(11,8), IN `radius_meters` INT, IN `exclude_user_id` INT)   BEGIN
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
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `update_incident_zone` (IN `p_zone_name` VARCHAR(255), IN `p_area_name` VARCHAR(255), IN `p_latitude` DECIMAL(10,8), IN `p_longitude` DECIMAL(11,8), IN `p_incident_date` DATETIME)   BEGIN
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

    -- Determine status
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

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `haversine_distance` (`lat1` DECIMAL(10,8), `lon1` DECIMAL(11,8), `lat2` DECIMAL(10,8), `lon2` DECIMAL(11,8)) RETURNS DECIMAL(10,2) DETERMINISTIC READS SQL DATA BEGIN
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

CREATE DEFINER=`root`@`localhost` FUNCTION `update_zone_status` (`report_count` INT) RETURNS ENUM('safe','moderate','unsafe') CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC READS SQL DATA BEGIN
    IF report_count >= 5 THEN
        RETURN 'unsafe';
    ELSEIF report_count >= 3 THEN
        RETURN 'moderate';
    ELSE
        RETURN 'safe';
    END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `active_users_with_location`
-- (See below for the actual view)
--
CREATE TABLE `active_users_with_location` (
`id` int(11)
,`email` varchar(100)
,`display_name` varchar(100)
,`current_latitude` decimal(10,8)
,`current_longitude` decimal(11,8)
,`is_online` tinyint(1)
,`last_seen` timestamp
,`allow_community_alerts` tinyint(1)
,`alert_radius` int(11)
,`notify_push` tinyint(1)
,`notify_sound` tinyint(1)
,`notify_email` tinyint(1)
);

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('emergency','warning','info','update') DEFAULT 'info',
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `location_name` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `radius_km` decimal(5,2) DEFAULT 1.00,
  `start_time` datetime DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `source_type` enum('system','user_report','admin','police','community') DEFAULT 'system',
  `source_user_id` int(11) DEFAULT NULL,
  `related_report_id` int(11) DEFAULT NULL,
  `views_count` int(11) DEFAULT 0,
  `acknowledgments_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `alerts`
--

INSERT INTO `alerts` (`id`, `title`, `description`, `type`, `severity`, `location_name`, `latitude`, `longitude`, `radius_km`, `start_time`, `end_time`, `is_active`, `source_type`, `source_user_id`, `related_report_id`, `views_count`, `acknowledgments_count`) VALUES
(5, 'সন্দেহজনক কার্যকলাপ সতর্কতা', 'মহাখালী বাস স্ট্যান্ড এলাকায় সন্দেহজনক ব্যক্তিদের দেখা গেছে। সতর্ক থাকুন এবং রাতে একা চলাচল এড়িয়ে চলুন।', 'warning', 'high', 'মহাখালী বাস স্ট্যান্ড', 23.78010000, 90.40530000, 1.50, '2026-01-24 14:00:00', '2026-01-26 14:00:00', 1, 'community', 5, NULL, 0, 0),
(6, 'জরুরি: ট্রাফিক দুর্ঘটনা', 'ফার্মগেট মোড়ে বড় ধরনের ট্রাফিক দুর্ঘটনা ঘটেছে। রাস্তা সম্পূর্ণ বন্ধ। বিকল্প পথ ব্যবহার করুন।', 'emergency', 'critical', 'ফার্মগেট মোড়', 23.75750000, 90.38890000, 2.00, '2026-01-24 13:30:00', '2026-01-24 18:00:00', 1, 'police', 6, NULL, 0, 0),
(7, 'hulaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'ddddddddddddddddddddddddddddddd', 'warning', 'medium', 'dfdsfsdfdf', -0.00000100, 0.00000100, 1.20, '2026-02-09 19:35:54', '2026-02-08 19:35:00', 1, 'admin', 4, NULL, 0, 0),
(8, '🚨 Emergency Alert Nearby', 'Someone in your area needs immediate help! This is an emergency panic alert.', 'emergency', 'critical', '23.81444352890792, 90.38659543342219', 23.81444353, 90.38659543, 5.00, '2026-02-09 20:12:45', NULL, 1, 'community', 4, NULL, 0, 0),
(9, '🚨 Emergency Alert Nearby', 'Someone in your area needs immediate help! This is an emergency panic alert.', 'emergency', 'critical', '23.814424411656717, 90.38656005061553', 23.81442441, 90.38656005, 5.00, '2026-02-09 20:12:54', NULL, 1, 'community', 4, NULL, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `alert_responses`
--

CREATE TABLE `alert_responses` (
  `id` int(11) NOT NULL,
  `alert_id` int(11) NOT NULL,
  `responder_id` int(11) NOT NULL,
  `response_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `eta_minutes` int(11) DEFAULT NULL,
  `status` enum('responding','arrived','cancelled') DEFAULT 'responding',
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `alert_responses`
--
DELIMITER $$
CREATE TRIGGER `update_responders_count_insert` AFTER INSERT ON `alert_responses` FOR EACH ROW BEGIN
    UPDATE panic_alerts
    SET responders_count = (
        SELECT COUNT(*)
        FROM alert_responses
        WHERE alert_id = NEW.alert_id
          AND status = 'responding'
    )
    WHERE id = NEW.alert_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_responders_count_update` AFTER UPDATE ON `alert_responses` FOR EACH ROW BEGIN
    UPDATE panic_alerts
    SET responders_count = (
        SELECT COUNT(*)
        FROM alert_responses
        WHERE alert_id = NEW.alert_id
          AND status = 'responding'
    )
    WHERE id = NEW.alert_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `area_safety_scores`
--

CREATE TABLE `area_safety_scores` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `area_safety_scores`
--

INSERT INTO `area_safety_scores` (`id`, `area_name`, `ward_number`, `union_name`, `division_id`, `district_id`, `upazila_id`, `safety_score`, `incident_rate_score`, `resolution_rate_score`, `response_time_score`, `user_ratings_score`, `critical_incidents_score`, `total_incidents`, `resolved_incidents`, `critical_incidents`, `response_time_avg_hours`, `last_updated`, `created_at`) VALUES
(1, 'Dhanmondi', '27', 'Dhanmondi', 1, 1, 1, 5.47, 10.00, 4.48, 0.00, 5.00, 6.00, 29, 13, 12, 161.65, '2026-01-24 12:51:33', '2025-11-16 14:35:50'),
(2, 'Gulshan', '19', 'Gulshan', 1, 1, 2, 4.91, 10.00, 1.85, 0.00, 5.00, 7.00, 27, 5, 9, 82.60, '2026-01-24 12:51:34', '2025-11-16 14:35:50'),
(3, 'Mirpur', '10', 'Mirpur', 1, 1, 3, 5.55, 10.00, 2.08, 0.00, 10.00, 5.33, 24, 5, 14, 85.68, '2026-01-24 12:51:33', '2025-11-16 14:35:50'),
(4, 'Uttara', '1', 'Uttara', 1, 1, 4, 4.67, 10.00, 2.22, 0.00, 5.00, 3.67, 27, 6, 19, 132.58, '2026-01-24 12:51:34', '2025-11-16 14:35:50'),
(5, 'Banani', '18', 'Banani', 1, 1, 5, 8.63, 10.00, 7.50, 10.00, 5.00, 10.00, 0, 0, 0, 0.00, '2026-01-24 12:51:34', '2025-11-16 14:35:50'),
(6, 'Wari', '15', 'Wari', 1, 1, 6, 9.38, 10.00, 7.50, 10.00, 10.00, 10.00, 0, 0, 0, 0.00, '2026-01-24 12:51:33', '2025-11-16 14:35:50'),
(7, 'Motijheel', '12', 'Motijheel', 1, 1, 7, 8.63, 10.00, 7.50, 10.00, 5.00, 10.00, 0, 0, 0, 0.00, '2026-01-24 12:51:33', '2025-11-16 14:35:50');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_url` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `request_url`, `created_at`) VALUES
(1, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-20 12:19:12'),
(2, 1, 'INSERT', 'incident_reports', 104, NULL, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-20 18:23:31'),
(3, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-22 13:23:28'),
(4, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-22 19:21:21'),
(5, 1, 'INSERT', 'incident_reports', 105, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(6, 1, 'INSERT', 'incident_reports', 106, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(7, 1, 'INSERT', 'incident_reports', 107, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(8, 1, 'INSERT', 'incident_reports', 108, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(9, 1, 'INSERT', 'incident_reports', 109, NULL, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(10, 1, 'INSERT', 'incident_reports', 110, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(11, 1, 'INSERT', 'incident_reports', 111, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(12, 1, 'INSERT', 'incident_reports', 112, NULL, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(13, 1, 'INSERT', 'incident_reports', 113, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(14, 1, 'INSERT', 'incident_reports', 114, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-22 19:59:26'),
(15, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-22 21:46:15'),
(16, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 10:47:26'),
(17, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 18:16:46'),
(18, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 18:19:56'),
(19, 3, 'INSERT', 'users', 3, NULL, '{\"email\": \"admin@test.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 18:23:13'),
(20, 1, 'UPDATE', 'incident_reports', 112, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 18:39:47'),
(21, 3, 'admin_approval', 'incident_report', 112, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 18:39:47'),
(22, 1, 'UPDATE', 'incident_reports', 104, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 18:39:55'),
(23, 3, 'admin_approval', 'incident_report', 104, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 18:39:55'),
(24, 4, 'INSERT', 'users', 4, NULL, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 19:31:48'),
(25, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 19:33:15'),
(26, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 19:33:53'),
(27, 4, 'admin_login', 'users', 4, NULL, '{\"login_time\":\"2025-11-23 14:33:53\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2025-11-23 19:33:53'),
(28, 4, 'admin_approval', 'community_group', 21, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"community_group\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 19:36:20'),
(29, 1, 'UPDATE', 'incident_reports', 114, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 19:45:37'),
(30, 4, 'admin_approval', 'incident_report', 114, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 19:45:37'),
(31, 1, 'UPDATE', 'incident_reports', 110, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 19:45:39'),
(32, 4, 'admin_approval', 'incident_report', 110, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 19:45:39'),
(33, 1, 'UPDATE', 'incident_reports', 109, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 19:45:48'),
(34, 4, 'admin_approval', 'incident_report', 109, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 19:45:48'),
(35, 2, 'UPDATE', 'incident_reports', 103, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:04:29'),
(36, 4, 'admin_approval', 'incident_report', 103, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:04:29'),
(37, 1, 'UPDATE', 'incident_reports', 107, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:09:22'),
(38, 4, 'admin_approval', 'incident_report', 107, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:09:22'),
(39, 1, 'UPDATE', 'incident_reports', 3, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:09:38'),
(40, 4, 'admin_approval', 'incident_report', 3, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:09:38'),
(41, 1, 'UPDATE', 'incident_reports', 105, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:09:42'),
(42, 4, 'admin_approval', 'incident_report', 105, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:09:42'),
(43, 1, 'UPDATE', 'incident_reports', 106, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:09:47'),
(44, 4, 'admin_approval', 'incident_report', 106, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:09:47'),
(45, 1, 'UPDATE', 'incident_reports', 111, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:09:51'),
(46, 4, 'admin_approval', 'incident_report', 111, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:09:51'),
(47, 1, 'UPDATE', 'incident_reports', 102, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:09:55'),
(48, 4, 'admin_approval', 'incident_report', 102, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:09:55'),
(49, 1, 'UPDATE', 'incident_reports', 113, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:09:59'),
(50, 4, 'admin_approval', 'incident_report', 113, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:09:59'),
(51, 1, 'UPDATE', 'incident_reports', 49, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:16:17'),
(52, 4, 'admin_approval', 'incident_report', 49, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:16:17'),
(53, 1, 'UPDATE', 'incident_reports', 101, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:16:23'),
(54, 4, 'admin_approval', 'incident_report', 101, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:16:23'),
(55, 1, 'UPDATE', 'incident_reports', 56, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:16:42'),
(56, 4, 'admin_approval', 'incident_report', 56, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:16:42'),
(57, 1, 'UPDATE', 'incident_reports', 57, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:16:54'),
(58, 4, 'admin_approval', 'incident_report', 57, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:16:54'),
(59, 1, 'UPDATE', 'incident_reports', 6, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:16:59'),
(60, 4, 'admin_approval', 'incident_report', 6, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:16:59'),
(61, 1, 'UPDATE', 'incident_reports', 5, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:03'),
(62, 4, 'admin_approval', 'incident_report', 5, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:03'),
(63, 1, 'UPDATE', 'incident_reports', 2, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:07'),
(64, 4, 'admin_approval', 'incident_report', 2, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:07'),
(65, 1, 'UPDATE', 'incident_reports', 10, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:12'),
(66, 4, 'admin_approval', 'incident_report', 10, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:12'),
(67, 1, 'UPDATE', 'incident_reports', 8, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:16'),
(68, 4, 'admin_approval', 'incident_report', 8, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:16'),
(69, 1, 'UPDATE', 'incident_reports', 11, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:22'),
(70, 4, 'admin_approval', 'incident_report', 11, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:22'),
(71, 1, 'UPDATE', 'incident_reports', 25, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:34'),
(72, 4, 'admin_approval', 'incident_report', 25, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:34'),
(73, 1, 'UPDATE', 'incident_reports', 12, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:38'),
(74, 4, 'admin_approval', 'incident_report', 12, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:38'),
(75, 1, 'UPDATE', 'incident_reports', 22, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:41'),
(76, 4, 'admin_approval', 'incident_report', 22, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:41'),
(77, 1, 'UPDATE', 'incident_reports', 14, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:46'),
(78, 4, 'admin_approval', 'incident_report', 14, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:46'),
(79, 1, 'UPDATE', 'incident_reports', 13, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:50'),
(80, 4, 'admin_approval', 'incident_report', 13, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:50'),
(81, 1, 'UPDATE', 'incident_reports', 35, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:54'),
(82, 4, 'admin_approval', 'incident_report', 35, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:54'),
(83, 1, 'UPDATE', 'incident_reports', 32, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:17:57'),
(84, 4, 'admin_approval', 'incident_report', 32, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:17:57'),
(85, 1, 'UPDATE', 'incident_reports', 24, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:18:00'),
(86, 4, 'admin_approval', 'incident_report', 24, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:18:00'),
(87, 1, 'UPDATE', 'incident_reports', 40, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:18:04'),
(88, 4, 'admin_approval', 'incident_report', 40, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:18:04'),
(89, 1, 'UPDATE', 'incident_reports', 43, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:18:07'),
(90, 4, 'admin_approval', 'incident_report', 43, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:18:07'),
(91, 1, 'UPDATE', 'incident_reports', 41, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:18:10'),
(92, 4, 'admin_approval', 'incident_report', 41, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:18:10'),
(93, 1, 'UPDATE', 'incident_reports', 55, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:18:13'),
(94, 4, 'admin_approval', 'incident_report', 55, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:18:13'),
(95, 1, 'UPDATE', 'incident_reports', 46, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2025-11-23 20:18:17'),
(96, 4, 'admin_approval', 'incident_report', 46, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"incident_report\",\"notes\":\"\"}', NULL, NULL, NULL, '2025-11-23 20:18:17'),
(97, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-23 22:55:18'),
(98, 1, 'sos_alert', 'walk_sessions', 2, NULL, '{\"session_token\":\"e73051fe72f264daf9f4245a2b2b0a937e21115ed1a53fa1ede22ac8dc9ad61b\",\"status\":\"emergency\",\"contacts_notified\":0}', NULL, NULL, NULL, '2025-11-23 23:07:28'),
(99, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-24 04:38:32'),
(100, 4, 'admin_login', 'users', 4, NULL, '{\"login_time\":\"2025-11-23 23:38:32\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2025-11-24 04:38:32'),
(101, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-24 04:47:17'),
(102, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-24 07:58:14'),
(103, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-24 08:02:09'),
(104, 4, 'admin_login', 'users', 4, NULL, '{\"login_time\":\"2025-11-24 03:02:09\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2025-11-24 08:02:09'),
(105, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-24 08:02:09'),
(106, 4, 'admin_login', 'users', 4, NULL, '{\"login_time\":\"2025-11-24 03:02:09\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2025-11-24 08:02:09'),
(107, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-11-26 21:58:24'),
(108, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-12-06 09:23:03'),
(109, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-12-08 06:34:25'),
(110, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-12-08 12:47:23'),
(111, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-12-15 05:24:41'),
(112, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-12-15 05:25:30'),
(113, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2025-12-30 08:14:15'),
(114, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-17 17:40:08'),
(115, 1, 'INSERT', 'incident_reports', 115, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-01-17 17:45:09'),
(116, 5, 'INSERT', 'users', 5, NULL, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 08:35:36'),
(117, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 08:35:45'),
(118, 6, 'INSERT', 'users', 6, NULL, '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 08:42:18'),
(119, 6, 'UPDATE', 'users', 6, '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 08:42:26'),
(120, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 09:12:55'),
(121, 6, 'UPDATE', 'users', 6, '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 09:13:12'),
(122, 6, 'panic_alert_from_walk', 'walk_sessions', 3, NULL, '{\"panic_alert_id\":5,\"walk_session_token\":\"58bca15d5ef35c5b95de6688680809b14efaea0ad3ec935086d31b886cadeed6\"}', NULL, NULL, NULL, '2026-01-24 09:16:22'),
(123, 6, 'sos_alert', 'walk_sessions', 3, NULL, '{\"session_token\":\"58bca15d5ef35c5b95de6688680809b14efaea0ad3ec935086d31b886cadeed6\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":5}', NULL, NULL, NULL, '2026-01-24 09:16:22'),
(124, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 09:58:34'),
(125, 6, 'UPDATE', 'users', 6, '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 09:58:58'),
(126, 6, 'panic_alert_from_walk', 'walk_sessions', 4, NULL, '{\"panic_alert_id\":6,\"walk_session_token\":\"6f6bdb204ab747efd886926ce48a995c1981af3afdcb87326f7758b1a048b84b\"}', NULL, NULL, NULL, '2026-01-24 09:59:30'),
(127, 6, 'sos_alert', 'walk_sessions', 4, NULL, '{\"session_token\":\"6f6bdb204ab747efd886926ce48a995c1981af3afdcb87326f7758b1a048b84b\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":6}', NULL, NULL, NULL, '2026-01-24 09:59:30'),
(128, 6, 'panic_alert_from_walk', 'walk_sessions', 5, NULL, '{\"panic_alert_id\":7,\"walk_session_token\":\"7862a4cfb79b3ff2508fcc6a19cc4626ddf15d8e646e56619c4ad5f9f7cb4472\"}', NULL, NULL, NULL, '2026-01-24 10:02:51'),
(129, 6, 'sos_alert', 'walk_sessions', 5, NULL, '{\"session_token\":\"7862a4cfb79b3ff2508fcc6a19cc4626ddf15d8e646e56619c4ad5f9f7cb4472\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":7}', NULL, NULL, NULL, '2026-01-24 10:02:51'),
(130, 6, 'community_alert_broadcast', 'panic_alerts', 8, NULL, '{\"panic_alert_id\":8,\"nearby_users_notified\":0,\"broadcast_radius\":5000,\"walk_session_token\":\"8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a\"}', NULL, NULL, NULL, '2026-01-24 10:17:41'),
(131, 6, 'panic_alert_from_walk', 'walk_sessions', 6, NULL, '{\"panic_alert_id\":8,\"walk_session_token\":\"8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a\"}', NULL, NULL, NULL, '2026-01-24 10:17:41'),
(132, 6, 'sos_alert', 'walk_sessions', 6, NULL, '{\"session_token\":\"8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":8,\"community_users_notified\":0}', NULL, NULL, NULL, '2026-01-24 10:17:41'),
(133, 5, 'community_alert_broadcast', 'panic_alerts', 9, NULL, '{\"panic_alert_id\":9,\"nearby_users_notified\":0,\"broadcast_radius\":5000,\"walk_session_token\":\"811ff24d743e03d6ed2e50de0b6663e13fe6440d78c8e25e5bf3649c56d74965\"}', NULL, NULL, NULL, '2026-01-24 10:22:47'),
(134, 5, 'panic_alert_from_walk', 'walk_sessions', 7, NULL, '{\"panic_alert_id\":9,\"walk_session_token\":\"811ff24d743e03d6ed2e50de0b6663e13fe6440d78c8e25e5bf3649c56d74965\"}', NULL, NULL, NULL, '2026-01-24 10:22:47'),
(135, 5, 'sos_alert', 'walk_sessions', 7, NULL, '{\"session_token\":\"811ff24d743e03d6ed2e50de0b6663e13fe6440d78c8e25e5bf3649c56d74965\",\"status\":\"emergency\",\"contacts_notified\":0,\"panic_alert_id\":9,\"community_users_notified\":0}', NULL, NULL, NULL, '2026-01-24 10:22:47'),
(136, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 11:58:05'),
(137, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 12:01:00'),
(138, 5, 'INSERT', 'incident_reports', 116, NULL, '{\"status\": \"pending\", \"severity\": \"critical\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-01-24 12:14:54'),
(139, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 12:16:41'),
(140, 4, 'admin_login', 'users', 4, NULL, '{\"login_time\":\"2026-01-24 07:16:41\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2026-01-24 12:16:41'),
(141, 4, 'panic_alert_from_walk', 'walk_sessions', 8, NULL, '{\"panic_alert_id\":10,\"walk_session_token\":\"21e6b60b84c43ad0737dc9c5dcc7c4813e7a2d45891eb73f33c755f728a969d7\"}', NULL, NULL, NULL, '2026-01-24 12:55:11'),
(142, 4, 'sos_alert', 'walk_sessions', 8, NULL, '{\"session_token\":\"21e6b60b84c43ad0737dc9c5dcc7c4813e7a2d45891eb73f33c755f728a969d7\",\"status\":\"emergency\",\"contacts_notified\":0,\"panic_alert_id\":10}', NULL, NULL, NULL, '2026-01-24 12:55:11'),
(143, 4, 'admin_approval', 'safe_space', 1, NULL, '{\"action\":\"approve\",\"approval_status\":\"approved\",\"item_type\":\"safe_space\",\"notes\":\"\"}', NULL, NULL, NULL, '2026-01-24 13:07:49'),
(144, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 13:45:11'),
(145, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-24 13:48:30'),
(146, 4, 'admin_login', 'users', 4, NULL, '{\"login_time\":\"2026-01-24 08:48:30\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2026-01-24 13:48:30'),
(147, 4, 'panic_alert_from_walk', 'walk_sessions', 9, NULL, '{\"panic_alert_id\":11,\"walk_session_token\":\"1b0b732fda336709d2fecdadaff5bff0318bb0bc14ccf18918b66ccb87c6b656\"}', NULL, NULL, NULL, '2026-01-24 14:41:28'),
(148, 4, 'sos_alert', 'walk_sessions', 9, NULL, '{\"session_token\":\"1b0b732fda336709d2fecdadaff5bff0318bb0bc14ccf18918b66ccb87c6b656\",\"status\":\"emergency\",\"contacts_notified\":0,\"panic_alert_id\":11}', NULL, NULL, NULL, '2026-01-24 14:41:28'),
(149, 4, 'panic_alert_from_walk', 'walk_sessions', 10, NULL, '{\"panic_alert_id\":12,\"walk_session_token\":\"3a92490418120d1654e23696e29c78a9793127d0095a0fcbc755463651b92066\"}', NULL, NULL, NULL, '2026-01-24 14:53:00'),
(150, 4, 'sos_alert', 'walk_sessions', 10, NULL, '{\"session_token\":\"3a92490418120d1654e23696e29c78a9793127d0095a0fcbc755463651b92066\",\"status\":\"emergency\",\"contacts_notified\":0,\"panic_alert_id\":12}', NULL, NULL, NULL, '2026-01-24 14:53:00'),
(151, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-26 03:58:48'),
(152, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-01-26 12:27:58'),
(153, 5, 'UPDATE', 'users', 5, '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"hulahuhu152@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-09 19:15:30'),
(154, 4, 'UPDATE', 'users', 4, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-09 19:34:33'),
(155, 4, 'admin_login', 'users', 4, NULL, '{\"login_time\":\"2026-02-09 14:34:34\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2026-02-09 19:34:34'),
(156, 4, 'admin_create_alert', 'alerts', 7, NULL, '{\"title\":\"hulaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"severity\":\"medium\",\"type\":\"warning\",\"location\":\"dfdsfsdfdf\"}', NULL, NULL, NULL, '2026-02-09 19:35:54'),
(157, 6, 'UPDATE', 'users', 6, '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabu018717@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-09 19:58:27'),
(158, 6, 'panic_alert_from_walk', 'walk_sessions', 11, NULL, '{\"panic_alert_id\":13,\"walk_session_token\":\"0ee137c065df04c36495fa1cf26569667c72c92b647f5a3021b1747926d79fd4\"}', NULL, NULL, NULL, '2026-02-09 19:58:54'),
(159, 6, 'sos_alert', 'walk_sessions', 11, NULL, '{\"session_token\":\"0ee137c065df04c36495fa1cf26569667c72c92b647f5a3021b1747926d79fd4\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":13}', NULL, NULL, NULL, '2026-02-09 19:58:54'),
(160, 4, 'panic_alert_from_walk', 'walk_sessions', 12, NULL, '{\"panic_alert_id\":15,\"walk_session_token\":\"79a8c36da85e4b2b2ab4c43cd3405f77d0155bb17ff70acf195cccc52c154bbb\"}', NULL, NULL, NULL, '2026-02-09 20:01:23'),
(161, 4, 'sos_alert', 'walk_sessions', 12, NULL, '{\"session_token\":\"79a8c36da85e4b2b2ab4c43cd3405f77d0155bb17ff70acf195cccc52c154bbb\",\"status\":\"emergency\",\"contacts_notified\":0,\"panic_alert_id\":15}', NULL, NULL, NULL, '2026-02-09 20:01:23'),
(162, 4, 'community_alert_broadcast', 'panic_alerts', 16, NULL, '{\"broadcast_radius\":5000,\"nearby_users_count\":0,\"community_alert_id\":8}', NULL, NULL, NULL, '2026-02-09 20:12:45'),
(163, 4, 'community_alert_broadcast', 'panic_alerts', 17, NULL, '{\"broadcast_radius\":5000,\"nearby_users_count\":0,\"community_alert_id\":9}', NULL, NULL, NULL, '2026-02-09 20:12:54'),
(164, 4, 'panic_alert_from_walk', 'walk_sessions', 13, NULL, '{\"panic_alert_id\":18,\"walk_session_token\":\"d8fa38a4d19e34392f728ce0a2abc8fc39e9a9cf0d473d40aabce2a82d4300a9\"}', NULL, NULL, NULL, '2026-02-09 20:13:01'),
(165, 4, 'sos_alert', 'walk_sessions', 13, NULL, '{\"session_token\":\"d8fa38a4d19e34392f728ce0a2abc8fc39e9a9cf0d473d40aabce2a82d4300a9\",\"status\":\"emergency\",\"contacts_notified\":0,\"panic_alert_id\":18}', NULL, NULL, NULL, '2026-02-09 20:13:01');

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `certificate_number` varchar(100) NOT NULL,
  `certificate_file_path` varchar(500) DEFAULT NULL,
  `verification_code` varchar(50) DEFAULT NULL,
  `issued_at` datetime DEFAULT current_timestamp(),
  `expires_at` date DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_enrollments`
--

CREATE TABLE `course_enrollments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `status` enum('enrolled','in_progress','completed','dropped') DEFAULT 'enrolled',
  `started_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `certificate_issued` tinyint(1) DEFAULT 0,
  `certificate_id` varchar(100) DEFAULT NULL,
  `rating` int(1) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `last_accessed_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_enrollments`
--

INSERT INTO `course_enrollments` (`id`, `user_id`, `course_id`, `progress_percentage`, `status`, `started_at`, `completed_at`, `certificate_issued`, `certificate_id`, `rating`, `feedback`, `last_accessed_at`) VALUES
(1, 2, 5, 0.00, 'enrolled', '2025-11-16 15:13:17', NULL, 0, NULL, NULL, NULL, '2025-11-16 15:13:17'),
(2, 1, 3, 0.00, 'enrolled', '2025-12-08 06:41:13', NULL, 0, NULL, NULL, NULL, '2025-12-08 06:41:13');

-- --------------------------------------------------------

--
-- Table structure for table `disputes`
--

CREATE TABLE `disputes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `reason` enum('false_accusation','wrong_person','misunderstanding','malicious_report','other') NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','under_review','approved','rejected','closed') DEFAULT 'pending',
  `evidence_files` text DEFAULT NULL,
  `supporting_documents` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `districts`
--

CREATE TABLE `districts` (
  `id` int(11) NOT NULL,
  `division_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `bbs_code` varchar(10) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `districts`
--

INSERT INTO `districts` (`id`, `division_id`, `name`, `bbs_code`, `created_at`, `updated_at`) VALUES
(1, 1, 'Dhaka', '30', '2025-11-16 13:30:00', '2025-11-16 13:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `divisions`
--

CREATE TABLE `divisions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `divisions`
--

INSERT INTO `divisions` (`id`, `name`, `code`, `created_at`, `updated_at`) VALUES
(1, 'Dhaka', 'DHK', '2025-11-16 13:30:00', '2025-11-16 13:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `emergency_contacts`
--

CREATE TABLE `emergency_contacts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `contact_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `priority` int(1) DEFAULT 1,
  `is_verified` tinyint(1) DEFAULT 0,
  `notification_methods` text DEFAULT 'sms,call',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `emergency_contacts`
--

INSERT INTO `emergency_contacts` (`id`, `user_id`, `contact_name`, `phone_number`, `relationship`, `priority`, `is_verified`, `notification_methods`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Anik', '01871745957', 'Brother', 1, 0, 'sms', 1, '2025-11-24 00:08:39', '2025-11-24 00:08:39'),
(2, 6, 'Anik', '01871745957', 'Brother', 1, 0, 'sms,call', 1, '2026-01-24 09:14:29', '2026-01-24 09:14:29');

-- --------------------------------------------------------

--
-- Table structure for table `emergency_services`
--

CREATE TABLE `emergency_services` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_bn` varchar(255) DEFAULT NULL COMMENT 'Bangla name',
  `type` enum('police_station','hospital','fire_station','womens_helpdesk','ngo') NOT NULL,
  `address` text DEFAULT NULL,
  `address_bn` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location` point NOT NULL,
  `operating_hours` varchar(100) DEFAULT '24/7',
  `has_womens_cell` tinyint(1) DEFAULT 0 COMMENT 'Has dedicated women support',
  `has_emergency_unit` tinyint(1) DEFAULT 1,
  `verified` tinyint(1) DEFAULT 0,
  `rating` decimal(2,1) DEFAULT 0.0,
  `total_ratings` int(11) DEFAULT 0,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `emergency_services`
--

INSERT INTO `emergency_services` (`id`, `name`, `name_bn`, `type`, `address`, `address_bn`, `phone`, `emergency_phone`, `email`, `website`, `latitude`, `longitude`, `location`, `operating_hours`, `has_womens_cell`, `has_emergency_unit`, `verified`, `rating`, `total_ratings`, `image_url`, `created_at`, `updated_at`) VALUES
(1, 'Dhanmondi Police Station', 'ধানমন্ডি থানা', 'police_station', 'Road 27, Dhanmondi, Dhaka', NULL, '02-9116453', '999', NULL, NULL, 23.74650000, 90.37620000, 0xe6100000010100000055302aa913985640c976be9f1abf3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(2, 'Gulshan Police Station', 'গুলশান থানা', 'police_station', 'Gulshan Avenue, Gulshan, Dhaka', NULL, '02-9858008', '999', NULL, NULL, 23.79250000, 90.40780000, 0xe61000000101000000c3d32b65199a56407b14ae47e1ca3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(3, 'Banani Police Station', 'বনানী থানা', 'police_station', 'Banani, Dhaka', NULL, '02-9870011', '999', NULL, NULL, 23.79370000, 90.40660000, 0xe610000001010000006ea301bc059a5640d0d556ec2fcb3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(4, 'Uttara West Police Station', 'উত্তরা পশ্চিম থানা', 'police_station', 'Sector 6, Uttara, Dhaka', NULL, '02-8931122', '999', NULL, NULL, 23.87590000, 90.37950000, 0xe610000001010000003f355eba4998564000917efb3ae03740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(5, 'Uttara East Police Station', 'উত্তরা পূর্ব থানা', 'police_station', 'Sector 10, Uttara, Dhaka', NULL, '02-8954411', '999', NULL, NULL, 23.86890000, 90.40120000, 0xe61000000101000000efc9c342ad995640917efb3a70de3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(6, 'Mirpur Police Station', 'মিরপুর থানা', 'police_station', 'Mirpur-10, Dhaka', NULL, '02-9002233', '999', NULL, NULL, 23.80690000, 90.36870000, 0xe610000001010000004182e2c798975640742497ff90ce3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(7, 'Mohammadpur Police Station', 'মোহাম্মদপুর থানা', 'police_station', 'Mohammadpur, Dhaka', NULL, '02-9116789', '999', NULL, NULL, 23.76620000, 90.35890000, 0xe6100000010100000034a2b437f8965640f931e6ae25c43740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(8, 'Motijheel Police Station', 'মতিঝিল থানা', 'police_station', 'Motijheel, Dhaka', NULL, '02-9559812', '999', NULL, NULL, 23.73320000, 90.41760000, 0xe61000000101000000d0b359f5b99a56405d6dc5feb2bb3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(9, 'Paltan Police Station', 'পল্টন থানা', 'police_station', 'Paltan, Dhaka', NULL, '02-9559123', '999', NULL, NULL, 23.73550000, 90.41120000, 0xe610000001010000005f07ce19519a56403f355eba49bc3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(10, 'Ramna Police Station', 'রমনা থানা', 'police_station', 'Ramna, Dhaka', NULL, '02-8317089', '999', NULL, NULL, 23.74210000, 90.40030000, 0xe61000000101000000af25e4839e995640925cfe43fabd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(11, 'Tejgaon Police Station', 'তেজগাঁও থানা', 'police_station', 'Tejgaon Industrial Area, Dhaka', NULL, '02-8871234', '999', NULL, NULL, 23.75870000, 90.39320000, 0xe61000000101000000613255302a995640a779c7293ac23740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(12, 'Badda Police Station', 'বাড্ডা থানা', 'police_station', 'Badda, Dhaka', NULL, '02-9887123', '999', NULL, NULL, 23.78090000, 90.42670000, 0xe61000000101000000014d840d4f9b56404772f90fe9c73740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(13, 'Khilgaon Police Station', 'খিলগাঁও থানা', 'police_station', 'Khilgaon, Dhaka', NULL, '02-7291234', '999', NULL, NULL, 23.75100000, 90.43320000, 0xe6100000010100000024287e8cb99b5640c74b378941c03740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(14, 'Dhaka Medical College Hospital', 'ঢাকা মেডিকেল কলেজ হাসপাতাল', 'hospital', 'Secretariat Road, Dhaka', NULL, '02-55165001', '199', NULL, NULL, 23.72570000, 90.39760000, 0xe61000000101000000ef384547729956400bb5a679c7b93740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(15, 'Square Hospital', 'স্কয়ার হাসপাতাল', 'hospital', '18/F Bir Uttam Qazi Nuruzzaman Sarak, Dhaka', NULL, '02-8159457', '10616', NULL, NULL, 23.75220000, 90.38780000, 0xe61000000101000000e25817b7d19856401b0de02d90c03740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(16, 'United Hospital', 'ইউনাইটেড হাসপাতাল', 'hospital', 'Plot 15, Road 71, Gulshan, Dhaka', NULL, '02-8836444', '10666', NULL, NULL, 23.79560000, 90.41450000, 0xe610000001010000004a0c022b879a564096b20c71accb3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(17, 'Labaid Hospital', 'ল্যাবএইড হাসপাতাল', 'hospital', 'House 1, Road 4, Dhanmondi, Dhaka', NULL, '02-9116551', '10606', NULL, NULL, 23.74120000, 90.37560000, 0xe610000001010000002b1895d40998564092cb7f48bfbd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(18, 'Apollo Hospital', 'অ্যাপোলো হাসপাতাল', 'hospital', 'Plot 81, Block E, Bashundhara, Dhaka', NULL, '02-8401661', '10678', NULL, NULL, 23.81950000, 90.43120000, 0xe610000001010000004182e2c7989b56406f1283c0cad13740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(19, 'Evercare Hospital', 'এভারকেয়ার হাসপাতাল', 'hospital', 'Plot 81, Block E, Bashundhara, Dhaka', NULL, '10678', '10678', NULL, NULL, 23.81670000, 90.42890000, 0xe610000001010000004850fc18739b5640a9a44e4013d13740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(20, 'Ibn Sina Hospital', 'ইবনে সিনা হাসপাতাল', 'hospital', 'House 48, Road 9/A, Dhanmondi, Dhaka', NULL, '02-9116910', '10656', NULL, NULL, 23.74890000, 90.37120000, 0xe610000001010000009d11a5bdc197564072f90fe9b7bf3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(21, 'Popular Medical College Hospital', 'পপুলার মেডিকেল কলেজ হাসপাতাল', 'hospital', 'House 16, Road 2, Dhanmondi, Dhaka', NULL, '02-8610610', '10636', NULL, NULL, 23.73980000, 90.38010000, 0xe610000001010000006a4df38e53985640af94658863bd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(22, 'Sir Salimullah Medical College Hospital', 'স্যার সলিমুল্লাহ মেডিকেল কলেজ হাসপাতাল', 'hospital', 'Mitford Road, Dhaka', NULL, '02-7319002', '199', NULL, NULL, 23.70890000, 90.40120000, 0xe61000000101000000efc9c342ad99564068226c787ab53740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(23, 'Bangabandhu Sheikh Mujib Medical University', 'বঙ্গবন্ধু শেখ মুজিব মেডিকেল বিশ্ববিদ্যালয়', 'hospital', 'Shahbag, Dhaka', NULL, '02-9661051', '199', NULL, NULL, 23.73920000, 90.39540000, 0xe61000000101000000a835cd3b4e995640053411363cbd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(24, 'One Stop Crisis Centre - DMC', 'ওয়ান স্টপ ক্রাইসিস সেন্টার', 'womens_helpdesk', 'Dhaka Medical College Hospital', NULL, '02-55165088', NULL, NULL, NULL, 23.72570000, 90.39760000, 0xe61000000101000000ef384547729956400bb5a679c7b93740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(25, 'BRAC Women & Girls Centre', 'ব্র্যাক নারী ও কিশোরী কেন্দ্র', 'ngo', 'BRAC Centre, Mohakhali, Dhaka', NULL, '02-9881265', NULL, NULL, NULL, 23.77870000, 90.40120000, 0xe61000000101000000efc9c342ad9956402c6519e258c73740, '9AM-5PM', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(26, 'Nari-O-Shishu Nirjaton Protirodh Cell', 'নারী ও শিশু নির্যাতন প্রতিরোধ সেল', 'womens_helpdesk', 'Police Headquarters, Dhaka', NULL, '02-8313633', NULL, NULL, NULL, 23.73850000, 90.41000000, 0xe610000001010000000ad7a3703d9a5640931804560ebd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(27, 'Acid Survivors Foundation', 'এসিড সারভাইভারস ফাউন্ডেশন', 'ngo', 'House 12, Road 22, Gulshan 1, Dhaka', NULL, '02-8859943', NULL, NULL, NULL, 23.78450000, 90.41560000, 0xe61000000101000000ed0dbe30999a564046b6f3fdd4c83740, '9AM-5PM', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(28, 'Manusher Jonno Foundation', 'মানুষের জন্য ফাউন্ডেশন', 'ngo', 'House 4, Road 50, Gulshan 2, Dhaka', NULL, '02-9886472', NULL, NULL, NULL, 23.79120000, 90.40890000, 0xe6100000010100000067d5e76a2b9a56405f984c158cca3740, '9AM-5PM', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(29, 'Bangladesh Mahila Parishad', 'বাংলাদেশ মহিলা পরিষদ', 'ngo', '28/5 Topkhana Road, Dhaka', NULL, '02-9558673', NULL, NULL, NULL, 23.73420000, 90.40890000, 0xe6100000010100000067d5e76a2b9a564024b9fc87f4bb3740, '9AM-5PM', 1, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(30, 'Dhaka Fire Station - Headquarters', 'ঢাকা ফায়ার স্টেশন - সদর দপ্তর', 'fire_station', '37 Naya Paltan, Dhaka', NULL, '02-9330088', '199', NULL, NULL, 23.73890000, 90.40980000, 0xe61000000101000000a779c7293a9a5640b003e78c28bd3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(31, 'Mirpur Fire Station', 'মিরপুর ফায়ার স্টেশন', 'fire_station', 'Mirpur-10, Dhaka', NULL, '02-8031199', '199', NULL, NULL, 23.80760000, 90.36560000, 0xe61000000101000000bada8afd65975640e63fa4dfbece3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(32, 'Uttara Fire Station', 'উত্তরা ফায়ার স্টেশন', 'fire_station', 'Sector 7, Uttara, Dhaka', NULL, '02-8953199', '199', NULL, NULL, 23.87010000, 90.39120000, 0xe610000001010000007e8cb96b09995640e63fa4dfbede3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36'),
(33, 'Gulshan Fire Station', 'গুলশান ফায়ার স্টেশন', 'fire_station', 'Gulshan, Dhaka', NULL, '02-9856199', '199', NULL, NULL, 23.78890000, 90.41230000, 0xe6100000010100000003098a1f639a56407dd0b359f5c93740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-09 14:49:36', '2026-02-09 14:49:36');

-- --------------------------------------------------------

--
-- Table structure for table `group_alerts`
--

CREATE TABLE `group_alerts` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `posted_by` int(11) NOT NULL,
  `alert_type` enum('safety_warning','missing_person','suspicious_activity','emergency','general') DEFAULT 'general',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `location_details` varchar(255) DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `views_count` int(11) DEFAULT 0,
  `acknowledgments` int(11) DEFAULT 0,
  `status` enum('active','resolved','false_alarm','expired') DEFAULT 'active',
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_alerts`
--

INSERT INTO `group_alerts` (`id`, `group_id`, `posted_by`, `alert_type`, `title`, `message`, `location_details`, `severity`, `is_verified`, `verified_by`, `views_count`, `acknowledgments`, `status`, `expires_at`, `created_at`) VALUES
(1, 1, 1, 'safety_warning', 'Suspicious Activity Near Dhanmondi Lake', 'Multiple reports of suspicious individuals near Dhanmondi Lake area. Please be cautious and report any unusual activity.', 'Near Dhanmondi Lake, Road 27', 'medium', 1, NULL, 0, 0, 'active', '2025-11-23 13:52:50', '2025-11-16 13:52:50'),
(2, 1, 1, 'missing_person', 'Missing Person Alert - Young Girl', 'A 12-year-old girl named Fatima has been missing since yesterday. Last seen near Dhanmondi Market. Please share and contact if you have any information.', 'Dhanmondi Market Area', 'high', 1, NULL, 0, 0, 'active', '2025-11-19 13:52:50', '2025-11-16 13:52:50'),
(3, 2, 1, 'safety_warning', 'Road Construction Alert', 'Major road construction on Gulshan Avenue. Expect traffic delays. Drive carefully.', 'Gulshan Avenue, Block 1', 'low', 1, NULL, 0, 0, 'active', '2025-11-21 13:52:50', '2025-11-16 13:52:50'),
(4, 2, 1, 'emergency', 'Emergency: Power Outage in Gulshan-2', 'Widespread power outage reported in Gulshan-2 area. Expected to be resolved within 2 hours. Stay safe.', 'Gulshan-2, Block 5-8', 'medium', 1, NULL, 0, 0, 'active', '2025-11-17 13:52:50', '2025-11-16 13:52:50'),
(5, 3, 1, 'suspicious_activity', 'Unusual Activity Near Mirpur Stadium', 'Reports of unusual gathering near Mirpur Stadium. Authorities have been notified. Please avoid the area if possible.', 'Mirpur Stadium Area', 'medium', 0, NULL, 0, 0, 'active', '2025-11-18 13:52:50', '2025-11-16 13:52:50'),
(6, 1, 1, 'safety_warning', 'Suspicious Activity Near Dhanmondi Lake', 'Multiple reports of suspicious individuals near Dhanmondi Lake area. Please be cautious and report any unusual activity.', 'Near Dhanmondi Lake, Road 27', 'medium', 1, NULL, 0, 0, 'active', '2025-11-23 14:14:09', '2025-11-16 14:14:09'),
(7, 1, 1, 'missing_person', 'Missing Person Alert - Young Girl', 'A 12-year-old girl named Fatima has been missing since yesterday. Last seen near Dhanmondi Market. Please share and contact if you have any information.', 'Dhanmondi Market Area', 'high', 1, NULL, 0, 0, 'active', '2025-11-19 14:14:09', '2025-11-16 14:14:09'),
(8, 2, 1, 'safety_warning', 'Road Construction Alert', 'Major road construction on Gulshan Avenue. Expect traffic delays. Drive carefully.', 'Gulshan Avenue, Block 1', 'low', 1, NULL, 0, 0, 'active', '2025-11-21 14:14:09', '2025-11-16 14:14:09'),
(9, 2, 1, 'emergency', 'Emergency: Power Outage in Gulshan-2', 'Widespread power outage reported in Gulshan-2 area. Expected to be resolved within 2 hours. Stay safe.', 'Gulshan-2, Block 5-8', 'medium', 1, NULL, 0, 0, 'active', '2025-11-17 14:14:09', '2025-11-16 14:14:09'),
(10, 3, 1, 'suspicious_activity', 'Unusual Activity Near Mirpur Stadium', 'Reports of unusual gathering near Mirpur Stadium. Authorities have been notified. Please avoid the area if possible.', 'Mirpur Stadium Area', 'medium', 0, NULL, 0, 0, 'active', '2025-11-18 14:14:09', '2025-11-16 14:14:09'),
(11, 21, 4, 'safety_warning', 'hhhhhhhhhhhh', 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh', 'Vashantek dawanpara', 'high', 0, NULL, 0, 0, 'active', '2026-01-24 14:35:00', '2026-01-24 14:35:21');

-- --------------------------------------------------------

--
-- Table structure for table `group_alert_acknowledgments`
--

CREATE TABLE `group_alert_acknowledgments` (
  `id` int(11) NOT NULL,
  `alert_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `acknowledged_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_media`
--

CREATE TABLE `group_media` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `alert_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` enum('image','video','document','other') NOT NULL,
  `file_size_bytes` bigint(20) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 1,
  `views_count` int(11) DEFAULT 0,
  `download_count` int(11) DEFAULT 0,
  `status` enum('active','deleted','flagged') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_media`
--

INSERT INTO `group_media` (`id`, `group_id`, `alert_id`, `uploaded_by`, `file_name`, `file_path`, `file_type`, `file_size_bytes`, `mime_type`, `thumbnail_path`, `description`, `is_public`, `views_count`, `download_count`, `status`, `created_at`) VALUES
(1, 21, 11, 4, 'Screenshot 2025-11-14 053006.png', 'uploads/group_media/group_21_6974844943b67_1769243721_Screenshot_2025-11-14_053006.png', 'image', 12188, 'image/png', 'uploads/group_media/group_21_6974844943b67_1769243721_Screenshot_2025-11-14_053006.png', NULL, 1, 0, 0, 'active', '2026-01-24 14:35:21');

-- --------------------------------------------------------

--
-- Table structure for table `group_members`
--

CREATE TABLE `group_members` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('member','moderator','admin','founder') DEFAULT 'member',
  `joined_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','inactive','banned') DEFAULT 'active',
  `contribution_score` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_members`
--

INSERT INTO `group_members` (`id`, `group_id`, `user_id`, `role`, `joined_at`, `status`, `contribution_score`) VALUES
(1, 1, 1, 'founder', '2025-11-16 13:52:50', 'active', 150),
(2, 2, 1, 'founder', '2025-11-16 13:52:50', 'active', 120),
(3, 3, 1, 'founder', '2025-11-16 13:52:50', 'active', 95),
(4, 4, 1, 'founder', '2025-11-16 13:52:50', 'active', 110),
(5, 5, 1, 'founder', '2025-11-16 13:52:50', 'active', 85),
(6, 1, 2, 'member', '2025-11-16 14:03:24', 'active', 0),
(7, 4, 2, 'member', '2025-11-16 14:04:05', 'active', 0),
(29, 21, 2, 'founder', '2025-11-16 15:31:31', 'active', 0),
(30, 21, 4, 'member', '2025-11-23 19:36:46', 'active', 8),
(31, 1, 4, 'member', '2026-01-24 14:36:37', 'active', 0);

-- --------------------------------------------------------

--
-- Table structure for table `helpline_numbers`
--

CREATE TABLE `helpline_numbers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_bn` varchar(100) DEFAULT NULL,
  `number` varchar(20) NOT NULL,
  `category` enum('emergency','womens_rights','domestic_violence','child_protection','legal_aid','medical','mental_health') NOT NULL,
  `description` text DEFAULT NULL,
  `description_bn` text DEFAULT NULL,
  `organization` varchar(255) DEFAULT NULL,
  `is_toll_free` tinyint(1) DEFAULT 1,
  `operating_hours` varchar(50) DEFAULT '24/7',
  `priority` int(11) DEFAULT 10 COMMENT 'Lower = higher priority',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `helpline_numbers`
--

INSERT INTO `helpline_numbers` (`id`, `name`, `name_bn`, `number`, `category`, `description`, `description_bn`, `organization`, `is_toll_free`, `operating_hours`, `priority`, `is_active`, `created_at`) VALUES
(1, 'National Emergency Service', 'জাতীয় জরুরি সেবা', '999', 'emergency', 'Police, Fire, Ambulance - All emergency services', 'পুলিশ, ফায়ার সার্ভিস, অ্যাম্বুলেন্স', 'Bangladesh Government', 1, '24/7', 1, 1, '2026-02-09 14:49:36'),
(2, 'Women & Children Helpline', 'মহিলা ও শিশু হেল্পলাইন', '10921', 'womens_rights', 'Ministry of Women and Children Affairs helpline for women safety', 'মহিলা ও শিশু বিষয়ক মন্ত্রণালয়ের হেল্পলাইন', 'Ministry of Women and Children Affairs', 1, '24/7', 2, 1, '2026-02-09 14:49:36'),
(3, 'Domestic Violence Helpline', 'পারিবারিক নির্যাতন হটলাইন', '109', 'domestic_violence', 'Report domestic violence and get immediate help', 'পারিবারিক নির্যাতনের রিপোর্ট করুন', 'Government of Bangladesh', 1, '24/7', 3, 1, '2026-02-09 14:49:36'),
(4, 'Child Helpline', 'শিশু হেল্পলাইন', '1098', 'child_protection', 'Report child abuse, trafficking, or missing children', 'শিশু নির্যাতন, পাচার বা নিখোঁজ শিশুর রিপোর্ট', 'Ministry of Social Welfare', 1, '24/7', 4, 1, '2026-02-09 14:49:36'),
(5, 'RAB Helpline', 'র‍্যাব হেল্পলাইন', '01779-529900', 'emergency', 'Rapid Action Battalion emergency contact', 'র‍্যাপিড অ্যাকশন ব্যাটালিয়ন জরুরি যোগাযোগ', 'RAB', 0, '24/7', 5, 1, '2026-02-09 14:49:36'),
(6, 'BNWLA Legal Aid', 'বিএনডব্লিউএলএ আইনি সহায়তা', '01730017055', 'legal_aid', 'Bangladesh National Women Lawyers Association - Free legal aid for women', 'নারীদের জন্য বিনামূল্যে আইনি সহায়তা', 'BNWLA', 0, '9AM-5PM', 6, 1, '2026-02-09 14:49:36'),
(7, 'Ain O Salish Kendra', 'আইন ও সালিশ কেন্দ্র', '01711876643', 'legal_aid', 'Human rights and legal aid organization', 'মানবাধিকার ও আইনি সহায়তা সংস্থা', 'ASK', 0, '9AM-5PM', 7, 1, '2026-02-09 14:49:36'),
(8, 'BLAST Legal Aid', 'ব্লাস্ট আইনি সহায়তা', '01711579898', 'legal_aid', 'Bangladesh Legal Aid and Services Trust', 'বাংলাদেশ লিগ্যাল এইড অ্যান্ড সার্ভিসেস ট্রাস্ট', 'BLAST', 0, '9AM-5PM', 8, 1, '2026-02-09 14:49:36'),
(9, 'Kaan Pete Roi', 'কান পেতে রই', '01779-554391', 'mental_health', 'Emotional support and mental health helpline', 'মানসিক সহায়তা হেল্পলাইন', 'Kaan Pete Roi', 0, '6PM-10PM', 9, 1, '2026-02-09 14:49:36'),
(10, 'Fire Service', 'ফায়ার সার্ভিস', '199', 'emergency', 'Fire emergency and rescue services', 'অগ্নিকাণ্ড ও উদ্ধার সেবা', 'Fire Service & Civil Defence', 1, '24/7', 10, 1, '2026-02-09 14:49:36');

-- --------------------------------------------------------

--
-- Table structure for table `incident_reports`
--

CREATE TABLE `incident_reports` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('harassment','assault','theft','vandalism','stalking','cyberbullying','discrimination','other') NOT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('pending','under_review','investigating','resolved','closed','disputed') DEFAULT 'pending',
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
  `assigned_to` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incident_reports`
--

INSERT INTO `incident_reports` (`id`, `user_id`, `title`, `description`, `category`, `severity`, `status`, `location_name`, `latitude`, `longitude`, `address`, `incident_date`, `reported_date`, `updated_date`, `resolved_at`, `is_anonymous`, `is_public`, `evidence_files`, `witness_count`, `response_time_minutes`, `assigned_to`) VALUES
(1, 1, 'Test Report #1', 'This is a test description for incident report number 1. The details are randomized.', 'stalking', 'high', 'resolved', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 00:25:24', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 1, NULL, NULL),
(2, 1, 'Test Report #2', 'This is a test description for incident report number 2. The details are randomized.', 'vandalism', 'medium', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-07 23:22:19', '2025-11-12 21:01:55', '2025-11-23 20:17:07', NULL, 1, 1, NULL, 5, NULL, NULL),
(3, 1, 'Test Report #3', 'This is a test description for incident report number 3. The details are randomized.', 'vandalism', 'medium', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 02:06:26', '2025-11-12 21:01:55', '2025-11-23 20:09:38', NULL, 1, 1, NULL, 1, NULL, NULL),
(4, 1, 'Test Report #4', 'This is a test description for incident report number 4. The details are randomized.', 'other', 'critical', 'resolved', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-08 22:16:12', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(5, 1, 'Test Report #5', 'This is a test description for incident report number 5. The details are randomized.', 'other', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-07 06:42:43', '2025-11-12 21:01:55', '2025-11-23 20:17:03', NULL, 1, 0, NULL, 4, NULL, NULL),
(6, 1, 'Test Report #6', 'This is a test description for incident report number 6. The details are randomized.', 'stalking', 'high', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 07:58:01', '2025-11-12 21:01:55', '2025-11-23 20:16:59', NULL, 0, 0, NULL, 3, NULL, NULL),
(7, 1, 'Test Report #7', 'This is a test description for incident report number 7. The details are randomized.', 'vandalism', 'medium', 'resolved', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 19:25:44', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 5, NULL, NULL),
(8, 1, 'Test Report #8', 'This is a test description for incident report number 8. The details are randomized.', 'assault', 'critical', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-06 07:12:04', '2025-11-12 21:01:55', '2025-11-23 20:17:16', NULL, 1, 1, NULL, 3, NULL, NULL),
(9, 1, 'Test Report #9', 'This is a test description for incident report number 9. The details are randomized.', 'stalking', 'high', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-10 13:26:35', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 1, NULL, NULL),
(10, 1, 'Test Report #10', 'This is a test description for incident report number 10. The details are randomized.', 'discrimination', 'medium', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-07 22:48:13', '2025-11-12 21:01:55', '2025-11-23 20:17:12', NULL, 0, 0, NULL, 0, NULL, NULL),
(11, 1, 'Test Report #11', 'This is a test description for incident report number 11. The details are randomized.', 'vandalism', 'critical', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 14:19:08', '2025-11-12 21:01:55', '2025-11-23 20:17:22', NULL, 1, 0, NULL, 5, NULL, NULL),
(12, 1, 'Test Report #12', 'This is a test description for incident report number 12. The details are randomized.', 'other', 'critical', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 01:06:35', '2025-11-12 21:01:55', '2025-11-23 20:17:38', NULL, 1, 1, NULL, 5, NULL, NULL),
(13, 1, 'Test Report #13', 'This is a test description for incident report number 13. The details are randomized.', 'vandalism', 'low', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 01:46:05', '2025-11-12 21:01:55', '2025-11-23 20:17:50', NULL, 1, 1, NULL, 1, NULL, NULL),
(14, 1, 'Test Report #14', 'This is a test description for incident report number 14. The details are randomized.', 'vandalism', 'medium', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-03 06:08:43', '2025-11-12 21:01:55', '2025-11-23 20:17:46', NULL, 0, 1, NULL, 2, NULL, NULL),
(15, 1, 'Test Report #15', 'This is a test description for incident report number 15. The details are randomized.', 'other', 'medium', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 01:46:22', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 0, NULL, NULL),
(16, 1, 'Test Report #16', 'This is a test description for incident report number 16. The details are randomized.', 'theft', 'low', 'resolved', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 06:46:07', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 3, NULL, NULL),
(17, 1, 'Test Report #17', 'This is a test description for incident report number 17. The details are randomized.', 'assault', 'medium', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 12:12:07', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 4, NULL, NULL),
(18, 1, 'Test Report #18', 'This is a test description for incident report number 18. The details are randomized.', 'other', 'high', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 11:24:46', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 1, NULL, NULL),
(19, 1, 'Test Report #19', 'This is a test description for incident report number 19. The details are randomized.', 'stalking', 'low', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 07:51:34', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(20, 1, 'Test Report #20', 'This is a test description for incident report number 20. The details are randomized.', 'theft', 'low', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 05:21:15', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 5, NULL, NULL),
(21, 1, 'Test Report #21', 'This is a test description for incident report number 21. The details are randomized.', 'cyberbullying', 'high', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 13:37:18', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 3, NULL, NULL),
(22, 1, 'Test Report #22', 'This is a test description for incident report number 22. The details are randomized.', 'other', 'critical', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 20:02:16', '2025-11-12 21:01:55', '2025-11-23 20:17:41', NULL, 0, 0, NULL, 5, NULL, NULL),
(23, 1, 'Test Report #23', 'This is a test description for incident report number 23. The details are randomized.', 'harassment', 'low', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 13:43:02', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 4, NULL, NULL),
(24, 1, 'Test Report #24', 'This is a test description for incident report number 24. The details are randomized.', 'cyberbullying', 'low', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-06 12:45:40', '2025-11-12 21:01:55', '2025-11-23 20:18:00', NULL, 0, 0, NULL, 5, NULL, NULL),
(25, 1, 'Test Report #25', 'This is a test description for incident report number 25. The details are randomized.', 'assault', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 11:43:25', '2025-11-12 21:01:55', '2025-11-23 20:17:34', NULL, 0, 0, NULL, 0, NULL, NULL),
(26, 1, 'Test Report #26', 'This is a test description for incident report number 26. The details are randomized.', 'discrimination', 'critical', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 03:06:51', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 2, NULL, NULL),
(27, 1, 'Test Report #27', 'This is a test description for incident report number 27. The details are randomized.', 'vandalism', 'medium', 'resolved', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 01:29:25', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 5, NULL, NULL),
(28, 1, 'Test Report #28', 'This is a test description for incident report number 28. The details are randomized.', 'assault', 'low', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-10 19:30:24', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 0, NULL, NULL),
(29, 1, 'Test Report #29', 'This is a test description for incident report number 29. The details are randomized.', 'harassment', 'critical', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-10 01:02:47', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(30, 1, 'Test Report #30', 'This is a test description for incident report number 30. The details are randomized.', 'vandalism', 'low', 'resolved', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 12:32:35', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 4, NULL, NULL),
(31, 1, 'Test Report #31', 'This is a test description for incident report number 31. The details are randomized.', 'other', 'medium', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 01:52:11', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 2, NULL, NULL),
(32, 1, 'Test Report #32', 'This is a test description for incident report number 32. The details are randomized.', 'discrimination', 'critical', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 10:35:15', '2025-11-12 21:01:55', '2025-11-23 20:17:57', NULL, 1, 1, NULL, 5, NULL, NULL),
(33, 1, 'Test Report #33', 'This is a test description for incident report number 33. The details are randomized.', 'discrimination', 'medium', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-03 16:11:47', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(34, 1, 'Test Report #34', 'This is a test description for incident report number 34. The details are randomized.', 'harassment', 'high', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-08 22:28:03', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 4, NULL, NULL),
(35, 1, 'Test Report #35', 'This is a test description for incident report number 35. The details are randomized.', 'cyberbullying', 'low', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 23:37:23', '2025-11-12 21:01:55', '2025-11-23 20:17:54', NULL, 1, 1, NULL, 3, NULL, NULL),
(36, 1, 'Test Report #36', 'This is a test description for incident report number 36. The details are randomized.', 'harassment', 'critical', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 02:00:48', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 5, NULL, NULL),
(37, 1, 'Test Report #37', 'This is a test description for incident report number 37. The details are randomized.', 'theft', 'critical', 'resolved', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 04:50:32', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 1, NULL, NULL),
(38, 1, 'Test Report #38', 'This is a test description for incident report number 38. The details are randomized.', 'other', 'high', 'resolved', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-06 12:38:44', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(39, 1, 'Test Report #39', 'This is a test description for incident report number 39. The details are randomized.', 'harassment', 'critical', 'resolved', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 16:40:55', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 5, NULL, NULL),
(40, 1, 'Test Report #40', 'This is a test description for incident report number 40. The details are randomized.', 'cyberbullying', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 10:08:24', '2025-11-12 21:01:55', '2025-11-23 20:18:04', NULL, 0, 0, NULL, 3, NULL, NULL),
(41, 1, 'Test Report #41', 'This is a test description for incident report number 41. The details are randomized.', 'theft', 'medium', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 07:38:30', '2025-11-12 21:01:55', '2025-11-23 20:18:10', NULL, 0, 0, NULL, 1, NULL, NULL),
(42, 1, 'Test Report #42', 'This is a test description for incident report number 42. The details are randomized.', 'discrimination', 'medium', 'resolved', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-10 23:53:32', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(43, 1, 'Test Report #43', 'This is a test description for incident report number 43. The details are randomized.', 'cyberbullying', 'critical', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 00:50:23', '2025-11-12 21:01:55', '2025-11-23 20:18:07', NULL, 0, 0, NULL, 1, NULL, NULL),
(44, 1, 'Test Report #44', 'This is a test description for incident report number 44. The details are randomized.', 'other', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 21:08:28', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 5, NULL, NULL),
(45, 1, 'Test Report #45', 'This is a test description for incident report number 45. The details are randomized.', 'vandalism', 'high', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 21:45:50', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 1, NULL, NULL),
(46, 1, 'Test Report #46', 'This is a test description for incident report number 46. The details are randomized.', 'vandalism', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 08:09:51', '2025-11-12 21:01:55', '2025-11-23 20:18:17', NULL, 0, 1, NULL, 1, NULL, NULL),
(47, 1, 'Test Report #47', 'This is a test description for incident report number 47. The details are randomized.', 'assault', 'low', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 12:25:08', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 1, NULL, NULL),
(48, 1, 'Test Report #48', 'This is a test description for incident report number 48. The details are randomized.', 'vandalism', 'medium', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-03 11:15:01', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 4, NULL, NULL),
(49, 1, 'Test Report #49', 'This is a test description for incident report number 49. The details are randomized.', 'assault', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 03:14:55', '2025-11-12 21:01:55', '2025-11-23 20:16:17', NULL, 0, 1, NULL, 0, NULL, NULL),
(50, 1, 'Test Report #50', 'This is a test description for incident report number 50. The details are randomized.', 'assault', 'high', 'resolved', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 03:21:28', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 1, NULL, NULL),
(51, 1, 'Test Report #51', 'This is a test description for incident report number 51. The details are randomized.', 'vandalism', 'critical', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 15:22:04', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 1, NULL, NULL),
(52, 1, 'Test Report #52', 'This is a test description for incident report number 52. The details are randomized.', 'assault', 'medium', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 03:19:48', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 1, NULL, NULL),
(53, 1, 'Test Report #53', 'This is a test description for incident report number 53. The details are randomized.', 'other', 'low', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 18:58:51', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 2, NULL, NULL),
(54, 1, 'Test Report #54', 'This is a test description for incident report number 54. The details are randomized.', 'theft', 'critical', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 03:09:20', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 3, NULL, NULL),
(55, 1, 'Test Report #55', 'This is a test description for incident report number 55. The details are randomized.', 'cyberbullying', 'high', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 20:02:41', '2025-11-12 21:01:55', '2025-11-23 20:18:13', NULL, 1, 1, NULL, 4, NULL, NULL),
(56, 1, 'Test Report #56', 'This is a test description for incident report number 56. The details are randomized.', 'cyberbullying', 'low', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-06 22:04:07', '2025-11-12 21:01:55', '2025-11-23 20:16:42', NULL, 0, 0, NULL, 4, NULL, NULL),
(57, 1, 'Test Report #57', 'This is a test description for incident report number 57. The details are randomized.', 'vandalism', 'critical', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 05:35:20', '2025-11-12 21:01:55', '2025-11-23 20:16:54', NULL, 1, 0, NULL, 2, NULL, NULL),
(58, 1, 'Test Report #58', 'This is a test description for incident report number 58. The details are randomized.', 'cyberbullying', 'low', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 11:16:43', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(59, 1, 'Test Report #59', 'This is a test description for incident report number 59. The details are randomized.', 'discrimination', 'low', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-08 21:44:05', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 2, NULL, NULL),
(60, 1, 'Test Report #60', 'This is a test description for incident report number 60. The details are randomized.', 'assault', 'medium', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-10 07:21:43', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 1, NULL, NULL),
(61, 1, 'Test Report #61', 'This is a test description for incident report number 61. The details are randomized.', 'other', 'high', 'pending', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-03 12:37:48', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 3, NULL, NULL),
(62, 1, 'Test Report #62', 'This is a test description for incident report number 62. The details are randomized.', 'vandalism', 'medium', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 17:33:31', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 0, NULL, NULL),
(63, 1, 'Test Report #63', 'This is a test description for incident report number 63. The details are randomized.', 'stalking', 'critical', 'resolved', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 20:34:12', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 0, NULL, NULL),
(64, 1, 'Test Report #64', 'This is a test description for incident report number 64. The details are randomized.', 'stalking', 'low', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-03 02:29:45', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 4, NULL, NULL),
(65, 1, 'Test Report #65', 'This is a test description for incident report number 65. The details are randomized.', 'vandalism', 'low', 'resolved', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 17:27:50', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 1, NULL, NULL),
(66, 1, 'Test Report #66', 'This is a test description for incident report number 66. The details are randomized.', 'assault', 'medium', 'pending', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 11:11:43', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(67, 1, 'Test Report #67', 'This is a test description for incident report number 67. The details are randomized.', 'assault', 'low', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 22:22:51', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 1, NULL, NULL),
(68, 1, 'Test Report #68', 'This is a test description for incident report number 68. The details are randomized.', 'cyberbullying', 'critical', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 01:52:36', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 0, NULL, NULL),
(69, 1, 'Test Report #69', 'This is a test description for incident report number 69. The details are randomized.', 'cyberbullying', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-03 19:36:31', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 4, NULL, NULL),
(70, 1, 'Test Report #70', 'This is a test description for incident report number 70. The details are randomized.', 'vandalism', 'medium', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-08 20:57:23', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 2, NULL, NULL),
(71, 1, 'Test Report #71', 'This is a test description for incident report number 71. The details are randomized.', 'other', 'medium', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 16:57:46', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 3, NULL, NULL),
(72, 1, 'Test Report #72', 'This is a test description for incident report number 72. The details are randomized.', 'assault', 'low', 'resolved', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 19:34:56', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 0, NULL, NULL),
(73, 1, 'Test Report #73', 'This is a test description for incident report number 73. The details are randomized.', 'stalking', 'high', 'pending', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-07 02:36:54', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 5, NULL, NULL),
(74, 1, 'Test Report #74', 'This is a test description for incident report number 74. The details are randomized.', 'discrimination', 'medium', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 17:22:42', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 5, NULL, NULL),
(75, 1, 'Test Report #75', 'This is a test description for incident report number 75. The details are randomized.', 'cyberbullying', 'high', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 06:00:48', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 3, NULL, NULL),
(76, 1, 'Test Report #76', 'This is a test description for incident report number 76. The details are randomized.', 'stalking', 'low', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-07 18:57:37', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 0, NULL, NULL),
(77, 1, 'Test Report #77', 'This is a test description for incident report number 77. The details are randomized.', 'cyberbullying', 'medium', 'pending', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-08 07:04:24', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 1, NULL, NULL),
(78, 1, 'Test Report #78', 'This is a test description for incident report number 78. The details are randomized.', 'discrimination', 'medium', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-04 08:22:44', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 5, NULL, NULL),
(79, 1, 'Test Report #79', 'This is a test description for incident report number 79. The details are randomized.', 'discrimination', 'medium', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-07 09:47:14', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 3, NULL, NULL),
(80, 1, 'Test Report #80', 'This is a test description for incident report number 80. The details are randomized.', 'discrimination', 'high', 'pending', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 13:52:58', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 5, NULL, NULL),
(81, 1, 'Test Report #81', 'This is a test description for incident report number 81. The details are randomized.', 'cyberbullying', 'medium', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-07 16:38:25', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 2, NULL, NULL),
(82, 1, 'Test Report #82', 'This is a test description for incident report number 82. The details are randomized.', 'assault', 'critical', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 09:17:32', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 5, NULL, NULL),
(83, 1, 'Test Report #83', 'This is a test description for incident report number 83. The details are randomized.', 'stalking', 'low', 'under_review', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 09:56:23', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 2, NULL, NULL),
(84, 1, 'Test Report #84', 'This is a test description for incident report number 84. The details are randomized.', 'harassment', 'low', 'resolved', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 11:25:39', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 0, NULL, NULL),
(85, 1, 'Test Report #85', 'This is a test description for incident report number 85. The details are randomized.', 'other', 'high', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 17:31:41', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 2, NULL, NULL),
(86, 1, 'Test Report #86', 'This is a test description for incident report number 86. The details are randomized.', 'harassment', 'critical', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 18:28:30', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 3, NULL, NULL),
(87, 1, 'Test Report #87', 'This is a test description for incident report number 87. The details are randomized.', 'stalking', 'medium', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 05:41:16', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 4, NULL, NULL),
(88, 1, 'Test Report #88', 'This is a test description for incident report number 88. The details are randomized.', 'vandalism', 'low', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-08 23:04:57', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 3, NULL, NULL),
(89, 1, 'Test Report #89', 'This is a test description for incident report number 89. The details are randomized.', 'harassment', 'medium', 'pending', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 21:07:58', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 2, NULL, NULL),
(90, 1, 'Test Report #90', 'This is a test description for incident report number 90. The details are randomized.', 'discrimination', 'low', 'resolved', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 18:14:14', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 3, NULL, NULL),
(91, 1, 'Test Report #91', 'This is a test description for incident report number 91. The details are randomized.', 'harassment', 'high', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-05 20:12:20', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 1, NULL, NULL),
(92, 1, 'Test Report #92', 'This is a test description for incident report number 92. The details are randomized.', 'assault', 'critical', 'resolved', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-08 09:41:51', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 4, NULL, NULL),
(93, 1, 'Test Report #93', 'This is a test description for incident report number 93. The details are randomized.', 'discrimination', 'high', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 08:44:13', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 5, NULL, NULL),
(94, 1, 'Test Report #94', 'This is a test description for incident report number 94. The details are randomized.', 'harassment', 'critical', 'under_review', 'Mirpur', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-01 07:39:49', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 3, NULL, NULL),
(95, 1, 'Test Report #95', 'This is a test description for incident report number 95. The details are randomized.', 'other', 'critical', 'pending', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 07:01:52', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 3, NULL, NULL),
(96, 1, 'Test Report #96', 'This is a test description for incident report number 96. The details are randomized.', 'stalking', 'low', 'pending', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-09 07:09:00', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 5, NULL, NULL),
(97, 1, 'Test Report #97', 'This is a test description for incident report number 97. The details are randomized.', 'assault', 'critical', 'under_review', 'Uttara', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-11 08:12:59', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 1, NULL, 0, NULL, NULL),
(98, 1, 'Test Report #98', 'This is a test description for incident report number 98. The details are randomized.', 'stalking', 'critical', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-12 02:51:05', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 0, NULL, 1, NULL, NULL),
(99, 1, 'Test Report #99', 'This is a test description for incident report number 99. The details are randomized.', 'discrimination', 'critical', 'under_review', 'Gulshan', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 01:58:13', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 1, 1, NULL, 5, NULL, NULL),
(100, 1, 'Test Report #100', 'This is a test description for incident report number 100. The details are randomized.', 'cyberbullying', 'critical', 'pending', 'Dhanmondi', NULL, NULL, 'Dhaka, Bangladesh', '2025-11-02 15:56:24', '2025-11-12 21:01:55', '2025-11-12 21:01:55', NULL, 0, 0, NULL, 3, NULL, NULL),
(101, 1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'harassment', 'high', 'under_review', 'Dhaka', 0.00000000, 0.00000000, '', '0000-00-00 00:00:00', '2025-11-13 15:43:04', '2025-11-23 20:16:23', NULL, 0, 0, NULL, 4, NULL, NULL),
(102, 1, 'tui kuttarbaccha chup', 'kanki magi vhodar moddhe eto kotha rakhos ken chup chap thakbi', 'theft', 'medium', 'under_review', 'Dhaka', 0.00000000, 0.00000000, '', '2025-11-11 15:44:00', '2025-11-13 15:45:02', '2025-11-23 20:09:55', NULL, 0, 0, NULL, 4, NULL, NULL),
(103, 2, 'ahuhuhuhuhuhu', 'assssssssssssssadfdsfasssssssssssssssssssssssssssssssssssssssssssssssssssssdfdsgfdgfd fdfg', 'assault', 'medium', 'under_review', 'Dhaka', 0.00000000, 0.00000000, 'BIssas bari 203', '2025-11-06 13:23:00', '2025-11-16 13:24:40', '2025-11-23 20:04:29', NULL, 0, 1, '[{\"path\":\"uploads/evidence/69197c382aaf1_1763277880_pc1.png\",\"name\":\"pc1.png\",\"type\":\"image/png\",\"size\":489956,\"extension\":\"png\"},{\"path\":\"uploads/evidence/69197c382b425_1763277880_pc2.png\",\"name\":\"pc2.png\",\"type\":\"image/png\",\"size\":489919,\"extension\":\"png\"},{\"path\":\"uploads/evidence/69197c382d0a8_1763277880_pc3.png\",\"name\":\"pc3.png\",\"type\":\"image/png\",\"size\":377289,\"extension\":\"png\"},{\"path\":\"uploads/evidence/69197c382fdf3_1763277880_pc4.png\",\"name\":\"pc4.png\",\"type\":\"image/png\",\"size\":370331,\"extension\":\"png\"}]', 3, NULL, NULL),
(104, 1, 'amake churi marse', 'amake buke churi marar try kora hoyeche  ami kisu korar agei amar bag niye chole gese', 'vandalism', 'critical', 'under_review', 'Dhaka', 0.00000000, 0.00000000, 'vashantek, 203 bissas bari', '2025-11-19 18:21:00', '2025-11-20 18:23:31', '2025-11-23 18:39:55', NULL, 0, 1, '[{\"path\":\"uploads/evidence/691f08439749d_1763641411_Screenshot_2025-11-15_215740.png\",\"name\":\"Screenshot 2025-11-15 215740.png\",\"type\":\"image/png\",\"size\":157676,\"extension\":\"png\"}]', 7, NULL, NULL),
(105, 1, 'Test Report - Dhanpara Area #1', 'This is a test incident report for Dhanpara area. Testing zone status functionality.', 'harassment', 'medium', 'under_review', 'Dhanpara', 23.75000000, 90.37000000, 'Dhanpara, Dhaka, Bangladesh', '2025-11-17 19:59:26', '2025-11-17 19:59:26', '2025-11-23 20:09:42', NULL, 0, 1, NULL, 0, NULL, NULL),
(106, 1, 'Test Report - Dhanpara Area #2', 'Second test incident report for Dhanpara area. This should trigger yellow zone status.', 'theft', 'high', 'under_review', 'Dhanpara', 23.75050000, 90.37050000, 'Dhanpara, Dhaka, Bangladesh', '2025-11-19 19:59:26', '2025-11-19 19:59:26', '2025-11-23 20:09:47', NULL, 0, 1, NULL, 0, NULL, NULL),
(107, 1, 'Test Report - Dhanpara Area #3', 'Third test incident report for Dhanpara area. Zone should now be marked as YELLOW (moderate risk).', 'vandalism', 'medium', 'under_review', 'Dhanpara', 23.75100000, 90.37100000, 'Dhanpara, Dhaka, Bangladesh', '2025-11-21 19:59:26', '2025-11-21 19:59:26', '2025-11-23 20:09:22', NULL, 0, 1, NULL, 0, NULL, NULL),
(108, 1, 'Test Report - Mirpur Area #1', 'First test incident report for Mirpur area. Testing red zone status.', 'assault', 'high', 'pending', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur, Dhaka, Bangladesh', '2025-11-12 19:59:26', '2025-11-12 19:59:26', '2025-11-22 19:59:26', NULL, 0, 1, NULL, 0, NULL, NULL),
(109, 1, 'Test Report - Mirpur Area #2', 'Second test incident report for Mirpur area.', 'stalking', 'critical', 'under_review', 'Mirpur', 23.81700000, 90.36700000, 'Mirpur, Dhaka, Bangladesh', '2025-11-14 19:59:26', '2025-11-14 19:59:26', '2025-11-23 19:45:48', NULL, 0, 1, NULL, 0, NULL, NULL),
(110, 1, 'Test Report - Mirpur Area #3', 'Third test incident report for Mirpur area. Zone should be YELLOW now.', 'harassment', 'medium', 'under_review', 'Mirpur', 23.81750000, 90.36750000, 'Mirpur, Dhaka, Bangladesh', '2025-11-16 19:59:26', '2025-11-16 19:59:26', '2025-11-23 19:45:39', NULL, 0, 1, NULL, 0, NULL, NULL),
(111, 1, 'Test Report - Mirpur Area #4', 'Fourth test incident report for Mirpur area. Still YELLOW.', 'theft', 'high', 'under_review', 'Mirpur', 23.81800000, 90.36800000, 'Mirpur, Dhaka, Bangladesh', '2025-11-18 19:59:26', '2025-11-18 19:59:26', '2025-11-23 20:09:51', NULL, 0, 1, NULL, 0, NULL, NULL),
(112, 1, 'Test Report - Mirpur Area #5', 'Fifth test incident report for Mirpur area. Zone should now be marked as RED (unsafe - high risk).', 'assault', 'critical', 'under_review', 'Mirpur', 23.81850000, 90.36850000, 'Mirpur, Dhaka, Bangladesh', '2025-11-20 19:59:26', '2025-11-20 19:59:26', '2025-11-23 18:39:47', NULL, 0, 1, NULL, 0, NULL, NULL),
(113, 1, 'Test Report - Gulshan Area #1', 'First test incident report for Gulshan area. Should stay GREEN (safe).', 'vandalism', 'low', 'under_review', 'Gulshan', 23.79470000, 90.41440000, 'Gulshan, Dhaka, Bangladesh', '2025-11-15 19:59:26', '2025-11-15 19:59:26', '2025-11-23 20:09:59', NULL, 0, 1, NULL, 0, NULL, NULL),
(114, 1, 'Test Report - Gulshan Area #2', 'Second test incident report for Gulshan area. Zone should remain GREEN (safe - 0-2 reports).', 'cyberbullying', 'low', 'under_review', 'Gulshan', 23.79500000, 90.41470000, 'Gulshan, Dhaka, Bangladesh', '2025-11-17 19:59:26', '2025-11-17 19:59:26', '2025-11-23 19:45:37', NULL, 0, 1, NULL, 0, NULL, NULL),
(115, 1, 'cchududhdhdhdhddhd', 'jiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiisssssssssssssssssssss', 'discrimination', 'low', 'pending', '67, Changgyeonggung-ro, Euljiro-dong', 37.56820000, 126.99770000, 'Road No. 2, Block H, Bashundhara Residential Area, Dhaka, Dhaka Metropolitan, Dhaka District, Dhaka Division, 1229, Bangladesh', '2026-01-14 17:43:00', '2026-01-17 17:45:09', '2026-01-17 17:45:09', NULL, 0, 1, NULL, 6, NULL, NULL),
(116, 5, 'chor amake marse', 'chor amar theke taka newyar shomoy ami taka dei nai dekhe amake ekta thappor marse', 'theft', 'critical', 'pending', 'UIU Road, Satarkul', 23.79835915, 90.45009065, 'Block H, Bashundhara Residential Area, Dhaka, Dhaka Metropolitan, Dhaka District, Dhaka Division, 1229, Bangladesh', '2026-01-23 23:11:00', '2026-01-24 12:14:54', '2026-01-24 12:14:54', NULL, 0, 1, '[{\"path\":\"uploads/evidence/6974635e99ea9_1769235294_Screenshot_2025-12-30_113043.png\",\"name\":\"Screenshot 2025-12-30 113043.png\",\"type\":\"image/png\",\"size\":798426,\"extension\":\"png\"}]', 0, NULL, NULL);

--
-- Triggers `incident_reports`
--
DELIMITER $$
CREATE TRIGGER `after_incident_insert` AFTER INSERT ON `incident_reports` FOR EACH ROW BEGIN
    IF NEW.location_name IS NOT NULL AND NEW.latitude IS NOT NULL AND NEW.longitude IS NOT NULL THEN
        CALL update_incident_zone(
            NEW.location_name,
            COALESCE(NEW.address, NEW.location_name),
            NEW.latitude,
            NEW.longitude,
            COALESCE(NEW.incident_date, NOW())
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_incident_reports_after_delete_audit` AFTER DELETE ON `incident_reports` FOR EACH ROW BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `created_at`)
  VALUES (OLD.user_id, 'DELETE', 'incident_reports', OLD.id,
          JSON_OBJECT('status', OLD.status, 'severity', OLD.severity, 'assigned_to', OLD.assigned_to),
          NULL,
          NOW());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_incident_reports_after_insert_audit` AFTER INSERT ON `incident_reports` FOR EACH ROW BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `created_at`)
  VALUES (NEW.user_id, 'INSERT', 'incident_reports', NEW.id, NULL,
          JSON_OBJECT('status', NEW.status, 'severity', NEW.severity, 'assigned_to', NEW.assigned_to),
          NOW());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_incident_reports_after_update_audit` AFTER UPDATE ON `incident_reports` FOR EACH ROW BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `created_at`)
  VALUES (NEW.user_id, 'UPDATE', 'incident_reports', NEW.id,
          JSON_OBJECT('status', OLD.status, 'severity', OLD.severity, 'assigned_to', OLD.assigned_to),
          JSON_OBJECT('status', NEW.status, 'severity', NEW.severity, 'assigned_to', NEW.assigned_to),
          NOW());
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `incident_zones`
--

CREATE TABLE `incident_zones` (
  `id` int(11) NOT NULL,
  `zone_name` varchar(255) NOT NULL,
  `area_name` varchar(255) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location` point NOT NULL,
  `report_count` int(11) DEFAULT 0,
  `zone_status` enum('safe','moderate','unsafe') DEFAULT 'safe',
  `last_incident_date` datetime DEFAULT NULL,
  `first_incident_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `incident_zones`
--

INSERT INTO `incident_zones` (`id`, `zone_name`, `area_name`, `latitude`, `longitude`, `location`, `report_count`, `zone_status`, `last_incident_date`, `first_incident_date`, `created_at`, `updated_at`) VALUES
(1, 'Dhanpara', 'Dhanpara, Dhaka, Bangladesh', 23.75000000, 90.37000000, 0xe6100000010100000039b4c876be975640c74b378941c03740, 3, 'moderate', '2025-11-21 19:59:26', '2025-11-17 19:59:26', '2025-11-22 13:59:26', '2025-11-22 13:59:26'),
(4, 'Mirpur', 'Mirpur, Dhaka, Bangladesh', 23.81670000, 90.36670000, 0xe61000000101000000dd24068195975640a8c64b3789d13740, 5, 'unsafe', '2025-11-20 19:59:26', '2025-11-12 19:59:26', '2025-11-22 13:59:26', '2025-11-22 13:59:26'),
(9, 'Gulshan', 'Gulshan, Dhaka, Bangladesh', 23.79470000, 90.41440000, 0xe61000000101000000ad69de718a9a5640ec51b81e85cb3740, 2, 'safe', '2025-11-17 19:59:26', '2025-11-15 19:59:26', '2025-11-22 13:59:26', '2025-11-22 13:59:26'),
(11, '67, Changgyeonggung-ro, Euljiro-dong', 'Road No. 2, Block H, Bashundhara Residential Area, Dhaka, Dhaka Metropolitan, Dhaka District, Dhaka Division, 1229, Bangladesh', 37.56820000, 126.99770000, 0xe6100000010100000007ce1951dabf5f4029cb10c7bac84240, 1, 'safe', '2026-01-14 17:43:00', '2026-01-14 17:43:00', '2026-01-17 11:45:09', '2026-01-17 11:45:10'),
(13, 'UIU Road, Satarkul', 'Block H, Bashundhara Residential Area, Dhaka, Dhaka Metropolitan, Dhaka District, Dhaka Division, 1229, Bangladesh', 23.79835915, 90.45009065, 0xe61000000101000000117f0349ce9c56405db6e74361cc3740, 1, 'safe', '2026-01-23 23:11:00', '2026-01-23 23:11:00', '2026-01-24 06:14:54', '2026-01-24 06:14:55');

-- --------------------------------------------------------

--
-- Table structure for table `leaf_nodes`
--

CREATE TABLE `leaf_nodes` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location` point NOT NULL,
  `safety_score` decimal(3,1) NOT NULL DEFAULT 5.0,
  `status` enum('safe','moderate','unsafe') NOT NULL DEFAULT 'moderate',
  `description` text DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `hours` varchar(100) DEFAULT NULL,
  `amenities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`amenities`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leaf_nodes`
--

INSERT INTO `leaf_nodes` (`id`, `name`, `category`, `latitude`, `longitude`, `location`, `safety_score`, `status`, `description`, `address`, `contact`, `hours`, `amenities`, `created_at`, `updated_at`) VALUES
(1, 'Gulshan Lake Park', 'park', 23.79470000, 90.41440000, 0xe6100000010100000098dd9387859a564096218e7571cb3740, 8.5, 'safe', 'Well-maintained park with good lighting and security', 'Gulshan-2, Dhaka', '+880-2-9881234', '6:00 AM - 10:00 PM', '[\"lighting\",\"security\",\"restrooms\",\"parking\"]', '2025-11-22 13:28:41', '2025-11-22 13:28:41'),
(2, 'Dhanmondi Lake', 'park', 23.74650000, 90.37000000, 0xe6100000010100000048e17a14ae975640c976be9f1abf3740, 7.8, 'safe', 'Popular recreational area with walking paths', 'Dhanmondi, Dhaka', '+880-2-9661234', '5:00 AM - 11:00 PM', '[\"lighting\",\"restrooms\",\"walking_paths\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(3, 'Ramna Park', 'park', 23.73060000, 90.40140000, 0xe610000001010000005227a089b09956402575029a08bb3740, 7.2, 'moderate', 'Historic park in central Dhaka', 'Ramna, Dhaka', '+880-2-9551234', '6:00 AM - 9:00 PM', '[\"lighting\",\"security\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(4, 'Hatirjheel', 'recreational', 23.77560000, 90.40000000, 0xe610000001010000009a9999999999564011c7bab88dc63740, 8.0, 'safe', 'Modern recreational area with lake and walkways', 'Tejgaon, Dhaka', '+880-2-9885678', '24/7', '[\"lighting\",\"security\",\"restrooms\",\"parking\",\"walking_paths\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(5, 'Bashundhara City Shopping Mall', 'commercial', 23.75000000, 90.37500000, 0xe6100000010100000000000000009856400000000000c03740, 9.0, 'safe', 'Large shopping complex with security', 'Panthapath, Dhaka', '+880-2-9123456', '10:00 AM - 10:00 PM', '[\"security\",\"parking\",\"restrooms\",\"lighting\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(6, 'Dhaka University Area', 'educational', 23.73330000, 90.39330000, 0xe610000001010000001361c3d32b99564024287e8cb9bb3740, 6.5, 'moderate', 'University campus area with mixed safety', 'Ramna, Dhaka', '+880-2-9661900', '24/7', '[\"lighting\",\"security\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(7, 'Gulshan-1 Residential Area', 'residential', 23.79000000, 90.41000000, 0xe610000001010000000ad7a3703d9a56400ad7a3703dca3740, 9.2, 'safe', 'High-security residential neighborhood', 'Gulshan-1, Dhaka', '+880-2-9880000', '24/7', '[\"security\",\"lighting\",\"gated_community\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(8, 'Banani Lake Park', 'park', 23.79060000, 90.40560000, 0xe610000001010000007dd0b359f5995640b537f8c264ca3740, 8.3, 'safe', 'Scenic lake park in Banani', 'Banani, Dhaka', '+880-2-9882345', '6:00 AM - 10:00 PM', '[\"lighting\",\"security\",\"restrooms\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(9, 'Old Dhaka - Lalbagh Fort', 'historical', 23.71830000, 90.38890000, 0xe61000000101000000865ad3bce398564080b74082e2b73740, 6.0, 'moderate', 'Historic fort area, moderate safety', 'Lalbagh, Old Dhaka', '+880-2-9556789', '9:00 AM - 5:00 PM', '[\"security\",\"lighting\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(10, 'Uttara Sector 7', 'residential', 23.87000000, 90.38000000, 0xe61000000101000000b81e85eb519856401f85eb51b8de3740, 8.8, 'safe', 'Planned residential area with good security', 'Uttara, Dhaka', '+880-2-8960000', '24/7', '[\"security\",\"lighting\",\"gated_community\",\"parking\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(11, 'Mohakhali Bus Terminal', 'transport', 23.77780000, 90.40560000, 0xe610000001010000007dd0b359f59956402cd49ae61dc73740, 5.5, 'moderate', 'Bus terminal with moderate safety', 'Mohakhali, Dhaka', '+880-2-9883456', '24/7', '[\"lighting\",\"security\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(12, 'Shahbagh Area', 'commercial', 23.73610000, 90.39580000, 0xe610000001010000006ff085c954995640ea95b20c71bc3740, 7.0, 'moderate', 'Commercial area with mixed safety', 'Shahbagh, Dhaka', '+880-2-9661111', '24/7', '[\"lighting\",\"security\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(13, 'Wari Area', 'residential', 23.71000000, 90.40000000, 0xe610000001010000009a99999999995640f6285c8fc2b53740, 5.8, 'moderate', 'Older residential area', 'Wari, Dhaka', '+880-2-9551111', '24/7', '[\"lighting\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(14, 'Mirpur Botanical Garden', 'park', 23.81670000, 90.36670000, 0xe610000001010000005ddc460378975640a9a44e4013d13740, 7.5, 'safe', 'Botanical garden with good maintenance', 'Mirpur, Dhaka', '+880-2-9001234', '8:00 AM - 6:00 PM', '[\"lighting\",\"security\",\"restrooms\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(15, 'Banani Graveyard Road', 'residential', 23.78500000, 90.40200000, 0xe610000001010000007d3f355eba995640295c8fc2f5c83740, 6.2, 'moderate', 'Residential area with moderate lighting', 'Banani, Dhaka', '+880-2-9884567', '24/7', '[\"lighting\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(16, 'Kakrail Area', 'commercial', 23.73000000, 90.41000000, 0xe610000001010000000ad7a3703d9a56407b14ae47e1ba3740, 6.8, 'moderate', 'Commercial area with moderate safety', 'Kakrail, Dhaka', '+880-2-9552222', '24/7', '[\"lighting\",\"security\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(17, 'Baridhara Diplomatic Zone', 'residential', 23.80000000, 90.42000000, 0xe610000001010000007b14ae47e19a5640cdcccccccccc3740, 9.5, 'safe', 'High-security diplomatic area', 'Baridhara, Dhaka', '+880-2-9889999', '24/7', '[\"security\",\"lighting\",\"gated_community\",\"parking\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(18, 'Farmgate Area', 'commercial', 23.75560000, 90.38890000, 0xe61000000101000000865ad3bce39856408cdb68006fc13740, 6.5, 'moderate', 'Busy commercial area', 'Farmgate, Dhaka', '+880-2-9111111', '24/7', '[\"lighting\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(19, 'Motijheel Commercial Area', 'commercial', 23.72220000, 90.41670000, 0xe61000000101000000910f7a36ab9a5640d42b6519e2b83740, 6.0, 'moderate', 'Major commercial district', 'Motijheel, Dhaka', '+880-2-9553333', '24/7', '[\"lighting\",\"security\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42'),
(20, 'Dhanmondi Residential Area', 'residential', 23.75000000, 90.37000000, 0xe6100000010100000048e17a14ae9756400000000000c03740, 8.0, 'safe', 'Well-planned residential area', 'Dhanmondi, Dhaka', '+880-2-9662222', '24/7', '[\"lighting\",\"security\"]', '2025-11-22 13:28:42', '2025-11-22 13:28:42');

-- --------------------------------------------------------

--
-- Table structure for table `legal_aid_providers`
--

CREATE TABLE `legal_aid_providers` (
  `id` int(11) NOT NULL,
  `organization_name` varchar(255) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `division` varchar(50) DEFAULT NULL,
  `specialization` text NOT NULL COMMENT 'Comma-separated: criminal,civil,family,labor,property,cyber,women_rights,human_rights',
  `language_support` text DEFAULT 'bn,en' COMMENT 'Comma-separated languages',
  `fee_structure` enum('free','low_cost','standard','pro_bono') DEFAULT 'free',
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT 0.00,
  `review_count` int(11) DEFAULT 0,
  `cases_handled` int(11) DEFAULT 0,
  `success_rate` decimal(5,2) DEFAULT 0.00,
  `availability_hours` text DEFAULT NULL,
  `is_24_7` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `legal_aid_providers`
--

INSERT INTO `legal_aid_providers` (`id`, `organization_name`, `contact_person`, `phone`, `email`, `address`, `city`, `district`, `division`, `specialization`, `language_support`, `fee_structure`, `is_verified`, `verified_by`, `rating`, `review_count`, `cases_handled`, `success_rate`, `availability_hours`, `is_24_7`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Ain o Salish Kendra (ASK)', 'Advocate Sultana Kamal', '+8801712345678', 'info@askbd.org', 'House 2/16, Block B, Lalmatia, Dhaka-1207', 'Dhaka', 'Dhaka', 'Dhaka', 'women_rights,human_rights,criminal,civil', 'bn,en', 'free', 1, NULL, 4.80, 150, 0, 0.00, '9:00 AM - 5:00 PM', 0, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24'),
(2, 'Bangladesh Legal Aid and Services Trust (BLAST)', 'Advocate Sara Hossain', '+8801712345679', 'info@blast.org.bd', 'House 39, Road 126, Gulshan-1, Dhaka-1212', 'Dhaka', 'Dhaka', 'Dhaka', 'human_rights,labor,family,civil', 'bn,en', 'free', 1, NULL, 4.70, 200, 0, 0.00, '9:00 AM - 6:00 PM', 0, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24'),
(3, 'Naripokkho', 'Advocate Khushi Kabir', '+8801712345680', 'info@naripokkho.org', 'House 2/14, Block A, Lalmatia, Dhaka-1207', 'Dhaka', 'Dhaka', 'Dhaka', 'women_rights,family,cyber', 'bn,en', 'free', 1, NULL, 4.90, 180, 0, 0.00, '10:00 AM - 4:00 PM', 0, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24'),
(4, 'Law Help Center', 'Advocate Rahman', '+8801712345681', 'help@lawcenter.bd', '24/1, Banglabazar, Old Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 'criminal,civil,property', 'bn', 'low_cost', 1, NULL, 4.50, 90, 0, 0.00, '9:00 AM - 8:00 PM', 0, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24'),
(5, 'Women\'s Legal Aid Services', 'Advocate Fatima Begum', '+8801712345682', 'wlas@bdlegal.org', 'House 15, Road 27, Dhanmondi, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 'women_rights,family,domestic_violence', 'bn,en', 'free', 1, NULL, 4.80, 120, 0, 0.00, '9:00 AM - 5:00 PM', 1, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24'),
(6, 'Cyber Legal Support Center', 'Advocate Ahmed Hassan', '+8801712345683', 'cyberlaw@bdlegal.org', 'House 45, Gulshan-2, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 'cyber,property,criminal', 'bn,en', 'standard', 1, NULL, 4.60, 75, 0, 0.00, '10:00 AM - 7:00 PM', 0, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24'),
(7, 'Chittagong Legal Aid Society', 'Advocate Karim', '+8801812345678', 'clas@bdlegal.org', 'Agrabad, Chittagong', 'Chittagong', 'Chittagong', 'Chittagong', 'criminal,civil,labor', 'bn', 'free', 1, NULL, 4.40, 60, 0, 0.00, '9:00 AM - 5:00 PM', 0, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24'),
(8, 'Sylhet Legal Services', 'Advocate Hasan', '+8801912345678', 'sls@bdlegal.org', 'Zindabazar, Sylhet', 'Sylhet', 'Sylhet', 'Sylhet', 'family,property,civil', 'bn', 'low_cost', 1, NULL, 4.30, 45, 0, 0.00, '10:00 AM - 6:00 PM', 0, 'active', '2025-11-16 13:34:24', '2025-11-16 13:34:24');

-- --------------------------------------------------------

--
-- Table structure for table `legal_consultations`
--

CREATE TABLE `legal_consultations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `provider_id` int(11) NOT NULL,
  `consultation_type` enum('initial','follow_up','emergency','document_review') DEFAULT 'initial',
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `status` enum('requested','scheduled','completed','cancelled','no_show') DEFAULT 'requested',
  `scheduled_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `provider_notes` text DEFAULT NULL,
  `user_feedback` text DEFAULT NULL,
  `rating` int(1) DEFAULT NULL,
  `cost_bdt` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `legal_consultations`
--

INSERT INTO `legal_consultations` (`id`, `user_id`, `report_id`, `provider_id`, `consultation_type`, `subject`, `description`, `preferred_date`, `preferred_time`, `status`, `scheduled_at`, `completed_at`, `provider_notes`, `user_feedback`, `rating`, `cost_bdt`, `created_at`) VALUES
(1, 4, NULL, 1, 'emergency', 'crime issue', 'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn', '2026-01-26', '12:46:00', 'requested', NULL, NULL, NULL, NULL, NULL, 0.00, '2026-01-24 12:46:29'),
(2, 4, NULL, 3, 'initial', 'crime issue', 'dcndcfjsd;afj jdifjdoaif;jsdaiofdj;asifiasdifjsd;iofjiosd;fidjif', '2026-01-24', '13:38:00', 'requested', NULL, NULL, NULL, NULL, NULL, 0.00, '2026-01-24 14:38:15');

-- --------------------------------------------------------

--
-- Table structure for table `legal_documents`
--

CREATE TABLE `legal_documents` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `document_type` enum('form','template','guideline','law_reference','case_study') NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_url` varchar(500) DEFAULT NULL,
  `language` enum('bn','en','both') DEFAULT 'bn',
  `download_count` int(11) DEFAULT 0,
  `is_premium` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `status` enum('active','draft','archived') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `legal_documents`
--

INSERT INTO `legal_documents` (`id`, `title`, `document_type`, `category`, `description`, `file_path`, `file_url`, `language`, `download_count`, `is_premium`, `created_by`, `status`, `created_at`) VALUES
(1, 'Complaint Form Template', 'form', 'General', 'Standard complaint form template that can be used for filing complaints with police or other authorities.', NULL, 'https://example.com/docs/complaint-form.pdf', 'both', 1, 0, NULL, 'active', '2025-11-16 13:34:24'),
(2, 'Domestic Violence Protection Guide', 'guideline', 'Family Law', 'Comprehensive guide on domestic violence laws, protection orders, and legal remedies available in Bangladesh.', NULL, 'https://example.com/docs/domestic-violence-guide.pdf', 'bn', 0, 0, NULL, 'active', '2025-11-16 13:34:24'),
(3, 'Labor Rights Handbook', 'guideline', 'Labor Law', 'Essential information about labor rights, minimum wage, working conditions, and how to file labor complaints.', NULL, 'https://example.com/docs/labor-rights-handbook.pdf', 'bn', 0, 0, NULL, 'active', '2025-11-16 13:34:24'),
(4, 'Cyber Crime Reporting Guide', 'guideline', 'Cyber Law', 'Step-by-step guide on how to report cyber crimes, what evidence to collect, and legal procedures.', NULL, 'https://example.com/docs/cyber-crime-guide.pdf', 'bn', 0, 0, NULL, 'active', '2025-11-16 13:34:24'),
(5, 'Women\'s Rights Legal Framework', 'law_reference', 'Women Rights', 'Complete reference of laws protecting women\'s rights in Bangladesh including marriage, divorce, and inheritance.', NULL, 'https://example.com/docs/womens-rights-framework.pdf', 'bn', 0, 0, NULL, 'active', '2025-11-16 13:34:24'),
(6, 'Legal Aid Application Form', 'form', 'Legal Aid', 'Form to apply for free legal aid services from government and NGO legal aid providers.', NULL, 'https://example.com/docs/legal-aid-application.pdf', 'both', 0, 0, NULL, 'active', '2025-11-16 13:34:24'),
(7, 'Property Dispute Template', 'template', 'Property Law', 'Template for filing property disputes including land, house, and commercial property cases.', NULL, 'https://example.com/docs/property-dispute-template.pdf', 'bn', 0, 0, NULL, 'active', '2025-11-16 13:34:24'),
(8, 'Human Rights Violation Report Format', 'form', 'Human Rights', 'Standard format for reporting human rights violations to relevant authorities and organizations.', NULL, 'https://example.com/docs/hr-violation-report.pdf', 'both', 1, 0, NULL, 'active', '2025-11-16 13:34:24');

-- --------------------------------------------------------

--
-- Table structure for table `medical_support_providers`
--

CREATE TABLE `medical_support_providers` (
  `id` int(11) NOT NULL,
  `provider_name` varchar(255) NOT NULL,
  `provider_type` enum('hospital','clinic','counselor','psychologist','psychiatrist','trauma_center','ngo') NOT NULL,
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
  `fee_structure` enum('free','subsidized','standard','premium') DEFAULT 'free',
  `rating` decimal(3,2) DEFAULT 0.00,
  `review_count` int(11) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_support_providers`
--

INSERT INTO `medical_support_providers` (`id`, `provider_name`, `provider_type`, `specialization`, `phone`, `email`, `website`, `address`, `city`, `district`, `division`, `is_24_7`, `languages`, `accepts_insurance`, `insurance_types`, `fee_structure`, `rating`, `review_count`, `is_verified`, `verified_by`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Dhaka Medical College Hospital', 'hospital', 'trauma_care,emergency,forensic,sexual_assault', '01713333333', 'info@dmch.gov.bd', NULL, 'Dhaka Medical College Road, Dhaka 1000', 'Dhaka', 'Dhaka', 'Dhaka', 1, 'bn,en', 1, NULL, 'subsidized', 4.50, 120, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11'),
(2, 'One Stop Crisis Centre (OCC)', 'trauma_center', 'trauma_care,sexual_assault,domestic_violence,women_health', '01713333334', 'occ@mohfw.gov.bd', NULL, 'Dhaka Medical College Hospital, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 1, 'bn,en', 1, NULL, 'free', 4.80, 95, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11'),
(3, 'Bangladesh National Women Lawyers Association', 'ngo', 'legal_support,domestic_violence,sexual_assault,counseling', '01713333335', 'info@bnwla.org', NULL, 'House 13, Road 27, Dhanmondi, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 0, 'bn,en', 0, NULL, 'free', 4.70, 78, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11'),
(4, 'National Institute of Mental Health', 'hospital', 'mental_health,psychology,psychiatry', '01713333336', 'info@nimh.gov.bd', NULL, 'Sher-e-Bangla Nagar, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 1, 'bn,en', 1, NULL, 'subsidized', 4.40, 150, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11'),
(5, 'Marie Stopes Bangladesh', 'clinic', 'women_health,sexual_assault,reproductive_health', '01713333337', 'info@mariestopes.org.bd', NULL, 'House 5, Road 10, Dhanmondi, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 0, 'bn,en', 1, NULL, 'subsidized', 4.60, 200, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11'),
(6, 'Ain o Salish Kendra (ASK)', 'ngo', 'legal_support,domestic_violence,human_rights,counseling', '01713333338', 'ask@citechco.net', NULL, 'House 2/1, Block F, Lalmatia, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 0, 'bn,en', 0, NULL, 'free', 4.90, 300, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11'),
(7, 'Chittagong Medical College Hospital', 'hospital', 'trauma_care,emergency,forensic', '01713333339', 'info@cmch.gov.bd', NULL, 'Chittagong Medical College Road, Chittagong', 'Chittagong', 'Chittagong', 'Chittagong', 1, 'bn,en', 1, NULL, 'subsidized', 4.30, 85, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11'),
(8, 'BRAC Mental Health Program', 'ngo', 'mental_health,counseling,psychology', '01713333340', 'mhp@brac.net', NULL, 'BRAC Centre, 75 Mohakhali, Dhaka', 'Dhaka', 'Dhaka', 'Dhaka', 0, 'bn,en,chakma', 0, NULL, 'free', 4.50, 180, 1, NULL, 'active', '2025-11-16 14:18:11', '2025-11-16 14:18:11');

-- --------------------------------------------------------

--
-- Table structure for table `neighborhood_groups`
--

CREATE TABLE `neighborhood_groups` (
  `id` int(11) NOT NULL,
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
  `status` enum('active','inactive','suspended','pending_approval') DEFAULT 'pending_approval',
  `privacy_level` enum('public','private','invite_only') DEFAULT 'public',
  `rules` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `neighborhood_groups`
--

INSERT INTO `neighborhood_groups` (`id`, `group_name`, `description`, `area_name`, `ward_number`, `union_name`, `division_id`, `district_id`, `upazila_id`, `created_by`, `member_count`, `active_members`, `is_verified`, `verified_by`, `status`, `privacy_level`, `rules`, `created_at`, `updated_at`) VALUES
(1, 'Dhanmondi Community Watch', 'Active community safety group for Dhanmondi area residents. We share safety alerts and work together to keep our neighborhood safe.', 'Dhanmondi', '27', 'Dhanmondi', 1, 1, 1, 1, 3, 3, 1, NULL, 'active', 'public', '1. Be respectful to all members\n2. Only post verified information\n3. No personal attacks\n4. Report false information immediately', '2025-11-16 13:52:50', '2026-01-24 14:36:37'),
(2, 'Gulshan Safety Network', 'Community safety network for Gulshan residents. Join us to stay informed about safety issues in our area.', 'Gulshan', '19', 'Gulshan', 1, 1, 2, 1, 32, 28, 1, NULL, 'active', 'public', '1. Respect privacy\n2. Verify before sharing\n3. Help neighbors in need', '2025-11-16 13:52:50', '2025-11-16 13:52:50'),
(3, 'Mirpur Community Safety', 'Safety group for Mirpur area. We coordinate neighborhood watch activities and share important safety updates.', 'Mirpur', '10', 'Mirpur', 1, 1, 3, 1, 28, 22, 0, NULL, 'active', 'public', 'Community safety rules apply', '2025-11-16 13:52:50', '2025-11-16 13:52:50'),
(4, 'Uttara Residents Safety Group', 'Uttara area residents working together for community safety and security.', 'Uttara', '1', 'Uttara', 1, 1, 4, 1, 2, 2, 1, NULL, 'active', 'public', 'Standard community safety guidelines', '2025-11-16 13:52:50', '2025-11-16 14:04:05'),
(5, 'Banani Community Watch', 'Active safety monitoring for Banani area. Join to stay informed and contribute to community safety.', 'Banani', '18', 'Banani', 1, 1, 2, 1, 22, 18, 0, NULL, 'active', 'public', 'Community safety first', '2025-11-16 13:52:50', '2025-11-16 13:52:50'),
(21, 'Mirpur 10', 'For the safety for Mirpur bashi', 'pallabi', '', '', 1, 1, 8, 2, 2, 2, 0, NULL, 'active', 'private', '', '2025-11-16 15:31:31', '2025-11-23 19:36:46');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('alert','report_update','dispute_update','system','emergency') DEFAULT 'system',
  `action_url` varchar(255) DEFAULT NULL,
  `action_data` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_sent` tinyint(1) DEFAULT 0,
  `email_sent` tinyint(1) DEFAULT 0,
  `sms_sent` tinyint(1) DEFAULT 0,
  `push_sent` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `action_url`, `action_data`, `is_read`, `is_sent`, `email_sent`, `sms_sent`, `push_sent`, `created_at`, `read_at`, `expires_at`) VALUES
(1, 1, 'Report Submitted Successfully', 'Your incident report #101 has been submitted and is under review.', 'report_update', 'view_report.php?id=101', NULL, 0, 0, 0, 0, 0, '2025-11-13 15:43:04', NULL, NULL),
(2, 1, 'Report Submitted Successfully', 'Your incident report #102 has been submitted and is under review.', 'report_update', 'view_report.php?id=102', NULL, 0, 0, 0, 0, 0, '2025-11-13 15:45:02', NULL, NULL),
(3, 2, 'Report Submitted Successfully', 'Your incident report #103 has been submitted and is under review.', 'report_update', 'view_report.php?id=103', NULL, 0, 0, 0, 0, 0, '2025-11-16 13:24:40', NULL, NULL),
(4, 2, 'Joined Community Group', 'You have successfully joined \"Dhanmondi Community Watch\"', 'system', 'group_detail.php?id=1', NULL, 0, 0, 0, 0, 0, '2025-11-16 14:03:24', NULL, NULL),
(5, 2, 'Joined Community Group', 'You have successfully joined \"Uttara Residents Safety Group\"', 'system', 'group_detail.php?id=4', NULL, 0, 0, 0, 0, 0, '2025-11-16 14:04:05', NULL, NULL),
(6, 2, 'Joined Community Group', 'You have successfully joined \"Dhanmondi Community Watch\"', 'system', 'group_detail.php?id=16', NULL, 0, 0, 0, 0, 0, '2025-11-16 14:14:51', NULL, NULL),
(7, 2, 'Group Created Successfully', 'Your community group \"Mirpur 10\" has been created and is pending approval.', 'system', 'community_groups.php', NULL, 0, 0, 0, 0, 0, '2025-11-16 15:31:31', NULL, NULL),
(8, 1, 'Report Submitted Successfully', 'Your incident report #104 has been submitted and is under review.', 'report_update', 'view_report.php?id=104', NULL, 0, 0, 0, 0, 0, '2025-11-20 18:23:31', NULL, NULL),
(9, 4, 'Joined Community Group', 'You have successfully joined \"Mirpur 10\"', 'system', 'group_detail.php?id=21', NULL, 0, 0, 0, 0, 0, '2025-11-23 19:36:46', NULL, NULL),
(10, 1, 'Report Submitted Successfully', 'Your incident report #115 has been submitted and is under review.', 'report_update', 'view_report.php?id=115', NULL, 0, 0, 0, 0, 0, '2026-01-17 17:45:10', NULL, NULL),
(11, 6, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Unknown\nPhone: N/A\nLocation: \nGPS: 23.797947456626, 90.449444430421\nMap: https://maps.google.com/?q=23.797947456626,90.449444430421\nLive Tracking: http://localhost/space-login/track_walk.php?token=58bca15d5ef35c5b95de6688680809b14efaea0ad3ec935086d31b886cadeed6\nTime: 2026-01-24 04:16:22\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-01-24 09:16:22', NULL, NULL),
(12, 6, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Unknown\nPhone: N/A\nLocation: \nGPS: 23.798447998936, 90.450098247573\nMap: https://maps.google.com/?q=23.798447998936,90.450098247573\nLive Tracking: http://localhost/space-login/track_walk.php?token=6f6bdb204ab747efd886926ce48a995c1981af3afdcb87326f7758b1a048b84b\nTime: 2026-01-24 04:59:30\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-01-24 09:59:30', NULL, NULL),
(13, 6, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Unknown\nPhone: N/A\nLocation: \nGPS: 23.798394089991, 90.450101009804\nMap: https://maps.google.com/?q=23.798394089991,90.450101009804\nLive Tracking: http://localhost/space-login/track_walk.php?token=7862a4cfb79b3ff2508fcc6a19cc4626ddf15d8e646e56619c4ad5f9f7cb4472\nTime: 2026-01-24 05:02:51\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-01-24 10:02:51', NULL, NULL),
(14, 6, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Unknown\nPhone: N/A\nLocation: \nGPS: 23.798424945568, 90.450098865706\nMap: https://maps.google.com/?q=23.798424945568,90.450098865706\nLive Tracking: http://localhost/space-login/track_walk.php?token=8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a\nTime: 2026-01-24 05:17:41\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-01-24 10:17:41', NULL, NULL),
(15, 5, 'Report Submitted Successfully', 'Your incident report #116 has been submitted and is under review.', 'report_update', 'view_report.php?id=116', NULL, 0, 0, 0, 0, 0, '2026-01-24 12:14:55', NULL, NULL),
(16, 4, 'Consultation Request Submitted', 'Your legal consultation request #1 has been submitted. The provider will contact you within 48 hours.', 'report_update', 'my_consultations.php', NULL, 0, 0, 0, 0, 0, '2026-01-24 12:46:29', NULL, NULL),
(17, 4, 'Joined Community Group', 'You have successfully joined \"Dhanmondi Community Watch\"', 'system', 'group_detail.php?id=1', NULL, 0, 0, 0, 0, 0, '2026-01-24 14:36:37', NULL, NULL),
(18, 4, 'Consultation Request Submitted', 'Your legal consultation request #2 has been submitted. The provider will contact you within 48 hours.', 'report_update', 'my_consultations.php', NULL, 0, 0, 0, 0, 0, '2026-01-24 14:38:15', NULL, NULL),
(19, 6, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Unknown\nPhone: N/A\nLocation: \nGPS: 23.814533786745, 90.386511085607\nMap: https://maps.google.com/?q=23.814533786745,90.386511085607\nLive Tracking: http://localhost/space-login/track_walk.php?token=0ee137c065df04c36495fa1cf26569667c72c92b647f5a3021b1747926d79fd4\nTime: 2026-02-09 14:58:54\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-09 19:58:54', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `panic_alerts`
--

CREATE TABLE `panic_alerts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `trigger_method` enum('app_button','sms_keyword','voice_command','automated') NOT NULL,
  `location_name` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `emergency_contacts_notified` int(11) DEFAULT 0,
  `police_notified` tinyint(1) DEFAULT 0,
  `ambulance_notified` tinyint(1) DEFAULT 0,
  `fire_service_notified` tinyint(1) DEFAULT 0,
  `status` enum('active','acknowledged','false_alarm','resolved') DEFAULT 'active',
  `response_time_seconds` int(11) DEFAULT NULL,
  `triggered_at` datetime DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `responders_count` int(11) DEFAULT 0 COMMENT 'Number of people responding',
  `community_notified` tinyint(1) DEFAULT 0 COMMENT 'Whether nearby users were notified',
  `broadcast_radius` int(11) DEFAULT 5000 COMMENT 'Radius in meters for community broadcast',
  `nearby_users_count` int(11) DEFAULT 0 COMMENT 'Number of nearby users at time of alert'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `panic_alerts`
--

INSERT INTO `panic_alerts` (`id`, `user_id`, `trigger_method`, `location_name`, `latitude`, `longitude`, `message`, `emergency_contacts_notified`, `police_notified`, `ambulance_notified`, `fire_service_notified`, `status`, `response_time_seconds`, `triggered_at`, `resolved_at`, `responders_count`, `community_notified`, `broadcast_radius`, `nearby_users_count`) VALUES
(1, 1, 'app_button', '23.814613316748986, 90.38611845264977', 23.81461332, 90.38611845, '', 0, 1, 0, 0, 'active', NULL, '2025-11-23 23:43:36', NULL, 0, 0, 5000, 0),
(2, 1, 'app_button', '23.81435478029568, 90.38679105231283', 23.81435478, 90.38679105, 'Help me', 1, 0, 0, 0, 'active', NULL, '2025-11-24 00:09:05', NULL, 0, 0, 5000, 0),
(3, 4, 'app_button', '23.797324, 90.449757', 23.79732400, 90.44975700, '', 0, 1, 0, 0, 'active', NULL, '2025-11-24 08:06:13', NULL, 0, 0, 5000, 0),
(4, 6, 'app_button', '23.797947456625984, 90.44944443042094', 23.79794746, 90.44944443, '', 2, 0, 0, 0, 'active', NULL, '2026-01-24 09:14:56', NULL, 0, 0, 5000, 0),
(5, 6, '', '', 23.79794746, 90.44944443, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=58bca15d5ef35c5b95de6688680809b14efaea0ad3ec935086d31b886cadeed6', 2, 1, 0, 0, 'active', NULL, '2026-01-24 09:16:22', NULL, 0, 0, 5000, 0),
(6, 6, '', '', 23.79844800, 90.45009825, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=6f6bdb204ab747efd886926ce48a995c1981af3afdcb87326f7758b1a048b84b', 2, 1, 0, 0, 'active', NULL, '2026-01-24 09:59:30', NULL, 0, 0, 5000, 0),
(7, 6, '', '', 23.79839409, 90.45010101, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=7862a4cfb79b3ff2508fcc6a19cc4626ddf15d8e646e56619c4ad5f9f7cb4472', 2, 1, 0, 0, 'active', NULL, '2026-01-24 10:02:51', NULL, 0, 0, 5000, 0),
(8, 6, '', '', 23.79842495, 90.45009887, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a', 2, 1, 0, 0, 'active', NULL, '2026-01-24 10:17:41', NULL, 0, 1, 5000, 0),
(9, 5, '', '', 23.79840436, 90.45009759, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=811ff24d743e03d6ed2e50de0b6663e13fe6440d78c8e25e5bf3649c56d74965', 0, 1, 0, 0, 'active', NULL, '2026-01-24 10:22:47', NULL, 0, 1, 5000, 0),
(10, 4, '', '', 23.79841092, 90.45011152, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=21e6b60b84c43ad0737dc9c5dcc7c4813e7a2d45891eb73f33c755f728a969d7', 0, 1, 0, 0, 'active', NULL, '2026-01-24 12:55:11', NULL, 0, 0, 5000, 0),
(11, 4, '', '', 23.79765900, 90.45019484, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=1b0b732fda336709d2fecdadaff5bff0318bb0bc14ccf18918b66ccb87c6b656', 0, 1, 0, 0, 'active', NULL, '2026-01-24 14:41:28', NULL, 0, 0, 5000, 0),
(12, 4, '', '', 23.79765900, 90.45019484, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=3a92490418120d1654e23696e29c78a9793127d0095a0fcbc755463651b92066', 0, 1, 0, 0, 'active', NULL, '2026-01-24 14:52:59', NULL, 0, 0, 5000, 0),
(13, 6, '', '', 23.81453379, 90.38651109, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=0ee137c065df04c36495fa1cf26569667c72c92b647f5a3021b1747926d79fd4', 2, 1, 0, 0, 'active', NULL, '2026-02-09 19:58:54', NULL, 0, 0, 5000, 0),
(14, 4, 'app_button', '23.814584156267806, 90.38655722500954', 23.81458416, 90.38655723, '', 0, 1, 0, 0, 'active', NULL, '2026-02-09 20:01:11', NULL, 0, 0, 5000, 0),
(15, 4, '', '', 23.81458416, 90.38655723, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=79a8c36da85e4b2b2ab4c43cd3405f77d0155bb17ff70acf195cccc52c154bbb', 0, 1, 0, 0, 'active', NULL, '2026-02-09 20:01:23', NULL, 0, 0, 5000, 0),
(16, 4, 'app_button', '23.81444352890792, 90.38659543342219', 23.81444353, 90.38659543, '', 0, 1, 0, 0, 'active', NULL, '2026-02-09 20:12:45', NULL, 0, 0, 5000, 0),
(17, 4, 'app_button', '23.814424411656717, 90.38656005061553', 23.81442441, 90.38656005, '', 0, 1, 0, 0, 'active', NULL, '2026-02-09 20:12:53', NULL, 0, 0, 5000, 0),
(18, 4, '', '', 23.81442441, 90.38656005, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=d8fa38a4d19e34392f728ce0a2abc8fc39e9a9cf0d473d40aabce2a82d4300a9', 0, 1, 0, 0, 'active', NULL, '2026-02-09 20:13:01', NULL, 0, 0, 5000, 0);

-- --------------------------------------------------------

--
-- Table structure for table `panic_notifications`
--

CREATE TABLE `panic_notifications` (
  `id` int(11) NOT NULL,
  `panic_alert_id` int(11) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `notification_type` enum('sms','whatsapp','email','call','push') NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('pending','sent','delivered','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `panic_notifications`
--

INSERT INTO `panic_notifications` (`id`, `panic_alert_id`, `contact_id`, `notification_type`, `recipient`, `message`, `created_at`, `status`, `sent_at`, `delivered_at`, `error_message`) VALUES
(1, 2, 1, 'sms', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Sayum\nLocation: https://maps.google.com/?q=23.81435478,90.38679105\nTime: 2025-11-24 00:09:05\nMessage: Help me\n\nPlease respond immediately!', '2025-11-24 00:09:05', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(2, 4, 2, 'sms', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79794746,90.44944443\nTime: 2026-01-24 09:14:56\n\nPlease respond immediately!', '2026-01-24 09:14:56', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(3, 4, 2, 'call', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79794746,90.44944443\nTime: 2026-01-24 09:14:56\n\nPlease respond immediately!', '2026-01-24 09:14:56', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(4, 5, 2, 'sms', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79794746,90.44944443\nTime: 2026-01-24 09:16:22\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=58bca15d5ef35c5b95de6688680809b14efaea0ad3ec935086d31b886cadeed6\n\nPlease respond immediately!', '2026-01-24 09:16:22', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(5, 5, 2, 'call', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79794746,90.44944443\nTime: 2026-01-24 09:16:22\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=58bca15d5ef35c5b95de6688680809b14efaea0ad3ec935086d31b886cadeed6\n\nPlease respond immediately!', '2026-01-24 09:16:22', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(6, 6, 2, 'sms', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79844800,90.45009825\nTime: 2026-01-24 09:59:30\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=6f6bdb204ab747efd886926ce48a995c1981af3afdcb87326f7758b1a048b84b\n\nPlease respond immediately!', '2026-01-24 09:59:30', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(7, 6, 2, 'call', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79844800,90.45009825\nTime: 2026-01-24 09:59:30\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=6f6bdb204ab747efd886926ce48a995c1981af3afdcb87326f7758b1a048b84b\n\nPlease respond immediately!', '2026-01-24 09:59:30', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(8, 7, 2, 'sms', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79839409,90.45010101\nTime: 2026-01-24 10:02:51\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=7862a4cfb79b3ff2508fcc6a19cc4626ddf15d8e646e56619c4ad5f9f7cb4472\n\nPlease respond immediately!', '2026-01-24 10:02:51', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(9, 7, 2, 'call', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79839409,90.45010101\nTime: 2026-01-24 10:02:51\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=7862a4cfb79b3ff2508fcc6a19cc4626ddf15d8e646e56619c4ad5f9f7cb4472\n\nPlease respond immediately!', '2026-01-24 10:02:51', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(10, 8, 2, 'sms', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79842495,90.45009887\nTime: 2026-01-24 10:17:41\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a\n\nPlease respond immediately!', '2026-01-24 10:17:41', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(11, 8, 2, 'call', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.79842495,90.45009887\nTime: 2026-01-24 10:17:41\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a\n\nPlease respond immediately!', '2026-01-24 10:17:41', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(12, 13, 2, 'sms', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.81453379,90.38651109\nTime: 2026-02-09 19:58:54\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=0ee137c065df04c36495fa1cf26569667c72c92b647f5a3021b1747926d79fd4\n\nPlease respond immediately!', '2026-02-09 19:58:54', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(13, 13, 2, 'call', '01871745957', '🚨 EMERGENCY ALERT 🚨\n\nUser: Unknown\nLocation: https://maps.google.com/?q=23.81453379,90.38651109\nTime: 2026-02-09 19:58:54\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=0ee137c065df04c36495fa1cf26569667c72c92b647f5a3021b1747926d79fd4\n\nPlease respond immediately!', '2026-02-09 19:58:54', 'failed', NULL, NULL, 'Call service not enabled in configuration');

-- --------------------------------------------------------

--
-- Table structure for table `safety_courses`
--

CREATE TABLE `safety_courses` (
  `id` int(11) NOT NULL,
  `course_title` varchar(255) NOT NULL,
  `course_description` text DEFAULT NULL,
  `category` enum('self_defense','cyber_safety','legal_rights','emergency_response','prevention','awareness') NOT NULL,
  `target_audience` text DEFAULT 'general',
  `duration_minutes` int(11) DEFAULT NULL,
  `content_type` enum('video','interactive','text','quiz','mixed') DEFAULT 'mixed',
  `content_url` varchar(500) DEFAULT NULL,
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `is_premium` tinyint(1) DEFAULT 0,
  `instructor_name` varchar(100) DEFAULT NULL,
  `language` enum('bn','en','both') DEFAULT 'bn',
  `enrollment_count` int(11) DEFAULT 0,
  `completion_count` int(11) DEFAULT 0,
  `average_rating` decimal(3,2) DEFAULT 0.00,
  `rating_count` int(11) DEFAULT 0,
  `status` enum('active','draft','archived') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `safety_courses`
--

INSERT INTO `safety_courses` (`id`, `course_title`, `course_description`, `category`, `target_audience`, `duration_minutes`, `content_type`, `content_url`, `thumbnail_url`, `is_premium`, `instructor_name`, `language`, `enrollment_count`, `completion_count`, `average_rating`, `rating_count`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Basic Self-Defense for Women', 'Learn essential self-defense techniques to protect yourself in dangerous situations. This course covers basic moves, awareness, and prevention strategies.', 'self_defense', 'women,general', 60, 'video', NULL, NULL, 0, 'Sgt. Fatima Rahman', 'both', 150, 120, 4.70, 95, 'active', '2025-11-16 15:12:15', '2025-11-16 15:12:15'),
(2, 'Cyber Safety & Online Protection', 'Protect yourself from cyberbullying, online harassment, and digital threats. Learn about privacy settings, safe online practices, and reporting mechanisms.', 'cyber_safety', 'general,children', 45, 'interactive', NULL, NULL, 0, 'Dr. Ahmed Hassan', 'both', 200, 180, 4.60, 150, 'active', '2025-11-16 15:12:15', '2025-11-16 15:12:15'),
(3, 'Know Your Legal Rights', 'Comprehensive guide to legal rights in Bangladesh, including women\'s rights, workplace rights, and how to seek legal help.', 'legal_rights', 'women,general,professionals', 90, 'mixed', NULL, NULL, 0, 'Advocate Roksana Begum', 'bn', 181, 140, 4.80, 120, 'active', '2025-11-16 15:12:15', '2025-12-08 06:41:13'),
(4, 'Emergency Response Training', 'Learn how to respond to emergencies, contact authorities, and provide first aid. Essential skills for everyone.', 'emergency_response', 'general', 75, 'video', NULL, NULL, 0, 'Dr. Mohammad Ali', 'both', 250, 200, 4.50, 180, 'active', '2025-11-16 15:12:15', '2025-11-16 15:12:15'),
(5, 'Preventing Domestic Violence', 'Understanding domestic violence, recognizing warning signs, and knowing how to get help. Resources and support information included.', 'prevention', 'women,general', 50, 'mixed', NULL, NULL, 0, 'Counselor Nasreen Akter', 'bn', 301, 250, 4.90, 220, 'active', '2025-11-16 15:12:15', '2025-11-16 15:13:17'),
(6, 'Child Safety & Protection', 'Essential safety education for parents and children. Learn about child protection, safe spaces, and how to report incidents.', 'awareness', 'children,general', 40, 'interactive', NULL, NULL, 0, 'Ms. Sharmin Sultana', 'both', 175, 150, 4.70, 130, 'active', '2025-11-16 15:12:15', '2025-11-16 15:12:15'),
(7, 'Workplace Safety & Harassment Prevention', 'Understanding workplace rights, recognizing harassment, and knowing how to report and protect yourself at work.', 'prevention', 'professionals,women', 55, 'mixed', NULL, NULL, 0, 'HR Specialist Tanvir Ahmed', 'both', 120, 95, 4.60, 80, 'active', '2025-11-16 15:12:15', '2025-11-16 15:12:15'),
(8, 'Advanced Self-Defense Techniques', 'Advanced self-defense moves and strategies for more experienced learners. Builds on basic techniques.', 'self_defense', 'women,general', 90, 'video', NULL, NULL, 1, 'Sgt. Fatima Rahman', 'both', 80, 60, 4.80, 50, 'active', '2025-11-16 15:12:15', '2025-11-16 15:12:15');

-- --------------------------------------------------------

--
-- Table structure for table `safety_resources`
--

CREATE TABLE `safety_resources` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('helpline','guide','emergency','support_group','legal','medical','counseling') NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_24_7` tinyint(1) DEFAULT 0,
  `hours_of_operation` text DEFAULT NULL,
  `languages` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Bangladesh',
  `is_national` tinyint(1) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `status` enum('active','inactive','pending') DEFAULT 'active',
  `views_count` int(11) DEFAULT 0,
  `contact_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `safe_spaces`
--

CREATE TABLE `safe_spaces` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('community_center','police_station','hospital','school','library','business','religious','other') NOT NULL,
  `address` text NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Bangladesh',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `hours_of_operation` text DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_date` datetime DEFAULT NULL,
  `status` enum('active','inactive','pending_verification') DEFAULT 'pending_verification',
  `average_rating` decimal(3,2) DEFAULT 0.00,
  `review_count` int(11) DEFAULT 0,
  `features` text DEFAULT NULL,
  `accessibility_features` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `safe_spaces`
--

INSERT INTO `safe_spaces` (`id`, `name`, `description`, `category`, `address`, `latitude`, `longitude`, `city`, `state`, `country`, `phone`, `email`, `website`, `hours_of_operation`, `is_verified`, `verified_by`, `verified_date`, `status`, `average_rating`, `review_count`, `features`, `accessibility_features`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'kuril safe ', 'safee', 'business', 'User-defined area', 23.82100957, 90.42075951, NULL, NULL, 'Bangladesh', NULL, NULL, NULL, NULL, 0, NULL, NULL, 'active', 0.00, 0, '{\"safety_level\":\"medium\",\"polygon_coordinates\":[[90.42068242545447,23.821056832017618],[90.42071996281288,23.820948809630934],[90.42083793736793,23.820978270290755],[90.4207977187696,23.821054376964394]],\"type\":\"user_drawn_zone\"}', NULL, 4, '2026-01-24 13:07:22', '2026-01-24 13:07:49');

-- --------------------------------------------------------

--
-- Table structure for table `safe_zones`
--

CREATE TABLE `safe_zones` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `boundary` polygon NOT NULL,
  `safety_level` enum('high','medium','low') NOT NULL DEFAULT 'medium',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `safe_zones`
--

INSERT INTO `safe_zones` (`id`, `name`, `description`, `boundary`, `safety_level`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'safe space', 'a place for woman ', 0xe61000000103000000010000000500000066de4093cd9c56402c7272009dd73740dd8debfbdb9c56405d017b28abd737405541ca63dd9c5640768d693a5fd73740db911ffbce9c56401a04a23c68d7374066de4093cd9c56402c7272009dd73740, 'medium', 5, '2026-01-24 02:37:32', '2026-01-24 02:37:32');

-- --------------------------------------------------------

--
-- Table structure for table `support_referrals`
--

CREATE TABLE `support_referrals` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `provider_id` int(11) NOT NULL,
  `referral_type` enum('medical','counseling','emergency','follow_up') NOT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `reason` text DEFAULT NULL,
  `status` enum('pending','contacted','appointment_scheduled','completed','declined') DEFAULT 'pending',
  `referred_at` datetime DEFAULT current_timestamp(),
  `appointment_date` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `user_feedback` text DEFAULT NULL,
  `rating` int(1) DEFAULT NULL,
  `provider_notes` text DEFAULT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_statistics`
--

CREATE TABLE `system_statistics` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upazilas`
--

CREATE TABLE `upazilas` (
  `id` int(11) NOT NULL,
  `district_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `upazilas`
--

INSERT INTO `upazilas` (`id`, `district_id`, `name`, `created_at`, `updated_at`) VALUES
(1, 1, 'Dhanmondi', '2025-11-16 13:30:00', '2025-11-16 13:30:00'),
(2, 1, 'Gulshan', '2025-11-16 13:30:00', '2025-11-16 13:30:00'),
(3, 1, 'Mirpur', '2025-11-16 13:30:00', '2025-11-16 13:30:00'),
(4, 1, 'Uttara', '2025-11-16 13:30:00', '2025-11-16 13:30:00'),
(5, 1, 'Banani', '2025-11-16 13:30:00', '2025-11-16 13:30:00'),
(6, 1, 'Wari', '2025-11-16 13:30:00', '2025-11-16 13:30:00'),
(7, 1, 'Motijheel', '2025-11-16 13:30:00', '2025-11-16 13:30:00'),
(8, 1, 'Pallabi', '2025-11-16 13:30:00', '2025-11-16 13:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
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
  `nid_number` varchar(20) DEFAULT NULL,
  `nid_front_photo` varchar(255) DEFAULT NULL,
  `nid_back_photo` varchar(255) DEFAULT NULL,
  `face_verified` tinyint(1) DEFAULT 0,
  `nid_verified` tinyint(1) DEFAULT 0,
  `verification_status` enum('pending','under_review','verified','rejected') DEFAULT 'pending',
  `current_latitude` decimal(10,8) DEFAULT NULL COMMENT 'Current GPS latitude',
  `current_longitude` decimal(11,8) DEFAULT NULL COMMENT 'Current GPS longitude',
  `last_location_update` timestamp NULL DEFAULT NULL COMMENT 'Last time location was updated',
  `is_online` tinyint(1) DEFAULT 0 COMMENT 'Whether user is currently active',
  `last_seen` timestamp NULL DEFAULT NULL COMMENT 'Last activity timestamp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `phone`, `bio`, `password`, `display_name`, `firebase_uid`, `provider`, `email_verified`, `is_admin`, `verification_token`, `password_reset_token`, `reset_token_expires`, `status`, `is_active`, `created_at`, `last_login`, `updated_at`, `nid_number`, `nid_front_photo`, `nid_back_photo`, `face_verified`, `nid_verified`, `verification_status`, `current_latitude`, `current_longitude`, `last_location_update`, `is_online`, `last_seen`) VALUES
(1, NULL, 'mdabusayumanik123@gmail.com', '+8801871745957', 'I am a Student', '$2y$10$Fg97eJDVI9xJiNdV3Js5POtKt4BMooAPk.s5eTGmzE.Rv0LaEEe4u', 'Sayum', 'b7bUyVB73gQhELitdXCQRLtRf3B3', 'google.com', 0, 0, NULL, NULL, NULL, 'active', 1, '2025-11-12 18:52:03', '2026-01-17 17:40:08', '2026-01-17 17:40:08', '4222776215', 'uploads/nid/nid_4222776215_front_1762951923_691482f325b0d.jpg', 'uploads/nid/nid_4222776215_back_1762951923_691482f326b51.jpg', 0, 0, 'pending', NULL, NULL, NULL, 0, NULL),
(2, NULL, 'manik2330217@bscse.uiu.ac.bd', '+8801871745958', '', '$2y$10$tMgm7ALjgLHzXrvHVmga1e/24EmigJr.IJDsZnXl0z.ar/KSB26zK', '', 'ZOYOmrnoxwX0XnhSmNiA87wSn0l1', 'password', 0, 0, NULL, NULL, NULL, 'active', 1, '2025-11-13 16:47:05', '2025-11-18 20:20:33', '2025-11-18 20:20:33', '4222776225', 'uploads/nid/nid_4222776225_front_1763030825_6915b7290cac7.jpeg', 'uploads/nid/nid_4222776225_back_1763030825_6915b7290e8b2.jpeg', 0, 0, 'pending', NULL, NULL, NULL, 0, NULL),
(3, NULL, 'admin@test.com', NULL, NULL, NULL, 'admin', NULL, 'local', 1, 0, NULL, NULL, NULL, 'active', 1, '2025-11-23 18:23:13', '2025-11-23 18:23:13', '2025-11-23 18:23:13', NULL, NULL, NULL, 0, 0, 'pending', NULL, NULL, NULL, 0, NULL),
(4, NULL, 'admin@safespace.com', NULL, NULL, '$2y$10$lHSheUwrpDNu76L3SxxxhOlW2weHx9fZWo47BT3BV3ibmySaORkkG', 'System Administrator', NULL, 'local', 1, 1, NULL, NULL, NULL, 'active', 1, '2025-11-23 19:31:48', '2026-02-09 19:34:33', '2026-02-09 19:34:33', NULL, NULL, NULL, 0, 0, 'pending', NULL, NULL, NULL, 0, NULL),
(5, NULL, 'hulahuhu152@gmail.com', '', '', '$2y$10$Q.ckFVyj/YwrKyO.QnSE6.Fb5trLsOv3i1VpvkLO6y4QHG90MnCmy', 'Hula', 'tPhuu6Y4dSWPEbJaoXSlGN4KrgH3', 'password', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-01-24 08:35:36', '2026-02-09 19:15:30', '2026-02-09 19:15:30', '0112330183', 'uploads/nid/nid_0112330183_front_1769222135_69742ff7bfdbc.jpg', 'uploads/nid/nid_0112330183_back_1769222135_69742ff7c2047.jpg', 0, 0, 'pending', NULL, NULL, NULL, 0, NULL),
(6, NULL, 'mdabu018717@gmail.com', NULL, NULL, '$2y$10$TFzPGLzdJjj8GQJ7QfEt/.MuYJ5Hh6x7YFiZ7k0PCXxQqHUbIpEtC', NULL, 'UdSQDppN44PuYiHDZE07csyPur82', 'password', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-01-24 08:42:18', '2026-02-09 19:58:27', '2026-02-09 19:58:27', '4222776218', 'uploads/nid/nid_4222776218_front_1769222537_69743189cb24f.png', 'uploads/nid/nid_4222776218_back_1769222537_69743189cc4b4.png', 0, 0, 'pending', NULL, NULL, NULL, 0, NULL);

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `tr_users_after_delete_audit` AFTER DELETE ON `users` FOR EACH ROW BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `created_at`)
  VALUES (OLD.id, 'DELETE', 'users', OLD.id,
          JSON_OBJECT('email', OLD.email, 'status', OLD.status, 'is_active', OLD.is_active),
          NULL,
          NOW());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_users_after_insert_audit` AFTER INSERT ON `users` FOR EACH ROW BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `created_at`)
  VALUES (NEW.id, 'INSERT', 'users', NEW.id, NULL,
          JSON_OBJECT('email', NEW.email, 'status', NEW.status, 'is_active', NEW.is_active),
          NOW());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tr_users_after_update_audit` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
  INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `created_at`)
  VALUES (NEW.id, 'UPDATE', 'users', NEW.id,
          JSON_OBJECT('email', OLD.email, 'status', OLD.status, 'is_active', OLD.is_active),
          JSON_OBJECT('email', NEW.email, 'status', NEW.status, 'is_active', NEW.is_active),
          NOW());
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_alert_settings`
--

CREATE TABLE `user_alert_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `allow_community_alerts` tinyint(1) DEFAULT 1,
  `alert_radius` int(11) DEFAULT 5000 COMMENT 'Radius in meters',
  `notify_push` tinyint(1) DEFAULT 1,
  `notify_sound` tinyint(1) DEFAULT 1,
  `notify_email` tinyint(1) DEFAULT 0,
  `notify_sms` tinyint(1) DEFAULT 0,
  `quiet_hours_start` time DEFAULT NULL,
  `quiet_hours_end` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_alert_settings`
--

INSERT INTO `user_alert_settings` (`id`, `user_id`, `allow_community_alerts`, `alert_radius`, `notify_push`, `notify_sound`, `notify_email`, `notify_sms`, `quiet_hours_start`, `quiet_hours_end`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-01-24 03:36:41', '2026-01-24 03:36:41'),
(2, 2, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-01-24 03:36:41', '2026-01-24 03:36:41'),
(3, 3, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-01-24 03:36:41', '2026-01-24 03:36:41'),
(4, 4, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-01-24 03:36:41', '2026-01-24 03:36:41'),
(5, 5, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-01-24 03:36:41', '2026-01-24 03:36:41'),
(6, 6, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-01-24 03:36:41', '2026-01-24 03:36:41');

-- --------------------------------------------------------

--
-- Table structure for table `user_area_ratings`
--

CREATE TABLE `user_area_ratings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `area_id` int(11) NOT NULL,
  `safety_rating` int(1) NOT NULL,
  `comments` text DEFAULT NULL,
  `factors` text DEFAULT NULL,
  `is_verified_resident` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_area_ratings`
--

INSERT INTO `user_area_ratings` (`id`, `user_id`, `area_id`, `safety_rating`, `comments`, `factors`, `is_verified_resident`, `created_at`, `updated_at`) VALUES
(1, 2, 6, 5, 'hello world', '{\"lighting\":\"excellent\",\"police_presence\":\"excellent\",\"traffic\":\"excellent\",\"public_transport\":\"excellent\",\"street_condition\":\"excellent\"}', 1, '2025-11-16 14:44:43', '2025-11-16 14:45:10'),
(2, 2, 3, 5, '', '{\"lighting\":\"excellent\",\"police_presence\":\"excellent\",\"traffic\":\"excellent\",\"public_transport\":\"excellent\",\"street_condition\":\"excellent\"}', 1, '2025-11-16 14:55:38', '2025-11-16 14:55:38');

-- --------------------------------------------------------

--
-- Table structure for table `user_preferences`
--

CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email_notifications` tinyint(1) DEFAULT 1,
  `sms_notifications` tinyint(1) DEFAULT 0,
  `push_notifications` tinyint(1) DEFAULT 1,
  `alert_radius_km` decimal(5,2) DEFAULT 1.00,
  `alert_types` text DEFAULT NULL,
  `profile_visibility` enum('public','private','friends_only') DEFAULT 'private',
  `location_sharing` tinyint(1) DEFAULT 0,
  `anonymous_reporting` tinyint(1) DEFAULT 1,
  `preferred_language` enum('en','bn') DEFAULT 'en',
  `timezone` varchar(50) DEFAULT 'Asia/Dhaka',
  `emergency_contacts` text DEFAULT NULL,
  `theme_preference` enum('light','dark','auto') DEFAULT 'auto',
  `accessibility_features` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_preferences`
--

INSERT INTO `user_preferences` (`id`, `user_id`, `email_notifications`, `sms_notifications`, `push_notifications`, `alert_radius_km`, `alert_types`, `profile_visibility`, `location_sharing`, `anonymous_reporting`, `preferred_language`, `timezone`, `emergency_contacts`, `theme_preference`, `accessibility_features`, `updated_at`) VALUES
(1, 1, 1, 0, 1, 1.00, NULL, 'private', 0, 1, 'en', 'Asia/Dhaka', NULL, 'auto', NULL, '2025-11-13 14:35:53');

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `device_type` enum('desktop','mobile','tablet') DEFAULT 'desktop',
  `login_time` datetime DEFAULT current_timestamp(),
  `last_activity` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `session_token`, `ip_address`, `user_agent`, `device_type`, `login_time`, `last_activity`, `is_active`) VALUES
(1, 1, 'ac1102709c0988f399f6d32d04e17b66504a3ca73766ab85f334a883954e5e4b', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-12 18:52:10', '2025-11-12 18:52:10', 1),
(2, 1, '9fc00468ec3b88a53707686f2808aa859718d591ff9371eb76a88382ec3e638c', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-12 20:59:22', '2025-11-12 20:59:22', 1),
(3, 1, '172e59f7ea61d877f1f3a9607b54378bcfca1aa41446ee5ee9d72e541fea58bc', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-13 13:13:32', '2025-11-13 13:13:32', 1),
(4, 1, 'f352730bcd02611abfe99f410969308d26e35abf45d250a52b4ad527b20f8952', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-13 15:38:02', '2025-11-13 15:38:02', 1),
(5, 2, '506e84f1dbe294dfca408c18c7c4c2227572079723f42f0bf08e21bb7edb569f', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-13 16:47:12', '2025-11-13 16:47:12', 1),
(6, 2, 'c49a4abe3efd910005ccd5dbad56496cb5f4447e7b8881e00edc044ecfdc7cc6', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-16 12:43:23', '2025-11-16 12:43:23', 1),
(7, 2, '9efbccf5c3a1936591001481ab085d5a1aabe5c182d6125641d9cd3bbd557ff3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-18 10:46:31', '2025-11-18 10:46:31', 1),
(8, 2, '139fdb708c3697108c07f05c7a19776bc8f80031078580be6d0079787f1a1d8e', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-18 10:46:31', '2025-11-18 10:46:31', 1),
(9, 2, 'dff131a00cbaffca0ca3096adef05413e5e14ad9a7b25411271a7924907fa170', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-18 11:06:53', '2025-11-18 11:06:53', 1),
(10, 2, '4e3a68f47cbfffc4e3e857b3e55e162f0d713b9d61860a5ef98a9c6085055c8a', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-18 13:40:36', '2025-11-18 13:40:36', 1),
(11, 2, 'f45c062ff0522e272902b1495fdbbb361ec9e81353b3ad4f242cc7fb7f3000be', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-18 19:57:38', '2025-11-18 19:57:38', 1),
(12, 1, '1cb747a6275b89e2c6a4cd7951b2e7029d0e7fc1d275d7bb59cda72ca062a3d4', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'desktop', '2025-11-18 20:01:40', '2025-11-18 20:01:40', 1),
(13, 2, 'b947f798c2b94e530b9cabb341c8f23b6adfb37fe91c0f852544d19f1843a589', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-18 20:20:33', '2025-11-18 20:20:33', 1),
(14, 1, '7451243b3bb9ba23b5f66ab6f0e21c6b8e335dd12317a0ffc9626b1fa4688155', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 11:32:59', '2025-11-19 11:32:59', 1),
(15, 1, '28de3be961f8c6f7deb8ead0228c5f3bfde771407a21045c6ca476b56a7b827b', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 11:50:20', '2025-11-19 11:50:20', 1),
(16, 1, '0ff5cbf44a5124aab87565f8e90e55371c1d4570dd05084e346dedb581c364f0', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 11:53:09', '2025-11-19 11:53:09', 1),
(17, 1, 'f0f32dedd64a12a3cf34cf9f7e8b28cedda10b39ee9c8e59dfabae914d7eab3d', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 11:55:05', '2025-11-19 11:55:05', 1),
(18, 1, '37b3b26a6a65e3ef98f949ddeefe7e13f6f51e47d4b81c05e9150ac65fe74ba5', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 11:55:54', '2025-11-19 11:55:54', 1),
(19, 1, 'e49c6f6c005cd5e3477c88749ca1798ca1319078ab33cd2ff0e6e482f199faaa', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 11:56:17', '2025-11-19 11:56:17', 1),
(20, 1, 'af8dd79fa83070d397aa89f18c039ddde8ab7a7cb3561d977b61b0df854737db', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 18:52:55', '2025-11-19 18:52:55', 1),
(21, 1, '7bd5e817558f78259061d68a0254e941fceaf6cc6bd7ae7239e771daf9cbae18', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 19:00:05', '2025-11-19 19:00:05', 1),
(22, 1, '909108288625719916a53b59886046484dd0a7584b43ef0bca685582731537b7', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-19 19:05:43', '2025-11-19 19:05:43', 1),
(23, 1, '7b07f5c43f9c83fd2c10919facdd74f42f6117e35bb32c9da6d33c6fcfbb9739', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-20 12:19:12', '2025-11-20 12:19:12', 1),
(24, 1, 'a2d0ca276d29802831e9d476b97658c7e825a1fbed05f0a6bebf4ed8e831c53b', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-22 13:23:28', '2025-11-22 13:23:28', 1),
(25, 1, 'a9b909c590b47ba0d379761df1a93d0b17c3d2ef0815dbd5433fe9c10390638c', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-22 19:21:21', '2025-11-22 19:21:21', 1),
(26, 1, '94f8143ae7e528ada0ee5192440c73bee4bea4315db1d095b3c70178452497bf', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-22 21:46:15', '2025-11-22 21:46:15', 1),
(27, 1, '4d54a780d1ffb0c742686c005759ed4448b01f92973fc71b106b421edb095161', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-23 10:47:26', '2025-11-23 10:47:26', 1),
(28, 1, '1428401a09f08bf5a0b25d941bb7bc48296402713e8ca5355ef72c54c7b4983c', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-23 18:16:46', '2025-11-23 18:16:46', 1),
(29, 1, '50814b4b70d67eb257a03c737e3b78e8f5e97df2a05b2bec449a143cd4816cb6', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-23 18:19:56', '2025-11-23 18:19:56', 1),
(30, 1, '8d1a146665b1d5c8df97f5af2e81ddf55c9095d205c0fa828328f71a694c197e', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-23 22:55:18', '2025-11-23 22:55:18', 1),
(31, 1, '6728252017f27d603311dbd217cb3545da53e2d0badd778027dd94dfde46b2d8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-24 04:47:17', '2025-11-24 04:47:17', 1),
(32, 1, '34b25226d5b2034e110836424c01b8c3a5a14a488a5ef8c07771ee73d867e5f8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-24 07:58:14', '2025-11-24 07:58:14', 1),
(33, 1, '8e21bede711c5d7b03e6970bbf40ff16457396dcc2684edac760f427cd96ff60', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-26 21:58:24', '2025-11-26 21:58:24', 1),
(34, 1, 'ad8eeeeaa3f6e07cdfa9c756408cae6a6f283d5053cc794461f8fdc1fcb2ea1d', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'desktop', '2025-12-06 09:23:03', '2025-12-06 09:23:03', 1),
(35, 1, 'ca94a2f5b9bd7a9af660527c7cb26a83630293262d8a8e8dc7fe265e35896e21', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'desktop', '2025-12-08 06:34:25', '2025-12-08 06:34:25', 1),
(36, 1, '993edf1270d5e64a498706f34d8f961b4671f7881781e134444c4601800c751f', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'desktop', '2025-12-08 12:47:23', '2025-12-08 12:47:23', 1),
(37, 1, '07a928fe4d97c4c9ab3d9f3d5077fdecb28d706dd91934932fb6e3be42797272', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'desktop', '2025-12-15 05:24:41', '2025-12-15 05:24:41', 1),
(38, 1, '71d716085fc33e88cdd4772641ec65a862093281621fb746fd8918ef69460b83', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'desktop', '2025-12-30 08:14:15', '2025-12-30 08:14:15', 1),
(39, 1, '3208f3dd0850e2d703772f7bf462c757d667885d7ae7ae5daf9036d31d6d7b18', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-17 17:40:08', '2026-01-17 17:40:08', 1),
(40, 5, 'f0c9302ed639cb3e87745d75fa31186baa87b246da876fce31499863717348c8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-24 08:35:45', '2026-01-24 08:35:45', 1),
(41, 6, '14ca9569afab4b8521da9e886c455c0a0a0c7772c8570bbb7fc164e8d8855d28', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', 'desktop', '2026-01-24 08:42:26', '2026-01-24 08:42:26', 1),
(42, 5, '6a799dd6e7a55d727159ceccab7b39da86b45abe0b874dcf9881f8e1b00161e7', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-24 09:12:55', '2026-01-24 09:12:55', 1),
(43, 6, '03cc9b4f15bd6185bbd183d8073425160fdb0c607d53f5c0c674bfb0462fb081', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', 'desktop', '2026-01-24 09:13:12', '2026-01-24 09:13:12', 1),
(44, 5, 'd1bf58fb1796ce67653b7127d8127c89f19e274ddb41e8f4f91d62ea3531c31b', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-24 09:58:34', '2026-01-24 09:58:34', 1),
(45, 6, 'ea18e0c8c2159426cc77c5f13cfdf3d45a47f355e9bf8dfd451f33b7c946e9b0', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', 'desktop', '2026-01-24 09:58:58', '2026-01-24 09:58:58', 1),
(46, 5, '33fa551e4dda3e606c528cad544de8d07d52ef044f67569b76b74bb02a44c8ea', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-24 11:58:05', '2026-01-24 11:58:05', 1),
(47, 5, 'f2b03dd8b07d0234bc407b8194c340d96b77108d91b9d986c9bb9b71b4ca03ae', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-24 13:45:11', '2026-01-24 13:45:11', 1),
(48, 5, 'd566dc5d489c9d4ba8e726ffc2378b02bd938c02eb516bfc1ba1e0810628d184', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-26 03:58:48', '2026-01-26 03:58:48', 1),
(49, 5, 'b4efe9e68c26aae283041aead5e099784ea55c7e6ce6b901d45960afdfbf220d', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-01-26 12:27:58', '2026-01-26 12:27:58', 1),
(50, 5, 'b4499149c1d3e971f87ea9934e4d2374faa167940ce3acdc7ef6655e89920bf4', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'desktop', '2026-02-09 19:15:30', '2026-02-09 19:15:30', 1),
(51, 6, 'ae54fd12663f790533f831701f2efbd35d467bfea5a253c2c87c00a7efaebc0a', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', 'desktop', '2026-02-09 19:58:27', '2026-02-09 19:58:27', 1);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_incident_zones_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_incident_zones_summary` (
`id` int(11)
,`zone_name` varchar(255)
,`area_name` varchar(255)
,`latitude` decimal(10,8)
,`longitude` decimal(11,8)
,`report_count` int(11)
,`zone_status` enum('safe','moderate','unsafe')
,`last_incident_date` datetime
,`first_incident_date` datetime
,`total_reports` bigint(21)
,`critical_count` bigint(21)
,`high_count` bigint(21)
,`resolved_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Table structure for table `walk_sessions`
--

CREATE TABLE `walk_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `start_time` datetime DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `status` enum('active','completed','emergency') DEFAULT 'active',
  `destination` varchar(255) DEFAULT NULL,
  `estimated_duration_minutes` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `walk_sessions`
--

INSERT INTO `walk_sessions` (`id`, `user_id`, `session_token`, `start_time`, `end_time`, `status`, `destination`, `estimated_duration_minutes`) VALUES
(2, 1, 'e73051fe72f264daf9f4245a2b2b0a937e21115ed1a53fa1ede22ac8dc9ad61b', '2025-11-23 23:06:16', '2025-11-23 23:07:40', 'completed', '', 0),
(3, 6, '58bca15d5ef35c5b95de6688680809b14efaea0ad3ec935086d31b886cadeed6', '2026-01-24 09:16:15', NULL, 'emergency', '', 0),
(4, 6, '6f6bdb204ab747efd886926ce48a995c1981af3afdcb87326f7758b1a048b84b', '2026-01-24 09:59:24', NULL, 'emergency', '', 0),
(5, 6, '7862a4cfb79b3ff2508fcc6a19cc4626ddf15d8e646e56619c4ad5f9f7cb4472', '2026-01-24 10:02:40', NULL, 'emergency', '', 0),
(6, 6, '8da46f81e51b4a131eceb3725f07c578d4d5582b9fc111fb24bad139c4df3d1a', '2026-01-24 10:17:36', NULL, 'emergency', '', 0),
(7, 5, '811ff24d743e03d6ed2e50de0b6663e13fe6440d78c8e25e5bf3649c56d74965', '2026-01-24 10:22:43', NULL, 'emergency', '', 0),
(8, 4, '21e6b60b84c43ad0737dc9c5dcc7c4813e7a2d45891eb73f33c755f728a969d7', '2026-01-24 12:55:07', NULL, 'emergency', '', 0),
(9, 4, '1b0b732fda336709d2fecdadaff5bff0318bb0bc14ccf18918b66ccb87c6b656', '2026-01-24 14:41:23', NULL, 'emergency', '', 0),
(10, 4, '3a92490418120d1654e23696e29c78a9793127d0095a0fcbc755463651b92066', '2026-01-24 14:52:46', NULL, 'emergency', '', 0),
(11, 6, '0ee137c065df04c36495fa1cf26569667c72c92b647f5a3021b1747926d79fd4', '2026-02-09 19:58:50', NULL, 'emergency', '', 0),
(12, 4, '79a8c36da85e4b2b2ab4c43cd3405f77d0155bb17ff70acf195cccc52c154bbb', '2026-02-09 20:01:20', NULL, 'emergency', '', 0),
(13, 4, 'd8fa38a4d19e34392f728ce0a2abc8fc39e9a9cf0d473d40aabce2a82d4300a9', '2026-02-09 20:12:59', NULL, 'emergency', '', 0),
(14, 6, '193544674996d5844aeb3fdd8a7c836edf4ad4511e123da72e1d91731b538db4', '2026-02-09 20:27:10', NULL, 'active', '', 0);

-- --------------------------------------------------------

--
-- Structure for view `active_users_with_location`
--
DROP TABLE IF EXISTS `active_users_with_location`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `active_users_with_location`  AS SELECT `u`.`id` AS `id`, `u`.`email` AS `email`, `u`.`display_name` AS `display_name`, `u`.`current_latitude` AS `current_latitude`, `u`.`current_longitude` AS `current_longitude`, `u`.`is_online` AS `is_online`, `u`.`last_seen` AS `last_seen`, `uas`.`allow_community_alerts` AS `allow_community_alerts`, `uas`.`alert_radius` AS `alert_radius`, `uas`.`notify_push` AS `notify_push`, `uas`.`notify_sound` AS `notify_sound`, `uas`.`notify_email` AS `notify_email` FROM (`users` `u` left join `user_alert_settings` `uas` on(`u`.`id` = `uas`.`user_id`)) WHERE `u`.`is_online` = 1 AND `u`.`current_latitude` is not null AND `u`.`current_longitude` is not null AND (`uas`.`allow_community_alerts` is null OR `uas`.`allow_community_alerts` = 1) ;

-- --------------------------------------------------------

--
-- Structure for view `v_incident_zones_summary`
--
DROP TABLE IF EXISTS `v_incident_zones_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_incident_zones_summary`  AS SELECT `iz`.`id` AS `id`, `iz`.`zone_name` AS `zone_name`, `iz`.`area_name` AS `area_name`, `iz`.`latitude` AS `latitude`, `iz`.`longitude` AS `longitude`, `iz`.`report_count` AS `report_count`, `iz`.`zone_status` AS `zone_status`, `iz`.`last_incident_date` AS `last_incident_date`, `iz`.`first_incident_date` AS `first_incident_date`, count(`ir`.`id`) AS `total_reports`, count(case when `ir`.`severity` = 'critical' then 1 end) AS `critical_count`, count(case when `ir`.`severity` = 'high' then 1 end) AS `high_count`, count(case when `ir`.`status` = 'resolved' then 1 end) AS `resolved_count` FROM (`incident_zones` `iz` left join `incident_reports` `ir` on(`ir`.`location_name` collate utf8mb4_unicode_ci = `iz`.`zone_name` collate utf8mb4_unicode_ci and abs(`ir`.`latitude` - `iz`.`latitude`) < 0.01 and abs(`ir`.`longitude` - `iz`.`longitude`) < 0.01 and `ir`.`status` <> 'disputed')) GROUP BY `iz`.`id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `source_user_id` (`source_user_id`),
  ADD KEY `related_report_id` (`related_report_id`),
  ADD KEY `type` (`type`),
  ADD KEY `severity` (`severity`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `start_time` (`start_time`),
  ADD KEY `latitude` (`latitude`,`longitude`),
  ADD KEY `source_type` (`source_type`),
  ADD KEY `location_name` (`location_name`(100));

--
-- Indexes for table `alert_responses`
--
ALTER TABLE `alert_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alert_id` (`alert_id`),
  ADD KEY `idx_responder_id` (`responder_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_response_time` (`response_time`),
  ADD KEY `idx_alert_responses_alert_status` (`alert_id`,`status`);

--
-- Indexes for table `area_safety_scores`
--
ALTER TABLE `area_safety_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `area_unique` (`district_id`,`upazila_id`,`union_name`,`ward_number`),
  ADD KEY `idx_area_division` (`division_id`),
  ADD KEY `idx_area_safety_score` (`safety_score`),
  ADD KEY `area_safety_scores_ibfk_3` (`upazila_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `action` (`action`),
  ADD KEY `table_name` (`table_name`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `certificate_number` (`certificate_number`),
  ADD UNIQUE KEY `verification_code` (`verification_code`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `enrollment_id` (`enrollment_id`);

--
-- Indexes for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_course` (`user_id`,`course_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `disputes`
--
ALTER TABLE `disputes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `report_id` (`report_id`),
  ADD KEY `status` (`status`),
  ADD KEY `reason` (`reason`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `districts`
--
ALTER TABLE `districts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `districts_name_unique` (`division_id`,`name`),
  ADD KEY `districts_division_idx` (`division_id`);

--
-- Indexes for table `divisions`
--
ALTER TABLE `divisions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `divisions_name_unique` (`name`);

--
-- Indexes for table `emergency_contacts`
--
ALTER TABLE `emergency_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `emergency_services`
--
ALTER TABLE `emergency_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `type` (`type`),
  ADD KEY `has_womens_cell` (`has_womens_cell`),
  ADD SPATIAL KEY `location` (`location`);

--
-- Indexes for table `group_alerts`
--
ALTER TABLE `group_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `posted_by` (`posted_by`),
  ADD KEY `alert_type` (`alert_type`),
  ADD KEY `status` (`status`),
  ADD KEY `severity` (`severity`);

--
-- Indexes for table `group_alert_acknowledgments`
--
ALTER TABLE `group_alert_acknowledgments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `alert_user` (`alert_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `group_media`
--
ALTER TABLE `group_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `alert_id` (`alert_id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `file_type` (`file_type`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `group_members`
--
ALTER TABLE `group_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `group_user` (`group_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indexes for table `helpline_numbers`
--
ALTER TABLE `helpline_numbers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category` (`category`),
  ADD KEY `priority` (`priority`);

--
-- Indexes for table `incident_reports`
--
ALTER TABLE `incident_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `category` (`category`),
  ADD KEY `status` (`status`),
  ADD KEY `severity` (`severity`),
  ADD KEY `incident_date` (`incident_date`),
  ADD KEY `reported_date` (`reported_date`),
  ADD KEY `location_name` (`location_name`(100)),
  ADD KEY `latitude` (`latitude`,`longitude`),
  ADD KEY `idx_status_resolved` (`status`,`resolved_at`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_date_status` (`incident_date`,`status`);

--
-- Indexes for table `incident_zones`
--
ALTER TABLE `incident_zones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_zone` (`zone_name`,`area_name`),
  ADD SPATIAL KEY `idx_location` (`location`),
  ADD KEY `idx_status` (`zone_status`),
  ADD KEY `idx_report_count` (`report_count`);

--
-- Indexes for table `leaf_nodes`
--
ALTER TABLE `leaf_nodes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_safety_score` (`safety_score`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD SPATIAL KEY `idx_location` (`location`),
  ADD KEY `idx_category_status` (`category`,`status`),
  ADD KEY `idx_safety_status` (`safety_score`,`status`);

--
-- Indexes for table `legal_aid_providers`
--
ALTER TABLE `legal_aid_providers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `city` (`city`,`district`),
  ADD KEY `is_verified` (`is_verified`),
  ADD KEY `status` (`status`),
  ADD KEY `rating` (`rating`);

--
-- Indexes for table `legal_consultations`
--
ALTER TABLE `legal_consultations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `provider_id` (`provider_id`),
  ADD KEY `report_id` (`report_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `legal_documents`
--
ALTER TABLE `legal_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_type` (`document_type`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `medical_support_providers`
--
ALTER TABLE `medical_support_providers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider_type` (`provider_type`),
  ADD KEY `district` (`district`),
  ADD KEY `city` (`city`),
  ADD KEY `is_verified` (`is_verified`),
  ADD KEY `status` (`status`),
  ADD KEY `rating` (`rating`),
  ADD KEY `fee_structure` (`fee_structure`);

--
-- Indexes for table `neighborhood_groups`
--
ALTER TABLE `neighborhood_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_neighborhood_location` (`division_id`,`district_id`,`upazila_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `status` (`status`),
  ADD KEY `is_verified` (`is_verified`),
  ADD KEY `neighborhood_groups_ibfk_3` (`district_id`),
  ADD KEY `neighborhood_groups_ibfk_4` (`upazila_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `type` (`type`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `panic_alerts`
--
ALTER TABLE `panic_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `triggered_at` (`triggered_at`);

--
-- Indexes for table `panic_notifications`
--
ALTER TABLE `panic_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `panic_alert_id` (`panic_alert_id`),
  ADD KEY `contact_id` (`contact_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `safety_courses`
--
ALTER TABLE `safety_courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category` (`category`),
  ADD KEY `status` (`status`),
  ADD KEY `language` (`language`),
  ADD KEY `is_premium` (`is_premium`);

--
-- Indexes for table `safety_resources`
--
ALTER TABLE `safety_resources`
  ADD PRIMARY KEY (`id`),
  ADD KEY `verified_by` (`verified_by`),
  ADD KEY `category` (`category`),
  ADD KEY `is_verified` (`is_verified`),
  ADD KEY `status` (`status`),
  ADD KEY `city` (`city`,`state`),
  ADD KEY `is_24_7` (`is_24_7`);

--
-- Indexes for table `safe_spaces`
--
ALTER TABLE `safe_spaces`
  ADD PRIMARY KEY (`id`),
  ADD KEY `verified_by` (`verified_by`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `category` (`category`),
  ADD KEY `is_verified` (`is_verified`),
  ADD KEY `status` (`status`),
  ADD KEY `latitude` (`latitude`,`longitude`),
  ADD KEY `city` (`city`,`state`),
  ADD KEY `average_rating` (`average_rating`);

--
-- Indexes for table `safe_zones`
--
ALTER TABLE `safe_zones`
  ADD PRIMARY KEY (`id`),
  ADD SPATIAL KEY `idx_boundary` (`boundary`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `support_referrals`
--
ALTER TABLE `support_referrals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `provider_id` (`provider_id`),
  ADD KEY `report_id` (`report_id`),
  ADD KEY `status` (`status`),
  ADD KEY `referral_type` (`referral_type`),
  ADD KEY `priority` (`priority`);

--
-- Indexes for table `system_statistics`
--
ALTER TABLE `system_statistics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date` (`date`),
  ADD KEY `date_2` (`date`);

--
-- Indexes for table `upazilas`
--
ALTER TABLE `upazilas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `upazilas_name_unique` (`district_id`,`name`),
  ADD KEY `upazilas_district_idx` (`district_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `nid_number` (`nid_number`),
  ADD KEY `firebase_uid` (`firebase_uid`),
  ADD KEY `verification_token` (`verification_token`),
  ADD KEY `password_reset_token` (`password_reset_token`),
  ADD KEY `verification_status` (`verification_status`),
  ADD KEY `status` (`status`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `idx_is_admin` (`is_admin`),
  ADD KEY `idx_is_online` (`is_online`),
  ADD KEY `idx_last_seen` (`last_seen`);

--
-- Indexes for table `user_alert_settings`
--
ALTER TABLE `user_alert_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_allow_alerts` (`allow_community_alerts`);

--
-- Indexes for table `user_area_ratings`
--
ALTER TABLE `user_area_ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_area` (`user_id`,`area_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `area_id` (`area_id`),
  ADD KEY `safety_rating` (`safety_rating`);

--
-- Indexes for table `user_preferences`
--
ALTER TABLE `user_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `user_id_2` (`user_id`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `walk_sessions`
--
ALTER TABLE `walk_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `alert_responses`
--
ALTER TABLE `alert_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `area_safety_scores`
--
ALTER TABLE `area_safety_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=166;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `disputes`
--
ALTER TABLE `disputes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `districts`
--
ALTER TABLE `districts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `divisions`
--
ALTER TABLE `divisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `emergency_contacts`
--
ALTER TABLE `emergency_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `emergency_services`
--
ALTER TABLE `emergency_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `group_alerts`
--
ALTER TABLE `group_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `group_alert_acknowledgments`
--
ALTER TABLE `group_alert_acknowledgments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_media`
--
ALTER TABLE `group_media`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `group_members`
--
ALTER TABLE `group_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `helpline_numbers`
--
ALTER TABLE `helpline_numbers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `incident_reports`
--
ALTER TABLE `incident_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=117;

--
-- AUTO_INCREMENT for table `incident_zones`
--
ALTER TABLE `incident_zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `leaf_nodes`
--
ALTER TABLE `leaf_nodes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `legal_aid_providers`
--
ALTER TABLE `legal_aid_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `legal_consultations`
--
ALTER TABLE `legal_consultations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `legal_documents`
--
ALTER TABLE `legal_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `medical_support_providers`
--
ALTER TABLE `medical_support_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `neighborhood_groups`
--
ALTER TABLE `neighborhood_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `panic_alerts`
--
ALTER TABLE `panic_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `panic_notifications`
--
ALTER TABLE `panic_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `safety_courses`
--
ALTER TABLE `safety_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `safety_resources`
--
ALTER TABLE `safety_resources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `safe_spaces`
--
ALTER TABLE `safe_spaces`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `safe_zones`
--
ALTER TABLE `safe_zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `support_referrals`
--
ALTER TABLE `support_referrals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_statistics`
--
ALTER TABLE `system_statistics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `upazilas`
--
ALTER TABLE `upazilas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `user_alert_settings`
--
ALTER TABLE `user_alert_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `user_area_ratings`
--
ALTER TABLE `user_area_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_preferences`
--
ALTER TABLE `user_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `walk_sessions`
--
ALTER TABLE `walk_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`source_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`related_report_id`) REFERENCES `incident_reports` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `alert_responses`
--
ALTER TABLE `alert_responses`
  ADD CONSTRAINT `alert_responses_ibfk_1` FOREIGN KEY (`alert_id`) REFERENCES `panic_alerts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `alert_responses_ibfk_2` FOREIGN KEY (`responder_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `area_safety_scores`
--
ALTER TABLE `area_safety_scores`
  ADD CONSTRAINT `area_safety_scores_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`),
  ADD CONSTRAINT `area_safety_scores_ibfk_2` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`),
  ADD CONSTRAINT `area_safety_scores_ibfk_3` FOREIGN KEY (`upazila_id`) REFERENCES `upazilas` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `safety_courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `certificates_ibfk_3` FOREIGN KEY (`enrollment_id`) REFERENCES `course_enrollments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  ADD CONSTRAINT `course_enrollments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `safety_courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `disputes`
--
ALTER TABLE `disputes`
  ADD CONSTRAINT `disputes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_2` FOREIGN KEY (`report_id`) REFERENCES `incident_reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `districts`
--
ALTER TABLE `districts`
  ADD CONSTRAINT `districts_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`);

--
-- Constraints for table `emergency_contacts`
--
ALTER TABLE `emergency_contacts`
  ADD CONSTRAINT `emergency_contacts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_alerts`
--
ALTER TABLE `group_alerts`
  ADD CONSTRAINT `group_alerts_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `neighborhood_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_alerts_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_alert_acknowledgments`
--
ALTER TABLE `group_alert_acknowledgments`
  ADD CONSTRAINT `group_alert_acknowledgments_ibfk_1` FOREIGN KEY (`alert_id`) REFERENCES `group_alerts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_alert_acknowledgments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_media`
--
ALTER TABLE `group_media`
  ADD CONSTRAINT `group_media_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `neighborhood_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_media_ibfk_2` FOREIGN KEY (`alert_id`) REFERENCES `group_alerts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `group_media_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_members`
--
ALTER TABLE `group_members`
  ADD CONSTRAINT `group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `neighborhood_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `incident_reports`
--
ALTER TABLE `incident_reports`
  ADD CONSTRAINT `incident_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `incident_reports_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `legal_consultations`
--
ALTER TABLE `legal_consultations`
  ADD CONSTRAINT `legal_consultations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `legal_consultations_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `legal_aid_providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `legal_consultations_ibfk_3` FOREIGN KEY (`report_id`) REFERENCES `incident_reports` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `neighborhood_groups`
--
ALTER TABLE `neighborhood_groups`
  ADD CONSTRAINT `neighborhood_groups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `neighborhood_groups_ibfk_2` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`),
  ADD CONSTRAINT `neighborhood_groups_ibfk_3` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`),
  ADD CONSTRAINT `neighborhood_groups_ibfk_4` FOREIGN KEY (`upazila_id`) REFERENCES `upazilas` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `panic_alerts`
--
ALTER TABLE `panic_alerts`
  ADD CONSTRAINT `panic_alerts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `panic_notifications`
--
ALTER TABLE `panic_notifications`
  ADD CONSTRAINT `panic_notifications_ibfk_1` FOREIGN KEY (`panic_alert_id`) REFERENCES `panic_alerts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `panic_notifications_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `emergency_contacts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `safety_resources`
--
ALTER TABLE `safety_resources`
  ADD CONSTRAINT `safety_resources_ibfk_1` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `safe_spaces`
--
ALTER TABLE `safe_spaces`
  ADD CONSTRAINT `safe_spaces_ibfk_1` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `safe_spaces_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `safe_zones`
--
ALTER TABLE `safe_zones`
  ADD CONSTRAINT `safe_zones_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `support_referrals`
--
ALTER TABLE `support_referrals`
  ADD CONSTRAINT `support_referrals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `support_referrals_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `medical_support_providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `upazilas`
--
ALTER TABLE `upazilas`
  ADD CONSTRAINT `upazilas_ibfk_1` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`);

--
-- Constraints for table `user_alert_settings`
--
ALTER TABLE `user_alert_settings`
  ADD CONSTRAINT `user_alert_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_area_ratings`
--
ALTER TABLE `user_area_ratings`
  ADD CONSTRAINT `user_area_ratings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_area_ratings_ibfk_2` FOREIGN KEY (`area_id`) REFERENCES `area_safety_scores` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_preferences`
--
ALTER TABLE `user_preferences`
  ADD CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
