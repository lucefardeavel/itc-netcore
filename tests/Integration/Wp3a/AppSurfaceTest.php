<?php
namespace Reseller\Tests\Integration\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP3A inside the real application (init.php autoloader, Idiorm connection, NUXR_RUNTIME) and owner
 * decision P7 (no finance API route; WP6B OD-1 narrowly amended its web half: the finance web surface exists only
 * through the RouteMap 'finance' controller). Unmapped routes stay
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

    /**
     * P7 as narrowly amended for WP6B (owner decision OD-1): the finance web surface exists only as the RouteMap
     * 'finance' controller (Reseller\Backoffice gateway). The API half of P7 is unchanged: no finance, pricing or
     * operations route and no finance-looking alias is API-reachable; unmapped finance names stay fail-closed; the
     * accepted reseller aliases still render at most the read-only tree; forged / refused requests write nothing.
     */
    public function testNoFinanceRoutesOrApi(): void
    {
        // unmapped finance names stay fail-closed for every role
        $unmapped = ['wallet', 'wallet/fund', 'wallet/list', 'wallet/transfer', 'ledger', 'ledger/list', 'transfer', 'reservation'];
        // finance-looking actions under the accepted WP2 reseller controller only alias the read-only tree page
        $aliases = ['reseller/wallet', 'reseller/fund', 'reseller/transfer', 'reseller/ledger', 'reseller/debt-limit', 'reseller/reverse'];
        // the WP6B web surface (web only): every action of the finance / pricing / operations controllers
        $web = [];
        foreach (['finance', 'pricing', 'operations'] as $controller) {
            foreach (array_keys(\Reseller\Security\RouteMap::ROUTES[$controller] ?? []) as $k) {
                if ($k !== '' && $k !== '*') {
                    $web[] = $controller . '/' . explode('#', $k)[0];
                }
            }
        }
        $web = array_values(array_unique(array_merge($web, ['finance', 'finance/fund', 'activation/cancel', 'activation/resume', 'reseller/detail'])));
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
                // the page content only (from WP6B on the shell navigation may link the finance surface)
                $start = strpos($g->body, 'id="nuxr-main"');
                $main = $start !== false ? substr($g->body, $start, max(0, (int) strpos($g->body, '<footer', $start) - $start)) : $g->body;
                $this->assertDoesNotMatchRegularExpression('/wallet|ledger|debt limit/i', strip_tags((string) preg_replace('#<script.*?</script>#s', '', $main)), "{$u} GET {$route}: no finance UI");
            }
            $token = $this->apiToken($u);
            // the API half of P7: no finance mutation through any alias (the accepted reseller tree stays readable);
            // no unmapped finance name and no WP6B web route at all
            foreach ($aliases as $route) {
                $r = $this->client()->api('POST', $route, $forged, $token);
                $this->assertFalse($r->json()['success'] ?? true, "API POST {$u} {$route}: " . $r->excerpt());
            }
            foreach (array_merge($unmapped, $web) as $route) {
                foreach (['GET', 'POST'] as $m) {
                    $r = $this->client()->api($m, $route, $m === 'POST' ? $forged : [], $token);
                    $this->assertFalse($r->json()['success'] ?? true, "API {$m} {$u} {$route}: " . $r->excerpt());
                }
            }
        }
        $after = $this->snapshot([]);
        foreach (['tbl_manager_wallets', 'tbl_manager_ledger', 'tbl_manager_transfers', 'tbl_manager_wallet_reservations', 'tbl_customers', 'tbl_manager_tree'] as $t) {
            $this->assertSame($before[$t], $after[$t], "{$t} unchanged");
        }
    }
}
