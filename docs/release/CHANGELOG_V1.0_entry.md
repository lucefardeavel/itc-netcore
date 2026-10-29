# CHANGELOG entry: ITC NetCore V1.0 (text to insert)

This file holds the text of the V1.0 entry for `CHANGELOG.md`. Insert it **above** the first upstream entry
(`## 2024.10.23`) when the owner authorizes the release. The upstream PHPNuxBill history below it stays unchanged.
Replace `<final SHA>` with the released commit and the status line once the release gates have passed.

---

## ITC NetCore V1.0 (Release Candidate) - 2026-10-29

Status: Release Candidate, not production-accepted. Lab verdict `LAB_ACCEPTED_WITH_LIMITATIONS`; target staging not
run; LIC-1 `BLOCKED — COUNSEL REQUIRED`. Lab-accepted candidate `a6b02eba`; release commit `<final SHA>`.
Full notes: `docs/release/RELEASE_NOTES_V1.0.md`.

ITC NetCore is a modified version of PHPNuxBill (https://github.com/hotspotbilling/phpnuxbill) by Ibnu Maksum (iBNuX)
and the PHPNuxBill contributors. Developed and maintained by ITC.

### Added

- Reseller hierarchy (Agents, sub-agents, Sales accounts) with server-side role, capability and data-scope checks; roles SuperAdmin, Admin, Agent, Sales, Report (Report: no data views in V1.0)
- Prepaid reseller wallets, debt limits, provider funding and withdrawal, parent to direct-child transfers
- Double-entry, insert-only ledger with compensating corrections and CSV statements
- Pricing chain: provider base cost, wholesale per child, retail price, commissions, provider settlement
- Activation state machine for new, renew, reactivate and change plan, with quote review, reservations, bounded retry, reconciliation, operator recovery and Admin reversal
- FreeRADIUS 3.2 policy, RFC 5176 Disconnect / CoA to MikroTik RouterOS, PPPoE session guard (SNMP), PPPoE admission reservation, Hotspot admission slots
- Data and time quotas per billing period with separate actual and billable usage (bounded enforcement, not byte-exact)
- Voucher codes masked in the interface, exactly-once redemption, review of unproven grants
- Arabic-first RTL adaptive staff interface (desktop, tablet, phone) and installable staff PWA
- ITC NetCore product identity (name, mark, wordmark, self-hosted Inter and Noto Sans Arabic) with operator `CompanyName` priority
- Migration runner, setup and release preflight, health checks, least-privilege database account generator
- Runbooks: deployment and upgrade, backup and restore, operator recovery, NAS onboarding and secret rotation; production configuration checklist; target acceptance procedure
- Backup and point-in-time-recovery tooling (`install/backup/`): encrypted scheduled backups, binlog archive, restore with replay to a time or position, periodic restore test, retention (recovery option 2)
- Fresh-install package (`install/fresh/`): artifact verification, gated command-line fresh install on an empty database, post-install verification, nginx / PHP-FPM / logrotate / cron examples
- Guides: installation, MikroTik and FreeRADIUS, operations, training, product profile (`docs/guides/`)

### Changed

- Legacy RouterOS API drivers and the `rlm_rest` device are disabled in the release profile
- CI actions pinned to commit SHAs
- The static IP of a customer must be a valid IPv4 address and unique across all customers, for every role (R-HF-CARRY-01)
- Agents and Sales get one generic refusal when a username, PPPoE username or static IP is already taken (R-HF-CARRY-01)
- Distinct Arabic labels: Refill Customer «شحن العميل بقسيمة», Recharge Customer «إعادة شحن اشتراك العميل», Refill Account «شحن الحساب بقسيمة», Coupons «الكوبونات»; the voucher list button reads "Add Vouchers" («إضافة قسائم»)
- The first installation uses the `install/fresh/` package instead of the web installer

### Fixed

- Cron could suspend a subscriber whose paid activation was parked before settlement (F-A1 / R-4)
- An adjustment confirmation token could execute on the provider-funding route and the reverse (F-A2)
- Legacy PPPoE IP / username availability hints answered for customers outside an Agent's or Sales account's scope (R-HF-CARRY-01, `0be31b63`)
- `customers/add-post` disclosed username existence to tree-scoped staff (R-HF-CARRY-01, `228f01c9`)
- The static IP was neither validated nor unique on save (R-HF-CARRY-01, `e8260df7`)
- Customer-portal header in product identity mode: the mark pushed the product name under the navigation bar (`012a9e91`)
- Untranslated Arabic strings: Voucher and FreeRADIUS settings titles, the portal Voucher item, "Or", default plan titles, 13 refusal messages (`012a9e91`)

### Known limitations

- R-HF-CARRY-01 stays open for the owner: low unauthenticated enumeration (forgot-password / forgot-username, self-registration) and accepted-level residuals; a third round (reserved IPv4 ranges) is in progress
- See `docs/guides/operations/` (supported capabilities and known limitations) and `docs/release/RELEASE_NOTES_V1.0.md`
