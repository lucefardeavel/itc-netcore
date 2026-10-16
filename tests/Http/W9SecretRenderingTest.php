<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP9-D / N-11 + O-T0-1: stored secrets are write-only in the admin UI. The settings page and the router
 * edit page never render a stored secret back (SMTP password, Telegram bot token, proxy login, GitHub
 * token, Tawk.to API key, router API password); an empty submission keeps the stored value, a new
 * value replaces it. RADIUS post-auth passwords are never rendered or exported.
 */
final class W9SecretRenderingTest extends Wp2HttpTestCase
{
    private const SECRETS = [
        'smtp_pass' => 'w9-smtp-secret-5a1c',
        'telegram_bot' => 'w9-telegram-secret-77de',
        'http_proxyauth' => 'w9-proxy:secret-0b9e',
        'github_token' => 'w9-github-secret-4c2f',
        'tawkto_api_key' => 'w9-tawk-secret-e81a',
    ];
    private const ROUTER_SECRET = 'w9-router-secret-91fd';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (self::SECRETS as $k => $v) {
            static::$db->exec('DELETE FROM tbl_appconfig WHERE setting = ' . static::$db->db()->pdo->quote($k));
            static::$db->insertRow('tbl_appconfig', ['setting' => $k, 'value' => $v]);
        }
        static::$db->db()->pdo->prepare('UPDATE tbl_routers SET password = ? WHERE id = ?')->execute([self::ROUTER_SECRET, static::$seed->routerId]);
    }

    private function setting(string $k): string
    {
        return (string) static::$db->value('SELECT value FROM tbl_appconfig WHERE setting = ?', [$k]);
    }

    public function testSettingsPageNeverRendersStoredSecrets(): void
    {
        $body = $this->staff('sa')->get('settings/app')->body;
        $this->assertStringContainsString('smtp_pass', $body, 'positive control: the settings form rendered');
        foreach (self::SECRETS as $k => $v) {
            $this->assertStringNotContainsString($v, $body, "{$k} rendered back");
        }
    }

    public function testEmptySecretSubmissionKeepsTheStoredValue(): void
    {
        $c = $this->staff('sa');
        $t = $c->token('settings/app');
        $r = $c->post('settings/app-post', ['csrf_token' => $t, 'smtp_pass' => '', 'github_token' => '', 'CompanyName' => 'W9 Co']);
        $this->assertContains($r->status, [200, 302], $r->excerpt());
        $this->assertSame(self::SECRETS['smtp_pass'], $this->setting('smtp_pass'));
        $this->assertSame(self::SECRETS['github_token'], $this->setting('github_token'));
        $t = $c->token('settings/app');
        $c->post('settings/app-post', ['csrf_token' => $t, 'smtp_pass' => 'w9-new-smtp', 'CompanyName' => 'W9 Co']);
        $this->assertSame('w9-new-smtp', $this->setting('smtp_pass'), 'a new value replaces the secret');
    }

    public function testRouterPasswordIsWriteOnly(): void
    {
        $c = $this->staff('sa');
        $id = static::$seed->routerId;
        $r = $c->get('routers/edit/' . $id);
        $this->assertSame(200, $r->status, $r->excerpt());
        $this->assertStringContainsString('wp2_router', $r->body, 'positive control: the router form rendered');
        $this->assertStringNotContainsString(self::ROUTER_SECRET, $r->body);
        $t = $c->token('routers/edit/' . $id);
        $c->post('routers/edit-post', ['csrf_token' => $t, 'id' => $id, 'name' => 'wp2_router', 'ip_address' => '127.0.0.1:9', 'username' => 'u', 'password' => '', 'description' => '', 'enabled' => 0]);
        $this->assertSame(self::ROUTER_SECRET, (string) static::$db->value('SELECT password FROM tbl_routers WHERE id = ?', [$id]), 'empty field keeps the stored password');
    }

    public function testPostAuthPasswordsAreNeverRenderedOrExported(): void
    {
        $root = dirname(__DIR__, 2);
        $tpl = (string) file_get_contents($root . '/ui/ui/admin/logs/radius.tpl');
        $this->assertStringNotContainsString("\$ds['pass']", $tpl);
        $ctl = (string) file_get_contents($root . '/system/controllers/logs.php');
        $this->assertStringNotContainsString("->select('pass')", $ctl);
    }
}
