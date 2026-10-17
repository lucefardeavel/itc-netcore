#!/usr/bin/env python3
"""N1: turn the lab evidence rows (V1.n1-overshoot*) into the measured table of owner-review/WP10/N1_PPPOE_OVERSHOOT.md.

  dev/wp10/n1_report.py EVIDENCE_JSONL [--since ISO] [--raw OUT_JSONL]

Prints a markdown table; --raw copies the selected rows (no secrets: counters and timings only) next to the evidence.
"""
import json
import sys


def main(argv):
    path = argv[0]
    since = argv[argv.index('--since') + 1] if '--since' in argv else ''
    raw = argv[argv.index('--raw') + 1] if '--raw' in argv else None
    rows = []
    for line in open(path):
        try:
            d = json.loads(line)
        except ValueError:
            continue
        if str(d.get('case', '')).startswith('V1.n1-overshoot') and d.get('t', '') >= since:
            rows.append(d)
    if raw:
        with open(raw, 'w') as f:
            for d in rows:
                f.write(json.dumps(d, sort_keys=True) + '\n')
    print('| case | interim s | pass s | rate / direction | quota | cut by | t_cross s | t_stop s | latency s | model bound s | used | overshoot | overshoot % of quota |')
    print('|---|---|---|---|---|---|---|---|---|---|---|---|---|')
    for d in rows:
        ended = d.get('ended') or []
        by = 'enforcer (Disconnect CONFIRMED)' if ended and ended[0].get('state') == 'CONFIRMED' else 'NAS native per-direction cap'
        print('| {case} | {i} | {p} | {k} kbit/s | {q:.1f} MB | {by} | {tc} | {ts} | {lat} | {mb} | {u:.2f} MB | {o:.2f} MB | {pc:.0f} % |'.format(
            case=d['case'].replace('V1.n1-overshoot', 'matrix').replace('-failing-disconnect', ' fault: CoA closed 40 s'),
            i=d['interim'], p=d['pass_every'], k=d['kbps_per_direction'], q=d['quota'] / 1e6, by=by, tc=d['t_cross_s'], ts=d['t_stop_s'],
            lat=d['latency_s'], mb=d['model_latency_bound_s'], u=d['used'] / 1e6, o=d['overshoot'] / 1e6, pc=100 * d['overshoot'] / d['quota']))


if __name__ == '__main__':
    main(sys.argv[1:])
