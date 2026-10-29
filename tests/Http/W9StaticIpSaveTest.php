<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\HttpClient;
use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * R-HF-CARRY-01 round 2, review finding F-2: the static (PPPoE) IP was saved after a plain trim(), with no format and
 * no uniqueness check, on every save path, and RadiusPolicyBuilder hands a valid IPv4 value out as Framed-IP-Address.
 * An Agent could give its own customer the static IP of a customer in another tree (duplicate remote-address).
 *
 * Required on every save path that sets the static IP (customers/add-post web + API -> CustomerService::create; Admin
 * customers/edit-post of an unassigned customer -> legacy save; of a reseller-owned customer ->
 * LegacyReprovisionService IDENTITY_EDIT): '' (none) or a valid IPv4 address, held by no other customer (system-wide,
 * every role). Tree-scoped staff get the same generic refusal as for any other identity collision (no name). An edit
 * that keeps the customer's own IP (also a legacy duplicate or legacy text) still saves.
 */
final class W9StaticIpSaveTest extends Wp2HttpTestCase
{
    /** customer => static IP */
    private const IPS = ['cA1' => '10.66.0.1', 'cC1' => '10.66.0.3', 'cNULL' => '10.66.0.5'];
    private const INVALID = ['not-an-ip', '10.0.0.256', '0.0.0.0', '10.0.0.1/24', '010.0.0.1', '10.0.0.1"><x-ip>'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (self::IPS as $u => $ip) {
            static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_ip = ? WHERE username = ?')->execute([$ip, $u]);
        }
    }

    /** @return array<string,mixed> a complete, valid add form */
    private static function form(string $username, string $ip, string $tag): array
    {
        return [
            'username' => $username, 'fullname' => 'Ip ' . $tag, 'password' => 'Pw' . $tag . 'Secret',
            'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => $ip,
            'email' => 'ip' . $tag . '@example.invalid', 'address' => '', 'phonenumber' => '0790123456',
            'service_type' => 'PPPoE', 'account_type' => 'Personal', 'coordinates' => '',
        ];
    }

    /** status, redirect target and the flash message of a web POST */
    private function outcome(HttpClient $c, string $route, array $form, string $tokenPage): string
    {
        $r = $c->postWithToken($route, $form, [], $tokenPage);
        $flash = '';
        if ($r->status === 302) {
            $page = $c->get(str_contains((string) $r->location(), 'customers/edit/') ? 'customers/edit/' . (int) $form['id'] : 'customers/add');
            if (preg_match("/icon: '(success|warning|error)',\\s*title: '((?:[^'\\\\]|\\\\.)*)'/", $page->body, $m)) {
                $flash = $m[1] . ': ' . $m[2];
            }
        }
        return $r->status . ' ' . (string) $r->location() . ' | ' . $flash;
    }

    private function add(HttpClient $c, array $form): string
    {
        return $this->outcome($c, 'customers/add-post', $form, 'customers/add');
    }

    private function customerCount(): int
    {
        return (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers');
    }

    private function ipOf(string $username): string
    {
        return (string) static::$db->value('SELECT pppoe_ip FROM tbl_customers WHERE username = ?', [$username]);
    }

    public function testAddRefusesAnInvalidStaticIp(): void
    {
        foreach (['A', 'A1', 'ad'] as $who) {
            $c = $this->staff($who);
            foreach (self::INVALID as $ip) {
                $tag = bin2hex(random_bytes(3));
                $before = $this->customerCount();
                $out = $this->add($c, self::form('w9ip_' . $tag, $ip, $tag));
                $this->assertSame($before, $this->customerCount(), "{$who}: static IP " . json_encode($ip) . ' must be refused: ' . $out);
                $this->assertStringContainsString('error: ', $out, "{$who}: a refusal for " . json_encode($ip));
                $this->assertStringContainsString('IPv4', $out, "{$who}: the refusal names the rule");
            }
            // positive controls: a free, valid IPv4 address and no address are saved
            $tag = bin2hex(random_bytes(3));
            $ip = '10.66.' . random_int(100, 199) . '.' . random_int(1, 254);
            $this->assertStringContainsString('customers/view/', $this->add($c, self::form('w9ip_ok' . $tag, $ip, $tag)), "{$who}: a free IPv4 address is saved");
            $this->assertSame($ip, $this->ipOf('w9ip_ok' . $tag));
            $this->assertStringContainsString('customers/view/', $this->add($c, self::form('w9ip_no' . $tag, '', $tag)), "{$who}: no static IP is saved");
            $this->assertSame('', $this->ipOf('w9ip_no' . $tag));
        }
    }

    public function testAddRefusesTheStaticIpOfAnotherCustomerWithTheGenericIdentityAnswer(): void
    {
        foreach (['A', 'A1'] as $who) {
            $c = $this->staff($who);
            $tag = bin2hex(random_bytes(3));
            $before = $this->customerCount();
            $answers = [
                'IP of another tree' => $this->add($c, self::form('w9ip_a' . $tag, self::IPS['cC1'], $tag)),
                'IP of an unassigned customer' => $this->add($c, self::form('w9ip_b' . $tag, self::IPS['cNULL'], $tag)),
                'IP of an in-scope customer' => $this->add($c, self::form('w9ip_c' . $tag, self::IPS['cA1'], $tag)),
                // a known-taken IP never turns the login username into a probe (and the other way round)
                'taken username + taken IP' => $this->add($c, self::form('cC1', self::IPS['cC1'], $tag)),
                'taken username + free IP' => $this->add($c, self::form('cC1', '10.66.250.' . random_int(1, 254), $tag)),
            ];
            $this->assertSame($before, $this->customerCount(), "{$who}: every static IP collision is refused on save");
            $this->assertCount(1, array_unique($answers), "{$who}: one generic answer: " . json_encode($answers, JSON_UNESCAPED_SLASHES));
            $one = (string) reset($answers);
            $this->assertStringContainsString('error: ', $one);
            foreach (array_keys(self::IPS) as $owner) {
                $this->assertStringNotContainsString($owner, $one, "{$who}: no customer name in the refusal");
            }
        }
        // Admin: refused too (system-wide uniqueness, every role), no other customer's name
        $before = $this->customerCount();
        $tag = bin2hex(random_bytes(3));
        $out = $this->add($this->staff('ad'), self::form('w9ip_d' . $tag, self::IPS['cC1'], $tag));
        $this->assertSame($before, $this->customerCount(), 'ad: a taken static IP is refused');
        $this->assertStringContainsString('error: ', $out);
        $this->assertStringNotContainsString('cC1', $out);
        // the API path (Agent token) is the same service
        $r = $this->client()->api('POST', 'customers/add-post', self::form('w9ip_e' . $tag, self::IPS['cC1'], $tag), $this->apiToken('A'));
        $this->assertFalse((bool) (($r->json() ?? [])['success'] ?? true), 'API: refused ' . $r->excerpt());
        $this->assertSame($before, $this->customerCount(), 'API: nothing is created');
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM tbl_customers WHERE pppoe_ip = ?', [self::IPS['cC1']]));
    }

    /** @return array<string,mixed> the Admin edit form of a customer as stored, with $over applied */
    private function editForm(string $username, array $over): array
    {
        $row = static::$db->rows('SELECT * FROM tbl_customers WHERE username = ?', [$username])[0];
        return $over + ['id' => $row['id'], 'username' => $row['username'], 'fullname' => $row['fullname'], 'account_type' => $row['account_type'], 'password' => '',
            'pppoe_username' => (string) $row['pppoe_username'], 'pppoe_password' => (string) $row['pppoe_password'], 'pppoe_ip' => (string) $row['pppoe_ip'],
            'email' => $row['email'], 'address' => (string) $row['address'], 'phonenumber' => $row['phonenumber'], 'service_type' => $row['service_type'],
            'coordinates' => $row['coordinates'], 'status' => $row['status'], 'city' => (string) $row['city'], 'district' => (string) $row['district'],
            'state' => (string) $row['state'], 'zip' => (string) $row['zip']];
    }

    private function edit(HttpClient $c, string $username, array $over): string
    {
        $form = $this->editForm($username, $over);
        return $this->outcome($c, 'customers/edit-post', $form, 'customers/edit/' . (int) $form['id']);
    }

    private function fullnameOf(string $username): string
    {
        return (string) static::$db->value('SELECT fullname FROM tbl_customers WHERE username = ?', [$username]);
    }

    /** cNULL2: unassigned (legacy save in the controller); cA0: owned by A (LegacyReprovisionService IDENTITY_EDIT). Neither has an active plan. */
    public function testAdminEditValidatesTheStaticIpAndKeepsItUnique(): void
    {
        $c = $this->staff('ad');
        foreach (['cNULL2', 'cA0'] as $u) {
            $start = $this->ipOf($u);
            foreach (self::INVALID as $ip) {
                $this->edit($c, $u, ['pppoe_ip' => $ip]);
                $this->assertSame($start, $this->ipOf($u), "{$u}: invalid static IP " . json_encode($ip) . ' is refused');
            }
            foreach (['cC1', 'cNULL', 'cA1'] as $owner) {
                $this->edit($c, $u, ['pppoe_ip' => self::IPS[$owner]]);
                $this->assertSame($start, $this->ipOf($u), "{$u}: the static IP of {$owner} is refused");
            }
            // positive controls: a free address is saved; an edit that keeps the customer's own address still saves
            $free = '10.66.' . random_int(200, 249) . '.' . random_int(1, 254);
            $this->edit($c, $u, ['pppoe_ip' => $free]);
            $this->assertSame($free, $this->ipOf($u), "{$u}: a free static IP is saved");
            $name = 'Keeps ' . bin2hex(random_bytes(3));
            $this->edit($c, $u, ['fullname' => $name]);
            $this->assertSame([$name, $free], [$this->fullnameOf($u), $this->ipOf($u)], "{$u}: an edit keeping its own IP saves");
            $this->edit($c, $u, ['pppoe_ip' => '']);
            $this->assertSame('', $this->ipOf($u), "{$u}: the static IP can be cleared");
        }
    }

    /**
     * Round 3, review N-1: special / reserved IPv4 addresses (255.255.255.255 = "the user selects" per RFC 2865, 0/8,
     * loopback, link-local, multicast, 240/4) are refused on every save path; RFC 1918 and CGNAT 100.64/10 are saved.
     */
    public function testSpecialAddressesAreRefusedOnEverySavePathAndSubscriberRangesAreSaved(): void
    {
        $special = ['255.255.255.255', '255.255.255.254', '0.1.2.3', '127.0.0.1', '169.254.10.20', '224.0.0.5', '240.0.0.1'];
        // add-post (CustomerService::create): Agent and Admin
        foreach (['A', 'ad'] as $who) {
            $c = $this->staff($who);
            foreach ($special as $ip) {
                $tag = bin2hex(random_bytes(3));
                $before = $this->customerCount();
                $out = $this->add($c, self::form('w9sp_' . $tag, $ip, $tag));
                $this->assertSame($before, $this->customerCount(), "{$who}: special address {$ip} must be refused: " . $out);
                $this->assertStringContainsString('error: ', $out, "{$who}: a refusal for {$ip}");
                $this->assertStringContainsString('IPv4', $out, "{$who}: the refusal names the rule");
            }
            foreach (['100.64.', '100.127.', '192.168.', '172.16.', '10.67.'] as $prefix) {
                $tag = bin2hex(random_bytes(3));
                $ip = $prefix . random_int(0, 255) . '.' . random_int(1, 254);
                $this->assertStringContainsString('customers/view/', $this->add($c, self::form('w9sp_ok' . $tag, $ip, $tag)), "{$who}: subscriber address {$ip} is saved");
                $this->assertSame($ip, $this->ipOf('w9sp_ok' . $tag));
            }
        }
        // Admin edit-post: cNULL2 (legacy save in the controller), cA0 (LegacyReprovisionService IDENTITY_EDIT)
        $c = $this->staff('ad');
        foreach (['cNULL2', 'cA0'] as $u) {
            $start = $this->ipOf($u);
            foreach ($special as $ip) {
                $this->edit($c, $u, ['pppoe_ip' => $ip]);
                $this->assertSame($start, $this->ipOf($u), "{$u}: special address {$ip} is refused on edit");
            }
            $cgnat = '100.' . random_int(64, 127) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
            $this->edit($c, $u, ['pppoe_ip' => $cgnat]);
            $this->assertSame($cgnat, $this->ipOf($u), "{$u}: a CGNAT address is saved on edit");
            $this->edit($c, $u, ['pppoe_ip' => '']);
            $this->assertSame('', $this->ipOf($u));
        }
    }

    /**
     * Round 3, review N-3: a reseller-owned customer whose stored static IP has edge whitespace (legacy / imported data)
     * changes the portal password (accounts/change-password-post -> LegacyReprovisionService PASSWORD_CHANGE). The IP is not
     * changed, so it is not re-judged: the password is saved and the stored IP stays as it is.
     */
    public function testPortalPasswordChangeOfAReprovisionedCustomerKeepsAPaddedLegacyIp(): void
    {
        $u = 'cA0';
        $oldPassword = (string) static::$db->value('SELECT password FROM tbl_customers WHERE username = ?', [$u]);
        $this->assertSame(\Reseller\Tests\Support\Wp2Seed::customerPassword($u), $oldPassword, 'seeded portal password');
        try {
            foreach (['10.66.9.9 ', " 10.66.9.10\t"] as $i => $padded) {
                static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_ip = ?, password = ? WHERE username = ?')->execute([$padded, $oldPassword, $u]);
                $new = 'Np' . $i . bin2hex(random_bytes(4));
                $c = $this->customer($u);
                $r = $c->postWithToken('accounts/change-password-post', ['password' => $oldPassword, 'npass' => $new, 'cnpass' => $new], [], 'accounts/change-password');
                $this->assertNotSame(500, $r->status, $r->excerpt());
                $this->assertSame([$new, $padded], [(string) static::$db->value('SELECT password FROM tbl_customers WHERE username = ?', [$u]), $this->ipOf($u)],
                    'padded legacy IP ' . json_encode($padded) . ': the password change is saved and the IP is kept (' . $r->status . ' ' . (string) $r->location() . ')');
            }
        } finally {
            static::$db->pdo->prepare("UPDATE tbl_customers SET pppoe_ip = '', password = ? WHERE username = ?")->execute([$oldPassword, $u]);
        }
    }

    /** Existing data: a legacy duplicate or legacy free text stays editable while the IP itself is not changed. */
    public function testLegacyValuesStayEditableWhileTheIpIsKept(): void
    {
        $c = $this->staff('ad');
        foreach (['cNULL2', 'cA0'] as $u) {
            foreach ([self::IPS['cC1'], 'legacy text'] as $legacy) {
                static::$db->pdo->prepare('UPDATE tbl_customers SET pppoe_ip = ? WHERE username = ?')->execute([$legacy, $u]);
                $name = 'Legacy ' . bin2hex(random_bytes(3));
                $this->edit($c, $u, ['fullname' => $name]);
                $this->assertSame([$name, $legacy], [$this->fullnameOf($u), $this->ipOf($u)], "{$u}: kept legacy value " . json_encode($legacy) . ', the edit saves');
            }
            static::$db->pdo->prepare("UPDATE tbl_customers SET pppoe_ip = '' WHERE username = ?")->execute([$u]);
        }
    }
}
