<?php
namespace Reseller\Tests\Integration\Wp8;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/Support/Wp8LabHelpers.php';

use PHPUnit\Framework\TestCase;
use Reseller\Tests\Support\Wp8Lab;
use Reseller\Tests\Support\Wp8LabHelpers;

/**
 * WP8 A2-R - S-1 REGRESSION GATE (owner 2026-10-09: "S-1 - HIGH - VERIFIED PPPoE USERNAME-EQUIVALENCE BYPASS"). On the
 * real RouterOS CHR 7.24.5 with the production contract (NAS type mikrotik_snmp, guard credentials): the same subscriber
 * logs in from two independent PPPoE clients under two spellings that the RADIUS lookup (radcheck utf8mb4_general_ci)
 * treats as the same subscriber - letter case, and an accent. Before A2-R the accepted A1 policy admitted both (two live
 * sessions: the guard matches the interface name of the spelling it is given). The gate: the second spelling is refused
 * and the NAS never shows two sessions. Evidence is recorded before the assertions; clients are always released.
 */
final class UsernameEquivalenceGateTest extends TestCase
{
    use Wp8LabHelpers;

    private const BW = ['rate_up' => '256', 'rate_up_unit' => 'Kbps', 'rate_down' => '512', 'rate_down_unit' => 'Kbps', 'burst' => ''];

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
        $this->releaseClients();
    }

    /** @return array<string,mixed> */
    private function trial(string $label, string $first, string $second, string $password, string $base): array
    {
        $a = Wp8Lab::agent('pppoe_up', ['client' => 'c1', 'user' => $first, 'password' => $password]);
        usleep(2_000_000);
        $b = Wp8Lab::agent('pppoe_up', ['client' => 'c3', 'user' => $second, 'password' => $password, 'wait' => 20]);
        usleep(3_000_000);
        $live = array_values(array_filter(Wp8Lab::pppActive(), static fn($s) => strtolower(str_replace(['é', 'É'], 'e', (string) ($s['name'] ?? ''))) === $base));
        $claim = static::labRadius()->query("SELECT state, HEX(session_user) AS spelling FROM nuxr_admission WHERE username = '{$base}'")->fetchAll(\PDO::FETCH_ASSOC);
        $e = ['first' => bin2hex($first), 'second' => bin2hex($second), 'first_ok' => $a['ok'], 'second_ok' => $b['ok'], 'second_log' => $b['log'] ?? [],
            'live' => array_map(static fn($s) => [$s['name'], $s['session-id'], $s['caller-id']], $live), 'claim' => $claim, 'decisions' => self::accessDecisions($second)];
        Wp8Lab::evidence('A2R.S1.' . $label, $e);
        $this->releaseClients();
        Wp8Lab::until(fn() => Wp8Lab::pppActive() === [], 20, 0.5);
        return $e;
    }

    public function testEquivalentSpellingsNeverHoldTwoSessions(): void
    {
        $base = 'nuxr_wp8_s1_cafe_' . bin2hex(random_bytes(2));
        $u = $this->provisionPolicy(['username' => $base, 'password' => 'S1-gate-1', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_ip' => ''],
            ['type' => 'PPPOE', 'pool' => 'nuxr_wp8_pool_dyn'], self::BW, new \DateTimeImmutable('+1 day'));
        $this->assertSame($base, $u);
        $trials = [
            'case' => $this->trial('case', strtoupper($base), $base, 'S1-gate-1', $base),
            'accent' => $this->trial('accent', str_replace('cafe', 'café', $base), $base, 'S1-gate-1', $base),
        ];
        foreach ($trials as $label => $t) {
            $this->assertTrue($t['first_ok'], "{$label}: the first spelling is a valid login");
            $this->assertFalse($t['second_ok'], "{$label}: the equivalent second spelling is refused (S-1)");
            $this->assertLessThanOrEqual(1, count($t['live']), "{$label}: never two live sessions of one subscriber");
        }
    }
}
