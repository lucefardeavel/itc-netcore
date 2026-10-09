<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\RadiusPacket;

/**
 * Sends RADIUS requests to the test FreeRADIUS (compose service freeradius-<engine>, secret
 * NUXR_RADIUS_SECRET) and returns the verified response. Development/test only.
 */
final class RadiusClient
{
    private static bool $ready = false;

    public static function host(): string
    {
        return 'freeradius-' . getenv('WP1_DB_HOST');
    }

    public static function secret(): string
    {
        $s = (string) getenv('NUXR_RADIUS_SECRET');
        if ($s === '') {
            throw new \RuntimeException('NUXR_RADIUS_SECRET must be set (use dev/test/run-tests.sh)');
        }
        return $s;
    }

    /**
     * Access-Request with PAP (password) or CHAP (chap = true).
     *
     * @param list<array{0:string,1:string|int}> $extra
     */
    public static function access(string $username, string $password, bool $chap = false, array $extra = []): ?RadiusPacket
    {
        $attrs = [['User-Name', $username], ['NAS-IP-Address', '127.0.0.1'], ['NAS-Port', 1]];
        if ($chap) {
            $challenge = random_bytes(16);
            $attrs[] = ['CHAP-Password', RadiusPacket::chapResponse(7, $password, $challenge)];
            $attrs[] = ['CHAP-Challenge', $challenge];
        } else {
            $attrs[] = ['User-Password', $password];
        }
        return self::send(1812, RadiusPacket::ACCESS_REQUEST, [...$attrs, ...$extra]);
    }

    /**
     * @param list<array{0:string,1:string|int}> $attrs
     * @param string $nas NAS-IP-Address of the accounting record (radacct.nasipaddress); WP8 A1 guard tests use TEST-NET NAS
     */
    public static function accounting(array $attrs, string $nas = '127.0.0.1'): ?RadiusPacket
    {
        return self::send(1813, RadiusPacket::ACCOUNTING_REQUEST, [['NAS-IP-Address', $nas], ...$attrs]);
    }

    /**
     * Waits (once per process) until FreeRADIUS answers: the container may still be starting.
     */
    public static function waitReady(): void
    {
        if (self::$ready) {
            return;
        }
        $deadline = microtime(true) + 90;
        while (microtime(true) < $deadline) {
            if (self::send(1812, RadiusPacket::ACCESS_REQUEST, [['User-Name', 'nuxr-readiness-probe'], ['User-Password', 'x'], ['NAS-IP-Address', '127.0.0.1']], 1.0) !== null) {
                self::$ready = true;
                return;
            }
        }
        throw new \RuntimeException('test FreeRADIUS did not answer');
    }

    /** @param list<array{0:string,1:string|int}> $attrs */
    private static function send(int $port, int $code, array $attrs, float $timeout = 5.0): ?RadiusPacket
    {
        $request = RadiusPacket::request($code, random_int(0, 255), $attrs, self::secret());
        $socket = @stream_socket_client('udp://' . self::host() . ':' . $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return null;
        }
        try {
            fwrite($socket, $request->raw);
            $deadline = microtime(true) + $timeout;
            while (($left = $deadline - microtime(true)) > 0) {
                stream_set_timeout($socket, (int) $left, (int) (($left - (int) $left) * 1000000));
                $raw = @fread($socket, 4096);
                if ($raw === false || $raw === '') {
                    return null;
                }
                $response = RadiusPacket::decode($raw);
                if (RadiusPacket::verifyResponse($response, $request, self::secret())) {
                    return $response;
                }
            }
            return null;
        } finally {
            fclose($socket);
        }
    }
}
