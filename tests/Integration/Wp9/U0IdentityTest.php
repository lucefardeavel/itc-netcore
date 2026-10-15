<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/U0Fixture.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * WP9 U0 identity matrix (sequential; test-first). Every acquirer P1–P6 against every identity class:
 *   customer username (other case) | customer PPPoE username (cross-field) | stored leading-space username | stored
 *   trailing-TAB PPPoE username | accent | live voucher-only identity (granted) | CLAIMED voucher | voucher-only recharge
 *   row (customer_id 0) | leftover RADIUS rows only;
 * expected REFUSED with NO unauthorized business / finance / identity / service state change (AM-W9-2; audit rows and
 * log lines allowed). Positive controls: a fresh name and an UNUSED voucher code (not an identity) are accepted.
 * Plus: case-only self-rename, same-customer username = PPPoE (I-2), grandfathered duplicates editable, the P5 revert
 * (P5r) never reverts onto a name taken meanwhile, RBAC of P1 (Admin / Agent / Sales), generic public messages.
 */
final class U0IdentityTest extends RadiusHttpTestCase
{
    use U0Fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::u0Setup();
    }

    /** builds the existing identity of class $class whose canonical name is $x; returns [description, guard closure for its RADIUS state] */
    private function existing(string $class, string $x): array
    {
        switch ($class) {
            case 'customer username (other case)':
                $c = $this->person(strtoupper($x));
                $this->provision($c['id']);
                return [strtoupper($x)];
            case 'customer PPPoE username (cross-field)':
                $c = $this->person(self::tag(), $x);
                $this->provision($c['id'], self::$pppoe);
                return [$x];
            case 'stored leading-space username':
                $c = $this->person(' ' . $x);
                $this->provision($c['id']);
                return [$x];
            case 'stored trailing-TAB PPPoE username':
                $c = $this->person(self::tag(), $x . "\t");
                $this->provision($c['id'], self::$pppoe);
                return [$x];
            case 'accent':
                $c = $this->person($x . 'é');
                $this->provision($c['id']);
                return [$x . 'é'];
            case 'live voucher-only identity':
                $this->voucher($x);
                $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($x)), 'fixture: the voucher-only identity is live');
                return [$x];
            case 'CLAIMED voucher':
                static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'radius', 'id_plan' => self::$hotspot, 'code' => $x,
                    'user' => '0', 'status' => 'CLAIMED', 'generated_by' => $this->sid('ad')]);
                return [];
            case 'voucher-only recharge row':
                static::$db->insertRow('tbl_user_recharges', ['customer_id' => 0, 'username' => $x, 'plan_id' => self::$hotspot, 'namebp' => 'w9u0',
                    'recharged_on' => date('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => date('Y-m-d', time() + 86400), 'time' => '23:59:59',
                    'status' => 'on', 'method' => 'Voucher - legacy', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 0]);
                return [];
            case 'leftover RADIUS rows':
                static::$db->insertRow('radcheck', ['username' => $x, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'leftover-' . bin2hex(random_bytes(3))]);
                return [$x];
        }
        throw new \LogicException($class);
    }

    public static function matrix(): array
    {
        $classes = ['customer username (other case)', 'customer PPPoE username (cross-field)', 'stored leading-space username', 'stored trailing-TAB PPPoE username',
            'accent', 'live voucher-only identity', 'CLAIMED voucher', 'voucher-only recharge row', 'leftover RADIUS rows'];
        $subset = ['customer PPPoE username (cross-field)', 'live voucher-only identity', 'leftover RADIUS rows'];
        $out = [];
        foreach (['P1 Admin', 'P1 Agent', 'P1 Sales', 'P2', 'P3', 'P4', 'P5', 'P6', 'P6 RADIUS'] as $w) {
            foreach (in_array($w, ['P1 Agent', 'P1 Sales', 'P6 RADIUS'], true) ? $subset : $classes as $c) {
                $out["{$w} vs {$c}"] = [$w, $c];
            }
        }
        return $out;
    }

    /** the acquirer's own fixture (its voucher / customer row), created BEFORE the state snapshot */
    private function prepare(string $w, string $x): array
    {
        return match ($w) {
            'P3' => ['code' => $this->voucherFor('W9U0M' . strtoupper(bin2hex(random_bytes(4))))],
            'P4' => ['customer' => $this->person(self::tag()), 'sa' => $this->staff('sa')],
            'P5' => ['customer' => $this->person(self::tag(), '', 'A')],
            'P6', 'P6 RADIUS' => ['vid' => $this->voucher($x)],
            default => [],
        };
    }

    private function voucherFor(string $code): string
    {
        $this->voucher($code);
        return $code;
    }

    /**
     * Runs acquirer $w for name $x (its fixture from prepare()). Returns [accepted, outcome detail].
     *
     * @return array{0:bool,1:mixed}
     */
    private function acquire(string $w, string $x, ?array $ctx = null): array
    {
        $ctx ??= $this->prepare($w, $x);
        switch ($w) {
            case 'P1 Admin':
            case 'P1 Agent':
            case 'P1 Sales':
                $actor = ['P1 Admin' => 'ad', 'P1 Agent' => 'A', 'P1 Sales' => 'A1'][$w];
                $o = (string) static::$app->exec(self::p1Code($actor, $x));
                return [str_starts_with($o, 'CREATED'), $o];
            case 'P2':
                $n = (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE BINARY username = ?', [$x]);
                $s = $this->p2($x);
                return [(int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE BINARY username = ?', [$x]) > $n, 'HTTP ' . $s];
            case 'P3':
                $o = (string) static::$app->exec(self::p3Code($ctx['code'], $x));
                return [$o === 'GRANTED', $o];
            case 'P4':
                $s = $this->p4($ctx['customer'], ['pppoe_username' => $x], $ctx['sa']);
                return [self::customerRow($ctx['customer']['id'])['pppoe_username'] === $x, 'HTTP ' . $s];
            case 'P5':
                $o = (string) static::$app->exec(self::p5Code($ctx['customer']['id'], ['username' => $x]));
                return [str_starts_with($o, 'ACCEPTED'), $o];
            case 'P6':
            case 'P6 RADIUS':
                $o = (string) static::$app->exec(self::p6Code($x, $w === 'P6 RADIUS'));
                return [$o === 'GRANTED', $o . ' voucher=' . self::vstatus($ctx['vid'])];
        }
        throw new \LogicException($w);
    }

    #[DataProvider('matrix')]
    public function testEveryAcquirerIsRefusedForEveryIdentityClass(string $w, string $class): void
    {
        $x = self::tag();
        $keys = $this->existing($class, $x);
        if ($class === 'accent') {
            $x .= 'e';   // the stored name ends with an accented e (existing())
        }
        $policies = array_combine($keys, array_map(static fn(string $k): array => self::exact($k), $keys)) ?: [];
        $ctx = $this->prepare($w, $x);
        $before = self::state(static::$db);
        [$accepted, $detail] = $this->acquire($w, $x, $ctx);
        $d = self::delta($before, self::state(static::$db));
        $intact = true;
        foreach ($policies as $k => $rows) {
            $intact = $intact && self::exact($k) === $rows;
        }
        $met = !$accepted && $intact && $d['business'] === [];
        self::record("U0 matrix {$w} vs {$class}", $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED',
            ['name' => $x, 'accepted' => $accepted, 'detail' => $detail, 'state_delta' => $d, 'existing_policy_unchanged' => $intact]);
        $this->assertFalse($accepted, "{$w} vs {$class}: refused " . json_encode($detail));
        $this->assertTrue($intact, "{$w} vs {$class}: the existing identity's RADIUS policy is unchanged");
        $this->assertSame([], $d['business'], "{$w} vs {$class}: no business / identity / RADIUS change " . json_encode($d));
    }

    public static function writers(): array
    {
        return ['P1 Admin' => ['P1 Admin'], 'P1 Agent' => ['P1 Agent'], 'P1 Sales' => ['P1 Sales'], 'P2' => ['P2'], 'P3' => ['P3'], 'P4' => ['P4'], 'P5' => ['P5'], 'P6' => ['P6'], 'P6 RADIUS' => ['P6 RADIUS']];
    }

    #[DataProvider('writers')]
    public function testAFreshNameIsAccepted(string $w): void
    {
        $x = self::tag();
        [$accepted, $detail] = $this->acquire($w, $x);
        self::record("U0 control {$w} fresh name", $accepted ? 'CONTROL_PASS' : 'CONTROL_FAIL', ['name' => $x, 'detail' => $detail]);
        $this->assertTrue($accepted, "{$w}: a fresh name is accepted " . json_encode($detail));
    }

    #[DataProvider('writers')]
    public function testAnUnusedVoucherCodeIsNotAnIdentity(string $w): void
    {
        if (str_starts_with($w, 'P6')) {
            $this->assertTrue(true, 'P6 redeems the code itself');
            return;
        }
        $x = self::tag();
        $this->voucher($x);
        [$accepted, $detail] = $this->acquire($w, $x);
        self::record("U0 control {$w} vs unused voucher code", $accepted ? 'CONTROL_PASS' : 'CONTROL_FAIL', ['name' => $x, 'detail' => $detail]);
        $this->assertTrue($accepted, "{$w}: an unused voucher code is not an identity (no enumeration oracle) " . json_encode($detail));
    }

    public function testCaseOnlySelfRenameIsAccepted(): void
    {
        $c = $this->person(self::tag());
        $this->provision($c['id']);
        $s = $this->p4($c, ['username' => strtoupper($c['username'])]);
        $p4 = self::customerRow($c['id'])['username'] === strtoupper($c['username']);
        $o = $this->person(self::tag(), '', 'A');
        $p5 = (string) static::$app->exec(self::p5Code($o['id'], ['username' => strtoupper($o['username'])]));
        self::record('U0 control case-only self-rename', $p4 && str_starts_with($p5, 'ACCEPTED') ? 'CONTROL_PASS' : 'CONTROL_FAIL', ['P4' => 'HTTP ' . $s, 'P4_renamed' => $p4, 'P5' => $p5]);
        $this->assertTrue($p4, 'P4: a case-only rename of the customer itself (own RADIUS rows) is accepted');
        $this->assertStringStartsWith('ACCEPTED', $p5, 'P5: a case-only rename of the customer itself is accepted');
        $this->assertSame($c['password'], self::password(strtoupper($c['username'])), 'P4: the own policy follows the rename');
    }

    public function testSameCustomerUsernameEqualsPppoeIsAccepted(): void
    {
        $x = self::tag();
        $p1 = (string) static::$app->exec(self::p1Code('ad', $x, $x));
        $c = $this->person(self::tag());
        $s = $this->p4($c, ['pppoe_username' => $c['username']]);
        $p4 = self::customerRow($c['id'])['pppoe_username'] === $c['username'];
        self::record('U0 control I-2 username = own PPPoE username', str_starts_with($p1, 'CREATED') && $p4 ? 'CONTROL_PASS' : 'CONTROL_FAIL', ['P1' => $p1, 'P4' => 'HTTP ' . $s]);
        $this->assertStringStartsWith('CREATED', $p1);
        $this->assertTrue($p4);
    }

    public function testGrandfatheredDuplicatesStayEditable(): void
    {
        $x = self::tag();
        $a = $this->person($x);
        $this->person(strtoupper($x));
        $form = $this->p4Data($a, ['fullname' => 'W9 U0 edited']);
        $s = $this->staff('sa')->postWithToken('customers/edit-post', $form)->status;
        $ok = self::customerRow($a['id'])['fullname'] === 'W9 U0 edited';
        self::record('U0 control grandfathered duplicate edit of another field', $ok ? 'CONTROL_PASS' : 'CONTROL_FAIL', ['HTTP' => $s]);
        $this->assertTrue($ok, 'an edit that does not change the identity is never re-checked (I-4)');
    }

    public function testP5RevertNeverRestoresANameTakenMeanwhile(): void
    {
        $x = self::tag();
        $y = self::tag();
        $o = $this->person($x, '', 'A');
        static::$db->insertRow('tbl_user_recharges', ['customer_id' => $o['id'], 'username' => $x, 'plan_id' => self::$failPlan, 'namebp' => 'w9u0_fail',
            'recharged_on' => date('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => date('Y-m-d', time() + 86400), 'time' => '23:59:59',
            'status' => 'on', 'method' => 'Admin - test', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 1]);
        // control: without a competitor the confirmed device failure reverts the identity
        $r0 = (string) static::$app->exec(self::p5Code($o['id'], ['username' => $y]));
        $this->assertSame('REFUSED REPROVISION_FAILED', $r0, 'control: confirmed failure, identity reverted');
        $this->assertSame($x, self::customerRow($o['id'])['username']);
        // the old name X is acquired while the device step is paused; the revert must not restore X
        self::pause('fail');
        $h = static::$app->execStart(self::p5Code($o['id'], ['username' => $y]));
        $this->assertTrue(self::waitUntil(static fn(): bool => self::arrived('fail'), 25), 'barrier: P5 paused in the device step');
        // P2 (no tree lock; P1 would wait for the tree lock P5 holds across its device step)
        $p2 = 'HTTP ' . $this->p2($x);
        self::go('fail');
        $p5 = (string) static::$app->execFinish($h);
        $holders = self::holders($x);
        $met = count($holders) <= 1;
        self::record('U0 P5r revert onto a name taken meanwhile', $met ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED',
            ['P2' => $p2, 'P5' => $p5, 'owned_customer_username_after' => self::customerRow($o['id'])['username'] === $y ? 'Y' : (self::customerRow($o['id'])['username'] === $x ? 'X' : 'other'), 'customers_holding_X' => count($holders)]);
        $this->assertLessThanOrEqual(1, count($holders), 'P5r: one holder of X ' . json_encode([$p2, $p5]));
    }

    public function testPublicRegistrationRefusalIsGeneric(): void
    {
        $customer = self::tag();
        $this->person($customer);
        $voucher = self::tag();
        $this->voucher($voucher);
        $this->assertSame('GRANTED', (string) static::$app->exec(self::p6Code($voucher)));
        $seen = [];
        foreach (['customer' => $customer, 'voucher identity' => $voucher] as $k => $name) {
            $r = $this->client()->postWithToken('register/post', $this->p2Data(strtoupper($name)), [], 'register');
            $seen[$k] = ['status' => $r->status, 'location' => $r->headers['location'][0] ?? null,
                'account_already_exists' => str_contains($r->body, 'Account already exists')];
        }
        $same = $seen['customer']['status'] === $seen['voucher identity']['status'] && $seen['customer']['location'] === $seen['voucher identity']['location']
            && $seen['customer']['account_already_exists'] && $seen['voucher identity']['account_already_exists'];
        self::record('U0 P2 refusal message is generic', $same ? 'CONTRACT_MET' : 'DEFECT_REPRODUCED', ['responses' => $seen]);
        $this->assertTrue($same, 'a voucher identity and a customer are refused with the same generic message ' . json_encode($seen));
        $this->assertSame([], self::holders(strtoupper($voucher)), 'no customer over the voucher identity');
    }
}
