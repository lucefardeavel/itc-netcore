# ITC NetCore V1.0 release notes

**ISP Management & Billing Platform** · developed and maintained by ITC

| Item | Value |
|---|---|
| Version | **V1.0 Release Candidate** (draft release notes) |
| Date | 2026-10-29 |
| Lab-accepted candidate | `a6b02eba` (branch `lab/release-v1`, Draft PR #6) |
| Release-preparation tree | `release/itc-netcore-v1-final-prep` (final-prep commits after the candidate: `0be31b63`, `228f01c9`, `e8260df7`, `012a9e91`, `b2f7837b`, `9b21ebfe`, `bd1e03cc`, `c550d6b8`, `33624129` and the documentation commit `e23e7540`). Later documentation and QA-fix commits are listed in the handover index rather than here. The release SHA cannot be written inside the commit it identifies: it is recorded in the `RELEASE_SHA` file, in the release archive and in the handover index |
| Release tag | **None.** No tag is created until every release gate passes |
| Lab verdict | `LAB_ACCEPTED_WITH_LIMITATIONS` |
| Release state | `RELEASE_BLOCKED_WITH_EVIDENCE` |

> [!IMPORTANT]
> This is a **Release Candidate. It is not production-accepted and has not been deployed to production.** Target
> staging and the target acceptance tests TA-01 … TA-10 have not been run, the legal review LIC-1 is
> `BLOCKED — COUNSEL REQUIRED`, and owner release decisions are open (see [Release gates](#release-gates)).

## Summary

ITC NetCore V1.0 is the first release of ITC NetCore, developed and maintained by ITC. It adds a
reseller business layer (hierarchy, wallets, ledger, pricing, auditable activation), hardens the FreeRADIUS and MikroTik
integration, adds data and time quotas with separate actual and billable usage, and provides an Arabic-first adaptive
staff interface with an installable staff PWA. Each ISP runs one isolated instance.

## What is new in V1.0

### Resellers and access control

- Reseller hierarchy: Agents and sub-agents to any depth, Sales accounts as leaves, explicit onboarding into the tree.
- Roles SuperAdmin, Admin, Agent, Sales and Report with a reviewed role → capability → scope map; anything not listed is denied. The Report role has no data views in V1.0.
- Server-side permission and data-scope checks on web and API requests.

### Wallets, pricing and finance

- Prepaid reseller wallets with debt limits; provider funding and withdrawal; parent → direct-child transfers.
- Double-entry, insert-only ledger; corrections are compensating entries; statement export to CSV.
- Pricing chain: provider base cost, wholesale per child account, retail price; commissions and provider settlement per activation.
- Reservations before network changes; HELD reservations are never released automatically (audited operator resume or cancel only).
- Review screens before funding and transfers; finance integrity checks (`health.php --finance`).

### Activation

- New, renew, reactivate and change plan through one auditable state machine, with bounded retry, reconciliation, operator recovery and Admin reversal of completed activations.
- Server-calculated quote with a confirmation review; credit re-checked on confirmation; plan changes warn about the paid time that will be lost (no proration) and require an acknowledgement.

### RADIUS and NAS

- FreeRADIUS 3.2 policy: PAP / CHAP, expiration, simultaneous use, rate limits, IP pools.
- RFC 5176 Disconnect / CoA to MikroTik RouterOS; PPPoE session guard over read-only SNMP; PPPoE admission reservation; Hotspot admission slots.
- Accounting history kept intact: the application never deletes or rewrites accounting rows.
- Legacy RouterOS API drivers disabled in the release profile.

### Quotas and usage

- Data and time quotas per billing period. Actual usage and billable usage are separate numbers; an overshoot is not charged and not carried over.
- Enforcement through per-session NAS limits plus the quota enforcer. Enforcement is **bounded, not byte-exact**, and must not be advertised as a byte-exact cap.

### Vouchers and reports

- Admin-generated vouchers with masked codes, exactly-once redemption and review of unproven grants.
- Transaction, activation-history and wallet-statement reports; per-role dashboards.

### Interface

- Arabic-first right-to-left interface with English; adaptive staff shell for desktop, tablet and phone.
- Installable staff PWA with an offline page; nothing is queued or replayed while offline.
- ITC NetCore product identity (name, mark, wordmark, self-hosted Inter and Noto Sans Arabic); an operator's own `CompanyName` keeps priority on operator surfaces.

### Operations

- Migration runner, setup and release preflight, health checks, least-privilege database account generator.
- Backup / restore, operator recovery, NAS onboarding and secret-rotation runbooks; production configuration checklist.
- Backup and point-in-time-recovery tooling under `install/backup/` (added in V1.0): scheduled, encrypted logical backups with manifest and off-host hook, binlog archive, restore into a new database with replay to a time or position, a pre-apply scan that refuses database-level statements, periodic restore test, retention. This implements recovery option 2 (daily backups plus binlog PITR); option 3 (replica with failover) is not implemented. Lab-drilled on MariaDB 10.11 and MySQL 8.0.
- Fresh-install package under `install/fresh/` (added in V1.0): `nuxr-artifact-verify.sh`, `nuxr-fresh-install.sh` (empty-database gate, accounts, base schema, `config.php`, migrations, admission tables, grants, permissions, cron, setup preflight and health) and `nuxr-post-install-verify.sh`, with nginx, PHP-FPM, log-rotation and cron examples. It replaces the web installer for the first installation. Lab-verified on a disposable instance on MariaDB 10.11 and MySQL 8.0.

## Fixes in the final release round

- **F-A1 (R-4):** cron could suspend a subscriber whose paid activation was parked before settlement. Cron now holds only the recharge row the activation writes and retries it at the next run (scoped after an independent review).
- **F-A2:** an adjustment confirmation token could be executed on the provider-funding route, and the reverse. Execution now branches on the validated operation.
- **R-HF-CARRY-01 (status: OPEN, owner decision):** on the release-preparation tree, after the lab-accepted candidate, with tests on both database engines:
  - the legacy PPPoE IP / username availability hints (`autoload/pppoe_ip_used`, `pppoe_username_used`) answer only for the caller's own customers for Agents and Sales accounts (`0be31b63`);
  - `customers/add-post` no longer discloses whether a username exists to tree-scoped staff; the uniqueness checks run last in the same transaction and a refusal rolls everything back (`228f01c9`);
  - the static IP is validated (IPv4) and kept unique across all customers on save, for every role (`e8260df7`);
  - round 3: special IPv4 classes (0/8, 127/8, 169.254/16, 224/4 and 240/4, including `255.255.255.255`) are refused as a static IP on every save path, and a kept legacy IP with edge whitespace is no longer re-judged (`b2f7837b`); the refusal names the rule, and every literal refusal detail has an Arabic text (`9b21ebfe`).

  Round-3 residuals: a legacy row that already holds such a value is still sent as `Framed-IP-Address` (R3-1); the VPN auto-assignment and the reprovision `revert()` write a static IP without the identity lock or the uniqueness check (R3-2).

  Still open for the owner: low-severity unauthenticated enumeration through the forgot-password / forgot-username and self-registration pages, and accepted-level residuals (a fully valid add by scoped staff still shows that some identity collides; no rate limit on refused creates; the static-IP check does not cover NAS, gateway or pool addresses; existing duplicate or invalid static IPs are not migrated).
- **Preflight G-4 (`bd1e03cc`):** a runtime database account that cannot connect is reported as `FAIL db.connect` (cause and MySQL error number only) with exit 4, instead of a PHP fatal error with exit 255.
- **Preflight G-1 (`33624129`):** `radius_checks` FAILs when its reference definitions `install/freeradius/*.sql` are missing or unreadable, instead of passing with an empty expected set.
- **Deployment documents (`c550d6b8`):** the runbook keeps `install/freeradius/`, checklist I1 / I2 run `cron.php` and `cron_reminder.php` from `system/`, and `quota.php --every` is at least 5.
- **UI and Arabic labels (`012a9e91`):** the customer-portal header in product identity mode shows the mark and the product name on one line; distinct Arabic labels for menu entries that shared one (see *Behaviour changes*); Arabic titles for the Voucher and FreeRADIUS settings sections; Arabic texts for 13 refusal messages, including the new identity and static-IP refusals; default plan titles stored in English are shown translated; the voucher list button reads "Add Vouchers". No brand asset, font or colour changed.
- Documentation and tooling corrections: target evaluator NAS clock limit, Hotspot admission audit table insert-only, NAS reload collation step, reverse-proxy checklist row, NAS type `mikrotik_snmp` in the onboarding guide, CI actions pinned to SHAs, staging-package gaps (SNMP flow, CoA teardown, second NAS, TA-01 checks).

## Behaviour changes operators should know

- A customer's **static IP** must be a valid, non-reserved IPv4 address (or empty) and **unique across all customers**, for every role including Admin. Special IPv4 classes (0/8, 127/8, 169.254/16, 224/4, 240/4) are refused (`b2f7837b`). A stored legacy value is not re-checked until it is changed.
- **Update check off by default:** the fresh installer stores `new_version_notify = disable`, so the legacy dashboard update check (which contacted `raw.githubusercontent.com`) does not run. Maintenance release V1.0.1 removes it.
- **Fresh-install gate:** the installer also refuses a target database on which another account already holds database, table, column or routine privileges. Revoke them first.
- **Backups:** an off-host copy that failed is retried automatically by the next run (one marker per item in `$BACKUP_DIR/.offhost/`); the run keeps exiting 5 while such a backlog remains, so alert on any exit 5. The encrypted manifest is mandatory at restore: a set without it is refused. With `age` encryption, keep the recipients file private and the backup store write-protected.
- **Reconcile CLI:** `reconcile.php cancel`, `rollback` and `balance-resolve` require `--operator=<name> --reason=<text>` (the reason at least 10 characters), and both are recorded in the audit trail. `cancel` also refuses an activation updated less than 300 seconds ago.
- **Cron:** `system/cron.php` resolves `init.php` from its own directory, so it works from any working directory. The documented form stays `cd <app>/system && php cron.php`.
- When a username, PPPoE username or static IP is already taken, **Agents and Sales see one generic refusal**; they are not told which value or by whom. Admins keep the specific reason.
- New distinct Arabic labels: Refill Customer «شحن العميل بقسيمة», Recharge Customer «إعادة شحن اشتراك العميل», Refill Account «شحن الحساب بقسيمة», Coupons «الكوبونات», Vouchers «القسائم», the "Add Vouchers" button «إضافة قسائم».
- The first installation uses the `install/fresh/` command-line package instead of the web installer.

The static-IP and generic-refusal changes are to be acknowledged by the owner before R-HF-CARRY-01 can be closed.

## Verification (isolated lab only)

The results below come from an isolated lab (MariaDB 10.11 and MySQL 8.0, FreeRADIUS 3.2.8, RouterOS CHR 7.24.5, a
16 GB workstation): the first five rows on candidate `a6b02eba`, the others on the final-prep work of 2026-10-29. No
result was obtained on a target or production system, and the lab does not prove production capacity.

| Stage | Result |
|---|---|
| Full test harness, MariaDB / MySQL | 1680 / 1680 each, 0 failures (132 CHR-only tests covered by the CHR window) |
| Real-router window, RouterOS CHR | 145 pass, 1 blocked per engine; the blocked two-NAS test later passed in the lab on a second CHR |
| Browser matrix | 208 pages, 0 failures, 0 console errors (7 roles × AR / EN × desktop / mobile × light / dark) |
| Backup / restore drill, bounded load, accounting load | Passed on both engines |
| Lab deployment and rollback rehearsal | Health passed; switch in about 0.05 s each way |
| Two-NAS test on a second local CHR (CHR2) | Passed on both engines (lab only; staging case X-1 is still required) |
| R-HF-CARRY-01 rounds 1–3, UI / i18n fixes, preflight fixes G-4 and G-1 | Targeted dual-engine harness runs (related classes plus every unit and guard test) passed; the browser matrix and CHR suites were not re-run on them |
| Fresh-install package (disposable instance) | Install exit 0, idempotent second run, post-install verify 67 PASS / 0 WARN / 0 FAIL, 8 / 8 refusal cases per engine. The proof is run on every release candidate on MariaDB 10.11 and MySQL 8.0; the result for this release is in the handover package (`08_Acceptance_Handover/FRESH_INSTALL_PROOF`) |
| Backup and PITR drill (71 MiB synthetic database) | Dropped database restored to the chosen time with an identical copy and 0 rows lost; host-loss window about the archive interval; tool run 13–31 s; target backup chain not tested (TA-08) |

## Installation and upgrade notes

- The first installation of V1.0 is a **fresh install on a new, empty database**. Migration from an existing legacy database is a separate future path and is not part of the first installation.
- Requirements: PHP 8.2, MariaDB 10.11 or MySQL 8.0 (`REPEATABLE-READ`, UTC), FreeRADIUS 3.2.x, MikroTik RouterOS 7.15 or later with NAS type `mikrotik_snmp`, NTP on every host and NAS.
- Follow the installation guide in `docs/guides/install/` (manual first-run artifact check, artifact verify, `install/fresh/nuxr-fresh-install.sh`, post-install verify) and the MikroTik / FreeRADIUS guide in `docs/guides/radius-mikrotik/`.
- Keep `install/freeradius/` in the document root, read-only and blocked by the web server: the preflight reads its expected CHECK expressions from it and FAILs without them (`33624129`). Use the default `--installer=strip`; `--installer=remove` makes the installer fail at the setup preflight (phase 12, exit 4).
- **First-run artifact check, independent of the verifier inside the tarball:** in the folder that holds the tarball, `sha256sum -c SHA256SUMS`, then `tar -xzOf itc-netcore-v1.0-<sha12>.tar.gz itc-netcore-v1.0-<sha12>/RELEASE_SHA` must print exactly the 40-character release SHA that ITC gives the customer out-of-band and that the handover index lists. `nuxr-artifact-verify.sh` is also delivered standalone next to the tarball (handover folder `07_Release_Artifacts`) for the first full verification.
- Run `cron.php` and `cron_reminder.php` with the documented form from `system/` (`cd system && php cron.php`); the `--install-cron` file `/etc/cron.d/itc-netcore` already does this.
- Set up `install/backup/` before go-live and agree a real off-host target with the ISP; no destination is built in.
- A production deployment needs the owner's explicit release authorization.

## Known limitations (selection)

The complete list is in the supported-capabilities and known-limitations guide in `docs/guides/operations/`.

- No database or RADIUS high availability, message broker, worker queue or shared multi-tenant database.
- Quota enforcement is bounded, not byte-exact; with the enforcer or a link down, only router limits apply.
- A2-R (PPPoE admission under SQL pool exhaustion): **MITIGATED — EFFECT UNVERIFIED**; not closed.
- O-DL: under a heavy stampede on one wallet, MariaDB can refuse an activation after bounded retries (about 1 in 400–1800 operations in the lab; 0 on MySQL); the reservation is held for an operator.
- Session-end selection after a plan change depends on the NAS clock (NTP required; preflight and health checks warn).
- Agents and Sales accounts cannot edit, deactivate or delete subscribers after creation, or generate vouchers.
- No automatic renewal for reseller-owned subscribers, no proration, no automatic customer refund on reversal, no payment-gateway plugin.
- No built-in login throttling; rate-limit sign-in at the HTTPS front.
- RTO 4 h / RPO 15 min are provisional engineering design targets, not owner-approved service levels. The backup tooling implements option 2 (binlog PITR); option 3 (replica) is not implemented.
- R-HF-CARRY-01 stays open: low unauthenticated enumeration (forgot-password / forgot-username, self-registration) and accepted-level residuals remain for the owner.

<a id="release-gates"></a>

## Release gates still open

| Gate | State |
|---|---|
| LIC-1 legal review | `BLOCKED — COUNSEL REQUIRED` (licence version statement, mPDF / Smarty combination, credits, test image, Terms and Privacy templates, data protection) |
| Target staging authorization and TA-01 … TA-10, X-1 … X-5 | Not run |
| Owner decisions | A2-R C7 residual risk, O-DL policy confirmation, RTO / RPO values and the recovery option, R-HF-CARRY-01 (including acknowledgement of the static-IP behaviour changes) and further owner-decision rows |
| WP9 / WP10 acceptance, G8 | Pending / open |
| Merge, tag `v1.0.0`, production deployment | Not authorized |

## Support

Support terms are defined per customer agreement.

## Credits

ITC NetCore is developed and maintained by ITC. Copyright and licence notices are kept as the licence requires.
Third-party components and their licences are listed in `docs/THIRD_PARTY_LICENSES.md`; see also `NOTICE` and `LICENSE`. The licence version and related
questions are open with counsel (LIC-1); these notes do not decide them.
