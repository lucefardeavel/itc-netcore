<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\RadiusHttpTestCase;

/**
 * The rlm_rest endpoint radius.php, configured (owner decisions Q3, Q4, Q9; SEC-01, E16): authenticated
 * requests only, no SQL injection effect, vouchers authenticate only while usable, CHAP verifies only the
 * right response, accounting counters per session (no double counting).
 */
final class RadiusRestEndpointTest extends RadiusHttpTestCase
{
    private const SECRET = 'wp4-rest-secret-0123456789abcdefghijklmnop';

    private static int $plan;
    private static string $user;

    protected static function restSecret(): ?string
    {
        return self::SECRET;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $bw = static::$db->insertRow('tbl_bandwidth', ['name_bw' => 'wp4_rest', 'rate_down' => 4, 'rate_down_unit' => 'Mbps', 'rate_up' => 1, 'rate_up_unit' => 'Mbps']);
        self::$plan = static::$db->insertRow('tbl_plans', [
            'name_plan' => 'wp4_rest_plan', 'id_bw' => $bw, 'price' => '1000', 'type' => 'Hotspot', 'typebp' => 'Unlimited',
            'limit_type' => 'Time_Limit', 'time_limit' => 0, 'time_unit' => 'Hrs', 'data_limit' => 0, 'data_unit' => 'MB',
            'validity' => 30, 'validity_unit' => 'Days', 'shared_users' => 2, 'routers' => 'radius', 'is_radius' => 1,
            'pool' => '', 'enabled' => 1, 'prepaid' => 'yes', 'plan_type' => 'Personal', 'device' => 'RadiusRest',
        ]);
        self::$user = 'cNULL';
        static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => static::$seed->customers['cNULL'], 'username' => 'cNULL', 'plan_id' => self::$plan, 'namebp' => 'wp4_rest_plan',
            'recharged_on' => gmdate('Y-m-d'), 'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', time() + 86400 * 5), 'time' => '23:59:59',
            'status' => 'on', 'method' => 'Admin - wp4', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 1,
        ]);
    }

    private function signed(string $action, array $data): \Reseller\Tests\Support\HttpResponse
    {
        return $this->rest($action, $data, ['X-Nuxr-Radius-Secret: ' . self::SECRET]);
    }

    public function testRefusedWithoutOrWithAWrongSecret(): void
    {
        $data = ['username' => 'cNULL', 'password' => 'cpw-cNULL'];
        foreach ([[], ['X-Nuxr-Radius-Secret: wrong'], ['Authorization: Basic ' . base64_encode('freeradius:nope')]] as $headers) {
            $r = $this->rest('authorize', $data, $headers);
            $this->assertSame(401, $r->status);
            $this->assertStringContainsString('Unauthorized', $r->body);
            $this->assertStringNotContainsString('Mikrotik-Rate-Limit', $r->body);
        }
        $ok = $this->rest('authorize', $data, ['Authorization: Basic ' . base64_encode('freeradius:' . self::SECRET)]);
        $this->assertSame(200, $ok->status, 'HTTP Basic (FreeRADIUS rest auth = basic)');
        $this->assertSame('1M/4M', json_decode($ok->body, true)['reply:Mikrotik-Rate-Limit'] ?? null);
        $this->assertSame(204, $this->signed('authenticate', $data)->status, 'header secret');
    }

    /** SEC-01: request values are parameters; injection payloads have no SQL effect. */
    public function testInjectionPayloadsHaveNoEffect(): void
    {
        $before = $this->snapshot();
        foreach (["zz' OR '1'='1", "nobody' OR username<>'", "x' UNION SELECT 1,2,3 -- ", "a\\' OR 1=1 #"] as $payload) {
            foreach (['authorize', 'authenticate'] as $action) {
                $r = $this->signed($action, ['username' => $payload, 'password' => 'x']);
                $this->assertSame(401, $r->status, "{$action}: {$payload}");
                $this->assertStringNotContainsString('Mikrotik-Rate-Limit', $r->body, 'no other subscriber\'s policy');
            }
            $this->signed('accounting', ['username' => $payload, 'acctStatusType' => 'Start', 'macAddr' => $payload, 'nasid' => $payload]);
        }
        $this->assertUnchanged($before, 'injection payloads');
        // a payload in a value that IS stored is stored literally
        $mac = "aa' OR '1'='1";
        $this->signed('accounting', ['username' => 'cNULL', 'acctStatusType' => 'Start', 'acctSessionId' => 'inj-1', 'macAddr' => $mac, 'nasid' => 'n1']);
        $this->assertSame(1, (int) static::$db->value('SELECT COUNT(*) FROM rad_acct WHERE macaddr = ?', [$mac]));
    }

    /** E16: a used voucher without an active recharge does not authenticate; an unused one does. */
    public function testVoucherAuthentication(): void
    {
        $used = 'WP4USED' . strtoupper(bin2hex(random_bytes(3)));
        $fresh = 'WP4FRESH' . strtoupper(bin2hex(random_bytes(3)));
        foreach ([$used => '1', $fresh => '0'] as $code => $status) {
            static::$db->insertRow('tbl_voucher', ['type' => 'Hotspot', 'routers' => 'radius', 'id_plan' => self::$plan, 'code' => $code, 'user' => '0', 'status' => $status]);
        }
        $this->assertSame(401, $this->signed('authenticate', ['username' => $used, 'password' => $used])->status, 'used voucher, no active recharge');
        $this->assertSame(204, $this->signed('authenticate', ['username' => $fresh, 'password' => $fresh])->status, 'unused voucher (authorize redeems it)');
        static::$db->insertRow('tbl_user_recharges', [
            'customer_id' => 0, 'username' => $used, 'plan_id' => self::$plan, 'namebp' => 'wp4_rest_plan', 'recharged_on' => gmdate('Y-m-d'),
            'recharged_time' => '00:00:00', 'expiration' => gmdate('Y-m-d', time() + 86400), 'time' => '23:59:59', 'status' => 'on',
            'method' => 'Voucher - x', 'routers' => 'radius', 'type' => 'Hotspot', 'admin_id' => 0,
        ]);
        $this->assertSame(204, $this->signed('authenticate', ['username' => $used, 'password' => $used])->status, 'used voucher with its active recharge');
    }

    /** Q4: a wrong CHAP response is rejected (the pre-WP4 verifier accepted it). */
    public function testChapVerifiesTheResponse(): void
    {
        $challenge = random_bytes(16);
        $chap = static fn(string $pw): string => '0x' . bin2hex(chr(9) . md5(chr(9) . $pw . $challenge, true));
        $req = static fn(string $pw): array => ['username' => 'cNULL', 'CHAPassword' => $chap($pw), 'CHAPchallenge' => '0x' . bin2hex($challenge)];
        $this->assertSame(204, $this->signed('authenticate', $req('cpw-cNULL'))->status, 'right CHAP response');
        $this->assertSame(401, $this->signed('authenticate', $req('wrong-password'))->status, 'wrong CHAP response');
        $this->assertSame(401, $this->signed('authorize', $req('wrong-password'))->status);
    }

    /** Q9: cumulative counters SET the session's values (no double counting); a new session is a new row. */
    public function testAccountingCountersPerSession(): void
    {
        $base = ['username' => 'cNULL', 'macAddr' => 'aa:bb:cc:00:00:01', 'nasid' => 'nas-1', 'framedIPAddress' => '10.1.1.1'];
        foreach ([['Start', 0, 0], ['Interim-Update', 1000, 2000], ['Interim-Update', 3000, 4000]] as [$type, $in, $out]) {
            $this->assertSame(200, $this->signed('accounting', $base + ['acctSessionId' => 'S1', 'acctStatusType' => $type, 'acctInputOctets' => $in, 'acctOutputOctets' => $out])->status);
        }
        $rows = static::$db->rows("SELECT acctinputoctets, acctoutputoctets, acctstatustype FROM rad_acct WHERE username = 'cNULL' AND acctsessionid = 'S1'");
        $this->assertSame([['acctinputoctets' => 3000, 'acctoutputoctets' => 4000, 'acctstatustype' => 'Start']], array_map(static fn($r) => array_map(static fn($v) => is_numeric($v) ? (int) $v : $v, $r), $rows), 'cumulative values set, not summed (legacy: 4000 / 6000)');
        $this->signed('accounting', $base + ['acctSessionId' => 'S1', 'acctStatusType' => 'Stop', 'acctInputOctets' => 3500, 'acctOutputOctets' => 4500]);
        $this->signed('accounting', $base + ['acctSessionId' => 'S2', 'acctStatusType' => 'Start', 'acctInputOctets' => 0, 'acctOutputOctets' => 0]);
        $this->signed('accounting', $base + ['acctSessionId' => 'S2', 'acctStatusType' => 'Interim-Update', 'acctInputOctets' => 100, 'acctOutputOctets' => 200]);
        $this->assertSame(['S1' => 'Stop', 'S2' => 'Start'], array_column(static::$db->rows("SELECT acctsessionid, acctstatustype FROM rad_acct WHERE username = 'cNULL' AND acctsessionid IN ('S1', 'S2') ORDER BY acctsessionid"), 'acctstatustype', 'acctsessionid'));
        $this->assertSame(3500 + 4500 + 100 + 200, (int) static::$db->value("SELECT SUM(acctinputoctets + acctoutputoctets) FROM rad_acct WHERE username = 'cNULL' AND acctsessionid IN ('S1', 'S2')"));
    }

    public function testRadiusRuntimeClassesRefuseDirectHttp(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/system/autoload/Reseller/Radius/*.php') as $file) {
            $r = $this->client()->raw('GET', 'system/autoload/Reseller/Radius/' . basename($file));
            $this->assertSame(404, $r->status, basename($file));
            $this->assertSame('', trim($r->body));
        }
    }
}
