<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP5B §7a legacy customer-balance authority (unassigned customers only): balance purchases are exactly-once
 * through a tbl_payment_gateway journal (HELD 5 -> COMPLETED 2 / REFUNDED 4), serialized by the customer row
 * lock; the transfer is one atomic locked unit; a balance never becomes negative; reseller-owned parties are
 * refused. Runs inside the full application (exec harness), real concurrent processes.
 */
final class LegacyCustomerBalanceTest extends Wp2HttpTestCase
{
    private const PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): failure injection inside the legacy grant
register_hook('recharge_user', 'wp5bbal_recharge');
function wp5bbal_recharge() {
    $d = __DIR__ . '/../../wp2_probe/';
    if (is_file($d . 'wp5b_throw.flag')) { throw new RuntimeException('injected: grant interrupted'); }
    // another transition wins while the grant is dispatched (fix round 1, blocker 1 D)
    if (is_file($d . 'wp5b_override.flag')) { ORM::get_db()->prepare('UPDATE tbl_payment_gateway SET status = 4 WHERE gateway_trx_id = ?')->execute([trim((string) file_get_contents($d . 'wp5b_override.flag'))]); }
    // deterministic barrier inside the live dispatch (fix round 1, blocker 1 A-C)
    if (is_file($d . 'wp5b_barrier.flag')) {
        touch($d . 'wp5b_barrier.entered');
        $until = microtime(true) + 30;
        while (is_file($d . 'wp5b_barrier.flag') && microtime(true) < $until) { usleep(20000); clearstatcache(); }
    }
}
PHP;
    private const S = '\Reseller\Domain\LegacyCustomerBalanceService';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        file_put_contents(static::$app->dir . '/system/plugin/wp5b_balance_probe.php', self::PLUGIN);
    }

    protected function tearDown(): void
    {
        foreach (['wp5b_throw.flag', 'wp5b_override.flag', 'wp5b_barrier.flag', 'wp5b_barrier.entered'] as $f) {
            @unlink(static::$app->dir . '/wp2_probe/' . $f);
        }
    }

    private function customer2(string $balance, ?int $manager = null): array
    {
        $u = 'lb' . bin2hex(random_bytes(4));
        $id = static::$db->insertRow('tbl_customers', ['username' => $u, 'password' => 'pw-' . $u, 'fullname' => $u, 'address' => '', 'phonenumber' => '0700',
            'email' => $u . '@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
            'balance' => $balance, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0, 'manager_id' => $manager]);
        return [$id, $u];
    }

    private function balance(int $id): string
    {
        return (string) static::$db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$id]);
    }

    private static function nonce(): string
    {
        return bin2hex(random_bytes(12));
    }

    private function purchaseCode(string $kind, int $payer, int $target, string $key, string $amount, string $routers = 'wp2_router'): string
    {
        return sprintf('$result = %s::purchase(["kind" => %s, "key" => %s, "payer_id" => %d, "target_id" => %d, "plan_id" => %d, "routers" => %s, "amount" => %s, "grant_channel" => "Customer"]);',
            self::S, var_export($kind, true), var_export($key, true), $payer, $target, static::$seed->planId, var_export($routers, true), var_export($amount, true));
    }

    private function purchase(string $kind, int $payer, int $target, string $key, string $amount, string $routers = 'wp2_router'): array
    {
        $r = static::$app->exec('try { ' . $this->purchaseCode($kind, $payer, $target, $key, $amount, $routers) . ' } catch (\Reseller\Domain\DomainError $e) { $result = ["error" => $e->reason]; }');
        return $r;
    }

    private function journal(string $key): array
    {
        return static::$db->rows("SELECT id, status, price, trx_invoice FROM tbl_payment_gateway WHERE gateway_trx_id = ? AND payment_method = 'Balance' ORDER BY id", [$key]);
    }

    private function grants(int $customer): int
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$customer]);
    }

    public function testPurchaseIsExactlyOnceAndReplaysWithoutASecondDebitOrGrant(): void
    {
        [$id] = $this->customer2('5000.00');
        $key = 'balance:buy:' . $id . ':' . self::nonce();
        $out = $this->purchase('buy', $id, $id, $key, '1000');
        $this->assertSame(['COMPLETED', false], [$out['status'], $out['replayed']], json_encode($out));
        $this->assertSame('4000.00', $this->balance($id));
        $this->assertSame(1, $this->grants($id));
        $j = $this->journal($key);
        $this->assertSame(['2', '1000.00', $out['invoice']], [(string) $j[0]['status'], $j[0]['price'], $j[0]['trx_invoice']]);
        $again = $this->purchase('buy', $id, $id, $key, '1000');
        $this->assertSame(['COMPLETED', true, $out['journal_id']], [$again['status'], $again['replayed'], $again['journal_id']]);
        $this->assertSame(['4000.00', 1, 1], [$this->balance($id), $this->grants($id), count($this->journal($key))]);
    }

    public function testConcurrentSameKeyDebitsOnce(): void
    {
        for ($round = 1; $round <= 4; $round++) {
            [$id] = $this->customer2('5000.00');
            $key = 'balance:buy:' . $id . ':' . self::nonce();
            $h = [static::$app->execStart($this->purchaseCode('buy', $id, $id, $key, '1000') . '$result = $result["status"];'),
                static::$app->execStart($this->purchaseCode('buy', $id, $id, $key, '1000') . '$result = $result["status"];')];
            $out = array_map(static fn(array $x) => static::$app->execFinish($x), $h);
            sort($out);
            // the second is a replay: COMPLETED, or HELD while the first is still dispatching its grant
            $this->assertContains($out, [['COMPLETED', 'COMPLETED'], ['COMPLETED', 'HELD']], "round {$round}");
            $this->assertSame('2', (string) $this->journal($key)[0]['status']);
            $this->assertSame(['4000.00', 1, 1], [$this->balance($id), $this->grants($id), count($this->journal($key))], "round {$round}");
        }
    }

    public function testInsufficientBalanceWritesNothing(): void
    {
        [$id] = $this->customer2('999.99');
        $key = 'balance:buy:' . $id . ':' . self::nonce();
        $this->assertSame(['error' => 'INSUFFICIENT_BALANCE'], $this->purchase('buy', $id, $id, $key, '1000'));
        $this->assertSame(['999.99', 0, []], [$this->balance($id), $this->grants($id), $this->journal($key)]);
        foreach (['0', '-1', '1e3', '10.001', 'abc', ''] as $bad) {
            $this->assertSame(['error' => 'INVALID_AMOUNT'], $this->purchase('buy', $id, $id, 'balance:buy:' . $id . ':' . self::nonce(), $bad), $bad);
        }
        $this->assertSame('999.99', $this->balance($id));
    }

    public function testProvenPreWriteRefusalIsRefundedOnce(): void
    {
        [$id] = $this->customer2('5000.00');
        $key = 'balance:buy:' . $id . ':' . self::nonce();
        // an empty router is refused by Package::rechargeUser before any write (exact false)
        $out = $this->purchase('buy', $id, $id, $key, '1000', '');
        $this->assertSame('REFUNDED', $out['status']);
        $this->assertSame(['5000.00', 0], [$this->balance($id), $this->grants($id)]);
        $this->assertSame('4', (string) $this->journal($key)[0]['status']);
        $this->assertSame('REFUNDED', $this->purchase('buy', $id, $id, $key, '1000', '')['status'], 'replay');
        $this->assertSame('5000.00', $this->balance($id), 'refunded exactly once');
    }

    public function testInterruptedGrantIsHeldForTheOperatorAndResolvedOnce(): void
    {
        foreach (['completed' => '4000.00', 'refund' => '5000.00'] as $decision => $final) {
            [$id] = $this->customer2('5000.00');
            $key = 'balance:buy:' . $id . ':' . self::nonce();
            touch(static::$app->dir . '/wp2_probe/wp5b_throw.flag');
            $out = $this->purchase('buy', $id, $id, $key, '1000');
            @unlink(static::$app->dir . '/wp2_probe/wp5b_throw.flag');
            $this->assertSame('HELD', $out['status'], $decision);
            $this->assertSame('4000.00', $this->balance($id), 'the money is held, never automatically refunded');
            $this->assertSame('HELD', $this->purchase('buy', $id, $id, $key, '1000')['status'], 'a replay shows HELD: no re-grant');
            $this->assertSame(0, $this->grants($id));
            $held = static::$app->exec('$result = array_column(' . self::S . '::held(), "id");');
            $this->assertContains($out['journal_id'], array_map('intval', $held));
            // final QA F-1: operator and reason are required; the audit record is written with the decision
            $bad = static::$app->exec('try { ' . self::S . '::resolve(' . (int) $out['journal_id'] . ', ' . var_export($decision, true) . ', "", ""); $result = "applied"; } catch (\Reseller\Domain\DomainError $e) { $result = $e->reason; }');
            $this->assertSame('INVALID_INPUT', $bad, 'no anonymous resolution');
            $this->assertSame('4000.00', $this->balance($id), 'refused: nothing changed');
            $r = static::$app->exec('$result = ' . self::S . '::resolve(' . (int) $out['journal_id'] . ', ' . var_export($decision, true) . ", 'qa-operator', 'held grant checked on the NAS');");
            $this->assertSame($decision, $r);
            $this->assertSame($final, $this->balance($id));
            $audit = static::$db->rows("SELECT after_json, reason, channel FROM tbl_reseller_audit_events WHERE event_type = 'balance.resolve' ORDER BY id DESC LIMIT 1");
            $this->assertCount(1, $audit, 'the resolution is audited');
            $this->assertStringContainsString('"operator":"qa-operator"', (string) $audit[0]['after_json']);
            $this->assertSame('held grant checked on the NAS', $audit[0]['reason']);
            $this->assertStringContainsString('"journal_id":' . (int) $out['journal_id'], (string) $audit[0]['after_json']);
            $second = static::$app->exec('try { ' . self::S . '::resolve(' . (int) $out['journal_id'] . ', "refund", "qa-operator", "held grant checked on the NAS"); $result = "applied"; } catch (\Reseller\Domain\DomainError $e) { $result = $e->reason; }');
            $this->assertNotSame('applied', $second, 'resolved exactly once');
            $this->assertSame($final, $this->balance($id));
        }
    }

    public function testResellerOwnedPayerOrTargetIsRefused(): void
    {
        [$owned] = $this->customer2('5000.00', $this->sid('A'));
        [$free] = $this->customer2('5000.00');
        foreach ([[$owned, $owned], [$free, $owned], [$owned, $free]] as [$p, $t]) {
            $this->assertSame(['error' => 'CUSTOMER_RESELLER_OWNED'], $this->purchase('gift', $p, $t, 'balance:gift:' . $p . ':' . self::nonce(), '1000'));
        }
        $this->assertSame(['5000.00', '5000.00'], [$this->balance($owned), $this->balance($free)]);
    }

    private function transferCode(int $sender, string $to, string $amount, string $nonce): string
    {
        return sprintf('try { $result = %s::transfer(%d, %s, %s, %s)["status"]; } catch (\Reseller\Domain\DomainError $e) { $result = $e->reason; }',
            self::S, $sender, var_export($to, true), var_export($amount, true), var_export($nonce, true));
    }

    public function testTransferIsAtomicAndValidated(): void
    {
        [$a, $ua] = $this->customer2('100.00');
        [$b, $ub] = $this->customer2('0.00');
        [$o, $uo] = $this->customer2('0.00', $this->sid('A'));
        $n = self::nonce();
        $this->assertSame('COMPLETED', static::$app->exec($this->transferCode($a, $ub, '40.50', $n)));
        $this->assertSame(['59.50', '40.50'], [$this->balance($a), $this->balance($b)]);
        $this->assertSame('COMPLETED', static::$app->exec($this->transferCode($a, $ub, '40.50', $n)), 'replay');
        $this->assertSame(['59.50', '40.50'], [$this->balance($a), $this->balance($b)], 'one transfer');
        foreach (['0' => 'INVALID_AMOUNT', '-5' => 'INVALID_AMOUNT', '1e2' => 'INVALID_AMOUNT', '5.555' => 'INVALID_AMOUNT', '60' => 'INSUFFICIENT_BALANCE'] as $amt => $why) {
            $this->assertSame($why, static::$app->exec($this->transferCode($a, $ub, (string) $amt, self::nonce())), (string) $amt);
        }
        $this->assertSame('SELF_TARGET', static::$app->exec($this->transferCode($a, $ua, '1', self::nonce())));
        $this->assertSame('CUSTOMER_RESELLER_OWNED', static::$app->exec($this->transferCode($a, $uo, '1', self::nonce())));
        $this->assertSame('CUSTOMER_RESELLER_OWNED', static::$app->exec($this->transferCode($o, $ua, '1', self::nonce())));
        $this->assertSame(['59.50', '40.50', '0.00'], [$this->balance($a), $this->balance($b), $this->balance($o)]);
    }

    public function testConcurrentTransfersNeverOverdraw(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            [$a] = $this->customer2('100.00');
            [$b, $ub] = $this->customer2('0.00');
            [$c, $uc] = $this->customer2('0.00');
            $h = [];
            foreach ([$ub, $uc, $ub, $uc] as $to) {
                $h[] = static::$app->execStart($this->transferCode($a, $to, '40', self::nonce()));
            }
            $out = array_map(static fn(array $x) => static::$app->execFinish($x), $h);
            $this->assertSame(2, count(array_keys($out, 'COMPLETED', true)), "round {$round}: " . json_encode($out));
            $this->assertSame('20.00', $this->balance($a));
            $this->assertSame('100.00', number_format((float) $this->balance($a) + (float) $this->balance($b) + (float) $this->balance($c), 2, '.', ''), 'conserved');
        }
    }

    // ---- fix round 1, blocker 1: the operator resolution never races a live dispatch ----

    private function probe(string $f): string
    {
        return static::$app->dir . '/wp2_probe/' . $f;
    }

    /** Starts a purchase that stops inside its grant dispatch (HELD committed, operation lock held). */
    private function liveDispatch(int $id, string $key): array
    {
        @unlink($this->probe('wp5b_barrier.entered'));
        touch($this->probe('wp5b_barrier.flag'));
        $h = static::$app->execStart($this->purchaseCode('buy', $id, $id, $key, '1000') . '$result = $result["status"];');
        $until = microtime(true) + 20;
        while (!is_file($this->probe('wp5b_barrier.entered')) && microtime(true) < $until) {
            usleep(20000);
            clearstatcache();
        }
        $this->assertFileExists($this->probe('wp5b_barrier.entered'), 'the dispatcher reached the barrier');
        $this->assertSame('5', (string) $this->journal($key)[0]['status'], 'HELD committed before the dispatch');
        return $h;
    }

    private function resolveCode(int $journal, string $decision): string
    {
        return 'try { $result = ' . self::S . '::resolve(' . $journal . ', ' . var_export($decision, true) . ', "qa-operator", "held grant checked on the NAS"); } catch (\Reseller\Domain\DomainError $e) { $result = $e->reason; }';
    }

    /** @return array<string,array{0:string}> */
    public static function decisions(): array
    {
        return ['A: refund' => ['refund'], 'B: completed' => ['completed']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('decisions')]
    public function testOperatorResolutionWaitsForTheLiveDispatcher(string $decision): void
    {
        [$id] = $this->customer2('5000.00');
        $key = 'balance:buy:' . $id . ':' . self::nonce();
        $a = $this->liveDispatch($id, $key);
        $journal = (int) $this->journal($key)[0]['id'];
        $b = static::$app->execStart($this->resolveCode($journal, $decision));
        usleep(1500000);
        $this->assertTrue(proc_get_status($b['proc'])['running'], 'the operator waits on the operation lock');
        $this->assertSame(['5', '4000.00'], [(string) $this->journal($key)[0]['status'], $this->balance($id)], 'nothing resolved meanwhile');
        unlink($this->probe('wp5b_barrier.flag'));
        $this->assertSame('COMPLETED', static::$app->execFinish($a));
        $this->assertSame('ACTIVATION_STATE_CONFLICT', static::$app->execFinish($b), 'the operator re-reads: no longer HELD, nothing changed');
        $this->assertSame(['2', '4000.00', 1], [(string) $this->journal($key)[0]['status'], $this->balance($id), $this->grants($id)], 'one final state; never service + refund');
    }

    public function testAbandonedHeldOperationIsResolvableOnceItsLockIsGone(): void
    {
        [$id] = $this->customer2('5000.00');
        $key = 'balance:buy:' . $id . ':' . self::nonce();
        $a = $this->liveDispatch($id, $key);
        // the dispatcher dies: its connection, and so its operation lock, goes away
        proc_terminate($a['proc'], 9);
        fclose($a['pipes'][1]);
        fclose($a['pipes'][2]);
        proc_close($a['proc']);
        @unlink($a['file']);
        unlink($this->probe('wp5b_barrier.flag'));
        $journal = (int) $this->journal($key)[0]['id'];
        $this->assertSame(['5', '4000.00', 0], [(string) $this->journal($key)[0]['status'], $this->balance($id), $this->grants($id)]);
        $this->assertSame('refund', static::$app->exec($this->resolveCode($journal, 'refund')));
        $this->assertSame(['4', '5000.00', 0], [(string) $this->journal($key)[0]['status'], $this->balance($id), $this->grants($id)]);
    }

    public function testFinalizerThatLosesItsConditionalUpdateReportsThePersistedState(): void
    {
        [$id] = $this->customer2('5000.00');
        $key = 'balance:buy:' . $id . ':' . self::nonce();
        file_put_contents($this->probe('wp5b_override.flag'), $key);
        $out = $this->purchase('buy', $id, $id, $key, '1000');
        $this->assertSame('REFUNDED', $out['status'], 'the persisted state, not a fabricated COMPLETED');
        $this->assertSame('4', (string) $this->journal($key)[0]['status']);
    }
}
