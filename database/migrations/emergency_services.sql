-- Emergency Services Finder - Database Migration
-- For Bangladesh Women's Safety Project

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;

-- =====================================================
-- Table: emergency_services
-- Stores police stations, hospitals, women's helpdesks
-- =====================================================

CREATE TABLE IF NOT EXISTS `emergency_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `has_womens_cell` (`has_womens_cell`),
  SPATIAL KEY `location` (`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: helpline_numbers
-- National emergency and support helplines
-- =====================================================

CREATE TABLE IF NOT EXISTS `helpline_numbers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category` (`category`),
  KEY `priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Insert Bangladesh National Helplines
-- =====================================================

INSERT INTO `helpline_numbers` (`name`, `name_bn`, `number`, `category`, `description`, `description_bn`, `organization`, `is_toll_free`, `operating_hours`, `priority`) VALUES
('National Emergency Service', 'জাতীয় জরুরি সেবা', '999', 'emergency', 'Police, Fire, Ambulance - All emergency services', 'পুলিশ, ফায়ার সার্ভিস, অ্যাম্বুলেন্স', 'Bangladesh Government', 1, '24/7', 1),
('Women & Children Helpline', 'মহিলা ও শিশু হেল্পলাইন', '10921', 'womens_rights', 'Ministry of Women and Children Affairs helpline for women safety', 'মহিলা ও শিশু বিষয়ক মন্ত্রণালয়ের হেল্পলাইন', 'Ministry of Women and Children Affairs', 1, '24/7', 2),
('Domestic Violence Helpline', 'পারিবারিক নির্যাতন হটলাইন', '109', 'domestic_violence', 'Report domestic violence and get immediate help', 'পারিবারিক নির্যাতনের রিপোর্ট করুন', 'Government of Bangladesh', 1, '24/7', 3),
('Child Helpline', 'শিশু হেল্পলাইন', '1098', 'child_protection', 'Report child abuse, trafficking, or missing children', 'শিশু নির্যাতন, পাচার বা নিখোঁজ শিশুর রিপোর্ট', 'Ministry of Social Welfare', 1, '24/7', 4),
('RAB Helpline', 'র‍্যাব হেল্পলাইন', '01779-529900', 'emergency', 'Rapid Action Battalion emergency contact', 'র‍্যাপিড অ্যাকশন ব্যাটালিয়ন জরুরি যোগাযোগ', 'RAB', 0, '24/7', 5),
('BNWLA Legal Aid', 'বিএনডব্লিউএলএ আইনি সহায়তা', '01730017055', 'legal_aid', 'Bangladesh National Women Lawyers Association - Free legal aid for women', 'নারীদের জন্য বিনামূল্যে আইনি সহায়তা', 'BNWLA', 0, '9AM-5PM', 6),
('Ain O Salish Kendra', 'আইন ও সালিশ কেন্দ্র', '01711876643', 'legal_aid', 'Human rights and legal aid organization', 'মানবাধিকার ও আইনি সহায়তা সংস্থা', 'ASK', 0, '9AM-5PM', 7),
('BLAST Legal Aid', 'ব্লাস্ট আইনি সহায়তা', '01711579898', 'legal_aid', 'Bangladesh Legal Aid and Services Trust', 'বাংলাদেশ লিগ্যাল এইড অ্যান্ড সার্ভিসেস ট্রাস্ট', 'BLAST', 0, '9AM-5PM', 8),
('Kaan Pete Roi', 'কান পেতে রই', '01779-554391', 'mental_health', 'Emotional support and mental health helpline', 'মানসিক সহায়তা হেল্পলাইন', 'Kaan Pete Roi', 0, '6PM-10PM', 9),
('Fire Service', 'ফায়ার সার্ভিস', '199', 'emergency', 'Fire emergency and rescue services', 'অগ্নিকাণ্ড ও উদ্ধার সেবা', 'Fire Service & Civil Defence', 1, '24/7', 10);

-- =====================================================
-- Insert Dhaka Police Stations
-- =====================================================

INSERT INTO `emergency_services` (`name`, `name_bn`, `type`, `address`, `phone`, `emergency_phone`, `latitude`, `longitude`, `location`, `operating_hours`, `has_womens_cell`, `verified`) VALUES
-- Dhaka Metropolitan Police Stations
('Dhanmondi Police Station', 'ধানমন্ডি থানা', 'police_station', 'Road 27, Dhanmondi, Dhaka', '02-9116453', '999', 23.7465, 90.3762, ST_GeomFromText('POINT(90.3762 23.7465)', 4326), '24/7', 1, 1),
('Gulshan Police Station', 'গুলশান থানা', 'police_station', 'Gulshan Avenue, Gulshan, Dhaka', '02-9858008', '999', 23.7925, 90.4078, ST_GeomFromText('POINT(90.4078 23.7925)', 4326), '24/7', 1, 1),
('Banani Police Station', 'বনানী থানা', 'police_station', 'Banani, Dhaka', '02-9870011', '999', 23.7937, 90.4066, ST_GeomFromText('POINT(90.4066 23.7937)', 4326), '24/7', 1, 1),
('Uttara West Police Station', 'উত্তরা পশ্চিম থানা', 'police_station', 'Sector 6, Uttara, Dhaka', '02-8931122', '999', 23.8759, 90.3795, ST_GeomFromText('POINT(90.3795 23.8759)', 4326), '24/7', 1, 1),
('Uttara East Police Station', 'উত্তরা পূর্ব থানা', 'police_station', 'Sector 10, Uttara, Dhaka', '02-8954411', '999', 23.8689, 90.4012, ST_GeomFromText('POINT(90.4012 23.8689)', 4326), '24/7', 1, 1),
('Mirpur Police Station', 'মিরপুর থানা', 'police_station', 'Mirpur-10, Dhaka', '02-9002233', '999', 23.8069, 90.3687, ST_GeomFromText('POINT(90.3687 23.8069)', 4326), '24/7', 1, 1),
('Mohammadpur Police Station', 'মোহাম্মদপুর থানা', 'police_station', 'Mohammadpur, Dhaka', '02-9116789', '999', 23.7662, 90.3589, ST_GeomFromText('POINT(90.3589 23.7662)', 4326), '24/7', 1, 1),
('Motijheel Police Station', 'মতিঝিল থানা', 'police_station', 'Motijheel, Dhaka', '02-9559812', '999', 23.7332, 90.4176, ST_GeomFromText('POINT(90.4176 23.7332)', 4326), '24/7', 1, 1),
('Paltan Police Station', 'পল্টন থানা', 'police_station', 'Paltan, Dhaka', '02-9559123', '999', 23.7355, 90.4112, ST_GeomFromText('POINT(90.4112 23.7355)', 4326), '24/7', 1, 1),
('Ramna Police Station', 'রমনা থানা', 'police_station', 'Ramna, Dhaka', '02-8317089', '999', 23.7421, 90.4003, ST_GeomFromText('POINT(90.4003 23.7421)', 4326), '24/7', 1, 1),
('Tejgaon Police Station', 'তেজগাঁও থানা', 'police_station', 'Tejgaon Industrial Area, Dhaka', '02-8871234', '999', 23.7587, 90.3932, ST_GeomFromText('POINT(90.3932 23.7587)', 4326), '24/7', 1, 1),
('Badda Police Station', 'বাড্ডা থানা', 'police_station', 'Badda, Dhaka', '02-9887123', '999', 23.7809, 90.4267, ST_GeomFromText('POINT(90.4267 23.7809)', 4326), '24/7', 1, 1),
('Khilgaon Police Station', 'খিলগাঁও থানা', 'police_station', 'Khilgaon, Dhaka', '02-7291234', '999', 23.7510, 90.4332, ST_GeomFromText('POINT(90.4332 23.7510)', 4326), '24/7', 1, 1);

-- =====================================================
-- Insert Dhaka Hospitals
-- =====================================================

INSERT INTO `emergency_services` (`name`, `name_bn`, `type`, `address`, `phone`, `emergency_phone`, `latitude`, `longitude`, `location`, `operating_hours`, `has_womens_cell`, `has_emergency_unit`, `verified`) VALUES
('Dhaka Medical College Hospital', 'ঢাকা মেডিকেল কলেজ হাসপাতাল', 'hospital', 'Secretariat Road, Dhaka', '02-55165001', '199', 23.7257, 90.3976, ST_GeomFromText('POINT(90.3976 23.7257)', 4326), '24/7', 1, 1, 1),
('Square Hospital', 'স্কয়ার হাসপাতাল', 'hospital', '18/F Bir Uttam Qazi Nuruzzaman Sarak, Dhaka', '02-8159457', '10616', 23.7522, 90.3878, ST_GeomFromText('POINT(90.3878 23.7522)', 4326), '24/7', 1, 1, 1),
('United Hospital', 'ইউনাইটেড হাসপাতাল', 'hospital', 'Plot 15, Road 71, Gulshan, Dhaka', '02-8836444', '10666', 23.7956, 90.4145, ST_GeomFromText('POINT(90.4145 23.7956)', 4326), '24/7', 1, 1, 1),
('Labaid Hospital', 'ল্যাবএইড হাসপাতাল', 'hospital', 'House 1, Road 4, Dhanmondi, Dhaka', '02-9116551', '10606', 23.7412, 90.3756, ST_GeomFromText('POINT(90.3756 23.7412)', 4326), '24/7', 1, 1, 1),
('Apollo Hospital', 'অ্যাপোলো হাসপাতাল', 'hospital', 'Plot 81, Block E, Bashundhara, Dhaka', '02-8401661', '10678', 23.8195, 90.4312, ST_GeomFromText('POINT(90.4312 23.8195)', 4326), '24/7', 1, 1, 1),
('Evercare Hospital', 'এভারকেয়ার হাসপাতাল', 'hospital', 'Plot 81, Block E, Bashundhara, Dhaka', '10678', '10678', 23.8167, 90.4289, ST_GeomFromText('POINT(90.4289 23.8167)', 4326), '24/7', 1, 1, 1),
('Ibn Sina Hospital', 'ইবনে সিনা হাসপাতাল', 'hospital', 'House 48, Road 9/A, Dhanmondi, Dhaka', '02-9116910', '10656', 23.7489, 90.3712, ST_GeomFromText('POINT(90.3712 23.7489)', 4326), '24/7', 1, 1, 1),
('Popular Medical College Hospital', 'পপুলার মেডিকেল কলেজ হাসপাতাল', 'hospital', 'House 16, Road 2, Dhanmondi, Dhaka', '02-8610610', '10636', 23.7398, 90.3801, ST_GeomFromText('POINT(90.3801 23.7398)', 4326), '24/7', 1, 1, 1),
('Sir Salimullah Medical College Hospital', 'স্যার সলিমুল্লাহ মেডিকেল কলেজ হাসপাতাল', 'hospital', 'Mitford Road, Dhaka', '02-7319002', '199', 23.7089, 90.4012, ST_GeomFromText('POINT(90.4012 23.7089)', 4326), '24/7', 1, 1, 1),
('Bangabandhu Sheikh Mujib Medical University', 'বঙ্গবন্ধু শেখ মুজিব মেডিকেল বিশ্ববিদ্যালয়', 'hospital', 'Shahbag, Dhaka', '02-9661051', '199', 23.7392, 90.3954, ST_GeomFromText('POINT(90.3954 23.7392)', 4326), '24/7', 1, 1, 1);

-- =====================================================
-- Insert Women's Helpdesks / NGOs
-- =====================================================

INSERT INTO `emergency_services` (`name`, `name_bn`, `type`, `address`, `phone`, `latitude`, `longitude`, `location`, `operating_hours`, `has_womens_cell`, `verified`) VALUES
('One Stop Crisis Centre - DMC', 'ওয়ান স্টপ ক্রাইসিস সেন্টার', 'womens_helpdesk', 'Dhaka Medical College Hospital', '02-55165088', 23.7257, 90.3976, ST_GeomFromText('POINT(90.3976 23.7257)', 4326), '24/7', 1, 1),
('BRAC Women & Girls Centre', 'ব্র্যাক নারী ও কিশোরী কেন্দ্র', 'ngo', 'BRAC Centre, Mohakhali, Dhaka', '02-9881265', 23.7787, 90.4012, ST_GeomFromText('POINT(90.4012 23.7787)', 4326), '9AM-5PM', 1, 1),
('Nari-O-Shishu Nirjaton Protirodh Cell', 'নারী ও শিশু নির্যাতন প্রতিরোধ সেল', 'womens_helpdesk', 'Police Headquarters, Dhaka', '02-8313633', 23.7385, 90.4100, ST_GeomFromText('POINT(90.4100 23.7385)', 4326), '24/7', 1, 1),
('Acid Survivors Foundation', 'এসিড সারভাইভারস ফাউন্ডেশন', 'ngo', 'House 12, Road 22, Gulshan 1, Dhaka', '02-8859943', 23.7845, 90.4156, ST_GeomFromText('POINT(90.4156 23.7845)', 4326), '9AM-5PM', 1, 1),
('Manusher Jonno Foundation', 'মানুষের জন্য ফাউন্ডেশন', 'ngo', 'House 4, Road 50, Gulshan 2, Dhaka', '02-9886472', 23.7912, 90.4089, ST_GeomFromText('POINT(90.4089 23.7912)', 4326), '9AM-5PM', 1, 1),
('Bangladesh Mahila Parishad', 'বাংলাদেশ মহিলা পরিষদ', 'ngo', '28/5 Topkhana Road, Dhaka', '02-9558673', 23.7342, 90.4089, ST_GeomFromText('POINT(90.4089 23.7342)', 4326), '9AM-5PM', 1, 1);

-- =====================================================
-- Insert Fire Stations
-- =====================================================

INSERT INTO `emergency_services` (`name`, `name_bn`, `type`, `address`, `phone`, `emergency_phone`, `latitude`, `longitude`, `location`, `operating_hours`, `verified`) VALUES
('Dhaka Fire Station - Headquarters', 'ঢাকা ফায়ার স্টেশন - সদর দপ্তর', 'fire_station', '37 Naya Paltan, Dhaka', '02-9330088', '199', 23.7389, 90.4098, ST_GeomFromText('POINT(90.4098 23.7389)', 4326), '24/7', 1),
('Mirpur Fire Station', 'মিরপুর ফায়ার স্টেশন', 'fire_station', 'Mirpur-10, Dhaka', '02-8031199', '199', 23.8076, 90.3656, ST_GeomFromText('POINT(90.3656 23.8076)', 4326), '24/7', 1),
('Uttara Fire Station', 'উত্তরা ফায়ার স্টেশন', 'fire_station', 'Sector 7, Uttara, Dhaka', '02-8953199', '199', 23.8701, 90.3912, ST_GeomFromText('POINT(90.3912 23.8701)', 4326), '24/7', 1),
('Gulshan Fire Station', 'গুলশান ফায়ার স্টেশন', 'fire_station', 'Gulshan, Dhaka', '02-9856199', '199', 23.7889, 90.4123, ST_GeomFromText('POINT(90.4123 23.7889)', 4326), '24/7', 1);

COMMIT;
