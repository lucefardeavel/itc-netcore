<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';
require_once dirname(__DIR__, 3) . '/system/autoload/Reseller/Ui/Subscriber.php';

use PHPUnit\Framework\TestCase;
use Reseller\Radius\AccountingService;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;
use Reseller\Ui\Subscriber;

/**
 * WP8 A4 (R-WP8-CARRY-03) accounting time contract on the real lab (RouterOS CHR 7.24.5 PPPoE, real FreeRADIUS writer):
 * the application zone (Asia/Baghdad) differs from the RADIUS database zone (UTC), exactly the OBS-W8-8 condition.
 *
 *   T-L1  a live session is not stale with the APPLICATION clock; after the accounting path is cut (UDP/1813 dropped on the
 *         NAS) past the threshold it is; when accounting resumes it is fresh again; after Stop it is never stale
 *   T-L2  Subscriber::session() shows the live session "online", its times in the application zone with the UTC offset,
 *         and "Online since" is the real start instant (RouterOS uptime), "offline" after Stop
 *   T-L4  whether RouterOS sends Event-Timestamp in Accounting-Requests (then radacct times come from the NAS clock)
 *
 * Retained history (every older radacct row) is byte-identical before / after. Evidence: Wp8Lab::evidence (A4.*).
 */
final class AccountingTimeLabTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
    private array $rules = [];
    private ?string $capture = null;
    private mixed $config;

    protected function setUp(): void
    {
        $this->requireLab();
        $this->config = $GLOBALS['config'] ?? null;
    }

    protected function tearDown(): void
    {
        if (static::labRadius() !== null) {
            foreach ($this->rules as $id) {
                Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
            }
            $this->rules = [];
            if ($this->capture !== null) {
                Wp8Lab::agent('capture_stop', ['name' => $this->capture]);
                $this->capture = null;
            }
            $this->releaseClients();
        }
        $GLOBALS['config'] = $this->config;
        if (class_exists(\ORM::class, false)) {
            \ORM::reset_db();
        }
    }

    private function rows(string $sql, string ...$params): array
    {
        $st = static::labRadius()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_NUM);
    }

    private function session(string $user): array
    {
        require_once dirname(__DIR__, 3) . '/system/orm.php';
        $GLOBALS['config'] = ['radius_enable' => 1] + (is_array($this->config) ? $this->config : []);
        \ORM::reset_db();
        \ORM::set_db(static::labRadius(), 'radius');
        return Subscriber::session(['username' => $user, 'pppoe_username' => ''], true);
    }

    /** @return list<int> radacctids of $user reported stale with the application clock */
    private function stale(string $user): array
    {
        $rows = (new AccountingService(static::labRadius()))->staleSessions(new \DateTimeImmutable('now'), 120);
        return array_values(array_map('intval', array_column(array_filter($rows, static fn($r) => $r['username'] === $user), 'radacctid')));
    }

    private function epochs(int $id): array
    {
        return array_map(static fn($v) => $v === null ? null : (int) $v,
            $this->rows('SELECT UNIX_TIMESTAMP(acctstarttime), UNIX_TIMESTAMP(acctupdatetime), UNIX_TIMESTAMP(acctstoptime) FROM radacct WHERE radacctid = ?', (string) $id)[0]);
    }

    private static function uptime(string $u): int
    {
        preg_match_all('/(\d+)([wdhms])/', $u, $m, PREG_SET_ORDER);
        $mult = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        return array_sum(array_map(static fn($x) => (int) $x[1] * $mult[$x[2]], $m));
    }

    private static function instant(?string $label): ?int
    {
        $d = $label === null ? false : \DateTimeImmutable::createFromFormat('Y-m-d H:i:s \U\T\CP', $label);
        return $d === false ? null : $d->getTimestamp();
    }

    public function testTheLiveChrSessionFollowsTheTimeContract(): void
    {
        $db = $this->rows('SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone, UNIX_TIMESTAMP(), TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')[0];
        $env = ['php_zone' => date_default_timezone_get(), 'php_offset' => (int) date('Z'), 'db_global' => $db[0], 'db_session' => $db[1], 'db_system' => $db[2],
            'db_offset' => (int) $db[4], 'db_epoch_minus_php' => (int) $db[3] - time(), 'chr_clock' => Wp8Lab::ros('/system/clock/print')[0] ?? []];
        $this->assertNotSame($env['db_offset'], $env['php_offset'], 'discriminating lab: the application zone differs from the database zone (OBS-W8-8 condition)');
        $this->assertLessThanOrEqual(2, abs($env['db_epoch_minus_php']), 'one clock (NTP host)');
        $historyBefore = $this->rows('SELECT * FROM radacct ORDER BY radacctid');

        $user = $this->provisionPolicy(['username' => self::ident('a4'), 'password' => 'A4-time-1', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $this->capture = 'a4-acct-' . bin2hex(random_bytes(3));
        $this->assertTrue(Wp8Lab::agent('capture_start', ['name' => $this->capture])['ok']);
        $up = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $user, 'password' => 'A4-time-1']);
        $this->assertTrue($up['ok'], json_encode($up['log'] ?? null));
        $row = Wp8Lab::until(fn() => $this->acct($user)[0] ?? null, 20, 1);
        $this->assertNotNull($row, 'radacct Start written by FreeRADIUS');
        $id = (int) $row['radacctid'];
        $interim = Wp8Lab::until(fn() => ($e = $this->epochs($id)) && $e[1] !== null && $e[1] > $e[0] ? $e : null, 100, 2);
        $this->assertNotNull($interim, 'Interim-Update received');

        // T-L1 / T-L2: live, fresh accounting
        $active = Wp8Lab::pppActive($user);
        $this->assertCount(1, $active);
        $realStart = time() - self::uptime((string) $active[0]['uptime']);
        $this->assertSame([], $this->stale($user), 'a live session is NOT stale with the application clock (OBS-W8-8 reversed)');
        $s = $this->session($user);
        $this->assertSame('online', $s['state'], 'Subscriber 360: Online, not "Status unknown"');
        $this->assertSame(1, $s['open_sessions']);
        $this->assertLessThanOrEqual(3, abs(self::instant($s['started']) - $realStart), '"Online since" is the real start instant (RouterOS uptime)');
        $this->assertSame((new \DateTimeImmutable('@' . $interim[0]))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s \U\T\CP'), $s['started'], 'application zone and offset');
        $this->assertStringEndsWith('UTC' . date('P'), $s['last_update']);
        $live = ['row' => $this->epochs($id), 'started' => $s['started'], 'last_update' => $s['last_update'], 'real_start' => $realStart, 'uptime' => $active[0]['uptime'],
            'db_text' => $this->rows('SELECT acctstarttime, acctupdatetime FROM radacct WHERE radacctid = ?', (string) $id)[0]];

        // accounting path cut on the NAS: no Interim-Update reaches FreeRADIUS, the PPPoE session stays up
        $this->rules[] = $rule = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_wp8_drill');
        $staleNow = Wp8Lab::until(fn() => $this->stale($user) === [$id] ? true : null, 240, 5);
        $this->assertTrue((bool) $staleNow, 'accounting lost past the threshold: reported stale (application clock)');
        $this->assertCount(1, Wp8Lab::pppActive($user), 'the session itself is still up on the NAS');
        $cut = ['age_s' => time() - (int) $this->epochs($id)[1], 'state' => $this->session($user)['state']];
        $this->assertGreaterThan(120, $cut['age_s']);
        $this->assertSame('online', $cut['state'], 'below the 1800 s display threshold the admin view still says Online (documented threshold)');
        Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $rule);
        $this->rules = [];
        $before = (int) $this->epochs($id)[1];
        $resumed = Wp8Lab::until(fn() => ($e = $this->epochs($id)) && $e[1] > $before ? $e : null, 120, 2);
        $this->assertNotNull($resumed, 'Interim-Updates resume');
        $this->assertSame([], $this->stale($user), 'fresh again');
        $this->assertSame('online', $this->session($user)['state']);

        // Stop
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $stopped = Wp8Lab::until(fn() => ($e = $this->epochs($id)) && $e[2] !== null ? $e : null, 30, 1);
        $this->assertNotNull($stopped, 'Stop recorded');
        $this->assertSame([], $this->stale($user), 'a stopped session is never stale');
        $s = $this->session($user);
        $this->assertSame('offline', $s['state']);
        $this->assertLessThanOrEqual(5, abs(self::instant($s['stopped']) - time()), 'the Stop instant');

        // T-L4: Event-Timestamp (attribute 55) in the RouterOS Accounting-Requests
        $pk = Wp8Lab::agent('capture_stop', ['name' => $this->capture])['packets'];
        $this->capture = null;
        $acct = array_values(array_filter($pk, static fn($p) => $p['code'] === 'Accounting-Request' && str_starts_with($p['src'], Wp8Lab::CHR . ':')));
        $this->assertNotEmpty($acct, 'Accounting-Requests captured');
        $withTs = count(array_filter($acct, static fn($p) => in_array(55, $p['attrs'], true)));
        $history = array_slice($this->rows('SELECT * FROM radacct ORDER BY radacctid'), 0, count($historyBefore));
        $this->assertSame($historyBefore, $history, 'retained history byte-identical (reads never write; FreeRADIUS only touched the new row)');
        Wp8Lab::evidence('A4.time-contract.chr', ['env' => $env, 'user' => $user, 'radacctid' => $id, 'live' => $live, 'accounting_cut' => $cut,
            'resumed' => $resumed, 'stopped' => $stopped, 'stopped_label' => $s['stopped'],
            'event_timestamp' => ['accounting_requests' => count($acct), 'with_event_timestamp' => $withTs, 'attrs_first' => $acct[0]['attrs'] ?? null]]);
    }
}
