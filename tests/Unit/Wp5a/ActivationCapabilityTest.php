<?php
namespace Reseller\Tests\Unit\Wp5a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Security\RoleCapabilityMap;

/** The activation capability matrix (owner decision A5), exactly; fail-closed principals. */
final class ActivationCapabilityTest extends TestCase
{
    private const MATRIX = [
        'SuperAdmin' => [C::ACTIVATION_CREATE => 'G', C::ACTIVATION_READ => 'G'],
        'Admin' => [C::ACTIVATION_CREATE => 'G', C::ACTIVATION_READ => 'G'],
        'Agent' => [C::ACTIVATION_CREATE => 'S', C::ACTIVATION_READ => 'T'],
        'Sales' => [C::ACTIVATION_CREATE => 'A', C::ACTIVATION_READ => 'A'],
        'Report' => [C::ACTIVATION_CREATE => null, C::ACTIVATION_READ => null],
    ];

    private static function staff(string $role, array $over = []): ActorContext
    {
        return ActorContext::make($over + [
            'type' => ActorContext::STAFF, 'channel' => 'WEB', 'userId' => 900, 'role' => $role, 'status' => 'Active',
            'enrolled' => in_array($role, ['Agent', 'Sales'], true), 'frozen' => false, 'inconsistent' => false,
            'managerId' => $role === 'Sales' ? 800 : ($role === 'Agent' ? 900 : null), 'ancestors' => [], 'schemaReady' => true,
        ]);
    }

    public function testExactMatrix(): void
    {
        foreach (self::MATRIX as $role => $caps) {
            foreach ($caps as $cap => $scope) {
                $this->assertSame($scope, RoleCapabilityMap::scope($role, $cap), "{$role} / {$cap}");
                $this->assertSame($scope ?? PermissionService::NONE, PermissionService::scopeOf(self::staff($role), $cap), "{$role} / {$cap} effective");
            }
        }
        $this->assertContains(C::ACTIVATION_CREATE, C::MUTATING);
        $this->assertNotContains(C::ACTIVATION_READ, C::MUTATING);
        $this->assertContains(C::ACTIVATION_CREATE, C::SCHEMA_DEPENDENT);
        $this->assertContains(C::ACTIVATION_READ, C::SCHEMA_DEPENDENT);
        $this->assertSame([], C::RESERVED);
    }

    public function testFailClosedPrincipals(): void
    {
        foreach (['SuperAdmin', 'Admin', 'Agent', 'Sales'] as $role) {
            $this->assertFalse(PermissionService::can(self::staff($role, ['schemaReady' => false]), C::ACTIVATION_CREATE), "{$role}: schema not ready");
            $this->assertFalse(PermissionService::can(self::staff($role, ['schemaReady' => false]), C::ACTIVATION_READ), "{$role}: schema not ready");
        }
        foreach (['Agent', 'Sales'] as $role) {
            $this->assertFalse(PermissionService::can(self::staff($role, ['frozen' => true]), C::ACTIVATION_CREATE), "{$role}: frozen cannot activate");
            $this->assertTrue(PermissionService::can(self::staff($role, ['frozen' => true]), C::ACTIVATION_READ), "{$role}: frozen can still read");
        }
        $this->assertFalse(PermissionService::can(self::staff('Sales', ['enrolled' => false]), C::ACTIVATION_CREATE), 'a non-enrolled Sales has no assigned scope');
        $customer = ActorContext::make(['type' => ActorContext::CUSTOMER, 'channel' => 'WEB', 'customerId' => 5, 'schemaReady' => true]);
        $this->assertFalse(PermissionService::can($customer, C::ACTIVATION_CREATE), 'customers do not use the staff activation flow (WP5B)');
    }
}
