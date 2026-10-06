# WP8 S4 — real A -> B -> C reseller business flow — results (2026-10-06)

`wp8-integration` `ResellerBusinessFlowTest` 1/1 (69 assertions), on the accepted activation fixture (disposable
application database, migrations R0001-R0007, finance + activation invariants after the test) with the application's
`radius` connection on the lab RADIUS database served by the real FreeRADIUS 3.2.8 to RouterOS CHR 7.24.5.

| Step | Result |
|---|---|
| hierarchy (accepted staff fixture) | Agent A (top) -> Agent B -> Agent C -> Sales (+ a second Sales user under C); all `nuxr_wp8_*` |
| wallets + funding + transfers (`TransferService`) | Admin funds A 1000.00; A -> B 300.00; B -> C 100.00 -> balances A 700.00 / B 200.00 / C 100.00 (wallets created by the funding / transfers) |
| pricing chain (`PricingService`, each by its authorized actor) | base 10.00 (Admin), A 12.00 (Admin), B 15.00 (A), C 17.00 (B); C retail 22.00 (C) |
| customer | PPPoE customer owned by C, assigned to the Sales user |
| Sales visibility | preview `full = false`, no wholesale / wallet keys, retail 22.00; `WalletService::view` of A / B / C refused; read model `wholesale = null` for Sales (Admin and C see 17.00) |
| NEW activation by the Sales user (`ActivationService`) | CREATED -> VALIDATED -> FUNDS_RESERVED -> PROVISIONING -> PROVISIONED -> VERIFYING -> VERIFIED -> FINANCIAL_COMMITTING -> COMPLETED |
| at `activation.before_commit` (accepted observation point, nothing injected) | state FINANCIAL_COMMITTING; reservation C 17.00 ACTIVE; **no ledger leg yet**; RADIUS policy in place (Cleartext-Password, Simultaneous-Use 1, Expiration, Framed-Pool `nuxr_wp8_pool_dyn`, Mikrotik-Rate-Limit 256K/512K, group `plan_<id>`); **real PPPoE session on CHR**: CHAP accepted, 10.88.10.148, `/ppp active radius = true`, queue 256000/512000; accounting Start with `nasipaddress 10.88.0.2` |
| settlement (original ledger rows) | C `ACTIVATION_DEBIT` 17.00; A `COMMISSION_CREDIT` 3.00; B `COMMISSION_CREDIT` 2.00; activation sealed `wholesale 17.00`, `retail 22.00`, `provider_settlement 12.00` |
| **conservation** | **17.00 (leaf debit) = 5.00 (commissions A 3.00 + B 2.00) + 12.00 (provider settlement)** |
| wallets after | A 703.00 / B 202.00 / C 83.00; each equals the sum of its own ledger rows; `LedgerService::continuity` empty for A, B, C |
| reservation | one, COMMITTED 17.00, `committed_ledger_entry_id` = the debit; nothing reserved afterwards (no orphan) |
| duplicates / replay | 3 distinct entry keys; the same idempotency key again -> same reference, `replayed = true`, ledger byte-identical (no double settlement, rows unchanged) |
| Sales scope | NEW activation by the Sales user for a customer assigned to the other Sales user -> `NOT_FOUND`; no activation row, no RADIUS subscriber written |

The customer-facing retail (22.00) is the activation's sealed retail value (legacy invoice); the reseller wallet leg
is the leaf wholesale (17.00), which is what the conservation identity covers.
