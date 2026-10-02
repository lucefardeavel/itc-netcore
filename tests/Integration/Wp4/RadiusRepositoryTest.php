<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusFaults;
use Reseller\Radius\RadiusPolicy;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Tests\Support\RadiusTestCase;

/**
 * RadiusRepository / RadiusProvisioner on the FreeRADIUS schema: exact, idempotent policies; only managed
 * attributes touched; rename across all three tables; accounting history never touched; SUCCESS / FAILED /
 * UNKNOWN (failure injection). The FreeRADIUS schema is in the application database (installer layout);
 * RadiusRepositorySeparateDbTest repeats everything with a separate RADIUS database.
 */
class RadiusRepositoryTest extends RadiusTestCase
{
    private function provisioner(): RadiusProvisioner
    {
        return new RadiusProvisioner($this->repo());
    }

    private static function policy(string $user, array $plan = [], ?array $bw = null, ?string $expiry = '+1 day', array $customer = []): RadiusPolicy
    {
        return RadiusPolicyBuilder::build($customer + ['username' => $user, 'password' => 'pw-' . $user], $plan + ['id' => 11, 'type' => 'Hotspot', 'shared_users' => 2],
            $bw ?? ['rate_up' => '1', 'rate_up_unit' => 'Mbps', 'rate_down' => '5', 'rate_down_unit' => 'Mbps', 'burst' => ''],
            $expiry === null ? null : new \DateTimeImmutable($expiry));
    }

    public function testApplyIsExactAndIdempotentAndLeavesUnmanagedRowsAlone(): void
    {
        $u = 'idem-' . bin2hex(random_bytes(3));
        // pre-WP4 legacy rows: reply attributes in radcheck, duplicate rows, an operator's own attribute
        foreach ([['radcheck', 'Mikrotik-Rate-Limit', '9M/9M'], ['radcheck', 'Port-Limit', '2'], ['radcheck', 'Simultaneous-Use', '9'], ['radcheck', 'Simultaneous-Use', '8'],
            ['radcheck', 'Calling-Station-Id', 'aa:bb'], ['radreply', 'Framed-IP-Netmask', '255.255.255.0'], ['radreply', 'Reply-Message', 'operator note']] as [$t, $a, $v]) {
            static::$radius->prepare("INSERT INTO {$t} (username, attribute, op, value) VALUES (?, ?, ':=', ?)")->execute([$u, $a, $v]);
        }
        static::$radius->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, 'plan_1', 1), (?, 'plan_2', 2)")->execute([$u, $u]);
        $acct = static::acct($u, 's1', null);
        $acctBefore = static::checksum('radacct');

        $p = self::policy($u);
        $r = $this->provisioner()->provision($p);
        $this->assertSame([ProvisionResult::SUCCESS, true], [$r->status, $r->metadata['changed']]);
        $state = $this->repo()->readUser($u);
        $this->assertEquals(['Calling-Station-Id' => 'aa:bb'] + $p->checkMap(), $state['check'], 'managed check items exact; the operator attribute kept');
        $this->assertEquals($p->replyMap() + ['Reply-Message' => 'operator note'], $state['reply'], 'legacy Framed-IP-Netmask removed, unmanaged reply kept');
        $this->assertSame(['plan_11'], $state['groups'], 'one group');
        $this->assertSame(1, count(static::rows("SELECT id FROM radcheck WHERE username = ? AND attribute = 'Simultaneous-Use'", [$u])), 'duplicates collapsed');

        $ids = static::rows('SELECT id FROM radcheck WHERE username = ? ORDER BY id', [$u]);
        $again = $this->provisioner()->provision($p);
        $this->assertSame([ProvisionResult::SUCCESS, false], [$again->status, $again->metadata['changed']], 'idempotent: nothing changed');
        $this->assertSame($ids, static::rows('SELECT id FROM radcheck WHERE username = ? ORDER BY id', [$u]), 'no row rewritten');
        $this->assertSame($acctBefore, static::checksum('radacct'), 'accounting history untouched');
        $this->assertSame(1, count(static::rows('SELECT radacctid FROM radacct WHERE radacctid = ?', [$acct])));
    }

    public function testPlanChangeReplacesThePolicyCompletely(): void
    {
        $u = 'chg-' . bin2hex(random_bytes(3));
        $customer = ['pppoe_username' => '', 'pppoe_password' => 'ppp-' . $u, 'pppoe_ip' => '10.9.9.9'];
        $this->assertTrue($this->provisioner()->provision(self::policy($u, ['id' => 21, 'type' => 'PPPOE', 'pool' => 'p1'], null, '+1 day', $customer))->isSuccess());
        $this->assertSame('10.9.9.9', $this->repo()->readUser($u)['reply']['Framed-IP-Address']);
        $hs = self::policy($u, ['id' => 22, 'type' => 'Hotspot', 'typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => 2, 'data_unit' => 'GB']);
        $this->assertTrue($this->provisioner()->provision($hs)->isSuccess());
        $state = $this->repo()->readUser($u);
        $this->assertArrayNotHasKey('Framed-IP-Address', $state['reply'], 'no stale PPPoE address after a switch to Hotspot');
        $this->assertSame('2000000000', $state['check']['Max-Data']);
        $this->assertSame(['plan_22'], $state['groups']);
        $this->assertTrue($this->provisioner()->provision(self::policy($u, ['id' => 23], null, null))->isSuccess());
        $state = $this->repo()->readUser($u);
        $this->assertArrayNotHasKey('Expiration', $state['check'], 'no end date: Expiration removed');
        $this->assertArrayNotHasKey('Max-Data', $state['check'], 'quota removed');
    }

    public function testRenameMovesAllThreeTablesAndKeepsHistory(): void
    {
        $from = 'ren-' . bin2hex(random_bytes(3));
        $to = $from . '-new';
        $this->assertTrue($this->provisioner()->provision(self::policy($from, ['id' => 31, 'type' => 'PPPOE', 'pool' => 'p'], null, '+1 day', ['pppoe_ip' => '10.1.1.1']))->isSuccess());
        static::$radius->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Simultaneous-Use', ':=', '5')")->execute([$to]);
        static::acct($from, 'r1', gmdate('Y-m-d H:i:s'));
        $acct = static::checksum('radacct');
        $r = $this->provisioner()->rename($from, $to);
        $this->assertTrue($r->isSuccess());
        $this->assertSame(['check' => [], 'reply' => [], 'groups' => []], $this->repo()->readUser($from));
        $moved = $this->repo()->readUser($to);
        $this->assertSame('10.1.1.1', $moved['reply']['Framed-IP-Address'], 'radreply renamed (the legacy device skipped it)');
        $this->assertSame('1', $moved['check']['Simultaneous-Use'], 'the target name\'s old rows were replaced, not merged');
        $this->assertSame(['plan_31'], $moved['groups']);
        $this->assertSame($acct, static::checksum('radacct'), 'accounting history is historical: not renamed, not deleted');
    }

    public function testSuspendAndRemoveNeverTouchAccounting(): void
    {
        $u = 'sus-' . bin2hex(random_bytes(3));
        $this->assertTrue($this->provisioner()->provision(self::policy($u))->isSuccess());
        static::acct($u, 'x1', null);
        static::acct($u, 'x2', gmdate('Y-m-d H:i:s'));
        $acct = static::checksum('radacct');
        $before = $this->repo()->readUser($u);
        $this->assertTrue($this->provisioner()->suspend($u)->isSuccess());
        $after = $this->repo()->readUser($u);
        $this->assertNotSame($before['check']['Cleartext-Password'], $after['check']['Cleartext-Password']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $after['check']['Cleartext-Password'], 'random, not derived from secrets');
        $this->assertSame(array_diff_key($before['check'], ['Cleartext-Password' => 1]), array_diff_key($after['check'], ['Cleartext-Password' => 1]), 'only the password changes');
        $this->assertTrue($this->provisioner()->remove($u)->isSuccess());
        $this->assertSame(['check' => [], 'reply' => [], 'groups' => []], $this->repo()->readUser($u));
        $this->assertSame($acct, static::checksum('radacct'));
    }

    public function testPlanGroupRows(): void
    {
        $bw = ['rate_up' => '3', 'rate_up_unit' => 'Mbps', 'rate_down' => '6', 'rate_down_unit' => 'Mbps', 'burst' => ''];
        $planId = random_int(100000, 999999);
        static::$radius->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES ('member', ?, 1)")->execute(['plan_' . $planId]);
        $this->assertTrue($this->provisioner()->applyPlan($planId, $bw)->isSuccess());
        $rows = static::rows('SELECT attribute, op, value FROM radgroupreply WHERE groupname = ? ORDER BY attribute', ['plan_' . $planId]);
        $this->assertSame([['attribute' => 'Ascend-Data-Rate', 'op' => ':=', 'value' => '6000000'], ['attribute' => 'Ascend-Xmit-Rate', 'op' => ':=', 'value' => '3000000'],
            ['attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '3M/6M']], $rows);
        $this->assertSame($rows, static::rows('SELECT attribute, op, value FROM radgroupreply WHERE groupname = ? ORDER BY attribute', ['plan_' . $planId]));
        $this->assertFalse($this->provisioner()->applyPlan($planId, $bw)->metadata['changed'], 'idempotent');
        $this->assertTrue($this->provisioner()->removePlan($planId)->isSuccess());
        $this->assertSame([], static::rows('SELECT id FROM radgroupreply WHERE groupname = ?', ['plan_' . $planId]));
        $this->assertSame('', static::rows("SELECT groupname FROM radusergroup WHERE username = 'member'")[0]['groupname'], 'legacy: members keep an empty group');
    }

    /** A write that fails before COMMIT is rolled back: FAILED, nothing changed. */
    public function testFailureBeforeCommitIsFailedAndRolledBack(): void
    {
        $u = 'fail-' . bin2hex(random_bytes(3));
        $this->assertTrue($this->provisioner()->provision(self::policy($u))->isSuccess());
        $before = [static::checksum('radcheck'), static::checksum('radreply'), static::checksum('radusergroup'), static::checksum('radacct')];
        RadiusFaults::$hook = static function (string $point): void {
            if ($point === 'radius.after_write') {
                throw new \RuntimeException('injected');
            }
        };
        foreach ([fn() => $this->provisioner()->provision(self::policy($u, ['id' => 99])), fn() => $this->provisioner()->suspend($u),
            fn() => $this->provisioner()->remove($u), fn() => $this->provisioner()->rename($u, $u . 'z'), fn() => $this->provisioner()->removePlan(11)] as $op) {
            $r = $op();
            $this->assertSame([ProvisionResult::FAILED, 'RADIUS_ERROR', false], [$r->status, $r->code, $r->retryable]);
            $this->assertSame($before, [static::checksum('radcheck'), static::checksum('radreply'), static::checksum('radusergroup'), static::checksum('radacct')], 'rolled back');
        }
        RadiusFaults::$hook = static function (string $point): void {
            if ($point === 'radius.after_write') {
                $e = new \PDOException('Deadlock found');
                $e->errorInfo = ['40001', 1213, 'Deadlock found'];
                throw $e;
            }
        };
        $r = $this->provisioner()->provision(self::policy($u, ['id' => 98]));
        $this->assertSame([ProvisionResult::FAILED, 'RADIUS_DB_ERROR', true], [$r->status, $r->code, $r->retryable], 'transient: safe to repeat (rolled back)');
    }

    /** A lost COMMIT acknowledgement is UNKNOWN (never FAILED, never retryable). */
    public function testLostCommitIsUnknown(): void
    {
        $u = 'unk-' . bin2hex(random_bytes(3));
        RadiusFaults::$hook = static function (string $point): void {
            if ($point === 'radius.commit_lost') {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $r = $this->provisioner()->provision(self::policy($u));
        $this->assertSame([ProvisionResult::UNKNOWN, 'RADIUS_COMMIT_UNKNOWN', false], [$r->status, $r->code, $r->retryable]);
        RadiusFaults::$hook = null;
        $this->assertNotSame([], $this->repo()->readUser($u)['check'], 'the change may well have been applied: hence UNKNOWN, not FAILED');
    }

    /** A committed change that reads back differently is UNKNOWN. */
    public function testVerifyMismatchIsUnknown(): void
    {
        $u = 'ver-' . bin2hex(random_bytes(3));
        $pdo = static::$radius;
        RadiusFaults::$hook = static function (string $point) use ($pdo, $u): void {
            if ($point === 'radius.commit_lost') {
                $pdo->prepare("UPDATE radcheck SET value = 'concurrent' WHERE username = ? AND attribute = 'Simultaneous-Use'")->execute([$u]);
            }
        };
        $r = $this->provisioner()->provision(self::policy($u));
        $this->assertSame([ProvisionResult::UNKNOWN, 'RADIUS_VERIFY_MISMATCH'], [$r->status, $r->code]);
    }
}
