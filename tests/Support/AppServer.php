<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A disposable copy of the application served by `php -S` against a disposable database.
 *
 * Test-only additions exist ONLY in the copy (never in the repository):
 *  - config.php with a dedicated $api_secret (optional) and display_errors deliberately ON, to prove
 *    that the application forces it off (OD-14)
 *  - system/plugin/wp2_probe.php: records Telegram/SMS hook payloads instead of any network call and
 *    registers probe plugin menu functions (OD-9) and failure endpoints (OD-14)
 *  - system/devices/Probe.php: a device that records every call (no network)
 * Outbound calls are therefore impossible: no Telegram/SMS/WhatsApp configuration, Probe device only.
 *
 * Server process lifecycle (WP8 AppServer repair): `php -S` runs with PHP_CLI_SERVER_WORKERS=4, i.e. a master and
 * four forked workers that all hold the listening socket. Signalling only the master left the workers listening on
 * the port after stop(); a later instance that drew the same port then talked to that orphan (HTTP 404 from a deleted
 * docroot). The server is therefore started through setsid(1) in its OWN session / process group (pgid = sid = the
 * child pid), stopped by signalling that group only (never a process outside it, never by name), and accepted only
 * when its master and all 4 workers exist and it serves a per-start random sentinel file from THIS copy. A port that
 * is busy is never reused.
 */
final class AppServer
{
    public readonly string $dir;
    private int $port = 0;
    /** @var resource|null */
    private $proc = null;
    /** the server's process group = session id = the pid of the setsid child (0 when not running) */
    private int $pgid = 0;
    private string $sentinel = '';

    private const WORKERS = 4;
    private const SETSID = '/usr/bin/setsid';
    private const SIGKILL = 9;
    private const SIGTERM = 15;

    private const PROBE_PLUGIN = <<<'PHP'
<?php
// test-only probe plugin (disposable app copy only)
function wp2probe_log($file, $value) { @file_put_contents(__DIR__ . '/../../wp2_probe/' . $file . '.log', json_encode($value) . "\n", FILE_APPEND | LOCK_EX); }
register_hook('send_telegram', 'wp2probe_telegram');
function wp2probe_telegram($args) { wp2probe_log('telegram', $args[0] ?? ''); }
register_hook('send_sms', 'wp2probe_sms');
function wp2probe_sms($args) { wp2probe_log('sms', $args); }
register_hook('send_whatsapp', 'wp2probe_whatsapp');
function wp2probe_whatsapp($args) { wp2probe_log('sms', $args); }
register_menu('WP2 Probe Admin', true, 'wp2probe_admin_page', 'AFTER_DASHBOARD', '', '', 'success', ['SuperAdmin', 'Admin']);
function wp2probe_admin_page() { wp2probe_log('plugin', 'admin_page'); echo 'WP2PROBE_ADMIN_PAGE'; }
register_menu('WP2 Probe Default', true, 'wp2probe_default_page', 'AFTER_DASHBOARD');
function wp2probe_default_page() { wp2probe_log('plugin', 'default_page'); echo 'WP2PROBE_DEFAULT_PAGE'; }
register_menu('WP2 Probe Customer', false, 'wp2probe_customer_page', 'AFTER_DASHBOARD');
function wp2probe_customer_page() { wp2probe_log('plugin', 'customer_page'); echo 'WP2PROBE_CUSTOMER_PAGE'; }
function wp2probe_unregistered() { wp2probe_log('plugin', 'unregistered'); echo 'WP2PROBE_UNREGISTERED'; }
register_menu('WP2 Fail PDO', true, 'wp2probe_fail_pdo', 'AFTER_DASHBOARD', '', '', 'success', ['SuperAdmin', 'Admin', 'Agent', 'Sales', 'Report']);
function wp2probe_fail_pdo() { global $db_pass; throw new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'secretdb.tbl_x' doesn't exist in /home/app/system/x.php:12 password=" . $db_pass); }
register_menu('WP2 Fail Type', true, 'wp2probe_fail_type', 'AFTER_DASHBOARD', '', '', 'success', ['SuperAdmin', 'Admin', 'Agent', 'Sales', 'Report']);
function wp2probe_fail_type() { strlen([]); }
register_menu('WP2 Fail Customer', false, 'wp2probe_fail_customer', 'AFTER_DASHBOARD');
function wp2probe_fail_customer() { global $db_pass; throw new RuntimeException('customer failure at /home/app/init.php:99 ' . $db_pass); }
register_hook('view_customer', 'wp2probe_hook_throw');
register_hook('save_pages', 'wp2probe_save_pages');
function wp2probe_save_pages($args = null) { wp2probe_log('pages', 'save_pages'); }
register_hook('view_edit_pages', 'wp2probe_view_pages');
function wp2probe_view_pages($args = null) { wp2probe_log('pages', 'view_edit_pages'); }
function wp2probe_hook_throw() { global $db_pass; if (is_file(__DIR__ . '/../../wp2_probe/throw_in_hook.flag')) { throw new PDOException("SQLSTATE[HY000] hook failure /home/app/x.php " . $db_pass); } }
PHP;

    private const PROBE_DEVICE = <<<'PHP'
<?php
// test-only device (disposable app copy only): records every call, never touches the network
class Probe
{
    public function description() { return ['title' => 'Probe', 'description' => 'test probe', 'author' => 'test', 'url' => []]; }
    public function __call($name, $args)
    {
        $who = '';
        if (isset($args[0]) && (is_array($args[0]) || $args[0] instanceof ArrayAccess)) { $who = (string) ($args[0]['username'] ?? ''); }
        @file_put_contents(__DIR__ . '/../../wp2_probe/device.log', json_encode(['call' => $name, 'username' => $who]) . "\n", FILE_APPEND | LOCK_EX);
        return $name === 'online_customer' ? false : true;
    }
}
PHP;

    private const EXEC_HEAD = <<<'PHP'
<?php
// test-only exec harness (disposable app copy only)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/init.php';
$result = null;
PHP;

    private function __construct(public readonly TestDatabase $db, string $dir)
    {
        $this->dir = $dir;
    }

    public function __destruct()
    {
        try {
            $this->stop();
        } catch (\Throwable) {
            // best effort only; stop() reports a surviving server when called explicitly
        }
    }

    /** @param array{api_secret?:string|null,app_secret?:string|null,extra_config?:string} $opts */
    public static function create(TestDatabase $db, array $opts = []): self
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/wp2_app_' . bin2hex(random_bytes(5));
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($root) + 1);
            if (preg_match('#^(\.git|tests|dev|docs|owner-review|ui/compiled)(/|$)#', $rel)) {
                continue;
            }
            $dest = $dir . '/' . $rel;
            if ($item->isDir()) {
                @mkdir($dest, 0777, true);
            } else {
                @mkdir(dirname($dest), 0777, true);
                copy($item->getPathname(), $dest);
            }
        }
        @mkdir($dir . '/ui/compiled', 0777, true);
        // an installed application has its editable pages (pages/ is gitignored and created at install)
        @mkdir($dir . '/pages', 0777, true);
        foreach (glob($root . '/pages_template/*.html') as $tpl) {
            copy($tpl, $dir . '/pages/' . basename($tpl));
        }
        @mkdir($dir . '/wp2_probe', 0777, true);
        @mkdir($dir . '/wp2_sessions', 0777, true);
        file_put_contents($dir . '/system/plugin/wp2_probe.php', self::PROBE_PLUGIN);
        file_put_contents($dir . '/system/devices/Probe.php', self::PROBE_DEVICE);
        // an unmapped controller (must be denied for every role by RouteGuard)
        file_put_contents($dir . '/system/controllers/wp2probe.php', "<?php\n@file_put_contents(__DIR__ . '/../../wp2_probe/controller.log', \"ran\\n\", FILE_APPEND);\necho 'WP2PROBE_CONTROLLER';\n");
        $app = new self($db, $dir);
        $app->writeConfig(array_key_exists('api_secret', $opts) ? $opts['api_secret'] : bin2hex(random_bytes(32)), $opts['extra_config'] ?? '', array_key_exists('app_secret', $opts) ? $opts['app_secret'] : false);
        return $app;
    }

    /** WP9-D / NEW-7: the dedicated application key of this app copy (stable across config rewrites) */
    private ?string $appSecret = null;
    private bool $appSecretSet = false;

    public function writeConfig(?string $apiSecret, string $extra = '', string|false|null $appSecret = false): void
    {
        if ($appSecret !== false) {
            $this->appSecret = $appSecret;
            $this->appSecretSet = true;
        } elseif (!$this->appSecretSet) {
            $this->appSecret = bin2hex(random_bytes(32));
            $this->appSecretSet = true;
        }
        $cfg = "<?php\n\$protocol = 'http://';\n\$host = \$_SERVER['HTTP_HOST'] ?? '127.0.0.1';\ndefine('APP_URL', \$protocol . \$host);\n"
            . "\$_app_stage = 'Live';\n\$db_host = " . var_export($this->db->host, true) . ";\n\$db_user = 'root';\n"
            . "\$db_pass = " . var_export(TestDatabase::rootPassword(), true) . ";\n\$db_name = " . var_export($this->db->name, true) . ";\n"
            . ($apiSecret !== null ? "\$api_secret = " . var_export($apiSecret, true) . ";\n" : '')
            . ($this->appSecret !== null ? "\$app_secret = " . var_export($this->appSecret, true) . ";\n" : '')
            . "error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);\nini_set('display_errors', 1);\n" . $extra;
        file_put_contents($this->dir . '/config.php', $cfg);
    }

    public function appSecret(): ?string
    {
        return $this->appSecret;
    }

    public function apiSecret(): ?string
    {
        $cfg = (string) file_get_contents($this->dir . '/config.php');
        return preg_match("/\\\$api_secret = '([^']*)'/", $cfg, $m) ? $m[1] : null;
    }

    /**
     * Starts the server on a free port (the $candidatePorts first, then random ports 20000-29999) and returns only when
     * THIS copy answers its sentinel. Idempotent. On any failure (busy port, early exit, timeout, wrong answer) the
     * launched process group is stopped before the next attempt; after the last attempt it throws.
     *
     * @param list<int> $candidatePorts
     */
    public function start(array $candidatePorts = [], float $timeout = 10.0): void
    {
        if ($this->proc !== null) {
            return;
        }
        if (!function_exists('posix_kill') || !is_executable(self::SETSID) || !is_dir('/proc/self')) {
            throw new \RuntimeException('app server needs ext-posix, ' . self::SETSID . ' and /proc');
        }
        $tried = [];
        for ($i = 0; $i < max(8, count($candidatePorts)); $i++) {
            $port = $candidatePorts[$i] ?? 20000 + random_int(0, 9999);
            if (!self::portFree($port)) {
                $tried[] = $port . ':busy';
                continue;
            }
            $why = $this->launch($port, $timeout);
            if ($why === null) {
                return;
            }
            $tried[] = $port . ':' . $why;
        }
        throw new \RuntimeException('app server did not start (' . implode('; ', $tried) . ')');
    }

    /** @return string|null null = started and verified, otherwise the reason (the attempt is already cleaned up) */
    private function launch(int $port, float $timeout): ?string
    {
        $token = bin2hex(random_bytes(16));
        $this->sentinel = 'wp2_probe/server-' . $token . '.txt';
        file_put_contents($this->dir . '/' . $this->sentinel, $token);
        $cmd = [self::SETSID, PHP_BINARY, '-d', 'session.save_path=' . $this->dir . '/wp2_sessions', '-d', 'error_log=' . $this->dir . '/wp2_probe/php_error.log',
            '-d', 'log_errors=1', '-S', '127.0.0.1:' . $port, '-t', $this->dir];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->dir . '/wp2_probe/server.log', 'a']], $pipes, $this->dir, ['PHP_CLI_SERVER_WORKERS' => (string) self::WORKERS] + getenv());
        if (!is_resource($proc)) {
            @unlink($this->dir . '/' . $this->sentinel);
            return 'proc_open failed';
        }
        $this->proc = $proc;
        $this->pgid = (int) proc_get_status($proc)['pid'];
        $this->port = $port;
        $deadline = microtime(true) + $timeout;
        $why = 'timeout';
        while (true) {
            $st = self::procStat($this->pgid);
            if ($st === null || $st['state'] === 'Z') {
                $why = 'exited (' . trim(substr((string) @file_get_contents($this->dir . '/wp2_probe/server.log'), -200)) . ')';
                break;
            }
            // ready only once setsid() made the child the leader of its own group, the master and ALL its workers exist,
            // and the port answers with THIS start's token
            if ($st['pgrp'] === $this->pgid && $st['sid'] === $this->pgid && count(self::members($this->pgid)) === 1 + self::WORKERS
                && $this->answersSentinel($token)) {
                return null;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(50000);
        }
        $this->stop();
        return $why;
    }

    /**
     * Stops the master and every worker: SIGTERM to the server's own process group, SIGKILL after 3 s. Signals go
     * only to processes in the session the setsid child created (checked in /proc); before setsid() took effect only
     * the direct child is signalled. Idempotent. Throws if a group member survives or the server still answers.
     */
    public function stop(): void
    {
        if ($this->proc === null) {
            return;
        }
        $pgid = $this->pgid;
        if (self::members($pgid) !== []) {
            posix_kill(-$pgid, self::SIGTERM);
            if (!self::waitGone($pgid, 3.0)) {
                posix_kill(-$pgid, self::SIGKILL);
                self::waitGone($pgid, 2.0);
            }
        } else {
            $st = proc_get_status($this->proc);
            if ($st['running']) {
                posix_kill((int) $st['pid'], self::SIGKILL);
            }
        }
        // a child that ran setsid() and forked between the checks above: its group is ours (its pid is not reaped yet)
        if (self::members($pgid) !== []) {
            posix_kill(-$pgid, self::SIGKILL);
            self::waitGone($pgid, 2.0);
        }
        proc_close($this->proc);
        $this->proc = null;
        $left = self::members($pgid);
        $answers = $this->sentinel !== '' && $this->answersSentinel((string) @file_get_contents($this->dir . '/' . $this->sentinel));
        @unlink($this->dir . '/' . $this->sentinel);
        $this->sentinel = '';
        $this->pgid = 0;
        if ($left !== [] || $answers) {
            throw new \RuntimeException('app server not fully stopped: ' . count($left) . ' process(es) of group ' . $pgid . ' left'
                . ($answers ? ', port ' . $this->port . ' still answers' : ''));
        }
    }

    public function destroy(): void
    {
        try {
            $this->stop();
        } finally {
            if (is_dir($this->dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($this->dir);
            }
        }
    }

    public function port(): int
    {
        return $this->port;
    }

    /** the server's process group id (= master pid), 0 when not running */
    public function processGroup(): int
    {
        return $this->proc !== null ? $this->pgid : 0;
    }

    /** @return array<int,int> live (non-zombie) processes of the server's own group: pid => parent pid */
    public function processes(): array
    {
        return $this->proc !== null ? array_map(static fn(array $s): int => $s['ppid'], self::members($this->pgid)) : [];
    }

    private function answersSentinel(string $token): bool
    {
        if ($token === '' || $this->port === 0) {
            return false;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true, 'header' => "Connection: close\r\n"]]);
        return @file_get_contents('http://127.0.0.1:' . $this->port . '/' . $this->sentinel, false, $ctx) === $token;
    }

    /** nothing accepts on the port and it can be bound (no other listener, ours or foreign) */
    private static function portFree(int $port): bool
    {
        $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($c !== false) {
            fclose($c);
            return false;
        }
        $s = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if ($s === false) {
            return false;
        }
        fclose($s);
        return true;
    }

    /** @return array{state:string,ppid:int,pgrp:int,sid:int}|null */
    private static function procStat(int $pid): ?array
    {
        $raw = @file_get_contents('/proc/' . $pid . '/stat');
        if ($raw === false || ($p = strrpos($raw, ')')) === false) {
            return null;
        }
        $f = explode(' ', substr($raw, $p + 2));
        return ['state' => $f[0], 'ppid' => (int) $f[1], 'pgrp' => (int) $f[2], 'sid' => (int) $f[3]];
    }

    /** @return array<int,array{state:string,ppid:int,pgrp:int,sid:int}> live processes whose group AND session are $pgid */
    private static function members(int $pgid): array
    {
        $out = [];
        if ($pgid <= 1) {
            return $out;
        }
        foreach (glob('/proc/[0-9]*', GLOB_NOSORT) ?: [] as $d) {
            $st = self::procStat((int) basename($d));
            if ($st !== null && $st['pgrp'] === $pgid && $st['sid'] === $pgid && $st['state'] !== 'Z') {
                $out[(int) basename($d)] = $st;
            }
        }
        return $out;
    }

    private static function waitGone(int $pgid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (self::members($pgid) === []) {
                return true;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        return self::members($pgid) === [];
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function client(): HttpClient
    {
        return new HttpClient($this->baseUrl());
    }

    /**
     * Runs a PHP snippet inside the application context (CLI). The snippet sets $result; it is
     * returned JSON-decoded.
     */
    public function exec(string $code, array $env = []): mixed
    {
        $file = $this->dir . '/wp2_exec_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, self::EXEC_HEAD . "\n" . $code . "\necho \"\\n@@WP2JSON@@\" . json_encode(\$result);\n");
        try {
            $r = RunnerProcess::exec([PHP_BINARY, '-d', 'error_log=' . $this->dir . '/wp2_probe/php_error.log', $file], $env);
        } finally {
            @unlink($file);
        }
        $pos = strrpos($r['raw'], '@@WP2JSON@@');
        if ($pos === false) {
            throw new \RuntimeException('exec failed: ' . $r['raw']);
        }
        return json_decode(trim(substr($r['raw'], $pos + 11)), true);
    }

    /** Starts exec() in a separate process without waiting (concurrency tests). */
    public function execStart(string $code): array
    {
        $file = $this->dir . '/wp2_exec_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, self::EXEC_HEAD . "\n" . $code . "\necho \"\\n@@WP2JSON@@\" . json_encode(\$result);\n");
        // as exec(): PHP diagnostics go to the probe log, never into the JSON result (WP5B concurrency tests)
        $proc = proc_open([PHP_BINARY, '-d', 'error_log=' . $this->dir . '/wp2_probe/php_error.log', '-d', 'display_errors=0', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return ['proc' => $proc, 'pipes' => $pipes, 'file' => $file];
    }

    public function execFinish(array $h): mixed
    {
        $out = stream_get_contents($h['pipes'][1]) . stream_get_contents($h['pipes'][2]);
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);
        @unlink($h['file']);
        $pos = strrpos($out, '@@WP2JSON@@');
        if ($pos === false) {
            throw new \RuntimeException('exec failed: ' . $out);
        }
        return json_decode(trim(substr($out, $pos + 11)), true);
    }

    /** Runs a script of the copy (e.g. system/cron.php) with the given cwd; returns [exit, output]. */
    public function run(array $args, string $cwd): array
    {
        $proc = proc_open(array_merge([PHP_BINARY, '-d', 'error_log=' . $this->dir . '/wp2_probe/php_error.log'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir . '/' . $cwd);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    /** @return list<mixed> decoded probe records */
    public function probe(string $name): array
    {
        $f = $this->dir . '/wp2_probe/' . $name . '.log';
        if (!is_file($f)) {
            return [];
        }
        $out = [];
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $out[] = json_decode($l, true);
        }
        return $out;
    }

    public function serverLog(): string
    {
        return (string) @file_get_contents($this->dir . '/wp2_probe/php_error.log');
    }

    public function clearProbes(): void
    {
        foreach (glob($this->dir . '/wp2_probe/*.log') as $f) {
            if (basename($f) !== 'server.log') {
                @unlink($f);
            }
        }
    }

    /** Hash of the copy's file tree (to prove "no filesystem change"). */
    public function treeHash(string $sub = ''): string
    {
        return hash('sha256', json_encode($this->treeFiles($sub)));
    }

    /** @return array<string,string> relative path => sha256 */
    public function treeFiles(string $sub = ''): array
    {
        $base = rtrim($this->dir . '/' . $sub, '/');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($this->dir) + 1);
            if (preg_match('#^(wp2_probe|wp2_sessions|ui/compiled|system/cache)/#', $rel)) {
                continue;
            }
            // Lang::T() appends newly seen strings to the active language file (legacy behaviour):
            // only the existence of language files is compared, not their content
            $files[$rel] = str_starts_with($rel, 'system/lan/') ? 'present' : hash_file('sha256', $f->getPathname());
        }
        ksort($files);
        return $files;
    }
}
