<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * Owner decision OD-11 (temporary safety gate until WP5B): for every customer with manager_id IS NOT
 * NULL, every legacy operation that can create, extend, re-provision or otherwise grant service fails
 * closed for EVERY role — including an Agent/Sales acting on a customer correctly inside its own
 * scope. Unassigned legacy customers keep authorized SuperAdmin/Admin compatibility.
 */
final class ServiceGateTest extends Wp2HttpTestCase
{
    /** @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}> name => [route, post, query] */
    private function serviceRequests(string $customer): array
    {
        $cid = $this->cid($customer);
        $rid = static::$seed->recharges[$customer] ?? static::$seed->recharges['cA1'];
        return [
            'recharge-post cash' => ['plan/recharge-post', ['id_customer' => $cid, 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'cash'], []],
            'recharge-post zero' => ['plan/recharge-post', ['id_customer' => $cid, 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'zero'], []],
            'refill-post' => ['plan/refill-post', ['id_customer' => $cid, 'code' => static::$seed->voucherCode('A')], []],
            'deposit-post' => ['plan/deposit-post', ['id_customer' => $cid, 'amount' => '777'], []],
            'extend' => ['plan/extend/' . $rid . '/30', [], []],
            'plan edit-post' => ['plan/edit-post', ['id' => $rid, 'id_plan' => static::$seed->planId, 'recharged_on' => gmdate('Y-m-d'), 'expiration' => '2099-01-01', 'time' => '23:59:59'], []],
            'customers sync' => ['customers/sync/' . $cid, [], []],
        ];
    }

    /** OD-11 required test 1: Agent / Sales cannot grant service even to their own in-scope customer. */
    public function testAgentAndSalesCannotGrantServiceToTheirOwnInScopeCustomer(): void
    {
        foreach (['A' => 'cA1', 'A1' => 'cA1', 'B' => 'cB', 'C1' => 'cC1'] as $staff => $customer) {
            $c = $this->staff($staff);
            $this->assertSame(200, $c->get('customers/view/' . $this->cid($customer))->status, "{$staff} sees its own customer {$customer}");
            static::$app->clearProbes();
            $before = $this->snapshot();
            foreach ($this->serviceRequests($customer) as $name => [$route, $post, $query]) {
                $r = $c->postWithToken($route, $post, $query);
                $this->assertDenied($r, "{$staff} {$name}");
                $this->assertSame(403, $r->status, "{$staff} {$name}: RouteGuard denies (no service.* capability)");
            }
            $r = $c->get('customers/recharge/' . $this->cid($customer) . '/' . static::$seed->planId);
            $this->assertSame(403, $r->status, "{$staff} recharge confirmation form");
            $this->assertUnchanged($before, "{$staff} service attempts");
            $this->assertSame([], static::$app->probe('device'), "{$staff}: no device call");
        }
    }

    /** OD-11 required test 1 (API variant): the same through an a2 token. */
    public function testAgentAndSalesCannotGrantServiceThroughTheApi(): void
    {
        foreach (['A' => 'cA1', 'A1' => 'cA1'] as $staff => $customer) {
            $token = $this->apiToken($staff);
            $before = $this->snapshot();
            foreach ($this->serviceRequests($customer) as $name => [$route, $post]) {
                $r = $this->client()->api('POST', $route, $post, $token);
                $this->assertDenied($r, "API {$staff} {$name}");
            }
            $this->assertUnchanged($before, "API {$staff}");
        }
    }

    /** OD-11 required test 2: SuperAdmin / Admin are refused on a reseller-owned customer. */
    public function testSuperAdminAndAdminAreRefusedOnResellerOwnedCustomers(): void
    {
        foreach (['sa', 'ad'] as $staff) {
            $c = $this->staff($staff);
            static::$app->clearProbes();
            $auditBefore = count($this->auditRows('service.refused'));
            $before = $this->snapshot();
            foreach ($this->serviceRequests('cA1') as $name => [$route, $post]) {
                if ($name === 'customers sync') {
                    continue; // WP5B (OD-10, E19b): a bounded re-provision through LegacyReprovisionService, below
                }
                $r = $c->postWithToken($route, $post);
                $this->assertSame(302, $r->status, "{$staff} {$name}: " . $r->excerpt());
                $this->assertFalse($r->leaksInternals());
                if ($name === 'recharge-post cash') {
                    // WP5B (OD-6, E1): the legacy action writes nothing and continues at the reseller activation
                    $this->assertStringContainsString('activation/create', (string) $r->location(), "{$staff} {$name}");
                }
            }
            $r = $c->get('customers/recharge/' . $this->cid('cA1') . '/' . static::$seed->planId, ['token' => $c->token()]);
            $this->assertSame(302, $r->status, "{$staff} recharge confirmation form not displayed (activation instead)");
            $this->assertStringContainsString('activation/create', (string) $r->location());
            $this->assertUnchanged($before, "{$staff} service attempts on cA1");
            $this->assertSame([], static::$app->probe('device'), "{$staff}: no device call");
            $this->assertGreaterThan($auditBefore, count($this->auditRows('service.refused')), 'refusals are audited');
            // the refusal message is shown on the next page
            $c->postWithToken('plan/deposit-post', ['id_customer' => $this->cid('cA1'), 'amount' => '777']);
            $page = $c->get('plan/recharge');
            // lab UX-2: customer-facing wording, no internal work-package code
            $this->assertStringContainsString('This service is managed by your reseller.', $page->body);
            $this->assertStringNotContainsString('WP5B', $page->body);
            // E19b is a bounded re-provision now: the device sync runs through the service, billing unchanged
            $r = $c->postWithToken('customers/sync/' . $this->cid('cA1'));
            $this->assertSame(302, $r->status);
            $this->assertSame(['add_customer'], array_values(array_unique(array_column(static::$app->probe('device'), 'call'))), "{$staff}: re-provisioned");
            $this->assertUnchanged($before, "{$staff} re-provision changes no billing data");
        }
        $rows = $this->auditRows('service.refused');
        $last = end($rows);
        $this->assertSame((int) $this->cid('cA1'), (int) $last['target_customer_id']);
        $this->assertSame('STAFF', $last['actor_type']);
        $this->assertSame(32, strlen($last['request_id']));
    }

    /** OD-11 required test 3: core guards refuse direct calls (any caller: plugins, cron, payment code). */
    public function testCoreGuardsRefuseDirectCallsForResellerOwnedCustomers(): void
    {
        $before = $this->snapshot();
        static::$app->clearProbes();
        $res = static::$app->exec(sprintf(
            '$c = ORM::for_table("tbl_customers")->find_one(%d);'
            . '$result = ['
            . '"rechargeUser" => Package::rechargeUser(%1$d, "wp2_router", %2$d, "Recharge", "Probe"),'
            . '"rechargeUserVoucherCollision" => Package::rechargeUser(0, "wp2_router", %2$d, "Voucher", "cA1"),'
            . '"rechargeBalance" => Package::rechargeBalance($c, ["name_plan" => "Balance", "price" => 100], "Deposit", "probe"),'
            . '"rechargeCustomBalance" => Package::rechargeCustomBalance($c, ["name_plan" => "Balance", "price" => 100], "Deposit", "probe"),'
            . '"transferFrom" => Balance::transfer(%1$d, "cNULL", 10),'
            . '"transferTo" => Balance::transfer(%3$d, "cA1", 10),'
            . '];',
            $this->cid('cA1'),
            static::$seed->planId,
            $this->cid('cNULL')
        ));
        foreach ($res as $k => $v) {
            $this->assertFalse($v, "{$k} must return false");
        }
        $this->assertUnchanged($before, 'core guards');
        $this->assertSame([], static::$app->probe('device'));
    }

    /** OD-11 required test 3 (cron): reseller-owned renewal skipped and logged; the loop continues. */
    public function testCronSkipsResellerOwnedAutoRenewalAndRenewsUnassigned(): void
    {
        $db = static::$db;
        $db->exec("UPDATE tbl_appconfig SET value = 'yes' WHERE setting = 'enable_balance'");
        if ((int) $db->value("SELECT COUNT(*) FROM tbl_appconfig WHERE setting = 'enable_balance'") === 0) {
            $db->insertRow('tbl_appconfig', ['setting' => 'enable_balance', 'value' => 'yes']);
        }
        $yesterday = gmdate('Y-m-d', time() - 86400 * 2);
        $db->exec("UPDATE tbl_user_recharges SET expiration = '{$yesterday}', time = '00:00:00', status = 'on' WHERE id IN ("
            . static::$seed->recharges['cA1'] . ',' . static::$seed->recharges['cNULL'] . ')');
        $balA = (string) $db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$this->cid('cA1')]);
        $balN = (string) $db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$this->cid('cNULL')]);
        $trxA = (int) $db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cA1')]);
        $trxN = (int) $db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL')]);
        static::$app->clearProbes();
        [$exit, $out] = static::$app->run(['cron.php'], 'system');
        $this->assertSame(0, $exit, $out);
        // WP5B (OD-3): no reseller auto-renew; one AUTO_RENEW_UNAVAILABLE record per obligation
        $this->assertStringContainsString('reseller-managed customer: AUTO_RENEW_UNAVAILABLE', $out);
        $this->assertStringContainsString('auto renewal COMPLETED', $out, 'the loop continued and renewed the unassigned customer');
        $this->assertSame($balA, (string) $db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$this->cid('cA1')]), 'no debit');
        $this->assertSame($trxA, (int) $db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cA1')]));
        $this->assertNotSame($balN, (string) $db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$this->cid('cNULL')]), 'unassigned customer debited');
        $this->assertSame($trxN + 1, (int) $db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL')]));
        foreach (static::$app->probe('telegram') as $msg) {
            $this->assertStringNotContainsString('FAILED RENEWAL #cron\n\n#u.cA1', (string) $msg, 'no false failed-renewal alert');
        }
        $this->assertCount(1, $db->rows("SELECT id FROM tbl_reseller_audit_events WHERE event_type = 'service.auto_renew_unavailable' AND target_customer_id = ?", [$this->cid('cA1')]));
    }

    /** OD-11 required test 4: customer portal of a reseller-owned customer. */
    public function testCustomerPortalRefusesServiceForResellerOwnedCustomer(): void
    {
        $db = static::$db;
        $pw = (string) $db->value('SELECT password FROM tbl_customers WHERE id = ?', [$this->cid('cA1')]);
        $c = $this->customer('cA1');
        $home = $c->get('home');
        $this->assertSame(200, $home->status, $home->excerpt());
        $before = $this->snapshot();
        static::$app->clearProbes();
        $code = static::$seed->voucherCode('ad');
        $attempts = [
            ['voucher/activation-post', ['code' => $code], []],
            ['order/pay/' . static::$seed->routerId . '/' . static::$seed->planId, [], []],
            ['order/send/' . static::$seed->routerId . '/' . static::$seed->planId, ['send' => 'plan', 'username' => 'cNULL'], []],
            ['order/gateway/' . static::$seed->routerId . '/' . static::$seed->planId, [], []],
            ['order/buy/' . static::$seed->routerId . '/' . static::$seed->planId, ['gateway' => 'balance'], []],
            ['home', [], ['extend' => (string) static::$seed->recharges['cA1'], 'stoken' => 'x']],
            ['home', ['send' => 'balance', 'username' => 'cNULL', 'balance' => '10'], []],
        ];
        // WP5B (OD-10, E19c / E20): the portal sync and password change are bounded re-provisions now (below)
        foreach ($attempts as [$route, $post, $query]) {
            $r = $c->postWithToken($route, $post, $query, 'home');
            $this->assertContains($r->status, [200, 302], $route . ' ' . $r->excerpt());
            $this->assertFalse($r->leaksInternals());
        }
        $this->assertUnchanged($before, 'customer portal service attempts');
        $this->assertSame([], static::$app->probe('device'));
        $this->assertSame($pw, (string) $db->value('SELECT password FROM tbl_customers WHERE id = ?', [$this->cid('cA1')]));
        $this->assertSame('0', (string) $db->value('SELECT status FROM tbl_voucher WHERE code = ?', [$code]), 'voucher not consumed');
        // E19c / E20 through LegacyReprovisionService: the device is updated, billing never (an active period is needed)
        $db->exec("UPDATE tbl_user_recharges SET status = 'on', expiration = '" . gmdate('Y-m-d', time() + 86400 * 10) . "' WHERE id = " . (int) static::$seed->recharges['cA1']);
        $billing = $db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$this->cid('cA1')]);
        $c->postWithToken('home', [], ['sync' => '1'], 'home');
        $this->assertSame(['add_customer'], array_values(array_unique(array_column(static::$app->probe('device'), 'call'))), 'portal sync re-provisioned');
        $c->postWithToken('accounts/change-password-post', ['password' => Wp2Seed::customerPassword('cA1'), 'npass' => 'newpass1', 'cnpass' => 'newpass1'], [], 'home');
        $this->assertSame('newpass1', (string) $db->value('SELECT password FROM tbl_customers WHERE id = ?', [$this->cid('cA1')]), 'E20 through the service');
        $this->assertSame($billing, $db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$this->cid('cA1')]));
        $this->assertNotEmpty($this->auditRows('reprovision.password_change'));
        $db->pdo->prepare('UPDATE tbl_customers SET password = ? WHERE id = ?')->execute([$pw, $this->cid('cA1')]);
    }

    /** OD-11 required test 5: public voucher + username activation for a reseller-owned username. */
    public function testPublicVoucherActivationRefusedAndPasswordUnchanged(): void
    {
        $db = static::$db;
        $pw = (string) $db->value('SELECT password FROM tbl_customers WHERE id = ?', [$this->cid('cA2')]);
        $code = static::$seed->voucherCode('B');
        $c = $this->client();
        $before = $this->snapshot();
        $token = $c->token('login');
        $r = $c->post('login/activation', ['username' => 'cA2', 'voucher' => $code, 'csrf_token' => $token]);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertUnchanged($before, 'public voucher activation');
        $this->assertSame($pw, (string) $db->value('SELECT password FROM tbl_customers WHERE id = ?', [$this->cid('cA2')]), 'password NOT overwritten');
        // voucher-only path colliding with a reseller-owned username
        $r = $c->post('login/activation', ['voucher_only' => 'cA2', 'csrf_token' => $token]);
        $this->assertContains($r->status, [200, 302]);
        $this->assertUnchanged($before, 'voucher-only collision');
    }

    /** E21: identity edits of a reseller-owned customer go through LegacyReprovisionService (WP5B, OD-10); re-activation is refused; profile edits remain. */
    public function testCustomerEditRefusesReprovisioningChangesForResellerOwned(): void
    {
        $db = static::$db;
        $cid = $this->cid('cA1');
        $row = $db->rows('SELECT username, fullname, email, address, phonenumber, status, password FROM tbl_customers WHERE id = ?', [$cid])[0];
        $form = ['id' => $cid, 'username' => $row['username'], 'fullname' => $row['fullname'], 'account_type' => 'Personal', 'password' => '',
            'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '', 'email' => $row['email'], 'address' => (string) $row['address'],
            'phonenumber' => $row['phonenumber'], 'service_type' => 'Hotspot', 'coordinates' => '', 'status' => 'Active'];
        $sa = $this->staff('sa');
        static::$app->clearProbes();
        $before = $this->snapshot();
        // WP5B (OD-10, E21): identity edits go through LegacyReprovisionService (device-verified, never a grant)
        $db->exec("UPDATE tbl_user_recharges SET status = 'on', expiration = '" . gmdate('Y-m-d', time() + 86400 * 10) . "' WHERE id = " . (int) static::$seed->recharges['cA1']);
        $before = $this->snapshot();
        $r = $sa->postWithToken('customers/edit-post', ['password' => 'new-secret-77'] + $form);
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame('new-secret-77', (string) $db->value('SELECT password FROM tbl_customers WHERE id = ?', [$cid]));
        $this->assertSame(['add_customer'], array_values(array_unique(array_column(static::$app->probe('device'), 'call'))));
        $this->assertNotEmpty($this->auditRows('reprovision.identity_edit'));
        $db->pdo->prepare('UPDATE tbl_customers SET password = ? WHERE id = ?')->execute([$row['password'], $cid]);
        static::$app->clearProbes();
        $before = $this->snapshot();
        // re-activation (Inactive -> Active) is a service grant as well
        $db->exec("UPDATE tbl_customers SET status = 'Inactive' WHERE id = {$cid}");
        $before = $this->snapshot();
        $sa->postWithToken('customers/edit-post', $form);
        $this->assertUnchanged($before, 're-activation of a reseller-owned customer');
        $db->exec("UPDATE tbl_customers SET status = 'Active' WHERE id = {$cid}");
        // a pure profile edit (no device effect) is still allowed
        $r = $sa->postWithToken('customers/edit-post', ['fullname' => 'Customer cA1 edited'] + $form);
        $this->assertSame(302, $r->status);
        $this->assertSame('Customer cA1 edited', $db->value('SELECT fullname FROM tbl_customers WHERE id = ?', [$cid]));
        $this->assertSame($row['password'], $db->value('SELECT password FROM tbl_customers WHERE id = ?', [$cid]));
        $this->assertSame([], static::$app->probe('device'), 'no device call for a profile edit');
        $db->exec("UPDATE tbl_customers SET fullname = " . static::$db->pdo->quote($row['fullname']) . " WHERE id = {$cid}");
        $this->assertNotEmpty(array_filter($this->auditRows('service.refused'), static fn($a) => (int) $a['target_customer_id'] === $cid));
    }

    /** E19a: the bulk plan sync re-provisions reseller-owned customers only through the service (WP5B, OD-10). */
    public function testBulkPlanSyncSkipsResellerOwnedCustomers(): void
    {
        // every seeded recharge active (an earlier test may have expired some)
        $exp = gmdate('Y-m-d', time() + 86400 * 10);
        static::$db->exec("UPDATE tbl_user_recharges SET status = 'on', expiration = '{$exp}' WHERE id IN (" . implode(',', static::$seed->recharges) . ')');
        $sa = $this->staff('sa');
        static::$app->clearProbes();
        $before = count($this->auditRows('service.refused'));
        $r = $sa->postWithToken('plan/sync');
        $this->assertSame(302, $r->status, $r->excerpt());
        $calls = array_column(static::$app->probe('device'), 'username');
        $this->assertContains('cNULL', $calls, 'unassigned customer provisioned');
        // WP5B (OD-10, E19a): reseller-owned customers are re-provisioned through LegacyReprovisionService, once each
        $synced = array_map(static fn(array $a): int => (int) $a['target_customer_id'], $this->auditRows('reprovision.admin_bulk_sync_row'));
        foreach (['cA1', 'cB', 'cC1'] as $owned) {
            $this->assertContains($owned, $calls, "{$owned} (reseller-owned) re-provisioned by the service");
            $this->assertContains($this->cid($owned), $synced, "{$owned} audited");
        }
        $this->assertSame($before, count($this->auditRows('service.refused')), 'nothing refused');
        $this->assertSame(403, $this->staff('A')->postWithToken('plan/sync')->status, 'Agent has no service.sync_all');
    }

    /** OD-11 required test 6: unassigned legacy customers keep SuperAdmin/Admin service compatibility. */
    public function testUnassignedCustomersKeepAdminServiceCompatibility(): void
    {
        $db = static::$db;
        $sa = $this->staff('sa');
        static::$app->clearProbes();
        $n = (int) $db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL2')]);
        $r = $sa->postWithToken('plan/recharge-post', ['id_customer' => $this->cid('cNULL2'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'cash']);
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertSame($n + 1, (int) $db->value('SELECT COUNT(*) FROM tbl_transactions WHERE user_id = ?', [$this->cid('cNULL2')]));
        $code = static::$seed->voucherCode('C');
        $r = $this->staff('ad')->postWithToken('plan/refill-post', ['id_customer' => $this->cid('cNULL2'), 'code' => $code]);
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertSame('1', (string) $db->value('SELECT status FROM tbl_voucher WHERE code = ?', [$code]));
        $r = $sa->postWithToken('customers/sync/' . $this->cid('cNULL'));
        $this->assertSame(302, $r->status);
        $this->assertNotSame([], static::$app->probe('device'), 'device provisioning happened for the unassigned customer');
        $calls = array_column(static::$app->probe('device'), 'username');
        $this->assertNotContains('cA1', $calls);
    }
}
