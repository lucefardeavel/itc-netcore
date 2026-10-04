<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP6A subscriber search: one parenthesized, parameter-bound OR predicate over the subscriber identifiers
 * (username, full name, phone, PPPoE username, static IP, customer id, e-mail; optionally the address and the
 * usernames of open RADIUS sessions with a matching framed IP). It never authorizes: callers add it to a query that
 * is ALREADY restricted by DataScope (customer.read), so the scope predicate can never be bypassed, and they bound
 * the result (quick search: a small limit; list: server pagination). LIKE wildcards in the term are escaped.
 */
final class CustomerSearch
{
    public const MIN = 2;
    public const MAX = 64;

    /** The normalized search term, or null when it is not a usable term (too short / not a string). */
    public static function term(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if (mb_strlen($t) < self::MIN) {
            return null;
        }
        return mb_substr($t, 0, self::MAX);
    }

    /** An IPv4 prefix ("10.20", "10.20.30.41"), the trigger for the open-session framed-IP lookup. */
    public static function looksLikeIp(string $term): bool
    {
        return (bool) preg_match('/^\d{1,3}(\.\d{1,3}){1,3}\.?$/D', $term);
    }

    /**
     * @param list<string> $sessionUsernames usernames of open RADIUS sessions whose framed IP matches the term
     * @return array{0:string,1:list<mixed>}
     */
    public static function predicate(string $term, string $table = 'tbl_customers', bool $withAddress = false, array $sessionUsernames = []): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
            throw new \InvalidArgumentException('invalid table alias');
        }
        if (preg_match('/^#(\d{1,10})$/D', $term, $m)) {
            return ["({$table}.id = ?)", [(int) $m[1]]];
        }
        $esc = addcslashes($term, '\\%_');
        $like = '%' . $esc . '%';
        $parts = ["{$table}.username LIKE ?", "{$table}.fullname LIKE ?", "{$table}.pppoe_username LIKE ?", "{$table}.email LIKE ?", "{$table}.phonenumber LIKE ?", "{$table}.pppoe_ip LIKE ?"];
        $params = [$like, $like, $like, $like, $like, $esc . '%'];
        if ($withAddress) {
            $parts[] = "{$table}.address LIKE ?";
            $params[] = $like;
        }
        $digits = (string) preg_replace('/\D/', '', $term);
        if (strlen($digits) >= 3 && $digits !== $term) {
            $parts[] = "{$table}.phonenumber LIKE ?";
            $params[] = '%' . $digits . '%';
        }
        if (strlen($digits) >= 3 && class_exists('Lang') && ($formatted = (string) \Lang::phoneFormat($digits)) !== $digits) {
            $parts[] = "{$table}.phonenumber LIKE ?";
            $params[] = '%' . addcslashes($formatted, '\\%_') . '%';
        }
        if (preg_match('/^\d{1,10}$/D', $term)) {
            $parts[] = "{$table}.id = ?";
            $params[] = (int) $term;
        }
        $sessionUsernames = array_values(array_unique(array_filter($sessionUsernames, 'is_string')));
        if ($sessionUsernames !== []) {
            $in = implode(', ', array_fill(0, count($sessionUsernames), '?'));
            $parts[] = "{$table}.username IN ({$in})";
            $parts[] = "{$table}.pppoe_username IN ({$in})";
            $params = [...$params, ...$sessionUsernames, ...$sessionUsernames];
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
