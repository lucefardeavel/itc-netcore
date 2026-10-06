# WP8 S5 — real lifecycle on RouterOS CHR 7.24.5 — results (2026-10-06)

`wp8-integration` `RealLifecycleTest` 3/3 (final runs: 84 + 34 assertions), canonical domain services only
(`ActivationService` NEW / RENEW / CHANGE_PLAN / REACTIVATE by the Sales user; `LegacyReprovisionService` for password
and username). The R-WP8-CARRY-01 legacy removal / deactivation routes were not used.

| Step | Result |
|---|---|
| **A. RENEW** | expiry extended from the current expiry: 2026-11-05 20:26:56 -> 2026-12-05 20:26:56; RADIUS `Expiration` rewritten to the saved billing expiry (`05 Nov` -> `05 Dec 2026 20:26:56`); **no disconnect**; live session unchanged (`0x81000002`), one accounting row; settled again: 17.00 = 5.00 + 12.00, reservation COMMITTED. The live session keeps the Session-Timeout it got at authentication until it re-authenticates (accepted WP5A model). |
| **B. CHANGE_PLAN, rate only** | accepted model = policy update + Disconnect (no live rate CoA in the activation path; live rate CoA itself proven in S3): policy `128K/1M`; annotation `disconnect: [SUCCESS:COA_ACK]`; client re-authenticates in the same pool; NAS queue `128000/1000000`; finance = full new-plan wholesale (no proration, accepted C14): debit 30.00, commissions 3.00 + 3.00, provider 24.00 -> 30.00 = 6.00 + 24.00 |
| **C. CHANGE_PLAN, pool** | policy `Framed-Pool nuxr_wp8_pool_dyn2`; Disconnect ACK; re-authentication -> address from the new pool; three radacct rows (one per session: NEW, rate change, pool change), the first two closed `NAS-Request`; no claim based on a CoA-ACK; 4 activations -> 4 debits, none duplicated |
| **D. EXPIRY** | plan validity 2 minutes: Session-Timeout 117 s; the NAS ended the current session at the billing expiry (Stop `Session-Timeout`, 118 s); next authentication -> Access-Reject |
| **E. REACTIVATE** | same plan after expiry -> derived action REACTIVATE, COMPLETED, settled (17.00 = 5.00 + 12.00); no open session to disconnect; the subscriber authenticates again; history: expired session row + new session row |
| **F. Re-provision, password** (customer) | `DONE`; RADIUS `Cleartext-Password` rewritten; the device provision disconnected the live session; old password rejected on the NAS, new password accepted |
| **F. Re-provision, username** (Admin `IDENTITY_EDIT`) | `DONE`; old identity has no RADIUS rows, the new one has the policy, exactly one RADIUS identity; old identity refused on the NAS, new identity accepted; recharge rows follow the username, plan / period / price / status unchanged |
| **F. uncertain RADIUS commit** (`radius.commit_lost`) during a password change | `REPROVISION_UNCONFIRMED`; the new value is kept in billing and in RADIUS (the commit had landed) - **no silent rollback**; the kept value authenticates on the NAS |

**Finding OD-W8-15 (raised, not changed):** a username change re-provisions and disconnects only the NEW RADIUS key.
The live session of the OLD identity survives the rename (`/ppp active` still shows it, `radius = true`), and the
customer could open a second session under the new name at the same time (observed: old + new both active). The
customer then holds two concurrent sessions beyond Simultaneous-Use 1 until the old session ends (at most its
Session-Timeout = remaining paid period). Admin-initiated only (IDENTITY_EDIT needs scope G); no unpaid service, no
cross-customer effect. Classified as a policy-enforcement gap (LOW-MEDIUM), not a security vulnerability; a fix needs a
runtime change (also disconnect the old key), so it is left to the owner.
