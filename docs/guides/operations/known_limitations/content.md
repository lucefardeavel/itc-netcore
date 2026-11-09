# About this document

This document states what ITC NetCore V1.0 supports and what it does not, as verified against the release source tree (`release/itc-netcore-v1-final-prep`: documentation baseline `e02d784b` plus the final-prep commits `0be31b63`, `228f01c9`, `e8260df7`, `012a9e91`, `b2f7837b`, `9b21ebfe`, `bd1e03cc`, `c550d6b8`, `33624129` and the documentation commit `e23e7540`; the release SHA is recorded at build), the final release report of 2026-10-29 and the lab reports of the same day on backup / PITR, the fresh-install package, R-HF-CARRY-01, the UI / i18n fixes and the two-NAS test. It **closes nothing and accepts no risk on the owner's behalf**. Where a limitation depends on an open owner decision, the decision is named.

> [!WARNING]
> Release Candidate, not yet production-accepted. Release state `RELEASE_BLOCKED_WITH_EVIDENCE`; lab verdict `LAB_ACCEPTED_WITH_LIMITATIONS`.

# 1. Release status

| Gate | State |
|---|---|
| Lab acceptance | `LAB_ACCEPTED_WITH_LIMITATIONS` on candidate `a6b02eba` (the release tree adds documentation-only commit `e02d784b`) |
| Final-prep fixes after the candidate | R-HF-CARRY-01 rounds 1–3 (`0be31b63`, `228f01c9`, `e8260df7`, `b2f7837b`), the UI / i18n fixes (`012a9e91`, `9b21ebfe`), the preflight fixes G-4 (`bd1e03cc`) and G-1 (`33624129`), and the deployment-document corrections (`c550d6b8`): each verified by targeted dual-engine harness runs (related classes plus every unit and guard test). The browser matrix and the CHR suites were **not** re-run on them |
| Target acceptance TA-01 … TA-10, closure cases X-1 … X-5 | **NOT_RUN** — no authorized staging target exists |
| Legal review LIC-1 | **`BLOCKED — COUNSEL REQUIRED`** (GPL version contradiction between LICENSE, README and `composer.json`; mPDF / Smarty licence combination; credits; the Lena test image; data protection and telecom retention) |
| WP9 / WP10 acceptance ledger rows | **PENDING** |
| G8 (merge, tag, production release) | **OPEN** |
| Production deployment | **Not authorized** |
| Owner decisions still open | A2-R C7 residual-risk acceptance (C5 training sign-off open); O-DL policy confirmation (record conflict); RTO / RPO values; R-HF-CARRY-01; WP9 / WP10 acceptance |
| Deployment type of the first instance | **Fresh install with an empty database** (owner decision). Migration of an existing legacy database is a separate future path |

# 2. Evidence level: local lab only

| Area | What was proven | Where it was proven |
|---|---|---|
| Test harness | 1680 / 1680 tests per engine, 0 failures (132 CHR-only tests covered by the CHR window) | Lab, MariaDB 10.11.19 and MySQL 8.0.46 |
| Real NAS behaviour | 145 pass, 1 blocked per engine; the blocked two-NAS test (`RenameOldSessionLabTest`, Hotspot sessions on two NAS) then **passed on both engines** against a second local CHR (CHR2) | Isolated **RouterOS CHR 7.24.5** VMs on one lab workstation — not the production NAS model; staging case X-1 is still required |
| FreeRADIUS | 3.2.8 (image mode); the session guard is validated with **3.2.8 only** | Lab |
| Backup / restore drill, bounded load, deadlock study, accounting load | OK on both engines | Lab (16 GB workstation; does not prove target capacity) |
| Backup and point-in-time recovery tooling (`install/backup/`) | Logical error (dropped database): restored copy **identical** to the state at the chosen time, 0 rows lost; host loss: loss window about the archive interval (51.4 s at 60 s, about 21 s at 30 s); tool run 13–31 s; every safety refusal and negative test as designed | Lab, synthetic 71 MiB database in disposable containers (MariaDB 10.11.19, MySQL 8.0.46); off-host copy simulated by a local directory. Not a target measurement (TA-08 `NOT_RUN`) |
| Fresh-install package (`install/fresh/`) | Artifact verify, fresh install and post-install verify: install exit 0, idempotent second run, 67 / 0 / 0 checks; 8 / 8 refusal cases per engine (non-empty or unknown database, wrong collation, existing accounts, unsafe env file, stray `config.php`) with nothing changed | Lab, disposable Docker instance (MariaDB 10.11 and MySQL 8.0, self-signed certificate, no FreeRADIUS or NAS). The proof is run on every release candidate on MariaDB 10.11 and MySQL 8.0; the result for this release is in the handover package (`08_Acceptance_Handover/FRESH_INSTALL_PROOF`). Not a target acceptance (TA-01 … TA-10 `NOT_RUN`) |
| Browser matrix | 208 pages, 0 failures (7 roles × AR/EN × desktop/mobile × light/dark) | Lab |

> [!NOTE]
> The local two-NAS pass does not replace staging case X-1. No target NAS model, RouterOS version, database host or capacity figure is proven.

<!-- pagebreak -->

# 3. Supported capabilities (V1.0)

| Capability | Scope in V1.0 |
|---|---|
| Deployment | **One isolated instance per ISP** (Option A): own database, FreeRADIUS and NAS set. No shared-database multi-tenancy (`tenant_id`) |
| Roles | SuperAdmin, Admin, Agent (reseller / sub-reseller), Sales, Report; customer portal |
| Reseller hierarchy | Agents under Agents to any depth; Sales as leaves; server-side scope checks |
| Wallets and ledger | Prepaid wallets, debt limits, transfers from a parent to its direct children, double-entry insert-only ledger, commissions, provider settlement, reservations, reversals, integrity checks |
| Activation | NEW / RENEW / REACTIVATE / CHANGE_PLAN with an auditable state machine, retry, reconciliation, operator recovery, reversal of completed activations |
| Plans for reseller activation | RADIUS device, Hotspot and PPPoE |
| RADIUS / NAS | FreeRADIUS 3.2 policy (PAP / CHAP, expiration, simultaneous use, rate limits, pools); RFC 5176 Disconnect / CoA to MikroTik; PPPoE session guard (SNMP); PPPoE admission reservation; Hotspot admission slots |
| Quotas | Data and time quotas per billing period (§6) |
| Vouchers | Generated by Admins; masked codes; exactly-once redemption; review of unproven grants |
| Operations | Migration runner, health checks, release preflight, backup / restore and recovery runbooks, least-privilege accounts |
| Interface | Arabic-first RTL and English; adaptive staff UI (desktop, tablet, phone); installable staff PWA (offline page; nothing queued) |

# 4. Platform and architecture limits

| Limitation | Source |
|---|---|
| No database or RADIUS high availability; no HA claim before an owner-approved HA requirement and failover drill (REL-06) | `CLAUDE.md`; owner lock |
| No Redis, message broker, outbox / worker, microservices or Kubernetes | `CLAUDE.md` |
| No advanced fair-usage policy or automatic next-profile engine | `CLAUDE.md` |
| Tested engines: MariaDB 10.11 and MySQL 8.0 only; `REPEATABLE-READ` and UTC mandatory | `README.md`; checklist A2–A4 |
| Global tree-lock serialization of hierarchy-dependent finance writes (a throughput limit) | WP3A §8 |
| Legacy MikroTik-API drivers and the rlm_rest device are disabled in the release profile | `ReleaseProfile`; preflight `profile` |
| No monitoring product integrated; alerting relies on CLI exit codes / JSON | `monitoring_alerting_guide` |
| Only the migration-runner variants `V1_FRESH_INSTALL`, `V2_FRESH_INSTALL_UPDATED`, `V3_LONGLIVED_2023_UPDATED` are recognised core schemas | `system/reseller/README.md` |
| Backup tooling now exists in `install/backup/` (scheduled encrypted backups, binlog archive, point-in-time restore, periodic restore test, retention, off-host hook): recovery **option 2** (daily backups plus binlog PITR) is implemented and lab-drilled. Still open: a real off-host target (no destination is built in), monitoring of the archive age, the owner's choice of interval, and the measurement on target hardware (TA-08). **Option 3** (replica with failover) is **not** implemented | `backup_restore_guide`; `disaster_recovery_guide` |
| Not drilled in the lab: a separate RADIUS database, `age` / `gpg` / `zstd`, local binlog mode, `gtid_mode=ON`, restore onto a fresh server, the overwrite path, and the systemd / cron schedules (syntax only); no finance activity was replayed across a PITR boundary | `backup_restore_guide` |
| The first installation is done with the `install/fresh/` command-line package; the web installer is not used (it has no empty-database check and its SQL drops tables). `install/freeradius/` must stay in the document root, read-only and blocked by the web server: preflight `radius_checks` reads its expected CHECK expressions from it and FAILs when they are missing (G-1, fixed in `33624129`). `--installer=remove` is therefore not usable: the installer would stop at the setup preflight (phase 12, exit 4); keep the default `--installer=strip` | `install_guide_en` |
| RTO 4 h / RPO 15 min remain **provisional engineering design targets, not owner-approved SLAs**. The lab drill does not change that | `disaster_recovery_guide` |

# 5. Classified residual risks

## 5.1 A2-R — PPPoE admission under SQL pool exhaustion

**Status: MITIGATED — EFFECT UNVERIFIED.** It is not fixed and must never be described as closed.

| ID | Residual | State |
|---|---|---|
| R-A2R-1 | An emptied SQL expansion under pool starvation might produce a silent wrong admission or duplicate session (severity High, likelihood unknown) | Layers L1–L4 turn the emptied value into a refusal; not shown to hold under real starvation. The reproduction is forbidden and was never run |
| R-A2R-2 | Starvation costs availability (refusals, orphan PENDING claims) | Accepted by design with audited manual recovery and no automatic release |
| R-A2R-3 | Another statement path could be affected the same way (Medium) | Open; not verifiable in the lab |
| R-A2R-4 | The layers depend on CHECK enforcement and strict `sql_mode` | Deployment gate: preflight FAILs otherwise |
| R-A2R-6 | The production database host differs from the lab | Target-only (TA-09) |

Launch conditions recorded for the owner: C1 preflight on the target; C2 FreeRADIUS threaded and pools sized (`pool.max >= max_servers`, `max_connections >= Σ pool.max + application + margin`); C3 bounded load test; C4 alerts fired and cleared; C5 operator training sign-off; C6 staged rollout; C7 the owner's written risk classification. None is evidenced on a target.

## 5.2 O-DL — concurrency refusal on one wallet (MariaDB)

- Under a heavy simultaneous stampede on **one** wallet, MariaDB can refuse an activation with `CONCURRENCY_RETRY` after the bounded retries (2): about 1 in 400–1800 operations in the lab; 0 on MySQL 8.0.
- Outcome: the activation stays `FUNDS_RESERVED` with one ACTIVE reservation; nothing is charged or granted; the customer's retry is refused with `ACTIVATION_IN_PROGRESS`.
- **HELD reservations are never released automatically.** An operator resumes or cancels after the 300 s stale floor (health alerts at 5 / 30 min for stale activations, 1 h / 24 h for reservations).
- The message "please try again" is misleading for this case (wording change pending, not part of V1.0).
- The owner must confirm the policy (records conflict); jittered backoff (Variant A) is not implemented.

## 5.3 RAD-3a — session-end cut-off depends on the NAS clock

After a plan change or renewal, the sessions to end are selected by the NAS's own accounting stamp with a 10 s allowance. A NAS clock more than 10 s ahead can leave an old session running with the previous plan's policy until it reconnects; it is neither ended nor reported automatically, and `reconcile.php session-end` does not select it. Controls: NTP, preflight `time.nas_clock` (WARN > 5 s, FAIL > 10 s), health `nas_clock` (sees a clock **ahead** only), manual recovery (runbook §4c). A server-clock alternative (option d) is a proposal only.

## 5.4 Other classified items

| Item | Limitation |
|---|---|
| AV-1 | A FreeRADIUS stall longer than the NAS retry window (~9.3 s) leaves an orphan PENDING claim; the subscriber is refused until an audited manual release with NAS-side proof. Run FreeRADIUS threaded, never `-X` |
| HOLD-C | A PPPoE re-login within 10 s of a lost session's Start is refused (`reject:active-fresh`) — the price of preventing double sessions |
| TD-1 / TD-1b | The guard fails closed: a missing or wrong SNMP credential locks PPPoE re-logins out on that NAS until fixed; `nuxr_guard_selfcheck` detects it |
| TD-2 | A NAS not of type `mikrotik_snmp` is unsupported: after a lost Stop its subscribers stay locked out; the release preflight FAILs |
| Lost Accounting-Stop | RouterOS does not resend it; the `radacct` row stays open (never closed or deleted by the application) |
| CoA to a vanished session | RouterOS answers NAK 406; the outcome stays unconfirmed and is reported as drift until a Stop arrives |
| CoA policy changes | A CoA changing a byte limit mid-session is not applied by RouterOS (PPPoE ACKs, Hotspot NAKs); a CoA changing `Framed-Pool` / `Framed-IP-Address` is ignored — change the policy, then Disconnect |
| Missing IP pool | A plan naming a pool that does not exist on the NAS gives Access-Accept without IPv4 |
| R-HF-CARRY-01 | **OPEN — owner decision.** Fixed on the final-prep branch (lab evidence on both engines): the `autoload/pppoe_ip_used` / `pppoe_username_used` existence oracles for Agents and Sales (`0be31b63`); the `customers/add-post` existence oracle for tree-scoped staff (`228f01c9`); the static IP is validated and unique on save for every role (`e8260df7`); round 3: special IPv4 classes (0/8, 127/8, 169.254/16, 224/4, 240/4, including `255.255.255.255`) are refused on every save path, and a kept padded legacy IP is not re-judged (`b2f7837b`; Arabic refusal details `9b21ebfe`). Round-3 residuals: a legacy row that already holds such a value is still sent to the NAS as `Framed-IP-Address` (R3-1), and the VPN auto-assignment and the reprovision `revert()` write a static IP without the identity lock or the uniqueness check (R3-2). Still open for the owner: **low** unauthenticated enumeration through the forgot-password / forgot-username and self-registration pages, and **accepted-level** residuals (a fully valid add by scoped staff still shows that some identity collides; no rate limit on refused creates; the static-IP check does not cover NAS, gateway or pool addresses; existing duplicate or invalid static IPs are not migrated) |
| Staff username probing | Non-administrators can no longer rename their own account (refused: "Your username can only be changed by an administrator"). An Agent can still learn whether a staff username exists by creating or renaming a sub-account; a create leaves an audited account. Same class as the customer-identity enumeration residuals of R-HF-CARRY-01; not closed |
| Reversal and commissions | A reversal checks that the parent resellers can repay their commission before it removes the service, but the binding check runs at commit. If a parent spends that margin in between, the reversal stops at `REVERSAL_PENDING`: the service is removed and the refund stays pending until an operator funds the parent. No value is lost and no balance goes negative. Recovery: operator recovery runbook (`financial_reconciliation_guide` §4.1) |
| Session guard at scale | The `mikrotik_snmp` session guard has a 1.8 s deadline for its SNMP walk, measured at about 1.1 s on a small lab CHR. It has **not** been measured on a NAS with thousands of PPPoE interfaces; a timeout rejects a re-dial that meets an open accounting row. Measure on the target NAS during staging (TA) |
| No cron heartbeat | `health.php` does not report whether `cron.php` runs. Monitor the cron log and the expiry results externally (`monitoring_alerting_guide` §6) |
| Backups with `age` encryption | The encrypted manifest cannot be authenticated against someone who holds the public recipient: such a person can encrypt a forged set. Keep the recipients file private and the backup store write-protected, or use openssl / gpg symmetric keys |

<!-- pagebreak -->

# 6. Quota granularity and accuracy

| Aspect | V1.0 behaviour |
|---|---|
| Units | Data: whole **MB or GB, decimal** (1 MB = 10^6 bytes, 1 GB = 10^9 bytes), download + upload combined. Time: whole minutes or hours |
| Period | Per billing period; a period owns the accounting rows that **arrived after it started** (independent of the NAS clock) |
| Reset | A renewal, plan change or reactivation starts a new period from zero; a password change, rename or re-sync keeps the period |
| NAS enforcement | At each authentication the session receives its share of the remaining quota. Hotspot: hard cap per session (lab overshoot 0.3–4.4 % past the share). PPPoE: each direction capped; upload + download together closed by the enforcer |
| Enforcer | `quota.php enforce` every minute, passes every 5 s (range 5–15); interim interval default 30 s (configurable 10–300 s) |
| Accuracy | **Bounded, not byte-exact.** Must never be sold or advertised as a byte-exact cap |
| Overshoot | Not charged, not carried into the next period; actual and billable usage are separate numbers |
| Outage | With the enforcer, FreeRADIUS, the database or the NAS link down, only the router's limits apply (PPPoE worst case: twice the remaining quota) |
| Invalid plans | A Limited plan with a zero, negative, non-numeric or unknown-unit limit cannot be saved; selling an older invalid plan fails closed (preflight `quota.plans`) |

# 7. Functional scope limits

| Limitation | Notes |
|---|---|
| No auto-renew for reseller-owned subscribers | The cron enforces expiry only |
| No reseller self-purchase; no gateway commerce for reseller-owned subscribers | — |
| No payment-gateway plugin ships with the product | `system/paymentgateway/` holds only `index.html` and `ui/` |
| Reseller activations: RADIUS Hotspot / PPPoE plans only; no VPN or Balance plans; no proration | Balance plans are never resellable |
| A leaf wholesale of 0 is not activatable | `ZERO_WHOLESALE_NOT_SUPPORTED` |
| Agents are never deleted (disable only); an Agent's role never changes | — |
| Agents and Sales cannot edit, deactivate, delete or sync customers after creation | Admin only |
| Agents and Sales cannot generate vouchers | Admin only |
| The Report role has no data access | Login, empty dashboard, own profile only |
| A reseller-owned service ends only by expiry or an Admin reversal | Deactivate is refused |
| A customer status change (for example Suspended) does not by itself end or block the RADIUS service | Owner question F-6 open |
| Customer portal login refuses only Banned accounts; Suspended / Inactive customers can log in and see their pages | Owner question F-6 open |
| Prepaid deactivation by a direct customer loses the remaining paid time; no refund flow | Explicit AR / EN confirmation dialog (UX-2P, closed in the lab; UI-1 on the target) |
| No automatic customer refund on an activation reversal | Refunds happen outside the application |
| Web database restore refused on a reseller installation; the legacy web table download is not a supported backup | Restore is at database level |
| No built-in login throttling or lockout | Rate-limit at the HTTPS front; the `install/fresh/` nginx example includes a login-POST rate limit (lab: `302` ×6, then `429` ×6) |
| Reseller retail prices appear in legacy reports | — |
| jQuery 1.11.1 retained (no exploitable path found in the assessment) | — |
| Staff and customer sessions in one browser are not separated | Owner question UX-13 |
| Single holder per RADIUS identity: a second service type on the same RADIUS key is refused | Use a distinct PPPoE username |
| Static IP (`pppoe_ip`): must be a valid, non-reserved IPv4 address (or empty) and **unique across all customers**, for every role including Admin | Behaviour change on the final-prep branch (`e8260df7`; special IPv4 classes refused since `b2f7837b`). A stored legacy value is not re-judged until it is changed; such a value is still sent to the NAS (R3-1) |
| Agents and Sales get **one generic refusal** when a username, PPPoE username or static IP is already taken; they are not told which one or by whom | Behaviour change (`228f01c9`, `e8260df7`); Admins keep the specific reason |

# 8. Items engineering-closed, awaiting owner signature

These are no longer functional limitations in the release tree, but their records need the owner's signature (owner-decision consolidation §1.1): AV-3 (database-attested operator in the admission-release audit), W8-14 (same-key re-provision refused), R-4 (cron cannot suspend a paid activation in flight, including the F-A1 fix), Phase B (plugin / direct-device authority, conditional on a target re-check if any plugin is installed), P1 (plan change with unconfirmed Disconnect reported until the old session ends), P3 / R-15 (bandwidth terms sealed at confirmation).
