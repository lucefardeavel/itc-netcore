<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\StubNas;

/**
 * Lab finding (ITC NetCore lab 2026-10-25, real RouterOS CHR 7.24.5, RealLifecycleTest renew -> change plan): after the
 * provisioning is verified, the activation ends the sessions that started "before now" - by the NAS's own Accounting
 * start stamp. A NAS clock only ~1 s AHEAD of the host (well within the NTP contract, QuotaPolicy::PERIOD_SKEW_SECONDS /
 * checklist H8) made a session that had just reconnected look newer than the commit: it got no Disconnect, stayed on the
 * old plan, and the activation still recorded the session end as confirmed.
 * The cut-off now carries the same NAS clock allowance as the period attribution: a session stamped up to
 * PERIOD_SKEW_SECONDS after the commit is ended (at worst a redundant redial onto the new policy), while a session that
 * clearly started after it (Option B: +120 s) is still never ended.
 */
final class W10SessionEndNasClockTest extends ActivationTestCase
{
    private const SECRET = 'w10-skew-secret';

    protected function setUp(): void
    {
        parent::setUp();
        RadiusFaults::$hook = null;
        static::$db->exec('DELETE FROM nas');
    }

    protected function tearDown(): void
    {
        RadiusFaults::$hook = null;
        parent::tearDown();
    }

    private function open(string $user, int $startOffset): int
    {
        return static::$db->insertRow('radacct', ['acctsessionid' => 'w10k' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $user,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() + $startOffset), 'framedipaddress' => '10.42.0.' . random_int(2, 250)]);
    }

    private function sessions(string $ref): array
    {
        $rows = static::$db->rows("SELECT e.metadata FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id
            WHERE a.reference = ? AND e.event_type = 'ANNOTATION' ORDER BY e.sequence_no", [$ref]);
        $last = json_decode((string) end($rows)['metadata'], true);
        return array_map('intval', array_column($last['sessions'] ?? [], 'radacctid'));
    }

    public function testASessionStampedSlightlyAheadByTheNasIsEndedAndAClearlyNewerOneIsNot(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $user = $this->username($c);
        $old = $this->open($user, -3600);
        $ahead = $this->open($user, 2);      // reconnected just before the commit, stamped by a NAS clock ~2 s fast
        $newer = $this->open($user, 120);    // started clearly after the renewal: already under the new policy
        $stub = new StubNas(self::SECRET, 'silent');
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'w10k', 'type' => 'other', 'ports' => $stub->port, 'secret' => self::SECRET, 'description' => 'W10 skew stub']);
        $renew = $this->activate($leaf, $c, $plan, 'RENEW');
        $this->assertSame('COMPLETED', $renew['status']);
        $ended = $this->sessions($renew['reference']);
        $this->assertContains($old, $ended, 'the previous-period session is ended');
        $this->assertContains($ahead, $ended, 'a session stamped within the NAS clock allowance after the commit is ended too');
        $this->assertNotContains($newer, $ended, 'a session clearly after the renewal is never ended (Option B)');
    }
}
