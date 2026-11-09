<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * Username-and-password hotspot cards (owner decision 2026-11-09, R0008): the voucher code is the user name and a
 * separate password, printed on the card, is the network password. A voucher without a row in tbl_voucher_logins is a
 * code-only card (the code is also the password, unchanged behaviour).
 *
 * The password is what RADIUS receives (Cleartext-Password), so it is stored in clear text, like
 * tbl_customers.password. It is never logged and never sent in a notification.
 */
final class VoucherLogin
{
    public const TABLE = 'tbl_voucher_logins';
    public const STYLE_CODE = 'code';
    public const STYLE_USERPASS = 'userpass';
    public const MIN_LENGTH = 4;
    public const MAX_LENGTH = 12;

    private static ?bool $available = null;

    /** True when R0008 is applied (the table exists). */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = \ORM::get_db()->query("SHOW TABLES LIKE '" . self::TABLE . "'")->fetchColumn() !== false;
            } catch (\Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /** A new card password of $length digits (CSPRNG). */
    public static function generate(int $length): string
    {
        $length = max(self::MIN_LENGTH, min(self::MAX_LENGTH, $length));
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }

    /** Stores the card password of a new voucher. */
    public static function create(int $voucherId, string $password): void
    {
        $st = \ORM::get_db()->prepare('INSERT INTO `' . self::TABLE . '` (voucher_id, password, created_at) VALUES (?, ?, ?)');
        $st->execute([$voucherId, $password, gmdate('Y-m-d H:i:s.u')]);
    }

    /**
     * @param list<int> $voucherIds
     * @return array<int,string> voucher id => card password (only the username-and-password cards)
     */
    public static function passwordsFor(array $voucherIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $voucherIds), static fn(int $i): bool => $i > 0)));
        if ($ids === [] || !self::available()) {
            return [];
        }
        $st = \ORM::get_db()->prepare('SELECT voucher_id, password FROM `' . self::TABLE . '` WHERE voucher_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['voucher_id']] = (string) $r['password'];
        }
        return $out;
    }

    /** The card password of the voucher with this exact code, or null for a code-only card (or an unknown code). */
    public static function passwordForCode(string $code): ?string
    {
        if ($code === '' || !self::available()) {
            return null;
        }
        $st = \ORM::get_db()->prepare('SELECT l.password FROM `' . self::TABLE . '` l JOIN tbl_voucher v ON v.id = l.voucher_id WHERE BINARY v.code = ? LIMIT 1');
        $st->execute([$code]);
        $p = $st->fetchColumn();
        return $p === false ? null : (string) $p;
    }

    /** The password the network receives for a voucher-only subscriber: the card password, else the code. */
    public static function networkPassword(string $code): string
    {
        return self::passwordForCode($code) ?? $code;
    }

    /**
     * True when $typed may activate the card $code: a code-only card needs nothing more; a username-and-password card
     * needs its exact password (constant-time comparison).
     */
    public static function accepts(string $code, string $typed): bool
    {
        $p = self::passwordForCode($code);
        return $p === null || hash_equals($p, $typed);
    }

    /** Removes the card passwords whose voucher no longer exists (called after every voucher delete). */
    public static function pruneOrphans(): void
    {
        if (!self::available()) {
            return;
        }
        \ORM::get_db()->exec('DELETE l FROM `' . self::TABLE . '` l LEFT JOIN tbl_voucher v ON v.id = l.voucher_id WHERE v.id IS NULL');
    }
}
