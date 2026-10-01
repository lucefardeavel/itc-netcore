# WP1 Schema Contract — reseller-domain database foundation

Work Package: **WP1 — Database Foundation** · Status: `IMPLEMENTED_NOT_ACCEPTED` (waiting for owner review)
Runner and operations: [`system/reseller/README.md`](../system/reseller/README.md) · Evidence: [`../owner-review/WP1/REPORT.md`](../owner-review/WP1/REPORT.md)

**Scope: structure only.** WP1 adds no service, no business behaviour, no UI, no RADIUS or MikroTik change, and no data (no backfill, no seed, no closure import). Every new table is empty after migration.

## 1. Owner decisions implemented
| Decision | Implementation |
|---|---|
| C13 strict project migrations | `system/reseller/migrate.php` + `tbl_project_migrations` / `tbl_project_migration_journal`; `system/updates.json` is not used for the reseller domain |
| C28 partial-DDL journaling | per-step STARTED/COMPLETED journal; states APPLYING / APPLIED / FAILED_PARTIAL / ROLLBACK_REQUIRED / ROLLING_BACK / ROLLBACK_FAILED / ROLLED_BACK; partial states block every later migration |
| Q1 database versions | preflight requires MariaDB ≥ 10.11 or MySQL ≥ 8.0.16; tested on MariaDB 10.11.19 and MySQL 8.0.46 |
| Q3 / C27 preflight model | authoritative core-schema contract over `tbl_users`, `tbl_customers`, `tbl_plans` (the tables WP1 depends on), matched against registered verified variants; extra unrelated tables allowed and reported; no runtime override |
| C19 user references | every new reference to `tbl_users.id` is `INT UNSIGNED`; customer and plan references are signed `INT` (legacy PKs); legacy reference columns unchanged |
| Customer migration | `manager_id` and `assigned_sales_id` nullable, **no backfill**; `created_by` is not used as input |
| C20 existing root tree | `tbl_manager_tree` created empty; no import from `tbl_users.root` |
| Money precision | all new money columns are `DECIMAL(18,2)`; legacy `tbl_customers.balance` unchanged |
| C12 legacy price storage | `tbl_plans.price` / `price_old`, `tbl_transactions.price`, `tbl_payment_gateway.price` unchanged (definition and data verified by tests) |
| Wholesale base cost | explicit new table `tbl_reseller_plan_base_costs` (D009; `tbl_plans.price` not repurposed) |
| Q4 base-cost history | no history table; current rows carry `created_by/at` + `updated_by/at`; activation snapshots preserve historical pricing (WP5A) |
| Manager plan availability (owner WP1 review, fix 1) | `tbl_manager_plan_prices.is_enabled` is an **optional** override: `NULL` = inherit (no override), `0` = explicitly disabled, `1` = explicitly enabled (still subject to ancestor/global restrictions, WP3B). An explicit `0`/`1` requires `availability_set_by` (FK `tbl_users.id`, RESTRICT) and `availability_set_at`; `NULL` requires both to be `NULL` (`ck_mpp_enabled_bool`, `ck_mpp_availability_audit`). No row = no manager-specific configuration; a pricing-only row inherits availability. No pricing logic in WP1 |
| Price override provenance (owner WP1 final review, fix 2) | Wholesale, retail and availability overrides follow one rule: the `*_set_by` / `*_set_at` fields describe **only the current explicit override**. Value `NULL` ⇔ setter and time `NULL`; explicit value ⇔ setter and time set (`ck_mpp_wholesale_audit`, `ck_mpp_retail_audit`, `ck_mpp_availability_audit`). Clearing an override clears its provenance. These are not history: historical and per-operation pricing is preserved by activation snapshots, and durable configuration-change audit belongs to the later service/audit layer. No price-history tables in WP1 |
| Actor customer identity (owner WP1 reviews: activations/events in fix 2, ledger in final fix 1) | `actor_customer_id INT NULL` (signed, FK `tbl_customers.id`, RESTRICT) stored directly on `tbl_reseller_activations`, `tbl_reseller_activation_events` and `tbl_manager_ledger`, so durable financial history does not depend on an activation join. Ledger: `STAFF` requires `operator_id`, `CUSTOMER` requires `actor_customer_id` (`ck_ledger_customer_actor`), `SYSTEM` / `CRON` / `API` need neither; whether a `CUSTOMER` entry must reference an activation is a WP3A rule. Activations and events: `STAFF` requires `actor_user_id`; `CUSTOMER` requires `actor_customer_id`; `SYSTEM` / `CRON` may carry neither; `API` gets no identity rule before the WP2 authorization model. The **initiating** customer is independent of the **target** `customer_id` (customer A gifting / buying for customer B, WP0 entrypoint inventory) |
| Q5 immutability | no triggers in WP1; enforcement belongs to WP3A (financial history) and WP5A (activation history) |
| C8 / D027 vouchers | no voucher-commerce schema; `tbl_voucher` untouched |
| Timestamps | new-domain timestamps are `DATETIME(6)` holding **UTC**, always written by the service (no `DEFAULT CURRENT_TIMESTAMP`, because legacy sessions use the server time zone) |

## 2. Supported base schemas (core contract, `system/reseller/Migration/variants.php`)
| Variant | Built from | Core fingerprint (MariaDB = MySQL) |
|---|---|---|
| `V1_FRESH_INSTALL` | current `install/phpnuxbill.sql` | `3ca25964039129ec2aae54d510c7c8b86a45de2a0001a3174f403aeca92e06b3` |
| `V2_FRESH_INSTALL_UPDATED` | V1 + all `system/updates.json` (legacy updater semantics: 35 ok, 56 failed-and-ignored) | `6c2f04ea02b5fc28991fbc2680ab90e53584ef785bb2964d368b28baffb9b1a9` |
| `V3_LONGLIVED_2023_UPDATED` | `install/phpnuxbill.sql` @ `56cea36a` (2023-08-09) + all updates (87 ok, 4 failed-and-ignored) | `e1a66aa3caf5890f0abbb6e52ddf5280ec289599f2409d699749de5b7cbfaafc` |

**Why WP1 can run on each variant.** The differences between the variants do not touch anything WP1 depends on:
- V2 adds `allow_purchase` and the `VPN` enum values;
- V3 differs in defaults and display widths, has the orphan `tbl_message`, and has an FK `tbl_customers_fields → tbl_customers`.

In all three, the anchor keys are identical: `tbl_users.id` INT UNSIGNED, `tbl_customers.id` and `tbl_plans.id` signed INT, all InnoDB, utf8mb4.

**Unrelated drift is not normalised.**

**Canonical form.** The core contract is compared in a canonical form. Integer display width is ignored; signedness, defaults (normalised between the MariaDB and MySQL `information_schema` formats), EXTRA, indexes, FKs, engine and collation are compared exactly.

## 3. Foreign-key policy
- Every foreign key is `ON DELETE RESTRICT ON UPDATE RESTRICT`. There are **no cascades and no SET NULL** anywhere. Financial and history rows can never disappear or be re-pointed because a user, customer or plan is deleted.
- **No FK to volatile legacy rows.** `tbl_user_recharges`, `tbl_transactions` and `tbl_payment_gateway` ids are stored as plain snapshot references, because legacy retention and delete paths remove those rows.
- **Later behaviour changes:** once rows reference a user, customer or plan, the legacy delete paths are refused by the database. WP0/WP1 evidence on how they then behave:
  - **user delete:** uncaught error page → **WP2**;
  - **customer delete:** unprovisions the customer first, then swallows the error → **WP2/WP5B**;
  - **plan delete:** removes the plan from the router/RADIUS first → **WP3B**.

  In WP1 no referencing rows exist, so behaviour is unchanged.

## 4. JSON portability (owner amendment)
MariaDB implements `JSON` as an alias of `LONGTEXT` with an automatic CHECK, while MySQL has a native `JSON` type. To avoid false schema drift, JSON snapshots and metadata use the **portable representation**: `LONGTEXT` plus an explicit named `CHECK (col IS NULL OR JSON_VALID(col))`.
- **Columns:** `plan_snapshot`, `pricing_chain_snapshot`, `metadata`.
- **Reporting:** both engines report this identically. The contract compares the column type and the CHECK *name*; CHECK expression text differs by engine and is not compared.
- **Tests:** invalid JSON is rejected on both engines.

## 5. Writer requirements for later WPs (not enforced by WP1 code)
- **Strict session mode.** New-domain writers must use a strict session `sql_mode`, as the runner does. In a non-strict session MySQL/MariaDB store an invalid ENUM value as `''` instead of rejecting it. CHECK and FK constraints are enforced regardless.
- **UTC timestamps.** Writers supply UTC `DATETIME(6)` values.
- **Immutability.** Ledger rows and activation events are append-only by design. WP3A and WP5A add service (and, if decided, database) enforcement.
- **Legacy restore (C24, assigned to WP2).** The SuperAdmin restore in `settings.php` disables FK checks (session and GLOBAL) and TRUNCATEs every table in the uploaded backup, including new-domain tables. WP1 documents this and changes nothing. WP2 must close it before authoritative ownership, Sales-assignment or hierarchy data exists.
- **API output (C25).** Legacy API responses that serialise full customer rows now include `manager_id: null` and `assigned_sales_id: null`. This is accepted; WP2 owns API authorization and scoping.

## 6. Foundation invariants enabled for later WPs
| Invariant (enforcing WP) | Schema support |
|---|---|
| available = balance + debt_limit − ACTIVE reservations (WP3A) | wallet row is the lock target; `ix_res_manager_status`; `ck_wallet_floor` (balance + debt_limit ≥ 0) |
| one wallet per manager | PK `tbl_manager_wallets.manager_id` |
| one ACTIVE reservation per operation | stored generated `active_activation_id` + `uq_res_one_active` |
| immutable ledger, compensating reversals | no mutable columns; `uq_ledger_entry_key`; `reverses_entry_id` unique self-FK; `ck_ledger_math` |
| parent → direct-child transfer (WP3A rule) | `PARENT_CHILD_TRANSFER` shape check (both sides, different) |
| provider funding without fake root wallet | `ck_trf_shape`: provider funding has no source wallet; mandatory `reason`, `operator_id` |
| provider settlement auditable, not a wallet credit | `tbl_reseller_activations.provider_settlement_amount`; no ledger row |
| activation idempotency / state machine (WP5A) | `uq_act_idempotency`, `uq_act_reference`; 4 actions; 15 frozen states; `channel` for WP5B entrypoint binding |
| plan availability inheritance / ancestor disable (WP3B) | `is_enabled` NULL / 0 / 1 with mandatory operator + time for explicit overrides |
| actor attribution per operation, event and ledger entry (WP2 / WP3A / WP5A / WP5B) | `ck_act_staff_actor`, `ck_act_customer_actor`, `ck_evt_staff_actor`, `ck_evt_customer_actor`, `ck_ledger_staff_operator`, `ck_ledger_customer_actor`; initiator (`actor_customer_id`) separate from target (`customer_id`) |
| state-transition timeline (WP5A) | `tbl_reseller_activation_events`: `(activation_id, sequence_no)` unique; `from_state` NULL only into CREATED; all 15×15 transitions representable (tested) |

## 7. Column-level contract (generated from the migration definitions)

### R0001_manager_tree

Reseller/Sales hierarchy closure table (empty; no import from tbl_users.root). Checksum (sha256 of file): `a0b52f7fb4bd5f5e317c701d85df6db7d2a95ace04ec1b1112873191e514a5a7`. Steps: 1 up / 1 down.

#### `tbl_manager_tree`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `ancestor_id` | `int unsigned not null` |
| `descendant_id` | `int unsigned not null` |
| `depth` | `smallint unsigned not null` |
| `created_at` | `datetime(6) not null` |

**Keys:** `INDEX ix_tree_ancestor_depth (ancestor_id,depth)`; `INDEX ix_tree_descendant_depth (descendant_id,depth)`; `PRIMARY KEY (ancestor_id,descendant_id)`

**Foreign keys:**
- `fk_tree_ancestor (ancestor_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_tree_descendant (descendant_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_tree_depth_self`

### R0002_pricing_foundation

Provider wholesale base cost per plan + per-manager wholesale/retail/availability overrides. Checksum (sha256 of file): `38bceb1fb4f4be1b26bdb53c5317840109ce6f7e1ef7b98dc8af61b9b8c3b771`. Steps: 2 up / 2 down.

#### `tbl_reseller_plan_base_costs`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `plan_id` | `int not null` |
| `base_cost` | `decimal(18,2) not null` |
| `is_resale_enabled` | `tinyint not null default '0'` |
| `created_by` | `int unsigned not null` |
| `created_at` | `datetime(6) not null` |
| `updated_by` | `int unsigned not null` |
| `updated_at` | `datetime(6) not null` |

**Keys:** `INDEX ix_basecost_created_by (created_by)`; `INDEX ix_basecost_updated_by (updated_by)`; `PRIMARY KEY (plan_id)`

**Foreign keys:**
- `fk_basecost_created_by (created_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_basecost_plan (plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_basecost_updated_by (updated_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_basecost_nonneg`, `ck_basecost_resale_bool`

#### `tbl_manager_plan_prices`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `id` | `bigint unsigned not null auto_increment` |
| `manager_id` | `int unsigned not null` |
| `plan_id` | `int not null` |
| `wholesale_cost` | `decimal(18,2) null` |
| `wholesale_set_by` | `int unsigned null` |
| `wholesale_set_at` | `datetime(6) null` |
| `retail_price` | `decimal(18,2) null` |
| `retail_set_by` | `int unsigned null` |
| `retail_set_at` | `datetime(6) null` |
| `is_enabled` | `tinyint null` |
| `availability_set_by` | `int unsigned null` |
| `availability_set_at` | `datetime(6) null` |
| `created_at` | `datetime(6) not null` |
| `updated_at` | `datetime(6) not null` |

**Keys:** `INDEX ix_mpp_availability_set_by (availability_set_by)`; `INDEX ix_mpp_plan (plan_id)`; `INDEX ix_mpp_retail_set_by (retail_set_by)`; `INDEX ix_mpp_wholesale_set_by (wholesale_set_by)`; `PRIMARY KEY (id)`; `UNIQUE uq_mpp_manager_plan (manager_id,plan_id)`

**Foreign keys:**
- `fk_mpp_availability_set_by (availability_set_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_mpp_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_mpp_plan (plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_mpp_retail_set_by (retail_set_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_mpp_wholesale_set_by (wholesale_set_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_mpp_availability_audit`, `ck_mpp_enabled_bool`, `ck_mpp_retail_audit`, `ck_mpp_retail_nonneg`, `ck_mpp_wholesale_audit`, `ck_mpp_wholesale_nonneg`

### R0003_activation_foundation

Reseller activations (frozen state machine, idempotency, snapshots) + append-only transition timeline. Checksum (sha256 of file): `60e63156bdc90e9772dd479f69c9507fdc648f98dbbe301ab060ff59fe3f9acd`. Steps: 2 up / 2 down.

#### `tbl_reseller_activations`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `id` | `bigint unsigned not null auto_increment` |
| `reference` | `varchar(40) collate utf8mb4_general_ci not null` |
| `idempotency_key` | `varchar(128) collate utf8mb4_general_ci not null` |
| `action` | `enum('NEW','RENEW','CHANGE_PLAN','REACTIVATE') collate utf8mb4_general_ci not null` |
| `status` | `enum('CREATED','VALIDATED','FUNDS_RESERVED','PROVISIONING','PROVISIONED','VERIFYING','VERIFIED','FINANCIAL_COMMITTING','COMPLETED','FAILED','RETRY_PENDING','RECONCILIATION_REQUIRED','ROLLBACK_PENDING','ROLLED_BACK','CANCELLED') collate utf8mb4_general_ci not null` |
| `channel` | `enum('STAFF_WEB','API','CRON_AUTORENEW','CUSTOMER_BALANCE','PAYMENT_GATEWAY','VOUCHER','SYSTEM') collate utf8mb4_general_ci not null` |
| `external_reference` | `varchar(512) collate utf8mb4_general_ci null` |
| `manager_id` | `int unsigned not null` |
| `actor_type` | `enum('STAFF','CUSTOMER','SYSTEM','CRON','API') collate utf8mb4_general_ci not null` |
| `actor_user_id` | `int unsigned null` |
| `actor_customer_id` | `int null` |
| `customer_id` | `int not null` |
| `plan_id` | `int not null` |
| `previous_plan_id` | `int null` |
| `wholesale_cost` | `decimal(18,2) null` |
| `retail_price` | `decimal(18,2) null` |
| `provider_settlement_amount` | `decimal(18,2) null` |
| `plan_snapshot` | `longtext collate utf8mb4_general_ci null` |
| `pricing_chain_snapshot` | `longtext collate utf8mb4_general_ci null` |
| `legacy_recharge_id` | `int null` |
| `legacy_transaction_id` | `int null` |
| `legacy_invoice` | `varchar(25) collate utf8mb4_general_ci null` |
| `error_code` | `varchar(64) collate utf8mb4_general_ci null` |
| `error_message` | `varchar(1000) collate utf8mb4_general_ci null` |
| `created_at` | `datetime(6) not null` |
| `updated_at` | `datetime(6) not null` |
| `completed_at` | `datetime(6) null` |

**Keys:** `INDEX ix_act_actor (actor_user_id)`; `INDEX ix_act_actor_customer (actor_customer_id)`; `INDEX ix_act_customer_created (customer_id,created_at)`; `INDEX ix_act_legacy_trx (legacy_transaction_id)`; `INDEX ix_act_manager_created (manager_id,created_at)`; `INDEX ix_act_plan (plan_id)`; `INDEX ix_act_previous_plan (previous_plan_id)`; `INDEX ix_act_status_updated (status,updated_at)`; `PRIMARY KEY (id)`; `UNIQUE uq_act_idempotency (idempotency_key)`; `UNIQUE uq_act_reference (reference)`

**Foreign keys:**
- `fk_act_actor (actor_user_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_act_actor_customer (actor_customer_id) REFERENCES tbl_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_act_customer (customer_id) REFERENCES tbl_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_act_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_act_plan (plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_act_previous_plan (previous_plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_act_change_plan_previous`, `ck_act_customer_actor`, `ck_act_plan_snapshot_json`, `ck_act_pricing_snapshot_json`, `ck_act_retail_nonneg`, `ck_act_settlement_nonneg`, `ck_act_staff_actor`, `ck_act_wholesale_nonneg`

#### `tbl_reseller_activation_events`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `id` | `bigint unsigned not null auto_increment` |
| `activation_id` | `bigint unsigned not null` |
| `sequence_no` | `int unsigned not null` |
| `event_type` | `enum('STATE_TRANSITION','PROVISIONING_RESULT','VERIFICATION_RESULT','FINANCIAL','RECONCILIATION','ANNOTATION') collate utf8mb4_general_ci not null` |
| `from_state` | `enum('CREATED','VALIDATED','FUNDS_RESERVED','PROVISIONING','PROVISIONED','VERIFYING','VERIFIED','FINANCIAL_COMMITTING','COMPLETED','FAILED','RETRY_PENDING','RECONCILIATION_REQUIRED','ROLLBACK_PENDING','ROLLED_BACK','CANCELLED') collate utf8mb4_general_ci null` |
| `to_state` | `enum('CREATED','VALIDATED','FUNDS_RESERVED','PROVISIONING','PROVISIONED','VERIFYING','VERIFIED','FINANCIAL_COMMITTING','COMPLETED','FAILED','RETRY_PENDING','RECONCILIATION_REQUIRED','ROLLBACK_PENDING','ROLLED_BACK','CANCELLED') collate utf8mb4_general_ci not null` |
| `provisioning_result` | `enum('SUCCESS','FAILED','UNKNOWN') collate utf8mb4_general_ci null` |
| `error_code` | `varchar(64) collate utf8mb4_general_ci null` |
| `error_message` | `varchar(1000) collate utf8mb4_general_ci null` |
| `actor_type` | `enum('STAFF','CUSTOMER','SYSTEM','CRON','API') collate utf8mb4_general_ci not null` |
| `actor_user_id` | `int unsigned null` |
| `actor_customer_id` | `int null` |
| `metadata` | `longtext collate utf8mb4_general_ci null` |
| `created_at` | `datetime(6) not null` |

**Keys:** `INDEX ix_evt_actor (actor_user_id)`; `INDEX ix_evt_actor_customer (actor_customer_id)`; `PRIMARY KEY (id)`; `UNIQUE uq_evt_activation_seq (activation_id,sequence_no)`

**Foreign keys:**
- `fk_evt_activation (activation_id) REFERENCES tbl_reseller_activations (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_evt_actor (actor_user_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_evt_actor_customer (actor_customer_id) REFERENCES tbl_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_evt_customer_actor`, `ck_evt_initial`, `ck_evt_metadata_json`, `ck_evt_staff_actor`

### R0004_wallet_finance_foundation

Manager wallets, funding/transfers, append-only ledger, reservations. Checksum (sha256 of file): `7f3bc5bc8479bfe0f186ec4ae00af4623cc0c8d7201f13897f7184d4b92dffa7`. Steps: 4 up / 4 down.

#### `tbl_manager_wallets`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `manager_id` | `int unsigned not null` |
| `balance` | `decimal(18,2) not null default '0.00'` |
| `debt_limit` | `decimal(18,2) not null default '0.00'` |
| `created_at` | `datetime(6) not null` |
| `updated_at` | `datetime(6) not null` |

**Keys:** `PRIMARY KEY (manager_id)`

**Foreign keys:**
- `fk_wallet_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_wallet_debt_nonneg`, `ck_wallet_floor`

#### `tbl_manager_transfers`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `id` | `bigint unsigned not null auto_increment` |
| `reference` | `varchar(64) collate utf8mb4_general_ci not null` |
| `operation_type` | `enum('PROVIDER_FUNDING','PROVIDER_WITHDRAWAL','ADJUSTMENT_CREDIT','ADJUSTMENT_DEBIT','PARENT_CHILD_TRANSFER') collate utf8mb4_general_ci not null` |
| `from_manager_id` | `int unsigned null` |
| `to_manager_id` | `int unsigned null` |
| `amount` | `decimal(18,2) not null` |
| `status` | `enum('PENDING','COMPLETED','FAILED','CANCELLED','REVERSED') collate utf8mb4_general_ci not null` |
| `reason` | `varchar(255) collate utf8mb4_general_ci not null` |
| `external_reference` | `varchar(100) collate utf8mb4_general_ci null` |
| `operator_id` | `int unsigned not null` |
| `reverses_transfer_id` | `bigint unsigned null` |
| `failure_reason` | `varchar(255) collate utf8mb4_general_ci null` |
| `created_at` | `datetime(6) not null` |
| `completed_at` | `datetime(6) null` |

**Keys:** `INDEX ix_trf_from_created (from_manager_id,created_at)`; `INDEX ix_trf_operator (operator_id)`; `INDEX ix_trf_status_created (status,created_at)`; `INDEX ix_trf_to_created (to_manager_id,created_at)`; `PRIMARY KEY (id)`; `UNIQUE uq_trf_reference (reference)`; `UNIQUE uq_trf_reverses (reverses_transfer_id)`

**Foreign keys:**
- `fk_trf_from (from_manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_trf_operator (operator_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_trf_reverses (reverses_transfer_id) REFERENCES tbl_manager_transfers (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_trf_to (to_manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_trf_amount_pos`, `ck_trf_shape`

#### `tbl_manager_ledger`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `id` | `bigint unsigned not null auto_increment` |
| `entry_key` | `varchar(128) collate utf8mb4_general_ci not null` |
| `manager_id` | `int unsigned not null` |
| `entry_type` | `enum('FUNDING','WITHDRAWAL','ACTIVATION_DEBIT','COMMISSION_CREDIT','TRANSFER_OUT','TRANSFER_IN','REFUND','REVERSAL','ADJUSTMENT') collate utf8mb4_general_ci not null` |
| `direction` | `enum('CREDIT','DEBIT') collate utf8mb4_general_ci not null` |
| `amount` | `decimal(18,2) not null` |
| `balance_before` | `decimal(18,2) not null` |
| `balance_after` | `decimal(18,2) not null` |
| `activation_id` | `bigint unsigned null` |
| `transfer_id` | `bigint unsigned null` |
| `counterparty_manager_id` | `int unsigned null` |
| `reverses_entry_id` | `bigint unsigned null` |
| `operator_id` | `int unsigned null` |
| `actor_type` | `enum('STAFF','CUSTOMER','SYSTEM','CRON','API') collate utf8mb4_general_ci not null` |
| `actor_customer_id` | `int null` |
| `reason` | `varchar(255) collate utf8mb4_general_ci null` |
| `created_at` | `datetime(6) not null` |

**Keys:** `INDEX ix_ledger_activation (activation_id)`; `INDEX ix_ledger_actor_customer (actor_customer_id)`; `INDEX ix_ledger_counterparty (counterparty_manager_id)`; `INDEX ix_ledger_manager_created (manager_id,created_at)`; `INDEX ix_ledger_operator (operator_id)`; `INDEX ix_ledger_transfer (transfer_id)`; `PRIMARY KEY (id)`; `UNIQUE uq_ledger_entry_key (entry_key)`; `UNIQUE uq_ledger_reverses (reverses_entry_id)`

**Foreign keys:**
- `fk_ledger_activation (activation_id) REFERENCES tbl_reseller_activations (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_ledger_actor_customer (actor_customer_id) REFERENCES tbl_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_ledger_counterparty (counterparty_manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_ledger_operator (operator_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_ledger_reverses (reverses_entry_id) REFERENCES tbl_manager_ledger (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_ledger_transfer (transfer_id) REFERENCES tbl_manager_transfers (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_ledger_wallet (manager_id) REFERENCES tbl_manager_wallets (manager_id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_ledger_amount_pos`, `ck_ledger_customer_actor`, `ck_ledger_math`, `ck_ledger_staff_operator`

#### `tbl_manager_wallet_reservations`

Engine `innodb`, collation `utf8mb4_general_ci`.

| Column | Canonical definition |
|---|---|
| `id` | `bigint unsigned not null auto_increment` |
| `manager_id` | `int unsigned not null` |
| `activation_id` | `bigint unsigned not null` |
| `amount` | `decimal(18,2) not null` |
| `status` | `enum('ACTIVE','COMMITTED','RELEASED','EXPIRED') collate utf8mb4_general_ci not null` |
| `active_activation_id` | `bigint unsigned null stored generated` |
| `committed_ledger_entry_id` | `bigint unsigned null` |
| `expires_at` | `datetime(6) null` |
| `resolution_note` | `varchar(255) collate utf8mb4_general_ci null` |
| `resolved_by` | `int unsigned null` |
| `created_at` | `datetime(6) not null` |
| `resolved_at` | `datetime(6) null` |

**Keys:** `INDEX ix_res_activation (activation_id)`; `INDEX ix_res_manager_status (manager_id,status)`; `INDEX ix_res_resolved_by (resolved_by)`; `PRIMARY KEY (id)`; `UNIQUE uq_res_committed_entry (committed_ledger_entry_id)`; `UNIQUE uq_res_one_active (active_activation_id)`

**Foreign keys:**
- `fk_res_activation (activation_id) REFERENCES tbl_reseller_activations (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_res_ledger_entry (committed_ledger_entry_id) REFERENCES tbl_manager_ledger (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_res_resolved_by (resolved_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_res_wallet (manager_id) REFERENCES tbl_manager_wallets (manager_id) ON DELETE RESTRICT ON UPDATE RESTRICT`

**CHECK constraints:** `ck_res_amount_pos`, `ck_res_commit_link`, `ck_res_resolution`

### R0005_customer_ownership

tbl_customers.manager_id + assigned_sales_id (nullable, no backfill). Checksum (sha256 of file): `b69135eab8b70f3bed2355946d959be356c3d48897df9db6cb41c6c7e45b3854`. Steps: 1 up / 4 down.

#### `tbl_customers (columns added to legacy table)`

| Column | Canonical definition |
|---|---|
| `manager_id` | `int unsigned null` |
| `assigned_sales_id` | `int unsigned null` |

**Keys:** `INDEX ix_customers_assigned_sales (assigned_sales_id)`; `INDEX ix_customers_manager (manager_id)`

**Foreign keys:**
- `fk_customers_assigned_sales (assigned_sales_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `fk_customers_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`


## 8. Runner registry tables

`tbl_project_migrations` (current state, PK `migration_id`) and `tbl_project_migration_journal` (append-only, PK `id`, indexes `(migration_id,id)` and `(run_id,id)`). Their contract is defined in `system/reseller/Migration/Registry.php` and verified on every run; the runner never deletes them or their history.
