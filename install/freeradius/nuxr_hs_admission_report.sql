-- PHPNuxBill WP8 H-2B: READ-ONLY REPORT of the Hotspot admission slots (MariaDB 10.11 / MySQL 8.0). It changes nothing.
--     mysql --defaults-extra-file=<protected client file> <radius_db> < nuxr_hs_admission_report.sql
-- For monitoring (run it every minute; alert on any ALERT line). It never releases anything: there is NO executable
-- manual release of a Hotspot slot (owner decision 2026-10-11, Strict Mode). A row here is a candidate for the operator
-- investigation of README.md "Hotspot admission slots" -> "Recovery runbook", never a proof.
--
-- 1. ALERT pending_over_1_minute: a PENDING slot older than 60 s without any accounting - the Access-Accept was lost or
--    late (the NAS gives up after ~9 s with /radius timeout=3s), or the NAS failed before any accounting. It blocks one
--    slot of the subscriber (with shared_users = 1: every login of the subscriber, on every NAS) until resolved.
SELECT 'ALERT pending_over_1_minute' AS kind, username, slot, nas, acct_session_id, calling_station_id, created_at,
       TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP(6)) AS age_s, attach_count
FROM nuxr_hs_admission
WHERE state = 'PENDING' AND last_acct_at IS NULL AND created_at < UTC_TIMESTAMP(6) - INTERVAL 60 SECOND
ORDER BY created_at;
-- 2. ALERT orphan_accounting (last 24 h): an Accounting Start / Interim-Update carried a "nuxh1" token that holds no live
--    slot - a Hotspot session the slots do not count. Investigate the NAS session immediately (the limit may be exceeded).
SELECT 'ALERT orphan_accounting' AS kind, at, outcome AS acct_status, username, token, evidence
FROM nuxr_hs_admission_audit
WHERE action = 'orphan_accounting' AND at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY
ORDER BY at;
-- 3. INFO active_without_recent_accounting: ACTIVE slots whose last accounting is older than 3 interim intervals (assumed
--    1 h here; adjust to the NAS radius-interim-update). The verifier releases them automatically on the next refused
--    login when their NAS proves the session gone; listed for visibility only.
SELECT 'INFO active_without_recent_accounting' AS kind, username, slot, nas, calling_station_id, last_acct_at,
       TIMESTAMPDIFF(SECOND, last_acct_at, UTC_TIMESTAMP(6)) AS since_acct_s
FROM nuxr_hs_admission
WHERE state = 'ACTIVE' AND last_acct_at < UTC_TIMESTAMP(6) - INTERVAL 3 HOUR
ORDER BY last_acct_at;
-- 4. INFO verified releases (last 24 h): automatic ACTIVE releases by the verifier, with their SNMP evidence.
SELECT 'INFO verified_release' AS kind, at, username, slot, outcome, evidence
FROM nuxr_hs_admission_audit
WHERE action = 'verified_release' AND at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY
ORDER BY at;
