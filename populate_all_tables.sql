-- ========================================
-- COMPREHENSIVE DATABASE POPULATION SCRIPT
-- For space_login database
-- Populates all 37 tables with Bangladesh-centric data
-- Users: 1, 2, 5, 6, 7
-- Date Range: January 1-16, 2026
-- ========================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+06:00";

-- ========================================
-- 1. ADD NEW USERS (5, 6, 7)
-- ========================================

INSERT INTO `users` (`id`, `username`, `email`, `phone`, `bio`, `password`, `display_name`, `provider`, `email_verified`, `is_admin`, `status`, `is_active`, `created_at`, `last_login`, `verification_status`) VALUES
(5, NULL, 'fatima.rahman@gmail.com', '+8801712345001', 'Community activist from Gulshan', '$2y$10$Fg97eJDVI9xJiNdV3Js5POtKt4BMooAPk.s5eTGmzE.Rv0LaEEe4u', 'Fatima Rahman', 'password', 1, 0, 'active', 1, '2025-12-20 10:30:00', '2026-01-15 14:20:00', 'verified'),
(6, NULL, 'karim.ahmed@yahoo.com', '+8801812345002', 'Safety advocate from Mirpur', '$2y$10$Fg97eJDVI9xJiNdV3Js5POtKt4BMooAPk.s5eTGmzE.Rv0LaEEe4u', 'Karim Ahmed', 'password', 1, 0, 'active', 1, '2025-12-22 09:15:00', '2026-01-16 08:45:00', 'verified'),
(7, NULL, 'nusrat.jahan@outlook.com', '+8801912345003', 'Women rights activist from Dhanmondi', '$2y$10$Fg97eJDVI9xJiNdV3Js5POtKt4BMooAPk.s5eTGmzE.Rv0LaEEe4u', 'Nusrat Jahan', 'password', 1, 0, 'active', 1, '2025-12-25 11:45:00', '2026-01-14 16:30:00', 'verified');

-- ========================================
-- 2. USER PREFERENCES
-- ========================================

INSERT INTO `user_preferences` (`user_id`, `email_notifications`, `sms_notifications`, `push_notifications`, `alert_radius_km`, `profile_visibility`, `location_sharing`, `anonymous_reporting`, `preferred_language`, `timezone`, `theme_preference`) VALUES
(2, 1, 1, 1, 2.00, 'public', 1, 0, 'bn', 'Asia/Dhaka', 'light'),
(5, 1, 1, 1, 3.00, 'public', 1, 0, 'bn', 'Asia/Dhaka', 'auto'),
(6, 1, 0, 1, 1.50, 'private', 0, 1, 'bn', 'Asia/Dhaka', 'dark'),
(7, 1, 1, 1, 2.50, 'public', 1, 0, 'en', 'Asia/Dhaka', 'light');

-- ========================================
-- 3. EMERGENCY CONTACTS
-- ========================================

INSERT INTO `emergency_contacts` (`user_id`, `contact_name`, `phone_number`, `relationship`, `priority`, `is_verified`, `notification_methods`, `is_active`, `created_at`) VALUES
-- User 1 contacts
(1, 'Anik Manik', '01871745957', 'Brother', 1, 1, 'sms,call', 1, '2025-11-24 00:08:39'),
(1, 'Rahima Begum', '01712345100', 'Mother', 2, 1, 'sms,call', 1, '2025-12-01 10:15:00'),
-- User 2 contacts
(2, 'Shakib Hassan', '01812345101', 'Friend', 1, 1, 'sms,call,whatsapp', 1, '2025-12-05 14:20:00'),
(2, 'Ayesha Siddique', '01912345102', 'Sister', 2, 1, 'sms,call', 1, '2025-12-05 14:22:00'),
-- User 5 contacts
(5, 'Mahmud Rahman', '01712345103', 'Husband', 1, 1, 'sms,call,whatsapp', 1, '2025-12-20 11:00:00'),
(5, 'Salma Khatun', '01812345104', 'Mother', 2, 1, 'sms,call', 1, '2025-12-20 11:05:00'),
-- User 6 contacts
(6, 'Nasrin Ahmed', '01912345105', 'Wife', 1, 1, 'sms,call,whatsapp', 1, '2025-12-22 10:00:00'),
(6, 'Rashid Ahmed', '01712345106', 'Father', 2, 1, 'sms,call', 1, '2025-12-22 10:10:00'),
-- User 7 contacts
(7, 'Tahmina Akter', '01812345107', 'Sister', 1, 1, 'sms,call', 1, '2025-12-25 12:00:00'),
(7, 'Jahangir Alam', '01912345108', 'Brother', 2, 1, 'sms,call,whatsapp', 1, '2025-12-25 12:05:00');

-- ========================================
-- 4. INCIDENT REPORTS (10 per user = 50 total)
-- Date range: January 1-16, 2026
-- ========================================

-- User 1 Reports (10)
INSERT INTO `incident_reports` (`user_id`, `title`, `description`, `category`, `severity`, `status`, `location_name`, `latitude`, `longitude`, `address`, `incident_date`, `reported_date`, `is_anonymous`, `is_public`, `witness_count`) VALUES
(1, 'Street Harassment near TSC', 'Experienced verbal harassment while walking near TSC area', 'harassment', 'medium', 'pending', 'Dhaka University TSC', 23.73500000, 90.39300000, 'TSC, Dhaka University', '2026-01-02 18:30:00', '2026-01-02 19:15:00', 0, 1, 2),
(1, 'Bag Snatching Attempt at Farmgate', 'Someone tried to snatch my bag while I was waiting for bus', 'theft', 'high', 'under_review', 'Farmgate', 23.75560000, 90.38890000, 'Farmgate Bus Stop, Dhaka', '2026-01-05 20:15:00', '2026-01-05 21:00:00', 0, 1, 1),
(1, 'Stalking Incident in Dhanmondi', 'Being followed by unknown person for 20 minutes', 'stalking', 'high', 'investigating', 'Dhanmondi', 23.74650000, 90.37000000, 'Road 27, Dhanmondi', '2026-01-08 19:45:00', '2026-01-08 20:30:00', 0, 1, 0),
(1, 'Cyberbullying on Social Media', 'Receiving threatening messages on Facebook', 'cyberbullying', 'medium', 'pending', 'Online', NULL, NULL, 'Social Media Platform', '2026-01-10 22:00:00', '2026-01-11 09:00:00', 0, 0, 0),
(1, 'Workplace Discrimination', 'Facing gender discrimination at workplace', 'discrimination', 'medium', 'under_review', 'Motijheel', 23.72220000, 90.41670000, 'Motijheel Commercial Area', '2026-01-12 11:00:00', '2026-01-12 18:00:00', 1, 0, 3),
(1, 'Assault at Shahbagh', 'Physical assault during protest', 'assault', 'critical', 'investigating', 'Shahbagh', 23.73610000, 90.39580000, 'Shahbagh Intersection', '2026-01-13 16:00:00', '2026-01-13 17:30:00', 0, 1, 5),
(1, 'Vandalism in Uttara', 'Car vandalized in parking lot', 'vandalism', 'medium', 'pending', 'Uttara', 23.87000000, 90.38000000, 'Sector 7, Uttara', '2026-01-14 08:00:00', '2026-01-14 10:00:00', 0, 1, 0),
(1, 'Harassment at Shopping Mall', 'Inappropriate behavior at Bashundhara City', 'harassment', 'low', 'pending', 'Panthapath', 23.75000000, 90.37500000, 'Bashundhara City Mall', '2026-01-15 15:30:00', '2026-01-15 16:00:00', 0, 1, 2),
(1, 'Theft of Mobile Phone', 'Mobile phone stolen from pocket in crowded bus', 'theft', 'medium', 'pending', 'Mohakhali', 23.77780000, 90.40560000, 'Mohakhali Bus Terminal', '2026-01-16 07:45:00', '2026-01-16 08:30:00', 0, 1, 0),
(1, 'Online Scam Attempt', 'Received fraudulent payment request', 'cyberbullying', 'low', 'pending', 'Online', NULL, NULL, 'Online Platform', '2026-01-16 20:00:00', '2026-01-16 20:30:00', 0, 0, 0);

-- User 2 Reports (10)
INSERT INTO `incident_reports` (`user_id`, `title`, `description`, `category`, `severity`, `status`, `location_name`, `latitude`, `longitude`, `address`, `incident_date`, `reported_date`, `is_anonymous`, `is_public`, `witness_count`) VALUES
(2, 'Eve Teasing at Gulshan', 'Experienced eve teasing near Gulshan Lake', 'harassment', 'medium', 'pending', 'Gulshan', 23.79470000, 90.41440000, 'Gulshan Lake Park', '2026-01-03 17:00:00', '2026-01-03 18:00:00', 0, 1, 1),
(2, 'Pickpocketing in New Market', 'Wallet stolen from bag at New Market', 'theft', 'high', 'under_review', 'New Market', 23.73330000, 90.39000000, 'New Market, Dhaka', '2026-01-04 14:30:00', '2026-01-04 16:00:00', 0, 1, 0),
(2, 'Road Rage Incident', 'Threatened by rickshaw puller', 'assault', 'medium', 'pending', 'Banani', 23.79060000, 90.40560000, 'Banani Road 11', '2026-01-06 09:15:00', '2026-01-06 10:00:00', 0, 1, 2),
(2, 'Stalking Near Home', 'Unknown person following me to home', 'stalking', 'high', 'investigating', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 10', '2026-01-07 21:00:00', '2026-01-07 22:00:00', 0, 1, 0),
(2, 'Discrimination at Restaurant', 'Refused service due to appearance', 'discrimination', 'low', 'pending', 'Dhanmondi', 23.74650000, 90.37000000, 'Dhanmondi Road 2', '2026-01-09 13:00:00', '2026-01-09 14:30:00', 0, 0, 1),
(2, 'Vandalism of Property', 'House wall spray painted with graffiti', 'vandalism', 'low', 'pending', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 11', '2026-01-11 06:00:00', '2026-01-11 08:00:00', 0, 1, 0),
(2, 'Cyber Harassment', 'Receiving abusive emails', 'cyberbullying', 'medium', 'pending', 'Online', NULL, NULL, 'Email Platform', '2026-01-12 19:00:00', '2026-01-12 20:00:00', 1, 0, 0),
(2, 'Assault by Group', 'Attacked by group of people', 'assault', 'critical', 'investigating', 'Old Dhaka', 23.71000000, 90.40000000, 'Lalbagh Area', '2026-01-14 22:00:00', '2026-01-14 23:30:00', 0, 1, 4),
(2, 'Theft from Vehicle', 'Items stolen from parked car', 'theft', 'medium', 'pending', 'Gulshan', 23.79470000, 90.41440000, 'Gulshan 2 Circle', '2026-01-15 11:00:00', '2026-01-15 12:00:00', 0, 1, 0),
(2, 'Harassment at Workplace', 'Sexual harassment by colleague', 'harassment', 'high', 'under_review', 'Kawran Bazar', 23.75000000, 90.39000000, 'Kawran Bazar Office Area', '2026-01-16 10:00:00', '2026-01-16 18:00:00', 1, 0, 2);

-- User 5 Reports (10)
INSERT INTO `incident_reports` (`user_id`, `title`, `description`, `category`, `severity`, `status`, `location_name`, `latitude`, `longitude`, `address`, `incident_date`, `reported_date`, `is_anonymous`, `is_public`, `witness_count`) VALUES
(5, 'Domestic Violence Incident', 'Witnessed domestic violence in neighborhood', 'assault', 'critical', 'investigating', 'Gulshan', 23.79000000, 90.41000000, 'Gulshan 1, Block A', '2026-01-01 23:00:00', '2026-01-02 08:00:00', 1, 0, 1),
(5, 'Child Harassment', 'Child being harassed by stranger', 'harassment', 'high', 'investigating', 'Gulshan', 23.79470000, 90.41440000, 'Gulshan Lake Area', '2026-01-04 16:00:00', '2026-01-04 17:00:00', 0, 1, 3),
(5, 'Burglary Attempt', 'Someone tried to break into house', 'theft', 'high', 'under_review', 'Gulshan', 23.79000000, 90.41000000, 'Gulshan 1, Road 45', '2026-01-06 03:00:00', '2026-01-06 07:00:00', 0, 1, 0),
(5, 'Street Harassment', 'Catcalling and inappropriate comments', 'harassment', 'medium', 'pending', 'Banani', 23.79060000, 90.40560000, 'Banani Main Road', '2026-01-08 18:30:00', '2026-01-08 19:00:00', 0, 1, 2),
(5, 'Online Fraud', 'Scammed through online shopping', 'cyberbullying', 'medium', 'pending', 'Online', NULL, NULL, 'E-commerce Platform', '2026-01-10 14:00:00', '2026-01-10 15:00:00', 0, 0, 0),
(5, 'Vandalism in Park', 'Public property damaged in park', 'vandalism', 'low', 'pending', 'Gulshan', 23.79470000, 90.41440000, 'Gulshan Lake Park', '2026-01-11 07:00:00', '2026-01-11 09:00:00', 0, 1, 1),
(5, 'Discrimination at Clinic', 'Discriminatory treatment at medical facility', 'discrimination', 'medium', 'pending', 'Banani', 23.79060000, 90.40560000, 'Banani Medical Center', '2026-01-13 10:00:00', '2026-01-13 12:00:00', 0, 0, 1),
(5, 'Stalking Incident', 'Being followed by unknown vehicle', 'stalking', 'high', 'investigating', 'Gulshan', 23.79000000, 90.41000000, 'Gulshan Avenue', '2026-01-14 20:00:00', '2026-01-14 21:00:00', 0, 1, 0),
(5, 'Assault at Market', 'Physical altercation at market', 'assault', 'medium', 'pending', 'Gulshan', 23.79470000, 90.41440000, 'Gulshan 2 Market', '2026-01-15 12:00:00', '2026-01-15 13:00:00', 0, 1, 4),
(5, 'Theft of Jewelry', 'Gold chain snatched on street', 'theft', 'critical', 'investigating', 'Gulshan', 23.79000000, 90.41000000, 'Gulshan 1 Main Road', '2026-01-16 19:00:00', '2026-01-16 19:30:00', 0, 1, 2);

-- User 6 Reports (10)
INSERT INTO `incident_reports` (`user_id`, `title`, `description`, `category`, `severity`, `status`, `location_name`, `latitude`, `longitude`, `address`, `incident_date`, `reported_date`, `is_anonymous`, `is_public`, `witness_count`) VALUES
(6, 'Mugging at Night', 'Robbed at knifepoint', 'assault', 'critical', 'investigating', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 10 Circle', '2026-01-02 23:30:00', '2026-01-03 00:30:00', 0, 1, 0),
(6, 'Vandalism of Shop', 'Shop window broken by vandals', 'vandalism', 'medium', 'pending', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 11 Shopping Center', '2026-01-05 05:00:00', '2026-01-05 08:00:00', 0, 1, 1),
(6, 'Harassment in Public Transport', 'Inappropriate touching in bus', 'harassment', 'high', 'under_review', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 10 Bus Stand', '2026-01-07 08:00:00', '2026-01-07 09:00:00', 1, 0, 3),
(6, 'Theft from Home', 'Burglary while family was away', 'theft', 'high', 'investigating', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 12, Block C', '2026-01-09 14:00:00', '2026-01-09 18:00:00', 0, 1, 0),
(6, 'Cyberbullying of Child', 'Child being bullied online', 'cyberbullying', 'high', 'investigating', 'Online', NULL, NULL, 'Social Media', '2026-01-10 16:00:00', '2026-01-10 17:00:00', 0, 0, 0),
(6, 'Discrimination at School', 'Child facing discrimination at school', 'discrimination', 'medium', 'pending', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur Model School', '2026-01-11 11:00:00', '2026-01-11 15:00:00', 0, 0, 2),
(6, 'Stalking Near Workplace', 'Being followed after work', 'stalking', 'high', 'investigating', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur DOHS', '2026-01-13 19:00:00', '2026-01-13 20:00:00', 0, 1, 0),
(6, 'Assault in Neighborhood', 'Fight between neighbors escalated', 'assault', 'medium', 'pending', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 11, Section 7', '2026-01-14 17:00:00', '2026-01-14 18:00:00', 0, 1, 5),
(6, 'Vandalism of Car', 'Car scratched and tires deflated', 'vandalism', 'medium', 'pending', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 10 Parking', '2026-01-15 06:00:00', '2026-01-15 07:00:00', 0, 1, 0),
(6, 'Theft of Motorcycle', 'Motorcycle stolen from parking', 'theft', 'critical', 'investigating', 'Mirpur', 23.81670000, 90.36670000, 'Mirpur 12 Market', '2026-01-16 21:00:00', '2026-01-16 22:00:00', 0, 1, 1);

-- User 7 Reports (10)
INSERT INTO `incident_reports` (`user_id`, `title`, `description`, `category`, `severity`, `status`, `location_name`, `latitude`, `longitude`, `address`, `incident_date`, `reported_date`, `is_anonymous`, `is_public`, `witness_count`) VALUES
(7, 'Harassment at Rally', 'Harassed during women rights rally', 'harassment', 'high', 'investigating', 'Dhanmondi', 23.74650000, 90.37000000, 'Dhanmondi 32', '2026-01-01 15:00:00', '2026-01-01 17:00:00', 0, 1, 10),
(7, 'Cyberbullying Campaign', 'Coordinated online harassment campaign', 'cyberbullying', 'critical', 'investigating', 'Online', NULL, NULL, 'Multiple Platforms', '2026-01-03 10:00:00', '2026-01-03 11:00:00', 0, 1, 0),
(7, 'Stalking by Ex-Partner', 'Ex-partner stalking and threatening', 'stalking', 'critical', 'investigating', 'Dhanmondi', 23.74650000, 90.37000000, 'Dhanmondi Road 27', '2026-01-05 20:00:00', '2026-01-05 21:00:00', 0, 1, 1),
(7, 'Assault at Office', 'Physical assault by colleague', 'assault', 'critical', 'investigating', 'Dhanmondi', 23.74650000, 90.37000000, 'Dhanmondi Office Complex', '2026-01-08 14:00:00', '2026-01-08 15:00:00', 0, 1, 3),
(7, 'Discrimination in Court', 'Gender discrimination during legal proceedings', 'discrimination', 'high', 'under_review', 'Ramna', 23.73060000, 90.40140000, 'High Court Area', '2026-01-10 11:00:00', '2026-01-10 13:00:00', 0, 1, 2),
(7, 'Theft of Documents', 'Important legal documents stolen', 'theft', 'high', 'investigating', 'Dhanmondi', 23.74650000, 90.37000000, 'Dhanmondi Road 15', '2026-01-12 09:00:00', '2026-01-12 10:00:00', 0, 1, 0),
(7, 'Vandalism of Office', 'Office vandalized, equipment damaged', 'vandalism', 'high', 'investigating', 'Dhanmondi', 23.74650000, 90.37000000, 'Dhanmondi 27 Office', '2026-01-13 04:00:00', '2026-01-13 08:00:00', 0, 1, 0),
(7, 'Harassment at Conference', 'Sexual harassment at professional conference', 'harassment', 'high', 'under_review', 'Banani', 23.79060000, 90.40560000, 'Banani Convention Center', '2026-01-14 16:00:00', '2026-01-14 18:00:00', 0, 1, 4),
(7, 'Online Threats', 'Death threats received via email', 'cyberbullying', 'critical', 'investigating', 'Online', NULL, NULL, 'Email', '2026-01-15 22:00:00', '2026-01-15 23:00:00', 0, 1, 0),
(7, 'Assault at Home', 'Home invasion and assault', 'assault', 'critical', 'investigating', 'Dhanmondi', 23.74650000, 90.37000000, 'Dhanmondi Road 8', '2026-01-16 02:00:00', '2026-01-16 03:00:00', 0, 1, 0);

-- ========================================
-- 5. NOTIFICATIONS (for new reports)
-- ========================================

INSERT INTO `notifications` (`user_id`, `title`, `message`, `type`, `action_url`, `is_read`, `created_at`) VALUES
-- Notifications for User 1
(1, 'Report Submitted', 'Your incident report has been submitted successfully', 'report_update', 'view_report.php', 0, '2026-01-02 19:15:00'),
(1, 'Report Under Review', 'Your report about bag snatching is under review', 'report_update', 'view_report.php', 0, '2026-01-05 21:30:00'),
-- Notifications for User 2
(2, 'Report Submitted', 'Your incident report has been submitted successfully', 'report_update', 'view_report.php', 0, '2026-01-03 18:00:00'),
(2, 'Report Under Review', 'Your report about pickpocketing is under review', 'report_update', 'view_report.php', 0, '2026-01-04 16:30:00'),
-- Notifications for User 5
(5, 'Report Submitted', 'Your incident report has been submitted successfully', 'report_update', 'view_report.php', 0, '2026-01-02 08:00:00'),
(5, 'Report Under Investigation', 'Your report is being investigated by authorities', 'report_update', 'view_report.php', 0, '2026-01-04 18:00:00'),
-- Notifications for User 6
(6, 'Report Submitted', 'Your incident report has been submitted successfully', 'report_update', 'view_report.php', 0, '2026-01-03 00:30:00'),
(6, 'Report Under Investigation', 'Your report about mugging is being investigated', 'report_update', 'view_report.php', 0, '2026-01-03 09:00:00'),
-- Notifications for User 7
(7, 'Report Submitted', 'Your incident report has been submitted successfully', 'report_update', 'view_report.php', 0, '2026-01-01 17:00:00'),
(7, 'Report Under Investigation', 'Your report is being investigated by authorities', 'report_update', 'view_report.php', 0, '2026-01-03 12:00:00');

-- ========================================
-- 6. ALERTS (System-generated based on reports)
-- ========================================

INSERT INTO `alerts` (`title`, `description`, `type`, `severity`, `location_name`, `latitude`, `longitude`, `radius_km`, `start_time`, `is_active`, `source_type`, `source_user_id`) VALUES
('High Crime Alert - Mirpur 10', 'Multiple incidents reported in Mirpur 10 area. Please be cautious.', 'warning', 'high', 'Mirpur', 23.81670000, 90.36670000, 2.00, '2026-01-10 10:00:00', 1, 'system', NULL),
('Safety Alert - Gulshan Area', 'Increased reports of theft in Gulshan area. Stay vigilant.', 'warning', 'medium', 'Gulshan', 23.79470000, 90.41440000, 1.50, '2026-01-12 09:00:00', 1, 'system', NULL),
('Emergency Alert - Dhanmondi', 'Critical incidents reported in Dhanmondi. Avoid area if possible.', 'emergency', 'critical', 'Dhanmondi', 23.74650000, 90.37000000, 1.00, '2026-01-16 04:00:00', 1, 'system', NULL),
('Community Alert - Stalking Reports', 'Multiple stalking incidents reported. Travel in groups when possible.', 'warning', 'high', 'Dhaka', 23.75000000, 90.38000000, 5.00, '2026-01-14 15:00:00', 1, 'system', NULL),
('Cyber Safety Alert', 'Increase in online harassment and scams. Be cautious online.', 'info', 'medium', 'Online', NULL, NULL, 0.00, '2026-01-11 10:00:00', 1, 'system', NULL);

-- ========================================
-- 7. GROUP MEMBERS (Add new users to existing groups)
-- ========================================

INSERT INTO `group_members` (`group_id`, `user_id`, `role`, `joined_at`, `status`, `contribution_score`) VALUES
-- User 5 joins Gulshan Safety Network
(2, 5, 'member', '2025-12-20 12:00:00', 'active', 25),
-- User 6 joins Mirpur Community Safety
(3, 6, 'member', '2025-12-22 10:00:00', 'active', 30),
-- User 7 joins Dhanmondi Community Watch
(1, 7, 'moderator', '2025-12-25 13:00:00', 'active', 45),
-- User 5 also joins Banani Community Watch
(5, 5, 'member', '2025-12-28 14:00:00', 'active', 15),
-- User 6 also joins Uttara Residents Safety Group
(4, 6, 'member', '2026-01-02 11:00:00', 'active', 10);

-- ========================================
-- 8. GROUP ALERTS
-- ========================================

INSERT INTO `group_alerts` (`group_id`, `posted_by`, `alert_type`, `title`, `message`, `location_details`, `severity`, `is_verified`, `status`, `created_at`) VALUES
(2, 5, 'safety_warning', 'Suspicious Activity in Gulshan 1', 'Unknown persons loitering near residential buildings. Please be alert.', 'Gulshan 1, Road 40-50', 'medium', 1, 'active', '2026-01-05 20:00:00'),
(3, 6, 'emergency', 'Fire Incident in Mirpur 11', 'Fire broke out in building. Fire service on the way.', 'Mirpur 11, Block D', 'critical', 1, 'resolved', '2026-01-08 15:30:00'),
(1, 7, 'missing_person', 'Missing Child Alert', 'A 7-year-old child missing since morning. Last seen near Dhanmondi Lake.', 'Dhanmondi Lake Area', 'critical', 1, 'resolved', '2026-01-10 10:00:00'),
(2, 5, 'suspicious_activity', 'Suspicious Vehicle', 'Unidentified vehicle circling the area repeatedly.', 'Gulshan 2, Block E', 'medium', 0, 'active', '2026-01-13 18:00:00'),
(3, 6, 'safety_warning', 'Street Light Outage', 'Multiple street lights not working. Area is dark at night.', 'Mirpur 10, Road 5-8', 'low', 1, 'active', '2026-01-15 19:00:00');

-- ========================================
-- 9. PANIC ALERTS
-- ========================================

INSERT INTO `panic_alerts` (`user_id`, `trigger_method`, `location_name`, `latitude`, `longitude`, `message`, `emergency_contacts_notified`, `police_notified`, `status`, `triggered_at`, `resolved_at`) VALUES
(5, 'app_button', 'Gulshan 1', 23.79000000, 90.41000000, 'Emergency! Need help immediately!', 2, 1, 'resolved', '2026-01-06 03:15:00', '2026-01-06 03:45:00'),
(6, 'app_button', 'Mirpur 10', 23.81670000, 90.36670000, 'Being robbed!', 2, 1, 'resolved', '2026-01-02 23:30:00', '2026-01-03 00:00:00'),
(7, 'app_button', 'Dhanmondi Road 8', 23.74650000, 90.37000000, 'Home invasion! Help!', 2, 1, 'resolved', '2026-01-16 02:00:00', '2026-01-16 02:30:00'),
(2, 'app_button', 'Old Dhaka', 23.71000000, 90.40000000, 'Being attacked!', 2, 1, 'resolved', '2026-01-14 22:00:00', '2026-01-14 22:30:00');

-- ========================================
-- 10. WALK SESSIONS
-- ========================================

INSERT INTO `walk_sessions` (`user_id`, `session_token`, `start_time`, `end_time`, `status`, `destination`, `estimated_duration_minutes`) VALUES
(5, SHA2(CONCAT('walk_session_5_1', UNIX_TIMESTAMP()), 256), '2026-01-05 18:00:00', '2026-01-05 18:45:00', 'completed', 'Gulshan 2 Market', 45),
(5, SHA2(CONCAT('walk_session_5_2', UNIX_TIMESTAMP()), 256), '2026-01-12 19:00:00', '2026-01-12 19:30:00', 'completed', 'Banani Lake Park', 30),
(6, SHA2(CONCAT('walk_session_6_1', UNIX_TIMESTAMP()), 256), '2026-01-07 08:00:00', '2026-01-07 08:20:00', 'completed', 'Mirpur 10 Bus Stand', 20),
(6, SHA2(CONCAT('walk_session_6_2', UNIX_TIMESTAMP()), 256), '2026-01-13 19:00:00', '2026-01-13 19:25:00', 'completed', 'Mirpur DOHS', 25),
(7, SHA2(CONCAT('walk_session_7_1', UNIX_TIMESTAMP()), 256), '2026-01-09 17:00:00', '2026-01-09 17:40:00', 'completed', 'Dhanmondi 32', 40),
(7, SHA2(CONCAT('walk_session_7_2', UNIX_TIMESTAMP()), 256), '2026-01-14 16:00:00', '2026-01-14 16:50:00', 'completed', 'Banani Convention Center', 50);

-- ========================================
-- 11. COURSE ENROLLMENTS
-- ========================================

INSERT INTO `course_enrollments` (`user_id`, `course_id`, `progress_percentage`, `status`, `started_at`, `completed_at`, `certificate_issued`, `rating`, `feedback`) VALUES
(5, 1, 100.00, 'completed', '2025-12-21 10:00:00', '2025-12-28 15:00:00', 1, 5, 'Excellent course! Very helpful for self-defense.'),
(5, 2, 75.00, 'in_progress', '2026-01-02 09:00:00', NULL, 0, NULL, NULL),
(6, 3, 100.00, 'completed', '2025-12-23 11:00:00', '2026-01-05 14:00:00', 1, 5, 'Very informative about legal rights.'),
(6, 4, 50.00, 'in_progress', '2026-01-08 10:00:00', NULL, 0, NULL, NULL),
(7, 3, 100.00, 'completed', '2025-12-26 12:00:00', '2026-01-10 16:00:00', 1, 5, 'Essential knowledge for activists.'),
(7, 5, 100.00, 'completed', '2026-01-03 09:00:00', '2026-01-12 17:00:00', 1, 5, 'Critical information about domestic violence prevention.'),
(7, 7, 80.00, 'in_progress', '2026-01-13 10:00:00', NULL, 0, NULL, NULL);

-- ========================================
-- 12. CERTIFICATES
-- ========================================

INSERT INTO `certificates` (`user_id`, `course_id`, `enrollment_id`, `certificate_number`, `verification_code`, `issued_at`, `is_verified`) VALUES
(5, 1, (SELECT id FROM course_enrollments WHERE user_id = 5 AND course_id = 1 LIMIT 1), 'CERT-2025-001-U5', 'VERIFY-5A1B2C', '2025-12-28 15:00:00', 1),
(6, 3, (SELECT id FROM course_enrollments WHERE user_id = 6 AND course_id = 3 LIMIT 1), 'CERT-2026-002-U6', 'VERIFY-6D3E4F', '2026-01-05 14:00:00', 1),
(7, 3, (SELECT id FROM course_enrollments WHERE user_id = 7 AND course_id = 3 LIMIT 1), 'CERT-2026-003-U7', 'VERIFY-7G5H6I', '2026-01-10 16:00:00', 1),
(7, 5, (SELECT id FROM course_enrollments WHERE user_id = 7 AND course_id = 5 LIMIT 1), 'CERT-2026-004-U7', 'VERIFY-7J7K8L', '2026-01-12 17:00:00', 1);

-- ========================================
-- 13. LEGAL CONSULTATIONS
-- ========================================

INSERT INTO `legal_consultations` (`user_id`, `report_id`, `provider_id`, `consultation_type`, `subject`, `description`, `preferred_date`, `status`, `scheduled_at`, `completed_at`, `rating`, `cost_bdt`) VALUES
(7, (SELECT id FROM incident_reports WHERE user_id = 7 AND category = 'stalking' LIMIT 1), 1, 'emergency', 'Stalking and Harassment', 'Need legal advice regarding stalking by ex-partner', '2026-01-06', 'completed', '2026-01-06 10:00:00', '2026-01-06 11:30:00', 5, 0.00),
(7, (SELECT id FROM incident_reports WHERE user_id = 7 AND category = 'discrimination' LIMIT 1), 2, 'initial', 'Gender Discrimination in Court', 'Seeking legal help for discrimination case', '2026-01-11', 'completed', '2026-01-11 14:00:00', '2026-01-11 15:30:00', 5, 0.00),
(5, (SELECT id FROM incident_reports WHERE user_id = 5 AND category = 'assault' LIMIT 1), 1, 'emergency', 'Domestic Violence', 'Witnessed domestic violence, need legal guidance', '2026-01-03', 'completed', '2026-01-03 09:00:00', '2026-01-03 10:00:00', 4, 0.00),
(2, (SELECT id FROM incident_reports WHERE user_id = 2 AND category = 'harassment' AND severity = 'high' LIMIT 1), 3, 'initial', 'Workplace Sexual Harassment', 'Need legal consultation for workplace harassment', '2026-01-17', 'scheduled', '2026-01-17 11:00:00', NULL, NULL, 0.00);

-- ========================================
-- 14. SUPPORT REFERRALS
-- ========================================

INSERT INTO `support_referrals` (`user_id`, `report_id`, `provider_id`, `referral_type`, `priority`, `reason`, `status`, `referred_at`, `appointment_date`, `completed_at`, `rating`) VALUES
(7, (SELECT id FROM incident_reports WHERE user_id = 7 AND category = 'assault' AND severity = 'critical' LIMIT 1), 2, 'emergency', 'urgent', 'Assault victim needs immediate medical and psychological support', 'completed', '2026-01-08 15:00:00', '2026-01-08 16:00:00', '2026-01-08 17:30:00', 5),
(2, (SELECT id FROM incident_reports WHERE user_id = 2 AND category = 'assault' AND severity = 'critical' LIMIT 1), 1, 'emergency', 'urgent', 'Assault victim needs trauma care', 'completed', '2026-01-14 23:30:00', '2026-01-15 00:00:00', '2026-01-15 02:00:00', 5),
(7, (SELECT id FROM incident_reports WHERE user_id = 7 AND category = 'cyberbullying' AND severity = 'critical' LIMIT 1), 8, 'counseling', 'high', 'Psychological support for online harassment victim', 'completed', '2026-01-04 12:00:00', '2026-01-05 10:00:00', '2026-01-05 11:00:00', 5),
(5, (SELECT id FROM incident_reports WHERE user_id = 5 AND category = 'stalking' LIMIT 1), 4, 'counseling', 'high', 'Counseling for stalking victim', 'appointment_scheduled', '2026-01-15 10:00:00', '2026-01-18 14:00:00', NULL, NULL);

-- ========================================
-- 15. DISPUTES
-- ========================================

INSERT INTO `disputes` (`user_id`, `report_id`, `reason`, `description`, `status`, `created_at`) VALUES
(1, (SELECT id FROM incident_reports WHERE user_id = 1 AND category = 'discrimination' LIMIT 1), 'misunderstanding', 'The incident was a misunderstanding, not actual discrimination', 'pending', '2026-01-13 10:00:00'),
(2, (SELECT id FROM incident_reports WHERE user_id = 2 AND category = 'discrimination' LIMIT 1), 'misunderstanding', 'Restaurant staff apologized, it was not intentional discrimination', 'under_review', '2026-01-10 15:00:00');

-- ========================================
-- 16. USER AREA RATINGS
-- ========================================

INSERT INTO `user_area_ratings` (`user_id`, `area_id`, `safety_rating`, `comments`, `factors`, `is_verified_resident`, `created_at`) VALUES
(5, 2, 8, 'Gulshan is generally safe with good security', '{"lighting":"excellent","police_presence":"good","traffic":"good","public_transport":"excellent","street_condition":"excellent"}', 1, '2026-01-07 10:00:00'),
(6, 3, 6, 'Mirpur has some safety concerns, especially at night', '{"lighting":"fair","police_presence":"fair","traffic":"poor","public_transport":"good","street_condition":"fair"}', 1, '2026-01-10 11:00:00'),
(7, 1, 7, 'Dhanmondi is relatively safe but needs better lighting', '{"lighting":"fair","police_presence":"good","traffic":"good","public_transport":"excellent","street_condition":"good"}', 1, '2026-01-12 12:00:00');

-- ========================================
-- 17. SAFETY RESOURCES
-- ========================================

INSERT INTO `safety_resources` (`title`, `description`, `category`, `phone`, `email`, `website`, `is_24_7`, `hours_of_operation`, `languages`, `city`, `state`, `is_national`, `is_verified`, `status`) VALUES
('National Emergency Helpline', 'Bangladesh National Emergency Service', 'emergency', '999', NULL, NULL, 1, '24/7', 'bn,en', NULL, NULL, 1, 1, 'active'),
('Women and Children Helpline', 'Dedicated helpline for women and children in distress', 'helpline', '109', NULL, NULL, 1, '24/7', 'bn,en', NULL, NULL, 1, 1, 'active'),
('Acid Survivors Foundation', 'Support for acid attack survivors', 'support_group', '01713328898', 'info@acidsurvivors.org', 'www.acidsurvivors.org', 0, '9:00 AM - 5:00 PM', 'bn,en', 'Dhaka', 'Dhaka', 1, 1, 'active'),
('Bangladesh Mahila Parishad', 'Women rights organization providing support', 'support_group', '01713328899', 'info@mahilaparishad.org', NULL, 0, '9:00 AM - 6:00 PM', 'bn,en', 'Dhaka', 'Dhaka', 1, 1, 'active'),
('Cyber Crime Helpline', 'Report cyber crimes and get assistance', 'helpline', '01320001616', 'info@cid.gov.bd', NULL, 0, '9:00 AM - 5:00 PM', 'bn,en', NULL, NULL, 1, 1, 'active');

-- ========================================
-- 18. SAFE SPACES
-- ========================================

INSERT INTO `safe_spaces` (`name`, `description`, `category`, `address`, `latitude`, `longitude`, `city`, `phone`, `hours_of_operation`, `is_verified`, `status`, `average_rating`, `features`) VALUES
('Gulshan Police Station', 'Police station providing safety and security services', 'police_station', 'Gulshan Avenue, Gulshan-1, Dhaka', 23.79000000, 90.41000000, 'Dhaka', '01713000001', '24/7', 1, 'active', 4.50, 'Emergency response, Women help desk, CCTV monitoring'),
('Mirpur Police Station', 'Police station serving Mirpur area', 'police_station', 'Mirpur 10, Dhaka', 23.81670000, 90.36670000, 'Dhaka', '01713000002', '24/7', 1, 'active', 4.20, 'Emergency response, Community policing'),
('Dhanmondi Community Center', 'Community center providing safe space for residents', 'community_center', 'Road 27, Dhanmondi, Dhaka', 23.74650000, 90.37000000, 'Dhaka', '01713000003', '8:00 AM - 10:00 PM', 1, 'active', 4.80, 'Meeting rooms, Security, Women-friendly space'),
('Dhaka Medical College Hospital', 'Major hospital with emergency services', 'hospital', 'Dhaka Medical College Road, Dhaka', 23.72500000, 90.39500000, 'Dhaka', '01713333333', '24/7', 1, 'active', 4.30, 'Emergency care, One Stop Crisis Centre, Security'),
('Gulshan Lake Park', 'Public park with security and good lighting', 'other', 'Gulshan-2, Dhaka', 23.79470000, 90.41440000, 'Dhaka', NULL, '6:00 AM - 10:00 PM', 1, 'active', 4.60, 'Security guards, CCTV, Good lighting, Walking paths');

-- ========================================
-- 19. SYSTEM STATISTICS
-- ========================================

INSERT INTO `system_statistics` (`date`, `new_users`, `active_users`, `total_users`, `new_reports`, `resolved_reports`, `total_reports`, `new_alerts`, `active_alerts`, `created_at`) VALUES
('2026-01-01', 0, 3, 5, 2, 0, 2, 0, 0, '2026-01-02 00:00:00'),
('2026-01-02', 0, 4, 5, 4, 0, 6, 0, 0, '2026-01-03 00:00:00'),
('2026-01-03', 0, 4, 5, 3, 0, 9, 0, 0, '2026-01-04 00:00:00'),
('2026-01-04', 0, 4, 5, 3, 0, 12, 0, 0, '2026-01-05 00:00:00'),
('2026-01-05', 0, 5, 5, 4, 1, 16, 1, 1, '2026-01-06 00:00:00'),
('2026-01-06', 0, 5, 5, 3, 0, 19, 0, 1, '2026-01-07 00:00:00'),
('2026-01-07', 0, 5, 5, 3, 0, 22, 0, 1, '2026-01-08 00:00:00'),
('2026-01-08', 0, 5, 5, 4, 0, 26, 1, 2, '2026-01-09 00:00:00'),
('2026-01-09', 0, 4, 5, 2, 0, 28, 0, 2, '2026-01-10 00:00:00'),
('2026-01-10', 0, 5, 5, 5, 0, 33, 2, 4, '2026-01-11 00:00:00'),
('2026-01-11', 0, 5, 5, 4, 0, 37, 0, 4, '2026-01-12 00:00:00'),
('2026-01-12', 0, 5, 5, 4, 0, 41, 1, 5, '2026-01-13 00:00:00'),
('2026-01-13', 0, 5, 5, 5, 0, 46, 1, 6, '2026-01-14 00:00:00'),
('2026-01-14', 0, 5, 5, 6, 0, 52, 1, 7, '2026-01-15 00:00:00'),
('2026-01-15', 0, 5, 5, 5, 0, 57, 1, 8, '2026-01-16 00:00:00'),
('2026-01-16', 0, 5, 5, 5, 0, 62, 0, 8, '2026-01-17 00:00:00');

-- ========================================
-- 20. AUDIT LOGS (Sample entries for new activities)
-- ========================================

INSERT INTO `audit_logs` (`user_id`, `action`, `table_name`, `record_id`, `new_values`, `created_at`) VALUES
(5, 'INSERT', 'users', 5, '{"email": "fatima.rahman@gmail.com", "status": "active", "is_active": 1}', '2025-12-20 10:30:00'),
(6, 'INSERT', 'users', 6, '{"email": "karim.ahmed@yahoo.com", "status": "active", "is_active": 1}', '2025-12-22 09:15:00'),
(7, 'INSERT', 'users', 7, '{"email": "nusrat.jahan@outlook.com", "status": "active", "is_active": 1}', '2025-12-25 11:45:00'),
(5, 'INSERT', 'incident_reports', NULL, '{"status": "pending", "severity": "critical"}', '2026-01-02 08:00:00'),
(6, 'INSERT', 'incident_reports', NULL, '{"status": "pending", "severity": "critical"}', '2026-01-03 00:30:00'),
(7, 'INSERT', 'incident_reports', NULL, '{"status": "pending", "severity": "high"}', '2026-01-01 17:00:00');

-- ========================================
-- 21. USER SESSIONS (Recent logins)
-- ========================================

INSERT INTO `user_sessions` (`user_id`, `session_token`, `ip_address`, `user_agent`, `device_type`, `login_time`, `last_activity`, `is_active`) VALUES
(5, SHA2(CONCAT('session_5_1', UNIX_TIMESTAMP()), 256), '192.168.1.105', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'desktop', '2026-01-15 14:20:00', '2026-01-15 16:30:00', 1),
(6, SHA2(CONCAT('session_6_1', UNIX_TIMESTAMP()), 256), '192.168.1.106', 'Mozilla/5.0 (Android 12; Mobile) AppleWebKit/537.36', 'mobile', '2026-01-16 08:45:00', '2026-01-16 10:15:00', 1),
(7, SHA2(CONCAT('session_7_1', UNIX_TIMESTAMP()), 256), '192.168.1.107', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'desktop', '2026-01-14 16:30:00', '2026-01-14 18:45:00', 1);

COMMIT;

-- ========================================
-- END OF POPULATION SCRIPT
-- ========================================
