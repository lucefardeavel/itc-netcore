<?php
namespace Reseller\Tests\Integration\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;
use Reseller\Tests\Support\RunnerProcess;

/**
 * The minimal staff activation flow over real HTTP (owner decision A7; 13_TEST_ACCEPTANCE_MATRIX "duplicate
 * HTTP request returns existing operation outcome", "page reload/back does not duplicate operation"), its
 * authorization, and the reconciliation CLI tool (A4).
 */
final class ActivationHttpTest extends RadiusHttpTestCase
{
    private static int $plan;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp5a_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp5a_http', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        // provider base cost, resale and the Agent's funding through the real services (inside the app runtime)
        // an enabled RADIUS plan nobody prices: never offered to a reseller, listed for Admin only
        static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp5a_unpriced', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        $plan = self::$plan;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\PricingService::setBaseCost(\$ad, {$plan}, '10');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, {$plan}, true);
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '100', 'initial provider funding', 'F-wp5a-http-fund');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    private function form(\Reseller\Tests\Support\HttpClient $c, int $customer): array
    {
        $page = $c->get('activation/create', ['customer' => $customer, 'plan' => self::$plan]);
        $this->assertSame(200, $page->status, $page->excerpt());
        preg_match('/name="expected_action" value="([A-Z_]+)"/', $page->body, $a);
        preg_match('/name="nonce" value="([0-9a-f]{32})"/', $page->body, $n);
        // WP6A (owner decision OD-B): the confirmation carries the server-rendered quote fingerprint
        preg_match('/name="preview" value="([0-9a-f]{64})"/', $page->body, $f);
        $this->assertNotEmpty($a, 'preview shown: ' . $page->excerpt());
        $this->assertNotEmpty($f, 'quote fingerprint rendered: ' . $page->excerpt());
        return ['customer_id' => $customer, 'plan_id' => self::$plan, 'expected_action' => $a[1], 'nonce' => $n[1], 'preview' => $f[1]];
    }

    private function finance(): array
    {
        return $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events']);
    }

    public function testStaffFlowCompletesAndRepeatsAreSafe(): void
    {
        $agent = $this->staff('A');
        $customer = static::$seed->customers['cA0'];
        $post = $this->form($agent, $customer);
        $this->assertSame('NEW', $post['expected_action']);
        $r = $agent->postWithToken('activation/create-post', $post);
        $this->assertSame(302, $r->status);
        $this->assertMatchesRegularExpression('#activation/view/(ACT-[0-9A-F]{16})#', $r->headers['location'][0] ?? '');
        preg_match('#(ACT-[0-9A-F]{16})#', $r->headers['location'][0], $m);
        $ref = $m[1];
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
        $view = $agent->get('activation/view/' . $ref);
        $this->assertSame(200, $view->status);
        $this->assertStringContainsString('COMPLETED', $view->body);
        // duplicate POST (double click / resubmitted form) and reloads: the same activation, nothing new
        $before = $this->finance();
        for ($i = 0; $i < 3; $i++) {
            $again = $agent->postWithToken('activation/create-post', $post);
            $this->assertSame(302, $again->status);
            $this->assertStringContainsString($ref, $again->headers['location'][0] ?? '', 'the same activation');
            $this->assertSame(200, $agent->get('activation/view/' . $ref)->status);
        }
        $this->assertUnchanged($before, 'repeated POST and reloads', ['tbl_logs', 'tbl_reseller_audit_events']);
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ?', [$customer]));
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_ledger WHERE entry_type = 'ACTIVATION_DEBIT' AND manager_id = ?", [static::$seed->staff['A']]));
        $this->assertSame('90.00', static::$db->value('SELECT balance FROM tbl_manager_wallets WHERE manager_id = ?', [static::$seed->staff['A']]), 'top-level Agent pays the base cost');
        $this->assertNotSame([], static::radcheck('cA0'), 'provisioned');
    }

    public function testAuthorizationOverHttp(): void
    {
        $customer = static::$seed->customers['cA2'];
        // log in first: a login updates tbl_users.last_login
        $agent = $this->staff('A');
        $others = ['A1' => $this->staff('A1'), 'B' => $this->staff('B')];
        $report = $this->staff('rp');
        $post = $this->form($agent, $customer);
        $before = $this->finance();
        $agent->post('activation/create-post', $post); // no CSRF token
        // a Sales account of A that is not assigned to cA2, another Agent
        foreach ($others as $c) {
            $c->postWithToken('activation/create-post', ['nonce' => bin2hex(random_bytes(16))] + $post);
        }
        $this->assertSame(403, $report->get('activation/create')->status, 'Report: no activation capability');
        $this->assertUnchanged($before, 'refused requests', ['tbl_logs', 'tbl_reseller_audit_events']);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activations WHERE customer_id = ?', [$customer]));
        $this->assertSame(405, $this->staff('A')->get('activation/create-post')->status, 'mutations are POST-only');
        // the bare controller and unknown actions are the read action: a frozen Agent (read only) never reaches the create form
        $frozen = $this->staff('F');
        $this->assertSame(403, $frozen->get('activation/create')->status, 'frozen: no activation.create');
        foreach (['activation', 'activation/no-such-action'] as $route) {
            $r = $frozen->get($route);
            $this->assertSame(302, $r->status, $route);
            $this->assertStringNotContainsString('activation/create', $r->headers['location'][0] ?? '', "{$route}: not sent to a create form");
        }
        $this->assertSame(403, $this->staff('E')->get('activation')->status, 'a non-enrolled Agent has no tree read scope');
    }

    /** @return list<string> the plan names offered by the create form */
    private static function options(\Reseller\Tests\Support\HttpResponse $r): array
    {
        preg_match_all('/<option value="\d+"[^>]*>([^<(]*) \(/', $r->body, $m);
        return array_map('trim', $m[1]);
    }

    /** Owner fix round 1: actor eligibility and the per-context plan catalog over real HTTP. */
    public function testEligibilityAndCatalogOverHttp(): void
    {
        $customers = static::$seed->customers;
        $e = $this->staff('E'); // Active Agent, not enrolled
        $agent = $this->staff('A');
        $sales = $this->staff('A1'); // Sales of A, assigned to cA1
        $admin = $this->staff('ad');
        $before = $this->finance();
        $this->assertSame(403, $e->get('activation')->status);
        foreach ([[], ['customer' => $customers['cA0'], 'plan' => self::$plan], ['customer' => 99999999, 'plan' => self::$plan]] as $q) {
            $r = $e->get('activation/create', $q);
            $this->assertSame(403, $r->status, 'unenrolled Agent: no create form ' . json_encode($q));
            $this->assertStringNotContainsString('wp5a_http', $r->body, 'no plan catalog rendered');
            $this->assertStringNotContainsString('Customer ID', $r->body);
        }
        $this->assertSame(403, $e->postWithToken('activation/create-post', ['customer_id' => $customers['cA0'], 'plan_id' => self::$plan, 'expected_action' => 'NEW', 'nonce' => bin2hex(random_bytes(16))])->status);
        $this->assertUnchanged($before, 'unenrolled Agent', ['tbl_logs', 'tbl_reseller_audit_events']);

        $mine = $agent->get('activation/create');
        $this->assertSame(200, $mine->status);
        $this->assertContains('wp5a_http', self::options($mine), 'the enrolled Agent sees its saleable plan');
        $this->assertNotContains('wp5a_unpriced', self::options($mine), 'never a plan it cannot sell');
        $this->assertDoesNotMatchRegularExpression('/wholesale|commission|settlement|base cost/i', strip_tags($mine->body), 'no upstream economics');

        $this->assertSame([], self::options($sales->get('activation/create')), 'Sales without a customer: no plans');
        $this->assertSame(['wp5a_http'], array_values(array_intersect(self::options($sales->get('activation/create', ['customer' => $customers['cA1']])), ['wp5a_http', 'wp5a_unpriced'])),
            'Sales with its assigned customer: the owner\'s saleable plans');
        foreach (['cA2', 'cB', 'cNULL'] as $other) {
            $r = $sales->get('activation/create', ['customer' => $customers[$other]]);
            $this->assertSame(200, $r->status);
            $this->assertSame([], self::options($r), "{$other}: not in the Sales scope, nothing listed");
            $this->assertStringContainsString('Not found', $r->body, "{$other}: same answer as a missing customer");
        }

        $all = self::options($admin->get('activation/create'));
        $this->assertContains('wp5a_http', $all);
        $this->assertContains('wp5a_unpriced', $all, 'Admin keeps the administrative list');
    }

    public function testReconciliationIsAdminOnlyAndTheCliToolWorks(): void
    {
        $agent = $this->staff('A');
        $ref = (string) static::$db->value("SELECT reference FROM tbl_reseller_activations WHERE status = 'COMPLETED' ORDER BY id LIMIT 1");
        if ($ref === '') {
            $agent->postWithToken('activation/create-post', $this->form($agent, static::$seed->customers['cA0']));
            $ref = (string) static::$db->value("SELECT reference FROM tbl_reseller_activations WHERE status = 'COMPLETED' ORDER BY id LIMIT 1");
        }
        $admin = $this->staff('ad');
        $before = $this->finance();
        $agent->postWithToken('activation/reconcile/' . $ref, []);
        $agent->postWithToken('activation/rollback/' . $ref, []);
        $admin->postWithToken('activation/rollback/' . $ref, []); // COMPLETED cannot be rolled back (refunds are WP5B)
        $this->assertUnchanged($before, 'reconciliation refused', ['tbl_logs', 'tbl_reseller_audit_events']);
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
        // the CLI tool (system actor), run inside the app copy
        $cli = static fn(array $args): array => RunnerProcess::exec([PHP_BINARY, static::$app->dir . '/system/reseller/reconcile.php', ...$args]);
        $show = $cli(['show', $ref]);
        $this->assertSame(0, $show['exit'], $show['raw']);
        $this->assertStringContainsString('"status":"COMPLETED"', $show['raw']);
        $this->assertStringContainsString('FINANCIAL FINANCIAL_COMMITTING -> COMPLETED', $show['raw']);
        $this->assertSame(0, $cli(['stale', '--seconds=0'])['exit']);
        $this->assertSame(2, $cli(['rollback', $ref])['exit'], 'refused: not in a reconcilable state');
        $this->assertSame(5, $cli(['nonsense'])['exit']);
        $web = $this->client()->raw('GET', 'system/reseller/reconcile.php');
        $this->assertSame(404, $web->status, 'CLI only');
    }
}
