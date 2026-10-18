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

    private function quotaPlan(array $h, int $mb): int
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
            $plan = $this->quotaPlan($h, $mb);
            $cust = $this->labCustomer($h['C'], $h['sales'], 'N1-pass');
            $user = $this->username($cust);
            $this->assertSame('COMPLETED', $this->activate($h['sales'], $cust, $plan, 'NEW')['status']);
        } finally {
            unset($GLOBALS['config']['nuxr_quota_interim_seconds']);
        }
        $this->assertSame((string) $interim, self::lastReply($user)['attrs']['Acct-Interim-Interval'] ?? (string) ($this->policy($user)['reply']['Acct-Interim-Interval'] ?? ''), 'the interim of this run is in the policy');
        $ledgerBefore = [(int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger'), static::$db->rows('SELECT * FROM tbl_manager_wallets ORDER BY manager_id')];
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'N1-pass'])['ok']);
        $quota = $mb * 1_000_000;
        $seconds = 150;
        $job = Wp8Lab::agentStart('traffic_mixed', ['client' => 'c1', 'seconds' => $seconds, 'kbps_up' => $kbps, 'kbps_down' => $kbps]);
        $enf = new QuotaEnforcer(static::labRadius());
        $t0 = microtime(true);
        $acted = null;
        $done = null;
        $attempts = 0;
        $log = [];
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
                $attempts++;
                $log[] = [round($t, 1), $a['ended'][0]['state'] ?? null];
                $acted ??= $a + ['at' => round($t, 1)];
                if ($done === null && ($a['ended'][0]['state'] ?? null) === 'CONFIRMED') {
                    $done = $a + ['at' => round($t, 1)];
                }
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
        // Option G on the real NAS: the overshoot is reported (actual > quota), the plan covers the quota only, and it cost nothing
        $g = (new \Reseller\Radius\QuotaUsage(static::labRadius()))->current($user)[0] ?? [];
        $this->assertSame((string) $quota, $g['limit'] ?? null);
        $this->assertSame((string) $used, $g['actual'] ?? null, 'actual = the raw accounting of the period');
        $this->assertSame((string) min($used, $quota), $g['billable'] ?? null, 'billable = min(actual, quota)');
        $this->assertSame((string) max(0, $used - $quota), $g['overshoot'] ?? null);
        $this->assertSame($ledgerBefore[0], (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger'), 'the overshoot created no ledger entry');
        $this->assertSame($ledgerBefore[1], static::$db->rows('SELECT * FROM tbl_manager_wallets ORDER BY manager_id'), 'and moved no wallet');
        return ['interim' => $interim, 'pass_every' => $every, 'kbps_per_direction' => $kbps, 'quota' => $quota, 'used' => $used, 'overshoot' => $used - $quota,
            'rate_bytes_per_s' => (int) $rate, 't_cross_s' => round($tCross, 1), 't_stop_s' => $dur, 'latency_s' => round($latency, 1),
            'first_record_over_quota_at_s' => $firstSeen, 'enforcer_acted_at_s' => $acted['at'] ?? null, 'confirmed_at_s' => $done['at'] ?? null, 'disconnect_attempts' => $attempts, 'attempt_log' => $log,
            'ended' => ($done ?? $acted)['ended'] ?? [],
            'option_g' => $g, 'model_latency_bound_s' => $interim + $every + self::CUT, 'cause' => $s['acctterminatecause'], 'traffic' => $traffic];
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
        $this->assertLessThanOrEqual($m['quota'] * 1.05, $m['overshoot'], $why . ': never beyond the native per-direction caps (the NAS cuts up to ~4 % past a limit)');
    }

    /** @return array<string,array{0:int,1:int,2:int,3:int}> interim, pass, kbps per direction, quota MB */
    public static function matrix(): array
    {
        // quota sized to be crossed after ~45 s at the offered rate (mixed traffic carries ~88 % of the offered kbit/s): the next interim
        // record then arrives before the NAS's own per-direction cap (~90 s), so the enforcer's detection latency is what is measured
        return [
            'I60 P5 400' => [60, 5, 400, 4], 'I60 P5 900' => [60, 5, 900, 9],
            'I30 P5 400' => [30, 5, 400, 4], 'I30 P5 900' => [30, 5, 900, 9],
            'I15 P5 400' => [15, 5, 400, 4], 'I15 P5 900' => [15, 5, 900, 9],
            'I10 P5 400' => [10, 5, 400, 4], 'I10 P5 900' => [10, 5, 900, 9],
            'I30 P15 900' => [30, 15, 900, 9],   // a slow enforcer pass
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
     * The quota is crossed BEFORE the first interim record of a long interval (I = 60 s, crossed after ~27 s): the NAS's own
     * per-direction caps (each direction is limited to the full quota) fire first, at ~2x the crossing time. The overshoot is then the
     * whole quota - the documented worst case of PPPoE, the reason a short interval matters for small quotas.
     */
    public function testAQuotaCrossedBeforeTheFirstInterimIsCutByTheNasCap(): void
    {
        $m = $this->measure(60, 5, 900, 6);
        Wp8Lab::evidence('V1.n1-overshoot-early-cross', $m);
        $this->assertGreaterThan(0, $m['overshoot']);
        $this->assertLessThanOrEqual($m['quota'] * 1.05, $m['overshoot'], 'the native per-direction caps hold (the NAS cuts up to ~4 % past a limit)');
        $this->assertGreaterThan($m['quota'] * 0.9, $m['overshoot'], 'the worst case is reached: the whole quota again');
        $this->assertSame([], $m['ended'], 'no Disconnect was needed: the NAS ended the session first');
    }

    /**
     * Fault: the first Disconnect gets no answer (the NAS ignores CoA for a while). The enforcer's retry is then the
     * latency; the retry delay after a non-answer is QuotaEnforcer::RESEND_AFTER_NO_ANSWER (not the 60 s of a NAK).
     */
    public function testAFailingFirstDisconnectIsRetriedQuickly(): void
    {
        // The lab agent runs one operation at a time (the traffic job occupies it), so the CoA path is closed on the application
        // side: nas.ports points at a port nobody listens on - the Disconnect gets no answer, exactly as with a NAS that ignores CoA.
        $radius = static::labRadius();
        $nas = (string) $radius->query("SELECT nasname FROM nas ORDER BY id LIMIT 1")->fetchColumn();
        $orig = $radius->prepare('SELECT ports FROM nas WHERE nasname = ?');
        $orig->execute([$nas]);
        $port = $orig->fetchColumn();
        $set = static function (?int $p) use ($radius, $nas): void {
            $radius->prepare('UPDATE nas SET ports = ? WHERE nasname = ?')->execute([$p, $nas]);
        };
        $blocked = false;
        $reopen = null;
        $reopenedAt = null;
        try {
            // 12 MB at 900 kbps per direction: the quota is crossed after ~55 s, the native per-direction caps cut at ~110 s
            $m = $this->measure(15, 5, 900, 12, function (float $t) use (&$blocked, &$reopen, &$reopenedAt, $set): void {
                if (!$blocked && $t >= 40) {                           // before the quota is crossed: the NAS stops answering CoA
                    $set(3898);
                    $blocked = true;
                    $reopen = $t + 40;                                  // ... and answers again 40 s later
                }
                if ($blocked && $reopen !== null && $t >= $reopen) {
                    $set(3799);
                    $reopenedAt = round($t, 1);
                    $reopen = null;
                }
            });
        } finally {
            $set($port === false || $port === null ? null : (int) $port);
        }
        $m['coa_path_reopened_at_s'] = $reopenedAt;
        Wp8Lab::evidence('V1.n1-overshoot-failing-disconnect', $m);
        $this->assertNotNull($reopenedAt, 'the CoA path was closed and reopened during the run');
        $this->assertGreaterThanOrEqual(2, $m['disconnect_attempts'], 'the first Disconnect got no answer and was retried');
        $this->assertSame('CONFIRMED', $m['ended'][0]['state'] ?? null, 'ended once the path was back');
        // once the path is back the retry comes within the short resend delay (+ the pass grid + the Disconnect time)
        $this->assertLessThanOrEqual($reopenedAt + QuotaEnforcer::RESEND_AFTER_NO_ANSWER + 5 + self::CUT, (float) $m['confirmed_at_s'], 'retry within the short resend delay once the path is back');
        $this->assertLessThanOrEqual($m['quota'] * 1.05, $m['overshoot'], 'the native per-direction caps still hold');
    }
}
