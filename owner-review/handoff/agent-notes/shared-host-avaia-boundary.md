---
name: shared-host-avaia-boundary
description: The PHPNuxBill dev host (vmi3577555) also runs AVAIA production; owner rules for running Docker workloads beside it
metadata:
  node_type: memory
  type: project
  originSessionId: ccd890f1-0769-4fe7-b94c-4fb60f2282e1
  modified: 2026-10-01T19:49:17.270Z
---

The PHPNuxBill Reseller dev server (hostname vmi3577555, repo at /srv/phpnuxbill/dev) also hosts AVAIA production (compose project `avaia-v3-production` from /opt/avaia-v3/production, plus the `deploy` project and other avaia-*/ai-news/telegram containers). On 2026-10-01 the owner approved PHPNuxBill development on this shared host, with AVAIA as a protected boundary.

**Why:** the owner expected a dedicated dev box. AVAIA prod shares the same Docker daemon, so any global Docker action could break another product's production.

**How to apply:**
- Never modify, restart, exec into, inspect secrets of (no full `docker inspect`; use `--format` for health/restart fields only), or otherwise alter AVAIA containers, compose files, volumes, networks, images or ports.
- Never run `docker system|volume|network|builder prune` or any global cleanup; never restart or reconfigure the Docker daemon.
- PHPNuxBill test workloads use only the isolated compose project `nuxbill-reseller-test` (dev/test/compose.yml). One-shot helper containers (composer, PHP 8.3 lint) are named `nuxbill-reseller-test-*`.
- **Owner scope correction 2026-10-06 (WP8 final resume directive): AVAIA is completely out of scope.** Do NOT snapshot, guard, monitor, compare start times, inspect events, investigate or report AVAIA activity, and never stop work because AVAIA containers change. (Superseded the earlier snapshot/monitor rule, which caused a needless WP8 stop on unrelated AVAIA container churn.) The non-interference rules above and below still apply.
- No host PHP or Composer: they run in containers. Ask before any change that affects Docker or networking globally.
- This approval covered baseline reproduction only. WP3B needs separate owner approval (environment details: [[phpnuxbill-dev-env]]).
