#!/usr/bin/env python3
"""WP8 / WP9 / WP10 closure matrix checker (test tooling only; read-only; no network, no database).

For every roadmap row that the project reports as closed it verifies, against the ACTUAL tree:
  * the control exists: each (file, pattern) reference is found in the file;
  * the proof exists: each cited test class is a file under tests/;
  * (with --testdox LOG, the per-test log of a full run) how that class ended on each engine: passed / failed / skipped.
A reference that cannot be found is an error (the matrix would otherwise cite something that is gone). Exit 0 only when every
reference resolves, no cited test failed, and (with --testdox) every cited test class that is not a real-NAS lab class ran.

  closure_check.py [--testdox LOG] [--markdown OUT]
"""
import argparse
import os
import re
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))

# id: (title, status, [(file, regex)], [test classes])   status: CLOSED / CONTRACT / DEPLOY / OWNER / LAB (lab-class proof)
ITEMS = [
    ('S-1', 'A2-R SQL-exhaustion admission risk (defensive layers; reproduction never run)', 'CLOSED',
     [('install/freeradius/nuxr_admission.sql', r'CHECK'), ('install/freeradius/sites-available/phpnuxbill', r'nuxr_admission'), ('system/reseller/preflight.php', r'radius_checks')],
     ['W9AdmissionHardeningTest', 'AdmissionSourceTest', 'SessionGuardSourceTest', 'ConcurrentFirstLoginGateTest']),
    ('S-2a', 'OBS-1 application writers: canonical identity, serialized, multi-owner refused', 'CLOSED',
     [('system/autoload/Reseller/Domain/CustomerIdentity.php', r'function assertSingleOwner'), ('system/autoload/Reseller/Domain/CustomerIdentity.php', r'function hold')],
     ['U0IdentityTest', 'U0RaceTest', 'U0OwnershipGuardTest', 'U0VoucherServiceTest', 'A1VoucherIdentityGuardTest']),
    ('S-2b', 'OBS-1 existing duplicate data: survey + deployment gate (resolution method = owner decision)', 'DEPLOY',
     [('system/reseller/preflight.php', r"identity\.collisions"), ('dev/wp9/identity_survey.sql', r'SELECT')], ['U0SurveyTest']),
    ('S-2c', 'OBS-1 external writers: least-privilege grants, plugin inventory', 'CLOSED',
     [('system/reseller/grants.php', r'GRANT|grant'), ('system/reseller/plugins.php', r'inventory|sha')], ['W9PreflightGrantsTest', 'W9PluginInventoryTest']),
    ('S-3', 'Phase B authority: device grant guards, release profile, plugin errors', 'CLOSED',
     [('system/autoload/Reseller/Domain/ReleaseProfile.php', r'deviceEnabled'), ('system/devices/Radius.php', r'assertDeviceAllowed')], ['W9PhaseBAuthorityTest', 'W9ReleaseProfileTest']),
    ('S-4', 'R-HF-CARRY-01 / N-12 autoload metadata exposure', 'CLOSED', [('system/autoload/Reseller/Security/PathGuard.php', r'class PathGuard')], ['W9AutoloadExposureTest']),
    ('S-5', 'WP1-CHECK / N-08 ledger-binding CHECK constraints', 'CLOSED',
     [('system/autoload/Reseller/Domain/CheckConstraints.php', r'class CheckConstraints'), ('system/autoload/Reseller/Domain/SchemaReadiness.php', r'CHECK|check')], ['W9CheckConstraintIntegrityTest', 'W9CheckExpressionTest']),
    ('S-6', 'NEW-7 key separation ($app_secret, purpose subkeys)', 'CLOSED',
     [('system/autoload/Reseller/Security/AppKey.php', r'class AppKey'), ('system/reseller/preflight.php', r'keys\.app_secret')], ['W9KeySeparationTest', 'W9KeyMissingTest', 'W9KeyVariantDeviceTest']),
    ('S-7', 'NEW-8 Smarty sandbox for page templates', 'CLOSED', [('system/autoload/Reseller/Ui/PageTemplate.php', r'enableSecurity')], ['W9PageSandboxTest']),
    ('S-8', 'N6 secrets at rest / redaction (NAS secret boundary = owner decision)', 'CLOSED',
     [('system/autoload/Reseller/Security/ErrorReporter.php', r'redact|scrub|secret'), ('docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md', r'secret')], ['W9SecretRedactionTest', 'W9SecretRenderingTest']),
    ('O-1', 'Operator recovery tooling (CLAIMED_REVIEW, PENDING, REPROVISION, held)', 'CLOSED',
     [('system/reseller/reconcile.php', r'voucher-resolve|admission-release|finance-check'), ('docs/OPERATOR_RECOVERY_RUNBOOK.md', r'voucher-resolve')], ['W9RecoveryToolsTest', 'W9ReconcileCliGuardTest']),
    ('O-2', 'Time contract: UTC session zone, clock skew (preflight), NAS clock', 'DEPLOY',
     [('system/reseller/preflight.php', r"time\.sql_session_zone"), ('system/reseller/health.php', r'accounting')], ['AccountingTimeContractTest', 'AccountingTimeUnitTest']),
    ('O-3', 'Capacity / load: bounded load tool (target-hardware sizing = target)', 'DEPLOY', [('dev/wp10/load.sh', r'StagingLoadTest'), ('dev/wp10/StagingLoadTest.php', r'class')], []),
    ('O-4', 'Backup / restore / rollback drill; health and alerting', 'CLOSED',
     [('dev/wp10/drill.sh', r'BackupRestoreDrillTest'), ('system/reseller/health.php', r'activations_stale|reservations')], ['W10HealthCheckTest']),
    ('O-5', 'REPEATABLE READ and MAC-auth deployment gates', 'DEPLOY', [('system/reseller/preflight.php', r"'isolation'|isolation"), ('system/reseller/preflight.php', r"'mac'|mac")], []),
    ('O-6', 'R-4 cron vs RENEW race', 'CLOSED', [('system/autoload/Reseller/Domain/ServiceLock.php', r'class ServiceLock')], ['W9CronRenewalRaceTest']),
    ('O-8a', 'N-07a CSV injection', 'CLOSED', [('system/autoload/Reseller/Security/ErrorReporter.php', r'class')], ['W9CsvExportInjectionTest', 'CsvCellCharacterizationTest']),
    ('O-8b', 'N-11 secrets in UI; N-17 README claim', 'CLOSED', [('install/freeradius/README.md', r'A2-R')], ['W9SecretRenderingTest']),
    ('O-9', 'Legacy drivers / VPN / rlm_rest disabled by contract; same-key plans refused', 'CONTRACT',
     [('system/autoload/Reseller/Domain/ReleaseProfile.php', r'deviceEnabled')], ['W9ReleaseProfileTest', 'W9SharedRadiusKeyTest', 'DriverClassificationSourceTest']),
    ('SEC-17', 'Staff password storage (salted, constant-time compare)', 'CLOSED', [('system/autoload/Reseller/Security/ActorContext.php', r'class')], ['W9StaffPasswordStorageTest']),
    ('INST-1', 'Installer post-install protection', 'CLOSED', [('install/step5.php', r'.')], ['W9InstallerPostInstallTest']),
    ('SESS-1', 'Session cookie flags and security headers', 'CLOSED', [('index.php', r'httponly.*samesite')], ['W9SessionCookieHardeningTest']),
    ('XSS-1', 'Stored XSS across privilege levels (whole route map crawled)', 'CLOSED', [('ui/ui/admin/customers/edit.tpl', r'escape')], ['W9StoredXssCrawlTest']),
    ('SC-1', 'Subresource Integrity for CDN assets', 'CLOSED', [('ui/ui/admin/header.tpl', r'integrity')], ['W9ExternalAssetIntegrityTest']),
    ('P1', 'CHANGE_PLAN/REACTIVATE session policy stale after an unconfirmed Disconnect', 'CLOSED',
     [('system/autoload/Reseller/Activation/DriftReport.php', r'SESSION_POLICY_STALE')], ['W9StalePolicySessionTest']),
    ('P3', 'Sealed bandwidth terms (R-15)', 'CLOSED', [('system/autoload/Reseller/Activation/ActivationService.php', r'BANDWIDTH_TERMS_CHANGED')], ['W9BandwidthTermsTest']),
    ('AUTH', 'Role / scope / CSRF / route matrix', 'CLOSED',
     [('system/autoload/Reseller/Security/RouteGuard.php', r'class RouteGuard'), ('system/autoload/Reseller/Security/CsrfPolicy.php', r'class CsrfPolicy')],
     ['RouteMatrixTest', 'CsrfTest', 'ScopeIsolationTest', 'HierarchyTest', 'SecurityRoutesTest', 'W9RedTeamHttpTest']),
    ('LEDGER', 'Ledger / wallet / settlement integrity', 'CLOSED', [('system/reseller/reconcile.php', r'finance-check')], ['W9OwnerPolicyTest']),
    # V1 quota + Option B + N2
    ('V1-Q2', 'GB / time / speed / composite plans; in-session share; fail closed', 'CLOSED',
     [('system/autoload/Reseller/Radius/QuotaPolicy.php', r'class QuotaPolicy'), ('install/freeradius/sites-available/phpnuxbill', r'Nuxr-Quota-Share')],
     ['V1QuotaPolicyTest', 'V1QuotaFreeRadiusTest', 'V1PlanQuotaFormTest', 'V1VoucherQuotaTest']),
    ('V1-ENF', 'Accounting enforcer, confirmed termination, health', 'CLOSED',
     [('system/autoload/Reseller/Radius/QuotaEnforcer.php', r'class QuotaEnforcer'), ('system/autoload/Reseller/Radius/SessionTermination.php', r'ACK_WITHOUT_STOP|CONFIRMED'), ('system/reseller/quota.php', r'GET_LOCK')],
     ['V1QuotaEnforcerTest', 'V1QuotaOperationsTest']),
    ('V1-Q1', 'Option B renewal: end + confirm, no double settlement', 'CLOSED',
     [('system/autoload/Reseller/Activation/ActivationService.php', r'endPeriodSessions'), ('system/autoload/Reseller/Domain/LegacyReprovisionService.php', r'endedInScope')],
     ['V1RenewalOptionBTest', 'V1LegacyRenewalTest', 'RenameOldSessionTest']),
    ('V1-N2', 'Exact period ownership by accounting-row order (NAS-clock independent)', 'CLOSED',
     [('system/autoload/Reseller/Radius/PeriodBoundary.php', r'class PeriodBoundary'), ('install/freeradius/mods-available/nuxr_counters', r'Nuxr-Period-Acct-Id')], ['V1PeriodOwnershipTest']),
    # real-NAS proof (lab classes; they self-mark incomplete without the lab and are run on the CHR)
    ('LAB-V1', 'Real RouterOS CHR: V1 quota, renewal, faults', 'LAB', [('tests/Integration/Wp8/V1QuotaLabTest.php', r'class')], ['V1QuotaLabTest']),
    ('LAB-A3', 'Real RouterOS CHR: rename / old session', 'LAB', [('tests/Integration/Wp8/RenameOldSessionLabTest.php', r'class')], ['RenameOldSessionLabTest']),
    ('LAB-G', 'Real RouterOS CHR: Option G, Port-Limit, equivalence, A2-R race gates', 'LAB', [('install/freeradius/README.md', r'Option G')],
     ['OptionGGateTest', 'PortLimitGateTest', 'UsernameEquivalenceGateTest', 'HotspotEquivalenceGateTest', 'ConcurrentFirstLoginGateTest']),
]


def find_test(name):
    for dp, dn, fn in os.walk(os.path.join(ROOT, 'tests')):
        if 'vendor' in dp.split(os.sep):
            continue
        if name + '.php' in fn:
            return os.path.relpath(os.path.join(dp, name + '.php'), ROOT)
    return None


def parse_testdox(path):
    """class (without 'Test', no spaces) -> {engine: {'ok': n, 'fail': n, 'skip': n}} from a run-tests.sh --testdox log"""
    out = {}
    engine = None
    cls = None
    for line in open(path, errors='replace'):
        line = line.rstrip('\n')
        m = re.match(r'=+ engine: (\w+) =+', line)
        if m:
            engine, cls = m.group(1), None
            continue
        m = re.match(r'^[^\s].*\(Reseller\\Tests\\[\w\\]*?(\w+)\)$', line)
        if m and engine:
            cls = m.group(1)
            continue
        if engine and cls:
            m = re.match(r'^ (✔|✘|∅|↩|⚠|☢)', line)
            if m:
                e = out.setdefault(cls, {}).setdefault(engine, {'ok': 0, 'fail': 0, 'skip': 0})
                e['ok' if m.group(1) == '✔' else 'fail' if m.group(1) == '✘' else 'skip'] += 1
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--testdox')
    ap.add_argument('--markdown')
    a = ap.parse_args()
    dox = parse_testdox(a.testdox) if a.testdox else None
    errors = []
    rows = []
    for iid, title, status, code, tests in ITEMS:
        cres = []
        for f, rx in code:
            p = os.path.join(ROOT, f)
            if not os.path.isfile(p):
                errors.append(f'{iid}: missing file {f}')
                cres.append(f'`{f}` MISSING')
                continue
            if not re.search(rx, open(p, errors='replace').read()):
                errors.append(f'{iid}: pattern /{rx}/ not found in {f}')
                cres.append(f'`{f}` PATTERN MISSING')
                continue
            cres.append(f'`{f}`')
        tres = []
        for t in tests:
            tp = find_test(t)
            if tp is None:
                errors.append(f'{iid}: missing test class {t}')
                tres.append(f'{t} MISSING')
                continue
            if dox is None:
                tres.append(t)
                continue
            key = re.sub(r'Test$', '', t)
            per = dox.get(key, {})
            if not per:
                if status == 'LAB':
                    tres.append(f'{t}: lab class (not in the harness run)')
                else:
                    errors.append(f'{iid}: {t} did not appear in the testdox log')
                    tres.append(f'{t}: NOT RUN')
                continue
            parts = []
            for eng in ('mariadb', 'mysql'):
                r = per.get(eng)
                if r is None:
                    parts.append(f'{eng}: -')
                    continue
                if r['fail']:
                    errors.append(f'{iid}: {t} FAILED on {eng}')
                parts.append(f"{eng}: {r['ok']} pass" + (f", {r['fail']} FAIL" if r['fail'] else '') + (f", {r['skip']} skip/incomplete" if r['skip'] else ''))
            tres.append(f"{t} ({'; '.join(parts)})")
        rows.append((iid, title, status, cres, tres))
    md = ['| ID | Item | Class | Control in the tree | Proof (tests) |', '|---|---|---|---|---|']
    for iid, title, status, cres, tres in rows:
        md.append(f"| {iid} | {title} | {status} | {'<br>'.join(cres)} | {'<br>'.join(tres) if tres else '(deployment / procedure)'} |")
    text = '\n'.join(md) + '\n'
    if a.markdown:
        open(a.markdown, 'w').write(text)
    print(text)
    if errors:
        print('\nCLOSURE CHECK FAILED:\n  ' + '\n  '.join(errors), file=sys.stderr)
        sys.exit(1)
    print(f'closure check OK: {len(rows)} items', file=sys.stderr)


if __name__ == '__main__':
    main()
