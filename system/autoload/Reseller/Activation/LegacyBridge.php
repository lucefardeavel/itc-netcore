<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

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
 */
final class LegacyBridge
{
    public const ROUTERS = 'radius';

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
     * @return array{recharge:int,transaction:int,invoice:string}
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
        return ['recharge' => $rechargeId, 'transaction' => $transactionId, 'invoice' => $invoice];
    }

    /** @param array<string,mixed> $values */
    private static function insert(\PDO $pdo, string $table, array $values): int
    {
        $cols = implode(', ', array_keys($values));
        Tx::exec($pdo, "INSERT INTO {$table} ({$cols}) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values));
        return (int) $pdo->lastInsertId();
    }
}
