# Operator recovery runbook (WP9-OPS)

**Scope:** recovering every state that the application deliberately leaves for an operator, instead of guessing.

**Audience:** the operator of ONE ISP instance, with shell access to the application host and the protected DB client file.

**Rules for every procedure:**
- Nothing in these procedures deletes financial history, RADIUS accounting (`radacct`) or audit records.
- Every decision runs exactly once and is audited.
- A refusal changes nothing.
- Never use a procedure "to see what happens". Detect, collect the evidence named here, then decide.
- Credentials are never put on a command line. The application CLI reads `config.php`; the SQL client reads a protected `--defaults-extra-file`.

All commands run from the application root:

```bash
php system/reseller/reconcile.php <command> ...
```

Exit codes:

| Code | Meaning |
|---|---|
| 0 | OK |
| 2 | refused (state, gate or not found; nothing changed) |
| 3 | failed (error reference in the server log) |
| 4 | issues found / external state ambiguous |
| 5 | usage error |

## 0. Daily detective checks (read-only)

| Command | Finds | Healthy result |
|---|---|---|
| `reconcile.php drift` | `PERIOD_NOT_BACKED`, `RADIUS_DRIFT`, `PAYMENT_FOR_RESELLER_OWNED`, `VOUCHER_CLAIMED`, `REPROVISION_UNCONFIRMED`, `OLD_SESSION_UNCONFIRMED`, `BALANCE_HELD` | exit 0, no line |
| `reconcile.php finance-check` | ledger continuity, wallet = ledger, available >= 0, transfer legs, reversal links, reservation <-> debit, settlement vs sealed snapshot, activation timelines / reservations / legacy records | `{"finance_check":"CONSISTENT"}`, exit 0 |
| `reconcile.php stale --seconds=300` | activations not moving for 5 minutes | no line |
| `reconcile.php admission-pending` | PPPoE admission claims left PENDING | no line |
| `reconcile.php voucher-review` | vouchers left CLAIMED (review) | no line |
| `reconcile.php balance-held` | interrupted legacy balance operations | no line |

**Continuous monitoring (WP10):** `php system/reseller/health.php --json` every 5 minutes, and with `--finance` hourly.
- Exit codes: 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN.
- It reports counts and ages only. It covers:
  - the database contract (REPEATABLE-READ, UTC, clock skew);
  - schema readiness;
  - stale and reconciliation-required activations;
  - the oldest ACTIVE reservation (N-09: reservations never expire on their own);
  - PPPoE / Hotspot admission claims PENDING over 10 minutes;
  - CLAIMED vouchers;
  - HELD balance operations;
  - accounting freshness;
  - unconfirmed Disconnect / CoA outcomes;
  - finance integrity (`--finance`).

  Each WARNING / CRITICAL line names the command of this runbook that lists the records.
- Alerting rules: `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` §J.

**`finance-check` exit 4 is a security incident.** It means a write happened outside the finance services: tampering, a plugin, or a bug.
- Do not "fix" the numbers.
- Preserve a database snapshot and the logs.
- Escalate.
- The WP10 least-privilege accounts make it impossible for the application account to rewrite the ledger.

## 1. Activations (RECONCILIATION_REQUIRED, stale FUNDS_RESERVED / RETRY_PENDING / VERIFIED, ROLLBACK_PENDING, REVERSAL_PENDING)

The web Operations Center (Admin) shows the allowed actions. The CLI equivalents are below.

| State | Meaning | Action |
|---|---|---|
| `RECONCILIATION_REQUIRED` | provisioning or settlement outcome unknown; funds reserved, never released by time | `reconcile <ACT>`:<br>• delivery verified → settle once;<br>• RADIUS = target → settle;<br>• RADIUS = prior → FAILED and released;<br>• anything else → `EXTERNAL_STATE_AMBIGUOUS` (exit 4): inspect RADIUS, then `rollback <ACT>` or leave held |
| `FUNDS_RESERVED` / `RETRY_PENDING` (stale ≥ 300 s) | stopped before / between RADIUS attempts | `resume <ACT>` (continues) or `cancel <ACT>` (release, CANCELLED). The CLI refuses a live (non-stale) activation<br>**Also left by a deadlock refusal:** under heavy concurrent activity on ONE wallet an activation can be refused with `CONCURRENCY_RETRY` ("please try again") after the bounded deadlock retries; if that happens after the reservation, the activation stays `FUNDS_RESERVED` with an ACTIVE reservation - funds held, nothing charged or granted (measured in the lab: about 1 run in 4 of a 96-operation, 12-wide stampede on one wallet, MariaDB; `dev/wp10/StagingLoadTest.php` proves the state and the clean `cancel`). A new activation for the same customer is refused (`ACTIVATION_IN_PROGRESS`) until the held one is resumed or cancelled |
| `VERIFIED` (stale) | delivered, settlement not yet run | `resume <ACT>` |
| `ROLLBACK_PENDING` | restore of the prior RADIUS state not confirmed | `rollback <ACT>` again; funds stay held until the restore is verified |
| `REVERSAL_PENDING` | reversal started, not finished | Admin "Reverse" again (idempotent continuation) |

## 2. Vouchers under review (CLAIMED / CLAIMED_REVIEW:NOT_CONFIRMED | DEVICE_FAILED | DEVICE_UNKNOWN | NO_SERVICE)

A voucher stays `CLAIMED` when its grant was not proven. It is never redeemable again until the operator decides.

1. List the vouchers under review:

   ```bash
   php system/reseller/reconcile.php voucher-review
   ```

   This gives `voucher_id`, plan, device, and the grant evidence:
   - recharge rows;
   - recharge rows that are on;
   - whether the voucher is voucher-only;
   - invoice rows;
   - RADIUS rows (`-1` means RADIUS could not be read).

   The code is never printed.
2. For `DEVICE_UNKNOWN`, check the NAS / RADIUS first: is the subscriber served?
3. Decide.

| Decision | Allowed only when | Effect |
|---|---|---|
| `confirm` | a recharge row is `on` and its invoice exists; for a FreeRADIUS plan, the identity's RADIUS rows exist | voucher used (GRANTED); service stays |
| `deny` | voucher-only grant with a recharge row | RADIUS identity suspended (random password, nothing deleted); recharge `off`; voucher used; invoice kept; refund outside the application |
| `release` | nothing of the grant exists (no recharge, no invoice, RADIUS rows = 0) | voucher redeemable again |

To apply a decision:

```bash
php system/reseller/reconcile.php voucher-resolve <voucher_id> <confirm|deny|release> --operator=<name> --reason="<why>"
```

## 3. PPPoE admission claims left PENDING (W8-4, AV-1)

A PENDING claim refuses new logins of that subscriber. An Access-Accept that was already sent cannot be cancelled from the RADIUS side. Never release a claim without NAS-side proof.

1. List the claims:

   ```bash
   php system/reseller/reconcile.php admission-pending
   ```

   This gives the key (hex), token, NAS, age, and `releasable`. `releasable` means older than 600 s and no open accounting row.
2. Collect NAS-side proof that the attempt cannot become a session, for example:
   - the NAS rebooted after `created_at`;
   - the NAS log shows the attempt failed.

   An empty active list alone is not proof.
3. Release the claim:

   ```bash
   php system/reseller/reconcile.php admission-release <key_hex> <token> --operator=<name> --reason="<why>" --evidence="<NAS, time, log line / reboot record>"
   ```

   - Gates: still PENDING with that token; ≥ 600 s old; no open accounting row of the session name.
   - The audit records the database-attested account (`[db_account=… connection=…]`).

The manual SQL equivalent is `install/freeradius/nuxr_admission_release.sql` (`install/freeradius/README.md` "Operator recovery").

ACTIVE claims are never released by hand. The A1 NAS verification ends them when the session is proven gone.

## 4. Re-provision not confirmed (REPROVISION_UNCONFIRMED) and old sessions (OLD_SESSION_UNCONFIRMED)

- **`REPROVISION_UNCONFIRMED`:** a password, identity edit or sync changed the device, but the result was not verified.
  1. Open the customer as Admin and compare the RADIUS rows with the customer record.
  2. Run "Sync" (ADMIN_SYNC) once; it re-applies the active plan.

  The billing period is unchanged by design.
- **`OLD_SESSION_UNCONFIRMED`:** after a rename, a session of the OLD name may still run. This applies to reseller-owned renames, and since WP9 W8-17 also to the Admin rename of an unassigned legacy customer (audit operation `LEGACY_RENAME`).
  1. Disconnect it on the NAS (or wait for its Session-Timeout).
  2. Check `radacct` for an open row of the old name.

  The drift line disappears once no open row of the old name remains.

## 4a. A plan change or renewal whose running session kept the old policy (SESSION_POLICY_STALE, lock gate P1 / P2)

A CHANGE_PLAN, REACTIVATE or RENEW (owner RENEWAL_OPTION_B_APPROVED) ends the running session of the previous period
(Disconnect) and confirms the end (its Accounting-Stop, or NAK 503) so that the next login gets the new policy. The
outcome is recorded on the activation (annotation `confirmed: true|false`). If the
Disconnect was not confirmed (UNKNOWN / FAILED) and a session that started before the change is still open, that
session runs with the previous plan's rate until it ends (bounded by the previous period's Session-Timeout).
- Detect: `reconcile.php drift` -> `SESSION_POLICY_STALE` with the activation reference.
- Act: `php system/reseller/reconcile.php session-end <ACT>` (re-sends the Disconnect; annotated on the activation),
  or disconnect the session on the NAS. Never automatic; repeating it is harmless (an ended session is not found).
- The finding clears when the old session's accounting Stop arrives.
- RouterOS answers a Disconnect for a session it no longer has with NAK 406 (unconfirmed). If the NAS shows no such
  session (`/ppp active`, `/ip hotspot active`) the Accounting-Stop was lost: the accounting row stays open (it is never
  closed or deleted by PHPNuxBill) and the finding stays until FreeRADIUS receives a Stop; record the NAS evidence on
  the activation. The renewal / settlement is never undone or repeated.

## 4c. NAS clock ahead (health `nas_clock`, preflight `time.nas_clock`; RAD-3a)

**Signal:** health `nas_clock` WARNING, or the preflight `time.nas_clock` WARN / FAIL naming the NAS (`<nas-ip> +<seconds>s`).
**Why it matters:** the session-end cut-off of a plan change / renewal allows the NAS clock 10 s; a NAS further ahead can leave an
old session running with the previous plan's policy until it reconnects.

1. Identify the NAS from the preflight line (read-only): `php system/reseller/preflight.php | grep time.nas_clock`.
2. On the NAS: `/system clock print` against `date -u` on the FreeRADIUS host; `/system ntp client print` (enabled, servers
   reachable, synchronised). Repair NTP (two internal servers by IP); never set the clock by hand without NTP.
3. Re-check after the next Interim: `preflight.php` `time.nas_clock` PASS and health `nas_clock` OK.
4. Sessions that changed plan while the clock was ahead are **not** reported automatically (that is the RAD-3a residual): for each
   subscriber on that NAS whose plan changed or renewed during the period, end the old session through the supported Disconnect of
   section 4a; the next login gets the new policy. Never edit `radacct` or the reservation tables by hand.
5. Record the NAS, the measured offset and the repair in the operations log.

## 4b. Data / time quota (V1, `quota.php`)

- `health.php` `quota_enforcer` CRITICAL: the enforcer cron (`php system/reseller/quota.php enforce --loop=55`, every
  minute) is not running. Restart it; until then only the router's own limits apply (Hotspot: per-session cap; PPPoE:
  per direction).
- `quota_enforcer` WARNING: `php system/reseller/quota.php report` lists the over-quota subscribers and their open
  sessions. The enforcer re-sends the Disconnect every 60 s (audit `quota.enforced`); a NAS that does not answer
  is a NAS / CoA problem (`install/freeradius/README.md` "Disconnect / CoA"). Never edit `radacct` or the quota rows.
- A subscriber refused with "Data quota exhausted or unavailable" has used the period quota, or the RADIUS database
  query failed (fail closed). "Quota unavailable" means a `Max-Data` / `Max-All-Session` row in `radcheck` is malformed
  or duplicated (written outside PHPNuxBill): re-save the customer's plan through the application, never by hand.

## 5. Legacy balance operations (HELD)

1. List them with `balance-held`.
2. Prove from the gateway and RADIUS whether the grant happened.
3. Resolve with `balance-resolve <journal id> completed` (the service was granted) or `refund` (credit back, exactly once).

## 6. Orphaned billing / service records

| Drift kind | Meaning | Action |
|---|---|---|
| `PERIOD_NOT_BACKED` | a reseller-owned customer has an active period without an activation | investigate (plugin, direct DB write, restore); deactivate the period through the customer UI or create the correct activation; never edit the ledger |
| `RADIUS_DRIFT` | RADIUS differs from the last activation's target | ADMIN_SYNC (re-applies the target); if refused, inspect for an external writer (plugin / other DB account) |
| `PAYMENT_FOR_RESELLER_OWNED` | a legacy payment gateway charged a reseller-owned customer | refund through the gateway; no service is granted by the legacy path |

## 7. Cron and renewals

The cron expiry and every service-period writer (activation, re-provision) share a per-customer service lock. A customer whose renewal is in flight is skipped and retried at the next cron run (`SKIPPED (service busy...)`). A period renewed meanwhile is reported `RENEWED meanwhile` and not expired. No action is needed unless the same customer is skipped on many consecutive runs; then check `stale`.

## 8. What is never done

- Deleting rows from `tbl_manager_ledger`, `tbl_manager_transfers`, `tbl_transactions`, `radacct` or any audit table.
- Running SQL against the ledger or wallets.
- Releasing an ACTIVE admission claim.
- Retrying an UNKNOWN network outcome blindly.
- Load, pool or fault experiments against FreeRADIUS SQL.
