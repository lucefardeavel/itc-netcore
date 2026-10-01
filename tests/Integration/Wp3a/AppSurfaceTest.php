<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP3A inside the real application (init.php autoloader, Idiorm connection, NUXR_RUNTIME) and owner
 * decision P7: no finance UI, no finance web route and no finance API route. Unmapped routes stay
 * fail-closed (accepted WP2 RouteGuard) for every role, SuperAdmin included.
 */
final class AppSurfaceTest extends Wp2HttpTestCase
{
    public function testFinanceServicesRunInTheApplicationContext(): void
    {
        $sa = $this->sid('sa');
        $b = $this->sid('B');
        $out = static::$app->exec(<<<PHP
\$row = ORM::for_table('tbl_users')->find_one({$sa})->as_array();
\$actor = \\Reseller\\Security\\ActorContext::forStaff(\$row, 'WEB');
\$t = \\Reseller\\Finance\\TransferService::fund(\$actor, {$b}, '42.00', 'application context funding', 'APPCTX-0001');
\$v = \\Reseller\\Finance\\WalletService::view(\$actor, {$b});
\$result = ['transfer' => \$t->toArray(), 'wallet' => \$v->toArray(), 'mode' => ORM::get_db()->query('SELECT @@SESSION.sql_mode')->fetchColumn()];
PHP);
        $this->assertSame('PROVIDER_FUNDING', $out['transfer']['operation_type']);
        $this->assertSame(['42.00', '42.00'], [$out['wallet']['balance'], $out['wallet']['available']]);
        $this->assertStringNotContainsString('STRICT_ALL_TABLES', (string) $out['mode'], 'the legacy request sql_mode is restored after the financial unit');
        $this->assertSame('42.00', static::$db->value('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [$b]));
        $this->assertSame('5000', (string) (int) static::$db->value('SELECT balance FROM tbl_customers WHERE username = ?', ['cB']), 'customer balance untouched');
    }

    /** P7: there is no finance web or API surface; every attempt is refused and writes nothing. */
    public function testNoFinanceRoutesOrApi(): void
    {
        // no finance controller exists: unmapped routes are fail-closed for every role
        $unmapped = ['wallet', 'wallet/fund', 'wallet/list', 'wallet/transfer', 'ledger', 'ledger/list', 'finance', 'finance/fund', 'transfer', 'reservation'];
        // finance-looking actions under the accepted WP2 reseller controller only alias the read-only tree page
        $aliases = ['reseller/wallet', 'reseller/fund', 'reseller/transfer', 'reseller/ledger', 'reseller/debt-limit', 'reseller/reverse'];
        $forged = ['manager_id' => $this->sid('B'), 'from' => $this->sid('A'), 'to' => $this->sid('B'), 'amount' => '1000', 'reason' => 'forged', 'reference' => 'FORGED-0001'];
        $before = $this->snapshot([]);
        foreach (['sa', 'ad', 'A'] as $u) {
            $c = $this->staff($u);
            foreach ($unmapped as $route) {
                $this->assertDenied($c->get($route), "{$u} GET {$route}");
                $this->assertDenied($c->postWithToken($route, $forged), "{$u} POST {$route}");
            }
            foreach ($aliases as $route) {
                $this->assertDenied($c->postWithToken($route, $forged), "{$u} POST {$route}");
                $g = $c->get($route);
                $this->assertTrue($g->status !== 200 || str_contains($g->body, 'Reseller Tree'), "{$u} GET {$route} is at most the read-only tree");
                $this->assertDoesNotMatchRegularExpression('/wallet|ledger|debt limit/i', strip_tags((string) preg_replace('#<script.*?</script>#s', '', $g->body)), "{$u} GET {$route}: no finance UI");
            }
            $token = $this->apiToken($u);
            foreach (array_merge($unmapped, $aliases) as $route) {
                $r = $this->client()->api('POST', $route, $forged, $token);
                $this->assertFalse($r->json()['success'] ?? true, "API {$u} {$route}: " . $r->excerpt());
            }
        }
        $after = $this->snapshot([]);
        foreach (['tbl_manager_wallets', 'tbl_manager_ledger', 'tbl_manager_transfers', 'tbl_manager_wallet_reservations', 'tbl_customers', 'tbl_manager_tree'] as $t) {
            $this->assertSame($before[$t], $after[$t], "{$t} unchanged");
        }
    }
}
