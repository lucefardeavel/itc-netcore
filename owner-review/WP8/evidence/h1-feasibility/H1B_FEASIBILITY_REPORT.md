# WP8 H-1 — H1-B Feasibility & Remediation Decision Report

Date: 2026-10-10. Lab only (WP8 lab, RouterOS CHR 7.24.5 real NAS, FreeRADIUS 3.2.8 threaded, production-contract NAS
row).
- Not done: no change to `install/`, `system/`, the schema or production; no commit or push.
- H1-A and H1-B were not implemented in production code.

**Verdict: `H1_B_FEASIBILITY_FAILED`.** RouterOS 7.24.5 ignores the canonical `User-Name` in the Hotspot Access-Accept,
for both the session identity and accounting, so the bypass stays open. The H1-A fallback plan is in §11–§12, for
approval.

Evidence:
- `owner-review/WP8/evidence/h1-feasibility/lab-evidence.jsonl` (19 records, including 2 invalid ones and their
  correction);
- `lab-gate-console.out`;
- pcaps `h1-*.pcap` in `$NUXR_WP8_STATE/pcap` (not committed).

## 1. Verified Git baseline

| Item | Value |
|---|---|
| HEAD | `5ea597d2b74d0f0b89c5bc7ef1f6eef172c3ced2` (= `origin/wp8/integration-poc`) |
| `origin/feat/reseller-system-v1` | `5bdc5745` (unchanged) |
| `origin/main` | `500d3a2d` (unchanged) |
| Worktree at start | clean |

Uncommitted after this round (lab and evidence only):
- `dev/wp8/freeradius/start.sh`: a lab marker `wp8-h1-site` that loads an experimental site copy;
- `dev/wp8/h1_gate.py` (new);
- `owner-review/WP8/evidence/h1-feasibility/*`.

`install/` and `system/` have 0 changed lines. Manifest 42/42.

## 2. H1-A versus H1-B — technical comparison

| | H1-B (tested) | H1-A (fallback) |
|---|---|---|
| Mechanism | Access-Accept for a Hotspot login carries `User-Name := <canonical radcheck.username>`; `request:User-Name` unchanged | Hotspot post-auth refuses a login whose spelling is not byte-identical to the canonical radcheck username |
| Depends on NAS behaviour | **yes**: RouterOS must adopt the replied name for the active session and Port-Limit | no: RouterOS only ever sees one spelling, and its per-spelling Port-Limit is then per subscriber |
| Result on RouterOS 7.24.5 | **ignored** (§4, §5) | not yet tested (implementation stage) |
| Accounting / concurrency | — | stateless: independent of lost accounting and of concurrent timing |
| User impact | none (would have kept case-insensitive login) | a non-exact spelling is refused (§8) |

## 3. RFC compliance versus observed RouterOS behaviour

**What the RFCs say.**
- **RFC 2865 §5.1 (User-Name):** it "MAY be sent in an Access-Accept packet, in which case the client SHOULD use the
  name returned in the Access-Accept packet in all Accounting-Request packets for this session."
- **RFC 2865 §5.42 (Port-Limit):** sets the maximum number of ports the NAS provides to the user, a per-NAS limit. The
  RFCs do not define which name a NAS gives its active session.

**What RouterOS 7.24.5 Hotspot does.** The Accept contained the canonical name (pcap), yet:
1. the active session keeps the **typed** name;
2. Start, Interim-Update and Stop carry the **typed** name (it does not follow the RFC 2865 "SHOULD");
3. Port-Limit is counted per typed name.

The RFC therefore gives no lever that RouterOS honours here.

## 4. Real Hotspot active-session identity

`H1.identity` (09:26Z, H1-B site; subscriber stored `nuxr_h1_id_cafe_dd61`, typed `NUXR_H1_ID_CAFE_DD61`, `http-chap`,
held 75 s):

| Source | Baseline (project site, 09:20Z) | H1-B site |
|---|---|---|
| Access-Request `User-Name` (pcap) | `NUXR_H1_ID_CAFE_7DC6` (typed) | `NUXR_H1_ID_CAFE_DD61` (typed) |
| Access-Accept `User-Name` (pcap) | absent | **`nuxr_h1_id_cafe_dd61` (canonical)** |
| `/ip hotspot active` user (2 s and 75 s) | typed | **typed** `NUXR_H1_ID_CAFE_DD61` |
| Reconnect (UPPER → exact → UPPER, one client) | the active name follows the typed spelling each time | the same, **typed** each time |

## 5. RADIUS and Accounting evidence

| | Baseline | H1-B |
|---|---|---|
| Start / Interim-Update / Stop `User-Name` (pcap) | typed ×3 | **typed ×3** |
| `radacct.username` | typed | **typed** |
| Other Accept attributes (`policy_unchanged`, exact spelling, `shared_users` 2) | Port-Limit 2, Mikrotik-Rate-Limit `256k/512k`, WISPr-Session-Terminate-Time, Session-Timeout | identical, plus `User-Name` (Session-Timeout and WISPr values are time-based) |

Rate, Port-Limit, expiry (Session-Timeout from `Expiration`) and the accounting rows were unchanged by H1-B.
Authentication and the Message-Authenticator handling were not touched (the `authorize` section is the same).

## 6. `shared_users` = 1 / 2 test matrix (live Hotspot sessions of ONE subscriber after the logins)

| Case | Limit | Baseline | H1-B |
|---|---|---|---|
| exact, exact (control) | 1 | 1 (2nd refused by RouterOS) | 1 |
| exact, UPPER | 1 | **2** | **2** |
| UPPER, exact | 1 | **2** | **2** |
| UPPER, accent (`café`) | 1 | **2** | **2** |
| mixed-case voucher code typed UPPER, then lower | 1 | **2** | **2** |
| exact ×3 (control) | 2 | 2 (3rd refused) | 2 |
| exact, UPPER, accent (3 clients) | 2 | **3** | **3** |
| HTTP-PAP (`login-by=http-pap`), UPPER then exact | 1 | **2** | not run (H1-B already failed) |
| Accounting-Start lost (NAS drops 1813), UPPER then exact | 1 | **2** | not run |
| Accounting-Stop lost, then the exact spelling re-logs in | 1 | admitted, 1 live (correct: the session was gone); the stale open `radacct` row stays (pre-existing) | not run |

The test detects H-1 (baseline) and shows H1-B does not change the outcome.

## 7. Concurrent login results (logins launched together from independent clients)

| Case | Trials | Baseline live per trial | H1-B live per trial |
|---|---|---|---|
| Port-Limit 1, two spellings (c2 UPPER, c4 exact) | 5 | 2, 2, 2, 2, 2 | 2, 2, 2, 2, 2 |
| Port-Limit 1, same spelling (RouterOS' own check) | 5 | 1, 1, 1, 1, 1 | 1, 1, 1, 1, 1 |
| Port-Limit 2, three spellings (c2, c4, c5) | 3 | 3, 3, 3 | 3, 3, 3 |

RouterOS enforced its own per-spelling limit atomically in all 10 same-spelling trials (5 + 5). This is limited
evidence, not a proof.

## 8. Voucher and MAC login compatibility

**Vouchers.**
- `plan.php` formats are `up` (UPPER), `low`, `rand` (mixed case via `randomUpLowCase`) and `numbers`; the voucher-only
  username is the code.
- Today a code typed in any case authenticates, and each typed spelling gets its own Port-Limit. The mixed-case test
  code gave 2 live sessions under limit 1.
- Under H1-A, only the exact code spelling logs in. `numbers` codes are unaffected; `up` / `low` codes must be typed in
  their case; `rand` codes exactly. No change to voucher generation or passwords (OD-H1-6).

**Cookie (`login-by=http-chap,cookie`, lab only, restored).**
- RouterOS stores the cookie under the **typed** spelling.
- The cookie re-login goes through RADIUS (Access-Request, Login-User / Wireless-802.11, CHAP) with that typed spelling.
- Under H1-A, cookies can only be created with the exact spelling. A cookie created under a variant spelling before
  deployment would be refused at its next use. Users then log in once with the exact spelling.

**MAC login (`login-by=mac,http-chap`, `mac-auth-mode=mac-as-username-and-password`, lab only, restored).**
- RouterOS sends the MAC **upper case with colons** (`6E:59:58:28:E8:37`) as the username and as the CHAP password,
  with the same discriminator (Service-Type Login-User, NAS-Port-Type Wireless-802.11).
- MAC stored upper case → accepted, active user `6E:59:58:28:E8:37`, `login-by=mac`.
- MAC stored **lower** case → **already refused today** (Access-Reject). This is consistent with a CHAP password
  mismatch: the password is the MAC as RouterOS sends it, in upper case. A pre-existing behaviour, not H-1.
- Under H1-A, the only working form (upper case) is the exact spelling, so MAC login is unchanged.
- Code: `customers.php` allows `:` in usernames, and nothing in PHPNuxBill normalises MAC case.
- `mac-as-username` (no password) was not tested; it is to be included in the H1-A gate.

## 9. Cross-NAS — explicit coverage limitation

- **Not tested.** The owner's condition, "if H1-B passes on one CHR", was not met.
- **Spelling unification across NAS** (question 2): not applicable to H1-B (it does not unify even on one NAS). For
  H1-A it holds per NAS by construction, because every NAS only ever sees the canonical spelling.
- **Global `shared_users` across all NAS of an ISP** (question 3): a separate issue, independent of spelling.
  - RouterOS enforces Port-Limit per NAS from its local active table.
  - The stock `Simultaneous-Use` check counts `radacct` across NAS, but its checkrad verification on the production NAS
    type fails open in FreeRADIUS 3.2.8 (A1 finding).
  - So one subscriber can very likely hold `shared_users` sessions on each NAS. This is **unverified**.
  - Proposed as a separate candidate finding, **H-2**, not added automatically. Verifying it needs CHR2 with a Hotspot
    server, DHCP and a Hotspot client namespace (lab tooling extension).

**VPN scope (OD-H1-3, code analysis only, no lab VPN).**
- `RadiusPolicyBuilder` writes `Simultaneous-Use` for VPN plans and no Port-Limit.
- RouterOS VPN PPP requests (L2TP, PPTP, SSTP and OVPN; NAS-Port-Type Virtual by RouterOS convention, not captured in
  this lab) fall outside the A2-R PPPoE branch and outside an H1-A Hotspot discriminator.
- VPN therefore relies only on the stock `Simultaneous-Use` check, which fails open as above. Spelling bypass and
  same-spelling duplicates are plausible.
- **Unverified.** Candidate **V-1**, scope only.

## 10. H1-B result

**FAIL.** The canonical name reached the NAS (pcap), but RouterOS 7.24.5 did not use it for the active session, the
accounting, or the Port-Limit count. Every variant case gave 2 sessions under limit 1, and 3 under limit 2, exactly as
without H1-B.

**Not INCONCLUSIVE:**
- the first H1-B run was invalid (lab tooling defect, see §14) and was corrected and repeated;
- 11 canonical replies were sent and observed in the corrected run, with 0 lab rejects.

## 11. H1-A fallback recommendation

H1-A is recommended as the H-1 fix, subject to its own implementation gate. It is not assumed safe until tested.

**Rule (proposed, not implemented).** In `post-auth`, only for Hotspot (Service-Type == Login-User AND NAS-Port-Type ==
Wireless-802.11, as captured for http-chap, cookie and MAC logins; http-pap must be confirmed in the H1-A gate),
before the Accept:
1. **Prime the username:** run `%{sql:SELECT '1'}` so rlm_sql creates the stock `SQL-User-Name`, exactly as the A2-R
   PPPoE block does. The H1-B lab defect proved this step is required.
2. **Compare bytes:** one sentinel-checked query compares the request spelling with the canonical radcheck row
   byte-for-byte, for example `SELECT CONCAT('x=', IF(EXISTS(SELECT 1 FROM radcheck WHERE username =
   '%{SQL-User-Name}' AND CAST(username AS BINARY) = CAST('%{SQL-User-Name}' AS BINARY)), 'exact', 'variant'))`.
3. **Outcome:**
   - exactly `x=exact` → continue (Accept, policy unchanged);
   - `x=variant` → Access-Reject;
   - anything else (SQL error, empty, missing `SQL-User-Name`) → Access-Reject (fail-closed).

**Properties.**
- **Authentication unchanged:** the password and CHAP checks and Message-Authenticator enforcement are untouched (the
  `authorize` section stays the same); the rule only adds a refusal after successful authentication.
- **Reject reply:** `attr_filter.access_reject`. The refusal must not reveal that the account exists (OD-H1-5): it is
  made **indistinguishable from a wrong-password refusal** (same attributes, no distinctive Reply-Message, same
  `reject_delay`). This is verified in the gate by comparing the two Reject packets and their timing.
- **PPPoE A2-R not affected:** a disjoint discriminator, placed before the PPPoE block, sharing no state.
- **`Port-Limit` policy unchanged:** the reply attributes are identical for the exact spelling.
- **No change** to `install/radius.sql`, `system/`, the schema, voucher generation or passwords.

## 12. Exact minimal implementation scope (H1-A, for approval)

| File | Change |
|---|---|
| `install/freeradius/sites-available/phpnuxbill` | one Hotspot block in post-auth (~25 lines: discriminator, priming, byte comparison, sentinel, reject) and the header comment |
| `install/freeradius/README.md` | Hotspot canonical-spelling contract, compatibility (vouchers, cookies, MAC), rollback |
| `tests/Unit/Wp4/SessionGuardSourceTest.php`, `tests/Unit/Wp4/AdmissionSourceTest.php` | updated reject / `attr_filter` counts; new source contract (Hotspot-only discriminator, priming, binary comparison, sentinel, fail-closed) |
| `tests/Integration/Wp4/AdmissionReservationContractTest.php` (or a new `HotspotSpellingContractTest.php`) | harness, both engines: exact accepted (policy attributes unchanged, no Class, no claim); case, accent and mixed variants refused; refusal equal to a wrong-password refusal; SQL faults fail closed |
| `tests/Integration/Wp8/HotspotEquivalenceGateTest.php` (new) + `tests/phpunit.xml` (`wp8-security-gates`) | real-CHR regression gate |
| `dev/wp8/h1_gate.py` | the same matrix as this report, with H1-A as the experimental site first, then the project site |

**Gate before acceptance (real CHR):**
- this report's matrix with H1-A (identity, `shared_users` 1/2, concurrent ×13, lost Start and Stop, reconnect, PAP,
  cookie, MAC in both modes);
- the Reject equivalence check;
- regressions: `wp8-integration`, `wp8-security-gates` (A2, S-1, H-1), A2-R lab cases on post-auth, and the harness on
  both engines.

**Rollback:** reinstall the `5ea597d2` site file. There is no data or schema to undo.

## 13. Security and availability implications

- **H1-B:** no security effect (it does not close H-1), no availability effect. Not pursued.
- **H1-A security:**
  - closes the per-NAS spelling bypass, independent of lost accounting and of concurrency (stateless);
  - does not address the global cross-NAS count (H-2 candidate) or VPN (V-1 candidate);
  - the refusal does not disclose account existence (OD-H1-5).
- **H1-A availability:**
  - users, vouchers and pre-existing cookies under a non-exact spelling are refused, with no time-based or automatic
    lock-out;
  - affected subscribers can still log in with the exact spelling;
  - MAC login is unchanged;
  - the impact must be measured before production by a read-only query (OD-H1-2 requires a separate authorization for
    any production query; none was run).
- **H-1 remains OPEN** and is a production and WP8-closure blocker.

## 14. Evidence quality and cleanup

**Invalid records, kept and not counted.**
- `H1.identity` (09:23:41Z) and `H1.shared1` (09:25:09Z) in H1-B mode are invalid. The first lab H1-B block queried
  radcheck with `%{SQL-User-Name}` before rlm_sql had created it in post-auth, so it expanded to an empty string (lookup
  `u=-`). The lab block itself therefore refused every login, the exact spelling included (fail-closed).
- `CORRECTION.H1B.first_variant` records this. The block was fixed with the A2-R priming step, and both cases were
  rerun (09:26Z, 09:28Z).
- FreeRADIUS logs after the fix: 0 `nuxr-h1b=reject`, 11 `nuxr-h1b=canonical`.

**Lab-only changes, all restored.** The site was back to `project` (verified by `state`) before shutdown, and the Hotspot
profile was back to `login-by=http-chap`. Cookies, the cookie jar and the test users were removed; no firewall rules
were left.

**Lab down:** qemu 0, netns 0, links 0, containers 0, networks 0. CHR2 was not used. This round's SNMP credential files
were deleted.

**Secrets:** sweep of the new evidence and tooling: 0 hits; random test passwords are not recorded. The mixed-case
voucher test code appears as a username; it was a disposable lab identity, deleted after the case.

**Not touched:** `install/`, `system/`, the database schema, production NAS, voucher generation.

## 15. Final owner decisions needed

| ID | Decision | Recommendation |
|---|---|---|
| OD-H1-8 | Approve an H1-A implementation stage with the scope in §12 (lab gate first, production code after the gate) | Approve |
| OD-H1-9 | Refusal form: identical to a wrong-password refusal (no Reply-Message) vs a generic text | Identical to a wrong-password refusal (no account disclosure) |
| OD-H1-2 | Authorize a separate read-only production query to measure the H1-A compatibility impact (Hotspot logins under non-canonical spellings, vouchers by format, MAC-format usernames) | Authorize before H1-A production rollout |
| OD-H1-10 | Open **H-2 (candidate)**, Hotspot `shared_users` not global across NAS, and authorize a CHR2 Hotspot lab assessment | Open as a separate issue; assess after H1-A |
| OD-H1-11 | Open **V-1 (candidate)**, VPN spelling and duplicate exposure, as a lab assessment only | Record; assess separately |
| — | Same-spelling concurrency: RouterOS was atomic in 10/10 trials; extend to more trials in the H1-A gate | Include |

**`H1_B_FEASIBILITY_FAILED`**

The H1-A fallback plan is presented for approval. No production-code implementation, no commit or push, no A3.
