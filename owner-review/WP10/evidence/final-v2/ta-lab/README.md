# TA-01 lab stand-in (NOT the target)

`TA-01-preflight.txt` / `TA-01-health.json` are the output of `preflight.php` and `health.php --json --finance` against the **disposable UI-lab application copy**
(MariaDB 10.11 throwaway database, root account, no FreeRADIUS admission tables). They show that the deployment gate does its job - it FAILs on the missing
`nuxr_admission` tables and on the global `ALL PRIVILEGES` grant of the lab account - and that the other checks pass. They are not evidence for any target:
`docs/V1_TARGET_ACCEPTANCE.md` TA-01 stays `NOT_RUN`.
