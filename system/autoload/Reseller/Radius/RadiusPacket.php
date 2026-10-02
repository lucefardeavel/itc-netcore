<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Minimal RADIUS packet codec (RFC 2865 / 2866 / 3579 / 5176) for the native CoA / Disconnect client and the
 * contract tests: no external process, no shell. Attributes are a list of [name, value] pairs; only the
 * attributes in ATTRIBUTES / VENDOR are encoded by name (unknown received attributes decode as "Attr-<n>").
 *
 *  - Access-Request: random Request Authenticator, User-Password hidden per RFC 2865 §5.2;
 *  - Accounting / Disconnect / CoA requests: Request Authenticator = MD5(Code+ID+Length+16 zero+Attrs+Secret);
 *  - every request carries Message-Authenticator (HMAC-MD5, RFC 3579 §3.2), computed with the authenticator
 *    zeroed for Accounting / Disconnect / CoA requests (RFC 5176) and with the Request Authenticator for Access;
 *  - responses are accepted only with the expected identifier, a valid Response Authenticator and, when
 *    present, a valid Message-Authenticator.
 */
final class RadiusPacket
{
    public const ACCESS_REQUEST = 1;
    public const ACCESS_ACCEPT = 2;
    public const ACCESS_REJECT = 3;
    public const ACCOUNTING_REQUEST = 4;
    public const ACCOUNTING_RESPONSE = 5;
    public const DISCONNECT_REQUEST = 40;
    public const DISCONNECT_ACK = 41;
    public const DISCONNECT_NAK = 42;
    public const COA_REQUEST = 43;
    public const COA_ACK = 44;
    public const COA_NAK = 45;

    /** name => [number, type] (type: string | octets | integer | ipaddr | date) */
    public const ATTRIBUTES = [
        'User-Name' => [1, 'string'], 'User-Password' => [2, 'octets'], 'CHAP-Password' => [3, 'octets'],
        'NAS-IP-Address' => [4, 'ipaddr'], 'NAS-Port' => [5, 'integer'], 'Service-Type' => [6, 'integer'],
        'Framed-Protocol' => [7, 'integer'], 'Framed-IP-Address' => [8, 'ipaddr'], 'Framed-IP-Netmask' => [9, 'ipaddr'],
        'Reply-Message' => [18, 'string'], 'State' => [24, 'octets'], 'Class' => [25, 'octets'],
        'Session-Timeout' => [27, 'integer'], 'Idle-Timeout' => [28, 'integer'], 'Called-Station-Id' => [30, 'string'],
        'Calling-Station-Id' => [31, 'string'], 'NAS-Identifier' => [32, 'string'], 'Proxy-State' => [33, 'octets'],
        'Acct-Status-Type' => [40, 'integer'], 'Acct-Delay-Time' => [41, 'integer'], 'Acct-Input-Octets' => [42, 'integer'],
        'Acct-Output-Octets' => [43, 'integer'], 'Acct-Session-Id' => [44, 'string'], 'Acct-Session-Time' => [46, 'integer'],
        'Acct-Terminate-Cause' => [49, 'integer'], 'Acct-Input-Gigawords' => [52, 'integer'], 'Acct-Output-Gigawords' => [53, 'integer'],
        'Event-Timestamp' => [55, 'date'], 'CHAP-Challenge' => [60, 'octets'], 'NAS-Port-Type' => [61, 'integer'],
        'Port-Limit' => [62, 'integer'], 'Message-Authenticator' => [80, 'octets'], 'Framed-Pool' => [88, 'string'],
        'Error-Cause' => [101, 'integer'],
    ];

    /** Vendor-Specific (26): name => [vendor id, vendor type, type] */
    public const VENDOR = [
        'Mikrotik-Rate-Limit' => [14988, 8, 'string'], 'Mikrotik-Total-Limit' => [14988, 17, 'integer'],
        'Mikrotik-Total-Limit-Gigawords' => [14988, 18, 'integer'],
        'WISPr-Bandwidth-Max-Up' => [14122, 7, 'integer'], 'WISPr-Bandwidth-Max-Down' => [14122, 8, 'integer'],
        'WISPr-Session-Terminate-Time' => [14122, 9, 'string'],
    ];

    /** @param list<array{0:string,1:string|int}> $attributes */
    private function __construct(
        public readonly int $code,
        public readonly int $identifier,
        public readonly string $authenticator,
        public readonly array $attributes,
        public readonly string $raw,
    ) {
    }

    /**
     * Encodes a request packet. Returns the packet; its authenticator is available via decode() or the
     * returned object (needed to verify the response).
     *
     * @param list<array{0:string,1:string|int}> $attributes
     */
    public static function request(int $code, int $identifier, array $attributes, string $secret): self
    {
        if ($secret === '' || $identifier < 0 || $identifier > 255) {
            throw new \InvalidArgumentException('RADIUS_REQUEST_INVALID');
        }
        $access = $code === self::ACCESS_REQUEST;
        $auth = $access ? random_bytes(16) : str_repeat("\0", 16);
        $body = '';
        foreach ($attributes as [$name, $value]) {
            if ($name === 'Message-Authenticator') {
                continue;
            }
            if ($name === 'User-Password') {
                $value = self::hidePassword((string) $value, $secret, $auth);
            }
            $body .= self::encode($name, $value);
        }
        $ma = chr(80) . chr(18) . str_repeat("\0", 16);
        $len = 20 + strlen($body) + 18;
        $head = chr($code) . chr($identifier) . pack('n', $len);
        $hmac = hash_hmac('md5', $head . $auth . $body . $ma, $secret, true);
        $body .= chr(80) . chr(18) . $hmac;
        if (!$access) {
            $auth = md5($head . str_repeat("\0", 16) . $body . $secret, true);
        }
        $raw = $head . $auth . $body;
        return new self($code, $identifier, $auth, self::decodeAttributes(substr($raw, 20)), $raw);
    }

    /** Decodes a packet (no authentication check; see verifyResponse()). */
    public static function decode(string $raw): self
    {
        if (strlen($raw) < 20) {
            throw new \InvalidArgumentException('RADIUS_PACKET_SHORT');
        }
        $len = unpack('n', substr($raw, 2, 2))[1];
        if ($len < 20 || $len > strlen($raw) || $len > 4096) {
            throw new \InvalidArgumentException('RADIUS_PACKET_LENGTH');
        }
        $raw = substr($raw, 0, $len);
        return new self(ord($raw[0]), ord($raw[1]), substr($raw, 4, 16), self::decodeAttributes(substr($raw, 20)), $raw);
    }

    /** Response Authenticator (and Message-Authenticator when present) of a reply to $request. */
    public static function verifyResponse(self $response, self $request, string $secret): bool
    {
        if ($response->identifier !== $request->identifier) {
            return false;
        }
        $raw = $response->raw;
        $expected = md5(substr($raw, 0, 4) . $request->authenticator . substr($raw, 20) . $secret, true);
        if (!hash_equals($expected, $response->authenticator)) {
            return false;
        }
        $pos = self::messageAuthenticatorOffset($raw);
        if ($pos !== null) {
            $zeroed = substr($raw, 0, 4) . $request->authenticator . substr($raw, 20, $pos - 20) . str_repeat("\0", 16) . substr($raw, $pos + 16);
            if (!hash_equals(hash_hmac('md5', $zeroed, $secret, true), substr($raw, $pos, 16))) {
                return false;
            }
        }
        return true;
    }

    /** First value of an attribute, or null. */
    public function get(string $name): string|int|null
    {
        foreach ($this->attributes as [$n, $v]) {
            if ($n === $name) {
                return $v;
            }
        }
        return null;
    }

    /** RFC 1994 CHAP response for a password (tests and the REST endpoint verifier share the algorithm). */
    public static function chapResponse(int $chapId, string $password, string $challenge): string
    {
        return chr($chapId) . md5(chr($chapId) . $password . $challenge, true);
    }

    private static function hidePassword(string $password, string $secret, string $auth): string
    {
        if (strlen($password) > 128) {
            throw new \InvalidArgumentException('RADIUS_PASSWORD_LENGTH');
        }
        $padded = str_pad($password, (int) max(16, ceil(strlen($password) / 16) * 16), "\0");
        $out = '';
        $prev = $auth;
        foreach (str_split($padded, 16) as $block) {
            $c = $block ^ md5($secret . $prev, true);
            $out .= $c;
            $prev = $c;
        }
        return $out;
    }

    private static function encode(string $name, string|int $value): string
    {
        if (isset(self::ATTRIBUTES[$name])) {
            [$number, $type] = self::ATTRIBUTES[$name];
            $data = self::value($type, $value);
            if (strlen($data) > 253) {
                throw new \InvalidArgumentException('RADIUS_ATTRIBUTE_LENGTH');
            }
            return chr($number) . chr(strlen($data) + 2) . $data;
        }
        if (isset(self::VENDOR[$name])) {
            [$vendor, $vtype, $type] = self::VENDOR[$name];
            $data = self::value($type, $value);
            if (strlen($data) > 247) {
                throw new \InvalidArgumentException('RADIUS_ATTRIBUTE_LENGTH');
            }
            $vsa = pack('N', $vendor) . chr($vtype) . chr(strlen($data) + 2) . $data;
            return chr(26) . chr(strlen($vsa) + 2) . $vsa;
        }
        throw new \InvalidArgumentException('RADIUS_ATTRIBUTE_UNKNOWN');
    }

    private static function value(string $type, string|int $value): string
    {
        return match ($type) {
            'integer', 'date' => pack('N', (int) $value),
            'ipaddr' => (string) (filter_var((string) $value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? inet_pton((string) $value) : throw new \InvalidArgumentException('RADIUS_IPADDR_INVALID')),
            default => (string) $value,
        };
    }

    /** @return list<array{0:string,1:string|int}> */
    private static function decodeAttributes(string $data): array
    {
        $out = [];
        $byNumber = [];
        foreach (self::ATTRIBUTES as $name => [$number, $type]) {
            $byNumber[$number] = [$name, $type];
        }
        $vendorMap = [];
        foreach (self::VENDOR as $name => [$vendor, $vtype, $type]) {
            $vendorMap[$vendor . ':' . $vtype] = [$name, $type];
        }
        $i = 0;
        $n = strlen($data);
        while ($i + 2 <= $n) {
            $type = ord($data[$i]);
            $len = ord($data[$i + 1]);
            if ($len < 2 || $i + $len > $n) {
                throw new \InvalidArgumentException('RADIUS_ATTRIBUTE_MALFORMED');
            }
            $value = substr($data, $i + 2, $len - 2);
            if ($type === 26 && strlen($value) >= 6) {
                $vendor = unpack('N', substr($value, 0, 4))[1];
                $vtype = ord($value[4]);
                [$name, $t] = $vendorMap[$vendor . ':' . $vtype] ?? ['Vendor-' . $vendor . '-Attr-' . $vtype, 'octets'];
                $out[] = [$name, self::readValue($t, substr($value, 6))];
            } else {
                [$name, $t] = $byNumber[$type] ?? ['Attr-' . $type, 'octets'];
                $out[] = [$name, self::readValue($t, $value)];
            }
            $i += $len;
        }
        return $out;
    }

    private static function readValue(string $type, string $data): string|int
    {
        return match ($type) {
            'integer', 'date' => strlen($data) === 4 ? unpack('N', $data)[1] : bin2hex($data),
            'ipaddr' => strlen($data) === 4 ? (string) inet_ntop($data) : bin2hex($data),
            'string' => $data,
            default => $data,
        };
    }

    private static function messageAuthenticatorOffset(string $raw): ?int
    {
        $i = 20;
        $n = strlen($raw);
        while ($i + 2 <= $n) {
            $len = ord($raw[$i + 1]);
            if ($len < 2) {
                return null;
            }
            if (ord($raw[$i]) === 80 && $len === 18) {
                return $i + 2;
            }
            $i += $len;
        }
        return null;
    }
}
