<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\RadiusHttpTestCase;
use Reseller\Tests\Support\StubNas;
use Reseller\Tests\Support\Wp2Seed;

/**
 * WP8 A5 (R-WP8-CARRY-01) over real HTTP: the legacy removal / deactivation paths
 *   P1 plan/delete/<recharge>                    (Admin; POST + CSRF)
 *   P2 customers/deactivate/<customer>/<plan>    (Admin; POST + CSRF)
 *   P3 home?deactivate=<recharge>                (customer portal; POST + CSRF, RouteMap dashboard#deactivate)
 * are refused for a reseller-owned customer BEFORE any hook, device call, CoA / Disconnect, database or financial
 * effect (owner decisions 2026-10-12 #1-#5), while unassigned legacy customers keep today's behaviour exactly.
 *
 * A reseller-owned customer here has a real COMPLETED activation (staff activation flow, Radius plan, real RADIUS rows)
 * and an open accounting session (nasipaddress = the stub's nasname) on a silent stub NAS: the Radius device removal
 * (suspend + CoA Disconnect) would reach it, which the positive control T1c proves. Device effects of the Radius plan are
 * therefore observed as "RADIUS tables byte-identical + no packet at the stub NAS"; the harness Probe device (legacy
 * plans, T1b / T3 / T4) records its calls. Hook effects: a test plugin on 'delete_customer_active_plan'; Telegram: the
 * harness probe. "Unchanged" = every table byte-identical (DbProbe), the append-only audit and the legacy log excluded:
 * one service.refused audit per refusal is required (schema ready), not an unwanted change.
 *
 *   T1  P1 / P2 / P3 refused, nothing changed, no hook / device / Disconnect / notification
 *   T1b P3: logged-in unassigned customer, matched recharge row of a reseller-owned customer: refused
 *   T1c positive control: the same session wiring sees the Disconnect when the Radius device removal really runs
 *   T2  after the refusals the activation is still reversible (activation/reverse -> REVERSED)
 *   T3  unassigned customers: P1 / P2 / P3 behave as before (device, hook, row, notification)
 *   T4  reseller schema not ready: P1 / P2 / P3 refused for every customer, nothing changed
 *   T5  API (admin token) P1 / P2: refused, nothing changed;  T6 Agent / Sales still 403
 *   T6b after an ownership transfer to another reseller: still refused
 *   L1  (D-A5-1) P1 / P2 never fall back to the session customer: an Admin who used "login as customer" on a
 *       reseller-owned customer X, then P1 on a recharge row with customer_id = 0 (a legacy voucher-only row) or P2 with
 *       customer id 0 / a malformed id, used to remove X's service (User::_info() session fallback). Refused before any
 *       hook, device call or write; X's service unchanged
 *   T8  the shared gate's ownership read fails: blocked (fail closed), checked in the app runtime (not over HTTP); that
 *       every A5 path calls the gate before any effect is proven by T9
 */
final class LegacyRemovalGateTest extends RadiusHttpTestCase
{
    private const SECRET = 'a5-stub-nas-secret';
    private static int $plan;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'a5_bw', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'a5_radius', 'id_bw' => $bw, 'price' => '20', 'type' => 'Hotspot', 'typebp' => 'Unlimited', 'limit_type' => 'Time_Limit',
            'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB', 'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 1,
            'routers' => 'radius', 'is_radius' => 1, 'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'Radius',
        ]);
        $plan = self::$plan;
        $a = static::$seed->staff['A'];
        $out = static::$app->exec(<<<PHP
\$ad = \\Reseller\\Security\\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\\Reseller\\Finance\\PricingService::setBaseCost(\$ad, {$plan}, '10');
\\Reseller\\Finance\\PricingService::setResaleEnabled(\$ad, {$plan}, true);
\\Reseller\\Finance\\TransferService::fund(\$ad, {$a}, '1000', 'A5 provider funding', 'F-a5-gate-fund');
\$result = 'ok';
PHP);
        if ($out !== 'ok') {
            throw new \RuntimeException('fixture failed: ' . json_encode($out));
        }
        // test plugin (disposable app copy only): records the P1 hook
        file_put_contents(static::$app->dir . '/system/plugin/a5_probe.php', "<?php\nregister_hook('delete_customer_active_plan', 'a5probe_hook');\n"
            . "function a5probe_hook() { @file_put_contents(__DIR__ . '/../../wp2_probe/hook.log', json_encode('delete_customer_active_plan') . \"\\n\", FILE_APPEND | LOCK_EX); }\n");
    }

    // ------------------------------------------------------------------------------------------------------- fixture

    private function newCustomer(string $tag, ?int $manager): array
    {
        $u = 'a5' . $tag . bin2hex(random_bytes(2));
        $cid = static::$db->insertRow('tbl_customers', [
            'username' => $u, 'password' => Wp2Seed::customerPassword($u), 'fullname' => 'A5 ' . $u, 'address' => '', 'phonenumber' => '0711' . random_int(100000, 999999),
            'email' => $u . '@example.invalid', 'service_type' => 'Hotspot', 'account_type' => 'Personal', 'coordinates' => '', 'status' => 'Active',
            'balance' => 0, 'created_by' => $this->sid('ad'), 'auto_renewal' => 0, 'manager_id' => $manager,
        ]);
        return [$cid, $u];
    }

    /** an unassigned legacy customer with an active recharge on the harness Probe-device plan */
    private function legacy(string $tag, ?string $rowUsername = null, ?int $rowCustomer = null): array
    {
        [$cid, $u] = $this->newCustomer($tag, null);
        $rid = static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $rowCustomer ?? $cid, 'username' => $rowUsername ?? $u, 'plan_id' => static::$seed->planId, 'namebp' => 'wp2_plan',
            'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', time() + 86400 * 10), 'time' => '23:59:59',
            'status' => 'on', 'method' => 'Admin - a5', 'routers' => 'wp2_router', 'type' => 'Hotspot', 'admin_id' => $this->sid('ad'),
        ]);
        return [$cid, $u, $rid];
    }

    /** a reseller-owned customer of Agent A with a real COMPLETED activation: [customer id, username, recharge id, reference] */
    private function activated(string $tag): array
    {
        [$cid, $u] = $this->newCustomer($tag, $this->sid('A'));
        $agent = $this->staff('A');
        $page = $agent->get('activation/create', ['customer' => $cid, 'plan' => self::$plan]);
        $this->assertSame(200, $page->status, $page->excerpt());
        preg_match('/name="expected_action" value="([A-Z_]+)"/', $page->body, $a);
        preg_match('/name="nonce" value="([0-9a-f]{32})"/', $page->body, $n);
        preg_match('/name="preview" value="([0-9a-f]{64})"/', $page->body, $f);
        $this->assertNotEmpty($f, 'activation preview: ' . $page->excerpt());
        $r = $agent->postWithToken('activation/create-post', ['customer_id' => $cid, 'plan_id' => self::$plan, 'expected_action' => $a[1], 'nonce' => $n[1], 'preview' => $f[1]]);
        $this->assertSame(1, preg_match('#(ACT-[0-9A-F]{16})#', (string) ($r->headers['location'][0] ?? ''), $m), $r->excerpt());
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$m[1]]));
        $rid = (int) static::$db->value("SELECT id FROM tbl_user_recharges WHERE customer_id = ? AND status = 'on' ORDER BY id DESC LIMIT 1", [$cid]);
        $this->assertGreaterThan(0, $rid);
        $this->assertNotSame([], static::radcheck($u), 'real RADIUS policy');
        return [$cid, $u, $rid, $m[1]];
    }

    /** an open accounting session of $username on a silent stub NAS: any Disconnect / CoA would reach it */
    private function liveSession(string $username): StubNas
    {
        static::$db->exec('DELETE FROM nas');
        $stub = new StubNas(self::SECRET, 'silent');
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'a5stub', 'type' => 'other', 'ports' => $stub->port, 'secret' => self::SECRET, 'description' => 'A5 stub NAS']);
        static::$db->insertRow('radacct', ['acctsessionid' => 'a5-' . bin2hex(random_bytes(4)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $username,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'framedipaddress' => '10.55.0.' . random_int(2, 250)]);
        return $stub;
    }

    private function refusals(): int
    {
        return count($this->auditRows('service.refused'));
    }

    /** P1 / P2 / P3 requests (POST + CSRF; P3 with the recharge id in the query, as RouteMap dashboard#deactivate) */
    private function p1(HttpClient $staff, int $rid): \Reseller\Tests\Support\HttpResponse
    {
        return $staff->postWithToken('plan/delete/' . $rid);
    }

    private function p2(HttpClient $staff, int $cid, int $planId): \Reseller\Tests\Support\HttpResponse
    {
        return $staff->postWithToken('customers/deactivate/' . $cid . '/' . $planId);
    }

    private function p2Raw(HttpClient $staff, string $customer, int $planId): \Reseller\Tests\Support\HttpResponse
    {
        return $staff->postWithToken('customers/deactivate/' . $customer . '/' . $planId);
    }

    private function p3(HttpClient $customer, string $token, int $rid): \Reseller\Tests\Support\HttpResponse
    {
        return $customer->post('home', ['csrf_token' => $token], ['deactivate' => (string) $rid]);
    }

    /** refused, nothing changed, no hook / device / notification, one service.refused audit for $operation */
    private function assertRefused(\Reseller\Tests\Support\HttpResponse $r, array $before, int $refusals, string $operation, int $cid, string $label): void
    {
        $this->assertSame(302, $r->status, $label . ': ' . $r->excerpt());
        $this->assertUnchanged($before, $label);
        $this->assertSame([], static::$app->probe('hook'), "{$label}: no hook effect");
        $this->assertSame([], static::$app->probe('device'), "{$label}: no device call");
        $this->assertSame([], static::$app->probe('telegram'), "{$label}: no notification");
        $rows = $this->auditRows('service.refused');
        $this->assertCount($refusals + 1, $rows, "{$label}: one refusal audit");
        $last = end($rows);
        $this->assertSame($operation, json_decode((string) $last['after_json'], true)['operation'] ?? null, $label);
        $this->assertSame($cid, (int) $last['target_customer_id'], $label);
    }

    // ---------------------------------------------------------------------------------------------------------- T1

    public function testP1PlanDeleteIsRefusedForAResellerCustomerBeforeAnyEffect(): void
    {
        [$cid, $u, $rid, $ref] = $this->activated('p1');
        $stub = $this->liveSession($u);
        $admin = $this->staff('ad');
        static::$app->clearProbes();
        $before = $this->snapshot();
        $n = $this->refusals();
        $r = $this->p1($admin, $rid);
        $this->assertStringContainsString('plan/list', (string) $r->location());
        $this->assertRefused($r, $before, $n, 'planDelete', $cid, 'P1');
        $this->assertSame([], $stub->received(), 'P1: no Disconnect / CoA reached the NAS');
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
    }

    public function testP2CustomerDeactivateIsRefusedForAResellerCustomerBeforeAnyEffect(): void
    {
        [$cid, $u, , $ref] = $this->activated('p2');
        $stub = $this->liveSession($u);
        $admin = $this->staff('ad');
        static::$app->clearProbes();
        $before = $this->snapshot();
        $n = $this->refusals();
        $r = $this->p2($admin, $cid, self::$plan);
        $this->assertStringContainsString('customers/view/' . $cid, (string) $r->location());
        $this->assertRefused($r, $before, $n, 'customerDeactivate', $cid, 'P2');
        $this->assertSame([], $stub->received(), 'P2: no Disconnect / CoA reached the NAS');
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
    }

    public function testP3PortalSelfDeactivationIsRefusedForAResellerCustomerBeforeAnyEffect(): void
    {
        [$cid, $u, $rid, $ref] = $this->activated('p3');
        $stub = $this->liveSession($u);
        $c = $this->customer($u);
        $token = $c->token('home');
        static::$app->clearProbes();
        $before = $this->snapshot();
        $n = $this->refusals();
        $r = $this->p3($c, $token, $rid);
        $this->assertStringContainsString('home', (string) $r->location());
        $this->assertRefused($r, $before, $n, 'portalDeactivate', $cid, 'P3');
        $this->assertSame([], $stub->received(), 'P3: no Disconnect / CoA reached the NAS');
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
        // P3 stays POST-only: a GET is refused by the route policy (no GET mutation)
        $this->assertSame(405, $c->get('home', ['deactivate' => (string) $rid])->status);
        $this->assertUnchanged($before, 'P3 GET');
    }

    // --------------------------------------------------------------------------------------------------------- T1b

    public function testP3IsRefusedWhenTheMatchedRechargeBelongsToAResellerCustomer(): void
    {
        // the portal matches the recharge row by username: an unassigned customer whose username is on a reseller-owned
        // customer's recharge row (a username collision) must not end that customer's service
        [$owned] = $this->newCustomer('own', $this->sid('A'));
        [$cid, $u] = $this->newCustomer('col', null);
        $rid = static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => $owned, 'username' => $u, 'plan_id' => static::$seed->planId, 'namebp' => 'wp2_plan', 'recharged_on' => gmdate('Y-m-d'),
            'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', time() + 86400 * 10), 'time' => '23:59:59', 'status' => 'on',
            'method' => 'Admin - a5', 'routers' => 'wp2_router', 'type' => 'Hotspot', 'admin_id' => $this->sid('ad'),
        ]);
        $c = $this->customer($u);
        $token = $c->token('home');
        static::$app->clearProbes();
        $before = $this->snapshot();
        $n = $this->refusals();
        $r = $this->p3($c, $token, $rid);
        $this->assertRefused($r, $before, $n, 'portalDeactivate', $owned, 'P3 matched row of a reseller customer');
        $this->assertSame('on', static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$rid]));
    }

    // --------------------------------------------------------------------------------------------------------- T1c

    public function testPositiveControlTheStubNasSeesTheDisconnectWhenTheDeviceRemovalRuns(): void
    {
        // without this, "no packet at the stub NAS" in T1 could pass vacuously: run the Radius device removal itself (what
        // P1 / P2 / P3 reached before A5) for an activated customer with the same session wiring
        [$cid, $u] = $this->activated('pc');
        $stub = $this->liveSession($u);
        $before = static::radcheck($u);
        $out = static::$app->exec(sprintf(<<<'PHP'
require_once 'system/devices/Radius.php';
(new Radius())->remove_customer(ORM::for_table('tbl_customers')->find_one(%d)->as_array(), ORM::for_table('tbl_plans')->find_one(%d)->as_array());
$result = 'ok';
PHP, $cid, self::$plan));
        $this->assertSame('ok', $out);
        $got = $stub->received();
        $this->assertNotSame([], $got, 'the stub NAS received the Disconnect');
        $this->assertSame([40], array_values(array_unique(array_column($got, 'code'))), 'Disconnect-Request only');
        $this->assertNotSame($before, static::radcheck($u), 'and the RADIUS policy was suspended');
    }

    // ---------------------------------------------------------------------------------------------------------- T2

    public function testTheActivationStaysReversibleAfterTheRefusals(): void
    {
        [$cid, $u, $rid, $ref] = $this->activated('rev');
        $admin = $this->staff('ad');
        $c = $this->customer($u);
        $token = $c->token('home');
        $this->p1($admin, $rid);
        $this->p2($admin, $cid, self::$plan);
        $this->p3($c, $token, $rid);
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
        $r = $admin->postWithToken('activation/reverse/' . $ref, ['reason' => 'A5 reversibility after legacy refusals']);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame('REVERSED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]), 'reversal succeeds (no external-state refusal)');
    }

    // ---------------------------------------------------------------------------------------------------------- T3

    public function testUnassignedLegacyCustomersKeepTodaysBehaviour(): void
    {
        $admin = $this->staff('ad');
        $n = $this->refusals();
        // P1: hook, device removal, row deleted
        [, $u1, $r1] = $this->legacy('l1');
        static::$app->clearProbes();
        $r = $this->p1($admin, $r1);
        $this->assertSame(302, $r->status);
        $this->assertStringContainsString('plan/list', (string) $r->location());
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE id = ?', [$r1]), 'P1 deletes the recharge');
        $this->assertSame(['delete_customer_active_plan'], static::$app->probe('hook'), 'P1 runs the hook');
        $this->assertSame([['call' => 'remove_customer', 'username' => $u1]], static::$app->probe('device'), 'P1 removes on the device');
        // P2: device removal, recharge off, notification
        [$c2, $u2, $r2] = $this->legacy('l2');
        static::$app->clearProbes();
        $r = $this->p2($admin, $c2, static::$seed->planId);
        $this->assertSame(302, $r->status);
        $this->assertStringContainsString('customers/view/' . $c2, (string) $r->location());
        $this->assertSame('off', static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$r2]), 'P2 deactivates');
        $this->assertSame([['call' => 'remove_customer', 'username' => $u2]], static::$app->probe('device'));
        $this->assertCount(1, static::$app->probe('telegram'));
        $this->assertSame([], static::$app->probe('hook'));
        // P3: the customer's own deactivation
        [, $u3, $r3] = $this->legacy('l3');
        $c = $this->customer($u3);
        $token = $c->token('home');
        static::$app->clearProbes();
        $r = $this->p3($c, $token, $r3);
        $this->assertSame(302, $r->status);
        $this->assertStringContainsString('home', (string) $r->location());
        $this->assertSame('off', static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$r3]), 'P3 deactivates');
        $this->assertSame([['call' => 'remove_customer', 'username' => $u3]], static::$app->probe('device'));
        $this->assertCount(1, static::$app->probe('telegram'));
        $this->assertSame($n, $this->refusals(), 'no refusal for unassigned customers');
    }

    // ---------------------------------------------------------------------------------------------------------- T4

    public function testSchemaNotReadyRefusesForEveryCustomer(): void
    {
        [, , $r1] = $this->legacy('n1');
        [$c2, , $r2] = $this->legacy('n2');
        [, $u3, $r3] = $this->legacy('n3');
        $admin = $this->staff('ad');
        $atoken = $admin->token();
        $cust = $this->customer($u3);
        $ctoken = $cust->token('home');
        $id = 'R0007_activation_reversal_states';
        $status = (string) static::$db->value('SELECT status FROM tbl_project_migrations WHERE migration_id = ?', [$id]);
        static::$db->pdo->prepare("UPDATE tbl_project_migrations SET status = 'FAILED_PARTIAL' WHERE migration_id = ?")->execute([$id]);
        try {
            static::$app->clearProbes();
            $before = $this->snapshot();
            $log = strlen(static::$app->serverLog());
            $audit = $this->refusals();
            foreach (['planDelete' => [$admin->post('plan/delete/' . $r1, ['csrf_token' => $atoken]), 'plan/list'],
                'customerDeactivate' => [$admin->post('customers/deactivate/' . $c2 . '/' . static::$seed->planId, ['csrf_token' => $atoken]), 'customers/view/' . $c2],
                'portalDeactivate' => [$this->p3($cust, $ctoken, $r3), 'home']] as $op => [$r, $to]) {
                $this->assertSame(302, $r->status, "{$op}: " . $r->excerpt());
                $this->assertStringContainsString($to, (string) $r->location(), $op);
                $this->assertStringContainsString('op=' . $op, substr(static::$app->serverLog(), $log), "{$op}: refused by the A5 gate (logged; no audit while not ready)");
            }
            $this->assertSame($audit, $this->refusals(), 'not ready: the audit table is not written');
            $this->assertUnchanged($before, 'schema not ready');
            $this->assertSame([], static::$app->probe('device'));
            $this->assertSame([], static::$app->probe('hook'));
            $this->assertSame([], static::$app->probe('telegram'));
            $this->assertSame(['on', 'on', 'on'], array_map(static fn($r) => (string) static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$r]), [$r1, $r2, $r3]));
        } finally {
            static::$db->pdo->prepare('UPDATE tbl_project_migrations SET status = ? WHERE migration_id = ?')->execute([$status, $id]);
        }
    }

    // ----------------------------------------------------------------------------------------------------- T5 / T6

    public function testTheApiIsRefusedAndAgentAndSalesStayForbidden(): void
    {
        [$cid, $u, $rid, $ref] = $this->activated('api');
        $token = $this->apiToken('ad');
        // logins write tbl_users.last_login: every session is opened before the snapshot
        $staff = ['A' => $this->staff('A'), 'A1' => $this->staff('A1')];
        static::$app->clearProbes();
        $before = $this->snapshot();
        $n = $this->refusals();
        $this->assertDenied($this->client()->api('POST', 'plan/delete/' . $rid, [], $token), 'API P1');
        $this->assertDenied($this->client()->api('POST', 'customers/deactivate/' . $cid . '/' . self::$plan, [], $token), 'API P2');
        $this->assertUnchanged($before, 'API refusals');
        $this->assertSame($n + 2, $this->refusals(), 'API refusals audited');
        $this->assertSame([], static::$app->probe('device'));
        $this->assertSame([], static::$app->probe('hook'));
        // T6: the route policy is unchanged: Agent and Sales never reach P1 / P2
        foreach ($staff as $who => $c) {
            $this->assertSame(403, $this->p1($c, $rid)->status, "{$who} P1");
            $this->assertSame(403, $this->p2($c, $cid, self::$plan)->status, "{$who} P2");
        }
        $this->assertUnchanged($before, 'Agent / Sales');
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
    }

    // --------------------------------------------------------------------------------------------------------- T6b

    public function testAnOwnershipTransferKeepsTheRefusal(): void
    {
        [$cid, $u, $rid] = $this->activated('xfer');
        $b = $this->sid('B');
        $out = static::$app->exec(sprintf(<<<'PHP'
$ad = \Reseller\Security\ActorContext::forStaff(ORM::for_table('tbl_users')->where('username', 'ad')->find_one()->as_array(), 'CLI');
\Reseller\Domain\CustomerService::transferOwner($ad, %d, %d);
$result = 'ok';
PHP, $cid, $b));
        $this->assertSame('ok', $out);
        $this->assertSame($b, (int) static::$db->value('SELECT manager_id FROM tbl_customers WHERE id = ?', [$cid]));
        $admin = $this->staff('ad');
        $c = $this->customer($u);
        $token = $c->token('home');
        static::$app->clearProbes();
        $before = $this->snapshot();
        $n = $this->refusals();
        $this->assertSame(302, $this->p1($admin, $rid)->status);
        $this->assertSame(302, $this->p2($admin, $cid, self::$plan)->status);
        $this->assertSame(302, $this->p3($c, $token, $rid)->status);
        $this->assertUnchanged($before, 'after the transfer');
        $this->assertSame($n + 3, $this->refusals());
        $this->assertSame([], static::$app->probe('device'));
        $this->assertSame([], static::$app->probe('hook'));
        $this->assertSame([], static::$app->probe('telegram'));
    }

    // ---------------------------------------------------------------------------------------- L1 (D-A5-1, L-A5-1)

    /** Admin "login as customer" on $cid: the Admin's browser session now carries uid = $cid (the legacy fallback) */
    private function impersonating(int $cid): HttpClient
    {
        $admin = $this->staff('ad');
        $r = $admin->postWithToken('customers/login/' . $cid);
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertStringContainsString('You are logging in as', $r->body);
        return $admin;
    }

    /** a legacy voucher-only active row (Package::rechargeUser voucher mode): customer_id 0, username = the voucher code */
    private function voucherOnlyRow(int $plan): int
    {
        return static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => 0, 'username' => 'A5V' . strtoupper(bin2hex(random_bytes(4))), 'plan_id' => $plan, 'namebp' => 'a5_radius', 'recharged_on' => gmdate('Y-m-d'),
            'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', time() + 86400 * 10), 'time' => '23:59:59', 'status' => 'on',
            'method' => 'Voucher - A5', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 0,
        ]);
    }

    public function testP1NeverFallsBackToTheImpersonatedCustomer(): void
    {
        [$x, $u, , $ref] = $this->activated('imp1');
        $stub = $this->liveSession($u);
        $admin = $this->impersonating($x);
        $row = $this->voucherOnlyRow(self::$plan);
        static::$app->clearProbes();
        $before = $this->snapshot();
        $policy = static::radcheck($u);
        $n = $this->refusals();
        $r = $this->p1($admin, $row);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertStringContainsString('plan/list', (string) $r->location());
        $this->assertUnchanged($before, 'P1 on a customer_id 0 row with an impersonation session');
        $this->assertSame($policy, static::radcheck($u), "the impersonated customer's RADIUS policy is unchanged");
        $this->assertSame([], $stub->received(), 'no Disconnect for the impersonated customer');
        $this->assertSame([], static::$app->probe('hook'), 'refused before the hook');
        $this->assertSame([], static::$app->probe('device'));
        $this->assertSame([], static::$app->probe('telegram'));
        $rows = $this->auditRows('service.refused');
        $this->assertCount($n + 1, $rows);
        $this->assertSame('planDeleteIdentity', json_decode((string) end($rows)['after_json'], true)['operation'] ?? null);
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
        $this->assertSame('on', static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$row]), 'the voucher-only row is kept (ends by cron expiry)');
    }

    public function testP2NeverFallsBackToTheImpersonatedCustomer(): void
    {
        [$x, $u, , $ref] = $this->activated('imp2');
        $stub = $this->liveSession($u);
        $admin = $this->impersonating($x);
        $row = $this->voucherOnlyRow(self::$plan);
        static::$app->clearProbes();
        $before = $this->snapshot();
        $policy = static::radcheck($u);
        $n = $this->refusals();
        foreach (['0', 'abc', '0x1'] as $bad) {
            $r = $this->p2Raw($admin, $bad, self::$plan);
            $this->assertUnchanged($before, "P2 with customer id '{$bad}' and an impersonation session");
            $this->assertSame(302, $r->status, "{$bad}: " . $r->excerpt());
            $this->assertStringNotContainsString('customers/view/' . $x, (string) $r->location(), "{$bad}: never the impersonated customer");
        }
        $this->assertSame($policy, static::radcheck($u), "the impersonated customer's RADIUS policy is unchanged");
        $this->assertSame([], $stub->received(), 'no Disconnect for the impersonated customer');
        $this->assertSame([], static::$app->probe('device'));
        $this->assertSame([], static::$app->probe('telegram'));
        $ops = array_map(static fn($a) => json_decode((string) $a['after_json'], true)['operation'] ?? null, array_slice($this->auditRows('service.refused'), $n));
        $this->assertSame(['customerDeactivateIdentity', 'customerDeactivateIdentity', 'customerDeactivateIdentity'], $ops);
        $this->assertSame('COMPLETED', static::$db->value('SELECT status FROM tbl_reseller_activations WHERE reference = ?', [$ref]));
        $this->assertSame('on', static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$row]));
    }

    // ---------------------------------------------------------------------------------------------------------- T8

    public function testTheGateFailsClosedWhenTheOwnershipReadFails(): void
    {
        [$cid] = $this->legacy('f1');
        $out = static::$app->exec(sprintf(<<<'PHP'
global $db_host, $db_user, $db_pass, $db_name;
$ok = \Reseller\Domain\SchemaReadiness::isReady();
$open = \Reseller\Domain\ServiceGate::isBlockedCustomerId(%1$d);
$real = ORM::get_db();
$broken = new class("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass) extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'SELECT manager_id FROM tbl_customers')) {
            throw new PDOException('A5 injected ownership read failure');
        }
        return parent::prepare($query, $options);
    }
};
ORM::set_db($broken);
$closed = \Reseller\Domain\ServiceGate::isBlockedCustomerId(%1$d);
ORM::set_db($real);
$result = ['ready' => $ok, 'unassigned_without_fault' => $open, 'unassigned_with_read_failure' => $closed];
PHP, $cid));
        $this->assertSame(['ready' => true, 'unassigned_without_fault' => false, 'unassigned_with_read_failure' => true], $out,
            'the gate of C1-C3 refuses when ownership cannot be read (it is the gate every A5 path calls before any effect, T9)');
    }
}
