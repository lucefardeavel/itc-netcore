#!/usr/bin/env python3
"""N1 analysis tool (test tooling only; pure arithmetic, no network, no database).

Quantifies the PPPoE data-quota overshoot of the V1 mechanisms and the cost of the alternatives, so that the owner can decide
the customer-facing guarantee with numbers instead of a percentage. Outputs Markdown tables.

  n1_overshoot_model.py bound      overshoot bound of "native per-direction cap + accounting enforcer"
  n1_overshoot_model.py split      forced reconnects of the "directional split" alternative (a native hard total)
  n1_overshoot_model.py load       accounting write rate caused by a shorter interim interval

Model (the formulas are validated against the real RouterOS CHR in owner-review/WP10/evidence/quota/n1/):
  quota remaining at login           R   bytes
  combined line rate (up + down)     r   bytes/s   (the plan's rate limit; the worst case a subscriber can reach)
  interim interval                   I   s         (Acct-Interim-Interval, a QuotaPolicy constant)
  enforcer pass interval             P   s         (quota.php --every)
  time from Disconnect-Request to the traffic stopping   c   s (CoA round trip + teardown; measured 0.1 - 1 s)
  the NAS cuts a direction at its limit, on its own timer: +d s of that direction's rate (measured 0.2 - 0.9 s)

  Without the enforcer the NAS caps each direction at R:   total <= 2R + 2 d r_dir      (overshoot <= R + ...)
  With the enforcer the quota is crossed at time t0; the enforcer sees it at the first interim after t0 (<= I later), acts
  at the next pass (<= P later) and the traffic stops c later:
      overshoot <= min(R, r * (I + P + c))
  Under faults the Disconnect is retransmitted twice, 2 s apart (CoAClient): c_fault <= 1 + 4 = 5 s; a NAS that never answers
  leaves only the native caps (overshoot <= R).
"""
import sys

MB = 1_000_000
GB = 1_000_000_000


def fmt(b):
    return f'{b / GB:.2f} GB' if b >= GB else f'{b / MB:.0f} MB'


def bound(R, r, I, P, c):
    return min(R, r * (I + P + c))


def table_bound():
    print('### Overshoot bound = min(R, r x (I + P + c)); c = 1 s normal, 5 s with CoA retransmissions\n')
    plans = [('10 / 10 Mbps', 2 * 10e6 / 8), ('25 / 25 Mbps', 2 * 25e6 / 8), ('50 / 50 Mbps', 2 * 50e6 / 8), ('100 / 100 Mbps', 2 * 100e6 / 8)]
    quotas = [5 * GB, 20 * GB, 100 * GB, 500 * GB]
    for I, P in [(60, 15), (30, 15), (15, 5), (10, 5)]:
        print(f'**Interim {I} s, pass {P} s**: latency L = {I + P + 1} s ({I + P + 5} s with retransmissions)\n')
        print('| plan (up / down) | combined | ' + ' | '.join(f'quota {fmt(q)}' for q in quotas) + ' |')
        print('|---|---|' + '---|' * len(quotas))
        for name, r in plans:
            cells = []
            for q in quotas:
                b = bound(q, r, I, P, 1)
                bf = bound(q, r, I, P, 5)
                cells.append(f'{fmt(b)} ({100 * b / q:.1f} %) / fault {fmt(bf)}')
            print(f'| {name} | {r / 1e6:.1f} MB/s | ' + ' | '.join(cells) + ' |')
        print()
    print('Reading: the first number per cell is the worst case when both directions run at the full plan rate for the whole latency;\n'
          'real traffic is usually far below it. It never exceeds R (the native per-direction caps).\n')


def table_load():
    print('### Accounting write rate (Interim-Update per online quota subscriber)\n')
    print('| online quota subscribers | interim 60 s | 30 s | 15 s | 10 s |')
    print('|---|---|---|---|---|')
    for n in (500, 2000, 5000, 20000):
        print(f'| {n} | ' + ' | '.join(f'{n / i:.0f} /s' for i in (60, 30, 15, 10)) + ' |')
    print()


def simulate_split(R, down_share_of_traffic, weights):
    """Directional split: at each login the NAS gets caps (in, out) = R_rem x (w_in, w_out). A direction that reaches its cap ends the
    session (a forced reconnect); the subscriber redials and gets new caps from what is left. Traffic is consumed in the fixed
    proportion down_share_of_traffic (down) / 1 - down_share_of_traffic (up). Returns (forced reconnects, bytes consumed)."""
    used_in = used_out = 0.0
    cuts = 0
    while cuts < 200:
        rem = R - used_in - used_out
        if rem < 1:
            break
        w_in, w_out = weights(used_in, used_out, rem)
        cap_in, cap_out = rem * w_in, rem * w_out
        # consumption proceeds in the fixed ratio until one direction hits its cap
        f_out = down_share_of_traffic            # 'out' = towards the subscriber (download)
        f_in = 1 - f_out
        t_in = cap_in / f_in if f_in > 0 else float('inf')
        t_out = cap_out / f_out if f_out > 0 else float('inf')
        t = min(t_in, t_out)
        used_in += t * f_in
        used_out += t * f_out
        cuts += 1
    return cuts


def table_split():
    print('### Directional split (native hard total): forced reconnects until the quota is used up\n')
    print('A direction that hits its cap ends the session although the quota is not used up; the subscriber redials and the caps are\n'
          're-derived from what is left. Counts are per billing period.\n')

    def fixed_by_plan(down, up):
        w_out = down / (down + up)
        return lambda used_in, used_out, rem: (1 - w_out, w_out)

    def adaptive(prior_out):
        # weight of the download direction = (used_out + prior) / (used + 2 prior), floored/capped so neither cap is 0
        def w(used_in, used_out, rem):
            tot = used_in + used_out
            wo = (used_out + prior_out * 1e9) / (tot + 1e9)
            wo = min(0.999, max(0.001, wo))
            return (1 - wo, wo)
        return w
    mixes = [('download only', 1.0), ('99 % down', 0.99), ('90 % down', 0.90), ('70 % down', 0.70), ('50 % down', 0.50)]
    print('| traffic mix | fixed 50 / 50 | fixed by plan 20 / 5 Mbps (80 / 20) | fixed by plan 100 / 20 (83 / 17) | adaptive (prior 80 %) |')
    print('|---|---|---|---|---|')
    R = 100 * GB
    for name, f in mixes:
        f_eff = min(f, 0.999999)
        row = [simulate_split(R, f_eff, lambda a, b, c: (0.5, 0.5)), simulate_split(R, f_eff, fixed_by_plan(20, 5)),
               simulate_split(R, f_eff, fixed_by_plan(100, 20)), simulate_split(R, f_eff, adaptive(0.8))]
        print(f'| {name} | ' + ' | '.join(str(x) for x in row) + ' |')
    print()
    print('A native hard total bounds the overshoot to about 2 x (the NAS cut delay x the direction rate) = 1 - 2 s of line rate,\n'
          'at the price above. Download-only subscribers hit a cap with every re-derivation (the remaining quota shrinks geometrically),\n'
          'which is why the count of a one-direction subscriber is the largest; the sessions then become shorter as the quota nears its end.\n')


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'bound'
    {'bound': table_bound, 'split': table_split, 'load': table_load}.get(cmd, lambda: sys.exit(__doc__))()
