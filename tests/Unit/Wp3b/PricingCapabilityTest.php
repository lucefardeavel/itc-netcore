<?php
namespace Reseller\Tests\Unit\Wp3b;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Security\RoleCapabilityMap;

/** WP3B §12 capability / scope matrix (owner decisions OD-3, OD-4) through the accepted WP2 PermissionService. */
final class PricingCapabilityTest extends TestCase
{
    private const PRICING = [C::PRICING_READ, C::PRICING_BASE_COST, C::PRICING_CHILD_WHOLESALE, C::PRICING_OWN_RETAIL, C::PRICING_CHILD_AVAILABILITY];
    private const MUTATING = [C::PRICING_BASE_COST, C::PRICING_CHILD_WHOLESALE, C::PRICING_OWN_RETAIL, C::PRICING_CHILD_AVAILABILITY];

    private const MATRIX = [
        'SuperAdmin' => ['pricing.read' => 'G', 'pricing.base_cost' => 'G', 'pricing.child_wholesale' => 'G', 'pricing.own_retail' => 'G', 'pricing.child_availability' => 'G'],
        'Admin' => ['pricing.read' => 'G', 'pricing.base_cost' => 'G', 'pricing.child_wholesale' => 'G', 'pricing.own_retail' => 'G', 'pricing.child_availability' => 'G'],
        'Agent' => ['pricing.read' => 'T', 'pricing.base_cost' => 'NONE', 'pricing.child_wholesale' => 'C', 'pricing.own_retail' => 'S', 'pricing.child_availability' => 'C'],
        'Sales' => ['pricing.read' => 'NONE', 'pricing.base_cost' => 'NONE', 'pricing.child_wholesale' => 'NONE', 'pricing.own_retail' => 'NONE', 'pricing.child_availability' => 'NONE'],
        'Report' => ['pricing.read' => 'NONE', 'pricing.base_cost' => 'NONE', 'pricing.child_wholesale' => 'NONE', 'pricing.own_retail' => 'NONE', 'pricing.child_availability' => 'NONE'],
    ];

    private static function staff(string $role, array $over = []): ActorContext
    {
        return ActorContext::make($over + [
            'type' => ActorContext::STAFF, 'channel' => 'WEB', 'userId' => 10, 'role' => $role, 'status' => 'Active',
            'enrolled' => in_array($role, ['Agent', 'Sales'], true), 'managerId' => $role === 'Agent' ? 10 : ($role === 'Sales' ? 5 : null),
            'schemaReady' => true,
        ]);
    }

    public function testClassification(): void
    {
        $this->assertSame([C::ACTIVATION_CREATE, C::ACTIVATION_READ], C::RESERVED, 'only activation stays reserved');
        foreach (self::PRICING as $cap) {
            $this->assertContains($cap, C::all());
            $this->assertContains($cap, C::SCHEMA_DEPENDENT, $cap);
        }
        foreach (self::MUTATING as $cap) {
            $this->assertContains($cap, C::MUTATING, $cap);
        }
        $this->assertNotContains(C::PRICING_READ, C::MUTATING);
    }

    public function testExactRoleMatrix(): void
    {
        $this->assertSame(RoleCapabilityMap::ROLES, array_keys(self::MATRIX));
        foreach (self::MATRIX as $role => $caps) {
            foreach ($caps as $cap => $scope) {
                $this->assertSame($scope, PermissionService::scopeOf(self::staff($role), $cap), "{$role} / {$cap}");
            }
        }
    }

    public function testFrozenInconsistentNonEnrolledAndNotReady(): void
    {
        $frozen = self::staff('Agent', ['frozen' => true]);
        $this->assertSame('T', PermissionService::scopeOf($frozen, C::PRICING_READ), 'a frozen Agent keeps read visibility');
        foreach (self::MUTATING as $cap) {
            $this->assertSame(PermissionService::NONE, PermissionService::scopeOf($frozen, $cap), "frozen / {$cap}");
            $this->assertSame(PermissionService::NONE, PermissionService::scopeOf(self::staff('Agent', ['inconsistent' => true]), $cap), "inconsistent / {$cap}");
        }
        foreach ([C::PRICING_READ, C::PRICING_CHILD_WHOLESALE, C::PRICING_CHILD_AVAILABILITY] as $cap) {
            $this->assertSame(PermissionService::NONE, PermissionService::scopeOf(self::staff('Agent', ['enrolled' => false]), $cap), "non-enrolled / {$cap}");
        }
        foreach (['Admin', 'SuperAdmin'] as $role) {
            foreach (self::MUTATING as $cap) {
                $this->assertSame(PermissionService::NONE, PermissionService::scopeOf(self::staff($role, ['status' => 'Inactive', 'frozen' => true]), $cap), "inactive {$role} / {$cap}");
            }
        }
        foreach (RoleCapabilityMap::ROLES as $role) {
            foreach (self::PRICING as $cap) {
                $this->assertFalse(PermissionService::can(self::staff($role, ['schemaReady' => false]), $cap), "not ready: {$role} / {$cap} (no admin bypass)");
            }
        }
    }

    public function testNonStaffHoldNothing(): void
    {
        foreach ([ActorContext::anonymous('WEB'), ActorContext::denied('API'), ActorContext::system('CLI'),
            ActorContext::make(['type' => ActorContext::CUSTOMER, 'customerId' => 3, 'schemaReady' => true]), self::staff('SuperAdmin', ['denied' => true])] as $a) {
            foreach (self::PRICING as $cap) {
                $this->assertFalse(PermissionService::can($a, $cap));
            }
        }
    }
}
