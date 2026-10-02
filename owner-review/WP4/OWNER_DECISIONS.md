# WP4 Owner Review Decision and Owner Decisions

Recorded 2026-10-02 from the owner's explicit decision in the implementation session. The owner wrote "Owner review is acceptable", then chose the status **ACCEPTED_WITH_NOTES** and "Local only (no push)".

This file records that decision and the WP4 owner decisions given during planning and implementation. The notes and binding inputs below are carried forward from the accepted WP4 documentation (`docs/WP4_RADIUS_MODEL.md`, `owner-review/WP4/REPORT.md`); they are not new owner statements.

## WP4 acceptance
- Owner status: **ACCEPTED_WITH_NOTES**
- Accepted HEAD: `58e3e45898f813454e9232a9e535ad36b32740e0` (branch `wp4/radius-hardening`, local)
- Base: `f2269a069420912b63731f98308d63f93fc1dd30` (WP3B accepted at `460714de` + owner metadata)
- `feat/reseller-system-v1` fast-forwarded to `58e3e458` (`--ff-only`), plus this metadata commit. Nothing is pushed.
- No R0007. R0001–R0006 are byte-identical. The FreeRADIUS schema `install/radius.sql` is unchanged.

## Owner decisions applied in WP4
| # | Decision |
|---|---|
| Q1 | FreeRADIUS 3.2.x test image in the isolated test project, loopback only, stub NAS. Used: `freeradius/freeradius-server:3.2.8` pinned by digest |
| Q2 | C16 option (a): project-owned dictionary and sqlcounter configuration |
| Q3 | `radius.php` authenticated by a shared secret plus a source allowlist (the FreeRADIUS servers); parameterized SQL; off by default |
| Q4 | Fix the CHAP bypass after a test proves it (proven: old code right=false, wrong=true) |
| Q5 | RADIUS expiry follows the saved billing expiry. Approved the pinned `Package.php` change (device calls after the recharge save) and the matching WP3A hash-pin update |
| Q6–Q12 | As recommended in the plan: native Disconnect/CoA with UNKNOWN surfaced; PPPoE key `pppoe_username`; stale sessions reported only; local `rad_acct` kept and fixed; NAS secret write-only in the UI; C17 fix applied globally; test autoloader includes `Reseller\Radius` |

## Accepted test evidence
- 539 tests / 43804 assertions per engine (MariaDB 10.11.19, MySQL 8.0.46, PHP 8.2.34); the registry matches.
- Testdox 1078/0. The clean MySQL rerun followed one AppServer harness flake.
- WP4 suites 69 tests / 567 assertions.
- Route matrix 1442/1164/278, unchanged.
- Lint 385/385; manifest 42/42.
- Details: `owner-review/WP4/REPORT.md`.

## Notes (accepted residuals, from the WP4 report §I / model §12)
1. Pre-WP4 PPPoE RADIUS rows keyed by `username` remain until the customer is re-provisioned or removed.
2. Accounting history deleted before WP4 cannot be recovered.
3. rlm_rest deployments must configure `$radius_rest_secret` (deliberate breaking change for SEC-01).
4. `Expiration` is interpreted in FreeRADIUS local time: run FreeRADIUS in the application time zone.
5. A session that started before a billing period is not counted in that period's quota.
6. No real NAS / MikroTik verification. CoA and Disconnect were tested against a stub NAS only (WP8).
7. Device failures are now explicit `ProvisionFailure` exceptions on legacy paths.
8. The NAS secret is plaintext at rest (FreeRADIUS needs it), and so is the customer password (Cleartext-Password / CHAP).
9. Unchanged by WP4: MikroTik device methods (WP8), the `.htaccess_firewall` default (SEC-20, WP10), the `radpostauth` password export (WP2 R11).
10. Test harness: the AppServer port-reuse flake (seen three times) and the `CliOnlyTest` healthcheck race. A harness fix needs owner approval.

## Binding inputs carried forward
- **WP5A:** follow `docs/WP4_RADIUS_MODEL.md` §10:
  - provision through `RadiusPolicyBuilder` and `RadiusProvisioner`, outside any DB transaction, with the activation's authoritative expiry;
  - store `ProvisionResult::status` in `provisioning_result`;
  - UNKNOWN → reconciliation, never a blind retry;
  - never write `radacct`, `rad_acct` or the RADIUS tables directly.

  Also follow the WP3B §9 contract.
- **WP8:** real FreeRADIUS + MikroTik NAS verification of CoA / Disconnect, rate, pool, static IP and expiry.
- **WP9:** red-team the rlm_rest endpoint and CoA injection.
- **WP10:** deployment of the FreeRADIUS configuration and time zone, least-privilege database accounts, and the `.htaccess_firewall` default.
