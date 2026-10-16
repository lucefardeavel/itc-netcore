<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 final closure, owner §4: CustomerIdentity::assertSingleOwner() called inside a caller's OPEN transaction.
 *
 * Contract (owner): with the identity lock (L-3) held on the caller's connection the count may run in place (lock order
 * L-3 -> BEGIN); with an open transaction and NO lock covering the count and the write, a racy read followed by a RADIUS
 * policy write is not allowed - refused (fail closed) with nothing written, no new deadlock, no partial write, no
 * unauthorized RADIUS policy mutation. Isolation: REPEATABLE READ by default, READ COMMITTED with WP9_ISOLATION.
 *
 * The caller with an open transaction is a plugin-style writer (no application RADIUS writer runs inside a transaction:
 * the tree lock and the identity lock are both taken before BEGIN and the device steps run after COMMIT). Concurrent
 * writers: (S1) a direct-DB duplicate committed after the caller's read view was established; (S2) the application's own
 * acquirer P4 (Admin edit: a PPPoE username, checked and written under L-3 with consistent reads), which cannot see the
 * caller's uncommitted owner row (CustomerService::create is not used here: its legacy FOR UPDATE scan of tbl_customers
 * waits for every uncommitted customer row); (S3) the accepted path - the caller holds L-3, so the acquirer waits.
 * Barriers: wp2_probe files (U0 barrier plugin); 'radwrite' pauses inside the RADIUS write transaction (RadiusFaults
 * point radius.after_write: policy rows written, not committed).
 */
final class U0OpenTransactionGuardTest extends RadiusHttpTestCase
{
    use U0Fixture;

    private static string $isolation = 'REPEATABLE-READ';

    private const RADWRITE_PLUGIN = <<<'PHP'
<?php
// test-only (disposable app copy): a barrier inside the RADIUS write transaction (rows written, before COMMIT)
\Reseller\Radius\RadiusFaults::$hook = static function (string $point): void {
    if ($point === 'radius.after_write') { wp9u0_barrier('radwrite'); }
};
PHP;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
        file_put_contents(static::$app->dir . '/system/plugin/wp9u0_radwrite.php', self::RADWRITE_PLUGIN);
        if (getenv('WP9_ISOLATION') === 'READ-COMMITTED') {
            static::$db->pdo->exec('SET GLOBAL TRANSACTION ISOLATION LEVEL READ COMMITTED');
            self::$isolation = 'READ-COMMITTED';
        }
    }

    public static function tearDownAfterClass(): void
    {
        static::$db?->pdo->exec('SET GLOBAL TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        parent::tearDownAfterClass();
    }

    /** PHP: the device write of $customerExpr (a row) with the plan, as "APPLIED" / "REFUSED <code>" / "ERROR <class>" */
    private static function addPlan(string $customerExpr): string
    {
        return sprintf('$p = ORM::for_table("tbl_plans")->find_one(%d)->as_array(); try { (new Radius())->customerAddPlan(%s, $p, ""); $out = "APPLIED"; } catch (\Reseller\Radius\ProvisionFailure $e) { $out = "REFUSED " . $e->result->code; } catch (\Throwable $e) { $out = "ERROR " . get_class($e); }',
            self::$hotspot, $customerExpr);
    }

    private static function log(): string
    {
        return (string) @file_get_contents(static::$app->dir . '/wp2_probe/php_error.log');
    }

    /** RADIUS policy rows of the key (all three tables, exact bytes) */
    private static function policy(string $key): array
    {
        $out = [];
        foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
            $out[$t] = static::$db->rows("SELECT * FROM {$t} WHERE BINARY username = ? ORDER BY id", [$key]);
        }
        return $out;
    }

    private static function deadlocks(string $logBefore): int
    {
        $new = substr(self::log(), strlen($logBefore));
        return preg_match_all('/\b(1213|1205)\b|Deadlock|Lock wait timeout/i', $new);
    }

    public function testAStaleReadViewInsideAnOpenTransactionNeverAuthorizesAPolicyWrite(): void
    {
        $t = self::tag();
        $x = $this->person($t);
        $log0 = self::log();
        self::pause('txopen');
        $h = static::$app->execStart('require_once "system/devices/Radius.php"; $pdo = ORM::get_db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->beginTransaction();'
            . ' $pdo->query("SELECT COUNT(*) FROM tbl_customers")->fetchColumn(); wp9u0_barrier("txopen");'
            . sprintf(' $c = ORM::for_table("tbl_customers")->find_one(%d); ', $x['id']) . self::addPlan('$c')
            . ' $pdo->commit(); $result = $out;');
        $this->assertTrue(self::waitUntil(static fn(): bool => self::arrived('txopen'), 30), 'the caller opened its transaction and read view');
        // a duplicate owner committed by a direct-DB writer AFTER the caller's read view
        $dup = $this->person(strtoupper($t));
        self::go('txopen');
        $outcome = (string) static::$app->execFinish($h);
        $rows = self::policy($t);
        $written = $rows['radcheck'] !== [] || $rows['radreply'] !== [] || $rows['radusergroup'] !== [];
        $obs = ['isolation' => self::$isolation, 'outcome' => $outcome, 'policy_rows_written_for_ambiguous_key' => $written, 'owners_at_end' => count(self::holders($t)),
            'deadlock_or_lock_wait_lines' => self::deadlocks($log0), 'refused_in_transaction_logged' => substr_count(substr(self::log(), strlen($log0)), 'identity.owner_check_refused')];
        self::record('U0 open-transaction guard S1 stale read view + direct-DB duplicate', $written ? 'DEFECT_REPRODUCED' : 'CONTRACT_MET', $obs);
        $this->assertSame(2, $obs['owners_at_end'], 'control: the key has two owners');
        $this->assertFalse($written, 'no RADIUS policy written for a key with two owners ' . json_encode($obs));
        $this->assertStringStartsWith('REFUSED RADIUS_IDENTITY_', $outcome);
        $this->assertSame(0, $obs['deadlock_or_lock_wait_lines']);
    }

    public function testAnUncommittedOwnerInsideAnOpenTransactionCannotRaceTheAcquirer(): void
    {
        $t = self::tag();
        $log0 = self::log();
        self::pause('radwrite');
        // the plugin-style writer: inserts its owner row and writes the RADIUS policy inside ONE open transaction
        $h = static::$app->execStart('require_once "system/devices/Radius.php"; $pdo = ORM::get_db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->beginTransaction();'
            . sprintf(' $pdo->prepare("INSERT INTO tbl_customers (username, password, pppoe_username, pppoe_password, pppoe_ip, fullname, address, phonenumber, email, service_type, account_type, coordinates, status, balance, created_by, auto_renewal) VALUES (?, \'Pw-x\', \'\', \'\', \'\', \'W9 tx\', \'\', \'0700\', \'w9u0@example.invalid\', \'Hotspot\', \'Personal\', \'\', \'Active\', 0, 1, 0)")->execute([%s]);', var_export($t, true))
            . ' $c = ORM::for_table("tbl_customers")->find_one((int) $pdo->lastInsertId()); ' . self::addPlan('$c')
            . ' $pdo->commit(); $result = $out;');
        $reached = self::waitUntil(static fn(): bool => self::arrived('radwrite') || !self::running($h), 30);
        $this->assertTrue($reached);
        $atBarrier = self::arrived('radwrite');
        // the application acquirer (P4: another customer takes an equivalent name as its PPPoE username), inside the
        // caller's check -> write window when that window is open
        $y = $this->person(self::tag());
        $t0 = microtime(true);
        $http = $this->p4($y, ['pppoe_username' => strtoupper($t)]);
        $elapsed = round(microtime(true) - $t0, 1);
        $acquired = (string) self::customerRow($y['id'])['pppoe_username'] === strtoupper($t);
        self::go('radwrite');
        $outcome = (string) static::$app->execFinish($h);
        $owners = count(self::holders($t));
        $rows = self::policy($t);
        $written = $rows['radcheck'] !== [];
        $obs = ['isolation' => self::$isolation, 'writer_reached_the_radius_write' => $atBarrier, 'writer_outcome' => $outcome, 'acquirer_http' => $http,
            'acquirer_took_the_name' => $acquired, 'acquirer_elapsed_s' => $elapsed, 'owners_at_end' => $owners, 'policy_written' => $written,
            'deadlock_or_lock_wait_lines' => self::deadlocks($log0)];
        $met = !($written && $owners > 1);
        self::record('U0 open-transaction guard S2 uncommitted owner vs application acquirer', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertTrue($met, 'never a RADIUS policy for a key that ends with two owners ' . json_encode($obs));
        $this->assertFalse($atBarrier, 'refused before any RADIUS write');
        $this->assertStringStartsWith('REFUSED RADIUS_IDENTITY_CHECK_UNAVAILABLE', $outcome);
        $this->assertFalse($acquired, 'the committed owner blocks the acquirer');
        $this->assertSame(1, $owners);
        $this->assertSame(0, $obs['deadlock_or_lock_wait_lines']);
    }

    public function testWithTheIdentityLockHeldTheCountAndTheWriteAreSerialized(): void
    {
        $t = self::tag();
        $log0 = self::log();
        self::pause('radwrite');
        // the accepted order L-3 -> BEGIN: the caller holds the identity lock across its transaction and the RADIUS write
        $h = static::$app->execStart('require_once "system/devices/Radius.php"; $result = \Reseller\Domain\CustomerIdentity::hold(function (PDO $pdo) { $pdo->beginTransaction();'
            . sprintf(' $pdo->prepare("INSERT INTO tbl_customers (username, password, pppoe_username, pppoe_password, pppoe_ip, fullname, address, phonenumber, email, service_type, account_type, coordinates, status, balance, created_by, auto_renewal) VALUES (?, \'Pw-x\', \'\', \'\', \'\', \'W9 tx\', \'\', \'0700\', \'w9u0@example.invalid\', \'Hotspot\', \'Personal\', \'\', \'Active\', 0, 1, 0)")->execute([%s]);', var_export($t, true))
            . ' $c = ORM::for_table("tbl_customers")->find_one((int) $pdo->lastInsertId()); ' . self::addPlan('$c')
            . ' $pdo->commit(); return $out; });');
        $this->assertTrue(self::waitUntil(static fn(): bool => self::arrived('radwrite') || !self::running($h), 30));
        $atBarrier = self::arrived('radwrite');
        $acq = static::$app->execStart(self::p1Code('ad', strtoupper($t)));
        $waited = self::waitUntil(static fn(): bool => self::userLockWaiters() >= 1, 15);
        self::go('radwrite');
        $outcome = (string) static::$app->execFinish($h);
        $acquirer = (string) static::$app->execFinish($acq);
        $owners = count(self::holders($t));
        $obs = ['isolation' => self::$isolation, 'writer_reached_the_radius_write' => $atBarrier, 'acquirer_waited_on_the_identity_lock' => $waited, 'writer_outcome' => $outcome,
            'acquirer_outcome' => $acquirer, 'owners_at_end' => $owners, 'password_is_the_writers' => self::password($t) === 'Pw-x',
            'deadlock_or_lock_wait_lines' => self::deadlocks($log0), 'in_transaction_events' => substr_count(substr(self::log(), strlen($log0)), 'identity.owner_check_')];
        $met = $outcome === 'APPLIED' && str_starts_with($acquirer, 'REFUSED ') && $owners === 1;
        self::record('U0 open-transaction guard S3 identity lock held (accepted order)', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', $obs);
        $this->assertTrue($atBarrier, 'control: the write ran (single owner, lock held)');
        $this->assertTrue($waited, 'the acquirer waited for the identity lock');
        $this->assertTrue($met, json_encode($obs));
        $this->assertTrue($obs['password_is_the_writers']);
        $this->assertSame(0, $obs['in_transaction_events'], 'the lock-held path counts in place (no open-transaction event)');
        $this->assertSame(0, $obs['deadlock_or_lock_wait_lines']);
    }

    public function testControlsOutsideATransactionAreUnchanged(): void
    {
        $t = self::tag();
        $x = $this->person($t);
        $single = (string) static::$app->exec('require_once "system/devices/Radius.php"; ' . sprintf('$c = ORM::for_table("tbl_customers")->find_one(%d); ', $x['id']) . self::addPlan('$c') . ' $result = $out;');
        $u = self::tag();
        $this->person($u);
        $y = $this->person(strtoupper($u));
        $ambiguous = (string) static::$app->exec('require_once "system/devices/Radius.php"; ' . sprintf('$c = ORM::for_table("tbl_customers")->find_one(%d); ', $y['id']) . self::addPlan('$c') . ' $result = $out;');
        // a single owner inside an open transaction WITHOUT the lock: fail closed (no application path does this)
        $w = self::tag();
        $z = $this->person($w);
        $inTx = (string) static::$app->exec('require_once "system/devices/Radius.php"; $pdo = ORM::get_db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->beginTransaction(); '
            . sprintf('$c = ORM::for_table("tbl_customers")->find_one(%d); ', $z['id']) . self::addPlan('$c') . ' $pdo->rollBack(); $result = $out;');
        $obs = ['isolation' => self::$isolation, 'single_owner_outside_tx' => $single, 'two_owners_outside_tx' => $ambiguous, 'single_owner_in_tx_without_lock' => $inTx,
            'in_tx_policy_written' => self::policy($w)['radcheck'] !== []];
        self::record('U0 open-transaction guard controls', 'OBSERVED', $obs);
        $this->assertSame('APPLIED', $single);
        $this->assertSame('REFUSED RADIUS_IDENTITY_AMBIGUOUS', $ambiguous);
        $this->assertSame('REFUSED RADIUS_IDENTITY_CHECK_UNAVAILABLE', $inTx);
        $this->assertFalse($obs['in_tx_policy_written']);
    }
}
