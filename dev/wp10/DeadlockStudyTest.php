<?php
namespace Reseller\Tests\Lab;

// WP10 O-DL STUDY (test tooling only). Run explicitly by dev/wp10/deadlock_study.sh on the disposable databases of the isolated
// nuxbill-reseller-test compose project. Bounded: at most 12 concurrent worker processes (one connection each). Never a production service.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationService;
use Reseller\Activation\ActivationStore;
use Reseller\Activation\ReconciliationService;
use Reseller\Domain\DomainError;
use Reseller\Finance\SettlementReadModel;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceWorkers;

/**
 * Owner direction on O-DL (2026-10-18): characterise the deadlock refusal of concurrent activations on ONE wallet with the SAME workload on any
 * candidate, and prove what it leaves behind. Per process: ROUNDS waves of 12 concurrent NEW activations by one manager (different customers).
 *  - frequency: operations, refusals, InnoDB deadlocks in total (absorbed by the bounded retries or not);
 *  - retry outcome: the refusal reason and the user-facing message;
 *  - what a refusal leaves: the activation state, the reservation state, no service granted (no RADIUS rows), no ledger entry, no second reservation;
 *  - what the customer's own retry gets (a new activation for the same customer);
 *  - operator recovery: the activation is made stale (the 300 s the operator tool requires) and half of them are RESUMED, half CANCELLED:
 *    resume = exactly one settlement and the service; cancel = reservation released, nothing charged; the time each action takes;
 *  - at the end: ledger / wallets reconcile, COMPLETED activations = COMMITTED reservations = ACTIVATION_DEBIT entries, no ACTIVE reservation, no
 *    non-terminal activation, no indefinite HELD state.
 * Parameters: DLSTUDY_ROUNDS (default 30), DLSTUDY_OUT (directory for the jsonl).
 */
final class DeadlockStudyTest extends ActivationTestCase
{
    private const WIDTH = 12;

    private static function log(array $row): void
    {
        $line = json_encode($row + ['t' => gmdate('Y-m-d\TH:i:s\Z')], JSON_UNESCAPED_SLASHES);
        file_put_contents((getenv('DLSTUDY_OUT') ?: '/tmp') . '/deadlock_study.jsonl', $line . "\n", FILE_APPEND);
        fwrite(STDERR, $line . "\n");
    }

    /** InnoDB deadlocks since the server started (MariaDB: Innodb_deadlocks; MySQL: INNODB_METRICS lock_deadlocks) */
    private function deadlocks(): int
    {
        $v = static::$db->rows("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'");
        if ($v !== []) {
            return (int) $v[0]['Value'];
        }
        return (int) static::$db->value("SELECT COUNT FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'");
    }

    public function testDeadlockRefusalsAndWhatTheyLeaveBehind(): void
    {
        $rounds = (int) (getenv('DLSTUDY_ROUNDS') ?: 30);
        $cfg = [];
        foreach (static::$db->rows("SHOW VARIABLES WHERE Variable_name IN ('version','transaction_isolation','tx_isolation','innodb_lock_wait_timeout','innodb_deadlock_detect','innodb_buffer_pool_size')") as $r) {
            $cfg[$r['Variable_name']] = $r['Value'];
        }
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $this->fund($top, '200000');
        $this->fund($leaf, '200000');
        $dl0 = $this->deadlocks();
        $ops = 0;
        $outcomes = [];
        $refusedCustomers = [];
        $waveSeconds = [];
        for ($w = 0; $w < $rounds; $w++) {
            $batch = [];
            $custs = [];
            for ($i = 0; $i < self::WIDTH; $i++) {
                $c = $this->customer($leaf);
                $custs[] = $c;
                $batch[] = ['op' => 'activate', 'actor' => $leaf, 'customer' => $c, 'plan' => $plan, 'action' => 'NEW', 'nonce' => self::nonce()];
            }
            $t = microtime(true);
            $res = FinanceWorkers::stampede(static::$db, $batch);
            $waveSeconds[] = microtime(true) - $t;
            foreach ($res as $i => $r) {
                $ops++;
                $key = $r['ok'] ? 'OK:' . ($r['result']['status'] ?? '?') : 'REFUSED:' . $r['reason'];
                $outcomes[$key] = ($outcomes[$key] ?? 0) + 1;
                if (!$r['ok']) {
                    $refusedCustomers[$custs[$i]] = $r['reason'];
                }
            }
        }
        $deadlocks = $this->deadlocks() - $dl0;
        $refusals = count($refusedCustomers);
        // what a refusal left behind, per refused customer
        $held = [];
        foreach ($refusedCustomers as $cust => $reason) {
            $acts = static::$db->rows('SELECT id, reference, status FROM tbl_reseller_activations WHERE customer_id = ?', [$cust]);
            $row = ['customer' => $cust, 'reason' => $reason, 'user_message' => (new DomainError((string) $reason))->getMessage(), 'activations' => count($acts)];
            if ($acts === []) {
                $row += ['state' => 'NO_ACTIVATION', 'reservation' => null];   // refused before anything was written: nothing to recover
                $held[] = $row;
                continue;
            }
            $this->assertCount(1, $acts, 'one activation per refused customer');
            $id = (int) $acts[0]['id'];
            $res = static::$db->rows('SELECT status, amount FROM tbl_manager_wallet_reservations WHERE activation_id = ?', [$id]);
            $username = $this->username($cust);
            $row += ['reference' => $acts[0]['reference'], 'state' => $acts[0]['status'], 'reservations' => array_column($res, 'status'),
                'service_rows' => (int) static::$db->value('SELECT COUNT(*) FROM radcheck WHERE username = ?', [$username]),
                'ledger_entries' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger WHERE activation_id = ?', [$id]),
                'timeline' => (string) static::$db->value("SELECT GROUP_CONCAT(CONCAT(IFNULL(from_state, '-'), '>', to_state) ORDER BY sequence_no SEPARATOR ' | ') FROM tbl_reseller_activation_events WHERE activation_id = ?", [$id])];
            // nothing granted and nothing charged while it is held
            if (!in_array($acts[0]['status'], ['COMPLETED', 'FAILED', 'CANCELLED'], true)) {
                $this->assertSame(0, $row['service_rows'], 'a held activation granted no service');
                $this->assertSame(0, $row['ledger_entries'], 'a held activation charged nothing');
                $this->assertLessThanOrEqual(1, count($res), 'no second reservation');
                // the customer's own retry (a new activation for the same customer) must not double-reserve or double-charge
                try {
                    $retry = $this->activate($leaf, $cust, $plan, 'NEW');
                    $row['customer_retry'] = 'ACCEPTED:' . ($retry['status'] ?? '?');
                } catch (DomainError $e) {
                    $row['customer_retry'] = 'REFUSED:' . $e->reason;
                }
                $row['activations_after_retry'] = (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ?', [$cust]);
                $row['active_reservations_after_retry'] = (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_wallet_reservations r JOIN tbl_reseller_activations a ON a.id = r.activation_id WHERE a.customer_id = ? AND r.status = 'ACTIVE'", [$cust]);
                $this->assertLessThanOrEqual(1, $row['active_reservations_after_retry'], 'the customer retry never holds the funds twice');
            }
            $held[] = $row;
        }
        self::log(['case' => 'wave-summary', 'rounds' => $rounds, 'ops' => $ops, 'wave_seconds_avg' => round(array_sum($waveSeconds) / max(1, count($waveSeconds)), 2), 'outcomes' => $outcomes,
            'refusals' => $refusals, 'innodb_deadlocks' => $deadlocks, 'refusal_rate_per_op' => round($refusals / max(1, $ops), 5)]);
        // operator recovery: stale (as the operator tool requires), then resume half and cancel half; time each
        $recovered = ['resumed' => [], 'cancelled' => []];
        $n = 0;
        foreach (static::$db->rows("SELECT id, reference, customer_id FROM tbl_reseller_activations WHERE status NOT IN ('COMPLETED','FAILED','CANCELLED','ROLLED_BACK','REVERSED') ORDER BY id") as $a) {
            static::$db->pdo->prepare('UPDATE tbl_reseller_activations SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR WHERE id = ?')->execute([$a['id']]);
            $stale = array_column(ReconciliationService::stale(), 'reference');
            $this->assertContains($a['reference'], $stale, 'a held activation is listed as stale (reconcile.php stale)');
            $t = microtime(true);
            if ($n++ % 2 === 0) {
                $r = ActivationService::execute((int) $a['id'], ActivationActor::system('dl-study'));
                $recovered['resumed'][] = ['ms' => (int) round((microtime(true) - $t) * 1000), 'status' => $r['status'] ?? '?'];
                $this->assertSame('COMPLETED', $r['status'], 'resume completes the held activation');
                $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_wallet_reservations WHERE activation_id = ? AND status = 'COMMITTED'", [$a['id']]), 'resume settles exactly once');
                $this->assertGreaterThan(0, (int) static::$db->value('SELECT COUNT(*) FROM radcheck WHERE username = ?', [$this->username((int) $a['customer_id'])]), 'resume grants the service');
            } else {
                $r = ReconciliationService::cancel(ActivationActor::system('dl-study'), $a['reference']);
                $recovered['cancelled'][] = ['ms' => (int) round((microtime(true) - $t) * 1000), 'status' => $r['status'] ?? '?'];
                $this->assertSame('CANCELLED', $r['status']);
                $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger WHERE activation_id = ?', [$a['id']]), 'cancel charges nothing');
                $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM radcheck WHERE username = ?', [$this->username((int) $a['customer_id'])]), 'cancel grants nothing');
            }
        }
        // final accounting of the whole study
        $completed = (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE status = 'COMPLETED'");
        $committed = (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_wallet_reservations WHERE status = 'COMMITTED'");
        $debits = (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_ledger WHERE entry_type = 'ACTIVATION_DEBIT'");
        $final = [
            'completed' => $completed, 'committed_reservations' => $committed, 'activation_debits' => $debits,
            'active_reservations' => (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_wallet_reservations WHERE status = 'ACTIVE'"),
            'non_terminal' => (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_activations WHERE status NOT IN ('COMPLETED','FAILED','CANCELLED','ROLLED_BACK','REVERSED')"),
            'integrity' => SettlementReadModel::integrity(),
        ];
        self::log(['case' => 'held-detail', 'config' => $cfg, 'held' => $held]);
        self::log(['case' => 'recovery', 'resumed' => $recovered['resumed'], 'cancelled' => $recovered['cancelled'], 'operator_wait_floor_s' => ReconciliationService::STALE_SECONDS, 'final' => $final]);
        $this->assertSame($completed, $committed, 'COMPLETED activations = COMMITTED reservations');
        $this->assertSame($committed, $debits, 'COMMITTED reservations = ACTIVATION_DEBIT entries (no double charge, no missing charge)');
        $this->assertSame([], static::$db->rows("SELECT customer_id, COUNT(*) AS n FROM tbl_reseller_activations WHERE status = 'COMPLETED' GROUP BY customer_id HAVING COUNT(*) > 1"), 'no customer was served twice (a retry plus a resume would double the service and the charge)');
        $this->assertSame(0, $final['active_reservations'], 'no reservation left HELD');
        $this->assertSame(0, $final['non_terminal'], 'no orphaned operation');
        $this->assertSame([], $final['integrity'], 'ledger and wallets reconcile');
        // health sees a held activation (monitoring): the check exists and is tested elsewhere; here only that nothing is left to see
        $this->assertSame(0, ActivationStore::staleCount(\Reseller\Finance\FinanceTx::pdo(), gmdate('Y-m-d H:i:s', time() - 300)), 'monitoring: nothing stale after the recovery');
    }
}
