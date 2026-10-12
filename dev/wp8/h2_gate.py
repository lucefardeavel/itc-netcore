#!/usr/bin/env python3
"""WP8 H-2 CROSS-NAS HOTSPOT FEASIBILITY GATE - lab tooling only (owner authorization 2026-10-11 "H-2 LAB FEASIBILITY
ONLY"; owner criterion: shared_users is the TOTAL number of concurrent sessions of one subscriber across ALL NAS of the
ISP). Never touches install/, system/, production or AVAIA; changes no FreeRADIUS policy. Acts on the running lab
(dev/wp8/lab.sh up + up-chr2, a2r_gate.py chr2 setup): CHR1 Hotspot clients c2 / c4 / c5 (10.88.2.0/24) and a Hotspot
on CHR2 that this tool adds next to its PPPoE server (ether3, 10.88.12.1/24) with two client namespaces wp8c7 / wp8c8
on the CHR2 segment bridge wp8br3 (explicit MACs). The project site and the production NAS contract (option_g_gate.py
on shipped) are used unchanged; NAS-side variants are selected with the existing switches.

  h2_gate.py setup            CHR2 Hotspot (same profile as CHR1: http-chap, RADIUS, accounting, interim 1m) + clients
  h2_gate.py state            NAS rows, RADIUS clients, Hotspot servers, client MACs, live sessions, open radacct rows
  h2_gate.py run CASE...      run cases (see CASES); evidence -> $NUXR_WP8_STATE/evidence/h2.jsonl
  h2_gate.py teardown         remove the CHR2 client namespaces (lab.sh down removes everything else)

Secrets come from $NUXR_WP8_STATE/secrets.env and are never printed; test passwords are random and never recorded."""
import json, os, re, secrets, subprocess, sys, threading, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import a2r_gate as g
import h1_gate as h
from a2r_gate import cg, ag, sql, sqlrows, hexs, until, FR

STATE = g.STATE
EVID = f'{STATE}/evidence/h2.jsonl'
GW2 = '10.88.12.1'
C2 = {'c7': ('wp8c7', 'wp8v7h', 'wp8v7c', '02:48:32:00:00:07'), 'c8': ('wp8c8', 'wp8v8h', 'wp8v8c', '02:48:32:00:00:08')}
C1 = ('c2', 'c4', 'c5')
NAS1, NAS2 = '10.88.0.2', '10.88.0.3'
MODE = ['?']


# ------------------------------------------------------------------------------------------------ CHR2 helpers
def r2(words):
    r = g.ros2()
    try:
        return r.rows(words)
    finally:
        r.close()


def sh(*cmd, check=False):
    return ag.sh(list(cmd), check=check)


def setup():
    for c, (ns, vh, vc, mac) in C2.items():
        if ns not in sh('ip', 'netns', 'list').stdout:
            sh('ip', 'netns', 'add', ns, check=True)
            sh('ip', 'link', 'add', vh, 'type', 'veth', 'peer', 'name', vc, check=True)
            sh('ip', 'link', 'set', vc, 'netns', ns, check=True)
            sh('ip', 'link', 'set', vh, 'master', 'wp8br3', 'up', check=True)
            ag.nsx(ns, 'ip', 'link', 'set', vc, 'address', mac)
            ag.nsx(ns, 'ip', 'link', 'set', 'lo', 'up')
            ag.nsx(ns, 'ip', 'link', 'set', vc, 'up')
    have = lambda path, key, val: [x for x in r2([path + '/print']) if x.get(key) == val]
    if not have('/ip/address', 'address', GW2 + '/24'):
        r2(['/ip/address/add', f'=address={GW2}/24', '=interface=ether3', '=comment=nuxr_wp8_h2'])
    if not have('/ip/pool', 'name', 'nuxr_wp8_pool_hs'):
        r2(['/ip/pool/add', '=name=nuxr_wp8_pool_hs', '=ranges=10.88.12.100-10.88.12.199'])
    if not have('/ip/dhcp-server/network', 'address', '10.88.12.0/24'):
        r2(['/ip/dhcp-server/network/add', '=address=10.88.12.0/24', f'=gateway={GW2}', '=comment=nuxr_wp8_h2'])
    if not have('/ip/dhcp-server', 'name', 'nuxr_wp8_dhcp'):
        r2(['/ip/dhcp-server/add', '=name=nuxr_wp8_dhcp', '=interface=ether3', '=address-pool=nuxr_wp8_pool_hs', '=lease-time=10m', '=disabled=no'])
    if not have('/ip/hotspot/profile', 'name', 'nuxr_wp8_hs_prof'):
        # the same profile as CHR1 (dev/wp8/configure_chr.py): http-chap only, RADIUS, accounting, interim 1 m
        r2(['/ip/hotspot/profile/add', '=name=nuxr_wp8_hs_prof', f'=hotspot-address={GW2}', '=login-by=http-chap',
            '=use-radius=yes', '=radius-accounting=yes', '=radius-interim-update=1m'])
    if not have('/ip/hotspot', 'name', 'nuxr_wp8_hs'):
        r2(['/ip/hotspot/add', '=name=nuxr_wp8_hs', '=interface=ether3', '=address-pool=none', '=profile=nuxr_wp8_hs_prof', '=disabled=no'])
    r2(['/ip/hotspot/user/profile/set', '=numbers=default', '=shared-users=10'])
    rad = r2(['/radius/print'])
    assert len(rad) == 1, rad
    r2(['/radius/set', '=.id=' + rad[0]['.id'], '=service=ppp,hotspot'])
    return state()


def teardown():
    for c, (ns, vh, vc, mac) in C2.items():
        sh('ip', 'netns', 'del', ns)
        sh('ip', 'link', 'del', vh)
    return {'netns_left': [n for n in sh('ip', 'netns', 'list').stdout.split() if n in ('wp8c7', 'wp8c8')]}


# ------------------------------------------------------------------------------------------------ Hotspot clients
def mac_of(client):
    ns, nic = (C2[client][0], C2[client][2]) if client in C2 else ag.CLIENTS[client][:2]
    out = ag.nsx(ns, 'cat', f'/sys/class/net/{nic}/address').stdout.strip()
    return out.upper()


def dhcp(client):
    if client not in C2:
        return ag.hs_dhcp(client)
    ns, _, nic, _ = C2[client]
    ag.nsx(ns, 'ip', 'addr', 'flush', 'dev', nic)
    p = ag.nsx(ns, 'busybox', 'udhcpc', '-i', nic, '-n', '-q', '-f', '-t', '5', '-s', '/bin/true', timeout=40)
    m = re.search(r'lease of (\S+) obtained', p.stdout + p.stderr)
    if not m:
        return {'ok': False, 'out': (p.stdout + p.stderr)[-300:]}
    ag.nsx(ns, 'ip', 'addr', 'add', m.group(1) + '/24', 'dev', nic)
    ag.nsx(ns, 'ip', 'route', 'replace', 'default', 'via', GW2)
    return {'ok': True, 'ip': m.group(1)}


def login(client, user, pw):
    if client not in C2:
        return {**ag.hs_login(client, user, pw), 'nas': 'CHR1'}
    import hashlib
    ns = C2[client][0]
    page, _ = ag._curl(ns, f'http://{GW2}/login')
    m = re.search(r"hexMD5\('((?:\\\d{3})+)' \+ document\.login\.password\.value \+ '((?:\\\d{3})+)'\)", page)
    if not m:
        return {'ok': False, 'state': 'NO_CHAP_FORM', 'nas': 'CHR2', 'page': re.sub(r'<[^>]+>', ' ', page)[:200]}
    resp = hashlib.md5(ag._octal(m.group(1)) + pw.encode() + ag._octal(m.group(2))).hexdigest()
    body, _ = ag._curl(ns, '--data-urlencode', f'username={user}', '--data-urlencode', f'password={resp}',
                       '--data-urlencode', 'dst=', '--data-urlencode', 'popup=false', f'http://{GW2}/login')
    text = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body))
    err = re.search(r'(RADIUS server is not responding|invalid username or password|[^.!]{0,40}(?:denied|reject|limit|expired|not allowed)[^.!]{0,60})', text, re.I)
    ok = err is None and re.search(r'You are logged in', text) is not None
    return {'ok': ok, 'state': 'LOGGED_IN' if ok else 'REFUSED', 'message': (err.group(1) if err else text[:160]).strip(), 'nas': 'CHR2'}


def logout(client):
    if client in C2:
        ag._curl(C2[client][0], '-o', '/dev/null', f'http://{GW2}/logout')
    else:
        ag.hs_logout(client)


def active(nas):
    rows = h.ros_rows(['/ip/hotspot/active/print']) if nas == 1 else r2(['/ip/hotspot/active/print'])
    return [{k: x.get(k) for k in ('user', 'address', 'mac-address', 'login-by')} for x in rows]


def clear_nas():
    for nas in (1, 2):
        for path in ('/ip/hotspot/active', '/ip/hotspot/host'):
            rows = h.ros_rows([path + '/print']) if nas == 1 else r2([path + '/print'])
            for x in rows:
                try:
                    (h.ros_do if nas == 1 else (lambda *w: r2(list(w))))(path + '/remove', '=.id=' + x['.id'])
                except Exception:
                    pass


def fresh(clients):
    for c in list(C1) + list(C2):
        logout(c)
    clear_nas()
    time.sleep(1)
    return {c: dhcp(c) for c in clients}


def live(user):
    a1 = [x for x in active(1) if x['user'] == user]
    a2 = [x for x in active(2) if x['user'] == user]
    return {'chr1': len(a1), 'chr2': len(a2), 'total': len(a1) + len(a2), 'macs': sorted(x['mac-address'] for x in a1 + a2)}


def sample(user, seconds, step=0.5):
    mx = {'chr1': 0, 'chr2': 0, 'total': 0}
    seen = []
    t = time.time()
    while True:
        l = live(user)
        for k in mx:
            mx[k] = max(mx[k], l[k])
        seen.append((round(time.time() - t, 1), l['chr1'], l['chr2']))
        if time.time() - t >= seconds:
            return {'max': mx, 'samples': seen[:: max(1, len(seen) // 12)], 'final': l}
        time.sleep(step)


def radacct(user):
    return [{'nas': r[0], 'acctsessionid': r[1], 'start': r[2], 'stop': r[3], 'callingstationid': r[4]}
            for r in sqlrows(f"SELECT nasipaddress, acctsessionid, acctstarttime, IFNULL(acctstoptime,'-'), callingstationid FROM radacct "
                             f"WHERE username = CONVERT(X'{hexs(user)}' USING utf8mb4) ORDER BY radacctid")]


def open_rows(user):
    return [r for r in radacct(user) if r['stop'] == '-']


def fr_decisions(since_rfc, user):
    """FreeRADIUS debug lines of the subscriber's Access-Requests: the session (Simultaneous-Use) step, checkrad, the
    reply; plus the checkrad trace wrapper lines (gate modes)"""
    lg = cg.sh(['docker', 'logs', '--since', since_rfc, FR], check=False)
    lines = (lg.stdout + lg.stderr).splitlines()
    reqs, nas, out = set(), {}, {}
    for l in lines:
        m = re.match(r'^\((\d+)\)\s+(.*)$', l)
        if not m:
            continue
        n, body = m.groups()
        if body.startswith(f'User-Name = "{user}"'):
            reqs.add(n)
        mm = re.match(r'Received Access-Request Id \d+ from ([\d.]+):', body)
        if mm:
            nas[n] = mm.group(1)
    keep = re.compile(r'Multiple logins|checkrad|Check-TS|Simultaneous|session \{|\} # session|Failed to check|Sent Access-|nuxr-hotspot=|Reply-Message|rlm_sql.*(simul|zap)|STALE|stale')
    for l in lines:
        m = re.match(r'^\((\d+)\)\s+(.*)$', l)
        if m and m.group(1) in reqs and keep.search(m.group(2)) and 'EXPAND' not in m.group(2):
            out.setdefault(m.group(1), {'nas': nas.get(m.group(1)), 'lines': []})['lines'].append(ag._redact(m.group(2).strip())[:200])
    trace = [ag._redact(l)[:220] for l in lines if '[wp8-checkrad]' in l and f'user={user}' in l]
    return {'requests': [{'request': k, **v} for k, v in sorted(out.items(), key=lambda kv: int(kv[0]))], 'checkrad_trace': trace}


def record(case, data):
    os.makedirs(os.path.dirname(EVID), mode=0o700, exist_ok=True)
    line = ag._redact(json.dumps({'t': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'case': case, 'nas_mode': MODE[0], **data},
                                 default=str, ensure_ascii=False))
    with open(EVID, 'a') as f:
        f.write(line + '\n')
    print(f'[h2] {MODE[0]} {case}: ' + json.dumps(data.get('verdict', ''), default=str, ensure_ascii=False)[:600], flush=True)


# ------------------------------------------------------------------------------------------------ state
def state():
    nas = sqlrows('SELECT id, nasname, shortname, type, ports, IF(community IS NULL OR community = "", 0, 1) FROM nas ORDER BY id')
    fl = cg.sh(['docker', 'logs', FR], check=False)
    clients = sorted(set(re.findall(r'Client "(nuxr_wp8[^"]*)"', fl.stdout + fl.stderr)))
    r1 = h.ros_rows(['/radius/print'])[0]
    rr2 = r2(['/radius/print'])[0]
    hs = lambda rows: [{k: x.get(k) for k in ('name', 'interface', 'profile', 'disabled')} for x in rows]
    prof = lambda rows: [{k: x.get(k) for k in ('name', 'login-by', 'use-radius', 'radius-accounting', 'radius-interim-update', 'hotspot-address')} for x in rows if x.get('name') == 'nuxr_wp8_hs_prof']
    macs = {c: mac_of(c) for c in list(C1) + list(C2)}
    nic = lambda rows: sorted(x.get('mac-address') for x in rows)
    st = {
        'nas_rows': [{'id': r[0], 'nasname': r[1], 'shortname': r[2], 'type': r[3], 'ports': r[4], 'community_set': r[5] == '1'} for r in nas],
        'freeradius_clients_loaded': clients,
        'radius_chr1': {k: r1.get(k) for k in ('address', 'service', 'src-address', 'timeout')},
        'radius_chr2': {k: rr2.get(k) for k in ('address', 'service', 'src-address', 'timeout')},
        'hotspot_chr1': hs(h.ros_rows(['/ip/hotspot/print'])), 'hotspot_chr2': hs(r2(['/ip/hotspot/print'])),
        'profile_chr1': prof(h.ros_rows(['/ip/hotspot/profile/print'])), 'profile_chr2': prof(r2(['/ip/hotspot/profile/print'])),
        'client_macs': macs, 'client_macs_unique': len(set(macs.values())) == len(macs),
        'chr_nic_macs_disjoint': not set(nic(h.ros_rows(['/interface/ethernet/print']))) & set(nic(r2(['/interface/ethernet/print']))),
        'hotspot_active': {'chr1': len(active(1)), 'chr2': len(active(2))},
        'open_radacct_rows_h2': sqlrows("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL AND username LIKE 'nuxr_h2_%'")[0][0],
        'nas_mode': MODE[0], 'site': h.state()['site_mode'],
    }
    return st


# ------------------------------------------------------------------------------------------------ trials
def subscriber(tag, shared):
    u = f'nuxr_h2_{tag}_{secrets.token_hex(3)}'
    pw = 'H2-' + secrets.token_hex(6)
    h.provision(u, pw, shared)
    return u, pw


def wait_open(user, nas_ip, n, seconds=15):
    return until(lambda: len([r for r in open_rows(user) if r['nas'] == nas_ip]) >= n or None, seconds, 0.5) is not None


def classify(limit, attempts, mx_total):
    if mx_total > limit:
        return 'EXCEEDED'
    admitted = [a for a in attempts if a['ok']]
    if len(admitted) < min(limit, len(attempts)):
        return 'AVAILABILITY_FAILURE'
    if mx_total <= limit and len(admitted) <= limit:
        return 'ENFORCED'
    return 'INCONCLUSIVE'


def trial(label, user, pw, limit, plan, concurrent=False, wait_start=True, observe=10, between=None):
    """plan: list of clients (c2/c4/c5 on CHR1, c7/c8 on CHR2) in login order; concurrent = all launched together"""
    dh = fresh(plan)
    t_rfc = g.since()
    t0 = time.time()
    attempts = []
    if concurrent:
        res, bar = {}, threading.Barrier(len(plan))
        def go(c):
            bar.wait()
            res[c] = {'client': c, 't_ms': round((time.time() - t0) * 1000), **login(c, user, pw)}
        th = [threading.Thread(target=go, args=(c,)) for c in plan]
        [t.start() for t in th]
        [t.join(60) for t in th]
        attempts = [res.get(c, {'client': c, 'ok': False, 'state': 'NO_RESULT'}) for c in plan]
    else:
        for i, c in enumerate(plan):
            if between and i in between:
                between[i]()
            a = {'client': c, 't_ms': round((time.time() - t0) * 1000), **login(c, user, pw)}
            if a['ok'] and wait_start:
                a['accounting_start_seen'] = wait_open(user, NAS1 if c in C1 else NAS2, len([x for x in attempts if x['ok'] and (x['client'] in C1) == (c in C1)]) + 1)
            attempts.append(a)
    smp = sample(user, observe)
    rows = radacct(user)
    dec = fr_decisions(t_rfc, user)
    for c in plan:
        logout(c)
    until(lambda: live(user)['total'] == 0 or None, 25, 1)
    v = {'limit': limit, 'plan': plan, 'concurrent': concurrent, 'dhcp_ok': all(x.get('ok') for x in dh.values()),
         'attempts': [{k: a.get(k) for k in ('client', 'nas', 't_ms', 'ok', 'state', 'message', 'accounting_start_seen')} for a in attempts],
         'live_max': smp['max'], 'live_samples': smp['samples'], 'radacct': rows, 'classification': classify(limit, attempts, smp['max']['total'])}
    return {'label': label, **v, 'freeradius': dec}


CASES = {}


def case(fn):
    CASES[fn.__name__] = fn
    return fn


def run_trials(name, limit, specs):
    """specs: list of (label, plan, kwargs); one fresh subscriber per case"""
    u, pw = subscriber(name, limit)
    out = []
    try:
        for label, plan, kw in specs:
            out.append(trial(label, u, pw, limit, plan, **kw))
    finally:
        h.unprovision(u)
    summary = {t['label']: {'class': t['classification'], 'live_max': t['live_max'], 'logins': [(a['client'], a['nas'], a['state']) for a in t['attempts']]} for t in out}
    v = {'user_tag': name, 'limit': limit, 'trials': summary, 'exceeded': sum(1 for t in out if t['classification'] == 'EXCEEDED'),
         'n': len(out)}
    record(f'H2.{name}', {'verdict': v, 'detail': out})
    return v


@case
def precheck():
    st = state()
    v = {'two_nas_rows_production_type': [r['type'] for r in st['nas_rows'] if r['nasname'] in (NAS1, NAS2)],
         'both_nas_clients_loaded': all(x in st['freeradius_clients_loaded'] for x in ('nuxr_wp8_chr', 'nuxr_wp8_chr2')),
         'both_radius_service_hotspot': 'hotspot' in (st['radius_chr1']['service'] or '') and 'hotspot' in (st['radius_chr2']['service'] or ''),
         'same_profile': [{k: v_ for k, v_ in p.items() if k != 'hotspot-address'} for p in st['profile_chr1']] == [{k: v_ for k, v_ in p.items() if k != 'hotspot-address'} for p in st['profile_chr2']],
         'client_macs_unique': st['client_macs_unique'], 'chr_nic_macs_disjoint': st['chr_nic_macs_disjoint'],
         'no_live_sessions': st['hotspot_active'] == {'chr1': 0, 'chr2': 0}, 'no_open_rows': st['open_radacct_rows_h2'] == '0',
         'site': st['site']}
    v['pass'] = all(x is True or x == 'project' for k, x in v.items() if k not in ('two_nas_rows_production_type',)) and len(v['two_nas_rows_production_type']) == 2
    record('H2.precheck', {'verdict': v, 'state': st})
    return v


@case
def control_same_nas():
    """control: the same NAS (RouterOS Port-Limit) - limit 1, CHR1 c2 then c4; CHR2 c7 then c8"""
    return run_trials('ctl', 1, [('chr1_c2_c4', ['c2', 'c4'], {}), ('chr2_c7_c8', ['c7', 'c8'], {})])


@case
def su1_sequential():
    """shared_users = 1: CHR1 first, Accounting-Start confirmed in radacct, then CHR2 (3 trials)"""
    return run_trials('su1seq', 1, [(f'chr1_then_chr2_{i}', ['c2', 'c7'], {}) for i in range(3)])


@case
def su1_reverse():
    """shared_users = 1: CHR2 first, then CHR1 (3 trials)"""
    return run_trials('su1rev', 1, [(f'chr2_then_chr1_{i}', ['c7', 'c2'], {}) for i in range(3)])


@case
def su1_concurrent():
    """shared_users = 1: CHR1 and CHR2 launched together (10 trials)"""
    return run_trials('su1conc', 1, [(f'together_{i}', ['c2', 'c7'], {'concurrent': True}) for i in range(10)])


@case
def su2_split():
    """shared_users = 2: 1 + 1 across the NAS, then an extra on each side; 2 on CHR1 then 1 / 2 on CHR2; concurrent 2+2"""
    return run_trials('su2', 2, [('chr1_chr2_then_chr1', ['c2', 'c7', 'c4'], {}),
                                 ('chr1_chr2_then_chr2', ['c2', 'c7', 'c8'], {}),
                                 ('chr1x2_then_chr2', ['c2', 'c4', 'c7'], {}),
                                 ('chr1x2_then_chr2x2', ['c2', 'c4', 'c7', 'c8'], {}),
                                 *[(f'together_2plus2_{i}', ['c2', 'c4', 'c7', 'c8'], {'concurrent': True}) for i in range(5)]])


@case
def lost_start():
    """shared_users = 1: CHR1 accounting dropped (no Start), then a CHR2 login; then the reverse (CHR2 accounting
    dropped) - the cross-NAS counter has nothing to count"""
    out = {}
    rid = g.block_acct()
    try:
        out['chr1_start_lost'] = run_trials('lost1', 1, [('chr1_nostart_then_chr2', ['c2', 'c7'], {'wait_start': False, 'observe': 8})])
    finally:
        g.unblock(rid)
    rr = r2(['/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_h2'])
    rid2 = [x for x in r2(['/ip/firewall/filter/print']) if x.get('comment') == 'nuxr_wp8_h2'][0]['.id']
    try:
        out['chr2_start_lost'] = run_trials('lost2', 1, [('chr2_nostart_then_chr1', ['c7', 'c2'], {'wait_start': False, 'observe': 8})])
    finally:
        r2(['/ip/firewall/filter/remove', '=.id=' + rid2])
    return out


@case
def late_accounting():
    """shared_users = 1: CHR1 accounting held back 20 s (RouterOS retries the Start), a CHR2 login inside that window;
    then accounting resumes and both sessions are counted"""
    u, pw = subscriber('late', 1)
    try:
        dh = fresh(['c2', 'c7'])
        t_rfc = g.since()
        rid = g.block_acct()
        a1 = login('c2', u, pw)
        time.sleep(3)
        rows_during = radacct(u)
        a2 = login('c7', u, pw)
        smp1 = sample(u, 6)
        g.unblock(rid)
        started = wait_open(u, NAS1, 1, 40)
        smp2 = sample(u, 6)
        rows_after = radacct(u)
        a3 = login('c8', u, pw)          # a third attempt after both sessions are in radacct
        smp3 = sample(u, 6)
        dec = fr_decisions(t_rfc, u)
        for c in ('c2', 'c7', 'c8'):
            logout(c)
        until(lambda: live(u)['total'] == 0 or None, 25, 1)
    finally:
        h.unprovision(u)
    v = {'chr1_login': a1['state'], 'radacct_while_held': rows_during, 'chr2_login_in_window': a2['state'], 'live_in_window': smp1['max'],
         'chr1_start_arrived_after_release': started, 'live_after': smp2['max'], 'radacct_after': rows_after,
         'third_login_chr2': a3['state'], 'live_after_third': smp3['max'], 'dhcp_ok': all(x.get('ok') for x in dh.values())}
    v['classification'] = 'EXCEEDED' if max(smp1['max']['total'], smp2['max']['total'], smp3['max']['total']) > 1 else 'ENFORCED'
    record('H2.late_accounting', {'verdict': v, 'freeradius': dec})
    return v


@case
def snmp_blocked():
    """shared_users = 1: SNMP from FreeRADIUS to CHR1 dropped on CHR1 (UDP 161 input), CHR1 first, then CHR2 - the
    session verification of the CHR1 row cannot reach the NAS (2 trials)"""
    rid = g.ros_add('/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=action=drop', '=comment=nuxr_wp8_h2')
    try:
        return run_trials('snmpblk', 1, [(f'chr1_then_chr2_snmp_blocked_{i}', ['c2', 'c7'], {}) for i in range(2)])
    finally:
        g.unblock(rid)


@case
def lost_stop():
    """shared_users = 1: a CHR1 session ends but its Accounting-Stop is lost (stale open radacct row); then a CHR2
    login - shows what the cross-NAS check does with a stale row (lock-out vs admission)"""
    u, pw = subscriber('lstop', 1)
    try:
        dh = fresh(['c2', 'c7'])
        t_rfc = g.since()
        a1 = login('c2', u, pw)
        started = wait_open(u, NAS1, 1)
        rid = g.block_acct()
        logout('c2')
        gone = until(lambda: live(u)['chr1'] == 0 or None, 20, 0.5) is not None
        time.sleep(4)
        g.unblock(rid)
        rows_stale = radacct(u)
        a2 = login('c7', u, pw)
        smp = sample(u, 6)
        dec = fr_decisions(t_rfc, u)
        logout('c7')
        until(lambda: live(u)['total'] == 0 or None, 25, 1)
    finally:
        h.unprovision(u)
    v = {'chr1_login': a1['state'], 'start_seen': started, 'chr1_session_gone': gone, 'radacct_stale': rows_stale,
         'chr2_login_after_lost_stop': a2['state'], 'live': smp['max'], 'dhcp_ok': all(x.get('ok') for x in dh.values())}
    v['classification'] = 'AVAILABILITY_FAILURE' if a2['state'] != 'LOGGED_IN' else ('EXCEEDED' if smp['max']['total'] > 1 else 'ADMITTED_STALE_ROW_IGNORED')
    record('H2.lost_stop', {'verdict': v, 'freeradius': dec})
    return v


def run(names):
    MODE[0] = os.environ.get('NUXR_H2_MODE', '?')
    for c in names:
        try:
            v = CASES[c]()
            print(json.dumps({c: v}, default=str, ensure_ascii=False)[:2500], flush=True)
        except Exception as ex:
            record(f'ERROR.{c}', {'summary': repr(ex)[:500]})
            print(f'[h2] {c} ERROR {ex!r}', flush=True)
        finally:
            try:
                fresh([])
            except Exception:
                pass


if __name__ == '__main__':
    a = sys.argv[1:] or ['state']
    if a[0] == 'setup':
        print(json.dumps(setup(), indent=1, default=str))
    elif a[0] == 'teardown':
        print(json.dumps(teardown(), indent=1, default=str))
    elif a[0] == 'state':
        MODE[0] = os.environ.get('NUXR_H2_MODE', '?')
        print(json.dumps(state(), indent=1, default=str))
    elif a[0] == 'run':
        run(a[1:])
    else:
        print(__doc__)
        sys.exit(2)
