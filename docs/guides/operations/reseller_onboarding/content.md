# About this guide

This guide describes how an ISP onboards a reseller (an **Agent**) and a sub-reseller (an Agent placed under another Agent) in ITC NetCore V1.0, and how funding, pricing and limits work. It describes only what the V1.0 code permits (`system/autoload/Reseller/Security/RoleCapabilityMap.php`, `docs/WP2_PERMISSION_MODEL.md`, `docs/WP3A_FINANCE_MODEL.md`, `docs/WP3B_PRICING_MODEL.md`, `docs/WP6B_FINANCE_UX_MODEL.md`).

> [!WARNING]
> Release Candidate, not yet production-accepted. Commercial terms between the ISP and its resellers (prices, commissions, contracts, credit policy) are the ISP's business decisions. They are not defined by the product or by this guide.

# 1. Concepts

| Term | Meaning in V1.0 |
|---|---|
| Agent | A staff account that acts as a reseller. It owns customers and a wallet |
| Sub-reseller | An Agent placed under another Agent. Same capabilities, limited to its own subtree, paying from its **own** wallet |
| Sales | A sales representative placed under an Agent. A leaf: it has no children and no wallet |
| Reseller tree | The hierarchy (closure table `tbl_manager_tree`). An Agent may have Agent or Sales children |
| Placed / enrolled | The account is in the tree. An unplaced Agent or Sales sees no data |
| Frozen | The account or any ancestor is not Active: every changing action is refused, read access stays |
| Wallet | Prepaid reseller credit. Only an enrolled, consistent Agent can own one |
| Available credit | `balance + debt limit − reserved` |

# 2. Who may do what

| Action | SuperAdmin / Admin | Agent | Sales |
|---|---|---|---|
| Create a top-level Agent, or an Agent under any Agent | Yes | — | — |
| Create an Agent (sub-reseller) or Sales as **its own direct child** | Yes | Yes (the parent field is ignored) | — |
| Edit profile, reset password, disable / reactivate | Any Agent / Sales | Strict descendants only | — |
| Delete an Agent | **Nobody in V1** (`AGENT_DELETE_PROHIBITED`) | — | — |
| Change an Agent's role | **Nobody** (`AGENT_ROLE_PERMANENT`) | — | — |
| Place or move accounts in the tree | Yes | — | — |
| Provider funding / withdrawal of a wallet | Yes | — | — |
| Set a debt limit | Yes | — | — |
| Accounting adjustment (credit / debit), transfer reversal | Yes | — | — |
| Send credit to a direct child | Yes | Yes, from its own wallet to a direct child only | — |
| Provider base cost and resale switch | Yes | — | — |
| Wholesale and availability of a plan for a reseller | Any reseller | **Direct children only**, never its own wholesale | — |
| Own retail price | Any reseller | Its own | — |
| View wallets, ledger, pricing | Global | Own and subtree | — |

> [!NOTE]
> An Admin can create and manage Report, Agent and Sales accounts and delete only Report accounts. An account disabled by an Admin cannot be re-enabled by an Agent.

# 3. Before the first reseller

Complete these once (SuperAdmin / Admin):

1. Plans exist on the **Radius** device, type Hotspot or PPPOE (other plans cannot be sold through resellers; Balance plans are never resellable).
2. **Pricing > Plans and prices:** for each plan to be resold, set the provider **base cost** (the wholesale floor) and switch **resale on**. A new base row starts with resale disabled. Base cost 0 is valid configuration.
3. Decide (ISP business decision) the reseller chain, wholesale levels and credit policy.

# 4. Onboarding a top-level reseller

| Step | Where | Action | Check |
|---|---|---|---|
| 1 | Settings > Administrator Users > Add | Role **Agent**, top level. Name, username, contact data, a temporary password given to the reseller out of band | The account appears in Resellers > Reseller Tree |
| 2 | Pricing > Plans and prices | For this Agent: wholesale price (≥ base cost; leave empty to inherit the base cost) and availability per plan | Plan shows as saleable for the Agent |
| 3 | Pricing (as the Agent, or by Admin) | The Agent's own retail price (≥ its wholesale; empty = the plan's default price) | No `RETAIL_BELOW_WHOLESALE` warning |
| 4 | Finance > Wallets > (wallet) > Fund | Provider funding: amount, reason (3–255 characters), external reference (for example the payment receipt number). **Review**, then **Confirm** | A `FUNDING` ledger entry; wallet balance updated |
| 5 | Finance > Wallets > (wallet) > Debt limit (optional) | Only if the ISP extends credit to this reseller. Review, typed confirmation, confirm | Available credit = balance + limit − reserved |
| 6 | The reseller logs in | Changes the temporary password from the personal menu (only the installer's default `admin` password is forced to change by the application). On a phone the bottom bar is Home / Customers / + / Wallet / More | Dashboard shows the wallet |

> [!FINANCE-WARNING]
> No wallet exists until the first wallet-creating operation: provider funding, a positive debt limit, or receipt of a transfer from the parent. Adjustments never create a wallet. Funding and every correction are review, then confirm; the server re-checks the rules at confirm time, and a double submit replays the same operation ("Already recorded").

# 5. Onboarding a sub-reseller

A sub-reseller can be created by the parent Agent itself or by an Admin.

| Step | Who | Action |
|---|---|---|
| 1 | Parent Agent (or Admin) | Settings > Administrator Users > Add, role **Agent**. Created by the Agent, the new account always becomes the Agent's **direct** child and is enrolled at once |
| 2 | Parent Agent (or Admin) | Pricing: set the child's **wholesale** price (must be ≥ the parent's own effective cost) and **availability** per plan. The difference between the child's wholesale and the parent's is the parent's commission |
| 3 | Sub-reseller | Sets its own retail price |
| 4 | Parent Agent | Finance > Wallet overview > *Send to child reseller*: choose the direct child, amount and reason, review, confirm. Two opposite ledger entries are written (`TRANSFER_OUT`, `TRANSFER_IN`) |
| 5 | Admin (optional) | Provider funding or a debt limit directly on the sub-reseller's wallet |

> [!FINANCE-WARNING]
> A reseller that earns a positive margin on its children's sales must itself have a wallet, or the child's activation is refused (`COMMISSION_RECIPIENT_NO_WALLET`). A parent that funds its children through transfers already has one.

## 5.1 Adding Sales representatives

The Agent (or Admin) creates a **Sales** account as the Agent's direct child. A Sales user:
- sees only customers explicitly assigned to it;
- creates customers (owned by its Agent, assigned to itself) and activates them on **its Agent's wallet**;
- never sees wallets, the ledger or prices.

# 6. Pricing rules

| Rule | Behaviour |
|---|---|
| Effective wholesale | The reseller's own override, else the nearest ancestor's override, else the base cost |
| Floor | A child's effective wholesale is ≥ its parent's; a top-level reseller's is ≥ the base cost. Equal is allowed (`WHOLESALE_BELOW_PARENT` otherwise) |
| Upstream raise | A raise that would put an explicit descendant override below its new parent price is rejected. Descendant prices are never rewritten |
| Retail below wholesale | Refused when set. If an upstream raise causes it, the plan becomes not saleable for that reseller until corrected (warning, audited) |
| Availability | An explicit "off" anywhere on the path closes the plan for that subtree; "on" never overrides an ancestor's "off"; empty inherits |
| Zero wholesale | A leaf wholesale of 0 is valid configuration but **not activatable** in V1 (`ZERO_WHOLESALE_NOT_SUPPORTED`) |
| Settlement | The selling reseller's wallet is debited its wholesale; each intermediary with a positive margin receives a commission credit; the provider settlement is the top-level reseller's wholesale. The chain is sealed at confirmation |
| Proration | None. A plan change starts a new period |

Example (from `docs/WP3B_PRICING_MODEL.md` §7): base 10, top reseller 12, sub-reseller 15. The sub-reseller's wallet is debited 15, the top reseller earns 3, the provider settlement is 12.

# 7. Limits that apply to resellers in V1.0

- A reseller activates, renews and changes plans only for customers **it owns**, always from **its own** wallet. An ancestor never spends a descendant's wallet.
- After creation, customer records (password, PPPoE identity, status) are edited by Admin only. Agents and Sales cannot edit, deactivate, delete or sync customers.
- Resellers cannot generate vouchers (Admin only); they can view vouchers generated inside their subtree.
- There is no auto-renew for reseller-owned customers and no reseller self-purchase or gateway payment for them.
- A reseller-owned service ends only by expiry or by an Admin reversal of the completed activation.
- With insufficient available credit the activation's Confirm stays disabled and a forced submission is refused by the server.
- Disabling a reseller freezes its whole subtree (read-only).

# 8. Verification after onboarding

| Check | How |
|---|---|
| Tree is consistent | `php system/reseller/hierarchy.php inspect` (read-only); expect `tree_drift: []` |
| Pricing chain is valid | Pricing > Plans and prices shows the plan as saleable for the new reseller |
| Wallet is correct | Finance > Wallets: balance, debt limit, reserved and available as expected; ledger shows the funding entry |
| Test sale | One activation on a test customer owned by the new reseller; one debit equal to its wholesale (see `subscriber_onboarding`) |

# 9. Off-boarding a reseller

1. Check that the reseller has no activation in flight and no held reservation (Operations Center; `reconcile.php stale`).
2. **While the account is still Active**, settle any remaining balance with an audited operation (Admin: withdrawal or adjustment) according to the ISP's commercial terms. Once the account is disabled, funding, withdrawal, adjustments, debt-limit changes and new transfers on its wallet are refused for everyone, Admin included (`WALLET_OWNER_FROZEN`); only an Admin reversal of an existing transfer remains possible. Never edit the wallet by SQL.
3. Transfer its customers to another owner if required (Admin: customer view > transfer owner).
4. Disable the account (Reseller Tree or the staff edit form). Its subtree is frozen; reads stay possible; settlement of activations already in flight still completes.
5. The account and its ledger history are kept (Agents are never deleted).
