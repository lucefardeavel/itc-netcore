<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Radius\ProvisionFailure;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * WP8 S7-F1 (owner decision S7_F1_TARGETED_REMEDIATION_APPROVED): the device default deny
 * (LegacyReprovisionService::assertDeviceAllowed) must judge the identity the device is given - the row's username AND
 * its PPPoE username (the PPPoE / VPN drivers and the RADIUS key of a PPPoE plan use pppoe_username). An unassigned row
 * that carries a reseller-owned customer's key as its PPPoE username - exactly, or in a form the database compares as
 * equal (case, trailing space, accent: utf8mb4_general_ci) - is refused before any device write, on both engines; the
 * legacy behaviour of a non-colliding unassigned customer and the permitted re-provision of the owned customer (per-call
 * permit, ended after the run) are unchanged. The real-router (CHR) side is DriverFailClosedLabTest FC-2 / FC-3b.
 */
final class DeviceGuardKeyCollisionTest extends ActivationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = dirname(__DIR__, 3);
        $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
        foreach (['Package', 'Text'] as $c) {
            require_once $root . "/system/autoload/{$c}.php";
        }
        require_once $root . '/system/devices/Radius.php';
    }

    /** @return array{0:int,1:int,2:int} a reseller-owned customer activated on a RADIUS PPPoE plan: plan, leaf, customer */
    private function owned(array $extra): array
    {
        [$plan, [, $leaf]] = $this->pricedChain(['12', '15'], '10', '20', 'PPPOE');
        $c = $this->customer($leaf, null, $extra);
        $this->assertSame('COMPLETED', $this->activate($leaf, $c, $plan, 'NEW')['status']);
        return [$plan, $leaf, $c];
    }

    /** an unassigned (legacy) customer with an active recharge of $plan */
    private function unassigned(int $plan, array $extra): array
    {
        $id = $this->customer(null, null, $extra + ['pppoe_password' => 'Zz-hijack-' . bin2hex(random_bytes(3))]);
        $this->recharge($id, $plan, '2031-01-01', '10:00:00', 'on', 'PPPOE');
        return static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$id])[0];
    }

    private function row(int $id): array
    {
        return static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$id])[0];
    }

    private function planRow(int $id): array
    {
        return static::$db->rows('SELECT * FROM tbl_plans WHERE id = ?', [$id])[0];
    }

    /** the RADIUS policy tables (every row, exact bytes) */
    private function policy(): string
    {
        $out = [];
        foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
            $out[] = json_encode(static::$db->rows("SELECT * FROM {$t} ORDER BY 1"));
        }
        return hash('sha256', implode("\n", $out));
    }

    /** the policy rows of one exact key */
    private function exact(string $key): array
    {
        return static::$db->rows('SELECT attribute, op, value FROM radcheck WHERE BINARY username = ? ORDER BY attribute', [$key]);
    }

    /** the guard alone (the first statement of every legacy device grant method, pinned by Wp5bSourceGuardTest) */
    private static function guard(array $row): string
    {
        try {
            R::assertDeviceAllowed($row);
            return 'ALLOWED';
        } catch (ProvisionFailure $e) {
            return $e->result->code;
        }
    }

    /** the legacy Radius device grant methods for $row on $plan: their result codes */
    private static function radius(array $row, array $plan): array
    {
        $out = [];
        foreach (['add_customer', 'sync_customer'] as $m) {
            try {
                (new \Radius())->$m($row, $plan);
                $out[$m] = 'ALLOWED';
            } catch (ProvisionFailure $e) {
                $out[$m] = $e->result->code;
            }
        }
        return $out;
    }

    /** @return array<string,array{0:string,1:string}> PPPoE-username variants of $key: [value, how the database compares it] */
    private static function variants(string $key): array
    {
        return [
            'exact' => [$key, 'equal'],
            'upper case' => [strtoupper($key), 'equal (case-insensitive collation)'],
            'trailing space' => [$key . ' ', 'equal (PAD SPACE collation)'],
            'accent' => [str_replace('e', 'é', $key), 'equal (accent-insensitive collation)'],
        ];
    }

    public function testAnUnassignedRowCarryingTheOwnedUsernameAsPppoeUsernameIsRefused(): void
    {
        $key = 'wp8ke' . bin2hex(random_bytes(3));           // contains an "e" for the accent variant
        [$plan, , $o] = $this->owned(['username' => $key]);
        $p = $this->planRow($plan);
        $before = [$this->policy(), $this->exact($key)];
        $this->assertNotSame([], $before[1], 'the owned customer holds a RADIUS policy under its username');
        $results = [];
        foreach (self::variants($key) as $name => [$value]) {
            $u = $this->unassigned($plan, ['pppoe_username' => $value]);
            $this->customersChanged();
            $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE manager_id IS NOT NULL AND username = ?', [$value]),
                "{$name}: the database compares the variant as the owned username");
            $results[$name] = ['guard' => self::guard($u)] + self::radius($u, $p);
        }
        $refused = ['guard' => 'RESELLER_OWNED_REFUSED', 'add_customer' => 'RESELLER_OWNED_REFUSED', 'sync_customer' => 'RESELLER_OWNED_REFUSED'];
        $this->assertSame(['results' => array_fill_keys(array_keys(self::variants($key)), $refused), 'owned_policy_unchanged' => true, 'tables_unchanged' => true],
            ['results' => $results, 'owned_policy_unchanged' => $this->exact($key) === $before[1], 'tables_unchanged' => $this->policy() === $before[0]],
            'every variant is refused before any device write; the owned customer\'s RADIUS policy is unchanged');
        $this->assertSame($o, (int) static::$db->value('SELECT id FROM tbl_customers WHERE username = ? AND manager_id IS NOT NULL', [$key]));
    }

    public function testAnUnassignedRowCarryingTheOwnedPppoeUsernameAsPppoeUsernameIsRefused(): void
    {
        $key = 'wp8pe' . bin2hex(random_bytes(3));
        [$plan] = $this->owned(['pppoe_username' => $key, 'pppoe_password' => 'own-ppp-secret']);
        $p = $this->planRow($plan);
        $before = [$this->policy(), $this->exact($key)];
        $this->assertNotSame([], $before[1], 'the owned customer holds a RADIUS policy under its PPPoE username');
        $results = [];
        foreach (self::variants($key) as $name => [$value]) {
            $u = $this->unassigned($plan, ['pppoe_username' => $value]);
            $this->customersChanged();
            $results[$name] = ['guard' => self::guard($u)] + self::radius($u, $p);
        }
        $refused = ['guard' => 'RESELLER_OWNED_REFUSED', 'add_customer' => 'RESELLER_OWNED_REFUSED', 'sync_customer' => 'RESELLER_OWNED_REFUSED'];
        $this->assertSame(['results' => array_fill_keys(array_keys(self::variants($key)), $refused), 'owned_policy_unchanged' => true, 'tables_unchanged' => true],
            ['results' => $results, 'owned_policy_unchanged' => $this->exact($key) === $before[1], 'tables_unchanged' => $this->policy() === $before[0]],
            'every variant is refused; the owned customer\'s RADIUS policy is unchanged');
        // control (already refused before S7-F1): the owned PPPoE key as the unassigned row's USERNAME
        $u = $this->unassigned($plan, ['username' => strtoupper($key)]);
        $this->customersChanged();
        $this->assertSame('RESELLER_OWNED_REFUSED', self::guard($u));
    }

    public function testANonCollidingUnassignedCustomerKeepsTheLegacyDeviceBehaviour(): void
    {
        [$plan] = $this->owned(['pppoe_username' => 'wp8po' . bin2hex(random_bytes(3))]);
        $free = 'wp8free' . bin2hex(random_bytes(3));
        $u = $this->unassigned($plan, ['pppoe_username' => $free, 'pppoe_password' => 'free-ppp-secret']);
        $this->customersChanged();
        $this->assertSame([], $this->exact($free));
        $this->assertSame(['guard' => 'ALLOWED', 'add_customer' => 'ALLOWED', 'sync_customer' => 'ALLOWED'], ['guard' => self::guard($u)] + self::radius($u, $this->planRow($plan)));
        $policy = array_column($this->exact($free), 'value', 'attribute');
        $this->assertSame('free-ppp-secret', $policy['Cleartext-Password'] ?? null, 'the legacy grant wrote the unassigned customer\'s own PPPoE key');
        // an unassigned customer without a PPPoE username: the empty value is not an identity
        $plain = $this->unassigned($plan, ['pppoe_username' => '', 'pppoe_password' => '']);
        $this->customersChanged();
        $this->assertSame('ALLOWED', self::guard($plain));
    }

    public function testThePermittedReprovisionOfAnOwnedPppoeIdentityStillWorksAndThePermitEnds(): void
    {
        $key = 'wp8pr' . bin2hex(random_bytes(3));
        [$plan, , $o] = $this->owned(['pppoe_username' => $key, 'pppoe_password' => '']);
        $p = $this->planRow($plan);
        $self = \Reseller\Security\ActorContext::forCustomer($this->row($o), 'WEB');
        $this->assertSame('DONE', R::run($this->ctx('ad'), $o, R::ADMIN_SYNC)['status'], 'admin sync of the PPPoE identity');
        $this->assertSame('DONE', R::run($self, $o, R::PASSWORD_CHANGE, ['password' => 'pr-new-secret'])['status'], 'password change');
        $this->assertSame('pr-new-secret', array_column($this->exact($key), 'value', 'attribute')['Cleartext-Password'] ?? null, 'applied to the PPPoE key');
        $renamed = 'wp8pn' . bin2hex(random_bytes(3));
        $r = R::run($this->ctx('ad'), $o, R::IDENTITY_EDIT, ['username' => $this->username($o), 'password' => '', 'pppoe_username' => $renamed]);
        $this->assertSame('DONE', $r['status'], 'identity edit of the PPPoE username');
        $this->assertSame([], $this->exact($key));
        $this->assertNotSame([], $this->exact($renamed));
        $this->customersChanged();
        // the per-call permit ended with the run: the same device call outside run() is refused
        $this->assertSame(['add_customer' => 'RESELLER_OWNED_REFUSED', 'sync_customer' => 'RESELLER_OWNED_REFUSED'], self::radius($this->row($o), $p));
    }
}
