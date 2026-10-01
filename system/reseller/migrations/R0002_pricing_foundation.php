<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0002 — pricing foundation.
 *  - tbl_reseller_plan_base_costs: explicit system/provider wholesale base cost per plan
 *    (D009: legacy tbl_plans.price is NOT repurposed and NOT modified; owner decision C12).
 *    A plan without a row is not resellable (fail closed, enforced from WP3B).
 *    Current rows carry actor/timestamp audit; history is preserved by activation snapshots (Q4).
 *  - tbl_manager_plan_prices: per-manager wholesale override (set by parent/Admin), optional
 *    retail override (set by the manager) and optional availability override. No row = no
 *    manager-specific configuration. Each override is independent and NULL = inherit. The
 *    *_set_by / *_set_at fields describe only the CURRENT explicit override (provenance, not
 *    history): value NULL => both NULL; value set => both set. Historical pricing is preserved
 *    by activation snapshots; configuration-change audit history is a later service/audit layer.
 *    Availability override:
 *      is_enabled NULL = inherit (no availability override; audit fields must be NULL)
 *      is_enabled 0    = explicitly disabled           } availability_set_by and
 *      is_enabled 1    = explicitly enabled, still      } availability_set_at required
 *                        subject to ancestor/global restrictions (WP3B)
 *    Inheritance, ancestor-disable, D023 and "retail >= own wholesale" are service rules (WP3B),
 *    not database constraints.
 * Money: DECIMAL(18,2). Timestamps: DATETIME(6), UTC, always written by the service.
 */

return [
    'id' => 'R0002_pricing_foundation',
    'description' => 'Provider wholesale base cost per plan + per-manager wholesale/retail/availability overrides',
    'requires' => 'R0001_manager_tree',
    'up' => [
        [
            'id' => 'create_tbl_reseller_plan_base_costs',
            'sql' => <<<'SQL'
CREATE TABLE `tbl_reseller_plan_base_costs` (
  `plan_id` INT NOT NULL COMMENT 'tbl_plans.id',
  `base_cost` DECIMAL(18,2) NOT NULL COMMENT 'system/provider wholesale base cost',
  `is_resale_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NOT NULL COMMENT 'tbl_users.id',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  `updated_by` INT UNSIGNED NOT NULL COMMENT 'tbl_users.id',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`plan_id`),
  KEY `ix_basecost_created_by` (`created_by`),
  KEY `ix_basecost_updated_by` (`updated_by`),
  CONSTRAINT `fk_basecost_plan` FOREIGN KEY (`plan_id`) REFERENCES `tbl_plans` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_basecost_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_basecost_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_basecost_nonneg` CHECK (`base_cost` >= 0),
  CONSTRAINT `ck_basecost_resale_bool` CHECK (`is_resale_enabled` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Provider wholesale base cost per plan (WP1 foundation)'
SQL,
        ],
        [
            'id' => 'create_tbl_manager_plan_prices',
            'sql' => <<<'SQL'
CREATE TABLE `tbl_manager_plan_prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `manager_id` INT UNSIGNED NOT NULL COMMENT 'tbl_users.id',
  `plan_id` INT NOT NULL COMMENT 'tbl_plans.id',
  `wholesale_cost` DECIMAL(18,2) NULL COMMENT 'override set by parent/Admin; NULL = inherit',
  `wholesale_set_by` INT UNSIGNED NULL COMMENT 'tbl_users.id',
  `wholesale_set_at` DATETIME(6) NULL COMMENT 'UTC',
  `retail_price` DECIMAL(18,2) NULL COMMENT 'manager customer-facing price; NULL = legacy default retail',
  `retail_set_by` INT UNSIGNED NULL COMMENT 'tbl_users.id',
  `retail_set_at` DATETIME(6) NULL COMMENT 'UTC',
  `is_enabled` TINYINT(1) NULL DEFAULT NULL COMMENT 'availability override: NULL = inherit, 0 = disabled, 1 = enabled (subject to ancestor/global restrictions)',
  `availability_set_by` INT UNSIGNED NULL COMMENT 'tbl_users.id',
  `availability_set_at` DATETIME(6) NULL COMMENT 'UTC',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  `updated_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpp_manager_plan` (`manager_id`, `plan_id`),
  KEY `ix_mpp_plan` (`plan_id`),
  KEY `ix_mpp_wholesale_set_by` (`wholesale_set_by`),
  KEY `ix_mpp_retail_set_by` (`retail_set_by`),
  KEY `ix_mpp_availability_set_by` (`availability_set_by`),
  CONSTRAINT `fk_mpp_manager` FOREIGN KEY (`manager_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mpp_plan` FOREIGN KEY (`plan_id`) REFERENCES `tbl_plans` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mpp_wholesale_set_by` FOREIGN KEY (`wholesale_set_by`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mpp_retail_set_by` FOREIGN KEY (`retail_set_by`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mpp_availability_set_by` FOREIGN KEY (`availability_set_by`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_mpp_wholesale_nonneg` CHECK (`wholesale_cost` IS NULL OR `wholesale_cost` >= 0),
  CONSTRAINT `ck_mpp_retail_nonneg` CHECK (`retail_price` IS NULL OR `retail_price` >= 0),
  CONSTRAINT `ck_mpp_enabled_bool` CHECK (`is_enabled` IS NULL OR `is_enabled` IN (0, 1)),
  CONSTRAINT `ck_mpp_availability_audit` CHECK ((`is_enabled` IS NULL AND `availability_set_by` IS NULL AND `availability_set_at` IS NULL) OR (`is_enabled` IS NOT NULL AND `availability_set_by` IS NOT NULL AND `availability_set_at` IS NOT NULL)),
  CONSTRAINT `ck_mpp_wholesale_audit` CHECK ((`wholesale_cost` IS NULL AND `wholesale_set_by` IS NULL AND `wholesale_set_at` IS NULL) OR (`wholesale_cost` IS NOT NULL AND `wholesale_set_by` IS NOT NULL AND `wholesale_set_at` IS NOT NULL)),
  CONSTRAINT `ck_mpp_retail_audit` CHECK ((`retail_price` IS NULL AND `retail_set_by` IS NULL AND `retail_set_at` IS NULL) OR (`retail_price` IS NOT NULL AND `retail_set_by` IS NOT NULL AND `retail_set_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Per-manager plan pricing overrides (WP1 foundation)'
SQL,
        ],
    ],
    'down' => [
        ['id' => 'drop_tbl_manager_plan_prices', 'sql' => 'DROP TABLE `tbl_manager_plan_prices`', 'if_exists' => ['table' => 'tbl_manager_plan_prices']],
        ['id' => 'drop_tbl_reseller_plan_base_costs', 'sql' => 'DROP TABLE `tbl_reseller_plan_base_costs`', 'if_exists' => ['table' => 'tbl_reseller_plan_base_costs']],
    ],
    'contract' => [
        'tables' => [
            'tbl_reseller_plan_base_costs' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'plan_id' => 'int not null',
                    'base_cost' => 'decimal(18,2) not null',
                    'is_resale_enabled' => "tinyint not null default '0'",
                    'created_by' => 'int unsigned not null',
                    'created_at' => 'datetime(6) not null',
                    'updated_by' => 'int unsigned not null',
                    'updated_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'INDEX ix_basecost_created_by (created_by)',
                    'INDEX ix_basecost_updated_by (updated_by)',
                    'PRIMARY KEY (plan_id)',
                ],
                'foreign_keys' => [
                    'fk_basecost_created_by (created_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_basecost_plan (plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_basecost_updated_by (updated_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_basecost_nonneg', 'ck_basecost_resale_bool'],
            ],
            'tbl_manager_plan_prices' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'manager_id' => 'int unsigned not null',
                    'plan_id' => 'int not null',
                    'wholesale_cost' => 'decimal(18,2) null',
                    'wholesale_set_by' => 'int unsigned null',
                    'wholesale_set_at' => 'datetime(6) null',
                    'retail_price' => 'decimal(18,2) null',
                    'retail_set_by' => 'int unsigned null',
                    'retail_set_at' => 'datetime(6) null',
                    'is_enabled' => 'tinyint null',
                    'availability_set_by' => 'int unsigned null',
                    'availability_set_at' => 'datetime(6) null',
                    'created_at' => 'datetime(6) not null',
                    'updated_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'INDEX ix_mpp_availability_set_by (availability_set_by)',
                    'INDEX ix_mpp_plan (plan_id)',
                    'INDEX ix_mpp_retail_set_by (retail_set_by)',
                    'INDEX ix_mpp_wholesale_set_by (wholesale_set_by)',
                    'PRIMARY KEY (id)',
                    'UNIQUE uq_mpp_manager_plan (manager_id,plan_id)',
                ],
                'foreign_keys' => [
                    'fk_mpp_availability_set_by (availability_set_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_mpp_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_mpp_plan (plan_id) REFERENCES tbl_plans (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_mpp_retail_set_by (retail_set_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_mpp_wholesale_set_by (wholesale_set_by) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_mpp_availability_audit', 'ck_mpp_enabled_bool', 'ck_mpp_retail_audit', 'ck_mpp_retail_nonneg', 'ck_mpp_wholesale_audit', 'ck_mpp_wholesale_nonneg'],
            ],
        ],
        'table_parts' => [],
    ],
];
