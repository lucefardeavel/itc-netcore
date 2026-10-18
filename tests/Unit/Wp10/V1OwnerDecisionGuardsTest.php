<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Source guards for the owner decisions of 2026-10-18 (V1_OWNER_DECISIONS_APPROVED):
 *  - D4 / AV-1: an unconfirmed PPPoE admission claim is released only by an operator through the CLI, never automatically;
 *  - N1 = A + G: no billing, wallet, ledger or activation code reads accounting usage or the overshoot;
 *  - D6: the CLI that handles NAS secrets never takes a secret on the command line (it is read from the database / config).
 */
final class V1OwnerDecisionGuardsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> relative paths of the PHP files under $dir (runtime + CLI, no tests / vendor) */
    private static function php(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (substr((string) $f, -4) === '.php' && !str_contains((string) $f, '/vendor/')) {
                $out[] = substr((string) $f, strlen(self::root()) + 1);
            }
        }
        sort($out);
        return $out;
    }

    public function testAdmissionReleaseIsOnlyReachableFromTheOperatorCli(): void
    {
        $callers = [];
        foreach (array_merge(self::php('system'), ['index.php', 'init.php']) as $rel) {
            if ($rel === 'system/autoload/Reseller/Radius/AdmissionRecovery.php') {
                continue;
            }
            if (preg_match('/AdmissionRecovery::release\s*\(/', (string) file_get_contents(self::root() . '/' . $rel))) {
                $callers[] = $rel;
            }
        }
        $this->assertSame(['system/reseller/reconcile.php'], $callers, 'no controller, cron, health check or plugin hook releases a claim');
        $cli = (string) file_get_contents(self::root() . '/system/reseller/reconcile.php');
        $this->assertStringContainsString("if (PHP_SAPI !== 'cli')", $cli);
        $this->assertStringContainsString("case 'admission-release':", $cli);
        $this->assertStringContainsString('--operator=', $cli, 'the operator name is a required, audited argument');
        $rec = (string) file_get_contents(self::root() . '/system/autoload/Reseller/Radius/AdmissionRecovery.php');
        foreach (['strlen(trim($operator)) < 2', 'strlen(trim($reason)) < 10', 'strlen(trim($evidence)) < 10', 'MIN_AGE_SECONDS', 'sessionSince('] as $need) {
            $this->assertStringContainsString($need, $rec, "release() keeps: {$need}");
        }
    }

    public function testBillingCodeNeverReadsUsageOrTheOvershoot(): void
    {
        $hits = [];
        foreach (['system/autoload/Reseller/Finance', 'system/autoload/Reseller/Activation'] as $dir) {
            foreach (self::php($dir) as $rel) {
                if (preg_match('/QuotaUsage|acctinputoctets|acctoutputoctets|overshoot|\bradacct\b/i', (string) file_get_contents(self::root() . '/' . $rel))) {
                    $hits[] = $rel;
                }
            }
        }
        $this->assertSame([], $hits, 'no finance or activation class reads accounting usage (the period boundary is read through PeriodBoundary / AccountingService)');
    }

    public function testNasSecretsAreNeverCommandLineArguments(): void
    {
        foreach (['system/reseller/reconcile.php', 'system/reseller/quota.php', 'system/reseller/health.php', 'system/reseller/preflight.php'] as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            $this->assertDoesNotMatchRegularExpression('/--(?:secret|password|passwd|community)\b/i', $src, "{$rel} takes no secret option");
        }
    }
}
