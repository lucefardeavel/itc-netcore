# Request for authorization: target-environment acceptance (one request, `docs/V1_TARGET_ACCEPTANCE.md` TA-01 .. TA-10)

Status: **REQUEST - nothing in it has been done or assumed.** No device, address, credential or window below is guessed: every blank is the owner's
to fill. The engineering side has prepared everything that can be prepared without the target (procedures, evidence table, tooling, synthetic data).
Until this request is answered and the tests are run **on the approved target**, every TA row stays `NOT_RUN` and the release status stays
`RELEASE_BLOCKED_WITH_EVIDENCE`. The isolated CHR lab is not proof for an untested production router or server.

## 1. What the owner is asked to provide

| # | Resource | Requirement | Owner's answer |
|---|---|---|---|
| R1 | **Staging NAS** | the same RouterOS version and board / model as the production routers (the capability probes of TA-04 and the N1 overshoot of TA-05 are version- and model-specific); carries **no customer traffic**; its RADIUS secret differs from every production NAS | model / version / location: ______ |
| R2 | A **test access segment** on that NAS | a PPPoE server and a Hotspot server on an isolated interface or VLAN with no subscribers, and a synthetic client able to dial both (a second router / CHR, or a Linux PPPoE client) | ______ |
| R3 | **Staging application, database and FreeRADIUS hosts** | the same OS, PHP, MariaDB / MySQL and FreeRADIUS versions as production, separate from production data and credentials; the same reverse proxy / TLS front for TA-10 | hosts / versions: ______ |
| R4 | **Time source** | NTP reachable by all four (app, database, FreeRADIUS, NAS) for TA-02 | ______ |
| R5 | **Database accounts** | the least-privilege accounts of `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` section D created on staging | ______ |
| R6 | **Operator** | a named person with shell access to the staging hosts and RouterOS write access to the staging NAS | name: ______ |
| R7 | **Window** | start / end (UTC) and a rollback contact; about one working day for TA-01 .. TA-08, a second day for TA-09 / TA-10 | ______ |
| R8 | **Backup** | a backup of the staging database taken before, and one restore tested (P3) | ______ |

## 2. What is requested (tick what is granted)

| ID | Test | Touches | Granted |
|---|---|---|---|
| TA-01 | preflight + health | staging DB (read), staging config | [ ] |
| TA-02 | clock discipline (hosts + NAS) | reads; one synthetic PPPoE login | [ ] |
| TA-03 | NAS contract (`/radius`, `/radius incoming`, `/ppp aaa`, one Disconnect) | **staging NAS configuration (write)** | [ ] |
| TA-04 | quota capability probe (limits, interim, Disconnect) | staging NAS, synthetic subscribers | [ ] |
| TA-05 | GB quota overshoot at the target line rate, interim 30 s / enforcer every 5 s | staging NAS + traffic generator | [ ] |
| TA-06 | renewal (Option B), re-dial inside 10 s, accounting delay | synthetic subscribers | [ ] |
| TA-07 | failure injection: stop the enforcer 2 min, block CoA for one pass, pause FreeRADIUS accounting one interval | staging services only | [ ] |
| TA-08 | deploy / upgrade / rollback and restore on a copy | staging hosts | [ ] |
| TA-09 | bounded capacity: accounting rate at the chosen interim against the staging FreeRADIUS pool; `load.sh` shape (never to exhaustion) | staging services | [ ] |
| TA-10 | security on the target: TLS, headers, cookies, rate limits behind the real proxy, grants, installer unreachable | staging web front | [ ] |

## 3. Safeguards the engineering side will keep

- Only synthetic identities `tgt_*` are created; none is created on a production NAS; no customer record is read or changed.
- Secrets are passed through the environment or 0600 files, never on a command line, never in the evidence; the staging NAS secret is generated for the test and rotated or destroyed afterwards.
- The pool is never exhausted: the forbidden A2-R reproduction (`a2r_sql_gate.py`, `a2r_gate.py pool|fault`) is not run, simulated or approximated; every load is paced and bounded (in-flight requests far below the pool).
- Each test has a stop condition (loss > 5 %, any `No connections available` in the FreeRADIUS log, any customer-traffic indication on the NAS) and stops at the first one.
- Every run's output is kept, failures included, as `owner-review/WP10/target/evidence/<id>.txt` (secrets redacted first).
- Staging objects (`tgt_*` users, plans, pools, test NAS entries) are removed at the end by a scripted read-then-delete; `/radius incoming` and every NAS setting are restored to the values captured first.

## 4. What is **not** requested

Production deployment or migration; any production database, router or secret; any customer data; any change to a live NAS; push or merge of a protected branch;
the OBS-1 survey on real data (separate request, `OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md`); the legal review (counsel).

## 5. After the answer

1. The owner fills section 1 and ticks section 2 (or sends the answers in any form).
2. The operator runs `docs/V1_TARGET_ACCEPTANCE.md` in order; the engineering side supplies the exact commands, parses the outputs and fills the evidence table.
3. A single `FAIL` or `NOT_RUN` keeps `RELEASE_BLOCKED_WITH_EVIDENCE`; all ten `PASS` with evidence files (and the decisions of `SAAS_RADIUS_V1_TARGET_RELEASE_ACCEPTANCE_PACKAGE.md` section 14) move it to `READY_FOR_PRODUCTION_OWNER_APPROVAL`.
