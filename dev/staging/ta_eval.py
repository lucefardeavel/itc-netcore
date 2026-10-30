#!/usr/bin/env python3
"""Pass / fail evaluation of the mechanical target-acceptance tests (docs/V1_TARGET_ACCEPTANCE.md).

  dev/staging/ta_eval.py TA-01 --preflight FILE [--health FILE]
  dev/staging/ta_eval.py TA-02 --offsets FILE            lines "<host> <offset_seconds> <kind>" (kind: core | nas)
  dev/staging/ta_eval.py TA-09 --load JSONL [--pass-seconds N --every N] [--freeradius-log FILE]
  dev/staging/ta_eval.py TA-10 --headers FILE            output of `curl -sI` (and a second `-I` with a login cookie) against the HTTPS front
  dev/staging/ta_eval.py selftest

Prints PASS / FAIL (and WARN notes) with the reason of every failed criterion and exits 0 only on PASS. It reads the evidence files; it never
connects to anything. A test that was not run is NOT_RUN (no file): this tool is never asked to evaluate it, and `docs/V1_TARGET_ACCEPTANCE.md`
section 3 stays NOT_RUN until a real run is evaluated here and its evidence file is attached.
"""
import argparse
import json
import re
import sys


def verdict(name, fails, warns):
    for w in warns:
        print(f'WARN  {name}: {w}')
    for f in fails:
        print(f'FAIL  {name}: {f}')
    print(f"{'PASS' if not fails else 'FAIL'}  {name}")
    return 0 if not fails else 1


def ta01(preflight, health=None):
    fails, warns = [], []
    text = open(preflight, errors='replace').read()
    checks = re.findall(r'^(PASS|WARN|FAIL)\s+(\S+)\s+(.*)$', text, re.M)
    if not checks:
        fails.append('no preflight check lines found (was preflight.php run?)')
    for status, name, detail in checks:
        if status == 'FAIL':
            fails.append(f'preflight {name}: {detail}')
        elif status == 'WARN' and name == 'stage.https':
            # V1_TARGET_ACCEPTANCE TA-01: a stage.https WARN is not acceptable on the target
            fails.append(f'preflight {name} WARN is not acceptable on the target: {detail}')
        elif status == 'WARN':
            warns.append(f'preflight {name}: {detail}')
    if 'preflight=FAIL' in text or not re.search(r'^preflight=(PASS|OK)', text, re.M) and not fails:
        if 'preflight=FAIL' in text and not fails:
            fails.append('preflight reports FAIL')
    must = ['keys.app_secret', 'isolation', 'time.sql_session_zone', 'time.clock_skew', 'time.nas_clock', 'schema', 'radius_checks', 'identity.collisions', 'grants', 'nas.verification']
    seen = {n for _, n, _ in checks}
    for m in must:
        if m not in seen:
            fails.append(f'required preflight check missing: {m}')
    if health:
        h = json.loads(open(health, errors='replace').read().strip().splitlines()[-1]) if open(health).read().strip().startswith('{') else None
        if h is None:
            fails.append('health output is not JSON')
        else:
            if h.get('status') in ('CRITICAL', 'UNKNOWN'):
                fails.append(f"health status {h.get('status')}")
            for c in h.get('checks', []):
                if c.get('status') == 'CRITICAL':
                    fails.append(f"health {c.get('check')}: {c.get('detail')}")
                elif c.get('status') == 'WARNING':
                    warns.append(f"health {c.get('check')}: {c.get('detail')} (explain it in the evidence)")
    return verdict('TA-01', fails, warns)


def ta02(offsets):
    fails, warns, n = [], [], 0
    for line in open(offsets):
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        host, off, kind = line.split()[:3]
        n += 1
        # core 5 s; NAS 10 s: the hard limit of the session-end cut-off (B-L08, docs/TIME_CONTRACT.md, V1_TARGET_ACCEPTANCE TA-02)
        if kind not in ('core', 'nas'):
            fails.append(f'{host}: unknown kind {kind!r} (core | nas)')
            continue
        limit = 5.0 if kind == 'core' else 10.0
        if abs(float(off)) > limit:
            fails.append(f'{host} ({kind}) offset {off} s exceeds {limit:g} s')
        elif abs(float(off)) > limit / 2:
            warns.append(f'{host} ({kind}) offset {off} s is above half of the {limit:g} s limit')
    if n < 4:
        fails.append('fewer than 4 hosts measured (application, database, FreeRADIUS and at least one NAS are required)')
    if not any(l.split()[2] == 'nas' for l in open(offsets) if l.strip() and not l.startswith('#')):
        fails.append('no NAS measured')
    return verdict('TA-02', fails, warns)


def ta09(load, pass_seconds=None, every=5, frlog=None):
    fails, warns, rows = [], [], []
    for line in open(load):
        try:
            d = json.loads(line)
        except ValueError:
            continue
        if d.get('case') == 'acct-load':
            rows.append(d)
    if not rows:
        fails.append('no acct-load window in the evidence')
    for d in rows:
        tag = f"{d['subscribers']} subs / I={d['interim_s']}s"
        if d['lost'] != 0:
            fails.append(f'{tag}: {d["lost"]} lost accounting request(s)')
        if d['latency_ms']['p99'] >= 250:
            fails.append(f"{tag}: p99 latency {d['latency_ms']['p99']} ms >= 250 ms")
        passmax = d.get('enforcer_pass_s', {}).get('max', 0)
        if passmax >= every:
            fails.append(f'{tag}: enforcer pass {passmax} s >= --every {every}')
        if d['db'].get('aborted_connects', 0):
            fails.append(f'{tag}: refused database connections')
        if d['achieved_rate_per_s'] < 0.95 * d['target_rate_per_s']:
            warns.append(f"{tag}: achieved {d['achieved_rate_per_s']}/s of {d['target_rate_per_s']}/s (the generator, not the target, may be the limit)")
    if pass_seconds is not None and pass_seconds >= every:
        fails.append(f'measured enforcer pass {pass_seconds} s >= --every {every}')
    if frlog:
        bad = [l.strip() for l in open(frlog, errors='replace') if re.search(r'No connections available|max connection limit|exhaust', l, re.I)]
        if bad:
            fails.append(f'FreeRADIUS log shows pool exhaustion ({len(bad)} line(s)): {bad[0][:120]}')
    return verdict('TA-09', fails, warns)


def ta10(headers):
    fails, warns = [], []
    text = open(headers, errors='replace').read()
    low = text.lower()
    if 'http/' not in low:
        fails.append('no HTTP response headers in the evidence')
    need = {'strict-transport-security': 'HSTS', 'x-content-type-options: nosniff': 'nosniff', 'x-frame-options': 'anti-framing (or CSP frame-ancestors)'}
    for k, what in need.items():
        if k not in low and not (what.startswith('anti-framing') and 'frame-ancestors' in low):
            fails.append(f'missing {what}')
    cookies = [l for l in text.splitlines() if l.lower().startswith('set-cookie:')]
    if not cookies:
        warns.append('no Set-Cookie in the evidence (capture a login response to check the cookie flags)')
    for c in cookies:
        cl = c.lower()
        for flag in ('httponly', 'secure', 'samesite'):
            if flag not in cl:
                fails.append(f'cookie without {flag}: {c.split("=")[0]}')
    if re.search(r'^http/\S+ 200', low, re.M) and 'location: http://' in low:
        fails.append('redirect to plain http')
    return verdict('TA-10', fails, warns)


def selftest():
    import os
    import tempfile
    d = tempfile.mkdtemp()

    def w(name, body):
        p = os.path.join(d, name)
        open(p, 'w').write(body)
        return p
    good_pre = 'PASS  keys.app_secret x\nPASS  isolation x\nPASS  time.sql_session_zone x\nPASS  time.clock_skew x\nPASS  time.nas_clock x\nPASS  schema x\nPASS  radius_checks x\nPASS  identity.collisions x\nPASS  grants x\nPASS  nas.verification x\npreflight=PASS\n'
    bad_pre = good_pre.replace('PASS  grants', 'FAIL  grants').replace('preflight=PASS', 'preflight=FAIL (1 failing check(s))')
    assert ta01(w('p1', good_pre)) == 0
    assert ta01(w('p2', bad_pre)) == 1
    assert ta01(w('p3', 'nothing')) == 1
    assert ta01(w('p4', good_pre.replace('preflight=PASS', 'WARN  stage.https APP_URL is not https\npreflight=PASS'))) == 1  # never on the target
    assert ta01(w('p5', good_pre.replace('preflight=PASS', 'WARN  other.check note\npreflight=PASS'))) == 0  # another WARN is reported, not failed
    assert ta02(w('o1', 'app 0.2 core\ndb 0.1 core\nfr 0.3 core\nnas1 3 nas\n')) == 0
    assert ta02(w('o2', 'app 0.2 core\ndb 7 core\nfr 0.3 core\nnas1 3 nas\n')) == 1
    assert ta02(w('o3', 'app 0.2 core\ndb 0.1 core\nfr 0.3 core\nnas1 70 nas\n')) == 1
    assert ta02(w('o4', 'app 0.2 core\ndb 0.1 core\nfr 0.3 core\nnas1 11 nas\n')) == 1  # above the 10 s NAS limit
    assert ta02(w('o5', 'app 0.2 core\ndb 6 cor\nfr 0.3 core\nnas1 3 nas\n')) == 1  # an unknown kind never gets the NAS limit
    row = {'case': 'acct-load', 'subscribers': 2000, 'interim_s': 30, 'lost': 0, 'latency_ms': {'p99': 40}, 'enforcer_pass_s': {'max': 0.3}, 'db': {'aborted_connects': 0}, 'achieved_rate_per_s': 66, 'target_rate_per_s': 66.7}
    assert ta09(w('l1', json.dumps(row) + '\n')) == 0
    assert ta09(w('l2', json.dumps({**row, 'lost': 2}) + '\n')) == 1
    assert ta09(w('l3', json.dumps({**row, 'enforcer_pass_s': {'max': 6}}) + '\n')) == 1
    assert ta09(w('l4', json.dumps(row) + '\n'), frlog=w('fr', 'Error: No connections available and at max connection limit\n')) == 1
    hdr = 'HTTP/2 200\nstrict-transport-security: max-age=31536000\nx-content-type-options: nosniff\nx-frame-options: DENY\nset-cookie: s=1; Path=/; HttpOnly; Secure; SameSite=Lax\n'
    assert ta10(w('h1', hdr)) == 0
    assert ta10(w('h2', hdr.replace('; Secure', ''))) == 1
    assert ta10(w('h3', 'HTTP/2 200\n')) == 1
    print('selftest OK')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('test')
    ap.add_argument('--preflight')
    ap.add_argument('--health')
    ap.add_argument('--offsets')
    ap.add_argument('--load')
    ap.add_argument('--pass-seconds', type=float)
    ap.add_argument('--every', type=float, default=5)
    ap.add_argument('--freeradius-log')
    ap.add_argument('--headers')
    a = ap.parse_args()
    if a.test == 'selftest':
        selftest()
        return 0
    if a.test == 'TA-01':
        return ta01(a.preflight, a.health)
    if a.test == 'TA-02':
        return ta02(a.offsets)
    if a.test == 'TA-09':
        return ta09(a.load, a.pass_seconds, a.every, a.freeradius_log)
    if a.test == 'TA-10':
        return ta10(a.headers)
    print('no evaluator for this test: it is judged against the explicit criteria of docs/V1_TARGET_ACCEPTANCE.md')
    return 2


if __name__ == '__main__':
    sys.exit(main())
