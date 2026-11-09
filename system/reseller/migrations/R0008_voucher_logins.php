<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0008 — login password of username-and-password hotspot cards (owner decision 2026-11-09).
 *
 * A hotspot card is either "code only" (the code is the user name and the password, as before) or "user name and
 * password": the code is the user name and a separate password is printed on the card. This table holds that
 * password, one row per such voucher. A voucher without a row is a code-only card.
 *
 * Owner-approved exception to "no voucher-commerce schema" (WP2 OD-1): this is a login credential of a card, not a
 * commerce object; nothing about price, ownership, stock or sale is stored.
 * The password is kept in clear text because RADIUS PAP / CHAP needs it (as tbl_customers.password).
 * No foreign key: the application deletes the row in the same request that deletes the voucher, and a missing row
 * simply means a code-only card.
 */

$c = ' collate utf8mb4_general_ci';

$sql = <<<SQL
CREATE TABLE `tbl_voucher_logins` (
  `voucher_id` INT NOT NULL COMMENT 'tbl_voucher.id (no FK by design; deleted with the voucher)',
  `password` VARCHAR(32) NOT NULL COMMENT 'hotspot login password printed on the card',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Login password of username-and-password hotspot cards'
SQL;

return [
    'id' => 'R0008_voucher_logins',
    'description' => 'Login password of username-and-password hotspot cards (one row per such voucher, no FKs by design)',
    'requires' => 'R0007_activation_reversal_states',
    'up' => [
        ['id' => 'create_tbl_voucher_logins', 'sql' => $sql],
    ],
    'down' => [
        ['id' => 'drop_tbl_voucher_logins', 'sql' => 'DROP TABLE `tbl_voucher_logins`', 'if_exists' => ['table' => 'tbl_voucher_logins']],
    ],
    'contract' => [
        'tables' => [
            'tbl_voucher_logins' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'voucher_id' => 'int not null',
                    'password' => "varchar(32){$c} not null",
                    'created_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'PRIMARY KEY (voucher_id)',
                ],
                'foreign_keys' => [],
                'checks' => [],
            ],
        ],
        'table_parts' => [],
    ],
];
