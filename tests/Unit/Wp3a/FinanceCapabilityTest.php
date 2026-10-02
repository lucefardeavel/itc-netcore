<?php
namespace Reseller\Tests\Unit\Wp3a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\ActorContext;
use Reseller\Security\Capability as C;
use Reseller\Security\PermissionService;
use Reseller\Security\RoleCapabilityMap;

/**
 * WP3A capability / scope matrix (owner decisions P2/P3), enforced through the accepted WP2
 * PermissionService. Activation stays reserved (pricing is activated by WP3B).
 */
final class FinanceCapabilityTest extends TestCase
{
    private const FINANCE = [C::WALLET_READ, C::LEDGER_READ, C::WALLET_FUND_WITHDRAW, C::WALLET_DEBT_LIMIT, C::WALLET_ADJUST, C::WALLET_REVERSE, C::WALLET_TRANSFER];

    /** role => capability => expected effective scope ('NONE' = denied) */
    private const MATRIX = [
        'SuperAdmin' => ['wallet.read' => 'G', 'ledger.read' => 'G', 'wallet.fund_withdraw' => 'G', 'wallet.debt_limit' => 'G', 'wallet.adjust' => 'G', 'wallet.reverse' => 'G', 'wallet.transfer' => 'G'],
        'Admin' => ['wallet.read' => 'G', 'ledger.read' => 'G', 'wallet.fund_withdraw' => 'G', 'wallet.debt_limit' => 'G', 'wallet.adjust' => 'G', 'wallet.reverse' => 'G', 'wallet.transfer' => 'G'],
        'Agent' => ['wallet.read' => 'T', 'ledger.read' => 'T', 'wallet.fund_withdraw' => 'NONE', 'wallet.debt_limit' => 'NONE', 'wallet.adjust' => 'NONE', 'wallet.reverse' => 'NONE', 'wallet.transfer' => 'C'],
        'Sales' => ['wallet.read' => 'NONE', 'ledger.read' => 'NONE', 'wallet.fund_withdraw' => 'NONE', 'wallet.debt_limit' => 'NONE', 'wallet.adjust' => 'NONE', 'wallet.reverse' => 'NONE', 'wallet.transfer' => 'NONE'],
        'Report' => ['wallet.read' => 'NONE', 'ledger.read' => 'NONE', 'wallet.fund_withdraw' => 'NONE', 'wallet.debt_limit' => 'NONE', 'wallet.adjust' => 'NONE', 'wallet.reverse' => 'NONE', 'wallet.transfer' => 'NONE'],
    ];

    private static function staff(string $role, array $over = []): ActorContext
    {
        return ActorContext::make($over + [
            'type' => ActorContext::STAFF, 'channel' => 'WEB', 'userId' => 10, 'role' => $role, 'status' => 'Active',
            'enrolled' => in_array($role, ['Agent', 'Sales'], true), 'managerId' => $role === 'Agent' ? 10 : ($role === 'Sales' ? 5 : null),
            'schemaReady' => true,
        ]);
    }

    public function testFinanceCapabilitiesAreDefinedAndClassified(): void
    {
        $this->assertSame(['wallet.read', 'ledger.read', 'wallet.fund_withdraw', 'wallet.debt_limit', 'wallet.adjust', 'wallet.reverse', 'wallet.transfer'], self::FINANCE);
        foreach (self::FINANCE as $cap) {
            $this->assertContains($cap, C::all());
            $this->assertNotContains($cap, C::RESERVED, "{$cap} is no longer reserved");
            $this->assertContains($cap, C::SCHEMA_DEPENDENT, "{$cap} depends on the finance schema");
        }
        foreach ([C::WALLET_FUND_WITHDRAW, C::WALLET_DEBT_LIMIT, C::WALLET_ADJUST, C::WALLET_REVERSE, C::WALLET_TRANSFER] as $cap) {
            $this->assertContains($cap, C::MUTATING, "{$cap} mutates");
        }
        $this->assertNotContains(C::WALLET_READ, C::MUTATING);
        $this->assertNotContains(C::LEDGER_READ, C::MUTATING);
    }

    public function testExactRoleMatrix(): void
    {
        foreach (self::MATRIX as $role => $caps) {
            foreach ($caps as $cap => $scope) {
                $this->assertSame($scope, PermissionService::scopeOf(self::staff($role), $cap), "{$role} / {$cap}");
            }
        }
    }

    /**
     * WP3B activated the pricing capabilities (exact matrix: Wp3b/PricingCapabilityTest) and WP5A the activation
     * capabilities (owner decision A5; exact matrix: Wp5a/ActivationCapabilityTest): nothing stays reserved.
     */
    public function testPricingAndActivationStayReserved(): void
    {
        $this->assertSame([], C::RESERVED);
        foreach (RoleCapabilityMap::ROLES as $role) {
            foreach (C::RESERVED as $cap) {
                $this->assertNull(RoleCapabilityMap::scope($role, $cap));
                $this->assertFalse(PermissionService::can(self::staff($role), $cap), "{$role} must not hold {$cap}");
            }
        }
    }

    /** Frozen (self or ancestor inactive) and inconsistent principals keep reads only; non-enrolled Agents nothing. */
    public function testFrozenNonEnrolledInconsistentAndInactive(): void
    {
        $frozen = self::staff('Agent', ['frozen' => true]);
        $this->assertSame('T', PermissionService::scopeOf($frozen, C::WALLET_READ), 'a frozen Agent keeps read visibility');
        $this->assertSame('T', PermissionService::scopeOf($frozen, C::LEDGER_READ));
        $this->assertSame(PermissionService::NONE, PermissionService::scopeOf($frozen, C::WALLET_TRANSFER));
        foreach (self::FINANCE as $cap) {
            $this->assertSame(PermissionService::NONE, PermissionService::scopeOf(self::staff('Agent', ['enrolled' => false]), $cap), "non-enrolled / {$cap}");
            $this->assertSame(PermissionService::NONE, PermissionService::scopeOf(self::staff('Agent', ['inconsistent' => true]), $cap), "inconsistent / {$cap}");
        }
        foreach (['Admin', 'SuperAdmin'] as $role) {
            $inactive = self::staff($role, ['status' => 'Inactive', 'frozen' => true]);
            $this->assertSame('G', PermissionService::scopeOf($inactive, C::WALLET_READ));
            foreach ([C::WALLET_FUND_WITHDRAW, C::WALLET_DEBT_LIMIT, C::WALLET_ADJUST, C::WALLET_REVERSE, C::WALLET_TRANSFER] as $cap) {
                $this->assertSame(PermissionService::NONE, PermissionService::scopeOf($inactive, $cap), "inactive {$role} / {$cap}");
            }
        }
    }

    public function testSchemaNotReadyFailsClosedForEveryRole(): void
    {
        foreach (RoleCapabilityMap::ROLES as $role) {
            foreach (self::FINANCE as $cap) {
                $this->assertFalse(PermissionService::can(self::staff($role, ['schemaReady' => false]), $cap), "{$role} / {$cap}");
            }
        }
    }

    public function testNonStaffPrincipalsHoldNoFinanceCapability(): void
    {
        foreach ([ActorContext::anonymous('WEB'), ActorContext::denied('API'), ActorContext::system('CLI'),
            ActorContext::make(['type' => ActorContext::CUSTOMER, 'customerId' => 3, 'schemaReady' => true]), self::staff('SuperAdmin', ['denied' => true])] as $a) {
            foreach (self::FINANCE as $cap) {
                $this->assertFalse(PermissionService::can($a, $cap));
            }
        }
    }

    /** The cosmetic $_can flags reflect the same matrix (templates never authorize). */
    public function testFlagsReflectTheMatrix(): void
    {
        $agent = PermissionService::flags(self::staff('Agent'));
        $this->assertTrue($agent['wallet.read']);
        $this->assertTrue($agent['wallet.transfer']);
        $this->assertFalse($agent['wallet.fund_withdraw']);
        $sales = PermissionService::flags(self::staff('Sales'));
        foreach (self::FINANCE as $cap) {
            $this->assertFalse($sales[$cap]);
        }
    }
}
