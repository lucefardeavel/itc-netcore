<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationReadService;
use Reseller\Activation\ActivationService;
use Reseller\Audit\AuditLog;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceSeed as Seed;

/**
 * WP6A (owner decision OD-A): the read-only activation read model. Its visibility is exactly
 * ActivationService::status() (activation.read: G all, T paid by the subtree, A assigned customers); only display
 * fields leave it; the sealed money fields are role-filtered; "released" / "charged" come from the reservation.
 */
final class ActivationReadModelTest extends ActivationTestCase
{
    /** @return array<string,mixed> */
    private function sales3(): array
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $sales = Seed::staff(static::$db, 'sl' . bin2hex(random_bytes(3)), $leaf, 'Active', true, 'Sales');
        $other = $this->funded('1000');
        $c1 = $this->customer($leaf, $sales);
        $c2 = $this->customer($leaf);
        $c3 = $this->customer($other);
        return [
            'plan' => $plan, 'top' => $top, 'leaf' => $leaf, 'sales' => $sales, 'other' => $other, 'c1' => $c1, 'c2' => $c2, 'c3' => $c3,
            'r1' => $this->activate($leaf, $c1, $plan, 'NEW')['reference'],
            'r2' => $this->activate($leaf, $c2, $plan, 'NEW')['reference'],
            'r3' => $this->activate($other, $c3, $plan, 'NEW')['reference'],
        ];
    }

    /** @return list<string> */
    private function refs(string|int $actor, array $filter = []): array
    {
        return array_column(ActivationReadService::list($this->ctx($actor), $filter)['rows'], 'reference');
    }

    public function testListAndViewVisibilityEqualStatusVisibility(): void
    {
        $s = $this->sales3();
        $mine = [$s['r1'], $s['r2'], $s['r3']];
        $expected = [
            'ad' => $mine, $s['leaf'] => [$s['r1'], $s['r2']], $s['top'] => [$s['r1'], $s['r2']], // T: paid by the subtree
            $s['sales'] => [$s['r1']], $s['other'] => [$s['r3']],
        ];
        foreach ($expected as $actor => $visible) {
            $listed = array_values(array_intersect($this->refs($actor), $mine));
            sort($listed);
            $want = $visible;
            sort($want);
            $this->assertSame($want, $listed, "list of {$actor}");
            foreach ($mine as $ref) {
                $statusVisible = true;
                try {
                    ActivationService::status($this->ctx($actor), $ref);
                } catch (\Reseller\Domain\DomainError $e) {
                    $statusVisible = false;
                }
                $this->assertSame($statusVisible, in_array($ref, $visible, true), "status() and the list agree: {$actor} / {$ref}");
                if ($statusVisible) {
                    $this->assertSame($ref, ActivationReadService::view($this->ctx($actor), $ref)['activation']['reference']);
                } else {
                    $this->expectExceptionReason('NOT_FOUND', fn() => ActivationReadService::view($this->ctx($actor), $ref), "view {$actor} {$ref}");
                }
            }
        }
        $this->expectExceptionReason('FORBIDDEN', fn() => ActivationReadService::list($this->ctx('rp')), 'Report has no activation.read');
        $this->assertSame([$s['r1']], array_values(array_intersect($this->refs('ad', ['customer' => $s['c1']]), $mine)), 'per-customer filter');
    }

    public function testViewIsDisplayOnlyAndMoneyIsRoleFiltered(): void
    {
        $s = $this->sales3();
        $forbidden = ['pricing_chain_snapshot', 'plan_snapshot', 'provider_settlement_amount', 'idempotency_key', 'metadata', 'error_message', 'wholesale_cost'];
        foreach (['ad', $s['leaf'], $s['top'], $s['sales']] as $actor) {
            $v = ActivationReadService::view($this->ctx($actor), $s['r1']);
            $json = (string) json_encode($v);
            foreach ($forbidden as $k) {
                $this->assertStringNotContainsString('"' . $k . '"', $json, "{$actor}: {$k} never leaves the read model");
            }
            $this->assertSame('20.00', $v['activation']['retail'], "{$actor}: retail is shown to every reader");
            $this->assertSame('charged', $v['activation']['credit'], "{$actor}: completed = charged (COMMITTED reservation)");
            $this->assertNotNull($v['activation']['period_expiry']);
            $this->assertSame('COMPLETED', end($v['events'])['to_state']);
        }
        $this->assertSame('15.00', ActivationReadService::view($this->ctx('ad'), $s['r1'])['activation']['wholesale'], 'Admin sees the sealed wholesale');
        $this->assertSame('15.00', ActivationReadService::view($this->ctx($s['leaf']), $s['r1'])['activation']['wholesale'], 'the paying Agent sees its own cost');
        $this->assertNull(ActivationReadService::view($this->ctx($s['top']), $s['r1'])['activation']['wholesale'], 'an ancestor never sees a child\'s cost here');
        $this->assertNull(ActivationReadService::view($this->ctx($s['sales']), $s['r1'])['activation']['wholesale'], 'Sales never sees wholesale');
        foreach (ActivationReadService::list($this->ctx('ad'))['rows'] as $row) {
            $this->assertArrayNotHasKey('wholesale', $row, 'lists carry no money');
        }
    }

    public function testGroupsCountsAndCustomerFlags(): void
    {
        $s = $this->sales3();
        $c4 = $this->customer($s['leaf']);
        $calls = 0;
        RadiusFaults::$hook = static function (string $p) use (&$calls): void {
            if ($p === 'radius.commit_lost' && ++$calls === 2) {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $r4 = $this->activate($s['leaf'], $c4, $s['plan'], 'NEW');
        RadiusFaults::$hook = null;
        $this->assertSame('RECONCILIATION_REQUIRED', $r4['status']);
        $counts = ActivationReadService::counts($this->ctx($s['leaf']));
        $this->assertSame(1, $counts['attention'], 'the unknown outcome needs attention');
        $this->assertSame(2, $counts['completed'], 'completed in the last 7 days');
        $this->assertSame(0, $counts['processing']);
        $this->assertSame(0, ActivationReadService::counts($this->ctx($s['sales']))['attention'], 'Sales: not its customer');
        $this->assertSame([$r4['reference']], array_values(array_intersect($this->refs($s['leaf'], ['group' => 'attention']), [$s['r1'], $s['r2'], $r4['reference']])));
        $view = ActivationReadService::view($this->ctx($s['leaf']), $r4['reference'])['activation'];
        $this->assertSame(['reserved', false], [$view['credit'], $view['terminal']], 'unknown: the credit stays reserved, never "released"');

        $flags = ActivationReadService::customerFlags($this->ctx($s['leaf']), $c4);
        $this->assertSame(['reference' => $r4['reference'], 'status' => 'RECONCILIATION_REQUIRED'], $flags['in_flight']);
        $this->assertNull(ActivationReadService::customerFlags($this->ctx($s['leaf']), $s['c2'])['in_flight']);
        $this->expectExceptionReason('NOT_FOUND', fn() => ActivationReadService::customerFlags($this->ctx($s['sales']), $c4), 'outside the Sales scope');
        $this->expectExceptionReason('NOT_FOUND', fn() => ActivationReadService::customerFlags($this->ctx($s['other']), $c4), 'another tree');

        $this->assertFalse($flags['reprovision_unconfirmed']);
        AuditLog::record('reprovision.sync', $this->ctx('ad'), ['target_type' => 'customer', 'target_customer_id' => $c4, 'after' => ['outcome' => 'REPROVISION_UNCONFIRMED']], static::$svc);
        $this->assertTrue(ActivationReadService::customerFlags($this->ctx($s['leaf']), $c4)['reprovision_unconfirmed'], 'as the drift report');
        AuditLog::record('reprovision.sync', $this->ctx('ad'), ['target_type' => 'customer', 'target_customer_id' => $c4, 'after' => ['outcome' => 'OK']], static::$svc);
        $this->assertFalse(ActivationReadService::customerFlags($this->ctx($s['leaf']), $c4)['reprovision_unconfirmed'], 'a later confirmed re-provision supersedes it');
    }
}
