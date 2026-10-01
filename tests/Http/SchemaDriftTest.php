<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\TestDatabase;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Owner schema-readiness rule on a migrated database whose reseller schema becomes partial or
 * inconsistent: each condition makes dependent operations fail closed for every role; restoring the
 * condition restores service.
 */
final class SchemaDriftTest extends Wp2HttpTestCase
{
    private function probe(string $condition): void
    {
        $sa = $this->staff('sa');
        $ag = $this->staff('A');
        static::$app->clearProbes();
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events', 'tbl_project_migrations', 'tbl_reseller_audit_x']);
        $r = $sa->postWithToken('plan/recharge-post', ['id_customer' => $this->cid('cNULL2'), 'server' => 'wp2_router', 'plan' => static::$seed->planId, 'using' => 'cash']);
        $this->assertSame(302, $r->status, "{$condition}: SuperAdmin recharge of an unassigned customer refused");
        $this->assertSame(403, $sa->postWithToken('customers/add-post', ['username' => 'drift_x', 'fullname' => 'X', 'password' => 'abc123'])->status, $condition);
        $this->assertSame(403, $ag->get('customers/list')->status, "{$condition}: Agent scope closed");
        $this->assertSame(200, $ag->get('dashboard')->status);
        $this->assertStringContainsString('schema is not ready', $sa->get('dashboard')->body);
        $this->assertUnchanged($before, $condition, ['tbl_logs', 'tbl_reseller_audit_events', 'tbl_project_migrations', 'tbl_reseller_audit_x']);
        $this->assertSame([], static::$app->probe('device'), $condition);
    }

    private function assertReady(): void
    {
        $this->assertSame(200, $this->staff('A')->get('customers/list')->status, 'readiness restored');
    }

    public function testFailedPartialMigration(): void
    {
        static::$db->exec("UPDATE tbl_project_migrations SET status = 'FAILED_PARTIAL' WHERE migration_id = 'R0006_reseller_audit'");
        try {
            $this->probe('FAILED_PARTIAL');
        } finally {
            static::$db->exec("UPDATE tbl_project_migrations SET status = 'APPLIED' WHERE migration_id = 'R0006_reseller_audit'");
        }
        $this->assertReady();
    }

    public function testChecksumDrift(): void
    {
        $old = (string) static::$db->value("SELECT checksum FROM tbl_project_migrations WHERE migration_id = 'R0005_customer_ownership'");
        static::$db->exec("UPDATE tbl_project_migrations SET checksum = '" . str_repeat('0', 64) . "' WHERE migration_id = 'R0005_customer_ownership'");
        try {
            $this->probe('checksum drift');
        } finally {
            static::$db->exec("UPDATE tbl_project_migrations SET checksum = '{$old}' WHERE migration_id = 'R0005_customer_ownership'");
        }
        $this->assertReady();
    }

    public function testMissingMigrationAndUnknownEntry(): void
    {
        static::$db->exec("UPDATE tbl_project_migrations SET migration_id = 'R0006_not_applied' WHERE migration_id = 'R0006_reseller_audit'");
        try {
            $this->probe('R0006 missing + unknown registry entry');
        } finally {
            static::$db->exec("UPDATE tbl_project_migrations SET migration_id = 'R0006_reseller_audit' WHERE migration_id = 'R0006_not_applied'");
        }
        $this->assertReady();
    }

    public function testMissingRequiredTable(): void
    {
        static::$db->exec('RENAME TABLE tbl_reseller_audit_events TO tbl_reseller_audit_x');
        try {
            $this->probe('audit table missing');
        } finally {
            static::$db->exec('RENAME TABLE tbl_reseller_audit_x TO tbl_reseller_audit_events');
        }
        $this->assertReady();
    }

    public function testMigrationRunInProgress(): void
    {
        $lockPdo = FixtureBuilder::connect(static::$db->host, TestDatabase::rootPassword(), static::$db->name);
        $name = 'nuxr_mig_' . substr(sha1(static::$db->name), 0, 20);
        $this->assertSame('1', (string) $lockPdo->query("SELECT GET_LOCK('{$name}', 5)")->fetchColumn());
        try {
            $this->probe('runner lock held');
        } finally {
            $lockPdo->query("SELECT RELEASE_LOCK('{$name}')");
        }
        $this->assertReady();
    }
}
