| Layer | Defence | Test class | MariaDB | MySQL |
|---|---|---|---|---|
| L1 | schema CHECKs: an emptied claim / token / name is refused by the database | W9AdmissionHardeningTest | 11 pass | 11 pass |
| L1 | schema CHECKs: an emptied claim / token / name is refused by the database | W9CheckConstraintIntegrityTest | 10 pass | 10 pass |
| L1 | schema CHECKs: an emptied claim / token / name is refused by the database | W9CheckExpressionTest | 13 pass | 13 pass |
| L2/L3 | echo validation and SAFE-verified takeover (source pins of the FreeRADIUS site) | AdmissionSourceTest | 5 pass | 5 pass |
| L2/L3 | echo validation and SAFE-verified takeover (source pins of the FreeRADIUS site) | SessionGuardSourceTest | 5 pass | 5 pass |
| L4 | readiness: CHECKs exist and are enforced (preflight), least-privilege grants | W9PreflightGrantsTest | 1 pass | 1 pass |
| REC | operator recovery of an orphan PENDING claim: audited, manual, never automatic | W9RecoveryToolsTest | 3 pass | 3 pass |
| REC | operator recovery of an orphan PENDING claim: audited, manual, never automatic | W9ReconcileCliGuardTest | 2 pass | 2 pass |
| REC | operator recovery of an orphan PENDING claim: audited, manual, never automatic | V1OwnerDecisionGuardsTest | 3 pass | 3 pass |
| ID | identity guards the admission relies on | U0IdentityTest | 86 pass | 86 pass |
| ID | identity guards the admission relies on | U0RaceTest | 23 pass | 23 pass |
| ID | identity guards the admission relies on | U0OwnershipGuardTest | 11 pass | 11 pass |
| HEALTH | monitoring and alerting | W10HealthCheckTest | 2 pass | 2 pass |
| CHR | real NAS: concurrent first login, username equivalence PPPoE + Hotspot, shared users | wp8-security-gates | OK 10 tests / 216 assertions | (CHR) |
