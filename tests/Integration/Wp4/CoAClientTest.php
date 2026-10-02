<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\AccountingService;
use Reseller\Radius\CoAClient;
use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RadiusPacket;
use Reseller\Radius\RadiusRepository;
use Reseller\Tests\Support\RadiusTestCase;
use Reseller\Tests\Support\StubNas;

/**
 * Hardened Disconnect / CoA client (SEC-11; 06_RADIUS_CONTRACT "CoA/disconnect observable"): native RADIUS
 * over UDP to a loopback test NAS. ACK -> SUCCESS, NAK -> FAILED, silence -> UNKNOWN (identical
 * retransmissions only, never a new request), forged replies ignored, only configured NAS contacted, no shell.
 */
final class CoAClientTest extends RadiusTestCase
{
    private const SECRET = 'coa-test-secret';

    protected function setUp(): void
    {
        static::$radius->exec('DELETE FROM nas');
    }

    private function client(): CoAClient
    {
        return new CoAClient(new AccountingService(static::$radius), new RadiusRepository(static::$radius), 0.5, 2);
    }

    private function nas(StubNas $stub): void
    {
        $this->repo()->nasAdd(['nasname' => '127.0.0.1', 'shortname' => 'stub', 'type' => 'other', 'ports' => (string) $stub->port, 'secret' => self::SECRET, 'description' => 'test']);
    }

    /** @return array<string,mixed> */
    private static function attrs(array $request): array
    {
        $out = [];
        foreach ($request['attributes'] as [$n, $v]) {
            $out[$n] = $v;
        }
        return $out;
    }

    public function testDisconnectAckEndsOnlyOpenSessionsAndKeepsHistory(): void
    {
        $u = 'ack-' . bin2hex(random_bytes(3));
        static::acct($u, 'closed-1', gmdate('Y-m-d H:i:s'));
        static::acct($u, 'open-1', null, ['framedipaddress' => '10.5.5.5']);
        $before = static::checksum('radacct');
        $stub = new StubNas(self::SECRET, 'ack');
        $this->nas($stub);
        $results = $this->client()->disconnect($u);
        $this->assertCount(1, $results, 'one Disconnect-Request per open session');
        $this->assertSame([ProvisionResult::SUCCESS, 'COA_ACK'], [$results[0]->status, $results[0]->code]);
        $got = $stub->received();
        $this->assertCount(1, $got);
        $this->assertSame(RadiusPacket::DISCONNECT_REQUEST, $got[0]['code']);
        $this->assertTrue($got[0]['valid'], 'RFC 5176 Request Authenticator with the NAS secret');
        $a = self::attrs($got[0]);
        $this->assertSame([$u, 'open-1', '10.5.5.5', '127.0.0.1'], [$a['User-Name'], $a['Acct-Session-Id'], $a['Framed-IP-Address'], $a['NAS-IP-Address']]);
        $this->assertArrayHasKey('Message-Authenticator', $a);
        $this->assertSame($before, static::checksum('radacct'), 'disconnect never rewrites accounting');
    }

    public function testNakIsFailedAndSessionNotFoundIsSuccess(): void
    {
        $u = 'nak-' . bin2hex(random_bytes(3));
        static::acct($u, 'open-n', null);
        $stub = new StubNas(self::SECRET, 'nak');
        $this->nas($stub);
        $r = $this->client()->disconnect($u)[0];
        $stub->received();
        $this->assertSame([ProvisionResult::FAILED, 'COA_NAK', false, 502], [$r->status, $r->code, $r->retryable, $r->metadata['error_cause']]);

        static::$radius->exec('DELETE FROM nas');
        $stub = new StubNas(self::SECRET, 'nak503');
        $this->nas($stub);
        $r = $this->client()->disconnect($u)[0];
        $stub->received();
        $this->assertSame([ProvisionResult::SUCCESS, 'SESSION_NOT_FOUND'], [$r->status, $r->code], 'Error-Cause 503: the session is already gone');
    }

    public function testSilenceIsUnknownWithIdenticalRetransmissionsOnly(): void
    {
        $u = 'sil-' . bin2hex(random_bytes(3));
        static::acct($u, 'open-s', null);
        $stub = new StubNas(self::SECRET, 'silent');
        $this->nas($stub);
        $r = $this->client()->disconnect($u)[0];
        $this->assertSame([ProvisionResult::UNKNOWN, 'COA_NO_RESPONSE', false], [$r->status, $r->code, $r->retryable], 'no answer: UNKNOWN, never retryable');
        $got = $stub->received();
        $this->assertCount(3, $got, 'one request and two retransmissions');
        $this->assertCount(1, array_unique(array_map(static fn(array $g): string => $g['id'] . '/' . $g['auth'], $got)), 'retransmissions are the identical packet (same identifier and authenticator)');
    }

    public function testForgedReplyIsIgnored(): void
    {
        $u = 'bad-' . bin2hex(random_bytes(3));
        static::acct($u, 'open-b', null);
        $stub = new StubNas(self::SECRET, 'badauth');
        $this->nas($stub);
        $r = $this->client()->disconnect($u)[0];
        $stub->received();
        $this->assertSame(ProvisionResult::UNKNOWN, $r->status, 'a reply with a wrong authenticator is not an ACK');
    }

    public function testCoaRequestChangesTheRate(): void
    {
        $u = 'coa-' . bin2hex(random_bytes(3));
        static::acct($u, 'open-c', null);
        $stub = new StubNas(self::SECRET, 'ack');
        $this->nas($stub);
        $r = $this->client()->changeAuthorization($u, [['Mikrotik-Rate-Limit', '3M/6M']])[0];
        $got = $stub->received();
        $this->assertSame([ProvisionResult::SUCCESS, 'COA_ACK'], [$r->status, $r->code]);
        $this->assertSame(RadiusPacket::COA_REQUEST, $got[0]['code']);
        $this->assertSame('3M/6M', self::attrs($got[0])['Mikrotik-Rate-Limit']);
    }

    public function testOfflineSubscriberAndUnknownNas(): void
    {
        $this->assertSame([], $this->client()->disconnect('nobody-' . bin2hex(random_bytes(3))), 'offline: nothing sent');
        $u = 'unk-' . bin2hex(random_bytes(3));
        static::acct($u, 'open-u', null, ['nasipaddress' => '192.0.2.77']);
        $r = $this->client()->disconnect($u)[0];
        $this->assertSame([ProvisionResult::FAILED, 'NAS_UNKNOWN', false], [$r->status, $r->code, $r->retryable], 'only configured NAS are contacted');
    }

    /** SEC-11: shell metacharacters are plain RADIUS attribute bytes; nothing is executed. */
    public function testNoShellIsInvolved(): void
    {
        $marker = sys_get_temp_dir() . '/wp4_shell_' . bin2hex(random_bytes(4));
        $u = "x';touch {$marker};'";
        static::acct($u, 'open-x', null);
        $stub = new StubNas(self::SECRET, 'ack');
        $this->nas($stub);
        $r = $this->client()->disconnect($u)[0];
        $got = $stub->received();
        $this->assertSame(ProvisionResult::SUCCESS, $r->status);
        $this->assertSame($u, self::attrs($got[0])['User-Name'], 'sent verbatim as an attribute');
        $this->assertFileDoesNotExist($marker);
    }
}
