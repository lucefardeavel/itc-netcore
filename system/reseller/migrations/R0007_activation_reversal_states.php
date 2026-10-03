<?php
// CLI-only data file: returns the migration definition; no side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * R0007 — activation reversal states (WP5B; owner decision OD-14, option B).
 *
 * Extends the activation status set with the two dedicated states of a COMPLETED-activation reversal:
 *   COMPLETED -> REVERSAL_PENDING -> REVERSED (terminal).
 * The WP5A ROLLBACK_PENDING / ROLLED_BACK states keep their pre-completion meaning.
 *
 * Nothing else: no data change, no new table, column, index or constraint. The two values are appended
 * to the ENUMs of tbl_reseller_activations.status and tbl_reseller_activation_events.from_state / to_state
 * (both created by R0003, which stays byte-identical). Rollback restores the R0003 definitions and is
 * refused while any activation or event uses a new value.
 */

$c = ' collate utf8mb4_general_ci';
$states = "'CREATED','VALIDATED','FUNDS_RESERVED','PROVISIONING','PROVISIONED','VERIFYING','VERIFIED','FINANCIAL_COMMITTING','COMPLETED','FAILED','RETRY_PENDING','RECONCILIATION_REQUIRED','ROLLBACK_PENDING','ROLLED_BACK','CANCELLED'";
$extended = $states . ",'REVERSAL_PENDING','REVERSED'";
$guardActivations = "SELECT 1 FROM `tbl_reseller_activations` WHERE `status` IN ('REVERSAL_PENDING','REVERSED') LIMIT 1";
$guardEvents = "SELECT 1 FROM `tbl_reseller_activation_events` WHERE `from_state` IN ('REVERSAL_PENDING','REVERSED') OR `to_state` IN ('REVERSAL_PENDING','REVERSED') LIMIT 1";

return [
    'id' => 'R0007_activation_reversal_states',
    'description' => 'Activation reversal states REVERSAL_PENDING / REVERSED (ENUM extension of R0003 state columns only)',
    'requires' => 'R0006_reseller_audit',
    'up' => [
        [
            'id' => 'extend_tbl_reseller_activations_status',
            'sql' => "ALTER TABLE `tbl_reseller_activations` MODIFY `status` ENUM({$extended}) NOT NULL",
        ],
        [
            'id' => 'extend_tbl_reseller_activation_events_states',
            'sql' => "ALTER TABLE `tbl_reseller_activation_events` MODIFY `from_state` ENUM({$extended}) NULL COMMENT 'NULL only for the initial event into CREATED', "
                . "MODIFY `to_state` ENUM({$extended}) NOT NULL COMMENT 'state after this event'",
        ],
    ],
    'down' => [
        [
            'id' => 'restore_tbl_reseller_activation_events_states',
            'sql' => "ALTER TABLE `tbl_reseller_activation_events` MODIFY `from_state` ENUM({$states}) NULL COMMENT 'NULL only for the initial event into CREATED', "
                . "MODIFY `to_state` ENUM({$states}) NOT NULL COMMENT 'state after this event'",
            'if_exists' => ['column_definition' => ['tbl_reseller_activation_events', 'to_state', "enum({$extended}){$c} not null"]],
        ],
        [
            'id' => 'restore_tbl_reseller_activations_status',
            'sql' => "ALTER TABLE `tbl_reseller_activations` MODIFY `status` ENUM({$states}) NOT NULL",
            'if_exists' => ['column_definition' => ['tbl_reseller_activations', 'status', "enum({$extended}){$c} not null"]],
        ],
    ],
    'contract' => [
        'tables' => [],
        'table_parts' => [],
        'redefines' => [
            'tbl_reseller_activations' => [
                'status' => ['from' => "enum({$states}){$c} not null", 'to' => "enum({$extended}){$c} not null", 'data_guard' => $guardActivations],
            ],
            'tbl_reseller_activation_events' => [
                'from_state' => ['from' => "enum({$states}){$c} null", 'to' => "enum({$extended}){$c} null", 'data_guard' => $guardEvents],
                'to_state' => ['from' => "enum({$states}){$c} not null", 'to' => "enum({$extended}){$c} not null", 'data_guard' => $guardEvents],
            ],
        ],
    ],
];
