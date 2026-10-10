import json, re, sys
rows = [dict(kv.split('=') for kv in l.split()) for l in open(sys.argv[1]) if l.startswith('new=')]
out = {'rows': len(rows), 'new': {'malformed_or_negative': 0, 'outside_bracket': 0}, 'old': {'malformed_or_negative': 0, 'outside_bracket': 0, 'below_bracket_by_900_to_1001ms': 0},
       'old_examples': [], 'new_examples_outside': [], 'evaluations_in_first_5ms_after_boundary': 0}
for r in rows:
    arr = int(r['arr'])
    for f, a, b in (('new', 'a', 'b'), ('old', 'c', 'd')):
        v = r[f]
        if not re.fullmatch(r'[0-9]{1,9}', v):
            out[f]['malformed_or_negative'] += 1
        x = int(v)
        lo, hi = (int(r[a]) - arr) // 1000, (int(r[b]) - arr) / 1000
        if not lo <= x <= hi:
            out[f]['outside_bracket'] += 1
            if f == 'old' and 900 <= lo - x <= 1001:
                out[f]['below_bracket_by_900_to_1001ms'] += 1
            ex = out['old_examples' if f == 'old' else 'new_examples_outside']
            if len(ex) < 8:
                ex.append({**r, 'bracket_ms': [lo, hi]})
    if int(r['a']) % 1_000_000 < 5000:
        out['evaluations_in_first_5ms_after_boundary'] += 1
print(json.dumps(out, indent=1))
