<?php
namespace Reseller\Activation;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Radius\RadiusPolicy;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusRepository;

/**
 * Canonical projection of the RADIUS state an activation's provisioning owns or changes (WP5A owner fix
 * round 1, reconciliation). Derived from the WP4 provisioner contract:
 *  - the subscriber: every managed check / reply attribute row [attribute, op, value] (RadiusPolicy::MANAGED_*)
 *    and every group membership row [group, priority] of the provisioned key;
 *  - the plan group: the managed group reply rows of plan_<id> (RadiusProvisioner::applyPlan).
 * Unmanaged rows and accounting history are not part of it (the provisioner never touches them).
 *
 * Passwords never appear: the password value is replaced by a salted SHA-256 digest (salt = the activation
 * reference), so the projection can be stored in the activation's event history and compared later without
 * persisting a secret. Diagnostics name differing components only, never values.
 */
final class RadiusState
{
    public const VERSION = 1;
    private const PASSWORD = 'Cleartext-Password';

    /** The current state of the subscriber key and of the plan group, projected. */
    public static function read(string $username, int $planId, string $salt): array
    {
        $repo = RadiusRepository::connection();
        $user = $repo->snapshotUser($username);
        return self::project($username, $user['check'], $user['reply'], $user['groups'], $repo->snapshotGroup($planId), $salt);
    }

    /** The exact state the provisioning of $policy (and of its plan group from $bandwidth) leaves behind. */
    public static function target(RadiusPolicy $policy, ?array $bandwidth, string $salt): array
    {
        $only = static fn(array $items, array $managed): array => array_values(array_map(
            static fn(array $i): array => [(string) $i[0], (string) $i[1], (string) $i[2]],
            array_filter($items, static fn(array $i): bool => in_array($i[0], $managed, true))));
        $groups = $policy->group !== null ? [[$policy->group, 1]] : [];
        $planGroup = $bandwidth !== null ? RadiusPolicyBuilder::groupReply($bandwidth) : [];
        return self::project($policy->username, $only($policy->check, RadiusPolicy::MANAGED_CHECK), $only($policy->reply, RadiusPolicy::MANAGED_REPLY),
            $groups, array_map(static fn(array $r): array => [(string) $r[0], (string) $r[1], (string) $r[2]], $planGroup), $salt);
    }

    /**
     * @param list<array{0:string,1:string,2:string}> $check
     * @param list<array{0:string,1:string,2:string}> $reply
     * @param list<array{0:string,1:int}>             $groups
     * @param list<array{0:string,1:string,2:string}> $planGroup
     */
    public static function project(string $username, array $check, array $reply, array $groups, array $planGroup, string $salt): array
    {
        $check = array_map(static fn(array $r): array => $r[0] === self::PASSWORD ? [$r[0], $r[1], self::digest($salt, $r[2])] : $r, $check);
        sort($check);
        sort($reply);
        sort($planGroup);
        return ['v' => self::VERSION, 'username' => $username,
            'subscriber' => ['check' => array_values($check), 'reply' => array_values($reply), 'groups' => RadiusRepository::orderGroups($groups)],
            'plan_group' => array_values($planGroup)];
    }

    public static function digest(string $salt, string $value): string
    {
        return 'sha256:' . hash('sha256', $salt . "\0" . $value);
    }

    /** Whole projection equality (subscriber and plan group). */
    public static function same(array $a, array $b): bool
    {
        return self::normal($a) === self::normal($b);
    }

    /** Subscriber-only equality (the rows a rollback restores). */
    public static function sameSubscriber(array $a, array $b): bool
    {
        return ($a['username'] ?? null) === ($b['username'] ?? null) && self::normal($a)['subscriber'] === self::normal($b)['subscriber'];
    }

    /**
     * Names of the components that differ (e.g. "check:Expiration", "groups", "plan_group:Mikrotik-Rate-Limit"),
     * never their values.
     *
     * @return list<string>
     */
    public static function diff(array $a, array $b): array
    {
        $a = self::normal($a);
        $b = self::normal($b);
        $out = [];
        if ($a['username'] !== $b['username']) {
            $out[] = 'username';
        }
        foreach (['check' => $a['subscriber']['check'], 'reply' => $a['subscriber']['reply'], 'plan_group' => $a['plan_group']] as $part => $rows) {
            $other = $part === 'plan_group' ? $b['plan_group'] : $b['subscriber'][$part];
            $names = array_unique(array_merge(array_column($rows, 0), array_column($other, 0)));
            sort($names);
            foreach ($names as $name) {
                $x = array_values(array_filter($rows, static fn(array $r): bool => $r[0] === $name));
                $y = array_values(array_filter($other, static fn(array $r): bool => $r[0] === $name));
                if ($x !== $y) {
                    $out[] = $part . ':' . $name;
                }
            }
        }
        if ($a['subscriber']['groups'] !== $b['subscriber']['groups']) {
            $out[] = 'groups';
        }
        return $out;
    }

    /** Canonical form of a stored (JSON-decoded) projection; malformed input never equals a real state. */
    private static function normal(array $p): array
    {
        $rows = static function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $r) {
                if (is_array($r) && count($r) === 3) {
                    $out[] = [(string) $r[0], (string) $r[1], (string) $r[2]];
                }
            }
            sort($out);
            return $out;
        };
        $groups = [];
        foreach (is_array($p['subscriber']['groups'] ?? null) ? $p['subscriber']['groups'] : [] as $g) {
            if (is_array($g) && count($g) === 2) {
                $groups[] = [(string) $g[0], (int) $g[1]];
            }
        }
        return ['v' => (int) ($p['v'] ?? 0), 'username' => (string) ($p['username'] ?? ''),
            'subscriber' => ['check' => $rows($p['subscriber']['check'] ?? null), 'reply' => $rows($p['subscriber']['reply'] ?? null), 'groups' => RadiusRepository::orderGroups($groups)],
            'plan_group' => $rows($p['plan_group'] ?? null)];
    }
}
