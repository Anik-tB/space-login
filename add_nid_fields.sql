-- Add NID verification fields to users table
ALTER TABLE users
ADD COLUMN nid_number VARCHAR(20) UNIQUE NULL,
ADD COLUMN nid_front_photo VARCHAR(255) NULL,
ADD COLUMN nid_back_photo VARCHAR(255) NULL,
ADD COLUMN face_verified TINYINT(1) DEFAULT 0,
ADD COLUMN nid_verified TINYINT(1) DEFAULT 0,
ADD COLUMN verification_status ENUM('pending', 'under_review', 'verified', 'rejected') DEFAULT 'pending',
ADD INDEX (nid_number),
ADD INDEX (verification_status);


