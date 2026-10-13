<?php
namespace Reseller\Tests\Integration\Wp5b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\DriftReport;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Radius\ProvisionFailure;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\StubNas;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP8 A3 final remediation (owner decisions D-1 / D-3 of 2026-10-13), regression tests for the verified review findings:
 *  - F1 a refusal never hides an earlier unresolved REPROVISION_UNCONFIRMED in the drift report;
 *  - F3 chained pairs of one edit (case-only username change + a new PPPoE username) are not "ambiguous";
 *  - F4 a pair that does not change the key is not a rename (no UNCONFIRMED old session, no drift);
 *  - F5 one Disconnect per session when two pairs share the old key;
 *  - F7 an unreadable RADIUS database is reported as such (still refused before any write);
 *  - F8 an old identity that cannot be targeted is logged (state only, no name);
 *  - D-3 a rename never replaces another subscriber's policy: PPPoE-username collisions and foreign / orphan RADIUS
 *    policies under the new name are refused before any write, and the write transaction re-checks (race closed).
 * (F6 is covered by RenameOldSessionUnitTest.)
 */
final class RenameRemediationTest extends ActivationTestCase
{
    private const SECRET = 'a3-remediation-secret';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = dirname(__DIR__, 3);
        $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
        foreach (['Package', 'Text'] as $c) {
            require_once $root . "/system/autoload/{$c}.php";
        }
        require_once $root . '/system/devices/Radius.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::$db->exec('DELETE FROM nas');
    }

    protected function tearDown(): void
    {
        ActivationFaults::$hook = null;
        RadiusFaults::$hook = null;
        R::renameScope(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures and readers

    /** a reseller-owned customer with an active Hotspot plan (username key), provisioned */
    private function hotspotCustomer(array $extra = []): int
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, $extra);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        return $c;
    }

    /** a reseller-owned customer with an active PPPoE plan (optionally also a Hotspot plan), provisioned by ADMIN_SYNC */
    private function pppoeCustomer(array $extra, bool $hotspotToo = false): int
    {
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, $extra);
        if ($hotspotToo) {
            $this->recharge($c, $this->radiusPlan('20', 'Hotspot'), '2031-01-01', '10:00:00');
        }
        $this->recharge($c, $this->radiusPlan('20', 'PPPOE'), '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        return $c;
    }

    /** @return list<string> the policy rows of one EXACT key ("table|attribute|op|value"), sorted */
    private function exact(string $key): array
    {
        $out = [];
        foreach (['radcheck', 'radreply'] as $t) {
            foreach (static::$db->rows("SELECT HEX(username) AS h, attribute, op, value FROM {$t}") as $r) {
                if (strtolower($r['h']) === bin2hex($key)) {
                    $out[] = "{$t}|{$r['attribute']}|{$r['op']}|{$r['value']}";
                }
            }
        }
        foreach (static::$db->rows('SELECT HEX(username) AS h, groupname, priority FROM radusergroup') as $r) {
            if (strtolower($r['h']) === bin2hex($key)) {
                $out[] = "radusergroup|{$r['groupname']}|{$r['priority']}";
            }
        }
        sort($out);
        return $out;
    }

    /** every RADIUS policy row (ids included), and every accounting row */
    private function radiusChecksum(): string
    {
        $rows = [];
        foreach (['radcheck', 'radreply', 'radusergroup', 'radacct'] as $t) {
            foreach (static::$db->rows("SELECT * FROM {$t} ORDER BY 1") as $r) {
                $rows[] = $t . json_encode($r);
            }
        }
        return hash('sha256', implode("\n", $rows));
    }

    private function sql(string $sql, array $params = []): void
    {
        static::$db->pdo->prepare($sql)->execute($params);
    }

    private function customerRow(int $c): array
    {
        return static::$db->rows('SELECT username, password, pppoe_username, pppoe_password, pppoe_ip, status FROM tbl_customers WHERE id = ?', [$c])[0];
    }

    private function userOf(int $c): string
    {
        return (string) static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$c]);
    }

    private function open(string $username, string $session, string $nas = '127.0.0.1'): int
    {
        return static::$db->insertRow('radacct', [
            'acctsessionid' => $session, 'acctuniqueid' => substr(md5($username . $session . microtime()), 0, 32), 'username' => $username,
            'nasipaddress' => $nas, 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'framedipaddress' => '10.34.0.' . random_int(2, 250),
        ]);
    }

    private function stub(string $mode): StubNas
    {
        $stub = new StubNas(self::SECRET, $mode);
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'a3rem', 'type' => 'other', 'ports' => $stub->port,
            'secret' => self::SECRET, 'description' => 'A3 remediation test NAS']);
        return $stub;
    }

    /** @return list<string> the drift kinds of one customer ("KIND" or "KIND:state") */
    private function drift(int $c): array
    {
        return array_values(array_map(static fn(array $f): string => $f['kind'] . (isset($f['state']) ? ':' . $f['state'] : ''),
            array_filter(DriftReport::collect(false), static fn(array $f): bool => (int) ($f['customer_id'] ?? 0) === $c)));
    }

    private function lastOutcome(int $c): ?string
    {
        $rows = static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'reprovision.identity_edit' AND target_customer_id = ? ORDER BY id DESC LIMIT 1", [$c]);
        return $rows === [] ? null : (json_decode((string) $rows[0]['after_json'], true)['outcome'] ?? null);
    }

    private function confirmationCount(int $c): int
    {
        return (int) static::$db->value("SELECT COUNT(*) FROM tbl_reseller_audit_events WHERE event_type = 'rename.old_session' AND target_customer_id = ?", [$c]);
    }

    /** a customer whose last re-provision ended REPROVISION_UNCONFIRMED (uncertain RADIUS commit of a rename) */
    private function unconfirmed(): int
    {
        $c = $this->hotspotCustomer();
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.commit_lost') {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $this->expectExceptionReason(R::UNCONFIRMED, fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'rq' . bin2hex(random_bytes(4)), 'password' => '']));
        RadiusFaults::$hook = null;
        $this->customersChanged();
        $this->assertContains('REPROVISION_UNCONFIRMED', $this->drift($c));
        return $c;
    }

    /** captures the server-side log lines written while $fn runs */
    private function logged(callable $fn): string
    {
        $file = tempnam(sys_get_temp_dir(), 'a3log');
        $prev = ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', (string) $prev);
        }
        $out = (string) file_get_contents($file);
        @unlink($file);
        return $out;
    }

    // ------------------------------------------------------------------ F1

    public function testF1ARefusalNeverHidesAnEarlierUnconfirmed(): void
    {
        $c = $this->unconfirmed();
        // AMBIGUOUS: an orphan key that the collation treats as equal to the new name
        $new = 'rr' . bin2hex(random_bytes(4));
        static::$db->insertRow('radcheck', ['username' => strtoupper($new), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'foreign']);
        $this->expectExceptionReason('RENAME_AMBIGUOUS_IDENTITY', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']));
        $this->assertSame('REFUSED_AMBIGUOUS_IDENTITY', $this->lastOutcome($c));
        $this->assertContains('REPROVISION_UNCONFIRMED', $this->drift($c), 'still reported after the refusal');
        // IDENTITY_TAKEN: an orphan policy under the exact new name
        $taken = 'rt' . bin2hex(random_bytes(4));
        static::$db->insertRow('radcheck', ['username' => $taken, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'foreign']);
        $this->expectExceptionReason('RENAME_IDENTITY_TAKEN', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $taken, 'password' => '']));
        $this->assertSame('REFUSED_IDENTITY_TAKEN', $this->lastOutcome($c));
        $this->assertContains('REPROVISION_UNCONFIRMED', $this->drift($c), 'still reported after the second refusal');
        static::$db->exec("DELETE FROM radcheck WHERE value = 'foreign'");
    }

    // ------------------------------------------------------------------ F3

    public function testF3ChainedPairsOfOneEditAreNotAmbiguous(): void
    {
        $u = 'rc' . bin2hex(random_bytes(4));
        // a PPPoE plan keyed on the username (no PPPoE username yet). (A second Radius plan on the SAME key cannot
        // verify even in an ADMIN_SYNC: the documented pre-existing residual B-2, so it is not part of this fixture.)
        $c = $this->pppoeCustomer(['username' => $u, 'pppoe_username' => '']);
        $policy = $this->exact($u);
        $this->assertNotSame([], $policy);
        $p = 'rcp' . bin2hex(random_bytes(4));
        // one edit: username abc -> ABC (case only) and a new PPPoE username: pairs [abc -> ABC], [ABC -> p]
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtoupper($u), 'password' => '', 'pppoe_username' => $p]);
        $this->customersChanged();
        $this->assertSame('DONE', $r['status'], 'the edit is applied (the review found a false RENAME_AMBIGUOUS_IDENTITY here)');
        $this->assertSame([[], []], [$this->exact($u), $this->exact(strtoupper($u))], 'nothing under the old key or the intermediate key');
        $pppoe = $this->exact($p);
        $this->assertSame($policy, $pppoe, 'the same policy, now under the PPPoE key');
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        $this->assertSame($pppoe, $this->exact($p), 'equal to a fresh sync');
    }

    public function testF3AForeignEquivalentKeyIsStillRefusedInAChainedEdit(): void
    {
        $u = 'rd' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => $u, 'pppoe_username' => '']);
        $p = 'rdp' . bin2hex(random_bytes(4));
        static::$db->insertRow('radcheck', ['username' => strtoupper($p), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'foreign']);
        $before = [$this->customerRow($c), $this->radiusChecksum()];
        $this->expectExceptionReason('RENAME_AMBIGUOUS_IDENTITY', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtoupper($u), 'password' => '', 'pppoe_username' => $p]));
        $this->assertSame($before, [$this->customerRow($c), $this->radiusChecksum()], 'nothing written');
        static::$db->exec("DELETE FROM radcheck WHERE value = 'foreign'");
    }

    // ------------------------------------------------------------------ F4

    public function testF4APairThatKeepsTheKeyIsNotARename(): void
    {
        $u = 're' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => $u, 'pppoe_username' => $u]);
        $policy = $this->exact($u);
        $this->assertNotSame([], $policy);
        // cleared: [old PPPoE username u -> username u] keeps the key
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $u, 'password' => '', 'pppoe_username' => '']);
        $this->customersChanged();
        $this->assertSame(['DONE', 'NOT_APPLICABLE'], [$r['status'], $r['old_session']]);
        $this->assertSame(0, $this->confirmationCount($c), 'no rename, no old-session confirmation');
        $this->assertSame([], array_values(array_filter($this->drift($c), static fn(string $k): bool => str_starts_with($k, 'OLD_SESSION'))));
        $this->assertSame($policy, $this->exact($u), 'the plan is still re-provisioned under its key');
        // set: [username u -> PPPoE username u] keeps the key as well (its own name: not "taken")
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $u, 'password' => '', 'pppoe_username' => $u]);
        $this->customersChanged();
        $this->assertSame(['DONE', 'NOT_APPLICABLE'], [$r['status'], $r['old_session']]);
        $this->assertSame($u, $this->customerRow($c)['pppoe_username']);
        $this->assertSame($policy, $this->exact($u));
    }

    // ------------------------------------------------------------------ F5

    public function testF5OneDisconnectPerSessionWhenTwoPairsShareTheOldKey(): void
    {
        $a = 'rf' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => $a, 'pppoe_username' => $a]);
        $row = $this->open($a, 'a3rf01');
        $stub = $this->stub('ack');
        ActivationFaults::$hook = static function (string $p) use ($row): void {
            if ($p === 'reprovision.old_session_after_send') {
                static::$db->exec('UPDATE radacct SET acctstoptime = UTC_TIMESTAMP() WHERE radacctid = ' . (int) $row);
            }
        };
        $t0 = microtime(true);
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'rfb' . bin2hex(random_bytes(4)), 'password' => '', 'pppoe_username' => 'rfc' . bin2hex(random_bytes(4))]);
        $seconds = microtime(true) - $t0;
        $this->customersChanged();
        $this->assertCount(1, $stub->received(), 'one Disconnect-Request for the one session');
        $this->assertSame(['DONE', 'CONFIRMED'], [$r['status'], $r['old_session']]);
        $this->assertLessThan(4.0, $seconds, 'no second, unanswered Disconnect (the review measured 6.2 s)');
        $conf = json_decode((string) static::$db->value("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'rename.old_session' AND target_customer_id = ?", [$c]), true);
        $targeted = [];
        foreach ($conf['identities'] as $i) {
            foreach ($i['sessions'] as $s) {
                $targeted[] = (int) $s['radacctid'];
            }
        }
        $this->assertSame([$row], $targeted, 'the session is targeted once');
    }

    // ------------------------------------------------------------------ F7

    public function testF7AnUnreadableRadiusDatabaseIsReportedAsSuchAndRefused(): void
    {
        $c = $this->unconfirmed();
        $before = $this->customerRow($c);
        static::$db->exec('RENAME TABLE radcheck TO radcheck_a3off');
        try {
            $this->expectExceptionReason('RENAME_CHECK_UNAVAILABLE', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'rg' . bin2hex(random_bytes(4)), 'password' => '']));
        } finally {
            static::$db->exec('RENAME TABLE radcheck_a3off TO radcheck');
        }
        $this->assertSame('REFUSED_CHECK_UNAVAILABLE', $this->lastOutcome($c), 'not reported as an ambiguous identity');
        $this->assertSame($before, $this->customerRow($c), 'fail closed: nothing written');
        $this->assertContains('REPROVISION_UNCONFIRMED', $this->drift($c), 'and the earlier UNCONFIRMED stays visible');
    }

    // ------------------------------------------------------------------ F8 (legacy R2 device path: sent at once)

    public function testF8AnOldIdentityThatCannotBeTargetedIsLogged(): void
    {
        $plan = $this->radiusPlan('20', 'Hotspot');
        $old = 'rh' . bin2hex(random_bytes(4));
        $c = $this->customer(null, null, ['username' => $old]);
        $this->recharge($c, $plan, '2031-01-01', '10:00:00');
        $planRow = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [$plan])[0];
        (new \Radius())->add_customer(static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$c])[0], $planRow);
        // another customer still holds the old name: nothing may be targeted (AMBIGUOUS)
        $other = $this->customer(null, null, ['pppoe_username' => $old]);
        $this->open($old, 'a3rh01');
        $new = 'rhn' . bin2hex(random_bytes(4));
        $this->sql('UPDATE tbl_customers SET username = ? WHERE id = ?', [$new, $c]);
        $log = $this->logged(fn() => (new \Radius())->change_username($planRow, $old, $new));
        $this->assertStringContainsString('event=radius.old_session', $log);
        $this->assertStringContainsString('capture=AMBIGUOUS', $log);
        $this->assertStringNotContainsString($old, $log, 'no name in the log line');
        // no accounting row at all: logged as such
        $new2 = 'rhm' . bin2hex(random_bytes(4));
        $this->sql('DELETE FROM tbl_customers WHERE id = ?', [$other]);
        $this->sql('UPDATE tbl_customers SET username = ? WHERE id = ?', [$new2, $c]);
        $log = $this->logged(fn() => (new \Radius())->change_username($planRow, $new, $new2));
        $this->assertStringContainsString('capture=NO_ACCOUNTING_EVIDENCE', $log);
        static::$db->exec("DELETE FROM radacct WHERE acctsessionid = 'a3rh01'");
        $this->customersChanged();
    }

    // ------------------------------------------------------------------ D-3: other subscribers are never touched

    public function testD3APppoeUsernameHeldByAnotherCustomerIsRefusedBeforeAnyWrite(): void
    {
        $x = $this->hotspotCustomer();
        $xName = $this->userOf($x);
        $xp = 'rxp' . bin2hex(random_bytes(4));
        $this->sql('UPDATE tbl_customers SET pppoe_username = ? WHERE id = ?', [$xp, $x]);
        $this->customersChanged();
        $xSession = $this->open($xName, 'a3rx01');
        $y = $this->pppoeCustomer(['pppoe_username' => 'ryp' . bin2hex(random_bytes(4))]);
        foreach ([$xName, strtoupper($xName), $xp] as $taken) {
            $before = [$this->customerRow($x), $this->customerRow($y), $this->radiusChecksum(), $this->exact($xName)];
            $this->expectExceptionReason('PPPOE_USERNAME_TAKEN', fn() => R::run($this->ctx('ad'), $y, R::IDENTITY_EDIT, ['username' => $this->userOf($y), 'password' => '', 'pppoe_username' => $taken]));
            $this->assertSame($before, [$this->customerRow($x), $this->customerRow($y), $this->radiusChecksum(), $this->exact($xName)],
                "{$taken}: refused before any write; the other customer's row, password, policy and session are unchanged");
        }
        $this->assertNull(static::$db->value('SELECT acctstoptime FROM radacct WHERE radacctid = ?', [$xSession]), 'its session is untouched');
        $this->assertSame(0, $this->confirmationCount($y), 'no Disconnect attempted');
        $this->sql('DELETE FROM radacct WHERE radacctid = ?', [$xSession]);
    }

    public function testD3AForeignOrOrphanPolicyUnderTheNewNameIsRefusedBeforeAnyWrite(): void
    {
        $y = $this->hotspotCustomer();
        $orphan = 'ro' . bin2hex(random_bytes(4));
        foreach ([['radcheck', ['attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'orphan-pass']], ['radusergroup', ['groupname' => 'plan_orphan', 'priority' => 1]]] as [$t, $row]) {
            static::$db->insertRow($t, ['username' => $orphan] + $row);
        }
        $before = [$this->customerRow($y), $this->radiusChecksum(), $this->exact($orphan)];
        $this->expectExceptionReason('RENAME_IDENTITY_TAKEN', fn() => R::run($this->ctx('ad'), $y, R::IDENTITY_EDIT, ['username' => $orphan, 'password' => '']));
        $this->assertSame($before, [$this->customerRow($y), $this->radiusChecksum(), $this->exact($orphan)], 'nothing written, the old policy kept byte-identical');
        $this->assertSame('REFUSED_IDENTITY_TAKEN', $this->lastOutcome($y));
        $this->sql('DELETE FROM radcheck WHERE username = ?', [$orphan]);
        $this->sql('DELETE FROM radusergroup WHERE username = ?', [$orphan]);
    }

    public function testD3TheCustomersOwnOldKeyMayStillBeReplaced(): void
    {
        $u = 'rk' . bin2hex(random_bytes(4));
        $p = 'rkp' . bin2hex(random_bytes(4));
        $c = $this->hotspotCustomer(['username' => $u, 'pppoe_username' => $p]);
        // stale rows of this customer under its own (old) PPPoE username
        static::$db->insertRow('radcheck', ['username' => $p, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'stale-own']);
        $r = R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $p, 'password' => '']);
        $this->customersChanged();
        $this->assertSame('DONE', $r['status'], 'its own key: allowed (legitimate case)');
        $this->assertSame([], $this->exact($u));
        $after = $this->exact($p);
        $this->assertNotContains('radcheck|Cleartext-Password|:=|stale-own', $after);
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        $this->assertSame($after, $this->exact($p), 'equal to a fresh sync');
    }

    public function testD3RaceBetweenThePreCheckAndTheWriteIsClosedR1(): void
    {
        $x = 'rz' . bin2hex(random_bytes(4));
        $y = $this->hotspotCustomer();
        $yName = $this->userOf($y);
        $yPolicy = $this->exact($yName);
        $yRow = $this->customerRow($y);
        $other = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
        // after the pre-check passed and the identity was written: another subscriber's policy appears under the name
        ActivationFaults::$hook = static function (string $p) use ($other, $x): void {
            if ($p === 'reprovision.before_devices') {
                $other->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', 'x-pass')")->execute([$x]);
                $other->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, 'plan_x', 1)")->execute([$x]);
            }
        };
        $this->expectExceptionReason('REPROVISION_FAILED', fn() => R::run($this->ctx('ad'), $y, R::IDENTITY_EDIT, ['username' => $x, 'password' => '']));
        ActivationFaults::$hook = null;
        $this->assertSame(['radcheck|Cleartext-Password|:=|x-pass', 'radusergroup|plan_x|1'], $this->exact($x), 'the other policy is byte-identical');
        $this->assertSame($yPolicy, $this->exact($yName), 'the renamed customer keeps its own policy (rolled back)');
        $this->assertSame($yRow, $this->customerRow($y), 'and its identity is reverted');
        $this->assertSame('FAILED_REVERTED', $this->lastOutcome($y));
        $this->sql('DELETE FROM radcheck WHERE username = ?', [$x]);
        $this->sql('DELETE FROM radusergroup WHERE username = ?', [$x]);
    }

    public function testD3RaceBetweenThePreCheckAndTheWriteIsClosedR2Device(): void
    {
        // the legacy flow: pre-check, customer saved, then the device rename inside the customer's rename scope
        $plan = $this->radiusPlan('20', 'Hotspot');
        $old = 'rw' . bin2hex(random_bytes(4));
        $c = $this->customer(null, null, ['username' => $old]);
        $this->recharge($c, $plan, '2031-01-01', '10:00:00');
        $planRow = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [$plan])[0];
        (new \Radius())->add_customer(static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$c])[0], $planRow);
        $new = 'rwn' . bin2hex(random_bytes(4));
        $billing = static::$db->rows("SELECT * FROM tbl_user_recharges WHERE customer_id = ? AND status = 'on'", [$c]);
        $this->assertSame(R::RENAME_OK, R::renameCheck($billing, ['username' => $old, 'pppoe_username' => ''], ['username' => $new, 'pppoe_username' => '']));
        static::$db->insertRow('radcheck', ['username' => $new, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'w-pass']);
        $policy = $this->exact($old);
        R::renameScope(['username' => $old, 'pppoe_username' => '']);
        try {
            (new \Radius())->change_username($planRow, $old, $new);
            $this->fail('expected the write transaction to refuse');
        } catch (ProvisionFailure $e) {
            $this->assertSame('RADIUS_IDENTITY_TAKEN', $e->result->code);
        } finally {
            R::renameScope(null);
        }
        $this->assertSame(['radcheck|Cleartext-Password|:=|w-pass'], $this->exact($new), 'the other policy is byte-identical');
        $this->assertSame($policy, $this->exact($old), 'nothing moved');
        $this->sql('DELETE FROM radcheck WHERE username = ?', [$new]);
        $this->customersChanged();
    }

    // ------------------------------------------------------------------ second review of the remediation (R-1, R-2, R-5)

    /** R-1: a second pair with the same old key is renamed once, but its target is still checked (it is written) */
    public function testR1TheTargetOfAPairRenamedOnlyOnceIsStillChecked(): void
    {
        $a = 'rs' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => $a, 'pppoe_username' => $a]);
        foreach (['exact' => ['IDENTITY_TAKEN', static fn(string $t): string => $t], 'equivalent' => ['AMBIGUOUS_IDENTITY', 'strtoupper']] as $case => [$reason, $spell]) {
            $target = 'rsc' . bin2hex(random_bytes(4));
            static::$db->insertRow('radcheck', ['username' => $spell($target), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'orphan-' . $case]);
            $before = [$this->customerRow($c), $this->radiusChecksum()];
            // pairs [a -> b], [a -> target]: the second is not renamed, but the PPPoE plan is written under the target
            $this->expectExceptionReason('RENAME_' . $reason, fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'rsb' . bin2hex(random_bytes(4)), 'password' => '', 'pppoe_username' => $target]));
            $this->assertSame($before, [$this->customerRow($c), $this->radiusChecksum()], "{$case}: refused before any write, the orphan policy byte-identical");
            $this->sql('DELETE FROM radcheck WHERE value = ?', ['orphan-' . $case]);
        }
    }

    /** R-2: the pre-check exempts only the EARLIER pairs of the edit: what the write transaction will refuse is refused first */
    public function testR2AKeyOfALaterPairIsNotExemptForAnEarlierPair(): void
    {
        $bob = 'Rb' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => 'ro' . bin2hex(random_bytes(4)), 'pppoe_username' => $bob]);
        $before = [$this->customerRow($c), $this->radiusChecksum()];
        // pairs [ou -> rb..], [Rb.. -> np]: the first target is equivalent to the customer's still-existing PPPoE key
        $this->expectExceptionReason('RENAME_AMBIGUOUS_IDENTITY', fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtolower($bob), 'password' => '', 'pppoe_username' => 'rnp' . bin2hex(random_bytes(4))]));
        $this->assertSame($before, [$this->customerRow($c), $this->radiusChecksum()], 'a clean refusal before any write (not a revert after the write)');
        $this->assertSame('REFUSED_AMBIGUOUS_IDENTITY', $this->lastOutcome($c));
    }

    /** R-5 / FIX-4: on the legacy path (sent at once) one rename scope sends one Disconnect per session */
    public function testR5OneDisconnectPerSessionInsideALegacyRenameScope(): void
    {
        $u = 'rp' . bin2hex(random_bytes(4));
        $row = $this->open($u, 'a3rp01');
        $stub = $this->stub('ack');
        $capture = ['from' => $u, 'to' => $u . 'x', 'equivalent' => false, 'state' => 'TARGETED',
            'targets' => static::$db->rows('SELECT * FROM radacct WHERE radacctid = ?', [$row])];
        R::renameScope(['username' => $u, 'pppoe_username' => '']);
        try {
            $first = R::disconnectCaptured($capture);
            $second = R::disconnectCaptured($capture);
        } finally {
            R::renameScope(null);
        }
        $this->assertCount(1, $stub->received(), 'one Disconnect-Request');
        $this->assertSame([1, 0], [count($first), count($second)]);
        $this->sql('DELETE FROM radacct WHERE radacctid = ?', [$row]);
    }
}
