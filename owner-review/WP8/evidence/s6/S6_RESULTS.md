# WP8 S6 — failure / uncertain-outcome drills on the real lab — results (2026-10-06)

`wp8-integration` `FailureDrillsTest` 9/9 (277 assertions; 1 PHP deprecation notice from the vendored PEAR2 client,
OBS-W8-6), final clean run. Failures produced by lab-only means and always restored; after every drill the full state
was inspected (activation state + event history, reservation, wallets A/B/C, ledger legs, provider settlement /
commissions, RADIUS policy, radacct, reconciliation state) and the invariants asserted (no double settlement, no lost
reservation, no blind business retry, no fabricated success, UNKNOWN kept, accounting history kept), plus the fixture's
finance / activation invariants after every test.

| # | Drill (lab mechanism) | Outcome (accepted semantics held) |
|---|---|---|
| 1 | FreeRADIUS unreachable before provisioning (`docker pause` of the lab FreeRADIUS) | provisioning targets the RADIUS **database**, so the activation completes normally (COMPLETED, reservation COMMITTED, one debit + 2 commissions; A 703 / B 202 / C 83); no service while FreeRADIUS is silent (see 6); service on return |
| 2 | RADIUS DB failure before COMMIT (accepted `radius.after_write` hook) | confirmed: **FAILED `RADIUS_DB_ERROR`**, reservation RELEASED, wallets unchanged, no ledger, no partial policy, no service. Transient (MySQL 2006): bounded automatic retry of the idempotent policy write inside the run (`PROVISIONING -> RETRY_PENDING -> PROVISIONING -> PROVISIONED`), COMPLETED once, service on the NAS |
| 3 | lost / uncertain RADIUS commit (`radius.commit_lost`) | **RECONCILIATION_REQUIRED `RADIUS_COMMIT_UNKNOWN`**, reservation ACTIVE (neither settled nor released), no ledger, one provisioning attempt (no blind retry); the commit had landed (policy present, NAS authenticates). `ReconciliationService::reconcile` -> COMPLETED, settled once; a second reconcile is refused (`ACTIVATION_STATE_CONFLICT`) and changes nothing |
| 4 | NAS unavailable for the Disconnect of a CHANGE_PLAN (CHR lab link down) | COMPLETED (Disconnect is best effort), annotation **`UNKNOWN:COA_NO_RESPONSE`**, one debit; the old session (old policy) stays up on the NAS; after the path returns an explicit operator Disconnect -> ACK, re-authentication gets the new pool (10.88.10.199) |
| 5 | incoming CoA disabled on the NAS (`/radius incoming accept=no`) | COMPLETED, annotation `UNKNOWN:COA_NO_RESPONSE`; capture: 3 identical Disconnect-Requests (same identifier), no new request; the session stays up (nothing fabricated); path restored -> ACK |
| 6 | RADIUS authentication timeout (FreeRADIUS paused) | the PPPoE client gets no session (RouterOS `/radius monitor` timeouts 4 -> 5); application state byte-identical during the timeout; service after FreeRADIUS returns |
| 7 | **accounting interrupted -> lost Stop** (CHR output to :1813 dropped while the subscriber disconnects) | **see the blocker below** |
| 8 | RouterOS API unavailable (input TCP 8728 dropped on ether2) | PEAR2 `SocketException: Error connecting to RouterOS` after 8 s (`default_socket_timeout` of the drill - the application passes no timeout; PHP default 60 s, OBS-W8-4); the FreeRADIUS-first activation path does not use the API: NEW activation COMPLETED and authenticated |
| 9 | Disconnect processed by the NAS, answer lost (CHR output from :3799 dropped) | COMPLETED, annotation `UNKNOWN:COA_NO_RESPONSE` although the NAS acted (subscriber disconnected, Stop `NAS-Request`) - UNKNOWN is never upgraded to SUCCESS; re-authentication on the new plan after restore |
| 10 | path recovery | no drill rule left, `/radius incoming accept=yes port=3799`, authentication and CoA (ACK) healthy |

## Drill 7 — lost accounting Stop (owner special blocker) — RESULT: STOP CONDITION 9

1-2. real PPPoE session, radacct row open. 3-5. NAS accounting blocked; the subscriber disconnects; `/ppp active` empty.
6-8. accounting restored; > one interim period later the row is **still open** — RouterOS did not resend the Stop.
9-10. real re-login -> **Access-Reject** (the client sees "authentication failed"); FreeRADIUS: `Simultaneous-Use := 1`
and `checkrad: No NAS type, or type "other" not checking` -> the stale row is assumed live. Still locked out a minute
later. 11. existing mechanisms: the application lists the row in `AccountingService::staleSessions()` (report only;
no caller acts on it; accepted WP4 Q8 "reported, never cleaned"); the application CoA gets **NAK 406** (no session on
the NAS) and changes nothing; FreeRADIUS cannot verify against the NAS (`checkrad` MikroTik needs SNMP tools that the
pinned image does not contain, and the NAS type is `other`). 12. the only existing recovery: a **RouterOS restart** ->
Accounting-On at boot -> the stock `accounting-on` query closes **every** open row of that NAS (`NAS-Reboot`, UPDATE,
history kept) -> the subscriber can log in again (2 rows kept).

=> Without a NAS-wide restart (which drops every subscriber on that NAS) the subscriber stays locked out
indefinitely. There is no safe per-subscriber recovery in the accepted system: **owner stop condition 9** — WP8
stopped after S6; owner decision **OD-W8-16** raised (`STOP_S6_LOST_STOP.md`). Nothing was deleted, no acctstoptime
was set by hand, Simultaneous-Use was not bypassed, no cleaner was added.
