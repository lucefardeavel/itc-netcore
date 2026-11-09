<?php
namespace Reseller\Tests\Unit\Wp9;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP9 final security audit, SC-1 (third-party script supply chain): every script or stylesheet that a template loads
 * from another origin is pinned to an exact version and carries Subresource Integrity (sha384 / sha512) with
 * crossorigin="anonymous", so a changed or compromised CDN file is refused by the browser instead of running in the
 * staff or customer origin (admin/header.tpl loads Chart.js on every staff page).
 * Only exception: the Google Fonts stylesheet of the maintenance page (a stylesheet, never a script; its CSS is generated
 * per browser, so no fixed hash exists).
 */
final class W9ExternalAssetIntegrityTest extends TestCase
{
    /** @return list<array{string, string}> [location, tag] */
    private static function externalTags(): array
    {
        $root = dirname(__DIR__, 3);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        $tags = [];
        foreach ($it as $f) {
            if (!in_array($f->getExtension(), ['tpl', 'html'], true)) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            preg_match_all('#<(script|link)\b[^>]*>#is', $src, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as $i => [$tag, $off]) {
                if (!preg_match('#\b(src|href)\s*=\s*["\'](?:https?:)?//#i', $tag)) {
                    continue;
                }
                if (strtolower($m[1][$i][0]) === 'link' && !preg_match('#\brel\s*=\s*["\'](?:stylesheet|preload|modulepreload)["\']#i', $tag)) {
                    continue;
                }
                $tags[] = [substr($f->getPathname(), strlen($root) + 1) . ':' . (substr_count(substr($src, 0, $off), "\n") + 1), $tag];
            }
        }
        return $tags;
    }

    public function testEveryExternalScriptAndStylesheetIsPinnedWithIntegrity(): void
    {
        $tags = self::externalTags();
        // V1.0.1 vendored the bulk-message, coupons, plan-editor and invoice assets (gate G7); the custom sign-in themes,
        // the map tiles and the maintenance-page font remain external
        $this->assertGreaterThanOrEqual(5, count($tags), 'the scan finds the known external assets');
        $bad = [];
        foreach ($tags as [$where, $tag]) {
            preg_match('#\b(?:src|href)\s*=\s*["\']([^"\']+)["\']#i', $tag, $u);
            $url = $u[1] ?? '';
            if (str_starts_with($tag, '<link') && str_starts_with($url, 'https://fonts.googleapis.com/css')) {
                continue;
            }
            $problems = [];
            if (!preg_match('#\bintegrity\s*=\s*["\']sha(384|512)-[A-Za-z0-9+/]{64,}={0,2}["\']#', $tag)) {
                $problems[] = 'no sha384/sha512 integrity';
            }
            if (!preg_match('#\bcrossorigin\s*=\s*["\']anonymous["\']#', $tag)) {
                $problems[] = 'no crossorigin="anonymous"';
            }
            if (!preg_match('#[@/-]\d+\.\d+\.\d+([/.]|$)#', parse_url($url, PHP_URL_PATH) ?: '')) {
                $problems[] = 'version not pinned (x.y.z)';
            }
            if ($problems !== []) {
                $bad[] = "{$where} {$url}: " . implode(', ', $problems);
            }
        }
        $this->assertSame([], $bad);
    }
}
