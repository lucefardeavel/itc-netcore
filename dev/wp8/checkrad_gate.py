#!/usr/bin/env python3
"""WP8 lab only - OD-W8-16 Option F checkrad GATE switches (owner second resolution round, sections 4-14).
Development/test only; never production. Operates on the RUNNING lab (dev/wp8/lab.sh up) and is undone by lab.sh down.

  checkrad_gate.py on            RouterOS SNMP read-only (random test-only community, source 10.88.0.10 only),
                                 net-snmp in the disposable FreeRADIUS container, nas row 1 -> type mikrotik_snmp +
                                 community, naspasswd derived from the nas table (0600 freerad), gate marker
                                 (start.sh: delete_stale_sessions = no, checkrad program paths, trace wrapper), restart
  checkrad_gate.py nas-type T    nas row 1 type := T (mikrotik_snmp | other), naspasswd re-derived, FreeRADIUS restart
  checkrad_gate.py fault F       snmpwalk-missing | checkrad-error | restore (verification failure inside FreeRADIUS)
  checkrad_gate.py state         gate state (never prints the community)
  checkrad_gate.py off           undo everything above

The community lives only in $NUXR_WP8_STATE/snmp.env (0600), the nas row of the disposable lab database and the
container's naspasswd; it is never printed, logged or committed (client_agent.py redacts it). FreeRADIUS reads NAS
clients (nas.type) only at start, hence the restarts; run these switches between PHPUnit runs, never during one
(the runner shares the FreeRADIUS network namespace)."""
import json, os, secrets, subprocess, sys, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from rosapi import Ros

STATE = os.environ.get('NUXR_WP8_STATE', '/var/lib/nuxr-wp8')
FR = 'nuxbill-reseller-test-freeradius-wp8-1'
DB = 'nuxbill-reseller-test-mariadb-1'
R = '/etc/freeradius'
TAG = 'nuxr_wp8_snmp'


def sh(args, inp=None, check=True):
    p = subprocess.run(args, input=inp, capture_output=True, text=True, timeout=300)
    if check and p.returncode != 0:
        raise RuntimeError(f'{args[:3]} rc={p.returncode} {p.stderr.strip()[-300:]}')
    return p


def community():
    f = f'{STATE}/snmp.env'
    if not os.path.exists(f):
        old = os.umask(0o077)
        try:
            open(f, 'w').write('NUXR_WP8_SNMP_COMMUNITY=wp8' + secrets.token_hex(12) + '\n')
        finally:
            os.umask(old)
    os.chmod(f, 0o600)
    return open(f).read().strip().split('=', 1)[1]


def ros():
    sec = dict(l.strip().split('=', 1) for l in open(f'{STATE}/secrets.env') if '=' in l)
    return Ros('127.0.0.1', 18728).login('admin', sec['NUXR_WP8_CHR_PASSWORD'])


def sql(q):
    return sh(['docker', 'exec', '-i', DB, 'sh', '-c', 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -B nuxr_radius'], q).stdout


def naspasswd():
    """derived from the disposable NAS configuration: one 'nasname SNMP community' line per mikrotik_snmp NAS"""
    rows = [l.split('\t') for l in sql("SELECT nasname, community FROM nas WHERE type = 'mikrotik_snmp' AND COALESCE(community, '') <> '' ORDER BY id").splitlines() if l]
    body = ''.join(f'{n} SNMP {c}\n' for n, c in rows)
    sh(['docker', 'exec', '-i', FR, 'sh', '-c', f'umask 077; cat > {R}/naspasswd; chown freerad:freerad {R}/naspasswd; chmod 0600 {R}/naspasswd'], body)
    return len(rows)


def restart():
    t = time.strftime('%Y-%m-%dT%H:%M:%S', time.gmtime(time.time() - 1))
    sh(['docker', 'restart', FR])
    for _ in range(60):
        if 'Ready to process requests' in sh(['docker', 'logs', '--since', t, FR], check=False).stdout:
            break
        time.sleep(1)
    else:
        raise RuntimeError('FreeRADIUS did not become ready')
    return [l for l in sh(['docker', 'logs', '--since', t, FR], check=False).stdout.splitlines() if l.startswith('[wp8]')]


def on():
    c = community()
    r = ros()
    pub = [x for x in r.rows(['/snmp/community/print']) if x.get('name') == 'public']
    for x in pub:
        r.rows(['/snmp/community/set', '=.id=' + x['.id'], '=disabled=yes'])
    if not [x for x in r.rows(['/snmp/community/print']) if x.get('name') == c]:
        r.rows(['/snmp/community/add', '=name=' + c, '=addresses=10.88.0.10/32', '=read-access=yes', '=write-access=no', '=security=none'])
    if not [x for x in r.rows(['/ip/firewall/filter/print']) if x.get('comment') == TAG]:
        r.rows(['/ip/firewall/filter/add', '=chain=input', '=protocol=udp', '=dst-port=161', '=src-address=!10.88.0.10', '=action=drop', '=comment=' + TAG])
    r.rows(['/snmp/set', '=enabled=yes'])
    if sh(['docker', 'exec', FR, 'sh', '-c', 'command -v snmpwalk'], check=False).returncode != 0:
        sh(['docker', 'exec', FR, 'sh', '-c', 'apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends snmp >/dev/null'])
    sql(f"UPDATE nas SET type = 'mikrotik_snmp', community = '{c}' WHERE id = 1")
    n = naspasswd()
    # owner decisions TD-1 / TD-1b (2026-10-28): the permanent PPPoE session guard runs after this stock layer in every
    # configuration, so the gate runs it as production does - WITH its credential file. Without it every verification
    # case was refused for "no credentials" and never for the fault under test.
    import option_g_gate
    g = option_g_gate.creds()
    sh(['docker', 'exec', FR, 'touch', f'{R}/wp8-checkrad-gate'])
    return {'naspasswd_entries': n, 'guard_credential_entries': g, 'start': restart(), **state()}


def nas_type(t):
    assert t in ('mikrotik_snmp', 'other')
    sql(f"UPDATE nas SET type = '{t}' WHERE id = 1")
    return {'naspasswd_entries': naspasswd(), 'start': restart(), **state()}


def fault(f):
    """verification infrastructure failures INSIDE FreeRADIUS (the router-side SNMP loss is a RouterOS firewall rule):
    snmpwalk-missing  the SNMP client cannot run (checkrad's walk returns nothing)
    checkrad-error    naspasswd entry without the SNMP marker -> the stock mikrotik_snmp method itself returns 2 (error)
    restore           both undone (naspasswd re-derived from the nas table)"""
    assert f in ('snmpwalk-missing', 'checkrad-error', 'restore')
    sh(['docker', 'exec', FR, 'chmod', '000' if f == 'snmpwalk-missing' else '755', '/usr/bin/snmpwalk'])
    if f == 'checkrad-error':
        sh(['docker', 'exec', FR, 'sed', '-i', 's/ SNMP / nuxr_not_snmp /', f'{R}/naspasswd'])
    elif f == 'restore':
        naspasswd()
    return state()


def state():
    r = ros()
    snmp = r.rows(['/snmp/print'])[0]
    comms = [{k: x.get(k) for k in ('addresses', 'read-access', 'write-access', 'disabled', 'default')} | {'name': 'public' if x.get('name') == 'public' else '[test-only]'}
             for x in r.rows(['/snmp/community/print'])]
    fr = sh(['docker', 'exec', FR, 'sh', '-c',
             f'test -f {R}/wp8-checkrad-gate && echo marker=1 || echo marker=0; test -x /usr/bin/snmpwalk && echo snmpwalk_exec=1 || echo snmpwalk_exec=0; '
             f'grep -E "^checkrad = " {R}/radiusd.conf; awk \'{{print "naspasswd_entry=" $1 " " $2 " [community]"}}\' {R}/naspasswd 2>/dev/null; grep -E "delete_stale_sessions" {R}/mods-enabled/sql; stat -c "naspasswd=%a %U" {R}/naspasswd 2>/dev/null || echo naspasswd=missing; '
             f'stat -c "guard_creds=%a %U" {R}/nuxr/nas_snmp 2>/dev/null || echo guard_creds=missing; '
             'dpkg-query -W -f="snmp_pkg=\\${Version}\\n" snmp 2>/dev/null || echo snmp_pkg=none'], check=False).stdout
    return {'nas': sql("SELECT id, nasname, type, IF(COALESCE(community, '') = '', 'unset', 'set') FROM nas ORDER BY id").split('\n')[:2],
            'routeros_snmp': {'enabled': snmp.get('enabled')}, 'routeros_communities': comms,
            'routeros_snmp_firewall': [x.get('src-address') + ' ' + x.get('action') for x in r.rows(['/ip/firewall/filter/print']) if x.get('comment') == TAG],
            'freeradius': [l.strip().replace('\t', ' ') for l in fr.splitlines() if l.strip()]}


def off():
    sql("UPDATE nas SET type = 'other', community = NULL WHERE id = 1")
    import option_g_gate      # the guard credential file that on() installed goes too (review 2026-10-28)
    option_g_gate.drop_creds()
    sh(['docker', 'exec', FR, 'sh', '-c', f'rm -f {R}/naspasswd {R}/wp8-checkrad-gate; chmod 755 /usr/bin/snmpwalk 2>/dev/null; true'])
    start = restart()
    r = ros()
    c = community()
    for x in r.rows(['/snmp/community/print']):
        if x.get('name') == c:
            r.rows(['/snmp/community/remove', '=.id=' + x['.id']])
        elif x.get('name') == 'public':
            r.rows(['/snmp/community/set', '=.id=' + x['.id'], '=disabled=no'])
    for x in r.rows(['/ip/firewall/filter/print']):
        if x.get('comment') == TAG:
            r.rows(['/ip/firewall/filter/remove', '=.id=' + x['.id']])
    r.rows(['/snmp/set', '=enabled=no'])
    return {'start': start, **state()}


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'state'
    out = {'on': on, 'off': off, 'state': state}.get(cmd)
    res = out() if out else nas_type(sys.argv[2]) if cmd == 'nas-type' else fault(sys.argv[2]) if cmd == 'fault' else sys.exit(__doc__)
    print(json.dumps(res, indent=1))
