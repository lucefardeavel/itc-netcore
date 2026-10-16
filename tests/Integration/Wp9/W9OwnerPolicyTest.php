<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Activation\ActivationFaults;
use Reseller\Activation\ActivationService;
use Reseller\Domain\CustomerService;
use Reseller\Domain\DomainError;
use Reseller\Domain\StaffService;
use Reseller\Tests\Support\ActivationTestCase;
use Reseller\Tests\Support\FinanceSeed;

/**
 * WP9-B / N-05 + N-15: the owner policy matrix P1-P5 (PLAN §10), conservative option B in each row
 * (owner pre-authorization W9-0). Every negative case has a positive control.
 *  P1 an activation replay never progresses after the actor lost authority over the customer;
 *  P2 an Agent cannot reactivate a sub-account an administrator disabled (it can its own disable);
 *  P3 an Agent resets the password of a direct sub-account only;
 *  P4 only the owning reseller (or an administrator) changes a customer's Sales assignment;
 *  P5 after an ownership transfer the new owner's Sales does not see the previous owner's activations.
 */
final class W9OwnerPolicyTest extends ActivationTestCase
{
    public function testP1ReplayAfterLostAuthorityDoesNotProgress(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $c = $this->customer($leaf);
        $nonce = self::nonce();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.before_execute') {
                throw new \RuntimeException('crash after open');
            }
        };
        try {
            $this->activate($leaf, $c, $plan, 'NEW', $nonce);
            $this->fail('the injected crash did not happen');
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $act = static::$db->rows('SELECT * FROM tbl_reseller_activations WHERE customer_id = ?', [$c])[0];
        $this->assertSame('FUNDS_RESERVED', $act['status']);
        CustomerService::transferOwner($this->ctx('ad'), $c, $top);
        $events = $this->rowCount('tbl_reseller_activation_events', 'activation_id = ?', [(int) $act['id']]);
        $out = $this->activate($leaf, $c, $plan, 'NEW', $nonce);
        $this->assertTrue($out['replayed']);
        $this->assertSame('FUNDS_RESERVED', $out['status'], 'no progression without authority');
        $this->assertSame($events, $this->rowCount('tbl_reseller_activation_events', 'activation_id = ?', [(int) $act['id']]), 'no state change');
        $this->assertSame([], $this->radcheck($this->username($c)), 'no service');

        // positive control: the same crash, authority kept -> the replay completes
        $c2 = $this->customer($leaf);
        $nonce2 = self::nonce();
        ActivationFaults::$hook = static function (string $p): void {
            if ($p === 'activation.before_execute') {
                throw new \RuntimeException('crash after open');
            }
        };
        try {
            $this->activate($leaf, $c2, $plan, 'NEW', $nonce2);
        } catch (\RuntimeException) {
        }
        ActivationFaults::$hook = null;
        $this->assertSame('COMPLETED', $this->activate($leaf, $c2, $plan, 'NEW', $nonce2)['status']);
        // the stranded FUNDS_RESERVED activation stays for an administrator (reservation ACTIVE, invariants hold)
    }

    public function testP2AdminDisabledAccountIsReactivatedByAnAdministratorOnly(): void
    {
        $agent = $this->agent($this->sid('A'));
        StaffService::setStatus($this->ctx('ad'), $agent, 'Inactive');
        $this->refused('STAFF_REACTIVATION_ADMIN_ONLY', fn() => StaffService::setStatus($this->ctx('A'), $agent, 'Active'), false);
        $this->assertSame('Inactive', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$agent]));
        StaffService::setStatus($this->ctx('ad'), $agent, 'Active');
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$agent]), 'positive: administrator');
        StaffService::setStatus($this->ctx('A'), $agent, 'Inactive');
        StaffService::setStatus($this->ctx('A'), $agent, 'Active');
        $this->assertSame('Active', static::$db->value('SELECT status FROM tbl_users WHERE id = ?', [$agent]), 'positive: its own disable');
    }

    /** the unchanged profile fields of a staff account (updateProfile validates them) */
    private function profile(int $id): array
    {
        $r = static::$db->rows('SELECT username, fullname FROM tbl_users WHERE id = ?', [$id])[0];
        return ['username' => $r['username'], 'fullname' => strlen((string) $r['fullname']) >= 3 ? $r['fullname'] : 'Staff ' . $r['username']];
    }

    public function testP3PasswordResetOfDirectSubAccountsOnly(): void
    {
        $child = $this->agent($this->sid('A'));
        $grandchild = $this->agent($child);
        $before = (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$grandchild]);
        $this->refused('STAFF_PASSWORD_DIRECT_CHILD_ONLY', fn() => StaffService::updateProfile($this->ctx('A'), $grandchild, $this->profile($grandchild), 'new-secret-1'), false);
        $this->assertSame($before, (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$grandchild]));
        StaffService::updateProfile($this->ctx('A'), $child, $this->profile($child), 'new-secret-2');
        $this->assertSame(sha1('new-secret-2'), (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$child]), 'positive: direct child');
        StaffService::updateProfile($this->ctx($child), $grandchild, $this->profile($grandchild), 'new-secret-3');
        $this->assertSame(sha1('new-secret-3'), (string) static::$db->value('SELECT password FROM tbl_users WHERE id = ?', [$grandchild]), 'positive: its own direct child');
    }

    public function testP4OnlyTheOwnerReassignsSales(): void
    {
        $child = $this->agent($this->sid('A'));
        $sales = FinanceSeed::staff(static::$db, 'w9p4s' . bin2hex(random_bytes(3)), $child, 'Active', true, 'Sales');
        $c = $this->customer($child);
        $this->refused('SALES_ASSIGN_OWNER_ONLY', fn() => CustomerService::assignSales($this->ctx('A'), $c, $sales), false);
        $this->assertNull(static::$db->value('SELECT assigned_sales_id FROM tbl_customers WHERE id = ?', [$c]));
        CustomerService::assignSales($this->ctx($child), $c, $sales);
        $this->assertSame($sales, (int) static::$db->value('SELECT assigned_sales_id FROM tbl_customers WHERE id = ?', [$c]), 'positive: the owner');
        CustomerService::assignSales($this->ctx('ad'), $c, null);
        $this->assertNull(static::$db->value('SELECT assigned_sales_id FROM tbl_customers WHERE id = ?', [$c]), 'positive: administrator');
        $this->customersChanged();
    }

    public function testP5NewOwnerSalesDoesNotSeePreviousOwnerActivations(): void
    {
        [$plan, [$top, $leaf]] = $this->pricedChain(['12', '15'], '10', '20');
        $topSales = FinanceSeed::staff(static::$db, 'w9p5s' . bin2hex(random_bytes(3)), $top, 'Active', true, 'Sales');
        $c = $this->customer($leaf);
        $old = $this->activate($leaf, $c, $plan, 'NEW');
        $this->assertSame('COMPLETED', $old['status']);
        CustomerService::transferOwner($this->ctx('ad'), $c, $top, $topSales);
        $this->customersChanged();
        $this->expectExceptionReason('NOT_FOUND', fn() => ActivationService::status($this->ctx($topSales), $old['reference']), 'previous owner activation');
        $refs = array_column(\Reseller\Activation\ActivationReadService::list($this->ctx($topSales), [])['rows'] ?? [], 'reference');
        $this->assertNotContains($old['reference'], $refs, 'not listed either');
        $this->assertSame($old['reference'], ActivationService::status($this->ctx($leaf), $old['reference'])['activation']['reference'], 'positive: the payer keeps its history');
        $new = $this->activate($top, $c, $plan, 'RENEW');
        $this->assertSame($new['reference'], ActivationService::status($this->ctx($topSales), $new['reference'])['activation']['reference'], 'positive: the current owner activation');
    }
}
