<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * R-HF-CARRY-01 residual (RBAC matrix F-2): autoload/pppoe_ip_used and autoload/pppoe_username_used ran an
 * UNSCOPED existence check. Names were hidden for tree-scoped staff, but the generic "has been used by another
 * customer" answer still told an Agent / Sales whether a static IP or PPPoE username exists ANYWHERE in the
 * instance (other trees, unassigned customers). Tree-scoped staff are now answered only about customers inside
 * their own CUSTOMER_READ scope; an out-of-scope match answers exactly like a value nobody uses.
 *
 * Positive controls: an in-scope collision still gets the generic warning (no names); SuperAdmin / Admin keep the
 * named answer for any customer; Report (no customer.read) is refused at the route; the server-side PPPoE username
 * uniqueness on save (customers/add-post, global RADIUS namespace) still refuses an out-of-scope collision.
 */
final class W9AutoloadPppoeScopeTest extends Wp2HttpTestCase
{
    /** customer => [pppoe_username, pppoe_ip] */
    private const VALUES = [
        'cA1' => ['w9pp_a1', '10.77.0.1'], 'cA2' => ['w9pp_a2', '10.77.0.2'], 'cB' => ['w9pp_b', '10.77.0.3'],
        'cC1' => ['w9pp_c1', '10.77.0.4'], 'cNULL' => ['w9pp_null', '10.77.0.5'],
    ];

    /** actor => [in-scope customers, out-of-scope customers] (Agent T: own subtree; Sales A: own assignments) */
    private const SCOPES = [
        'A' => [['cA2', 'cB'], ['cC1', 'cNULL']],
        'B' => [['cB'], ['cA1', 'cC1', 'cNULL']],
        'A1' => [['cA1'], ['cA2', 'cB', 'cC1', 'cNULL']],
        'C1' => [['cC1'], ['cA1', 'cB', 'cNULL']],
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (self::VALUES as $u => [$pp, $ip]) {
            static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_username = ?, pppoe_ip = ? WHERE username = ?')->execute([$pp, $ip, $u]);
        }
    }

    /** @return array{ip:string,u:string} the two answers for one customer's values */
    private function probe(\Reseller\Tests\Support\HttpClient $c, string $ip, string $pp, int $id = 0): array
    {
        $a = $c->get('autoload/pppoe_ip_used', ['ip' => $ip, 'id' => $id]);
        $b = $c->get('autoload/pppoe_username_used', ['u' => $pp, 'id' => $id]);
        $this->assertSame(200, $a->status, $a->excerpt());
        $this->assertSame(200, $b->status, $b->excerpt());
        return ['ip' => $a->body, 'u' => $b->body];
    }

    public function testTreeScopedStaffLearnNothingAboutOutOfScopeIpOrPppoeUsername(): void
    {
        foreach (self::SCOPES as $who => [, $out]) {
            $c = $this->staff($who);
            $unused = $this->probe($c, '10.77.9.' . random_int(10, 250), 'w9pp_unused_' . bin2hex(random_bytes(3)));
            $this->assertStringNotContainsString('used by', $unused['ip'] . $unused['u'], "{$who}: control, an unused value");
            foreach ($out as $owner) {
                [$pp, $ip] = self::VALUES[$owner];
                $r = $this->probe($c, $ip, $pp, $this->cid('cA0'));
                $this->assertSame($unused['ip'], $r['ip'], "{$who}: the static IP of out-of-scope {$owner} must answer like an unused IP");
                $this->assertSame($unused['u'], $r['u'], "{$who}: the PPPoE username of out-of-scope {$owner} must answer like an unused name");
            }
        }
    }

    public function testApiAnswersTheSameWayForTreeScopedStaff(): void
    {
        $token = $this->apiToken('A');
        $ask = fn(string $action, array $q) => $this->client()->api('GET', 'autoload/' . $action, [], $token, $q)->body;
        $unusedU = $ask('pppoe_username_used', ['u' => 'w9pp_unused_' . bin2hex(random_bytes(3)), 'id' => 0]);
        $unusedIp = $ask('pppoe_ip_used', ['ip' => '10.77.9.' . random_int(10, 250), 'id' => 0]);
        $this->assertSame($unusedU, $ask('pppoe_username_used', ['u' => self::VALUES['cC1'][0], 'id' => 0]), 'API: out-of-scope PPPoE username');
        $this->assertSame($unusedIp, $ask('pppoe_ip_used', ['ip' => self::VALUES['cC1'][1], 'id' => 0]), 'API: out-of-scope static IP');
        $in = $ask('pppoe_username_used', ['u' => self::VALUES['cA2'][0], 'id' => 0]);
        $this->assertStringContainsString('has been used by another customer', $in, 'API positive control: in-scope collision is warned');
    }

    public function testTreeScopedStaffAreStillWarnedAboutAnInScopeCollisionWithoutNames(): void
    {
        foreach (self::SCOPES as $who => [$in]) {
            $c = $this->staff($who);
            foreach ($in as $owner) {
                [$pp, $ip] = self::VALUES[$owner];
                $r = $this->probe($c, $ip, $pp);
                $this->assertStringContainsString('IP has been used by another customer', $r['ip'], "{$who}: in-scope {$owner} IP collision");
                $this->assertStringContainsString('Username has been used by another customer', $r['u'], "{$who}: in-scope {$owner} name collision");
                $this->assertStringNotContainsString($owner, $r['ip'] . $r['u'], "{$who}: names stay hidden for tree scopes");
                // the customer being edited is never its own collision
                $self = $this->probe($c, $ip, $pp, $this->cid($owner));
                $this->assertStringNotContainsString('used by', $self['ip'] . $self['u'], "{$who}: {$owner} against itself");
            }
        }
    }

    public function testGlobalStaffKeepTheNamedAnswerForAnyCustomer(): void
    {
        foreach (['sa', 'ad'] as $who) {
            $c = $this->staff($who);
            foreach (['cC1', 'cNULL', 'cA1'] as $owner) {
                [$pp, $ip] = self::VALUES[$owner];
                $r = $this->probe($c, $ip, $pp);
                $this->assertStringContainsString('IP has been used by', $r['ip'], "{$who}: {$owner} IP");
                $this->assertStringContainsString($owner, $r['ip'], "{$who}: {$owner} named (IP)");
                $this->assertStringContainsString('Username has been used by', $r['u'], "{$who}: {$owner} name");
                $this->assertStringContainsString($owner, $r['u'], "{$who}: {$owner} named (PPPoE username)");
            }
        }
    }

    public function testReportStaffAreRefusedAtTheRoute(): void
    {
        $c = $this->staff('rp');
        foreach (['pppoe_ip_used' => ['ip' => self::VALUES['cC1'][1]], 'pppoe_username_used' => ['u' => self::VALUES['cC1'][0]]] as $action => $q) {
            $r = $c->get('autoload/' . $action, $q + ['id' => 0]);
            $this->assertStringNotContainsString('used by', $r->body, "rp: {$action}");
        }
    }

    public function testSaveTimePppoeUsernameUniquenessIsStillEnforcedAcrossScopes(): void
    {
        $c = $this->staff('A');
        $tag = bin2hex(random_bytes(3));
        $form = fn(string $username, string $pp) => [
            'username' => $username, 'fullname' => 'Uniq ' . $tag, 'password' => 'Pw' . $tag . 'Secret',
            'pppoe_username' => $pp, 'pppoe_password' => 'Pp' . $tag . 'Secret', 'pppoe_ip' => '',
            'email' => 'uniq' . $tag . '@example.invalid', 'address' => '', 'phonenumber' => '0790123456',
            'service_type' => 'PPPoE', 'account_type' => 'Personal', 'coordinates' => '',
        ];
        $before = (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers');
        // the PPPoE username of cC1 (another tree): the autoload hint is silent for A, the save still refuses it
        $r = $c->postWithToken('customers/add-post', $form('w9uq_' . $tag, self::VALUES['cC1'][0]), [], 'customers/add');
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame($before, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers'), 'an out-of-scope PPPoE username collision is refused on save');
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE pppoe_username = ?', [self::VALUES['cC1'][0]]));
        // positive control: the same form with an unused PPPoE username is saved (the refusal is the collision)
        $r = $c->postWithToken('customers/add-post', $form('w9uq2_' . $tag, 'w9pp_free_' . $tag), [], 'customers/add');
        $this->assertSame(302, $r->status, $r->excerpt());
        $this->assertSame($before + 1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers'), 'positive control: a free PPPoE username is saved');
        $this->assertSame($this->sid('A'), (int) static::$db->value('SELECT manager_id FROM tbl_customers WHERE username = ?', ['w9uq2_' . $tag]));
    }
}
