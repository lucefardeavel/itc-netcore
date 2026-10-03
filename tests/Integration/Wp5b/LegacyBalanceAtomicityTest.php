<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP5B §14 (owner-approved Balance.php delta): the legacy Balance writers are single atomic relative updates;
 * plusByPhone / minByPhone resolve exactly ONE customer id by username (the username is not unique) and update
 * by id; a legacy credit can never overwrite a concurrent hardened (locked) debit.
 */
final class LegacyBalanceAtomicityTest extends Wp2HttpTestCase
{
    private function customer2(string $balance, ?string $username = null): array
    {
        $u = $username ?? 'ba' . bin2hex(random_bytes(4));
        $id = static::$db->insertRow('tbl_customers', ['username' => $u, 'password' => 'pw', 'fullname' => $u, 'address' => '', 'phonenumber' => '0700',
            'email' => $u . '@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
            'balance' => $balance, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0]);
        return [$id, $u];
    }

    private function balance(int $id): string
    {
        return (string) static::$db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$id]);
    }

    public function testDuplicateUsernamesChangeAtMostOneRow(): void
    {
        [$first, $u] = $this->customer2('10.00');
        [$second] = $this->customer2('10.00', $u);
        $this->assertTrue(static::$app->exec('$result = Balance::plusByPhone(' . var_export($u, true) . ', 5);'));
        $this->assertSame(['15.00', '10.00'], [$this->balance($first), $this->balance($second)], 'only the lowest id');
        $this->assertTrue(static::$app->exec('$result = Balance::minByPhone(' . var_export($u, true) . ', 15);'));
        $this->assertSame(['0.00', '10.00'], [$this->balance($first), $this->balance($second)]);
        $this->assertFalse(static::$app->exec('$result = Balance::minByPhone(' . var_export($u, true) . ', 1);'), 'insufficient stays false');
        $this->assertSame(['0.00', '10.00'], [$this->balance($first), $this->balance($second)]);
        $this->assertTrue(static::$app->exec('$result = Balance::minByPhone(' . var_export($u, true) . ', 0);'), 'zero amount covered (legacy)');
        $this->assertFalse(static::$app->exec('$result = Balance::plusByPhone("no-such-user-x", 5);'));
        $this->assertFalse(static::$app->exec('$result = Balance::minByPhone("no-such-user-x", 5);'));
        // the legacy return values of plus / min are unchanged (null / true, no floor on min)
        $this->assertSame([null, true], static::$app->exec('$result = [Balance::plus(' . $second . ', 1), Balance::min(' . $second . ', 20)];'));
        $this->assertSame('-9.00', $this->balance($second));
    }

    public function testLegacyCreditNeverOverwritesAConcurrentHardenedDebit(): void
    {
        $S = '\Reseller\Domain\LegacyCustomerBalanceService';
        for ($round = 1; $round <= 6; $round++) {
            [$a] = $this->customer2('100.00');
            [, $ub] = $this->customer2('0.00');
            $credit = $round % 2 === 0 ? 'Balance::plus(' . $a . ', 7);' : 'Balance::plusByPhone(' . var_export((string) static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$a]), true) . ', 7);';
            $h = [];
            for ($i = 0; $i < 3; $i++) {
                $h[] = static::$app->execStart(sprintf('$result = %s::transfer(%d, %s, "10", %s)["status"];', $S, $a, var_export($ub, true), var_export(bin2hex(random_bytes(12)), true)));
                $h[] = static::$app->execStart($credit . '$result = "credited";');
            }
            $out = array_map(static fn(array $x) => static::$app->execFinish($x), $h);
            $this->assertSame(3, count(array_keys($out, 'COMPLETED', true)), json_encode($out));
            $this->assertSame('91.00', $this->balance($a), "round {$round}: 100 - 3 x 10 + 3 x 7, no lost debit or credit");
        }
    }
}
