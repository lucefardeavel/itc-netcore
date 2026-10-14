#!/usr/bin/env python3
"""WP8 lab only - S7 cleanup (read-before-delete) on the RUNNING lab CHR (dev/wp8/lab.sh up). Development/test only.

  s7_cleanup.py list     list the S7 objects (never deletes)
  s7_cleanup.py delete   delete exactly the listed S7 objects, then list again (must be empty)

An S7 object is a NON-dynamic RouterOS object whose name / user / comment / profile / remote-address contains
"nuxr_wp8_local" or starts with "nuxr_wp8_s7api", or whose to-addresses / dst-address is in the S7 test block 10.88.250-251.x
(the same rule as tests/Support/Wp8DriverLabCase.php). No other object is ever selected. Prints names only (no secrets)."""
import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from rosapi import Ros

STATE = os.environ.get('NUXR_WP8_STATE', '/var/lib/nuxr-wp8')
PATHS = ['/ppp/active', '/ip/hotspot/active', '/ppp/secret', '/ip/hotspot/user', '/ip/firewall/nat', '/ip/firewall/filter',
         '/ppp/profile', '/ip/hotspot/user/profile', '/ip/pool', '/user', '/user/group']


def is_s7(r):
    for k in ('name', 'user', 'comment', 'profile', 'remote-address'):
        v = r.get(k, '') or ''
        if 'nuxr_wp8_local' in v or v.startswith('nuxr_wp8_s7api'):
            return True
    return any((r.get(k, '') or '').startswith('10.88.25') for k in ('to-addresses', 'dst-address'))


def ros():
    sec = dict(l.strip().split('=', 1) for l in open(f'{STATE}/secrets.env') if '=' in l)
    return Ros('127.0.0.1', 18728).login('admin', sec['NUXR_WP8_CHR_PASSWORD'])


def listing(r):
    out = []
    for p in PATHS:
        for k, a in r.talk([p + '/print']):
            if k == '!re' and is_s7(a) and a.get('dynamic', 'false') != 'true':
                out.append({'path': p, '.id': a['.id'], 'name': a.get('name') or a.get('user') or a.get('comment')})
    return out


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'list'
    r = ros()
    found = listing(r)
    res = {'listed': found}
    if cmd == 'delete':
        res['deleted'] = []
        for o in found:
            traps = [a for k, a in r.talk([o['path'] + '/remove', '=.id=' + o['.id']]) if k == '!trap']
            res['deleted'].append({**o, 'ok': not traps})
        res['remaining'] = listing(r)
    r.close()
    print(json.dumps(res, indent=1))
    sys.exit(0 if cmd == 'list' or not res.get('remaining') else 1)
