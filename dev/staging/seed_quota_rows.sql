-- TA-09 / TA-05 synthetic quota subscribers for an APPROVED STAGING RADIUS database only (never production). Synthetic identities tgt_NNNNN.
-- 1. seed (adjust @subs); 2. run dev/staging/acct_gen.php and time `php system/reseller/quota.php report`; 3. clean up with the DELETEs at the end.
-- Requires MariaDB >= 10.2 or MySQL >= 8.0 (recursive CTE). Writes ONLY radcheck rows of tgt_% (and, through FreeRADIUS, radacct rows of tgt_%).
SET @subs = 2000;
INSERT INTO radcheck (username, attribute, op, value)
WITH RECURSIVE n (i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i + 1 < @subs)
SELECT CONCAT('tgt_', LPAD(i, 5, '0')), 'Max-Data', ':=', '50000000000' FROM n;
INSERT INTO radcheck (username, attribute, op, value)
WITH RECURSIVE n (i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i + 1 < @subs)
SELECT CONCAT('tgt_', LPAD(i, 5, '0')), 'Nuxr-Period-Start', ':=', '1' FROM n;
-- 3. cleanup (staging only; the accounting rows of the synthetic sessions are removed too: they are the test's own data, not customer history)
-- DELETE FROM radcheck WHERE username LIKE 'tgt\_%';
-- DELETE FROM radacct WHERE username LIKE 'tgt\_%';
