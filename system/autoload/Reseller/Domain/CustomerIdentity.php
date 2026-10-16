<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

use Reseller\Security\ErrorReporter;

/**
 * WP9 U0 (OBS-1 / L-A5-2 / OBS-W9-V1; AM-W9-1, owner D-U0-1 … D-U0-8): the shared identity lock and the identity
 * availability check of every application writer that ACQUIRES a RADIUS-relevant name.
 *
 * Acquirers (owner_review/WP9/U0_DESIGN.md §1.1): P1 CustomerService::create, P2 register, P3 voucher + new username,
 * P4 legacy customer edit (changed names), P5 / P5r reseller-owned rename and its revert, P6 voucher-only redemption
 * (the claim is the durable reservation of the code). Releasers and key writers take no identity lock.
 *
 * Lock: one named lock per database (L-3), taken only OUTSIDE a transaction, after the tree lock (L-1) and the voucher
 * username lock (L-2) when a path holds them, never before them (global order L-1 → L-2 → L-3 → BEGIN). Every identity
 * write commits before the lock is released (an open transaction left by the callback is rolled back). No RADIUS write,
 * device or network call runs under it; only bounded, read-only, single-statement lookups (D-U0-2).
 *
 * Canonical identity: PHP trim() (RadiusPolicyBuilder::key(): space, TAB, LF, CR, NUL, VT) compared under
 * utf8mb4_general_ci (the radcheck.username collation: case- and accent-insensitive), values passed as HEX.
 * A name is taken when its canonical form equals:
 *  (a) the username or the PPPoE username of another customer (owned or unassigned);
 *  (b) a live voucher identity: a CLAIMED voucher code (in flight or under review), a voucher redeemed voucher-only
 *      (status 1, user '0'), or the username of a voucher-only recharge (customer_id 0) - permanent (D-U0-3);
 *      unused codes (status 0) are NOT identities (no code-enumeration oracle);
 *  (c) a key that holds RADIUS policy rows (radcheck / radreply / radusergroup) other than the acquirer's own keys -
 *      a renamed-away key stays unavailable until its rows are renamed or removed (U0 gap G3).
 * Any error, an empty name or more candidate rows than the bound means "cannot check" (null): callers fail closed.
 */
final class CustomerIdentity
{
    /** owner D-U0-5: web paths wait at most 10 s, the RADIUS voucher authorization at most 3 s */
    public const TIMEOUT = 10;
    public const RADIUS_TIMEOUT = 3;
    /** candidate rows per lookup; more = the check fails closed (open availability limit, WP9 REPORT §A1.9) */
    public const CANDIDATES = 1000;
    /** per-statement time bound of every lookup (MySQL MAX_EXECUTION_TIME / MariaDB max_statement_time) */
    public const STATEMENT_MS = 2000;
    private const TRIM_RE = '^[ \t\n\r\x0B\x00]+|[ \t\n\r\x0B\x00]+$';
    private const EDGE_RE = '^[ \t\n\r\x0B\x00]|[ \t\n\r\x0B\x00]$';

    /** @var array<int,bool> spl_object_id(PDO) => is MariaDB */
    private static array $mariadb = [];

    public static function name(\PDO $pdo): string
    {
        return 'nuxr_identity_' . substr(sha1((string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 20);
    }

    /**
     * Runs $fn under the identity lock (L-3). Refuses inside an open transaction (LogicException); a lock that is
     * not obtained within $timeout seconds, or a lock error, refuses with nothing written (DomainError).
     *
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    public static function hold(callable $fn, int $timeout = self::TIMEOUT): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('the identity lock is taken before any transaction');
        }
        // the RADIUS connection is opened OUTSIDE the lock (a slow connect never extends the lock hold)
        self::radiusPdo();
        $name = self::name($pdo);
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $st->execute([$name, $timeout]);
            $got = (string) $st->fetchColumn();
        } catch (\PDOException $e) {
            // e.g. MySQL ER_USER_LOCK_DEADLOCK: refused, nothing written
            ErrorReporter::report($e, 'identity-lock');
            throw new DomainError('IDENTITY_CHECK_UNAVAILABLE');
        }
        if ($got !== '1') {
            throw new DomainError('LOCK_TIMEOUT', 'The identity check is busy; please try again.');
        }
        try {
            // test seam (no-op unless a test hook is set): inside the identity lock, before the callback
            \Reseller\Activation\ActivationFaults::hit('identity.locked');
            return $fn($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                // R4: an identity write commits inside the callback; anything left open is never committed here
                $pdo->rollBack();
            }
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    /** Whether this session holds the identity lock. */
    public static function held(\PDO $pdo): bool
    {
        $st = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $st->execute([self::name($pdo)]);
        return (string) $st->fetchColumn() === '1';
    }

    /**
     * Refuses (DomainError IDENTITY_TAKEN / IDENTITY_CHECK_UNAVAILABLE) unless every name is available. Only under the
     * identity lock. $selfCustomerId excludes the acquiring customer's own row (rename); $ownKeys are the exact RADIUS
     * keys the acquirer already holds (its current names).
     *
     * @param list<string> $names
     * @param list<string> $ownKeys
     */
    public static function assertAvailable(\PDO $pdo, array $names, ?int $selfCustomerId = null, array $ownKeys = []): void
    {
        if (!self::held($pdo)) {
            throw new \LogicException('the identity availability check runs only under the identity lock');
        }
        $taken = self::conflicts($pdo, $names, $selfCustomerId, $ownKeys);
        if ($taken === null) {
            throw new DomainError('IDENTITY_CHECK_UNAVAILABLE');
        }
        if ($taken !== []) {
            ErrorReporter::log('identity.taken', 'reasons=' . implode(',', array_unique(array_merge(...array_values($taken)))));
            throw new DomainError('IDENTITY_TAKEN');
        }
    }

    /**
     * The names (canonical form => reasons customer | voucher | recharge | radius) that are taken; [] when none; null
     * when the check cannot run (any error, an empty canonical name, more candidate rows than the bound). Without the
     * identity lock this is a best-effort answer (voucher generation).
     *
     * @param list<string> $names
     * @param list<string> $ownKeys
     * @return array<string,list<string>>|null
     */
    public static function conflicts(\PDO $pdo, array $names, ?int $selfCustomerId = null, array $ownKeys = []): ?array
    {
        $keys = [];
        foreach ($names as $n) {
            $k = trim((string) $n);
            if ($k === '') {
                return null;
            }
            $keys[$k] = true;
        }
        $keys = array_keys($keys);
        if ($keys === []) {
            return [];
        }
        $hex = array_map(static fn(string $k): string => strtoupper(bin2hex($k)), $keys);
        $in = implode(', ', array_fill(0, count($keys), self::rad('UNHEX(?)')));
        try {
            $candidates = [];   // canonical HEX => reasons
            $sources = [
                'customer' => ['SELECT HEX(' . self::rad("REGEXP_REPLACE(username, ?, '')") . ') AS a, HEX(' . self::rad("REGEXP_REPLACE(pppoe_username, ?, '')") . ') AS b FROM tbl_customers'
                    . ' WHERE (' . self::rad('username') . " IN ({$in}) OR " . self::rad('pppoe_username') . " IN ({$in}) OR username REGEXP ? OR pppoe_username REGEXP ?)"
                    . ($selfCustomerId !== null ? ' AND id <> ?' : ''),
                    [self::TRIM_RE, self::TRIM_RE, ...$hex, ...$hex, self::EDGE_RE, self::EDGE_RE, ...($selfCustomerId !== null ? [$selfCustomerId] : [])]],
                'voucher' => ['SELECT HEX(' . self::rad("REGEXP_REPLACE(code, ?, '')") . ") AS a, '' AS b FROM tbl_voucher"
                    . " WHERE (status = 'CLAIMED' OR (status = '1' AND user = '0')) AND (" . self::rad('code') . " IN ({$in}) OR code REGEXP ?)",
                    [self::TRIM_RE, ...$hex, self::EDGE_RE]],
                'recharge' => ['SELECT HEX(' . self::rad("REGEXP_REPLACE(username, ?, '')") . ") AS a, '' AS b FROM tbl_user_recharges"
                    . ' WHERE customer_id = 0 AND (' . self::rad('username') . " IN ({$in}) OR username REGEXP ?)",
                    [self::TRIM_RE, ...$hex, self::EDGE_RE]],
            ];
            foreach ($sources as $reason => [$sql, $params]) {
                $rows = self::bounded($pdo, $sql . ' LIMIT ' . (self::CANDIDATES + 1), $params);
                if (count($rows) > self::CANDIDATES) {
                    return null;
                }
                foreach ($rows as $r) {
                    foreach (['a', 'b'] as $col) {
                        if ((string) $r[$col] !== '') {
                            $candidates[strtoupper((string) $r[$col])][$reason] = true;
                        }
                    }
                }
            }
            $byHex = array_combine($hex, $keys);
            $taken = [];
            if ($candidates !== []) {
                $kv = implode(' UNION ALL ', array_fill(0, count($keys), 'SELECT ' . self::rad('UNHEX(?)') . ' AS v'));
                $cv = implode(' UNION ALL ', array_fill(0, count($candidates), 'SELECT ' . self::rad('UNHEX(?)') . ' AS n'));
                foreach (self::bounded($pdo, "SELECT HEX(k.v) AS k, HEX(c.n) AS c FROM ({$kv}) k JOIN ({$cv}) c ON k.v = c.n", [...$hex, ...array_keys($candidates)]) as $r) {
                    $key = $byHex[strtoupper((string) $r['k'])] ?? throw new \LogicException('identity hex');
                    foreach (array_keys($candidates[strtoupper((string) $r['c'])] ?? []) as $reason) {
                        $taken[$key][$reason] = true;
                    }
                }
            }
            $radius = self::radiusPdo();
            if ($radius !== null) {
                $own = array_map(static fn(string $k): string => strtoupper(bin2hex($k)), $ownKeys);
                $kv = implode(' UNION ALL ', array_fill(0, count($keys), 'SELECT ' . self::rad('UNHEX(?)') . ' AS v'));
                foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
                    $rows = self::bounded($radius, "SELECT DISTINCT HEX(k.v) AS k, HEX(t.username) AS h FROM ({$kv}) k JOIN {$t} t ON t.username = k.v LIMIT " . (self::CANDIDATES + 1), $hex);
                    if (count($rows) > self::CANDIDATES) {
                        return null;
                    }
                    foreach ($rows as $r) {
                        if (!in_array(strtoupper((string) $r['h']), $own, true)) {
                            $taken[$byHex[strtoupper((string) $r['k'])] ?? throw new \LogicException('identity hex')]['radius'] = true;
                        }
                    }
                }
            } elseif (self::radiusConfigured()) {
                return null;
            }
            return array_map(static fn(array $r): array => array_keys($r), $taken);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'identity-check');
            return null;
        }
    }

    /**
     * WP9 U0 final hardening (D-U0-R1): the number of distinct LOGICAL OWNERS of one canonical RADIUS key - customers that
     * hold it as username or PPPoE username (canonical, any ownership) plus live voucher identities (CLAIMED or
     * voucher-only granted vouchers; a voucher-only recharge row without such a voucher counts as one). null when the
     * count cannot be established (any error, an empty key, more candidate rows than the bound).
     */
    public static function owners(\PDO $pdo, string $key): ?int
    {
        $k = trim($key);
        if ($k === '') {
            return null;
        }
        $hex = [strtoupper(bin2hex($k))];
        $key = self::rad('UNHEX(?)');
        try {
            $customers = self::bounded($pdo, 'SELECT DISTINCT id FROM tbl_customers WHERE (' . self::rad('username') . " = {$key} OR " . self::rad('pppoe_username') . " = {$key}"
                . ' OR username REGEXP ? OR pppoe_username REGEXP ?) AND (' . self::rad("REGEXP_REPLACE(username, ?, '')") . " = {$key}"
                . " OR (pppoe_username <> '' AND " . self::rad("REGEXP_REPLACE(pppoe_username, ?, '')") . " = {$key})) LIMIT " . (self::CANDIDATES + 1),
                [...$hex, ...$hex, self::EDGE_RE, self::EDGE_RE, self::TRIM_RE, ...$hex, self::TRIM_RE, ...$hex]);
            $vouchers = self::bounded($pdo, "SELECT DISTINCT id FROM tbl_voucher WHERE (status = 'CLAIMED' OR (status = '1' AND user = '0')) AND ("
                . self::rad('code') . " = {$key} OR code REGEXP ?) AND " . self::rad("REGEXP_REPLACE(code, ?, '')") . " = {$key} LIMIT " . (self::CANDIDATES + 1),
                [...$hex, self::EDGE_RE, self::TRIM_RE, ...$hex]);
            if (count($customers) > self::CANDIDATES || count($vouchers) > self::CANDIDATES) {
                return null;
            }
            $n = count($customers) + count($vouchers);
            if ($vouchers === []) {
                $n += count(self::bounded($pdo, 'SELECT 1 AS x FROM tbl_user_recharges WHERE customer_id = 0 AND (' . self::rad('username') . " = {$key} OR username REGEXP ?) AND "
                    . self::rad("REGEXP_REPLACE(username, ?, '')") . " = {$key} LIMIT 1", [...$hex, self::EDGE_RE, self::TRIM_RE, ...$hex]));
            }
            return $n;
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'identity-owners');
            return null;
        }
    }

    /**
     * WP9 U0 R-FC-4E: the voucher rows (any status) and the voucher-only recharge rows (customer_id 0) of ONE canonical
     * identity - PHP trim() compared under utf8mb4_general_ci, the RADIUS key rule every serving path resolves names with -
     * each marked "exact" when its stored name is the requested key itself (the same bytes after PHP trim). null when the
     * answer cannot be established (empty key, any error, more rows than the bound): callers fail closed. Read only,
     * bounded and time-limited like every identity lookup; no lock, no transaction.
     *
     * @return array{vouchers:list<array{status:string,user:string,exact:bool}>,recharges:list<array{exact:bool}>}|null
     */
    public static function voucherIdentity(\PDO $pdo, string $key): ?array
    {
        $k = trim($key);
        if ($k === '') {
            return null;
        }
        $hex = strtoupper(bin2hex($k));
        $p = self::rad('UNHEX(?)');
        try {
            $vouchers = self::bounded($pdo, "SELECT status, user, HEX(CONVERT(REGEXP_REPLACE(code, ?, '') USING utf8mb4)) AS h FROM tbl_voucher WHERE ("
                . self::rad('code') . " = {$p} OR code REGEXP ?) AND " . self::rad("REGEXP_REPLACE(code, ?, '')") . " = {$p} LIMIT " . (self::CANDIDATES + 1),
                [self::TRIM_RE, $hex, self::EDGE_RE, self::TRIM_RE, $hex]);
            $recharges = self::bounded($pdo, "SELECT HEX(CONVERT(REGEXP_REPLACE(username, ?, '') USING utf8mb4)) AS h FROM tbl_user_recharges WHERE customer_id = 0 AND ("
                . self::rad('username') . " = {$p} OR username REGEXP ?) AND " . self::rad("REGEXP_REPLACE(username, ?, '')") . " = {$p} LIMIT " . (self::CANDIDATES + 1),
                [self::TRIM_RE, $hex, self::EDGE_RE, self::TRIM_RE, $hex]);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'identity-voucher');
            return null;
        }
        if (count($vouchers) > self::CANDIDATES || count($recharges) > self::CANDIDATES) {
            return null;
        }
        return [
            'vouchers' => array_map(static fn(array $r): array => ['status' => (string) $r['status'], 'user' => (string) $r['user'], 'exact' => strtoupper((string) $r['h']) === $hex], $vouchers),
            'recharges' => array_map(static fn(array $r): array => ['exact' => strtoupper((string) $r['h']) === $hex], $recharges),
        ];
    }

    /**
     * WP9 U0 final hardening (D-U0-R1): refuses (DomainError IDENTITY_AMBIGUOUS / IDENTITY_CHECK_UNAVAILABLE) when one of
     * $keys has more than one logical owner. The count runs under the shared identity lock (at most RADIUS_TIMEOUT
     * seconds of waiting: it guards writes on RADIUS and web paths alike); a caller that already holds the lock on this
     * connection (order L-3 -> BEGIN) is counted in place. Called before every write of a subscriber's RADIUS login policy.
     *
     * WP9 U0 final closure (owner §4): inside a caller's open transaction WITHOUT the lock nothing serializes the count
     * and the following write against an acquirer (which cannot see the caller's uncommitted rows), and under REPEATABLE
     * READ the count would read the transaction's old snapshot: refused (IDENTITY_CHECK_UNAVAILABLE), nothing counted or
     * written. No application RADIUS writer runs inside a transaction (the device steps run after COMMIT).
     */
    public static function assertSingleOwner(string ...$keys): void
    {
        $keys = array_values(array_unique(array_filter(array_map('trim', $keys), static fn(string $k): bool => $k !== '')));
        if ($keys === []) {
            return;
        }
        $check = static function (\PDO $pdo) use ($keys): void {
            foreach ($keys as $k) {
                $n = self::owners($pdo, $k);
                if ($n === null) {
                    throw new DomainError('IDENTITY_CHECK_UNAVAILABLE');
                }
                if ($n > 1) {
                    ErrorReporter::log('identity.ambiguous', 'owners=' . $n);
                    throw new DomainError('IDENTITY_AMBIGUOUS');
                }
            }
        };
        $pdo = self::pdo();
        try {
            $held = self::held($pdo);
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'identity-owners');
            throw new DomainError('IDENTITY_CHECK_UNAVAILABLE');
        }
        if ($held) {
            $check($pdo);
            return;
        }
        if ($pdo->inTransaction()) {
            ErrorReporter::log('identity.owner_check_refused', 'reason=open_transaction keys=' . count($keys));
            throw new DomainError('IDENTITY_CHECK_UNAVAILABLE');
        }
        self::hold($check, self::RADIUS_TIMEOUT);
    }

    /**
     * The exact RADIUS keys an identity already holds (its current names, as stored and as RadiusPolicyBuilder::key()
     * trims them): the acquirer's own policy rows never block its own rename.
     *
     * @return list<string>
     */
    public static function keysOf(string ...$names): array
    {
        $out = [];
        foreach ($names as $n) {
            foreach ([$n, trim($n)] as $k) {
                if ($k !== '') {
                    $out[$k] = true;
                }
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * The names an identity change ACQUIRES: each new non-empty name that differs (exact bytes) from the old value of
     * the same field. A cleared PPPoE username or an unchanged name acquires nothing.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return list<string>
     */
    public static function acquired(array $old, array $new): array
    {
        $out = [];
        foreach (['username', 'pppoe_username'] as $f) {
            $n = (string) ($new[$f] ?? '');
            if (trim($n) !== '' && $n !== (string) ($old[$f] ?? '')) {
                $out[] = $n;
            }
        }
        return $out;
    }

    private static function rad(string $expr): string
    {
        return "CONVERT({$expr} USING utf8mb4) COLLATE utf8mb4_general_ci";
    }

    /**
     * One read-only statement with a per-statement time bound (D-U0-2); a timeout raises, so the caller fails closed.
     *
     * @return list<array<string,mixed>>
     */
    private static function bounded(\PDO $pdo, string $sql, array $params): array
    {
        $id = spl_object_id($pdo);
        if (!isset(self::$mariadb[$id])) {
            self::$mariadb[$id] = stripos((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
        }
        $sql = self::$mariadb[$id]
            ? 'SET STATEMENT max_statement_time=' . (self::STATEMENT_MS / 1000) . ' FOR ' . $sql
            : preg_replace('/^SELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(' . self::STATEMENT_MS . ') */ ', $sql, 1);
        $st = $pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Whether the installation uses the RADIUS database (the init.php condition of the 'radius' connection). */
    private static function radiusConfigured(): bool
    {
        global $config, $radius_user;
        return !empty($radius_user) && !empty($config['radius_enable']);
    }

    /** The RADIUS connection (opened on first use), null when RADIUS is not configured or cannot be reached. */
    private static function radiusPdo(): ?\PDO
    {
        if (!self::radiusConfigured()) {
            return null;
        }
        try {
            $pdo = \ORM::get_db('radius');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (\Throwable $e) {
            ErrorReporter::report($e, 'identity-radius');
            return null;
        }
    }

    private static function pdo(): \PDO
    {
        $pdo = \ORM::get_db();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
