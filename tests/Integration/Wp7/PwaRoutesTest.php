<?php
namespace Reseller\Tests\Integration\Wp7;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Reseller\Tests\Support\Wp2HttpTestCase;

/**
 * WP7 owner decision OD-W7-2 over real HTTP: the public, read-only staff PWA support routes. pwa/manifest is built from
 * public configuration only (identical for every visitor, correct JSON whatever CompanyName holds); pwa/ping is a fixed
 * no-store marker whose cookie-less requests leave no session behind while a cookie-identified session is never
 * touched; nothing else exists under pwa/, nothing accepts POST, and the API refuses the controller.
 */
final class PwaRoutesTest extends Wp2HttpTestCase
{
    private function sessionFiles(): int
    {
        return count(glob(static::$app->dir . '/wp2_sessions/sess_*') ?: []);
    }

    public function testManifestIsUserNeutralAndComplete(): void
    {
        $anon = $this->client()->get('pwa/manifest');
        $this->assertSame(200, $anon->status, $anon->excerpt());
        $this->assertStringStartsWith('application/manifest+json', (string) $anon->header('content-type'));
        $this->assertStringContainsString('no-cache', (string) $anon->header('cache-control'));
        $m = $anon->json();
        $this->assertIsArray($m);
        foreach (['id', 'name', 'short_name', 'lang', 'dir', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons'] as $k) {
            $this->assertArrayHasKey($k, $m, $k);
        }
        $this->assertSame('./?_route=admin', $m['start_url'], 'launch = the existing staff login (no pwa/start)');
        $this->assertSame('./', $m['scope']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/?_route=admin', $m['id'], 'stable per installation (root installation here)');
        $sizes = array_map(static fn(array $i): string => $i['sizes'] . '/' . $i['purpose'], $m['icons']);
        $this->assertSame(['192x192/any', '512x512/any', '512x512/maskable'], $sizes);
        foreach ($m['icons'] as $i) {
            $this->assertStringStartsWith('ui/ui/images/pwa/', $i['src'], 'replaceable identity assets live in one path');
            $this->assertFileExists(dirname(__DIR__, 3) . '/' . $i['src']);
        }
        // the same bytes for every visitor: anonymous, Admin, Agent, Sales (the browser fetches it without credentials)
        foreach (['ad', 'A', 'A1'] as $u) {
            $this->assertSame($anon->body, $this->staff($u)->get('pwa/manifest')->body, "{$u}: user-neutral manifest");
        }
        foreach (['Staff ad', 'Staff A', 'csrf', 'token', 'session'] as $needle) {
            $this->assertStringNotContainsString($needle, $anon->body);
        }
    }

    public function testManifestEncodesAnyCompanyNameAsValidJson(): void
    {
        $db = static::$db;
        $old = $db->value("SELECT value FROM tbl_appconfig WHERE setting = 'CompanyName'");
        $name = 'Noor "Net" </script><img src=x> \\ شبكة النور للإنترنت والاتصالات';
        $db->exec("DELETE FROM tbl_appconfig WHERE setting = 'CompanyName'");
        $db->insertRow('tbl_appconfig', ['setting' => 'CompanyName', 'value' => $name]);
        try {
            $r = $this->client()->get('pwa/manifest');
            $m = json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($name, $m['name'], 'round-trips exactly');
            $this->assertSame(mb_substr($name, 0, 12), $m['short_name'], 'multibyte-safe short name');
        } finally {
            $db->exec("DELETE FROM tbl_appconfig WHERE setting = 'CompanyName'");
            if ($old !== null && $old !== false) {
                $db->insertRow('tbl_appconfig', ['setting' => 'CompanyName', 'value' => (string) $old]);
            }
        }
    }

    public function testPingIsAFixedNoStoreMarkerAndLeavesNoAnonymousSession(): void
    {
        $before = $this->sessionFiles();
        for ($i = 0; $i < 5; $i++) {
            $r = $this->client()->get('pwa/ping'); // a fresh client per request: no cookie, as credentials: 'omit'
            $this->assertSame(200, $r->status);
            $this->assertStringStartsWith('application/json', (string) $r->header('content-type'));
            $this->assertStringContainsString('no-store', (string) $r->header('cache-control'));
            $this->assertSame('{"app":"nuxr","ok":true}', $r->body);
            $this->assertNull($r->header('set-cookie'), 'no session cookie for a heartbeat');
        }
        $this->assertSame($before, $this->sessionFiles(), 'cookie-less heartbeats leave no session files');
        // a cookie-identified session is never destroyed by a ping
        $staff = $this->staff('A');
        $this->assertSame(200, $staff->get('pwa/ping')->status);
        $dash = $staff->get('dashboard');
        $this->assertSame(200, $dash->status, 'the staff session survives a ping sent with its cookie');
        $this->assertStringContainsString('nuxr-shell', $dash->body);
    }

    public function testManifestLeavesNoAnonymousSessionAndNeverTouchesACookieSession(): void
    {
        // owner fix round 1 (FR1-5): the same throwaway-session hygiene as the heartbeat
        $before = $this->sessionFiles();
        $bodies = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $this->client()->get('pwa/manifest'); // a fresh client per request: the browser fetches it without credentials
            $this->assertSame(200, $r->status);
            $this->assertNull($r->header('set-cookie'), 'no session cookie for an anonymous manifest request');
            $bodies[] = $r->body;
        }
        $this->assertSame($before, $this->sessionFiles(), 'cookie-less manifest requests leave no session files');
        $this->assertCount(1, array_unique($bodies));
        // a manifest request carrying an existing staff session: the session survives, nothing is re-issued
        foreach (['ad', 'A', 'A1'] as $u) {
            $staff = $this->staff($u);
            $files = $this->sessionFiles();
            $r = $staff->get('pwa/manifest');
            $this->assertSame($bodies[0], $r->body, "{$u}: byte-identical to the anonymous manifest");
            $this->assertNull($r->header('set-cookie'), "{$u}: the session cookie is not re-issued or cleared");
            $this->assertSame($files, $this->sessionFiles(), "{$u}: no session file created or removed");
            $dash = $staff->get('dashboard');
            $this->assertSame(200, $dash->status, "{$u}: the staff session survives a manifest request sent with its cookie");
            $this->assertStringContainsString('nuxr-shell', $dash->body);
        }
    }

    public function testOnlyTwoGetActionsExistAndNothingMutates(): void
    {
        foreach (['pwa', 'pwa/start', 'pwa/offline', 'pwa/sw', 'pwa/anything'] as $route) {
            $r = $this->client()->get($route);
            $this->assertSame(404, $r->status, $route);
            $this->assertSame('', trim($r->body), "{$route}: no body");
        }
        foreach (['pwa/manifest', 'pwa/ping'] as $route) {
            $this->assertSame(405, $this->staff('ad')->postWithToken($route, [])->status, "{$route}: GET only");
        }
    }

    public function testTheApiRefusesThePwaController(): void
    {
        $t = $this->apiToken('ad');
        foreach (['pwa/ping', 'pwa/manifest'] as $r) {
            $j = $this->client()->api('GET', $r, [], $t)->json();
            $this->assertFalse((bool) ($j['success'] ?? true), "{$r} is not API-reachable");
        }
    }
}
