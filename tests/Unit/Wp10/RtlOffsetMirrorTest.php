<?php
namespace Reseller\Tests\Unit\Wp10;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Lab browser finding (2026-10-25): the Arabic customer login box was not centred. nuxr-rtl-legacy.css mirrored only the
 * column offsets 1-3, so the login's col-sm-offset-4 column floated right but kept its LEFT margin. Every Bootstrap
 * column offset a template uses must be mirrored (margin-right, margin-left reset) at its own breakpoint.
 */
final class RtlOffsetMirrorTest extends TestCase
{
    private const MEDIA = ['xs' => null, 'sm' => '768px', 'md' => '992px', 'lg' => '1200px'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> media width (or '' for xs) => the CSS inside that block */
    private static function blocks(string $css): array
    {
        $out = ['' => preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css)];
        preg_match_all('/@media \(min-width: (\d+px)\) \{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as $b) {
            $out[$b[1]] = ($out[$b[1]] ?? '') . $b[2];
        }
        return $out;
    }

    public function testEveryOffsetUsedByATemplateIsMirroredAtItsBreakpoint(): void
    {
        $used = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/ui/ui', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.tpl') && preg_match_all('/\bcol-(xs|sm|md|lg)-offset-(\d+)\b/', (string) file_get_contents($f->getPathname()), $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $used[$x[1] . '-' . $x[2]] = [$x[1], (int) $x[2]];
                }
            }
        }
        $this->assertArrayHasKey('sm-4', $used, 'fixture: the customer login uses col-sm-offset-4');
        $blocks = self::blocks((string) file_get_contents(self::root() . '/ui/ui/styles/nuxr-rtl-legacy.css'));
        foreach ($used as [$bp, $n]) {
            $want = $n === 0 ? '0' : rtrim(rtrim(sprintf('%.8f', $n * 100 / 12), '0'), '.') . '%';
            $css = $blocks[self::MEDIA[$bp] ?? ''] ?? '';
            $this->assertMatchesRegularExpression('/\[dir="rtl"\] \.col-' . $bp . '-offset-' . $n . ' \{ margin-left: 0; margin-right: ' . preg_quote($want, '/') . '; \}/', $css,
                "col-{$bp}-offset-{$n} is mirrored in RTL at its breakpoint");
        }
    }
}
