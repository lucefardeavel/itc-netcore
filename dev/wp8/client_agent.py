#!/usr/bin/env python3
"""WP8 lab client agent (development/test only). Runs on the lab host, started and stopped by dev/wp8/lab.sh.

PHPUnit runs inside the php82-wp8 runner container and cannot drive host network namespaces, so the WP8 tests send
small JSON requests through the state directory ($NUXR_WP8_STATE/ctl, mounted at /wp8ctl in the runner):
    ctl/req/<id>.json  ->  ctl/res/<id>.json
Only the whitelisted operations below exist. They act on the lab only: the independent client namespaces
(wp8c1 / wp8c3 PPPoE, wp8c2 / wp8c4 Hotspot, wp8srv traffic peer), the lab CHR through its loopback management
forward, the lab FreeRADIUS container (pause/unpause for drills) and packet captures on the lab bridge wp8lab0.
Passwords in requests are never logged; request files are deleted after processing."""
import glob, hashlib, json, os, re, signal, socket, struct, subprocess, sys, time, traceback
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from rosapi import Ros

STATE = os.environ['NUXR_WP8_STATE']
CTL = f'{STATE}/ctl'
SEC = dict(l.strip().split('=', 1) for l in open(f'{STATE}/secrets.env') if '=' in l and not l.startswith('#'))
FR = 'nuxbill-reseller-test-freeradius-wp8-1'
CLIENTS = {'c1': ('wp8c1', 'wp8v1c', 1), 'c3': ('wp8c3', 'wp8v3c', 3), 'c2': ('wp8c2', 'wp8v2c', 2), 'c4': ('wp8c4', 'wp8v4c', 4), 'c5': ('wp8c5', 'wp8v5c', 5),
           'c6': ('wp8c6', 'wp8v6c', 6),     # WP8 A2-R: PPPoE client of the second CHR (lab.sh up-chr2)
           # WP8 A3: Hotspot clients of the second CHR (namespaces created by dev/wp8/h2_gate.py setup)
           'c7': ('wp8c7', 'wp8v7c', 7), 'c8': ('wp8c8', 'wp8v8c', 8)}
HS_GW = {'c7': '10.88.12.1', 'c8': '10.88.12.1'}   # Hotspot gateway per client (default: CHR1 10.88.2.1)
PPPD = {}
CAPS = {}


def sh(cmd, timeout=60, check=False):
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    if check and p.returncode != 0:
        raise RuntimeError(f'{cmd[:4]} rc={p.returncode} {p.stderr[-300:]}')
    return p


def nsx(ns, *cmd, timeout=60):
    return sh(['ip', 'netns', 'exec', ns, *cmd], timeout=timeout)


def ros():
    return Ros('127.0.0.1', 18728).login('admin', SEC['NUXR_WP8_CHR_PASSWORD'])


def addr(ns, ifname):
    out = nsx(ns, 'ip', '-4', '-o', 'addr', 'show', 'dev', ifname).stdout
    m = re.search(r'inet (\d+\.\d+\.\d+\.\d+)', out)
    return m.group(1) if m else None


# ---------------------------------------------------------------- PPPoE (independent Linux pppd client)
def pppoe_up(client, user, password, wait=40):
    ns, nic, unit = CLIENTS[client]
    pppoe_down(client)
    d = f'{STATE}/run/{client}'; os.makedirs(d, mode=0o700, exist_ok=True)
    log = f'{d}/pppd.log'; open(log, 'w').close()
    opts = f'{d}/pppd.opts'
    with open(opts, 'w') as f:
        os.chmod(opts, 0o600)
        f.write(open(os.path.dirname(os.path.abspath(__file__)) + '/pppd-client.opts.tpl').read()
                .replace('@NIC@', nic).replace('@UNIT@', str(unit)).replace('@LOG@', log)
                .replace('@USER@', user).replace('@PASSWORD@', password))
    t0 = time.time()
    p = subprocess.Popen(['ip', 'netns', 'exec', ns, 'pppd', 'file', opts], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    PPPD[client] = p
    ip = None
    while time.time() - t0 < wait:
        if p.poll() is not None: break
        ip = addr(ns, f'ppp{unit}')
        if ip: break
        time.sleep(0.5)
    os.unlink(opts)
    txt = open(log).read()
    remote = re.findall(r'remote IP address (\S+)', txt)
    return {'ok': ip is not None, 'ip': ip, 'remote': remote[-1] if remote else None, 'seconds': round(time.time() - t0, 1),
            'running': p.poll() is None, 'log': _pppd_log(txt)}


def _pppd_log(txt):
    keep = [l for l in txt.splitlines() if re.search(r'PADO|PADS|CHAP|authentication|local  IP|remote IP|TermReq|hangup|terminated|Connection|radius|exit', l, re.I)]
    return keep[-14:]


def pppoe_start(client, user, password):
    """non-blocking pppd start (the caller then watches the NAS / pppoe_state)"""
    ns, nic, unit = CLIENTS[client]
    pppoe_down(client)
    d = f'{STATE}/run/{client}'; os.makedirs(d, mode=0o700, exist_ok=True)
    log = f'{d}/pppd.log'; open(log, 'w').close()
    opts = f'{d}/pppd.opts'
    with open(opts, 'w') as f:
        os.chmod(opts, 0o600)
        f.write(open(os.path.dirname(os.path.abspath(__file__)) + '/pppd-client.opts.tpl').read()
                .replace('@NIC@', nic).replace('@UNIT@', str(unit)).replace('@LOG@', log)
                .replace('@USER@', user).replace('@PASSWORD@', password))
    PPPD[client] = subprocess.Popen(['ip', 'netns', 'exec', ns, 'pppd', 'file', opts], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1.0); os.unlink(opts)
    return {'ok': True}


def watch_active(kind, user, seconds=30, interval=0.5):
    """samples the NAS active table for $user: max simultaneous live sessions over the window"""
    path = {'ppp': '/ppp/active/print', 'hotspot': '/ip/hotspot/active/print'}[kind]
    key = '?name=' if kind == 'ppp' else '?user='
    r = ros(); samples = []; t0 = time.time()
    while time.time() - t0 < seconds:
        rows = [a for k, a in r.talk([path, key + user]) if k == '!re']
        samples.append([round(time.time() - t0, 1), len(rows), [x.get('address') for x in rows]])
        time.sleep(interval)
    r.close()
    return {'max': max(s[1] for s in samples), 'samples': samples[::max(1, len(samples) // 20)], 'n': len(samples)}


def pppoe_state(client):
    ns, _, unit = CLIENTS[client]
    p = PPPD.get(client)
    log = f'{STATE}/run/{client}/pppd.log'
    return {'running': p is not None and p.poll() is None, 'ip': addr(ns, f'ppp{unit}'),
            'log': _pppd_log(open(log).read()) if os.path.exists(log) else []}


def pppoe_wait_down(client, wait=30):
    t0 = time.time()
    while time.time() - t0 < wait:
        st = pppoe_state(client)
        if not st['running'] and not st['ip']:
            return {**st, 'down': True, 'seconds': round(time.time() - t0, 1)}
        time.sleep(0.5)
    return {**pppoe_state(client), 'down': False}


def pppoe_down(client):
    p = PPPD.pop(client, None)
    if p and p.poll() is None:
        p.send_signal(signal.SIGTERM)
        try: p.wait(10)
        except subprocess.TimeoutExpired: p.kill(); p.wait(5)
    return {'ok': True}


# ---------------------------------------------------------------- Hotspot (independent Linux client, http-chap)
def hs_dhcp(client):
    ns, nic, _ = CLIENTS[client]
    nsx(ns, 'ip', 'addr', 'flush', 'dev', nic)
    p = nsx(ns, 'busybox', 'udhcpc', '-i', nic, '-n', '-q', '-f', '-t', '5', '-s', '/bin/true', timeout=40)
    m = re.search(r'lease of (\S+) obtained', p.stdout + p.stderr)
    if not m: return {'ok': False, 'out': (p.stdout + p.stderr)[-300:]}
    nsx(ns, 'ip', 'addr', 'add', m.group(1) + '/24', 'dev', nic)
    nsx(ns, 'ip', 'route', 'replace', 'default', 'via', HS_GW.get(client, '10.88.2.1'))
    return {'ok': True, 'ip': m.group(1)}


def _curl(ns, *args, timeout=20):
    p = nsx(ns, 'curl', '-sS', '-m', str(timeout), *args, timeout=timeout + 5)
    return p.stdout, p.stderr


def http_probe(client, url='http://192.0.2.1/'):
    ns = CLIENTS[client][0]
    out, err = _curl(ns, '-o', '/dev/null', '-D', '-', url, timeout=8)
    st = re.search(r'^HTTP/\S+ (\d+)', out, re.M); loc = re.search(r'^Location: (\S+)', out, re.M | re.I)
    return {'status': int(st.group(1)) if st else None, 'location': loc.group(1).strip() if loc else None, 'error': err.strip()[-160:]}


def _octal(s):
    return bytes(int(o, 8) for o in re.findall(r'\\(\d{3})', s))


def hs_login(client, user, password):
    ns = CLIENTS[client][0]
    gw = HS_GW.get(client, '10.88.2.1')
    page, _ = _curl(ns, f'http://{gw}/login')
    m = re.search(r"hexMD5\('((?:\\\d{3})+)' \+ document\.login\.password\.value \+ '((?:\\\d{3})+)'\)", page)
    if not m: return {'ok': False, 'state': 'NO_CHAP_FORM', 'page': re.sub(r'<[^>]+>', ' ', page)[:200]}
    resp = hashlib.md5(_octal(m.group(1)) + password.encode() + _octal(m.group(2))).hexdigest()
    body, _ = _curl(ns, '--data-urlencode', f'username={user}', '--data-urlencode', f'password={resp}',
                    '--data-urlencode', 'dst=', '--data-urlencode', 'popup=false', f'http://{gw}/login')
    text = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body))
    err = re.search(r'(RADIUS server is not responding|invalid username or password|[^.!]{0,40}(?:denied|reject|limit|expired|not allowed)[^.!]{0,60})', text, re.I)
    # success only on RouterOS' own success page; a RADIUS Reply-Message such as "You are already logged in - access
    # denied" (FreeRADIUS Simultaneous-Use) is a refusal
    ok = err is None and re.search(r'You are logged in', text) is not None
    return {'ok': ok, 'state': 'LOGGED_IN' if ok else 'REFUSED', 'message': (err.group(1) if err else text[:160]).strip(), 'auth': 'http-chap'}


def hs_logout(client):
    _curl(CLIENTS[client][0], '-o', '/dev/null', f"http://{HS_GW.get(client, '10.88.2.1')}/logout")
    return {'ok': True}


# ---------------------------------------------------------------- traffic (rate-limit direction proof)
RX = r'''
import socket,time,sys
s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM); s.bind(("0.0.0.0",5001)); s.settimeout(1.0)
n=0; t0=None; end=time.time()+float(sys.argv[1])+6
while time.time()<end:
    try: d,_=s.recvfrom(2048)
    except socket.timeout: continue
    if t0 is None: t0=time.time()
    n+=len(d); last=time.time()
print(n, (last-t0) if t0 and n else 0)
'''
TX = r'''
import socket,time,sys
dst,sec,kbps=sys.argv[1],float(sys.argv[2]),float(sys.argv[3])
s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM); pkt=b"x"*1000; gap=8000.0/(kbps*1000.0); t0=time.time(); nxt=t0
while time.time()-t0<sec:
    s.sendto(pkt,(dst,5001)); nxt+=gap; d=nxt-time.time()
    if d>0: time.sleep(d)
'''


def traffic(client, direction, seconds=8, kbps_offer=2000):
    ns, _, unit = CLIENTS[client]
    cip = addr(ns, f'ppp{unit}') or addr(ns, CLIENTS[client][1])
    if addr(ns, f'ppp{unit}'):  # pppd runs with nodefaultroute: route the traffic peer over the PPPoE session
        nsx(ns, 'ip', 'route', 'replace', '10.88.0.40/32', 'dev', f'ppp{unit}')
    rx_ns, tx_ns, dst = ('wp8srv', ns, '10.88.0.40') if direction == 'up' else (ns, 'wp8srv', cip)
    rx = subprocess.Popen(['ip', 'netns', 'exec', rx_ns, 'python3', '-c', RX, str(seconds)], stdout=subprocess.PIPE, text=True)
    time.sleep(0.8)
    nsx(tx_ns, 'python3', '-c', TX, dst, str(seconds), str(kbps_offer), timeout=seconds + 20)
    out, _ = rx.communicate(timeout=seconds + 30)
    n, dur = out.split()
    n, dur = int(n), float(dur)
    return {'direction': direction, 'client_ip': cip, 'offered_kbps': kbps_offer, 'bytes': n, 'seconds': round(dur, 2),
            'measured_kbps': round(n * 8 / dur / 1000, 1) if dur > 0 else 0.0}


def traffic_mixed(client, seconds=60, kbps_up=1500, kbps_down=1500):
    """V1 quota lab (owner QUOTA_V1_GB_SUPPORT_APPROVED): upload and download at the same time (PPPoE mixed traffic,
    the case RouterOS does not cap natively); each direction as traffic()"""
    import threading
    out = {}

    def run(direction, kbps):
        try:
            out[direction] = traffic(client, direction, seconds, kbps)
        except Exception as e:  # reported, never hidden
            out[direction] = {'error': f'{type(e).__name__}: {e}'[:200]}
    th = [threading.Thread(target=run, args=('up', kbps_up)), threading.Thread(target=run, args=('down', kbps_down))]
    for t in th:
        t.start()
        time.sleep(0.3)
    for t in th:
        t.join(seconds + 60)
    return out


# ---------------------------------------------------------------- CHR, FreeRADIUS, captures
def ros_cmd(words):
    r = ros(); rows = r.talk(words); r.close()
    traps = [a for k, a in rows if k == '!trap']
    return {'ok': not traps, 'rows': [a for k, a in rows if k == '!re'], 'ret': next((a.get('ret') for k, a in rows if k == '!done'), None), 'trap': traps}


def nas_link(state):
    """drill: take the CHR lab-network TAP (wp8tap1) down / up - the NAS becomes unreachable from FreeRADIUS and the
    application; the PPPoE / Hotspot segments are untouched"""
    assert state in ('up', 'down')
    p = sh(['ip', 'link', 'set', 'wp8tap1', state])
    return {'ok': p.returncode == 0, 'state': state}


def fr(action):
    assert action in ('pause', 'unpause')
    p = sh(['docker', action, FR])
    return {'ok': p.returncode == 0, 'err': p.stderr.strip()[-200:]}


def fr_log(pattern, since=None, limit=200):
    """matching FreeRADIUS log lines, newest last. limit: how many of the newest matches (default 200, max 5000); the
    permanent PPPoE session guard (WP8 A1) logs many post-auth lines per PPPoE request, so request parsers ask for more."""
    args = ['docker', 'logs', FR] + (['--since', since] if since else [])
    p = sh(args, timeout=60)
    lines = [l for l in (p.stdout + p.stderr).splitlines() if re.search(pattern, l)]
    return {'lines': [_redact(l) for l in lines[-max(1, min(int(limit), 5000)):]]}


def _lab_secrets():
    """secrets.env plus the OD-W8-16 checkrad gate SNMP community (snmp.env, created later by checkrad_gate.py)"""
    vals = set(SEC.values())
    if os.path.exists(f'{STATE}/snmp.env'):
        vals |= {l.strip().split('=', 1)[1] for l in open(f'{STATE}/snmp.env') if '=' in l}
    return vals


def _redact(s):
    for v in sorted({v for v in _lab_secrets() if len(v) >= 6}, key=len, reverse=True):
        s = s.replace(v, '[REDACTED]')
    return re.sub(r'(User-Password|Cleartext-Password|CHAP-Password)\s*=\s*\S+', r'\1 = [REDACTED]', s)


def capture_start(name, bpf='udp port 1812 or udp port 1813 or udp port 3799'):
    os.makedirs(f'{STATE}/pcap', mode=0o700, exist_ok=True)
    f = f'{STATE}/pcap/{name}.pcap'
    p = subprocess.Popen(['tcpdump', '-i', 'wp8lab0', '-nn', '-U', '-w', f, bpf], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    CAPS[name] = (p, f); time.sleep(1.0)
    return {'ok': p.poll() is None}


RCODES = {1: 'Access-Request', 2: 'Access-Accept', 3: 'Access-Reject', 4: 'Accounting-Request', 5: 'Accounting-Response',
          40: 'Disconnect-Request', 41: 'Disconnect-ACK', 42: 'Disconnect-NAK', 43: 'CoA-Request', 44: 'CoA-ACK', 45: 'CoA-NAK'}


def capture_stop(name):
    p, f = CAPS.pop(name)
    time.sleep(1.0); p.send_signal(signal.SIGINT); p.wait(10)
    data = open(f, 'rb').read(); off = 24; out = []
    while off + 16 <= len(data):
        ts, us, incl, _ = struct.unpack('<IIII', data[off:off + 16]); pkt = data[off + 16:off + 16 + incl]; off += 16 + incl
        if len(pkt) < 42 or struct.unpack('!H', pkt[12:14])[0] != 0x0800 or pkt[23] != 17: continue
        ihl = (pkt[14] & 0x0f) * 4; udp = pkt[14 + ihl:]; sport, dport = struct.unpack('!HH', udp[:4]); r = udp[8:]
        if len(r) < 20: continue
        ln = struct.unpack('!H', r[2:4])[0]; a = r[20:ln]; types = []; cause = None; i = 0
        while i + 2 <= len(a):
            t, l = a[i], a[i + 1]
            if t == 101 and l == 6: cause = struct.unpack('!I', a[i + 2:i + 6])[0]
            types.append(t); i += max(l, 2)
        out.append({'t': round(ts + us / 1e6, 3), 't_us': ts * 1_000_000 + us, 'src': '.'.join(map(str, pkt[26:30])) + f':{sport}', 'dst': '.'.join(map(str, pkt[30:34])) + f':{dport}',
                    'code': RCODES.get(r[0], r[0]), 'id': r[1], 'message_authenticator': 80 in types, 'error_cause': cause, 'attrs': types})
    return {'packets': out, 'file': os.path.basename(f)}


def checkrad_state():
    """OD-W8-16 checkrad gate state (read-only; never contains the community)"""
    import checkrad_gate
    return json.loads(_redact(json.dumps(checkrad_gate.state())))


def checkrad_trace(user):
    """the checkrad runs FreeRADIUS made for $user (trace wrapper lines) plus the session-section decisions"""
    lines = fr_log(r'\[wp8-checkrad\] .*user=' + re.escape(user) + r' |checkrad: |Check-TS|Failed to check the terminal server|Multiple logins|delete_stale|snmpwalk|-r: not found')['lines']
    return {'lines': lines}


def option_g(cmd, arg=None):
    """OD-W8-16 Option G gate switches that do NOT restart FreeRADIUS (fault | nasb | state); result redacted"""
    import option_g_gate
    if cmd == 'fault':
        res = option_g_gate.fault(arg)
    elif cmd == 'nasb':
        res = option_g_gate.nasb(arg[0], arg[1] if len(arg) > 1 else None)
    else:
        res = option_g_gate.state()
    return json.loads(_redact(json.dumps(res)))


def guard_invoke(argv):
    """runs the candidate guard directly as the FreeRADIUS user with an exact argv (no shell)"""
    assert isinstance(argv, list) and all(isinstance(a, str) for a in argv)
    t0 = time.time()
    p = sh(['docker', 'exec', '-u', 'freerad', FR, '/etc/freeradius/nuxr/nuxr_pppoe_session_guard', *argv], timeout=30)
    return {'rc': p.returncode, 'out': _redact(p.stdout), 'err': _redact(p.stderr)[-300:], 'ms': int((time.time() - t0) * 1000)}


def guard_selfcheck(nas=None):
    """TD-1b: the guard readiness check (install/freeradius/bin/nuxr_guard_selfcheck) as the FreeRADIUS user; it never
    prints a community, and the output is redacted anyway"""
    nas = nas or []
    assert isinstance(nas, list) and all(isinstance(a, str) for a in nas)
    t0 = time.time()
    p = sh(['docker', 'exec', '-u', 'freerad', FR, '/etc/freeradius/nuxr/nuxr_guard_selfcheck', *nas], timeout=60)
    return {'rc': p.returncode, 'out': _redact(p.stdout), 'err': _redact(p.stderr)[-300:], 'ms': int((time.time() - t0) * 1000)}


def fr_canaries():
    """files a successful shell injection would have created inside the FreeRADIUS container"""
    p = sh(['docker', 'exec', FR, 'find', '/tmp', '/etc/freeradius', '-name', 'nuxr_pwn*'])
    return {'found': [l for l in p.stdout.splitlines() if l]}


# ---------------------------------------------------------------- WP8 A2: concurrent first-login race (lab only)
def _ros_clock(r):
    """host realtime before/after one RouterOS /system/clock read (RouterOS clock resolution: 1 s)"""
    t0 = time.time_ns(); rows = [a for k, a in r.talk(['/system/clock/print']) if k == '!re']; t1 = time.time_ns()
    c = rows[0] if rows else {}
    return {'host_before_ns': t0, 'host_after_ns': t1, 'ros_date': c.get('date'), 'ros_time': c.get('time'), 'ros_tz': c.get('time-zone-name')}


def pppoe_race(clients, user, password, offset_ms=0, observe=20, interval=0.15, ping_interval=0.2):
    """Two independent PPPoE logins of the SAME username from two client namespaces, released together by a barrier
    (second one offset_ms later), observed in parallel by independent threads. Every timestamp is host CLOCK_REALTIME
    in ns (time.time_ns) - the same clock tcpdump stamps the pcap with on this host (microsecond resolution) - so the
    pcap, the client events and the NAS samples share one time base without conversion. RouterOS clock readings
    (1 s resolution) are bracketed by host time for the coarse NAS-log correlation. Sessions are LEFT UP: the caller
    inspects and then calls pppoe_down for each client (always, also after a failed assertion)."""
    import threading
    assert len(clients) == 2 and clients[0] != clients[1] and all(c in CLIENTS for c in clients)
    assert 0 <= int(offset_ms) <= 30000 and 5 <= float(observe) <= 120 and 0.05 <= float(interval) <= 1.0
    for c in clients:
        pppoe_down(c)
    tpl = open(os.path.dirname(os.path.abspath(__file__)) + '/pppd-client.opts.tpl').read()
    prep = {}
    for c in clients:
        ns, nic, unit = CLIENTS[c]
        d = f'{STATE}/run/{c}'; os.makedirs(d, mode=0o700, exist_ok=True)
        log = f'{d}/pppd.log'; open(log, 'w').close()
        opts = f'{d}/pppd.opts'
        with open(opts, 'w') as f:
            os.chmod(opts, 0o600)
            f.write(tpl.replace('@NIC@', nic).replace('@UNIT@', str(unit)).replace('@LOG@', log).replace('@USER@', user).replace('@PASSWORD@', password))
        prep[c] = (ns, unit, opts, log)
    sampler_ros = ros()
    clock_before = _ros_clock(sampler_ros)
    stop = threading.Event()
    samples = []
    out = {c: {'launch_ns': None, 'popen_ns': None, 'ip': None, 'ip_up_ns': None, 'ip_down_ns': None, 'ping_ok_ns': [], 'ping_sent': 0, 'pppd_exit': None} for c in clients}

    def sampler():
        while not stop.is_set():
            t0 = time.time_ns()
            try:
                rows = [a for k, a in sampler_ros.talk(['/ppp/active/print', '?name=' + user]) if k == '!re']
                err = None
            except Exception as e:  # recorded as a gap, never hidden
                rows, err = [], type(e).__name__
            t1 = time.time_ns()
            samples.append({'t0_ns': t0, 't1_ns': t1, 'n': len(rows), 'err': err,
                            'rows': [{k: x.get(k) for k in ('.id', 'session-id', 'caller-id', 'address', 'uptime', 'service', 'radius')} for x in rows]})
            stop.wait(float(interval))

    barrier = threading.Barrier(2)
    base = {}

    def launch(i, c):
        ns, unit, opts, log = prep[c]
        barrier.wait()
        if i == 0:
            base['t'] = time.monotonic_ns() + 300_000_000           # common release point, 0.3 s ahead
        while 't' not in base:
            time.sleep(0.0005)
        target = base['t'] + i * int(offset_ms) * 1_000_000
        while time.monotonic_ns() < target:                       # busy-wait the last stretch for a tight release
            rem = target - time.monotonic_ns()
            if rem > 2_000_000:
                time.sleep(rem / 2e9)
        out[c]['launch_ns'] = time.time_ns()
        PPPD[c] = subprocess.Popen(['ip', 'netns', 'exec', ns, 'pppd', 'file', opts], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        out[c]['popen_ns'] = time.time_ns()
        stop.wait(1.0)                                             # this client's pppd has read its options by now
        try: os.unlink(opts)
        except FileNotFoundError: pass

    def client_watch(c):
        ns, unit, opts, log = prep[c]
        ifn = f'ppp{unit}'
        pinger = None
        while not stop.is_set():
            p = PPPD.get(c)
            ip = addr(ns, ifn) if p is not None else None
            now = time.time_ns()
            if ip and out[c]['ip_up_ns'] is None:
                out[c]['ip'], out[c]['ip_up_ns'] = ip, now
                pinger = subprocess.Popen(['ip', 'netns', 'exec', ns, 'ping', '-D', '-n', '-I', ifn, '-i', str(ping_interval), '-W', '1', '10.88.10.1'],
                                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
            if out[c]['ip_up_ns'] is not None and not ip and out[c]['ip_down_ns'] is None:
                out[c]['ip_down_ns'] = now
            if p is not None and p.poll() is not None and out[c]['pppd_exit'] is None:
                out[c]['pppd_exit'] = [p.returncode, now]
            stop.wait(0.05)
        if pinger is not None:
            pinger.send_signal(signal.SIGINT)
            try:
                txt = pinger.communicate(timeout=5)[0]
            except subprocess.TimeoutExpired:
                pinger.kill(); txt = pinger.communicate()[0]
            for l in txt.splitlines():
                m = re.match(r'\[(\d+)\.(\d+)\] \d+ bytes from 10\.88\.10\.1: icmp_seq=(\d+)', l)
                if m:
                    out[c]['ping_ok_ns'].append(int(m.group(1)) * 10**9 + int(m.group(2).ljust(9, '0')[:9]))
            m = re.search(r'(\d+) packets transmitted', txt)
            out[c]['ping_sent'] = int(m.group(1)) if m else 0

    threads = [threading.Thread(target=sampler, daemon=True)]
    threads += [threading.Thread(target=launch, args=(i, c), daemon=True) for i, c in enumerate(clients)]
    threads += [threading.Thread(target=client_watch, args=(c,), daemon=True) for c in clients]
    t_start = time.time_ns()
    for t in threads:
        t.start()
    # each launch thread removes its own options file 1 s after ITS pppd started (a delayed second client must still
    # find its file: removing both after a fixed 1.5 s broke offsets >= 1.5 s - WP8 A2 tooling defect, fixed)
    stop.wait(float(observe) + int(offset_ms) / 1000.0)
    stop.set()
    for t in threads:
        t.join(15)
    for c in clients:                                             # never leave a password file behind
        try: os.unlink(prep[c][2])
        except FileNotFoundError: pass
    clock_after = _ros_clock(sampler_ros)
    sampler_ros.close()

    # ---- overlap from independent sources (all host realtime ns)
    gaps = [b['t0_ns'] - a['t1_ns'] for a, b in zip(samples, samples[1:])]
    doubles = [s for s in samples if s['n'] >= 2]
    a, b = clients
    def alive(c):
        o = out[c]
        return (o['ip_up_ns'], o['ip_down_ns'] or (o['pppd_exit'][1] if o['pppd_exit'] else None) or time.time_ns()) if o['ip_up_ns'] else None
    ia, ib = alive(a), alive(b)
    client_overlap = [max(ia[0], ib[0]), min(ia[1], ib[1])] if ia and ib and max(ia[0], ib[0]) < min(ia[1], ib[1]) else None
    pa, pb = out[a]['ping_ok_ns'], out[b]['ping_ok_ns']
    ping_pairs = sum(1 for x in pa if any(abs(x - y) <= 500_000_000 for y in pb))     # replies on BOTH links within 0.5 s
    sess_ids = sorted({r.get('session-id') for s in samples for r in s['rows'] if r.get('session-id')})
    return {'user': user, 'offset_ms': int(offset_ms), 'clients': out, 'launch_skew_ns': (out[b]['launch_ns'] or 0) - (out[a]['launch_ns'] or 0),
            'popen_ns': {c: out[c]['popen_ns'] for c in clients}, 't_start_ns': t_start, 'ros_clock': [clock_before, clock_after],
            'samples': samples, 'sample_count': len(samples), 'max_gap_ms': round(max(gaps) / 1e6, 1) if gaps else None,
            'mean_api_ms': round(sum(s['t1_ns'] - s['t0_ns'] for s in samples) / max(1, len(samples)) / 1e6, 1),
            'sample_errors': sum(1 for s in samples if s['err']),
            'nas_max': max([s['n'] for s in samples] or [0]), 'nas_double_samples': len(doubles),
            'nas_double_first_ns': doubles[0]['t0_ns'] if doubles else None, 'nas_double_last_ns': doubles[-1]['t1_ns'] if doubles else None,
            'nas_session_ids': sess_ids, 'client_overlap_ns': client_overlap, 'ping_ok': {c: len(out[c]['ping_ok_ns']) for c in clients},
            'ping_pairs_within_500ms': ping_pairs, 'logs': {c: _pppd_log(open(prep[c][3]).read()) for c in clients}}

OPS = {'option_g': option_g, 'guard_invoke': guard_invoke, 'guard_selfcheck': guard_selfcheck,'fr_canaries': fr_canaries, 'checkrad_state': checkrad_state, 'checkrad_trace': checkrad_trace, 'ping': lambda: {'ok': True, 'time': time.time()}, 'pppoe_up': pppoe_up, 'pppoe_start': pppoe_start, 'pppoe_race': pppoe_race, 'watch_active': watch_active, 'pppoe_down': pppoe_down, 'pppoe_state': pppoe_state,
       'pppoe_wait_down': pppoe_wait_down, 'hs_dhcp': hs_dhcp, 'hs_login': hs_login, 'hs_logout': hs_logout, 'http_probe': http_probe,
       'traffic': traffic, 'traffic_mixed': traffic_mixed, 'ros': ros_cmd, 'fr': fr, 'nas_link': nas_link, 'fr_log': fr_log, 'capture_start': capture_start, 'capture_stop': capture_stop}


def main():
    for d in ('req', 'res'): os.makedirs(f'{CTL}/{d}', exist_ok=True)
    os.chmod(CTL, 0o777); os.chmod(f'{CTL}/req', 0o777); os.chmod(f'{CTL}/res', 0o777)
    log = open(f'{STATE}/agent.log', 'a', buffering=1)
    log.write(f'{time.strftime("%FT%TZ", time.gmtime())} agent start pid {os.getpid()}\n')
    while True:
        for path in sorted(glob.glob(f'{CTL}/req/*.json')):
            rid = os.path.basename(path)[:-5]
            try:
                req = json.load(open(path)); os.unlink(path)
                op, args = req['op'], req.get('args', {})
                t0 = time.time(); res = {'ok_call': True, 'result': OPS[op](**args)}
            except Exception as e:  # reported to the test, never swallowed
                res = {'ok_call': False, 'error': f'{type(e).__name__}: {e}', 'trace': traceback.format_exc()[-600:]}
                op, t0 = locals().get('op', '?'), time.time()
            log.write(f'{time.strftime("%FT%TZ", time.gmtime())} {op} {round(time.time() - t0, 2)}s ok={res.get("ok_call")}\n')
            tmp = f'{CTL}/res/.{rid}.tmp'; json.dump(res, open(tmp, 'w')); os.chmod(tmp, 0o666); os.rename(tmp, f'{CTL}/res/{rid}.json')
        time.sleep(0.15)


if __name__ == '__main__':
    main()
