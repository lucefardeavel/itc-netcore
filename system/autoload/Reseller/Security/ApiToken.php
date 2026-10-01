<?php
namespace Reseller\Security;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * OD-3 / OD-15: actor-bound staff API tokens, format a2.{uid}.{iat}.{mac}
 *
 *   mac = hex(HMAC-SHA256("nuxr-api-staff|v2|{uid}|{iat}|" . tbl_users.password, $api_secret))
 *
 * - $api_secret must be a dedicated config.php value (>= 32 chars, never equal to $db_pass); there is
 *   no fallback: without it every API request fails closed
 * - binding the MAC to the stored password hash revokes every earlier token when the password changes
 *   (existing per-user material; no schema change); the hash itself is never revealed
 * - iat must be numeric, not in the future (60 s skew) and at most 90 days old; the user must exist
 *   and be Active on every request
 */
final class ApiToken
{
    public const PREFIX = 'a2';
    public const MAX_AGE = 7776000;
    public const FUTURE_SKEW = 60;
    public const MIN_SECRET_LENGTH = 32;

    /** The dedicated signing secret, or null when the API must fail closed. */
    public static function secret(): ?string
    {
        $s = $GLOBALS['api_secret'] ?? null;
        $db = $GLOBALS['db_pass'] ?? null;
        if (!is_string($s) || strlen($s) < self::MIN_SECRET_LENGTH) {
            return null;
        }
        if (is_string($db) && $db !== '' && hash_equals($db, $s)) {
            return null;
        }
        return $s;
    }

    public static function issue(int $uid, string $passwordHash, ?int $iat = null): ?string
    {
        $secret = self::secret();
        if ($secret === null || $uid <= 0) {
            return null;
        }
        $iat ??= time();
        return self::PREFIX . '.' . $uid . '.' . $iat . '.' . self::mac($uid, $iat, $passwordHash, $secret);
    }

    /**
     * @return array<string,mixed>|null the authoritative, Active tbl_users row, or null if invalid
     */
    public static function verify(string $token, ?int $now = null): ?array
    {
        $secret = self::secret();
        if ($secret === null) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return null;
        }
        [, $uid, $iat, $mac] = $parts;
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $uid) || !preg_match('/^[1-9][0-9]{0,11}$/', $iat) || !preg_match('/^[0-9a-f]{64}$/', $mac)) {
            return null;
        }
        $now ??= time();
        $iat = (int) $iat;
        if ($iat > $now + self::FUTURE_SKEW || $now - $iat > self::MAX_AGE) {
            return null;
        }
        $st = \ORM::get_db()->prepare('SELECT * FROM tbl_users WHERE id = ?');
        $st->execute([(int) $uid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || ($row['status'] ?? '') !== 'Active') {
            return null;
        }
        $expected = self::mac((int) $uid, $iat, (string) $row['password'], $secret);
        return hash_equals($expected, $mac) ? $row : null;
    }

    private static function mac(int $uid, int $iat, string $passwordHash, string $secret): string
    {
        return hash_hmac('sha256', 'nuxr-api-staff|v2|' . $uid . '|' . $iat . '|' . $passwordHash, $secret);
    }
}
