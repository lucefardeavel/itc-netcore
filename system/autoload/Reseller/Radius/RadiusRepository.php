<?php
namespace Reseller\Radius;

// Runtime class: loaded only through init.php (NUXR_RUNTIME); never executable on its own.
if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }

/**
 * The only writer of the per-subscriber and per-plan FreeRADIUS rows (radcheck, radreply, radusergroup,
 * radgroupreply) and of NAS rows, on the 'radius' connection only (06_RADIUS_CONTRACT "RadiusRepository":
 * CRUD for the relevant RADIUS tables, no business pricing or wallet decisions).
 *
 *  - every statement is prepared; nothing is interpolated;
 *  - every multi-row change is one transaction on the RADIUS connection; re-applying the same policy changes
 *    nothing (idempotent), and only attributes this application manages are touched;
 *  - radacct (FreeRADIUS accounting history) is NEVER written, updated or deleted here (D018, C17).
 */
final class RadiusRepository
{
    private const GROUP_MANAGED = ['Ascend-Data-Rate', 'Ascend-Xmit-Rate', 'Mikrotik-Rate-Limit'];

    private ?bool $groupPlanColumn = null;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** The configured 'radius' ORM connection (init.php). */
    public static function connection(): self
    {
        return new self(\ORM::get_db('radius'));
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** Applies a policy exactly (managed attributes only). Returns true when anything changed. */
    public function applyUser(RadiusPolicy $policy): bool
    {
        return $this->tx(function () use ($policy): bool {
            $changed = $this->sync('radcheck', $policy->username, $policy->check, RadiusPolicy::MANAGED_CHECK);
            $changed = $this->sync('radreply', $policy->username, $policy->reply, RadiusPolicy::MANAGED_REPLY) || $changed;
            if ($policy->group !== null) {
                $rows = $this->rows('SELECT id, groupname, priority FROM radusergroup WHERE username = ? ORDER BY id FOR UPDATE', [$policy->username]);
                if (count($rows) !== 1 || $rows[0]['groupname'] !== $policy->group || (int) $rows[0]['priority'] !== 1) {
                    $this->exec('DELETE FROM radusergroup WHERE username = ?', [$policy->username]);
                    $this->exec('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)', [$policy->username, $policy->group]);
                    $changed = true;
                }
            }
            return $changed;
        });
    }

    /** @return array{check:array<string,string>,reply:array<string,string>,groups:list<string>} */
    public function readUser(string $username): array
    {
        $map = function (string $table) use ($username): array {
            $out = [];
            foreach ($this->rows("SELECT attribute, value FROM {$table} WHERE username = ? ORDER BY id", [$username]) as $r) {
                $out[(string) $r['attribute']] = (string) $r['value'];
            }
            ksort($out);
            return $out;
        };
        $groups = array_map(static fn(array $r): string => (string) $r['groupname'],
            $this->rows('SELECT groupname FROM radusergroup WHERE username = ? ORDER BY priority, id', [$username]));
        return ['check' => $map('radcheck'), 'reply' => $map('radreply'), 'groups' => $groups];
    }

    /**
     * Exact rows of the attributes this application manages for one subscriber (WP5A reconciliation): every
     * managed radcheck / radreply row as [attribute, op, value] (duplicates kept, canonical order) and every
     * radusergroup row as [groupname, priority] in priority order. Unmanaged rows are not part of it.
     *
     * @return array{check:list<array{0:string,1:string,2:string}>,reply:list<array{0:string,1:string,2:string}>,groups:list<array{0:string,1:int}>}
     */
    public function snapshotUser(string $username): array
    {
        $managed = function (string $table, array $attributes) use ($username): array {
            $out = [];
            foreach ($this->rows("SELECT attribute, op, value FROM {$table} WHERE username = ?", [$username]) as $r) {
                if (in_array((string) $r['attribute'], $attributes, true)) {
                    $out[] = [(string) $r['attribute'], (string) $r['op'], (string) $r['value']];
                }
            }
            sort($out);
            return $out;
        };
        $groups = self::orderGroups(array_map(static fn(array $r): array => [(string) $r['groupname'], (int) $r['priority']],
            $this->rows('SELECT groupname, priority FROM radusergroup WHERE username = ?', [$username])));
        return ['check' => $managed('radcheck', RadiusPolicy::MANAGED_CHECK), 'reply' => $managed('radreply', RadiusPolicy::MANAGED_REPLY), 'groups' => $groups];
    }

    /**
     * Exact managed radgroupreply rows of a plan group (plan_<id>) as [attribute, op, value], canonical order.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public function snapshotGroup(int $planId): array
    {
        $out = [];
        foreach ($this->rows('SELECT attribute, op, value FROM radgroupreply WHERE groupname = ?', ['plan_' . $planId]) as $r) {
            if (in_array((string) $r['attribute'], self::GROUP_MANAGED, true)) {
                $out[] = [(string) $r['attribute'], (string) $r['op'], (string) $r['value']];
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Restores a subscriber's managed rows exactly (WP5A rollback to a captured prior state): every managed
     * attribute ends with exactly the given rows (none when absent), and radusergroup with exactly the given
     * [groupname, priority] rows. Unmanaged rows are never touched; radacct is never touched.
     *
     * @param list<array{0:string,1:string,2:string}> $check
     * @param list<array{0:string,1:string,2:string}> $reply
     * @param list<array{0:string,1:int}>             $groups
     */
    public function restoreUser(string $username, array $check, array $reply, array $groups): bool
    {
        return $this->tx(function () use ($username, $check, $reply, $groups): bool {
            $changed = $this->syncExact('radcheck', $username, $check, RadiusPolicy::MANAGED_CHECK);
            $changed = $this->syncExact('radreply', $username, $reply, RadiusPolicy::MANAGED_REPLY) || $changed;
            $have = self::orderGroups(array_map(static fn(array $r): array => [(string) $r['groupname'], (int) $r['priority']],
                $this->rows('SELECT groupname, priority FROM radusergroup WHERE username = ? FOR UPDATE', [$username])));
            $want = self::orderGroups($groups);
            if ($have !== $want) {
                $this->exec('DELETE FROM radusergroup WHERE username = ?', [$username]);
                foreach ($want as [$group, $priority]) {
                    $this->exec('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, ?)', [$username, $group, $priority]);
                }
                $changed = true;
            }
            return $changed;
        });
    }

    /**
     * Suspends a subscriber without deleting anything: the password becomes an unguessable random value
     * (the legacy behaviour, without the database password as salt). Returns the number of rows changed.
     */
    public function suspendUser(string $username): int
    {
        return $this->tx(fn(): int => $this->exec(
            "UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'",
            [bin2hex(random_bytes(24)), $username]
        ));
    }

    /** Removes the subscriber's policy rows (radcheck, radreply, radusergroup). radacct is kept. */
    public function removeUser(string $username): int
    {
        return $this->tx(function () use ($username): int {
            $n = 0;
            foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
                $n += $this->exec("DELETE FROM {$t} WHERE username = ?", [$username]);
            }
            return $n;
        });
    }

    /**
     * Renames a subscriber in all three policy tables (the legacy device skipped radreply). Rows already held
     * by the target name are replaced, so the renamed subscriber never ends up with two policies.
     */
    public function renameUser(string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }
        return $this->tx(function () use ($from, $to): int {
            $n = 0;
            foreach (['radcheck', 'radreply', 'radusergroup'] as $t) {
                $this->exec("DELETE FROM {$t} WHERE username = ?", [$to]);
                $n += $this->exec("UPDATE {$t} SET username = ? WHERE username = ?", [$to, $from]);
            }
            return $n;
        });
    }

    /**
     * Applies a plan group's reply rows (radgroupreply, group plan_<id>), managed attributes only.
     *
     * @param list<array{0:string,1:string,2:string}> $rows
     */
    public function applyGroup(int $planId, array $rows): bool
    {
        $group = 'plan_' . $planId;
        return $this->tx(function () use ($group, $planId, $rows): bool {
            $existing = $this->rows('SELECT id, attribute, op, value FROM radgroupreply WHERE groupname = ? ORDER BY id FOR UPDATE', [$group]);
            $changed = false;
            $desired = [];
            foreach ($rows as [$a, $op, $v]) {
                $desired[$a] = [$op, $v];
            }
            foreach (self::GROUP_MANAGED as $attribute) {
                $have = array_values(array_filter($existing, static fn(array $r): bool => $r['attribute'] === $attribute));
                $want = $desired[$attribute] ?? null;
                if ($want !== null && count($have) === 1 && $have[0]['op'] === $want[0] && $have[0]['value'] === $want[1]) {
                    continue;
                }
                if ($have !== []) {
                    $this->exec('DELETE FROM radgroupreply WHERE groupname = ? AND attribute = ?', [$group, $attribute]);
                    $changed = true;
                }
                if ($want !== null) {
                    if ($this->groupHasPlanColumn()) {
                        $this->exec('INSERT INTO radgroupreply (groupname, attribute, op, value, plan_id) VALUES (?, ?, ?, ?, ?)', [$group, $attribute, $want[0], $want[1], $planId]);
                    } else {
                        $this->exec('INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, ?, ?, ?)', [$group, $attribute, $want[0], $want[1]]);
                    }
                    $changed = true;
                }
            }
            return $changed;
        });
    }

    /** Removes a plan group (legacy behaviour: members keep their row with an empty group). */
    public function removeGroup(int $planId): int
    {
        $group = 'plan_' . $planId;
        return $this->tx(function () use ($group, $planId): int {
            $n = $this->exec('DELETE FROM radgroupreply WHERE groupname = ?', [$group]);
            if ($this->groupHasPlanColumn()) {
                $n += $this->exec('DELETE FROM radgroupreply WHERE plan_id = ?', [$planId]);
            }
            return $n + $this->exec("UPDATE radusergroup SET groupname = '' WHERE groupname = ?", [$group]);
        });
    }

    /** @return list<array<string,mixed>> NAS rows whose nasname is exactly this address */
    public function nasByAddress(string $address): array
    {
        return $this->rows('SELECT * FROM nas WHERE nasname = ? ORDER BY id', [$address]);
    }

    /** @return array<string,mixed>|null */
    public function nas(int $id): ?array
    {
        return $this->rows('SELECT * FROM nas WHERE id = ?', [$id])[0] ?? null;
    }

    /** @param array<string,string|null> $values nasname, shortname, type, ports, secret, description, server, community, routers */
    public function nasAdd(array $values): int
    {
        $cols = self::nasColumns($values);
        $this->exec('INSERT INTO nas (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($cols));
        return (int) $this->pdo->lastInsertId();
    }

    /** A null secret keeps the stored secret (the UI never renders it back). */
    public function nasUpdate(int $id, array $values): bool
    {
        if ($this->nas($id) === null) {
            return false;
        }
        $cols = self::nasColumns($values);
        if (($cols['secret'] ?? null) === null) {
            unset($cols['secret']);
        }
        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($cols)));
        $this->exec("UPDATE nas SET {$set} WHERE id = ?", [...array_values($cols), $id]);
        return true;
    }

    /** @return array<string,string|null> */
    private static function nasColumns(array $values): array
    {
        $out = [];
        foreach (['nasname', 'shortname', 'type', 'ports', 'secret', 'description', 'server', 'community', 'routers'] as $c) {
            if (array_key_exists($c, $values)) {
                $out[$c] = $values[$c] === null ? null : (string) $values[$c];
            }
        }
        return $out;
    }

    /**
     * @param list<array{0:string,1:string,2:string}> $desired
     * @param list<string> $managed
     */
    private function sync(string $table, string $username, array $desired, array $managed): bool
    {
        $existing = $this->rows("SELECT id, attribute, op, value FROM {$table} WHERE username = ? ORDER BY id FOR UPDATE", [$username]);
        $want = [];
        foreach ($desired as [$a, $op, $v]) {
            $want[$a] = [$op, $v];
        }
        $changed = false;
        foreach ($managed as $attribute) {
            $have = array_values(array_filter($existing, static fn(array $r): bool => $r['attribute'] === $attribute));
            $w = $want[$attribute] ?? null;
            if ($w !== null && count($have) === 1 && $have[0]['op'] === $w[0] && $have[0]['value'] === $w[1]) {
                continue;
            }
            if ($have !== []) {
                $this->exec("DELETE FROM {$table} WHERE username = ? AND attribute = ?", [$username, $attribute]);
                $changed = true;
            }
            if ($w !== null) {
                $this->exec("INSERT INTO {$table} (username, attribute, op, value) VALUES (?, ?, ?, ?)", [$username, $attribute, $w[0], $w[1]]);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Like sync(), but list-exact per managed attribute (several rows of one attribute are kept as given).
     *
     * @param list<array{0:string,1:string,2:string}> $desired
     * @param list<string> $managed
     */
    private function syncExact(string $table, string $username, array $desired, array $managed): bool
    {
        $existing = $this->rows("SELECT attribute, op, value FROM {$table} WHERE username = ? ORDER BY id FOR UPDATE", [$username]);
        $changed = false;
        foreach ($managed as $attribute) {
            $have = [];
            foreach ($existing as $r) {
                if ($r['attribute'] === $attribute) {
                    $have[] = [(string) $r['attribute'], (string) $r['op'], (string) $r['value']];
                }
            }
            $want = array_values(array_filter($desired, static fn(array $d): bool => $d[0] === $attribute));
            $sortedHave = $have;
            $sortedWant = $want;
            sort($sortedHave);
            sort($sortedWant);
            if ($sortedHave === $sortedWant) {
                continue;
            }
            if ($have !== []) {
                $this->exec("DELETE FROM {$table} WHERE username = ? AND attribute = ?", [$username, $attribute]);
            }
            foreach ($want as [$a, $op, $v]) {
                $this->exec("INSERT INTO {$table} (username, attribute, op, value) VALUES (?, ?, ?, ?)", [$username, $a, $op, $v]);
            }
            $changed = true;
        }
        return $changed;
    }

    /**
     * Canonical radusergroup order (priority, then name; byte order, independent of the SQL collation).
     *
     * @param list<array{0:string,1:int}> $groups
     * @return list<array{0:string,1:int}>
     */
    public static function orderGroups(array $groups): array
    {
        usort($groups, static fn(array $x, array $y): int => [(int) $x[1], (string) $x[0]] <=> [(int) $y[1], (string) $y[0]]);
        return array_values($groups);
    }

    private function groupHasPlanColumn(): bool
    {
        if ($this->groupPlanColumn === null) {
            $this->groupPlanColumn = $this->rows("SHOW COLUMNS FROM radgroupreply LIKE 'plan_id'") !== [];
        }
        return $this->groupPlanColumn;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            RadiusFaults::hit('radius.after_write');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        try {
            $this->pdo->commit();
            RadiusFaults::hit('radius.commit_lost');
        } catch (\Throwable $e) {
            throw new RadiusCommitUncertain('RADIUS commit outcome unknown', 0, $e);
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exec(string $sql, array $params): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
