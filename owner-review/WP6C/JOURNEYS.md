# WP6C Real Journeys (candidate)

These are real sessions against the lab: real POSTs, server answers and redirects, with emulated Chromium devices. The candidate is `cd7b43c0`. Each journey ends with a screenshot in `visual/journeys/`. A journey fails on any uncaught JavaScript error or browser `window` dialog. Status-line console entries for intended 403/404 pages are expected.

| ID | Journey | Role | Viewport | Lang | Result | Evidence |
|---|---|---|---|---|---|---|
| [J01](visual/journeys/J01.png) | Login and logout (phone) | A | 390x844 | en | **PASS** | landed dashboard; after logout dashboard -> login form (200) |
| [J02](visual/journeys/J02.png) | Dashboard (Admin, 360 Arabic) | ad | 360x780 | ar | **PASS** | dashboard rtl, bottom nav True, overflow False |
| [J03](visual/journeys/J03.png) | Primary navigation (Agent phone) | A | 390x844 | en | **PASS** | visited ['customers/list', 'finance/overview', 'dashboard']; current marked |
| [J04](visual/journeys/J04.png) | More sheet (Sales phone, Arabic) | A1 | 390x844 | ar | **PASS** | 10 links; opened 6: all 200 |
| [J05](visual/journeys/J05.png) | List to detail and Up (Agent phone) | A | 390x844 | en | **PASS** | customers/view/11 -> Up -> customers/list |
| [J06](visual/journeys/J06.png) | Search subscribers (Agent phone) | A | 390x844 | en | **PASS** | typeahead 1 hits -> customers/view/9 |
| [J07](visual/journeys/J07.png) | Filters on Active Customers (Admin phone, Arabic) | ad | 390x844 | ar | **PASS** | status filter applied (on); disclosure stays open with an active filter; no overflow |
| [J08](visual/journeys/J08.png) | Create then edit a bandwidth profile (Admin phone) | ad | 390x844 | en | **PASS** | created wp6c_bw_1791196648; edited upload rate -> row: wp6c_bw_1791196648 8 Kbps / 3 Kbps Edit  — confirmation sheet: Continue the Bandwidth addition process?; confirmation sheet: Continue the Bandwidth change process? |
| [J09](visual/journeys/J09.png) | Validation failure: browser field check, then a server refusal (Admin phone) | ad | 390x844 | en | **PASS** | empty required fields ['name', 'price']: browser message, no confirmation, no request; wrong current password -> "Incorrect Current Password" on settings/change-password |
| [J10](visual/journeys/J10.png) | Save a customer edit via the sticky row (Admin phone, Arabic) | ad | 390x844 | ar | **PASS** | sticky row (sticky); saved -> customers/view/14; message User Updated Successfully — confirmation sheet: متابعة تعديل بيانات المشترك؟ |
| [J11](visual/journeys/J11.png) | Up on activation / pricing / finance detail (Agent phone) | A | 390x844 | en | **PASS** | [('activation/view/ACT-0F687A16AC114504', 'activation/list'), ('finance/transfers', 'no Up (top-level)'), ('customers/edit/9', 'customers/view/9')] |
| [J12](visual/journeys/J12.png) | Keyboard open on a long form (Admin phone) | ad | 390x844 | en | **PASS** | bottom nav hidden; action row at the bottom edge (0px); focused field covered: False |
| [J13](visual/journeys/J13.png) | Activation create -> review -> confirm (Agent phone) | A | 390x844 | en | **PASS** | activation%2Fcreate -> confirm -> activation/view/ACT-15B80C1CC1B8C3A8 (ACT-15B80C1CC1B8C3A8) — review: activation%2Fcreate |
| [J14](visual/journeys/J14.png) | Finance send: review -> confirm (Agent phone) | A | 390x844 | en | **PASS** | finance/send-review -> confirm -> finance/transfer/3; Recorded. |
| [J15](visual/journeys/J15.png) | Stale debt-limit review is refused (Admin phone) | ad | 390x844 | en | **PASS** | old review confirmed after a concurrent change -> "This confirmation is no longer valid; nothing was changed. Review the operation again." on finance/debt-limit-post |
| [J16](visual/journeys/J16.png) | Pricing: edit a child wholesale in the editing surface (Agent phone, Arabic) | A | 390x844 | ar | **PASS** | full-screen surface True; current Rp. 15; saved -> تم الحفظ. |
| [J17](visual/journeys/J17.png) | Permission-restricted pages stay in the shell (Agent + Sales) | A | 390x844 | ar | **PASS** | Agent plan/edit -> 403 in shell with 2 recovery links; Agent sees 0 row menus on plan/list; Sales finance/wallets -> 403 in shell |
| [J18](visual/journeys/J18.png) | Empty and error states (Admin phone) | ad | 390x844 | en | **PASS** | 404 in shell (404); empty search -> "No subscribers match this search. Try the userna" |
| [J19](visual/journeys/J19.png) | Operations Center (Admin desktop) and stale cancel dialog (phone) | ad | 390x844 | en | **PASS** | overview 200 (10 links), activations 200; stale cancel dialog open True |
| [J20](visual/journeys/J20.png) | Same pages at phone / tablet / landscape | A | 844x390 | ar | **PASS** | 12 page views, overflow in none |

**Result: 20 of 20 PASS.**

## Defects the journeys found, fixed before the candidate (`8ffbf54a`)
1. **A tap on Save while a text field had focus could miss.** Focus left the field, the bottom navigation reappeared and the sticky row moved between press and release. The navigation and row now return after 0.4 s.
2. **The confirmation question opened for a form the browser would refuse.** Invalid fields now show their own messages first.

## Notes
- **J08** created two bandwidth profiles in the lab database. The lab is disposable.
- **J15** used two sessions: the second changed the debt limit between the first session's review and confirm. The server refused the stale confirm with `REVIEW_EXPIRED` wording, and nothing changed.
- **J04** excludes the lab-only WP2 probe plugins (`plugin/wp2probe_*`, intentionally failing test fixtures) from the More-sheet link check.

