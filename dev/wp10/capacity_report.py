#!/usr/bin/env python3
"""Accounting-load results (dev/wp10/acct_load.sh) -> markdown tables.

  dev/wp10/capacity_report.py EVIDENCE_DIR [EVIDENCE_DIR ...]

Each directory holds acct-<engine>-<stamp>/acct_load.jsonl (counters and timings only; no secrets).
"""
import glob
import json
import os
import sys


def rows(d):
    out = []
    for f in sorted(glob.glob(os.path.join(d, '*', 'acct_load.jsonl'))):
        for line in open(f):
            try:
                r = json.loads(line)
            except ValueError:
                continue
            if r.get('case') == 'acct-load':
                out.append(r)
    return out


def main(argv):
    for d in argv:
        print(f'### {d}\n')
        print('| engine | online quota subscribers | interim s | interim updates / s (target / achieved) | lost | latency ms p50 / p95 / p99 / max | enforcer pass s avg / max (every 5 s) | DB questions / s | row-lock waits | max used connections |')
        print('|---|---|---|---|---|---|---|---|---|---|')
        for r in sorted(rows(d), key=lambda x: (x['engine'], x['subscribers'], -x['interim_s'])):
            l = r['latency_ms']
            p = r['enforcer_pass_s']
            print('| {e} | {s} | {i} | {t} / {a} | {lost} | {p50} / {p95} / {p99} / {mx} | {pa} / {pm} | {q} | {lk} | {mc} |'.format(
                e=r['engine'], s=r['subscribers'], i=r['interim_s'], t=r['target_rate_per_s'], a=r['achieved_rate_per_s'], lost=r['lost'],
                p50=l['p50'], p95=l['p95'], p99=l['p99'], mx=l['max'], pa=p['avg'], pm=p['max'], q=r['db']['questions_per_s'],
                lk=r['db']['row_lock_waits'], mc=r['db']['max_used_connections']))
        print()


if __name__ == '__main__':
    main(sys.argv[1:])
