-- WP9 U0 — READ-ONLY canonical identity survey (grandfathered duplicates; PLAN §7.4 corrected by T1-A: SQL TRIM()
-- misses TAB / LF / CR / NUL / VT). Run ONLY on an owner-approved copy, never on production in WP9. SELECT only.
-- Canonical key = PHP trim() of RadiusPolicyBuilder::key() (space, TAB, LF, CR, NUL, VT) compared under
-- utf8mb4_general_ci (the radcheck.username collation). Requires MariaDB >= 10.0.5 / MySQL >= 8.0 (REGEXP_REPLACE),
-- the R0005 ownership column (tbl_customers.manager_id) and the installer layout (RADIUS tables in the same database;
-- with a separate RADIUS database run statement 5 there against an exported key list).
-- The regex escapes are doubled so the SQL string parser hands \t \n \r \x0B \x00 to the regex engine (a single \x0B
-- would become 'x0B'). Statement 0 aborts the reading when the session would parse them differently.

-- 0. session check: must return OK (NO_BACKSLASH_ESCAPES would turn every escape below into literal characters)
SELECT IF(@@SESSION.sql_mode LIKE '%NO_BACKSLASH_ESCAPES%', 'ABORT_NO_BACKSLASH_ESCAPES', 'OK') AS survey_session,
       IF(LENGTH('\\t') = 2, 'OK', 'ABORT_ESCAPES') AS escape_check;

-- 1. identity column collations as deployed
SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'tbl_customers' AND COLUMN_NAME IN ('username', 'pppoe_username'))
    OR (TABLE_NAME = 'tbl_voucher' AND COLUMN_NAME = 'code') OR (TABLE_NAME = 'tbl_user_recharges' AND COLUMN_NAME = 'username')
    OR (TABLE_NAME IN ('radcheck', 'radreply', 'radusergroup') AND COLUMN_NAME = 'username'));

-- 2. canonical keys held by more than one identity (customers cross-field, owned / unassigned, + live voucher-only identities)
SELECT k, COUNT(DISTINCT src) AS holders, GROUP_CONCAT(DISTINCT src ORDER BY src SEPARATOR ',') AS identities FROM (
  SELECT CONVERT(REGEXP_REPLACE(username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci AS k,
         CONCAT('customer:', id, IF(manager_id IS NULL, '(unassigned)', '(owned)')) AS src FROM tbl_customers
  UNION ALL
  SELECT CONVERT(REGEXP_REPLACE(pppoe_username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci,
         CONCAT('customer:', id, IF(manager_id IS NULL, '(unassigned)', '(owned)')) FROM tbl_customers
   WHERE REGEXP_REPLACE(pppoe_username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') <> ''
  UNION ALL
  SELECT CONVERT(REGEXP_REPLACE(code, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci,
         CONCAT('voucher:', id, '(', status, ')') FROM tbl_voucher
   WHERE status = 'CLAIMED' OR (status = '1' AND user = '0')
  UNION ALL
  SELECT CONVERT(REGEXP_REPLACE(username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci,
         'voucher-only-recharge' FROM tbl_user_recharges
   WHERE customer_id = 0
) x GROUP BY k HAVING COUNT(DISTINCT src) > 1 ORDER BY k;

-- 3. stored names whose canonical form differs from the stored bytes (edge whitespace / control characters)
SELECT id, HEX(username) AS username_hex, HEX(pppoe_username) AS pppoe_hex, IF(manager_id IS NULL, 'unassigned', 'owned') AS ownership FROM tbl_customers
 WHERE username REGEXP '^[ \\t\\n\\r\\x0B\\x00]|[ \\t\\n\\r\\x0B\\x00]$' OR pppoe_username REGEXP '^[ \\t\\n\\r\\x0B\\x00]|[ \\t\\n\\r\\x0B\\x00]$';

-- 4. names with an interior control character (never a valid RADIUS key: RadiusPolicyBuilder::key() refuses them)
SELECT id, HEX(username) AS username_hex, HEX(pppoe_username) AS pppoe_hex FROM tbl_customers
 WHERE username REGEXP '[\\x00-\\x1F\\x7F]' OR pppoe_username REGEXP '[\\x00-\\x1F\\x7F]';

-- 5. RADIUS policy keys without any live owner (old / suspended / orphaned rows) and keys whose rows are claimed by more
--    than one identity (statement 2 lists the identities)
SELECT HEX(r.username) AS radius_key_hex, COUNT(*) AS policy_rows,
       (SELECT COUNT(*) FROM tbl_customers c WHERE CONVERT(REGEXP_REPLACE(c.username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(r.username USING utf8mb4) COLLATE utf8mb4_general_ci
           OR (c.pppoe_username <> '' AND CONVERT(REGEXP_REPLACE(c.pppoe_username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(r.username USING utf8mb4) COLLATE utf8mb4_general_ci)) AS customer_owners,
       (SELECT COUNT(*) FROM tbl_voucher v WHERE (v.status = 'CLAIMED' OR (v.status = '1' AND v.user = '0'))
           AND CONVERT(REGEXP_REPLACE(v.code, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(r.username USING utf8mb4) COLLATE utf8mb4_general_ci) AS voucher_owners
  FROM radcheck r GROUP BY r.username
 HAVING customer_owners + voucher_owners <> 1 ORDER BY radius_key_hex;
