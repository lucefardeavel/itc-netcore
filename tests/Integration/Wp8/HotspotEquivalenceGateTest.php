<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * WP8 H-1 REGRESSION GATE (owner 2026-10-10: "H-1 - HIGH - VERIFIED HOTSPOT USERNAME-EQUIVALENCE BYPASS"; fix H1-A). On
 * the real RouterOS CHR 7.24.5 Hotspot (http-chap) with the production contract and the accepted RadiusPolicyBuilder
 * Hotspot policy (Port-Limit = Simultaneous-Use = shared_users): the same subscriber from independent Hotspot clients
 * under the stored spelling and under spellings the RADIUS lookup treats as equal (case, accent). Before H1-A each
 * equivalent spelling opened its own Port-Limit (two live sessions under shared_users = 1). The gate: an equivalent
 * spelling is refused, the stored spelling logs in, and the NAS never shows more live sessions of the subscriber than
 * shared_users. Evidence is recorded before the assertions; clients are always released.
 */
final class HotspotEquivalenceGateTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];
    private const CLIENTS = ['c2', 'c4', 'c5'];

    protected function setUp(): void
    {
        $this->requireLab();
        $st = Wp8Lab::agent('option_g', ['cmd' => 'state']);
        $nas = explode("\t", (string) ($st['nas'][0] ?? ''));
        if (($nas[2] ?? '') !== 'mikrotik_snmp' || !str_contains(implode("\n", $st['freeradius']), 'persistent_creds=1')) {
            $this->markTestSkipped('needs the production-contract lab (dev/wp8/option_g_gate.py on shipped)');
        }
    }

    protected function tearDown(): void
    {
        if (static::labRadius() === null) {
            return;
        }
        foreach (self::CLIENTS as $c) {
            Wp8Lab::agent('hs_logout', ['client' => $c]);
        }
    }

    private static function fold(string $s): string
    {
        return strtolower(str_replace(['é', 'É'], 'e', $s));
    }

    /** live Hotspot sessions of the subscriber under ANY spelling, sampled for $seconds */
    private static function sample(string $base, float $seconds): array
    {
        $max = 0;
        $users = [];
        $t = microtime(true);
        do {
            $now = array_values(array_filter(Wp8Lab::ros('/ip/hotspot/active/print'), static fn($a) => self::fold((string) ($a['user'] ?? '')) === self::fold($base)));
            $max = max($max, count($now));
            foreach ($now as $a) {
                $users[$a['user']] = true;
            }
            usleep(500_000);
        } while (microtime(true) - $t < $seconds);
        return ['max' => $max, 'users' => array_keys($users)];
    }

    /** @param list<array{0:string,1:string}> $plan [client, typed spelling] */
    private function trial(string $label, string $base, string $password, array $plan): array
    {
        foreach ($plan as [$c]) {
            Wp8Lab::agent('hs_logout', ['client' => $c]);
            Wp8Lab::agent('hs_dhcp', ['client' => $c]);
        }
        $logins = [];
        foreach ($plan as [$c, $typed]) {
            $l = Wp8Lab::agent('hs_login', ['client' => $c, 'user' => $typed, 'password' => $password]);
            $logins[] = ['client' => $c, 'typed_hex' => bin2hex($typed), 'exact' => $typed === $base, 'ok' => $l['ok'], 'message' => $l['message']];
            sleep(2);
        }
        $e = ['base' => $base, 'logins' => $logins, 'live' => self::sample($base, 8)];
        Wp8Lab::evidence('H1.gate.' . $label, $e);
        foreach ($plan as [$c]) {
            Wp8Lab::agent('hs_logout', ['client' => $c]);
        }
        Wp8Lab::until(fn() => self::sample($base, 0.1)['max'] === 0 ?: null, 20, 1);
        return $e;
    }

    private function subscriber(string $tag, int $shared): array
    {
        $base = 'nuxr_wp8_h1_cafe_' . $tag . '_' . bin2hex(random_bytes(2));
        $pw = 'H1-gate-' . bin2hex(random_bytes(3));
        $u = $this->provisionPolicy(['username' => $base, 'password' => $pw], ['type' => 'Hotspot', 'shared_users' => $shared], self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertSame($base, $u);
        return [$base, $pw];
    }

    public function testEquivalentSpellingsNeverOpenAnExtraHotspotSession(): void
    {
        [$base, $pw] = $this->subscriber('s1', 1);
        $trials = [
            'exact_then_upper' => $this->trial('shared1.exact_then_upper', $base, $pw, [['c2', $base], ['c4', strtoupper($base)]]),
            'upper_then_exact' => $this->trial('shared1.upper_then_exact', $base, $pw, [['c2', strtoupper($base)], ['c4', $base]]),
            'exact_then_accent' => $this->trial('shared1.exact_then_accent', $base, $pw, [['c2', $base], ['c4', str_replace('cafe', 'café', $base)]]),
        ];
        foreach ($trials as $label => $t) {
            foreach ($t['logins'] as $l) {
                if ($l['exact']) {
                    $this->assertTrue($l['ok'], "{$label}: the stored spelling logs in");
                } else {
                    $this->assertFalse($l['ok'], "{$label}: an equivalent spelling is refused (H-1)");
                }
            }
            $this->assertLessThanOrEqual(1, $t['live']['max'], "{$label}: never more live sessions than shared_users = 1");
            $this->assertSame([$base], $t['live']['users'], "{$label}: only the stored spelling is ever live");
        }
    }

    public function testSharedUsersTwoAdmitsTwoStoredSpellingSessionsAndNoVariant(): void
    {
        [$base, $pw] = $this->subscriber('s2', 2);
        $t = $this->trial('shared2.exact_upper_exact', $base, $pw, [['c2', $base], ['c4', strtoupper($base)], ['c5', $base]]);
        $this->assertSame([true, false, true], array_column($t['logins'], 'ok'), 'stored spelling twice, the variant refused');
        $this->assertSame(2, $t['live']['max'], 'shared_users = 2: two live sessions');
        $this->assertSame([$base], $t['live']['users']);
    }
}
