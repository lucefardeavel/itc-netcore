<?php
namespace Reseller\Tests\Integration\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP6A activation UX over real HTTP: the live confirmation (OD-B visibility), the browser never being money authority,
 * the stale-quote refusal, the scoped customer picker, the persisted-state status page (money statements only when the
 * reservation proves them; no blind retry for UNKNOWN; no raw error text) and operator actions for Admin only.
 *
 * The status-mapping case relabels completed activations in this disposable database to reach every state's page; it
 * checks presentation only (the state machine itself is covered by the WP5A / WP5B suites).
 */
final class ActivationUxTest extends RadiusHttpTestCase
{
    private static int $plan;

    /** @var array<string,\Reseller\Tests\Support\HttpClient> one browser session per actor (the quote key is per session) */
    private array $sessions = [];

    private function session(string $actor): \Reseller\Tests\Support\HttpClient
    {
        return $this->sessions[$actor] ??= $this->staff($actor);
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp6a_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp6a_ux', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        $plan = self::$plan;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\PricingService::setBaseCost(\$ad, {$plan}, '10');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, {$plan}, true);
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '100', 'initial provider funding', 'F-wp6a-ux');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
    }

    /** @return array{0:string,1:array<string,string>} confirmation page and its POST fields */
    private function confirmation(string $actor, int $customer): array
    {
        $page = $this->session($actor)->get('activation/create', ['customer' => $customer, 'plan' => self::$plan]);
        $this->assertSame(200, $page->status, $page->excerpt());
        $f = [];
        foreach (['customer_id', 'plan_id', 'expected_action', 'nonce', 'preview'] as $n) {
            $this->assertMatchesRegularExpression('/name="' . $n . '" value="([^"]+)"/', $page->body, "{$actor}: {$n}");
            preg_match('/name="' . $n . '" value="([^"]+)"/', $page->body, $m);
            $f[$n] = html_entity_decode($m[1]);
        }
        return [$page->body, $f];
    }

    private function confirm(string $actor, array $fields): string
    {
        $r = $this->session($actor)->postWithToken('activation/create-post', $fields);
        $this->assertSame(302, $r->status);
        return (string) $r->location();
    }

    public function testConfirmationShowsLiveServerValuesByRole(): void
    {
        $cid = static::$seed->customers['cA1'];
        [$admin] = $this->confirmation('ad', $cid);
        [$agent] = $this->confirmation('A', $cid);
        [$sales] = $this->confirmation('A1', $cid);
        foreach (['ad' => $admin, 'A' => $agent] as $who => $html) {
            $this->assertStringContainsString('Wholesale - reseller cost', $html, $who);
            $this->assertStringContainsString('Retail - customer price', $html, $who);
            $this->assertStringContainsString('Available credit now', $html, $who);
            $this->assertMatchesRegularExpression('#nuxr-money is-wholesale.*?<bdi dir="ltr">[^<]*\b10\b#s', $html, "{$who}: the resolved wholesale");
            $this->assertMatchesRegularExpression('#Available credit after.*?<bdi dir="ltr">[^<]*\b90\b#s', $html, "{$who}: 100 - 10");
        }
        $this->assertStringNotContainsString('Wholesale', $sales, 'Sales: never wholesale');
        $this->assertStringNotContainsString('Available credit', $sales, 'Sales: never the reseller credit');
        $this->assertStringContainsString('Retail - customer price', $sales);
        $this->assertStringContainsString('Credit sufficient: yes', $sales);
        $this->assertStringNotContainsString('wp6a_ux · 20', $sales);
    }

    public function testBrowserMoneyIsIgnoredAndAStaleQuoteWritesNothing(): void
    {
        $cid = static::$seed->customers['cA0'];
        [, $f] = $this->confirmation('A', $cid);
        // the quote changes after the review: the leaf wholesale rises
        $plan = self::$plan;
        $a = static::$seed->staff['A'];
        $this->assertSame('ok', static::$app->exec("\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');"
            . "\\Reseller\\Finance\\PricingService::setChildWholesale(\$ad, {$a}, {$plan}, '11'); \$result = 'ok';"));
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events']);
        $loc = $this->confirm('A', $f);
        $this->assertStringContainsString('activation/create&customer=' . $cid . '&plan=' . $plan, $loc, 'back to the updated confirmation');
        $this->assertUnchanged($before, 'a stale quote writes nothing', ['tbl_logs', 'tbl_reseller_audit_events']);
        [$fresh, $f] = $this->confirmation('A', $cid);
        $this->assertMatchesRegularExpression('#nuxr-money is-wholesale.*?<bdi dir="ltr">[^<]*\b11\b#s', $fresh, 'the updated quote');
        // forged money / period fields in the POST change nothing: the server charges its own resolution
        $loc = $this->confirm('A', $f + ['wholesale' => '0.01', 'retail' => '0.01', 'price' => '0.01', 'amount' => '0.01', 'expiry' => '2099-01-01']);
        preg_match('#(ACT-[0-9A-F]{16})#', $loc, $m);
        $this->assertNotEmpty($m, $loc);
        $this->assertSame(['COMPLETED', '11.00'], array_values(static::$db->rows('SELECT status, wholesale_cost FROM tbl_reseller_activations WHERE reference = ?', [$m[1]])[0]));
        $this->assertStringContainsString($m[1], $this->confirm('A', $f), 'a double submit returns the same activation');
    }

    public function testCustomerPickerIsScopedToActivatableCustomers(): void
    {
        $page = $this->staff('A')->get('activation/create', ['q' => 'cA'])->body;
        preg_match_all('#activation/create&customer=(\d+)#', $page, $m);
        $ids = array_map('intval', array_unique($m[1]));
        $c = static::$seed->customers;
        $this->assertContains($c['cA0'], $ids);
        $this->assertNotContains($c['cB'], $ids, "an ancestor never activates a child's customer (A5)");
        $this->assertNotContains($c['cC1'], $ids);
        $this->assertNotContains($c['cNULL'], $ids, 'no legacy customer');
        $sales = $this->staff('A1')->get('activation/create', ['q' => 'cA'])->body;
        preg_match_all('#activation/create&customer=(\d+)#', $sales, $m);
        $this->assertSame([$c['cA1']], array_values(array_map('intval', array_unique($m[1]))), 'Sales: assigned customers only');
        $this->assertStringNotContainsString('name="customer" type="number"', $page, 'no typed customer id');
    }

    public function testStatusPageMapsEveryStateHonestly(): void
    {
        $cid = static::$seed->customers['cA2'];
        [, $f] = $this->confirmation('A', $cid);
        preg_match('#(ACT-[0-9A-F]{16})#', $this->confirm('A', $f), $m);
        $ref = $m[1];
        $id = (int) static::$db->value('SELECT id FROM tbl_reseller_activations WHERE reference = ?', [$ref]);
        $set = function (string $status, ?string $code, string $reservation) use ($id): void {
            static::$db->pdo->prepare('UPDATE tbl_reseller_activations SET status = ?, error_code = ?, error_message = ? WHERE id = ?')
                ->execute([$status, $code, $code !== null ? 'SQLSTATE[HY000] secret internal detail' : null, $id]);
            static::$db->pdo->prepare('UPDATE tbl_manager_wallet_reservations SET status = ?, resolved_at = ? WHERE activation_id = ?')
                ->execute([$reservation, $reservation === 'ACTIVE' ? null : gmdate('Y-m-d H:i:s'), $id]);
        };
        $page = fn(string $actor): string => $this->session($actor)->get('activation/view/' . $ref)->body;
        $cases = [
            ['COMPLETED', null, 'COMMITTED', ['Service active until', 'The wholesale cost was charged'], ['released', 'refunded']],
            ['FAILED', 'PROVISION_RETRIES_EXHAUSTED', 'RELEASED', ['Not activated', 'The reserved credit was released'], ['cost was charged']],
            ['FAILED', 'PROVISION_RETRIES_EXHAUSTED', 'COMMITTED', ['Not activated'], ['released']],
            ['RECONCILIATION_REQUIRED', 'RADIUS_COMMIT_UNKNOWN', 'ACTIVE', ['Do not repeat the activation', 'stay held'], ['released', 'refunded']],
            ['RECONCILIATION_REQUIRED', 'EXTERNAL_STATE_AMBIGUOUS', 'ACTIVE', ['Operator review required', 'No funds were released automatically'], []],
            ['ROLLBACK_PENDING', 'PRIOR_SECRET_UNRESTORABLE', 'ACTIVE', ['cannot be restored automatically'], []],
            ['REVERSAL_PENDING', null, 'COMMITTED', ['must be resumed', 'Nothing has been refunded yet'], ['was refunded']],
            ['REVERSED', null, 'COMMITTED', ['was reversed', 'The wholesale cost was refunded'], []],
            ['PROVISIONING', null, 'ACTIVE', ['Do not repeat this activation', 'data-nuxr-poll'], ['released', 'cost was charged']],
        ];
        foreach ($cases as [$status, $code, $res, $present, $absent]) {
            $set($status, $code, $res);
            $html = $page('A');
            foreach ($present as $p) {
                $this->assertStringContainsString($p, $html, "{$status}/{$code}/{$res}: {$p}");
            }
            $text = strip_tags((string) preg_replace('#<script.*?</script>#s', '', $html));
            foreach ($absent as $a) {
                $this->assertStringNotContainsString($a, $text, "{$status}/{$code}/{$res}: never '{$a}'");
            }
            $this->assertStringNotContainsString('SQLSTATE', $html, 'no raw error text');
            $this->assertStringNotContainsString('activation/reconcile', $html, 'Agent: no operator actions');
            $this->assertStringNotContainsString('activation/rollback', $html);
            $this->assertStringNotContainsString('activation/reverse', $html);
            $this->assertStringNotContainsString('activation/reverse', $page('A1'), 'Sales: no operator actions');
        }
        $set('RECONCILIATION_REQUIRED', 'RADIUS_COMMIT_UNKNOWN', 'ACTIVE');
        $admin = $page('ad');
        $this->assertStringContainsString('activation/reconcile/' . $ref, $admin);
        $this->assertStringContainsString('nuxr-rollback-dialog', $admin, 'roll back behind a confirmation');
        $set('COMPLETED', null, 'COMMITTED');
        $this->assertMatchesRegularExpression('#activation/reverse/' . $ref . '.*name="reason"[^>]*required.*data-nuxr-match="' . $ref . '"#s', $page('ad'), 'reverse: reason + typed reference');
        $this->assertStringContainsString('Wholesale - reseller cost', $page('A'), 'the paying Agent sees its own cost');
        $this->assertStringNotContainsString('Wholesale', $page('A1'), 'Sales never sees wholesale');
        $list = $this->staff('A')->get('activation/list', ['group' => 'completed'])->body;
        $this->assertStringContainsString($ref, $list);
        $this->assertStringNotContainsString($ref, $this->staff('A')->get('activation/list', ['group' => 'attention'])->body);
    }
}
