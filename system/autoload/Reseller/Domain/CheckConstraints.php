<?php
namespace Reseller\Domain;

// Runtime class: loaded only through init.php (NUXR_RUNTIME) or by the CLI migration runner, which
// defines NUXR_RUNTIME before requiring it; never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * WP9-D / WP1-CHECK (N-08, ledger-binding): CHECK constraints are verified by their normalized
 * EXPRESSION, not only by their name, and their enforcement is verified per engine.
 *
 * The expected expression of every project CHECK constraint is taken from the migration DDL itself
 * (`CONSTRAINT `name` CHECK (...)`); the actual expression comes from
 * information_schema.CHECK_CONSTRAINTS. The two engines print the same constraint differently
 * (MariaDB 10.11: minimal parentheses; MySQL 8.0: every operand parenthesized, charset introducers,
 * escaped quotes), so both texts are parsed into the same small expression tree and compared in one
 * canonical, fully parenthesized rendering. Anything the parser does not understand is a mismatch
 * (fail closed), never a pass.
 *
 * Enforcement: MySQL reports TABLE_CONSTRAINTS.ENFORCED = 'NO' for a NOT ENFORCED CHECK; MariaDB can
 * switch all CHECK enforcement off with the check_constraint_checks variable. Both are problems.
 */
final class CheckConstraints
{
    /**
     * Expected CHECK constraints declared in SQL / migration source text.
     * @return array<string,array{table:string,expr:string}> name => table + raw expression
     */
    public static function expectedFromSql(string $sql): array
    {
        $out = [];
        $offset = 0;
        while (preg_match('/CONSTRAINT\s+`([A-Za-z0-9_]+)`\s+CHECK\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $name = $m[1][0];
            $start = $m[0][1] + strlen($m[0][0]) - 1; // the opening parenthesis
            $expr = self::balanced($sql, $start);
            if ($expr === null) {
                $out[$name] = ['table' => '', 'expr' => "\0unbalanced"];
                break;
            }
            $table = '';
            if (preg_match_all('/(?:CREATE|ALTER)\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([A-Za-z0-9_]+)`/i', substr($sql, 0, $m[0][1]), $t) > 0) {
                $table = (string) end($t[1]);
            }
            $out[$name] = ['table' => $table, 'expr' => $expr];
            $offset = $start + strlen($expr) + 2;
        }
        return $out;
    }

    /**
     * Problems of the actual CHECK constraints against the expected ones (empty list = consistent).
     * @param array<string,array{table:string,expr:string}> $expected
     * @return list<string>
     */
    public static function problems(\PDO $pdo, string $engineFamily, array $expected): array
    {
        if ($expected === []) {
            return [];
        }
        $p = [];
        if ($engineFamily === 'mariadb') {
            $on = $pdo->query('SELECT @@SESSION.check_constraint_checks')->fetchColumn();
            if ((string) $on !== '1') {
                $p[] = 'CHECK constraints are not enforced on this connection (check_constraint_checks = ' . (string) $on . ')';
            }
        }
        $enforcedCol = $engineFamily === 'mysql' ? ', tc.ENFORCED AS enforced' : ", 'YES' AS enforced";
        $rows = $pdo->query(
            "SELECT tc.TABLE_NAME AS t, cc.CONSTRAINT_NAME AS n, cc.CHECK_CLAUSE AS c{$enforcedCol}
               FROM information_schema.CHECK_CONSTRAINTS cc
               JOIN information_schema.TABLE_CONSTRAINTS tc
                 ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                AND tc.CONSTRAINT_TYPE = 'CHECK'"
                . ($engineFamily === 'mariadb' ? ' AND tc.TABLE_NAME = cc.TABLE_NAME' : '')
                . ' WHERE cc.CONSTRAINT_SCHEMA = DATABASE()'
        )->fetchAll(\PDO::FETCH_ASSOC);
        // a CHECK of an absent table is not reported here: the missing table itself is (runner contract /
        // readiness), and a table that exists is always fully verified
        $tables = array_map('strtolower', $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(\PDO::FETCH_COLUMN));
        $actual = [];
        foreach ($rows as $r) {
            $actual[(string) $r['n']][] = $r;
        }
        foreach ($expected as $name => $e) {
            if ($e['table'] !== '' && !in_array(strtolower($e['table']), $tables, true)) {
                continue;
            }
            $found = $actual[$name] ?? [];
            if ($e['table'] !== '') {
                $found = array_values(array_filter($found, static fn(array $r): bool => strcasecmp((string) $r['t'], $e['table']) === 0));
            }
            if ($found === []) {
                $p[] = "CHECK {$name}" . ($e['table'] !== '' ? " on {$e['table']}" : '') . ' is missing';
                continue;
            }
            if (count($found) > 1) {
                $p[] = "CHECK {$name} is defined more than once";
                continue;
            }
            $want = self::canonical($e['expr']);
            $have = self::canonical((string) $found[0]['c']);
            if ($want === null || $have === null || $want !== $have) {
                $p[] = "CHECK {$name} expression differs from its migration (expected [" . ($want ?? 'unparseable') . '] actual [' . ($have ?? 'unparseable') . '])';
            }
            if (strtoupper((string) $found[0]['enforced']) !== 'YES') {
                $p[] = "CHECK {$name} is NOT ENFORCED";
            }
        }
        return $p;
    }

    /** Canonical rendering of a CHECK expression, or null if it cannot be parsed. */
    public static function canonical(string $expr): ?string
    {
        try {
            $tokens = self::tokens(str_replace("\\'", "'", $expr));
            $pos = 0;
            $tree = self::parseOr($tokens, $pos);
            if ($pos !== count($tokens)) {
                return null;
            }
            return self::render($tree);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    private static function balanced(string $s, int $open): ?string
    {
        $depth = 0;
        $len = strlen($s);
        $quote = false;
        for ($i = $open; $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote) {
                if ($ch === "'") {
                    $quote = false;
                }
                continue;
            }
            if ($ch === "'") {
                $quote = true;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $open + 1, $i - $open - 1);
                }
            }
        }
        return null;
    }

    /** @return list<array{0:string,1:string}> [kind, value] */
    private static function tokens(string $s): array
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $ch = $s[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if ($ch === '`') {
                $j = strpos($s, '`', $i + 1);
                if ($j === false) {
                    throw new \UnexpectedValueException('unterminated identifier');
                }
                $out[] = ['id', strtolower(substr($s, $i + 1, $j - $i - 1))];
                $i = $j + 1;
                continue;
            }
            if (preg_match('/\G(?:_[A-Za-z0-9]+)?\'((?:[^\']|\'\')*)\'/A', $s, $m, 0, $i) === 1) {
                $out[] = ['str', str_replace("''", "'", $m[1])];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G\d+(?:\.\d+)?/A', $s, $m, 0, $i) === 1) {
                $out[] = ['num', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G(?:>=|<=|<>|!=|&&|\|\||[=<>+\-*\/(),])/A', $s, $m, 0, $i) === 1) {
                $op = $m[0] === '!=' ? '<>' : ($m[0] === '&&' ? 'and' : ($m[0] === '||' ? 'or' : $m[0]));
                $out[] = ['op', $op];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/A', $s, $m, 0, $i) === 1) {
                $w = strtolower($m[0]);
                $out[] = [in_array($w, ['and', 'or', 'not', 'is', 'null', 'in', 'like', 'regexp', 'between', 'true', 'false'], true) ? 'kw' : 'word', $w];
                $i += strlen($m[0]);
                continue;
            }
            throw new \UnexpectedValueException('unexpected character');
        }
        return $out;
    }

    private static function peek(array $t, int $pos, string $kind, ?string $value = null): bool
    {
        return isset($t[$pos]) && $t[$pos][0] === $kind && ($value === null || $t[$pos][1] === $value);
    }

    private static function expect(array $t, int &$pos, string $kind, string $value): void
    {
        if (!self::peek($t, $pos, $kind, $value)) {
            throw new \UnexpectedValueException("expected {$value}");
        }
        $pos++;
    }

    private static function parseOr(array $t, int &$pos): array
    {
        $items = [self::parseAnd($t, $pos)];
        while (self::peek($t, $pos, 'kw', 'or') || self::peek($t, $pos, 'op', 'or')) {
            $pos++;
            $items[] = self::parseAnd($t, $pos);
        }
        return count($items) === 1 ? $items[0] : self::flat('or', $items);
    }

    private static function parseAnd(array $t, int &$pos): array
    {
        $items = [self::parseNot($t, $pos)];
        while (self::peek($t, $pos, 'kw', 'and') || self::peek($t, $pos, 'op', 'and')) {
            $pos++;
            $items[] = self::parseNot($t, $pos);
        }
        return count($items) === 1 ? $items[0] : self::flat('and', $items);
    }

    private static function flat(string $op, array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if ($i[0] === $op) {
                array_push($out, ...$i[1]);
            } else {
                $out[] = $i;
            }
        }
        return [$op, $out];
    }

    private static function parseNot(array $t, int &$pos): array
    {
        if (self::peek($t, $pos, 'kw', 'not')) {
            $pos++;
            return ['not', [self::parseNot($t, $pos)]];
        }
        return self::parseCompare($t, $pos);
    }

    private static function parseCompare(array $t, int &$pos): array
    {
        $left = self::parseAdd($t, $pos);
        if (isset($t[$pos]) && $t[$pos][0] === 'op' && in_array($t[$pos][1], ['=', '<>', '<', '>', '<=', '>='], true)) {
            $op = $t[$pos][1];
            $pos++;
            return ['cmp', [$op, $left, self::parseAdd($t, $pos)]];
        }
        if (self::peek($t, $pos, 'kw', 'is')) {
            $pos++;
            $neg = false;
            if (self::peek($t, $pos, 'kw', 'not')) {
                $neg = true;
                $pos++;
            }
            self::expect($t, $pos, 'kw', 'null');
            return ['isnull', [$neg, $left]];
        }
        $neg = false;
        if (self::peek($t, $pos, 'kw', 'not') && (self::peek($t, $pos + 1, 'kw', 'in') || self::peek($t, $pos + 1, 'kw', 'like') || self::peek($t, $pos + 1, 'kw', 'regexp'))) {
            $neg = true;
            $pos++;
        }
        if (self::peek($t, $pos, 'kw', 'in')) {
            $pos++;
            self::expect($t, $pos, 'op', '(');
            $list = [self::parseOr($t, $pos)];
            while (self::peek($t, $pos, 'op', ',')) {
                $pos++;
                $list[] = self::parseOr($t, $pos);
            }
            self::expect($t, $pos, 'op', ')');
            return ['in', [$neg, $left, $list]];
        }
        if (self::peek($t, $pos, 'kw', 'like') || self::peek($t, $pos, 'kw', 'regexp')) {
            $op = $t[$pos][1];
            $pos++;
            return ['match', [$neg, $op, $left, self::parseAdd($t, $pos)]];
        }
        if ($neg) {
            throw new \UnexpectedValueException('dangling not');
        }
        return $left;
    }

    private static function parseAdd(array $t, int &$pos): array
    {
        $left = self::parseMul($t, $pos);
        while (isset($t[$pos]) && $t[$pos][0] === 'op' && in_array($t[$pos][1], ['+', '-'], true)) {
            $op = $t[$pos][1];
            $pos++;
            $left = ['arith', [$op, $left, self::parseMul($t, $pos)]];
        }
        return $left;
    }

    private static function parseMul(array $t, int &$pos): array
    {
        $left = self::parseUnary($t, $pos);
        while (isset($t[$pos]) && $t[$pos][0] === 'op' && in_array($t[$pos][1], ['*', '/'], true)) {
            $op = $t[$pos][1];
            $pos++;
            $left = ['arith', [$op, $left, self::parseUnary($t, $pos)]];
        }
        return $left;
    }

    private static function parseUnary(array $t, int &$pos): array
    {
        if (self::peek($t, $pos, 'op', '-')) {
            $pos++;
            return ['neg', [self::parseUnary($t, $pos)]];
        }
        return self::parsePrimary($t, $pos);
    }

    private static function parsePrimary(array $t, int &$pos): array
    {
        if (!isset($t[$pos])) {
            throw new \UnexpectedValueException('unexpected end');
        }
        [$kind, $v] = $t[$pos];
        if ($kind === 'op' && $v === '(') {
            $pos++;
            $e = self::parseOr($t, $pos);
            self::expect($t, $pos, 'op', ')');
            return $e;
        }
        if ($kind === 'id' || $kind === 'num' || $kind === 'str') {
            $pos++;
            return [$kind, $v];
        }
        if ($kind === 'kw' && in_array($v, ['null', 'true', 'false'], true)) {
            $pos++;
            return ['lit', $v];
        }
        if ($kind === 'word') {
            $pos++;
            if (self::peek($t, $pos, 'op', '(')) {
                $pos++;
                $args = [];
                if (!self::peek($t, $pos, 'op', ')')) {
                    $args[] = self::parseOr($t, $pos);
                    while (self::peek($t, $pos, 'op', ',')) {
                        $pos++;
                        $args[] = self::parseOr($t, $pos);
                    }
                }
                self::expect($t, $pos, 'op', ')');
                return ['fn', [$v, $args]];
            }
            return ['id', $v]; // a bare (unquoted) column name
        }
        throw new \UnexpectedValueException('unexpected token');
    }

    private static function render(array $n): string
    {
        [$kind, $v] = $n;
        return match ($kind) {
            'or', 'and' => '(' . implode(" {$kind} ", array_map([self::class, 'render'], $v)) . ')',
            'not' => '(not ' . self::render($v[0]) . ')',
            'cmp' => '(' . self::render($v[1]) . " {$v[0]} " . self::render($v[2]) . ')',
            'isnull' => '(' . self::render($v[1]) . ($v[0] ? ' is not null)' : ' is null)'),
            'in' => '(' . self::render($v[1]) . ($v[0] ? ' not in (' : ' in (') . implode(',', array_map([self::class, 'render'], $v[2])) . '))',
            'match' => '(' . self::render($v[2]) . ($v[0] ? ' not ' : ' ') . $v[1] . ' ' . self::render($v[3]) . ')',
            'arith' => '(' . self::render($v[1]) . " {$v[0]} " . self::render($v[2]) . ')',
            'neg' => '(-' . self::render($v[0]) . ')',
            'fn' => $v[0] . '(' . implode(',', array_map([self::class, 'render'], $v[1])) . ')',
            'id' => '`' . $v . '`',
            'str' => "'" . str_replace("'", "''", $v) . "'",
            'num', 'lit' => $v,
            default => throw new \UnexpectedValueException('unknown node'),
        };
    }
}
