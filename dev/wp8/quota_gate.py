#!/usr/bin/env python3
"""V1 QUOTA CAPABILITY GATE - lab tooling only (owner decision QUOTA_V1_GB_SUPPORT_APPROVED / RENEWAL_OPTION_B_APPROVED,
2026-10-17). Measures on the real lab RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8 what the NAS itself enforces inside a
running session, per access type, so the V1 data-quota design uses native enforcement where it is reliable.
Never touches install/, system/, production or AVAIA. Requires the production-contract lab (dev/wp8/lab.sh up,
option_g_gate.py on shipped, ...). Synthetic users nuxr_q_*; RADIUS rows written directly into the disposable lab
database; traffic from the independent client namespaces to the traffic peer (wp8srv).

  quota_gate.py run CASE...     evidence -> $NUXR_WP8_STATE/evidence/quota-gate.jsonl
  quota_gate.py list

Secrets (RADIUS secret, RouterOS password) come from $NUXR_WP8_STATE and are never printed."""
import hashlib, hmac, json, os, secrets, socket, struct, sys, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import a2r_gate as g
import client_agent as ag

EVID = f'{g.STATE}/evidence/quota-gate.jsonl'
SECRET = g.SECRET
CHR = '10.88.0.2'
MIKROTIK = {'Mikrotik-Recv-Limit': 1, 'Mikrotik-Xmit-Limit': 2, 'Mikrotik-Rate-Limit': 8, 'Mikrotik-Recv-Limit-Gigawords': 14,
            'Mikrotik-Xmit-Limit-Gigawords': 15, 'Mikrotik-Total-Limit': 17, 'Mikrotik-Total-Limit-Gigawords': 18}
STD = {'User-Name': (1, 's'), 'Session-Timeout': (27, 'i'), 'Acct-Session-Id': (44, 's'), 'Framed-IP-Address': (8, 'ip'),
       'Acct-Interim-Interval': (85, 'i'), 'Idle-Timeout': (28, 'i')}
CODES = {41: 'Disconnect-ACK', 42: 'Disconnect-NAK', 44: 'CoA-ACK', 45: 'CoA-NAK'}


def record(case, data):
    os.makedirs(os.path.dirname(EVID), mode=0o700, exist_ok=True)
    line = ag._redact(json.dumps({'t': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'case': case, **data}, default=str))
    with open(EVID, 'a') as f:
        f.write(line + '\n')
    print(f'[quota] {case}: ' + json.dumps(data.get('verdict', ''), default=str)[:600], flush=True)


def esc(v):
    return str(v).replace('\\', '\\\\').replace("'", "\\'")


def provision(user, pw, check=(), reply=(), pppoe=True):
    h = g.hexs(user)
    rows_c = [('Cleartext-Password', pw), ('Simultaneous-Use', '1'), *check]
    rows_r = ([('Framed-Pool', 'nuxr_wp8_pool_dyn')] if pppoe else []) + list(reply)
    g.unprovision(user)
    g.sql('INSERT INTO radcheck (username, attribute, op, value) VALUES ' + ', '.join(
        f"(CONVERT(X'{h}' USING utf8mb4), '{a}', ':=', '{esc(v)}')" for a, v in rows_c))
    if rows_r:
        g.sql('INSERT INTO radreply (username, attribute, op, value) VALUES ' + ', '.join(
            f"(CONVERT(X'{h}' USING utf8mb4), '{a}', ':=', '{esc(v)}')" for a, v in rows_r))


def acct(user):
    """accounting rows of the user (newest first): octets, stop, cause"""
    return [dict(zip(('radacctid', 'acctsessionid', 'start', 'update', 'stop', 'sessiontime', 'in', 'out', 'cause'), r)) for r in g.sqlrows(
        "SELECT radacctid, acctsessionid, acctstarttime, acctupdatetime, IFNULL(acctstoptime,''), IFNULL(acctsessiontime,''), acctinputoctets, acctoutputoctets, "
        f"IFNULL(acctterminatecause,'') FROM radacct WHERE username = CONVERT(X'{g.hexs(user)}' USING utf8mb4) COLLATE utf8mb4_bin ORDER BY radacctid DESC")]


def ppp_detail(user):
    r = g.ros()
    try:
        return [a for k, a in r.talk(['/ppp/active/print', '=detail=', '?name=' + user]) if k == '!re']
    finally:
        r.close()


def hs_detail(user):
    r = g.ros()
    try:
        return [a for k, a in r.talk(['/ip/hotspot/active/print', '?user=' + user]) if k == '!re']
    finally:
        r.close()


def _attr(name, value):
    if name in MIKROTIK:
        v = str(value).encode() if name == 'Mikrotik-Rate-Limit' else struct.pack('!I', int(value))
        sub = bytes([MIKROTIK[name], len(v) + 2]) + v
        vsa = struct.pack('!I', 14988) + sub
        return bytes([26, len(vsa) + 2]) + vsa
    t, kind = STD[name]
    v = struct.pack('!I', int(value)) if kind == 'i' else socket.inet_aton(value) if kind == 'ip' else str(value).encode()
    return bytes([t, len(v) + 2]) + v


def coa(code, attrs, nas=CHR):
    """CoA-Request (43) / Disconnect-Request (40) to the NAS from the application address, framed like
    Reseller\\Radius\\RadiusPacket::request (Message-Authenticator over a zero authenticator)"""
    pid = secrets.randbelow(256)
    body = b''.join(_attr(n, v) for n, v in attrs)
    ma = bytes([80, 18]) + b'\0' * 16
    ln = 20 + len(body) + 18
    head = struct.pack('!BBH', code, pid, ln)
    body += bytes([80, 18]) + hmac.new(SECRET, head + b'\0' * 16 + body + ma, 'md5').digest()
    auth = hashlib.md5(head + b'\0' * 16 + body + SECRET).digest()
    pkt = head + auth + body
    s = g.ns_socket()
    s.settimeout(4)
    t0 = time.time()
    s.sendto(pkt, (nas, 3799))
    try:
        raw, _ = s.recvfrom(4096)
        res = {'code': CODES.get(raw[0], raw[0]), 'ms': round((time.time() - t0) * 1000)}
        if raw[0] in (42, 45):
            i = 20
            while i + 2 <= len(raw):
                if raw[i] == 101:
                    res['error_cause'] = struct.unpack('!I', raw[i + 2:i + 6])[0]
                i += raw[i + 1] or 1
    except socket.timeout:
        res = {'code': 'TIMEOUT'}
    finally:
        s.close()
    return res


def gone_ppp(user, seconds):
    return g.until(lambda: not g.ppp_active(user), seconds, 0.5)


def gone_hs(user, seconds):
    return g.until(lambda: not hs_detail(user), seconds, 0.5)


def new(tag):
    return f'nuxr_q_{tag}_{secrets.token_hex(3)}', 'Q-' + secrets.token_hex(6)


CASES = {}


def case(fn):
    CASES[fn.__name__] = fn
    return fn


def run_case(name):
    try:
        CASES[name]()
    except Exception as e:  # recorded, never hidden
        record(name, {'verdict': f'ERROR {type(e).__name__}: {e}'[:300]})
    finally:
        g.cleanup_clients()


# ------------------------------------------------------------------------------------------- PPPoE at authentication
def _pppoe_limit_case(name, reply, direction='down', seconds=14, kbps=4000, budget=2_000_000):
    user, pw = new(name[:6])
    provision(user, pw, reply=[('Mikrotik-Rate-Limit', '8M/8M'), *reply])
    up = ag.pppoe_up('c1', user, pw)
    detail = ppp_detail(user)
    tr = ag.traffic('c1', direction, seconds, kbps) if up.get('ok') else {}
    ended = gone_ppp(user, 15)
    time.sleep(3)
    rows = acct(user)
    used = int(rows[0]['in']) + int(rows[0]['out']) if rows else None
    enforced = bool(ended) and used is not None and used <= budget * 1.25
    record(name, {'up': up.get('ok'), 'reply': reply, 'ppp_active_detail_keys': sorted(detail[0].keys()) if detail else [],
                  'ppp_active_limits': {k: v for k, v in (detail[0] if detail else {}).items() if 'limit' in k or 'byte' in k},
                  'traffic': tr, 'session_ended': bool(ended), 'acct': rows[:1], 'used_bytes': used,
                  'verdict': f'{"ENFORCED" if enforced else "NOT_ENFORCED"}: ended={bool(ended)} used={used} budget={budget} offered~{kbps * seconds // 8 * 1000}'})


@case
def pppoe_total_limit():
    _pppoe_limit_case('pppoe_total_limit', [('Mikrotik-Total-Limit', '2000000')])


@case
def pppoe_recv_xmit_limit():
    _pppoe_limit_case('pppoe_recv_xmit_limit', [('Mikrotik-Recv-Limit', '2000000'), ('Mikrotik-Xmit-Limit', '2000000')])


@case
def pppoe_v1_reply_full():
    """the exact V1 reply set (Total + Recv + Xmit, each with Gigawords 0): does the NAS end the session at the cap?"""
    _pppoe_limit_case('pppoe_v1_reply_full', [('Mikrotik-Total-Limit', '2000000'), ('Mikrotik-Total-Limit-Gigawords', '0'),
                                              ('Mikrotik-Recv-Limit', '2000000'), ('Mikrotik-Recv-Limit-Gigawords', '0'),
                                              ('Mikrotik-Xmit-Limit', '2000000'), ('Mikrotik-Xmit-Limit-Gigawords', '0')], seconds=24)


@case
def pppoe_v1_reply_no_total():
    """Recv + Xmit with Gigawords 0, no Total-Limit"""
    _pppoe_limit_case('pppoe_v1_reply_no_total', [('Mikrotik-Recv-Limit', '2000000'), ('Mikrotik-Recv-Limit-Gigawords', '0'),
                                                  ('Mikrotik-Xmit-Limit', '2000000'), ('Mikrotik-Xmit-Limit-Gigawords', '0')], seconds=24)


@case
def pppoe_session_timeout():
    user, pw = new('pst')
    provision(user, pw, reply=[('Session-Timeout', '25')])
    t0 = time.time()
    up = ag.pppoe_up('c1', user, pw)
    ended = gone_ppp(user, 60)
    dur = round(time.time() - t0)
    time.sleep(3)
    rows = acct(user)
    record('pppoe_session_timeout', {'up': up.get('ok'), 'ended_after_s': dur if ended else None, 'acct': rows[:1],
                                     'verdict': f'{"ENFORCED" if ended and 20 <= dur <= 45 else "NOT_ENFORCED"} after {dur}s cause={rows[0]["cause"] if rows else None}'})


@case
def pppoe_interim_interval():
    user, pw = new('pii')
    provision(user, pw, reply=[('Acct-Interim-Interval', '10')])
    up = ag.pppoe_up('c1', user, pw)
    seen = []
    t0 = time.time()
    while time.time() - t0 < 45:
        r = acct(user)
        if r and r[0]['update'] not in seen:
            seen.append(r[0]['update'])
        time.sleep(1)
    record('pppoe_interim_interval', {'up': up.get('ok'), 'distinct_updates': len(seen), 'updates': seen,
                                      'verdict': f'{"HONOURED" if len(seen) >= 4 else "NOT_HONOURED"}: {len(seen)} distinct acctupdatetime values in 45 s with Acct-Interim-Interval=10'})


# ------------------------------------------------------------------------------------------- Hotspot at authentication
def _hs_up(user, pw):
    ag.hs_dhcp('c2')
    return ag.hs_login('c2', user, pw)


@case
def hotspot_total_limit():
    user, pw = new('htl')
    provision(user, pw, reply=[('Mikrotik-Rate-Limit', '8M/8M'), ('Mikrotik-Total-Limit', '2000000')], pppoe=False)
    up = _hs_up(user, pw)
    det = hs_detail(user)
    tr = ag.traffic('c2', 'down', 14, 4000)
    ended = gone_hs(user, 15)
    time.sleep(3)
    rows = acct(user)
    used = int(rows[0]['in']) + int(rows[0]['out']) if rows else None
    record('hotspot_total_limit', {'up': up, 'hotspot_active': {k: v for k, v in (det[0] if det else {}).items() if 'limit' in k or 'byte' in k},
                                   'traffic': tr, 'session_ended': bool(ended), 'acct': rows[:1], 'used_bytes': used,
                                   'verdict': f'{"ENFORCED" if ended and used is not None and used <= 2_500_000 else "NOT_ENFORCED"}: used={used} budget=2000000'})


@case
def hotspot_total_limit_gigawords():
    user, pw = new('hgw')
    provision(user, pw, reply=[('Mikrotik-Total-Limit', '5'), ('Mikrotik-Total-Limit-Gigawords', '1')], pppoe=False)
    up = _hs_up(user, pw)
    det = hs_detail(user)
    lim = (det[0] if det else {}).get('limit-bytes-total')
    record('hotspot_total_limit_gigawords', {'up': up, 'hotspot_active': det[0] if det else None,
                                             'verdict': f'{"COMBINED" if lim == str(2 ** 32 + 5) else "NOT_COMBINED"}: limit-bytes-total={lim} (expected {2 ** 32 + 5})'})


# ------------------------------------------------------------------------------------------- mid-session CoA
@case
def coa_pppoe_session_timeout():
    user, pw = new('cst')
    provision(user, pw)
    up = ag.pppoe_up('c1', user, pw)
    sess = acct(user)
    time.sleep(2)
    t0 = time.time()
    r = coa(43, [('User-Name', user), ('Acct-Session-Id', sess[0]['acctsessionid'] if sess else ''), ('Session-Timeout', '15')])
    ended = gone_ppp(user, 60)
    dur = round(time.time() - t0)
    record('coa_pppoe_session_timeout', {'up': up.get('ok'), 'coa': r, 'ended_after_coa_s': dur if ended else None,
                                         'verdict': f'{r["code"]}; session {"ENDED after " + str(dur) + "s" if ended else "NOT ended within 60 s"}'})


@case
def coa_pppoe_total_limit():
    user, pw = new('ctl')
    provision(user, pw, reply=[('Mikrotik-Rate-Limit', '8M/8M')])
    up = ag.pppoe_up('c1', user, pw)
    sess = acct(user)
    r = coa(43, [('User-Name', user), ('Acct-Session-Id', sess[0]['acctsessionid'] if sess else ''), ('Mikrotik-Total-Limit', '2000000')])
    tr = ag.traffic('c1', 'down', 14, 4000)
    ended = gone_ppp(user, 15)
    time.sleep(3)
    rows = acct(user)
    used = int(rows[0]['in']) + int(rows[0]['out']) if rows else None
    record('coa_pppoe_total_limit', {'up': up.get('ok'), 'coa': r, 'traffic': tr, 'session_ended': bool(ended), 'used_bytes': used,
                                     'verdict': f'{r["code"]}; {"ENFORCED" if ended and used is not None and used <= 2_500_000 else "NOT_ENFORCED"} used={used}'})


@case
def coa_hotspot_total_limit():
    user, pw = new('chl')
    provision(user, pw, reply=[('Mikrotik-Rate-Limit', '8M/8M')], pppoe=False)
    up = _hs_up(user, pw)
    sess = acct(user)
    r = coa(43, [('User-Name', user), ('Acct-Session-Id', sess[0]['acctsessionid'] if sess else ''), ('Mikrotik-Total-Limit', '2000000')])
    det = hs_detail(user)
    tr = ag.traffic('c2', 'down', 14, 4000)
    ended = gone_hs(user, 15)
    time.sleep(3)
    rows = acct(user)
    used = int(rows[0]['in']) + int(rows[0]['out']) if rows else None
    record('coa_hotspot_total_limit', {'up': up, 'coa': r, 'hotspot_active_after_coa': {k: v for k, v in (det[0] if det else {}).items() if 'limit' in k},
                                       'traffic': tr, 'session_ended': bool(ended), 'used_bytes': used,
                                       'verdict': f'{r["code"]}; {"ENFORCED" if ended and used is not None and used <= 2_500_000 else "NOT_ENFORCED"} used={used}'})


@case
def disconnect_pppoe():
    user, pw = new('dpp')
    provision(user, pw)
    up = ag.pppoe_up('c1', user, pw)
    sess = acct(user)
    r = coa(40, [('User-Name', user), ('Acct-Session-Id', sess[0]['acctsessionid'] if sess else '')])
    ended = gone_ppp(user, 10)
    time.sleep(2)
    rows = acct(user)
    record('disconnect_pppoe', {'up': up.get('ok'), 'disconnect': r, 'acct': rows[:1],
                                'verdict': f'{r["code"]}; session {"ENDED" if ended else "NOT ended"}; stop={bool(rows and rows[0]["stop"])} cause={rows[0]["cause"] if rows else None}'})


@case
def coa_pppoe_recv_xmit_limit():
    user, pw = new('cxl')
    provision(user, pw, reply=[('Mikrotik-Rate-Limit', '8M/8M')])
    up = ag.pppoe_up('c1', user, pw)
    sess = acct(user)
    r = coa(43, [('User-Name', user), ('Acct-Session-Id', sess[0]['acctsessionid'] if sess else ''), ('Mikrotik-Recv-Limit', '2000000'), ('Mikrotik-Xmit-Limit', '2000000')])
    det = ppp_detail(user)
    tr = ag.traffic('c1', 'down', 14, 4000)
    ended = gone_ppp(user, 15)
    time.sleep(3)
    rows = acct(user)
    used = int(rows[0]['in']) + int(rows[0]['out']) if rows else None
    record('coa_pppoe_recv_xmit_limit', {'up': up.get('ok'), 'coa': r, 'ppp_active_after_coa': {k: v for k, v in (det[0] if det else {}).items() if 'limit' in k},
                                         'traffic': tr, 'session_ended': bool(ended), 'used_bytes': used,
                                         'verdict': f'{r["code"]}; {"ENFORCED" if ended and used is not None and used <= 2_500_000 else "NOT_ENFORCED"} used={used}'})


def main():
    a = sys.argv[1:] or ['list']
    if a[0] == 'list':
        print(' '.join(CASES))
    elif a[0] == 'run':
        for name in a[1:] or list(CASES):
            run_case(name)
    else:
        sys.exit('usage: quota_gate.py list | run [CASE...]')


if __name__ == '__main__':
    main()
