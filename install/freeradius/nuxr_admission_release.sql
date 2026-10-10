-- PHPNuxBill WP8 A2-R: OPERATOR RECOVERY of ONE unresolved PPPoE admission claim (state PENDING; "UNKNOWN" when old).
--
-- Use ONLY after the attempt has been proven INVALIDATED ON THE NAS (README.md "PPPoE admission reservation" ->
-- "Operator recovery"): an Access-Accept that was already sent cannot be cancelled from the RADIUS side, so a PENDING
-- claim may be released only when that attempt can no longer become a live session - for example the NAS was rebooted
-- after the claim was created, or the NAS log shows the attempt failed. "No session in SNMP / the NAS active list" alone
-- is NOT sufficient (the attempt may still complete). Never release ACTIVE claims here (the A1 SNMP verification ends
-- them automatically when the session is proven gone).
--
-- Usage (mysql / mariadb client on the RADIUS database of the ISP instance; credentials from a protected client file,
-- never on the command line):
--     SET @nuxr_username = '<canonical radcheck.username>';  -- as shown by the status query in README.md
--     SET @nuxr_token    = '<32 hex token of the PENDING claim>';
--     SET @nuxr_operator = '<operator name>';
--     SET @nuxr_reason   = '<why the claim must be released (>= 10 characters)>';
--     SET @nuxr_evidence = '<NAS-side proof of invalidation: NAS, time, log line / reboot record (>= 10 characters)>';
--     SOURCE nuxr_admission_release.sql;
-- It prints exactly one line: "nuxr_admission_release=RELEASED" or "nuxr_admission_release=REFUSED". The claim is
-- released only if it is still PENDING with exactly that token (a newer claim is never touched), and every decision -
-- released or refused - is appended to nuxr_admission_audit with the operator, reason and evidence.
START TRANSACTION;
SET @nuxr_valid := (CHAR_LENGTH(COALESCE(@nuxr_operator, '')) BETWEEN 2 AND 64
    AND CHAR_LENGTH(COALESCE(@nuxr_reason, '')) BETWEEN 10 AND 255
    AND CHAR_LENGTH(COALESCE(@nuxr_evidence, '')) BETWEEN 10 AND 1024
    AND CHAR_LENGTH(COALESCE(@nuxr_username, '')) BETWEEN 1 AND 64
    AND COALESCE(@nuxr_token, '') REGEXP '^[0-9a-f]{32}$');
UPDATE nuxr_admission SET state = 'ENDED', ended_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), end_reason = 'operator_recovery'
 WHERE @nuxr_valid = 1
   AND username = CONVERT(@nuxr_username USING utf8mb4) COLLATE utf8mb4_bin
   AND token = CONVERT(@nuxr_token USING ascii) COLLATE ascii_bin
   AND state = 'PENDING';
SET @nuxr_released := ROW_COUNT();
INSERT INTO nuxr_admission_audit (at, action, outcome, username, token, operator, reason, evidence)
VALUES (UTC_TIMESTAMP(6), 'release_pending', IF(@nuxr_released = 1, 'RELEASED', 'REFUSED'),
        LEFT(CONVERT(COALESCE(@nuxr_username, '') USING utf8mb4), 64),
        IF(COALESCE(@nuxr_token, '') REGEXP '^[0-9a-f]{32}$', CONVERT(@nuxr_token USING ascii), '-'),
        LEFT(CONVERT(COALESCE(@nuxr_operator, '') USING utf8mb4), 64), LEFT(CONVERT(COALESCE(@nuxr_reason, '') USING utf8mb4), 255),
        LEFT(CONVERT(COALESCE(@nuxr_evidence, '') USING utf8mb4), 1024));
COMMIT;
SELECT IF(@nuxr_released = 1, 'nuxr_admission_release=RELEASED', 'nuxr_admission_release=REFUSED') AS nuxr_admission_release;
