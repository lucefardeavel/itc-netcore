<?php
namespace Reseller\Tests\Integration\F6;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;
use Reseller\Tests\Support\Wp2Seed;

/**
 * F6 (ITC NetCore lab, 2026-10-24): inactive-account matrix probe. For the customer statuses Suspended, Disabled,
 * Inactive and Banned, every legacy path that can create, renew, reactivate or (re-)provision service is exercised
 * against the unassigned legacy customer cNULL (Probe device: every device call is recorded).
 *
 * Convention: a test named ...Refuses... / ...Grants nothing... ASSERTS THE SAFE BEHAVIOUR, so a failure is a finding.
 * A test named ...Characterization records the present behaviour of an OWNER POLICY question (it passes while the
 * behaviour is unchanged; its message tells what was observed). Product code is not changed by this probe.
 */
final class F6InactiveAccountMatrixTest extends Wp2HttpTestCase
{
    private const NON_ACTIVE = ['Suspended', 'Disabled', 'Inactive', 'Banned'];
    private const LOGIN_CAPABLE = ['Suspended', 'Disabled', 'Inactive'];

    private function setStatus(string $username, string $status): void
    {
        static::$db->pdo->prepare('UPDATE tbl_customers SET status = ? WHERE username = ?')->execute([$status, $username]);
    }

    private function config(string $k, string $v): void
    {
        if ((int) static::$db->value('SELECT COUNT(*) FROM tbl_appconfig WHERE setting = ?', [$k]) === 0) {
            static::$db->insertRow('tbl_appconfig', ['setting' => $k, 'value' => $v]);
        } else {
            static::$db->pdo->prepare('UPDATE tbl_appconfig SET value = ? WHERE setting = ?')->execute([$v, $k]);
        }
    }

    /** Everything a grant / renewal / debit would change for one customer. */
    private function fp(string $username): string
    {
        $cid = $this->cid($username);
        return json_encode([
            'recharges' => static::$db->rows('SELECT id, plan_id, expiration, time, status FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id', [$cid]),
            'trx' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE username = ?', [$username]),
            'balance' => (string) static::$db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$cid]),
            'pg' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_payment_gateway WHERE user_id = ? OR username = ?', [$cid, $username]),
        ]);
    }

    /** @return list<string> device calls for $username that write a login policy */
    private function grantCalls(string $username): array
    {
        return array_values(array_map(static fn($r) => (string) $r['call'], array_filter(static::$app->probe('device'),
            static fn($r) => ($r['username'] ?? '') === $username && in_array($r['call'] ?? '', ['add_customer', 'sync_customer', 'change_customer', 'connect_customer'], true))));
    }

    private function rid(): int
    {
        return static::$seed->recharges['cNULL'];
    }

    private function rechargeState(string $status, string $expiration, string $time = '00:00:00'): void
    {
        static::$db->pdo->prepare('UPDATE tbl_user_recharges SET status = ?, expiration = ?, time = ?, plan_id = ?, namebp = ? WHERE id = ?')
            ->execute([$status, $expiration, $time, static::$seed->planId, 'wp2_plan', $this->rid()]);
    }

    private function rstatus(): string
    {
        return (string) static::$db->value('SELECT status FROM tbl_user_recharges WHERE id = ?', [$this->rid()]);
    }

    private static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        $this->setStatus('cNULL', 'Active');
        $this->setStatus('cNULL2', 'Active');
        $this->rechargeState('on', gmdate('Y-m-d', time() + 86400 * 10), '23:59:59');
        static::$db->pdo->prepare('UPDATE tbl_customers SET balance = 5000 WHERE username IN (?, ?)')->execute(['cNULL', 'cNULL2']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ (1) authentication

    /** (1) Banned: an existing session / remember cookie / login link never reaches account data. */
    public function testBannedSessionCookieAndLoginLinkAreRefused(): void
    {
        $c = $this->customer('cNULL');
        $this->assertSame(200, $c->get('home')->status, 'logged in while Active');
        $this->setStatus('cNULL', 'Banned');
        foreach (['home', 'accounts/profile', 'order/history', 'voucher/list-activated', 'order/package'] as $route) {
            $r = $c->get($route);
            $this->assertStringContainsString('Banned', $r->body, "existing session, {$route}: the Banned alert ends the request: " . $r->excerpt());
            $this->assertStringNotContainsString('cNULL@example.invalid', strtolower($r->body), "{$route}: no account data");
        }
        // signed login link (?uid=, l3) of a Banned customer
        $tok = (string) static::$app->exec('$result = User::generateToken(' . $this->cid('cNULL') . ')["token"];');
        $this->assertNotSame('', $tok);
        $r = $this->client()->get('home', ['uid' => $tok]);
        $this->assertTrue(str_contains($r->body, 'Banned') || $r->status === 302, 'login link of a Banned customer: refused: ' . $r->excerpt());
        $this->assertStringNotContainsString('cnull@example.invalid', strtolower($r->body));
    }

    /** (1) system/api.php has no customer path for any status (only a2 staff tokens; legacy u. tokens refused). */
    public function testCustomerApiLoginIsClosedForEveryStatus(): void
    {
        foreach (array_merge(['Active'], self::NON_ACTIVE) as $status) {
            $this->setStatus('cNULL', $status);
            $r = $this->client()->api('POST', 'login/post', ['username' => 'cNULL', 'password' => Wp2Seed::customerPassword('cNULL')], null);
            $this->assertDenied($r, "API customer login ({$status})");
            $this->assertStringNotContainsString('"token"', $r->body, "{$status}: no customer token issued");
        }
    }

    /** (1)+(2)+(3) OWNER POLICY: Suspended / Disabled / Inactive customers log in and read home, profile, history, invoices. */
    public function testNonActivePortalAccessCharacterization(): void
    {
        $seen = [];
        foreach (self::LOGIN_CAPABLE as $status) {
            $this->setStatus('cNULL', $status);
            $c = $this->client();
            $c->loginCustomer('cNULL', Wp2Seed::customerPassword('cNULL'));
            foreach (['home', 'accounts/profile', 'order/history', 'voucher/list-activated', 'order/package', 'voucher/invoice'] as $route) {
                $r = $c->get($route);
                $seen[$status][$route] = $r->status . (str_contains($r->body, 'This account status') ? ' alert' : '');
            }
        }
        fwrite(STDERR, "\nF6 portal access characterization: " . json_encode($seen) . "\n");
        foreach (self::LOGIN_CAPABLE as $status) {
            $this->assertSame('200', $seen[$status]['home'], "{$status}: logs in and sees home (UI help text documents this only for Disabled)");
        }
    }

    // ------------------------------------------------------------------ (5) activation: legacy staff + customer self-purchase

    /** (5) staff legacy recharge (cash / zero / balance), web and API: nothing granted, nothing debited, no device call. */
    public function testStaffLegacyRechargeRefusesNonActive(): void
    {
        $this->config('enable_balance', 'yes');
        $sa = $this->staff('ad');
        $token = $this->apiToken('ad');
        foreach (self::NON_ACTIVE as $status) {
            $this->setStatus('cNULL', $status);
            foreach (['cash', 'zero', 'balance'] as $using) {
                static::$app->clearProbes();
                $before = $this->fp('cNULL');
                $post = ['id_customer' => $this->cid('cNULL'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => $using, 'nuxr_nonce' => self::nonce()];
                $r = $sa->postWithToken('plan/recharge-post', $post);
                $this->assertSame($before, $this->fp('cNULL'), "web {$using} for a {$status} customer: nothing granted / debited: " . $r->excerpt());
                $this->assertSame([], $this->grantCalls('cNULL'), "web {$using} {$status}: no device call");
                $sa = $this->staff('ad'); // a Banned alert redirects the staff browser to logout
                $r = $this->client()->api('POST', 'plan/recharge-post', $post, $token);
                $this->assertSame($before, $this->fp('cNULL'), "API {$using} for a {$status} customer: " . $r->excerpt());
                $this->assertSame([], $this->grantCalls('cNULL'), "API {$using} {$status}: no device call");
            }
        }
    }

    /** (5) customer self-purchase with balance (order/pay) and gateway start (order/buy): refused. */
    public function testCustomerSelfPurchaseRefusesNonActive(): void
    {
        $this->config('enable_balance', 'yes');
        foreach (self::LOGIN_CAPABLE as $status) {
            $c = $this->customer('cNULL');
            $tok = $c->token('home');
            $this->setStatus('cNULL', $status);
            static::$app->clearProbes();
            $before = $this->fp('cNULL');
            $r = $c->post('order/pay/' . static::$seed->routerId . '/' . static::$seed->planId, ['csrf_token' => $tok, 'nuxr_nonce' => self::nonce()]);
            $this->assertSame($before, $this->fp('cNULL'), "order/pay {$status}: " . $r->excerpt());
            $r = $c->post('order/buy/' . static::$seed->routerId . '/' . static::$seed->planId, ['csrf_token' => $tok, 'gateway' => 'x']);
            $this->assertSame($before, $this->fp('cNULL'), "order/buy {$status}: no payment row: " . $r->excerpt());
            $this->assertSame([], $this->grantCalls('cNULL'));
            $this->setStatus('cNULL', 'Active');
        }
    }

    /** (5) an Active customer cannot buy a plan for (gift) or send balance to a non-Active customer. */
    public function testGiftAndBalanceTransferToNonActiveTargetRefused(): void
    {
        $this->config('enable_balance', 'yes');
        $this->config('allow_balance_transfer', 'yes');
        $c = $this->customer('cNULL2');
        foreach (self::NON_ACTIVE as $status) {
            $this->setStatus('cNULL', $status);
            static::$app->clearProbes();
            $before = $this->fp('cNULL');
            $sender = $this->fp('cNULL2');
            $r = $c->postWithToken('order/send/' . static::$seed->routerId . '/' . static::$seed->planId, ['send' => 'plan', 'username' => 'cNULL', 'nuxr_nonce' => self::nonce()], [], 'home');
            $this->assertSame($before, $this->fp('cNULL'), "gift to {$status}: " . $r->excerpt());
            $this->assertSame($sender, $this->fp('cNULL2'), "gift to {$status}: sender not debited");
            $r = $c->postWithToken('home', ['send' => 'balance', 'username' => 'cNULL', 'balance' => '100', 'nuxr_nonce' => self::nonce()], [], 'home');
            $this->assertSame($before, $this->fp('cNULL'), "transfer to {$status}: " . $r->excerpt());
            $this->assertSame($sender, $this->fp('cNULL2'), "transfer to {$status}: sender not debited");
            $this->assertSame([], $this->grantCalls('cNULL'));
        }
    }

    // ------------------------------------------------------------------ (4) voucher: public re-login of an already redeemed voucher

    /** (4) E13 re-login branch (login.php:241): a Banned / non-Active customer's used voucher grants nothing and opens no portal session. */
    public function testPublicVoucherReloginOfNonActiveCustomerGrantsNothing(): void
    {
        $code = 'F6V' . strtoupper(bin2hex(random_bytes(5)));
        static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'wp2_router', 'id_plan' => static::$seed->planId, 'code' => $code,
            'user' => 'cNULL', 'status' => '1', 'generated_by' => $this->sid('ad'), 'used_date' => gmdate('Y-m-d H:i:s')]);
        foreach (self::NON_ACTIVE as $status) {
            $this->setStatus('cNULL', $status);
            static::$app->clearProbes();
            $before = $this->fp('cNULL');
            $c = $this->client();
            $r = $c->post('login/activation', ['csrf_token' => $c->token('login'), 'voucher' => $code, 'username' => 'cNULL']);
            $this->assertSame($before, $this->fp('cNULL'), "{$status}: nothing granted: " . $r->excerpt());
            $this->assertSame([], $this->grantCalls('cNULL'), "{$status}: no device call (no nux-mac session)");
            $this->assertNotSame(200, $c->get('home')->status, "{$status}: the voucher re-login opens no portal session");
        }
    }

    // ------------------------------------------------------------------ (6) provisioning

    /** (6) OWNER POLICY: a status change (customers/edit-post) leaves the active recharge and the device policy in place. */
    public function testStatusChangeLeavesRadiusPolicyCharacterization(): void
    {
        $row = static::$db->rows('SELECT username, fullname, email, address, phonenumber FROM tbl_customers WHERE id = ?', [$this->cid('cNULL')])[0];
        $form = ['id' => $this->cid('cNULL'), 'username' => $row['username'], 'fullname' => $row['fullname'], 'account_type' => 'Personal', 'password' => '',
            'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => '', 'email' => $row['email'], 'address' => (string) $row['address'],
            'phonenumber' => $row['phonenumber'], 'service_type' => 'Hotspot', 'coordinates' => ''];
        $sa = $this->staff('sa');
        $seen = [];
        foreach (self::NON_ACTIVE as $status) {
            static::$app->clearProbes();
            $r = $sa->postWithToken('customers/edit-post', ['status' => $status] + $form);
            $this->assertSame($status, (string) static::$db->value('SELECT status FROM tbl_customers WHERE id = ?', [$this->cid('cNULL')]), $r->excerpt());
            $seen[$status] = ['recharge' => $this->rstatus(), 'device' => array_column(static::$app->probe('device'), 'call')];
            $this->setStatus('cNULL', 'Active');
        }
        fwrite(STDERR, "\nF6 status-change characterization: " . json_encode($seen) . "\n");
        foreach (self::NON_ACTIVE as $status) {
            $this->assertSame('on', $seen[$status]['recharge'], "{$status}: the active recharge stays 'on'");
            $this->assertNotContains('remove_customer', $seen[$status]['device'], "{$status}: no device remove / suspend on a status change");
        }
    }

    /** (6) the customer's own "sync" re-provisions the device policy only for an Active customer. */
    public function testPortalSyncByNonActiveCustomerWritesNoPolicy(): void
    {
        $bad = [];
        foreach (self::LOGIN_CAPABLE as $status) {
            $c = $this->customer('cNULL');
            $tok = $c->token('home');
            $this->setStatus('cNULL', $status);
            static::$app->clearProbes();
            $r = $c->post('home', ['csrf_token' => $tok], ['sync' => '1']);
            if ($this->grantCalls('cNULL') !== []) {
                $bad[$status] = $this->grantCalls('cNULL');
            }
            $this->setStatus('cNULL', 'Active');
        }
        $this->assertSame([], $bad, 'FINDING: a non-Active customer re-provisioned its device policy through home?sync (home.php:80-116 has no status check)');
    }

    // ------------------------------------------------------------------ (7) renewal / reactivation

    /** (7) cron auto-renew from balance: expiry enforced, no renewal, no debit for a non-Active customer. */
    public function testCronAutoRenewSkipsNonActive(): void
    {
        $this->config('enable_balance', 'yes');
        $n = 2;
        foreach (self::NON_ACTIVE as $status) {
            $this->rechargeState('on', gmdate('Y-m-d', time() - 86400 * $n++));
            static::$db->pdo->prepare('UPDATE tbl_customers SET auto_renewal = 1, balance = 5000 WHERE id = ?')->execute([$this->cid('cNULL')]);
            $this->setStatus('cNULL', $status);
            $trx = (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE username = ?', ['cNULL']);
            static::$app->clearProbes();
            [$exit, $out] = static::$app->run(['cron.php'], 'system');
            $this->assertSame(0, $exit, $out);
            $this->assertSame('off', $this->rstatus(), "{$status}: expired, not renewed: {$out}");
            $this->assertSame('5000.00', (string) static::$db->value('SELECT balance FROM tbl_customers WHERE id = ?', [$this->cid('cNULL')]), "{$status}: no debit");
            $this->assertSame($trx, (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE username = ?', ['cNULL']), "{$status}: no invoice");
            $this->assertStringContainsString('CUSTOMER_INACTIVE', $out, "{$status}: refused with CUSTOMER_INACTIVE");
            $this->assertSame([], $this->grantCalls('cNULL'), "{$status}: no add_customer");
        }
    }

    /** (7) staff "extend" of an expired recharge (plan/extend) must not reactivate service of a non-Active customer. */
    public function testStaffExtendRefusesNonActive(): void
    {
        $sa = $this->staff('ad');
        $bad = [];
        foreach (self::NON_ACTIVE as $status) {
            $this->rechargeState('off', gmdate('Y-m-d', time() - 86400 * 2));
            $this->setStatus('cNULL', $status);
            static::$app->clearProbes();
            $r = $sa->postWithToken('plan/extend/' . $this->rid() . '/3', [], ['svoucher' => bin2hex(random_bytes(8))]);
            if ($this->rstatus() !== 'off' || $this->grantCalls('cNULL') !== []) {
                $bad[$status] = ['recharge' => $this->rstatus(), 'device' => $this->grantCalls('cNULL'), 'http' => $r->status . ' ' . substr((string) $r->location(), 0, 80)];
            }
        }
        // positive control: the same request does extend an Active customer (the probe can see a grant)
        $this->rechargeState('off', gmdate('Y-m-d', time() - 86400 * 2));
        $this->setStatus('cNULL', 'Active');
        static::$app->clearProbes();
        $sa->postWithToken('plan/extend/' . $this->rid() . '/3', [], ['svoucher' => bin2hex(random_bytes(8))]);
        $this->assertSame('on', $this->rstatus(), 'control: an Active customer is extended');
        $this->assertSame([], $bad, 'FINDING: plan/extend reactivated service for non-Active customers (plan.php:1175-1237 has no customer status check): ' . json_encode($bad));
    }

    /** (7) staff plan edit (plan/edit-post) of an expired recharge must not reactivate / re-provision a non-Active customer. */
    public function testStaffPlanEditRefusesReactivatingNonActive(): void
    {
        $seedPlan = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seedPlan['id']);
        $seedPlan['name_plan'] = 'f6_plan_b';
        $planB = static::$db->insertRow('tbl_plans', $seedPlan);
        $sa = $this->staff('ad');
        $bad = [];
        $future = gmdate('Y-m-d', time() + 86400 * 20);
        foreach (self::NON_ACTIVE as $status) {
            $this->rechargeState('off', gmdate('Y-m-d', time() - 86400 * 2));
            $this->setStatus('cNULL', $status);
            static::$app->clearProbes();
            $r = $sa->postWithToken('plan/edit-post', ['id' => $this->rid(), 'id_plan' => $planB, 'recharged_on' => gmdate('Y-m-d'), 'expiration' => $future, 'time' => '23:59:59']);
            if ($this->rstatus() !== 'off' || $this->grantCalls('cNULL') !== []) {
                $bad[$status] = ['recharge' => $this->rstatus(), 'device' => $this->grantCalls('cNULL'), 'http' => $r->status];
            }
            $sa = $this->staff('ad'); // a Banned alert redirects the staff browser to logout
        }
        $this->assertSame([], $bad, 'FINDING: plan/edit-post reactivated (status off->on) and/or re-provisioned non-Active customers (plan.php:466-546 has no customer status check): ' . json_encode($bad));
    }

    /** (7) the customer's own "extend" (home?extend) is refused for a non-Active customer. */
    public function testPortalSelfExtendRefusesNonActive(): void
    {
        $this->config('extend_expired', '1');
        $this->config('extend_days', '3');
        foreach (self::LOGIN_CAPABLE as $status) {
            $this->rechargeState('off', gmdate('Y-m-d', time() - 86400 * 2));
            $c = $this->customer('cNULL');
            $tok = $c->token('home');
            $this->setStatus('cNULL', $status);
            static::$app->clearProbes();
            $r = $c->post('home', ['csrf_token' => $tok], ['extend' => (string) $this->rid(), 'stoken' => bin2hex(random_bytes(8))]);
            $this->assertSame('off', $this->rstatus(), "{$status}: not extended: " . $r->excerpt());
            $this->assertSame([], $this->grantCalls('cNULL'), "{$status}: no device call");
            $this->setStatus('cNULL', 'Active');
        }
    }

    /** (7) OWNER POLICY: a non-Active customer can still switch its own auto-renewal flag (no service effect while cron refuses). */
    public function testAutoRenewalToggleCharacterization(): void
    {
        $c = $this->customer('cNULL');
        $tok = $c->token('home');
        $this->setStatus('cNULL', 'Suspended');
        static::$db->exec('UPDATE tbl_customers SET auto_renewal = 0 WHERE id = ' . $this->cid('cNULL'));
        $c->post('home', ['csrf_token' => $tok], ['renewal' => '1']);
        $v = (string) static::$db->value('SELECT auto_renewal FROM tbl_customers WHERE id = ?', [$this->cid('cNULL')]);
        fwrite(STDERR, "\nF6 auto-renewal toggle by a Suspended customer: auto_renewal={$v}\n");
        $this->assertContains($v, ['0', '1']);
    }
}
