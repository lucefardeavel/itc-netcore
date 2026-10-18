#!/usr/bin/env python3
"""O-DL study summary (dev/wp10/deadlock_study.sh -> markdown).

  dev/wp10/deadlock_report.py EVIDENCE_ROOT   (contains <label>-<engine>/deadlock_study.jsonl, phpunit-run*.out, server-deadlocks.txt)

Per (candidate label, engine): operations, refusals and the refusal rate, InnoDB deadlocks in total (absorbed by the bounded retries or not), the runs in which a
refusal occurred, what the refusals left behind (state / reservation / service / ledger), the customer's retry outcome, the operator recovery (stale floor + action time)
and the final accounting check; plus the statement pairs of the deadlocks that the server logged.
"""
import collections
import glob
import json
import os
import re
import sys


def main(root):
    print('| candidate | engine | runs | operations | InnoDB deadlocks | refusals (CONCURRENCY_RETRY) | refusal rate / op | runs with a refusal | failed runs |')
    print('|---|---|---|---|---|---|---|---|---|')
    details = []
    for d in sorted(glob.glob(os.path.join(root, '*-*'))):
        if not os.path.isdir(d) or not os.path.exists(os.path.join(d, 'deadlock_study.jsonl')):
            continue
        label = os.path.basename(d)
        cand, eng = label.rsplit('-', 1)
        waves, helds, recs = [], [], []
        for line in open(os.path.join(d, 'deadlock_study.jsonl')):
            r = json.loads(line)
            if r['case'] == 'wave-summary':
                waves.append(r)
            elif r['case'] == 'held-detail':
                helds.append(r)
            elif r['case'] == 'recovery':
                recs.append(r)
        failed = sum(1 for f in glob.glob(os.path.join(d, 'phpunit-run*.out')) if re.search(r'^(FAILURES|ERRORS)', open(f, errors='replace').read(), re.M))
        ops = sum(w['ops'] for w in waves)
        ref = sum(w['refusals'] for w in waves)
        dls = sum(w['innodb_deadlocks'] for w in waves)
        print(f"| {cand} | {eng} | {len(waves)} | {ops} | {dls} | {ref} | {ref / max(1, ops):.4f} | {sum(1 for w in waves if w['refusals'])} | {failed} |")
        details.append((cand, eng, helds, recs))
    print('\n### What the refusals left behind, the customer retry and the operator recovery\n')
    print('| candidate | engine | held activations | states | reservations | service rows | ledger entries | customer retry | reasons / user message | resume ms (n, avg, max) | cancel ms (n, avg, max) | final: completed = committed = debits, active reservations, non-terminal, integrity |')
    print('|---|---|---|---|---|---|---|---|---|---|---|---|')
    for cand, eng, helds, recs in details:
        rows = [h for x in helds for h in x['held']]
        held = [h for h in rows if h.get('state') not in ('NO_ACTIVATION', 'COMPLETED', 'FAILED', 'CANCELLED')]
        cnt = lambda k: dict(collections.Counter(str(h.get(k)) for h in held))
        msg = collections.Counter((h['reason'], h['user_message']) for h in rows)
        def st(kind):
            v = [m['ms'] for x in recs for m in x[kind]]
            return f'{len(v)}, {sum(v) // max(1, len(v))}, {max(v) if v else 0}'
        fin = [x['final'] for x in recs]
        ok = all(f['completed'] == f['committed_reservations'] == f['activation_debits'] and f['active_reservations'] == 0 and f['non_terminal'] == 0 and f['integrity'] == [] for f in fin)
        retry = collections.Counter(h.get('customer_retry') for h in held)
        print(f"| {cand} | {eng} | {len(held)} (of {len(rows)} refusals) | {cnt('state')} | {cnt('reservations')} | {sum(h.get('service_rows', 0) for h in held)} | {sum(h.get('ledger_entries', 0) for h in held)} | {dict(retry)} | {dict(msg)} | {st('resumed')} | {st('cancelled')} | {'OK' if ok else 'CHECK'} ({len(fin)} runs) |")
    # statement pairs of the deadlocks the server logged
    for d in sorted(glob.glob(os.path.join(root, '*-*', 'server-deadlocks.txt'))):
        txt = open(d, errors='replace').read()
        pairs = collections.Counter()
        for blk in re.split(r'(?i)deadlock detected', txt)[1:]:
            stmts = re.findall(r'^(UPDATE|INSERT INTO|SELECT|DELETE FROM)\s+(\S+)', blk, re.M)
            if len(stmts) >= 2:
                pairs[' x '.join(sorted({f'{a} {b}' for a, b in stmts[:2]}))] += 1
        print(f"\n{os.path.basename(os.path.dirname(d))}: {sum(pairs.values())} logged deadlock(s); statement pairs: {dict(pairs.most_common(5))}")


if __name__ == '__main__':
    main(sys.argv[1])
