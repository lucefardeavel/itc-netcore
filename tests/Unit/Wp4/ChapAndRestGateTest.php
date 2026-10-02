<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Radius\ProvisionResult;
use Reseller\Radius\RestGate;

/**
 * Owner decisions Q4 (CHAP verification) and Q3 (rlm_rest endpoint authentication), and the ProvisionResult
 * contract (UNKNOWN is never retryable).
 */
final class ChapAndRestGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/system/autoload/Password.php';
    }

    private static function chap(int $id, string $password, string $challenge): array
    {
        return ['0x' . bin2hex(chr($id) . md5(chr($id) . $password . $challenge, true)), '0x' . bin2hex($challenge)];
    }

    /** Q4: the pre-WP4 verifier returned true on a MISMATCH; it must verify only the right response. */
    public function testChapVerifiesOnlyTheRightResponse(): void
    {
        [$response, $challenge] = self::chap(17, 'correct horse', random_bytes(16));
        $this->assertTrue(\Password::chap_verify('correct horse', $response, $challenge), 'right password');
        $this->assertTrue(\Password::chap_verify('correct horse', '0x' . strtoupper(substr($response, 2)), $challenge), 'hex case-insensitive');
        $this->assertFalse(\Password::chap_verify('wrong', $response, $challenge), 'wrong password must not verify');
        $this->assertFalse(\Password::chap_verify('', $response, $challenge), 'empty password must not verify');
        foreach (['', '0x', '0xzz', $response . '00', substr($response, 0, 20)] as $bad) {
            $this->assertFalse(\Password::chap_verify('correct horse', $bad, $challenge), 'malformed response: ' . $bad);
        }
        $this->assertFalse(\Password::chap_verify('correct horse', $response, ''), 'missing challenge');
    }

    public function testRestEndpointIsOffUntilConfigured(): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_NUXR_RADIUS_SECRET' => str_repeat('a', 40)];
        $this->assertSame('DISABLED', RestGate::refusal(null, null, $server));
        $this->assertSame('DISABLED', RestGate::refusal(str_repeat('a', 31), null, $server), 'too short a secret disables it');
        $this->assertNull(RestGate::refusal(str_repeat('a', 40), null, $server));
    }

    public function testSecretAndSourceAreBothRequired(): void
    {
        $secret = bin2hex(random_bytes(20));
        $basic = 'Basic ' . base64_encode('freeradius:' . $secret);
        $this->assertNull(RestGate::refusal($secret, null, ['REMOTE_ADDR' => '::1', 'HTTP_AUTHORIZATION' => $basic]), 'HTTP Basic (rlm_rest auth = basic)');
        $this->assertNull(RestGate::refusal($secret, null, ['REMOTE_ADDR' => '127.0.0.1', 'PHP_AUTH_PW' => $secret]));
        $this->assertSame('SECRET', RestGate::refusal($secret, null, ['REMOTE_ADDR' => '127.0.0.1']), 'no secret');
        $this->assertSame('SECRET', RestGate::refusal($secret, null, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_NUXR_RADIUS_SECRET' => $secret . 'x']));
        $this->assertSame('SOURCE', RestGate::refusal($secret, null, ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_NUXR_RADIUS_SECRET' => $secret]), 'default allowlist is loopback');
        $allow = '10.20.0.0/16, 2001:db8::/32 192.0.2.5';
        foreach (['10.20.255.1' => null, '10.21.0.1' => 'SOURCE', '2001:db8:1::5' => null, '2001:db9::1' => 'SOURCE', '192.0.2.5' => null, '192.0.2.6' => 'SOURCE', 'garbage' => 'SOURCE'] as $ip => $want) {
            $this->assertSame($want, RestGate::refusal($secret, $allow, ['REMOTE_ADDR' => $ip, 'HTTP_X_NUXR_RADIUS_SECRET' => $secret]), $ip);
        }
        $this->assertFalse(RestGate::allowed('10.0.0.1', '10.0.0.0/33'), 'invalid prefix ignored');
    }

    public function testUnknownIsNeverRetryable(): void
    {
        $u = ProvisionResult::unknown('X', 'y');
        $this->assertSame(['UNKNOWN', false], [$u->status, $u->retryable]);
        $f = ProvisionResult::failed('X', 'y', true);
        $this->assertSame(['FAILED', true], [$f->status, $f->retryable]);
        $this->assertTrue(ProvisionResult::success()->isSuccess());
        $this->assertSame(['SUCCESS', 'FAILED', 'UNKNOWN'], [ProvisionResult::SUCCESS, ProvisionResult::FAILED, ProvisionResult::UNKNOWN], 'the R0003 provisioning_result enum values');
    }
}
