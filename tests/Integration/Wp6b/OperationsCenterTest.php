<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP6B slice 7 over real HTTP (owner decisions OD-7 / OD-8): the Operations Center is Admin / SuperAdmin only
 * (system.admin) and read-only; the web cancel / resume of a pre-provision activation goes through
 * ActivationOperatorService (Admin / SuperAdmin, stale only, the accepted primitives). A genuinely stuck
 * FUNDS_RESERVED activation is produced with the accepted WP5A fault hook (crash after the opening transaction).
 */
final class OperationsCenterTest extends RadiusHttpTestCase
{
    private static int $plan;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6b_opsc_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp6b_opsc', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        $plan = self::$plan;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\PricingService::setBaseCost(\$ad, {$plan}, '10');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, {$plan}, true);
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '100', 'initial provider funding', 'WP6B-O-A-0001');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    /** A FUNDS_RESERVED activation left by a crash after the opening transaction (WP5A fault point); its reference. */
    private function stuck(string $customer): string
    {
        $plan = self::$plan;
        $cid = static::$seed->customers[$customer];
        $ref = static::$app->exec(<<<PHP
\$a = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'A')->find_one()->as_array(), 'CLI');
\\Reseller\\Activation\\ActivationFaults::\$hook = static function (string \$p): void { if (\$p === 'activation.before_execute') { throw new \\RuntimeException('injected'); } };
\$action = \\Reseller\\Activation\\ActivationService::preview(\$a, {$cid}, {$plan})['action'];
try { \\Reseller\\Activation\\ActivationService::create(\$a, {$cid}, {$plan}, \$action, bin2hex(random_bytes(16))); } catch (\\RuntimeException \$e) { }
\$result = ORM::for_table('tbl_reseller_activations')->where('customer_id', {$cid})->order_by_desc('id')->find_one()->reference;
PHP);
        $this->assertMatchesRegularExpression('/^ACT-[0-9A-F]{16}$/', (string) $ref);
        $this->assertSame('FUNDS_RESERVED', $this->stateOf($ref));
        return $ref;
    }

    private function stateOf(string $ref): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]);
    }

    private function age(string $ref, int $seconds): void
    {
        static::$db->pdo->prepare('UPDATE tbl_reseller_activations SET updated_at = ? WHERE reference = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - $seconds) . '.000000', $ref]);
    }

    private function reservation(string $ref): string
    {
        return (string) static::$db->value('SELECT r.status FROM tbl_manager_wallet_reservations r JOIN tbl_reseller_activations a ON a.id = r.activation_id WHERE a.reference = ?', [$ref]);
    }

    public function testOnlyAdminsReachTheOperationsCenter(): void
    {
        foreach (['operations', 'operations/overview', 'operations/activations', 'operations/held', 'operations/renewals', 'operations/drift'] as $route) {
            foreach (['sa', 'ad'] as $u) {
                $r = $this->staff($u)->get($route);
                $this->assertSame(200, $r->status, "{$u} {$route}: " . $r->excerpt());
                if (in_array($route, ['operations', 'operations/overview', 'operations/activations'], true)) {
                    $this->assertStringContainsString('Needs attention', $r->body, $route);
                }
            }
            foreach (['A', 'A1', 'rp'] as $u) {
                $this->assertDenied($this->staff($u)->get($route), "{$u} {$route}");
            }
        }
        $drift = $this->staff('ad')->get('operations/drift', ['run' => '1'])->body;
        $this->assertMatchesRegularExpression('/No drift found|nuxr-drift-list/', $drift, 'the check ran on demand');
        $this->assertStringNotContainsString('balance-resolve', $this->staff('ad')->get('operations/held')->body, 'no held operation in this fixture');
    }

    public function testCancelIsRefusedWhileFreshAndCancelsOnceStale(): void
    {
        $ref = $this->stuck('cA1');
        $admin = $this->staff('ad');
        $page = $admin->get('activation/view/' . $ref)->body;
        $this->assertStringNotContainsString('activation/cancel/' . $ref, $page, 'no cancel while it may still be running');
        $events = static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ?', [$ref]);
        $r = $admin->postWithToken('activation/cancel/' . $ref, []);
        $this->assertSame(302, $r->status);
        $this->assertSame('FUNDS_RESERVED', $this->stateOf($ref), 'fresh: refused (ACTIVATION_IN_PROGRESS)');
        $this->assertSame($events, static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ?', [$ref]));
        $this->age($ref, 400);
        $ops = $admin->get('operations/activations')->body;
        $this->assertStringContainsString($ref, $ops, 'listed as stale');
        $this->assertStringContainsString('activation/cancel/' . $ref, $admin->get('activation/view/' . $ref)->body);
        // an Agent (activation.create T, not G) never cancels through the web
        $this->assertSame(302, $this->staff('A')->postWithToken('activation/cancel/' . $ref, [])->status);
        $this->assertSame('FUNDS_RESERVED', $this->stateOf($ref), 'the Agent is refused');
        $this->assertSame(302, $admin->postWithToken('activation/cancel/' . $ref, [])->status);
        $this->assertSame('CANCELLED', $this->stateOf($ref));
        $this->assertSame('RELEASED', $this->reservation($ref), 'the reserved credit is released');
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'activation.cancel' AND target_snapshot LIKE ?", ['%' . $ref . '%']));
    }

    public function testResumeOfAStaleActivationCompletesIt(): void
    {
        $ref = $this->stuck('cA2');
        $admin = $this->staff('ad');
        $this->assertSame(302, $admin->postWithToken('activation/resume/' . $ref, [])->status);
        $this->assertSame('FUNDS_RESERVED', $this->stateOf($ref), 'fresh: refused');
        $this->age($ref, 400);
        $this->assertStringContainsString('activation/resume/' . $ref, $admin->get('activation/view/' . $ref)->body);
        $this->assertSame(302, $admin->postWithToken('activation/resume/' . $ref, [])->status);
        $this->assertSame('COMPLETED', $this->stateOf($ref));
        $this->assertSame('COMMITTED', $this->reservation($ref));
        $this->assertSame(1, (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'activation.resume'"));
        // terminal now: nothing more to cancel or resume
        $this->assertSame(302, $admin->postWithToken('activation/cancel/' . $ref, [])->status);
        $this->assertSame('COMPLETED', $this->stateOf($ref));
    }

    public function testAnAttentionItemIsNeverListedTwice(): void
    {
        $ref = $this->stuck('cA0');
        static::$db->pdo->prepare("UPDATE tbl_reseller_activations SET status = 'ROLLBACK_PENDING', error_code = 'PRIOR_SECRET_UNRESTORABLE' WHERE reference = ?")->execute([$ref]);
        $this->age($ref, 900);
        $html = $this->staff('ad')->get('operations/activations')->body;
        $section = static fn(string $id): string => (string) substr($html, (int) strpos($html, 'id="' . $id . '"'), (int) strpos($html, '</section>', (int) strpos($html, 'id="' . $id . '"')) - (int) strpos($html, 'id="' . $id . '"'));
        $this->assertStringContainsString($ref, $section('nuxr-att-t'), 'needs attention');
        $this->assertStringNotContainsString($ref, $section('nuxr-stale-t'), 'not repeated as stale');
        // operator review (CLI / informational): the list offers no action, only the activation page
        $this->assertDoesNotMatchRegularExpression('/' . $ref . '.*?Open to act/s', $section('nuxr-att-t'));
    }

    public function testInformationalItemsCarryNoActionButtons(): void
    {
        $admin = $this->staff('ad');
        foreach (['operations/held', 'operations/renewals', 'operations/drift'] as $route) {
            $body = $admin->get($route, ['run' => '1'])->body;
            $main = substr($body, (int) strpos($body, 'id="nuxr-main"'));
            $this->assertDoesNotMatchRegularExpression('#<form[^>]+method="post"#i', $main, "{$route}: read-only");
            foreach (['activation/cancel/', 'activation/resume/', 'activation/rollback/', 'activation/reverse/', 'activation/reconcile/'] as $op) {
                $this->assertStringNotContainsString($op, $main, "{$route}: no operator action");
            }
        }
    }
}
