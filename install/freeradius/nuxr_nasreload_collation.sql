-- PHPNuxBill lab B-L09: NASRELOAD COLLATION UPGRADE for an EXISTING RADIUS database (the database that holds radacct,
-- created from install/radius.sql).
--
-- install/radius.sql before the B-L09 fix created `nasreload` without a collation, so the table took the database
-- default. On a database whose default is not utf8mb4_general_ci (a MySQL 8 default database is utf8mb4_0900_ai_ci)
-- the stock FreeRADIUS simultaneous-use queries (queries.conf simul_count_query / simul_verify_query join radacct and
-- nasreload on nasipaddress) fail with ERROR 1267 "Illegal mix of collations" on EVERY login. New installs are fixed in
-- install/radius.sql; this script fixes an installed database.
--
-- Idempotent: it converts nasreload ONLY when its nasipaddress column or its table default is not utf8mb4 /
-- utf8mb4_general_ci; a second run (or a run on a correct database) changes nothing. It never drops or deletes
-- anything: CONVERT TO keeps every row (nasreload holds only NAS addresses and reload times, ASCII). Run it in the
-- RADIUS database, e.g.  mysql RADIUS_DB < install/freeradius/nuxr_nasreload_collation.sql
-- Verify: the SELECT at the end prints nasreload_collation=READY.
SET @nuxr_bl09 = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nasreload'
    AND COLUMN_NAME = 'nasipaddress' AND (CHARACTER_SET_NAME <> 'utf8mb4' OR COLLATION_NAME <> 'utf8mb4_general_ci')) > 0
  OR (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nasreload'
    AND TABLE_COLLATION <> 'utf8mb4_general_ci') > 0,
  'ALTER TABLE `nasreload` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci', 'DO 0');
PREPARE nuxr_bl09_stmt FROM @nuxr_bl09;
EXECUTE nuxr_bl09_stmt;
DEALLOCATE PREPARE nuxr_bl09_stmt;
SELECT CONCAT('nasreload_collation=', IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nasreload'
    AND COLUMN_NAME = 'nasipaddress' AND CHARACTER_SET_NAME = 'utf8mb4' AND COLLATION_NAME = 'utf8mb4_general_ci') = 1
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nasreload'
    AND TABLE_COLLATION = 'utf8mb4_general_ci') = 1, 'READY', 'NOT_READY')) AS nuxr_check;
