<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\ResellerTreeService;
use Reseller\Domain\Tx;
use Reseller\Finance\FinanceTx;
use Reseller\Finance\PricingService;
use Reseller\Finance\ReservationService;
use Reseller\Radius\CoAClient;
use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusPolicy;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
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
     * @return array{reference:string,status:string,action:string,replayed:bool,error_code:?string,error_message:?string}
     */
    public static function create(ActorContext $a, mixed $customerId, mixed $planId, mixed $expectedAction, mixed $nonce): array
    {
        ResellerTreeService::authorize($a, C::ACTIVATION_CREATE);
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
        [$id, $replayed] = FinanceTx::run(
            static fn(\PDO $pdo): array => self::open($pdo, $a, (int) $customerId, (int) $planId, $expectedAction, $key),
            true
        );
        return self::outcome(self::execute($id, $actor), $replayed);
    }

    /**
     * Read-only preview for the confirmation form: the server-derived action and service period. Not
     * authoritative (create() re-derives everything inside its transaction and refuses a changed action).
     *
     * @return array{action:string,start:string,expiry:string,plan:string,customer:string}
     */
    public static function preview(ActorContext $a, mixed $customerId, mixed $planId): array
    {
        ResellerTreeService::authorize($a, C::ACTIVATION_CREATE);
        if (!DataScope::validId($customerId) || !DataScope::validId($planId)) {
            throw new DomainError('NOT_FOUND');
        }
        $pdo = FinanceTx::pdo();
        $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [(int) $customerId]) ?? throw new DomainError('NOT_FOUND');
        self::authorizeCustomer($a, $customer);
        $plan = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [(int) $planId]) ?? throw new DomainError('NOT_FOUND');
        if ($plan['device'] !== 'Radius' || !in_array($plan['type'], self::PLAN_TYPES, true)) {
            throw new DomainError('PLAN_DEVICE_NOT_SUPPORTED');
        }
        $now = self::now();
        $current = LegacyBridge::currentRecharge($pdo, (int) $customerId, (string) $plan['type']);
        $action = self::deriveAction($current, (int) $planId, $now);
        [$start, $expiry] = ServicePeriod::compute($action, $plan, $action === 'RENEW' ? $current : null, $now, self::dayExp($pdo, (int) $customerId, $plan));
        return ['action' => $action, 'start' => $start->format('Y-m-d H:i:s'), 'expiry' => $expiry->format('Y-m-d H:i:s'),
            'plan' => (string) $plan['name_plan'], 'customer' => (string) $customer['username']];
    }

    /** @return array{0:int,1:bool} [activation id, replayed] */
    private static function open(\PDO $pdo, ActorContext $a, int $customerId, int $planId, string $expected, string $key): array
    {
        $existing = ActivationStore::byKey($pdo, $key);
        if ($existing !== null) {
            if ((int) $existing['customer_id'] !== $customerId || (int) $existing['plan_id'] !== $planId
                || $existing['action'] !== $expected || (int) $existing['actor_user_id'] !== (int) $a->userId) {
                throw new DomainError('IDEMPOTENCY_CONFLICT');
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
        [$start, $expiry] = ServicePeriod::compute($action, $plan, $action === 'RENEW' ? $current : null, $now, self::dayExp($pdo, $customerId, $plan));
        $snap = PricingService::resolveInTx($pdo, $manager, $planId);
        $utcNow = Tx::utcNow();
        $id = ActivationStore::insert($pdo, [
            'reference' => self::reference(), 'idempotency_key' => $key, 'action' => $action, 'manager_id' => $manager,
            'actor_type' => 'STAFF', 'actor_user_id' => (int) $a->userId, 'customer_id' => $customerId, 'plan_id' => $planId,
            'previous_plan_id' => $action === 'CHANGE_PLAN' ? (int) $current['plan_id'] : null,
        ], $snap->activationColumns(), $utcNow);
        $actor = ActivationActor::staff($a);
        ActivationStore::event($pdo, $id, null, ActivationState::CREATED, $actor, [], $utcNow);
        $utc = new \DateTimeZone('UTC');
        ActivationStore::transition($pdo, $id, [ActivationState::CREATED], ActivationState::VALIDATED, $actor, ['metadata' => [
            'action' => $action, 'previous_recharge_id' => $current !== null ? (int) $current['id'] : null,
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
        $pdo = FinanceTx::pdo();
        for ($guard = 0; $guard < 10; $guard++) {
            $row = ActivationStore::find($pdo, $id) ?? throw new DomainError('NOT_FOUND');
            try {
                switch ($row['status']) {
                    case ActivationState::FUNDS_RESERVED:
                    case ActivationState::RETRY_PENDING:
                        self::provisionStep($row, $actor);
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
    }

    private static function provisionStep(array $row, ActivationActor $actor): void
    {
        $id = (int) $row['id'];
        $attempts = count(array_filter(ActivationStore::events(FinanceTx::pdo(), $id), static fn(array $e): bool => $e['to_state'] === ActivationState::PROVISIONING));
        if ($row['status'] === ActivationState::RETRY_PENDING && $attempts > self::PROVISION_RETRIES) {
            self::fail($id, [ActivationState::RETRY_PENDING], $actor, 'PROVISION_RETRIES_EXHAUSTED', 'The RADIUS change could not be applied.', 'STATE_TRANSITION', null);
            return;
        }
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::FUNDS_RESERVED, ActivationState::RETRY_PENDING], ActivationState::PROVISIONING, $actor));
        ActivationFaults::hit('activation.before_provision');
        [$result, $policy] = self::provision($row);
        ActivationFaults::hit('activation.after_provision');
        $event = ['event_type' => 'PROVISIONING_RESULT', 'provisioning_result' => $result->status, 'error_code' => $result->isSuccess() ? null : $result->code,
            'error_message' => $result->isSuccess() ? null : $result->message, 'metadata' => ['code' => $result->code, 'retryable' => $result->retryable]];
        if ($result->status === ProvisionResult::SUCCESS) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONING], ActivationState::PROVISIONED, $actor, $event));
            self::verifyStep($row, $policy, $actor);
            return;
        }
        if ($result->status === ProvisionResult::FAILED && $result->retryable) {
            self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONING], ActivationState::RETRY_PENDING, $actor, $event));
            return;
        }
        if ($result->status === ProvisionResult::FAILED) {
            self::fail($id, [ActivationState::PROVISIONING], $actor, $result->code, $result->message, 'PROVISIONING_RESULT', ProvisionResult::FAILED);
            return;
        }
        // UNKNOWN: the change may have been applied; the reservation stays ACTIVE until reconciliation
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONING], ActivationState::RECONCILIATION_REQUIRED, $actor,
            $event, ['error_code' => $result->code, 'error_message' => $result->message]));
    }

    private static function verifyStep(array $row, ?RadiusPolicy $policy, ActivationActor $actor): void
    {
        $id = (int) $row['id'];
        self::tx(static fn(\PDO $pdo) => ActivationStore::transition($pdo, $id, [ActivationState::PROVISIONED], ActivationState::VERIFYING, $actor));
        $ok = false;
        try {
            $ok = $policy !== null && self::applied(RadiusRepository::connection()->readUser($policy->username), $policy);
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
        if (in_array($row['action'], ['CHANGE_PLAN', 'REACTIVATE'], true)) {
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
                        'invoice' => $legacy['invoice'], 'recharge_id' => $legacy['recharge'], 'transaction_id' => $legacy['transaction']],
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
            $policy = self::policy($act);
        } catch (\InvalidArgumentException $e) {
            return [ProvisionResult::failed($e->getMessage(), 'The customer data cannot be provisioned.', false), null];
        }
        $provisioner = RadiusProvisioner::connection();
        $plan = self::plan($act);
        $bw = Tx::row(FinanceTx::pdo(), 'SELECT * FROM tbl_bandwidth WHERE id = ?', [(int) $plan['id_bw']]);
        $group = $provisioner->applyPlan((int) $plan['id'], $bw);
        if (!$group->isSuccess()) {
            return [$group, $policy];
        }
        return [$provisioner->provision($policy), $policy];
    }

    /** @return RadiusPolicy the policy this activation must leave in RADIUS */
    public static function policy(array $act): RadiusPolicy
    {
        $pdo = FinanceTx::pdo();
        $customer = Tx::row($pdo, 'SELECT * FROM tbl_customers WHERE id = ?', [(int) $act['customer_id']]) ?? throw new \InvalidArgumentException('CUSTOMER_MISSING');
        $plan = self::plan($act);
        $bw = Tx::row($pdo, 'SELECT * FROM tbl_bandwidth WHERE id = ?', [(int) $plan['id_bw']]);
        $meta = ActivationStore::period($pdo, (int) $act['id']);
        $zone = new \DateTimeZone((string) $meta['period']['timezone']);
        $start = ServicePeriod::at(substr($meta['period']['start'], 0, 10), substr($meta['period']['start'], 11), $zone);
        $expiry = ServicePeriod::at(substr($meta['period']['expiry'], 0, 10), substr($meta['period']['expiry'], 11), $zone);
        return RadiusPolicyBuilder::build($customer, $plan, $bw, $expiry, $start);
    }

    /** RADIUS shows the activation's policy: its expiry and its plan group (the activation-specific facts). */
    public static function applied(array $state, RadiusPolicy $policy): bool
    {
        $want = $policy->checkMap();
        return ($state['check']['Expiration'] ?? null) === ($want['Expiration'] ?? null) && $state['groups'] === [$policy->group];
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
            'A' => DataScope::customerInScope($a, C::ACTIVATION_READ, (int) $act['customer_id']),
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

    /** Ends the customer's sessions so a changed policy applies (best effort; UNKNOWN never undoes provisioning). */
    private static function endSessions(int $id, string $username, ActivationActor $actor): void
    {
        $summary = [];
        try {
            foreach (CoAClient::connection()->disconnect($username) as $r) {
                $summary[] = $r->status . ':' . $r->code;
            }
        } catch (\Throwable $e) {
            $summary[] = 'ERROR';
            \Reseller\Security\ErrorReporter::report($e, 'activation-disconnect');
        }
        self::tx(static function (\PDO $pdo) use ($id, $actor, $summary): void {
            ActivationStore::lock($pdo, $id);
            ActivationStore::event($pdo, $id, ActivationState::VERIFIED, ActivationState::VERIFIED, $actor, ['event_type' => 'ANNOTATION', 'metadata' => ['disconnect' => $summary]]);
        });
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
