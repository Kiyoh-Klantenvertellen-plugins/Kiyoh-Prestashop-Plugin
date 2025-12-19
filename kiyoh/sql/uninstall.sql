-- Kiyoh Module Database Uninstallation
-- This file contains the SQL statements to remove the database tables

-- Drop product sync tracking table
DROP TABLE IF EXISTS `PREFIX_kiyoh_product_sync`;

-- Drop invitation tracking table
DROP TABLE IF EXISTS `PREFIX_kiyoh_invitations`;