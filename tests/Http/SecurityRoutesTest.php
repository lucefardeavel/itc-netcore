<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/** SEC-02/03/05/12, C8, SEC-19 minimal, NEW-1/2/4/5 and direct access to WP2 runtime files. */
final class SecurityRoutesTest extends Wp2HttpTestCase
{
    /** SEC-02 / OD-9: only registered AND authorized plugin functions. */
    public function testPluginDispatchRequiresRegisteredAuthorizedFunction(): void
    {
        static::$app->clearProbes();
        $anon = $this->client();
        $sa = $this->staff('sa');
        $ad = $this->staff('ad');
        $ag = $this->staff('A');
        $cust = $this->customer('cNULL');
        foreach ([$anon, $sa, $ag, $cust] as $c) {
            foreach (['plugin/phpinfo', 'plugin/wp2probe_unregistered', 'plugin/_admin', 'plugin/Package::rechargeUser', 'plugin/'] as $route) {
                $r = $c->get($route);
                $this->assertContains($r->status, [302, 404], $route);
                $this->assertStringNotContainsString('PHP Version', $r->body);
                $this->assertStringNotContainsString('WP2PROBE_UNREGISTERED', $r->body);
            }
        }
        $this->assertStringContainsString('WP2PROBE_ADMIN_PAGE', $sa->get('plugin/wp2probe_admin_page')->body);
        $this->assertStringContainsString('WP2PROBE_ADMIN_PAGE', $ad->get('plugin/wp2probe_admin_page')->body);
        $this->assertSame(404, $ag->get('plugin/wp2probe_admin_page')->status, 'Agent not in the menu auth list');
        $this->assertStringContainsString('WP2PROBE_DEFAULT_PAGE', $sa->get('plugin/wp2probe_default_page')->body);
        $this->assertSame(404, $ag->get('plugin/wp2probe_default_page')->status, 'empty auth list = SuperAdmin/Admin only');
        $this->assertStringContainsString('WP2PROBE_CUSTOMER_PAGE', $cust->get('plugin/wp2probe_customer_page')->body);
        $this->assertSame(404, $anon->get('plugin/wp2probe_customer_page')->status);
        $this->assertNotContains('unregistered', static::$app->probe('plugin'));
    }

    /** SEC-03 / OD-2: the web updater is gone. */
    public function testUpdaterIsDisabled(): void
    {
        $sa = $this->staff('sa');
        $tree = static::$app->treeHash();
        foreach (['GET', 'POST'] as $m) {
            foreach (['', '?step=1&update_url=http://127.0.0.1:9/x.zip', '?step=4', '?step=5'] as $q) {
                $r = $sa->raw($m, 'update.php' . $q, $m === 'POST' ? ['update_url' => 'http://127.0.0.1:9/x.zip'] : null);
                $this->assertSame(403, $r->status, "{$m} update.php{$q}");
            }
        }
        $this->assertSame($tree, static::$app->treeHash(), 'application files unchanged');
        $this->assertStringNotContainsString('update.php', $sa->get('community')->body, 'no updater link');
    }

    /** SEC-19 minimal: the installer refuses to run once config.php exists. */
    public function testInstallerIsDisabledOnceConfigured(): void
    {
        $cfg = hash_file('sha256', static::$app->dir . '/config.php');
        $c = $this->client();
        foreach (['install/index.php', 'install/step2.php', 'install/step3.php', 'install/update.php'] as $p) {
            $this->assertSame(403, $c->raw('GET', $p)->status, $p);
        }
        $r = $c->raw('POST', 'install/step4.php', ['appurl' => 'http://x', 'dbhost' => static::$db->host, 'dbuser' => 'root', 'dbpass' => (string) getenv('WP1_DB_ROOT_PASSWORD'), 'dbname' => static::$db->name]);
        $this->assertSame(403, $r->status);
        $this->assertSame($cfg, hash_file('sha256', static::$app->dir . '/config.php'), 'config.php untouched');
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_users WHERE username = 'sa'"), 'no re-import');
    }

    /** SEC-05: customer search requires staff auth and is scoped. */
    public function testSearchUserRequiresStaffAndScope(): void
    {
        $r = $this->client()->get('search_user', ['query' => 'cA']);
        $this->assertSame(302, $r->status);
        $this->assertStringNotContainsString('cA1', $r->body);
        $r = $this->customer('cNULL')->get('search_user', ['query' => 'cA']);
        $this->assertStringNotContainsString('cA1', $r->body);
        $a1 = $this->staff('A1')->get('search_user', ['query' => 'cA']);
        $this->assertStringContainsString('cA1', $a1->body);
        $this->assertStringNotContainsString('cA2', $a1->body);
    }

    /** SEC-12: transaction retention delete removed; logs/orderby parameters inert. */
    public function testRawSqlParametersAreInert(): void
    {
        $sa = $this->staff('sa');
        $trx = static::$db->dataHash('tbl_transactions', ['id', 'invoice', 'user_id', 'price']);
        foreach (['1', '0 OR 1=1', "1); DROP TABLE tbl_transactions; --", '-1'] as $keep) {
            $sa->postWithToken('reports/by-date', ['keep' => $keep]);
            $sa->postWithToken('reports/activation', ['keep' => $keep]);
        }
        $this->assertSame($trx, static::$db->dataHash('tbl_transactions', ['id', 'invoice', 'user_id', 'price']), 'financial history untouched');
        $logs = (int) static::$db->value('SELECT COUNT(*) FROM tbl_logs');
        $r = $sa->postWithToken('logs/list', ['keep' => '1 DAY) OR (1=1']);
        $this->assertSame(302, $r->status);
        $this->assertGreaterThanOrEqual($logs, (int) static::$db->value('SELECT COUNT(*) FROM tbl_logs'), 'injection in keep rejected');
        $r = $sa->get('logs/message', ['q' => "x' OR '1'='1"]);
        $this->assertSame(200, $r->status);
        $this->assertFalse($r->leaksInternals());
        foreach (["username; DROP TABLE x", "(SELECT 1)", "balance desc, (select sleep(5))"] as $o) {
            $r = $sa->get('customers/list', ['order' => 'lastname', 'orderby' => $o]);
            $this->assertSame(200, $r->status);
            $r = $sa->get('customers/list', ['order' => $o]);
            $this->assertSame(200, $r->status);
            $this->assertFalse($r->leaksInternals());
        }
    }

    /** C8 / D027: Agent and Sales cannot generate vouchers (form or forged POST). */
    public function testAgentAndSalesCannotGenerateVouchers(): void
    {
        $n = (int) static::$db->value('SELECT COUNT(*) FROM tbl_voucher');
        foreach (['A', 'A1', 'C'] as $u) {
            $c = $this->staff($u);
            $this->assertSame(403, $c->get('plan/add-voucher')->status);
            $r = $c->postWithToken('plan/voucher-post', ['type' => 'Hotspot', 'plan' => static::$seed->planId, 'server' => 'wp2_router', 'numbervoucher' => '5', 'lengthcode' => '8', 'voucher_format' => 'up']);
            $this->assertSame(403, $r->status);
        }
        $this->assertSame($n, (int) static::$db->value('SELECT COUNT(*) FROM tbl_voucher'));
        $r = $this->staff('ad')->postWithToken('plan/voucher-post', ['type' => 'Hotspot', 'plan' => static::$seed->planId, 'server' => 'wp2_router', 'numbervoucher' => '2', 'lengthcode' => '8', 'voucher_format' => 'up']);
        $this->assertSame(302, $r->status);
        $this->assertSame($n + 2, (int) static::$db->value('SELECT COUNT(*) FROM tbl_voucher'), 'Admin still generates vouchers');
    }

    /** NEW-4 / OD-14: page and voucher-template names are allowlisted and canonicalized. */
    public function testPagesTraversalIsRejected(): void
    {
        $sa = $this->staff('sa');
        $tree = static::$app->treeHash();
        $files = static::$app->treeFiles();
        foreach (['..%2f..%2fconfig', '../../config', '..\\..\\config', 'Voucher.html', '%2e%2e', 'NoSuchPage', str_repeat('A', 80), "Voucher\0x"] as $name) {
            $r = $sa->get('pages/' . $name);
            $this->assertSame(404, $r->status, "pages/{$name}");
            $this->assertStringNotContainsString('formpages', $r->body);
            $r = $sa->postWithToken('pages/' . $name . '-post', ['html' => '<p>x</p>']);
            $this->assertContains($r->status, [404, 405], "pages/{$name}-post");
        }
        $this->assertSame($tree, static::$app->treeHash(), 'no file written or created by traversal attempts: '
            . json_encode(array_keys(array_diff_assoc(static::$app->treeFiles(), $files) + array_diff_key($files, static::$app->treeFiles()))));
        // template_name traversal on an allowlisted page
        foreach (['../../evil', '../x', 'a/b', 'x.php', "a\0b"] as $tn) {
            $r = $sa->postWithToken('pages/Voucher-post', ['html' => '<p>v</p>', 'template_save' => 'yes', 'template_name' => $tn]);
            $this->assertSame(302, $r->status);
        }
        $this->assertFileDoesNotExist(static::$app->dir . '/evil.html');
        $this->assertFileDoesNotExist(static::$app->dir . '/pages/x.html');
        $this->assertSame([], glob(static::$app->dir . '/pages/vouchers/*evil*') ?: []);
        // allowlisted page and a valid template name still work
        $r = $sa->get('pages/Announcement');
        $this->assertSame(200, $r->status);
        $r = $sa->postWithToken('pages/Voucher-post', ['html' => '<p>ok</p>', 'template_save' => 'yes', 'template_name' => 'My Template']);
        $this->assertSame(302, $r->status);
        $this->assertFileExists(static::$app->dir . '/pages/vouchers/My Template.html');
        foreach (['..%2f..%2fconfig', '..', 'NoSuchPage'] as $name) {
            $r = $this->customer('cNULL')->get('page/' . $name);
            $this->assertStringNotContainsString('db_pass', $r->body);
            $this->assertStringNotContainsString('panel-heading', $r->body, "customer page/{$name} is not rendered");
        }
    }

    /** NEW-1: the language name never reaches the filesystem unless it is a shipped language. */
    public function testLanguageCookieTraversalIsIgnored(): void
    {
        $c = $this->client();
        $tree = static::$app->treeHash();
        foreach (['../../../tmp/wp2evil', '../config', 'nosuchlang', "english\0"] as $l) {
            $c->cookies = ['user_language' => rawurlencode($l)];
            $r = $c->get('login');
            $this->assertSame(200, $r->status);
        }
        $this->assertSame($tree, static::$app->treeHash(), 'no language file created');
        $this->assertFileDoesNotExist(static::$app->dir . '/system/lan/nosuchlang.json');
        $cust = $this->customer('cNULL');
        $r = $cust->postWithToken('accounts/language-update-post', [], ['lang' => '../../x'], 'home');
        $this->assertSame(302, $r->status);
        $this->assertSame($tree, static::$app->treeHash());
    }

    /** NEW-5: public invoice links need a positive id and a valid signature (no "latest transaction"). */
    public function testPublicInvoiceSignature(): void
    {
        $c = $this->client();
        $db = (string) getenv('WP1_DB_ROOT_PASSWORD');
        $r = $c->get('voucher/invoice/');
        $this->assertSame(404, $r->status);
        $r = $c->get('voucher/invoice//' . md5('' . $db));
        $this->assertSame(404, $r->status, 'empty id with its signature no longer returns the latest transaction');
        $this->assertStringNotContainsString('INV-WP2', $r->body);
        $id = static::$seed->transactions['cB'];
        $this->assertSame(404, $c->get('voucher/invoice/' . $id . '/' . md5($id . 'wrong'))->status);
        $r = $c->get('voucher/invoice/' . $id . '/' . md5($id . $db));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('INV-WP2', $r->body);
    }

    /** NEW-2: reflected print content is escaped. */
    public function testPrintContentIsEscaped(): void
    {
        $r = $this->staff('sa')->postWithToken('plan/print', ['content' => '<script>alert(1)</script>']);
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $r->body);
        $this->assertStringContainsString('&lt;script&gt;', $r->body);
    }

    /** New WP2 runtime classes, CLI tools and test files are never executable over HTTP. */
    public function testWp2FilesRefuseDirectHttpExecution(): void
    {
        $c = $this->client();
        foreach (['system/autoload/Reseller/Security/RouteGuard.php', 'system/autoload/Reseller/Domain/ServiceGate.php', 'system/autoload/Reseller/Audit/AuditLog.php',
            'system/autoload/Reseller/Domain/ResellerTreeService.php', 'system/reseller/hierarchy.php', 'system/controllers/reseller.php'] as $p) {
            $r = $c->raw('GET', $p);
            $this->assertSame(404, $r->status, $p);
            $this->assertSame('', $r->body, $p);
        }
    }
}
