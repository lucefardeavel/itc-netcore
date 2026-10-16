<?php
namespace Reseller\Finance;

// Runtime class: loaded only through init.php (NUXR_RUNTIME) or the operator CLI; never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * WP9-OPS / TAMPER (owner §3 "finance and ledger consistency"): READ-ONLY whole-database integrity report of the
 * reseller finance and activation records, for the operator CLI (`php system/reseller/reconcile.php finance-check`)
 * and the WP10 health checks. It is the runtime form of the WP3A-WP5B test invariants (tests/Support/FinanceInvariants
 * + the activation invariants): ledger continuity per wallet and wallet = last balance_after, available >= 0,
 * transfer legs, reversal links, reservation <-> debit, WP3B settlement against the sealed snapshot, activation
 * timelines and their reservation / legacy records. It never writes; a non-empty result means tampering, a bug or a
 * write outside the finance services (the application enforces ledger immutability; WP10 least-privilege accounts
 * make it a database guarantee).
 */
final class IntegrityCheck
{
    /** Read-only integrity report of the CURRENT database (operator CLI). @return list<string> */
    public static function current(): array
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db = new Reader($pdo);
        return array_merge(self::check($db), self::activations($db));
    }

    /** @return list<string> */
    public static function check(Reader $db): array
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
            if (!preg_match('/^(trf:[1-9][0-9]*:(credit|debit|out|in)|rev:[1-9][0-9]*:(credit|debit|e[1-9][0-9]*)|res:[1-9][0-9]*:commit|com:[1-9][0-9]*:m[1-9][0-9]*)$/D', (string) $e['entry_key'])) {
                $issues[] = "ledger {$e['id']}: unexpected entry key {$e['entry_key']}";
            }
            if ($e['transfer_id'] === null && !in_array($e['entry_type'], ['ACTIVATION_DEBIT', 'COMMISSION_CREDIT'], true)
                && !($e['entry_type'] === 'REVERSAL' && $e['activation_id'] !== null)) {
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
        return array_merge($issues, self::settlements($db, $ledger, $reservations, $byId), self::activationReversals($db, $ledger, $byId));
    }

    /**
     * WP5B completed-activation reversals: every activation REVERSAL entry compensates exactly one original leg
     * of its activation (ACTIVATION_DEBIT / COMMISSION_CREDIT: same wallet and amount, opposite direction, key
     * rev:{activation}:e{original}, STAFF actor with a reason); a REVERSED activation has every original leg
     * reversed exactly once and any other activation none (the reversal commits all-or-nothing).
     *
     * @param list<array<string,mixed>> $ledger
     * @param array<int,array<string,mixed>> $byId
     * @return list<string>
     */
    private static function activationReversals(Reader $db, array $ledger, array $byId): array
    {
        $issues = [];
        $legs = [];
        $reversed = [];
        foreach ($ledger as $e) {
            if ($e['activation_id'] !== null && in_array($e['entry_type'], ['ACTIVATION_DEBIT', 'COMMISSION_CREDIT'], true)) {
                $legs[(int) $e['activation_id']][] = (int) $e['id'];
            }
            if ($e['entry_type'] !== 'REVERSAL' || $e['transfer_id'] !== null) {
                continue;
            }
            $act = (int) $e['activation_id'];
            $o = $byId[(int) ($e['reverses_entry_id'] ?? 0)] ?? null;
            if ($o === null || (int) $o['activation_id'] !== $act || !in_array($o['entry_type'], ['ACTIVATION_DEBIT', 'COMMISSION_CREDIT'], true)
                || (int) $o['manager_id'] !== (int) $e['manager_id'] || $o['direction'] === $e['direction'] || $o['amount'] !== $e['amount']
                || $e['entry_key'] !== "rev:{$act}:e{$o['id']}" || $e['actor_type'] !== 'STAFF' || $e['operator_id'] === null || $e['reason'] === null) {
                $issues[] = "ledger {$e['id']}: activation reversal does not compensate one original leg of activation {$act}";
                continue;
            }
            $reversed[$act][] = (int) $o['id'];
        }
        $status = [];
        foreach ($db->rows('SELECT id, status FROM tbl_reseller_activations') as $a) {
            $status[(int) $a['id']] = $a['status'];
        }
        foreach ($status as $act => $st) {
            $want = $st === 'REVERSED' ? ($legs[$act] ?? []) : [];
            $got = $reversed[$act] ?? [];
            sort($want);
            sort($got);
            if ($want !== $got || ($st === 'REVERSED' && $want === [])) {
                $issues[] = "activation {$act}: {$st} with reversed legs " . json_encode($got) . ', expected ' . json_encode($want);
            }
        }
        return $issues;
    }

    /**
     * WP3B activation settlements: commission legs and exact conservation per committed activation.
     *
     * @param list<array<string,mixed>> $ledger
     * @param list<array<string,mixed>> $reservations
     * @param array<int,array<string,mixed>> $byId
     * @return list<string>
     */
    private static function settlements(Reader $db, array $ledger, array $reservations, array $byId): array
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

    /**
     * Every activation's timeline is contiguous from CREATED with allowed transitions only, its status equals the timeline,
     * its reservation state matches its status, a COMPLETED / reversed activation has its legacy records and exactly the
     * compensating REV- transaction of a reversal (the WP5A / WP5B activation invariants).
     *
     * @return list<string>
     */
    public static function activations(Reader $db): array
    {
        $problems = [];
        foreach ($db->rows('SELECT * FROM tbl_reseller_activations ORDER BY id') as $a) {
            $events = $db->rows('SELECT * FROM tbl_reseller_activation_events WHERE activation_id = ? ORDER BY sequence_no', [$a['id']]);
            $tag = $a['reference'] . ' ';
            if ($events === [] || $events[0]['to_state'] !== 'CREATED' || $events[0]['from_state'] !== null) {
                $problems[] = $tag . 'timeline does not start at CREATED';
            }
            $state = null;
            foreach ($events as $i => $e) {
                if ((int) $e['sequence_no'] !== $i + 1) {
                    $problems[] = $tag . 'sequence gap at ' . $e['sequence_no'];
                }
                if ($i > 0 && $e['from_state'] !== $state) {
                    $problems[] = $tag . 'event ' . $e['sequence_no'] . ' does not continue from ' . $state;
                }
                if ($i > 0 && $e['from_state'] !== $e['to_state'] && !\Reseller\Activation\ActivationState::allowed((string) $e['from_state'], (string) $e['to_state'])) {
                    $problems[] = $tag . 'forbidden transition ' . $e['from_state'] . ' -> ' . $e['to_state'];
                }
                $state = $e['to_state'];
            }
            if ($state !== $a['status']) {
                $problems[] = $tag . 'status ' . $a['status'] . ' differs from the timeline ' . $state;
            }
            $statuses = array_column($db->rows('SELECT status FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$a['id']]), 'status');
            $want = match ($a['status']) {
                'COMPLETED', 'REVERSAL_PENDING', 'REVERSED' => ['COMMITTED'],
                'FAILED', 'CANCELLED', 'ROLLED_BACK' => ['RELEASED'],
                'CREATED', 'VALIDATED' => [],
                default => ['ACTIVE'],
            };
            if ($statuses !== $want) {
                $problems[] = $tag . $a['status'] . ' with reservations ' . json_encode($statuses);
            }
            if (in_array($a['status'], ['COMPLETED', 'REVERSAL_PENDING', 'REVERSED'], true) && ($a['legacy_recharge_id'] === null || $a['legacy_transaction_id'] === null || $a['legacy_invoice'] !== 'INV-' . $a['reference'])) {
                $problems[] = $tag . 'COMPLETED without its legacy records';
            }
            $rev = (int) ($db->rows('SELECT COUNT(*) AS n FROM tbl_transactions WHERE invoice = ?', ['REV-' . $a['reference']])[0]['n'] ?? 0);
            if ($rev !== ($a['status'] === 'REVERSED' ? 1 : 0)) {
                $problems[] = $tag . $a['status'] . " with {$rev} REV- transaction(s)";
            }
        }
        return $problems;
    }
}
