<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * Lab SEC-E2 fresh-session hold on the real RouterOS CHR 7.24.5 (production-contract lab, as ConcurrentFirstLoginGateTest).
 *
 *  1. measure: after a login's Accounting-Start confirmed its reservation, how long until the session guard sees the
 *     session over SNMP (LIVE). This is the window SEC-E2 used; the hold must be well above it.
 *  2. a second login with the same username sent right after the first login's Accounting-Start is never admitted
 *     while the first session lives (gate assertion: at most one live NAS session; on the fix: reject:active-fresh).
 *  3. the A1 lost-Stop recovery still works: a session lost without its Stop blocks a new login only for the hold;
 *     the retry after the hold is admitted.
 * Evidence is recorded before any assertion; clients and firewall rules are always cleaned up.
 */
final class W10FreshSessionHoldGateTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
    private const HOLD_S = 10;
    private array $rules = [];

    protected function setUp(): void
    {
        $this->requireLab();
        $st = Wp8Lab::agent('option_g', ['cmd' => 'state']);
        $fr = implode("\n", $st['freeradius']);
        $nas = explode("\t", (string) ($st['nas'][0] ?? ''));
        if (($nas[2] ?? '') !== 'mikrotik_snmp' || !str_contains($fr, 'persistent_creds=1') || !str_contains($fr, 'post_auth_policy=1')) {
            $this->markTestSkipped('needs the production-contract lab (dev/wp8/option_g_gate.py on shipped)');
        }
    }

    protected function tearDown(): void
    {
        if (static::labRadius() === null) {
            return;
        }
        foreach ($this->rules as $id) {
            Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $id);
        }
        $this->rules = [];
        $this->releaseClients();
    }

    private function user(string $password): string
    {
        return $this->provisionPolicy(['username' => self::ident('hold'), 'password' => $password, 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
    }

    /** the reservation of $user: state, token, age of confirmed_at in ms (database clock) */
    private function reservation(string $user): ?array
    {
        $st = static::labRadius()->prepare("SELECT state, token, TIMESTAMPDIFF(MICROSECOND, confirmed_at, UTC_TIMESTAMP(6)) DIV 1000 AS age_ms FROM nuxr_admission WHERE username = (SELECT username FROM radcheck WHERE username = ? ORDER BY CAST(username AS BINARY) LIMIT 1)");
        $st->execute([$user]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** waits until $user's reservation is ACTIVE under a token other than $old; returns it */
    private function active(string $user, ?string $old = null): array
    {
        $r = Wp8Lab::until(function () use ($user, $old) {
            $r = $this->reservation($user);
            return $r && $r['state'] === 'ACTIVE' && $r['token'] !== $old ? $r : null;
        }, 20, 0.02);
        $this->assertNotNull($r, 'the first login was confirmed by its Accounting-Start');
        return $r;
    }

    private function guard(string $user): array
    {
        $g = Wp8Lab::agent('guard_invoke', ['argv' => [bin2hex($user), '1', '10.88.0.2']]);
        return ['verdict' => preg_match('/Tmp-String-7 = "(\w+)"/', (string) $g['out'], $m) ? $m[1] : null, 'ms' => $g['ms']];
    }

    private function freshRejects(): int
    {
        return count(Wp8Lab::frLog('nuxr-admission=reject:active-fresh', 5000));
    }

    public function testSnmpVisibilityLagAfterAccountingStart(): void
    {
        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $u = $this->user('W10-hold-1');
            $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u, 'password' => 'W10-hold-1'])['ok']);
            $r = $this->active($u);
            $polls = [];
            $t0 = microtime(true);
            $seen = null;
            while (microtime(true) - $t0 < 15) {
                $g = $this->guard($u);
                $polls[] = [round((microtime(true) - $t0) * 1000), $g['verdict']];
                if ($g['verdict'] === 'LIVE') {
                    $seen = (int) $r['age_ms'] + (int) round((microtime(true) - $t0) * 1000);
                    break;
                }
            }
            $out[] = ['age_at_first_poll_ms' => (int) $r['age_ms'], 'live_after_start_ms_at_most' => $seen, 'polls' => array_slice($polls, 0, 40)];
            Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
            Wp8Lab::until(fn() => Wp8Lab::pppActive($u) === [], 20, 0.5);
        }
        Wp8Lab::evidence('W10.hold.lag', ['trials' => $out, 'hold_s' => self::HOLD_S]);
        foreach ($out as $t) {
            $this->assertNotNull($t['live_after_start_ms_at_most'], 'the guard sees a live session within 15 s');
            $this->assertLessThan(self::HOLD_S * 1000 / 2, $t['live_after_start_ms_at_most'], 'the hold is at least twice the measured SNMP lag');
        }
    }

    public function testSecondLoginRightAfterTheFirstAccountingStartIsNeverAdmitted(): void
    {
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $u = $this->user('W10-hold-2');
            $before = $this->freshRejects();
            $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u, 'password' => 'W10-hold-2'])['ok']);
            $first = $this->active($u);
            $b = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $u, 'password' => 'W10-hold-2', 'wait' => 12]);
            $nas = Wp8Lab::pppActive($u);
            $after = $this->reservation($u);
            $out[] = ['first_age_ms_when_b_launched' => (int) $first['age_ms'], 'b_ok' => $b['ok'], 'b_seconds' => $b['seconds'], 'nas_sessions' => count($nas),
                'reservation_token_kept' => $after['token'] === $first['token'], 'active_fresh_rejects' => $this->freshRejects() - $before,
                'decisions' => array_map(static fn($d) => [$d['reply'], $d['open_rows'], $d['guard_verdict']], self::accessDecisions($u))];
            $this->releaseClients();
            Wp8Lab::until(fn() => Wp8Lab::pppActive($u) === [], 20, 0.5);
        }
        Wp8Lab::evidence('W10.hold.second_login', ['trials' => $out]);
        foreach ($out as $i => $t) {
            $this->assertFalse($t['b_ok'], "trial {$i}: the second client got no address - SECURITY GATE FAILED");
            $this->assertLessThanOrEqual(1, $t['nas_sessions'], "trial {$i}: two live NAS sessions of one username - SECURITY GATE FAILED");
            $this->assertTrue($t['reservation_token_kept'], "trial {$i}: the live session keeps its reservation");
        }
    }

    public function testLostStopRecoveryAfterTheHold(): void
    {
        $u = $this->user('W10-hold-3');
        $this->assertTrue(Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $u, 'password' => 'W10-hold-3'])['ok']);
        $first = $this->active($u);
        // lose the Stop: accounting blocked, client gone, path restored (RouterOS does not resend the Stop)
        $this->rules[] = $rule = Wp8Lab::rosAdd('/ip/firewall/filter/add', '=chain=output', '=protocol=udp', '=dst-port=1813', '=dst-address=10.88.0.10', '=action=drop', '=comment=nuxr_w10_hold');
        Wp8Lab::agent('pppoe_down', ['client' => 'c1']);
        $this->assertTrue((bool) Wp8Lab::until(fn() => Wp8Lab::pppActive($u) === [], 20, 0.5), 'the NAS session is gone');
        Wp8Lab::ros('/ip/firewall/filter/remove', '=.id=' . $rule);
        $this->rules = [];
        $mid = $this->reservation($u);
        $inside = null;
        if ((int) $mid['age_ms'] < (self::HOLD_S - 2) * 1000) {
            $inside = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $u, 'password' => 'W10-hold-3', 'wait' => 8]);
        }
        $age = (int) $this->reservation($u)['age_ms'];
        if ($age < self::HOLD_S * 1000 + 500) {
            usleep((self::HOLD_S * 1000 + 500 - $age) * 1000);
        }
        $retry = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $u, 'password' => 'W10-hold-3']);
        $final = $this->reservation($u);
        Wp8Lab::evidence('W10.hold.lost_stop', ['age_ms_after_loss' => (int) $mid['age_ms'], 'inside_hold' => $inside ? ['ok' => $inside['ok'], 'seconds' => $inside['seconds']] : null,
            'retry_ok' => $retry['ok'], 'final' => $final, 'first_token' => $first['token'], 'decisions' => array_map(static fn($d) => [$d['reply'], $d['open_rows'], $d['guard_verdict']], self::accessDecisions($u))]);
        if ($inside !== null) {
            $this->assertFalse($inside['ok'], 'inside the hold the lost session still blocks a new login');
        }
        $this->assertTrue($retry['ok'], 'after the hold the guard proves the lost session gone and the login is admitted (A1 recovery)');
        $this->assertNotSame($first['token'], $final['token'], 'the new session holds the reservation');
    }
}
