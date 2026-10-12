#!/usr/bin/env python3
"""WP8 H-2 R-H2-B FEASIBILITY GATES - lab tooling only (owner authorization 2026-10-11 "H-2 Remediation Architecture &
Feasibility Gate"; R-H2-B = an atomic global Hotspot admission reservation). Never touches install/, system/,
production or AVAIA; the PROJECT FreeRADIUS policy is unchanged - the prototype policy is a lab-only site variant
generated at run time (marker wp8-h1-site, like h1_gate.py) and the prototype tables exist only in the disposable lab
database. Requires the H-2 lab (lab.sh up, option_g_gate.py on shipped, a2r_gate.py threaded on, lab.sh up-chr2,
a2r_gate.py chr2 setup, h2_gate.py setup).

  h2b_gate.py run CASE...     evidence -> $NUXR_WP8_STATE/evidence/h2b.jsonl
  h2b_gate.py site MODE       project | hsres (prototype) | hsres_fault (prototype + lab SQL faults)
  h2b_gate.py schema          create the PROTOTYPE tables in the lab database (proposal; never install/)

Secrets: the SNMP community, the RouterOS password and the RADIUS secret are read from $NUXR_WP8_STATE files and passed
only through 0600 files or stdin - never as process arguments, never printed."""
import hashlib, json, os, re, secrets, socket, subprocess, sys, tempfile, threading, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import a2r_gate as g
import h1_gate as h
import h2_gate as h2
import checkrad_gate as cg
from a2r_gate import ag, sql, sqlrows, hexs, until, FR

STATE = g.STATE
EVID = f'{STATE}/evidence/h2b.jsonl'
NAS = {1: '10.88.0.2', 2: '10.88.0.3'}
OID_HS = '1.3.6.1.4.1.14988.1.1.5.1.1'            # MIKROTIK-MIB Hotspot active table (col 3 user, 5 IP, 6 MAC, 7 uptime)
OID_UPTIME = '1.3.6.1.2.1.1.3.0'
MODE = ['?']


def record(case, data):
    os.makedirs(os.path.dirname(EVID), mode=0o700, exist_ok=True)
    line = ag._redact(json.dumps({'t': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'case': case, 'site_mode': MODE[0], **data},
                                 default=str, ensure_ascii=False))
    with open(EVID, 'a') as f:
        f.write(line + '\n')
    print(f'[h2b] {MODE[0]} {case}: ' + json.dumps(data.get('verdict', ''), default=str, ensure_ascii=False)[:900], flush=True)


# ------------------------------------------------------------------------------------------------ Gate A methods
def fr_pid():
    return int(cg.sh(['docker', 'inspect', '-f', '{{.State.Pid}}', FR]).stdout.strip())


def snmp_sessions(nas_ip, timeout=1):
    """the verifier's view by SNMP from the FreeRADIUS container (community in a private 0600 net-snmp config file):
    health probe first (sysUpTime), then the Hotspot active table. Returns (ok, [(user, mac, ip)], error, ms)."""
    t0 = time.time()
    d = '/tmp/h2bsnmp.' + secrets.token_hex(4)
    cg.sh(['docker', 'exec', '-i', FR, 'sh', '-c', f'umask 077; mkdir -p {d}; cat > {d}/snmp.conf'],
          inp=f'defCommunity {cg.community()}\ndefVersion 2c\nhexOutputLength 0\nmibs :\n')
    env = ['-e', f'SNMPCONFPATH={d}', '-e', f'SNMP_PERSISTENT_DIR={d}', '-e', 'MIBS=']
    try:
        hp = cg.sh(['docker', 'exec', *env, FR, 'snmpget', '-r0', f'-t{timeout}', '-On', nas_ip, OID_UPTIME], check=False)
        if hp.returncode != 0 or 'Timeticks' not in hp.stdout:
            return False, [], 'health: ' + (hp.stderr or hp.stdout).strip()[-120:], round((time.time() - t0) * 1000)
        w = cg.sh(['docker', 'exec', *env, FR, 'snmpbulkwalk', '-r0', f'-t{timeout}', '-On', '-Ox', '-Cr50', nas_ip, OID_HS], check=False)
        if w.returncode != 0:
            return False, [], 'walk: ' + w.stderr.strip()[-120:], round((time.time() - t0) * 1000)
    finally:
        cg.sh(['docker', 'exec', FR, 'rm', '-rf', d], check=False)
    rows = {}
    for l in w.stdout.splitlines():
        m = re.match(r'^\.' + re.escape(OID_HS) + r'\.(\d+)\.(\d+) = (?:Hex-STRING|STRING|IpAddress): ?(.*)$', l)
        if m:
            rows.setdefault(m.group(2), {})[m.group(1)] = m.group(3).strip().strip('"')
    out = []
    for idx, c in rows.items():
        user = bytes.fromhex(c.get('3', '').replace(' ', '')).decode('utf-8', 'replace') if re.fullmatch(r'[0-9A-Fa-f ]*', c.get('3', '')) else c.get('3', '')
        mac = ':'.join(c.get('6', '').split()).upper()
        out.append((user, mac, c.get('5')))
    return True, sorted(out), None, round((time.time() - t0) * 1000)


def _in_fr_netns(fn):
    """run fn() with the current thread inside the FreeRADIUS network namespace (sockets created there stay there)"""
    me, tgt = os.open('/proc/thread-self/ns/net', os.O_RDONLY), os.open(f'/proc/{fr_pid()}/ns/net', os.O_RDONLY)
    try:
        os.setns(tgt, os.CLONE_NEWNET)
        return fn()
    finally:
        os.setns(me, os.CLONE_NEWNET)
        os.close(me)
        os.close(tgt)


def api_sessions(nas_ip, timeout=2.0):
    """RouterOS API (8728) from the FreeRADIUS network namespace, admin login (lab; production: a read-only API user)"""
    t0 = time.time()
    try:
        r = _in_fr_netns(lambda: g.Ros(nas_ip, 8728, timeout=timeout))
        r.login('admin', g.SEC['NUXR_WP8_CHR_PASSWORD'])
        try:
            rows = r.rows(['/ip/hotspot/active/print'])
        finally:
            r.close()
    except Exception as ex:
        return False, [], f'{type(ex).__name__}: {str(ex)[:100]}', round((time.time() - t0) * 1000)
    return True, sorted((x.get('user'), (x.get('mac-address') or '').upper(), x.get('address')) for x in rows), None, round((time.time() - t0) * 1000)


def rest_sessions(nas_ip, timeout=2):
    """RouterOS REST (www service, plain HTTP in the lab) from the FreeRADIUS network namespace; credentials in a 0600
    curl config file (never argv)"""
    t0 = time.time()
    fd, cfg = tempfile.mkstemp(prefix='h2brest.')
    try:
        os.write(fd, f'user = "admin:{g.SEC["NUXR_WP8_CHR_PASSWORD"]}"\n'.encode())
        os.close(fd)
        p = subprocess.run(['nsenter', '-t', str(fr_pid()), '-n', 'curl', '-sS', '-m', str(timeout), '-K', cfg, '-w', '\n%{http_code}',
                            f'http://{nas_ip}/rest/ip/hotspot/active'], capture_output=True, text=True)
    finally:
        os.remove(cfg)
    body, _, code = p.stdout.rpartition('\n')
    if p.returncode != 0 or code != '200':
        return False, [], f'rc={p.returncode} http={code} {p.stderr.strip()[-100:]}', round((time.time() - t0) * 1000)
    rows = json.loads(body or '[]')
    return True, sorted((x.get('user'), (x.get('mac-address') or '').upper(), x.get('address')) for x in rows), None, round((time.time() - t0) * 1000)


METHODS = {'snmp': snmp_sessions, 'api': api_sessions, 'rest': rest_sessions}


def observe(nas_ip, user):
    out = {}
    for k, fn in METHODS.items():
        ok, rows, err, ms = fn(nas_ip)
        out[k] = {'ok': ok, 'sessions': [list(r) for r in rows if r[0] == user], 'error': err, 'ms': ms}
    return out


def block_mgmt(nas, ports=(161, 8728, 80)):
    """drop the verifier's management traffic (from 10.88.0.10) on the NAS input chain; RADIUS stays open"""
    ids = []
    for p in ports:
        words = ['/ip/firewall/filter/add', '=chain=input', '=protocol=' + ('udp' if p == 161 else 'tcp'), f'=dst-port={p}',
                 '=src-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_h2b']
        if nas == 1:
            ids.append(g.ros_add(*words))
        else:
            h2.r2(words)
            ids.append([x for x in h2.r2(['/ip/firewall/filter/print']) if x.get('comment') == 'nuxr_wp8_h2b'][-1]['.id'])
    return ids


def unblock_mgmt(nas, ids):
    for i in ids:
        (g.unblock(i) if nas == 1 else h2.r2(['/ip/firewall/filter/remove', '=.id=' + i]))


CASES = {}


def case(fn):
    CASES[fn.__name__] = fn
    return fn


@case
def gate_a():
    """Gate A - can a verifier tell a LIVE Hotspot session from an ended one, per NAS, by user + MAC? Scenarios: live,
    ended, Accounting-Start lost (live, no radacct row), Accounting-Stop lost (radacct open, NAS has nothing), management
    unreachable (must be an ERROR, never "absent"), two sessions under one name; both NAS"""
    u, pw = h2.subscriber('gA', 2)
    out = {}
    try:
        h2.fresh(['c2', 'c4', 'c7'])
        a = h2.login('c2', u, pw)
        h2.wait_open(u, NAS[1], 1)
        out['live_chr1'] = {'login': a['state'], 'mac': h2.mac_of('c2'), 'radacct_open': len(h2.open_rows(u)), **observe(NAS[1], u)}
        b = h2.login('c7', u, pw)
        h2.wait_open(u, NAS[2], 1)
        out['live_chr2'] = {'login': b['state'], 'mac': h2.mac_of('c7'), **observe(NAS[2], u)}
        c = h2.login('c4', u, pw)
        h2.wait_open(u, NAS[1], 2)
        out['two_sessions_same_name_chr1'] = {'login': c['state'], 'macs': sorted([h2.mac_of('c2'), h2.mac_of('c4')]), **observe(NAS[1], u)}
        ids = block_mgmt(1)
        try:
            out['management_unreachable_chr1'] = observe(NAS[1], u)
        finally:
            unblock_mgmt(1, ids)
        for cl in ('c2', 'c4', 'c7'):
            h2.logout(cl)
        until(lambda: h2.live(u)['total'] == 0 or None, 20, 0.5)
        out['ended_both'] = {'radacct_open': len(h2.open_rows(u)), 'chr1': observe(NAS[1], u), 'chr2': observe(NAS[2], u)}
        # Accounting-Start lost: live on the NAS, no radacct row
        rid = g.block_acct()
        try:
            h2.fresh(['c2'])
            d = h2.login('c2', u, pw)
            time.sleep(3)
            out['start_lost_chr1'] = {'login': d['state'], 'radacct_open': len(h2.open_rows(u)), **observe(NAS[1], u)}
            # Accounting-Stop lost: the session ends while accounting is still dropped -> radacct row stays open
        finally:
            g.unblock(rid)
        h2.fresh(['c2'])
        e = h2.login('c2', u, pw)
        h2.wait_open(u, NAS[1], 1)
        rid = g.block_acct()
        try:
            h2.logout('c2')
            until(lambda: h2.live(u)['chr1'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            g.unblock(rid)
        out['stop_lost_chr1'] = {'login': e['state'], 'radacct_open': len(h2.open_rows(u)), **observe(NAS[1], u)}
    finally:
        for cl in ('c2', 'c4', 'c7'):
            h2.logout(cl)
        h.unprovision(u)
    def sees(o, m):
        return sorted(s[1] for s in o[m]['sessions']) if o[m]['ok'] else 'ERROR'
    verdict = {}
    for m in METHODS:
        verdict[m] = {
            'live_chr1': sees(out['live_chr1'], m) == [out['live_chr1']['mac']],
            'live_chr2': sees(out['live_chr2'], m) == [out['live_chr2']['mac']],
            'two_sessions': sees(out['two_sessions_same_name_chr1'], m) == out['two_sessions_same_name_chr1']['macs'],
            'unreachable_is_error': not out['management_unreachable_chr1'][m]['ok'],
            'ended_absent': sees(out['ended_both']['chr1'], m) == [] and sees(out['ended_both']['chr2'], m) == [],
            'start_lost_still_seen_live': sees(out['start_lost_chr1'], m) == [h2.mac_of('c2')],
            'stop_lost_seen_absent': sees(out['stop_lost_chr1'], m) == [],
            'ms_live': out['live_chr1'][m]['ms'], 'ms_unreachable': out['management_unreachable_chr1'][m]['ms']}
        verdict[m]['pass'] = all(v for k, v in verdict[m].items() if not k.startswith('ms_'))
    record('H2B.gate_a', {'verdict': verdict, 'detail': out})
    return verdict


# ================================================================================================ prototype (lab only)
AGE = '"%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}"'


def _rej(tag, ind='\t\t\t'):
    return (f'{ind}update request {{\n{ind}\t&Tmp-String-0 := "nuxr-hsres=reject:{tag}"\n{ind}}}\n'
            f'{ind}attr_filter.access_reject\n{ind}reject\n')


def _upd(lst, attr, val, ind='\t\t'):
    return f'{ind}update {lst} {{\n{ind}\t&{attr} := "{val}"\n{ind}}}\n'


KEY = "UNHEX('%{Tmp-String-3}')"
LIMIT = '%{control:Simultaneous-Use}'
PROTO_POST = (
    '\t\t# ==== LAB PROTOTYPE R-H2-B (dev/wp8/h2b_gate.py) - NOT the project policy: GLOBAL Hotspot admission slots ====\n'
    '\t\tif (&control:Simultaneous-Use !~ /^[1-9][0-9]?$/) {\n' + _rej('limit') + '\t\t}\n'
    + _upd('request', 'Tmp-String-7', '%{Packet-Authentication-Vector}')
    + '\t\tif (&Tmp-String-7 =~ /^0x([0-9a-f]{32})$/) {\n' + _upd('request', 'Tmp-String-7', '%{1}', '\t\t\t') + '\t\t}\n'
    '\t\tif (!&NAS-IP-Address || !&Acct-Session-Id || !&Calling-Station-Id || (&Acct-Session-Id !~ /^[0-9A-Za-z]{1,32}$/) || '
    '(&Calling-Station-Id !~ /^[0-9A-Fa-f:.-]{1,32}$/) || (&Tmp-String-7 !~ /^[0-9a-f]{32}$/)) {\n' + _rej('identity') + '\t\t}\n'
    + '\t\tupdate request {\n\t\t\t&Tmp-String-6 := ' + AGE + '\n\t\t}\n'
    '\t\tif ((&Tmp-String-6 !~ /^[0-9]{1,9}$/) || (<integer>&Tmp-String-6 > 5000)) {\n' + _rej('late') + '\t\t}\n'
    # slots 1..limit exist (idempotent)
    + _upd('request', 'Tmp-String-8', "hs-slots=%{sql:INSERT IGNORE INTO nuxr_hs_admission (username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at) "
           f"SELECT {KEY}, t.n, 'ENDED', MD5(CONCAT(UUID(), RAND(), t.n)), '', '', '', '', '', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) FROM (SELECT a.d * 10 + b.d + 1 AS n FROM "
           "(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a CROSS JOIN "
           f"(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b) t WHERE t.n <= {LIMIT}}}")
    # rlm_sql returns "" for a write that affected no rows (an INSERT IGNORE of existing slots): verify with a SELECT
    + _upd('request', 'Tmp-String-8', f"%{{sql:SELECT CONCAT('s=', COUNT(*)) FROM nuxr_hs_admission WHERE username = {KEY} AND slot <= {LIMIT}}}")
    + '\t\tif (&Tmp-String-8 != "s=%{control:Simultaneous-Use}") {\n' + _rej('sql-slots') + '\t\t}\n'
    + _upd('control', 'Tmp-String-5', '%{randstr:hhhhhhhhhhhhhhhh}')
    + '\t\tif (&control:Tmp-String-5 !~ /^[0-9a-f]{32}$/) {\n' + _rej('token') + '\t\t}\n'
    # an exact retransmission of the claiming request re-uses its claim
    + _upd('control', 'Tmp-String-6', f"%{{sql:SELECT CONCAT('a=', COALESCE((SELECT token FROM nuxr_hs_admission WHERE username = {KEY} AND state = 'PENDING' AND nas = '%{{NAS-IP-Address}}' AND acct_session_id = '%{{Acct-Session-Id}}' AND calling_station_id = '%{{Calling-Station-Id}}' AND request_authenticator = '%{{Tmp-String-7}}' LIMIT 1), '-'))}}")
    + '\t\tif (&control:Tmp-String-6 =~ /^a=([0-9a-f]{32})$/) {\n'
    + _upd('control', 'Tmp-String-5', '%{1}', '\t\t\t') + _upd('control', 'Tmp-String-9', 'attached', '\t\t\t')
    + _upd('request', 'Tmp-String-9', "hs-claim=%{sql:UPDATE nuxr_hs_admission SET attach_count = attach_count + 1, updated_at = UTC_TIMESTAMP(6) WHERE token = '%{control:Tmp-String-5}' AND state = 'PENDING'}", '\t\t\t')
    + '\t\t}\n\t\telsif (&control:Tmp-String-6 == "a=-") {\n'
    + _upd('control', 'Tmp-String-9', 'created', '\t\t\t')
    # v2 (WP8 H2B Gate C finding): the in-use count is a separate, non-locking pre-check (a limit decrease); inside the
    # claim UPDATE it took shared locks on MySQL 8 and deadlocked concurrent claims. The slot rows alone bound concurrency.
    + _upd('request', 'Tmp-String-4', f"%{{sql:SELECT IF(COUNT(*) < {LIMIT}, 'free', 'full') FROM nuxr_hs_admission WHERE username = {KEY} AND state <> 'ENDED'}}", '\t\t\t')
    + '\t\t\tif (&Tmp-String-4 != "free") {\n' + _rej('full-precheck', '\t\t\t\t') + '\t\t\t}\n'
    # THE claim: one conditional UPDATE of the lowest free slot <= limit, and never while limit slots are already in use
    + _upd('request', 'Tmp-String-9', "hs-claim=%{sql:UPDATE nuxr_hs_admission SET prev_token = token, prev_state = state, state = 'PENDING', token = '%{control:Tmp-String-5}', nas = '%{NAS-IP-Address}', "
           "acct_session_id = '%{Acct-Session-Id}', calling_station_id = '%{Calling-Station-Id}', request_authenticator = '%{Tmp-String-7}', session_user = '%{SQL-User-Name}', attach_count = 0, "
           "created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), confirmed_at = NULL, last_acct_at = NULL, ended_at = NULL, end_reason = NULL "
           f"WHERE username = {KEY} AND slot <= {LIMIT} AND state = 'ENDED' "
           "ORDER BY slot LIMIT 1}", '\t\t\t')
    + '\t\t}\n\t\telse {\n' + _rej('sql-attach') + '\t\t}\n'
    '\t\tif (&Tmp-String-9 != "hs-claim=1") {\n' + _rej('full') + '\t\t}\n'
    + '\t\tupdate request {\n\t\t\t&Tmp-String-6 := ' + AGE + '\n\t\t}\n'
    '\t\tif ((&Tmp-String-6 !~ /^[0-9]{1,9}$/) || (<integer>&Tmp-String-6 > 5000)) {\n'
    '\t\t\tif (&control:Tmp-String-9 == "created") {\n'
    + _upd('request', 'Tmp-String-8', "hs-release=%{sql:UPDATE nuxr_hs_admission SET state = 'ENDED', ended_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), end_reason = 'late_claim' WHERE token = '%{control:Tmp-String-5}' AND state = 'PENDING' AND attach_count = 0}", '\t\t\t\t')
    + '\t\t\t}\n' + _rej('late-claim') + '\t\t}\n'
    + _upd('reply', 'Class', '0x6e75786831%{control:Tmp-String-5}')
    + _upd('request', 'Tmp-String-0', 'nuxr-hsres=claim:%{control:Tmp-String-9}')
)
PROTO_ACCT = (
    '\t# ==== LAB PROTOTYPE R-H2-B (dev/wp8/h2b_gate.py): Hotspot slot transitions by Class "nuxh1" token ====\n'
    '\tif (&Class) {\n' + _upd('request', 'Tmp-String-3', '%{Class}')
    + '\t\tif (&Tmp-String-3 =~ /^0x6e75786831([0-9a-f]{32})$/) {\n' + _upd('request', 'Tmp-String-4', '%{1}', '\t\t\t')
    + '\t\t\tif (&Acct-Status-Type == Stop) {\n'
    + _upd('request', 'Tmp-String-5', "hs-acct=%{sql:UPDATE nuxr_hs_admission SET state = 'ENDED', ended_at = UTC_TIMESTAMP(6), last_acct_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), end_reason = 'acct_stop' "
           "WHERE token = '%{Tmp-String-4}' AND nas = '%{NAS-IP-Address}' AND acct_session_id = '%{Acct-Session-Id}' AND calling_station_id = '%{Calling-Station-Id}' AND state IN ('PENDING', 'ACTIVE')}", '\t\t\t\t')
    + '\t\t\t}\n\t\t\telsif ((&Acct-Status-Type == Start) || (&Acct-Status-Type == Interim-Update)) {\n'
    + _upd('request', 'Tmp-String-5', "hs-acct=%{sql:UPDATE nuxr_hs_admission SET state = 'ACTIVE', confirmed_at = COALESCE(confirmed_at, UTC_TIMESTAMP(6)), last_acct_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) "
           "WHERE token = '%{Tmp-String-4}' AND nas = '%{NAS-IP-Address}' AND acct_session_id = '%{Acct-Session-Id}' AND calling_station_id = '%{Calling-Station-Id}' AND state IN ('PENDING', 'ACTIVE')}", '\t\t\t\t')
    + '\t\t\t}\n\t\t}\n\t}\n'
)
# ---- PENDING safety gate (owner 2026-10-11 "Design Approval & Safety Gate"), lab only:
#  hsres2       the final-design candidate = hsres + orphan-accounting detection (a Start / Interim-Update whose nuxh1 token
#               matches no live slot is an audit row: a session the slots do not count) + the stock radacct/checkrad
#               Simultaneous-Use check NOT run for Hotspot (the slots are the Hotspot limit; checkrad cannot see Hotspot)
#  *_hold       + two LAB hold points (SQL SLEEP of ONE attempt, keyed by its Calling-Station-Id, table nuxr_h2b_lab_hold):
#               post_claim = after the claim, before the last age check; pre_reply = after the last age check and the Class,
#               i.e. the last statement before FreeRADIUS sends the Access-Accept
SITE_MODES = ('hsres', 'hsres_hold', 'hsres2', 'hsres2_hold')
HOLD_A = '\t\tif (&Tmp-String-9 != "hs-claim=1") {\n' + _rej('full') + '\t\t}\n'
HOLD_B = _upd('request', 'Tmp-String-0', 'nuxr-hsres=claim:%{control:Tmp-String-9}')


def _hold(point):
    return ('\t\t# LAB HOLD POINT ' + point + ' (dev/wp8/h2b_gate.py PENDING gate) - never in a real policy\n'
            + _upd('control', 'Tmp-String-1', f"%{{sql:UPDATE nuxr_h2b_lab_hold SET uses = uses - 1, fired_at = UTC_TIMESTAMP(6) WHERE mac = '%{{Calling-Station-Id}}' AND point = '{point}' AND uses > 0}}")
            + '\t\tif (&control:Tmp-String-1 == "1") {\n'
            + _upd('control', 'Tmp-String-1', f"held=%{{sql:SELECT SLEEP(ms / 1000) FROM nuxr_h2b_lab_hold WHERE mac = '%{{Calling-Station-Id}}' AND point = '{point}'}}", '\t\t\t')
            + '\t\t}\n')


def _acct2(status_sql):
    # rlm_sql: "" for 0 rows. An unmatched Start/Interim = a session holding no live slot -> audit row (alarm)
    return ('\t\t\t\tif (&Tmp-String-5 != "hs-acct=1") {\n'
            + _upd('request', 'Tmp-String-0', 'nuxr-hsres-acct=orphan:%{Acct-Status-Type}', '\t\t\t\t\t')
            + (_upd('request', 'Tmp-String-6', "%{sql:INSERT INTO nuxr_hs_admission_audit (at, action, outcome, username, slot, token, actor, evidence) VALUES (UTC_TIMESTAMP(6), 'orphan_accounting', '%{Acct-Status-Type}', '%{User-Name}', 0, '%{Tmp-String-4}', 'freeradius', CONCAT('nas %{NAS-IP-Address} asid %{Acct-Session-Id} mac %{Calling-Station-Id}'))}", '\t\t\t\t\t') if status_sql else '')
            + '\t\t\t\t}\n')


PROTO_ACCT2 = PROTO_ACCT.replace(
    "AND state IN ('PENDING', 'ACTIVE')}\"\n\t\t\t\t}\n\t\t\t}\n\t\t\telsif",
    "AND state IN ('PENDING', 'ACTIVE')}\"\n\t\t\t\t}\n" + _acct2(False) + "\t\t\t}\n\t\t\telsif", 1).replace(
    "AND state IN ('PENDING', 'ACTIVE')}\"\n\t\t\t\t}\n\t\t\t}\n\t\t}\n\t}\n",
    "AND state IN ('PENDING', 'ACTIVE')}\"\n\t\t\t\t}\n" + _acct2(True) + "\t\t\t}\n\t\t}\n\t}\n", 1)
assert PROTO_ACCT2.count('orphan:') == 2
SESSION_ANCHOR = 'session {\n\t# Simultaneous-Use: open sessions in radacct\n\tsql\n}\n'
SESSION_HS2 = ('session {\n\t# Simultaneous-Use: open sessions in radacct\n'
               '\t# LAB PROTOTYPE R-H2-B: a Hotspot login is limited by the admission slots (post-auth), not by radacct + checkrad\n'
               '\tif ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {\n\t\tok\n\t}\n\telse {\n\t\tsql\n\t}\n}\n')
POST_ANCHOR = '\t\tupdate request {\n\t\t\t&Tmp-String-0 := "nuxr-hotspot=exact"\n\t\t}\n\t}\n\tPost-Auth-Type REJECT {'
ACCT_ANCHOR = '\tattr_filter.accounting_response\n}\n\nsession {'


# WP8 H-2B IMPLEMENTATION (owner 2026-10-11): the slots are now part of the PROJECT site; the feasibility prototype
# modes above (hsres*, built on the pre-H-2B project text) no longer apply. Lab-only overlay on the project site:
#  hold   the two PENDING-gate hold points (SQL SLEEP of ONE attempt keyed by Calling-Station-Id, nuxr_h2b_lab_hold)
#         post_claim = before the "H-2B 7" late-claim guard; pre_reply = after the Class (last statement before the reply)
IMPL_POST_CLAIM = '\t\t# ---- H-2B 7: late-claim guard AFTER the claim'
IMPL_PRE_REPLY = '\t\tupdate request {\n\t\t\t&Tmp-String-0 := "nuxr-hs-admission=claim:%{control:Tmp-String-9}"\n\t\t}\n'


def site_text(mode):
    s = open(g.SITE).read()
    if mode == 'project':
        return s
    if mode == 'hold':
        assert s.count(IMPL_POST_CLAIM) == 1 and s.count(IMPL_PRE_REPLY) == 1
        return s.replace(IMPL_POST_CLAIM, _hold('post_claim') + IMPL_POST_CLAIM).replace(IMPL_PRE_REPLY, IMPL_PRE_REPLY + _hold('pre_reply'))
    assert mode in SITE_MODES and s.count(POST_ANCHOR) == 1 and s.count(ACCT_ANCHOR) == 1
    post, acct = PROTO_POST, PROTO_ACCT
    if mode.startswith('hsres2'):
        acct = PROTO_ACCT2
        assert s.count(SESSION_ANCHOR) == 1
        s = s.replace(SESSION_ANCHOR, SESSION_HS2)
    if mode.endswith('_hold'):
        assert post.count(HOLD_A) == 1 and post.count(HOLD_B) == 1
        post = post.replace(HOLD_A, HOLD_A + _hold('post_claim')).replace(HOLD_B, HOLD_B + _hold('pre_reply'))
    s = s.replace(POST_ANCHOR, POST_ANCHOR.replace('\t}\n\tPost-Auth-Type REJECT {', post + '\t}\n\tPost-Auth-Type REJECT {'))
    return s.replace(ACCT_ANCHOR, acct + ACCT_ANCHOR)


def site(mode):
    if mode == 'project':
        cg.sh(['docker', 'exec', FR, 'rm', '-f', f'{h.R}/wp8-h1-site-on', f'{h.R}/wp8-h1-site'])
    else:
        tmp = f'{STATE}/run/wp8-h1-site'
        os.makedirs(os.path.dirname(tmp), exist_ok=True)
        open(tmp, 'w').write(site_text(mode))
        cg.sh(['docker', 'cp', tmp, f'{FR}:{h.R}/wp8-h1-site'])
        cg.sh(['docker', 'exec', FR, 'touch', f'{h.R}/wp8-h1-site-on'])
        os.remove(tmp)
    cg.restart()
    return site_mode()


def site_mode():
    run = cg.sh(['docker', 'exec', FR, 'sha256sum', f'{h.R}/sites-enabled/phpnuxbill']).stdout.split()[0]
    sha = lambda t: hashlib.sha256(t.encode()).hexdigest()
    errs = cg.sh(['docker', 'logs', FR], check=False)
    bad = len(re.findall(r'Errors? reading|parse error|Failed to parse', errs.stdout + errs.stderr))
    known = {}
    for m in ('project', 'hold', *SITE_MODES):
        try:
            known[sha(site_text(m))] = m
        except AssertionError:      # a feasibility prototype mode that does not apply to the current project text
            pass
    return {'site': known.get(run, 'other'), 'config_errors': bad}


def schema():
    sql(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'install', 'freeradius', 'nuxr_hs_admission.sql')).read())
    return sqlrows("SELECT table_name FROM information_schema.tables WHERE table_schema = 'nuxr_radius' AND table_name LIKE 'nuxr_hs_%'")


def slots(user):
    return [{'slot': r[0], 'state': r[1], 'nas': r[2], 'mac': r[3], 'asid': r[4], 'token': r[5][:8], 'attach': r[6], 'end_reason': r[7], 'age_s': r[8]}
            for r in sqlrows(f"SELECT slot, state, nas, calling_station_id, acct_session_id, token, attach_count, IFNULL(end_reason,'-'), "
                             f"TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP(6)) FROM nuxr_hs_admission WHERE username = CONVERT(X'{hexs(user)}' USING utf8mb4) ORDER BY slot")]


def in_use(user):
    return [x for x in slots(user) if x['state'] != 'ENDED']


def settle(user, seconds=25):
    """after a trial: every client logged out -> wait until the Accounting-Stops ended every slot"""
    ok = until(lambda: in_use(user) == [] or None, seconds, 0.5) is not None
    return {'all_ended': ok, 'slots': slots(user)}


# ------------------------------------------------------------------------------------------------ verifier (lab)
HORIZON_S = 15


def verify_release(user, actor='lab-verifier', rule='horizon'):
    """the proposed recovery step, run by the lab: for every non-ENDED slot whose NAS answers SNMP (health probe first) and
    shows NO Hotspot session for (user, MAC), and - for PENDING - only once the slot is older than the establishment
    horizon: ENDED (conditional on the same token and state) + audit row. An unreachable NAS releases nothing.
    rule='strict' (PENDING gate, A2-R Strict Mode): a PENDING slot is NEVER released by the verifier (time + SNMP cannot
    prove that the stalled request will not still produce an Access-Accept the NAS takes); only ACTIVE slots."""
    out = []
    for x in in_use(user):
        if x['state'] == 'PENDING' and rule == 'strict':
            out.append({**x, 'decision': 'keep:pending-strict'})
            continue
        if x['state'] == 'PENDING' and int(x['age_s']) < HORIZON_S:
            out.append({**x, 'decision': 'keep:young-pending'})
            continue
        ok, rows, err, ms = snmp_sessions(x['nas'])
        if not ok:
            out.append({**x, 'decision': 'keep:verify-error', 'error': err, 'ms': ms})
            continue
        if any(r[0] == user and r[1] == x['mac'].upper() for r in rows):
            out.append({**x, 'decision': 'keep:live', 'ms': ms})
            continue
        full = sqlrows(f"SELECT token FROM nuxr_hs_admission WHERE username = CONVERT(X'{hexs(user)}' USING utf8mb4) AND slot = {x['slot']}")[0][0]
        n = sql(f"UPDATE nuxr_hs_admission SET state = 'ENDED', ended_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), end_reason = 'verified_gone' "
                f"WHERE username = CONVERT(X'{hexs(user)}' USING utf8mb4) AND slot = {x['slot']} AND token = '{full}' AND state = '{x['state']}'; SELECT ROW_COUNT();").strip()
        sql(f"INSERT INTO nuxr_hs_admission_audit (at, action, outcome, username, slot, token, actor, evidence) VALUES (UTC_TIMESTAMP(6), 'verified_release', "
            f"'{'released' if n == '1' else 'raced'}', CONVERT(X'{hexs(user)}' USING utf8mb4), {x['slot']}, '{full}', '{actor}', "
            f"'snmp {x['nas']}: no Hotspot session for user+mac {x['mac']}; state {x['state']} age {x['age_s']} s')")
        out.append({**x, 'decision': 'released' if n == '1' else 'raced', 'ms': ms})
    return out


def fr_tags(since_rfc, user):
    d = h2.fr_decisions(since_rfc, user)
    lg = cg.sh(['docker', 'logs', '--since', since_rfc, FR], check=False)
    tags = sorted(set(re.findall(r'nuxr-hsres=[a-z:-]+|nuxr-hs-admission=[a-z:-]+|nuxr-hs-acct=[0-9]*', lg.stdout + lg.stderr)))
    return {'tags': tags, 'requests': d['requests'][-4:]}


# ------------------------------------------------------------------------------------------------ Gate B
def _pkts(cap, t0, user):
    out = []
    for p in g.pcap_packets(cap):
        d = dict(p['attrs'])
        if p['code'] in ('Access-Request', 'Accounting-Request') and d.get('User-Name') != user:
            continue
        if p['code'] in ('Access-Accept', 'Access-Reject', 'Accounting-Response'):
            out.append({'t_ms': round((p['t_us'] - t0 * 1e6) / 1000), 'code': p['code'], 'id': p['id'], 'class': d.get('Class')})
        else:
            out.append({'t_ms': round((p['t_us'] - t0 * 1e6) / 1000), 'code': p['code'], 'id': p['id'], 'status': d.get('Acct-Status-Type'),
                        'class': d.get('Class'), 'asid': d.get('Acct-Session-Id'), 'mac': d.get('Calling-Station-Id'), 'src': p.get('src')})
    return out


def _method_login(method, user, pw, jar):
    ns = ag.CLIENTS['c2'][0]
    if method == 'http-chap':
        return h2.login('c2', user, pw)['ok']
    if method == 'http-pap':
        return h.hs_login_pap('c2', user, pw)['ok']
    if method == 'cookie':
        ok = h._chap_login_jar(ns, jar, user, pw)
        for x in h.ros_rows(['/ip/hotspot/active/print']):
            h.ros_do('/ip/hotspot/active/remove', '=.id=' + x['.id'])   # forces the cookie re-login (a new RADIUS login)
        time.sleep(2)
        ag._curl(ns, '-c', jar, '-b', jar, '-L', 'http://10.88.2.1/login')
        time.sleep(3)
        return ok and bool([x for x in h.ros_rows(['/ip/hotspot/active/print']) if x.get('login-by') == 'cookie'])
    if method == 'mac':
        ag.http_probe('c2')
        time.sleep(4)
        return bool([x for x in h.ros_rows(['/ip/hotspot/active/print']) if x.get('login-by') == 'mac'])


@case
def gate_b():
    """Gate B (site hsres): does the RouterOS Hotspot accounting echo the Access-Accept Class unchanged in Start, Interim
    and Stop, with the same Acct-Session-Id and Calling-Station-Id as the claiming Access-Request? Per login method
    http-chap, http-pap, cookie re-login, MAC (mac-as-username, upper-case stored MAC); waits for one Interim (1 m)"""
    assert MODE[0] in ('hsres', 'project', 'hold'), 'needs: site project (H-2B implemented) or hsres'
    out = {}
    mac = h2.mac_of('c2')
    jar = f'{STATE}/run/h2b-cookie.jar'
    profiles = {'http-chap': dict(login_by='http-chap'), 'http-pap': dict(login_by='http-pap'),
                'cookie': dict(login_by='http-chap,cookie', http_cookie_lifetime='1h'), 'mac': dict(login_by='mac,http-chap', mac_auth_mode='mac-as-username')}
    try:
        for method, prof in profiles.items():
            user = mac if method == 'mac' else f'nuxr_h2b_gb_{method.replace("-", "")}_{secrets.token_hex(2)}'
            pw = '' if method == 'mac' else 'H2B-' + secrets.token_hex(6)
            h.provision(user, pw, 1)
            h.profile(**prof)
            if os.path.exists(jar):
                os.remove(jar)
            h2.fresh(['c2'])
            cap = f'h2b-gate-b-{method}'
            ag.capture_start(cap)
            t0 = time.time()
            ok = _method_login(method, user, pw, jar)
            time.sleep(68)                                  # one Interim-Update (radius-interim-update = 1m)
            mid = slots(user)
            h2.logout('c2')
            for x in h.ros_rows(['/ip/hotspot/active/print']):
                h.ros_do('/ip/hotspot/active/remove', '=.id=' + x['.id'])
            time.sleep(4)
            pk = _pkts(ag.capture_stop(cap)['file'], t0, user)
            acc = [p for p in pk if p['code'] == 'Access-Accept']
            areq = [p for p in pk if p['code'] == 'Access-Request']
            acct = [p for p in pk if p['code'] == 'Accounting-Request']
            # the Class of each admitted attempt, keyed by the Acct-Session-Id of its Access-Request (reply = same RADIUS id)
            cls_by_asid = {}
            for q in areq:
                rep_ = next((x for x in acc if x['id'] == q['id'] and x['t_ms'] >= q['t_ms']), None)
                if rep_:
                    cls_by_asid[q['asid']] = rep_['class']
            cls = acc[-1]['class'] if acc else None
            out[method] = {'login_ok': ok, 'accept_classes': cls_by_asid, 'access_request_asid': [p['asid'] for p in areq],
                           'accounting': [(p['status'], p['asid'] in cls_by_asid and p['class'] == cls_by_asid[p['asid']], p['asid'], p['mac']) for p in acct],
                           'slots_during': [(x['state'], x['asid']) for x in mid], 'slots_after': [(x['state'], x['end_reason']) for x in slots(user)]}
            stats = [p['status'] for p in acct]
            o = out[method]
            o['class_echoed_unchanged'] = bool(cls_by_asid) and bool(acct) and all(x[1] for x in o['accounting'])
            o['start_interim_stop_seen'] = all(s in stats for s in ('Start', 'Interim-Update', 'Stop'))
            o['asid_matches_claim'] = bool(areq) and all(p['asid'] in cls_by_asid for p in acct)
            o['slot_active_then_ended'] = [x[0] for x in o['slots_during']] == ['ACTIVE'] and [x[0] for x in o['slots_after']] == ['ENDED'] and all(x[1] == 'acct_stop' for x in o['slots_after'])
            h.unprovision(user)
    finally:
        h.restore_profile()
        h2.fresh(['c2'])
        for c in h.ros_rows(['/ip/hotspot/cookie/print']):
            h.ros_do('/ip/hotspot/cookie/remove', '=.id=' + c['.id'])
        if os.path.exists(jar):
            os.remove(jar)
    v = {m: {k: o[k] for k in ('login_ok', 'class_echoed_unchanged', 'start_interim_stop_seen', 'asid_matches_claim', 'slot_active_then_ended')} for m, o in out.items()}
    v['pass'] = all(all(x.values()) for x in v.values())
    record('H2B.gate_b', {'verdict': v, 'detail': out})
    return v


# ------------------------------------------------------------------------------------------------ Gate C
def proto_trials(name, limit, specs):
    u, pw = h2.subscriber(name, limit)
    out = []
    try:
        for label, plan, kw in specs:
            t = h2.trial(label, u, pw, limit, plan, **kw)
            t['slots_at_end'] = settle(u)
            out.append(t)
    finally:
        h.unprovision(u)
    summary = {t['label']: {'class': t['classification'], 'live_max': t['live_max']['total'], 'admitted': sum(1 for a in t['attempts'] if a['ok']),
                            'attempts': len(t['attempts']), 'slots_settled': t['slots_at_end']['all_ended']} for t in out}
    v = {'limit': limit, 'trials': summary, 'exceeded': sum(1 for t in out if t['classification'] == 'EXCEEDED'),
         'availability_failures': sum(1 for t in out if t['classification'] == 'AVAILABILITY_FAILURE'), 'n': len(out)}
    record(f'H2B.{name}', {'verdict': v, 'detail': out})
    return v


@case
def gate_c_nas():
    """Gate C on the real NAS (site hsres): limit 1 sequential both ways + 10 concurrent; limit 2 with 4 clients on two
    NAS (sequential + 5 concurrent); limit 3 with 5 clients on two NAS (5 concurrent)"""
    assert MODE[0] in ('hsres', 'project', 'hold'), 'needs: site project (H-2B implemented) or hsres'
    return {
        'su1': proto_trials('gc1', 1, [('chr1_then_chr2', ['c2', 'c7'], {}), ('chr2_then_chr1', ['c7', 'c2'], {}),
                                       *[(f'together_{i}', ['c2', 'c7'], {'concurrent': True}) for i in range(10)]]),
        'su2': proto_trials('gc2', 2, [('seq_4clients', ['c2', 'c7', 'c4', 'c8'], {}),
                                       *[(f'together_4clients_{i}', ['c2', 'c4', 'c7', 'c8'], {'concurrent': True}) for i in range(5)]]),
        'su3': proto_trials('gc3', 3, [(f'together_5clients_{i}', ['c2', 'c4', 'c5', 'c7', 'c8'], {'concurrent': True}) for i in range(5)]),
    }


# SQL-level stress: N concurrent sessions run the policy's slot creation + claim at the same instant
CLAIM_SQL = ("INSERT IGNORE INTO nuxr_hs_admission (username, slot, state, token, nas, acct_session_id, calling_station_id, request_authenticator, session_user, created_at, updated_at) "
             "SELECT '{u}', t.n, 'ENDED', MD5(CONCAT(UUID(), RAND(), t.n)), '', '', '', '', '', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) FROM (SELECT a.d * 10 + b.d + 1 AS n FROM "
             "(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a CROSS JOIN "
             "(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b) t WHERE t.n <= {L};\n"
             "SELECT IF(COUNT(*) < {L} AND COALESCE(SUM(slot > {L}), 0) = 0, 'free', 'full') INTO @pre FROM nuxr_hs_admission WHERE username = '{u}' AND state <> 'ENDED';\n"
             "UPDATE nuxr_hs_admission SET prev_token = token, prev_state = state, state = 'PENDING', token = '{tok}', nas = '10.88.0.9', acct_session_id = '{i}', calling_station_id = 'AA', "
             "request_authenticator = '{tok}', session_user = '{u}', attach_count = 0, created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) "
             "WHERE username = '{u}' AND slot <= {L} AND state = 'ENDED' AND @pre = 'free' "
             "ORDER BY slot LIMIT 1;\nSELECT CONCAT('claimed=', ROW_COUNT());\n")


def _engine_cmd(engine, db):
    if engine == 'mariadb':
        return ['docker', 'exec', '-i', g.DB, 'sh', '-c', f'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot -N -B {db}']
    return ['docker', 'exec', '-i', 'wp8-h2b-mysql', 'mysql', '-uroot', '-N', '-B'] + ([db] if db else [])


def _stress(engine, db, limit, n, start_at):
    u = f'stress_{secrets.token_hex(3)}'
    res = [None] * n
    def one(i):
        q = f"DO SLEEP(GREATEST(0, {start_at} - UNIX_TIMESTAMP(NOW(6))));\n" + CLAIM_SQL.format(u=u, L=limit, tok=secrets.token_hex(16), i=i)
        p = subprocess.run(_engine_cmd(engine, db), input=q, capture_output=True, text=True, timeout=60)
        res[i] = (p.stdout.strip().splitlines() or [''])[-1] if p.returncode == 0 else 'ERROR:' + p.stderr.strip()[-80:]
    th = [threading.Thread(target=one, args=(i,)) for i in range(n)]
    [t.start() for t in th]
    [t.join(90) for t in th]
    q = subprocess.run(_engine_cmd(engine, db), input=f"SELECT COUNT(*) FROM nuxr_hs_admission WHERE username = '{u}' AND state <> 'ENDED';\n", capture_output=True, text=True)
    return {'limit': limit, 'sessions': n, 'claimed': sum(1 for r in res if r == 'claimed=1'), 'refused': sum(1 for r in res if r == 'claimed=0'),
            'errors': [r for r in res if r and r.startswith('ERROR')][:3], 'error_count': sum(1 for r in res if r and r.startswith('ERROR')),
            'in_use_rows': int(q.stdout.strip() or -1)}


@case
def gate_c_sql():
    """Gate C at the SQL level, both engines: 25 concurrent sessions released at the same instant run the policy's slot
    creation + claim for one subscriber (limit 1, 2, 3, 5; 5 rounds each); then a limit decrease (3 in use, limit 1)"""
    schema_sql = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'install', 'freeradius', 'nuxr_hs_admission.sql')).read()
    out = {}
    try:
        subprocess.run(['docker', 'run', '-d', '--rm', '--name', 'wp8-h2b-mysql', '--network', 'none', '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', 'mysql:8.0'], capture_output=True, check=True)
        until(lambda: subprocess.run(['docker', 'exec', 'wp8-h2b-mysql', 'mysql', '-uroot', '-e', 'SELECT 1'], capture_output=True).returncode == 0 or None, 120, 2)
        for engine in ('mariadb', 'mysql'):
            subprocess.run(_engine_cmd(engine, ''), input='DROP DATABASE IF EXISTS nuxr_h2b_stress; CREATE DATABASE nuxr_h2b_stress CHARACTER SET utf8mb4;\n', capture_output=True, text=True, check=True)
            subprocess.run(_engine_cmd(engine, 'nuxr_h2b_stress'), input=schema_sql, capture_output=True, text=True, check=True)
            ver = subprocess.run(_engine_cmd(engine, 'nuxr_h2b_stress'), input='SELECT VERSION();\n', capture_output=True, text=True).stdout.strip()
            rounds = []
            for limit in (1, 2, 3, 5):
                for r in range(5):
                    rounds.append(_stress(engine, 'nuxr_h2b_stress', limit, 25, time.time() + 4))
            # limit decrease: 3 slots in use under limit 3, then a claim under limit 1 must fail
            u = f'decr_{secrets.token_hex(3)}'
            for i in range(3):
                subprocess.run(_engine_cmd(engine, 'nuxr_h2b_stress'), input=CLAIM_SQL.format(u=u, L=3, tok=secrets.token_hex(16), i=i), capture_output=True, text=True)
            dec = subprocess.run(_engine_cmd(engine, 'nuxr_h2b_stress'), input=CLAIM_SQL.format(u=u, L=1, tok=secrets.token_hex(16), i=9), capture_output=True, text=True)
            out[engine] = {'version': ver, 'rounds': rounds,
                           'never_above_limit': all(x['in_use_rows'] <= x['limit'] and x['claimed'] <= x['limit'] for x in rounds),
                           'exactly_limit_admitted': all(x['claimed'] == x['limit'] for x in rounds),
                           'total_errors': sum(x['error_count'] for x in rounds),
                           'limit_decrease_refused': dec.stdout.strip().splitlines()[-1:] == ['claimed=0'], 'limit_decrease_raw': dec.stdout.strip()[-40:] + dec.stderr.strip()[-120:]}
            subprocess.run(_engine_cmd(engine, ''), input='DROP DATABASE IF EXISTS nuxr_h2b_stress;\n', capture_output=True, text=True)
    finally:
        subprocess.run(['docker', 'rm', '-f', 'wp8-h2b-mysql'], capture_output=True)
    v = {e: {k: o[k] for k in ('version', 'never_above_limit', 'exactly_limit_admitted', 'total_errors', 'limit_decrease_refused')} for e, o in out.items()}
    v['pass'] = all(x['never_above_limit'] and x['limit_decrease_refused'] for x in v.values())
    record('H2B.gate_c_sql', {'verdict': v, 'detail': out})
    return v


@case
def gate_c_retx():
    """Gate C: an exact retransmission (same bytes) of a synthetic Hotspot Access-Request after FreeRADIUS forgot the
    first one (> cleanup_delay) re-uses the same claim (same Class), a NEW attempt of the same client is refused under
    limit 1; synthetic requests from the lab app NAS 10.88.0.10"""
    assert MODE[0] in ('hsres', 'project', 'hold'), 'needs: site project (H-2B implemented) or hsres'
    u, pw = h2.subscriber('gcr', 1)
    try:
        attrs = [('User-Name', u), ('NAS-IP-Address', '10.88.0.10'), ('Service-Type', 1), ('NAS-Port-Type', 19), ('NAS-Port', 2147483700),
                 ('Acct-Session-Id', '81000077'), ('Calling-Station-Id', '02:AB:CD:00:00:77'), ('Called-Station-Id', 'nuxr_wp8_hs'), ('NAS-Identifier', 'nuxr-h2b-retx')]
        pkt = g.access_request(attrs, pw, chap=True)
        r1 = g.exchange(pkt)
        time.sleep(7)                                       # > FreeRADIUS cleanup_delay: the copy is a new request
        r2 = g.exchange(pkt)
        other = g.exchange(g.access_request(attrs, pw, chap=True))   # same client, new authenticator = a new attempt
        st = slots(u)
        v = {'first': r1['reply'] and r1['reply']['code'], 'retransmission': r2['reply'] and r2['reply']['code'],
             'same_class': bool(g.reply_class(r1)) and g.reply_class(r1) == g.reply_class(r2), 'new_attempt': other['reply'] and other['reply']['code'],
             'slots': [(x['state'], x['attach']) for x in st]}
        v['pass'] = v['first'] == 'Access-Accept' and v['retransmission'] == 'Access-Accept' and v['same_class'] and v['new_attempt'] == 'Access-Reject' and [x[0] for x in v['slots']] == ['PENDING']
        cls = g.reply_class(r1)
        if cls:   # end the synthetic claim through its own accounting Stop
            g.exchange(g.accounting_request([('User-Name', u), ('NAS-IP-Address', '10.88.0.10'), ('Acct-Status-Type', 2), ('Acct-Session-Id', '81000077'),
                                             ('Calling-Station-Id', '02:AB:CD:00:00:77'), ('Class', bytes.fromhex(cls)), ('Acct-Session-Time', 5)]), port=1813)
        v['slots_after_stop'] = [(x['state'], x['end_reason']) for x in slots(u)]
    finally:
        h.unprovision(u)
    record('H2B.gate_c_retx', {'verdict': v})
    return v


# ------------------------------------------------------------------------------------------------ Gate D
@case
def gate_d():
    """Gate D (site hsres, limit 1, CHR1 first then CHR2): lost Access-Accept, lost Accounting-Start, lost
    Accounting-Stop, verifier with the NAS management unreachable, FreeRADIUS restart, database outage during a login"""
    assert MODE[0] in ('hsres', 'project', 'hold'), 'needs: site project (H-2B implemented) or hsres'
    out = {}
    def scenario(name, fn):
        u, pw = h2.subscriber('gd' + name[:4], 1)
        try:
            h2.fresh(['c2', 'c7'])
            t_rfc = g.since()
            out[name] = fn(u, pw)
            out[name]['freeradius'] = fr_tags(t_rfc, u)
        finally:
            h2.logout('c2'); h2.logout('c7')
            time.sleep(4)
            out[name]['slots_final'] = [(x['state'], x['end_reason']) for x in slots(u)]
            h.unprovision(u)

    def lost_accept(u, pw):
        rid = g.block_auth_replies()
        try:
            a = h2.login('c2', u, pw)
        finally:
            g.unblock(rid)
        s1 = slots(u)
        b = h2.login('c7', u, pw)
        v_young = verify_release(u)
        time.sleep(HORIZON_S + 2)
        v_old = verify_release(u)
        c = h2.login('c7', u, pw)
        return {'chr1_login_reply_dropped': a['state'], 'slots_after': [(x['state'], x['nas']) for x in s1], 'chr2_login_while_pending': b['state'],
                'verifier_young': [x['decision'] for x in v_young], 'verifier_after_horizon': [x['decision'] for x in v_old], 'chr2_login_after_release': c['state'],
                'live': h2.live(u)}

    def lost_start(u, pw):
        rid = g.block_acct()
        try:
            a = h2.login('c2', u, pw)
            time.sleep(3)
            s1 = slots(u)
            b = h2.login('c7', u, pw)
            v1 = verify_release(u)
        finally:
            g.unblock(rid)
        ok = until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 75, 2) is not None   # the Interim-Update with the Class
        return {'chr1_login': a['state'], 'slot_while_start_lost': [x['state'] for x in in_use(u)] if False else [x['state'] for x in s1 if x['state'] != 'ENDED'],
                'chr2_login': b['state'], 'verifier': [x['decision'] for x in v1], 'confirmed_by_interim': ok, 'live': h2.live(u)}

    def lost_stop(u, pw):
        a = h2.login('c2', u, pw)
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        rid = g.block_acct()
        try:
            h2.logout('c2')
            until(lambda: h2.live(u)['chr1'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            g.unblock(rid)
        s1 = [x['state'] for x in in_use(u)]
        b = h2.login('c7', u, pw)
        ids = block_mgmt(1, ports=(161,))
        try:
            v_err = verify_release(u)
        finally:
            unblock_mgmt(1, ids)
        b2 = h2.login('c7', u, pw)
        v_ok = verify_release(u)
        c = h2.login('c7', u, pw)
        return {'chr1_login': a['state'], 'stale_slot': s1, 'chr2_login_stale': b['state'], 'verifier_nas_unreachable': [x['decision'] for x in v_err],
                'chr2_login_still_refused': b2['state'], 'verifier_reachable': [x['decision'] for x in v_ok], 'chr2_login_after_release': c['state'], 'live': h2.live(u)}

    def fr_restart(u, pw):
        a = h2.login('c2', u, pw)
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        cg.restart()
        s1 = [x['state'] for x in in_use(u)]
        b = h2.login('c7', u, pw)
        h2.logout('c2')
        until(lambda: in_use(u) == [] or None, 20, 0.5)
        c = h2.login('c7', u, pw)
        return {'chr1_login': a['state'], 'slots_after_restart': s1, 'chr2_login_while_active': b['state'], 'chr2_login_after_stop': c['state'], 'live': h2.live(u)}

    def db_outage(u, pw):
        cg.sh(['docker', 'pause', g.DB])
        try:
            a = h2.login('c2', u, pw)
        finally:
            cg.sh(['docker', 'unpause', g.DB])
        time.sleep(3)
        s1 = [x['state'] for x in in_use(u)]
        v1 = verify_release(u) if s1 else []
        b = h2.login('c7', u, pw)
        return {'chr1_login_db_paused': a['state'], 'slots_in_use_after': s1, 'verifier': [x['decision'] for x in v1], 'chr2_login_after': b['state'], 'live': h2.live(u)}

    for name, fn in (('lost_accept', lost_accept), ('lost_start', lost_start), ('lost_stop', lost_stop), ('fr_restart', fr_restart), ('db_outage', db_outage)):
        scenario(name, fn)
    v = {k: {q: w for q, w in o.items() if q not in ('freeradius',)} for k, o in out.items()}
    v['max_live_any'] = max(o['live']['total'] for o in out.values())
    record('H2B.gate_d', {'verdict': v, 'detail': out})
    return v


@case
def gate_d_nas_reboot():
    """Gate D: NAS reboot - a CHR2 session (slot ACTIVE), CHR2 rebooted (sessions lost, no Stop); the slot stays; the
    verifier sees CHR2 unreachable during the reboot (keep) and without the session afterwards (release); then CHR1"""
    assert MODE[0] in ('hsres', 'project', 'hold'), 'needs: site project (H-2B implemented) or hsres'
    u, pw = h2.subscriber('gdnb', 1)
    try:
        h2.fresh(['c2', 'c7'])
        a = h2.login('c7', u, pw)
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        r = g.ros2()
        try:
            r.talk(['/system/reboot'])
        except Exception:
            pass
        time.sleep(8)
        during = verify_release(u)
        b = h2.login('c2', u, pw)
        def back():
            try:
                return bool(h2.r2(['/system/identity/print'])) or None
            except Exception:
                return None
        up = until(back, 300, 5) is not None
        time.sleep(10)
        after = verify_release(u)
        c = h2.login('c2', u, pw)
        v = {'chr2_login': a['state'], 'verifier_during_reboot': [x['decision'] for x in during], 'chr1_login_during': b['state'], 'chr2_back': up,
             'verifier_after_reboot': [x['decision'] for x in after], 'chr1_login_after_release': c['state'], 'live': h2.live(u)}
        h2.logout('c2')
        time.sleep(4)
        v['slots_final'] = [(x['state'], x['end_reason']) for x in slots(u)]
    finally:
        h.unprovision(u)
    record('H2B.gate_d_nas_reboot', {'verdict': v})
    return v


# ------------------------------------------------------------------------------------------------ Gate E
@case
def gate_e():
    """Gate E: (1) Access-Request -> Access-Accept on the wire for 10 Hotspot logins (pcap) in the CURRENT site mode;
    (2) the verifier's SNMP cost measured INSIDE the FreeRADIUS container (health probe + Hotspot table walk, one exec)"""
    u, pw = h2.subscriber('ge', 1)
    lat = []
    try:
        for i in range(10):
            h2.fresh(['c2'])
            cap = f'h2b-gate-e-{MODE[0]}-{i}'
            ag.capture_start(cap)
            t0 = time.time()
            ok = h2.login('c2', u, pw)['ok']
            pk = _pkts(ag.capture_stop(cap)['file'], t0, u)
            rq = [p for p in pk if p['code'] == 'Access-Request']
            ac = [p for p in pk if p['code'] in ('Access-Accept', 'Access-Reject')]
            if rq and ac:
                lat.append({'ok': ok, 'reply': ac[0]['code'], 'ms': ac[0]['t_ms'] - rq[0]['t_ms']})
            h2.logout('c2')
            until(lambda: in_use(u) == [] or None, 20, 0.5) if MODE[0] in ('hsres', 'project', 'hold') else time.sleep(2)
    finally:
        h.unprovision(u)
    d = '/tmp/h2bsnmpE'
    cg.sh(['docker', 'exec', '-i', FR, 'sh', '-c', f'umask 077; mkdir -p {d}; cat > {d}/snmp.conf'], inp=f'defCommunity {cg.community()}\ndefVersion 2c\nmibs :\n')
    script = (f'export SNMPCONFPATH={d} SNMP_PERSISTENT_DIR={d} MIBS=; for i in 1 2 3 4 5; do s=$(date +%s%N); '
              f'snmpget -r0 -t1 -On 10.88.0.2 {OID_UPTIME} >/dev/null && snmpbulkwalk -r0 -t1 -On -Ox -Cr50 10.88.0.2 {OID_HS} >/dev/null; '
              'e=$(date +%s%N); echo $(( (e - s) / 1000000 )); done')
    try:
        snmp_ms = [int(x) for x in cg.sh(['docker', 'exec', FR, 'sh', '-c', script], check=False).stdout.split()]
    finally:
        cg.sh(['docker', 'exec', FR, 'rm', '-rf', d], check=False)
    ms = sorted(x['ms'] for x in lat)
    v = {'site': MODE[0], 'logins': len(lat), 'all_accepted': all(x['reply'] == 'Access-Accept' for x in lat),
         'accept_ms_min_median_max': [ms[0], ms[len(ms) // 2], ms[-1]] if ms else None, 'snmp_verify_ms_in_container': snmp_ms}
    record('H2B.gate_e', {'verdict': v, 'detail': lat})
    return v


# ================================================================================================ PENDING SAFETY GATE
# Owner 2026-10-11 "WP8 H-2 R-H2-B Design Approval & Safety Gate": a claim (PENDING) stalls after the claim and before
# the Access-Accept, the recovery horizon passes, SNMP shows no session, another NAS takes the slot, then the stalled
# request resumes. Can its Access-Accept still create a session? Lab only; the hold points exist only in *_hold sites.
HOLD_DDL = ("CREATE TABLE IF NOT EXISTS nuxr_h2b_lab_hold (mac varchar(32) NOT NULL, point varchar(16) NOT NULL, ms int unsigned NOT NULL, "
            "uses int NOT NULL, fired_at datetime(6) NULL, PRIMARY KEY (mac, point)) ENGINE=InnoDB")


def hold(mac, point, seconds, uses=1):
    sql(HOLD_DDL)
    sql(f"REPLACE INTO nuxr_h2b_lab_hold (mac, point, ms, uses) VALUES ('{mac}', '{point}', {int(seconds * 1000)}, {uses})")


def hold_clear():
    sql(HOLD_DDL + "; DELETE FROM nuxr_h2b_lab_hold")


def hold_fired(mac, point):
    r = sqlrows(f"SELECT IFNULL(DATE_FORMAT(fired_at, '%H:%i:%s.%f'), '-'), uses FROM nuxr_h2b_lab_hold WHERE mac = '{mac}' AND point = '{point}'")
    return r[0] if r else None


def chr1_radius_timeout(value=None):
    """read (and optionally set) the CHR1 /radius entry of FreeRADIUS 10.88.0.10 - the A1 contract value is 3s"""
    row = next(x for x in h.ros_rows(['/radius/print']) if x.get('address') == '10.88.0.10')
    if value:
        h.ros_do('/radius/set', '=.id=' + row['.id'], f'=timeout={value}')
        row = next(x for x in h.ros_rows(['/radius/print']) if x.get('address') == '10.88.0.10')
    return row.get('timeout')


def hs_login_long(client, user, pw, timeout=60):
    """ag.hs_login (CHR1 clients) with a long HTTP timeout: the Hotspot login page answers only when RouterOS has the
    RADIUS outcome (Accept, Reject or its own RADIUS timeout)"""
    import hashlib
    ns = ag.CLIENTS[client][0]
    page, _ = ag._curl(ns, 'http://10.88.2.1/login', timeout=15)
    m = re.search(r"hexMD5\('((?:\\\d{3})+)' \+ document\.login\.password\.value \+ '((?:\\\d{3})+)'\)", page)
    if not m:
        return {'ok': False, 'state': 'NO_CHAP_FORM', 'page': re.sub(r'<[^>]+>', ' ', page)[:200]}
    resp = hashlib.md5(ag._octal(m.group(1)) + pw.encode() + ag._octal(m.group(2))).hexdigest()
    t0 = time.time()
    body, err = ag._curl(ns, '--data-urlencode', f'username={user}', '--data-urlencode', f'password={resp}', '--data-urlencode', 'dst=',
                         '--data-urlencode', 'popup=false', 'http://10.88.2.1/login', timeout=timeout)
    text = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body))
    e = re.search(r'(RADIUS server is not responding|invalid username or password|[^.!]{0,40}(?:denied|reject|limit|expired|not allowed)[^.!]{0,60})', text, re.I)
    ok = e is None and re.search(r'You are logged in', text) is not None
    return {'ok': ok, 'state': 'LOGGED_IN' if ok else 'REFUSED', 'message': (e.group(1) if e else (text[:120] or err.strip()[-120:])).strip(),
            'page_s': round(time.time() - t0, 2)}


def operator_release(user, slot, token, operator, reason, evidence):
    """LAB MODEL of the audited operator recovery (a separate owner decision): names ONE claim by its full token; ENDED only
    if that claim still holds the slot; append-only audit row either way"""
    n = sql(f"UPDATE nuxr_hs_admission SET state = 'ENDED', ended_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), end_reason = 'operator' "
            f"WHERE username = CONVERT(X'{hexs(user)}' USING utf8mb4) AND slot = {int(slot)} AND token = '{token}' AND state IN ('PENDING', 'ACTIVE'); SELECT ROW_COUNT();").strip()
    sql(f"INSERT INTO nuxr_hs_admission_audit (at, action, outcome, username, slot, token, actor, evidence) VALUES (UTC_TIMESTAMP(6), 'operator_release', "
        f"'{'released' if n == '1' else 'refused'}', CONVERT(X'{hexs(user)}' USING utf8mb4), {int(slot)}, '{token}', '{operator}', '{(reason + ': ' + evidence).replace(chr(39), '')[:1000]}')")
    return 'released' if n == '1' else 'refused'


def full_token(user, slot):
    r = sqlrows(f"SELECT token FROM nuxr_hs_admission WHERE username = CONVERT(X'{hexs(user)}' USING utf8mb4) AND slot = {int(slot)}")
    return r[0][0] if r else None


def wire(cap, t0, user, mac=None):
    """RADIUS packets of one capture for the user (Access-Request by User-Name; replies matched by id + authenticator of
    the request they answer, verified with the RADIUS secret = the NAS would accept the bytes)"""
    pk = g.pcap_packets(cap)
    reqs = [p for p in pk if p['code'] == 'Access-Request' and dict(p['attrs']).get('User-Name') == user]
    out = []
    for p in pk:
        a = dict(p['attrs'])
        t = round((p['t_us'] - t0 * 1e6) / 1000)
        if p['code'] == 'Access-Request' and p in reqs:
            out.append({'t_ms': t, 'code': 'Access-Request', 'src': p['src'].split(':')[0], 'id': p['id'], 'auth8': p['auth'][:8], 'mac': a.get('Calling-Station-Id')})
        elif p['code'] in ('Access-Accept', 'Access-Reject'):
            q = next((r for r in reqs if r['id'] == p['id'] and r['dst'].split(':')[0] == p['src'].split(':')[0] and r['src'] == p['dst']), None)
            if q:
                raw = bytes.fromhex(p['raw'])
                valid = hashlib.md5(raw[:4] + bytes.fromhex(q['auth']) + raw[20:] + g.SECRET).digest() == raw[4:20]
                out.append({'t_ms': t, 'code': p['code'], 'to': p['dst'].split(':')[0], 'id': p['id'], 'answers_auth8': q['auth'][:8],
                            'response_authenticator_valid': valid, 'class': (a.get('Class') or '')[:18]})
        elif p['code'] == 'Accounting-Request' and a.get('User-Name') == user:
            out.append({'t_ms': t, 'code': 'Accounting-Request', 'src': p['src'].split(':')[0], 'status': a.get('Acct-Status-Type'), 'class': (a.get('Class') or '')[:18],
                        'asid': a.get('Acct-Session-Id')})
    return out


def _first_req_ms(w, src):
    return next((x['t_ms'] for x in w if x['code'] == 'Access-Request' and x['src'] == src), None)


def _cleanup_user(u):
    h.unprovision(u)
    sql(f"DELETE FROM nuxr_hs_admission WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4)")


@case
def pend_deadline():
    """P0 (site *_hold, CHR1 /radius timeout 3s = the A1 contract): ONE Hotspot login whose Access-Accept FreeRADIUS holds
    AFTER the last age check (pre_reply) for H seconds. Where is the RouterOS Hotspot deadline, and is an Access-Accept that
    arrives after it (valid authenticator, on the wire) discarded - no session, no accounting?"""
    assert MODE[0].endswith('hold'), 'needs: h2b_gate.py site hold (or hsres2_hold)'
    tmo = chr1_radius_timeout()
    out = []
    for H in (2.0, 6.5, 8.0, 8.7, 9.4, 11.0, 14.0):
        u, pw = h2.subscriber('p0', 1)
        try:
            h2.fresh(['c2'])
            mac = h2.mac_of('c2')
            hold(mac, 'pre_reply', H)
            cap = f'h2b-p0-{H}'
            ag.capture_start(cap)
            t0 = time.time()
            r = hs_login_long('c2', u, pw, timeout=40)
            time.sleep(4)
            lv = h2.live(u)
            st = slots(u)
            time.sleep(1)
            w = wire(ag.capture_stop(cap)['file'], t0, u)
            f = _first_req_ms(w, '10.88.0.2')
            rel = lambda x: None if f is None else x - f
            acc = [x for x in w if x['code'] == 'Access-Accept']
            out.append({'hold_s': H, 'login': r['state'], 'message': r['message'], 'page_s': r['page_s'], 'hold_fired': hold_fired(mac, 'pre_reply'),
                        'access_requests_ms': [rel(x['t_ms']) for x in w if x['code'] == 'Access-Request'],
                        'same_id_auth': len({(x['id'], x['auth8']) for x in w if x['code'] == 'Access-Request'}) == 1,
                        'accepts_on_wire_ms': [rel(x['t_ms']) for x in acc], 'accept_authenticator_valid': [x['response_authenticator_valid'] for x in acc],
                        'accounting': [(x['status'], rel(x['t_ms'])) for x in w if x['code'] == 'Accounting-Request'],
                        'nas_live': lv['total'], 'slots': [(x['state'], x['end_reason']) for x in st]})
            h2.logout('c2')
            time.sleep(3)
        finally:
            hold_clear()
            _cleanup_user(u)
    taken = [o['hold_s'] for o in out if o['nas_live'] == 1]
    dropped = [o['hold_s'] for o in out if o['nas_live'] == 0 and o['accepts_on_wire_ms']]
    v = {'chr1_radius_timeout': tmo, 'trials': [{k: o[k] for k in ('hold_s', 'login', 'accepts_on_wire_ms', 'nas_live', 'slots', 'access_requests_ms')} for o in out],
         'accepted_by_nas_at_hold_s': taken, 'late_accept_discarded_at_hold_s': dropped,
         'every_late_accept_on_wire_valid': all(all(o['accept_authenticator_valid']) for o in out if o['accepts_on_wire_ms']),
         'no_accounting_for_discarded': all(not o['accounting'] for o in out if o['nas_live'] == 0)}
    v['pass'] = bool(taken) and bool(dropped) and max(taken) < min(dropped) and v['every_late_accept_on_wire_valid'] and v['no_accounting_for_discarded']
    record('H2B.PEND.p0_deadline', {'verdict': v, 'detail': out})
    return v


def owner_scenario(label, rule, nas_timeout, point, hold_s=22.0, limit=1):
    """the owner's six steps: (1) a CHR1 login claims a slot (PENDING); (2) FreeRADIUS stalls it at `point`; (3) the recovery
    horizon passes; (4) the verifier (rule) runs - SNMP shows no session; (5) a CHR2 login tries; (6) the stalled request
    resumes. Then: who is live, which slot holds which token, what crossed the wire."""
    u, pw = h2.subscriber('pd', limit)
    res = {'label': label, 'rule': rule, 'point': point, 'hold_s': hold_s, 'limit': limit}
    old = chr1_radius_timeout()
    try:
        res['chr1_radius_timeout'] = chr1_radius_timeout(nas_timeout) if nas_timeout != old else old
        h2.fresh(['c2', 'c7'])
        mac1 = h2.mac_of('c2')
        hold(mac1, point, hold_s)
        cap = f'h2b-pd-{label}'
        ag.capture_start(cap)
        t_rfc = g.since()
        t0 = time.time()
        box = {}
        th = threading.Thread(target=lambda: box.__setitem__('r1', hs_login_long('c2', u, pw, timeout=hold_s + 40)))
        th.start()
        claimed = until(lambda: in_use(u) or None, 15, 0.2)
        res['step1_claim'] = [(x['state'], x['nas'], x['token']) for x in (claimed or [])]
        t1 = full_token(u, 1)
        res['step3_verifier_young'] = [x['decision'] for x in verify_release(u, rule=rule)]
        time.sleep(max(0.0, HORIZON_S + 1.5 - (time.time() - t0)))
        ok, rows, err, ms = snmp_sessions(NAS[1])
        res['step4_snmp_chr1'] = {'ok': ok, 'sessions_of_user': [r for r in rows if r[0] == u], 'error': err}
        res['step4_verifier'] = [(x['decision'], x['state'], x['age_s']) for x in verify_release(u, rule=rule)]
        r2 = h2.login('c7', u, pw)
        res['step5_chr2_login'] = {'state': r2['state'], 'message': r2.get('message'), 't_s': round(time.time() - t0, 1)}
        res['slots_before_resume'] = [(x['state'], x['nas'], x['token']) for x in slots(u)]
        th.join(hold_s + 60)
        r1 = box.get('r1', {'state': 'NO_RESULT'})
        res['step6_chr1_resumed'] = {'state': r1['state'], 'message': r1.get('message'), 'page_s': r1.get('page_s'), 't_s': round(time.time() - t0, 1),
                                     'hold_fired': hold_fired(mac1, point)}
        time.sleep(3)
        smp = h2.sample(u, 8)
        res['live_max'] = smp['max']
        res['live_final'] = smp['final']
        res['slots_final'] = [(x['state'], x['nas'], x['token'], x['end_reason']) for x in slots(u)]
        res['slot1_token_is_claim1'] = full_token(u, 1) == t1
        res['freeradius'] = fr_tags(t_rfc, u)['tags']
        if rule == 'strict' and [x for x in in_use(u) if x['state'] == 'PENDING'] and r2['state'] != 'LOGGED_IN':
            # availability path of Strict Mode: the stuck PENDING is released only by the audited operator procedure
            x = [x for x in in_use(u) if x['state'] == 'PENDING'][0]
            res['operator_release'] = operator_release(u, x['slot'], full_token(u, x['slot']), 'wp8-lab-operator', 'PENDING gate: stalled claim, NAS deadline passed',
                                                       f"claim created {x['age_s']} s ago on {x['nas']}; CHR1 radius timeout {res['chr1_radius_timeout']}; no session on SNMP")
            r3 = h2.login('c7', u, pw)
            res['chr2_login_after_operator_release'] = r3['state']
            time.sleep(3)
            res['live_after_operator_release'] = h2.live(u)
        time.sleep(1)
        res['wire'] = wire(ag.capture_stop(cap)['file'], t0, u)
        f1 = _first_req_ms(res['wire'], '10.88.0.2')
        acc1 = [x for x in res['wire'] if x['code'] == 'Access-Accept' and x['to'] == '10.88.0.2']
        res['chr1_accept_after_first_request_ms'] = [x['t_ms'] - f1 for x in acc1] if f1 is not None else None
        res['audit'] = sqlrows(f"SELECT action, outcome, slot, actor FROM nuxr_hs_admission_audit WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) ORDER BY id")
    finally:
        try:
            if chr1_radius_timeout() != old:
                chr1_radius_timeout(old)
        finally:
            hold_clear()
            h2.logout('c2'); h2.logout('c7')
            time.sleep(3)
            _cleanup_user(u)
    res['exceeded'] = res.get('live_max', {}).get('total', 0) > limit or res.get('live_after_operator_release', {}).get('total', 0) > limit
    return res


@case
def pend_owner():
    """P1: the owner's scenario under the prototype rule (time + SNMP, horizon 15 s) and under Strict Mode, for a stall
    AFTER the last age check (pre_reply) and BEFORE it (post_claim), with the CHR1 /radius timeout 3s (deadline ~9 s, shorter
    than the horizon) and 10s (deadline ~30 s, longer than the horizon)"""
    assert MODE[0].endswith('hold'), 'needs: h2b_gate.py site hold (or hsres2_hold)'
    runs = [owner_scenario('a_horizon_prereply_t3', 'horizon', '3s', 'pre_reply'),
            owner_scenario('b_horizon_prereply_t10', 'horizon', '10s', 'pre_reply'),
            owner_scenario('c_strict_prereply_t10', 'strict', '10s', 'pre_reply'),
            owner_scenario('d_strict_prereply_t3', 'strict', '3s', 'pre_reply'),
            owner_scenario('e_horizon_postclaim_t3', 'horizon', '3s', 'post_claim'),
            owner_scenario('f_strict_postclaim_t3', 'strict', '3s', 'post_claim'),
            owner_scenario('g_horizon_postclaim_t10', 'horizon', '10s', 'post_claim')]
    v = {r['label']: {'chr2_login': r['step5_chr2_login']['state'], 'verifier': [x[0] for x in r['step4_verifier']], 'chr1_resumed': r['step6_chr1_resumed']['state'],
                      'chr1_accept_after_first_request_ms': r['chr1_accept_after_first_request_ms'], 'live_max': r['live_max']['total'],
                      'slots_final': [(x[0], x[3]) for x in r['slots_final']], 'exceeded': r['exceeded'],
                      'operator_release': r.get('operator_release'), 'chr2_after_operator_release': r.get('chr2_login_after_operator_release')} for r in runs}
    v['strict_never_exceeds'] = not any(r['exceeded'] for r in runs if r['rule'] == 'strict')
    v['horizon_rule_exceeded_in'] = [r['label'] for r in runs if r['rule'] == 'horizon' and r['exceeded']]
    record('H2B.PEND.p1_owner_scenario', {'verdict': v, 'detail': runs})
    return v


@case
def pend_concurrent():
    """P2: limit 2, Strict Mode. A CHR1 login (c2) is held pre_reply 12 s (past the 3s-timeout deadline); during the hold
    four clients on both NAS (c4, c5 on CHR1; c7, c8 on CHR2) log in at the same instant"""
    assert MODE[0].endswith('hold'), 'needs: h2b_gate.py site hold (or hsres2_hold)'
    u, pw = h2.subscriber('pc', 2)
    try:
        h2.fresh(['c2', 'c4', 'c5', 'c7', 'c8'])
        mac1 = h2.mac_of('c2')
        hold(mac1, 'pre_reply', 12.0)
        t_rfc = g.since()
        box = {}
        th = threading.Thread(target=lambda: box.__setitem__('r1', hs_login_long('c2', u, pw, timeout=50)))
        th.start()
        until(lambda: in_use(u) or None, 15, 0.2)
        held_claim = [(x['state'], x['slot']) for x in in_use(u)]
        res, bar = {}, threading.Barrier(4)
        def go(c):
            bar.wait()
            res[c] = (h2.login(c, u, pw) if c in ('c7', 'c8') else ag.hs_login(c, u, pw))['state']
        ts = [threading.Thread(target=go, args=(c,)) for c in ('c4', 'c5', 'c7', 'c8')]
        [t.start() for t in ts]
        [t.join(60) for t in ts]
        mid = [(x['state'], x['slot'], x['nas']) for x in in_use(u)]
        vr = [x['decision'] for x in verify_release(u, rule='strict')]
        th.join(60)
        time.sleep(3)
        smp = h2.sample(u, 6)
        v = {'held_claim': held_claim, 'concurrent': res, 'admitted_concurrent': sum(1 for x in res.values() if x == 'LOGGED_IN'), 'slots_mid': mid,
             'verifier_strict': vr, 'held_login': box.get('r1', {}).get('state'), 'live_max': smp['max'], 'slots_final': [(x['state'], x['end_reason']) for x in slots(u)],
             'freeradius': fr_tags(t_rfc, u)['tags']}
        v['pass'] = v['admitted_concurrent'] == 1 and smp['max']['total'] <= 2
    finally:
        hold_clear()
        for c in ('c2', 'c4', 'c5', 'c7', 'c8'):
            h2.logout(c)
        time.sleep(3)
        _cleanup_user(u)
    record('H2B.PEND.p2_concurrent', {'verdict': v})
    return v


def _syn_attrs(u, asid, mac, nas='10.88.0.10'):
    return [('User-Name', u), ('NAS-IP-Address', nas), ('Service-Type', 1), ('NAS-Port-Type', 19), ('NAS-Port', 2147483700),
            ('Acct-Session-Id', asid), ('Calling-Station-Id', mac), ('Called-Station-Id', 'nuxr_wp8_hs'), ('NAS-Identifier', 'nuxr-h2b-pend')]


def _acct(u, status, asid, mac, cls, nas='10.88.0.10'):
    st = {'Start': 1, 'Stop': 2, 'Interim-Update': 3}[status]
    attrs = [('User-Name', u), ('NAS-IP-Address', nas), ('Acct-Status-Type', st), ('Acct-Session-Id', asid), ('Calling-Station-Id', mac)]
    if cls:
        attrs.append(('Class', bytes.fromhex(cls)))
    r = g.exchange(g.accounting_request(attrs), port=1813)
    return r['reply'] and r['reply']['code']


@case
def pend_retx():
    """P3 (synthetic NAS 10.88.0.10, site *_hold): FreeRADIUS holds a claim pre_reply 8 s while the SAME packet is
    retransmitted at 0, 3, 6 s from the same source (RouterOS pattern) -> one claim, how many replies; then a copy after the
    reply (cached) and a NEW attempt (refused)"""
    assert MODE[0].endswith('hold'), 'needs: h2b_gate.py site hold (or hsres2_hold)'
    u, pw = h2.subscriber('pr', 1)
    mac = '02:AB:CD:00:01:77'
    try:
        hold(mac, 'pre_reply', 8.0)
        pkt = g.access_request(_syn_attrs(u, '81000177', mac), pw, chap=True)
        s_ = g.ns_socket()
        s_.settimeout(0.2)
        t0 = time.time()
        replies, sent = [], []
        for at in (0.0, 3.0, 6.0, 9.5):
            while time.time() - t0 < at:
                try:
                    raw, _ = s_.recvfrom(4096)
                    replies.append((round((time.time() - t0) * 1000), g.CODES.get(raw[0], raw[0]), hashlib.md5(raw).hexdigest()[:8]))
                except socket.timeout:
                    pass
            s_.sendto(pkt, (g.APP, 1812))
            sent.append(round((time.time() - t0) * 1000))
            mid = [(x['state'], x['attach']) for x in slots(u)]
        end = time.time() + 3
        while time.time() < end:
            try:
                raw, _ = s_.recvfrom(4096)
                replies.append((round((time.time() - t0) * 1000), g.CODES.get(raw[0], raw[0]), hashlib.md5(raw).hexdigest()[:8]))
            except socket.timeout:
                pass
        s_.close()
        new = g.exchange(g.access_request(_syn_attrs(u, '81000178', '02:AB:CD:00:01:78'), pw, chap=True))
        st = slots(u)
        v = {'sent_ms': sent, 'replies': replies, 'slots_during': mid, 'slots': [(x['state'], x['attach']) for x in st],
             'distinct_reply_bytes': len({r[2] for r in replies}), 'new_attempt': new['reply'] and new['reply']['code']}
        v['pass'] = len([x for x in st if x['state'] != 'ENDED']) == 1 and v['distinct_reply_bytes'] == 1 and all(r[1] == 'Access-Accept' for r in replies) \
            and v['new_attempt'] == 'Access-Reject' and replies and replies[0][0] >= 8000
    finally:
        hold_clear()
        _cleanup_user(u)
    record('H2B.PEND.p3_retransmission', {'verdict': v})
    return v


@case
def pend_fr_max_request_time():
    """P4 (informative, synthetic): a claim held pre_reply 35 s (> FreeRADIUS max_request_time) - does FreeRADIUS 3.2.8 still
    send the Access-Accept? (a server-side bound is a second layer only, never the safety argument)"""
    assert MODE[0].endswith('hold'), 'needs: h2b_gate.py site hold (or hsres2_hold)'
    u, pw = h2.subscriber('pm', 1)
    mac = '02:AB:CD:00:02:77'
    mrt = cg.sh(['docker', 'exec', FR, 'sh', '-c', "grep -E '^\\s*max_request_time' /etc/freeradius/radiusd.conf /opt/etc/raddb/radiusd.conf 2>/dev/null | head -2"], check=False).stdout.strip()
    try:
        hold(mac, 'pre_reply', 35.0)
        t_rfc = g.since()
        r = g.exchange(g.access_request(_syn_attrs(u, '81000277', mac), pw, chap=True), timeout=45)
        lg = cg.sh(['docker', 'logs', '--since', t_rfc, FR], check=False)
        lines = [l.strip()[:160] for l in (lg.stdout + lg.stderr).splitlines() if re.search(r'Unresponsive|max_request_time|Sent Access|Discarding|STOP|stop processing|unresponsive', l, re.I)]
        v = {'max_request_time_conf': mrt, 'reply': r['reply'] and r['reply']['code'], 'ms': r['ms'], 'log': lines[:8], 'slots': [(x['state'], x['end_reason']) for x in slots(u)]}
    finally:
        hold_clear()
        _cleanup_user(u)
    record('H2B.PEND.p4_fr_max_request_time', {'verdict': v})
    return v


@case
def design_accounting():
    """F1 (synthetic NAS 10.88.0.10, site hsres2*): accounting order and identity reuse. (1) Stop before Start, then the late
    Start and an Interim; (2) duplicate Start, duplicate Stop; (3) a stale Stop / Interim of an OLD claim after a NEW claim
    took the slot - same Acct-Session-Id and MAC (identity reuse); (4) a Stop with the right Class from another NAS; (5) a Stop
    without Class"""
    assert MODE[0].startswith('hsres2') or MODE[0] in ('project', 'hold'), 'needs: site project (H-2B implemented) or hsres2'
    u, pw = h2.subscriber('fa', 1)
    mac, asid = '02:AB:CD:00:03:77', '81000377'
    out = {}
    def claim(a=asid, m=mac):
        r = g.exchange(g.access_request(_syn_attrs(u, a, m), pw, chap=True))
        return r['reply'] and r['reply']['code'], g.reply_class(r)
    def st():
        return [(x['state'], x['end_reason']) for x in slots(u)]
    try:
        c, ca = claim()
        out['1_claim'] = (c, st())
        out['1_stop_before_start'] = (_acct(u, 'Stop', asid, mac, ca), st())
        out['1_late_start'] = (_acct(u, 'Start', asid, mac, ca), st())
        out['1_late_interim'] = (_acct(u, 'Interim-Update', asid, mac, ca), st())
        c, cb = claim()
        out['2_claim'] = (c, st())
        out['2_start_x2'] = (_acct(u, 'Start', asid, mac, cb), _acct(u, 'Start', asid, mac, cb), st())
        out['2_interim'] = (_acct(u, 'Interim-Update', asid, mac, cb), st())
        out['2_stop_x2'] = (_acct(u, 'Stop', asid, mac, cb), _acct(u, 'Stop', asid, mac, cb), st())
        c, cc = claim()                                          # NEW claim, SAME Acct-Session-Id and MAC (reuse)
        _acct(u, 'Start', asid, mac, cc)
        out['3_new_claim_active'] = (c, st(), cc != cb)
        out['3_stale_stop_old_claim'] = (_acct(u, 'Stop', asid, mac, cb), st())
        out['3_stale_interim_old_claim'] = (_acct(u, 'Interim-Update', asid, mac, cb), st())
        out['3_stale_stop_first_claim'] = (_acct(u, 'Stop', asid, mac, ca), st())
        out['4_stop_right_class_other_nas'] = (_acct(u, 'Stop', asid, mac, cc, nas='10.88.0.3'), st())
        out['5_stop_without_class'] = (_acct(u, 'Stop', asid, mac, None), st())
        out['6_stop_right_class'] = (_acct(u, 'Stop', asid, mac, cc), st())
        out['audit_orphan'] = sqlrows(f"SELECT action, outcome FROM nuxr_hs_admission_audit WHERE actor = 'freeradius' AND username = CONVERT(X'{hexs(u)}' USING utf8mb4) ORDER BY id")
    finally:
        _cleanup_user(u)
    v = {k: w for k, w in out.items()}
    v['pass'] = (out['1_stop_before_start'][1] == [('ENDED', 'acct_stop')] and out['1_late_start'][1] == [('ENDED', 'acct_stop')]
                 and out['1_late_interim'][1] == [('ENDED', 'acct_stop')] and out['2_start_x2'][2] == [('ACTIVE', '-')]
                 and out['2_stop_x2'][2] == [('ENDED', 'acct_stop')] and out['3_new_claim_active'][1] == [('ACTIVE', '-')]
                 and all(out[k][1] == [('ACTIVE', '-')] for k in ('3_stale_stop_old_claim', '3_stale_interim_old_claim', '3_stale_stop_first_claim',
                                                                 '4_stop_right_class_other_nas', '5_stop_without_class'))
                 and out['6_stop_right_class'][1] == [('ENDED', 'acct_stop')])
    record('H2B.DESIGN.f1_accounting_order_reuse', {'verdict': v})
    return v


def _set_limit(u, n):
    sql(f"UPDATE radcheck SET value = '{n}' WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) COLLATE utf8mb4_bin AND attribute = 'Simultaneous-Use'; "
        f"UPDATE radreply SET value = '{n}' WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) COLLATE utf8mb4_bin AND attribute = 'Port-Limit'")


@case
def design_limit_change():
    """F2 (real NAS, site hsres2*): limit 2 with two live sessions (CHR1 c2, CHR2 c7); limit decreased to 1; new logins,
    a Stop of the slot above the new limit, then the remaining session ends; then the limit increased to 2 again"""
    assert MODE[0].startswith('hsres2') or MODE[0] in ('project', 'hold'), 'needs: site project (H-2B implemented) or hsres2'
    u, pw = h2.subscriber('fl', 2)
    out = {}
    def st():
        return [(x['slot'], x['state'], x['nas']) for x in slots(u)]
    try:
        h2.fresh(['c2', 'c4', 'c7', 'c8'])
        out['a_c2'] = h2.login('c2', u, pw)['state']
        out['a_c7'] = h2.login('c7', u, pw)['state']
        until(lambda: len([x for x in slots(u) if x['state'] == 'ACTIVE']) == 2 or None, 15, 0.5)
        out['a_slots'] = st()
        _set_limit(u, 1)
        out['b_c4_after_decrease'] = h2.login('c4', u, pw)['state']
        out['b_live'] = h2.live(u)['total']
        h2.logout('c7')
        until(lambda: len(in_use(u)) == 1 or None, 15, 0.5)
        out['c_slots_after_c7_stop'] = st()
        out['c_c8_one_in_use'] = h2.login('c8', u, pw)['state']
        h2.logout('c2')
        until(lambda: in_use(u) == [] or None, 15, 0.5)
        out['d_c4_none_in_use'] = h2.login('c4', u, pw)['state']
        until(lambda: in_use(u) or None, 10, 0.5)
        out['d_slots'] = st()
        out['d_c8_full'] = h2.login('c8', u, pw)['state']
        _set_limit(u, 2)
        out['e_c8_after_increase'] = h2.login('c8', u, pw)['state']
        time.sleep(2)
        out['e_live'] = h2.live(u)['total']
        out['e_slots'] = st()
    finally:
        for c in ('c2', 'c4', 'c7', 'c8'):
            h2.logout(c)
        time.sleep(3)
        _cleanup_user(u)
    v = dict(out)
    v['pass'] = (out['a_c2'] == out['a_c7'] == 'LOGGED_IN' and out['b_c4_after_decrease'] == 'REFUSED' and out['c_c8_one_in_use'] == 'REFUSED'
                 and out['d_c4_none_in_use'] == 'LOGGED_IN' and out['d_c8_full'] == 'REFUSED' and out['e_c8_after_increase'] == 'LOGGED_IN' and out['e_live'] == 2)
    record('H2B.DESIGN.f2_limit_change', {'verdict': v})
    return v


@case
def design_snmp_absent():
    """F3 (real NAS, site hsres2*, Strict Mode verifier): an ACTIVE slot whose Stop was lost; the verifier with (1) SNMP
    disabled on CHR1, (2) a wrong community (the verifier's), (3) SNMP back -> only (3) releases; a PENDING is never
    released"""
    assert MODE[0].startswith('hsres2') or MODE[0] in ('project', 'hold'), 'needs: site project (H-2B implemented) or hsres2'
    u, pw = h2.subscriber('fs', 1)
    out = {}
    try:
        h2.fresh(['c2', 'c7'])
        out['login'] = h2.login('c2', u, pw)['state']
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        rid = g.block_acct()
        try:
            h2.logout('c2')
            until(lambda: h2.live(u)['chr1'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            g.unblock(rid)
        out['stale'] = [x['state'] for x in in_use(u)]
        h.ros_do('/snmp/set', '=enabled=no')
        try:
            out['1_snmp_disabled'] = [(x['decision'], (x.get('error') or '')[:60]) for x in verify_release(u, rule='strict')]
        finally:
            h.ros_do('/snmp/set', '=enabled=yes')
        real = cg.community
        cg.community = lambda: 'wrong-' + secrets.token_hex(4)
        try:
            out['2_wrong_community'] = [(x['decision'], (x.get('error') or '')[:60]) for x in verify_release(u, rule='strict')]
        finally:
            cg.community = real
        time.sleep(2)
        out['3_snmp_ok'] = [x['decision'] for x in verify_release(u, rule='strict')]
        out['3_c7_after'] = h2.login('c7', u, pw)['state']
        out['audit'] = sqlrows(f"SELECT action, outcome FROM nuxr_hs_admission_audit WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) ORDER BY id")
    finally:
        h.ros_do('/snmp/set', '=enabled=yes')
        h2.logout('c2'); h2.logout('c7')
        time.sleep(3)
        _cleanup_user(u)
    v = dict(out)
    v['pass'] = (out['stale'] == ['ACTIVE'] and all(d[0] == 'keep:verify-error' for d in out['1_snmp_disabled']) and all(d[0] == 'keep:verify-error' for d in out['2_wrong_community'])
                 and out['3_snmp_ok'] == ['released'] and out['3_c7_after'] == 'LOGGED_IN')
    record('H2B.DESIGN.f3_snmp_absent', {'verdict': v})
    return v


@case
def design_legacy_simuse():
    """F4 (real NAS, sites hsres vs hsres2): a lost Stop leaves an open radacct row on CHR1; the Strict verifier releases the
    ACTIVE slot on SNMP proof; THEN SNMP to CHR1 fails (management path down). Does the stock radacct + checkrad
    Simultaneous-Use check refuse the legitimate CHR2 login before the slots are consulted?"""
    assert MODE[0] in ('hsres', 'hsres2'), 'needs: h2b_gate.py site hsres | hsres2'
    u, pw = h2.subscriber('fu', 1)
    out = {'site': MODE[0]}
    try:
        h2.fresh(['c2', 'c7'])
        out['login'] = h2.login('c2', u, pw)['state']
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        rid = g.block_acct()
        try:
            h2.logout('c2')
            until(lambda: h2.live(u)['chr1'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            g.unblock(rid)
        out['radacct_open'] = len(h2.open_rows(u))
        out['verifier'] = [x['decision'] for x in verify_release(u, rule='strict')]
        ids = block_mgmt(1, ports=(161,))
        try:
            t_rfc = g.since()
            cap = f'h2b-f4-{MODE[0]}'
            ag.capture_start(cap)
            t0 = time.time()
            r = h2.login('c7', u, pw)
            time.sleep(1)
            w = wire(ag.capture_stop(cap)['file'], t0, u)
            out['c7_login'] = r['state']
            out['c7_message'] = r.get('message')
            out['wire'] = [(x['code'], x['t_ms'] - w[0]['t_ms']) for x in w if x['code'].startswith('Access')] if w else []
            out['freeradius'] = fr_tags(t_rfc, u)['tags']
            lg = cg.sh(['docker', 'logs', '--since', t_rfc, FR], check=False)
            out['checkrad_lines'] = [l.strip()[:150] for l in (lg.stdout + lg.stderr).splitlines() if re.search(r'checkrad|terminal server', l)][:4]
        finally:
            unblock_mgmt(1, ids)
        out['live'] = h2.live(u)
    finally:
        h2.logout('c2'); h2.logout('c7')
        time.sleep(3)
        sql(f"UPDATE radacct SET acctstoptime = NOW() WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) AND acctstoptime IS NULL")
        _cleanup_user(u)
    v = dict(out)
    record('H2B.DESIGN.f4_legacy_simultaneous_use', {'verdict': v})
    return v


@case
def design_timing():
    """F5 (real NAS, current site): Access-Request -> reply on the wire for 30 sequential logins (CHR1) and 6 bursts of 4
    concurrent logins on both NAS (limit 4) - min / p50 / p95 / MAX against the NAS per-transmission timeout (3 s) and the
    AV-5 budget (5000 ms)"""
    seq, burst = [], []
    u, pw = h2.subscriber('ft', 1)
    try:
        for i in range(30):
            h2.fresh(['c2']) if i % 10 == 0 else None
            cap = f'h2b-f5-seq-{i}'
            ag.capture_start(cap)
            t0 = time.time()
            ag.hs_login('c2', u, pw)
            w = wire(ag.capture_stop(cap)['file'], t0, u)
            rq = [x for x in w if x['code'] == 'Access-Request']
            rp = [x for x in w if x['code'] in ('Access-Accept', 'Access-Reject')]
            if rq and rp:
                seq.append({'ms': rp[0]['t_ms'] - rq[0]['t_ms'], 'code': rp[0]['code']})
            h2.logout('c2')
            until(lambda: in_use(u) == [] or None, 20, 0.3) if MODE[0].startswith('hsres') or MODE[0] in ('project', 'hold') else time.sleep(2)
    finally:
        _cleanup_user(u)
    u, pw = h2.subscriber('ftb', 4)
    try:
        for i in range(6):
            h2.fresh(['c2', 'c4', 'c7', 'c8'])
            cap = f'h2b-f5-burst-{i}'
            ag.capture_start(cap)
            t0 = time.time()
            bar = threading.Barrier(4)
            def go(c):
                bar.wait()
                (h2.login(c, u, pw) if c in ('c7', 'c8') else ag.hs_login(c, u, pw))
            ts = [threading.Thread(target=go, args=(c,)) for c in ('c2', 'c4', 'c7', 'c8')]
            [t.start() for t in ts]
            [t.join(60) for t in ts]
            w = wire(ag.capture_stop(cap)['file'], t0, u)
            for q in [x for x in w if x['code'] == 'Access-Request']:
                rp = next((x for x in w if x['code'] in ('Access-Accept', 'Access-Reject') and x['answers_auth8'] == q['auth8']), None)
                if rp:
                    burst.append({'ms': rp['t_ms'] - q['t_ms'], 'code': rp['code']})
            for c in ('c2', 'c4', 'c7', 'c8'):
                h2.logout(c)
            until(lambda: in_use(u) == [] or None, 20, 0.3) if MODE[0].startswith('hsres') or MODE[0] in ('project', 'hold') else time.sleep(2)
    finally:
        _cleanup_user(u)
    def stats(xs):
        ms = sorted(x['ms'] for x in xs)
        return {'n': len(ms), 'min': ms[0], 'p50': ms[len(ms) // 2], 'p95': ms[min(len(ms) - 1, int(len(ms) * 0.95))], 'max': ms[-1],
                'codes': sorted({x['code'] for x in xs})} if ms else None
    v = {'site': MODE[0], 'sequential': stats(seq), 'concurrent_bursts': stats(burst)}
    record('H2B.DESIGN.f5_timing', {'verdict': v, 'detail': {'seq': seq, 'burst': burst}})
    return v


# ================================================================================================ H-2B IMPLEMENTATION GATES
# Owner 2026-10-11 "WP8 H-2B Implementation Authorization": the slots, the inline Hotspot slot verifier and Strict Mode
# are part of the PROJECT site (install/freeradius). These cases run against site `project` (or `hold` = the project
# site + the two lab-only hold points). No lab-side release exists here: the only automatic release is the verifier
# called by FreeRADIUS itself, and only for ACTIVE slots.
def report_sql():
    """install/freeradius/nuxr_hs_admission_report.sql (read-only) -> its ALERT / INFO lines"""
    out = sql(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'install', 'freeradius', 'nuxr_hs_admission_report.sql')).read())
    return [l.split('\t')[:4] for l in out.splitlines() if l.startswith(('ALERT', 'INFO'))]


def chr2_acct_block():
    h2.r2(['/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_h2b_acct'])
    return [x for x in h2.r2(['/ip/firewall/filter/print']) if x.get('comment') == 'nuxr_wp8_h2b_acct'][-1]['.id']


def chr2_unblock(i):
    h2.r2(['/ip/firewall/filter/remove', '=.id=' + i])


def impl_stall(label, nas_timeout, point, hold_s=22.0):
    """a CHR1 login (c2) is held at `point` for hold_s; at ~17 s a CHR2 login (c7) tries (the inline verifier sees no
    ACTIVE slot - a PENDING one is never a candidate); then c2 resumes. limit 1."""
    u, pw = h2.subscriber('is', 1)
    res = {'label': label, 'point': point, 'hold_s': hold_s}
    old = chr1_radius_timeout()
    try:
        res['chr1_radius_timeout'] = chr1_radius_timeout(nas_timeout) if nas_timeout != old else old
        h2.fresh(['c2', 'c7'])
        mac1 = h2.mac_of('c2')
        hold(mac1, point, hold_s)
        cap = f'h2b-impl-stall-{label}'
        ag.capture_start(cap)
        t_rfc = g.since()
        t0 = time.time()
        box = {}
        th = threading.Thread(target=lambda: box.__setitem__('r1', hs_login_long('c2', u, pw, timeout=hold_s + 40)))
        th.start()
        claimed = until(lambda: in_use(u) or None, 15, 0.2)
        res['claim'] = [(x['state'], x['nas']) for x in (claimed or [])]
        time.sleep(max(0.0, 17.0 - (time.time() - t0)))
        ok, rows, err, ms = snmp_sessions(NAS[1])
        res['snmp_chr1_at_17s'] = {'ok': ok, 'sessions_of_user': [r for r in rows if r[0] == u]}
        r2 = h2.login('c7', u, pw)
        res['chr2_login'] = {'state': r2['state'], 't_s': round(time.time() - t0, 1)}
        res['slots_before_resume'] = [(x['state'], x['nas']) for x in slots(u)]
        th.join(hold_s + 60)
        r1 = box.get('r1', {'state': 'NO_RESULT'})
        res['chr1_resumed'] = {'state': r1['state'], 'message': (r1.get('message') or '')[:40], 'page_s': r1.get('page_s'), 'hold_fired': hold_fired(mac1, point)}
        time.sleep(3)
        smp = h2.sample(u, 8)
        res['live_max'] = smp['max']['total']
        res['slots_after'] = [(x['state'], x['nas'], x['end_reason']) for x in slots(u)]
        res['freeradius'] = fr_tags(t_rfc, u)['tags']
        time.sleep(1)
        w = wire(ag.capture_stop(cap)['file'], t0, u)
        f1 = _first_req_ms(w, '10.88.0.2')
        res['chr1_reply_after_first_request_ms'] = [(x['code'], x['t_ms'] - f1) for x in w if x['code'] in ('Access-Accept', 'Access-Reject') and x['to'] == '10.88.0.2'] if f1 is not None else None
        res['chr1_requests_ms'] = [x['t_ms'] - f1 for x in w if x['code'] == 'Access-Request' and x['src'] == '10.88.0.2'] if f1 is not None else None
        if [x for x in in_use(u) if x['state'] == 'PENDING']:
            # Strict Mode: no time-based release - still PENDING and still refusing after > 60 s; the read-only report alerts
            time.sleep(max(0.0, 66.0 - (time.time() - t0)))
            res['chr2_login_after_66s'] = h2.login('c7', u, pw)['state']
            res['still_pending_after_66s'] = [x['state'] for x in in_use(u)]
            res['report'] = [r for r in report_sql() if len(r) > 1 and r[1] == u]
    finally:
        try:
            if chr1_radius_timeout() != old:
                chr1_radius_timeout(old)
        finally:
            hold_clear()
            h2.logout('c2'); h2.logout('c7')
            time.sleep(3)
            _cleanup_user(u)
    res['exceeded'] = res.get('live_max', 0) > 1
    return res


@case
def impl_pending_stall():
    """Strict PENDING on the implemented site (`hold`): stall AFTER the last age check (pre_reply) and BEFORE it
    (post_claim), CHR1 /radius timeout 3s (NAS window ~9 s) and 10s (~30 s)"""
    assert MODE[0] == 'hold', 'needs: h2b_gate.py site hold'
    runs = [impl_stall('prereply_t3', '3s', 'pre_reply'), impl_stall('prereply_t10', '10s', 'pre_reply'),
            impl_stall('postclaim_t3', '3s', 'post_claim'), impl_stall('postclaim_t10', '10s', 'post_claim')]
    v = {r['label']: {k: r.get(k) for k in ('chr2_login', 'chr1_resumed', 'chr1_reply_after_first_request_ms', 'live_max', 'slots_after', 'freeradius',
                                             'chr2_login_after_66s', 'still_pending_after_66s', 'report', 'exceeded')} for r in runs}
    v['pass'] = (not any(r['exceeded'] for r in runs) and all(r['chr2_login']['state'] == 'REFUSED' for r in runs)
                 and v['prereply_t3']['still_pending_after_66s'] == ['PENDING'] and v['prereply_t3']['chr2_login_after_66s'] == 'REFUSED'
                 and v['prereply_t10']['live_max'] == 1 and [x[0] for x in v['prereply_t10']['slots_after']] == ['ACTIVE']
                 and all([x[2] for x in v[k]['slots_after']] == ['late_claim'] for k in ('postclaim_t3', 'postclaim_t10')))
    record('H2B.IMPL.pending_stall', {'verdict': v, 'detail': runs})
    return v


@case
def impl_verifier_never_pending():
    """limit 2 on the implemented site (`hold`, CHR1 timeout 10s): slot A = an ACTIVE session of c4 whose Stop was lost;
    slot B = a c2 claim held pre_reply 20 s (PENDING, its Accept will still be taken). A CHR2 login (c7) during the hold:
    the inline verifier may only release A (proven gone) - never B; then c2's late Accept arrives. live <= 2"""
    assert MODE[0] == 'hold', 'needs: h2b_gate.py site hold'
    u, pw = h2.subscriber('vp', 2)
    old = chr1_radius_timeout()
    v = {}
    try:
        chr1_radius_timeout('10s')
        h2.fresh(['c2', 'c4', 'c7'])
        v['c4_login'] = h2.login('c4', u, pw)['state']
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        rid = g.block_acct()
        try:
            h2.logout('c4')
            until(lambda: h2.live(u)['chr1'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            g.unblock(rid)
        v['slot_a_stale'] = [(x['slot'], x['state']) for x in in_use(u)]
        hold(h2.mac_of('c2'), 'pre_reply', 20.0)
        t_rfc = g.since()
        box = {}
        th = threading.Thread(target=lambda: box.__setitem__('r1', hs_login_long('c2', u, pw, timeout=60)))
        th.start()
        until(lambda: len(in_use(u)) == 2 or None, 15, 0.2)
        v['slots_full'] = [(x['slot'], x['state']) for x in in_use(u)]
        time.sleep(2)
        v['c7_login'] = h2.login('c7', u, pw)['state']
        v['slots_after_c7'] = [(x['slot'], x['state'], x['nas'], x['end_reason']) for x in slots(u)]
        th.join(80)
        v['c2_resumed'] = box.get('r1', {}).get('state')
        time.sleep(3)
        v['live_max'] = h2.sample(u, 6)['max']['total']
        v['slots_final'] = [(x['slot'], x['state'], x['nas']) for x in slots(u)]
        v['audit'] = sqlrows(f"SELECT action, outcome, slot FROM nuxr_hs_admission_audit WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) ORDER BY id")
        v['freeradius'] = fr_tags(t_rfc, u)['tags']
    finally:
        try:
            chr1_radius_timeout(old)
        finally:
            hold_clear()
            for c in ('c2', 'c4', 'c7'):
                h2.logout(c)
            time.sleep(3)
            _cleanup_user(u)
    v['pass'] = (v.get('c7_login') == 'LOGGED_IN' and v.get('c2_resumed') == 'LOGGED_IN' and v.get('live_max') == 2
                 and v.get('audit') == [['verified_release', 'proven_gone', '1']] and sorted(x[1] for x in v.get('slots_final', [])) == ['ACTIVE', 'ACTIVE'])
    record('H2B.IMPL.verifier_never_pending', {'verdict': v})
    return v


@case
def impl_lost_accounting():
    """implemented site: lost Accounting-Start (PENDING; CHR2 refused; the Interim-Update with the Class confirms it),
    lost Interim-Update (ACTIVE stays, CHR2 refused - the verifier sees the live session), lost Stop (the next CHR2 login
    is admitted after the inline verifier proves the CHR1 session gone; audit row)"""
    assert MODE[0] in ('project', 'hold'), 'needs: site project'
    out = {}
    u, pw = h2.subscriber('la', 1)
    try:
        h2.fresh(['c2', 'c7'])
        rid = g.block_acct()
        try:
            out['start_lost_login'] = h2.login('c2', u, pw)['state']
            time.sleep(3)
            out['start_lost_slot'] = [x['state'] for x in in_use(u)]
            out['start_lost_chr2'] = h2.login('c7', u, pw)['state']
        finally:
            g.unblock(rid)
        out['interim_confirms'] = until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 80, 2) is not None
        rid = g.block_acct()
        try:
            time.sleep(70)                                   # one Interim-Update (1 m) dropped
            out['interim_lost_slot'] = [x['state'] for x in in_use(u)]
            t0 = time.time()
            out['interim_lost_chr2'] = h2.login('c7', u, pw)['state']
            out['interim_lost_chr2_s'] = round(time.time() - t0, 2)
            h2.logout('c2')                                  # and now the Stop is lost too
            until(lambda: h2.live(u)['chr1'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            g.unblock(rid)
        out['stop_lost_slot'] = [x['state'] for x in in_use(u)]
        out['radacct_open'] = len(h2.open_rows(u))
        t0 = time.time()
        out['stop_lost_chr2'] = h2.login('c7', u, pw)['state']
        out['stop_lost_chr2_s'] = round(time.time() - t0, 2)
        time.sleep(3)
        out['slots_final'] = [(x['state'], x['nas']) for x in in_use(u)]
        out['audit'] = sqlrows(f"SELECT action, outcome FROM nuxr_hs_admission_audit WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) ORDER BY id")
        out['live'] = h2.live(u)['total']
    finally:
        h2.logout('c2'); h2.logout('c7')
        time.sleep(3)
        sql(f"UPDATE radacct SET acctstoptime = NOW() WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) AND acctstoptime IS NULL")
        _cleanup_user(u)
    v = dict(out)
    v['pass'] = (out['start_lost_slot'] == ['PENDING'] and out['start_lost_chr2'] == 'REFUSED' and out['interim_confirms']
                 and out['interim_lost_slot'] == ['ACTIVE'] and out['interim_lost_chr2'] == 'REFUSED' and out['stop_lost_slot'] == ['ACTIVE']
                 and out['stop_lost_chr2'] == 'LOGGED_IN' and out['audit'] == [['verified_release', 'proven_gone']] and out['live'] == 1)
    record('H2B.IMPL.lost_accounting', {'verdict': v})
    return v


def _creds_set(content):
    cg.sh(['docker', 'exec', '-i', FR, 'sh', '-c', 'umask 077; cat > /etc/freeradius/nuxr/nas_snmp.tmp && chown freerad:freerad /etc/freeradius/nuxr/nas_snmp.tmp && mv /etc/freeradius/nuxr/nas_snmp.tmp /etc/freeradius/nuxr/nas_snmp'], inp=content)


def _creds_get():
    return cg.sh(['docker', 'exec', FR, 'cat', '/etc/freeradius/nuxr/nas_snmp']).stdout


@case
def impl_snmp_and_nas():
    """implemented site, limit 1, a CHR1 session whose Stop was lost (slot ACTIVE): a CHR2 login with (1) SNMP disabled on
    CHR1, (2) a wrong community for CHR1 in the verifier's credential file, (3) no credential entry for CHR1, (4) the
    credential file missing, (5) the verifier program missing -> all refused, slot kept; (6) everything restored ->
    admitted (verifier proves the session gone). Then NAS failure: a CHR2 session (Stop lost), CHR2 rebooted: a CHR1 login
    while CHR2 is down -> refused (verifier ERROR); after CHR2 is back without the session -> admitted. Wire latencies."""
    assert MODE[0] in ('project', 'hold'), 'needs: site project'
    out = {}
    u, pw = h2.subscriber('sn', 1)
    creds = _creds_get()
    def lost_stop_on(client, nas):
        out.setdefault('setup', []).append(h2.login(client, u, pw)['state'])
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        if nas == 1:
            rid = g.block_acct()
        else:
            rid = chr2_acct_block()
        try:
            h2.logout(client)
            until(lambda: h2.live(u)['chr1' if nas == 1 else 'chr2'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            (g.unblock(rid) if nas == 1 else chr2_unblock(rid))
    def attempt(client, label):
        cap = f'h2b-impl-sn-{label}'
        ag.capture_start(cap)
        t0 = time.time()
        st = (h2.login(client, u, pw) if client in ('c7', 'c8') else ag.hs_login(client, u, pw))['state']
        time.sleep(1)
        w = wire(ag.capture_stop(cap)['file'], t0, u)
        rq = [x for x in w if x['code'] == 'Access-Request']
        rp = [x for x in w if x['code'] in ('Access-Accept', 'Access-Reject')]
        return {'state': st, 'wire_ms': (rp[0]['t_ms'] - rq[0]['t_ms']) if rq and rp else None, 'reply': rp[0]['code'] if rp else None,
                'slots': [x['state'] for x in in_use(u)]}
    try:
        h2.fresh(['c2', 'c7'])
        t_rfc = g.since()
        lost_stop_on('c2', 1)
        out['stale'] = [x['state'] for x in in_use(u)]
        h.ros_do('/snmp/set', '=enabled=no')
        try:
            out['1_snmp_disabled'] = attempt('c7', 'snmpoff')
        finally:
            h.ros_do('/snmp/set', '=enabled=yes')
        time.sleep(2)
        _creds_set('\n'.join(('10.88.0.2 wrong' + secrets.token_hex(4)) if l.startswith('10.88.0.2 ') else l for l in creds.splitlines()) + '\n')
        try:
            out['2_wrong_community'] = attempt('c7', 'wrongcomm')
            _creds_set('\n'.join(l for l in creds.splitlines() if not l.startswith('10.88.0.2 ')) + '\n')
            out['3_no_entry'] = attempt('c7', 'noentry')
            cg.sh(['docker', 'exec', FR, 'rm', '-f', '/etc/freeradius/nuxr/nas_snmp'])
            out['4_no_file'] = attempt('c7', 'nofile')
        finally:
            _creds_set(creds)
        cg.sh(['docker', 'exec', FR, 'mv', '/etc/freeradius/nuxr/nuxr_hotspot_slot_verifier', '/etc/freeradius/nuxr/nuxr_hotspot_slot_verifier.off'])
        try:
            out['5_no_verifier'] = attempt('c7', 'noverifier')
        finally:
            cg.sh(['docker', 'exec', FR, 'mv', '/etc/freeradius/nuxr/nuxr_hotspot_slot_verifier.off', '/etc/freeradius/nuxr/nuxr_hotspot_slot_verifier'])
        out['6_restored'] = attempt('c7', 'restored')
        out['tags_1_6'] = fr_tags(t_rfc, u)['tags']
        h2.logout('c7')
        until(lambda: in_use(u) == [] or None, 20, 0.5)
        # ---- NAS failure / restart: a CHR2 session whose Stop is lost, CHR2 rebooted
        h2.fresh(['c2', 'c7'])
        out['7_chr2_login'] = h2.login('c7', u, pw)['state']
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        rid = chr2_acct_block()                              # persists across the reboot: the reboot Stop is lost
        try:
            r = g.ros2()
            try:
                r.talk(['/system/reboot'])
            except Exception:
                pass
            time.sleep(6)
            out['7_chr1_login_chr2_down'] = attempt('c2', 'chr2down')
            def back():
                try:
                    return bool(h2.r2(['/system/identity/print'])) or None
                except Exception:
                    return None
            out['7_chr2_back'] = until(back, 300, 5) is not None
            time.sleep(10)
            out['7_slot_after_reboot'] = [(x['state'], x['nas']) for x in in_use(u)]
            out['7_chr1_login_after'] = attempt('c2', 'chr2back')
        finally:
            try:
                chr2_unblock(rid)
            except Exception as ex:
                out['7_unblock_error'] = repr(ex)[:120]
        time.sleep(3)
        out['live'] = h2.live(u)
        out['audit'] = sqlrows(f"SELECT action, outcome, LEFT(evidence, 140) FROM nuxr_hs_admission_audit WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) ORDER BY id")
    finally:
        _creds_set(creds)
        cg.sh(['docker', 'exec', FR, 'sh', '-c', 'test -x /etc/freeradius/nuxr/nuxr_hotspot_slot_verifier || mv /etc/freeradius/nuxr/nuxr_hotspot_slot_verifier.off /etc/freeradius/nuxr/nuxr_hotspot_slot_verifier'], check=False)
        h.ros_do('/snmp/set', '=enabled=yes')
        h2.logout('c2'); h2.logout('c7')
        time.sleep(3)
        sql(f"UPDATE radacct SET acctstoptime = NOW() WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) AND acctstoptime IS NULL")
        _cleanup_user(u)
    v = dict(out)
    refused = ('1_snmp_disabled', '2_wrong_community', '3_no_entry', '4_no_file', '5_no_verifier')
    v['pass'] = (all(out[k]['state'] == 'REFUSED' and out[k]['slots'] == ['ACTIVE'] for k in refused) and out['6_restored']['state'] == 'LOGGED_IN'
                 and out['7_chr1_login_chr2_down']['state'] == 'REFUSED' and out['7_chr2_back'] and out['7_chr1_login_after']['state'] == 'LOGGED_IN'
                 and out['live']['total'] == 1 and len(out['audit']) == 2)
    record('H2B.IMPL.snmp_and_nas', {'verdict': v})
    return v


@case
def impl_component_failures():
    """implemented site: (1) the slot table missing (renamed), (2) the audit table missing while a verified release is
    needed, (3) the RADIUS database paused during a login -> every Hotspot login refused, nothing half-written; restored ->
    admitted. limit 1."""
    assert MODE[0] in ('project', 'hold'), 'needs: site project'
    out = {}
    u, pw = h2.subscriber('cf', 1)
    try:
        h2.fresh(['c2', 'c7'])
        sql('RENAME TABLE nuxr_hs_admission TO nuxr_hs_admission_off')
        try:
            out['1_table_missing'] = h2.login('c2', u, pw)['state']
            out['1_live'] = h2.live(u)['total']
        finally:
            sql('RENAME TABLE nuxr_hs_admission_off TO nuxr_hs_admission')
        out['1_restored'] = h2.login('c2', u, pw)['state']
        until(lambda: [x for x in slots(u) if x['state'] == 'ACTIVE'] or None, 15, 0.5)
        rid = g.block_acct()
        try:
            h2.logout('c2')
            until(lambda: h2.live(u)['chr1'] == 0 or None, 20, 0.5)
            time.sleep(3)
        finally:
            g.unblock(rid)
        sql('RENAME TABLE nuxr_hs_admission_audit TO nuxr_hs_admission_audit_off')
        try:
            out['2_audit_missing'] = h2.login('c7', u, pw)['state']
            out['2_slot_kept'] = [x['state'] for x in in_use(u)]
        finally:
            sql('RENAME TABLE nuxr_hs_admission_audit_off TO nuxr_hs_admission_audit')
        out['2_restored'] = h2.login('c7', u, pw)['state']
        h2.logout('c7')
        until(lambda: in_use(u) == [] or None, 20, 0.5)
        cg.sh(['docker', 'pause', g.DB])
        try:
            out['3_db_paused'] = h2.login('c2', u, pw)['state']
        finally:
            cg.sh(['docker', 'unpause', g.DB])
        time.sleep(3)
        out['3_slots_after'] = [x['state'] for x in in_use(u)]
        out['3_restored'] = h2.login('c2', u, pw)['state']
        time.sleep(2)
        out['live'] = h2.live(u)['total']
    finally:
        for t in ('nuxr_hs_admission', 'nuxr_hs_admission_audit'):
            if sqlrows(f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'nuxr_radius' AND table_name = '{t}_off'")[0][0] == '1':
                sql(f'RENAME TABLE {t}_off TO {t}')
        h2.logout('c2'); h2.logout('c7')
        time.sleep(3)
        sql(f"UPDATE radacct SET acctstoptime = NOW() WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) AND acctstoptime IS NULL")
        _cleanup_user(u)
    v = dict(out)
    v['pass'] = (out['1_table_missing'] == 'REFUSED' and out['1_live'] == 0 and out['1_restored'] == 'LOGGED_IN' and out['2_audit_missing'] == 'REFUSED'
                 and out['2_slot_kept'] == ['ACTIVE'] and out['2_restored'] == 'LOGGED_IN' and out['3_db_paused'] == 'REFUSED' and out['3_slots_after'] == []
                 and out['3_restored'] == 'LOGGED_IN' and out['live'] == 1)
    record('H2B.IMPL.component_failures', {'verdict': v})
    return v


@case
def impl_synthetic_burst():
    """implemented site, synthetic NAS 10.88.0.10: N RouterOS-shaped Hotspot Access-Requests of ONE subscriber (distinct
    sessions and MACs) sent at the same instant from N sockets, limit 1 / 2 / 3 / 5, 3 rounds each -> never more Accepts
    than the limit, slots in use = Accepts; then every admitted claim ended by its own Stop"""
    assert MODE[0] in ('project', 'hold'), 'needs: site project'
    rounds = []
    for limit in (1, 2, 3, 5):
        for rnd in range(3):
            u, pw = h2.subscriber('sb', limit)
            n = 16
            try:
                ids = [(f'8{rnd}{limit}{i:05d}', '02:5B:%02X:%02X:%02X:%02X' % (limit, rnd, i, 7)) for i in range(n)]
                pk = [g.access_request(_syn_attrs(u, a, m), pw, chap=True) for a, m in ids]
                socks = [g.ns_socket() for _ in range(n)]
                res = [None] * n
                bar = threading.Barrier(n)
                def go(j):
                    bar.wait()
                    res[j] = g.exchange(pk[j], sock=socks[j], timeout=10)
                ts = [threading.Thread(target=go, args=(j,)) for j in range(n)]
                [t.start() for t in ts]
                [t.join(30) for t in ts]
                [x.close() for x in socks]
                acc = [j for j in range(n) if res[j] and res[j]['reply'] and res[j]['reply']['code'] == 'Access-Accept']
                rej = [j for j in range(n) if res[j] and res[j]['reply'] and res[j]['reply']['code'] == 'Access-Reject']
                used = len(in_use(u))
                for j in acc:
                    _acct(u, 'Stop', ids[j][0], ids[j][1], g.reply_class(res[j]))
                rounds.append({'limit': limit, 'round': rnd, 'requests': n, 'accepts': len(acc), 'rejects': len(rej), 'no_reply': n - len(acc) - len(rej),
                               'slots_in_use': used, 'in_use_after_stops': len(in_use(u)),
                               'max_ms': max((res[j]['ms'] for j in range(n) if res[j]), default=None)})
            finally:
                _cleanup_user(u)
    v = {'rounds': rounds, 'never_above_limit': all(r['accepts'] <= r['limit'] and r['slots_in_use'] <= r['limit'] for r in rounds),
         'exactly_limit': all(r['accepts'] == r['limit'] for r in rounds), 'slots_equal_accepts': all(r['slots_in_use'] == r['accepts'] for r in rounds),
         'all_ended_by_stop': all(r['in_use_after_stops'] == 0 for r in rounds)}
    v['pass'] = v['never_above_limit'] and v['slots_equal_accepts'] and v['all_ended_by_stop']
    record('H2B.IMPL.synthetic_burst', {'verdict': v})
    return v


@case
def impl_vpn_untouched():
    """implemented site, synthetic NAS 10.88.0.10: a VPN-shaped Access-Request (Framed-User, PPP, NAS-Port-Type Virtual)
    and a Hotspot-shaped one for the same subscriber, with an open radacct row from a stale Start (no Class): the VPN
    request still meets the STOCK Simultaneous-Use check (unchanged) and gets no slot / no nuxh1 Class; the Hotspot request
    is decided by the slots (stock check bypassed) and gets the nuxh1 Class"""
    assert MODE[0] in ('project', 'hold'), 'needs: site project'
    u, pw = h2.subscriber('vu', 1)
    try:
        g.exchange(g.accounting_request([('User-Name', u), ('NAS-IP-Address', '10.88.0.10'), ('Acct-Status-Type', 1), ('Acct-Session-Id', '8vpn0001'),
                                         ('Calling-Station-Id', '02:AB:CD:00:09:01')]), port=1813)
        vpn = g.exchange(g.access_request([('User-Name', u), ('NAS-IP-Address', '10.88.0.10'), ('Service-Type', 2), ('Framed-Protocol', 1), ('NAS-Port-Type', 5),
                                           ('Acct-Session-Id', '8vpn0002'), ('Calling-Station-Id', '02:AB:CD:00:09:02')], pw, chap=True))
        slots_after_vpn = slots(u)
        hs = g.exchange(g.access_request(_syn_attrs(u, '8vpn0003', '02:AB:CD:00:09:03'), pw, chap=True))
        cls_hs = g.reply_class(hs)
        v = {'open_radacct_rows': len(h2.open_rows(u)), 'vpn_reply': vpn['reply'] and vpn['reply']['code'],
             'vpn_reply_message': next((x[1] for x in (vpn['reply'] or {}).get('attrs', []) if x[0] == 'Reply-Message'), None),
             'vpn_class': g.reply_class(vpn), 'slots_after_vpn': len(slots_after_vpn),
             'hotspot_reply': hs['reply'] and hs['reply']['code'], 'hotspot_class_prefix': (cls_hs or '')[:10], 'slots_after_hotspot': [x['state'] for x in slots(u)]}
        if cls_hs:
            _acct(u, 'Stop', '8vpn0003', '02:AB:CD:00:09:03', cls_hs)
    finally:
        sql(f"UPDATE radacct SET acctstoptime = NOW() WHERE username = CONVERT(X'{hexs(u)}' USING utf8mb4) AND acctstoptime IS NULL")
        _cleanup_user(u)
    v['pass'] = (v['vpn_reply'] == 'Access-Reject' and v['vpn_class'] is None and v['slots_after_vpn'] == 0 and v['hotspot_reply'] == 'Access-Accept'
                 and v['hotspot_class_prefix'] == '6e75786831' and v['slots_after_hotspot'] == ['PENDING'])
    record('H2B.IMPL.vpn_untouched', {'verdict': v})
    return v


@case
def impl_decrease_concurrent():
    """implemented site, synthetic NAS 10.88.0.10 (review M1): limit 3 with slots 1-2 ENDED and slot 3 ACTIVE; the limit
    is lowered to 2 and 16 Hotspot logins arrive at the same instant -> never more than 2 live slots (the pre-check
    refuses while a slot above the new limit is live); after slot 3's Stop one login is admitted. 5 rounds."""
    assert MODE[0] in ('project', 'hold'), 'needs: site project'
    rounds = []
    for rnd in range(5):
        u, pw = h2.subscriber('dc', 3)
        try:
            ids = [(f'8d{rnd}{i:05d}', '02:5D:%02X:%02X:00:%02X' % (rnd, i, 9)) for i in range(3)]
            reps = [g.exchange(g.access_request(_syn_attrs(u, a, m), pw, chap=True)) for a, m in ids]
            for (a, m), r in zip(ids, reps):
                _acct(u, 'Start', a, m, g.reply_class(r))
            _acct(u, 'Stop', ids[0][0], ids[0][1], g.reply_class(reps[0]))
            _acct(u, 'Stop', ids[1][0], ids[1][1], g.reply_class(reps[1]))
            before = [x['state'] for x in slots(u)]
            _set_limit(u, 2)
            n = 16
            new = [(f'8e{rnd}{i:05d}', '02:5E:%02X:%02X:00:%02X' % (rnd, i, 9)) for i in range(n)]
            pk = [g.access_request(_syn_attrs(u, a, m), pw, chap=True) for a, m in new]
            socks = [g.ns_socket() for _ in range(n)]
            res = [None] * n
            bar = threading.Barrier(n)
            def go(j):
                bar.wait()
                res[j] = g.exchange(pk[j], sock=socks[j], timeout=10)
            ts = [threading.Thread(target=go, args=(j,)) for j in range(n)]
            [t.start() for t in ts]
            [t.join(30) for t in ts]
            [x.close() for x in socks]
            acc = sum(1 for r in res if r and r['reply'] and r['reply']['code'] == 'Access-Accept')
            live = len(in_use(u))
            _acct(u, 'Stop', ids[2][0], ids[2][1], g.reply_class(reps[2]))
            after = g.exchange(g.access_request(_syn_attrs(u, f'8f{rnd}00001', '02:5F:00:00:00:%02X' % rnd), pw, chap=True))
            rounds.append({'round': rnd, 'before': before, 'accepts_during_decrease': acc, 'live_slots': live,
                           'after_slot3_stop': after['reply'] and after['reply']['code']})
        finally:
            _cleanup_user(u)
    v = {'rounds': rounds, 'never_above_new_limit': all(r['live_slots'] <= 2 for r in rounds)}
    v['pass'] = v['never_above_new_limit'] and all(r['before'] == ['ENDED', 'ENDED', 'ACTIVE'] and r['after_slot3_stop'] == 'Access-Accept' for r in rounds)
    record('H2B.IMPL.decrease_concurrent', {'verdict': v})
    return v


def run(names):
    for c in names:
        try:
            v = CASES[c]()
            print(json.dumps({c: v}, default=str, ensure_ascii=False)[:3000], flush=True)
        except Exception as ex:
            record(f'ERROR.{c}', {'summary': repr(ex)[:500]})
            print(f'[h2b] {c} ERROR {ex!r}', flush=True)
        finally:
            try:
                h2.fresh([])
            except Exception:
                pass


if __name__ == '__main__':
    a = sys.argv[1:] or ['help']
    if a[0] == 'run':
        MODE[0] = site_mode()['site']
        run(a[1:])
    elif a[0] == 'site':
        print(json.dumps(site(a[1]), indent=1))
    elif a[0] == 'schema':
        print(json.dumps(schema()))
    else:
        print(__doc__)
        sys.exit(2)
