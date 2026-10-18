# ITC NetCore

**ISP Management & Billing Platform** · Version **V1.0** · developed and maintained by **ITC**

ITC NetCore runs the business side of an internet service provider. It covers subscribers, plans, resellers and their
wallets, and activation onto the network through FreeRADIUS and MikroTik RouterOS (PPPoE and Hotspot). It is
Arabic-first (RTL) and also supports English, with an adaptive staff interface for desktop, tablet and phone and an
installable staff web app (PWA).

ITC NetCore is a modified version of **[PHPNuxBill](https://github.com/hotspotbilling/phpnuxbill)** by iBNuX and its
contributors. See [Credits and licence](#credits-and-licence).

> **Status: development release candidate.** It has not been approved for production. The release state is
> `RELEASE_BLOCKED_WITH_EVIDENCE` until three things are done: the target-environment acceptance tests TA-01 … TA-10 pass
> on the approved staging, the owner decides the open items, and the legal review (LIC-1) is complete.
> See `owner-review/WP10/SAAS_RADIUS_V1_FINAL_OPERATIONAL_CLOSURE.md`.

## What it does

- **Reseller hierarchy:** administrators, agents, sub-resellers and sales staff. Each actor sees and acts only on its own
  customers (server-side permission and data-scope checks).
- **Wallets and ledger:** prepaid reseller wallets, double-entry ledger, pricing chain with commissions and provider
  settlement, reservations, reversals, and integrity checks.
- **Activation:** an auditable activation state machine (new, renew, change plan) with retry, reconciliation and operator
  recovery. Uncertain operations stay recoverable and are never silently released.
- **RADIUS and NAS:** FreeRADIUS 3.2 policy (PAP/CHAP, expiration, simultaneous use, rate limits, pools), accounting
  history kept intact, RFC 5176 Disconnect / CoA to MikroTik.
- **Data quotas:** quotas limited natively on the NAS plus an enforcer. Billable usage is kept separate from actual usage,
  and an overshoot creates no debt and does not carry over. It is not advertised as a byte-exact cap.
- **Vouchers, invoices and reports**, with codes masked in the user interface.
- **Operations:** migration runner, health checks, backup / restore and recovery runbooks, least-privilege database
  accounts.
- **Interface:** Arabic-first RTL and English; the adaptive shell for desktop, tablet and phone; the staff PWA.

## Requirements

- PHP 8.2 with `pdo_mysql`, `curl`, `gd`, `mbstring`, `zip`
- MariaDB 10.11 or MySQL 8.0 (`REPEATABLE-READ`, UTC)
- FreeRADIUS 3.2.x (the configuration in `install/freeradius/`)
- MikroTik RouterOS as the NAS (behaviour verified in an isolated CHR 7.24.5 lab; target routers are verified by TA-03 / TA-04)
- NTP on every host and on the NAS (`docs/TIME_CONTRACT.md`)

## Documentation

| Topic | Where |
|---|---|
| Deployment and upgrade | `docs/DEPLOYMENT_AND_UPGRADE_RUNBOOK.md`, `docs/PRODUCTION_CONFIGURATION_CHECKLIST.md` |
| Backup, restore, operator recovery | `docs/BACKUP_RESTORE_RUNBOOK.md`, `docs/OPERATOR_RECOVERY_RUNBOOK.md` |
| FreeRADIUS and NAS onboarding | `install/freeradius/README.md`, `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` |
| Target staging and acceptance | `docs/STAGING_DEPLOYMENT_PACKAGE.md`, `docs/V1_TARGET_ACCEPTANCE.md` |
| Domain models | `docs/WP1_SCHEMA_CONTRACT.md` … `docs/WP8_INTEGRATION_MODEL.md` |
| Brand and naming rules | `docs/BRAND_IDENTITY.md` |
| Third-party licences | `docs/THIRD_PARTY_LICENSES.md` |
| Changelog | [CHANGELOG.md](CHANGELOG.md) (the original project's history), then the Git history of this repository |

## Naming note

The product name is ITC NetCore. Internal identifiers keep their original names: database and table names, routes, API
fields, the FreeRADIUS site and dictionary (`phpnuxbill`), file paths and the Composer package name. They are not part of
the visible identity, and renaming them would break existing installations.

## Credits and licence

ITC NetCore is built on **PHPNuxBill** (<https://github.com/hotspotbilling/phpnuxbill>), created by Ibnu Maksum (iBNuX)
together with the PHPNuxBill contributors. Their copyright notices in the source files and in the installer are kept. The
original project's README is kept verbatim in [docs/UPSTREAM_PHPNUXBILL_README.md](docs/UPSTREAM_PHPNUXBILL_README.md),
including its community, donation and support channels. Those channels belong to the original project, not to ITC.

The interface uses AdminLTE and Bootstrap. Every third-party component and its licence is listed in
[docs/THIRD_PARTY_LICENSES.md](docs/THIRD_PARTY_LICENSES.md). See also [NOTICE](NOTICE).

**Licence:** [LICENSE](LICENSE) contains the GNU General Public License version 2. The original project's README says
"version 2 or later", but no source file states a version. The licence version, its combination with LGPL-3.0
components, and the use of the product and third-party names are open legal questions with counsel (LIC-1,
`owner-review/WP10/LEGAL_REVIEW_PACKAGE.md`). This README does not decide them.

<a href="https://github.com/hotspotbilling/phpnuxbill/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=hotspotbilling/phpnuxbill" alt="PHPNuxBill contributors" />
</a>
