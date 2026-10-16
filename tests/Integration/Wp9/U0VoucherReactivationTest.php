<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 R-FC-4 (owner WP9_U0_INDEPENDENT_ACCEPTANCE_ON_HOLD — R_FC_4_VERIFICATION_AUTHORIZED): can a voucher whose
 * redemption is NOT granted - CLAIMED_REVIEW (the grant left an invoice and a voucher-only recharge row but no confirmed
 * service), or an unused voucher next to an orphan voucher-only recharge row - be served again without the voucher state
 * machine? Entry points that serve a voucher-only identity from its recharge row:
 *  E12  public voucher-only login (login.php: an existing voucher-only recharge row is re-provisioned by the device);
 *  E16  RADIUS REST authenticate (radius.php: a voucher with an active recharge authenticates);
 *  E15  RADIUS REST authorize (radius.php: an existing recharge row is answered with the service attributes);
 *  DEV  a direct device grant of the voucher-only identity (the shared device guard of every driver).
 * Contract: none of them serves a CLAIMED (in review / in flight elsewhere) voucher or an unused one; no RADIUS policy write,
 * no invoice, no recharge row, no voucher state change. Legitimate re-activation stays: a voucher granted voucher-only
 * (status 1, user 0) with an active recharge, and a legacy voucher-only recharge whose voucher row no longer exists; an
 * expired recharge stays refused as before. Synthetic data only.
 */
final class U0VoucherReactivationTest extends RadiusHttpTestCase
{
    use U0Fixture;

    private const SECRET = 'w9u0-rfc4-rest-endpoint-secret-32plus';
    private static int $restPlan = 0;

    private const FAULT_PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): during ONE grant (hook recharge_user, before the device) and only while the flag exists,
// the RADIUS write transaction fails after its writes (rolled back): a CONFIRMED device failure -> CLAIMED_REVIEW
register_hook('recharge_user', 'wp9u0_rfc4_fault');
function wp9u0_rfc4_fault() {
    $flag = __DIR__ . '/../../wp2_probe/u0_rfc4_fault';
    if (!is_file($flag)) { return; }
    unlink($flag);
    \Reseller\Radius\RadiusFaults::$hook = static function (string $p): void {
        if ($p === 'radius.after_write') { throw new \RuntimeException('w9u0 rfc4 test fault'); }
    };
}
PHP;

    protected static function restSecret(): ?string
    {
        return self::SECRET;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
        // run_hook() runs only the FIRST registered function of a hook: the U0 barrier plugin (recharge_user) is removed
        unlink(static::$app->dir . '/system/plugin/wp9u0_barrier.php');
        file_put_contents(static::$app->dir . '/system/plugin/wp9u0_rfc4.php', self::FAULT_PLUGIN);
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [self::$hotspot])[0];
        unset($seed['id']);
        self::$restPlan = static::$db->insertRow('tbl_plans', ['name_plan' => 'w9u0_rest_hotspot', 'device' => 'RadiusRest'] + $seed);
    }

    // ------------------------------------------------------------------ states

    /** a voucher-only redemption that ends CLAIMED_REVIEW through the real flow (confirmed device failure) */
    private function reviewed(): array
    {
        $code = self::tag();
        $vid = $this->voucher($code);
        touch(static::$app->dir . '/wp2_probe/u0_rfc4_fault');
        $status = (string) static::$app->exec(self::p6Code($code));
        @unlink(static::$app->dir . '/wp2_probe/u0_rfc4_fault');
        $this->assertSame('CLAIMED_REVIEW', $status, 'fixture: the grant ends in review');
        $this->assertSame('CLAIMED', self::vstatus($vid));
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_user_recharges WHERE BINARY username = ? AND customer_id = 0', [$code]), 'fixture: Package saved the recharge row');
        $this->assertSame([], self::exact($code), 'fixture: no RADIUS policy (the write was rolled back)');
        return [$code, $vid];
    }

    /** a voucher-only recharge row as fixture data (legacy / released state) */
    private function orphanRecharge(string $code, int $plan, string $status = 'on'): void
    {
        static::$db->insertRow('tbl_user_recharges', ['customer_id' => 0, 'username' => $code, 'plan_id' => $plan, 'namebp' => 'w9u0',
            'recharged_on' => date('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => $status === 'on' ? date('Y-m-d', time() + 86400 * 3) : date('Y-m-d', time() - 86400),
            'time' => '23:59:59', 'status' => $status, 'method' => 'Voucher - fixture', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 0]);
    }

    private static function voucherState(string $code, ?int $vid): array
    {
        $policy = [];
        foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
            $policy[$t] = static::$db->rows("SELECT * FROM {$t} WHERE BINARY username = ? ORDER BY id", [$code]);
        }
        return ['voucher' => $vid === null ? null : static::$db->rows('SELECT status, user FROM tbl_voucher WHERE id = ?', [$vid])[0],
            'policy' => $policy, 'transactions' => (int) static::$db->value('SELECT COUNT(*) FROM tbl_transactions WHERE BINARY username = ?', [$code]),
            'recharges' => static::$db->rows('SELECT id, status, plan_id, expiration, time FROM tbl_user_recharges WHERE BINARY username = ? ORDER BY id', [$code])];
    }

    // ------------------------------------------------------------------ entry points

    /** E12: the public voucher-only login; returns the flash message class it led to */
    private function e12(string $code): string
    {
        $c = $this->client();
        // _alert() renders its message in the POST response itself; r2() flashes it onto the next page
        $body = $c->postWithToken('login/activation', ['voucher_only' => $code], [], 'login')->body . $c->get('login')->body;
        return match (true) {
            str_contains($body, 'Voucher activation success') => 'SERVED',
            str_contains($body, 'Voucher Not Valid') => 'REFUSED_NOT_VALID',
            str_contains($body, 'Internet Voucher Expired') => 'REFUSED_EXPIRED',
            default => 'OTHER',
        };
    }

    private function restStatus(string $action, string $code): int
    {
        return $this->rest($action, ['username' => $code, 'password' => $code], ['X-Nuxr-Radius-Secret: ' . self::SECRET])->status;
    }

    /** DEV: a direct device grant of the voucher-only identity (plugin / legacy caller of the shared device guard) */
    private function device(string $code, int $plan): string
    {
        return (string) static::$app->exec(sprintf('require_once "system/devices/Radius.php"; $p = ORM::for_table("tbl_plans")->find_one(%d); try { (new Radius())->add_customer(["fullname" => "Voucher", "email" => "", "username" => %s, "password" => %s], $p); $result = "SERVED"; } catch (\Reseller\Radius\ProvisionFailure $e) { $result = "REFUSED " . $e->result->code; } catch (\Throwable $e) { $result = "ERROR " . get_class($e); }',
            $plan, var_export($code, true), var_export($code, true)));
    }

    /** runs every entry point on one state and judges the "not served, nothing changed" contract */
    private function notServed(string $case, string $code, ?int $vid, bool $withDevice = true, array $byDesign = []): array
    {
        $before = self::voucherState($code, $vid);
        $out = ['E12 login' => $this->e12($code), 'E16 REST authenticate' => $this->restStatus('authenticate', $code), 'E15 REST authorize' => $this->restStatus('authorize', $code)];
        if ($withDevice) {
            $out['DEV device grant'] = $this->device($code, self::$hotspot);
        }
        $after = self::voucherState($code, $vid);
        // $byDesign: an answer the entry point's contract gives for this state without serving (E16 authenticates an UNUSED
        // voucher so that authorize can redeem it) - reported, never counted as service
        $served = array_values(array_diff(array_keys(array_filter($out, static fn($o): bool => $o === 'SERVED' || $o === 200 || $o === 204)), $byDesign));
        $changed = array_keys(array_filter(['voucher' => $before['voucher'] !== $after['voucher'], 'radius policy' => $before['policy'] !== $after['policy'],
            'invoice' => $before['transactions'] !== $after['transactions'], 'recharge' => $before['recharges'] !== $after['recharges']]));
        $obs = ['entry_points' => $out, 'served_by' => $served, 'by_design_not_service' => $byDesign, 'state_changed' => $changed, 'voucher_after' => $after['voucher'],
            'policy_rows_after' => count($after['policy']['radcheck']), 'invoices' => $after['transactions'], 'recharge_rows' => count($after['recharges'])];
        self::record("U0 R-FC-4 {$case}", $served === [] && $changed === [] ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        return $obs;
    }

    // ------------------------------------------------------------------ cases

    public function testAVoucherInReviewIsNotServedByAnyEntryPoint(): void
    {
        [$code, $vid] = $this->reviewed();
        $obs = $this->notServed('CLAIMED_REVIEW (confirmed device failure) on a Radius plan', $code, $vid);
        $this->assertSame([], $obs['served_by'], 'no entry point serves a voucher in review ' . json_encode($obs));
        $this->assertSame([], $obs['state_changed'], 'no RADIUS write, invoice, recharge or voucher change');
        // replay through the redemption service is unchanged
        $this->assertSame('NOT_VALID', (string) static::$app->exec(self::p6Code($code)));
        $this->assertSame('CLAIMED', self::vstatus($vid));
    }

    public function testAClaimedVoucherWithARechargeOnTheRestDeviceIsNotServed(): void
    {
        // the state a CLAIMED_REVIEW outcome leaves on a RadiusRest plan (voucher CLAIMED + Package's recharge row)
        $code = self::tag();
        $vid = $this->voucher($code, self::$restPlan);
        static::$db->exec("UPDATE tbl_voucher SET status = 'CLAIMED' WHERE id = {$vid}");
        $this->orphanRecharge($code, self::$restPlan);
        $obs = $this->notServed('CLAIMED voucher + recharge row on a RadiusRest plan', $code, $vid, false);
        $this->assertSame([], $obs['served_by'], json_encode($obs));
        $this->assertSame([], $obs['state_changed']);
    }

    public function testAnUnusedVoucherNextToAnOrphanVoucherOnlyRechargeIsNotServed(): void
    {
        $code = self::tag();
        $vid = $this->voucher($code);
        $this->orphanRecharge($code, self::$hotspot);
        // E16 authenticates an UNUSED voucher by design (authorize then redeems it); authorize must not serve it from the row
        $obs = $this->notServed('unused voucher + orphan voucher-only recharge row', $code, $vid, true, ['E16 REST authenticate']);
        $this->assertSame(204, $obs['entry_points']['E16 REST authenticate'], 'E16 contract for an unused voucher unchanged');
        $this->assertSame([], $obs['served_by'], json_encode($obs));
        $this->assertSame([], $obs['state_changed']);
        $this->assertSame('0', self::vstatus($vid), 'the voucher was neither redeemed nor bypassed');
    }

    public function testLegitimateReactivationIsUnchanged(): void
    {
        $out = [];
        // a voucher granted voucher-only (status 1, user 0) with its active recharge
        $code = self::tag();
        $vid = $this->voucher($code);
        $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($code)), 'control: the grant in flight is not refused');
        $before = self::voucherState($code, $vid);
        $out['granted'] = ['E12 login' => $this->e12($code), 'E16 REST authenticate' => $this->restStatus('authenticate', $code), 'E15 REST authorize' => $this->restStatus('authorize', $code),
            'DEV device grant' => $this->device($code, self::$hotspot)];
        $after = self::voucherState($code, $vid);
        $out['granted_no_new_invoice_or_recharge'] = $before['transactions'] === $after['transactions'] && $before['recharges'] === $after['recharges'] && $after['voucher'] === ['status' => '1', 'user' => '0'];
        $out['granted_policy_is_own'] = self::password($code) === $code;
        // a legacy voucher-only recharge whose voucher row no longer exists
        $legacy = self::tag();
        $this->orphanRecharge($legacy, self::$hotspot);
        $out['legacy'] = ['E12 login' => $this->e12($legacy), 'E16 REST authenticate' => $this->restStatus('authenticate', $legacy), 'E15 REST authorize' => $this->restStatus('authorize', $legacy)];
        // an expired voucher-only recharge stays refused
        $expired = self::tag();
        $evid = $this->voucher($expired);
        static::$db->exec("UPDATE tbl_voucher SET status = '1' WHERE id = {$evid}");
        $this->orphanRecharge($expired, self::$hotspot, 'off');
        $out['expired'] = ['E12 login' => $this->e12($expired), 'E15 REST authorize' => $this->restStatus('authorize', $expired)];
        // an ordinary customer with an active recharge is unaffected
        $cust = $this->person(self::tag());
        $this->assertMatchesRegularExpression('/^INV-/', $this->provision($cust['id'], self::$restPlan));
        $out['customer REST authorize'] = $this->rest('authorize', ['username' => $cust['username'], 'password' => $cust['password']], ['X-Nuxr-Radius-Secret: ' . self::SECRET])->status;
        self::record('U0 R-FC-4 legitimate re-activation controls', 'OBSERVED', $out);
        $this->assertSame(['E12 login' => 'SERVED', 'E16 REST authenticate' => 204, 'E15 REST authorize' => 200, 'DEV device grant' => 'SERVED'], $out['granted']);
        $this->assertTrue($out['granted_no_new_invoice_or_recharge'], 're-activation writes no invoice / recharge and keeps the voucher state');
        $this->assertTrue($out['granted_policy_is_own']);
        $this->assertSame(['E12 login' => 'SERVED', 'E16 REST authenticate' => 401, 'E15 REST authorize' => 200], $out['legacy'], 'legacy voucher-only recharge (no voucher row): E16 needs a voucher row (unchanged), the rest serve');
        $this->assertSame(['E12 login' => 'REFUSED_EXPIRED', 'E15 REST authorize' => 401], $out['expired']);
        $this->assertSame(200, $out['customer REST authorize']);
    }
}
