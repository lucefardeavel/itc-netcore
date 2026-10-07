# OD-W8-16 Option E — Port-Limit gate on RouterOS CHR 7.24.5 — results (2026-10-07)

Lab only: `Port-Limit` reply injected into disposable subscriber policy, `Simultaneous-Use` removed (stand-in for "the
SQL count ignores the row"), NAS active table sampled every 0.5 s. `lab-evidence-all-runs.jsonl` holds every run
(first full run, PPPoE re-run with capture, final full run, observation re-run).

| Case | Runs | Max live sessions on the NAS | Gate |
|---|---|---|---|
| PPPoE, Port-Limit 1 | 3 | **2** every run (`<pppoe-USER>` + `<pppoe-USER-1>`, both with addresses, `radius=true`, whole window); Access-Accept to the 2nd client carries Port-Limit = 1 (attribute 62 on the wire) | **FAILED** |
| PPPoE, Port-Limit 1, accounting lost 190 s | 2 | **2** every run | **FAILED** |
| Hotspot, Port-Limit 1 | 2 | 1 (2nd login: RADIUS Access-Accept, refused by RouterOS) | PASSED |
| Hotspot, Port-Limit 2 | 2 | 2 (3rd login refused by RouterOS) | PASSED |
| Hotspot, Port-Limit 1, accounting lost 190 s | 2 | 1 (2nd device refused by RouterOS) | PASSED |
| observation: PPPoE + `/ppp profile only-one=yes` (lab, reverted) | 3 | 1, **2 (one 0.5 s sample, newcomer with an address, then torn down)**, 1; 2nd client never got an IPv4, 1st kept | not a gate; candidate |

Conclusion: Option E's required second layer does not exist for PPPoE on RouterOS 7.24.5 (Port-Limit ignored by the
PPPoE server). Option E was not implemented; WP8 stopped for the owner decision.
