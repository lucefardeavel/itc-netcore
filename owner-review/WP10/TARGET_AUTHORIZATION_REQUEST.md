# Request for authorization: isolated target staging and target-environment acceptance (one consolidated request)

Status: **REQUEST - nothing in it has been done, connected to or assumed.** No device, address, credential or window is named by the engineering side: every blank is the owner's to fill.
Everything that can be prepared without the target **is prepared** (section 6). Until this request is answered and the tests run **on the approved target**, every row of
`docs/V1_TARGET_ACCEPTANCE.md` stays `NOT_RUN` and the release status stays `RELEASE_BLOCKED_WITH_EVIDENCE`. The isolated CHR lab is not proof for an untested production router or server.

Related: `docs/STAGING_DEPLOYMENT_PACKAGE.md` (how the staging is built), `docs/V1_TARGET_ACCEPTANCE.md` section 2A (the execution sheets with pass / fail criteria),
`dev/staging/` (RouterOS template, accounting generator, synthetic seed, evaluators), `docs/OBS1_SURVEY_RUNBOOK.md` (separate request for real data).

## 1. Preconditions P1 - P5 (all must hold before the first command)

| # | Precondition | What exactly satisfies it | Owner's answer |
|---|---|---|---|
| P1 | Written owner authorization | a message naming: the staging NAS (model, RouterOS version, management address), the staging database host, the FreeRADIUS host, the application host, the time window (UTC) and the operator | ______ |
| P2 | The NAS carries no customer traffic; its RADIUS secret differs from every production NAS | the NAS is dedicated to the test, or is taken out of service for the window; the staging secret is generated for the test | ______ |
| P3 | Backup of the staging database taken and a restore tested | a backup record (time, size, SHA-256) and a restore log (`docs/BACKUP_RESTORE_RUNBOOK.md`) | ______ |
| P4 | Only synthetic identities | `tgt_*` users, plans, pools and a synthetic reseller chain; none exists on a production NAS; no customer record is read or changed | ______ |
| P5 | Secrets only in the environment / 0600 files | never on a command line, in shell history, tickets, logs or evidence; redaction before the evidence is stored | ______ |

## 2. Hardware, NAS, database and network access needed

| # | Resource | Requirement | Owner's answer |
|---|---|---|---|
| R1 | **Staging NAS** | the **same RouterOS version and board / model as production** (capability findings - PPPoE total limit ignored, per-direction limits, CoA not changing byte limits or interim - are version / model specific); write access for the operator for `/radius`, `/radius incoming`, `/ppp aaa`, a PPPoE server and a Hotspot server on the test interface, `/system ntp client`, `/snmp` (read-only community for the session guard of NAS type `mikrotik_snmp`) | model / version / address: ______ |
| R1b | **Second staging NAS** (case X-1 only) | same requirements as R1, its own isolated segment and secret; needed for the two-NAS old-session case X-1, which a local two-CHR run never replaces | model / version / address: ______ |
| R2 | **Isolated test segment** | an interface or VLAN with no subscribers on that NAS; a synthetic client that can dial PPPoE and Hotspot on it (a second router / CHR, or a Linux PPPoE client) and generate mixed upload + download traffic at a known rate | ______ |
| R3 | **Application host** | 2 vCPU, 4 GB, SSD; PHP 8.2 with `pdo_mysql`, `curl`, `gd`, `mbstring`, `zip`; HTTPS front with a valid certificate (the same reverse proxy as production for TA-10); cron | ______ |
| R4 | **Database host** | 4 vCPU, 8 GB, disk-backed SSD; MariaDB 10.11 **or** MySQL 8.0 (the production engine) as a **separate instance from production**; `REPEATABLE-READ`, `time_zone = '+00:00'`, `max_connections` >= 151 | engine / host: ______ |
| R5 | **FreeRADIUS host** | 4 vCPU, 4 GB (may share the application host on a small staging); FreeRADIUS 3.2.x threaded under the service manager; reaches the database over 3306 and the NAS over UDP 1812 / 1813 with no NAT, and queries the NAS over SNMP UDP 161 (session guard) | ______ |
| R6 | **Load generator** | any host with PHP 8.2 that can send UDP 1813 to FreeRADIUS (may be the application host) | ______ |
| R7 | **Time source** | NTP reachable by the application, database, FreeRADIUS hosts and the NAS (TA-02) | ______ |
| R8 | **Least-privilege database accounts** | created from `php system/reseller/grants.php` (application, FreeRADIUS) plus a separate DDL account for migrations | ______ |
| R9 | **Operator** | a named person with shell access to the staging hosts and RouterOS write access to the staging NAS | name: ______ |
| R10 | **Window** | start / end (UTC) and a rollback contact; about one working day for TA-01 .. TA-08, a second day for TA-09 / TA-10 | ______ |

Network flows to open (and nothing else): operator -> application TCP 443; application and FreeRADIUS -> database TCP 3306; NAS -> FreeRADIUS UDP 1812 / 1813;
FreeRADIUS -> NAS UDP 161 (read-only SNMP, session guard); application -> NAS UDP 3799 (or the `nas.ports` value); load generator -> FreeRADIUS UDP 1813; every host -> NTP UDP 123; operator SSH. The RouterOS API (8728 / 8729) is **not** used and must stay closed.

## 3. Authorization boundaries requested (tick what is granted)

| Boundary | Granted |
|---|---|
| Deploy the candidate (`SHA` named in the acceptance package) on the **staging** hosts only, and create the staging databases and accounts | [ ] |
| Configure the **staging NAS** (the objects named `nuxr_tgt_*` and the settings listed in `dev/staging/staging_nas.rsc.tpl`), after capturing and keeping the prior values | [ ] |
| Create synthetic reseller chain, plans, customers `tgt_*`; seed synthetic quota rows; send synthetic accounting from the load generator | [ ] |
| Run TA-01 .. TA-10 (including failure injection on staging services, restore drill, bounded capacity test, non-destructive security checks) | [ ] |
| Keep the redacted evidence in the repository under `owner-review/WP10/target/evidence/` (no secrets, no images, no captures with customer data) | [ ] |

**Not requested and not allowed by this request:** production deployment or migration; any production database, router, secret or customer record; a change to a live NAS; the OBS-1 survey on real data (own
request: `docs/OBS1_SURVEY_RUNBOOK.md`); push or merge of a protected branch; the SQL-exhaustion reproduction (`a2r_sql_gate.py`, `a2r_gate.py pool|fault`) - never; the legal review (counsel).

## 4. Stop conditions (the session ends at the first)

customer traffic on the staging NAS; a production identity, address or secret in a command or file; more than 5 % lost accounting requests in a load window or any `No connections available` in the FreeRADIUS log;
database `Threads_connected` above 70 % of `max_connections`; any FAIL that makes the next test unsafe.

## 5. Readiness of the ten tests (what exists, what is missing)

| ID | Test | Prepared | Needs from the target |
|---|---|---|---|
| TA-01 | preflight + health | commands, required checks, evaluator `ta_eval.py TA-01` | R3, R4, R5, R8 |
| TA-02 | clock discipline | commands, limits (core <= 5 s, NAS <= 10 s: the session-end cut-off, `docs/TIME_CONTRACT.md`; the earlier 60 s of the N2 proposal is superseded), evaluator `ta_eval.py TA-02` | R7, R1 |
| TA-03 | NAS contract | RouterOS template (`staging_nas.rsc.tpl`), criteria | R1, R2 |
| TA-04 | quota capability probe | steps, numeric criteria, lab reference results (CHR 7.24.5) | R1, R2 |
| TA-05 | GB overshoot (N1 = A + G) | steps, bound formula, Option G checks, failure-operation step | R1, R2, the line rate |
| TA-06 | renewal (Option B) + time contract | steps, criteria (incl. immediate re-dial, delayed accounting) | R1, R2 |
| TA-07 | failure injection | steps, expected alerts and recovery | R1, R5 |
| TA-08 | deploy / backup / restore / rollback | runbook references, criteria | R3, R4 |
| TA-09 | capacity | `dev/staging/acct_gen.php`, `seed_quota_rows.sql`, evaluator `ta_eval.py TA-09`, criteria | R4, R5, R6 and the planned peak of online quota subscribers |
| TA-10 | security on the target | commands, criteria, evaluator `ta_eval.py TA-10` | R3 (the real HTTPS front) |

## 6. What the engineering side has already done (nothing of it touched a target)

Final candidate gates in the lab (full dual-engine harness, CHR gates, UI / PWA, drill, bounded load, accounting capacity with the set-based enforcer), the O-DL study
(`O_DL_CLASSIFICATION.md`), the A2-R evidence (`A2R_OWNER_DECISION_EVIDENCE.md`), the legal package for counsel (`LEGAL_REVIEW_PACKAGE.md`), the OBS-1 survey tooling and runbook.

## 7. After the answer

1. The owner fills sections 1 - 3 (any form) and names the candidate SHA to deploy.
2. The operator runs `docs/STAGING_DEPLOYMENT_PACKAGE.md` S1 - S14; the engineering side supplies exact commands, evaluates the evidence and fills the table of `docs/V1_TARGET_ACCEPTANCE.md`.
3. A single `FAIL` or `NOT_RUN` keeps `RELEASE_BLOCKED_WITH_EVIDENCE`; all ten `PASS` with evidence files (and the owner / counsel decisions of the acceptance package section 14) move it to `READY_FOR_PRODUCTION_OWNER_APPROVAL`.
