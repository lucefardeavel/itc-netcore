#!/usr/bin/env python3
"""A2-R owner risk-decision evidence (D2): the defensive evidence, read from the final harness log and the CHR gate output.

  dev/wp10/a2r_evidence.py --testdox FULL_RUN_LOG --chr-gates SECURITY_GATES_OUT [--markdown OUT]

Reads logs only: it runs nothing, touches no database, and never runs or approximates the forbidden SQL-exhaustion reproduction.
Exit 0 only when every cited class ran on both engines without failure and the CHR security gates are OK.
"""
import argparse
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import closure_check as C  # noqa: E402  (shares the testdox parser)

LAYERS = [
    ('L1', 'schema CHECKs: an emptied claim / token / name is refused by the database', ['W9AdmissionHardeningTest', 'W9CheckConstraintIntegrityTest', 'W9CheckExpressionTest']),
    ('L2/L3', 'echo validation and SAFE-verified takeover (source pins of the FreeRADIUS site)', ['AdmissionSourceTest', 'SessionGuardSourceTest']),
    ('L4', 'readiness: CHECKs exist and are enforced (preflight), least-privilege grants', ['W9PreflightGrantsTest']),
    ('REC', 'operator recovery of an orphan PENDING claim: audited, manual, never automatic', ['W9RecoveryToolsTest', 'W9ReconcileCliGuardTest', 'V1OwnerDecisionGuardsTest']),
    ('ID', 'identity guards the admission relies on', ['U0IdentityTest', 'U0RaceTest', 'U0OwnershipGuardTest']),
    ('HEALTH', 'monitoring and alerting', ['W10HealthCheckTest']),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--testdox', required=True)
    ap.add_argument('--chr-gates', required=True)
    ap.add_argument('--markdown')
    a = ap.parse_args()
    dox = C.parse_testdox(a.testdox)
    errors = []
    md = ['| Layer | Defence | Test class | MariaDB | MySQL |', '|---|---|---|---|---|']
    for lid, what, classes in LAYERS:
        for t in classes:
            if C.find_test(t) is None:
                errors.append(f'{lid}: missing test class {t}')
                md.append(f'| {lid} | {what} | {t} | MISSING | MISSING |')
                continue
            per = dox.get(re.sub(r'Test$', '', t), {})
            cells = []
            for eng in ('mariadb', 'mysql'):
                r = per.get(eng)
                if r is None:
                    errors.append(f'{lid}: {t} did not run on {eng}')
                    cells.append('NOT RUN')
                    continue
                if r['fail']:
                    errors.append(f'{lid}: {t} FAILED on {eng}')
                cells.append(f"{r['ok']} pass" + (f", {r['fail']} FAIL" if r['fail'] else ''))
            md.append(f'| {lid} | {what} | {t} | {cells[0]} | {cells[1]} |')
    gates = open(a.chr_gates, errors='replace').read()
    m = re.search(r'^OK \((\d+) tests?, (\d+) assertions?\)', gates, re.M)
    if not m:
        errors.append('CHR security gates are not OK')
        md.append('| CHR | real NAS: concurrent first login, username equivalence PPPoE + Hotspot, shared users | wp8-security-gates | NOT OK | - |')
    else:
        md.append(f'| CHR | real NAS: concurrent first login, username equivalence PPPoE + Hotspot, shared users | wp8-security-gates | OK {m.group(1)} tests / {m.group(2)} assertions | (CHR) |')
    out = '\n'.join(md) + '\n'
    if a.markdown:
        open(a.markdown, 'w').write(out)
    print(out)
    if errors:
        print('A2R EVIDENCE CHECK FAILED:\n  ' + '\n  '.join(errors))
        sys.exit(1)
    print('a2r evidence check OK')


if __name__ == '__main__':
    main()
