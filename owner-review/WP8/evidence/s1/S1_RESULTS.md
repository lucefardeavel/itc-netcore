# WP8 S1 — permanent lab + OD-W8-13 enforcement — results (2026-10-06)

Lab: `dev/wp8/lab.sh up` from the committed tooling — CHR **7.24.5** (image SHA-256 `e4caf173…2d2`, verified by
`lab.sh`), QEMU 8.2.2 TCG, API after 87–101 s; FreeRADIUS 3.2.8 with `read_clients = yes` (startup:
`Client "nuxr_wp8_chr" (sql) added`, `Client "nuxr_wp8_app" (sql) added`, global `require_message_authenticator = auto`
unchanged — the protection comes only from the virtual server); TZ `Asia/Baghdad` for FreeRADIUS and the runner.
Refusal checks, idempotent `down` (0 links / namespaces / QEMU / containers / networks) exercised three times.

| Check | Result |
|---|---|
| `wp8-integration` `FreeRadiusSqlClientTest` (SQL NAS client `nuxr_wp8_app`, real FreeRADIUS) | 8/8 |
| — MA-1 missing (PAP, CHAP) | no response; debug: `if (!&Message-Authenticator) -> TRUE`, `do_not_respond = handled`, no module after |
| — MA-2 wrong HMAC (PAP, CHAP; correct password) | no response; debug: `Dropping packet without response because of error: Received packet from 10.88.0.10 with invalid Message-Authenticator! … (from client nuxr_wp8_app)`; never reaches authorize |
| — MA-3 valid | PAP / CHAP good -> Access-Accept; bad -> Access-Reject |
| — accounting without Message-Authenticator | Start / Interim / Stop answered and written (counters, NAS address) |
| — PAP, CHAP, Framed-Pool, static Framed-IP-Address (never both), rate `256K/512K`, Simultaneous-Use, Expiration in the application zone (Session-Timeout 60–90 s for +90 s), time and data quotas per period, history kept | pass |
| `wp8-integration` `RouterOsClosureTest` (CHR 7.24.5, independent clients) | 1/1 — PPPoE CHAP (pool address), Hotspot http-chap, all RouterOS Access-Requests with Message-Authenticator, Access-Accepts, Start / Interim / Stop without Message-Authenticator answered, application `CoAClient` disconnect from 10.88.0.10 -> Disconnect-ACK x2, `nas_id = 1`, Stop rows, Hotspot re-intercepted (302) |
| WP4 `wp4-unit` + `wp4-integration` (new source guard + harness MA contract) | MariaDB 71/71 (599 assertions), MySQL 71/71 (599) |
| PEAR2 `RouterOS\Client` from the runner (plain 8728) | login 0.26 s; `/system/resource`, `/radius`, `/radius incoming` (3799), `/ppp aaa` read; 14 PHP 8.2 deprecation notices (pre-existing, OBS-W8-6) |
| AVAIA guard (valid snapshot 11:22:57Z -> check 11:49:53Z) | all 25 protected containers unchanged; no non-lab container event |

Observation **OBS-W8-7** (pre-existing, not changed): RouterOS sends a NAS-level **Accounting-On** before its first
accounting record; the stock `accounting-on` SQL query updates 0 rows when the NAS has no open session, the section
returns `noop`, and FreeRADIUS 3 sends no Accounting-Response (RouterOS counts one timeout). With open sessions the
same query closes them as `NAS-Reboot` by UPDATE — accounting history is never deleted.
