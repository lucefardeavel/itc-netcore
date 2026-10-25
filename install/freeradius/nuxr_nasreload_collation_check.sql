-- PHPNuxBill lab B-L09: read-only readiness check of the nasreload collation (see nuxr_nasreload_collation.sql).
-- Prints nasreload_collation=READY when nasreload.nasipaddress and the table default are utf8mb4_general_ci.
SELECT CONCAT('nasreload_collation=', IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nasreload'
    AND COLUMN_NAME = 'nasipaddress' AND CHARACTER_SET_NAME = 'utf8mb4' AND COLLATION_NAME = 'utf8mb4_general_ci') = 1
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nasreload'
    AND TABLE_COLLATION = 'utf8mb4_general_ci') = 1, 'READY', 'NOT_READY')) AS nuxr_check;
