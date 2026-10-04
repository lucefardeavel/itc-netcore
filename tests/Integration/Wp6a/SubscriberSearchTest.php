<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP6A subscriber search: server-side, DataScope-restricted, bounded. Username, full name (Arabic), phone, PPPoE
 * username, static IP, open-session framed IP, customer id; LIKE wildcards escaped; output escaped; the search group
 * never widens the scope (another tree's subscriber is never found, whatever field matches).
 */
final class SubscriberSearchTest extends RadiusHttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $c = static::$seed->customers;
        $set = static fn(string $u, array $cols) => static::$db->pdo->prepare('UPDATE tbl_customers SET ' . implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($cols))) . ' WHERE id = ?')
            ->execute([...array_values($cols), $c[$u]]);
        $set('cA1', ['fullname' => 'فاطمة الزهراء', 'phonenumber' => '07701234567', 'pppoe_username' => 'fz-home@isp', 'pppoe_ip' => '10.20.30.41']);
        $set('cC1', ['fullname' => 'فاطمة كاظم', 'phonenumber' => '07709876543', 'pppoe_username' => 'fk-home@isp', 'pppoe_ip' => '10.20.30.99']);
        $set('cA0', ['fullname' => '<script>alert(1)</script> Hacker', 'phonenumber' => '0780__55']);
        static::$db->insertRow('radacct', [
            'acctsessionid' => 'S1', 'acctuniqueid' => bin2hex(random_bytes(8)), 'username' => 'cA2', 'nasipaddress' => '192.0.2.1', 'framedipaddress' => '100.64.7.9',
            'acctstarttime' => date('Y-m-d H:i:s'), 'acctupdatetime' => date('Y-m-d H:i:s'), 'acctstoptime' => null,
        ]);
        static::$db->insertRow('radacct', [
            'acctsessionid' => 'S2', 'acctuniqueid' => bin2hex(random_bytes(8)), 'username' => 'cC1', 'nasipaddress' => '192.0.2.1', 'framedipaddress' => '100.64.7.10',
            'acctstarttime' => date('Y-m-d H:i:s'), 'acctupdatetime' => date('Y-m-d H:i:s'), 'acctstoptime' => null,
        ]);
    }

    /** @return list<int> customer ids in the quick-search fragment */
    private function quick(string $actor, string $q): array
    {
        $r = $this->staff($actor)->get('search_user', ['query' => $q]);
        $this->assertSame(200, $r->status);
        preg_match_all('#customers/view/(\d+)#', $r->body, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /** @return list<int> customer ids on the first list page */
    private function listed(string $actor, string $q, array $extra = []): array
    {
        $r = $this->staff($actor)->get('customers/list', ['search' => $q, 'filter' => 'All'] + $extra);
        $this->assertSame(200, $r->status, $r->excerpt() . substr(static::$app->serverLog(), -1500));
        preg_match_all('#customers/view/(\d+)#', $r->body, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    public function testEveryIdentifierMatchesWithinScopeOnly(): void
    {
        $c = static::$seed->customers;
        $cases = [
            'فاطمة' => ['A' => [$c['cA1']], 'C' => [$c['cC1']], 'ad' => [$c['cA1'], $c['cC1']], 'A1' => [$c['cA1']], 'A2' => []],
            'cA1' => ['A' => [$c['cA1']], 'C' => []],
            '0770123' => ['A' => [$c['cA1']], 'C' => []],
            '0770 987' => ['C' => [$c['cC1']], 'A' => []],
            'fz-home' => ['A' => [$c['cA1']], 'C' => []],
            '10.20.30' => ['A' => [$c['cA1']], 'C' => [$c['cC1']], 'ad' => [$c['cA1'], $c['cC1']]],
            '100.64.7' => ['A' => [$c['cA2']], 'C' => [$c['cC1']], 'A1' => []],
            '#' . $c['cC1'] => ['A' => [], 'C' => [$c['cC1']], 'ad' => [$c['cC1']]],
        ];
        foreach ($cases as $q => $byActor) {
            foreach ($byActor as $actor => $want) {
                sort($want);
                $quick = $this->quick($actor, $q);
                sort($quick);
                $this->assertSame($want, $quick, "quick search {$actor} '{$q}'");
                $list = $this->listed($actor, $q);
                sort($list);
                $this->assertSame($want, $list, "list search {$actor} '{$q}'");
            }
        }
    }

    public function testBoundsWildcardsAndEscaping(): void
    {
        $c = static::$seed->customers;
        $this->assertStringContainsString('at least 2', $this->staff('ad')->get('search_user', ['query' => 'x'])->body, 'one character is not a search');
        $this->assertSame([], $this->quick('ad', '%%'), 'percent is literal');
        $this->assertSame([$c['cA0']], $this->quick('ad', '0780__'), 'underscore is literal (only the phone that contains "__")');
        $this->assertLessThanOrEqual(20, count($this->quick('ad', 'c')) ?: 0);
        $body = $this->staff('ad')->get('search_user', ['query' => 'Hacker'])->body;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body, 'escaped');
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $list = $this->staff('ad')->get('customers/list', ['search' => 'Hacker', 'filter' => 'All'])->body;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $list);
        $this->assertStringContainsString('dir="ltr"', $list, 'technical tokens isolated LTR');
    }

    public function testListFiltersAndRoleActions(): void
    {
        $c = static::$seed->customers;
        $this->assertNotContains($c['cNULL'], $this->listed('A', ''), 'unassigned legacy customers are Admin-only');
        $this->assertContains($c['cNULL'], $this->listed('ad', ''));
        $this->assertSame([], array_values(array_intersect($this->listed('A', '', ['service' => 'none']), [$c['cA1']])), 'cA1 has a seeded active recharge');
        $this->assertContains($c['cA1'], $this->listed('A', '', ['service' => 'active']));
        $agent = $this->staff('A')->get('customers/list')->body;
        foreach (['customers/edit', 'customers/sync', 'plan/recharge', 'customers/csv', 'code.jquery.com'] as $dead) {
            $this->assertStringNotContainsString($dead, $agent, "Agent list: no {$dead}");
        }
        $this->assertStringContainsString('customers/csv', $this->staff('ad')->get('customers/list')->body, 'Admin keeps the CSV export');
    }
}
