<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\RadiusPacket;

/** RADIUS codec used by the CoA client (RFC 2865 / 2866 / 3579 / 5176). */
final class RadiusPacketTest extends TestCase
{
    public function testDisconnectRequestAuthenticatorAndMessageAuthenticator(): void
    {
        $p = RadiusPacket::request(RadiusPacket::DISCONNECT_REQUEST, 9, [['User-Name', 'alice'], ['Framed-IP-Address', '10.0.0.7'], ['Mikrotik-Rate-Limit', '1M/2M']], 's3cret');
        $raw = $p->raw;
        $this->assertSame(40, ord($raw[0]));
        $this->assertSame(9, ord($raw[1]));
        $this->assertSame(strlen($raw), unpack('n', substr($raw, 2, 2))[1]);
        // RFC 5176 Request Authenticator
        $this->assertSame(md5(substr($raw, 0, 4) . str_repeat("\0", 16) . substr($raw, 20) . 's3cret', true), substr($raw, 4, 16));
        // RFC 3579 Message-Authenticator over the packet with the authenticator and MA zeroed
        $pos = strpos($raw, chr(80) . chr(18), 20);
        $zeroed = substr($raw, 0, 4) . str_repeat("\0", 16) . substr($raw, 20, $pos + 2 - 20) . str_repeat("\0", 16) . substr($raw, $pos + 18);
        $this->assertSame(hash_hmac('md5', $zeroed, 's3cret', true), substr($raw, $pos + 2, 16));
        $d = RadiusPacket::decode($raw);
        $this->assertSame('alice', $d->get('User-Name'));
        $this->assertSame('10.0.0.7', $d->get('Framed-IP-Address'));
        $this->assertSame('1M/2M', $d->get('Mikrotik-Rate-Limit'), 'vendor-specific attribute round trip');
    }

    public function testAccessRequestHidesThePassword(): void
    {
        $p = RadiusPacket::request(RadiusPacket::ACCESS_REQUEST, 1, [['User-Name', 'bob'], ['User-Password', 'pa55word-that-is-long']], 'xyz');
        $hidden = (string) $p->get('User-Password');
        $this->assertSame(32, strlen($hidden), 'padded to 16-byte blocks');
        $this->assertStringNotContainsString('pa55word', $p->raw);
        // RFC 2865 §5.2 reversal
        $b1 = substr($hidden, 0, 16) ^ md5('xyz' . $p->authenticator, true);
        $b2 = substr($hidden, 16, 16) ^ md5('xyz' . substr($hidden, 0, 16), true);
        $this->assertSame('pa55word-that-is-long', rtrim($b1 . $b2, "\0"));
    }

    public function testResponseVerification(): void
    {
        $req = RadiusPacket::request(RadiusPacket::COA_REQUEST, 200, [['User-Name', 'carol']], 'k');
        $reply = function (int $code, int $id, string $secret, string $attrs = '') use ($req): RadiusPacket {
            $head = chr($code) . chr($id) . pack('n', 20 + strlen($attrs));
            return RadiusPacket::decode($head . md5($head . $req->authenticator . $attrs . $secret, true) . $attrs);
        };
        $this->assertTrue(RadiusPacket::verifyResponse($reply(44, 200, 'k'), $req, 'k'), 'valid CoA-ACK');
        $this->assertFalse(RadiusPacket::verifyResponse($reply(44, 201, 'k'), $req, 'k'), 'wrong identifier');
        $this->assertFalse(RadiusPacket::verifyResponse($reply(44, 200, 'other'), $req, 'k'), 'wrong secret');
        $nak = $reply(45, 200, 'k', chr(101) . chr(6) . pack('N', 503));
        $this->assertTrue(RadiusPacket::verifyResponse($nak, $req, 'k'));
        $this->assertSame(503, $nak->get('Error-Cause'));
    }

    public function testChapResponseAndMalformedPackets(): void
    {
        $this->assertSame(chr(5) . md5(chr(5) . 'pw' . 'chal', true), RadiusPacket::chapResponse(5, 'pw', 'chal'));
        foreach (['short', str_repeat("\0", 19), chr(2) . chr(1) . pack('n', 9000) . str_repeat("\0", 16)] as $bad) {
            try {
                RadiusPacket::decode($bad);
                $this->fail('malformed accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([fn() => RadiusPacket::request(40, 1, [['No-Such-Attribute', 'x']], 's'), fn() => RadiusPacket::request(40, 1, [], ''),
            fn() => RadiusPacket::request(40, 1, [['Framed-IP-Address', 'not-ip']], 's')] as $f) {
            try {
                $f();
                $this->fail('invalid request built');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
