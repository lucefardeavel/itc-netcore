<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\QuotaEnforcer;
use Reseller\Radius\RadiusFaults;
use Reseller\Radius\SessionTermination;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\StubNas;

/**
 * V1 quota enforcer (owner QUOTA_V1_GB_SUPPORT_APPROVED) and confirmed session termination:
 *  - SessionTermination: CONFIRMED only on the NAS's evidence (ACK + Accounting-Stop, or NAK 503); an ACK without the
 *    Stop, a silent NAS and an unknown NAS are not confirmed; one Disconnect per session (+ 2 identical retransmissions);
 *  - QuotaEnforcer: a subscriber whose CURRENT-period usage reached Max-Data / Max-All-Session gets its open sessions of
 *    that period ended; below the quota, unlimited subscribers and sessions of a previous period are never touched;
 *    a session is not re-sent a Disconnect within RESEND_SECONDS; accounting rows are never changed by the enforcer.
 */
final class V1QuotaEnforcerTest extends ActivationTestCase
{
    private const SECRET = 'v1-quota-enforcer-secret';

    protected function setUp(): void
    {
        parent::setUp();
        static::$db->exec('DELETE FROM nas');
        // the RADIUS tables are not rolled back between tests: every test starts without the rows of the previous ones
        static::$db->exec("DELETE FROM radcheck WHERE username LIKE 'v1q%'");
        static::$db->exec("DELETE FROM radacct WHERE username LIKE 'v1q%'");
        RadiusFaults::$hook = null;
    }

    protected function tearDown(): void
    {
        RadiusFaults::$hook = null;
        parent::tearDown();
    }

    private function stub(string $mode): StubNas
    {
        $stub = new StubNas(self::SECRET, $mode);
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'v1q', 'type' => 'other', 'ports' => $stub->port, 'secret' => self::SECRET, 'description' => 'V1 quota stub']);
        return $stub;
    }

    private function quota(string $user, array $check): void
    {
        foreach ($check as $a => $v) {
            static::$db->insertRow('radcheck', ['username' => $user, 'attribute' => $a, 'op' => ':=', 'value' => (string) $v]);
        }
    }

    private function session(string $user, int $startedAgo, int $in, int $out, int $time = 60, ?bool $stopped = false, string $nas = '127.0.0.1'): int
    {
        return static::$db->insertRow('radacct', ['acctsessionid' => 'v1q' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $user,
            'nasipaddress' => $nas, 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - $startedAgo), 'acctupdatetime' => gmdate('Y-m-d H:i:s', time() - 30),
            'acctstoptime' => $stopped ? gmdate('Y-m-d H:i:s', time() - 10) : null, 'acctsessiontime' => $time, 'acctinputoctets' => $in, 'acctoutputoctets' => $out,
            'framedipaddress' => '10.40.0.' . random_int(2, 250)]);
    }

    private function radacctChecksum(): string
    {
        return md5(json_encode(static::$db->rows('SELECT * FROM radacct ORDER BY radacctid')));
    }

    private function user(): string
    {
        return 'v1q' . bin2hex(random_bytes(4));
    }

    public function testConfirmationNeedsNasEvidence(): void
    {
        // ACK + the Accounting-Stop recorded right after the send -> CONFIRMED
        $u = $this->user();
        $id = $this->session($u, 600, 1, 1);
        $stub = $this->stub('ack');
        RadiusFaults::$hook = static function (string $p) use ($id): void {
            if ($p === 'session.after_disconnect') {
                static::$db->exec("UPDATE radacct SET acctstoptime = UTC_TIMESTAMP(), acctterminatecause = 'Admin-Reset' WHERE radacctid = {$id}");
            }
        };
        $r = SessionTermination::end(static::$db->rows('SELECT * FROM radacct WHERE radacctid = ?', [$id]));
        $this->assertSame([['CONFIRMED', true]], array_map(static fn($e) => [$e['state'], $e['confirmed']], $r));
        $this->assertCount(1, $stub->received());
        RadiusFaults::$hook = null;

        // ACK without a Stop within the bound -> not confirmed
        $u2 = $this->user();
        $id2 = $this->session($u2, 600, 1, 1);
        static::$db->exec('DELETE FROM nas');
        $ack = $this->stub('ack');   // keep the reference: the stub process ends with its object
        $r = SessionTermination::end(static::$db->rows('SELECT * FROM radacct WHERE radacctid = ?', [$id2]), null, null, 1.0);
        $this->assertSame([['ACK_WITHOUT_STOP', false]], array_map(static fn($e) => [$e['state'], $e['confirmed']], $r), 'an ACK alone is not proof');

        // NAK 503 (no such session) -> CONFIRMED; a silent NAS -> not confirmed (3 identical sends)
        static::$db->exec('DELETE FROM nas');
        $nak = $this->stub('nak503');
        $this->assertTrue(SessionTermination::end(static::$db->rows('SELECT * FROM radacct WHERE radacctid = ?', [$id2]))[0]['confirmed']);
        static::$db->exec('DELETE FROM nas');
        $silent = $this->stub('silent');
        $r = SessionTermination::end(static::$db->rows('SELECT * FROM radacct WHERE radacctid = ?', [$id2]));
        $this->assertFalse($r[0]['confirmed']);
        $this->assertCount(3, $silent->received(), 'one request, two identical retransmissions, never a new request');
        // unknown NAS -> not confirmed, nothing sent
        $id3 = $this->session($this->user(), 600, 1, 1, 60, false, '192.0.2.77');
        $this->assertFalse(SessionTermination::end(static::$db->rows('SELECT * FROM radacct WHERE radacctid = ?', [$id3]))[0]['confirmed']);
    }

    /**
     * N1: a Disconnect that got no answer / no Stop is retried after QuotaEnforcer::RESEND_AFTER_NO_ANSWER (a transient loss must
     * not leave an over-quota session running for a minute); a NAK (the NAS no longer has the session: a lost Stop) keeps the
     * long RESEND_SECONDS so a vanished session is not sent a Disconnect every pass.
     */
    public function testResendDelayDependsOnTheOutcome(): void
    {
        $u = $this->user();
        $this->quota($u, ['Max-Data' => 1_000_000, 'Nuxr-Period-Start' => time() - 3600]);
        $this->session($u, 600, 700_000, 400_000);
        $now = 1000.0;
        $state = 'COA_NO_RESPONSE';
        $sent = 0;
        $enf = new QuotaEnforcer(static::$svc, static function (array $rows) use (&$sent, &$state): array {
            $sent++;
            return array_map(static fn($r) => ['radacctid' => (int) $r['radacctid'], 'state' => $state, 'confirmed' => false], $rows);
        }, static function () use (&$now): float {
            return $now;
        });
        $this->assertCount(1, $enf->pass(), 't=0: first Disconnect (no answer)');
        $now += QuotaEnforcer::RESEND_AFTER_NO_ANSWER - 1;
        $this->assertSame([], $enf->pass(), 'inside the short delay: nothing');
        $now += 2;
        $state = 'COA_NAK';
        $this->assertCount(1, $enf->pass(), 'after the short delay: retried');
        $now += QuotaEnforcer::RESEND_SECONDS - 1;
        $this->assertSame([], $enf->pass(), 'a NAK keeps the long delay');
        $now += 2;
        $this->assertCount(1, $enf->pass(), 'after the long delay: sent again');
        $this->assertSame(3, $sent);
        $this->assertLessThan(QuotaEnforcer::RESEND_SECONDS, QuotaEnforcer::RESEND_AFTER_NO_ANSWER);
    }

    /** N1: one termination call per pass for every over-quota subscriber (the Stop wait is shared, not added up per user) */
    public function testOnePassOneTerminationCallForAllSubscribers(): void
    {
        $a = $this->user();
        $b = $this->user();
        foreach ([$a, $b] as $u) {
            $this->quota($u, ['Max-Data' => 1_000_000, 'Nuxr-Period-Start' => time() - 3600]);
        }
        $ia = $this->session($a, 600, 700_000, 400_000);
        $ib = $this->session($b, 600, 900_000, 400_000);
        $calls = [];
        $enf = new QuotaEnforcer(static::$svc, static function (array $rows) use (&$calls): array {
            $calls[] = array_map(static fn($r) => (int) $r['radacctid'], $rows);
            return array_map(static fn($r) => ['radacctid' => (int) $r['radacctid'], 'state' => 'CONFIRMED', 'confirmed' => true], $rows);
        });
        $actions = $enf->pass();
        $this->assertCount(1, $calls, 'one call');
        $this->assertEqualsCanonicalizing([$ia, $ib], $calls[0], 'with every session');
        $by = [];
        foreach ($actions as $x) {
            $by[$x['username']] = array_map(static fn($e) => $e['radacctid'], $x['ended']);
        }
        $expected = [$a => [$ia], $b => [$ib]];
        ksort($by);
        ksort($expected);
        $this->assertSame($expected, $by, 'results go back to their subscriber');
    }

    /** N1: a NAS that did not answer one Disconnect is not asked again for the other sessions of the same call (a NAS outage cannot stretch a pass to minutes) */
    public function testASilentNasIsAskedOncePerCall(): void
    {
        $u = $this->user();
        $a = $this->session($u, 600, 1, 1);
        $b = $this->session($u, 500, 1, 1);
        $silent = $this->stub('silent');
        $r = SessionTermination::end(static::$db->rows('SELECT * FROM radacct WHERE radacctid IN (?, ?) ORDER BY radacctid', [$a, $b]), null, null, 1.0);
        $this->assertSame(['COA_NO_RESPONSE', 'COA_NAS_SKIPPED'], array_column($r, 'state'));
        $this->assertSame([false, false], array_column($r, 'confirmed'), 'neither is claimed ended');
        $this->assertCount(3, $silent->received(), 'one request + two identical retransmissions, for the first session only');
    }

    public function testEnforcerEndsOnlyOverQuotaSessionsOfTheCurrentPeriod(): void
    {
        $start = time() - 3600;
        $over = $this->user();
        $this->quota($over, ['Max-Data' => 1_000_000, 'Nuxr-Period-Start' => $start]);
        $old = $this->session($over, 7200, 900_000, 900_000);                 // previous period: never counted, never ended here
        $done = $this->session($over, 1800, 400_000, 200_000, 60, true);     // current period, stopped
        $open = $this->session($over, 600, 300_000, 150_000);                // current period, open: 1 050 000 >= 1 000 000

        $under = $this->user();
        $this->quota($under, ['Max-Data' => 5_000_000, 'Nuxr-Period-Start' => $start]);
        $this->session($under, 600, 1_000_000, 1_000_000);
        $free = $this->user();
        $this->session($free, 600, 9_000_000_000, 9_000_000_000);         // unlimited: no quota row

        $sent = [];
        $enf = new QuotaEnforcer(static::$svc, static function (array $rows) use (&$sent): array {
            $sent[] = array_map(static fn($r) => (int) $r['radacctid'], $rows);
            return array_map(static fn($r) => ['radacctid' => (int) $r['radacctid'], 'state' => 'CONFIRMED', 'confirmed' => true], $rows);
        });
        $before = $this->radacctChecksum();
        $report = $enf->overQuota();
        $this->assertSame([[$over, 'Max-Data', '1000000', '1050000']], array_map(static fn($o) => [$o['username'], $o['quota'], $o['limit'], $o['usage']], $report));
        $actions = $enf->pass();
        $this->assertSame([[$open]], $sent, 'only the open session of the current period; not the previous-period session, not the stopped one');
        $this->assertCount(1, $actions);
        $this->assertSame([], $enf->pass(), 'not re-sent within RESEND_SECONDS');
        $this->assertSame($before, $this->radacctChecksum(), 'the enforcer never changes accounting');
        $this->assertNotEquals(0, $old + $done);
    }

    /**
     * Full chain (ActivationService -> the stored boundary -> the enforcer): a PPPoE / Hotspot session whose NAS-stamped
     * start is 2 s before the activation's period start belongs to the new period; one that started minutes before does not.
     */
    public function testSessionStampedJustBeforeTheActivationIsEnforcedInTheNewPeriod(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        static::$db->exec("UPDATE tbl_plans SET typebp = 'Limited', limit_type = 'Data_Limit', data_limit = 1, data_unit = 'MB', time_limit = 0, time_unit = 'Hrs' WHERE id = {$plan}");
        $c = $this->customer($leaf);
        $user = $this->username($c);
        // N2: a period owns the rows that ARRIVED after it started. A session that started 2 minutes before the activation was
        // recorded before it, so it is inserted before; the first session of the new period is recorded after the activation
        // even though a lagging NAS stamps its start 2 s earlier than the period start
        $far = $this->session($user, 120, 5_000_000, 5_000_000);
        $before = time();
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $this->assertSame('1000000', (string) static::$db->value("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Max-Data'", [$user]));
        $this->assertNotNull(static::$db->value("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Nuxr-Period-Acct-Id'", [$user]), 'the period has its arrival-order boundary');
        $near = $this->session($user, time() - ($before - 2), 800_000, 400_000);      // started $before - 2: before the period start (NAS stamp)
        try {
            $report = (new QuotaEnforcer(static::$svc, static fn(array $r): array => []))->overQuota();
            $this->assertSame([[$user, '1000000', '1200000', [$near]]], array_map(static fn($o) => [$o['username'], $o['limit'], $o['usage'],
                array_map(static fn($s) => (int) $s['radacctid'], $o['sessions'])], $report), 'only the near-boundary session is in the new period');
            $this->assertNotSame($near, $far);
        } finally {
            // the enforcer reads the whole accounting table: this customer is not a 'v1q' user, remove its rows (test data only)
            static::$db->exec('DELETE FROM radacct WHERE username = ' . static::$db->db()->pdo->quote($user));
            static::$db->exec('DELETE FROM radcheck WHERE username = ' . static::$db->db()->pdo->quote($user));
        }
    }

    public function testTimeQuotaAcrossConcurrentSessions(): void
    {
        $u = $this->user();
        $this->quota($u, ['Max-All-Session' => 3600, 'Simultaneous-Use' => 2, 'Nuxr-Period-Start' => time() - 7200]);
        $a = $this->session($u, 3000, 1, 1, 2000);
        $b = $this->session($u, 2500, 1, 1, 1700);                            // 3700 s together >= 3600
        $sent = [];
        $enf = new QuotaEnforcer(static::$svc, static function (array $rows) use (&$sent): array {
            $sent = array_merge($sent, array_map(static fn($r) => (int) $r['radacctid'], $rows));
            return [];
        });
        $enf->pass();
        $this->assertSame([$a, $b], $sent, 'both concurrent sessions of the exhausted time quota');
    }

    public function testMalformedQuotaRowsAreSkippedNotEnforced(): void
    {
        $u = $this->user();
        $this->quota($u, ['Max-Data' => 'abc', 'Nuxr-Period-Start' => time() - 100]);
        $this->session($u, 50, 9_000_000, 9_000_000);
        $this->assertSame([], (new QuotaEnforcer(static::$svc, static fn(array $r): array => []))->overQuota(), 'FreeRADIUS refuses such a login (fail closed); nothing to measure');
    }
}
