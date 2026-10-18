#!/usr/bin/env python3
"""OBS-1 decision sheet from the collision report (D7). Reads identity_report.php --json output; writes a CSV the OWNER completes.

  dev/wp10/obs1_sheet.py report.json sheet.csv
  dev/wp10/obs1_sheet.py selftest

One row per colliding canonical key: the key (masked), the holders, their kinds (owned customer / unassigned customer / voucher), the options, and EMPTY
columns for the owner's choice (who keeps the key, the option R1 - R4, the new name of the other holder, approver / date). The tool never fills the choice
and never ranks the holders: which customer keeps a key is not an engineering decision (owner-review/WP10/OBS1_SURVEY_AND_REMEDIATION_PACKAGE.md).
The report contains customer names / ids: keep it and the sheet on the isolated host, mode 0600, outside Git.
"""
import csv
import json
import os
import re
import sys


def mask(k):
    k = str(k)
    return '****' if len(k) <= 4 else k[:2] + '****' + k[-2:]


def kinds(identities):
    out = []
    for h in str(identities).split(','):
        h = h.strip()
        if h.startswith('customer:'):
            out.append('unassigned customer' if h.endswith('(unassigned)') else 'owned customer')
        elif h.startswith('voucher:'):
            out.append('live voucher')
        elif h == 'voucher-only-recharge':
            out.append('voucher-only recharge')
        else:
            out.append(h)
    return '; '.join(out)


def build(report_path, out_path):
    r = json.load(open(report_path))
    sec = r['sections']
    rows = sec['2']['rows'] if isinstance(sec, dict) else sec[2]['rows']
    with open(out_path, 'w', newline='') as f:
        w = csv.writer(f)
        w.writerow(['key_masked', 'holders', 'identities', 'kinds', 'options', 'OWNER: who keeps the key', 'OWNER: option (R1-R4)', 'OWNER: new name for the other holder', 'OWNER: approved by / date'])
        for x in rows:
            w.writerow([mask(x.get('k', '')), x.get('holders', ''), x.get('identities', ''), kinds(x.get('identities', '')), 'R1 rename / R2 retire / R3 merge (no tool) / R4 rule', '', '', '', ''])
    s = r.get('summary', {})
    print(f"{len(rows)} collision row(s) written to {out_path}; non-canonical names {s.get('non_canonical_names')}, interior-control names {s.get('interior_control_names')}, "
          f"RADIUS keys without exactly one owner {s.get('radius_keys_without_one_owner')}, statements failed {s.get('statements_failed')}")
    os.chmod(out_path, 0o600)


def selftest():
    import tempfile
    d = tempfile.mkdtemp()
    rep = os.path.join(d, 'r.json')
    json.dump({'summary': {'collisions': 2, 'non_canonical_names': 0, 'interior_control_names': 0, 'radius_keys_without_one_owner': 1, 'statements_failed': 0},
               'sections': {'2': {'rows': [{'k': 'alice77', 'holders': 2, 'identities': 'customer:5(owned),customer:9(unassigned)'},
                                           {'k': 'ABCD', 'holders': 2, 'identities': 'customer:7(owned),voucher:3(CLAIMED)'}]}}}, open(rep, 'w'))
    out = os.path.join(d, 's.csv')
    build(rep, out)
    rows = list(csv.reader(open(out)))
    assert rows[1][0] == 'al****77' and rows[2][0] == '****', rows
    assert rows[1][3] == 'owned customer; unassigned customer' and rows[2][3] == 'owned customer; live voucher', rows
    assert all(c == '' for r in rows[1:] for c in r[5:]), 'the owner columns stay empty'
    assert oct(os.stat(out).st_mode & 0o777) == '0o600'
    print('selftest OK')


if __name__ == '__main__':
    if sys.argv[1:] == ['selftest']:
        selftest()
    else:
        build(sys.argv[1], sys.argv[2])
