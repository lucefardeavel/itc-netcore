<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP2 §7: ResellerTreeService — explicit placement (OD-6), subtree moves, cycle/self-parent/role
 * rules, disable/reactivate (OD-13), closure ↔ root consistency, audit, and serialization of
 * concurrent cross-moves (global tree lock taken before BEGIN).
 */
final class HierarchyTest extends Wp2HttpTestCase
{
    private function verify(): array
    {
        return static::$app->exec('$result = \Reseller\Domain\ResellerTreeService::verify(ORM::get_db());');
    }

    private function parentOf(string $u): ?int
    {
        $v = static::$db->value('SELECT ancestor_id FROM tbl_manager_tree WHERE descendant_id = ? AND depth = 1', [$this->sid($u)]);
        return $v === null ? null : (int) $v;
    }

    private function notify(\Reseller\Tests\Support\HttpClient $c): string
    {
        $r = $c->get('reseller/tree');
        return preg_match("/title: '([^']*)'/", $r->body, $m) ? $m[1] : '';
    }

    public function testSeedTreeIsConsistent(): void
    {
        $this->assertSame([], $this->verify());
    }

    public function testExplicitPlacementOfLegacyAccounts(): void
    {
        $sa = $this->staff('sa');
        $page = $sa->get('reseller/tree');
        $this->assertStringContainsString('<td>E <small', $page->body, 'not-yet-enrolled legacy Agent E is listed for explicit onboarding');
        $this->assertStringContainsString('<td>E1 <small', $page->body);
        // Sales without a parent is refused; Sales under a Sales is refused
        $sa->postWithToken('reseller/place-post', ['user_id' => $this->sid('E1'), 'parent_id' => '']);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ?', [$this->sid('E1')]));
        $sa->postWithToken('reseller/place-post', ['user_id' => $this->sid('E1'), 'parent_id' => $this->sid('A1')]);
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ?', [$this->sid('E1')]));
        // place E top-level, then E1 under E: the legacy root of E1 (= E) is never applied automatically
        $sa->postWithToken('reseller/place-post', ['user_id' => $this->sid('E'), 'parent_id' => '']);
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ?', [$this->sid('E')]));
        $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree WHERE descendant_id = ?', [$this->sid('E1')]), 'no automatic import of E1');
        $sa->postWithToken('reseller/place-post', ['user_id' => $this->sid('E1'), 'parent_id' => $this->sid('C')]);
        $this->assertSame($this->sid('C'), $this->parentOf('E1'), 'the admin choice wins over the legacy root');
        $this->assertSame($this->sid('C'), (int) static::$db->value('SELECT root FROM tbl_users WHERE id = ?', [$this->sid('E1')]), 'root mirrors the closure');
        $rows = $this->auditRows('tree.place');
        $last = end($rows);
        $this->assertStringContainsString('"legacy_root":' . $this->sid('E'), $last['before_json']);
        // already enrolled → refused
        $sa->postWithToken('reseller/place-post', ['user_id' => $this->sid('E1'), 'parent_id' => $this->sid('A')]);
        $this->assertSame($this->sid('C'), $this->parentOf('E1'));
        // Agent / Admin-less roles cannot place
        $this->assertSame(403, $this->staff('A')->postWithToken('reseller/place-post', ['user_id' => $this->sid('E1'), 'parent_id' => $this->sid('A')])->status);
        $this->assertSame([], $this->verify());
    }

    public function testMoveRulesCycleSelfParentAndRoles(): void
    {
        $sa = $this->staff('sa');
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('A'), 'parent_id' => $this->sid('B')]);
        $this->assertStringContainsString('cycle', strtolower($this->notify($sa)));
        $this->assertNull($this->parentOf('A'), 'cycle refused, A stays top-level');
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('A'), 'parent_id' => $this->sid('A')]);
        $this->assertStringContainsString('own parent', $this->notify($sa));
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('B'), 'parent_id' => $this->sid('A1')]);
        $this->assertSame($this->sid('A'), $this->parentOf('B'), 'Sales parent refused');
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('B'), 'parent_id' => $this->sid('D')]);
        $this->assertSame($this->sid('A'), $this->parentOf('B'), 'inactive parent refused');
        $this->assertSame(403, $this->staff('A')->postWithToken('reseller/move-post', ['node_id' => $this->sid('B'), 'parent_id' => $this->sid('C')])->status, 'Agent cannot move');
        $this->assertSame([], $this->verify());
    }

    public function testMoveSubtreeKeepsClosureConsistentAndClearsMismatchedSalesAssignments(): void
    {
        $sa = $this->staff('sa');
        // B (with customer cB) moves under C: closure rewritten, ownership unchanged
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('B'), 'parent_id' => $this->sid('C')]);
        $this->assertSame($this->sid('C'), $this->parentOf('B'));
        $this->assertSame([], $this->verify());
        $this->assertSame($this->sid('B'), (int) static::$db->value('SELECT manager_id FROM tbl_customers WHERE id = ?', [$this->cid('cB')]));
        $this->assertSame(200, $this->staff('C')->get('customers/view/' . $this->cid('cB'))->status, 'C now sees B\'s customers');
        $this->assertNotSame(200, $this->staff('A')->get('customers/view/' . $this->cid('cB'))->status, 'A no longer does');
        // Sales A2 (assigned to cA2 of A) moves under C: assignment cleared (owner stays A), audited
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('A2'), 'parent_id' => $this->sid('C')]);
        $this->assertSame($this->sid('C'), $this->parentOf('A2'));
        $this->assertNull(static::$db->value('SELECT assigned_sales_id FROM tbl_customers WHERE id = ?', [$this->cid('cA2')]));
        $this->assertSame($this->sid('A'), (int) static::$db->value('SELECT manager_id FROM tbl_customers WHERE id = ?', [$this->cid('cA2')]));
        $this->assertNotEmpty($this->auditRows('customer.clear_sales'));
        $this->assertSame([], $this->verify());
        // restore the seed shape
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('B'), 'parent_id' => $this->sid('A')]);
        $sa->postWithToken('reseller/move-post', ['node_id' => $this->sid('A2'), 'parent_id' => $this->sid('A')]);
        static::$db->exec('UPDATE tbl_customers SET assigned_sales_id = ' . $this->sid('A2') . ' WHERE id = ' . $this->cid('cA2'));
        $this->assertSame([], $this->verify());
        $this->assertGreaterThanOrEqual(4, count($this->auditRows('tree.move')));
    }

    public function testAgentDisablesStrictDescendantsOnly(): void
    {
        $a = $this->staff('A');
        $a->postWithToken('reseller/status-post', ['user_id' => $this->sid('C1'), 'status' => 'Inactive']);
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('C1')]), 'unrelated tree');
        $a->postWithToken('reseller/status-post', ['user_id' => $this->sid('A'), 'status' => 'Inactive']);
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('A')]), 'not self');
        $this->staff('B')->postWithToken('reseller/status-post', ['user_id' => $this->sid('A'), 'status' => 'Inactive']);
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('A')]), 'not an ancestor');
        $this->assertSame(403, $this->staff('A1')->postWithToken('reseller/status-post', ['user_id' => $this->sid('A2'), 'status' => 'Inactive'])->status, 'Sales cannot disable');
        $a->postWithToken('reseller/status-post', ['user_id' => $this->sid('A1'), 'status' => 'Inactive']);
        $this->assertSame('Inactive', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('A1')]));
        $a->postWithToken('reseller/status-post', ['user_id' => $this->sid('A1'), 'status' => 'Active']);
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$this->sid('A1')]));
    }

    public function testFrozenDescendantIsReadOnly(): void
    {
        // F's parent D is inactive: F can read its own scope but cannot mutate
        $f = $this->staff('F');
        $this->assertSame(200, $f->get('customers/view/' . $this->cid('cF'))->status);
        $before = $this->snapshot();
        $this->assertSame(403, $f->postWithToken('customers/add-post', ['username' => 'by_frozen', 'fullname' => 'X', 'password' => 'abc123'])->status);
        $this->assertSame(403, $f->postWithToken('settings/users-post', ['username' => 'by_frozen2', 'fullname' => 'Xyz', 'password' => 'abc12345', 'user_type' => 'Sales'])->status);
        $this->assertUnchanged($before, 'frozen actor');
    }

    /** Two concurrent cross-moves (X under Y, Y under X) are serialized: exactly one wins, no cycle. */
    public function testConcurrentCrossMovesCannotCreateACycle(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $ids = static::$app->exec(sprintf(
                '$a = \Reseller\Security\ActorContext::forStaff(ORM::for_table("tbl_users")->where("username", "sa")->find_one()->as_array(), "CLI");'
                . '$x = \Reseller\Domain\StaffService::create($a, ["username" => "cx%1$d", "fullname" => "Cross X %1$d", "password" => "pw-cross-1", "user_type" => "Agent", "parent_id" => null]);'
                . '$y = \Reseller\Domain\StaffService::create($a, ["username" => "cy%1$d", "fullname" => "Cross Y %1$d", "password" => "pw-cross-1", "user_type" => "Agent", "parent_id" => null]);'
                . '$result = [$x, $y];',
                $i
            ));
            [$x, $y] = $ids;
            $move = static fn(int $node, int $parent) => sprintf(
                '$a = \Reseller\Security\ActorContext::forStaff(ORM::for_table("tbl_users")->where("username", "sa")->find_one()->as_array(), "CLI");'
                . 'try { \Reseller\Domain\ResellerTreeService::moveSubtree($a, %d, %d); $result = "ok"; } catch (\Reseller\Domain\DomainError $e) { $result = $e->reason; }',
                $node,
                $parent
            );
            $h1 = static::$app->execStart($move($x, $y));
            $h2 = static::$app->execStart($move($y, $x));
            $results = [static::$app->execFinish($h1), static::$app->execFinish($h2)];
            sort($results);
            $this->assertSame(['CYCLE', 'ok'], $results, "round {$i}");
            $this->assertSame([], $this->verify(), "round {$i}: closure consistent");
            $cycle = (int) static::$db->value('SELECT COUNT(*) FROM tbl_manager_tree a JOIN tbl_manager_tree b ON a.ancestor_id = b.descendant_id AND a.descendant_id = b.ancestor_id WHERE a.depth > 0');
            $this->assertSame(0, $cycle, 'no mutual ancestry');
        }
    }
}
