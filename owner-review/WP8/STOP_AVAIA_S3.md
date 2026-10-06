# WP8 — STOP: protected AVAIA containers changed during the S3 window (2026-10-06)

Status: **WP8 STOPPED after S3 — WAITING OWNER**. Owner rule (implementation GO, "AVAIA RULE"): "If ANY protected AVAIA
object changes between valid snapshots: STOP WP8 immediately. Do not touch or repair AVAIA. Report the event to Owner
with evidence." Classification: **CONCURRENT / UNATTRIBUTED SHARED-HOST ACTIVITY** (no proof of the actor).

## Event

| | |
|---|---|
| valid S3 snapshot | 2026-10-06T12:11:50Z, 27 protected containers (`evidence/s3/avaia-guard-s3-before.txt`) |
| S3 slice-end check | 2026-10-06T12:14:52Z+ (after the final S3 run) |
| result | **VIOLATION missing: `avaia-wp2-pg-dev`, `avaia-wp2-pg-full`, `avaia-wp2-pg-rehearsal`**; the other 24 protected containers unchanged (`avaia-guard-s3-check.txt`) |
| full container list diff | the three entries removed (created 11:14:45Z / 11:28:25Z / 11:30:57Z); nothing else changed (`avaia-container-list-diff.txt`) |
| `docker ps -a` afterwards | no `avaia-wp2-*` object exists; `docker events` returns no record for them (event history not reliable on this host) |

## What WP8 did in the window (complete)

- `docker compose ... run --rm php82-wp8` (PHPUnit) in project `nuxbill-reseller-test`, twice;
- `docker logs` of `nuxbill-reseller-test-freeradius-wp8-1` (agent `fr_log`); `docker exec` into
  `nuxbill-reseller-test-mariadb-1` (read queries);
- RouterOS API calls to the lab CHR, `ip netns` / `pppd` / `curl` in the lab namespaces, `tcpdump` on `wp8lab0`.
No command addressed any AVAIA container, volume or network; no prune, no daemon change.

These three containers have been created and removed repeatedly by an unknown actor throughout the day
(`avaia-wp2-pg-base/-dev/-rehearsal` removed before S0, `-dev` created 11:14:45Z, `-full` 11:28:25Z, `-rehearsal`
11:30:57Z during S1 - all restart policy `no`, no compose project).

## State at the stop

- WP8 lab taken down (0 links, 0 namespaces, 0 QEMU, 0 lab containers / networks); AVAIA untouched.
- S1 (`c7811e50`), S2 (`b4347279`) committed; S3 tests 6/6 and evidence committed with this record.
- Not started: S4 (A->B->C finance), S5 (lifecycle), S6 (drills), S7 (drivers / fail-closed), S8 (docs / candidate).
- WP8 resumes only on an explicit owner instruction (e.g. after confirming the AVAIA activity is expected).
