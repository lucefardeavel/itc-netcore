# WP7 PWA lab (test tooling only)

The WP7 owner decision OD-W7-4 places the PWA browser / security QA harness here. It is not part of any PHPUnit suite and is never used in production.

| File | Purpose |
|---|---|
| `PwaLabTest.php` | Seeds a disposable database (staff of every role, Agent-owned customers, pricing, funding, one activation). It serves the disposable application copy twice with `php -S`: a root installation and a `/billing/` subdirectory installation, with a production-like `APP_URL` (`dirname(SCRIPT_NAME)`). |
| `lab.sh` | `up` / `sync` / `down` for the lab container in the isolated `nuxbill-reseller-test` compose project. Ports are published on **127.0.0.1 only**: 18099 (root) and 18098 (subdirectory). |
| `pwa_suite.py` | Python Playwright checks. Every check first asserts `window.isSecureContext === true`; without a proven secure context a check fails, it is never skipped. |

## Why `localhost`
- Service workers, install and the offline document only work in a secure context. `http://localhost:<port>` is a potentially trustworthy origin.
- Plain `http://<container-ip>` is not, so it is not valid service-worker evidence.
- No TLS certificate or production configuration is involved.

## Run
```bash
export WP1_DB_ROOT_PASSWORD=...   # same throwaway values as the running test databases
export NUXR_RADIUS_SECRET=...
dev/pwa-lab/lab.sh up
python3 dev/pwa-lab/pwa_suite.py --out /tmp/pwalab-out
dev/pwa-lab/lab.sh down
```

## Requirements and boundaries
- **QA host:** Python 3 with `playwright` and its Chromium.
- **Excluded:** no Node, no frontend build system, nothing installed into the application.
- **Allowed scope:** only the `nuxbill-reseller-test` compose project and the `nuxbill-reseller-test-pwalab` container are used. Never production, never any other container.
