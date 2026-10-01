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
 */
final class AppServer
{
    public readonly string $dir;
    public readonly int $port;
    /** @var resource|null */
    private $proc = null;

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

    private function __construct(public readonly TestDatabase $db, string $dir, int $port)
    {
        $this->dir = $dir;
        $this->port = $port;
    }

    /** @param array{api_secret?:string|null,extra_config?:string} $opts */
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
        $port = 20000 + random_int(0, 9999);
        $app = new self($db, $dir, $port);
        $app->writeConfig(array_key_exists('api_secret', $opts) ? $opts['api_secret'] : bin2hex(random_bytes(32)), $opts['extra_config'] ?? '');
        return $app;
    }

    public function writeConfig(?string $apiSecret, string $extra = ''): void
    {
        $cfg = "<?php\n\$protocol = 'http://';\n\$host = \$_SERVER['HTTP_HOST'] ?? '127.0.0.1';\ndefine('APP_URL', \$protocol . \$host);\n"
            . "\$_app_stage = 'Live';\n\$db_host = " . var_export($this->db->host, true) . ";\n\$db_user = 'root';\n"
            . "\$db_pass = " . var_export(TestDatabase::rootPassword(), true) . ";\n\$db_name = " . var_export($this->db->name, true) . ";\n"
            . ($apiSecret !== null ? "\$api_secret = " . var_export($apiSecret, true) . ";\n" : '')
            . "error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);\nini_set('display_errors', 1);\n" . $extra;
        file_put_contents($this->dir . '/config.php', $cfg);
    }

    public function apiSecret(): ?string
    {
        $cfg = (string) file_get_contents($this->dir . '/config.php');
        return preg_match("/\\\$api_secret = '([^']*)'/", $cfg, $m) ? $m[1] : null;
    }

    public function start(): void
    {
        if ($this->proc !== null) {
            return;
        }
        $cmd = [PHP_BINARY, '-d', 'session.save_path=' . $this->dir . '/wp2_sessions', '-d', 'error_log=' . $this->dir . '/wp2_probe/php_error.log',
            '-d', 'log_errors=1', '-S', '127.0.0.1:' . $this->port, '-t', $this->dir];
        $this->proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->dir . '/wp2_probe/server.log', 'a']], $pipes, $this->dir, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $this->port);
            if ($s !== false) {
                fclose($s);
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('app server did not start');
    }

    public function stop(): void
    {
        if ($this->proc !== null) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    public function destroy(): void
    {
        $this->stop();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
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
        $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
