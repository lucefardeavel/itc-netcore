-- WP9 U0 — READ-ONLY canonical identity survey (grandfathered duplicates; PLAN §7.4 corrected by T1-A: SQL TRIM()
-- misses TAB / LF / CR / NUL / VT). Run ONLY on an owner-approved copy, never on production in WP9. SELECT only.
-- Canonical key = PHP trim() of RadiusPolicyBuilder::key() (space, TAB, LF, CR, NUL, VT) compared under
-- utf8mb4_general_ci (the radcheck.username collation). Requires MariaDB >= 10.0.5 / MySQL >= 8.0 (REGEXP_REPLACE).
-- The regex escapes are doubled so the SQL string parser hands \t \n \r \x0B \x00 to the regex engine (a single \x0B
-- would become 'x0B'); do not run with sql_mode NO_BACKSLASH_ESCAPES.

-- 1. identity column collations as deployed
SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'tbl_customers' AND COLUMN_NAME IN ('username', 'pppoe_username'))
    OR (TABLE_NAME = 'tbl_voucher' AND COLUMN_NAME = 'code') OR (TABLE_NAME = 'tbl_user_recharges' AND COLUMN_NAME = 'username'));

-- 2. canonical keys held by more than one identity (customers cross-field + live voucher-only identities)
SELECT k, COUNT(*) AS holders, GROUP_CONCAT(src ORDER BY src SEPARATOR ',') AS identities FROM (
  SELECT DISTINCT CONVERT(REGEXP_REPLACE(username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci AS k, CONCAT('customer:', id) AS src FROM tbl_customers
  UNION ALL
  SELECT DISTINCT CONVERT(REGEXP_REPLACE(pppoe_username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci, CONCAT('customer:', id) FROM tbl_customers
   WHERE REGEXP_REPLACE(pppoe_username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') <> ''
  UNION ALL
  SELECT CONVERT(REGEXP_REPLACE(code, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci, CONCAT('voucher:', id) FROM tbl_voucher
   WHERE status = 'CLAIMED' OR (status = '1' AND user = '0')
  UNION ALL
  SELECT DISTINCT CONVERT(REGEXP_REPLACE(username, '^[ \\t\\n\\r\\x0B\\x00]+|[ \\t\\n\\r\\x0B\\x00]+$', '') USING utf8mb4) COLLATE utf8mb4_general_ci, 'voucher-only-recharge' FROM tbl_user_recharges
   WHERE customer_id = 0
) x GROUP BY k HAVING COUNT(DISTINCT src) > 1 ORDER BY k;

-- 3. stored names whose canonical form differs from the stored bytes (edge whitespace)
SELECT id, HEX(username) AS username_hex, HEX(pppoe_username) AS pppoe_hex FROM tbl_customers
 WHERE username REGEXP '^[ \\t\\n\\r\\x0B\\x00]|[ \\t\\n\\r\\x0B\\x00]$' OR pppoe_username REGEXP '^[ \\t\\n\\r\\x0B\\x00]|[ \\t\\n\\r\\x0B\\x00]$';
