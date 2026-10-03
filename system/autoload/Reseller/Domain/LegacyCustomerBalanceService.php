<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ErrorReporter;

/**
 * Legacy customer-balance authority for UNASSIGNED customers (WP5B §7a): balance purchases (E8 buy, E9 gift,
 * E1 admin recharge "using balance", E11 auto-renew) and the customer-to-customer transfer (E18). A
 * reseller-owned payer, sender or target is always refused (read fresh under the lock). It is not reseller
 * commerce: no wallet, no activation, no settlement; it never references Reseller\Finance and never calls
 * Balance::*.
 *
 * Money: strict decimal strings parsed into integer minor units (DECIMAL(15,2) range), checked arithmetic, no
 * float. The balance is always the value read under SELECT ... FOR UPDATE of the customer row in the same
 * transaction and written back with a single statement; it never becomes negative. Customer rows are locked in
 * ascending id.
 *
 * Purchase lifecycle (journal = one tbl_payment_gateway row, gateway 'Balance', gateway_trx_id = the operation
 * key; status 5 HELD, 2 COMPLETED, 4 REFUNDED):
 *  1. Tx1: lock the payer (and target) row, look the key up: found -> replay (never debit or grant again);
 *  2. validate (amount > 0, plan enabled, target unassigned and Active, balance >= amount), debit, insert the
 *     journal row HELD; commit (the money is held before any dispatch);
 *  3. dispatch the legacy grant (Package::rechargeUser) outside any transaction;
 *  4. invoice -> COMPLETED once; exactly false (proven pre-write refusal) -> REFUNDED once with the credit back;
 *     anything else -> stays HELD for operator review (reconcile.php balance-held / balance-resolve).
 */
final class LegacyCustomerBalanceService
{
    public const GATEWAY = 'Balance';
    public const HELD = 5;
    public const COMPLETED = 2;
    public const REFUNDED = 4;
    public const CHANNELS = ['buy' => 'Buy Plan', 'gift' => 'Send Plan', 'admin' => 'Admin Recharge', 'cron' => 'Auto Renew'];
    private const MAX_MINOR = 9999999999999; // DECIMAL(15,2): 13 integer digits
    private const NONCE = '/^[A-Za-z0-9_-]{16,64}$/D';

    // ------------------------------------------------------------------ operation keys

    public static function key(string $kind, int $owner, string $suffix): string
    {
        if (!isset(self::CHANNELS[$kind]) && $kind !== 'transfer') {
            throw new \LogicException('unknown balance operation');
        }
        if ($kind !== 'cron' && !preg_match(self::NONCE, $suffix)) {
            throw new DomainError('INVALID_IDEMPOTENCY_KEY');
        }
        return 'balance:' . $kind . ':' . $owner . ':' . $suffix;
    }

    public static function cronKey(array $recharge): string
    {
        return 'balance:cron:' . (int) $recharge['id'] . ':' . $recharge['expiration'] . 'T' . $recharge['time'];
    }

    // ------------------------------------------------------------------ money

    /** Strict decimal (string) or a legacy computed number, to minor units; refuses <= 0, malformed, out of range. */
    public static function minor(mixed $amount): int
    {
        if (is_int($amount) || is_float($amount)) {
            if (!is_finite($amount)) {
                throw new DomainError('INVALID_AMOUNT');
            }
            $amount = sprintf('%.2f', $amount);
        }
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', trim($amount), $m)) {
            throw new DomainError('INVALID_AMOUNT');
        }
        $minor = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($minor <= 0 || $minor > self::MAX_MINOR * 100 + 99) {
            throw new DomainError('INVALID_AMOUNT');
        }
        return $minor;
    }

    public static function format(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        return $sign . intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ purchase

    /**
     * @param array{kind:string,key:string,payer_id:int,target_id:int,plan_id:int,routers:string,routers_id?:int,amount:mixed,grant_channel?:string,grant?:array{0:string,1:string}} $op
     * @return array{status:string,journal_id:int,invoice:?string,replayed:bool}
     */
    public static function purchase(array $op): array
    {
        $kind = $op['kind'];
        $channel = self::CHANNELS[$kind] ?? throw new \LogicException('unknown balance purchase');
        $amount = self::minor($op['amount']);
        $payer = (int) $op['payer_id'];
        $target = (int) $op['target_id'];
        $key = (string) $op['key'];
        // Tx1: identify / replay, validate, debit, HELD
        $held = Tx::run(static function (\PDO $pdo) use ($op, $channel, $amount, $payer, $target, $key): array {
            $rows = self::lockCustomers($pdo, array_unique([$payer, $target]));
            $journal = self::journal($pdo, $payer, $key);
            if ($journal !== null) {
                return ['replay' => $journal];
            }
            $p = $rows[$payer] ?? throw new DomainError('NOT_FOUND');
            $t = $rows[$target] ?? throw new DomainError('NOT_FOUND');
            foreach ([$p, $t] as $c) {
                if ($c['manager_id'] !== null) {
                    throw new DomainError('CUSTOMER_RESELLER_OWNED');
                }
            }
            if ($t['status'] !== 'Active' || ($op['kind'] !== 'admin' && $p['status'] !== 'Active')) {
                throw new DomainError('CUSTOMER_INACTIVE');
            }
            $plan = Tx::row($pdo, 'SELECT * FROM tbl_plans WHERE id = ?', [(int) $op['plan_id']]);
            if ($plan === null || (string) $plan['enabled'] !== '1') {
                throw new DomainError('PLAN_DISABLED');
            }
            $balance = self::balanceMinor($p['balance']);
            if ($balance < $amount) {
                throw new DomainError('INSUFFICIENT_BALANCE');
            }
            Tx::exec($pdo, 'UPDATE tbl_customers SET balance = ? WHERE id = ?', [self::format($balance - $amount), $payer]);
            $now = date('Y-m-d H:i:s');
            Tx::exec($pdo, 'INSERT INTO tbl_payment_gateway (username, user_id, gateway, gateway_trx_id, plan_id, plan_name, routers_id, routers, price, '
                . 'pg_url_payment, payment_method, payment_channel, pg_request, pg_paid_response, expired_date, created_date, paid_date, trx_invoice, status) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'balance', ?, ?, ?, NULL, NULL, ?, NULL, '', ?)", [
                (string) $p['username'], $payer, self::GATEWAY, $key, (int) $plan['id'], (string) $plan['name_plan'], (int) ($op['routers_id'] ?? 0),
                (string) $op['routers'], self::format($amount), self::GATEWAY, $channel,
                json_encode(['op' => $op['kind'], 'amount_minor' => $amount, 'target_customer_id' => $target, 'plan_id' => (int) $plan['id'],
                    'routers' => (string) $op['routers'], 'created' => $now], JSON_UNESCAPED_SLASHES),
                $now, self::HELD,
            ]);
            return ['id' => (int) $pdo->lastInsertId(), 'target' => $t];
        });
        if (isset($held['replay'])) {
            $j = $held['replay'];
            return ['status' => self::statusName((int) $j['status']), 'journal_id' => (int) $j['id'], 'invoice' => $j['trx_invoice'] !== '' ? $j['trx_invoice'] : null, 'replayed' => true];
        }
        $id = $held['id'];
        // dispatch: outside any transaction, no lock held
        try {
            // the legacy grant call of the path, unchanged: [gateway, channel] (default: the path's label, 'Balance')
            [$gw, $ch] = $op['grant'] ?? [(string) $op['grant_channel'], self::GATEWAY];
            $result = \Package::rechargeUser($target, (string) $op['routers'], (int) $op['plan_id'], (string) $gw, (string) $ch);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'balance-grant');
            return ['status' => 'HELD', 'journal_id' => $id, 'invoice' => null, 'replayed' => false];
        }
        if (is_string($result) && $result !== '') {
            Tx::run(static fn(\PDO $pdo) => Tx::exec($pdo, 'UPDATE tbl_payment_gateway SET status = ?, paid_date = ?, trx_invoice = ? WHERE id = ? AND status = ?',
                [self::COMPLETED, date('Y-m-d H:i:s'), substr($result, 0, 25), $id, self::HELD]));
            return ['status' => 'COMPLETED', 'journal_id' => $id, 'invoice' => $result, 'replayed' => false];
        }
        if ($result === false) {
            self::refund($id, ['refund' => 'not_granted']);
            return ['status' => 'REFUNDED', 'journal_id' => $id, 'invoice' => null, 'replayed' => false];
        }
        return ['status' => 'HELD', 'journal_id' => $id, 'invoice' => null, 'replayed' => false];
    }

    /** Exactly-once refund of a HELD journal row: status 5 -> 4 and the locked credit back, in one transaction. */
    private static function refund(int $id, array $why): bool
    {
        return Tx::run(static function (\PDO $pdo) use ($id, $why): bool {
            $j = Tx::row($pdo, 'SELECT * FROM tbl_payment_gateway WHERE id = ? AND gateway = ?', [$id, self::GATEWAY]) ?? throw new DomainError('NOT_FOUND');
            $payer = (int) $j['user_id'];
            $c = self::lockCustomers($pdo, [$payer])[$payer] ?? throw new DomainError('NOT_FOUND');
            $req = json_decode((string) $j['pg_request'], true);
            $amount = is_array($req) && is_int($req['amount_minor'] ?? null) ? $req['amount_minor'] : self::minor((string) $j['price']);
            $n = Tx::exec($pdo, 'UPDATE tbl_payment_gateway SET status = ?, paid_date = ?, pg_paid_response = ? WHERE id = ? AND status = ?',
                [self::REFUNDED, date('Y-m-d H:i:s'), json_encode($why + ['amount_minor' => $amount], JSON_UNESCAPED_SLASHES), $id, self::HELD]);
            if ($n !== 1) {
                return false;
            }
            $after = self::balanceMinor($c['balance']) + $amount;
            if ($after > self::MAX_MINOR * 100 + 99) {
                throw new DomainError('STORAGE_RANGE_EXCEEDED');
            }
            Tx::exec($pdo, 'UPDATE tbl_customers SET balance = ? WHERE id = ?', [self::format($after), $payer]);
            return true;
        });
    }

    // ------------------------------------------------------------------ operator resolution (CLI)

    /** @return list<array<string,mixed>> HELD balance operations (oldest first) */
    public static function held(): array
    {
        return Tx::rows(self::pdo(), 'SELECT id, username, user_id, gateway_trx_id, plan_id, plan_name, routers, price, payment_channel, created_date '
            . 'FROM tbl_payment_gateway WHERE gateway = ? AND status = ? ORDER BY id', [self::GATEWAY, self::HELD]);
    }

    /** Operator decision on one HELD row (exactly once): completed -> 2; refund -> 4 + credit back. */
    public static function resolve(int $id, string $decision): string
    {
        if ($decision === 'completed') {
            $n = Tx::run(static fn(\PDO $pdo): int => Tx::exec($pdo, "UPDATE tbl_payment_gateway SET status = ?, paid_date = ?, pg_paid_response = '{\"resolved\":\"completed\"}' WHERE id = ? AND gateway = ? AND status = ?",
                [self::COMPLETED, date('Y-m-d H:i:s'), $id, self::GATEWAY, self::HELD]));
            $ok = $n === 1;
        } elseif ($decision === 'refund') {
            $ok = self::refund($id, ['resolved' => 'refund']);
        } else {
            throw new DomainError('INVALID_INPUT');
        }
        ErrorReporter::log('balance.resolve', 'journal=' . $id . ' decision=' . $decision . ' applied=' . ($ok ? 'yes' : 'no'));
        if ($ok) {
            try {
                $j = Tx::row(self::pdo(), 'SELECT user_id, gateway_trx_id FROM tbl_payment_gateway WHERE id = ?', [$id]);
                \Reseller\Audit\AuditLog::record('balance.resolve', \Reseller\Security\ActorContext::system('CLI'), [
                    'target_type' => 'customer', 'target_customer_id' => (int) ($j['user_id'] ?? 0) ?: null,
                    'after' => ['journal_id' => $id, 'decision' => $decision, 'operation' => (string) ($j['gateway_trx_id'] ?? '')],
                ]);
            } catch (\Throwable $e) {
                ErrorReporter::report($e, 'balance-resolve-audit');
            }
        }
        if (!$ok) {
            throw new DomainError('ACTIVATION_STATE_CONFLICT', 'The operation is not HELD (already resolved or unknown); nothing was changed.');
        }
        return $decision;
    }

    // ------------------------------------------------------------------ transfer (E18)

    /**
     * Atomic customer-to-customer transfer: one transaction, both rows locked in ascending id, no reseller-owned
     * party, sender balance >= amount, legacy minimum; replay of the same key returns the earlier result.
     *
     * @return array{status:string,journal_id:int,replayed:bool}
     */
    public static function transfer(int $senderId, string $recipientUsername, mixed $amount, string $nonce, mixed $minimum = 0): array
    {
        $minor = self::minor($amount);
        $key = self::key('transfer', $senderId, $nonce);
        try {
            $min = is_numeric($minimum) && $minimum > 0 ? self::minor(is_string($minimum) ? trim($minimum) : $minimum) : 0;
        } catch (DomainError) {
            $min = 0; // a malformed legacy minimum_transfer setting imposes no minimum (legacy behaviour)
        }
        return Tx::run(static function (\PDO $pdo) use ($senderId, $recipientUsername, $minor, $key, $min): array {
            $r = Tx::row($pdo, 'SELECT id FROM tbl_customers WHERE username = ? ORDER BY id LIMIT 1', [$recipientUsername]) ?? throw new DomainError('NOT_FOUND', 'Username not found');
            $recipientId = (int) $r['id'];
            if ($recipientId === $senderId) {
                throw new DomainError('SELF_TARGET');
            }
            $rows = self::lockCustomers($pdo, [$senderId, $recipientId]);
            $journal = self::journal($pdo, $senderId, $key);
            if ($journal !== null) {
                return ['status' => self::statusName((int) $journal['status']), 'journal_id' => (int) $journal['id'], 'replayed' => true];
            }
            $s = $rows[$senderId] ?? throw new DomainError('NOT_FOUND');
            $t = $rows[$recipientId] ?? throw new DomainError('NOT_FOUND');
            if ($s['manager_id'] !== null || $t['manager_id'] !== null) {
                throw new DomainError('CUSTOMER_RESELLER_OWNED');
            }
            if ($s['status'] !== 'Active' || $t['status'] !== 'Active') {
                throw new DomainError('CUSTOMER_INACTIVE');
            }
            if ($minor < $min) {
                throw new DomainError('INVALID_AMOUNT', 'The amount is below the minimum transfer.');
            }
            $sb = self::balanceMinor($s['balance']);
            $tb = self::balanceMinor($t['balance']);
            if ($sb < $minor) {
                throw new DomainError('INSUFFICIENT_BALANCE');
            }
            if ($tb + $minor > self::MAX_MINOR * 100 + 99) {
                throw new DomainError('STORAGE_RANGE_EXCEEDED');
            }
            Tx::exec($pdo, 'UPDATE tbl_customers SET balance = ? WHERE id = ?', [self::format($sb - $minor), $senderId]);
            Tx::exec($pdo, 'UPDATE tbl_customers SET balance = ? WHERE id = ?', [self::format($tb + $minor), $recipientId]);
            $now = date('Y-m-d H:i:s');
            $ids = [];
            foreach ([[$s, $t, 'Send Balance', $key], [$t, $s, 'Received Balance', '']] as [$owner, $other, $ch, $trx]) {
                Tx::exec($pdo, 'INSERT INTO tbl_payment_gateway (username, user_id, gateway, gateway_trx_id, plan_id, plan_name, routers_id, routers, price, '
                    . 'pg_url_payment, payment_method, payment_channel, pg_request, pg_paid_response, expired_date, created_date, paid_date, trx_invoice, status) '
                    . "VALUES (?, ?, ?, ?, 0, ?, 0, 'balance', ?, 'balance', ?, ?, ?, '{}', ?, ?, ?, '', ?)", [
                    (string) $owner['username'], (int) $owner['id'], (string) $other['username'], $trx, $ch, self::format($minor), self::GATEWAY, $ch,
                    json_encode(['op' => 'transfer', 'amount_minor' => $minor, 'counterparty_id' => (int) $other['id']], JSON_UNESCAPED_SLASHES),
                    $now, $now, $now, self::COMPLETED,
                ]);
                $ids[] = (int) $pdo->lastInsertId();
            }
            return ['status' => 'COMPLETED', 'journal_id' => $ids[0], 'replayed' => false];
        });
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<int,array<string,mixed>> rows by id, locked FOR UPDATE in ascending id */
    private static function lockCustomers(\PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            $r = Tx::row($pdo, 'SELECT id, username, status, balance, manager_id FROM tbl_customers WHERE id = ? FOR UPDATE', [$id]);
            if ($r !== null) {
                $out[$id] = $r;
            }
        }
        return $out;
    }

    /** The journal row of an operation key (read inside the payer's row lock). */
    private static function journal(\PDO $pdo, int $payer, string $key): ?array
    {
        return Tx::row($pdo, 'SELECT id, status, trx_invoice FROM tbl_payment_gateway WHERE user_id = ? AND gateway_trx_id = ? AND payment_method = ? ORDER BY id LIMIT 1',
            [$payer, $key, self::GATEWAY]);
    }

    private static function balanceMinor(mixed $db): int
    {
        if (!preg_match('/^(-?)([0-9]+)\.([0-9]{2})$/D', (string) $db, $m)) {
            throw new \LogicException('unexpected customer balance format');
        }
        $v = (int) $m[2] * 100 + (int) $m[3];
        return $m[1] === '-' ? -$v : $v;
    }

    private static function statusName(int $status): string
    {
        return match ($status) {
            self::HELD => 'HELD', self::COMPLETED => 'COMPLETED', self::REFUNDED => 'REFUNDED', default => 'UNKNOWN',
        };
    }

    private static function pdo(): \PDO
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
