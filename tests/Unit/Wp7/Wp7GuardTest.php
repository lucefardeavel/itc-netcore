<?php
namespace Reseller\Tests\Unit\Wp7;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;
use Reseller\Security\RouteMap;

/**
 * WP7 source guards (owner plan 245e46bf, decisions OD-W7-1..4, amendments A-O). The staff PWA is presentation,
 * connectivity and safe offline behaviour only: two public read-only routes, no business authority, no business cache.
 */
final class Wp7GuardTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    public function testPwaRoutesArePublicGetOnlyAndNeverApiAllowed(): void
    {
        $map = RouteMap::ROUTES['pwa'] ?? null;
        $this->assertIsArray($map);
        $this->assertSame(['', '*', 'notfound', 'manifest', 'ping'], array_keys($map), 'exactly manifest + ping (+ the 404 default)');
        foreach (['notfound', 'manifest', 'ping'] as $k) {
            $this->assertSame(['GET' => 'public'], $map[$k], "pwa/{$k}: GET-only, public, no capability");
        }
        $this->assertArrayNotHasKey('pwa', RouteMap::API_ALLOWED);
    }

    public function testThePwaControllerHasNoBusinessOrMutatingCode(): void
    {
        $src = self::read('system/controllers/pwa.php');
        $this->assertStringContainsString("if (!defined('NUXR_RUNTIME'))", $src);
        foreach (['/ORM::/', '/\$_POST/', '/->save\(/', '/ActorContext/', '/Admin::/', '/User::/', '/\$_SESSION\[/', '/file_put_contents|fopen|unlink/',
                  '/Finance|Activation|Wallet|Pricing|DataScope|PermissionService/', '/\$ui->/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $src, "pwa.php: {$re}");
        }
        preg_match_all("/case '([a-z-]+)':/", $src, $m);
        $this->assertSame(['manifest', 'ping', 'notfound'], $m[1]);
        // heartbeat session disposal only for the cookie-less throwaway session
        $this->assertMatchesRegularExpression('/if \(!isset\(\$_COOKIE\[session_name\(\)\]\) && session_status\(\) === PHP_SESSION_ACTIVE\)/', $src);
    }

    public function testThePwaBuilderIsPureAndUserNeutral(): void
    {
        $src = self::read('system/autoload/Reseller/Ui/Pwa.php');
        foreach (['/ORM::/', '/\$_(SESSION|COOKIE|GET|POST|SERVER)/', '/ActorContext|Admin::|User::/', '/file_get_contents|fopen|curl_/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $src, "Pwa.php: {$re}");
        }
        $this->assertMatchesRegularExpression("/'start_url' => '\.\/\?_route=admin'/", $src);
    }

    public function testTheServiceWorkerStoresOnlyTheLiteralPrecacheList(): void
    {
        $sw = self::read('nuxr-sw.js');
        preg_match('/var PRECACHE = \[(.*?)\];/s', $sw, $m);
        preg_match_all("/'([^']+)'/", $m[1] ?? '', $items);
        $this->assertSame(['ui/ui/images/pwa/icon-180.png', 'ui/ui/images/pwa/icon-192.png', 'ui/ui/images/pwa/icon-512.png', 'ui/ui/images/pwa/icon-maskable-512.png'],
            $items[1], 'precache = the offline document (OFFLINE) + the interim icons, nothing else');
        $this->assertStringContainsString("PRECACHE = [\n    OFFLINE,", $sw);
        $this->assertStringContainsString("var OFFLINE = 'nuxr-offline.html';", $sw);
        // exactly one write into CacheStorage, inside the install handler, over the precache URLs only
        $this->assertSame(1, substr_count($sw, '.put('), 'one cache.put');
        $install = substr($sw, (int) strpos($sw, "addEventListener('install'"), 900);
        $this->assertStringContainsString('cache.put(u, r)', $install);
        $this->assertStringContainsString('PRECACHE_URLS.map(', $install);
        $this->assertStringContainsString("credentials: 'omit'", $install, 'precache fetched without credentials');
        foreach (['/\.add\(|addAll\(/', '/indexedDB/', "/addEventListener\\('(sync|periodicsync|push|backgroundfetch)/", '/requestPermission|pushManager|showNotification/',
                  '/importScripts/', '#https?://#', '/navigator\.userAgent/', '/caches\.open\((?!CACHE\))/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $sw, "nuxr-sw.js: {$re}");
        }
        // no automatic activation: skipWaiting only in the user-initiated message branch
        $this->assertSame(1, substr_count($sw, 'skipWaiting()'));
        $this->assertMatchesRegularExpression("/data\\.type === 'SKIP_WAITING' && event\\.source && event\\.source\\.type === 'window'\\) \\{\\s*self\\.skipWaiting\\(\\)/", $sw);
        // navigations are network-only; the only other respondWith serves the precache list
        $this->assertSame(2, substr_count($sw, 'respondWith('));
        $this->assertMatchesRegularExpression('/return res; \/\/ network response, never stored/', $sw);
        $this->assertStringContainsString("Response.redirect(OFFLINE_URL + '?reason=unconfirmed', 303)", $sw);
    }

    public function testOneVersionForWorkerClientAndBuilder(): void
    {
        preg_match("/var VERSION = '([^']+)';/", self::read('nuxr-sw.js'), $sw);
        preg_match("/public const VERSION = '([^']+)';/", self::read('system/autoload/Reseller/Ui/Pwa.php'), $php);
        $this->assertNotEmpty($sw);
        $this->assertSame($php[1], $sw[1]);
        foreach (['ui/ui/admin/footer.tpl', 'ui/ui/admin/admin/login.tpl'] as $tpl) {
            $this->assertStringContainsString('nuxr-pwa.js?' . $sw[1] . '" data-nuxr-sw="{$app_url}/nuxr-sw.js"', self::read($tpl), $tpl);
        }
    }

    public function testTheOfflineDocumentIsStaticAndGeneric(): void
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', self::read('nuxr-offline.html'));
        foreach (['/<script[^>]+src=/i', '/<link[^>]+href=/i', '#url\(\s*["\']?https?:#i', '/<\?|\{\$/', '/csrf|token|password/i', '/fetch\(|XMLHttpRequest/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $html, "nuxr-offline.html: {$re}");
        }
        $this->assertStringContainsString('It may or may not have reached the server. Reconnect and check the authoritative record before repeating the action.', $html);
        $this->assertStringContainsString('must not be treated as authoritative', $html);
        $this->assertStringContainsString('Changes cannot be sent while offline.', $html);
        $this->assertStringNotContainsString('nothing changed', strtolower($html));
    }

    public function testThePwaClientUsesFeatureDetectionOnly(): void
    {
        $js = self::read('ui/ui/scripts/nuxr-pwa.js');
        foreach (['/navigator\.(userAgent|platform|vendor|maxTouchPoints)/', '/matchMedia\(/', '/ontouchstart/', '/isMobile/i', '#https?://#',
                  '/requestPermission|pushManager/', '/indexedDB/', '/caches\./'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $js, "nuxr-pwa.js: {$re}");
        }
        $this->assertStringContainsString("register(swUrl, { updateViaCache: 'none' })", $js);
        foreach (['ui/ui/admin/header.tpl', 'ui/ui/admin/admin/login.tpl'] as $tpl) {
            $src = self::read($tpl);
            $this->assertStringContainsString('<link rel="manifest" href="{$app_url}/?_route=pwa/manifest">', $src, $tpl . ': manifest without credentials');
            $this->assertStringNotContainsString('use-credentials', $src);
        }
    }

    public function testTheCsrfShimHookCanOnlyStopARequest(): void
    {
        $shim = self::read('ui/ui/nuxr-csrf.tpl');
        $this->assertStringContainsString("return typeof window.nuxrMutationGate !== 'function' || window.nuxrMutationGate(kind, method, url) !== false;", $shim,
            'absent gate = unchanged behaviour (customer portal)');
        preg_match_all('/allowed\(\'([A-Za-z.-]+)\'/', $shim, $m);
        $this->assertSame(['form.submit', 'xhr', 'fetch', 'nuxrPost', 'data-method'], $m[1], 'every script-driven send path consults the gate');
        // the token is only ever read, never generated or altered by the shim
        $this->assertSame(1, preg_match_all('/\btoken = /', $shim), 'one token assignment (from the server-rendered value)');
        $this->assertStringContainsString('var token = window.nuxrCsrfToken;', $shim);
        $code = (string) preg_replace('/\{\*.*?\*\}/s', '', $shim);
        foreach (['/setTimeout\([^)]*nativeSubmit/', '/localStorage|sessionStorage|indexedDB/', '/retry|queue|replay/i'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $code, "nuxr-csrf.tpl: {$re}");
        }
    }

    public function testTheGateBlocksOnlyConfirmedOfflineOrReconnectingNonGetRequests(): void
    {
        $js = self::read('ui/ui/scripts/nuxr-pwa.js');
        $this->assertMatchesRegularExpression("/if \(m === 'GET' \|\| m === 'HEAD' \|\| !sameOrigin\(url\)\) \{ return true; \}/", $js);
        $this->assertMatchesRegularExpression("/if \(state !== 'offline' && state !== 'reconnecting'\) \{ return true; \}/", $js, 'SLOW submits normally');
        $this->assertStringContainsString("fetch(cfg.ping, { credentials: 'omit', cache: 'no-store', redirect: 'error'", $js, 'heartbeat without cookies');
        $this->assertMatchesRegularExpression("/j\.app === 'nuxr' && j\.ok === true && Object\.keys\(j\)\.length === 2/", $js, 'exact marker (captive portals fail)');
        $this->assertStringContainsString("document.addEventListener('submit', function (e) {", $js);
        $this->assertStringContainsString('window.XMLHttpRequest = GatedXHR;', $js, 'XHR gated per instance (Pace replaces the constructor)');
        $this->assertStringContainsString("jqXHR.abort('offline');", $js);
        foreach (['/localStorage\.setItem\((?!\'nuxr-)/', '/sessionStorage\.setItem\((?!\'nuxr-)/', '/navigator\.onLine\s*\?/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $js, "nuxr-pwa.js: {$re}");
        }
    }

    public function testInstallIsUserInitiatedOnly(): void
    {
        $js = self::read('ui/ui/scripts/nuxr-pwa.js');
        // the browser prompt is only deferred, then shown once from the click handler; nothing prompts on load
        $this->assertStringContainsString("window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferredPrompt = e; });", $js);
        $this->assertSame(1, substr_count($js, '.prompt()'), 'one prompt() call site');
        $install = substr($js, (int) strpos($js, 'function install() {'), 700);
        $this->assertStringContainsString('p.prompt();', $install, 'prompt() only inside install()');
        $this->assertStringContainsString("b.addEventListener('click', install);", $js, 'install() is only a click handler');
        $this->assertSame(1, substr_count($js, 'install()'), 'install() is defined and never called directly');
        $this->assertStringContainsString('b.hidden = !secure;', $js, 'offered only in a secure context');
        // both entry points are hidden by default in the markup, and hidden by CSS inside the installed app
        $this->assertStringContainsString('<button type="button" data-nuxr-install hidden>', self::read('ui/ui/admin/header.tpl'));
        $this->assertStringContainsString('<button type="button" data-nuxr-install hidden>', self::read('ui/ui/admin/footer.tpl'));
        $this->assertMatchesRegularExpression('/@media \(display-mode: standalone\) \{\s*\[data-nuxr-install\] \{\s*display: none !important;/', self::read('ui/ui/styles/nuxr-shell.css'));
    }

    public function testUpdateIsUserChosenDeferredInCriticalFlowsAndReloadsOnlyThisTabOnce(): void
    {
        $js = self::read('ui/ui/scripts/nuxr-pwa.js');
        $this->assertStringNotContainsString('skipWaiting', $js, 'the client never activates a worker by itself');
        $this->assertSame(1, substr_count($js, "postMessage({ type: 'SKIP_WAITING' })"), 'one SKIP_WAITING site');
        $apply = substr($js, (int) strpos($js, 'function applyUpdate() {'), 400);
        $this->assertMatchesRegularExpression("/if \(critical\(\)\) \{ showUpdateBar\('deferred'\); return; \}.*\n\s*updating = true;\n\s*w\.postMessage\(\{ type: 'SKIP_WAITING' \}\);/", $apply,
            'SKIP_WAITING only after the critical-flow check, from the Update click');
        $this->assertStringContainsString("addEventListener('click', applyUpdate);", $js);
        $this->assertSame(1, substr_count($js, 'applyUpdate()'), 'applyUpdate() is never called directly');
        // exactly one reload, only in the tab that pressed Update, only once; other tabs are only told
        $this->assertSame(1, substr_count($js, 'location.reload('));
        $this->assertMatchesRegularExpression("/if \(updating\) \{\s*if \(!reloaded\) \{ reloaded = true; location\.reload\(\); \}\s*\} else if \(hadController\) \{\s*showUpdateBar\('updated'\);/", $js);
        $this->assertStringContainsString("q('form[data-nuxr-submitted], [data-nuxr-poll], dialog[open], [data-nuxr-critical]')", $js, 'critical-flow markers (plan section 9)');
        $this->assertStringContainsString('reg && reg.waiting && navigator.serviceWorker.controller ? reg.waiting : null', $js, 'nothing offered on the first installation');
        $this->assertStringContainsString('id="nuxr-confirm-form" data-nuxr-critical>', self::read('ui/ui/admin/activation/create.tpl'));
        $this->assertStringContainsString('class="nuxr-form" style="margin-top:12px" data-nuxr-critical>', self::read('ui/ui/admin/finance/review.tpl'));
    }

    public function testPruneStaysInsideTheInstallationNamespace(): void
    {
        $sw = self::read('nuxr-sw.js');
        $this->assertStringContainsString("var NS = 'nuxr-pwa:' + scopeHash(SCOPE.href) + ':';", $sw);
        $this->assertSame(1, substr_count($sw, 'caches.delete('), 'one cache deletion site');
        $this->assertStringContainsString("keys.filter(function (k) { return k.indexOf(NS) === 0 && k !== CACHE; })", $sw, 'only this installation namespace');
        $js = self::read('ui/ui/scripts/nuxr-pwa.js');
        $this->assertStringContainsString("r.active.postMessage({ type: 'PRUNE' })", $js);
        $this->assertStringContainsString("if (k.indexOf('nuxr-') === 0) { sessionStorage.removeItem(k); }", $js, 'clears only its own transient keys');
        $this->assertDoesNotMatchRegularExpression('/localStorage\.clear|sessionStorage\.clear|caches\.delete/', $js);
    }
}
