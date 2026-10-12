<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Radius\RadiusPacket;

/**
 * TEST-ONLY byte-level RADIUS client for the WP8 A2-R PPPoE admission tests (harness and WP8 lab). Unlike
 * RadiusClient / Wp8Radius (which use the runtime encoder and its fixed attribute dictionary) it encodes the complete
 * RouterOS PPPoE attempt identity - including NAS-Port-Id (87) - and can:
 *   - re-send the EXACT bytes of a request (same Identifier, Request Authenticator, source port) = a retransmission;
 *   - send many requests at the same instant (non-blocking sockets) to make concurrent attempts race;
 *   - carry any Class value in Accounting-Requests (late, foreign, malformed tokens).
 * Responses are verified (Response Authenticator) and decoded with the runtime RadiusPacket::decode(). Development/test
 * only; the shared secret comes from the environment and is never printed.
 */
final class RadiusWire
{
    private const ATTR = [
        'User-Name' => [1, 's'], 'User-Password' => [2, 'o'], 'CHAP-Password' => [3, 'o'], 'NAS-IP-Address' => [4, 'ip'],
        'NAS-Port' => [5, 'i'], 'Service-Type' => [6, 'i'], 'Framed-Protocol' => [7, 'i'], 'Framed-IP-Address' => [8, 'ip'],
        'Class' => [25, 'o'], 'Called-Station-Id' => [30, 's'], 'Calling-Station-Id' => [31, 's'], 'NAS-Identifier' => [32, 's'],
        'Acct-Status-Type' => [40, 'i'], 'Acct-Delay-Time' => [41, 'i'], 'Acct-Session-Id' => [44, 's'], 'Acct-Session-Time' => [46, 'i'],
        'Acct-Terminate-Cause' => [49, 'i'], 'Event-Timestamp' => [55, 'i'], 'CHAP-Challenge' => [60, 'o'], 'NAS-Port-Type' => [61, 'i'],
        'NAS-Port-Id' => [87, 's'],
    ];
    public const PPPOE = [['Service-Type', 2], ['Framed-Protocol', 1], ['NAS-Port-Type', 15]];       // Framed-User, PPP, Ethernet
    public const HOTSPOT = [['Service-Type', 1], ['NAS-Port-Type', 19]];                             // Login-User, Wireless-802.11

    public function __construct(private readonly string $host, private readonly string $secret)
    {
    }

    /** the WP4 harness FreeRADIUS of the current engine (static clients.conf client) */
    public static function harness(): self
    {
        return new self(RadiusClient::host(), RadiusClient::secret());
    }

    /** the WP8 lab FreeRADIUS at 10.88.0.10 (SQL NAS client nuxr_wp8_app, the co-located runner) */
    public static function lab(): self
    {
        return new self('10.88.0.10', (string) getenv('NUXR_RADIUS_SECRET'));
    }

    /** a RouterOS-like PPPoE attempt identity (fresh Acct-Session-Id and client MAC unless given) */
    public static function attempt(string $nas, ?string $sessionId = null, ?string $mac = null, string $port = 'ether3'): array
    {
        return [
            'nas' => $nas,
            'sid' => $sessionId ?? sprintf('8f%06x', random_int(0, 0xffffff)),
            'mac' => $mac ?? strtoupper(implode(':', array_merge(['02', 'A2'], str_split(bin2hex(random_bytes(4)), 2)))),
            'port' => $port,
        ];
    }

    /**
     * Access-Request bytes for a PPPoE attempt.
     * @param array{nas:string,sid:string,mac:string,port:string} $a
     * @param 'pap'|'chap'|'mschapv2' $method
     * @param list<string> $drop attribute names to leave out (identity probes)
     * @param list<array{0:string|int,1:string|int}> $extra
     */
    public function pppoeAccess(string $user, string $password, array $a, string $method = 'chap', array $drop = [], array $extra = []): string
    {
        $attrs = [['User-Name', $user], ['NAS-IP-Address', $a['nas']], ['NAS-Port', 15728640], ...self::PPPOE,
            ['Acct-Session-Id', $a['sid']], ['Calling-Station-Id', $a['mac']], ['Called-Station-Id', 'nuxr_wp8'], ['NAS-Port-Id', $a['port']], ...$extra];
        $attrs = array_values(array_filter($attrs, static fn($x) => !in_array($x[0], $drop, true)));
        return $this->accessBytes($attrs, $password, $method);
    }

    /**
     * Access-Request bytes for a RouterOS 7.24.5-shaped HOTSPOT login (WP8 H-2B: Login-User + Wireless-802.11 with the
     * attempt identity the Hotspot admission slots require - NAS-IP-Address, Acct-Session-Id, Calling-Station-Id; no
     * NAS-Port-Id, as RouterOS Hotspot sends none).
     * @param array{nas:string,sid:string,mac:string,port:string} $a
     * @param list<string> $drop attribute names to leave out (identity probes)
     * @param list<array{0:string|int,1:string|int}> $extra
     */
    public function hotspotAccess(string $user, string $password, array $a, string $method = 'chap', array $drop = [], array $extra = []): string
    {
        $attrs = [['User-Name', $user], ['NAS-IP-Address', $a['nas']], ['NAS-Port', 2147483700], ...self::HOTSPOT,
            ['Acct-Session-Id', $a['sid']], ['Calling-Station-Id', $a['mac']], ['Called-Station-Id', 'nuxr_wp8_hs'], ...$extra];
        $attrs = array_values(array_filter($attrs, static fn($x) => !in_array($x[0], $drop, true)));
        return $this->accessBytes($attrs, $password, $method);
    }

    /**
     * Hotspot accounting of an attempt (WP8 H-2B), RouterOS-shaped.
     * @param array{nas:string,sid:string,mac:string,port:string} $a
     * @param 'Start'|'Interim-Update'|'Stop' $status
     */
    public function hotspotAccounting(string $user, array $a, string $status, ?string $class = null, ?string $nas = null): ?RadiusPacket
    {
        $code = ['Start' => 1, 'Stop' => 2, 'Interim-Update' => 3][$status];
        $attrs = [['User-Name', $user], ['NAS-IP-Address', $nas ?? $a['nas']], ['Acct-Status-Type', $code], ['Acct-Delay-Time', 0], ['Event-Timestamp', time()],
            ['Acct-Session-Id', $a['sid']], ['Calling-Station-Id', $a['mac']], ['NAS-Port-Type', 19], ['Service-Type', 1],
            ['Framed-IP-Address', '10.78.' . random_int(0, 255) . '.' . random_int(2, 250)]];
        if ($status === 'Stop') {
            $attrs[] = ['Acct-Session-Time', 5];
            $attrs[] = ['Acct-Terminate-Cause', 1];
        }
        if ($class !== null) {
            $attrs[] = ['Class', $class];
        }
        return $this->send($this->accountingBytes($attrs), 1813);
    }

    /** @param list<array{0:string|int,1:string|int}> $attrs */
    public function accessBytes(array $attrs, string $password, string $method = 'pap'): string
    {
        $auth = random_bytes(16);
        $body = '';
        foreach ($attrs as [$n, $v]) {
            $body .= self::enc($n, $v);
        }
        if ($method === 'chap') {
            $challenge = random_bytes(16);
            $id = random_int(0, 255);
            $body .= self::enc('CHAP-Password', chr($id) . md5(chr($id) . $password . $challenge, true)) . self::enc('CHAP-Challenge', $challenge);
        } elseif ($method === 'mschapv2') {
            // Microsoft vendor attributes (311): MS-CHAP-Challenge (11) + MS-CHAP2-Response (25) - not configured in the
            // project virtual server; the request must be refused
            $vsa = static fn(int $t, string $v): string => chr(26) . chr(strlen($v) + 8) . pack('N', 311) . chr($t) . chr(strlen($v) + 2) . $v;
            $body .= $vsa(11, random_bytes(16)) . $vsa(25, chr(1) . chr(0) . random_bytes(16) . str_repeat("\0", 8) . random_bytes(24));
        } else {
            $p = $password . str_repeat("\0", $password === '' ? 16 : (16 - strlen($password) % 16) % 16);
            $out = '';
            $prev = $auth;
            foreach (str_split($p, 16) as $block) {
                $prev = $block ^ md5($this->secret . $prev, true);
                $out .= $prev;
            }
            $body .= self::enc('User-Password', $out);
        }
        $len = 20 + strlen($body) + 18;
        $head = chr(1) . chr(random_int(0, 255)) . pack('n', $len);
        $mac = hash_hmac('md5', $head . $auth . $body . chr(80) . chr(18) . str_repeat("\0", 16), $this->secret, true);
        return $head . $auth . $body . chr(80) . chr(18) . $mac;
    }

    /**
     * Accounting-Request bytes (no Message-Authenticator, like RouterOS).
     * @param list<array{0:string|int,1:string|int}> $attrs
     */
    public function accountingBytes(array $attrs): string
    {
        $body = '';
        foreach ($attrs as [$n, $v]) {
            $body .= self::enc($n, $v);
        }
        $head = chr(4) . chr(random_int(0, 255)) . pack('n', 20 + strlen($body));
        return $head . md5($head . str_repeat("\0", 16) . $body . $this->secret, true) . $body;
    }

    /**
     * PPPoE accounting of an attempt.
     * @param array{nas:string,sid:string,mac:string,port:string} $a
     * @param 'Start'|'Interim-Update'|'Stop'|'Accounting-On' $status
     */
    public function pppoeAccounting(string $user, array $a, string $status, ?string $class = null, ?string $nas = null): ?RadiusPacket
    {
        $code = ['Start' => 1, 'Stop' => 2, 'Interim-Update' => 3, 'Accounting-On' => 7][$status];
        $attrs = [['NAS-IP-Address', $nas ?? $a['nas']], ['Acct-Status-Type', $code], ['Acct-Delay-Time', 0], ['Event-Timestamp', time()]];
        if ($status !== 'Accounting-On') {
            $attrs = [['User-Name', $user], ...$attrs, ['Acct-Session-Id', $a['sid']], ['Calling-Station-Id', $a['mac']], ['NAS-Port-Id', $a['port']],
                ['NAS-Port-Type', 15], ['Service-Type', 2], ['Framed-Protocol', 1], ['Framed-IP-Address', '10.77.' . random_int(0, 255) . '.' . random_int(2, 250)]];
            if ($status === 'Stop') {
                $attrs[] = ['Acct-Session-Time', 5];
                $attrs[] = ['Acct-Terminate-Cause', 1];
            }
        } else {
            $attrs[] = ['Acct-Session-Id', '00000000'];
        }
        if ($class !== null) {
            $attrs[] = ['Class', $class];
        }
        return $this->send($this->accountingBytes($attrs), 1813);
    }

    /** sends $raw (optionally on a given socket, e.g. to re-send the same bytes from the same source port) */
    public function send(string $raw, int $port = 1812, float $timeout = 5.0, $socket = null): ?RadiusPacket
    {
        $own = $socket === null;
        $socket ??= $this->socket($port);
        try {
            fwrite($socket, $raw);
            return $this->receive($socket, $raw, $timeout);
        } finally {
            if ($own) {
                fclose($socket);
            }
        }
    }

    /**
     * Sends $raw and returns at once (the reply is read later with finish()): for requests that must be in flight while
     * the test changes the database (locks, renamed tables).
     * @return resource
     */
    public function begin(string $raw, int $port = 1812)
    {
        $s = $this->socket($port);
        fwrite($s, $raw);
        return $s;
    }

    /** @param resource $socket */
    public function finish($socket, string $raw, float $timeout = 10.0): ?RadiusPacket
    {
        try {
            return $this->receive($socket, $raw, $timeout);
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    public function socket(int $port = 1812)
    {
        $s = @stream_socket_client('udp://' . $this->host . ':' . $port, $errno, $errstr, 5);
        if ($s === false) {
            throw new \RuntimeException("RADIUS socket: {$errstr}");
        }
        return $s;
    }

    /**
     * Sends every request at (nearly) the same instant, each from its own source port, and collects the replies.
     * @param list<string> $raws
     * @return list<?RadiusPacket>
     */
    public function sendMany(array $raws, int $port = 1812, float $timeout = 8.0): array
    {
        $socks = array_map(fn() => $this->socket($port), $raws);
        foreach ($socks as $s) {
            stream_set_blocking($s, false);
        }
        foreach ($raws as $i => $raw) {
            fwrite($socks[$i], $raw);
        }
        $out = array_fill(0, count($raws), null);
        $deadline = microtime(true) + $timeout;
        $open = array_keys($raws);
        while ($open && microtime(true) < $deadline) {
            $r = array_map(static fn($i) => $socks[$i], $open);
            $w = $e = null;
            if (@stream_select($r, $w, $e, 0, 200000) < 1) {
                continue;
            }
            foreach ($open as $k => $i) {
                if (!in_array($socks[$i], $r, true)) {
                    continue;
                }
                $data = fread($socks[$i], 4096);
                if ($data !== false && $data !== '' && ($p = $this->verified($data, $raws[$i])) !== null) {
                    $out[$i] = $p;
                    unset($open[$k]);
                }
            }
        }
        foreach ($socks as $s) {
            fclose($s);
        }
        return $out;
    }

    /** the Class value of a reply as hex (or null) */
    public static function classHex(?RadiusPacket $p): ?string
    {
        if ($p === null) {
            return null;
        }
        foreach ($p->attributes as [$name, $value]) {
            if ($name === 'Class') {
                return bin2hex((string) $value);
            }
        }
        return null;
    }

    /** the 32-hex admission token carried by a reply's Class ("nuxr1" + 16 bytes), or null */
    public static function token(?RadiusPacket $p): ?string
    {
        $c = self::classHex($p);
        return $c !== null && preg_match('/^6e75787231([0-9a-f]{32})$/', $c, $m) ? $m[1] : null;
    }

    /** the 32-hex Hotspot slot token carried by a reply's Class ("nuxh1" + 16 bytes, WP8 H-2B), or null */
    public static function hotspotToken(?RadiusPacket $p): ?string
    {
        $c = self::classHex($p);
        return $c !== null && preg_match('/^6e75786831([0-9a-f]{32})$/', $c, $m) ? $m[1] : null;
    }

    /** @param resource $socket */
    private function receive($socket, string $raw, float $timeout): ?RadiusPacket
    {
        stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1e6));
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $data = fread($socket, 4096);
            if ($data === false || $data === '') {
                if (stream_get_meta_data($socket)['timed_out']) {
                    return null;
                }
                continue;
            }
            if (($p = $this->verified($data, $raw)) !== null) {
                return $p;
            }
        }
        return null;
    }

    private function verified(string $data, string $request): ?RadiusPacket
    {
        if (strlen($data) < 20 || $data[1] !== $request[1]) {
            return null;
        }
        $len = unpack('n', substr($data, 2, 2))[1];
        $data = substr($data, 0, $len);
        if (md5(substr($data, 0, 4) . substr($request, 4, 16) . substr($data, 20) . $this->secret, true) !== substr($data, 4, 16)) {
            return null;
        }
        return RadiusPacket::decode($data);
    }

    private static function enc(string|int $name, string|int $value): string
    {
        [$type, $kind] = is_int($name) ? [$name, 'o'] : (self::ATTR[$name] ?? throw new \InvalidArgumentException("unknown attribute {$name}"));
        $v = match ($kind) {
            'i' => pack('N', (int) $value),
            'ip' => (string) inet_pton((string) $value),
            default => (string) $value,
        };
        if (strlen($v) > 253) {
            throw new \InvalidArgumentException('attribute too long');
        }
        return chr($type) . chr(strlen($v) + 2) . $v;
    }
}
