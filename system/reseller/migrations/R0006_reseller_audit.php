<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0006 — reseller-domain audit trail (WP2; owner decision OD-1, Option A).
 *
 * Append-only evidence for security-relevant reseller-domain actions: staff create / role change /
 * disable / reactivate / delete, hierarchy placement and moves, customer ownership and Sales
 * assignment changes, legacy-owner assignment, refused restores and refused service operations.
 *
 * No foreign keys (owner-approved exception): audit evidence must survive later deletion or change
 * of the referenced identities, and must not itself block legitimate deletes. Identity snapshots
 * (JSON) preserve who/what was involved at event time.
 * The application never updates or deletes rows (no code path exists); the legacy restore is
 * refused while this table exists (C24 guard); log-retention deletes do not touch it.
 * Payloads are redacted by the writer: never passwords, secrets, tokens or OTPs.
 * JSON portability: LONGTEXT + named CHECK (JSON_VALID) as in WP1.
 */

$c = ' collate utf8mb4_general_ci';
$actors = "'STAFF','CUSTOMER','SYSTEM','CRON','API'";

$sql = <<<SQL
CREATE TABLE `tbl_reseller_audit_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(64) NOT NULL COMMENT 'e.g. staff.create, tree.move, customer.transfer_owner',
  `actor_type` ENUM({$actors}) NOT NULL,
  `actor_user_id` INT UNSIGNED NULL COMMENT 'tbl_users.id at event time (no FK by design)',
  `actor_customer_id` INT NULL COMMENT 'tbl_customers.id at event time (no FK by design)',
  `actor_snapshot` LONGTEXT NOT NULL COMMENT 'JSON identity snapshot of the actor',
  `target_type` VARCHAR(32) NOT NULL COMMENT 'staff | customer | tree | system',
  `target_user_id` INT UNSIGNED NULL COMMENT 'tbl_users.id (no FK by design)',
  `target_customer_id` INT NULL COMMENT 'tbl_customers.id (no FK by design)',
  `target_snapshot` LONGTEXT NULL COMMENT 'JSON identity snapshot of the target',
  `before_json` LONGTEXT NULL COMMENT 'JSON, redacted',
  `after_json` LONGTEXT NULL COMMENT 'JSON, redacted',
  `reason` VARCHAR(255) NULL,
  `request_id` CHAR(32) NOT NULL COMMENT 'correlates with server logs',
  `channel` ENUM('WEB','API','CLI') NOT NULL,
  `ip` VARCHAR(45) NULL COMMENT 'REMOTE_ADDR only',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `ix_audit_target_user` (`target_user_id`, `created_at`),
  KEY `ix_audit_target_customer` (`target_customer_id`, `created_at`),
  KEY `ix_audit_actor_user` (`actor_user_id`, `created_at`),
  KEY `ix_audit_event_type` (`event_type`, `created_at`),
  CONSTRAINT `ck_audit_staff_actor` CHECK (`actor_type` <> 'STAFF' OR `actor_user_id` IS NOT NULL),
  CONSTRAINT `ck_audit_customer_actor` CHECK (`actor_type` <> 'CUSTOMER' OR `actor_customer_id` IS NOT NULL),
  CONSTRAINT `ck_audit_actor_snapshot_json` CHECK (JSON_VALID(`actor_snapshot`)),
  CONSTRAINT `ck_audit_target_snapshot_json` CHECK (`target_snapshot` IS NULL OR JSON_VALID(`target_snapshot`)),
  CONSTRAINT `ck_audit_before_json` CHECK (`before_json` IS NULL OR JSON_VALID(`before_json`)),
  CONSTRAINT `ck_audit_after_json` CHECK (`after_json` IS NULL OR JSON_VALID(`after_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Append-only reseller-domain audit trail (WP2)'
SQL;

return [
    'id' => 'R0006_reseller_audit',
    'description' => 'Append-only reseller-domain audit trail (identity snapshots, redacted before/after, no FKs by design)',
    'requires' => 'R0005_customer_ownership',
    'up' => [
        ['id' => 'create_tbl_reseller_audit_events', 'sql' => $sql],
    ],
    'down' => [
        ['id' => 'drop_tbl_reseller_audit_events', 'sql' => 'DROP TABLE `tbl_reseller_audit_events`', 'if_exists' => ['table' => 'tbl_reseller_audit_events']],
    ],
    'contract' => [
        'tables' => [
            'tbl_reseller_audit_events' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'id' => 'bigint unsigned not null auto_increment',
                    'event_type' => "varchar(64){$c} not null",
                    'actor_type' => "enum({$actors}){$c} not null",
                    'actor_user_id' => 'int unsigned null',
                    'actor_customer_id' => 'int null',
                    'actor_snapshot' => "longtext{$c} not null",
                    'target_type' => "varchar(32){$c} not null",
                    'target_user_id' => 'int unsigned null',
                    'target_customer_id' => 'int null',
                    'target_snapshot' => "longtext{$c} null",
                    'before_json' => "longtext{$c} null",
                    'after_json' => "longtext{$c} null",
                    'reason' => "varchar(255){$c} null",
                    'request_id' => "char(32){$c} not null",
                    'channel' => "enum('WEB','API','CLI'){$c} not null",
                    'ip' => "varchar(45){$c} null",
                    'created_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'INDEX ix_audit_actor_user (actor_user_id,created_at)',
                    'INDEX ix_audit_event_type (event_type,created_at)',
                    'INDEX ix_audit_target_customer (target_customer_id,created_at)',
                    'INDEX ix_audit_target_user (target_user_id,created_at)',
                    'PRIMARY KEY (id)',
                ],
                'foreign_keys' => [],
                'checks' => [
                    'ck_audit_actor_snapshot_json', 'ck_audit_after_json', 'ck_audit_before_json',
                    'ck_audit_customer_actor', 'ck_audit_staff_actor', 'ck_audit_target_snapshot_json',
                ],
            ],
        ],
        'table_parts' => [],
    ],
];
