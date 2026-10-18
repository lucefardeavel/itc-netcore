<?php
namespace Reseller\Tests\Unit\Wp7;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Ui\Pwa;

/**
 * WP7 pure manifest builder (OD-W7-2, amendments B / O): built from public configuration only, identical for every
 * visitor, per-installation id, the existing staff login as start_url, replaceable interim icons (OD-W7-1).
 */
final class PwaManifestTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        if (!class_exists('Lang', false)) {
            require_once $root . '/system/autoload/Lang.php';
        }
        // Lang::getIsoLang() reads this global once (system/lan/country.json); seed it so no file / cwd is involved
        $GLOBALS['isolang'] = json_decode((string) file_get_contents($root . '/system/lan/country.json'), true);
        require_once $root . '/system/autoload/Reseller/Ui/Format.php';
        require_once $root . '/system/autoload/Reseller/Ui/Brand.php';
        require_once $root . '/system/autoload/Reseller/Ui/Pwa.php';
    }

    public function testManifestFieldsForARootAndASubdirectoryInstallation(): void
    {
        $cfg = ['CompanyName' => 'Al Noor Net', 'language' => 'english', 'secret' => 'never-read'];
        $root = Pwa::manifest($cfg, Pwa::appPath('https://isp.example/'));
        $sub = Pwa::manifest($cfg, Pwa::appPath('https://isp.example/billing'));
        $this->assertSame('/?_route=admin', $root['id']);
        $this->assertSame('/billing/?_route=admin', $sub['id'], 'one id per installation');
        foreach ([$root, $sub] as $m) {
            $this->assertSame(['id', 'name', 'short_name', 'lang', 'dir', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons'], array_keys($m));
            $this->assertSame('./?_route=admin', $m['start_url']);
            $this->assertSame('./', $m['scope']);
            $this->assertSame('standalone', $m['display']);
            $this->assertSame(['en', 'ltr'], [$m['lang'], $m['dir']]);
            $this->assertStringNotContainsString('never-read', json_encode($m));
        }
        $this->assertSame($root, Pwa::manifest($cfg, ''), 'deterministic');
    }

    public function testNamesLanguageAndFallback(): void
    {
        $ar = Pwa::manifest(['CompanyName' => 'شبكة النور للإنترنت', 'language' => 'arabic'], '');
        $this->assertSame(['ar', 'rtl'], [$ar['lang'], $ar['dir']]);
        $this->assertSame('شبكة النور للإنترنت', $ar['name']);
        // at most 12 characters, a plain prefix (it may cut a word: a recorded residual, the name stays complete)
        $this->assertSame('شبكة النور ل', $ar['short_name']);
        $this->assertSame(0, mb_strpos($ar['name'], $ar['short_name']), 'short_name is a prefix of name');
        $none = Pwa::manifest(['CompanyName' => '  '], '');
        // owner decision 2026-10-18 (product ITC NetCore): an unconfigured name - empty or the stock PHPNuxBill placeholder -
        // shows the product name (until then the fallback was 'PHPNuxBill')
        $this->assertSame(['ITC NetCore', 'ITC NetCore'], [$none['name'], $none['short_name']]);
        $stock = Pwa::manifest(['CompanyName' => 'PHPNuxBill'], '');
        $this->assertSame(['ITC NetCore', 'ITC NetCore'], [$stock['name'], $stock['short_name']]);
        $this->assertSame(['en', 'ltr'], [$none['lang'], $none['dir']]);
    }

    public function testIconsAreTheInterimReplaceableAssets(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertSame(['any', 'any', 'maskable'], array_column(Pwa::ICONS, 'purpose'));
        foreach (array_merge(array_column(Pwa::ICONS, 'src'), [Pwa::APPLE_TOUCH_ICON]) as $src) {
            $this->assertStringStartsWith('ui/ui/images/pwa/', $src);
            $size = getimagesize($root . '/' . $src);
            $this->assertNotFalse($size, $src);
            $this->assertSame($size[0], $size[1], "{$src} square");
        }
        $this->assertSame(['app' => 'nuxr', 'ok' => true], Pwa::PING);
    }
}
