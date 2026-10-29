<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Lab brand round 3 (full browser matrix, 2026-10-26): the public registration forms printed the posted values back into
 * value="..." unescaped (fullname, email, address, phone; _post() only trims): a reflected XSS on a public page. Every
 * value echoed into an attribute of the customer public / registration templates is escaped, and the vendored chart
 * library is the self-hosted, SRI-pinned copy.
 */
final class PublicFormEscapingTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testRegistrationValuesAreEscaped(): void
    {
        foreach (glob(self::root() . '/ui/ui/customer/{register,register-otp,register-rotp,login,login-noreg,forgot}.tpl', GLOB_BRACE) as $f) {
            $tpl = (string) file_get_contents($f);
            // (the CSRF token is server-generated hex, never user input)
            preg_match_all('/value="\{\$(?!csrf_token\})[A-Za-z_]+\}"/', $tpl, $m);
            $this->assertSame([], $m[0], basename($f) . ': a value attribute without |escape');
        }
    }

    public function testChartJsIsSelfHostedWithItsPinnedHash(): void
    {
        foreach (['ui/ui/admin/header.tpl', 'ui/ui/admin/reports/list.tpl'] as $p) {
            $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/chart', (string) file_get_contents(self::root() . '/' . $p), $p);
        }
        $js = self::root() . '/ui/ui/scripts/vendor/chart-3.5.1.min.js';
        $this->assertSame('sha384-zVyRZ9eQ45iRS7ZRZQSFKGJpypyxssU6clBCysSqzjmdGWNn8//uC8dh9DabpAbK', 'sha384-' . base64_encode(hash_file('sha384', $js, true)));
        $ac = self::root() . '/ui/ui/scripts/vendor/chartjs-plugin-autocolors-0.5.1.min.js';
        $this->assertSame('sha384-vnGRB4CdtZ0nxGdDU8ZknCYFULPqwUgZDuBR0bVo3l1hpTKuOCWykajKvrGGFnyz', 'sha384-' . base64_encode(hash_file('sha384', $ac, true)));
    }
}
