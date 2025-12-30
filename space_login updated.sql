-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 23, 2025 at 06:59 PM
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
(1, 'Dhanmondi', '27', 'Dhanmondi', 1, 1, 1, 3.73, 4.20, 4.48, 0.00, 5.00, 6.00, 29, 13, 12, 161.65, '2025-11-20 18:23:31', '2025-11-16 14:35:50'),
(2, 'Gulshan', '19', 'Gulshan', 1, 1, 2, 3.45, 5.00, 2.00, 0.00, 5.00, 7.00, 25, 5, 9, 82.60, '2025-11-16 14:55:20', '2025-11-16 14:35:50'),
(3, 'Mirpur', '10', 'Mirpur', 1, 1, 3, 4.68, 6.20, 2.63, 0.00, 10.00, 6.67, 19, 5, 10, 85.68, '2025-11-16 14:55:38', '2025-11-16 14:35:50'),
(4, 'Uttara', '1', 'Uttara', 1, 1, 4, 3.05, 4.60, 2.22, 0.00, 5.00, 3.67, 27, 6, 19, 132.58, '2025-11-16 14:55:20', '2025-11-16 14:35:50'),
(5, 'Banani', '18', 'Banani', 1, 1, 5, 3.45, 5.00, 2.00, 0.00, 5.00, 7.00, 25, 5, 9, 82.60, '2025-11-16 14:55:06', '2025-11-16 14:35:50'),
(6, 'Wari', '15', 'Wari', 1, 1, 6, 9.38, 10.00, 7.50, 10.00, 10.00, 10.00, 0, 0, 0, 0.00, '2025-11-22 21:02:59', '2025-11-16 14:35:50'),
(7, 'Motijheel', '12', 'Motijheel', 1, 1, 7, 8.63, 10.00, 7.50, 10.00, 5.00, 10.00, 0, 0, 0, 0.00, '2025-11-16 14:55:20', '2025-11-16 14:35:50');

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
(98, 1, 'sos_alert', 'walk_sessions', 2, NULL, '{\"session_token\":\"e73051fe72f264daf9f4245a2b2b0a937e21115ed1a53fa1ede22ac8dc9ad61b\",\"status\":\"emergency\",\"contacts_notified\":0}', NULL, NULL, NULL, '2025-11-23 23:07:28');

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
(1, 2, 5, 0.00, 'enrolled', '2025-11-16 15:13:17', NULL, 0, NULL, NULL, NULL, '2025-11-16 15:13:17');

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
(10, 3, 1, 'suspicious_activity', 'Unusual Activity Near Mirpur Stadium', 'Reports of unusual gathering near Mirpur Stadium. Authorities have been notified. Please avoid the area if possible.', 'Mirpur Stadium Area', 'medium', 0, NULL, 0, 0, 'active', '2025-11-18 14:14:09', '2025-11-16 14:14:09');

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
(30, 21, 4, 'member', '2025-11-23 19:36:46', 'active', 0);

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
(114, 1, 'Test Report - Gulshan Area #2', 'Second test incident report for Gulshan area. Zone should remain GREEN (safe - 0-2 reports).', 'cyberbullying', 'low', 'under_review', 'Gulshan', 23.79500000, 90.41470000, 'Gulshan, Dhaka, Bangladesh', '2025-11-17 19:59:26', '2025-11-17 19:59:26', '2025-11-23 19:45:37', NULL, 0, 1, NULL, 0, NULL, NULL);

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
(9, 'Gulshan', 'Gulshan, Dhaka, Bangladesh', 23.79470000, 90.41440000, 0xe61000000101000000ad69de718a9a5640ec51b81e85cb3740, 2, 'safe', '2025-11-17 19:59:26', '2025-11-15 19:59:26', '2025-11-22 13:59:26', '2025-11-22 13:59:26');

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
(8, 'Human Rights Violation Report Format', 'form', 'Human Rights', 'Standard format for reporting human rights violations to relevant authorities and organizations.', NULL, 'https://example.com/docs/hr-violation-report.pdf', 'both', 0, 0, NULL, 'active', '2025-11-16 13:34:24');

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
(1, 'Dhanmondi Community Watch', 'Active community safety group for Dhanmondi area residents. We share safety alerts and work together to keep our neighborhood safe.', 'Dhanmondi', '27', 'Dhanmondi', 1, 1, 1, 1, 2, 2, 1, NULL, 'active', 'public', '1. Be respectful to all members\n2. Only post verified information\n3. No personal attacks\n4. Report false information immediately', '2025-11-16 13:52:50', '2025-11-16 14:03:24'),
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
(9, 4, 'Joined Community Group', 'You have successfully joined \"Mirpur 10\"', 'system', 'group_detail.php?id=21', NULL, 0, 0, 0, 0, 0, '2025-11-23 19:36:46', NULL, NULL);

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
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `panic_alerts`
--

INSERT INTO `panic_alerts` (`id`, `user_id`, `trigger_method`, `location_name`, `latitude`, `longitude`, `message`, `emergency_contacts_notified`, `police_notified`, `ambulance_notified`, `fire_service_notified`, `status`, `response_time_seconds`, `triggered_at`, `resolved_at`) VALUES
(1, 1, 'app_button', '23.814613316748986, 90.38611845264977', 23.81461332, 90.38611845, '', 0, 1, 0, 0, 'active', NULL, '2025-11-23 23:43:36', NULL);

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
(3, 'Know Your Legal Rights', 'Comprehensive guide to legal rights in Bangladesh, including women\'s rights, workplace rights, and how to seek legal help.', 'legal_rights', 'women,general,professionals', 90, 'mixed', NULL, NULL, 0, 'Advocate Roksana Begum', 'bn', 180, 140, 4.80, 120, 'active', '2025-11-16 15:12:15', '2025-11-16 15:12:15'),
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
  `verification_status` enum('pending','under_review','verified','rejected') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `phone`, `bio`, `password`, `display_name`, `firebase_uid`, `provider`, `email_verified`, `is_admin`, `verification_token`, `password_reset_token`, `reset_token_expires`, `status`, `is_active`, `created_at`, `last_login`, `updated_at`, `nid_number`, `nid_front_photo`, `nid_back_photo`, `face_verified`, `nid_verified`, `verification_status`) VALUES
(1, NULL, 'mdabusayumanik123@gmail.com', '+8801871745957', 'I am a Student', '$2y$10$Fg97eJDVI9xJiNdV3Js5POtKt4BMooAPk.s5eTGmzE.Rv0LaEEe4u', 'Sayum', 'DYdgxZf7gpXXNRWEaquyoTuqedh2', 'password', 0, 0, NULL, NULL, NULL, 'active', 1, '2025-11-12 18:52:03', '2025-11-23 22:55:18', '2025-11-23 22:55:18', '4222776215', 'uploads/nid/nid_4222776215_front_1762951923_691482f325b0d.jpg', 'uploads/nid/nid_4222776215_back_1762951923_691482f326b51.jpg', 0, 0, 'pending'),
(2, NULL, 'manik2330217@bscse.uiu.ac.bd', '+8801871745958', '', '$2y$10$tMgm7ALjgLHzXrvHVmga1e/24EmigJr.IJDsZnXl0z.ar/KSB26zK', '', 'ZOYOmrnoxwX0XnhSmNiA87wSn0l1', 'password', 0, 0, NULL, NULL, NULL, 'active', 1, '2025-11-13 16:47:05', '2025-11-18 20:20:33', '2025-11-18 20:20:33', '4222776225', 'uploads/nid/nid_4222776225_front_1763030825_6915b7290cac7.jpeg', 'uploads/nid/nid_4222776225_back_1763030825_6915b7290e8b2.jpeg', 0, 0, 'pending'),
(3, NULL, 'admin@test.com', NULL, NULL, NULL, 'admin', NULL, 'local', 1, 0, NULL, NULL, NULL, 'active', 1, '2025-11-23 18:23:13', '2025-11-23 18:23:13', '2025-11-23 18:23:13', NULL, NULL, NULL, 0, 0, 'pending'),
(4, NULL, 'admin@safespace.com', NULL, NULL, '$2y$10$lHSheUwrpDNu76L3SxxxhOlW2weHx9fZWo47BT3BV3ibmySaORkkG', 'System Administrator', NULL, 'local', 1, 1, NULL, NULL, NULL, 'active', 1, '2025-11-23 19:31:48', '2025-11-23 19:33:53', '2025-11-23 19:33:53', NULL, NULL, NULL, 0, 0, 'pending');

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
(30, 1, '8d1a146665b1d5c8df97f5af2e81ddf55c9095d205c0fa828328f71a694c197e', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0', 'desktop', '2025-11-23 22:55:18', '2025-11-23 22:55:18', 1);

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
(2, 1, 'e73051fe72f264daf9f4245a2b2b0a937e21115ed1a53fa1ede22ac8dc9ad61b', '2025-11-23 23:06:16', '2025-11-23 23:07:40', 'completed', '', 0);

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
  ADD KEY `idx_is_admin` (`is_admin`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_alerts`
--
ALTER TABLE `group_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `group_alert_acknowledgments`
--
ALTER TABLE `group_alert_acknowledgments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_media`
--
ALTER TABLE `group_media`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_members`
--
ALTER TABLE `group_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `incident_reports`
--
ALTER TABLE `incident_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `incident_zones`
--
ALTER TABLE `incident_zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `panic_alerts`
--
ALTER TABLE `panic_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `panic_notifications`
--
ALTER TABLE `panic_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `safe_zones`
--
ALTER TABLE `safe_zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `walk_sessions`
--
ALTER TABLE `walk_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
