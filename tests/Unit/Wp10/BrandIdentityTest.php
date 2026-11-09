<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Ui\Brand;

/**
 * Owner decision 2026-10-18: PRODUCT_NAME_APPROVED = ITC NetCore, BRAND = ITC, TAGLINE = ISP Management & Billing Platform,
 * VERSION = V1.0. The visible identity comes from one class (Reseller\Ui\Brand); the original project stays credited; the
 * licence is unchanged (LIC-1 open with counsel); internal identifiers (database, tables, routes, RADIUS names, paths) keep
 * their names.
 */
final class BrandIdentityTest extends TestCase
{
    /** Templates that render a page frame (title, shell, footer). */
    private const FRAMES = [
        'ui/ui/admin/header.tpl', 'ui/ui/admin/footer.tpl', 'ui/ui/admin/admin/login.tpl', 'ui/ui/admin/alert.tpl',
        'ui/ui/customer/header.tpl', 'ui/ui/customer/header-public.tpl', 'ui/ui/customer/footer.tpl', 'ui/ui/customer/footer-public.tpl',
        'ui/ui/customer/error.tpl', 'ui/ui/customer/login-custom-moon.tpl', 'ui/ui/customer/reg-login-custom-moon.tpl',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once self::root() . '/system/autoload/Reseller/Ui/Brand.php';
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    public function testTheApprovedIdentity(): void
    {
        $this->assertSame(['ITC NetCore', 'ITC', 'NetCore', 'ISP Management & Billing Platform', 'V1.0', 'ITC'],
            [Brand::NAME, Brand::BRAND, Brand::PRODUCT, Brand::TAGLINE, Brand::VERSION, Brand::VENDOR]);
        $this->assertSame(Brand::NAME, Brand::BRAND . ' ' . Brand::PRODUCT);
        $this->assertSame(['PHPNuxBill', 'iBNuX', 'https://github.com/hotspotbilling/phpnuxbill'], [Brand::UPSTREAM, Brand::UPSTREAM_AUTHOR, Brand::UPSTREAM_URL]);
        $this->assertSame('GNU GPL v2', Brand::LICENSE, 'what LICENSE states; the version question (Q1) is with counsel');
    }

    public function testTheOperatorNameAndTheProductFallback(): void
    {
        foreach ([[], ['CompanyName' => ''], ['CompanyName' => '   '], ['CompanyName' => 'PHPNuxBill']] as $cfg) {
            $v = Brand::view($cfg);
            $this->assertSame(['ITC NetCore', 'ITC', true], [$v['company'], $v['mark'], $v['company_is_product']], json_encode($cfg));
        }
        $v = Brand::view(['CompanyName' => ' شبكة النور ']);
        $this->assertSame(['شبكة النور', 'ش', false], [$v['company'], $v['mark'], $v['company_is_product']]);
        $this->assertSame('Al Noor Net', Brand::company(['CompanyName' => 'Al Noor Net']));
        // the product name is never stored: the operator's configured value is returned unchanged
        $this->assertSame('phpnuxbill', Brand::company(['CompanyName' => 'phpnuxbill']), 'only the exact stock default is a placeholder');
    }

    public function testEveryPageFrameShowsTheBrandAndNotTheRawSetting(): void
    {
        foreach (self::FRAMES as $tpl) {
            $src = self::read($tpl);
            $this->assertStringContainsString('$_brand.', $src, $tpl);
            $this->assertStringNotContainsString("\$_c['CompanyName']", $src, "{$tpl}: the operator name comes from \$_brand.company");
            $this->assertStringNotContainsString('PHPNuxBill by', $src, $tpl);
            $this->assertStringNotContainsString('<b>N</b>uX', $src, $tpl);
            $this->assertStringNotContainsString('images/logo.png', $src, "{$tpl}: the favicon is the interim ITC mark");
        }
        // boot assigns the brand before anything can render (the error handler included)
        $boot = self::read('system/boot.php');
        $this->assertLessThan(strpos($boot, '$ui->display('), strpos($boot, "\$ui->assign('_brand', \\Reseller\\Ui\\Brand::view("));
        $this->assertLessThan(strpos($boot, "\$ui->assign('_kolaps'"), strpos($boot, "\$ui->assign('_brand'"));
    }

    public function testTheInterfaceShowsItcOnlyAndTheLicenceFilesStay(): void
    {
        // owner request 2026-11-09: the footers and the About page show ITC only (no original-project, licence or theme credit)
        foreach (['ui/ui/admin/footer.tpl', 'ui/ui/customer/footer.tpl', 'ui/ui/admin/community.tpl'] as $tpl) {
            $src = self::read($tpl);
            foreach (['upstream', '{$_brand.license', 'adminlte.io', "Lang::T('Credits')", 'PHPNuxBill'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$tpl}: {$needle}");
            }
        }
        $about = self::read('ui/ui/admin/community.tpl');
        foreach (['id="about"', '{$_brand.name|escape}', '{$_brand.version|escape}', '{$_brand.vendor_full|escape}', '{$_brand.website|escape}', '$_brand.phones'] as $needle) {
            $this->assertStringContainsString($needle, $about, "About page: {$needle}");
        }
        // the licence files and the original copyright notices stay in the distribution (GPL-2.0; LIC-1 open with counsel)
        foreach (['install/index.php', 'install/step2.php', 'install/step3.php', 'install/step4.php', 'install/step5.php', 'install/update.php'] as $f) {
            $this->assertStringContainsString('Copyright &copy; 2021 PHPNuxBill. All Rights Reserved', self::read($f), "{$f}: the upstream notice is kept");
            $this->assertStringContainsString('ITC NetCore', self::read($f), $f);
        }
        $notice = self::read('NOTICE');
        $this->assertStringContainsString('docs/THIRD_PARTY_LICENSES.md', $notice);
        $this->assertStringContainsString('PHPNuxBill', $notice);
        $this->assertStringContainsString('https://github.com/hotspotbilling/phpnuxbill', $notice);
        $readme = self::read('README.md');
        $this->assertStringContainsString('# ITC NetCore', $readme);
        $this->assertStringContainsString('https://github.com/hotspotbilling/phpnuxbill', $readme);
    }

    public function testTheLicenceAndInternalIdentifiersAreUnchanged(): void
    {
        $root = self::root();
        $this->assertSame('db296f2f7f35bca3a174efb0eb392b3b17bd94b341851429a3dff411b1c2fc73', hash_file('sha256', $root . '/LICENSE'),
            'LICENSE is not changed by the rebrand (LIC-1 stays open with counsel)');
        // the stock schema keeps its bytes: the migration runner identifies the installed variant by this hash
        $this->assertSame('98c246ce6ceb90ec6a51937c7342b41a7a1e4bcdbdf23e92c6f5795651fce3cf', hash_file('sha256', $root . '/install/phpnuxbill.sql'));
        $this->assertStringContainsString('98c246ce6ceb90ec6a51937c7342b41a7a1e4bcdbdf23e92c6f5795651fce3cf  install/phpnuxbill.sql', self::read('system/reseller/Migration/variants.php'));
        foreach (['install/freeradius/sites-available/phpnuxbill', 'install/freeradius/dictionary.phpnuxbill', 'ui/ui/styles/phpnuxbill.css'] as $f) {
            $this->assertFileExists($root . '/' . $f);
        }
        $this->assertStringContainsString("['logs/phpnuxbill', Brand::NAME,", self::read('system/autoload/Reseller/Ui/Navigation.php'), 'route name unchanged');
        $this->assertStringContainsString('"name": "hotspotbilling/phpnuxbill"', self::read('composer.json'));
        // no business, security, RADIUS or finance code reads the presentation identity
        foreach (['Finance', 'Radius', 'Activation', 'Security', 'Domain', 'Audit', 'Pricing'] as $ns) {
            foreach (glob($root . "/system/autoload/Reseller/{$ns}/*.php") ?: [] as $f) {
                $this->assertStringNotContainsString('Brand::', (string) file_get_contents($f), $f);
            }
        }
    }

    public function testNoOtherTemplateNamesTheOriginalProductAsThisOne(): void
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.tpl') && preg_match('/PHPNuxBill|PHPNUXBILL/', (string) file_get_contents($f->getPathname()))) {
                $found[] = substr($f->getPathname(), strlen(self::root()) + 1);
            }
        }
        sort($found);
        // no template shows the original product name (owner request 2026-11-09: ITC only)
        $this->assertSame([], $found);
    }

    public function testNoTestOrTrialWordingIsShown(): void
    {
        // owner request 2026-11-09: a delivered system shows no test / trial wording; the check functions keep working
        $ar = json_decode(self::read('system/lan/arabic.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['Send_check_message', 'Check_the_connection', 'Preview_only__no_message_is_sent_', 'Check_message_sent'] as $k) {
            $this->assertArrayHasKey($k, $ar, $k);
        }
        $found = [];
        foreach (['ui/ui/admin/settings/app.tpl', 'ui/ui/admin/routers/add.tpl', 'ui/ui/admin/message/bulk.tpl', 'system/controllers/settings.php',
                     'system/controllers/message.php', 'system/devices/Dummy.php'] as $rel) {
            if (preg_match('/>Test (TG|WA)<|Lang::T\\(\'Test(ing)? |\'Test (Mode|Whatsapp|SMS|Email|Telegram)|NetCore Test|just dummy/', self::read($rel), $m)) {
                $found[] = "{$rel}: {$m[0]}";
            }
        }
        $this->assertSame([], $found);
    }

    public function testNoDonationOrOriginalProjectSupportChannels(): void
    {
        $this->assertFileDoesNotExist(self::root() . '/.github/FUNDING.yml');
        $this->assertFileDoesNotExist(self::root() . '/docs/UPSTREAM_PHPNUXBILL_README.md');
        $files = ['README.md', 'docs/index.html', 'install/index.php', 'install/step2.php', 'install/step3.php',
                  '.github/ISSUE_TEMPLATE/bug_report.md', '.github/ISSUE_TEMPLATE/feature_request.md'];
        foreach (['ui/ui', 'system/devices'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (preg_match('/\.(tpl|php)$/', $f->getFilename())) {
                    $files[] = substr($f->getPathname(), strlen(self::root()) + 1);
                }
            }
        }
        $found = [];
        foreach ($files as $rel) {
            if (preg_match('~donat|paypal\.me|trakteer|karyakarsa|paid support|t\.me/phpnuxbill|t\.me/ibnux"|nux\.my\.id|standwithpalestine|contrib\.rocks|hotspotbilling/phpnuxbill/(wiki|discussions|graphs)|hotspotbilling/android-printer|assignees: ibnux~i', self::read($rel), $m)) {
                $found[] = "{$rel}: {$m[0]}";
            }
        }
        $this->assertSame([], $found, 'ITC is the only support channel: no donation or original-project support links');
    }

    public function testEveryBrandStringHasAnArabicEntry(): void
    {
        $catalog = json_decode(self::read('system/lan/arabic.json'), true, 512, JSON_THROW_ON_ERROR);
        $strings = [];
        foreach (array_merge(self::FRAMES, ['ui/ui/admin/community.tpl', 'ui/ui/admin/dashboard.tpl', 'ui/ui/admin/settings/app.tpl', 'ui/ui/admin/hotspot/add.tpl']) as $tpl) {
            preg_match_all("/Lang::T\\((['\"])(.*?)\\1\\)/", self::read($tpl), $m);
            foreach ($m[2] as $s) {
                if (preg_match('/ITC|NetCore|Billing Platform|maintained|open-source|Interface theme|Powered by|Original project|upstream|Base version|Licence|contributors|^About$|^Product$|^Version$|^Company Name$|reviewed release/', $s)) {
                    $strings[] = (string) preg_replace('/\\s+/', ' ', $s);
                }
            }
        }
        $strings = array_values(array_unique($strings));
        $this->assertGreaterThanOrEqual(9, count($strings));
        $missing = [];
        foreach ($strings as $s) {
            if (!isset($catalog[$s]) && !isset($catalog[(string) preg_replace('/[^A-Za-z0-9]/', '_', $s)])) {
                $missing[] = $s;
            }
        }
        $this->assertSame([], $missing, 'brand strings without an Arabic entry');
        // the Latin product name is isolated from the surrounding (RTL) text wherever it is printed in a sentence
        foreach (['ui/ui/admin/footer.tpl', 'ui/ui/customer/footer.tpl', 'ui/ui/customer/footer-public.tpl'] as $tpl) {
            $this->assertStringContainsString('<bdi', self::read($tpl), $tpl);
        }
    }

    public function testThePwaCarriesTheInterimTextMark(): void
    {
        $this->assertStringContainsString("dest='text', default='ITC'", self::read('dev/pwa-lab/make_icons.py'));
        $this->assertStringContainsString('Brand::company($config)', self::read('system/autoload/Reseller/Ui/Pwa.php'));
    }
}
