# V1 engineering decision record (continuous execution phase)

Scope: decisions an engineer may take inside the accepted contracts (owner `CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED`, 2026-10-17). Non-delegable decisions are **not** recorded as taken here; they are listed under "Deferred to the owner" with options and a recommendation.

## Taken (engineering)

| ID | Date | Decision | Why | Alternatives rejected |
|---|---|---|---|---|
| E-N2-1 | 2026-10-17 | A billing period owns the accounting rows that **arrived after it started** (`radacct.radacctid` > `Nuxr-Period-Acct-Id` = the table's highest id at the period start). The time boundary (period start − 10 s) stays as the fallback for rows without an id. | exact for every row, independent of the NAS clock; removes the under-count (clock lag) and the over-count (session opened in the 10 s before a renewal) of the time rule; one new check attribute, no schema change | receive-time stamping in FreeRADIUS (`event_timestamp_epoch = %l`): changes the accepted accounting time contract → owner decision, not taken |
| E-N2-2 | 2026-10-17 | The id is **paired with its period**: same time boundary → keep the stored id (or its absence); new boundary → take the table's current end. A period provisioned before this change keeps the time rule until it ends. | a re-provision (password, rename, re-sync) must never reset the usage; no in-place migration of existing periods | computing a fresh id on every provision (would reset usage on a password change) |
| E-N2-3 | 2026-10-17 | An activation records the id in its captured target (before the first write); `provision()` writes that target and verification compares with it. A RADIUS-database error while resolving is a retryable RADIUS failure (activation does not start). | accounting rows arriving between capture and provision must not cause `VERIFY_MISMATCH` | re-reading `MAX(radacctid)` in each step |
| E-N2-4 | 2026-10-17 | Readers trust an id only when it is well-formed and not beyond the table's end; a malformed id fails closed at login. | an accounting table restored from an older backup must not make every new row look "old" (quota bypass) | trusting the stored value blindly |
| E-N1-1 | 2026-10-17 | The interim interval of a data-quota session is configurable (`nuxr_quota_interim_seconds`, 10 - 300, default 60 until 2026-10-18, then 30: E-N1-3). | measured: the interim is the dominant term of the detection latency and cannot be changed by CoA | hard-coding 30 (accounting load decision belongs to the operator / owner) |
| E-N1-2 | 2026-10-17 | Enforcer: a Disconnect with no answer / no Stop is retried after 15 s, a NAK after 60 s; one termination call per pass; a silent NAS is asked once per call and never claimed ended. | bounds the delay of a transient CoA fault without hammering a vanished session; a NAS outage cannot stretch a pass | unlimited retries per pass; treating no answer as ended |
| E-N1-3 | 2026-10-18 | The shipped default interim of a data-quota session is **30 s** (was 60). Owner N1 = A + G (provisional 30 s / `--every=5`), confirmed by the lab capacity measurement | zero loss and flat enforcer pass time up to 7500 online quota subscribers (250 Interim-Updates/s) on both engines; target sizing stays TA-09 | keeping 60 (doubles the normal-operation overshoot bound) |
| E-N1-4 | 2026-10-18 | `QuotaEnforcer::overQuota()` is set-based (one grouped query per quota kind and ownership rule) | the per-subscriber scan took 5 - 9 s at 5000 online quota subscribers, longer than `--every=5`; differential test proves identical results | raising `--every` (increases the detection latency) |
| E-ODL-1 | 2026-10-18 | O-DL (deadlock refusal of concurrent activations on one wallet): classified as an **existing MariaDB contention limit, not a regression**; fail-safe verified on both engines; **no code change**. No automatic cancellation of HELD operations (owner direction); the locked finance / ledger / retry contracts are untouched. A jittered-backoff variant (same retry bound) is documented for a separate authorization | `O_DL_CLASSIFICATION.md`: old vs new refusal rate 0.22 % vs 0.28 % on MariaDB, 0 on MySQL; every refusal left FUNDS_RESERVED + one ACTIVE reservation, no service, no ledger entry; resume 109 ms avg; variant A 0 / 5,400 vs baseline 8 / 5,400 | automatic cancel (owner: no), more retries (changes the pinned bound) |

## Deferred to the owner (collected for the final package)

| ID | Item | Options | Recommendation |
|---|---|---|---|
| N1 | PPPoE total-quota overshoot semantics (`N1_PPPOE_OVERSHOOT.md`) | (A) keep native per-direction caps + accounting enforcer: overshoot <= min(R, r x (I+P+c)), <= R if the enforcer is down; never byte-exact. (B) directional split: native hard total (overshoot ~1-2 s of line rate) at 5-60 forced reconnects per period. (F) strict quotas on Hotspot only. (G) overshoot not charged / not carried (commercial policy). Interim 60 -> 30 s costs 2x accounting writes. | A with `nuxr_quota_interim_seconds`=30 and enforcer `--every=5` (<= ~2000 online quota subscribers); wording "a few seconds of line speed", no percentage and no "exact GB"; B only if a hard PPPoE total is required |
| N2 | Period-attribution method and NAS clock requirement (`N2_PERIOD_OWNERSHIP.md`) | Implemented (engineering, E-N2-1..4): ownership by accounting-row arrival order, time rule only as fallback. Owner to accept: NTP + UTC on every host, max drift 5 s app/db/FreeRADIUS, 60 s NAS (health warns > 120 s ahead) as deployment requirements | Accept as implemented; make the clock checks (TA-02) a go-live gate |
| D1, D2, D4 – D10 | as in `SAAS_RADIUS_V1_GB_QUOTA_AND_RENEWAL_FINAL_ACCEPTANCE_PACKAGE.md` §15 | | |

## Branch decision (2026-10-24)

| ID | Decision | Effect |
|---|---|---|
| B-FEAT-1 | Owner: "خلي feat يطابق آخر نسخة" (make `feat` match the latest version) | `feat/reseller-system-v1` fast-forwarded from `704031c6` to the head of `wp9/red-team-rc` (no force, no history rewrite). Not a WP9 / WP10 acceptance: the ledger rows stay `PENDING`, release state `RELEASE_BLOCKED_WITH_EVIDENCE`, no deploy authorized. `main` unchanged (`500d3a2d`) |
