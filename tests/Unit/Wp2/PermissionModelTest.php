<?php
namespace Reseller\Tests\Unit\Wp2;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Security\RoleCapabilityMap;

/** WP2 §3: role → capability → scope map and the PermissionService truth table. */
final class PermissionModelTest extends TestCase
{
    private static function staff(string $role, array $over = []): ActorContext
    {
        return ActorContext::make($over + [
            'type' => ActorContext::STAFF, 'channel' => 'WEB', 'userId' => 10, 'role' => $role, 'status' => 'Active',
            'enrolled' => in_array($role, ['Agent', 'Sales'], true), 'managerId' => $role === 'Sales' ? 5 : ($role === 'Agent' ? 10 : null),
            'schemaReady' => true,
        ]);
    }

    public function testEveryMappedCapabilityIsDefinedAndEveryScopeKnown(): void
    {
        $all = C::all();
        foreach (RoleCapabilityMap::map() as $role => $caps) {
            $this->assertContains($role, RoleCapabilityMap::ROLES);
            foreach ($caps as $cap => $scope) {
                $this->assertContains($cap, $all, "{$role}: unknown capability {$cap}");
                $this->assertContains($scope, RoleCapabilityMap::SCOPES, "{$role}/{$cap}");
            }
        }
        foreach ([C::MUTATING, C::SCHEMA_DEPENDENT, C::RESERVED] as $list) {
            foreach ($list as $cap) {
                $this->assertContains($cap, $all);
            }
        }
    }

    public function testReservedFinanceCapabilitiesAreNeverGranted(): void
    {
        foreach (RoleCapabilityMap::ROLES as $role) {
            foreach (C::RESERVED as $cap) {
                $this->assertNull(RoleCapabilityMap::scope($role, $cap), "{$role} must not hold {$cap} in WP2");
                $this->assertFalse(PermissionService::can(self::staff($role), $cap));
            }
        }
    }

    /** OD-11: Agent/Sales hold no legacy service or sync capability. */
    public function testAgentAndSalesHaveNoServiceCapability(): void
    {
        foreach (['Agent', 'Sales'] as $role) {
            foreach (C::all() as $cap) {
                if (str_starts_with($cap, 'service.') || $cap === C::CUSTOMER_SYNC || $cap === C::VOUCHER_GENERATE
                    || $cap === C::CUSTOMER_UPDATE || $cap === C::CUSTOMER_DELETE || $cap === C::CUSTOMER_EXPORT
                    || $cap === C::CUSTOMER_TRANSFER_OWNER || $cap === C::CUSTOMER_ASSIGN_LEGACY_OWNER
                    || $cap === C::REPORT_READ || $cap === C::MESSAGE_BULK || str_starts_with($cap, 'system.')) {
                    $this->assertFalse(PermissionService::can(self::staff($role), $cap), "{$role} must not hold {$cap}");
                }
            }
        }
    }

    /** OD-4: Report keeps only dashboard, own profile/password, self staff read, API access. */
    public function testReportHasNoDataAccess(): void
    {
        $granted = [];
        foreach (C::all() as $cap) {
            if (PermissionService::can(self::staff('Report'), $cap)) {
                $granted[] = $cap;
            }
        }
        sort($granted);
        $this->assertSame([C::API_ACCESS, C::DASHBOARD_VIEW, C::SELF_CHANGE_PASSWORD, C::SELF_UPDATE_PROFILE, C::STAFF_READ], $granted);
        $this->assertSame('S', PermissionService::scopeOf(self::staff('Report'), C::STAFF_READ));
    }

    /** OD-12 / OD-10 / OD-2. */
    public function testAdminSuperAdminBoundary(): void
    {
        foreach ([C::SYSTEM_CODE, C::SYSTEM_BACKUP, C::SYSTEM_RESTORE] as $cap) {
            $this->assertTrue(PermissionService::can(self::staff('SuperAdmin'), $cap));
            $this->assertFalse(PermissionService::can(self::staff('Admin'), $cap), "Admin must not hold {$cap}");
        }
        foreach (RoleCapabilityMap::ROLES as $role) {
            $this->assertFalse(PermissionService::can(self::staff($role), C::SYSTEM_UPDATE), 'the web updater is disabled for everyone');
        }
        $this->assertTrue(PermissionService::can(self::staff('Admin'), C::SYSTEM_COUPONS));
        $this->assertFalse(PermissionService::can(self::staff('Sales'), C::SYSTEM_COUPONS), 'OD-10');
        // OD-12: the Pages editor is readable by Admin, but writing / resetting templates is system.code
        $this->assertTrue(PermissionService::can(self::staff('Admin'), \Reseller\Security\RouteMap::ROUTES['pages']['page']['GET']));
        foreach (['page-post', 'page-reset'] as $key) {
            $rule = \Reseller\Security\RouteMap::ROUTES['pages'][$key]['POST'];
            $this->assertFalse(PermissionService::can(self::staff('Admin'), $rule), "Admin must not hold pages {$key}");
            $this->assertTrue(PermissionService::can(self::staff('SuperAdmin'), $rule), "SuperAdmin holds pages {$key}");
        }
    }

    public function testScopesByRole(): void
    {
        $this->assertSame('G', PermissionService::scopeOf(self::staff('SuperAdmin'), C::CUSTOMER_READ));
        $this->assertSame('G', PermissionService::scopeOf(self::staff('Admin'), C::CUSTOMER_READ));
        $this->assertSame('T', PermissionService::scopeOf(self::staff('Agent'), C::CUSTOMER_READ));
        $this->assertSame('A', PermissionService::scopeOf(self::staff('Sales'), C::CUSTOMER_READ));
        $this->assertSame(PermissionService::NONE, PermissionService::scopeOf(self::staff('Report'), C::CUSTOMER_READ));
        $this->assertSame('C', PermissionService::scopeOf(self::staff('Agent'), C::STAFF_CREATE));
        $this->assertSame('D', PermissionService::scopeOf(self::staff('Agent'), C::STAFF_DISABLE), 'OD-13');
        $this->assertSame('T', PermissionService::scopeOf(self::staff('Agent'), C::CUSTOMER_ASSIGN_SALES), 'OD-7');
    }

    /** C20: a non-enrolled Agent/Sales has an empty tree scope; inconsistent → no tree scope. */
    public function testNonEnrolledAndInconsistentHaveNoTreeScope(): void
    {
        foreach (['Agent', 'Sales'] as $role) {
            $this->assertFalse(PermissionService::can(self::staff($role, ['enrolled' => false]), C::CUSTOMER_READ));
            $this->assertFalse(PermissionService::can(self::staff($role, ['inconsistent' => true]), C::CUSTOMER_READ));
            $this->assertTrue(PermissionService::can(self::staff($role, ['enrolled' => false]), C::SELF_CHANGE_PASSWORD));
        }
    }

    /** Frozen (self or ancestor inactive): read capabilities only. */
    public function testFrozenActorKeepsReadOnly(): void
    {
        $frozen = self::staff('Agent', ['frozen' => true]);
        $this->assertTrue(PermissionService::can($frozen, C::CUSTOMER_READ));
        foreach ([C::CUSTOMER_CREATE, C::STAFF_CREATE, C::STAFF_DISABLE, C::CUSTOMER_ASSIGN_SALES, C::MESSAGE_SEND, C::INVOICE_SEND] as $cap) {
            $this->assertFalse(PermissionService::can($frozen, $cap), $cap);
        }
        $this->assertFalse(PermissionService::can(self::staff('SuperAdmin', ['status' => 'Inactive', 'frozen' => true]), C::SYSTEM_ADMIN));
    }

    /** Owner schema-readiness rule: dependent capabilities fail closed for EVERY role. */
    public function testSchemaNotReadyFailsClosedForEveryRole(): void
    {
        foreach (RoleCapabilityMap::ROLES as $role) {
            $a = self::staff($role, ['schemaReady' => false]);
            foreach (C::SCHEMA_DEPENDENT as $cap) {
                $this->assertFalse(PermissionService::can($a, $cap), "{$role}/{$cap} must fail closed when not ready");
            }
            $this->assertTrue(PermissionService::can($a, C::SELF_CHANGE_PASSWORD), "{$role} keeps own password");
            $this->assertTrue(PermissionService::can($a, C::DASHBOARD_VIEW), "{$role} keeps the dashboard");
        }
        $this->assertFalse(PermissionService::can(self::staff('Agent', ['schemaReady' => false]), C::CUSTOMER_READ));
        $this->assertTrue(PermissionService::can(self::staff('Admin', ['schemaReady' => false]), C::SYSTEM_ADMIN), 'pure legacy administration remains');
    }

    public function testNonStaffAndDeniedContextsHoldNothing(): void
    {
        foreach ([ActorContext::anonymous('WEB'), ActorContext::denied('WEB'), ActorContext::make(['type' => ActorContext::CUSTOMER, 'customerId' => 3]),
            self::staff('SuperAdmin', ['denied' => true])] as $a) {
            foreach (C::all() as $cap) {
                $this->assertFalse(PermissionService::can($a, $cap));
            }
        }
    }
}
