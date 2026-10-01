# Frozen Decisions

D001: PHPNuxBill is the foundation, not something to rewrite from scratch.
D002: FreeRADIUS is primary AAA authority.
D003: MikroTik is NAS/operational integration, not parallel subscriber truth.
D004: Existing `tbl_users.root` is retained for compatibility; recursive hierarchy gets an explicit query-safe model such as closure table.
D005: Customer commercial ownership is separate from `created_by`.
D006: Sales assignment is separate from commercial ownership and creator audit identity.
D007: Existing customers with no commercial owner are Admin/SuperAdmin-only in the new reseller domain until explicitly assigned.
D008: Customer balance is separate from reseller wallet.
D009: Legacy `tbl_plans.price` is not repurposed as reseller wholesale/base cost; wholesale base cost gets explicit new-domain storage.
D010: Retail/customer price and wholesale reseller cost are separate price domains.
D011: Parent/Admin sets a child's wholesale cost; reseller does not set its own wholesale cost.
D012: Financial ledger is append-only; corrections use compensating entries.
D013: Wallet reservations are required for uncertain provisioning operations.
D014: Provider/system settlement is auditable but is not automatically credited to a normal reseller wallet.
D015: Admin/SuperAdmin provider funding/withdrawal is an audited operation separate from reseller parent-child transfers.
D016: Activation is a persistent state machine with event timeline.
D017: UNKNOWN provisioning outcome requires reconciliation and is not clean failure.
D018: `radacct` history is preserved; normal lifecycle actions may not delete it.
D019: Critical permission/finance logic must fail closed and not depend solely on fail-open plugin loading.
D020: DataScope is centralized and applies to screens, APIs, reports and exports.
D021: Agent can be a multi-level reseller; Sales is operational child and sees explicitly assigned customers only.
D022: Direct-child transfer is default V1 reseller transfer policy.
D023: Child wholesale cost cannot be below parent effective wholesale cost in V1.
D024: Plan/pricing snapshots are immutable per activation event.
D025: Commission settlement must conserve the charged wholesale amount.
D026: Auto-renewal, customer self-service, payment callback, API and voucher paths cannot bypass reseller finance for reseller-owned customers.
D027: Reseller voucher-generation/wholesale-commerce is disabled by default in V1 pending a dedicated owner-approved settlement model.
D028: PPPoE and Hotspot are V1; static IPv4 is V1; full IPv6/FUP policy engine deferred.
D029: Desktop/tablet/mobile are adaptive experiences, not one squeezed layout.
D030: PWA is V1 but offline critical mutations are forbidden.
D031: PWA user-specific caches must not leak across logout/account switch and cached data is never mutation authority.
D032: Arabic-first RTL; technical identifiers remain readable LTR.
D033: No permanent reseller delete of subscribers in V1; suspend/disable instead.
D034: V1 frontend defaults to existing PHPNuxBill server-rendered/Smarty stack with progressive enhancement; no new SPA framework without owner decision.
D035: Work is implemented by Claude one WP at a time; owner/spec chat is architecture/review authority.
D036: The upstream baseline is ancestry only. WP1+ builds on the owner-accepted prior-WP HEAD and may not reset to baseline.
D037: Earlier generated implementation drafts are non-authoritative until re-derived/verified by Claude against the repository.
D038: No push/deploy/production by default.
D039: Upstream license metadata conflict (README GPL v2-or-later, GPLv2 LICENSE text, composer MIT) must be verified/reported in WP0; preserve all notices and do not assume relicensing rights.
D040: Every WP produces a durable owner-review artifact in the repository in addition to the chat response.
