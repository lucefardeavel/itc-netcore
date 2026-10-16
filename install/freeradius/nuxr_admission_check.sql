-- PHPNuxBill WP8 A2-R: READINESS CHECK of the PPPoE admission reservation schema (read-only; MariaDB 10.11 / MySQL 8.0).
-- Run it in the RADIUS database of the ISP instance AFTER nuxr_admission.sql and BEFORE enabling the A2-R virtual server:
--     mysql --defaults-extra-file=<protected client file> <radius_db> < nuxr_admission_check.sql
-- It prints exactly one line: "nuxr_admission=READY", or "nuxr_admission=NOT_READY:<failed checks>". Anything else
-- (an error, no output) means NOT READY. It changes nothing.
SELECT CASE WHEN f = '' THEN 'nuxr_admission=READY' ELSE CONCAT('nuxr_admission=NOT_READY:', f) END AS nuxr_admission_check
FROM (SELECT CONCAT_WS(',',
  IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission' AND ENGINE = 'InnoDB') = 1, NULL, 'table'),
  IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission_audit' AND ENGINE = 'InnoDB') = 1, NULL, 'audit_table'),
  IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission' AND (
        (COLUMN_NAME = 'username' AND COLUMN_TYPE = 'varchar(64)' AND COLLATION_NAME = 'utf8mb4_bin' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'state' AND COLUMN_TYPE = 'enum(''PENDING'',''ACTIVE'',''ENDED'')' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'token' AND COLUMN_TYPE = 'char(32)' AND COLLATION_NAME = 'ascii_bin' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'nas' AND COLUMN_TYPE = 'varchar(15)' AND COLLATION_NAME = 'utf8mb4_general_ci' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'acct_session_id' AND COLUMN_TYPE = 'varchar(64)' AND COLLATION_NAME = 'utf8mb4_bin' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'calling_station_id' AND COLUMN_TYPE = 'varchar(50)' AND COLLATION_NAME = 'utf8mb4_bin' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'nas_port_id' AND COLUMN_TYPE = 'varchar(64)' AND COLLATION_NAME = 'utf8mb4_bin' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'request_authenticator' AND COLUMN_TYPE = 'char(32)' AND COLLATION_NAME = 'ascii_bin' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'session_user' AND COLUMN_TYPE = 'varchar(64)' AND COLLATION_NAME = 'utf8mb4_bin' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME = 'attach_count' AND COLUMN_TYPE LIKE 'int%unsigned' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME IN ('created_at', 'updated_at') AND COLUMN_TYPE = 'datetime(6)' AND IS_NULLABLE = 'NO')
     OR (COLUMN_NAME IN ('confirmed_at', 'last_acct_at', 'ended_at') AND COLUMN_TYPE = 'datetime(6)')
     OR (COLUMN_NAME IN ('end_reason', 'prev_state') AND COLUMN_TYPE IN ('varchar(32)', 'varchar(8)') AND COLLATION_NAME = 'ascii_bin')
     OR (COLUMN_NAME = 'prev_token' AND COLUMN_TYPE = 'char(32)' AND COLLATION_NAME = 'ascii_bin'))) = 18, NULL, 'columns'),
  IF((SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission' AND INDEX_NAME = 'PRIMARY') = 'username', NULL, 'primary_key'),
  IF((SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY') = 'token', NULL, 'token_unique'),
  -- WP9 A2-R hardening L4: the two CHECK constraints that refuse an emptied SQL expansion (enforced by MariaDB >= 10.2
  -- and MySQL >= 8.0.16; their exact expressions and enforcement - MySQL NOT ENFORCED, MariaDB check_constraint_checks -
  -- are verified by the engine-aware application preflight, php system/reseller/preflight.php)
  IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission' AND CONSTRAINT_TYPE = 'CHECK'
      AND CONSTRAINT_NAME IN ('ck_nuxr_admission_token', 'ck_nuxr_admission_claim')) = 2, NULL, 'check_constraints'),
  -- the canonical-name key relies on the radcheck lookup collation of install/radius.sql
  IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'radcheck' AND COLUMN_NAME = 'username' AND COLLATION_NAME = 'utf8mb4_general_ci') = 1, NULL, 'radcheck_collation'),
  IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'radacct' AND COLUMN_NAME = 'nasipaddress' AND COLLATION_NAME = 'utf8mb4_general_ci') = 1, NULL, 'radacct_collation')
) AS f) AS c;
