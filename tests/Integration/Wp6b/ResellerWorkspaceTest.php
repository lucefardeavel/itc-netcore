<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP6B slice 4 over real HTTP: the reseller workspace. The tree follows reseller.tree_read (G all, T own subtree),
 * stays money-free (it is an accepted API route), drills down one level on phones; the web-only reseller detail is
 * scoped, never names an account above the viewer, links to scoped subscriber lists and shows the wallet card only to
 * wallet.read holders. The subscriber-list owner / Sales filters only narrow the accepted scope.
 */
final class ResellerWorkspaceTest extends Wp2HttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $ids = static::$seed->staff;
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\TransferService::fund(\$ad, {$ids['B']}, '77', 'funding for the card', 'WP6B-R-B-0001');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    public function testTreeScopeDrillDownAndNoMoney(): void
    {
        $id = static::$seed->staff;
        $admin = $this->staff('ad')->get('reseller/tree')->body;
        foreach (['A', 'B', 'C', 'D', 'F', 'A1'] as $u) {
            $this->assertStringContainsString('reseller/detail/' . $id[$u] . '"', $admin, "Admin sees {$u}");
        }
        $this->assertStringContainsString('Reseller Tree', $admin);
        $agent = $this->staff('A')->get('reseller/tree')->body;
        foreach (['A', 'B', 'A1', 'A2'] as $u) {
            $this->assertStringContainsString('reseller/detail/' . $id[$u] . '"', $agent, "A sees {$u}");
        }
        foreach (['C', 'C1', 'D', 'F'] as $u) {
            $this->assertStringNotContainsString('reseller/detail/' . $id[$u] . '"', $agent, "A never sees {$u}");
        }
        // phone drill-down: the children of A as cards, with a breadcrumb back
        $drill = $this->staff('ad')->get('reseller/tree', ['node' => (string) $id['A']])->body;
        $this->assertStringContainsString('nuxr-crumbs', $drill);
        $this->assertMatchesRegularExpression('#nuxr-drill.*reseller/detail/' . $id['B'] . '"#s', $drill);
        // the tree is an accepted API route: its payload never carries money
        $api = $this->client()->api('GET', 'reseller/tree', [], $this->apiToken('ad'));
        $this->assertTrue($api->json()['success'] ?? false);
        $this->assertDoesNotMatchRegularExpression('/"(balance|available|debt_limit|reserved|wallet)"/', $api->body);
        $this->assertSame(false, $this->client()->api('GET', 'reseller/detail/' . $id['B'], [], $this->apiToken('ad'))->json()['success'] ?? true, 'detail is web only');
        // Sales and Report hold no tree
        $this->assertDenied($this->staff('A1')->get('reseller/tree'));
        $this->assertDenied($this->staff('rp')->get('reseller/detail/' . $id['A']));
    }

    public function testDetailIsScopedAndMasksAccountsAboveTheViewer(): void
    {
        $id = static::$seed->staff;
        $b = $this->staff('B');
        $own = $b->get('reseller/detail/' . $id['B']);
        $this->assertSame(200, $own->status);
        $this->assertStringContainsString('Upstream reseller', $own->body, 'the parent is a role label for B');
        $this->assertStringNotContainsString('Staff A<', $own->body);
        $this->assertStringContainsString('Available credit', $own->body, 'own wallet card (wallet.read T)');
        foreach ([$id['A'], $id['C'], 999999] as $other) {
            $this->assertSame(302, $b->get('reseller/detail/' . $other)->status, "B: {$other} is outside its scope");
        }
        $a = $this->staff('A')->get('reseller/detail/' . $id['B'])->body;
        $this->assertStringContainsString('reseller/detail/' . $id['A'] . '"', $a, 'A names its own child\'s parent (itself)');
        $this->assertMatchesRegularExpression('#finance/send(&amp;|&)to=' . $id['B'] . '"#', $a, 'the parent may send to its direct child');
        $this->assertMatchesRegularExpression('#customers/list(&amp;|&)filter=All(&amp;|&)owner=' . $id['B'] . '"#', $a);
        $sales = $this->staff('ad')->get('reseller/detail/' . $id['A1'])->body;
        $this->assertStringContainsString('Subscribers assigned', $sales);
        $this->assertStringContainsString('sales=' . $id['A1'], $sales);
        $this->assertStringNotContainsString('Available credit', $sales, 'a Sales account has no wallet');
    }

    public function testSubscriberFiltersOnlyNarrowTheScope(): void
    {
        $id = static::$seed->staff;
        $ad = $this->staff('ad');
        $owner = $ad->get('customers/list', ['filter' => 'All', 'owner' => (string) $id['A']])->body;
        $this->assertStringContainsString('cA1', $owner);
        $this->assertStringNotContainsString('>cB<', $owner);
        $sales = $ad->get('customers/list', ['filter' => 'All', 'sales' => (string) $id['A1']])->body;
        $this->assertStringContainsString('cA1', $sales);
        $this->assertStringNotContainsString('cA2', $sales);
        // an Agent cannot widen its scope with another tree's owner id
        $foreign = $this->staff('A')->get('customers/list', ['filter' => 'All', 'owner' => (string) $id['C']])->body;
        $this->assertStringNotContainsString('cC1', $foreign);
    }
}
