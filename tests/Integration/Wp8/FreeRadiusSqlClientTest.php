<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8Radius.php';
require_once dirname(__DIR__, 2) . '/Support/Wp8Lab.php';

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusPacket;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8Radius;

/**
 * WP8 S1 (owner decisions OD-W8-7, OD-W8-10, OD-W8-13, amendment W8-A3): the project FreeRADIUS configuration with
 * PRODUCTION-STYLE NAS clients loaded from the `nas` table (read_clients = yes) in the real FreeRADIUS 3.2.8 of the
 * WP8 lab. The requests come from the co-located application host 10.88.0.10, which is the SQL-loaded NAS client
 * "nuxr_wp8_app" (dev/wp8/lab.sh). Runs only in the WP8 lab (dev/wp8/lab.sh test); elsewhere every test is incomplete.
 *
 *   MA-1 missing Message-Authenticator      -> no response (virtual-server policy, before any other module)
 *   MA-2 wrong Message-Authenticator (HMAC) -> no response, discarded on receipt before authorize
 *   MA-3 valid Message-Authenticator        -> normal Accept / Reject
 *   Accounting-Requests WITHOUT Message-Authenticator keep working (authentication-only enforcement)
 *   plus the WP4 policy contract on this client path: PAP, CHAP, dynamic pool, static IPv4, Simultaneous-Use,
 *   Expiration (FreeRADIUS in the application time zone), time / data quotas, accounting history kept.
 */
final class FreeRadiusSqlClientTest extends TestCase
{
    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
    private static ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (Wp8Lab::enabled()) {
            self::$pdo = Wp8Lab::radiusPdo();
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestIncomplete('WP8 real-NAS lab not running (dev/wp8/lab.sh up; dev/wp8/lab.sh test)');
        }
    }

    private static function user(string $what): string
    {
        return 'nuxr_wp8_' . $what . '_' . bin2hex(random_bytes(3));
    }

    private function provision(array $customer, array $plan, ?array $bw, \DateTimeImmutable $expiry, ?\DateTimeImmutable $period = null): string
    {
        $policy = RadiusPolicyBuilder::build($customer, $plan, $bw, $expiry, $period);
        $r = (new RadiusProvisioner(new RadiusRepository(self::$pdo)))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    private static function plan(string $type, array $extra = []): array
    {
        return $extra + ['id' => random_int(100000, 999999), 'type' => $type, 'typebp' => 'Unlimited', 'shared_users' => 1, 'pool' => ''];
    }

    /** the FreeRADIUS debug lines of the last request with identifier $id from the application host */
    private static function handling(int $id): array
    {
        $lines = Wp8Lab::frLog('^\(\d+\) |Dropping packet|Received Access-Request');
        $n = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\((\d+)\) Received Access-Request Id ' . $id . ' from 10\.88\.0\.10:/', $l, $m)) {
                $n = $m[1];
                $start = $i;
            }
        }
        if ($n === null) {
            return [];
        }
        $out = [$lines[$start]];
        foreach (array_slice($lines, $start + 1) as $l) {
            if (str_starts_with($l, "({$n}) ") || str_starts_with($l, 'Dropping packet')) {
                $out[] = $l;
            } elseif (preg_match('/^\((\d+)\) Received/', $l)) {
                break;
            }
        }
        return $out;
    }

    public function testNasClientsComeFromTheNasTableWithoutAnyHardenedClientPolicy(): void
    {
        $added = Wp8Lab::frLog('Client "nuxr_wp8_[a-z]+" \(sql\) added');
        $this->assertCount(1, preg_grep('/rlm_sql \(10\.88\.0\.2\): Client "nuxr_wp8_chr" \(sql\) added/', $added), 'CHR loaded from nas');
        $this->assertCount(1, preg_grep('/rlm_sql \(10\.88\.0\.10\): Client "nuxr_wp8_app" \(sql\) added/', $added), 'application host loaded from nas');
        $global = implode("\n", Wp8Lab::frLog('^\[wp8\]'));
        $this->assertStringContainsString('read_clients = yes', $global);
        $this->assertStringContainsString('require_message_authenticator = auto', $global, 'the stock global policy stays as shipped: the enforcement comes from the virtual server');
        Wp8Lab::evidence('S1.clients', ['sql_clients' => array_values($added), 'startup' => $global]);
    }

    public function testMa1MissingMessageAuthenticatorGetsNoResponse(): void
    {
        $c = new Wp8Radius('10.88.0.10', (string) getenv('NUXR_RADIUS_SECRET'), '10.88.0.10');
        $key = $this->provision(['username' => self::user('ma1'), 'password' => 'Ma1-pass-ok'], self::plan('Hotspot'), self::BW, new \DateTimeImmutable('+1 day'));
        foreach ([false, true] as $chap) {
            $this->assertNull($c->access($key, 'Ma1-pass-ok', 'missing', $chap), 'MA-1: no Accept, no Reject' . ($chap ? ' (CHAP)' : ' (PAP)'));
            $h = self::handling($c->lastId);
            $this->assertNotSame([], $h, 'the request reached FreeRADIUS');
            $this->assertNotEmpty(preg_grep('/if \(!&Message-Authenticator\)\s+-> TRUE/', $h), 'the virtual-server policy saw no attribute');
            $this->assertNotEmpty(preg_grep('/policy do_not_respond = handled/', $h));
            $this->assertEmpty(preg_grep('/Sent Access-(Accept|Reject)|sql|pap|chap\]/i', array_slice($h, 1)), 'no module after the policy, no reply');
            Wp8Lab::evidence('S1.MA-1', ['chap' => $chap, 'reply' => null, 'freeradius' => $h]);
        }
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $c->access($key, 'Ma1-pass-ok')?->code, 'the same subscriber is accepted with a valid Message-Authenticator');
    }

    public function testMa2InvalidMessageAuthenticatorIsDiscardedBeforeAuthorization(): void
    {
        $c = Wp8Radius::lab();
        $key = $this->provision(['username' => self::user('ma2'), 'password' => 'Ma2-pass-ok'], self::plan('Hotspot'), self::BW, new \DateTimeImmutable('+1 day'));
        foreach ([false, true] as $chap) {
            $this->assertNull($c->access($key, 'Ma2-pass-ok', 'bogus', $chap), 'MA-2: correct credentials, wrong HMAC -> no response');
            $h = self::handling($c->lastId);
            $this->assertNotSame([], $h, 'the request reached FreeRADIUS');
            $this->assertMatchesRegularExpression('/Dropping packet without response because of error: Received packet from 10\.88\.0\.10 with invalid Message-Authenticator!.*\(from client nuxr_wp8_app\)/', $h[1] ?? '', 'discarded on receipt (HMAC verified)');
            $this->assertEmpty(preg_grep('/Message-Authenticator\)\s+->|authorize|Sent Access/', $h), 'never reached authorize / authenticate');
            Wp8Lab::evidence('S1.MA-2', ['chap' => $chap, 'reply' => null, 'freeradius' => $h]);
        }
    }

    public function testMa3ValidMessageAuthenticatorKeepsNormalPapAndChapBehaviour(): void
    {
        $c = Wp8Radius::lab();
        $key = $this->provision(['username' => self::user('ma3'), 'password' => 'Ma3-pass-ok'], self::plan('Hotspot'), self::BW, new \DateTimeImmutable('+1 day'));
        $res = [];
        foreach (['pap' => false, 'chap' => true] as $label => $chap) {
            $ok = $c->access($key, 'Ma3-pass-ok', 'valid', $chap);
            $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $ok?->code, "MA-3 {$label} good credentials");
            $this->assertNotEmpty(preg_grep('/if \(!&Message-Authenticator\)\s+-> FALSE/', self::handling($c->lastId)));
            $bad = $c->access($key, 'wrong-password', 'valid', $chap);
            $this->assertSame(RadiusPacket::ACCESS_REJECT, $bad?->code, "MA-3 {$label} bad credentials");
            $res[$label] = ['good' => 'Access-Accept', 'bad' => 'Access-Reject'];
        }
        Wp8Lab::evidence('S1.MA-3', $res);
    }

    public function testAccountingWithoutMessageAuthenticatorStillWritesStartInterimStop(): void
    {
        $c = Wp8Radius::lab();
        $key = $this->provision(['username' => self::user('acct'), 'password' => 'Acct-pass-1'], self::plan('Hotspot', ['shared_users' => 2]), self::BW, new \DateTimeImmutable('+1 day'));
        $sid = bin2hex(random_bytes(6));
        $base = [['User-Name', $key], ['Acct-Session-Id', $sid], ['NAS-Port', 9], ['Framed-IP-Address', '10.88.2.150']];
        foreach ([[1, []], [3, [['Acct-Session-Time', 60], ['Acct-Input-Octets', 1000], ['Acct-Output-Octets', 2000]]],
                  [2, [['Acct-Session-Time', 90], ['Acct-Input-Octets', 1500], ['Acct-Output-Octets', 3000], ['Acct-Terminate-Cause', 1]]]] as [$type, $more]) {
            $r = $c->accounting([...$base, ['Acct-Status-Type', $type], ['Event-Timestamp', time()], ...$more]);
            $this->assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $r?->code, "Accounting-Request type {$type} without Message-Authenticator is answered");
        }
        $row = self::$pdo->query("SELECT acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, nasipaddress FROM radacct WHERE username = " . self::$pdo->quote($key))->fetchAll();
        $this->assertCount(1, $row);
        $this->assertNotNull($row[0]['acctstoptime'], 'Stop written');
        $this->assertSame([90, 1500, 3000, '10.88.0.10'], [(int) $row[0]['acctsessiontime'], (int) $row[0]['acctinputoctets'], (int) $row[0]['acctoutputoctets'], $row[0]['nasipaddress']]);
        Wp8Lab::evidence('S1.accounting-without-MA', ['answered' => ['Start', 'Interim-Update', 'Stop'], 'radacct' => $row[0]]);
    }

    public function testPppoePoolStaticAddressRateAndSimultaneousUseOnTheSqlClient(): void
    {
        $c = Wp8Radius::lab();
        $dyn = $this->provision(['username' => self::user('pool'), 'password' => 'Pool-pass-1', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''], self::plan('PPPOE', ['pool' => 'nuxr_wp8_pool_dyn']), self::BW, new \DateTimeImmutable('+1 day'));
        $r = $c->access($dyn, 'Pool-pass-1', 'valid', true);
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame('nuxr_wp8_pool_dyn', $r->get('Framed-Pool'));
        $this->assertNull($r->get('Framed-IP-Address'));
        $this->assertSame('256K/512K', $r->get('Mikrotik-Rate-Limit'));

        $static = $this->provision(['username' => self::user('st'), 'password' => 'x', 'pppoe_username' => self::user('stp'), 'pppoe_password' => 'St-pass-1', 'pppoe_ip' => '10.88.10.50'], self::plan('PPPOE', ['pool' => 'nuxr_wp8_pool_dyn']), self::BW, new \DateTimeImmutable('+1 day'));
        $r = $c->access($static, 'St-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $this->assertSame('10.88.10.50', $r->get('Framed-IP-Address'));
        $this->assertNull($r->get('Framed-Pool'), 'never both');

        $sid = bin2hex(random_bytes(6));
        $this->assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $c->accounting([['User-Name', $dyn], ['Acct-Status-Type', 1], ['Acct-Session-Id', $sid], ['Event-Timestamp', time()]])?->code);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $c->access($dyn, 'Pool-pass-1', 'valid', true)?->code, 'Simultaneous-Use 1 with an open session');
        $this->assertSame(RadiusPacket::ACCOUNTING_RESPONSE, $c->accounting([['User-Name', $dyn], ['Acct-Status-Type', 2], ['Acct-Session-Id', $sid], ['Event-Timestamp', time()], ['Acct-Session-Time', 3]])?->code);
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $c->access($dyn, 'Pool-pass-1', 'valid', true)?->code);
        Wp8Lab::evidence('S1.pppoe-policy', ['pool' => 'Framed-Pool nuxr_wp8_pool_dyn', 'static' => 'Framed-IP-Address 10.88.10.50, no pool', 'rate' => '256K/512K', 'simultaneous_use' => 'second session rejected while one is open']);
    }

    public function testExpirationIsEvaluatedInTheApplicationTimeZone(): void
    {
        $c = Wp8Radius::lab();
        $this->assertSame((string) getenv('NUXR_WP8_TZ'), date_default_timezone_get(), 'the runner uses the lab application time zone');
        $customer = ['username' => self::user('exp'), 'password' => 'Exp-pass-1'];
        $key = $this->provision($customer, self::plan('Hotspot'), self::BW, new \DateTimeImmutable('-1 minute'));
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $c->access($key, 'Exp-pass-1')?->code, 'expired one minute ago');
        $this->provision($customer, self::plan('Hotspot'), self::BW, new \DateTimeImmutable('+90 seconds'));
        $r = $c->access($key, 'Exp-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $r?->code);
        $timeout = (int) $r->get('Session-Timeout');
        $this->assertGreaterThanOrEqual(60, $timeout, 'FreeRADIUS reads Expiration in the same zone (a 3 h offset would reject or give ~10890 s)');
        $this->assertLessThanOrEqual(90, $timeout);
        Wp8Lab::evidence('S1.expiration', ['tz' => date_default_timezone_get(), 'expired' => 'Access-Reject', 'session_timeout_for_+90s' => $timeout]);
    }

    public function testTimeAndDataQuotasPerPeriodKeepTheAccountingHistory(): void
    {
        $c = Wp8Radius::lab();
        $plan = self::plan('Hotspot', ['typebp' => 'Limited', 'limit_type' => 'Time_Limit', 'time_limit' => 1, 'time_unit' => 'Mins']);
        $customer = ['username' => self::user('tq'), 'password' => 'Tq-pass-1'];
        $key = $this->provision($customer, $plan, self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('-10 minutes'));
        $first = $c->access($key, 'Tq-pass-1');
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $first?->code);
        $this->assertLessThanOrEqual(60, (int) $first->get('Session-Timeout'));
        $sid = bin2hex(random_bytes(6));
        $c->accounting([['User-Name', $key], ['Acct-Status-Type', 1], ['Acct-Session-Id', $sid], ['Event-Timestamp', time() - 300]]);
        $c->accounting([['User-Name', $key], ['Acct-Status-Type', 2], ['Acct-Session-Id', $sid], ['Event-Timestamp', time() - 200], ['Acct-Session-Time', 61]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $c->access($key, 'Tq-pass-1')?->code, 'time quota used');
        $this->provision($customer, $plan, self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('now'));
        $this->assertSame(RadiusPacket::ACCESS_ACCEPT, $c->access($key, 'Tq-pass-1')?->code, 'new billing period');

        $dplan = self::plan('Hotspot', ['typebp' => 'Limited', 'limit_type' => 'Data_Limit', 'data_limit' => 1, 'data_unit' => 'MB']);
        $dc = ['username' => self::user('dq'), 'password' => 'Dq-pass-1'];
        $dkey = $this->provision($dc, $dplan, self::BW, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('-10 minutes'));
        $sid = bin2hex(random_bytes(6));
        $c->accounting([['User-Name', $dkey], ['Acct-Status-Type', 1], ['Acct-Session-Id', $sid], ['Event-Timestamp', time() - 300]]);
        $c->accounting([['User-Name', $dkey], ['Acct-Status-Type', 2], ['Acct-Session-Id', $sid], ['Event-Timestamp', time() - 200], ['Acct-Session-Time', 30], ['Acct-Input-Octets', 600000], ['Acct-Output-Octets', 600000]]);
        $this->assertSame(RadiusPacket::ACCESS_REJECT, $c->access($dkey, 'Dq-pass-1')?->code, 'data quota used');
        $count = static fn(string $u): int => (int) self::$pdo->query('SELECT COUNT(*) FROM radacct WHERE username = ' . self::$pdo->quote($u))->fetchColumn();
        $this->assertSame([1, 1], [$count($key), $count($dkey)], 'accounting history kept');
        Wp8Lab::evidence('S1.quotas', ['time_quota' => 'Session-Timeout <= 60, reject after use, accept in the new period', 'data_quota' => 'reject after 1.2 MB of 1 MB', 'history_rows' => [1, 1]]);
    }
}
