# V1 GB quota + Option B renewal — technical plan (2026-10-17)

**Owner decision:** `QUOTA_V1_GB_SUPPORT_APPROVED`, `RENEWAL_OPTION_B_APPROVED`, `AUTONOMOUS_RELEASE_COMPLETION_AUTHORIZED` (2026-10-17).

**Baseline:**
- `wp9/red-team-rc` at `e45899dd456d6df5ca63a016e9664aaebf45c1ed` (clean); product tree `46624cd8`.
- Protected refs unchanged: feat `704031c6`, main `500d3a2d`.

## 1. Measured NAS / RADIUS capabilities (RouterOS CHR 7.24.5 + FreeRADIUS 3.2.8)

Tool: `dev/wp8/quota_gate.py`. Raw evidence: `$NUXR_WP8_STATE/evidence/quota-gate.jsonl`, copied to `owner-review/WP10/evidence/quota/`.

| Mechanism | PPPoE | Hotspot |
|---|---|---|
| `Mikrotik-Total-Limit` in Access-Accept | **ignored** (`limit-bytes-in/out = 0`; 2.5 MB passed a 2 MB budget) | **enforced**: session ended at 2 005 628 B of 2 000 000 B |
| `Mikrotik-Total-Limit-Gigawords` | (ignored) | combined correctly (`limit-bytes-total` = 2^32 + 5) |
| `Mikrotik-Recv-Limit` / `Xmit-Limit` in Access-Accept | **enforced per direction** (`limit-bytes-in/out`); session ended at 2 022 642 B downloaded of 2 000 000 B; Stop cause `Session-Timeout` | (supported; not needed) |
| `Session-Timeout` in Access-Accept | enforced (ended after 30 s for 25 s incl. setup) | enforced (WP4 / WP8) |
| `Acct-Interim-Interval` in Access-Accept | honoured (10 s → 5 updates in 45 s) | — |
| CoA `Session-Timeout` mid-session | CoA-ACK, applied (session ended) | — |
| CoA `Total-Limit` / `Recv` / `Xmit` mid-session | CoA-ACK but **not applied** | CoA-NAK |
| Disconnect-Request | ACK, Accounting-Stop (`NAS-Request`) | ACK (WP8 DC-2) |

## 2. V1 data-quota contract (Q2)

**Native in-session limit at every authentication.** When the subscriber's check items carry `Max-Data`, FreeRADIUS (`authorize`, after the period counters) computes:
- `remaining = Max-Data − period usage`;
- `share = floor(remaining / Simultaneous-Use)`.

It replies `Mikrotik-Total-Limit(+Gigawords)`, `Mikrotik-Recv-Limit(+Gigawords)` and `Mikrotik-Xmit-Limit(+Gigawords)`, each equal to `share`, plus `Acct-Interim-Interval = 60`.
- **Hotspot:** the router enforces `share` on the session's total traffic: a **hard cap per session**, not byte-exact (measured overshoot 0.3 % - 4.4 % at ~1 Mbps = 0.2 - 0.7 s of line rate; the lab tests assert the share + 1 s of the measured rate). With `shared_users = n`, every concurrent session gets 1/n of the remaining quota.
- **PPPoE:** the router enforces `share` (= the remaining quota; PPPoE always has Simultaneous-Use 1) **per direction**. Traffic in one direction only is capped by the NAS (same overshoot as Hotspot). Mixed upload + download can reach the quota before either direction hits its limit; the accounting enforcer closes that gap.
- **Fail closed:**
  - a share of 0 is never sent (RouterOS reads 0 as unlimited): the request is rejected;
  - a missing / emptied SQL expansion (the A2-R failure mode) or a malformed result rejects;
  - the existing counter still rejects an exhausted quota.

**Accounting enforcer** (`system/reseller/quota.php enforce`, run every minute by cron, with an internal 15 s pass):
- For every open session whose subscriber's period usage reaches `Max-Data` (or `Max-All-Session`), it sends a Disconnect-Request to that session's NAS and confirms the end: an Accounting-Stop seen, or NAK 503.
- Each action is audited (`quota.enforced`). Repeated Disconnects to the same session are spaced 60 s apart.
- **Overshoot bound** for PPPoE mixed traffic, and for Hotspot multi-session accounting staleness: at most `min(share_at_auth, max_rate × (interim 60 s + pass 15 s + Disconnect ≤ 6 s))`.
  - It is measured on CHR.
  - **It is not called a hard cap.**
- If FreeRADIUS, the database, the NAS link or the enforcer itself is down, the native per-direction / per-session limits still hold (PPPoE worst case `2 × share`).
- `health.php` check `quota_enforcer` (as implemented): CRITICAL when quota subscribers are online and the enforcer has not completed a pass for 3 minutes; WARNING while an over-quota session is still open (paged after 30 minutes per checklist §J). An over-quota session that the NAS no longer has (lost Stop, RouterOS NAK 406) cannot be closed by PHPNuxBill and would otherwise keep a CRITICAL forever.

**Time quota:** `Max-All-Session` → `Session-Timeout` = the remaining time (native; PPPoE now too). Concurrent Hotspot sessions are reconciled by the enforcer.

**Plans:**
- PPPoE plans get the Limited options (time / data / both) that Hotspot plans already have.
- Saving or activating a Limited plan with a zero, negative or non-numeric limit is refused (form message; the policy builder throws `POLICY_QUOTA_INVALID`).
- Unlimited plans carry no quota attribute.

**Accounting integrity:** a stale Interim-Update (one arriving after the Stop, or reporting smaller counters than already stored) is acknowledged but not written, so usage never decreases. Gigawords are combined by the stock queries; 64-bit counters are tested.

## 3. Renewal contract (Q1, P2 Option B)

- **Period boundary:**
  - **(V1.1, N2: superseded by the exact rule)** the period owns the accounting rows that arrived after it started (`Nuxr-Period-Acct-Id`); see `N2_PERIOD_OWNERSHIP.md`. The time boundary below is the fallback;
  - a RENEW starts the new quota period at the renewal instant; the fallback attribution boundary `Nuxr-Period-Start` is that instant **minus `QuotaPolicy::PERIOD_SKEW_SECONDS` (10 s)**: measured on CHR 7.24.5 the NAS stamps Accounting-Start 1.1 - 1.7 s before the host receives it, so a strict comparison booked a session that logs in within ~2 s of the renewal (the Option B redial) to the previous period (found by the CHR mixed-traffic test; the suspected DB time-zone mismatch was checked and refuted: all sessions UTC);
  - usage is attributed to the period in which its **session started**, so old and new usage never mix;
  - accounting history is never deleted.
- **Ending the old session:** after the new period is provisioned and verified, every open session of the subscriber that started **before** the new period is sent a Disconnect, and its end is **confirmed** (Accounting-Stop within the bound, or NAK 503 "no such session"). A Disconnect-ACK alone is not proof. RouterOS 7.24.5 answers NAK 406 (never 503) for a session it no longer has: that stays unconfirmed (fail closed). This applies to:
  - activation RENEW, CHANGE_PLAN and REACTIVATE;
  - the legacy recharge / voucher renewal path (implemented as the legacy device rule: every open session of the subscriber is ended, one Disconnect each, now confirmed; no second Disconnect).
- **One Disconnect per session (A3 coordination):** inside a rename operation (reseller `run()` and the legacy Admin form) the device re-provision ends the running sessions with the Stop-confirmed termination, and A3 also targets the sessions it captured before the rename. A session the device already ended **and confirmed** (exact radacctid + NAS + Acct-Session-Id, recorded in the rename scope; a prior Stop alone, or any NAK / timeout / unknown, is never evidence) is not sent a second Disconnect; A3 still reads the Accounting-Stop from accounting itself. Found by the real-NAS run: the old fire-and-forget Disconnect only passed A3 by racing the PPPoE teardown, and RouterOS answers NAK 406 for a vanished session.
- **No session under the new policy while the old one may still run:**
  - PPPoE: Simultaneous-Use 1 + the Option G / A2-R admission guard refuse a second session while the old one is open or unverified.
  - Hotspot: the old session keeps its old per-session cap (old period's allowance); new sessions get the new period's share.
- **Unconfirmed end** (timeout, NAK other than 503, NAS unknown, UNKNOWN):
  - recorded on the activation;
  - reported by the drift report as `SESSION_POLICY_STALE` (now also for RENEW);
  - ended through the operator `session-end` command.
  - The old session can never exceed its own grant: the old Session-Timeout and the old per-session data limits.
- **Finance:** unchanged. The renewal settles exactly once (WP5A state machine). A session-end outcome never changes or repeats a settlement, recharge or grant.

## 4. Scope / files (no schema change)

| Area | Files |
|---|---|
| Policy | `Reseller/Radius/RadiusPolicyBuilder.php` (PPPoE quotas, interim, validation), `Reseller/Radius/QuotaPolicy.php` (new: plan quota validation) |
| FreeRADIUS | `install/freeradius/sites-available/phpnuxbill` (authorize: in-session limits; accounting: stale interim guard); README |
| Enforcer | `system/reseller/quota.php` (new CLI), `Reseller/Radius/SessionTermination.php` (new: Disconnect + Stop confirmation), `health.php` |
| Renewal | `Reseller/Activation/ActivationService.php` (RENEW ends + confirms), `DriftReport.php`, `system/devices/Radius.php` (legacy renewal) |
| Plans UI | `controllers/services.php`, `ui/ui/admin/pppoe/{add,edit}.tpl` |

Protected-file exceptions (if needed): `AdmissionSourceTest` / `SessionGuardSourceTest` pins, narrowest re-pin with old / new values.

## 5. Tests (test-first; both engines; real FreeRADIUS harness; real CHR)

| Level | Tests |
|---|---|
| Unit | builder (PPPoE / Hotspot quota attributes, interim, invalid quotas), QuotaPolicy |
| FreeRADIUS harness | Access-Accept carries the share (incl. > 4 GiB gigawords, shared users); exhausted → reject; zero share → reject; stale / duplicate / out-of-order interim; interim after Stop; 64-bit counters |
| Integration | enforcer: over quota → Disconnect + confirmation + audit; under quota / unlimited → nothing; time quota; repeated pass idempotent; NAS silent → UNCONFIRMED + health. Activation RENEW / CHANGE_PLAN: open session → Disconnect + confirmed / unconfirmed + drift; settlement exactly once. Legacy recharge renewal. Plan validation. |
| CHR (real) | Hotspot GB plan exhausted mid-session; PPPoE GB plan download-only exhausted mid-session (native); PPPoE mixed traffic → enforcer cut within the bound; RENEW during an active session (old ended + confirmed, the new session gets the new share); CHANGE_PLAN; reconnect; FreeRADIUS paused mid-session (native limit holds); NAS link down (enforcer UNCONFIRMED, native limit holds); the existing security gates + integration (Simultaneous-Use, Option G, A2-R, identity guard) |
