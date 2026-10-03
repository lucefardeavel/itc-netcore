<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\DriftReport;
use Reseller\Tests\Support\ActivationTestCase;

/** WP5B §5 detective control: direct writes that bypass the closure are reported (read only). */
final class DriftReportTest extends ActivationTestCase
{
    /** @return list<array<string,mixed>> findings of one customer */
    private function findings(int $customer): array
    {
        return array_values(array_filter(DriftReport::collect(), static fn(array $f): bool => ($f['customer_id'] ?? null) === $customer));
    }

    public function testACleanActivationHasNoFindingAndDirectWritesAreReported(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $before = static::$db->snapshot();
        $this->assertSame([], $this->findings($c), 'a period granted by a COMPLETED activation is backed');
        $this->assertSame($before, static::$db->snapshot(), 'the report writes nothing');
        // a plugin / direct SQL grants a period and edits RADIUS
        $rid = $this->recharge($c, $plan, '2040-01-01', '00:00:00');
        static::$db->pdo->prepare("UPDATE radcheck SET value = 'plugin-secret' WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$this->username($c)]);
        $kinds = array_column($this->findings($c), 'kind');
        sort($kinds);
        $this->assertSame(['PERIOD_NOT_BACKED', 'RADIUS_DRIFT'], $kinds);
        $drift = array_values(array_filter($this->findings($c), static fn(array $f): bool => $f['kind'] === 'RADIUS_DRIFT'))[0];
        $this->assertSame(['check:Cleartext-Password'], $drift['differs']);
        $this->assertStringNotContainsString('plugin-secret', json_encode(DriftReport::collect()), 'never a secret');
        $this->assertSame($rid, array_values(array_filter($this->findings($c), static fn(array $f): bool => $f['kind'] === 'PERIOD_NOT_BACKED'))[0]['recharge_id']);
        // payment, voucher and balance journal findings
        static::$db->insertRow('tbl_payment_gateway', ['username' => $this->username($c), 'user_id' => $c, 'gateway' => 'x', 'plan_id' => $plan, 'plan_name' => 'p',
            'routers_id' => 0, 'routers' => 'radius', 'price' => '1', 'created_date' => '2026-10-03 00:00:00', 'status' => 2]);
        $v = static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'radius', 'id_plan' => $plan, 'code' => 'DRIFT' . bin2hex(random_bytes(3)), 'user' => '0', 'status' => 'CLAIMED']);
        $all = DriftReport::collect(false);
        $this->assertContains(['kind' => 'VOUCHER_CLAIMED', 'voucher_id' => $v, 'plan_id' => $plan], $all);
        $this->assertNotEmpty(array_filter($all, static fn(array $f): bool => $f['kind'] === 'PAYMENT_FOR_RESELLER_OWNED' && $f['customer_id'] === $c));
        static::$db->exec("DELETE FROM tbl_voucher WHERE id = {$v}");
    }
}
