# WP4 — RADIUS Hardening: model and contracts

- **Base:** `feat/reseller-system-v1` at `f2269a06` (WP3B accepted at `460714de` plus the owner metadata commit).
- **Mission:** a FreeRADIUS-first provisioning and accounting abstraction, without activation finance orchestration. Orchestration is WP5A's job.
- **No schema change:** no R0007; R0001–R0006 are byte-identical; the FreeRADIUS schema (`install/radius.sql`) is unchanged.

> **Note added 2026-10-09 (documentation sync; the accepted WP4 model below is unchanged and remains the implemented state on `feat/reseller-system-v1`).**
> The owner architecture lock [`architecture/owner-lock-2026-10-09/`](architecture/owner-lock-2026-10-09/OWNER_ARCHITECTURE_LOCK.md) adds these directions. None of them is implemented on the accepted branch yet.
> - **AD-03:** `radacct` is FreeRADIUS-owned accounting history. It is not literally immutable, because FreeRADIUS updates rows on Interim / Stop. The binding rule is unchanged: application code never deletes or rewrites it (D018 / C17 below).
> - **AD-04 / AD-06 / AD-07:** session admission moves to a logical Session Authority (PROVEN_SAFE / PROVEN_LIVE / UNKNOWN). "Stale" means unverified, not offline. Simultaneous-Use is service-aware and needs a concurrent-first-login gate.
>   - WP8 Option G is the lab-proven candidate (`wp8/integration-poc@47a3a02c`). It is **not** productionized.
>   - `AccountingService::staleSessions` stays a report only. It is never an admission authority.
> - **Status 2026-10-16 (WP9 sync, D-S8-4):** WP8 (Option G session authority, admission tables, H-2/H-2B/A2-R hardening) has since been accepted with notes (dev only) and merged into `feat/reseller-system-v1`; the current status of each gate is in `owner-review/WP9/`.
> - **Accounting time contract:** R-WP8-CARRY-03 / GAP-05 is open, and WP4 owner decision 4 (run FreeRADIUS in the application time zone) is unchanged.
>   - The lock suggests a canonical (preferably UTC) time contract. The final contract is an open owner decision; see `architecture/DOCUMENTATION_SYNC_REPORT.md`, conflict C-05.

## 1. Components (`system/autoload/Reseller/Radius/`, namespace `Reseller\Radius`)

| Class | Responsibility |
|---|---|
| `ProvisionResult` | `SUCCESS` / `FAILED` / `UNKNOWN`, with a code, a sanitized message, `retryable`, a NAS id and metadata. The values map 1:1 onto the R0003 `provisioning_result` enum. `UNKNOWN` is never retryable. |
| `RadiusPolicy` / `RadiusPolicyBuilder` | Pure. Turns customer + plan + bandwidth + **saved billing expiry** + billing-period start into the exact check / reply / group policy (§2). |
| `RadiusRepository` | The only writer of `radcheck`, `radreply`, `radusergroup`, `radgroupreply` and `nas`, on the `radius` connection. Every statement is prepared. Each change is one transaction, is idempotent, and touches only managed attributes. **Never touches `radacct`.** |
| `RadiusProvisioner` | provision, suspend, remove, rename, plan group apply/remove. Each operation writes, reads back to verify, and returns a `ProvisionResult` (§5). |
| `AccountingService` | Read-only access to the authoritative history `radacct`: open sessions, history, usage, stale sessions (report only). |
| `CoAClient` + `RadiusPacket` | A native RFC 5176 Disconnect-Request / CoA-Request client over UDP. No shell; Message-Authenticator; strict response checks (§6). |
| `RestGate` | Authentication for the rlm_rest endpoint `radius.php` (§7). |
| `RestCounter` | The local `rad_acct` counter table of the rlm_rest mode: per-session, non-authoritative (§4). |
| `RadiusFaults` | Test-only failure-injection points (source-guarded). |
| `ProvisionFailure` / `RadiusCommitUncertain` | Typed exceptions. |

**Legacy device `system/devices/Radius.php`.**
- Public methods are unchanged: `add_customer`, `sync_customer`, `remove_customer`, `change_username`, `add_plan`, `update_plan`, `remove_plan`, `disconnect_customer`, `nasAdd`, `nasUpdate`, `customerDeactivate`, `customerDelete`, `customerAddPlan`, `disconnectCustomer`, and the table getters.
- Every operation delegates to the classes above.
- A result other than SUCCESS raises `ProvisionFailure`. Callers keep their legacy failure path: `Package::rechargeUser` reports the error and notifies the operator to "sync manually".

**`system/devices/RadiusRest.php`.** At period end it resets the local REST counters through `RestCounter` (it zeroes the counters and keeps the rows).

## 2. Attribute contract

| Attribute | Table | Rule |
|---|---|---|
| `Cleartext-Password` | radcheck | PPPoE: `pppoe_password`, falling back to `password`. Hotspot / VPN: `password`. Suspension replaces it with 48 random hex characters. |
| `Simultaneous-Use` | radcheck | PPPoE: 1. Hotspot / VPN: `shared_users` (minimum 1). |
| `Expiration` | radcheck | The saved billing expiry, `d M Y H:i:s`, in the application time zone (§3). |
| `Max-All-Session` | radcheck | Limited Time / Both (Hotspot; PPPoE since V1, owner QUOTA_V1_GB_SUPPORT_APPROVED): the time quota in seconds. It is no longer overwritten by the seconds until expiry. |
| `Max-Data` | radcheck | Limited Data / Both (Hotspot; PPPoE since V1): bytes, decimal units as in the legacy code (1 GB = 10^9). FreeRADIUS replies the per-session share of the remaining quota as `Mikrotik-Total-Limit` / `Recv-Limit` / `Xmit-Limit` (+ Gigawords); `install/freeradius/README.md` "V1 data and time quota". A Limited plan with an invalid limit is never provisioned (`POLICY_QUOTA_INVALID`). |
| `Acct-Interim-Interval` | radreply | V1: 60 with a data quota (input of the accounting enforcer `system/reseller/quota.php`). |
| `Nuxr-Period-Start` | radcheck | Written with a quota: the attribution boundary = the UNIX start of the billing period **minus 10 s** (V1: the NAS stamps session starts up to ~2 s early; `QuotaPolicy::PERIOD_SKEW_SECONDS`, §4). |
| `Mikrotik-Rate-Limit` | radreply | `up/down` plus the burst tail. The burst is kept only when it consists of MikroTik rate tokens. |
| `Framed-IP-Address` | radreply | PPPoE with a valid IPv4 `pppoe_ip` (static). Never `0.0.0.0`. |
| `Framed-Pool` | radreply | PPPoE without a static address, when the plan has a pool (dynamic). Never written together with a static address. |
| `WISPr-Session-Terminate-Time` | radreply | Hotspot with an expiry: ISO 8601 with an offset. |
| group `plan_<id>` | radusergroup / radgroupreply | One membership, priority 1. The group rows are `Ascend-Data-Rate` (download), `Ascend-Xmit-Rate` (upload; the legacy code wrote the download rate twice) and `Mikrotik-Rate-Limit`. |

> **WP8 A1 addendum (2026-10-09, `wp8/integration-poc`; the accepted WP4 contract above is otherwise unchanged).**
> - `Port-Limit` | radreply | **Hotspot only**: `max(1, shared_users)`, equal to `Simultaneous-Use`. RouterOS enforces it at
>   the NAS (WP8 OD-W8-16, verified on RouterOS 7.24.5). It is still never written to `radcheck` (the legacy placement).
>   PPPoE and VPN get no `Port-Limit`.
> - PPPoE admission against an open `radacct` row is verified by the fail-closed PPPoE session guard in `post-auth`
>   (`install/freeradius/README.md` "PPPoE session guard"), and `delete_stale_sessions = no`. `radacct` is still never
>   deleted or rewritten; `AccountingService::staleSessions` stays a report only.

> **WP8 A2-R addendum (2026-10-09, `wp8/integration-poc`, pending owner acceptance; the contract above is otherwise
> unchanged).** PPPoE admission is additionally serialized by an atomic **admission reservation** in the RADIUS
> database (`install/freeradius/nuxr_admission.sql`, additive; `install/radius.sql` unchanged): one row per canonical
> subscriber (the exact `radcheck.username` the lookup matched, so case / accent spellings share it), claimed by
> FreeRADIUS as the last step before the Access-Accept and correlated with accounting through a per-attempt `Class`
> token. PHPNuxBill does not write or read it; `radacct` is still never written by the policy. Install order, readiness
> check, recovery procedure and limits: `install/freeradius/README.md` "PPPoE admission reservation".

> **WP8 H-2B addendum (2026-10-11, `wp8/integration-poc`, implementation pending owner acceptance; the contract above is
> otherwise unchanged).** `shared_users` of a Hotspot plan is the limit across **every** NAS of the ISP. RouterOS enforces
> `Port-Limit` per NAS only (WP8 H-2), so Hotspot admission is additionally serialized by **admission slots** in the
> RADIUS database (`install/freeradius/nuxr_hs_admission.sql`, additive; `install/radius.sql` unchanged): one row per
> (canonical subscriber, slot), slots `1..Simultaneous-Use`, claimed by FreeRADIUS after the H1-A spelling check and
> correlated with accounting through a per-claim `Class` token ("nuxh1"). `Port-Limit` stays as an additional per-NAS
> limit. Strict Mode: a claimed slot whose session is unconfirmed (`PENDING`) is never released by time or SNMP; an
> `ACTIVE` one is released only on read-only SNMP proof from its NAS. PHPNuxBill does not write or read the slots;
> `radacct` is still never written by the policy. Details: `install/freeradius/README.md` "Hotspot admission slots".

- **RADIUS key.** PPPoE: `pppoe_username` when set (owner decision Q7), otherwise `username`. Hotspot / VPN: `username`.
- **Managed attributes.** Applying a policy removes managed attributes the policy does not contain. That includes the legacy reply attributes the old device stored in `radcheck` (Mikrotik-Rate-Limit, Port-Limit, Mikrotik-Wireless-Comment, WISPr-Session-Terminate-Time) and `Framed-IP-Netmask`. Unmanaged attributes are left alone.

## 3. Expiry follows billing (owner decision Q5)

The device reads `expiration` / `time` (and `recharged_on` / `recharged_time` for the period start) from the active recharge of that plan: `customer_id`, or `username` for a voucher-only customer, with `status = 'on'`. If no active recharge exists, the device refuses with `NO_ACTIVE_RECHARGE` and writes nothing.

For that to hold on every path, the callers now **save the recharge row before calling the device**:
- `Package::rechargeUser`, both branches. This is an owner-approved change to a pinned file; the hash pin in `tests/Unit/Wp3a/SourceGuardTest.php` is updated.
- `plan/edit-post`. It also syncs a RADIUS plan when only the expiry changed; previously RADIUS never saw that edit.
- `plan/extend` and the customer self-extend `home?extend`.

A failed device call therefore leaves the billing row saved: the legacy "billed, sync manually" semantics, now with an explicit failure.

**WP8 S7-FC clarification (owner decision OD-S7-FC-1, 2026-10-14).**
- **A confirmed ownership refusal happens before any billing write.** Before its recharge cycle, `Package::rechargeUser` runs the device default deny (`LegacyReprovisionService::assertDeviceAllowed`, which checks `username` and `pppoe_username`). It does this for a non-voucher grant, after the customer and plan are loaded and after the balance branches.
- **What a refusal leaves behind.** The refused case is a customer whose identity collides with a reseller-owned customer's key. The function returns `false` with nothing written: no `recharge_user` hook, no recharge insert or update, no transaction or invoice, no device call and no notification. Only the refusal is recorded (`op=deviceGrant`).
- **Voucher-only grants are unchanged.** The WP2 gate at the top already judges them by the same username / PPPoE-username lookup.
- **The order above is unchanged for every allowed grant:** the recharge is saved, then the device runs.
- **Later device failures behave as before.** Examples are an unreachable API or a device error unrelated to ownership. They keep the billing row and the "sync manually" path.

## 4. Accounting

- **`radacct`** (FreeRADIUS, `radius` connection) is the authoritative history. FreeRADIUS writes it; PHPNuxBill only reads it (`AccountingService`). **No lifecycle path deletes or rewrites it** (C17, D018; source-guarded and tested for every device operation and the admin flows).
- **Quotas per billing period.** The counters in `install/freeradius/mods-available/nuxr_counters` sum only the sessions with `acctstarttime >= Nuxr-Period-Start`. A renewal therefore starts a fresh quota without deleting history; the pre-WP4 code reset quotas by purging `radacct`.
- **`rad_acct`** (application database) is the rlm_rest mode's local counter state, not history (owner decision Q9):
  - one row per session (username + nasid + macaddr + acctsessionid);
  - cumulative Interim / Stop values are set, not added (the legacy code double-counted);
  - usage is the sum of the rows;
  - the period end zeroes the counters;
  - the cron reaper marks silent sessions stopped (the legacy `OR` precedence bug is fixed).
- **Stale and open sessions are reported only** (`AccountingService::staleSessions`, owner decision Q8). Reconciliation actions belong to WP5A.

### 4.1 Accounting time contract (WP8 A4, R-WP8-CARRY-03; owner decision 2026-10-13)

- **Writer.** FreeRADIUS writes `acctstarttime`, `acctupdatetime` and `acctstoptime` as `FROM_UNIXTIME(epoch)` (stock queries). The epoch is `Event-Timestamp` when the NAS sends it (then the **NAS clock**), else the FreeRADIUS receive time (`%l`). The value is a wall clock in the writer's **SQL session zone**, and `DATETIME` stores no zone.
  - **Proven on RouterOS 7.24.5 (PPPoE, CHR lab):** `Event-Timestamp` is present in Start, Interim-Update and Stop; it was 1 s behind the capture time.
  - **Absent in Accounting-On.** For Accounting-On FreeRADIUS uses `%l`: the stock accounting-on query closes the NAS's open rows with `acctstoptime = FROM_UNIXTIME(%l)` and `acctsessiontime = %l - UNIX_TIMESTAMP(acctstarttime)`. This mixes the FreeRADIUS clock with the NAS clock, which is why both need NTP.
  - **Not captured:** Hotspot accounting and other NAS models or firmware. Verify them in the operational preflight.
- **Reader.** The application never parses these columns as PHP-local strings. `AccountingService` reads them as epochs, `UNIX_TIMESTAMP(col) AS acctstart_epoch / acctupdate_epoch / acctstop_epoch` (additive columns; the `DATETIME` columns are unchanged). Every comparison and display uses the epochs:
  - `staleSessions($now, $s)` compares with the instant `$now->getTimestamp() - max(60, $s)`; the zone of `$now` does not matter;
  - `Reseller\Ui\Subscriber::session()` computes the state from `time()` and the epoch, and shows the times in the application zone with their UTC offset (`2026-10-13 12:35:13 UTC+03:00`).
- **Same session zone on both sides.** The conversion is lossless only when the writer and the reader use the same session zone. Neither FreeRADIUS (`sql` module) nor `init.php` sets `time_zone`, so both use the RADIUS database server zone (source-guarded). **Never set `time_zone` on one side only**, and do not change the server zone while sessions are open: rows written before a change keep the old wall clock and read off by the difference. They are never rewritten.
- **The unified UTC contract is about the effective SESSION zone, not `@@global.time_zone` alone.**
  - **What counts:** the zone each connection actually uses. That is `@@session.time_zone`; when it is `SYSTEM`, it is `@@system_time_zone`, and the effective offset is `TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())` in that session.
  - **Why global is not enough:** a session can override the global value (an `init_command`, a driver option, a connection pool opened before a global change).
  - **Verified (development only):** the harness (both engines) and the WP8 lab show `@@global` = `@@session` = `SYSTEM`, `@@system_time_zone` = `UTC` and a session offset of 0 on the application connection.
  - **How the writer was verified:** FreeRADIUS's own session variables cannot be read from outside without a configuration change. Its session zone was proven by round trip: the text it stored for a known epoch equals the application session's `FROM_UNIXTIME` of that epoch, and in the lab a real start at 18:48:18 UTC was stored as `18:48:18`.
  - **Not verified:** production.
- **Production requirement: the RADIUS database zone is UTC** (no DST). In a DST zone the repeated autumn hour maps two instants to one `DATETIME`, and this is characterised by test:
  - freshness, `acctinterval` and the accounting-on `acctsessiontime` can be wrong by 3600 s;
  - the quota period edge (`acctstarttime >= FROM_UNIXTIME(period)`) can count a session from before the period.
- **Operational preflight before production (later stage, production blocker).** A4 changes no production setting. The preflight must:
  1. check the effective session zone of the FreeRADIUS `sql` connections **and** of the application `radius` connection (UTC, offset 0, no DST);
  2. check NTP on every NAS and on the FreeRADIUS, application and database hosts;
  3. survey the existing `radacct` history for rows written under another zone.

  Such rows need a separate, owner-approved compatibility or migration plan. A4 authorises no data migration, no change of an existing database zone and no change of historical `radacct` rows.
- **Not the FreeRADIUS process zone.** The FreeRADIUS process `TZ` serves `Expiration` only and stays equal to the application zone (WP4 residual 4). It is independent of the SQL session zone. The WP8 lab runs exactly this split: FreeRADIUS `TZ=Asia/Baghdad` for `Expiration`, SQL session UTC for accounting. Do not "fix" one by changing the other.
- **Clocks.** The PHP, FreeRADIUS, database and **every NAS** clock must be NTP-synchronised. A last update more than 300 s in the future (`AccountingService::CLOCK_TOLERANCE_SECONDS`) breaks the contract.
- **Fail-safe.** A missing, invalid (zero / out-of-range) or future time is never a confirmed "online":
  - `Subscriber::session()` shows "Status unknown";
  - `staleSessions()` reports the row for review.
- **A writer / reader zone mismatch voids the freshness guarantee.** Every epoch read is off by exactly the zone difference Δ (characterised by test):
  - **Writer ahead of the reader:** a freshly written row reads as a future time ("Status unknown", reported). But a session whose accounting went silent about Δ ago reads as just updated, so it can show "online" for up to 1800 s (display) or the report threshold (`staleSessions`).
  - **Writer behind the reader:** sessions look older by Δ. A Δ smaller than 1800 s (a 15 / 30 / 45 min zone pair) can still show "online", with every time shifted by Δ.

  This is why the same session zone (UTC) on both sides is a deployment requirement, not a code guarantee.
- **Unchanged contracts:**
  - `Expiration` is parsed in the FreeRADIUS process zone; run FreeRADIUS in the application zone (residual 4; a separate policy contract, OD-A4-3);
  - the rlm_rest `rad_acct` reaper uses the PHP clock on both sides (RadiusRest, OD-A4-4);
  - A2-R / H-2B use `UTC_TIMESTAMP` only.

## 5. Results and failure semantics

| Situation | Result |
|---|---|
| Committed, and the read-back equals the policy | `SUCCESS` (`changed` = whether anything was written) |
| Error before COMMIT (rolled back) | `FAILED`. Retryable only for 1205 / 1213 / 2002 / 2006 / 2013. |
| COMMIT sent, acknowledgement lost | `UNKNOWN` `RADIUS_COMMIT_UNKNOWN` |
| Committed, but the read-back differs or is unavailable | `UNKNOWN` `RADIUS_VERIFY_MISMATCH` / `RADIUS_VERIFY_UNAVAILABLE` |

Nothing retries by itself. `UNKNOWN` is never `retryable`.

## 6. Disconnect / CoA (SEC-11)

- `CoAClient::disconnect(username)` sends one Disconnect-Request per open `radacct` session. `changeAuthorization(username, attributes)` sends a CoA-Request.
- **Request contents:** User-Name, Acct-Session-Id, Framed-IP-Address and NAS-IP-Address, plus Message-Authenticator. The Request Authenticator follows RFC 5176.
- **NAS:** only the `nas` row whose `nasname` equals the session's NAS address. The port is `nas.ports` when valid (legacy convention), otherwise 3799.
- **Outcomes:**
  - ACK → `SUCCESS`;
  - NAK → `FAILED` with `error_cause`;
  - Disconnect NAK 503 (Session Context Not Found) → `SUCCESS` `SESSION_NOT_FOUND`;
  - no valid answer after the identical retransmissions → `UNKNOWN` `COA_NO_RESPONSE`;
  - a reply with a wrong identifier or authenticator is ignored.
- **No shell or process.** The secret never appears in logs or argv.
- **Legacy device behaviour:** after provisioning, the device ends the subscriber's sessions so the new policy applies (as before). Failures are logged and never thrown.

> **WP8 S8 note (2026-10-14).** The rules above are unchanged in code. On RouterOS CHR 7.24.5 the following were verified (§13):
> - the NAS never answers 503. It NAKs every request that matches no session with **Error-Cause 406**, which stays `FAILED COA_NAK` (OD-W8-14, Option A), so on MikroTik a vanished session shows as `FAILED COA_NAK (406)`, not `SESSION_NOT_FOUND`;
> - a CoA from an address that is not a configured `/radius` server was ignored. This was observed in the feasibility gate on RouterOS **7.12.1** and **not re-tested on 7.24.5**; the rule stays an owner contract (OD-W8-11);
> - a CoA-ACK does not prove an address or pool change (policy → Disconnect → re-authentication).

## 7. rlm_rest endpoint `radius.php` (SEC-01, E16, owner decisions Q3 and Q4)

- **Off unless configured.** `config.php` must set `$radius_rest_secret` (at least 32 characters); `$radius_rest_allow` is optional and defaults to loopback.
- **Every request** must come from an allowed address (the FreeRADIUS servers, not the NAS devices) and present the secret: as the HTTP Basic password (rest module `auth = 'basic'`) or as `X-Nuxr-Radius-Secret`.
- **All queries are parameterized.** Source guard: no variable or concatenation in raw SQL.
- **E16.** A voucher authenticates only while it is unused (authorize redeems it) or while its recharge is active and not expired.
- **Q4.** `Password::chap_verify` returned `true` on a **mismatch**, so any CHAP response was accepted (proven: old code right=false, wrong=true). It now verifies the RFC 1994 response in constant time and rejects malformed input.
- **Authorize** prefers the active recharge, and also refuses a recharge whose status is not `on`.

## 8. NAS administration (SEC-17, NAS part)

- The edit form never renders the stored secret: the field is write-only, and empty keeps the current secret.
- **Validation:** the address must be an IP, a CIDR range or a host name; the port 1–65535; the secret 1–60 printable characters.
- **Storage:** the secret stays plaintext in `nas`, because FreeRADIUS `read_clients` needs it (owner decision Q10).

## 9. FreeRADIUS configuration and test environment (C16; owner decisions Q1, Q2 option a)

- **Configuration:** `install/freeradius/` (see its README) holds the dictionary (`Nuxr-Period-Start`), the period counters (`Max-All-Session`, `Max-Data`), the `phpnuxbill` virtual server (PAP / CHAP / expiration / quotas / accounting / Simultaneous-Use) and an example `sql` module.
- **Tests:** `dev/test/compose.yml` runs `freeradius/freeradius-server:3.2.8`, pinned by digest, once per engine (`freeradius-mariadb`, `freeradius-mysql`). There are no published ports and the secret is throwaway. `dev/test/freeradius/start.sh` installs the project configuration against the `nuxr_radius` test database.
- **No real NAS / MikroTik** is involved (WP8). CoA is tested against a loopback stub NAS.
- **WP8 S8 note (2026-10-14):** this describes the WP4 harness, which is unchanged. Real-NAS behaviour was verified separately in the WP8 CHR lab (`dev/wp8/`, §13).

## 10. Binding input for WP5A

1. **Provisioning.** Build the policy with `RadiusPolicyBuilder::build(customer, plan, bandwidth, expiry, periodStart)` using the activation's authoritative expiry. Apply it with `RadiusProvisioner::provision()`, **outside any database transaction**, after the reservation (WP3B §9). Store `ProvisionResult::status` in `tbl_reseller_activation_events.provisioning_result`, plus the sanitized code and message.
2. **Outcomes.**
   - `UNKNOWN` → RECONCILIATION_REQUIRED. Never retry blindly; read the RADIUS state back (`RadiusRepository::readUser`) to decide.
   - `FAILED` and `retryable` → the activation may retry the identical policy (idempotent).
   - `FAILED` and not retryable → fail the activation with no settlement.
3. **Disconnect / CoA.** `UNKNOWN` CoA results do not undo provisioning; they only mean the old session may continue until re-authentication.
4. **Never** write `radacct`, the `rad_acct` counters, or the RADIUS tables directly. Use the repository and provisioner only.
5. **Stale sessions.** `AccountingService::staleSessions` is the reconciliation input. Closing or adjusting sessions is a WP5A decision.

## 11. Owner decisions applied

| # | Decision |
|---|---|
| Q1 | FreeRADIUS 3.2.x test image in the isolated test project, loopback only, stub NAS: **approved** (3.2.8, pinned by digest) |
| Q2 | C16 option **a**: project dictionary and counters |
| Q3 | rlm_rest endpoint: shared secret plus source allowlist, parameterized SQL, **off by default** |
| Q4 | CHAP bypass fixed after a test proved it |
| Q5 | RADIUS expiry follows the saved billing expiry. **Pinned `Package.php` change approved**: the device runs after the save. WP8 S7-FC (OD-S7-FC-1): a confirmed ownership refusal is judged before any billing write (§3); the order for allowed grants and later device failures is unchanged |
| Q6 | Native Disconnect + CoA-Request; UNKNOWN surfaced |
| Q7 | PPPoE key = `pppoe_username` when set; rename covers all three tables |
| Q8 | Stale / open sessions reported only (WP5A reconciles) |
| Q9 | Local `rad_acct` kept, fixed and documented |
| Q10 | NAS secret write-only in the UI; plaintext at rest (FreeRADIUS needs it); documented |
| Q11 | The C17 fix applies globally (every customer through the legacy device) |
| Q12 | The test autoloader includes `Reseller\Radius` |

## 12. Residuals

1. **Pre-WP4 PPPoE rows keyed by `username`.**
   - Before WP4, PPPoE customers with a `pppoe_username` had their policy under `username`.
   - The new policy lives under `pppoe_username`, but the old rows stay until the customer's username policy is removed or re-provisioned. They are the same customer, the same plan and the same credentials.
   - They are not deleted automatically, because a customer may also have a Hotspot plan under `username`.
2. **History lost before WP4.** Deployments that ran the legacy device may already have lost `radacct` history (WP0 risk I.2). WP4 stops further loss.
3. **rlm_rest deployments must configure the secret.** The endpoint is off by default. This is a deliberate breaking change (SEC-01).
4. **Expiration and FreeRADIUS time zone.** `Expiration` is interpreted in FreeRADIUS local time; run FreeRADIUS in the application time zone. This is independent of the accounting time contract (§4.1), which depends on the database session zone, not the FreeRADIUS process zone.
5. **Sessions across a period boundary.** A session that started before the period start is not counted in the new period.
6. **No real-NAS verification.** CoA and Disconnect are verified against a stub NAS only. MikroTik verification is WP8.
   - **WP8 S8 update (2026-10-14):** the residual is superseded for RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8 only (§13). Other RouterOS versions and models, physical NAS hardware, quotas on a NAS, IPv6 and the rlm_rest mode remain unverified.
7. **Device errors are now explicit.** Device-level failures raise `ProvisionFailure`. Legacy controllers without try/catch show the generic error page (as they did for PDO errors before). `Package::rechargeUser` reports them and notifies the operator.
8. **Plaintext secrets.** The NAS secret is plaintext at rest (Q10). The customer password is plaintext because Cleartext-Password / CHAP needs it (WP0 §12).
9. **Unchanged by WP4:** the legacy MikroTik device methods (WP8), the `.htaccess_firewall` default (SEC-20, WP10), and the `logs/radius` export of `radpostauth` passwords (WP2 R11). The project site does not enable post-auth logging.
10. **Accounting time (WP8 A4).** The §4.1 contract fixes the application side. The remaining conditions are deployment requirements, not code:
    - a UTC (non-DST) RADIUS database zone;
    - the same session zone for the writer and the reader;
    - NTP on every host and the NAS.
    Rows written under a former database zone are read shifted and never corrected in place.

## 13. WP8 real-NAS verified notes (S8, 2026-10-14)

**Scope of these notes.**
- **What was verified:** RouterOS **CHR 7.24.5** (QEMU, isolated lab) with FreeRADIUS **3.2.8**, `read_clients` from `nas`, and the project site.
- **Exceptions** (marked in the row): the feasibility gate on **7.12.1 / 7.20.3**, and lab FreeRADIUS results driven by a **crafted test client** (no RouterOS).
- **Nothing changed:** these notes record evidence only. No contract above changes. The full model is `docs/WP8_INTEGRATION_MODEL.md`.
- **Paths and labels:** evidence paths are relative to `owner-review/WP8/`. "Harness only" marks an item that was **not** verified on the NAS.

| Contract item (section) | Verified behaviour on the CHR | Evidence |
|---|---|---|
| §2 `Mikrotik-Rate-Limit` | `rx/tx` = client **upload / download** (dynamic queue `256000/512000`; measured 253 / 505 kbps) | `evidence/s2/lab-evidence.jsonl` |
| §2 `Framed-Pool` / `Framed-IP-Address` | A pool gives an address from the named pool; a static gives the exact address; never both. A pool that does not exist on the NAS gives an Access-Accept with **no IPv4** (RADIUS cannot detect it). | `evidence/s2/` |
| §2 `Simultaneous-Use` | The second PPPoE session is rejected; Hotspot `shared_users=1` is refused. **Alone it is not sufficient:**<br>• a lost Stop leaves an indefinite lockout (S6);<br>• the stock checkrad fails open (Option F).<br>Admission is completed by the A1 session guard, the A2-R reservation, H1-A and H-2B (§2 addenda). | `evidence/s2/`, `evidence/s6/`, `evidence/od-w8-16-*` |
| §2 `Port-Limit` (A1 addendum) | Enforced per NAS on **Hotspot** (limits 1 and 2); **ignored on PPPoE** (two live sessions with Port-Limit 1 in the Accept) | `evidence/od-w8-16-gate/GATE_RESULTS.md` |
| §2 `Expiration` | The NAS gets a Session-Timeout capped at the expiry, sends Stop `Session-Timeout`, and the next authentication is rejected. The FreeRADIUS process `TZ` was the application zone (residual 4). | `evidence/s2/`, `evidence/s5/` |
| §2 quotas (`Max-All-Session`, `Max-Data`, `Nuxr-Period-Start`) | **Not verified on the NAS.** WP8 S1 covered them only with crafted packets from a test client against the lab FreeRADIUS (no RouterOS). | `evidence/s1/S1_RESULTS.md` |
| §3 expiry follows billing | RENEW rewrites `Expiration` with **no disconnect**. The live session keeps its auth-time Session-Timeout until it re-authenticates. | `evidence/s5/` |
| §4 `radacct` authority | Start / Interim (1 m) / Stop, one row per session. Terminate causes `User-Request`, `NAS-Request` / `Admin-Reset` (Disconnect), `Session-Timeout`, `NAS-Reboot` (Accounting-On).<br>**A lost Stop is not resent by RouterOS.**<br>No lifecycle path deleted a row. | `evidence/s2/`, `evidence/s3/`, `evidence/s6/` |
| §4.1 accounting time | `Event-Timestamp` is present in PPPoE Start / Interim / Stop and absent in Accounting-On. The epoch reads match the real session. **Hotspot and other NAS models were not captured.** | `evidence/a4/lab/` |
| §5 results | `RADIUS_DB_ERROR` → FAILED (reservation released).<br>`radius.commit_lost` → `RECONCILIATION_REQUIRED` → one settlement after reconcile.<br>FreeRADIUS paused before provisioning → the activation completes (DB authority) but no service is given until FreeRADIUS returns. | `evidence/s6/S6_RESULTS.md` |
| §6 Disconnect / CoA | **Answers:**<br>• ACK → `SUCCESS`, exactly one packet;<br>• **NAK 406** for any request without a matching session → `FAILED COA_NAK` (OD-W8-14, Option A; **503 is never sent**);<br>• silence → `UNKNOWN COA_NO_RESPONSE` after 3 identical packets about 2 s apart.<br>**Source and port:** port 3799 = `nas.ports`; the co-located FreeRADIUS address is answered. A request from an address that is not a `/radius` server was ignored **on 7.12.1 (feasibility gate) only**, so the source rule is an owner contract (OD-W8-11) that was not re-tested on 7.24.5.<br>**Effects:** a rate CoA is applied live; a `Framed-Pool` / `Framed-IP-Address` CoA is ACKed and **ignored**. | `evidence/chr-feasibility/coa.txt`, `evidence/s3/`, `evidence/s6/` |
| §6 legacy device rename | A3: exact-byte rename. The old identity's sessions are captured and disconnected (CONFIRMED on CHR1 + CHR2). | `evidence/a3/` |
| §8 NAS administration | NAS identity: `radacct.nasipaddress` = `nas.nasname` = RouterOS `/radius src-address` (OD-W8-4) | `evidence/s1/`, `evidence/s2/`, `evidence/s3/` |
| §9 FreeRADIUS configuration | **Message-Authenticator** (lab FreeRADIUS with a crafted SQL client): the global `require_message_authenticator = yes` is **not** applied to SQL-loaded clients, and the project site enforces it in `authorize` (OD-W8-13).<br>**On the NAS:** RouterOS 7.24.5 sends it in every Access-Request; 7.12.1 sends none (feasibility gate). The floor RouterOS ≥ 7.15 is an **owner contract**, not a test result.<br>**Accounting-On:** RouterOS sends one before its first record (OBS-W8-7). | `evidence/s1/`, `evidence/s1-ma-policy/`, `evidence/chr-feasibility/` |

**Still unverified on a NAS:**
- quotas;
- IPv6;
- other PPP services (L2TP / PPTP / SSTP / OVPN);
- other RouterOS versions or hardware;
- the rlm_rest mode;
- Hotspot `Event-Timestamp`;
- RouterOS's default incoming port 1700 (documentation only).

Production preconditions remain open (`owner-review/WP8/REPORT.md` §5).

**WP8 final development note (2026-10-15).**
- The operator README `install/freeradius/README.md` ("Disconnect / CoA") now carries the §6 port and source notes above:
  - `nas.ports` is the CoA / Disconnect destination port (empty → 3799), and the NAS port must be set explicitly;
  - RouterOS 1700 is documented as the vendor default only;
  - CoA must come from a `/radius` server address; the wrong-source case is [CHR-FG], observed on 7.12.1 only.
- No contract in this document changed.
- WP8 is development-accepted with blockers (`WP8_FINAL_DEVELOPMENT_ACCEPTED_WITH_BLOCKERS`, `PRODUCTION_NOT_AUTHORIZED`). The production preconditions in `owner-review/WP8/REPORT.md` §5 remain open.
