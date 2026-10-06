# WP8 S3 — real Disconnect / CoA on RouterOS CHR 7.24.5 — results (2026-10-06)

`wp8-integration` `CoaDisconnectTest` 6/6 (70 assertions). Instrument: the application's `Reseller\Radius\CoAClient`
(unchanged) in the runner sharing the FreeRADIUS address 10.88.0.10 (OD-W8-11); captures and NAS reads only cross-check.

| Case | Result |
|---|---|
| PPPoE Disconnect | `SUCCESS COA_ACK`, `nas_id 1`; client torn down; `/ppp active` empty; Stop `NAS-Request`; capture: exactly 1 Disconnect-Request 10.88.0.10 -> 10.88.0.2:3799 and 1 Disconnect-ACK (no retransmission once answered) |
| Hotspot Disconnect | `SUCCESS COA_ACK`; `/ip hotspot active` empty; Stop written; client intercepted again (302) |
| Rate-limit CoA | `changeAuthorization(Mikrotik-Rate-Limit 128k/1M)` -> CoA-ACK; live dynamic queue `256000/512000 -> 128000/1000000`; same session (no reconnect, no new accounting row); measured upload 126.9 kbps |
| IP / pool change | CoA with `Framed-Pool` or `Framed-IP-Address` on a live session -> **CoA-ACK but NO effect** (address unchanged, no re-auth). Binding rule proven: update policy -> Disconnect -> re-authentication -> new address: pool `nuxr_wp8_pool_dyn` 10.88.10.127 -> pool `_dyn2` 10.88.10.199 -> static 10.88.10.61; three accounting rows, history kept |
| No response (incoming CoA dropped on the NAS, lab only) | `UNKNOWN COA_NO_RESPONSE`, not retryable, after 6.0 s; capture: 3 identical Disconnect-Requests (same identifier 151, same attributes) - no new request generated; the session stayed up (nothing fabricated); after the path was restored: `SUCCESS` |

## Error-Cause investigation (owner requirement; mapping NOT changed)

| Request | RouterOS 7.24.5 answer | Accepted mapping result |
|---|---|---|
| known active session | Disconnect-ACK | SUCCESS COA_ACK |
| already-disconnected session, radacct row still open (Stop lost: NAS accounting blocked) | NAK **406** | FAILED COA_NAK |
| deliberately unknown user + session id | NAK **406** | FAILED COA_NAK |
| active user, wrong Acct-Session-Id | NAK **406** | FAILED COA_NAK |
| CoA for an unknown user | CoA-NAK **406** | FAILED COA_NAK |
| request with no identifying attribute (only NAS-IP-Address) | NAK **406** | FAILED COA_NAK |
| CoA `Framed-Pool` on a live session | CoA-ACK (ignored) | SUCCESS COA_ACK (no effect) |
| CoA `Mikrotik-Rate-Limit` on a live session | CoA-ACK (applied) | SUCCESS COA_ACK |

Conclusion: RouterOS 7.24.5 **never sends 503**; it uses **406 for every request that matches no session**, including
a request that identifies nothing. 406 is therefore broader than "session absent" and must not be mapped to SUCCESS
wholesale. With the accepted mapping a vanished session appears as `FAILED COA_NAK (406)` in the best-effort
disconnect annotation; the activation / reversal state machines do not depend on that result (CHANGE_PLAN and
REACTIVATE record it as an ANNOTATION only), so there is **no state-machine correctness issue and no code change**.
Raised as non-blocking owner decision **OD-W8-14**.

Related observation (pre-existing WP4 position "stale sessions are reported only"): RouterOS did **not** resend a
Stop that was lost while its accounting path was blocked; the radacct row stayed open after the path returned.
With Simultaneous-Use 1 such a stale row is expected to refuse the subscriber's next login until it is cleaned
(planned S6 drill 7; not executed because of the AVAIA stop).
