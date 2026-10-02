<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Audit\AuditLog;
use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceActor;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\ReservationService;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;

/**
 * Reconciliation of activations whose outcome is not known (D017; owner decision A4). Only Admin /
 * SuperAdmin staff (activation.create scope G) or the system (the CLI system/reseller/reconcile.php) act here.
 * Nothing is automatic: there is no timeout release (WP3A), stale activations are only reported.
 *
 *  - reconcile: RADIUS is read back. The activation's policy is there -> VERIFIED -> the exactly-once
 *    settlement (COMPLETED); it is not there -> the reservation is released and the activation FAILED. A
 *    settlement-only failure is simply committed again (the ledger key makes it exactly once).
 *    In-flight states (PROVISIONING .. FINANCIAL_COMMITTING) are reconciled only when stale, so a request
 *    that is still running is never raced.
 *  - rollback: the previous RADIUS state is restored from the customer's (unchanged) recharge row, or the
 *    customer is suspended when there was none; then the reservation is released -> ROLLED_BACK.
 *  - cancel: before any provisioning (FUNDS_RESERVED / RETRY_PENDING) -> reservation released -> CANCELLED.
 */
final class ReconciliationService
{
    public const STALE_SECONDS = 300;
    private const IN_FLIGHT = [ActivationState::PROVISIONING, ActivationState::PROVISIONED, ActivationState::VERIFYING, ActivationState::FINANCIAL_COMMITTING];

    public static function staffActor(ActorContext $a): ActivationActor
    {
        if (PermissionService::scopeOf($a, C::ACTIVATION_CREATE) !== 'G') {
            throw new DomainError('FORBIDDEN');
        }
        return ActivationActor::staff($a);
    }

    /** @return array<string,mixed> the activation row after reconciliation */
    public static function reconcile(ActivationActor $actor, string $reference, int $staleSeconds = self::STALE_SECONDS): array
    {
        $act = self::find($reference);
        $id = (int) $act['id'];
        if (in_array($act['status'], self::IN_FLIGHT, true)) {
            if (self::age($act) < $staleSeconds) {
                throw new DomainError('ACTIVATION_IN_PROGRESS');
            }
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, self::IN_FLIGHT, ActivationState::RECONCILIATION_REQUIRED, $actor,
                ['event_type' => 'RECONCILIATION', 'error_code' => 'STALE_IN_FLIGHT'], ['error_code' => 'STALE_IN_FLIGHT', 'error_message' => 'The activation stopped while in flight.']));
            $act = self::find($reference);
        }
        if ($act['status'] !== ActivationState::RECONCILIATION_REQUIRED) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $policy = ActivationService::policy($act);
        try {
            $applied = ActivationService::applied(RadiusRepository::connection()->readUser($policy->username), $policy);
        } catch (\Throwable $e) {
            \Reseller\Security\ErrorReporter::report($e, 'activation-reconcile');
            self::tx(static function (\PDO $pdo) use ($id, $actor): void {
                ActivationStore::lock($pdo, $id);
                ActivationStore::event($pdo, $id, ActivationState::RECONCILIATION_REQUIRED, ActivationState::RECONCILIATION_REQUIRED, $actor,
                    ['event_type' => 'RECONCILIATION', 'error_code' => 'RADIUS_READ_FAILED']);
            });
            throw new DomainError('ACTIVATION_STATE_CONFLICT', 'The RADIUS state could not be read; nothing was changed.');
        }
        self::audit($actor, 'activation.reconcile', $act, ['applied' => $applied]);
        if ($applied) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::RECONCILIATION_REQUIRED], ActivationState::VERIFIED, $actor,
                ['event_type' => 'RECONCILIATION', 'provisioning_result' => 'SUCCESS', 'metadata' => ['applied' => true]], ['error_code' => null, 'error_message' => null]));
            return ActivationService::execute($id, $actor);
        }
        ActivationService::fail($id, [ActivationState::RECONCILIATION_REQUIRED], $actor, 'NOT_APPLIED', 'RADIUS does not show the activation; nothing was charged.', 'RECONCILIATION', 'FAILED');
        return self::find($reference);
    }

    /** @return array<string,mixed> */
    public static function rollback(ActivationActor $actor, string $reference): array
    {
        $act = self::find($reference);
        $id = (int) $act['id'];
        if (in_array($act['status'], [ActivationState::RECONCILIATION_REQUIRED, ActivationState::VERIFIED], true)) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::RECONCILIATION_REQUIRED, ActivationState::VERIFIED], ActivationState::ROLLBACK_PENDING, $actor,
                ['event_type' => 'RECONCILIATION', 'metadata' => ['rollback' => 'requested']]));
        } elseif ($act['status'] !== ActivationState::ROLLBACK_PENDING) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $act = self::find($reference);
        $result = self::restorePrevious($act);
        self::audit($actor, 'activation.rollback', $act, ['restore' => $result->status . ':' . $result->code]);
        if (!$result->isSuccess()) {
            self::tx(static function (\PDO $pdo) use ($id, $actor, $result): void {
                ActivationStore::lock($pdo, $id);
                ActivationStore::event($pdo, $id, ActivationState::ROLLBACK_PENDING, ActivationState::ROLLBACK_PENDING, $actor,
                    ['event_type' => 'RECONCILIATION', 'provisioning_result' => $result->status, 'error_code' => $result->code, 'error_message' => $result->message]);
            });
            throw new DomainError('ACTIVATION_STATE_CONFLICT', 'The previous RADIUS state could not be restored (' . $result->code . '); the rollback can be repeated.');
        }
        self::tx(static function (\PDO $pdo) use ($id, $actor): void {
            $reservation = ActivationStore::reservation($pdo, $id);
            if ($reservation !== null && $reservation['status'] === 'ACTIVE') {
                ReservationService::releaseInTx($pdo, (int) $reservation['id'], FinanceActor::system('wp5a-activation'), 'activation rolled back');
            }
            ActivationStore::transition($pdo, $id, [ActivationState::ROLLBACK_PENDING], ActivationState::ROLLED_BACK, $actor,
                ['event_type' => 'RECONCILIATION', 'provisioning_result' => 'SUCCESS', 'metadata' => ['rollback' => 'restored']],
                ['error_code' => 'ROLLED_BACK', 'error_message' => 'The activation was rolled back; nothing was charged.']);
        });
        return self::find($reference);
    }

    /** @return array<string,mixed> */
    public static function cancel(ActivationActor $actor, string $reference): array
    {
        $act = self::find($reference);
        if (!in_array($act['status'], [ActivationState::FUNDS_RESERVED, ActivationState::RETRY_PENDING], true)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $id = (int) $act['id'];
        self::tx(static function (\PDO $pdo) use ($id, $actor): void {
            $reservation = ActivationStore::reservation($pdo, $id);
            if ($reservation !== null && $reservation['status'] === 'ACTIVE') {
                ReservationService::releaseInTx($pdo, (int) $reservation['id'], FinanceActor::system('wp5a-activation'), 'activation cancelled');
            }
            ActivationStore::transition($pdo, $id, [ActivationState::FUNDS_RESERVED, ActivationState::RETRY_PENDING], ActivationState::CANCELLED, $actor,
                ['event_type' => 'RECONCILIATION', 'metadata' => ['cancel' => true]], ['error_code' => 'CANCELLED', 'error_message' => 'The activation was cancelled before provisioning.']);
        });
        self::audit($actor, 'activation.cancel', $act, []);
        return self::find($reference);
    }

    /**
     * Non-terminal activations not updated for $seconds: report only (no action).
     *
     * @return list<array{reference:string,status:string,customer_id:int,manager_id:int,updated_at:string}>
     */
    public static function stale(int $seconds = self::STALE_SECONDS): array
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . max(0, $seconds) . ' seconds')->format('Y-m-d H:i:s.u');
        $rows = Tx::rows(FinanceTx::pdo(),
            'SELECT reference, status, customer_id, manager_id, updated_at FROM tbl_reseller_activations WHERE status NOT IN ('
            . implode(', ', array_fill(0, count(ActivationState::TERMINAL), '?')) . ') AND updated_at <= ? ORDER BY updated_at',
            [...ActivationState::TERMINAL, $cutoff]);
        return array_map(static fn(array $r): array => ['reference' => (string) $r['reference'], 'status' => (string) $r['status'],
            'customer_id' => (int) $r['customer_id'], 'manager_id' => (int) $r['manager_id'], 'updated_at' => (string) $r['updated_at']], $rows);
    }

    /** The previous RADIUS state: the customer's current recharge row is untouched until COMPLETED. */
    private static function restorePrevious(array $act): \Reseller\Radius\ProvisionResult
    {
        $pdo = FinanceTx::pdo();
        $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [(int) $act['customer_id']]);
        $plan = ActivationService::plan($act);
        if ($customer === null) {
            return \Reseller\Radius\ProvisionResult::failed('CUSTOMER_MISSING', 'The customer does not exist.', false);
        }
        $provisioner = RadiusProvisioner::connection();
        $current = LegacyBridge::currentRecharge($pdo, (int) $act['customer_id'], (string) $plan['type']);
        $zone = new \DateTimeZone(date_default_timezone_get());
        $now = new \DateTimeImmutable('now', $zone);
        if ($current !== null && $current['status'] === 'on' && ServicePeriod::at((string) $current['expiration'], (string) $current['time'], $zone) > $now) {
            $prevPlan = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [(int) $current['plan_id']]);
            if ($prevPlan === null) {
                return \Reseller\Radius\ProvisionResult::failed('PREVIOUS_PLAN_MISSING', 'The previous plan does not exist.', false);
            }
            $bw = Tx::row($pdo, 'SELECT * FROM tbl_bandwidth WHERE id = ?', [(int) $prevPlan['id_bw']]);
            try {
                $policy = RadiusPolicyBuilder::build($customer, $prevPlan, $bw,
                    ServicePeriod::at((string) $current['expiration'], (string) $current['time'], $zone),
                    ServicePeriod::at((string) $current['recharged_on'], (string) $current['recharged_time'], $zone));
            } catch (\InvalidArgumentException $e) {
                return \Reseller\Radius\ProvisionResult::failed($e->getMessage(), 'The previous state cannot be provisioned.', false);
            }
            $group = $provisioner->applyPlan((int) $prevPlan['id'], $bw);
            return $group->isSuccess() ? $provisioner->provision($policy) : $group;
        }
        try {
            $key = RadiusPolicyBuilder::key($customer, $plan['type'] === 'PPPOE');
        } catch (\InvalidArgumentException $e) {
            return \Reseller\Radius\ProvisionResult::failed($e->getMessage(), 'The customer data cannot be provisioned.', false);
        }
        return $provisioner->suspend($key);
    }

    private static function find(string $reference): array
    {
        if (!preg_match('/^ACT-[0-9A-F]{16}$/D', $reference)) {
            throw new DomainError('NOT_FOUND');
        }
        return ActivationStore::byReference(FinanceTx::pdo(), $reference) ?? throw new DomainError('NOT_FOUND');
    }

    private static function age(array $act): int
    {
        $updated = new \DateTimeImmutable((string) $act['updated_at'], new \DateTimeZone('UTC'));
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $updated->getTimestamp();
    }

    private static function audit(ActivationActor $actor, string $event, array $act, array $after): void
    {
        if ($actor->context === null) {
            return;
        }
        AuditLog::record($event, $actor->context, [
            'target_type' => 'activation', 'target_user_id' => (int) $act['manager_id'],
            'target_snapshot' => ['reference' => $act['reference'], 'customer_id' => (int) $act['customer_id']],
            'before' => ['status' => $act['status']], 'after' => $after,
        ]);
    }

    private static function tx(callable $fn): mixed
    {
        return ActivationService::tx($fn);
    }
}
