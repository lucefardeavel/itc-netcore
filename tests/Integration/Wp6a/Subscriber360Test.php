<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP6A Subscriber 360: only available truth; actions rendered from the accepted capabilities (no button that would
 * 403, no Disconnect / Suspend: OD-E); secrets never for Agent / Sales; RADIUS session state from accounting data
 * only (OD-D: honest unknown / never states, NAS identity for Admin only); escaped output and LTR technical tokens.
 */
final class Subscriber360Test extends RadiusHttpTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $c = static::$seed->customers;
        static::$db->pdo->prepare('UPDATE tbl_customers SET fullname = ?, pppoe_username = ?, pppoe_ip = ? WHERE id = ?')
            ->execute(['<img src=x onerror=alert(1)> سارة', 'sara-pppoe', '10.9.8.7', $c['cA1']]);
        $now = date('Y-m-d H:i:s');
        static::$db->insertRow('radacct', ['acctsessionid' => 'L1', 'acctuniqueid' => bin2hex(random_bytes(8)), 'username' => 'sara-pppoe', 'nasipaddress' => '192.0.2.77',
            'framedipaddress' => '100.70.1.2', 'callingstationid' => 'AA:BB:CC:00:11:22', 'acctstarttime' => $now, 'acctupdatetime' => $now, 'acctstoptime' => null,
            'acctsessiontime' => 3600, 'acctinputoctets' => 1048576, 'acctoutputoctets' => 10485760]);
        $old = date('Y-m-d H:i:s', time() - 7200);
        static::$db->insertRow('radacct', ['acctsessionid' => 'L2', 'acctuniqueid' => bin2hex(random_bytes(8)), 'username' => 'cA2', 'nasipaddress' => '192.0.2.77',
            'framedipaddress' => '100.70.1.3', 'acctstarttime' => $old, 'acctupdatetime' => $old, 'acctstoptime' => null]);
        static::$db->insertRow('radacct', ['acctsessionid' => 'L3', 'acctuniqueid' => bin2hex(random_bytes(8)), 'username' => 'cA0', 'nasipaddress' => '192.0.2.77',
            'framedipaddress' => '100.70.1.4', 'acctstarttime' => $old, 'acctupdatetime' => $old, 'acctstoptime' => $now]);
    }

    private function view(string $actor, string $customer, string $tab = ''): string
    {
        $r = $this->staff($actor)->get('customers/view/' . static::$seed->customers[$customer] . ($tab !== '' ? '/' . $tab : ''));
        $this->assertSame(200, $r->status, "{$actor} {$customer}: " . $r->excerpt() . substr(static::$app->serverLog(), -6000));
        return $r->body;
    }

    /** the page content (shell navigation excluded) */
    private static function content(string $html): string
    {
        return (string) (preg_match('#<section class="content">(.*)<footer class="main-footer#s', $html, $m) ? $m[1] : '');
    }

    public function testActionsFollowCapabilitiesAndNothingRenderedIsDenied(): void
    {
        $id = static::$seed->customers['cA1'];
        $expect = [
            // actor => [present, absent]
            'ad' => [["customers/edit/{$id}", "customers/sync/{$id}", "customers/delete/{$id}", 'reseller/customer-transfer', "activation/create&customer={$id}", 'reseller/customer-assign-sales'], []],
            'A' => [["activation/create&customer={$id}", 'reseller/customer-assign-sales', 'message/send/'],
                ["customers/edit/{$id}", "customers/sync/{$id}", "customers/delete/{$id}", 'customers/deactivate', 'reseller/customer-transfer', 'customers/login', 'plan/recharge']],
            'A1' => [["activation/create&customer={$id}", 'message/send/'],
                ['reseller/customer-assign-sales', "customers/edit/{$id}", "customers/delete/{$id}", 'customers/sync', 'reseller/customer-transfer', 'plan/recharge']],
        ];
        foreach ($expect as $actor => [$present, $absent]) {
            $content = self::content($this->view($actor, 'cA1'));
            foreach ($present as $p) {
                $this->assertStringContainsString($p, $content, "{$actor}: {$p}");
            }
            foreach ($absent as $a) {
                $this->assertStringNotContainsString($a, $content, "{$actor}: no {$a}");
            }
            $this->assertDoesNotMatchRegularExpression('/disconnect|suspend/i', strip_tags($content), "{$actor}: no Disconnect / Suspend (OD-E)");
            // every GET link of the page content is authorized for the actor
            preg_match_all('#href="[^"]*\?_route=([^"]+)"#', $content, $m);
            $c = $this->staff($actor);
            foreach (array_unique(array_map('html_entity_decode', $m[1])) as $route) {
                [$path, $q] = array_pad(explode('&', $route, 2), 2, '');
                parse_str($q, $query);
                $r = $c->get($path, $query);
                $this->assertNotContains($r->status, [403, 404, 405, 500], "{$actor}: {$route} is linked but answers {$r->status}");
            }
        }
        $this->assertDenied($this->staff('rp')->get('customers/view/' . $id), 'Report');
        // legacy unassigned customer: Admin gets the legacy recharge and owner assignment, never the reseller activation
        $legacy = self::content($this->view('ad', 'cNULL'));
        $this->assertStringContainsString('plan/recharge/', $legacy);
        $this->assertStringContainsString('reseller/customer-assign-owner', $legacy);
        $this->assertStringNotContainsString('activation/create', $legacy);
    }

    public function testSecretsEscapingTokensAndAssets(): void
    {
        foreach (['A', 'A1'] as $actor) {
            $html = $this->view($actor, 'cA1');
            $this->assertDoesNotMatchRegularExpression('/type="password"[^>]*value="[^"]+"/', $html, "{$actor}: never the customer's credentials");
            $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html, 'escaped');
            $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
            $this->assertMatchesRegularExpression('#<bdi dir="ltr"[^>]*>sara-pppoe</bdi>#', $html, 'PPPoE username LTR');
            $this->assertMatchesRegularExpression('#<bdi dir="ltr"[^>]*>10\.9\.8\.7</bdi>#', $html, 'static IP LTR');
            foreach (['unpkg.com', 'google.com/vt', 'leaflet'] as $asset) {
                $this->assertStringNotContainsStringIgnoringCase($asset, $html, "no map asset before the operator opens a map: {$asset}");
            }
        }
        $this->assertStringContainsString('Show credentials', $this->view('ad', 'cA1'), 'Admin keeps the legacy credential view');
    }

    public function testSessionStateComesFromAccountingOnly(): void
    {
        $admin = self::content($this->view('ad', 'cA1'));
        $this->assertStringContainsString('Online', $admin);
        $this->assertStringContainsString('100.70.1.2', $admin);
        $this->assertStringContainsString('AA:BB:CC:00:11:22', $admin);
        $this->assertStringContainsString('192.0.2.77', $admin, 'Admin sees the NAS');
        $agent = self::content($this->view('A', 'cA1'));
        $this->assertStringContainsString('Online', $agent);
        $this->assertStringNotContainsString('192.0.2.77', $agent, 'no NAS identity for Agent');
        $this->assertStringNotContainsString('192.0.2.77', self::content($this->view('A1', 'cA1')), 'no NAS identity for Sales');
        $this->assertStringContainsString('Status unknown', self::content($this->view('A', 'cA2')), 'an open session not updated for 2 hours is never "Online"');
        $this->assertStringContainsString('Offline', self::content($this->view('A', 'cA0')));
        $this->assertStringContainsString('No session yet', self::content($this->view('ad', 'cNULL')));
    }

    public function testOperationalFlagsAndTabs(): void
    {
        $id = static::$seed->customers['cA0'];
        $out = static::$app->exec(sprintf(<<<'PHP'
$ad = \Reseller\Security\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\Reseller\Audit\AuditLog::record('reprovision.sync', $ad, ['target_type' => 'customer', 'target_customer_id' => %d, 'after' => ['outcome' => 'REPROVISION_UNCONFIRMED']]);
$result = 'ok';
PHP, $id));
        $this->assertSame('ok', $out);
        $this->assertStringContainsString('Network change not confirmed', self::content($this->view('A', 'cA0')));
        $this->assertStringNotContainsString('Network change not confirmed', self::content($this->view('A', 'cA2')));
        foreach (['activations', 'invoices', 'order', 'activation'] as $tab) {
            $this->assertStringContainsString('nuxr-tabs', $this->view('A', 'cA1', $tab), "tab {$tab}");
        }
        $this->assertStringContainsString('INV-WP2', self::content($this->view('A', 'cA1', 'invoices')), 'legacy transactions under Invoices');
        $this->assertStringContainsString('aria-current="page">' , $this->view('A', 'cA1', 'activation'), 'the former "activation" tab key opens Invoices');
    }
}
