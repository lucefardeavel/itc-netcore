# WP8 H-2 / V-1 — separate test proposal (PLAN ONLY, not started)

Both findings are **unverified candidates**, recorded separately from H-1 / H1-A (owner decision 2026-10-10). H1-A does not
change either of them. Nothing below is implemented; each part needs owner approval before any lab work.

## H-2 — Possible Cross-NAS Hotspot `shared_users` enforcement gap

**Hypothesis.** `Port-Limit` (= `shared_users`) is enforced by RouterOS per NAS, from its own active table. The only
cross-NAS layer is the stock FreeRADIUS `Simultaneous-Use` check (open `radacct` rows of all NAS). When the count reaches
the limit, its checkrad verification on the production NAS type (`mikrotik_snmp`) fails open in FreeRADIUS 3.2.8 (A1
finding). One subscriber may therefore hold `shared_users` sessions on **each** NAS of the ISP. This is spelling-independent
and also applies with the exact stored spelling.

**Lab tooling needed (lab only):**
- a Hotspot server on CHR2: an extra NIC, a bridge with one or two client namespaces (for example `wp8c7` / `wp8c8`),
  DHCP, and the same Hotspot profile as CHR1 (`login-by=http-chap`, RADIUS accounting);
- the existing `chr2 setup` (nas row `mikrotik_snmp`, guard credentials) is reused;
- explicit MACs (the lesson from the A2-R CHR2 MAC collision).

**Cases:**
1. `shared_users` = 1, exact spelling: CHR1 c2 logs in, then CHR2 c7. Count live sessions on both NAS (the expected gap:
   2).
2. The same with the order reversed, and launched concurrently (5 trials).
3. `shared_users` = 2: CHR1 ×2 then CHR2 ×1 (and ×2).
4. Accounting-Start lost on CHR1, then a CHR2 login.
5. Control: the same NAS (A1 / H1-A gates).

Evidence: the RADIUS decisions (session section, checkrad exit), `/ip hotspot active` on both NAS, radacct.

**Outcome.** If proven, it becomes a security finding with its own remediation decision (for example verification of
the other NAS before Accept, or a Hotspot admission counter), never added automatically.

## V-1 — Possible VPN username-equivalence bypass

**Hypothesis.**
- `RadiusPolicyBuilder` writes `Simultaneous-Use` (no `Port-Limit`) for VPN plans.
- RouterOS VPN PPP Access-Requests (L2TP, PPTP, SSTP, OVPN) carry `Framed-User` + PPP with `NAS-Port-Type = Virtual`
  (by RouterOS convention; not yet captured in this lab), so they match neither the A2-R PPPoE branch (Ethernet) nor the
  H1-A Hotspot branch.
- They are protected only by the stock `Simultaneous-Use` check, whose verification fails open on `mikrotik_snmp`.
- Equivalent spellings, and possibly duplicate same-spelling sessions, may be admitted.

**Lab tooling needed (lab only; no host package installation):**
- an L2TP (or PPTP) server on CHR1 using the RADIUS `ppp` service;
- **CHR2 as the VPN client** (RouterOS `/interface l2tp-client`, two clients with different spellings), so no VPN client
  software is installed on the shared host.

**Cases:**
1. The capture of a VPN Access-Request (Service-Type, Framed-Protocol, NAS-Port-Type).
2. Same spelling twice (`Simultaneous-Use` = 1).
3. Two equivalent spellings.
4. Accounting-Start lost.
5. Evidence of the RADIUS decisions and the NAS active list.

**Outcome.** If proven, a separate remediation decision; the options include extending the H1-A rule and/or the A2-R
reservation to the VPN discriminator.

## Order and gating

Recommended order: H-2 (Hotspot users are the H-1 population), then V-1. Both must be assessed before the final WP8
acceptance. Neither is part of H1-A.
