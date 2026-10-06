<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusPacket;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * WP8 S3 — real Disconnect / CoA on RouterOS CHR 7.24.5. The primary instrument is the APPLICATION's
 * Reseller\Radius\CoAClient (unchanged) running in the runner that shares the FreeRADIUS address 10.88.0.10 (the
 * RouterOS /radius server; OD-W8-11). Packet captures on the lab bridge and NAS readbacks are cross-checks only.
 *
 * Also the owner-required Error-Cause 406 investigation: known session, already-disconnected session whose radacct row
 * is still open, deliberately unknown session, wrong session id, attributes RouterOS cannot change by CoA, and a
 * request without identifying attributes. The accepted mapping (only 503 = SESSION_NOT_FOUND) is NOT changed here.
 */
final class CoaDisconnectTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
    /** @var list<string> firewall rule ids added by a test */
    private array $rules = [];

    protected function setUp(): void
    {
        $this->requireLab();
    }

    protected function tearDown(): void
    {
        foreach ($this->rules as $id) {
            Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
        }
        $this->rules = [];
        $this->releaseClients();
    }

    private function block(string ...$words): void
    {
        $this->rules[] = Wp8Lab::rosAdd('/ip/firewall/filter/add', ...[...$words, '=action=drop', '=comment=nuxr_wp8_drill']);
    }

    private function tearDownRulesOnly(): void
    {
        foreach ($this->rules as $id) {
            Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
        }
        $this->rules = [];
    }

    private function pppoe(string $pool = 'nuxr_wp8_pool_dyn', string $ip = ''): array
    {
        $customer = ['username' => self::ident('coa'), 'password' => 'Coa-pass-1', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => $ip];
        $user = $this->provisionPolicy($customer, ['type' => 'PPPOE', 'pool' => $pool], self::BW, new \DateTimeImmutable('+1 day'));
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Coa-pass-1']);
        $this->assertTrue($up['ok'], json_encode($up['log']));
        $this->assertIsArray(Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1), 'Start written');
        return [$user, $up['ip'], $customer];
    }

    /** @return list<array<string,mixed>> */
    private static function results(array $results): array
    {
        return array_map(static fn($r) => $r->toArray(), $results);
    }

    private static function coaPackets(array $pk): array
    {
        return array_values(array_filter($pk, static fn($p) => str_starts_with($p['code'], 'Disconnect') || str_starts_with($p['code'], 'CoA')));
    }

    public function testPppoeDisconnectAckSessionGoneStopWrittenSingleRequest(): void
    {
        [$user, $ip] = $this->pppoe();
        Wp8Lab::agent('capture_start', ['name' => 's3-dc-pppoe']);
        $res = self::results($this->coa()->disconnect($user));
        $this->assertSame([['SUCCESS', 'COA_ACK', '1']], array_map(static fn($r) => [$r['status'], $r['code'], $r['nas_id']], $res));
        $this->assertTrue(Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1'])['down'], 'the subscriber is disconnected');
        $this->assertSame([], Wp8Lab::pppActive($user));
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertSame('NAS-Request', $stop['acctterminatecause'] ?? null, 'Stop accounting');
        $pk = self::coaPackets(Wp8Lab::agent('capture_stop', ['name' => 's3-dc-pppoe'])['packets']);
        $this->assertSame([['Disconnect-Request', '10.88.0.10', '10.88.0.2:3799'], ['Disconnect-ACK', '10.88.0.2', '10.88.0.10']],
            array_map(static fn($p) => [$p['code'], explode(':', $p['src'])[0], $p['code'] === 'Disconnect-ACK' ? explode(':', $p['dst'])[0] : $p['dst']], $pk),
            'exactly one request (no retransmission once answered), from the RouterOS /radius server address to nas.ports 3799');
        Wp8Lab::evidence('S3.disconnect-pppoe', ['result' => $res, 'stop' => $stop, 'packets' => $pk]);
    }

    public function testHotspotDisconnectAckAndReinterception(): void
    {
        $user = $this->provisionPolicy(['username' => self::ident('coahs'), 'password' => 'Coahs-pass-1'], ['type' => 'Hotspot'], self::BW, new \DateTimeImmutable('+1 day'));
        Wp8Lab::agent('hs_dhcp', ['client' => 'c2']);
        $this->assertTrue(Wp8Lab::agent('hs_login', ['client' => 'c2', 'user' => $user, 'password' => 'Coahs-pass-1'])['ok']);
        Wp8Lab::until(fn() => $this->acct($user) ?: null, 20, 1);
        $res = self::results($this->coa()->disconnect($user));
        $this->assertSame(['SUCCESS', 'COA_ACK'], [$res[0]['status'], $res[0]['code']]);
        $this->assertSame([], Wp8Lab::hotspotActive($user));
        $stop = Wp8Lab::until(fn() => ($r = $this->acct($user)) && $r[0]['acctstoptime'] ? $r[0] : null, 30, 2);
        $this->assertNotNull($stop, 'Stop accounting');
        $this->assertSame(302, Wp8Lab::agent('http_probe', ['client' => 'c2'])['status'], 'intercepted again');
        Wp8Lab::evidence('S3.disconnect-hotspot', ['result' => $res, 'stop' => $stop]);
    }

    public function testRateLimitCoaChangesTheEffectiveSessionRate(): void
    {
        [$user] = $this->pppoe();
        $q0 = Wp8Lab::queue('<pppoe-' . $user . '>')[0]['max-limit'];
        $this->assertSame('256000/512000', $q0);
        $res = self::results($this->coa()->changeAuthorization($user, [['Mikrotik-Rate-Limit', '128k/1M']]));
        $this->assertSame([['SUCCESS', 'COA_ACK']], array_map(static fn($r) => [$r['status'], $r['code']], $res), 'CoA-ACK');
        $q1 = Wp8Lab::until(fn() => ($q = Wp8Lab::queue('<pppoe-' . $user . '>')) && $q[0]['max-limit'] !== $q0 ? $q[0]['max-limit'] : null, 15, 1);
        $this->assertSame('128000/1000000', $q1, 'the dynamic queue of the live session changed');
        $this->assertCount(1, Wp8Lab::pppActive($user), 'same session, not reconnected');
        $up = Wp8Lab::agent('traffic', ['client' => 'c1', 'direction' => 'up', 'seconds' => 8, 'kbps_offer' => 2000], 90);
        $this->assertGreaterThan(128 * 0.7, $up['measured_kbps']);
        $this->assertLessThan(128 * 1.15, $up['measured_kbps'], 'new upload limit enforced');
        $this->assertCount(1, $this->acct($user), 'no new accounting session');
        Wp8Lab::evidence('S3.coa-rate', ['before' => $q0, 'after' => $q1, 'upload_kbps' => $up['measured_kbps'], 'result' => $res]);
    }

    public function testAddressAndPoolChangesNeedDisconnectAndReauthentication(): void
    {
        [$user, $ip, $customer] = $this->pppoe('nuxr_wp8_pool_dyn');
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[0-4]\d)$/', $ip);
        // attempting the change by CoA alone: RouterOS documents that address / pool cannot be changed this way
        $attempts = [];
        foreach ([[['Framed-Pool', 'nuxr_wp8_pool_dyn2']], [['Framed-IP-Address', '10.88.10.60']]] as $attrs) {
            $r = self::results($this->coa()->changeAuthorization($user, $attrs))[0];
            $attempts[] = ['attrs' => $attrs, 'status' => $r['status'], 'code' => $r['code'], 'error_cause' => $r['metadata']['error_cause'] ?? null];
        }
        sleep(3);
        $this->assertSame($ip, Wp8Lab::pppActive($user)[0]['address'] ?? null, 'the live address did not change by CoA');
        $this->assertCount(1, $this->acct($user), 'no re-authentication happened');
        // RouterOS 7.24.5 ACKs such a CoA-Request and silently ignores the address / pool attribute: a CoA-ACK is
        // NOT proof that an address or pool change took effect (binding operational rule, see the WP8 model)
        foreach ($attempts as $a) {
            $this->assertSame(['SUCCESS', 'COA_ACK'], [$a['status'], $a['code']], 'observed RouterOS 7.24.5 answer: ACK without effect');
        }
        // the binding procedure: update the authoritative policy -> disconnect -> re-authentication
        $this->provisionPolicy($customer, ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn2'], self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertSame('SUCCESS', self::results($this->coa()->disconnect($user))[0]['status']);
        Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1']);
        $re = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Coa-pass-1']);
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[5-9]\d)$/', (string) $re['ip'], 'new address from nuxr_wp8_pool_dyn2');
        // and pool -> static
        $customer['pppoe_ip'] = '10.88.10.61';
        $this->provisionPolicy($customer, ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn2'], self::BW, new \DateTimeImmutable('+1 day'));
        $this->coa()->disconnect($user);
        Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1']);
        $st = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'Coa-pass-1']);
        $this->assertSame('10.88.10.61', $st['ip'], 'static address after re-authentication');
        $rows = Wp8Lab::until(fn() => count($r = $this->acct($user)) === 3 && $r[0]['acctstoptime'] && $r[1]['acctstoptime'] ? $r : null, 30, 2);
        $this->assertSame([$ip, $re['ip'], '10.88.10.61'], array_column($rows, 'framedipaddress'), 'one accounting row per session, history kept');
        Wp8Lab::evidence('S3.address-change', ['coa_attempts' => $attempts, 'addresses' => array_column($rows, 'framedipaddress'), 'rule' => 'policy -> disconnect -> re-auth']);
    }

    public function testNoResponseGivesUnknownAfterIdenticalRetransmissionsAndNoSuccess(): void
    {
        [$user] = $this->pppoe();
        $this->block('=chain=input', '=protocol=udp', '=dst-port=3799', '=src-address=10.88.0.10');
        Wp8Lab::agent('capture_start', ['name' => 's3-noresp']);
        $t = microtime(true);
        $res = self::results($this->coa()->disconnect($user));
        $elapsed = microtime(true) - $t;
        $pk = self::coaPackets(Wp8Lab::agent('capture_stop', ['name' => 's3-noresp'])['packets']);
        $this->assertSame([['UNKNOWN', 'COA_NO_RESPONSE', false]], array_map(static fn($r) => [$r['status'], $r['code'], $r['retryable']], $res), 'UNKNOWN, never SUCCESS, not retryable');
        $this->assertCount(3, $pk, 'original + 2 retransmissions');
        $this->assertSame(['Disconnect-Request'], array_values(array_unique(array_column($pk, 'code'))));
        $this->assertCount(1, array_unique(array_column($pk, 'id')), 'identical retransmissions (same identifier), no new request generated');
        $this->assertSame(1, count(array_unique(array_map(static fn($p) => json_encode($p['attrs']), $pk))));
        $this->assertCount(1, Wp8Lab::pppActive($user), 'the session is still up: nothing fabricated');
        $this->tearDownRulesOnly();
        $this->assertSame('SUCCESS', self::results($this->coa()->disconnect($user))[0]['status'], 'path restored');
        Wp8Lab::evidence('S3.no-response', ['result' => $res, 'elapsed_s' => round($elapsed, 2), 'packets' => $pk]);
    }

    public function testErrorCause406Investigation(): void
    {
        $nas = (new \Reseller\Radius\RadiusRepository(self::labRadius()))->nas(1);
        $send = fn(int $code, array $attrs) => $this->coa()->send($nas, $code, $attrs)->toArray();
        $cases = [];

        // 1. known active session (control)
        [$user, $ip] = $this->pppoe();
        $sid = $this->acct($user)[0]['acctsessionid'];
        $cases['wrong_session_id_for_active_user'] = $send(RadiusPacket::DISCONNECT_REQUEST, [['User-Name', $user], ['Acct-Session-Id', 'deadbeef'], ['NAS-IP-Address', '10.88.0.2']]);
        $cases['known_active_session'] = self::results($this->coa()->disconnect($user))[0];
        Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1']);

        // 2. already-disconnected session whose radacct row is still OPEN (the Stop is lost: NAS accounting blocked)
        [$user2] = $this->pppoe();
        $this->block('=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10');
        $this->assertSame('SUCCESS', self::results($this->coa()->disconnect($user2))[0]['status']);
        Wp8Lab::agent('pppoe_wait_down', ['client' => 'c1']);
        sleep(3);
        $this->assertNull($this->acct($user2)[0]['acctstoptime'], 'the radacct row stays open (stale)');
        $stale = self::results($this->coa()->disconnect($user2));
        $cases['stale_open_radacct'] = $stale[0];
        $this->tearDownRulesOnly();

        // 3. deliberately unknown user / session
        $cases['unknown_user_and_session'] = $send(RadiusPacket::DISCONNECT_REQUEST, [['User-Name', 'nuxr_wp8_nobody_' . bin2hex(random_bytes(2))], ['Acct-Session-Id', '81ffffff'], ['NAS-IP-Address', '10.88.0.2']]);
        // 4. CoA for an unknown user
        $cases['coa_unknown_user'] = $send(RadiusPacket::COA_REQUEST, [['User-Name', 'nuxr_wp8_nobody'], ['Mikrotik-Rate-Limit', '1M/1M']]);
        // 5. request without any identifying attribute
        $cases['no_identity_attributes'] = $send(RadiusPacket::DISCONNECT_REQUEST, [['NAS-IP-Address', '10.88.0.2']]);
        // 6. attribute RouterOS cannot change by CoA, on a live session
        [$user3] = $this->pppoe();
        $cases['unsupported_coa_attribute_live_session'] = $send(RadiusPacket::COA_REQUEST, [['User-Name', $user3], ['Framed-Pool', 'nuxr_wp8_pool_dyn2']]);
        $cases['coa_live_session_supported_attribute'] = self::results($this->coa()->changeAuthorization($user3, [['Mikrotik-Rate-Limit', '200k/400k']]))[0];

        $summary = array_map(static fn($r) => [$r['status'], $r['code'], $r['metadata']['error_cause'] ?? null], $cases);
        Wp8Lab::evidence('S3.error-cause-investigation', ['cases' => $summary]);
        // accepted mapping unchanged: ACK -> SUCCESS, NAK 503 -> SUCCESS SESSION_NOT_FOUND, other NAK -> FAILED COA_NAK
        $this->assertSame(['SUCCESS', 'COA_ACK', null], $summary['known_active_session']);
        $this->assertSame(['SUCCESS', 'COA_ACK', null], $summary['coa_live_session_supported_attribute']);
        // RouterOS 7.24.5 uses Error-Cause 406 for EVERY request that matches no session - stale radacct, unknown user,
        // wrong session id, CoA for an unknown user and a request with no identifying attribute at all - and never 503.
        // 406 is therefore broader than "session absent"; with the accepted mapping it is FAILED COA_NAK (never
        // SUCCESS, never UNKNOWN). No mapping change here (owner review, OD-W8-14).
        foreach (['stale_open_radacct', 'unknown_user_and_session', 'wrong_session_id_for_active_user', 'coa_unknown_user', 'no_identity_attributes'] as $k) {
            $this->assertSame(['FAILED', 'COA_NAK', 406], $summary[$k], "{$k}: NAK Error-Cause 406");
        }
        // an attribute RouterOS cannot change by CoA (Framed-Pool) on a live session: ACK, no effect
        $this->assertSame(['SUCCESS', 'COA_ACK', null], $summary['unsupported_coa_attribute_live_session']);
        $this->assertNotSame([], Wp8Lab::pppActive($user3));
        $this->assertMatchesRegularExpression('/^10\.88\.10\.(1[0-4]\d)$/', Wp8Lab::pppActive($user3)[0]['address'], 'still the old pool');
        $this->assertNull($this->acct($user2)[0]['acctstoptime'], 'RouterOS did not resend the lost Stop: the row stays open (stale)');
    }
}
