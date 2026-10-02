<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** A loopback test NAS in its own process (stub_nas.php): answers one Disconnect / CoA request per mode. */
final class StubNas
{
    public readonly int $port;
    private string $log;
    /** @var resource */
    private $proc;

    public function __construct(public readonly string $secret, string $mode)
    {
        $this->port = 30000 + random_int(0, 9999);
        $this->log = sys_get_temp_dir() . '/wp4_nas_' . bin2hex(random_bytes(5)) . '.json';
        $this->proc = proc_open([PHP_BINARY, __DIR__ . '/stub_nas.php', (string) $this->port, $secret, $mode, $this->log],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $deadline = microtime(true) + 10;
        while (!is_file($this->log . '.ready') && microtime(true) < $deadline) {
            usleep(10000);
        }
    }

    /** @return list<array{code:int,valid:bool,attributes:list<array{0:string,1:mixed}>}> what the NAS received */
    public function received(): array
    {
        $deadline = microtime(true) + 25;
        while (!is_file($this->log) && microtime(true) < $deadline) {
            usleep(10000);
        }
        proc_terminate($this->proc);
        proc_close($this->proc);
        $data = is_file($this->log) ? json_decode((string) file_get_contents($this->log), true) : null;
        @unlink($this->log);
        @unlink($this->log . '.ready');
        return is_array($data) ? $data : [];
    }
}
