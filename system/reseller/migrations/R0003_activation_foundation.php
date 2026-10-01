<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0003 — activation foundation (structure only; no ActivationService, WP5A).
 *
 * tbl_reseller_activations: one row per authoritative business operation (NEW / RENEW /
 *   CHANGE_PLAN / REACTIVATE) with the frozen 15-state machine, unique idempotency key, commercial
 *   owner snapshot (manager_id at creation; never rewritten by later customer transfers), actor,
 *   customer and plan references, immutable wholesale / retail / provider-settlement snapshots,
 *   JSON plan and pricing-chain snapshots, legacy recharge/transaction/invoice references (no FK:
 *   legacy flows update/delete those rows) and sanitized error fields.
 *
 * tbl_reseller_activation_events: the append-only state-transition timeline. Every event records
 *   the state after the event (to_state); from_state is NULL only for the initial event into
 *   CREATED. Provisioning SUCCESS / FAILED / UNKNOWN is optional data on the relevant event.
 *   Transition validity is ActivationService logic (WP5A); the schema can record any transition.
 *
 * JSON portability: snapshots/metadata are LONGTEXT + named CHECK (... IS NULL OR JSON_VALID(...)).
 * MariaDB maps the JSON type to LONGTEXT while MySQL has a native JSON type; the portable form is
 * reported identically by both engines, so no false schema drift.
 */

$c = ' collate utf8mb4_general_ci';
$states = "'CREATED','VALIDATED','FUNDS_RESERVED','PROVISIONING','PROVISIONED','VERIFYING','VERIFIED','FINANCIAL_COMMITTING','COMPLETED','FAILED','RETRY_PENDING','RECONCILIATION_REQUIRED','ROLLBACK_PENDING','ROLLED_BACK','CANCELLED'";
$actors = "'STAFF','CUSTOMER','SYSTEM','CRON','API'";

$activationsSql = <<<SQL
CREATE TABLE `tbl_reseller_activations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(40) NOT NULL COMMENT 'stable public reference',
  `idempotency_key` VARCHAR(128) NOT NULL,
  `action` ENUM('NEW','RENEW','CHANGE_PLAN','REACTIVATE') NOT NULL,
  `status` ENUM({$states}) NOT NULL,
  `channel` ENUM('STAFF_WEB','API','CRON_AUTORENEW','CUSTOMER_BALANCE','PAYMENT_GATEWAY','VOUCHER','SYSTEM') NOT NULL COMMENT 'originating entrypoint (WP0 inventory / WP5B)',
  `external_reference` VARCHAR(512) NULL COMMENT 'e.g. payment gateway transaction id',
  `manager_id` INT UNSIGNED NOT NULL COMMENT 'commercial owner snapshot at creation (tbl_users.id)',
  `actor_type` ENUM({$actors}) NOT NULL,
  `actor_user_id` INT UNSIGNED NULL COMMENT 'tbl_users.id; NULL for customer/system/cron',
  `customer_id` INT NOT NULL COMMENT 'tbl_customers.id',
  `plan_id` INT NOT NULL COMMENT 'tbl_plans.id (target plan)',
  `previous_plan_id` INT NULL COMMENT 'tbl_plans.id (CHANGE_PLAN/REACTIVATE)',
  `wholesale_cost` DECIMAL(18,2) NULL COMMENT 'snapshot: leaf manager wholesale debit',
  `retail_price` DECIMAL(18,2) NULL COMMENT 'snapshot: customer retail charge where applicable',
  `provider_settlement_amount` DECIMAL(18,2) NULL COMMENT 'snapshot: provider/system share; not a wallet credit',
  `plan_snapshot` LONGTEXT NULL COMMENT 'JSON',
  `pricing_chain_snapshot` LONGTEXT NULL COMMENT 'JSON',
  `legacy_recharge_id` INT NULL COMMENT 'tbl_user_recharges.id (no FK)',
  `legacy_transaction_id` INT NULL COMMENT 'tbl_transactions.id (no FK)',
  `legacy_invoice` VARCHAR(25) NULL COMMENT 'tbl_transactions.invoice copy',
  `error_code` VARCHAR(64) NULL,
  `error_message` VARCHAR(1000) NULL COMMENT 'sanitized',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  `completed_at` DATETIME(6) NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_act_reference` (`reference`),
  UNIQUE KEY `uq_act_idempotency` (`idempotency_key`),
  KEY `ix_act_manager_created` (`manager_id`, `created_at`),
  KEY `ix_act_customer_created` (`customer_id`, `created_at`),
  KEY `ix_act_status_updated` (`status`, `updated_at`),
  KEY `ix_act_plan` (`plan_id`),
  KEY `ix_act_previous_plan` (`previous_plan_id`),
  KEY `ix_act_actor` (`actor_user_id`),
  KEY `ix_act_legacy_trx` (`legacy_transaction_id`),
  CONSTRAINT `fk_act_manager` FOREIGN KEY (`manager_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_act_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_act_customer` FOREIGN KEY (`customer_id`) REFERENCES `tbl_customers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_act_plan` FOREIGN KEY (`plan_id`) REFERENCES `tbl_plans` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_act_previous_plan` FOREIGN KEY (`previous_plan_id`) REFERENCES `tbl_plans` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_act_wholesale_nonneg` CHECK (`wholesale_cost` IS NULL OR `wholesale_cost` >= 0),
  CONSTRAINT `ck_act_retail_nonneg` CHECK (`retail_price` IS NULL OR `retail_price` >= 0),
  CONSTRAINT `ck_act_settlement_nonneg` CHECK (`provider_settlement_amount` IS NULL OR `provider_settlement_amount` >= 0),
  CONSTRAINT `ck_act_plan_snapshot_json` CHECK (`plan_snapshot` IS NULL OR JSON_VALID(`plan_snapshot`)),
  CONSTRAINT `ck_act_pricing_snapshot_json` CHECK (`pricing_chain_snapshot` IS NULL OR JSON_VALID(`pricing_chain_snapshot`)),
  CONSTRAINT `ck_act_change_plan_previous` CHECK (`action` <> 'CHANGE_PLAN' OR `previous_plan_id` IS NOT NULL),
  CONSTRAINT `ck_act_staff_actor` CHECK (`actor_type` <> 'STAFF' OR `actor_user_id` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Authoritative reseller activations (WP1 foundation)'
SQL;

$eventsSql = <<<SQL
CREATE TABLE `tbl_reseller_activation_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activation_id` BIGINT UNSIGNED NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `event_type` ENUM('STATE_TRANSITION','PROVISIONING_RESULT','VERIFICATION_RESULT','FINANCIAL','RECONCILIATION','ANNOTATION') NOT NULL,
  `from_state` ENUM({$states}) NULL COMMENT 'NULL only for the initial event into CREATED',
  `to_state` ENUM({$states}) NOT NULL COMMENT 'state after this event',
  `provisioning_result` ENUM('SUCCESS','FAILED','UNKNOWN') NULL COMMENT 'ProvisionResult status; UNKNOWN is never FAILED',
  `error_code` VARCHAR(64) NULL,
  `error_message` VARCHAR(1000) NULL COMMENT 'sanitized',
  `actor_type` ENUM({$actors}) NOT NULL,
  `actor_user_id` INT UNSIGNED NULL COMMENT 'tbl_users.id',
  `metadata` LONGTEXT NULL COMMENT 'JSON',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evt_activation_seq` (`activation_id`, `sequence_no`),
  KEY `ix_evt_actor` (`actor_user_id`),
  CONSTRAINT `fk_evt_activation` FOREIGN KEY (`activation_id`) REFERENCES `tbl_reseller_activations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_evt_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_evt_initial` CHECK (`from_state` IS NOT NULL OR `to_state` = 'CREATED'),
  CONSTRAINT `ck_evt_metadata_json` CHECK (`metadata` IS NULL OR JSON_VALID(`metadata`)),
  CONSTRAINT `ck_evt_staff_actor` CHECK (`actor_type` <> 'STAFF' OR `actor_user_id` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Append-only activation state-transition timeline (WP1 foundation)'
SQL;

return [
    'id' => 'R0003_activation_foundation',
    'description' => 'Reseller activations (frozen state machine, idempotency, snapshots) + append-only transition timeline',
    'requires' => 'R0002_pricing_foundation',
    'up' => [
        ['id' => 'create_tbl_reseller_activations', 'sql' => $activationsSql],
        ['id' => 'create_tbl_reseller_activation_events', 'sql' => $eventsSql],
    ],
    'down' => [
        ['id' => 'drop_tbl_reseller_activation_events', 'sql' => 'DROP TABLE `tbl_reseller_activation_events`', 'if_exists' => ['table' => 'tbl_reseller_activation_events']],
        ['id' => 'drop_tbl_reseller_activations', 'sql' => 'DROP TABLE `tbl_reseller_activations`', 'if_exists' => ['table' => 'tbl_reseller_activations']],
    ],
    'contract' => [
        'tables' => [
            'tbl_reseller_activations' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'reference' => "varchar(40){$c} not null",
                    'idempotency_key' => "varchar(128){$c} not null",
                    'action' => "enum('NEW','RENEW','CHANGE_PLAN','REACTIVATE'){$c} not null",
                    'status' => "enum({$states}){$c} not null",
                    'channel' => "enum('STAFF_WEB','API','CRON_AUTORENEW','CUSTOMER_BALANCE','PAYMENT_GATEWAY','VOUCHER','SYSTEM'){$c} not null",
                    'external_reference' => "varchar(512){$c} null",
                    'manager_id' => 'int unsigned not null',
                    'actor_type' => "enum({$actors}){$c} not null",
                    'actor_user_id' => 'int unsigned null',
                    'customer_id' => 'int not null',
                    'plan_id' => 'int not null',
                    'previous_plan_id' => 'int null',
                    'wholesale_cost' => 'decimal(18,2) null',
                    'retail_price' => 'decimal(18,2) null',
                    'provider_settlement_amount' => 'decimal(18,2) null',
                    'plan_snapshot' => "longtext{$c} null",
                    'pricing_chain_snapshot' => "longtext{$c} null",
                    'legacy_recharge_id' => 'int null',
                    'legacy_transaction_id' => 'int null',
                    'legacy_invoice' => "varchar(25){$c} null",
                    'error_code' => "varchar(64){$c} null",
                    'error_message' => "varchar(1000){$c} null",
                    'created_at' => 'datetime(6) not null',
                    'updated_at' => 'datetime(6) not null',
                    'completed_at' => 'datetime(6) null',
                ],
                'indexes' => [
                    'INDEX ix_act_actor (actor_user_id)',
                    'INDEX ix_act_customer_created (customer_id,created_at)',
                    'INDEX ix_act_legacy_trx (legacy_transaction_id)',
                    'INDEX ix_act_manager_created (manager_id,created_at)',
                    'INDEX ix_act_plan (plan_id)',
                    'INDEX ix_act_previous_plan (previous_plan_id)',
                    'INDEX ix_act_status_updated (status,updated_at)',
                    'PRIMARY KEY (id)',
                    'UNIQUE uq_act_idempotency (idempotency_key)',
                    'UNIQUE uq_act_reference (reference)',
                ],
                'foreign_keys' => [
                    'fk_act_actor (actor_user_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_act_customer (customer_id) REFERENCES tbl_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_act_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_act_plan (plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_act_previous_plan (previous_plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => [
                    'ck_act_change_plan_previous', 'ck_act_plan_snapshot_json', 'ck_act_pricing_snapshot_json',
                    'ck_act_retail_nonneg', 'ck_act_settlement_nonneg', 'ck_act_staff_actor', 'ck_act_wholesale_nonneg',
                ],
            ],
            'tbl_reseller_activation_events' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'activation_id' => 'bigint unsigned not null',
                    'sequence_no' => 'int unsigned not null',
                    'event_type' => "enum('STATE_TRANSITION','PROVISIONING_RESULT','VERIFICATION_RESULT','FINANCIAL','RECONCILIATION','ANNOTATION'){$c} not null",
                    'from_state' => "enum({$states}){$c} null",
                    'to_state' => "enum({$states}){$c} not null",
                    'provisioning_result' => "enum('SUCCESS','FAILED','UNKNOWN'){$c} null",
                    'error_code' => "varchar(64){$c} null",
                    'error_message' => "varchar(1000){$c} null",
                    'actor_type' => "enum({$actors}){$c} not null",
                    'actor_user_id' => 'int unsigned null',
                    'metadata' => "longtext{$c} null",
                    'created_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'INDEX ix_evt_actor (actor_user_id)',
                    'PRIMARY KEY (id)',
                    'UNIQUE uq_evt_activation_seq (activation_id,sequence_no)',
                ],
                'foreign_keys' => [
                    'fk_evt_activation (activation_id) REFERENCES tbl_reseller_activations (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_evt_actor (actor_user_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_evt_initial', 'ck_evt_metadata_json', 'ck_evt_staff_actor'],
            ],
        ],
        'table_parts' => [],
    ],
];
