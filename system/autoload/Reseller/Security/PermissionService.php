<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Single authority for "may this principal use this capability, and over which scope" (WP2 §5).
 * Hooks and plugins are never consulted (C11).
 */
final class PermissionService
{
    public const NONE = 'NONE';

    /** Effective scope after status, freeze, enrolment, consistency and schema readiness rules. */
    public static function scopeOf(ActorContext $a, string $capability): string
    {
        if (!$a->isStaff()) {
            return self::NONE;
        }
        $scope = RoleCapabilityMap::scope($a->role, $capability);
        if ($scope === null) {
            return self::NONE;
        }
        $treeScoped = in_array($scope, ['T', 'D', 'C', 'A'], true);
        if (!$a->schemaReady) {
            // owner schema-readiness rule: dependent capabilities fail closed for every role;
            // Agent/Sales keep only self-scoped capabilities (dashboard, profile, password)
            if (in_array($capability, Capability::SCHEMA_DEPENDENT, true) || $treeScoped) {
                return self::NONE;
            }
        }
        if ($treeScoped && (!$a->enrolled || $a->inconsistent)) {
            return self::NONE; // C20: a non-enrolled Agent/Sales has an empty tree scope
        }
        if (in_array($capability, Capability::PLACEMENT_REQUIRED, true) && in_array($a->role, ['Agent', 'Sales'], true)
            && (!$a->enrolled || $a->inconsistent || $a->managerId === null)) {
            return self::NONE; // acts for a reseller identity: only a validly placed Agent / Sales (WP5A)
        }
        if (in_array($capability, Capability::MUTATING, true) && !$a->canMutate()) {
            return self::NONE; // frozen (self or ancestor not Active) / inconsistent: read only
        }
        return $scope;
    }

    public static function can(ActorContext $a, string $capability): bool
    {
        return self::scopeOf($a, $capability) !== self::NONE;
    }

    /** Ends the request with the uniform denial (403 page / API success:false) unless allowed. */
    public static function require(ActorContext $a, string $capability): string
    {
        $scope = self::scopeOf($a, $capability);
        if ($scope === self::NONE) {
            RouteGuard::deny(403, 'capability ' . $capability);
        }
        return $scope;
    }

    /** @return array<string,bool> capability => allowed, for cosmetic template use only */
    public static function flags(ActorContext $a): array
    {
        $out = [];
        foreach (Capability::all() as $c) {
            $out[$c] = self::can($a, $c);
        }
        return $out;
    }
}
