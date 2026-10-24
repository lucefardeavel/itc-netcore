<?php
namespace Reseller\Tests\Http;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * Lab finding F-1 (ITC NetCore lab 2026-10-24, owner direction D-21): settings/miscellaneous (SuperAdmin and Admin)
 * stored EVERY posted key into tbl_appconfig, while settings/app-post allowlists its keys and reserves the security
 * keys (radius_enable, single_session, session timeout, http_proxy, github_token ...) to SuperAdmin and refuses the
 * global api_key / csrf_enabled entirely (WP2 SEC-07 / OD-12). The miscellaneous page may only store its own form
 * fields; the form controls themselves (save, csrf_token) are never stored as settings.
 */
final class W10SettingsMiscellaneousAllowlistTest extends Wp2HttpTestCase
{
    private const FOREIGN = ['radius_enable' => 'nuxr-f1', 'single_session' => 'nuxr-f1', 'enable_session_timeout' => 'nuxr-f1',
        'session_timeout_duration' => '9999', 'http_proxy' => 'nuxr-f1.invalid:1', 'http_proxyauth' => 'nuxr-f1', 'github_token' => 'nuxr-f1',
        'github_username' => 'nuxr-f1', 'api_key' => 'nuxr-f1', 'csrf_enabled' => '0', 'CompanyName' => 'nuxr-f1', 'nuxr_f1_arbitrary' => 'nuxr-f1'];

    private function setting(string $key): ?string
    {
        $v = static::$db->rows('SELECT value FROM tbl_appconfig WHERE setting = ?', [$key]);
        return $v === [] ? null : (string) $v[0]['value'];
    }

    public function testOnlyTheMiscellaneousFormFieldsAreStoredForAdminAndSuperAdmin(): void
    {
        foreach (['ad', 'sa'] as $who) {
            $before = [];
            foreach (array_keys(self::FOREIGN) + [100 => 'save', 101 => 'csrf_token'] as $k) {
                $before[$k] = $this->setting($k);
            }
            $marker = $who === 'ad' ? 'yes' : 'no';
            $c = $this->staff($who);
            $r = $c->postWithToken('settings/miscellaneous', ['save' => 'save', 'router_check' => $marker] + self::FOREIGN, [], 'settings/miscellaneous');
            $this->assertContains($r->status, [200, 302], "{$who}: " . $r->excerpt());
            $this->assertSame($marker, $this->setting('router_check'), "{$who}: positive control, the page's own field is saved");
            foreach ($before as $k => $v) {
                $this->assertSame($v, $this->setting($k), "{$who}: settings/miscellaneous must not store '{$k}'");
            }
        }
    }
}
