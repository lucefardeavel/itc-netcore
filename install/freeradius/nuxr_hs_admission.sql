-- PHPNuxBill WP8 H-2B: HOTSPOT ADMISSION SLOTS (atomic, fail-closed, GLOBAL shared_users across every NAS of one ISP).
--
-- ADDITIVE SCHEMA UPGRADE for the FreeRADIUS / RADIUS database of ONE ISP instance (the database that holds radcheck and
-- radacct, created from install/radius.sql). install/radius.sql is NOT changed. Idempotent: safe to run again; it never
-- drops, alters or rewrites anything. Apply it BEFORE enabling the H-2B virtual server (sites-available/phpnuxbill);
-- without it every Hotspot login is refused (fail closed). Verify with nuxr_hs_admission_check.sql. See README.md
-- "Hotspot admission slots". One ISP = one RADIUS database, so slots of different ISPs never mix.
--
-- nuxr_hs_admission: one row per (CANONICAL subscriber, slot number). The canonical subscriber is the exact
-- radcheck.username bytes (the H1-A identity: a Hotspot login is admitted only under those exact bytes). Slots
-- 1..Simultaneous-Use (= max(1, shared_users)) are created on demand by FreeRADIUS (INSERT IGNORE, state ENDED). A login
-- is admitted only by claiming ONE ENDED slot whose number is <= the limit with ONE conditional UPDATE ... ORDER BY slot
-- LIMIT 1 (the InnoDB row lock serialises concurrent claims from any NAS), after a non-locking pre-check that fewer than
-- `limit` slots are in use AND that no slot above the limit is live (a limit decrease). Requests that read the same limit
-- therefore never hold more than `limit` non-ENDED slots; only a request still in flight with the OLD, higher limit at the
-- moment of a decrease can take one slot above the new limit (README.md "Limit changes").
-- Written ONLY by FreeRADIUS (post-auth and accounting of the Hotspot path). PHPNuxBill never writes it. Rows are never
-- deleted by the policy; ENDED slots are re-used.
--   state    PENDING  claimed; the Access-Accept carrying the token was sent (or its delivery is uncertain)
--            ACTIVE   an Accounting-Start / Interim-Update carrying the token arrived from the claiming NAS
--            ENDED    an Accounting-Stop with the token; a late claim released by its creator BEFORE any reply; an ACTIVE
--                     slot whose session the Hotspot slot verifier proved gone on its NAS (SNMP)
--            STRICT MODE: a PENDING slot is NEVER released by time, by SNMP or by another request (WP8 H2-B PENDING
--            safety gate: an Access-Accept stalled inside FreeRADIUS can still be taken by the NAS later). There is NO
--            executable manual release (owner decision 2026-10-11); see README.md "Hotspot admission slots".
--   token    per-claim correlation value (128 random bits, lowercase hex), sent as Class = "nuxh1" + 16 bytes
--            (PPPoE A2-R uses "nuxr1": the two never match each other)
--   nas, acct_session_id, calling_station_id, request_authenticator
--            the claiming attempt: an exact retransmission (same Request Authenticator) re-uses the claim
--   session_user  the stock SQL-User-Name of the claiming request (= radacct.username of its session)
--   attach_count  requests of the SAME attempt that re-used the claim (their Access-Accept carries the same token)
--   *_at     UTC from the database clock (UTC_TIMESTAMP(6)), DATETIME(6)
CREATE TABLE IF NOT EXISTS nuxr_hs_admission (
  username varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  slot smallint unsigned NOT NULL,
  state enum('PENDING','ACTIVE','ENDED') NOT NULL,
  token char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nas varchar(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  acct_session_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  calling_station_id varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
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
  PRIMARY KEY (username, slot),
  UNIQUE KEY uq_nuxr_hs_admission_token (token),
  KEY ix_nuxr_hs_admission_nas (nas, state),
  -- WP8 H-2B (lab finding 2026-10-11): FreeRADIUS 3.2.8 rlm_sql escapes every %{...} inside a %{sql:...} statement
  -- with its own pool connection; when the pool is exhausted that expansion becomes an EMPTY string and the statement
  -- still runs. These constraints make the database refuse such a statement (-> Access-Reject) instead of storing an
  -- empty token or attempt identity: a token is always 32 lower-case hex digits (so no statement can ever match a
  -- token '') and a claimed (non-ENDED) slot always carries its complete attempt identity.
  CONSTRAINT ck_nuxr_hs_admission_token CHECK (token REGEXP '^[0-9a-f]{32}$' AND username <> ''),
  CONSTRAINT ck_nuxr_hs_admission_claim CHECK (state = 'ENDED' OR (nas REGEXP '^[0-9]{1,3}([.][0-9]{1,3}){3}$' AND acct_session_id <> ''
    AND calling_station_id <> '' AND request_authenticator REGEXP '^[0-9a-f]{32}$' AND session_user <> ''))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Append-only audit, written by FreeRADIUS: every automatic release of an ACTIVE slot by the Hotspot slot verifier
-- (action verified_release, with the verifier's per-NAS evidence) and every Accounting Start / Interim-Update whose
-- "nuxh1" token holds no live slot (action orphan_accounting: a session the slots do not count - an alarm). Never updated
-- or deleted by PHPNuxBill or FreeRADIUS.
CREATE TABLE IF NOT EXISTS nuxr_hs_admission_audit (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  at datetime(6) NOT NULL,
  action varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  outcome varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  username varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  slot smallint unsigned NOT NULL,
  token char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  evidence varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (id),
  KEY ix_nuxr_hs_admission_audit_user (username),
  KEY ix_nuxr_hs_admission_audit_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
