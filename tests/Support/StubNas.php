<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A loopback test NAS in its own process (stub_nas.php): answers one Disconnect / CoA request per mode.
 *
 * WP8 TI-02: received() used to read the log as soon as the FILE existed and then terminated the stub, so it could read
 * an empty / partial log (the ACK had arrived, the record was lost). Now the stub publishes its log atomically, and
 * received() waits for the stub to EXIT on its own (bounded), then reads and validates the complete JSON document.
 * A bind failure is detected and retried on another port; every failure is reported loudly. Only this instance's own
 * child process is ever signalled (through its proc_open handle).
 */
final class StubNas
{
    public readonly int $port;
    private string $log = '';
    /** @var resource|null */
    private $proc = null;
    private int $pid = 0;
    /** @var list<array<string,mixed>>|null */
    private ?array $data = null;
    /** @var list<string> */
    private array $attempts = [];

    private const READY_TIMEOUT = 10.0;
    private const RANDOM_ATTEMPTS = 5;

    /** @param list<int> $ports ports to try instead of random ones (tests) */
    public function __construct(public readonly string $secret, string $mode, array $ports = [])
    {
        $candidates = $ports !== [] ? $ports : array_map(static fn(): int => 30000 + random_int(0, 9999), range(1, self::RANDOM_ATTEMPTS));
        foreach ($candidates as $port) {
            $why = $this->launch($port, $mode);
            if ($why === null) {
                $this->port = $port;
                return;
            }
            $this->attempts[] = $port . ':' . $why;
        }
        throw new \RuntimeException('stub NAS did not start (' . implode('; ', $this->attempts) . ')');
    }

    /** @return string|null null = listening; otherwise the reason (that attempt is already cleaned up) */
    private function launch(int $port, string $mode): ?string
    {
        $this->log = sys_get_temp_dir() . '/wp4_nas_' . bin2hex(random_bytes(5)) . '.json';
        $proc = proc_open([PHP_BINARY, __DIR__ . '/stub_nas.php', (string) $port, $this->secret, $mode, $this->log],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            return 'proc_open failed';
        }
        $this->proc = $proc;
        $this->pid = (int) proc_get_status($proc)['pid'];
        $deadline = microtime(true) + self::READY_TIMEOUT;
        while (microtime(true) < $deadline) {
            if (is_file($this->log . '.ready')) {
                return null;
            }
            if (!proc_get_status($proc)['running']) {
                break;
            }
            usleep(10000);
        }
        $doc = json_decode((string) @file_get_contents($this->log), true);
        $why = is_array($doc) && isset($doc['error']) ? (string) $doc['error'] : 'not ready';
        $this->release();
        return $why;
    }

    /** @return list<string> "port:reason" of every failed start attempt */
    public function failedAttempts(): array
    {
        return $this->attempts;
    }

    /** the stub's pid while it has not been reaped, 0 afterwards */
    public function pid(): int
    {
        return $this->proc !== null ? $this->pid : 0;
    }

    /**
     * What the NAS received: waits (bounded) for the stub to finish on its own - it publishes its complete log just
     * before it exits - then validates the document. Idempotent. Throws on timeout or on a missing / malformed log.
     *
     * @return list<array{code:int,id:int,auth:string,valid:bool,attributes:list<array{0:string,1:mixed}>}>
     */
    public function received(float $timeout = 25.0): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if ($this->proc === null) {
            throw new \RuntimeException('stub NAS is not running');
        }
        $deadline = microtime(true) + $timeout;
        while (($running = proc_get_status($this->proc)['running']) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if ($running) {
            $this->release();
            throw new \RuntimeException(sprintf('stub NAS (port %d) did not finish within %.1f s', $this->port, $timeout));
        }
        $raw = @file_get_contents($this->log);
        $this->release();
        return $this->data = self::parseLog($raw === false ? null : $raw);
    }

    /**
     * Validates a stub log: a complete JSON list of request records. An error document, a partial or empty file and
     * a wrong structure are rejected.
     *
     * @return list<array{code:int,id:int,auth:string,valid:bool,attributes:list<array{0:string,1:mixed}>}>
     */
    public static function parseLog(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            throw new \RuntimeException('stub NAS log missing or empty');
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            throw new \RuntimeException('stub NAS log is not complete JSON: ' . json_last_error_msg());
        }
        if (isset($doc['error'])) {
            throw new \RuntimeException('stub NAS reported an error: ' . json_encode($doc));
        }
        if (!array_is_list($doc)) {
            throw new \RuntimeException('stub NAS log is not a list of requests');
        }
        foreach ($doc as $i => $r) {
            if (!is_array($r) || !is_int($r['code'] ?? null) || !is_int($r['id'] ?? null) || !is_string($r['auth'] ?? null)
                || !is_bool($r['valid'] ?? null) || !is_array($r['attributes'] ?? null)) {
                throw new \RuntimeException("stub NAS log record {$i} is malformed");
            }
        }
        return $doc;
    }

    /** terminates (only) this instance's own unfinished child, reaps it and removes its files; idempotent */
    private function release(): void
    {
        if ($this->proc !== null) {
            if (proc_get_status($this->proc)['running']) {
                proc_terminate($this->proc, 9);
            }
            proc_close($this->proc);
            $this->proc = null;
        }
        if ($this->log !== '') {
            foreach (array_merge([$this->log, $this->log . '.ready'], glob($this->log . '.tmp.*') ?: []) as $f) {
                @unlink($f);
            }
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
