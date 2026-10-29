<?php
namespace Reseller\Tests\Unit\Wp4;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Owner decision TD-1b (2026-10-28): install/freeradius/bin/nuxr_guard_selfcheck, the readiness check of the PPPoE
 * session guard. Source guards pin what makes it trustworthy as an operational alert - it probes exactly what the guard
 * probes (same credential file, OIDs and net-snmp options), it is read-only, it never prints or passes a community on a
 * command line - and the file-level faults it must report are executed here (no SNMP needed for them). The SNMP faults
 * (wrong community, unreachable, missing tool) are proven on the real CHR: OptionGGateTest::testShippedGuardSelfcheck*.
 */
final class GuardSelfcheckSourceTest extends TestCase
{
    private const BIN = 'install/freeradius/bin/nuxr_guard_selfcheck';
    private const GUARD = 'install/freeradius/bin/nuxr_pppoe_session_guard';

    private static function read(string $rel): string
    {
        $f = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($f);
        return (string) file_get_contents($f);
    }

    public function testItProbesExactlyWhatTheGuardProbes(): void
    {
        $sc = self::read(self::BIN);
        $guard = self::read(self::GUARD);
        foreach (['CREDS="${0%/*}/nas_snmp"', 'OID_UPTIME=.1.3.6.1.2.1.1.3.0', 'OID_IFNAME=.1.3.6.1.4.1.14988.1.1.14.1.1.2',
            "printf 'defCommunity %s\\ndefVersion 2c\\nhexOutputLength 0\\nmibs :\\n' \"\$comm\"",
            'snmpget -r0 -t1 -On "$ip" "$OID_UPTIME"', 'snmpbulkwalk -r0 -t1 -On -Ox -Cr50 "$ip" "$OID_IFNAME"',
            "comm=\$(awk -v ip=\"\$ip\" '\$1 == ip { print \$2; exit }' \"\$CREDS\")", "'' | *[!A-Za-z0-9._-]*)"] as $needle) {
            $this->assertStringContainsString($needle, $guard, "the guard still has: {$needle}");
            $this->assertStringContainsString($needle, $sc, "the readiness check probes the same way: {$needle}");
        }
        $this->assertSame(0755, fileperms(dirname(__DIR__, 3) . '/' . self::BIN) & 0777, 'committed executable (0755)');
    }

    public function testItIsReadOnlyAndNeverExposesTheCommunity(): void
    {
        $code = implode("\n", array_filter(explode("\n", self::read(self::BIN)), static fn(string $l): bool => !preg_match('/^\s*#/', $l)));
        foreach (['snmpset', 'mysql', 'mariadb', 'radacct', 'nuxr_admission', 'curl', 'wget'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "read-only: no {$forbidden}");
        }
        // the community only ever goes into the private per-run net-snmp configuration, never into output or argv
        $this->assertSame(1, substr_count($code, '"$comm"'), 'the community is used exactly once: the private snmp.conf');
        $this->assertDoesNotMatchRegularExpression('/echo[^\n]*\$comm/', $code);
        $this->assertDoesNotMatchRegularExpression('/snmp\w+ [^\n]*-c /', $code, 'no -c COMMUNITY on a command line');
        $this->assertStringContainsString('umask 077', $code);
        $this->assertStringContainsString("rm -rf -- \"\$WORK\"", $code, 'the per-run configuration is deleted');
    }

    /** runs a copy of the program next to $creds (null = no credential file) */
    private static function selfcheck(?string $creds, int $mode = 0600, string ...$nas): array
    {
        $dir = sys_get_temp_dir() . '/nuxr-sc-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        copy(dirname(__DIR__, 3) . '/' . self::BIN, "{$dir}/nuxr_guard_selfcheck");
        chmod("{$dir}/nuxr_guard_selfcheck", 0755);
        if ($creds !== null) {
            file_put_contents("{$dir}/nas_snmp", $creds);
            chmod("{$dir}/nas_snmp", $mode);
        }
        exec(implode(' ', array_map('escapeshellarg', ['/bin/sh', "{$dir}/nuxr_guard_selfcheck", ...$nas])) . ' 2>&1', $out, $rc);
        @unlink("{$dir}/nas_snmp");
        @unlink("{$dir}/nuxr_guard_selfcheck");
        @rmdir($dir);
        return ['rc' => $rc, 'out' => $out];
    }

    public function testFileLevelFaultsAreReportedWithExitTwo(): void
    {
        $missing = self::selfcheck(null);
        $this->assertSame(2, $missing['rc']);
        $this->assertSame(['nas=- status=ERROR:no-credential-file', 'guard_selfcheck=ERROR nas=0 errors=1'], $missing['out']);

        $open = self::selfcheck("10.88.0.2 secretcommunity1\n", 0644);
        $this->assertSame(2, $open['rc']);
        $this->assertSame('nas=- status=ERROR:credential-file-mode', $open['out'][0], 'a group / world readable file is a fault');
        $this->assertStringNotContainsString('secretcommunity1', implode("\n", $open['out']));
    }

    public function testCredentialEntryFaultsArePerNasWhenTheToolsExist(): void
    {
        if (!is_executable('/usr/bin/snmpget') || !is_executable('/usr/bin/snmpbulkwalk')) {
            $r = self::selfcheck("10.88.0.2 goodcommunity\n");
            $this->assertSame(2, $r['rc']);
            $this->assertMatchesRegularExpression('/^nas=- status=ERROR:snmp(get|bulkwalk)-missing$/', $r['out'][0], 'no net-snmp here: reported, never OK');
            return;
        }
        $r = self::selfcheck("10.88.0.2 bad'community\n10.88.0.3 goodcommunity\n", 0600, '10.88.0.2', '10.88.0.9', '10.88.0');
        $this->assertSame(2, $r['rc']);
        $this->assertMatchesRegularExpression('/^nas=10\.88\.0\.2 status=ERROR:credentials ms=\d+$/', $r['out'][0], 'invalid characters in the community');
        $this->assertMatchesRegularExpression('/^nas=10\.88\.0\.9 status=ERROR:credentials ms=\d+$/', $r['out'][1], 'no entry for the NAS');
        $this->assertMatchesRegularExpression('/^nas=- status=ERROR:malformed-nas ms=\d+$/', $r['out'][2]);
        $this->assertSame('guard_selfcheck=ERROR nas=3 errors=3', end($r['out']));
        $this->assertStringNotContainsString('goodcommunity', implode("\n", $r['out']));
    }
}
