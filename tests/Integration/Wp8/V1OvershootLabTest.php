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
 * N1 (owner CONTINUOUS_AUTONOMOUS_EXECUTION_APPROVED): measures, on the REAL lab NAS (RouterOS CHR 7.24.5 + the shipped
 * FreeRADIUS site), how far a PPPoE subscriber who uses upload AND download passes a data quota before the accounting
 * enforcer cuts the session, and checks the measured detection latency against the model
 *     latency <= I (interim) + P (pass interval) + c (Disconnect-to-silence),   overshoot = rate x latency <= min(R, ...)
 * for several interim intervals, rates, a slow enforcer pass and a failing first Disconnect. The numbers are evidence for
 * the owner's N1 decision (dev/wp10/n1_overshoot_model.py extrapolates them to realistic plan rates; the lab path carries
 * about 1 Mbps per direction, so the absolute figures are small and the SECONDS are what carries over).
 * Evidence: V1.n1-overshoot rows in the lab evidence file.
 */
final class V1OvershootLabTest extends Wp8ActivationLabCase
{
    private const BW_1M = ['name_bw' => 'nuxr_wp8_bw_1m_1m', 'rate_up' => 1, 'rate_up_unit' => 'Mbps', 'rate_down' => 1, 'rate_down_unit' => 'Mbps', 'burst' => ''];
    /** Disconnect-to-silence allowance of the model: CoA round trip + teardown (measured 0.1 - 1 s) with margin */
    private const CUT = 6;

    private function plan(array $h, int $mb): int
    {
        return $this->chainPlan($h, ['10.00', '12.00', '15.00', '17.00'], '22.00', 'PPPOE', ['id_bw' => $this->bandwidth(self::BW_1M), 'typebp' => 'Limited',
            'limit_type' => 'Data_Limit', 'data_limit' => $mb, 'data_unit' => 'MB', 'time_limit' => 0, 'time_unit' => 'Hrs']);
    }

    /**
     * One run: a PPPoE subscriber with a $mb MB quota pushes $kbps up AND down; the enforcer passes every $every s.
     *
     * @param (callable(float):void)|null $watch called once per pass with the seconds since the traffic started
     * @return array<string,mixed> measurements
     */
    private function measure(int $interim, int $every, int $kbps, int $mb, ?callable $watch = null): array
    {
        $GLOBALS['config']['nuxr_quota_interim_seconds'] = $interim;
        try {
            $h = $this->hierarchy('2000.00', '800.00', '600.00');
            $plan = $this->plan($h, $mb);
            $cust = $this->labCustomer($h['C'], $h['sales'], 'N1-pass');
            $user = $this->username($cust);
            $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        } finally {
            unset($GLOBALS['config']['nuxr_quota_interim_seconds']);
        }
        $this->assertSame((string) $interim, self::lastReply($user)['attrs']['Acct-Interim-Interval'] ?? (string) ($this->policy($user)['reply']['Acct-Interim-Interval'] ?? ''), 'the interim of this run is in the policy');
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'N1-pass'])['ok']);
        $quota = $mb * 1_000_000;
        $seconds = 150;
        $job = Wp8Lab::agentStart('traffic_mixed', ['client' => 'c1', 'seconds' => $seconds, 'kbps_up' => $kbps, 'kbps_down' => $kbps]);
        $enf = new QuotaEnforcer(static::labRadius());
        $t0 = microtime(true);
        $acted = null;
        $firstSeen = null;
        while (microtime(true) - $t0 < $seconds + 20) {
            $row = $this->acct($user)[0] ?? null;
            $t = microtime(true) - $t0;
            if ($firstSeen === null && $row !== null && (int) $row['acctinputoctets'] + (int) $row['acctoutputoctets'] >= $quota) {
                $firstSeen = round($t, 1);   // the first accounting record at / over the quota that the enforcer could see
            }
            if ($watch !== null) {
                $watch($t);
            }
            foreach ($enf->pass() as $a) {
                $acted ??= $a + ['at' => round($t, 1)];
            }
            if ($acted !== null && ($row['acctstoptime'] ?? null)) {
                break;
            }
            sleep($every);
        }
        $traffic = Wp8Lab::agentResult($job, $seconds + 60);
        $s = $this->stopped($user);
        $used = (int) $s['acctinputoctets'] + (int) $s['acctoutputoctets'];
        $dur = max(1, (int) $s['acctsessiontime']);
        $rate = $used / $dur;
        $tCross = $quota / $rate;
        $latency = $dur - $tCross;
        return ['interim' => $interim, 'pass_every' => $every, 'kbps_per_direction' => $kbps, 'quota' => $quota, 'used' => $used, 'overshoot' => $used - $quota,
            'rate_bytes_per_s' => (int) $rate, 't_cross_s' => round($tCross, 1), 't_stop_s' => $dur, 'latency_s' => round($latency, 1),
            'first_record_over_quota_at_s' => $firstSeen, 'enforcer_acted_at_s' => $acted['at'] ?? null, 'ended' => $acted['ended'] ?? [],
            'model_latency_bound_s' => $interim + $every + self::CUT, 'cause' => $s['acctterminatecause'], 'traffic' => $traffic];
    }

    private function stopped(string $user): array
    {
        return Wp8Lab::until(function () use ($user) {
            $r = $this->acct($user)[0] ?? null;
            return $r !== null && $r['acctstoptime'] ? $r : null;
        }, 40, 1) ?? $this->fail("no Accounting-Stop for {$user}");
    }

    private function assertModel(array $m, string $why): void
    {
        $this->assertSame([['CONFIRMED', true]], array_map(static fn($e) => [$e['state'], $e['confirmed']], $m['ended']), $why . ': Disconnect-ACK + Stop');
        $this->assertGreaterThan(0, $m['overshoot'], $why . ': mixed traffic passes the quota before it is seen (no native total cap)');
        $this->assertLessThanOrEqual($m['model_latency_bound_s'], $m['latency_s'], $why . ': detection latency within I + P + c');
        $this->assertLessThanOrEqual($m['quota'], $m['overshoot'], $why . ': never beyond the native per-direction caps');
    }

    /** @return array<string,array{0:int,1:int,2:int,3:int}> interim, pass, kbps per direction, quota MB */
    public static function matrix(): array
    {
        // quota sized to be crossed after ~25 s at the offered rate (the quota is crossed BEFORE the first interim for I >= 30)
        return [
            'I60 P5 400' => [60, 5, 400, 3], 'I60 P5 900' => [60, 5, 900, 6],
            'I30 P5 400' => [30, 5, 400, 3], 'I30 P5 900' => [30, 5, 900, 6],
            'I15 P5 400' => [15, 5, 400, 3], 'I15 P5 900' => [15, 5, 900, 6],
            'I10 P5 400' => [10, 5, 400, 3], 'I10 P5 900' => [10, 5, 900, 6],
            'I30 P15 900' => [30, 15, 900, 6],   // a slow enforcer pass
        ];
    }

    /** @dataProvider matrix */
    public function testDetectionLatencyFollowsTheModel(int $interim, int $every, int $kbps, int $mb): void
    {
        $m = $this->measure($interim, $every, $kbps, $mb);
        Wp8Lab::evidence('V1.n1-overshoot', $m);
        $this->assertModel($m, "I={$interim} P={$every} {$kbps} kbps");
    }

    /**
     * Fault: the first Disconnect gets no answer (the NAS ignores CoA for a while). The enforcer's retry is then the
     * latency; the retry delay after a non-answer is QuotaEnforcer::RESEND_AFTER_NO_ANSWER (not the 60 s of a NAK).
     */
    public function testAFailingFirstDisconnectIsRetriedQuickly(): void
    {
        $blocked = false;
        $reopen = null;
        $m = $this->measure(15, 5, 900, 6, function (float $t) use (&$blocked, &$reopen): void {
            if (!$blocked && $t >= 18) {                           // just before the quota is crossed (~25 s): the NAS stops accepting CoA
                Wp8Lab::ros('/radius/incoming/set', '=accept=no');
                $blocked = true;
                $reopen = $t + 40;                                  // ... and accepts it again 40 s later
            }
            if ($blocked && $reopen !== null && $t >= $reopen) {
                Wp8Lab::ros('/radius/incoming/set', '=accept=yes', '=port=3799');
                $reopen = null;
            }
        });
        Wp8Lab::ros('/radius/incoming/set', '=accept=yes', '=port=3799');
        Wp8Lab::evidence('V1.n1-overshoot-failing-disconnect', $m);
        $this->assertSame([['CONFIRMED', true]], array_map(static fn($e) => [$e['state'], $e['confirmed']], $m['ended']));
        // the CoA path is closed for 40 s: the cut cannot come earlier than that, and must come soon after it reopens
        $this->assertLessThanOrEqual(40 + QuotaEnforcer::RESEND_AFTER_NO_ANSWER + 5 + self::CUT + 15, $m['t_stop_s'] - $m['t_cross_s'] + 40, 'retry within the short resend delay once the path is back');
        $this->assertLessThanOrEqual($m['quota'], $m['overshoot'], 'the native per-direction caps still hold');
    }
}
