<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0005 — customer commercial ownership and optional Sales assignment (owner decision C19).
 *
 * The only change to a legacy table in WP1, applied last and as ONE atomic ALTER statement.
 *  - manager_id, assigned_sales_id: INT UNSIGNED NULL (match tbl_users.id INT UNSIGNED).
 *  - NO backfill: every existing customer stays NULL-owned (Admin/SuperAdmin-only in the new
 *    domain, D007). created_by is NOT used as input; tbl_users.root is NOT used.
 *  - Legacy columns (incl. signed created_by) are untouched.
 * Rollback drops the foreign keys first, then the columns (their single-column indexes go with
 * them), restoring the exact pre-migration definition of tbl_customers.
 */

return [
    'id' => 'R0005_customer_ownership',
    'description' => 'tbl_customers.manager_id + assigned_sales_id (nullable, no backfill)',
    'requires' => 'R0004_wallet_finance_foundation',
    'up' => [
        [
            'id' => 'alter_tbl_customers_add_ownership',
            'sql' => <<<'SQL'
ALTER TABLE `tbl_customers`
  ADD COLUMN `manager_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'commercial owner (tbl_users.id); NULL = unassigned legacy customer',
  ADD COLUMN `assigned_sales_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'optional Sales assignment (tbl_users.id)',
  ADD KEY `ix_customers_manager` (`manager_id`),
  ADD KEY `ix_customers_assigned_sales` (`assigned_sales_id`),
  ADD CONSTRAINT `fk_customers_manager` FOREIGN KEY (`manager_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_customers_assigned_sales` FOREIGN KEY (`assigned_sales_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL,
        ],
    ],
    'down' => [
        [
            'id' => 'drop_fk_customers_assigned_sales',
            'sql' => 'ALTER TABLE `tbl_customers` DROP FOREIGN KEY `fk_customers_assigned_sales`',
            'if_exists' => ['foreign_key' => ['tbl_customers', 'fk_customers_assigned_sales']],
        ],
        [
            'id' => 'drop_fk_customers_manager',
            'sql' => 'ALTER TABLE `tbl_customers` DROP FOREIGN KEY `fk_customers_manager`',
            'if_exists' => ['foreign_key' => ['tbl_customers', 'fk_customers_manager']],
        ],
        [
            'id' => 'drop_col_assigned_sales_id',
            'sql' => 'ALTER TABLE `tbl_customers` DROP COLUMN `assigned_sales_id`',
            'if_exists' => ['column' => ['tbl_customers', 'assigned_sales_id']],
        ],
        [
            'id' => 'drop_col_manager_id',
            'sql' => 'ALTER TABLE `tbl_customers` DROP COLUMN `manager_id`',
            'if_exists' => ['column' => ['tbl_customers', 'manager_id']],
        ],
    ],
    'contract' => [
        'tables' => [],
        'table_parts' => [
            'tbl_customers' => [
                'columns' => [
                    'manager_id' => 'int unsigned null',
                    'assigned_sales_id' => 'int unsigned null',
                ],
                'indexes' => [
                    'INDEX ix_customers_assigned_sales (assigned_sales_id)',
                    'INDEX ix_customers_manager (manager_id)',
                ],
                'foreign_keys' => [
                    'fk_customers_assigned_sales (assigned_sales_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_customers_manager (manager_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => [],
            ],
        ],
    ],
];
