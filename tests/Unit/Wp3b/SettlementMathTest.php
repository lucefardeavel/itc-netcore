<?php
namespace Reseller\Tests\Unit\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reseller\Domain\DomainError;
use Reseller\Finance\Money;
use Reseller\Tests\Support\SnapshotFactory;

/**
 * WP3B §13–§15: exact commission / provider-settlement math (Money, integer minor units, no float).
 * L = c_k (leaf debit), P = c_1 (provider settlement), m_i = c_{i+1} - c_i; P + sum(m) = L exactly;
 * the leaf never gets a margin; only positive margins are commissions.
 */
final class SettlementMathTest extends TestCase
{
    /** @return array<string,array{0:string,1:list<?string>,2:string,3:array<int,string>,4:string,5:string}> */
    public static function cases(): array
    {
        return [
            // name => [base, overrides top->leaf, L, commissions (manager => amount), P, provider margin over base]
            'one level, override' => ['10', ['12'], '12.00', [], '12.00', '2.00'],
            'no override (base)' => ['10', [null], '10.00', [], '10.00', '0.00'],
            'two levels 10->12->15' => ['10', ['12', '15'], '15.00', [101 => '3.00'], '12.00', '2.00'],
            'spec 10->12->15->17' => ['10', ['12', '15', '17'], '17.00', [102 => '2.00', 101 => '3.00'], '12.00', '2.00'],
            'inherited middle earns the gap' => ['10', ['12', null, '17'], '17.00', [102 => '5.00'], '12.00', '2.00'],
            'equal adjacent explicit' => ['10', ['12', '12', '12'], '12.00', [], '12.00', '2.00'],
            'all inherit base' => ['10', [null, null, null], '10.00', [], '10.00', '0.00'],
            'base 0, owner example 0->5->8' => ['0', ['5', '8'], '8.00', [101 => '3.00'], '5.00', '5.00'],
            'base 0, top inherits 0' => ['0', [null, '5'], '5.00', [101 => '5.00'], '0.00', '0.00'],
            'cents' => ['0.01', ['0.02', '0.05', '0.06'], '0.06', [102 => '0.01', 101 => '0.03'], '0.02', '0.01'],
            'deep chain' => ['1', ['2', '3', null, '7', '11', '13'], '13.00', [105 => '2.00', 104 => '4.00', 103 => '4.00', 101 => '1.00'], '2.00', '1.00'],
            'maximum' => ['0', ['1.00', '9999999999999999.99'], '9999999999999999.99', [101 => '9999999999999998.99'], '1.00', '1.00'],
        ];
    }

    #[DataProvider('cases')]
    public function testExactSettlement(string $base, array $overrides, string $leaf, array $commissions, string $provider, string $marginOverBase): void
    {
        $s = SnapshotFactory::seal($base, $overrides, $leaf === '9999999999999999.99' ? '9999999999999999.99' : '9999999');
        $this->assertSame($leaf, $s->leafDebit->format());
        $this->assertSame($provider, $s->providerSettlement->format());
        $this->assertSame($marginOverBase, $s->providerMarginOverBase->format());
        $got = array_map(static fn(Money $m) => $m->format(), $s->commissionRecipients());
        $this->assertSame($commissions, $got, 'positive margins only, leaf -> top');
        $sum = $s->providerSettlement;
        foreach ($s->commissionRecipients() as $m) {
            $this->assertTrue($m->isPositive(), 'no zero-value commission');
            $sum = $sum->plus($m);
        }
        $this->assertSame($s->leafDebit->format(), $sum->format(), 'P + sum(commissions) = L exactly');
        $this->assertArrayNotHasKey($s->leafManagerId, $s->commissionRecipients(), 'the leaf never earns a margin');
        // zero margins stay visible in the chain for audit
        $chain = $s->toArray()['chain'];
        $this->assertCount(count($overrides), $chain);
        $this->assertSame('0.00', $chain[count($chain) - 1]['margin']);
        // the activation columns carry L, effective retail and P
        $cols = $s->activationColumns();
        $this->assertSame([$leaf, $provider], [$cols['wholesale_cost'], $cols['provider_settlement_amount']]);
    }

    public function testZeroLeafWholesaleIsNeverSealed(): void
    {
        try {
            SnapshotFactory::seal('0', [null, null]);
            $this->fail('a zero leaf wholesale must not be sealed');
        } catch (DomainError $e) {
            $this->assertSame('PRICING_SNAPSHOT_INVALID', $e->reason);
        }
    }

    public function testNoFloatDriftAcrossManyCents(): void
    {
        $overrides = [];
        $c = 0;
        for ($i = 0; $i < 30; $i++) {
            $c += 10; // 0.10 per level
            $overrides[] = intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
        }
        $s = SnapshotFactory::seal('0', $overrides, '9999');
        $this->assertSame('3.00', $s->leafDebit->format());
        $this->assertCount(29, $s->commissionRecipients());
        foreach ($s->commissionRecipients() as $m) {
            $this->assertSame('0.10', $m->format());
        }
        $this->assertSame('0.10', $s->providerSettlement->format());
    }
}
