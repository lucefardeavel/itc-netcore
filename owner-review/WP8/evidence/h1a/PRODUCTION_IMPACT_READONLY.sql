-- WP8 H-1 H1-A - PRODUCTION COMPATIBILITY IMPACT, READ-ONLY (for owner review; NOT run on production; needs a separate
-- owner authorization). Only SELECT statements. Run per ISP instance, on the RADIUS database (queries 1-4) and on the
-- PHPNuxBill application database (query 5). Output: counts and names - no passwords are read.

-- 1. Hotspot sessions of the last 90 days opened under a spelling that is NOT the stored one (these users would be
--    refused by H1-A until they type the stored spelling). The join uses the lookup collation on purpose; the
--    comparison of the spellings is byte-wise.
SELECT c.username AS stored_spelling, a.username AS used_spelling, COUNT(*) AS sessions, MAX(a.acctstarttime) AS last_seen
FROM radacct a
JOIN (SELECT DISTINCT username FROM radcheck) c ON c.username = a.username
WHERE a.nasporttype = 'Wireless-802.11'
  AND a.acctstarttime >= NOW() - INTERVAL 90 DAY
  AND CAST(a.username AS BINARY) <> CAST(c.username AS BINARY)
GROUP BY c.username, a.username
ORDER BY sessions DESC;

-- 2. The same as numbers: affected subscribers and their share of all Hotspot subscribers seen in 90 days.
SELECT COUNT(DISTINCT CASE WHEN CAST(a.username AS BINARY) <> CAST(c.username AS BINARY) THEN c.username END) AS affected_subscribers,
       COUNT(DISTINCT c.username) AS hotspot_subscribers_seen
FROM radacct a
JOIN (SELECT DISTINCT username FROM radcheck) c ON c.username = a.username
WHERE a.nasporttype = 'Wireless-802.11' AND a.acctstarttime >= NOW() - INTERVAL 90 DAY;

-- 3. Ambiguous identities: byte-distinct stored spellings that the lookup treats as ONE subscriber (H1-A refuses every
--    Hotspot login of these; the A2-R collision pre-check expects zero).
SELECT MIN(username) AS example, COUNT(DISTINCT CAST(username AS BINARY)) AS stored_spellings
FROM radcheck GROUP BY username HAVING COUNT(DISTINCT CAST(username AS BINARY)) > 1;

-- 4. MAC-shaped usernames stored with lower-case letters (RouterOS MAC login sends UPPER case with colons; H1-A refuses
--    these in every mac-auth mode).
SELECT DISTINCT username FROM radcheck
WHERE username REGEXP '^[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}$'
  AND CAST(username AS BINARY) <> CAST(UPPER(username) AS BINARY);

-- 5. (application database) Hotspot vouchers by letter case of the code: "mixed" codes must be typed exactly; "upper"
--    / "lower" codes in their case; "no_letters" codes are not affected.
SELECT CASE WHEN CAST(code AS BINARY) = CAST(UPPER(code) AS BINARY) AND CAST(code AS BINARY) = CAST(LOWER(code) AS BINARY) THEN 'no_letters'
            WHEN CAST(code AS BINARY) = CAST(UPPER(code) AS BINARY) THEN 'upper'
            WHEN CAST(code AS BINARY) = CAST(LOWER(code) AS BINARY) THEN 'lower'
            ELSE 'mixed' END AS code_case,
       status, COUNT(*) AS vouchers
FROM tbl_voucher WHERE type = 'Hotspot'
GROUP BY 1, 2 ORDER BY 1, 2;
