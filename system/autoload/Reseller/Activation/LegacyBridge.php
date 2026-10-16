<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Domain\Tx;

/**
 * Compatibility bridge (owner decision A6). Inside the COMPLETED transaction, an activation records what the
 * legacy application reads: the customer's RADIUS recharge row (legacy UI, cron expiry, device sync, rlm_rest)
 * and one transaction row (invoice history). It never calls Package / hooks / notifications, never composes
 * bills or tax (WP5B), and never uses the racy Package::_raid() invoice numbers.
 *
 *  - tbl_user_recharges: the customer's current row for routers 'radius' and the plan type (one per type,
 *    as the legacy code keeps it) is updated, or a row is inserted: plan, period start / expiry (wall clock
 *    in the application time zone), status 'on', method 'Reseller - <reference>';
 *  - tbl_transactions: invoice 'INV-<reference>' (unique), price = the sealed retail snapshot.
 *
 * WP5B reversal context (version 1): record() also returns what a later reversal of the activation needs to
 * undo exactly these two writes, stored by the caller in the COMPLETED event: the recharge row before the
 * write (legacy.pre, with an explicit 'exists') and after it (legacy.post, with the transaction id and invoice).
 * reverse() restores legacy.pre (or switches an inserted row off) only while the row still equals
 * legacy.post, and records the compensating transaction 'REV-<reference>' (negative retail).
 */
final class LegacyBridge
{
    public const ROUTERS = 'radius';
    public const CONTEXT_VERSION = 1;
    /** the recharge columns the reversal context captures and compares */
    public const RECHARGE_COLUMNS = ['id', 'customer_id', 'username', 'plan_id', 'namebp', 'recharged_on', 'recharged_time', 'expiration', 'time', 'status', 'method', 'routers', 'type', 'admin_id'];

    /** The customer's current recharge row for the plan type (the one the action is derived from). */
    public static function currentRecharge(\PDO $pdo, int $customerId, string $type, bool $lock = false): ?array
    {
        return Tx::row(
            $pdo,
            'SELECT * FROM tbl_user_recharges WHERE customer_id = ? AND routers = ? AND type = ? ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$customerId, self::ROUTERS, $type]
        );
    }

    /**
     * @param array<string,mixed> $act      activation row
     * @param array<string,mixed> $customer tbl_customers row
     * @param array<string,mixed> $plan     tbl_plans row
     * @return array{recharge:int,transaction:int,invoice:string,context:array<string,mixed>}
     */
    public static function record(\PDO $pdo, array $act, array $customer, array $plan, \DateTimeImmutable $start, \DateTimeImmutable $expiry, ?int $adminId): array
    {
        $method = 'Reseller - ' . $act['reference'];
        $admin = $adminId ?? 0;
        $values = [
            'customer_id' => (int) $customer['id'], 'username' => (string) $customer['username'], 'plan_id' => (int) $plan['id'],
            'namebp' => (string) $plan['name_plan'], 'recharged_on' => $start->format('Y-m-d'), 'recharged_time' => $start->format('H:i:s'),
            'expiration' => $expiry->format('Y-m-d'), 'time' => $expiry->format('H:i:s'), 'status' => 'on', 'method' => $method,
            'routers' => self::ROUTERS, 'type' => (string) $plan['type'], 'admin_id' => $admin,
        ];
        $current = self::currentRecharge($pdo, (int) $customer['id'], (string) $plan['type'], true);
        $pre = $current !== null ? ['exists' => true, 'row' => self::pick($current)] : ['exists' => false, 'routers' => self::ROUTERS, 'type' => (string) $plan['type']];
        if ($current !== null) {
            $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($values)));
            Tx::exec($pdo, "UPDATE tbl_user_recharges SET {$set} WHERE id = ?", [...array_values($values), (int) $current['id']]);
            $rechargeId = (int) $current['id'];
        } else {
            $rechargeId = self::insert($pdo, 'tbl_user_recharges', $values);
        }
        $invoice = 'INV-' . $act['reference'];
        $transactionId = self::insert($pdo, 'tbl_transactions', [
            'invoice' => $invoice, 'username' => (string) $customer['username'], 'user_id' => (int) $customer['id'],
            'plan_name' => (string) $plan['name_plan'], 'price' => (string) $act['retail_price'],
            'recharged_on' => $start->format('Y-m-d'), 'recharged_time' => $start->format('H:i:s'),
            'expiration' => $expiry->format('Y-m-d'), 'time' => $expiry->format('H:i:s'), 'method' => $method,
            'routers' => self::ROUTERS, 'type' => (string) $plan['type'],
            'note' => 'Reseller activation ' . $act['action'] . ' ' . $act['reference'], 'admin_id' => $admin,
        ]);
        $post = Tx::row($pdo, 'SELECT * FROM tbl_user_recharges WHERE id = ?', [$rechargeId]) ?? throw new \LogicException('recharge row not written');
        $context = ['v' => self::CONTEXT_VERSION, 'legacy' => ['pre' => $pre,
            'post' => ['recharge_id' => $rechargeId, 'row' => self::pick($post), 'transaction_id' => $transactionId, 'invoice' => $invoice]]];
        return ['recharge' => $rechargeId, 'transaction' => $transactionId, 'invoice' => $invoice, 'context' => $context];
    }

    /**
     * The validated legacy part of a stored reversal context, or null when it is absent or malformed (an
     * activation completed before WP5B, or a tampered event): the reversal then refuses.
     *
     * @return array{pre:array<string,mixed>,post:array<string,mixed>}|null
     */
    public static function context(mixed $context): ?array
    {
        $legacy = is_array($context) && ($context['v'] ?? null) === self::CONTEXT_VERSION ? ($context['legacy'] ?? null) : null;
        $pre = is_array($legacy) ? ($legacy['pre'] ?? null) : null;
        $post = is_array($legacy) ? ($legacy['post'] ?? null) : null;
        if (!is_array($pre) || !is_array($post) || !is_int($post['recharge_id'] ?? null) || !is_int($post['transaction_id'] ?? null)
            || !is_string($post['invoice'] ?? null) || !self::isRow($post['row'] ?? null) || (string) $post['row']['id'] !== (string) $post['recharge_id']) {
            return null;
        }
        if (($pre['exists'] ?? null) === true) {
            if (!self::isRow($pre['row'] ?? null) || (string) $pre['row']['id'] !== (string) $post['recharge_id']) {
                return null;
            }
        } elseif (($pre['exists'] ?? null) !== false || !is_string($pre['type'] ?? null)) {
            return null;
        }
        return ['pre' => $pre, 'post' => $post];
    }

    /** Whether the recharge row still equals legacy.post (read, optionally locked, in the caller's transaction). */
    public static function unchangedSince(\PDO $pdo, array $legacy, bool $lock = false): bool
    {
        $row = Tx::row($pdo, 'SELECT * FROM tbl_user_recharges WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [(int) $legacy['post']['recharge_id']]);
        return $row !== null && self::pick($row) === $legacy['post']['row'];
    }

    /** The invoice name of the compensating transaction of a reversed activation (the only REV- writer). */
    public static function reversalInvoice(string $reference): string
    {
        return 'REV-' . $reference;
    }

    /**
     * The legacy part of a reversal (caller's transaction, after the finance locks): the recharge row is locked
     * and must still equal legacy.post; it is restored to legacy.pre, or switched off when the activation had
     * inserted it (nothing is fabricated or deleted); then the compensating transaction 'REV-<reference>' is
     * written once (negative retail, the other columns from the 'INV-' row, today's date).
     *
     * @param array{pre:array<string,mixed>,post:array<string,mixed>} $legacy
     * @return array{recharge:int,transaction:int,invoice:string}
     */
    public static function reverse(\PDO $pdo, array $act, array $legacy, string $reason, ?int $adminId): array
    {
        if (!self::unchangedSince($pdo, $legacy, true)) {
            throw new DomainError('REVERSAL_EXTERNAL_STATE_CHANGED');
        }
        $rechargeId = (int) $legacy['post']['recharge_id'];
        if ($legacy['pre']['exists'] === true) {
            $values = $legacy['pre']['row'];
            unset($values['id']);
        } else {
            $values = ['status' => 'off', 'method' => 'Reseller - Reversed ' . $act['reference']];
        }
        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($values)));
        Tx::exec($pdo, "UPDATE tbl_user_recharges SET {$set} WHERE id = ?", [...array_values($values), $rechargeId]);
        $invoice = self::reversalInvoice((string) $act['reference']);
        if (Tx::row($pdo, 'SELECT id FROM tbl_transactions WHERE invoice = ? LIMIT 1', [$invoice]) !== null) {
            throw new \LogicException('compensating transaction already recorded');
        }
        $inv = Tx::row($pdo, 'SELECT * FROM tbl_transactions WHERE id = ? AND invoice = ?', [(int) $legacy['post']['transaction_id'], (string) $legacy['post']['invoice']])
            ?? throw new DomainError('REVERSAL_EXTERNAL_STATE_CHANGED');
        $now = ActivationService::now();
        $transactionId = self::insert($pdo, 'tbl_transactions', [
            'invoice' => $invoice, 'username' => (string) $inv['username'], 'user_id' => (int) $inv['user_id'],
            'plan_name' => (string) $inv['plan_name'], 'price' => '-' . $act['retail_price'],
            'recharged_on' => $now->format('Y-m-d'), 'recharged_time' => $now->format('H:i:s'),
            'expiration' => (string) $inv['expiration'], 'time' => (string) $inv['time'], 'method' => 'Reseller - Reversal ' . $act['reference'],
            'routers' => (string) $inv['routers'], 'type' => (string) $inv['type'],
            'note' => mb_substr('Reversal of ' . $inv['invoice'] . ': ' . $reason, 0, 256), 'admin_id' => $adminId ?? 0,
        ]);
        return ['recharge' => $rechargeId, 'transaction' => $transactionId, 'invoice' => $invoice];
    }

    /** @return array<string,?string> the captured recharge columns as strings (null kept) */
    private static function pick(array $row): array
    {
        $out = [];
        foreach (self::RECHARGE_COLUMNS as $c) {
            $out[$c] = array_key_exists($c, $row) && $row[$c] !== null ? (string) $row[$c] : null;
        }
        return $out;
    }

    private static function isRow(mixed $row): bool
    {
        return is_array($row) && array_keys($row) === self::RECHARGE_COLUMNS
            && array_filter($row, static fn(mixed $v): bool => $v !== null && !is_string($v)) === [];
    }

    /** @param array<string,mixed> $values */
    private static function insert(\PDO $pdo, string $table, array $values): int
    {
        $cols = implode(', ', array_keys($values));
        Tx::exec($pdo, "INSERT INTO {$table} ({$cols}) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values));
        return (int) $pdo->lastInsertId();
    }
}
