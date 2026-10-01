<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\DomainError;
use Reseller\Finance\Money;
use Reseller\Finance\PricingSnapshot;

/**
 * Whole-database financial invariants (WP3A), checked after every finance test and every concurrency
 * round. Returns a list of human-readable violations (empty = consistent):
 *
 *  - ledger continuity per wallet: entries ordered by id start at 0.00, balance_before = previous
 *    balance_after, balance_after = balance_before ± amount, last balance_after = wallet balance
 *  - debt_limit >= 0 and AVAILABLE = balance + debt_limit - SUM(ACTIVE) >= 0
 *  - every transfer row is COMPLETED or REVERSED (WP3A never persists PENDING/FAILED/CANCELLED) and has
 *    exactly its leg set with equal amounts, the operator as STAFF actor and deterministic keys
 *  - reversals are linked 1:1 (reverses_transfer_id / reverses_entry_id) and the original is REVERSED
 *  - every COMMITTED reservation has exactly one ACTIVATION_DEBIT of the reserved amount (and vice
 *    versa); ACTIVE reservations are unresolved and never expire on their own
 *  - WP3B settlement: every COMMISSION_CREDIT belongs to an activation with a COMMITTED reservation and
 *    a valid sealed pricing snapshot; the commission set of that activation is exactly the snapshot's
 *    positive margins (manager, amount, key com:{activation}:m{manager}, counterparty = leaf, same actor
 *    as the debit); debit = wholesale_cost = leaf wholesale; sum(commissions) + provider settlement =
 *    debit; an activation without a snapshot has no commission
 */
final class FinanceInvariants
{
    /** @return list<string> */
    public static function check(TestDatabase $db): array
    {
        $issues = [];
        $ledger = $db->rows('SELECT * FROM tbl_manager_ledger ORDER BY id');
        $byId = [];
        $byManager = [];
        $byTransfer = [];
        foreach ($ledger as $e) {
            $byId[(int) $e['id']] = $e;
            $byManager[(int) $e['manager_id']][] = $e;
            if ($e['transfer_id'] !== null) {
                $byTransfer[(int) $e['transfer_id']][] = $e;
            }
            if (!preg_match('/^(trf:[1-9][0-9]*:(credit|debit|out|in)|rev:[1-9][0-9]*:(credit|debit)|res:[1-9][0-9]*:commit|com:[1-9][0-9]*:m[1-9][0-9]*)$/D', (string) $e['entry_key'])) {
                $issues[] = "ledger {$e['id']}: unexpected entry key {$e['entry_key']}";
            }
            if ($e['transfer_id'] === null && !in_array($e['entry_type'], ['ACTIVATION_DEBIT', 'COMMISSION_CREDIT'], true)) {
                $issues[] = "ledger {$e['id']}: {$e['entry_type']} without an operation";
            }
        }
        $reservations = $db->rows('SELECT * FROM tbl_manager_wallet_reservations ORDER BY id');
        $activeByManager = [];
        foreach ($reservations as $r) {
            if ($r['status'] === 'ACTIVE') {
                $activeByManager[(int) $r['manager_id']] = ($activeByManager[(int) $r['manager_id']] ?? Money::zero())->plus(Money::fromDb($r['amount']));
            }
        }
        foreach ($db->rows('SELECT * FROM tbl_manager_wallets ORDER BY manager_id') as $w) {
            $m = (int) $w['manager_id'];
            $prev = Money::zero();
            foreach ($byManager[$m] ?? [] as $e) {
                $before = Money::fromDb($e['balance_before']);
                $after = Money::fromDb($e['balance_after']);
                $amount = Money::fromDb($e['amount']);
                if (!$before->equals($prev)) {
                    $issues[] = "wallet {$m} ledger {$e['id']}: balance_before {$before->format()} != previous {$prev->format()}";
                }
                $expect = $e['direction'] === 'CREDIT' ? $before->plus($amount) : $before->minus($amount);
                if (!$after->equals($expect) || !$amount->isPositive()) {
                    $issues[] = "wallet {$m} ledger {$e['id']}: arithmetic";
                }
                $prev = $after;
            }
            $balance = Money::fromDb($w['balance']);
            $debt = Money::fromDb($w['debt_limit']);
            if (!$balance->equals($prev)) {
                $issues[] = "wallet {$m}: balance {$balance->format()} != ledger {$prev->format()}";
            }
            if ($debt->isNegative()) {
                $issues[] = "wallet {$m}: negative debt limit";
            }
            $available = $balance->plus($debt)->minus($activeByManager[$m] ?? Money::zero());
            if ($available->isNegative()) {
                $issues[] = "wallet {$m}: available {$available->format()} < 0";
            }
        }
        $transfers = [];
        foreach ($db->rows('SELECT * FROM tbl_manager_transfers ORDER BY id') as $t) {
            $transfers[(int) $t['id']] = $t;
        }
        $reversedBy = [];
        foreach ($transfers as $id => $t) {
            if (!in_array($t['status'], ['COMPLETED', 'REVERSED'], true)) {
                $issues[] = "transfer {$id}: status {$t['status']}";
            }
            if ($t['completed_at'] === null) {
                $issues[] = "transfer {$id}: no completed_at";
            }
            $from = $t['from_manager_id'] !== null ? (int) $t['from_manager_id'] : null;
            $to = $t['to_manager_id'] !== null ? (int) $t['to_manager_id'] : null;
            $rev = $t['reverses_transfer_id'] !== null ? (int) $t['reverses_transfer_id'] : null;
            $expected = [];
            if ($rev === null) {
                $map = [
                    'PROVIDER_FUNDING' => [['credit', 'FUNDING', $to, 'credit']],
                    'PROVIDER_WITHDRAWAL' => [['debit', 'WITHDRAWAL', $from, 'debit']],
                    'ADJUSTMENT_CREDIT' => [['credit', 'ADJUSTMENT', $to, 'credit']],
                    'ADJUSTMENT_DEBIT' => [['debit', 'ADJUSTMENT', $from, 'debit']],
                    'PARENT_CHILD_TRANSFER' => [['debit', 'TRANSFER_OUT', $from, 'out'], ['credit', 'TRANSFER_IN', $to, 'in']],
                ];
                foreach ($map[$t['operation_type']] as [$side, $type, $mgr, $leg]) {
                    $expected["trf:{$id}:{$leg}"] = [$side, $type, $mgr];
                }
            } else {
                $reversedBy[$rev][] = $id;
                if ($from !== null) {
                    $expected["rev:{$id}:debit"] = ['debit', 'REVERSAL', $from];
                }
                if ($to !== null) {
                    $expected["rev:{$id}:credit"] = ['credit', 'REVERSAL', $to];
                }
                $o = $transfers[$rev] ?? null;
                $inverse = ['PROVIDER_FUNDING' => 'PROVIDER_WITHDRAWAL', 'PROVIDER_WITHDRAWAL' => 'PROVIDER_FUNDING', 'ADJUSTMENT_CREDIT' => 'ADJUSTMENT_DEBIT',
                    'ADJUSTMENT_DEBIT' => 'ADJUSTMENT_CREDIT', 'PARENT_CHILD_TRANSFER' => 'PARENT_CHILD_TRANSFER'];
                if ($o === null || $o['status'] !== 'REVERSED' || $o['reverses_transfer_id'] !== null
                    || $inverse[$o['operation_type']] !== $t['operation_type'] || $o['amount'] !== $t['amount']
                    || $o['from_manager_id'] !== $t['to_manager_id'] || $o['to_manager_id'] !== $t['from_manager_id'] || $t['status'] !== 'COMPLETED') {
                    $issues[] = "transfer {$id}: reversal of {$rev} is not a mirrored compensation";
                }
            }
            $entries = $byTransfer[$id] ?? [];
            if (count($entries) !== count($expected)) {
                $issues[] = "transfer {$id}: " . count($entries) . ' ledger entries, expected ' . count($expected);
            }
            foreach ($entries as $e) {
                $x = $expected[(string) $e['entry_key']] ?? null;
                if ($x === null || strtolower((string) $e['direction']) !== $x[0] || $e['entry_type'] !== $x[1] || (int) $e['manager_id'] !== $x[2]) {
                    $issues[] = "transfer {$id}: unexpected entry {$e['entry_key']}";
                    continue;
                }
                if ($e['amount'] !== $t['amount'] || $e['actor_type'] !== 'STAFF' || (int) $e['operator_id'] !== (int) $t['operator_id']) {
                    $issues[] = "transfer {$id}: entry {$e['id']} amount/actor differs from the operation";
                }
                if ($rev !== null) {
                    $orig = $byId[(int) ($e['reverses_entry_id'] ?? 0)] ?? null;
                    if ($orig === null || (int) $orig['transfer_id'] !== $rev || (int) $orig['manager_id'] !== (int) $e['manager_id'] || $orig['direction'] === $e['direction']) {
                        $issues[] = "transfer {$id}: entry {$e['id']} does not compensate an entry of {$rev}";
                    }
                } elseif ($e['reverses_entry_id'] !== null) {
                    $issues[] = "transfer {$id}: entry {$e['id']} has a reverses_entry_id";
                }
            }
            if ($t['status'] === 'REVERSED' && count($reversedBy[$id] ?? []) === 0) {
                // the reversal row has a higher id; checked again below
            }
        }
        foreach ($transfers as $id => $t) {
            $n = count($reversedBy[$id] ?? []);
            if (($t['status'] === 'REVERSED') !== ($n === 1) || $n > 1) {
                $issues[] = "transfer {$id}: status {$t['status']} with {$n} reversal(s)";
            }
        }
        $debitRefs = [];
        foreach ($reservations as $r) {
            $id = (int) $r['id'];
            if ($r['expires_at'] !== null) {
                $issues[] = "reservation {$id}: expires_at set (WP3A never schedules expiry)";
            }
            if ($r['status'] === 'COMMITTED') {
                $e = $byId[(int) ($r['committed_ledger_entry_id'] ?? 0)] ?? null;
                if ($e === null || $e['entry_type'] !== 'ACTIVATION_DEBIT' || $e['direction'] !== 'DEBIT' || $e['amount'] !== $r['amount']
                    || (int) $e['manager_id'] !== (int) $r['manager_id'] || (int) $e['activation_id'] !== (int) $r['activation_id']
                    || $e['entry_key'] !== "res:{$id}:commit") {
                    $issues[] = "reservation {$id}: committed without its exact ACTIVATION_DEBIT";
                } else {
                    $debitRefs[(int) $e['id']] = ($debitRefs[(int) $e['id']] ?? 0) + 1;
                }
            } elseif ($r['committed_ledger_entry_id'] !== null) {
                $issues[] = "reservation {$id}: {$r['status']} with a ledger entry";
            }
            if (($r['status'] === 'ACTIVE') !== ($r['resolved_at'] === null)) {
                $issues[] = "reservation {$id}: resolution timestamp inconsistent";
            }
        }
        foreach ($ledger as $e) {
            if ($e['entry_type'] === 'ACTIVATION_DEBIT' && ($debitRefs[(int) $e['id']] ?? 0) !== 1) {
                $issues[] = "ledger {$e['id']}: ACTIVATION_DEBIT not linked to exactly one committed reservation";
            }
        }
        return array_merge($issues, self::settlements($db, $ledger, $reservations, $byId));
    }

    /**
     * WP3B activation settlements: commission legs and exact conservation per committed activation.
     *
     * @param list<array<string,mixed>> $ledger
     * @param list<array<string,mixed>> $reservations
     * @param array<int,array<string,mixed>> $byId
     * @return list<string>
     */
    private static function settlements(TestDatabase $db, array $ledger, array $reservations, array $byId): array
    {
        $issues = [];
        $commissions = [];
        foreach ($ledger as $e) {
            if ($e['entry_type'] === 'COMMISSION_CREDIT') {
                $commissions[(int) $e['activation_id']][] = $e;
                if ($e['direction'] !== 'CREDIT' || $e['transfer_id'] !== null || $e['reverses_entry_id'] !== null) {
                    $issues[] = "ledger {$e['id']}: malformed COMMISSION_CREDIT";
                }
            }
        }
        $committed = [];
        foreach ($reservations as $r) {
            if ($r['status'] === 'COMMITTED') {
                $committed[(int) $r['activation_id']][] = $r;
            }
        }
        foreach ($commissions as $act => $entries) {
            if (count($committed[$act] ?? []) !== 1) {
                $issues[] = "activation {$act}: commission credits without exactly one committed reservation";
            }
        }
        foreach ($committed as $act => $rows) {
            if (count($rows) !== 1) {
                $issues[] = "activation {$act}: more than one committed reservation";
                continue;
            }
            $r = $rows[0];
            $debit = $byId[(int) ($r['committed_ledger_entry_id'] ?? 0)] ?? null;
            $a = $db->rows('SELECT * FROM tbl_reseller_activations WHERE id = ?', [$act])[0] ?? null;
            if ($a === null || $debit === null) {
                $issues[] = "activation {$act}: settlement without activation or debit";
                continue;
            }
            $entries = $commissions[$act] ?? [];
            if ($a['pricing_chain_snapshot'] === null) {
                if ($entries !== []) {
                    $issues[] = "activation {$act}: commission credits without a pricing snapshot";
                }
                continue;
            }
            try {
                $snap = PricingSnapshot::fromActivationRow($a);
            } catch (DomainError $e) {
                $issues[] = "activation {$act}: committed with an invalid pricing snapshot ({$e->getMessage()})";
                continue;
            }
            if ($debit['amount'] !== $snap->leafDebit->format() || $a['wholesale_cost'] !== $snap->leafDebit->format() || (int) $debit['manager_id'] !== $snap->leafManagerId) {
                $issues[] = "activation {$act}: debit differs from the snapshot leaf wholesale";
            }
            $expected = [];
            foreach ($snap->commissionRecipients() as $m => $amount) {
                $expected["com:{$act}:m{$m}"] = [$m, $amount->format()];
            }
            $sum = $snap->providerSettlement;
            $seen = [];
            foreach ($entries as $e) {
                $x = $expected[(string) $e['entry_key']] ?? null;
                if ($x === null || (int) $e['manager_id'] !== $x[0] || $e['amount'] !== $x[1] || (int) $e['counterparty_manager_id'] !== $snap->leafManagerId
                    || $e['actor_type'] !== $debit['actor_type'] || $e['operator_id'] !== $debit['operator_id'] || $e['actor_customer_id'] !== $debit['actor_customer_id']) {
                    $issues[] = "activation {$act}: unexpected commission entry {$e['entry_key']}";
                    continue;
                }
                $seen[(string) $e['entry_key']] = true;
                $sum = $sum->plus(Money::fromDb($e['amount']));
            }
            if (count($seen) !== count($expected) || count($entries) !== count($expected)) {
                $issues[] = "activation {$act}: commission set differs from the snapshot";
            }
            if (!$sum->equals(Money::fromDb($debit['amount'])) || $a['provider_settlement_amount'] !== $snap->providerSettlement->format()) {
                $issues[] = "activation {$act}: settlement does not conserve the leaf debit";
            }
        }
        return $issues;
    }
}
