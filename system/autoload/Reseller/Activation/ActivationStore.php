<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;

/**
 * Persistence of activations and their event timeline (R0003). The only writer of
 * tbl_reseller_activations / tbl_reseller_activation_events besides the finance primitives' own reads.
 *
 *  - an activation is inserted exactly once, with its sealed pricing snapshot (all five columns, WP3B);
 *  - its snapshot columns are never updated;
 *  - every state change is a guarded UPDATE (the current state must be the expected one and the
 *    transition must be allowed by ActivationState) plus one appended event with the next sequence number,
 *    under the activation row lock: a concurrent duplicate step loses cleanly (ACTIVATION_STATE_CONFLICT);
 *  - events are append-only: never updated or deleted.
 */
final class ActivationStore
{
    /** Rows needed by the orchestration (snapshot columns included for the finance re-checks). */
    public static function lock(\PDO $pdo, int $id): array
    {
        return Tx::row($pdo, 'SELECT * FROM tbl_reseller_activations WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainError('NOT_FOUND');
    }

    /** @return array<string,mixed>|null */
    public static function find(\PDO $pdo, int $id): ?array
    {
        return Tx::row($pdo, 'SELECT * FROM tbl_reseller_activations WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function byKey(\PDO $pdo, string $key): ?array
    {
        return Tx::row($pdo, 'SELECT * FROM tbl_reseller_activations WHERE idempotency_key = ?', [$key]);
    }

    /** @return array<string,mixed>|null */
    public static function byReference(\PDO $pdo, string $reference): ?array
    {
        return Tx::row($pdo, 'SELECT * FROM tbl_reseller_activations WHERE reference = ?', [$reference]);
    }

    /** The customer's non-terminal activation, if any (the in-flight guard). */
    public static function inFlight(\PDO $pdo, int $customerId): ?array
    {
        return Tx::row(
            $pdo,
            'SELECT id, reference, status FROM tbl_reseller_activations WHERE customer_id = ? AND status NOT IN (' . implode(', ', array_fill(0, count(ActivationState::TERMINAL), '?')) . ') ORDER BY id LIMIT 1',
            [$customerId, ...ActivationState::TERMINAL]
        );
    }

    /**
     * The single activation INSERT (status CREATED), with the five sealed snapshot columns.
     *
     * @param array<string,mixed>       $f    identity, action, actor, customer, plan, manager
     * @param array<string,string|null> $snap PricingSnapshot::activationColumns()
     */
    public static function insert(\PDO $pdo, array $f, array $snap, string $now): int
    {
        Tx::exec(
            $pdo,
            'INSERT INTO tbl_reseller_activations (reference, idempotency_key, action, status, channel, manager_id, actor_type, actor_user_id, '
            . 'customer_id, plan_id, previous_plan_id, wholesale_cost, retail_price, provider_settlement_amount, plan_snapshot, pricing_chain_snapshot, '
            . "created_at, updated_at) VALUES (?, ?, ?, 'CREATED', 'STAFF_WEB', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $f['reference'], $f['idempotency_key'], $f['action'], $f['manager_id'], $f['actor_type'], $f['actor_user_id'],
                $f['customer_id'], $f['plan_id'], $f['previous_plan_id'],
                $snap['wholesale_cost'], $snap['retail_price'], $snap['provider_settlement_amount'], $snap['plan_snapshot'], $snap['pricing_chain_snapshot'],
                $now, $now,
            ]
        );
        return (int) $pdo->lastInsertId();
    }

    /**
     * Guarded state change + event (caller holds an open transaction). Returns the updated row.
     *
     * @param list<string>          $from    acceptable current states
     * @param array<string,mixed>   $event   event_type, provisioning_result, error_code, error_message, metadata
     * @param array<string,mixed>   $columns extra activation columns (error_code, error_message, completed_at, legacy_*)
     */
    public static function transition(\PDO $pdo, int $id, array $from, string $to, ActivationActor $actor, array $event = [], array $columns = []): array
    {
        $row = self::lock($pdo, $id);
        if (!in_array($row['status'], $from, true)) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        ActivationState::assertTransition((string) $row['status'], $to);
        $now = Tx::utcNow();
        self::write($pdo, $id, (string) $row['status'], ['status' => $to, 'updated_at' => $now] + $columns);
        self::event($pdo, $id, (string) $row['status'], $to, $actor, $event, $now);
        return self::lock($pdo, $id);
    }

    /**
     * A recorded outcome without a state change (e.g. EXTERNAL_STATE_AMBIGUOUS): the activation must be in
     * $state; its error columns are updated and one event from = to = $state is appended.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $columns error_code, error_message
     */
    public static function note(\PDO $pdo, int $id, string $state, ActivationActor $actor, array $event, array $columns = []): array
    {
        $row = self::lock($pdo, $id);
        if ($row['status'] !== $state) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
        $now = Tx::utcNow();
        if ($columns !== []) {
            self::write($pdo, $id, $state, ['updated_at' => $now] + $columns);
        }
        self::event($pdo, $id, $state, $state, $actor, $event, $now);
        return self::lock($pdo, $id);
    }

    /** The single guarded UPDATE of an activation row: only the columns below, never a snapshot column. */
    private static function write(\PDO $pdo, int $id, string $expected, array $set): void
    {
        $allowed = ['status', 'updated_at', 'error_code', 'error_message', 'completed_at', 'legacy_recharge_id', 'legacy_transaction_id', 'legacy_invoice'];
        if (array_diff(array_keys($set), $allowed) !== []) {
            throw new \LogicException('activation column not updatable');
        }
        $cols = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($set)));
        $n = Tx::exec($pdo, "UPDATE tbl_reseller_activations SET {$cols} WHERE id = ? AND status = ?", [...array_values($set), $id, $expected]);
        if ($n !== 1) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT');
        }
    }

    /**
     * Appends one event (caller holds the activation row lock). An event without a state change uses
     * from = to = the current state (ANNOTATION / RECONCILIATION records).
     *
     * @param array<string,mixed> $event
     */
    public static function event(\PDO $pdo, int $id, ?string $from, string $to, ActivationActor $actor, array $event = [], ?string $now = null): void
    {
        $seq = (int) Tx::row($pdo, 'SELECT COALESCE(MAX(sequence_no), 0) + 1 AS n FROM tbl_reseller_activation_events WHERE activation_id = ?', [$id])['n'];
        $meta = $event['metadata'] ?? null;
        Tx::exec(
            $pdo,
            'INSERT INTO tbl_reseller_activation_events (activation_id, sequence_no, event_type, from_state, to_state, provisioning_result, '
            . 'error_code, error_message, actor_type, actor_user_id, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, $seq, $event['event_type'] ?? 'STATE_TRANSITION', $from, $to, $event['provisioning_result'] ?? null,
                isset($event['error_code']) ? substr((string) $event['error_code'], 0, 64) : null,
                isset($event['error_message']) ? substr((string) $event['error_message'], 0, 1000) : null,
                $actor->type, $actor->userId,
                $meta === null ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $now ?? Tx::utcNow(),
            ]
        );
    }

    /** @return list<array<string,mixed>> the timeline, in order */
    public static function events(\PDO $pdo, int $id): array
    {
        return Tx::rows($pdo, 'SELECT * FROM tbl_reseller_activation_events WHERE activation_id = ? ORDER BY sequence_no', [$id]);
    }

    /** The service period frozen at validation (VALIDATED event metadata). */
    public static function period(\PDO $pdo, int $id): array
    {
        $row = Tx::row($pdo, "SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND to_state = 'VALIDATED' ORDER BY sequence_no LIMIT 1", [$id]);
        $meta = $row !== null ? json_decode((string) $row['metadata'], true) : null;
        if (!is_array($meta) || !isset($meta['period'])) {
            throw new \LogicException('activation without a frozen period');
        }
        return $meta;
    }

    /**
     * The RADIUS states captured when provisioning started (PROVISIONING event metadata): the prior state of
     * the FIRST attempt (what a rollback restores) and the target of the LATEST attempt (what it applied).
     *
     * @return array{prior:?array,target:?array}
     */
    public static function radius(\PDO $pdo, int $id): array
    {
        $prior = null;
        $target = null;
        foreach (Tx::rows($pdo, "SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND to_state = 'PROVISIONING' ORDER BY sequence_no", [$id]) as $r) {
            $meta = $r['metadata'] !== null ? json_decode((string) $r['metadata'], true) : null;
            $radius = is_array($meta) ? ($meta['radius'] ?? null) : null;
            if (!is_array($radius)) {
                continue;
            }
            if ($prior === null && is_array($radius['prior'] ?? null)) {
                $prior = $radius['prior'];
            }
            if (is_array($radius['target'] ?? null)) {
                $target = $radius['target'];
            }
        }
        return ['prior' => $prior, 'target' => $target];
    }

    /** Durable evidence that the delivery was verified: the timeline reached VERIFIED at least once. */
    public static function wasVerified(\PDO $pdo, int $id): bool
    {
        return Tx::row($pdo, "SELECT 1 AS x FROM tbl_reseller_activation_events WHERE activation_id = ? AND to_state = 'VERIFIED' LIMIT 1", [$id]) !== null;
    }

    /** WP5B reversal: the metadata of the COMPLETED (FINANCIAL) event, or null. */
    public static function completion(\PDO $pdo, int $id): ?array
    {
        $row = Tx::row($pdo, "SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND to_state = 'COMPLETED' AND event_type = 'FINANCIAL' ORDER BY sequence_no DESC LIMIT 1", [$id]);
        $meta = $row !== null && $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null;
        return is_array($meta) ? $meta : null;
    }

    /**
     * WP5B reversal: the metadata of the PROVISIONING_RESULT events recorded while in $state with $result.
     *
     * @return list<mixed>
     */
    public static function results(\PDO $pdo, int $id, string $state, string $result): array
    {
        return array_map(static fn(array $e): mixed => json_decode((string) $e['metadata'], true), Tx::rows($pdo,
            "SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND from_state = ? AND to_state = ? AND event_type = 'PROVISIONING_RESULT' AND provisioning_result = ? ORDER BY sequence_no",
            [$id, $state, $state, $result]));
    }

    /**
     * WP5B reversal: a later activation of the customer (higher id) whose status is not in $excluded, or null.
     *
     * @param list<string> $excluded
     */
    public static function later(\PDO $pdo, int $customerId, int $id, array $excluded): ?array
    {
        return Tx::row($pdo, 'SELECT id, reference, status FROM tbl_reseller_activations WHERE customer_id = ? AND id > ? AND status NOT IN ('
            . implode(', ', array_fill(0, count($excluded), '?')) . ') ORDER BY id LIMIT 1', [$customerId, $id, ...$excluded]);
    }

    /** WP5B drift report: the customer's latest activation in $status, or null. */
    public static function latest(\PDO $pdo, int $customerId, string $status): ?array
    {
        return Tx::row($pdo, 'SELECT * FROM tbl_reseller_activations WHERE customer_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$customerId, $status]);
    }

    /**
     * WP6A (owner decision OD-A): read-only display rows for the activation read model. $where is a visibility
     * predicate over the alias `a` built by ActivationReadService (placeholders only); the joined columns are
     * display fields. No snapshot, settlement or chain column is selected.
     *
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function displayPage(\PDO $pdo, string $where, array $params, int $limit, int $offset): array
    {
        return Tx::rows($pdo, self::DISPLAY . " WHERE {$where} ORDER BY a.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
    }

    /** @param list<mixed> $params */
    public static function displayCount(\PDO $pdo, string $where, array $params): int
    {
        return (int) (Tx::row($pdo, "SELECT COUNT(*) AS n FROM tbl_reseller_activations a WHERE {$where}", $params)['n'] ?? 0);
    }

    /**
     * WP6A: activation counts per status within a visibility predicate, optionally only rows updated since $sinceUtc.
     *
     * @param list<mixed> $params
     * @return array<string,int>
     */
    public static function statusCounts(\PDO $pdo, string $where, array $params, ?string $sinceUtc = null): array
    {
        $sql = "SELECT a.status, COUNT(*) AS n FROM tbl_reseller_activations a WHERE {$where}" . ($sinceUtc !== null ? ' AND a.updated_at >= ?' : '') . ' GROUP BY a.status';
        $out = [];
        foreach (Tx::rows($pdo, $sql, $sinceUtc !== null ? [...$params, $sinceUtc] : $params) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** WP6B: stale (not terminal, untouched since $cutoffUtc) activations, one bounded display page (oldest first). */
    public static function stalePage(\PDO $pdo, string $cutoffUtc, int $limit, int $offset): array
    {
        return Tx::rows($pdo, self::DISPLAY . ' WHERE a.status NOT IN (' . implode(', ', array_fill(0, count(ActivationState::TERMINAL), '?')) . ') AND a.updated_at <= ? '
            . 'ORDER BY a.updated_at LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), [...ActivationState::TERMINAL, $cutoffUtc]);
    }

    public static function staleCount(\PDO $pdo, string $cutoffUtc): int
    {
        return (int) (Tx::row($pdo, 'SELECT COUNT(*) AS n FROM tbl_reseller_activations a WHERE a.status NOT IN (' . implode(', ', array_fill(0, count(ActivationState::TERMINAL), '?'))
            . ') AND a.updated_at <= ?', [...ActivationState::TERMINAL, $cutoffUtc])['n'] ?? 0);
    }

    /** WP6A: one display row by id (same columns as displayPage), or null. */
    public static function displayById(\PDO $pdo, int $id): ?array
    {
        return Tx::row($pdo, self::DISPLAY . ' WHERE a.id = ?', [$id]);
    }

    private const DISPLAY = 'SELECT a.id, a.reference, a.action, a.status, a.error_code, a.customer_id, a.plan_id, a.previous_plan_id, a.manager_id, '
        . 'a.actor_type, a.actor_user_id, a.legacy_invoice, a.created_at, a.updated_at, c.username AS customer_username, c.fullname AS customer_fullname, '
        . 'p.name_plan AS plan_name, p.type AS plan_type, m.username AS manager_username, u.username AS actor_username '
        . 'FROM tbl_reseller_activations a LEFT JOIN tbl_customers c ON c.id = a.customer_id LEFT JOIN tbl_plans p ON p.id = a.plan_id '
        . 'LEFT JOIN tbl_users m ON m.id = a.manager_id LEFT JOIN tbl_users u ON u.id = a.actor_user_id';

    /** @return array<string,mixed>|null the activation's reservation (ACTIVE or COMMITTED first) */
    public static function reservation(\PDO $pdo, int $id): ?array
    {
        return Tx::row(
            $pdo,
            "SELECT * FROM tbl_manager_wallet_reservations WHERE activation_id = ? ORDER BY status = 'ACTIVE' DESC, status = 'COMMITTED' DESC, id DESC LIMIT 1",
            [$id]
        );
    }
}
