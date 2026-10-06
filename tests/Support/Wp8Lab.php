<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * WP8 real-NAS lab access for tests running in the php82-wp8 runner (dev/wp8/lab.sh test). The runner shares the
 * network namespace of the lab FreeRADIUS (10.88.0.10), so application CoA leaves from the configured RouterOS /radius
 * server address. Independent PPPoE / Hotspot clients, the CHR management path, FreeRADIUS pause/log and captures are
 * driven through the host-side lab client agent (dev/wp8/client_agent.py) via /wp8ctl. Development/test only.
 */
final class Wp8Lab
{
    public const CHR = '10.88.0.2';
    public const APP = '10.88.0.10';

    public static function enabled(): bool
    {
        return getenv('NUXR_WP8_LAB') === '1' && is_dir('/wp8ctl/req');
    }

    /** @return array<string,mixed> the operation result; throws when the agent reports an error */
    public static function agent(string $op, array $args = [], int $timeout = 120): array
    {
        $id = sprintf('%.6f-%s', microtime(true), bin2hex(random_bytes(4)));
        $tmp = "/wp8ctl/req/.{$id}.tmp";
        file_put_contents($tmp, json_encode(['op' => $op, 'args' => (object) $args]));
        chmod($tmp, 0666);
        rename($tmp, "/wp8ctl/req/{$id}.json");
        $res = "/wp8ctl/res/{$id}.json";
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $res);
            if (is_file($res)) {
                $out = json_decode((string) file_get_contents($res), true);
                @unlink($res);
                if (!is_array($out) || ($out['ok_call'] ?? false) !== true) {
                    throw new \RuntimeException("lab agent {$op}: " . ($out['error'] ?? 'invalid response'));
                }
                return $out['result'];
            }
            usleep(150000);
        }
        throw new \RuntimeException("lab agent {$op}: no answer within {$timeout}s");
    }

    /** RouterOS command on the lab CHR through its management path; @return list<array<string,string>> rows */
    public static function ros(string ...$words): array
    {
        $r = self::agent('ros', ['words' => $words]);
        if (!$r['ok']) {
            throw new \RuntimeException('RouterOS ' . $words[0] . ': ' . json_encode($r['trap']));
        }
        return $r['rows'];
    }

    /** RouterOS add returning the new .id */
    public static function rosAdd(string ...$words): string
    {
        $r = self::agent('ros', ['words' => $words]);
        if (!$r['ok']) {
            throw new \RuntimeException('RouterOS ' . $words[0] . ': ' . json_encode($r['trap']));
        }
        return (string) $r['ret'];
    }

    /** @return list<array<string,string>> */
    public static function pppActive(?string $name = null): array
    {
        return self::ros('/ppp/active/print', ...($name === null ? [] : ['?name=' . $name]));
    }

    /** @return list<array<string,string>> */
    public static function hotspotActive(?string $user = null): array
    {
        return self::ros('/ip/hotspot/active/print', ...($user === null ? [] : ['?user=' . $user]));
    }

    /** @return list<array<string,string>> the dynamic simple queues (RADIUS rate limits applied by RouterOS) */
    public static function queue(string $name): array
    {
        return self::ros('/queue/simple/print', '?name=' . $name);
    }

    public static function radiusPdo(): \PDO
    {
        return FixtureBuilder::connect((string) getenv('WP1_DB_HOST'), TestDatabase::rootPassword(), 'nuxr_radius');
    }

    /** waits until $fn returns a truthy value; returns it (or the last falsy value after the timeout) */
    public static function until(callable $fn, float $seconds, float $step = 1.0): mixed
    {
        $deadline = microtime(true) + $seconds;
        do {
            $v = $fn();
            if ($v) {
                return $v;
            }
            usleep((int) ($step * 1e6));
        } while (microtime(true) < $deadline);
        return $v;
    }

    /** FreeRADIUS debug-log lines (redacted by the agent) matching $pattern */
    public static function frLog(string $pattern): array
    {
        return self::agent('fr_log', ['pattern' => $pattern])['lines'];
    }

    /** appends a sanitized evidence record for the WP8 report ($NUXR_WP8_STATE/ctl/evidence.jsonl) */
    public static function evidence(string $case, array $data): void
    {
        $line = json_encode(['t' => gmdate('c'), 'case' => $case] + $data, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents('/wp8ctl/evidence.jsonl', $line, FILE_APPEND | LOCK_EX);
    }
}
