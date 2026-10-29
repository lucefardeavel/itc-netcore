#!/usr/bin/env python3
"""WP8 lab only - OD-W8-16 Option G gate tooling on the RUNNING lab. Since WP8 A1 the fail-closed PPPoE session guard
is PERMANENT project configuration (install/freeradius: bin/nuxr_pppoe_session_guard, the named exec module and the
post-auth policy of sites-available/phpnuxbill; installed by dev/wp8/freeradius/start.sh on every start); this tool
only configures what the production contract leaves to the operator - RouterOS SNMP, the NAS rows (type
mikrotik_snmp) and the guard credential file - plus the fault injection used by the tests. The original gate copy
dev/wp8/freeradius/option-g/ is kept as the reference of the 2026-10-07 gate and is not used. Development/test only;
undone by `off` and by dev/wp8/lab.sh down.

  option_g_gate.py on [shipped|functional]
        RouterOS SNMP read-only (random test-only community, source 10.88.0.10 only, `public` disabled), nas row 1
        (the CHR) -> type mikrotik_snmp, nas row 3 (10.88.0.30, the fake second NAS) -> type mikrotik_snmp, guard
        credential file = the PERSISTENT lab file $NUXR_WP8_STATE/radius-secrets/nas_snmp (dir 0700, file 0600; the
        container mounts it read-only and start.sh installs it as /etc/freeradius/nuxr/nas_snmp, 0600 freerad, on
        every start), gate marker (start.sh: stock checkrad as shipped behind the trace wrapper), FreeRADIUS restart.
        net-snmp is part of the FreeRADIUS image (dev/test/freeradius/Dockerfile), never installed at run time.
        shipped    (default) the stock checkrad exactly as shipped in the pinned image (no naspasswd)
        functional lab-only variant: the stock checkrad with its two build-time program paths + naspasswd (the
                   OD-W8-16 Option F lab configuration), to observe the stock layer rejecting first
  option_g_gate.py fault F   F = get-missing | walk-missing | bad-community | no-creds | restore   (no restart)
  option_g_gate.py nasb up|down|ifnames HEX[,HEX...]   fake second NAS 10.88.0.30 (net-snmp snmpd in a disposable
        container of the compose project) serving sysUpTime and a MIKROTIK-MIB mtxrInterfaceStatsName table whose
        names are given in hex (default: lo, ether1)
  option_g_gate.py state | off

The community lives only in $NUXR_WP8_STATE/snmp.env (0600), the disposable lab database, the guard credential file
($NUXR_WP8_STATE/radius-secrets/nas_snmp and its installed copy) and the fake NAS configuration; it is never printed (client_agent.py and redact.py redact it). Restarting FreeRADIUS
kills a running PHPUnit runner (shared network namespace): run `on` between PHPUnit runs; `fault` and `nasb` never
restart FreeRADIUS and are also available to the tests through the lab agent."""
import json, os, sys, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import checkrad_gate as cg

FR, R = cg.FR, cg.R
NASB = 'nuxbill-reseller-test-wp8nasb'
IMG = 'freeradius/freeradius-server:3.2.8@sha256:af6fd34a5b7826b6245008732f43fbc0432eb23d23e5de7106371b2941e70a4b'
IFNAME = '.1.3.6.1.4.1.14988.1.1.14.1.1.2'
DEFAULT_IFNAMES = ['lo'.encode().hex(), 'ether1'.encode().hex()]


SECRETS = os.path.join(cg.STATE, 'radius-secrets')     # mounted read-only at /nuxr/secrets (compose.wp8.yml)
INSTALL = f'install -m 0600 -o freerad -g freerad /nuxr/secrets/nas_snmp {R}/nuxr/nas_snmp'


def creds(community=None):
    """the guard credential file, derived from the disposable lab nas table (type mikrotik_snmp with a community).
    Normal: written to the persistent lab file and installed into the running container (no restart).
    community=...: a deliberately WRONG file for the fault test, written to the running container only - the
    persistent file stays correct, so a restart or a recreated container always gets the right one back."""
    rows = [l.split('\t') for l in cg.sql("SELECT nasname, community FROM nas WHERE type = 'mikrotik_snmp' AND COALESCE(community, '') <> '' ORDER BY id").splitlines() if l]
    body = ''.join(f'{n} {community or c}\n' for n, c in rows)
    if community:
        cg.sh(['docker', 'exec', '-i', FR, 'sh', '-c', f'umask 077; cat > {R}/nuxr/nas_snmp; chown freerad:freerad {R}/nuxr/nas_snmp; chmod 0600 {R}/nuxr/nas_snmp'], body)
        return len(rows)
    old = os.umask(0o077)
    try:
        os.makedirs(SECRETS, exist_ok=True)
        os.chmod(SECRETS, 0o700)
        tmp = os.path.join(SECRETS, '.nas_snmp.new')
        with open(tmp, 'w') as f:
            f.write(body)
        os.chmod(tmp, 0o600)
        os.replace(tmp, os.path.join(SECRETS, 'nas_snmp'))     # atomic, like the documented rotation
    finally:
        os.umask(old)
    cg.sh(['docker', 'exec', FR, 'sh', '-c', INSTALL])
    return len(rows)


def on(stock='shipped'):
    assert stock in ('shipped', 'functional')
    c = cg.community()
    r = cg.ros()
    for x in r.rows(['/snmp/community/print']):
        if x.get('name') == 'public':
            r.rows(['/snmp/community/set', '=.id=' + x['.id'], '=disabled=yes'])
    if not [x for x in r.rows(['/snmp/community/print']) if x.get('name') == c]:
        r.rows(['/snmp/community/add', '=name=' + c, '=addresses=10.88.0.10/32', '=read-access=yes', '=write-access=no', '=security=none'])
    if not [x for x in r.rows(['/ip/firewall/filter/print']) if x.get('comment') == cg.TAG]:
        r.rows(['/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=src-address=!10.88.0.10', '=action=drop', '=comment=' + cg.TAG])
    r.rows(['/snmp/set', '=enabled=yes'])
    if cg.sh(['docker', 'exec', FR, 'sh', '-c', 'test -x /usr/bin/snmpget && test -x /usr/bin/snmpbulkwalk'], check=False).returncode != 0:
        raise RuntimeError('net-snmp missing from the FreeRADIUS image (dev/test/freeradius/Dockerfile): rebuild, never install at run time')
    cg.sql(f"UPDATE nas SET type = 'mikrotik_snmp', community = '{c}' WHERE id = 1")
    cg.sql(f"DELETE FROM nas WHERE id = 3; INSERT INTO nas (id, nasname, shortname, type, ports, secret, community, description, routers) "
           f"SELECT 3, '10.88.0.30', 'nuxr_wp8_nasb', 'mikrotik_snmp', 3799, secret, '{c}', 'WP8 Option G fake second NAS (SNMP only)', '' FROM nas WHERE id = 1")
    n = creds()
    if stock == 'functional':
        cg.naspasswd()
        cg.sh(['docker', 'exec', FR, 'touch', f'{R}/wp8-checkrad-gate'])
    else:
        cg.sh(['docker', 'exec', FR, 'rm', '-f', f'{R}/wp8-checkrad-gate', f'{R}/naspasswd'])
    cg.sh(['docker', 'exec', FR, 'touch', f'{R}/wp8-option-g'])
    return {'stock_checkrad': stock, 'guard_credential_entries': n, 'start': cg.restart(), **state()}


def fault(f):
    """verification failures (no FreeRADIUS restart): the guard re-reads its tools and credential file per run"""
    assert f in ('get-missing', 'walk-missing', 'bad-community', 'no-creds', 'restore')
    cg.sh(['docker', 'exec', FR, 'chmod', '000' if f == 'get-missing' else '755', '/usr/bin/snmpget'])
    cg.sh(['docker', 'exec', FR, 'chmod', '000' if f == 'walk-missing' else '755', '/usr/bin/snmpbulkwalk'])
    if f == 'bad-community':
        creds(community='wp8wrong' + os.urandom(6).hex())
    elif f == 'no-creds':
        cg.sh(['docker', 'exec', FR, 'sed', '-i', '/^10\\.88\\.0\\.2 /d', f'{R}/nuxr/nas_snmp'])
    else:
        creds()
    return state()


def nasb(action, names=None):
    if action == 'down':
        cg.sh(['docker', 'rm', '-f', NASB], check=False)
        return {'nasb': 'down'}
    exists = cg.sh(['docker', 'inspect', NASB], check=False).returncode == 0
    if not exists:
        cg.sh(['docker', 'run', '-d', '--init', '--name', NASB, '--label', 'com.docker.compose.project=nuxbill-reseller-test',
               '--network', 'nuxbill-reseller-test_default', '--entrypoint', 'sleep', IMG, 'infinity'])
        cg.sh(['docker', 'exec', NASB, 'sh', '-c', 'apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends snmpd >/dev/null'])
        cg.sh(['docker', 'network', 'disconnect', 'nuxbill-reseller-test_default', NASB])
        cg.sh(['docker', 'network', 'connect', '--ip', '10.88.0.30', 'nuxbill-reseller-test_wp8lab', NASB])
    hexes = names if names is not None else DEFAULT_IFNAMES
    for h in hexes:
        assert h and all(ch in '0123456789abcdefABCDEF' for ch in h) and len(h) % 2 == 0
    conf = (f'agentaddress udp:10.88.0.30:161\nrocommunity {cg.community()} 10.88.0.10\n'
            + ''.join(f'override {IFNAME}.{i} octet_str 0x{h}\n' for i, h in enumerate(hexes, 1)))
    cg.sh(['docker', 'exec', '-i', NASB, 'sh', '-c', 'umask 077; cat > /etc/snmp/nuxr-nasb.conf'], conf)
    cg.sh(['docker', 'exec', NASB, 'sh', '-c', 'pkill -x snmpd; i=0; while pgrep -x snmpd >/dev/null && [ $i -lt 50 ]; do sleep 0.1; i=$((i+1)); done; '
                                     'snmpd -C -c /etc/snmp/nuxr-nasb.conf -Lf /dev/null -p /run/snmpd.pid; sleep 0.3; test -s /run/snmpd.pid'])
    # ready = the guard itself (as the FreeRADIUS user, community only in its private config) gets past the health probe
    probe = []
    for _ in range(20):
        p = cg.sh(['docker', 'exec', '-u', 'freerad', FR, '/etc/freeradius/nuxr/nuxr_pppoe_session_guard', 'nuxr_probe'.encode().hex(), '1', '10.88.0.30'], check=False)
        probe = [l for l in p.stdout.splitlines() if 'Tmp-String-6' in l]
        if probe and 'ERROR:health' not in probe[0] and 'ERROR:timeout' not in probe[0]:
            break
        time.sleep(0.5)
    return {'nasb': 'up', 'ready': probe, 'ifnames': [bytes.fromhex(h).decode('latin-1') for h in hexes]}


def state():
    st = cg.state()
    fr = cg.sh(['docker', 'exec', FR, 'sh', '-c',
                f'test -f {R}/wp8-option-g && echo option_g=1 || echo option_g=0; test -x /usr/bin/snmpget && echo snmpget_exec=1 || echo snmpget_exec=0; '
                f'test -x /usr/bin/snmpbulkwalk && echo snmpbulkwalk_exec=1 || echo snmpbulkwalk_exec=0; '
                f'sha256sum /usr/sbin/checkrad | cut -c1-64 | sed "s/^/stock_checkrad_sha256=/"; '
                f'stat -c "guard_creds=%a %U" {R}/nuxr/nas_snmp 2>/dev/null || echo guard_creds=missing; '
                f'awk \'{{print "guard_creds_entry=" $1 " [community]"}}\' {R}/nuxr/nas_snmp 2>/dev/null; '
                f'test -x {R}/nuxr/nuxr_pppoe_session_guard && echo guard_installed=1 || echo guard_installed=0; '
                f'test -f {R}/mods-enabled/nuxr_pppoe_session_guard && echo guard_module=1 || echo guard_module=0; '
                f'grep -c "^[[:space:]]*nuxr_pppoe_session_guard {{" {R}/sites-enabled/phpnuxbill | sed "s/^/post_auth_policy=/"; '
                f'grep -c "option-g" {R}/sites-enabled/phpnuxbill | sed "s/^/lab_policy_refs=/"; '
                f'cmp -s /nuxr/install/bin/nuxr_pppoe_session_guard {R}/nuxr/nuxr_pppoe_session_guard && echo guard_is_install_copy=1 || echo guard_is_install_copy=0; '
                f'test -f /nuxr/secrets/nas_snmp && echo persistent_creds=1 || echo persistent_creds=0'], check=False).stdout
    st['freeradius'] += [l.strip() for l in fr.splitlines() if l.strip()]
    st['nas'] = cg.sql("SELECT id, nasname, type, IF(COALESCE(community, '') = '', 'unset', 'set') FROM nas ORDER BY id").split('\n')[:3]
    st['nasb'] = 'up' if cg.sh(['docker', 'inspect', '-f', '{{.State.Running}}', NASB], check=False).stdout.strip() == 'true' else 'down'
    return st


def drop_creds():
    """The guard credential file goes (container copy and the persistent lab copy start.sh reinstalls from)."""
    cg.sh(['docker', 'exec', FR, 'rm', '-f', f'{R}/nuxr/nas_snmp'], check=False)
    f = os.path.join(SECRETS, 'nas_snmp')
    if os.path.exists(f):
        os.remove(f)


def off():
    nasb('down')
    cg.sh(['docker', 'exec', FR, 'sh', '-c', f'rm -f {R}/wp8-option-g {R}/nuxr/nas_snmp; chmod 755 /usr/bin/snmpget /usr/bin/snmpbulkwalk 2>/dev/null; true'])
    f = os.path.join(SECRETS, 'nas_snmp')
    if os.path.exists(f):
        os.remove(f)          # no credentials -> the permanent guard rejects PPPoE logins that meet an open row
    cg.sql('DELETE FROM nas WHERE id = 3')
    return cg.off()


if __name__ == '__main__':
    a = sys.argv[1:] or ['state']
    if a[0] == 'on':
        res = on(*(a[1:2] or ['shipped']))
    elif a[0] == 'fault':
        res = fault(a[1])
    elif a[0] == 'nasb':
        res = nasb(a[1], a[2].split(',') if len(a) > 2 else None)
    elif a[0] in ('state', 'off'):
        res = {'state': state, 'off': off}[a[0]]()
    else:
        sys.exit(__doc__)
    print(json.dumps(res, indent=1))
