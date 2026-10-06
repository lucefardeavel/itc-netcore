<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\RadiusPacket;

/**
 * TEST-ONLY raw RADIUS client (WP8, owner decision OD-W8-13 / amendment W8-A3). It starts from the runtime encoder
 * RadiusPacket::request() - which always appends a valid Message-Authenticator as the last attribute - and derives the
 * negative probes from it WITHOUT any runtime switch:
 *   'valid'   the runtime packet unchanged
 *   'missing' the trailing Message-Authenticator removed (length fixed)
 *   'bogus'   the trailing Message-Authenticator replaced by random bytes (wrong HMAC)
 * Accounting-Requests can be sent without Message-Authenticator, like a RouterOS NAS does (Request Authenticator
 * recomputed per RFC 2866). Development/test only.
 */
final class Wp8Radius
{
    public function __construct(private readonly string $host, private readonly string $secret, private readonly string $nasIp)
    {
    }

    /** the WP8 lab: FreeRADIUS at 10.88.0.10, reached from the co-located runner, i.e. the SQL NAS client nuxr_wp8_app */
    public static function lab(): self
    {
        return new self('10.88.0.10', (string) getenv('NUXR_RADIUS_SECRET'), '10.88.0.10');
    }

    /** the WP4 harness FreeRADIUS (static clients.conf client) */
    public static function harness(): self
    {
        return new self(RadiusClient::host(), RadiusClient::secret(), '127.0.0.1');
    }

    /**
     * @param 'valid'|'missing'|'bogus' $ma
     * @param list<array{0:string,1:string|int}> $extra
     */
    public function access(string $username, string $password, string $ma = 'valid', bool $chap = false, array $extra = []): ?RadiusPacket
    {
        $attrs = [['User-Name', $username], ['NAS-IP-Address', $this->nasIp], ['NAS-Port', 1]];
        if ($chap) {
            $challenge = random_bytes(16);
            $attrs[] = ['CHAP-Password', RadiusPacket::chapResponse(5, $password, $challenge)];
            $attrs[] = ['CHAP-Challenge', $challenge];
        } else {
            $attrs[] = ['User-Password', $password];
        }
        $raw = RadiusPacket::request(RadiusPacket::ACCESS_REQUEST, random_int(0, 255), [...$attrs, ...$extra], $this->secret)->raw;
        $raw = match ($ma) {
            'valid' => $raw,
            'missing' => self::withLength(substr($raw, 0, -18)),
            'bogus' => substr($raw, 0, -16) . random_bytes(16),
        };
        return $this->send(1812, $raw);
    }

    /**
     * Accounting-Request WITHOUT Message-Authenticator (as RouterOS sends it).
     * @param list<array{0:string,1:string|int}> $attrs
     */
    public function accounting(array $attrs): ?RadiusPacket
    {
        $raw = RadiusPacket::request(RadiusPacket::ACCOUNTING_REQUEST, random_int(0, 255), [['NAS-IP-Address', $this->nasIp], ...$attrs], $this->secret)->raw;
        $raw = self::withLength(substr($raw, 0, -18));
        $raw = substr($raw, 0, 4) . md5(substr($raw, 0, 4) . str_repeat("\0", 16) . substr($raw, 20) . $this->secret, true) . substr($raw, 20);
        return $this->send(1813, $raw);
    }

    /** the identifier of the last request sent (to correlate with the FreeRADIUS debug log) */
    public int $lastId = -1;

    private static function withLength(string $raw): string
    {
        return substr($raw, 0, 2) . pack('n', strlen($raw)) . substr($raw, 4);
    }

    private function send(int $port, string $raw, float $timeout = 4.0): ?RadiusPacket
    {
        $request = RadiusPacket::decode($raw);
        $this->lastId = $request->identifier;
        $socket = @stream_socket_client('udp://' . $this->host . ':' . $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new \RuntimeException("RADIUS socket: {$errstr}");
        }
        try {
            stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1e6));
            fwrite($socket, $raw);
            $deadline = microtime(true) + $timeout;
            while (microtime(true) < $deadline) {
                $reply = fread($socket, 4096);
                if ($reply === false || $reply === '') {
                    $meta = stream_get_meta_data($socket);
                    if ($meta['timed_out']) {
                        return null;
                    }
                    continue;
                }
                $response = RadiusPacket::decode($reply);
                if (RadiusPacket::verifyResponse($response, $request, $this->secret)) {
                    return $response;
                }
            }
            return null;
        } finally {
            fclose($socket);
        }
    }
}
