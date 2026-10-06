# WP8 S2 — real AAA + accounting on RouterOS CHR 7.24.5 — results (2026-10-06)

`wp8-integration` `RealAaaTest` 7/7 (78 assertions) in the lab; independent Linux clients (pppd CHAP in netns
wp8c1 / wp8c3; Hotspot http-chap from wp8c2 / wp8c4); policies written by the application provisioner for nuxr_wp8_*
identities. No local RouterOS subscriber exists (`/ppp secret` empty; the only Hotspot user is the fixed, inert
`default-trial`, trial login not enabled).

| Proof | Result (NAS / traffic / radacct readback) |
|---|---|
| PPPoE discovery, CHAP, Access-Request with Message-Authenticator, Access-Accept | PADO `nuxr_wp8_chr`; `CHAP authentication succeeded`; every Access-Request MA = true |
| dynamic pool | reply `Framed-Pool = nuxr_wp8_pool_dyn`, no Framed-IP-Address; client 10.88.10.140 (pool 100-149); `/ppp active radius = true` |
| static IPv4 | reply `Framed-IP-Address = 10.88.10.50`, no Framed-Pool; client and `/ppp active` exactly 10.88.10.50 |
| rate + **direction** | reply `Mikrotik-Rate-Limit = 256K/512K`; dynamic queue `max-limit 256000/512000`; measured through the NAS: upload 253.2 kbps, download 505 kbps -> **first value (rx) = client upload, second (tx) = client download** |
| accounting | Start, Interim (1 min), Stop `User-Request`; `acctsessionid` = NAS `session-id` (0x81000014 -> 81000014); `nasipaddress` 10.88.0.2 = `nas.nasname`; framed address; `nasportid ether3`, `Ethernet`; input octets > uploaded bytes, output octets > downloaded bytes |
| Simultaneous-Use (PPPoE = 1) | second session from another host -> Access-Reject; accepted after the first closed |
| expiration | Session-Timeout 66-69 s for a +70 s expiry; the NAS ended the session at the expiry (Stop `Session-Timeout`); re-authentication -> Access-Reject |
| negatives | wrong password -> Reject; expired subscriber -> Reject; **nonexistent pool** -> Access-Accept with `Framed-Pool nuxr_wp8_pool_missing`, the client gets no IPv4 and RouterOS 7.24.5 keeps a PPP link with address 0.0.0.0 until it is torn down (recorded; RADIUS cannot detect a pool missing on the NAS) |
| Hotspot | DHCP lease; HTTP intercepted (302 -> `/login`); explicit auth method **http-chap** (CHAP over RADIUS); wrong password refused; `/ip hotspot active login-by = http-chap, radius = true`, address = DHCP address; queue `256000/512000`; Start / Interim; logout -> Stop `User-Request`; intercepted again (302) |
| Hotspot session limit (shared_users = 1) | second device -> Access-Reject (RouterOS shows FreeRADIUS' "You are already logged in - access denied") |
| Hotspot expiration | session ended at the expiry (Stop `Session-Timeout`); re-login refused; intercepted again |

Lab corrections made while building S2 (lab tooling only): the Hotspot server now uses `address-pool=none` (sharing
the DHCP pool as the one-to-one NAT pool let a second client receive an address already mapped to another session);
the agent routes the traffic peer over the PPPoE link (pppd runs with `nodefaultroute`); Hotspot success is detected
only on RouterOS' own success page (a RADIUS Reply-Message containing "logged in" is a refusal).

AVAIA guard (valid snapshot 11:51Z -> check after S2): all 27 protected containers unchanged; full non-lab container
list (names + creation times) identical before and after.
