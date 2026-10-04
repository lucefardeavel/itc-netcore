<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationPreviewService;
use Reseller\Activation\ActivationService;
use Reseller\Activation\PreviewBinding;
use Reseller\Finance\PricingService;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceSeed as Seed;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * WP6A (owner decision OD-B): the live activation confirmation and its staleness guarantee. The preview is
 * read-only and role-filtered (Sales: retail + "credit sufficient" only); a confirmation bound to a quote that no
 * longer matches the authoritative one is refused with ACTIVATION_PREVIEW_CHANGED before anything is written.
 */
final class ActivationPreviewTest extends ActivationTestCase
{
    private static function key(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return array<string,int> */
    private function sale(string $fund = '1000'): array
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        if ($fund !== '1000') {
            $plan = $this->radiusPlan('20');
            $this->base($plan, '10');
            [$top, $leaf] = $this->chain($plan, ['12', '15'], $fund);
        }
        $sales = Seed::staff(static::$db, 'sl' . bin2hex(random_bytes(3)), $leaf, 'Active', true, 'Sales');
        return ['plan' => $plan, 'top' => $top, 'leaf' => $leaf, 'sales' => $sales, 'c' => $this->customer($leaf, $sales)];
    }

    private function confirm(string|int $actor, array $s, ?PreviewBinding $binding, ?string $nonce = null): array
    {
        return ActivationService::create($this->ctx($actor), $s['c'], $s['plan'], 'NEW', $nonce ?? self::nonce(), $binding);
    }

    public function testQuoteValuesAndRoleVisibilityWriteNothing(): void
    {
        $s = $this->sale();
        $key = self::key();
        $before = $this->checksums();
        $admin = ActivationPreviewService::quote($this->ctx('ad'), $s['c'], $s['plan'], $key);
        $agent = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        $sales = ActivationPreviewService::quote($this->ctx($s['sales']), $s['c'], $s['plan'], $key);
        $this->assertSame([], \Reseller\Tests\Support\DbProbe::changed($before, $this->checksums()), 'a preview writes nothing');
        foreach ([$admin, $agent] as $q) {
            $this->assertSame(['NEW', '15.00', '20.00', '1000.00', '985.00', true, true],
                [$q['action'], $q['wholesale'], $q['retail'], $q['available_before'], $q['available_after'], $q['credit_sufficient'], $q['full']]);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $q['fingerprint']);
        }
        $this->assertSame($admin['fingerprint'], $agent['fingerprint'], 'the same material quote');
        $this->assertSame((string) static::$db->value('SELECT username FROM tbl_users WHERE id = ?', [$s['leaf']]), $admin['payer']);
        foreach (['wholesale', 'available_before', 'available_after', 'payer'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $sales, "Sales never sees {$hidden}");
        }
        $this->assertSame(['20.00', true, false], [$sales['retail'], $sales['credit_sufficient'], $sales['full']]);
        $this->assertStringNotContainsString('15.00', (string) json_encode($sales), 'no wholesale value in any Sales field');
        $this->expectExceptionReason('NOT_FOUND', fn() => ActivationPreviewService::quote($this->ctx($s['top']), $s['c'], $s['plan'], $key), 'an ancestor never spends the child wallet (A5)');
        $this->expectExceptionReason('FORBIDDEN', fn() => ActivationPreviewService::quote($this->ctx('rp'), $s['c'], $s['plan'], $key));
    }

    public function testConfirmedQuoteExecutesAndChangedMaterialIsRefusedBeforeAnyWrite(): void
    {
        $s = $this->sale();
        $key = self::key();
        $q = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        // the leaf's own wholesale changes after the preview
        PricingService::setChildWholesale($this->ctx($s['top']), $s['leaf'], $s['plan'], '16');
        $this->refused('ACTIVATION_PREVIEW_CHANGED', fn() => $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], $key)));
        // the retail changes
        $q = ActivationPreviewService::quote($this->ctx($s['sales']), $s['c'], $s['plan'], $key);
        PricingService::setRetail($this->ctx($s['leaf']), $s['leaf'], $s['plan'], '25');
        $this->refused('ACTIVATION_PREVIEW_CHANGED', fn() => $this->confirm($s['sales'], $s, PreviewBinding::of($q['fingerprint'], $key)), 'Sales: retail changed');
        // the plan's period definition changes
        $q = ActivationPreviewService::quote($this->ctx('ad'), $s['c'], $s['plan'], $key);
        static::$db->exec("UPDATE tbl_plans SET validity = 60 WHERE id = {$s['plan']}");
        $this->refused('ACTIVATION_PREVIEW_CHANGED', fn() => $this->confirm('ad', $s, PreviewBinding::of($q['fingerprint'], $key)), 'period basis changed');
        // a fresh review of the updated quote executes, at the new price
        $q = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        $this->assertSame(['16.00', '25.00'], [$q['wholesale'], $q['retail']]);
        $out = $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], $key));
        $this->assertSame('COMPLETED', $out['status']);
        $this->assertSame('16.00', (string) static::$db->value('SELECT wholesale_cost FROM tbl_reseller_activations WHERE reference = ?', [$out['reference']]));
    }

    public function testNonMaterialChangesKeepTheQuote(): void
    {
        $s = $this->sale();
        $key = self::key();
        $q = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        $this->fund($s['leaf'], '50'); // available credit is re-checked by the reservation, not part of the quote
        PricingService::setChildWholesale($this->ctx('ad'), $s['top'], $s['plan'], '13'); // upstream only: the leaf still pays 15, retail 20
        $out = $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], $key));
        $this->assertSame('COMPLETED', $out['status']);
    }

    public function testMissingForgedOrForeignFingerprintsAreRefused(): void
    {
        $s = $this->sale();
        $key = self::key();
        $q = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        foreach (['', null, 'zz', str_repeat('0', 64), strtoupper($q['fingerprint']), [$q['fingerprint']]] as $forged) {
            $this->refused('ACTIVATION_PREVIEW_CHANGED', fn() => $this->confirm($s['leaf'], $s, PreviewBinding::of($forged, $key)), 'forged ' . json_encode($forged));
        }
        $this->refused('ACTIVATION_PREVIEW_CHANGED', fn() => $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], self::key())), 'another session key');
        $this->refused('ACTIVATION_PREVIEW_CHANGED', fn() => $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], 'short')), 'unusable key');
    }

    public function testReplayReturnsTheSameActivationWhateverChangedLater(): void
    {
        $s = $this->sale();
        $key = self::key();
        $nonce = self::nonce();
        $q = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        $first = $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], $key), $nonce);
        PricingService::setChildWholesale($this->ctx($s['top']), $s['leaf'], $s['plan'], '16');
        $again = $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], $key), $nonce);
        $this->assertSame([$first['reference'], true], [$again['reference'], $again['replayed']], 'the same operation, nothing new');
        $this->assertSame(1, $this->rowCount('tbl_reseller_activations', 'customer_id = ?', [$s['c']]));
    }

    public function testInsufficientCreditIsAYesNoForSales(): void
    {
        $s = $this->sale('10');
        $key = self::key();
        $agent = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        $sales = ActivationPreviewService::quote($this->ctx($s['sales']), $s['c'], $s['plan'], $key);
        $this->assertSame(['10.00', '-5.00', false], [$agent['available_before'], $agent['available_after'], $agent['credit_sufficient']]);
        $this->assertFalse($sales['credit_sufficient']);
        $this->assertArrayNotHasKey('available_after', $sales);
        $this->refused('INSUFFICIENT_AVAILABLE_CREDIT', fn() => $this->confirm($s['sales'], $s, PreviewBinding::of($sales['fingerprint'], $key)), 'the reservation stays authoritative');
    }

    /** Gives the plan a bandwidth row of its own (the seeded one is shared) and returns its id. */
    private function ownBandwidth(int $plan, array $values = []): int
    {
        $bw = static::$db->insertRow('tbl_bandwidth', $values + ['name_bw' => 'wp6a_' . bin2hex(random_bytes(3)), 'rate_down' => 10, 'rate_down_unit' => 'Mbps', 'rate_up' => 5, 'rate_up_unit' => 'Mbps', 'burst' => '']);
        static::$db->pdo->prepare('UPDATE tbl_plans SET id_bw = ? WHERE id = ?')->execute([$bw, $plan]);
        return (int) $bw;
    }

    /** Confirms an old quote after a service-term change: ACTIVATION_PREVIEW_CHANGED and nothing written anywhere. */
    private function assertStaleQuoteRefused(array $s, array $q, string $key, string $what): void
    {
        $counts = fn(): array => [
            'activation' => $this->rowCount('tbl_reseller_activations', 'customer_id = ?', [$s['c']]),
            'reservation' => $this->rowCount('tbl_manager_wallet_reservations'),
            'ledger' => $this->rowCount('tbl_manager_ledger'),
            'recharge' => $this->rowCount('tbl_user_recharges', 'customer_id = ?', [$s['c']]),
            'transaction' => $this->rowCount('tbl_transactions'),
            'radcheck' => $this->rowCount('radcheck'), 'radreply' => $this->rowCount('radreply'), 'radgroupreply' => $this->rowCount('radgroupreply'),
        ];
        $before = $counts();
        $wallets = static::$db->rows('SELECT manager_id, balance FROM tbl_manager_wallets ORDER BY manager_id');
        // refused() also asserts that the checksum of every table (RADIUS tables included) is unchanged
        $this->refused('ACTIVATION_PREVIEW_CHANGED', fn() => $this->confirm($s['leaf'], $s, PreviewBinding::of($q['fingerprint'], $key)), $what);
        $this->assertSame(0, $before['activation'], "{$what}: no earlier activation");
        $this->assertSame($before, $counts(), "{$what}: no activation, reservation, ledger, legacy billing or RADIUS row");
        $this->assertSame($wallets, static::$db->rows('SELECT manager_id, balance FROM tbl_manager_wallets ORDER BY manager_id'), "{$what}: no wallet change");
    }

    /** @return array<string,array{0:string,1:string}> service-affecting plan column => SQL expression of a different value */
    public static function planTerms(): array
    {
        return [
            'id_bw' => ['id_bw', '@other'],
            'typebp' => ['typebp', "IF(typebp = 'Limited', 'Unlimited', 'Limited')"],
            'limit_type' => ['limit_type', "IF(limit_type = 'Data_Limit', 'Time_Limit', 'Data_Limit')"],
            'time_limit' => ['time_limit', 'COALESCE(time_limit, 0) + 7'],
            'time_unit' => ['time_unit', "IF(time_unit = 'Mins', 'Hrs', 'Mins')"],
            'data_limit' => ['data_limit', 'COALESCE(data_limit, 0) + 9'],
            'data_unit' => ['data_unit', "IF(data_unit = 'MB', 'GB', 'MB')"],
            'shared_users' => ['shared_users', 'COALESCE(shared_users, 1) + 2'],
            'pool' => ['pool', "CONCAT(COALESCE(pool, ''), 'x')"],
        ];
    }

    /** OD-B fix round 1: a change of the selected service's terms invalidates the quote although price and validity stay. */
    #[DataProvider('planTerms')]
    public function testAChangedPlanServiceTermRefusesTheOldQuote(string $column, string $expression): void
    {
        $s = $this->sale();
        $this->ownBandwidth($s['plan']);
        $key = self::key();
        $q = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        if ($expression === '@other') {
            $other = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6a_other', 'rate_down' => 50, 'rate_down_unit' => 'Mbps', 'rate_up' => 20, 'rate_up_unit' => 'Mbps', 'burst' => '']);
            $expression = (string) (int) $other;
        }
        $before = static::$db->value("SELECT {$column} FROM tbl_plans WHERE id = ?", [$s['plan']]);
        static::$db->exec("UPDATE tbl_plans SET {$column} = {$expression} WHERE id = {$s['plan']}");
        $this->assertNotSame($before, static::$db->value("SELECT {$column} FROM tbl_plans WHERE id = ?", [$s['plan']]), "{$column} changed");
        $this->assertStaleQuoteRefused($s, $q, $key, "plan {$column}");
        $now = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        $this->assertSame([$q['action'], $q['wholesale'], $q['retail'], $q['validity'], $q['validity_unit']],
            [$now['action'], $now['wholesale'], $now['retail'], $now['validity'], $now['validity_unit']], 'price, action and validity unchanged');
        $this->assertNotSame($q['fingerprint'], $now['fingerprint'], 'a new review shows the new terms');
    }

    /** @return array<string,array{0:string}> RADIUS rate columns => SQL assignment of a different value */
    public static function rateTerms(): array
    {
        return [
            'rate_up' => ['rate_up = rate_up + 1'],
            'rate_up_unit' => ["rate_up_unit = IF(rate_up_unit = 'Kbps', 'Mbps', 'Kbps')"],
            'rate_down' => ['rate_down = rate_down + 1'],
            'rate_down_unit' => ["rate_down_unit = IF(rate_down_unit = 'Kbps', 'Mbps', 'Kbps')"],
            'burst' => ["burst = '20M/10M 15M/8M 16/16'"],
        ];
    }

    /** OD-B fix round 1: the live bandwidth row turned into the RADIUS rate policy is part of the reviewed terms. */
    #[DataProvider('rateTerms')]
    public function testAChangedBandwidthRateRefusesTheOldQuote(string $assignment): void
    {
        $s = $this->sale();
        $bw = $this->ownBandwidth($s['plan']);
        $key = self::key();
        $q = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        static::$db->exec("UPDATE tbl_bandwidth SET {$assignment} WHERE id = {$bw}");
        $this->assertStaleQuoteRefused($s, $q, $key, "bandwidth {$assignment}");
        $now = ActivationPreviewService::quote($this->ctx($s['leaf']), $s['c'], $s['plan'], $key);
        $this->assertSame([$q['action'], $q['wholesale'], $q['retail'], $q['validity'], $q['validity_unit']],
            [$now['action'], $now['wholesale'], $now['retail'], $now['validity'], $now['validity_unit']], 'price, action and validity unchanged');
        $this->assertNotSame($q['fingerprint'], $now['fingerprint'], 'a new review shows the new rate');
    }

    /** Presentation-only renames (plan name, bandwidth name) are not service terms: the reviewed quote still confirms. */
    public function testPresentationOnlyRenamesKeepTheQuote(): void
    {
        $s = $this->sale();
        $bw = $this->ownBandwidth($s['plan']);
        $key = self::key();
        $q = ActivationPreviewService::quote($this->ctx($s['sales']), $s['c'], $s['plan'], $key);
        static::$db->pdo->prepare('UPDATE tbl_plans SET name_plan = ? WHERE id = ?')->execute(['renamed ' . bin2hex(random_bytes(3)), $s['plan']]);
        static::$db->pdo->prepare('UPDATE tbl_bandwidth SET name_bw = ? WHERE id = ?')->execute(['renamed_bw', $bw]);
        $out = $this->confirm($s['sales'], $s, PreviewBinding::of($q['fingerprint'], $key));
        $this->assertSame('COMPLETED', $out['status']);
    }

    /** Drift guard: every plan / bandwidth field the service period or the RADIUS policy consumes is a bound service term. */
    public function testEveryServiceTermThePolicyConsumesIsBound(): void
    {
        $root = dirname(__DIR__, 3) . '/system/autoload/Reseller';
        $read = static function (string $file, string $var): array {
            preg_match_all('/\$' . $var . '\[\'([a-z_]+)\'\]/', (string) file_get_contents($file), $m);
            return array_values(array_unique($m[1]));
        };
        $planKeys = array_merge($read("{$root}/Radius/RadiusPolicyBuilder.php", 'plan'), $read("{$root}/Activation/ServicePeriod.php", 'plan'));
        $bound = array_diff(\Reseller\Finance\PricingSnapshot::PLAN_COLUMNS, ActivationPreviewService::NON_MATERIAL_PLAN_COLUMNS);
        $this->assertNotEmpty($planKeys);
        $this->assertSame([], array_values(array_diff($planKeys, $bound)), 'plan fields consumed but not bound');
        $rateKeys = $read("{$root}/Radius/RadiusPolicyBuilder.php", 'bandwidth');
        $this->assertNotEmpty($rateKeys);
        $this->assertSame([], array_values(array_diff($rateKeys, ActivationPreviewService::RATE_COLUMNS)), 'bandwidth fields consumed but not bound');
        $this->assertSame(['name_plan', 'price'], ActivationPreviewService::NON_MATERIAL_PLAN_COLUMNS, 'only presentation is unbound');
    }
}
