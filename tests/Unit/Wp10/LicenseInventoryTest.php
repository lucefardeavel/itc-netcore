<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * D10 (owner V1_OWNER_DECISIONS_APPROVED): the licence inventory handed to counsel cannot drift from the code.
 * A fact check, not a legal conclusion: every Composer package of system/composer.lock is listed in docs/THIRD_PARTY_LICENSES.md with the
 * same version and licence, and the list of packages that ship no licence file (LEGAL_REVIEW_PACKAGE.md) is the real one.
 */
final class LicenseInventoryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<array<string,mixed>> */
    private static function packages(): array
    {
        $lock = json_decode((string) file_get_contents(self::root() . '/system/composer.lock'), true);
        return $lock['packages'];
    }

    public function testEveryComposerPackageIsInTheInventoryWithItsVersionAndLicense(): void
    {
        $doc = (string) file_get_contents(self::root() . '/docs/THIRD_PARTY_LICENSES.md');
        $this->assertNotSame([], self::packages());
        foreach (self::packages() as $p) {
            $licence = implode(' / ', $p['license'] ?? []);
            $this->assertMatchesRegularExpression('/^\| ' . preg_quote($p['name'], '/') . ' \| ' . preg_quote($p['version'], '/') . ' \| ' . preg_quote($licence, '/') . ' \|/m', $doc,
                "{$p['name']} {$p['version']} ({$licence}) is listed in docs/THIRD_PARTY_LICENSES.md");
        }
    }

    public function testThePackagesWithoutALicenceFileAreExactlyTheListedOnes(): void
    {
        $missing = [];
        foreach (self::packages() as $p) {
            $dir = self::root() . '/system/vendor/' . $p['name'];
            $has = false;
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (preg_match('/^(licen[sc]e|copying|notice)/i', basename($f))) {
                    $has = true;
                }
            }
            if (!$has) {
                $missing[] = $p['name'];
            }
        }
        sort($missing);
        $legal = (string) file_get_contents(self::root() . '/owner-review/WP10/LEGAL_REVIEW_PACKAGE.md');
        $this->assertSame(1, preg_match('/Packages that ship \*\*no licence file\*\* in `system\/vendor\/`: ([^|]+)\|/', $legal, $m));
        preg_match_all('/`([a-z0-9\/_.-]+)`/', $m[1], $names);
        $listed = $names[1];
        sort($listed);
        $this->assertSame($listed, $missing, 'the legal package lists exactly the packages without a licence file');
    }

    public function testTheApplicationLicenseIsGplV2(): void
    {
        $text = (string) file_get_contents(self::root() . '/LICENSE');
        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $text);
        $this->assertStringContainsString('Version 2, June 1991', $text);
    }
}
