# About this guide

This guide covers the subscriber life cycle in ITC NetCore V1.0: creating a subscriber, assigning and activating a plan, portal access and vouchers. It is written for Admins, resellers (Agents), Sales users and support staff. Sources: `docs/WP2_PERMISSION_MODEL.md` §6, `docs/WP5A_ACTIVATION_MODEL.md`, `docs/WP5B_LEGACY_CLOSURE_MODEL.md`, `docs/OPERATOR_RECOVERY_RUNBOOK.md` §2, `install/freeradius/README.md`, and the controllers and templates named in `OPS_DOCS_SOURCES.md`.

> [!WARNING]
> Release Candidate, not yet production-accepted. The network behaviour was validated on an isolated RouterOS CHR 7.24.5 lab only; the target NAS model has not been tested (TA-03 / TA-04 not run).

# 1. Two kinds of subscriber

| | Direct subscriber (no reseller) | Reseller-owned subscriber |
|---|---|---|
| Owner field | Empty (`manager_id` NULL) | An Agent |
| Created by | SuperAdmin / Admin | Admin (choosing the owner Agent), the Agent itself, or a Sales user of that Agent |
| Plans assigned through | Legacy service tools: Recharge, Refill with a voucher, Extend (Admin), or the portal | The **activation** screen only (NEW / RENEW / REACTIVATE / CHANGE_PLAN) |
| Who pays | The subscriber (portal, balance, voucher) or a free recharge by Admin | The owner Agent's wallet, whoever starts the activation |
| Portal self-service (recharge, buy, voucher, extend, deactivate, balance transfer) | Available, depending on settings | **Refused**: "This service is managed by your reseller and cannot be changed here. Please contact your authorized reseller." |

# 2. Creating a subscriber

**Where:** Subscribers > Add subscriber (or the **+** quick action on a phone).

| Field group | Fields | Notes |
|---|---|---|
| Identity | Username, full name, password, phone, email, address fields, coordinates | The username is the RADIUS login (Hotspot). It must not collide with another subscriber's RADIUS identity |
| Service | Account type, service type | Hotspot or PPPoE for reseller activations |
| PPPoE | PPPoE username, PPPoE password, static PPPoE IP (optional) | A subscriber with both a Hotspot and a PPPoE service needs a **distinct PPPoE username**; a second service type on the same RADIUS key is refused. A static IP must be a valid, non-reserved IPv4 address and **unique across all customers** (every role); leave it empty for an address from the NAS pool |
| Ownership | Owner (Admin only), Sales user (optional) | For an Agent the owner is always itself; for a Sales user the owner is its Agent and the subscriber is assigned to it. Posted owner fields are ignored for Agents and Sales |
| Notification | Send welcome message (SMS / WhatsApp / email channels as configured) | Optional |

**Who can create:**

| Actor | Owner set to | Sales assignment |
|---|---|---|
| SuperAdmin / Admin | Empty (direct subscriber, the default) or an enrolled Active Agent | Optional; only a Sales child of that owner |
| Agent | Itself | Optional; must be an Active, enrolled **direct** Sales child |
| Sales | Its parent Agent | Itself |
| Report, unplaced or frozen accounts | Refused | — |

> [!NOTE]
> Creation is refused when the new identity collides with an existing one anywhere in the instance (for example a `username` equal to another subscriber's `pppoe_username`, or a static IP already assigned to another customer). Agents and Sales get **one generic refusal**, which does not say which value is taken or by whom; Admins get the specific reason. Choose another value. On a fresh install there is no legacy data, so only new colliders can occur.

After creation, changes to the subscriber record (password, PPPoE identity, status, sync) are **Admin** work; Agents and Sales cannot edit, deactivate, delete or sync customers.

<!-- pagebreak -->

# 3. Assigning and activating a plan (reseller-owned subscribers)

**Where:** customer view > *Renew / Change plan*, or Subscribers > Activations > Activate / Renew.

1. Choose the plan. Only plans offered to the selling reseller appear: priced, available, enabled, on the **Radius** device, type Hotspot or PPPOE.
2. Press **Review**. The server derives the action from the subscriber's current service:

   | Current service | Action | New period |
   |---|---|---|
   | None | NEW | Starts now |
   | Same plan, still active | RENEW | Extends from the current expiry |
   | Same plan, expired or off | REACTIVATE | Starts now |
   | Another plan | CHANGE_PLAN | Starts now (no proration) |

3. Check the preview: wholesale price (debited from the owner Agent's wallet), retail price, available credit now and after, new period.
4. A plan change that replaces paid time asks for an explicit acknowledgement that the remaining paid time is lost.
5. **Confirm.** Funds are reserved, the service is provisioned on RADIUS and verified, then settled (one debit). The result page shows the stage timeline and the invoice number.

> [!FINANCE-WARNING]
> With insufficient available credit, Confirm stays disabled and a forced submission is refused by the server. If the network outcome is unclear, the activation is shown as needing follow-up, the reserved amount is **never released automatically**, and nothing is charged twice. An Admin resolves it (`incident_troubleshooting_guide`).

**Common refusals**

| Message / code | Meaning | What to do |
|---|---|---|
| `ACTIVATION_IN_PROGRESS` | The subscriber already has an unfinished activation | Wait; if it stays, an Admin resolves it after 5 minutes |
| `CONCURRENCY_RETRY` ("please try again") | Heavy simultaneous activity on one wallet (MariaDB). The funds may be **held** with nothing charged or granted | Do not retry repeatedly. Ask an Admin to resume or cancel the held activation once it is 5 minutes old |
| Insufficient credit | Available credit below the wholesale price | The reseller is funded by its parent or the provider |

**Renewals.** V1 has no auto-renew for reseller-owned subscribers. When the period ends, the cron enforces the expiry; the reseller renews through the activation screen.

**Early termination.** A reseller-owned service ends only by expiry or by an Admin **reversal** of the completed activation (reason required). Deactivate and "delete active plan" are refused for these subscribers.

# 4. Assigning plans to direct subscribers (Admin)

| Task | Menu | Notes |
|---|---|---|
| Recharge a plan | Services > Recharge Customer | Free recharge needs the `service.recharge_free` capability; balance payments go through the balance service |
| Refill with a voucher | Services > Refill Customer | One atomic redemption, exactly once |
| Refill balance | Services > Refill Balance | Only when balance is enabled in settings |
| Extend, edit plan, deactivate | Customer view / Active Customers | Audited |

# 5. Data and time quota plans

- A **Limited** plan carries a data quota (whole MB or GB, decimal: 1 GB = 10^9 bytes, download + upload per billing period), a time quota (minutes or hours), or both.
- The quota is enforced by the router's own limits plus the accounting enforcer (`quota.php enforce`, every minute). **It is bounded, not byte-exact, and must never be sold as a byte-exact cap.** Usage above the quota is not charged and is not carried into the next period.
- A subscriber who used the quota is refused with "Data quota exhausted or unavailable".
- Support can read a subscriber's period usage: `php system/reseller/quota.php usage <USERNAME>` (actual, billable, overshoot; read-only).

# 6. First connection check

1. The subscriber connects (PPPoE dial or Hotspot login) with the RADIUS credentials.
2. Staff check the customer view (service active, expiry date) and, for PPPoE, that the plan's IP pool exists on the NAS (a missing pool gives Access-Accept without an IPv4 address).
3. If login fails, see `incident_troubleshooting_guide` ("RADIUS reject", "Customer cannot reconnect").

# 7. Customer portal access

| Topic | Behaviour |
|---|---|
| Login | Username and password on the portal login page (it shows the company name and the login announcement) |
| Self-registration | Available unless disabled in settings (`disable_registration`); optional SMS code verification |
| Forgotten password / username | Verification code to phone or email |
| Voucher login | The login page accepts a voucher code; refused when the voucher name collides with a reseller-managed subscriber |
| Status | A Banned subscriber cannot log in to the portal |
| Home | Account information, current plan and expiry, and the widgets enabled under Settings > Widgets |
| Sync button | Re-sends the account to the network (a bounded re-provision; it never grants service) |
| Password change | Allowed for both kinds; it re-provisions the network account |
| Buying a plan online | Direct subscribers only, and only if a payment-gateway plugin is installed and configured by the SuperAdmin. **No payment gateway ships with V1.0** |
| Prepaid deactivation (direct subscribers) | The confirmation states that the remaining paid time is lost and not refunded |

# 8. Vouchers

| Step | Who | Action |
|---|---|---|
| Generate | SuperAdmin / Admin only | Services > Vouchers > Add vouchers: type, router **Radius**, plan, number of vouchers, code format, prefix, code length; optional print |
| Distribute | ISP | Codes are masked in lists and reports; treat printed codes as cash |
| Redeem | Direct subscriber | Portal voucher box, voucher login, or Admin Services > Refill Customer |
| Redeem — reseller-owned subscriber | — | **Refused.** Reseller-owned subscribers are activated by their reseller |
| View | Agents | Vouchers generated inside their subtree (read-only) |
| Review | Operator | A voucher whose grant was not proven stays `CLAIMED` and is never redeemable again until an operator decides (`reconcile.php voucher-review`, recovery runbook §2). Never re-issue a voucher blindly |

# 9. Checklist for a new subscriber

- [ ] Customer created with the correct owner (direct or Agent) and, if used, Sales user
- [ ] Distinct PPPoE username when the subscriber has both service types
- [ ] Plan activated (reseller-owned) or recharged / refilled (direct); invoice number noted
- [ ] First login succeeded; accounting visible
- [ ] Portal login tested; subscriber told how to reach their reseller or the ISP
