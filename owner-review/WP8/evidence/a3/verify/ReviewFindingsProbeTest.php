<?php
namespace Reseller\Tests\Integration\Wp5b;

// Verification probe only (WP8 A3 final pre-acceptance, review findings F1/F3/F4/F5); kept outside the repository,
// mounted read-only. Each test ASSERTS THE REPORTED DEFECT, so "OK" means the finding is reproduced.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\DriftReport;
use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\StubNas;

final class ReviewFindingsProbeTest extends ActivationTestCase
{
    private const SECRET = 'a3-probe-secret';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = '/repo';
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
        parent::tearDown();
    }

    private static function obs(string $case, array $data): void
    {
        fwrite(STDERR, 'PROBE ' . json_encode(['case' => $case] + $data) . "\n");
    }

    private function outcome(callable $f): array
    {
        try {
            $r = $f();
            return ['status' => $r['status'], 'old_session' => $r['old_session'] ?? null, 'reason' => null];
        } catch (DomainError $e) {
            return ['status' => 'DomainError', 'old_session' => null, 'reason' => $e->reason];
        }
    }

    private function kinds(int $c): array
    {
        return array_values(array_map(static fn(array $f): string => $f['kind'] . (isset($f['state']) ? ':' . $f['state'] : ''),
            array_filter(DriftReport::collect(false), static fn(array $f): bool => (int) ($f['customer_id'] ?? 0) === $c)));
    }

    private function exactCount(string $key): int
    {
        $n = 0;
        foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
            foreach (static::$db->rows("SELECT HEX(username) AS h FROM {$t}") as $r) {
                $n += strtolower($r['h']) === bin2hex($key) ? 1 : 0;
            }
        }
        return $n;
    }

    private function pppoeCustomer(array $extra): int
    {
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, $extra);
        $this->recharge($c, $this->radiusPlan('20', 'PPPOE'), '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $this->assertSame('DONE', R::run($this->ctx('ad'), $c, R::ADMIN_SYNC)['status']);
        return $c;
    }

    // F1: the ambiguity refusal is a reprovision.% outcome and hides an earlier unresolved REPROVISION_UNCONFIRMED
    public function testF1RefusalMasksAnEarlierUnconfirmedInTheDrift(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.commit_lost') {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $first = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'pf1a' . bin2hex(random_bytes(4)), 'password' => '']));
        RadiusFaults::$hook = null;
        $before = $this->kinds($c);
        $new = 'pf1' . bin2hex(random_bytes(4));
        static::$db->insertRow('radcheck', ['username' => strtoupper($new), 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'foreign']);
        $refusal = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $new, 'password' => '']));
        $after = $this->kinds($c);
        static::$db->exec("DELETE FROM radcheck WHERE value = 'foreign'");
        self::obs('F1', ['first' => $first, 'drift_before' => $before, 'refusal' => $refusal, 'drift_after' => $after]);
        $this->assertSame(R::UNCONFIRMED, $first['reason']);
        $this->assertContains('REPROVISION_UNCONFIRMED', $before);
        $this->assertSame('RENAME_AMBIGUOUS_IDENTITY', $refusal['reason']);
        $this->assertNotContains('REPROVISION_UNCONFIRMED', $after, 'F1 reproduced: the unresolved UNCONFIRMED is no longer reported');
        $this->customersChanged();
    }

    // F3: chained pairs (case-only username change + PPPoE username set in one edit) are refused although no other
    // identity exists; the same two changes in two edits are accepted
    public function testF3ChainedPairsAreFalselyRefused(): void
    {
        $u = 'pf3' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => $u, 'pppoe_username' => '']);
        $p = 'pf3p' . bin2hex(random_bytes(4));
        $together = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtoupper($u), 'password' => '', 'pppoe_username' => $p]));
        $one = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtoupper($u), 'password' => '', 'pppoe_username' => '']));
        $two = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => strtoupper($u), 'password' => '', 'pppoe_username' => $p]));
        self::obs('F3', ['together' => $together, 'separately' => [$one, $two]]);
        $this->assertSame('RENAME_AMBIGUOUS_IDENTITY', $together['reason'], 'F3 reproduced: false refusal');
        $this->assertSame(['DONE', 'DONE'], [$one['status'], $two['status']]);
        $this->customersChanged();
    }

    // F4: a no-op pair (PPPoE username equal to the username, then cleared) gives old_session UNCONFIRMED and drift
    public function testF4NoOpPairIsAnUnconfirmedOldSession(): void
    {
        $u = 'pf4' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => $u, 'pppoe_username' => $u]);
        $r = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $u, 'password' => '', 'pppoe_username' => '']));
        $rows = static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'rename.old_session' AND target_customer_id = ?", [$c]);
        $conf = $rows === [] ? null : json_decode((string) $rows[0]['after_json'], true);
        $drift = $this->kinds($c);
        self::obs('F4', ['result' => $r, 'confirmation' => $conf, 'drift' => $drift]);
        $this->assertSame(['DONE', 'UNCONFIRMED'], [$r['status'], $r['old_session']], 'F4 reproduced');
        $this->assertSame('rename_not_confirmed', $conf['identities'][0]['reason'] ?? null);
        $this->assertContains('OLD_SESSION_UNCONFIRMED:UNCONFIRMED', $drift);
        $this->customersChanged();
    }

    // F5: two pairs with the same source (username = PPPoE username, both changed) target the same session twice
    public function testF5SameSourceTwoTargetsDisconnectsTheSessionTwice(): void
    {
        $a = 'pf5' . bin2hex(random_bytes(4));
        $c = $this->pppoeCustomer(['username' => $a, 'pppoe_username' => $a]);
        $row = static::$db->insertRow('radacct', ['acctsessionid' => 'pf5s1', 'acctuniqueid' => substr(md5($a . microtime()), 0, 32), 'username' => $a,
            'nasipaddress' => '127.0.0.1', 'acctstarttime' => gmdate('Y-m-d H:i:s', time() - 600), 'framedipaddress' => '10.33.0.9']);
        $stub = new StubNas(self::SECRET, 'ack');
        static::$db->insertRow('nas', ['nasname' => '127.0.0.1', 'shortname' => 'a3probe', 'type' => 'other', 'ports' => $stub->port,
            'secret' => self::SECRET, 'description' => 'A3 probe NAS']);
        $b = 'pf5b' . bin2hex(random_bytes(4));
        $p = 'pf5c' . bin2hex(random_bytes(4));
        $t0 = microtime(true);
        $r = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $b, 'password' => '', 'pppoe_username' => $p]));
        $seconds = round(microtime(true) - $t0, 1);
        $got = $stub->received();
        $conf = json_decode((string) static::$db->rows("SELECT after_json FROM tbl_reseller_audit_events WHERE event_type = 'rename.old_session' AND target_customer_id = ?", [$c])[0]['after_json'], true);
        $targets = [];
        foreach ($conf['identities'] as $i) {
            foreach ($i['sessions'] as $s) {
                $targets[] = [$i['from'], $i['to'], $s['radacctid'], $s['result']];
            }
        }
        self::obs('F5', ['result' => $r, 'run_seconds' => $seconds, 'stub_received' => count($got), 'targets' => $targets,
            'rows_under_new_username' => $this->exactCount($b), 'rows_under_new_pppoe' => $this->exactCount($p)]);
        $this->assertCount(2, array_filter($targets, static fn(array $t): bool => $t[2] === $row), 'F5 reproduced: the same session targeted twice');
        static::$db->exec('DELETE FROM radacct WHERE radacctid = ' . (int) $row);
        $this->customersChanged();
    }
}
