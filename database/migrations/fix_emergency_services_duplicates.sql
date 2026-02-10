-- =====================================================
-- Fix Emergency Services Duplicates
-- Remove entries 34-66 which have corrupted Bangla text
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- First, let's see what we're deleting
-- SELECT id, name, name_bn FROM emergency_services WHERE id >= 34;

-- Delete the duplicate entries with corrupted Bangla text
DELETE FROM emergency_services WHERE id >= 34 AND id <= 66;

-- Reset the AUTO_INCREMENT to continue after the valid entries
ALTER TABLE emergency_services AUTO_INCREMENT = 34;

-- =====================================================
-- Fix Helpline Numbers Duplicates
-- Remove entries 11-20 which have corrupted Bangla text
-- =====================================================

DELETE FROM helpline_numbers WHERE id > 10;

-- Reset the AUTO_INCREMENT
ALTER TABLE helpline_numbers AUTO_INCREMENT = 11;

-- Verify the cleanup
SELECT 'emergency_services' as table_name, COUNT(*) as total FROM emergency_services
UNION ALL
SELECT 'helpline_numbers' as table_name, COUNT(*) as total FROM helpline_numbers;

COMMIT;

