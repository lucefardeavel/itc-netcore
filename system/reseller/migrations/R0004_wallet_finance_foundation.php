<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0004 — wallet / finance foundation (structure only; no WalletService/LedgerService, WP3A).
 *
 *  tbl_manager_wallets              one wallet per manager (PK manager_id); available credit =
 *                                   balance + debt_limit - SUM(ACTIVE reservations), computed by
 *                                   WP3A under SELECT ... FOR UPDATE of the wallet row.
 *  tbl_manager_transfers            provider funding / withdrawal / adjustments / parent->child
 *                                   transfer; provider funding has NO source wallet (no fake root
 *                                   wallet); mandatory reason; reversible at most once.
 *  tbl_manager_ledger               append-only wallet journal; positive amount + direction;
 *                                   balance before/after with arithmetic CHECK; unique entry key;
 *                                   each entry reversible at most once. Provider/system settlement
 *                                   is NOT a ledger row (it lives on the activation).
 *  tbl_manager_wallet_reservations  at most one ACTIVE reservation per activation (stored
 *                                   generated column + UNIQUE); history rows are kept.
 *
 * The "direct child" transfer rule, debt-limit exposure rules and immutability enforcement are
 * WP3A service/database responsibilities (owner decision Q5: no triggers in WP1).
 * Money: DECIMAL(18,2). Timestamps: DATETIME(6), UTC, always written by the service.
 */

$c = ' collate utf8mb4_general_ci';
$actors = "'STAFF','CUSTOMER','SYSTEM','CRON','API'";

$walletsSql = <<<'SQL'
CREATE TABLE `tbl_manager_wallets` (
  `manager_id` INT UNSIGNED NOT NULL COMMENT 'tbl_users.id (one wallet per manager)',
  `balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `debt_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`manager_id`),
  CONSTRAINT `fk_wallet_manager` FOREIGN KEY (`manager_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_wallet_debt_nonneg` CHECK (`debt_limit` >= 0),
  CONSTRAINT `ck_wallet_floor` CHECK (`balance` + `debt_limit` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Reseller wallets (WP1 foundation)'
SQL;

$transfersSql = <<<'SQL'
CREATE TABLE `tbl_manager_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(64) NOT NULL COMMENT 'unique reference / idempotency',
  `operation_type` ENUM('PROVIDER_FUNDING','PROVIDER_WITHDRAWAL','ADJUSTMENT_CREDIT','ADJUSTMENT_DEBIT','PARENT_CHILD_TRANSFER') NOT NULL,
  `from_manager_id` INT UNSIGNED NULL COMMENT 'NULL for provider funding / credit adjustment',
  `to_manager_id` INT UNSIGNED NULL COMMENT 'NULL for provider withdrawal / debit adjustment',
  `amount` DECIMAL(18,2) NOT NULL,
  `status` ENUM('PENDING','COMPLETED','FAILED','CANCELLED','REVERSED') NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `external_reference` VARCHAR(100) NULL,
  `operator_id` INT UNSIGNED NOT NULL COMMENT 'tbl_users.id',
  `reverses_transfer_id` BIGINT UNSIGNED NULL,
  `failure_reason` VARCHAR(255) NULL,
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  `completed_at` DATETIME(6) NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trf_reference` (`reference`),
  UNIQUE KEY `uq_trf_reverses` (`reverses_transfer_id`),
  KEY `ix_trf_from_created` (`from_manager_id`, `created_at`),
  KEY `ix_trf_to_created` (`to_manager_id`, `created_at`),
  KEY `ix_trf_status_created` (`status`, `created_at`),
  KEY `ix_trf_operator` (`operator_id`),
  CONSTRAINT `fk_trf_from` FOREIGN KEY (`from_manager_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_trf_to` FOREIGN KEY (`to_manager_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_trf_operator` FOREIGN KEY (`operator_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_trf_reverses` FOREIGN KEY (`reverses_transfer_id`) REFERENCES `tbl_manager_transfers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_trf_amount_pos` CHECK (`amount` > 0),
  CONSTRAINT `ck_trf_shape` CHECK (
    (`operation_type` IN ('PROVIDER_FUNDING','ADJUSTMENT_CREDIT') AND `from_manager_id` IS NULL AND `to_manager_id` IS NOT NULL)
    OR (`operation_type` IN ('PROVIDER_WITHDRAWAL','ADJUSTMENT_DEBIT') AND `from_manager_id` IS NOT NULL AND `to_manager_id` IS NULL)
    OR (`operation_type` = 'PARENT_CHILD_TRANSFER' AND `from_manager_id` IS NOT NULL AND `to_manager_id` IS NOT NULL AND `from_manager_id` <> `to_manager_id`)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Funding / withdrawal / adjustment / parent-child transfers (WP1 foundation)'
SQL;

$ledgerSql = <<<SQL
CREATE TABLE `tbl_manager_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_key` VARCHAR(128) NOT NULL COMMENT 'stable idempotency key per entry',
  `manager_id` INT UNSIGNED NOT NULL COMMENT 'wallet owner',
  `entry_type` ENUM('FUNDING','WITHDRAWAL','ACTIVATION_DEBIT','COMMISSION_CREDIT','TRANSFER_OUT','TRANSFER_IN','REFUND','REVERSAL','ADJUSTMENT') NOT NULL,
  `direction` ENUM('CREDIT','DEBIT') NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL COMMENT 'always positive; direction carries the sign',
  `balance_before` DECIMAL(18,2) NOT NULL,
  `balance_after` DECIMAL(18,2) NOT NULL,
  `activation_id` BIGINT UNSIGNED NULL,
  `transfer_id` BIGINT UNSIGNED NULL,
  `counterparty_manager_id` INT UNSIGNED NULL,
  `reverses_entry_id` BIGINT UNSIGNED NULL COMMENT 'compensating entry for (D012)',
  `operator_id` INT UNSIGNED NULL COMMENT 'tbl_users.id; NULL for system/cron',
  `actor_type` ENUM({$actors}) NOT NULL,
  `reason` VARCHAR(255) NULL,
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ledger_entry_key` (`entry_key`),
  UNIQUE KEY `uq_ledger_reverses` (`reverses_entry_id`),
  KEY `ix_ledger_manager_created` (`manager_id`, `created_at`),
  KEY `ix_ledger_activation` (`activation_id`),
  KEY `ix_ledger_transfer` (`transfer_id`),
  KEY `ix_ledger_counterparty` (`counterparty_manager_id`),
  KEY `ix_ledger_operator` (`operator_id`),
  CONSTRAINT `fk_ledger_wallet` FOREIGN KEY (`manager_id`) REFERENCES `tbl_manager_wallets` (`manager_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ledger_activation` FOREIGN KEY (`activation_id`) REFERENCES `tbl_reseller_activations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ledger_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `tbl_manager_transfers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ledger_counterparty` FOREIGN KEY (`counterparty_manager_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ledger_reverses` FOREIGN KEY (`reverses_entry_id`) REFERENCES `tbl_manager_ledger` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ledger_operator` FOREIGN KEY (`operator_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_ledger_amount_pos` CHECK (`amount` > 0),
  CONSTRAINT `ck_ledger_math` CHECK (
    (`direction` = 'CREDIT' AND `balance_after` = `balance_before` + `amount`)
    OR (`direction` = 'DEBIT' AND `balance_after` = `balance_before` - `amount`)
  ),
  CONSTRAINT `ck_ledger_staff_operator` CHECK (`actor_type` <> 'STAFF' OR `operator_id` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Append-only reseller wallet ledger (WP1 foundation)'
SQL;

$reservationsSql = <<<'SQL'
CREATE TABLE `tbl_manager_wallet_reservations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `manager_id` INT UNSIGNED NOT NULL COMMENT 'wallet owner',
  `activation_id` BIGINT UNSIGNED NOT NULL COMMENT 'authoritative business operation',
  `amount` DECIMAL(18,2) NOT NULL,
  `status` ENUM('ACTIVE','COMMITTED','RELEASED','EXPIRED') NOT NULL,
  `active_activation_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` = 'ACTIVE' THEN `activation_id` END) STORED COMMENT 'enforces one ACTIVE reservation per activation',
  `committed_ledger_entry_id` BIGINT UNSIGNED NULL,
  `expires_at` DATETIME(6) NULL COMMENT 'UTC; UNKNOWN outcomes are never released blindly',
  `resolution_note` VARCHAR(255) NULL,
  `resolved_by` INT UNSIGNED NULL COMMENT 'tbl_users.id',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  `resolved_at` DATETIME(6) NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_res_one_active` (`active_activation_id`),
  UNIQUE KEY `uq_res_committed_entry` (`committed_ledger_entry_id`),
  KEY `ix_res_manager_status` (`manager_id`, `status`),
  KEY `ix_res_activation` (`activation_id`),
  KEY `ix_res_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_res_wallet` FOREIGN KEY (`manager_id`) REFERENCES `tbl_manager_wallets` (`manager_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_res_activation` FOREIGN KEY (`activation_id`) REFERENCES `tbl_reseller_activations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_res_ledger_entry` FOREIGN KEY (`committed_ledger_entry_id`) REFERENCES `tbl_manager_ledger` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_res_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_res_amount_pos` CHECK (`amount` > 0),
  CONSTRAINT `ck_res_resolution` CHECK ((`status` = 'ACTIVE' AND `resolved_at` IS NULL) OR (`status` <> 'ACTIVE' AND `resolved_at` IS NOT NULL)),
  CONSTRAINT `ck_res_commit_link` CHECK (`status` <> 'COMMITTED' OR `committed_ledger_entry_id` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Wallet reservations for uncertain provisioning (WP1 foundation)'
SQL;

return [
    'id' => 'R0004_wallet_finance_foundation',
    'description' => 'Manager wallets, funding/transfers, append-only ledger, reservations',
    'requires' => 'R0003_activation_foundation',
    'up' => [
        ['id' => 'create_tbl_manager_wallets', 'sql' => $walletsSql],
        ['id' => 'create_tbl_manager_transfers', 'sql' => $transfersSql],
        ['id' => 'create_tbl_manager_ledger', 'sql' => $ledgerSql],
        ['id' => 'create_tbl_manager_wallet_reservations', 'sql' => $reservationsSql],
    ],
    'down' => [
        ['id' => 'drop_tbl_manager_wallet_reservations', 'sql' => 'DROP TABLE `tbl_manager_wallet_reservations`', 'if_exists' => ['table' => 'tbl_manager_wallet_reservations']],
        ['id' => 'drop_tbl_manager_ledger', 'sql' => 'DROP TABLE `tbl_manager_ledger`', 'if_exists' => ['table' => 'tbl_manager_ledger']],
        ['id' => 'drop_tbl_manager_transfers', 'sql' => 'DROP TABLE `tbl_manager_transfers`', 'if_exists' => ['table' => 'tbl_manager_transfers']],
        ['id' => 'drop_tbl_manager_wallets', 'sql' => 'DROP TABLE `tbl_manager_wallets`', 'if_exists' => ['table' => 'tbl_manager_wallets']],
    ],
    'contract' => [
        'tables' => [
            'tbl_manager_wallets' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'manager_id' => 'int unsigned not null',
                    'balance' => "decimal(18,2) not null default '0.00'",
                    'debt_limit' => "decimal(18,2) not null default '0.00'",
                    'created_at' => 'datetime(6) not null',
                    'updated_at' => 'datetime(6) not null',
                ],
                'indexes' => ['PRIMARY KEY (manager_id)'],
                'foreign_keys' => [
                    'fk_wallet_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_wallet_debt_nonneg', 'ck_wallet_floor'],
            ],
            'tbl_manager_transfers' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'reference' => "varchar(64){$c} not null",
                    'operation_type' => "enum('PROVIDER_FUNDING','PROVIDER_WITHDRAWAL','ADJUSTMENT_CREDIT','ADJUSTMENT_DEBIT','PARENT_CHILD_TRANSFER'){$c} not null",
                    'from_manager_id' => 'int unsigned null',
                    'to_manager_id' => 'int unsigned null',
                    'amount' => 'decimal(18,2) not null',
                    'status' => "enum('PENDING','COMPLETED','FAILED','CANCELLED','REVERSED'){$c} not null",
                    'reason' => "varchar(255){$c} not null",
                    'external_reference' => "varchar(100){$c} null",
                    'operator_id' => 'int unsigned not null',
                    'reverses_transfer_id' => 'bigint unsigned null',
                    'failure_reason' => "varchar(255){$c} null",
                    'created_at' => 'datetime(6) not null',
                    'completed_at' => 'datetime(6) null',
                ],
                'indexes' => [
                    'INDEX ix_trf_from_created (from_manager_id,created_at)',
                    'INDEX ix_trf_operator (operator_id)',
                    'INDEX ix_trf_status_created (status,created_at)',
                    'INDEX ix_trf_to_created (to_manager_id,created_at)',
                    'PRIMARY KEY (id)',
                    'UNIQUE uq_trf_reference (reference)',
                    'UNIQUE uq_trf_reverses (reverses_transfer_id)',
                ],
                'foreign_keys' => [
                    'fk_trf_from (from_manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_trf_operator (operator_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_trf_reverses (reverses_transfer_id) REFERENCES tbl_manager_transfers (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_trf_to (to_manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_trf_amount_pos', 'ck_trf_shape'],
            ],
            'tbl_manager_ledger' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'entry_key' => "varchar(128){$c} not null",
                    'manager_id' => 'int unsigned not null',
                    'entry_type' => "enum('FUNDING','WITHDRAWAL','ACTIVATION_DEBIT','COMMISSION_CREDIT','TRANSFER_OUT','TRANSFER_IN','REFUND','REVERSAL','ADJUSTMENT'){$c} not null",
                    'direction' => "enum('CREDIT','DEBIT'){$c} not null",
                    'amount' => 'decimal(18,2) not null',
                    'balance_before' => 'decimal(18,2) not null',
                    'balance_after' => 'decimal(18,2) not null',
                    'activation_id' => 'bigint unsigned null',
                    'transfer_id' => 'bigint unsigned null',
                    'counterparty_manager_id' => 'int unsigned null',
                    'reverses_entry_id' => 'bigint unsigned null',
                    'operator_id' => 'int unsigned null',
                    'actor_type' => "enum({$actors}){$c} not null",
                    'reason' => "varchar(255){$c} null",
                    'created_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'INDEX ix_ledger_activation (activation_id)',
                    'INDEX ix_ledger_counterparty (counterparty_manager_id)',
                    'INDEX ix_ledger_manager_created (manager_id,created_at)',
                    'INDEX ix_ledger_operator (operator_id)',
                    'INDEX ix_ledger_transfer (transfer_id)',
                    'PRIMARY KEY (id)',
                    'UNIQUE uq_ledger_entry_key (entry_key)',
                    'UNIQUE uq_ledger_reverses (reverses_entry_id)',
                ],
                'foreign_keys' => [
                    'fk_ledger_activation (activation_id) REFERENCES tbl_reseller_activations (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_ledger_counterparty (counterparty_manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_ledger_operator (operator_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_ledger_reverses (reverses_entry_id) REFERENCES tbl_manager_ledger (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_ledger_transfer (transfer_id) REFERENCES tbl_manager_transfers (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_ledger_wallet (manager_id) REFERENCES tbl_manager_wallets (manager_id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_ledger_amount_pos', 'ck_ledger_math', 'ck_ledger_staff_operator'],
            ],
            'tbl_manager_wallet_reservations' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'manager_id' => 'int unsigned not null',
                    'activation_id' => 'bigint unsigned not null',
                    'amount' => 'decimal(18,2) not null',
                    'status' => "enum('ACTIVE','COMMITTED','RELEASED','EXPIRED'){$c} not null",
                    'active_activation_id' => 'bigint unsigned null stored generated',
                    'committed_ledger_entry_id' => 'bigint unsigned null',
                    'expires_at' => 'datetime(6) null',
                    'resolution_note' => "varchar(255){$c} null",
                    'resolved_by' => 'int unsigned null',
                    'created_at' => 'datetime(6) not null',
                    'resolved_at' => 'datetime(6) null',
                ],
                'indexes' => [
                    'INDEX ix_res_activation (activation_id)',
                    'INDEX ix_res_manager_status (manager_id,status)',
                    'INDEX ix_res_resolved_by (resolved_by)',
                    'PRIMARY KEY (id)',
                    'UNIQUE uq_res_committed_entry (committed_ledger_entry_id)',
                    'UNIQUE uq_res_one_active (active_activation_id)',
                ],
                'foreign_keys' => [
                    'fk_res_activation (activation_id) REFERENCES tbl_reseller_activations (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_res_ledger_entry (committed_ledger_entry_id) REFERENCES tbl_manager_ledger (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_res_resolved_by (resolved_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_res_wallet (manager_id) REFERENCES tbl_manager_wallets (manager_id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_res_amount_pos', 'ck_res_commit_link', 'ck_res_resolution'],
            ],
        ],
        'table_parts' => [],
    ],
];
