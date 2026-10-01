# Verified Planning Findings for Claude to Re-check

These were observed during architecture research against upstream around baseline `d3e0596287322944ee21fcc14dc1798158463140`. They are pointers, not substitutes for Claude's own repository inspection.

1. PHPNuxBill has roles including SuperAdmin/Admin/Report/Agent/Sales and `tbl_users.root` used for subaccounts.
2. Current Agent handling appears designed mainly around immediate children/Sales, not a full arbitrary reseller tree.
3. Customer list/view paths require stronger centralized scope for reseller isolation.
4. Legacy `Balance` methods are customer-balance helpers and are not suitable as reseller wallet accounting.
5. `Package::rechargeUser()` historically catches provisioning failures and can continue internal recharge recording, so reseller finance must not rely on it as the authoritative orchestrator.
6. RADIUS plan update path was observed deleting FreeRADIUS accounting rows after disconnect. This must be re-verified and corrected in the RADIUS-hardening WP.
7. Plugin loading can ignore plugin errors, therefore critical authorization/finance domain logic must not rely on a fail-open plugin registration path.
8. PHPNuxBill supports plugin menus and custom UI/template paths useful for non-critical presentation integration.
9. `Package::getDevice()` selects Radius / MikroTik PPPoE / MikroTik Hotspot device handlers.
10. A candidate variable bug was observed in Hotspot username-change logic and should be verified by tests before reuse.
11. Upstream updater historically catches PDO migration exceptions and continues, so the project should prefer a strict migration verification path for financial schema.
12. The baseline Dockerfile uses PHP 7.4 while README states a minimum PHP 8.2; runtime target must be resolved in WP0.
13. `tbl_plans.price` is used by customer order/plan/cron paths and appears to be a legacy customer/retail price. It must not be silently repurposed as wholesale cost.
14. Auto-renew cron calls legacy `Package::rechargeUser()` and then customer `Balance::min()`. This is a known service/finance bypass candidate for reseller-owned customers and must be inventoried/adapted.
15. Customer order/balance flows and voucher redemption also call `Package::rechargeUser()` in the baseline. Payment/API/plugin/hook callers must be fully inventoried by WP0.
16. `tbl_voucher.generated_by` exists as operator/audit identity; that is not a complete multi-level commercial ownership/settlement model.
17. PHPNuxBill contains a local `rad_acct` helper table while FreeRADIUS code uses standard `radacct` on the RADIUS connection. WP0 must map both and confirm the authoritative accounting source.
18. Upstream license metadata conflicts: README says GPL v2 or later, `LICENSE` contains GPLv2 text, and `composer.json` declares MIT. Preserve all notices and report the conflict rather than assuming a relicensing answer.

Claude must mark each relevant item as `CONFIRMED`, `CHANGED_UPSTREAM`, or `NOT_REPRODUCED` during WP0 with file/function evidence.
