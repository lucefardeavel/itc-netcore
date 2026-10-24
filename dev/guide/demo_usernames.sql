SET NAMES utf8mb4;
UPDATE tbl_users SET username = CASE username
 WHEN 'sa' THEN 'gm' WHEN 'ad' THEN 'ali.admin' WHEN 'rp' THEN 'reports' WHEN 'A' THEN 'rafidain' WHEN 'B' THEN 'karrada'
 WHEN 'C' THEN 'dijla' WHEN 'A1' THEN 'haider' WHEN 'A2' THEN 'zainab.s' WHEN 'C1' THEN 'mustafa' WHEN 'D' THEN 'furat'
 WHEN 'F' THEN 'hilla' WHEN 'E' THEN 'babil' WHEN 'E1' THEN 'sajjad' ELSE username END;
UPDATE tbl_user_recharges SET namebp = 'هوتسبوت 4 ميغا - 30 يوم' WHERE namebp LIKE 'Hotspot 4M%';
UPDATE tbl_customers SET created_by = created_by;
