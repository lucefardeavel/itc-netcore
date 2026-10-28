<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\CustomerIdentity;
use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationService;
use Reseller\Radius\AccountingService;
use Reseller\Radius\SessionTermination;
use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusPolicy;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\DataScope;
use Reseller\Security\PermissionService;

/**
 * Authoritative reseller activation (WP5A; D016, D017; owner decisions C14, A1-A8). The ONLY path that grants
 * service to a reseller-owned customer: NEW, RENEW, CHANGE_PLAN and REACTIVATE through the persistent state
 * machine, the WP3B pricing snapshot, the WP3A reservation / settlement and the WP4 RADIUS provisioning.
 *
 *  1. Open (one tree-locked transaction, WP3B §9): idempotency probe -> actor revalidation -> scope and
 *     eligibility -> in-flight guard -> server-derived action + C14 period -> resolveInTx -> the single sealed
 *     INSERT -> CREATED, VALIDATED (period frozen) -> reserveInTx -> FUNDS_RESERVED. Any refusal writes nothing.
 *  2. Provision (no transaction around the RADIUS call; PROVISIONING persisted first) -> PROVISIONED ->
 *     VERIFYING -> VERIFIED, or RETRY_PENDING (identical retry, bounded) / FAILED (reservation released) /
 *     RECONCILIATION_REQUIRED (UNKNOWN: reservation kept, never retried blindly).
 *  3. Commit (commitInTx first) + legacy bridge rows + COMPLETED in one transaction; a settlement failure
 *     leaves the provisioned service with its reservation ACTIVE (RECONCILIATION_REQUIRED): never free.
 *
 * Replays (same idempotency key and request) return the same activation and never execute a step twice.
 */
final class ActivationService
{
    public const ACTIONS = ['NEW', 'RENEW', 'CHANGE_PLAN', 'REACTIVATE'];
    public const PLAN_TYPES = ['Hotspot', 'PPPOE'];
    /** identical re-attempts after a confirmed, retryable provisioning failure */
    public const PROVISION_RETRIES = 2;
    private const NONCE = '/^[A-Za-z0-9_-]{16,64}$/D';

    /**
     * Creates (or replays) an activation and drives it as far as is safe in this request.
     *
     * WP6A (owner decision OD-B, preview staleness): a $preview binding carries the fingerprint of the quote the
     * operator confirmed (ActivationPreviewService). The material quote is re-derived inside the open transaction,
     * under the tree lock, right after the authoritative pricing resolution; if it differs the request is refused
     * with ACTIVATION_PREVIEW_CHANGED before the activation row, the reservation, provisioning or any legacy write.
     * A replay of an existing idempotency key returns that activation (the binding is not re-checked).
     *
     * @return array{reference:string,status:string,action:string,replayed:bool,error_code:?string,error_message:?string}
     */
    public static function create(ActorContext $a, mixed $customerId, mixed $planId, mixed $expectedAction, mixed $nonce, ?PreviewBinding $preview = null): array
    {
        self::assertEligible($a);
        if (!DataScope::validId($customerId) || !DataScope::validId($planId)) {
            throw new DomainError('NOT_FOUND');
        }
        if (!is_string($expectedAction) || !in_array($expectedAction, self::ACTIONS, true)) {
            throw new DomainError('INVALID_INPUT');
        }
        if (!is_string($nonce) || !preg_match(self::NONCE, $nonce)) {
            throw new DomainError('INVALID_IDEMPOTENCY_KEY');
        }
        $actor = ActivationActor::staff($a);
        $key = 'staff:' . (int) $a->userId . ':' . $nonce;
        $opened = FinanceTx::run(
            static fn(\PDO $pdo): array => self::open($pdo, $a, (int) $customerId, (int) $planId, $expectedAction, $key, $preview),
            true
        );
        [$id, $replayed] = $opened;
        if (($opened[2] ?? true) === false) {
            // P1: authority lost since the original request: status only, no progression
            return self::outcome(ActivationStore::find(FinanceTx::pdo(), $id) ?? throw new DomainError('NOT_FOUND'), true);
        }
        return self::outcome(self::execute($id, $actor), $replayed);
    }

    /**
     * Read-only preview for the confirmation form: the server-derived action and service period. Not
     * authoritative (create() re-derives everything inside its transaction and refuses a changed action).
     *
     * @return array{action:string,start:string,expiry:string,plan:string,customer:string}
     */
    /**
     * True when this staff actor already submitted this nonce (expert review 2026-10-26): the controller then leaves the
     * answer to the idempotency path of create() instead of re-checking form fields. Read only.
     */
    public static function isReplay(ActorContext $a, mixed $nonce): bool
    {
        if (!is_string($nonce) || !preg_match(self::NONCE, $nonce)) {
            return false;
        }
        return ActivationStore::byKey(FinanceTx::pdo(), 'staff:' . (int) $a->userId . ':' . $nonce) !== null;
    }

    public static function preview(ActorContext $a, mixed $customerId, mixed $planId): array
    {
        self::assertEligible($a);
        if (!DataScope::validId($customerId) || !DataScope::validId($planId)) {
            throw new DomainError('NOT_FOUND');
        }
        $pdo = FinanceTx::pdo();
        $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [(int) $customerId]) ?? throw new DomainError('NOT_FOUND');
        $manager = self::authorizeCustomer($a, $customer);
        $plan = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [(int) $planId]) ?? throw new DomainError('NOT_FOUND');
        // only a plan of the actor's catalog is previewed; Agent / Sales never learn why another plan is not offered
        $reason = (string) $plan['enabled'] !== '1' ? 'PLAN_DISABLED'
            : ($plan['device'] !== 'Radius' || !in_array($plan['type'], self::PLAN_TYPES, true) ? 'PLAN_DEVICE_NOT_SUPPORTED' : self::unsaleable($manager, (int) $planId));
        if ($reason !== null) {
            throw new DomainError(PermissionService::scopeOf($a, C::ACTIVATION_CREATE) === 'G' ? $reason : 'NOT_FOUND');
        }
        $now = self::now();
        $current = LegacyBridge::currentRecharge($pdo, (int) $customerId, (string) $plan['type']);
        $action = self::deriveAction($current, (int) $planId, $now);
        [$start, $expiry] = ServicePeriod::compute($action, $plan, $action === 'RENEW' ? $current : null, $now, self::dayExp($pdo, (int) $customerId, $plan));
        return ['action' => $action, 'start' => $start->format('Y-m-d H:i:s'), 'expiry' => $expiry->format('Y-m-d H:i:s'),
            'plan' => (string) $plan['name_plan'], 'customer' => (string) $customer['username']];
    }

    /**
     * Activation actor eligibility (owner fix round 1). Admin / SuperAdmin keep the approved global behaviour;
     * an Agent must be Active, enrolled, consistent, not frozen and its own reseller identity; a Sales account
     * must be Active, enrolled, consistent, not frozen and placed under an Agent. The central PermissionService
     * applies the same placement rule (Capability::PLACEMENT_REQUIRED), and open() revalidates the actor in
     * the database inside the transaction.
     */
    public static function eligible(ActorContext $a): bool
    {
        if (!$a->isStaff() || !PermissionService::can($a, C::ACTIVATION_CREATE)) {
            return false;
        }
        $placed = $a->status === 'Active' && $a->enrolled && !$a->inconsistent && !$a->frozen;
        return match ($a->role) {
            'SuperAdmin', 'Admin' => true,
            'Agent' => $placed && $a->managerId === (int) $a->userId,
            'Sales' => $placed && $a->managerId !== null && $a->managerId !== (int) $a->userId,
            default => false,
        };
    }

    /** Schema readiness + capability (ResellerTreeService::authorize) + eligibility, before any data access. */
    private static function assertEligible(ActorContext $a): void
    {
        ResellerTreeService::authorize($a, C::ACTIVATION_CREATE);
        if (!self::eligible($a)) {
            throw new DomainError('FORBIDDEN');
        }
    }

    /**
     * The plans the actor may activate in this context (owner fix round 1): id, name and type only; no price,
     * cost, chain, commission or settlement data.
     *  - Admin / SuperAdmin: the administrative list of enabled RADIUS Hotspot / PPPoE plans (unchanged);
     *  - Agent: the plans saleable for its own reseller identity (PricingService resolution);
     *  - Sales: the plans saleable for the authoritative owner of the selected customer, which must be in its
     *    scope (no customer: no plans). The owner is read from the customer row, never from the request.
     *
     * @return list<array{id:int,name_plan:string,type:string}>
     */
    public static function catalog(ActorContext $a, mixed $customerId = null): array
    {
        self::assertEligible($a);
        $pdo = FinanceTx::pdo();
        $plans = array_map(static fn(array $p): array => ['id' => (int) $p['id'], 'name_plan' => (string) $p['name_plan'], 'type' => (string) $p['type']],
            Tx::rows($pdo, "SELECT id, name_plan, type FROM tbl_plans WHERE device = 'Radius' AND enabled = 1 AND type IN ('Hotspot', 'PPPOE') ORDER BY name_plan, id"));
        $scope = PermissionService::scopeOf($a, C::ACTIVATION_CREATE);
        if ($scope === 'G') {
            return $plans;
        }
        if ($scope === 'S') {
            $owner = (int) $a->userId;
        } else {
            if ($customerId === null || $customerId === '') {
                return [];
            }
            if (!DataScope::validId($customerId)) {
                throw new DomainError('NOT_FOUND');
            }
            $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [(int) $customerId]) ?? throw new DomainError('NOT_FOUND');
            $owner = self::authorizeCustomer($a, $customer);
        }
        $ok = self::saleable($owner, array_column($plans, 'id'));
        return array_values(array_filter($plans, static fn(array $p): bool => isset($ok[$p['id']])));
    }

    /**
     * Authoritative saleability of plans for one reseller owner (PricingService::resolveInTx under the tree
     * lock; every fail-closed pricing reason excludes the plan). The snapshot is discarded.
     *
     * @param list<int> $planIds
     * @return array<int,true>
     */
    private static function saleable(int $owner, array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }
        return FinanceTx::run(static function (\PDO $pdo) use ($owner, $planIds): array {
            $ok = [];
            foreach ($planIds as $planId) {
                try {
                    PricingService::resolveInTx($pdo, $owner, (int) $planId);
                    $ok[(int) $planId] = true;
                } catch (DomainError) {
                }
            }
            return $ok;
        }, true);
    }

    /** The pricing reason a plan is not saleable for $owner, or null. */
    private static function unsaleable(int $owner, int $planId): ?string
    {
        return FinanceTx::run(static function (\PDO $pdo) use ($owner, $planId): ?string {
            try {
                PricingService::resolveInTx($pdo, $owner, $planId);
                return null;
            } catch (DomainError $e) {
                return $e->reason;
            }
        }, true);
    }

    /** @return array{0:int,1:bool} [activation id, replayed] */
    private static function open(\PDO $pdo, ActorContext $a, int $customerId, int $planId, string $expected, string $key, ?PreviewBinding $preview = null): array
    {
        $existing = ActivationStore::byKey($pdo, $key);
        if ($existing !== null) {
            if ((int) $existing['customer_id'] !== $customerId || (int) $existing['plan_id'] !== $planId
                || $existing['action'] !== $expected || (int) $existing['actor_user_id'] !== (int) $a->userId) {
                throw new DomainError('IDEMPOTENCY_CONFLICT');
            }
            // WP9-B / N-05 P1 (OD-W9-6 option B): a replay drives the activation forward only while the actor still
            // has authority over the customer; otherwise it reports the status and changes nothing (the reserved
            // operation stays for an administrator to reconcile or cancel; no automatic release)
            try {
                ResellerTreeService::revalidateActor($pdo, $a);
                $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [$customerId]) ?? throw new DomainError('NOT_FOUND');
                self::authorizeCustomer($a, $customer);
            } catch (DomainError) {
                return [(int) $existing['id'], true, false];
            }
            return [(int) $existing['id'], true];
        }
        ResellerTreeService::revalidateActor($pdo, $a);
        $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ? LOCK IN SHARE MODE', [$customerId]) ?? throw new DomainError('NOT_FOUND');
        $manager = self::authorizeCustomer($a, $customer);
        if ($customer['status'] !== 'Active') {
            throw new DomainError('CUSTOMER_INACTIVE');
        }
        $plan = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [$planId]) ?? throw new DomainError('NOT_FOUND');
        if ((string) $plan['enabled'] !== '1') {
            throw new DomainError('PLAN_DISABLED');
        }
        if ($plan['device'] !== 'Radius' || !in_array($plan['type'], self::PLAN_TYPES, true)) {
            throw new DomainError('PLAN_DEVICE_NOT_SUPPORTED');
        }
        if (ActivationStore::inFlight($pdo, $customerId) !== null) {
            throw new DomainError('ACTIVATION_IN_PROGRESS');
        }
        $now = self::now();
        $current = LegacyBridge::currentRecharge($pdo, $customerId, (string) $plan['type']);
        $action = self::deriveAction($current, $planId, $now);
        if ($action !== $expected) {
            throw new DomainError('ACTION_MISMATCH');
        }
        self::assertOwnRadiusKey($pdo, $customer, (string) $plan['type']);
        [$start, $expiry] = ServicePeriod::compute($action, $plan, $action === 'RENEW' ? $current : null, $now, self::dayExp($pdo, $customerId, $plan));
        $snap = PricingService::resolveInTx($pdo, $manager, $planId);
        if ($preview !== null && !$preview->matches(ActivationPreviewService::material($customerId, $planId, $manager, $action, $current, $snap, self::dayExp($pdo, $customerId, $plan), ActivationPreviewService::serviceTerms($pdo, $snap)))) {
            throw new DomainError('ACTIVATION_PREVIEW_CHANGED'); // WP6A OD-B: nothing written (no row, reservation or provisioning)
        }
        $utcNow = Tx::utcNow();
        $id = ActivationStore::insert($pdo, [
            'reference' => self::reference(), 'idempotency_key' => $key, 'action' => $action, 'manager_id' => $manager,
            'actor_type' => 'STAFF', 'actor_user_id' => (int) $a->userId, 'customer_id' => $customerId, 'plan_id' => $planId,
            'previous_plan_id' => $action === 'CHANGE_PLAN' ? (int) $current['plan_id'] : null,
        ], $snap->activationColumns(), $utcNow);
        $actor = ActivationActor::staff($a);
        ActivationStore::event($pdo, $id, null, ActivationState::CREATED, $actor, [], $utcNow);
        $utc = new \DateTimeZone('UTC');
        // Lock gate P3 / WP6A R-15: the bandwidth terms confirmed now are sealed with the period and verified before
        // provisioning (a later bandwidth edit never changes this activation's service)
        $sealedBw = self::bandwidthTerms(Tx::row($pdo, 'SELECT * FROM tbl_bandwidth WHERE id = ?', [(int) $plan['id_bw']]));
        ActivationStore::transition($pdo, $id, [ActivationState::CREATED], ActivationState::VALIDATED, $actor, ['metadata' => [
            'action' => $action, 'previous_recharge_id' => $current !== null ? (int) $current['id'] : null, 'bandwidth' => $sealedBw,
            'period' => [
                'timezone' => $now->getTimezone()->getName(),
                'start' => $start->format('Y-m-d H:i:s'), 'expiry' => $expiry->format('Y-m-d H:i:s'),
                'start_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'expiry_utc' => $expiry->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
        ]]);
        $reservation = ReservationService::reserveInTx($pdo, $id, $manager, $snap->leafDebit);
        ActivationStore::transition($pdo, $id, [ActivationState::VALIDATED], ActivationState::FUNDS_RESERVED, $actor, [
            'event_type' => 'FINANCIAL', 'metadata' => ['reservation_id' => $reservation->reservationId, 'amount' => $reservation->amount->format()],
        ]);
        ActivationFaults::hit('activation.after_open');
        return [$id, false];
    }

    /**
     * Drives an activation from a safe state (FUNDS_RESERVED, RETRY_PENDING, VERIFIED) as far as possible;
     * any other state is returned as it is (terminal, or waiting for reconciliation, or owned by a
     * concurrent request). Never repeats a step whose outcome is unknown.
     *
     * @return array<string,mixed> the activation row
     */
    public static function execute(int $id, ActivationActor $actor): array
    {
        ActivationFaults::hit('activation.before_execute');
        // WP9-B / R-4: provisioning, verification and settlement of a customer's service period run under the
        // per-customer service lock shared with the cron expiry (ServiceLock, outermost). A busy lock makes no
        // progress and changes nothing: the activation keeps its state and is resumed later.
        $pdo = FinanceTx::pdo();
        $row = ActivationStore::find($pdo, $id) ?? throw new DomainError('NOT_FOUND');
        $steps = static function () use ($pdo, $id, $actor): array {
            for ($guard = 0; $guard < 10; $guard++) {
                $row = ActivationStore::find($pdo, $id) ?? throw new DomainError('NOT_FOUND');
                try {
                    switch ($row['status']) {
                        case ActivationState::FUNDS_RESERVED:
                        case ActivationState::RETRY_PENDING:
                            if (!self::provisionStep($row, $actor)) {
                                return ActivationStore::find($pdo, $id) ?? $row;
                            }
                            continue 2;
                        case ActivationState::VERIFIED:
                            self::commitStep($row, $actor);
                            continue 2;
                        default:
                            return $row;
                    }
                } catch (DomainError $e) {
                    if ($e->reason !== 'ACTIVATION_STATE_CONFLICT') {
                        throw $e;
                    }
                    // a concurrent request owns this step: report the state it reached
                    return ActivationStore::find($pdo, $id) ?? $row;
                }
            }
            return ActivationStore::find($pdo, $id) ?? throw new DomainError('NOT_FOUND');
        };
        try {
            return \Reseller\Domain\ServiceLock::hold((int) $row['customer_id'], $steps);
        } catch (DomainError $e) {
            if ($e->reason !== 'SERVICE_BUSY') {
                throw $e;
            }
            return ActivationStore::find($pdo, $id) ?? $row;
        }
    }

    /** @return bool false when provisioning could not start (the customer is not Active, or the prior RADIUS state could not be captured) */
    private static function provisionStep(array $row, ActivationActor $actor): bool
    {
        $id = (int) $row['id'];
        $attempts = count(array_filter(ActivationStore::events(FinanceTx::pdo(), $id), static fn(array $e): bool => $e['to_state'] === ActivationState::PROVISIONING));
        if ($row['status'] === ActivationState::RETRY_PENDING && $attempts > self::PROVISION_RETRIES) {
            self::fail($id, [ActivationState::RETRY_PENDING], $actor, 'PROVISION_RETRIES_EXHAUSTED', 'The RADIUS change could not be applied.', 'STATE_TRANSITION', null);
            return true;
        }
        // lab F6-3: an activation accepted while the customer was Active is not provisioned once the customer is no longer
        // Active (operator resume, or the replay of the same request, after a suspension). Nothing changes: the funds stay
        // reserved for the operator to cancel (reconcile.php cancel) or to resume after the customer is reactivated.
        $customerStatus = Tx::row(FinanceTx::pdo(), 'SELECT status FROM tbl_customers WHERE id = ?', [(int) $row['customer_id']])['status'] ?? null;
        if ($customerStatus !== 'Active') {
            $status = (string) $row['status'];
            // expert review 2026-10-26: noted once; a repeated resume / replay changes nothing (no updated_at bump, so the
            // stale-only web Cancel stays available for the reserved funds)
            if ((string) ($row['error_code'] ?? '') === 'CUSTOMER_INACTIVE') {
                return false;
            }
            self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, $status, $actor,
                ['event_type' => 'ANNOTATION', 'error_code' => 'CUSTOMER_INACTIVE', 'error_message' => 'The customer is not Active; provisioning did not start.'],
                ['error_code' => 'CUSTOMER_INACTIVE', 'error_message' => 'The customer is not Active; provisioning did not start. Cancel, or resume after reactivation.']));
            return false;
        }
        // the prior and target RADIUS states are captured BEFORE any write, durably (owner fix round 1)
        $capture = self::capture($row);
        if ($capture === false) {
            $status = (string) $row['status'];
            self::tx(static fn(\PDO $pdo) => ActivationStore::note($pdo, $id, $status, $actor,
                ['event_type' => 'ANNOTATION', 'error_code' => 'RADIUS_READ_FAILED', 'error_message' => 'The RADIUS state could not be read; provisioning did not start.'],
                ['error_code' => 'RADIUS_READ_FAILED', 'error_message' => 'The RADIUS state could not be read; provisioning did not start. Resume or cancel.']));
            return false;
        }
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::FUNDS_RESERVED, ActivationState::RETRY_PENDING], ActivationState::PROVISIONING, $actor,
            $capture === null ? [] : ['metadata' => $capture]));
        ActivationFaults::hit('activation.before_provision');
        [$result, $policy] = self::provision($row);
        ActivationFaults::hit('activation.after_provision');
        $event = ['event_type' => 'PROVISIONING_RESULT', 'provisioning_result' => $result->status, 'error_code' => $result->isSuccess() ? null : $result->code,
            'error_message' => $result->isSuccess() ? null : $result->message, 'metadata' => ['code' => $result->code, 'retryable' => $result->retryable]];
        if ($result->status === ProvisionResult::SUCCESS) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONING], ActivationState::PROVISIONED, $actor, $event));
            self::verifyStep($row, $policy, $actor);
            return true;
        }
        if ($result->status === ProvisionResult::FAILED && $result->retryable) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONING], ActivationState::RETRY_PENDING, $actor, $event));
            return true;
        }
        if ($result->status === ProvisionResult::FAILED) {
            self::fail($id, [ActivationState::PROVISIONING], $actor, $result->code, $result->message, 'PROVISIONING_RESULT', ProvisionResult::FAILED);
            return true;
        }
        // UNKNOWN: the change may have been applied; the reservation stays ACTIVE until reconciliation
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONING], ActivationState::RECONCILIATION_REQUIRED, $actor,
            $event, ['error_code' => $result->code, 'error_message' => $result->message]));
        return true;
    }

    /**
     * The RADIUS states for the PROVISIONING event: the prior state (first attempt only; what a rollback
     * restores and what proves NOT_APPLIED) and the exact target of this attempt. null when no policy can be
     * built (the attempt then fails before any write); false when the current state cannot be read.
     *
     * @return array{radius:array{prior?:array,target:array}}|null|false
     */
    private static function capture(array $row): array|null|false
    {
        try {
            // N2: the first attempt of an activation opens a NEW period (table end); a later attempt keeps the boundary its earlier
            // attempt recorded, so one activation always owns the same rows
            $recorded = self::recordedBoundary($row);
            $policy = self::policy($row, $recorded !== null ? static fn(string $user, int $timeBoundary): ?int => $recorded['id'] : null);
        } catch (\InvalidArgumentException) {
            return null;
        } catch (\PDOException $e) {
            // N2: the period boundary is read from the RADIUS database; unreadable = the attempt does not start (resumable)
            \Reseller\Security\ErrorReporter::report($e, 'activation-capture');
            return false;
        }
        $plan = self::plan($row);
        $bw = Tx::row(FinanceTx::pdo(), 'SELECT * FROM tbl_bandwidth WHERE id = ?', [(int) $plan['id_bw']]);
        $salt = (string) $row['reference'];
        $radius = ['target' => RadiusState::target($policy, $bw, $salt)];
        if (ActivationStore::radius(FinanceTx::pdo(), (int) $row['id'])['prior'] === null) {
            try {
                $radius = ['prior' => RadiusState::read($policy->username, (int) $plan['id'], $salt)] + $radius;
            } catch (\Throwable $e) {
                \Reseller\Security\ErrorReporter::report($e, 'activation-capture');
                return false;
            }
        }
        return ['radius' => $radius];
    }

    private static function verifyStep(array $row, ?RadiusPolicy $policy, ActivationActor $actor): void
    {
        $id = (int) $row['id'];
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONED], ActivationState::VERIFYING, $actor));
        $ok = false;
        try {
            // the whole captured target (subscriber and plan group rows), not only the activation-specific facts
            $target = ActivationStore::radius(FinanceTx::pdo(), $id)['target'];
            $ok = $policy !== null && $target !== null
                && RadiusState::same(RadiusState::read($policy->username, (int) self::plan($row)['id'], (string) $row['reference']), $target);
        } catch (\Throwable $e) {
            \Reseller\Security\ErrorReporter::report($e, 'activation-verify');
        }
        if (!$ok) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::VERIFYING], ActivationState::RECONCILIATION_REQUIRED, $actor,
                ['event_type' => 'VERIFICATION_RESULT', 'provisioning_result' => ProvisionResult::UNKNOWN, 'error_code' => 'VERIFY_MISMATCH'],
                ['error_code' => 'VERIFY_MISMATCH', 'error_message' => 'The RADIUS state could not be confirmed.']));
            return;
        }
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::VERIFYING], ActivationState::VERIFIED, $actor,
            ['event_type' => 'VERIFICATION_RESULT', 'provisioning_result' => ProvisionResult::SUCCESS]));
        // V1 / owner RENEWAL_OPTION_B_APPROVED: a renewal ends the old period's session too (CHANGE_PLAN / REACTIVATE
        // already did), and the end is CONFIRMED (SessionTermination), never assumed from a Disconnect-ACK
        if (in_array($row['action'], ['CHANGE_PLAN', 'REACTIVATE', 'RENEW'], true)) {
            self::endSessions($id, $policy->username, $actor);
        }
    }

    /** Exactly-once settlement + legacy bridge + COMPLETED; any failure keeps the reservation ACTIVE. */
    public static function commitStep(array $row, ActivationActor $actor): void
    {
        $id = (int) $row['id'];
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::VERIFIED], ActivationState::FINANCIAL_COMMITTING, $actor));
        ActivationFaults::hit('activation.before_commit');
        try {
            FinanceTx::run(static function (\PDO $pdo) use ($id, $actor): void {
                $act = ActivationStore::find($pdo, $id) ?? throw new DomainError('NOT_FOUND');
                if ($act['status'] !== ActivationState::FINANCIAL_COMMITTING) {
                    throw new DomainError('ACTIVATION_STATE_CONFLICT');
                }
                $reservation = ActivationStore::reservation($pdo, $id) ?? throw new \LogicException('activation without a reservation');
                // the settlement is the first locking work of this transaction (WP3B §9.3)
                $settled = ReservationService::commitInTx($pdo, (int) $reservation['id'], $actor->finance());
                $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [(int) $act['customer_id']]) ?? throw new \LogicException('activation without its customer');
                $meta = ActivationStore::period($pdo, $id);
                $zone = new \DateTimeZone((string) $meta['period']['timezone']);
                $legacy = LegacyBridge::record($pdo, $act, $customer, self::plan($act),
                    ServicePeriod::at(substr($meta['period']['start'], 0, 10), substr($meta['period']['start'], 11), $zone),
                    ServicePeriod::at(substr($meta['period']['expiry'], 0, 10), substr($meta['period']['expiry'], 11), $zone),
                    $actor->userId);
                ActivationFaults::hit('activation.after_settlement');
                ActivationStore::transition($pdo, $id, [ActivationState::FINANCIAL_COMMITTING], ActivationState::COMPLETED, $actor, [
                    'event_type' => 'FINANCIAL',
                    'metadata' => ['reservation_id' => $settled->reservationId, 'ledger_entry_id' => $settled->committedLedgerEntryId,
                        'invoice' => $legacy['invoice'], 'recharge_id' => $legacy['recharge'], 'transaction_id' => $legacy['transaction'],
                        // WP5B: what a later reversal needs to undo exactly the legacy writes of this transaction
                        'reversal_context' => $legacy['context']],
                ], ['completed_at' => Tx::utcNow(), 'legacy_recharge_id' => $legacy['recharge'], 'legacy_transaction_id' => $legacy['transaction'],
                    'legacy_invoice' => $legacy['invoice'], 'error_code' => null, 'error_message' => null]);
            }, false);
        } catch (\Throwable $e) {
            if ($e instanceof DomainError && $e->reason === 'ACTIVATION_STATE_CONFLICT') {
                throw $e;
            }
            $ref = \Reseller\Security\ErrorReporter::report($e, 'activation-commit');
            // the WP3B classification of anything the settlement unit raised: TRANSIENT / CAPACITY / INTEGRITY / UNKNOWN
            $code = 'SETTLEMENT_' . \Reseller\Finance\SettlementFailure::classify($e);
            // provisioned, not settled: the reservation stays ACTIVE (never free service); reconciliation commits it
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::FINANCIAL_COMMITTING], ActivationState::RECONCILIATION_REQUIRED, $actor,
                ['event_type' => 'FINANCIAL', 'error_code' => $code, 'error_message' => 'The settlement did not complete (ref ' . $ref . ').'],
                ['error_code' => $code, 'error_message' => 'The settlement did not complete; the funds stay reserved.']));
        }
    }

    /**
     * Confirmed failure: reservation released (nothing was applied) and the activation FAILED, atomically.
     *
     * @param list<string> $from
     */
    public static function fail(int $id, array $from, ActivationActor $actor, string $code, string $message, string $eventType, ?string $result): void
    {
        self::tx(static function (\PDO $pdo) use ($id, $from, $actor, $code, $message, $eventType, $result): void {
            $reservation = ActivationStore::reservation($pdo, $id);
            if ($reservation !== null && $reservation['status'] === 'ACTIVE') {
                ReservationService::releaseInTx($pdo, (int) $reservation['id'], \Reseller\Finance\FinanceActor::system('wp5a-activation'), 'activation failed: ' . $code);
            }
            ActivationStore::transition($pdo, $id, $from, ActivationState::FAILED, $actor,
                ['event_type' => $eventType, 'provisioning_result' => $result, 'error_code' => $code, 'error_message' => $message],
                ['error_code' => substr($code, 0, 64), 'error_message' => $message]);
        });
    }

    /**
     * The activation's RADIUS policy (sealed plan, live customer and bandwidth, frozen period), applied
     * with its plan group. Never retried here.
     *
     * @return array{0:ProvisionResult,1:?RadiusPolicy}
     */
    public static function provision(array $act): array
    {
        try {
            // N2: capture() recorded this attempt's exact target (including the period's arrival-order boundary): the policy
            // written and the policy verified are that target, even when accounting rows arrived in between
            $recorded = self::recordedBoundary($act);
            $policy = self::policy($act, $recorded !== null ? static fn(string $user, int $timeBoundary): ?int => $recorded['id'] : null);
        } catch (\InvalidArgumentException $e) {
            return [ProvisionResult::failed($e->getMessage(), 'The customer data cannot be provisioned.', false), null];
        } catch (\PDOException $e) {
            \Reseller\Security\ErrorReporter::report($e, 'activation-provision');
            return [ProvisionResult::failed('RADIUS_DB_ERROR', 'The RADIUS database is unavailable; nothing was applied.', true), null];
        }
        // WP9 U0 final hardening (D-U0-R1): a key with more than one logical owner (grandfathered duplicate) is never
        // written - FAILED before any RADIUS change (WP5A: reservation released); a check that cannot run is retryable
        try {
            CustomerIdentity::assertSingleOwner($policy->username);
        } catch (DomainError $e) {
            return [$e->reason === 'IDENTITY_AMBIGUOUS'
                ? ProvisionResult::failed('RADIUS_IDENTITY_AMBIGUOUS', $e->getMessage(), false)
                : ProvisionResult::failed('RADIUS_IDENTITY_CHECK_UNAVAILABLE', $e->getMessage(), true), $policy];
        }
        $provisioner = RadiusProvisioner::connection();
        $plan = self::plan($act);
        $bw = Tx::row(FinanceTx::pdo(), 'SELECT * FROM tbl_bandwidth WHERE id = ?', [(int) $plan['id_bw']]);
        // Lock gate P3 / R-15: verify the sealed bandwidth terms BEFORE any RADIUS write (activations opened before WP9
        // carry no sealed terms and keep the WP6A behaviour)
        $meta = ActivationStore::period(FinanceTx::pdo(), (int) $act['id']);
        if (array_key_exists('bandwidth', $meta) && self::bandwidthTerms($bw) !== $meta['bandwidth']) {
            return [ProvisionResult::failed('BANDWIDTH_TERMS_CHANGED', 'The bandwidth of the plan changed after the confirmation; nothing was applied or charged. Review the activation again.', false), $policy];
        }
        $group = $provisioner->applyPlan((int) $plan['id'], $bw);
        if (!$group->isSuccess()) {
            return [$group, $policy];
        }
        return [$provisioner->provision($policy), $policy];
    }

    /**
     * N2: the arrival-order boundary (Nuxr-Period-Acct-Id) in the target the latest provisioning attempt captured.
     *
     * @return array{id:?int}|null null = no target captured yet (the boundary is then resolved against the table)
     */
    private static function recordedBoundary(array $act): ?array
    {
        $target = ActivationStore::radius(FinanceTx::pdo(), (int) $act['id'])['target'] ?? null;
        if (!is_array($target)) {
            return null;
        }
        foreach (is_array($target['subscriber']['check'] ?? null) ? $target['subscriber']['check'] : [] as $r) {
            if (is_array($r) && ($r[0] ?? '') === 'Nuxr-Period-Acct-Id') {
                return ['id' => preg_match('/^[0-9]{1,18}$/D', (string) ($r[2] ?? '')) === 1 ? (int) $r[2] : null];
            }
        }
        return ['id' => null];
    }

    /**
     * @param (\Closure(string,int):?int)|null $boundary N2: the period boundary resolver (default: against the accounting table)
     * @return RadiusPolicy the policy this activation must leave in RADIUS
     */
    public static function policy(array $act, ?\Closure $boundary = null): RadiusPolicy
    {
        $pdo = FinanceTx::pdo();
        $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [(int) $act['customer_id']]) ?? throw new \InvalidArgumentException('CUSTOMER_MISSING');
        $plan = self::plan($act);
        $bw = Tx::row($pdo, 'SELECT * FROM tbl_bandwidth WHERE id = ?', [(int) $plan['id_bw']]);
        $meta = ActivationStore::period($pdo, (int) $act['id']);
        $zone = new \DateTimeZone((string) $meta['period']['timezone']);
        $start = ServicePeriod::at(substr($meta['period']['start'], 0, 10), substr($meta['period']['start'], 11), $zone);
        $expiry = ServicePeriod::at(substr($meta['period']['expiry'], 0, 10), substr($meta['period']['expiry'], 11), $zone);
        return RadiusPolicyBuilder::build($customer, $plan, $bw, $expiry, $start, $boundary ?? \Reseller\Radius\PeriodBoundary::newPeriodForConnection());
    }

    /**
     * WP9 / W8-14 (same-key ADMIN_SYNC, lowest-risk option: forbid the configuration): a Hotspot and a PPPoE service of
     * one customer must not share one RADIUS key (the PPPoE key is the username when no PPPoE username is set) - two
     * plans writing one key make every later re-provision unconfirmable. Refused before anything is written while the
     * customer holds an active recharge of the other service type with the same (canonical) key.
     */
    private static function assertOwnRadiusKey(\PDO $pdo, array $customer, string $type): void
    {
        $other = $type === 'PPPOE' ? 'Hotspot' : 'PPPOE';
        $active = Tx::row($pdo, "SELECT id FROM tbl_user_recharges WHERE customer_id = ? AND type = ? AND status = 'on' LIMIT 1", [(int) $customer['id'], $other]);
        if ($active === null) {
            return;
        }
        try {
            $mine = RadiusPolicyBuilder::key($customer, $type === 'PPPOE');
            $theirs = RadiusPolicyBuilder::key($customer, $other === 'PPPOE');
        } catch (\InvalidArgumentException) {
            return; // an invalid key is refused by the policy build itself
        }
        if (mb_strtolower($mine) === mb_strtolower($theirs)) {
            throw new DomainError('RADIUS_KEY_SHARED');
        }
    }

    /**
     * The bandwidth terms RadiusPolicyBuilder applies (Mikrotik-Rate-Limit / Ascend rates), normalized for comparison.
     *
     * @param array<string,mixed>|null $bw
     * @return array<string,string>|null
     */
    public static function bandwidthTerms(?array $bw): ?array
    {
        if ($bw === null) {
            return null;
        }
        $out = [];
        foreach (['id', 'rate_down', 'rate_down_unit', 'rate_up', 'rate_up_unit', 'burst'] as $f) {
            $out[$f] = trim((string) ($bw[$f] ?? ''));
        }
        return $out;
    }

    /** The sealed plan (plan_snapshot) of the activation. */
    public static function plan(array $act): array
    {
        $plan = json_decode((string) $act['plan_snapshot'], true);
        if (!is_array($plan) || (int) ($plan['id'] ?? 0) !== (int) $act['plan_id']) {
            throw new \LogicException('activation without a sealed plan');
        }
        return $plan;
    }

    /** Server-derived action (owner decision A2) from the customer's current RADIUS recharge of the plan type. */
    public static function deriveAction(?array $current, int $planId, \DateTimeImmutable $now): string
    {
        if ($current === null) {
            return 'NEW';
        }
        if ((int) $current['plan_id'] !== $planId) {
            return 'CHANGE_PLAN';
        }
        $active = $current['status'] === 'on'
            && ServicePeriod::at((string) $current['expiration'], (string) $current['time'], $now->getTimezone()) > $now;
        return $active ? 'RENEW' : 'REACTIVATE';
    }

    /**
     * Activation status for a reader (activation.read): G any; T activations of the actor's subtree
     * (historical owner); A activations of customers assigned to the Sales account.
     *
     * @return array{activation:array<string,mixed>,events:list<array<string,mixed>>}
     */
    public static function status(ActorContext $a, mixed $reference): array
    {
        ResellerTreeService::authorize($a, C::ACTIVATION_READ);
        if (!is_string($reference) || !preg_match('/^ACT-[0-9A-F]{16}$/D', $reference)) {
            throw new DomainError('NOT_FOUND');
        }
        $pdo = FinanceTx::pdo();
        $act = ActivationStore::byReference($pdo, $reference) ?? throw new DomainError('NOT_FOUND');
        $scope = PermissionService::scopeOf($a, C::ACTIVATION_READ);
        $visible = match ($scope) {
            'G' => true,
            'T' => Tx::row($pdo, 'SELECT 1 AS x FROM tbl_manager_tree WHERE ancestor_id = ? AND descendant_id = ?', [(int) $a->userId, (int) $act['manager_id']]) !== null,
            // WP9-B / N-15 P5 (OD-W9-6 option B): a Sales account sees only the activations paid by the customer's
            // CURRENT owner (no history of a previous owner after an ownership transfer)
            'A' => DataScope::customerInScope($a, C::ACTIVATION_READ, (int) $act['customer_id'])
                && Tx::row($pdo, 'SELECT 1 AS x FROM tbl_customers WHERE id = ? AND manager_id = ?', [(int) $act['customer_id'], (int) $act['manager_id']]) !== null,
            default => false,
        };
        if (!$visible) {
            throw new DomainError('NOT_FOUND');
        }
        return ['activation' => $act, 'events' => ActivationStore::events($pdo, (int) $act['id'])];
    }

    /** @return array{reference:string,status:string,action:string,replayed:bool,error_code:?string,error_message:?string} */
    public static function outcome(array $row, bool $replayed): array
    {
        return ['reference' => (string) $row['reference'], 'status' => (string) $row['status'], 'action' => (string) $row['action'],
            'replayed' => $replayed, 'error_code' => $row['error_code'], 'error_message' => $row['error_message']];
    }

    /** WP6A: the paying owner manager of a customer in the actor's activation scope (authorizeCustomer, read-only callers). */
    public static function payerOf(ActorContext $a, array $customer): int
    {
        return self::authorizeCustomer($a, $customer);
    }

    /** WP6A: the legacy "Expired Date" day used by the service period (read-only callers). */
    public static function expiredDay(\PDO $pdo, int $customerId, array $plan): int
    {
        return self::dayExp($pdo, $customerId, $plan);
    }

    /** The owner manager whose wallet pays (A5): S = own customers, A = assigned customers, G = any reseller customer. */
    private static function authorizeCustomer(ActorContext $a, array $customer): int
    {
        $manager = $customer['manager_id'] === null ? null : (int) $customer['manager_id'];
        $scope = PermissionService::scopeOf($a, C::ACTIVATION_CREATE);
        $inScope = match ($scope) {
            'G' => true,
            'S' => $manager !== null && $manager === (int) $a->userId,
            'A' => $manager !== null && (int) $customer['assigned_sales_id'] === (int) $a->userId && $manager === (int) $a->managerId,
            default => false,
        };
        if (!$inScope) {
            throw new DomainError('NOT_FOUND');
        }
        if ($manager === null) {
            throw new DomainError('CUSTOMER_NOT_RESELLER_OWNED');
        }
        return $manager;
    }

    /** The legacy "Expired Date" day for Period plans (customer attribute, else the postpaid plan day, else 20). */
    private static function dayExp(\PDO $pdo, int $customerId, array $plan): int
    {
        if ($plan['validity_unit'] !== 'Period') {
            return 20;
        }
        $attr = Tx::row($pdo, "SELECT field_value FROM tbl_customers_fields WHERE field_name = 'Expired Date' AND customer_id = ?", [$customerId]);
        $day = $attr !== null ? (int) $attr['field_value'] : 0;
        if ($day < 1) {
            $day = $plan['prepaid'] === 'no' ? (int) $plan['expired_date'] : 20;
        }
        return $day >= 1 ? $day : 20;
    }

    /**
     * WP9 lock gate P1 / V1 Option B: operator re-send of the session end of a COMPLETED CHANGE_PLAN / REACTIVATE / RENEW
     * whose first end was not confirmed (DriftReport SESSION_POLICY_STALE). Operator-initiated only (never automatic);
     * only sessions that started before the activation's period are targeted; a session that already ended is answered
     * NAK 503 / not found and changes nothing. The outcome is annotated.
     *
     * @return list<string> per-session "<status>:<code>"
     */
    public static function endStaleSessions(string $reference, ActivationActor $actor): array
    {
        $pdo = FinanceTx::pdo();
        $act = ActivationStore::byReference($pdo, $reference) ?? throw new DomainError('NOT_FOUND');
        if ($act['status'] !== ActivationState::COMPLETED || !in_array($act['action'], ['CHANGE_PLAN', 'REACTIVATE', 'RENEW'], true)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $username = ActivationStore::radius($pdo, (int) $act['id'])['target']['username'] ?? throw new DomainError('ACTIVATION_STATE_CONFLICT');
        $outcome = self::endPeriodSessions((string) $username, self::periodStartEpoch($pdo, (int) $act['id']));
        self::tx(static function (\PDO $pdo) use ($act, $actor, $outcome): void {
            ActivationStore::lock($pdo, (int) $act['id']);
            ActivationStore::event($pdo, (int) $act['id'], ActivationState::COMPLETED, ActivationState::COMPLETED, $actor, ['event_type' => 'ANNOTATION', 'metadata' => $outcome + ['operator' => 'session-end']]);
        });
        return $outcome['disconnect'];
    }

    /**
     * Ends the sessions that may still run the previous policy so the new one applies (UNKNOWN never undoes provisioning
     * or finance). Called after the provisioning is verified: every session that started before NOW (not only before the
     * period start) authenticated under the previous policy - a re-login between the activation's start and the RADIUS
     * commit is ended too. "Before NOW" is judged on the NAS's own Accounting start stamp, so the cut-off carries the NAS
     * clock allowance of the period attribution (QuotaPolicy::PERIOD_SKEW_SECONDS, NTP contract H8): lab finding on a
     * RouterOS CHR whose clock ran ~1 s ahead, where a session that had just reconnected escaped the Disconnect while the
     * end was recorded as confirmed. Cost: a session that started within the allowance after the commit (already under the
     * new policy) is ended too and redials.
     */
    private static function endSessions(int $id, string $username, ActivationActor $actor): void
    {
        $outcome = self::endPeriodSessions($username, max(self::periodStartEpoch(FinanceTx::pdo(), $id), time()) + \Reseller\Radius\QuotaPolicy::PERIOD_SKEW_SECONDS);
        self::tx(static function (\PDO $pdo) use ($id, $actor, $outcome): void {
            ActivationStore::lock($pdo, $id);
            ActivationStore::event($pdo, $id, ActivationState::VERIFIED, ActivationState::VERIFIED, $actor, ['event_type' => 'ANNOTATION', 'metadata' => $outcome]);
        });
    }

    /**
     * The open sessions of $username that started before $periodStart get a Disconnect each, and their end is confirmed
     * (Accounting-Stop after the ACK, or NAK 503). Never throws.
     *
     * @return array{disconnect:list<string>,sessions:list<array<string,mixed>>,confirmed:bool}
     */
    private static function endPeriodSessions(string $username, int $periodStart): array
    {
        try {
            $rows = array_values(array_filter(AccountingService::connection()->openSessions($username),
                static fn(array $r): bool => $r['acctstart_epoch'] === null || (int) $r['acctstart_epoch'] < $periodStart));
        } catch (\Throwable $e) {
            \Reseller\Security\ErrorReporter::report($e, 'activation-disconnect');
            return ['disconnect' => ['ERROR'], 'sessions' => [], 'confirmed' => false];
        }
        $ended = SessionTermination::end($rows);
        return ['disconnect' => array_column($ended, 'result'), 'sessions' => $ended, 'confirmed' => !in_array(false, array_column($ended, 'confirmed'), true)];
    }

    /** the activation's service period start as a UNIX timestamp (now when unknown: every open session is from before) */
    private static function periodStartEpoch(\PDO $pdo, int $id): int
    {
        try {
            $meta = ActivationStore::period($pdo, $id)['period'] ?? null;
        } catch (\LogicException) {
            $meta = null;
        }
        if (!is_array($meta) || !isset($meta['start'], $meta['timezone'])) {
            return time();
        }
        return (new \DateTimeImmutable((string) $meta['start'], new \DateTimeZone((string) $meta['timezone'])))->getTimestamp();
    }

    /** The application time zone (legacy wall clock), now. */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    }

    private static function reference(): string
    {
        return 'ACT-' . strtoupper(bin2hex(random_bytes(8)));
    }

    /** A short strict transaction for a state step (no tree lock: no plan reference is created). */
    public static function tx(callable $fn): mixed
    {
        return FinanceTx::run(static fn(\PDO $pdo) => $fn($pdo), false);
    }
}
