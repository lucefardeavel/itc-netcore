<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP5B §8 / §7a.3 / OD-3: cron is CLI-only (a web request gets an empty 404 and changes nothing; the
 * .htaccess allow is gone); a reseller-owned customer is never auto-renewed (expiry enforced, no activation,
 * no wallet or balance debit, one AUTO_RENEW_UNAVAILABLE record per obligation); an unassigned auto-renew is
 * debited and granted exactly once per obligation, a HELD obligation is never retried blindly.
 */
final class CronHardeningTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (['enable_balance' => 'yes'] as $k => $v) {
            if ((int) static::$db->value('SELECT COUNT(*) FROM tbl_appconfig WHERE setting = ?', [$k]) === 0) {
                static::$db->insertRow('tbl_appconfig', ['setting' => $k, 'value' => $v]);
            } else {
                static::$db->pdo->prepare('UPDATE tbl_appconfig SET value = ? WHERE setting = ?')->execute([$v, $k]);
            }
        }
    }

    private function expire(string $customer, string $expiration): int
    {
        $rid = static::$seed->recharges[$customer] ??= static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $this->cid($customer), 'username' => $customer, 'plan_id' => static::$seed->planId, 'namebp' => 'wp2_plan',
            'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => $expiration, 'time' => '00:00:00',
            'status' => 'on', 'method' => 'Admin - seed', 'routers' => 'wp2_router', 'type' => 'Hotspot', 'admin_id' => $this->sid('ad'),
        ]);
        static::$db->pdo->prepare("UPDATE tbl_user_recharges SET expiration = ?, time = '00:00:00', status = 'on' WHERE id = ?")->execute([$expiration, $rid]);
        static::$db->pdo->prepare('UPDATE tbl_customers SET auto_renewal = 1, balance = 5000 WHERE id = ?')->execute([$this->cid($customer)]);
        return $rid;
    }

    private function cron(): string
    {
        [$exit, $out] = static::$app->run(['cron.php'], 'system');
        $this->assertSame(0, $exit, $out);
        return $out;
    }

    private function balance(string $customer): string
    {
        return (string) static::$db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$this->cid($customer)]);
    }

    public function testCronIsNotReachableOverTheWeb(): void
    {
        $before = $this->snapshot();
        foreach (['GET', 'POST'] as $m) {
            $r = $this->client()->raw($m, '/system/cron.php', $m === 'POST' ? ['x' => '1'] : null);
            $this->assertSame([404, ''], [$r->status, trim($r->body)], $m);
        }
        $this->assertUnchanged($before, 'web cron');
        $ht = (string) file_get_contents(dirname(__DIR__, 3) . '/system/.htaccess');
        $this->assertStringNotContainsString('<Files cron.php>', $ht);
        $this->assertStringContainsString('<Files cron_reminder.php>', $ht, 'other entries unchanged');
        $this->assertMatchesRegularExpression("/^<\\?php\\s*(\\/\\/[^\\n]*\\n\\s*)*if \\(PHP_SAPI !== 'cli'\\) \\{ http_response_code\\(404\\); exit; \\}/", (string) file_get_contents(dirname(__DIR__, 3) . '/system/cron.php'));
    }

    public function testResellerOwnedAutoRenewIsUnavailableExactlyOncePerObligation(): void
    {
        $exp = gmdate('Y-m-d', time() - 86400 * 3);
        $rid = $this->expire('cA1', $exp);
        $wallets = static::$db->rows('SELECT manager_id, balance FROM tbl_manager_wallets ORDER BY manager_id');
        $ledger = (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger');
        $acts = (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations');
        $trx = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cA1')]);
        foreach ([1, 2, 3] as $run) {
            $out = $this->cron();
            $this->assertStringContainsString('AUTO_RENEW_UNAVAILABLE', $out, "run {$run}");
            $this->assertSame('off', (string) static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$rid]), 'expiry enforced');
            // the same obligation seen again (e.g. the expiry write was undone by a failed run)
            static::$db->pdo->prepare("UPDATE tbl_user_recharges SET status = 'on' WHERE id = ?")->execute([$rid]);
        }
        $this->assertSame('5000.00', $this->balance('cA1'), 'no customer-balance debit');
        $this->assertSame($wallets, static::$db->rows('SELECT manager_id, balance FROM tbl_manager_wallets ORDER BY manager_id'), 'no wallet change');
        $this->assertSame([$ledger, $acts], [(int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger'), (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations')]);
        $this->assertSame($trx, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cA1')]));
        $this->assertCount(1, static::$db->rows("SELECT id FROM tbl_reseller_audit_events WHERE event_type = 'service.auto_renew_unavailable' AND target_customer_id = ?", [$this->cid('cA1')]));
        static::$db->pdo->prepare("UPDATE tbl_user_recharges SET status = 'off' WHERE id = ?")->execute([$rid]);
    }

    public function testUnassignedAutoRenewIsExactlyOncePerObligation(): void
    {
        $exp = gmdate('Y-m-d', time() - 86400 * 2);
        $rid = $this->expire('cNULL', $exp);
        $price = (float) static::$db->value('SELECT price FROM tbl_plans WHERE id = ?', [static::$seed->planId]);
        $trx = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL')]);
        $out = $this->cron();
        $this->assertStringContainsString('auto renewal COMPLETED', $out);
        $after = $this->balance('cNULL');
        $this->assertSame(number_format(5000 - $price, 2, '.', ''), $after);
        $this->assertSame($trx + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL')]));
        $key = 'balance:cron:' . $rid . ':' . $exp . 'T00:00:00';
        $this->assertSame(['2'], array_map('strval', array_column(static::$db->rows("SELECT status FROM tbl_payment_gateway WHERE gateway_trx_id = ?", [$key]), 'status')));
        // a repeated / overlapping run on the same obligation: replay, no second debit, grant or extension
        static::$db->pdo->prepare("UPDATE tbl_user_recharges SET expiration = ?, time = '00:00:00', status = 'on' WHERE id = ?")->execute([$exp, $rid]);
        $out = $this->cron();
        $this->assertStringContainsString('already processed', $out);
        $this->assertSame($after, $this->balance('cNULL'));
        $this->assertSame($trx + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL')]));
    }

    public function testHeldObligationIsSkippedAndNeverRetried(): void
    {
        $exp = gmdate('Y-m-d', time() - 86400 * 4);
        $rid = $this->expire('cNULL2', $exp);
        // a crashed earlier run left the obligation HELD (debited, grant uncertain)
        static::$db->insertRow('tbl_payment_gateway', ['username' => 'cNULL2', 'user_id' => $this->cid('cNULL2'), 'gateway' => 'Balance',
            'gateway_trx_id' => 'balance:cron:' . $rid . ':' . $exp . 'T00:00:00', 'plan_id' => static::$seed->planId, 'plan_name' => 'p', 'routers_id' => 0,
            'routers' => 'wp2_router', 'price' => '1000.00', 'payment_method' => 'Balance', 'payment_channel' => 'Auto Renew', 'created_date' => date('Y-m-d H:i:s'), 'status' => 5]);
        $trx = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL2')]);
        $out = $this->cron();
        $this->assertStringContainsString('auto renewal HELD (already processed)', $out);
        $this->assertSame('5000.00', $this->balance('cNULL2'), 'no second debit');
        $this->assertSame($trx, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL2')]), 'no blind grant');
    }
}
