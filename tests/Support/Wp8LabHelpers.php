<?php
namespace Reseller\Tests\Support;

// CLI-only test support: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/Wp8Lab.php';

use Reseller\Radius\AccountingService;
use Reseller\Radius\CoAClient;
use Reseller\Radius\RadiusPolicyBuilder;
use Reseller\Radius\RadiusProvisioner;
use Reseller\Radius\RadiusRepository;

/**
 * Shared WP8 real-NAS test helpers (lab runner only): the lab RADIUS database, policy provisioning for nuxr_wp8_*
 * identities, accounting reads, FreeRADIUS reply evidence and client cleanup. Development/test only.
 */
trait Wp8LabHelpers
{
    protected static ?\PDO $radius = null;

    protected static function labRadius(): ?\PDO
    {
        return Wp8Lab::enabled() ? (self::$radius ??= Wp8Lab::radiusPdo()) : null;
    }

    protected function requireLab(): void
    {
        if (static::labRadius() === null) {
            $this->markTestIncomplete('WP8 real-NAS lab not running (dev/wp8/lab.sh up; dev/wp8/lab.sh test)');
        }
    }

    protected function releaseClients(): void
    {
        if (static::labRadius() === null) {
            return;
        }
        foreach (['c1', 'c3'] as $c) {
            Wp8Lab::agent('pppoe_down', ['client' => $c]);
        }
        foreach (['c2', 'c4', 'c5'] as $c) {
            Wp8Lab::agent('hs_logout', ['client' => $c]);
        }
    }

    protected static function ident(string $what): string
    {
        return 'nuxr_wp8_' . $what . '_' . bin2hex(random_bytes(3));
    }

    /** @param array<string,mixed> $plan */
    protected function provisionPolicy(array $customer, array $plan, ?array $bw, \DateTimeImmutable $expiry, ?\DateTimeImmutable $period = null): string
    {
        $plan += ['id' => random_int(100000, 999999), 'typebp' => 'Unlimited', 'shared_users' => 1, 'pool' => ''];
        $policy = RadiusPolicyBuilder::build($customer, $plan, $bw, $expiry, $period);
        $r = (new RadiusProvisioner(new RadiusRepository(static::labRadius())))->provision($policy);
        $this->assertSame('SUCCESS', $r->status, $r->code);
        return $policy->username;
    }

    /** @return list<array<string,mixed>> radacct rows of $user, oldest first */
    protected function acct(string $user): array
    {
        $st = static::labRadius()->prepare('SELECT radacctid, acctsessionid, acctuniqueid, username, nasipaddress, nasportid, nasporttype, framedipaddress, callingstationid, acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, acctterminatecause FROM radacct WHERE username = ? ORDER BY radacctid');
        $st->execute([$user]);
        return $st->fetchAll();
    }

    protected function coa(): CoAClient
    {
        return new CoAClient(new AccountingService(static::labRadius()), new RadiusRepository(static::labRadius()));
    }

    /**
     * Owner decision HOLD-C (2026-10-28): the A2-R reservation of $user - state, token, confirmed_at and its age in ms on
     * the DATABASE clock, the clock and the event (first Accounting-Start / Interim of the session) the fresh-session hold
     * itself uses - or null when the subscriber has none.
     *
     * @return array{state:string,token:string,confirmed_at:?string,age_ms:?int}|null
     */
    protected static function admissionReservation(string $user): ?array
    {
        $st = static::labRadius()->prepare("SELECT state, token, confirmed_at, TIMESTAMPDIFF(MICROSECOND, confirmed_at, UTC_TIMESTAMP(6)) DIV 1000 AS age_ms FROM nuxr_admission WHERE username = (SELECT username FROM radcheck WHERE username = ? ORDER BY CAST(username AS BINARY) LIMIT 1)");
        $st->execute([$user]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $r['age_ms'] = $r['age_ms'] === null ? null : (int) $r['age_ms'];
        return $r;
    }

    /** HOLD-C: waits (database clock) until $user's reservation is at least $ms old; returns the age reached */
    protected static function waitReservationAge(string $user, int $ms): int
    {
        $age = (int) (static::admissionReservation($user)['age_ms'] ?? $ms);
        if ($age < $ms) {
            usleep(($ms - $age) * 1000);
            $age = (int) (static::admissionReservation($user)['age_ms'] ?? $ms);
        }
        return $age;
    }

    /** TD-1b: the guard readiness check as the FreeRADIUS user; per-NAS status lines and the summary */
    protected static function guardSelfcheck(array $nas = []): array
    {
        $r = Wp8Lab::agent('guard_selfcheck', ['nas' => $nas], 120);
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $r['out']))));
        $per = [];
        foreach ($lines as $l) {
            if (preg_match('/^nas=(\S+) status=(\S+)/', $l, $m)) {
                $per[$m[1]] = $m[2];
            }
        }
        return ['rc' => $r['rc'], 'per_nas' => $per, 'summary' => end($lines) ?: '', 'lines' => $lines];
    }

    /**
     * WP8 A2: every Access-Request of $user in log order, request-local and restart-safe (the packet type is tracked in
     * log order; a request number reused by a later request ends the previous one). Per request: RADIUS Id and source
     * port (to join the pcap), the post-auth open-row lookup (Tmp-String-8 "n=..;NAS"), whether the PPPoE session guard
     * ran and its verdict, whether the STOCK Simultaneous-Use layer refused it, and the reply sent.
     *
     * @return list<array<string,mixed>>
     */
    protected static function accessDecisions(string $user): array
    {
        $lines = Wp8Lab::frLog('^\(\d+\) ', 5000);
        $out = [];
        $open = [];          // request number => index in $out of the Access-Request being read
        $kind = [];
        $hdr = [];
        foreach ($lines as $l) {
            if (!preg_match('/^\((\d+)\) (.*)$/', $l, $m)) {
                continue;
            }
            [$n, $rest] = [$m[1], $m[2]];
            if (preg_match('/^Received (Access|Accounting)-Request Id (\d+) from [\d.]+:(\d+)/', $rest, $r)) {
                unset($open[$n]);
                $kind[$n] = $r[1];
                $hdr[$n] = ['id' => (int) $r[2], 'port' => (int) $r[3]];
                continue;
            }
            if (($kind[$n] ?? '') === 'Access' && !isset($open[$n]) && preg_match('/^\s+User-Name = "(.*)"$/', $rest, $u)) {
                if (stripcslashes($u[1]) === $user || $u[1] === $user) {
                    $out[] = ['request' => (int) $n, 'radius_id' => $hdr[$n]['id'], 'src_port' => $hdr[$n]['port'], 'open_rows' => null,
                        'guard_ran' => false, 'guard_verdict' => null, 'guard_detail' => null, 'stock_session_reject' => false, 'reply' => null, 'reply_message' => null];
                    $open[$n] = count($out) - 1;
                }
                continue;
            }
            if (!isset($open[$n])) {
                continue;
            }
            $d = &$out[$open[$n]];
            if (preg_match('/Tmp-String-8 := "?([^"]*?)"?$/', $rest, $r)) {
                $d['open_rows'] = $r[1];
            }
            if (str_contains($rest, 'Program returned code') || str_contains($rest, 'Program executed') || str_contains($rest, 'nuxr_pppoe_session_guard (')) {
                $d['guard_ran'] = true;
            }
            if (preg_match('/Tmp-String-7 = "(\w+)"/', $rest, $r)) {
                $d['guard_verdict'] = $r[1];
            }
            if (preg_match('/Tmp-String-6 = "([^"]*)"/', $rest, $r)) {
                $d['guard_detail'] = $r[1];
            }
            if (preg_match('/Multiple logins|already logged in/i', $rest)) {
                $d['stock_session_reject'] = true;
            } elseif (preg_match('/^Sent (Access-\w+) Id/', $rest, $r)) {
                $d['reply'] = $r[1];
            } elseif ($d['reply'] !== null && preg_match('/^\s+Reply-Message = "(.*)"$/', $rest, $r)) {
                $d['reply_message'] = $r[1];
            }
            unset($d);
        }
        return $out;
    }

    /**
     * The reply attributes FreeRADIUS sent in its LAST Access-Accept / Access-Reject for $user (debug log, redacted).
     * @return array{code:?string, attrs:array<string,string>}
     */
    protected static function lastReply(string $user): array
    {
        // Request-local and restart-safe (WP8 A1): the log may hold several FreeRADIUS runs (request numbers restart at
        // 0) and an accounting request may reuse a number seen earlier as an Access-Request, so the packet type is
        // tracked in log order and the reply is taken only AFTER the matching request. The permanent PPPoE session guard
        // logs many post-auth lines per PPPoE request, hence the larger window.
        $lines = Wp8Lab::frLog('^\(\d+\) ', 4000);
        $kind = [];
        $req = null;
        $at = -1;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\((\d+)\) Received (Access|Accounting)-Request /', $l, $m)) {
                $kind[$m[1]] = $m[2];
            } elseif (preg_match('/^\((\d+)\)\s+User-Name = "' . preg_quote($user, '/') . '"$/', $l, $m) && ($kind[$m[1]] ?? '') === 'Access') {
                $req = $m[1];
                $at = $i;
            }
        }
        $code = null;
        $attrs = [];
        if ($req !== null) {
            $in = false;
            foreach (array_slice($lines, $at + 1) as $l) {
                if (preg_match('/^\(' . $req . '\) Received /', $l)) {
                    break;                       // the number was reused by a newer request
                }
                if ($code === null && preg_match('/^\(' . $req . '\) Sent (Access-\w+) Id/', $l, $m)) {
                    $code = $m[1];
                    $in = true;
                    continue;
                }
                if ($in && preg_match('/^\(' . $req . '\)\s+([A-Za-z][\w-]+) :?= (.*)$/', $l, $m)) {
                    $attrs[$m[1]] = trim($m[2], '"');
                } elseif ($in && str_starts_with($l, '(' . $req . ')')) {
                    break;
                }
            }
        }
        return ['code' => $code, 'attrs' => $attrs];
    }

    /** proves no local RouterOS subscriber can mask RADIUS */
    protected function assertNoLocalSubscribers(): void
    {
        $this->assertSame([], Wp8Lab::ros('/ppp/secret/print'), 'no local PPP secret');
        $this->assertSame(['default-trial'], array_column(Wp8Lab::ros('/ip/hotspot/user/print'), 'name'), 'only the fixed, inert trial entry');
    }
}
