<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9 final security audit, INST-1: the last installer step (install/step5.php) had no post-install guard. Once the
 * application was installed, an unauthenticated request copied pages_template/ over the operator's edited pages/
 * (Terms, Privacy, announcements...) and deleted pages_template/. Every other installer step already refuses once
 * config.php exists (WP2 SEC-19). After the fix: refused (403), nothing copied or deleted. The page copy still runs
 * during a real installation (config.php written by step 4, pages/ not created yet) - positive control.
 */
final class W9InstallerPostInstallTest extends Wp2HttpTestCase
{
    public function testStep5DoesNothingOnAnInstalledApplication(): void
    {
        $dir = static::$app->dir;
        $page = $dir . '/pages/Privacy_Policy.html';
        file_put_contents($page, '<p>operator edited privacy policy</p>');
        $templates = glob($dir . '/pages_template/*');
        $this->assertNotEmpty($templates);
        $r = $this->client()->raw('GET', 'install/step5.php');
        $this->assertSame(403, $r->status, $r->excerpt());
        $this->assertSame('<p>operator edited privacy policy</p>', file_get_contents($page), 'edited page untouched');
        $this->assertSame($templates, glob($dir . '/pages_template/*'), 'pages_template untouched');
    }

    public function testStep5StillCopiesThePagesDuringAFreshInstallation(): void
    {
        $dir = static::$app->dir;
        $moved = $dir . '-pages-' . bin2hex(random_bytes(3));
        rename($dir . '/pages', $moved);
        try {
            $r = $this->client()->raw('GET', 'install/step5.php');
            $this->assertSame(200, $r->status, $r->excerpt());
            $this->assertFileExists($dir . '/pages/Privacy_Policy.html', 'the installer created pages/');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir . '/pages'));
            rename($moved, $dir . '/pages');
            if (!is_dir($dir . '/pages_template')) {
                exec('cp -a ' . escapeshellarg(dirname(__DIR__, 2) . '/pages_template') . ' ' . escapeshellarg($dir . '/pages_template'));
            }
        }
    }
}
