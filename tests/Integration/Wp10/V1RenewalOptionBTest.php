<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationService;
use Reseller\Activation\DriftReport;
use Reseller\Finance\SettlementReadModel;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\StubNas;

/**
 * Owner decision RENEWAL_OPTION_B_APPROVED (lock gate P2 / Q1): a RENEW ends the running session of the previous
 * period and CONFIRMS its end before the new period's policy is taken up by a new session:
 *  - the session that started before the renewal gets a Disconnect; its end is confirmed by the Accounting-Stop
 *    (or NAK 503); the outcome is recorded on the activation;
 *  - an unconfirmed end (silent NAS) leaves the renewal COMPLETED (finance settled exactly once, provisioning kept) and
 *    is reported (drift SESSION_POLICY_STALE, now also for RENEW) until the operator / NAS ends the session;
 *  - a session that started after the renewal (already under the new policy) is never ended;
 *  - accounting history is kept; no extra ledger entry, recharge or grant.
 */
final class V1RenewalOptionBTest extends ActivationTestCase
{
    private const SECRET = 'v1-renewal-secret';

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

    private function stub(string $mode): StubNas
    {
        static::$db->exec('DELETE FROM nas');
        $stub = new StubNas(self::SECRET, $mode);
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'v1r', 'type' => 'other', 'ports' => $stub->port, 'secret' => self::SECRET, 'description' => 'V1 renewal stub']);
        return $stub;
    }

    private function open(string $user, int $startOffset): int
    {
        return static::$db->insertRow('radacct', ['acctsessionid' => 'v1r' . bin2hex(random_bytes(3)), 'acctuniqueid' => bin2hex(random_bytes(16)), 'username' => $user,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() + $startOffset), 'framedipaddress' => '10.41.0.' . random_int(2, 250)]);
    }

    private function annotation(string $ref): array
    {
        $rows = static::$db->rows("SELECT e.metadata FROM tbl_reseller_activation_events e JOIN tbl_reseller_activations a ON a.id = e.activation_id
            WHERE a.reference = ? AND e.event_type = 'ANNOTATION' ORDER BY e.sequence_no", [$ref]);
        return array_map(static fn($r) => json_decode((string) $r['metadata'], true), $rows);
    }

    private function stale(int $customer): array
    {
        return array_values(array_filter(DriftReport::collect(true), static fn(array $f): bool => $f['kind'] === 'SESSION_POLICY_STALE' && $f['customer_id'] === $customer));
    }

    private function ledgerCount(): int
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_ledger');
    }

    public function testRenewalEndsAndConfirmsThePreviousPeriodSession(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $before = $this->ledgerCount();
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $perActivation = $this->ledgerCount() - $before;
        $user = $this->username($c);
        $old = $this->open($user, -3600);
        $stub = $this->stub('ack');
        RadiusFaults::$hook = static function (string $p) use ($old): void {
            if ($p === 'session.after_disconnect') {   // the NAS's Accounting-Stop after the Disconnect-ACK
                static::$db->exec("UPDATE radacct SET acctstoptime = UTC_TIMESTAMP(), acctterminatecause = 'Admin-Reset' WHERE radacctid = {$old}");
            }
        };
        $ledger = $this->ledgerCount();
        $renew = $this->activate($leaf, $c, $plan, 'RENEW');
        $this->assertSame('COMPLETED', $renew['status']);
        $this->assertCount(1, $stub->received(), 'one Disconnect for the previous-period session');
        $ann = $this->annotation($renew['reference']);
        $this->assertCount(1, $ann);
        $this->assertTrue($ann[0]['confirmed']);
        $this->assertSame([[$old, 'CONFIRMED']], array_map(static fn($s) => [$s['radacctid'], $s['state']], $ann[0]['sessions']));
        $this->assertSame([], $this->stale($c));
        $this->assertSame($perActivation, $this->ledgerCount() - $ledger, 'the renewal settles exactly once (as the NEW did), nothing for the session end');
        $this->assertSame([], SettlementReadModel::integrity());
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM radacct WHERE radacctid = ?', [$old]), 'accounting history kept');
    }

    public function testUnconfirmedEndIsReportedAndTheOperatorEndsIt(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $user = $this->username($c);
        $this->open($user, -3600);
        $newer = $this->open($user, 120);   // started after the renewal: already under the new period - never ended
        $silent = $this->stub('silent');
        $renew = $this->activate($leaf, $c, $plan, 'RENEW');
        $this->assertSame('COMPLETED', $renew['status'], 'an unconfirmed end never undoes the renewal');
        $sent = $silent->received();
        $this->assertCount(3, $sent, 'one Disconnect (+2 identical retransmissions) for the previous-period session only');
        $this->assertNotContains($newer, array_column($this->annotation($renew['reference'])[0]['sessions'], 'radacctid'));
        $this->assertFalse($this->annotation($renew['reference'])[0]['confirmed']);
        $this->assertCount(1, $this->stale($c), 'RENEW with an unconfirmed end: SESSION_POLICY_STALE');
        // operator session-end, the NAS answers "no such session": confirmed, the finding clears
        $nak = $this->stub('nak503');   // keep the reference: the stub process ends with its object
        ActivationService::endStaleSessions($renew['reference'], ActivationActor::system('v1-test'));
        $annotations = $this->annotation($renew['reference']);
        $this->assertTrue(end($annotations)['confirmed'] ?? false, 'the operator end is confirmed');
        $this->assertSame([], $this->stale($c));
        $this->assertSame([], SettlementReadModel::integrity());
    }

    public function testNewActivationEndsNothing(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $this->open($this->username($c), -60);
        $stub = $this->stub('silent');
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        $this->assertSame([], $stub->received(), 'NEW: no session end');
    }
}
