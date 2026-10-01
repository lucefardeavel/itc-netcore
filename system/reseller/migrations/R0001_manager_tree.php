<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0001 — reseller hierarchy foundation (closure table).
 * Schema only: NO import from tbl_users.root (owner decision C20), no cycle-prevention logic
 * (WP2). Rows will be maintained exclusively by the WP2 tree service.
 * depth 0 = self row (ancestor_id = descendant_id); depth 1 = direct child.
 */

return [
    'id' => 'R0001_manager_tree',
    'description' => 'Reseller/Sales hierarchy closure table (empty; no import from tbl_users.root)',
    'requires' => null,
    'up' => [
        [
            'id' => 'create_tbl_manager_tree',
            'sql' => <<<'SQL'
CREATE TABLE `tbl_manager_tree` (
  `ancestor_id` INT UNSIGNED NOT NULL COMMENT 'tbl_users.id',
  `descendant_id` INT UNSIGNED NOT NULL COMMENT 'tbl_users.id',
  `depth` SMALLINT UNSIGNED NOT NULL COMMENT '0 = self row, 1 = direct child',
  `created_at` DATETIME(6) NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`ancestor_id`, `descendant_id`),
  KEY `ix_tree_descendant_depth` (`descendant_id`, `depth`),
  KEY `ix_tree_ancestor_depth` (`ancestor_id`, `depth`),
  CONSTRAINT `fk_tree_ancestor` FOREIGN KEY (`ancestor_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_tree_descendant` FOREIGN KEY (`descendant_id`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_tree_depth_self` CHECK ((`depth` = 0 AND `ancestor_id` = `descendant_id`) OR (`depth` > 0 AND `ancestor_id` <> `descendant_id`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Reseller hierarchy closure (WP1 foundation)'
SQL,
        ],
    ],
    'down' => [
        ['id' => 'drop_tbl_manager_tree', 'sql' => 'DROP TABLE `tbl_manager_tree`', 'if_exists' => ['table' => 'tbl_manager_tree']],
    ],
    'contract' => [
        'tables' => [
            'tbl_manager_tree' => [
                'engine' => 'innodb',
                'collation' => 'utf8mb4_general_ci',
                'columns' => [
                    'ancestor_id' => 'int unsigned not null',
                    'descendant_id' => 'int unsigned not null',
                    'depth' => 'smallint unsigned not null',
                    'created_at' => 'datetime(6) not null',
                ],
                'indexes' => [
                    'INDEX ix_tree_ancestor_depth (ancestor_id,depth)',
                    'INDEX ix_tree_descendant_depth (descendant_id,depth)',
                    'PRIMARY KEY (ancestor_id,descendant_id)',
                ],
                'foreign_keys' => [
                    'fk_tree_ancestor (ancestor_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'fk_tree_descendant (descendant_id) REFERENCES tbl_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                ],
                'checks' => ['ck_tree_depth_self'],
            ],
        ],
        'table_parts' => [],
    ],
];
