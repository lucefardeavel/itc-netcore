<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * ITC NetCore brand integration (lab/brand-netcore-v1, 2026-10-26) guards: the approved vector assets are the byte-identical
 * v0.7 sources (ASSETS.txt) with no text, image, script or external reference; the fonts are self-hosted with their OFL
 * licences; nuxr-brand.css is the single source of the colour tokens and every page that renders the shell or the portal
 * loads it first; the token values meet WCAG 2.2 AA (text >= 4.5:1, focus >= 3:1) in light and dark, computed from the CSS
 * itself; the product mark replaces the interim text tile only when the company is the product (operator identity kept).
 */
final class BrandIdentityAssetsTest extends TestCase
{
    private const COLOUR_TOKENS = ['bg', 'surface', 'surface-2', 'border', 'text', 'muted', 'primary', 'primary-soft', 'on-primary', 'on-status', 'focus',
        'success', 'success-soft', 'warning', 'warning-soft', 'danger', 'danger-soft', 'info', 'info-soft', 'neutral', 'neutral-soft', 'processing', 'processing-soft'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    /** @return array<string,string> token => #rrggbb of one CSS block */
    private static function tokens(string $css, string $selector): array
    {
        self::assertSame(1, preg_match('/^' . preg_quote($selector, '/') . ' \{\n(.*?)^\}/ms', $css, $m), $selector);
        preg_match_all('/--(n[xc]-[a-z0-9-]+):\s*(#[0-9a-f]{6});/', $m[1], $t, PREG_SET_ORDER);
        $out = [];
        foreach ($t as $x) {
            $out[$x[1]] = $x[2];
        }
        return $out;
    }

    private static function ratio(string $a, string $b): float
    {
        $l = static function (string $h): float {
            $c = array_map(static fn($i) => hexdec(substr($h, 1 + 2 * $i, 2)) / 255, [0, 1, 2]);
            $c = array_map(static fn($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$x, $y] = [$l($a), $l($b)];
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    public function testTheVectorAssetsAreTheApprovedSourcesAndCarryNoTextOrExternalReference(): void
    {
        $manifest = self::read('ui/ui/images/brand/ASSETS.txt');
        preg_match_all('/^([0-9a-f]{64})  (\S+\.svg)  <-  (\S+)$/m', $manifest, $m, PREG_SET_ORDER);
        $this->assertCount(7, $m, 'mark, white, navy, micro + 3 wordmarks');
        // the hashes of the Drive v0.7 / BR-RC1 provenance documents (primary and white quoted there)
        $byName = array_column($m, 1, 2);
        $this->assertSame('592996c1c9d79d83e7c952e82eae80bf82b43a979be1e79f26296a3196bdfb10', $byName['netcore-mark.svg']);
        $this->assertSame('6b798de13613dd7e7dd36b890c526c3164d268abd3ad78f5ab9b28ce4f0552b7', $byName['netcore-mark-white.svg']);
        foreach ($m as [, $sha, $file]) {
            $svg = self::read('ui/ui/images/brand/' . $file);
            $this->assertSame($sha, hash('sha256', $svg), "{$file} is byte-identical to its approved source");
            $this->assertDoesNotMatchRegularExpression('/<(text|tspan|image|script|foreignObject|style)\b|href=|url\(\s*["\']?(https?:|\/\/)|@import|font-family/i', $svg, "{$file}: vector paths only");
        }
        $fav = self::read('ui/ui/images/brand/favicon.svg');
        $this->assertDoesNotMatchRegularExpression('/<(text|image|script|foreignObject)\b|https?:\/\/(?!www\.w3\.org)/i', $fav);
    }

    public function testTheFontsAreSelfHostedWithTheirLicences(): void
    {
        $list = self::read('ui/ui/fonts/brand/FONTS.txt');
        preg_match_all('/^([0-9a-f]{64})  (\S+)$/m', $list, $m, PREG_SET_ORDER);
        $names = array_column($m, 1, 2);
        foreach (['inter-latin-regular.woff2', 'inter-latin-medium.woff2', 'inter-latin-semibold.woff2', 'inter-latin-bold.woff2',
            'noto-sans-arabic-regular.woff2', 'noto-sans-arabic-bold.woff2', 'Inter-LICENSE.txt', 'NotoSansArabic-LICENSE.txt'] as $f) {
            $this->assertArrayHasKey($f, $names, $f);
            $this->assertSame($names[$f], hash_file('sha256', self::root() . '/ui/ui/fonts/brand/' . $f), $f);
        }
        $this->assertStringContainsString('OFL-1.1', self::read('ui/ui/fonts/brand/Inter-LICENSE.txt'));
        $this->assertStringContainsString('OFL-1.1', self::read('ui/ui/fonts/brand/NotoSansArabic-LICENSE.txt'));
        $css = self::read('ui/ui/styles/nuxr-brand.css');
        preg_match_all('/url\("([^"]+)"\)/', $css, $u);
        $this->assertNotEmpty($u[1]);
        foreach ($u[1] as $url) {
            $this->assertStringStartsWith('../fonts/brand/', $url, 'no external font request');
            $this->assertFileExists(self::root() . '/ui/ui/styles/' . $url);
        }
        $this->assertDoesNotMatchRegularExpression('/https?:|@import/', $css, 'the brand layer loads nothing external');
    }

    public function testTheBrandLayerIsTheSingleSourceOfTheColourTokensAndLoadsFirst(): void
    {
        $brand = self::read('ui/ui/styles/nuxr-brand.css');
        $shell = self::read('ui/ui/styles/nuxr-shell.css');
        $light = self::tokens($brand, ':root');
        $dark = self::tokens($brand, 'body.dark-mode');
        foreach (self::COLOUR_TOKENS as $t) {
            $this->assertArrayHasKey('nx-' . $t, $light, "light --nx-{$t}");
            $this->assertArrayHasKey('nx-' . $t, $dark, "dark --nx-{$t}");
            $this->assertDoesNotMatchRegularExpression('/--nx-' . preg_quote($t, '/') . ':/', $shell, "--nx-{$t} is defined only in nuxr-brand.css");
        }
        $this->assertStringContainsString('--nx-font: "Inter", "Noto Sans Arabic"', $brand);
        // every template that loads the shell stylesheet loads the brand layer just before it
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        $shells = 0;
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.tpl')) {
                continue;
            }
            $tpl = (string) file_get_contents($f->getPathname());
            if (str_contains($tpl, 'styles/nuxr-shell.css')) {
                $shells++;
                $b = strpos($tpl, 'styles/nuxr-brand.css');
                $this->assertNotFalse($b, $f->getFilename() . ' loads the brand layer');
                $this->assertLessThan(strpos($tpl, 'styles/nuxr-shell.css'), $b, $f->getFilename() . ': brand layer first');
            }
        }
        $this->assertGreaterThanOrEqual(3, $shells, 'header, sign-in, error');
        foreach (['ui/ui/customer/header.tpl', 'ui/ui/customer/header-public.tpl', 'ui/ui/customer/error.tpl', 'ui/ui/admin/alert.tpl'] as $p) {
            $tpl = self::read($p);
            $this->assertStringContainsString('styles/nuxr-brand.css', $tpl, $p);
            $this->assertMatchesRegularExpression('/<body[^>]*\bnuxr-portal\b/', $tpl, "{$p}: portal token mapping");
        }
    }

    public function testTheTokenValuesMeetWcagAaInBothThemes(): void
    {
        $brand = self::read('ui/ui/styles/nuxr-brand.css');
        $fails = [];
        foreach ([':root' => self::tokens($brand, ':root'), 'dark' => self::tokens($brand, 'body.dark-mode') + self::tokens($brand, ':root')] as $theme => $t) {
            $pairs = [];
            foreach (['bg', 'surface', 'surface-2'] as $s) {
                $pairs[] = ['text', $s, 4.5];
                $pairs[] = ['muted', $s, 4.5];
                $pairs[] = ['primary', $s, 4.5];
                $pairs[] = ['link', $s, 4.5];
            }
            foreach (['success', 'warning', 'danger', 'info', 'neutral', 'processing'] as $tone) {
                $pairs[] = [$tone, $tone . '-soft', 4.5];
                $pairs[] = [$tone, 'surface', 4.5];
                $pairs[] = [$tone, 'bg', 4.5];
                $pairs[] = ['on-status', $tone, 4.5];
            }
            array_push($pairs, ['on-primary', 'primary', 4.5], ['primary', 'primary-soft', 4.5], ['focus', 'surface', 3.0], ['focus', 'bg', 3.0],
                ['portal-head-text', 'portal-head-bg', 4.5], ['portal-side-text', 'portal-side-bg', 4.5], ['toast-text', 'toast-bg', 4.5]);
            foreach ($pairs as [$fg, $bg, $need]) {
                $this->assertArrayHasKey('nx-' . $fg, $t, "{$theme} --nx-{$fg}");
                $this->assertArrayHasKey('nx-' . $bg, $t, "{$theme} --nx-{$bg}");
                $r = self::ratio($t['nx-' . $fg], $t['nx-' . $bg]);
                if ($r < $need) {
                    $fails[] = sprintf('%s %s on %s %.2f < %.1f', $theme, $fg, $bg, $r, $need);
                }
            }
        }
        // the solid legacy button tones carry white text in both themes
        $root = self::tokens($brand, ':root');
        foreach (['primary', 'success', 'danger', 'warning', 'info'] as $s) {
            $r = self::ratio('#ffffff', $root['nc-solid-' . $s]);
            if ($r < 4.5) {
                $fails[] = sprintf('white on --nc-solid-%s %.2f', $s, $r);
            }
        }
        $this->assertSame([], $fails);
    }

    public function testTheProductMarkReplacesTheTextTileOnlyForTheProductIdentity(): void
    {
        $tpl = self::read('ui/ui/admin/header.tpl');
        $this->assertMatchesRegularExpression('/\{if \$_brand\.company_is_product\}<span class="nuxr-brand-mark is-logo".*?netcore-mark\.svg.*?netcore-mark-white\.svg.*?\{else\}<span class="nuxr-brand-mark" aria-hidden="true">\{\$_brand\.mark\|escape\}<\/span>\{\/if\}/', $tpl);
        $this->assertStringContainsString('<bdi>{$_brand.company|escape}</bdi>', $tpl, 'the operator name stays');
        // sign-in: the operator name + initial above the card; the product wordmark below it (product mode: shown once)
        $login = self::read('ui/ui/admin/admin/login.tpl');
        $this->assertStringContainsString('{if $_brand.company_is_product}<h1 class="sr-only">{$_brand.name|escape}</h1>{else}<div class="nuxr-login-brand"><span class="nuxr-brand-mark" aria-hidden="true">{$_brand.mark|escape}</span><bdi>{$_brand.company|escape}</bdi></div>{/if}', $login);
        $this->assertStringContainsString('netcore-wordmark.svg', $login);
        $this->assertMatchesRegularExpression('/\{if \$_brand\.company_is_product\}<img class="nc-mark"[^>]*netcore-mark-white\.svg[^>]*>\{else\}<b>\{\$_brand\.mark\|escape\}<\/b>\{\/if\}/', self::read('ui/ui/customer/header.tpl'));
        // the reversed artwork is the only one shown on dark surfaces (F-03)
        $this->assertStringContainsString('body.dark-mode .nc-on-light { display: none !important; }', self::read('ui/ui/styles/nuxr-brand.css'));
        // both portal footer variants carry the product line, and only it (owner request 2026-11-09: ITC only)
        $foot = self::read('ui/ui/customer/footer.tpl');
        $this->assertSame(2, substr_count($foot, "{Lang::T('Powered by')} <bdi>{\$_brand.name|escape}</bdi>"), 'product line in both footer variants');
        $this->assertStringNotContainsString('upstream', $foot);
    }

    public function testThePortalHeaderShowsTheProductMarkInlineBeforeTheName(): void
    {
        // UI polish 2026-10-29 (lab screenshots 043 / 052 / 101 / 117, product mode): the generic img.nc-mark rule is
        // display:block, so the mark stacked above the name in the 50px logo bar and "ITC NetCore" slid under the navbar;
        // the portal logo rule keeps mark + name on one line, as the staff shell does (operator mode has no mark there)
        $css = self::read('ui/ui/styles/nuxr-brand.css');
        $this->assertMatchesRegularExpression('/^body\.nuxr-portal \.main-header \.logo \.logo-lg \.nc-mark \{[^}]*\bdisplay: inline-block;[^}]*\bvertical-align: middle;/m', $css);
        $this->assertStringContainsString('img.nc-mark { display: block;', $css, 'the generic rule (sign-in, About) is unchanged');
        $this->assertMatchesRegularExpression('/<span class="logo-lg">\{if \$_brand\.company_is_product\}<img class="nc-mark"[^>]*netcore-mark-white\.svg[^>]*>\{\/if\}<bdi>\{\$_brand\.company\|escape\}<\/bdi><\/span>/', self::read('ui/ui/customer/header.tpl'));
    }
}
