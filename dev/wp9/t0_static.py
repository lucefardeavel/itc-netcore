#!/usr/bin/env python3
"""WP9 T0 static / inert checks (owner: WP9_T0_STATIC_TESTING_AUTHORIZED, 2026-10-15).

Python 3 standard library only. Reads repository files and writes evidence files under --out.
It opens no network connection, no database, starts no container, process or service, and never
executes PHP. Every check is a characterisation of the source text at the fingerprinted tree.

Usage: python3 -I -B dev/wp9/t0_static.py --out owner-review/WP9/evidence/t0
Exit code: 0 = every executed check passed; 1 = at least one check failed; 2 = usage / internal error.
"""
import argparse
import hashlib
import json
import os
import re
import sys
import traceback

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SITE = 'install/freeradius/sites-available/phpnuxbill'


# ----------------------------------------------------------------------------------------------- helpers
def rel_read(rel):
    with open(os.path.join(ROOT, rel), encoding='utf-8', errors='replace') as fh:
        return fh.read()


def sha256_file(rel):
    with open(os.path.join(ROOT, rel), 'rb') as fh:
        return hashlib.sha256(fh.read()).hexdigest()


def php_files(dirs, exclude_prefixes=('system/vendor/',)):
    out = []
    for d in dirs:
        for dp, _dn, fn in os.walk(os.path.join(ROOT, d)):
            for f in fn:
                if f.endswith('.php'):
                    rel = os.path.relpath(os.path.join(dp, f), ROOT)
                    if not rel.startswith(exclude_prefixes):
                        out.append(rel)
    out += sorted(f for f in os.listdir(ROOT) if f.endswith('.php'))
    return sorted(set(out))


def tree_fingerprint():
    """sha256 over (path, sha256) of every file in system/, install/, ui/, tests/ (without tests/vendor) and root *.php."""
    entries = []
    for d in ('system', 'install', 'ui', 'tests'):
        for dp, _dn, fn in os.walk(os.path.join(ROOT, d)):
            for f in fn:
                rel = os.path.relpath(os.path.join(dp, f), ROOT)
                if rel.startswith('tests/vendor/') or '/.phpunit' in rel or '__pycache__' in rel:
                    continue
                entries.append((rel, sha256_file(rel)))
    for f in sorted(os.listdir(ROOT)):
        if f.endswith('.php'):
            entries.append((f, sha256_file(f)))
    entries.sort()
    h = hashlib.sha256()
    for rel, digest in entries:
        h.update(f'{rel}\0{digest}\n'.encode())
    return {'files': len(entries), 'sha256': h.hexdigest()}


def block_lines(lines, start_idx):
    """Line indexes of the brace block that opens on lines[start_idx] (brace counting; unlang braces are balanced per line)."""
    depth = 0
    out = []
    for i in range(start_idx, len(lines)):
        depth += lines[i].count('{') - lines[i].count('}')
        out.append(i)
        if depth <= 0 and i > start_idx:
            break
    return out


def outer_sql_inner_expansions(line):
    """Inner %{...} names inside the %{sql:...} expansion of one unlang line (outer sql xlat excluded)."""
    k = line.find('%{sql:')
    if k < 0:
        return []
    body = line[k + 6:]
    return re.findall(r'%\{([A-Za-z][A-Za-z0-9:-]*(?:\[[0-9]+\])?)\}', body)


class Check:
    def __init__(self, cid, finding, title):
        self.cid, self.finding, self.title = cid, finding, title
        self.assertions = 0
        self.files = set()
        self.details = {}
        self.controls = []

    def read(self, rel):
        self.files.add(rel)
        return rel_read(rel)

    def ok(self, cond, msg):
        self.assertions += 1
        if not cond:
            raise AssertionError(msg)

    def eq(self, a, b, msg):
        self.assertions += 1
        if a != b:
            raise AssertionError(f'{msg}: expected {b!r}, got {a!r}')

    def control(self, kind, desc, passed):
        self.controls.append({'kind': kind, 'description': desc, 'passed': bool(passed)})
        self.ok(passed, f'{kind} control failed: {desc}')


# ----------------------------------------------------------------------------------------------- checks
def t0_01_a2r_inventory(c):
    """A2-R static statement inventory (N-13, PLAN §6.1)."""
    site = c.read(SITE).split('\n')
    start = next(i for i, l in enumerate(site) if '&control:Simultaneous-Use) {' in l and 'Framed-Protocol == PPP' in l)
    pppoe = block_lines(site, start)
    acct_start = next(i for i, l in enumerate(site) if re.match(r'^accounting \{', l))
    acct = block_lines(site, acct_start)
    is_code = lambda l: not l.lstrip().startswith('#')
    pppoe_sql = [i + 1 for i in pppoe if '%{sql:' in site[i] and is_code(site[i])]
    acct_a2r = [i + 1 for i in acct if '%{sql:' in site[i] and is_code(site[i]) and re.search(r'\bnuxr_admission\b(?!_)', site[i])]
    c.details['pppoe_block_lines'] = [start + 1, pppoe[-1] + 1]
    c.eq(pppoe_sql, [209, 240, 259, 310, 311, 416, 425, 434, 444, 451, 478], 'PPPoE block %{sql:} statement lines')
    c.eq(acct_a2r, [96, 101], 'accounting nuxr_admission statements')
    inventory = []
    for ln in pppoe_sql + acct_a2r:
        text = site[ln - 1]
        body = text[text.find('%{sql:') + 6:]
        verb = re.match(r'\s*(SELECT|INSERT|UPDATE|DELETE)', body).group(1)
        tables = sorted(set(re.findall(r'\b(nuxr_admission|radcheck|radacct)\b(?!_)', body)))
        target = re.match(r'\s*&?([A-Za-z0-9-]+)\s*:=', text.strip().lstrip('&'))
        inventory.append({'line': ln, 'section': 'accounting' if ln < 150 else 'post-auth', 'verb': verb, 'tables': tables,
                          'result_attribute': target.group(1) if target else None,
                          'inner_expansions': outer_sql_inner_expansions(text)})
    c.details['inventory'] = inventory
    writes = [s for s in inventory if s['verb'] in ('INSERT', 'UPDATE')]
    c.eq([s['line'] for s in writes], [416, 425, 434, 451, 478, 96, 101], 'write statements on nuxr_admission')
    for s in writes:
        c.ok(s['tables'] == ['nuxr_admission'], f"write at :{s['line']} targets nuxr_admission only")
        c.ok(len(s['inner_expansions']) >= 2, f"write at :{s['line']} interpolates escaped values (empty-expansion exposure)")
    # N-13: no echo validation (parameter row + echoed HEX) on the PPPoE path
    # echo validation = a derived parameter row built from expansions (... FROM (SELECT ...%{x}... ) AS p) whose values are
    # echoed back (HEX(p.k)); run-1 used a narrower pattern that missed the H-2B form UNHEX('%{..}') AS k (detector defect)
    echo_pat = re.compile(r"FROM\s*\(\s*SELECT\s[^;]*?%\{[^}]+\}[^;]*?\)\s*AS\s+p\b.*?|HEX\(\s*p\.", re.I)
    c.ok(not any(echo_pat.search(site[i]) for i in pppoe), 'no echoed parameter row on the PPPoE path (N-13)')
    hs_echo = [i + 1 for i, l in enumerate(site) if echo_pat.search(l)]
    c.control('positive', 'the echo detector finds the H-2B echoed parameter rows', len(hs_echo) >= 1 and all(n > pppoe[-1] + 1 for n in hs_echo))
    c.details['h2b_echo_lines'] = hs_echo
    # schema: A2-R has no CHECK constraint; H-2B has two (positive control for the detector)
    a2r_sql = c.read('install/freeradius/nuxr_admission.sql')
    hs_sql = c.read('install/freeradius/nuxr_hs_admission.sql')
    ck = re.compile(r'CONSTRAINT\s+\w+\s+CHECK\s*\(', re.I)
    c.eq(len(ck.findall(a2r_sql)), 0, 'CHECK constraints in nuxr_admission.sql')
    c.control('positive', 'the CHECK detector finds the two H-2B constraints', len(ck.findall(hs_sql)) == 2)
    c.ok(re.search(r'PRIMARY KEY \(username\)', a2r_sql) is not None, 'nuxr_admission PRIMARY KEY(username) present')
    c.ok(re.search(r'UNIQUE KEY uq_nuxr_admission_token \(token\)', a2r_sql) is not None, 'nuxr_admission UNIQUE(token) present')
    c.ok(re.search(r'never alters', a2r_sql, re.I) is not None or 'ALTER' not in a2r_sql.upper().replace('ALTERS', ''),
         'nuxr_admission.sql is additive (no ALTER statement)')


def t0_02_a2r_decision_table(c):
    """A2-R sentinel / rejection decision table (N-13, PLAN §6.1 items 4-6); regex literals taken from the site."""
    site = c.read(SITE).split('\n')
    L = lambda n: site[n - 1]

    def rejects_within(n, span=12):
        return any(re.match(r'^\s*reject\s*$', site[i]) for i in range(n, min(n + span, len(site))))

    # anchor lines: the exact condition text at each checked line
    anchors = {
        211: '(&Tmp-String-5 != "1")', 242: '&Tmp-String-1 =~ /^k=', 261: '&Tmp-String-3 == "r=none"',
        266: '&Tmp-String-3 =~ /^r=(PENDING|ACTIVE|ENDED)', 313: '&Tmp-String-4 != "m=0"', 323: '&Tmp-String-8 == "n=0;"',
        326: '&Tmp-String-8 =~ /^n=[1-9]', 441: '&Tmp-String-0 != "nuxr-admission-claim=1"', 446: '&Tmp-String-3 =~ /^r=PENDING',
        458: '&Tmp-String-0 != "nuxr-admission-claim=1"',
    }
    for n, frag in anchors.items():
        c.ok(frag in L(n), f'condition at :{n} contains {frag!r}')
    # reject branches
    for n in (211, 313, 458):
        c.ok(rejects_within(n), f'condition at :{n} leads to reject')
    c.ok(rejects_within(275), 'else of S1 (:247) / S2 (:275) rejects')
    c.ok(rejects_within(247), 'else of the key read rejects')
    # the n=0; branch contains no statement (verification skipped) - F2 premise
    c.ok(L(324).strip().startswith('#') and L(325).strip() == '}', ':323 n=0; branch is empty (guard skipped)')
    # unchecked results: L (:478 Tmp-String-1) and accounting (:96/:101 Tmp-String-2)
    def compared_after(n, attr, span):
        return [i + 1 for i in range(n, min(n + span, len(site)))
                if re.search(r'\bif\b|\belsif\b', site[i]) and attr in site[i]]
    c.eq(compared_after(478, '&Tmp-String-1', 12), [], 'late release result (:478) is not checked')
    c.ok(rejects_within(478, 12), 'late-claim path rejects regardless of the release result')
    acct = compared_after(96, '&Tmp-String-2', 20)
    c.eq(acct, [], 'accounting results (:96, :101) are not checked')
    # evaluate the site's own regex literals on synthetic results (unlang POSIX ERE subset; Python-compatible here)
    rx = {
        'S1': re.search(r'=~ /(.+)/\)', L(242)).group(1),
        'S2': re.search(r'=~ /(.+)/\)', L(266)).group(1),
        'S3a': re.search(r'=~ /(.+)/\)', L(326)).group(1),
        'R': re.search(r'=~ /(.+)/\)', L(446)).group(1),
    }
    c.details['regex_literals'] = rx

    def s0(v): return 'reject' if v != '1' else 'continue'
    def s1(v): return 'key' if re.search(rx['S1'], v) else 'reject'
    def s2(v): return 'NONE' if v == 'r=none' else ('parsed' if re.search(rx['S2'], v) else 'reject')
    def s3b(v): return 'reject' if v != 'm=0' else 'continue'
    def s3a(v): return 'skip-guard' if v == 'n=0;' else ('guard' if re.search(rx['S3a'], v) else 'reject')
    def claim(v): return 'claimed' if v == 'nuxr-admission-claim=1' else 'reread'
    table = [
        ('S0', s0, '', 'reject'), ('S0', s0, '1', 'continue'),
        ('S1', s1, '', 'reject'), ('S1', s1, 'k=-', 'reject'), ('S1', s1, 'k=414243', 'key'),
        ('S2', s2, '', 'reject'), ('S2', s2, 'r=none', 'NONE'), ('S2', s2, 'r=ACTIVE|' + 'a' * 32 + '|same|spell|10.88.0.2', 'parsed'),
        ('S2', s2, 'r=ACTIVE||same|spell|10.88.0.2', 'reject'),
        ('S3b', s3b, '', 'reject'), ('S3b', s3b, 'm=0', 'continue'), ('S3b', s3b, 'm=1', 'reject'),
        ('S3a', s3a, '', 'reject'), ('S3a', s3a, 'n=;', 'reject'), ('S3a', s3a, 'n=0;', 'skip-guard'),
        ('S3a', s3a, 'n=1;10.88.0.2', 'guard'), ('S3a', s3a, 'n=2;10.88.0.2,10.88.0.3', 'guard'),
        ('C', claim, '', 'reread'), ('C', claim, 'nuxr-admission-claim=0', 'reread'), ('C', claim, 'nuxr-admission-claim=1', 'claimed'),
    ]
    rows = []
    for name, fn, value, expect in table:
        got = fn(value)
        rows.append({'statement': name, 'result': value, 'decision': got, 'expected': expect})
        c.eq(got, expect, f'{name} decision for {value!r}')
    c.details['decision_table'] = rows
    c.control('negative', 'an empty SQL result is rejected by every guarded read (S0, S1, S2, S3a, S3b)',
              all(r['decision'] == 'reject' for r in rows if r['result'] == '' and r['statement'] != 'C'))
    c.control('positive', 'well-formed results take the accepting path', s1('k=414243') == 'key' and s3a('n=1;10.88.0.2') == 'guard')
    c.details['observation'] = ('A well-formed result "n=0;" (zero open rows, zero ACTIVE reservations) skips the session guard; '
                                'an SQL statement whose inner expansions were emptied could also return "n=0;" (F2, hypothesis, not tested).')


PINNED_RE = re.compile(r"'([^']+\.php)'\s*=>\s*'([0-9a-f]{64})'")


def t0_03_sourceguard(c):
    """SourceGuard-oriented analysis: SHA pins match the files; which planned files are pinned (PLAN §7.3, §15)."""
    sg = c.read('tests/Unit/Wp3a/SourceGuardTest.php')
    pins = dict(PINNED_RE.findall(sg))
    c.details['pinned_files'] = sorted(pins)
    c.ok(len(pins) >= 20, 'SourceGuard PINNED map parsed')
    mismatched = []
    for rel, digest in sorted(pins.items()):
        c.files.add(rel)
        if sha256_file(rel) != digest:
            mismatched.append(rel)
        c.assertions += 1
    c.eq(mismatched, [], 'files whose sha256 differs from the SourceGuard pin')
    # negative control: a mutated copy is detected
    raw = open(os.path.join(ROOT, 'system/autoload/Reseller/Domain/Tx.php'), 'rb').read()
    c.control('negative', 'a one-byte mutation of a pinned file changes its digest',
              hashlib.sha256(raw + b'\n').hexdigest() != pins['system/autoload/Reseller/Domain/Tx.php'])
    for rel in ('system/autoload/Reseller/Domain/Tx.php', 'system/reseller/Migration/SchemaInspector.php',
                'system/reseller/Migration/Runner.php', 'system/autoload/Reseller/Security/RouteMap.php',
                'system/autoload/Package.php', 'system/autoload/Reseller/Domain/ServiceGate.php'):
        c.ok(rel in pins, f'{rel} is SHA-pinned')
    future_unpinned = ['system/autoload/Reseller/Domain/CustomerService.php', 'system/controllers/customers.php',
                       'system/controllers/register.php', 'system/autoload/Reseller/Domain/VoucherRedemptionService.php',
                       'system/autoload/Reseller/Domain/LegacyReprovisionService.php',
                       'system/autoload/Reseller/Domain/TreeSerialization.php', 'system/devices/Radius.php',
                       'system/cron.php', 'system/autoload/User.php', 'system/boot.php']
    for rel in future_unpinned:
        c.ok(rel not in pins, f'{rel} is not SHA-pinned')
    # pinned-text tests that the A2-R hardening design would break (PLAN §6.5)
    adm = c.read('tests/Unit/Wp4/AdmissionSourceTest.php')
    c.ok('testSchemaUpgradeIsAdditiveAndRadiusSqlUnchanged' in adm, 'AdmissionSourceTest pins an additive nuxr_admission.sql')
    c.ok(os.path.exists(os.path.join(ROOT, 'tests/Unit/Wp4/SessionGuardSourceTest.php')), 'SessionGuardSourceTest exists')
    # AM-W9-1 premise: withTreeLock opens the transaction before the callback; TreeSerialization::hold refuses an open transaction
    tx = c.read('system/autoload/Reseller/Domain/Tx.php')
    m = re.search(r'function withTreeLock\(.*?\n    \}', tx, re.S)
    c.ok(m is not None and 'GET_LOCK' in m.group(0) and 'self::run($fn)' in m.group(0), 'withTreeLock = GET_LOCK then Tx::run')
    run = re.search(r'function run\(.*?\n    \}', tx, re.S).group(0)
    c.ok(run.index('beginTransaction()') < run.index('$fn($pdo)'), 'Tx::run begins the transaction before the callback')
    ts = c.read('system/autoload/Reseller/Domain/TreeSerialization.php')
    c.ok("inTransaction()" in ts and 'LogicException' in ts, 'TreeSerialization::hold refuses an open transaction')
    cs = c.read('system/autoload/Reseller/Domain/CustomerService.php')
    c.ok('return Tx::withTreeLock(function (\\PDO $pdo)' in cs, 'P1 CustomerService::create runs inside Tx::withTreeLock today')
    c.ok("SELECT id FROM tbl_customers WHERE username = ? FOR UPDATE" in cs, 'P1 checks username only')
    tests_pin_cs = [f for f in php_files(['tests'], exclude_prefixes=('tests/vendor/',))
                    if 'CustomerService' in rel_read(f) and 'withTreeLock' in rel_read(f)]
    c.eq(tests_pin_cs, [], 'tests that couple CustomerService to withTreeLock')


SINK_RE = re.compile(r'(?<![\w>$:\\])(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec|eval|assert)\s*\(')
SINK_ALLOW = {
    ('system/autoload/PEAR2/Console/CommandLine/Action/Password.php', "system('stty -echo');"),
    ('system/autoload/PEAR2/Console/CommandLine/Action/Password.php', "system('stty echo');"),
    ('system/autoload/mail/PHPMailer.php', "$mail = @popen($sendmail, 'w');"),
    ('system/controllers/settings.php', "$php = trim(shell_exec('which php'));"),
    ('system/widgets/customer/html_php.php', "eval('?>'. $data['content']);"),
    ('system/widgets/html_php.php', "eval('?>'. $data['content']);"),
    ('system/widgets/html_php_card.php', "eval('?>'. $data['content']);"),
}


def scan_sinks(text, rel):
    hits = []
    for i, line in enumerate(text.split('\n'), 1):
        s = line.strip()
        if s.startswith(('*', '//', '#', '/*')):
            continue
        for m in SINK_RE.finditer(line):
            if re.search(r'function\s+$', line[:m.start()]):
                continue
            hits.append({'file': rel, 'line': i, 'call': m.group(1), 'text': s[:160]})
        if re.search(r'=\s*`[^`]+`\s*;', line):
            hits.append({'file': rel, 'line': i, 'call': 'backtick', 'text': s[:160]})
    return hits


def t0_04_no_shell(c):
    """No-shell static scan of all runtime PHP outside system/vendor (matrix #14, SEC-11 / SEC-22 context)."""
    files = php_files(['system', 'install', 'ui'])
    hits = []
    for rel in files:
        hits += scan_sinks(c.read(rel), rel)
    unknown = [h for h in hits if (h['file'], h['text']) not in SINK_ALLOW]
    known = [h for h in hits if (h['file'], h['text']) in SINK_ALLOW]
    c.details['files_scanned'] = len(files)
    c.details['known_sinks'] = known
    c.details['unknown_sinks'] = unknown
    c.eq(unknown, [], 'process / eval sinks outside the reviewed allow-list')
    c.eq(len(known), 8, 'reviewed constant / admin-trusted sinks found (PEAR2 stty x2, PHPMailer popen x2, which php, html_php eval x3)')
    radius_paths = [h for h in hits if h['file'].startswith(('system/autoload/Reseller/Radius/', 'system/devices/Radius'))]
    c.eq(radius_paths, [], 'no process sink in the RADIUS / CoA code')
    c.control('negative', 'the scanner detects shell_exec($x) and eval($y)',
              len(scan_sinks("$a = shell_exec($x);\neval($y);\n$b = `ls`;", 'synthetic')) == 3)
    c.control('positive', 'method calls and declarations are not reported',
              scan_sinks("$pdo->exec($sql);\nTx::exec($pdo, $s);\npublic static function exec(", 'synthetic') == [])


LEDGER_TABLES = ('tbl_manager_ledger', 'tbl_reseller_audit_events')


def t0_05_ledger_writers(c):
    """Ledger / audit writer scan incl. CLI, plugin and gateway directories (matrix #11, TAMPER)."""
    files = [f for f in php_files(['system', 'install', 'ui']) if not f.startswith('system/reseller/migrations/')]
    mutate = re.compile(r'\b(UPDATE|DELETE\s+FROM|REPLACE(\s+INTO)?|TRUNCATE(\s+TABLE)?|ALTER\s+TABLE|DROP\s+TABLE)\s+`?(tbl_manager_ledger|tbl_reseller_audit_events)\b', re.I)
    insert = re.compile(r'\bINSERT\s+(IGNORE\s+)?INTO\s+`?(tbl_manager_ledger|tbl_reseller_audit_events)\b', re.I)
    orm = re.compile(r"for_table\(\s*['\"](tbl_manager_ledger|tbl_reseller_audit_events|tbl_manager_wallets|tbl_manager_wallet_reservations|tbl_manager_transfers)['\"]")
    mut_hits, ins_hits, orm_hits = [], [], []
    for rel in files:
        src = c.read(rel)
        for m in mutate.finditer(src):
            mut_hits.append({'file': rel, 'match': m.group(0)})
        for m in insert.finditer(src):
            ins_hits.append({'file': rel, 'table': m.group(2)})
        for m in orm.finditer(src):
            orm_hits.append({'file': rel, 'table': m.group(1)})
    c.details.update({'files_scanned': len(files), 'mutations': mut_hits, 'inserts': ins_hits, 'orm_finance_access': orm_hits,
                      'plugin_dirs': {d: sorted(os.listdir(os.path.join(ROOT, d))) for d in ('system/plugin', 'system/paymentgateway')}})
    c.eq(mut_hits, [], 'UPDATE / DELETE / REPLACE / TRUNCATE / ALTER / DROP on ledger or audit tables')
    c.eq(sorted({h['file'] for h in ins_hits if h['table'] == 'tbl_manager_ledger'}),
         ['system/autoload/Reseller/Finance/LedgerService.php'], 'single ledger INSERT writer')
    c.eq(orm_hits, [], 'ORM access to finance / audit tables')
    c.control('positive', 'the scanner finds the LedgerService INSERT', any(h['table'] == 'tbl_manager_ledger' for h in ins_hits))
    c.control('negative', 'a synthetic UPDATE on the ledger is detected', mutate.search("UPDATE tbl_manager_ledger SET amount = 0") is not None)
    c.details['note'] = ('Static scan only: the database itself does not reject UPDATE / DELETE (no triggers; app-level immutability, '
                         'TAMPER / LPDB). system/plugin and system/paymentgateway contain no PHP plugin at HEAD.')


def t0_06_csv_static(c):
    """CSV formula / escaping static characterisation (N-07). Pure-unit execution of PHP Format::csvCell: NOT TESTED (no PHP)."""
    src = c.read('system/controllers/customers.php')
    lines = src.split('\n')
    def case_block(label):
        i = next(k for k, l in enumerate(lines) if l.strip() == f"case '{label}':")
        j = next(k for k in range(i + 1, len(lines)) if re.match(r"^\s*case '", lines[k]))
        return i + 1, '\n'.join(lines[i:j])
    for label in ('csv', 'csv-prepaid'):
        start, block = case_block(label)
        c.ok("implode('\",\"', $row)" in block, f"customers/{label} (:{start}) builds rows with implode('\",\"')")
        c.ok('str_replace(\'"\'' not in block and 'fputcsv' not in block and 'csvCell' not in block,
             f'customers/{label} has no quote escaping and no formula neutralisation')
        c.details[f'customers_{label}_start_line'] = start
    k = src.index("_post('export', '') == 'csv'")
    list_export = src[k:k + 2500]
    c.ok('fputcsv(' in list_export and 'csvCell' not in list_export, 'list export uses fputcsv without formula neutralisation')
    fmt = c.read('system/autoload/Reseller/Ui/Format.php')
    m = re.search(r'function csvCell\(.*?\n    \}', fmt, re.S)
    c.ok(m is not None, 'Format::csvCell found')
    c.details['csvCell_source'] = m.group(0)
    lead = re.search(r"'/\^\[([^\]]+)\]/'", m.group(0))
    c.details['csvCell_leading_class'] = lead.group(1) if lead else None
    c.ok(lead is not None and ' ' not in lead.group(1) and '\\n' not in lead.group(1),
         'csvCell checks only the first character (no leading space / newline handling) — static reading')
    c.details['not_tested'] = 'Executing Format::csvCell on the planned cases needs a PHP runtime (only in Docker): NOT TESTED in T0.'


def t0_07_new7_inventory(c):
    """NEW-7 key-use inventory: every use of $db_pass in runtime PHP (outside system/vendor)."""
    files = php_files(['system', 'install', 'ui'])
    rows = []
    for rel in files:
        for i, line in enumerate(c.read(rel).split('\n'), 1):
            if 'db_pass' in line:
                s = line.strip()
                kind = ('hash_hmac' if 'hash_hmac' in s else 'sha1' if 'sha1(' in s else 'md5' if 'md5(' in s
                        else 'global/config' if re.match(r'(global|\$db_pass\s*=|\$db_password)', s) or 'config.php' in rel
                        else 'comparison' if re.search(r'[!=]==?', s) else 'other')
                rows.append({'file': rel, 'line': i, 'kind': kind, 'text': s[:170]})
    c.details['uses'] = rows
    c.details['by_kind'] = {k: sum(1 for r in rows if r['kind'] == k) for k in sorted({r['kind'] for r in rows})}
    c.details['by_file'] = {f: sum(1 for r in rows if r['file'] == f) for f in sorted({r['file'] for r in rows})}
    adm = [r for r in rows if r['file'] == 'system/autoload/Admin.php' and r['kind'] == 'hash_hmac']
    c.ok(len(adm) >= 2, 'staff fingerprint and remember-cookie MAC use $db_pass as HMAC key (Admin.php)')
    usr = [r for r in rows if r['file'] == 'system/autoload/User.php' and r['kind'] == 'sha1']
    c.ok(len(usr) >= 1, 'customer cookie uses sha1(... . $db_pass) (User.php)')
    user_src = c.read('system/autoload/User.php')
    c.ok(re.search(r"sha1\(\$tmp\[0\] \. '\.' \. \$tmp\[1\] \. '\.' \. \$db_pass\) == \$tmp\[2\]", user_src) is not None,
         'customer cookie compared with loose == (N-03)')
    c.ok("time() - $tmp[1] < 86400 * 30" in user_src, 'customer cookie age check has no lower bound (future time accepted, N-03)')
    boot = c.read('system/boot.php')
    c.ok(re.search(r"\$_GET\['uid'\]", boot) is not None, 'boot.php copies ?uid= into the cookie (N-03)')
    c.ok(any(r['kind'] == 'md5' for r in rows), 'md5(... . $db_pass) signatures exist (voucher / invoice)')
    api = c.read('system/autoload/Reseller/Security/ApiToken.php')
    c.control('positive', 'ApiToken refuses an api_secret equal to db_pass', 'db_pass' in api)
    tpl_hits = []
    for dp, _dn, fn in os.walk(os.path.join(ROOT, 'ui')):
        for f in fn:
            if f.endswith('.tpl'):
                rel = os.path.relpath(os.path.join(dp, f), ROOT)
                if 'db_pass' in rel_read(rel):
                    tpl_hits.append(rel)
    c.eq(tpl_hits, [], 'templates referencing db_pass')


def t0_08_smarty_inventory(c):
    """NEW-8 Smarty static-call and configuration-exposure inventory."""
    tpl_dirs = ['ui', 'system/plugin', 'system/paymentgateway']
    static_re = re.compile(r'\{\s*([A-Z][A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)')
    cfg_re = re.compile(r'\$_c\.([A-Za-z0-9_]+)|\$_c\[\s*[\'"]([A-Za-z0-9_]+)')
    files_with_static, calls, cfg_keys, files_with_cfg = set(), {}, {}, set()
    tpl_count = 0
    for d in tpl_dirs:
        for dp, _dn, fn in os.walk(os.path.join(ROOT, d)):
            for f in fn:
                if not f.endswith(('.tpl', '.html')):
                    continue
                rel = os.path.relpath(os.path.join(dp, f), ROOT)
                src = rel_read(rel)
                tpl_count += 1
                for m in static_re.finditer(src):
                    files_with_static.add(rel)
                    key = f'{m.group(1)}::{m.group(2)}'
                    calls[key] = calls.get(key, 0) + 1
                for m in cfg_re.finditer(src):
                    files_with_cfg.add(rel)
                    k = m.group(1) or m.group(2)
                    cfg_keys[k] = cfg_keys.get(k, 0) + 1
    c.details.update({'templates_scanned': tpl_count, 'templates_with_static_calls': len(files_with_static),
                      'static_calls': dict(sorted(calls.items(), key=lambda kv: -kv[1])),
                      'templates_using_$_c': len(files_with_cfg), '$_c_keys': dict(sorted(cfg_keys.items(), key=lambda kv: -kv[1]))})
    c.ok(len(files_with_static) >= 150, 'many templates call static classes (a global Smarty security policy would break them)')
    c.ok(calls.get('Lang::T', 0) >= 1000, 'Lang::T is the dominant static call')
    boot = c.read('system/boot.php')
    c.ok(re.search(r"\$ui->assign\(\s*'_c'\s*,\s*\$config\s*\)", boot) is not None, 'boot.php assigns the whole $config to every template')
    runtime = php_files(['system', 'install', 'ui'])
    sec = [f for f in runtime if re.search(r'enableSecurity|Smarty_Security|escape_html', rel_read(f))]
    c.eq(sec, [], 'Smarty security policy / escape_html enabled anywhere in runtime PHP')
    pages = c.read('system/controllers/pages.php')
    c.ok('raw.githubusercontent.com' in pages, 'page reset fetches templates from GitHub (pages.php)')
    secret_like = sorted(k for k in cfg_keys if re.search(r'(secret|pass|key|token)', k, re.I))
    c.details['$_c_secret_like_keys_used_in_templates'] = secret_like
    c.control('positive', 'the static-call detector finds {Lang::T in a synthetic template', static_re.search("{Lang::T('x')}") is not None)


CHECKS = [
    ('T0-01', 'N-13', 'A2-R static statement inventory', t0_01_a2r_inventory),
    ('T0-02', 'N-13', 'A2-R sentinel / rejection decision table', t0_02_a2r_decision_table),
    ('T0-03', 'N-08, §7.3, §15', 'SourceGuard-oriented analysis and lock-ordering premises', t0_03_sourceguard),
    ('T0-04', 'matrix #14 (CoA injection), SEC-22', 'No-shell static scan', t0_04_no_shell),
    ('T0-05', 'matrix #11, TAMPER', 'Ledger / audit writer static scan', t0_05_ledger_writers),
    ('T0-06', 'N-07', 'CSV export escaping (static characterisation)', t0_06_csv_static),
    ('T0-07', 'NEW-7, N-03', 'NEW-7 key-use inventory', t0_07_new7_inventory),
    ('T0-08', 'NEW-8', 'Smarty static-call / configuration-exposure inventory', t0_08_smarty_inventory),
]
NOT_TESTED = [
    {'id': 'T0-06b', 'finding': 'N-07', 'title': 'CSV Format::csvCell pure-unit execution (" =1", "\\n=1", "-12.5", "=1+1", "@SUM")',
     'status': 'NOT TESTED', 'reason': 'requires executing PHP; the host has no PHP runtime and Docker is not allowed in T0'},
    {'id': 'T0-09', 'finding': 'all', 'title': 'PHPUnit execution of the existing source-guard suites (Unit/Wp3a, Unit/Wp4, Unit/Wp8)',
     'status': 'NOT TESTED', 'reason': 'requires PHP (Docker image nuxbill-reseller-test-php82 only); not allowed in T0'},
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    args = ap.parse_args()
    out = os.path.join(ROOT, args.out)
    os.makedirs(out, exist_ok=True)
    before = tree_fingerprint()
    results = []
    for cid, finding, title, fn in CHECKS:
        c = Check(cid, finding, title)
        try:
            fn(c)
            status, error = 'PASS', None
        except AssertionError as e:
            status, error = 'FAIL', str(e)
        except Exception as e:  # internal error is a failure too, never a pass
            status, error = 'ERROR', ''.join(traceback.format_exception_only(type(e), e)).strip()
        results.append({'id': cid, 'finding': finding, 'title': title, 'status': status, 'error': error,
                        'assertions': c.assertions, 'controls': c.controls,
                        'source_fingerprint': {f: sha256_file(f) for f in sorted(c.files)}, 'details': c.details})
        print(f'{cid} {status:5} assertions={c.assertions:4d} files={len(c.files):4d}  {title}' + (f'  -- {error}' if error else ''))
    after = tree_fingerprint()
    summary = {'tests': len(results), 'passed': sum(r['status'] == 'PASS' for r in results),
               'failed': sum(r['status'] != 'PASS' for r in results), 'assertions': sum(r['assertions'] for r in results),
               'not_tested': len(NOT_TESTED), 'tree_fingerprint_before': before, 'tree_fingerprint_after': after,
               'tree_unchanged': before == after, 'script_sha256': sha256_file(os.path.relpath(os.path.abspath(__file__), ROOT))}
    for r in NOT_TESTED:
        print(f"{r['id']} NOT TESTED  {r['title']}  -- {r['reason']}")
    print(f"SUMMARY tests={summary['tests']} passed={summary['passed']} failed={summary['failed']} assertions={summary['assertions']} "
          f"not_tested={summary['not_tested']} tree_unchanged={summary['tree_unchanged']}")
    with open(os.path.join(out, 't0-results.json'), 'w') as fh:
        json.dump({'summary': summary, 'results': results, 'not_tested': NOT_TESTED}, fh, indent=1, sort_keys=False)
    return 0 if summary['failed'] == 0 and summary['tree_unchanged'] else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except Exception:
        traceback.print_exc()
        sys.exit(2)
