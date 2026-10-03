<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Failure-injection points of the activation orchestration (WP5A failure tests). A no-op unless a test
 * assigns $hook; runtime code never assigns it (enforced by a source guard). Points:
 *  - activation.after_open          the opening transaction (sealed INSERT + reservation) before COMMIT
 *  - activation.before_execute     after the opening transaction committed (crash = FUNDS_RESERVED left)
 *  - activation.before_provision    PROVISIONING persisted, before the RADIUS call (crash = in flight)
 *  - activation.after_provision     RADIUS applied, before the outcome is persisted (crash = in flight)
 *  - activation.before_commit       FINANCIAL_COMMITTING persisted, before the settlement transaction
 *  - activation.after_settlement    inside the settlement transaction, after the debit and the legacy rows
 *  - reversal.after_start            REVERSAL_PENDING committed, before the RADIUS restore (WP5B)
 *  - reversal.after_restore          RADIUS restored, before the evidence is persisted
 *  - reversal.after_evidence         evidence persisted, before the financial / legacy transaction
 *  - reversal.after_ledger           inside the reversal transaction, after the ledger and legacy writes
 */
final class ActivationFaults
{
    /** @var (\Closure(string):void)|null */
    public static ?\Closure $hook = null;

    public static function hit(string $point): void
    {
        if (self::$hook !== null) {
            (self::$hook)($point);
        }
    }
}
