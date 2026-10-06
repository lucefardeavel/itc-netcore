#!/usr/bin/env python3
"""WP8 lab: deterministic configuration of a FRESH CHR (blank admin password) over the API at 127.0.0.1:18728
(QEMU user-mode management forward). Lab tooling only. Secrets come from $NUXR_WP8_STATE/secrets.env; none is printed.

Topology (see dev/wp8/README.md):
  ether1  QEMU user-mode management (restrict=on, only 127.0.0.1:18728 -> 8728)
  ether2  10.88.0.2/24   lab network wp8lab0 (FreeRADIUS + application 10.88.0.10, traffic server 10.88.0.40)
  ether3  PPPoE server "nuxr_wp8" (Linux bridge wp8br1, client namespaces wp8c1 / wp8c3)
  ether4  10.88.2.1/24   Hotspot "nuxr_wp8_hs" + DHCP (Linux bridge wp8br2, client namespaces wp8c2 / wp8c4)"""
import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from rosapi import Ros

STATE = os.environ.get('NUXR_WP8_STATE', '/var/lib/nuxr-wp8')
sec = dict(l.strip().split('=', 1) for l in open(f'{STATE}/secrets.env') if '=' in l and not l.startswith('#'))
pw = sec['NUXR_WP8_CHR_PASSWORD']

ros = Ros('127.0.0.1', 18728)
try:
    ros.login('admin', '')
    uid = [a['.id'] for a in ros.rows(['/user/print']) if a.get('name') == 'admin'][0]
    ros.rows(['/user/set', '=.id=' + uid, '=password=' + pw])
    ros.close(); ros = Ros('127.0.0.1', 18728).login('admin', pw)
    print('admin: blank factory password replaced by the lab password')
except PermissionError:
    ros = Ros('127.0.0.1', 18728).login('admin', pw)
    print('admin: lab password already set')

STEPS = [
    ['/system/identity/set', '=name=nuxr_wp8_chr'],
    ['/ip/address/add', '=address=10.88.0.2/24', '=interface=ether2', '=comment=nuxr_wp8'],
    ['/ip/address/add', '=address=10.88.2.1/24', '=interface=ether4', '=comment=nuxr_wp8'],
    # PPPoE: two RADIUS-selectable pools; the profile assigns NO remote address, so Framed-Pool / Framed-IP-Address
    # from RADIUS are the only address source (a profile pool would mask the RADIUS decision)
    ['/ip/pool/add', '=name=nuxr_wp8_pool_dyn', '=ranges=10.88.10.100-10.88.10.149'],
    ['/ip/pool/add', '=name=nuxr_wp8_pool_dyn2', '=ranges=10.88.10.150-10.88.10.199'],
    ['/ip/pool/add', '=name=nuxr_wp8_pool_hs', '=ranges=10.88.2.100-10.88.2.199'],
    ['/ppp/profile/add', '=name=nuxr_wp8_pppoe', '=local-address=10.88.10.1'],
    ['/interface/pppoe-server/server/add', '=service-name=nuxr_wp8', '=interface=ether3', '=default-profile=nuxr_wp8_pppoe',
     '=authentication=chap', '=one-session-per-host=no', '=disabled=no'],
    ['/ppp/aaa/set', '=use-radius=yes', '=accounting=yes', '=interim-update=1m'],
    ['/radius/add', '=address=10.88.0.10', '=secret=' + sec['NUXR_RADIUS_SECRET'], '=service=ppp,hotspot',
     '=src-address=10.88.0.2', '=timeout=3000ms', '=comment=nuxr_wp8_freeradius'],
    # OD-W8-3 A: the CoA / Disconnect port is nas.ports = 3799 (RouterOS factory default would be 1700)
    ['/radius/incoming/set', '=accept=yes', '=port=3799'],
    ['/ip/dhcp-server/network/add', '=address=10.88.2.0/24', '=gateway=10.88.2.1', '=comment=nuxr_wp8'],
    ['/ip/dhcp-server/add', '=name=nuxr_wp8_dhcp', '=interface=ether4', '=address-pool=nuxr_wp8_pool_hs', '=lease-time=10m', '=disabled=no'],
    # Hotspot: explicit auth method http-chap only (no PAP, no MAC / cookie / trial login that could bypass RADIUS)
    ['/ip/hotspot/profile/add', '=name=nuxr_wp8_hs_prof', '=hotspot-address=10.88.2.1', '=login-by=http-chap',
     '=use-radius=yes', '=radius-accounting=yes', '=radius-interim-update=1m'],
    ['/ip/hotspot/add', '=name=nuxr_wp8_hs', '=interface=ether4', '=address-pool=nuxr_wp8_pool_hs', '=profile=nuxr_wp8_hs_prof', '=disabled=no'],
    # RADIUS (Simultaneous-Use) is the session-count authority: the local default user profile must not cap first
    ['/ip/hotspot/user/profile/set', '=numbers=default', '=shared-users=10'],
    ['/ip/service/set', '=numbers=api', '=address=10.88.0.0/24,10.0.2.0/24'],
]
for s in STEPS:
    ros.rows(s)
    print(f'  {s[0]:40s} OK')
# no local subscriber may mask RADIUS: no PPP secret, and the only Hotspot user is the factory "default-trial" (a
# fixed RouterOS entry that can be neither removed nor disabled); it is inert because "trial" is not a login method
assert ros.rows(['/ppp/secret/print']) == []
assert [u.get('name') for u in ros.rows(['/ip/hotspot/user/print'])] == ['default-trial']
assert 'trial' not in ros.rows(['/ip/hotspot/profile/print', '?name=nuxr_wp8_hs_prof'])[0].get('login-by', '')
res = ros.rows(['/system/resource/print'])[0]
print(json.dumps({'version': res.get('version'), 'architecture': res.get('architecture-name'), 'board': res.get('board-name'),
                  'license': ros.rows(['/system/license/print'])[0].get('level'),
                  'radius': {k: ros.rows(['/radius/print'])[0].get(k) for k in ('address', 'service', 'src-address', 'require-message-auth')},
                  'incoming': ros.rows(['/radius/incoming/print'])[0]}))
