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
}
