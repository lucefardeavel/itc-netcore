<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab candidate F-12 (ITC NetCore lab 2026-10-25, RBAC_MATRIX §5): settings/lang-post (system.admin: SuperAdmin AND
 * Admin) stores posted translations verbatim into the active language file, and Lang::T() output is printed unescaped
 * by the templates (3521 raw {Lang::T(...)} sites vs 88 escaped). An Admin could therefore store markup that renders in
 * SuperAdmin's (and customers') pages: an Admin -> SuperAdmin stored-script path. The shipped translations are plain
 * text (english 0 / 461 values with markup). A translation containing markup characters must be refused; a plain-text
 * edit still saves (positive control). The language file is restored afterwards.
 */
final class W10LanguageMarkupTest extends Wp2HttpTestCase
{
    private const TAG = 'x-nuxr-xss';

    private function langFile(): string
    {
        $lang = (string) static::$db->value("SELECT value FROM tbl_appconfig WHERE setting = 'language'") ?: 'english';
        return static::$app->dir . '/system/lan/' . $lang . '.json';
    }

    public function testAdminCannotStoreMarkupInATranslationThatSuperAdminRenders(): void
    {
        $file = $this->langFile();
        $this->assertFileExists($file);
        $original = (string) file_get_contents($file);
        $map = json_decode($original, true);
        $this->assertIsArray($map);
        $this->assertArrayHasKey('Dashboard', $map, 'fixture: the Dashboard title is translated');
        try {
            $admin = $this->staff('ad');
            $admin->get('settings/language');
            // markup in one translation (the whole map is posted, as the form does)
            $admin->postWithToken('settings/lang-post', ['Dashboard' => 'Dash"><' . self::TAG . '>'] + $map, [], 'settings/language');
            // the impact first: does Admin-written markup render for SuperAdmin? then: was it stored at all?
            $page = $this->staff('sa')->get('dashboard')->body;
            $this->assertFalse(str_contains($page, '<' . self::TAG . '>'), 'Admin-written markup rendered in the SuperAdmin dashboard');
            $stored = json_decode((string) file_get_contents($file), true);
            $this->assertFalse(str_contains((string) ($stored['Dashboard'] ?? ''), '<' . self::TAG . '>'), 'the markup translation was stored');
            // positive control: a plain-text edit is saved
            $plain = 'Dashboard W10 ' . bin2hex(random_bytes(2));
            $this->staff('ad')->postWithToken('settings/lang-post', ['Dashboard' => $plain] + $map, [], 'settings/language');
            $this->assertSame($plain, json_decode((string) file_get_contents($file), true)['Dashboard'] ?? null, 'a plain-text translation is saved');
        } finally {
            file_put_contents($file, $original);
        }
    }
}
