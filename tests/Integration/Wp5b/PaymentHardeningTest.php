<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP5B §7 payment core hardening for unassigned customers (OD-2): one gateway resolver (SEC-13), no posted
 * discount (SEC-14), conditional core transitions (a paid row is never cancelled, the plugin status check runs
 * only while unpaid), the customer-balance pseudo gateway never resolvable. A test-only fixture gateway
 * (disposable app copy) records its calls.
 */
final class PaymentHardeningTest extends Wp2HttpTestCase
{
    private const GATEWAY = <<<'PHP'
<?php
// test-only payment gateway (disposable app copy only)
function wp5bpay_log($v) { @file_put_contents(__DIR__ . '/../../wp2_probe/gateway.log', json_encode($v) . "\n", FILE_APPEND | LOCK_EX); }
function wp5bpay_validate_config() {}
function wp5bpay_create_transaction($trx, $user) { wp5bpay_log(['create', (int) $trx['id'], (string) $trx['price']]); r2(getUrl('order/view/' . $trx['id']), 's', 'created'); }
function wp5bpay_get_status($trx, $user) { wp5bpay_log(['status', (int) $trx['id']]); r2(getUrl('order/view/' . $trx['id']), 's', 'checked'); }
function wp5bpay_payment_notification() { wp5bpay_log(['notify']); echo 'WP5BPAY_NOTIFIED'; }
PHP;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $dir = static::$app->dir . '/system/paymentgateway';
        @mkdir($dir, 0777, true);
        foreach (['wp5bpay', 'unlisted'] as $g) {
            file_put_contents($dir . '/' . $g . '.php', str_replace('wp5bpay', $g, self::GATEWAY));
        }
        file_put_contents(static::$app->dir . '/system/Balance.php', "<?php echo 'BALANCE_PSEUDO_GATEWAY_INCLUDED';");
        $db = static::$db;
        foreach (['payment_gateway' => 'wp5bpay,Balance,unlisted_missing', 'enable_coupons' => ''] as $k => $v) {
            if ((int) $db->value('SELECT COUNT(*) FROM tbl_appconfig WHERE setting = ?', [$k]) === 0) {
                $db->insertRow('tbl_appconfig', ['setting' => $k, 'value' => $v]);
            } else {
                $db->pdo->prepare('UPDATE tbl_appconfig SET value = ? WHERE setting = ?')->execute([$v, $k]);
            }
        }
    }

    private function calls(): array
    {
        return static::$app->probe('gateway');
    }

    private function rows(): array
    {
        return static::$db->rows('SELECT id, gateway, price, status FROM tbl_payment_gateway WHERE user_id = ? ORDER BY id', [$this->cid('cNULL')]);
    }

    private function buy(array $post): \Reseller\Tests\Support\HttpResponse
    {
        return $this->customer('cNULL')->postWithToken('order/buy/0/' . static::$seed->planId, $post, [], 'order/package');
    }

    public function testOnlyAConfiguredGatewayInsideTheDirectoryIsUsed(): void
    {
        static::$app->clearProbes();
        $before = $this->rows();
        foreach (['../../system/Balance', 'unlisted', 'Balance', 'unlisted_missing', 'wp5bpay.php', 'WP5BPAY/..', str_repeat('a', 65), ''] as $g) {
            $r = $this->buy(['gateway' => $g]);
            $this->assertNotSame(500, $r->status, $g);
            $this->assertStringNotContainsString('BALANCE_PSEUDO_GATEWAY_INCLUDED', $r->body, $g);
        }
        $this->assertSame([], $this->calls(), 'no plugin code ran');
        $this->assertSame($before, $this->rows(), 'no transaction row');
        foreach (['unlisted', 'Balance', '..%2F..%2Fsystem%2FBalance'] as $g) {
            $r = $this->client()->get('callback/' . $g);
            $this->assertSame(404, $r->status, $g);
        }
        $r = $this->client()->get('callback/wp5bpay');
        $this->assertStringContainsString('WP5BPAY_NOTIFIED', $r->body, 'a configured gateway is dispatched');
    }

    public function testPostedDiscountIsIgnored(): void
    {
        static::$app->clearProbes();
        $price = (string) static::$db->value('SELECT price FROM tbl_plans WHERE id = ?', [static::$seed->planId]);
        $this->buy(['gateway' => 'wp5bpay', 'discount' => (string) ((int) $price - 1)]);
        $rows = $this->rows();
        $last = end($rows);
        $this->assertSame('wp5bpay', $last['gateway']);
        $this->assertSame((float) $price, (float) $last['price'], 'the forged discount did not reduce the charge');
        $this->assertSame([['create', (int) $last['id'], $last['price']]], $this->calls());
    }

    public function testAPaidRowIsNeverCancelledOrCheckedAgain(): void
    {
        $id = static::$db->insertRow('tbl_payment_gateway', ['username' => 'cNULL', 'user_id' => $this->cid('cNULL'), 'gateway' => 'wp5bpay', 'plan_id' => static::$seed->planId,
            'plan_name' => 'p', 'routers_id' => 0, 'routers' => 'wp2_router', 'price' => '1000', 'pg_url_payment' => 'http://pay.invalid/x', 'created_date' => date('Y-m-d H:i:s'),
            'status' => 2, 'paid_date' => date('Y-m-d H:i:s'), 'trx_invoice' => 'INV-PAID']);
        static::$app->clearProbes();
        $c = $this->customer('cNULL');
        foreach (['cancel', 'check'] as $step) {
            $c->get('order/view/' . $id . '/' . $step);
            $c->postWithToken('order/view/' . $id . '/' . $step, [], [], 'order/package');
            $this->assertSame('2', (string) static::$db->value('SELECT status FROM tbl_payment_gateway WHERE id = ?', [$id]), "{$step}: still paid");
        }
        $this->assertSame([], $this->calls(), 'the plugin status check never ran for a paid row');
        // an unpaid row can still be cancelled, exactly once
        static::$db->pdo->prepare('UPDATE tbl_payment_gateway SET status = 1 WHERE id = ?')->execute([$id]);
        $c->postWithToken('order/view/' . $id . '/cancel', [], [], 'order/package');
        $c->get('order/view/' . $id . '/cancel');
        $this->assertSame('4', (string) static::$db->value('SELECT status FROM tbl_payment_gateway WHERE id = ?', [$id]));
    }

    public function testResolverUnit(): void
    {
        $dir = static::$app->dir . '/system/paymentgateway';
        $res = static::$app->exec('$cfg = ["payment_gateway" => "wp5bpay,Balance,unlisted_missing"]; $d = ' . var_export($dir, true) . ';'
            . '$result = array_map(fn($n) => \Reseller\Domain\PaymentGatewayResolver::resolve($n, $cfg, $d) === null ? null : basename(\Reseller\Domain\PaymentGatewayResolver::resolve($n, $cfg, $d)),'
            . ' ["wp5bpay", "Balance", "balance", "unlisted", "unlisted_missing", "../wp5bpay", "wp5bpay\0", 5, null]);');
        $this->assertSame(['wp5bpay.php', null, null, null, null, null, null, null, null], $res);
    }
}
