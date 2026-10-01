<?php
namespace Reseller\Tests\Integration\Wp2;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Migration\Outcome;
use Reseller\Tests\Support\DbProbe;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\IntegrationTestCase;
use Reseller\Tests\Support\RunnerProcess;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP2 §12: system/reseller/hierarchy.php is a read-only dry-run tool. inspect / validate / plan never
 * mutate, findings carry the full cycle path, the plan is deterministic, and apply is refused
 * (no hierarchy import without explicit owner authorization, C20).
 */
final class HierarchyCliTest extends IntegrationTestCase
{
    private const COUNTERS = ['Com_insert', 'Com_update', 'Com_delete', 'Com_replace', 'Com_truncate', 'Com_insert_select', 'Com_update_multi', 'Com_delete_multi'];

    private function migrated(): TestDatabase
    {
        $db = $this->newDatabase(FixtureBuilder::V2, 'hc');
        $r = RunnerProcess::run($db, 'migrate');
        $this->assertSame(Outcome::OK, $r['exit'], $r['raw']);
        return $db;
    }

    /** @return array{exit:int,report:array<string,mixed>,raw:string} */
    private function tool(TestDatabase $db, string $command): array
    {
        return RunnerProcess::exec([
            PHP_BINARY, dirname(__DIR__, 3) . '/system/reseller/hierarchy.php', $command, '--json',
            '--db-host=' . $db->host, '--db-name=' . $db->name, '--db-user=root', '--db-password-env=WP1_DB_ROOT_PASSWORD',
        ]);
    }

    private function user(TestDatabase $db, int $id, string $username, string $role, int $root, string $status = 'Active'): void
    {
        $db->insertRow('tbl_users', [
            'id' => $id, 'username' => $username, 'fullname' => $username, 'password' => sha1('x-' . $username),
            'user_type' => $role, 'status' => $status, 'root' => $root,
        ]);
    }

    /** @return array<string,int> */
    private function counters(TestDatabase $db): array
    {
        $out = [];
        $in = "'" . implode("','", self::COUNTERS) . "'";
        foreach ($db->rows("SHOW GLOBAL STATUS WHERE Variable_name IN ({$in})") as $r) {
            $out[(string) $r['Variable_name']] = (int) $r['Value'];
        }
        ksort($out);
        return $out;
    }

    /** A legacy root graph with every validation finding. */
    private function seedInvalidGraph(TestDatabase $db): void
    {
        $this->user($db, 1000, 'agentTop', 'Agent', 0);
        $this->user($db, 1001, 'salesOk', 'Sales', 1000);
        $this->user($db, 1002, 'agentChild', 'Agent', 1000);
        $this->user($db, 1010, 'selfParent', 'Agent', 1010);
        $this->user($db, 1020, 'missingParent', 'Agent', 999999);
        // 3-cycle 1030 -> 1031 -> 1032 -> 1030, plus a node hanging under the cycle
        $this->user($db, 1030, 'cycP', 'Agent', 1031);
        $this->user($db, 1031, 'cycQ', 'Agent', 1032);
        $this->user($db, 1032, 'cycR', 'Agent', 1030);
        $this->user($db, 1033, 'underCycle', 'Sales', 1030);
        // Agent under Sales (and therefore Sales with children)
        $this->user($db, 1040, 'salesParent', 'Sales', 1000);
        $this->user($db, 1041, 'agentUnderSales', 'Agent', 1040);
        // Sales without any parent
        $this->user($db, 1050, 'salesOrphan', 'Sales', 0);
        // warnings only
        $this->user($db, 1060, 'adminWithRoot', 'Admin', 1000);
        $this->user($db, 1070, 'agentInactive', 'Agent', 0, 'Inactive');
        $this->user($db, 1071, 'underInactive', 'Agent', 1070);
    }

    public function testReadOnlyCommandsNeverMutate(): void
    {
        $db = $this->migrated();
        $this->seedInvalidGraph($db);
        $before = DbProbe::checksums($db, []);
        foreach (['inspect', 'validate', 'plan', 'apply'] as $cmd) {
            $c0 = $this->counters($db);
            $r = $this->tool($db, $cmd);
            $c1 = $this->counters($db);
            $this->assertContains($r['exit'], [0, 2, 5], "{$cmd}: " . $r['raw']);
            foreach (self::COUNTERS as $k) {
                $this->assertSame($c0[$k] ?? 0, $c1[$k] ?? 0, "{$cmd} executed {$k}");
            }
        }
        $this->assertSame([], DbProbe::changed($before, DbProbe::checksums($db, [])), 'no table changed (audit and logs included)');
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM tbl_manager_tree'), 'nothing was enrolled');
    }

    public function testValidateReportsEveryFindingWithTheCyclePath(): void
    {
        $db = $this->migrated();
        $this->seedInvalidGraph($db);
        $r = $this->tool($db, 'validate');
        $this->assertSame(2, $r['exit'], $r['raw']);
        $codes = [];
        foreach ($r['report']['findings'] as $f) {
            $codes[$f['user_id']][] = $f['code'];
        }
        $this->assertContains('SELF_PARENT', $codes[1010] ?? []);
        $this->assertContains('MISSING_PARENT', $codes[1020] ?? []);
        $this->assertContains('CYCLE', $codes[1030] ?? [], 'the cycle is reported once, on its smallest member');
        $cycles = array_values(array_filter($r['report']['findings'], static fn($f) => $f['code'] === 'CYCLE'));
        $this->assertCount(1, $cycles);
        $this->assertSame('cycle 1030 -> 1031 -> 1032 -> 1030', $cycles[0]['detail']);
        $this->assertContains('PARENT_NOT_AGENT', $codes[1041] ?? []);
        $this->assertContains('SALES_WITH_CHILDREN', $codes[1040] ?? []);
        $this->assertContains('SALES_WITHOUT_AGENT_PARENT', $codes[1050] ?? []);
        $this->assertContains('NON_TREE_ROLE_WITH_PARENT', $codes[1060] ?? []);
        $this->assertContains('PARENT_INACTIVE', $codes[1071] ?? []);
        $this->assertArrayNotHasKey(1000, $codes, 'a valid top-level Agent has no finding');
        $this->assertArrayNotHasKey(1001, $codes);
        $this->assertArrayNotHasKey(1002, $codes);
        $this->assertGreaterThan(0, $r['report']['errors']);
    }

    public function testPlanExcludesInvalidSubtreesAndOrdersParentsFirst(): void
    {
        $db = $this->migrated();
        $this->seedInvalidGraph($db);
        $r = $this->tool($db, 'plan');
        $this->assertSame(2, $r['exit'], $r['raw']);
        $plan = $r['report']['plan'];
        $this->assertSame('nuxr-hierarchy-plan/1', $plan['format']);
        $ops = array_map(static fn($o) => [$o['user_id'], $o['parent_id']], $plan['operations']);
        // parents first (depth), ties by id; underInactive is valid (warning only)
        $this->assertSame([[1000, null], [1070, null], [1001, 1000], [1002, 1000], [1071, 1070]], $ops);
        $excluded = [];
        foreach ($plan['excluded'] as $e) {
            $excluded[$e['user_id']] = $e['reasons'];
        }
        foreach ([1010, 1020, 1030, 1031, 1032, 1033, 1040, 1041, 1050] as $id) {
            $this->assertArrayHasKey($id, $excluded, "user {$id} must be excluded");
        }
        $this->assertSame(['INVALID_ANCESTOR'], $excluded[1033], 'a node under a cycle is excluded with its ancestor');
        $this->assertSame(['CYCLE'], $excluded[1031]);
        $this->assertArrayNotHasKey(1060, $excluded, 'non-tree roles are never planned');
    }

    public function testPlanIsDeterministicAcrossRerunsAndInsertionOrder(): void
    {
        $users = [
            [2000, 'top1', 'Agent', 0], [2001, 'top2', 'Agent', 0], [2010, 'mid1', 'Agent', 2000],
            [2011, 's1', 'Sales', 2000], [2020, 'leaf1', 'Sales', 2010], [2021, 'mid2', 'Agent', 2010], [2030, 's2', 'Sales', 2021],
        ];
        $a = $this->migrated();
        foreach ($users as [$id, $u, $role, $root]) {
            $this->user($a, $id, $u, $role, $root);
        }
        $b = $this->migrated();
        $shuffled = $users;
        // deterministic but different insertion order (children before parents)
        usort($shuffled, static fn($x, $y) => $y[0] <=> $x[0]);
        $b->exec('SET SESSION foreign_key_checks = 0');
        foreach ($shuffled as [$id, $u, $role, $root]) {
            $this->user($b, $id, $u, $role, $root);
        }
        $b->exec('SET SESSION foreign_key_checks = 1');

        $r1 = $this->tool($a, 'plan');
        $r2 = $this->tool($a, 'plan');
        $r3 = $this->tool($b, 'plan');
        foreach ([$r1, $r2, $r3] as $r) {
            $this->assertSame(0, $r['exit'], $r['raw']);
        }
        $this->assertSame($r1['report']['plan'], $r2['report']['plan'], 'rerun yields the identical plan');
        $this->assertSame($r1['report']['plan']['plan_sha256'], $r3['report']['plan']['plan_sha256'], 'insertion order does not change the plan');
        $this->assertSame($r1['report']['plan']['source_fingerprint'], $r3['report']['plan']['source_fingerprint']);
        $this->assertSame(
            [2000, 2001, 2010, 2011, 2020, 2021, 2030],
            array_map(static fn($o) => $o['user_id'], $r1['report']['plan']['operations'])
        );
        // the plan hash covers the body
        $body = $r1['report']['plan'];
        $hash = $body['plan_sha256'];
        unset($body['plan_sha256']);
        $this->assertSame($hash, hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        // a state change changes the fingerprint
        $a->exec('UPDATE tbl_users SET status = \'Inactive\' WHERE id = 2001');
        $r4 = $this->tool($a, 'plan');
        $this->assertNotSame($r1['report']['plan']['source_fingerprint'], $r4['report']['plan']['source_fingerprint']);
    }

    public function testAlreadyEnrolledNodesAreNeverReplacedAndInspectReportsState(): void
    {
        $db = $this->migrated();
        $this->user($db, 3000, 'enrolledTop', 'Agent', 0);
        $this->user($db, 3001, 'newChild', 'Sales', 3000);
        $db->exec('INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES (3000, 3000, 0, UTC_TIMESTAMP(6))');
        $plan = $this->tool($db, 'plan');
        $this->assertSame(0, $plan['exit'], $plan['raw']);
        $this->assertSame([[3001, 3000]], array_map(static fn($o) => [$o['user_id'], $o['parent_id']], $plan['report']['plan']['operations']));

        $inspect = $this->tool($db, 'inspect');
        $this->assertSame(0, $inspect['exit'], $inspect['raw']);
        $this->assertSame(1, $inspect['report']['enrolled']);
        $this->assertSame(1, $inspect['report']['tree_rows']);
        $this->assertSame(1, $inspect['report']['users_with_legacy_root']);
        $this->assertSame([], $inspect['report']['tree_drift']);
        $this->assertSame(0, $inspect['report']['customers_with_owner']);
    }

    public function testApplyIsRefusedWithoutOwnerAuthorization(): void
    {
        $db = $this->migrated();
        $this->user($db, 4000, 'top', 'Agent', 0);
        $before = DbProbe::checksums($db, []);
        $r = $this->tool($db, 'apply');
        $this->assertSame(5, $r['exit'], $r['raw']);
        $this->assertStringContainsString('requires explicit owner authorization (C20)', implode(' ', $r['report']['problems']));
        $this->assertSame([], DbProbe::changed($before, DbProbe::checksums($db, [])));
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM tbl_manager_tree'));
    }

    public function testUnknownCommandsAndOptionsAreUsageErrors(): void
    {
        $db = $this->migrated();
        $this->assertSame(5, $this->tool($db, 'import')['exit']);
        $r = RunnerProcess::exec([PHP_BINARY, dirname(__DIR__, 3) . '/system/reseller/hierarchy.php', 'plan', '--json', '--force']);
        $this->assertSame(5, $r['exit']);
    }
}
