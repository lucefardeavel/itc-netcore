<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Domain\DomainError;
use Reseller\Domain\SchemaReadiness;
use Reseller\Finance\FinanceFaults;
use Reseller\Finance\TransferService;
use Reseller\Migration\Outcome;
use Reseller\Security\ActorContext;

/**
 * Base class for WP3A integration tests: one disposable database per class (V2 legacy variant +
 * R0001–R0006 applied by the strict runner + Wp2Seed + FinanceSeed). The finance services run
 * in-process on their own connection (ORM default connection), separate from the test's assertion
 * connection, exactly as in the application.
 *
 * After every test: tbl_customers (including the legacy customer balance) is byte-identical to before
 * the test, and the whole-database financial invariants hold.
 */
abstract class FinanceTestCase extends TestCase
{
    protected static ?TestDatabase $db = null;
    protected static ?Wp2Seed $seed = null;
    protected static ?\PDO $svc = null;
    private string $customersBefore = '';

    public static function setUpBeforeClass(): void
    {
        $db = new TestDatabase('f');
        try {
            $db->build(FixtureBuilder::V2);
            $r = RunnerProcess::run($db, 'migrate');
            if ($r['exit'] !== Outcome::OK) {
                throw new \RuntimeException('migration failed: ' . $r['raw']);
            }
            static::$seed = Wp2Seed::seed($db)->attach($db);
            FinanceSeed::extend($db, static::$seed);
        } catch (\Throwable $e) {
            $db->drop();
            throw $e;
        }
        static::$db = $db;
        require_once dirname(__DIR__, 2) . '/system/orm.php';
        static::$svc = FixtureBuilder::connect($db->host, TestDatabase::rootPassword(), $db->name);
        \ORM::reset_db();
        \ORM::set_db(static::$svc);
        SchemaReadiness::reset();
        ActorContext::reset();
        FinanceFaults::$hook = null;
    }

    public static function tearDownAfterClass(): void
    {
        FinanceFaults::$hook = null;
        \ORM::reset_db();
        SchemaReadiness::reset();
        static::$db?->drop();
        static::$db = null;
        static::$seed = null;
        static::$svc = null;
    }

    protected function setUp(): void
    {
        FinanceFaults::$hook = null;
        $this->customersBefore = $this->customersChecksum();
    }

    protected function assertPostConditions(): void
    {
        FinanceFaults::$hook = null;
        $this->assertSame($this->customersBefore, $this->customersChecksum(), 'tbl_customers (legacy customer balance) must never change through manager finance');
        $this->assertSame([], FinanceInvariants::check(static::$db), 'financial invariants');
    }

    protected function tearDown(): void
    {
        FinanceFaults::$hook = null;
    }

    protected function customersChecksum(): string
    {
        return (string) (static::$db->rows('CHECKSUM TABLE tbl_customers EXTENDED')[0]['Checksum'] ?? '');
    }

    protected function sid(string $username): int
    {
        return static::$seed->staff[$username];
    }

    protected function cid(string $username): int
    {
        return static::$seed->customers[$username];
    }

    /** Server-resolved staff context built from the current tbl_users row (WP2 ActorContext::forStaff). */
    protected function ctx(string|int $user, string $channel = 'WEB'): ActorContext
    {
        $col = is_int($user) ? 'id' : 'username';
        $rows = static::$db->rows("SELECT * FROM tbl_users WHERE {$col} = ?", [$user]);
        $this->assertCount(1, $rows, "staff {$user}");
        return ActorContext::forStaff($rows[0], $channel);
    }

    protected function customerCtx(string $username, string $channel = 'WEB'): ActorContext
    {
        $rows = static::$db->rows('SELECT * FROM tbl_customers WHERE username = ?', [$username]);
        return ActorContext::forCustomer($rows[0], $channel);
    }

    protected static function ref(string $prefix = 'T'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    /** A fresh enrolled reseller Agent (optionally under $parent). */
    protected function agent(?int $parent = null, string $status = 'Active', bool $enroll = true): int
    {
        return FinanceSeed::staff(static::$db, 'ag' . bin2hex(random_bytes(4)), $parent, $status, $enroll);
    }

    /** A fresh Agent funded by the Admin through the real service. */
    protected function funded(string $amount, ?int $parent = null): int
    {
        $id = $this->agent($parent);
        TransferService::fund($this->ctx('ad'), $id, $amount, 'initial provider funding', self::ref('F'));
        return $id;
    }

    protected function activation(int $managerId, ?string $wholesale = null, string $customer = 'cA0'): int
    {
        return FinanceSeed::activation(static::$db, $managerId, $this->cid($customer), static::$seed->planId, $this->sid('ad'), $wholesale);
    }

    protected function setStatus(int $userId, string $status): void
    {
        static::$db->pdo->prepare('UPDATE tbl_users SET status = ? WHERE id = ?')->execute([$status, $userId]);
    }

    /** @return array<string,mixed>|null */
    protected function wallet(int $managerId): ?array
    {
        return static::$db->rows('SELECT * FROM tbl_manager_wallets WHERE manager_id = ?', [$managerId])[0] ?? null;
    }

    protected function balance(int $managerId): ?string
    {
        return $this->wallet($managerId)['balance'] ?? null;
    }

    /** @return list<array<string,mixed>> */
    protected function ledger(int $managerId): array
    {
        return static::$db->rows('SELECT * FROM tbl_manager_ledger WHERE manager_id = ? ORDER BY id', [$managerId]);
    }

    protected function rowCount(string $table, string $where = '1 = 1', array $params = []): int
    {
        return (int) static::$db->value("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
    }

    /** @return array<string,string> checksum of every table except tbl_logs (audit table included) */
    protected function checksums(): array
    {
        return DbProbe::checksums(static::$db, ['tbl_logs']);
    }

    /** Asserts a DomainError with $reason and (by default) that the refusal wrote nothing anywhere. */
    protected function refused(string $reason, callable $fn, bool $noWrite = true): void
    {
        $before = $noWrite ? $this->checksums() : [];
        try {
            $fn();
            $this->fail("expected refusal {$reason}");
        } catch (DomainError $e) {
            $this->assertSame($reason, $e->reason, 'refusal reason: ' . $e->getMessage());
        }
        if ($noWrite) {
            $this->assertSame([], DbProbe::changed($before, $this->checksums()), "refusal {$reason} must not write");
        }
    }
}
