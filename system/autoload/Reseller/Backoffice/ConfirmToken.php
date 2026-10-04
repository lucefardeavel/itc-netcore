<?php
namespace Reseller\Backoffice;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Domain\DomainError;

/**
 * WP6B review -> execute binding (plan §16.5): the review page carries a token = HMAC-SHA256(per-session key, the
 * reviewed parameters). Execute recomputes it from the posted values; any difference (a tampered amount, target,
 * reason or reference, another session) fails closed with REVIEW_EXPIRED before any service call. The key lives only in
 * the server session (the controller passes it in) and is never rendered. This is UI integrity only: every service
 * still validates and authorizes on its own.
 */
final class ConfirmToken
{
    /** @param array<string,mixed> $fields */
    public static function sign(string $key, array $fields): string
    {
        self::key($key);
        return hash_hmac('sha256', (string) json_encode(self::canonical($fields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key);
    }

    /** @param array<string,mixed> $fields */
    public static function verify(string $key, array $fields, mixed $token): void
    {
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/D', $token) || !hash_equals(self::sign($key, $fields), $token)) {
            throw new DomainError('REVIEW_EXPIRED');
        }
    }

    /** A new server-generated operation reference (accepted grammar: 8-64 of [A-Za-z0-9._:-], alphanumeric first). */
    public static function reference(string $op): string
    {
        return 'WEB-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $op) ?: 'OP', 0, 3)) . '-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
    }

    /** @param array<string,mixed> $fields @return array<string,string> */
    private static function canonical(array $fields): array
    {
        ksort($fields);
        return array_map(static fn($v): string => $v === null ? '' : (string) $v, $fields);
    }

    private static function key(string $key): void
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $key)) {
            throw new DomainError('REVIEW_EXPIRED');
        }
    }
}
