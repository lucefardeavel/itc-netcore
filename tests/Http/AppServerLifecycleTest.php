<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\AppServer;
use Reseller\Tests\Support\TestDatabase;

/**
 * WP8 AppServer repair (test infrastructure): the disposable `php -S` server runs a master and 4 workers in its OWN
 * process group; stop() removes all of them and frees the port; a busy port (a foreign listener or orphaned workers
 * of an earlier server) is never reused; a failed start leaves nothing behind; the workers serve requests in parallel.
 * Before the repair, stop() signalled only the master and the 4 workers kept serving HTTP 404 on the port.
 */
final class AppServerLifecycleTest extends TestCase
{
    private static ?TestDatabase $db = null;
    /** @var list<AppServer> */
    private array $apps = [];

    public static function setUpBeforeClass(): void
    {
        self::$db = new TestDatabase('as');
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->drop();
        self::$db = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->apps as $a) {
            $a->destroy();
        }
        $this->apps = [];
    }

    private function app(): AppServer
    {
        return $this->apps[] = AppServer::create(self::$db);
    }

    /** a process exists and is not a zombie */
    private static function alive(int $pid): bool
    {
        $raw = @file_get_contents('/proc/' . $pid . '/stat');
        return $raw !== false && substr($raw, strrpos($raw, ')') + 2, 1) !== 'Z';
    }

    private static function accepts(int $port): bool
    {
        $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($c === false) {
            return false;
        }
        fclose($c);
        return true;
    }

    /** live processes whose command line serves $dir (only this test's servers can match) */
    private static function serversOf(string $dir): array
    {
        $out = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $d) {
            $cmd = str_replace("\0", ' ', (string) @file_get_contents($d . '/cmdline'));
            if (str_contains($cmd, ' -S 127.0.0.1:') && str_contains($cmd, ' -t ' . $dir) && self::alive((int) basename($d))) {
                $out[] = (int) basename($d);
            }
        }
        return $out;
    }

    private static function get(AppServer $a, string $path): string|false
    {
        return @file_get_contents($a->baseUrl() . '/' . $path, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
    }

    public function testStartsAMasterAndFourWorkersInItsOwnGroupAndStopRemovesThemAll(): void
    {
        $a = $this->app();
        file_put_contents($a->dir . '/wp2_probe/hello.txt', 'HELLO-' . basename($a->dir));
        $a->start();
        $pg = $a->processGroup();
        $procs = $a->processes();
        $this->assertGreaterThan(1, $pg);
        $this->assertNotSame(posix_getpgid(getmypid()), $pg, 'not the test runner\'s process group');
        $this->assertCount(5, $procs, 'master + 4 workers (PHP_CLI_SERVER_WORKERS=4)');
        $this->assertSame(getmypid(), $procs[$pg], 'the master is the direct child of the test runner');
        $this->assertCount(4, array_filter($procs, static fn(int $ppid): bool => $ppid === $pg), 'four workers forked by the master');
        $this->assertSame('HELLO-' . basename($a->dir), self::get($a, 'wp2_probe/hello.txt'), 'THIS copy answers');
        $port = $a->port();

        $a->stop();
        foreach (array_keys($procs) as $pid) {
            $this->assertFalse(self::alive($pid), "process {$pid} of the server group is gone");
        }
        $this->assertFalse(self::accepts($port), 'no listener left on the port');
        $this->assertSame([], $a->processes());
        $this->assertSame(0, $a->processGroup());
        $a->stop();                                   // idempotent
        $a->destroy();
        $a->destroy();                                // idempotent
        $this->assertDirectoryDoesNotExist($a->dir);
        $this->assertSame([], self::serversOf($a->dir));
    }

    public function testRestartServesTheSameCopyFromANewProcessGroup(): void
    {
        $a = $this->app();
        file_put_contents($a->dir . '/wp2_probe/hello.txt', 'RESTART');
        $a->start();
        $first = $a->processes();
        $a->stop();
        $a->start();
        $second = $a->processes();
        $this->assertCount(5, $second);
        $this->assertSame([], array_intersect(array_keys($first), array_keys($second)), 'new processes');
        foreach (array_keys($first) as $pid) {
            $this->assertFalse(self::alive($pid));
        }
        $this->assertSame('RESTART', self::get($a, 'wp2_probe/hello.txt'));
        $a->start();                                  // idempotent while running
        $this->assertSame($second, $a->processes());
    }

    public function testABusyPortIsNeverReusedAndAForeignListenerIsNeverTouched(): void
    {
        $foreign = null;
        for ($i = 0; $i < 20 && $foreign === null; $i++) {
            $p = 20000 + random_int(0, 9999);
            $foreign = @stream_socket_server('tcp://127.0.0.1:' . $p) ?: null;
        }
        $this->assertNotNull($foreign);
        $fp = (int) substr(strrchr(stream_socket_get_name($foreign, false), ':'), 1);
        $a = $this->app();
        $a->start([$fp]);
        $this->assertNotSame($fp, $a->port(), 'the busy port was skipped');
        $a->stop();
        $this->assertTrue(self::accepts($fp), 'the foreign listener (this test process) still works: never signalled');
        fclose($foreign);
    }

    public function testOrphanedWorkersOfAnEarlierServerAreNeitherReusedNorLeftBehind(): void
    {
        $old = $this->app();
        file_put_contents($old->dir . '/wp2_probe/who.txt', 'OLD');
        $old->start();
        $workers = array_keys(array_filter($old->processes(), fn(int $ppid): bool => $ppid === $old->processGroup()));
        $this->assertCount(4, $workers);
        // the master ends before its workers (crash): the workers keep the socket - the pre-repair failure mode
        posix_kill($old->processGroup(), 9);
        usleep(300000);
        $this->assertTrue(self::accepts($old->port()), 'orphaned workers still listen');
        foreach ($workers as $w) {
            $this->assertTrue(self::alive($w));
        }

        $new = $this->app();
        file_put_contents($new->dir . '/wp2_probe/who.txt', 'NEW');
        $new->start([$old->port()]);
        $this->assertNotSame($old->port(), $new->port(), 'the port held by the orphans is not reused');
        $this->assertSame('NEW', self::get($new, 'wp2_probe/who.txt'), 'the new instance answers for its own copy');

        $old->stop();                                 // the group of the dead master is still found and removed
        foreach ($workers as $w) {
            $this->assertFalse(self::alive($w), "orphaned worker {$w} removed");
        }
        $this->assertFalse(self::accepts($old->port()));
        $this->assertSame('NEW', self::get($new, 'wp2_probe/who.txt'), 'the other server is untouched');
        $this->assertCount(5, $new->processes());
    }

    public function testAFailedStartLeavesNoProcessAndNoListener(): void
    {
        $a = $this->app();
        try {
            $a->start([], 0.0);                       // no time to come up: every attempt times out
            $this->fail('start must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('app server did not start', $e->getMessage());
        }
        preg_match_all('/(\d{5}):timeout/', $e->getMessage(), $m);
        $this->assertNotEmpty($m[1], $e->getMessage());
        foreach ($m[1] as $port) {
            $this->assertFalse(self::accepts((int) $port), "no listener left on attempted port {$port}");
        }
        $this->assertSame([], self::serversOf($a->dir), 'no server process of this copy left');
        $this->assertSame(0, $a->processGroup());
        $this->assertSame([], glob($a->dir . '/wp2_probe/server-*.txt'), 'sentinels removed');
        // the instance is still usable after the failure
        $a->start();
        $this->assertCount(5, $a->processes());
    }

    public function testFourWorkersServeRequestsInParallel(): void
    {
        $a = $this->app();
        file_put_contents($a->dir . '/wp2_probe/slow.php', "<?php usleep(1500000); echo getmypid();");
        $a->start();
        $socks = [];
        $t0 = microtime(true);
        for ($i = 0; $i < 4; $i++) {
            $s = stream_socket_client('tcp://127.0.0.1:' . $a->port(), $errno, $errstr, 5);
            fwrite($s, "GET /wp2_probe/slow.php HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
            $socks[] = $s;
        }
        $pids = [];
        foreach ($socks as $s) {
            stream_set_timeout($s, 10);
            $body = stream_get_contents($s);
            fclose($s);
            $pids[] = (int) trim(substr($body, strpos($body, "\r\n\r\n") + 4));
        }
        $elapsed = microtime(true) - $t0;
        $this->assertLessThan(4.0, $elapsed, 'four 1.5 s requests did not run one after another (serial >= 6 s)');
        $this->assertGreaterThanOrEqual(2, count(array_unique($pids)), 'served by several workers');
        foreach ($pids as $pid) {
            $this->assertArrayHasKey($pid, $a->processes(), 'every answer came from a worker of THIS server');
        }
    }
}
