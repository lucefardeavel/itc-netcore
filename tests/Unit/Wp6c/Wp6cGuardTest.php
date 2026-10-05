<?php
namespace Reseller\Tests\Unit\Wp6c;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP6C source guards (presentation only): no device / user-agent detection (Amendment F), no new external asset, no
 * zoom lock, viewport-fit=cover for safe areas (Amendment E), component-width tables through container queries with a
 * fallback (Amendment D), no hover-only reveal without a touch / keyboard equivalent (Amendment G), no Smarty comment
 * inside a literal block (Amendment H), the Up registry lives in the UI layer and never repurposes the RouteMap
 * (Amendment B), and the WP6C components escape their output.
 */
final class Wp6cGuardTest extends TestCase
{
    private const COMPONENTS = ['ui/ui/admin/nuxr/actionbar.tpl', 'ui/ui/admin/nuxr/secret.tpl', 'ui/ui/admin/nuxr/state-page.tpl', 'ui/ui/admin/nuxr/pricing-row.tpl'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    /** @return list<string> staff templates (the customer portal is out of WP6C scope, OD-3) */
    private static function staffTemplates(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/ui/ui/admin', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->getExtension() === 'tpl') {
                $out[] = substr($f->getPathname(), strlen(self::root()) + 1);
            }
        }
        sort($out);
        return $out;
    }

    public function testNoDeviceDetectionInTheShellScript(): void
    {
        $js = self::read('ui/ui/scripts/nuxr-shell.js');
        foreach (['/navigator\.userAgent/', '/navigator\.platform/', '/\bisMobile\b/i', '/ontouchstart/', '/maxTouchPoints/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $js, "no device detection: {$re}");
        }
        // the one accepted WP6A media query (phone stepper) is the only matchMedia; WP6C adds none
        $this->assertSame(1, substr_count($js, 'matchMedia('), 'WP6C adds no matchMedia');
    }

    public function testNoNewExternalAssetsInTheShell(): void
    {
        foreach (['ui/ui/scripts/nuxr-shell.js', 'ui/ui/styles/nuxr-shell.css', 'ui/ui/styles/nuxr-rtl-legacy.css'] as $rel) {
            // the accepted WP6A residual: the operator-opened lazy map (Leaflet) is the only external URL
            $src = str_replace(['https://unpkg.com/leaflet@1.9.3/dist/leaflet.css', 'https://unpkg.com/leaflet@1.9.3/dist/leaflet.js'], '', self::read($rel));
            $this->assertDoesNotMatchRegularExpression('#https?://#', $src, "{$rel}: no external URL");
            $this->assertStringNotContainsString('@import', $src, "{$rel}: no @import");
        }
        foreach (self::COMPONENTS as $rel) {
            $this->assertDoesNotMatchRegularExpression('#<(script|link)\b[^>]*(src|href)="https?://#', self::read($rel), "{$rel}: no external asset");
        }
    }

    public function testViewportAllowsZoomAndCoversSafeAreas(): void
    {
        foreach (['ui/ui/admin/header.tpl', 'ui/ui/admin/admin/login.tpl', 'ui/ui/admin/error.tpl'] as $rel) {
            $src = self::read($rel);
            $this->assertMatchesRegularExpression('/<meta name="viewport" content="[^"]*width=device-width/', $src, $rel);
            $this->assertDoesNotMatchRegularExpression('/maximum-scale|user-scalable\s*=\s*no/', $src, "{$rel}: zoom stays available");
        }
        foreach (['ui/ui/admin/header.tpl', 'ui/ui/admin/admin/login.tpl'] as $rel) {
            $this->assertStringContainsString('viewport-fit=cover', self::read($rel), "{$rel}: env(safe-area-*) resolves");
        }
        $css = self::read('ui/ui/styles/nuxr-shell.css');
        $this->assertStringContainsString('env(safe-area-inset-bottom', $css);
    }

    public function testTablesSwitchByComponentWidthWithAFallback(): void
    {
        $css = self::read('ui/ui/styles/nuxr-shell.css');
        $this->assertMatchesRegularExpression('/\.nuxr-adaptive\s*\{[^}]*container-type:\s*inline-size/', $css);
        $this->assertMatchesRegularExpression('/\.nuxr-stack\s*\{[^}]*container-type:\s*inline-size/', $css);
        $this->assertStringContainsString('@supports not (container-type: inline-size)', $css, 'viewport fallback for old engines');
        $this->assertGreaterThanOrEqual(3, preg_match_all('/@container\s*\(/', $css), 'container queries drive the switch');
    }

    public function testNoHoverOnlyRevealInStaffTemplates(): void
    {
        $bad = [];
        foreach (self::staffTemplates() as $rel) {
            $src = self::read($rel);
            // a legacy cell that only became readable on mouse hover (black on black)
            if (preg_match('/onmouseenter="this\.style\.backgroundColor/', $src)) {
                $bad[] = $rel . ' (hover-only cell)';
            }
            // a password field revealed on hover must also carry the tap / keyboard toggle
            if (preg_match_all('/<input\b[^>]*onmouseenter="this\.type = \'text\'"[^>]*>/s', $src, $m)) {
                foreach ($m[0] as $tag) {
                    if (!str_contains($tag, 'data-nuxr-peek')) {
                        $bad[] = $rel . ' (hover-only password)';
                    }
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function testNoSmartyCommentInsideALiteralBlock(): void
    {
        $bad = [];
        foreach (self::staffTemplates() as $rel) {
            if (preg_match_all('/\{literal\}(.*?)\{\/literal\}/s', self::read($rel), $m)) {
                foreach ($m[1] as $block) {
                    if (str_contains($block, '{*')) {
                        $bad[] = $rel;
                    }
                }
            }
        }
        $this->assertSame([], $bad, 'a {* *} inside {literal} is printed into the page as text');
    }

    public function testUpRegistryLivesInTheUiLayer(): void
    {
        $nav = self::read('system/autoload/Reseller/Ui/Navigation.php');
        $this->assertStringContainsString('const UP = [', $nav);
        $this->assertStringContainsString('public static function up(', $nav);
        // Up never reveals what the actor may not open: every candidate goes through the same allow check
        $this->assertMatchesRegularExpression('/function up\([^)]*\)[^{]*\{.*?self::allowed\(/s', $nav);
        $routeMap = self::read('system/autoload/Reseller/Security/RouteMap.php');
        $this->assertStringNotContainsString('const UP', $routeMap, 'RouteMap is not repurposed');
        $this->assertStringNotContainsString('Navigation', $routeMap, 'RouteMap does not depend on the UI layer');
    }

    public function testWp6cComponentsEscapeTheirOutput(): void
    {
        $bad = [];
        foreach (self::COMPONENTS as $rel) {
            $src = (string) preg_replace('/\{\*.*?\*\}/s', '', self::read($rel));
            preg_match_all('/\{(\$[^}\s|]+)((?:\|[^}]*)?)\}/', $src, $m, PREG_SET_ORDER);
            foreach ($m as [$all, $var, $mods]) {
                if ($var === '$csrf_token' || str_contains($mods, 'escape')) {
                    continue;
                }
                $bad[] = "{$rel}: {$all}";
            }
        }
        $this->assertSame([], $bad);
    }

    public function testNoInlineWidthsInWp6cComponents(): void
    {
        foreach (self::COMPONENTS as $rel) {
            $this->assertDoesNotMatchRegularExpression('/style="[^"]*\bwidth\s*:/', self::read($rel), "{$rel}: layout lives in the stylesheet");
        }
    }

    public function testEveryWp6cStringHasAnArabicEntry(): void
    {
        $catalog = json_decode(self::read('system/lan/arabic.json'), true, 512, JSON_THROW_ON_ERROR);
        $strings = [];
        foreach (array_merge(self::COMPONENTS, ['ui/ui/admin/403.tpl', 'ui/ui/admin/404.tpl', 'ui/ui/admin/error.tpl']) as $rel) {
            preg_match_all("/Lang::T\\((['\"])(.*?)\\1\\)/", self::read($rel), $m);
            foreach ($m[2] as $s) {
                $strings[$s] = $rel;
            }
        }
        preg_match('/const UP = \\[(.*?)\\n    \\];/s', self::read('system/autoload/Reseller/Ui/Navigation.php'), $up);
        preg_match_all("/\\], '([^']+)'\\]/", $up[1] ?? '', $labels);
        foreach ($labels[1] as $s) {
            $strings[$s] = 'Navigation::UP';
        }
        $this->assertGreaterThan(30, count($strings));
        $missing = [];
        foreach ($strings as $s => $file) {
            $s = (string) preg_replace('/\\s+/', ' ', $s);
            $key = (string) preg_replace('/[^A-Za-z0-9]/', '_', $s);
            if (!isset($catalog[$s]) && !isset($catalog[$key])) {
                $missing[] = "{$file}: {$s}";
            }
        }
        $this->assertSame([], $missing, 'WP6C strings without an Arabic entry');
    }
}
