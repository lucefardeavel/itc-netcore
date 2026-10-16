<?php
namespace Reseller\Tests\Integration\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP9 A2-R hardening (OD-W9-2, owner pre-authorization): SAFE, database-level evidence only. No FreeRADIUS, no SQL
 * pool, no connection pressure, no fault injection: the statements of the shipped site file are executed directly
 * with (a) the values a normal request supplies and (b) an EMPTY string in place of one expansion - the documented
 * effect of the halted scenario, which is never reproduced here.
 *
 *  L1 the CHECK constraints refuse a claim written with an emptied key / token / attempt identity, on both engines;
 *     the guarded upgrade adds them to a pre-WP9 table once and stops on a violating row (never deletes it);
 *  L2 the two open-session statements echo their inputs: an emptied name or key can never yield the accepted form.
 */
final class W9AdmissionHardeningTest extends TestCase
{
    private ?\PDO $p = null;
    private string $db = '';

    protected function setUp(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $this->db = 'nuxr_w9a2r_' . bin2hex(random_bytes(3));
        FixtureBuilder::connect($host, TestDatabase::rootPassword())->exec("CREATE DATABASE `{$this->db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $this->p = FixtureBuilder::connect($host, TestDatabase::rootPassword(), $this->db);
        (new FixtureBuilder(self::root()))->loadSqlFile($this->p, self::root() . '/install/radius.sql');
    }

    protected function tearDown(): void
    {
        FixtureBuilder::connect((string) getenv('WP1_DB_HOST'), TestDatabase::rootPassword())->exec("DROP DATABASE IF EXISTS `{$this->db}`");
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function upgrade(): void
    {
        (new FixtureBuilder(self::root()))->loadSqlFile($this->p, self::root() . '/install/freeradius/nuxr_admission.sql');
    }

    private function claim(array $over = []): void
    {
        $v = $over + ['username' => "UNHEX('6162')", 'token' => "'" . str_repeat('a', 32) . "'", 'nas' => "'10.0.0.1'", 'sid' => "'81a0'", 'csid' => "'aa:bb'",
            'port' => "'ether1'", 'auth' => "'" . str_repeat('b', 32) . "'", 'user' => "'ab'"];
        $this->p->exec("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at)
            VALUES ({$v['username']}, 'PENDING', {$v['token']}, {$v['nas']}, {$v['sid']}, {$v['csid']}, {$v['port']}, {$v['auth']}, {$v['user']}, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
    }

    /** @return array<string,array{0:array<string,string>}> */
    public static function emptied(): array
    {
        return [
            'key UNHEX(empty)' => [['username' => "UNHEX('')"]],
            'token' => [['token' => "''"]],
            'session user' => [['user' => "''"]],
            'nas' => [['nas' => "''"]],
            'acct session id' => [['sid' => "''"]],
            'calling station' => [['csid' => "''"]],
            'nas port' => [['port' => "''"]],
            'request authenticator' => [['auth' => "''"]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptied')]
    public function testL1AClaimWithAnEmptiedValueIsRefused(array $over): void
    {
        $this->upgrade();
        try {
            $this->claim($over);
            $this->fail('the database stored a claim with an emptied value');
        } catch (\PDOException $e) {
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), [3819, 4025, 1048], 'refused by the CHECK constraint (MySQL 3819 / MariaDB 4025): ' . $e->getMessage());
        }
        $this->assertSame(0, (int) $this->p->query('SELECT COUNT(*) FROM nuxr_admission')->fetchColumn());
    }

    public function testL1AWellFormedClaimIsStoredAndEmptyTokenNeverMatches(): void
    {
        $this->upgrade();
        $this->claim();
        $this->assertSame(1, (int) $this->p->query('SELECT COUNT(*) FROM nuxr_admission')->fetchColumn(), 'positive control');
        $this->assertSame(0, $this->p->exec("UPDATE nuxr_admission SET state = 'ENDED' WHERE token = ''"), 'an emptied token in a release / Stop statement matches nothing');
    }

    public function testL1GuardedUpgradeOfAPreWp9TableAndViolatingRows(): void
    {
        $old = (string) shell_exec('git -C ' . escapeshellarg(self::root()) . ' show 7e46758114545dd4a6d2b0179e9d5d7ceb60e643:install/freeradius/nuxr_admission.sql 2>/dev/null');
        if ($old === '') {
            // the test container has no git: the pre-WP9 table definition is the current one without the two constraints
            $old = (string) preg_replace('/,\s*--[^\n]*\n(\s*--[^\n]*\n)*\s*CONSTRAINT ck_nuxr_admission_token.*?\)\s*\n\)\s*ENGINE/s', "\n) ENGINE", (string) file_get_contents(self::root() . '/install/freeradius/nuxr_admission.sql'), 1);
            $old = substr($old, 0, (int) strpos($old, '-- WP9 A2-R hardening L1 for a nuxr_admission table created BEFORE WP9'));
        }
        $this->assertStringNotContainsString('ck_nuxr_admission_token', $old, 'pre-WP9 definition');
        foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('/^--.*$/m', '', $old)))) as $sql) {
            $this->p->exec($sql);
        }
        $this->claim(['token' => "''"]); // a row the old schema accepted
        try {
            $this->upgrade();
            $this->fail('the upgrade must stop on a violating row');
        } catch (\PDOException $e) {
            $this->assertStringContainsStringIgnoringCase('ck_nuxr_admission', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->p->query('SELECT COUNT(*) FROM nuxr_admission')->fetchColumn(), 'the violating row is never deleted automatically');
        $this->p->exec('DELETE FROM nuxr_admission'); // the operator resolved it
        $this->upgrade();
        $this->upgrade(); // idempotent
        $n = (int) $this->p->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nuxr_admission' AND CONSTRAINT_TYPE = 'CHECK'")->fetchColumn();
        $this->assertSame(2, $n);
        $this->assertSame('nuxr_admission=READY', (string) $this->p->query((string) file_get_contents(self::root() . '/install/freeradius/nuxr_admission_check.sql'))->fetchColumn());
    }

    /** the exact SQL of one %{sql:...} assignment of the shipped site, with the given expansions substituted */
    private function statement(string $attr, array $values): string
    {
        $site = (string) file_get_contents(self::root() . '/install/freeradius/sites-available/phpnuxbill');
        $this->assertSame(1, preg_match('/&' . preg_quote($attr, '/') . ' := "%\{sql:(SELECT CONCAT\(\'' . ($attr === 'Tmp-String-8' ? 'n' : 'm') . '=\'.*?)\}"\n/', $site, $m), $attr);
        return strtr($m[1], $values);
    }

    public function testL2EchoTurnsAnEmptiedInputIntoAReject(): void
    {
        $this->upgrade();
        $this->p->exec("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime) VALUES ('s1', 'u1', 'ab', '10.0.0.9', UTC_TIMESTAMP())");
        $key = '6162'; // HEX('ab')
        $accept = '/^n=[0-9]+;[0-9.,]*;u=[0-9a-f]{2,512};k=' . $key . '$/';
        $acceptM = '/^m=[0-9]+;u=[0-9a-f]{2,512}$/';
        $run = fn(string $sql): string => (string) $this->p->query($sql)->fetchColumn();
        // a normal request: the echo is well formed and the open row is counted
        $n = $run($this->statement('Tmp-String-8', ["%{SQL-User-Name}" => 'ab', "%{Tmp-String-2}" => $key]));
        $this->assertMatchesRegularExpression($accept, $n, 'positive control');
        $this->assertStringStartsWith('n=1;10.0.0.9;', $n);
        $this->assertMatchesRegularExpression($acceptM, $run($this->statement('Tmp-String-4', ["%{SQL-User-Name}" => 'ab'])));
        // an emptied subscriber name (would have counted n=0 and skipped the NAS verification): rejected by the echo
        $n = $run($this->statement('Tmp-String-8', ["%{SQL-User-Name}" => '', "%{Tmp-String-2}" => $key]));
        $this->assertStringStartsWith('n=0;', $n, 'the condition the echo exists for');
        $this->assertDoesNotMatchRegularExpression($accept, $n, 'emptied name -> reject:echo');
        $this->assertDoesNotMatchRegularExpression($acceptM, $run($this->statement('Tmp-String-4', ["%{SQL-User-Name}" => ''])), 'emptied name -> reject:echo');
        // an emptied canonical key: the echoed key is not this request's key
        $this->assertDoesNotMatchRegularExpression($accept, $run($this->statement('Tmp-String-8', ["%{SQL-User-Name}" => 'ab', "%{Tmp-String-2}" => ''])), 'emptied key -> reject:echo');
    }
}
