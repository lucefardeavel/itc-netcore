<?php
namespace Reseller\Tests\Unit\Wp6a;

// CLI-only test: must never run through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use PHPUnit\Framework\TestCase;

/**
 * WP6A source guards: the new / rewritten staff templates escape every variable (explicit allow-list for ids, tokens,
 * URLs and the WP2 plugin-menu HTML), use confirmation dialogs instead of confirm(), load no external asset beyond
 * the documented residuals, never show the legacy plan price in the activation flow, and the UI helpers stay outside
 * the finance layer. SEC-25: no external translation request in Lang.
 */
final class Wp6aSourceGuardTest extends TestCase
{
    private const TEMPLATES = [
        'ui/ui/admin/header.tpl', 'ui/ui/admin/footer.tpl', 'ui/ui/admin/admin/login.tpl', 'ui/ui/admin/customers/list.tpl', 'ui/ui/admin/customers/view.tpl',
        'ui/ui/admin/customers/add.tpl', 'ui/ui/admin/activation/create.tpl', 'ui/ui/admin/activation/view.tpl', 'ui/ui/admin/activation/list.tpl',
    ];

    /** Unescaped output allowed only for these (ids / counters / tokens / application URLs / sanctioned HTML). */
    private const SAFE = '/^\$(app_url|csrf_token|nuxr_nonce|UPLOAD_PATH|_url|notify|xheader|xfooter|nuxr_dir|nuxr_lang|nuxr_more_url|nuxr_q_url|paginator\[\'url\'\]|_nav\.plugins[.\[][^}]*|w\[\'content\'\]|d\[\'id\'\]|c\[\'id\'\]|ds\[\'id\'\]|nuxr_customer_id|nuxr_plan_id|nuxr_total|nuxr_group|e\.sequence_no|o\[\'id\'\]|s\[\'id\'\]|p\.id|c\.id|q\.icon|i\.icon|p\.icon|g\.icon|g\.id|nuxr_customer\.id|package\[\'plan_id\'\]|pdays|nuxr_list\.(total|page|pages)|nuxr_activations\.(page|pages)|paginator\[\'(page|count)\'\]|h\.customers\.\w+|h\.activations\.\w+|nuxr_flags\.in_flight\.reference|nuxr_plan_id|a\.customer_id|q\.(id|route)|tone|st)$/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> */
    private static function files(): array
    {
        return array_merge(self::TEMPLATES, glob(self::root() . '/ui/ui/admin/nuxr/*.tpl') ? array_map(static fn(string $f): string => substr($f, strlen(self::root()) + 1), glob(self::root() . '/ui/ui/admin/nuxr/*.tpl')) : [], ['ui/ui/admin/dashboard.tpl']);
    }

    public function testEveryVariableIsEscapedOrAllowListed(): void
    {
        $bad = [];
        foreach (self::files() as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            if ($rel === 'ui/ui/admin/dashboard.tpl') {
                $src = (string) substr($src, 0, (int) strpos($src, '{else}' . "\n" . '{function showWidget')); // the legacy widget grid is unchanged
            }
            $src = (string) preg_replace('/\{literal\}.*?\{\/literal\}/s', '', $src);
            preg_match_all('/\{(\$[^}\s|]+)((?:\|[^}]*)?)\}/', $src, $m, PREG_SET_ORDER);
            foreach ($m as [$all, $var, $mods]) {
                if (str_contains($mods, 'escape') || preg_match(self::SAFE, $var)) {
                    continue;
                }
                if (preg_match('/\|(default:\'?\'?|truncate:[^|]*)$/', $mods) && preg_match(self::SAFE, $var)) {
                    continue;
                }
                $bad[] = "{$rel}: {$all}";
            }
        }
        $this->assertSame([], $bad, 'unescaped template output');
    }

    public function testConfirmationsUseDialogsNotWindowConfirm(): void
    {
        foreach (self::files() as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            $this->assertDoesNotMatchRegularExpression('/onclick="return (ask|confirm)\(/', $src, "{$rel}: no window.confirm for actions");
        }
    }

    public function testNoExternalAssetsBeyondTheDocumentedResiduals(): void
    {
        $allowed = [
            'https://cdn.jsdelivr.net/npm/chart.js@3.5.1/dist/chart.min.js', // charts tab / reports only (residual)
            'https://unpkg.com/leaflet@1.9.3/dist/leaflet.css', 'https://unpkg.com/leaflet@1.9.3/dist/leaflet.js', // opened map only (residual)
            'https://github.com/hotspotbilling/phpnuxbill', 'https://adminlte.io/', 'https://www.google.com/maps/dir//', // attribution / directions links
        ];
        $files = array_merge(self::files(), ['ui/ui/styles/nuxr-shell.css', 'ui/ui/styles/nuxr-rtl-legacy.css', 'ui/ui/scripts/nuxr-shell.js', 'ui/ui/styles/phpnuxbill.css', 'ui/ui/styles/modern-AdminLTE.min.css']);
        foreach ($files as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            if ($rel === 'ui/ui/admin/dashboard.tpl') {
                $src = (string) substr($src, 0, (int) strpos($src, '{else}' . "\n" . '{function showWidget')); // legacy widgets tab (optional version check) unchanged
            }
            $src = (string) preg_replace("#'https://\{literal\}\{s\}\{/literal\}\.google\.com/vt/[^']*'#", '', $src); // the opened map's tile source (residual)
            if (str_ends_with($rel, '.css')) {
                // stylesheets: only real loads (@import / url()); licence comments are not requests
                preg_match_all('#(?:@import\s*(?:url\()?\s*["\']?|url\(\s*["\']?)(https?://[^"\')\s]+)#', $src, $m);
                $urls = $m[1];
            } else {
                preg_match_all('#https?://[A-Za-z0-9.\-]+(/[^\s"\')<]*)?#', $src, $m);
                $urls = $m[0];
            }
            foreach ($urls as $url) {
                $ok = false;
                foreach ($allowed as $a) {
                    $ok = $ok || str_starts_with($url, $a) || str_starts_with($a, $url);
                }
                $this->assertTrue($ok || str_starts_with($url, 'http://www.w3.org/') || str_starts_with($url, 'http://fontawesome.io') || str_starts_with($url, 'https://getbootstrap.com') || str_starts_with($url, 'http://almsaeedstudio.com'), "{$rel}: external URL {$url} (licence comments excepted)");
            }
            $this->assertStringNotContainsString('fonts.googleapis.com', $src, "{$rel}: no Google Fonts import");
        }
    }

    public function testActivationFlowNeverShowsTheLegacyPlanPrice(): void
    {
        foreach (['ui/ui/admin/activation/create.tpl', 'ui/ui/admin/activation/view.tpl', 'ui/ui/admin/activation/list.tpl', 'ui/ui/admin/nuxr/activation-rows.tpl'] as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            $this->assertDoesNotMatchRegularExpression("/\\.price\\b|\\['price'\\]/", $src, "{$rel}: prices come only from the server quote / sealed snapshot");
        }
        $ctrl = (string) file_get_contents(self::root() . '/system/controllers/activation.php');
        $this->assertDoesNotMatchRegularExpression('/_post\(\s*[\'"](price|amount|wholesale|retail|cost|expiry|available)/i', $ctrl, 'no browser money');
    }

    public function testUiHelpersStayOutsideFinanceAndTranslationStaysLocal(): void
    {
        foreach (glob(self::root() . '/system/autoload/Reseller/Ui/*.php') as $f) {
            $src = (string) file_get_contents($f);
            $this->assertStringContainsString("if (!defined('NUXR_RUNTIME')) { http_response_code(404); exit; }", $src, basename($f));
            $this->assertDoesNotMatchRegularExpression('/Reseller\\\\+Finance|tbl_reseller_activations|tbl_manager_/', $src, basename($f) . ': no finance / activation tables in UI helpers');
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b|->save\(|->delete\(/i', $src, basename($f) . ': read only');
        }
        $lang = (string) file_get_contents(self::root() . '/system/autoload/Lang.php');
        $this->assertDoesNotMatchRegularExpression('/curl_|translate\.google|file_put_contents\(\s*\$lan_file/', $lang, 'SEC-25 closed');
    }
}
