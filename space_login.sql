-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 19, 2026 at 01:33 AM
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
CREATE DEFINER=`root`@`localhost` PROCEDURE `calculate_user_safety_score` (IN `p_user_id` INT)   BEGIN
  WITH report_stats AS (
    SELECT
      COUNT(*)                                        AS total_reports,
      SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) AS resolved_count,
      COUNT(DISTINCT category)                        AS category_diversity
    FROM incident_reports WHERE user_id = p_user_id
  ),
  course_stats AS (
    SELECT
      COUNT(*)                                        AS courses_completed,
      COALESCE(AVG(rating), 0)                       AS avg_rating
    FROM course_enrollments
    WHERE user_id = p_user_id AND status = 'completed'
  ),
  response_stats AS (
    SELECT COUNT(*)                                   AS responses_given
    FROM alert_responses WHERE responder_id = p_user_id
  )
  SELECT
    p_user_id                                         AS user_id,
    r.total_reports,
    r.resolved_count,
    r.category_diversity,
    c.courses_completed,
    c.avg_rating,
    rs.responses_given,
    LEAST(10.0, ROUND(
      (r.total_reports      * 0.10) +
      (r.resolved_count     * 0.20) +
      (r.category_diversity * 0.15) +
      (c.courses_completed  * 0.25) +
      (rs.responses_given   * 0.30)
    , 2))                                             AS engagement_score,
    get_incident_danger_level(
      LEAST(10.0, ROUND(
        (r.total_reports * 0.10) + (r.resolved_count * 0.20) +
        (r.category_diversity * 0.15) + (c.courses_completed * 0.25) +
        (rs.responses_given * 0.30)
      , 2))
    )                                                 AS score_level
  FROM report_stats r, course_stats c, response_stats rs;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `cleanup_old_data` ()   BEGIN
  DELETE FROM `notifications`
  WHERE `is_read` = 1 AND `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);

  UPDATE `user_sessions`
  SET `is_active` = 0
  WHERE `last_activity` < DATE_SUB(NOW(), INTERVAL 30 DAY) AND `is_active` = 1;

  UPDATE `alerts`
  SET `is_active` = 0
  WHERE `end_time` IS NOT NULL AND `end_time` < NOW() AND `is_active` = 1;

  UPDATE `panic_alerts`
  SET `status` = 'resolved', `resolved_at` = NOW()
  WHERE `status` = 'active'
    AND `triggered_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    AND `responders_count` = 0;
END$$

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

CREATE DEFINER=`root`@`localhost` PROCEDURE `get_incident_heatmap_data` (IN `p_lat_min` DECIMAL(10,8), IN `p_lat_max` DECIMAL(10,8), IN `p_lng_min` DECIMAL(11,8), IN `p_lng_max` DECIMAL(11,8), IN `p_days_back` INT)   BEGIN
  SELECT
    ROUND(latitude, 2)   AS grid_lat,
    ROUND(longitude, 2)  AS grid_lng,
    COUNT(*)             AS incident_count,
    SUM(CASE WHEN severity='critical' THEN 4 WHEN severity='high' THEN 3
             WHEN severity='medium'   THEN 2 ELSE 1 END) AS weighted_score,
    GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR ', ') AS categories,
    MAX(reported_date)   AS latest_incident,
    get_incident_danger_level(GREATEST(0, 10 - LEAST(10, COUNT(*) * 0.8))) AS zone_danger_level
  FROM `incident_reports`
  WHERE status != 'disputed'
    AND latitude  BETWEEN p_lat_min AND p_lat_max
    AND longitude BETWEEN p_lng_min AND p_lng_max
    AND reported_date >= DATE_SUB(NOW(), INTERVAL p_days_back DAY)
    AND latitude IS NOT NULL AND longitude IS NOT NULL
  GROUP BY grid_lat, grid_lng
  HAVING incident_count > 0
  ORDER BY weighted_score DESC
  LIMIT 500;
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
CREATE DEFINER=`root`@`localhost` FUNCTION `format_distance_km` (`p_distance_meters` DECIMAL(10,2)) RETURNS VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC READS SQL DATA BEGIN
  IF p_distance_meters < 1000 THEN
    RETURN CONCAT(ROUND(p_distance_meters), ' m');
  ELSEIF p_distance_meters < 10000 THEN
    RETURN CONCAT(ROUND(p_distance_meters / 1000, 1), ' km');
  ELSE
    RETURN CONCAT(ROUND(p_distance_meters / 1000), ' km');
  END IF;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `get_incident_danger_level` (`p_safety_score` DECIMAL(5,2)) RETURNS VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC READS SQL DATA BEGIN
  IF p_safety_score >= 8.0 THEN RETURN 'safe';
  ELSEIF p_safety_score >= 6.0 THEN RETURN 'moderate';
  ELSEIF p_safety_score >= 4.0 THEN RETURN 'danger';
  ELSE RETURN 'critical';
  END IF;
END$$

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
) ;

--
-- Dumping data for table `alerts`
--

INSERT INTO `alerts` (`id`, `title`, `description`, `type`, `severity`, `location_name`, `latitude`, `longitude`, `radius_km`, `start_time`, `end_time`, `is_active`, `source_type`, `source_user_id`, `related_report_id`, `views_count`, `acknowledgments_count`) VALUES
(1, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 30, 5),
(2, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 32, 6),
(3, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 34, 7),
(4, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 36, 8),
(5, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 38, 9),
(6, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 40, 10),
(7, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'info', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 42, 11),
(8, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'update', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 44, 12),
(9, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'warning', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 46, 13),
(10, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'info', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 48, 14),
(11, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'update', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 50, 15),
(12, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'warning', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 52, 16),
(13, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'info', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 54, 17),
(14, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'update', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 56, 18),
(15, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'warning', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 58, 19),
(16, 'New security post - Badda', 'New security post. Area safer now.', 'info', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 60, 20),
(17, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'update', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 62, 21),
(18, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'warning', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 64, 22),
(19, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'info', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 66, 23),
(20, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'update', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 68, 24),
(21, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'warning', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 70, 25),
(22, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'info', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 72, 26),
(23, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'update', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 74, 27),
(24, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'warning', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 76, 28),
(25, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'info', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 78, 29),
(26, 'New security post - Badda', 'New security post. Area safer now.', 'update', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 80, 30),
(27, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'warning', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 82, 31),
(28, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'info', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 84, 32),
(29, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'update', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 86, 33),
(30, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'warning', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 88, 34),
(31, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 90, 35),
(32, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 92, 36),
(33, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 94, 37),
(34, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 96, 38),
(35, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 98, 39),
(36, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 100, 40),
(37, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'info', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 102, 41),
(38, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'update', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 104, 42),
(39, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'warning', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 106, 43),
(40, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'info', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 108, 44),
(41, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'update', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 110, 45),
(42, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'warning', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 112, 46),
(43, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'info', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 114, 47),
(44, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'update', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 116, 48),
(45, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'warning', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 118, 49),
(46, 'New security post - Badda', 'New security post. Area safer now.', 'info', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 120, 50),
(47, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'update', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 122, 51),
(48, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'warning', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 124, 52),
(49, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'info', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 126, 53),
(50, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'update', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 128, 54),
(51, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'warning', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 130, 55),
(52, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'info', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 132, 56),
(53, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'update', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 134, 57),
(54, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'warning', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 136, 58),
(55, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'info', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 138, 59),
(56, 'New security post - Badda', 'New security post. Area safer now.', 'update', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 140, 60),
(57, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'update', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 142, 61),
(58, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'info', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 144, 62),
(59, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'update', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 146, 63),
(60, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'update', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 148, 64),
(61, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 150, 65),
(62, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 152, 66),
(63, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 154, 67),
(64, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 156, 68),
(65, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 158, 69),
(66, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 160, 70),
(67, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'info', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 162, 71),
(68, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'update', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 164, 72),
(69, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'warning', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 166, 73),
(70, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'info', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 168, 74),
(71, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'update', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 170, 75),
(72, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'warning', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 172, 76),
(73, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'info', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 174, 77),
(74, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'update', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 176, 78),
(75, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'warning', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 178, 79),
(76, 'New security post - Badda', 'New security post. Area safer now.', 'info', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 180, 80),
(77, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'update', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 182, 81),
(78, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'warning', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 184, 82),
(79, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'info', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 186, 83),
(80, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'update', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 188, 84),
(81, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'warning', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 190, 85),
(82, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'info', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 192, 86),
(83, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'update', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 194, 87),
(84, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'warning', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 196, 88),
(85, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'info', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 198, 89),
(86, 'New security post - Badda', 'New security post. Area safer now.', 'update', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 200, 90),
(87, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'warning', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 7, NULL, 202, 91),
(88, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'info', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 8, NULL, 204, 92),
(89, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'update', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 9, NULL, 206, 93),
(90, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'warning', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 10, NULL, 208, 94),
(91, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 1, NULL, 210, 95),
(92, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 2, NULL, 212, 96),
(93, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 3, NULL, 214, 97),
(94, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 4, NULL, 216, 98),
(95, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 5, NULL, 218, 99),
(96, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:06', NULL, 1, 'community', 6, NULL, 220, 100),
(97, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 30, 5),
(98, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 32, 6),
(99, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 34, 7),
(100, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 36, 8),
(101, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 38, 9),
(102, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 40, 10),
(103, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'info', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 42, 11),
(104, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'update', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 44, 12),
(105, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'warning', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 46, 13),
(106, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'info', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 48, 14),
(107, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'update', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 50, 15),
(108, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'warning', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 52, 16),
(109, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'info', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 54, 17),
(110, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'update', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 56, 18),
(111, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'warning', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 58, 19),
(112, 'New security post - Badda', 'New security post. Area safer now.', 'info', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 60, 20),
(113, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'update', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 62, 21),
(114, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'warning', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 64, 22),
(115, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'info', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 66, 23),
(116, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'update', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 68, 24),
(117, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'warning', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 70, 25),
(118, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'info', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 72, 26),
(119, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'update', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 74, 27),
(120, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'warning', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 76, 28),
(121, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'info', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 78, 29),
(122, 'New security post - Badda', 'New security post. Area safer now.', 'update', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 80, 30),
(123, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'warning', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 82, 31),
(124, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'info', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 84, 32),
(125, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'update', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 86, 33),
(126, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'warning', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 88, 34),
(127, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 90, 35),
(128, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 92, 36),
(129, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 94, 37),
(130, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 96, 38),
(131, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 98, 39),
(132, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 100, 40),
(133, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'info', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 102, 41),
(134, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'update', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 104, 42),
(135, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'warning', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 106, 43),
(136, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'info', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 108, 44),
(137, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'update', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 110, 45),
(138, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'warning', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 112, 46),
(139, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'info', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 114, 47),
(140, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'update', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 116, 48),
(141, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'warning', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 118, 49),
(142, 'New security post - Badda', 'New security post. Area safer now.', 'info', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 120, 50),
(143, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'update', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 122, 51),
(144, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'warning', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 124, 52),
(145, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'info', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 126, 53),
(146, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'update', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 128, 54),
(147, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'warning', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 130, 55),
(148, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'info', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 132, 56),
(149, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'update', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 134, 57),
(150, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'warning', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 136, 58),
(151, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'info', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 138, 59),
(152, 'New security post - Badda', 'New security post. Area safer now.', 'update', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 140, 60),
(153, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'warning', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 142, 61),
(154, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'info', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 144, 62),
(155, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'update', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 146, 63),
(156, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'warning', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 148, 64),
(157, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 150, 65),
(158, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 152, 66),
(159, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 154, 67),
(160, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 156, 68),
(161, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 158, 69),
(162, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 160, 70),
(163, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'info', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 162, 71),
(164, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'update', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 164, 72),
(165, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'warning', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 166, 73),
(166, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'info', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 168, 74),
(167, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'update', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 170, 75),
(168, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'warning', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 172, 76),
(169, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'info', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 174, 77),
(170, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'update', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 176, 78),
(171, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'warning', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 178, 79),
(172, 'New security post - Badda', 'New security post. Area safer now.', 'info', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 180, 80),
(173, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'update', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 182, 81),
(174, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'warning', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 184, 82),
(175, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'info', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 186, 83),
(176, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'update', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 188, 84),
(177, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'warning', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 190, 85),
(178, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'info', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 192, 86),
(179, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'update', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 194, 87),
(180, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'warning', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 196, 88),
(181, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'info', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 198, 89),
(182, 'New security post - Badda', 'New security post. Area safer now.', 'update', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 200, 90),
(183, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'warning', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 202, 91),
(184, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'info', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 204, 92),
(185, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'update', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 206, 93),
(186, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'warning', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 208, 94),
(187, 'Street light fixed - Dhanmondi Rd 2', 'Street light fixed. Area safer now.', 'info', 'low', 'Dhanmondi Rd 2', 23.74520000, 90.37520000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 1, NULL, 210, 95),
(188, 'CCTV installed - Mirpur 10', 'CCTV installed. Area safer now.', 'update', 'low', 'Mirpur 10', 23.80350000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 2, NULL, 212, 96),
(189, 'Safe route added - Uttara Sector 3', 'Safe route added. Area safer now.', 'warning', 'low', 'Uttara Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 3, NULL, 214, 97),
(190, 'Police patrol increased - Gulshan 2', 'Police patrol increased. Area safer now.', 'info', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 4, NULL, 216, 98),
(191, 'Road repair done - Farmgate', 'Road repair done. Area safer now.', 'update', 'low', 'Farmgate', 23.75400000, 90.38800000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 5, NULL, 218, 99),
(192, 'New security post - Badda', 'New security post. Area safer now.', 'warning', 'low', 'Badda', 23.78800000, 90.43200000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 6, NULL, 220, 100),
(193, 'Community watch active - Mohammadpur', 'Community watch active. Area safer now.', 'info', 'low', 'Mohammadpur', 23.76100000, 90.36600000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 7, NULL, 222, 101),
(194, 'Well-lit path - Banani', 'Well-lit path. Area safer now.', 'update', 'low', 'Banani', 23.78600000, 90.40500000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 8, NULL, 224, 102),
(195, 'Emergency button added - Panthapath', 'Emergency button added. Area safer now.', 'warning', 'low', 'Panthapath', 23.75100000, 90.39100000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 9, NULL, 226, 103),
(196, 'Guard posted - Mohakhali', 'Guard posted. Area safer now.', 'info', 'low', 'Mohakhali', 23.77850000, 90.37250000, 0.50, '2026-02-15 21:11:26', NULL, 1, 'community', 10, NULL, 228, 104),
(197, 'Suspicious activity near Dhanmondi Lake', 'Multiple reports of unknown persons lingering near the lake after dark. Stay alert.', 'warning', 'medium', 'Dhanmondi Lake', 23.75000000, 90.37800000, 1.00, '2026-02-15 21:11:48', NULL, 1, 'community', 2, 24, 45, 12),
(198, 'Road construction - Diversion at Mirpur 10', 'Main road blocked. Use alternate route. Well-lit path available.', 'info', 'low', 'Mirpur 10', 23.80690000, 90.36870000, 0.50, '2026-02-15 21:11:48', NULL, 1, 'system', NULL, NULL, 120, 8),
(199, 'Police presence increased at Gulshan 2', 'Additional patrol for your safety. All clear.', 'update', 'low', 'Gulshan 2', 23.79250000, 90.41620000, 1.00, '2026-02-15 21:11:48', NULL, 1, 'admin', 1, NULL, 85, 25),
(200, 'New street lights installed - Dhanmondi 27', 'Road 27 now has improved lighting. Safer for evening walks.', 'update', 'low', 'Dhanmondi Road 27', 23.74600000, 90.38000000, 0.30, '2026-02-15 21:11:48', NULL, 1, 'community', 2, NULL, 65, 22),
(201, 'CCTV camera out of order - Mirpur 10', 'Camera near community center not working. Report to authority.', 'warning', 'medium', 'Mirpur 10 Community Center', 23.80690000, 90.36870000, 0.20, '2026-02-15 21:11:48', NULL, 1, 'community', 5, NULL, 42, 15),
(202, 'Safe route mapped - Uttara Metro to Sector 3', 'Community has verified safe walking path. Check app for details.', 'info', 'low', 'Uttara Metro to Sector 3', 23.86300000, 90.39700000, 0.50, '2026-02-15 21:11:48', NULL, 1, 'community', 10, NULL, 88, 35),
(203, 'Temporary road closure - Farmgate', 'Road repair until Feb 20. Use alternate route.', 'info', 'low', 'Farmgate Main Road', 23.75400000, 90.38800000, 0.25, '2026-02-15 21:11:48', NULL, 1, 'system', NULL, NULL, 120, 45),
(204, 'Suspicious person following women - Kamalapur', 'Multiple reports of man following women near station exit. Police notified. Avoid walking alone.', 'emergency', 'critical', 'Kamalapur Railway Station', 23.72950000, 90.42700000, 0.80, '2026-02-15 21:45:22', NULL, 1, 'community', 2, NULL, 156, 89),
(205, 'Snatchers active - Gabtoli Bus Terminal', 'Phone snatching reported near terminal entrance. 3 incidents today. Be alert with belongings.', 'warning', 'critical', 'Gabtoli Bus Terminal', 23.76800000, 90.35500000, 0.60, '2026-02-15 21:45:22', NULL, 1, 'community', 5, NULL, 203, 112),
(206, 'Harassment near Jatrabari crossing', 'Eve-teasing and verbal harassment reported. Women advised to use main road, avoid shortcuts.', 'emergency', 'critical', 'Jatrabari Crossing', 23.71800000, 90.43500000, 0.70, '2026-02-15 21:45:22', NULL, 1, 'community', 7, NULL, 178, 95),
(207, 'Street light out - Karwan Bazar night market', 'Dark stretch near wholesale market. Multiple incidents. Avoid after 10 PM.', 'warning', 'high', 'Karwan Bazar Night Market', 23.75200000, 90.39000000, 0.50, '2026-02-15 21:45:22', NULL, 1, 'community', 3, NULL, 134, 67),
(208, 'Robbery attempt - Demra Bus Stand', 'Armed robbery attempt reported. Police patrolling increased. Stay in well-lit areas.', 'emergency', 'critical', 'Demra Bus Stand', 23.71000000, 90.44500000, 0.75, '2026-02-15 21:45:22', NULL, 1, 'community', 9, NULL, 221, 134),
(209, 'Stalking reported - Farmgate flyover', 'Person repeatedly following students. Report to police if you notice.', 'warning', 'high', 'Farmgate Flyover', 23.75300000, 90.38600000, 0.55, '2026-02-15 21:45:22', NULL, 1, 'community', 4, NULL, 145, 78);

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
-- Dumping data for table `alert_responses`
--

INSERT INTO `alert_responses` (`id`, `alert_id`, `responder_id`, `response_time`, `current_latitude`, `current_longitude`, `eta_minutes`, `status`, `message`, `created_at`, `updated_at`) VALUES
(1, 1, 5, '2026-02-15 15:11:48', 23.80690000, 90.36870000, 0, 'arrived', 'I am here. You are safe now.', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(2, 1, 6, '2026-02-15 15:11:48', 23.80500000, 90.36900000, 2, 'arrived', 'On my way - 2 min away.', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(3, 3, 1, '2026-02-15 15:11:48', 23.75160000, 90.37750000, 0, 'arrived', 'Police contacted. Stay calm.', '2026-02-15 15:11:48', '2026-02-15 15:11:48');

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
) ;

--
-- Dumping data for table `area_safety_scores`
--

INSERT INTO `area_safety_scores` (`id`, `area_name`, `ward_number`, `union_name`, `division_id`, `district_id`, `upazila_id`, `safety_score`, `incident_rate_score`, `resolution_rate_score`, `response_time_score`, `user_ratings_score`, `critical_incidents_score`, `total_incidents`, `resolved_incidents`, `critical_incidents`, `response_time_avg_hours`, `last_updated`, `created_at`) VALUES
(1, 'Dhanmondi', '52', NULL, 1, 1, 1, 7.50, 7.00, 8.00, 7.50, 7.50, 8.00, 12, 9, 1, 1.20, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(2, 'Mirpur', '1', NULL, 1, 1, 2, 6.80, 6.50, 7.00, 6.80, 7.00, 6.50, 18, 12, 3, 1.50, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(3, 'Uttara', '1', NULL, 1, 1, 3, 7.20, 7.00, 7.50, 7.20, 7.20, 7.00, 8, 6, 1, 1.00, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(4, 'Gulshan', '19', NULL, 1, 1, 4, 7.80, 8.00, 8.00, 7.50, 7.80, 8.00, 6, 5, 0, 0.80, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(5, 'Mohammadpur', '15', NULL, 1, 1, 6, 6.50, 6.00, 6.50, 6.50, 6.80, 6.00, 15, 9, 2, 1.80, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(6, 'Badda', '11', NULL, 1, 1, 7, 6.90, 6.50, 7.00, 7.00, 7.00, 6.50, 10, 7, 2, 1.40, '2026-02-15 21:11:47', '2026-02-15 21:11:47');

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
(1, 9, 'INSERT', 'incident_reports', 1, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(2, 5, 'INSERT', 'incident_reports', 2, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(3, 5, 'INSERT', 'incident_reports', 3, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(4, 5, 'INSERT', 'incident_reports', 4, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(5, 10, 'INSERT', 'incident_reports', 5, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(6, 5, 'INSERT', 'incident_reports', 6, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(7, 9, 'INSERT', 'incident_reports', 7, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(8, 9, 'INSERT', 'incident_reports', 8, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(9, 9, 'INSERT', 'incident_reports', 9, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(10, 7, 'INSERT', 'incident_reports', 10, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(11, 6, 'INSERT', 'incident_reports', 11, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(12, 3, 'INSERT', 'incident_reports', 12, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(13, 4, 'INSERT', 'incident_reports', 13, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(14, 10, 'INSERT', 'incident_reports', 14, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(15, 4, 'INSERT', 'incident_reports', 15, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(16, 9, 'INSERT', 'incident_reports', 16, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(17, 4, 'INSERT', 'incident_reports', 17, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(18, 3, 'INSERT', 'incident_reports', 18, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(19, 7, 'INSERT', 'incident_reports', 19, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(20, 5, 'INSERT', 'incident_reports', 20, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(21, 4, 'INSERT', 'incident_reports', 21, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(22, 6, 'INSERT', 'incident_reports', 22, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(23, 10, 'INSERT', 'incident_reports', 23, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(24, 2, 'INSERT', 'incident_reports', 24, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(25, 5, 'INSERT', 'incident_reports', 25, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(26, 9, 'INSERT', 'incident_reports', 26, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(27, 6, 'INSERT', 'incident_reports', 27, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(28, 2, 'INSERT', 'incident_reports', 28, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(29, 8, 'INSERT', 'incident_reports', 29, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(30, 9, 'INSERT', 'incident_reports', 30, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(31, 2, 'INSERT', 'incident_reports', 31, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(32, 10, 'INSERT', 'incident_reports', 32, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(33, 5, 'INSERT', 'incident_reports', 33, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(34, 5, 'INSERT', 'incident_reports', 34, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(35, 3, 'INSERT', 'incident_reports', 35, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(36, 9, 'INSERT', 'incident_reports', 36, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(37, 9, 'INSERT', 'incident_reports', 37, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(38, 2, 'INSERT', 'incident_reports', 38, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(39, 9, 'INSERT', 'incident_reports', 39, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(40, 4, 'INSERT', 'incident_reports', 40, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(41, 2, 'INSERT', 'incident_reports', 41, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(42, 7, 'INSERT', 'incident_reports', 42, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(43, 3, 'INSERT', 'incident_reports', 43, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(44, 6, 'INSERT', 'incident_reports', 44, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(45, 5, 'INSERT', 'incident_reports', 45, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(46, 4, 'INSERT', 'incident_reports', 46, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(47, 8, 'INSERT', 'incident_reports', 47, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(48, 7, 'INSERT', 'incident_reports', 48, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(49, 7, 'INSERT', 'incident_reports', 49, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(50, 9, 'INSERT', 'incident_reports', 50, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(51, 7, 'INSERT', 'incident_reports', 51, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(52, 4, 'INSERT', 'incident_reports', 52, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(53, 8, 'INSERT', 'incident_reports', 53, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(54, 8, 'INSERT', 'incident_reports', 54, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(55, 8, 'INSERT', 'incident_reports', 55, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(56, 2, 'INSERT', 'incident_reports', 56, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(57, 6, 'INSERT', 'incident_reports', 57, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(58, 1, 'INSERT', 'incident_reports', 58, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(59, 6, 'INSERT', 'incident_reports', 59, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(60, 6, 'INSERT', 'incident_reports', 60, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(61, 9, 'INSERT', 'incident_reports', 61, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(62, 6, 'INSERT', 'incident_reports', 62, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(63, 10, 'INSERT', 'incident_reports', 63, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(64, 9, 'INSERT', 'incident_reports', 64, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(65, 1, 'INSERT', 'incident_reports', 65, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(66, 1, 'INSERT', 'incident_reports', 66, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(67, 1, 'INSERT', 'incident_reports', 67, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(68, 10, 'INSERT', 'incident_reports', 68, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(69, 7, 'INSERT', 'incident_reports', 69, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(70, 8, 'INSERT', 'incident_reports', 70, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(71, 4, 'INSERT', 'incident_reports', 71, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(72, 8, 'INSERT', 'incident_reports', 72, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(73, 2, 'INSERT', 'incident_reports', 73, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(74, 2, 'INSERT', 'incident_reports', 74, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(75, 6, 'INSERT', 'incident_reports', 75, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(76, 2, 'INSERT', 'incident_reports', 76, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(77, 5, 'INSERT', 'incident_reports', 77, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(78, 9, 'INSERT', 'incident_reports', 78, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(79, 8, 'INSERT', 'incident_reports', 79, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(80, 6, 'INSERT', 'incident_reports', 80, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(81, 8, 'INSERT', 'incident_reports', 81, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(82, 10, 'INSERT', 'incident_reports', 82, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(83, 9, 'INSERT', 'incident_reports', 83, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(84, 8, 'INSERT', 'incident_reports', 84, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(85, 2, 'INSERT', 'incident_reports', 85, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(86, 3, 'INSERT', 'incident_reports', 86, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(87, 8, 'INSERT', 'incident_reports', 87, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(88, 3, 'INSERT', 'incident_reports', 88, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(89, 8, 'INSERT', 'incident_reports', 89, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(90, 7, 'INSERT', 'incident_reports', 90, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(91, 5, 'INSERT', 'incident_reports', 91, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(92, 5, 'INSERT', 'incident_reports', 92, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(93, 5, 'INSERT', 'incident_reports', 93, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(94, 7, 'INSERT', 'incident_reports', 94, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(95, 6, 'INSERT', 'incident_reports', 95, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(96, 10, 'INSERT', 'incident_reports', 96, NULL, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(97, 1, 'INSERT', 'incident_reports', 97, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(98, 10, 'INSERT', 'incident_reports', 98, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(99, 9, 'INSERT', 'incident_reports', 99, NULL, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(100, 10, 'INSERT', 'incident_reports', 100, NULL, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 20:15:54'),
(101, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 20:26:29'),
(102, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik05@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik05@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(103, 2, 'UPDATE', 'users', 2, '{\"email\": \"safrin2330183@bscse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"safrin2330183@bscse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(104, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(105, 4, 'UPDATE', 'users', 4, '{\"email\": \"bonnyafrin98@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"bonnyafrin98@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(106, 5, 'UPDATE', 'users', 5, '{\"email\": \"manik2330217@bscse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"manik2330217@bscse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(107, 6, 'UPDATE', 'users', 6, '{\"email\": \"sadiaafrinbonny183@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"sadiaafrinbonny183@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(108, 7, 'UPDATE', 'users', 7, '{\"email\": \"msakib2330048@bscse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"msakib2330048@bscse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(109, 8, 'UPDATE', 'users', 8, '{\"email\": \"sidratul@cse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"sidratul@cse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(110, 9, 'UPDATE', 'users', 9, '{\"email\": \"sanjida@cse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"sanjida@cse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(111, 10, 'UPDATE', 'users', 10, '{\"email\": \"aurna@cse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"aurna@cse.uiu.ac.bd\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(112, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik05@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik05@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-15 21:11:47'),
(113, 9, 'UPDATE', 'incident_reports', 1, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(114, 5, 'UPDATE', 'incident_reports', 2, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(115, 5, 'UPDATE', 'incident_reports', 3, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(116, 5, 'UPDATE', 'incident_reports', 4, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(117, 10, 'UPDATE', 'incident_reports', 5, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(118, 2, 'UPDATE', 'incident_reports', 24, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(119, 5, 'UPDATE', 'incident_reports', 2, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"resolved\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(120, 5, 'UPDATE', 'incident_reports', 3, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"resolved\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(121, 9, 'UPDATE', 'incident_reports', 7, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"resolved\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(122, 9, 'UPDATE', 'incident_reports', 8, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"resolved\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(123, 4, 'UPDATE', 'incident_reports', 13, '{\"status\": \"pending\", \"severity\": \"low\", \"assigned_to\": null}', '{\"status\": \"resolved\", \"severity\": \"low\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(124, 9, 'UPDATE', 'incident_reports', 1, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(125, 5, 'UPDATE', 'incident_reports', 4, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(126, 10, 'UPDATE', 'incident_reports', 5, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(127, 9, 'UPDATE', 'incident_reports', 9, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(128, 3, 'UPDATE', 'incident_reports', 12, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"under_review\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(129, 7, 'UPDATE', 'incident_reports', 10, '{\"status\": \"pending\", \"severity\": \"medium\", \"assigned_to\": null}', '{\"status\": \"investigating\", \"severity\": \"medium\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(130, 10, 'UPDATE', 'incident_reports', 14, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"investigating\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(131, 3, 'UPDATE', 'incident_reports', 18, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"investigating\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(132, 2, 'UPDATE', 'incident_reports', 24, '{\"status\": \"pending\", \"severity\": \"high\", \"assigned_to\": null}', '{\"status\": \"investigating\", \"severity\": \"high\", \"assigned_to\": null}', NULL, NULL, NULL, '2026-02-15 21:11:48'),
(133, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-16 22:35:50'),
(134, 11, 'INSERT', 'users', 11, NULL, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-16 22:47:30'),
(135, 11, 'UPDATE', 'users', 11, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-16 22:49:28'),
(136, 11, 'UPDATE', 'users', 11, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-16 22:49:48'),
(137, 11, 'admin_login', 'users', 11, NULL, '{\"login_time\":\"2026-02-16 17:49:48\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2026-02-16 22:49:48'),
(138, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-16 23:08:49'),
(139, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-16 23:15:03'),
(140, 11, 'UPDATE', 'users', 11, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-16 23:17:39'),
(141, 11, 'admin_login', 'users', 11, NULL, '{\"login_time\":\"2026-02-16 18:17:39\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2026-02-16 23:17:39'),
(142, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-17 00:09:26'),
(143, 3, 'panic_alert_from_walk', 'walk_sessions', 6, NULL, '{\"panic_alert_id\":4,\"walk_session_token\":\"d004d2fcc9adbf24b68e556500f587cc9585920dc29a722ff266bb79058987e7\"}', NULL, NULL, NULL, '2026-02-17 00:12:06'),
(144, 3, 'sos_alert', 'walk_sessions', 6, NULL, '{\"session_token\":\"d004d2fcc9adbf24b68e556500f587cc9585920dc29a722ff266bb79058987e7\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":4}', NULL, NULL, NULL, '2026-02-17 00:12:06'),
(145, 3, 'panic_alert_from_walk', 'walk_sessions', 7, NULL, '{\"panic_alert_id\":5,\"walk_session_token\":\"f48649eea503d90557b75a1013d77e591f6f58c11fd3ea64918f4eb1a940865b\"}', NULL, NULL, NULL, '2026-02-17 00:14:05'),
(146, 3, 'sos_alert', 'walk_sessions', 7, NULL, '{\"session_token\":\"f48649eea503d90557b75a1013d77e591f6f58c11fd3ea64918f4eb1a940865b\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":5}', NULL, NULL, NULL, '2026-02-17 00:14:05'),
(147, 3, 'panic_alert_from_walk', 'walk_sessions', 8, NULL, '{\"panic_alert_id\":6,\"walk_session_token\":\"9dc037d3301ed8b202b5479dba32d3b2a922fbb61852fdb4ea60f90eca6aae72\"}', NULL, NULL, NULL, '2026-02-17 00:16:44'),
(148, 3, 'sos_alert', 'walk_sessions', 8, NULL, '{\"session_token\":\"9dc037d3301ed8b202b5479dba32d3b2a922fbb61852fdb4ea60f90eca6aae72\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":6}', NULL, NULL, NULL, '2026-02-17 00:16:44'),
(149, 1, 'UPDATE', 'users', 1, '{\"email\": \"mdabusayumanik05@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik05@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-17 00:18:09'),
(150, 3, 'panic_alert_from_walk', 'walk_sessions', 9, NULL, '{\"panic_alert_id\":8,\"walk_session_token\":\"387135a448e82d1154afd4d42cc4073434033aabe56f950ea5c62fbdfc11e703\"}', NULL, NULL, NULL, '2026-02-17 00:27:43'),
(151, 3, 'sos_alert', 'walk_sessions', 9, NULL, '{\"session_token\":\"387135a448e82d1154afd4d42cc4073434033aabe56f950ea5c62fbdfc11e703\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":8}', NULL, NULL, NULL, '2026-02-17 00:27:43'),
(152, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-17 18:08:23'),
(153, 3, 'panic_alert_from_walk', 'walk_sessions', 10, NULL, '{\"panic_alert_id\":9,\"walk_session_token\":\"f0558c1c1475512fc2f5ec5fd1108fbd127c7708c7acfcc4dd6e5c8a4183f8cc\"}', NULL, NULL, NULL, '2026-02-17 18:24:45'),
(154, 3, 'sos_alert', 'walk_sessions', 10, NULL, '{\"session_token\":\"f0558c1c1475512fc2f5ec5fd1108fbd127c7708c7acfcc4dd6e5c8a4183f8cc\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":9}', NULL, NULL, NULL, '2026-02-17 18:24:45'),
(155, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-17 18:54:34'),
(156, 3, 'panic_alert_from_walk', 'walk_sessions', 11, NULL, '{\"panic_alert_id\":10,\"walk_session_token\":\"0c367c8016dcd64a5979d25d72644b9fd482ca1bdb33fd42b7b552f9e03d1e34\"}', NULL, NULL, NULL, '2026-02-17 19:05:04'),
(157, 3, 'sos_alert', 'walk_sessions', 11, NULL, '{\"session_token\":\"0c367c8016dcd64a5979d25d72644b9fd482ca1bdb33fd42b7b552f9e03d1e34\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":10}', NULL, NULL, NULL, '2026-02-17 19:05:04'),
(158, 11, 'UPDATE', 'users', 11, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-17 19:28:05'),
(159, 11, 'admin_login', 'users', 11, NULL, '{\"login_time\":\"2026-02-17 14:28:05\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2026-02-17 19:28:05'),
(160, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-18 17:02:04'),
(161, 3, 'panic_alert_from_walk', 'walk_sessions', 12, NULL, '{\"panic_alert_id\":11,\"walk_session_token\":\"d692335c91afd0078fbe3ae2db404efdbc007942031e5f18b5bf05d48cac06bc\"}', NULL, NULL, NULL, '2026-02-18 17:16:11'),
(162, 3, 'sos_alert', 'walk_sessions', 12, NULL, '{\"session_token\":\"d692335c91afd0078fbe3ae2db404efdbc007942031e5f18b5bf05d48cac06bc\",\"status\":\"emergency\",\"contacts_notified\":1,\"panic_alert_id\":11}', NULL, NULL, NULL, '2026-02-18 17:16:11'),
(163, 11, 'UPDATE', 'users', 11, '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"admin@safespace.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-18 17:44:43'),
(164, 11, 'admin_login', 'users', 11, NULL, '{\"login_time\":\"2026-02-18 12:44:43\",\"ip\":\"::1\"}', NULL, NULL, NULL, '2026-02-18 17:44:43'),
(165, 3, 'UPDATE', 'users', 3, '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', '{\"email\": \"mdabusayumanik123@gmail.com\", \"status\": \"active\", \"is_active\": 1}', NULL, NULL, NULL, '2026-02-19 05:11:09');

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

--
-- Dumping data for table `certificates`
--

INSERT INTO `certificates` (`id`, `user_id`, `course_id`, `enrollment_id`, `certificate_number`, `certificate_file_path`, `verification_code`, `issued_at`, `expires_at`, `is_verified`) VALUES
(1, 1, 1, 1, 'CERT-2026-001', NULL, 'V001', '2026-02-15 21:11:48', NULL, 1),
(2, 1, 2, 2, 'CERT-2026-002', NULL, 'V002', '2026-02-15 21:11:48', NULL, 1),
(3, 2, 1, 4, 'CERT-2026-003', NULL, 'V003', '2026-02-15 21:11:48', NULL, 1),
(4, 3, 2, 6, 'CERT-2026-004', NULL, 'V004', '2026-02-15 21:11:48', NULL, 1),
(5, 3, 3, 7, 'CERT-2026-005', NULL, 'V005', '2026-02-15 21:11:48', NULL, 1),
(6, 4, 1, 8, 'CERT-2026-006', NULL, 'V006', '2026-02-15 21:11:48', NULL, 1),
(7, 5, 3, 11, 'CERT-2026-007', NULL, 'V007', '2026-02-15 21:11:48', NULL, 1),
(8, 6, 1, 12, 'CERT-2026-008', NULL, 'V008', '2026-02-15 21:11:48', NULL, 1),
(9, 7, 2, 14, 'CERT-2026-009', NULL, 'V009', '2026-02-15 21:11:48', NULL, 1),
(10, 8, 1, 15, 'CERT-2026-010', NULL, 'V010', '2026-02-15 21:11:48', NULL, 1),
(11, 8, 3, 16, 'CERT-2026-011', NULL, 'V011', '2026-02-15 21:11:48', NULL, 1),
(12, 9, 1, 17, 'CERT-2026-012', NULL, 'V012', '2026-02-15 21:11:48', NULL, 1),
(13, 9, 4, 18, 'CERT-2026-013', NULL, 'V013', '2026-02-15 21:11:48', NULL, 1),
(14, 10, 1, 19, 'CERT-2026-014', NULL, 'V014', '2026-02-15 21:11:48', NULL, 1),
(15, 10, 2, 20, 'CERT-2026-015', NULL, 'V015', '2026-02-15 21:11:48', NULL, 1),
(16, 10, 3, 21, 'CERT-2026-016', NULL, 'V016', '2026-02-15 21:11:48', NULL, 1);

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
) ;

--
-- Dumping data for table `course_enrollments`
--

INSERT INTO `course_enrollments` (`id`, `user_id`, `course_id`, `progress_percentage`, `status`, `started_at`, `completed_at`, `certificate_issued`, `certificate_id`, `rating`, `feedback`, `last_accessed_at`) VALUES
(1, 1, 1, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Very helpful!', '2026-02-15 21:11:47'),
(2, 1, 2, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 4, 'Good cyber awareness content.', '2026-02-15 21:11:47'),
(3, 1, 3, 75.00, 'in_progress', '2026-02-15 21:11:47', NULL, 0, NULL, NULL, NULL, '2026-02-15 21:11:47'),
(4, 2, 1, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Essential for everyone.', '2026-02-15 21:11:47'),
(5, 2, 4, 50.00, 'in_progress', '2026-02-15 21:11:47', NULL, 0, NULL, NULL, NULL, '2026-02-15 21:11:47'),
(6, 3, 2, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Learned a lot about online safety.', '2026-02-15 21:11:47'),
(7, 3, 3, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 4, NULL, '2026-02-15 21:11:47'),
(8, 4, 1, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Empowering.', '2026-02-15 21:11:47'),
(9, 4, 4, 30.00, 'enrolled', '2026-02-15 21:11:47', NULL, 0, NULL, NULL, NULL, '2026-02-15 21:11:47'),
(10, 5, 1, 80.00, 'in_progress', '2026-02-15 21:11:47', NULL, 0, NULL, NULL, NULL, '2026-02-15 21:11:47'),
(11, 5, 3, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Life saving knowledge.', '2026-02-15 21:11:47'),
(12, 6, 1, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 4, NULL, '2026-02-15 21:11:47'),
(13, 6, 2, 60.00, 'in_progress', '2026-02-15 21:11:47', NULL, 0, NULL, NULL, NULL, '2026-02-15 21:11:47'),
(14, 7, 2, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Must for students.', '2026-02-15 21:11:47'),
(15, 8, 1, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Excellent instructor.', '2026-02-15 21:11:47'),
(16, 8, 3, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 4, NULL, '2026-02-15 21:11:47'),
(17, 9, 1, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Women should take this.', '2026-02-15 21:11:47'),
(18, 9, 4, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Important legal info.', '2026-02-15 21:11:47'),
(19, 10, 1, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, NULL, '2026-02-15 21:11:47'),
(20, 10, 2, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Very relevant.', '2026-02-15 21:11:47'),
(21, 10, 3, 100.00, 'completed', '2026-02-15 21:11:47', NULL, 0, NULL, 5, 'Ready for emergencies.', '2026-02-15 21:11:47');

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
(1, 1, 'Dhaka', '3026', '2026-02-15 20:01:29', '2026-02-15 20:01:29');

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
(1, 'Dhaka', 'DHK', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(2, 'Chittagong', 'CTG', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(3, 'Rajshahi', 'RAJ', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(4, 'Khulna', 'KHU', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(5, 'Sylhet', 'SYL', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(6, 'Barisal', 'BAR', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(7, 'Rangpur', 'RAN', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(8, 'Mymensingh', 'MYM', '2026-02-15 20:01:29', '2026-02-15 20:01:29');

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
(1, 1, 'Abir Hossain', '01711002233', 'Brother', 1, 1, 'sms,call', 1, '2026-02-15 20:22:50', '2026-02-15 20:22:50'),
(2, 1, 'Laila Begum', '01822334455', 'Mother', 2, 1, 'sms,call', 1, '2026-02-15 20:22:50', '2026-02-15 20:22:50'),
(3, 2, 'Kamal Ahmed', '01911223344', 'Father', 1, 1, 'sms,call', 1, '2026-02-15 20:22:50', '2026-02-15 20:22:50'),
(4, 3, 'Sultana Razia', '01511223344', 'Friend', 1, 1, 'sms,call', 1, '2026-02-15 20:22:50', '2026-02-15 20:22:50'),
(5, 5, 'Karim Ullah', '01311223344', 'Spouse', 1, 1, 'sms,call', 1, '2026-02-15 20:22:50', '2026-02-15 20:22:50'),
(6, 10, 'Nabila Islam', '01611223344', 'Sister', 1, 1, 'sms,call', 1, '2026-02-15 20:22:50', '2026-02-15 20:22:50'),
(7, 4, 'Rahim Uddin', '01876543210', 'Father', 1, 1, 'sms,call', 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(8, 4, 'Fatema Begum', '01776543211', 'Mother', 2, 1, 'sms,call', 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(9, 6, 'Karim Ahmed', '01976543212', 'Brother', 1, 1, 'sms,call', 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(10, 7, 'Nasrin Akter', '01676543213', 'Sister', 1, 1, 'sms,call,whatsapp', 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(11, 8, 'Jamil Hossain', '01576543214', 'Father', 1, 1, 'sms,call', 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(12, 9, 'Taslima Akter', '01876543215', 'Mother', 1, 1, 'sms,call', 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47');

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
(12, 'Mohammadpur Thana', 'মোহাম্মদপুর থানা', 'police_station', 'Mohammadpur, Dhaka', NULL, '01320040200', '999', NULL, NULL, 23.76200000, 90.36500000, 0xe610000001010000008fc2f5285c975640508d976e12c33740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(13, 'Badda Thana', 'বাড্ডা থানা', 'police_station', 'Badda, Dhaka', NULL, '01320040500', '999', NULL, NULL, 23.78500000, 90.44200000, 0xe610000001010000003f355eba499c5640295c8fc2f5c83740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(14, 'Paltan Thana', 'পল্টন থানা', 'police_station', 'Paltan, Dhaka', NULL, '01320040100', '999', NULL, NULL, 23.73000000, 90.41200000, 0xe61000000101000000ee7c3f355e9a56407b14ae47e1ba3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(15, 'Motijheel Thana', 'মতিঝিল থানা', 'police_station', 'Motijheel C/A, Dhaka', NULL, '01320040350', '999', NULL, NULL, 23.72500000, 90.41800000, 0xe61000000101000000986e1283c09a56409a99999999b93740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(16, 'Khilgaon Thana', 'খিলগাঁও থানা', 'police_station', 'Khilgaon, Dhaka', NULL, '01320040600', '999', NULL, NULL, 23.75220000, 90.43350000, 0xe6100000010100000039b4c876be9b56401b0de02d90c03740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(17, 'Jatrabari Thana', 'যাত্রাবাড়ী থানা', 'police_station', 'Jatrabari, Dhaka', NULL, '01320040700', '999', NULL, NULL, 23.71850000, 90.43480000, 0xe61000000101000000401361c3d39b56400e2db29defb73740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(18, 'Demra Thana', 'ডেমরা থানা', 'police_station', 'Demra, Dhaka', NULL, '01320040900', '999', NULL, NULL, 23.71020000, 90.44450000, 0xe610000001010000009cc420b0729c5640849ecdaacfb53740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(19, 'Rampura Thana', 'রামপুরা থানা', 'police_station', 'Rampura, Dhaka', NULL, '01320041000', '999', NULL, NULL, 23.76250000, 90.42480000, 0xe61000000101000000d0d556ec2f9b56403333333333c33740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(20, 'Pallabi Thana', 'পল্লবী থানা', 'police_station', 'Pallabi, Mirpur, Dhaka', NULL, '01320041100', '999', NULL, NULL, 23.81250000, 90.35820000, 0xe61000000101000000575bb1bfec9656400000000000d03740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(21, 'Adabor Thana', 'আদাবর থানা', 'police_station', 'Adabor, Dhaka', NULL, '01320041300', '999', NULL, NULL, 23.74720000, 90.36480000, 0xe610000001010000002c6519e2589756403a92cb7f48bf3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(22, 'Sher-e-Bangla Nagar Thana', 'শেরেবাংলা নগর থানা', 'police_station', 'Sher-e-Bangla Nagar, Dhaka', NULL, '01320041400', '999', NULL, NULL, 23.78200000, 90.38000000, 0xe61000000101000000b81e85eb51985640d578e92631c83740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(23, 'Shahbagh Thana', 'শাহবাগ থানা', 'police_station', 'Shahbagh, Dhaka', NULL, '01320041500', '999', NULL, NULL, 23.73700000, 90.39500000, 0xe61000000101000000e17a14ae47995640e9263108acbc3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(24, 'Kotwali Thana', 'কোতোয়ালি থানা', 'police_station', 'Kotwali, Old Dhaka', NULL, '01320040150', '999', NULL, NULL, 23.72200000, 90.40800000, 0xe61000000101000000273108ac1c9a564046b6f3fdd4b83740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(25, 'Cantonment Thana', 'ক্যান্টনমেন্ট থানা', 'police_station', 'Dhaka Cantonment', NULL, '01320041700', '999', NULL, NULL, 23.83500000, 90.40000000, 0xe610000001010000009a99999999995640f6285c8fc2d53740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(26, 'Turag Thana', 'তুরাগ থানা', 'police_station', 'Turag, Dhaka', NULL, '01320041800', '999', NULL, NULL, 23.87800000, 90.38500000, 0xe61000000101000000713d0ad7a398564054e3a59bc4e03740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(27, 'Banani Thana', 'বনানী থানা', 'police_station', 'Banani, Dhaka', NULL, '01320041200', '999', NULL, NULL, 23.79370000, 90.40330000, 0xe61000000101000000849ecdaacf995640d0d556ec2fcb3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(28, 'Gulshan Thana', 'গুলশান থানা', 'police_station', 'Gulshan 1, Dhaka', NULL, '01320041150', '999', NULL, NULL, 23.77970000, 90.41540000, 0xe610000001010000008ab0e1e9959a5640f2b0506b9ac73740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(29, 'Uttara West Thana', 'উত্তরা পশ্চিম থানা', 'police_station', 'Sector 3, Uttara, Dhaka', NULL, '01320041550', '999', NULL, NULL, 23.86310000, 90.39210000, 0xe61000000101000000be30992a18995640772d211ff4dc3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(30, 'Dhanmondi Thana', 'ধানমন্ডি থানা', 'police_station', 'Road 6, Dhanmondi, Dhaka', NULL, '01320040300', '999', NULL, NULL, 23.74080000, 90.38110000, 0xe610000001010000005c2041f16398564076e09c11a5bd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(31, 'Tejgaon Thana', 'তেজগাঁও থানা', 'police_station', 'Tejgaon, Dhaka', NULL, '01320040400', '999', NULL, NULL, 23.75940000, 90.39080000, 0xe61000000101000000b7d100de029956401895d40968c23740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(32, 'Dhaka Medical College Hospital', 'ঢাকা মেডিকেল কলেজ হাসপাতাল', 'hospital', 'Bakshibazar, Dhaka', NULL, '02-55165088', '10655', NULL, NULL, 23.72510000, 90.39760000, 0xe61000000101000000ef3845477299564061545227a0b93740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(33, 'Bangabandhu Sheikh Mujib Medical University', 'বঙ্গবন্ধু শেখ মুজিব মেডিকেল বিশ্ববিদ্যালয়', 'hospital', 'Shahbagh, Dhaka', NULL, '02-55165760', '999', NULL, NULL, 23.73910000, 90.39560000, 0xe610000001010000000c93a982519956403e7958a835bd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(34, 'Kurmitola General Hospital', 'কুর্মিটোলা জেনারেল হাসপাতাল', 'hospital', 'Airport Road, Dhaka', NULL, '02-55062347', '999', NULL, NULL, 23.82410000, 90.41260000, 0xe610000001010000001895d409689a564034a2b437f8d23740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(35, 'Square Hospital', 'স্কয়ার হাসপাতাল', 'hospital', 'Panthapath, Dhaka', NULL, '02-48115951', '10616', NULL, NULL, 23.75310000, 90.38160000, 0xe61000000101000000d50968226c9856401b9e5e29cbc03740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(36, 'Evercare Hospital', 'এভারকেয়ার হাসপাতাল', 'hospital', 'Bashundhara R/A, Dhaka', NULL, '02-8431661', '10678', NULL, NULL, 23.81030000, 90.43120000, 0xe610000001010000004182e2c7989b5640e5f21fd26fcf3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(37, 'United Hospital', 'ইউনাইটেড হাসপাতাল', 'hospital', 'Gulshan 2, Dhaka', NULL, '02-9852466', '10666', NULL, NULL, 23.80410000, 90.41560000, 0xe61000000101000000ed0dbe30999a5640aeb6627fd9cd3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(38, 'Birdem General Hospital', 'বারডেম জেনারেল হাসপাতাল', 'hospital', 'Shahbagh, Dhaka', NULL, '02-9661551', '999', NULL, NULL, 23.73810000, 90.39590000, 0xe61000000101000000211ff46c56995640772d211ff4bc3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(39, 'National Heart Foundation', 'জাতীয় হৃদরোগ ফাউন্ডেশন', 'hospital', 'Mirpur-2, Dhaka', NULL, '02-58051355', '999', NULL, NULL, 23.80420000, 90.36210000, 0xe610000001010000006c787aa52c97564076711b0de0cd3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(40, 'Suhrawardy Medical College Hospital', 'শহীদ সোহরাওয়ার্দী মেডিকেল কলেজ হাসপাতাল', 'hospital', 'Sher-e-Bangla Nagar, Dhaka', NULL, '02-9130800', '999', NULL, NULL, 23.77050000, 90.37120000, 0xe610000001010000009d11a5bdc19756406891ed7c3fc53740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(41, 'Ibn Sina Specialized Hospital', 'ইবনে সিনা স্পেশালাইজড হাসপাতাল', 'hospital', 'Dhanmondi, Dhaka', NULL, '02-9121206', '10615', NULL, NULL, 23.74560000, 90.37230000, 0xe61000000101000000401361c3d3975640c9e53fa4dfbe3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(42, 'Labaid Specialized Hospital', 'ল্যাবএইড স্পেশালাইজড হাসপাতাল', 'hospital', 'Dhanmondi, Dhaka', NULL, '02-58610793', '10606', NULL, NULL, 23.74120000, 90.38240000, 0xe61000000101000000637fd93d7998564092cb7f48bfbd3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(43, 'Holy Family Red Crescent Hospital', 'হলি ফ্যামিলি রেড ক্রিসেন্ট হাসপাতাল', 'hospital', 'Eskaton, Dhaka', NULL, '02-48311721', '999', NULL, NULL, 23.74520000, 90.40210000, 0xe610000001010000002f6ea301bc995640adfa5c6dc5be3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(44, 'Sir Salimullah Medical College', 'স্যার সলিমুল্লাহ মেডিকেল কলেজ', 'hospital', 'Mitford, Dhaka', NULL, '02-57316104', '999', NULL, NULL, 23.71010000, 90.39950000, 0xe6100000010100000021b0726891995640bde3141dc9b53740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(45, 'National Institute of Cancer Research', 'জাতীয় ক্যান্সার গবেষণা ইনস্টিটিউট', 'hospital', 'Mohakhali, Dhaka', NULL, '02-9880078', '999', NULL, NULL, 23.77560000, 90.40120000, 0xe61000000101000000efc9c342ad99564011c7bab88dc63740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(46, 'Enam Medical College', 'এনাম মেডিকেল কলেজ', 'hospital', 'Savar (Dhaka Office)', NULL, '01716358146', '999', NULL, NULL, 23.84560000, 90.25820000, 0xe61000000101000000f1f44a5986905640637fd93d79d83740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(47, 'Delta Hospital', 'ডেল্টা হাসপাতাল', 'hospital', 'Mirpur-1, Dhaka', NULL, '02-58050442', '999', NULL, NULL, 23.79120000, 90.35230000, 0xe610000001010000005f984c158c9656405f984c158cca3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(48, 'Popular Specialized Hospital', 'পপুলার স্পেশালাইজড হাসপাতাল', 'hospital', 'Dhanmondi, Dhaka', NULL, '02-9669480', '10636', NULL, NULL, 23.74010000, 90.38310000, 0xe610000001010000003fc6dcb58498564005c58f3177bd3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(49, 'Central Hospital', 'সেন্ট্রাল হাসপাতাল', 'hospital', 'Green Road, Dhaka', NULL, '02-9660015', '999', NULL, NULL, 23.74410000, 90.38620000, 0xe61000000101000000c66d3480b79856401ff46c567dbe3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(50, 'Kidney Foundation Hospital', 'কিডনি ফাউন্ডেশন হাসপাতাল', 'hospital', 'Mirpur-2, Dhaka', NULL, '02-58054708', '999', NULL, NULL, 23.80550000, 90.36010000, 0xe6100000010100000089d2dee00b97564091ed7c3f35ce3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(51, 'Infectious Diseases Hospital', 'সংক্রামক ব্যাধি হাসপাতাল', 'hospital', 'Mohakhali, Dhaka', NULL, '02-9898155', '999', NULL, NULL, 23.77820000, 90.40250000, 0xe61000000101000000f6285c8fc299564048bf7d1d38c73740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:04', '2026-02-15 16:10:04'),
(52, 'Fire Service HQ', 'ফায়ার সার্ভিস সদর দপ্তর', 'fire_station', 'Kazi Alauddin Road, Dhaka', NULL, '02-223355555', '16163', NULL, NULL, 23.72250000, 90.40720000, 0xe6100000010100000099bb96900f9a5640295c8fc2f5b83740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(53, 'Mohammadpur Fire Station', 'মোহাম্মদপুর ফায়ার স্টেশন', 'fire_station', 'Mohammadpur, Dhaka', NULL, '02-9112222', '999', NULL, NULL, 23.75880000, 90.35880000, 0xe6100000010100000082734694f69656406e3480b740c23740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(54, 'Siddique Bazar Fire Station', 'সিদ্দিক বাজার ফায়ার স্টেশন', 'fire_station', 'Gulistan, Dhaka', NULL, '02-9555555', '999', NULL, NULL, 23.72350000, 90.40950000, 0xe6100000010100000091ed7c3f359a5640f0a7c64b37b93740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(55, 'Mirpur Fire Station', 'মিরপুর ফায়ার স্টেশন', 'fire_station', 'Mirpur-10, Dhaka', NULL, '02-9010214', '999', NULL, NULL, 23.80680000, 90.36880000, 0xe61000000101000000f2b0506b9a975640ad69de718ace3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(56, 'Kurmitola Fire Station', 'কুর্মিটোলা ফায়ার স্টেশন', 'fire_station', 'Airport Road, Dhaka', NULL, '02-8931111', '999', NULL, NULL, 23.82650000, 90.41320000, 0xe6100000010100000043ad69de719a5640dd24068195d33740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(57, 'Tejgaon Fire Station', 'তেজগাঁও ফায়ার স্টেশন', 'fire_station', 'Tejgaon Industrial Area, Dhaka', NULL, '02-9113333', '999', NULL, NULL, 23.76540000, 90.39540000, 0xe61000000101000000a835cd3b4e995640c05b2041f1c33740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(58, 'Khilgaon Fire Station', 'খিলগাঁও ফায়ার স্টেশন', 'fire_station', 'Khilgaon, Dhaka', NULL, '02-7212121', '999', NULL, NULL, 23.75350000, 90.43120000, 0xe610000001010000004182e2c7989b564037894160e5c03740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(59, 'Postagola Fire Station', 'পোস্তগোলা ফায়ার স্টেশন', 'fire_station', 'Postagola, Dhaka', NULL, '02-7440022', '999', NULL, NULL, 23.69230000, 90.43540000, 0xe610000001010000006b2bf697dd9b56405305a3923ab13740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(60, 'Lalbagh Fire Station', 'লালবাগ ফায়ার স্টেশন', 'fire_station', 'Lalbagh, Old Dhaka', NULL, '02-7313333', '999', NULL, NULL, 23.71880000, 90.38880000, 0xe61000000101000000d42b6519e2985640645ddc4603b83740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(61, 'Hazaribagh Fire Station', 'হাজারীবাগ ফায়ার স্টেশন', 'fire_station', 'Hazaribagh, Dhaka', NULL, '02-9661122', '999', NULL, NULL, 23.73450000, 90.36540000, 0xe61000000101000000567daeb66297564079e9263108bc3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(62, 'Uttara Fire Station', 'উত্তরা ফায়ার স্টেশন', 'fire_station', 'Sector-3, Uttara, Dhaka', NULL, '02-8911111', '999', NULL, NULL, 23.86450000, 90.39540000, 0xe61000000101000000a835cd3b4e9956405a643bdf4fdd3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(63, 'Savar Fire Station', 'সাভার ফায়ার স্টেশন', 'fire_station', 'Savar, Dhaka', NULL, '02-7741555', '999', NULL, NULL, 23.84120000, 90.26540000, 0xe61000000101000000f0164850fc9056402c6519e258d73740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(64, 'Palashbari Fire Station', 'পলাশবাড়ী ফায়ার স্টেশন', 'fire_station', 'Ashulia, Dhaka', NULL, '01730336644', '999', NULL, NULL, 23.91230000, 90.28540000, 0xe61000000101000000d1915cfe439256400b24287e8ce93740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(65, 'Demra Fire Station', 'ডেমরা ফায়ার স্টেশন', 'fire_station', 'Demra, Dhaka', NULL, '02-7500111', '999', NULL, NULL, 23.71230000, 90.44540000, 0xe61000000101000000dc68006f819c5640d8f0f44a59b63740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(66, 'Keraniganj Fire Station', 'কেরানীগঞ্জ ফায়ার স্টেশন', 'fire_station', 'Keraniganj, Dhaka', NULL, '02-7762222', '999', NULL, NULL, 23.68230000, 90.38540000, 0xe6100000010100000038f8c264aa985640910f7a36abae3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(67, 'Baridhara Fire Station', 'বারিধারা ফায়ার স্টেশন', 'fire_station', 'Baridhara, Dhaka', NULL, '02-9883333', '999', NULL, NULL, 23.80120000, 90.42540000, 0xe61000000101000000faedebc0399b5640228e75711bcd3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(68, 'Gulshan Fire Station', 'গুলশান ফায়ার স্টেশন', 'fire_station', 'Gulshan-2, Dhaka', NULL, '02-8822222', '999', NULL, NULL, 23.79230000, 90.41540000, 0xe610000001010000008ab0e1e9959a5640ed9e3c2cd4ca3740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(69, 'Purbachal Fire Station', 'পূর্বাচল ফায়ার স্টেশন', 'fire_station', 'Purbachal, Dhaka', NULL, '01730336699', '999', NULL, NULL, 23.83230000, 90.51540000, 0xe61000000101000000f0164850fca05640f775e09c11d53740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(70, 'Basundhara Fire Station', 'বসুন্ধরা ফায়ার স্টেশন', 'fire_station', 'Bashundhara R/A, Dhaka', NULL, '02-8401111', '999', NULL, NULL, 23.81540000, 90.43540000, 0xe610000001010000006b2bf697dd9b56408d28ed0dbed03740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(71, 'Tongi Fire Station', 'টঙ্গী ফায়ার স্টেশন', 'fire_station', 'Tongi, Dhaka Border', NULL, '02-9811111', '999', NULL, NULL, 23.89540000, 90.40540000, 0xe610000001010000001973d712f2995640a1d634ef38e53740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(72, 'Victim Support Centre', 'ভিকটিম সাপোর্ট সেন্টার', 'womens_helpdesk', 'Tejgaon Thana Complex, Dhaka', NULL, '01713398328', '109', NULL, NULL, 23.76020000, 90.39120000, 0xe610000001010000007e8cb96b09995640516b9a779cc23740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(73, 'National Helpline for Violence Against Women', 'জাতীয় নারী নির্যাতন প্রতিরোধ হেল্পলাইন', 'womens_helpdesk', 'Secretariat, Dhaka', NULL, '109', '109', NULL, NULL, 23.73120000, 90.40120000, 0xe61000000101000000efc9c342ad995640d0d556ec2fbb3740, '24/7', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(74, 'DMP Womens Help Desk', 'ডিএমপি নারী সহায়তা ডেস্ক', 'womens_helpdesk', 'DMP HQ, Minto Road', NULL, '01713373155', '999', NULL, NULL, 23.74310000, 90.40010000, 0xe610000001010000004bc8073d9b99564058a835cd3bbe3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(75, 'One Stop Crisis Centre (DMC)', 'ওয়ান স্টপ ক্রাইসিস সেন্টার (ডিএমসি)', 'womens_helpdesk', 'DMCH, Dhaka', NULL, '01711446544', '109', NULL, NULL, 23.72510000, 90.39760000, 0xe61000000101000000ef3845477299564061545227a0b93740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(76, 'Bangladesh Mahila Parishad', 'বাংলাদেশ মহিলা পরিষদ', 'womens_helpdesk', 'Sufia Kamal Bhaban, Segunbagicha', NULL, '02-9582156', '999', NULL, NULL, 23.73450000, 90.40670000, 0xe6100000010100000020d26f5f079a564079e9263108bc3740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(77, 'Naripokkho', 'নারীপক্ষ', 'womens_helpdesk', 'Dhanmondi, Dhaka', NULL, '02-9111457', '999', NULL, NULL, 23.74560000, 90.37560000, 0xe610000001010000002b1895d409985640c9e53fa4dfbe3740, '10:00-18:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(78, 'BNWLA Help Desk', 'বিএনডব্লিউএলএ হেল্প ডেস্ক', 'womens_helpdesk', 'Adabor, Dhaka', NULL, '02-9110031', '999', NULL, NULL, 23.77120000, 90.36210000, 0xe610000001010000006c787aa52c975640daacfa5c6dc53740, '09:00-17:00', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(79, 'Ain o Salish Kendra (ASK)', 'আইন ও সালিশ কেন্দ্র', 'womens_helpdesk', 'Lalmatia, Dhaka', NULL, '01711565638', '999', NULL, NULL, 23.75540000, 90.36870000, 0xe610000001010000004182e2c798975640fe65f7e461c13740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(80, 'BUP Womens Cell', 'বিইউপি নারী সেল', 'womens_helpdesk', 'Mirpur Cantonment', NULL, '01769021500', '999', NULL, NULL, 23.83780000, 90.35410000, 0xe61000000101000000dfe00b93a9965640bc96900f7ad63740, '08:00-16:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(81, 'BRAC Legal Aid Center', 'ব্র্যাক আইন সহায়তা কেন্দ্র', 'womens_helpdesk', 'Mohakhali, Dhaka', NULL, '01713063544', '999', NULL, NULL, 23.77820000, 90.40120000, 0xe61000000101000000efc9c342ad99564048bf7d1d38c73740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(82, 'Police Womens Network', 'পুলিশ উইমেন নেটওয়ার্ক', 'womens_helpdesk', 'Police HQ, Dhaka', NULL, '01713398300', '999', NULL, NULL, 23.72560000, 90.41230000, 0xe6100000010100000003098a1f639a564044faedebc0b93740, '24/7', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(83, 'Mohammadpur Womens Desk', 'মোহাম্মদপুর নারী ডেস্ক', 'womens_helpdesk', 'Mohammadpur Thana', NULL, '01320040200', '999', NULL, NULL, 23.76210000, 90.36510000, 0xe6100000010100000041f163cc5d975640174850fc18c33740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(84, 'Gulshan Womens Desk', 'গুলশান নারী ডেস্ক', 'womens_helpdesk', 'Gulshan Thana', NULL, '01320041150', '999', NULL, NULL, 23.77980000, 90.41550000, 0xe610000001010000003bdf4f8d979a5640ba6b09f9a0c73740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(85, 'Uttara Womens Desk', 'উত্তরা নারী ডেস্ক', 'womens_helpdesk', 'Uttara Thana', NULL, '01320041550', '999', NULL, NULL, 23.86320000, 90.39220000, 0xe61000000101000000705f07ce199956403ee8d9acfadc3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(86, 'Mirpur Womens Desk', 'মিরপুর নারী ডেস্ক', 'womens_helpdesk', 'Mirpur Thana', NULL, '01320041600', '999', NULL, NULL, 23.80690000, 90.36890000, 0xe61000000101000000a4dfbe0e9c975640742497ff90ce3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(87, 'Dhanmondi Womens Desk', 'ধানমন্ডি নারী ডেস্ক', 'womens_helpdesk', 'Dhanmondi Thana', NULL, '01320040300', '999', NULL, NULL, 23.74090000, 90.38120000, 0xe610000001010000000e4faf94659856403d9b559fabbd3740, '24/7', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(88, 'Shakti Foundation Helpdesk', 'শক্তি ফাউন্ডেশন হেল্পডেস্ক', 'womens_helpdesk', 'Mirpur-2, Dhaka', NULL, '02-9023456', '999', NULL, NULL, 23.80540000, 90.36230000, 0xe61000000101000000d0d556ec2f975640ca32c4b12ece3740, '09:00-18:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(89, 'ActionAid Womens Center', 'অ্যাকশনএইড নারী কেন্দ্র', 'womens_helpdesk', 'Gulshan, Dhaka', NULL, '02-8837111', '999', NULL, NULL, 23.79120000, 90.41230000, 0xe6100000010100000003098a1f639a56405f984c158cca3740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(90, 'Blast Legal Aid', 'ব্লাস্ট আইন সহায়তা', 'womens_helpdesk', 'Old Railway District Office', NULL, '02-9662344', '999', NULL, NULL, 23.72340000, 90.41560000, 0xe61000000101000000ed0dbe30999a564029ed0dbe30b93740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(91, 'Care Bangladesh Helpline', 'কেয়ার বাংলাদেশ হেল্পলাইন', 'womens_helpdesk', 'RAOWA Complex, Mohakhali', NULL, '02-9112344', '999', NULL, NULL, 23.77450000, 90.39560000, 0xe610000001010000000c93a9825199564083c0caa145c63740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(92, 'BRAC HQ', 'ব্র্যাক প্রধান কার্যালয়', 'ngo', '75 Mohakhali, Dhaka', NULL, '02-222281245', '999', NULL, NULL, 23.77760000, 90.40340000, 0xe6100000010100000036cd3b4ed19956409e5e29cb10c73740, '09:00-17:00', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(93, 'ASA NGO', 'আশা এনজিও', 'ngo', 'Shyamoli, Dhaka', NULL, '02-9116344', '999', NULL, NULL, 23.77120000, 90.36450000, 0xe6100000010100000017d9cef753975640daacfa5c6dc53740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(94, 'Proshika', 'প্রশিকা', 'ngo', 'Mirpur-2, Dhaka', NULL, '02-8016021', '999', NULL, NULL, 23.80560000, 90.36210000, 0xe610000001010000006c787aa52c97564058a835cd3bce3740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(95, 'BDRCS HQ', 'বাংলাদেশ রেড ক্রিসেন্ট সোসাইটি', 'ngo', 'Mogbazar, Dhaka', NULL, '02-9330188', '999', NULL, NULL, 23.75120000, 90.40340000, 0xe6100000010100000036cd3b4ed199564055c1a8a44ec03740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(96, 'CARE Bangladesh', 'কেয়ার বাংলাদেশ', 'ngo', 'Mohakhali, Dhaka', NULL, '02-9112344', '999', NULL, NULL, 23.77450000, 90.39560000, 0xe610000001010000000c93a9825199564083c0caa145c63740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(97, 'Save the Children', 'সেভ দ্য চিলড্রেন', 'ngo', 'Gulshan-2, Dhaka', NULL, '02-9861690', '999', NULL, NULL, 23.79450000, 90.41230000, 0xe6100000010100000003098a1f639a564008ac1c5a64cb3740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(98, 'ICDDR,B', 'আইসিডিডিআর,বি', 'ngo', 'Mohakhali, Dhaka', NULL, '02-9827001', '999', NULL, NULL, 23.77910000, 90.40450000, 0xe61000000101000000d9cef753e39956404850fc1873c73740, '24/7', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(99, 'Plan International', 'প্ল্যান ইন্টারন্যাশনাল', 'ngo', 'Gulshan-1, Dhaka', NULL, '02-9861440', '999', NULL, NULL, 23.78120000, 90.41560000, 0xe61000000101000000ed0dbe30999a56409ca223b9fcc73740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(100, 'ActionAid Bangladesh', 'অ্যাকশনএইড বাংলাদেশ', 'ngo', 'Gulshan, Dhaka', NULL, '02-8837111', '999', NULL, NULL, 23.79120000, 90.41230000, 0xe6100000010100000003098a1f639a56405f984c158cca3740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(101, 'World Vision', 'ওয়ার্ল্ড ভিশন', 'ngo', 'Gulshan-2, Dhaka', NULL, '02-9821001', '999', NULL, NULL, 23.80120000, 90.41560000, 0xe61000000101000000ed0dbe30999a5640228e75711bcd3740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(102, 'Oxfam Bangladesh', 'অক্সফাম বাংলাদেশ', 'ngo', 'Banani, Dhaka', NULL, '02-9852444', '999', NULL, NULL, 23.79340000, 90.40450000, 0xe61000000101000000d9cef753e39956407aa52c431ccb3740, '09:00-17:00', 1, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(103, 'Islamic Relief', 'ইসলামিক রিলিফ', 'ngo', 'Baridhara, Dhaka', NULL, '02-9842344', '999', NULL, NULL, 23.79450000, 90.42340000, 0xe61000000101000000174850fc189b564008ac1c5a64cb3740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(104, 'Christian Aid', 'ক্রিশ্চিয়ান এইড', 'ngo', 'Gulshan, Dhaka', NULL, '02-9856788', '999', NULL, NULL, 23.78450000, 90.41560000, 0xe61000000101000000ed0dbe30999a564046b6f3fdd4c83740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(105, 'WaterAid Bangladesh', 'ওয়াটারএইড বাংলাদেশ', 'ngo', 'Gulshan, Dhaka', NULL, '02-8815433', '999', NULL, NULL, 23.77890000, 90.41450000, 0xe610000001010000004a0c022b879a5640bada8afd65c73740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(106, 'Heifer International', 'হাইফার ইন্টারন্যাশনাল', 'ngo', 'Uttara, Dhaka', NULL, '02-8956744', '999', NULL, NULL, 23.86540000, 90.39560000, 0xe610000001010000000c93a982519956405af5b9da8add3740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(107, 'Friendship NGO', 'ফ্রেন্ডশিপ এনজিও', 'ngo', 'Baridhara, Dhaka', NULL, '02-9856433', '999', NULL, NULL, 23.79560000, 90.42560000, 0xe610000001010000005e4bc8073d9b564096b20c71accb3740, '09:00-17:00', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(108, 'Sajida Foundation', 'সাজিদা ফাউন্ডেশন', 'ngo', 'Gulshan, Dhaka', NULL, '02-9854322', '999', NULL, NULL, 23.77560000, 90.41560000, 0xe61000000101000000ed0dbe30999a564011c7bab88dc63740, '09:00-17:00', 0, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(109, 'DAM (Ahsania Mission)', 'ঢাকা আহ্ছানিয়া মিশন', 'ngo', 'Dhanmondi, Dhaka', NULL, '02-9123422', '999', NULL, NULL, 23.74560000, 90.38560000, 0xe610000001010000009b559fabad985640c9e53fa4dfbe3740, '09:00-17:00', 1, 1, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(110, 'Practical Action', 'প্র্যাকটিক্যাল অ্যাকশন', 'ngo', 'Uttara, Dhaka', NULL, '02-8954322', '999', NULL, NULL, 23.87560000, 90.39560000, 0xe610000001010000000c93a98251995640aa60545227e03740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05'),
(111, 'Muslim Aid', 'মুসলিম এইড', 'ngo', 'Mohammadpur, Dhaka', NULL, '02-9123411', '999', NULL, NULL, 23.75560000, 90.36560000, 0xe61000000101000000bada8afd659756408cdb68006fc13740, '09:00-17:00', 0, 0, 1, 0.0, 0, NULL, '2026-02-15 16:10:05', '2026-02-15 16:10:05');

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
(1, 1, 1, 'safety_warning', 'Street light out near Road 27', 'The street light near house 603 has been out for 2 days. Report to DCC.', 'Dhanmondi Road 27', 'low', 0, NULL, 15, 5, 'active', NULL, '2026-02-15 21:11:48'),
(2, 1, 3, 'suspicious_activity', 'Unknown person asking for directions', 'Be cautious. Someone was asking detailed questions about residents. Possibly surveying.', 'Dhanmondi block B', 'medium', 0, NULL, 28, 12, 'active', NULL, '2026-02-15 21:11:48'),
(3, 2, 5, 'general', 'Community meeting this Saturday', 'Safety watch meeting at Mirpur 10 community center, 4 PM.', 'Mirpur 10 Community Center', 'low', 1, NULL, 42, 18, 'active', NULL, '2026-02-15 21:11:48'),
(4, 3, 10, 'safety_warning', 'Uttara sector 3 - New safe route', 'We have mapped a well-lit route from metro to sector 3. Check the app.', 'Uttara Sector 3', 'low', 1, NULL, 35, 20, 'resolved', NULL, '2026-02-15 21:11:48'),
(5, 5, 9, 'emergency', 'Medical emergency - Resolved', 'Elderly neighbor had fall. Ambulance arrived. All good now.', 'Mohammadpur block C', 'high', 1, NULL, 55, 30, 'resolved', NULL, '2026-02-15 21:11:48');

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
(1, 1, 1, 'founder', '2026-02-15 20:26:03', 'active', 0),
(2, 1, 2, 'member', '2026-02-15 20:26:03', 'active', 0),
(3, 1, 3, 'moderator', '2026-02-15 20:26:03', 'active', 0),
(4, 2, 4, 'founder', '2026-02-15 20:26:03', 'active', 0),
(5, 2, 5, 'member', '2026-02-15 20:26:03', 'active', 0),
(6, 3, 10, 'founder', '2026-02-15 20:26:03', 'active', 0),
(7, 4, 7, 'member', '2026-02-15 20:26:03', 'active', 0),
(8, 5, 9, 'member', '2026-02-15 20:26:03', 'active', 0),
(9, 1, 6, 'member', '2026-02-15 21:11:47', 'active', 15),
(10, 1, 9, 'member', '2026-02-15 21:11:47', 'active', 8),
(11, 2, 8, 'member', '2026-02-15 21:11:47', 'active', 10),
(12, 3, 6, 'member', '2026-02-15 21:11:47', 'active', 5),
(13, 3, 7, 'member', '2026-02-15 21:11:47', 'active', 12),
(14, 4, 10, 'member', '2026-02-15 21:11:47', 'active', 7),
(15, 5, 6, 'moderator', '2026-02-15 21:11:47', 'active', 20);

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
(1, 'National Emergency', 'জাতীয় জরুরি সেবা', '999', 'emergency', 'Police, Fire, Ambulance', NULL, NULL, 1, '24/7', 1, 1, '2026-02-15 14:01:49'),
(2, 'Women Helpline', 'নারী ও শিশু সহায়তা', '109', 'womens_rights', 'Violence against women/children', NULL, NULL, 1, '24/7', 2, 1, '2026-02-15 14:01:49'),
(3, 'Government Info', 'সরকারি তথ্য সেবা', '333', 'emergency', 'Citizen services and info', NULL, NULL, 1, '24/7', 3, 1, '2026-02-15 14:01:49'),
(4, 'Child Helpline', 'চাইল্ড হেল্পলাইন', '1098', 'child_protection', 'Child rights and protection', NULL, NULL, 1, '24/7', 4, 1, '2026-02-15 14:01:49'),
(5, 'Legal Aid', 'আইনি সহায়তা', '16430', 'legal_aid', 'National legal aid services', NULL, NULL, 1, '24/7', 5, 1, '2026-02-15 14:01:49');

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
) ;

--
-- Dumping data for table `incident_reports`
--

INSERT INTO `incident_reports` (`id`, `user_id`, `title`, `description`, `category`, `severity`, `status`, `location_name`, `latitude`, `longitude`, `address`, `incident_date`, `reported_date`, `updated_date`, `resolved_at`, `is_anonymous`, `is_public`, `evidence_files`, `witness_count`, `response_time_minutes`, `assigned_to`) VALUES
(1, 9, 'Stalking near Dhanmondi market', 'Unknown male followed me from the market for 15 minutes. Lost him near the lake.', 'stalking', 'medium', 'under_review', 'Dhanmondi Market Area', 23.70158186, 90.42591610, 'Near Dhanmondi Lake, Dhaka', '2026-02-04 13:02:25', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(2, 5, 'Eve-teasing at bus stand', 'Verbal harassment while waiting for bus. Multiple witnesses.', 'stalking', 'low', 'resolved', 'Mirpur 10 Bus Stand', 23.76666909, 90.41712910, 'Mirpur 10, Dhaka', '2026-01-01 02:26:08', '2026-02-15 20:15:54', '2026-02-15 21:11:48', '2026-02-13 21:11:48', 0, 0, NULL, 0, NULL, NULL),
(3, 5, 'Pickpocket attempt at mall', 'Someone tried to take my phone. Security helped.', 'other', 'low', 'resolved', 'Bashundhara City Mall', 23.84021393, 90.38167970, 'Panthapath, Dhaka', '2026-02-04 22:45:19', '2026-02-15 20:15:54', '2026-02-15 21:11:48', '2026-02-13 21:11:48', 0, 0, NULL, 0, NULL, NULL),
(4, 5, 'Unwanted advances at workplace', 'Colleague making inappropriate comments. Reported to HR.', 'harassment', 'medium', 'under_review', 'Gulshan 2 Office', 23.78605621, 90.35299695, 'Gulshan 2, Dhaka', '2026-01-19 01:17:20', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(5, 10, 'Online harassment - Fake profile', 'Someone created fake social media profile with my photos.', 'cyberbullying', 'high', 'under_review', 'Online', 23.81711796, 90.39841179, 'Cyber incident', '2026-01-03 22:31:52', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(6, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'low', 'pending', 'Dhaka Metropolitan Area', 23.80098146, 90.37978952, 'Dhaka, Bangladesh', '2026-01-21 10:55:59', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(7, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'low', 'resolved', 'Dhaka Metropolitan Area', 23.76198158, 90.37642487, 'Dhaka, Bangladesh', '2026-01-03 14:46:03', '2026-02-15 20:15:54', '2026-02-15 21:11:48', '2026-02-13 21:11:48', 0, 0, NULL, 0, NULL, NULL),
(8, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'low', 'resolved', 'Dhaka Metropolitan Area', 23.76063662, 90.43157408, 'Dhaka, Bangladesh', '2026-02-08 21:10:59', '2026-02-15 20:15:54', '2026-02-15 21:11:48', '2026-02-13 21:11:48', 0, 0, NULL, 0, NULL, NULL),
(9, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'high', 'under_review', 'Dhaka Metropolitan Area', 23.72938437, 90.43908205, 'Dhaka, Bangladesh', '2026-02-08 15:49:50', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(10, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'medium', 'investigating', 'Dhaka Metropolitan Area', 23.82810457, 90.43302071, 'Dhaka, Bangladesh', '2026-01-27 10:53:54', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(11, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'low', 'pending', 'Dhaka Metropolitan Area', 23.83165114, 90.40809127, 'Dhaka, Bangladesh', '2026-01-12 14:45:32', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(12, 3, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'medium', 'under_review', 'Dhaka Metropolitan Area', 23.71975529, 90.36584622, 'Dhaka, Bangladesh', '2026-01-18 12:15:19', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(13, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'low', 'resolved', 'Dhaka Metropolitan Area', 23.79720174, 90.38544734, 'Dhaka, Bangladesh', '2026-02-07 01:52:40', '2026-02-15 20:15:54', '2026-02-15 21:11:48', '2026-02-13 21:11:48', 0, 0, NULL, 0, NULL, NULL),
(14, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'investigating', 'Dhaka Metropolitan Area', 23.83890923, 90.38412801, 'Dhaka, Bangladesh', '2026-02-11 14:48:49', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(15, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.83637057, 90.39193765, 'Dhaka, Bangladesh', '2026-01-16 14:08:29', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(16, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.80412488, 90.41967004, 'Dhaka, Bangladesh', '2026-01-18 21:57:29', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(17, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.83748660, 90.37291737, 'Dhaka, Bangladesh', '2026-01-18 07:02:09', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(18, 3, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'high', 'investigating', 'Dhaka Metropolitan Area', 23.70061210, 90.44044521, 'Dhaka, Bangladesh', '2026-01-23 20:04:53', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(19, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'high', 'pending', 'Dhaka Metropolitan Area', 23.83042149, 90.43614777, 'Dhaka, Bangladesh', '2026-02-01 21:50:55', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(20, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.72016573, 90.41131156, 'Dhaka, Bangladesh', '2026-01-30 11:19:38', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(21, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'low', 'pending', 'Dhaka Metropolitan Area', 23.74363728, 90.35711020, 'Dhaka, Bangladesh', '2026-01-22 04:48:44', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(22, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'low', 'pending', 'Dhaka Metropolitan Area', 23.76347875, 90.40562485, 'Dhaka, Bangladesh', '2026-01-23 21:21:00', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(23, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'high', 'pending', 'Dhaka Metropolitan Area', 23.70270541, 90.43665239, 'Dhaka, Bangladesh', '2026-01-12 19:01:53', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(24, 2, 'Wallet stolen on bus', 'Wallet taken from bag during rush hour. Lost 2000 BDT.', 'theft', 'high', 'investigating', 'Mirpur-Dhanmondi Route', 23.84332099, 90.44216495, 'Route 7 bus', '2026-02-03 22:39:38', '2026-02-15 20:15:54', '2026-02-15 21:11:48', NULL, 0, 0, NULL, 0, NULL, NULL),
(25, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'high', 'pending', 'Dhaka Metropolitan Area', 23.81297605, 90.40533534, 'Dhaka, Bangladesh', '2026-01-23 21:01:41', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(26, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'high', 'pending', 'Dhaka Metropolitan Area', 23.81828153, 90.41688897, 'Dhaka, Bangladesh', '2026-02-14 21:17:53', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(27, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'high', 'pending', 'Dhaka Metropolitan Area', 23.84872232, 90.43487920, 'Dhaka, Bangladesh', '2026-01-12 19:13:43', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(28, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'low', 'pending', 'Dhaka Metropolitan Area', 23.83145799, 90.39057422, 'Dhaka, Bangladesh', '2026-01-18 18:43:57', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(29, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.75469778, 90.39833650, 'Dhaka, Bangladesh', '2026-01-14 03:56:31', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(30, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'low', 'pending', 'Dhaka Metropolitan Area', 23.72427268, 90.44250419, 'Dhaka, Bangladesh', '2026-01-06 22:10:03', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(31, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.84157995, 90.36497410, 'Dhaka, Bangladesh', '2026-02-11 03:16:16', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(32, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.75617408, 90.41118642, 'Dhaka, Bangladesh', '2026-02-12 20:14:47', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(33, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.74799887, 90.39688943, 'Dhaka, Bangladesh', '2026-01-17 12:22:44', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(34, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.75643811, 90.35116751, 'Dhaka, Bangladesh', '2026-02-11 14:42:46', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(35, 3, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'high', 'pending', 'Dhaka Metropolitan Area', 23.81915742, 90.38183806, 'Dhaka, Bangladesh', '2026-01-09 02:07:37', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(36, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.81327214, 90.43492236, 'Dhaka, Bangladesh', '2026-02-14 08:32:12', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(37, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'low', 'pending', 'Dhaka Metropolitan Area', 23.84868751, 90.42537801, 'Dhaka, Bangladesh', '2026-02-05 17:08:45', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(38, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.78645979, 90.42145233, 'Dhaka, Bangladesh', '2026-02-07 01:45:53', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(39, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'low', 'pending', 'Dhaka Metropolitan Area', 23.80509825, 90.40432801, 'Dhaka, Bangladesh', '2026-01-28 10:36:57', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(40, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.82025563, 90.41007130, 'Dhaka, Bangladesh', '2026-01-27 04:33:46', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(41, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.73019008, 90.38149299, 'Dhaka, Bangladesh', '2026-02-13 21:49:36', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(42, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'low', 'pending', 'Dhaka Metropolitan Area', 23.74107836, 90.39035942, 'Dhaka, Bangladesh', '2026-01-09 18:30:34', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(43, 3, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'low', 'pending', 'Dhaka Metropolitan Area', 23.76911071, 90.38891758, 'Dhaka, Bangladesh', '2026-01-25 15:37:08', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(44, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.77965948, 90.42841095, 'Dhaka, Bangladesh', '2026-01-15 06:49:37', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(45, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.70716611, 90.44841917, 'Dhaka, Bangladesh', '2026-02-04 22:27:16', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(46, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.70548978, 90.40072076, 'Dhaka, Bangladesh', '2026-01-19 14:37:48', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(47, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'high', 'pending', 'Dhaka Metropolitan Area', 23.82639625, 90.43764609, 'Dhaka, Bangladesh', '2026-02-08 15:25:13', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(48, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.74361181, 90.40039062, 'Dhaka, Bangladesh', '2026-01-29 17:23:38', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(49, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'low', 'pending', 'Dhaka Metropolitan Area', 23.84192866, 90.38398623, 'Dhaka, Bangladesh', '2026-02-08 06:49:06', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(50, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'low', 'pending', 'Dhaka Metropolitan Area', 23.83321483, 90.43970531, 'Dhaka, Bangladesh', '2026-02-06 09:55:42', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(51, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'low', 'pending', 'Dhaka Metropolitan Area', 23.73316338, 90.42570210, 'Dhaka, Bangladesh', '2026-01-05 08:06:53', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(52, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'low', 'pending', 'Dhaka Metropolitan Area', 23.78533256, 90.41030047, 'Dhaka, Bangladesh', '2026-01-14 17:35:18', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(53, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'low', 'pending', 'Dhaka Metropolitan Area', 23.82731177, 90.39397479, 'Dhaka, Bangladesh', '2026-01-30 22:38:19', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(54, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.82556953, 90.40471654, 'Dhaka, Bangladesh', '2026-01-10 11:32:11', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(55, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.79539255, 90.41228231, 'Dhaka, Bangladesh', '2026-01-09 03:54:30', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(56, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'high', 'pending', 'Dhaka Metropolitan Area', 23.82319181, 90.40338103, 'Dhaka, Bangladesh', '2026-01-09 10:11:29', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(57, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.72905445, 90.40771106, 'Dhaka, Bangladesh', '2026-01-14 18:59:01', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(58, 1, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'low', 'pending', 'Dhaka Metropolitan Area', 23.81135513, 90.40637704, 'Dhaka, Bangladesh', '2026-01-27 06:25:06', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(59, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.80986557, 90.41095216, 'Dhaka, Bangladesh', '2026-02-08 10:09:00', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(60, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'low', 'pending', 'Dhaka Metropolitan Area', 23.79581286, 90.35209833, 'Dhaka, Bangladesh', '2026-01-08 21:07:42', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(61, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'low', 'pending', 'Dhaka Metropolitan Area', 23.74412315, 90.44263262, 'Dhaka, Bangladesh', '2026-02-03 23:12:16', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(62, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.84005024, 90.39479422, 'Dhaka, Bangladesh', '2026-01-20 20:23:34', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(63, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'high', 'pending', 'Dhaka Metropolitan Area', 23.77464518, 90.36863843, 'Dhaka, Bangladesh', '2026-01-20 15:15:45', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(64, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.84676168, 90.40753601, 'Dhaka, Bangladesh', '2026-02-12 23:33:43', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(65, 1, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'low', 'pending', 'Dhaka Metropolitan Area', 23.72867987, 90.40860872, 'Dhaka, Bangladesh', '2026-01-16 00:37:20', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(66, 1, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.83607396, 90.42992406, 'Dhaka, Bangladesh', '2026-01-12 23:25:17', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(67, 1, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.82682631, 90.39922677, 'Dhaka, Bangladesh', '2026-02-11 03:32:03', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(68, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'high', 'pending', 'Dhaka Metropolitan Area', 23.79865001, 90.39321858, 'Dhaka, Bangladesh', '2026-01-08 15:26:02', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(69, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.76944532, 90.37448861, 'Dhaka, Bangladesh', '2026-02-07 10:37:51', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(70, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.70710566, 90.39262611, 'Dhaka, Bangladesh', '2026-02-14 16:00:44', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(71, 4, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'low', 'pending', 'Dhaka Metropolitan Area', 23.84335231, 90.39929750, 'Dhaka, Bangladesh', '2026-01-27 12:14:42', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(72, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'low', 'pending', 'Dhaka Metropolitan Area', 23.74479770, 90.39507432, 'Dhaka, Bangladesh', '2026-01-16 10:28:40', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(73, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.78987741, 90.39073973, 'Dhaka, Bangladesh', '2026-01-11 23:24:00', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(74, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'high', 'pending', 'Dhaka Metropolitan Area', 23.78027296, 90.37095010, 'Dhaka, Bangladesh', '2026-01-20 13:57:41', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(75, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'low', 'pending', 'Dhaka Metropolitan Area', 23.84138729, 90.39462767, 'Dhaka, Bangladesh', '2026-01-18 16:18:16', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(76, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'high', 'pending', 'Dhaka Metropolitan Area', 23.82311185, 90.39069155, 'Dhaka, Bangladesh', '2026-01-26 15:22:58', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(77, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'low', 'pending', 'Dhaka Metropolitan Area', 23.70516357, 90.43227557, 'Dhaka, Bangladesh', '2025-12-31 14:01:20', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(78, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'high', 'pending', 'Dhaka Metropolitan Area', 23.84566617, 90.39195515, 'Dhaka, Bangladesh', '2026-01-08 15:55:27', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(79, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'high', 'pending', 'Dhaka Metropolitan Area', 23.82214816, 90.39875003, 'Dhaka, Bangladesh', '2026-02-14 12:14:40', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(80, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.73369863, 90.41374683, 'Dhaka, Bangladesh', '2026-01-23 15:42:23', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(81, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'low', 'pending', 'Dhaka Metropolitan Area', 23.75331895, 90.44091944, 'Dhaka, Bangladesh', '2026-01-22 16:05:20', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(82, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'low', 'pending', 'Dhaka Metropolitan Area', 23.77526630, 90.39097399, 'Dhaka, Bangladesh', '2026-01-24 11:42:12', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(83, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'assault', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.78800810, 90.40417814, 'Dhaka, Bangladesh', '2026-02-12 02:50:28', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(84, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.70380866, 90.37399592, 'Dhaka, Bangladesh', '2026-01-05 21:33:27', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(85, 2, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'low', 'pending', 'Dhaka Metropolitan Area', 23.72188241, 90.37095681, 'Dhaka, Bangladesh', '2026-01-28 10:08:03', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(86, 3, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'high', 'pending', 'Dhaka Metropolitan Area', 23.81981446, 90.39683284, 'Dhaka, Bangladesh', '2026-02-12 07:43:21', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(87, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'low', 'pending', 'Dhaka Metropolitan Area', 23.72724868, 90.38814111, 'Dhaka, Bangladesh', '2026-01-16 15:59:17', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(88, 3, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.74096272, 90.36971719, 'Dhaka, Bangladesh', '2026-01-07 05:47:46', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(89, 8, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'high', 'pending', 'Dhaka Metropolitan Area', 23.74426368, 90.41329475, 'Dhaka, Bangladesh', '2026-01-12 11:57:48', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(90, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'high', 'pending', 'Dhaka Metropolitan Area', 23.73448830, 90.39174890, 'Dhaka, Bangladesh', '2026-01-18 17:39:44', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(91, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.79842746, 90.37817756, 'Dhaka, Bangladesh', '2026-01-20 08:33:05', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(92, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.73599812, 90.43346652, 'Dhaka, Bangladesh', '2026-01-20 18:18:27', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(93, 5, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.81784781, 90.37899275, 'Dhaka, Bangladesh', '2026-01-04 14:14:49', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(94, 7, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'low', 'pending', 'Dhaka Metropolitan Area', 23.75666938, 90.35572108, 'Dhaka, Bangladesh', '2026-01-07 14:12:02', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(95, 6, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'cyberbullying', 'low', 'pending', 'Dhaka Metropolitan Area', 23.77427990, 90.35695671, 'Dhaka, Bangladesh', '2026-02-08 02:27:34', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(96, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'theft', 'high', 'pending', 'Dhaka Metropolitan Area', 23.72683646, 90.39091871, 'Dhaka, Bangladesh', '2026-01-23 07:38:35', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(97, 1, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'stalking', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.71958823, 90.42117313, 'Dhaka, Bangladesh', '2026-01-07 16:46:56', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(98, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.76535430, 90.44321983, 'Dhaka, Bangladesh', '2026-01-16 23:21:01', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(99, 9, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'harassment', 'low', 'pending', 'Dhaka Metropolitan Area', 23.70994915, 90.36191632, 'Dhaka, Bangladesh', '2026-01-18 15:02:24', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL),
(100, 10, 'Safety Concern Reported', 'Automatically generated safety report for system testing in Dhaka region.', 'other', 'medium', 'pending', 'Dhaka Metropolitan Area', 23.73186574, 90.35329120, 'Dhaka, Bangladesh', '2026-01-24 12:53:59', '2026-02-15 20:15:54', '2026-02-15 20:15:54', NULL, 0, 0, NULL, 0, NULL, NULL);

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
DELIMITER $$
CREATE TRIGGER `trg_after_report_insert` AFTER INSERT ON `incident_reports` FOR EACH ROW BEGIN
  INSERT INTO `notifications` (
    `user_id`, `title`, `message`, `type`, `action_url`, `created_at`
  )
  SELECT
    u.id,
    'New Incident Report',
    CONCAT('New ', NEW.category, ' report near ',
           COALESCE(NEW.location_name, 'unknown location')),
    'report',
    CONCAT('/admin_dashboard.php?report=', NEW.id),
    NOW()
  FROM `users` u
  WHERE u.is_admin = 1 AND u.is_active = 1;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_after_report_status_update` AFTER UPDATE ON `incident_reports` FOR EACH ROW BEGIN
  IF OLD.status != NEW.status THEN
    INSERT INTO `notifications` (
      `user_id`, `title`, `message`, `type`, `action_url`, `created_at`
    ) VALUES (
      NEW.user_id,
      'Report Status Updated',
      CONCAT('Your report "', LEFT(NEW.title, 50), '" is now: ', NEW.status),
      'update',
      CONCAT('/my_reports.php?id=', NEW.id),
      NOW()
    );
    INSERT INTO `audit_logs` (
      `user_id`, `action`, `table_name`, `record_id`,
      `old_values`, `new_values`, `created_at`
    ) VALUES (
      COALESCE(NEW.assigned_to, NEW.user_id),
      'STATUS_CHANGE',
      'incident_reports',
      NEW.id,
      JSON_OBJECT('status', OLD.status, 'severity', OLD.severity),
      JSON_OBJECT('status', NEW.status, 'severity', NEW.severity),
      NOW()
    );
  END IF;
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
(1, 'Dhaka Metropolitan Area', 'Dhaka, Bangladesh', 23.70158186, 90.42591610, 0xe61000000101000000bc7db1529c965640e35c9a8d5bbb3740, 1, 'safe', '2026-01-24 12:53:59', '2026-02-04 13:02:25', '2026-02-15 14:15:54', '2026-02-15 14:15:54'),
(101, 'Dhanmondi Lake Area', 'Dhanmondi, Dhaka', 23.74800000, 90.37800000, 0xe61000000101000000d578e92631985640736891ed7cbf3740, 12, 'moderate', '2026-02-13 21:11:48', '2026-01-16 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(102, 'Mirpur 10 Junction', 'Mirpur 10, Dhaka', 23.80700000, 90.36900000, 0xe61000000101000000560e2db29d9756403bdf4f8d97ce3740, 8, 'moderate', '2026-02-10 21:11:48', '2026-01-01 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(103, 'Uttara Sector 3', 'Uttara, Dhaka', 23.86300000, 90.39700000, 0xe61000000101000000c520b07268995640b0726891eddc3740, 4, 'safe', '2026-02-05 21:11:48', '2025-12-17 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(104, 'Gulshan 2 Circle', 'Gulshan 2, Dhaka', 23.79250000, 90.41620000, 0xe6100000010100000018265305a39a56407b14ae47e1ca3740, 3, 'safe', '2026-01-31 21:11:48', '2025-11-17 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(105, 'Mohammadpur Town Hall', 'Mohammadpur, Dhaka', 23.75950000, 90.36800000, 0xe61000000101000000643bdf4f8d975640df4f8d976ec23740, 6, 'moderate', '2026-02-12 21:11:48', '2026-01-26 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(106, 'Farmgate Bus Stand', 'Farmgate, Dhaka', 23.75400000, 90.38800000, 0xe6100000010100000046b6f3fdd49856401b2fdd2406c13740, 15, 'unsafe', '2026-02-14 21:11:48', '2026-01-21 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(107, 'Badda Madani Avenue', 'Badda, Dhaka', 23.79800000, 90.45000000, 0xe61000000101000000cdcccccccc9c56403f355eba49cc3740, 5, 'safe', '2026-02-08 21:11:48', '2026-01-06 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(108, 'Panthapath Bashundhara', 'Panthapath, Dhaka', 23.75100000, 90.39100000, 0xe610000001010000001b2fdd2406995640c74b378941c03740, 7, 'moderate', '2026-02-11 21:11:48', '2026-01-11 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(109, 'Tejgaon Industrial', 'Tejgaon, Dhaka', 23.76000000, 90.39000000, 0xe61000000101000000295c8fc2f5985640c3f5285c8fc23740, 9, 'moderate', '2026-02-09 21:11:48', '2025-12-27 21:11:48', '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(110, 'Kamalapur Rail Station Night', 'Kamalapur, Dhaka', 23.72950000, 90.42700000, 0xe6100000010100000017d9cef7539b5640986e1283c0ba3740, 22, 'unsafe', '2026-02-14 23:30:00', '2025-11-20 22:00:00', '2026-02-15 15:39:42', '2026-02-15 15:39:42'),
(111, 'Gabtoli Bus Terminal Late Night', 'Gabtoli, Dhaka', 23.76800000, 90.35500000, 0xe610000001010000001f85eb51b8965640f853e3a59bc43740, 18, 'unsafe', '2026-02-13 01:15:00', '2025-12-01 00:30:00', '2026-02-15 15:39:42', '2026-02-15 15:39:42'),
(112, 'Jatrabari Crossing', 'Jatrabari, Dhaka', 23.71800000, 90.43500000, 0xe61000000101000000a4703d0ad79b56402b8716d9ceb73740, 14, 'unsafe', '2026-02-12 21:45:00', '2026-01-05 20:00:00', '2026-02-15 15:39:42', '2026-02-15 15:39:42'),
(113, 'Karwan Bazar Night Market', 'Karwan Bazar, Dhaka', 23.75200000, 90.39000000, 0xe61000000101000000295c8fc2f59856408d976e1283c03740, 12, 'unsafe', '2026-02-11 23:00:00', '2026-01-15 22:30:00', '2026-02-15 15:39:42', '2026-02-15 15:39:42'),
(114, 'Demra Bus Stand Area', 'Demra, Dhaka', 23.71000000, 90.44500000, 0xe6100000010100000014ae47e17a9c5640f6285c8fc2b53740, 11, 'unsafe', '2026-02-10 22:20:00', '2026-01-20 21:00:00', '2026-02-15 15:39:42', '2026-02-15 15:39:42');

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
(1, 'Dhanmondi Metro Station', 'transportation', 23.75050000, 90.38550000, 0xe61000000101000000e9263108ac985640e3a59bc420c03740, 4.8, 'safe', NULL, 'Mirpur Road, Dhaka', NULL, NULL, NULL, '2026-02-15 14:19:40', '2026-02-15 14:19:40'),
(2, 'Bashundhara City Shopping Mall', 'shopping_mall', 23.75110000, 90.39080000, 0xe61000000101000000b7d100de029956408e06f01648c03740, 4.7, 'safe', NULL, 'Panthapath, Dhaka', NULL, NULL, NULL, '2026-02-15 14:19:40', '2026-02-15 14:19:40'),
(3, 'Ramna Park Main Gate', 'park', 23.73710000, 90.40100000, 0xe610000001010000008b6ce7fba9995640b1e1e995b2bc3740, 4.2, 'moderate', NULL, 'Ramna, Dhaka', NULL, NULL, NULL, '2026-02-15 14:19:40', '2026-02-15 14:19:40'),
(4, 'Jamuna Future Park', 'shopping_mall', 23.81350000, 90.42420000, 0xe61000000101000000a5bdc117269b5640c74b378941d03740, 4.8, 'safe', NULL, 'Pragati Sarani, Dhaka', NULL, NULL, NULL, '2026-02-15 14:19:40', '2026-02-15 14:19:40'),
(5, 'TSC, Dhaka University', 'education', 23.73310000, 90.39650000, 0xe610000001010000004c3789416099564096b20c71acbb3740, 4.5, 'safe', NULL, 'Nilkhet Road, Dhaka', NULL, NULL, NULL, '2026-02-15 14:19:40', '2026-02-15 14:19:40'),
(6, 'Dhanmondi Rd 2 ATM', 'other', 23.74520000, 90.37520000, 0xe61000000101000000645ddc4603985640adfa5c6dc5be3740, 4.2, 'safe', NULL, 'Dhanmondi Rd 2, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(7, 'Dhanmondi Rd 4 Shop', 'other', 23.74480000, 90.37680000, 0xe610000001010000008048bf7d1d985640910f7a36abbe3740, 4.3, 'safe', NULL, 'Dhanmondi Rd 4, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(8, 'Dhanmondi Rd 8 Park', 'park', 23.74420000, 90.37820000, 0xe6100000010100000039d6c56d34985640e6ae25e483be3740, 4.5, 'safe', NULL, 'Dhanmondi Rd 8, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(9, 'Dhanmondi Rd 12 Bus Stop', 'transportation', 23.74380000, 90.37950000, 0xe610000001010000003f355eba49985640cac342ad69be3740, 4.0, 'moderate', NULL, 'Dhanmondi Rd 12, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(10, 'Dhanmondi Rd 16 Market', 'other', 23.74320000, 90.38080000, 0xe610000001010000004694f6065f9856402063ee5a42be3740, 4.1, 'safe', NULL, 'Dhanmondi Rd 16, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(11, 'Mirpur 1 Crossing', 'transportation', 23.80120000, 90.36220000, 0xe610000001010000001ea7e8482e975640228e75711bcd3740, 4.0, 'moderate', NULL, 'Mirpur 1, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(12, 'Mirpur 2 Market', 'other', 23.80200000, 90.36350000, 0xe6100000010100000025068195439756405a643bdf4fcd3740, 4.2, 'safe', NULL, 'Mirpur 2, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(13, 'Mirpur 6 Bus Stand', 'transportation', 23.80280000, 90.36480000, 0xe610000001010000002c6519e258975640933a014d84cd3740, 3.9, 'moderate', NULL, 'Mirpur 6, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(14, 'Mirpur 10 Market', 'other', 23.80350000, 90.36600000, 0xe610000001010000008195438b6c97564004560e2db2cd3740, 4.3, 'safe', NULL, 'Mirpur 10, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(15, 'Mirpur 12 Park', 'park', 23.80420000, 90.36720000, 0xe61000000101000000d6c56d348097564076711b0de0cd3740, 4.1, 'safe', NULL, 'Mirpur 12, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(16, 'Uttara Sector 1 Gate', 'transportation', 23.85800000, 90.39200000, 0xe610000001010000000c022b8716995640cff753e3a5db3740, 4.6, 'safe', NULL, 'Uttara Sector 1, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(17, 'Uttara Sector 2 Market', 'other', 23.85880000, 90.39350000, 0xe6100000010100000077be9f1a2f99564007ce1951dadb3740, 4.5, 'safe', NULL, 'Uttara Sector 2, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(18, 'Uttara Sector 4 Park', 'park', 23.85950000, 90.39480000, 0xe610000001010000007e1d38674499564079e9263108dc3740, 4.7, 'safe', NULL, 'Uttara Sector 4, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(19, 'Uttara Sector 5 Bus', 'transportation', 23.86020000, 90.39600000, 0xe61000000101000000d34d621058995640ea04341136dc3740, 4.4, 'safe', NULL, 'Uttara Sector 5, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(20, 'Uttara Sector 7 ATM', 'other', 23.86100000, 90.39720000, 0xe61000000101000000287e8cb96b99564023dbf97e6adc3740, 4.5, 'safe', NULL, 'Uttara Sector 7, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(21, 'Gulshan 1 Circle', 'transportation', 23.77800000, 90.41000000, 0xe610000001010000000ad7a3703d9a5640ba490c022bc73740, 4.8, 'safe', NULL, 'Gulshan 1, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(22, 'Gulshan 2 Lake Side', 'park', 23.79200000, 90.41500000, 0xe61000000101000000c3f5285c8f9a5640986e1283c0ca3740, 4.7, 'safe', NULL, 'Gulshan 2, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(23, 'Banani 11 Market', 'other', 23.78600000, 90.40500000, 0xe6100000010100000052b81e85eb995640f0a7c64b37c93740, 4.6, 'safe', NULL, 'Banani 11, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(24, 'Banani C Block', 'other', 23.78700000, 90.40650000, 0xe61000000101000000bc749318049a5640b6f3fdd478c93740, 4.5, 'safe', NULL, 'Banani C Block, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(25, 'Mohakhali Bus Stand', 'transportation', 23.77850000, 90.37250000, 0xe61000000101000000a4703d0ad79756409eefa7c64bc73740, 4.1, 'moderate', NULL, 'Mohakhali, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(26, 'Farmgate Flyover', 'transportation', 23.75300000, 90.38600000, 0xe6100000010100000062105839b498564054e3a59bc4c03740, 4.0, 'moderate', NULL, 'Farmgate, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(27, 'Kawran Bazar', 'other', 23.75100000, 90.38400000, 0xe610000001010000007f6abc7493985640c74b378941c03740, 3.8, 'moderate', NULL, 'Kawran Bazar, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(28, 'Paltan Crossing', 'transportation', 23.73000000, 90.41200000, 0xe61000000101000000ee7c3f355e9a56407b14ae47e1ba3740, 3.9, 'moderate', NULL, 'Paltan, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(29, 'Motijheel CA', 'other', 23.72500000, 90.41800000, 0xe61000000101000000986e1283c09a56409a99999999b93740, 4.0, 'moderate', NULL, 'Motijheel, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(30, 'Badda Link Road', 'transportation', 23.79500000, 90.43800000, 0xe6100000010100000079e92631089c5640ec51b81e85cb3740, 4.3, 'safe', NULL, 'Badda, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(31, 'Rampura Bridge', 'transportation', 23.76200000, 90.42500000, 0xe6100000010100000033333333339b5640508d976e12c33740, 4.2, 'moderate', NULL, 'Rampura, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(32, 'Malibagh Chowdhury', 'other', 23.75800000, 90.43000000, 0xe61000000101000000ec51b81e859b5640355eba490cc23740, 4.1, 'moderate', NULL, 'Malibagh, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(33, 'Khilgaon Taltola', 'other', 23.75200000, 90.43500000, 0xe61000000101000000a4703d0ad79b56408d976e1283c03740, 4.0, 'moderate', NULL, 'Khilgaon, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(34, 'Shyamoli Square', 'transportation', 23.77500000, 90.36800000, 0xe61000000101000000643bdf4f8d9756406666666666c63740, 4.2, 'moderate', NULL, 'Shyamoli, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(35, 'Adabor Bus Stand', 'transportation', 23.74700000, 90.36500000, 0xe610000001010000008fc2f5285c975640ac1c5a643bbf3740, 4.1, 'moderate', NULL, 'Adabor, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(36, 'Mohammadpur Town', 'other', 23.76000000, 90.36700000, 0xe61000000101000000736891ed7c975640c3f5285c8fc23740, 4.2, 'moderate', NULL, 'Mohammadpur, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(37, 'Agargaon Taltola', 'transportation', 23.77700000, 90.37500000, 0xe610000001010000000000000000985640f4fdd478e9c63740, 4.3, 'safe', NULL, 'Agargaon, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(38, 'Sher-e-Bangla Nagar', 'other', 23.78200000, 90.38000000, 0xe61000000101000000b81e85eb51985640d578e92631c83740, 4.4, 'safe', NULL, 'Sher-e-Bangla Nagar, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(39, 'Tejgaon Industrial', 'other', 23.76400000, 90.38800000, 0xe6100000010100000046b6f3fdd4985640dd24068195c33740, 3.9, 'moderate', NULL, 'Tejgaon, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(40, 'Karwan Bazar', 'other', 23.75200000, 90.39000000, 0xe61000000101000000295c8fc2f59856408d976e1283c03740, 3.8, 'moderate', NULL, 'Karwan Bazar, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(41, 'Shahbagh Crossing', 'transportation', 23.73700000, 90.39500000, 0xe61000000101000000e17a14ae47995640e9263108acbc3740, 4.4, 'moderate', NULL, 'Shahbagh, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(42, 'Nilkhet Market', 'other', 23.73200000, 90.39800000, 0xe61000000101000000b6f3fdd47899564008ac1c5a64bb3740, 4.0, 'moderate', NULL, 'Nilkhet, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(43, 'New Market', 'other', 23.73000000, 90.40100000, 0xe610000001010000008b6ce7fba99956407b14ae47e1ba3740, 4.2, 'moderate', NULL, 'New Market, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(44, 'Azimpur Gate', 'transportation', 23.72600000, 90.40400000, 0xe6100000010100000060e5d022db99564060e5d022dbb93740, 4.1, 'moderate', NULL, 'Azimpur, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(45, 'Bashundhara Gate', 'transportation', 23.81500000, 90.42000000, 0xe610000001010000007b14ae47e19a5640713d0ad7a3d03740, 4.5, 'safe', NULL, 'Bashundhara, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(46, 'Kuratoli Intersection', 'transportation', 23.82000000, 90.42800000, 0xe6100000010100000008ac1c5a649b564052b81e85ebd13740, 4.4, 'safe', NULL, 'Kuratoli, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(47, 'NATORE Bus Stand', 'transportation', 23.75000000, 90.38200000, 0xe610000001010000009cc420b0729856400000000000c03740, 3.9, 'moderate', NULL, 'Farmgate, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(48, 'Gabtoli Bus Terminal', 'transportation', 23.76800000, 90.35500000, 0xe610000001010000001f85eb51b8965640f853e3a59bc43740, 3.7, 'moderate', NULL, 'Gabtoli, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(49, 'Mohammadpur Bus Stand', 'transportation', 23.76100000, 90.36600000, 0xe610000001010000008195438b6c975640894160e5d0c23740, 4.1, 'moderate', NULL, 'Mohammadpur, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(50, 'Jatrabari Crossing', 'transportation', 23.71800000, 90.43500000, 0xe61000000101000000a4703d0ad79b56402b8716d9ceb73740, 3.8, 'moderate', NULL, 'Jatrabari, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(51, 'Demra Bus Stand', 'transportation', 23.71000000, 90.44500000, 0xe6100000010100000014ae47e17a9c5640f6285c8fc2b53740, 3.9, 'moderate', NULL, 'Demra, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(52, 'Pallabi Market', 'other', 23.81200000, 90.35800000, 0xe61000000101000000f4fdd478e99656401d5a643bdfcf3740, 4.0, 'moderate', NULL, 'Pallabi, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(53, 'Kalshi Metro Area', 'transportation', 23.82500000, 90.37200000, 0xe610000001010000002b8716d9ce9756403333333333d33740, 4.4, 'safe', NULL, 'Kalshi, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(54, 'Bashundhara R/A Block A', 'other', 23.81800000, 90.42200000, 0xe610000001010000005eba490c029b5640c520b07268d13740, 4.6, 'safe', NULL, 'Bashundhara R/A, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(55, 'Bashundhara R/A Block B', 'other', 23.81900000, 90.42400000, 0xe610000001010000004260e5d0229b56408b6ce7fba9d13740, 4.5, 'safe', NULL, 'Bashundhara R/A, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(56, 'Baridhara DOHS', 'other', 23.80200000, 90.42000000, 0xe610000001010000007b14ae47e19a56405a643bdf4fcd3740, 4.8, 'safe', NULL, 'Baridhara, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(57, 'Niketon Gulshan', 'other', 23.79000000, 90.41200000, 0xe61000000101000000ee7c3f355e9a56400ad7a3703dca3740, 4.6, 'safe', NULL, 'Niketon, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(58, 'Badda H block', 'other', 23.78800000, 90.43200000, 0xe61000000101000000cff753e3a59b56407d3f355ebac93740, 4.4, 'safe', NULL, 'Badda, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(59, 'Badda Gulshan Link', 'transportation', 23.79200000, 90.43500000, 0xe61000000101000000a4703d0ad79b5640986e1283c0ca3740, 4.5, 'safe', NULL, 'Badda, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(60, 'Shantinagar', 'other', 23.74000000, 90.40800000, 0xe61000000101000000273108ac1c9a56403d0ad7a370bd3740, 4.0, 'moderate', NULL, 'Shantinagar, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(61, 'Moghbazar', 'other', 23.74500000, 90.41500000, 0xe61000000101000000c3f5285c8f9a56401f85eb51b8be3740, 4.1, 'moderate', NULL, 'Moghbazar, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(62, 'Wireless Gate', 'transportation', 23.74800000, 90.41800000, 0xe61000000101000000986e1283c09a5640736891ed7cbf3740, 4.2, 'moderate', NULL, 'Wireless, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(63, 'Baily Road', 'other', 23.73500000, 90.40500000, 0xe6100000010100000052b81e85eb9956405c8fc2f528bc3740, 4.1, 'moderate', NULL, 'Baily Road, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(64, 'Green Road', 'other', 23.74200000, 90.39200000, 0xe610000001010000000c022b8716995640cba145b6f3bd3740, 4.2, 'safe', NULL, 'Green Road, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(65, 'Panthapath Signal', 'transportation', 23.75050000, 90.38950000, 0xe61000000101000000b0726891ed985640e3a59bc420c03740, 4.3, 'safe', NULL, 'Panthapath, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(66, 'Russel Square', 'park', 23.74350000, 90.38650000, 0xe61000000101000000dbf97e6abc9856407593180456be3740, 4.4, 'safe', NULL, 'Dhanmondi, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(67, 'Satmasjid Road', 'other', 23.73800000, 90.37800000, 0xe61000000101000000d578e92631985640b0726891edbc3740, 4.2, 'moderate', NULL, 'Dhanmondi, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(68, 'Kalabagan', 'other', 23.74650000, 90.38250000, 0xe6100000010100000014ae47e17a985640c976be9f1abf3740, 4.3, 'safe', NULL, 'Kalabagan, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(69, 'Elephant Road', 'other', 23.73400000, 90.39850000, 0xe610000001010000002fdd24068199564096438b6ce7bb3740, 4.1, 'moderate', NULL, 'Elephant Road, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(70, 'Science Lab', 'other', 23.73650000, 90.39650000, 0xe610000001010000004c37894160995640068195438bbc3740, 4.3, 'safe', NULL, 'Science Lab, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(71, 'Mirpur Road 1', 'transportation', 23.75250000, 90.38450000, 0xe61000000101000000f853e3a59b985640713d0ad7a3c03740, 4.2, 'moderate', NULL, 'Mirpur Road, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(72, 'Mirpur Road 2', 'transportation', 23.75500000, 90.38300000, 0xe610000001010000008d976e1283985640e17a14ae47c13740, 4.1, 'moderate', NULL, 'Mirpur Road, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(73, 'Asad Gate', 'transportation', 23.75600000, 90.37900000, 0xe61000000101000000c74b378941985640a8c64b3789c13740, 4.2, 'moderate', NULL, 'Asad Gate, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(74, 'College Gate', 'transportation', 23.73250000, 90.40200000, 0xe610000001010000007d3f355eba995640ec51b81e85bb3740, 4.2, 'moderate', NULL, 'College Gate, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(75, 'Dhanmondi 15', 'other', 23.74100000, 90.38100000, 0xe61000000101000000aaf1d24d6298564004560e2db2bd3740, 4.4, 'safe', NULL, 'Dhanmondi 15, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(76, 'Dhanmondi 27 Area', 'other', 23.74450000, 90.37950000, 0xe610000001010000003f355eba499856403bdf4f8d97be3740, 4.5, 'safe', NULL, 'Dhanmondi 27, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(77, 'Dhanmondi 32 Lake', 'park', 23.74700000, 90.37750000, 0xe610000001010000005c8fc2f528985640ac1c5a643bbf3740, 4.6, 'safe', NULL, 'Dhanmondi 32, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(78, 'Shishu Park', 'park', 23.74900000, 90.39000000, 0xe61000000101000000295c8fc2f598564039b4c876bebf3740, 4.5, 'safe', NULL, 'Dhanmondi, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(79, 'Sobhanbag', 'other', 23.75400000, 90.37200000, 0xe610000001010000002b8716d9ce9756401b2fdd2406c13740, 4.2, 'safe', NULL, 'Sobhanbag, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(80, 'Monipur', 'other', 23.75700000, 90.37400000, 0xe610000001010000000e2db29def9756406f1283c0cac13740, 4.1, 'moderate', NULL, 'Monipur, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(81, 'Shewrapara', 'other', 23.77100000, 90.37000000, 0xe6100000010100000048e17a14ae9756404c37894160c53740, 4.2, 'moderate', NULL, 'Shewrapara, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(82, 'Kazupara', 'other', 23.77400000, 90.36800000, 0xe61000000101000000643bdf4f8d975640a01a2fdd24c63740, 4.1, 'moderate', NULL, 'Kazupara, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(83, 'Mirpur 14', 'other', 23.80500000, 90.36900000, 0xe61000000101000000560e2db29d975640ae47e17a14ce3740, 4.2, 'safe', NULL, 'Mirpur 14, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(84, 'Mirpur 14 Bus', 'transportation', 23.80550000, 90.36950000, 0xe61000000101000000cff753e3a597564091ed7c3f35ce3740, 4.0, 'moderate', NULL, 'Mirpur 14, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(85, 'Uttara Sector 6', 'other', 23.86050000, 90.39850000, 0xe610000001010000002fdd2406819956403f355eba49dc3740, 4.5, 'safe', NULL, 'Uttara Sector 6, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(86, 'Uttara Sector 8', 'other', 23.86150000, 90.39950000, 0xe6100000010100000021b0726891995640068195438bdc3740, 4.6, 'safe', NULL, 'Uttara Sector 8, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(87, 'Uttara Sector 10', 'other', 23.86250000, 90.40050000, 0xe610000001010000001283c0caa1995640cdccccccccdc3740, 4.5, 'safe', NULL, 'Uttara Sector 10, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(88, 'Uttara Sector 12', 'other', 23.86350000, 90.40150000, 0xe6100000010100000004560e2db2995640931804560edd3740, 4.6, 'safe', NULL, 'Uttara Sector 12, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(89, 'Uttara Sector 14', 'other', 23.86450000, 90.40250000, 0xe61000000101000000f6285c8fc29956405a643bdf4fdd3740, 4.5, 'safe', NULL, 'Uttara Sector 14, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(90, 'Gulshan 1 Block A', 'other', 23.77900000, 90.41100000, 0xe61000000101000000fca9f1d24d9a56408195438b6cc73740, 4.7, 'safe', NULL, 'Gulshan 1, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(91, 'Gulshan 1 Block B', 'other', 23.77950000, 90.41150000, 0xe6100000010100000075931804569a5640643bdf4f8dc73740, 4.8, 'safe', NULL, 'Gulshan 1, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(92, 'Gulshan 2 Block C', 'other', 23.79300000, 90.41650000, 0xe610000001010000002db29defa79a56405eba490c02cb3740, 4.7, 'safe', NULL, 'Gulshan 2, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(93, 'Gulshan 2 Block D', 'other', 23.79350000, 90.41700000, 0xe61000000101000000a69bc420b09a56404260e5d022cb3740, 4.6, 'safe', NULL, 'Gulshan 2, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(94, 'Banani Block A', 'other', 23.78650000, 90.40550000, 0xe61000000101000000cba145b6f3995640d34d621058c93740, 4.6, 'safe', NULL, 'Banani, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(95, 'Banani Block B', 'other', 23.78750000, 90.40650000, 0xe61000000101000000bc749318049a56409a99999999c93740, 4.5, 'safe', NULL, 'Banani, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(96, 'Badda A Block', 'other', 23.78600000, 90.43100000, 0xe61000000101000000dd240681959b5640f0a7c64b37c93740, 4.4, 'safe', NULL, 'Badda, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(97, 'Badda B Block', 'other', 23.78700000, 90.43200000, 0xe61000000101000000cff753e3a59b5640b6f3fdd478c93740, 4.5, 'safe', NULL, 'Badda, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(98, 'Rampura Bazar', 'other', 23.76100000, 90.42400000, 0xe610000001010000004260e5d0229b5640894160e5d0c23740, 4.1, 'moderate', NULL, 'Rampura, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(99, 'Rampura Bridge North', 'transportation', 23.76250000, 90.42550000, 0xe61000000101000000ac1c5a643b9b56403333333333c33740, 4.2, 'moderate', NULL, 'Rampura, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(100, 'Bashabo', 'other', 23.72800000, 90.42800000, 0xe6100000010100000008ac1c5a649b5640ee7c3f355eba3740, 3.9, 'moderate', NULL, 'Bashabo, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(101, 'Kodomtoli', 'other', 23.72200000, 90.43200000, 0xe61000000101000000cff753e3a59b564046b6f3fdd4b83740, 3.8, 'moderate', NULL, 'Kodomtoli, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(102, 'Sutrapur', 'other', 23.71600000, 90.42000000, 0xe610000001010000007b14ae47e19a56409eefa7c64bb73740, 3.7, 'moderate', NULL, 'Sutrapur, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(103, 'Lalbagh', 'other', 23.71200000, 90.40800000, 0xe61000000101000000273108ac1c9a564083c0caa145b63740, 3.9, 'moderate', NULL, 'Lalbagh, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(104, 'Chawkbazar', 'other', 23.70800000, 90.41200000, 0xe61000000101000000ee7c3f355e9a56406891ed7c3fb53740, 3.8, 'moderate', NULL, 'Chawkbazar, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(105, 'Saidabad', 'other', 23.72000000, 90.43800000, 0xe6100000010100000079e92631089c5640b81e85eb51b83740, 3.9, 'moderate', NULL, 'Saidabad, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(106, 'Matuail', 'transportation', 23.70500000, 90.44800000, 0xe61000000101000000e9263108ac9c564014ae47e17ab43740, 3.8, 'moderate', NULL, 'Matuail, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(107, 'Signboard', 'transportation', 23.69800000, 90.45200000, 0xe61000000101000000b0726891ed9c5640a69bc420b0b23740, 3.7, 'moderate', NULL, 'Signboard, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(108, 'Badda C Block', 'other', 23.78800000, 90.43300000, 0xe61000000101000000c1caa145b69b56407d3f355ebac93740, 4.4, 'safe', NULL, 'Badda, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(109, 'Panthapath Square', 'other', 23.75150000, 90.39050000, 0xe61000000101000000a245b6f3fd985640aaf1d24d62c03740, 4.3, 'safe', NULL, 'Panthapath, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(110, 'Dhanmondi 5A', 'other', 23.74400000, 90.37700000, 0xe61000000101000000e3a59bc4209856405839b4c876be3740, 4.4, 'safe', NULL, 'Dhanmondi, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(111, 'Mirpur 11', 'other', 23.80380000, 90.36650000, 0xe61000000101000000fa7e6abc74975640598638d6c5cd3740, 4.2, 'safe', NULL, 'Mirpur 11, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(112, 'Uttara Sector 9', 'other', 23.86200000, 90.40000000, 0xe610000001010000009a99999999995640e9263108acdc3740, 4.5, 'safe', NULL, 'Uttara Sector 9, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(113, 'Gulshan Avenue', 'transportation', 23.78100000, 90.41300000, 0xe61000000101000000df4f8d976e9a56400e2db29defc73740, 4.7, 'safe', NULL, 'Gulshan, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(114, 'Banani Club', 'other', 23.78850000, 90.40800000, 0xe61000000101000000273108ac1c9a564060e5d022dbc93740, 4.6, 'safe', NULL, 'Banani, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(115, 'Shyamoli 10', 'other', 23.77450000, 90.36750000, 0xe61000000101000000ec51b81e8597564083c0caa145c63740, 4.2, 'moderate', NULL, 'Shyamoli, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(116, 'Tejgaon Link Road', 'transportation', 23.76500000, 90.38900000, 0xe6100000010100000037894160e5985640a4703d0ad7c33740, 4.0, 'moderate', NULL, 'Tejgaon, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(117, 'Mohakhali Flyover', 'transportation', 23.77900000, 90.37300000, 0xe610000001010000001d5a643bdf9756408195438b6cc73740, 4.2, 'moderate', NULL, 'Mohakhali, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(118, 'Nilkhet Corner', 'other', 23.73300000, 90.39750000, 0xe610000001010000003d0ad7a370995640cff753e3a5bb3740, 4.1, 'moderate', NULL, 'Nilkhet, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(119, 'Dhanmondi 2A', 'other', 23.74550000, 90.37550000, 0xe6100000010100000079e9263108985640022b8716d9be3740, 4.3, 'safe', NULL, 'Dhanmondi, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(120, 'Mirpur 3 Market', 'other', 23.80150000, 90.36280000, 0xe6100000010100000048bf7d1d3897564077be9f1a2fcd3740, 4.1, 'moderate', NULL, 'Mirpur 3, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(121, 'Uttara J block', 'other', 23.85750000, 90.39150000, 0xe61000000101000000931804560e995640ec51b81e85db3740, 4.6, 'safe', NULL, 'Uttara, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(122, 'Gulshan 2 North', 'other', 23.79400000, 90.41750000, 0xe610000001010000001f85eb51b89a56402506819543cb3740, 4.6, 'safe', NULL, 'Gulshan 2, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(123, 'Bashundhara Block C', 'other', 23.82000000, 90.42600000, 0xe6100000010100000025068195439b564052b81e85ebd13740, 4.5, 'safe', NULL, 'Bashundhara, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(124, 'Farmgate South', 'other', 23.75250000, 90.38700000, 0xe6100000010100000054e3a59bc4985640713d0ad7a3c03740, 4.0, 'moderate', NULL, 'Farmgate, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(125, 'Dhanmondi 9', 'other', 23.74350000, 90.37850000, 0xe610000001010000004e621058399856407593180456be3740, 4.4, 'safe', NULL, 'Dhanmondi 9, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(126, 'Mirpur 7', 'other', 23.80300000, 90.36520000, 0xe61000000101000000f31fd26f5f97564021b0726891cd3740, 4.1, 'moderate', NULL, 'Mirpur 7, Dhaka', NULL, NULL, NULL, '2026-02-15 15:11:06', '2026-02-15 15:11:06'),
(127, 'Uttara North Metro Station', 'transportation', 23.87000000, 90.39800000, 0xe61000000101000000b6f3fdd4789956401f85eb51b8de3740, 4.9, 'safe', 'MRT Line 6 - North end', 'Uttara Sector 15, Dhaka', NULL, '6AM-10PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(128, 'Farmgate Metro Station', 'transportation', 23.75450000, 90.38750000, 0xe61000000101000000cdcccccccc985640fed478e926c13740, 4.3, 'moderate', 'MRT Line 6 - Central', 'Farmgate, Dhaka', NULL, '6AM-10PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(129, 'Agargaon Metro Station', 'transportation', 23.77700000, 90.37700000, 0xe61000000101000000e3a59bc420985640f4fdd478e9c63740, 4.6, 'safe', 'MRT Line 6', 'Agargaon, Dhaka', NULL, '6AM-10PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(130, 'Shahbagh Metro Station', 'transportation', 23.73800000, 90.39700000, 0xe61000000101000000c520b07268995640b0726891edbc3740, 4.4, 'moderate', 'MRT Line 6', 'Shahbagh, Dhaka', NULL, '6AM-10PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(131, 'Farmgate Bus Stand', 'transportation', 23.75400000, 90.38800000, 0xe6100000010100000046b6f3fdd49856401b2fdd2406c13740, 3.8, 'moderate', 'Main bus interchange', 'Farmgate, Dhaka', NULL, '24/7', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(132, 'Mirpur 10 Bus Stand', 'transportation', 23.80650000, 90.36900000, 0xe61000000101000000560e2db29d9756405839b4c876ce3740, 4.2, 'moderate', 'Mirpur route buses', 'Mirpur 10, Dhaka', NULL, '5AM-11PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(133, 'Gulshan 2 Lake Park', 'park', 23.79500000, 90.41800000, 0xe61000000101000000986e1283c09a5640ec51b81e85cb3740, 4.7, 'safe', 'Lakeside walking path', 'Gulshan 2, Dhaka', NULL, '6AM-8PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(134, 'Suhrawardy Udyan', 'park', 23.73600000, 90.39600000, 0xe61000000101000000d34d62105899564023dbf97e6abc3740, 4.5, 'safe', 'Historic park, well-lit', 'Shahbagh, Dhaka', NULL, '6AM-9PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(135, 'Banani Lake', 'park', 23.78800000, 90.40800000, 0xe61000000101000000273108ac1c9a56407d3f355ebac93740, 4.4, 'safe', 'Walking area', 'Banani, Dhaka', NULL, '6AM-7PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(136, 'Brac University Campus', 'education', 23.75000000, 90.37200000, 0xe610000001010000002b8716d9ce9756400000000000c03740, 4.8, 'safe', 'University security', 'Mohakhali, Dhaka', NULL, '8AM-8PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(137, 'NSU Campus', 'education', 23.81700000, 90.42500000, 0xe6100000010100000033333333339b5640fed478e926d13740, 4.7, 'safe', 'North South University', 'Bashundhara, Dhaka', NULL, '8AM-10PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(138, 'DBBL ATM Dhanmondi 27', 'other', 23.74600000, 90.38000000, 0xe61000000101000000b81e85eb51985640e5d022dbf9be3740, 4.2, 'safe', '24/7 ATM, CCTV', 'Road 27, Dhanmondi', NULL, '24/7', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(139, 'Bashundhara City ATM Zone', 'other', 23.75100000, 90.39100000, 0xe610000001010000001b2fdd2406995640c74b378941c03740, 4.6, 'safe', 'Mall entrance area', 'Panthapath, Dhaka', NULL, '10AM-9PM', NULL, '2026-02-15 15:11:48', '2026-02-15 15:11:48');

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
(1, 'Bangladesh Legal Aid and Services Trust (BLAST)', NULL, '01715220022', 'mail@blast.org.bd', '1/1, Pioneer Road, Kakrail, Dhaka', 'Dhaka', NULL, NULL, 'criminal,family,women_rights,human_rights', 'bn,en', 'free', 1, NULL, 0.00, 0, 0, 0.00, NULL, 0, 'active', '2026-02-15 20:18:27', '2026-02-15 20:18:27'),
(2, 'Ain o Salish Kendra (ASK)', NULL, '01711632510', 'ask@citechco.net', '2/16, Block-B, Lalmatia, Dhaka', 'Dhaka', NULL, NULL, 'women_rights,human_rights,labor', 'bn,en', 'pro_bono', 1, NULL, 0.00, 0, 0, 0.00, NULL, 0, 'active', '2026-02-15 20:18:27', '2026-02-15 20:18:27'),
(3, 'National Legal Aid Services Organization', NULL, '16430', 'nlaso@gmail.com', '145, New Baily Road, Dhaka', 'Dhaka', NULL, NULL, 'criminal,civil,family,property', 'bn,en', 'free', 1, NULL, 0.00, 0, 0, 0.00, NULL, 0, 'active', '2026-02-15 20:18:27', '2026-02-15 20:18:27'),
(4, 'Bangladesh National Woman Lawyers Association (BNWLA)', NULL, '01711223344', 'bnwla@bdonline.com', 'Road 27, House 603, Dhanmondi, Dhaka', 'Dhaka', NULL, NULL, 'women_rights,cyber,family', 'bn,en', 'low_cost', 1, NULL, 0.00, 0, 0, 0.00, NULL, 0, 'active', '2026-02-15 20:18:27', '2026-02-15 20:18:27');

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
(1, 4, 15, 4, 'initial', 'Assault case - Legal options', 'Need advice on filing a case for workplace harassment.', NULL, NULL, 'scheduled', '2026-02-18 21:11:48', NULL, NULL, NULL, NULL, 0.00, '2026-02-15 21:11:48'),
(2, 9, 9, 4, 'initial', 'Stalking - Restraining order', 'Someone has been following me. Need legal protection.', NULL, NULL, 'requested', NULL, NULL, NULL, NULL, NULL, 500.00, '2026-02-15 21:11:48'),
(3, 2, 24, 1, 'follow_up', 'Theft case follow-up', 'Checking status of my police report.', NULL, NULL, 'completed', '2026-02-13 21:11:48', NULL, NULL, NULL, NULL, 0.00, '2026-02-15 21:11:48');

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
(1, 'GD (General Diary) Sample Form', 'template', 'Police Matters', 'A standard template for filing a GD in any police station in Bangladesh.', NULL, NULL, 'both', 0, 0, NULL, 'active', '2026-02-15 20:20:16'),
(2, 'Nari o Shishu Nirjatan Case Guide', 'guideline', 'Women Rights', 'Steps to follow for filing a case under the Women and Children Repression Prevention Act.', NULL, NULL, 'bn', 0, 0, NULL, 'active', '2026-02-15 20:20:16'),
(3, 'Cyber Crime Complaint Process', 'guideline', 'Cyber Safety', 'Official guide to reporting online harassment to the Cyber Crime Investigation Division.', NULL, NULL, 'bn', 0, 0, NULL, 'active', '2026-02-15 20:20:16'),
(4, 'Digital Security Act Reference', 'law_reference', 'General Law', 'A simplified summary of important clauses for common citizens.', NULL, NULL, 'en', 0, 0, NULL, 'active', '2026-02-15 20:20:16'),
(5, 'Legal Aid Application Form', 'form', 'Legal Aid', 'Official form to apply for government legal aid services.', NULL, NULL, 'bn', 0, 0, NULL, 'active', '2026-02-15 20:20:16');

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
(1, 'Dhaka Medical College Hospital', 'hospital', 'Emergency, Trauma, Surgery', '01711223344', NULL, NULL, 'Ramna, Dhaka', 'Dhaka', NULL, NULL, 1, 'bn,en', 0, NULL, 'free', 0.00, 0, 1, NULL, 'active', '2026-02-15 20:18:41', '2026-02-15 20:18:41'),
(2, 'United Hospital Limited', 'hospital', 'Emergency, Cardiac, ICU', '10666', NULL, NULL, 'Plot 15, Road 71, Gulshan 2, Dhaka', 'Dhaka', NULL, NULL, 1, 'bn,en', 0, NULL, 'premium', 0.00, 0, 1, NULL, 'active', '2026-02-15 20:18:41', '2026-02-15 20:18:41'),
(3, 'Apollo/Evercare Hospital', 'hospital', 'Trauma, Neurology, Emergency', '01713041234', NULL, NULL, 'Plot 81, Block E, Bashundhara R/A, Dhaka', 'Dhaka', NULL, NULL, 1, 'bn,en', 0, NULL, 'premium', 0.00, 0, 1, NULL, 'active', '2026-02-15 20:18:41', '2026-02-15 20:18:41'),
(4, 'Moner Bondhu (Mental Health)', 'ngo', 'Counseling, Trauma Support, Mental Health', '01776632344', NULL, NULL, 'Banani, Dhaka', 'Dhaka', NULL, NULL, 0, 'bn,en', 0, NULL, 'subsidized', 0.00, 0, 1, NULL, 'active', '2026-02-15 20:18:41', '2026-02-15 20:18:41'),
(5, 'National Institute of Traumatology (NITOR)', 'hospital', 'Orthopedic, Trauma', '02-9112150', NULL, NULL, 'Sher-e-Bangla Nagar, Dhaka', 'Dhaka', NULL, NULL, 1, 'bn,en', 0, NULL, 'free', 0.00, 0, 1, NULL, 'active', '2026-02-15 20:18:41', '2026-02-15 20:18:41');

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
(1, 'Dhanmondi Safety Watch', 'A community group for Dhanmondi residents to share safety updates.', 'Dhanmondi', NULL, NULL, 1, 1, 1, 1, 5, 4, 0, NULL, 'active', 'public', NULL, '2026-02-15 20:17:38', '2026-02-15 21:11:47'),
(2, 'Mirpur Resident Network', 'Connecting neighbors in Mirpur for a safer environment.', 'Mirpur', NULL, NULL, 1, 1, 2, 2, 3, 3, 0, NULL, 'active', 'public', NULL, '2026-02-15 20:17:38', '2026-02-15 21:11:47'),
(3, 'Uttara Security Forum', 'Discussing security measures and reporting incidents in Uttara.', 'Uttara', NULL, NULL, 1, 1, 3, 3, 3, 3, 0, NULL, 'active', 'public', NULL, '2026-02-15 20:17:38', '2026-02-15 21:11:47'),
(4, 'Gulshan Community Care', 'A private group for Gulshan residents to ensure collective safety.', 'Gulshan', NULL, NULL, 1, 1, 4, 4, 2, 2, 0, NULL, 'active', 'invite_only', NULL, '2026-02-15 20:17:38', '2026-02-15 21:11:47'),
(5, 'Mohammadpur Alert Group', 'Real-time alerts and support for Mohammadpur area.', 'Mohammadpur', NULL, NULL, 1, 1, 6, 5, 3, 3, 0, NULL, 'active', 'public', NULL, '2026-02-15 20:17:38', '2026-02-15 21:11:47');

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
(1, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(2, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(3, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(4, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(5, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(6, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(7, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(8, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(9, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(10, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(11, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(12, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(13, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(14, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(15, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(16, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(17, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(18, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(19, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(20, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(21, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(22, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(23, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(24, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(25, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(26, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(27, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(28, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(29, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(30, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(31, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(32, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(33, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(34, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(35, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(36, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(37, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(38, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(39, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(40, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(41, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(42, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(43, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(44, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(45, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(46, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(47, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(48, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(49, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(50, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(51, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(52, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(53, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(54, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(55, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(56, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(57, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(58, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(59, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(60, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(61, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(62, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(63, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(64, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(65, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(66, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(67, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(68, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(69, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(70, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(71, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(72, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(73, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(74, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(75, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(76, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(77, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(78, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(79, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(80, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(81, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(82, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(83, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(84, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(85, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(86, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(87, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(88, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(89, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(90, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(91, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(92, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(93, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(94, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(95, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(96, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(97, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(98, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(99, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(100, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(101, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(102, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(103, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(104, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(105, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(106, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(107, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(108, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(109, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(110, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(111, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(112, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(113, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(114, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(115, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(116, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(117, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(118, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(119, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(120, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:06', NULL, NULL),
(121, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(122, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(123, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(124, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(125, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(126, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(127, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(128, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(129, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(130, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(131, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(132, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(133, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(134, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(135, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(136, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(137, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(138, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(139, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(140, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(141, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(142, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(143, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(144, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(145, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(146, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(147, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(148, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(149, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(150, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(151, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(152, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(153, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(154, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(155, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(156, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(157, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(158, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(159, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(160, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(161, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(162, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(163, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(164, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(165, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(166, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(167, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(168, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(169, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(170, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(171, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(172, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(173, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(174, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(175, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(176, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(177, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(178, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(179, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(180, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(181, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(182, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(183, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(184, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(185, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(186, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(187, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(188, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(189, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(190, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(191, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(192, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(193, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(194, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(195, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(196, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(197, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(198, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(199, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(200, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(201, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(202, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(203, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(204, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(205, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(206, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(207, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(208, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(209, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(210, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(211, 1, 'New alert nearby', 'New alert nearby - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(212, 2, 'Report status updated', 'Report status updated - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(213, 3, 'Community meeting reminder', 'Community meeting reminder - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(214, 4, 'Safety tip', 'Safety tip - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(215, 5, 'Course completed', 'Course completed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(216, 6, 'Certificate ready', 'Certificate ready - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(217, 7, 'Group joined', 'Group joined - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(218, 8, 'Profile viewed', 'Profile viewed - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(219, 9, 'Location shared', 'Location shared - check the app for details.', 'system', NULL, NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(220, 10, 'Walk completed', 'Walk completed - check the app for details.', 'system', NULL, NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:26', NULL, NULL),
(221, 1, 'New safety course available', 'Check out \"Understanding Your Legal Rights\" - now available.', 'system', '/courses', NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(222, 2, 'Your report was received', 'Incident report #24 has been received. We will review shortly.', 'report_update', '/reports/24', NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(223, 3, 'Community alert nearby', 'Safety warning in Dhanmondi - check the map.', 'alert', '/alerts', NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(224, 4, 'Panic alert resolved', 'Your emergency contacts were notified. Stay safe!', 'emergency', '/alerts', NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(225, 5, 'Group alert acknowledged', '5 members acknowledged the Mirpur meeting alert.', 'system', '/groups/2', NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(226, 6, 'Certificate ready', 'Your Basic Self-Defense certificate is ready to download.', 'system', '/certificates', NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(227, 7, 'New member joined', 'Sidratul joined Uttara Security Forum.', 'system', '/groups/3', NULL, 0, 1, 0, 0, 0, '2026-02-15 21:11:48', NULL, NULL),
(228, 8, 'Course progress', 'You completed Emergency First Aid 101! Great job.', 'system', '/courses', NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(229, 9, 'Incident update', 'Report #9 status changed to under_review.', 'report_update', '/reports/9', NULL, 0, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(230, 10, 'Welcome to Space', 'Your account is set up. Add emergency contacts for faster response.', 'system', '/dashboard', NULL, 1, 1, 0, 0, 1, '2026-02-15 21:11:48', NULL, NULL),
(231, 3, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Raihan Ahmed\nPhone: 01912345603\nLocation: \nGPS: 23.814562408369, 90.386618511262\nMap: https://maps.google.com/?q=23.814562408369,90.386618511262\nLive Tracking: http://localhost/space-login/track_walk.php?token=d004d2fcc9adbf24b68e556500f587cc9585920dc29a722ff266bb79058987e7\nTime: 2026-02-16 19:12:06\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-17 00:12:06', NULL, NULL),
(232, 3, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Raihan Ahmed\nPhone: 01912345603\nLocation: \nGPS: 23.814492819661, 90.386560137993\nMap: https://maps.google.com/?q=23.814492819661,90.386560137993\nLive Tracking: http://localhost/space-login/track_walk.php?token=f48649eea503d90557b75a1013d77e591f6f58c11fd3ea64918f4eb1a940865b\nTime: 2026-02-16 19:14:05\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-17 00:14:05', NULL, NULL),
(233, 3, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Raihan Ahmed\nPhone: 01912345603\nLocation: \nGPS: 23.814525035486, 90.386631048884\nMap: https://maps.google.com/?q=23.814525035486,90.386631048884\nLive Tracking: http://localhost/space-login/track_walk.php?token=9dc037d3301ed8b202b5479dba32d3b2a922fbb61852fdb4ea60f90eca6aae72\nTime: 2026-02-16 19:16:44\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-17 00:16:44', NULL, NULL),
(234, 3, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Raihan Ahmed\nPhone: 01912345603\nLocation: \nLive Tracking: http://localhost/space-login/track_walk.php?token=387135a448e82d1154afd4d42cc4073434033aabe56f950ea5c62fbdfc11e703\nTime: 2026-02-16 19:27:43\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-17 00:27:43', NULL, NULL),
(235, 3, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Raihan Ahmed\nPhone: 01912345603\nLocation: \nGPS: 23.814496001057, 90.386604320834\nMap: https://maps.google.com/?q=23.814496001057,90.386604320834\nLive Tracking: http://localhost/space-login/track_walk.php?token=f0558c1c1475512fc2f5ec5fd1108fbd127c7708c7acfcc4dd6e5c8a4183f8cc\nTime: 2026-02-17 13:24:45\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-17 18:24:45', NULL, NULL),
(236, 3, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Raihan Ahmed\nPhone: 01912345603\nLocation: \nGPS: 23.814545485861, 90.386379249228\nMap: https://maps.google.com/?q=23.814545485861,90.386379249228\nLive Tracking: http://localhost/space-login/track_walk.php?token=0c367c8016dcd64a5979d25d72644b9fd482ca1bdb33fd42b7b552f9e03d1e34\nTime: 2026-02-17 14:05:04\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-17 19:05:04', NULL, NULL),
(237, 3, 'SOS Alert - Walk With Me', '🚨 SOS ALERT - Walk With Me 🚨\n\nUser: Raihan Ahmed\nPhone: 01912345603\nLocation: \nGPS: 23.814319415464, 90.386628059775\nMap: https://maps.google.com/?q=23.814319415464,90.386628059775\nLive Tracking: http://localhost/space-login/track_walk.php?token=d692335c91afd0078fbe3ae2db404efdbc007942031e5f18b5bf05d48cac06bc\nTime: 2026-02-18 12:16:11\nMessage: SOS triggered during Walk With Me session\n\n⚠️ EMERGENCY - Please check on them immediately!', 'emergency', NULL, NULL, 0, 0, 0, 0, 0, '2026-02-18 17:16:11', NULL, NULL);

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
(1, 4, 'app_button', 'Mirpur 10, near community center', 23.80690000, 90.36870000, 'Need help! Someone following me.', 2, 1, 0, 0, 'resolved', 180, '2026-02-12 21:11:48', NULL, 0, 1, 5000, 3),
(2, 9, 'app_button', 'Farmgate bus stand area', 23.75950000, 90.39000000, 'Felt unsafe, activated by mistake', 1, 0, 0, 0, 'false_alarm', NULL, '2026-02-14 21:11:48', NULL, 0, 1, 5000, 2),
(3, 2, 'app_button', 'Dhanmondi Road 32', 23.75160000, 90.37750000, 'Wallet snatched. Need police.', 2, 1, 0, 0, 'resolved', 420, '2026-02-10 21:11:48', NULL, 0, 1, 5000, 4),
(4, 3, '', '', 23.81456241, 90.38661851, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=d004d2fcc9adbf24b68e556500f587cc9585920dc29a722ff266bb79058987e7', 2, 1, 0, 0, 'active', NULL, '2026-02-17 00:12:06', NULL, 0, 0, 5000, 0),
(5, 3, '', '', 23.81449282, 90.38656014, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=f48649eea503d90557b75a1013d77e591f6f58c11fd3ea64918f4eb1a940865b', 2, 1, 0, 0, 'active', NULL, '2026-02-17 00:14:05', NULL, 0, 0, 5000, 0),
(6, 3, '', '', 23.81452504, 90.38663105, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=9dc037d3301ed8b202b5479dba32d3b2a922fbb61852fdb4ea60f90eca6aae72', 2, 1, 0, 0, 'active', NULL, '2026-02-17 00:16:44', NULL, 0, 0, 5000, 0),
(8, 3, '', '', NULL, NULL, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=387135a448e82d1154afd4d42cc4073434033aabe56f950ea5c62fbdfc11e703', 2, 1, 0, 0, 'active', NULL, '2026-02-17 00:27:43', NULL, 0, 0, 5000, 0),
(9, 3, '', '', 23.81449600, 90.38660432, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=f0558c1c1475512fc2f5ec5fd1108fbd127c7708c7acfcc4dd6e5c8a4183f8cc', 2, 1, 0, 0, 'active', NULL, '2026-02-17 18:24:45', NULL, 0, 0, 5000, 0),
(10, 3, '', '', 23.81454549, 90.38637925, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=0c367c8016dcd64a5979d25d72644b9fd482ca1bdb33fd42b7b552f9e03d1e34', 2, 1, 0, 0, 'active', NULL, '2026-02-17 19:05:04', NULL, 0, 0, 5000, 0),
(11, 3, '', '', 23.81431942, 90.38662806, 'SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=d692335c91afd0078fbe3ae2db404efdbc007942031e5f18b5bf05d48cac06bc', 2, 1, 0, 0, 'active', NULL, '2026-02-18 17:16:11', NULL, 0, 0, 5000, 0);

--
-- Triggers `panic_alerts`
--
DELIMITER $$
CREATE TRIGGER `trg_after_panic_alert_insert` AFTER INSERT ON `panic_alerts` FOR EACH ROW BEGIN
  INSERT INTO `alerts` (
    `title`, `description`, `type`, `severity`,
    `location_name`, `latitude`, `longitude`, `radius_km`,
    `start_time`, `is_active`, `source_type`, `source_user_id`
  ) VALUES (
    'Emergency Panic Alert',
    CONCAT('A panic alert was triggered near ',
           COALESCE(NEW.location_name, 'your area'),
           '. Please assist if safe to do so.'),
    'emergency',
    'critical',
    COALESCE(NEW.location_name, 'Unknown Location'),
    NEW.latitude,
    NEW.longitude,
    0.50,
    NOW(),
    1,
    'user_report',
    NEW.user_id
  );
END
$$
DELIMITER ;

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
(1, 1, 7, 'sms', '01876543210', 'URGENT: Bonny needs help at Mirpur 10. Open Space app for location.', '2026-02-15 21:11:48', 'delivered', '2026-02-12 21:11:48', NULL, NULL),
(2, 1, 8, 'call', '01776543211', 'Emergency alert from Bonny Afrin', '2026-02-15 21:11:48', 'delivered', '2026-02-12 21:11:48', NULL, NULL),
(3, 3, 3, 'sms', '01911223344', 'URGENT: Safrin needs help at Dhanmondi Road 32. Open Space app.', '2026-02-15 21:11:48', 'delivered', '2026-02-10 21:11:48', NULL, NULL),
(4, 4, 4, 'sms', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81456241,90.38661851\nTime: 2026-02-17 00:12:06\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=d004d2fcc9adbf24b68e556500f587cc9585920dc29a722ff266bb79058987e7\n\nPlease respond immediately!', '2026-02-17 00:12:06', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(5, 4, 4, 'call', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81456241,90.38661851\nTime: 2026-02-17 00:12:06\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=d004d2fcc9adbf24b68e556500f587cc9585920dc29a722ff266bb79058987e7\n\nPlease respond immediately!', '2026-02-17 00:12:06', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(6, 5, 4, 'sms', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81449282,90.38656014\nTime: 2026-02-17 00:14:05\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=f48649eea503d90557b75a1013d77e591f6f58c11fd3ea64918f4eb1a940865b\n\nPlease respond immediately!', '2026-02-17 00:14:05', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(7, 5, 4, 'call', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81449282,90.38656014\nTime: 2026-02-17 00:14:05\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=f48649eea503d90557b75a1013d77e591f6f58c11fd3ea64918f4eb1a940865b\n\nPlease respond immediately!', '2026-02-17 00:14:05', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(8, 6, 4, 'sms', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81452504,90.38663105\nTime: 2026-02-17 00:16:44\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=9dc037d3301ed8b202b5479dba32d3b2a922fbb61852fdb4ea60f90eca6aae72\n\nPlease respond immediately!', '2026-02-17 00:16:44', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(9, 6, 4, 'call', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81452504,90.38663105\nTime: 2026-02-17 00:16:44\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=9dc037d3301ed8b202b5479dba32d3b2a922fbb61852fdb4ea60f90eca6aae72\n\nPlease respond immediately!', '2026-02-17 00:16:44', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(10, 8, 4, 'sms', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: \nTime: 2026-02-17 00:27:43\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=387135a448e82d1154afd4d42cc4073434033aabe56f950ea5c62fbdfc11e703\n\nPlease respond immediately!', '2026-02-17 00:27:43', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(11, 8, 4, 'call', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: \nTime: 2026-02-17 00:27:43\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=387135a448e82d1154afd4d42cc4073434033aabe56f950ea5c62fbdfc11e703\n\nPlease respond immediately!', '2026-02-17 00:27:43', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(12, 9, 4, 'sms', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81449600,90.38660432\nTime: 2026-02-17 18:24:45\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=f0558c1c1475512fc2f5ec5fd1108fbd127c7708c7acfcc4dd6e5c8a4183f8cc\n\nPlease respond immediately!', '2026-02-17 18:24:45', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(13, 9, 4, 'call', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81449600,90.38660432\nTime: 2026-02-17 18:24:45\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=f0558c1c1475512fc2f5ec5fd1108fbd127c7708c7acfcc4dd6e5c8a4183f8cc\n\nPlease respond immediately!', '2026-02-17 18:24:45', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(14, 10, 4, 'sms', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81454549,90.38637925\nTime: 2026-02-17 19:05:04\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=0c367c8016dcd64a5979d25d72644b9fd482ca1bdb33fd42b7b552f9e03d1e34\n\nPlease respond immediately!', '2026-02-17 19:05:04', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(15, 10, 4, 'call', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81454549,90.38637925\nTime: 2026-02-17 19:05:04\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=0c367c8016dcd64a5979d25d72644b9fd482ca1bdb33fd42b7b552f9e03d1e34\n\nPlease respond immediately!', '2026-02-17 19:05:04', 'failed', NULL, NULL, 'Call service not enabled in configuration'),
(16, 11, 4, 'sms', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81431942,90.38662806\nTime: 2026-02-18 17:16:11\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=d692335c91afd0078fbe3ae2db404efdbc007942031e5f18b5bf05d48cac06bc\n\nPlease respond immediately!', '2026-02-18 17:16:11', 'failed', NULL, NULL, 'SMS service not enabled in configuration'),
(17, 11, 4, 'call', '01511223344', '🚨 EMERGENCY ALERT 🚨\n\nUser: Raihan Ahmed\nLocation: https://maps.google.com/?q=23.81431942,90.38662806\nTime: 2026-02-18 17:16:11\nMessage: SOS triggered during Walk With Me session. SOS triggered during Walk With Me session\n\nTracking Link: http://localhost/space-login/track_walk.php?token=d692335c91afd0078fbe3ae2db404efdbc007942031e5f18b5bf05d48cac06bc\n\nPlease respond immediately!', '2026-02-18 17:16:11', 'failed', NULL, NULL, 'Call service not enabled in configuration');

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
(1, 'Basic Self-Defense for Women', 'Learn essential physical techniques to protect yourself in emergencies.', 'self_defense', 'general', NULL, 'mixed', NULL, NULL, 0, 'Farzana Ahmed', 'both', 10, 8, 0.00, 0, 'active', '2026-02-15 20:17:56', '2026-02-15 21:11:47'),
(2, 'Cyber Security Awareness', 'How to stay safe from online harassment and scams in Bangladesh.', 'cyber_safety', 'general', NULL, 'mixed', NULL, NULL, 0, 'Tanvir Rahman', 'bn', 8, 5, 0.00, 0, 'active', '2026-02-15 20:17:56', '2026-02-15 21:11:48'),
(3, 'Emergency First Aid 101', 'Primary medical response steps before reaching a hospital.', 'emergency_response', 'general', NULL, 'mixed', NULL, NULL, 0, 'Dr. Sajid Hossain', 'bn', 8, 6, 0.00, 0, 'active', '2026-02-15 20:17:56', '2026-02-15 21:11:48'),
(4, 'Understanding Your Legal Rights', 'A guide to Bangladeshi laws regarding harassment and assault.', 'legal_rights', 'general', NULL, 'mixed', NULL, NULL, 0, 'Advocate Sumaiya', 'bn', 6, 2, 0.00, 0, 'active', '2026-02-15 20:17:56', '2026-02-15 21:11:48');

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

--
-- Dumping data for table `safety_resources`
--

INSERT INTO `safety_resources` (`id`, `title`, `description`, `category`, `phone`, `email`, `website`, `address`, `is_24_7`, `hours_of_operation`, `languages`, `city`, `state`, `country`, `is_national`, `is_verified`, `verified_by`, `status`, `views_count`, `contact_count`, `created_at`, `updated_at`) VALUES
(1, 'BRAC Human Rights & Legal Aid', 'Provides legal and social protection for the marginalized.', 'legal', '02-9881265', NULL, 'www.brac.net', NULL, 0, NULL, NULL, NULL, NULL, 'Bangladesh', 1, 0, NULL, 'active', 0, 0, '2026-02-15 20:20:23', '2026-02-15 20:20:23'),
(2, 'Sajida Foundation Counseling', 'Professional mental health and trauma support services.', 'counseling', '01777771515', NULL, 'www.sajidafoundation.org', NULL, 1, NULL, NULL, NULL, NULL, 'Bangladesh', 1, 0, NULL, 'active', 0, 0, '2026-02-15 20:20:23', '2026-02-15 20:20:23'),
(3, 'Bangladesh Mohila Parishad', 'A leading organization for womens rights and support groups.', 'support_group', '02-9110817', NULL, 'www.mahilaparishad.org', NULL, 0, NULL, NULL, NULL, NULL, 'Bangladesh', 1, 0, NULL, 'active', 0, 0, '2026-02-15 20:20:23', '2026-02-15 20:20:23'),
(4, 'Maya (Digital Health & Safety)', 'An anonymous platform for legal and medical advice.', 'helpline', '01711223344', NULL, 'www.maya.com.bd', NULL, 1, NULL, NULL, NULL, NULL, 'Bangladesh', 1, 0, NULL, 'active', 0, 0, '2026-02-15 20:20:23', '2026-02-15 20:20:23');

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
(1, 'Dhanmondi 32 Police Box', 'Well-lit police assistance point with 24/7 presence.', 'police_station', 'Road 32, Dhanmondi, Dhaka', 23.75160000, 90.37750000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, NULL, 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, 24/7 Guards, Help Desk', NULL, NULL, '2026-02-15 20:25:52', '2026-02-15 20:25:52'),
(2, 'Bashundhara City Security Hub', 'Central security desk and safe waiting area inside the mall.', 'business', 'Panthapath, Dhaka', 23.75110000, 90.39080000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, NULL, 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, First Aid, Lost and Found', NULL, NULL, '2026-02-15 20:25:52', '2026-02-15 20:25:52'),
(3, 'Gulshan 2 Metro Shelter', 'Designated safe zone near the metro rail pillar for emergency refuge.', 'other', 'Gulshan Circle 2, Dhaka', 23.79250000, 90.41620000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, NULL, 1, NULL, NULL, 'active', 0.00, 0, 'Emergency Button, Well Lit', NULL, NULL, '2026-02-15 20:25:52', '2026-02-15 20:25:52'),
(4, 'United International University (UIU)', 'Educational campus with restricted entry and active security monitoring.', 'school', 'Madani Avenue, Badda, Dhaka', 23.79770000, 90.44970000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, NULL, 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Security Gates, 24/7 Security', NULL, NULL, '2026-02-15 20:25:52', '2026-02-15 20:25:52'),
(5, 'Mirpur 10 Community Center', 'Spacious public building often used for community safety meetings.', 'community_center', 'Mirpur 10, Dhaka', 23.80690000, 90.36870000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, NULL, 1, NULL, NULL, 'active', 0.00, 0, 'Public Phone, Guarded Entrance', NULL, NULL, '2026-02-15 20:25:52', '2026-02-15 20:25:52'),
(6, 'Shyamoli Police Box', '24/7 police assistance near Shyamoli Square', 'police_station', 'Shyamoli Square, Dhaka', 23.77520000, 90.36850000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Help Desk', NULL, 1, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(7, 'Kamalapur Railway Women Help Desk', 'Women help desk, safe waiting area', 'other', 'Kamalapur Station, Dhaka', 23.72980000, 90.42720000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Women Cell', NULL, 2, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(8, 'Rampura Security Post', 'Security post near Rampura Bridge', 'business', 'Rampura, Dhaka', 23.76250000, 90.42480000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '8AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Guard', NULL, 3, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(9, 'Khilgaon Thana', 'Police station with women desk', 'police_station', 'Khilgaon, Dhaka', 23.75220000, 90.43350000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'Women Cell, CCTV', NULL, 4, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(10, 'Paltan Metro Gate A', 'Safe zone near metro, well lit', 'other', 'Paltan, Dhaka', 23.73150000, 90.41250000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '6AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'Emergency Button, CCTV', NULL, 5, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(11, 'Pallabi Police Box', 'Police assistance Pallabi area', 'police_station', 'Pallabi, Mirpur, Dhaka', 23.81250000, 90.35820000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Help Desk', NULL, 1, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(12, 'Jatrabari Thana', 'Police station with women support', 'police_station', 'Jatrabari, Dhaka', 23.71850000, 90.43480000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'Women Cell, 24/7', NULL, 2, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(13, 'Gabtoli Bus Terminal Security', 'Security desk at bus terminal', 'business', 'Gabtoli, Dhaka', 23.76820000, 90.35480000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '5AM-11PM', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Lost & Found', NULL, 3, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(14, 'Agargaon Taltola Metro', 'Safe zone near metro station', 'other', 'Agargaon, Dhaka', 23.77750000, 90.37520000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '6AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'Well Lit, CCTV', NULL, 4, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(15, 'Demra Thana', 'Police station Demra area', 'police_station', 'Demra, Dhaka', 23.71020000, 90.44450000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'Women Desk, CCTV', NULL, 5, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(16, 'Motijheel CA Police Box', 'Police assistance commercial area', 'police_station', 'Motijheel C/A, Dhaka', 23.72520000, 90.41850000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Help Desk', NULL, 1, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(17, 'Kalshi Metro Shelter', 'Designated safe zone near metro', 'other', 'Kalshi, Dhaka', 23.82520000, 90.37180000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '6AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'Emergency Button, Well Lit', NULL, 2, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(18, 'Square Hospital ER', 'Hospital emergency - safe refuge', 'other', 'Panthapath, Dhaka', 23.75080000, 90.39120000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, '24/7, Security', NULL, 3, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(19, 'Kawran Bazar Police Box', 'Police post near Karwan Bazar', 'police_station', 'Kawran Bazar, Dhaka', 23.75150000, 90.38420000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Help Desk', NULL, 4, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(20, 'Bashundhara R/A Gate', 'Safe zone residential area entrance', 'business', 'Bashundhara R/A, Dhaka', 23.81780000, 90.42150000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'Security, CCTV', NULL, 5, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(21, 'Adabor Community Center', 'Community safe space', 'community_center', 'Adabor, Dhaka', 23.74720000, 90.36480000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '8AM-8PM', 1, NULL, NULL, 'active', 0.00, 0, 'Public Phone, Guard', NULL, 6, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(22, 'Nilkhet DU Gate', 'University area safe zone', 'school', 'Nilkhet, Dhaka', 23.73250000, 90.39820000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '8AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'Crowded, Safe', NULL, 7, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(23, 'Azimpur Thana', 'Police station Azimpur', 'police_station', 'Azimpur, Dhaka', 23.72620000, 90.40420000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'Women Cell, CCTV', NULL, 8, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(24, 'Malibagh Police Box', 'Police assistance Malibagh', 'police_station', 'Malibagh, Dhaka', 23.75820000, 90.42950000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Help Desk', NULL, 9, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(25, 'Kuratoli Police Outpost', 'Police post Kuratoli area', 'police_station', 'Kuratoli, Dhaka', 23.82020000, 90.42780000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Security', NULL, 10, '2026-02-15 21:45:22', '2026-02-15 21:45:22'),
(206, 'Uttara North Metro Gate', 'Safe waiting area near metro, security present', 'other', 'Uttara Sector 15 Metro, Dhaka', 23.87000000, 90.39800000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '6AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Security Guard', NULL, 1, '2026-02-15 21:11:48', '2026-02-15 21:11:48'),
(207, 'Farmgate Metro Station Entrance', 'Designated safe zone, well lit', 'other', 'Farmgate MRT Station, Dhaka', 23.75450000, 90.38750000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '6AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'Emergency Button, Well Lit', NULL, 2, '2026-02-15 21:11:48', '2026-02-15 21:11:48'),
(208, 'Gulshan 1 Police Box', '24/7 police assistance', 'police_station', 'Gulshan 1 Circle, Dhaka', 23.78000000, 90.41200000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'CCTV, Help Desk', NULL, 1, '2026-02-15 21:11:48', '2026-02-15 21:11:48'),
(209, 'Badda Thana', 'Police station - women desk available', 'police_station', 'Badda, Dhaka', 23.78500000, 90.44200000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'Women Cell, CCTV', NULL, 3, '2026-02-15 21:11:48', '2026-02-15 21:11:48'),
(210, 'DU TSC Cafe Area', 'University area, student presence', '', 'Dhaka University, Dhaka', 23.73350000, 90.39680000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '8AM-10PM', 1, NULL, NULL, 'active', 0.00, 0, 'Crowded, Safe', NULL, 6, '2026-02-15 21:11:48', '2026-02-15 21:11:48'),
(211, 'BRAC University Gate', 'Campus security, safe entrance', 'school', 'Mohakhali, Dhaka', 23.75000000, 90.37200000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '8AM-8PM', 1, NULL, NULL, 'active', 0.00, 0, 'Security, CCTV', NULL, 7, '2026-02-15 21:11:48', '2026-02-15 21:11:48'),
(212, 'Mohammadpur Thana', 'Police station with women support', 'police_station', 'Mohammadpur, Dhaka', 23.76200000, 90.36500000, 'Dhaka', NULL, 'Bangladesh', NULL, NULL, NULL, '24/7', 1, NULL, NULL, 'active', 0.00, 0, 'Women Desk, 24/7', NULL, 9, '2026-02-15 21:11:48', '2026-02-15 21:11:48');

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
(1, 'Dhanmondi Lake Perimeter', 'Well-monitored lake area with walking path', 0xe6100000010300000001000000050000001d5a643bdf9756405839b4c876be37408d976e12839856405839b4c876be37408d976e12839856408d976e1283c037401d5a643bdf9756408d976e1283c037401d5a643bdf9756405839b4c876be3740, 'high', 1, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(2, 'Gulshan 2 Commercial', 'Gulshan 2 main commercial zone', 0xe610000001030000000100000005000000273108ac1c9a56407d3f355ebac937405eba490c029b56407d3f355ebac937405eba490c029b56403f355eba49cc3740273108ac1c9a56403f355eba49cc3740273108ac1c9a56407d3f355ebac93740, 'high', 1, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(3, 'Uttara Sector 3 Residential', 'Residential sector with community watch', 0xe6100000010300000001000000050000000c022b8716995640cff753e3a5db37407d3f355eba995640cff753e3a5db37407d3f355eba99564091ed7c3f35de37400c022b871699564091ed7c3f35de37400c022b8716995640cff753e3a5db3740, 'high', 10, '2026-02-15 15:11:48', '2026-02-15 15:11:48'),
(4, 'UIU Campus Area', 'University campus and surrounding', 0xe61000000103000000010000000500000023dbf97e6a9c5640986e1283c0ca374077be9f1a2f9d5640986e1283c0ca374077be9f1a2f9d56405a643bdf4fcd374023dbf97e6a9c56405a643bdf4fcd374023dbf97e6a9c5640986e1283c0ca3740, 'high', 1, '2026-02-15 15:11:48', '2026-02-15 15:11:48');

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

--
-- Dumping data for table `support_referrals`
--

INSERT INTO `support_referrals` (`id`, `user_id`, `report_id`, `provider_id`, `referral_type`, `priority`, `reason`, `status`, `referred_at`, `appointment_date`, `completed_at`, `user_feedback`, `rating`, `provider_notes`, `is_anonymous`) VALUES
(1, 4, 15, 4, 'counseling', 'high', 'Trauma support after assault incident.', 'appointment_scheduled', '2026-02-15 21:11:48', '2026-02-20 21:11:48', NULL, NULL, NULL, NULL, 0),
(2, 9, 9, 4, 'counseling', 'medium', 'Anxiety and stress from stalking.', 'pending', '2026-02-15 21:11:48', NULL, NULL, NULL, NULL, NULL, 0),
(3, 7, 19, 4, 'counseling', 'high', 'Cyberbullying trauma support.', 'contacted', '2026-02-15 21:11:48', NULL, NULL, NULL, NULL, NULL, 0);

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

--
-- Dumping data for table `system_statistics`
--

INSERT INTO `system_statistics` (`id`, `date`, `new_users`, `active_users`, `total_users`, `new_reports`, `resolved_reports`, `total_reports`, `new_alerts`, `active_alerts`, `total_alerts`, `avg_response_time_minutes`, `min_response_time_minutes`, `max_response_time_minutes`, `top_cities`, `top_categories`, `system_uptime_percentage`, `avg_page_load_time`, `created_at`) VALUES
(1, '2025-12-31', 10, 5, 10, 5, 2, 5, 2, 0, 0, 0.00, 0, 0, NULL, NULL, 99.90, 0.45, '2026-02-15 20:21:50'),
(2, '2026-01-15', 25, 15, 35, 15, 8, 20, 5, 0, 0, 0.00, 0, 0, NULL, NULL, 99.80, 0.52, '2026-02-15 20:21:50'),
(3, '2026-01-31', 40, 30, 75, 30, 20, 50, 10, 0, 0, 0.00, 0, 0, NULL, NULL, 100.00, 0.48, '2026-02-15 20:21:50'),
(4, '2026-02-10', 25, 60, 10, 40, 35, 90, 15, 0, 0, 0.00, 0, 0, NULL, NULL, 99.70, 0.55, '2026-02-15 20:21:50'),
(5, '2026-02-15', 5, 7, 10, 10, 35, 100, 3, 0, 0, 0.00, 0, 0, 'Dhaka', 'harassment,theft,stalking', 100.00, 0.42, '2026-02-15 20:21:50');

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
(1, 1, 'Dhanmondi', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(2, 1, 'Mirpur', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(3, 1, 'Uttara', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(4, 1, 'Gulshan', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(5, 1, 'Banani', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(6, 1, 'Mohammadpur', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(7, 1, 'Badda', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(8, 1, 'Farmgate', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(9, 1, 'Khilgaon', '2026-02-15 20:01:29', '2026-02-15 20:01:29'),
(10, 1, 'Paltan', '2026-02-15 20:01:29', '2026-02-15 20:01:29');

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
(1, NULL, 'mdabusayumanik05@gmail.com', '01712345601', 'UIU CSE student. Active in community safety.', '$2y$10$b925gY24wYaRph8f4UaNj.pwyy/N1p.LFAAWcUGmi6ZE2oa0qk2wW', 'Abu Sayeed Manik', 'yK6cQ56Pr2NpS6rnm5RygRic8R13', 'password', 0, 1, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:40:38', '2026-02-17 00:18:09', '2026-02-17 00:18:09', '4222776215', 'uploads/nid/nid_4222776215_front_1771162838_6991ccd68e1e9.jpg', 'uploads/nid/nid_4222776215_back_1771162838_6991ccd69125e.jpg', 0, 0, 'pending', 23.79770000, 90.44970000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(2, NULL, 'safrin2330183@bscse.uiu.ac.bd', '01812345602', 'Loves helping neighbors stay safe.', '$2y$10$Ci6n75.CyUPiIL9HPQneFeN4ci4MQIuSr.zc41hxh5UkfHDYZWDSe', 'Safrin Akter', 'QkKafaydj0Sbq24GdZfTiAdKkNZ2', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:43:07', NULL, '2026-02-15 21:11:47', '4222776216', 'uploads/nid/nid_4222776216_front_1771162987_6991cd6b9a7b7.jpg', 'uploads/nid/nid_4222776216_back_1771162987_6991cd6b9b9f3.jpg', 0, 0, 'pending', 23.75160000, 90.37750000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(3, NULL, 'mdabusayumanik123@gmail.com', '01912345603', 'Tech enthusiast. Safety first.', '$2y$10$i0WoiYDCqBWIVOlDIjDfL.2RABMwoXCrgM.EWTV3c2/Qzj.Zs1Q/.', 'Raihan Ahmed', 'C7wByr7YcUYn1Gm1bgo2kCkztUr2', 'password', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:45:16', '2026-02-19 05:11:09', '2026-02-19 05:11:09', '4222776217', 'uploads/nid/nid_4222776217_front_1771163116_6991cdeca604a.jpg', 'uploads/nid/nid_4222776217_back_1771163116_6991cdeca932b.jpg', 0, 0, 'pending', 23.74610000, 90.37420000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(4, NULL, 'bonnyafrin98@gmail.com', '01612345604', 'Women safety advocate.', '$2y$10$.WWUxToODG0bDnN/CHMtFOim4WO8NDZgrKs75JyOpMyQJ5ahD9gLW', 'Bonny Afrin', 'Xg7qZdtIYUeuSPzQFAN6FjYRelk1', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:46:26', NULL, '2026-02-15 21:11:47', '4222776218', 'uploads/nid/nid_4222776218_front_1771163186_6991ce3256a8f.jpg', 'uploads/nid/nid_4222776218_back_1771163186_6991ce3256f7c.jpg', 0, 0, 'pending', 23.80420000, 90.36350000, NULL, 0, '2026-02-15 13:11:47'),
(5, NULL, 'manik2330217@bscse.uiu.ac.bd', '01512345605', 'Mirpur resident. Community volunteer.', '$2y$10$LPpi4ld55rdPUxlEz1jz5.pdffIxMapmbewFu/0cjUiTH6lP1uLDK', 'Manik Hossain', 'g1hJ8dig66Tzl8QtDTUXuT93EQ22', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:47:44', NULL, '2026-02-15 21:11:47', '4222776219', 'uploads/nid/nid_4222776219_front_1771163264_6991ce80aa7f5.jpg', 'uploads/nid/nid_4222776219_back_1771163264_6991ce80abb0d.jpg', 0, 0, 'pending', 23.80690000, 90.36870000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(6, NULL, 'sadiaafrinbonny183@gmail.com', '01722345606', 'Dhanmondi area. Always ready to help.', '$2y$10$d5oeWb2WBmDsS.duTdY/C.sneYNizyXsPTFHukMpKQ3d1tMyVYwQK', 'Sadia Afrin', 'pos6gG3klzUtFoj76FgMKzgYMmI3', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:49:44', NULL, '2026-02-15 21:11:47', '4222776211', 'uploads/nid/nid_4222776211_front_1771163384_6991cef8bd86e.jpg', 'uploads/nid/nid_4222776211_back_1771163384_6991cef8be41b.jpg', 0, 0, 'pending', 23.75050000, 90.38550000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(7, NULL, 'msakib2330048@bscse.uiu.ac.bd', '01822345607', 'Gulshan 2. Tech & safety.', '$2y$10$dOaYNKgCCkV64HaHiYncmuzYVU.v.lG36yadDRWYkRlCdzur02Yl.', 'Muhammad Sakib', '1IIaF8414UMHrpynBdiib6SB7Se2', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:51:04', NULL, '2026-02-15 21:11:47', '4222776212', 'uploads/nid/nid_4222776212_front_1771163464_6991cf482ab88.jpg', 'uploads/nid/nid_4222776212_back_1771163464_6991cf482b514.jpg', 0, 0, 'pending', 23.79250000, 90.41620000, NULL, 0, '2026-02-15 14:41:47'),
(8, NULL, 'sidratul@cse.uiu.ac.bd', '01922345608', 'UIU CSE. Safety awareness promoter.', '$2y$10$PXV/51F5x.HS1Da1RJjsj.x9gi/n2.QMO1Pt2OUCSLe55nuXckrjq', 'Sidratul Muntaha', 'qPuRv1FCLHg2pSBgy1E3AdK75d43', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:52:51', NULL, '2026-02-15 21:11:47', '4222776213', 'uploads/nid/nid_4222776213_front_1771163571_6991cfb3b8977.jpg', 'uploads/nid/nid_4222776213_back_1771163571_6991cfb3b908c.jpg', 0, 0, 'pending', 23.86310000, 90.39670000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(9, NULL, 'sanjida@cse.uiu.ac.bd', '01622345609', 'Mohammadpur. Neighborhood watch member.', '$2y$10$wQSaGmZIANGNWSK2BJKhb.otZDBovi0I00s6g/bTEzq7FN/Lr7CVe', 'Sanjida Akter', 'Y938RyqfW5XkdEQTGpnrAy6fQA73', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:54:43', NULL, '2026-02-15 21:11:47', '4222776214', 'uploads/nid/nid_4222776214_front_1771163683_6991d02327576.jpg', 'uploads/nid/nid_4222776214_back_1771163683_6991d02329442.jpg', 0, 0, 'pending', 23.75950000, 90.39000000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(10, NULL, 'aurna@cse.uiu.ac.bd', '01732345610', 'Uttara resident. Active responder.', '$2y$10$A8bTWpksR0Ht6.MikS/QbuvKoa4VXmMZ/vlF9EGDbRtjC/oPit2PK', 'Aurna Akter', 'OTwVAzKaKTOtKMWxiYMT5S4A2eH2', 'local', 0, 0, NULL, NULL, NULL, 'active', 1, '2026-02-15 19:55:58', NULL, '2026-02-15 21:11:47', '4222776210', 'uploads/nid/nid_4222776210_front_1771163757_6991d06deba32.jpg', 'uploads/nid/nid_4222776210_back_1771163757_6991d06decd47.jpg', 0, 0, 'pending', 23.86310000, 90.39670000, '2026-02-15 15:11:47', 1, '2026-02-15 15:11:47'),
(11, NULL, 'admin@safespace.com', NULL, NULL, '$2y$12$OcPJHlfnneurP4R.5wj8meSgCXBX2NQwekaKlAskiefqmu445nFsm', 'Admin', NULL, 'local', 0, 1, NULL, NULL, NULL, 'active', 1, '2026-02-16 22:47:30', '2026-02-18 17:44:43', '2026-02-18 17:44:43', NULL, NULL, NULL, 0, 0, 'pending', NULL, NULL, NULL, 0, NULL);

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
(1, 1, 1, 5000, 1, 1, 1, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(2, 2, 1, 3000, 1, 1, 0, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(3, 3, 1, 5000, 1, 1, 1, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(4, 4, 1, 4000, 1, 1, 0, 1, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(5, 5, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(6, 6, 1, 6000, 1, 1, 1, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(7, 7, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(8, 8, 1, 4000, 1, 1, 1, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(9, 9, 1, 5000, 1, 1, 0, 0, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47'),
(10, 10, 1, 7000, 1, 1, 1, 1, NULL, NULL, '2026-02-15 15:11:47', '2026-02-15 15:11:47');

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
(1, 1, 5, 5, 'Badda is generally safe, good lighting near UIU.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(2, 2, 1, 5, 'Dhanmondi 32 area is well monitored.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(3, 3, 1, 4, 'Busy area but police presence helps.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(4, 4, 2, 4, 'Mirpur 10 has improved recently.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(5, 5, 2, 5, 'Community watch is active here.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(6, 6, 1, 5, 'Love the metro station area.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(7, 7, 4, 5, 'Gulshan 2 very safe.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(8, 8, 3, 5, 'Uttara sector 3 is great.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(9, 9, 5, 4, 'Mohammadpur getting better.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47'),
(10, 10, 3, 5, 'Uttara is my safe zone.', NULL, 1, '2026-02-15 21:11:47', '2026-02-15 21:11:47');

--
-- Triggers `user_area_ratings`
--
DELIMITER $$
CREATE TRIGGER `trg_after_area_rating_insert` AFTER INSERT ON `user_area_ratings` FOR EACH ROW BEGIN
  UPDATE `area_safety_scores`
  SET
    `user_ratings_score` = (
      SELECT ROUND(AVG(uar.safety_rating) * 2, 2)
      FROM `user_area_ratings` uar
      WHERE uar.area_id = NEW.area_id
    ),
    `last_updated` = NOW()
  WHERE `id` = NEW.area_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_after_area_rating_update` AFTER UPDATE ON `user_area_ratings` FOR EACH ROW BEGIN
  UPDATE `area_safety_scores`
  SET
    `user_ratings_score` = (
      SELECT ROUND(AVG(uar.safety_rating) * 2, 2)
      FROM `user_area_ratings` uar
      WHERE uar.area_id = NEW.area_id
    ),
    `last_updated` = NOW()
  WHERE `id` = NEW.area_id;
END
$$
DELIMITER ;

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
(1, 1, 1, 0, 1, 5.00, NULL, 'public', 1, 1, 'en', 'Asia/Dhaka', NULL, 'dark', NULL, '2026-02-15 21:11:47'),
(2, 2, 1, 0, 1, 3.00, NULL, 'friends_only', 1, 1, 'bn', 'Asia/Dhaka', NULL, 'auto', NULL, '2026-02-15 21:11:47'),
(3, 3, 1, 0, 1, 5.00, NULL, 'public', 1, 0, 'en', 'Asia/Dhaka', NULL, 'dark', NULL, '2026-02-15 21:11:47'),
(4, 4, 1, 1, 1, 4.00, NULL, 'private', 1, 1, 'bn', 'Asia/Dhaka', NULL, 'light', NULL, '2026-02-15 21:11:48'),
(5, 5, 1, 0, 1, 5.00, NULL, 'friends_only', 1, 1, 'bn', 'Asia/Dhaka', NULL, 'auto', NULL, '2026-02-15 21:11:47'),
(6, 6, 1, 0, 1, 6.00, NULL, 'public', 1, 1, 'en', 'Asia/Dhaka', NULL, 'dark', NULL, '2026-02-15 21:11:47'),
(7, 7, 1, 0, 1, 5.00, NULL, 'private', 1, 1, 'en', 'Asia/Dhaka', NULL, 'auto', NULL, '2026-02-15 21:11:48'),
(8, 8, 1, 0, 1, 4.00, NULL, 'friends_only', 1, 1, 'bn', 'Asia/Dhaka', NULL, 'light', NULL, '2026-02-15 21:11:47'),
(9, 9, 1, 0, 1, 5.00, NULL, 'private', 1, 1, 'bn', 'Asia/Dhaka', NULL, 'auto', NULL, '2026-02-15 21:11:47'),
(10, 10, 1, 1, 1, 7.00, NULL, 'public', 1, 1, 'en', 'Asia/Dhaka', NULL, 'dark', NULL, '2026-02-15 21:11:47');

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
(1, 3, 'a75397274864007bc6c67ff4a4eaf95573d50b656a1ac974598abd2a52179c48', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-15 20:26:29', '2026-02-15 20:26:29', 1),
(2, 1, 'b8f2a1c3d4e5f6789012345678901234abcdef', '::1', NULL, 'desktop', '2026-02-15 21:11:48', '2026-02-15 21:11:48', 1),
(3, 2, 'c9f3b2d4e5f6a7890123456789012345bcdefg', '::1', NULL, 'mobile', '2026-02-15 20:11:48', '2026-02-15 21:11:48', 1),
(4, 6, 'd0f4c3e5f6a7b8901234567890123456cdefgh', '::1', NULL, 'mobile', '2026-02-15 21:11:48', '2026-02-15 21:11:48', 1),
(5, 8, 'e1f5d4f6a7b8c9012345678901234567defghi', '::1', NULL, 'desktop', '2026-02-15 21:11:48', '2026-02-15 21:11:48', 1),
(6, 10, 'f2f6e5a7b8c9d0123456789012345678efghij', '::1', NULL, 'mobile', '2026-02-15 20:41:48', '2026-02-15 21:11:48', 1),
(7, 3, 'd9b6b9138f5cbf6a2bcd8b2dbc23a15be10a62e57730799284e74532445e6f3d', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-16 22:35:50', '2026-02-16 22:35:50', 1),
(8, 3, '7c76aadee7c875a0dfc2f3058190f7ab2a1d1200472b21513bda4608b63caaf9', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-16 23:08:49', '2026-02-16 23:08:49', 1),
(9, 3, 'db645ff064232adf010022c8eae6eb42da4657bbfc426614e92d343463086c72', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-16 23:15:03', '2026-02-16 23:15:03', 1),
(10, 3, 'f3d5d3c1f557b29de4ec8a731936e6e22a840b2fb951af76fb164aab54aa317f', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-17 00:09:26', '2026-02-17 00:09:26', 1),
(11, 1, 'ed22fe2a340bd931556e6d877dfed4ebb2d3fdd6f494eb78ee959311f2a08005', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', 'desktop', '2026-02-17 00:18:09', '2026-02-17 00:18:09', 1),
(12, 3, '72e07bb5f9205b87aa9357012ec4b8e54fc3925900d3ea01c06fa73e2d6bbe61', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-17 18:08:23', '2026-02-17 18:08:23', 1),
(13, 3, 'c3cf81c39f488862e6461a1f4dcddd33c0fed497867da9c911bfe16b03b1fe88', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-17 18:54:34', '2026-02-17 18:54:34', 1),
(14, 3, '51f37f5e2a96063b45011165745af5e90e20c76c2c284c14573a3ee90fc465f7', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-18 17:02:04', '2026-02-18 17:02:04', 1),
(15, 3, '52126c45da34bcc25efaa86c5c63302467eb33c726aea813cbbfe1a3581b1cb1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'desktop', '2026-02-19 05:11:09', '2026-02-19 05:11:09', 1);

--
-- Triggers `user_sessions`
--
DELIMITER $$
CREATE TRIGGER `trg_after_session_insert` AFTER INSERT ON `user_sessions` FOR EACH ROW BEGIN

  DELETE FROM `user_sessions`
  WHERE `user_id` = NEW.user_id
    AND `id` NOT IN (
      SELECT `id` FROM (
        SELECT `id` FROM `user_sessions`
        WHERE `user_id` = NEW.user_id
        ORDER BY `created_at` DESC
        LIMIT 5
      ) AS recent_sessions
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_active_incidents_with_user`
-- (See below for the actual view)
--
CREATE TABLE `vw_active_incidents_with_user` (
`id` int(11)
,`title` varchar(255)
,`description` text
,`category` enum('harassment','assault','theft','vandalism','stalking','cyberbullying','discrimination','other')
,`severity` enum('low','medium','high','critical')
,`status` enum('pending','under_review','investigating','resolved','closed','disputed')
,`latitude` decimal(10,8)
,`longitude` decimal(11,8)
,`location_name` varchar(255)
,`incident_date` datetime
,`reported_date` datetime
,`is_anonymous` tinyint(1)
,`reporter_name` varchar(100)
,`reporter_email` varchar(100)
,`assigned_to_name` varchar(100)
,`hours_since_reported` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_area_danger_rankings`
-- (See below for the actual view)
--
CREATE TABLE `vw_area_danger_rankings` (
`id` int(11)
,`area_name` varchar(255)
,`safety_score` decimal(5,2)
,`total_incidents` int(11)
,`critical_incidents` int(11)
,`response_time_avg_hours` decimal(8,2)
,`danger_level` varchar(20)
,`safety_rank` bigint(21)
,`danger_rank` bigint(21)
,`safety_percentile` double(17,10)
,`rank_in_district` bigint(21)
,`district_name` varchar(100)
,`division_name` varchar(100)
,`last_updated` datetime
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_monthly_incident_trend`
-- (See below for the actual view)
--
CREATE TABLE `vw_monthly_incident_trend` (
`report_year` int(4)
,`report_month` int(2)
,`month_label` varchar(7)
,`category` enum('harassment','assault','theft','vandalism','stalking','cyberbullying','discrimination','other')
,`severity` enum('low','medium','high','critical')
,`incident_count` bigint(21)
,`resolved_count` decimal(22,0)
,`resolution_rate_pct` decimal(27,1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_user_safety_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_user_safety_summary` (
`user_id` int(11)
,`display_name` varchar(100)
,`email` varchar(100)
,`is_admin` tinyint(1)
,`member_since` datetime
,`total_reports` bigint(21)
,`resolved` decimal(22,0)
,`pending` decimal(22,0)
,`investigating` decimal(22,0)
,`critical_reports` decimal(22,0)
,`courses_completed` bigint(21)
,`emergency_contacts_count` bigint(21)
,`safety_engagement_score` decimal(24,1)
);

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
(1, 6, 'walk_a1b2c3d4e5f6', '2026-02-13 21:11:48', '2026-02-13 21:26:48', 'completed', 'Dhanmondi Metro to Home', 15),
(2, 2, 'walk_b2c3d4e5f6a7', '2026-02-14 21:11:48', '2026-02-14 21:31:48', 'completed', 'Farmgate to Dhanmondi 32', 20),
(3, 10, 'walk_c3d4e5f6a7b8', '2026-02-12 21:11:48', '2026-02-12 21:23:48', 'completed', 'Uttara Metro to Sector 3', 12),
(4, 4, 'walk_d4e5f6a7b8c9', '2026-02-10 21:11:48', '2026-02-10 21:19:48', 'completed', 'Mirpur 10 to Community Center', 8),
(5, 1, 'walk_e5f6a7b8c9d0', '2026-02-15 21:11:48', NULL, 'active', 'UIU to Badda', 25),
(6, 3, 'd004d2fcc9adbf24b68e556500f587cc9585920dc29a722ff266bb79058987e7', '2026-02-17 00:11:59', NULL, 'emergency', '', 0),
(7, 3, 'f48649eea503d90557b75a1013d77e591f6f58c11fd3ea64918f4eb1a940865b', '2026-02-17 00:13:58', NULL, 'emergency', '', 0),
(8, 3, '9dc037d3301ed8b202b5479dba32d3b2a922fbb61852fdb4ea60f90eca6aae72', '2026-02-17 00:16:33', NULL, 'emergency', '', 0),
(9, 3, '387135a448e82d1154afd4d42cc4073434033aabe56f950ea5c62fbdfc11e703', '2026-02-17 00:27:39', NULL, 'emergency', '', 0),
(10, 3, 'f0558c1c1475512fc2f5ec5fd1108fbd127c7708c7acfcc4dd6e5c8a4183f8cc', '2026-02-17 18:24:26', NULL, 'emergency', '', 0),
(11, 3, '0c367c8016dcd64a5979d25d72644b9fd482ca1bdb33fd42b7b552f9e03d1e34', '2026-02-17 19:04:45', NULL, 'emergency', '', 0),
(12, 3, 'd692335c91afd0078fbe3ae2db404efdbc007942031e5f18b5bf05d48cac06bc', '2026-02-18 17:15:52', NULL, 'emergency', '', 0);

-- --------------------------------------------------------

--
-- Structure for view `active_users_with_location`
--
DROP TABLE IF EXISTS `active_users_with_location`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `active_users_with_location`  AS SELECT `u`.`id` AS `id`, `u`.`email` AS `email`, `u`.`display_name` AS `display_name`, `u`.`current_latitude` AS `current_latitude`, `u`.`current_longitude` AS `current_longitude`, `u`.`is_online` AS `is_online`, `u`.`last_seen` AS `last_seen`, `uas`.`allow_community_alerts` AS `allow_community_alerts`, `uas`.`alert_radius` AS `alert_radius`, `uas`.`notify_push` AS `notify_push`, `uas`.`notify_sound` AS `notify_sound`, `uas`.`notify_email` AS `notify_email` FROM (`users` `u` left join `user_alert_settings` `uas` on(`u`.`id` = `uas`.`user_id`)) WHERE `u`.`is_online` = 1 AND `u`.`current_latitude` is not null AND `u`.`current_longitude` is not null AND (`uas`.`allow_community_alerts` is null OR `uas`.`allow_community_alerts` = 1) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_active_incidents_with_user`
--
DROP TABLE IF EXISTS `vw_active_incidents_with_user`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_active_incidents_with_user`  AS SELECT `ir`.`id` AS `id`, `ir`.`title` AS `title`, `ir`.`description` AS `description`, `ir`.`category` AS `category`, `ir`.`severity` AS `severity`, `ir`.`status` AS `status`, `ir`.`latitude` AS `latitude`, `ir`.`longitude` AS `longitude`, `ir`.`location_name` AS `location_name`, `ir`.`incident_date` AS `incident_date`, `ir`.`reported_date` AS `reported_date`, `ir`.`is_anonymous` AS `is_anonymous`, CASE WHEN `ir`.`is_anonymous` = 1 THEN 'Anonymous' ELSE `u`.`display_name` END AS `reporter_name`, CASE WHEN `ir`.`is_anonymous` = 1 THEN NULL ELSE `u`.`email` END AS `reporter_email`, coalesce(`d`.`display_name`,'Unassigned') AS `assigned_to_name`, timestampdiff(HOUR,`ir`.`reported_date`,current_timestamp()) AS `hours_since_reported` FROM ((`incident_reports` `ir` join `users` `u` on(`ir`.`user_id` = `u`.`id`)) left join `users` `d` on(`ir`.`assigned_to` = `d`.`id`)) WHERE `ir`.`status` <> 'resolved' ORDER BY field(`ir`.`severity`,'critical','high','medium','low') ASC, `ir`.`reported_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `vw_area_danger_rankings`
--
DROP TABLE IF EXISTS `vw_area_danger_rankings`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_area_danger_rankings`  AS SELECT `ass`.`id` AS `id`, `ass`.`area_name` AS `area_name`, `ass`.`safety_score` AS `safety_score`, `ass`.`total_incidents` AS `total_incidents`, `ass`.`critical_incidents` AS `critical_incidents`, `ass`.`response_time_avg_hours` AS `response_time_avg_hours`, `get_incident_danger_level`(`ass`.`safety_score`) AS `danger_level`, rank() over ( order by `ass`.`safety_score` desc) AS `safety_rank`, dense_rank() over ( order by `ass`.`critical_incidents` desc) AS `danger_rank`, percent_rank() over ( order by `ass`.`safety_score`) AS `safety_percentile`, row_number() over ( partition by `dist`.`id` order by `ass`.`safety_score` desc) AS `rank_in_district`, `dist`.`name` AS `district_name`, `divn`.`name` AS `division_name`, `ass`.`last_updated` AS `last_updated` FROM ((`area_safety_scores` `ass` join `districts` `dist` on(`ass`.`district_id` = `dist`.`id`)) join `divisions` `divn` on(`ass`.`division_id` = `divn`.`id`)) ORDER BY `ass`.`safety_score` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `vw_monthly_incident_trend`
--
DROP TABLE IF EXISTS `vw_monthly_incident_trend`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_monthly_incident_trend`  AS SELECT year(`incident_reports`.`reported_date`) AS `report_year`, month(`incident_reports`.`reported_date`) AS `report_month`, date_format(`incident_reports`.`reported_date`,'%Y-%m') AS `month_label`, `incident_reports`.`category` AS `category`, `incident_reports`.`severity` AS `severity`, count(0) AS `incident_count`, sum(case when `incident_reports`.`status` = 'resolved' then 1 else 0 end) AS `resolved_count`, round(sum(case when `incident_reports`.`status` = 'resolved' then 1 else 0 end) * 100.0 / count(0),1) AS `resolution_rate_pct` FROM `incident_reports` WHERE `incident_reports`.`reported_date` is not null GROUP BY year(`incident_reports`.`reported_date`), month(`incident_reports`.`reported_date`), `incident_reports`.`category`, `incident_reports`.`severity` ORDER BY year(`incident_reports`.`reported_date`) DESC, month(`incident_reports`.`reported_date`) DESC, count(0) DESC ;

-- --------------------------------------------------------

--
-- Structure for view `vw_user_safety_summary`
--
DROP TABLE IF EXISTS `vw_user_safety_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_user_safety_summary`  AS SELECT `u`.`id` AS `user_id`, `u`.`display_name` AS `display_name`, `u`.`email` AS `email`, `u`.`is_admin` AS `is_admin`, `u`.`created_at` AS `member_since`, count(distinct `ir`.`id`) AS `total_reports`, sum(case when `ir`.`status` = 'resolved' then 1 else 0 end) AS `resolved`, sum(case when `ir`.`status` = 'pending' then 1 else 0 end) AS `pending`, sum(case when `ir`.`status` = 'investigating' then 1 else 0 end) AS `investigating`, sum(case when `ir`.`severity` in ('high','critical') then 1 else 0 end) AS `critical_reports`, count(distinct `ce`.`id`) AS `courses_completed`, count(distinct `ec`.`id`) AS `emergency_contacts_count`, round(count(distinct `ir`.`id`) * 0.3 + count(distinct `ce`.`id`) * 0.5 + count(distinct `ec`.`id`) * 0.2,1) AS `safety_engagement_score` FROM (((`users` `u` left join `incident_reports` `ir` on(`u`.`id` = `ir`.`user_id`)) left join `course_enrollments` `ce` on(`u`.`id` = `ce`.`user_id` and `ce`.`status` = 'completed')) left join `emergency_contacts` `ec` on(`u`.`id` = `ec`.`user_id` and `ec`.`is_active` = 1)) GROUP BY `u`.`id`, `u`.`display_name`, `u`.`email`, `u`.`is_admin`, `u`.`created_at` ORDER BY round(count(distinct `ir`.`id`) * 0.3 + count(distinct `ce`.`id`) * 0.5 + count(distinct `ec`.`id`) * 0.2,1) DESC ;

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
  ADD KEY `location_name` (`location_name`(100)),
  ADD KEY `idx_alerts_active_time` (`is_active`,`start_time`),
  ADD KEY `idx_alerts_severity` (`severity`,`type`),
  ADD KEY `idx_alerts_geo` (`latitude`,`longitude`);
ALTER TABLE `alerts` ADD FULLTEXT KEY `ft_alert_search` (`title`,`description`,`location_name`);

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
  ADD KEY `area_safety_scores_ibfk_3` (`upazila_id`),
  ADD KEY `idx_area_score` (`safety_score`),
  ADD KEY `idx_area_district` (`district_id`,`division_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `action` (`action`),
  ADD KEY `table_name` (`table_name`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_audit_user_time` (`user_id`,`created_at`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_table` (`table_name`,`record_id`);

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
  ADD UNIQUE KEY `idx_enroll_unique` (`user_id`,`course_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_enroll_status` (`status`);

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
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_disputes_status` (`status`,`created_at`),
  ADD KEY `idx_disputes_report` (`report_id`);

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
  ADD KEY `idx_date_status` (`incident_date`,`status`),
  ADD KEY `idx_ir_user_status` (`user_id`,`status`),
  ADD KEY `idx_ir_geo` (`latitude`,`longitude`),
  ADD KEY `idx_ir_category_date` (`category`,`reported_date`),
  ADD KEY `idx_ir_status_severity` (`status`,`severity`),
  ADD KEY `idx_ir_reported_date` (`reported_date`),
  ADD KEY `idx_ir_incident_date` (`incident_date`),
  ADD KEY `idx_ir_anonymous` (`is_anonymous`);
ALTER TABLE `incident_reports` ADD FULLTEXT KEY `ft_report_search` (`title`,`description`,`location_name`);

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
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `idx_notif_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_notif_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_notif_type` (`type`);

--
-- Indexes for table `panic_alerts`
--
ALTER TABLE `panic_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `triggered_at` (`triggered_at`),
  ADD KEY `idx_panic_status` (`status`),
  ADD KEY `idx_panic_user` (`user_id`,`triggered_at`),
  ADD KEY `idx_panic_geo` (`latitude`,`longitude`);

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
ALTER TABLE `safety_resources` ADD FULLTEXT KEY `ft_resource_search` (`title`,`description`);

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
  ADD UNIQUE KEY `idx_users_email` (`email`),
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
  ADD KEY `idx_last_seen` (`last_seen`),
  ADD KEY `idx_users_online_geo` (`is_online`,`current_latitude`,`current_longitude`),
  ADD KEY `idx_users_admin` (`is_admin`),
  ADD KEY `idx_users_active` (`is_active`,`created_at`);

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
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_session_user` (`user_id`),
  ADD KEY `idx_session_active` (`is_active`,`last_activity`);

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
-- AUTO_INCREMENT for table `alert_responses`
--
ALTER TABLE `alert_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `area_safety_scores`
--
ALTER TABLE `area_safety_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=166;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `emergency_contacts`
--
ALTER TABLE `emergency_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `emergency_services`
--
ALTER TABLE `emergency_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=112;

--
-- AUTO_INCREMENT for table `group_alerts`
--
ALTER TABLE `group_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `helpline_numbers`
--
ALTER TABLE `helpline_numbers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `incident_reports`
--
ALTER TABLE `incident_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `incident_zones`
--
ALTER TABLE `incident_zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=116;

--
-- AUTO_INCREMENT for table `leaf_nodes`
--
ALTER TABLE `leaf_nodes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=140;

--
-- AUTO_INCREMENT for table `legal_aid_providers`
--
ALTER TABLE `legal_aid_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `legal_consultations`
--
ALTER TABLE `legal_consultations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `legal_documents`
--
ALTER TABLE `legal_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `medical_support_providers`
--
ALTER TABLE `medical_support_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `neighborhood_groups`
--
ALTER TABLE `neighborhood_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=238;

--
-- AUTO_INCREMENT for table `panic_alerts`
--
ALTER TABLE `panic_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `panic_notifications`
--
ALTER TABLE `panic_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `safety_courses`
--
ALTER TABLE `safety_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `safety_resources`
--
ALTER TABLE `safety_resources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `safe_spaces`
--
ALTER TABLE `safe_spaces`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=213;

--
-- AUTO_INCREMENT for table `safe_zones`
--
ALTER TABLE `safe_zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `support_referrals`
--
ALTER TABLE `support_referrals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `system_statistics`
--
ALTER TABLE `system_statistics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `upazilas`
--
ALTER TABLE `upazilas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_alert_settings`
--
ALTER TABLE `user_alert_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_area_ratings`
--
ALTER TABLE `user_area_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_preferences`
--
ALTER TABLE `user_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `walk_sessions`
--
ALTER TABLE `walk_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

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

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `ev_nightly_maintenance` ON SCHEDULE EVERY 1 DAY STARTS '2026-02-20 02:00:00' ON COMPLETION PRESERVE ENABLE COMMENT 'Nightly cleanup: stale notifications, idle sessions, expired ale' DO CALL cleanup_old_data()$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- =============================================================================
-- FIXED: Appended missing definitions (Functions, Procedures, Views, Triggers)
-- =============================================================================

DELIMITER $$

-- FUNCTIONS
DROP FUNCTION IF EXISTS `get_incident_danger_level`$$
CREATE FUNCTION `get_incident_danger_level`(`p_safety_score` DECIMAL(5,2)) RETURNS VARCHAR(20) DETERMINISTIC READS SQL DATA
BEGIN
  IF p_safety_score >= 8.0 THEN RETURN 'safe';
  ELSEIF p_safety_score >= 6.0 THEN RETURN 'moderate';
  ELSEIF p_safety_score >= 4.0 THEN RETURN 'danger';
  ELSE RETURN 'critical';
  END IF;
END$$

DROP FUNCTION IF EXISTS `format_distance_km`$$
CREATE FUNCTION `format_distance_km`(`p_distance_meters` DECIMAL(10,2)) RETURNS VARCHAR(20) DETERMINISTIC READS SQL DATA
BEGIN
  IF p_distance_meters < 1000 THEN RETURN CONCAT(ROUND(p_distance_meters), ' m');
  ELSEIF p_distance_meters < 10000 THEN RETURN CONCAT(ROUND(p_distance_meters / 1000, 1), ' km');
  ELSE RETURN CONCAT(ROUND(p_distance_meters / 1000), ' km');
  END IF;
END$$

-- PROCEDURES
DROP PROCEDURE IF EXISTS `calculate_user_safety_score`$$
CREATE PROCEDURE `calculate_user_safety_score`(IN `p_user_id` INT)
BEGIN
  WITH report_stats AS (
    SELECT COUNT(*) AS total_reports, SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) AS resolved_count, COUNT(DISTINCT category) AS category_diversity FROM incident_reports WHERE user_id = p_user_id
  ), course_stats AS (
    SELECT COUNT(*) AS courses_completed, COALESCE(AVG(rating), 0) AS avg_rating FROM course_enrollments WHERE user_id = p_user_id AND status = 'completed'
  ), response_stats AS (
    SELECT COUNT(*) AS responses_given FROM alert_responses WHERE responder_id = p_user_id
  )
  SELECT p_user_id AS user_id,
    LEAST(10.0, ROUND((r.total_reports * 0.10) + (r.resolved_count * 0.20) + (r.category_diversity * 0.15) + (c.courses_completed * 0.25) + (rs.responses_given * 0.30), 2)) AS engagement_score
  FROM report_stats r, course_stats c, response_stats rs;
END$$

DROP PROCEDURE IF EXISTS `get_incident_heatmap_data`$$
CREATE PROCEDURE `get_incident_heatmap_data`(IN `p_lat_min` DECIMAL(10,8), IN `p_lat_max` DECIMAL(10,8), IN `p_lng_min` DECIMAL(11,8), IN `p_lng_max` DECIMAL(11,8), IN `p_days_back` INT)
BEGIN
  SELECT ROUND(latitude, 2) AS grid_lat, ROUND(longitude, 2) AS grid_lng, COUNT(*) AS incident_count,
    SUM(CASE WHEN severity='critical' THEN 4 WHEN severity='high' THEN 3 WHEN severity='medium' THEN 2 ELSE 1 END) AS weighted_score,
    get_incident_danger_level(GREATEST(0, 10 - LEAST(10, COUNT(*) * 0.8))) AS zone_danger_level,
    MAX(reported_date) as latest_incident,
    GROUP_CONCAT(DISTINCT category) as categories
  FROM `incident_reports`
  WHERE status != 'disputed' AND latitude BETWEEN p_lat_min AND p_lat_max AND longitude BETWEEN p_lng_min AND p_lng_max AND reported_date >= DATE_SUB(NOW(), INTERVAL p_days_back DAY)
  GROUP BY grid_lat, grid_lng HAVING incident_count > 0 ORDER BY weighted_score DESC LIMIT 500;
END$$

DROP PROCEDURE IF EXISTS `cleanup_old_data`$$
CREATE PROCEDURE `cleanup_old_data`()
BEGIN
  DELETE FROM `notifications` WHERE `is_read` = 1 AND `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);
  UPDATE `user_sessions` SET `is_active` = 0 WHERE `last_activity` < DATE_SUB(NOW(), INTERVAL 30 DAY) AND `is_active` = 1;
  UPDATE `alerts` SET `is_active` = 0 WHERE `end_time` IS NOT NULL AND `end_time` < NOW() AND `is_active` = 1;
END$$

-- TRIGGERS
DROP TRIGGER IF EXISTS `trg_after_report_insert`$$
CREATE TRIGGER `trg_after_report_insert` AFTER INSERT ON `incident_reports` FOR EACH ROW
BEGIN
  INSERT INTO `notifications` (`user_id`, `title`, `message`, `type`, `action_url`, `created_at`)
  SELECT u.id, 'New Incident Report', CONCAT('New ', NEW.category, ' report'), 'report', CONCAT('/admin_dashboard.php?report=', NEW.id), NOW()
  FROM `users` u WHERE u.is_admin = 1 AND u.is_active = 1;
END$$

DROP TRIGGER IF EXISTS `trg_after_report_status_update`$$
CREATE TRIGGER `trg_after_report_status_update` AFTER UPDATE ON `incident_reports` FOR EACH ROW
BEGIN
  IF OLD.status != NEW.status THEN
    INSERT INTO `notifications` (`user_id`, `title`, `message`, `type`, `action_url`, `created_at`)
    VALUES (NEW.user_id, 'Report Status Updated', CONCAT('Your report is now: ', NEW.status), 'update', CONCAT('/my_reports.php?id=', NEW.id), NOW());
  END IF;
END$$

DROP TRIGGER IF EXISTS `trg_after_panic_alert_insert`$$
CREATE TRIGGER `trg_after_panic_alert_insert` AFTER INSERT ON `panic_alerts` FOR EACH ROW
BEGIN
  INSERT INTO `alerts` (`title`, `description`, `type`, `severity`, `location_name`, `latitude`, `longitude`, `radius_km`, `start_time`, `is_active`, `source_type`, `source_user_id`)
  VALUES ('Emergency Panic Alert', 'Panic alert triggered', 'emergency', 'critical', COALESCE(NEW.location_name, 'Unknown'), NEW.latitude, NEW.longitude, 0.5, NOW(), 1, 'user_report', NEW.user_id);
END$$

DELIMITER ;

-- VIEWS (Drop placeholder tables first!)
DROP TABLE IF EXISTS `vw_active_incidents_with_user`;
CREATE OR REPLACE VIEW `vw_active_incidents_with_user` AS
SELECT ir.id, ir.title, ir.category, ir.severity, ir.status, ir.latitude, ir.longitude, ir.location_name, ir.reported_date,
  CASE WHEN ir.is_anonymous = 1 THEN 'Anonymous' ELSE u.display_name END AS reporter_name,
  CASE WHEN ir.is_anonymous = 1 THEN NULL ELSE u.email END AS reporter_email,
  COALESCE(d.display_name, 'Unassigned') AS assigned_to_name
FROM `incident_reports` ir
JOIN `users` u ON ir.user_id = u.id
LEFT JOIN `users` d ON ir.assigned_to = d.id
WHERE ir.status != 'resolved'
ORDER BY ir.reported_date DESC;

DROP TABLE IF EXISTS `vw_user_safety_summary`;
CREATE OR REPLACE VIEW `vw_user_safety_summary` AS
SELECT u.id AS user_id, u.display_name, u.email, u.is_admin, u.created_at AS member_since,
  COUNT(DISTINCT ir.id) AS total_reports,
  SUM(CASE WHEN ir.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
  ROUND(COUNT(DISTINCT ir.id) * 0.3 + COUNT(DISTINCT ce.id) * 0.5, 1) AS safety_engagement_score,
  RANK() OVER (ORDER BY (COUNT(DISTINCT ir.id) * 0.3 + COUNT(DISTINCT ce.id) * 0.5) DESC) as community_rank,
  PERCENT_RANK() OVER (ORDER BY (COUNT(DISTINCT ir.id) * 0.3 + COUNT(DISTINCT ce.id) * 0.5)) as percentile
FROM `users` u
LEFT JOIN `incident_reports` ir ON u.id = ir.user_id
LEFT JOIN `course_enrollments` ce ON u.id = ce.user_id AND ce.status = 'completed'
GROUP BY u.id, u.display_name;

DROP TABLE IF EXISTS `vw_area_danger_rankings`;
CREATE OR REPLACE VIEW `vw_area_danger_rankings` AS
SELECT ass.id, ass.area_name, ass.safety_score, ass.total_incidents,
  get_incident_danger_level(ass.safety_score) AS danger_level,
  RANK() OVER (ORDER BY ass.safety_score DESC) AS safety_rank
FROM `area_safety_scores` ass
ORDER BY ass.safety_score DESC;

DROP TABLE IF EXISTS `vw_monthly_incident_trend`;
CREATE OR REPLACE VIEW `vw_monthly_incident_trend` AS
SELECT YEAR(reported_date) AS report_year, MONTH(reported_date) AS report_month, category, COUNT(*) AS incident_count
FROM `incident_reports`
GROUP BY YEAR(reported_date), MONTH(reported_date), category;

