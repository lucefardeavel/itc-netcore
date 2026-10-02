<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Authentication of the rlm_rest endpoint radius.php (SEC-01, owner decision Q3). The endpoint is OFF unless
 * the deployment configures it in config.php:
 *
 *   $radius_rest_secret = '<at least 32 random characters>';   // shared with the FreeRADIUS rest module
 *   $radius_rest_allow  = '127.0.0.1, ::1';                    // optional; IPs / CIDRs of the FreeRADIUS servers
 *
 * A request is accepted only from an allowed source address AND with the secret, sent as HTTP Basic auth
 * password (FreeRADIUS rest module `auth = 'basic'`) or as the header X-Nuxr-Radius-Secret. The secret is
 * compared in constant time and never logged. The allowlist names the FreeRADIUS servers that call this
 * endpoint (not the NAS devices: rlm_rest requests come from the RADIUS server).
 */
final class RestGate
{
    public const MIN_SECRET_LENGTH = 32;
    public const DEFAULT_ALLOW = '127.0.0.1, ::1';

    /** @return string|null null when accepted, else a refusal code (DISABLED, SOURCE, SECRET) */
    public static function refusal(?string $secret, ?string $allow, array $server): ?string
    {
        if ($secret === null || strlen($secret) < self::MIN_SECRET_LENGTH) {
            return 'DISABLED';
        }
        if (!self::allowed((string) ($server['REMOTE_ADDR'] ?? ''), $allow === null || trim($allow) === '' ? self::DEFAULT_ALLOW : $allow)) {
            return 'SOURCE';
        }
        $given = self::presented($server);
        if ($given === null || !hash_equals($secret, $given)) {
            return 'SECRET';
        }
        return null;
    }

    private static function presented(array $server): ?string
    {
        if (isset($server['HTTP_X_NUXR_RADIUS_SECRET']) && is_string($server['HTTP_X_NUXR_RADIUS_SECRET'])) {
            return $server['HTTP_X_NUXR_RADIUS_SECRET'];
        }
        if (isset($server['PHP_AUTH_PW']) && is_string($server['PHP_AUTH_PW'])) {
            return $server['PHP_AUTH_PW'];
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $h) {
            if (isset($server[$h]) && is_string($server[$h]) && preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/', trim($server[$h]), $m)) {
                $pair = base64_decode($m[1], true);
                if ($pair !== false && str_contains($pair, ':')) {
                    return explode(':', $pair, 2)[1];
                }
            }
        }
        return null;
    }

    /** Exact IP or CIDR match (IPv4 and IPv6). */
    public static function allowed(string $ip, string $allow): bool
    {
        $addr = @inet_pton($ip);
        if ($addr === false) {
            return false;
        }
        foreach (preg_split('/[\s,]+/', trim($allow)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            [$net, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
            $netAddr = @inet_pton($net);
            if ($netAddr === false || strlen($netAddr) !== strlen($addr)) {
                continue;
            }
            $max = strlen($addr) * 8;
            $bits = $bits === null ? $max : (ctype_digit($bits) ? (int) $bits : -1);
            if ($bits < 0 || $bits > $max) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($addr, 0, $bytes) !== substr($netAddr, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($addr[$bytes]) ^ ord($netAddr[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }
}
