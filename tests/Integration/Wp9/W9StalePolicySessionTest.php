<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationActor;
use Reseller\Activation\ActivationService;
use Reseller\Activation\DriftReport;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * Architecture Lock gate P1 (CHANGE_PLAN / REACTIVATE after a Disconnect UNKNOWN; WP8 S6 drill 4 observed the old
 * session keeping the previous policy). Targeted mitigation (lowest risk, no automatic retry): the drift report names
 * the plan change whose running session kept the previous policy (SESSION_POLICY_STALE) while that session is open,
 * the operator re-sends the Disconnect (audited annotation), and the finding clears when the old session ends.
 * Positive control: a plan change without an open old session is never reported.
 */
final class W9StalePolicySessionTest extends ActivationTestCase
{
    private function stale(int $customer): array
    {
        return array_values(array_filter(DriftReport::collect(true), static fn(array $f): bool => $f['kind'] === 'SESSION_POLICY_STALE' && $f['customer_id'] === $customer));
    }

    public function testReactivationWhoseDisconnectFailedIsReportedUntilTheOldSessionEnds(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $new = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $new['status']);
        $user = $this->username($c);
        // a NAS that never answers CoA (closed UDP port on the loopback) and an open session that started before the change
        static::$db->exec("DELETE FROM nas WHERE nasname = '127.0.0.1'");
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'w9-silent', 'type' => 'other', 'ports' => 9, 'secret' => bin2hex(random_bytes(8)), 'description' => 'w9']);
        static::$db->insertRow('radacct', ['acctsessionid' => 'w9old', 'acctuniqueid' => substr(md5($user . 'w9old'), 0, 32), 'username' => $user, 'nasipaddress' => '127.0.0.1',
            'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $this->assertSame([], $this->stale($c), 'positive control: a NEW activation is never reported');
        static::$db->exec("UPDATE tbl_user_recharges SET expiration = '2020-01-01', time = '00:00:00' WHERE customer_id = {$c}");
        $re = $this->activate($leaf, $c, $plan, 'REACTIVATE');
        $this->assertSame('COMPLETED', $re['status']);
        $found = $this->stale($c);
        $this->assertCount(1, $found, 'the session that kept the previous policy is reported');
        $this->assertSame($re['reference'], $found[0]['activation']);
        // operator re-send (still no answer): annotated, still reported
        $summary = ActivationService::endStaleSessions($re['reference'], ActivationActor::system('w9-test'));
        $this->assertNotSame([], $summary);
        $this->assertCount(1, $this->stale($c));
        // the old session ends (the NAS Stop / Session-Timeout of the previous period): the finding clears
        static::$db->exec("UPDATE radacct SET acctstoptime = UTC_TIMESTAMP() WHERE acctsessionid = 'w9old'");
        $this->assertSame([], $this->stale($c));
        $this->expectExceptionReason('ACTIVATION_STATE_CONFLICT', fn() => ActivationService::endStaleSessions($new['reference'], ActivationActor::system('w9')), 'only a plan change / reactivation');
    }
}
