<?php
namespace Reseller\Tests\Integration\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\FixtureBuilder;
use Reseller\Tests\Support\TestDatabase;

/**
 * Lab SEC-E2 (2026-10-25): on the real CHR a second PPPoE login that arrived just after the first login's
 * Accounting-Start read the reservation ACTIVE, the SNMP guard did not see the brand-new session yet (SAFE), and the
 * ACTIVE take-over admitted it: two live sessions of one subscriber. The fresh-session hold makes the take-over
 * statement itself refuse an ACTIVE row confirmed less than 10 s ago (database clock).
 *
 * Database-level evidence on both engines, like W9AdmissionHardeningTest: the exact take-over statement of the shipped
 * site file runs against a fresh RADIUS schema; the affected-row count is what the policy turns into claim=1 / reject.
 */
final class W10FreshSessionHoldTest extends TestCase
{
    private const OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NEW = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private ?\PDO $p = null;
    private string $db = '';

    protected function setUp(): void
    {
        $host = (string) getenv('WP1_DB_HOST');
        $this->db = 'nuxr_w10hold_' . bin2hex(random_bytes(3));
        FixtureBuilder::connect($host, TestDatabase::rootPassword())->exec("CREATE DATABASE `{$this->db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $this->p = FixtureBuilder::connect($host, TestDatabase::rootPassword(), $this->db);
        $fx = new FixtureBuilder(self::root());
        $fx->loadSqlFile($this->p, self::root() . '/install/radius.sql');
        $fx->loadSqlFile($this->p, self::root() . '/install/freeradius/nuxr_admission.sql');
    }

    protected function tearDown(): void
    {
        FixtureBuilder::connect((string) getenv('WP1_DB_HOST'), TestDatabase::rootPassword())->exec("DROP DATABASE IF EXISTS `{$this->db}`");
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** a reservation of subscriber 'ab' in $state whose session was confirmed $confirmed (SQL expression) */
    private function reservation(string $state, string $confirmed): void
    {
        $this->p->exec("INSERT INTO nuxr_admission (username, state, token, nas, acct_session_id, calling_station_id, nas_port_id, request_authenticator, session_user, attach_count, created_at, updated_at, confirmed_at, last_acct_at)
            VALUES (UNHEX('6162'), '{$state}', '" . self::OLD . "', '10.0.0.1', '81a0', 'aa:bb', 'ether1', '" . str_repeat('c', 32) . "', 'ab', 0, UTC_TIMESTAMP(6) - INTERVAL 1 HOUR, UTC_TIMESTAMP(6), {$confirmed}, {$confirmed})");
    }

    /** the shipped take-over statement (ENDED / verified ACTIVE -> PENDING) for this request; returns the affected rows */
    private function takeOver(string $state): int
    {
        $site = (string) file_get_contents(self::root() . '/install/freeradius/sites-available/phpnuxbill');
        $this->assertSame(1, preg_match('/"nuxr-admission-claim=%\{sql:(UPDATE nuxr_admission SET prev_token = token, .*?)\}"\n/', $site, $m), 'one take-over statement');
        $sql = strtr($m[1], ['%{control:Tmp-String-5}' => self::NEW, '%{NAS-IP-Address}' => '10.0.0.2', '%{Acct-Session-Id}' => '81b0', '%{Calling-Station-Id}' => 'cc:dd',
            '%{NAS-Port-Id}' => 'ether2', '%{Tmp-String-7}' => str_repeat('d', 32), '%{SQL-User-Name}' => 'ab', '%{Tmp-String-2}' => '6162',
            '%{control:Tmp-String-0}' => $state, '%{control:Tmp-String-1}' => self::OLD]);
        $this->assertStringNotContainsString('%{', $sql, 'every expansion substituted');
        return (int) $this->p->exec($sql);
    }

    private function token(): string
    {
        return (string) $this->p->query("SELECT token FROM nuxr_admission WHERE username = UNHEX('6162')")->fetchColumn();
    }

    public function testAFreshActiveSessionIsNeverTakenOver(): void
    {
        $this->reservation('ACTIVE', 'UTC_TIMESTAMP(6) - INTERVAL 20 MICROSECOND');
        $this->assertSame(0, $this->takeOver('ACTIVE'), 'SEC-E2: confirmed just now -> no claim -> reject:active-fresh');
        $this->assertSame(self::OLD, $this->token(), 'the live session keeps its reservation');
        $this->p->exec("UPDATE nuxr_admission SET confirmed_at = UTC_TIMESTAMP(6) - INTERVAL 9500000 MICROSECOND");
        $this->assertSame(0, $this->takeOver('ACTIVE'), 'still inside the 10 s hold');
    }

    public function testAnAgedActiveSessionIsTakenOverAsBefore(): void
    {
        $this->reservation('ACTIVE', 'UTC_TIMESTAMP(6) - INTERVAL 11 SECOND');
        $this->assertSame(1, $this->takeOver('ACTIVE'), 'A1 lost-Stop recovery after the hold is unchanged');
        $row = $this->p->query("SELECT state, token, prev_state, confirmed_at FROM nuxr_admission")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['PENDING', self::NEW, 'ACTIVE', null], [$row['state'], $row['token'], $row['prev_state'], $row['confirmed_at']]);
    }

    public function testAnEndedReservationIsReusedImmediately(): void
    {
        $this->reservation('ENDED', 'UTC_TIMESTAMP(6)');
        $this->assertSame(1, $this->takeOver('ENDED'), 'a session that ended (Stop) is not held: a normal reconnect is never delayed');
    }

    public function testAnActiveRowWithoutConfirmationOrFromTheFutureIsHeld(): void
    {
        $this->reservation('ACTIVE', 'NULL');
        $this->assertSame(0, $this->takeOver('ACTIVE'), 'no confirmation time: cannot prove the age -> fail closed');
        $this->p->exec("UPDATE nuxr_admission SET confirmed_at = UTC_TIMESTAMP(6) + INTERVAL 1 HOUR");
        $this->assertSame(0, $this->takeOver('ACTIVE'), 'a confirmation in the future is never old enough');
    }

    public function testTheStateAndTokenConditionsStillApply(): void
    {
        $this->reservation('ACTIVE', 'UTC_TIMESTAMP(6) - INTERVAL 1 HOUR');
        $this->assertSame(0, $this->takeOver('ENDED'), 'state read as ENDED but the row is ACTIVE');
        $this->p->exec("UPDATE nuxr_admission SET token = '" . str_repeat('e', 32) . "'");
        $this->assertSame(0, $this->takeOver('ACTIVE'), 'another token: lost race');
    }
}
