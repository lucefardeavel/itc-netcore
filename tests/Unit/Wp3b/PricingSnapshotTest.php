<?php
namespace Reseller\Tests\Unit\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\DomainError;
use Reseller\Finance\PricingSnapshot;
use Reseller\Tests\Support\SnapshotFactory;

/**
 * WP3B §6 / F-8: the internal snapshot is complete, canonical (byte-stable) and immutable; decoding an
 * activation row re-verifies every invariant and refuses any tampered, partial or non-canonical form.
 */
final class PricingSnapshotTest extends TestCase
{
    private static function invalid(callable $fn, string $message): void
    {
        try {
            $fn();
            self::fail('expected PRICING_SNAPSHOT_INVALID: ' . $message);
        } catch (DomainError $e) {
            self::assertSame('PRICING_SNAPSHOT_INVALID', $e->reason, $message);
        }
    }

    public function testCanonicalBytesAndCompleteness(): void
    {
        $s = SnapshotFactory::seal('10', ['12', '15', '17'], '20');
        $again = SnapshotFactory::seal('10', ['12', '15', '17'], '20');
        $this->assertSame($s->chainJson(), $again->chainJson(), 'deterministic encoding');
        $this->assertTrue($s->sameTermsAs($again));
        $d = json_decode($s->chainJson(), true);
        $this->assertSame(['v', 'plan_id', 'leaf_manager_id', 'resolved_at', 'provider', 'chain', 'retail', 'availability', 'settlement'], array_keys($d));
        $this->assertSame('10.00', $d['provider']['base_cost'], 'provider economics stay in the internal snapshot');
        $this->assertSame([12, 15, 17], array_map(static fn($l) => (int) $l['effective_wholesale'], $d['chain']));
        $this->assertSame(['3.00', '2.00', '0.00'], array_column($d['chain'], 'margin'), 'zero margins are kept for audit');
        $this->assertSame(['12.00', '2.00'], [$d['settlement']['provider_settlement'], $d['settlement']['provider_margin_over_base']]);
        $this->assertSame([['manager_id' => 102, 'amount' => '2.00'], ['manager_id' => 101, 'amount' => '3.00']], $d['settlement']['commissions']);
        $this->assertSame(['effective' => '20.00', 'source' => 'OVERRIDE', 'legacy_raw' => '1000', 'set_by' => 103, 'set_at' => SnapshotFactory::STAMP], $d['retail']);
        $this->assertSame(['effective' => true, 'decided_by' => 'DEFAULT'], $d['availability']);
        foreach ($d['chain'] as $l) {
            $this->assertIsString($l['effective_wholesale'], 'money is always a string');
        }
        $this->assertStringNotContainsString('": ', $s->chainJson(), 'compact encoding');
        $this->assertStringNotContainsString(', "', $s->chainJson(), 'compact encoding');
        $this->assertStringNotContainsString("\n", $s->chainJson(), 'compact encoding');
        // a later resolution with another timestamp has the same terms
        $row = SnapshotFactory::row($s);
        $decoded = PricingSnapshot::fromActivationRow($row);
        $this->assertSame($s->chainJson(), $decoded->chainJson());
        $this->assertSame($s->activationColumns(), $decoded->activationColumns());
    }

    public function testDecodeRefusesTamperingAndPartialRows(): void
    {
        $s = SnapshotFactory::seal('10', ['12', '15', '17'], '20');
        $row = SnapshotFactory::row($s);
        $d = json_decode($row['pricing_chain_snapshot'], true);
        $enc = static fn(array $x) => json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cases = [
            'missing chain column' => ['pricing_chain_snapshot' => null],
            'missing plan snapshot' => ['plan_snapshot' => null],
            'wholesale_cost differs' => ['wholesale_cost' => '16.99'],
            'provider settlement differs' => ['provider_settlement_amount' => '11.00'],
            'retail differs' => ['retail_price' => '21.00'],
            'manager differs' => ['manager_id' => '999'],
            'plan differs' => ['plan_id' => '8'],
            'not json' => ['pricing_chain_snapshot' => '{'],
            'non-canonical whitespace' => ['pricing_chain_snapshot' => ' ' . $row['pricing_chain_snapshot']],
            'pretty printed' => ['pricing_chain_snapshot' => json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ];
        $mut = function (callable $f) use ($d, $enc): string {
            $x = $d;
            $f($x);
            return $enc($x);
        };
        $cases['unknown version'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['v'] = 2; })];
        $cases['extra key'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['extra'] = 1; })];
        $cases['number instead of money string'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['chain'][0]['effective_wholesale'] = 12; })];
        $cases['float money'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['provider']['base_cost'] = 10.0; })];
        $cases['wrong margin'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['chain'][0]['margin'] = '2.00'; })];
        $cases['non-monotone chain'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['chain'][1]['effective_wholesale'] = '11.00'; })];
        $cases['forged commission'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['settlement']['commissions'][0]['amount'] = '9.00'; })];
        $cases['provider settlement forged'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['settlement']['provider_settlement'] = '0.00'; })];
        $cases['leaf swapped'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['leaf_manager_id'] = 101; })];
        $cases['disabled reseller'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['chain'][1]['availability'] = 0; })];
        $cases['resale disabled'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['provider']['resale_enabled'] = false; })];
        $cases['inherited without source'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['chain'][1]['source'] = 'INHERITED'; })];
        $cases['retail below wholesale'] = ['pricing_chain_snapshot' => $mut(function (&$x) { $x['retail']['effective'] = '16.00'; }), 'retail_price' => '16.00'];
        $cases['balance plan'] = ['plan_snapshot' => str_replace('"Hotspot"', '"Balance"', $row['plan_snapshot'])];
        foreach ($cases as $name => $over) {
            self::invalid(static fn() => PricingSnapshot::fromActivationRow($over + $row), $name);
        }
    }

    public function testInvariantsAreEnforcedOnSeal(): void
    {
        self::invalid(static fn() => SnapshotFactory::seal('10', ['9']), 'override below the base cost');
        self::invalid(static fn() => SnapshotFactory::seal('10', ['12', '11']), 'child below parent');
        self::invalid(static fn() => SnapshotFactory::seal('10', ['12'], '11.99'), 'retail below wholesale');
        self::invalid(static fn() => SnapshotFactory::seal('10', ['12'], null, '+1000'), 'malformed legacy default');
        self::invalid(static fn() => SnapshotFactory::seal('10', ['12', '13'], null, '1000', [0 => 0]), 'ancestor disabled');
        self::invalid(static fn() => SnapshotFactory::seal('10', ['12'], null, '1000', [], 'Balance'), 'Balance plan');
        $this->assertSame('EXPLICIT', json_decode(SnapshotFactory::seal('10', ['12', '13'], null, '1000', [1 => 1])->chainJson(), true)['availability']['decided_by']);
    }

    public function testPlanSnapshotExcludesScripts(): void
    {
        $json = PricingSnapshot::planJson(['id' => '7', 'name_plan' => 'n', 'price' => '10', 'on_login' => 'secret script', 'on_logout' => 'x', 'price_old' => '20', 'type' => 'PPPOE']);
        $this->assertSame('{"id":"7","name_plan":"n","type":"PPPOE","price":"10"}', $json);
        $this->assertSame('{"id":"7","name_plan":"�","price":"1"}', PricingSnapshot::planJson(['id' => '7', 'name_plan' => "\xff", 'price' => '1']), 'invalid UTF-8 is substituted deterministically');
    }
}
