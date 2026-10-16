<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9-D / NEW-8 (owner decision O8-A): an editable page template (pages/*.html; written by a SuperAdmin,
 * a plugin or a restored backup) is rendered on PUBLIC pages (customer login announcement). It must be
 * rendered by a sandboxed Smarty instance: no static class except the Lang / Text allowlist, no PHP
 * functions outside the safe list, no {include} / {fetch} / constants / $smarty variables and no
 * secret configuration. Positive control: plain HTML and allowlisted helpers still render.
 */
final class W9PageSandboxTest extends Wp2HttpTestCase
{
    private const SECRET = 'nuxr-w9-smtp-secret-value-7f3a';

    private static int $tick = 0;

    private function page(string $html): string
    {
        $f = static::$app->dir . '/pages/Announcement.html';
        file_put_contents($f, $html);
        // a strictly newer mtime for every rewrite: an unsandboxed Smarty would otherwise keep serving the
        // template it compiled for an earlier rewrite in the same second
        touch($f, time() + 10 + 2 * ++self::$tick);
        clearstatcache();
        $r = $this->client()->get('login');
        $this->assertSame(200, $r->status, $r->excerpt());
        return $r->body;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$db->exec("DELETE FROM tbl_appconfig WHERE setting IN ('smtp_pass', 'CompanyName')");
        static::$db->insertRow('tbl_appconfig', ['setting' => 'smtp_pass', 'value' => self::SECRET]);
        static::$db->insertRow('tbl_appconfig', ['setting' => 'CompanyName', 'value' => 'W9 Company']);
        @mkdir(static::$app->dir . '/pages', 0755, true);
    }

    public function testPlainPageAndAllowlistedHelpersRender(): void
    {
        $body = $this->page('<p class="w9mark">Hello [{$_c.CompanyName}] {Lang::T("Announcement")}</p>');
        $this->assertStringContainsString('<p class="w9mark">Hello [W9 Company] Announcement</p>', $body);
    }

    public function testSecretConfigurationIsNotAvailable(): void
    {
        $body = $this->page('<p class="w9mark">X{$_c.smtp_pass}Y</p>');
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringContainsString('<p class="w9mark">XY</p>', $body);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function hostile(): array
    {
        return [
            'static ORM read of a staff password hash' => ['W9A{ORM::for_table("tbl_users")->find_one(1)->password}W9B', 'W9A'],
            'php function' => ['W9A{phpversion()}W9B', 'W9A'],
            'php function via modifier' => ['W9A{"id"|exec}W9B', 'W9A'],
            'include a local file' => ['W9A{include file="/etc/hostname"}W9B', 'W9A'],
            'fetch' => ['W9A{fetch file="/etc/hostname"}W9B', 'W9A'],
            'constant' => ['W9A{$smarty.const.APP_URL}W9B', 'W9A'],
            'super global' => ['W9A{$smarty.server.PATH}W9B', 'W9A'],
            'php tag' => ['W9A{php}echo 1;{/php}W9B', 'W9A'],
            'template object' => ['W9A{$smarty.template_object->smarty->getTemplateVars()|count}W9B', 'W9A'],
            'unlisted static method of an allowed class' => ['W9A{Lang::getNotifText("x")}W9B', 'W9A'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostile')]
    public function testHostileTemplateIsRefusedWithoutLeaking(string $tpl, string $marker): void
    {
        $hash = (string) static::$db->value('SELECT password FROM tbl_users WHERE id = 1');
        $body = $this->page($tpl);
        $this->assertStringNotContainsString($marker, $body, 'a template the policy refuses renders nothing: ' . substr((string) strstr($body, $marker), 0, 120));
        $this->assertStringNotContainsString($hash, $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString(PHP_VERSION, $body);
        $this->assertStringNotContainsString(static::$app->dir, $body);
    }
}
