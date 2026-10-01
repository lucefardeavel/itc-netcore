<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Migration\Outcome;
use Reseller\Migration\SchemaInspector;
use Reseller\Migration\VariantRegistry;

abstract class IntegrationTestCase extends TestCase
{
    /** @var list<TestDatabase> */
    private array $databases = [];

    protected function newDatabase(?string $variant = null, string $prefix = 't'): TestDatabase
    {
        $db = new TestDatabase($prefix);
        $this->databases[] = $db;
        if ($variant !== null) {
            $db->build($variant);
        }
        return $db;
    }

    protected function tearDown(): void
    {
        foreach ($this->databases as $db) {
            $db->drop();
        }
        $this->databases = [];
    }

    /** @return array<string,array{0:string}> */
    public static function variants(): array
    {
        $out = [];
        foreach (FixtureBuilder::ALL as $v) {
            $out[$v] = [$v];
        }
        return $out;
    }

    protected function run_(TestDatabase $db, string $command, array $args = [], array $env = []): array
    {
        return RunnerProcess::run($db, $command, $args, $env);
    }

    protected function assertOutcome(int $expected, array $result, string $message = ''): void
    {
        $this->assertSame(
            $expected,
            $result['exit'],
            ($message !== '' ? $message . "\n" : '') . 'expected ' . Outcome::name($expected) . ', got exit ' . $result['exit'] . "\n" . $result['raw']
        );
    }

    /** Raw canonical fingerprint of the core tables (no subtraction). */
    protected function coreFingerprint(TestDatabase $db): string
    {
        $inspector = new SchemaInspector($db->db());
        $core = [];
        foreach (VariantRegistry::CORE_TABLES as $t) {
            $core[$t] = $inspector->table($t);
        }
        return SchemaInspector::fingerprint($core);
    }

    /** @return list<string> */
    protected function projectTables(): array
    {
        return [
            'tbl_manager_tree', 'tbl_reseller_plan_base_costs', 'tbl_manager_plan_prices',
            'tbl_reseller_activations', 'tbl_reseller_activation_events',
            'tbl_manager_wallets', 'tbl_manager_transfers', 'tbl_manager_ledger', 'tbl_manager_wallet_reservations',
            'tbl_reseller_audit_events',
        ];
    }

    /** @return array<string,string> migration id => status */
    protected function statuses(TestDatabase $db): array
    {
        $out = [];
        foreach ($db->rows('SELECT migration_id, status FROM tbl_project_migrations ORDER BY migration_id') as $r) {
            $out[$r['migration_id']] = $r['status'];
        }
        return $out;
    }
}
