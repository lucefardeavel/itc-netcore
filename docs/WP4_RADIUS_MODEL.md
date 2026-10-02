# WP4 — RADIUS Hardening: model and contracts

- **Base:** `feat/reseller-system-v1` at `f2269a06` (WP3B accepted at `460714de` plus the owner metadata commit).
- **Mission:** a FreeRADIUS-first provisioning and accounting abstraction, without activation finance orchestration. Orchestration is WP5A's job.
- **No schema change:** no R0007; R0001–R0006 are byte-identical; the FreeRADIUS schema (`install/radius.sql`) is unchanged.

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
| `Max-All-Session` | radcheck | Hotspot Limited Time / Both: the time quota in seconds. It is no longer overwritten by the seconds until expiry. |
| `Max-Data` | radcheck | Hotspot Limited Data / Both: bytes, decimal units as in the legacy code. |
| `Nuxr-Period-Start` | radcheck | Written with a quota: the UNIX start of the billing period (§4). |
| `Mikrotik-Rate-Limit` | radreply | `up/down` plus the burst tail. The burst is kept only when it consists of MikroTik rate tokens. |
| `Framed-IP-Address` | radreply | PPPoE with a valid IPv4 `pppoe_ip` (static). Never `0.0.0.0`. |
| `Framed-Pool` | radreply | PPPoE without a static address, when the plan has a pool (dynamic). Never written together with a static address. |
| `WISPr-Session-Terminate-Time` | radreply | Hotspot with an expiry: ISO 8601 with an offset. |
| group `plan_<id>` | radusergroup / radgroupreply | One membership, priority 1. The group rows are `Ascend-Data-Rate` (download), `Ascend-Xmit-Rate` (upload; the legacy code wrote the download rate twice) and `Mikrotik-Rate-Limit`. |

- **RADIUS key.** PPPoE: `pppoe_username` when set (owner decision Q7), otherwise `username`. Hotspot / VPN: `username`.
- **Managed attributes.** Applying a policy removes managed attributes the policy does not contain. That includes the legacy reply attributes the old device stored in `radcheck` (Mikrotik-Rate-Limit, Port-Limit, Mikrotik-Wireless-Comment, WISPr-Session-Terminate-Time) and `Framed-IP-Netmask`. Unmanaged attributes are left alone.

## 3. Expiry follows billing (owner decision Q5)

The device reads `expiration` / `time` (and `recharged_on` / `recharged_time` for the period start) from the active recharge of that plan: `customer_id`, or `username` for a voucher-only customer, with `status = 'on'`. If no active recharge exists, the device refuses with `NO_ACTIVE_RECHARGE` and writes nothing.

For that to hold on every path, the callers now **save the recharge row before calling the device**:
- `Package::rechargeUser`, both branches. This is an owner-approved change to a pinned file; the hash pin in `tests/Unit/Wp3a/SourceGuardTest.php` is updated.
- `plan/edit-post`. It also syncs a RADIUS plan when only the expiry changed; previously RADIUS never saw that edit.
- `plan/extend` and the customer self-extend `home?extend`.

A failed device call therefore leaves the billing row saved: the legacy "billed, sync manually" semantics, now with an explicit failure.

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
| Q5 | RADIUS expiry follows the saved billing expiry. **Pinned `Package.php` change approved**: the device runs after the save. |
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
4. **Expiration and FreeRADIUS time zone.** `Expiration` is interpreted in FreeRADIUS local time; run FreeRADIUS in the application time zone.
5. **Sessions across a period boundary.** A session that started before the period start is not counted in the new period.
6. **No real-NAS verification.** CoA and Disconnect are verified against a stub NAS only. MikroTik verification is WP8.
7. **Device errors are now explicit.** Device-level failures raise `ProvisionFailure`. Legacy controllers without try/catch show the generic error page (as they did for PDO errors before). `Package::rechargeUser` reports them and notifies the operator.
8. **Plaintext secrets.** The NAS secret is plaintext at rest (Q10). The customer password is plaintext because Cleartext-Password / CHAP needs it (WP0 §12).
9. **Unchanged by WP4:** the legacy MikroTik device methods (WP8), the `.htaccess_firewall` default (SEC-20, WP10), and the `logs/radius` export of `radpostauth` passwords (WP2 R11). The project site does not enable post-auth logging.
