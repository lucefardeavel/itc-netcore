<?php
namespace Reseller\Tests\Integration\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\StubNas;

/**
 * WP8 TI-02: the loopback test NAS used by the CoA / Disconnect tests. Before the repair, received() could read the
 * stub's log between its creation and its content (or kill the stub mid-write): the ACK arrived but the recorded
 * request was empty (reproduced 5 / 600). Now: every answered request is recorded, silent mode records every
 * retransmission, a bind failure is retried on another port, timeouts and malformed logs fail loudly, and only the
 * instance's own process is ever stopped. No database, no production code.
 */
final class StubNasReliabilityTest extends TestCase
{
    private const SECRET = 'ti02-stub-secret';

    private static function alive(int $pid): bool
    {
        $raw = @file_get_contents('/proc/' . $pid . '/stat');
        return $raw !== false && substr($raw, strrpos($raw, ')') + 2, 1) !== 'Z';
    }

    /** a minimal RADIUS request (code, id, length 20, random authenticator); returns [reply code or null, authenticator hex] */
    private static function exchange(int $port, int $code, int $id, int $sends = 1, float $wait = 3.0): array
    {
        $s = stream_socket_client('udp://127.0.0.1:' . $port, $errno, $errstr);
        $auth = random_bytes(16);
        $pkt = chr($code) . chr($id) . pack('n', 20) . $auth;
        for ($i = 0; $i < $sends; $i++) {
            fwrite($s, $pkt);
        }
        stream_set_timeout($s, (int) ceil($wait));
        $reply = fread($s, 4096);
        fclose($s);
        return [$reply === '' || $reply === false ? null : ord($reply[0]), bin2hex($auth)];
    }

    /** UDP sockets owned by this test process (stand-ins for "a port held by someone else") */
    private static function occupy(int $n): array
    {
        $out = [];
        while (count($out) < $n) {
            $p = 30000 + random_int(0, 9999);
            $s = @stream_socket_server('udp://127.0.0.1:' . $p, $errno, $errstr, STREAM_SERVER_BIND);
            if ($s !== false) {
                $out[$p] = $s;
            }
        }
        return $out;
    }

    private static function files(): array
    {
        return glob(sys_get_temp_dir() . '/wp4_nas_*') ?: [];
    }

    public function testEveryAnsweredRequestIsRecordedOverManyExchanges(): void
    {
        $lost = [];
        for ($i = 0; $i < 300; $i++) {
            $code = $i % 2 === 0 ? 43 : 40;             // CoA-Request / Disconnect-Request
            $stub = new StubNas(self::SECRET, 'ack');
            [$reply, $auth] = self::exchange($stub->port, $code, $i % 256);
            $got = $stub->received();
            if ($reply !== $code + 1 || count($got) !== 1 || $got[0]['code'] !== $code || $got[0]['id'] !== $i % 256 || $got[0]['auth'] !== $auth) {
                $lost[] = ['i' => $i, 'reply' => $reply, 'got' => $got];
            }
        }
        $this->assertSame([], $lost, 'every ACKed request is in the record (pre-repair: some ACKed requests were lost)');
    }

    public function testSilentModeRecordsEveryRetransmission(): void
    {
        $stub = new StubNas(self::SECRET, 'silent');
        [$reply, $auth] = self::exchange($stub->port, 40, 7, 3, 1.0);
        $this->assertNull($reply, 'silent: no answer');
        $got = $stub->received();
        $this->assertCount(3, $got, 'the request and both retransmissions');
        $this->assertSame([[40, 7, $auth]], array_values(array_unique(array_map(static fn(array $g): array => [$g['code'], $g['id'], $g['auth']], $got), SORT_REGULAR)), 'identical packets');
    }

    public function testNakModesAndForgedRepliesStillAnswerAsBefore(): void
    {
        foreach (['nak' => 45, 'nak503' => 45, 'badauth' => 44, 'ack' => 44] as $mode => $want) {
            $stub = new StubNas(self::SECRET, $mode);
            [$reply] = self::exchange($stub->port, 43, 9);
            $this->assertSame($want, $reply, $mode);
            $this->assertCount(1, $stub->received(), $mode);
        }
    }

    public function testABindFailureAndAPortInUseAreRetriedOnAnotherPortAndCleaned(): void
    {
        // PHP binds every stream socket with SO_REUSEADDR: a second UDP bind on a used port does NOT fail by itself;
        // the stub must detect the other socket (port_in_use). Port 70000 cannot be bound at all (bind).
        $busy = self::occupy(1);
        $before = self::files();
        $stub = new StubNas(self::SECRET, 'ack', [70000, array_key_first($busy), 30000 + random_int(0, 9999)]);
        $this->assertNotSame(array_key_first($busy), $stub->port);
        $this->assertSame(['70000:bind', array_key_first($busy) . ':port_in_use'], $stub->failedAttempts(), 'both failures detected, not waited out');
        [$reply] = self::exchange($stub->port, 43, 1);
        $this->assertSame(44, $reply);
        $this->assertCount(1, $stub->received());
        $this->assertSame($before, self::files(), 'no file left by the failed attempt or the stub');
        foreach ($busy as $p => $s) {
            $this->assertNotFalse(@stream_socket_get_name($s, false), 'the socket held by someone else is untouched');
            fclose($s);
        }
    }

    public function testNoUsablePortFailsLoudlyAndLeavesNothing(): void
    {
        $busy = self::occupy(2);
        $before = self::files();
        try {
            new StubNas(self::SECRET, 'ack', array_keys($busy));
            $this->fail('must not start');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stub NAS did not start', $e->getMessage());
            foreach (array_keys($busy) as $p) {
                $this->assertStringContainsString($p . ':port_in_use', $e->getMessage());
            }
        }
        $this->assertSame($before, self::files());
        foreach ($busy as $s) {
            fclose($s);
        }
    }

    public function testATimeoutFailsLoudlyAndStopsOnlyItsOwnStub(): void
    {
        $other = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);   // an unrelated process
        $otherPid = proc_get_status($other)['pid'];
        $stub = new StubNas(self::SECRET, 'silent');                       // listens 8 s
        $pid = $stub->pid();
        $port = $stub->port;
        $this->assertTrue(self::alive($pid));
        try {
            $stub->received(0.5);
            $this->fail('must time out');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not finish within 0.5 s', $e->getMessage());
        }
        $this->assertFalse(self::alive($pid), 'its own stub is stopped');
        $this->assertSame(0, $stub->pid());
        $this->assertTrue(self::alive($otherPid), 'an unrelated process is never signalled');
        $free = @stream_socket_server('udp://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND);
        $this->assertNotFalse($free, 'the port is released');
        fclose($free);
        proc_terminate($other, 9);
        proc_close($other);
    }

    public function testMalformedOrIncompleteLogsAreRejected(): void
    {
        $bad = ['missing' => null, 'empty' => '', 'partial' => '[{"code":43,"id":1', 'not_json' => 'garbage', 'error' => '{"error":"bind","errno":98}',
                'not_a_list' => '{"a":1}', 'record_fields' => '[{"code":43}]', 'record_types' => '[{"code":"43","id":1,"auth":"aa","valid":true,"attributes":[]}]'];
        foreach ($bad as $label => $raw) {
            try {
                StubNas::parseLog($raw);
                $this->fail($label . ' must be rejected');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('stub NAS', $e->getMessage(), $label);
            }
        }
        $this->assertSame([], StubNas::parseLog('[]'), 'nothing received is a valid, complete record');
        $this->assertCount(1, StubNas::parseLog('[{"code":43,"id":1,"auth":"aa","valid":false,"attributes":[]}]'));
    }

    public function testReceivedIsIdempotentAndCleansUp(): void
    {
        $before = self::files();
        $stub = new StubNas(self::SECRET, 'ack');
        $pid = $stub->pid();
        self::exchange($stub->port, 43, 3);
        $first = $stub->received();
        $this->assertSame($first, $stub->received(), 'a second call returns the same record');
        $this->assertFalse(self::alive($pid));
        $this->assertSame($before, self::files(), 'log, ready marker and temporary files removed');
        $free = @stream_socket_server('udp://127.0.0.1:' . $stub->port, $errno, $errstr, STREAM_SERVER_BIND);
        $this->assertNotFalse($free, 'the port is released');
        fclose($free);
    }
}
