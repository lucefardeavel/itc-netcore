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
 *
 * UX-11 Arabic folding: a term with Arabic letters adds ONE more OR branch per name column (full name, address) that
 * compares the column folded by a fixed nested REPLACE() chain with the term folded the same way (normalize(); both
 * come from the single map ARABIC_FOLD), so "احمد" finds "أحمد". Plain REPLACE only (identical on MariaDB 10.11 and
 * MySQL 8.0; no REGEXP_REPLACE, no collation change, no schema change). The chain is built from the class constant,
 * never from input; the term is bound. The branch sits inside the same parenthesized OR group, so the scope predicate
 * the caller applies is unchanged. Arabic-Indic digits typed on an Arabic keyboard are read as ASCII digits (term()).
 */
final class CustomerSearch
{
    public const MIN = 2;
    public const MAX = 64;

    /**
     * Arabic spelling variants folded to one form (search only; stored data is never changed): alef forms -> bare alef,
     * teh marbuta -> heh, alef maksura -> yeh, Persian / Kurdish keheh, farsi yeh and ae -> Arabic kaf, yeh, heh; tatweel
     * and harakat (fathatan .. sukun, superscript alef) removed. The single source of normalize() and of the SQL chain.
     */
    public const ARABIC_FOLD = [
        "\u{0623}" => "\u{0627}", "\u{0625}" => "\u{0627}", "\u{0622}" => "\u{0627}", "\u{0671}" => "\u{0627}",
        "\u{0629}" => "\u{0647}", "\u{0649}" => "\u{064A}",
        "\u{06A9}" => "\u{0643}", "\u{06CC}" => "\u{064A}", "\u{06D5}" => "\u{0647}",
        "\u{0640}" => '',
        "\u{064B}" => '', "\u{064C}" => '', "\u{064D}" => '', "\u{064E}" => '', "\u{064F}" => '', "\u{0650}" => '', "\u{0651}" => '', "\u{0652}" => '',
        "\u{0670}" => '',
    ];

    /** Arabic-Indic (U+0660..U+0669) and extended Arabic-Indic / Persian (U+06F0..U+06F9) digits -> ASCII. */
    public const DIGITS = [
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
        "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
        "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
    ];

    /** The search form of a term: Arabic digits as ASCII and the ARABIC_FOLD variants folded (display text is never changed). */
    public static function normalize(string $term): string
    {
        return strtr(strtr($term, self::DIGITS), self::ARABIC_FOLD);
    }

    /** True when the term contains an Arabic-script letter (the trigger for the folded name branch). */
    public static function hasArabic(string $term): bool
    {
        return (bool) preg_match('/[\x{0621}-\x{064A}\x{066E}-\x{06D3}\x{06D5}\x{06EE}\x{06EF}\x{06FA}-\x{06FC}]/u', $term);
    }

    /** "REPLACE(REPLACE(<column>, 'أ', 'ا'), ...)" over ARABIC_FOLD; $column is a validated alias.column, the pairs are constants. */
    public static function foldSql(string $column): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/D', $column)) {
            throw new \InvalidArgumentException('invalid column');
        }
        $sql = $column;
        foreach (self::ARABIC_FOLD as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }
        return $sql;
    }

    /** The normalized search term, or null when it is not a usable term (too short / not a string). */
    public static function term(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', strtr($raw, self::DIGITS)));
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
        $folded = self::normalize($term);
        if (self::hasArabic($term) && mb_strlen($folded) >= self::MIN) {
            $foldedLike = '%' . addcslashes($folded, '\\%_') . '%';
            $parts[] = self::foldSql("{$table}.fullname") . ' LIKE ?';
            $params[] = $foldedLike;
            if ($withAddress) {
                $parts[] = self::foldSql("{$table}.address") . ' LIKE ?';
                $params[] = $foldedLike;
            }
        }
        $digits = (string) preg_replace('/\D/u', '', $term);
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
