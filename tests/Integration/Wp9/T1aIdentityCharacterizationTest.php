<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp9Evidence.php';

use Reseller\Domain\CustomerService;
use Reseller\Domain\DomainError;
use Reseller\Domain\LegacyReprovisionService as R;
use Reseller\Tests\Support\ActivationTestCase;

/**
 * WP9 T1-A file 1 - OBS-1 / L-A5-2 identity CHARACTERISATION before any fix (owner 2026-10-15). Disposable database,
 * synthetic data only; no FreeRADIUS, no device, no SQL fault. Records how the database compares identities (collation,
 * whitespace, accents, other collations), what the read-only historical survey finds, and how the service-level
 * creation / rename paths P1 (CustomerService::create) and P5 (LegacyReprovisionService IDENTITY_EDIT, no active plan,
 * so no device step and no RADIUS write) behave today. Controls are asserted; everything else is recorded.
 */
final class T1aIdentityCharacterizationTest extends ActivationTestCase
{
    use Wp9Evidence;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // as LegacyReprovisionTest (WP5B): the legacy classes the re-provision service uses
        $root = dirname(__DIR__, 3);
        $GLOBALS['DEVICE_PATH'] = $root . '/system/devices';
        $GLOBALS['_app_stage'] = 'Live';
        foreach (['Package', 'Text'] as $c) {
            require_once $root . "/system/autoload/{$c}.php";
        }
        require_once $root . '/system/devices/Radius.php';
    }

    /** @return array<string,array{0:string,1:string}> stored value, probe value */
    private static function pairs(): array
    {
        return [
            'exact' => ['wpq', 'wpq'],
            'case' => ['wpq', 'WPQ'],
            'trailing space stored' => ['wpq ', 'wpq'],
            'trailing space probe' => ['wpq', 'wpq '],
            'leading space stored' => [' wpq', 'wpq'],
            'accent' => ['wpé', 'wpe'],
            'trailing tab stored' => ["wpq\t", 'wpq'],
            'trailing newline stored' => ["wpq\n", 'wpq'],
            'trailing no-break space stored' => ["wpq\u{00A0}", 'wpq'],
            'distinct (negative control)' => ['wpq', 'wpr'],
        ];
    }

    /** equality of each pair in a temporary copy of $table.$col (same column definition) */
    private function matrix(string $table, string $col): array
    {
        $pdo = static::$db->pdo;
        $tmp = 'w9tmp_' . bin2hex(random_bytes(3));
        $def = $pdo->query("SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'")->fetch(\PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TEMPORARY TABLE {$tmp} (id INT AUTO_INCREMENT PRIMARY KEY, v {$def['COLUMN_TYPE']} CHARACTER SET {$def['CHARACTER_SET_NAME']} COLLATE {$def['COLLATION_NAME']} NOT NULL)");
        $out = [];
        foreach (self::pairs() as $name => [$stored, $probe]) {
            $pdo->exec("DELETE FROM {$tmp}");
            $pdo->prepare("INSERT INTO {$tmp} (v) VALUES (?)")->execute([$stored]);
            // two statements: MySQL cannot reference one TEMPORARY table twice in a query (error 1137, run 2)
            $q = $pdo->prepare("SELECT COUNT(*) FROM {$tmp} WHERE v = ?");
            $q->execute([$probe]);
            $r = ['eq' => $q->fetchColumn()];
            $q = $pdo->prepare("SELECT COUNT(*) FROM {$tmp} WHERE TRIM(v) = TRIM(?)");
            $q->execute([$probe]);
            $r['eq_sql_trim'] = $q->fetchColumn();
            $out[$name] = ['eq' => (int) $r['eq'] > 0, 'eq_sql_trim' => (int) $r['eq_sql_trim'] > 0,
                'php_trim_equal_ci' => mb_strtolower(trim($stored)) === mb_strtolower(trim($probe))];
        }
        $pdo->exec("DROP TEMPORARY TABLE {$tmp}");
        return ['collation' => $def['COLLATION_NAME'], 'pairs' => $out];
    }

    public function testDeployedIdentityColumnCollations(): void
    {
        $rows = static::$db->rows("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLLATION_NAME AS coll, COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'tbl_customers' AND COLUMN_NAME IN ('username','pppoe_username')) OR (TABLE_NAME = 'radcheck' AND COLUMN_NAME = 'username')) ORDER BY 1, 2");
        self::record('deployed collations', 'OBSERVED', ['columns' => $rows]);
        $this->assertSame(['utf8mb4_general_ci'], array_values(array_unique(array_column($rows, 'coll'))), 'control: the harness schema uses utf8mb4_general_ci for all three identity columns');
        $this->assertCount(3, $rows);
    }

    public function testEqualityMatrixOfTheIdentityColumns(): void
    {
        foreach ([['tbl_customers', 'username'], ['tbl_customers', 'pppoe_username'], ['radcheck', 'username']] as [$t, $c]) {
            $m = $this->matrix($t, $c);
            self::record("equality matrix {$t}.{$c}", 'OBSERVED', $m);
            $this->assertTrue($m['pairs']['exact']['eq'], "control {$t}.{$c}: an identical value is equal");
            $this->assertFalse($m['pairs']['distinct (negative control)']['eq'], "control {$t}.{$c}: a different value is not equal");
            self::record("equality controls {$t}.{$c}", 'CONTROL_PASS');
            // design check (AM-W9-1 / owner §3): a stored leading space is NOT equal to the trimmed name under the column
            // comparison, while the RADIUS key (PHP trim) treats them as one identity
            if (!$m['pairs']['leading space stored']['eq'] && $m['pairs']['leading space stored']['php_trim_equal_ci']) {
                self::record("leading-space gap {$t}.{$c}", 'DEFECT_REPRODUCED', ['detail' => 'stored " wpq" != "wpq" under the column comparison, but trim() makes them the same RADIUS key: a check by column equality misses it']);
            }
        }
    }

    public function testEqualityUnderOtherCollations(): void
    {
        $pdo = static::$db->pdo;
        $out = [];
        foreach ([['utf8mb4', 'utf8mb4_general_ci'], ['utf8mb4', 'utf8mb4_unicode_ci'], ['utf8mb4', 'utf8mb4_unicode_520_ci'], ['utf8mb4', 'utf8mb4_bin'],
                  ['utf8mb4', 'utf8mb4_0900_ai_ci'], ['utf8mb4', 'utf8mb4_uca1400_ai_ci'], ['utf8mb3', 'utf8mb3_general_ci']] as [$cs, $coll]) {
            $tmp = 'w9col_' . bin2hex(random_bytes(3));
            try {
                $pdo->exec("CREATE TEMPORARY TABLE {$tmp} (v VARCHAR(64) CHARACTER SET {$cs} COLLATE {$coll} NOT NULL)");
            } catch (\PDOException $e) {
                $out[$coll] = 'UNSUPPORTED (' . ($e->errorInfo[1] ?? '?') . ')';
                continue;
            }
            $res = [];
            foreach (self::pairs() as $name => [$stored, $probe]) {
                $pdo->exec("DELETE FROM {$tmp}");
                try {
                    $pdo->prepare("INSERT INTO {$tmp} (v) VALUES (?)")->execute([$stored]);
                    $q = $pdo->prepare("SELECT COUNT(*) FROM {$tmp} WHERE v = ?");
                    $q->execute([$probe]);
                    $res[$name] = (int) $q->fetchColumn() > 0;
                } catch (\PDOException $e) {
                    $res[$name] = 'ERROR ' . ($e->errorInfo[1] ?? '?');
                }
            }
            $pdo->exec("DROP TEMPORARY TABLE {$tmp}");
            $out[$coll] = $res;
        }
        self::record('equality under other collations', 'OBSERVED', ['collations' => $out]);
        $this->assertSame(true, $out['utf8mb4_bin']['exact'], 'control: binary collation, identical value equal');
        $this->assertSame(false, $out['utf8mb4_bin']['case'], 'control: binary collation, case differs');
        $this->assertSame(true, $out['utf8mb4_general_ci']['case'], 'control: general_ci is case-insensitive');
        self::record('other-collation controls', 'CONTROL_PASS');
    }

    public function testPhpTrimVersusSqlTrim(): void
    {
        $out = [];
        foreach (['space' => ' wq ', 'tab' => "\twq\t", 'newline' => "\nwq\n", 'carriage return' => "\rwq\r", 'nul' => "\0wq\0", 'vertical tab' => "\x0Bwq\x0B",
                  'no-break space' => "\u{00A0}wq\u{00A0}"] as $name => $v) {
            $st = static::$db->pdo->prepare('SELECT HEX(TRIM(?))');
            $st->execute([$v]);
            $out[$name] = ['php_trim_hex' => strtoupper(bin2hex(trim($v))), 'sql_trim_hex' => (string) $st->fetchColumn()];
            $out[$name]['same'] = $out[$name]['php_trim_hex'] === $out[$name]['sql_trim_hex'];
        }
        self::record('php trim vs sql TRIM', 'OBSERVED', ['values' => $out]);
        $this->assertTrue($out['space']['same'], 'control: spaces are trimmed identically');
        $diff = array_keys(array_filter($out, static fn(array $r): bool => !$r['same']));
        if ($diff !== []) {
            self::record('trim mismatch', 'DEFECT_REPRODUCED', ['detail' => 'SQL TRIM() removes spaces only; PHP trim() (RadiusPolicyBuilder::key) also removes these', 'characters' => $diff]);
        }
    }

    public function testReadOnlyHistoricalSurveyOnSyntheticDuplicates(): void
    {
        $tag = 'w9sv' . bin2hex(random_bytes(2));
        $fx = [
            'cross-field case' => [['username' => "{$tag}a"], ['username' => "{$tag}a2", 'pppoe_username' => strtoupper("{$tag}a")]],
            'trailing space' => [['username' => "{$tag}b "], ['username' => "{$tag}b"]],
            'leading space' => [['username' => " {$tag}c"], ['username' => "{$tag}c"]],
            'trailing tab' => [['username' => "{$tag}d\t"], ['username' => "{$tag}d"]],
            'accent' => [['username' => "{$tag}é"], ['username' => "{$tag}e"]],
            'no duplicate (control)' => [['username' => "{$tag}z"], ['username' => "{$tag}y"]],
        ];
        $ids = [];
        foreach ($fx as $pair) {
            foreach ($pair as $extra) {
                $ids[] = $this->customer(null, null, $extra);
            }
        }
        $before = self::state(static::$db);
        $q1 = static::$db->rows("SELECT COLUMN_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_customers' AND COLUMN_NAME IN ('username','pppoe_username')");
        $q2 = static::$db->rows("SELECT k, COUNT(DISTINCT id) n, GROUP_CONCAT(DISTINCT id) ids FROM (SELECT id, TRIM(username) k FROM tbl_customers UNION ALL SELECT id, TRIM(pppoe_username) FROM tbl_customers WHERE TRIM(pppoe_username) <> '') x GROUP BY k HAVING n > 1");
        $q3 = static::$db->rows('SELECT id FROM tbl_customers WHERE username <> TRIM(username) OR pppoe_username <> TRIM(pppoe_username)');
        $after = self::state(static::$db);
        $found = [];
        foreach ($fx as $name => $pair) {
            $found[$name] = false;
        }
        $idsOf = [];
        $i = 0;
        foreach ($fx as $name => $pair) {
            $idsOf[$name] = [$ids[$i], $ids[$i + 1]];
            $i += 2;
        }
        foreach ($q2 as $g) {
            $members = array_map('intval', explode(',', (string) $g['ids']));
            foreach ($idsOf as $name => $pair) {
                if (count(array_intersect($pair, $members)) === 2) {
                    $found[$name] = true;
                }
            }
        }
        $ws = array_map('intval', array_column($q3, 'id'));
        $wsFound = [];
        foreach ($idsOf as $name => $pair) {
            $wsFound[$name] = array_values(array_intersect($pair, $ws)) !== [];
        }
        $d = self::delta($before, $after);
        self::record('historical survey (read-only)', 'OBSERVED', ['collations' => $q1, 'duplicate_groups_found' => $found, 'whitespace_query_found' => $wsFound, 'state_delta' => $d]);
        $this->assertSame(['business' => [], 'audit' => []], $d, 'control: the survey writes nothing');
        $this->assertTrue($found['cross-field case'], 'control: a cross-field case duplicate is found');
        $this->assertFalse($found['no duplicate (control)'], 'control: distinct names are not reported');
        self::record('survey controls', 'CONTROL_PASS');
        foreach (['trailing tab', 'leading space', 'trailing space', 'accent'] as $name) {
            if (!$found[$name]) {
                self::record("survey misses {$name}", 'DEFECT_REPRODUCED', ['detail' => "the PLAN §7.4 survey does not report the {$name} duplicate"]);
            }
        }
        // remove the synthetic rows (they are test data only)
        static::$db->pdo->exec('DELETE FROM tbl_customers WHERE id IN (' . implode(',', $ids) . ')');
        $this->customersChanged();
    }

    /** P1: CustomerService::create as Admin (G), Agent (T) and Sales (A) against existing identities of another tree */
    public function testP1CreationPathCurrentBehaviour(): void
    {
        $tag = 'w9p1' . bin2hex(random_bytes(2));
        $victim = $this->customer($this->sid('C'), null, ['username' => "{$tag}vic", 'pppoe_username' => "{$tag}vpp"]);
        $vicRow = static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$victim])[0];
        $cases = [
            'username = victim username (upper case)' => ['ad', ['username' => strtoupper("{$tag}vic")], 'refused'],
            'Admin: pppoe_username = victim username' => ['ad', ['username' => "{$tag}n1", 'pppoe_username' => "{$tag}vic"], 'collision'],
            'Admin: username = victim pppoe_username' => ['ad', ['username' => "{$tag}vpp"], 'collision'],
            'Admin: pppoe_username = victim pppoe_username (upper case)' => ['ad', ['username' => "{$tag}n2", 'pppoe_username' => strtoupper("{$tag}vpp")], 'collision'],
            'Agent A (other tree): pppoe_username = victim username' => ['A', ['username' => "{$tag}n3", 'pppoe_username' => "{$tag}vic"], 'collision'],
            'Sales A1 (other tree): username = victim pppoe_username' => ['A1', ['username' => strtoupper("{$tag}vpp")], 'collision'],
            'distinct names (positive control)' => ['A', ['username' => "{$tag}ok", 'pppoe_username' => "{$tag}okpp"], 'created'],
        ];
        foreach ($cases as $name => [$actor, $in, $kind]) {
            $before = self::state(static::$db);
            $radBefore = self::radRows(static::$db, "{$tag}vic");
            $outcome = null;
            try {
                $id = CustomerService::create($this->ctx($actor), $in + ['password' => 'Pw-' . bin2hex(random_bytes(3)), 'fullname' => 'T1A P1 ' . substr(md5($name), 0, 8)]); // <= 45 characters (validateCustomer); run 1 used the case name and was refused INVALID_INPUT
                $outcome = 'CREATED id=' . $id;
            } catch (DomainError $e) {
                $outcome = 'REFUSED ' . $e->reason;
            }
            $this->customersChanged();
            $d = self::delta($before, self::state(static::$db));
            $vicAfter = static::$db->rows('SELECT * FROM tbl_customers WHERE id = ?', [$victim])[0];
            $data = ['actor' => $actor, 'input' => array_intersect_key($in, ['username' => 1, 'pppoe_username' => 1]), 'outcome' => $outcome, 'state_delta' => $d,
                'victim_row_unchanged' => $vicAfter === $vicRow, 'victim_radcheck_unchanged' => self::radRows(static::$db, "{$tag}vic") === $radBefore];
            $this->assertTrue($data['victim_row_unchanged'], "{$name}: the existing customer row is never changed by a creation");
            if ($kind === 'refused') {
                $this->assertSame('REFUSED USERNAME_TAKEN', $outcome, "control {$name}");
                $this->assertSame([], $d['business'], "control {$name}: a refusal changes no business state");
                self::record("P1 {$name}", 'CONTROL_PASS', $data);
            } elseif ($kind === 'created') {
                $this->assertStringStartsWith('CREATED', (string) $outcome, "control {$name}");
                self::record("P1 {$name}", 'CONTROL_PASS', $data);
            } else {
                self::record("P1 {$name}", str_starts_with((string) $outcome, 'CREATED') ? 'DEFECT_REPRODUCED' : 'OBSERVED', $data);
            }
        }
    }

    /** P5: an Admin IDENTITY_EDIT of a reseller-owned customer with no active plan (validate + identity write, no device step) */
    public function testP5RenamePathCurrentBehaviour(): void
    {
        $tag = 'w9p5' . bin2hex(random_bytes(2));
        $victim = $this->customer($this->sid('C'), null, ['username' => "{$tag}vic", 'pppoe_username' => "{$tag}vpp"]);
        $lead = $this->customer(null, null, ['username' => " {$tag}lead"]);
        $tab = $this->customer(null, null, ['username' => "{$tag}tab\t"]);
        $cases = [
            'username -> victim username (upper case)' => [['username' => strtoupper("{$tag}vic")], 'refused', 'USERNAME_TAKEN'],
            'pppoe_username -> victim username' => [['pppoe_username' => "{$tag}vic"], 'refused', 'PPPOE_USERNAME_TAKEN'],
            'pppoe_username -> victim pppoe_username (upper case)' => [['pppoe_username' => strtoupper("{$tag}vpp")], 'refused', 'PPPOE_USERNAME_TAKEN'],
            'pppoe_username -> trimmed form of a stored leading-space username' => [['pppoe_username' => "{$tag}lead"], 'gap', null],
            'pppoe_username -> trimmed form of a stored trailing-tab username' => [['pppoe_username' => "{$tag}tab"], 'gap', null],
            'pppoe_username -> a distinct name (positive control)' => [['pppoe_username' => "{$tag}free"], 'allowed', null],
        ];
        foreach ($cases as $name => [$requested, $kind, $reason]) {
            $o = $this->customer($this->sid('A'), null, ['username' => "{$tag}o" . bin2hex(random_bytes(2))]);
            $before = self::state(static::$db);
            try {
                $r = R::run($this->ctx('ad'), $o, R::IDENTITY_EDIT, $requested);
                $outcome = 'ACCEPTED ' . ($r['status'] ?? '');
            } catch (DomainError $e) {
                $outcome = 'REFUSED ' . $e->reason;
            }
            $this->customersChanged();
            $d = self::delta($before, self::state(static::$db));
            $data = ['requested' => $requested, 'outcome' => $outcome, 'state_delta' => $d];
            if ($kind === 'refused') {
                $this->assertSame('REFUSED ' . $reason, $outcome, "control {$name}");
                $this->assertSame([], $d['business'], "control {$name}: a refusal changes no business state");
                self::record("P5 {$name}", 'CONTROL_PASS', $data);
            } elseif ($kind === 'allowed') {
                $this->assertStringStartsWith('ACCEPTED', $outcome, "control {$name}");
                self::record("P5 {$name}", 'CONTROL_PASS', $data);
            } else {
                self::record("P5 {$name}", str_starts_with($outcome, 'ACCEPTED') ? 'DEFECT_REPRODUCED' : 'OBSERVED',
                    $data + ['detail' => 'the stored name is equal to the new name after trim() (the RADIUS key) but not under the column comparison']);
            }
        }
        static::$db->pdo->exec('DELETE FROM tbl_customers WHERE id IN (' . (int) $lead . ',' . (int) $tab . ')');
        $this->customersChanged();
    }
}
