<?php
namespace Reseller\Tests\Integration\Wp6b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationOperatorService as Ops;
use Reseller\Activation\ActivationState as S;
use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP6B slice 0 (owner correction of a WP6A UI defect found in WP6B reconnaissance): the activation page offers an
 * operator action only where the accepted primitive accepts the state. WP6A offered Reconcile for VERIFIED and
 * ROLLBACK_PENDING, which ReconciliationService::reconcile() always refuses.
 *
 * The status relabelling targets presentation only (a disposable database); the state machine itself is covered by
 * the WP5A / WP5B suites.
 */
final class ActivationActionMatrixTest extends RadiusHttpTestCase
{
    private static int $plan;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6b_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp6b_ops', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        $plan = self::$plan;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\PricingService::setBaseCost(\$ad, {$plan}, '10');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, {$plan}, true);
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '100', 'initial provider funding', 'F-wp6b-ops');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    /** One completed activation through the real web flow; returns its reference. */
    private function activation(): string
    {
        $c = $this->staff('A');
        $page = $c->get('activation/create', ['customer' => static::$seed->customers['cA2'], 'plan' => self::$plan]);
        $f = [];
        foreach (['customer_id', 'plan_id', 'expected_action', 'nonce', 'preview'] as $n) {
            preg_match('/name="' . $n . '" value="([^"]+)"/', $page->body, $m);
            $f[$n] = html_entity_decode($m[1] ?? '');
        }
        $r = $c->postWithToken('activation/create-post', $f);
        $this->assertSame(302, $r->status);
        $this->assertMatchesRegularExpression('#(ACT-[0-9A-F]{16})#', (string) $r->location());
        preg_match('#(ACT-[0-9A-F]{16})#', (string) $r->location(), $m);
        return $m[1];
    }

    /** @return list<string> the operator actions rendered on the activation page */
    private static function rendered(string $html, string $ref): array
    {
        $out = [];
        if (str_contains($html, 'activation/reconcile/' . $ref)) {
            $out[] = Ops::RECONCILE;
        }
        if (str_contains($html, 'id="nuxr-rollback-dialog"') && str_contains($html, 'activation/rollback/' . $ref)) {
            $out[] = Ops::ROLLBACK;
        }
        if (str_contains($html, 'id="nuxr-reverse-dialog"') && str_contains($html, 'activation/reverse/' . $ref)) {
            $out[] = Ops::REVERSE;
        }
        return $out;
    }

    public function testEveryStateRendersExactlyTheAcceptedOperations(): void
    {
        $ref = $this->activation();
        $set = function (string $status, int $ageSeconds) use ($ref): void {
            static::$db->pdo->prepare('UPDATE tbl_reseller_activations SET status = ?, updated_at = ? WHERE reference = ?')
                ->execute([$status, gmdate('Y-m-d H:i:s', time() - $ageSeconds) . '.000000', $ref]);
        };
        // the accepted primitives' state preconditions (ReconciliationService::reconcile / rollback,
        // ActivationReversalService::reverse); in-flight states are reconcilable only once stale (300 s)
        $expected = [
            S::CREATED => [], S::VALIDATED => [], S::FUNDS_RESERVED => [], S::PROVISIONING => [], S::PROVISIONED => [],
            S::VERIFYING => [], S::VERIFIED => [Ops::ROLLBACK], S::FINANCIAL_COMMITTING => [], S::COMPLETED => [Ops::REVERSE],
            S::FAILED => [], S::RETRY_PENDING => [], S::RECONCILIATION_REQUIRED => [Ops::RECONCILE, Ops::ROLLBACK],
            S::ROLLBACK_PENDING => [Ops::ROLLBACK], S::ROLLED_BACK => [], S::CANCELLED => [], S::REVERSAL_PENDING => [Ops::REVERSE], S::REVERSED => [],
        ];
        $this->assertSame(S::ALL, array_keys($expected), 'all 17 states covered');
        $admin = $this->staff('ad');
        $agent = $this->staff('A');
        foreach ($expected as $state => $ops) {
            $set($state, 30);
            $html = $admin->get('activation/view/' . $ref)->body;
            $this->assertSame($ops, self::rendered($html, $ref), "{$state} (fresh): rendered operator actions");
            $this->assertSame([], self::rendered($agent->get('activation/view/' . $ref)->body, $ref), "{$state}: an Agent gets no operator action");
            if (in_array($state, Ops::IN_FLIGHT, true)) {
                $set($state, 400);
                $this->assertSame([Ops::RECONCILE], self::rendered($admin->get('activation/view/' . $ref)->body, $ref), "{$state} (stale): reconcile only");
            }
        }
        // the regression itself: Reconcile is never offered for VERIFIED / ROLLBACK_PENDING, because the backend
        // always refuses it there and writes nothing
        foreach ([S::VERIFIED, S::ROLLBACK_PENDING] as $state) {
            $set($state, 400);
            $this->assertStringNotContainsString('activation/reconcile/' . $ref, $admin->get('activation/view/' . $ref)->body, $state);
            $events = static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ?', [$ref]);
            $r = $admin->postWithToken('activation/reconcile/' . $ref, []);
            $this->assertSame(302, $r->status);
            $this->assertSame($state, static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]), "{$state}: reconcile refused, state unchanged");
            $this->assertSame($events, static::$db->value('SELECT COUNT(*) FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ?', [$ref]), "{$state}: nothing written");
        }
        $set(S::COMPLETED, 400);
    }

    /** Drift guard: the operation table equals the state preconditions written in the accepted primitives. */
    public function testTheOperationTableMirrorsTheAcceptedPrimitives(): void
    {
        $root = dirname(__DIR__, 3) . '/system/autoload/Reseller/Activation/';
        $rec = (string) file_get_contents($root . 'ReconciliationService.php');
        $rev = (string) file_get_contents($root . 'ActivationReversalService.php');
        $body = static function (string $src, string $fn): string {
            $start = strpos($src, 'public static function ' . $fn . '(');
            return (string) substr($src, (int) $start, (int) strpos($src, "\n    }\n", (int) $start) - (int) $start);
        };
        $states = static fn(string $s): array => preg_match_all('/ActivationState::([A-Z_]+)/', $s, $m) ? array_map(static fn(string $n): string => constant(S::class . '::' . $n), $m[1]) : [];
        preg_match('/private const IN_FLIGHT = \[([^\]]+)\]/', $rec, $m);
        $this->assertSame(Ops::IN_FLIGHT, $states($m[1]), 'stale in-flight set');
        $reconcile = $body($rec, 'reconcile');
        $this->assertStringContainsString("if (\$act['status'] !== ActivationState::RECONCILIATION_REQUIRED)", $reconcile, 'reconcile accepts RECONCILIATION_REQUIRED only (after the stale in-flight move)');
        preg_match('/if \(!in_array\(\$act\[\'status\'\], \[([^\]]+)\], true\)\)/', $body($rec, 'rollback'), $m);
        $rollback = $states($m[1]);
        $reverse = [S::COMPLETED, S::REVERSAL_PENDING];
        $this->assertStringContainsString("\$act['status'] === ActivationState::COMPLETED", $body($rev, 'reverse'));
        $this->assertStringContainsString("\$act['status'] !== ActivationState::REVERSAL_PENDING", $body($rev, 'reverse'));
        $derived = [];
        foreach (S::ALL as $state) {
            $ops = array_values(array_filter([
                $state === S::RECONCILIATION_REQUIRED ? Ops::RECONCILE : null,
                in_array($state, $rollback, true) ? Ops::ROLLBACK : null,
                in_array($state, $reverse, true) ? Ops::REVERSE : null,
            ]));
            if ($ops !== []) {
                $derived[$state] = $ops;
            }
        }
        $table = Ops::STATE_OPERATIONS;
        ksort($table);
        ksort($derived);
        $this->assertSame($derived, $table, 'ActivationOperatorService::STATE_OPERATIONS equals the accepted primitives');
    }
}
