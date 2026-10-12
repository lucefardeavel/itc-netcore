-- PROPOSAL ONLY (WP8 H-2 R-H2-B feasibility, 2026-10-11) - NOT part of install/, NOT applied to any production
-- database. Applied ONLY to the disposable WP8 lab database (nuxr_radius in the lab MariaDB) and to a throwaway MySQL 8.0
-- container for the Gate C engine check. A future migration would be a separate additive, idempotent file next to
-- install/freeradius/nuxr_admission.sql, under a separate owner decision.
--
-- nuxr_hs_admission: Hotspot admission SLOTS, one row per (CANONICAL subscriber, slot number). The canonical subscriber is
-- the exact radcheck.username bytes (the H1-A identity). Slots 1..shared_users are created lazily (INSERT IGNORE, state
-- ENDED). A login is admitted only by claiming one ENDED slot whose number is <= shared_users with ONE conditional
-- UPDATE ... ORDER BY slot LIMIT 1 (the row lock of InnoDB serializes concurrent claims, from any NAS); the number of
-- non-ENDED slots can therefore never exceed shared_users. One ISP = one RADIUS database (deployment model), so slots of
-- different ISPs can never mix.
--   state    PENDING  claimed, Access-Accept with the token sent (or its delivery uncertain)
--            ACTIVE   Accounting Start / Interim-Update with the token arrived from the claiming NAS
--            ENDED    Accounting-Stop with the token; a late claim released by its creator; a release after a NAS-side
--                     proof that the session is gone (verifier); an operator recovery
--   token    per-claim correlation value (128 random bits, lowercase hex), sent as Class = "nuxh1" + 16 bytes
--   nas, acct_session_id, calling_station_id, request_authenticator   the claiming attempt (retransmission = re-use)
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
  KEY ix_nuxr_hs_admission_nas (nas, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Append-only audit of every release that is not an Accounting-Stop (verifier proof or operator), same shape as
-- nuxr_admission_audit.
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
  KEY ix_nuxr_hs_admission_audit_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
