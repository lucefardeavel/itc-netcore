#!/usr/bin/env python3
"""WP8 A2-R IMPLEMENTATION GATES - lab tooling only (owner authorization 2026-10-09 "A2-R Final Implementation"). Never
touches install/, system/, production or AVAIA. Acts on the running WP8 lab (dev/wp8/lab.sh up [+ up-chr2]): the disposable
RADIUS database nuxr_radius (install/radius.sql + install/freeradius/nuxr_admission.sql), the lab FreeRADIUS container
running the PROJECT site (install/freeradius/sites-available/phpnuxbill), the lab CHR(s) 7.24.5 and the client namespaces.

  a2r_gate.py state
  a2r_gate.py threaded on|off   production thread pool (freeradius -fxx) instead of the single-threaded -X (restart)
  a2r_gate.py fault on|off      FAULT-INJECTION copy of the project site: SQL delays at two named points, active only for
                                NAS-Identifier "nuxr-a2r-fault-sleep-before-claim" (1.5 s before the claim) and
                                "nuxr-a2r-fault-creator-delay" (6 s after the claim); generated from the project site
                                at run time, never written to install/ (restart)
  a2r_gate.py pool N|off        AV-2: lab SQL pool max N instead of 8 (restart)
  a2r_gate.py chr2 setup        the second CHR as a production-contract NAS (nas row, SNMP, guard credentials; restart)
  a2r_gate.py run CASE...       run gate cases (see CASES); evidence -> $NUXR_WP8_STATE/evidence/a2r-impl.jsonl

Synthetic RADIUS requests are sent from INSIDE the FreeRADIUS network namespace (source 10.88.0.10 = the lab nas row 2,
"nuxr_wp8_app"), built byte by byte (exact retransmission = the same bytes). Secrets come from
$NUXR_WP8_STATE/secrets.env and are never printed; test passwords are random and never recorded."""
import hashlib, hmac, json, os, random, re, secrets, socket, struct, subprocess, sys, threading, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import checkrad_gate as cg
import client_agent as ag
from rosapi import Ros

STATE, FR, DB, R = cg.STATE, cg.FR, cg.DB, cg.R
HERE = os.path.dirname(os.path.abspath(__file__))
SEC = dict(l.strip().split('=', 1) for l in open(f'{STATE}/secrets.env') if '=' in l and not l.startswith('#'))
SECRET = SEC['NUXR_RADIUS_SECRET'].encode()
EVID = f'{STATE}/evidence/a2r-impl.jsonl'
SITE = f'{HERE}/../../install/freeradius/sites-available/phpnuxbill'
CHR2 = '10.88.0.3'
CHR = '10.88.0.2'
APP = '10.88.0.10'


# ------------------------------------------------------------------------------------------------ lab state
def sql(q):
    return cg.sql(q)


def sqlrows(q):
    return [l.split('\t') for l in sql(q).splitlines() if l != '']


def hexs(s):
    return (s.encode() if isinstance(s, str) else s).hex().upper()


def fr_ready_since(t):
    for _ in range(90):
        if 'Ready to process requests' in cg.sh(['docker', 'logs', '--since', t, FR], check=False).stdout:
            return True
        time.sleep(1)
    raise RuntimeError('FreeRADIUS not ready')


FAULT_A = '''		# ---- A2-R 5: late-claim guard BEFORE the claim'''
FAULT_B = '''		# ---- A2-R 7: late-claim guard AFTER the claim'''


def fault_site():
    """the project site + two lab-only SQL delays (gate cases only), inserted textually before two named steps"""
    s = open(SITE).read()
    assert s.count(FAULT_A) == 1 and s.count(FAULT_B) == 1
    ia = ('\t\t# LAB FAULT INJECTION (a2r_gate.py): hold the request 1.5 s before the claim\n\t\tif (&NAS-Identifier == "nuxr-a2r-fault-sleep-before-claim") {\n'
          '\t\t\tupdate request {\n\t\t\t\t&Tmp-String-0 := "%{sql:SELECT SLEEP(1.5)}"\n\t\t\t}\n\t\t}\n')
    ib = ('\t\t# LAB FAULT INJECTION (a2r_gate.py): the creator is held 6 s after its claim (> the 5000 ms budget -> late-claim release path)\n\t\tif (&NAS-Identifier == "nuxr-a2r-fault-creator-delay") {\n'
          '\t\t\tupdate request {\n\t\t\t\t&Tmp-String-1 := "%{sql:SELECT SLEEP(6)}"\n\t\t\t}\n\t\t}\n')
    return s.replace(FAULT_A, ia + FAULT_A).replace(FAULT_B, ib + FAULT_B)


def fault(flag):
    if flag == 'on':
        tmp = f'{STATE}/run/wp8-a2r-fault-site'
        os.makedirs(os.path.dirname(tmp), exist_ok=True)
        open(tmp, 'w').write(fault_site())
        cg.sh(['docker', 'cp', tmp, f'{FR}:{R}/wp8-a2r-fault-site'])
        cg.sh(['docker', 'exec', FR, 'touch', f'{R}/wp8-a2r-fault'])
        os.remove(tmp)
    else:
        cg.sh(['docker', 'exec', FR, 'rm', '-f', f'{R}/wp8-a2r-fault', f'{R}/wp8-a2r-fault-site'])
    cg.restart()
    return state()


def threaded(flag):
    cg.sh(['docker', 'exec', FR, 'sh', '-c', f'{"touch" if flag == "on" else "rm -f"} {R}/wp8-a2r-threaded'])
    cg.restart()
    return state()


def pool(n):
    """AV-2 (lab only): the lab SQL pool max for the next FreeRADIUS start ("off" = the lab default 8)"""
    cmd = f'rm -f {R}/wp8-a2r-pool' if n == 'off' else f'echo {int(n)} > {R}/wp8-a2r-pool'
    cg.sh(['docker', 'exec', FR, 'sh', '-c', cmd])
    cg.restart()
    lines = cg.sh(['docker', 'logs', '--tail', '200', FR], check=False)
    return {**state(), 'pool_line': [l for l in (lines.stdout + lines.stderr).splitlines() if 'AV-2' in l][-1:]}


def state():
    site = cg.sh(['docker', 'exec', FR, 'sh', '-c', f'sha256sum {R}/sites-enabled/phpnuxbill | cut -c1-16'], check=False).stdout.strip()
    proj = hashlib.sha256(open(SITE, 'rb').read()).hexdigest()[:16]
    flt = hashlib.sha256(fault_site().encode()).hexdigest()[:16]
    thr = cg.sh(['docker', 'exec', FR, 'sh', '-c', f'test -f {R}/wp8-a2r-threaded && echo 1 || echo 0'], check=False).stdout.strip()
    pid1 = cg.sh(['docker', 'exec', FR, 'sh', '-c', 'tr "\\0" " " < /proc/1/cmdline'], check=False).stdout.strip()
    chk = cg.sh(['docker', 'exec', '-i', DB, 'sh', '-c', 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -B nuxr_radius'],
                open(f'{HERE}/../../install/freeradius/nuxr_admission_check.sql').read(), check=False).stdout.strip()
    return {'threaded': thr == '1', 'freeradius_cmdline': pid1, 'site_sha16': site,
            'site_is': 'project' if site == proj else ('fault-injection' if site == flt else 'other'), 'readiness': chk,
            'nas': sqlrows('SELECT id, nasname, type FROM nas ORDER BY id')}


def record(case, data):
    os.makedirs(os.path.dirname(EVID), mode=0o700, exist_ok=True)
    line = json.dumps({'t': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'case': case, **data}, default=str)
    line = ag._redact(line)
    with open(EVID, 'a') as f:
        f.write(line + '\n')
    print(f'[a2r] {case}: ' + json.dumps(data.get('verdict', data.get('summary', '')), default=str)[:400], flush=True)


def provision(name, password, pool='nuxr_wp8_pool_dyn'):
    h = hexs(name)
    sql(f"DELETE FROM radcheck WHERE username = CONVERT(X'{h}' USING utf8mb4) COLLATE utf8mb4_bin; "
        f"DELETE FROM radreply WHERE username = CONVERT(X'{h}' USING utf8mb4) COLLATE utf8mb4_bin; "
        f"INSERT INTO radcheck (username, attribute, op, value) VALUES (CONVERT(X'{h}' USING utf8mb4), 'Cleartext-Password', ':=', '{password}'), "
        f"(CONVERT(X'{h}' USING utf8mb4), 'Simultaneous-Use', ':=', '1'); "
        f"INSERT INTO radreply (username, attribute, op, value) VALUES (CONVERT(X'{h}' USING utf8mb4), 'Framed-Pool', ':=', '{pool}')")


def unprovision(name):
    h = hexs(name)
    sql(f"DELETE FROM radcheck WHERE username = CONVERT(X'{h}' USING utf8mb4) COLLATE utf8mb4_bin; DELETE FROM radreply WHERE username = CONVERT(X'{h}' USING utf8mb4) COLLATE utf8mb4_bin")


def resv(name=None):
    """the reservation row(s): of the canonical key of $name (by radcheck lookup), or all"""
    cols = 'HEX(username), state, token, nas, acct_session_id, calling_station_id, nas_port_id, HEX(session_user), request_authenticator, attach_count, created_at, updated_at, confirmed_at, last_acct_at, ended_at, end_reason, prev_token, prev_state'
    names = ['username_hex', 'state', 'token', 'nas', 'acct_session_id', 'calling_station_id', 'nas_port_id', 'session_user_hex', 'request_authenticator', 'attach_count', 'created_at', 'updated_at', 'confirmed_at', 'last_acct_at', 'ended_at', 'end_reason', 'prev_token', 'prev_state']
    w = '' if name is None else f"WHERE username = (SELECT username FROM radcheck WHERE username = CONVERT(X'{hexs(name)}' USING utf8mb4) ORDER BY CAST(username AS BINARY) LIMIT 1)"
    out = []
    for r in sqlrows(f'SELECT {cols} FROM nuxr_admission {w}'):
        d = dict(zip(names, r))
        for k, v in d.items():
            if v == 'NULL':
                d[k] = None
        out.append(d)
    return out


def utc_db():
    return sql('SELECT UTC_TIMESTAMP(6)').strip()


# ------------------------------------------------------------------------------------------------ RADIUS (raw)
ATTR = {'User-Name': 1, 'User-Password': 2, 'CHAP-Password': 3, 'NAS-IP-Address': 4, 'NAS-Port': 5, 'Service-Type': 6, 'Framed-Protocol': 7,
        'Framed-IP-Address': 8, 'Reply-Message': 18, 'Class': 25, 'Called-Station-Id': 30, 'Calling-Station-Id': 31, 'NAS-Identifier': 32,
        'Acct-Status-Type': 40, 'Acct-Delay-Time': 41, 'Acct-Input-Octets': 42, 'Acct-Output-Octets': 43, 'Acct-Session-Id': 44,
        'Acct-Session-Time': 46, 'Acct-Terminate-Cause': 49, 'Event-Timestamp': 55, 'CHAP-Challenge': 60, 'NAS-Port-Type': 61,
        'Message-Authenticator': 80, 'NAS-Port-Id': 87, 'Framed-Pool': 88}
NAME = {v: k for k, v in ATTR.items()}
INTS = {5, 6, 7, 40, 41, 42, 43, 46, 49, 55, 61}
IPS = {4, 8}
CODES = {1: 'Access-Request', 2: 'Access-Accept', 3: 'Access-Reject', 4: 'Accounting-Request', 5: 'Accounting-Response'}
STATUS = {1: 'Start', 2: 'Stop', 3: 'Interim-Update', 7: 'Accounting-On', 8: 'Accounting-Off'}
PPPOE = [('Service-Type', 2), ('Framed-Protocol', 1), ('NAS-Port-Type', 15)]


def _enc(name, v):
    t = ATTR[name]
    b = struct.pack('!I', v) if t in INTS else socket.inet_aton(v) if t in IPS else v if isinstance(v, bytes) else v.encode()
    assert len(b) <= 253
    return bytes([t, len(b) + 2]) + b


def _decode_attrs(a):
    out, i = [], 0
    while i + 2 <= len(a):
        t, l = a[i], a[i + 1]
        if l < 2:
            break
        v = a[i + 2:i + l]
        i += l
        if t in INTS and len(v) == 4:
            val = struct.unpack('!I', v)[0]
            if t == 40:
                val = STATUS.get(val, val)
        elif t in IPS and len(v) == 4:
            val = socket.inet_ntoa(v)
        elif t in (1, 18, 30, 31, 32, 44, 87, 88):
            val = v.decode('utf-8', 'replace')
        elif t in (2, 3):
            val = '[hidden]'
        else:
            val = v.hex()
        out.append((NAME.get(t, str(t)), val))
    return out


def _pap(password, auth):
    p = password.encode()
    p += b'\0' * (-len(p) % 16 or (16 if not p else 0))
    out, prev = b'', auth
    for i in range(0, len(p), 16):
        x = bytes(a ^ b for a, b in zip(p[i:i + 16], hashlib.md5(SECRET + prev).digest()))
        out += x
        prev = x
    return out


def access_request(attrs, password, chap=False, pid=None, auth=None):
    pid = random.randrange(256) if pid is None else pid
    auth = auth or os.urandom(16)
    body = b''.join(_enc(n, v) for n, v in attrs)
    if chap:
        ch, cid = os.urandom(16), random.randrange(256)
        body += _enc('CHAP-Password', bytes([cid]) + hashlib.md5(bytes([cid]) + password.encode() + ch).digest()) + _enc('CHAP-Challenge', ch)
    else:
        body += _enc('User-Password', _pap(password, auth))
    ln = 20 + len(body) + 18
    head = struct.pack('!BBH', 1, pid, ln)
    mac = hmac.new(SECRET, head + auth + body + bytes([80, 18]) + b'\0' * 16, 'md5').digest()
    return head + auth + body + bytes([80, 18]) + mac


def accounting_request(attrs, pid=None):
    pid = random.randrange(256) if pid is None else pid
    body = b''.join(_enc(n, v) for n, v in attrs)
    head = struct.pack('!BBH', 4, pid, 20 + len(body))
    return head + hashlib.md5(head + b'\0' * 16 + body + SECRET).digest() + body


def ns_socket():
    """UDP socket created inside the FreeRADIUS container network namespace (bound to 10.88.0.10)"""
    pid = int(cg.sh(['docker', 'inspect', '-f', '{{.State.Pid}}', FR]).stdout.strip())
    me, tgt = os.open('/proc/thread-self/ns/net', os.O_RDONLY), os.open(f'/proc/{pid}/ns/net', os.O_RDONLY)
    try:
        os.setns(tgt, os.CLONE_NEWNET)
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.bind((APP, 0))
    finally:
        os.setns(me, os.CLONE_NEWNET)
        os.close(me)
        os.close(tgt)
    return s


def exchange(pkt, port=1812, timeout=6.0, sock=None):
    """send one packet, wait for the verified reply; returns dict (reply None on timeout)"""
    s = sock or ns_socket()
    s.settimeout(timeout)
    t0 = time.time()
    s.sendto(pkt, (APP, port))
    reply = None
    try:
        while True:
            raw, _ = s.recvfrom(4096)
            if raw[1] != pkt[1]:
                continue
            ln = struct.unpack('!H', raw[2:4])[0]
            raw = raw[:ln]
            if hashlib.md5(raw[:4] + pkt[4:20] + raw[20:] + SECRET).digest() != raw[4:20]:
                continue
            reply = {'code': CODES.get(raw[0], raw[0]), 'attrs': _decode_attrs(raw[20:]), 'raw_md5': hashlib.md5(raw).hexdigest()}
            break
    except socket.timeout:
        pass
    res = {'ms': round((time.time() - t0) * 1000, 1), 'src_port': s.getsockname()[1], 'id': pkt[1], 'reply': reply}
    if sock is None:
        s.close()
    return res


def reply_class(res):
    rep = res.get('reply') or {}
    return next((v for k, v in rep.get('attrs', []) if k == 'Class'), None)


def token_of(cls_hex):
    return cls_hex[10:] if cls_hex and cls_hex.startswith('6e75787231') else None


class Attempt:
    """one synthetic PPPoE attempt identity on NAS-IP-Address $nas"""
    def __init__(self, user, password, nas=APP, asid=None, mac=None, port='ether3', nas_id=None):
        self.user, self.password, self.nas = user, password, nas
        self.asid = asid or ('8f%06x' % random.randrange(1 << 24))
        self.mac = mac or ('02:a2:%02X:%02X:%02X:%02X' % tuple(os.urandom(4)))
        self.port, self.nas_id = port, nas_id

    def attrs(self, extra=()):
        a = [('User-Name', self.user), ('NAS-IP-Address', self.nas), ('NAS-Port', 15728640), *PPPOE,
             ('Acct-Session-Id', self.asid), ('Calling-Station-Id', self.mac), ('Called-Station-Id', 'nuxr_wp8'), ('NAS-Port-Id', self.port)]
        if self.nas_id:
            a.append(('NAS-Identifier', self.nas_id))
        return a + list(extra)

    def access(self, chap=True, drop=(), **kw):
        attrs = [x for x in self.attrs() if x[0] not in drop]
        return exchange(access_request(attrs, self.password, chap=chap), **kw)

    def acct(self, status, cls_hex=None, nas=None, delay=0, extra=()):
        a = [('User-Name', self.user), ('NAS-IP-Address', nas or self.nas), ('Acct-Status-Type', {'Start': 1, 'Stop': 2, 'Interim-Update': 3}[status]),
             ('Acct-Session-Id', self.asid), ('Calling-Station-Id', self.mac), ('NAS-Port-Id', self.port), ('NAS-Port-Type', 15),
             ('Framed-IP-Address', '10.99.0.%d' % random.randrange(2, 250)), ('Acct-Delay-Time', delay), ('Event-Timestamp', int(time.time()))]
        if status == 'Stop':
            a += [('Acct-Session-Time', 5), ('Acct-Terminate-Cause', 1)]
        if cls_hex:
            a.append(('Class', bytes.fromhex(cls_hex)))
        return exchange(accounting_request(a + list(extra)), port=1813)


def fr_trace(since, rid, port):
    """FreeRADIUS -X lines of the request received with Id $rid from 10.88.0.10:$port (a2r tags + reply)"""
    lines = cg.sh(['docker', 'logs', '--since', since, FR], check=False)
    lines = (lines.stdout + lines.stderr).splitlines()
    n, out = None, []
    for l in lines:
        m = re.match(r'^\((\d+)\) Received (Access|Accounting)-Request Id (\d+) from ([\d.]+):(\d+)', l)
        if m:
            if n is not None and m.group(1) == n:
                break
            if int(m.group(3)) == rid and int(m.group(5)) == port:
                n = m.group(1)
            continue
        if n is not None and l.startswith(f'({n})') and re.search(r'nuxr-admission|Sent Access-|Sent Accounting-|Tmp-String-8 :=|Tmp-String-7 = |duplicate|Ignoring|ERROR|SQL query failed|rlm_sql', l):
            out.append(ag._redact(l)[:300])
    return out


def since():
    return time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime(time.time() - 1))


def new_user(tag):
    u = f'nuxr_a2r_{tag}_{secrets.token_hex(3)}'
    pw = 'A2r-' + secrets.token_hex(6)
    provision(u, pw)
    return u, pw


# ------------------------------------------------------------------------------------------------ pcap decoding
def pcap_packets(fname):
    data = open(f'{STATE}/pcap/{fname}', 'rb').read()
    off, out = 24, []
    while off + 16 <= len(data):
        ts, us, incl, _ = struct.unpack('<IIII', data[off:off + 16])
        pkt = data[off + 16:off + 16 + incl]
        off += 16 + incl
        if len(pkt) < 42 or struct.unpack('!H', pkt[12:14])[0] != 0x0800 or pkt[23] != 17:
            continue
        ihl = (pkt[14] & 0x0f) * 4
        udp = pkt[14 + ihl:]
        sport, dport = struct.unpack('!HH', udp[:4])
        r = udp[8:]
        if len(r) < 20:
            continue
        ln = struct.unpack('!H', r[2:4])[0]
        out.append({'t_us': ts * 1_000_000 + us, 'src': '.'.join(map(str, pkt[26:30])) + f':{sport}', 'dst': '.'.join(map(str, pkt[30:34])) + f':{dport}',
                    'code': CODES.get(r[0], r[0]), 'id': r[1], 'auth': r[4:20].hex(), 'attrs': [x for x in _decode_attrs(r[20:ln]) if x[0] not in ('Message-Authenticator',)],
                    'raw': r[:ln].hex()})
    return out


def brief(p, t0):
    a = dict(p['attrs'])
    keep = {k: a[k] for k in ('User-Name', 'Acct-Status-Type', 'Acct-Session-Id', 'Calling-Station-Id', 'NAS-Port-Id', 'Class', 'Acct-Delay-Time',
                              'CHAP-Challenge', 'Reply-Message', 'Acct-Terminate-Cause', 'Framed-IP-Address') if k in a}
    return {'t_ms': round((p['t_us'] - t0) / 1000, 1), 'code': p['code'], 'id': p['id'], 'src': p['src'], 'auth16': p['auth'][:16], **keep}


# ------------------------------------------------------------------------------------------------ CHR helpers
def ros():
    return ag.ros()


def ros_add(*words):
    r = ros()
    try:
        res = r.talk(list(words))
        traps = [a for k, a in res if k == '!trap']
        if traps:
            raise RuntimeError(traps)
        return [a for k, a in res if k == '!done'][0].get('ret')
    finally:
        r.close()


def ros_rm(path, rid):
    r = ros()
    try:
        r.talk([path, '=.id=' + rid])
    finally:
        r.close()


def block_acct():
    return ros_add('/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_a2r')


def block_auth_replies():
    return ros_add('/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=src-port=1812', '=src-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_a2r')


def unblock(rid):
    ros_rm('/ip/firewall/filter/remove', rid)


def ros2():
    return Ros('127.0.0.1', 18729).login('admin', SEC['NUXR_WP8_CHR_PASSWORD'])


def ppp_active(user=None, nas=1):
    r = ros() if nas == 1 else ros2()
    try:
        return [{k: a.get(k) for k in ('name', 'session-id', 'caller-id', 'address', 'uptime')} for k, a in r.talk(['/ppp/active/print'] + (['?name=' + user] if user else [])) if k == '!re']
    finally:
        r.close()


def until(fn, seconds, step=1.0):
    t0 = time.time()
    while time.time() - t0 < seconds:
        v = fn()
        if v:
            return v
        time.sleep(step)
    return None


def cleanup_clients():
    """every case ends here: clients down, lab firewall rules removed, containers unpaused, guard program restored"""
    for c in ('c1', 'c3', 'c6'):
        if c in ag.CLIENTS and os.path.exists(f'/run/netns/{ag.CLIENTS[c][0]}'):
            ag.pppoe_down(c)
    for c in ('c2', 'c4'):
        try:
            ag.hs_logout(c)
        except Exception:
            pass
    for name in (FR, DB):
        cg.sh(['docker', 'unpause', name], check=False)
    cg.sh(['docker', 'exec', FR, 'chmod', '0755', f'{R}/nuxr/nuxr_pppoe_session_guard'], check=False)
    cg.sh(['docker', 'exec', FR, 'sh', '-c', f'G={R}/nuxr/nuxr_pppoe_session_guard; [ -f $G.orig ] && mv -f $G.orig $G; chmod 0755 $G'], check=False)
    for get in (ros, ros2):
        try:
            r = get()
        except Exception:
            continue
        try:
            for x in r.rows(['/ip/firewall/filter/print']):
                if x.get('comment') == 'nuxr_wp8_a2r':
                    r.talk(['/ip/firewall/filter/remove', '=.id=' + x['.id']])
        finally:
            r.close()


CASES = {}


def case(fn):
    CASES[fn.__name__] = fn
    return fn


TOKEN_PREFIX = '6e75787231'          # "nuxr1"


def cls_of(tok):
    return TOKEN_PREFIX + tok if tok else None


def pk_of(name, t0):
    """decoded packets of a capture, relative times"""
    return [brief(p, int(t0 * 1e6)) for p in pcap_packets(name)]


def tags_since(t, extra=''):
    lg = cg.sh(['docker', 'logs', '--since', t, FR], check=False)
    pat = r'nuxr-admission[^"]*"?|Sent Access-\w+' + (('|' + extra) if extra else '')
    return [re.sub(r'^\(\d+\)\s+', '', l).strip()[:170] for l in (lg.stdout + lg.stderr).splitlines() if re.search(pat, l) and ('EXPAND' not in l) and ('-->' not in l)]


# ================================================================================================ G-CLASS (real CHR)
@case
def class_basic():
    """Access-Accept Class -> Start, Interim, Stop on the real NAS; reservation PENDING -> ACTIVE -> ENDED"""
    u, pw = new_user('cls')
    cap = 'a2r-class-basic'
    ag.capture_start(cap)
    t0 = time.time()
    up = ag.pppoe_up('c1', u, pw)
    act = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    first = act[0]['last_acct_at'] if act else None
    interim = until(lambda: [r for r in resv(u) if r['last_acct_at'] and r['last_acct_at'] != first], 100, 2)
    live = ppp_active(u)
    ag.pppoe_down('c1')
    ended = until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 20, 0.5)
    pkts = pk_of(ag.capture_stop(cap)['file'], t0)
    acc = [p for p in pkts if p['code'] == 'Access-Accept']
    cls = acc[0].get('Class') if acc else None
    by = {}
    for p in pkts:
        if p['code'] == 'Accounting-Request' and p['src'].startswith(CHR):
            by.setdefault(p.get('Acct-Status-Type'), []).append(p.get('Class'))
    v = {'login_ok': up['ok'], 'accept_class': cls, 'class_bytes': len(cls) // 2 if cls else 0,
         'start_class_equal': by.get('Start') == [cls], 'interim_class_equal': bool(by.get('Interim-Update')) and set(by['Interim-Update']) == {cls},
         'stop_class_equal': by.get('Stop') == [cls], 'active_by_token': bool(act) and cls_of(act[0]['token']) == cls, 'interim_refreshed': bool(interim),
         'ended_by_stop': bool(ended) and ended[0]['end_reason'] == 'acct_stop', 'nas_sessions': len(live)}
    v['pass'] = cls is not None and all(v[k] for k in ('login_ok', 'start_class_equal', 'interim_class_equal', 'stop_class_equal', 'active_by_token', 'interim_refreshed', 'ended_by_stop'))
    record('G-CLASS.basic', {'verdict': v, 'user': u, 'packets': pkts})
    unprovision(u)
    return v


@case
def class_lost_start():
    """Start lost: PENDING; a different attempt refused; restored path: the first Interim (same Class) confirms"""
    u, pw = new_user('lst')
    cap = 'a2r-lost-start'
    ag.capture_start(cap)
    t0 = time.time()
    rule = block_acct()
    up = ag.pppoe_up('c1', u, pw)
    pend = resv(u)
    second = ag.pppoe_up('c3', u, pw, wait=20)
    live = ppp_active(u)
    time.sleep(8)
    unblock(rule)
    tu = time.time()
    act = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 100, 1)
    ta = time.time()
    ag.pppoe_down('c1')
    ended = until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 20, 0.5)
    pkts = pk_of(ag.capture_stop(cap)['file'], t0)
    acc = [p for p in pkts if p['code'] == 'Access-Accept']
    cls = acc[0].get('Class') if acc else None
    acct = [p for p in pkts if p['code'] == 'Accounting-Request' and p['src'].startswith(CHR)]
    v = {'first_login_ok': up['ok'], 'pending_while_start_lost': [r['state'] for r in pend] == ['PENDING'],
         'second_attempt_refused': not second['ok'] and len(acc) == 1, 'nas_sessions_while_pending': len(live),
         'confirmed_after_restore_s': round(ta - tu, 1) if act else None,
         'packets_after_restore': [(p.get('Acct-Status-Type'), p.get('Class') == cls) for p in acct if p['t_ms'] >= (tu - t0) * 1000],
         'all_accounting_class_equal': bool(acct) and all(p.get('Class') == cls for p in acct), 'ended': bool(ended)}
    v['pass'] = v['first_login_ok'] and v['pending_while_start_lost'] and v['second_attempt_refused'] and v['nas_sessions_while_pending'] == 1 and bool(act) and v['all_accounting_class_equal'] and v['ended']
    record('G-CLASS.lost_start', {'verdict': v, 'user': u, 'packets': pkts})
    unprovision(u)
    return v


@case
def class_stop_without_start():
    u, pw = new_user('sws')
    cap = 'a2r-stop-no-start'
    ag.capture_start(cap)
    t0 = time.time()
    rule = block_acct()
    up = ag.pppoe_up('c1', u, pw)
    pend = resv(u)
    time.sleep(12)
    unblock(rule)
    tu = time.time()
    time.sleep(3)
    mid = resv(u)
    ag.pppoe_down('c1')
    ended = until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 20, 0.5)
    pkts = pk_of(ag.capture_stop(cap)['file'], t0)
    cls = next((p.get('Class') for p in pkts if p['code'] == 'Access-Accept'), None)
    after = [(p.get('Acct-Status-Type'), p.get('Class') == cls) for p in pkts if p['code'] == 'Accounting-Request' and p['t_ms'] >= (tu - t0) * 1000]
    v = {'login_ok': up['ok'], 'pending': [r['state'] for r in pend], 'before_stop': [r['state'] for r in mid], 'after_restore': after,
         'ended_by_stop': bool(ended) and ended[0]['end_reason'] == 'acct_stop' and ended[0]['confirmed_at'] is None,
         'radacct': sqlrows(f"SELECT acctsessionid, acctstoptime IS NOT NULL, acctterminatecause FROM radacct WHERE username = '{u}'")}
    v['pass'] = up['ok'] and v['pending'] == ['PENDING'] and v['before_stop'] == ['PENDING'] and v['ended_by_stop']
    record('G-CLASS.stop_without_start', {'verdict': v, 'user': u, 'packets': pkts})
    unprovision(u)
    return v


@case
def class_acct_retransmit():
    """FreeRADIUS paused while the Stop is sent: RouterOS retransmits it exactly; the claim ends once"""
    u, pw = new_user('art')
    cap = 'a2r-acct-retx'
    ag.capture_start(cap)
    t0 = time.time()
    up = ag.pppoe_up('c1', u, pw)
    act = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    ag.fr('pause')
    try:
        ag.pppoe_down('c1')
        time.sleep(6)
    finally:
        ag.fr('unpause')
    ended = until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 30, 0.5)
    time.sleep(6)
    pkts = pk_of(ag.capture_stop(cap)['file'], t0)
    cls = next((p.get('Class') for p in pkts if p['code'] == 'Access-Accept'), None)
    stops = [p for p in pkts if p['code'] == 'Accounting-Request' and p.get('Acct-Status-Type') == 'Stop']
    v = {'login_ok': up['ok'], 'active_before': bool(act), 'stop_packets': len(stops), 'stop_id_auth': sorted({(p['id'], p['auth16']) for p in stops}),
         'stop_class_equal': bool(stops) and all(p.get('Class') == cls for p in stops), 'ended_once': bool(ended) and ended[0]['end_reason'] == 'acct_stop',
         'radacct': sqlrows(f"SELECT COUNT(*), SUM(acctstoptime IS NULL) FROM radacct WHERE username = '{u}'")}
    v['pass'] = up['ok'] and v['stop_class_equal'] and v['ended_once'] and len(v['stop_id_auth']) == 1
    record('G-CLASS.acct_retransmission', {'verdict': v, 'user': u, 'packets': pkts})
    unprovision(u)
    return v


@case
def class_sequential_tokens():
    u, pw = new_user('seq')
    cap = 'a2r-seq'
    ag.capture_start(cap)
    t0 = time.time()
    a = ag.pppoe_up('c1', u, pw)
    r1 = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    ag.pppoe_down('c1')
    until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 20, 0.5)
    b = ag.pppoe_up('c1', u, pw)
    r2 = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    ag.pppoe_down('c1')
    e2 = until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 20, 0.5)
    pkts = pk_of(ag.capture_stop(cap)['file'], t0)
    toks = [token_of(p.get('Class')) for p in pkts if p['code'] == 'Access-Accept']
    reqs = [p for p in pkts if p['code'] == 'Access-Request']
    v = {'logins_ok': [a['ok'], b['ok']], 'tokens': toks, 'asids': [p.get('Acct-Session-Id') for p in reqs],
         'authenticators_distinct': len({p['auth16'] for p in reqs}) == len(reqs),
         'second_took_over_ended_first': bool(r1) and bool(r2) and r2[0]['prev_state'] == 'ENDED' and r2[0]['prev_token'] == r1[0]['token'], 'ended': bool(e2)}
    v['pass'] = all(v['logins_ok']) and len(set(toks)) == 2 and None not in toks and v['second_took_over_ended_first'] and v['ended']
    record('G-CLASS.sequential_tokens', {'verdict': v, 'user': u, 'packets': pkts})
    unprovision(u)
    return v


@case
def lost_stop_takeover():
    """A1 lost-Stop semantics: an ACTIVE claim whose Stop was lost is taken over only after SNMP proves it gone"""
    u, pw = new_user('lsp')
    a = ag.pppoe_up('c1', u, pw)
    act = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    rule = block_acct()
    ag.pppoe_down('c1')
    gone = until(lambda: ppp_active(u) == [], 20, 0.5) is not None
    time.sleep(6)
    unblock(rule)
    stale = resv(u)
    t = since()
    b = ag.pppoe_up('c3', u, pw)
    now = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE' and act and r['token'] != act[0]['token']], 10, 0.2)
    tags = tags_since(t, r'Tmp-String-7 = |Tmp-String-8 :=')
    ag.pppoe_down('c3')
    v = {'first_ok': a['ok'], 'stale_claim': [r['state'] for r in stale], 'old_session_gone': gone, 'relogin_ok': b['ok'],
         'taken_over_from_active': bool(now) and now[0]['prev_state'] == 'ACTIVE', 'fr': tags[-5:]}
    v['pass'] = a['ok'] and v['stale_claim'] == ['ACTIVE'] and b['ok'] and v['taken_over_from_active']
    record('G-LIFECYCLE.lost_stop_takeover', {'verdict': v, 'user': u})
    unprovision(u)
    return v


# ================================================================================================ G-RETX (real CHR)
@case
def retx_real_replay():
    """a REAL RouterOS Access-Request (captured) replayed byte-exact = exact retransmission -> re-uses the PENDING claim
    and its token; the same RouterOS identity with a new Request Authenticator (what a re-created NAS that re-uses the
    Acct-Session-Id would send) -> refused"""
    u, pw = new_user('rpl')
    cap = 'a2r-replay'
    ag.capture_start(cap)
    t0 = time.time()
    rule = block_acct()                         # the Start is lost: the claim stays PENDING
    up = ag.pppoe_up('c1', u, pw)
    pkts = pcap_packets(ag.capture_stop(cap)['file'])
    req = [p for p in pkts if p['code'] == 'Access-Request' and p['src'].startswith(CHR)]
    claim0 = resv(u)
    raw = bytes.fromhex(req[0]['raw'])
    rep = exchange(raw)                         # same bytes, new source (10.88.0.10): not a FreeRADIUS duplicate
    claim1 = resv(u)
    a = dict(req[0]['attrs'])
    fake = Attempt(u, pw, nas=a['NAS-IP-Address'], asid=a['Acct-Session-Id'], mac=a['Calling-Station-Id'], port=a['NAS-Port-Id'])
    new = fake.access(chap=True)
    claim2 = resv(u)
    unblock(rule)
    ag.pppoe_down('c1')
    v = {'login_ok': up['ok'], 'claim': [(c['state'], c['acct_session_id'], c['attach_count']) for c in claim0],
         'replay_reply': rep['reply'] and rep['reply']['code'], 'replay_same_token': bool(claim0) and token_of(reply_class(rep)) == claim0[0]['token'],
         'replay_attach_count': claim1[0]['attach_count'] if claim1 else None,
         'same_identity_new_authenticator': new['reply'] and new['reply']['code'], 'claim_after': [(c['state'], c['token'] == claim0[0]['token'], c['attach_count']) for c in claim2]}
    v['pass'] = up['ok'] and v['replay_reply'] == 'Access-Accept' and v['replay_same_token'] and v['replay_attach_count'] == '1' and v['same_identity_new_authenticator'] == 'Access-Reject'
    record('G-RETX.real_replay', {'verdict': v, 'user': u})
    unprovision(u)
    return v


@case
def nas_deadline():
    """how long RouterOS 7.24.5 (/radius timeout=3s) waits for an Access-Accept: FreeRADIUS replies are dropped at the NAS
    input for X seconds after the first Access-Request; X=4.0 (the 3rd transmission at ~6 s gets the cached Accept -> UP)
    and X=7.0 (all 3 transmissions unanswered -> abandoned, the claim stays PENDING = uncertain)"""
    out = {}
    for x in (4.0, 7.0):
        u, pw = new_user('dl')
        cap = f'a2r-deadline-{int(x)}s'
        ag.capture_start(cap)
        t0 = time.time()
        rule = block_auth_replies()
        ag.pppoe_start('c1', u, pw)
        # X counts from the FIRST Access-Request on the wire (PPPoE discovery takes 1-3 s before it)
        first = until(lambda: next((p['t_us'] for p in pcap_packets_live(cap) if p['code'] == 'Access-Request'), None), 15, 0.05)
        time.sleep(max(0.0, x - (time.time() - (first or t0 * 1e6) / 1e6)))
        unblock(rule)
        st = until(lambda: ag.pppoe_state('c1')['ip'] or (not ag.pppoe_state('c1')['running'] and 'down'), 25, 0.5)
        pkts = pk_of(ag.capture_stop(cap)['file'], t0)
        req = [p for p in pkts if p['code'] == 'Access-Request']
        out[f'blocked_{x}s'] = {'session': 'UP' if st and st != 'down' else 'FAILED',
                                 'access_requests_ms': [round(p['t_ms'] - req[0]['t_ms']) for p in req],
                                 'same_id_auth': len({(p['id'], p['auth16']) for p in req}) == 1, 'claim': [(c['state'], c['attach_count']) for c in resv(u)],
                                 'pppd': ag.pppoe_state('c1')['log'][-3:]}
        ag.pppoe_down('c1')
        until(lambda: [c for c in resv(u) if c['state'] != 'ACTIVE'], 15, 0.5)
        unprovision(u)
    v = {**out, 'pass': out['blocked_4.0s']['session'] == 'UP' and out['blocked_7.0s']['session'] == 'FAILED'}
    record('G-LATE.nas_deadline', {'verdict': v})
    return v


@case
def orphan_reboot_runbook():
    """one lost Access-Accept leaves an uncertain claim (PENDING) - refused logins, also after a NAS reboot (no
    automatic release); the operator procedure releases it on NAS-side proof (reboot after created_at) with an audit row"""
    u, pw = new_user('orb')
    cap = 'a2r-orphan-runbook'
    ag.capture_start(cap, bpf='udp port 1812 or udp port 1813')
    t0 = time.time()
    rule = block_auth_replies()
    o = ag.pppoe_up('c1', u, pw, wait=25)
    unblock(rule)
    orphan = resv(u)
    r1 = ag.pppoe_up('c3', u, pw, wait=20)
    nas_log = [a.get('message') for k, a in ros().talk(['/log/print']) if k == '!re' and u in (a.get('message') or '')]
    ag.pppoe_down('c1')
    ag.pppoe_down('c3')
    tb = time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime())
    b = reboot()
    r2 = ag.pppoe_up('c1', u, pw, wait=20)
    after_reboot = resv(u)
    ag.pppoe_down('c1')
    # operator procedure (README): proof = NAS reboot after created_at (+ Accounting-On seen in the capture)
    pkts = pk_of(ag.capture_stop(cap)['file'], t0)
    acct_on = [p['t_ms'] for p in pkts if p.get('Acct-Status-Type') == 'Accounting-On']
    canon = sqlrows(f"SELECT username FROM radcheck WHERE username = '{u}' LIMIT 1")[0][0]
    ev = f'CHR {CHR} rebooted at ~{tb}Z after claim created_at {orphan[0]["created_at"]}; Accounting-On seen; NAS log: {nas_log[-1] if nas_log else "-"}'
    script = (f"SET @nuxr_username = '{canon}'; SET @nuxr_token = '{orphan[0]['token']}'; SET @nuxr_operator = 'wp8-lab-operator'; "
              f"SET @nuxr_reason = 'A2-R gate: Access-Accept lost, subscriber locked out'; SET @nuxr_evidence = '{ev.replace(chr(39), '')}';\n"
              + open(f'{HERE}/../../install/freeradius/nuxr_admission_release.sql').read())
    rel = sql(script).strip().splitlines()[-1:]
    r3 = ag.pppoe_up('c1', u, pw)
    final = until(lambda: [c for c in resv(u) if c['state'] == 'ACTIVE'], 10, 0.3)
    audit = sqlrows(f"SELECT outcome, operator, LEFT(evidence, 60) FROM nuxr_admission_audit WHERE username = '{canon}'")
    ag.pppoe_down('c1')
    v = {'orphan_login_ok': o['ok'], 'orphan_claim': [(c['state'], c['acct_session_id'], c['attach_count']) for c in orphan], 'nas_log': nas_log[-2:],
         'refused_before_reboot': not r1['ok'], 'reboot_s': b, 'accounting_on_ms': acct_on,
         'refused_after_reboot_no_auto_release': not r2['ok'] and [c['state'] for c in after_reboot] == ['PENDING'],
         'operator_release': rel, 'audit': audit, 'login_after_release': r3['ok'], 'new_claim_active': bool(final)}
    v['pass'] = (not o['ok']) and v['refused_before_reboot'] and v['refused_after_reboot_no_auto_release'] and rel == ['nuxr_admission_release=RELEASED'] and r3['ok'] and bool(final)
    record('G-RECOVERY.orphan_reboot_runbook', {'verdict': v, 'user': u})
    unprovision(u)
    return v


def reboot():
    t0 = time.time()
    try:
        r = ros()
        r.s.settimeout(5)
        r.talk(['/system/reboot'])
    except Exception:
        pass
    time.sleep(15)
    if not until(lambda: _ros_ok(), 300, 3):
        raise RuntimeError('CHR did not come back')
    time.sleep(8)
    return round(time.time() - t0, 1)


def _ros_ok():
    try:
        r = ros()
        r.close()
        return True
    except Exception:
        return False


# ================================================================================================ S-1 / Hotspot (real)
@case
def s1_real_spellings():
    """S-1 closure on the real NAS: the same subscriber from two clients under two equivalent spellings"""
    out = {}
    for first, second in (('upper', 'lower'), ('accent', 'plain')):
        base = 'nuxr_a2r_s1r_cafe_' + secrets.token_hex(2)
        pw = 'A2r-' + secrets.token_hex(6)
        provision(base, pw)
        sp = {'upper': base.upper(), 'lower': base, 'accent': base.replace('cafe', 'café'), 'plain': base}
        t = since()
        a = ag.pppoe_up('c1', sp[first], pw)
        time.sleep(2)
        b = ag.pppoe_up('c3', sp[second], pw, wait=20)
        time.sleep(3)
        live = [s for s in ppp_active() if (s['name'] or '').lower().replace('é', 'e') == base]
        out[f'{first}_then_{second}'] = {'first_ok': a['ok'], 'second_ok': b['ok'], 'live_sessions': len(live), 'fr': tags_since(t)[-4:]}
        ag.pppoe_down('c1')
        ag.pppoe_down('c3')
        time.sleep(3)
        unprovision(base)
    v = {**out, 'pass': all(x['first_ok'] and not x['second_ok'] and x['live_sessions'] == 1 for x in out.values())}
    record('S-1.real_spellings', {'verdict': v})
    return v


def provision_hotspot(name, password, shared=1):
    h = hexs(name)
    sql(f"INSERT INTO radcheck (username, attribute, op, value) VALUES (CONVERT(X'{h}' USING utf8mb4), 'Cleartext-Password', ':=', '{password}'), "
        f"(CONVERT(X'{h}' USING utf8mb4), 'Simultaneous-Use', ':=', '{shared}'); "
        f"INSERT INTO radreply (username, attribute, op, value) VALUES (CONVERT(X'{h}' USING utf8mb4), 'Port-Limit', ':=', '{shared}')")


@case
def hotspot_spellings():
    """Hotspot assessment (no Hotspot change): Port-Limit 1, the same subscriber from two Hotspot clients under the same
    spelling (control) and under two equivalent spellings - how many live Hotspot sessions does RouterOS allow"""
    out = {}
    for label, s1, s2 in (('same_spelling', lambda b: b, lambda b: b), ('upper_vs_lower', lambda b: b.upper(), lambda b: b),
                          ('accent_vs_plain', lambda b: b.replace('cafe', 'café'), lambda b: b)):
        base = 'nuxr_a2r_hs_cafe_' + secrets.token_hex(2)
        pw = 'A2r-' + secrets.token_hex(6)
        provision_hotspot(base, pw, 1)
        for c in ('c2', 'c4'):
            ag.hs_logout(c)
            ag.hs_dhcp(c)
        a = ag.hs_login('c2', s1(base), pw)
        time.sleep(2)
        b = ag.hs_login('c4', s2(base), pw)
        time.sleep(3)
        r = ros()
        try:
            live = [{k: x.get(k) for k in ('user', 'address', 'mac-address')} for k2, x in r.talk(['/ip/hotspot/active/print']) if k2 == '!re' and (x.get('user') or '').lower().replace('é', 'e') == base]
        finally:
            r.close()
        out[label] = {'first': a.get('state'), 'second': b.get('state'), 'second_message': b.get('message'), 'live_hotspot_sessions': len(live), 'live': live}
        for c in ('c2', 'c4'):
            ag.hs_logout(c)
        time.sleep(2)
        unprovision(base)
    v = {**out, 'bypass_proven': any(x['live_hotspot_sessions'] >= 2 for k, x in out.items() if k != 'same_spelling'),
         'control_enforced': out['same_spelling']['live_hotspot_sessions'] == 1}
    record('HOTSPOT.username_variants', {'verdict': v})
    return v


# ================================================================================================ failures (real)
@case
def fr_restart_pending():
    """FreeRADIUS restarted while a claim is PENDING (Start lost): the claim survives; a second attempt is refused; the
    first Interim confirms it"""
    u, pw = new_user('frr')
    rule = block_acct()
    a = ag.pppoe_up('c1', u, pw)
    before = resv(u)
    cg.restart()
    after = resv(u)
    b = ag.pppoe_up('c3', u, pw, wait=20)
    unblock(rule)
    act = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 100, 1)
    live = ppp_active(u)
    ag.pppoe_down('c1')
    v = {'login_ok': a['ok'], 'pending_before': [(c['state'], c['token'][:8]) for c in before], 'pending_after_restart': [(c['state'], c['token'][:8]) for c in after],
         'second_refused': not b['ok'], 'confirmed_by_interim': bool(act) and act[0]['token'] == before[0]['token'], 'nas_sessions': len(live)}
    v['pass'] = a['ok'] and v['pending_before'] == v['pending_after_restart'] and before[0]['state'] == 'PENDING' and v['second_refused'] and v['confirmed_by_interim'] and len(live) == 1
    record('G-FAIL.freeradius_restart_pending', {'verdict': v, 'user': u})
    unprovision(u)
    return v


@case
def verifier_faults():
    """a live session (ACTIVE claim) and a second login while the A1 verifier cannot prove anything: SNMP unreachable
    (verifier timeout) and the verifier program not executable (crash): refused, claim untouched, one session"""
    u, pw = new_user('vfy')
    a = ag.pppoe_up('c1', u, pw)
    act = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    out = {}
    t = since()
    b = ag.pppoe_up('c3', u, pw, wait=20)
    out['live_baseline'] = {'second_ok': b['ok'], 'fr': tags_since(t, r'Tmp-String-7 = ')[-3:]}
    rid = ros_add('/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=action=drop', '=comment=nuxr_wp8_a2r')
    t = since()
    t1 = time.time()
    b = ag.pppoe_up('c3', u, pw, wait=20)
    out['snmp_unreachable'] = {'second_ok': b['ok'], 'seconds': round(time.time() - t1, 1), 'fr': tags_since(t, r'Tmp-String-7 = ')[-3:]}
    unblock(rid)
    cg.sh(['docker', 'exec', FR, 'chmod', '0000', f'{R}/nuxr/nuxr_pppoe_session_guard'])
    try:
        t = since()
        b = ag.pppoe_up('c3', u, pw, wait=20)
        out['verifier_crash'] = {'second_ok': b['ok'], 'fr': tags_since(t, r'Program returned|Failed|exec')[-4:]}
    finally:
        cg.sh(['docker', 'exec', FR, 'chmod', '0755', f'{R}/nuxr/nuxr_pppoe_session_guard'])
    claim = resv(u)
    live = ppp_active(u)
    ag.pppoe_down('c1')
    ag.pppoe_down('c3')
    v = {'first_ok': a['ok'], **out, 'claim_untouched': bool(act) and [(c['state'], c['token']) for c in claim] == [('ACTIVE', act[0]['token'])], 'nas_sessions': len(live)}
    v['pass'] = a['ok'] and not any(x['second_ok'] for x in out.values()) and v['claim_untouched'] and len(live) == 1
    record('G-FAIL.verifier_timeout_and_crash', {'verdict': v, 'user': u})
    unprovision(u)
    return v


@case
def db_interruption_real():
    """the RADIUS database paused while a real login is in FreeRADIUS, until X s after the first Access-Request.
    BUDGET 5000 ms (owner decision D-AV4, 2026-10-10; with 2000 ms this case paused 4 s and expected no Accept):
      X = 4 s -> inside the budget: the Accept leaves at ~4 s, the NAS still waits (it gives up ~9 s) -> session UP,
                the accounting confirms the claim (ACTIVE): the NAS consumed the Access-Accept
      X = 7 s -> past the budget: no claim, no Access-Accept, no orphan; the next login works"""
    out = {}
    for x in (4.0, 7.0):
        u, pw = new_user('dbi')
        cap = f'a2r-db-pause-{int(x)}s'
        ag.capture_start(cap)
        t0 = time.time()
        # paused BEFORE the Access-Request arrives (PPPoE discovery takes 1-3 s): FreeRADIUS holds the request in
        # authorize (radcheck query) and continues when the database returns
        cg.sh(['docker', 'pause', DB])
        ag.pppoe_start('c1', u, pw)
        seen = until(lambda: next((p['t_us'] for p in pcap_packets_live(cap) if p['code'] == 'Access-Request'), None), 15, 0.05)
        time.sleep(max(0.0, x - (time.time() - (seen or 0) / 1e6)) if seen else x)
        cg.sh(['docker', 'unpause', DB])
        st = until(lambda: ag.pppoe_state('c1')['ip'] or (not ag.pppoe_state('c1')['running'] and 'down'), 25, 0.5)
        time.sleep(3)
        claim = resv(u)
        live = ppp_active(u)
        ag.pppoe_down('c1')
        pk = pk_of(ag.capture_stop(cap)['file'], (seen or t0 * 1e6) / 1e6)
        nxt = ag.pppoe_up('c1', u, pw)
        ag.pppoe_down('c1')
        out[f'paused_{int(x)}s'] = {'request_seen_before_pause_end': bool(seen), 'session': 'UP' if st and st != 'down' else 'FAILED', 'nas_live': len(live),
                                    'requests_ms': [p['t_ms'] for p in pk if p['code'] == 'Access-Request'],
                                    'replies_ms': [(p['t_ms'], p['code']) for p in pk if p['code'] in ('Access-Accept', 'Access-Reject')],
                                    'claim_after': [(c['state'], c['end_reason']) for c in claim], 'next_login_ok': nxt['ok']}
        unprovision(u)
    i, o = out['paused_4s'], out['paused_7s']
    v = {**out, 'pass': (i['session'] == 'UP' and i['nas_live'] == 1 and [s_ for s_, _ in i['claim_after']] == ['ACTIVE'] and i['next_login_ok']
                        and o['session'] == 'FAILED' and not any(c == 'Access-Accept' for _, c in o['replies_ms'])
                        and all(s_ != 'PENDING' for s_, _ in o['claim_after']) and o['next_login_ok'])}
    record('G-FAIL.db_interruption_real', {'verdict': v, 'budget_ms': 5000})
    return v


def pcap_packets_live(name):
    try:
        return pcap_packets(f'{name}.pcap')
    except Exception:
        return []


class RowHold:
    """a database session that holds the reservation row FOR UPDATE until release() (lab only): the claim statement
    of a login waits for it - a delay INSIDE FreeRADIUS, visible to the late-claim guard"""
    def __init__(self, key_hex):
        self.p = subprocess.Popen(['docker', 'exec', '-i', DB, 'sh', '-c', 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -B nuxr_radius'],
                                  stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.p.stdin.write(f"START TRANSACTION; SELECT state FROM nuxr_admission WHERE username = UNHEX('{key_hex}') FOR UPDATE;\n")
        self.p.stdin.flush()
        time.sleep(0.5)

    def release(self):
        self.p.stdin.write('COMMIT;\n')
        self.p.stdin.close()
        self.p.wait(15)


def fr_ages(since_rfc, user):
    """the ages (ms) the policy computed for $user's Access-Requests, per request, in order (pre-claim, post-claim)"""
    rq, _ = fr_requests(since_rfc, user)
    return [{'request': n, 'age_ms': r['age_ms'], 'tags': r['tags'][-2:], 'reply': (r['sent'] or (None, None))[1]} for n, r in rq.items()]


def _held_login(u, pw, hold_s, cap):
    """one real PPPoE login whose claim statement is held until $hold_s after the FIRST Access-Request on the wire"""
    key = resv(u)[0]['username_hex']
    ag.capture_start(cap)
    t0 = time.time()
    s_rfc = time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime(t0 - 1))
    h = RowHold(key)
    ag.pppoe_start('c1', u, pw)
    first = until(lambda: next((p['t_us'] for p in pcap_packets_live(cap) if p['code'] == 'Access-Request'), None), 15, 0.05)
    time.sleep(max(0.0, hold_s - (time.time() - first / 1e6)) if first else hold_s)
    h.release()
    st = until(lambda: ag.pppoe_state('c1')['ip'] or (not ag.pppoe_state('c1')['running'] and 'down'), 25, 0.3)
    up = bool(st and st != 'down')
    claim = until(lambda: [c for c in resv(u) if c['state'] == 'ACTIVE'], 10, 0.3) if up else resv(u)
    live = ppp_active(u)
    ag.pppoe_down('c1')
    until(lambda: [c for c in resv(u) if c['state'] == 'ENDED'], 15, 0.5)
    pk = pk_of(ag.capture_stop(cap)['file'], (first or t0 * 1e6) / 1e6)
    return {'hold_s': hold_s, 'session': 'UP' if up else 'FAILED', 'nas_live': len(live),
            'wire_requests_ms': [p['t_ms'] for p in pk if p['code'] == 'Access-Request'],
            'wire_replies_ms': [(p['t_ms'], p['code']) for p in pk if p['code'] in ('Access-Accept', 'Access-Reject')],
            'freeradius_age_ms': fr_ages(s_rfc, u), 'claim': [(c['state'], c['end_reason'], c['prev_state']) for c in (claim or [])],
            'client_log': ag.pppoe_state('c1')['log'][-2:]}


@case
def late_claim_real():
    """the claim statement held by a row lock until 6.5 s after the first Access-Request (past the 5000 ms budget,
    before the NAS gives up at ~9 s): claimed late, released by its creator, refused - no Access-Accept; the next login
    is admitted. (With the 2000 ms budget this case held the lock 6 s from before the PPPoE discovery.)"""
    u, pw = new_user('lcr')
    a = ag.pppoe_up('c1', u, pw)
    until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    ag.pppoe_down('c1')
    until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 20, 0.5)
    held = _held_login(u, pw, 6.5, 'a2r-late-claim-6s')
    c = ag.pppoe_up('c1', u, pw)
    ag.pppoe_down('c1')
    v = {'first_ok': a['ok'], 'held': held, 'next_login_ok': c['ok']}
    v['pass'] = (a['ok'] and held['session'] == 'FAILED' and held['claim'] == [('ENDED', 'late_claim', 'ENDED')]
                 and not any(code == 'Access-Accept' for _, code in held['wire_replies_ms']) and c['ok'])
    record('G-LATE.late_claim_real', {'verdict': v, 'user': u, 'budget_ms': 5000})
    unprovision(u)
    return v


@case
def late_budget_real():
    """AV-4 (owner decision D-AV4, 2026-10-10): where is the 5000 ms boundary on the real NAS? The claim statement is
    held until X s after the first Access-Request (a delay INSIDE FreeRADIUS, like a slow A1 verification). Three
    clocks per run: the age the policy computed, the wire time from the first Access-Request (pcap), and whether the
    NAS still consumed the Access-Accept (session UP + accounting confirms the claim) - RouterOS gives up ~9 s.
      X = 3.5 / 4.5 s -> inside the budget: Accept after the 1st / 2nd retransmission; the NAS must consume it
      X = 5.5 s       -> past the budget: refused, claim released by its creator, no Accept"""
    u, pw = new_user('lbr')
    a = ag.pppoe_up('c1', u, pw)
    until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    ag.pppoe_down('c1')
    until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 20, 0.5)
    out = {}
    for x in (3.5, 4.5, 5.5):
        out[f'held_{x}s'] = _held_login(u, pw, x, f'a2r-budget-{int(x * 10)}')
    ok_in = all(out[k]['session'] == 'UP' and out[k]['nas_live'] == 1 and out[k]['claim'][:1] and out[k]['claim'][0][0] == 'ACTIVE'
                for k in ('held_3.5s', 'held_4.5s'))
    late = out['held_5.5s']
    v = {**out, 'inside_budget_consumed_by_nas': ok_in,
         'past_budget_refused': late['session'] == 'FAILED' and late['claim'] == [('ENDED', 'late_claim', 'ENDED')]
                                and not any(c_ == 'Access-Accept' for _, c_ in late['wire_replies_ms'])}
    v['pass'] = a['ok'] and v['inside_budget_consumed_by_nas'] and v['past_budget_refused']
    record('G-LATE.budget_5000_real', {'verdict': v, 'user': u, 'budget_ms': 5000})
    unprovision(u)
    return v


GUARD = f'{R}/nuxr/nuxr_pppoe_session_guard'


def slow_guard(delay):
    """lab only: the A1 guard behind a wrapper that sleeps $delay s first (None = restore the original program)"""
    if delay is None:
        cg.sh(['docker', 'exec', FR, 'sh', '-c', f'[ -f {GUARD}.orig ] && mv -f {GUARD}.orig {GUARD}; chmod 0755 {GUARD}'], check=False)
        return
    cg.sh(['docker', 'exec', FR, 'sh', '-c', f'[ -f {GUARD}.orig ] || cp -p {GUARD} {GUARD}.orig; '
           f'printf "#!/bin/sh\\nsleep {float(delay)}\\nexec {GUARD}.orig \\"\\$@\\"\\n" > {GUARD}; chmod 0755 {GUARD}'])


@case
def slow_guard_lost_stop():
    """AV-4: the Accounting-Stop of a live session is lost (radacct row and claim stay open), the session is gone; the
    legitimate reconnect needs the stock Simultaneous-Use check AND the A1 SNMP guard. The guard is slowed by a lab
    wrapper: 2.5 s (inside the guard's 3 s module timeout; with the 2000 ms budget this reconnect was refused late)
    -> admitted, the ACTIVE claim taken over; 3.5 s (past the module timeout) -> the guard FAILS -> refused, no claim.
    Per run: stock session-check time, guard time, policy age, wire time."""
    u, pw = new_user('sgl')
    out = {}
    for d in (2.5, 3.5):
        a = ag.pppoe_up('c1', u, pw)
        act = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
        rule = block_acct()
        ag.pppoe_down('c1')
        gone = until(lambda: ppp_active(u) == [], 20, 0.5) is not None
        time.sleep(6)
        unblock(rule)
        slow_guard(d)
        try:
            cap = f'a2r-slow-guard-{int(d * 10)}'
            ag.capture_start(cap)
            t0 = time.time()
            s_rfc = time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime(t0 - 1))
            b = ag.pppoe_up('c3', u, pw, wait=25)
        finally:
            slow_guard(None)
        now = until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE' and act and r['token'] != act[0]['token']], 10, 0.2) if b['ok'] else resv(u)
        live = ppp_active(u)
        lg = cg.sh(['docker', 'logs', '-t', '--since', s_rfc, FR], check=False)
        lines = (lg.stdout + lg.stderr).splitlines()
        def t_of(pat):
            m = next((l for l in lines if re.search(pat, l)), None)
            return round((_epoch(m.split(' ', 1)[0]) - t0) * 1000) if m else None
        timing = {'received': t_of(r'\) Received Access-Request'), 'session_start': t_of(r'# Executing section session'), 'session_end': t_of(r'\} # session'),
                  'guard_done': t_of(r'nuxr_pppoe_session_guard: .*(Program returned|Timeout|timed out|Failed)'), 'reply': t_of(r'Sent Access-')}
        ag.pppoe_down('c3')
        until(lambda: [r for r in resv(u) if r['state'] == 'ENDED'], 15, 0.5)
        pk = pk_of(ag.capture_stop(cap)['file'], t0)
        req = [p for p in pk if p['code'] == 'Access-Request']
        f0 = req[0]['t_ms'] if req else 0
        out[f'guard_delay_{d}s'] = {'first_ok': a['ok'], 'old_session_gone': gone, 'stale_claim': [r['state'] for r in act or []], 'relogin_ok': b['ok'],
                                    'taken_over': bool(now) and b['ok'] and now[0]['prev_state'] == 'ACTIVE', 'nas_live': len(live),
                                    'freeradius_ms_from_receive': {k: (v_ - timing['received'] if v_ is not None and timing['received'] is not None else None) for k, v_ in timing.items()},
                                    'freeradius_age_ms': fr_ages(s_rfc, u), 'wire_ms': [(round(p['t_ms'] - f0), p['code']) for p in pk if p['code'] != 'Accounting-Request' and p['code'] != 'Accounting-Response'],
                                    'claim_after': [(r['state'], r['prev_state']) for r in resv(u)]}
    s25, s35 = out['guard_delay_2.5s'], out['guard_delay_3.5s']
    v = {**out, 'pass': s25['relogin_ok'] and s25['taken_over'] and s25['nas_live'] == 1 and (not s35['relogin_ok']) and s35['nas_live'] == 0}
    record('G-LATE.slow_guard_lost_stop', {'verdict': v, 'user': u, 'budget_ms': 5000})
    unprovision(u)
    return v


# ================================================================================================ cross-NAS (CHR + CHR2)
def chr2_setup():
    """the second CHR as a production-contract NAS: nas row 4 (mikrotik_snmp), SNMP read-only community (same lab
    community, source 10.88.0.10 only), guard credential file regenerated from the nas table, FreeRADIUS restart"""
    import option_g_gate as og
    c = cg.community()
    r = ros2()
    try:
        for x in r.rows(['/snmp/community/print']):
            if x.get('name') == 'public':
                r.rows(['/snmp/community/set', '=.id=' + x['.id'], '=disabled=yes'])
        if not [x for x in r.rows(['/snmp/community/print']) if x.get('name') == c]:
            r.rows(['/snmp/community/add', '=name=' + c, '=addresses=10.88.0.10/32', '=read-access=yes', '=write-access=no', '=security=none'])
        if not [x for x in r.rows(['/ip/firewall/filter/print']) if x.get('comment') == cg.TAG]:
            r.rows(['/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=src-address=!10.88.0.10', '=action=drop', '=comment=' + cg.TAG])
        r.rows(['/snmp/set', '=enabled=yes'])
    finally:
        r.close()
    sql(f"DELETE FROM nas WHERE id = 4; INSERT INTO nas (id, nasname, shortname, type, ports, secret, community, description, routers) "
        f"SELECT 4, '{CHR2}', 'nuxr_wp8_chr2', 'mikrotik_snmp', 3799, secret, '{c}', 'WP8 A2-R lab second CHR', '' FROM nas WHERE id = 1")
    n = og.creds()
    cg.restart()
    return {'guard_credential_entries': n, **state()}


def ppp_both(user):
    return ppp_active(user, 1), ppp_active(user, 2)


@case
def cross_nas():
    """the same subscriber on two REAL NAS (CHR 10.88.0.2 / c1 and CHR2 10.88.0.3 / c6): concurrent first logins (x10),
    the second NAS 5 s later with the first Accounting-Start lost (x3), a live session on one NAS vs a login on the
    other (A1 LIVE), and the lost-Stop take-over across NAS"""
    trials = []
    for i in range(13):
        u, pw = new_user('xn')
        lost = i >= 10
        rule = block_acct() if lost else None
        res = {}
        bar = threading.Barrier(2)

        def go(client):
            bar.wait()
            if client == 'c6' and lost:
                time.sleep(5)
            res[client] = ag.pppoe_up(client, u, pw, wait=25)
        th = [threading.Thread(target=go, args=(c,)) for c in ('c1', 'c6')]
        [x.start() for x in th]
        samples = []
        t0 = time.time()
        while any(x.is_alive() for x in th) or time.time() - t0 < 12:
            a1, a2 = ppp_both(u)
            samples.append(len(a1) + len(a2))
            time.sleep(0.3)
        [x.join() for x in th]
        a1, a2 = ppp_both(u)
        trials.append({'lost_start': lost, 'c1_ok': res['c1']['ok'], 'c6_ok': res['c6']['ok'], 'max_live_both_nas': max(samples), 'final_live': len(a1) + len(a2),
                       'claim': [(c['state'], c['nas']) for c in resv(u)]})
        if rule:
            unblock(rule)
        ag.pppoe_down('c1')
        ag.pppoe_down('c6')
        time.sleep(2)
        unprovision(u)
    # live on CHR2, login on CHR1: the claim is ACTIVE on CHR2 -> the guard asks CHR2 -> LIVE -> refused
    u, pw = new_user('xl')
    a = ag.pppoe_up('c6', u, pw)
    until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    t = since()
    b = ag.pppoe_up('c1', u, pw, wait=20)
    live_x = {'c6_ok': a['ok'], 'c1_ok': b['ok'], 'fr': tags_since(t, r'Tmp-String-7 = ')[-3:], 'live': [len(x) for x in ppp_both(u)]}
    # lost Stop on CHR2, then login on CHR1: SNMP of CHR2 proves it gone -> take-over
    r2 = ros2()
    try:
        rid = [a2 for k, a2 in r2.talk(['/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_a2r']) if k == '!done'][0].get('ret')
    finally:
        r2.close()
    ag.pppoe_down('c6')
    time.sleep(6)
    r2 = ros2()
    try:
        r2.talk(['/ip/firewall/filter/remove', '=.id=' + rid])
    finally:
        r2.close()
    c = ag.pppoe_up('c1', u, pw)
    take = resv(u)
    ag.pppoe_down('c1')
    unprovision(u)
    v = {'race_trials': trials, 'live_on_other_nas': live_x,
         'lost_stop_other_nas_takeover': {'login_ok': c['ok'], 'claim': [(x['state'], x['nas'], x['prev_state']) for x in take]}}
    v['pass'] = (all(t['max_live_both_nas'] <= 1 and t['final_live'] <= 1 and (t['c1_ok'] + t['c6_ok']) == 1 for t in trials)
                 and not live_x['c1_ok'] and live_x['c6_ok'] and c['ok'] and take and take[0]['prev_state'] == 'ACTIVE')
    record('G-XNAS.cross_nas', {'verdict': v})
    return v


@case
def cross_nas_followups():
    """cross-NAS follow-ups (re-run on their own): a live session on CHR2 vs a login on CHR1 (the guard asks CHR2 ->
    LIVE -> refused), and the lost Stop on CHR2 followed by a login on CHR1 (SNMP of CHR2 proves it gone -> take-over)"""
    u, pw = new_user('xf')
    a = ag.pppoe_up('c6', u, pw)
    until(lambda: [r for r in resv(u) if r['state'] == 'ACTIVE'], 10, 0.2)
    t = since()
    b = ag.pppoe_up('c1', u, pw, wait=20)
    live_x = {'c6_ok': a['ok'], 'c1_ok': b['ok'], 'fr': [x for x in tags_since(t, r'Tmp-String-7 = ') if 'Tmp-String-7' in x or 'reject' in x][-2:], 'live': [len(x) for x in ppp_both(u)]}
    r2 = ros2()
    try:
        rid = [x for k, x in r2.talk(['/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_a2r']) if k == '!done'][0].get('ret')
    finally:
        r2.close()
    ag.pppoe_down('c6')
    gone = until(lambda: ppp_active(u, 2) == [], 20, 0.5) is not None
    time.sleep(4)
    r2 = ros2()
    try:
        r2.talk(['/ip/firewall/filter/remove', '=.id=' + rid])
    finally:
        r2.close()
    stale = resv(u)
    t = since()
    c = ag.pppoe_up('c1', u, pw)
    take = resv(u)
    tk = [x for x in tags_since(t, r'Tmp-String-7 = ') if 'Tmp-String-7' in x][-1:]
    ag.pppoe_down('c1')
    unprovision(u)
    v = {'live_on_other_nas': live_x, 'lost_stop_other_nas': {'old_session_gone': gone, 'stale_claim': [(x['state'], x['nas']) for x in stale], 'login_ok': c['ok'],
                                                             'claim': [(x['state'], x['nas'], x['prev_state']) for x in take], 'guard': tk}}
    v['pass'] = live_x['c6_ok'] and not live_x['c1_ok'] and any('LIVE' in x for x in live_x['fr']) and c['ok'] and bool(take) and take[0]['prev_state'] == 'ACTIVE' and take[0]['nas'] == CHR
    record('G-XNAS.cross_nas_followups', {'verdict': v})
    return v


# ================================================================================================ synthetic (FreeRADIUS)
@case
def retx_synthetic():
    """exact retransmission: in flight (dropped), cached (identical reply), after a FreeRADIUS restart and from another
    source port (re-processed -> re-uses the claim); a new Request Authenticator / another attempt -> refused; missing or
    malformed identity and MS-CHAPv2 -> refused; PAP and CHAP admitted. Needs: fault on, threaded."""
    u, pw = new_user('rxs')
    res = {}
    at = Attempt(u, pw, nas_id='nuxr-a2r-fault-sleep-before-claim')
    pkt = access_request(at.attrs(), pw, chap=True)
    s = ns_socket()
    t = since()
    box = {}
    th = threading.Thread(target=lambda: box.__setitem__('r', exchange(pkt, sock=s, timeout=10)))
    th.start()
    time.sleep(0.5)
    s.sendto(pkt, (APP, 1812))
    th.join()
    r0 = box['r']
    lg = cg.sh(['docker', 'logs', '--since', t, FR], check=False)
    res['a_in_flight'] = {'reply': r0['reply'] and r0['reply']['code'], 'claim': [(c['state'], c['attach_count']) for c in resv(u)],
                          'dropped': [l[:150] for l in (lg.stdout + lg.stderr).splitlines() if 'Ignoring duplicate' in l][:2]}
    r1 = exchange(pkt, sock=s)
    time.sleep(7)
    r2 = exchange(pkt, sock=s)
    s.close()
    res['b_cached_1s_8s'] = {'replies': [r1['reply'] and r1['reply']['code'], r2['reply'] and r2['reply']['code']],
                             'identical_reply_bytes': bool(r1['reply'] and r2['reply'] and r0['reply'] and r1['reply']['raw_md5'] == r0['reply']['raw_md5'] == r2['reply']['raw_md5']),
                             'claim': [(c['state'], c['attach_count']) for c in resv(u)]}
    r3 = exchange(pkt)                                  # another source port: processed again
    res['c_other_source_port'] = {'reply': r3['reply'] and r3['reply']['code'], 'same_token': token_of(reply_class(r3)) == token_of(reply_class(r0)),
                                  'claim': [(c['state'], c['attach_count']) for c in resv(u)]}
    cg.restart()
    r4 = exchange(pkt)
    res['d_after_freeradius_restart'] = {'reply': r4['reply'] and r4['reply']['code'], 'same_token': token_of(reply_class(r4)) == token_of(reply_class(r0)),
                                         'claim': [(c['state'], c['attach_count']) for c in resv(u)]}
    e = {}
    for label, kw in (('same_identity_new_authenticator', {'asid': at.asid, 'mac': at.mac, 'port': at.port}), ('new_asid', {}), ('same_asid_other_mac', {'asid': at.asid}),
                      ('same_asid_mac_other_port', {'asid': at.asid, 'mac': at.mac, 'port': 'ether9'}), ('same_asid_mac_port_other_nas', {'asid': at.asid, 'mac': at.mac, 'port': at.port, 'nas': '10.88.0.31'})):
        e[label] = Attempt(u, pw, **kw).access()['reply']['code']
    res['e_other_attempts_while_pending'] = e
    tok = token_of(reply_class(r0))
    at.acct('Stop', cls_of(tok))
    f = {}
    for label, drop, over in (('no_acct_session_id', ('Acct-Session-Id',), {}), ('no_calling_station_id', ('Calling-Station-Id',), {}), ('no_nas_port_id', ('NAS-Port-Id',), {}),
                              ('malformed_asid', (), {'asid': "81'; DROP--"}), ('malformed_mac', (), {'mac': 'zz:zz<b>'}), ('long_asid', (), {'asid': 'A' * 40})):
        x = Attempt(u, pw, **over)
        f[label] = x.access(drop=drop)['reply']['code']
    ms = Attempt(u, pw)
    vsa = lambda t_, v_: bytes([26, len(v_) + 8]) + struct.pack('!I', 311) + bytes([t_, len(v_) + 2]) + v_
    raw = access_request(ms.attrs(), pw, chap=False)
    # MS-CHAPv2: replace the PAP attribute by MS-CHAP-Challenge + MS-CHAP2-Response (recompute Message-Authenticator)
    body = raw[20:-18]
    body = b''.join(_split_attrs(body, drop_types={2})) + vsa(11, os.urandom(16)) + vsa(25, b'\x01\x00' + os.urandom(16) + b'\0' * 8 + os.urandom(24))
    head = struct.pack('!BBH', 1, raw[1], 20 + len(body) + 18)
    mac = hmac.new(SECRET, head + raw[4:20] + body + bytes([80, 18]) + b'\0' * 16, 'md5').digest()
    f['mschapv2'] = exchange(head + raw[4:20] + body + bytes([80, 18]) + mac)['reply']['code']
    for m in ('pap', 'chap'):
        y = Attempt(u, pw)
        ry = y.access(chap=(m == 'chap'))
        f[f'{m}_admitted'] = ry['reply']['code']
        if token_of(reply_class(ry)):
            y.acct('Stop', reply_class(ry))
    res['f_identity_and_methods'] = f
    v = res
    v['pass'] = (res['a_in_flight']['reply'] == 'Access-Accept' and res['a_in_flight']['claim'] == [('PENDING', '0')] and bool(res['a_in_flight']['dropped'])
                 and res['b_cached_1s_8s']['identical_reply_bytes'] and res['b_cached_1s_8s']['claim'] == [('PENDING', '0')]
                 and res['c_other_source_port']['same_token'] and res['c_other_source_port']['claim'] == [('PENDING', '1')]
                 and res['d_after_freeradius_restart']['same_token'] and res['d_after_freeradius_restart']['claim'] == [('PENDING', '2')]
                 and all(x == 'Access-Reject' for x in e.values())
                 and all(f[k] == 'Access-Reject' for k in f if not k.endswith('_admitted')) and f['pap_admitted'] == f['chap_admitted'] == 'Access-Accept')
    record('G-RETX.synthetic', {'verdict': v, 'user': u})
    unprovision(u)
    return v


def _split_attrs(body, drop_types=()):
    out, i = [], 0
    while i + 2 <= len(body):
        t, l = body[i], body[i + 1]
        if t not in drop_types:
            out.append(body[i:i + l])
        i += l
    return out


@case
def lifecycle_synthetic():
    """claim before Accept; Class validation; delayed Start; Stop before Start; old accounting after replacement;
    Accounting-On; creator failure without and WITH an accepted attacher. Needs: fault on, threaded."""
    u, pw = new_user('lif')
    out = {}
    a = Attempt(u, pw)
    t = since()
    r = a.access()
    tr = fr_trace(t, r['id'], r['src_port'])
    i_c = next((i for i, l in enumerate(tr) if 'nuxr-admission-claim=1' in l), None)
    i_a = next((i for i, l in enumerate(tr) if 'Sent Access-Accept' in l), None)
    tok = token_of(reply_class(r))
    out['1_claim_before_accept'] = {'reply': r['reply']['code'], 'order_ok': i_c is not None and i_a is not None and i_c < i_a}
    bad = {}
    for label, cls, nas, kw in (('no_class', None, None, {}), ('garbage', b'garbage'.hex(), None, {}), ('other_prefix', '6e75787232' + tok, None, {}),
                                ('unknown_token', cls_of(secrets.token_hex(16)), None, {}), ('other_nas', cls_of(tok), '10.88.0.31', {}),
                                ('other_session_id', cls_of(tok), None, {'asid': 'deadbeef'}), ('other_mac', cls_of(tok), None, {'mac': '02:A2:00:00:00:01'})):
        x = Attempt(u, pw, nas=a.nas, asid=kw.get('asid', a.asid), mac=kw.get('mac', a.mac), port=a.port)
        x.acct('Stop', cls, nas=nas)
        bad[label] = resv(u)[0]['state']
    out['2_invalid_class_no_effect'] = bad
    b = Attempt(u, pw)
    rb = b.access()
    a.acct('Interim-Update', cls_of(tok), delay=20)
    out['3_delayed_start_interim_confirms'] = {'other_attempt': rb['reply']['code'], 'after': [(c['state'], c['confirmed_at'] is not None) for c in resv(u)]}
    on = exchange(accounting_request([('NAS-IP-Address', APP), ('Acct-Status-Type', 7), ('Acct-Session-Id', '00000000'), ('Acct-Delay-Time', 0)]), port=1813)
    out['4_accounting_on'] = [c['state'] for c in resv(u)]
    a.acct('Stop', cls_of(tok))
    a.acct('Start', cls_of(tok), delay=40)
    out['5_stop_then_late_start'] = [(c['state'], c['end_reason']) for c in resv(u)]
    c = Attempt(u, pw)
    rc = c.access()
    before = resv(u)
    for st in ('Start', 'Interim-Update', 'Stop'):
        a.acct(st, cls_of(tok), delay=30)
    after = resv(u)
    out['6_old_accounting_after_replacement'] = {'new_claim': rc['reply']['code'], 'prev_token_is_old': before[0]['prev_token'] == tok,
                                                 'unchanged': [(x['state'], x['token'], x['updated_at']) for x in before] == [(x['state'], x['token'], x['updated_at']) for x in after]}
    c.acct('Stop', reply_class(rc))
    # creator failure without attacher: held 6 s after its claim -> late -> releases (attach_count = 0) -> refused
    d = Attempt(u, pw, nas_id='nuxr-a2r-fault-creator-delay')
    rd = d.access(timeout=15)
    out['7_creator_late_no_attacher'] = {'reply': rd['reply']['code'], 'claim': [(x['state'], x['end_reason'], x['attach_count']) for x in resv(u)]}
    # creator failure AFTER an exact retransmission (other source port) of the SAME attempt attached and was accepted
    # The attacher is the SAME attempt (same NAS, Acct-Session-Id, Calling-Station-Id, NAS-Port-Id, spelling and Request
    # Authenticator) WITHOUT the fault marker (NAS-Identifier is not part of the identity): the same attempt handled a
    # second time while the first handling is stalled after its claim (e.g. a second FreeRADIUS worker or instance).
    e = Attempt(u, pw, nas_id='nuxr-a2r-fault-creator-delay')
    auth = os.urandom(16)
    pkt = access_request(e.attrs(), pw, chap=True, auth=auth)
    e2 = Attempt(u, pw, asid=e.asid, mac=e.mac, port=e.port)
    pkt2 = access_request(e2.attrs(), pw, chap=True, auth=auth)
    box = {}
    th = threading.Thread(target=lambda: box.__setitem__('creator', exchange(pkt, timeout=16)))
    th.start()
    time.sleep(1.0)
    box['attacher'] = exchange(pkt2)
    th.join()
    claim = resv(u)
    out['8_creator_fail_with_accepted_attacher'] = {'creator': box['creator']['reply']['code'], 'attacher': box['attacher']['reply']['code'],
                                                     'attacher_token_is_claim': bool(claim) and token_of(reply_class(box['attacher'])) == claim[0]['token'],
                                                     'claim_after': [(x['state'], x['attach_count'], x['end_reason']) for x in claim]}
    v = out
    v['pass'] = (out['1_claim_before_accept']['order_ok'] and all(x == 'PENDING' for x in bad.values())
                 and out['3_delayed_start_interim_confirms'] == {'other_attempt': 'Access-Reject', 'after': [('ACTIVE', True)]}
                 and out['4_accounting_on'] == ['ACTIVE'] and out['5_stop_then_late_start'] == [('ENDED', 'acct_stop')]
                 and out['6_old_accounting_after_replacement']['unchanged'] and out['6_old_accounting_after_replacement']['prev_token_is_old']
                 and out['7_creator_late_no_attacher'] == {'reply': 'Access-Reject', 'claim': [('ENDED', 'late_claim', '0')]}
                 and out['8_creator_fail_with_accepted_attacher']['creator'] == 'Access-Reject' and out['8_creator_fail_with_accepted_attacher']['attacher'] == 'Access-Accept'
                 and out['8_creator_fail_with_accepted_attacher']['attacher_token_is_claim'] and out['8_creator_fail_with_accepted_attacher']['claim_after'] == [('PENDING', '1', None)])
    record('G-LIFECYCLE.synthetic', {'verdict': v, 'user': u})
    unprovision(u)
    return v


@case
def sql_concurrency():
    """concurrent workers (threaded FreeRADIUS): 10 x 12 different attempts -> exactly one Accept per round; 12 copies
    of ONE attempt from 12 source ports -> one token; SQL pool pressure (40 attempts > pool max 8) -> still at most one
    Accept, rejections counted as availability (pool) not security. Needs: threaded."""
    u, pw = new_user('cc')
    rounds = []
    for i in range(10):
        ats = [Attempt(u, pw) for _ in range(12)]
        rs = burst([access_request(x.attrs(), pw, chap=True) for x in ats])
        acc = [j for j, r in enumerate(rs) if (r['reply'] or {}).get('code') == 'Access-Accept']
        rounds.append((len(acc), sum(1 for r in rs if (r['reply'] or {}).get('code') == 'Access-Reject'), sum(1 for r in rs if not r['reply'])))
        if acc:
            ats[acc[0]].acct('Stop', reply_class(rs[acc[0]]))
    same = Attempt(u, pw)
    pkt = access_request(same.attrs(), pw, chap=True)
    rs = burst([pkt] * 12)
    toks = {token_of(reply_class(r)) for r in rs if (r['reply'] or {}).get('code') == 'Access-Accept'}
    same_res = {'accepts': sum(1 for r in rs if (r['reply'] or {}).get('code') == 'Access-Accept'), 'distinct_tokens': len(toks), 'claim': [(c['state'], c['attach_count']) for c in resv(u)]}
    if toks:
        same.acct('Stop', cls_of(list(toks)[0]))
    t = since()
    press = []
    for i in range(3):
        ats = [Attempt(u, pw) for _ in range(40)]
        rs = burst([access_request(x.attrs(), pw, chap=True) for x in ats])
        acc = [j for j, r in enumerate(rs) if (r['reply'] or {}).get('code') == 'Access-Accept']
        press.append((len(acc), sum(1 for r in rs if (r['reply'] or {}).get('code') == 'Access-Reject'), sum(1 for r in rs if not r['reply'])))
        if acc:
            ats[acc[0]].acct('Stop', reply_class(rs[acc[0]]))
    lg = cg.sh(['docker', 'logs', '--since', t, FR], check=False)
    pool = sum(1 for l in (lg.stdout + lg.stderr).splitlines() if 'No connections available' in l)
    v = {'distinct_rounds_accept_reject_noreply': rounds, 'same_attempt_12_ports': same_res, 'pool_pressure_rounds': press, 'pool_exhausted_log_lines': pool}
    v['pass'] = all(r[0] == 1 for r in rounds) and same_res['distinct_tokens'] == 1 and all(p[0] <= 1 for p in press)
    record('G-SQL.concurrency_and_pool', {'verdict': v, 'user': u})
    unprovision(u)
    return v


def burst(pkts):
    socks = [ns_socket() for _ in pkts]
    res = [None] * len(pkts)
    bar = threading.Barrier(len(pkts))

    def go(j):
        bar.wait()
        res[j] = exchange(pkts[j], sock=socks[j], timeout=10)
    th = [threading.Thread(target=go, args=(j,)) for j in range(len(pkts))]
    [x.start() for x in th]
    [x.join() for x in th]
    [s.close() for s in socks]
    return res


@case
def sql_faults():
    """missing table; connection killed before the claim; database paused before the claim (-> late, no claim); UTC.
    Needs: fault on, threaded."""
    u, pw = new_user('sqf')
    out = {}
    sql('RENAME TABLE nuxr_admission TO nuxr_admission_gate_off')
    try:
        x = Attempt(u, pw)
        r = x.access()
        out['missing_table'] = {'reply': r['reply']['code'], 'attrs': [k for k, _ in r['reply']['attrs'] if k != 'Message-Authenticator']}
    finally:
        sql('RENAME TABLE nuxr_admission_gate_off TO nuxr_admission')
    x = Attempt(u, pw, nas_id='nuxr-a2r-fault-sleep-before-claim')
    box = {}
    th = threading.Thread(target=lambda: box.__setitem__('r', x.access(timeout=12)))
    th.start()
    killed = until(lambda: sqlrows("SELECT id FROM information_schema.processlist WHERE info LIKE '%SLEEP(1.5)%' AND info NOT LIKE '%processlist%'"), 1.4, 0.05) or []
    for (cid,) in killed:
        sql(f'KILL CONNECTION {cid}')
    th.join()
    r = box['r']
    out['connection_killed'] = {'killed': len(killed), 'reply': r['reply']['code'] if r['reply'] else None, 'claim': [(c['state'], c['acct_session_id'] == x.asid) for c in resv(u)]}
    if token_of(reply_class(r)):
        x.acct('Stop', reply_class(r))
    x = Attempt(u, pw, nas_id='nuxr-a2r-fault-sleep-before-claim')
    before = resv(u)
    box = {}
    th = threading.Thread(target=lambda: box.__setitem__('r', x.access(timeout=30)))
    th.start()
    time.sleep(0.5)
    cg.sh(['docker', 'pause', DB])
    time.sleep(6)
    cg.sh(['docker', 'unpause', DB])
    th.join()
    r = box['r']
    after = resv(u)
    out['database_paused'] = {'reply': r['reply']['code'] if r['reply'] else 'NO_REPLY', 'ms': r['ms'],
                              'claim_unchanged': [(c['state'], c['token']) for c in before] == [(c['state'], c['token']) for c in after]}
    y = Attempt(u, pw)
    h0 = time.time()
    ry = y.access()
    c = [c for c in resv(u) if c['acct_session_id'] == y.asid]
    out['utc'] = {'host_utc': time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(h0)), 'created_at': c[0]['created_at'] if c else None,
                  'freeradius_tz': cg.sh(['docker', 'exec', FR, 'sh', '-c', 'echo $TZ $(date +%z)']).stdout.strip()}
    if token_of(reply_class(ry)):
        y.acct('Stop', reply_class(ry))
    v = out
    v['pass'] = (out['missing_table'] == {'reply': 'Access-Reject', 'attrs': ['Reply-Message']} and out['database_paused']['reply'] in ('Access-Reject', 'NO_REPLY')
                 and out['database_paused']['claim_unchanged'] and out['connection_killed']['killed'] >= 1 and out['connection_killed']['reply'] in ('Access-Accept', 'Access-Reject')
                 and bool(c) and c[0]['created_at'][:16] == out['utc']['host_utc'][:16])
    record('G-SQL.faults', {'verdict': v, 'user': u})
    unprovision(u)
    return v


ID_BASES = ['nuxr_a2r_idcase', 'Nuxr_A2R_Mixed', 'nuxr_a2r_café', 'nuxr_a2r_straße', "nuxr.a2r-o'neil", 'nuxr_a2r_q"uote']


def _variants(b):
    v = {b, b.upper(), b.lower(), b.swapcase(), b + ' ', ' ' + b, b.replace('é', 'e'), b.replace('é', 'É'), b.replace('é', 'é'),
         b.replace('ß', 'ss'), b.replace('ß', 's'), b.replace('_', '＿'), b.replace('a', 'á', 1), b.replace('i', 'ı', 1)}
    return sorted(v)


@case
def s1_synthetic():
    """S-1: every spelling the authentication accepts maps to ONE reservation; none is admitted while it is PENDING"""
    out = []
    for base in ID_BASES:
        name = base + '_' + secrets.token_hex(2)
        pw = 'A2r-' + secrets.token_hex(6)
        provision(name, pw)
        acc, keys = [], set()
        for vv in _variants(name):                       # authentication equivalence (each alone, ended after)
            at = Attempt(vv, pw)
            r = at.access()
            if (r['reply'] or {}).get('code') == 'Access-Accept':
                acc.append(vv)
                keys |= {c['username_hex'] for c in resv(name)}
                at.acct('Stop', reply_class(r))
        first = Attempt(name, pw)
        r0 = first.access()
        held = [vv for vv in _variants(name) if vv != name and (Attempt(vv, pw).access()['reply'] or {}).get('code') == 'Access-Accept']
        if token_of(reply_class(r0)):
            first.acct('Stop', reply_class(r0))
        out.append({'base': name, 'accepted_spellings': acc, 'distinct_keys': len(keys), 'admitted_while_pending': held, 'base_admitted': (r0['reply'] or {}).get('code')})
        unprovision(name)
    v = {'per_base': out, 'pass': all(o['distinct_keys'] <= 1 and not o['admitted_while_pending'] for o in out)}
    record('S-1.synthetic', {'verdict': v})
    return v


@case
def collision_precheck():
    """S-1 pre-check: distinct radcheck usernames that the lookup collation treats as the same subscriber"""
    rows = sqlrows("SELECT HEX(a.username), HEX(b.username) FROM radcheck a JOIN radcheck b ON a.username = b.username AND CAST(a.username AS BINARY) < CAST(b.username AS BINARY) GROUP BY 1, 2")
    v = {'colliding_pairs': rows, 'subscribers': sqlrows('SELECT COUNT(DISTINCT username) FROM radcheck')[0][0], 'pass': rows == []}
    record('S-1.collision_precheck', {'verdict': v})
    return v



# ================================================================================================ AV-1 (FreeRADIUS stall)
def _epoch(ts):
    """docker log timestamp (RFC 3339, ns) or DB datetime(6) (UTC) -> epoch seconds"""
    import datetime as _dt
    ts = ts.strip().rstrip('Z').replace(' ', 'T')
    base, _, frac = ts.partition('.')
    return _dt.datetime.strptime(base, '%Y-%m-%dT%H:%M:%S').replace(tzinfo=_dt.timezone.utc).timestamp() + (float('0.' + frac[:6]) if frac else 0.0)


def fr_requests(since_rfc, user):
    """FreeRADIUS log lines (docker write time) of every Access-Request of $user: received, the age the policy computed,
    admission tags, reply; plus every duplicate-packet line of the window"""
    p = cg.sh(['docker', 'logs', '-t', '--since', since_rfc, FR], check=False)
    reqs, users, dup, want = {}, {}, [], None
    for l in (p.stdout + p.stderr).splitlines():
        ts, _, rest = l.partition(' ')
        if re.search(r'duplicate|Discarding', rest):
            dup.append((ts, ag._redact(rest)[:200]))
        m = re.search(r'\((\d+)\)\s+(.*)$', rest)
        if not m:
            continue
        n, body = m.groups()
        if body.startswith('Received Access-Request'):
            reqs[n] = {'received': ts, 'line': body[:90], 'age_ms': [], 'tags': [], 'sent': None}
            continue
        if n not in reqs:
            continue
        r = reqs[n]
        if body.startswith('User-Name = '):
            users[n] = body
        elif ('(%C - %M)' in body or '(%{time_since:us} - %l' in body) and 'EXPAND' in body:  # pre / post WP8 AV-5 formula
            want = n
        elif body.startswith('-->') and want == n:
            r['age_ms'].append(body[3:].strip())
            want = None
        elif 'nuxr-admission' in body and 'EXPAND' not in body and '-->' not in body:
            r['tags'].append(re.sub(r'.*(nuxr-admission[^"]*).*', r'\1', body)[:80])
        elif body.startswith('Sent Access-'):
            r['sent'] = (ts, body[:60])
    return {n: r for n, r in reqs.items() if f'"{user}"' in users.get(n, '')}, dup


def _av1_run(label, u, pw, hold_s):
    """one real PPPoE login with FreeRADIUS paused (docker pause = the whole process frozen, kernel keeps queueing the
    NAS packets) from before the Access-Request until: $hold_s seconds after the first Access-Request, or (None) until
    the client attempt has failed (RouterOS gave up) + 1 s - the WP8 drill-6 situation"""
    cap = f'a2r-av1-{label}'
    ag.capture_start(cap)
    t0 = time.time()
    s_rfc = time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime(t0 - 1))
    cg.sh(['docker', 'pause', FR])
    t_pause = time.time()
    ag.pppoe_start('c1', u, pw)
    first = until(lambda: next((p['t_us'] for p in pcap_packets_live(cap) if p['code'] == 'Access-Request'), None), 15, 0.05)
    t_client_failed = None
    if hold_s is None:
        until(lambda: not ag.pppoe_state('c1')['running'], 30, 0.2)
        t_client_failed = time.time()          # first poll (0.2 s) that saw the attempt over
        time.sleep(1.0)
    else:
        time.sleep(max(0.0, hold_s - (time.time() - first / 1e6)) if first else hold_s)
        t_client_failed = None if ag.pppoe_state('c1')['running'] else time.time()
    t_unpause_cmd = time.time()
    cg.sh(['docker', 'unpause', FR])
    t_unpause = time.time()
    st = until(lambda: ag.pppoe_state('c1')['ip'] or (not ag.pppoe_state('c1')['running'] and 'down'), 25, 0.5)
    time.sleep(3)
    claim = resv(u)
    client_log = ag.pppoe_state('c1')['log'][-4:]
    live = ppp_active(u)
    ag.pppoe_down('c1')
    pk = pcap_packets(ag.capture_stop(cap)['file'])
    f0 = first or int(t0 * 1e6)
    ms = lambda t: None if t is None else round((t * 1e6 - f0) / 1000)
    req = [p for p in pk if p['code'] == 'Access-Request' and dict(p['attrs']).get('User-Name') == u]
    rep = [p for p in pk if p['code'] in ('Access-Accept', 'Access-Reject') and p['dst'].startswith(CHR + ':')]
    rq, dup = fr_requests(s_rfc, u)
    nas_log = [a.get('message') for k, a in ros().talk(['/log/print']) if k == '!re' and (u in (a.get('message') or '') or 'radius' in (a.get('message') or '').lower())][-6:]
    tl = {
        'pause_ms': ms(t_pause), 'unpause_cmd_ms': [ms(t_unpause_cmd), ms(t_unpause)], 'client_attempt_failed_by_ms': ms(t_client_failed),
        'access_requests_on_wire': [{'t_ms': round((p['t_us'] - f0) / 1000), 'id': p['id'], 'auth16': p['auth'][:16],
                                     'acct_session_id': dict(p['attrs']).get('Acct-Session-Id')} for p in req],
        'replies_on_wire': [{'t_ms': round((p['t_us'] - f0) / 1000), 'code': p['code'], 'id': p['id']} for p in rep],
        'freeradius': [{'request': n, 'read_from_socket_ms': ms(_epoch(r['received'])), 'policy_age_ms': r['age_ms'], 'tags': r['tags'][-3:],
                        'reply_logged_ms': ms(_epoch(r['sent'][0])) if r['sent'] else None, 'reply': r['sent'][1] if r['sent'] else None} for n, r in rq.items()],
        'duplicate_lines': [(ms(_epoch(t)), x) for t, x in dup][-6:],
        'claim': [{'state': c['state'], 'acct_session_id': c['acct_session_id'], 'auth16': (c['request_authenticator'] or '')[:16], 'attach_count': c['attach_count'],
                   'created_ms': ms(_epoch(c['created_at'])), 'end_reason': c['end_reason']} for c in claim],
        'session': 'UP' if st and st != 'down' else 'FAILED', 'nas_live_sessions': live, 'client_log': client_log, 'nas_log': nas_log,
    }
    return tl, claim


@case
def av1_stall_timeline():
    """AV-1 (owner closure round): where does a FreeRADIUS stall put the request, the claim and the Access-Accept on the
    timeline? short = paused 2 s after the first Access-Request (inside the NAS deadline); long = drill 6 (paused until
    the NAS gave up). For the long run: the next login, and the operator procedure with NAS-side proof"""
    out = {}
    u, pw = new_user('av1s')
    out['short_2s'], _ = _av1_run('short', u, pw, 2.0)
    until(lambda: [c for c in resv(u) if c['state'] == 'ENDED'], 15, 0.5)
    unprovision(u)
    # paused 5 s (past the 2nd transmission at 3 s, before the NAS gives up): invisible queueing inside the NAS window
    u, pw = new_user('av1m')
    out['mid_5s'], _ = _av1_run('mid', u, pw, 5.0)
    until(lambda: [c for c in resv(u) if c['state'] == 'ENDED'], 15, 0.5)
    unprovision(u)
    u, pw = new_user('av1l')
    tl, claim = _av1_run('long', u, pw, None)
    out['long_drill6'] = tl
    t = since()
    nxt = ag.pppoe_up('c1', u, pw, wait=20)
    nxt_tags = tags_since(t)
    ag.pppoe_down('c1')
    out['next_login'] = {'ok': nxt['ok'], 'fr': nxt_tags[-3:], 'claim': [(c['state'], c['acct_session_id']) for c in resv(u)]}
    # operator procedure (README "Operator recovery"): NAS-side proof that the claimed attempt holds no session
    orphan = [c for c in resv(u) if c['state'] == 'PENDING']
    proof_live = ppp_active(u)
    if orphan and not proof_live:
        canon = sqlrows(f"SELECT username FROM radcheck WHERE username = '{u}' LIMIT 1")[0][0]
        ev = (f"CHR {CHR} /ppp active has no session for the subscriber; claimed attempt Acct-Session-Id {orphan[0]['acct_session_id']} "
              f"timed out at the NAS during the FreeRADIUS stall (lab pcap a2r-av1-long)")
        script = (f"SET @nuxr_username = '{canon}'; SET @nuxr_token = '{orphan[0]['token']}'; SET @nuxr_operator = 'wp8-lab-operator'; "
                  f"SET @nuxr_reason = 'AV-1 gate: Access-Accept sent after the NAS abandoned the attempt'; SET @nuxr_evidence = '{ev}';\n"
                  + open(f'{HERE}/../../install/freeradius/nuxr_admission_release.sql').read())
        rel = sql(script).strip().splitlines()[-1:]
        audit = sqlrows(f"SELECT action, outcome, operator, LEFT(reason, 70), LEFT(evidence, 90) FROM nuxr_admission_audit WHERE token = '{orphan[0]['token']}'")
        after = ag.pppoe_up('c1', u, pw)
        act = until(lambda: [c for c in resv(u) if c['state'] == 'ACTIVE'], 10, 0.3)
        ag.pppoe_down('c1')
        out['operator_recovery'] = {'nas_proof_live_sessions': proof_live, 'release': rel, 'audit': audit, 'login_after_release': after['ok'], 'new_claim_active': bool(act)}
    else:
        out['operator_recovery'] = {'skipped_reason': 'no PENDING orphan' if not orphan else 'NAS shows a live session', 'nas_proof_live_sessions': proof_live}
    s, l = out['short_2s'], out['long_drill6']
    # the Access-Accept went out after the NAS had abandoned the attempt (client saw "radius timeout" and pppd exited
    # before the unpause command was issued); the unpause command's return time is NOT the resume time
    acc_after = [r for r in l['replies_on_wire'] if r['code'] == 'Access-Accept' and l['client_attempt_failed_by_ms'] is not None and r['t_ms'] > l['client_attempt_failed_by_ms']]
    m = out['mid_5s']
    v = {'short_stall_inside_deadline_ok': s['session'] == 'UP' and [c['state'] for c in s['claim']] in (['ACTIVE'], ['ENDED']),
         'mid_stall_inside_deadline_ok': m['session'] == 'UP' and [c['state'] for c in m['claim']] in (['ACTIVE'], ['ENDED']),
         'long_stall_reproduces_av1': l['session'] == 'FAILED' and bool(acc_after) and [c['state'] for c in l['claim']] == ['PENDING'] and not out['next_login']['ok'],
         'policy_age_seen_ms': [a for f in l['freeradius'] for a in f['policy_age_ms']],
         'kernel_queue_ms': [f['read_from_socket_ms'] for f in l['freeradius']],
         'operator_recovery_ok': out['operator_recovery'].get('login_after_release') is True}
    record('AV-1.stall_timeline', {'verdict': v, 'detail': out})
    unprovision(u)
    return {**v, 'detail': out}

if __name__ == '__main__':
    a = sys.argv[1:] or ['state']
    if a[0] == 'threaded':
        print(json.dumps(threaded(a[1]), indent=1, default=str))
    elif a[0] == 'pool':
        print(json.dumps(pool(a[1]), indent=1, default=str))
    elif a[0] == 'fault':
        print(json.dumps(fault(a[1]), indent=1, default=str))
    elif a[0] == 'chr2' and a[1:] == ['setup']:
        print(json.dumps(chr2_setup(), indent=1, default=str))
    elif a[0] == 'state':
        print(json.dumps(state(), indent=1, default=str))
    elif a[0] == 'run':
        for c in a[1:]:
            try:
                v = CASES[c]()
                print(json.dumps({c: v}, default=str, ensure_ascii=False)[:4000], flush=True)
            except Exception as ex:
                record(f'ERROR.{c}', {'summary': repr(ex)[:500]})
                print(f'[a2r] {c} ERROR {ex!r}', flush=True)
            finally:
                cleanup_clients()
    else:
        print(__doc__)
        sys.exit(2)
