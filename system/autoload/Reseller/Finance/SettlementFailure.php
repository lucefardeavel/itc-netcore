<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Security\ErrorReporter;

/**
 * Typed failure of an activation financial settlement (WP3B owner decision F-6). A commit runs after
 * provisioning, and state can change between reserve and commit (e.g. a recipient wallet is funded
 * towards the money-range limit), so the settlement itself can still fail. When it does, the whole
 * settlement unit is rolled back by the transaction: no partial debit, no partial commission, no lost
 * commission and no silent free completion (effects = NONE once the transaction is rolled back). The
 * reservation stays ACTIVE.
 *
 * kind (for WP5A reconciliation, which owns FINANCIAL_COMMITTING -> COMPLETED / RECONCILIATION_REQUIRED):
 *   TRANSIENT  concurrency / lock timeout / connection loss: retry the same command
 *   CAPACITY   a resulting balance is outside the money range / leaf floor: needs an administrative fix
 *   INTEGRITY  the activation snapshot, wallets or ledger state are inconsistent: needs investigation
 *   UNKNOWN    anything else
 *
 * Preconditions detected before the settlement unit (NOT_FOUND, RESERVATION_NOT_ACTIVE, SCHEMA_NOT_READY,
 * INVALID_ACTOR) stay plain DomainError: nothing was attempted. Inside ReservationService::commitInTx,
 * deadlock (1213) and lock-wait timeout (1205) are never wrapped, so the accepted whole-unit retry of
 * FinanceTx::run keeps working; commit() classifies the final outcome. The original message is kept
 * (sanitized) and the original exception is chained.
 */
final class SettlementFailure extends \RuntimeException
{
    public const TRANSIENT = 'TRANSIENT';
    public const CAPACITY = 'CAPACITY';
    public const INTEGRITY = 'INTEGRITY';
    public const UNKNOWN = 'UNKNOWN';
    public const EFFECTS_NONE = 'NONE';

    private const CAPACITY_REASONS = ['STORAGE_RANGE_EXCEEDED', 'MONEY_OVERFLOW', 'COMMISSION_RECIPIENT_CAPACITY', 'INSUFFICIENT_AVAILABLE_CREDIT'];
    private const INTEGRITY_REASONS = ['PRICING_SNAPSHOT_INVALID', 'LEDGER_STATE_MISMATCH', 'COMMISSION_RECIPIENT_NO_WALLET', 'ACTIVATION_MISMATCH'];
    private const TRANSIENT_REASONS = ['CONCURRENCY_RETRY', 'LOCK_TIMEOUT'];
    /** 1213 deadlock, 1205 lock wait timeout, 2006 server gone away, 2013 lost connection */
    private const TRANSIENT_DRIVER = [1213, 1205, 2006, 2013];

    public function __construct(
        public readonly string $kind,
        public readonly string $reason,
        public readonly int $reservationId,
        public readonly ?int $activationId,
        string $message,
        ?\Throwable $previous = null,
        public readonly string $effects = self::EFFECTS_NONE,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function wrap(\Throwable $e, int $reservationId, ?int $activationId): self
    {
        if ($e instanceof self) {
            return $e;
        }
        return new self(self::classify($e), self::reasonOf($e), $reservationId, $activationId, ErrorReporter::sanitize($e->getMessage()), $e);
    }

    /** Classification of any Throwable raised by a settlement (also used by WP5A for commitInTx). */
    public static function classify(\Throwable $e): string
    {
        if ($e instanceof self) {
            return $e->kind;
        }
        if ($e instanceof DomainError) {
            return match (true) {
                in_array($e->reason, self::TRANSIENT_REASONS, true) => self::TRANSIENT,
                in_array($e->reason, self::CAPACITY_REASONS, true) => self::CAPACITY,
                in_array($e->reason, self::INTEGRITY_REASONS, true) => self::INTEGRITY,
                default => self::UNKNOWN,
            };
        }
        if ($e instanceof \PDOException) {
            if (in_array(FinanceTx::driverCode($e), self::TRANSIENT_DRIVER, true)) {
                return self::TRANSIENT;
            }
            $state = is_array($e->errorInfo) && isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : (string) $e->getCode();
            return $state === '23000' ? self::INTEGRITY : self::UNKNOWN;
        }
        if ($e instanceof \LogicException || $e instanceof \UnexpectedValueException) {
            return self::INTEGRITY;
        }
        return self::UNKNOWN;
    }

    private static function reasonOf(\Throwable $e): string
    {
        if ($e instanceof DomainError) {
            return $e->reason;
        }
        if ($e instanceof \PDOException) {
            $code = FinanceTx::driverCode($e);
            return $code !== null ? 'DRIVER_' . $code : 'SQLSTATE_' . preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $e->getCode()));
        }
        return 'UNEXPECTED';
    }
}
