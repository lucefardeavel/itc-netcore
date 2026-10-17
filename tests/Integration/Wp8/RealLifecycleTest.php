<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8ActivationLabCase.php';

use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Finance\Money;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\Wp8ActivationLabCase;
use Reseller\Tests\Support\Wp8Lab;

/**
 * WP8 S5 — the real subscriber lifecycle through the canonical domain services only (ActivationService for NEW /
 * RENEW / CHANGE_PLAN / REACTIVATE; LegacyReprovisionService for password / username), observed on the real RouterOS
 * CHR 7.24.5 with an independent PPPoE client. The R-WP8-CARRY-01 legacy removal / deactivation routes are never used.
 */
final class RealLifecycleTest extends Wp8ActivationLabCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (Wp8Lab::enabled()) {
            // the legacy device environment LegacyReprovisionService needs (as in the accepted WP5B tests)
            $root = dirname(__DIR__, 3);
            $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
            $GLOBALS['_app_stage'] = 'Live';
            foreach (['Package', 'Text'] as $c) {
                require_once $root . "/system/autoload/{$c}.php";
            }
            require_once $root . '/system/devices/Radius.php';
        }
    }

    private function legs(string $reference): array
    {
        $legs = $this->activationLedger((int) $this->act($reference)['id']);
        return array_map(static fn($l) => [$l['entry_type'], $l['amount']], $legs);
    }

    private function conserved(string $reference): string
    {
        $act = $this->act($reference);
        $legs = $this->activationLedger((int) $act['id']);
        $debit = Money::zero();
        $comm = Money::zero();
        foreach ($legs as $l) {
            $l['entry_type'] === 'ACTIVATION_DEBIT' ? $debit = $debit->plus(Money::fromDb($l['amount'])) : $comm = $comm->plus(Money::fromDb($l['amount']));
        }
        $this->assertSame($debit->format(), $comm->plus(Money::fromDb($act['provider_settlement_amount']))->format(), "{$reference}: debit = commissions + provider settlement");
        $this->assertSame(1, count(array_filter($legs, static fn($l) => $l['entry_type'] === 'ACTIVATION_DEBIT')), 'exactly one debit');
        $this->assertSame([['COMMITTED']], array_map(static fn($r) => [$r['status']], $this->reservations((int) $act['id'])));
        return sprintf('%s = %s + %s', $debit->format(), $comm->format(), Money::fromDb($act['provider_settlement_amount'])->format());
    }

    private function rechargeRow(int $cust): array
    {
        return static::$db->rows("SELECT plan_id, recharged_on, recharged_time, expiration, time, status FROM tbl_user_recharges WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$cust])[0];
    }

    private function annotation(string $reference): ?array
    {
        foreach ($this->timeline($reference) as $e) {
            if (str_starts_with($e, 'ANNOTATION')) {
                $id = (int) $this->act($reference)['id'];
                $m = static::$db->value("SELECT metadata FROM tbl_reseller_activation_events WHERE activation_id = ? AND event_type = 'ANNOTATION' ORDER BY sequence_no DESC LIMIT 1", [$id]);
                return json_decode((string) $m, true);
            }
        }
        return null;
    }

    public function testRenewThenChangePlanRateThenChangePlanPool(): void
    {
        $h = $this->hierarchy('1000.00', '400.00', '300.00');
        $plan1 = $this->chainPlan($h);
        $bw2 = $this->bandwidth(['name_bw' => 'nuxr_wp8_bw_128k_1m', 'rate_up' => 128, 'rate_up_unit' => 'Kbps', 'rate_down' => 1, 'rate_down_unit' => 'Mbps', 'burst' => '']);
        $plan2 = $this->chainPlan($h, ['20.00', '24.00', '27.00', '30.00'], '35.00', 'PPPOE', ['id_bw' => $bw2]);
        $plan3 = $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', 'PPPOE', ['pool' => 'nuxr_wp8_pool_dyn2']);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'S5-life-pass');
        $user = $this->username($cust);

        // NEW + real session
        $new = $this->activate($h['sales'], $cust, $plan1, 'NEW');
        $this->assertSame('COMPLETED', $new['status']);
        $s1 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S5-life-pass']);
        $this->assertTrue($s1['ok']);
        $sid1 = Wp8Lab::pppActive($user)[0]['session-id'];
        $r0 = $this->rechargeRow($cust);
        $exp0 = $this->policy($user)['check']['Expiration'];

        // A. RENEW: expiry extended from the current expiry, policy updated, settled again. Owner RENEWAL_OPTION_B_APPROVED
        // (2026-10-17; before: the live session continued with its old Session-Timeout): the session of the previous
        // period is ended and its end CONFIRMED (Disconnect-ACK + Accounting-Stop), then the subscriber re-authenticates
        $renew = $this->activate($h['sales'], $cust, $plan1, 'RENEW');
        $this->assertSame(['COMPLETED', 'RENEW'], [$renew['status'], $renew['action']]);
        $r1 = $this->rechargeRow($cust);
        $this->assertSame((new \DateTimeImmutable($r0['expiration'] . ' ' . $r0['time']))->modify('+30 days')->format('Y-m-d H:i:s'), $r1['expiration'] . ' ' . $r1['time'], 'RENEW extends from the current expiry');
        $exp1 = $this->policy($user)['check']['Expiration'];
        $this->assertNotSame($exp0, $exp1);
        $this->assertSame((new \DateTimeImmutable($r1['expiration'] . ' ' . $r1['time']))->format('d M Y H:i:s'), $exp1, 'RADIUS Expiration = saved billing expiry');
        $renewAnn = $this->annotation($renew['reference']);
        $this->assertSame(['SUCCESS:COA_ACK'], $renewAnn['disconnect'] ?? null, 'RENEW disconnects the previous-period session');
        $this->assertSame([['CONFIRMED', true]], array_map(static fn($e) => [$e['state'], $e['confirmed']], $renewAnn['sessions'] ?? []), 'ACK + Accounting-Stop: confirmed');
        $this->assertTrue($renewAnn['confirmed'] ?? null);
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down'], 'the old session is gone on the NAS');
        $this->assertNotNull($this->acct($user)[0]['acctstoptime'], 'its Accounting-Stop is recorded');
        $s1b = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S5-life-pass']);
        $this->assertTrue($s1b['ok'], 'the subscriber re-authenticates under the renewed period');
        $this->assertNotSame($sid1, Wp8Lab::pppActive($user)[0]['session-id'] ?? null, 'a new session after RENEW');
        $this->assertCount(2, $this->acct($user), 'accounting: the old session (stopped, kept) + the new one');
        $renewC = $this->conserved($renew['reference']);

        // B. CHANGE_PLAN, rate only: accepted model = policy update + Disconnect (no live rate CoA in the activation path)
        $cp = $this->activate($h['sales'], $cust, $plan2, 'CHANGE_PLAN');
        $this->assertSame(['COMPLETED', 'CHANGE_PLAN'], [$cp['status'], $cp['action']]);
        $this->assertSame('128K/1M', $this->policy($user)['reply']['Mikrotik-Rate-Limit']);
        $this->assertSame([['SUCCESS:COA_ACK'], true], [$this->annotation($cp['reference'])['disconnect'] ?? null, $this->annotation($cp['reference'])['confirmed'] ?? null], 'the old session was disconnected (ACK + Stop: confirmed)');
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down']);
        $s2 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S5-life-pass']);
        $this->assertTrue($s2['ok']);
        $this->assertSame('128000/1000000', Wp8Lab::queue('<pppoe-' . $user . '>')[0]['max-limit'], 'new rate effective on the NAS');
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[0-4]\d)$/', $s2['ip'], 'same pool');
        $cpC = $this->conserved($cp['reference']);
        $this->assertSame([['ACTIVATION_DEBIT', '30.00'], ['COMMISSION_CREDIT', '3.00'], ['COMMISSION_CREDIT', '3.00']], $this->legs($cp['reference']), 'full new-plan wholesale, no proration (accepted C14)');

        // C. CHANGE_PLAN to a different pool: policy -> Disconnect -> re-authentication -> new pool (never a CoA-ACK claim)
        $cp2 = $this->activate($h['sales'], $cust, $plan3, 'CHANGE_PLAN');
        $this->assertSame('COMPLETED', $cp2['status']);
        $this->assertSame('nuxr_wp8_pool_dyn2', $this->policy($user)['reply']['Framed-Pool']);
        $this->assertSame([['SUCCESS:COA_ACK'], true], [$this->annotation($cp2['reference'])['disconnect'] ?? null, $this->annotation($cp2['reference'])['confirmed'] ?? null]);
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down']);
        $s3 = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S5-life-pass']);
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[5-9]\d)$/', (string) $s3['ip'], 'address from the new pool after re-authentication');
        $rows = Wp8Lab::until(fn() => count($r = $this->acct($user)) === 4 && $r[0]['acctstoptime'] && $r[1]['acctstoptime'] && $r[2]['acctstoptime'] ? $r : null, 30, 2);
        $this->assertSame([$s1['ip'], $s1b['ip'], $s2['ip'], $s3['ip']], array_column($rows, 'framedipaddress'), 'one radacct row per session; history kept');
        $this->assertSame(['NAS-Request', 'NAS-Request', 'NAS-Request'], [$rows[0]['acctterminatecause'], $rows[1]['acctterminatecause'], $rows[2]['acctterminatecause']]);
        $cp2C = $this->conserved($cp2['reference']);
        $this->assertSame(4, (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_ledger WHERE entry_type = 'ACTIVATION_DEBIT' AND manager_id = ?", [$h['C']]), 'four activations, four debits, none duplicated');
        Wp8Lab::evidence('S5.renew-change-plan', ['renew' => ['expiry_before' => $r0['expiration'] . ' ' . $r0['time'], 'expiry_after' => $r1['expiration'] . ' ' . $r1['time'],
            'radius_expiration' => [$exp0, $exp1], 'session_end' => $renewAnn, 'conservation' => $renewC],
            'change_plan_rate' => ['annotation' => $this->annotation($cp['reference']), 'queue_after' => '128000/1000000', 'legs' => $this->legs($cp['reference']), 'conservation' => $cpC],
            'change_plan_pool' => ['annotation' => $this->annotation($cp2['reference']), 'addresses' => array_column($rows, 'framedipaddress'), 'conservation' => $cp2C]]);
    }

    public function testExpiryOnTheNasThenReactivate(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', 'PPPOE', ['validity' => 2, 'validity_unit' => 'Mins']);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'S5-exp-pass');
        $user = $this->username($cust);
        $new = $this->activate($h['sales'], $cust, $plan, 'NEW');
        $this->assertSame('COMPLETED', $new['status']);
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S5-exp-pass']);
        $this->assertTrue($up['ok']);
        $timeout = (int) (self::lastReply($user)['attrs']['Session-Timeout'] ?? 0);
        $this->assertGreaterThan(60, $timeout);
        $this->assertLessThanOrEqual(120, $timeout, 'Session-Timeout capped by the 2-minute billing expiry');
        $down = Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1', 'wait' => 160], 200);
        $this->assertTrue($down['down'], 'the NAS ended the current session at the expiry');
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertSame('Session-Timeout', $stop['acctterminatecause']);
        $again = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S5-exp-pass', 'wait' => 25]);
        $this->assertFalse($again['ok']);
        $this->assertSame('Access-Reject', self::lastReply($user)['code'], 'next authentication after the expiry rejected');

        // E. REACTIVATE (expired recharge of the same plan): accepted action, new period from now, settled
        $re = $this->activate($h['sales'], $cust, $plan, 'REACTIVATE');
        $this->assertSame(['COMPLETED', 'REACTIVATE'], [$re['status'], $re['action']]);
        $reAnn = $this->annotation($re['reference']) ?? ['disconnect' => [], 'sessions' => []];
        $this->assertSame([[], []], [$reAnn['disconnect'], $reAnn['sessions'] ?? []], 'no open session to disconnect');
        $back = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'S5-exp-pass']);
        $this->assertTrue($back['ok'], 'service restored after REACTIVATE');
        $reC = $this->conserved($re['reference']);
        $this->assertCount(2, $this->acct($user), 'history kept: expired session + new session');
        Wp8Lab::evidence('S5.expiry-reactivate', ['session_timeout' => $timeout, 'stop' => $stop, 'reauth_after_expiry' => 'Access-Reject',
            'reactivate' => ['status' => $re['status'], 'action' => $re['action'], 'conservation' => $reC, 'ip' => $back['ip']]]);
    }

    public function testLegacyReprovisionPasswordAndUsernameOnTheRealNas(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'S5-old-pass');
        $old = $this->username($cust);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $old, 'password' => 'S5-old-pass'])['ok']);
        $billing = static::$db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ?', [$cust]);

        // password change (the customer itself): policy rewritten, current session ended, new password only
        $r = R::run($this->customerCtx($old), $cust, R::PASSWORD_CHANGE, ['password' => 'S5-new-pass']);
        $this->customersChanged();
        $this->assertSame('DONE', $r['status']);
        $this->assertSame('S5-new-pass', $this->policy($old)['check']['Cleartext-Password']);
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down'], 'the device provision disconnected the session');
        $this->assertFalse(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $old, 'password' => 'S5-old-pass', 'wait' => 25])['ok'], 'old password unusable');
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $old, 'password' => 'S5-new-pass'])['ok'], 'new password works');

        // username change (Admin) while the old identity has a live session
        $new = self::ident('renamed');
        $r = R::run($this->ctx('ad'), $cust, R::IDENTITY_EDIT, ['username' => $new]);
        $this->customersChanged();
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertSame(['check' => [], 'reply' => [], 'groups' => []], $this->policy($old), 'no stale policy under the old identity');
        $this->assertSame('S5-new-pass', $this->policy($new)['check']['Cleartext-Password']);
        $this->assertSame(1, (int) static::labRadius()->query("SELECT COUNT(DISTINCT username) FROM radcheck WHERE username IN (" . static::labRadius()->quote($old) . ',' . static::labRadius()->quote($new) . ')')->fetchColumn(), 'exactly one RADIUS identity');
        // WP8 A3 (R-WP8-CARRY-02, formerly the OD-W8-15 finding that the old-identity session survived the rename): the
        // rename ends the live session of the OLD identity (one Disconnect per captured accounting row, after the tree
        // lock), so the customer never holds a second session under the new name
        $this->assertTrue(Wp8Lab::until(fn() => Wp8Lab::pppActive($old) === [] ? true : null, 20, 0.5), 'the old-identity session ended after the rename');
        $liveOld = Wp8Lab::pppActive($old);
        $parallel = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $new, 'password' => 'S5-new-pass', 'wait' => 30]);
        $both = ['old' => Wp8Lab::pppActive($old), 'new' => Wp8Lab::pppActive($new)];
        $this->assertTrue($parallel['ok'], 'the new name logs in');
        $this->assertSame([0, 1], [count($both['old']), count($both['new'])], 'never an old-identity session next to the new one');
        Wp8Lab::agent('pppoe_down', ['client' => 'c3']);
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        Wp8Lab::until(fn() => Wp8Lab::pppActive($old) === [], 20, 1);
        $this->assertFalse(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $old, 'password' => 'S5-new-pass', 'wait' => 25])['ok'], 'old identity unusable');
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $new, 'password' => 'S5-new-pass'])['ok'], 'new identity works on the NAS');
        $after = static::$db->rows('SELECT * FROM tbl_user_recharges WHERE customer_id = ?', [$cust]);
        $this->assertSame([$new], array_values(array_unique(array_column($after, 'username'))), 'the recharge rows follow the username (accepted WP5B)');
        $strip = static fn(array $rows) => array_map(static fn($r) => array_diff_key($r, ['username' => 1]), $rows);
        $this->assertSame($strip($billing), $strip($after), 'billing (plan, period, price, status) never changed by a re-provision');

        // uncertain RADIUS commit during a password change: the new value is kept (no silent rollback), reported, convergent
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.commit_lost') {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        try {
            R::run($this->customerCtx($new), $cust, R::PASSWORD_CHANGE, ['password' => 'S5-third-pass']);
            $this->fail('expected REPROVISION_UNCONFIRMED');
        } catch (DomainError $e) {
            $this->assertSame(R::UNCONFIRMED, $e->reason);
        } finally {
            RadiusFaults::$hook = null;
        }
        $this->customersChanged();
        $this->assertSame('S5-third-pass', static::$db->value('SELECT password FROM tbl_customers WHERE id = ?', [$cust]), 'kept, not reverted');
        $this->assertSame('S5-third-pass', $this->policy($new)['check']['Cleartext-Password'], 'the commit had in fact landed');
        Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1', 'wait' => 15]);
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        Wp8Lab::until(fn() => Wp8Lab::pppActive($new) === [], 20, 1);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $new, 'password' => 'S5-third-pass'])['ok'], 'the kept value works on the NAS');
        Wp8Lab::evidence('S5.reprovision', ['password_change' => 'DONE; old password rejected; new accepted; session disconnected by the device provision',
            'username_change' => ['old_policy' => 'empty', 'new_policy' => 'present', 'distinct_identities' => 1, 'old_session' => $r['old_session'], 'old_identity_live_session_after_rename' => $liveOld,
                'parallel_new_identity_session' => ['ok' => $parallel['ok'], 'ip' => $parallel['ip'], 'nas_old' => count($both['old']), 'nas_new' => count($both['new'])]],
            'uncertain_commit' => 'REPROVISION_UNCONFIRMED; value kept in billing and RADIUS; works on the NAS']);
    }
}
