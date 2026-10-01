<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * OD-9 / SEC-02: plugin/{fn} may invoke only a function that a plugin registered as a menu entry AND
 * that the current principal is authorized for. function_exists() alone is never authorization.
 *
 *   admin menu entry    → active staff whose role is in the entry's auth list; an empty list means
 *                         SuperAdmin/Admin only (other roles must be listed explicitly)
 *   customer menu entry → an authenticated customer
 */
final class PluginRegistry
{
    /** @var array<string,list<array{admin:bool,auth:list<string>}>> */
    private static array $entries = [];
    private static bool $captured = false;

    /** Snapshot of the registered menus, taken before system/boot.php discards them. */
    public static function capture(array $menuRegistered): void
    {
        self::$entries = [];
        foreach ($menuRegistered as $m) {
            if (!is_array($m) || !isset($m['function']) || !is_string($m['function'])) {
                continue;
            }
            self::$entries[$m['function']][] = [
                'admin' => (bool) ($m['admin'] ?? false),
                'auth' => array_values(array_filter((array) ($m['auth'] ?? []), 'is_string')),
            ];
        }
        self::$captured = true;
    }

    public static function isAllowed(ActorContext $actor, string $function): bool
    {
        if (!self::$captured || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $function) || !isset(self::$entries[$function])) {
            return false;
        }
        foreach (self::$entries[$function] as $e) {
            if ($e['admin']) {
                if (!$actor->isStaff() || $actor->status !== 'Active') {
                    continue;
                }
                $roles = $e['auth'] === [] ? ['SuperAdmin', 'Admin'] : $e['auth'];
                if (in_array($actor->role, $roles, true)) {
                    return true;
                }
            } elseif ($actor->isCustomer()) {
                return true;
            }
        }
        return false;
    }

    /** RouteGuard hook: unregistered or unauthorized functions are reported as not found. */
    public static function authorize(ActorContext $actor, string $function): void
    {
        if (!self::isAllowed($actor, $function)) {
            RouteGuard::deny(404, 'plugin function not registered/authorized');
        }
    }
}
