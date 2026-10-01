<?php
namespace Reseller\Tests\Integration\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP1 binding input to WP3B: safe plan deletion with the new RESTRICT references. A plan referenced by
 * reseller pricing or activation data is refused by every legacy delete path BEFORE any hook, router /
 * RADIUS removal or delete (no device call, no database change, audit evidence); an unreferenced plan
 * is deleted exactly as before.
 */
final class PlanDeleteGuardTest extends Wp2HttpTestCase
{
    private const PATHS = ['Hotspot' => 'delete', 'PPPOE' => 'pppoe-delete', 'VPN' => 'vpn-delete', 'Balance' => 'balance-delete'];

    private function plan(string $type): int
    {
        $seed = static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [static::$seed->planId])[0];
        unset($seed['id']);
        $seed['name_plan'] = 'del_' . bin2hex(random_bytes(4));
        $seed['type'] = $type;
        return static::$db->insertRow('tbl_plans', $seed);
    }

    /** @return list<string> */
    private function types(): array
    {
        $t = (string) static::$db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_plans' AND COLUMN_NAME = 'type'");
        preg_match_all("/'([^']+)'/", $t, $m);
        return array_values(array_intersect(array_keys(self::PATHS), $m[1]));
    }

    private function delete(string $type, int $id, ?\Reseller\Tests\Support\HttpClient $sa = null): void
    {
        $sa ??= $this->staff('sa');
        $r = $sa->postWithToken('services/' . self::PATHS[$type] . '/' . $id, [], ['token' => $sa->token()]);
        $this->assertSame(302, $r->status, $type . ': ' . $r->excerpt());
    }

    public function testReferencedPlansAreRefusedBeforeAnySideEffect(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $sa = $this->sid('sa');
        foreach ($this->types() as $type) {
            $id = $this->plan($type);
            // referenced by a provider base cost (fixture row: the plan was once priced for resellers)
            static::$db->insertRow('tbl_reseller_plan_base_costs', ['plan_id' => $id, 'base_cost' => '10.00', 'is_resale_enabled' => 0,
                'created_by' => $sa, 'created_at' => $now, 'updated_by' => $sa, 'updated_at' => $now]);
            $client = $this->staff('sa'); // log in first: a login updates tbl_users.last_login
            static::$app->clearProbes();
            $audits = count($this->auditRows('plan.delete_refused'));
            $before = $this->snapshot(['tbl_logs', 'tbl_reseller_audit_events']);
            $this->delete($type, $id, $client);
            $this->assertUnchanged($before, "{$type}: refused delete", ['tbl_logs', 'tbl_reseller_audit_events']);
            $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_plans WHERE id = ?', [$id]), "{$type}: plan kept");
            $this->assertSame([], static::$app->probe('device'), "{$type}: no router / RADIUS removal");
            $rows = $this->auditRows('plan.delete_refused');
            $this->assertCount($audits + 1, $rows, "{$type}: audited");
            $this->assertSame(['plan_id' => $id], json_decode(end($rows)['target_snapshot'], true));
            $this->assertStringContainsString('cannot be deleted', $client->get('services/' . strtolower($type === 'PPPOE' ? 'pppoe' : $type))->body, "{$type}: clear refusal shown");
        }
    }

    public function testActivationReferencesIncludingPreviousPlanAreRefused(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $current = $this->plan('Hotspot');
        $previous = $this->plan('Hotspot');
        $ref = 'ACT-' . bin2hex(random_bytes(6));
        static::$db->insertRow('tbl_reseller_activations', [
            'reference' => $ref, 'idempotency_key' => 'idem-' . $ref, 'action' => 'CHANGE_PLAN', 'status' => 'CREATED', 'channel' => 'STAFF_WEB',
            'manager_id' => $this->sid('A'), 'actor_type' => 'STAFF', 'actor_user_id' => $this->sid('ad'), 'customer_id' => $this->cid('cA1'),
            'plan_id' => $current, 'previous_plan_id' => $previous, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([$current, $previous] as $id) {
            static::$app->clearProbes();
            $this->delete('Hotspot', $id);
            $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_plans WHERE id = ?', [$id]));
            $this->assertSame([], static::$app->probe('device'));
        }
    }

    public function testUnreferencedPlansAreDeletedAsBefore(): void
    {
        foreach ($this->types() as $type) {
            $id = $this->plan($type);
            static::$app->clearProbes();
            $this->delete($type, $id);
            $this->assertSame(0, (int) static::$db->value('SELECT COUNT(*) FROM tbl_plans WHERE id = ?', [$id]), "{$type}: deleted");
            if ($type !== 'Balance') {
                $this->assertContains('remove_plan', array_column(static::$app->probe('device'), 'call'), "{$type}: legacy device removal unchanged");
            }
        }
    }
}
