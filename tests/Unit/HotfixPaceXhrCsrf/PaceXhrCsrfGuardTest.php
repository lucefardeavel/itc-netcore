<?php
namespace Reseller\Tests\Unit\HotfixPaceXhrCsrf;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * Pre-WP8 hotfix R-WP7-CARRY-01 (owner plan ae4eaeac, OD-HF-1..4, amendment HF-A1). The WP2 CSRF shim must patch the
 * prototype that real XMLHttpRequest instances inherit (Pace replaces window.XMLHttpRequest on staff pages with a
 * factory returning native instances), patching ONLY open and send, once per document, never replacing the constructor
 * or setRequestHeader. The shim stays the single runtime producer of X-CSRF-Token; vendor files and script order are
 * unchanged.
 */
final class PaceXhrCsrfGuardTest extends TestCase
{
    private const SHIM = 'ui/ui/nuxr-csrf.tpl';
    private const TOKEN_LINE = "if (this._nuxrSame) { try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) { } }";
    private const VENDOR = [
        'ui/ui/scripts/pace.min.js' => '10fae43631849825b26b36f703f1298fe5bb426da907dbe77d7c3e5fa2c898f3',
        'ui/ui/scripts/jquery.min.js' => '540bc6dec1dd4b92ea4d3fb903f69eabf6d919afd48f4e312b163c28cff0f441',
        'ui/ui/scripts/vendors.js' => '1bafaae5796440e64b9231b34e70e8ddbae8e4401f0541a75c6ca47db54b8107',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    /** the shim's JavaScript (the literal block), without Smarty comments */
    private static function shimJs(): string
    {
        $src = (string) preg_replace('/\{\*.*?\*\}/s', '', self::read(self::SHIM));
        preg_match('/\{literal\}\s*<script>(.*?)<\/script>\s*\{\/literal\}/s', $src, $m);
        return $m[1] ?? '';
    }

    /** the shim's JavaScript without // and block comments (for structural checks) */
    private static function shimCode(): string
    {
        $js = (string) preg_replace('#/\*.*?\*/#s', '', self::shimJs());
        return (string) preg_replace('#(^|[^:\'"])//[^\n]*#', '$1', $js);
    }

    public function testThePatchTargetIsThePrototypeRealInstancesInherit(): void
    {
        $js = self::shimCode();
        $this->assertNotSame('', $js);
        $this->assertStringContainsString('var XP = XMLHttpRequest.prototype;', $js, 'compatibility fallback = the previous target');
        $this->assertStringContainsString('var probeXhr = new XMLHttpRequest();', $js);
        $this->assertStringContainsString('var realProto = Object.getPrototypeOf(probeXhr);', $js);
        $this->assertStringContainsString("if (realProto && typeof realProto.open === 'function' && typeof realProto.send === 'function') { XP = realProto; }", $js);
        // the probe is disposable: never opened, never sent, nothing else done with it
        $this->assertSame(2, substr_count($js, 'probeXhr'), 'probeXhr: declared and read once, never used for a request');
        $this->assertDoesNotMatchRegularExpression('/probeXhr\.(open|send|setRequestHeader|abort|addEventListener)/', $js);
    }

    public function testOnlyOpenAndSendArePatchedAndTheConstructorIsNeverReplaced(): void
    {
        $js = self::shimCode();
        preg_match_all('/\bXP\.([A-Za-z_]+)\s*=\s*function\b/', $js, $m);
        $this->assertSame(['open', 'send'], $m[1], 'exactly open and send are patched on the real prototype');
        $this->assertDoesNotMatchRegularExpression('/(window\.)?XMLHttpRequest\s*=[^=]/', $js, 'window.XMLHttpRequest is never reassigned');
        $this->assertDoesNotMatchRegularExpression('/XMLHttpRequest\.prototype\.[A-Za-z_]+\s*=[^=]/', $js, 'no patch on the constructor prototype');
        // amendment HF-A1: setRequestHeader is only called, never wrapped or replaced
        $this->assertDoesNotMatchRegularExpression('/setRequestHeader\s*=|\[\s*[\'"]setRequestHeader[\'"]\s*\]\s*=|defineProperty\([^)]*setRequestHeader/', $js);
        $this->assertSame(1, substr_count($js, 'setRequestHeader('), 'one call site: the token line');
        // once per document: a non-enumerable, identity-free marker on the prototype
        $this->assertStringContainsString('if (!XP.__nuxrCsrfXhr) {', $js);
        $this->assertStringContainsString("try { Object.defineProperty(XP, '__nuxrCsrfXhr', { value: true }); } catch (e) { XP.__nuxrCsrfXhr = true; }", $js);
        $this->assertSame(3, substr_count($js, '__nuxrCsrfXhr'), 'the marker holds only true (no token, identity or session data)');
    }

    public function testTheTokenLineIsCentralSameOriginGuardedAndBeforeTheGate(): void
    {
        $js = self::shimCode();
        $this->assertSame(1, substr_count($js, self::TOKEN_LINE), 'the WP2 token line, byte-identical (also asserted by PwaCsrfGateTest)');
        $this->assertStringContainsString('XP.open = function (m, u) { this._nuxrSame = sameOrigin(u); this._nuxrMethod = m; this._nuxrUrl = u; return xOpen.apply(this, arguments); };', $js);
        $send = substr($js, (int) strpos($js, 'XP.send = function () {'), 700);
        $this->assertStringContainsString(self::TOKEN_LINE, $send);
        $this->assertLessThan(strpos($send, "if (!allowed('xhr', this._nuxrMethod, this._nuxrUrl)) {"), strpos($send, self::TOKEN_LINE));
        $this->assertLessThan(strpos($send, 'return xSend.apply(this, arguments);'), strpos($send, "if (!allowed('xhr', this._nuxrMethod, this._nuxrUrl)) {"),
            'the WP7 gate still decides before the native send');
        $this->assertStringContainsString('function sameOrigin(u) {', $js);
        $this->assertStringContainsString("return new URL(u, window.location.href).origin === window.location.origin;", $js);
        $this->assertStringContainsString('var token = window.nuxrCsrfToken;', $js, 'the server-rendered token is the only source');
    }

    public function testNoRuntimeCallerManuallySetsTheCsrfHeader(): void
    {
        // the shim is the single runtime producer of X-CSRF-Token; servers only read it (HTTP_X_CSRF_TOKEN)
        $setter = '/setRequestHeader\(\s*[\'"]x-csrf-token[\'"]|[\'"]x-csrf-token[\'"]\s*:|\.(set|append)\(\s*[\'"]x-csrf-token[\'"]|\[\s*[\'"]x-csrf-token[\'"]\s*\]\s*=/i';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen(self::root()) + 1);
            if (!preg_match('/\.(php|tpl|js|html|htm)$/', $rel)
                || preg_match('#^(tests|docs|owner-review|dev|\.git)/|(^|/)vendor/|^ui/compiled/|^system/uploads/#', $rel)) {
                continue;
            }
            if (preg_match($setter, (string) file_get_contents($f->getPathname()))) {
                $files[] = $rel;
            }
        }
        sort($files);
        $this->assertSame([self::SHIM], $files, 'X-CSRF-Token is set only by the central CSRF shim');
        $this->assertSame(2, preg_match_all($setter, self::shimCode()), 'shim: the XHR token line and the fetch header');
    }

    public function testVendorFilesAreByteIdentical(): void
    {
        foreach (self::VENDOR as $rel => $sha) {
            $this->assertSame($sha, hash_file('sha256', self::root() . '/' . $rel), "{$rel}: unchanged vendor file");
        }
    }

    public function testTheAcceptedScriptOrderIsUnchanged(): void
    {
        $order = static function (string $src, array $needles): array {
            return array_map(static fn(string $n): int => (int) strpos($src, $n), $needles);
        };
        $staff = self::read('ui/ui/admin/footer.tpl');
        $pos = $order($staff, ['scripts/jquery.min.js', 'scripts/pace.min.js', 'scripts/nuxr-shell.js', 'scripts/nuxr-pwa.js', '{include file="nuxr-csrf.tpl"}']);
        foreach ($pos as $p) {
            $this->assertGreaterThan(0, $p);
        }
        $sorted = $pos;
        sort($sorted);
        $this->assertSame($sorted, $pos, 'staff: jQuery -> Pace -> shell -> WP7 client -> CSRF shim');
        $login = self::read('ui/ui/admin/admin/login.tpl');
        $this->assertLessThan(strpos($login, 'nuxr-csrf.tpl'), strpos($login, 'nuxr-pwa.js?'));
        $this->assertStringNotContainsString('pace', $login);
        foreach (['ui/ui/customer/footer.tpl', 'ui/ui/customer/footer-public.tpl'] as $tpl) {
            $src = self::read($tpl);
            $this->assertStringNotContainsString('pace.min.js', $src, "{$tpl}: no Pace on the customer side");
            $this->assertStringNotContainsString('nuxr-pwa.js', $src);
            $this->assertNotFalse(strpos($src, '{include file="nuxr-csrf.tpl"}'));
        }
        $this->assertSame(1, substr_count($staff, 'pace.min.js'), 'Pace only in the staff shell footer');
    }

    public function testNoRetryQueueReplayOrStorageInTheShim(): void
    {
        $js = self::shimCode();
        foreach (['/retry|queue|replay|resend/i', '/localStorage|sessionStorage|indexedDB|caches\./', '/setTimeout\([^)]*xSend/'] as $re) {
            $this->assertDoesNotMatchRegularExpression($re, $js, "nuxr-csrf.tpl: {$re}");
        }
        // the only deferred action is the existing synthetic "not sent" error of a gated request
        $this->assertSame(1, substr_count($js, 'setTimeout('));
    }
}
