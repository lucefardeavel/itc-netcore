# ITC NetCore V1.0 Training Manual: verification record

This record lists, for every workflow in the training manual, the source evidence it was checked against and the screenshots used. It also lists the statements that could not be verified; those statements are **not** in the manual.

| Item | Value |
|---|---|
| Manual folders | `deliverables/docs/training_manual_ar/` (primary, Arabic, RTL) and `deliverables/docs/training_manual_en/` (same structure) |
| Product tree (read-only) | `\\wsl.localhost\Ubuntu-24.04\srv\itcnc\src\final`, branch `release/itc-netcore-v1-final-prep`. **The tree was under active development during this verification:** HEAD moved `e02d784b` -> `0be31b63` (15:02) -> `228f01c9` (15:39, R-HF-CARRY-01: one generic identity refusal for tree-scoped staff on customer create), and the working tree had further uncommitted changes in `DomainError.php`, `CustomerService.php`, `CustomerIdentity.php`, `LegacyReprovisionService.php` and `customers.php`. Line numbers below were re-checked at `228f01c9`; `DomainError.php` is cited by message key because its line numbers kept moving. None of these changes alters a statement in the manual (the tree-scoped refusal keeps the text "This username or PPPoE username is already in use.") |
| Screenshots | `deliverables/screenshots_final/` (`manifest.json`), captured from the final runtime candidate `33624129` with the same ids and file names as the earlier set `deliverables/screenshots/` (release `a6b02eba`); see §7 |
| UI labels | Every quoted label was read from the template's `Lang::T('…')` key and resolved through `system/lan/arabic.json` (Arabic edition) or `system/lan/english.json`, falling back to the source key as `Lang::T` does (`system/autoload/Lang.php:11-31`) |
| Nothing was executed | Static reading of source, templates, language files and docs only. No route was called, no data was changed |

Path abbreviations: `RM` = `system/autoload/Reseller/Security/RouteMap.php`; `RCM` = `system/autoload/Reseller/Security/RoleCapabilityMap.php`; `CAP` = `system/autoload/Reseller/Security/Capability.php`; `NAV` = `system/autoload/Reseller/Ui/Navigation.php`; `T/` = `ui/ui/`; `C/` = `system/controllers/`; `DE` = `system/autoload/Reseller/Domain/DomainError.php`. Screenshot ids are the three-digit prefixes from `manifest.json` (Arabic / English).

## 1. Corrections to earlier material

| Earlier statement | Where | Corrected in the manual to | Evidence |
|---|---|---|---|
| Report role "للاطلاع فقط … التقارير والقوائم دون إجراءات" (view-only reports and lists) | `dev/guide/gen.py` chapter `roles` | The Report role has **no data views**: dashboard message only, menu = Dashboard, Reports returns 403 | `RCM:86-90` (Report = `dashboard.view`, `staff.read`, `self.*`, `api.access` only; no `report.read`); `RM:119-123` (`reports/*` = `report.read`); `NAV:67` (no Services group for Report) and `NAV:88-95` (Reports items filtered by RouteMap); `T/admin/dashboard.tpl:95-99`; `system/autoload/Reseller/Ui/Dashboard.php:26,43`; screenshots 042 / 100, 115, 051 / 116 (manifest note: 403 checked during capture) |
| Base-cost change "with the reason for the change" (reason required) | `gen.py` chapter `pricing`; handover `SUPERADMIN_GUIDE.md` step 6 | "Reason (optional)" | `T/admin/pricing/plans.tpl:39`; `T/admin/nuxr/pricing-row.tpl:49` |
| Refresh button «تحديث العرض» | `gen.py` chapter `activation` | «تحديث التسعير» / "Refresh quote" | `T/admin/activation/create.tpl:148`; `arabic.json` |
| Admin may add "Agent, Sales, Admin or Report" | `gen.py` chapter `resellers` | SuperAdmin creates every role; Admin creates Report, Agent, Sales; Agent creates Agent, Sales as direct children | `system/autoload/Reseller/Domain/StaffService.php:27-38` |
| Portal home shows "voucher activation" for every subscriber | `gen.py` chapter `portal` | The "Voucher" menu item is hidden and redemption is refused for reseller-owned subscribers | `T/customer/header.tpl:152-158` (`!$nuxr_owned`); `C/voucher.php:62-65` (`ServiceGate::guardCustomer`) |
| NAS onboarding without the NAS type | `gen.py` chapter `network` | Type must be `mikrotik_snmp` (default `other` unsupported) | `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 step 3; `T/admin/radius/nas-add.tpl:38-40` (default value `other`); `install/freeradius/README.md:123-124` |
| Service menu captions «إعادة شحن الحساب» | `gen.py` chapter `services` | Actual Arabic label «إعادة شحن العميل» (for both Refill Customer and Recharge Customer) | `NAV:69,72`; `arabic.json` keys `Refill_Customer`, `Recharge_Customer` |

## 2. Workflow evidence

### Chapter 1 — Introduction

| Workflow / statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| Roles and their capability sets | `RCM:24-51` (ADMIN_COMMON), `:55-57` (SuperAdmin + `system.code/backup/restore`), `:59` (Admin), `:60-76` (Agent), `:77-85` (Sales), `:86-90` (Report); role labels `arabic.json` (`SuperAdmin`→مدير النظام, `Admin`→مدير, `Agent`→الوكيل, `Sales`→مبيعات, `Report`→تقارير) | — |
| Server-side authorization; hidden menu is not protection | `RM:9-27` (unmapped denied), `NAV:14-19` (docblock), `system/autoload/Reseller/Security/RouteGuard.php:60-101` | — |
| Staff login (`/admin`, labels, Login) | `RM:35`; `T/admin/admin/login.tpl:9,36-47` | 060 / 001 |
| Inactive account gets the same message | `C/admin.php:34-35,57-63` ("Invalid Username or Password") | — |
| Default credential forces password change | `RouteGuard.php:117-123` (428 except `settings/change-password[-post]`) | — |
| Change password labels | `T/admin/change-password.tpl:6-29`; `T/admin/header.tpl:92` | — |
| No login throttling | `reports/RBAC_MATRIX.md` F-7; `deliverables/docs/known_limitations` §7 | — |
| CSRF on every changing POST; error reference | `docs/WP2_PERMISSION_MODEL.md` §8, §10 (via `STAFF_OPERATIONS_GUIDE.md` §5); `RM:23-26` | — |
| 403 page "Access denied" / "You cannot open this page" | `T/admin/403.tpl:10-12`; `T/admin/nuxr/state-page.tpl:7-8` | — |
| Staff/customer sessions not separated | `deliverables/docs/known_limitations` §7 (UX-13) | — |
| Sidebar groups and items | `NAV:46-134`; `T/admin/nuxr/nav-groups.tpl:7-18` | 062 / 004 |
| Quick actions | `NAV:142-148`; `T/admin/dashboard.tpl:12-16` | 062, 088 / 004, 030 |
| Search field, `/` shortcut | `T/admin/header.tpl:72-78`; `ui/ui/scripts/nuxr-shell.js:199` | visible in 088 / 030 |
| Account menu (My Account, Change Password, language, theme, Install app, Logout) | `T/admin/header.tpl:85-98` | — |
| Dashboard tabs | `T/admin/dashboard.tpl:5-9` | — |
| Language switch stored per browser | `nuxr-shell.js:122-128` (cookie `user_language`); `T/admin/header.tpl:93-94` | 119 / 055 (dark theme) |
| Portal language selector | `T/customer/header.tpl:59-66` | — |
| Phone bottom bar (Home / Customers / New / Wallet or Activations / More) | `NAV:138-150` | 106, 107 / 047, 048 |
| PWA install only in secure context; offline page; nothing queued | `T/admin/header.tpl:96-97`; `ui/ui/scripts/nuxr-pwa.js:22,271`; `nuxr-sw.js:1-16` | 105 / 003 |
| Duplicate Arabic labels note (Arabic edition only) | `arabic.json`: `Refill_Customer` = `Recharge_Customer` = «إعادة شحن العميل»; `Vouchers` = `Coupons` = «القسائم»; order `NAV:68-74` | — |

### Chapter 2 — SuperAdmin

| Workflow / statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| SuperAdmin-only capabilities | `RCM:55-57`; `RM:151` (`dbbackup`/`dbrestore`), `:296` (gateway), `:300` (plugins), `:308` (pages) | — |
| SuperAdmin-only setting keys silently skipped for Admin | `C/settings.php:352-354` | — |
| First login; schema-not-ready banner | `RouteGuard.php:117-123`; `T/admin/header.tpl:128` | — |
| Initial setup order | `reports/handover/guides/SUPERADMIN_GUIDE.md` §3 (verified item by item: `T/admin/settings/app.tpl:24,529`; `NAV:105,128`; `RM:256-260,209-219`) | — |
| Company Name (General section, help text) | `T/admin/settings/app.tpl:15-29`; `docs/BRAND_IDENTITY.md` §2 | 086, 137 / 028, 128 |
| Secret settings write-only | `C/settings.php:355-356`; `SUPERADMIN_GUIDE.md` §1 | — |
| Add staff (labels, User Type, Parent Agent, help texts, Save Changes) | `T/admin/admin/add.tpl:9-101`; `T/admin/admin/list.tpl:31`; `RM:144` | — |
| Creatable roles per actor | `StaffService.php:27-38` | — |
| Agent never deleted, role permanent, last SuperAdmin, admin-disabled account | `StaffService.php:222,267,307,349`; `DE` `LAST_SUPERADMIN`, `AGENT_DELETE_PROHIBITED`, `AGENT_ROLE_PERMANENT` | — |
| Reseller tree and detail (Add Agent / Sales, Disable, Reactivate, Move, confirmations, Place) | `T/admin/reseller/tree.tpl:13,73-94`; `T/admin/reseller/detail.tpl:21-24,80-96`; `RM:229-241` | 069, 070 / 011, 012 |
| Frozen wallet blocks money operations | `DE` `WALLET_OWNER_FROZEN`; `T/admin/finance/wallet.tpl:18`; `deliverables/docs/reseller_onboarding` §9 | — |
| Plans on Radius, Hotspot/PPPoE only for reseller activation | `DE` `PLAN_DEVICE_NOT_SUPPORTED`, `BALANCE_PLAN_NOT_RESELLABLE`; `deliverables/docs/known_limitations` §7 | 066, 067 / 008, 009 |
| Pricing screens and rules | `T/admin/pricing/plans.tpl:14-49`; `T/admin/pricing/plan.tpl:15`; `T/admin/nuxr/pricing-row.tpl:4-76`; `RM:209-219`; `DE` `WHOLESALE_BELOW_PARENT`, `RETAIL_BELOW_WHOLESALE`, `ZERO_WHOLESALE_NOT_SUPPORTED`; example from `docs/WP3B_PRICING_MODEL.md` §7 via `reseller_onboarding` §6 | 068 / 010 |
| Voucher generation (Admin only; labels; Generate) | `RM:103-106`; `RCM:24-51` vs `:60-90`; `T/admin/voucher/add.tpl:7-93`; `T/admin/voucher/list.tpl:11-12` | 081, 080 / 023, 022 |
| Codes masked; CLAIMED vouchers under review | `docs/OPERATOR_RECOVERY_RUNBOOK.md` §2; screenshots README "Masking" | — |
| System-wide reports | `NAV:88-95`; `RM:119-128,202-205` | 082, 083 / 024, 025 |
| Export limits (20,000 rows, 366 days) | `DE` `EXPORT_RANGE_REQUIRED`, `EXPORT_TOO_LARGE`; `financial_reconciliation_guide` §6 | — |
| Security settings (Enable Session Timeout, Single Admin Session) | `T/admin/settings/app.tpl:399-445` | — |
| NAS form (labels) | `T/admin/radius/nas-add.tpl:7-78`; `T/admin/radius/nas.tpl:27-41`; `RM:256-260`; `NAV:105` (needs `radius_enable`) | 085, 084 / 027, 026 |
| NAS procedure (type `mikrotik_snmp`, port 3799, secret, router steps, restart FreeRADIUS) | `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §1-§3; `install/freeradius/README.md:587-611` | — |
| Operations Center sections | `T/admin/operations/overview.tpl:7-23`; `RM:221-228` | — |
| Web restore refused | `C/settings.php:978-992`; `system/autoload/Reseller/Security/RestoreGuard.php:7-46`; `T/admin/settings/dbstatus.tpl:6-56` | — |
| Logs (ITC NetCore / Radius / Message; Activity Log; Clean up Logs) | `NAV:126-130`; `T/admin/logs/system.tpl:15,42`; `T/admin/logs/radius.tpl` | — |
| No screen for the reseller audit trail | search of `ui/ui/admin` for "audit": only `paymentgateway/audit.tpl` and a comment in `customers/view.tpl` | — |

### Chapter 3 — Reseller and sub-reseller

| Workflow / statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| Scopes T / C / S; placement; frozen | `RCM:13-19,60-76`; `CAP:89-110` (MUTATING, PLACEMENT_REQUIRED); `reports/RBAC_MATRIX.md` §1 modifiers | — |
| Agent dashboard and menu | `T/admin/dashboard.tpl:19-89`; `NAV:46-134` filtered by `RCM:60-76` | 088, 111, 097 / 030, 049, 039 |
| Customer scope table (can / cannot) | `RCM:60-76` (no `customer.update/sync/deactivate/delete/export/impersonate`, no `service.*`, no `voucher.generate`, no `message.bulk`, `activation.create` = S) | 089 / 031 |
| Add subscriber (steps, labels, messages) | `T/admin/customers/add.tpl:6-157`; `T/admin/customers/view.tpl:11-12`; `RM:82` | 064 / 006 |
| Identity collision refused | `DE` `IDENTITY_TAKEN` / `CUSTOMER_IDENTITY_TAKEN` (same English text; no Arabic translation in `arabic.json`) | — |
| Activation steps, labels, action types | `T/admin/activation/create.tpl:7-153`; `RM:157-172` | 090, 091, 092, 112 / 032, 033, 034, 050 |
| Plan-change acknowledgement (client and server) | `T/admin/activation/create.tpl:144-151`; `C/activation.php:49-60` ("Please confirm that the remaining paid time will be lost.") | 092, 112 / 034, 050 |
| Insufficient credit keeps Confirm disabled; server refuses | `T/admin/activation/create.tpl:131-134,151`; `DE` `INSUFFICIENT_AVAILABLE_CREDIT` | 091 / 033 |
| Owner's wallet always debited | `RCM:47,73-75,83` comments; `T/admin/activation/create.tpl:118` | — |
| Activation result page (Progress, Completed, Timeline, details) | `T/admin/activation/view.tpl:20-134` | 079 / 021 |
| ACTIVATION_IN_PROGRESS, preview changed messages | `DE` `ACTIVATION_IN_PROGRESS`, `ACTIVATION_PREVIEW_CHANGED` | — |
| No auto-renew for reseller-owned | `system/autoload/Reseller/Domain/AutoRenewPolicy.php:9-13` | — |
| Pricing by Agent (child wholesale/availability, own retail) | `RCM:72` (`PRICING_CHILD_WHOLESALE` C, `PRICING_CHILD_AVAILABILITY` C, `PRICING_OWN_RETAIL` S); `T/admin/nuxr/pricing-row.tpl:37-76`; `T/admin/pricing/plan.tpl:15` | — |
| Wallet overview, ledger, formula | `T/admin/finance/overview.tpl:8-45`; `T/admin/nuxr/wallet-money.tpl:5-12`; `T/admin/finance/ledger.tpl:7-36` | 095 / 037 |
| Send to child (labels, review, no typed confirmation) | `T/admin/finance/send.tpl:7-39`; `T/admin/finance/review.tpl:28-34,55-66`; `system/autoload/Reseller/Backoffice/WalletDesk.php:242` (`typed => null`); `RM:188-190`; `C/finance.php:188` ("Already recorded…") | 093, 094 / 035, 036 |
| Direct child only | `RCM:69` (`WALLET_TRANSFER` C); `DE` `NOT_DIRECT_CHILD` | — |
| Create sub-reseller / Sales as direct child | `StaffService.php:35`; `T/admin/admin/add.tpl:58-72` | — |
| Agent manages descendants (D), cannot delete / re-role / move | `RCM:65` (`STAFF_UPDATE_PROFILE` D, `STAFF_DISABLE` D); `RM:234-236`; `StaffService.php:222` | — |
| Commission recipient needs a wallet | `DE` `COMMISSION_RECIPIENT_NO_WALLET` | — |
| Agent reports (Sales, Commissions, Reseller performance; not settlement or legacy) | `NAV:88-95` (`finance/settlement` shown only to admins); `RM:119-123` (`report.read` not held by Agent) | 096 / 038 |
| Error table messages | `DE` `INSUFFICIENT_AVAILABLE_CREDIT`, `WALLET_OWNER_FROZEN`, `CONCURRENCY_RETRY`, `ACTIVATION_IN_PROGRESS`; `T/admin/activation/create.tpl:81-82`; `T/admin/activation/view.tpl:47`; `ServiceGate.php:23` | — |

### Chapter 4 — Sales and operational staff

| Workflow / statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| Sales permissions (scope A) | `RCM:77-85`; `system/autoload/Reseller/Security/DataScope.php` (scope A) | 098, 099, 114 / 040, 041 |
| Sales owner text | `T/admin/customers/add.tpl:122-125` | — |
| Sales sees no wholesale or credit in the review | `system/autoload/Reseller/Activation/ActivationPreviewService.php:83-89` (`full` only for scope G or S); `T/admin/activation/create.tpl:118-127` | — |
| Sales menu and phone bar | `NAV:46-134,138-150` filtered by `RCM:77-85` | 098, 114 / 040 |
| Admin = SuperAdmin minus code / backup / restore / SA-only keys | `RCM:59`; `C/settings.php:352-354` | — |
| Direct vs reseller-owned subscriber; recharge redirect message | `C/customers.php:198-215`; `T/admin/customers/view.tpl:74-102,156` | 063, 065 / 005, 007 |
| Edit / Synchronize / Network change not confirmed | `T/admin/customers/view.tpl:25-26,283-291,320`; `RM:84-86` | 065 / 007 |
| Deactivate plan dialog and server refusal for reseller-owned | `T/admin/customers/view.tpl:134-148`; `C/customers.php:282-293`; `ServiceGate.php:23,82-88` | 087 / 029 |
| Delete subscriber (typed username; refusal) | `T/admin/customers/view.tpl:297-336`; `RM:88` | 065 / 007 |
| Ownership actions | `T/admin/customers/view.tpl:74-102,352-371`; `RM:237-240` | 065 / 007 |
| Bulk messages admin-only | `RM:116`; `RCM:32` (`MESSAGE_BULK` only in ADMIN_COMMON) | — |
| Permission summary table | `RCM:24-90`; `RM:33-310`; `StaffService.php:27-38` | — |

### Chapter 5 — Report role

| Statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| Login, logout, dashboard message, profile, password | `RCM:86-90`; `RM:35,39,74,145,148`; `T/admin/dashboard.tpl:95-99` | 100, 115 / 042 |
| Menu = Dashboard only | `NAV:46-134` (every item gated by a capability Report lacks; `settings/users` gated by role list `NAV:121`) | 116 / 051 |
| Reports → 403 Access denied | `RM:119-123`; `RouteGuard.php:97`; `T/admin/403.tpl:10`; manifest notes of 042 / 100 / 115 | — |
| Extending the role needs a code change | `CAP:7-10` (code-defined catalogue, no DB editor) | — |

### Chapter 6 — Subscriber portal

| Workflow / statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| Direct vs reseller-owned table; refusal message | `ServiceGate.php:23,71-88`; `C/home.php:25,85,144,202-203`; `C/order.php:180,218,319,405,554`; `C/voucher.php:65` | — |
| Login labels; Banned message | `T/customer/login.tpl:10-82`; `C/login.php:37-41` | 136, 140 / 127, 132 |
| Account card labels | `T/widget/customers/account_info.tpl:3-18` | 138, 142 / 130, 133 |
| Plan card labels, Sync, renew-through-agent text | `T/widget/customers/active_internet_plan.tpl:27-158` | 138, 142 / 130, 133 |
| Portal sidebar items and conditions | `T/customer/header.tpl:139-192` | 138 / 130 |
| Recharge leads to the gateway page; no gateway ships | `C/home.php:118-134` (`order/gateway`); `system/paymentgateway/` contains only `index.html`, `ui` | — |
| Extend only for expired non-prepaid with `extend_expired` | `T/widget/customers/active_internet_plan.tpl:138` | — |
| Voucher redemption (labels, messages, refusal) | `T/customer/activation.tpl:8-34`; `T/widget/customers/voucher_activation.tpl:7-34`; `C/voucher.php:62-77`; `T/customer/header.tpl:152-158` | 139, 143 / 131, 134 |
| Prepaid deactivation warning, direct only | `T/widget/customers/active_internet_plan.tpl:124-135`; `C/home.php:198-203`; no refund flow: `known_limitations` §7 | not captured (screenshots README "Not captured") |
| Inbox, Payment History, Activation History, User Notification | `T/customer/header.tpl:72,180-188`; `RM:61,64,71`; `NAV:119` | — |

### Chapter 7 — Financial operations

| Workflow / statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| No direct DB repair | `docs/OPERATOR_RECOVERY_RUNBOOK.md` §8; `financial_reconciliation_guide` (Finance callout, §9) | — |
| Concepts, wallet opening, available formula | `T/admin/nuxr/wallet-money.tpl:5-12`; `T/admin/finance/ledger.tpl:10` | 071, 072 / 013, 014 |
| Funding / withdrawal steps and labels | `T/admin/finance/funding.tpl:10-29`; `T/admin/finance/review.tpl:9-66`; `T/admin/finance/wallet.tpl:26-27`; `RM:185-187`; `NAV:147` | 073, 074, 110 / 015, 016 |
| Typed confirmation: withdrawal, debt-line decrease, debit adjustment (username); transfer reversal (reference); none for funding / transfer / credit adjustment | `WalletDesk.php:194,242,275,332,368,489-491`; `T/admin/finance/review.tpl:60-62` | — |
| Review expired, already recorded, mismatch | `DE` `REVIEW_EXPIRED`, `CONFIRMATION_MISMATCH`; `C/finance.php:188,230,295` | — |
| Reason 3–255 characters | `DE` `INVALID_REASON` | — |
| Debt line / adjustments labels and rules | `T/admin/finance/debt-limit.tpl:8-22`; `T/admin/finance/adjust.tpl:8-26`; `T/admin/finance/wallet.tpl:33-38`; `DE` `DEBT_LIMIT_EXPOSURE`, `DEBT_LIMIT_CHANGED`; `RM:192-197` | — |
| Transfer reversal | `T/admin/finance/transfer.tpl:24-25`; `T/admin/finance/reverse.tpl:7-30`; `RM:198-200` | 076, 077 / 018, 019 |
| Activation debit, settlement, commissions | `T/admin/activation/view.tpl:32-41`; `financial_reconciliation_guide` §3 | 075 / 017 |
| HELD never auto-released | `system/reseller/health.php:19` ("N-09: never expire on their own"); `financial_reconciliation_guide` §4; `known_limitations` §5.2 | — |
| Activation operator actions, states, dialogs, 5-minute floor | `T/admin/activation/view.tpl:31-60,88-185`; `RM:164-171`; `C/activation.php:15-16`; `financial_reconciliation_guide` §4.1 | 078 / 020 |
| Reverse a completed activation | `T/admin/activation/view.tpl:60-64,176-185`; `RM:167`; `RCM:50`; `DE` `REVERSAL_EXTERNAL_STATE_CHANGED` | — |
| Reconciliation routine, finance-check exit 4 | `docs/OPERATOR_RECOVERY_RUNBOOK.md` §0; `financial_reconciliation_guide` §6-§7; `health.php:27` | — |
| Audit content | `financial_reconciliation_guide` §8 | — |

### Chapter 8 — Network operations

| Statement | Source evidence | Screenshots AR / EN |
|---|---|---|
| NAS list columns, routers | `T/admin/radius/nas.tpl:34-41`; `NAV:101,105` | (085, 084 / 027, 026 in Ch. 2) |
| FreeRADIUS reads clients at start | `docs/NAS_ONBOARDING_AND_SECRET_ROTATION.md` §2 step 5 | — |
| Session guard, 10 s hold, self-check reasons | `install/freeradius/README.md:123-124,362`; `incident_troubleshooting_guide` §7 | — |
| Connection card states and labels | `T/admin/customers/view.tpl:163-185` | 108 / — |
| Recovery actions | `docs/OPERATOR_RECOVERY_RUNBOOK.md` §3, §4, §4a, §6 | — |
| Disconnect/CoA behaviour; port; source rule; CoA limits | `install/freeradius/README.md:575-611` | — |
| No disconnect button in the staff UI | `T/admin/customers/view.tpl:3-5` ("No Disconnect / Suspend (OD-E…)") | — |
| Quota behaviour and labels | `T/admin/customers/view.tpl:193-204`; `known_limitations` §6; `docs/OPERATOR_RECOVERY_RUNBOOK.md` §4b | 065 / 007 |
| Clock contract | `docs/TIME_CONTRACT.md:11-12,59-72`; `OPERATOR_RECOVERY_RUNBOOK.md` §4c | — |
| Health checks and exit codes | `system/reseller/health.php:8-27` | — |

### Chapter 9 — Troubleshooting

Each case was checked against `deliverables/docs/incident_troubleshooting_guide` §1-§10 and the sources named there, and its user-facing messages against `DE` and the templates cited for Chapters 3, 6 and 7. RADIUS reply messages ("Data quota exhausted or unavailable", "Quota unavailable", "Session verification failed") are quoted in English in both editions because they are sent by FreeRADIUS, not by the language files (`OPERATOR_RECOVERY_RUNBOOK.md` §4b; `install/freeradius/README.md`). No screenshots.

### Chapter 10 — Training exercises

| Exercise | Built on |
|---|---|
| 1 Create reseller | Ch. 2.4 evidence (`T/admin/admin/add.tpl`, `T/admin/reseller/tree.tpl:22`) |
| 2 Create customer | Ch. 3.3 evidence; Sales visibility `RCM:78` |
| 3 Assign plan (pricing) | Ch. 2.6 evidence; `DE` `RETAIL_BELOW_WHOLESALE` |
| 4 Fund wallet | Ch. 7.2 evidence; `C/finance.php:188` |
| 5 Activate | Ch. 3.4 / 4.1 evidence; `ActivationPreviewService.php:83-89` |
| 6 Renew / change plan | `T/admin/activation/create.tpl:95-98,144-151` |
| 7 Redeem voucher | Ch. 6.5 evidence; `T/admin/customers/add.tpl:93` |
| 8 Verify RADIUS session | Ch. 8.4 evidence |
| 9 Review invoice | `T/admin/customers/view.tpl:212,247`; `T/admin/plan/invoice.tpl:17-28`; `RM:99-100` |
| 10 Operational exception | Ch. 7.3 / 7.8 evidence |

All exercise names (`trn_*`) are synthetic placeholders. No password is given.

### Chapter 11 — Competency and assessment

Every question's answer is cross-referenced to a manual section in Appendix A; each of those sections is covered above.

## 3. Screenshots used

The 11 product-mode customer-portal images flagged in the manifest (header defect: 043, 044, 052, 053, 059, 101, 102, 117, 118, 122, 125) are **not** used. Operator-mode portal images are used instead. No image was edited.

**Arabic edition (58 images, all `-ar-`):** 060, 062, 063, 064, 065, 066, 067, 068, 069, 070, 071, 072, 073, 074, 075, 076, 077, 078, 079, 080, 081, 082, 083, 084, 085, 086, 087, 088, 089, 090, 091, 092, 093, 094, 095, 096, 097, 098, 099, 100, 105, 106, 107, 108, 110, 111, 112, 114, 115, 116, 119, 136, 137, 138, 139, 140, 142, 143.

**English edition (54 images, all `-en-`):** 001, 003, 004, 005, 006, 007, 008, 009, 010, 011, 012, 013, 014, 015, 016, 017, 018, 019, 020, 021, 022, 023, 024, 025, 026, 027, 028, 029, 030, 031, 032, 033, 034, 035, 036, 037, 038, 039, 040, 041, 042, 047, 048, 049, 050, 051, 055, 127, 128, 130, 131, 132, 133, 134.

The English edition has fewer images because the screenshot set has no English phone captures for: customer view (108), funding review (110), Sales dashboard (114) and Report dashboard (115). The chapter structure is identical.

Caption cautions recorded in the manual itself:
- 139 / 131, 143 / 134 (voucher page) were opened by URL for a reseller-owned lab account; the manual states that the menu item is hidden for such subscribers and redemption is refused.
- 087 / 029 (Deactivate plan dialog) is for reseller-owned subscriber `labcA1`; the manual states that the server refuses the action for such subscribers.
- The portal deactivation dialog was not captured (screenshots README "Not captured").

## 4. UI label issues observed (for the owner; the manual quotes them as they are)

| Issue | Evidence |
|---|---|
| «إعادة شحن العميل» is used for both "Refill Customer" and "Recharge Customer" | `arabic.json`; `NAV:69,72` |
| «القسائم» is used for both "Vouchers" and "Coupons" | `arabic.json`; `NAV:70-71` |
| Portal menu item "Voucher" is a literal English string | `T/customer/header.tpl:156` |
| "Radius Plan" shown in English on the Arabic portal | `T/widget/customers/active_internet_plan.tpl:21` resolves, but screenshot 138 shows "Radius Plan"; screenshots README "Known issues" 3 |
| Settings section titles "FreeRadius" and "Voucher" are literal English | `T/admin/settings/app.tpl:457-459,521-523` |
| `IDENTITY_TAKEN` message has no Arabic translation | `DE` `IDENTITY_TAKEN`, `CUSTOMER_IDENTITY_TAKEN`; absent from `arabic.json` |
| The voucher list's "add" button is labelled "Vouchers" rather than "Add Vouchers" | `T/admin/voucher/list.tpl:11-12` |

## 5. Statements not verified (left out of the manual)

| # | Statement | Why not verified | How the manual handles it |
|---|---|---|---|
| U-1 | How to send "one Disconnect from the application" as the NAS onboarding check (`NAS_ONBOARDING…` §2 step 6) | The staff UI has no disconnect action (`T/admin/customers/view.tpl:3-5`); the onboarding doc does not name the mechanism | The manual's expected result for NAS setup lists only a test login, an accounting record and `preflight.php`; manual disconnect is described as router-side or `reconcile.php session-end` |
| U-2 | What the NAS form fields "Server" and "SNMP community" do | The fields exist (`T/admin/radius/nas-add.tpl:45-51`), but the guard reads its community from the FreeRADIUS credential file (`NAS_ONBOARDING…` §1); the use of the form field was not traced | Not mentioned as a step; the manual names the credential file (operator task) |
| U-3 | Which customer widgets (voucher box, balance transfer, recharge a friend, order button) are enabled by default on the portal home | Widget configuration is data, not code; not checked on a fresh install | The manual describes the menu path and the plan card only |
| U-4 | Default of self-registration and of SMS OTP at registration | Configuration-dependent (`C/register.php`) | "Unless the ISP disabled it" |
| U-5 | Which setting switches the portal login to phone number or email | `T/customer/login.tpl:26-30` shows the variants; the setting was not traced | "If the ISP configured that" |
| U-6 | Default notification channels and template content | Configuration data | Only the menu path "User Notification" is given |
| U-7 | Agent voucher list content in practice | List scoped by `generated_by` (`DataScope.php:85-88`); whether any voucher ever appears for an Agent depends on data | The manual says only that Agents and Sales cannot generate vouchers; it does not describe the Agent's list contents |
| U-8 | Exact behaviour of "Enable Session Timeout" and "Single Admin Session" beyond their help texts | Not traced into the session code | Described only by the on-screen help text |
| U-9 | Exercise 8 (live RADIUS session) and exercise 10 scenario A (stale activation) | Not executable in the lab: FreeRADIUS inactive and the CHR router stopped; a stale activation needs fault injection. The other exercises were executed in the browser on 2026-10-29 (§7) | Exercises stay as written; the trainer runs exercise 8 and scenario A in a training environment with a router |
| U-10 | Quota figures (enforcer pass interval, Hotspot overshoot percentages) | Taken from docs, not re-measured | The manual states only "every few seconds" and "a small amount" |
| U-11 | Customer `auto_renewal` switch effect for direct subscribers | Not traced | Not described; only the reseller-owned case (no auto-renew) is stated, from `AutoRenewPolicy.php:9-13` |

Statements from the earlier guide that were dropped because they are outside the manual's scope or not re-verified: message channels (SMS / WhatsApp / Telegram) details, maps and ODP pages, custom fields, maintenance mode, payment-gateway and plugin pages, and the system requirements list.

## 6. Addendum 2026-10-29: label issues of §4 fixed on the final-prep branch

The UI / i18n commit `012a9e91` (`LAB:reports/UI_I18N_FIXES_20261029.md`) fixed every issue of §4: distinct Arabic labels (Refill Customer «شحن العميل بقسيمة», Recharge Customer «إعادة شحن اشتراك العميل», Refill Account «شحن الحساب بقسيمة», Coupons «الكوبونات», Vouchers «القسائم»); the portal menu item "Voucher" and the settings titles "Voucher" and "FreeRADIUS" translated; default plan titles such as "Radius Plan" shown translated; Arabic entries for the refusal messages, including `IDENTITY_TAKEN`, `CUSTOMER_IDENTITY_TAKEN`, `STATIC_IP_INVALID` and `STATIC_IP_TAKEN`; the voucher list button reads "Add Vouchers" («إضافة قسائم»). The product-mode portal header defect of §3 is fixed too.

Both manuals now quote the corrected labels; the note on duplicate Arabic labels (row "Duplicate Arabic labels note" of §2) was replaced by a note listing the distinct labels. The screenshots predate the fix: the manuals say so, and the 11 product-mode portal images stay unused until they are recaptured. The per-file change log is `RECONCILIATION_20261029.md`.

## 7. Addendum 2026-10-29: final screenshots and exercises executed

- **Screenshots recaptured on the final runtime candidate `33624129`** (`deliverables/screenshots_final/`, 143 images, same ids and file names). Every image used by the two manuals (54 English, 58 Arabic) was replaced by its final version, so the note that the screenshots predate the label fixes was removed from both manuals. The product-mode portal header is fixed and verified (mark and "ITC NetCore" on one line, fully visible) in the 11 product-mode portal images.
- **Chapter 10 exercises executed** in a real browser against the lab, through the UI only, with synthetic `trn_` records (`scripts/e2e_14_training_exercises.mjs`; evidence `LAB:evidence/e2e/training_validation_20261029T141441Z/`; report `LAB:reports/TRAINING_VALIDATION_20261029.md`). Exercises 1-7, 9 and 10 scenario B PASS; exercise 8 and exercise 10 scenario A NOT_RUN (see U-9). Where a step or label did not match the real UI, both manuals were corrected; the list of corrections is in the report.

