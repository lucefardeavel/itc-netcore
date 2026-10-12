#!/usr/bin/env python3
"""WP8 H-1 FEASIBILITY GATE - lab tooling only (owner authorization 2026-10-10 "H1-B FIRST - FEASIBILITY GATE ONLY").
Never touches install/, system/, production or AVAIA. Acts on the running WP8 lab (dev/wp8/lab.sh up): the disposable
RADIUS database, the lab FreeRADIUS container and the lab CHR 7.24.5 with its Hotspot clients c2 / c4 / c5.

  h1_gate.py site MODE          the lab FreeRADIUS site (restart):
                                  project  the PROJECT site (since H1-A: with the Hotspot canonical-spelling check)
                                  pre      the accepted A2-R site 5ea597d2 (no H1-A): the H-1 bypass baseline
                                  h1b      "pre" + ONE experimental Hotspot block (H1-B: reply User-Name := canonical;
                                           request:User-Name never changed) - the H1-B feasibility gate
                                  fault    "project" with lab-only SQL faults in the H1-A block, active only for
                                           synthetic requests with NAS-Identifier nuxr-h1-fault-*
                                  av5 / av5probe  the REJECTED AV-5 proposal ("%C %c" + 1000 ms in the first 20 ms),
                                           kept only to reproduce its evidence
                                  av5hold  "project" + lab-only instrumentation for synthetic requests with
                                           NAS-Identifier nuxr-av5-*: a SQL hold of N ms before age block A (pre-claim)
                                           or B (post-claim) for nuxr-av5-hold-N-A|B, and after each age block a
                                           logged line with the computed age, a fresh %{time_since:us}, the coarse
                                           %c read right after it (lag = %c below the gettimeofday second) and %l%M
                                  av5hold_pre  "av5hold" with the PRE-FIX age formula (%C / %c), for contrast
  h1_gate.py state
  h1_gate.py run CASE...        run cases; every case runs in the CURRENT site mode and records it
                                evidence -> $NUXR_WP8_STATE/evidence/h1.jsonl

The question H1-B must answer on the real NAS (three independent sources): did FreeRADIUS send the canonical name
(pcap), which name does RouterOS give the Hotspot session (/ip hotspot active), which name does the accounting carry
(Start / Interim / Stop, pcap + radacct) - and does RouterOS then enforce Port-Limit on ONE identity."""
import hashlib, json, os, re, secrets, socket, struct, subprocess, sys, threading, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import a2r_gate as g
from a2r_gate import cg, ag, sql, sqlrows, hexs, until, FR, R, CHR

STATE = g.STATE
EVID = f'{STATE}/evidence/h1.jsonl'
SITE = g.SITE
H1B = '''	# LAB ONLY - WP8 H-1 FEASIBILITY (dev/wp8/h1_gate.py), NOT the project policy. H1-B: the Hotspot Access-Accept
	# carries User-Name = the canonical radcheck.username (the exact row the lookup matched); request:User-Name is
	# NOT changed. Anything else than one well-formed canonical name: reject.
	if ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {
		# as in the A2-R PPPoE block: a first trivial %{sql:} call makes rlm_sql create the stock SQL-User-Name
		update request {
			&Tmp-String-5 := "%{sql:SELECT '1'}"
		}
		if (!&SQL-User-Name || (&Tmp-String-5 != "1")) {
			attr_filter.access_reject
			update reply {
				&Reply-Message := "Login failed"
			}
			reject
		}
		update request {
			&Tmp-String-3 := "%{sql:SELECT CONCAT('u=', COALESCE((SELECT username FROM radcheck WHERE username = '%{SQL-User-Name}' ORDER BY CAST(username AS BINARY) LIMIT 1), '-'))}"
		}
		if (&Tmp-String-3 =~ /^u=([0-9A-Za-z][0-9A-Za-z:+_.@-]{0,63})$/) {
			update reply {
				&User-Name := "%{1}"
			}
			update request {
				&Tmp-String-4 := "nuxr-h1b=canonical"
			}
		}
		else {
			update request {
				&Tmp-String-4 := "nuxr-h1b=reject"
			}
			attr_filter.access_reject
			update reply {
				&Reply-Message := "Login failed"
			}
			reject
		}
	}
'''
HS = ('c2', 'c4', 'c5')


# ------------------------------------------------------------------------------------------------ site mode
PRE_REF = '5ea597d2b74d0f0b89c5bc7ef1f6eef172c3ced2'
# WP8 AV-5 (lab only, proposed - NOT the project policy): ONE expansion reads %C (gettimeofday microseconds) BEFORE %c
# (time(NULL), a coarse clock that can still show the previous second for up to one tick after a second boundary); both
# are parsed; when %C is in the first 20 ms of a second the seconds may lag by one -> +1000 ms. The age is then never
# lower than the true age (no under-estimate; at most +1000 ms in that window) and never negative from the lag.
# probe cases: case -> (%C microseconds, %M microseconds, %c - %l seconds); 'x' = a malformed %C
AV5_PROBES = {'lag': (400, 995000, 0), 'window_nolag': (400, 995000, 1), 'normal': (500000, 995000, 1), 'late': (500000, 100000, 5),
              'window_edge_in': (19999, 0, 4), 'window_edge_out': (20000, 0, 4), 'malformed': ('x', 0, 1)}
AV5_AGE = ("""\t\tupdate request {\n\t\t\t&Tmp-String-6 := "%C %c"\n\t\t}\n"""
           """\t\tif (&Tmp-String-6 =~ /^([0-9]{1,6}) ([0-9]{9,11})$/) {\n"""
           """\t\t\tupdate request {\n\t\t\t\t&Tmp-String-6 := "%{expr:(%{1} - %M) / 1000 + (%{2} - %l) * 1000 + (999999 - %{1}) / 980000 * 1000}"\n\t\t\t}\n\t\t}\n""")
HS_DISC = 'if ((&Service-Type == Login-User) && (&NAS-Port-Type == Wireless-802.11)) {'
# WP8 AV-5 fix (owner authorization 2026-10-10 "H1-A + AV-5 Final Closure"): the age on ONE clock
AGE_NEW = """\t\tupdate request {\n\t\t\t&Tmp-String-6 := "%{expr:(%{time_since:us} - %l * 1000000 - %M) / 1000}"\n\t\t}\n"""
AGE_PRE = """\t\tupdate request {\n\t\t\t&Tmp-String-6 := "%{expr:(%C - %M) / 1000 + (%c - %l) * 1000}"\n\t\t}\n"""
MODES = ('project', 'pre', 'h1b', 'fault', 'av5', 'av5probe', 'av5hold', 'av5hold_pre')


def av5hold_text(s, formula):
    """lab only: before age block A / B a SQL hold of N ms (NAS-Identifier nuxr-av5-hold-N-A / -B); after each age block
    the computed age with a fresh %{time_since:us} and the receive time %l%M in a logged control attribute (nuxr-av5-*)"""
    assert s.count(AGE_NEW) == 2
    parts = s.split(AGE_NEW)
    out = parts[0]
    for i, blk in enumerate(('A', 'B')):
        out += (f'\t\t# LAB AV-5 (h1_gate.py): hold N ms before age block {blk}\n'
                f'\t\tif (&NAS-Identifier =~ /^nuxr-av5-hold-([0-9]{{1,5}})-{blk}$/) {{\n'
                f'\t\t\tupdate control {{\n\t\t\t\t&Tmp-String-4 := "%{{sql:SELECT SLEEP(%{{1}} / 1000)}}"\n\t\t\t}}\n\t\t}}\n')
        out += ('\t\tif (&NAS-Identifier =~ /^nuxr-av5-/) {\n\t\t\tupdate control {\n'
                '\t\t\t\t&Tmp-String-6 := "%{time_since:us}"\n\t\t\t}\n\t\t}\n')
        out += formula
        out += (f'\t\tif (&NAS-Identifier =~ /^nuxr-av5-/) {{\n\t\t\tupdate control {{\n'
                f'\t\t\t\t&Tmp-String-8 := "nuxr-av5-age blk={blk} age=%{{Tmp-String-6}} pre_us=%{{control:Tmp-String-6}} now_us=%{{time_since:us}} coarse_s=%c arr_us=%l%M asid=%{{Acct-Session-Id}}"\n'
                f'\t\t\t}}\n\t\t}}\n')
        out += parts[i + 1]
    return out


def site_text(mode):
    if mode in ('pre', 'h1b'):
        s = subprocess.run(['git', '-C', os.path.join(os.path.dirname(SITE), '..', '..', '..'), 'show', f'{PRE_REF}:install/freeradius/sites-available/phpnuxbill'],
                           capture_output=True, text=True, check=True).stdout
        if mode == 'pre':
            return s
        anchor = 'post-auth {\n'
        assert s.count(anchor) == 1
        return s.replace(anchor, anchor + H1B, 1)
    s = open(SITE).read()
    if mode == 'project':
        return s
    if mode in ('av5hold', 'av5hold_pre'):
        return av5hold_text(s, AGE_NEW if mode == 'av5hold' else AGE_PRE)
    if mode in ('av5', 'av5probe'):
        # the rejected proposal was written against the pre-fix formula: restore it first
        assert s.count(AGE_NEW) == 2
        s = s.replace(AGE_NEW, AGE_PRE)
        old = AGE_PRE
        assert s.count(old) == 2
        if mode == 'av5':
            return s.replace(old, AV5_AGE)
        # av5probe: the FIRST (pre-claim) age block evaluates the proposed formula on FIXED clock values for synthetic
        # requests with NAS-Identifier nuxr-av5-probe-<case> (C, M, c-l chosen per case); every other request: av5
        probe = ''
        for k, (c_us, m_us, dsec) in AV5_PROBES.items():
            probe += (f'\t\t{"if" if not probe else "elsif"} (&NAS-Identifier == "nuxr-av5-probe-{k}") {{\n'
                      f'\t\t\tupdate request {{\n\t\t\t\t&Tmp-String-6 := "{c_us} {1791000000 + dsec}"\n\t\t\t}}\n'
                      f'\t\t\tif (&Tmp-String-6 =~ /^([0-9]{{1,6}}) ([0-9]{{9,11}})$/) {{\n\t\t\t\tupdate request {{\n'
                      f'\t\t\t\t\t&Tmp-String-6 := "%{{expr:(%{{1}} - {m_us}) / 1000 + (%{{2}} - 1791000000) * 1000 + (999999 - %{{1}}) / 980000 * 1000}}"\n'
                      f'\t\t\t\t}}\n\t\t\t}}\n\t\t}}\n')
        probe += '\t\telse {\n' + AV5_AGE.replace('\n\t\t', '\n\t\t\t').replace('\t\tupdate', '\t\t\tupdate', 1).replace('\t\tif (&Tmp', '\t\t\tif (&Tmp', 1) + '\t\t}\n'
        i = s.index(old)
        return s[:i] + probe + s[i + len(old):].replace(old, AV5_AGE, 1)
    assert mode == 'fault'
    # the post-auth Hotspot block = the LAST occurrence (WP8 H-2B: session { } carries the same discriminator)
    head, block = s.rsplit(HS_DISC, 1)
    prime = """\t\tupdate request {\n\t\t\t&Tmp-String-5 := "%{sql:SELECT '1'}"\n\t\t}\n"""
    query = """\t\tupdate request {\n\t\t\t&Tmp-String-1 := "%{sql:SELECT CONCAT('c=', COUNT(DISTINCT CAST(username AS BINARY)), ';', COALESCE(LOWER(HEX(MIN(CAST(username AS BINARY)))), '-')) FROM radcheck WHERE username = '%{SQL-User-Name}'}"\n\t\t\t&Tmp-String-2 := "%{hex:&User-Name}"\n\t\t}\n"""
    assert block.count(prime) == 1 and block.count(query) == 1
    fp = ("""\t\t# LAB FAULT (h1_gate.py): a real SQL error in the priming query\n\t\tif (&NAS-Identifier == "nuxr-h1-fault-prime") {\n"""
          """\t\t\tupdate request {\n\t\t\t\t&Tmp-String-5 := "%{sql:SELECT nuxr_no_such_column FROM radcheck LIMIT 1}"\n\t\t\t}\n\t\t}\n\t\telse {\n"""
          + prime.replace('\n\t\t', '\n\t\t\t').replace('\t\tupdate', '\t\t\tupdate', 1) + '\t\t}\n')
    fq = ("""\t\t# LAB FAULT (h1_gate.py): a real SQL error / an unexpected result / two stored spellings in the identity query\n"""
          """\t\tif (&NAS-Identifier == "nuxr-h1-fault-query") {\n\t\t\tupdate request {\n\t\t\t\t&Tmp-String-1 := "%{sql:SELECT nuxr_no_such_column FROM radcheck LIMIT 1}"\n\t\t\t\t&Tmp-String-2 := "%{hex:&User-Name}"\n\t\t\t}\n\t\t}\n"""
          """\t\telsif (&NAS-Identifier == "nuxr-h1-fault-garbage") {\n\t\t\tupdate request {\n\t\t\t\t&Tmp-String-1 := "c=1;zz"\n\t\t\t\t&Tmp-String-2 := "%{hex:&User-Name}"\n\t\t\t}\n\t\t}\n"""
          """\t\telsif (&NAS-Identifier == "nuxr-h1-fault-count") {\n\t\t\tupdate request {\n\t\t\t\t&Tmp-String-1 := "c=2;%{hex:&User-Name}"\n\t\t\t\t&Tmp-String-2 := "%{hex:&User-Name}"\n\t\t\t}\n\t\t}\n"""
          """\t\telse {\n""" + query.replace('\n\t\t', '\n\t\t\t').replace('\t\tupdate', '\t\t\tupdate', 1) + '\t\t}\n')
    return head + HS_DISC + block.replace(prime, fp, 1).replace(query, fq, 1)


def site(mode):
    assert mode in MODES
    if mode != 'project':
        tmp = f'{STATE}/run/wp8-h1-site'
        os.makedirs(os.path.dirname(tmp), exist_ok=True)
        open(tmp, 'w').write(site_text(mode))
        cg.sh(['docker', 'cp', tmp, f'{FR}:{R}/wp8-h1-site'])
        cg.sh(['docker', 'exec', FR, 'touch', f'{R}/wp8-h1-site-on'])
        os.remove(tmp)
    else:
        cg.sh(['docker', 'exec', FR, 'rm', '-f', f'{R}/wp8-h1-site-on', f'{R}/wp8-h1-site'])
    cg.restart()
    return state()


def state():
    run = cg.sh(['docker', 'exec', FR, 'sha256sum', f'{R}/sites-enabled/phpnuxbill']).stdout.split()[0][:16]
    sha = lambda t: hashlib.sha256(t.encode()).hexdigest()[:16]
    def _sha_or_none(m):
        # WP8 H-2B: modes built for the pre-H-2B site text (e.g. the AV-5 probes expect exactly 2 age blocks) may not
        # apply any more; such a mode simply cannot be the running site
        try:
            return sha(site_text(m))
        except AssertionError:
            return None
    mode = {k: m for m in MODES if (k := _sha_or_none(m))}.get(run, 'other')
    prof = ros_rows(['/ip/hotspot/profile/print', '?name=nuxr_wp8_hs_prof'])[0]
    return {'site_mode': mode, 'site_sha16': run, 'hotspot_profile': {k: prof.get(k) for k in ('login-by', 'mac-auth-mode', 'http-cookie-lifetime')},
            'a2r': g.state()['readiness']}


def record(case, data):
    os.makedirs(os.path.dirname(EVID), mode=0o700, exist_ok=True)
    line = ag._redact(json.dumps({'t': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'case': case, 'site_mode': MODE[0], **data}, default=str, ensure_ascii=False))
    with open(EVID, 'a') as f:
        f.write(line + '\n')
    print(f'[h1] {MODE[0]} {case}: ' + json.dumps(data.get('verdict', ''), default=str, ensure_ascii=False)[:500], flush=True)


MODE = ['?']


# ------------------------------------------------------------------------------------------------ helpers
def ros_rows(words):
    r = g.ros()
    try:
        return [a for k, a in r.talk(words) if k == '!re']
    finally:
        r.close()


def ros_do(*words):
    r = g.ros()
    try:
        res = r.talk(list(words))
        traps = [a for k, a in res if k == '!trap']
        if traps:
            raise RuntimeError(traps)
    finally:
        r.close()


def profile(**kv):
    ros_do('/ip/hotspot/profile/set', '=numbers=nuxr_wp8_hs_prof', *[f'={k.replace("_", "-")}={v}' for k, v in kv.items()])


def restore_profile():
    profile(login_by='http-chap', mac_auth_mode='mac-as-username')


def provision(name, password, shared, quota=False):
    """a Hotspot subscriber as the accepted RadiusPolicyBuilder writes it (credentials, Simultaneous-Use, Expiration,
    Port-Limit, rate, WISPr session end)"""
    h = hexs(name)
    exp = time.strftime('%d %b %Y %H:%M:%S', time.localtime(time.time() + 86400))
    wispr = time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime(time.time() + 86400))
    unprovision(name)
    sql(f"INSERT INTO radcheck (username, attribute, op, value) VALUES (CONVERT(X'{h}' USING utf8mb4), 'Cleartext-Password', ':=', '{password}'), "
        f"(CONVERT(X'{h}' USING utf8mb4), 'Simultaneous-Use', ':=', '{shared}'), (CONVERT(X'{h}' USING utf8mb4), 'Expiration', ':=', '{exp}')"
        + (f", (CONVERT(X'{h}' USING utf8mb4), 'Max-All-Session', ':=', '3600'), (CONVERT(X'{h}' USING utf8mb4), 'Max-Data', ':=', '52428800'), "
           f"(CONVERT(X'{h}' USING utf8mb4), 'Nuxr-Period-Start', ':=', '{int(time.time()) - 60}')" if quota else '') + "; "
        f"INSERT INTO radreply (username, attribute, op, value) VALUES (CONVERT(X'{h}' USING utf8mb4), 'Port-Limit', ':=', '{shared}'), "
        f"(CONVERT(X'{h}' USING utf8mb4), 'Mikrotik-Rate-Limit', ':=', '256k/512k'), (CONVERT(X'{h}' USING utf8mb4), 'WISPr-Session-Terminate-Time', ':=', '{wispr}')")


def unprovision(name):
    h = hexs(name)
    sql(f"DELETE FROM radcheck WHERE username = CONVERT(X'{h}' USING utf8mb4) COLLATE utf8mb4_bin; DELETE FROM radreply WHERE username = CONVERT(X'{h}' USING utf8mb4) COLLATE utf8mb4_bin")


def new_base(tag):
    return f'nuxr_h1_{tag}_cafe_{secrets.token_hex(2)}', 'H1-' + secrets.token_hex(6)


def fresh(*clients):
    for c in clients:
        ag.hs_logout(c)
    for x in ros_rows(['/ip/hotspot/active/print']):
        ros_do('/ip/hotspot/active/remove', '=.id=' + x['.id'])
    for x in ros_rows(['/ip/hotspot/host/print']):
        try:
            ros_do('/ip/hotspot/host/remove', '=.id=' + x['.id'])
        except Exception:
            pass
    time.sleep(1)
    for c in clients:
        ag.hs_dhcp(c)


def live(base):
    """Hotspot active sessions of the subscriber (any spelling)"""
    fold = lambda s: (s or '').lower().replace('é', 'e')
    return [{k: x.get(k) for k in ('user', 'address', 'mac-address', 'login-by')} for x in ros_rows(['/ip/hotspot/active/print'])
            if fold(x.get('user')) == fold(base)]


def radacct(base):
    return [{'username_hex': r[0], 'username': bytes.fromhex(r[0]).decode('utf-8', 'replace'), 'acctsessionid': r[1], 'start': r[2], 'update': r[3], 'stop': r[4]}
            for r in sqlrows(f"SELECT HEX(username), acctsessionid, acctstarttime, IFNULL(acctupdatetime,'-'), IFNULL(acctstoptime,'-') FROM radacct "
                             f"WHERE username = CONVERT(X'{hexs(base)}' USING utf8mb4) ORDER BY radacctid")]


def packets(cap, t0, base):
    """RADIUS packets of the subscriber in the capture: Access-Request (User-Name sent by the NAS), the reply (User-Name
    and policy attributes sent by FreeRADIUS), Accounting (User-Name, status)"""
    out, ids = [], {}
    fold = lambda s: (s or '').lower().replace('é', 'e')
    for p in g.pcap_packets(cap):
        a = p['attrs']
        d = dict(a)
        if p['code'] == 'Access-Request' and fold(d.get('User-Name')) == base.lower():
            ids[(p['id'], p['src'])] = True
            out.append({'t_ms': round((p['t_us'] - t0 * 1e6) / 1000), 'code': p['code'], 'id': p['id'], 'user_name': d.get('User-Name')})
        elif p['code'] in ('Access-Accept', 'Access-Reject') and (p['id'], p['dst']) in ids:
            out.append({'t_ms': round((p['t_us'] - t0 * 1e6) / 1000), 'code': p['code'], 'id': p['id'], 'user_name': d.get('User-Name'),
                        'attrs': [(k, v) for k, v in a if k not in ('Message-Authenticator',)]})
        elif p['code'] == 'Accounting-Request' and fold(d.get('User-Name')) == base.lower():
            out.append({'t_ms': round((p['t_us'] - t0 * 1e6) / 1000), 'code': p['code'], 'status': d.get('Acct-Status-Type'), 'user_name': d.get('User-Name')})
    return out


def login(client, user, pw, method='chap'):
    if method == 'pap':
        return hs_login_pap(client, user, pw)
    return ag.hs_login(client, user, pw)


def hs_login_pap(client, user, password, jar=None):
    ns = ag.CLIENTS[client][0]
    extra = ['-c', jar, '-b', jar] if jar else []
    page, _ = ag._curl(ns, *extra, 'http://10.88.2.1/login')
    body, _ = ag._curl(ns, *extra, '--data-urlencode', f'username={user}', '--data-urlencode', f'password={password}',
                       '--data-urlencode', 'dst=', '--data-urlencode', 'popup=false', 'http://10.88.2.1/login')
    text = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body))
    ok = re.search(r'You are logged in', text) is not None
    return {'ok': ok, 'state': 'LOGGED_IN' if ok else 'REFUSED', 'message': text[:160].strip(), 'auth': 'http-pap', 'chap_form': 'hexMD5' in page}


def seq(base, pw, plan, method='chap'):
    """logins one after the other: plan = [(client, spelling)]"""
    res = []
    for c, sp in plan:
        r = login(c, sp, pw, method)
        res.append({'client': c, 'spelling': sp, 'state': r['state'], 'message': r['message'][:90]})
        time.sleep(2)
    time.sleep(2)
    return res


def conc(base, pw, plan):
    """logins launched together from independent clients"""
    box, ths = {}, []
    for c, sp in plan:
        ths.append(threading.Thread(target=lambda c=c, sp=sp: box.__setitem__(c, login(c, sp, pw))))
    for t in ths:
        t.start()
    for t in ths:
        t.join()
    time.sleep(3)
    return [{'client': c, 'spelling': sp, 'state': box[c]['state'], 'message': box[c]['message'][:90]} for c, sp in plan]


CASES = {}


def case(fn):
    CASES[fn.__name__] = fn
    return fn


# ================================================================================================ cases
@case
def identity():
    """one login under the UPPER-CASE spelling of a lower-case subscriber, held 75 s (one Interim), then logout:
    the name in the Access-Accept (pcap), in /ip hotspot active, in Start / Interim / Stop (pcap) and in radacct"""
    base, pw = new_base('id')
    provision(base, pw, 1)
    fresh('c2')
    cap = f'h1-identity-{MODE[0]}'
    ag.capture_start(cap)
    t0 = time.time()
    r = login('c2', base.upper(), pw)
    time.sleep(2)
    act = live(base)
    time.sleep(73)
    act2 = live(base)
    ag.hs_logout('c2')
    time.sleep(4)
    pk = packets(ag.capture_stop(cap)['file'], t0, base)
    rows = radacct(base)
    acc = [p for p in pk if p['code'] == 'Access-Accept']
    v = {'login': r['state'], 'typed': base.upper(), 'canonical': base,
         'accept_user_name': [p['user_name'] for p in acc], 'accept_attrs': acc[0]['attrs'] if acc else None,
         'request_user_name': [p['user_name'] for p in pk if p['code'] == 'Access-Request'],
         'active_user': [x['user'] for x in act], 'active_user_after_75s': [x['user'] for x in act2],
         'accounting': [(p['status'], p['user_name']) for p in pk if p['code'] == 'Accounting-Request'],
         'radacct_username': [x['username'] for x in rows]}
    v['nas_uses_canonical'] = bool(act) and all(x == base for x in v['active_user']) and bool(v['accounting']) and all(u == base for _, u in v['accounting'])
    # the exact spelling, same client
    e = login('c2', base, pw)
    time.sleep(2)
    v['exact_login'] = e['state']
    v['exact_active'] = [x['user'] for x in live(base)]
    ag.hs_logout('c2')
    v['h1a_pass'] = (r['state'] == 'REFUSED' and not act and not v['accounting'] and e['state'] == 'LOGGED_IN' and v['exact_active'] == [base])
    record('H1.identity', {'verdict': v, 'packets': pk})
    unprovision(base)
    return v


@case
def shared1():
    """Port-Limit = Simultaneous-Use = 1 (shared_users 1): exact twice (control), exact then UPPER, UPPER then exact,
    UPPER then accent, mixed-case voucher-style subscriber typed upper then lower"""
    out = {}
    plans = {'exact_exact': lambda b: [('c2', b), ('c4', b)], 'exact_upper': lambda b: [('c2', b), ('c4', b.upper())],
             'upper_exact': lambda b: [('c2', b.upper()), ('c4', b)], 'upper_accent': lambda b: [('c2', b.upper()), ('c4', b.replace('cafe', 'café'))]}
    for label, plan in plans.items():
        base, pw = new_base('s1')
        provision(base, pw, 1)
        fresh('c2', 'c4')
        res = seq(base, pw, plan(base))
        out[label] = {'logins': res, 'live': live(base)}
        fresh('c2', 'c4')
        unprovision(base)
    # voucher-style mixed-case code (plan.php voucher_format "rand" = randomUpLowCase), stored as issued
    code = 'Vx' + secrets.token_hex(3).upper() + 'kQ'
    provision(code, code, 1)
    fresh('c2', 'c4')
    res = seq(code, code, [('c2', code.upper()), ('c4', code.lower())])
    out['voucher_upper_lower'] = {'code': code, 'logins': res, 'live': live(code)}
    fresh('c2', 'c4')
    res = seq(code, code, [('c2', code), ('c4', code.upper())])
    out['voucher_exact_then_upper'] = {'code': code, 'logins': res, 'live': live(code)}
    fresh('c2', 'c4')
    unprovision(code)
    v = {k: {'states': [x['state'] for x in o['logins']], 'live': len(o['live']), 'live_users': [x['user'] for x in o['live']]} for k, o in out.items()}
    v['max_live'] = max(x['live'] for x in v.values() if isinstance(x, dict))
    exp = {'exact_exact': ['LOGGED_IN', 'REFUSED'], 'exact_upper': ['LOGGED_IN', 'REFUSED'], 'upper_exact': ['REFUSED', 'LOGGED_IN'],
           'upper_accent': ['REFUSED', 'REFUSED'], 'voucher_upper_lower': ['REFUSED', 'REFUSED'], 'voucher_exact_then_upper': ['LOGGED_IN', 'REFUSED']}
    v['h1a_pass'] = all(v[k]['states'] == st for k, st in exp.items()) and v['max_live'] <= 1
    record('H1.shared1', {'verdict': v, 'detail': out})
    return v


@case
def shared2():
    """Port-Limit = 2 (shared_users 2): exact x3 (control: 2 allowed, 3rd refused); exact, UPPER, accent from three
    clients"""
    out = {}
    for label, plan in (('exact_x3', lambda b: [('c2', b), ('c4', b), ('c5', b)]),
                        ('three_spellings', lambda b: [('c2', b), ('c4', b.upper()), ('c5', b.replace('cafe', 'café'))])):
        base, pw = new_base('s2')
        provision(base, pw, 2)
        fresh(*HS)
        res = seq(base, pw, plan(base))
        out[label] = {'logins': res, 'live': live(base)}
        fresh(*HS)
        unprovision(base)
    v = {k: {'states': [x['state'] for x in o['logins']], 'live': len(o['live']), 'live_users': [x['user'] for x in o['live']]} for k, o in out.items()}
    v['h1a_pass'] = (v['exact_x3']['states'] == ['LOGGED_IN', 'LOGGED_IN', 'REFUSED'] and v['exact_x3']['live'] == 2
                     and v['three_spellings']['states'] == ['LOGGED_IN', 'REFUSED', 'REFUSED'] and v['three_spellings']['live'] == 1)
    record('H1.shared2', {'verdict': v, 'detail': out})
    return v


@case
def concurrent():
    """logins launched together: Port-Limit 1 - two spellings (5 trials), same spelling (5 trials, RouterOS' own
    check); Port-Limit 2 - three spellings (3 trials)"""
    out = {}
    for label, limit, n, plan in (('pl1_two_spellings', 1, 5, lambda b: [('c2', b.upper()), ('c4', b)]),
                                  ('pl1_same_spelling', 1, 5, lambda b: [('c2', b), ('c4', b)]),
                                  ('pl1_three_spellings', 1, 3, lambda b: [('c2', b.upper()), ('c4', b), ('c5', b.replace('cafe', 'café'))]),
                                  ('pl2_three_spellings', 2, 3, lambda b: [('c2', b), ('c4', b.upper()), ('c5', b.replace('cafe', 'café'))])):
        trials = []
        for i in range(n):
            base, pw = new_base('cc')
            provision(base, pw, limit)
            fresh(*[c for c, _ in plan(base)])
            res = conc(base, pw, plan(base))
            lv = live(base)
            trials.append({'logins': [x['state'] for x in res], 'live': len(lv), 'live_users': [x['user'] for x in lv]})
            fresh(*HS)
            unprovision(base)
        out[label] = {'limit': limit, 'trials': trials, 'max_live': max(t['live'] for t in trials)}
    vv = {k: {'limit': o['limit'], 'max_live': o['max_live'], 'live_per_trial': [t['live'] for t in o['trials']]} for k, o in out.items()}
    vv['h1a_pass'] = all(o['max_live'] <= o['limit'] for o in out.values()) and out['pl1_two_spellings']['max_live'] == 1 and out['pl2_three_spellings']['max_live'] == 1
    record('H1.concurrent', {'verdict': vv, 'detail': out})
    return out


@case
def lost_accounting():
    """Port-Limit 1. Accounting-Start lost (NAS output to 1813 dropped): UPPER first, then exact. Accounting-Stop lost:
    UPPER logs out with the Stop dropped, then exact logs in (the session is really gone)"""
    out = {}
    base, pw = new_base('la')
    provision(base, pw, 1)
    fresh('c2', 'c4')
    rule = g.block_acct()
    try:
        res = seq(base, pw, [('c2', base.upper()), ('c4', base)])
        out['start_lost'] = {'logins': res, 'live': live(base), 'radacct_rows': len(radacct(base))}
        fresh(*HS)
        res = seq(base, pw, [('c2', base), ('c4', base.upper()), ('c5', base)])
        out['start_lost_exact_first'] = {'logins': res, 'live': live(base), 'radacct_rows': len(radacct(base))}
    finally:
        g.unblock(rule)
    fresh('c2', 'c4')
    unprovision(base)
    base, pw = new_base('ls')
    provision(base, pw, 1)
    fresh('c2', 'c4')
    a = login('c2', base, pw)
    time.sleep(3)
    rule = g.block_acct()
    try:
        ag.hs_logout('c2')
        time.sleep(3)
        gone = live(base) == []
    finally:
        g.unblock(rule)
    stale = radacct(base)
    b = login('c4', base, pw)
    time.sleep(3)
    out['stop_lost'] = {'first': a['state'], 'gone_at_nas': gone, 'radacct_open_rows': [r['username'] for r in stale if r['stop'] == '-'],
                        'relogin_exact': b['state'], 'live': live(base)}
    fresh('c2', 'c4')
    unprovision(base)
    v = {'start_lost': {'states': [x['state'] for x in out['start_lost']['logins']], 'live': len(out['start_lost']['live'])},
         'start_lost_exact_first': {'states': [x['state'] for x in out['start_lost_exact_first']['logins']], 'live': len(out['start_lost_exact_first']['live'])},
         'stop_lost': {'relogin': out['stop_lost']['relogin_exact'], 'live': len(out['stop_lost']['live']), 'stale_rows': out['stop_lost']['radacct_open_rows']}}
    v['h1a_pass'] = (v['start_lost'] == {'states': ['REFUSED', 'LOGGED_IN'], 'live': 1}
                     and v['start_lost_exact_first'] == {'states': ['LOGGED_IN', 'REFUSED', 'REFUSED'], 'live': 1}
                     and v['stop_lost']['live'] == 1)
    record('H1.lost_accounting', {'verdict': v, 'detail': out})
    return v


@case
def reconnect():
    """Port-Limit 1, one client: UPPER login, logout, exact login, logout, UPPER login again; the active name each time"""
    base, pw = new_base('rc')
    provision(base, pw, 1)
    fresh('c2')
    steps = []
    for sp in (base.upper(), base, base.upper()):
        r = login('c2', sp, pw)
        time.sleep(2)
        steps.append({'typed': sp, 'state': r['state'], 'active': [x['user'] for x in live(base)]})
        ag.hs_logout('c2')
        time.sleep(3)
    fresh('c2')
    unprovision(base)
    record('H1.reconnect', {'verdict': steps, 'h1a_pass': [x['state'] for x in steps] == ['REFUSED', 'LOGGED_IN', 'REFUSED']})
    return steps


@case
def http_pap():
    """the Hotspot profile switched to login-by=http-pap (lab only, restored): UPPER then exact, Port-Limit 1"""
    base, pw = new_base('pap')
    provision(base, pw, 1)
    profile(login_by='http-pap')
    try:
        fresh('c2', 'c4')
        res = seq(base, pw, [('c2', base.upper()), ('c4', base)], method='pap')
        lv = live(base)
    finally:
        fresh('c2', 'c4')
        restore_profile()
        unprovision(base)
    v = {'states': [x['state'] for x in res], 'live': len(lv), 'live_users': [x['user'] for x in lv], 'logins': res}
    v['h1a_pass'] = v['states'] == ['REFUSED', 'LOGGED_IN'] and v['live'] == 1
    record('H1.http_pap', {'verdict': v})
    return v


def _chap_login_jar(ns, jar, user, pw):
    page, _ = ag._curl(ns, '-c', jar, '-b', jar, 'http://10.88.2.1/login')
    m = re.search(r"hexMD5\('((?:\\\d{3})+)' \+ document\.login\.password\.value \+ '((?:\\\d{3})+)'\)", page)
    resp = hashlib.md5(ag._octal(m.group(1)) + pw.encode() + ag._octal(m.group(2))).hexdigest() if m else ''
    body, _ = ag._curl(ns, '-c', jar, '-b', jar, '--data-urlencode', f'username={user}', '--data-urlencode', f'password={resp}',
                       '--data-urlencode', 'dst=', '--data-urlencode', 'popup=false', 'http://10.88.2.1/login')
    return 'You are logged in' in re.sub(r'<[^>]+>', ' ', body)


@case
def cookie():
    """login-by=http-chap,cookie (lab only, restored), per spelling (UPPER, then exact): login with a cookie jar, the
    active session removed on the NAS, the client browses again -> cookie re-login: the User-Name RouterOS sends, the
    RADIUS answer, the active name"""
    base, pw = new_base('ck')
    provision(base, pw, 1)
    profile(login_by='http-chap,cookie', http_cookie_lifetime='1h')
    jar = f'{STATE}/run/h1-cookie.jar'
    out = {}
    try:
        for label, typed in (('upper', base.upper()), ('exact', base)):
            fresh('c2')
            for c in ros_rows(['/ip/hotspot/cookie/print']):
                ros_do('/ip/hotspot/cookie/remove', '=.id=' + c['.id'])
            if os.path.exists(jar):
                os.remove(jar)
            ns = ag.CLIENTS['c2'][0]
            ok = _chap_login_jar(ns, jar, typed, pw)
            o = {'first_login': ok, 'active': [x['user'] for x in live(base)], 'cookies_on_nas': [c.get('user') for c in ros_rows(['/ip/hotspot/cookie/print'])]}
            cap = f'h1-cookie-{label}-{MODE[0]}'
            ag.capture_start(cap)
            t0 = time.time()
            for x in ros_rows(['/ip/hotspot/active/print']):
                ros_do('/ip/hotspot/active/remove', '=.id=' + x['.id'])
            time.sleep(2)
            ag._curl(ns, '-c', jar, '-b', jar, '-L', 'http://10.88.2.1/login')
            time.sleep(3)
            o['after_relogin_attempt'] = [{k: x.get(k) for k in ('user', 'login-by')} for x in ros_rows(['/ip/hotspot/active/print'])]
            pk = packets(ag.capture_stop(cap)['file'], t0, base)
            o['relogin_requests'] = [p.get('user_name') for p in pk if p['code'] == 'Access-Request']
            o['relogin_replies'] = [p['code'] for p in pk if p['code'] in ('Access-Accept', 'Access-Reject')]
            out[label] = o
    finally:
        fresh('c2')
        for c in ros_rows(['/ip/hotspot/cookie/print']):
            ros_do('/ip/hotspot/cookie/remove', '=.id=' + c['.id'])
        restore_profile()
        if os.path.exists(jar):
            os.remove(jar)
        unprovision(base)
    v = {k: {'first_login': o['first_login'], 'cookies': o['cookies_on_nas'], 'relogin_requests': o['relogin_requests'], 'relogin_replies': o['relogin_replies'],
             'active_after': o['after_relogin_attempt']} for k, o in out.items()}
    v['h1a_pass'] = (out['upper']['first_login'] is False and out['upper']['cookies_on_nas'] == []
                     and out['exact']['first_login'] is True and out['exact']['relogin_replies'][-1:] == ['Access-Accept']
                     and [x['user'] for x in out['exact']['after_relogin_attempt']] == [base])
    record('H1.cookie', {'verdict': v})
    return v


@case
def mac_login():
    """login-by=mac,http-chap (lab only, restored) in both RouterOS MAC modes: mac-as-username-and-password (password =
    the MAC as sent) and mac-as-username (empty password); the client MAC provisioned as the RADIUS username in lower
    and in upper case: what RouterOS sends, the RADIUS answer, the active name"""
    out = {}
    ns, nic, _ = ag.CLIENTS['c2']
    mac = re.search(r'link/ether (\S+)', ag.nsx(ns, 'ip', 'link', 'show', nic).stdout).group(1)
    try:
        for mode in ('mac-as-username-and-password', 'mac-as-username'):
            profile(login_by='mac,http-chap', mac_auth_mode=mode)
            for label, stored in (('stored_lower', mac.lower()), ('stored_upper', mac.upper())):
                provision(stored, stored if mode == 'mac-as-username-and-password' else '', 1)
                cap = f'h1-mac-{mode[-8:]}-{label}-{MODE[0]}'
                ag.capture_start(cap)
                t0 = time.time()
                fresh('c2')
                ag.http_probe('c2')
                time.sleep(4)
                act = [{k: x.get(k) for k in ('user', 'login-by')} for x in ros_rows(['/ip/hotspot/active/print'])]
                pk = packets(ag.capture_stop(cap)['file'], t0, stored)
                out[f'{mode}/{label}'] = {'stored': stored, 'requests': sorted(set(p.get('user_name') for p in pk if p['code'] == 'Access-Request')),
                                          'replies': sorted(set(p['code'] for p in pk if p['code'] in ('Access-Accept', 'Access-Reject'))), 'active': act}
                fresh('c2')
                unprovision(stored)
    finally:
        restore_profile()
        fresh('c2')
    record('H1.mac_login', {'verdict': out})
    return out


@case
def mac_compare():
    """controlled MAC-login comparison (owner 2026-10-10): both RouterOS MAC modes x the client MAC stored lower / UPPER
    case; per run the FULL Access-Request attributes RouterOS sent (pcap), the RADIUS answer, and the FreeRADIUS reason
    (password check / H1-A tag) - to run once with site "pre" and once with site "project"."""
    out = {}
    ns, nic, _ = ag.CLIENTS['c2']
    mac = re.search(r'link/ether (\S+)', ag.nsx(ns, 'ip', 'link', 'show', nic).stdout).group(1)
    try:
        for mode in ('mac-as-username-and-password', 'mac-as-username'):
            profile(login_by='mac,http-chap', mac_auth_mode=mode)
            for label, stored in (('stored_lower', mac.lower()), ('stored_upper', mac.upper())):
                provision(stored, stored if mode == 'mac-as-username-and-password' else '', 1)
                cap = f'h1-maccmp-{mode[-8:]}-{label}-{MODE[0]}'
                t_log = g.since()
                ag.capture_start(cap)
                t0 = time.time()
                fresh('c2')
                ag.http_probe('c2')
                time.sleep(4)
                act = [{k: x.get(k) for k in ('user', 'login-by')} for x in ros_rows(['/ip/hotspot/active/print'])]
                pk = [p for p in g.pcap_packets(ag.capture_stop(cap)['file'])]
                reqs = [p for p in pk if p['code'] == 'Access-Request' and dict(p['attrs']).get('User-Name', '').lower() == mac.lower()]
                ids = {(p['id'], p['src']) for p in reqs}
                reps = [p['code'] for p in pk if p['code'] in ('Access-Accept', 'Access-Reject') and (p['id'], p['dst']) in ids]
                lg = cg.sh(['docker', 'logs', '--since', t_log, FR], check=False)
                reasons = sorted({re.sub(r'^\(\d+\)\s+', '', l).strip()[:110] for l in (lg.stdout + lg.stderr).splitlines()
                                  if re.search(r'Password comparison failed|password is incorrect|Cleartext-Password is required|nuxr-hotspot=|Login incorrect', l) and 'EXPAND' not in l and '-->' not in l})
                out[f'{mode}/{label}'] = {'stored': stored,
                                          'request_attrs': [(k, v) for k, v in reqs[0]['attrs']] if reqs else None,
                                          'requests': len(reqs), 'replies': reps, 'reasons': reasons, 'active': act}
                fresh('c2')
                unprovision(stored)
    finally:
        restore_profile()
        fresh('c2')
    record('H1.mac_compare', {'verdict': {k: {'replies': sorted(set(v['replies'])), 'reasons': v['reasons'], 'active': [a['user'] for a in v['active']]} for k, v in out.items()},
                              'detail': out})
    return out


@case
def av5_age_study():
    """WP8 AV-5 (A2-R request age clock consistency; lab only). Synthetic PPPoE Access-Requests from the FreeRADIUS
    network namespace, each sent at a planned offset before a second boundary (sweep -15 .. +1 ms, step 0.1 ms) so that
    the post-auth age computation falls at every position around the boundary. Per request: the age the policy computed
    (pre-claim check) and the TRUE age = FreeRADIUS log write time of that assignment - log write time of "Received".
    Run once with site "project" (current formula) and once with site "av5" (proposed formula)."""
    u, pw = g.new_user('av5')
    out = []
    try:
        for i in range(161):
            off = -0.015 + i * 0.0001
            at = g.Attempt(u, pw)
            pkt = g.access_request(at.attrs(), pw, chap=True)
            target = int(time.time()) + 1 + off
            while time.time() < target - 0.002:
                time.sleep(0.0005)
            while time.time() < target:
                pass
            r = g.exchange(pkt)
            cls = g.reply_class(r)
            if cls:
                at.acct('Stop', cls)
            out.append({'offset_ms': round(off * 1000, 1), 'reply': r['reply'] and r['reply']['code'], 'asid': at.asid})
    finally:
        g.unprovision(u)
    # FreeRADIUS log: per request, the receive time and the first (pre-claim) final age assignment
    lg = cg.sh(['docker', 'logs', '-t', '--since', time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime(time.time() - 200)), FR], check=False)
    recv, ages, users, asids = {}, {}, {}, {}
    for l in (lg.stdout + lg.stderr).splitlines():
        ts, _, rest = l.partition(' ')
        m = re.match(r'\((\d+)\)\s+(.*)$', rest)
        if not m:
            continue
        n, body = m.groups()
        if body.startswith('Received Access-Request'):
            recv[n] = ts
            ages.pop(n, None)
            continue
        if body.startswith('Acct-Session-Id = '):
            asids[n] = body.split('"')[1] if '"' in body else body
        mm = re.match(r'&Tmp-String-6 := (-?[0-9]+)$', body)
        if mm and n in recv and n not in ages:
            ages[n] = (ts, int(mm.group(1)))
    by_asid = {asids[n]: n for n in ages if n in asids}
    rows = []
    for o in out:
        n = by_asid.get(o['asid'])
        if n is None:
            rows.append({**o, 'computed_ms': None})
            continue
        ts, age = ages[n]
        true = (g._epoch(ts) - g._epoch(recv[n])) * 1000
        rows.append({**o, 'computed_ms': age, 'true_ms': round(true, 2), 'error_ms': round(age - true, 1),
                     'eval_usec_into_second': int(round((g._epoch(ts) % 1) * 1e6))})
    ok = [r for r in rows if r.get('computed_ms') is not None]
    v = {'requests': len(rows), 'measured': len(ok), 'negative_ages': sum(1 for r in ok if r['computed_ms'] < 0),
         'under_estimates_gt_5ms': sum(1 for r in ok if r['error_ms'] < -5), 'over_estimates_gt_5ms': sum(1 for r in ok if r['error_ms'] > 5),
         'max_over_ms': max((r['error_ms'] for r in ok), default=None), 'min_error_ms': min((r['error_ms'] for r in ok), default=None),
         'replies': {c: sum(1 for r in rows if r['reply'] == c) for c in ('Access-Accept', 'Access-Reject', None)},
         'anomalies': [r for r in ok if abs(r['error_ms']) > 5][:20]}
    record('AV5.age_study', {'verdict': v, 'rows': rows})
    return v


def _c_div(a, b):
    q = abs(a) // abs(b)
    return q if (a >= 0) == (b > 0) else -q


@case
def av5_probe():
    """site "av5probe" only: the proposed AV-5 age formula evaluated by the REAL FreeRADIUS 3.2.8 (unlang regex + rlm_expr)
    on fixed clock inputs; the result then goes through the unchanged late-claim check (> 5000 ms or malformed ->
    reject). Expected values computed with C integer division (truncation toward zero)."""
    assert MODE[0] == 'av5probe', 'needs: h1_gate.py site av5probe'
    u, pw = g.new_user('av5p')
    out = {}
    try:
        for k, (c_us, m_us, dsec) in AV5_PROBES.items():
            at = g.Attempt(u, pw, nas_id=f'nuxr-av5-probe-{k}')
            t = g.since()
            r = g.exchange(g.access_request(at.attrs(), pw, chap=True))
            cls = g.reply_class(r)
            if cls:
                at.acct('Stop', cls)
            lg = cg.sh(['docker', 'logs', '--since', t, FR], check=False)
            vals = [re.sub(r'^\(\d+\)\s+', '', l).strip() for l in (lg.stdout + lg.stderr).splitlines() if '&Tmp-String-6 := ' in l]
            if c_us == 'x':
                exp, exp_reply = None, 'Access-Reject'
            else:
                exp = _c_div(c_us - m_us, 1000) + dsec * 1000 + _c_div(999999 - c_us, 980000) * 1000
                exp_reply = 'Access-Accept' if exp <= 5000 else 'Access-Reject'
            out[k] = {'inputs': {'C_us': c_us, 'M_us': m_us, 'c_minus_l': dsec}, 'freeradius_assignments': vals[:2], 'expected_age_ms': exp,
                      'reply': r['reply'] and r['reply']['code'], 'expected_reply': exp_reply}
            out[k]['ok'] = out[k]['reply'] == exp_reply and (exp is None or (len(vals) >= 2 and vals[1].endswith(f':= {exp}')))
    finally:
        g.unprovision(u)
    v = {**out, 'pass': all(o['ok'] for o in out.values())}
    record('AV5.probe', {'verdict': v})
    return v


# ------------------------------------------------------------------------------------------------ AV-5 (age clock)
AV5_LINE = re.compile(r'nuxr-av5-age blk=([AB]) age=(\S*) pre_us=(\d+) now_us=(\d+) coarse_s=(\d+) arr_us=(\d+) asid=(\S+?)"?$')


def _av5_log(since_rfc):
    """per Acct-Session-Id: the logged age lines (block A / B) and the admission tags of that request"""
    lg = cg.sh(['docker', 'logs', '--since', since_rfc, FR], check=False)
    req_asid, rows, tags = {}, {}, {}
    for l in (lg.stdout + lg.stderr).splitlines():
        m = re.match(r'^\((\d+)\)\s+(.*)$', l)
        if not m:
            continue
        n, body = m.groups()
        a = AV5_LINE.search(body)
        if a and 'EXPAND' not in body and not body.startswith('-->'):
            blk, age, pre_us, now_us, coarse, arr_us, asid = a.groups()
            req_asid[n] = asid
            rows.setdefault(asid, {})[blk] = {'age': age, 'pre_us': int(pre_us), 'now_us': int(now_us), 'coarse_s': int(coarse), 'arr_us': int(arr_us)}
        elif 'nuxr-admission' in body and 'EXPAND' not in body and not body.startswith('-->'):
            tags.setdefault(n, []).append(re.sub(r'.*(nuxr-admission[^"]*).*', r'\1', body)[:60])
    return {asid: {'blocks': b, 'tags': sorted(set(tags.get(n, [])) - {''}) if (n := next((k for k, v in req_asid.items() if v == asid), None)) else []}
            for asid, b in rows.items()}


def _verify(raw, pkt):
    ln = struct.unpack('!H', raw[2:4])[0]
    raw = raw[:ln]
    if raw[1] != pkt[1] or hashlib.md5(raw[:4] + pkt[4:20] + raw[20:] + g.SECRET).digest() != raw[4:20]:
        return None
    return {'code': g.CODES.get(raw[0], raw[0]), 'attrs': g._decode_attrs(raw[20:]), 'raw_md5': hashlib.md5(raw).hexdigest()}


def _av5_one(u, pw, hold_ms, blk, delta_ms, lat_ms, retx=()):
    """one synthetic PPPoE attempt sent so that age block $blk is evaluated $delta_ms after a second boundary; the
    same bytes are re-sent at each offset (s) in $retx, like a NAS retransmission; every reply is collected"""
    nas_id = f'nuxr-av5-hold-{hold_ms}-{blk}' if hold_ms else 'nuxr-av5-x'
    at = g.Attempt(u, pw, nas_id=nas_id)
    pkt = g.access_request(at.attrs(), pw, chap=True)
    b = int(time.time() + hold_ms / 1000) + 2
    send_at = b + (delta_ms - hold_ms - lat_ms) / 1000
    sock = g.ns_socket()
    sock.settimeout(0.02)
    while time.time() < send_at - 0.002:
        time.sleep(0.0005)
    while time.time() < send_at:
        pass
    t0 = time.time()
    sock.sendto(pkt, (g.APP, 1812))
    sends, replies, todo = [t0], [], sorted(retx)
    end = t0 + (max(retx) + 3.0 if retx else hold_ms / 1000 + 5.0)
    while time.time() < end:
        if todo and time.time() >= t0 + todo[0]:
            sock.sendto(pkt, (g.APP, 1812))
            sends.append(time.time())
            todo.pop(0)
        try:
            raw, _ = sock.recvfrom(4096)
        except socket.timeout:
            continue
        rep = _verify(raw, pkt)
        if rep:
            replies.append((time.time(), rep))
            if not retx:
                break
    sock.close()
    first = replies[0][1] if replies else None
    cls = next((v for k, v in (first or {}).get('attrs', []) if k == 'Class'), None)
    if first and first['code'] == 'Access-Accept' and cls:
        at.acct('Stop', cls)
    return {'asid': at.asid, 'hold_ms': hold_ms, 'blk': blk, 'delta_ms': delta_ms, 't_send': sends, 'replies': [(t, r['code'], r['raw_md5']) for t, r in replies],
            'claim_after': [(c['state'], c['end_reason']) for c in g.resv(u)]}


def _av5_check(x, log, formula):
    """the checks of one attempt against its own logged clock values and the client's send / reply times"""
    e = log.get(x['asid'], {'blocks': {}, 'tags': []})
    blocks, out = e['blocks'], {}
    t_send, t_reply = x['t_send'][0], (x['replies'][0][0] if x['replies'] else None)
    reply = x['replies'][0][1] if x['replies'] else None
    for blk, r in blocks.items():
        ok_int = bool(re.fullmatch(r'[0-9]{1,9}', r['age']))
        age = int(r['age']) if re.fullmatch(r'-?[0-9]+', r['age']) else None
        ref = (r['now_us'] - r['arr_us']) / 1000
        low = (r['pre_us'] - r['arr_us']) // 1000
        lag = r['coarse_s'] < r['now_us'] // 1_000_000
        held = x['hold_ms'] if x['blk'] == blk else (x['hold_ms'] if (x['blk'] == 'A' and blk == 'B') else 0)
        out[blk] = {'age_ms': age, 'ref_ms': round(ref, 3), 'ref_minus_age_ms': None if age is None else round(ref - age, 3),
                    'eval_ms_into_second': round((r['now_us'] % 1_000_000) / 1000, 3), 'coarse_lag': lag,
                    'bracket_ms': [low, round(ref, 3)],
                    'checks': {'well_formed': ok_int,
                               'not_above_one_clock_read_after': age is not None and age <= ref,
                               'not_below_one_clock_read_before': age is not None and age >= low,
                               'not_below_hold': age is not None and age >= held,
                               'receive_after_send': r['arr_us'] / 1e6 >= t_send - 0.0005,
                               'now_before_reply': t_reply is None or r['now_us'] / 1e6 <= t_reply + 0.0005}}
    a, b = out.get('A'), out.get('B')
    late_a = a is None or not a['checks']['well_formed'] or a['age_ms'] > 5000
    late_b = b is not None and (not b['checks']['well_formed'] or b['age_ms'] > 5000)
    if late_a:
        want = ('Access-Reject', 'nuxr-admission=reject:late', None)
    elif late_b:
        want = ('Access-Reject', 'nuxr-admission=reject:late-claim', ('ENDED', 'late_claim'))
    else:
        want = ('Access-Accept', None, ('ENDED', 'acct_stop'))
    decision_ok = (reply == want[0] and (want[1] is None or want[1] in e['tags'])
                   and (want[2] is None or x['claim_after'][:1] == [want[2]]) and (b is not None or late_a))
    retx_ok = len({md5 for _, _, md5 in x['replies']}) <= 1 and all(c == reply for _, c, _ in x['replies'])
    return {'asid': x['asid'], 'hold_ms': x['hold_ms'], 'hold_blk': x['blk'], 'delta_ms': x['delta_ms'], 'reply': reply,
            'replies': len(x['replies']), 'sends': len(x['t_send']), 'tags': e['tags'], 'claim_after': x['claim_after'],
            'blocks': out, 'expected': want, 'decision_ok': decision_ok, 'retx_same_reply': retx_ok,
            'age_computations': len(blocks)}


@case
def av5_boundary():
    """WP8 AV-5 boundary timing (sites "av5hold" = the fixed project formula, "av5hold_pre" = the pre-fix formula, same
    instrumentation). Synthetic PPPoE Access-Requests from the FreeRADIUS network namespace, each sent so that an age
    block is evaluated at a planned offset from a second boundary:
      sweep    no hold, block A evaluated -3 .. +6 ms around a boundary (step 0.1 ms)
      edge     a SQL hold of 4900 / 4980 / 5020 / 5100 ms before block A or before block B (after the claim), the
               evaluation 0.3 / 1.0 / 2.5 ms after a boundary: the 5000 ms budget at the second boundary
      retx     holds 4500-A, 5500-A, 5500-B; the identical packet re-sent at +3 s and +6 s (RouterOS timing)
    Per evaluation: the computed age, bracketed by one-clock reads (%{time_since:us} - %l%M) right BEFORE and right
    AFTER it (the true age at the evaluation lies between them), whether the coarse %c lagged at that moment, the client
    send / reply times, the reply, the admission tag and the claim."""
    assert MODE[0] in ('av5hold', 'av5hold_pre'), 'needs: h1_gate.py site av5hold | av5hold_pre'
    u, pw = g.new_user('av5b')
    t_rfc = g.since()
    raw = {'calibration': [], 'sweep': [], 'edge': [], 'retx': []}
    try:
        for _ in range(6):
            raw['calibration'].append(_av5_one(u, pw, 0, 'A', 500.0, 0.0))
        cal = _av5_log(t_rfc)
        lat = sorted((cal[x['asid']]['blocks']['A']['now_us'] / 1e6 - x['t_send'][0]) * 1000 for x in raw['calibration']
                     if x['asid'] in cal and 'A' in cal[x['asid']]['blocks'])
        lat_a = lat[len(lat) // 2] if lat else 3.0
        for i in range(91):
            raw['sweep'].append(_av5_one(u, pw, 0, 'A', -3.0 + i * 0.1, lat_a))
        for blk in ('A', 'B'):
            for hold in (4900, 4980, 5020, 5100):
                for d in (0.3, 1.0, 2.5):
                    raw['edge'].append(_av5_one(u, pw, hold, blk, d, lat_a))
        for hold, blk in ((4500, 'A'), (5500, 'A'), (5500, 'B')):
            raw['retx'].append(_av5_one(u, pw, hold, blk, 1.0, lat_a, retx=(3.0, 6.0)))
    finally:
        g.unprovision(u)
    log = _av5_log(t_rfc)
    res = {k: [_av5_check(x, log, MODE[0]) for x in v] for k, v in raw.items()}
    evals = [(k, r, blk, b) for k, rows in res.items() for r in rows for blk, b in r['blocks'].items()]
    near = [e for e in evals if e[3]['eval_ms_into_second'] < 5.0]
    def fails(name):
        return [{'set': k, 'asid': r['asid'], 'blk': blk, **{q: b[q] for q in ('age_ms', 'ref_ms', 'eval_ms_into_second', 'coarse_lag')}, 'hold': r['hold_ms']}
                for k, r, blk, b in evals if not b['checks'][name]]
    v = {'site_mode': MODE[0], 'latency_to_block_A_ms': round(lat_a, 3), 'attempts': sum(len(x) for x in res.values()),
         'age_evaluations': len(evals), 'evaluations_in_first_5ms_after_boundary': len(near),
         'evaluations_with_coarse_lag': sum(1 for e in evals if e[3]['coarse_lag']),
         'max_ref_minus_age_ms': max((e[3]['ref_minus_age_ms'] for e in evals if e[3]['ref_minus_age_ms'] is not None), default=None),
         'min_ref_minus_age_ms': min((e[3]['ref_minus_age_ms'] for e in evals if e[3]['ref_minus_age_ms'] is not None), default=None),
         'check_failures': {c: fails(c)[:12] for c in ('well_formed', 'not_above_one_clock_read_after', 'not_below_one_clock_read_before', 'not_below_hold',
                                                        'receive_after_send', 'now_before_reply')},
         'decision_failures': [{'set': k, **{q: r[q] for q in ('asid', 'hold_ms', 'hold_blk', 'delta_ms', 'reply', 'tags', 'claim_after', 'expected')},
                                'ages': {b_: r['blocks'][b_]['age_ms'] for b_ in r['blocks']}} for k, rows in res.items() for r in rows if not r['decision_ok']],
         'edge_outcomes': [(r['hold_ms'], r['hold_blk'], r['delta_ms'], {b_: r['blocks'][b_]['age_ms'] for b_ in r['blocks']}, r['reply'], r['tags'][-1:] if r['tags'] else [])
                           for r in res['edge']],
         'retx': [{q: r[q] for q in ('hold_ms', 'hold_blk', 'sends', 'replies', 'retx_same_reply', 'age_computations', 'reply', 'tags', 'claim_after')} | {'ages': {b_: r['blocks'][b_]['age_ms'] for b_ in r['blocks']}} for r in res['retx']]}
    v['pass'] = (all(not x for x in v['check_failures'].values()) and not v['decision_failures']
                 and all(r['retx_same_reply'] and r['replies'] >= 1 and r['age_computations'] == (1 if r['hold_blk'] == 'A' and r['hold_ms'] > 5000 else 2) for r in res['retx'])
                 and v['evaluations_in_first_5ms_after_boundary'] >= 20)
    record('AV5.boundary', {'verdict': v, 'rows': res})
    return v


@case
def negatives():
    """wrong password, unknown user, an equivalent spelling (UPPER) with the right password, and the exact login: what
    the Hotspot user sees, the RADIUS answer and its attributes, the answer time (pcap)"""
    base, pw = new_base('neg')
    provision(base, pw, 1)
    out = {}
    for label, user, password in (('wrong_password', base, 'H1-wrong-' + secrets.token_hex(3)), ('unknown_user', 'nuxr_h1_nobody_' + secrets.token_hex(3), pw),
                                  ('equivalent_spelling', base.upper(), pw), ('exact', base, pw)):
        fresh('c2')
        cap = f'h1-neg-{label}-{MODE[0]}'
        ag.capture_start(cap)
        t0 = time.time()
        r = login('c2', user, password)
        time.sleep(2)
        allp = g.pcap_packets(ag.capture_stop(cap)['file'])
        req = [p for p in allp if p['code'] == 'Access-Request']
        rep_ = [p for p in allp if p['code'] in ('Access-Accept', 'Access-Reject')]
        out[label] = {'state': r['state'], 'visible_message': r['message'][:80],
                      'reply': rep_[0]['code'] if rep_ else None,
                      'reply_attrs': sorted(k for k, _ in rep_[0]['attrs'] if k != 'Message-Authenticator') if rep_ else None,
                      'reply_ms': round((rep_[0]['t_us'] - req[0]['t_us']) / 1000) if rep_ and req else None}
        ag.hs_logout('c2')
    fresh('c2')
    unprovision(base)
    v = out
    v['h1a_pass'] = (out['equivalent_spelling']['state'] == 'REFUSED' and out['exact']['state'] == 'LOGGED_IN'
                     and out['equivalent_spelling']['reply'] == out['wrong_password']['reply'] == out['unknown_user']['reply'] == 'Access-Reject'
                     and out['equivalent_spelling']['reply_attrs'] == out['wrong_password']['reply_attrs'] == out['unknown_user']['reply_attrs']
                     and out['equivalent_spelling']['visible_message'] == out['wrong_password']['visible_message'] == out['unknown_user']['visible_message'])
    record('H1.negatives', {'verdict': v})
    return v


@case
def duplicate_identity():
    """two byte-distinct stored spellings of one subscriber (lower and UPPER, both with the same password, Port-Limit 1;
    made on purpose - the A2-R collision pre-check must find none in production): each exact spelling from its own
    client"""
    lower, pw = new_base('dup')
    upper = lower.upper()
    provision(lower, pw, 1)
    provision(upper, pw, 1)
    try:
        fresh('c2', 'c4')
        res = seq(lower, pw, [('c2', lower), ('c4', upper)])
        lv = live(lower)
    finally:
        fresh('c2', 'c4')
        unprovision(lower)
        unprovision(upper)
    v = {'states': [x['state'] for x in res], 'live': len(lv), 'live_users': [x['user'] for x in lv],
         'collision_precheck_left': sqlrows(f"SELECT COUNT(*) FROM radcheck WHERE username = CONVERT(X'{hexs(lower)}' USING utf8mb4)")[0][0]}
    v['h1a_pass'] = v['states'] == ['REFUSED', 'REFUSED'] and v['live'] == 0
    record('H1.duplicate_identity', {'verdict': v})
    return v


@case
def synthetic_faults():
    """site mode "fault" only: synthetic Hotspot Access-Requests from the FreeRADIUS network namespace (source = the lab
    nas row of the application) with NAS-Identifier markers that make the H1-A block hit a real SQL error (priming,
    identity query), an unexpected result, two stored spellings; controls: the exact spelling without a marker
    (Accept) and an equivalent spelling (Reject)"""
    assert MODE[0] == 'fault', 'needs: h1_gate.py site fault'
    base, pw = new_base('flt')
    provision(base, pw, 1)
    out = {}
    try:
        for label, user, marker in (('control_exact', base, 'nuxr_h1_synthetic'), ('control_variant', base.upper(), 'nuxr_h1_synthetic'),
                                    ('sql_error_priming', base, 'nuxr-h1-fault-prime'), ('sql_error_identity', base, 'nuxr-h1-fault-query'),
                                    ('unexpected_result', base, 'nuxr-h1-fault-garbage'), ('two_spellings', base, 'nuxr-h1-fault-count')):
            attrs = [('User-Name', user), ('NAS-IP-Address', g.APP), ('NAS-Port', 1), ('Service-Type', 1), ('NAS-Port-Type', 19),
                     ('NAS-Identifier', marker), ('Calling-Station-Id', '02:A2:00:00:00:31')]
            t = g.since()
            r = g.exchange(g.access_request(attrs, pw, chap=True))
            tags = [l for l in g.tags_since(t, r'nuxr-hotspot=') if 'nuxr-hotspot' in l]
            out[label] = {'reply': r['reply'] and r['reply']['code'], 'attrs': [k for k, _ in (r['reply'] or {}).get('attrs', []) if k != 'Message-Authenticator'],
                          'reason': tags[-1:]}
    finally:
        unprovision(base)
    v = out
    v['h1a_pass'] = (out['control_exact']['reply'] == 'Access-Accept'
                     and all(out[k]['reply'] == 'Access-Reject' and out[k]['attrs'] == [] for k in out if k != 'control_exact'))
    record('H1.synthetic_faults', {'verdict': v})
    return v


@case
def policy_unchanged():
    """the same subscriber, exact spelling: the Access-Accept attributes other than User-Name (rate, Port-Limit,
    Session-Timeout from Expiration, WISPr end) and the accounting row - to compare across site modes"""
    base = 'nuxr_h1_policy_fixed'
    pw = 'H1-policy-fixed'
    provision(base, pw, 2, quota=True)
    fresh('c2')
    cap = f'h1-policy-{MODE[0]}'
    ag.capture_start(cap)
    t0 = time.time()
    r = login('c2', base, pw)
    time.sleep(3)
    ag.hs_logout('c2')
    time.sleep(3)
    pk = packets(ag.capture_stop(cap)['file'], t0, base)
    acc = [p for p in pk if p['code'] == 'Access-Accept']
    attrs = acc[0]['attrs'] if acc else []
    v = {'login': r['state'], 'user_name_in_accept': [v_ for k, v_ in attrs if k == 'User-Name'],
         'policy_attrs': sorted((k, v_) for k, v_ in attrs if k not in ('User-Name', 'Session-Timeout')),
         'session_timeout_present': any(k in ('Session-Timeout', '27') for k, _ in attrs),
         'accounting': [(p['status'], p['user_name']) for p in pk if p['code'] == 'Accounting-Request'], 'radacct_rows': len(radacct(base))}
    record('H1.policy_unchanged', {'verdict': v})
    unprovision(base)
    return v


def cleanup():
    try:
        fresh(*HS)
    except Exception:
        pass
    try:
        restore_profile()
    except Exception:
        pass
    g.cleanup_clients()


if __name__ == '__main__':
    a = sys.argv[1:] or ['state']
    if a[0] == 'site':
        print(json.dumps(site(a[1]), indent=1, default=str))
    elif a[0] == 'state':
        print(json.dumps(state(), indent=1, default=str))
    elif a[0] == 'run':
        MODE[0] = state()['site_mode']
        for c in a[1:]:
            try:
                v = CASES[c]()
                print(json.dumps({c: v}, default=str, ensure_ascii=False)[:3000], flush=True)
            except Exception as ex:
                record(f'ERROR.{c}', {'summary': repr(ex)[:500]})
                print(f'[h1] {c} ERROR {ex!r}', flush=True)
            finally:
                cleanup()
    else:
        print(__doc__)
        sys.exit(2)
