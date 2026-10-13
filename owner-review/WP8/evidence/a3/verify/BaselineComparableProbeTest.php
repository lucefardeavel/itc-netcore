<?php
namespace Reseller\Tests\Integration\Wp5b;

// Verification probe only (WP8 A3 final pre-acceptance); outside the repository, mounted read-only. Runs unchanged on
// the baseline (HEAD product files) and on A3, and only RECORDS what happens (no outcome assertion), so the two runs
// can be compared: pre-existing or introduced by A3.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\DriftReport;
use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Radius\RadiusFaults;
use Reseller\Tests\Support\ActivationTestCase;

final class BaselineComparableProbeTest extends ActivationTestCase
{
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
        RadiusFaults::$hook = null;
        parent::tearDown();
    }

    private function outcome(callable $f): string
    {
        try {
            return (string) $f()['status'];
        } catch (DomainError $e) {
            return 'DomainError:' . $e->reason;
        }
    }

    private function hasUnconfirmed(int $c): bool
    {
        foreach (DriftReport::collect(false) as $f) {
            if ($f['kind'] === 'REPROVISION_UNCONFIRMED' && (int) $f['customer_id'] === $c) {
                return true;
            }
        }
        return false;
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

    // context for F1: does a later FAILED_REVERTED (pre-existing outcome) also hide an earlier REPROVISION_UNCONFIRMED?
    public function testFailedRevertedAfterUnconfirmed(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.commit_lost') {
                throw new \PDOException('MySQL server has gone away');
            }
        };
        $first = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'pb1' . bin2hex(random_bytes(4)), 'password' => '']));
        $before = $this->hasUnconfirmed($c);
        RadiusFaults::$hook = static function (string $p): void {
            if ($p === 'radius.after_write') {
                throw new \RuntimeException('injected: write rejected');
            }
        };
        $second = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => 'pb2' . bin2hex(random_bytes(4)), 'password' => '']));
        RadiusFaults::$hook = null;
        $after = $this->hasUnconfirmed($c);
        fwrite(STDERR, 'PROBE ' . json_encode(['case' => 'F1-context', 'first' => $first, 'unconfirmed_listed_before' => $before, 'second' => $second, 'unconfirmed_listed_after' => $after]) . "\n");
        $this->assertTrue(true);
        $this->customersChanged();
    }

    // F5 side effect: username = PPPoE username, both changed in one edit (PPPoE plan): which keys hold a policy after?
    public function testSameSourceTwoTargetsKeys(): void
    {
        $a = 'pb5' . bin2hex(random_bytes(4));
        [, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf, null, ['username' => $a, 'pppoe_username' => $a]);
        $this->recharge($c, $this->radiusPlan('20', 'PPPOE'), '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $sync = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::ADMIN_SYNC));
        $b = 'pb5b' . bin2hex(random_bytes(4));
        $p = 'pb5c' . bin2hex(random_bytes(4));
        $r = $this->outcome(fn() => R::run($this->ctx('ad'), $c, R::IDENTITY_EDIT, ['username' => $b, 'password' => '', 'pppoe_username' => $p]));
        fwrite(STDERR, 'PROBE ' . json_encode(['case' => 'F5-keys', 'sync' => $sync, 'rename' => $r, 'rows_old' => $this->exactCount($a),
            'rows_new_username' => $this->exactCount($b), 'rows_new_pppoe' => $this->exactCount($p)]) . "\n");
        $this->assertTrue(true);
        $this->customersChanged();
    }

    // I1: the PPPoE username is not checked for uniqueness; renaming it to ANOTHER customer's exact username
    public function testPppoeRenameOntoAnotherCustomersExactKey(): void
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $x = $this->customer($leaf, null);
        $this->assertSame('COMPLETED', $this->activate($leaf, $x, $plan, 'NEW')['status']);
        $xName = (string) static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$x]);
        $yp = 'pi1' . bin2hex(random_bytes(4));
        $y = $this->customer($leaf, null, ['pppoe_username' => $yp]);
        $this->recharge($y, $this->radiusPlan('20', 'PPPOE'), '2031-01-01', '10:00:00', 'on', 'PPPOE');
        $sync = $this->outcome(fn() => R::run($this->ctx('ad'), $y, R::ADMIN_SYNC));
        $before = $this->exactCount($xName);
        $yUser = (string) static::$db->value('SELECT username FROM tbl_customers WHERE id = ?', [$y]);
        $r = $this->outcome(fn() => R::run($this->ctx('ad'), $y, R::IDENTITY_EDIT, ['username' => $yUser, 'password' => '', 'pppoe_username' => $xName]));
        $xPassword = static::$db->rows("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'", [$xName]);
        fwrite(STDERR, 'PROBE ' . json_encode(['case' => 'I1-exact-target', 'sync' => $sync, 'rename' => $r, 'x_rows_before' => $before,
            'x_rows_after' => $this->exactCount($xName), 'x_key_password_is_x' => array_column($xPassword, 'value') === ['pw-' . $xName]]) . "\n");
        $this->assertTrue(true);
        $this->customersChanged();
    }
}
