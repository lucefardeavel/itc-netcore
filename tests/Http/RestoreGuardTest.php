<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Migration\Outcome;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\Wp2HttpTestCase;

/** C24 / OD-5: the legacy JSON restore fails closed before any mutation on a reseller installation. */
final class RestoreGuardTest extends Wp2HttpTestCase
{
    private function backupFile(array $payload): string
    {
        $f = tempnam(sys_get_temp_dir(), 'wp2bk') . '.json';
        file_put_contents($f, json_encode($payload));
        return $f;
    }

    public function testRestoreIsRefusedBeforeMutationOnResellerInstallation(): void
    {
        $sa = $this->staff('sa');
        $payloads = [
            'legacy-format backup' => ['tbl_customers' => [['id' => 1, 'username' => 'restored', 'password' => 'x', 'fullname' => 'R']], 'tbl_users' => []],
            'project tables' => ['tbl_manager_tree' => [], 'tbl_project_migrations' => [], 'tbl_reseller_audit_events' => []],
        ];
        $registry = static::$db->rows('SELECT migration_id, status, checksum FROM tbl_project_migrations ORDER BY migration_id');
        $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events']);
        $audit = count($this->auditRows('restore.refused'));
        foreach ($payloads as $name => $payload) {
            $f = $this->backupFile($payload);
            $r = $sa->postMultipart('settings/dbrestore', ['csrf_token' => $sa->token()], ['json' => $f]);
            @unlink($f);
            $this->assertSame(302, $r->status, $name);
            $this->assertUnchanged($before, $name, ['tbl_logs', 'tbl_reseller_audit_events']);
        }
        $this->assertSame('1', (string) static::$db->value('SELECT @@GLOBAL.foreign_key_checks'), 'GLOBAL foreign_key_checks never touched');
        $this->assertSame($registry, static::$db->rows('SELECT migration_id, status, checksum FROM tbl_project_migrations ORDER BY migration_id'));
        $this->assertSame(Outcome::OK, RunnerProcess::run(static::$db, 'verify')['exit'], 'migrate.php verify still OK');
        $this->assertSame($audit + 2, count($this->auditRows('restore.refused')), 'refusals audited');
        $this->assertStringContainsString('Restore is disabled', $sa->get('settings/users')->body);
    }

    public function testOnlySuperAdminReachesBackupAndRestoreAndCsrfIsRequired(): void
    {
        $f = $this->backupFile(['tbl_bandwidth' => []]);
        foreach (['ad', 'A', 'rp'] as $u) {
            $c = $this->staff($u);
            $this->assertSame(403, $c->postMultipart('settings/dbrestore', ['csrf_token' => $c->token()], ['json' => $f])->status, $u);
            $this->assertSame(403, $c->postWithToken('settings/dbbackup', ['tables' => ['tbl_users']])->status, $u);
        }
        $sa = $this->staff('sa');
        $this->assertSame(403, $sa->postMultipart('settings/dbrestore', [], ['json' => $f])->status, 'missing CSRF token');
        @unlink($f);
        $r = $sa->postWithToken('settings/dbbackup', ['tables' => ['tbl_bandwidth', 'no_such_table', 'tbl_x; DROP TABLE y']]);
        $this->assertSame(200, $r->status);
        $j = json_decode($r->body, true);
        $this->assertSame(['tbl_bandwidth'], array_keys($j), 'only existing tables are exported');
    }
}
