<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * WP3A fixtures on top of Wp2Seed (disposable databases only):
 *
 *   B2  Agent, child of A (sibling of B)
 *   G   Agent, child of B (grandchild of A)
 *
 * plus helpers for fresh per-test resellers and activation rows. Activation rows are inserted directly
 * as test fixtures (there is no ActivationService in WP3A).
 */
final class FinanceSeed
{
    public static function extend(TestDatabase $db, Wp2Seed $seed): void
    {
        $seed->staff['B2'] = self::staff($db, 'B2', $seed->staff['A']);
        $seed->staff['G'] = self::staff($db, 'G', $seed->staff['B']);
    }

    /** Inserts a staff account (password "pw-<username>") and, when enrolled, its closure rows. */
    public static function staff(TestDatabase $db, string $username, ?int $parentId, string $status = 'Active', bool $enroll = true, string $role = 'Agent'): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = $db->insertRow('tbl_users', [
            'username' => $username, 'fullname' => 'Staff ' . $username, 'password' => sha1(Wp2Seed::password($username)),
            'user_type' => $role, 'status' => $status, 'root' => $parentId ?? 0, 'creationdate' => $now, 'last_login' => $now,
        ]);
        if ($enroll) {
            $tree = $db->pdo->prepare('INSERT INTO tbl_manager_tree (ancestor_id, descendant_id, depth, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))');
            $tree->execute([$id, $id, 0]);
            if ($parentId !== null) {
                foreach ($db->rows('SELECT ancestor_id, depth FROM tbl_manager_tree WHERE descendant_id = ?', [$parentId]) as $a) {
                    $tree->execute([(int) $a['ancestor_id'], $id, (int) $a['depth'] + 1]);
                }
            }
        }
        return $id;
    }

    /** An authoritative activation row (fixture) for reservation primitives. */
    public static function activation(TestDatabase $db, int $managerId, int $customerId, int $planId, int $actorUserId, ?string $wholesale = null): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $ref = 'ACT-' . bin2hex(random_bytes(8));
        return $db->insertRow('tbl_reseller_activations', [
            'reference' => $ref, 'idempotency_key' => 'idem-' . $ref, 'action' => 'NEW', 'status' => 'VALIDATED',
            'channel' => 'STAFF_WEB', 'manager_id' => $managerId, 'actor_type' => 'STAFF', 'actor_user_id' => $actorUserId,
            'customer_id' => $customerId, 'plan_id' => $planId, 'wholesale_cost' => $wholesale,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
