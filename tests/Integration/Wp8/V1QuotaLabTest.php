<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8ActivationLabCase.php';

use Reseller\Radius\QuotaEnforcer;
use Reseller\Radius\QuotaPolicy;
use Reseller\Tests\Support\Wp8ActivationLabCase;
use Reseller\Tests\Support\Wp8Lab;

/**
 * V1 data / time quota and Option B renewal on the REAL lab NAS (RouterOS CHR 7.24.5 + the shipped FreeRADIUS 3.2.8 site),
 * owner decisions QUOTA_V1_GB_SUPPORT_APPROVED / RENEWAL_OPTION_B_APPROVED (2026-10-17). Plans are sold through the
 * canonical ActivationService; sessions come from the independent lab clients; traffic from the lab traffic peer.
 *  - Hotspot data plan: the NAS caps the session at its share (exact), shared users split the remaining quota, the
 *    quota is never exceeded in total, an exhausted quota is refused at login;
 *  - PPPoE data plan, download only: the NAS caps the direction natively; exhausted -> refused at login;
 *  - PPPoE data plan, upload + download: the accounting enforcer ends the session; overshoot measured against the
 *    documented bound (NOT a hard cap);
 *  - PPPoE time plan: Session-Timeout = the remaining time; exhausted -> refused;
 *  - RENEW / CHANGE_PLAN during a live data session: the previous-period session is ended and CONFIRMED (ACK + Stop),
 *    the next session gets the new period's full share (old and new usage never mix), settlement exactly once;
 *  - faults: FreeRADIUS frozen mid-session (the NAS limit holds), NAS link down for the enforcer (UNCONFIRMED, never
 *    fabricated; confirmed after the path returns).
 */
final class V1QuotaLabTest extends Wp8ActivationLabCase
{
    private const BW_FAST = ['name_bw' => 'nuxr_wp8_bw_8m', 'rate_up' => 8, 'rate_up_unit' => 'Mbps', 'rate_down' => 8, 'rate_down_unit' => 'Mbps', 'burst' => ''];
    private const BW_1M = ['name_bw' => 'nuxr_wp8_bw_1m_1m', 'rate_up' => 1, 'rate_up_unit' => 'Mbps', 'rate_down' => 1, 'rate_down_unit' => 'Mbps', 'burst' => ''];

    private function dataPlan(array $h, string $type, int $mb, array $bw = self::BW_FAST, array $extra = []): int
    {
        return $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', $type, $extra + ['id_bw' => $this->bandwidth($bw), 'typebp' => 'Limited',
            'limit_type' => 'Data_Limit', 'data_limit' => $mb, 'data_unit' => 'MB', 'time_limit' => 0, 'time_unit' => 'Hrs']);
    }

    private function used(array $row): int
    {
        return (int) $row['acctinputoctets'] + (int) $row['acctoutputoctets'];
    }

    /** the accounting row of the session, once its Stop is recorded */
    private function stopped(string $user, int $index = -1): array
    {
        return Wp8Lab::until(function () use ($user, $index) {
            $rows = $this->acct($user);
            $r = $rows[$index < 0 ? count($rows) + $index : $index] ?? null;
            return $r !== null && $r['acctstoptime'] ? $r : null;
        }, 30, 1) ?? $this->fail("no Accounting-Stop for {$user}");
    }

    private function hsUp(string $client, string $user, string $pw): array
    {
        Wp8Lab::agent('hs_dhcp', ['client' => $client]);
        return Wp8Lab::agent('hs_login', ['client' => $client, 'user' => $user, 'password' => $pw]);
    }

    public function testHotspotDataPlanIsCappedPerSessionAndInTotal(): void
    {
        $h = $this->hierarchy();
        $plan = $this->dataPlan($h, 'Hotspot', 4, self::BW_FAST, ['shared_users' => 2]);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'Q-hs-pass', 'Hotspot');
        $user = $this->username($cust);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        $this->assertSame(['4000000', '60'], [$this->policy($user)['check']['Max-Data'] ?? null, $this->policy($user)['reply']['Acct-Interim-Interval'] ?? null]);

        // session 1: share = 4 MB / 2 shared users
        $this->assertTrue($this->hsUp('c2', $user, 'Q-hs-pass')['ok']);
        $this->assertSame('2000000', Wp8Lab::hotspotActive($user)[0]['limit-bytes-total'] ?? null, 'the NAS holds the session share');
        $tr1 = Wp8Lab::agent('traffic', ['client' => 'c2', 'direction' => 'down', 'seconds' => 12, 'kbps_offer' => 4000]);
        $this->assertNotNull(Wp8Lab::until(fn() => Wp8Lab::hotspotActive($user) === [] ? true : null, 20, 0.5), 'the NAS ended the session at its share');
        $s1 = $this->stopped($user);
        $this->assertLessThanOrEqual(2_000_000 * 1.01, $this->used($s1), 'exact per-session cap (1 % accounting granularity)');

        // session 2 (second device): share of the REMAINING quota
        $this->assertTrue($this->hsUp('c4', $user, 'Q-hs-pass')['ok']);
        $share2 = (int) (Wp8Lab::hotspotActive($user)[0]['limit-bytes-total'] ?? 0);
        $this->assertSame(intdiv(4_000_000 - $this->used($s1), 2), $share2, 'share = (quota - period usage) / shared users');
        Wp8Lab::agent('traffic', ['client' => 'c4', 'direction' => 'down', 'seconds' => 10, 'kbps_offer' => 4000]);
        Wp8Lab::until(fn() => Wp8Lab::hotspotActive($user) === [] ? true : null, 20, 0.5);
        $s2 = $this->stopped($user);
        $total = $this->used($s1) + $this->used($s2);
        $this->assertLessThanOrEqual(4_000_000 * 1.01, $total, 'the quota is never exceeded in total');

        // exhaust the rest, then the login is refused
        $exhausted = false;
        for ($i = 0; $i < 8 && !$exhausted; $i++) {
            $l = $this->hsUp('c2', $user, 'Q-hs-pass');
            if (!$l['ok']) {
                $exhausted = true;
                break;
            }
            Wp8Lab::agent('traffic', ['client' => 'c2', 'direction' => 'down', 'seconds' => 6, 'kbps_offer' => 4000]);
            Wp8Lab::until(fn() => Wp8Lab::hotspotActive($user) === [] ? true : null, 20, 0.5);
            $this->stopped($user);
        }
        $rows = $this->acct($user);
        $sum = array_sum(array_map(fn($r) => $this->used($r), $rows));
        $this->assertLessThanOrEqual(4_000_000 * 1.01, $sum, 'total over every session within the quota');
        Wp8Lab::evidence('V1.hotspot-data', ['quota' => 4_000_000, 'shared_users' => 2, 'session1' => ['share' => 2_000_000, 'used' => $this->used($s1), 'cause' => $s1['acctterminatecause'], 'traffic' => $tr1],
            'session2' => ['share' => $share2, 'used' => $this->used($s2)], 'sessions' => count($rows), 'total_used' => $sum, 'login_refused_when_exhausted' => $exhausted,
            'last_reply' => self::lastReply($user)]);
        if (!$exhausted) {
            $this->markTestIncomplete('quota not exhausted within 8 sessions (shares halve): recorded, not passed');
        }
        $this->assertSame('Access-Reject', self::lastReply($user)['code']);
    }

    public function testPppoeDataPlanDownloadIsCappedNatively(): void
    {
        $h = $this->hierarchy();
        $plan = $this->dataPlan($h, 'PPPOE', 3);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'Q-pp-pass');
        $user = $this->username($cust);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-pp-pass'])['ok']);
        $detail = Wp8Lab::ros('/ppp/active/print', '=detail=', '?name=' . $user)[0] ?? [];
        $this->assertSame(['3000000', '3000000'], [$detail['limit-bytes-in'] ?? null, $detail['limit-bytes-out'] ?? null], 'per-direction limits = the remaining quota');
        $tr = Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'down', 'seconds' => 14, 'kbps_offer' => 4000]);
        $this->assertNotNull(Wp8Lab::until(fn() => Wp8Lab::pppActive($user) === [] ? true : null, 20, 0.5), 'the NAS ended the session');
        $s = $this->stopped($user);
        $this->assertLessThanOrEqual(3_000_000 * 1.02, $this->used($s), 'native per-direction cap');
        $again = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-pp-pass', 'wait' => 25]);
        $this->assertFalse($again['ok'], 'exhausted: refused');
        $reply = self::lastReply($user);
        $this->assertSame('Access-Reject', $reply['code']);
        Wp8Lab::evidence('V1.pppoe-data-native', ['quota' => 3_000_000, 'limits' => [$detail['limit-bytes-in'] ?? null, $detail['limit-bytes-out'] ?? null], 'traffic' => $tr,
            'used' => $this->used($s), 'cause' => $s['acctterminatecause'], 'relogin' => $reply]);
    }

    public function testPppoeMixedTrafficIsEndedByTheEnforcerWithinTheBound(): void
    {
        $h = $this->hierarchy();
        $quota = 10_000_000;
        $plan = $this->dataPlan($h, 'PPPOE', 10, self::BW_1M);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'Q-mx-pass');
        $user = $this->username($cust);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-mx-pass'])['ok']);
        $this->assertSame('60', self::lastReply($user)['attrs']['Acct-Interim-Interval'] ?? null);
        $job = Wp8Lab::agentStart('traffic_mixed', ['client' => 'c1', 'seconds' => 110, 'kbps_up' => 1500, 'kbps_down' => 1500]);
        $enf = new QuotaEnforcer(static::labRadius());
        $t0 = microtime(true);
        $actions = [];
        $crossed = null;
        while (microtime(true) - $t0 < 130) {
            $row = $this->acct($user)[0] ?? null;
            if ($crossed === null && $row !== null && $this->used($row) >= $quota) {
                $crossed = round(microtime(true) - $t0, 1);   // first accounting record at / over the quota
            }
            foreach ($enf->pass() as $a) {
                $actions[] = $a + ['at' => round(microtime(true) - $t0, 1)];
            }
            if ($actions !== [] && ($row['acctstoptime'] ?? null)) {
                break;
            }
            sleep(5);   // the production pass interval is 15 s (quota.php --every), the bound uses 15 s
        }
        $traffic = Wp8Lab::agentResult($job, 200);
        $s = $this->stopped($user, 0);
        $used = $this->used($s);
        $rate = 2 * 1_000_000 / 8;   // the plan's 1M / 1M in bytes per second, both directions
        $bound = min($quota, (int) ($rate * (QuotaPolicy::INTERIM_SECONDS + 15 + 6)));
        $this->assertNotSame([], $actions, 'the enforcer acted');
        $this->assertSame([['CONFIRMED', true]], array_map(static fn($e) => [$e['state'], $e['confirmed']], $actions[0]['ended']), 'Disconnect-ACK + Accounting-Stop');
        $this->assertGreaterThan($quota, $used, 'mixed traffic passes the quota before the enforcer sees it (no native total cap on PPPoE)');
        $this->assertLessThanOrEqual($quota + $bound, $used, 'overshoot within the documented bound');
        $this->assertLessThan(2 * $quota, $used, 'never beyond the native per-direction caps');
        Wp8Lab::evidence('V1.pppoe-mixed-enforcer', ['quota' => $quota, 'rate_bytes_per_s' => $rate, 'bound' => $bound, 'used' => $used, 'overshoot' => $used - $quota,
            'first_record_over_quota_at_s' => $crossed, 'enforcer_actions' => $actions, 'cause' => $s['acctterminatecause'], 'traffic' => $traffic]);
    }

    public function testPppoeTimePlanUsesSessionTimeout(): void
    {
        $h = $this->hierarchy();
        $plan = $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', 'PPPOE', ['typebp' => 'Limited', 'limit_type' => 'Time_Limit',
            'time_limit' => 1, 'time_unit' => 'Mins', 'data_limit' => 0, 'data_unit' => 'MB']);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'Q-tm-pass');
        $user = $this->username($cust);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-tm-pass'])['ok']);
        $timeout = (int) (self::lastReply($user)['attrs']['Session-Timeout'] ?? 0);
        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(60, $timeout, 'the remaining time quota');
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1', 'wait' => 100], 130)['down']);
        $s = $this->stopped($user);
        $again = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-tm-pass', 'wait' => 25]);
        $this->assertFalse($again['ok'], 'time quota exhausted: refused');
        Wp8Lab::evidence('V1.pppoe-time', ['quota_s' => 60, 'session_timeout' => $timeout, 'session_time' => $s['acctsessiontime'], 'cause' => $s['acctterminatecause'], 'relogin' => self::lastReply($user)]);
    }

    public function testRenewAndChangePlanDuringALiveDataSession(): void
    {
        $h = $this->hierarchy('1000.00', '400.00', '300.00');
        $plan = $this->dataPlan($h, 'PPPOE', 10);
        $plan2 = $this->dataPlan($h, 'PPPOE', 20);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'Q-rn-pass');
        $user = $this->username($cust);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-rn-pass'])['ok']);
        Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'down', 'seconds' => 4, 'kbps_offer' => 4000]);
        $debits = static fn() => (int) static::$db->value("SELECT COUNT(*) FROM tbl_manager_ledger WHERE entry_type = 'ACTIVATION_DEBIT' AND manager_id = ?", [$h['C']]);
        $d0 = $debits();

        // RENEW: the previous-period session is ended and confirmed; the new session gets the full new-period share
        $renew = $this->activate($h['sales'], $cust, $plan, 'RENEW');
        $this->assertSame('COMPLETED', $renew['status']);
        $ann = json_decode((string) static::$db->value("SELECT e.metadata FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ? AND e.event_type = 'ANNOTATION' ORDER BY e.sequence_no DESC LIMIT 1", [$renew['reference']]), true);
        $this->assertSame([['CONFIRMED', true]], array_map(static fn($e) => [$e['state'], $e['confirmed']], $ann['sessions'] ?? []), 'ACK + Accounting-Stop');
        $old = $this->stopped($user, 0);
        $this->assertGreaterThan(1_000_000, $this->used($old), 'the old session carried usage of the previous period');
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down']);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-rn-pass'])['ok']);
        $limit = Wp8Lab::ros('/ppp/active/print', '=detail=', '?name=' . $user)[0]['limit-bytes-out'] ?? null;
        $this->assertSame('10000000', $limit, 'new period: the old usage is not counted again (no mixing)');
        $this->assertSame($d0 + 1, $debits(), 'RENEW settled exactly once');

        // CHANGE_PLAN to the 20 MB plan during the new session: ended + confirmed, next session gets the new plan's quota
        Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'down', 'seconds' => 3, 'kbps_offer' => 4000]);
        $cp = $this->activate($h['sales'], $cust, $plan2, 'CHANGE_PLAN');
        $this->assertSame('COMPLETED', $cp['status']);
        $cpAnn = json_decode((string) static::$db->value("SELECT e.metadata FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id WHERE a.reference = ? AND e.event_type = 'ANNOTATION' ORDER BY e.sequence_no DESC LIMIT 1", [$cp['reference']]), true);
        $this->assertTrue($cpAnn['confirmed'] ?? null);
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down']);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-rn-pass'])['ok']);
        $this->assertSame('20000000', Wp8Lab::ros('/ppp/active/print', '=detail=', '?name=' . $user)[0]['limit-bytes-out'] ?? null);
        $this->assertSame($d0 + 2, $debits(), 'one debit per paid action, none for a session end');
        $this->assertCount(3, $this->acct($user), 'accounting history kept: one row per session');
        Wp8Lab::evidence('V1.renew-change-plan-live', ['renew' => ['annotation' => $ann, 'old_session_used' => $this->used($old), 'new_limit' => $limit],
            'change_plan' => ['annotation' => $cpAnn], 'debits' => $debits() - $d0, 'acct_rows' => count($this->acct($user))]);
    }

    public function testFaultsTheNasLimitHoldsAndTheEnforcerNeverFabricates(): void
    {
        $h = $this->hierarchy();
        $plan = $this->dataPlan($h, 'PPPOE', 3);
        $cust = $this->labCustomer($h['C'], $h['sales'], 'Q-ft-pass');
        $user = $this->username($cust);
        $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);

        // FreeRADIUS frozen mid-session: the NAS still caps the session at the share it got at login
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-ft-pass'])['ok']);
        Wp8Lab::agent('fr', ['action' => 'pause']);
        try {
            Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'down', 'seconds' => 14, 'kbps_offer' => 4000]);
            $cut = Wp8Lab::until(fn() => Wp8Lab::pppActive($user) === [] ? true : null, 20, 0.5);
        } finally {
            Wp8Lab::agent('fr', ['action' => 'unpause']);
        }
        $this->assertNotNull($cut, 'the NAS ended the session at its share while FreeRADIUS was frozen');
        $frozen = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 60, 2);

        // NAS link down for the enforcer: an over-quota session is reported UNCONFIRMED, never fabricated
        static::labRadius()->prepare("UPDATE radcheck SET value = '20000000' WHERE username = ? AND attribute = 'Max-Data'")->execute([$user]);
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Q-ft-pass'])['ok']);
        Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'down', 'seconds' => 3, 'kbps_offer' => 2000]);
        static::labRadius()->prepare("UPDATE radcheck SET value = '1000' WHERE username = ? AND attribute = 'Max-Data'")->execute([$user]);   // synthetic: the quota is now used up
        // the usage reaches accounting with the next interim (60 s)
        $this->assertNotNull(Wp8Lab::until(fn() => (new QuotaEnforcer(static::labRadius()))->overQuota() !== [] ? true : null, 90, 3), 'over quota in accounting');
        Wp8Lab::agent('nas_link', ['state' => 'down']);
        try {
            $down = (new QuotaEnforcer(static::labRadius()))->pass();
        } finally {
            Wp8Lab::agent('nas_link', ['state' => 'up']);
        }
        $this->assertSame([[false]], array_map(static fn($a) => array_column($a['ended'], 'confirmed'), $down), 'NAS unreachable: not confirmed');
        $this->assertNotSame([], Wp8Lab::pppActive($user), 'nothing fabricated: the session is still up');
        sleep(3);
        $up = (new QuotaEnforcer(static::labRadius()))->pass();
        $this->assertSame([['CONFIRMED']], array_map(static fn($a) => array_column($a['ended'], 'state'), $up), 'path restored: ended and confirmed');
        Wp8Lab::evidence('V1.faults', ['freeradius_frozen' => ['session_cut_by_nas' => (bool) $cut, 'used' => $frozen ? $this->used($frozen) : null, 'stop_recorded_after_unpause' => (bool) $frozen],
            'nas_link_down' => $down, 'after_restore' => $up]);
    }
}
