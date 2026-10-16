-- PHPNuxBill WP8 A2-R: PPPoE ADMISSION RESERVATION (atomic, fail-closed, one admitted PPPoE attempt per subscriber).
--
-- ADDITIVE SCHEMA UPGRADE for the FreeRADIUS / RADIUS database of ONE ISP instance (the database that holds radcheck and
-- radacct, created from install/radius.sql). install/radius.sql is NOT changed. Idempotent: safe to run again; it never
-- drops, rewrites or deletes anything; the only ALTER is the guarded, once-only addition of the two WP9 CHECK constraints
-- to a table created before WP9 (see the end of this file). Apply it BEFORE enabling the A2-R virtual server
-- (sites-available/phpnuxbill); without it every PPPoE login is refused (fail closed). Verify with
-- nuxr_admission_check.sql. See README.md "PPPoE admission reservation".
--
-- nuxr_admission: one row per CANONICAL subscriber name = the exact radcheck.username bytes that the RADIUS lookup matched
-- (radcheck.username is utf8mb4_general_ci, so different spellings - case, accents - can authenticate as the same
-- subscriber; they all map to this one row). Written ONLY by FreeRADIUS (post-auth and accounting of the PPPoE path)
-- and by the operator recovery procedure (nuxr_admission_release.sql). PHPNuxBill never writes it. Rows are never deleted
-- by the policy; ENDED rows are re-used by the next admission of the same subscriber.
--   state            PENDING  claimed; the Access-Accept carrying the token was sent (or its delivery is uncertain)
--                    ACTIVE   an Accounting-Start / Interim-Update carrying the token arrived from the admitting NAS
--                    ENDED    an Accounting-Stop with the token, the A1 SNMP proof that an ACTIVE session is gone (take-over),
--                             a late claim released by its creator, or an operator recovery
--                    (UNKNOWN is not stored: a PENDING row older than the establishment horizon; never released by time)
--   token            per-attempt correlation value (128 random bits, lowercase hex), sent as Class = "nuxr1" + 16 bytes
--   nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator
--                    the attempt identity of the claiming Access-Request: an exact retransmission of that request (same
--                    Request Authenticator) re-uses the claim; any other request is a different attempt
--   session_user     the stock SQL-User-Name of the claiming request (= radacct.username of its session)
--   attach_count     requests of the SAME attempt that re-used the claim (their Access-Accept carries the same token)
--   *_at             UTC from the database clock (UTC_TIMESTAMP(6)), DATETIME(6): no session time-zone conversion
CREATE TABLE IF NOT EXISTS nuxr_admission (
  username varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  state enum('PENDING','ACTIVE','ENDED') NOT NULL,
  token char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nas varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  acct_session_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  calling_station_id varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  nas_port_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  request_authenticator char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  session_user varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  attach_count int unsigned NOT NULL DEFAULT 0,
  created_at datetime(6) NOT NULL,
  updated_at datetime(6) NOT NULL,
  confirmed_at datetime(6) NULL,
  last_acct_at datetime(6) NULL,
  ended_at datetime(6) NULL,
  end_reason varchar(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  prev_token char(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  prev_state varchar(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (username),
  UNIQUE KEY uq_nuxr_admission_token (token),
  -- WP9 A2-R hardening L1 (same rule as the WP8 H-2B slots): rlm_sql 3.2.8 escapes every %{...} inside a %{sql:...}
  -- statement on its own pool connection; under pool exhaustion such an expansion can become an EMPTY string while the
  -- statement still runs. These constraints make the database refuse a claim written with an emptied key, token or
  -- attempt identity (-> Access-Reject) instead of storing it, and no statement can ever match a token ''.
  CONSTRAINT ck_nuxr_admission_token CHECK (token REGEXP '^[0-9a-f]{32}$' AND username <> '' AND session_user <> ''),
  CONSTRAINT ck_nuxr_admission_claim CHECK (nas REGEXP '^[0-9]{1,3}([.][0-9]{1,3}){3}$' AND acct_session_id <> ''
    AND calling_station_id <> '' AND nas_port_id <> '' AND request_authenticator REGEXP '^[0-9a-f]{32}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Append-only audit of operator recovery decisions (nuxr_admission_release.sql): who released which claim, why, and on
-- which NAS-side evidence. Never updated or deleted by PHPNuxBill or FreeRADIUS.
CREATE TABLE IF NOT EXISTS nuxr_admission_audit (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  at datetime(6) NOT NULL,
  action varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  outcome varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  username varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  token char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operator varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  reason varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  evidence varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (id),
  KEY ix_nuxr_admission_audit_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- WP9 A2-R hardening L1 for a nuxr_admission table created BEFORE WP9: add each CHECK constraint once (guarded by
-- information_schema; a second run changes nothing). MariaDB and MySQL validate the existing rows: a row that violates
-- a constraint (an empty key / token / attempt identity - only possible from the condition these constraints prevent)
-- makes the ALTER fail and this script stop. Find such rows first with the read-only pre-scan in README.md
-- "PPPoE admission reservation - WP9 CHECK upgrade"; they are resolved by the operator, never deleted automatically.
SET @nuxr_a2r_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission'
    AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_nuxr_admission_token') = 0,
  'ALTER TABLE nuxr_admission ADD CONSTRAINT ck_nuxr_admission_token CHECK (token REGEXP ''^[0-9a-f]{32}$'' AND username <> '''' AND session_user <> '''')',
  'DO 0');
PREPARE nuxr_a2r_stmt FROM @nuxr_a2r_sql;
EXECUTE nuxr_a2r_stmt;
DEALLOCATE PREPARE nuxr_a2r_stmt;
SET @nuxr_a2r_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission'
    AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_nuxr_admission_claim') = 0,
  'ALTER TABLE nuxr_admission ADD CONSTRAINT ck_nuxr_admission_claim CHECK (nas REGEXP ''^[0-9]{1,3}([.][0-9]{1,3}){3}$'' AND acct_session_id <> '''' AND calling_station_id <> '''' AND nas_port_id <> '''' AND request_authenticator REGEXP ''^[0-9a-f]{32}$'')',
  'DO 0');
PREPARE nuxr_a2r_stmt FROM @nuxr_a2r_sql;
EXECUTE nuxr_a2r_stmt;
DEALLOCATE PREPARE nuxr_a2r_stmt;
